<?php
/**
 * Withdrawal button.
 *
 * @var string   $url            Form URL.
 * @var string   $label          Statutory withdrawal label.
 * @var int|null $days_remaining Days left in the window (informational), or null.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wwu-wb-button-wrap">
	<a class="wwu-wb-button" href="<?php echo esc_url( $url ); ?>" data-no-translation>
		<?php echo esc_html( $label ); ?>
	</a>
	<?php if ( is_int( $days_remaining ) && $days_remaining >= 0 ) : ?>
		<span class="wwu-wb-window-note">
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
