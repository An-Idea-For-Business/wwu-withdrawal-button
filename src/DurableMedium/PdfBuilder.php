<?php
/**
 * PDF generation for the durable-medium receipt (Dompdf, LGPL-2.1).
 *
 * Dompdf is bundled in vendor/ (built by Composer; LGPL-2.1 is GPLv3-compatible).
 * If the vendor directory is missing (e.g. a source checkout without
 * `composer install`), generation degrades gracefully to an empty result and the
 * email is still sent with the full textual content — the durable-medium
 * obligation is met by the email itself; the PDF is an additional copy.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\DurableMedium;

use WWU\WithdrawalButton\Debug\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML → PDF builder.
 */
final class PdfBuilder {

	/**
	 * Whether the Dompdf library is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
			return true;
		}
		$autoload = WWU_WB_PATH . '/vendor/autoload.php';
		if ( is_readable( $autoload ) ) {
			require_once $autoload;
		}
		return class_exists( '\\Dompdf\\Dompdf' );
	}

	/**
	 * Render HTML to PDF bytes, or '' if the library is unavailable / fails.
	 *
	 * @param string $html UTF-8 HTML (table-based layout; CSS 2.1).
	 * @return string Binary PDF, or empty string.
	 */
	public function render( string $html ): string {
		if ( ! self::is_available() ) {
			Debug::warn( 'durable_medium', 'pdf.unavailable', array() );
			return '';
		}

		try {
			$options = new \Dompdf\Options();
			$options->set( 'isRemoteEnabled', false ); // security: no remote fetches during render.
			$options->set( 'defaultFont', 'DejaVu Sans' ); // full Latin coverage (à ä ö ü ß ñ ç …).
			$options->set( 'isHtml5ParserEnabled', true );

			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();
			return (string) $dompdf->output();
		} catch ( \Throwable $e ) {
			Debug::error( 'durable_medium', 'pdf.render_failed', array( 'message' => $e->getMessage() ) );
			return '';
		}
	}
}
