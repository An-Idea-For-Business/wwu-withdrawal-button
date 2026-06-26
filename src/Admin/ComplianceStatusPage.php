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

		$this->maybe_render_policy_notice();

		// Go-live.
		$this->render_go_live( $go_live );

		// Documents checklist + clauses.
		echo '<h2>' . esc_html__( 'Documents to update (requirement 6)', 'wwu-withdrawal-button' ) . '</h2>';

		// Lawyer's reminder (A. Vercellotti): the button does not replace updating
		// the withdrawal article in the merchant's own legal texts. Art. 6 CRD still
		// requires informing the consumer HOW to withdraw — which now includes the
		// online button. Make this impossible to miss before listing the clauses.
		echo '<div class="notice notice-warning inline" style="margin:.5em 0 1em;">';
		echo '<p style="margin-top:.6em;"><strong>' . esc_html__( 'Installing the button is not enough — update your legal texts too.', 'wwu-withdrawal-button' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'EU law requires your Terms & Conditions of sale and your pre-contractual information to describe how the consumer withdraws — and that now includes the new online "withdrawal button". Edit the withdrawal article in your own documents to mention it: copy the ready-to-paste clauses below. The plugin adds the button, but it cannot change your published terms for you.', 'wwu-withdrawal-button' ) . '</p>';
		echo '</div>';

		echo '<p>' . esc_html__( 'The withdrawal button is additional to the Annex I-B model form, which stays mandatory. Update these documents and place the model form in your pre-contractual information.', 'wwu-withdrawal-button' ) . '</p>';

		echo '<p><strong>' . esc_html__( 'Annex I-B model form shortcode:', 'wwu-withdrawal-button' ) . '</strong> <code>[wwu_wb_model_form lang="it"]</code></p>';
		echo '<p><strong>' . esc_html__( 'Pre-contractual info shortcode:', 'wwu-withdrawal-button' ) . '</strong> <code>[wwu_wb_info type="precontractual" lang="it"]</code></p>';

		// The clauses below are read-only previews of the text in use. The merchant
		// edits them (no code) in Settings -> Legal clauses; overrides flow back here
		// and into the [wwu_wb_info] shortcode automatically.
		echo '<p class="description">' . wp_kses_post(
			sprintf(
				/* translators: %s: Settings page URL. */
				__( 'The texts below are the ones in use. To replace them with your own wording (no code needed), edit them in <a href="%s">Settings &rarr; Legal clauses</a> — your version then appears here and wherever the <code>[wwu_wb_info]</code> shortcode is used.', 'wwu-withdrawal-button' ),
				esc_url( admin_url( 'admin.php?page=' . AdminController::SETTINGS_SLUG ) )
			)
		) . '</p>';

		$lang = strtolower( substr( determine_locale(), 0, 2 ) );
		foreach ( array( 'precontractual', 'terms', 'privacy', 'consent_privacy' ) as $type ) {
			// Open the two clauses the merchant must paste into their sale documents
			// (pre-contractual info + general terms) so they are not overlooked.
			$open  = in_array( $type, array( 'precontractual', 'terms' ), true );
			$badge = ClauseLibrary::has_override( $type, $lang )
				? ' <span class="wwu-wb-badge wwu-wb-badge--ok">' . esc_html__( 'customised', 'wwu-withdrawal-button' ) . '</span>'
				: '';
			echo '<details class="wwu-wb-clause"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( $this->clause_label( $type ) ) . wp_kses_post( $badge ) . '</summary>';
			echo '<textarea readonly rows="6" style="width:100%;">' . esc_textarea( ClauseLibrary::get( $type, $lang ) ) . '</textarea>';
			echo '</details>';
		}

		// Consolidated "Right of withdrawal" notice (assembled policy).
		$this->render_policy();

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
	 * The consolidated "Right of withdrawal" notice — a single document assembled
	 * live from the merchant's settings + selected exemptions. Offers: open/create
	 * the auto-updating policy page ([wwu_wb_policy]), freeze it to static HTML, and
	 * a collapsible live preview. The global "complements, not replaces" disclaimer
	 * comes from PolicyBuilder.
	 *
	 * @return void
	 */
	private function render_policy(): void {
		$settings  = (array) get_option( 'wwu_wb_settings', array() );
		$policy_id = (int) ( $settings['policy_page_id'] ?? 0 );
		$policy_ok = $policy_id > 0 && 'page' === get_post_type( $policy_id ) && 'trash' !== get_post_status( $policy_id );

		echo '<h2>' . esc_html__( 'Right of withdrawal — information notice', 'wwu-withdrawal-button' ) . '</h2>';
		echo '<p>' . esc_html__( 'A single consolidated notice, assembled live from your settings and the exemptions you selected. It complements — it does not replace — your Terms of Sale. Publish it on a page and link it from your footer, or freeze a static copy.', 'wwu-withdrawal-button' ) . '</p>';

		echo '<p>';
		if ( $policy_ok ) {
			$edit_url = get_edit_post_link( $policy_id, 'url' );
			$open_url = ( is_string( $edit_url ) && '' !== $edit_url ) ? $edit_url : (string) get_permalink( $policy_id );
			$status   = ( 'publish' === get_post_status( $policy_id ) )
				? esc_html__( 'Published', 'wwu-withdrawal-button' )
				: esc_html__( 'Draft', 'wwu-withdrawal-button' );
			echo '<a class="button" href="' . esc_url( $open_url ) . '">' . esc_html__( 'Open the policy page', 'wwu-withdrawal-button' ) . '</a> ';
			echo '<span class="wwu-wb-badge wwu-wb-badge--ok" style="margin-left:.4em;">' . esc_html( $status ) . '</span> ';
		} else {
			$create = wp_nonce_url( admin_url( 'admin-post.php?action=wwu_wb_recreate_page&which=policy' ), DashboardPage::RECREATE_PAGE_NONCE );
			echo '<a class="button button-primary" href="' . esc_url( $create ) . '">' . esc_html__( 'Create the policy page (draft)', 'wwu-withdrawal-button' ) . '</a> ';
		}
		$freeze = wp_nonce_url( admin_url( 'admin-post.php?action=wwu_wb_freeze_policy' ), DashboardPage::FREEZE_POLICY_NONCE );
		echo '<a class="button" href="' . esc_url( $freeze ) . '">' . esc_html__( 'Freeze to static HTML', 'wwu-withdrawal-button' ) . '</a>';
		echo '</p>';

		echo '<p class="description">'
			. esc_html__( 'Auto-updating shortcode:', 'wwu-withdrawal-button' ) . ' <code>[wwu_wb_policy]</code>. '
			. esc_html__( '“Freeze” snapshots the current text into the page as plain HTML so it stops changing; to return to the auto-updating version, edit the page and put the shortcode back.', 'wwu-withdrawal-button' )
			. '</p>';

		// Collapsible live preview of the assembled notice. Admin-only; the HTML is
		// builder-escaped, and wp_kses_post is a defensive second pass.
		$preview = '<div class="wwu-wb-policy-wrap">'
			. \WWU\WithdrawalButton\Legal\PolicyBuilder::disclaimer_html()
			. \WWU\WithdrawalButton\Legal\PolicyBuilder::build()->to_html()
			. '</div>';
		echo '<details class="wwu-wb-clause"><summary>' . esc_html__( 'Preview the assembled notice', 'wwu-withdrawal-button' ) . '</summary>';
		echo '<div class="wwu-wb-policy-preview" style="border:1px solid #c3c4c7;border-radius:4px;padding:1em 1.2em;margin-top:.6em;background:#fff;">';
		echo wp_kses_post( $preview );
		echo '</div></details>';
	}

	/**
	 * Success/failure notice after a policy page create/recreate or a freeze action
	 * (both redirect back to this page via PRG).
	 *
	 * @return void
	 */
	private function maybe_render_policy_notice(): void {
		// Display-only flags set by the nonce-checked PRG redirects in DashboardPage.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$recreated = isset( $_GET['wwu_wb_page_recreated'] ) ? sanitize_key( wp_unslash( $_GET['wwu_wb_page_recreated'] ) ) : '';
		$frozen    = isset( $_GET['wwu_wb_policy_frozen'] ) ? sanitize_key( wp_unslash( $_GET['wwu_wb_policy_frozen'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'policy' === $recreated ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The withdrawal policy page has been created as a draft — review and publish it.', 'wwu-withdrawal-button' ) . '</p></div>';
		} elseif ( 'fail' === $recreated ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not create the policy page. Please try again.', 'wwu-withdrawal-button' ) . '</p></div>';
		}

		if ( '1' === $frozen ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The policy was frozen into static HTML on the policy page. It will no longer update automatically until you put the shortcode back.', 'wwu-withdrawal-button' ) . '</p></div>';
		} elseif ( 'fail' === $frozen ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not freeze the policy. Please try again.', 'wwu-withdrawal-button' ) . '</p></div>';
		}
	}

	/**
	 * Render environment warnings.
	 *
	 * @return void
	 */
	private function render_warnings(): void {
		$warnings = array();

		$settings = (array) get_option( 'wwu_wb_settings', array() );
		$page_id  = (int) ( $settings['public_form_page_id'] ?? 0 );
		if ( ! empty( $settings['enabled'] ) && ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No published withdrawal form page is configured. Guests (and FluentCart customers) cannot withdraw. Create a page with the [wwu_wb_form] shortcode and set it in Settings.', 'wwu-withdrawal-button' ) . '</p></div>';
		}

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
			case 'consent_privacy':
				return __( 'Privacy policy clause (exemption-consent evidence)', 'wwu-withdrawal-button' );
			case 'precontractual':
			default:
				return __( 'Pre-contractual information clause', 'wwu-withdrawal-button' );
		}
	}
}
