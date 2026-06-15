<?php
/**
 * FluentCart order data source.
 *
 * FluentCart (by WPManageNinja) stores orders in custom tables via an
 * Eloquent-style ORM (FluentCart\App\Models\Order), not CPTs — so WP_Query /
 * post-meta do not apply. This adapter reads via the ORM defensively (every call
 * is guarded) and keeps its own operational meta in a per-order option, so the
 * withdrawal flow + evidence log + durable medium work even where FluentCart's
 * own meta API differs across versions.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentCart adapter.
 */
final class FluentCartAdapter implements OrderDataSource {

	/**
	 * Per-request order cache.
	 *
	 * @var array<string,object|null>
	 */
	private $cache = array();

	/**
	 * Per-request product-category cache (post_id => term-id list).
	 *
	 * @var array<int,int[]>
	 */
	private $cat_cache = array();

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'fluentcart';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return function_exists( 'fluent_cart_api' ) || class_exists( '\\FluentCart\\App\\Models\\Order' );
	}

	/**
	 * Map a FluentCart order's fulfillment + payment status to the normalized
	 * status used for withdrawal eligibility.
	 *
	 * Eligibility presupposes a concluded (paid) contract. FluentCart signals this
	 * via payment_status, not necessarily the fulfillment status (which may be
	 * 'pending'). Surfaces 'paid' when paid, keeping completed/processing if set.
	 * Pure + static so it is unit-testable without FluentCart active.
	 *
	 * @param string $fulfillment Order fulfillment status.
	 * @param string $payment     Order payment status.
	 * @return string
	 */
	public static function eligible_status( string $fulfillment, string $payment ): string {
		$payment = strtolower( $payment );
		$status  = $fulfillment;
		if ( in_array( $payment, array( 'paid', 'partially_paid', 'partially-paid' ), true )
			&& ! in_array( strtolower( $fulfillment ), array( 'completed', 'processing' ), true ) ) {
			$status = 'paid';
		}
		return $status;
	}

	/**
	 * Unwrap an Eloquent collection (or other value) to a plain array of models.
	 *
	 * Casting an Eloquent collection with (array) iterates the collection object's
	 * internal properties, NOT its models — use ->all(). Arrays pass through,
	 * Traversables are materialised, anything else yields an empty array. Pure +
	 * static so it is unit-testable without FluentCart active.
	 *
	 * @param mixed $value Collection, array, Traversable or scalar.
	 * @return array
	 */
	public static function unwrap_collection( $value ): array {
		if ( is_object( $value ) && method_exists( $value, 'all' ) ) {
			$all = $value->all();
			return is_array( $all ) ? $all : array();
		}
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( $value instanceof \Traversable ) {
			return iterator_to_array( $value );
		}
		return array();
	}

	/**
	 * Load a FluentCart order model (guarded), cached per request.
	 *
	 * @param string $order_ref Order id.
	 * @return object|null
	 */
	private function load( string $order_ref ) {
		if ( array_key_exists( $order_ref, $this->cache ) ) {
			return $this->cache[ $order_ref ];
		}
		$order = null;
		$model = '\\FluentCart\\App\\Models\\Order';
		// Do NOT guard with method_exists($model, 'find'): Eloquent's find() is a
		// magic static (__callStatic → query builder), so method_exists() returns
		// false and would skip the lookup entirely, returning null for every order.
		if ( class_exists( $model ) ) {
			try {
				$order = $model::find( (int) $order_ref );
			} catch ( \Throwable $e ) {
				$order = null;
			}
		}
		$this->cache[ $order_ref ] = is_object( $order ) ? $order : null;
		return $this->cache[ $order_ref ];
	}

	/**
	 * Read a property/attribute from a FluentCart model defensively.
	 *
	 * @param object $order Model.
	 * @param string $name  Attribute name.
	 * @return mixed
	 */
	private function attr( $order, string $name ) {
		if ( isset( $order->{$name} ) ) {
			return $order->{$name};
		}
		if ( method_exists( $order, 'getAttribute' ) ) {
			try {
				return $order->getAttribute( $name );
			} catch ( \Throwable $e ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Read a related model from a FluentCart Eloquent model defensively.
	 *
	 * Per the official schema (dev.fluentcart.com/database/models/order) FluentCart
	 * does NOT keep email, billing country or the WordPress user id as flat columns
	 * on the order: email lives on the `customer` relation, billing country on the
	 * `billing_address` (OrderAddress) relation, and the WP user id on
	 * `customer->user_id` (fct_orders.customer_id is the FluentCart customer PK, not
	 * a WP user). Accessing the magic relation property triggers the ORM lazy-load;
	 * every access is guarded so a non-Eloquent or detached model returns null.
	 *
	 * @param object $model    Model.
	 * @param string $relation Relationship accessor (e.g. 'customer', 'billing_address').
	 * @return object|null
	 */
	private function rel( $model, string $relation ) {
		if ( ! is_object( $model ) ) {
			return null;
		}
		try {
			$value = $model->{$relation};
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_object( $value ) ? $value : null;
	}

	/**
	 * Resolve the billing country (ISO-2) for a FluentCart order.
	 *
	 * Tries the convenience `billing_address` relation, then walks the
	 * `order_addresses` collection picking the `type === 'billing'` row (falling
	 * back to the first address), then any flat column. Per the official schema
	 * (fct_order_addresses.country) the value is an ISO-2 code, e.g. "IT".
	 *
	 * @param object $order Order model.
	 * @return string ISO-2 country code, or '' when undeterminable.
	 */
	private function billing_country( $order ): string {
		$billing = $this->rel( $order, 'billing_address' );
		if ( $billing ) {
			$country = (string) ( $this->attr( $billing, 'country' ) ?? '' );
			if ( '' !== $country ) {
				return $country;
			}
		}

		$addresses = $this->rel( $order, 'order_addresses' );
		if ( is_iterable( $addresses ) ) {
			$first = '';
			foreach ( $addresses as $addr ) {
				$type    = strtolower( (string) ( $this->attr( $addr, 'type' ) ?? '' ) );
				$country = (string) ( $this->attr( $addr, 'country' ) ?? '' );
				if ( '' === $country ) {
					continue;
				}
				if ( 'billing' === $type ) {
					return $country;
				}
				if ( '' === $first ) {
					$first = $country;
				}
			}
			if ( '' !== $first ) {
				return $first;
			}
		}

		return (string) ( $this->attr( $order, 'billing_country' ) ?? $this->attr( $order, 'country' ) ?? '' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_order( string $order_ref ): ?NormalizedOrder {
		$order = $this->load( $order_ref );
		if ( ! $order ) {
			return null;
		}

		$customer = $this->rel( $order, 'customer' );

		// Email: Customer relation first, then any flat fallback.
		$email = $customer ? (string) ( $this->attr( $customer, 'email' ) ?? '' ) : '';
		if ( '' === $email ) {
			$email = (string) ( $this->attr( $order, 'customer_email' ) ?? $this->attr( $order, 'email' ) ?? '' );
		}

		// Billing country (ISO-2): billing_address relation → order_addresses
		// collection (type=billing) → flat column. {@see self::billing_country()}.
		$country = $this->billing_country( $order );

		// WordPress user id: Customer::user_id (the order's customer_id is the
		// FluentCart customer PK, never a WP user id — so do NOT fall back to it).
		$user_id = $customer ? (int) ( $this->attr( $customer, 'user_id' ) ?? 0 ) : 0;
		if ( $user_id <= 0 ) {
			$user_id = (int) ( $this->attr( $order, 'user_id' ) ?? 0 );
		}

		// Withdrawal eligibility hinges on a concluded (PAID) contract, which
		// FluentCart signals via payment_status — not necessarily the fulfillment
		// status (which may still be 'pending'). The green "Paid" badge in the portal
		// is the payment_status. {@see self::eligible_status()}.
		$status = self::eligible_status(
			(string) ( $this->attr( $order, 'status' ) ?? '' ),
			(string) ( $this->attr( $order, 'payment_status' ) ?? '' )
		);

		$number = (string) ( $this->attr( $order, 'invoice_no' ) ?? $this->attr( $order, 'order_number' ) ?? $order_ref );

		return new NormalizedOrder(
			$this->key(),
			$order_ref,
			$number,
			$email,
			$user_id,
			strtoupper( $country ),
			$status,
			(string) $this->meta_get( $order_ref, 'locale' ),
			$this->to_immutable( $this->attr( $order, 'created_at' ) ),
			$this->to_immutable( $this->attr( $order, 'paid_at' ) ?? $this->attr( $order, 'created_at' ) ),
			$this->to_immutable( $this->attr( $order, 'completed_at' ) ),
			$this->map_items( $order ),
			$this->has_vat_number( $order )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_refunded( string $order_ref ): bool {
		$order = $this->load( $order_ref );
		if ( ! $order ) {
			return false;
		}
		$payment = strtolower( (string) ( $this->attr( $order, 'payment_status' ) ?? '' ) );
		return in_array( $payment, array( 'refunded', 'partially_refunded', 'partially-refunded' ), true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify_owner( string $order_ref, int $user_id ): bool {
		$order = $this->load( $order_ref );
		if ( ! $order || $user_id <= 0 ) {
			return false;
		}
		// Ownership is the WordPress user id on the Customer relation, never the
		// order's customer_id (which is the FluentCart customer PK).
		$customer = $this->rel( $order, 'customer' );
		$owner    = $customer ? (int) ( $this->attr( $customer, 'user_id' ) ?? 0 ) : 0;
		if ( $owner <= 0 ) {
			$owner = (int) ( $this->attr( $order, 'user_id' ) ?? 0 );
		}
		return $owner > 0 && $owner === $user_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify_guest_key( string $order_ref, string $key ): bool {
		$order = $this->load( $order_ref );
		if ( ! $order || '' === $key ) {
			return false;
		}
		$hash = (string) ( $this->attr( $order, 'order_hash' ) ?? $this->attr( $order, 'uuid' ) ?? '' );
		return '' !== $hash && hash_equals( $hash, $key );
	}

	/**
	 * {@inheritDoc}
	 */
	public function mark_withdrawal_requested( string $order_ref ): bool {
		// FluentCart status transitions vary by version; record our own status and
		// let integrators map it to a native status via the action hook.
		$this->set_meta( $order_ref, 'native_status_note', 'withdrawal_requested' );
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function add_note( string $order_ref, string $note ): void {
		// Preferred: FluentCart's own activity log → visible in the admin order
		// timeline. Signature confirmed by the FluentCart team (2026-06-15):
		// fluent_cart_add_log( $title, $message, $level, $context ). See
		// docs/analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md.
		if ( function_exists( 'fluent_cart_add_log' ) ) {
			try {
				$context = array(
					'module_name' => 'order',
					// FluentCart order refs are integer IDs; guard the cast so a
					// non-numeric ref is passed through verbatim (visible in the log)
					// instead of silently collapsing to 0.
					'module_id'   => is_numeric( $order_ref ) ? (int) $order_ref : $order_ref,
					'log_type'    => 'activity',
				);
				if ( class_exists( '\\FluentCart\\App\\Models\\Order' ) ) {
					$context['module_type'] = 'FluentCart\\App\\Models\\Order';
				}
				fluent_cart_add_log(
					__( 'Withdrawal evidence recorded', 'wwu-withdrawal-button' ),
					$note,
					'info',
					$context
				);
				return;
			} catch ( \Throwable $e ) {
				// fall through to the model method, then meta log.
			}
		}

		$order = $this->load( $order_ref );
		if ( $order && method_exists( $order, 'addNote' ) ) {
			try {
				$order->addNote( $note );
				return;
			} catch ( \Throwable $e ) {
				// fall through to meta log below.
			}
		}
		$notes   = (array) $this->meta_get( $order_ref, 'notes' );
		$notes[] = array( 'at' => gmdate( 'c' ), 'note' => $note );
		$this->set_meta( $order_ref, 'notes', $notes );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_meta( string $order_ref, string $key ) {
		return $this->meta_get( $order_ref, $key );
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_meta( string $order_ref, string $key, $value ): void {
		$all         = (array) get_option( $this->meta_option( $order_ref ), array() );
		$all[ $key ] = $value;
		update_option( $this->meta_option( $order_ref ), $all, false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function batch_meta( string $order_ref, array $pairs ): void {
		$all = (array) get_option( $this->meta_option( $order_ref ), array() );
		foreach ( $pairs as $key => $value ) {
			$all[ $key ] = $value;
		}
		update_option( $this->meta_option( $order_ref ), $all, false );
	}

	/**
	 * Read a value from the per-order meta option.
	 *
	 * @param string $order_ref Order id.
	 * @param string $key       Key.
	 * @return mixed
	 */
	private function meta_get( string $order_ref, string $key ) {
		$all = (array) get_option( $this->meta_option( $order_ref ), array() );
		return $all[ $key ] ?? '';
	}

	/**
	 * Option name for an order's operational meta.
	 *
	 * @param string $order_ref Order id.
	 * @return string
	 */
	private function meta_option( string $order_ref ): string {
		return 'wwu_wb_fc_' . preg_replace( '/[^a-z0-9]/i', '', $order_ref );
	}

	/**
	 * Map FluentCart order items to the normalized shape (best-effort).
	 *
	 * @param object $order Model.
	 * @return array<int,array<string,mixed>>
	 */
	private function map_items( $order ): array {
		$items = array();
		// Line items are a HasMany relation; lazy-load via rel(), fall back to a
		// flat attribute for non-Eloquent shapes.
		$raw_items = $this->rel( $order, 'order_items' );
		if ( ! is_object( $raw_items ) ) {
			$raw_items = $this->rel( $order, 'items' );
		}
		if ( ! is_object( $raw_items ) ) {
			$raw_items = $this->attr( $order, 'items' ) ?? $this->attr( $order, 'order_items' ) ?? array();
		}
		if ( is_iterable( $raw_items ) ) {
			foreach ( $raw_items as $it ) {
				// Official OrderItem schema: fulfillment_type (physical|digital|service),
				// product reference is post_id (WordPress post ID), title/post_title.
				$type    = (string) ( $this->attr( $it, 'fulfillment_type' ) ?? $this->attr( $it, 'product_type' ) ?? '' );
				$digital = in_array( strtolower( $type ), array( 'digital', 'downloadable', 'license', 'licensed' ), true );
				$pid     = (int) ( $this->attr( $it, 'post_id' ) ?? $this->attr( $it, 'product_id' ) ?? 0 );
				$items[] = array(
					'product_id'   => $pid,
					'name'         => (string) ( $this->attr( $it, 'title' ) ?? $this->attr( $it, 'post_title' ) ?? $this->attr( $it, 'name' ) ?? '' ),
					'qty'          => (int) ( $this->attr( $it, 'quantity' ) ?? 1 ),
					'virtual'      => $digital,
					'downloadable' => $digital,
					'type'         => $type,
					'category_ids' => $this->product_category_ids( $pid ),
				);
			}
		}
		return $items;
	}

	/**
	 * Resolve the WordPress term ids of a FluentCart product's categories.
	 *
	 * FluentCart products are a WordPress post type; their categories live in the
	 * `product-categories` taxonomy — confirmed by the FluentCart team (2026-06-15,
	 * see docs/analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md). Resolving them here is
	 * what makes FluentCart exemptions category-aware, in parity with WooCommerce
	 * (`product_cat`) and EDD (`download_category`). Cached per request.
	 *
	 * @param int $post_id Product post id.
	 * @return int[]
	 */
	private function product_category_ids( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}
		if ( ! isset( $this->cat_cache[ $post_id ] ) ) {
			$this->cat_cache[ $post_id ] = self::category_ids_for_post( $post_id );
		}
		return $this->cat_cache[ $post_id ];
	}

	/**
	 * Stateless taxonomy lookup of a FluentCart product's category term ids.
	 *
	 * Shared by the adapter (order-items path) and the checkout-consent renderer
	 * (cart path) so both resolve categories identically. Guarded: returns [] when
	 * the taxonomy is not registered (older FluentCart / product type without
	 * categories) instead of letting wp_get_object_terms() emit a WP_Error.
	 *
	 * @param int $post_id Product post id.
	 * @return int[]
	 */
	public static function category_ids_for_post( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'wp_get_object_terms' ) || ! taxonomy_exists( 'product-categories' ) ) {
			return array();
		}
		$terms = wp_get_object_terms( $post_id, 'product-categories', array( 'fields' => 'ids' ) );
		$ids   = is_array( $terms ) ? array_map( 'intval', $terms ) : array();
		/**
		 * Filter the resolved FluentCart product category term ids.
		 *
		 * @param int[] $ids     Category term ids.
		 * @param int   $post_id Product post id.
		 */
		return (array) apply_filters( 'wwu_wb_fluentcart_product_category_ids', $ids, $post_id );
	}

	/**
	 * VAT/business detection.
	 *
	 * @param object $order Model.
	 * @return bool
	 */
	private function has_vat_number( $order ): bool {
		$vat = (string) ( $this->attr( $order, 'vat_number' ) ?? $this->attr( $order, 'eu_vat_number' ) ?? '' );
		return '' !== $vat;
	}

	/**
	 * Convert a date-ish value to DateTimeImmutable.
	 *
	 * @param mixed $value Date value.
	 * @return \DateTimeImmutable|null
	 */
	private function to_immutable( $value ): ?\DateTimeImmutable {
		if ( empty( $value ) ) {
			return null;
		}
		try {
			if ( is_numeric( $value ) ) {
				return new \DateTimeImmutable( '@' . (int) $value );
			}
			return new \DateTimeImmutable( (string) $value );
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
