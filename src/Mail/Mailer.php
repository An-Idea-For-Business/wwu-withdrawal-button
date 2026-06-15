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

		// try/finally guarantees the global filter is removed even if wp_mail() — or a
		// third-party hook firing inside it (phpmailer_init, wp_mail, etc.) — throws.
		// Without it a thrown exception would leave wp_mail_content_type forced to
		// text/html for the rest of the request, turning OTHER plugins' plain-text
		// emails into HTML. Conflict-safety: never leak a global mail filter.
		$headers = array();
		try {
			$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
		} finally {
			remove_filter( 'wp_mail_content_type', $set_html );
		}
		return (bool) $sent;
	}
}
