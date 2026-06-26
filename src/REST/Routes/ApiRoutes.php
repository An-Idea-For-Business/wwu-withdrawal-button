<?php
/**
 * Public read-only automations API (namespace webwakeupwdb/v1).
 *
 *   GET /requests                                  — paginated confirmed requests (lean, no email/IP)
 *   GET /requests/{request_uid}                    — one request (email + products, never the IP)
 *   GET /orders/{platform}/{order_ref}/withdrawal  — per-order withdrawal status
 *
 * Auth model (SPEC): WordPress Application Passwords (Basic auth over HTTPS) →
 * WP resolves the user → permission_callback requires the WWU_WB admin capability.
 * The callbacks do NOT re-verify a nonce — Application-Password REST requests carry
 * none, and WP has already authenticated the user before permission_callback runs
 * (alpha-PWA trap #53). All endpoints are GET (no CSRF surface) and rate-limited.
 *
 * The raw consumer IP is NEVER exposed here; the row_hash is surfaced for external
 * integrity verification instead.
 *
 * @see \WebWakeUpWdb\WithdrawalButton\Api\RequestReader
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\REST\Routes;

use WebWakeUpWdb\WithdrawalButton\Api\RequestReader;
use WebWakeUpWdb\WithdrawalButton\REST\Authentication;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only automations routes.
 */
final class ApiRoutes extends AbstractRoute {

	/**
	 * Rate-limit bucket for the read API.
	 *
	 * @var string
	 */
	private const RL_BUCKET = 'read_api';

	/**
	 * Register the three read endpoints.
	 *
	 * @return void
	 */
	public function register(): void {
		$perm = Authentication::require_admin();

		register_rest_route(
			WEBWAKEUPWDB_REST_NAMESPACE,
			'/requests',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'list_requests' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 25,
						'sanitize_callback' => 'absint',
					),
					'platform' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'status'   => array(
						'type'              => 'string',
						'enum'              => RequestReader::STATUSES,
						'sanitize_callback' => 'sanitize_key',
					),
					'after'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'before'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			WEBWAKEUPWDB_REST_NAMESPACE,
			'/requests/(?P<request_uid>[A-Za-z0-9\-]{8,64})',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'get_request' ),
			)
		);

		register_rest_route(
			WEBWAKEUPWDB_REST_NAMESPACE,
			'/orders/(?P<platform>[a-z0-9_\-]{1,20})/(?P<order_ref>[A-Za-z0-9_.\-]{1,64})/withdrawal',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'order_withdrawal' ),
			)
		);
	}

	/**
	 * GET /requests — paginated list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_requests( \WP_REST_Request $request ) {
		$limited = $this->guard_rate();
		if ( $limited ) {
			return $limited;
		}

		$result = ( new RequestReader() )->list(
			array(
				'platform' => (string) $request->get_param( 'platform' ),
				'status'   => (string) $request->get_param( 'status' ),
				'after'    => (string) $request->get_param( 'after' ),
				'before'   => (string) $request->get_param( 'before' ),
			),
			(int) $request->get_param( 'page' ),
			(int) $request->get_param( 'per_page' )
		);

		$response = $this->success( $result['rows'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['pages'] );
		return $response;
	}

	/**
	 * GET /requests/{request_uid} — one request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_request( \WP_REST_Request $request ) {
		$limited = $this->guard_rate();
		if ( $limited ) {
			return $limited;
		}

		$uid    = sanitize_text_field( (string) $request->get_param( 'request_uid' ) );
		$detail = ( new RequestReader() )->detail( $uid );
		if ( null === $detail ) {
			return $this->error( 'webwakeupwdb_not_found', __( 'No confirmed withdrawal request with that id.', 'wwu-withdrawal-button' ), 404 );
		}
		return $this->success( $detail );
	}

	/**
	 * GET /orders/{platform}/{order_ref}/withdrawal — per-order status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function order_withdrawal( \WP_REST_Request $request ) {
		$limited = $this->guard_rate();
		if ( $limited ) {
			return $limited;
		}

		$platform  = sanitize_key( (string) $request->get_param( 'platform' ) );
		$order_ref = sanitize_text_field( (string) $request->get_param( 'order_ref' ) );

		$status = ( new RequestReader() )->order_status( $platform, $order_ref );
		if ( null === $status ) {
			return $this->error( 'webwakeupwdb_order_unknown', __( 'No order found for that platform/reference.', 'wwu-withdrawal-button' ), 404 );
		}
		return $this->success( $status );
	}

	/**
	 * Apply the read-API rate limit; returns a 429 error when exceeded, else null.
	 *
	 * @return \WP_Error|null
	 */
	private function guard_rate(): ?\WP_Error {
		if ( ! Authentication::enforce_rate_limit( self::RL_BUCKET, 120, 60 ) ) {
			return $this->error( 'webwakeupwdb_rate_limited', __( 'Too many requests. Please slow down.', 'wwu-withdrawal-button' ), 429 );
		}
		return null;
	}
}
