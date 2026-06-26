<?php
/**
 * POST /webwakeupwdb/v1/debug/run-tests — smoke-test runner (wwu-tools contract).
 *
 * Returns the canonical shape:
 *   { summary: {pass, fail, skip, total}, suites: [{ name, tests: [{name,status,output}] }] }
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\REST\Routes;

use WebWakeUpWdb\WithdrawalButton\Debug\SmokeTests;
use WebWakeUpWdb\WithdrawalButton\REST\Authentication;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smoke-test route.
 */
final class DebugTestsRoute extends AbstractRoute {

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			WEBWAKEUPWDB_REST_NAMESPACE,
			'/debug/run-tests',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => Authentication::require_debug_audience(),
				'args'                => array(
					'suite' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => 'all',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Run the requested suite(s).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$suite  = (string) $request->get_param( 'suite' );
		$report = ( new SmokeTests() )->run( $suite );
		return $this->success( $report );
	}
}
