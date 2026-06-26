<?php
/**
 * Complianz compatibility.
 *
 * The withdrawal flow is contract-performance / legal-obligation processing
 * (GDPR Art. 6(1)(b)/(c)) and must work WITHOUT consent. Complianz's cookie
 * blocker scans the page output and could rewrite our functional script to
 * type="text/plain" if a substring coincidentally matches a known service. We
 * whitelist our marker attribute (data-webwakeupwdb=) so our scripts are never blocked,
 * and force them into the always-allowed "functional" category as a belt.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Complianz integration.
 */
final class Complianz {

	/**
	 * Wire filters early so they apply before Complianz builds its blocked list.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cmplz_whitelisted_script_tags', array( $this, 'whitelist' ) );
		add_filter( 'cmplz_service_category', array( $this, 'force_functional' ), 10, 3 );
	}

	/**
	 * Add our marker to the Complianz whitelist (substring match).
	 *
	 * @param array $tags Whitelisted substrings.
	 * @return array
	 */
	public function whitelist( $tags ): array {
		$tags   = (array) $tags;
		$tags[] = 'data-webwakeupwdb=';
		return $tags;
	}

	/**
	 * Force our scripts into the never-blocked "functional" category.
	 *
	 * @param string $class       The resolved category.
	 * @param string $total_match The full matched tag.
	 * @param bool   $found       Whether Complianz matched a known service.
	 * @return string
	 */
	public function force_functional( $class, $total_match, $found ): string {
		if ( is_string( $total_match ) && false !== strpos( $total_match, 'data-webwakeupwdb=' ) ) {
			return 'functional';
		}
		return (string) $class;
	}

	/**
	 * Bust the Complianz blocked-scripts transient (call on activation / settings save).
	 *
	 * @return void
	 */
	public static function bust_cache(): void {
		if ( function_exists( 'cmplz_delete_transient' ) ) {
			cmplz_delete_transient( 'cmplz_blocked_scripts' );
		}
	}
}
