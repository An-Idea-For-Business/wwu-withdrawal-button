<?php
/**
 * Migration 1 — initial schema.
 *
 * Creates the immutable withdrawal log table and the trusted-timestamp proofs
 * table. Idempotent: dbDelta() only applies the diff, so re-running is safe.
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
 * Initial schema migration.
 */
final class Migration_1 {

	/**
	 * Apply the migration.
	 *
	 * @return void
	 */
	public static function up(): void {
		// require_once of upgrade.php is done by the Migrator before calling up().
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( LogTable::create_sql() );
		dbDelta( TimestampTable::create_sql() );
	}
}
