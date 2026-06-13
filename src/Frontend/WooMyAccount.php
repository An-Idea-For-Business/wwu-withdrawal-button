<?php
/**
 * WooCommerce My Account surfaces for the withdrawal function.
 *
 * Adds: a button on the Orders list, a button/notice on the single order view,
 * and a dedicated account endpoint tab that renders the two-step form for a
 * chosen order (and lists the customer's prior requests). The button is rendered
 * only when the applicability engine says so, with the statutory label resolved
 * per consumer country/locale.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Frontend;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Platform\NormalizedOrder;
use WWU\WithdrawalButton\Platform\OrderDataSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * My Account integration.
 */
final class WooMyAccount {

	/**
	 * Endpoint slug (configurable).
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$settings       = (array) get_option( 'wwu_wb_settings', array() );
		$this->endpoint = sanitize_title( (string) ( $settings['endpoint_slug'] ?? 'wwu-withdrawal' ) );
	}

	/**
	 * Wire hooks (frontend only, WooCommerce active).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ), 0 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . $this->endpoint . '_endpoint', array( $this, 'render_endpoint' ) );

		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'orders_list_action' ), 50, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_detail_button' ) );
	}

	/**
	 * Register the rewrite endpoint.
	 *
	 * @return void
	 */
	public function add_endpoint(): void {
		add_rewrite_endpoint( $this->endpoint, EP_ROOT | EP_PAGES );
	}

	/**
	 * Map the endpoint to a query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_var( array $vars ): array {
		$vars[ $this->endpoint ] = $this->endpoint;
		return $vars;
	}

	/**
	 * Add the account-menu item (before Logout).
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function add_menu_item( array $items ): array {
		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		if ( null !== $logout ) {
			unset( $items['customer-logout'] );
		}
		$items[ $this->endpoint ] = __( 'Right of withdrawal', 'wwu-withdrawal-button' );
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	/**
	 * Render the endpoint tab: the form for a chosen order, else the request list.
	 *
	 * @return void
	 */
	public function render_endpoint(): void {
		$adapter = $this->adapter();
		if ( ! $adapter ) {
			return;
		}

		$order_ref = isset( $_GET['wwu_wb_order'] ) ? sanitize_text_field( wp_unslash( $_GET['wwu_wb_order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id   = get_current_user_id();

		if ( '' !== $order_ref && $adapter->verify_owner( $order_ref, $user_id ) ) {
			$order = $adapter->get_order( $order_ref );
			if ( $order ) {
				echo $this->render_form( $order, $adapter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes.
				return;
			}
		}

		echo $this->render_request_list( $user_id, $adapter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes.
	}

	/**
	 * Add the withdrawal action to the Orders list row.
	 *
	 * @param array     $actions Row actions.
	 * @param \WC_Order $wc_order The order.
	 * @return array
	 */
	public function orders_list_action( array $actions, $wc_order ): array {
		$adapter = $this->adapter();
		if ( ! $adapter || ! $wc_order instanceof \WC_Order ) {
			return $actions;
		}
		$order = $adapter->get_order( (string) $wc_order->get_id() );
		if ( ! $order || ! $this->should_show( $order ) ) {
			return $actions;
		}
		$actions['wwu_wb'] = array(
			'url'  => $this->form_url( $order->order_ref ),
			'name' => Services::instance()->labels->withdraw_label( $order->country, $this->locale( $order ) ),
		);
		return $actions;
	}

	/**
	 * Inject the button on the single order view.
	 *
	 * @param mixed $wc_order The order (WC_Order on the account view).
	 * @return void
	 */
	public function order_detail_button( $wc_order ): void {
		$adapter = $this->adapter();
		if ( ! $adapter || ! $wc_order instanceof \WC_Order ) {
			return;
		}
		$order = $adapter->get_order( (string) $wc_order->get_id() );
		if ( ! $order ) {
			return;
		}

		// If a request already exists, show a status notice instead of the button.
		$status = (string) $adapter->get_meta( $order->order_ref, 'status' );
		if ( '' !== $status ) {
			echo '<p class="wwu-wb-status-notice">' . esc_html(
				sprintf(
					/* translators: %s: status. */
					__( 'Withdrawal request status: %s', 'wwu-withdrawal-button' ),
					$status
				)
			) . '</p>';
			return;
		}

		if ( ! $this->should_show( $order ) ) {
			return;
		}
		echo $this->render_button( $order, $adapter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes.
	}

	/**
	 * Render the withdrawal button (template).
	 *
	 * @param NormalizedOrder $order   Order.
	 * @param OrderDataSource $adapter Adapter.
	 * @return string
	 */
	public function render_button( NormalizedOrder $order, OrderDataSource $adapter ): string {
		$services = Services::instance();
		$locale   = $this->locale( $order );
		return Template::render(
			'button/withdrawal-button.php',
			array(
				'url'           => $this->form_url( $order->order_ref ),
				'label'         => $services->labels->withdraw_label( $order->country, $locale ),
				'days_remaining'=> $services->window->days_remaining( $order ),
			)
		);
	}

	/**
	 * Render the two-step form (template).
	 *
	 * @param NormalizedOrder $order   Order.
	 * @param OrderDataSource $adapter Adapter.
	 * @return string
	 */
	public function render_form( NormalizedOrder $order, OrderDataSource $adapter ): string {
		$services = Services::instance();
		$locale   = $this->locale( $order );
		$user     = wp_get_current_user();
		return Template::render(
			'form/withdrawal-form.php',
			array(
				'order_ref'      => $order->order_ref,
				'order_number'   => $order->number,
				'name'           => $user->exists() ? $user->display_name : '',
				'email'          => $order->email,
				'withdraw_label' => $services->labels->withdraw_label( $order->country, $locale ),
				'confirm_label'  => $services->labels->confirm_label( $order->country, $locale ),
				'days_remaining' => $services->window->days_remaining( $order ),
			)
		);
	}

	/**
	 * Render the customer's withdrawal-request list.
	 *
	 * @param int             $user_id User ID.
	 * @param OrderDataSource $adapter Adapter.
	 * @return string
	 */
	private function render_request_list( int $user_id, OrderDataSource $adapter ): string {
		return Template::render( 'myaccount/withdrawal-list.php', array() );
	}

	/**
	 * Whether the button should be shown for this order.
	 *
	 * @param NormalizedOrder $order Order.
	 * @return bool
	 */
	private function should_show( NormalizedOrder $order ): bool {
		if ( ! \WWU\WithdrawalButton\Core\Settings::enabled() ) {
			return false;
		}
		// The status-eligibility gate (no button on failed/cancelled/refunded
		// orders) is applied centrally inside ApplicabilityResolver::decide().
		return Services::instance()->applicability->decide( $order )->show;
	}

	/**
	 * Build the URL of the form (account endpoint with the order ref).
	 *
	 * @param string $order_ref Order reference.
	 * @return string
	 */
	private function form_url( string $order_ref ): string {
		return add_query_arg( 'wwu_wb_order', rawurlencode( $order_ref ), wc_get_account_endpoint_url( $this->endpoint ) );
	}

	/**
	 * Resolve the active WooCommerce adapter.
	 *
	 * @return OrderDataSource|null
	 */
	private function adapter(): ?OrderDataSource {
		return Services::instance()->platforms->get( 'woocommerce' );
	}

	/**
	 * Determine the display locale for an order (order locale → site locale).
	 *
	 * @param NormalizedOrder $order Order.
	 * @return string
	 */
	private function locale( NormalizedOrder $order ): string {
		return '' !== $order->locale ? $order->locale : determine_locale();
	}
}
