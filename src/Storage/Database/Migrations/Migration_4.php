<?php
/**
 * Migration 4 — heal the auto-created pages' shortcode prefix (plugin 1.3.0).
 *
 * The 1.3.0 WordPress.org-compliance rename changed the shortcodes from
 * `[wwu_wb_*]` to `[webwakeupwdb_*]`. An install whose options/meta were already
 * adopted to the new prefix but whose auto-created form/policy pages still carry
 * the OLD shortcode (e.g. a site where the one-time adoption ran but its page step
 * did not complete) self-heals here.
 *
 * Uses a low-level `$wpdb->update` on `post_content` — NOT `wp_update_post()` —
 * because migrations run on `plugins_loaded` (including under WP-CLI), where the
 * `wp_insert_post()` revision cascade reads `WP_POST_REVISIONS` before it is
 * defined and fatals. The page body is plain text, so a direct UPDATE is safe.
 *
 * Idempotent: only rewrites a page whose content still contains the old prefix; a
 * fresh 1.3.0 install (already on the new shortcodes) is a no-op.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Storage\Database\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrite [wwu_wb_*] → [webwakeupwdb_*] inside the auto-created pages.
 */
final class Migration_4 {

	/**
	 * Apply the migration.
	 *
	 * @return void
	 */
	public static function up(): void {
		global $wpdb;

		$settings = (array) get_option( 'webwakeupwdb_settings', array() );
		foreach ( array( 'public_form_page_id', 'policy_page_id' ) as $key ) {
			$pid = isset( $settings[ $key ] ) ? (int) $settings[ $key ] : 0;
			if ( $pid <= 0 ) {
				continue;
			}
			$post = get_post( $pid );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$content = str_replace( '[wwu_wb_', '[webwakeupwdb_', (string) $post->post_content );
			if ( $content !== $post->post_content ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $pid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				clean_post_cache( $pid );
			}
		}
	}
}
