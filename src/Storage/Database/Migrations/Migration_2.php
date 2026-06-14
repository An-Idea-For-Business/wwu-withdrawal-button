<?php
/**
 * Migration 2 — flip the digital auto-exclusion default to OFF.
 *
 * The `wwu_wb_exclusions.auto_detect_virtual` flag was seeded ON, which auto-
 * excluded immediate-access digital content (Art. 59 lett. o / Art. 16(m)) from
 * the withdrawal button whenever the order was completed. That is legally over-
 * broad: the digital exemption only applies when prior express consent AND an
 * acknowledgment of losing the right were captured — which the auto-detect does
 * not verify — so it risks HIDING the button from consumers who still have the
 * right (under-compliance), and it contradicts the plugin's own admin guidance
 * ("do not simply hide the button without the legal conditions").
 *
 * There is no UI to change this flag, so any stored `true` is the old seed, never
 * a deliberate merchant choice — flipping it to false here overwrites no intent.
 * Merchants who genuinely sell only immediate-access digital goods can re-enable
 * it (future setting) or exclude specific products/categories.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Storage\Database\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalise the digital auto-exclusion default to OFF.
 */
final class Migration_2 {

	/**
	 * Apply the migration.
	 *
	 * @return void
	 */
	public static function up(): void {
		$exclusions = get_option( 'wwu_wb_exclusions' );
		if ( ! is_array( $exclusions ) ) {
			return;
		}
		if ( empty( $exclusions['auto_detect_virtual'] ) ) {
			return;
		}
		$exclusions['auto_detect_virtual'] = false;
		update_option( 'wwu_wb_exclusions', $exclusions );
	}
}
