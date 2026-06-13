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
		$this->assets->register();
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
			array( $this, 'render_dashboard' ),
			'dashicons-undo',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'wwu-withdrawal-button' ),
			__( 'Dashboard', 'wwu-withdrawal-button' ),
			$capability,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
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

	/**
	 * Render the dashboard placeholder (full dashboard ships in F8).
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		$go_live = WWU_WB_GO_LIVE_DATE;
		echo '<div class="wrap wwu-wb-wrap">';
		echo '<h1>' . esc_html__( 'WWU Withdrawal Button', 'wwu-withdrawal-button' ) . '</h1>';
		echo '<p>' . esc_html__( 'EU online right-of-withdrawal function (Art. 11a / Art. 54-bis) for WooCommerce & FluentCart.', 'wwu-withdrawal-button' ) . '</p>';
		echo '<p><strong>' . esc_html(
			sprintf(
				/* translators: %s: go-live date. */
				__( 'Legal go-live: %s — the obligation applies to contracts concluded on or after this date.', 'wwu-withdrawal-button' ),
				$go_live
			)
		) . '</strong></p>';
		echo '<p class="description">' . esc_html__( 'This is a foundation build (F0). The withdrawal flow, durable-medium receipt, immutable log and compliance documents are added in the following phases.', 'wwu-withdrawal-button' ) . '</p>';
		echo '<p style="margin-top:2em;color:#666;">' . wp_kses_post(
			sprintf(
				/* translators: %s: WebWakeUp link. */
				__( 'Made with care by %s.', 'wwu-withdrawal-button' ),
				'<a href="https://webwakeup.it" target="_blank" rel="noopener">WebWakeUp</a>'
			)
		) . '</p>';
		echo '</div>';
	}
}
