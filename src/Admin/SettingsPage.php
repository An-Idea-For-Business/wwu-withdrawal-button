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
		$settings            = (array) get_option( 'wwu_wb_settings', array() );
		$settings['enabled'] = Sanitizer::bool( $_POST['enabled'] ?? '' );
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
