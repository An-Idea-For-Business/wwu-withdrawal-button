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
use WWU\WithdrawalButton\Compat\CacheExclusions;
use WWU\WithdrawalButton\Compat\Complianz;
use WWU\WithdrawalButton\DurableMedium\ConfirmationDispatcher;
use WWU\WithdrawalButton\Frontend\Assets;
use WWU\WithdrawalButton\Frontend\WooMyAccount;
use WWU\WithdrawalButton\Platform\WooCommerce\OrderStatus;
use WWU\WithdrawalButton\REST\RestApi;
use WWU\WithdrawalButton\Shortcodes\Shortcodes;
use WWU\WithdrawalButton\Timestamp\TimestampService;

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

		$services = \WWU\WithdrawalButton\Core\Services::instance();

		// WooCommerce-specific surfaces (status + My Account) when WooCommerce is active.
		if ( null !== $services->platforms->get( 'woocommerce' ) ) {
			( new OrderStatus() )->register();
			( new WooMyAccount() )->register();
			( new \WWU\WithdrawalButton\Mail\OrderEmailLink() )->register();

			// Register the acknowledgement as a first-class WC_Email so it appears
			// under WooCommerce → Emails (branding + customiser + theme override).
			// The callback instantiates WooAckEmail lazily, only when WooCommerce
			// fires the filter — by which point \WC_Email is guaranteed loaded, so
			// the class (which extends \WC_Email) is never autoloaded too early.
			add_filter(
				'woocommerce_email_classes',
				static function ( $emails ) {
					$emails[ \WWU\WithdrawalButton\Mail\WooAckEmail::CLASS_KEY ] = new \WWU\WithdrawalButton\Mail\WooAckEmail();
					return $emails;
				}
			);

			// Record reimbursements against a withdrawal in the evidence log.
			( new \WWU\WithdrawalButton\Platform\WooRefundRecorder() )->register();
		}

		// FluentCart portal injection when FluentCart is active.
		if ( null !== $services->platforms->get( 'fluentcart' ) ) {
			( new \WWU\WithdrawalButton\Frontend\FluentCartPortal() )->register();
		}

		// Frontend assets (gated internally; the enqueue hook only fires on the front end).
		( new Assets() )->register();

		// No-JavaScript fallback flow (admin-post handlers).
		( new \WWU\WithdrawalButton\Frontend\NoScriptFlow() )->register();

		// Durable-medium acknowledgement: listens on wwu_wb_withdrawal_confirmed.
		( new ConfirmationDispatcher() )->register();

		// Trusted timestamping (OpenTimestamps) of the immutable-log hash.
		( new TimestampService() )->register();

		// Shortcodes (button / form / status / model form / info).
		( new Shortcodes() )->register();

		// Gutenberg block (server-rendered wrapper over the form shortcode).
		( new \WWU\WithdrawalButton\Frontend\Blocks() )->register();

		// Ecosystem compatibility (Complianz consent-block whitelist + cache exclusions).
		( new Complianz() )->register();
		( new CacheExclusions() )->register();

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
