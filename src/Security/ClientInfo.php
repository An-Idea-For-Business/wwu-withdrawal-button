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
		 * SECURITY: the result is stored as legal evidence. A callback that reads a
		 * client-supplied header (X-Forwarded-For, X-Real-IP, …) MUST trust it only
		 * when REMOTE_ADDR is your known reverse proxy — otherwise a visitor can put
		 * an arbitrary value into the evidence log. The result is re-validated with
		 * FILTER_VALIDATE_IP below, so it is always a syntactically valid IP, but a
		 * valid IP is not necessarily an authentic one.
		 *
		 * @param string $raw The REMOTE_ADDR value.
		 */
		$raw = (string) apply_filters( 'wwu_wb_client_ip', $raw );
		$ip  = filter_var( $raw, FILTER_VALIDATE_IP );
		return $ip ? $ip : '';
	}

	/**
	 * GDPR-anonymise an IP address (zero the last IPv4 octet / last 80 IPv6 bits).
	 *
	 * The anonymised value is what gets committed to the immutable-log hash, so the
	 * permanent record is data-minimised; the full IP is retained separately (the
	 * `ip_full` column) only for the legal retention window, then purged.
	 *
	 * @param string $ip Raw IP (may be empty).
	 * @return string Anonymised IP, or '' when the input is empty.
	 */
	public static function anonymize_ip( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}
		return (string) wp_privacy_anonymize_ip( $ip );
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
