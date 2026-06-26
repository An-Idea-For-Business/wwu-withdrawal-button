<?php
/**
 * Plugin singleton — wires services and WordPress hooks.
 *
 * Boot order is intentionally lean: the foundation (i18n, schema self-heal,
 * multisite hooks, debug stack, REST debug endpoints, admin shell) is wired in
 * F0. Later phases add the platform adapters, frontend surfaces, durable-medium,
 * timestamping, compatibility and shortcodes by extending register_services().
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Core;

use WebWakeUpWdb\WithdrawalButton\Admin\AdminController;
use WebWakeUpWdb\WithdrawalButton\Compat\CacheExclusions;
use WebWakeUpWdb\WithdrawalButton\Compat\Complianz;
use WebWakeUpWdb\WithdrawalButton\Compat\ComplianzDocuments;
use WebWakeUpWdb\WithdrawalButton\DurableMedium\ConfirmationDispatcher;
use WebWakeUpWdb\WithdrawalButton\Frontend\Assets;
use WebWakeUpWdb\WithdrawalButton\Frontend\WooMyAccount;
use WebWakeUpWdb\WithdrawalButton\Platform\WooCommerce\OrderStatus;
use WebWakeUpWdb\WithdrawalButton\REST\RestApi;
use WebWakeUpWdb\WithdrawalButton\Shortcodes\Shortcodes;
use WebWakeUpWdb\WithdrawalButton\Timestamp\TimestampService;

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

		$services = \WebWakeUpWdb\WithdrawalButton\Core\Services::instance();

		// WooCommerce-specific surfaces (status + My Account) when WooCommerce is active.
		if ( null !== $services->platforms->get( 'woocommerce' ) ) {
			( new OrderStatus() )->register();
			( new WooMyAccount() )->register();
			( new \WebWakeUpWdb\WithdrawalButton\Mail\OrderEmailLink() )->register();

			// Register the acknowledgement as a first-class WC_Email so it appears
			// under WooCommerce → Emails (branding + customiser + theme override).
			// The callback instantiates WooAckEmail lazily, only when WooCommerce
			// fires the filter — by which point \WC_Email is guaranteed loaded, so
			// the class (which extends \WC_Email) is never autoloaded too early.
			add_filter(
				'woocommerce_email_classes',
				static function ( $emails ) {
					$emails[ \WebWakeUpWdb\WithdrawalButton\Mail\WooAckEmail::CLASS_KEY ] = new \WebWakeUpWdb\WithdrawalButton\Mail\WooAckEmail();
					return $emails;
				}
			);

			// Record reimbursements against a withdrawal in the evidence log.
			( new \WebWakeUpWdb\WithdrawalButton\Platform\WooRefundRecorder() )->register();

			// Capture the consumer's consent + acknowledgement at checkout for the two
			// conditional exemptions (digital immediate / service performed). Classic
			// checkout (shortcode) + block Checkout (Store API, Additional Checkout
			// Fields API) — mutually exclusive per order, sharing the same order meta.
			( new \WebWakeUpWdb\WithdrawalButton\Frontend\WooCheckoutConsent() )->register();
			( new \WebWakeUpWdb\WithdrawalButton\Frontend\WooBlockCheckoutConsent() )->register();
		}

		// FluentCart portal injection + checkout consent capture + the {{wwu.recesso_url}}
		// e-mail merge tag when FluentCart is active.
		if ( null !== $services->platforms->get( 'fluentcart' ) ) {
			( new \WebWakeUpWdb\WithdrawalButton\Frontend\FluentCartPortal() )->register();
			( new \WebWakeUpWdb\WithdrawalButton\Frontend\FluentCartCheckoutConsent() )->register();
			( new \WebWakeUpWdb\WithdrawalButton\Mail\FluentCartWithdrawalTag() )->register();
		}

		// EDD customer-facing surfaces (receipt + purchase-history button, e-mail link)
		// and checkout consent capture when Easy Digital Downloads is active.
		if ( null !== $services->platforms->get( 'edd' ) ) {
			( new \WebWakeUpWdb\WithdrawalButton\Frontend\EddCustomerOrders() )->register();
			( new \WebWakeUpWdb\WithdrawalButton\Frontend\EddCheckoutConsent() )->register();
		}

		// Feed captured exemption consent (any platform's order meta) back to the
		// evaluator so conditional exemptions hide the button only once consent exists.
		( new \WebWakeUpWdb\WithdrawalButton\Frontend\ConsentReader() )->register();

		// Daily retention purge: anonymise the IP on stored consents past the horizon.
		( new ConsentRetention() )->register();

		// Frontend assets (gated internally; the enqueue hook only fires on the front end).
		( new Assets() )->register();

		// No-JavaScript fallback flow (admin-post handlers).
		( new \WebWakeUpWdb\WithdrawalButton\Frontend\NoScriptFlow() )->register();

		// Durable-medium acknowledgement: listens on webwakeupwdb_withdrawal_confirmed.
		( new ConfirmationDispatcher() )->register();

		// Trusted timestamping (OpenTimestamps) of the immutable-log hash.
		( new TimestampService() )->register();

		// Outbound webhook for automations: async HMAC delivery on withdrawal
		// confirmed (no-op unless the merchant configured it under Integrations).
		( new \WebWakeUpWdb\WithdrawalButton\Api\WebhookDispatcher() )->register();

		// Shortcodes (button / form / status / model form / info).
		( new Shortcodes() )->register();

		// Gutenberg block (server-rendered wrapper over the form shortcode).
		( new \WebWakeUpWdb\WithdrawalButton\Frontend\Blocks() )->register();

		// Ecosystem compatibility (Complianz consent-block whitelist + opt-in document
		// injection + cache exclusions). All no-op unless their host plugin is active.
		( new Complianz() )->register();
		( new ComplianzDocuments() )->register();
		( new CacheExclusions() )->register();

		if ( is_admin() ) {
			$this->admin = new AdminController();
			$this->admin->register();
		}
	}

	/*
	 * Translations are loaded automatically by WordPress: the plugin's bundled .mo
	 * files (under the Domain Path "/languages") are loaded just-in-time since WP 4.6,
	 * and language packs from translate.wordpress.org once the plugin is hosted there.
	 * An explicit textdomain-loader call is therefore no longer needed (the plugin
	 * requires WP 5.8+).
	 */

	/**
	 * Accessor for the REST API service.
	 *
	 * @return RestApi|null
	 */
	public function rest_api(): ?RestApi {
		return $this->rest_api;
	}
}
