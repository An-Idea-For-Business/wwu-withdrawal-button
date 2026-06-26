<?php
/**
 * Durable-medium confirmation of the withdrawal-exemption consent (Art. 8(7) CRD
 * / Art. 51(7) Codice del Consumo).
 *
 * For the DIGITAL-content exemption (Art. 59(1)(o) CdC / Art. 16(1)(m) CRD) the
 * trader must give the consumer, on a durable medium and BEFORE performance
 * begins, a confirmation that includes the consumer's prior express consent +
 * acknowledgement — otherwise the exemption does NOT crystallise and the consumer
 * keeps the 14-day right (Art. 14(4)(b)(iii) CRD). For the SERVICE exemption it is
 * an independent confirmation duty + strong best practice. So we send it for BOTH.
 *
 * Two distinct legally-operative acts must be logged separately:
 *   1. the CHECKOUT CAPTURE — what wording was shown and accepted (handled by
 *      {@see \WebWakeUpWdb\WithdrawalButton\Frontend\WooCheckoutConsent});
 *   2. the CONFIRMATION DELIVERY — that the durable-medium confirmation was sent
 *      (this class) — the consent log alone does not prove delivery.
 *
 * This sends a self-contained e-mail (an e-mail IS a durable medium, recital 23
 * CRD) reproducing the verbatim consent wording, and appends its own immutable-log
 * event so the dispatch is provable. See
 * docs/legal/webwakeupwdb-exemption-consent-evidence-NOTE.md.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Mail;

use WebWakeUpWdb\WithdrawalButton\Domain\ConsentText;
use WebWakeUpWdb\WithdrawalButton\Domain\ExceptionTypes;
use WebWakeUpWdb\WithdrawalButton\Storage\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exemption-consent durable-medium confirmation sender.
 */
final class ExemptionConfirmation {

	/**
	 * Send the durable-medium confirmation for an order's captured consent and log
	 * the dispatch as its own immutable-log event.
	 *
	 * @param string                         $platform  Platform key.
	 * @param string                         $order_ref Order reference.
	 * @param string                         $email     Consumer e-mail (the durable medium recipient).
	 * @param string                         $number    Human order number (for the body).
	 * @param array<int,array<string,mixed>> $entries   Stored consent entries.
	 * @return bool Whether the confirmation was handed to the mailer.
	 */
	public static function send_for_order( string $platform, string $order_ref, string $email, string $number, array $entries ): bool {
		if ( '' === $email || ! is_email( $email ) || empty( $entries ) ) {
			return false;
		}

		$html    = self::build_html( $number, $entries );
		$subject = sprintf(
			/* translators: %s: order number. */
			__( 'Your order %s — confirmation of your right of withdrawal', 'wwu-withdrawal-button' ),
			$number
		);

		$sent = ( new Mailer() )->send_html( $email, $subject, $html );

		// Log the DELIVERY as a distinct legal act (PII-free payload; the recipient
		// is the consumer's own order e-mail, already on the order). The IP is NOT
		// put in the immutable log — it lives only on the purgeable order meta.
		$reasons = array();
		foreach ( $entries as $entry ) {
			$rid = (string) ( ( (array) $entry )['reason_id'] ?? '' );
			if ( '' !== $rid && ! in_array( $rid, $reasons, true ) ) {
				$reasons[] = $rid;
			}
		}

		( new LogRepository() )->append(
			array(
				'request_uid'    => 'consent-confirm-' . $order_ref,
				'platform'       => $platform,
				'order_ref'      => $order_ref,
				'customer_email' => $email,
				'event'          => $sent ? 'exemption_confirmation_sent' : 'exemption_confirmation_failed',
				'payload'        => array(
					'reasons' => $reasons,
					'sent'    => $sent,
				),
				'ip_address'     => '',
			)
		);

		return $sent;
	}

	/**
	 * Build a PREVIEW of the durable-medium confirmation for one reason, for the
	 * admin "what the consumer sees" panel. Same builder the consumer e-mail uses, so
	 * the preview can never drift from the real message. Returns '' for a reason that
	 * has no acknowledgement wording (i.e. a non-conditional reason).
	 *
	 * @param string $reason Reason id (e.g. '59_o', '59_a').
	 * @return string HTML, or '' when the reason needs no confirmation.
	 */
	public static function preview_html( string $reason ): string {
		$text = ConsentText::for_reason( $reason );
		if ( '' === $text ) {
			return '';
		}
		$entries = array(
			array(
				'product_id'   => 0,
				'reason_id'    => $reason,
				'text'         => $text,
				'consented_at' => gmdate( 'c' ),
			),
		);
		return self::build_html( '#' . __( 'EXAMPLE', 'wwu-withdrawal-button' ), $entries );
	}

	/**
	 * Build the confirmation e-mail body (durable medium): reproduces the verbatim
	 * consent + acknowledgement wording the consumer accepted, per reason.
	 *
	 * @param string                         $number  Human order number.
	 * @param array<int,array<string,mixed>> $entries Stored consent entries.
	 * @return string
	 */
	private static function build_html( string $number, array $entries ): string {
		// Group the verbatim wording by reason (one block per distinct reason).
		$by_reason = array();
		foreach ( $entries as $entry ) {
			$entry = (array) $entry;
			$rid   = (string) ( $entry['reason_id'] ?? '' );
			if ( '' === $rid ) {
				continue;
			}
			if ( ! isset( $by_reason[ $rid ] ) ) {
				$by_reason[ $rid ] = array(
					'text'         => (string) ( $entry['text'] ?? '' ),
					'consented_at' => (string) ( $entry['consented_at'] ?? '' ),
				);
			}
		}

		$out  = '<p>' . esc_html(
			sprintf(
				/* translators: %s: order number. */
				__( 'This message confirms, on a durable medium, the express request and acknowledgement you made when placing order %s.', 'wwu-withdrawal-button' ),
				$number
			)
		) . '</p>';

		$out .= '<p>' . esc_html__( 'For the following item type(s) you asked to start immediately and confirmed that you understand you lose the right of withdrawal once that happens:', 'wwu-withdrawal-button' ) . '</p>';

		foreach ( $by_reason as $rid => $info ) {
			$def   = ExceptionTypes::get( (string) $rid );
			$label = is_array( $def ) ? (string) ( $def['label'] ?? $rid ) : (string) $rid;
			$out  .= '<div style="margin:0 0 12px;padding:10px 14px;border-left:3px solid #1d6b2f;background:#f6f7f7;">';
			$out  .= '<p style="margin:0 0 6px;"><strong>' . esc_html( $label ) . '</strong></p>';
			if ( '' !== $info['text'] ) {
				$out .= '<p style="margin:0;">&ldquo;' . esc_html( $info['text'] ) . '&rdquo;</p>';
			}
			$out  .= '</div>';
		}

		$out .= '<p>' . esc_html__( 'Because you gave this prior express consent and acknowledgement, the right of withdrawal does not apply to the item type(s) above once performance has begun (Art. 59 Codice del Consumo / Art. 16 Directive 2011/83/EU). Your 14-day right of withdrawal for any other item in the order is unaffected.', 'wwu-withdrawal-button' ) . '</p>';

		$out .= '<p style="color:#555;font-size:13px;">' . esc_html__( 'Keep this message as your record. It was sent automatically when you placed the order.', 'wwu-withdrawal-button' ) . '</p>';

		/**
		 * Filter the durable-medium exemption-confirmation e-mail HTML.
		 *
		 * @param string $out     The HTML body.
		 * @param string $number  Order number.
		 * @param array  $entries Stored consent entries.
		 */
		return (string) apply_filters( 'webwakeupwdb_exemption_confirmation_html', $out, $number, $entries );
	}
}
