<?php
/**
 * Page-cache exclusions.
 *
 * The withdrawal form / receipt endpoints must never be served stale from a
 * full-page cache. We auto-exclude them for WP Rocket and LiteSpeed (which have
 * clean filters); W3TC and Cloudflare have no PHP-side filter, so the Compliance
 * page surfaces a manual-rule note for those.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache-exclusion integration.
 */
final class CacheExclusions {

	/**
	 * Wire filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rocket_cache_reject_uri', array( $this, 'add_paths' ) );
		add_filter( 'litespeed_excluded_uri', array( $this, 'add_paths' ) );
	}

	/**
	 * Add the withdrawal paths to a cache-exclusion list.
	 *
	 * @param array $uris Existing excluded URIs.
	 * @return array
	 */
	public function add_paths( $uris ): array {
		$uris = (array) $uris;

		// The public form page, if configured.
		$settings = (array) get_option( 'webwakeupwdb_settings', array() );
		$page_id  = (int) ( $settings['public_form_page_id'] ?? 0 );
		if ( $page_id > 0 ) {
			$path = wp_parse_url( (string) get_permalink( $page_id ), PHP_URL_PATH );
			if ( $path ) {
				$uris[] = $path;
			}
		}

		// The REST receipt/verify endpoints.
		$uris[] = '/wp-json/' . WEBWAKEUPWDB_REST_NAMESPACE . '/receipt/';
		$uris[] = '/wp-json/' . WEBWAKEUPWDB_REST_NAMESPACE . '/verify/';

		return array_values( array_unique( $uris ) );
	}
}
