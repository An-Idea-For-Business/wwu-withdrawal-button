<?php
/**
 * Viewer-aware withdrawal-form URL builder (single source of truth).
 *
 * Every surface that links to the withdrawal form — the My Account button, the
 * [webwakeupwdb_button] / [webwakeupwdb_form] shortcodes, the Gutenberg block, and the
 * eligible-orders list — must build the destination the same way:
 *
 *   - Logged-in customers go to the owner-verified My Account endpoint.
 *   - Guests (no account) would be bounced to the login screen by
 *     wc_get_account_endpoint_url(), so they are routed to the PUBLIC form page
 *     carrying the order reference + WooCommerce order key — the same
 *     pre-authenticated link the order-confirmation email uses (OrderEmailLink),
 *     so they reach the form with no login.
 *
 * Centralising this here prevents the drift that left the shortcode/list still
 * pointing guests at the login screen after the My Account button was fixed.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Frontend;

use WebWakeUpWdb\WithdrawalButton\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the withdrawal-form URL for the current viewer.
 */
final class WithdrawalUrl {

	/**
	 * Resolve the withdrawal-form URL for the current viewer.
	 *
	 * @param string      $order_ref Order reference.
	 * @param string|null $order_key Optional WooCommerce order key for guest
	 *                               pre-authentication. When null it is looked up
	 *                               from the order (WooCommerce only).
	 * @return string
	 */
	public static function resolve( string $order_ref, ?string $order_key = null ): string {
		// Logged-in: the owner-verified My Account endpoint (unchanged behaviour).
		if ( is_user_logged_in() ) {
			return self::account_url( $order_ref );
		}

		// Guest: the public form page (no login). Falls back to the account
		// endpoint only when no public form page is configured.
		$page_id = (int) ( Settings::main()['public_form_page_id'] ?? 0 );
		if ( $page_id <= 0 ) {
			return self::account_url( $order_ref );
		}

		$args = array( 'webwakeupwdb_order' => rawurlencode( $order_ref ) );
		$key  = ( null === $order_key || '' === $order_key ) ? self::woocommerce_order_key( $order_ref ) : $order_key;
		if ( '' !== $key ) {
			$args['key'] = rawurlencode( $key );
		}

		return add_query_arg( $args, get_permalink( $page_id ) );
	}

	/**
	 * The owner-verified My Account endpoint URL for an order.
	 *
	 * @param string $order_ref Order reference.
	 * @return string
	 */
	private static function account_url( string $order_ref ): string {
		$slug = sanitize_title( (string) ( Settings::main()['endpoint_slug'] ?? 'wwu-withdrawal' ) );
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return add_query_arg( 'webwakeupwdb_order', rawurlencode( $order_ref ), wc_get_account_endpoint_url( $slug ) );
		}
		return add_query_arg( 'webwakeupwdb_order', rawurlencode( $order_ref ) );
	}

	/**
	 * Look up the WooCommerce order key (guest pre-authentication token).
	 *
	 * @param string $order_ref Order reference (WooCommerce order ID).
	 * @return string Empty string when this is not a resolvable WooCommerce order.
	 */
	private static function woocommerce_order_key( string $order_ref ): string {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return '';
		}
		$order = wc_get_order( $order_ref );
		return $order instanceof \WC_Order ? (string) $order->get_order_key() : '';
	}
}
