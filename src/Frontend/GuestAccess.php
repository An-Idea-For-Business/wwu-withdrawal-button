<?php
/**
 * Guest access tokens + rate limiting for the public withdrawal flow.
 *
 * A guest proves order ownership either via the WooCommerce order key (carried
 * in the signed email link) or by passing the public lookup (order number +
 * email), which mints a short-lived HMAC access token bound to (order_ref,
 * email). All failures return identical generic errors (no enumeration), and
 * lookup attempts are rate-limited per IP.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guest access helper.
 */
final class GuestAccess {

	/**
	 * Token validity window in seconds (token bucket length).
	 *
	 * @var int
	 */
	private const BUCKET = 3600;

	/**
	 * Mint an access token bound to an order + email for the current time bucket.
	 *
	 * @param string $order_ref Order reference.
	 * @param string $email     Email.
	 * @return string
	 */
	public static function mint( string $order_ref, string $email ): string {
		return self::token( $order_ref, $email, self::bucket() );
	}

	/**
	 * Verify a token against the current and previous time bucket.
	 *
	 * @param string $order_ref Order reference.
	 * @param string $email     Email.
	 * @param string $token     Token to verify.
	 * @return bool
	 */
	public static function verify( string $order_ref, string $email, string $token ): bool {
		if ( '' === $token ) {
			return false;
		}
		$now = self::bucket();
		foreach ( array( $now, $now - 1 ) as $bucket ) {
			if ( hash_equals( self::token( $order_ref, $email, $bucket ), $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enforce a per-IP rate limit on lookup attempts.
	 *
	 * @return bool True if within limit, false if exceeded.
	 */
	public static function check_rate_limit(): bool {
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$key     = 'wwu_wb_rl_' . md5( $ip );
		$max     = (int) apply_filters( 'wwu_wb_rate_limit_max_attempts', 10 );
		$window  = (int) apply_filters( 'wwu_wb_rate_limit_window_seconds', 300 );
		$count   = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Compute the HMAC token for a bucket.
	 *
	 * @param string $order_ref Order reference.
	 * @param string $email     Email.
	 * @param int    $bucket    Time bucket.
	 * @return string
	 */
	private static function token( string $order_ref, string $email, int $bucket ): string {
		$secret = \WWU\WithdrawalButton\Security\Secret::get();
		$data   = implode( '|', array( 'guest', $order_ref, strtolower( $email ), (string) $bucket ) );
		return substr( hash_hmac( 'sha256', $data, $secret ), 0, 40 );
	}

	/**
	 * Current time bucket.
	 *
	 * @return int
	 */
	private static function bucket(): int {
		return (int) floor( time() / self::BUCKET );
	}
}
