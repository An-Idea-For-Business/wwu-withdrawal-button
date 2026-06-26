<?php
/**
 * Numbered database migration runner.
 *
 * Mirrors the canonical WWU pattern (wwu-experiments-lite): each schema change
 * is a numbered class Migration_N with a static up() method; the runner applies
 * every migration whose number is greater than the stored db version, in order.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Core;

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
	public const OPTION_DB_VERSION = 'webwakeupwdb_db_version';

	/**
	 * Run every migration in (from, to].
	 *
	 * @param int $from Currently installed schema version (0 on first install).
	 * @param int $to   Target schema version (WEBWAKEUPWDB_SCHEMA_VERSION).
	 * @return void
	 */
	public static function migrate( int $from, int $to ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		for ( $version = $from + 1; $version <= $to; $version++ ) {
			$class = '\\WebWakeUpWdb\\WithdrawalButton\\Storage\\Database\\Migrations\\Migration_' . $version;
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
		self::maybe_adopt_legacy_prefix();

		$installed = (int) get_option( self::OPTION_DB_VERSION, 0 );
		$target    = (int) WEBWAKEUPWDB_SCHEMA_VERSION;

		if ( $installed < $target ) {
			self::migrate( $installed, $target );
		}
	}

	/**
	 * One-time data adoption after the WordPress.org-compliance prefix rename
	 * (`wwu_wb_` / `WWU_WB_` → `webwakeupwdb_` / `WEBWAKEUPWDB_`, in 1.3.0). Renames
	 * every legacy option, the exemption-consent order meta, and the shortcodes in
	 * the auto-created pages, so an install from before the rename keeps its
	 * settings, its evidence and its working pages.
	 *
	 * Guarded twice: it no-ops once the new db-version option exists (already
	 * adopted) AND no-ops when there is no legacy marker at all — so a clean
	 * WordPress install (the .org review environment) is completely unaffected.
	 *
	 * @return void
	 */
	private static function maybe_adopt_legacy_prefix(): void {
		// Already on the new prefix → nothing to do.
		if ( false !== get_option( self::OPTION_DB_VERSION, false ) ) {
			return;
		}
		// Fresh install (no legacy data) → nothing to adopt.
		if ( false === get_option( 'wwu_wb_db_version', false ) ) {
			return;
		}

		global $wpdb;

		// 1) Options: wwu_wb_* → webwakeupwdb_* (preserving autoload).
		$rows = $wpdb->get_results( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE 'wwu\\_wb\\_%'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $rows as $row ) {
			$old = (string) $row['option_name'];
			$new = 'webwakeupwdb_' . substr( $old, strlen( 'wwu_wb_' ) );
			if ( false === get_option( $new, false ) ) {
				$autoload_yes = ! in_array( strtolower( (string) $row['autoload'] ), array( 'no', 'off', 'auto-off' ), true );
				update_option( $new, get_option( $old ), $autoload_yes );
			}
			delete_option( $old );
		}

		// 2) Order meta (exemption-consent evidence): _wwu_wb_* → _webwakeupwdb_*,
		// across legacy postmeta + the HPOS orders-meta table when it exists.
		$start       = strlen( '_wwu_wb_' ) + 1;
		$meta_tables = array( $wpdb->postmeta );
		$hpos        = $wpdb->prefix . 'wc_orders_meta';
		if ( $hpos === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta_tables[] = $hpos;
		}
		foreach ( $meta_tables as $table ) {
			// $table is a trusted, code-derived identifier; $start is an int literal.
			$wpdb->query( "UPDATE `{$table}` SET meta_key = CONCAT('_webwakeupwdb_', SUBSTRING(meta_key, {$start})) WHERE meta_key LIKE '\\_wwu\\_wb\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		}

		// 3) Shortcodes inside the auto-created pages (form + policy).
		$settings = (array) get_option( 'webwakeupwdb_settings', array() );
		foreach ( array( 'public_form_page_id', 'policy_page_id' ) as $key ) {
			$pid = isset( $settings[ $key ] ) ? (int) $settings[ $key ] : 0;
			if ( $pid <= 0 ) {
				continue;
			}
			$post = get_post( $pid );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$content = str_replace( '[wwu_wb_', '[webwakeupwdb_', (string) $post->post_content );
			if ( $content !== $post->post_content ) {
				wp_update_post( array( 'ID' => $pid, 'post_content' => $content ) );
			}
		}

		// 4) Drop stale object-cache entries for the renamed options/meta.
		wp_cache_flush();
	}
}
