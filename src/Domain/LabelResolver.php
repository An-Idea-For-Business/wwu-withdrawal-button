<?php
/**
 * Statutory button-label resolver.
 *
 * Resolves the legally-exact withdrawal and confirmation labels per country /
 * locale, defaulting to the official EU directive wording (Art. 11a), with
 * national overrides (DE §356a — note: NO "hier"; FR D.221-5; ES direct-effect).
 *
 * The confirmation label is constrained by Art. 11a(3) to ONLY the statutory
 * words; a merchant override that is not in the recognised equivalence set emits
 * a debug warning so non-compliant wording is caught in development.
 *
 * @see docs/legal/wwu-wb-legal-reference.md §4
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Domain;

use WWU\WithdrawalButton\Debug\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves statutory labels.
 */
final class LabelResolver {

	/**
	 * Statutory labels keyed by language code.
	 * Values: [ withdraw, confirm, authority ].
	 *
	 * @var array<string,array{0:string,1:string,2:string}>
	 */
	private const STATUTORY = array(
		'it' => array( 'recedere dal contratto qui', 'conferma recesso', 'Art. 54-bis Cod. Consumo' ),
		'en' => array( 'withdraw from contract here', 'confirm withdrawal', 'Dir. 2011/83 Art. 11a' ),
		'de' => array( 'Vertrag widerrufen', 'Widerruf bestätigen', '§356a BGB' ),
		'fr' => array( 'renoncer au contrat ici', 'confirmer la rétractation', 'Art. D.221-5 Code conso' ),
		'es' => array( 'desistir del contrato aquí', 'confirmar desistimiento', 'Dir. (RDL 1/2007 pending)' ),
	);

	/**
	 * Resolve the withdrawal-button label for a country + locale.
	 *
	 * @param string $country Consumer country code.
	 * @param string $locale  Active locale (e.g. 'it_IT').
	 * @return string
	 */
	public function withdraw_label( string $country, string $locale ): string {
		return $this->resolve( $country, $locale, 0, 'withdraw' );
	}

	/**
	 * Resolve the confirmation-button label for a country + locale.
	 *
	 * @param string $country Consumer country code.
	 * @param string $locale  Active locale.
	 * @return string
	 */
	public function confirm_label( string $country, string $locale ): string {
		return $this->resolve( $country, $locale, 1, 'confirm' );
	}

	/**
	 * Cite the statutory authority for a country/locale (for admin transparency).
	 *
	 * @param string $country Consumer country code.
	 * @param string $locale  Active locale.
	 * @return string
	 */
	public function authority( string $country, string $locale ): string {
		$lang = $this->resolve_language( $country, $locale );
		return self::STATUTORY[ $lang ][2] ?? self::STATUTORY['en'][2];
	}

	/**
	 * Resolve a label, applying merchant overrides with a compliance warning.
	 *
	 * @param string $country Consumer country code.
	 * @param string $locale  Active locale.
	 * @param int    $index   0 = withdraw, 1 = confirm.
	 * @param string $kind    'withdraw'|'confirm' (for the warning channel).
	 * @return string
	 */
	private function resolve( string $country, string $locale, int $index, string $kind ): string {
		$lang      = $this->resolve_language( $country, $locale );
		$statutory = self::STATUTORY[ $lang ][ $index ] ?? self::STATUTORY['en'][ $index ];

		$overrides = \WWU\WithdrawalButton\Core\Settings::get( 'wwu_wb_labels' );
		$override  = isset( $overrides[ $lang ][ $kind ] ) ? trim( (string) $overrides[ $lang ][ $kind ] ) : '';

		if ( '' === $override ) {
			return $statutory;
		}

		// A merchant override is allowed (the directive permits "unambiguous
		// equivalents"), but warn if it diverges from the statutory wording —
		// the confirmation label in particular must use only the statutory words.
		if ( 0 !== strcasecmp( $override, $statutory ) ) {
			Debug::warn(
				'compliance',
				'label.override',
				array(
					'kind'      => $kind,
					'lang'      => $lang,
					'statutory' => $statutory,
					'override'  => $override,
					'note'      => 'confirm' === $kind
						? 'Art. 11a(3) requires ONLY the statutory words for the confirmation button.'
						: 'Ensure the override is an unambiguous equivalent.',
				)
			);
		}

		return $override;
	}

	/**
	 * Pick the language code for a country/locale.
	 * Country-specific statute wins (e.g. DE), then the locale's language,
	 * then the country's default language, then English.
	 *
	 * @param string $country Country code.
	 * @param string $locale  Active locale.
	 * @return string
	 */
	private function resolve_language( string $country, string $locale ): string {
		$country = strtoupper( $country );

		// The locale's language prefix (it_IT → it).
		$locale_lang = strtolower( substr( $locale, 0, 2 ) );
		if ( isset( self::STATUTORY[ $locale_lang ] ) ) {
			return $locale_lang;
		}

		// The country's default language.
		$country_lang = Countries::locale_for( $country );
		if ( isset( self::STATUTORY[ $country_lang ] ) ) {
			return $country_lang;
		}

		return 'en';
	}
}
