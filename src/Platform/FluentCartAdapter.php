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
		if ( class_exists( $model ) && method_exists( $model, 'find' ) ) {
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
	 * {@inheritDoc}
	 */
	public function get_order( string $order_ref ): ?NormalizedOrder {
		$order = $this->load( $order_ref );
		if ( ! $order ) {
			return null;
		}

		$customer = $this->rel( $order, 'customer' );
		$billing  = $this->rel( $order, 'billing_address' );

		// Email: Customer relation first, then any flat fallback.
		$email = $customer ? (string) ( $this->attr( $customer, 'email' ) ?? '' ) : '';
		if ( '' === $email ) {
			$email = (string) ( $this->attr( $order, 'customer_email' ) ?? $this->attr( $order, 'email' ) ?? '' );
		}

		// Billing country: OrderAddress relation first, then any flat fallback.
		$country = $billing ? (string) ( $this->attr( $billing, 'country' ) ?? '' ) : '';
		if ( '' === $country ) {
			$country = (string) ( $this->attr( $order, 'billing_country' ) ?? $this->attr( $order, 'country' ) ?? '' );
		}

		// WordPress user id: Customer::user_id (the order's customer_id is the
		// FluentCart customer PK, never a WP user id — so do NOT fall back to it).
		$user_id = $customer ? (int) ( $this->attr( $customer, 'user_id' ) ?? 0 ) : 0;
		if ( $user_id <= 0 ) {
			$user_id = (int) ( $this->attr( $order, 'user_id' ) ?? 0 );
		}

		$status = (string) ( $this->attr( $order, 'status' ) ?? '' );
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
				$type   = (string) ( $this->attr( $it, 'product_type' ) ?? $this->attr( $it, 'fulfillment_type' ) ?? '' );
				$digital = in_array( strtolower( $type ), array( 'digital', 'downloadable', 'license', 'licensed' ), true );
				$items[] = array(
					'product_id'   => (int) ( $this->attr( $it, 'product_id' ) ?? 0 ),
					'name'         => (string) ( $this->attr( $it, 'title' ) ?? $this->attr( $it, 'name' ) ?? '' ),
					'qty'          => (int) ( $this->attr( $it, 'quantity' ) ?? 1 ),
					'virtual'      => $digital,
					'downloadable' => $digital,
					'type'         => $type,
					'category_ids' => array(),
				);
			}
		}
		return $items;
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
