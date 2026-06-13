<?php
/**
 * Durable-medium receipt endpoints.
 *
 *   GET /receipt/{uid}?t=token — stream the stored PDF (the permanent link)
 *   GET /verify/{uid}?t=token  — JSON: hash, submission time, OTS + chain status
 *
 * Both are token-gated (HMAC) and rate-limited; unknown/invalid requests return
 * a generic 404/403 with no enumeration.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\REST\Routes;

use WWU\WithdrawalButton\DurableMedium\ReceiptStore;
use WWU\WithdrawalButton\DurableMedium\VerifiableLink;
use WWU\WithdrawalButton\Frontend\GuestAccess;
use WWU\WithdrawalButton\Storage\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receipt + verification routes.
 */
final class ReceiptRoute extends AbstractRoute {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register(): void {
		$args = array(
			'permission_callback' => '__return_true',
			'args'                => array(
				'uid' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				't'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
			),
		);

		register_rest_route( WWU_WB_REST_NAMESPACE, '/receipt/(?P<uid>[a-f0-9\-]{36})', array_merge( $args, array(
			'methods'  => 'GET',
			'callback' => array( $this, 'download' ),
		) ) );

		register_rest_route( WWU_WB_REST_NAMESPACE, '/verify/(?P<uid>[a-f0-9\-]{36})', array_merge( $args, array(
			'methods'  => 'GET',
			'callback' => array( $this, 'verify' ),
		) ) );
	}

	/**
	 * Stream the receipt PDF.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error|void
	 */
	public function download( \WP_REST_Request $request ) {
		if ( ! GuestAccess::check_rate_limit() ) {
			return $this->error( 'wwu_wb_rate_limited', __( 'Too many attempts.', 'wwu-withdrawal-button' ), 429 );
		}
		$uid   = (string) $request->get_param( 'uid' );
		$token = (string) $request->get_param( 't' );
		if ( ! VerifiableLink::verify( $uid, $token ) ) {
			return $this->error( 'wwu_wb_not_found', __( 'Not found.', 'wwu-withdrawal-button' ), 404 );
		}

		$store = new ReceiptStore();
		if ( ! $store->exists( $uid ) ) {
			return $this->error( 'wwu_wb_not_found', __( 'Receipt not available.', 'wwu-withdrawal-button' ), 404 );
		}

		$path = $store->path_for( $uid );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="withdrawal-receipt.pdf"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	/**
	 * Verification payload (hash + submission + integrity status).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verify( \WP_REST_Request $request ) {
		if ( ! GuestAccess::check_rate_limit() ) {
			return $this->error( 'wwu_wb_rate_limited', __( 'Too many attempts.', 'wwu-withdrawal-button' ), 429 );
		}
		$uid   = (string) $request->get_param( 'uid' );
		$token = (string) $request->get_param( 't' );
		if ( ! VerifiableLink::verify( $uid, $token ) ) {
			return $this->error( 'wwu_wb_not_found', __( 'Not found.', 'wwu-withdrawal-button' ), 404 );
		}

		$repo = new LogRepository();
		$row  = $repo->find( $uid, 'confirmed' );
		if ( ! $row ) {
			return $this->error( 'wwu_wb_not_found', __( 'Not found.', 'wwu-withdrawal-button' ), 404 );
		}

		$payload = (array) json_decode( (string) $row['payload_json'], true );
		return $this->success(
			array(
				'request_uid'   => $uid,
				'order_number'  => (string) ( $payload['order_number'] ?? '' ),
				'submitted_at'  => (string) ( $payload['submitted_at'] ?? $row['created_at'] ),
				'row_hash'      => (string) $row['row_hash'],
				// Cheap per-row integrity (O(1)); the full-chain scan is admin-only.
				'record_intact' => $repo->verify_row( $row ),
				'within_window' => (bool) ( $payload['within_window'] ?? true ),
			)
		);
	}
}
