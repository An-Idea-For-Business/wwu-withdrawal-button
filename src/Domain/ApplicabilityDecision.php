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

	/** @var bool Whether to render the withdrawal function at all. */
	public $show;

	/** @var bool Whether it is legally mandatory (vs voluntary). */
	public $mandatory;

	/** @var string Machine-readable reason slug. */
	public $reason;

	/** @var string Consumer country. */
	public $country;

	/**
	 * Constructor.
	 *
	 * @param bool   $show      Whether to render the withdrawal function at all.
	 * @param bool   $mandatory Whether it is legally mandatory (vs voluntary).
	 * @param string $reason    Machine-readable reason slug.
	 * @param string $country   Consumer country.
	 */
	public function __construct( bool $show, bool $mandatory, string $reason, string $country ) {
		$this->show      = $show;
		$this->mandatory = $mandatory;
		$this->reason    = $reason;
		$this->country   = $country;
	}
}
