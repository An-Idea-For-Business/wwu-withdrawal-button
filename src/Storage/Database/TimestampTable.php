<?php
/**
 * Schema definition for the trusted-timestamp proofs table.
 *
 * Each row stores the cryptographic timestamp of a log row's hash. The default
 * provider is OpenTimestamps (free, Bitcoin-anchored); the column set is generic
 * enough to also hold an RFC 3161 / eIDAS qualified timestamp proof later.
 *
 * Anchoring is asynchronous: a row is created with status 'pending' on submission
 * and upgraded to 'confirmed' by a WP-Cron poller once the Bitcoin block is mined.
 *
 * @see \WWU\WithdrawalButton\Timestamp\OpenTimestampsProvider
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Storage\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Timestamp proofs table.
 */
final class TimestampTable {

	/**
	 * Unprefixed table suffix.
	 *
	 * @var string
	 */
	public const SUFFIX = 'wwu_wb_timestamps';

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
	 * @return string
	 */
	public static function create_sql(): string {
		global $wpdb;
		$table   = self::name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_id        bigint(20) unsigned NOT NULL DEFAULT 0,
			sha256_hex    char(64) NOT NULL DEFAULT '',
			nonce_hex     char(32) NOT NULL DEFAULT '',
			provider      varchar(40) NOT NULL DEFAULT 'opentimestamps',
			proof_blob    longblob DEFAULT NULL,
			bitcoin_block int(10) unsigned DEFAULT NULL,
			status        varchar(20) NOT NULL DEFAULT 'pending',
			submitted_at  datetime NOT NULL,
			confirmed_at  datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_log (log_id),
			KEY idx_status (status)
		) {$charset};";
	}

	/**
	 * Drop the table. Called only from uninstall.
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
