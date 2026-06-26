<?php
/**
 * Shortcodes for placing and customising the withdrawal surfaces.
 *
 *   [wwu_wb_button order_id="" label="" style=""]
 *   [wwu_wb_form order_id="" ]            (reads ?wwu_wb_order + ?key for guests)
 *   [wwu_wb_status order_id=""]
 *   [wwu_wb_model_form lang=""]           (Annex I-B model form)
 *   [wwu_wb_info type="precontractual|terms|privacy" lang=""]
 *
 * Order-scoped shortcodes pass through an ownership/token access check and never
 * leak another customer's order; failures render nothing.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Shortcodes;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Frontend\ExemptionNoteRenderer;
use WWU\WithdrawalButton\Frontend\GuestAccess;
use WWU\WithdrawalButton\Frontend\Template;
use WWU\WithdrawalButton\Frontend\WithdrawalUrl;
use WWU\WithdrawalButton\Legal\ClauseLibrary;
use WWU\WithdrawalButton\Legal\ModelForm;
use WWU\WithdrawalButton\Platform\NormalizedOrder;
use WWU\WithdrawalButton\Platform\OrderDataSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode registrar.
 */
final class Shortcodes {

	/**
	 * Register all shortcodes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'wwu_wb_button', array( $this, 'button' ) );
		add_shortcode( 'wwu_wb_form', array( $this, 'form' ) );
		add_shortcode( 'wwu_wb_status', array( $this, 'status' ) );
		add_shortcode( 'wwu_wb_model_form', array( $this, 'model_form' ) );
		add_shortcode( 'wwu_wb_info', array( $this, 'info' ) );
		add_shortcode( 'wwu_wb_policy', array( $this, 'policy' ) );
	}

	/**
	 * [wwu_wb_policy] — render the consolidated "Right of withdrawal — information
	 * notice", assembled live from the merchant's settings + selected Art. 59
	 * exemptions. Carries the global "complements, not replaces / have your lawyer
	 * review it" disclaimer. Optional atts: lang (override), sections (csv allow-list).
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function policy( $atts ): string {
		$atts = shortcode_atts( array( 'lang' => '', 'sections' => '' ), (array) $atts, 'wwu_wb_policy' );
		$opts = array();
		if ( '' !== (string) $atts['sections'] ) {
			$opts['sections'] = array_values( array_filter( array_map( 'trim', explode( ',', (string) $atts['sections'] ) ) ) );
		}
		$doc = \WWU\WithdrawalButton\Legal\PolicyBuilder::build( sanitize_text_field( (string) $atts['lang'] ), $opts );

		return '<div class="wwu-wb-policy-wrap">' . \WWU\WithdrawalButton\Legal\PolicyBuilder::disclaimer_html() . $doc->to_html() . '</div>';
	}

	/**
	 * [wwu_wb_button] — render the withdrawal button for an order.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function button( $atts ): string {
		$atts = shortcode_atts( array( 'order_id' => '' ), (array) $atts, 'wwu_wb_button' );
		$ctx  = $this->resolve_order_access( sanitize_text_field( (string) $atts['order_id'] ) );
		if ( ! $ctx ) {
			return '';
		}
		list( $adapter, $order ) = $ctx;
		if ( ! Services::instance()->applicability->decide( $order )->show ) {
			return '';
		}
		$services = Services::instance();
		$locale   = $this->locale( $order );
		return Template::render(
			'button/withdrawal-button.php',
			array(
				'url'            => $this->form_url( $order ),
				'label'          => $services->labels->withdraw_label( $order->country, $locale ),
				'days_remaining' => $services->window->days_remaining( $order ),
			)
		);
	}

	/**
	 * [wwu_wb_form] — render the two-step withdrawal form.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function form( $atts ): string {
		$atts      = shortcode_atts( array( 'order_id' => '' ), (array) $atts, 'wwu_wb_form' );
		$order_ref = sanitize_text_field( (string) $atts['order_id'] );
		if ( '' === $order_ref && isset( $_GET['wwu_wb_order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_ref = sanitize_text_field( wp_unslash( $_GET['wwu_wb_order'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		// No specific order in context: instead of an unhelpful error, show the
		// logged-in customer their withdrawal-relevant orders to choose from (and
		// guide guests to the email link). A failed access for a *given* order ref
		// still returns the not-found notice below.
		if ( '' === $order_ref ) {
			return \WWU\WithdrawalButton\Frontend\EligibleOrders::render_for_user( get_current_user_id() );
		}

		$ctx = $this->resolve_order_access( $order_ref );
		if ( ! $ctx ) {
			return '<p class="wwu-wb-notice">' . esc_html__( 'Order not found or access could not be verified.', 'wwu-withdrawal-button' ) . '</p>';
		}
		list( $adapter, $order ) = $ctx;

		// Close the direct-shortcode path: a renewal order (or any order the
		// applicability engine excludes) must not render the two-step form even when
		// reached via [wwu_wb_form order_id=…]. The button surfaces already gate on
		// applicability; this matches them.
		$decision = Services::instance()->applicability->decide( $order );
		if ( ! $decision->show ) {
			/*
			 * When the reason is a confirmed Art. 59 exemption, show the transparency
			 * note naming the applicable exception(s) — more informative than a generic
			 * "not available" message. For any other reason (out-of-scope country, B2B
			 * order, ineligible status …) keep the generic notice.
			 */
			if ( 'no_withdrawal_right' === $decision->reason ) {
				$note = ExemptionNoteRenderer::render( $order );
				if ( '' !== $note ) {
					return $note;
				}
			}
			return '<p class="wwu-wb-notice">' . esc_html__( 'The right of withdrawal is not available for this order.', 'wwu-withdrawal-button' ) . '</p>';
		}

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
	 * [wwu_wb_status] — show the status of an order's withdrawal request.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function status( $atts ): string {
		$atts = shortcode_atts( array( 'order_id' => '' ), (array) $atts, 'wwu_wb_status' );
		$ctx  = $this->resolve_order_access( sanitize_text_field( (string) $atts['order_id'] ) );
		if ( ! $ctx ) {
			return '';
		}
		list( $adapter, $order ) = $ctx;
		$status = (string) $adapter->get_meta( $order->order_ref, 'status' );
		if ( '' === $status ) {
			return '<p class="wwu-wb-notice">' . esc_html__( 'No withdrawal request for this order.', 'wwu-withdrawal-button' ) . '</p>';
		}
		return '<p class="wwu-wb-status-notice">' . esc_html(
			sprintf(
				/* translators: %s: status. */
				__( 'Withdrawal request status: %s', 'wwu-withdrawal-button' ),
				$status
			)
		) . '</p>';
	}

	/**
	 * [wwu_wb_model_form] — render the Annex I-B model withdrawal form.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function model_form( $atts ): string {
		$atts = shortcode_atts( array( 'lang' => '' ), (array) $atts, 'wwu_wb_model_form' );
		$lang = '' !== $atts['lang'] ? sanitize_key( (string) $atts['lang'] ) : determine_locale();
		return Template::render( 'legal/model-form.php', array( 'form' => ModelForm::for_language( $lang ) ) );
	}

	/**
	 * [wwu_wb_info] — render a pre-contractual / terms / privacy clause.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function info( $atts ): string {
		$atts = shortcode_atts( array( 'type' => 'precontractual', 'lang' => '' ), (array) $atts, 'wwu_wb_info' );
		$type = sanitize_key( (string) $atts['type'] );
		$lang = '' !== $atts['lang'] ? sanitize_key( (string) $atts['lang'] ) : determine_locale();
		$text = ClauseLibrary::get( $type, $lang );
		if ( '' === $text ) {
			return '';
		}
		return '<div class="wwu-wb-info">' . wpautop( esc_html( $text ) ) . '</div>';
	}

	/**
	 * Resolve an order + verify the caller may access it.
	 *
	 * @param string $order_ref Order reference.
	 * @return array{0:OrderDataSource,1:NormalizedOrder}|null
	 */
	private function resolve_order_access( string $order_ref ): ?array {
		if ( '' === $order_ref ) {
			return null;
		}
		$adapter = Services::instance()->platforms->resolve_for_order( $order_ref );
		if ( ! $adapter ) {
			return null;
		}
		$order = $adapter->get_order( $order_ref );
		if ( ! $order ) {
			return null;
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 && $adapter->verify_owner( $order_ref, $user_id ) ) {
			return array( $adapter, $order );
		}
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $key && $adapter->verify_guest_key( $order_ref, $key ) ) {
			return array( $adapter, $order );
		}
		$access = isset( $_GET['access_token'] ) ? sanitize_text_field( wp_unslash( $_GET['access_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $access && GuestAccess::verify( $order_ref, $order->email, $access ) ) {
			return array( $adapter, $order );
		}
		return null;
	}

	/**
	 * Build the withdrawal-form URL for an order, viewer-aware.
	 *
	 * Delegates to the shared WithdrawalUrl helper: logged-in customers get the
	 * My Account endpoint, guests get the public form page (no login) carrying the
	 * order key. See WithdrawalUrl::resolve().
	 *
	 * @param NormalizedOrder $order Order.
	 * @return string
	 */
	private function form_url( NormalizedOrder $order ): string {
		return WithdrawalUrl::resolve( $order->order_ref );
	}

	/**
	 * Display locale for an order.
	 *
	 * @param NormalizedOrder $order Order.
	 * @return string
	 */
	private function locale( NormalizedOrder $order ): string {
		return '' !== $order->locale ? $order->locale : determine_locale();
	}
}
