<?php
/**
 * WooCommerce-wrapped acknowledgement-of-receipt email (HTML).
 *
 * Rendered through wc_get_template_html() by WooAckEmail. It uses ONLY WooCommerce-
 * native email markup (the woocommerce_email_* header/footer hooks + the `.td`
 * table convention that WooCommerce styles via its own email-styles.php /
 * Emogrifier). The plugin adds NO custom colours or CSS here — the email inherits
 * the store's email design configured in WooCommerce → Settings → Emails.
 *
 * Override in a theme at: woocommerce/emails/webwakeupwdb-withdrawal-ack.php
 *
 * @var array  $data               Receipt data (order_number, name, items, …).
 * @var string $email_heading      Email heading.
 * @var string $additional_content Merchant's additional content (from WC settings).
 * @var bool   $sent_to_admin      Always false here.
 * @var bool   $plain_text         Always false here.
 * @var \WC_Email $email           The email object.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$trader = isset( $data['trader'] ) && is_array( $data['trader'] ) ? $data['trader'] : array(
	'name'    => '',
	'address' => '',
	'email'   => '',
);

/**
 * WooCommerce email header (store logo + heading), styled by WooCommerce.
 *
 * @hooked WC_Emails::email_header()
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php echo esc_html( sprintf( /* translators: %s: consumer name. */ __( 'Dear %s,', 'wwu-withdrawal-button' ), (string) ( $data['name'] ?? '' ) ) ); ?></p>

<p><?php esc_html_e( 'We confirm that we have received your withdrawal from the following distance contract. This message is your acknowledgement of receipt on a durable medium.', 'wwu-withdrawal-button' ); ?></p>

<h2><?php esc_html_e( 'Withdrawal details', 'wwu-withdrawal-button' ); ?></h2>

<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; margin-bottom: 20px;" border="1">
	<tr>
		<th class="td" scope="row" style="text-align: left;"><?php esc_html_e( 'Order', 'wwu-withdrawal-button' ); ?></th>
		<td class="td" style="text-align: left;"><?php echo esc_html( (string) ( $data['order_number'] ?? '' ) ); ?></td>
	</tr>
	<tr>
		<th class="td" scope="row" style="text-align: left;"><?php esc_html_e( 'Goods / services', 'wwu-withdrawal-button' ); ?></th>
		<td class="td" style="text-align: left;"><?php echo esc_html( (string) ( $data['items'] ?? '' ) ); ?></td>
	</tr>
	<tr>
		<th class="td" scope="row" style="text-align: left;"><?php esc_html_e( 'Consumer', 'wwu-withdrawal-button' ); ?></th>
		<td class="td" style="text-align: left;"><?php echo esc_html( (string) ( $data['name'] ?? '' ) ); ?> (<?php echo esc_html( (string) ( $data['email'] ?? '' ) ); ?>)</td>
	</tr>
	<?php if ( ! empty( $data['reason'] ) ) : ?>
	<tr>
		<th class="td" scope="row" style="text-align: left;"><?php esc_html_e( 'Reason (optional)', 'wwu-withdrawal-button' ); ?></th>
		<td class="td" style="text-align: left;"><?php echo esc_html( (string) $data['reason'] ); ?></td>
	</tr>
	<?php endif; ?>
	<?php if ( ! empty( $data['products_selected'] ) ) : ?>
	<tr>
		<th class="td" scope="row" style="text-align: left;"><?php esc_html_e( 'Products withdrawn', 'wwu-withdrawal-button' ); ?></th>
		<td class="td" style="text-align: left;"><?php echo esc_html( (string) $data['products_selected'] ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<th class="td" scope="row" style="text-align: left;"><?php esc_html_e( 'Date and time of submission', 'wwu-withdrawal-button' ); ?></th>
		<td class="td" style="text-align: left;"><strong><?php echo esc_html( (string) ( $data['submitted_local'] ?? '' ) ); ?></strong> (<?php echo esc_html( (string) ( $data['submitted_at'] ?? '' ) ); ?>)</td>
	</tr>
</table>

<p><?php esc_html_e( 'Addressee (trader):', 'wwu-withdrawal-button' ); ?>
	<?php echo esc_html( (string) ( $trader['name'] ?? '' ) ); ?><?php echo '' !== (string) ( $trader['address'] ?? '' ) ? ', ' . esc_html( (string) $trader['address'] ) : ''; ?><?php echo '' !== (string) ( $trader['email'] ?? '' ) ? ' — ' . esc_html( (string) $trader['email'] ) : ''; ?>
</p>

<?php if ( ! empty( $data['download_url'] ) ) : ?>
<p><a href="<?php echo esc_url( (string) $data['download_url'] ); ?>"><?php esc_html_e( 'Download your receipt (PDF)', 'wwu-withdrawal-button' ); ?></a></p>
<?php endif; ?>

<p><small>
	<?php esc_html_e( 'Evidence reference:', 'wwu-withdrawal-button' ); ?> <?php echo esc_html( substr( (string) ( $data['row_hash'] ?? '' ), 0, 32 ) ); ?>…
	<?php if ( ! empty( $data['verify_url'] ) ) : ?>
		— <a href="<?php echo esc_url( (string) $data['verify_url'] ); ?>"><?php esc_html_e( 'Verify this receipt', 'wwu-withdrawal-button' ); ?></a>
	<?php endif; ?>
</small></p>

<?php
if ( '' !== (string) $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( (string) $additional_content ) ) );
}

/**
 * WooCommerce email footer, styled by WooCommerce.
 *
 * @hooked WC_Emails::email_footer()
 */
do_action( 'woocommerce_email_footer', $email );
