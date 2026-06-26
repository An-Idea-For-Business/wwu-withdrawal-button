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
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Core;

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
	public const CRON_COMPLETE_NETWORK = 'webwakeupwdb_complete_network_activation';

	/**
	 * Autoloaded flag option: '1' means a one-time rewrite-rules flush is pending
	 * on the next wp_loaded (after the WooCommerce My Account endpoint has been
	 * registered on init). Toggled to '0' once flushed — never deleted, so the
	 * check stays a cheap autoload-cache read with no extra DB query per request.
	 *
	 * @var string
	 */
	public const OPTION_FLUSH_PENDING = 'webwakeupwdb_flush_pending';

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
		self::ensure_form_page();

		Migrator::migrate( (int) get_option( Migrator::OPTION_DB_VERSION, 0 ), (int) WEBWAKEUPWDB_SCHEMA_VERSION );

		// The WooCommerce My Account withdrawal tab is a rewrite endpoint registered
		// on `init` by the frontend layer. During this activation request the plugin
		// boot never runs (plugins_loaded has already fired), so the endpoint is NOT
		// registered and the flush below cannot persist its rule yet. We flush anyway
		// (harmless) AND set a flag for a one-time deferred flush on the next
		// wp_loaded, by which point the endpoint exists — otherwise the
		// "/my-account/<slug>" tab 404s until the admin re-saves permalinks.
		flush_rewrite_rules( false );
		update_option( self::OPTION_FLUSH_PENDING, '1' );

		// Daily consent-retention purge (GDPR storage limitation).
		ConsentRetention::schedule();

		// Invalidate any Complianz blocked-scripts cache so our marker is honoured.
		\WebWakeUpWdb\WithdrawalButton\Compat\Complianz::bust_cache();
	}

	/**
	 * One-time deferred rewrite-rules flush, run on wp_loaded after activation.
	 *
	 * The activation-time flush in setup_site() runs before the My Account
	 * endpoint is registered (the plugin boot never runs during activation, as
	 * plugins_loaded has already fired). This callback fires on the FIRST normal
	 * request after activation — by wp_loaded the endpoint has been registered on
	 * init, so flushing here persists its rewrite rule and the
	 * "/my-account/<slug>" tab resolves instead of returning a 404. Idempotent:
	 * the flag is flipped to '0' (kept autoloaded, never deleted) so subsequent
	 * requests are a cache-only no-op with no extra DB query.
	 *
	 * @return void
	 */
	public static function maybe_deferred_flush(): void {
		if ( '1' !== (string) get_option( self::OPTION_FLUSH_PENDING, '0' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::OPTION_FLUSH_PENDING, '0' );
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
		if ( ! is_plugin_active_for_network( WEBWAKEUPWDB_PLUGIN_BASENAME ) ) {
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
			'webwakeupwdb_settings',
			array(
				'enabled'              => false,
				'endpoint_slug'        => 'wwu-withdrawal',
				'public_form_page_id'  => 0,
				'policy_page_id'       => 0,
				// Complianz document injection (opt-in, default off): append the
				// withdrawal clauses to the merchant's Complianz Privacy Policy and
				// (with the complianz-terms-conditions companion) Terms & Conditions.
				'complianz_inject_privacy' => false,
				'complianz_inject_terms'   => false,
				'withdrawal_window_days' => 14,
				'send_pdf'             => true,
				'receipt_link_enabled' => true,
				'merchant_email'       => get_option( 'admin_email' ),
				'retention_years'      => 10,
				'consent_capture_ip'   => true,
				'go_live_date'         => WEBWAKEUPWDB_GO_LIVE_DATE,
				/*
				 * Consumer-facing copy overrides. Both default to '' which means the
				 * built-in i18n text is used; non-empty values are rendered as-is
				 * (sanitized via wp_kses_post on save). See SettingsPage::render_guidance_section().
				 */
				'custom_guidance'       => '',
				'custom_exemption_note' => '',
				// Subscriptions: a renewal does NOT restart the 14-day right (one right
				// per contract, at conclusion — Art. 9 CRD / art. 52 Cod. Consumo), so by
				// default the button shows on the initial order only and is suppressed on
				// renewals. Auto-cancelling the subscription on withdrawal is OFF by
				// default (the merchant handles the refund + any pro-rata manually).
				'treat_renewals_as_withdrawable'   => false,
				'cancel_subscription_on_withdrawal' => false,
				// FluentCart handling: 'auto' shows our button on FluentCart orders but
				// steps aside automatically if FluentCart's own withdrawal add-on is
				// detected (no duplicate); 'always' keeps ours; 'off' disables our
				// FluentCart surfaces. Default 'auto'. {@see FluentCartAdapter::should_render()}.
				'fluentcart_mode'                  => 'auto',
			),
			'',
			'yes'
		);

		add_option(
			'webwakeupwdb_applicability',
			array(
				'mode'                 => 'eu_eea_only',
				'custom_countries'     => array(),
				'b2b_vat_out_of_scope' => true,
			),
			'',
			'yes'
		);

		// Read only inside the withdrawal flow → not autoloaded on every page.
		add_option( 'webwakeupwdb_labels', array(), '', 'no' );

		add_option(
			'webwakeupwdb_exclusions',
			array(
				// Per-reason exemption map: { '<59_x>': { products:[], categories:[] } }.
				// The merchant tags products/categories under a specific statutory
				// reason (Art. 59) via Settings → Exemptions. Empty = nothing exempt
				// (the right of withdrawal is the default, including digital).
				'by_reason'           => array(),
				// Legacy crude digital auto-detect. Default OFF: the digital exemption
				// (Art. 59 lett. o / Art. 16(m)) only applies with captured consent +
				// acknowledgment — which the auto-detect does NOT verify. The proper
				// path is tagging '59_o' with consent capture.
				'auto_detect_virtual' => false,
			),
			'',
			'no'
		);

		add_option(
			'webwakeupwdb_timestamp',
			array(
				// External calls are OPT-IN (wp.org Guideline 7: no phoning home without
				// explicit consent). Default 'none' = the local hash-chained log still
				// works; the merchant opts into OpenTimestamps or an RFC 3161 authority.
				'provider' => 'none',
				'rfc3161'  => array(
					'endpoint' => '',
					'user'     => '',
					'pass'     => '',
				),
			),
			'',
			'no'
		);

		add_option(
			'webwakeupwdb_compliance',
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
			'webwakeupwdb_debug',
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

		// Outbound automations webhook (read-only API needs no option — it uses
		// Application Passwords). The secret is a shared HMAC key, stored retrievable
		// (we sign every delivery) and only ever shown masked. Autoload no: it is
		// read only at delivery time, never on a front-end page.
		add_option(
			'webwakeupwdb_webhook',
			array(
				'enabled' => false,
				'url'     => '',
				'secret'  => '',
			),
			'',
			'no'
		);

		add_option( Migrator::OPTION_DB_VERSION, '0', '', 'yes' );
	}

	/**
	 * Ensure a published page with the [webwakeupwdb_form] shortcode exists, so guests
	 * (and FluentCart customers) always have a reachable withdrawal surface. The
	 * page id is stored in settings['public_form_page_id'].
	 *
	 * @return void
	 */
	public static function ensure_form_page(): int {
		return self::ensure_page(
			'public_form_page_id',
			__( 'Right of withdrawal', 'wwu-withdrawal-button' ),
			'right-of-withdrawal',
			'[webwakeupwdb_form]',
			'publish'
		);
	}

	/**
	 * Ensure the consolidated "Right of withdrawal — information notice" policy
	 * page exists. Created as a DRAFT (the merchant publishes it explicitly).
	 * Stored in settings['policy_page_id']. Idempotent + self-healing (recreated
	 * if trashed/deleted). Content is the [webwakeupwdb_policy] shortcode. {@see PolicyBuilder}.
	 *
	 * @return int The policy page id (0 on failure).
	 */
	public static function ensure_policy_page(): int {
		return self::ensure_page(
			'policy_page_id',
			__( 'Right of withdrawal — information notice', 'wwu-withdrawal-button' ),
			'withdrawal-policy',
			'[webwakeupwdb_policy]',
			'draft'
		);
	}

	/**
	 * Create-if-missing helper for an auto-managed page. Returns the existing id
	 * when the stored page is still a non-trashed `page`; otherwise — deleted or
	 * trashed by the merchant — self-heals by creating a fresh one and storing its
	 * id. Safe to call on demand: the "Recreate page" admin buttons use it so a
	 * page removed by mistake is a one-click fix instead of a plugin re-activation.
	 *
	 * @param string $key     Settings key holding the page id.
	 * @param string $title   Page title.
	 * @param string $slug    Page slug.
	 * @param string $content Page content (the relevant shortcode).
	 * @param string $status  'publish' | 'draft'.
	 * @return int The page id (0 on failure).
	 */
	private static function ensure_page( string $key, string $title, string $slug, string $content, string $status ): int {
		$settings = (array) get_option( 'webwakeupwdb_settings', array() );
		$page_id  = (int) ( $settings[ $key ] ?? 0 );

		if ( $page_id > 0 && 'page' === get_post_type( $page_id ) && 'trash' !== get_post_status( $page_id ) ) {
			return $page_id; // still valid — no duplicate.
		}

		$new_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_status'  => $status,
				'post_type'    => 'page',
			),
			true
		);

		if ( ! is_wp_error( $new_id ) && $new_id > 0 ) {
			$settings[ $key ] = (int) $new_id;
			update_option( 'webwakeupwdb_settings', $settings );
			return (int) $new_id;
		}

		return 0;
	}

	/**
	 * Ensure a per-site cryptographic secret exists (log genesis + token HMAC).
	 * Generated once, never exposed, never regenerated unless missing.
	 *
	 * @return void
	 */
	private static function ensure_secret(): void {
		// Delegate to the central Secret accessor, which mints + persists the
		// secret if it is missing (and is also the fail-safe used by every token
		// gate at runtime, so the key is never an empty string).
		\WebWakeUpWdb\WithdrawalButton\Security\Secret::get();
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
		ConsentRetention::unschedule();
		\WebWakeUpWdb\WithdrawalButton\Timestamp\TimestampService::clear_cron();
		flush_rewrite_rules( false );
	}
}
