<?php
/**
 * Two-step withdrawal form (Art. 11a(2)+(3)).
 *
 * Step 1 collects/confirms name, contract and email; Step 2 (revealed by JS only
 * after Step 1 succeeds) is the statutory confirmation control, labelled with
 * ONLY the statutory words. No mandatory reason. The JS controller talks to the
 * REST endpoints; this template degrades to a clear message without JS.
 *
 * @var string   $order_ref      Order reference.
 * @var string   $order_number   Human order number.
 * @var string   $name           Pre-filled name.
 * @var string   $email          Pre-filled email.
 * @var string   $withdraw_label Statutory withdrawal label.
 * @var string   $confirm_label  Statutory confirmation label (only these words).
 * @var int|null $days_remaining Days left, or null.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$webwakeupwdb_key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
$webwakeupwdb_token = isset( $_GET['access_token'] ) ? sanitize_text_field( wp_unslash( $_GET['access_token'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>
<div class="webwakeupwdb-form-wrap" data-no-translation
	data-order-ref="<?php echo esc_attr( $order_ref ); ?>"
	data-key="<?php echo esc_attr( $webwakeupwdb_key ); ?>"
	data-access-token="<?php echo esc_attr( $webwakeupwdb_token ); ?>">

	<h2 class="webwakeupwdb-form-title"><?php echo esc_html( $withdraw_label ); ?></h2>

	<?php
	// Reassuring, plain-language explanation of the process (UX + transparency).
	echo \WebWakeUpWdb\WithdrawalButton\Frontend\Template::render( 'partials/consumer-guidance.php' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the partial escapes its own output.
	?>

	<noscript>
		<p class="webwakeupwdb-noscript"><?php esc_html_e( 'JavaScript is required to use the withdrawal form. You may also use the model withdrawal form provided in our terms.', 'wwu-withdrawal-button' ); ?></p>
	</noscript>

	<form class="webwakeupwdb-form" data-step="1" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="webwakeupwdb_noscript_statement" />
		<input type="hidden" name="order_ref" value="<?php echo esc_attr( $order_ref ); ?>" />
		<input type="hidden" name="key" value="<?php echo esc_attr( $webwakeupwdb_key ); ?>" />
		<input type="hidden" name="access_token" value="<?php echo esc_attr( $webwakeupwdb_token ); ?>" />
		<?php wp_nonce_field( 'webwakeupwdb_noscript' ); ?>
		<p class="webwakeupwdb-field">
			<label for="webwakeupwdb-name"><?php esc_html_e( 'Your name', 'wwu-withdrawal-button' ); ?></label>
			<input type="text" id="webwakeupwdb-name" name="name" value="<?php echo esc_attr( $name ); ?>" required autocomplete="name" />
		</p>
		<p class="webwakeupwdb-field">
			<label for="webwakeupwdb-order"><?php esc_html_e( 'Order', 'wwu-withdrawal-button' ); ?></label>
			<input type="text" id="webwakeupwdb-order" value="<?php echo esc_attr( $order_number ); ?>" readonly />
		</p>
		<p class="webwakeupwdb-field">
			<label for="webwakeupwdb-email"><?php esc_html_e( 'Email for the confirmation', 'wwu-withdrawal-button' ); ?></label>
			<input type="email" id="webwakeupwdb-email" name="email" value="<?php echo esc_attr( $email ); ?>" required autocomplete="email" />
		</p>
		<p class="webwakeupwdb-field">
			<label for="webwakeupwdb-reason"><?php esc_html_e( 'Reason (optional)', 'wwu-withdrawal-button' ); ?></label>
			<textarea id="webwakeupwdb-reason" name="reason" rows="2" placeholder="<?php esc_attr_e( 'You are not required to give a reason.', 'wwu-withdrawal-button' ); ?>"></textarea>
		</p>

		<?php if ( ! empty( $items ) && is_array( $items ) ) : ?>
		<fieldset class="webwakeupwdb-field webwakeupwdb-products">
			<legend><?php esc_html_e( 'Withdrawing from only some products?', 'wwu-withdrawal-button' ); ?></legend>
			<p class="webwakeupwdb-products-help"><?php esc_html_e( 'Tick them — leave empty to withdraw from the whole order. If you bought more than one of an item, you can set how many to withdraw (leave blank for all).', 'wwu-withdrawal-button' ); ?></p>
			<?php foreach ( $items as $item ) :
				$item_name = (string) ( $item['name'] ?? '' );
				$item_qty  = (int) ( $item['qty'] ?? 1 );
				if ( '' === $item_name ) {
					continue;
				}
			?>
			<label class="webwakeupwdb-products-item">
				<input type="checkbox" name="products[]" value="<?php echo esc_attr( $item_name ); ?>" />
				<?php echo esc_html( $item_name ); ?>
			</label>
			<?php if ( $item_qty > 1 ) : ?>
			<label class="webwakeupwdb-products-qty">
				<span class="webwakeupwdb-products-qty-label"><?php
					/* translators: %d is the quantity the consumer ordered. */
					printf( esc_html__( 'Quantity to withdraw (of %d):', 'wwu-withdrawal-button' ), (int) $item_qty );
				?></span>
				<input type="number" name="product_qty[<?php echo esc_attr( $item_name ); ?>]" min="1" max="<?php echo esc_attr( (string) $item_qty ); ?>" step="1" inputmode="numeric" placeholder="<?php echo esc_attr( sprintf( /* translators: %d: ordered quantity. */ __( 'all %d', 'wwu-withdrawal-button' ), (int) $item_qty ) ); ?>" />
			</label>
			<?php endif; ?>
			<?php endforeach; ?>
		</fieldset>
		<?php endif; ?>

		<p class="webwakeupwdb-actions">
			<button type="submit" class="webwakeupwdb-button webwakeupwdb-continue"><?php esc_html_e( 'Continue', 'wwu-withdrawal-button' ); ?></button>
		</p>
	</form>

	<div class="webwakeupwdb-step2" hidden>
		<p class="webwakeupwdb-step2-intro"><?php esc_html_e( 'Please confirm your withdrawal. This is the final step.', 'wwu-withdrawal-button' ); ?></p>
		<p class="webwakeupwdb-actions">
			<button type="button" class="webwakeupwdb-button webwakeupwdb-confirm" data-confirm-label="<?php echo esc_attr( $confirm_label ); ?>"><?php echo esc_html( $confirm_label ); ?></button>
		</p>
	</div>

	<div class="webwakeupwdb-result" role="status" aria-live="polite" hidden></div>
</div>
