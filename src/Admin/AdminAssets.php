<?php
/**
 * Admin asset enqueue.
 *
 * Loads the plugin admin stylesheet + the Inspector script only on the plugin's
 * own admin pages, and localises the REST base + nonce. The WWU UI Kit is
 * enqueued via its loader when present (copied into assets/ui-kit/ during build).
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin assets.
 */
final class AdminAssets {

	/**
	 * Wire enqueue hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue on plugin screens only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'webwakeupwdb' ) && false === strpos( $hook, 'wwu-withdrawal-button' ) ) {
			return;
		}

		$this->maybe_enqueue_ui_kit();

		wp_enqueue_style(
			'webwakeupwdb-admin',
			WEBWAKEUPWDB_URL . '/assets/admin/admin.css',
			array(),
			WEBWAKEUPWDB_VERSION
		);

		wp_enqueue_script(
			'webwakeupwdb-inspector',
			WEBWAKEUPWDB_URL . '/assets/admin/inspector.js',
			array(),
			WEBWAKEUPWDB_VERSION,
			true
		);

		wp_localize_script(
			'webwakeupwdb-inspector',
			'webwakeupwdbData',
			array(
				'restUrl'   => esc_url_raw( rest_url( WEBWAKEUPWDB_REST_NAMESPACE . '/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'pollOn'  => __( 'Polling: on', 'wwu-withdrawal-button' ),
					'pollOff' => __( 'Polling: off', 'wwu-withdrawal-button' ),
					'pause'   => __( 'Pause', 'wwu-withdrawal-button' ),
					'resume'  => __( 'Resume', 'wwu-withdrawal-button' ),
					'copied'  => __( 'Copied.', 'wwu-withdrawal-button' ),
					'running' => __( 'Running…', 'wwu-withdrawal-button' ),
				),
			)
		);
	}

	/**
	 * Enqueue the WWU UI Kit if its loader is bundled.
	 *
	 * @return void
	 */
	private function maybe_enqueue_ui_kit(): void {
		$loader = WEBWAKEUPWDB_PATH . '/assets/ui-kit/php/class-ui-kit-loader.php';
		if ( ! is_readable( $loader ) ) {
			return;
		}
		require_once $loader;
		if ( class_exists( '\\WebWakeUpWdb_UI_Kit_Loader' ) ) {
			// Only the components actually used by the admin UI. `.wwu-ui-notice`
			// lives in `utilities` (there is no standalone `notice` component); the
			// loader auto-pulls the `tokens` + `core` baseline. (The old list also
			// requested unused components — toast/ajax/form-field/switch/save-bar —
			// and an invalid `notice` id that the loader silently skipped.)
			\WebWakeUpWdb_UI_Kit_Loader::enqueue(
				'webwakeupwdb',
				array( 'accordion', 'badge', 'utilities' )
			);
		}
	}
}
