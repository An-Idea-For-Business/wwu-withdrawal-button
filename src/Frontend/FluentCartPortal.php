<?php
/**
 * FluentCart customer-portal integration.
 *
 * FluentCart ships a Vue single-page customer portal, but it is fully extensible
 * from PHP through documented server-side hooks. Every shape below was verified
 * against the official docs (dev.fluentcart.com/hooks/filters/customers-and-subscriptions
 * and .../orders-and-payments) so a FluentCart customer gets the same withdrawal
 * experience a WooCommerce customer gets:
 *
 *  - `fluent_cart/customer_portal/custom_endpoints` (1 arg) → a dedicated
 *    "Right of withdrawal" portal page. The endpoint is keyed by its slug (the
 *    array index) and its only key is `render_callback`, a callable that ECHOES
 *    its HTML (FluentCart ignores the return value).
 *  - `fluent_cart/global_customer_menu_items` (2 args) → the sidebar entry,
 *    keyed by slug with exactly label / css_class ('fct_route' for SPA routing) /
 *    link / icon_svg.
 *  - `fluent_cart/customer_dashboard_data` (2 args) → a reassuring banner in the
 *    `sections_parts.before_orders_table` slot.
 *  - `fluent_cart/customer/order_details_section_parts` (2 args) → the withdrawal
 *    button in the `after_summary` slot of a single order ($context['order']).
 *
 * A custom email merge tag was intentionally NOT wired: the filter name we first
 * used (`fluent_cart/email_notification_merge_tags`) does not exist in the official
 * docs, and `fluent_cart/editor_shortcodes` only populates the editor picker with
 * no documented value-resolver — so a tag would render literally in sent mail.
 * The standalone `[webwakeupwdb_form]` page stays available as a universal fallback.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Frontend;

use WebWakeUpWdb\WithdrawalButton\Core\Services;
use WebWakeUpWdb\WithdrawalButton\Core\Settings;
use WebWakeUpWdb\WithdrawalButton\Frontend\ExemptionNoteRenderer;
use WebWakeUpWdb\WithdrawalButton\Platform\NormalizedOrder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentCart portal integration.
 */
final class FluentCartPortal {

	/**
	 * Portal endpoint key (slug used by FluentCart to route to our page).
	 *
	 * @var string
	 */
	private const ENDPOINT_KEY = 'wwu-withdrawal';

	/**
	 * Raw SVG for the portal menu icon (FluentCart's item shape wants 'icon_svg').
	 *
	 * @var string
	 */
	private const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 5 5a5 5 0 0 1-5 5H9"/></svg>';

	/**
	 * Wire every FluentCart customer-portal hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'fluent_cart/customer_portal/custom_endpoints', array( $this, 'register_endpoint' ), 10, 1 );
		add_filter( 'fluent_cart/global_customer_menu_items', array( $this, 'add_menu_item' ), 10, 2 );
		add_filter( 'fluent_cart/customer_dashboard_data', array( $this, 'add_dashboard_banner' ), 10, 2 );
		add_filter( 'fluent_cart/customer/order_details_section_parts', array( $this, 'inject' ), 10, 2 );

		// The portal page renders our CSS/JS-dependent chooser; force the bundle to
		// load on it (the standard context gate cannot see the FluentCart portal).
		add_filter( 'webwakeupwdb_force_enqueue_frontend', array( $this, 'maybe_enqueue_on_portal' ), 10, 1 );
	}

	/**
	 * Register a "Right of withdrawal" endpoint inside the FluentCart portal.
	 *
	 * @param mixed $endpoints Existing endpoint definitions (array expected).
	 * @return array
	 */
	public function register_endpoint( $endpoints ): array {
		$endpoints = is_array( $endpoints ) ? $endpoints : array();

		if ( ! Settings::enabled() ) {
			return $endpoints;
		}

		// Official contract (dev.fluentcart.com/hooks/filters/customers-and-subscriptions):
		// the slug is the ARRAY KEY and the only documented definition key is
		// 'render_callback' (a callable that ECHOES its output). The matching portal
		// menu item is registered separately via global_customer_menu_items.
		$endpoints[ self::ENDPOINT_KEY ] = array(
			'render_callback' => array( $this, 'render_endpoint' ),
		);
		return $endpoints;
	}

	/**
	 * Render the portal endpoint: the customer's order chooser + form.
	 *
	 * FluentCart injects the returned HTML into its SPA shell. We make sure our
	 * frontend bundle is present (the portal page bypasses the standard gate).
	 *
	 * @param mixed ...$args Optional context passed by FluentCart (unused).
	 * @return void
	 */
	public function render_endpoint( ...$args ): void {
		( new Assets() )->ensure();

		$heading = '<h2 class="webwakeupwdb-portal__title">' . esc_html__( 'Right of withdrawal', 'wwu-withdrawal-button' ) . '</h2>';
		$chooser = EligibleOrders::render_for_user( get_current_user_id() );

		// FluentCart ECHOES the endpoint output (its return value is ignored), so we
		// echo. The heading is escaped above; EligibleOrders escapes its own output.
		echo '<div class="webwakeupwdb-portal">' . $heading . $chooser . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- heading escaped above; chooser escapes internally.
	}

	/**
	 * Add a sidebar menu item that links to the withdrawal endpoint.
	 *
	 * @param mixed $menu_items Existing menu items (array expected).
	 * @param mixed $context    Portal context (may expose base_url).
	 * @return array
	 */
	public function add_menu_item( $menu_items, $context = null ): array {
		$menu_items = is_array( $menu_items ) ? $menu_items : array();

		if ( ! Settings::enabled() ) {
			return $menu_items;
		}

		// Official item shape (dev.fluentcart.com/hooks/filters/customers-and-subscriptions):
		// keyed by slug, with exactly label / css_class / link / icon_svg. The
		// css_class 'fct_route' makes the portal SPA navigate to our custom endpoint
		// client-side instead of doing a hard page reload.
		$menu_items[ self::ENDPOINT_KEY ] = array(
			'label'     => __( 'Right of withdrawal', 'wwu-withdrawal-button' ),
			'css_class' => 'fct_route',
			'link'      => $this->endpoint_url( $context ),
			'icon_svg'  => self::ICON_SVG,
		);

		return $menu_items;
	}

	/**
	 * Add a reassuring banner above the customer's orders table.
	 *
	 * @param mixed $data    Dashboard data (array expected).
	 * @param mixed $context Dashboard context (exposes 'customer'); 2 args per docs.
	 * @return array
	 */
	public function add_dashboard_banner( $data, $context = null ): array {
		$data = is_array( $data ) ? $data : array();

		if ( ! Settings::enabled() ) {
			return $data;
		}

		// The dashboard context exposes the customer, not base_url, so the CTA points
		// at the standalone withdrawal page (always a real, asset-loaded URL).
		$url  = $this->endpoint_url( $context );
		$text = esc_html__( 'Changed your mind? You can exercise your right of withdrawal on eligible orders.', 'wwu-withdrawal-button' );
		$cta  = esc_html__( 'Open the withdrawal page', 'wwu-withdrawal-button' );

		$banner  = '<div class="webwakeupwdb-portal-banner">';
		$banner .= '<p>' . $text . ' ';
		$banner .= '<a class="webwakeupwdb-portal-banner__link" href="' . esc_url( $url ) . '">' . $cta . '</a></p>';
		$banner .= '</div>';

		if ( ! isset( $data['sections_parts'] ) || ! is_array( $data['sections_parts'] ) ) {
			$data['sections_parts'] = array();
		}
		$existing = isset( $data['sections_parts']['before_orders_table'] ) ? (string) $data['sections_parts']['before_orders_table'] : '';
		$data['sections_parts']['before_orders_table'] = $existing . $banner;

		return $data;
	}

	/**
	 * Inject the withdrawal button into a single order's details (after summary).
	 *
	 * @param mixed $sections Named HTML slots (array expected).
	 * @param mixed $context  Context — expected to expose the order model.
	 * @return array
	 */
	public function inject( $sections, $context = null ): array {
		$sections = (array) $sections;

		// FluentCart handling can be off, or auto-deferred to FluentCart's own
		// withdrawal add-on — then render no portal button (avoid a duplicate).
		if ( ! \WebWakeUpWdb\WithdrawalButton\Platform\FluentCartAdapter::should_render() ) {
			return $sections;
		}

		$order_ref = $this->order_ref_from_context( $context );
		if ( '' === $order_ref ) {
			return $sections;
		}

		$adapter = Services::instance()->platforms->get( 'fluentcart' );
		if ( ! $adapter ) {
			return $sections;
		}
		$order = $adapter->get_order( $order_ref );
		if ( ! $order ) {
			return $sections;
		}

		// If a withdrawal request already exists for this order, show its (localized)
		// status instead of the button — never offer to withdraw a second time.
		$status_label = EligibleOrders::request_status_label( $adapter, $order->order_ref );
		if ( '' !== $status_label ) {
			$notice = '<p class="webwakeupwdb-status-notice">' . esc_html( $status_label ) . '</p>';
			$sections['after_summary'] = ( $sections['after_summary'] ?? '' ) . $notice;
			return $sections;
		}

		if ( ! Settings::enabled() ) {
			return $sections;
		}
		$decision = Services::instance()->applicability->decide( $order );
		if ( ! $decision->show ) {
			/*
			 * When the reason is a confirmed Art. 59 exemption, append the transparency
			 * note into after_summary so the consumer knows why the button is absent.
			 */
			if ( 'no_withdrawal_right' === $decision->reason ) {
				$note = ExemptionNoteRenderer::render( $order );
				if ( '' !== $note ) {
					$sections['after_summary'] = ( $sections['after_summary'] ?? '' ) . $note;
				}
			}
			return $sections;
		}

		// `after_summary` is the FluentCart-recommended slot for per-order CTAs.
		$html = $this->button_html( $order );
		$sections['after_summary'] = ( $sections['after_summary'] ?? '' ) . $html;
		return $sections;
	}

	/**
	 * Force-load the frontend bundle on the FluentCart portal page.
	 *
	 * Detection is best-effort: FluentCart renders its portal via its own
	 * shortcode/block on a normal WordPress page. When that marker is present we
	 * opt in; otherwise we leave the decision to the standard gate.
	 *
	 * @param mixed $enqueue Current decision.
	 * @return bool
	 */
	public function maybe_enqueue_on_portal( $enqueue ): bool {
		if ( $enqueue ) {
			return true;
		}
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		$content = (string) $post->post_content;
		foreach ( array( 'fluent_cart_customer_portal', 'fluentcart_customer_portal', 'fluent-cart/customer-portal', 'fluent_cart/customer-portal' ) as $needle ) {
			if ( false !== strpos( $content, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the button HTML for a single order in the portal.
	 *
	 * @param NormalizedOrder $order Order.
	 * @return string
	 */
	private function button_html( NormalizedOrder $order ): string {
		$services = Services::instance();
		$locale   = '' !== $order->locale ? $order->locale : determine_locale();

		$url = $this->standalone_page_url();
		$url = '' !== $url
			? add_query_arg( 'webwakeupwdb_order', rawurlencode( $order->order_ref ), $url )
			: '#';

		return Template::render(
			'button/withdrawal-button.php',
			array(
				'url'            => $url,
				'label'          => $services->labels->withdraw_label( $order->country, $locale ),
				'days_remaining' => $services->window->days_remaining( $order ),
				// FluentCart portal is a Vue SPA where the plugin stylesheet may not
				// load — render the button with self-contained inline styles.
				'inline'         => true,
			)
		);
	}

	/**
	 * URL of the in-portal withdrawal endpoint.
	 *
	 * Prefers a base URL supplied by FluentCart in the portal context; falls back
	 * to the standalone public form page so the link is never dead.
	 *
	 * @param mixed $context Portal context (may expose base_url).
	 * @return string
	 */
	private function endpoint_url( $context ): string {
		$base = '';
		if ( is_array( $context ) && ! empty( $context['base_url'] ) ) {
			$base = (string) $context['base_url'];
		} elseif ( is_object( $context ) && isset( $context->base_url ) ) {
			$base = (string) $context->base_url;
		}

		if ( '' !== $base ) {
			return trailingslashit( $base ) . self::ENDPOINT_KEY;
		}

		$standalone = $this->standalone_page_url();
		return '' !== $standalone ? $standalone : home_url( '/' );
	}

	/**
	 * URL of the standalone public withdrawal page (settings: public_form_page_id).
	 *
	 * @return string
	 */
	private function standalone_page_url(): string {
		$settings = (array) get_option( 'webwakeupwdb_settings', array() );
		$page_id  = (int) ( $settings['public_form_page_id'] ?? 0 );
		if ( $page_id <= 0 ) {
			return '';
		}
		$permalink = get_permalink( $page_id );
		return is_string( $permalink ) ? $permalink : '';
	}

	/**
	 * Extract an order reference from the FluentCart context (defensive).
	 *
	 * The documented context for `order_details_section_parts` exposes the order
	 * model under the `order` key; we also tolerate `order_id`/`id` scalars.
	 *
	 * @param mixed $context Context.
	 * @return string
	 */
	private function order_ref_from_context( $context ): string {
		if ( is_array( $context ) ) {
			foreach ( array( 'order', 'order_id', 'id' ) as $k ) {
				if ( ! isset( $context[ $k ] ) ) {
					continue;
				}
				$v = $context[ $k ];
				if ( is_object( $v ) && isset( $v->id ) ) {
					return (string) $v->id;
				}
				if ( is_scalar( $v ) ) {
					return (string) $v;
				}
			}
		}
		if ( is_object( $context ) && isset( $context->id ) ) {
			return (string) $context->id;
		}
		return '';
	}
}
