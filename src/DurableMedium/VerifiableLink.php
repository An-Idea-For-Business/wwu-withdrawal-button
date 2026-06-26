<?php
/**
 * Permanent, token-gated link to a durable-medium receipt + its verification page.
 *
 * Token = truncated HMAC(uid, site secret). The link is stable (no expiry) so the
 * consumer can re-download the receipt at any time, as a durable medium must
 * allow; it is unguessable and rate-limited at the endpoint.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\DurableMedium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifiable-link tokens + URLs.
 */
final class VerifiableLink {

	/**
	 * Mint the token for a request UID.
	 *
	 * @param string $request_uid Request UUID.
	 * @return string
	 */
	public static function token( string $request_uid ): string {
		$secret = \WebWakeUpWdb\WithdrawalButton\Security\Secret::get();
		return substr( hash_hmac( 'sha256', 'receipt|' . $request_uid, $secret ), 0, 40 );
	}

	/**
	 * Verify a token for a request UID (constant-time).
	 *
	 * @param string $request_uid Request UUID.
	 * @param string $token       Token.
	 * @return bool
	 */
	public static function verify( string $request_uid, string $token ): bool {
		return '' !== $token && hash_equals( self::token( $request_uid ), $token );
	}

	/**
	 * The download URL for a receipt.
	 *
	 * @param string $request_uid Request UUID.
	 * @return string
	 */
	public static function download_url( string $request_uid ): string {
		return rest_url( WEBWAKEUPWDB_REST_NAMESPACE . '/receipt/' . rawurlencode( $request_uid ) ) . '?t=' . self::token( $request_uid );
	}

	/**
	 * The verification URL (shows hash + timestamp status).
	 *
	 * @param string $request_uid Request UUID.
	 * @return string
	 */
	public static function verify_url( string $request_uid ): string {
		return rest_url( WEBWAKEUPWDB_REST_NAMESPACE . '/verify/' . rawurlencode( $request_uid ) ) . '?t=' . self::token( $request_uid );
	}
}
