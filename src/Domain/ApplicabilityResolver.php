<?php
/**
 * Applicability resolver.
 *
 * Decides, per order, whether to show the withdrawal function and whether it is
 * legally mandatory, following the consumer's country (Rome I Art. 6), the
 * configured mode, B2B detection, and Art. 59 exceptions.
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
 * Resolves applicability decisions.
 */
final class ApplicabilityResolver {

	/**
	 * Art. 59 evaluator.
	 *
	 * @var ArticleFiftyNineEvaluator
	 */
	private $art59;

	/**
	 * Constructor.
	 *
	 * @param ArticleFiftyNineEvaluator|null $art59 Evaluator (optional, for injection in tests).
	 */
	public function __construct( ?ArticleFiftyNineEvaluator $art59 = null ) {
		$this->art59 = $art59 ?? new ArticleFiftyNineEvaluator();
	}

	/**
	 * Decide applicability for an order.
	 *
	 * @param NormalizedOrder $order Order.
	 * @return ApplicabilityDecision
	 */
	public function decide( NormalizedOrder $order ): ApplicabilityDecision {
		$config  = \WWU\WithdrawalButton\Core\Settings::get( 'wwu_wb_applicability' );
		$mode    = (string) ( $config['mode'] ?? 'eu_eea_only' );
		$country = strtoupper( $order->country );

		$decision = $this->evaluate( $order, $mode, $country, $config );

		/**
		 * Filter the final applicability decision for an order.
		 *
		 * @param ApplicabilityDecision $decision Decision.
		 * @param NormalizedOrder       $order    Order.
		 */
		return apply_filters( 'wwu_wb_applicability_decision', $decision, $order );
	}

	/**
	 * Core evaluation logic.
	 *
	 * @param NormalizedOrder $order   Order.
	 * @param string          $mode    Applicability mode.
	 * @param string          $country Consumer country.
	 * @param array           $config  Applicability config.
	 * @return ApplicabilityDecision
	 */
	private function evaluate( NormalizedOrder $order, string $mode, string $country, array $config ): ApplicabilityDecision {
		// Order-status eligibility: a withdrawal right presupposes a concluded
		// contract. Never show the function on failed / unpaid / cancelled /
		// refunded / draft orders (covers WooCommerce + FluentCart via the
		// normalized status).
		if ( ! $this->is_eligible_status( $order ) ) {
			return new ApplicabilityDecision( false, false, 'ineligible_status', $country );
		}

		// B2B: a provided VAT number is treated as out of scope when configured.
		if ( $order->has_vat_number && ! empty( $config['b2b_vat_out_of_scope'] ) ) {
			return new ApplicabilityDecision( false, false, 'b2b_vat', $country );
		}

		// Substantive right of withdrawal (Art. 59): no withdrawable item → no function.
		if ( ! $this->art59->has_withdrawable_item( $order ) ) {
			return new ApplicabilityDecision( false, false, 'no_withdrawal_right', $country );
		}

		$in_scope = Countries::is_in_scope( $country );

		switch ( $mode ) {
			case 'always':
				// Shown to everyone; mandatory only for in-scope consumers.
				return new ApplicabilityDecision( true, $in_scope, $in_scope ? 'mandatory' : 'voluntary', $country );

			case 'custom_list':
				$list    = array_map( 'strtoupper', (array) ( $config['custom_countries'] ?? array() ) );
				$in_list = in_array( $country, $list, true );
				return new ApplicabilityDecision( $in_list, $in_list && $in_scope, $in_list ? ( $in_scope ? 'mandatory' : 'voluntary_custom' ) : 'out_of_list', $country );

			case 'eu_eea_only':
			default:
				// Switzerland (and other non-EU/EEA) consumers: no statutory mandate.
				// (status/B2B/Art.59 gates already applied above.)
				if ( ! $in_scope ) {
					$reason = Countries::is_switzerland( $country ) ? 'switzerland_voluntary' : 'out_of_scope';
					return new ApplicabilityDecision( false, false, $reason, $country );
				}
				return new ApplicabilityDecision( true, true, 'mandatory', $country );
		}
	}

	/**
	 * Whether the order's status corresponds to a concluded, withdrawable contract.
	 *
	 * @param NormalizedOrder $order Order.
	 * @return bool
	 */
	private function is_eligible_status( NormalizedOrder $order ): bool {
		$status = strtolower( (string) $order->status );

		// Allowlist of contract-bearing statuses across WooCommerce + FluentCart.
		$eligible = array( 'processing', 'completed', 'on-hold', 'paid', 'partially-paid', 'partially_paid', 'shipped', 'delivered' );

		/**
		 * Filter the order statuses for which a withdrawal right is presumed to exist.
		 *
		 * @param string[]        $eligible Lower-case unprefixed status slugs.
		 * @param NormalizedOrder $order    Order.
		 */
		$eligible = array_map( 'strtolower', (array) apply_filters( 'wwu_wb_eligible_statuses', $eligible, $order ) );

		return in_array( $status, $eligible, true );
	}
}
