<?php
/**
 * Hash-chain helpers for the immutable log.
 *
 * Each row's row_hash commits to the previous row's row_hash plus a canonical
 * serialization of the row's own evidence fields. Verification replays the chain
 * and reports the first broken link, making any insertion, deletion or edit of a
 * historical row detectable.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hash-chain computation + verification.
 */
final class LogChain {

	/**
	 * Compute the row hash from the previous hash and the row's evidence fields.
	 *
	 * The field order is fixed and canonical; changing it is a breaking change to
	 * the chain format and must be versioned.
	 *
	 * @param string $prev_hash    Previous row's row_hash (genesis = sha256(secret)).
	 * @param array  $evidence     Evidence fields (request_uid, event, payload, ip, ua, created_at, ...).
	 * @return string 64-char lowercase hex SHA-256.
	 */
	public static function compute( string $prev_hash, array $evidence ): string {
		$canonical = wp_json_encode( self::canonicalize( $evidence ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', $prev_hash . '|' . (string) $canonical );
	}

	/**
	 * The genesis hash for a site (used as prev_hash of the first row).
	 *
	 * @return string
	 */
	public static function genesis(): string {
		$secret = \WWU\WithdrawalButton\Security\Secret::get();
		return hash( 'sha256', 'wwu_wb_genesis|' . $secret );
	}

	/**
	 * Recursively sort keys so the canonical form is order-independent.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			$is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
			if ( $is_assoc ) {
				ksort( $value );
			}
			return array_map( array( self::class, 'canonicalize' ), $value );
		}
		return $value;
	}
}
