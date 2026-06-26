<?php
/**
 * Static debug facade (Standard #11).
 *
 * Every call first checks the audience gate, so when debug is off the cost is a
 * single boolean check and the call is a no-op (zero production overhead). When
 * the audience is open, calls are forwarded to the Collector singleton.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Facade over Audience + Collector.
 */
final class Debug {

	/**
	 * Whether the debug audience is currently open.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return Audience::is_current_user();
	}

	/**
	 * Record a log-level entry.
	 *
	 * @param string $channel Channel.
	 * @param string $event   Event slug.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function log( string $channel, string $event, array $context = array() ): void {
		self::record( 'info', $channel, $event, $context );
	}

	/**
	 * Record an info-level entry.
	 *
	 * @param string $channel Channel.
	 * @param string $event   Event slug.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function info( string $channel, string $event, array $context = array() ): void {
		self::record( 'info', $channel, $event, $context );
	}

	/**
	 * Record a debug-level entry.
	 *
	 * @param string $channel Channel.
	 * @param string $event   Event slug.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function debug( string $channel, string $event, array $context = array() ): void {
		self::record( 'debug', $channel, $event, $context );
	}

	/**
	 * Record a warn-level entry.
	 *
	 * @param string $channel Channel.
	 * @param string $event   Event slug.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function warn( string $channel, string $event, array $context = array() ): void {
		self::record( 'warn', $channel, $event, $context );
	}

	/**
	 * Record an error-level entry.
	 *
	 * @param string $channel Channel.
	 * @param string $event   Event slug.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function error( string $channel, string $event, array $context = array() ): void {
		self::record( 'error', $channel, $event, $context );
	}

	/**
	 * Start a named timer (no-op when audience is closed).
	 *
	 * @param string $label Timer label.
	 * @return void
	 */
	public static function start_timer( string $label ): void {
		if ( self::is_active() ) {
			Collector::instance()->start_timer( $label );
		}
	}

	/**
	 * Stop a named timer (no-op when audience is closed).
	 *
	 * @param string $label   Timer label.
	 * @param string $channel Channel.
	 * @return void
	 */
	public static function end_timer( string $label, string $channel = 'timer' ): void {
		if ( self::is_active() ) {
			Collector::instance()->end_timer( $label, $channel );
		}
	}

	/**
	 * Snapshot accessor (Inspector / REST).
	 *
	 * @return array
	 */
	public static function snapshot(): array {
		return Collector::instance()->snapshot();
	}

	/**
	 * Internal: forward to the Collector if the audience is open.
	 *
	 * @param string $level   Level.
	 * @param string $channel Channel.
	 * @param string $event   Event.
	 * @param array  $context Context.
	 * @return void
	 */
	private static function record( string $level, string $channel, string $event, array $context ): void {
		if ( ! self::is_active() ) {
			return;
		}
		Collector::instance()->record( $level, $channel, $event, $context );
	}
}
