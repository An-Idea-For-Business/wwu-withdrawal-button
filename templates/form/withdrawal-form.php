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
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$wwu_wb_key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
$wwu_wb_token = isset( $_GET['access_token'] ) ? sanitize_text_field( wp_unslash( $_GET['access_token'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>
<div class="wwu-wb-form-wrap" data-no-translation
	data-order-ref="<?php echo esc_attr( $order_ref ); ?>"
	data-key="<?php echo esc_attr( $wwu_wb_key ); ?>"
	data-access-token="<?php echo esc_attr( $wwu_wb_token ); ?>">

	<h2 class="wwu-wb-form-title"><?php echo esc_html( $withdraw_label ); ?></h2>

	<?php
	// Reassuring, plain-language explanation of the process (UX + transparency).
	echo \WWU\WithdrawalButton\Frontend\Template::render( 'partials/consumer-guidance.php' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the partial escapes its own output.
	?>

	<noscript>
		<p class="wwu-wb-noscript"><?php esc_html_e( 'JavaScript is required to use the withdrawal form. You may also use the model withdrawal form provided in our terms.', 'wwu-withdrawal-button' ); ?></p>
	</noscript>

	<form class="wwu-wb-form" data-step="1" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wwu_wb_noscript_statement" />
		<input type="hidden" name="order_ref" value="<?php echo esc_attr( $order_ref ); ?>" />
		<input type="hidden" name="key" value="<?php echo esc_attr( $wwu_wb_key ); ?>" />
		<input type="hidden" name="access_token" value="<?php echo esc_attr( $wwu_wb_token ); ?>" />
		<?php wp_nonce_field( 'wwu_wb_noscript' ); ?>
		<p class="wwu-wb-field">
			<label for="wwu-wb-name"><?php esc_html_e( 'Your name', 'wwu-withdrawal-button' ); ?></label>
			<input type="text" id="wwu-wb-name" name="name" value="<?php echo esc_attr( $name ); ?>" required autocomplete="name" />
		</p>
		<p class="wwu-wb-field">
			<label for="wwu-wb-order"><?php esc_html_e( 'Order', 'wwu-withdrawal-button' ); ?></label>
			<input type="text" id="wwu-wb-order" value="<?php echo esc_attr( $order_number ); ?>" readonly />
		</p>
		<p class="wwu-wb-field">
			<label for="wwu-wb-email"><?php esc_html_e( 'Email for the confirmation', 'wwu-withdrawal-button' ); ?></label>
			<input type="email" id="wwu-wb-email" name="email" value="<?php echo esc_attr( $email ); ?>" required autocomplete="email" />
		</p>
		<p class="wwu-wb-field">
			<label for="wwu-wb-reason"><?php esc_html_e( 'Reason (optional)', 'wwu-withdrawal-button' ); ?></label>
			<textarea id="wwu-wb-reason" name="reason" rows="2" placeholder="<?php esc_attr_e( 'You are not required to give a reason.', 'wwu-withdrawal-button' ); ?>"></textarea>
		</p>

		<?php if ( ! empty( $items ) && is_array( $items ) ) : ?>
		<fieldset class="wwu-wb-field wwu-wb-products">
			<legend><?php esc_html_e( 'Withdrawing from only some products?', 'wwu-withdrawal-button' ); ?></legend>
			<p class="wwu-wb-products-help"><?php esc_html_e( 'Tick them — leave empty to withdraw from the whole order.', 'wwu-withdrawal-button' ); ?></p>
			<?php foreach ( $items as $item ) :
				$item_name = (string) ( $item['name'] ?? '' );
				$item_qty  = (int) ( $item['qty'] ?? 1 );
				if ( '' === $item_name ) {
					continue;
				}
			?>
			<label class="wwu-wb-products-item">
				<input type="checkbox" name="products[]" value="<?php echo esc_attr( $item_name ); ?>" />
				<?php echo esc_html( $item_name ); ?><?php if ( $item_qty > 1 ) : ?> <span class="wwu-wb-products-qty">&times; <?php echo esc_html( (string) $item_qty ); ?></span><?php endif; ?>
			</label>
			<?php endforeach; ?>
		</fieldset>
		<?php endif; ?>

		<p class="wwu-wb-actions">
			<button type="submit" class="wwu-wb-button wwu-wb-continue"><?php esc_html_e( 'Continue', 'wwu-withdrawal-button' ); ?></button>
		</p>
	</form>

	<div class="wwu-wb-step2" hidden>
		<p class="wwu-wb-step2-intro"><?php esc_html_e( 'Please confirm your withdrawal. This is the final step.', 'wwu-withdrawal-button' ); ?></p>
		<p class="wwu-wb-actions">
			<button type="button" class="wwu-wb-button wwu-wb-confirm" data-confirm-label="<?php echo esc_attr( $confirm_label ); ?>"><?php echo esc_html( $confirm_label ); ?></button>
		</p>
	</div>

	<div class="wwu-wb-result" role="status" aria-live="polite" hidden></div>
</div>
