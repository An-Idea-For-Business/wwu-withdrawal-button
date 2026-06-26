<?php
/**
 * Migration 5 — rename the custom DB tables after the prefix rename (plugin 1.3.0).
 *
 * The 1.3.0 WordPress.org-compliance rename changed the table suffix from
 * `wwu_wb_log` / `wwu_wb_timestamps` to `webwakeupwdb_log` / `webwakeupwdb_timestamps`
 * (LogTable::SUFFIX / TimestampTable::SUFFIX). The one-time option/meta/page adoption
 * did NOT rename the tables, so on an upgraded install the immutable log + timestamp
 * tables still carry the old name and the new code cannot find them. This renames
 * them so the existing evidence is preserved.
 *
 * Idempotent: only renames when the old table exists and the new one does not, and
 * then runs dbDelta to guarantee the schema. A fresh 1.3.0 install (tables already
 * created under the new name by Migration 1) is a no-op.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Storage\Database\Migrations;

use WebWakeUpWdb\WithdrawalButton\Storage\Database\LogTable;
use WebWakeUpWdb\WithdrawalButton\Storage\Database\TimestampTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rename the pre-1.3.0 custom tables to the new prefix.
 */
final class Migration_5 {

	/**
	 * Apply the migration.
	 *
	 * @return void
	 */
	public static function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$renames = array(
			$wpdb->prefix . 'wwu_wb_log'        => $wpdb->prefix . LogTable::SUFFIX,
			$wpdb->prefix . 'wwu_wb_timestamps' => $wpdb->prefix . TimestampTable::SUFFIX,
		);

		foreach ( $renames as $old => $new ) {
			$old_exists = ( $old === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$new_exists = ( $new === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $old_exists && ! $new_exists ) {
				// $old/$new are code-derived identifiers (prefix + class SUFFIX).
				$wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		// Guarantee the schema (creates the tables fresh if neither name existed; a
		// no-op when the rename already put them in place).
		dbDelta( LogTable::create_sql() );
		dbDelta( TimestampTable::create_sql() );
	}
}
