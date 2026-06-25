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

	/**
	 * Sanitise a comma-separated list of e-mail addresses into a normalised
	 * comma-separated string. Each entry is run through sanitize_email(); empty or
	 * invalid entries are dropped, duplicates are removed, and the list is capped to a
	 * sane number of recipients. Returns '' when none are valid. Useful for
	 * notification fields that may target more than one mailbox.
	 *
	 * @param mixed $value Raw value (comma-separated string).
	 * @return string
	 */
	public static function email_list( $value ): string {
		$raw = is_string( $value ) ? $value : '';
		$out = array();
		foreach ( explode( ',', $raw ) as $candidate ) {
			$email = sanitize_email( trim( $candidate ) );
			if ( '' !== $email && ! in_array( $email, $out, true ) ) {
				$out[] = $email;
			}
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * The first address of a comma-separated e-mail list (already sanitised), or '' if
	 * the list is empty. Used where a single contact address is required (e.g. the
	 * trader contact shown to the consumer on the receipt) while the full list may
	 * carry several internal notification recipients.
	 *
	 * @param string $list Comma-separated e-mail list.
	 * @return string
	 */
	public static function first_email( string $list ): string {
		$parts = explode( ',', $list );
		return '' !== trim( $list ) ? trim( $parts[0] ) : '';
	}
}
