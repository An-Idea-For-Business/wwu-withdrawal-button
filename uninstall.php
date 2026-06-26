<?php
/**
 * Uninstall cleanup for WWU Withdrawal Button.
 *
 * IMPORTANT — legal-hold default: the immutable withdrawal log and its timestamp
 * proofs are EVIDENCE. By default this uninstaller KEEPS those two tables and the
 * per-site secret, and removes only configuration options, transients and cron.
 * Set webwakeupwdb_settings['erase_on_uninstall'] = true to also drop the evidence
 * tables (irreversible — only do this once you are certain no dispute is pending).
 *
 * Self-contained: no plugin classes are loaded here.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean a single site (current blog context).
 *
 * @return void
 */
function webwakeupwdb_uninstall_cleanup_site(): void {
	global $wpdb;

	$settings  = get_option( 'webwakeupwdb_settings', array() );
	$erase_all = is_array( $settings ) && ! empty( $settings['erase_on_uninstall'] );

	// Configuration options (always removed).
	$options = array(
		'webwakeupwdb_settings',
		'webwakeupwdb_applicability',
		'webwakeupwdb_labels',
		'webwakeupwdb_exclusions',
		'webwakeupwdb_timestamp',
		'webwakeupwdb_compliance',
		'webwakeupwdb_debug',
		'webwakeupwdb_webhook',
		'webwakeupwdb_db_version',
		'webwakeupwdb_flush_pending',
		'webwakeupwdb_clauses',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// FluentCart per-order operational meta options (webwakeupwdb_fc_*).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'webwakeupwdb_fc_%'" );

	// Cron.
	wp_clear_scheduled_hook( 'webwakeupwdb_complete_network_activation' );
	wp_clear_scheduled_hook( 'webwakeupwdb_timestamp_upgrade' );
	wp_clear_scheduled_hook( 'webwakeupwdb_consent_retention_purge' );
	wp_clear_scheduled_hook( 'webwakeupwdb_deliver_webhook' );

	if ( $erase_all ) {
		// Irreversible: drop the evidence tables + secret only on explicit opt-in.
		$log_table = $wpdb->prefix . 'webwakeupwdb_log';
		$ts_table  = $wpdb->prefix . 'webwakeupwdb_timestamps';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$log_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$ts_table}" );
		// phpcs:enable
		delete_option( 'webwakeupwdb_secret' );
	}
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		webwakeupwdb_uninstall_cleanup_site();
		restore_current_blog();
	}
} else {
	webwakeupwdb_uninstall_cleanup_site();
}
