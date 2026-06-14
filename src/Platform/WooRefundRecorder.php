<?php
/**
 * Records reimbursements against a withdrawal in the immutable evidence log.
 *
 * The right of withdrawal obliges the trader to reimburse the consumer within 14
 * days (Art. 56 Codice del Consumo / Art. 13 CRD). Proving that reimbursement
 * happened — and when — is part of the evidence trail, so when a WooCommerce
 * refund is issued on an order that has a withdrawal request, we append a
 * `refund_issued` event (amount, currency, refund id, timestamp, acting user) to
 * the append-only log, linked to the request. It never mutates prior rows.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Platform;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Debug\Debug;
use WWU\WithdrawalButton\Storage\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce refund → evidence-log recorder.
 */
final class WooRefundRecorder {

	/**
	 * Wire the refund hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );
	}

	/**
	 * Append a refund_issued event when a withdrawal order is refunded.
	 *
	 * @param int $order_id  Parent order id.
	 * @param int $refund_id Refund object id.
	 * @return void
	 */
	public function on_refunded( $order_id, $refund_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$adapter = Services::instance()->platforms->get( 'woocommerce' );
		if ( ! $adapter ) {
			return;
		}

		// Only log refunds for orders that actually have a withdrawal request.
		$uid = (string) $adapter->get_meta( (string) $order_id, 'request_uid' );
		if ( '' === $uid ) {
			return;
		}

		$refund = wc_get_order( $refund_id );
		$amount = ( $refund instanceof \WC_Order_Refund )
			? (string) $refund->get_amount()
			: (string) $order->get_total_refunded();

		( new LogRepository() )->append(
			array(
				'request_uid'    => $uid,
				'platform'       => 'woocommerce',
				'order_ref'      => (string) $order_id,
				'customer_email' => (string) $order->get_billing_email(),
				'event'          => 'refund_issued',
				'payload'        => array(
					'amount'    => $amount,
					'currency'  => (string) $order->get_currency(),
					'refund_id' => (int) $refund_id,
					'at'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'by'        => get_current_user_id(),
				),
			)
		);

		Debug::info( 'refund', 'recorded', array( 'order' => (int) $order_id, 'amount' => $amount ) );
	}
}
