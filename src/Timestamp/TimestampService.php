<?php
/**
 * Timestamp orchestration.
 *
 * On a confirmed withdrawal, submits the immutable-log row_hash to the configured
 * provider (OpenTimestamps by default), stores the pending proof, links it to the
 * log row, and schedules the upgrade cron. The cron later anchors the proof.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Timestamp;

use WWU\WithdrawalButton\Storage\Database\LogTable;
use WWU\WithdrawalButton\Storage\LogRepository;
use WWU\WithdrawalButton\Debug\Debug;

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
	public const CRON_UPGRADE = 'wwu_wb_timestamp_upgrade';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wwu_wb_log_written', array( $this, 'maybe_stamp' ), 10, 3 );
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
		$config = (array) get_option( 'wwu_wb_timestamp', array() );
		$key    = (string) ( $config['provider'] ?? 'opentimestamps' );

		$provider = ( 'none' === $key ) ? new NoneProvider() : new OpenTimestampsProvider();

		/**
		 * Filter the timestamp provider (e.g. inject an RFC 3161 / eIDAS provider).
		 *
		 * @param TimestampProvider $provider Provider.
		 * @param string            $key      Configured provider key.
		 */
		return apply_filters( 'wwu_wb_timestamp_provider', $provider, $key );
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

		$stamp = $provider->stamp( $row_hash );
		if ( null === $stamp ) {
			return; // calendars unreachable; the chain alone still stands. Cron does not retry stamping.
		}

		$repo = new TimestampRepository();
		$id   = $repo->insert( $log_id, $row_hash, $stamp['nonce_hex'], $provider->key(), $stamp['proof_blob'] );

		if ( $id > 0 ) {
			// Link the proof back to the log row.
			global $wpdb;
			$wpdb->update( LogTable::name(), array( 'ots_proof_id' => $id ), array( 'id' => $log_id ), array( '%d' ), array( '%d' ) );
			Debug::info( 'timestamp', 'stamped', array( 'log_id' => $log_id, 'provider' => $provider->key() ) );

			/**
			 * Fires when a log row's hash has been submitted for timestamping.
			 *
			 * @param int    $log_id   Log row id.
			 * @param string $row_hash The stamped hash.
			 * @param string $provider Provider key.
			 */
			do_action( 'wwu_wb_timestamp_anchored', $log_id, $row_hash, $provider->key() );
		}
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
