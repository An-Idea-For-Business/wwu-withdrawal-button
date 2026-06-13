<?php
/**
 * Result of an applicability evaluation for one order.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable applicability decision.
 */
final class ApplicabilityDecision {

	/**
	 * Constructor.
	 *
	 * @param bool   $show      Whether to render the withdrawal function at all.
	 * @param bool   $mandatory Whether it is legally mandatory (vs voluntary).
	 * @param string $reason    Machine-readable reason slug.
	 * @param string $country   Consumer country.
	 */
	public function __construct(
		public bool $show,
		public bool $mandatory,
		public string $reason,
		public string $country
	) {}
}
