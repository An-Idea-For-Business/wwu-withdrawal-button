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
