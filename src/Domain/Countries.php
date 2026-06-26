<?php
/**
 * Country sets for applicability (Rome I Art. 6 — the obligation follows the
 * consumer's country, not the trader's).
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ISO-3166 alpha-2 country sets and helpers.
 */
final class Countries {

	/**
	 * EU-27 member states.
	 *
	 * @var string[]
	 */
	private const EU = array(
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
		'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
		'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
	);

	/**
	 * EEA-EFTA states (transpose the directive via the EEA Agreement).
	 *
	 * @var string[]
	 */
	private const EEA_EFTA = array( 'NO', 'IS', 'LI' );

	/**
	 * Map of country code → default UI locale used for the statutory label when
	 * the site locale does not already cover it.
	 *
	 * @var array<string,string>
	 */
	private const COUNTRY_LOCALE = array(
		'IT' => 'it',
		'DE' => 'de',
		'AT' => 'de',
		'FR' => 'fr',
		'BE' => 'fr',
		'LU' => 'fr',
		'ES' => 'es',
		'SE' => 'sv',
	);

	/**
	 * The full in-scope set (EU-27 + EEA-EFTA), filterable.
	 *
	 * @return string[]
	 */
	public static function in_scope(): array {
		$countries = array_merge( self::EU, self::EEA_EFTA );
		/**
		 * Filter the set of countries treated as in-scope for the mandatory
		 * withdrawal function. Integrators can add/remove (e.g. pending EEA
		 * incorporation for Liechtenstein).
		 *
		 * @param string[] $countries Uppercase ISO-3166 alpha-2 codes.
		 */
		$countries = (array) apply_filters( 'webwakeupwdb_in_scope_countries', $countries );
		return array_values( array_unique( array_map( 'strtoupper', $countries ) ) );
	}

	/**
	 * Whether a country is in the mandatory in-scope set.
	 *
	 * @param string $code Country code.
	 * @return bool
	 */
	public static function is_in_scope( string $code ): bool {
		return in_array( strtoupper( $code ), self::in_scope(), true );
	}

	/**
	 * Whether a country is Switzerland (voluntary-only — no statutory mandate).
	 *
	 * @param string $code Country code.
	 * @return bool
	 */
	public static function is_switzerland( string $code ): bool {
		return 'CH' === strtoupper( $code );
	}

	/**
	 * Suggested label locale for a country (falls back to 'en').
	 *
	 * @param string $code Country code.
	 * @return string
	 */
	public static function locale_for( string $code ): string {
		return self::COUNTRY_LOCALE[ strtoupper( $code ) ] ?? 'en';
	}
}
