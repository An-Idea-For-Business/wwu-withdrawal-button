<?php
/**
 * HTML mailer with attachment support.
 *
 * A thin wrapper over wp_mail() that sends a single HTML email (with optional
 * attachments) without permanently changing the site's mail content type.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email sender.
 */
final class Mailer {

	/**
	 * Send an HTML email.
	 *
	 * @param string   $to          Recipient.
	 * @param string   $subject     Subject.
	 * @param string   $html        HTML body.
	 * @param string[] $attachments Absolute file paths.
	 * @return bool
	 */
	public function send_html( string $to, string $subject, string $html, array $attachments = array() ): bool {
		$set_html = static function () {
			return 'text/html';
		};
		add_filter( 'wp_mail_content_type', $set_html );

		// try / catch / finally:
		// - finally removes the global content-type filter so a thrown send never leaves
		//   wp_mail_content_type forced to text/html for the rest of the request (which
		//   would turn OTHER plugins' plain-text emails into HTML).
		// - catch keeps an exception raised INSIDE wp_mail() from escaping and crashing
		//   the request. WordPress's own wp_mail() only catches
		//   \PHPMailer\PHPMailer\Exception; an SMTP plugin (WP Mail SMTP, FluentSMTP, a
		//   provider mailer) can raise a different exception type, or a PHP \Error on
		//   8.x, which wp_mail() does NOT swallow. Here a failed send degrades to false;
		//   the caller records the failure and the merchant can resend. The withdrawal
		//   itself is already recorded, so the consumer never sees a "critical error".
		$headers = array();
		$sent    = false;
		try {
			$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
		} catch ( \Throwable $e ) {
			\WWU\WithdrawalButton\Debug\Debug::error( 'durable_medium', 'mail.exception', array( 'error' => $e->getMessage() ) );
			error_log( '[WWU Withdrawal Button] wp_mail threw during send: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$sent = false;
		} finally {
			remove_filter( 'wp_mail_content_type', $set_html );
		}
		return (bool) $sent;
	}
}
