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

		$headers = array();
		$sent    = wp_mail( $to, $subject, $html, $headers, $attachments );

		remove_filter( 'wp_mail_content_type', $set_html );
		return (bool) $sent;
	}
}
