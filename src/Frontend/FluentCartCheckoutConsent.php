<?php
/**
 * FluentCart checkout capture of the exemption consent + acknowledgement.
 *
 * The FluentCart counterpart of {@see WooCheckoutConsent}. It uses the FluentCart
 * checkout hooks verified against the official docs + a direct confirmation from
 * the FluentCart team (2026-06-15, docs/analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md):
 *   - render  → `fluent_cart/before_payment_methods` (ACTION, $data['cart']) — the
 *                team's recommended hook; it fires in the standard, modal AND block
 *                checkout renderers (the block checkout still runs FluentCart's own
 *                checkout-form flow, NOT the WooCommerce Store API);
 *   - validate → `fluent_cart/checkout/validate_before_process` (FILTER, return
 *                true or a WP_Error to block);
 *   - capture  → `fluent_cart/checkout/prepare_other_data` (ACTION, $data['order']
 *                is the just-created draft order).
 *
 * A custom field rendered inside the FluentCart checkout `<form>` IS submitted
 * (the checkout JS builds FormData from the form) and is therefore available in
 * validate + capture — confirmed by the team. Unchecked checkboxes are NOT
 * submitted, so an absent consent is correctly treated as "no".
 *
 * Design = defensive + fail-safe, matching the rest of the FluentCart adapter:
 *  - The AUTHORITATIVE capture reads the order's line items via the adapter's
 *    already-verified `order_items` path, so it never depends on the (unverified)
 *    FluentCart Cart item shape.
 *  - Render/validate read the cart best-effort (guarded, multiple fallbacks); if
 *    the cart cannot be read, no checkbox is shown and checkout is NOT blocked —
 *    so the consent simply isn't captured and the button stays (fail-safe toward
 *    the consumer's right). There is no path where the button is wrongly hidden.
 *  - Consent is stored through the adapter's own per-order option storage
 *    (`set_meta`), which {@see ConsentReader} already reads platform-agnostically;
 *    no dependency on FluentCart's native order-meta API.
 *
 * Category-aware: product categories live in the `product-categories` taxonomy
 * (team-confirmed), resolved via {@see FluentCartAdapter::category_ids_for_post()},
 * so FluentCart exemptions match by PRODUCT ID and by CATEGORY — in parity with
 * WooCommerce (`product_cat`) and EDD (`download_category`).
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Frontend;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Core\Settings;
use WWU\WithdrawalButton\Domain\ConsentText;
use WWU\WithdrawalButton\Domain\ExceptionTypes;
use WWU\WithdrawalButton\Domain\ExemptionResolver;
use WWU\WithdrawalButton\Mail\ExemptionConfirmation;
use WWU\WithdrawalButton\Platform\FluentCartAdapter;
use WWU\WithdrawalButton\Security\ClientInfo;
use WWU\WithdrawalButton\Storage\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout-consent capture (FluentCart).
 */
final class FluentCartCheckoutConsent {

	/**
	 * Field name root for the consent checkboxes (`wwu_wb_consent[<reason>]`).
	 *
	 * @var string
	 */
	private const FIELD = 'wwu_wb_consent';

	/**
	 * Register the FluentCart checkout hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// `before_payment_methods` is the safer render hook: it fires in the standard,
		// modal AND block checkout renderers (the FluentCart team's explicit
		// recommendation, 2026-06-15). `after_payment_methods` only fires in the
		// standard renderer. See docs/analysis/wwu-wb-fluentcart-hooks-ANALYSIS.md.
		add_action( 'fluent_cart/before_payment_methods', array( $this, 'render_fields' ), 10, 1 );
		add_filter( 'fluent_cart/checkout/validate_before_process', array( $this, 'validate' ), 10, 2 );
		add_action( 'fluent_cart/checkout/prepare_other_data', array( $this, 'capture' ), 10, 1 );
	}

	/**
	 * Render one required acknowledgement checkbox per conditional reason in cart.
	 *
	 * @param mixed $data Hook payload (expects $data['cart']).
	 * @return void
	 */
	public function render_fields( $data = array() ): void {
		$map = $this->cart_conditional_map( $this->extract_cart( $data ) );
		if ( empty( $map ) ) {
			return;
		}

		echo '<div class="wwu-wb-consent" style="margin:12px 0;">';
		foreach ( array_keys( $map ) as $reason ) {
			$text = ConsentText::for_reason( (string) $reason );
			if ( '' === $text ) {
				continue;
			}
			echo '<p class="wwu-wb-consent__row" style="margin:0 0 10px;"><label style="display:block;line-height:1.4;">';
			printf(
				'<input type="checkbox" class="wwu-wb-consent__input" name="%1$s[%2$s]" value="1" required /> ',
				esc_attr( self::FIELD ),
				esc_attr( (string) $reason )
			);
			echo '<span class="wwu-wb-consent__text">' . esc_html( $text ) . '</span>';
			echo '</label></p>';
		}
		echo '</div>';
	}

	/**
	 * Block checkout (return a WP_Error) until every required acknowledgement is ticked.
	 *
	 * FILTER: return $validation unchanged to proceed, or a WP_Error to abort. If the
	 * cart cannot be read we do NOT block (fail-safe — better to let checkout proceed
	 * than to wrongly block; the button simply stays afterwards).
	 *
	 * @param mixed $validation Incoming validation result (true).
	 * @param mixed $data       Checkout submission data (expects a cart).
	 * @return mixed true|WP_Error
	 */
	public function validate( $validation, $data = array() ) {
		$map = $this->cart_conditional_map( $this->extract_cart( $data ) );
		if ( empty( $map ) ) {
			return $validation;
		}

		$posted = $this->posted_consent( $data );
		foreach ( array_keys( $map ) as $reason ) {
			if ( empty( $posted[ $reason ] ) ) {
				$def   = ExceptionTypes::get( (string) $reason );
				$label = is_array( $def ) ? (string) ( $def['label'] ?? $reason ) : (string) $reason;
				return new \WP_Error(
					'wwu_wb_consent_required',
					sprintf(
						/* translators: %s: exemption reason label. */
						__( 'Please confirm the required acknowledgement for: %s', 'wwu-withdrawal-button' ),
						$label
					)
				);
			}
		}

		return $validation;
	}

	/**
	 * Capture the consent onto the just-created order + send the confirmation + log.
	 *
	 * Uses the adapter's verified order-items path (never the unverified Cart shape).
	 *
	 * @param mixed $data Hook payload (expects $data['order'] + request data).
	 * @return void
	 */
	public function capture( $data = array() ): void {
		$order = is_array( $data ) ? ( $data['order'] ?? null ) : null;
		if ( ! is_object( $order ) ) {
			return;
		}

		$order_id = (int) ( $order->id ?? ( method_exists( $order, 'getKey' ) ? $order->getKey() : 0 ) );
		if ( $order_id <= 0 ) {
			return;
		}

		$adapter = Services::instance()->platforms->get( 'fluentcart' );
		if ( null === $adapter ) {
			return;
		}

		// Authoritative: read the order's items via the adapter's verified path.
		$normalized = $adapter->get_order( (string) $order_id );
		if ( null === $normalized ) {
			return;
		}

		$map = array();
		foreach ( (array) $normalized->items as $item ) {
			$item   = (array) $item;
			$pid    = (int) ( $item['product_id'] ?? 0 );
			$cats   = array_map( 'intval', (array) ( $item['category_ids'] ?? array() ) );
			$reason = ExemptionResolver::reason_for( $pid, $cats ); // Product-id + category aware.
			if ( null !== $reason && ExceptionTypes::is_conditional( $reason ) ) {
				$map[ $reason ][] = $pid;
			}
		}
		if ( empty( $map ) ) {
			return;
		}

		$entries = WooCheckoutConsent::build_consent_entries( $map, $this->posted_consent( $data ), $this->captured_ip() );
		if ( empty( $entries ) ) {
			return;
		}

		// Idempotency: only once per order.
		if ( '' !== (string) $adapter->get_meta( (string) $order_id, 'consent_logged' ) ) {
			return;
		}

		$adapter->set_meta( (string) $order_id, 'consent', $entries );

		// PII-free immutable-log event (IP lives only on the purgeable adapter meta).
		$payload = array();
		foreach ( $entries as $entry ) {
			$entry     = (array) $entry;
			$payload[] = array(
				'product_id'   => (int) ( $entry['product_id'] ?? 0 ),
				'reason_id'    => (string) ( $entry['reason_id'] ?? '' ),
				'consent_kind' => (string) ( $entry['consent_kind'] ?? '' ),
				'text_hash'    => (string) ( $entry['text_hash'] ?? '' ),
				'consented_at' => (string) ( $entry['consented_at'] ?? '' ),
			);
		}
		( new LogRepository() )->append(
			array(
				'request_uid'    => 'consent-fc-' . (string) $order_id,
				'platform'       => 'fluentcart',
				'order_ref'      => (string) $order_id,
				'customer_email' => (string) $normalized->email,
				'event'          => 'exemption_consent',
				'payload'        => array( 'entries' => $payload ),
				'ip_address'     => '',
			)
		);

		// Durable-medium confirmation (constitutive for the digital exemption).
		$confirmed = ExemptionConfirmation::send_for_order(
			'fluentcart',
			(string) $order_id,
			(string) $normalized->email,
			(string) $normalized->number,
			$entries
		);

		$adapter->batch_meta(
			(string) $order_id,
			array(
				'consent_logged'            => gmdate( 'c' ),
				'consent_confirmation_sent' => $confirmed ? gmdate( 'c' ) : '0',
			)
		);
	}

	/**
	 * Conditional-exempt product ids present in the cart, grouped by reason.
	 *
	 * @param mixed $cart FluentCart Cart instance (or null).
	 * @return array<string,int[]>
	 */
	private function cart_conditional_map( $cart ): array {
		$map = array();
		foreach ( $this->cart_product_ids( $cart ) as $pid ) {
			// Category-aware: resolve the product's `product-categories` terms so a
			// category-tagged exemption matches in the cart, in parity with the
			// authoritative order-items capture below.
			$cats   = FluentCartAdapter::category_ids_for_post( (int) $pid );
			$reason = ExemptionResolver::reason_for( (int) $pid, $cats );
			if ( null !== $reason && ExceptionTypes::is_conditional( $reason ) ) {
				$map[ $reason ][ (int) $pid ] = (int) $pid;
			}
		}
		foreach ( $map as $reason => $set ) {
			$map[ $reason ] = array_values( $set );
		}
		return $map;
	}

	/**
	 * Best-effort list of product ids (WP post ids) in a FluentCart cart.
	 *
	 * Guarded across the cart-item accessors FluentCart may expose; returns [] when
	 * the cart cannot be read (→ no checkbox, fail-safe).
	 *
	 * @param mixed $cart Cart instance or null.
	 * @return int[]
	 */
	private function cart_product_ids( $cart ): array {
		$ids = array();
		if ( ! is_object( $cart ) ) {
			return $ids;
		}

		$items = null;
		foreach ( array( 'getItems', 'get_items' ) as $accessor ) {
			if ( method_exists( $cart, $accessor ) ) {
				try {
					$items = $cart->{$accessor}();
					break;
				} catch ( \Throwable $e ) {
					$items = null;
				}
			}
		}
		if ( null === $items && isset( $cart->items ) ) {
			$items = $cart->items;
		}

		foreach ( FluentCartAdapter::unwrap_collection( $items ) as $it ) {
			$pid = 0;
			foreach ( array( 'post_id', 'product_id' ) as $k ) {
				if ( is_object( $it ) && isset( $it->{$k} ) ) {
					$pid = (int) $it->{$k};
					break;
				}
				if ( is_array( $it ) && isset( $it[ $k ] ) ) {
					$pid = (int) $it[ $k ];
					break;
				}
			}
			if ( $pid <= 0 && is_object( $it ) && isset( $it->product ) && is_object( $it->product ) && isset( $it->product->post_id ) ) {
				$pid = (int) $it->product->post_id;
			}
			if ( $pid > 0 ) {
				$ids[] = $pid;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Extract the cart object from a hook payload (array or object).
	 *
	 * @param mixed $data Hook payload.
	 * @return mixed Cart instance or null.
	 */
	private function extract_cart( $data ) {
		if ( is_array( $data ) && isset( $data['cart'] ) ) {
			return $data['cart'];
		}
		if ( is_object( $data ) && isset( $data->cart ) ) {
			return $data->cart;
		}
		return null;
	}

	/**
	 * The posted consent map (reason => bool), from the hook request data or $_POST.
	 *
	 * @param mixed $data Hook payload (may carry request_data).
	 * @return array<string,bool>
	 */
	private function posted_consent( $data = array() ): array {
		$raw = array();
		if ( is_array( $data ) ) {
			if ( isset( $data['request_data'][ self::FIELD ] ) && is_array( $data['request_data'][ self::FIELD ] ) ) {
				$raw = $data['request_data'][ self::FIELD ];
			} elseif ( isset( $data['validated_data'][ self::FIELD ] ) && is_array( $data['validated_data'][ self::FIELD ] ) ) {
				$raw = $data['validated_data'][ self::FIELD ];
			}
		}
		if ( empty( $raw ) && isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- FluentCart verifies its own checkout request; values read as booleans by reason key.
			$raw = wp_unslash( $_POST[ self::FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- flags consumed as booleans by reason key below.
		}

		$out = array();
		foreach ( (array) $raw as $reason => $val ) {
			$out[ sanitize_key( (string) $reason ) ] = ! empty( $val );
		}
		return $out;
	}

	/**
	 * The client IP to store, honouring the merchant's setting (default on).
	 *
	 * @return string
	 */
	private function captured_ip(): string {
		$main    = Settings::main();
		$capture = array_key_exists( 'consent_capture_ip', $main ) ? ! empty( $main['consent_capture_ip'] ) : true;
		return $capture ? ClientInfo::ip() : '';
	}
}
