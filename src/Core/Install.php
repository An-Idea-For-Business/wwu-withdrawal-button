<?php
/**
 * Activation / deactivation lifecycle, multisite-aware.
 *
 * On activation each site gets: default options (add_option, never overwriting
 * existing settings), a per-site cryptographic secret, the database schema, and
 * a rewrite-rules flush. Network activation processes the first batch of sites
 * synchronously and schedules a cron continuation for the rest to avoid 504s on
 * large networks. New sites created after a network activation are provisioned
 * via the wp_initialize_site hook (wired in Plugin::register_hooks()).
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin installer.
 */
final class Install {

	/**
	 * Number of sites provisioned synchronously during network activation.
	 *
	 * @var int
	 */
	private const SYNC_BATCH_SIZE = 20;

	/**
	 * Cron hook that finishes provisioning the remaining network sites.
	 *
	 * @var string
	 */
	public const CRON_COMPLETE_NETWORK = 'wwu_wb_complete_network_activation';

	/**
	 * Activation entry point.
	 *
	 * @param bool $network_wide True when "Network Activate" was used on multisite.
	 * @return void
	 */
	public static function activate( bool $network_wide ): void {
		if ( $network_wide && is_multisite() ) {
			self::activate_network();
			return;
		}
		self::setup_site();
	}

	/**
	 * Provision the first batch of network sites and schedule the rest.
	 *
	 * @return void
	 */
	private static function activate_network(): void {
		$site_ids  = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		$remaining = array();

		foreach ( $site_ids as $index => $site_id ) {
			if ( $index < self::SYNC_BATCH_SIZE ) {
				switch_to_blog( (int) $site_id );
				self::setup_site();
				restore_current_blog();
			} else {
				$remaining[] = (int) $site_id;
			}
		}

		if ( ! empty( $remaining ) && ! wp_next_scheduled( self::CRON_COMPLETE_NETWORK, array( $remaining ) ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_COMPLETE_NETWORK, array( $remaining ) );
		}
	}

	/**
	 * Cron callback: provision the remaining network sites in batches.
	 *
	 * @param array $site_ids Site IDs still to provision.
	 * @return void
	 */
	public static function complete_network_activation( array $site_ids ): void {
		$batch     = array_splice( $site_ids, 0, self::SYNC_BATCH_SIZE );
		$remaining = $site_ids;

		foreach ( $batch as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::setup_site();
			restore_current_blog();
		}

		if ( ! empty( $remaining ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_COMPLETE_NETWORK, array( $remaining ) );
		}
	}

	/**
	 * Provision a single site (current blog context): options, secret, schema, flush.
	 *
	 * @return void
	 */
	public static function setup_site(): void {
		self::seed_default_options();
		self::ensure_secret();

		Migrator::migrate( (int) get_option( Migrator::OPTION_DB_VERSION, 0 ), (int) WWU_WB_SCHEMA_VERSION );

		// Endpoint rewrite rules are registered in the frontend layer (F1+); flushing
		// here is harmless and ensures they take effect as soon as they exist.
		flush_rewrite_rules( false );

		// Invalidate any Complianz blocked-scripts cache so our marker is honoured.
		\WWU\WithdrawalButton\Compat\Complianz::bust_cache();
	}

	/**
	 * Provision a freshly created subsite (wp_initialize_site hook).
	 *
	 * @param \WP_Site $new_site The new site object.
	 * @return void
	 */
	public static function provision_new_site( \WP_Site $new_site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( WWU_WB_PLUGIN_BASENAME ) ) {
			return;
		}
		switch_to_blog( (int) $new_site->blog_id );
		self::setup_site();
		restore_current_blog();
	}

	/**
	 * Seed default options. add_option() is a no-op when the option already
	 * exists, so re-activating never resets a merchant's configuration.
	 *
	 * @return void
	 */
	private static function seed_default_options(): void {
		add_option(
			'wwu_wb_settings',
			array(
				'enabled'              => false,
				'endpoint_slug'        => 'wwu-withdrawal',
				'public_form_page_id'  => 0,
				'withdrawal_window_days' => 14,
				'send_pdf'             => true,
				'receipt_link_enabled' => true,
				'merchant_email'       => get_option( 'admin_email' ),
				'retention_years'      => 10,
				'go_live_date'         => WWU_WB_GO_LIVE_DATE,
			),
			'',
			'yes'
		);

		add_option(
			'wwu_wb_applicability',
			array(
				'mode'                 => 'eu_eea_only',
				'custom_countries'     => array(),
				'b2b_vat_out_of_scope' => true,
			),
			'',
			'yes'
		);

		add_option( 'wwu_wb_labels', array(), '', 'yes' );

		add_option(
			'wwu_wb_exclusions',
			array(
				'excluded_category_ids' => array(),
				'excluded_product_ids'  => array(),
				'auto_detect_virtual'   => true,
			),
			'',
			'yes'
		);

		add_option(
			'wwu_wb_timestamp',
			array(
				'provider'    => 'opentimestamps',
				'rfc3161_url' => '',
			),
			'',
			'yes'
		);

		add_option(
			'wwu_wb_compliance',
			array(
				'model_form_published' => false,
				'privacy_updated'      => false,
				'terms_updated'        => false,
				'precontract_updated'  => false,
			),
			'',
			'no'
		);

		add_option(
			'wwu_wb_debug',
			array(
				'enabled'       => false,
				'mode'          => 'all_admins',
				'roles'         => array(),
				'users'         => array(),
				'console_level' => 'warn',
			),
			'',
			'no'
		);

		add_option( Migrator::OPTION_DB_VERSION, '0', '', 'yes' );
	}

	/**
	 * Ensure a per-site cryptographic secret exists (log genesis + token HMAC).
	 * Generated once, never exposed, never regenerated unless missing.
	 *
	 * @return void
	 */
	private static function ensure_secret(): void {
		if ( get_option( 'wwu_wb_secret' ) ) {
			return;
		}
		try {
			$secret = bin2hex( random_bytes( 32 ) );
		} catch ( \Exception $e ) {
			$secret = wp_generate_password( 64, true, true );
		}
		add_option( 'wwu_wb_secret', $secret, '', 'no' );
	}

	/**
	 * Deactivation: flush rewrite rules only. Nothing destructive — data and the
	 * immutable log survive deactivation; only uninstall.php removes them.
	 *
	 * @param bool $network_wide Whether the plugin was network-deactivated.
	 * @return void
	 */
	public static function deactivate( bool $network_wide ): void {
		wp_clear_scheduled_hook( self::CRON_COMPLETE_NETWORK );
		\WWU\WithdrawalButton\Timestamp\TimestampService::clear_cron();
		flush_rewrite_rules( false );
	}
}
