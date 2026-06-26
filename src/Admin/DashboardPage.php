<?php
/**
 * Onboarding dashboard — the friendly "is everything set up, how does it work,
 * and where does the button appear?" landing page.
 *
 * Written for non-technical merchants. It shows:
 *   - a setup checklist (status + one-click fixes) — including a real
 *     "email delivery" check with a Send-test-email button, because the most
 *     common "nothing happens" complaint is a missing SMTP transport, not the
 *     plugin itself;
 *   - a short "How it works" walkthrough of the consumer flow;
 *   - where the button appears, and the reasons it can legitimately be hidden.
 *
 * Page-creation guidance is shown ONLY when a form page is actually missing —
 * if everything is in place the page explains how the plugin works instead.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Admin;

use WWU\WithdrawalButton\Core\Install;
use WWU\WithdrawalButton\Core\Settings;
use WWU\WithdrawalButton\DurableMedium\PdfBuilder;
use WWU\WithdrawalButton\Mail\Mailer;
use WWU\WithdrawalButton\REST\Authentication;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard / onboarding page.
 */
final class DashboardPage {

	/**
	 * Nonce action for the "send test email" button.
	 *
	 * @var string
	 */
	private const TEST_EMAIL_NONCE = 'wwu_wb_test_email';

	/**
	 * Transient holding the throttle flag for the test-email button.
	 *
	 * @var string
	 */
	private const TEST_EMAIL_THROTTLE = 'wwu_wb_test_email_throttle';

	/**
	 * Transient holding the last test-email result for display.
	 *
	 * @var string
	 */
	private const TEST_EMAIL_RESULT = 'wwu_wb_test_email_result';

	/**
	 * Nonce action for the "recreate page" buttons (form / policy page).
	 *
	 * @var string
	 */
	public const RECREATE_PAGE_NONCE = 'wwu_wb_recreate_page';

	/**
	 * Render the dashboard.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			return;
		}

		$settings = Settings::main();
		$app      = (array) get_option( 'wwu_wb_applicability', array() );
		$enabled  = ! empty( $settings['enabled'] );
		$page_id  = (int) ( $settings['public_form_page_id'] ?? 0 );
		$page_ok  = $page_id > 0 && 'publish' === get_post_status( $page_id );
		$pdf_ok   = PdfBuilder::is_available();
		$woo      = class_exists( 'WooCommerce' );
		$fluent   = function_exists( 'fluent_cart_api' );
		$mode     = (string) ( $app['mode'] ?? 'eu_eea_only' );
		$mail     = $this->mail_transport();

		echo '<div class="wrap wwu-wb-wrap">';
		echo '<h1>' . esc_html__( 'WWU Withdrawal Button', 'wwu-withdrawal-button' ) . '</h1>';
		echo '<p>' . esc_html__( 'The EU online right-of-withdrawal function (Art. 11a / Art. 54-bis) for WooCommerce & FluentCart.', 'wwu-withdrawal-button' ) . '</p>';

		$this->maybe_render_test_email_result();
		$this->maybe_render_recreate_notice();
		$this->render_go_live( $settings );

		// --- Setup checklist ---
		echo '<h2>' . esc_html__( 'Setup checklist', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:880px;"><tbody>';

		$this->row(
			$enabled,
			__( 'Withdrawal function enabled', 'wwu-withdrawal-button' ),
			$enabled
				? __( 'The button is live for eligible consumers.', 'wwu-withdrawal-button' )
				: __( 'Turn it on in Settings to start showing the button.', 'wwu-withdrawal-button' ),
			$enabled ? '' : array( admin_url( 'admin.php?page=' . AdminController::SETTINGS_SLUG ), __( 'Enable now', 'wwu-withdrawal-button' ) )
		);

		$this->row(
			$woo || $fluent,
			__( 'E-commerce platform detected', 'wwu-withdrawal-button' ),
			( $woo ? 'WooCommerce ' : '' ) . ( $fluent ? 'FluentCart ' : '' ) . ( ( $woo || $fluent ) ? __( 'active.', 'wwu-withdrawal-button' ) : __( 'Activate WooCommerce or FluentCart.', 'wwu-withdrawal-button' ) )
		);

		// Form page: only frame it as "something to create" when it is missing.
		// When it exists we just confirm it and say what it is for.
		if ( $page_ok ) {
			$this->row(
				true,
				__( 'Withdrawal form page', 'wwu-withdrawal-button' ),
				__( 'Published — this is the public page guests (buyers without an account) use to start a withdrawal.', 'wwu-withdrawal-button' ),
				array( get_permalink( $page_id ), __( 'View page', 'wwu-withdrawal-button' ) )
			);
		} else {
			$recreate_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wwu_wb_recreate_page&which=form' ),
				self::RECREATE_PAGE_NONCE
			);
			$this->row(
				false,
				__( 'Withdrawal form page', 'wwu-withdrawal-button' ),
				__( 'No published form page found — it may have been deleted or trashed. Guests without an account need one; recreate it in one click:', 'wwu-withdrawal-button' ),
				array( $recreate_url, __( 'Recreate the page', 'wwu-withdrawal-button' ) )
			);
		}

		// Policy page (optional): a consolidated "Right of withdrawal" notice. Same
		// recreate action (which=policy), so a deleted/trashed one is one click to restore.
		$policy_id = (int) ( $settings['policy_page_id'] ?? 0 );
		$policy_ok = $policy_id > 0 && 'page' === get_post_type( $policy_id ) && 'trash' !== get_post_status( $policy_id );
		if ( $policy_ok ) {
			$edit_url  = get_edit_post_link( $policy_id, 'url' );
			$open_url  = ( is_string( $edit_url ) && '' !== $edit_url ) ? $edit_url : (string) get_permalink( $policy_id );
			$published = ( 'publish' === get_post_status( $policy_id ) );
			$this->row(
				true,
				__( 'Withdrawal policy page', 'wwu-withdrawal-button' ),
				$published
					? __( 'Published — a consolidated "Right of withdrawal" notice assembled from your settings and exemptions.', 'wwu-withdrawal-button' )
					: __( 'Created as a draft — review it and publish when ready.', 'wwu-withdrawal-button' ),
				array( $open_url, __( 'Open page', 'wwu-withdrawal-button' ) ),
				$published ? 'ok' : 'warn'
			);
		} else {
			$policy_act = wp_nonce_url( admin_url( 'admin-post.php?action=wwu_wb_recreate_page&which=policy' ), self::RECREATE_PAGE_NONCE );
			$this->row(
				false,
				__( 'Withdrawal policy page (optional)', 'wwu-withdrawal-button' ),
				__( 'A single consolidated "Right of withdrawal" notice (assembled from your settings + exemptions) you can link from your footer. Create it as a draft to review:', 'wwu-withdrawal-button' ),
				array( $policy_act, __( 'Create the policy page', 'wwu-withdrawal-button' ) ),
				'warn'
			);
		}

		// Email delivery — the row that actually answers "why didn't I get an email?".
		$mail_detail = '' !== $mail['name']
			? sprintf( /* translators: %s: SMTP plugin name. */ __( 'Sending through %s. Use the test below to confirm it reaches your inbox.', 'wwu-withdrawal-button' ), $mail['name'] )
			: __( 'No SMTP plugin detected. WordPress\'s built-in mail is often dropped or marked as spam by mail servers — the acknowledgement email may silently fail. Install an SMTP plugin (e.g. FluentSMTP, free) and send the test below.', 'wwu-withdrawal-button' );
		$this->row(
			$mail['active'],
			__( 'Email delivery', 'wwu-withdrawal-button' ),
			$mail_detail,
			null,
			$mail['active'] ? 'ok' : 'warn'
		);

		$this->row(
			$pdf_ok,
			__( 'PDF receipts (optional)', 'wwu-withdrawal-button' ),
			$pdf_ok
				? __( 'PDF library available — the receipt email also carries a PDF copy.', 'wwu-withdrawal-button' )
				: __( 'PDF library not found. The email receipt still works (it is the legal durable medium); to also attach a PDF, install the plugin from the official ZIP, which bundles the library.', 'wwu-withdrawal-button' ),
			null,
			$pdf_ok ? 'ok' : 'warn'
		);

		// Trusted timestamp ("data certa"): off by default (no external call without
		// opt-in), but strongly recommended — it is the independent legal proof of WHEN
		// a withdrawal was received. Surface it prominently here so the admin enables it.
		$ts_provider = (string) ( ( (array) get_option( 'wwu_wb_timestamp', array() ) )['provider'] ?? 'none' );
		$ts_on       = ( 'none' !== $ts_provider );
		$this->row(
			$ts_on,
			__( 'Trusted timestamp — legal "data certa" (recommended)', 'wwu-withdrawal-button' ),
			$ts_on
				? sprintf(
					/* translators: %s: provider key (opentimestamps / rfc3161). */
					__( 'Enabled (%s). Each confirmed withdrawal is anchored to an independent, tamper-proof timestamp — the legally decisive proof of WHEN it was received.', 'wwu-withdrawal-button' ),
					$ts_provider
				)
				: __( 'OFF — please turn this on. The log is already tamper-evident, but a trusted timestamp adds an independent "data certa": proof of the exact date and time a withdrawal was received, which is the fact the statutory 14-day deadline turns on. OpenTimestamps is free and sends only an anonymous one-way hash — never personal data.', 'wwu-withdrawal-button' ),
			$ts_on ? '' : array( admin_url( 'admin.php?page=' . AdminController::SETTINGS_SLUG ), __( 'Enable it now', 'wwu-withdrawal-button' ) ),
			$ts_on ? 'ok' : 'warn'
		);

		echo '</tbody></table>';

		$this->render_test_email_box( $mail );

		// Documents reminder — the button alone does not update the merchant's
		// own legal texts; surface this prominently on the landing page.
		$this->render_documents_reminder();

		// --- How it works ---
		echo '<h2>' . esc_html__( 'How it works', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<ol style="max-width:880px;line-height:1.7;">';
		echo '<li>' . esc_html__( 'An eligible buyer opens their order and clicks the statutory withdrawal button (its wording is fixed by law for each language).', 'wwu-withdrawal-button' ) . '</li>';
		echo '<li>' . esc_html__( 'A two-step form appears: they review what they are withdrawing from, then explicitly confirm. No dark patterns, no extra hurdles.', 'wwu-withdrawal-button' ) . '</li>';
		echo '<li>' . esc_html__( 'On confirmation the order is flagged "withdrawal requested", and the consumer immediately receives an acknowledgement of receipt by email (the legal durable medium) — plus a PDF and a permanent verifiable link when available.', 'wwu-withdrawal-button' ) . '</li>';
		echo '<li>' . esc_html__( 'Every step is written to a tamper-evident, append-only log so you can prove what happened and when. You then handle the refund as usual.', 'wwu-withdrawal-button' ) . '</li>';
		echo '</ol>';

		// --- Where does the button appear ---
		echo '<h2>' . esc_html__( 'Where the button appears', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:1.4em;max-width:880px;">';
		echo '<li>' . esc_html__( 'In the customer account, on each eligible order (order list + order detail) and in the "Right of withdrawal" account tab.', 'wwu-withdrawal-button' ) . '</li>';
		echo '<li>' . esc_html__( 'As a link inside the WooCommerce order emails, so guests without an account can reach it.', 'wwu-withdrawal-button' ) . '</li>';
		echo '<li>' . wp_kses_post( sprintf( /* translators: %s: shortcodes. */ __( 'Anywhere you place a shortcode: %s.', 'wwu-withdrawal-button' ), '<code>[wwu_wb_button]</code>, <code>[wwu_wb_form]</code>' ) ) . '</li>';
		echo '</ul>';

		// --- Why it might not appear ---
		echo '<h2>' . esc_html__( 'Why the button might not show on an order', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<p>' . esc_html__( 'The button only appears where a real withdrawal right exists. It is hidden when:', 'wwu-withdrawal-button' ) . '</p>';
		echo '<ul style="list-style:disc;margin-left:1.4em;max-width:880px;">';
		echo '<li>' . wp_kses_post(
			sprintf(
				/* translators: %s: current mode label. */
				__( 'The consumer is <strong>outside the scope of your applicability mode</strong> (currently: <strong>%s</strong>). For example, a Switzerland order is hidden in "EU/EEA only" mode — that is correct, Swiss law mandates no button.', 'wwu-withdrawal-button' ),
				esc_html( $this->mode_label( $mode ) )
			)
		) . '</li>';
		echo '<li>' . esc_html__( 'The order is not in a contract state (failed, cancelled, refunded, awaiting payment).', 'wwu-withdrawal-button' ) . '</li>';
		echo '<li>' . wp_kses_post( __( 'Every item is exempt under Art. 59 — either an <strong>unconditional</strong> reason (custom-made, perishable, dated services…), or one of the two <strong>conditional</strong> reasons (digital immediate access / service fully performed) <em>for which the consumer\'s consent was captured at checkout</em>. Note: <strong>physical products are never auto-hidden</strong> (they always keep the right), and a conditional item <strong>without</strong> captured consent also keeps the button — fail-safe toward the consumer.', 'wwu-withdrawal-button' ) ) . '</li>';
		echo '<li>' . esc_html__( 'It is a business (VAT) order, if you treat B2B as out of scope.', 'wwu-withdrawal-button' ) . '</li>';
		echo '<li>' . esc_html__( 'The function is disabled in Settings.', 'wwu-withdrawal-button' ) . '</li>';
		echo '</ul>';
		echo '<p>' . wp_kses_post(
			sprintf(
				/* translators: %s: settings URL. */
				__( 'Want it to show on <em>every</em> order regardless of country? Set the mode to "Always show it" in <a href="%s">Settings → Where the button applies</a>.', 'wwu-withdrawal-button' ),
				esc_url( admin_url( 'admin.php?page=' . AdminController::SETTINGS_SLUG ) )
			)
		) . '</p>';

		// --- Quick links ---
		echo '<h2>' . esc_html__( 'Next steps', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . AdminController::SETTINGS_SLUG ) ) . '">' . esc_html__( 'Settings', 'wwu-withdrawal-button' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . AdminController::COMPLIANCE_SLUG ) ) . '">' . esc_html__( 'Compliance & documents', 'wwu-withdrawal-button' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . AdminController::REQUESTS_SLUG ) ) . '">' . esc_html__( 'Withdrawal requests', 'wwu-withdrawal-button' ) . '</a>';
		echo '</p>';

		echo '<p style="margin-top:2em;color:#666;">' . wp_kses_post(
			sprintf(
				/* translators: %s: WebWakeUp link. */
				__( 'A free open-source compliance tool by %s, with mredodos and Matteo Alfieri (An Idea for Business).', 'wwu-withdrawal-button' ),
				'<a href="https://webwakeup.it" target="_blank" rel="noopener">WebWakeUp</a>'
			)
		) . '</p>';

		echo '</div>';
	}

	/**
	 * Render the "Send test email" box (form + last result).
	 *
	 * The button posts to admin-post.php; the handler sends a real email to the
	 * current admin only (never an arbitrary address), so this is a safe way for
	 * a non-technical merchant to prove whether outgoing mail works at all.
	 *
	 * @param array $mail Transport info from mail_transport().
	 * @return void
	 */
	private function render_test_email_box( array $mail ): void {
		$to = $this->test_recipient();

		echo '<div class="wwu-wb-clause" style="max-width:880px;margin:1em 0;padding:1em 1.2em;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7;">';
		echo '<p style="margin-top:0;"><strong>' . esc_html__( 'Test email delivery', 'wwu-withdrawal-button' ) . '</strong></p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: recipient email. */
				__( 'Sends a one-off test message to your address (%s). If it does not arrive (check spam too), outgoing email is not configured — install/configure an SMTP plugin. This does not depend on the PDF library.', 'wwu-withdrawal-button' ),
				$to
			)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wwu_wb_send_test_email" />';
		wp_nonce_field( self::TEST_EMAIL_NONCE );
		echo '<button type="submit" class="button">' . esc_html__( 'Send test email', 'wwu-withdrawal-button' ) . '</button>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Prominent reminder that installing the button is not enough: the merchant
	 * must also update the withdrawal article in their own Terms & Conditions and
	 * pre-contractual information so it describes the new "withdrawal button"
	 * modality. Art. 6 CRD requires informing the consumer how to withdraw; the
	 * ready-to-paste clauses live on the Compliance page.
	 *
	 * @return void
	 */
	private function render_documents_reminder(): void {
		$compliance = admin_url( 'admin.php?page=' . AdminController::COMPLIANCE_SLUG );

		echo '<div class="notice notice-warning inline" style="max-width:880px;margin:1.5em 0;padding:.4em 1.2em 1em;">';
		echo '<p style="margin-bottom:.4em;"><strong>' . esc_html__( 'Installing the button is not enough — update your legal texts too.', 'wwu-withdrawal-button' ) . '</strong></p>';
		echo '<p style="margin-top:0;">' . esc_html__( 'EU law requires your Terms & Conditions of sale and your pre-contractual information to describe how the consumer withdraws — and that now includes the new online "withdrawal button". Edit the withdrawal article in your own documents to mention it. The plugin generates ready-to-paste clauses, but it cannot change your published terms for you.', 'wwu-withdrawal-button' ) . '</p>';
		echo '<p style="margin-bottom:.4em;"><a class="button button-secondary" href="' . esc_url( $compliance ) . '">' . esc_html__( 'Get the ready-to-paste clauses', 'wwu-withdrawal-button' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Show the outcome of the last test-email send, if any (one-shot transient).
	 *
	 * @return void
	 */
	private function maybe_render_test_email_result(): void {
		if ( isset( $_GET['wwu_wb_test_throttled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Please wait a few seconds before sending another test email.', 'wwu-withdrawal-button' ) . '</p></div>';
		}

		$result = get_transient( self::TEST_EMAIL_RESULT );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( self::TEST_EMAIL_RESULT );

		$to = isset( $result['to'] ) ? (string) $result['to'] : '';
		if ( ! empty( $result['ok'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %s: recipient email. */
					__( 'Test email handed to WordPress for %s. If it does not arrive within a minute (check spam), your mail transport is dropping it — configure an SMTP plugin.', 'wwu-withdrawal-button' ),
					$to
				)
			) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %s: recipient email. */
					__( 'WordPress could not send the test email to %s (wp_mail returned an error). Outgoing email is not working on this site — install and configure an SMTP plugin (e.g. FluentSMTP).', 'wwu-withdrawal-button' ),
					$to
				)
			) . '</p></div>';
		}
	}

	/**
	 * Handle the "send test email" admin-post request.
	 *
	 * Security: capability-gated + nonce + short throttle; the recipient is ALWAYS
	 * the current user's own address (falling back to the site admin email), never
	 * a value taken from the request — so the button can never be abused to relay
	 * mail to arbitrary addresses. PRG redirect back to the dashboard.
	 *
	 * @return void
	 */
	public function handle_test_email(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wwu-withdrawal-button' ) );
		}
		check_admin_referer( self::TEST_EMAIL_NONCE );

		$redirect = admin_url( 'admin.php?page=' . AdminController::MENU_SLUG );

		// Rate limit: one test every 15 seconds, to avoid hammering the mailer.
		if ( get_transient( self::TEST_EMAIL_THROTTLE ) ) {
			wp_safe_redirect( add_query_arg( 'wwu_wb_test_throttled', '1', $redirect ) );
			exit;
		}
		set_transient( self::TEST_EMAIL_THROTTLE, 1, 15 );

		$to = $this->test_recipient();

		$subject = __( 'WWU Withdrawal Button — test email', 'wwu-withdrawal-button' );
		$body    = '<p>' . esc_html__( 'This is a test message from the WWU Withdrawal Button plugin.', 'wwu-withdrawal-button' ) . '</p>'
			. '<p>' . esc_html__( 'If you received it, outgoing email works and withdrawal acknowledgements will be delivered.', 'wwu-withdrawal-button' ) . '</p>';

		$ok = ( new Mailer() )->send_html( $to, $subject, $body );

		set_transient(
			self::TEST_EMAIL_RESULT,
			array(
				'ok' => $ok,
				'to' => $to,
			),
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * The fixed recipient for the test email (current admin, never request input).
	 *
	 * @return string
	 */
	private function test_recipient(): string {
		$user = wp_get_current_user();
		$to   = $user ? (string) $user->user_email : '';
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		return $to;
	}

	/**
	 * Detect a known SMTP/mail-integration plugin, or any phpmailer_init hook.
	 *
	 * @return array{name:string,active:bool} name is '' when unknown; active is
	 *               true when a known plugin OR a phpmailer_init listener is present.
	 */
	private function mail_transport(): array {
		$name = '';
		if ( defined( 'FLUENTMAIL_PLUGIN_VERSION' ) || function_exists( 'wpFluentMail' ) ) {
			$name = 'FluentSMTP';
		} elseif ( defined( 'WPMS_PLUGIN_VER' ) || function_exists( 'wp_mail_smtp' ) ) {
			$name = 'WP Mail SMTP';
		} elseif ( defined( 'POST_SMTP_VER' ) || class_exists( 'PostmanOptions' ) ) {
			$name = 'Post SMTP';
		} elseif ( defined( 'EasyWPSMTP_PLUGIN_VERSION' ) || defined( 'SWPSMTP_PLUGIN_VERSION' ) ) {
			$name = 'Easy WP SMTP';
		} elseif ( defined( 'MAILSTER_VERSION' ) ) {
			$name = 'Mailster';
		}

		// A known plugin is a strong signal; otherwise, anything hooking
		// phpmailer_init usually means an SMTP integration is configured.
		$active = '' !== $name || ( false !== has_action( 'phpmailer_init' ) );

		return array(
			'name'   => $name,
			'active' => $active,
		);
	}

	/**
	 * Recreate an auto-managed plugin page (form or policy) on demand — for when a
	 * merchant deleted/trashed it by mistake, a one-click fix instead of a plugin
	 * re-activation. Capability + nonce gated; idempotent (no duplicate when the
	 * page still exists, via Install::ensure_*); PRG redirect with a result flag.
	 *
	 * @return void
	 */
	public function handle_recreate_page(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wwu-withdrawal-button' ) );
		}
		check_admin_referer( self::RECREATE_PAGE_NONCE );

		$which = isset( $_REQUEST['which'] ) ? sanitize_key( wp_unslash( $_REQUEST['which'] ) ) : 'form';
		if ( 'policy' === $which ) {
			$id       = Install::ensure_policy_page();
			$redirect = admin_url( 'admin.php?page=' . AdminController::COMPLIANCE_SLUG );
		} else {
			$id       = Install::ensure_form_page();
			$redirect = admin_url( 'admin.php?page=' . AdminController::MENU_SLUG );
		}

		wp_safe_redirect( add_query_arg( 'wwu_wb_page_recreated', $id > 0 ? $which : 'fail', $redirect ) );
		exit;
	}

	/**
	 * Render the success/failure notice after a "Recreate page" action.
	 *
	 * @return void
	 */
	private function maybe_render_recreate_notice(): void {
		// Display-only flag set by handle_recreate_page() after its nonce-checked PRG redirect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flag = isset( $_GET['wwu_wb_page_recreated'] ) ? sanitize_key( wp_unslash( $_GET['wwu_wb_page_recreated'] ) ) : '';
		if ( '' === $flag ) {
			return;
		}
		if ( 'fail' === $flag ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not recreate the page. Please try again, or create it manually with the relevant shortcode.', 'wwu-withdrawal-button' ) . '</p></div>';
			return;
		}
		$msg = ( 'policy' === $flag )
			? __( 'The withdrawal policy page has been recreated as a draft — review and publish it.', 'wwu-withdrawal-button' )
			: __( 'The withdrawal form page has been recreated and published.', 'wwu-withdrawal-button' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/**
	 * Render a checklist row.
	 *
	 * @param bool              $ok     Pass/fail.
	 * @param string            $label  Row label.
	 * @param string            $detail Detail text.
	 * @param array|string|null $action [url, text] action link, '' for none.
	 * @param string            $level  'ok'|'warn'|'err' (overrides the icon when $ok is false).
	 * @return void
	 */
	private function row( bool $ok, string $label, string $detail, $action = '', string $level = '' ): void {
		if ( $ok ) {
			$icon = '<span style="color:#008a20;font-weight:700;">&#10003;</span>';
		} elseif ( 'warn' === $level ) {
			$icon = '<span style="color:#996800;font-weight:700;">&#9888;</span>';
		} else {
			$icon = '<span style="color:#d63638;font-weight:700;">&#10007;</span>';
		}
		echo '<tr>';
		echo '<td style="width:28px;">' . $icon . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
		echo '<td style="width:220px;"><strong>' . esc_html( $label ) . '</strong></td>';
		echo '<td>' . esc_html( $detail );
		if ( is_array( $action ) && 2 === count( $action ) ) {
			echo ' &nbsp;<a href="' . esc_url( $action[0] ) . '">' . esc_html( $action[1] ) . '</a>';
		}
		echo '</td></tr>';
	}

	/**
	 * Render the go-live banner.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	private function render_go_live( array $settings ): void {
		$go_live = (string) ( $settings['go_live_date'] ?? WWU_WB_GO_LIVE_DATE );
		echo '<p><span class="wwu-wb-badge wwu-wb-badge--warn">' . esc_html(
			sprintf(
				/* translators: %s: date. */
				__( 'Mandatory for EU/EEA consumers on contracts concluded on or after %s.', 'wwu-withdrawal-button' ),
				$go_live
			)
		) . '</span></p>';
	}

	/**
	 * Human label for an applicability mode.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	private function mode_label( string $mode ): string {
		switch ( $mode ) {
			case 'always':
				return __( 'Always show it', 'wwu-withdrawal-button' );
			case 'custom_list':
				return __( 'Custom country list', 'wwu-withdrawal-button' );
			case 'eu_eea_only':
			default:
				return __( 'Only EU/EEA consumers', 'wwu-withdrawal-button' );
		}
	}
}
