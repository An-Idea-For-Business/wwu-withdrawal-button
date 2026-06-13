<?php
/**
 * Admin "Compliance" page.
 *
 * Shows the go-live countdown, the statutory labels in use, the document
 * checklist (with ready-to-paste clauses + the Annex I-B model-form shortcode),
 * and environment warnings (Complianz / cache / multilingual) the merchant
 * should address.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Admin;

use WWU\WithdrawalButton\Legal\ClauseLibrary;
use WWU\WithdrawalButton\REST\Authentication;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compliance status page.
 */
final class ComplianceStatusPage {

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			return;
		}

		$settings = (array) get_option( 'wwu_wb_settings', array() );
		$go_live  = (string) ( $settings['go_live_date'] ?? WWU_WB_GO_LIVE_DATE );

		echo '<div class="wrap wwu-wb-wrap">';
		echo '<h1>' . esc_html__( 'Compliance', 'wwu-withdrawal-button' ) . '</h1>';

		// Go-live.
		$this->render_go_live( $go_live );

		// Documents checklist + clauses.
		echo '<h2>' . esc_html__( 'Documents to update (requirement 6)', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<p>' . esc_html__( 'The withdrawal button is additional to the Annex I-B model form, which stays mandatory. Update these documents and place the model form in your pre-contractual information.', 'wwu-withdrawal-button' ) . '</p>';

		echo '<p><strong>' . esc_html__( 'Annex I-B model form shortcode:', 'wwu-withdrawal-button' ) . '</strong> <code>[wwu_wb_model_form lang="it"]</code></p>';
		echo '<p><strong>' . esc_html__( 'Pre-contractual info shortcode:', 'wwu-withdrawal-button' ) . '</strong> <code>[wwu_wb_info type="precontractual" lang="it"]</code></p>';

		$lang = strtolower( substr( determine_locale(), 0, 2 ) );
		foreach ( array( 'precontractual', 'terms', 'privacy' ) as $type ) {
			echo '<details class="wwu-wb-clause"><summary>' . esc_html( $this->clause_label( $type ) ) . '</summary>';
			echo '<textarea readonly rows="6" style="width:100%;">' . esc_textarea( ClauseLibrary::get( $type, $lang ) ) . '</textarea>';
			echo '</details>';
		}

		// Environment warnings.
		$this->render_warnings();

		echo '</div>';
	}

	/**
	 * Render the go-live countdown.
	 *
	 * @param string $go_live Go-live date (Y-m-d).
	 * @return void
	 */
	private function render_go_live( string $go_live ): void {
		echo '<h2>' . esc_html__( 'Legal go-live', 'wwu-withdrawal-button' ) . '</h2>';
		try {
			$target = new \DateTimeImmutable( $go_live, wp_timezone() );
			$now    = new \DateTimeImmutable( 'now', wp_timezone() );
			$days   = (int) $now->diff( $target )->format( '%r%a' );
			if ( $days > 0 ) {
				echo '<p class="wwu-wb-badge wwu-wb-badge--warn">' . esc_html(
					sprintf(
						/* translators: 1: date, 2: days. */
						__( 'The obligation applies from %1$s — %2$d days to go (for contracts concluded on/after that date).', 'wwu-withdrawal-button' ),
						$go_live,
						$days
					)
				) . '</p>';
			} else {
				echo '<p class="wwu-wb-badge wwu-wb-badge--ok">' . esc_html(
					sprintf(
						/* translators: %s: date. */
						__( 'The obligation has been in effect since %s.', 'wwu-withdrawal-button' ),
						$go_live
					)
				) . '</p>';
			}
		} catch ( \Exception $e ) {
			echo '<p>' . esc_html( $go_live ) . '</p>';
		}
	}

	/**
	 * Render environment warnings.
	 *
	 * @return void
	 */
	private function render_warnings(): void {
		$warnings = array();

		if ( defined( 'CMPLZ_VERSION' ) || function_exists( 'cmplz_get_value' ) ) {
			$warnings[] = __( 'Complianz is active. The withdrawal flow is functional (consent-exempt); the plugin marks its scripts so they are not blocked. Verify on the front end after first activation.', 'wwu-withdrawal-button' );
		}
		if ( defined( 'TRP_PLUGIN_VERSION' ) || class_exists( 'TRP_Translate_Press' ) ) {
			$warnings[] = __( 'TranslatePress is active. Statutory button labels are marked data-no-translation so they are not machine-translated; confirm the per-language wording is correct.', 'wwu-withdrawal-button' );
		}
		if ( defined( 'WP_ROCKET_VERSION' ) || defined( 'LSCWP_V' ) || defined( 'W3TC' ) ) {
			$warnings[] = __( 'A page-cache plugin is active. Exclude the My Account / withdrawal form pages from full-page cache so the button reflects the live state.', 'wwu-withdrawal-button' );
		}

		if ( empty( $warnings ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Environment notes', 'wwu-withdrawal-button' ) . '</h2><ul class="wwu-wb-warnings">';
		foreach ( $warnings as $w ) {
			echo '<li>' . esc_html( $w ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Human label for a clause type.
	 *
	 * @param string $type Clause type.
	 * @return string
	 */
	private function clause_label( string $type ): string {
		switch ( $type ) {
			case 'terms':
				return __( 'General terms clause', 'wwu-withdrawal-button' );
			case 'privacy':
				return __( 'Privacy policy clause (withdrawal log)', 'wwu-withdrawal-button' );
			case 'precontractual':
			default:
				return __( 'Pre-contractual information clause', 'wwu-withdrawal-button' );
		}
	}
}
