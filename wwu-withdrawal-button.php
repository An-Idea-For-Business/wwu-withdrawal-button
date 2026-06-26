<?php
/**
 * Plugin Name:          WWU Right of Withdrawal for Popular Ecommerce Platforms
 * Plugin URI:           https://webwakeup.it/wwu-withdrawal-button/
 * Description:          EU online right-of-withdrawal function ("withdrawal button", Art. 11a Dir. 2011/83/EU as amended by Dir. (EU) 2023/2673; Italy: Art. 54-bis Codice del Consumo). Adds the legally-mandated, statutory-labelled two-step withdrawal flow, durable-medium acknowledgement (email + PDF + verifiable link) and a tamper-evident immutable log to WooCommerce, FluentCart & Easy Digital Downloads. Applies from 19 June 2026.
 * Version:              1.3.0
 * Requires at least:    5.8
 * Requires PHP:         8.1
 * Author:               mredodos, Matteo Alfieri (An Idea for Business), WebWakeUp
 * Author URI:           https://webwakeup.it
 * License:              GPL-3.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          wwu-withdrawal-button
 * Domain Path:          /languages
 * WC requires at least: 5.0
 * WC tested up to:      9.9
 *
 * WWU Withdrawal Button is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Double-load guard: never define the plugin twice (e.g. plugin + mu-plugin copy).
if ( defined( 'WEBWAKEUPWDB_VERSION' ) ) {
	return;
}

/*
 * ---------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------------
 */
define( 'WEBWAKEUPWDB_VERSION', '1.3.0' );
define( 'WEBWAKEUPWDB_MIN_PHP', '8.1' );
define( 'WEBWAKEUPWDB_MIN_WP', '5.8' );
define( 'WEBWAKEUPWDB_PLUGIN_FILE', __FILE__ );
define( 'WEBWAKEUPWDB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WEBWAKEUPWDB_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'WEBWAKEUPWDB_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'WEBWAKEUPWDB_SLUG', 'wwu-withdrawal-button' );
define( 'WEBWAKEUPWDB_TEXT_DOMAIN', 'wwu-withdrawal-button' );
define( 'WEBWAKEUPWDB_OPTION_PREFIX', 'webwakeupwdb_' );
define( 'WEBWAKEUPWDB_META_PREFIX', '_webwakeupwdb_' );
define( 'WEBWAKEUPWDB_REST_NAMESPACE', 'webwakeupwdb/v1' );
define( 'WEBWAKEUPWDB_NONCE_ACTION', 'webwakeupwdb_nonce' );

/**
 * Database schema version.
 *
 * Bump this integer whenever a new numbered migration is added under
 * src/Storage/Database/Migrations/. The Migrator runs every migration whose
 * number is greater than the stored webwakeupwdb_db_version option.
 */
define( 'WEBWAKEUPWDB_SCHEMA_VERSION', 3 );

/**
 * Legal application ("go-live") date for the EU/IT market.
 *
 * The obligation applies to distance contracts concluded on or after this date.
 * Exposed as a constant so the value is auditable in one place; the merchant can
 * still override it from the settings (webwakeupwdb_settings['go_live_date']).
 *
 * @see docs/legal/webwakeupwdb-legal-reference.md
 */
define( 'WEBWAKEUPWDB_GO_LIVE_DATE', '2026-06-19' );

/*
 * ---------------------------------------------------------------------------
 * Autoloader (PSR-4, no Composer runtime dependency)
 * ---------------------------------------------------------------------------
 */
require_once WEBWAKEUPWDB_PATH . '/src/Autoloader.php';
\WebWakeUpWdb\WithdrawalButton\Autoloader::register();

/*
 * ---------------------------------------------------------------------------
 * Bundled vendor libraries (Dompdf — durable-medium PDF, LGPL-2.1).
 * Loaded lazily by the PdfBuilder, not at file-load, to keep cold boot cheap.
 * The PdfBuilder requires vendor/autoload.php on demand.
 * ---------------------------------------------------------------------------
 */

/*
 * ---------------------------------------------------------------------------
 * WooCommerce HPOS (High-Performance Order Storage) compatibility.
 * Declared unconditionally and early; harmless when WooCommerce is absent.
 * ---------------------------------------------------------------------------
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WEBWAKEUPWDB_PLUGIN_FILE,
				true
			);
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * Activation / deactivation / uninstall lifecycle.
 * ---------------------------------------------------------------------------
 */
register_activation_hook(
	__FILE__,
	static function ( $network_wide = false ) {
		\WebWakeUpWdb\WithdrawalButton\Core\Install::activate( (bool) $network_wide );
	}
);

register_deactivation_hook(
	__FILE__,
	static function ( $network_wide = false ) {
		\WebWakeUpWdb\WithdrawalButton\Core\Install::deactivate( (bool) $network_wide );
	}
);

/*
 * ---------------------------------------------------------------------------
 * Boot.
 * ---------------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	static function () {
		// Environment guard: deactivate gracefully if PHP/WP are too old.
		if ( version_compare( PHP_VERSION, WEBWAKEUPWDB_MIN_PHP, '<' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html(
						sprintf(
							/* translators: 1: required PHP version, 2: current PHP version. */
							__( 'WWU Withdrawal Button requires PHP %1$s or higher. You are running PHP %2$s.', 'wwu-withdrawal-button' ),
							WEBWAKEUPWDB_MIN_PHP,
							PHP_VERSION
						)
					);
					echo '</p></div>';
				}
			);
			return;
		}

		\WebWakeUpWdb\WithdrawalButton\Core\Plugin::instance()->boot();

			// One-time rewrite-rules flush after activation, once the My Account
			// endpoint has been registered on init. See Install::maybe_deferred_flush.
			add_action( 'wp_loaded', array( \WebWakeUpWdb\WithdrawalButton\Core\Install::class, 'maybe_deferred_flush' ) );
	},
	5
);
