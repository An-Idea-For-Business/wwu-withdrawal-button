<?php
/**
 * Consumer acknowledgement-of-receipt email (Art. 11a(4) durable medium).
 *
 * Reproduces the withdrawal statement content + the exact date and time of
 * submission, with a permanent verifiable link.
 *
 * @var string $name          Consumer name.
 * @var string $order_number  Order number.
 * @var string $items         Items summary.
 * @var string $email         Consumer email.
 * @var string $reason        Optional reason.
 * @var string $submitted_local Localised submission datetime.
 * @var string $submitted_at  ISO submission datetime.
 * @var string $row_hash      Evidence hash.
 * @var array  $trader        Trader {name,address,email}.
 * @var string $download_url  Receipt PDF link.
 * @var string $verify_url    Verification link.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#333;">
	<h2 style="color:#1a1f3a;"><?php esc_html_e( 'Acknowledgement of receipt of your withdrawal', 'wwu-withdrawal-button' ); ?></h2>

	<p><?php echo esc_html( sprintf( /* translators: %s: name. */ __( 'Dear %s,', 'wwu-withdrawal-button' ), $name ) ); ?></p>
	<p><?php esc_html_e( 'We confirm that we have received your withdrawal from the following distance contract. This message is your acknowledgement of receipt on a durable medium.', 'wwu-withdrawal-button' ); ?></p>

	<table style="width:100%;border-collapse:collapse;margin:16px 0;">
		<tr><td style="padding:6px 0;width:40%;color:#666;"><?php esc_html_e( 'Order', 'wwu-withdrawal-button' ); ?></td><td style="padding:6px 0;"><?php echo esc_html( $order_number ); ?></td></tr>
		<tr><td style="padding:6px 0;color:#666;"><?php esc_html_e( 'Goods / services', 'wwu-withdrawal-button' ); ?></td><td style="padding:6px 0;"><?php echo esc_html( $items ); ?></td></tr>
		<tr><td style="padding:6px 0;color:#666;"><?php esc_html_e( 'Consumer', 'wwu-withdrawal-button' ); ?></td><td style="padding:6px 0;"><?php echo esc_html( $name ); ?> (<?php echo esc_html( $email ); ?>)</td></tr>
		<?php if ( '' !== $reason ) : ?>
		<tr><td style="padding:6px 0;color:#666;"><?php esc_html_e( 'Reason (optional)', 'wwu-withdrawal-button' ); ?></td><td style="padding:6px 0;"><?php echo esc_html( $reason ); ?></td></tr>
		<?php endif; ?>
		<?php if ( '' !== $products_selected ) : ?>
		<tr><td style="padding:6px 0;color:#666;"><?php esc_html_e( 'Products withdrawn', 'wwu-withdrawal-button' ); ?></td><td style="padding:6px 0;"><?php echo esc_html( $products_selected ); ?></td></tr>
		<?php endif; ?>
		<tr><td style="padding:6px 0;color:#666;"><?php esc_html_e( 'Date and time of submission', 'wwu-withdrawal-button' ); ?></td><td style="padding:6px 0;"><strong><?php echo esc_html( $submitted_local ); ?></strong> <span style="color:#999;">(<?php echo esc_html( $submitted_at ); ?>)</span></td></tr>
	</table>

	<p style="font-size:13px;color:#666;">
		<?php esc_html_e( 'Addressee (trader):', 'wwu-withdrawal-button' ); ?>
		<?php echo esc_html( $trader['name'] ); ?><?php echo '' !== $trader['address'] ? ', ' . esc_html( $trader['address'] ) : ''; ?><?php echo '' !== $trader['email'] ? ' — ' . esc_html( $trader['email'] ) : ''; ?>
	</p>

	<p>
		<a href="<?php echo esc_url( $download_url ); ?>" style="display:inline-block;padding:10px 18px;background:#1a1f3a;color:#fff;text-decoration:none;border-radius:5px;"><?php esc_html_e( 'Download your receipt (PDF)', 'wwu-withdrawal-button' ); ?></a>
	</p>

	<p style="font-size:12px;color:#999;">
		<?php esc_html_e( 'Evidence reference:', 'wwu-withdrawal-button' ); ?> <code><?php echo esc_html( substr( $row_hash, 0, 32 ) ); ?>…</code><br>
		<a href="<?php echo esc_url( $verify_url ); ?>" style="color:#999;"><?php esc_html_e( 'Verify this receipt', 'wwu-withdrawal-button' ); ?></a>
	</p>
</div>
