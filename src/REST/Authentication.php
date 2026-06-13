<?php
/**
 * REST permission helpers.
 *
 * CRITICAL: permission callbacks must NOT re-verify the WordPress REST nonce.
 * WP REST already validates the X-WP-Nonce header (action 'wp_rest') via
 * rest_cookie_check_errors BEFORE the permission_callback runs. Re-verifying with
 * a plugin-specific nonce action would mismatch and 403 every authenticated call.
 * We therefore check capability + (for debug endpoints) the audience gate only.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\REST;

use WWU\WithdrawalButton\Debug\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission-callback factory.
 */
final class Authentication {

	/**
	 * Resolved admin capability.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return (string) apply_filters( 'wwu_wb_admin_capability', 'manage_woocommerce' );
	}

	/**
	 * Permission callback: logged-in + admin capability.
	 *
	 * @return callable
	 */
	public static function require_admin(): callable {
		return static function () {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error(
					'wwu_wb_unauthorized',
					__( 'Authentication required.', 'wwu-withdrawal-button' ),
					array( 'status' => 401 )
				);
			}
			if ( ! current_user_can( self::capability() ) ) {
				return new \WP_Error(
					'wwu_wb_forbidden',
					__( 'Insufficient permissions.', 'wwu-withdrawal-button' ),
					array( 'status' => 403 )
				);
			}
			return true;
		};
	}

	/**
	 * Permission callback for /debug/* endpoints: admin capability + audience gate.
	 *
	 * @return callable
	 */
	public static function require_debug_audience(): callable {
		return static function () {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error(
					'wwu_wb_unauthorized',
					__( 'Authentication required.', 'wwu-withdrawal-button' ),
					array( 'status' => 401 )
				);
			}
			if ( ! current_user_can( self::capability() ) ) {
				return new \WP_Error(
					'wwu_wb_forbidden',
					__( 'Insufficient permissions.', 'wwu-withdrawal-button' ),
					array( 'status' => 403 )
				);
			}
			if ( ! Audience::is_current_user() ) {
				return new \WP_Error(
					'wwu_wb_audience_closed',
					__( 'Debug is not enabled for your account. Enable it under WWU Withdrawal Button → Settings → Debug.', 'wwu-withdrawal-button' ),
					array( 'status' => 403 )
				);
			}
			return true;
		};
	}
}
