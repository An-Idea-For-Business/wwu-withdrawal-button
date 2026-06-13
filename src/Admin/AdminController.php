<?php
/**
 * Admin menu + page routing.
 *
 * F0 registers the top-level menu and three pages: Dashboard (placeholder until
 * F8), Settings (plugin enable + debug audience), and Debug Inspector. Later
 * phases add the Requests Dashboard and Compliance Status pages.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Admin;

use WWU\WithdrawalButton\REST\Authentication;
use WWU\WithdrawalButton\Core\Settings;
use WWU\WithdrawalButton\DurableMedium\PdfBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller.
 */
final class AdminController {

	public const MENU_SLUG       = 'wwu-withdrawal-button';
	public const REQUESTS_SLUG   = 'wwu-wb-requests';
	public const COMPLIANCE_SLUG = 'wwu-wb-compliance';
	public const SETTINGS_SLUG   = 'wwu-wb-settings';
	public const INSPECTOR_SLUG  = 'wwu-wb-inspector';

	/**
	 * Settings page handler.
	 *
	 * @var SettingsPage
	 */
	private $settings;

	/**
	 * Inspector page handler.
	 *
	 * @var InspectorPage
	 */
	private $inspector;

	/**
	 * Requests dashboard handler.
	 *
	 * @var RequestsDashboard
	 */
	private $requests;

	/**
	 * Compliance status page handler.
	 *
	 * @var ComplianceStatusPage
	 */
	private $compliance;

	/**
	 * Dashboard / onboarding page handler.
	 *
	 * @var DashboardPage
	 */
	private $dashboard;

	/**
	 * Asset loader.
	 *
	 * @var AdminAssets
	 */
	private $assets;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings   = new SettingsPage();
		$this->inspector  = new InspectorPage();
		$this->requests   = new RequestsDashboard();
		$this->compliance = new ComplianceStatusPage();
		$this->dashboard  = new DashboardPage();
		$this->assets     = new AdminAssets();
	}

	/**
	 * Wire admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_wwu_wb_save_settings', array( $this->settings, 'handle_save' ) );
		add_action( 'admin_post_wwu_wb_send_test_email', array( $this->dashboard, 'handle_test_email' ) );
		add_action( 'admin_notices', array( $this, 'maybe_mail_failure_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_pdf_missing_notice' ) );
		$this->assets->register();
	}

	/**
	 * On the plugin's own screens, warn (in plain language) when the PDF library
	 * is missing so the PDF copy of the receipt can't be attached. The email
	 * receipt still works, so this is informational, not an error.
	 *
	 * @return void
	 */
	public function maybe_pdf_missing_notice(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::MENU_SLUG ) ) {
			return;
		}
		if ( PdfBuilder::is_available() || empty( Settings::main()['enabled'] ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'WWU Withdrawal Button: the PDF library was not found, so the receipt is sent by email only (no PDF attachment). This is fine to go live, but to also attach a PDF copy, install the plugin using the official packaged ZIP (it bundles the library) instead of a plain source copy.', 'wwu-withdrawal-button' )
			. '</p></div>';
	}

	/**
	 * Show an admin notice if a durable-medium acknowledgement email failed to send.
	 *
	 * @return void
	 */
	public function maybe_mail_failure_notice(): void {
		if ( ! current_user_can( \WWU\WithdrawalButton\REST\Authentication::capability() ) ) {
			return;
		}
		$uid = get_transient( 'wwu_wb_mail_failed' );
		if ( ! $uid ) {
			return;
		}
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'WWU Withdrawal Button: a withdrawal acknowledgement email could not be sent. The consumer is legally entitled to receive it. Check your site\'s email/SMTP configuration and resend from the Requests page.', 'wwu-withdrawal-button' ) . '</p></div>';
	}

	/**
	 * Register the menu tree.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$capability = Authentication::capability();

		add_menu_page(
			__( 'Withdrawal Button', 'wwu-withdrawal-button' ),
			__( 'Withdrawal Button', 'wwu-withdrawal-button' ),
			$capability,
			self::MENU_SLUG,
			array( $this->dashboard, 'render' ),
			'dashicons-undo',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'wwu-withdrawal-button' ),
			__( 'Dashboard', 'wwu-withdrawal-button' ),
			$capability,
			self::MENU_SLUG,
			array( $this->dashboard, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Withdrawal requests', 'wwu-withdrawal-button' ),
			__( 'Requests', 'wwu-withdrawal-button' ),
			$capability,
			self::REQUESTS_SLUG,
			array( $this->requests, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Compliance', 'wwu-withdrawal-button' ),
			__( 'Compliance', 'wwu-withdrawal-button' ),
			$capability,
			self::COMPLIANCE_SLUG,
			array( $this->compliance, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'wwu-withdrawal-button' ),
			__( 'Settings', 'wwu-withdrawal-button' ),
			$capability,
			self::SETTINGS_SLUG,
			array( $this->settings, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Debug Inspector', 'wwu-withdrawal-button' ),
			__( 'Debug Inspector', 'wwu-withdrawal-button' ),
			$capability,
			self::INSPECTOR_SLUG,
			array( $this->inspector, 'render' )
		);
	}
}
