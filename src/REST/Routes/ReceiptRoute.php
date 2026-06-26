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
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\REST\Routes;

use WebWakeUpWdb\WithdrawalButton\DurableMedium\ReceiptStore;
use WebWakeUpWdb\WithdrawalButton\DurableMedium\VerifiableLink;
use WebWakeUpWdb\WithdrawalButton\Frontend\GuestAccess;
use WebWakeUpWdb\WithdrawalButton\Storage\LogRepository;

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

		register_rest_route( WEBWAKEUPWDB_REST_NAMESPACE, '/receipt/(?P<uid>[a-f0-9\-]{36})', array_merge( $args, array(
			'methods'  => 'GET',
			'callback' => array( $this, 'download' ),
		) ) );

		register_rest_route( WEBWAKEUPWDB_REST_NAMESPACE, '/verify/(?P<uid>[a-f0-9\-]{36})', array_merge( $args, array(
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
			return $this->error( 'webwakeupwdb_rate_limited', __( 'Too many attempts.', 'wwu-withdrawal-button' ), 429 );
		}
		$uid   = (string) $request->get_param( 'uid' );
		$token = (string) $request->get_param( 't' );
		if ( ! VerifiableLink::verify( $uid, $token ) ) {
			return $this->error( 'webwakeupwdb_not_found', __( 'Not found.', 'wwu-withdrawal-button' ), 404 );
		}

		$store = new ReceiptStore();
		if ( ! $store->exists( $uid ) ) {
			return $this->error( 'webwakeupwdb_not_found', __( 'Receipt not available.', 'wwu-withdrawal-button' ), 404 );
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
			return $this->error( 'webwakeupwdb_rate_limited', __( 'Too many attempts.', 'wwu-withdrawal-button' ), 429 );
		}
		$uid   = (string) $request->get_param( 'uid' );
		$token = (string) $request->get_param( 't' );
		if ( ! VerifiableLink::verify( $uid, $token ) ) {
			return $this->error( 'webwakeupwdb_not_found', __( 'Not found.', 'wwu-withdrawal-button' ), 404 );
		}

		$repo = new LogRepository();
		$row  = $repo->find( $uid, 'confirmed' );
		if ( ! $row ) {
			return $this->error( 'webwakeupwdb_not_found', __( 'Not found.', 'wwu-withdrawal-button' ), 404 );
		}

		$payload = (array) json_decode( (string) $row['payload_json'], true );
		$result  = array(
			'request_uid'   => $uid,
			'order_number'  => (string) ( $payload['order_number'] ?? '' ),
			'submitted_at'  => (string) ( $payload['submitted_at'] ?? $row['created_at'] ),
			'row_hash'      => (string) $row['row_hash'],
			// Cheap per-row integrity (O(1)); the full-chain scan is admin-only.
			'record_intact' => $repo->verify_row( $row ),
			'within_window' => (bool) ( $payload['within_window'] ?? true ),
		);

		// Content negotiation: a person clicking the link in a browser gets a
		// readable verification page; API clients (and ?format=json) get the JSON.
		if ( $this->wants_html( $request ) ) {
			$this->render_html_verification( $result );
			exit;
		}

		return $this->success( $result );
	}

	/**
	 * Whether to serve the human-readable HTML verification page (vs JSON).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function wants_html( \WP_REST_Request $request ): bool {
		if ( 'json' === strtolower( (string) $request->get_param( 'format' ) ) ) {
			return false;
		}
		if ( 'html' === strtolower( (string) $request->get_param( 'format' ) ) ) {
			return true;
		}
		return false !== stripos( (string) $request->get_header( 'accept' ), 'text/html' );
	}

	/**
	 * Render the standalone HTML verification page and send it to the browser.
	 *
	 * @param array $result Verification result.
	 * @return void
	 */
	private function render_html_verification( array $result ): void {
		$format        = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );
		$submitted_ts  = strtotime( (string) $result['submitted_at'] );
		$submitted_hum = $submitted_ts ? wp_date( $format, $submitted_ts ) : (string) $result['submitted_at'];

		$html = \WebWakeUpWdb\WithdrawalButton\Frontend\Template::render(
			'public/verify-receipt.php',
			array(
				'order_number'    => (string) $result['order_number'],
				'submitted_human' => (string) $submitted_hum,
				'submitted_iso'   => (string) $result['submitted_at'],
				'row_hash'        => (string) $result['row_hash'],
				'intact'          => (bool) $result['record_intact'],
				'within_window'   => (bool) $result['within_window'],
				'site_name'       => (string) get_bloginfo( 'name' ),
				'verified_human'  => wp_date( $format ),
			)
		);

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
	}
}
