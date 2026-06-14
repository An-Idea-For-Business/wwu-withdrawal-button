<?php
/**
 * Art. 59 Codice del Consumo / Art. 16 CRD exclusions evaluator.
 *
 * The withdrawal function modernises the PROCEDURE, not the substantive
 * exceptions. This evaluator decides, per order, whether at least one line item
 * still carries a right of withdrawal (mixed carts: show the function if ANY
 * item is withdrawable). Auto-detection is conservative; merchants override via
 * excluded products/categories and the wwu_wb_excluded_product_ids filter.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Domain;

use WWU\WithdrawalButton\Platform\NormalizedOrder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-order withdrawal-eligibility evaluator.
 */
final class ArticleFiftyNineEvaluator {

	/**
	 * Whether the order has at least one withdrawable item.
	 *
	 * @param NormalizedOrder $order Order.
	 * @return bool
	 */
	public function has_withdrawable_item( NormalizedOrder $order ): bool {
		// The right of withdrawal is the DEFAULT; Art. 59 exceptions are the
		// exception. If we cannot read the order's line items (e.g. a platform whose
		// item relation did not load), we must NOT silently hide the function —
		// default to "withdrawable" and let merchants exempt specific products.
		if ( empty( $order->items ) ) {
			return true;
		}

		foreach ( $order->items as $item ) {
			if ( $this->item_is_withdrawable( $item, $order ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a single line item is withdrawable.
	 *
	 * An item loses the withdrawal right only when it is tagged with a statutory
	 * exception reason that ACTUALLY applies:
	 *  - unconditional, non-seal reason → exempt;
	 *  - conditional reason (service performed / digital immediate) → exempt only
	 *    when the consumer's consent + acknowledgement was captured (P2 — until then
	 *    the item stays withdrawable, fail-safe toward the consumer's right);
	 *  - seal-based reason → never auto-hides at order time (depends on unsealing).
	 *
	 * @param array           $item  Normalized item.
	 * @param NormalizedOrder $order Order (for status + consent context).
	 * @return bool
	 */
	private function item_is_withdrawable( array $item, NormalizedOrder $order ): bool {
		$reason = ExemptionResolver::reason_for_item( $item, $order );
		if ( null !== $reason ) {
			$def = ExceptionTypes::get( $reason );
			if ( null !== $def ) {
				if ( ! empty( $def['seal_based'] ) ) {
					return true;
				}
				if ( ! empty( $def['conditional'] ) ) {
					return ! $this->consent_captured( $order, $item, (string) $reason );
				}
				return false;
			}
		}

		// Legacy crude auto-detect (default OFF since alpha.24): delivered virtual/
		// downloadable content on a completed order. Kept for back-compat; the proper
		// path is tagging '59_o' and capturing consent.
		$settings = (array) \WWU\WithdrawalButton\Core\Settings::get( 'wwu_wb_exclusions' );
		if ( ! empty( $settings['auto_detect_virtual'] ) ) {
			$is_digital = ! empty( $item['virtual'] ) || ! empty( $item['downloadable'] );
			if ( $is_digital && 'completed' === $order->status ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the consumer's express consent + acknowledgement was captured for a
	 * conditional exemption on this order (Art. 16(1)(a)/(m); Art. 59(1)(a)/(o)).
	 *
	 * The consent is provided through the `wwu_wb_exemption_consent` filter, which
	 * the checkout-consent layer (P2) hooks to return the order's stored consent
	 * entries. Until that layer ships, the filter returns nothing → consent is never
	 * present → conditional reasons keep the button.
	 *
	 * @param NormalizedOrder $order  Order.
	 * @param array           $item   Line item.
	 * @param string          $reason Reason id.
	 * @return bool
	 */
	private function consent_captured( NormalizedOrder $order, array $item, string $reason ): bool {
		/**
		 * Filter the captured exemption consent entries for an order.
		 *
		 * @param array           $consent List of { product_id, reason_id, ... } entries.
		 * @param NormalizedOrder $order   Order.
		 * @param array           $item    Line item being evaluated.
		 * @param string          $reason  Reason id.
		 */
		$consent = (array) apply_filters( 'wwu_wb_exemption_consent', array(), $order, $item, $reason );
		if ( empty( $consent ) ) {
			return false;
		}

		$item_product = (int) ( $item['product_id'] ?? 0 );
		foreach ( $consent as $entry ) {
			$entry         = (array) $entry;
			$entry_reason  = (string) ( $entry['reason_id'] ?? '' );
			$entry_product = (int) ( $entry['product_id'] ?? 0 );
			if ( $entry_reason === $reason && ( 0 === $entry_product || $entry_product === $item_product ) ) {
				return true;
			}
		}
		return false;
	}
}
