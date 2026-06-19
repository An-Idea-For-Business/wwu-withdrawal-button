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
use WWU\WithdrawalButton\Frontend\ExemptionNoteRenderer;
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

		// If a request already exists, show a localized status notice instead of the
		// button (shared label — never the raw internal status).
		$status_label = EligibleOrders::request_status_label( $adapter, $order->order_ref );
		if ( '' !== $status_label ) {
			echo '<p class="wwu-wb-status-notice">' . esc_html( $status_label ) . '</p>';
			return;
		}

		/*
		 * Call decide() directly here (not should_show()) so we can inspect ->reason
		 * and emit the exemption transparency note when the right is removed by Art. 59.
		 */
		if ( ! \WWU\WithdrawalButton\Core\Settings::enabled() ) {
			return;
		}
		$decision = Services::instance()->applicability->decide( $order );
		if ( ! $decision->show ) {
			if ( 'no_withdrawal_right' === $decision->reason ) {
				$note = ExemptionNoteRenderer::render( $order );
				if ( '' !== $note ) {
					echo $note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ExemptionNoteRenderer builds safe HTML.
				}
			}
			return;
		}
		echo $this->render_button( $order, $adapter, $wc_order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes.
	}

	/**
	 * Render the withdrawal button (template).
	 *
	 * @param NormalizedOrder $order    Order.
	 * @param OrderDataSource $adapter  Adapter.
	 * @param mixed           $wc_order WC_Order when available (carries the order key for the guest link).
	 * @return string
	 */
	public function render_button( NormalizedOrder $order, OrderDataSource $adapter, $wc_order = null ): string {
		$services = Services::instance();
		$locale   = $this->locale( $order );
		return Template::render(
			'button/withdrawal-button.php',
			array(
				'url'           => $this->resolve_form_url( $order, $wc_order ),
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
				'items'          => $order->items,
			)
		);
	}

	/**
	 * Render the customer's withdrawal-relevant orders (eligible + already
	 * requested). Delegates to the shared EligibleOrders builder so the account
	 * tab and the public form page show the same list.
	 *
	 * @param int             $user_id User ID.
	 * @param OrderDataSource $adapter Adapter.
	 * @return string
	 */
	private function render_request_list( int $user_id, OrderDataSource $adapter ): string {
		return EligibleOrders::render_for_user( $user_id );
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
	 * Resolve the withdrawal-form URL for the current viewer.
	 *
	 * Logged-in customers go to the My Account endpoint (owner-verified). Guests
	 * — who have no account and would otherwise be bounced to the login screen by
	 * wc_get_account_endpoint_url() — are routed to the public form page carrying
	 * the order reference + order key (the same pre-authenticated link the order
	 * confirmation email uses, see OrderEmailLink). Falls back to the account
	 * endpoint when no public form page is configured.
	 *
	 * @param NormalizedOrder $order    Order.
	 * @param mixed           $wc_order WC_Order when available (carries the key).
	 * @return string
	 */
	private function resolve_form_url( NormalizedOrder $order, $wc_order = null ): string {
		if ( is_user_logged_in() ) {
			return $this->form_url( $order->order_ref );
		}
		$guest = $this->guest_form_url( $order->order_ref, $wc_order );
		return '' !== $guest ? $guest : $this->form_url( $order->order_ref );
	}

	/**
	 * Build the public-page withdrawal URL for a guest (no account).
	 *
	 * Mirrors OrderEmailLink: the link carries the order reference + WooCommerce
	 * order key so the public form page can authenticate the guest via
	 * verify_guest_key() without a login. Returns '' when no public form page is
	 * configured (the caller then falls back to the account endpoint).
	 *
	 * @param string $order_ref Order reference.
	 * @param mixed  $wc_order  WC_Order when available (carries the key).
	 * @return string
	 */
	private function guest_form_url( string $order_ref, $wc_order = null ): string {
		$page_id = (int) ( \WWU\WithdrawalButton\Core\Settings::main()['public_form_page_id'] ?? 0 );
		if ( $page_id <= 0 ) {
			return '';
		}
		$args = array( 'wwu_wb_order' => rawurlencode( $order_ref ) );
		if ( $wc_order instanceof \WC_Order ) {
			$args['key'] = rawurlencode( (string) $wc_order->get_order_key() );
		}
		return add_query_arg( $args, get_permalink( $page_id ) );
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
