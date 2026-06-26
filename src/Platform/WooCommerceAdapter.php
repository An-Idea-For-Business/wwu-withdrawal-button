<?php
/**
 * WooCommerce order data source (HPOS-safe).
 *
 * All order access goes through wc_get_order() and WC_Order methods — never
 * get_post()/get_post_meta(), which do not address the HPOS data store. Meta is
 * written via update_meta_data()/save().
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce adapter.
 */
final class WooCommerceAdapter implements OrderDataSource, SubscriptionAware {

	/**
	 * Per-request cache of loaded orders.
	 *
	 * @var array<string,\WC_Order|null>
	 */
	private $cache = array();

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'woocommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Load a WC_Order with a per-request cache.
	 *
	 * @param string $order_ref Order id as string.
	 * @return \WC_Order|null
	 */
	private function load( string $order_ref ): ?\WC_Order {
		if ( array_key_exists( $order_ref, $this->cache ) ) {
			return $this->cache[ $order_ref ];
		}
		$order = $this->is_active() ? wc_get_order( (int) $order_ref ) : false;
		$this->cache[ $order_ref ] = ( $order instanceof \WC_Order ) ? $order : null;
		return $this->cache[ $order_ref ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_order( string $order_ref ): ?NormalizedOrder {
		$order = $this->load( $order_ref );
		if ( ! $order ) {
			return null;
		}

		$country = $order->get_billing_country();
		if ( '' === $country ) {
			$country = $order->get_shipping_country();
		}

		return new NormalizedOrder(
			$this->key(),
			(string) $order->get_id(),
			$order->get_order_number(),
			$order->get_billing_email(),
			(int) $order->get_customer_id(),
			strtoupper( (string) $country ),
			$order->get_status(),
			$this->detect_locale( $order ),
			$this->to_immutable( $order->get_date_created() ),
			$this->to_immutable( $order->get_date_paid() ),
			$this->to_immutable( $order->get_date_completed() ),
			$this->map_items( $order ),
			$this->has_vat_number( $order ),
			$this->is_renewal_order( $order_ref ),
			$this->subscription_ref( $order_ref )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * WooCommerce Subscriptions renewal detection (guarded — WCS is a separate plugin).
	 * Fails open: returns false when WCS is absent / state unknown.
	 */
	public function is_renewal_order( string $order_ref ): bool {
		$is_renewal = false;
		$order      = $this->load( $order_ref );
		if ( $order instanceof \WC_Order ) {
			if ( function_exists( 'wcs_order_contains_renewal' ) ) {
				$is_renewal = (bool) wcs_order_contains_renewal( $order );
			} else {
				// Fallback: WCS renewal orders carry the _subscription_renewal meta.
				$is_renewal = '' !== (string) $order->get_meta( '_subscription_renewal' );
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
	 *
	 * Returns the first related subscription id (works for the initial order too, so
	 * the subscription can be cancelled when the consumer withdraws from it).
	 */
	public function subscription_ref( string $order_ref ): string {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return '';
		}
		$order = $this->load( $order_ref );
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		$subs = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
		if ( is_array( $subs ) && ! empty( $subs ) ) {
			$ids = array_keys( $subs );
			return (string) reset( $ids );
		}
		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Cancels every subscription related to the order (guarded; never fatals).
	 */
	public function cancel_subscription( string $order_ref ): bool {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return false;
		}
		$order = $this->load( $order_ref );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		$subs = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
		$ok   = false;
		foreach ( (array) $subs as $sub ) {
			if ( is_object( $sub ) && method_exists( $sub, 'can_be_updated_to' ) && $sub->can_be_updated_to( 'cancelled' ) ) {
				try {
					$sub->update_status( 'cancelled', __( 'Cancelled following the consumer right-of-withdrawal request.', 'wwu-withdrawal-button' ) );
					$ok = true;
				} catch ( \Exception $e ) {
					// Leave $ok false on this subscription; caller logs the failure.
					continue;
				}
			}
		}
		return $ok;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_refunded( string $order_ref ): bool {
		$order = $this->load( $order_ref );
		return $order instanceof \WC_Order && (float) $order->get_total_refunded() > 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify_owner( string $order_ref, int $user_id ): bool {
		$order = $this->load( $order_ref );
		if ( ! $order || $user_id <= 0 ) {
			return false;
		}
		return (int) $order->get_customer_id() === $user_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify_guest_key( string $order_ref, string $key ): bool {
		$order = $this->load( $order_ref );
		if ( ! $order || '' === $key ) {
			return false;
		}
		return hash_equals( (string) $order->get_order_key(), $key );
	}

	/**
	 * {@inheritDoc}
	 */
	public function mark_withdrawal_requested( string $order_ref ): bool {
		$order = $this->load( $order_ref );
		if ( ! $order ) {
			return false;
		}
		$order->update_status( WooCommerce\OrderStatus::SLUG_UNPREFIXED, __( 'Withdrawal requested by the consumer.', 'wwu-withdrawal-button' ) );
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function add_note( string $order_ref, string $note ): void {
		$order = $this->load( $order_ref );
		if ( $order ) {
			$order->add_order_note( $note );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_meta( string $order_ref, string $key ) {
		$order = $this->load( $order_ref );
		return $order ? $order->get_meta( WEBWAKEUPWDB_META_PREFIX . $key ) : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_meta( string $order_ref, string $key, $value ): void {
		$order = $this->load( $order_ref );
		if ( $order ) {
			$order->update_meta_data( WEBWAKEUPWDB_META_PREFIX . $key, $value );
			$order->save();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function batch_meta( string $order_ref, array $pairs ): void {
		$order = $this->load( $order_ref );
		if ( ! $order ) {
			return;
		}
		foreach ( $pairs as $key => $value ) {
			$order->update_meta_data( WEBWAKEUPWDB_META_PREFIX . $key, $value );
		}
		$order->save(); // single persist for all keys.
	}

	/**
	 * Convert a WC_DateTime to DateTimeImmutable (UTC), or null.
	 *
	 * @param mixed $date WC_DateTime|null.
	 * @return \DateTimeImmutable|null
	 */
	private function to_immutable( $date ): ?\DateTimeImmutable {
		if ( ! $date instanceof \WC_DateTime ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( '@' . $date->getTimestamp() ) );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Map order line items to the normalized item shape.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function map_items( \WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$items[] = array(
				'product_id'   => $product ? $product->get_id() : 0,
				'name'         => $item->get_name(),
				'qty'          => (int) $item->get_quantity(),
				'virtual'      => $product ? $product->is_virtual() : false,
				'downloadable' => $product ? $product->is_downloadable() : false,
				'type'         => $product ? $product->get_type() : '',
				'category_ids' => $product ? (array) $product->get_category_ids() : array(),
			);
		}
		return $items;
	}

	/**
	 * Detect the locale stored at checkout (own meta → TranslatePress → WC core).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function detect_locale( \WC_Order $order ): string {
		$candidates = array(
			(string) $order->get_meta( WEBWAKEUPWDB_META_PREFIX . 'locale' ),
			(string) $order->get_meta( 'trp_language' ),
			(string) $order->get_meta( 'wpml_language' ),
		);
		foreach ( $candidates as $locale ) {
			if ( '' !== $locale ) {
				return $locale;
			}
		}
		return '';
	}

	/**
	 * Heuristic VAT/business-number detection across common meta keys + filter.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private function has_vat_number( \WC_Order $order ): bool {
		$keys = array( '_billing_vat', '_vat_number', 'billing_eu_vat_number', '_billing_eu_vat_number', 'vat_number', '_billing_vat_number' );
		$found = false;
		foreach ( $keys as $key ) {
			if ( '' !== (string) $order->get_meta( $key ) ) {
				$found = true;
				break;
			}
		}
		/**
		 * Override B2B (VAT-number) detection for an order.
		 *
		 * @param bool      $found Whether a VAT number was detected.
		 * @param \WC_Order $order The order.
		 */
		return (bool) apply_filters( 'webwakeupwdb_order_has_vat_number', $found, $order );
	}
}
