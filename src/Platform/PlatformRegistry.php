<?php
/**
 * Registry of active platform adapters.
 *
 * Resolves which adapter owns a given order reference and exposes the list of
 * active platforms. Both WooCommerce and FluentCart can be active at once.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Platform registry.
 */
final class PlatformRegistry {

	/**
	 * All known adapters (active or not).
	 *
	 * @var OrderDataSource[]
	 */
	private $adapters;

	/**
	 * Constructor.
	 *
	 * @param OrderDataSource[] $adapters Adapter instances.
	 */
	public function __construct( array $adapters ) {
		$this->adapters = $adapters;
	}

	/**
	 * Build the default registry (WooCommerce + FluentCart).
	 *
	 * @return PlatformRegistry
	 */
	public static function create_default(): PlatformRegistry {
		$adapters = array( new WooCommerceAdapter() );
		if ( class_exists( '\\WebWakeUpWdb\\WithdrawalButton\\Platform\\FluentCartAdapter' ) ) {
			$adapters[] = new FluentCartAdapter();
		}
		if ( class_exists( '\\WebWakeUpWdb\\WithdrawalButton\\Platform\\EddAdapter' ) ) {
			$adapters[] = new EddAdapter();
		}
		/**
		 * Filter the registered platform adapters.
		 *
		 * @param OrderDataSource[] $adapters Adapter instances.
		 */
		$adapters = (array) apply_filters( 'webwakeupwdb_platform_adapters', $adapters );
		return new self( $adapters );
	}

	/**
	 * Active adapters only.
	 *
	 * @return OrderDataSource[]
	 */
	public function active(): array {
		return array_values(
			array_filter(
				$this->adapters,
				static function ( OrderDataSource $a ): bool {
					return $a->is_active();
				}
			)
		);
	}

	/**
	 * Whether any supported platform is active.
	 *
	 * @return bool
	 */
	public function has_active(): bool {
		return ! empty( $this->active() );
	}

	/**
	 * Get an adapter by key.
	 *
	 * @param string $key Adapter key.
	 * @return OrderDataSource|null
	 */
	public function get( string $key ): ?OrderDataSource {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->key() === $key && $adapter->is_active() ) {
				return $adapter;
			}
		}
		return null;
	}

	/**
	 * Resolve the adapter that can load a given order reference.
	 *
	 * @param string $order_ref Order reference.
	 * @return OrderDataSource|null
	 */
	public function resolve_for_order( string $order_ref ): ?OrderDataSource {
		foreach ( $this->active() as $adapter ) {
			if ( null !== $adapter->get_order( $order_ref ) ) {
				return $adapter;
			}
		}
		return null;
	}
}
