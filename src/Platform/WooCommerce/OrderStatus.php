<?php
/**
 * Custom WooCommerce order status: "Withdrawal requested" (wc-wb-requested).
 *
 * Registration requires three parallel hooks (register_post_status +
 * wc_order_statuses + woocommerce_register_shop_order_post_statuses) and the
 * bulk-action hooks must be registered for BOTH the legacy CPT screen and the
 * HPOS screen, or actions silently fail on one storage mode.
 *
 * Note: WC_Order::get_status() returns the slug WITHOUT the 'wc-' prefix, so
 * the unprefixed form 'wb-requested' is what comparisons see.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Platform\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Withdrawal-requested order status.
 */
final class OrderStatus {

	/**
	 * Prefixed status slug (used with register/update_status).
	 *
	 * @var string
	 */
	public const SLUG = 'wc-wb-requested';

	/**
	 * Unprefixed status slug (returned by WC_Order::get_status()).
	 *
	 * @var string
	 */
	public const SLUG_UNPREFIXED = 'wb-requested';

	/**
	 * Bulk action id.
	 *
	 * @var string
	 */
	private const BULK_ACTION = 'webwakeupwdb_mark_requested';

	/**
	 * Wire all hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_to_order_statuses' ) );
		add_filter( 'woocommerce_register_shop_order_post_statuses', array( $this, 'register_shop_order_post_statuses' ) );

		// Bulk actions on both CPT and HPOS order list screens.
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_action' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	/**
	 * Register the custom post status.
	 *
	 * @return void
	 */
	public function register_post_status(): void {
		register_post_status(
			self::SLUG,
			array(
				'label'                     => _x( 'Withdrawal requested', 'Order status', 'wwu-withdrawal-button' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders. */
				'label_count'               => _n_noop( 'Withdrawal requested <span class="count">(%s)</span>', 'Withdrawal requested <span class="count">(%s)</span>', 'wwu-withdrawal-button' ),
			)
		);
	}

	/**
	 * Expose the status in the WooCommerce status dropdown.
	 *
	 * @param array $statuses Existing statuses.
	 * @return array
	 */
	public function add_to_order_statuses( array $statuses ): array {
		$statuses[ self::SLUG ] = _x( 'Withdrawal requested', 'Order status', 'wwu-withdrawal-button' );
		return $statuses;
	}

	/**
	 * Register the status in WooCommerce's internal shop_order post statuses.
	 *
	 * @param array $statuses Existing statuses.
	 * @return array
	 */
	public function register_shop_order_post_statuses( array $statuses ): array {
		$statuses[ self::SLUG ] = array(
			'label'                     => _x( 'Withdrawal requested', 'Order status', 'wwu-withdrawal-button' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
		);
		return $statuses;
	}

	/**
	 * Add the bulk action to a list screen.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_action( array $actions ): array {
		$actions[ self::BULK_ACTION ] = __( 'Mark as withdrawal requested', 'wwu-withdrawal-button' );
		return $actions;
	}

	/**
	 * Handle the bulk action on either screen.
	 *
	 * @param string $redirect    Redirect URL.
	 * @param string $doaction    Selected action.
	 * @param array  $object_ids  Order IDs.
	 * @return string
	 */
	public function handle_bulk_action( string $redirect, string $doaction, array $object_ids ): string {
		if ( self::BULK_ACTION !== $doaction ) {
			return $redirect;
		}
		$changed = 0;
		foreach ( $object_ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( $order ) {
				$order->update_status( self::SLUG_UNPREFIXED, __( 'Marked as withdrawal requested (bulk).', 'wwu-withdrawal-button' ) );
				++$changed;
			}
		}
		return add_query_arg( 'webwakeupwdb_marked', $changed, $redirect );
	}
}
