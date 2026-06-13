<?php
/**
 * Numbered database migration runner.
 *
 * Mirrors the canonical WWU pattern (wwu-experiments-lite): each schema change
 * is a numbered class Migration_N with a static up() method; the runner applies
 * every migration whose number is greater than the stored db version, in order.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies pending migrations and tracks the installed schema version.
 */
final class Migrator {

	/**
	 * Option key holding the installed schema version for the current site.
	 *
	 * @var string
	 */
	public const OPTION_DB_VERSION = 'wwu_wb_db_version';

	/**
	 * Run every migration in (from, to].
	 *
	 * @param int $from Currently installed schema version (0 on first install).
	 * @param int $to   Target schema version (WWU_WB_SCHEMA_VERSION).
	 * @return void
	 */
	public static function migrate( int $from, int $to ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		for ( $version = $from + 1; $version <= $to; $version++ ) {
			$class = '\\WWU\\WithdrawalButton\\Storage\\Database\\Migrations\\Migration_' . $version;
			if ( ! class_exists( $class ) || ! method_exists( $class, 'up' ) ) {
				continue;
			}
			call_user_func( array( $class, 'up' ) );
			update_option( self::OPTION_DB_VERSION, (string) $version, 'yes' );
		}
	}

	/**
	 * Upgrade the current site's schema if it is behind the target version.
	 *
	 * Hooked on plugins_loaded:5 so a file-system upgrade (FTP/rsync) that did not
	 * run the activation hook still self-heals the schema on the next request.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::OPTION_DB_VERSION, 0 );
		$target    = (int) WWU_WB_SCHEMA_VERSION;

		if ( $installed < $target ) {
			self::migrate( $installed, $target );
		}
	}
}
