<?php
/**
 * Shared input-sanitisation helpers.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitiser utilities.
 */
final class Sanitizer {

	/**
	 * Sanitise a checkbox-style boolean from $_POST.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function bool( $value ): bool {
		return ! empty( $value ) && '0' !== $value && 'false' !== $value;
	}

	/**
	 * Sanitise an array of integer IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	public static function int_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	/**
	 * Sanitise a value against a whitelist, falling back to a default.
	 *
	 * @param mixed    $value   Raw value.
	 * @param string[] $allowed Allowed values.
	 * @param string   $default Fallback.
	 * @return string
	 */
	public static function enum( $value, array $allowed, string $default ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Sanitise an admin-authored custom CSS blob.
	 *
	 * The CSS is authored by a user with the admin capability, so the trust level
	 * is high; this is defence-in-depth: it strips any HTML tags / `</style>`
	 * break-out, and neutralises the classic CSS-based script vectors
	 * (expression(), javascript:, behavior:, @import, and url(javascript:)).
	 *
	 * @param mixed $value Raw CSS.
	 * @return string
	 */
	public static function css( $value ): string {
		$css = is_string( $value ) ? $value : '';
		// Remove any tags / style-tag break-out.
		$css = wp_strip_all_tags( $css );
		$css = str_ireplace( array( '</style', '<style', '<', '>' ), '', $css );
		// Neutralise CSS script vectors.
		$css = preg_replace( '/expression\s*\(/i', 'blocked(', $css );
		$css = preg_replace( '/javascript\s*:/i', 'blocked:', $css );
		$css = preg_replace( '/behaviou?r\s*:/i', 'blocked:', $css );
		$css = preg_replace( '/@import\b/i', '/* @import blocked */', $css );
		// Cap length to a sane size.
		return trim( (string) substr( (string) $css, 0, 50000 ) );
	}

	/**
	 * Sanitise an array of role slugs.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public static function role_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $value ) ) ) );
	}

	/**
	 * Sanitise a two-letter country code list (uppercase ISO-3166 alpha-2).
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public static function country_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $code ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $code ) );
			if ( 2 === strlen( $code ) ) {
				$out[] = $code;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
