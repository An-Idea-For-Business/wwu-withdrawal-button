<?php
/**
 * Injects the statutory withdrawal hyperlink into WooCommerce customer emails.
 *
 * Recital 37 expressly suggests the trader provide hyperlinks leading the
 * consumer to the withdrawal function. This is also the canonical guest path:
 * the link carries the order reference + order key so a guest (no account)
 * reaches the pre-authenticated withdrawal form.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Mail;

use WebWakeUpWdb\WithdrawalButton\Core\Services;
use WebWakeUpWdb\WithdrawalButton\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order-email withdrawal link.
 */
final class OrderEmailLink {

	/**
	 * Wire the hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_email_after_order_table', array( $this, 'maybe_add_link' ), 20, 4 );
	}

	/**
	 * Add the withdrawal link to eligible customer emails.
	 *
	 * @param mixed  $wc_order      WC_Order.
	 * @param bool   $sent_to_admin Whether the email goes to the admin.
	 * @param bool   $plain_text    Whether this is the plain-text email.
	 * @param mixed  $email         WC_Email instance.
	 * @return void
	 */
	public function maybe_add_link( $wc_order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( $sent_to_admin || ! Settings::enabled() || ! $wc_order instanceof \WC_Order ) {
			return;
		}

		$adapter = Services::instance()->platforms->get( 'woocommerce' );
		if ( ! $adapter ) {
			return;
		}
		$order = $adapter->get_order( (string) $wc_order->get_id() );
		if ( ! $order || ! Services::instance()->applicability->decide( $order )->show ) {
			return;
		}

		$page_id = (int) ( Settings::main()['public_form_page_id'] ?? 0 );
		if ( $page_id <= 0 ) {
			return;
		}

		$url = add_query_arg(
			array(
				'webwakeupwdb_order' => rawurlencode( $order->order_ref ),
				'key'          => rawurlencode( (string) $wc_order->get_order_key() ),
			),
			get_permalink( $page_id )
		);

		$label = Services::instance()->labels->withdraw_label( $order->country, '' !== $order->locale ? $order->locale : determine_locale() );

		if ( $plain_text ) {
			echo "\n" . esc_html( $label ) . ': ' . esc_url( $url ) . "\n";
			return;
		}

		echo '<p style="margin-top:16px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:8px 14px;background:#1a1f3a;color:#fff;text-decoration:none;border-radius:5px;" data-no-translation>' . esc_html( $label ) . '</a></p>';
	}
}
