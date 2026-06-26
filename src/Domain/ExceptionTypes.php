<?php
/**
 * Registry of the statutory withdrawal exceptions (Art. 16 CRD / Art. 59 CdC).
 *
 * Each entry is a specific legal reason a product/service may be exempt from the
 * right of withdrawal — NOT an opaque "excluded" boolean. The registry drives the
 * admin UI (the merchant tags products by reason), the per-item evaluator (an item
 * is exempt only when its reason actually applies), and the consumer-transparency
 * copy (it names WHY an item is exempt).
 *
 * Two reasons are CONDITIONAL: they only remove the right when the consumer's
 * prior express consent + acknowledgement of losing the right were captured at
 * checkout (and, for digital, the trader confirmed it on a durable medium). The
 * unconditional ones are exempt by nature. Some are SEAL-based: they depend on the
 * consumer unsealing after delivery, which cannot be known at order time, so they
 * never auto-hide the button (the merchant assesses on return).
 *
 * Filterable via `webwakeupwdb_exception_types` so an integrator can extend/override.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Statutory exception-type registry.
 */
final class ExceptionTypes {

	/** Consent kind: none (unconditional, exempt by nature). */
	public const CONSENT_NONE = 'none';

	/** Consent kind: a service fully performed with prior express consent. */
	public const CONSENT_SERVICE_PERFORMED = 'service_performed';

	/** Consent kind: digital content supplied immediately with consent + acknowledgement. */
	public const CONSENT_DIGITAL_IMMEDIATE = 'digital_immediate';

	/**
	 * Per-request cache of the resolved registry.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static $cache = null;

	/**
	 * The full registry, keyed by reason id.
	 *
	 * @return array<string,array<string,mixed>> id => { id, label, legal_ref, conditional, consent_kind, seal_based, hint }
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$types = array(
			'manual'      => self::def( 'manual', __( 'Manually excluded (no specific reason)', 'wwu-withdrawal-button' ), '', false, self::CONSENT_NONE, false, __( 'Legacy / catch-all. Prefer a specific reason below so the exemption is auditable.', 'wwu-withdrawal-button' ) ),
			'59_a'        => self::def( '59_a', __( 'Service fully performed', 'wwu-withdrawal-button' ), 'Art. 16(1)(a) CRD / Art. 59(1)(a) CdC', true, self::CONSENT_SERVICE_PERFORMED, false, __( 'Only once the service is FULLY performed AND the consumer gave prior express consent and acknowledged losing the right. Partial performance → the right still applies (pro-rata payment).', 'wwu-withdrawal-button' ) ),
			'59_o'        => self::def( '59_o', __( 'Digital content with immediate access', 'wwu-withdrawal-button' ), 'Art. 16(1)(m) CRD / Art. 59(1)(o) CdC', true, self::CONSENT_DIGITAL_IMMEDIATE, false, __( 'Digital content not on a tangible medium (downloads/streaming). Exempt only with prior express consent, acknowledgement of losing the right, AND the trader\'s confirmation on a durable medium. If any condition fails, the right survives and the consumer owes nothing.', 'wwu-withdrawal-button' ) ),
			'59_c'        => self::def( '59_c', __( 'Custom-made / clearly personalised', 'wwu-withdrawal-button' ), 'Art. 16(1)(c) CRD / Art. 59(1)(c) CdC', false, self::CONSENT_NONE, false, __( 'Goods made to the consumer\'s specifications or clearly personalised.', 'wwu-withdrawal-button' ) ),
			'59_d'        => self::def( '59_d', __( 'Perishable / rapidly expiring goods', 'wwu-withdrawal-button' ), 'Art. 16(1)(d) CRD / Art. 59(1)(d) CdC', false, self::CONSENT_NONE, false, __( 'Goods liable to deteriorate or expire rapidly (e.g. fresh food).', 'wwu-withdrawal-button' ) ),
			'59_e'        => self::def( '59_e', __( 'Sealed health/hygiene goods (unsealed after delivery)', 'wwu-withdrawal-button' ), 'Art. 16(1)(e) CRD / Art. 59(1)(e) CdC', false, self::CONSENT_NONE, true, __( 'Only once the consumer breaks the seal — which cannot be known at order time. Tag the product, but the button is NOT auto-hidden; assess on return.', 'wwu-withdrawal-button' ) ),
			'59_i'        => self::def( '59_i', __( 'Sealed audio/video/software (unsealed after delivery)', 'wwu-withdrawal-button' ), 'Art. 16(1)(i) CRD / Art. 59(1)(i) CdC', false, self::CONSENT_NONE, true, __( 'Sealed recordings or computer software once unsealed. Seal-based — not auto-hidden; assess on return.', 'wwu-withdrawal-button' ) ),
			'59_f'        => self::def( '59_f', __( 'Inseparably mixed after delivery', 'wwu-withdrawal-button' ), 'Art. 16(1)(f) CRD / Art. 59(1)(f) CdC', false, self::CONSENT_NONE, false, __( 'Goods that, after delivery, are inseparably mixed with other items.', 'wwu-withdrawal-button' ) ),
			'59_l'        => self::def( '59_l', __( 'Accommodation / transport / leisure on a specific date', 'wwu-withdrawal-button' ), 'Art. 16(1)(l) CRD / Art. 59(1)(n) CdC', false, self::CONSENT_NONE, false, __( 'Accommodation, transport of goods, car rental, catering or leisure services tied to a specific date/period (e.g. dated event tickets, hotel bookings).', 'wwu-withdrawal-button' ) ),
			'59_b'        => self::def( '59_b', __( 'Price tied to financial-market fluctuations', 'wwu-withdrawal-button' ), 'Art. 16(1)(b) CRD / Art. 59(1)(b) CdC', false, self::CONSENT_NONE, false, __( 'Goods/services whose price depends on market fluctuations the trader cannot control.', 'wwu-withdrawal-button' ) ),
			'59_g'        => self::def( '59_g', __( 'Alcoholic beverages with delayed, market-linked delivery', 'wwu-withdrawal-button' ), 'Art. 16(1)(g) CRD / Art. 59(1)(g) CdC', false, self::CONSENT_NONE, false, __( 'Alcohol whose price was agreed at conclusion, delivered after 30 days, value market-dependent (e.g. en primeur wine).', 'wwu-withdrawal-button' ) ),
			'59_h'        => self::def( '59_h', __( 'Urgent repairs/maintenance requested by the consumer', 'wwu-withdrawal-button' ), 'Art. 16(1)(h) CRD / Art. 59(1)(h) CdC', false, self::CONSENT_NONE, false, __( 'Where the consumer specifically requested an urgent visit for repair or maintenance.', 'wwu-withdrawal-button' ) ),
			'59_j'        => self::def( '59_j', __( 'Newspapers / periodicals (except subscriptions)', 'wwu-withdrawal-button' ), 'Art. 16(1)(j) CRD / Art. 59(1)(k) CdC', false, self::CONSENT_NONE, false, __( 'Single copies of newspapers or magazines — NOT subscription contracts.', 'wwu-withdrawal-button' ) ),
			'59_k'        => self::def( '59_k', __( 'Public auction', 'wwu-withdrawal-button' ), 'Art. 16(1)(k) CRD / Art. 59(1)(m) CdC', false, self::CONSENT_NONE, false, __( 'Contracts concluded at a public auction.', 'wwu-withdrawal-button' ) ),
		);

		/**
		 * Filter the statutory exception-type registry.
		 *
		 * @param array $types Reason id => definition.
		 */
		$types = (array) apply_filters( 'webwakeupwdb_exception_types', $types );

		// Inject a plain-language input→outcome example per reason (Standard #12),
		// unless an integrator already supplied one via the filter.
		$examples = self::examples();
		foreach ( $types as $id => $def_row ) {
			if ( empty( $def_row['example'] ) ) {
				$types[ $id ]['example'] = $examples[ $id ] ?? '';
			}
		}

		self::$cache = $types;
		return $types;
	}

	/**
	 * The behavioural group a reason belongs to, derived from its flags:
	 *  - `conditional`     → needs captured consent to remove the right (59_a/59_o);
	 *  - `seal_based`      → depends on unsealing, never auto-hidden (59_e/59_i);
	 *  - `unconditional`   → exempt by nature (everything else, incl. custom types).
	 *
	 * @param string $id Reason id.
	 * @return string One of 'conditional' | 'seal_based' | 'unconditional'.
	 */
	public static function group( string $id ): string {
		$def = self::get( $id );
		if ( null === $def ) {
			return 'unconditional';
		}
		if ( ! empty( $def['seal_based'] ) ) {
			return 'seal_based';
		}
		if ( ! empty( $def['conditional'] ) ) {
			return 'conditional';
		}
		return 'unconditional';
	}

	/**
	 * Plain-language input→outcome example per reason (Standard #12).
	 *
	 * @return array<string,string>
	 */
	private static function examples(): array {
		return array(
			'manual' => __( 'e.g. a product you have assessed individually → tag it here and the button is hidden for it.', 'wwu-withdrawal-button' ),
			'59_a'   => __( 'e.g. a one-off consulting call performed during the order → button hidden after the buyer consents AND the service is fully performed.', 'wwu-withdrawal-button' ),
			'59_o'   => __( 'e.g. an ebook or video the buyer downloads immediately → button hidden only after the buyer ticks the consent box at checkout.', 'wwu-withdrawal-button' ),
			'59_c'   => __( 'e.g. a necklace engraved with the buyer\'s text → no withdrawal, button hidden.', 'wwu-withdrawal-button' ),
			'59_d'   => __( 'e.g. a fresh-food box → no withdrawal, button hidden.', 'wwu-withdrawal-button' ),
			'59_e'   => __( 'e.g. sealed cosmetics → button stays; if returned unsealed you may refuse the refund.', 'wwu-withdrawal-button' ),
			'59_i'   => __( 'e.g. shrink-wrapped software or a DVD → button stays; assess on return whether the seal was broken.', 'wwu-withdrawal-button' ),
			'59_f'   => __( 'e.g. heating oil poured into the buyer\'s tank → no withdrawal, button hidden.', 'wwu-withdrawal-button' ),
			'59_l'   => __( 'e.g. a concert ticket for 20 June, or a dated live webinar → no withdrawal, button hidden.', 'wwu-withdrawal-button' ),
			'59_b'   => __( 'e.g. a gold coin priced to the spot market → no withdrawal, button hidden.', 'wwu-withdrawal-button' ),
			'59_g'   => __( 'e.g. en-primeur wine delivered after 30 days → no withdrawal, button hidden.', 'wwu-withdrawal-button' ),
			'59_h'   => __( 'e.g. an urgent plumber visit the buyer specifically requested → no withdrawal for that visit.', 'wwu-withdrawal-button' ),
			'59_j'   => __( 'e.g. a single magazine issue (NOT a subscription) → no withdrawal, button hidden.', 'wwu-withdrawal-button' ),
			'59_k'   => __( 'e.g. a lot won at a public auction → no withdrawal, button hidden.', 'wwu-withdrawal-button' ),
		);
	}

	/**
	 * A single reason definition, or null when unknown.
	 *
	 * @param string $id Reason id.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Whether a reason id is registered.
	 *
	 * @param string $id Reason id.
	 * @return bool
	 */
	public static function exists( string $id ): bool {
		return null !== self::get( $id );
	}

	/**
	 * Whether a reason is conditional (needs captured consent to apply).
	 *
	 * @param string $id Reason id.
	 * @return bool
	 */
	public static function is_conditional( string $id ): bool {
		$def = self::get( $id );
		return null !== $def && ! empty( $def['conditional'] );
	}

	/**
	 * Whether a reason is seal-based (cannot auto-hide the button at order time).
	 *
	 * @param string $id Reason id.
	 * @return bool
	 */
	public static function is_seal_based( string $id ): bool {
		$def = self::get( $id );
		return null !== $def && ! empty( $def['seal_based'] );
	}

	/**
	 * Reset the per-request cache (tests / after a filter changes).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * Build a normalized definition row.
	 *
	 * @param string $id           Reason id.
	 * @param string $label        Human label.
	 * @param string $legal_ref    Legal citation.
	 * @param bool   $conditional  Whether captured consent is required.
	 * @param string $consent_kind One of the CONSENT_* constants.
	 * @param bool   $seal_based   Whether it depends on unsealing (no auto-hide).
	 * @param string $hint         Plain-language guidance (Standard #12).
	 * @return array<string,mixed>
	 */
	private static function def( string $id, string $label, string $legal_ref, bool $conditional, string $consent_kind, bool $seal_based, string $hint ): array {
		return array(
			'id'           => $id,
			'label'        => $label,
			'legal_ref'    => $legal_ref,
			'conditional'  => $conditional,
			'consent_kind' => $consent_kind,
			'seal_based'   => $seal_based,
			'hint'         => $hint,
		);
	}
}
