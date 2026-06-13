<?php
/**
 * Settings page.
 *
 * F0 surface: master enable toggle + debug audience configuration (so an admin
 * can turn debug on and use the /debug/* REST endpoints + Inspector). Later
 * phases extend this page with labels, applicability, exclusions, timestamp
 * provider and retention sections.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Admin;

use WWU\WithdrawalButton\Debug\Audience;
use WWU\WithdrawalButton\Frontend\Template;
use WWU\WithdrawalButton\Mail\WooAckEmail;
use WWU\WithdrawalButton\REST\Authentication;
use WWU\WithdrawalButton\Security\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page handler.
 */
final class SettingsPage {

	/**
	 * Nonce action for the settings form.
	 *
	 * @var string
	 */
	private const NONCE = 'wwu_wb_save_settings';

	/**
	 * Nonce action for the email-preview link.
	 *
	 * @var string
	 */
	private const PREVIEW_NONCE = 'wwu_wb_preview_email';

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			return;
		}

		$settings = wp_parse_args( (array) get_option( 'wwu_wb_settings', array() ), array( 'enabled' => false ) );
		$debug    = Audience::config();
		$saved    = isset( $_GET['wwu_wb_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap wwu-wb-wrap">';
		echo '<h1>' . esc_html__( 'WWU Withdrawal Button — Settings', 'wwu-withdrawal-button' ) . '</h1>';

		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wwu-withdrawal-button' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wwu_wb_save_settings" />';
		wp_nonce_field( self::NONCE );

		echo '<h2>' . esc_html__( 'General', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Enable withdrawal function', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $settings['enabled'] ), true, false ) . ' /> ';
		echo esc_html__( 'Show the withdrawal button to eligible consumers.', 'wwu-withdrawal-button' ) . '</label>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: go-live date. */
				__( 'Mandatory for EU/EEA consumers on contracts concluded on or after %s.', 'wwu-withdrawal-button' ),
				WWU_WB_GO_LIVE_DATE
			)
		) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';

		$this->render_applicability_section( $settings );
		$this->render_receipt_section( $settings );

		echo '<h2>' . esc_html__( 'Debug', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Enable debug', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<label><input type="checkbox" name="debug_enabled" value="1" ' . checked( ! empty( $debug['enabled'] ), true, false ) . ' /> ';
		echo esc_html__( 'Collect runtime diagnostics and expose the /debug REST endpoints + Inspector.', 'wwu-withdrawal-button' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Audience', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<select name="debug_mode">';
		$modes = array(
			Audience::MODE_ALL_ADMINS       => __( 'All admins', 'wwu-withdrawal-button' ),
			Audience::MODE_SPECIFIC_ROLES   => __( 'Specific roles', 'wwu-withdrawal-button' ),
			Audience::MODE_SPECIFIC_USERS   => __( 'Specific users', 'wwu-withdrawal-button' ),
			Audience::MODE_CURRENT_USER_ONLY => __( 'Current user only', 'wwu-withdrawal-button' ),
		);
		foreach ( $modes as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $debug['mode'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Console level', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<select name="debug_console_level">';
		foreach ( array( 'silent', 'error', 'warn', 'info', 'debug' ) as $level ) {
			echo '<option value="' . esc_attr( $level ) . '" ' . selected( $debug['console_level'], $level, false ) . '>' . esc_html( $level ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		echo '</tbody></table>';

		$this->render_appearance_section( $settings );

		submit_button( __( 'Save settings', 'wwu-withdrawal-button' ) );
		echo '</form>';

		echo '<p style="margin-top:2em;color:#666;">' . wp_kses_post(
			sprintf(
				/* translators: %s: WebWakeUp link. */
				__( 'WWU Withdrawal Button — a free open-source compliance tool by %s, with mredodos and Matteo Alfieri (An Idea for Business).', 'wwu-withdrawal-button' ),
				'<a href="https://webwakeup.it" target="_blank" rel="noopener">WebWakeUp</a>'
			)
		) . '</p>';

		echo '</div>';
	}

	/**
	 * Render the "Where it applies" section (applicability mode + B2B).
	 *
	 * @param array $settings Current settings (for context only).
	 * @return void
	 */
	private function render_applicability_section( array $settings ): void {
		$app  = wp_parse_args(
			(array) get_option( 'wwu_wb_applicability', array() ),
			array(
				'mode'                 => 'eu_eea_only',
				'custom_countries'     => array(),
				'b2b_vat_out_of_scope' => true,
			)
		);
		$mode = (string) $app['mode'];

		echo '<h2>' . esc_html__( 'Where the button applies', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Applicability', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<select name="applicability_mode">';
		$opts = array(
			'eu_eea_only' => __( 'Only EU/EEA consumers (legal minimum — recommended)', 'wwu-withdrawal-button' ),
			'always'      => __( 'Always show it (to every consumer, also non-EU)', 'wwu-withdrawal-button' ),
			'custom_list' => __( 'Only a custom list of countries', 'wwu-withdrawal-button' ),
		);
		foreach ( $opts as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $mode, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The button is mandatory only for EU/EEA consumers. "Always show it" is a safe superset if you prefer one consistent flow (showing it to non-EU buyers is allowed and low-risk).', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Custom countries', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<input type="text" name="applicability_custom" class="regular-text" value="' . esc_attr( implode( ', ', (array) $app['custom_countries'] ) ) . '" placeholder="IT, DE, FR" />';
		echo '<p class="description">' . esc_html__( 'Two-letter country codes, comma-separated. Used only with the "custom list" mode.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Business (B2B) orders', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<label><input type="checkbox" name="applicability_b2b" value="1" ' . checked( ! empty( $app['b2b_vat_out_of_scope'] ), true, false ) . ' /> ';
		echo esc_html__( 'Hide the button when a VAT number was provided (treat as business / out of scope).', 'wwu-withdrawal-button' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Render the "Receipt & evidence" section.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function render_receipt_section( array $settings ): void {
		$ts       = wp_parse_args( (array) get_option( 'wwu_wb_timestamp', array() ), array( 'provider' => 'opentimestamps' ) );
		$merchant = (string) ( $settings['merchant_email'] ?? get_option( 'admin_email' ) );
		$retention= (int) ( $settings['retention_years'] ?? 10 );
		$slug     = (string) ( $settings['endpoint_slug'] ?? 'wwu-withdrawal' );

		echo '<h2>' . esc_html__( 'Receipt & evidence', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Attach PDF receipt', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<label><input type="checkbox" name="send_pdf" value="1" ' . checked( ! empty( $settings['send_pdf'] ), true, false ) . ' /> ';
		echo esc_html__( 'Attach a PDF copy to the acknowledgement email (the email itself is always the durable medium).', 'wwu-withdrawal-button' ) . '</label>';
		if ( ! \WWU\WithdrawalButton\DurableMedium\PdfBuilder::is_available() ) {
			echo '<p class="description" style="color:#8a1f21;">' . esc_html__( 'PDF library not detected — PDF receipts are currently disabled (the email still works). Install the plugin from the official ZIP to enable PDFs.', 'wwu-withdrawal-button' ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Notification email', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<input type="email" name="merchant_email" class="regular-text" value="' . esc_attr( $merchant ) . '" />';
		echo '<p class="description">' . esc_html__( 'Where to notify you of new withdrawal requests.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Evidence retention (years)', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<input type="number" name="retention_years" min="1" max="30" value="' . esc_attr( (string) $retention ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'How long to keep the immutable log (default 10 — the contract limitation period).', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Trusted timestamp', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<select name="timestamp_provider">';
		$tsopts = array(
			'opentimestamps' => __( 'OpenTimestamps (free, Bitcoin-anchored — recommended)', 'wwu-withdrawal-button' ),
			'none'           => __( 'None (the hash chain alone is the evidence)', 'wwu-withdrawal-button' ),
		);
		foreach ( $tsopts as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $ts['provider'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'OpenTimestamps sends only a one-way hash to public calendar servers — never personal data.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'My Account tab slug', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<input type="text" name="endpoint_slug" class="regular-text" value="' . esc_attr( $slug ) . '" />';
		echo '<p class="description">' . esc_html__( 'The URL slug of the "Right of withdrawal" tab in the customer account. Change only if it conflicts.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		// Live preview of the acknowledgement email (opens in a new tab).
		$preview_url = wp_nonce_url( admin_url( 'admin-post.php?action=wwu_wb_preview_email' ), self::PREVIEW_NONCE );
		echo '<tr><th scope="row">' . esc_html__( 'Email preview', 'wwu-withdrawal-button' ) . '</th><td>';
		echo '<a class="button" href="' . esc_url( $preview_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Preview the acknowledgement email', 'wwu-withdrawal-button' ) . '</a>';
		echo '<p class="description">' . esc_html__( 'Opens a sample of the email the consumer receives. On WooCommerce it uses your store\'s email branding (header, footer, colours) exactly as it will be sent.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Render a styled preview of the acknowledgement email with sample data.
	 *
	 * Capability-gated + nonce-checked. Renders nothing real — it builds the email
	 * from representative sample data so the merchant can see how it looks. On
	 * WooCommerce it goes through the WC_Email style inliner (store branding);
	 * otherwise it renders the plain standalone template. Outputs a full HTML
	 * document in the browser and exits.
	 *
	 * @return void
	 */
	public function handle_preview_email(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wwu-withdrawal-button' ) );
		}
		check_admin_referer( self::PREVIEW_NONCE );

		$data = $this->sample_receipt_data();
		$html = '';

		// Prefer the branded WooCommerce email when WooCommerce is available.
		if ( class_exists( '\WC_Email' ) && function_exists( 'WC' ) && WC() && method_exists( WC(), 'mailer' ) ) {
			$emails = WC()->mailer()->get_emails();
			$key    = WooAckEmail::CLASS_KEY;
			if ( isset( $emails[ $key ] ) && method_exists( $emails[ $key ], 'preview' ) ) {
				$html = (string) $emails[ $key ]->preview( $data );
			}
		}

		// Fallback: the plain standalone template (FluentCart / no WooCommerce).
		if ( '' === $html ) {
			$html = Template::render( 'emails/withdrawal-confirmation.php', $data );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- email templates escape their own values; WC style_inline returns trusted markup.
		exit;
	}

	/**
	 * Representative sample data for the email preview (no real order needed).
	 *
	 * @return array
	 */
	private function sample_receipt_data(): array {
		$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );
		return array(
			'name'            => __( 'Sample Customer', 'wwu-withdrawal-button' ),
			'order_number'    => '1234',
			'items'           => __( 'Sample product × 1', 'wwu-withdrawal-button' ),
			'email'           => 'customer@example.com',
			'reason'          => '',
			'submitted_local' => wp_date( $format ),
			'submitted_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'row_hash'        => str_repeat( '0', 64 ),
			'trader'          => array(
				'name'    => (string) get_bloginfo( 'name' ),
				'address' => '',
				'email'   => (string) get_option( 'admin_email' ),
			),
			'download_url'    => '#',
			'verify_url'      => '#',
			'within_window'   => true,
			'site_name'       => (string) get_bloginfo( 'name' ),
		);
	}

	/**
	 * Render the "Appearance & custom CSS" section + the styling reference.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function render_appearance_section( array $settings ): void {
		$custom_css = (string) ( $settings['custom_css'] ?? '' );

		echo '<h2>' . esc_html__( 'Appearance — Custom CSS', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Paste CSS to restyle any element of the withdrawal flow on your site. It is loaded after the plugin styles, so it always wins. Tip: override the CSS variables below for quick color/shape changes, or target the classes for full control.', 'wwu-withdrawal-button' ) . '</p>';
		echo '<p class="description" style="color:#8a1f21;"><strong>' . esc_html__( 'Compliance note:', 'wwu-withdrawal-button' ) . '</strong> ' . esc_html__( 'the withdrawal button must stay legible and prominent (Art. 11a). Do not hide it, shrink it, or reduce its contrast.', 'wwu-withdrawal-button' ) . '</p>';

		echo '<p><textarea name="custom_css" rows="10" class="large-text code" spellcheck="false" placeholder=":root { --wwu-wb-accent: #f49619; --wwu-wb-radius: 8px; }">' . esc_textarea( $custom_css ) . '</textarea></p>';

		// --- Reference: CSS variables ---
		$vars = array(
			array( '--wwu-wb-accent', '#1a1f3a', __( 'Button background color', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-accent-text', '#ffffff', __( 'Button text color', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-accent-focus', '#1a1f3a', __( 'Button focus outline color', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-radius', '5px', __( 'Button corner radius', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-field-radius', '4px', __( 'Input/result corner radius', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-button-padding', '0.7em 1.4em', __( 'Button padding', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-button-font-size', '1rem', __( 'Button font size', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-button-font-weight', '600', __( 'Button font weight', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-button-border', '2px solid currentColor', __( 'Button border', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-form-max-width', '540px', __( 'Form max width', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-field-border', '#c3c4c7', __( 'Input border color', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-field-padding', '0.6em 0.7em', __( 'Input padding', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-label-weight', '600', __( 'Field label weight', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-note-color', '#666666', __( 'Helper/note text color', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-success-bg / -border / -text', '#edfaef / #46b450 / #1a4d24', __( 'Success message colors', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-error-bg / -border / -text', '#fcf0f1 / #d63638 / #8a1f21', __( 'Error message colors', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-notice-bg / -accent', '#f6f7f7 / #1a1f3a', __( 'Status-notice colors', 'wwu-withdrawal-button' ) ),
			array( '--wwu-wb-spacing', '1em', __( 'Vertical spacing rhythm', 'wwu-withdrawal-button' ) ),
		);

		// --- Reference: classes ---
		$classes = array(
			array( '.wwu-wb-button-wrap', __( 'Wrapper around the withdrawal button', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-button', __( 'Every button/CTA (withdraw, continue, confirm)', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-continue', __( 'The Step-1 "Continue" button', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-confirm', __( 'The statutory "Confirm withdrawal" button', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-window-note', __( 'The "X days left" note next to the button', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-form-wrap', __( 'The withdrawal form container', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-form-title', __( 'The form heading', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-field', __( 'Each field row (label + input)', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-actions', __( 'The actions row holding a button', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-step2 / .wwu-wb-step2-intro', __( 'The Step-2 confirmation block + its intro text', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-result.is-success / .is-error', __( 'The result message (success / error states)', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-status-notice', __( 'The "request status" notice on an order', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-notice', __( 'Generic inline notice', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-model-form (+ __fields)', __( 'The Annex I-B model form block', 'wwu-withdrawal-button' ) ),
			array( '.wwu-wb-info', __( 'The pre-contractual info block', 'wwu-withdrawal-button' ) ),
		);

		echo '<details class="wwu-wb-clause" style="margin-top:1em;"><summary><strong>' . esc_html__( 'CSS reference — variables & classes', 'wwu-withdrawal-button' ) . '</strong></summary>';

		echo '<h3>' . esc_html__( 'CSS variables (quick overrides)', 'wwu-withdrawal-button' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:860px;"><thead><tr><th>' . esc_html__( 'Variable', 'wwu-withdrawal-button' ) . '</th><th>' . esc_html__( 'Default', 'wwu-withdrawal-button' ) . '</th><th>' . esc_html__( 'Controls', 'wwu-withdrawal-button' ) . '</th></tr></thead><tbody>';
		foreach ( $vars as $v ) {
			echo '<tr><td><code>' . esc_html( $v[0] ) . '</code></td><td><code>' . esc_html( $v[1] ) . '</code></td><td>' . esc_html( $v[2] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Classes (full control)', 'wwu-withdrawal-button' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:860px;"><thead><tr><th>' . esc_html__( 'Class', 'wwu-withdrawal-button' ) . '</th><th>' . esc_html__( 'Targets', 'wwu-withdrawal-button' ) . '</th></tr></thead><tbody>';
		foreach ( $classes as $c ) {
			echo '<tr><td><code>' . esc_html( $c[0] ) . '</code></td><td>' . esc_html( $c[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Examples', 'wwu-withdrawal-button' ) . '</h3>';
		$examples = "/* Brand the button (quick way) */\n:root {\n    --wwu-wb-accent: #f49619;\n    --wwu-wb-accent-text: #ffffff;\n    --wwu-wb-radius: 8px;\n}\n\n/* Full control on the confirm button */\n.wwu-wb-confirm {\n    text-transform: uppercase;\n    letter-spacing: .5px;\n}\n\n/* Make the form wider */\n.wwu-wb-form-wrap { max-width: 680px; }";
		echo '<pre class="wwu-wb-inspector__snapshot" style="max-width:860px;">' . esc_html( $examples ) . '</pre>';

		echo '</details>';
	}

	/**
	 * Handle the settings POST (admin-post.php). PRG redirect on success.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wwu-withdrawal-button' ) );
		}
		check_admin_referer( self::NONCE );

		// General settings.
		$settings               = (array) get_option( 'wwu_wb_settings', array() );
		$old_slug               = sanitize_title( (string) ( $settings['endpoint_slug'] ?? 'wwu-withdrawal' ) );
		$settings['enabled']    = Sanitizer::bool( $_POST['enabled'] ?? '' );
		$settings['custom_css'] = Sanitizer::css( isset( $_POST['custom_css'] ) ? wp_unslash( $_POST['custom_css'] ) : '' );
		$settings['send_pdf']        = Sanitizer::bool( $_POST['send_pdf'] ?? '' );
		$settings['merchant_email']  = sanitize_email( (string) ( $_POST['merchant_email'] ?? '' ) );
		$settings['retention_years'] = max( 1, min( 30, (int) ( $_POST['retention_years'] ?? 10 ) ) );
		$new_slug                    = sanitize_title( (string) ( $_POST['endpoint_slug'] ?? 'wwu-withdrawal' ) );
		$settings['endpoint_slug']   = '' !== $new_slug ? $new_slug : 'wwu-withdrawal';
		update_option( 'wwu_wb_settings', $settings );

		// Applicability.
		$applicability = array(
			'mode'                 => Sanitizer::enum( $_POST['applicability_mode'] ?? '', array( 'eu_eea_only', 'always', 'custom_list' ), 'eu_eea_only' ),
			'custom_countries'     => Sanitizer::country_list( $_POST['applicability_custom'] ?? '' ),
			'b2b_vat_out_of_scope' => Sanitizer::bool( $_POST['applicability_b2b'] ?? '' ),
		);
		update_option( 'wwu_wb_applicability', $applicability );

		// Timestamp provider.
		$timestamp = (array) get_option( 'wwu_wb_timestamp', array() );
		$timestamp['provider'] = Sanitizer::enum( $_POST['timestamp_provider'] ?? '', array( 'opentimestamps', 'rfc3161', 'none' ), 'opentimestamps' );
		update_option( 'wwu_wb_timestamp', $timestamp );

		// If the account-tab slug changed, refresh rewrite rules so it works immediately.
		if ( $new_slug !== $old_slug ) {
			flush_rewrite_rules( false );
		}

		// Debug audience.
		$debug = Audience::config();
		$debug['enabled']       = Sanitizer::bool( $_POST['debug_enabled'] ?? '' );
		$debug['mode']          = Sanitizer::enum(
			$_POST['debug_mode'] ?? '',
			array(
				Audience::MODE_ALL_ADMINS,
				Audience::MODE_SPECIFIC_ROLES,
				Audience::MODE_SPECIFIC_USERS,
				Audience::MODE_CURRENT_USER_ONLY,
			),
			Audience::MODE_ALL_ADMINS
		);
		$debug['console_level'] = Sanitizer::enum(
			$_POST['debug_console_level'] ?? '',
			array( 'silent', 'error', 'warn', 'info', 'debug' ),
			'warn'
		);
		update_option( 'wwu_wb_debug', $debug );
		Audience::reset_cache();
		\WWU\WithdrawalButton\Core\Settings::flush();
		\WWU\WithdrawalButton\Compat\Complianz::bust_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => AdminController::SETTINGS_SLUG,
					'wwu_wb_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
