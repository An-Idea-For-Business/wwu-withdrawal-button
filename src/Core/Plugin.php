<?php
/**
 * Plugin singleton — wires services and WordPress hooks.
 *
 * Boot order is intentionally lean: the foundation (i18n, schema self-heal,
 * multisite hooks, debug stack, REST debug endpoints, admin shell) is wired in
 * F0. Later phases add the platform adapters, frontend surfaces, durable-medium,
 * timestamping, compatibility and shortcodes by extending register_services().
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Core;

use WWU\WithdrawalButton\Admin\AdminController;
use WWU\WithdrawalButton\REST\RestApi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin container.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * REST API orchestrator.
	 *
	 * @var RestApi|null
	 */
	private $rest_api = null;

	/**
	 * Admin controller.
	 *
	 * @var AdminController|null
	 */
	private $admin = null;

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Get (and lazily create) the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot the plugin. Idempotent.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// We are on plugins_loaded:5 — self-heal the schema for FTP/rsync upgrades.
		Migrator::maybe_upgrade();

		$this->register_hooks();
		$this->register_services();
	}

	/**
	 * Register cross-cutting WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Multisite lifecycle.
		add_action( 'wp_initialize_site', array( Install::class, 'provision_new_site' ), 20 );
		add_action( Install::CRON_COMPLETE_NETWORK, array( Install::class, 'complete_network_activation' ) );
	}

	/**
	 * Instantiate and wire the service objects.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$this->rest_api = new RestApi();
		$this->rest_api->register();

		if ( is_admin() ) {
			$this->admin = new AdminController();
			$this->admin->register();
		}
	}

	/**
	 * Load the plugin text domain for translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wwu-withdrawal-button',
			false,
			dirname( WWU_WB_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Accessor for the REST API service.
	 *
	 * @return RestApi|null
	 */
	public function rest_api(): ?RestApi {
		return $this->rest_api;
	}
}
