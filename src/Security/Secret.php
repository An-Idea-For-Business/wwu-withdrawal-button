<?php
/**
 * Central accessor for the plugin's HMAC secret.
 *
 * Every token gate in the plugin — guest access tokens (GuestAccess), receipt /
 * verify links (VerifiableLink) and the immutable log's genesis hash (LogChain) —
 * derives from the `webwakeupwdb_secret` option. If that option were ever empty (e.g.
 * deleted, a partial DB restore, or plugin files dropped in without running
 * activation), an HMAC computed over an empty key becomes attacker-reproducible
 * and tokens could be forged.
 *
 * get() therefore NEVER returns an empty string: if the secret is missing it is
 * minted on first use (a strong random value) and stored, so no HMAC is ever
 * computed over a known/empty key. A consequence in that (theoretical) recovery
 * case is that links/log rows issued under a previously-lost secret will no
 * longer verify — which is the correct, fail-secure outcome (they can no longer
 * be trusted) rather than silently accepting forgeries.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HMAC secret provider.
 */
final class Secret {

	/**
	 * Option name holding the secret (autoload=no).
	 *
	 * @var string
	 */
	public const OPTION = 'webwakeupwdb_secret';

	/**
	 * Return the HMAC secret, minting and persisting one if absent.
	 *
	 * Guaranteed non-empty.
	 *
	 * @return string
	 */
	public static function get(): string {
		$secret = (string) get_option( self::OPTION, '' );
		if ( '' !== $secret ) {
			return $secret;
		}

		// Fail-safe mint: the secret must never be empty.
		$minted = self::mint();
		if ( add_option( self::OPTION, $minted, '', 'no' ) ) {
			return $minted;
		}

		// Lost a race with a concurrent request that just created it: use the
		// stored value so every request agrees on the same key.
		$stored = (string) get_option( self::OPTION, '' );
		return '' !== $stored ? $stored : $minted;
	}

	/**
	 * Generate a strong random secret.
	 *
	 * @return string
	 */
	public static function mint(): string {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Exception $e ) {
			return wp_generate_password( 64, true, true );
		}
	}
}
