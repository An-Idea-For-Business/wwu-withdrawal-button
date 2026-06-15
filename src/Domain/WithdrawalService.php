<?php
/**
 * Orchestrates the two-step withdrawal flow (Art. 11a(2)–(5)).
 *
 * Step 1 (submit_statement): records the statement, issues a single-use
 * confirmation token. Step 2 (confirm): validates the token, writes the
 * immutable "confirmed" log row (the authoritative timestamp of timely exercise),
 * transitions the order, and fires wwu_wb_withdrawal_confirmed — the hook the
 * durable-medium (email/PDF) and timestamping layers listen on.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Domain;

use WWU\WithdrawalButton\Platform\NormalizedOrder;
use WWU\WithdrawalButton\Platform\OrderDataSource;
use WWU\WithdrawalButton\Security\ClientInfo;
use WWU\WithdrawalButton\Storage\LogRepository;
use WWU\WithdrawalButton\Debug\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Withdrawal flow service.
 */
final class WithdrawalService {

	/**
	 * Log repository.
	 *
	 * @var LogRepository
	 */
	private $log;

	/**
	 * Window calculator.
	 *
	 * @var WindowCalculator
	 */
	private $window;

	/**
	 * Constructor.
	 *
	 * @param LogRepository|null    $log    Log repository.
	 * @param WindowCalculator|null $window Window calculator.
	 */
	public function __construct( ?LogRepository $log = null, ?WindowCalculator $window = null ) {
		$this->log    = $log ?? new LogRepository();
		$this->window = $window ?? new WindowCalculator();
	}

	/**
	 * Step 1 — record the statement and issue a confirmation token.
	 *
	 * @param OrderDataSource   $adapter Platform adapter.
	 * @param NormalizedOrder   $order   Order.
	 * @param WithdrawalRequest $req     Statement.
	 * @return array{request_uid:string,confirm_token:string}
	 */
	public function submit_statement( OrderDataSource $adapter, NormalizedOrder $order, WithdrawalRequest $req ): array {
		$request_uid = wp_generate_uuid4();
		$token       = wp_generate_password( 24, false );

		$adapter->set_meta(
			$order->order_ref,
			'pending',
			array(
				'request_uid' => $request_uid,
				'token_hash'  => wp_hash( $token ),
				'expires'     => time() + ( 48 * HOUR_IN_SECONDS ),
			)
		);

		$this->log->append(
			array(
				'request_uid'    => $request_uid,
				'platform'       => $order->platform,
				'order_ref'      => $order->order_ref,
				'customer_email' => $req->email,
				'event'          => 'statement_submitted',
				'payload'        => array(
					'statement' => $req->to_array(),
					'locale'    => $order->locale,
					'user_agent'=> ClientInfo::user_agent(),
				),
				'ip_address'     => ClientInfo::ip(),
			)
		);

		Debug::log( 'withdrawal', 'statement.submitted', array( 'request_uid' => $request_uid, 'order_ref' => $order->order_ref ) );

		return array(
			'request_uid'   => $request_uid,
			'confirm_token' => $token,
		);
	}

	/**
	 * Step 2 — confirm the withdrawal.
	 *
	 * @param OrderDataSource   $adapter Platform adapter.
	 * @param NormalizedOrder   $order   Order.
	 * @param WithdrawalRequest $req     Statement (re-validated server-side).
	 * @param string            $request_uid Request UID from step 1.
	 * @param string            $token       Confirmation token from step 1.
	 * @return array|\WP_Error Result payload, or WP_Error on failure.
	 */
	public function confirm( OrderDataSource $adapter, NormalizedOrder $order, WithdrawalRequest $req, string $request_uid, string $token ) {
		// Idempotency: if this request was already confirmed, return the prior result.
		$confirmed_uid = (string) $adapter->get_meta( $order->order_ref, 'confirmed_uid' );
		if ( '' !== $confirmed_uid && hash_equals( $confirmed_uid, $request_uid ) ) {
			$existing = $this->log->find( $request_uid, 'confirmed' );
			return $this->result( $order, $request_uid, $existing ? (int) $existing['id'] : 0, true );
		}

		// Validate the confirmation token against the pending record.
		$pending = $adapter->get_meta( $order->order_ref, 'pending' );
		if ( ! is_array( $pending ) || empty( $pending['token_hash'] ) ) {
			return new \WP_Error( 'wwu_wb_no_pending', __( 'No pending withdrawal to confirm. Please start again.', 'wwu-withdrawal-button' ), array( 'status' => 409 ) );
		}
		if ( (int) ( $pending['expires'] ?? 0 ) < time() ) {
			return new \WP_Error( 'wwu_wb_token_expired', __( 'This confirmation has expired. Please start the withdrawal again.', 'wwu-withdrawal-button' ), array( 'status' => 410 ) );
		}
		if ( ! hash_equals( (string) $pending['token_hash'], wp_hash( $token ) ) || ! hash_equals( (string) ( $pending['request_uid'] ?? '' ), $request_uid ) ) {
			return new \WP_Error( 'wwu_wb_token_invalid', __( 'Invalid confirmation. Please start the withdrawal again.', 'wwu-withdrawal-button' ), array( 'status' => 403 ) );
		}

		$submitted_at = gmdate( 'Y-m-d\TH:i:s\Z' );
		$within       = $this->window->is_within_window( $order );
		$days_left    = $this->window->days_remaining( $order );

		$log_id = $this->log->append(
			array(
				'request_uid'    => $request_uid,
				'platform'       => $order->platform,
				'order_ref'      => $order->order_ref,
				'customer_email' => $req->email,
				'event'          => 'confirmed',
				'payload'        => array(
					'statement'    => $req->to_array(),
					'order_number' => $order->number,
					'country'      => $order->country,
					'locale'       => $order->locale,
					'submitted_at' => $submitted_at,
					'within_window'=> $within,
					'days_left'    => $days_left,
					'user_agent'   => ClientInfo::user_agent(),
				),
				'ip_address'     => ClientInfo::ip(),
			)
		);

		// Persist operational state on the order in a single write (mutable; the
		// immutable log is the legal evidence).
		$adapter->batch_meta(
			$order->order_ref,
			array(
				'status'        => 'pending',
				'request_uid'   => $request_uid,
				'requested_at'  => $submitted_at,
				'locale'        => $order->locale,
				'country'       => $order->country,
				'confirmed_uid' => $request_uid,
				'late'          => $within ? 0 : 1,
			)
		);

		$adapter->mark_withdrawal_requested( $order->order_ref );
		$adapter->add_note(
			$order->order_ref,
			$within
				? __( 'Right of withdrawal exercised by the consumer (within the period).', 'wwu-withdrawal-button' )
				: __( 'Right of withdrawal exercised by the consumer — flagged outside the computed period; please verify validity.', 'wwu-withdrawal-button' )
		);

		Debug::info( 'withdrawal', 'confirmed', array( 'request_uid' => $request_uid, 'order_ref' => $order->order_ref, 'within_window' => $within ) );

		// Subscription side-effects: stamp the subscription link + (opt-in) cancel.
		$this->maybe_handle_subscription( $adapter, $order, $request_uid );

		/**
		 * Fires when a withdrawal is confirmed. The durable-medium (email/PDF) and
		 * timestamping layers listen here to send the acknowledgement and anchor
		 * the log hash. Refer to the order via the adapter + order_ref.
		 *
		 * @param string            $request_uid Request UID.
		 * @param NormalizedOrder   $order       Order.
		 * @param WithdrawalRequest $req         Statement.
		 * @param int               $log_id      Immutable-log row id.
		 * @param OrderDataSource   $adapter     Platform adapter.
		 */
		do_action( 'wwu_wb_withdrawal_confirmed', $request_uid, $order, $req, $log_id, $adapter );

		return $this->result( $order, $request_uid, $log_id, false, $submitted_at, $within );
	}

	/**
	 * Subscription side-effects of a confirmed withdrawal.
	 *
	 * Stamps the link to the subscription on the order (so the dashboard can show the
	 * "Subscription" badge + the cancel/pro-rata reminder), and — only when the
	 * merchant opted in — cancels the subscription to stop future renewals. The refund
	 * and any pro-rata deduction always stay manual; the RequestsDashboard surfaces the
	 * reminder regardless of the auto-cancel toggle. No-op for non-subscription orders.
	 *
	 * @param OrderDataSource $adapter     Platform adapter.
	 * @param NormalizedOrder $order       Order.
	 * @param string          $request_uid Request UID (ties the evidence event to the flow).
	 * @return void
	 */
	private function maybe_handle_subscription( OrderDataSource $adapter, NormalizedOrder $order, string $request_uid ): void {
		$sub_ref = (string) $order->subscription_ref;
		if ( '' === $sub_ref ) {
			return; // Not a subscription order — nothing to do.
		}

		// Stamp the subscription link on the order for the dashboard reminder.
		$adapter->batch_meta(
			$order->order_ref,
			array(
				'is_subscription_initial' => $order->is_renewal ? 0 : 1,
				'subscription_ref'        => $sub_ref,
			)
		);

		$opted_in = ! empty( \WWU\WithdrawalButton\Core\Settings::main()['cancel_subscription_on_withdrawal'] );
		if ( ! $opted_in || ! $adapter instanceof \WWU\WithdrawalButton\Platform\SubscriptionAware ) {
			return;
		}

		$cancelled = false;
		try {
			$cancelled = $adapter->cancel_subscription( $order->order_ref );
		} catch ( \Throwable $e ) {
			$cancelled = false;
		}

		// Evidence event (immutable log): record the attempt + outcome.
		$this->log->append(
			array(
				'request_uid'    => $request_uid,
				'platform'       => $order->platform,
				'order_ref'      => $order->order_ref,
				'customer_email' => $order->email,
				'event'          => 'subscription_cancelled',
				'payload'        => array(
					'subscription_ref' => $sub_ref,
					'result'           => $cancelled ? 'cancelled' : 'failed',
					'auto'             => true,
				),
				'ip_address'     => ClientInfo::ip(),
			)
		);

		$adapter->add_note(
			$order->order_ref,
			$cancelled
				? __( 'Subscription cancelled automatically following the withdrawal (future renewals stopped). Refund / any pro-rata: handle manually.', 'wwu-withdrawal-button' )
				: __( 'Automatic subscription cancellation could not be completed — please cancel the subscription manually and process the refund.', 'wwu-withdrawal-button' )
		);

		Debug::info( 'withdrawal', 'subscription.cancel', array( 'order_ref' => $order->order_ref, 'subscription_ref' => $sub_ref, 'result' => $cancelled ) );

		/**
		 * Fires after an attempt to auto-cancel a subscription on withdrawal.
		 *
		 * @param bool            $cancelled Whether the cancel succeeded.
		 * @param string          $sub_ref   Subscription reference.
		 * @param NormalizedOrder $order     Order.
		 * @param OrderDataSource $adapter   Adapter.
		 */
		do_action( 'wwu_wb_subscription_cancel_result', $cancelled, $sub_ref, $order, $adapter );
	}

	/**
	 * Build the confirm result payload.
	 *
	 * @param NormalizedOrder $order        Order.
	 * @param string          $request_uid  Request UID.
	 * @param int             $log_id       Log row id.
	 * @param bool            $already      Whether this was an idempotent replay.
	 * @param string          $submitted_at ISO submission time.
	 * @param bool            $within       Whether within the window.
	 * @return array
	 */
	private function result( NormalizedOrder $order, string $request_uid, int $log_id, bool $already, string $submitted_at = '', bool $within = true ): array {
		return array(
			'request_uid'  => $request_uid,
			'order_number' => $order->number,
			'log_id'       => $log_id,
			'submitted_at' => $submitted_at,
			'within_window'=> $within,
			'already'      => $already,
		);
	}
}
