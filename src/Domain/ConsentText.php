<?php
/**
 * Statutory acknowledgement wording for the two CONDITIONAL exemptions.
 *
 * Art. 16(1)(a)/(m) CRD (Art. 59(1)(a)/(o) Codice del Consumo) only remove the
 * right of withdrawal when the consumer gave **prior express consent** AND
 * **acknowledged that the right is lost**. This class returns the plain-language
 * acknowledgement the consumer ticks at checkout, one wording per consent kind:
 *  - digital, immediate access (download/streaming begins);
 *  - service that begins immediately and is fully performed.
 *
 * The exact wording is statutory-sensitive, so it ships as an i18n default and is
 * fully overridable per kind/reason via the `wwu_wb_consent_text` filter (a
 * merchant who wants their lawyer's exact phrasing replaces it there). The text
 * the consumer agreed to is stored verbatim on the order, with a SHA-256 hash, as
 * evidence — so the wording at the time of consent is reconstructable later even
 * if this default changes.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consent / acknowledgement copy resolver.
 */
final class ConsentText {

	/**
	 * The acknowledgement wording for a reason id, or '' when the reason is not
	 * one of the conditional exemptions.
	 *
	 * @param string $reason_id Reason id (e.g. '59_o', '59_a').
	 * @return string
	 */
	public static function for_reason( string $reason_id ): string {
		$def  = ExceptionTypes::get( $reason_id );
		$kind = is_array( $def ) ? (string) ( $def['consent_kind'] ?? '' ) : '';
		return self::for_kind( $kind, $reason_id );
	}

	/**
	 * The acknowledgement wording for a consent kind, or '' when the kind needs no
	 * consent (unconditional / seal-based reasons).
	 *
	 * @param string $kind      One of the ExceptionTypes::CONSENT_* constants.
	 * @param string $reason_id Reason id (for filter context only).
	 * @return string
	 */
	public static function for_kind( string $kind, string $reason_id = '' ): string {
		switch ( $kind ) {
			case ExceptionTypes::CONSENT_DIGITAL_IMMEDIATE:
				$text = __( 'I request immediate access to this digital content and I acknowledge that, once the download or streaming has begun, I lose my right of withdrawal.', 'wwu-withdrawal-button' );
				break;

			case ExceptionTypes::CONSENT_SERVICE_PERFORMED:
				$text = __( 'I request that this service begins immediately and I acknowledge that, once it has been fully performed, I lose my right of withdrawal.', 'wwu-withdrawal-button' );
				break;

			default:
				$text = '';
		}

		/**
		 * Filter the statutory acknowledgement wording.
		 *
		 * Return the exact phrasing your jurisdiction / legal counsel requires. The
		 * value the consumer agreed to is stored verbatim on the order as evidence.
		 *
		 * @param string $text      Default wording (may be '').
		 * @param string $kind      Consent kind (ExceptionTypes::CONSENT_*).
		 * @param string $reason_id Reason id.
		 */
		return (string) apply_filters( 'wwu_wb_consent_text', $text, $kind, $reason_id );
	}
}
