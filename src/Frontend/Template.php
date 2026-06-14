<?php
/**
 * Template loader with theme override + path confinement.
 *
 * Resolution order: {stylesheet}/wwu-withdrawal-button/{name} →
 * {template}/wwu-withdrawal-button/{name} → plugin templates/{name}. The final
 * path passes through the wwu_wb_template_path filter and is realpath-confined to
 * a trusted directory to prevent local file inclusion via a hostile filter.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template resolver/renderer.
 */
final class Template {

	/**
	 * Theme override sub-directory.
	 *
	 * @var string
	 */
	private const THEME_DIR = 'wwu-withdrawal-button/';

	/**
	 * Render a template and return its output.
	 *
	 * @param string $template_name Template name relative to templates/ (e.g. 'form/withdrawal-form.php').
	 * @param array  $args          Variables exposed to the template.
	 * @return string
	 */
	public static function render( string $template_name, array $args = array() ): string {
		$resolved = self::locate( $template_name, $args );
		if ( '' === $resolved ) {
			return '';
		}
		// Include in an isolated scope so the local variable names here cannot
		// collide with template variables (e.g. a "name" arg vs a $name parameter).
		return self::load_in_scope( $resolved, $args );
	}

	/**
	 * Include a template in an isolated scope and capture its output.
	 *
	 * The two locals use reserved, collision-proof names so extract() always
	 * populates the real template variables (a template arg named "name",
	 * "args" or "template" would otherwise be skipped by EXTR_SKIP).
	 *
	 * @param string $wwu_wb_template_file Absolute template path.
	 * @param array  $wwu_wb_template_vars Variables to expose.
	 * @return string
	 */
	private static function load_in_scope( string $wwu_wb_template_file, array $wwu_wb_template_vars ): string {
		ob_start();
		extract( $wwu_wb_template_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $wwu_wb_template_file;
		return (string) ob_get_clean();
	}

	/**
	 * Locate a confined, existing template file path.
	 *
	 * @param string $name Template name.
	 * @param array  $args Args (passed to the filter only).
	 * @return string Absolute path, or '' if not found / outside trusted dirs.
	 */
	private static function locate( string $name, array $args ): string {
		$name = ltrim( str_replace( array( '..', "\0" ), '', $name ), '/' );

		$theme = locate_template( array( self::THEME_DIR . $name ) );
		$path  = $theme ? $theme : WWU_WB_PATH . '/templates/' . $name;

		/**
		 * Filter the resolved template path.
		 *
		 * @param string $path Absolute path.
		 * @param string $name Template name.
		 * @param array  $args Template args.
		 */
		$path = (string) apply_filters( 'wwu_wb_template_path', $path, $name, $args );

		// Confine to trusted directories (plugin templates or active theme).
		$real = realpath( $path );
		if ( false === $real ) {
			return '';
		}
		$trusted = array(
			realpath( WWU_WB_PATH . '/templates' ),
			realpath( get_stylesheet_directory() ),
			realpath( get_template_directory() ),
		);
		foreach ( $trusted as $base ) {
			if ( $base && 0 === strpos( $real, $base ) ) {
				return $real;
			}
		}
		return '';
	}
}
