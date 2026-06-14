<?php
/**
 * Gutenberg block registration.
 *
 * Ships a single dynamic block, "Withdrawal — self-service"
 * (wwu-wb/withdrawal-form), so merchants can place the withdrawal surface
 * anywhere in the block editor / Site Editor. It is a thin server-rendered
 * wrapper: the render callback delegates to the SAME Shortcodes::form() renderer
 * the [wwu_wb_form] shortcode uses, so the applicability + ownership gates and the
 * eligible-orders list behave identically. No build step — the editor script is
 * plain JS using the window.wp.* globals (see blocks/withdrawal-form/).
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Frontend;

use WWU\WithdrawalButton\Shortcodes\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block registrar.
 */
final class Blocks {

	/**
	 * Wire the init hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register the block types from their block.json directories.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; // WordPress < 5.0 — no block editor.
		}

		register_block_type(
			WWU_WB_PATH . '/blocks/withdrawal-form',
			array( 'render_callback' => array( $this, 'render_form_block' ) )
		);
	}

	/**
	 * Render callback for wwu-wb/withdrawal-form.
	 *
	 * Treats attributes as untrusted: the optional order id is cast to a positive
	 * integer and passed to the shared shortcode renderer, which performs the
	 * applicability + ownership checks and returns escaped HTML (or guidance / the
	 * eligible-orders list when no order is given).
	 *
	 * @param array         $attributes Block attributes.
	 * @param string        $content    Inner content (unused — dynamic block).
	 * @param \WP_Block|null $block      Block instance (unused).
	 * @return string
	 */
	public function render_form_block( array $attributes, string $content = '', $block = null ): string {
		$order_id = isset( $attributes['orderId'] ) ? absint( $attributes['orderId'] ) : 0;

		$inner = ( new Shortcodes() )->form(
			array( 'order_id' => $order_id > 0 ? (string) $order_id : '' )
		);

		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '';

		return '<div ' . $wrapper . '>' . $inner . '</div>';
	}
}
