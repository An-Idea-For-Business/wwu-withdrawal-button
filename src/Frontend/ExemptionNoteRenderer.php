<?php
/**
 * Renders the consumer-facing "why exempt" transparency note.
 *
 * When the withdrawal button is absent because an order is exempt under Art. 59
 * (reason = 'no_withdrawal_right'), this helper builds a short, accurate note
 * naming the matched statutory exception(s) and the legal reference, so the
 * consumer understands why the right does not apply — instead of seeing silence.
 *
 * Gate: only called when `$decision->show === false` AND
 *       `$decision->reason === 'no_withdrawal_right'` — callers are responsible for
 *       enforcing both conditions before delegating here.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Frontend;

use WebWakeUpWdb\WithdrawalButton\Core\Settings;
use WebWakeUpWdb\WithdrawalButton\Domain\ExceptionTypes;
use WebWakeUpWdb\WithdrawalButton\Domain\ExemptionResolver;
use WebWakeUpWdb\WithdrawalButton\Platform\NormalizedOrder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the exemption transparency note shown to consumers.
 */
final class ExemptionNoteRenderer {

	/**
	 * Build the HTML note for an exempt order.
	 *
	 * Re-resolves per-item reasons on the order to collect the distinct Art. 59
	 * reason ids; seal-based reasons (59_e, 59_i) are skipped because they never
	 * legitimately hide the button. If the resulting set is empty the method
	 * returns an empty string (fail-safe: render nothing rather than a misleading
	 * generic message).
	 *
	 * @param NormalizedOrder $order The order to explain.
	 * @return string HTML or empty string.
	 */
	public static function render( NormalizedOrder $order ): string {
		/*
		 * Collect distinct, non-seal-based reason ids from all line items.
		 * ExemptionResolver::reason_for_item() returns null when no exemption is
		 * mapped for the item, and a reason id string when one is found. We dedupe
		 * via array keys so a reason appearing on multiple items is only listed once.
		 */
		$reason_ids = array();
		foreach ( $order->items as $item ) {
			$id = ExemptionResolver::reason_for_item( (array) $item, $order );
			if ( null === $id ) {
				continue;
			}
			$def = ExceptionTypes::get( $id );
			/*
			 * Skip seal-based reasons (59_e and 59_i): they require the physical seal
			 * to have been broken after delivery, which cannot be verified at checkout,
			 * and they never legitimately suppress the button at the time of ordering.
			 */
			if ( null === $def || ! empty( $def['seal_based'] ) ) {
				continue;
			}
			$reason_ids[ $id ] = true;
		}

		if ( empty( $reason_ids ) ) {
			/* Fail-safe: no confirmed Art. 59 reason → render nothing. */
			return '';
		}

		$reason_ids = array_keys( $reason_ids );

		/*
		 * Merchant-supplied override wins. Saved via wp_kses_post, so it is already
		 * sanitized; we pass it through wp_kses_post() once more on output for
		 * defense-in-depth (the setting may have been imported or modified directly).
		 */
		$settings             = Settings::main();
		$custom_note          = isset( $settings['custom_exemption_note'] ) ? (string) $settings['custom_exemption_note'] : '';
		if ( '' !== $custom_note ) {
			$html = '<div class="webwakeupwdb-exempt-note">' . wp_kses_post( $custom_note ) . '</div>';
			/**
			 * Filters the exemption transparency note HTML.
			 *
			 * @param string          $html       The rendered note HTML.
			 * @param string[]        $reason_ids Matched Art. 59 reason ids.
			 * @param NormalizedOrder $order      The exempt order.
			 */
			return (string) apply_filters( 'webwakeupwdb_exemption_note_text', $html, $reason_ids, $order );
		}

		/*
		 * Default i18n note: collect labels + legal refs from ExceptionTypes, then
		 * build a human-readable sentence naming each exception.
		 */
		$label_parts = array();
		$ref_parts   = array();
		foreach ( $reason_ids as $id ) {
			$def = ExceptionTypes::get( $id );
			if ( null === $def ) {
				continue;
			}
			/* Labels are already translated inside ExceptionTypes via __(). */
			$label_parts[] = (string) ( $def['label'] ?? $id );
			$ref = (string) ( $def['legal_ref'] ?? '' );
			if ( '' !== $ref ) {
				$ref_parts[] = $ref;
			}
		}

		if ( empty( $label_parts ) ) {
			/* Every matched id was unknown — remain fail-safe. */
			return '';
		}

		$labels_str = implode( ', ', $label_parts );
		$refs_str   = implode( ', ', array_unique( $ref_parts ) );

		if ( '' !== $refs_str ) {
			$inner = sprintf(
				/* translators: 1: comma-separated exception label(s), e.g. "Digital content with immediate access"; 2: legal reference(s), e.g. "Art. 59(1)(o) CdC / Art. 16(1)(m) CRD". */
				esc_html__(
					'The right of withdrawal does not apply to this order: every item falls under a statutory exception to the 14-day right (%1$s — %2$s), which you expressly agreed to at checkout.',
					'wwu-withdrawal-button'
				),
				esc_html( $labels_str ),
				esc_html( $refs_str )
			);
		} else {
			// Fallback for reasons (e.g. 'manual') that carry no legal reference.
			$inner = sprintf(
				/* translators: %s: comma-separated exception label(s). */
				esc_html__(
					'The right of withdrawal does not apply to this order: every item falls under a statutory exception to the 14-day right (%s), which you expressly agreed to at checkout.',
					'wwu-withdrawal-button'
				),
				esc_html( $labels_str )
			);
		}

		$html = '<div class="webwakeupwdb-exempt-note"><p>' . $inner . '</p></div>';

		/** This filter is documented above in the custom-note branch. */
		return (string) apply_filters( 'webwakeupwdb_exemption_note_text', $html, $reason_ids, $order );
	}
}
