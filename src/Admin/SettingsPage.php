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
		$settings['enabled']    = Sanitizer::bool( $_POST['enabled'] ?? '' );
		$settings['custom_css'] = Sanitizer::css( isset( $_POST['custom_css'] ) ? wp_unslash( $_POST['custom_css'] ) : '' );
		update_option( 'wwu_wb_settings', $settings );

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
