<?php
/**
 * Platform adapter interface.
 *
 * One implementation per e-commerce platform (WooCommerce, FluentCart). The
 * Domain and Frontend layers depend only on this contract, never on platform
 * internals, so a new platform is added without touching business logic.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalised order data source.
 */
interface OrderDataSource {

	/**
	 * Stable adapter key ('woocommerce' | 'fluentcart').
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Whether the underlying platform is active on this site.
	 *
	 * @return bool
	 */
	public function is_active(): bool;

	/**
	 * Load and normalise an order, or null if not found.
	 *
	 * @param string $order_ref Order reference.
	 * @return NormalizedOrder|null
	 */
	public function get_order( string $order_ref ): ?NormalizedOrder;

	/**
	 * Whether the given user owns the order (logged-in path).
	 *
	 * @param string $order_ref Order reference.
	 * @param int    $user_id   User ID.
	 * @return bool
	 */
	public function verify_owner( string $order_ref, int $user_id ): bool;

	/**
	 * Whether a guest order key matches (guest path).
	 *
	 * @param string $order_ref Order reference.
	 * @param string $key       Order key / token from the URL.
	 * @return bool
	 */
	public function verify_guest_key( string $order_ref, string $key ): bool;

	/**
	 * Transition the order to the "withdrawal requested" status.
	 *
	 * @param string $order_ref Order reference.
	 * @return bool
	 */
	public function mark_withdrawal_requested( string $order_ref ): bool;

	/**
	 * Append a human-readable note to the order timeline.
	 *
	 * @param string $order_ref Order reference.
	 * @param string $note      Note text.
	 * @return void
	 */
	public function add_note( string $order_ref, string $note ): void;

	/**
	 * Read a plugin meta value (HPOS-safe).
	 *
	 * @param string $order_ref Order reference.
	 * @param string $key       Meta key (without prefix).
	 * @return mixed
	 */
	public function get_meta( string $order_ref, string $key );

	/**
	 * Write a plugin meta value (HPOS-safe).
	 *
	 * @param string $order_ref Order reference.
	 * @param string $key       Meta key (without prefix).
	 * @param mixed  $value     Value.
	 * @return void
	 */
	public function set_meta( string $order_ref, string $key, $value ): void;
}
