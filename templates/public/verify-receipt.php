<?php
/**
 * Standalone, human-readable verification page for a withdrawal receipt.
 *
 * Served directly by ReceiptRoute::verify() when a browser opens the verify link
 * (the REST endpoint still returns JSON for API clients / ?format=json). It is a
 * self-contained HTML document — independent of the active theme — so the link
 * always renders the same trustworthy "verification certificate" regardless of
 * the site that issued it. The small inline CSS here is intentional (this is a
 * public certificate page, not the WooCommerce email).
 *
 * @var string $order_number   Order number.
 * @var string $submitted_human Localised submission datetime.
 * @var string $submitted_iso  ISO submission datetime.
 * @var string $row_hash       Evidence hash.
 * @var bool   $intact         Whether the logged record verifies.
 * @var bool   $within_window  Whether it was within the statutory window.
 * @var string $site_name      Site name.
 * @var string $verified_human Localised "verified at" datetime.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ok_color  = '#1a7f37';
$bad_color = '#b3261e';
?><!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Withdrawal receipt verification', 'wwu-withdrawal-button' ); ?></title>
	<style>
		body { margin: 0; background: #f4f5f7; color: #1d2327; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; }
		.webwakeupwdb-vbox { max-width: 600px; margin: 5vh auto; background: #fff; border: 1px solid #e2e4e7; border-radius: 10px; padding: 32px; }
		.webwakeupwdb-vbadge { display: inline-block; padding: 6px 14px; border-radius: 999px; font-weight: 700; color: #fff; }
		h1 { font-size: 1.4rem; margin: 0 0 4px; }
		.webwakeupwdb-vsub { color: #646970; margin: 0 0 24px; }
		dl { display: grid; grid-template-columns: max-content 1fr; gap: 8px 16px; margin: 0 0 24px; }
		dt { color: #646970; }
		dd { margin: 0; font-weight: 600; word-break: break-word; }
		code { background: #f0f0f1; padding: 2px 6px; border-radius: 4px; font-size: 0.85em; }
		.webwakeupwdb-vfoot { color: #646970; font-size: 0.85rem; border-top: 1px solid #e2e4e7; padding-top: 16px; }
	</style>
</head>
<body>
	<div class="webwakeupwdb-vbox">
		<p>
			<?php if ( $intact ) : ?>
				<span class="webwakeupwdb-vbadge" style="background: <?php echo esc_attr( $ok_color ); ?>;">&#10003; <?php esc_html_e( 'Verified — record intact', 'wwu-withdrawal-button' ); ?></span>
			<?php else : ?>
				<span class="webwakeupwdb-vbadge" style="background: <?php echo esc_attr( $bad_color ); ?>;">&#10007; <?php esc_html_e( 'Record could not be verified', 'wwu-withdrawal-button' ); ?></span>
			<?php endif; ?>
		</p>

		<h1><?php esc_html_e( 'Withdrawal receipt verification', 'wwu-withdrawal-button' ); ?></h1>
		<p class="webwakeupwdb-vsub"><?php echo esc_html( $site_name ); ?></p>

		<dl>
			<dt><?php esc_html_e( 'Order', 'wwu-withdrawal-button' ); ?></dt>
			<dd><?php echo esc_html( $order_number ); ?></dd>

			<dt><?php esc_html_e( 'Submitted', 'wwu-withdrawal-button' ); ?></dt>
			<dd><?php echo esc_html( $submitted_human ); ?> <span style="font-weight:400;color:#646970;">(<?php echo esc_html( $submitted_iso ); ?>)</span></dd>

			<dt><?php esc_html_e( 'Within statutory window', 'wwu-withdrawal-button' ); ?></dt>
			<dd><?php echo $within_window ? esc_html__( 'Yes', 'wwu-withdrawal-button' ) : esc_html__( 'No (late — still recorded)', 'wwu-withdrawal-button' ); ?></dd>

			<dt><?php esc_html_e( 'Evidence hash', 'wwu-withdrawal-button' ); ?></dt>
			<dd><code><?php echo esc_html( $row_hash ); ?></code></dd>
		</dl>

		<p class="webwakeupwdb-vfoot">
			<?php
			echo esc_html(
				$intact
					? __( 'This page confirms that the withdrawal record exists and matches its tamper-evident entry in the immutable log. The evidence hash above is the fingerprint of that entry.', 'wwu-withdrawal-button' )
					: __( 'The withdrawal record could not be matched against the immutable log. Please contact the trader.', 'wwu-withdrawal-button' )
			);
			?>
			<br><?php echo esc_html( sprintf( /* translators: %s: datetime. */ __( 'Checked on %s.', 'wwu-withdrawal-button' ), $verified_human ) ); ?>
		</p>
	</div>
</body>
</html>
