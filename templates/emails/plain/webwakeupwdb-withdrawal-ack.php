<?php
/**
 * WooCommerce-wrapped acknowledgement-of-receipt email (plain text).
 *
 * Override in a theme at: woocommerce/emails/plain/webwakeupwdb-withdrawal-ack.php
 *
 * @var array  $data               Receipt data.
 * @var string $email_heading      Email heading.
 * @var string $additional_content Merchant's additional content.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$trader = isset( $data['trader'] ) && is_array( $data['trader'] ) ? $data['trader'] : array();

echo "= " . esc_html( wp_strip_all_tags( (string) $email_heading ) ) . " =\n\n";

echo esc_html( sprintf( /* translators: %s: consumer name. */ __( 'Dear %s,', 'wwu-withdrawal-button' ), (string) ( $data['name'] ?? '' ) ) ) . "\n\n";
echo esc_html__( 'We confirm that we have received your withdrawal from the following distance contract. This message is your acknowledgement of receipt on a durable medium.', 'wwu-withdrawal-button' ) . "\n\n";

echo esc_html__( 'Order', 'wwu-withdrawal-button' ) . ': ' . esc_html( (string) ( $data['order_number'] ?? '' ) ) . "\n";
echo esc_html__( 'Goods / services', 'wwu-withdrawal-button' ) . ': ' . esc_html( (string) ( $data['items'] ?? '' ) ) . "\n";
echo esc_html__( 'Consumer', 'wwu-withdrawal-button' ) . ': ' . esc_html( (string) ( $data['name'] ?? '' ) ) . ' (' . esc_html( (string) ( $data['email'] ?? '' ) ) . ")\n";
if ( ! empty( $data['reason'] ) ) {
	echo esc_html__( 'Reason (optional)', 'wwu-withdrawal-button' ) . ': ' . esc_html( (string) $data['reason'] ) . "\n";
}
echo esc_html__( 'Date and time of submission', 'wwu-withdrawal-button' ) . ': ' . esc_html( (string) ( $data['submitted_local'] ?? '' ) ) . ' (' . esc_html( (string) ( $data['submitted_at'] ?? '' ) ) . ")\n\n";

echo esc_html__( 'Addressee (trader):', 'wwu-withdrawal-button' ) . ' '
	. esc_html( (string) ( $trader['name'] ?? '' ) )
	. ( '' !== (string) ( $trader['address'] ?? '' ) ? ', ' . esc_html( (string) $trader['address'] ) : '' )
	. ( '' !== (string) ( $trader['email'] ?? '' ) ? ' - ' . esc_html( (string) $trader['email'] ) : '' )
	. "\n\n";

if ( ! empty( $data['download_url'] ) ) {
	echo esc_html__( 'Download your receipt (PDF):', 'wwu-withdrawal-button' ) . ' ' . esc_url( (string) $data['download_url'] ) . "\n";
}
if ( ! empty( $data['verify_url'] ) ) {
	echo esc_html__( 'Verify this receipt:', 'wwu-withdrawal-button' ) . ' ' . esc_url( (string) $data['verify_url'] ) . "\n";
}

if ( '' !== (string) $additional_content ) {
	echo "\n----------\n\n" . esc_html( wp_strip_all_tags( (string) $additional_content ) ) . "\n";
}
