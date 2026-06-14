<?php
/**
 * Debug Inspector page (Standard #11 / STANDARD-DEBUG-INSPECTOR).
 *
 * Renders the canonical inspector shell: a status bar, the smoke-test runner,
 * a live-entries table, counters, and a snapshot block. Behaviour lives in
 * assets/admin/inspector.js, which talks to the /debug/* REST endpoints.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Admin;

use WWU\WithdrawalButton\Debug\Audience;
use WWU\WithdrawalButton\REST\Authentication;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspector page renderer.
 */
final class InspectorPage {

	/**
	 * Render the Inspector page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			return;
		}

		echo '<div class="wrap wwu-wb-wrap wwu-wb-inspector">';
		echo '<h1>' . esc_html__( 'WWU Withdrawal Button — Debug Inspector', 'wwu-withdrawal-button' ) . '</h1>';

		if ( ! Audience::is_current_user() ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: settings page URL. */
					__( 'Debug is not enabled for your account. Enable it under <a href="%s">Settings → Debug</a>, then reload this page.', 'wwu-withdrawal-button' ),
					esc_url( admin_url( 'admin.php?page=' . AdminController::SETTINGS_SLUG ) )
				)
			) . '</p></div>';
			echo '</div>';
			return;
		}

		// Status bar.
		echo '<div class="wwu-wb-inspector__statusbar">';
		echo '<span data-role="poll-state">' . esc_html__( 'Polling: on', 'wwu-withdrawal-button' ) . '</span> · ';
		echo '<span data-role="entry-count">0</span> ' . esc_html__( 'entries', 'wwu-withdrawal-button' ) . ' · ';
		echo '<button type="button" class="button" data-action="toggle-poll">' . esc_html__( 'Pause', 'wwu-withdrawal-button' ) . '</button> ';
		echo '<button type="button" class="button" data-action="clear">' . esc_html__( 'Clear view', 'wwu-withdrawal-button' ) . '</button>';
		echo '</div>';

		// Smoke tests.
		echo '<h2>' . esc_html__( 'Smoke tests', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<p>';
		echo '<button type="button" class="button button-primary" data-action="run-suite" data-suite="all">' . esc_html__( 'Run ALL', 'wwu-withdrawal-button' ) . '</button> ';
		foreach ( \WWU\WithdrawalButton\Debug\SmokeTests::suite_names() as $suite ) {
			echo '<button type="button" class="button" data-action="run-suite" data-suite="' . esc_attr( $suite ) . '">' . esc_html( $suite ) . '</button> ';
		}
		echo '</p>';
		echo '<div data-role="test-report"></div>';

		// Live entries.
		echo '<h2>' . esc_html__( 'Live entries', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Level', 'wwu-withdrawal-button' ) . '</th>';
		echo '<th>' . esc_html__( 'Channel', 'wwu-withdrawal-button' ) . '</th>';
		echo '<th>' . esc_html__( 'Event', 'wwu-withdrawal-button' ) . '</th>';
		echo '<th>' . esc_html__( 'Context', 'wwu-withdrawal-button' ) . '</th>';
		echo '</tr></thead><tbody data-role="entries"></tbody></table>';

		// Snapshot.
		echo '<h2>' . esc_html__( 'Snapshot', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<p><button type="button" class="button" data-action="snapshot">' . esc_html__( 'Fetch snapshot', 'wwu-withdrawal-button' ) . '</button> ';
		echo '<button type="button" class="button" data-action="copy-snapshot">' . esc_html__( 'Copy', 'wwu-withdrawal-button' ) . '</button></p>';
		echo '<pre data-role="snapshot" class="wwu-wb-inspector__snapshot"></pre>';

		echo '</div>';
	}
}
