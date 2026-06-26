<?php
/**
 * Withdrawal button.
 *
 * @var string   $url            Form URL.
 * @var string   $label          Statutory withdrawal label.
 * @var int|null $days_remaining Days left in the window (informational), or null.
 * @var bool     $inline         When true, add self-contained inline styles so the
 *                               button looks like a button even where the plugin
 *                               stylesheet is not loaded (e.g. the FluentCart SPA).
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$inline    = isset( $inline ) ? (bool) $inline : false;
$btn_style = $inline ? ' style="display:inline-block;background:#1d2327;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:600;line-height:1.2;"' : '';
$note_style = $inline ? ' style="display:block;margin-top:8px;font-size:13px;color:#555;"' : '';
?>
<div class="webwakeupwdb-button-wrap">
	<a class="webwakeupwdb-button" href="<?php echo esc_url( $url ); ?>"<?php echo $btn_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline style literal, no user input. ?> data-no-translation>
		<?php echo esc_html( $label ); ?>
	</a>
	<?php if ( is_int( $days_remaining ) && $days_remaining >= 0 ) : ?>
		<span class="webwakeupwdb-window-note"<?php echo $note_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline style literal. ?>>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n( '%d day left to withdraw', '%d days left to withdraw', $days_remaining, 'wwu-withdrawal-button' ),
					$days_remaining
				)
			);
			?>
		</span>
	<?php endif; ?>
</div>
