<?php
/**
 * REST API orchestrator: registers all plugin routes on rest_api_init.
 *
 * F0 wires the diagnostic /debug/* routes. Later phases append the withdrawal,
 * receipt and verify routes to build_routes().
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\REST;

use WebWakeUpWdb\WithdrawalButton\REST\Routes\ApiRoutes;
use WebWakeUpWdb\WithdrawalButton\REST\Routes\DebugRoute;
use WebWakeUpWdb\WithdrawalButton\REST\Routes\DebugTestsRoute;
use WebWakeUpWdb\WithdrawalButton\REST\Routes\WithdrawalRoute;
use WebWakeUpWdb\WithdrawalButton\REST\Routes\ReceiptRoute;

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
	 * @return \WebWakeUpWdb\WithdrawalButton\REST\Routes\AbstractRoute[]
	 */
	private function build_routes(): array {
		return array(
			new DebugRoute(),
			new DebugTestsRoute(),
			new WithdrawalRoute(),
			new ReceiptRoute(),
			new ApiRoutes(),
		);
	}
}
