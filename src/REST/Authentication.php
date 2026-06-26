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
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\REST;

use WebWakeUpWdb\WithdrawalButton\Debug\Audience;

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
		return (string) apply_filters( 'webwakeupwdb_admin_capability', 'manage_woocommerce' );
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
					'webwakeupwdb_unauthorized',
					__( 'Authentication required.', 'wwu-withdrawal-button' ),
					array( 'status' => 401 )
				);
			}
			if ( ! current_user_can( self::capability() ) ) {
				return new \WP_Error(
					'webwakeupwdb_forbidden',
					__( 'Insufficient permissions.', 'wwu-withdrawal-button' ),
					array( 'status' => 403 )
				);
			}
			return true;
		};
	}

	/**
	 * Simple per-user, per-bucket rate limit (DoS guard for the read API).
	 *
	 * Authenticated admins only ever reach these endpoints, so this is a soft
	 * abuse cap rather than a security control. Keyed by the current user id so one
	 * caller cannot exhaust another's budget. Returns true while under the cap.
	 *
	 * @param string $bucket Logical bucket (e.g. 'read_api').
	 * @param int    $max    Max requests per window.
	 * @param int    $window Window length in seconds.
	 * @return bool True if the request is allowed.
	 */
	public static function enforce_rate_limit( string $bucket, int $max = 120, int $window = 60 ): bool {
		$key  = 'webwakeupwdb_rl_' . md5( $bucket . '|' . (int) get_current_user_id() );
		$hits = (int) get_transient( $key );
		if ( $hits >= $max ) {
			return false;
		}
		set_transient( $key, $hits + 1, $window );
		return true;
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
					'webwakeupwdb_unauthorized',
					__( 'Authentication required.', 'wwu-withdrawal-button' ),
					array( 'status' => 401 )
				);
			}
			if ( ! current_user_can( self::capability() ) ) {
				return new \WP_Error(
					'webwakeupwdb_forbidden',
					__( 'Insufficient permissions.', 'wwu-withdrawal-button' ),
					array( 'status' => 403 )
				);
			}
			if ( ! Audience::is_current_user() ) {
				return new \WP_Error(
					'webwakeupwdb_audience_closed',
					__( 'Debug is not enabled for your account. Enable it under WWU Withdrawal Button → Settings → Debug.', 'wwu-withdrawal-button' ),
					array( 'status' => 403 )
				);
			}
			return true;
		};
	}
}
