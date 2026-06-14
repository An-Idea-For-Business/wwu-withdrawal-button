<?php
/**
 * Merchant notification email for a new withdrawal request.
 *
 * @var string $order_number    Order number.
 * @var string $name            Consumer name.
 * @var string $email           Consumer email.
 * @var string $items           Items summary.
 * @var string $reason          Optional reason.
 * @var string $submitted_local Localised submission datetime.
 * @var string $verify_url      Verification link.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#333;">
	<h2 style="color:#1a1f3a;"><?php esc_html_e( 'New withdrawal request', 'wwu-withdrawal-button' ); ?></h2>
	<?php if ( isset( $within_window ) && false === $within_window ) : ?>
	<p style="padding:8px 12px;background:#fcf9e8;border:1px solid #dba617;color:#7a5c00;border-radius:4px;">
		<strong><?php esc_html_e( 'Flagged outside the computed withdrawal period', 'wwu-withdrawal-button' ); ?></strong> — <?php esc_html_e( 'please verify whether the request is still valid.', 'wwu-withdrawal-button' ); ?>
	</p>
	<?php endif; ?>
	<table style="width:100%;border-collapse:collapse;margin:16px 0;">
		<tr><td style="padding:6px 0;width:40%;color:#666;"><?php esc_html_e( 'Order', 'wwu-withdrawal-button' ); ?></td><td style="padding:6px 0;"><?php echo esc_html( $order_number ); ?></td></tr>
		<tr><td style="padding:6px 0;color:#666;"><?php esc_html_e( 'Consumer', 'wwu-withdrawal-button' ); ?></td><td style="padding:6px 0;"><?php echo esc_html( $name ); ?> (<?php echo esc_html( $email ); ?>)</td></tr>
		<tr><td style="padding:6px 0;color:#666;"><?php esc_html_e( 'Goods / services', 'wwu-withdrawal-button' ); ?></td><td style="padding:6px 0;"><?php echo esc_html( $items ); ?></td></tr>
		<?php if ( '' !== $reason ) : ?>
		<tr><td style="padding:6px 0;color:#666;"><?php esc_html_e( 'Reason', 'wwu-withdrawal-button' ); ?></td><td style="padding:6px 0;"><?php echo esc_html( $reason ); ?></td></tr>
		<?php endif; ?>
		<tr><td style="padding:6px 0;color:#666;"><?php esc_html_e( 'Submitted', 'wwu-withdrawal-button' ); ?></td><td style="padding:6px 0;"><?php echo esc_html( $submitted_local ); ?></td></tr>
	</table>
	<p style="font-size:13px;color:#666;"><?php esc_html_e( 'The order has been marked "Withdrawal requested". Remember the refund obligation (within 14 days). This request is recorded in the immutable log.', 'wwu-withdrawal-button' ); ?></p>
	<p><a href="<?php echo esc_url( $verify_url ); ?>" style="color:#1a1f3a;"><?php esc_html_e( 'View the evidence record', 'wwu-withdrawal-button' ); ?></a></p>
</div>
