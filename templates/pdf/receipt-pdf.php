<?php
/**
 * Durable-medium receipt PDF (Dompdf, CSS 2.1 / table layout).
 *
 * @var string $name            Consumer name.
 * @var string $order_number    Order number.
 * @var string $items           Items summary.
 * @var string $email           Consumer email.
 * @var string $reason          Optional reason.
 * @var string $submitted_local Localised submission datetime.
 * @var string $submitted_at    ISO submission datetime.
 * @var string $row_hash        Evidence hash.
 * @var string $request_uid     Request UUID.
 * @var array  $trader          Trader {name,address,email}.
 * @var string $verify_url      Verification link.
 * @var string $site_name       Site name.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
	body { font-family: "DejaVu Sans", sans-serif; color: #222; font-size: 12px; }
	h1 { color: #1a1f3a; font-size: 18px; margin-bottom: 2px; }
	.muted { color: #777; }
	table { width: 100%; border-collapse: collapse; margin: 14px 0; }
	td { padding: 5px 0; vertical-align: top; }
	td.k { color: #777; width: 38%; }
	.box { border: 1px solid #ccc; padding: 10px 14px; margin-top: 10px; }
	.hash { font-family: "DejaVu Sans Mono", monospace; font-size: 10px; word-break: break-all; }
	.foot { margin-top: 24px; font-size: 10px; color: #999; }
</style>
</head>
<body>
	<h1><?php esc_html_e( 'Acknowledgement of receipt of withdrawal', 'wwu-withdrawal-button' ); ?></h1>
	<p class="muted"><?php echo esc_html( $site_name ); ?> — <?php esc_html_e( 'durable medium (Art. 11a Dir. 2011/83/EU)', 'wwu-withdrawal-button' ); ?></p>

	<table>
		<tr><td class="k"><?php esc_html_e( 'Order', 'wwu-withdrawal-button' ); ?></td><td><?php echo esc_html( $order_number ); ?></td></tr>
		<tr><td class="k"><?php esc_html_e( 'Goods / services', 'wwu-withdrawal-button' ); ?></td><td><?php echo esc_html( $items ); ?></td></tr>
		<tr><td class="k"><?php esc_html_e( 'Consumer', 'wwu-withdrawal-button' ); ?></td><td><?php echo esc_html( $name ); ?> (<?php echo esc_html( $email ); ?>)</td></tr>
		<?php if ( '' !== $reason ) : ?>
		<tr><td class="k"><?php esc_html_e( 'Reason (optional)', 'wwu-withdrawal-button' ); ?></td><td><?php echo esc_html( $reason ); ?></td></tr>
		<?php endif; ?>
		<?php if ( '' !== $products_selected ) : ?>
		<tr><td class="k"><?php esc_html_e( 'Products withdrawn', 'wwu-withdrawal-button' ); ?></td><td><?php echo esc_html( $products_selected ); ?></td></tr>
		<?php endif; ?>
		<tr><td class="k"><?php esc_html_e( 'Date and time of submission', 'wwu-withdrawal-button' ); ?></td><td><strong><?php echo esc_html( $submitted_local ); ?></strong> (<?php echo esc_html( $submitted_at ); ?>)</td></tr>
		<tr><td class="k"><?php esc_html_e( 'Addressee (trader)', 'wwu-withdrawal-button' ); ?></td><td><?php echo esc_html( $trader['name'] ); ?><?php echo '' !== $trader['address'] ? ', ' . esc_html( $trader['address'] ) : ''; ?><?php echo '' !== $trader['email'] ? ' — ' . esc_html( $trader['email'] ) : ''; ?></td></tr>
	</table>

	<div class="box">
		<strong><?php esc_html_e( 'Declaration', 'wwu-withdrawal-button' ); ?></strong>
		<p><?php echo esc_html( sprintf( /* translators: 1: name, 2: items. */ __( 'I, %1$s, hereby give notice that I withdraw from my contract of sale of the following goods/services: %2$s.', 'wwu-withdrawal-button' ), $name, $items ) ); ?></p>
	</div>

	<div class="foot">
		<p><?php esc_html_e( 'Tamper-evidence reference (SHA-256 of the immutable-log record):', 'wwu-withdrawal-button' ); ?></p>
		<p class="hash"><?php echo esc_html( $row_hash ); ?></p>
		<p><?php esc_html_e( 'Request ID:', 'wwu-withdrawal-button' ); ?> <?php echo esc_html( $request_uid ); ?></p>
		<p><?php esc_html_e( 'Verify:', 'wwu-withdrawal-button' ); ?> <?php echo esc_html( $verify_url ); ?></p>
	</div>
</body>
</html>
