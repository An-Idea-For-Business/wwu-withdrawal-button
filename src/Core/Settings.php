<?php
/**
 * Per-request settings cache.
 *
 * The withdrawal options are read repeatedly in hot paths (the My Account orders
 * list resolves several of them once per row). This caches each option array for
 * the duration of the request so those reads are a single array lookup instead of
 * a repeated get_option() + cast.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cached option accessor.
 */
final class Settings {

	/**
	 * Per-request cache keyed by option name.
	 *
	 * @var array<string,array>
	 */
	private static $cache = array();

	/**
	 * Get an option array (cached per request).
	 *
	 * @param string $option Option name.
	 * @return array
	 */
	public static function get( string $option ): array {
		if ( ! array_key_exists( $option, self::$cache ) ) {
			self::$cache[ $option ] = (array) get_option( $option, array() );
		}
		return self::$cache[ $option ];
	}

	/**
	 * Convenience: the main settings option.
	 *
	 * @return array
	 */
	public static function main(): array {
		return self::get( 'webwakeupwdb_settings' );
	}

	/**
	 * Whether the withdrawal function is enabled.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$main = self::main();
		return ! empty( $main['enabled'] );
	}

	/**
	 * Flush the cache (call after a settings save mid-request).
	 *
	 * @param string $option Optional single option to flush; empty = all.
	 * @return void
	 */
	public static function flush( string $option = '' ): void {
		if ( '' === $option ) {
			self::$cache = array();
			return;
		}
		unset( self::$cache[ $option ] );
	}
}
