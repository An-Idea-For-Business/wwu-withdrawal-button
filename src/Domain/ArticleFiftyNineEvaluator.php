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
		$settings        = (array) get_option( 'wwu_wb_exclusions', array() );
		$excluded_cats   = array_map( 'intval', (array) ( $settings['excluded_category_ids'] ?? array() ) );
		$excluded_prods  = array_map( 'intval', (array) ( $settings['excluded_product_ids'] ?? array() ) );
		$auto_detect     = ! empty( $settings['auto_detect_virtual'] );

		/**
		 * Filter the set of excluded product IDs for an order (e.g. all subscriptions).
		 *
		 * @param int[]           $ids   Excluded product IDs.
		 * @param NormalizedOrder $order Order.
		 */
		$excluded_prods = array_map( 'intval', (array) apply_filters( 'wwu_wb_excluded_product_ids', $excluded_prods, $order ) );

		foreach ( $order->items as $item ) {
			if ( $this->item_is_withdrawable( $item, $excluded_cats, $excluded_prods, $auto_detect, $order ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a single line item is withdrawable.
	 *
	 * @param array           $item           Normalized item.
	 * @param int[]           $excluded_cats  Excluded category IDs.
	 * @param int[]           $excluded_prods Excluded product IDs.
	 * @param bool            $auto_detect    Whether to auto-exclude delivered digital.
	 * @param NormalizedOrder $order          Order (for status context).
	 * @return bool
	 */
	private function item_is_withdrawable( array $item, array $excluded_cats, array $excluded_prods, bool $auto_detect, NormalizedOrder $order ): bool {
		$product_id = (int) ( $item['product_id'] ?? 0 );
		if ( $product_id > 0 && in_array( $product_id, $excluded_prods, true ) ) {
			return false;
		}

		$category_ids = array_map( 'intval', (array) ( $item['category_ids'] ?? array() ) );
		if ( ! empty( $excluded_cats ) && array_intersect( $category_ids, $excluded_cats ) ) {
			return false;
		}

		// Auto-detect: delivered virtual/downloadable content typically loses the
		// withdrawal right once execution begins (Art. 59 lett. o / Art. 16(m) CRD).
		// We approximate "execution begun" with a completed order.
		if ( $auto_detect ) {
			$is_digital = ! empty( $item['virtual'] ) || ! empty( $item['downloadable'] );
			if ( $is_digital && 'completed' === $order->status ) {
				return false;
			}
		}

		return true;
	}
}
