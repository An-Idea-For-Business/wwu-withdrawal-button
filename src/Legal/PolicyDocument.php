<?php
/**
 * Value object for the assembled "Right of withdrawal" policy document.
 *
 * Pure data produced by {@see PolicyBuilder} and rendered to HTML (page /
 * shortcode / block) or plain text (PDF text layer / archival). Section bodies
 * are curated HTML the builder already escaped — the document renders the title
 * and headings escaped, and the body verbatim (builder contract).
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Legal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable assembled policy document.
 */
final class PolicyDocument {

	/**
	 * Document title.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Ordered sections, each: [ id, heading, body_html, source ].
	 *
	 * @var array<int,array<string,string>>
	 */
	private $sections;

	/**
	 * Constructor.
	 *
	 * @param string                            $title    Document title.
	 * @param array<int,array<string,string>>   $sections Ordered sections (id/heading/body_html/source).
	 */
	public function __construct( string $title, array $sections ) {
		$this->title    = $title;
		$this->sections = array_values( $sections );
	}

	/**
	 * The document title.
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * The ordered sections.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function sections(): array {
		return $this->sections;
	}

	/**
	 * Render to self-contained HTML. Title + headings are escaped here; each
	 * section's `body_html` is curated, already-escaped HTML from the builder.
	 *
	 * @return string
	 */
	public function to_html(): string {
		$out = '<div class="webwakeupwdb-policy">';
		$out .= '<h2 class="webwakeupwdb-policy__title">' . esc_html( $this->title ) . '</h2>';
		foreach ( $this->sections as $section ) {
			$id      = isset( $section['id'] ) ? sanitize_html_class( (string) $section['id'] ) : '';
			$heading = isset( $section['heading'] ) ? (string) $section['heading'] : '';
			$body    = isset( $section['body_html'] ) ? (string) $section['body_html'] : '';
			$out    .= '<section class="webwakeupwdb-policy__section"' . ( '' !== $id ? ' id="webwakeupwdb-policy-' . esc_attr( $id ) . '"' : '' ) . '>';
			if ( '' !== $heading ) {
				$out .= '<h3 class="webwakeupwdb-policy__heading">' . esc_html( $heading ) . '</h3>';
			}
			$out .= '<div class="webwakeupwdb-policy__body">' . $body . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- curated, builder-escaped clause HTML.
			$out .= '</section>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * Render to plain text (PDF text layer / archival copy).
	 *
	 * @return string
	 */
	public function to_plain(): string {
		$lines = array( $this->title, '' );
		foreach ( $this->sections as $section ) {
			if ( ! empty( $section['heading'] ) ) {
				$lines[] = (string) $section['heading'];
			}
			$body    = isset( $section['body_html'] ) ? wp_strip_all_tags( (string) $section['body_html'] ) : '';
			$lines[] = trim( $body );
			$lines[] = '';
		}
		return trim( implode( "\n", $lines ) );
	}
}
