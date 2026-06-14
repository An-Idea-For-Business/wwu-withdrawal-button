<?php
/**
 * Withdrawal REST endpoints (the two-step flow + guest lookup).
 *
 *   POST /withdrawal/lookup    — guest order lookup (order_ref + email), rate-limited
 *   POST /withdrawal/statement — Step 1, returns a single-use confirmation token
 *   POST /withdrawal/confirm   — Step 2, fires the withdrawal
 *
 * Access is granted by: logged-in ownership, a WooCommerce order key, or a
 * lookup-minted guest token. Failures return identical generic errors so an
 * unauthenticated caller cannot enumerate orders.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\REST\Routes;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Domain\WithdrawalRequest;
use WWU\WithdrawalButton\Frontend\GuestAccess;
use WWU\WithdrawalButton\Platform\NormalizedOrder;
use WWU\WithdrawalButton\Platform\OrderDataSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Withdrawal flow routes.
 */
final class WithdrawalRoute extends AbstractRoute {

	/**
	 * Generic, enumeration-safe access error.
	 *
	 * @return \WP_Error
	 */
	private function access_denied(): \WP_Error {
		return $this->error( 'wwu_wb_access_denied', __( 'Invalid request.', 'wwu-withdrawal-button' ), 403 );
	}

	/**
	 * Register the routes. These are public endpoints (no capability) protected by
	 * per-request ownership/token checks; WP REST still validates the nonce for
	 * cookie-authenticated callers.
	 *
	 * @return void
	 */
	public function register(): void {
		$args = array( 'permission_callback' => '__return_true' );

		register_rest_route( WWU_WB_REST_NAMESPACE, '/withdrawal/lookup', array_merge( $args, array(
			'methods'  => 'POST',
			'callback' => array( $this, 'lookup' ),
		) ) );

		register_rest_route( WWU_WB_REST_NAMESPACE, '/withdrawal/statement', array_merge( $args, array(
			'methods'  => 'POST',
			'callback' => array( $this, 'statement' ),
		) ) );

		register_rest_route( WWU_WB_REST_NAMESPACE, '/withdrawal/confirm', array_merge( $args, array(
			'methods'  => 'POST',
			'callback' => array( $this, 'confirm' ),
		) ) );
	}

	/**
	 * Resolve the adapter + order for a request, or null.
	 *
	 * @param string $order_ref Order reference.
	 * @return array{0:OrderDataSource,1:NormalizedOrder}|null
	 */
	private function resolve( string $order_ref ): ?array {
		$adapter = Services::instance()->platforms->resolve_for_order( $order_ref );
		if ( ! $adapter ) {
			return null;
		}
		$order = $adapter->get_order( $order_ref );
		if ( ! $order ) {
			return null;
		}
		return array( $adapter, $order );
	}

	/**
	 * Whether the caller may act on this order.
	 *
	 * @param OrderDataSource  $adapter Adapter.
	 * @param NormalizedOrder  $order   Order.
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function has_access( OrderDataSource $adapter, NormalizedOrder $order, \WP_REST_Request $request ): bool {
		$user_id = get_current_user_id();
		if ( $user_id > 0 && $adapter->verify_owner( $order->order_ref, $user_id ) ) {
			return true;
		}
		$key = sanitize_text_field( (string) $request->get_param( 'key' ) );
		if ( '' !== $key && $adapter->verify_guest_key( $order->order_ref, $key ) ) {
			return true;
		}
		$access = sanitize_text_field( (string) $request->get_param( 'access_token' ) );
		$email  = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( '' !== $access && '' !== $email && GuestAccess::verify( $order->order_ref, $email, $access ) ) {
			return true;
		}
		return false;
	}

	/**
	 * POST /withdrawal/lookup — guest order lookup.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup( \WP_REST_Request $request ) {
		if ( ! GuestAccess::check_rate_limit() ) {
			return $this->error( 'wwu_wb_rate_limited', __( 'Too many attempts. Please try again in a few minutes.', 'wwu-withdrawal-button' ), 429 );
		}

		$order_ref = sanitize_text_field( (string) $request->get_param( 'order_ref' ) );
		$email     = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( '' === $order_ref || ! is_email( $email ) ) {
			return $this->access_denied();
		}

		$resolved = $this->resolve( $order_ref );
		// Constant-ish response: only succeed when the email matches the order.
		if ( ! $resolved || 0 !== strcasecmp( $resolved[1]->email, $email ) ) {
			return $this->access_denied();
		}

		$order = $resolved[1];
		return $this->success(
			array(
				'order_ref'    => $order->order_ref,
				'order_number' => $order->number,
				'name'         => '',
				'access_token' => GuestAccess::mint( $order->order_ref, $email ),
			)
		);
	}

	/**
	 * POST /withdrawal/statement — Step 1.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function statement( \WP_REST_Request $request ) {
		$order_ref = sanitize_text_field( (string) $request->get_param( 'order_ref' ) );
		$resolved  = $this->resolve( $order_ref );
		if ( ! $resolved || ! $this->has_access( $resolved[0], $resolved[1], $request ) ) {
			return $this->access_denied();
		}
		list( $adapter, $order ) = $resolved;

		$req = WithdrawalRequest::from_input(
			array(
				'name'      => $request->get_param( 'name' ),
				'order_ref' => $order->order_ref,
				'email'     => $request->get_param( 'email' ) ?: $order->email,
				'reason'    => $request->get_param( 'reason' ),
			)
		);
		if ( ! $req->is_valid() ) {
			return $this->error( 'wwu_wb_invalid_statement', __( 'Please provide your name and a valid email address.', 'wwu-withdrawal-button' ), 422 );
		}

		$result = Services::instance()->withdrawal->submit_statement( $adapter, $order, $req );
		return $this->success( $result );
	}

	/**
	 * POST /withdrawal/confirm — Step 2.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function confirm( \WP_REST_Request $request ) {
		$order_ref = sanitize_text_field( (string) $request->get_param( 'order_ref' ) );
		$resolved  = $this->resolve( $order_ref );
		if ( ! $resolved || ! $this->has_access( $resolved[0], $resolved[1], $request ) ) {
			return $this->access_denied();
		}
		list( $adapter, $order ) = $resolved;

		$request_uid = sanitize_text_field( (string) $request->get_param( 'request_uid' ) );
		$token       = sanitize_text_field( (string) $request->get_param( 'confirm_token' ) );
		if ( '' === $request_uid || '' === $token ) {
			return $this->error( 'wwu_wb_missing_token', __( 'Missing confirmation token. Please start again.', 'wwu-withdrawal-button' ), 400 );
		}

		$req    = WithdrawalRequest::from_input(
			array(
				'name'      => $request->get_param( 'name' ),
				'order_ref' => $order->order_ref,
				'email'     => $request->get_param( 'email' ) ?: $order->email,
				'reason'    => $request->get_param( 'reason' ),
			)
		);
		$result = Services::instance()->withdrawal->confirm( $adapter, $order, $req, $request_uid, $token );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->success( $result );
	}
}
