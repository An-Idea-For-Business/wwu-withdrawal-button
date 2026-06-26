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
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentCart adapter.
 */
final class FluentCartAdapter implements OrderDataSource, SubscriptionAware {

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
	 * The merchant's withdrawal-handling mode for FluentCart orders.
	 *
	 * - 'auto'   — render our FluentCart withdrawal surfaces UNLESS FluentCart's own
	 *              native withdrawal add-on is detected (then step aside, no duplicate).
	 * - 'always' — always render ours, even alongside a native add-on.
	 * - 'off'    — never render ours (the merchant handles withdrawal another way).
	 *
	 * Pure read of the cached option; safe to call when FluentCart is inactive.
	 *
	 * @return string One of 'auto'|'always'|'off'.
	 */
	public static function mode(): string {
		$mode = (string) ( \WebWakeUpWdb\WithdrawalButton\Core\Settings::main()['fluentcart_mode'] ?? 'auto' );
		return in_array( $mode, array( 'auto', 'always', 'off' ), true ) ? $mode : 'auto';
	}

	/**
	 * Whether FluentCart's own native right-of-withdrawal add-on is active.
	 *
	 * FluentCart shipped its "Customer Rights" add-on (free, separate plugin, slug
	 * `fluent-cart-customer-rights`). The FluentCart team confirmed (2026-06-19) the
	 * stable detection signal: the constant `FLUENT_CART_CUSTOMER_RIGHTS_PLUGIN_PATH`,
	 * defined at boot (available on plugins_loaded priority 20) as their own
	 * double-load guard, which will not be removed; `class_exists( 'FluentCartCustomerRights' )`
	 * is a secondary guard. The result remains filterable via
	 * `webwakeupwdb_fluentcart_native_active` for forward-compatibility.
	 *
	 * @return bool
	 */
	public static function native_addon_active(): bool {
		// FluentCart "Customer Rights" add-on detection (team-confirmed signal,
		// 2026-06-19). Constant first (set on plugins_loaded:20, their double-load
		// guard), class as a secondary guard.
		$detected = defined( 'FLUENT_CART_CUSTOMER_RIGHTS_PLUGIN_PATH' ) || class_exists( 'FluentCartCustomerRights' );
		/**
		 * Filter whether FluentCart's native withdrawal add-on is active.
		 *
		 * Return true to make Auto mode step aside on FluentCart orders.
		 *
		 * @param bool $detected Whether the native add-on was detected.
		 */
		return (bool) apply_filters( 'webwakeupwdb_fluentcart_native_active', $detected );
	}

	/**
	 * Whether THIS plugin should render its consumer-facing FluentCart withdrawal
	 * surfaces (portal button, checkout-consent capture, e-mail link, public form).
	 *
	 * Gates ONLY consumer entry points — the admin Requests dashboard and any
	 * in-flight durable-medium confirmation keep working, so a FluentCart withdrawal
	 * already recorded is never stranded when handling is later turned off.
	 *
	 * @return bool
	 */
	public static function should_render(): bool {
		$mode = self::mode();
		if ( 'off' === $mode ) {
			return false;
		}
		if ( 'always' === $mode ) {
			return true;
		}
		// 'auto': defer to FluentCart's native add-on when present.
		return ! self::native_addon_active();
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
			$this->has_vat_number( $order ),
			$this->is_renewal_order( $order_ref ),
			$this->subscription_ref( $order_ref )
		);
	}

	/**
	 * Load the FluentCart subscription whose parent_order_id is this order (i.e. the
	 * subscription created BY this order — present only on the initial order). Guarded.
	 *
	 * @param string $order_ref Order id.
	 * @return object|null
	 */
	private function subscription_for_parent_order( string $order_ref ) {
		$model = '\\FluentCart\\App\\Models\\Subscription';
		if ( ! class_exists( $model ) ) {
			return null;
		}
		try {
			// where()/first() are Eloquent magic statics — call them directly (do not
			// guard with method_exists; see the load() note for the same reason).
			$sub = $model::where( 'parent_order_id', (int) $order_ref )->first();
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_object( $sub ) ? $sub : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Fails open (false): when the subscription state cannot be determined the order
	 * is treated as a normal order so a legitimate withdrawal button is never hidden.
	 * The only HIGH-confidence renewal signal we act on is an explicit order `type`;
	 * the exact FluentCart renewal marker is pending confirmation from their team
	 * (see _internal FluentCart questions), and an initial order is detected reliably
	 * via the subscription's parent_order_id.
	 */
	public function is_renewal_order( string $order_ref ): bool {
		$is_renewal = false;
		$order      = $this->load( $order_ref );
		if ( is_object( $order ) ) {
			// A subscription whose parent_order_id is this order ⇒ this is the INITIAL
			// order (it concluded the contract) and is never a renewal.
			if ( ! $this->subscription_for_parent_order( $order_ref ) ) {
				$type = strtolower( (string) ( $this->attr( $order, 'type' ) ?? '' ) );
				if ( in_array( $type, array( 'renewal', 'subscription_renewal', 'sub_renewal' ), true ) ) {
					$is_renewal = true;
				}
			}
		}
		/**
		 * Override subscription-renewal detection for an order.
		 *
		 * @param bool   $is_renewal Whether the order is a subscription renewal.
		 * @param string $order_ref  Order reference.
		 * @param string $platform   Adapter key.
		 */
		return (bool) apply_filters( 'webwakeupwdb_order_is_renewal', $is_renewal, $order_ref, $this->key() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function subscription_ref( string $order_ref ): string {
		$sub = $this->subscription_for_parent_order( $order_ref );
		if ( is_object( $sub ) ) {
			$id = $this->attr( $sub, 'id' );
			return null !== $id ? (string) $id : '';
		}
		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Best-effort, guarded — also OFF by default (only runs when the merchant opts in
	 * to auto-cancel). Prefers the gateway-aware cancel, then a local status flip; the
	 * RequestsDashboard always surfaces a manual reminder so a no-op never strands the
	 * merchant.
	 */
	public function cancel_subscription( string $order_ref ): bool {
		$sub = $this->subscription_for_parent_order( $order_ref );
		if ( ! is_object( $sub ) ) {
			return false;
		}
		foreach ( array( 'cancelRemoteSubscription', 'cancel' ) as $method ) {
			if ( method_exists( $sub, $method ) ) {
				try {
					$sub->{$method}();
					return true;
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}
		try {
			if ( method_exists( $sub, 'update' ) ) {
				$sub->update( array( 'status' => 'cancelled' ) );
				return true;
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return false;
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
		// docs/analysis/webwakeupwdb-fluentcart-hooks-ANALYSIS.md.
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
		return 'webwakeupwdb_fc_' . preg_replace( '/[^a-z0-9]/i', '', $order_ref );
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
	 * see docs/analysis/webwakeupwdb-fluentcart-hooks-ANALYSIS.md). Resolving them here is
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
		return (array) apply_filters( 'webwakeupwdb_fluentcart_product_category_ids', $ids, $post_id );
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
