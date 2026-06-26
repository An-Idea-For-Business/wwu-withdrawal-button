<?php
/**
 * In-memory debug collector (Standard #11).
 *
 * A per-request singleton ring buffer of structured debug entries, plus counters
 * and timers. Context is sanitised and any key that looks like a secret is masked
 * before storage, so a snapshot can be copy-pasted into a support ticket safely.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton collector.
 */
final class Collector {

	/**
	 * Maximum number of retained entries (ring buffer).
	 *
	 * @var int
	 */
	private const MAX_ENTRIES = 500;

	/**
	 * Maximum recursion depth when sanitising context.
	 *
	 * @var int
	 */
	private const MAX_DEPTH = 6;

	/**
	 * Substrings that mark a value as secret and trigger masking.
	 *
	 * @var string[]
	 */
	private const SECRET_KEY_HINTS = array(
		'api_key',
		'apikey',
		'api-key',
		'api_secret',
		'apisecret',
		'token',
		'access_token',
		'refresh_token',
		'id_token',
		'jwt',
		'secret',
		'secret_key',
		'client_secret',
		'consumer_secret',
		'password',
		'passwd',
		'pwd',
		'passphrase',
		'pass_hash',
		'pass',
		'authorization',
		'bearer',
		'auth_header',
		'oauth',
		'credential',
		'cred',
		'private_key',
		'privatekey',
		'priv_key',
		'sessid',
		'session',
		'cookie',
		'csrf',
		'xsrf',
	);

	/**
	 * Singleton instance.
	 *
	 * @var Collector|null
	 */
	private static $instance = null;

	/**
	 * Collected entries.
	 *
	 * @var array<int,array>
	 */
	private $entries = array();

	/**
	 * Aggregate counters keyed by "{channel}:{event}".
	 *
	 * @var array<string,int>
	 */
	private $counters = array();

	/**
	 * Running timers keyed by label → start microtime.
	 *
	 * @var array<string,float>
	 */
	private $timers = array();

	/**
	 * Get the singleton.
	 *
	 * @return Collector
	 */
	public static function instance(): Collector {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Record a debug entry.
	 *
	 * @param string $level   silent|error|warn|info|debug.
	 * @param string $channel Free-form channel (e.g. 'withdrawal', 'log', 'rest').
	 * @param string $event   Short event slug.
	 * @param array  $context Arbitrary context (sanitised + secret-masked).
	 * @return void
	 */
	public function record( string $level, string $channel, string $event, array $context = array() ): void {
		$this->entries[] = array(
			'at'      => microtime( true ),
			'level'   => $level,
			'channel' => $channel,
			'event'   => $event,
			'context' => $this->sanitize_context( $context ),
		);

		if ( count( $this->entries ) > self::MAX_ENTRIES ) {
			array_shift( $this->entries );
		}

		$key                    = $channel . ':' . $event;
		$this->counters[ $key ] = ( $this->counters[ $key ] ?? 0 ) + 1;
	}

	/**
	 * Start a named timer.
	 *
	 * @param string $label Timer label.
	 * @return void
	 */
	public function start_timer( string $label ): void {
		$this->timers[ $label ] = microtime( true );
	}

	/**
	 * Stop a named timer and record the elapsed time.
	 *
	 * @param string $label   Timer label.
	 * @param string $channel Channel for the resulting entry.
	 * @return void
	 */
	public function end_timer( string $label, string $channel = 'timer' ): void {
		if ( ! isset( $this->timers[ $label ] ) ) {
			return;
		}
		$elapsed_ms = ( microtime( true ) - $this->timers[ $label ] ) * 1000;
		unset( $this->timers[ $label ] );
		$this->record( 'debug', $channel, $label, array( 'elapsed_ms' => round( $elapsed_ms, 2 ) ) );
	}

	/**
	 * Entries recorded after the given microtime cutoff (incremental polling).
	 *
	 * @param float $cutoff microtime(true) cutoff.
	 * @return array
	 */
	public function entries_since( float $cutoff ): array {
		return array_values(
			array_filter(
				$this->entries,
				static function ( array $entry ) use ( $cutoff ): bool {
					return $entry['at'] > $cutoff;
				}
			)
		);
	}

	/**
	 * A JSON-serialisable snapshot for the Inspector / support tickets.
	 *
	 * @return array
	 */
	public function snapshot(): array {
		return array(
			'entries'  => $this->entries,
			'counters' => $this->counters,
			'count'    => count( $this->entries ),
			'now'      => microtime( true ),
		);
	}

	/**
	 * Recursively sanitise context: bound depth, mask secret-looking keys,
	 * stringify scalars, and never store objects/resources raw.
	 *
	 * @param mixed $value Value to sanitise.
	 * @param int   $depth Current recursion depth.
	 * @return mixed
	 */
	private function sanitize_context( $value, int $depth = 0 ) {
		if ( $depth >= self::MAX_DEPTH ) {
			return '…';
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && $this->looks_like_secret( $key ) ) {
					$out[ $key ] = $this->mask( $item );
					continue;
				}
				$out[ $key ] = $this->sanitize_context( $item, $depth + 1 );
			}
			return $out;
		}

		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		// Objects/resources: store a type marker only.
		return '[' . gettype( $value ) . ']';
	}

	/**
	 * Whether a key name suggests a secret value.
	 *
	 * @param string $key Array key.
	 * @return bool
	 */
	private function looks_like_secret( string $key ): bool {
		$needle = strtolower( $key );
		foreach ( self::SECRET_KEY_HINTS as $hint ) {
			if ( false !== strpos( $needle, $hint ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mask a value to "••••••••••last4".
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function mask( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $value ) {
			return '';
		}
		return '••••••••••' . substr( $value, -4 );
	}
}
