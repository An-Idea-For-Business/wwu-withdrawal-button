<?php
/**
 * Frontend asset enqueue for the withdrawal flow.
 *
 * Loads a small CSS+JS bundle ONLY where the flow can appear (My Account, or a
 * page containing a plugin shortcode) — zero overhead elsewhere. Every emitted
 * script tag carries a data-webwakeupwdb marker so Complianz (and similar consent
 * blockers) can whitelist this functional, consent-exempt flow (handled fully in
 * the Compat layer; the marker is added here at the source).
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Frontend;

use WebWakeUpWdb\WithdrawalButton\Core\Services;

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
		$this->do_enqueue();
	}

	/**
	 * Force the frontend bundle to load, bypassing the context gate.
	 *
	 * Used by surfaces the standard gate cannot detect — notably the FluentCart
	 * customer portal, whose page is a Vue SPA injected server-side. Safe to call
	 * more than once: WordPress de-duplicates by handle. Still respects the master
	 * "enabled" switch and the "a platform is active" precondition so a disabled
	 * plugin never ships assets.
	 *
	 * @return void
	 */
	public function ensure(): void {
		if ( ! \WebWakeUpWdb\WithdrawalButton\Core\Settings::enabled() ) {
			return;
		}
		if ( ! Services::instance()->platforms->has_active() ) {
			return;
		}
		if ( wp_style_is( 'webwakeupwdb-frontend', 'enqueued' ) ) {
			return;
		}
		$this->do_enqueue();
	}

	/**
	 * Register and enqueue the CSS+JS bundle and its localized data.
	 *
	 * @return void
	 */
	private function do_enqueue(): void {
		// Register the Complianz marker filter only on pages that actually load our
		// script, so it does not run on every script tag site-wide.
		add_filter( 'script_loader_tag', array( $this, 'mark_script_tag' ), 10, 2 );

		wp_enqueue_style(
			'webwakeupwdb-frontend',
			WEBWAKEUPWDB_URL . '/assets/frontend/withdrawal.css',
			array(),
			WEBWAKEUPWDB_VERSION
		);

		wp_enqueue_script(
			'webwakeupwdb-frontend',
			WEBWAKEUPWDB_URL . '/assets/frontend/withdrawal.js',
			array(),
			WEBWAKEUPWDB_VERSION,
			true
		);

		wp_localize_script(
			'webwakeupwdb-frontend',
			'webwakeupwdbData',
			array(
				'restUrl'   => esc_url_raw( rest_url( WEBWAKEUPWDB_REST_NAMESPACE . '/' ) ),
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
		if ( false === strpos( $handle, 'webwakeupwdb' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'data-webwakeupwdb' ) ) {
			return $tag;
		}
		return str_replace( '<script ', '<script data-webwakeupwdb="withdrawal-flow" ', $tag );
	}

	/**
	 * Whether the current request should load the frontend bundle.
	 *
	 * @return bool
	 */
	private function should_enqueue(): bool {
		if ( ! \WebWakeUpWdb\WithdrawalButton\Core\Settings::enabled() ) {
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
			if ( $post && ( has_shortcode( (string) $post->post_content, 'webwakeupwdb_form' ) || has_shortcode( (string) $post->post_content, 'webwakeupwdb_button' ) ) ) {
				return true;
			}
		}

		/**
		 * Force-enqueue the frontend bundle (e.g. for a page builder context).
		 *
		 * @param bool $enqueue Whether to enqueue.
		 */
		return (bool) apply_filters( 'webwakeupwdb_force_enqueue_frontend', false );
	}
}
