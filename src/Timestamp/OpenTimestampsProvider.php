<?php
/**
 * OpenTimestamps provider (free, Bitcoin-anchored).
 *
 * No PHP OTS library exists, so we call the calendar-server HTTP API directly.
 * A 16-byte random nonce is appended before submission to prevent the calendar
 * from correlating identical digests (privacy best practice from the official
 * client). Anchoring is asynchronous (≈30 min–2 h, one Bitcoin block); the
 * upgrade poller completes the proof later.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Timestamp;

use WWU\WithdrawalButton\Debug\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenTimestamps HTTP provider.
 */
final class OpenTimestampsProvider implements TimestampProvider {

	/**
	 * Public calendar servers (free, no auth).
	 *
	 * @var string[]
	 */
	private const CALENDARS = array(
		'https://a.pool.opentimestamps.org',
		'https://b.pool.opentimestamps.org',
		'https://a.pool.eternitywall.com',
		'https://ots.btc.catallaxy.com',
	);

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'opentimestamps';
	}

	/**
	 * Resolve the configured calendar list (filterable).
	 *
	 * @return string[]
	 */
	private function calendars(): array {
		/**
		 * Filter the OpenTimestamps calendar servers.
		 *
		 * @param string[] $calendars Calendar base URLs.
		 */
		return (array) apply_filters( 'wwu_wb_ots_calendars', self::CALENDARS );
	}

	/**
	 * {@inheritDoc}
	 */
	public function stamp( string $sha256_hex ): ?array {
		$digest = @hex2bin( $sha256_hex ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $digest || 32 !== strlen( $digest ) ) {
			return null;
		}

		try {
			$nonce = random_bytes( 16 );
		} catch ( \Exception $e ) {
			$nonce = wp_generate_password( 16, true, true );
		}
		$commitment = hash( 'sha256', $digest . $nonce, true ); // 32 raw bytes.

		foreach ( $this->calendars() as $base ) {
			$url = trailingslashit( $base ) . 'digest';
			// SSRF guard: a filter (wwu_wb_ots_calendars) can repoint the calendar list,
			// so re-validate at request time and never follow redirects (parity with the
			// webhook + RFC 3161 callers).
			if ( ! \WWU\WithdrawalButton\Security\OutboundUrlGuard::is_safe_url( $url ) ) {
				continue;
			}
			$response = wp_remote_post(
				$url,
				array(
					'body'               => $commitment,
					'headers'            => array(
						'Accept'       => 'application/vnd.opentimestamps.v1',
						'Content-Type' => 'application/octet-stream',
					),
					'timeout'            => 10,
					'redirection'        => 0,
					'reject_unsafe_urls' => true,
				)
			);
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				return array(
					'nonce_hex'  => bin2hex( $nonce ),
					'proof_blob' => (string) wp_remote_retrieve_body( $response ),
					'pending'    => true,
				);
			}
		}

		Debug::warn( 'timestamp', 'ots.stamp_failed', array() );
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function upgrade( array $stamp ): ?array {
		$sha256_hex = (string) ( $stamp['sha256_hex'] ?? '' );
		$nonce_hex  = (string) ( $stamp['nonce_hex'] ?? '' );
		$digest     = @hex2bin( $sha256_hex ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$nonce      = @hex2bin( $nonce_hex );  // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $digest || false === $nonce ) {
			return null;
		}

		$commitment_hex = strtolower( bin2hex( hash( 'sha256', $digest . $nonce, true ) ) );

		foreach ( $this->calendars() as $base ) {
			$url = trailingslashit( $base ) . 'timestamp/' . $commitment_hex;
			if ( ! \WWU\WithdrawalButton\Security\OutboundUrlGuard::is_safe_url( $url ) ) {
				continue;
			}
			$response = wp_remote_get(
				$url,
				array(
					'headers'            => array( 'Accept' => 'application/vnd.opentimestamps.v1' ),
					'timeout'            => 10,
					'redirection'        => 0,
					'reject_unsafe_urls' => true,
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
				return array(
					'proof_blob'    => (string) wp_remote_retrieve_body( $response ),
					'bitcoin_block' => null, // Block-height parse requires the .ots binary parser (deferred).
				);
			}
			// 404 = still pending; try the next calendar / next cron tick.
		}

		return null;
	}
}
