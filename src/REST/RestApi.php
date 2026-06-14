<?php
/**
 * REST API orchestrator: registers all plugin routes on rest_api_init.
 *
 * F0 wires the diagnostic /debug/* routes. Later phases append the withdrawal,
 * receipt and verify routes to build_routes().
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\REST;

use WWU\WithdrawalButton\REST\Routes\DebugRoute;
use WWU\WithdrawalButton\REST\Routes\DebugTestsRoute;
use WWU\WithdrawalButton\REST\Routes\WithdrawalRoute;
use WWU\WithdrawalButton\REST\Routes\ReceiptRoute;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Route registry.
 */
final class RestApi {

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		foreach ( $this->build_routes() as $route ) {
			$route->register();
		}
	}

	/**
	 * Build the list of route objects.
	 *
	 * @return \WWU\WithdrawalButton\REST\Routes\AbstractRoute[]
	 */
	private function build_routes(): array {
		return array(
			new DebugRoute(),
			new DebugTestsRoute(),
			new WithdrawalRoute(),
			new ReceiptRoute(),
		);
	}
}
