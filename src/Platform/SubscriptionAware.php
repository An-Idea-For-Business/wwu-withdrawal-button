<?php
/**
 * Optional capability for platform adapters whose store runs a subscription plugin.
 *
 * An adapter implements this ONLY when its subscription plugin is active (WooCommerce
 * Subscriptions / FluentCart native subscriptions / EDD Recurring Payments). The
 * resolver/UI use `instanceof SubscriptionAware` so adapters without subscriptions stay
 * untouched and the 23-method {@see OrderDataSource} contract is unchanged.
 *
 * Used to (a) suppress the withdrawal button on renewal orders — a renewal does NOT
 * restart the 14-day right (Art. 9 CRD) — and (b) cancel the subscription when a
 * consumer withdraws from its initial order.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscription-aware adapter capability.
 */
interface SubscriptionAware {

	/**
	 * Whether the given order is a subscription RENEWAL order (not the initial order).
	 *
	 * MUST fail open: when the subscription state cannot be determined, return false
	 * (treat as a normal order) so a legitimate withdrawal button is never wrongly hidden.
	 *
	 * @param string $order_ref Order reference.
	 * @return bool
	 */
	public function is_renewal_order( string $order_ref ): bool;

	/**
	 * The platform subscription id tied to the order, or '' when there is none.
	 *
	 * @param string $order_ref Order reference.
	 * @return string
	 */
	public function subscription_ref( string $order_ref ): string;

	/**
	 * Cancel the subscription tied to the order (stop future renewals).
	 *
	 * @param string $order_ref Order reference.
	 * @return bool True on success.
	 */
	public function cancel_subscription( string $order_ref ): bool;
}
