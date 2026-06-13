<?php
/**
 * Trusted-timestamp provider interface.
 *
 * Pluggable so the free OpenTimestamps default can be swapped for an RFC 3161 /
 * eIDAS qualified provider later without touching the call sites.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Timestamp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Timestamp provider.
 */
interface TimestampProvider {

	/**
	 * Provider key.
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Submit a 64-char hex digest for timestamping.
	 *
	 * @param string $sha256_hex 64-char lowercase hex digest (the log row_hash).
	 * @return array{nonce_hex:string,proof_blob:string,pending:bool}|null Stamp data, or null on total failure.
	 */
	public function stamp( string $sha256_hex ): ?array;

	/**
	 * Attempt to upgrade a pending stamp to a confirmed (anchored) proof.
	 *
	 * @param array $stamp Stored stamp row (sha256_hex, nonce_hex, proof_blob, …).
	 * @return array{proof_blob:string,bitcoin_block:?int}|null Confirmed data, or null if still pending.
	 */
	public function upgrade( array $stamp ): ?array;
}
