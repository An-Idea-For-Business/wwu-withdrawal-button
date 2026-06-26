<?php
/**
 * Null timestamp provider (audit-only mode — the hash chain alone is the evidence).
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Timestamp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No-op provider.
 */
final class NoneProvider implements TimestampProvider {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'none';
	}

	/**
	 * {@inheritDoc}
	 */
	public function stamp( string $sha256_hex ): ?array {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function upgrade( array $stamp ): ?array {
		return null;
	}
}
