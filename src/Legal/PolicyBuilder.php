<?php
/**
 * Assembles the consolidated "Right of withdrawal" information notice from live
 * settings, the merchant's clause overrides, and the selected Art. 59 exemptions.
 *
 * Pure (no side effects): returns a {@see PolicyDocument}. Clause bodies come from
 * {@see ClauseLibrary} (override-aware; the per-clause sample disclaimer is stripped
 * because the document carries one global disclaimer in its wrapper). Section
 * headings and framing are i18n `__()` strings; the exceptions section is driven by
 * {@see ExceptionTypes} + the `wwu_wb_exclusions['by_reason']` option.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Legal;

use WWU\WithdrawalButton\Core\Settings;
use WWU\WithdrawalButton\Domain\ExceptionTypes;
use WWU\WithdrawalButton\Security\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamic policy assembler.
 */
final class PolicyBuilder {

	/**
	 * Build the policy document.
	 *
	 * @param string               $lang Two-letter language; '' = the current site language.
	 * @param array<string,mixed>  $opts Optional: `sections` = allow-list of section ids to keep.
	 * @return PolicyDocument
	 */
	public static function build( string $lang = '', array $opts = array() ): PolicyDocument {
		$lang     = '' !== $lang ? strtolower( substr( $lang, 0, 2 ) ) : self::current_lang();
		$settings = Settings::main();
		$days     = max( 14, (int) ( $settings['withdrawal_window_days'] ?? 14 ) );

		$sections = array(
			self::section_right( $lang, $days ),
			self::section_how( $lang ),
			self::section_refund( $lang, $days ),
		);
		$exceptions = self::section_exceptions( $lang );
		if ( null !== $exceptions ) {
			$sections[] = $exceptions;
		}
		$sections[] = self::section_evidence( $lang );
		$sections[] = self::section_trader( $lang, $settings );

		// Optional allow-list (shortcode `sections="right,how"`).
		if ( ! empty( $opts['sections'] ) && is_array( $opts['sections'] ) ) {
			$allow    = array_map( 'sanitize_key', $opts['sections'] );
			$sections = array_values(
				array_filter(
					$sections,
					static function ( $section ) use ( $allow ) {
						return in_array( $section['id'], $allow, true );
					}
				)
			);
		}

		/**
		 * Filter the assembled policy sections (add / remove / reorder).
		 *
		 * @param array  $sections Ordered sections, each [ id, heading, body_html, source ].
		 * @param string $lang     Two-letter language.
		 */
		$sections = (array) apply_filters( 'wwu_wb_policy_sections', $sections, $lang );

		return new PolicyDocument(
			__( 'Right of withdrawal — information notice', 'wwu-withdrawal-button' ),
			$sections
		);
	}

	/**
	 * Section 1 — the right and the period.
	 *
	 * @param string $lang Language.
	 * @param int    $days Configured withdrawal window.
	 * @return array<string,string>
	 */
	private static function section_right( string $lang, int $days ): array {
		$intro = sprintf(
			/* translators: %d is the number of days in the withdrawal window. */
			__( 'You have %d days to withdraw from this contract without giving any reason. The period runs from the day you (or a third party you indicate) take physical possession of the goods, or from the conclusion of the contract for services and digital content.', 'wwu-withdrawal-button' ),
			$days
		);
		$body = self::to_html( $intro ) . self::clause_html( 'precontractual', $lang );
		return self::section( 'right', __( 'Your right of withdrawal', 'wwu-withdrawal-button' ), $body, 'precontractual' );
	}

	/**
	 * Section 2 — how to withdraw (the online button + model form).
	 *
	 * @param string $lang Language.
	 * @return array<string,string>
	 */
	private static function section_how( string $lang ): array {
		return self::section( 'how', __( 'How to withdraw', 'wwu-withdrawal-button' ), self::clause_html( 'terms', $lang ), 'terms' );
	}

	/**
	 * Section 3 — refunds and returns (Art. 13/14).
	 *
	 * @param string $lang Language.
	 * @param int    $days Configured withdrawal window.
	 * @return array<string,string>
	 */
	private static function section_refund( string $lang, int $days ): array {
		$text = __( 'If you withdraw, we reimburse all payments received from you, including standard delivery costs, without undue delay and no later than 14 days from the day we are informed of your decision. We use the same means of payment you used, at no cost to you. For goods, we may withhold the refund until we receive the goods back or you provide proof of return; you must send the goods back without undue delay and within 14 days, bearing the direct cost of return unless we agreed otherwise. You are only liable for any diminished value resulting from handling beyond what is necessary to establish the nature, characteristics and functioning of the goods.', 'wwu-withdrawal-button' );
		return self::section( 'refund', __( 'Refunds and returns', 'wwu-withdrawal-button' ), self::to_html( $text ), 'builtin' );
	}

	/**
	 * Section 4 — the statutory exceptions that apply to THIS shop (from the
	 * merchant's selected Art. 59 reasons). Returns null when none are configured,
	 * so the section is omitted (coherence — never print "none").
	 *
	 * @param string $lang Language.
	 * @return array<string,string>|null
	 */
	private static function section_exceptions( string $lang ): ?array {
		$opt       = (array) Settings::get( 'wwu_wb_exclusions' );
		$by_reason = ( isset( $opt['by_reason'] ) && is_array( $opt['by_reason'] ) ) ? $opt['by_reason'] : array();

		$items = '';
		foreach ( $by_reason as $rid => $sets ) {
			$has_targets = is_array( $sets ) && ( ! empty( $sets['products'] ) || ! empty( $sets['categories'] ) );
			if ( ! $has_targets ) {
				continue;
			}
			$def = ExceptionTypes::get( (string) $rid );
			if ( null === $def ) {
				continue;
			}
			$line = '<strong>' . esc_html( (string) $def['label'] ) . '</strong>';
			if ( ! empty( $def['legal_ref'] ) ) {
				$line .= ' <span class="wwu-wb-policy__ref">(' . esc_html( (string) $def['legal_ref'] ) . ')</span>';
			}
			if ( ! empty( $def['hint'] ) ) {
				$line .= '<br>' . esc_html( (string) $def['hint'] );
			}
			if ( 'conditional' === ExceptionTypes::group( (string) $rid ) ) {
				$line .= '<br><em>' . esc_html__( 'This exception applies only where your prior express consent and acknowledgement were captured at checkout; otherwise the right of withdrawal still applies.', 'wwu-withdrawal-button' ) . '</em>';
			}
			$items .= '<li>' . $line . '</li>';
		}

		if ( '' === $items ) {
			return null;
		}

		$intro = self::to_html( __( 'For some of the products or services in this shop, the law excludes or limits the right of withdrawal:', 'wwu-withdrawal-button' ) );
		$body  = $intro . '<ul class="wwu-wb-policy__exceptions">' . $items . '</ul>';
		return self::section( 'exceptions', __( 'Exceptions that apply to this shop', 'wwu-withdrawal-button' ), $body, 'exemptions' );
	}

	/**
	 * Section 5 — evidence and privacy (withdrawal log + exemption-consent).
	 *
	 * @param string $lang Language.
	 * @return array<string,string>
	 */
	private static function section_evidence( string $lang ): array {
		$body = self::clause_html( 'privacy', $lang ) . self::clause_html( 'consent_privacy', $lang );
		return self::section( 'privacy', __( 'Evidence and privacy', 'wwu-withdrawal-button' ), $body, 'privacy' );
	}

	/**
	 * Section 6 — trader identity.
	 *
	 * @param string              $lang     Language.
	 * @param array<string,mixed> $settings Main settings.
	 * @return array<string,string>
	 */
	private static function section_trader( string $lang, array $settings ): array {
		$name  = (string) get_bloginfo( 'name' );
		$email = Sanitizer::first_email( (string) ( $settings['merchant_email'] ?? get_option( 'admin_email' ) ) );

		$lines = array();
		if ( '' !== $name ) {
			$lines[] = '<strong>' . esc_html( $name ) . '</strong>';
		}
		if ( '' !== $email ) {
			/* translators: %s is the trader's contact email address. */
			$lines[] = esc_html( sprintf( __( 'Contact for withdrawals: %s', 'wwu-withdrawal-button' ), $email ) );
		}
		$body = '<p>' . implode( '<br>', $lines ) . '</p>';
		return self::section( 'trader', __( 'Who we are', 'wwu-withdrawal-button' ), $body, 'builtin' );
	}

	/**
	 * Build a section row.
	 *
	 * @param string $id      Section id.
	 * @param string $heading Heading.
	 * @param string $body    Body HTML (already safe).
	 * @param string $source  Provenance tag.
	 * @return array<string,string>
	 */
	private static function section( string $id, string $heading, string $body, string $source ): array {
		return array(
			'id'        => $id,
			'heading'   => $heading,
			'body_html' => $body,
			'source'    => $source,
		);
	}

	/**
	 * A clause rendered to safe HTML. Uses the merchant override verbatim when
	 * present, else the built-in default WITHOUT the per-clause sample disclaimer
	 * (the document shows one global disclaimer in its wrapper).
	 *
	 * @param string $type Clause type.
	 * @param string $lang Language.
	 * @return string
	 */
	private static function clause_html( string $type, string $lang ): string {
		$text = ClauseLibrary::has_override( $type, $lang )
			? ClauseLibrary::get( $type, $lang )
			: ClauseLibrary::default_text( $type, $lang );
		return self::to_html( $text );
	}

	/**
	 * Escape plain clause text and wrap it in paragraphs.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function to_html( string $text ): string {
		return wpautop( esc_html( $text ) );
	}

	/**
	 * The current two-letter site language.
	 *
	 * @return string
	 */
	private static function current_lang(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return strtolower( substr( (string) $locale, 0, 2 ) );
	}
}
