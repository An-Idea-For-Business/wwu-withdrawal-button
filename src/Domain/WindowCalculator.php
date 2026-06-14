<?php
/**
 * Withdrawal-window calculator (INFORMATIONAL ONLY).
 *
 * Computes the 14-day deadline to (a) show the consumer the remaining days and
 * (b) flag late submissions to the admin. It is deliberately NOT used to hide or
 * disable the button: hiding the function during a still-valid period (e.g. due
 * to an unknown delivery date) would itself be a dark-pattern/compliance risk.
 * The merchant decides final validity of any late request.
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
 * Computes (informational) withdrawal deadlines.
 */
final class WindowCalculator {

	/**
	 * Configured window length in days.
	 *
	 * @param NormalizedOrder $order Order.
	 * @return int
	 */
	public function window_days( NormalizedOrder $order ): int {
		$settings = \WWU\WithdrawalButton\Core\Settings::main();
		$days     = isset( $settings['withdrawal_window_days'] ) ? (int) $settings['withdrawal_window_days'] : 14;
		/**
		 * Filter the withdrawal-window length (days) for an order.
		 *
		 * @param int             $days  Window length.
		 * @param NormalizedOrder $order The order.
		 */
		$days = (int) apply_filters( 'wwu_wb_withdrawal_window_days', $days, $order );
		return max( 1, min( 365, $days ) );
	}

	/**
	 * Compute the deadline, or null if the start date is unknown.
	 *
	 * @param NormalizedOrder $order Order.
	 * @return \DateTimeImmutable|null
	 */
	public function deadline( NormalizedOrder $order ): ?\DateTimeImmutable {
		$start = $order->window_start();
		if ( ! $start ) {
			return null;
		}
		$days     = $this->window_days( $order );
		$deadline = $start->modify( '+' . $days . ' days' );
		/**
		 * Filter the computed deadline (e.g. to use a real delivery date).
		 *
		 * @param \DateTimeImmutable $deadline Deadline.
		 * @param NormalizedOrder    $order    Order.
		 * @param int                $days     Window length.
		 */
		return apply_filters( 'wwu_wb_compute_deadline', $deadline, $order, $days );
	}

	/**
	 * Days remaining (negative if past deadline). Null if unknown.
	 *
	 * @param NormalizedOrder $order Order.
	 * @return int|null
	 */
	public function days_remaining( NormalizedOrder $order ): ?int {
		$deadline = $this->deadline( $order );
		if ( ! $deadline ) {
			return null;
		}
		$now  = new \DateTimeImmutable( 'now' );
		$diff = (int) floor( ( $deadline->getTimestamp() - $now->getTimestamp() ) / DAY_IN_SECONDS );
		return $diff;
	}

	/**
	 * Whether a submission "now" is within the window. Unknown start = treated as
	 * within (never block on missing data).
	 *
	 * @param NormalizedOrder $order Order.
	 * @return bool
	 */
	public function is_within_window( NormalizedOrder $order ): bool {
		$deadline = $this->deadline( $order );
		if ( ! $deadline ) {
			return true;
		}
		return ( new \DateTimeImmutable( 'now' ) )->getTimestamp() <= $deadline->getTimestamp();
	}
}
