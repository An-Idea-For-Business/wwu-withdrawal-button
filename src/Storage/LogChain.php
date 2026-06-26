<?php
/**
 * Hash-chain helpers for the immutable log.
 *
 * Each row's row_hash commits to the previous row's row_hash plus a canonical
 * serialization of the row's own evidence fields. Verification replays the chain
 * and reports the first broken link, making any insertion, deletion or edit of a
 * historical row detectable.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hash-chain computation + verification.
 */
final class LogChain {

	/**
	 * Current chain format version.
	 *
	 * v2 (1.1.0+) keys each row hash with the per-site secret (HMAC-SHA256), so a
	 * DB-write attacker who does NOT also hold `webwakeupwdb_secret` cannot recompute a
	 * forged chain. v1 (pre-1.1.0) used unkeyed SHA-256. Every row stores its own
	 * `chain_version`, so a mixed chain (legacy v1 rows followed by new v2 rows)
	 * still verifies — each row is checked with the formula it was written under.
	 *
	 * @var int
	 */
	public const VERSION = 2;

	/**
	 * Compute the row hash from the previous hash and the row's evidence fields.
	 *
	 * The field order is fixed and canonical; changing it is a breaking change to
	 * the chain format and must be versioned (see self::VERSION).
	 *
	 * @param string $prev_hash Previous row's row_hash (genesis for the first row).
	 * @param array  $evidence  Evidence fields (request_uid, event, payload, ip, created_at, ...).
	 * @param int    $version   Chain format version (default = current). v1 = unkeyed SHA-256;
	 *                          v2+ = HMAC-SHA256 keyed with the site secret.
	 * @return string 64-char lowercase hex digest.
	 */
	public static function compute( string $prev_hash, array $evidence, int $version = self::VERSION ): string {
		$canonical = wp_json_encode( self::canonicalize( $evidence ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$message   = $prev_hash . '|' . (string) $canonical;

		if ( $version >= 2 ) {
			// Keyed: an attacker without the secret cannot reproduce row hashes, so
			// the chain can no longer be silently rewritten by a DB-write actor.
			// (An attacker who ALSO reads `webwakeupwdb_secret` is out of scope for the
			// chain alone — the external timestamp proof is the cross-check.)
			return hash_hmac( 'sha256', $message, \WebWakeUpWdb\WithdrawalButton\Security\Secret::get() );
		}

		// v1 legacy: unkeyed SHA-256. Retained so pre-1.1.0 rows keep verifying.
		return hash( 'sha256', $message );
	}

	/**
	 * The genesis hash for a site (used as prev_hash of the first row).
	 *
	 * @return string
	 */
	public static function genesis(): string {
		$secret = \WebWakeUpWdb\WithdrawalButton\Security\Secret::get();
		return hash( 'sha256', 'webwakeupwdb_genesis|' . $secret );
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
