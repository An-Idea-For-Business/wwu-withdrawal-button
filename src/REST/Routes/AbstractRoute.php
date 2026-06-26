<?php
/**
 * Base class for REST routes: shared registration + response helpers.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\REST\Routes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract REST route.
 */
abstract class AbstractRoute {

	/**
	 * Register the route(s) on the plugin namespace.
	 *
	 * @return void
	 */
	abstract public function register(): void;

	/**
	 * Build a success response envelope.
	 *
	 * @param mixed $data   Payload.
	 * @param int   $status HTTP status.
	 * @return \WP_REST_Response
	 */
	protected function success( $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			$status
		);
	}

	/**
	 * Build an error.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	protected function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
