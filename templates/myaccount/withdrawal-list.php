<?php
/**
 * Withdrawal account tab — landing view (no specific order selected).
 *
 * Guides the customer to start a withdrawal from one of their orders. A richer
 * per-customer request history is added with the admin dashboard phase.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wwu-wb-account-intro">
	<h2><?php esc_html_e( 'Right of withdrawal', 'wwu-withdrawal-button' ); ?></h2>
	<p><?php esc_html_e( 'You can withdraw from an eligible distance contract within the withdrawal period. Open one of your orders and use the withdrawal button to start.', 'wwu-withdrawal-button' ); ?></p>
	<p>
		<a class="wwu-wb-button" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">
			<?php esc_html_e( 'Go to my orders', 'wwu-withdrawal-button' ); ?>
		</a>
	</p>
</div>
