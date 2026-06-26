<?php
/**
 * Lean, PII-scoped reader over the immutable log for the public read API + webhook.
 *
 * This is the single place that decides WHAT a withdrawal request looks like when
 * it leaves the plugin boundary (REST response or outbound webhook). The crux is
 * privacy: the evidence log stores the raw consumer IP and the hash-chain
 * internals, and NONE of that is ever exposed here. The list omits the email
 * entirely; the detail + webhook expose the email (already in the merchant's
 * order) but never the IP, the prev_hash, or the user agent. The row_hash is
 * surfaced so an external system can verify integrity without seeing the IP that
 * went into it.
 *
 * Status is derived from the append-only log alone (no per-order adapter load, so
 * it works the same across WooCommerce / FluentCart / EDD): a request is
 * `refunded` if a refund_issued event exists, else `processed` if a
 * request_processed event exists, else `open`. This mirrors the precedence the
 * admin Requests dashboard shows (refunded > processed > open).
 *
 * @see \WebWakeUpWdb\WithdrawalButton\Storage\LogRepository
 * @see \WebWakeUpWdb\WithdrawalButton\Admin\RequestsDashboard
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Api;

use WebWakeUpWdb\WithdrawalButton\Storage\Database\LogTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read model for the automations API.
 */
final class RequestReader {

	/**
	 * Hard cap on page size (mirrors the SPEC; protects the read endpoints).
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Valid status filter values (also the derived status vocabulary).
	 *
	 * @var string[]
	 */
	public const STATUSES = array( 'open', 'processed', 'refunded' );

	/**
	 * Paginated list of confirmed withdrawal requests (lean rows, no email/IP).
	 *
	 * @param array $filters {
	 *     @type string $platform Platform key (exact match).
	 *     @type string $status   One of open|processed|refunded.
	 *     @type string $after    ISO date (Y-m-d) lower bound on created_at (UTC).
	 *     @type string $before   ISO date (Y-m-d) upper bound on created_at (UTC).
	 * }
	 * @param int $page     1-based page.
	 * @param int $per_page Page size (clamped to 1..MAX_PER_PAGE).
	 * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int,page:int,per_page:int}
	 */
	public function list( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$table = LogTable::name();

		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		list( $where_sql, $args ) = $this->build_where( $filters );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} t WHERE {$where_sql}", ...$args ) );

		$page_args = array_merge( $args, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
		$db_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT t.id, t.request_uid, t.platform, t.order_ref, t.payload_json, t.created_at FROM {$table} t WHERE {$where_sql} ORDER BY t.id DESC LIMIT %d OFFSET %d", ...$page_args ),
			ARRAY_A
		);
		$db_rows = is_array( $db_rows ) ? $db_rows : array();

		$uids     = array_values( array_filter( array_map( static fn( $r ) => (string) ( $r['request_uid'] ?? '' ), $db_rows ) ) );
		$statuses = $this->statuses_for_uids( $uids );

		$rows = array();
		foreach ( $db_rows as $r ) {
			$uid    = (string) ( $r['request_uid'] ?? '' );
			$rows[] = $this->lean_row( $r, $statuses[ $uid ] ?? 'open' );
		}

		return array(
			'rows'     => $rows,
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per_page ),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * One confirmed request, with the email + product selection (no IP, no chain
	 * internals beyond the row_hash). Null when the uid is unknown.
	 *
	 * @param string $request_uid Request UID.
	 * @return array<string,mixed>|null
	 */
	public function detail( string $request_uid ): ?array {
		$row = $this->confirmed_row( $request_uid );
		if ( null === $row ) {
			return null;
		}
		$payload = $this->payload( $row );
		$status  = $this->statuses_for_uids( array( $request_uid ) )[ $request_uid ] ?? 'open';

		$products = ( isset( $payload['statement']['products'] ) && is_array( $payload['statement']['products'] ) )
			? array_values( array_map( 'strval', $payload['statement']['products'] ) )
			: array();
		$product_quantities = ( isset( $payload['statement']['product_quantities'] ) && is_array( $payload['statement']['product_quantities'] ) )
			? array_map( 'intval', $payload['statement']['product_quantities'] )
			: array();

		return array_merge(
			$this->lean_row( $row, $status ),
			array(
				'consumer_email' => (string) ( $row['customer_email'] ?? '' ),
				'products'       => $products,
				'product_quantities' => $product_quantities,
				'submitted_at'   => (string) ( $payload['submitted_at'] ?? $row['created_at'] ?? '' ),
				'days_left'      => isset( $payload['days_left'] ) ? (int) $payload['days_left'] : null,
				'row_hash'       => (string) ( $row['row_hash'] ?? '' ),
			)
		);
	}

	/**
	 * Per-order withdrawal status for an order-management integration.
	 *
	 * @param string $platform  Platform key.
	 * @param string $order_ref Order reference.
	 * @return array{withdrawn:bool,status:string,request_uid?:string,created_at?:string}|null
	 *         Null when the platform/order is unknown to the plugin (→ 404); a
	 *         `{ withdrawn:false }` shape when the order is known but has no request.
	 */
	public function order_status( string $platform, string $order_ref ): ?array {
		global $wpdb;
		$table = LogTable::name();

		// Any log row for this order means the order is "known" to the plugin.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$known = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE platform = %s AND order_ref = %s", $platform, $order_ref )
		);
		if ( 0 === $known ) {
			return null; // Unknown order → 404 at the route.
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$confirmed = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT request_uid, created_at FROM {$table} WHERE platform = %s AND order_ref = %s AND event = %s ORDER BY id DESC LIMIT 1",
				$platform,
				$order_ref,
				'confirmed'
			),
			ARRAY_A
		);

		if ( ! is_array( $confirmed ) || empty( $confirmed['request_uid'] ) ) {
			return array( 'withdrawn' => false, 'status' => 'none' );
		}

		$uid    = (string) $confirmed['request_uid'];
		$status = $this->statuses_for_uids( array( $uid ) )[ $uid ] ?? 'open';

		return array(
			'withdrawn'   => true,
			'status'      => $status,
			'request_uid' => $uid,
			'created_at'  => $this->iso( (string) $confirmed['created_at'] ),
		);
	}

	/**
	 * Build the outbound-webhook payload for a confirmed request, or null if the
	 * uid is unknown. Same privacy rules as detail() (email yes, IP never).
	 *
	 * @param string $request_uid Request UID.
	 * @return array<string,mixed>|null
	 */
	public function webhook_payload( string $request_uid ): ?array {
		$row = $this->confirmed_row( $request_uid );
		if ( null === $row ) {
			return null;
		}
		$payload = $this->payload( $row );
		$status  = $this->statuses_for_uids( array( $request_uid ) )[ $request_uid ] ?? 'open';

		return array(
			'event'          => 'withdrawal.confirmed',
			'request_uid'    => (string) ( $row['request_uid'] ?? '' ),
			'platform'       => (string) ( $row['platform'] ?? '' ),
			'order_ref'      => (string) ( $row['order_ref'] ?? '' ),
			'order_number'   => (string) ( $payload['order_number'] ?? $row['order_ref'] ?? '' ),
			'consumer_email' => (string) ( $row['customer_email'] ?? '' ),
			'status'         => $status,
			'country'        => (string) ( $payload['country'] ?? '' ),
			'within_window'  => (bool) ( $payload['within_window'] ?? true ),
			'created_at'     => $this->iso( (string) ( $row['created_at'] ?? '' ) ),
			'row_hash'       => (string) ( $row['row_hash'] ?? '' ),
		);
	}

	/* --------------------------------------------------------------------- *
	 * Internals
	 * --------------------------------------------------------------------- */

	/**
	 * Shape a lean list row (NO email, NO IP, NO chain internals).
	 *
	 * @param array  $row    DB row (associative).
	 * @param string $status Derived status.
	 * @return array<string,mixed>
	 */
	private function lean_row( array $row, string $status ): array {
		$payload = $this->payload( $row );
		return array(
			'request_uid'   => (string) ( $row['request_uid'] ?? '' ),
			'platform'      => (string) ( $row['platform'] ?? '' ),
			'order_ref'     => (string) ( $row['order_ref'] ?? '' ),
			'order_number'  => (string) ( $payload['order_number'] ?? $row['order_ref'] ?? '' ),
			'status'        => $status,
			'country'       => (string) ( $payload['country'] ?? '' ),
			'within_window' => (bool) ( $payload['within_window'] ?? true ),
			'created_at'    => $this->iso( (string) ( $row['created_at'] ?? '' ) ),
		);
	}

	/**
	 * Decode a row's payload_json safely.
	 *
	 * @param array $row DB row.
	 * @return array<string,mixed>
	 */
	private function payload( array $row ): array {
		$decoded = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Fetch the confirmed row for a uid (full row, internal use only).
	 *
	 * @param string $request_uid Request UID.
	 * @return array<string,mixed>|null
	 */
	private function confirmed_row( string $request_uid ): ?array {
		global $wpdb;
		$table = LogTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE request_uid = %s AND event = %s LIMIT 1", $request_uid, 'confirmed' ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Derive status for a set of uids in a single query.
	 *
	 * @param string[] $uids Request UIDs.
	 * @return array<string,string> uid → open|processed|refunded.
	 */
	private function statuses_for_uids( array $uids ): array {
		$uids = array_values( array_unique( array_filter( array_map( 'strval', $uids ) ) ) );
		if ( empty( $uids ) ) {
			return array();
		}

		global $wpdb;
		$table = LogTable::name();

		$placeholders = implode( ',', array_fill( 0, count( $uids ), '%s' ) );
		$args         = array_merge( array( 'request_processed', 'refund_issued' ), $uids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT request_uid, event FROM {$table} WHERE event IN ( %s, %s ) AND request_uid IN ( {$placeholders} )", ...$args ),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$map = array_fill_keys( $uids, 'open' );
		foreach ( $rows as $r ) {
			$uid = (string) ( $r['request_uid'] ?? '' );
			if ( ! isset( $map[ $uid ] ) ) {
				continue;
			}
			$event = (string) ( $r['event'] ?? '' );
			if ( 'refund_issued' === $event ) {
				$map[ $uid ] = 'refunded'; // Wins outright.
			} elseif ( 'request_processed' === $event && 'refunded' !== $map[ $uid ] ) {
				$map[ $uid ] = 'processed';
			}
		}
		return $map;
	}

	/**
	 * Build the WHERE clause + bound args for the list/count queries.
	 *
	 * The status filter uses EXISTS/NOT EXISTS subqueries on the same table so the
	 * count + pagination stay correct; the event literals are constants (bound for
	 * consistency), the table name is a trusted prefix-derived identifier.
	 *
	 * @param array $filters Raw filters.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function build_where( array $filters ): array {
		global $wpdb;
		$table = LogTable::name();

		$where = array( 't.event = %s' );
		$args  = array( 'confirmed' );

		$platform = isset( $filters['platform'] ) ? (string) $filters['platform'] : '';
		if ( '' !== $platform ) {
			$where[] = 't.platform = %s';
			$args[]  = $platform;
		}

		$after = $this->date_bound( $filters['after'] ?? '', true );
		if ( '' !== $after ) {
			$where[] = 't.created_at >= %s';
			$args[]  = $after;
		}

		$before = $this->date_bound( $filters['before'] ?? '', false );
		if ( '' !== $before ) {
			$where[] = 't.created_at <= %s';
			$args[]  = $before;
		}

		$status = isset( $filters['status'] ) ? (string) $filters['status'] : '';
		if ( in_array( $status, self::STATUSES, true ) ) {
			if ( 'refunded' === $status ) {
				$where[] = "EXISTS ( SELECT 1 FROM {$table} r WHERE r.request_uid = t.request_uid AND r.event = %s )";
				$args[]  = 'refund_issued';
			} elseif ( 'processed' === $status ) {
				$where[] = "EXISTS ( SELECT 1 FROM {$table} p WHERE p.request_uid = t.request_uid AND p.event = %s )";
				$args[]  = 'request_processed';
				$where[] = "NOT EXISTS ( SELECT 1 FROM {$table} r WHERE r.request_uid = t.request_uid AND r.event = %s )";
				$args[]  = 'refund_issued';
			} else { // open.
				$where[] = "NOT EXISTS ( SELECT 1 FROM {$table} x WHERE x.request_uid = t.request_uid AND x.event IN ( %s, %s ) )";
				$args[]  = 'request_processed';
				$args[]  = 'refund_issued';
			}
		}

		return array( implode( ' AND ', $where ), $args );
	}

	/**
	 * Validate an ISO date (Y-m-d) and turn it into a UTC datetime bound.
	 *
	 * @param mixed $value Raw input.
	 * @param bool  $start True for the day's start (00:00:00), false for end (23:59:59).
	 * @return string Empty string when the input is not a valid Y-m-d date.
	 */
	private function date_bound( $value, bool $start ): string {
		$value = trim( (string) $value );
		if ( '' === $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}
		$dt = \DateTime::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $value ) {
			return '';
		}
		return $value . ( $start ? ' 00:00:00' : ' 23:59:59' );
	}

	/**
	 * Normalise a stored UTC datetime ("Y-m-d H:i:s") to ISO-8601 Z.
	 *
	 * @param string $value Stored datetime.
	 * @return string
	 */
	private function iso( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		// Re-emit a canonical UTC "Z" string from the two shapes we actually store:
		// ISO-8601 (submitted_at, gmdate Y-m-d\TH:i:s\Z) and the log's "Y-m-d H:i:s".
		// Never echo an unparseable value straight back out (audit INFO-2).
		$utc = new \DateTimeZone( 'UTC' );
		foreach ( array( 'Y-m-d\TH:i:s\Z', 'Y-m-d H:i:s' ) as $fmt ) {
			$dt = \DateTimeImmutable::createFromFormat( $fmt, $value, $utc );
			if ( $dt instanceof \DateTimeImmutable ) {
				return $dt->format( 'Y-m-d\TH:i:s\Z' );
			}
		}
		// Last resort: a permissive parse; fall back to '' rather than echo garbage.
		try {
			return ( new \DateTimeImmutable( $value, $utc ) )->format( 'Y-m-d\TH:i:s\Z' );
		} catch ( \Exception $e ) {
			return '';
		}
	}
}
