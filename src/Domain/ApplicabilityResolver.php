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
		$config  = (array) get_option( 'wwu_wb_applicability', array() );
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
				if ( ! $in_scope ) {
					$reason = Countries::is_switzerland( $country ) ? 'switzerland_voluntary' : 'out_of_scope';
					return new ApplicabilityDecision( false, false, $reason, $country );
				}
				return new ApplicabilityDecision( true, true, 'mandatory', $country );
		}
	}
}
