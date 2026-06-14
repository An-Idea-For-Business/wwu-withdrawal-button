<?php
/**
 * Client request information (IP, user agent) for the evidence log.
 *
 * The raw IP is intentionally captured: Art. 54-bis requires the log to record
 * "data, ora, IP e dati contratto" as evidence (GDPR Art. 6(1)(c)/(f) basis).
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client info accessors.
 */
final class ClientInfo {

	/**
	 * Best-effort client IP address (validated). Respects a proxy-header filter.
	 *
	 * @return string
	 */
	public static function ip(): string {
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		/**
		 * Filter the raw client IP (e.g. to read a trusted proxy header).
		 *
		 * @param string $raw The REMOTE_ADDR value.
		 */
		$raw = (string) apply_filters( 'wwu_wb_client_ip', $raw );
		$ip  = filter_var( $raw, FILTER_VALIDATE_IP );
		return $ip ? $ip : '';
	}

	/**
	 * Truncated, sanitised user agent.
	 *
	 * @return string
	 */
	public static function user_agent(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		$ua = sanitize_text_field( (string) $ua );
		return substr( $ua, 0, 255 );
	}
}
