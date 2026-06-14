<?php
/**
 * Supplies an order's captured exemption-consent entries to the evaluator.
 *
 * The {@see \WWU\WithdrawalButton\Domain\ArticleFiftyNineEvaluator} asks, via the
 * `wwu_wb_exemption_consent` filter, "was the consumer's express consent +
 * acknowledgement captured for this conditional exemption?". This reader answers
 * by reading the consent entries the checkout-consent layer stored on the order
 * meta (`_wwu_wb_consent`), through the platform adapter — so it is platform-
 * agnostic: any platform whose adapter persisted the entries (WooCommerce today;
 * FluentCart once its checkout hook lands) is understood without changing the
 * evaluator.
 *
 * Reading happens through the adapter's HPOS-safe get_meta(), never raw post meta.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Frontend;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Platform\NormalizedOrder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stored-consent provider.
 */
final class ConsentReader {

	/**
	 * Register the filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wwu_wb_exemption_consent', array( $this, 'provide' ), 10, 4 );
	}

	/**
	 * Merge the order's stored consent entries into the evaluator's list.
	 *
	 * @param mixed                 $consent Incoming consent entries (array).
	 * @param NormalizedOrder|mixed $order   Order being evaluated.
	 * @param array|mixed           $item    Line item (unused here; matched by the evaluator).
	 * @param string|mixed          $reason  Reason id (unused here).
	 * @return array<int,array<string,mixed>>
	 */
	public function provide( $consent, $order = null, $item = null, $reason = '' ): array {
		unset( $item, $reason );

		$consent = is_array( $consent ) ? $consent : array();

		if ( ! $order instanceof NormalizedOrder ) {
			return $consent;
		}

		$adapter = Services::instance()->platforms->get( $order->platform );
		if ( null === $adapter ) {
			return $consent;
		}

		$stored = $adapter->get_meta( $order->order_ref, 'consent' );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return $consent;
		}

		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && isset( $entry['reason_id'] ) ) {
				$consent[] = $entry;
			}
		}

		return $consent;
	}
}
