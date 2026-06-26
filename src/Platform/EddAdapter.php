<?php
/**
 * Easy Digital Downloads (EDD 3.0+) order data source.
 *
 * EDD 3.0 stores orders in custom tables via the EDD\Orders\* API (NOT the legacy
 * edd_payment CPT). This adapter reads through that API defensively (every call is
 * guarded) and keeps its plugin meta in first-class EDD order meta
 * (edd_*_order_meta), so the withdrawal flow + evidence log + durable medium + the
 * platform-agnostic ConsentReader all work unchanged.
 *
 * A "download" is the `download` CPT; its categories live in the `download_category`
 * taxonomy, so EDD exemptions are BOTH product-id and category aware.
 *
 * Verified against official EDD sources — see docs/specs/webwakeupwdb-edd-integration-SPEC.md.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EDD adapter.
 */
final class EddAdapter implements OrderDataSource, SubscriptionAware {

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
		return 'edd';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return function_exists( 'edd_get_order' ); // EDD 3.0+ custom-table API.
	}

	/**
	 * Map an EDD order status to the normalized status used for eligibility.
	 *
	 * Eligibility presupposes a concluded (paid) contract; EDD signals this with the
	 * 'complete' status. Pure + static so it is unit-testable without EDD active.
	 *
	 * @param string $status EDD order status.
	 * @return string
	 */
	public static function eligible_status( string $status ): string {
		$status = strtolower( $status );
		if ( 'complete' === $status || 'completed' === $status ) {
			return 'completed';
		}
		return $status;
	}

	/**
	 * Load an EDD order model (guarded), cached per request.
	 *
	 * @param string $order_ref Order id.
	 * @return object|null
	 */
	private function load( string $order_ref ) {
		if ( array_key_exists( $order_ref, $this->cache ) ) {
			return $this->cache[ $order_ref ];
		}
		$order = null;
		if ( $this->is_active() ) {
			try {
				$order = edd_get_order( (int) $order_ref );
			} catch ( \Throwable $e ) {
				$order = null;
			}
		}
		$this->cache[ $order_ref ] = is_object( $order ) ? $order : null;
		return $this->cache[ $order_ref ];
	}

	/**
	 * Read a property/attribute from an EDD model defensively.
	 *
	 * @param object $obj  Model.
	 * @param string $name Attribute name.
	 * @return mixed
	 */
	private function attr( $obj, string $name ) {
		if ( is_object( $obj ) && isset( $obj->{$name} ) ) {
			return $obj->{$name};
		}
		if ( is_object( $obj ) && method_exists( $obj, 'getAttribute' ) ) {
			try {
				return $obj->getAttribute( $name );
			} catch ( \Throwable $e ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_order( string $order_ref ): ?NormalizedOrder {
		$order = $this->load( $order_ref );
		if ( ! $order ) {
			return null;
		}

		$email   = (string) ( $this->attr( $order, 'email' ) ?? '' );
		$user_id = (int) ( $this->attr( $order, 'user_id' ) ?? 0 );
		$number  = (string) ( $this->attr( $order, 'order_number' ) ?? $this->attr( $order, 'number' ) ?? $order_ref );
		$status  = self::eligible_status( (string) ( $this->attr( $order, 'status' ) ?? '' ) );

		$created   = $this->to_immutable( $this->attr( $order, 'date_created' ) );
		$completed = $this->to_immutable( $this->attr( $order, 'date_completed' ) );

		return new NormalizedOrder(
			$this->key(),
			$order_ref,
			$number,
			$email,
			$user_id,
			strtoupper( $this->billing_country( $order, $order_ref ) ),
			$status,
			(string) $this->get_meta( $order_ref, 'locale' ),
			$created,
			$completed ?? $created,
			$completed,
			$this->map_items( $order ),
			$this->has_vat_number( $order_ref ),
			$this->is_renewal_order( $order_ref ),
			$this->subscription_ref( $order_ref )
		);
	}

	/**
	 * Load the EDD Recurring subscription whose parent_payment_id is this order — i.e.
	 * the subscription created by this order, present only on the INITIAL order.
	 * Returns a full EDD_Subscription (so can_cancel()/cancel() work). Guarded.
	 *
	 * @param string $order_ref Order id.
	 * @return \EDD_Subscription|null
	 */
	private function edd_subscription_for_parent( string $order_ref ) {
		if ( ! class_exists( '\\EDD_Subscriptions_DB' ) || ! class_exists( '\\EDD_Subscription' ) ) {
			return null; // EDD Recurring not active — fail open.
		}
		try {
			$db   = new \EDD_Subscriptions_DB();
			$rows = $db->get_subscriptions( array( 'parent_payment_id' => (int) $order_ref, 'number' => 1 ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return null;
		}
		$row = reset( $rows );
		$id  = is_object( $row ) ? (int) ( $row->id ?? 0 ) : 0;
		if ( $id <= 0 ) {
			return null;
		}
		try {
			$sub = new \EDD_Subscription( $id );
		} catch ( \Throwable $e ) {
			return null;
		}
		// An EDD_Subscription built from a missing id has id === 0.
		return ( is_object( $sub ) && (int) ( $sub->id ?? 0 ) > 0 ) ? $sub : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Fails open (false): a state we cannot determine is treated as a normal order so a
	 * legitimate withdrawal button is never hidden. We only act on the high-confidence
	 * EDD Recurring renewal status; renewal orders that EDD completes with a plain
	 * 'complete' status may not be detected here — by design (over-showing the button is
	 * the safe failure). Integrators can refine via the `webwakeupwdb_order_is_renewal` filter.
	 */
	public function is_renewal_order( string $order_ref ): bool {
		$is_renewal = false;
		$order      = $this->load( $order_ref );
		if ( is_object( $order ) ) {
			// The subscription's parent payment is the INITIAL order — never a renewal.
			if ( ! $this->edd_subscription_for_parent( $order_ref ) ) {
				// EDD Recurring marks renewal payments with the 'edd_subscription' status.
				$status = strtolower( (string) ( $this->attr( $order, 'status' ) ?? '' ) );
				if ( 'edd_subscription' === $status ) {
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
		$sub = $this->edd_subscription_for_parent( $order_ref );
		if ( is_object( $sub ) ) {
			$id = $this->attr( $sub, 'id' );
			return null !== $id ? (string) $id : '';
		}
		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Guarded + OFF by default (only runs on merchant opt-in). Honours EDD Recurring's
	 * own can_cancel() gate; the RequestsDashboard always shows a manual reminder so a
	 * no-op never strands the merchant.
	 */
	public function cancel_subscription( string $order_ref ): bool {
		$sub = $this->edd_subscription_for_parent( $order_ref );
		if ( ! is_object( $sub ) || ! method_exists( $sub, 'cancel' ) ) {
			return false;
		}
		try {
			if ( method_exists( $sub, 'can_cancel' ) && ! $sub->can_cancel() ) {
				return false;
			}
			$sub->cancel();
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Resolve the billing country (ISO-2) for an EDD order.
	 *
	 * @param object $order     Order model.
	 * @param string $order_ref Order id.
	 * @return string
	 */
	private function billing_country( $order, string $order_ref ): string {
		// Prefer the order's address relation/method, then the function helper.
		if ( is_object( $order ) && method_exists( $order, 'get_address' ) ) {
			try {
				$addr    = $order->get_address();
				$country = is_object( $addr ) ? (string) ( $this->attr( $addr, 'country' ) ?? '' ) : '';
				if ( '' !== $country ) {
					return $country;
				}
			} catch ( \Throwable $e ) {
				$country = '';
			}
		}
		if ( function_exists( 'edd_get_order_address' ) ) {
			try {
				$addr    = edd_get_order_address( (int) $order_ref );
				$country = is_object( $addr ) ? (string) ( $this->attr( $addr, 'country' ) ?? '' ) : '';
				if ( '' !== $country ) {
					return $country;
				}
			} catch ( \Throwable $e ) {
				$country = '';
			}
		}
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_refunded( string $order_ref ): bool {
		$order  = $this->load( $order_ref );
		$status = $order ? strtolower( (string) ( $this->attr( $order, 'status' ) ?? '' ) ) : '';
		return in_array( $status, array( 'refunded', 'partially_refunded' ), true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify_owner( string $order_ref, int $user_id ): bool {
		$order = $this->load( $order_ref );
		if ( ! $order || $user_id <= 0 ) {
			return false;
		}
		return (int) ( $this->attr( $order, 'user_id' ) ?? 0 ) === $user_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify_guest_key( string $order_ref, string $key ): bool {
		$order = $this->load( $order_ref );
		if ( ! $order || '' === $key ) {
			return false;
		}
		$hash = (string) ( $this->attr( $order, 'payment_key' ) ?? '' );
		return '' !== $hash && hash_equals( $hash, $key );
	}

	/**
	 * {@inheritDoc}
	 */
	public function mark_withdrawal_requested( string $order_ref ): bool {
		// EDD status transitions are merchant-driven; record our own state + a note.
		$this->set_meta( $order_ref, 'native_status_note', 'withdrawal_requested' );
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function add_note( string $order_ref, string $note ): void {
		if ( function_exists( 'edd_add_note' ) ) {
			try {
				edd_add_note(
					array(
						'object_id'   => (int) $order_ref,
						'object_type' => 'order',
						'content'     => $note,
					)
				);
				return;
			} catch ( \Throwable $e ) {
				// fall through to meta log.
			}
		}
		$notes   = (array) $this->get_meta( $order_ref, 'notes' );
		$notes[] = array(
			'at'   => gmdate( 'c' ),
			'note' => $note,
		);
		$this->set_meta( $order_ref, 'notes', $notes );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_meta( string $order_ref, string $key ) {
		if ( ! function_exists( 'edd_get_order_meta' ) ) {
			return '';
		}
		$value = edd_get_order_meta( (int) $order_ref, 'webwakeupwdb_' . $key, true );
		return ( null === $value || false === $value ) ? '' : $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_meta( string $order_ref, string $key, $value ): void {
		if ( function_exists( 'edd_update_order_meta' ) ) {
			edd_update_order_meta( (int) $order_ref, 'webwakeupwdb_' . $key, $value );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function batch_meta( string $order_ref, array $pairs ): void {
		foreach ( $pairs as $key => $value ) {
			$this->set_meta( $order_ref, (string) $key, $value );
		}
	}

	/**
	 * Map EDD order items to the normalized shape (category-aware).
	 *
	 * @param object $order Model.
	 * @return array<int,array<string,mixed>>
	 */
	private function map_items( $order ): array {
		$items = array();

		$raw_items = array();
		if ( is_object( $order ) && method_exists( $order, 'get_items' ) ) {
			try {
				$raw_items = $order->get_items();
			} catch ( \Throwable $e ) {
				$raw_items = array();
			}
		}
		if ( empty( $raw_items ) ) {
			$raw_items = (array) ( $this->attr( $order, 'items' ) ?? array() );
		}

		if ( is_iterable( $raw_items ) ) {
			foreach ( $raw_items as $it ) {
				$pid  = (int) ( $this->attr( $it, 'product_id' ) ?? $this->attr( $it, 'download_id' ) ?? 0 );
				$cats = array();
				if ( $pid > 0 && function_exists( 'wp_get_post_terms' ) ) {
					$terms = wp_get_post_terms( $pid, 'download_category', array( 'fields' => 'ids' ) );
					if ( is_array( $terms ) ) {
						$cats = array_map( 'intval', $terms );
					}
				}
				$items[] = array(
					'product_id'   => $pid,
					'name'         => (string) ( $this->attr( $it, 'product_name' ) ?? $this->attr( $it, 'name' ) ?? '' ),
					'qty'          => (int) ( $this->attr( $it, 'quantity' ) ?? 1 ),
					'virtual'      => true,  // EDD sells digital downloads.
					'downloadable' => true,
					'type'         => 'digital',
					'category_ids' => $cats,
				);
			}
		}

		return $items;
	}

	/**
	 * VAT/business detection via our own order meta (set by integrators if needed).
	 *
	 * @param string $order_ref Order id.
	 * @return bool
	 */
	private function has_vat_number( string $order_ref ): bool {
		$vat = (string) $this->get_meta( $order_ref, 'vat_number' );
		/**
		 * Override B2B (VAT-number) detection for an EDD order.
		 *
		 * @param bool   $found     Whether a VAT number was detected.
		 * @param string $order_ref Order id.
		 */
		return (bool) apply_filters( 'webwakeupwdb_edd_order_has_vat_number', '' !== $vat, $order_ref );
	}

	/**
	 * Convert a date-ish value to DateTimeImmutable (UTC), or null.
	 *
	 * @param mixed $value Date value (EDD stores GMT 'Y-m-d H:i:s' strings).
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
			return new \DateTimeImmutable( (string) $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
