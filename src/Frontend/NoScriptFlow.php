<?php
/**
 * Server-rendered no-JavaScript fallback for the two-step withdrawal flow.
 *
 * The JS controller is a progressive enhancement (it preventDefaults the form);
 * with JS disabled the form POSTs to admin-post.php and this handler renders a
 * server-side Step-2 confirmation page, then confirms. This keeps the statutory
 * withdrawal function "easily accessible" (Art. 11a(1)) even without scripting.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Frontend;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Domain\WithdrawalRequest;
use WWU\WithdrawalButton\Platform\NormalizedOrder;
use WWU\WithdrawalButton\Platform\OrderDataSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No-JS withdrawal flow.
 */
final class NoScriptFlow {

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	private const NONCE = 'wwu_wb_noscript';

	/**
	 * Wire admin-post handlers (logged-in + guest).
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( array( 'statement', 'confirm' ) as $step ) {
			add_action( 'admin_post_wwu_wb_noscript_' . $step, array( $this, 'handle_' . $step ) );
			add_action( 'admin_post_nopriv_wwu_wb_noscript_' . $step, array( $this, 'handle_' . $step ) );
		}
	}

	/**
	 * Step 1 (no-JS): record the statement and render the confirmation page.
	 *
	 * @return void
	 */
	public function handle_statement(): void {
		$this->enforce_rate_limit();
		$this->verify_nonce();
		$ctx = $this->resolve();
		list( $adapter, $order ) = $ctx;

		/* phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized inside from_input(). */
		$raw_products = isset( $_POST['products'] ) && is_array( $_POST['products'] ) ? wp_unslash( $_POST['products'] ) : array();
		/* phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized */
		$req = WithdrawalRequest::from_input(
			array(
				'name'      => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
				'order_ref' => $order->order_ref,
				'email'     => isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : $order->email,
				'reason'    => isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '',
				'products'  => $raw_products,
			)
		);
		if ( ! $req->is_valid() ) {
			$this->render_page(
				__( 'Withdrawal', 'wwu-withdrawal-button' ),
				'<p>' . esc_html__( 'Please provide your name and a valid email address.', 'wwu-withdrawal-button' ) . '</p>'
			);
			return;
		}

		$result = Services::instance()->withdrawal->submit_statement( $adapter, $order, $req );

		$locale  = '' !== $order->locale ? $order->locale : determine_locale();
		$confirm = Services::instance()->labels->confirm_label( $order->country, $locale );

		$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '';

		$body  = '<p>' . esc_html__( 'Please confirm your withdrawal. This is the final step.', 'wwu-withdrawal-button' ) . '</p>';
		$body .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$body .= '<input type="hidden" name="action" value="wwu_wb_noscript_confirm" />';
		$body .= '<input type="hidden" name="order_ref" value="' . esc_attr( $order->order_ref ) . '" />';
		$body .= '<input type="hidden" name="request_uid" value="' . esc_attr( $result['request_uid'] ) . '" />';
		$body .= '<input type="hidden" name="confirm_token" value="' . esc_attr( $result['confirm_token'] ) . '" />';
		$body .= '<input type="hidden" name="name" value="' . esc_attr( $req->name ) . '" />';
		$body .= '<input type="hidden" name="email" value="' . esc_attr( $req->email ) . '" />';
		$body .= '<input type="hidden" name="reason" value="' . esc_attr( $req->reason ) . '" />';
		foreach ( $req->products as $product_name ) {
			$body .= '<input type="hidden" name="products[]" value="' . esc_attr( $product_name ) . '" />';
		}
		$body .= '<input type="hidden" name="key" value="' . esc_attr( $key ) . '" />';
		$body .= '<input type="hidden" name="access_token" value="' . esc_attr( $token ) . '" />';
		$body .= wp_nonce_field( self::NONCE, '_wpnonce', true, false );
		$body .= '<button type="submit" class="wwu-wb-button" data-no-translation>' . esc_html( $confirm ) . '</button>';
		$body .= '</form>';

		$this->render_page( $confirm, $body );
	}

	/**
	 * Step 2 (no-JS): confirm the withdrawal and render the result.
	 *
	 * @return void
	 */
	public function handle_confirm(): void {
		$this->enforce_rate_limit();
		$this->verify_nonce();
		$ctx = $this->resolve();
		list( $adapter, $order ) = $ctx;

		/* phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized inside from_input(). */
		$raw_products_confirm = isset( $_POST['products'] ) && is_array( $_POST['products'] ) ? wp_unslash( $_POST['products'] ) : array();
		/* phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized */
		$req = WithdrawalRequest::from_input(
			array(
				'name'      => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
				'order_ref' => $order->order_ref,
				'email'     => isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : $order->email,
				'reason'    => isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '',
				'products'  => $raw_products_confirm,
			)
		);
		$request_uid = isset( $_POST['request_uid'] ) ? sanitize_text_field( wp_unslash( $_POST['request_uid'] ) ) : '';
		$token       = isset( $_POST['confirm_token'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_token'] ) ) : '';

		$result = Services::instance()->withdrawal->confirm( $adapter, $order, $req, $request_uid, $token );

		if ( is_wp_error( $result ) ) {
			$this->render_page( __( 'Withdrawal', 'wwu-withdrawal-button' ), '<p>' . esc_html( $result->get_error_message() ) . '</p>' );
			return;
		}

		$this->render_page(
			__( 'Withdrawal registered', 'wwu-withdrawal-button' ),
			'<p>' . esc_html__( 'Your withdrawal has been registered. We have emailed you a confirmation on a durable medium.', 'wwu-withdrawal-button' ) . '</p>'
		);
	}

	/**
	 * Throttle the public no-JS handlers per IP (shared bucket with the REST flow),
	 * rendering a generic notice + exiting when the limit is exceeded.
	 *
	 * @return void
	 */
	private function enforce_rate_limit(): void {
		if ( ! GuestAccess::check_rate_limit() ) {
			$this->render_page(
				__( 'Withdrawal', 'wwu-withdrawal-button' ),
				'<p>' . esc_html__( 'Too many attempts. Please try again in a few minutes.', 'wwu-withdrawal-button' ) . '</p>'
			);
		}
	}

	/**
	 * Verify the nonce or die.
	 *
	 * @return void
	 */
	private function verify_nonce(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the form and try again.', 'wwu-withdrawal-button' ), 403 );
		}
	}

	/**
	 * Resolve adapter + order and verify access, or die.
	 *
	 * @return array{0:OrderDataSource,1:NormalizedOrder}
	 */
	private function resolve(): array {
		$order_ref = isset( $_POST['order_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['order_ref'] ) ) : '';
		$adapter   = Services::instance()->platforms->resolve_for_order( $order_ref );
		$order     = $adapter ? $adapter->get_order( $order_ref ) : null;
		if ( ! $adapter || ! $order || ! $this->has_access( $adapter, $order ) ) {
			wp_die( esc_html__( 'Invalid request.', 'wwu-withdrawal-button' ), 403 );
		}
		return array( $adapter, $order );
	}

	/**
	 * Whether the caller may act on the order (logged-in / key / access token).
	 *
	 * @param OrderDataSource $adapter Adapter.
	 * @param NormalizedOrder $order   Order.
	 * @return bool
	 */
	private function has_access( OrderDataSource $adapter, NormalizedOrder $order ): bool {
		$user_id = get_current_user_id();
		if ( $user_id > 0 && $adapter->verify_owner( $order->order_ref, $user_id ) ) {
			return true;
		}
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( '' !== $key && $adapter->verify_guest_key( $order->order_ref, $key ) ) {
			return true;
		}
		$token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '';
		if ( '' !== $token && GuestAccess::verify( $order->order_ref, $order->email, $token ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Render a minimal standalone HTML page (admin-post has no theme) and exit.
	 *
	 * @param string $title Page title.
	 * @param string $body  Body HTML (already escaped).
	 * @return void
	 */
	private function render_page( string $title, string $body ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		$css = '.wwu-wb-np{max-width:560px;margin:6vh auto;padding:0 20px;font-family:Arial,Helvetica,sans-serif;color:#222;}.wwu-wb-button{display:inline-block;padding:.7em 1.4em;background:#1a1f3a;color:#fff;border:0;border-radius:5px;font-size:1rem;cursor:pointer;text-decoration:none;}';
		// Apply the merchant's custom CSS to the no-JS pages too (consistent branding).
		$custom = (string) ( \WWU\WithdrawalButton\Core\Settings::main()['custom_css'] ?? '' );
		if ( '' !== $custom ) {
			$css .= "\n" . \WWU\WithdrawalButton\Security\Sanitizer::css( $custom );
		}
		echo '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $title ) . '</title><style>' . $css . '</style></head><body><div class="wwu-wb-np"><h1>' . esc_html( $title ) . '</h1>' . $body . '<p style="margin-top:2em;"><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the site', 'wwu-withdrawal-button' ) . '</a></p></div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body is pre-escaped; $css is a static literal.
		exit;
	}
}
