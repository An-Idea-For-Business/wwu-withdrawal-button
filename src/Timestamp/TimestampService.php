<?php
/**
 * Timestamp orchestration.
 *
 * On a confirmed withdrawal, submits the immutable-log row_hash to the configured
 * provider (OpenTimestamps by default), stores the pending proof, links it to the
 * log row, and schedules the upgrade cron. The cron later anchors the proof.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Timestamp;

use WebWakeUpWdb\WithdrawalButton\Storage\Database\LogTable;
use WebWakeUpWdb\WithdrawalButton\Storage\LogRepository;
use WebWakeUpWdb\WithdrawalButton\Debug\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Timestamp service.
 */
final class TimestampService {

	/**
	 * Cron hook for upgrading pending proofs.
	 *
	 * @var string
	 */
	public const CRON_UPGRADE = 'webwakeupwdb_timestamp_upgrade';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'webwakeupwdb_log_written', array( $this, 'maybe_stamp' ), 10, 3 );
		add_action( self::CRON_UPGRADE, array( $this, 'upgrade_pending' ) );

		if ( ! wp_next_scheduled( self::CRON_UPGRADE ) && 'none' !== $this->provider()->key() ) {
			wp_schedule_event( time() + ( 30 * MINUTE_IN_SECONDS ), 'hourly', self::CRON_UPGRADE );
		}
	}

	/**
	 * Resolve the configured provider.
	 *
	 * @return TimestampProvider
	 */
	public function provider(): TimestampProvider {
		$config = (array) get_option( 'webwakeupwdb_timestamp', array() );
		// Default 'none' (no external call) when unset — opt-in only (wp.org Guideline 7).
		$key    = (string) ( $config['provider'] ?? 'none' );

		if ( 'none' === $key ) {
			$provider = new NoneProvider();
		} elseif ( 'rfc3161' === $key ) {
			$provider = new Rfc3161Provider( (array) ( $config['rfc3161'] ?? array() ) );
		} else {
			$provider = new OpenTimestampsProvider();
		}

		/**
		 * Filter the timestamp provider (e.g. inject an RFC 3161 / eIDAS provider).
		 *
		 * @param TimestampProvider $provider Provider.
		 * @param string            $key      Configured provider key.
		 */
		return apply_filters( 'webwakeupwdb_timestamp_provider', $provider, $key );
	}

	/**
	 * Stamp the row_hash of a newly-written 'confirmed' log row.
	 *
	 * @param int    $log_id Log row id.
	 * @param string $event  Event slug.
	 * @param array  $row    Submitted row (without row_hash).
	 * @return void
	 */
	public function maybe_stamp( int $log_id, string $event, array $row ): void {
		if ( 'confirmed' !== $event || $log_id <= 0 ) {
			return;
		}

		$provider = $this->provider();
		if ( 'none' === $provider->key() ) {
			return;
		}

		$row_hash = $this->row_hash_for( $log_id );
		if ( '' === $row_hash ) {
			return;
		}

		// If the calendars/TSA are unreachable now, the upgrade cron retries the
		// initial stamp later (retry_unstamped), so a confirmed row is never left
		// un-anchored forever because of a transient network failure.
		$this->stamp_and_link( $provider, $log_id, $row_hash );
	}

	/**
	 * Stamp a log row's hash with the provider and link the proof back to the row.
	 *
	 * Shared by the initial stamp (maybe_stamp) and the cron retry (retry_unstamped).
	 *
	 * @param TimestampProvider $provider Provider.
	 * @param int               $log_id   Log row id.
	 * @param string            $row_hash The row hash to stamp.
	 * @return bool True when a proof was obtained and linked.
	 */
	private function stamp_and_link( TimestampProvider $provider, int $log_id, string $row_hash ): bool {
		$stamp = $provider->stamp( $row_hash );
		if ( null === $stamp ) {
			return false; // unreachable now; the upgrade cron will retry.
		}

		$repo = new TimestampRepository();
		$id   = $repo->insert( $log_id, $row_hash, $stamp['nonce_hex'], $provider->key(), $stamp['proof_blob'] );
		if ( $id <= 0 ) {
			return false;
		}

		// Link the proof back to the log row.
		global $wpdb;
		$wpdb->update( LogTable::name(), array( 'ots_proof_id' => $id ), array( 'id' => $log_id ), array( '%d' ), array( '%d' ) );

		// Synchronous providers (RFC 3161) return a final proof immediately — confirm
		// it now so the upgrade cron skips it. Asynchronous providers (OpenTimestamps)
		// stay pending until a Bitcoin block confirms.
		if ( empty( $stamp['pending'] ) ) {
			$repo->mark_confirmed( $id, (string) $stamp['proof_blob'], null );
		}

		Debug::info( 'timestamp', 'stamped', array( 'log_id' => $log_id, 'provider' => $provider->key(), 'pending' => ! empty( $stamp['pending'] ) ) );

		/**
		 * Fires when a log row's hash has been submitted for timestamping.
		 *
		 * @param int    $log_id   Log row id.
		 * @param string $row_hash The stamped hash.
		 * @param string $provider Provider key.
		 */
		do_action( 'webwakeupwdb_timestamp_anchored', $log_id, $row_hash, $provider->key() );
		return true;
	}

	/**
	 * Cron: upgrade pending proofs to anchored proofs.
	 *
	 * @return void
	 */
	public function upgrade_pending(): void {
		$provider = $this->provider();
		if ( 'none' === $provider->key() ) {
			return;
		}
		$repo = new TimestampRepository();
		foreach ( $repo->pending( 20 ) as $stamp ) {
			$result = $provider->upgrade( $stamp );
			if ( null !== $result ) {
				$repo->mark_confirmed( (int) $stamp['id'], (string) $result['proof_blob'], $result['bitcoin_block'] );
				Debug::info( 'timestamp', 'confirmed', array( 'id' => (int) $stamp['id'] ) );
			}
		}

		// Retry confirmed rows whose INITIAL stamp failed (e.g. the calendars/TSA were
		// unreachable at confirm time), so a transient outage never leaves evidence
		// permanently un-anchored.
		$this->retry_unstamped( $provider, 10 );
	}

	/**
	 * Re-attempt the initial stamp for confirmed log rows that still have no proof.
	 *
	 * @param TimestampProvider $provider Provider.
	 * @param int               $limit    Max rows to retry per run.
	 * @return void
	 */
	private function retry_unstamped( TimestampProvider $provider, int $limit ): void {
		global $wpdb;
		$table = LogTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is constant-derived; values bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, row_hash FROM {$table} WHERE event = %s AND ots_proof_id IS NULL ORDER BY id ASC LIMIT %d",
				'confirmed',
				max( 1, $limit )
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $r ) {
			$row_hash = (string) ( $r['row_hash'] ?? '' );
			if ( '' !== $row_hash && $this->stamp_and_link( $provider, (int) $r['id'], $row_hash ) ) {
				Debug::info( 'timestamp', 'retry.stamped', array( 'log_id' => (int) $r['id'] ) );
			}
		}
	}

	/**
	 * Count confirmed log rows that still have no timestamp proof (un-anchored).
	 *
	 * Surfaced in admin so the merchant can see whether any evidence lacks an
	 * external anchor. Returns 0 when the provider is 'none' (anchoring off by intent).
	 *
	 * @return int
	 */
	public function count_unanchored(): int {
		if ( 'none' === $this->provider()->key() ) {
			return 0;
		}
		global $wpdb;
		$table = LogTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is constant-derived; values bound.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event = %s AND ots_proof_id IS NULL", 'confirmed' )
		);
	}

	/**
	 * Read the stored row_hash for a log id.
	 *
	 * @param int $log_id Log id.
	 * @return string
	 */
	private function row_hash_for( int $log_id ): string {
		global $wpdb;
		$table = LogTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT row_hash FROM {$table} WHERE id = %d", $log_id ) );
	}

	/**
	 * Clear the cron (called on deactivation).
	 *
	 * @return void
	 */
	public static function clear_cron(): void {
		wp_clear_scheduled_hook( self::CRON_UPGRADE );
	}
}
