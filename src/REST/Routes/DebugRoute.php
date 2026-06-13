<?php
/**
 * GET /wwu-wb/v1/debug/snapshot — Collector snapshot for support tickets / AI agents.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\REST\Routes;

use WWU\WithdrawalButton\Debug\Debug;
use WWU\WithdrawalButton\Debug\Collector;
use WWU\WithdrawalButton\REST\Authentication;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug snapshot route.
 */
final class DebugRoute extends AbstractRoute {

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			WWU_WB_REST_NAMESPACE,
			'/debug/snapshot',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => Authentication::require_debug_audience(),
				'args'                => array(
					'since' => array(
						'type'              => 'number',
						'required'          => false,
						'sanitize_callback' => static function ( $value ) {
							return (float) $value;
						},
					),
				),
			)
		);
	}

	/**
	 * Return the full snapshot, or only entries since a cutoff.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$since = (float) $request->get_param( 'since' );

		if ( $since > 0 ) {
			return $this->success(
				array(
					'entries' => Collector::instance()->entries_since( $since ),
					'now'     => microtime( true ),
				)
			);
		}

		return $this->success( Debug::snapshot() );
	}
}
