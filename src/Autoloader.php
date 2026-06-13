<?php
/**
 * PSR-4 autoloader for the WWU\WithdrawalButton namespace.
 *
 * No Composer runtime dependency: this maps the plugin namespace onto the
 * src/ directory. The bundled Dompdf library has its own Composer autoloader,
 * loaded on demand by the PdfBuilder.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal PSR-4 autoloader scoped to the plugin namespace.
 */
final class Autoloader {

	/**
	 * Root namespace prefix handled by this autoloader.
	 *
	 * @var string
	 */
	private const PREFIX = 'WWU\\WithdrawalButton\\';

	/**
	 * Whether register() has already run (idempotency guard).
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the autoloader on the SPL stack.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		spl_autoload_register( array( self::class, 'autoload' ) );
		self::$registered = true;
	}

	/**
	 * Resolve a fully-qualified class name to a file under src/ and load it.
	 *
	 * @param string $class Fully-qualified class name.
	 * @return void
	 */
	public static function autoload( string $class ): void {
		if ( 0 !== strncmp( self::PREFIX, $class, strlen( self::PREFIX ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$relative = str_replace( '\\', '/', $relative );
		$file     = WWU_WB_PATH . '/src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
