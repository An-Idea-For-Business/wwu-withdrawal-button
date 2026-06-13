<?php
/**
 * Frontend asset enqueue for the withdrawal flow.
 *
 * Loads a small CSS+JS bundle ONLY where the flow can appear (My Account, or a
 * page containing a plugin shortcode) — zero overhead elsewhere. Every emitted
 * script tag carries a data-wwu-wb marker so Complianz (and similar consent
 * blockers) can whitelist this functional, consent-exempt flow (handled fully in
 * the Compat layer; the marker is added here at the source).
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Frontend;

use WWU\WithdrawalButton\Core\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend assets.
 */
final class Assets {

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue on relevant contexts only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		// Register the Complianz marker filter only on pages that actually load our
		// script, so it does not run on every script tag site-wide.
		add_filter( 'script_loader_tag', array( $this, 'mark_script_tag' ), 10, 2 );

		wp_enqueue_style(
			'wwu-wb-frontend',
			WWU_WB_URL . '/assets/frontend/withdrawal.css',
			array(),
			WWU_WB_VERSION
		);

		// Merchant custom CSS — printed AFTER the plugin stylesheet so it overrides.
		$custom_css = (string) ( \WWU\WithdrawalButton\Core\Settings::main()['custom_css'] ?? '' );
		if ( '' !== $custom_css ) {
			wp_add_inline_style( 'wwu-wb-frontend', \WWU\WithdrawalButton\Security\Sanitizer::css( $custom_css ) );
		}

		wp_enqueue_script(
			'wwu-wb-frontend',
			WWU_WB_URL . '/assets/frontend/withdrawal.js',
			array(),
			WWU_WB_VERSION,
			true
		);

		wp_localize_script(
			'wwu-wb-frontend',
			'wwuWbData',
			array(
				'restUrl'   => esc_url_raw( rest_url( WWU_WB_REST_NAMESPACE . '/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'submitting'   => __( 'Submitting…', 'wwu-withdrawal-button' ),
					'confirming'   => __( 'Confirming…', 'wwu-withdrawal-button' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'wwu-withdrawal-button' ),
					'confirmed'    => __( 'Your withdrawal has been registered. We have emailed you a confirmation.', 'wwu-withdrawal-button' ),
					'step2Intro'   => __( 'Please confirm your withdrawal. This is the final step.', 'wwu-withdrawal-button' ),
					'lookupSubmit' => __( 'Find my order', 'wwu-withdrawal-button' ),
					'lookupFailed' => __( 'If those details match an eligible order, you can continue. Please check your order number and email and try again.', 'wwu-withdrawal-button' ),
				),
			)
		);
	}

	/**
	 * Add the Complianz-whitelist marker to our script tag.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle The script handle.
	 * @return string
	 */
	public function mark_script_tag( string $tag, string $handle ): string {
		if ( false === strpos( $handle, 'wwu-wb' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'data-wwu-wb' ) ) {
			return $tag;
		}
		return str_replace( '<script ', '<script data-wwu-wb="withdrawal-flow" ', $tag );
	}

	/**
	 * Whether the current request should load the frontend bundle.
	 *
	 * @return bool
	 */
	private function should_enqueue(): bool {
		if ( ! \WWU\WithdrawalButton\Core\Settings::enabled() ) {
			return false;
		}
		if ( ! Services::instance()->platforms->has_active() ) {
			return false;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		// Pages containing one of the plugin shortcodes.
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && ( has_shortcode( (string) $post->post_content, 'wwu_wb_form' ) || has_shortcode( (string) $post->post_content, 'wwu_wb_button' ) ) ) {
				return true;
			}
		}

		/**
		 * Force-enqueue the frontend bundle (e.g. for a page builder context).
		 *
		 * @param bool $enqueue Whether to enqueue.
		 */
		return (bool) apply_filters( 'wwu_wb_force_enqueue_frontend', false );
	}
}
