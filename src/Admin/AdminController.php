<?php
/**
 * Admin menu + page routing.
 *
 * F0 registers the top-level menu and three pages: Dashboard (placeholder until
 * F8), Settings (plugin enable + debug audience), and Debug Inspector. Later
 * phases add the Requests Dashboard and Compliance Status pages.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Admin;

use WebWakeUpWdb\WithdrawalButton\REST\Authentication;
use WebWakeUpWdb\WithdrawalButton\Core\Settings;
use WebWakeUpWdb\WithdrawalButton\DurableMedium\PdfBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller.
 */
final class AdminController {

	public const MENU_SLUG       = 'wwu-withdrawal-button';
	public const REQUESTS_SLUG   = 'webwakeupwdb-requests';
	public const CONSENT_SLUG    = 'webwakeupwdb-consents';
	public const COMPLIANCE_SLUG = 'webwakeupwdb-compliance';
	public const SETTINGS_SLUG   = 'webwakeupwdb-settings';
	public const INSPECTOR_SLUG  = 'webwakeupwdb-inspector';

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
	 * Consent-records page handler.
	 *
	 * @var ConsentRecordsPage
	 */
	private $consents;

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
		$this->consents   = new ConsentRecordsPage();
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
		add_action( 'admin_post_webwakeupwdb_save_settings', array( $this->settings, 'handle_save' ) );
		add_action( 'admin_post_webwakeupwdb_send_test_email', array( $this->dashboard, 'handle_test_email' ) );
		add_action( 'admin_post_webwakeupwdb_preview_email', array( $this->settings, 'handle_preview_email' ) );
		add_action( 'admin_post_webwakeupwdb_mark_processed', array( $this->requests, 'handle_mark_processed' ) );
		add_action( 'admin_post_webwakeupwdb_resend', array( $this->requests, 'handle_resend' ) );
		add_action( 'admin_post_webwakeupwdb_export_consents', array( $this->consents, 'handle_export' ) );
		add_action( 'admin_post_webwakeupwdb_recreate_page', array( $this->dashboard, 'handle_recreate_page' ) );
		add_action( 'admin_post_webwakeupwdb_freeze_policy', array( $this->dashboard, 'handle_freeze_policy' ) );
		add_action( 'admin_post_webwakeupwdb_policy_pdf', array( $this->dashboard, 'handle_policy_pdf' ) );
		add_action( 'admin_notices', array( $this, 'maybe_mail_failure_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_pdf_missing_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_timestamp_off_notice' ) );
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
	 * Action-required notice shown on every plugin screen while the plugin is
	 * live but trusted timestamping is OFF. The timestamp is the independent
	 * "data certa" of when a withdrawal was received — the fact the statutory
	 * 14-day deadline turns on — so we prompt the admin imperatively, with a
	 * direct link to the setting, until they enable a provider. Not dismissible:
	 * it self-resolves the moment a provider is chosen.
	 *
	 * @return void
	 */
	public function maybe_timestamp_off_notice(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::MENU_SLUG ) ) {
			return;
		}
		if ( empty( Settings::main()['enabled'] ) ) {
			return;
		}
		$provider = (string) ( ( (array) get_option( 'webwakeupwdb_timestamp', array() ) )['provider'] ?? 'none' );
		if ( 'none' !== $provider ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '#webwakeupwdb-timestamp' );
		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html__( 'WWU Withdrawal Button — action recommended: turn on the trusted timestamp.', 'wwu-withdrawal-button' )
			. '</strong> '
			. esc_html__( 'The plugin is live but trusted timestamping is OFF, so your withdrawal records have no independent "data certa" — the legally decisive proof of when each withdrawal was received (the fact the statutory 14-day deadline turns on). Turn it on now: it is free, and only an anonymous one-way hash ever leaves your site (never personal data).', 'wwu-withdrawal-button' )
			. '</p><p><a class="button button-primary" href="' . esc_url( $url ) . '">'
			. esc_html__( 'Enable trusted timestamping →', 'wwu-withdrawal-button' )
			. '</a></p></div>';
	}

	/**
	 * Show an admin notice if a durable-medium acknowledgement email failed to send.
	 *
	 * @return void
	 */
	public function maybe_mail_failure_notice(): void {
		if ( ! current_user_can( \WebWakeUpWdb\WithdrawalButton\REST\Authentication::capability() ) ) {
			return;
		}
		$failed = get_transient( 'webwakeupwdb_mail_failed' );
		if ( empty( $failed ) ) {
			return;
		}
		// The dispatcher stores array{uid, reason}; tolerate a bare uid string from an
		// acknowledgement that failed before this build (back-compat).
		$reason = is_array( $failed ) ? trim( (string) ( $failed['reason'] ?? '' ) ) : '';

		$message = __( 'WWU Withdrawal Button: a withdrawal acknowledgement email could not be sent. The consumer is legally entitled to receive it. Check your site\'s email/SMTP configuration and resend from the Requests page.', 'wwu-withdrawal-button' );
		if ( '' !== $reason ) {
			$message .= ' ' . sprintf(
				/* translators: %s: the specific error reported by the mail transport (e.g. an SMTP plugin). */
				__( 'Reported reason: %s', 'wwu-withdrawal-button' ),
				$reason
			);
		}
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
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
			__( 'Consent records', 'wwu-withdrawal-button' ),
			__( 'Consent records', 'wwu-withdrawal-button' ),
			$capability,
			self::CONSENT_SLUG,
			array( $this->consents, 'render' )
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
