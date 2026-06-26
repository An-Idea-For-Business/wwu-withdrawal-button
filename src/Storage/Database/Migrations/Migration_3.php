<?php
/**
 * Migration 3 — evidence-log hardening (plugin 1.1.0).
 *
 * Adds two columns to the immutable log so the chain can be keyed and the IP can
 * be given a GDPR erasure horizon without breaking tamper-evidence:
 *
 *   - ip_full       full client IP, kept OUTSIDE the hash (purgeable). From now on
 *                   the hashed `ip_address` holds the ANONYMISED IP; the full value
 *                   lives here and is blanked by the retention purge after the
 *                   horizon (GDPR Art. 5(1)(e) storage limitation).
 *   - chain_version the LogChain format the row was written under. Existing rows
 *                   default to 1 (legacy unkeyed SHA-256); rows written from 1.1.0
 *                   onward are 2 (HMAC-keyed). Verification picks the formula per
 *                   row, so a mixed chain stays verifiable.
 *
 * Idempotent: dbDelta only adds the columns when they are missing, so re-running
 * (re-activation, plugins_loaded self-heal) is safe. Existing rows are NOT
 * rewritten — their raw IP stays in `ip_address` (part of their v1 hash) and they
 * verify under chain_version = 1.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Storage\Database\Migrations;

use WebWakeUpWdb\WithdrawalButton\Storage\Database\LogTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add ip_full + chain_version to the immutable log.
 */
final class Migration_3 {

	/**
	 * Apply the migration.
	 *
	 * @return void
	 */
	public static function up(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// dbDelta compares the live table to the CREATE statement and adds any
		// missing columns (ip_full, chain_version). It never drops or rewrites data.
		dbDelta( LogTable::create_sql() );
	}
}
