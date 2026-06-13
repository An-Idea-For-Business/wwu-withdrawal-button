<?php
/**
 * Schema definition for the immutable, append-only, hash-chained withdrawal log.
 *
 * This table holds the legally-required evidence of every withdrawal event
 * (Italian "log immodificabile": date, time, IP, contract data). It is:
 *   - APPEND-ONLY: there is no updated_at/deleted_at column and no code path
 *     that updates or deletes a row.
 *   - HASH-CHAINED: each row stores prev_hash + row_hash forming a global chain;
 *     altering or removing any row breaks verification from that point onward.
 *   - DATETIME (never TIMESTAMP): TIMESTAMP can auto-update on row change in some
 *     MySQL configurations, which would silently destroy tamper-evidence.
 *
 * @see \WWU\WithdrawalButton\Storage\LogRepository
 * @see \WWU\WithdrawalButton\Storage\LogChain
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Storage\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable withdrawal log table.
 */
final class LogTable {

	/**
	 * Unprefixed table suffix.
	 *
	 * @var string
	 */
	public const SUFFIX = 'wwu_wb_log';

	/**
	 * Fully-qualified table name including the site table prefix.
	 *
	 * @return string
	 */
	public static function name(): string {
		global $wpdb;
		return $wpdb->prefix . self::SUFFIX;
	}

	/**
	 * dbDelta-compatible CREATE TABLE statement.
	 *
	 * dbDelta quirks honoured here:
	 *   - two spaces after "PRIMARY KEY";
	 *   - table name NOT wrapped in backticks;
	 *   - KEY (not INDEX) for secondary indexes;
	 *   - $wpdb->get_charset_collate() for charset.
	 *
	 * @return string
	 */
	public static function create_sql(): string {
		global $wpdb;
		$table   = self::name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_uid    char(36) NOT NULL,
			platform       varchar(20) NOT NULL DEFAULT 'woocommerce',
			order_ref      varchar(64) NOT NULL DEFAULT '',
			customer_email varchar(255) NOT NULL DEFAULT '',
			event          varchar(40) NOT NULL DEFAULT '',
			payload_json   longtext NOT NULL,
			ip_address     varchar(45) NOT NULL DEFAULT '',
			prev_hash      char(64) NOT NULL DEFAULT '',
			row_hash       char(64) NOT NULL DEFAULT '',
			ots_proof_id   bigint(20) unsigned DEFAULT NULL,
			created_at     datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_request (request_uid),
			KEY idx_order (platform, order_ref),
			KEY idx_email (customer_email(60)),
			KEY idx_created (created_at)
		) {$charset};";
	}

	/**
	 * Drop the table. Called only from uninstall (never on deactivation).
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;
		$table = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a constant-derived identifier.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
