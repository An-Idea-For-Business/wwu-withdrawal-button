<?php
/**
 * Complianz document injection — appends the right-of-withdrawal clauses to the
 * merchant's Complianz Privacy Policy and (when the companion add-on is active)
 * Terms & Conditions documents, via the `cmplz_document_elements` filter.
 *
 * Opt-in only (two Settings toggles, default off), **EU region only**, and clearly
 * labelled. The text is the merchant's clause override when present, else the
 * built-in default WITHOUT the per-clause sample disclaimer (the admin UI carries
 * the lawyer-review disclaimer; the published document stays clean). This never
 * writes into Complianz's document store directly — Complianz re-runs the filter
 * on every regeneration, and turning a toggle off removes the elements next time.
 *
 * Verified contract (docs/analysis/…-complianz-i18n-law-recon-2026-06-19):
 *  - filter `cmplz_document_elements( array $elements, string $region, string $type, array $fields )`, priority 20.
 *  - `$type` slugs: `privacy-policy` | `cookie-policy` | `terms-conditions` (the
 *    last only exists when the `complianz-terms-conditions` companion is active).
 *  - element keys must be plugin-prefixed; `content` is filtered through Complianz KSES.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Compat;

use WWU\WithdrawalButton\Legal\ClauseLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Complianz Privacy / Terms document injector.
 */
final class ComplianzDocuments {

	/**
	 * Main settings option (carries the two opt-in toggles).
	 *
	 * @var string
	 */
	private const OPTION = 'wwu_wb_settings';

	/**
	 * Wire the document filter. No-op unless Complianz is active (the filter
	 * simply never fires), so it is safe to register unconditionally.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cmplz_document_elements', array( $this, 'inject' ), 20, 4 );
	}

	/**
	 * Append our clauses to the relevant Complianz document, gated by region,
	 * document type, and the merchant's opt-in toggle.
	 *
	 * @param mixed  $elements Complianz elements (associative key => definition array).
	 * @param string $region   Complianz region ('eu'|'uk'|'us'|…).
	 * @param string $type     Document slug ('privacy-policy'|'terms-conditions'|…).
	 * @param mixed  $fields   Wizard field values (unused).
	 * @return array
	 */
	public function inject( $elements, $region, $type, $fields ): array {
		unset( $fields );
		$elements = is_array( $elements ) ? $elements : array();

		/**
		 * Regions where the EU withdrawal clauses are appropriate. Default: EU only —
		 * adding them to US/CA/AU documents would be legally incorrect. A merchant who
		 * also wants UK (Consumer Contracts Regulations 2013) can extend this.
		 *
		 * @param string[] $regions Complianz region slugs.
		 */
		$regions = (array) apply_filters( 'wwu_wb_complianz_regions', array( 'eu' ) );
		if ( ! in_array( (string) $region, $regions, true ) ) {
			return $elements;
		}

		$settings = (array) get_option( self::OPTION, array() );
		$lang     = strtolower( substr( determine_locale(), 0, 2 ) );

		if ( 'privacy-policy' === $type && ! empty( $settings['complianz_inject_privacy'] ) ) {
			$elements = $this->append_privacy( $elements, $lang );
		}
		if ( 'terms-conditions' === $type && ! empty( $settings['complianz_inject_terms'] ) ) {
			$elements = $this->append_terms( $elements, $lang );
		}

		/**
		 * Last-chance edit of the elements we add (developer extension).
		 *
		 * @param array  $elements The full element list after our additions.
		 * @param string $type     Document slug.
		 * @param string $lang     Two-letter language.
		 */
		return (array) apply_filters( 'wwu_wb_complianz_elements', $elements, $type, $lang );
	}

	/**
	 * Terms & Conditions: the withdrawal article + the online/Annex I-B modality.
	 *
	 * @param array  $elements Existing elements.
	 * @param string $lang     Two-letter language.
	 * @return array
	 */
	private function append_terms( array $elements, string $lang ): array {
		$elements['wwu_wb_withdrawal_title'] = array(
			'title'     => __( 'Right of withdrawal', 'wwu-withdrawal-button' ),
			'numbering' => true,
		);
		$elements['wwu_wb_withdrawal_terms'] = array(
			'content' => $this->clause_text( 'terms', $lang ),
		);
		$elements['wwu_wb_withdrawal_modelform'] = array(
			'content' => __( 'You may withdraw using the model withdrawal form (Annex I-B) or directly online through the "withdraw from contract" function available in your order area throughout the withdrawal period.', 'wwu-withdrawal-button' ),
		);
		return $elements;
	}

	/**
	 * Privacy Policy: the withdrawal-log + exemption-consent record-keeping clauses.
	 *
	 * @param array  $elements Existing elements.
	 * @param string $lang     Two-letter language.
	 * @return array
	 */
	private function append_privacy( array $elements, string $lang ): array {
		$elements['wwu_wb_withdrawal_privacy_title'] = array(
			'title'     => __( 'Right-of-withdrawal records', 'wwu-withdrawal-button' ),
			'numbering' => true,
		);
		$elements['wwu_wb_withdrawal_privacy'] = array(
			'content' => $this->clause_text( 'privacy', $lang ),
		);
		$elements['wwu_wb_withdrawal_consent_privacy'] = array(
			'content' => $this->clause_text( 'consent_privacy', $lang ),
		);
		return $elements;
	}

	/**
	 * A clause as plain text: the merchant override verbatim when present, else the
	 * built-in default WITHOUT the per-clause sample disclaimer (so the published
	 * document is not cluttered — the admin UI carries the disclaimer).
	 *
	 * @param string $type Clause type.
	 * @param string $lang Two-letter language.
	 * @return string
	 */
	private function clause_text( string $type, string $lang ): string {
		return ClauseLibrary::has_override( $type, $lang )
			? ClauseLibrary::get( $type, $lang )
			: ClauseLibrary::default_text( $type, $lang );
	}

	/**
	 * Build the elements we would inject for a given type — used by the admin
	 * preview so the merchant sees exactly what will be added before enabling.
	 *
	 * @param string $type 'privacy-policy'|'terms-conditions'.
	 * @param string $lang Two-letter language.
	 * @return array
	 */
	public function preview_elements( string $type, string $lang ): array {
		return ( 'terms-conditions' === $type )
			? $this->append_terms( array(), $lang )
			: $this->append_privacy( array(), $lang );
	}

	/**
	 * Whether Complianz is active (admin UI gate).
	 *
	 * @return bool
	 */
	public static function is_complianz_active(): bool {
		return defined( 'cmplz_version' ) || function_exists( 'cmplz_get_value' );
	}

	/**
	 * Whether the Terms & Conditions companion add-on is active (admin UI gate).
	 *
	 * @return bool
	 */
	public static function terms_companion_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = function_exists( 'is_plugin_active' )
			&& is_plugin_active( 'complianz-terms-conditions/complianz-terms-conditions.php' );

		/**
		 * Override the Terms-companion detection (e.g. a non-standard install path).
		 *
		 * @param bool $active Whether the companion is active.
		 */
		return (bool) apply_filters( 'wwu_wb_complianz_terms_companion_active', $active );
	}

	/**
	 * Force Complianz to regenerate its documents (call after a settings save so a
	 * toggle change is reflected without waiting for the next wizard save).
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( function_exists( 'cmplz_flush_documents' ) ) {
			cmplz_flush_documents();
		}
	}
}
