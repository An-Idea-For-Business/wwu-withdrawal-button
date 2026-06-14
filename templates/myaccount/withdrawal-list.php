<?php
/**
 * Withdrawal landing view — lists the customer's withdrawal-relevant orders.
 *
 * Shown in the My Account "Right of withdrawal" tab and on the public form page
 * when no specific order is selected. Each eligible order links to its two-step
 * form; orders that already have a request show their status.
 *
 * @var array  $rows       Order rows {number, date, status, eligible, url, label}.
 * @var bool   $logged_in  Whether a customer is logged in.
 * @var string $orders_url URL of the WooCommerce "Orders" tab (may be '').
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows       = isset( $rows ) && is_array( $rows ) ? $rows : array();
$logged_in  = ! empty( $logged_in );
$orders_url = isset( $orders_url ) ? (string) $orders_url : '';
?>
<div class="wwu-wb-account-intro">
	<?php
	/*
	 * No heading here: the My Account endpoint and the page already render their
	 * own "Right of withdrawal" title, so a heading in this template would be a
	 * duplicate. The intro paragraph below provides the context instead.
	 */
	?>

	<?php
	// Reassuring, plain-language explanation of how withdrawal works.
	echo \WWU\WithdrawalButton\Frontend\Template::render( 'partials/consumer-guidance.php' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the partial escapes its own output.
	?>

	<?php if ( ! $logged_in ) : ?>

		<p><?php esc_html_e( 'Enter your order number and the email you used at checkout to start a withdrawal. You can also use the link in your order confirmation email, or log in to your account.', 'wwu-withdrawal-button' ); ?></p>

		<form class="wwu-wb-lookup wwu-wb-form-wrap" data-wwu-wb-lookup>
			<div class="wwu-wb-field">
				<label for="wwu-wb-lookup-order"><?php esc_html_e( 'Order number', 'wwu-withdrawal-button' ); ?></label>
				<input id="wwu-wb-lookup-order" name="order_ref" type="text" autocomplete="off" required>
			</div>
			<div class="wwu-wb-field">
				<label for="wwu-wb-lookup-email"><?php esc_html_e( 'Email used at checkout', 'wwu-withdrawal-button' ); ?></label>
				<input id="wwu-wb-lookup-email" name="email" type="email" autocomplete="email" required>
			</div>
			<div class="wwu-wb-actions">
				<button type="submit" class="wwu-wb-button"><?php esc_html_e( 'Find my order', 'wwu-withdrawal-button' ); ?></button>
			</div>
			<p class="wwu-wb-result" role="alert" hidden></p>
		</form>

	<?php elseif ( empty( $rows ) ) : ?>

		<p><?php esc_html_e( 'You have no orders that can be withdrawn right now. The button appears on eligible orders within the withdrawal period.', 'wwu-withdrawal-button' ); ?></p>
		<?php if ( '' !== $orders_url ) : ?>
			<p><a class="wwu-wb-button" href="<?php echo esc_url( $orders_url ); ?>"><?php esc_html_e( 'Go to my orders', 'wwu-withdrawal-button' ); ?></a></p>
		<?php endif; ?>

	<?php else : ?>

		<p><?php esc_html_e( 'Choose an order to withdraw from:', 'wwu-withdrawal-button' ); ?></p>
		<table class="wwu-wb-orders">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'wwu-withdrawal-button' ); ?></th>
					<th><?php esc_html_e( 'Date', 'wwu-withdrawal-button' ); ?></th>
					<th><?php esc_html_e( 'Action', 'wwu-withdrawal-button' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td class="wwu-wb-orders__num"><?php echo esc_html( '#' . (string) $row['number'] ); ?></td>
						<td class="wwu-wb-orders__date"><?php echo esc_html( (string) $row['date'] ); ?></td>
						<td class="wwu-wb-orders__action">
							<?php if ( '' !== (string) $row['status'] ) : ?>
								<span class="wwu-wb-status-pill"><?php echo esc_html( (string) $row['status'] ); ?></span>
							<?php else : ?>
								<a class="wwu-wb-button wwu-wb-button--sm" href="<?php echo esc_url( (string) $row['url'] ); ?>"><?php echo esc_html( (string) $row['label'] ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

	<?php endif; ?>
</div>
