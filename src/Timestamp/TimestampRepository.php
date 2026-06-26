<?php
/**
 * Repository for the trusted-timestamp proofs table.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Timestamp;

use WebWakeUpWdb\WithdrawalButton\Storage\Database\TimestampTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Timestamp proofs repository.
 */
final class TimestampRepository {

	/**
	 * Insert a pending stamp.
	 *
	 * @param int    $log_id     Linked log row id.
	 * @param string $sha256_hex The digest (log row_hash).
	 * @param string $nonce_hex  Privacy nonce.
	 * @param string $provider   Provider key.
	 * @param string $proof_blob Partial proof bytes.
	 * @return int Inserted id, or 0.
	 */
	public function insert( int $log_id, string $sha256_hex, string $nonce_hex, string $provider, string $proof_blob ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			TimestampTable::name(),
			array(
				'log_id'       => $log_id,
				'sha256_hex'   => $sha256_hex,
				'nonce_hex'    => $nonce_hex,
				'provider'     => $provider,
				'proof_blob'   => $proof_blob,
				'status'       => 'pending',
				'submitted_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Pending stamps (oldest first), capped.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function pending( int $limit = 20 ): array {
		global $wpdb;
		$table = TimestampTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d", 'pending', max( 1, $limit ) ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark a stamp confirmed.
	 *
	 * @param int      $id         Stamp id.
	 * @param string   $proof_blob Full proof bytes.
	 * @param int|null $block      Bitcoin block height (nullable).
	 * @return void
	 */
	public function mark_confirmed( int $id, string $proof_blob, ?int $block ): void {
		global $wpdb;
		$wpdb->update(
			TimestampTable::name(),
			array(
				'proof_blob'    => $proof_blob,
				'bitcoin_block' => $block,
				'status'        => 'confirmed',
				'confirmed_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Whether any confirmed proof exists for a log row.
	 *
	 * @param int $log_id Log row id.
	 * @return bool
	 */
	public function has_confirmed_for_log( int $log_id ): bool {
		global $wpdb;
		$table = TimestampTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE log_id = %d AND status = %s LIMIT 1", $log_id, 'confirmed' ) );
	}
}
