<?php
/**
 * Outbound webhook delivery on a confirmed withdrawal.
 *
 * Listens on `webwakeupwdb_withdrawal_confirmed` (priority 20, after the durable-medium
 * and timestamp side-effects) and schedules a single async event so the consumer
 * request is never blocked by the merchant's endpoint. Delivery rebuilds the
 * payload from the immutable log at send time (the uid is the only thing carried
 * across the schedule boundary — no serialised order object), re-checks the URL
 * through OutboundUrlGuard (send-time TOCTOU / DNS-rebinding defence), signs the
 * body with the shared secret, and POSTs with `redirection => 0` +
 * `reject_unsafe_urls => true`. One retry on a transport error; HTTP error codes
 * are reported but not retried (the receiver owns its own idempotency).
 *
 * @see \WebWakeUpWdb\WithdrawalButton\Api\Webhook
 * @see \WebWakeUpWdb\WithdrawalButton\Api\RequestReader
 * @see \WebWakeUpWdb\WithdrawalButton\Security\OutboundUrlGuard
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Api;

use WebWakeUpWdb\WithdrawalButton\Debug\Debug;
use WebWakeUpWdb\WithdrawalButton\Security\OutboundUrlGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook dispatcher.
 */
final class WebhookDispatcher {

	/**
	 * Max retry attempts after a transport (not HTTP-status) failure.
	 *
	 * @var int
	 */
	private const MAX_RETRIES = 1;

	/**
	 * Wire the confirm listener + the async delivery hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'webwakeupwdb_withdrawal_confirmed', array( $this, 'on_confirmed' ), 20, 5 );
		add_action( Webhook::DELIVER_HOOK, array( $this, 'deliver' ), 10, 2 );
	}

	/**
	 * Schedule an async delivery when a withdrawal is confirmed.
	 *
	 * @param string $request_uid Request UID.
	 * @param mixed  $order       Normalised order (unused — payload is rebuilt at send time).
	 * @param mixed  $req         Statement (unused).
	 * @param int    $log_id      Log row id (unused).
	 * @param mixed  $adapter     Adapter (unused).
	 * @return void
	 */
	public function on_confirmed( string $request_uid, $order = null, $req = null, int $log_id = 0, $adapter = null ): void {
		unset( $order, $req, $log_id, $adapter );

		if ( '' === $request_uid || ! Webhook::is_active() ) {
			return;
		}

		$scheduled_args = array( $request_uid, 0 );
		if ( ! wp_next_scheduled( Webhook::DELIVER_HOOK, $scheduled_args ) ) {
			wp_schedule_single_event( time() + 1, Webhook::DELIVER_HOOK, $scheduled_args );
		}
	}

	/**
	 * Deliver the webhook for a request (async hook callback).
	 *
	 * @param string $request_uid Request UID.
	 * @param int    $attempt     Current attempt (0-based).
	 * @return void
	 */
	public function deliver( string $request_uid, int $attempt = 0 ): void {
		if ( '' === $request_uid || ! Webhook::is_active() ) {
			return;
		}

		$cfg = Webhook::config();

		// Send-time SSRF re-check: a host that validated at save time could now
		// resolve to an internal target (DNS rebinding). Refuse + do not retry.
		if ( ! OutboundUrlGuard::is_safe_url( $cfg['url'] ) ) {
			Debug::error( 'webhook', 'blocked_unsafe_url', array( 'request_uid' => $request_uid ) );
			/** This action documents every delivery outcome (see hooks reference). */
			do_action( 'webwakeupwdb_webhook_delivered', false, 0, $request_uid, '' );
			return;
		}

		$payload = ( new RequestReader() )->webhook_payload( $request_uid );
		if ( null === $payload ) {
			Debug::warn( 'webhook', 'payload_missing', array( 'request_uid' => $request_uid ) );
			return;
		}

		/**
		 * Filter the outbound webhook payload before it is signed + sent.
		 *
		 * @param array  $payload     Default payload (no raw IP).
		 * @param string $request_uid Request UID.
		 */
		$payload = (array) apply_filters( 'webwakeupwdb_webhook_payload', $payload, $request_uid );

		$body        = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$delivery_id = wp_generate_uuid4();
		$event       = (string) ( $payload['event'] ?? 'withdrawal.confirmed' );

		$response = wp_remote_post(
			$cfg['url'],
			array(
				'timeout'            => 5,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'blocking'           => true,
				'headers'            => array(
					'Content-Type'       => 'application/json; charset=utf-8',
					'Accept'             => 'application/json',
					'User-Agent'         => 'WWU-Withdrawal-Button/' . WEBWAKEUPWDB_VERSION,
					'X-WWU-WB-Event'     => $event,
					'X-WWU-WB-Delivery'  => $delivery_id,
					'X-WWU-WB-Signature' => Webhook::sign( $body, $cfg['secret'] ),
				),
				'body'               => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( $attempt < self::MAX_RETRIES ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Webhook::DELIVER_HOOK, array( $request_uid, $attempt + 1 ) );
			}
			Debug::warn(
				'webhook',
				'transport_error',
				array(
					'request_uid' => $request_uid,
					'attempt'     => $attempt,
					'error'       => $response->get_error_code(),
				)
			);
			do_action( 'webwakeupwdb_webhook_delivered', false, 0, $request_uid, $delivery_id );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$ok   = $code >= 200 && $code < 300;

		Debug::info(
			'webhook',
			$ok ? 'delivered' : 'http_error',
			array(
				'request_uid' => $request_uid,
				'code'        => $code,
				'delivery'    => $delivery_id,
			)
		);

		/**
		 * Fires after a webhook delivery attempt (success or failure).
		 *
		 * @param bool   $ok          Whether the receiver returned a 2xx.
		 * @param int    $code        HTTP status code (0 on transport error).
		 * @param string $request_uid Request UID.
		 * @param string $delivery_id Per-delivery uuid (the X-WWU-WB-Delivery header).
		 */
		do_action( 'webwakeupwdb_webhook_delivered', $ok, $code, $request_uid, $delivery_id );
	}
}
