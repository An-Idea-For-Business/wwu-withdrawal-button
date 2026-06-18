<?php
/**
 * Append-only repository for the immutable withdrawal log.
 *
 * The ONLY write path is append(): there is no update or delete. Inserts are
 * serialised with a named MySQL lock so the global hash chain is computed
 * against the true latest row even under concurrency (withdrawal write volume is
 * low, so the lock is cheap).
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Storage;

use WWU\WithdrawalButton\Storage\Database\LogTable;
use WWU\WithdrawalButton\Debug\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only log repository.
 */
final class LogRepository {

	/**
	 * Named lock used to serialise chain appends.
	 *
	 * @var string
	 */
	private const LOCK = 'wwu_wb_log_append';

	/**
	 * Append a row to the immutable log.
	 *
	 * @param array $row {
	 *     @type string $request_uid    UUID of the withdrawal request.
	 *     @type string $platform       Platform key.
	 *     @type string $order_ref      Order reference.
	 *     @type string $customer_email Customer email.
	 *     @type string $event          Event slug.
	 *     @type array  $payload        Full evidence payload (statement, ua, locale, …).
	 *     @type string $ip_address     Raw IP (legal evidence).
	 *     @type int    $ots_proof_id   Optional timestamp proof id.
	 * }
	 * @return int Inserted row id, or 0 on failure.
	 */
	public function append( array $row ): int {
		global $wpdb;
		$table = LogTable::name();

		$created_at = gmdate( 'Y-m-d H:i:s' );
		$payload    = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();

		// Acquire the append lock so prev_hash reflects the real latest row.
		// If the lock cannot be acquired we MUST abort: appending without it could
		// compute prev_hash against a stale latest row and corrupt the chain.
		$got_lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK, 5 ) );
		if ( 1 !== $got_lock ) {
			Debug::error( 'log', 'append_lock_failed', array( 'event' => (string) ( $row['event'] ?? '' ) ) );
			return 0;
		}

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$prev_hash = (string) $wpdb->get_var( "SELECT row_hash FROM {$table} ORDER BY id DESC LIMIT 1" );
			if ( '' === $prev_hash ) {
				$prev_hash = LogChain::genesis();
			}

			// IP handling (chain v2 / GDPR storage limitation): the hash commits to
			// the ANONYMISED IP (data-minimised, retained permanently), while the
			// FULL IP is stored separately in ip_full — kept for the legal window,
			// then blanked by the retention purge. ip_full is NOT part of the hashed
			// evidence, so erasing it later never breaks the chain.
			$raw_ip  = (string) ( $row['ip_address'] ?? '' );
			$anon_ip = \WWU\WithdrawalButton\Security\ClientInfo::anonymize_ip( $raw_ip );

			$evidence = array(
				'request_uid' => (string) ( $row['request_uid'] ?? '' ),
				'platform'    => (string) ( $row['platform'] ?? '' ),
				'order_ref'   => (string) ( $row['order_ref'] ?? '' ),
				'event'       => (string) ( $row['event'] ?? '' ),
				'payload'     => $payload,
				'ip_address'  => $anon_ip,
				'created_at'  => $created_at,
			);
			$row_hash = LogChain::compute( $prev_hash, $evidence, LogChain::VERSION );

			$ok = $wpdb->insert(
				$table,
				array(
					'request_uid'    => $evidence['request_uid'],
					'platform'       => $evidence['platform'],
					'order_ref'      => $evidence['order_ref'],
					'customer_email' => (string) ( $row['customer_email'] ?? '' ),
					'event'          => $evidence['event'],
					'payload_json'   => (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					'ip_address'     => $anon_ip,
					'ip_full'        => $raw_ip,
					'prev_hash'      => $prev_hash,
					'row_hash'       => $row_hash,
					'chain_version'  => LogChain::VERSION,
					'ots_proof_id'   => isset( $row['ots_proof_id'] ) ? (int) $row['ots_proof_id'] : null,
					'created_at'     => $created_at,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
			);

			$id = $ok ? (int) $wpdb->insert_id : 0;
		} finally {
			if ( 1 === $got_lock ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
			}
		}

		if ( $id > 0 ) {
			Debug::log( 'log', 'appended', array( 'id' => $id, 'event' => $evidence['event'], 'order_ref' => $evidence['order_ref'] ) );
			/**
			 * Fires after a row is written to the immutable log.
			 *
			 * @param int    $id    Log row id.
			 * @param string $event Event slug.
			 * @param array  $row   The submitted row.
			 */
			do_action( 'wwu_wb_log_written', $id, $evidence['event'], $row );
		} else {
			Debug::error( 'log', 'append_failed', array( 'db_error' => $wpdb->last_error ) );
		}

		return $id;
	}

	/**
	 * Fetch the log rows for an order, oldest first.
	 *
	 * @param string $platform  Platform key.
	 * @param string $order_ref Order reference.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_order( string $platform, string $order_ref ): array {
		global $wpdb;
		$table = LogTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE platform = %s AND order_ref = %s ORDER BY id ASC", $platform, $order_ref ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single row by request UID + event.
	 *
	 * @param string $request_uid Request UID.
	 * @param string $event       Event slug.
	 * @return array<string,mixed>|null
	 */
	public function find( string $request_uid, string $event ): ?array {
		global $wpdb;
		$table = LogTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE request_uid = %s AND event = %s LIMIT 1", $request_uid, $event ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * List recent confirmed withdrawal requests (for the admin dashboard).
	 *
	 * @param int $limit  Max rows.
	 * @param int $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_confirmed( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = LogTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event = %s ORDER BY id DESC LIMIT %d OFFSET %d",
				'confirmed',
				max( 1, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count confirmed withdrawal requests.
	 *
	 * @return int
	 */
	public function count_confirmed(): int {
		global $wpdb;
		$table = LogTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event = %s", 'confirmed' ) );
	}

	/**
	 * Verify a single stored row's own hash (cheap O(1) integrity check).
	 *
	 * Recomputes row_hash from the row's evidence fields + its stored prev_hash.
	 * Used by the public verify endpoint instead of scanning the whole chain.
	 *
	 * @param array $row A stored log row (associative).
	 * @return bool
	 */
	public function verify_row( array $row ): bool {
		$evidence = array(
			'request_uid' => (string) ( $row['request_uid'] ?? '' ),
			'platform'    => (string) ( $row['platform'] ?? '' ),
			'order_ref'   => (string) ( $row['order_ref'] ?? '' ),
			'event'       => (string) ( $row['event'] ?? '' ),
			'payload'     => json_decode( (string) ( $row['payload_json'] ?? '' ), true ) ?: array(),
			'ip_address'  => (string) ( $row['ip_address'] ?? '' ),
			'created_at'  => (string) ( $row['created_at'] ?? '' ),
		);
		// Verify with the formula the row was written under (1 = legacy SHA-256,
		// 2+ = HMAC). Rows are fetched with SELECT *, so chain_version is present; a
		// row that predates the column defaults to 1.
		$version  = isset( $row['chain_version'] ) ? (int) $row['chain_version'] : 1;
		$computed = LogChain::compute( (string) ( $row['prev_hash'] ?? '' ), $evidence, $version );
		return hash_equals( $computed, (string) ( $row['row_hash'] ?? '' ) );
	}

	/**
	 * Cached whole-chain integrity status for the admin dashboard.
	 *
	 * Caches the verify_chain() result in a transient so the (potentially O(n))
	 * scan runs at most once per cache window, never on a public request.
	 *
	 * @param int $ttl Cache lifetime in seconds.
	 * @return int First broken row id, or 0 if intact.
	 */
	public function chain_status_cached( int $ttl = 900 ): int {
		$cached = get_transient( 'wwu_wb_chain_status' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$broken = $this->verify_chain();
		set_transient( 'wwu_wb_chain_status', $broken, $ttl );
		return $broken;
	}

	/**
	 * Verify the global hash chain. Returns the id of the first broken row, or 0
	 * if the chain is intact.
	 *
	 * @param int $limit Max rows to scan (0 = all).
	 * @return int
	 */
	public function verify_chain( int $limit = 0 ): int {
		global $wpdb;
		$table = LogTable::name();
		$sql   = "SELECT id, request_uid, platform, order_ref, event, payload_json, ip_address, prev_hash, row_hash, chain_version, created_at FROM {$table} ORDER BY id ASC";
		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . (int) $limit;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$expected_prev = LogChain::genesis();
		foreach ( $rows as $row ) {
			if ( ! hash_equals( $expected_prev, (string) $row['prev_hash'] ) ) {
				return (int) $row['id'];
			}
			$evidence = array(
				'request_uid' => (string) $row['request_uid'],
				'platform'    => (string) $row['platform'],
				'order_ref'   => (string) $row['order_ref'],
				'event'       => (string) $row['event'],
				'payload'     => json_decode( (string) $row['payload_json'], true ) ?: array(),
				'ip_address'  => (string) $row['ip_address'],
				'created_at'  => (string) $row['created_at'],
			);
			$computed = LogChain::compute( (string) $row['prev_hash'], $evidence, isset( $row['chain_version'] ) ? (int) $row['chain_version'] : 1 );
			if ( ! hash_equals( $computed, (string) $row['row_hash'] ) ) {
				return (int) $row['id'];
			}
			$expected_prev = (string) $row['row_hash'];
		}
		return 0;
	}
}
