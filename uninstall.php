<?php
/**
 * Uninstall cleanup for WWU Withdrawal Button.
 *
 * IMPORTANT — legal-hold default: the immutable withdrawal log and its timestamp
 * proofs are EVIDENCE. By default this uninstaller KEEPS those two tables and the
 * per-site secret, and removes only configuration options, transients and cron.
 * Set wwu_wb_settings['erase_on_uninstall'] = true to also drop the evidence
 * tables (irreversible — only do this once you are certain no dispute is pending).
 *
 * Self-contained: no plugin classes are loaded here.
 *
 * @package WWU\WithdrawalButton
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
function wwu_wb_uninstall_cleanup_site(): void {
	global $wpdb;

	$settings  = get_option( 'wwu_wb_settings', array() );
	$erase_all = is_array( $settings ) && ! empty( $settings['erase_on_uninstall'] );

	// Configuration options (always removed).
	$options = array(
		'wwu_wb_settings',
		'wwu_wb_applicability',
		'wwu_wb_labels',
		'wwu_wb_exclusions',
		'wwu_wb_timestamp',
		'wwu_wb_compliance',
		'wwu_wb_debug',
		'wwu_wb_db_version',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// FluentCart per-order operational meta options (wwu_wb_fc_*).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wwu_wb_fc_%'" );

	// Cron.
	wp_clear_scheduled_hook( 'wwu_wb_complete_network_activation' );
	wp_clear_scheduled_hook( 'wwu_wb_timestamp_upgrade' );
	wp_clear_scheduled_hook( 'wwu_wb_consent_retention_purge' );

	if ( $erase_all ) {
		// Irreversible: drop the evidence tables + secret only on explicit opt-in.
		$log_table = $wpdb->prefix . 'wwu_wb_log';
		$ts_table  = $wpdb->prefix . 'wwu_wb_timestamps';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$log_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$ts_table}" );
		// phpcs:enable
		delete_option( 'wwu_wb_secret' );
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
		wwu_wb_uninstall_cleanup_site();
		restore_current_blog();
	}
} else {
	wwu_wb_uninstall_cleanup_site();
}
