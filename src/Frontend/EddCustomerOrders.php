<?php
/**
 * Easy Digital Downloads customer-facing withdrawal surfaces.
 *
 * The EDD counterpart of {@see WooMyAccount} / {@see FluentCartPortal}: it puts the
 * statutory withdrawal button on the EDD customer's own pages, so an EDD buyer gets
 * the same experience a WooCommerce/FluentCart buyer gets — not just the standalone
 * public page.
 *
 * EDD has no unified "My Account" with a routable endpoint (unlike WooCommerce), so
 * the button links to the plugin's standalone public form page
 * (settings: public_form_page_id), pre-authenticated with the order ref + EDD
 * payment key (?wwu_wb_order=&key=) exactly like the order-email link. Three surfaces,
 * all on EDD 3.x hooks verified against the official EDD source — see
 * docs/analysis/wwu-wb-edd-customer-surfaces-ANALYSIS.md:
 *
 *  - Purchase RECEIPT ([edd_receipt]) — `edd_order_receipt_after_table`
 *    (`templates/shortcode-receipt.php`), after the receipt table (block context, so
 *    the <div> button is valid and not foster-parented out of the table); args
 *    `( EDD\Orders\Order $order, array $args )`;
 *  - PURCHASE HISTORY ([purchase_history]) — `edd_order_history_row_end`
 *    (`templates/history-purchases.php`), end of each order row; arg
 *    `( EDD\Orders\Order $order )`;
 *  - Purchase RECEIPT EMAIL — `edd_order_receipt` filter (EDD 3.2.0+) appends the
 *    withdrawal link to the e-mail body (Recital 37 hyperlink), the canonical guest
 *    path; the legacy `edd_purchase_receipt` filter is also wired for EDD 3.0–3.1,
 *    de-duplicated so 3.2.0+ never appends twice.
 *
 * Every callback is defensive: it tolerates the EDD 3.0 Order object and a numeric
 * id, and renders nothing when the order is ineligible, already requested, or the
 * public page is unset (fail-safe — never a dead button).
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Frontend;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Core\Settings;
use WWU\WithdrawalButton\Frontend\ExemptionNoteRenderer;
use WWU\WithdrawalButton\Platform\NormalizedOrder;
use WWU\WithdrawalButton\Platform\OrderDataSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EDD customer-facing withdrawal surfaces.
 */
final class EddCustomerOrders {

	/**
	 * Guard against rendering the same surface twice for one order in one request.
	 *
	 * @var array<string,bool>
	 */
	private $rendered = array();

	/**
	 * Wire the EDD customer hooks (EDD active, frontend). Hook names + signatures
	 * are EDD 3.x, source-verified (see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		// Receipt: use the AFTER-TABLE hook (block context) rather than the inside-table
		// `edd_order_receipt_after`, so our <div> button is valid markup and is not
		// foster-parented out of the <table> by the HTML parser.
		add_action( 'edd_order_receipt_after_table', array( $this, 'receipt_button' ), 20, 2 );
		add_action( 'edd_order_history_row_end', array( $this, 'history_row_button' ), 20, 1 );

		// E-mail body: modern catch-all filter (EDD 3.2.0+) + legacy filter (3.0–3.1).
		// Both route to the same appender, which is de-duplicated per order so a 3.2.0+
		// store (where the legacy shim also fires when hooked) never appends twice.
		add_filter( 'edd_order_receipt', array( $this, 'email_link' ), 20, 2 );
		add_filter( 'edd_purchase_receipt', array( $this, 'email_link_legacy' ), 20, 3 );
	}

	/**
	 * Button on the purchase receipt ([edd_receipt]).
	 *
	 * @param mixed $order EDD\Orders\Order object (or a numeric id).
	 * @param mixed $args  Receipt args (unused).
	 * @return void
	 */
	public function receipt_button( $order = null, $args = array() ): void {
		echo $this->button_for( $order, 'receipt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder escapes (template + esc_url); inline styles are static literals.
	}

	/**
	 * Button at the end of a purchase-history order row ([purchase_history]).
	 *
	 * @param mixed $order EDD\Orders\Order object (or a numeric id).
	 * @return void
	 */
	public function history_row_button( $order = null ): void {
		$html = $this->button_for( $order, 'history' );
		if ( '' === $html ) {
			return;
		}
		// The hook fires inside the <tr> (between cells), so wrap in a <td> to keep
		// the row valid; render only when there is something to show.
		echo '<td class="wwu-wb-history-cell">' . $html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is built + escaped by button_for(); the <td> wrapper is a static literal.
	}

	/**
	 * Append the withdrawal link to the purchase-receipt e-mail body (modern filter,
	 * EDD 3.2.0+): `edd_order_receipt( string $body, EDD\Orders\Order $order )`.
	 *
	 * @param mixed $body  Current e-mail body (string).
	 * @param mixed $order EDD\Orders\Order object.
	 * @return mixed
	 */
	public function email_link( $body, $order = null ) {
		return is_string( $body ) ? $this->append_email_link( $body, $order ) : $body;
	}

	/**
	 * Append the withdrawal link via the legacy filter (EDD 3.0–3.1):
	 * `edd_purchase_receipt( string $message, int $payment_id, array $meta )`.
	 * De-duplicated against {@see email_link()} so 3.2.0+ never appends twice.
	 *
	 * @param mixed $body       Current e-mail body (string).
	 * @param mixed $payment_id EDD payment/order id.
	 * @param mixed $meta       Payment meta (unused).
	 * @return mixed
	 */
	public function email_link_legacy( $body, $payment_id = 0, $meta = array() ) {
		return is_string( $body ) ? $this->append_email_link( $body, $payment_id ) : $body;
	}

	/**
	 * Build the button HTML for an order, or '' when it should not show.
	 *
	 * Shows a localized status notice instead of the button when a request already
	 * exists; renders nothing when ineligible, the public page is unset, or it was
	 * already rendered for this order+surface in the same request.
	 *
	 * @param mixed  $source  EDD order object or id.
	 * @param string $surface Surface key (for the dedupe guard).
	 * @return string
	 */
	private function button_for( $source, string $surface ): string {
		$order_id = $this->order_id_from( $source );
		if ( $order_id <= 0 ) {
			return '';
		}

		$adapter = $this->adapter();
		if ( ! $adapter ) {
			return '';
		}
		$order = $adapter->get_order( (string) $order_id );
		if ( ! $order ) {
			return '';
		}

		$guard = $surface . ':' . $order->order_ref;
		if ( isset( $this->rendered[ $guard ] ) ) {
			return '';
		}
		$this->rendered[ $guard ] = true;

		// A request already exists → show its localized status, never a second button.
		$status_label = EligibleOrders::request_status_label( $adapter, $order->order_ref );
		if ( '' !== $status_label ) {
			return '<p class="wwu-wb-status-notice">' . esc_html( $status_label ) . '</p>';
		}

		if ( ! Settings::enabled() ) {
			return '';
		}
		$decision = Services::instance()->applicability->decide( $order );
		if ( ! $decision->show ) {
			/*
			 * When the order is exempt under Art. 59, return the transparency note so
			 * the consumer understands why the button is absent. For any other reason
			 * (out-of-scope country, B2B, ineligible status …) return ''.
			 */
			if ( 'no_withdrawal_right' === $decision->reason ) {
				return ExemptionNoteRenderer::render( $order );
			}
			return '';
		}

		$url = $this->form_url( $order, $this->payment_key_for( $order_id, $source ) );
		if ( '' === $url ) {
			return ''; // No public form page configured — nowhere to send the customer.
		}

		$services = Services::instance();
		$locale   = '' !== $order->locale ? $order->locale : determine_locale();
		return Template::render(
			'button/withdrawal-button.php',
			array(
				'url'            => $url,
				'label'          => $services->labels->withdraw_label( $order->country, $locale ),
				'days_remaining' => $services->window->days_remaining( $order ),
				// EDD receipt/history pages do not load the plugin stylesheet, so the
				// button carries self-contained inline styles (same as the FluentCart SPA).
				'inline'         => true,
			)
		);
	}

	/**
	 * Append the withdrawal-link block to an e-mail body when the order is eligible
	 * and a public page exists. De-duplicated per order (modern + legacy filters).
	 *
	 * @param string $body   Current e-mail body.
	 * @param mixed  $source EDD order object or id.
	 * @return string
	 */
	private function append_email_link( string $body, $source ): string {
		$ctx = $this->eligible_context( $this->order_id_from( $source ) );
		if ( null === $ctx ) {
			return $body;
		}
		list( $order, $url, $label ) = $ctx;

		$guard = 'email:' . $order->order_ref;
		if ( isset( $this->rendered[ $guard ] ) ) {
			return $body; // Already appended (e.g. the modern + legacy filter both fired).
		}
		$this->rendered[ $guard ] = true;

		return $body . '<p style="margin-top:16px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:8px 14px;background:#1a1f3a;color:#fff;text-decoration:none;border-radius:5px;" data-no-translation>' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Resolve {order, url, label} when the order is eligible + a public page exists.
	 *
	 * @param int $order_id EDD order id.
	 * @return array{0:NormalizedOrder,1:string,2:string}|null
	 */
	private function eligible_context( int $order_id ): ?array {
		if ( $order_id <= 0 || ! Settings::enabled() ) {
			return null;
		}
		$adapter = $this->adapter();
		if ( ! $adapter ) {
			return null;
		}
		$order = $adapter->get_order( (string) $order_id );
		if ( ! $order || ! Services::instance()->applicability->decide( $order )->show ) {
			return null;
		}
		$url = $this->form_url( $order, $this->payment_key_for( $order_id, null ) );
		if ( '' === $url ) {
			return null;
		}
		$locale = '' !== $order->locale ? $order->locale : determine_locale();
		$label  = Services::instance()->labels->withdraw_label( $order->country, $locale );
		return array( $order, $url, $label );
	}

	/**
	 * Build the public-form-page URL for an order, pre-authenticated with the EDD
	 * payment key. Returns '' when no public page is configured.
	 *
	 * @param NormalizedOrder $order       Order.
	 * @param string          $payment_key EDD payment key (guest auth), or ''.
	 * @return string
	 */
	private function form_url( NormalizedOrder $order, string $payment_key ): string {
		$page_id = (int) ( Settings::main()['public_form_page_id'] ?? 0 );
		if ( $page_id <= 0 ) {
			return '';
		}
		$permalink = get_permalink( $page_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}
		$args = array( 'wwu_wb_order' => rawurlencode( $order->order_ref ) );
		if ( '' !== $payment_key ) {
			$args['key'] = rawurlencode( $payment_key );
		}
		return add_query_arg( $args, $permalink );
	}

	/**
	 * Extract an EDD order id from a hook argument (EDD\Orders\Order object or a
	 * numeric id).
	 *
	 * @param mixed $arg Hook argument.
	 * @return int
	 */
	private function order_id_from( $arg ): int {
		if ( is_numeric( $arg ) ) {
			return (int) $arg;
		}
		if ( is_object( $arg ) ) {
			foreach ( array( 'id', 'ID' ) as $prop ) {
				if ( isset( $arg->{$prop} ) && is_numeric( $arg->{$prop} ) ) {
					return (int) $arg->{$prop};
				}
			}
		}
		return 0;
	}

	/**
	 * Resolve the EDD payment key for guest authentication, from the passed object
	 * first, then via edd_get_order().
	 *
	 * @param int   $order_id EDD order id.
	 * @param mixed $source   The hook argument (may already carry the key).
	 * @return string
	 */
	private function payment_key_for( int $order_id, $source ): string {
		if ( is_object( $source ) ) {
			foreach ( array( 'payment_key', 'key' ) as $prop ) {
				if ( isset( $source->{$prop} ) && '' !== (string) $source->{$prop} ) {
					return (string) $source->{$prop};
				}
			}
		}
		if ( $order_id > 0 && function_exists( 'edd_get_order' ) ) {
			try {
				$order = edd_get_order( $order_id );
				if ( is_object( $order ) && isset( $order->payment_key ) ) {
					return (string) $order->payment_key;
				}
			} catch ( \Throwable $e ) {
				return '';
			}
		}
		return '';
	}

	/**
	 * The active EDD adapter, or null.
	 *
	 * @return OrderDataSource|null
	 */
	private function adapter(): ?OrderDataSource {
		return Services::instance()->platforms->get( 'edd' );
	}
}
