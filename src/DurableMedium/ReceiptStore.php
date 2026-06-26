<?php
/**
 * Stores durable-medium receipt PDFs in a protected uploads sub-directory.
 *
 * Direct web access is denied (.htaccess + index.html); receipts are served only
 * through the token-gated REST endpoint. Filenames use the request UUID, which is
 * unguessable.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\DurableMedium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receipt PDF store.
 */
final class ReceiptStore {

	/**
	 * Base directory inside uploads.
	 *
	 * @return string
	 */
	private function base_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'webwakeupwdb/receipts';
	}

	/**
	 * Ensure the protected directory exists.
	 *
	 * @return void
	 */
	private function ensure_dir(): void {
		$dir = $this->base_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}
		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Persist a PDF for a request and return its absolute path (or '' on failure).
	 *
	 * @param string $request_uid Request UUID.
	 * @param string $pdf_bytes   Binary PDF.
	 * @return string
	 */
	public function save( string $request_uid, string $pdf_bytes ): string {
		if ( '' === $pdf_bytes ) {
			return '';
		}
		$this->ensure_dir();
		$path = $this->path_for( $request_uid );

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		global $wp_filesystem;
		WP_Filesystem();
		$ok = $wp_filesystem ? $wp_filesystem->put_contents( $path, $pdf_bytes, FS_CHMOD_FILE ) : false;

		return $ok ? $path : '';
	}

	/**
	 * Absolute path for a request's receipt.
	 *
	 * @param string $request_uid Request UUID.
	 * @return string
	 */
	public function path_for( string $request_uid ): string {
		$uid = preg_replace( '/[^a-f0-9\-]/i', '', $request_uid );
		return $this->base_dir() . '/' . $uid . '.pdf';
	}

	/**
	 * Whether a receipt exists for a request.
	 *
	 * @param string $request_uid Request UUID.
	 * @return bool
	 */
	public function exists( string $request_uid ): bool {
		return is_readable( $this->path_for( $request_uid ) );
	}
}
