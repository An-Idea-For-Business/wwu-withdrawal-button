<?php
/**
 * HTML mailer with attachment support.
 *
 * A thin wrapper over wp_mail() that sends a single HTML email (with optional
 * attachments) without permanently changing the site's mail content type, and
 * that captures the SPECIFIC failure reason so callers can surface a detailed
 * message to the admin instead of a generic "email failed".
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email sender.
 */
final class Mailer {

	/**
	 * Last send-failure reason: the exception message (when wp_mail() throws) or the
	 * WP_Error message WordPress reports via the wp_mail_failed action (when wp_mail()
	 * returns false). Empty string on success. Lets the caller record + show a DETAILED
	 * reason ("SMTP connect failed", "Could not authenticate", …) instead of a generic
	 * "email failed".
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * The reason the last send_html() call failed, or '' if it succeeded.
	 *
	 * @return string
	 */
	public function last_error(): string {
		return $this->last_error;
	}

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
		$this->last_error = '';

		$set_html = static function () {
			return 'text/html';
		};
		add_filter( 'wp_mail_content_type', $set_html );

		// Capture the SPECIFIC reason WordPress reports when wp_mail() returns false:
		// core fires wp_mail_failed with a WP_Error carrying the transport's message
		// (e.g. an SMTP plugin's "Could not connect" / "Authentication failed"). Stored
		// so the caller can surface a detailed reason to the admin, not a generic one.
		$capture = function ( $wp_error ) {
			if ( is_wp_error( $wp_error ) ) {
				$this->last_error = self::cap( (string) $wp_error->get_error_message() );
			}
		};
		add_action( 'wp_mail_failed', $capture );

		// try / catch / finally:
		// - finally removes the global content-type filter (so a thrown send never
		//   leaves wp_mail_content_type forced to text/html for OTHER plugins' mail)
		//   and the wp_mail_failed listener.
		// - catch keeps an exception raised INSIDE wp_mail() from escaping and crashing
		//   the request. WordPress's own wp_mail() only catches
		//   \PHPMailer\PHPMailer\Exception; an SMTP plugin (WP Mail SMTP, FluentSMTP, a
		//   provider mailer) can raise a different exception type, or a PHP \Error on
		//   8.x, which wp_mail() does NOT swallow. A failed send degrades to false (with
		//   last_error set); the caller records it and the merchant can resend. The
		//   withdrawal itself is already recorded, so the consumer never sees a fatal.
		$headers = array();
		$sent    = false;
		try {
			$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
		} catch ( \Throwable $e ) {
			$this->last_error = self::cap( $e->getMessage() );
			\WebWakeUpWdb\WithdrawalButton\Debug\Debug::error( 'durable_medium', 'mail.exception', array( 'error' => $this->last_error ) );
			error_log( '[WWU Withdrawal Button] wp_mail threw during send: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$sent = false;
		} finally {
			remove_filter( 'wp_mail_content_type', $set_html );
			remove_action( 'wp_mail_failed', $capture );
		}
		return (bool) $sent;
	}

	/**
	 * Cap a failure reason so it never bloats the append-only immutable log or the
	 * admin transient (a real SMTP error is well under this).
	 *
	 * @param string $value Reason.
	 * @return string
	 */
	private static function cap( string $value ): string {
		$value = trim( $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 300 ) : substr( $value, 0, 300 );
	}
}
