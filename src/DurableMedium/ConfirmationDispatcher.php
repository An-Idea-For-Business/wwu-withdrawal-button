<?php
/**
 * Sends the durable-medium acknowledgement of receipt on withdrawal confirmation.
 *
 * Listens on wwu_wb_withdrawal_confirmed and, "without undue delay" (synchronously
 * on the request), renders the receipt (email HTML + PDF), stores the PDF, mints
 * the verifiable link, emails the consumer and notifies the merchant, then logs
 * the receipt_sent event. All rendering is locale-switched to the consumer's
 * language so the receipt is in the language they bought in.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\DurableMedium;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Domain\WithdrawalRequest;
use WWU\WithdrawalButton\Frontend\Template;
use WWU\WithdrawalButton\Mail\Mailer;
use WWU\WithdrawalButton\Platform\NormalizedOrder;
use WWU\WithdrawalButton\Platform\OrderDataSource;
use WWU\WithdrawalButton\Storage\LogRepository;
use WWU\WithdrawalButton\Debug\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable-medium dispatcher.
 */
final class ConfirmationDispatcher {

	/**
	 * Wire the listener.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wwu_wb_withdrawal_confirmed', array( $this, 'dispatch' ), 10, 5 );
	}

	/**
	 * Build + send the acknowledgement.
	 *
	 * @param string            $request_uid Request UUID.
	 * @param NormalizedOrder   $order       Order.
	 * @param WithdrawalRequest $req         Statement.
	 * @param int               $log_id      Confirmed log row id.
	 * @param OrderDataSource   $adapter     Adapter.
	 * @return bool True if the consumer acknowledgement was handed to the mailer.
	 */
	public function dispatch( string $request_uid, NormalizedOrder $order, WithdrawalRequest $req, int $log_id, OrderDataSource $adapter ): bool {
		$settings = (array) get_option( 'wwu_wb_settings', array() );

		$log_repo = new LogRepository();
		$log_row  = $log_repo->find( $request_uid, 'confirmed' ) ?? array();
		$payload  = isset( $log_row['payload_json'] ) ? (array) json_decode( (string) $log_row['payload_json'], true ) : array();
		$submitted_at = (string) ( $payload['submitted_at'] ?? gmdate( 'Y-m-d\TH:i:s\Z' ) );

		$locale = '' !== $order->locale ? $order->locale : determine_locale();
		$switched = switch_to_locale( $locale );

		$data = ( new ReceiptBuilder() )->data( $request_uid, $order, $req, $log_row, $submitted_at );

		// Generate + store the PDF (best-effort, fully OPTIONAL).
		//
		// The durable-medium obligation (Art. 11a(4)) is met by the acknowledgement
		// EMAIL itself, which always carries the full textual content. The PDF is an
		// extra copy. We therefore guard the whole block with is_available(): when
		// Dompdf is absent (e.g. a plain source checkout without the bundled vendor/
		// dir) we skip rendering + storage entirely and the email still goes out.
		// This keeps a missing PDF library from ever interfering with the send — the
		// underlying builders are already non-throwing, but the explicit guard makes
		// the "PDF is optional" contract obvious at the call site and avoids the
		// extra warn-log + filesystem round-trip when it can't produce anything.
		$pdf_path = '';
		if ( ! empty( $settings['send_pdf'] ) && PdfBuilder::is_available() ) {
			$pdf_html  = Template::render( 'pdf/receipt-pdf.php', $data );
			$pdf_bytes = ( new PdfBuilder() )->render( $pdf_html );
			$pdf_path  = ( new ReceiptStore() )->save( $request_uid, $pdf_bytes );
		}

		// Consumer email.
		$consumer_html = Template::render( 'emails/withdrawal-confirmation.php', $data );
		$subject       = sprintf(
			/* translators: %s: order number. */
			__( 'Acknowledgement of your withdrawal — order %s', 'wwu-withdrawal-button' ),
			$order->number
		);
		$attachments = ( '' !== $pdf_path ) ? array( $pdf_path ) : array();

		/**
		 * Filter the consumer acknowledgement email before sending.
		 *
		 * @param array $email { to, subject, html, attachments }.
		 * @param array $data  Receipt data.
		 */
		$email = (array) apply_filters(
			'wwu_wb_email_content',
			array(
				'to'          => $req->email,
				'subject'     => $subject,
				'html'        => $consumer_html,
				'attachments' => $attachments,
			),
			$data
		);

		// Delivery routing.
		//
		// On WooCommerce, prefer the integrated WC_Email (branded header/footer +
		// customisable subject/heading + theme override) when the merchant has it
		// enabled under WooCommerce → Emails. If WooCommerce is absent (FluentCart),
		// the WC email is missing, or the merchant disabled it, we fall back to the
		// plain standalone mailer so the legally-required acknowledgement always
		// goes out — the WC toggle controls styling, never whether it is sent.
		$sent = false;
		if ( 'woocommerce' === $order->platform && function_exists( 'WC' ) && WC() && method_exists( WC(), 'mailer' ) ) {
			$wc_emails = WC()->mailer()->get_emails();
			$key       = \WWU\WithdrawalButton\Mail\WooAckEmail::CLASS_KEY;
			if ( isset( $wc_emails[ $key ] ) && method_exists( $wc_emails[ $key ], 'trigger' ) ) {
				$sent = (bool) $wc_emails[ $key ]->trigger( $data, (string) $email['to'], (array) $email['attachments'] );
			}
		}

		$mailer = new Mailer();
		if ( ! $sent ) {
			$sent = $mailer->send_html( (string) $email['to'], (string) $email['subject'], (string) $email['html'], (array) $email['attachments'] );
		}

		if ( ! $sent ) {
			// The acknowledgement MUST reach the consumer (Art. 11a(4)). Record the
			// failure in the immutable log and flag an admin notice for follow-up.
			Debug::error( 'durable_medium', 'receipt.email_failed', array( 'request_uid' => $request_uid ) );
			set_transient( 'wwu_wb_mail_failed', $request_uid, WEEK_IN_SECONDS );
			$log_repo->append(
				array(
					'request_uid'    => $request_uid,
					'platform'       => $order->platform,
					'order_ref'      => $order->order_ref,
					'customer_email' => $req->email,
					'event'          => 'receipt_failed',
					'payload'        => array( 'reason' => 'wp_mail_returned_false' ),
				)
			);
		}

		// Merchant notification.
		$merchant = (string) ( $settings['merchant_email'] ?? get_option( 'admin_email' ) );
		if ( '' !== $merchant ) {
			$admin_html = Template::render( 'emails/admin-notification.php', $data );
			$mailer->send_html(
				$merchant,
				sprintf(
					/* translators: %s: order number. */
					__( 'New withdrawal request — order %s', 'wwu-withdrawal-button' ),
					$order->number
				),
				$admin_html
			);
		}

		if ( $switched ) {
			restore_previous_locale();
		}

		// Record the acknowledgement in the immutable log.
		$log_repo->append(
			array(
				'request_uid'    => $request_uid,
				'platform'       => $order->platform,
				'order_ref'      => $order->order_ref,
				'customer_email' => $req->email,
				'event'          => 'receipt_sent',
				'payload'        => array(
					'channel'   => '' !== $pdf_path ? 'email+pdf' : 'email',
					'pdf_stored'=> '' !== $pdf_path,
				),
			)
		);

		Debug::info( 'durable_medium', 'receipt.sent', array( 'request_uid' => $request_uid, 'pdf' => '' !== $pdf_path ) );

		/**
		 * Fires after the durable-medium acknowledgement has been dispatched.
		 *
		 * @param string $request_uid Request UUID.
		 * @param string $channel     'email' or 'email+pdf'.
		 * @param array  $data        Receipt data.
		 */
		do_action( 'wwu_wb_receipt_sent', $request_uid, '' !== $pdf_path ? 'email+pdf' : 'email', $data );

		return $sent;
	}

	/**
	 * Resend the acknowledgement for an existing confirmed request.
	 *
	 * Reconstructs the statement + order from the immutable log (the confirmed row
	 * stores the full statement) and re-runs dispatch(). Used by the admin Requests
	 * page so a merchant can resend after fixing email/SMTP — the failure notice
	 * tells them to "resend from the Requests page".
	 *
	 * @param string $request_uid Request UUID.
	 * @return bool True if the email was handed to the mailer.
	 */
	public function resend( string $request_uid ): bool {
		$repo = new LogRepository();
		$row  = $repo->find( $request_uid, 'confirmed' );
		if ( ! $row ) {
			return false;
		}

		$payload   = (array) json_decode( (string) $row['payload_json'], true );
		$statement = (array) ( $payload['statement'] ?? array() );

		$platform = (string) $row['platform'];
		$order_ref = (string) $row['order_ref'];

		$adapter = Services::instance()->platforms->get( $platform );
		if ( ! $adapter ) {
			$adapter = Services::instance()->platforms->resolve_for_order( $order_ref );
		}
		if ( ! $adapter ) {
			return false;
		}

		$order = $adapter->get_order( $order_ref );
		if ( ! $order ) {
			return false;
		}

		$req = WithdrawalRequest::from_input( $statement );

		return $this->dispatch( $request_uid, $order, $req, (int) $row['id'], $adapter );
	}
}
