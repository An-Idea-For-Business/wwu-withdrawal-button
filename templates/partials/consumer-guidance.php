<?php
/**
 * Consumer-facing "how withdrawal works" guidance.
 *
 * A reassuring, plain-language explanation shown wherever a consumer can start a
 * withdrawal (the form, the account chooser, the public/guest page). It both
 * improves UX (reduces hesitation) and strengthens compliance (clear, transparent
 * information about the right and the process).
 *
 * The withdrawal window (days) is read from settings so a merchant who voluntarily
 * grants MORE than the 14-day legal minimum sees the correct figure. A merchant can
 * also replace the whole block with their own text (e.g. to spell out product-type
 * exemptions like event tickets) via the "Custom consumer guidance" setting — they
 * are then responsible for its legal accuracy. The reimbursement / return deadlines
 * stay at the statutory 14 days regardless of the withdrawal window granted.
 *
 * @var bool $compact When true, render only the short intro (no details block).
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$compact  = isset( $compact ) ? (bool) $compact : false;
$settings = \WebWakeUpWdb\WithdrawalButton\Core\Settings::main();
$days     = isset( $settings['withdrawal_window_days'] ) ? max( 14, (int) $settings['withdrawal_window_days'] ) : 14;
$custom   = isset( $settings['custom_guidance'] ) ? (string) $settings['custom_guidance'] : '';

// Merchant override: render their own wording (already sanitised on save).
if ( '' !== trim( $custom ) ) {
	echo '<div class="webwakeupwdb-guidance webwakeupwdb-guidance--custom">' . wp_kses_post( wpautop( $custom ) ) . '</div>';
	return;
}
?>
<div class="webwakeupwdb-guidance">
	<p class="webwakeupwdb-guidance__intro">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: number of withdrawal days. */
				__( 'You can withdraw from this contract within %d days, without giving any reason. It takes two short steps and we will email you a confirmation straight away.', 'wwu-withdrawal-button' ),
				$days
			)
		);
		?>
	</p>

	<?php if ( ! $compact ) : ?>
		<details class="webwakeupwdb-guidance__details">
			<summary><?php esc_html_e( 'How it works & what happens next', 'wwu-withdrawal-button' ); ?></summary>
			<ul class="webwakeupwdb-guidance__list">
				<li><?php echo esc_html( sprintf( /* translators: %d: number of withdrawal days. */ __( 'You have %d days to withdraw — counted from when you (or someone you nominated) received the goods, or from the day the contract was concluded for a service.', 'wwu-withdrawal-button' ), $days ) ); ?></li>
				<li><?php esc_html_e( 'You do not need to explain why. There are no hidden steps and no obligation to call us.', 'wwu-withdrawal-button' ); ?></li>
				<li><?php esc_html_e( 'Fill in your name and email, then confirm. Right after confirming, we email you an acknowledgement of receipt — keep it as your proof.', 'wwu-withdrawal-button' ); ?></li>
				<li><?php esc_html_e( 'We refund all payments you made for the order, including the standard delivery cost, within 14 days, using the same payment method you used.', 'wwu-withdrawal-button' ); ?></li>
				<li><?php esc_html_e( 'If your order is physical goods, please send them back within 14 days of telling us. We may wait until we receive them (or your proof of return) before refunding; return shipping may be at your expense unless we stated otherwise.', 'wwu-withdrawal-button' ); ?></li>
				<li><?php esc_html_e( 'Some items cannot be withdrawn by law (for example, sealed items unsealed after delivery, event tickets for a specific date, or digital content you agreed to start immediately). If that applies, we will let you know.', 'wwu-withdrawal-button' ); ?></li>
			</ul>
			<p class="webwakeupwdb-guidance__help"><?php esc_html_e( 'If anything is unclear, contact us before confirming — we are happy to help.', 'wwu-withdrawal-button' ); ?></p>
		</details>
	<?php endif; ?>
</div>
