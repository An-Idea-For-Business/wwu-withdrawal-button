<?php
/**
 * WooCommerce checkout capture of the exemption consent + acknowledgement.
 *
 * For the two CONDITIONAL exemptions (digital immediate access / service fully
 * performed) the right of withdrawal is only removed when the consumer gave prior
 * express consent AND acknowledged losing the right (Art. 16(1)(a)/(m) CRD; Art.
 * 59(1)(a)/(o) CdC). This class:
 *  1. detects conditional-exempt items in the cart;
 *  2. renders one required acknowledgement checkbox per reason at checkout;
 *  3. blocks checkout server-side until each required box is ticked;
 *  4. stores the agreed wording (verbatim + SHA-256 hash + timestamp + IP) on the
 *     order meta `_wwu_wb_consent`, which {@see ConsentReader} feeds back to the
 *     evaluator so the button is then legitimately hidden for those items;
 *  5. writes an order note + an append-only immutable-log event as durable
 *     evidence the consent existed.
 *
 * Scope note: this targets the **classic** (shortcode/PHP) checkout, which fires
 * `woocommerce_review_order_before_submit` / `woocommerce_checkout_process` /
 * `woocommerce_checkout_create_order`. The block-based Checkout (Store API) does
 * not fire these and needs a separate integration — tracked as a follow-up.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Frontend;

use WWU\WithdrawalButton\Core\Settings;
use WWU\WithdrawalButton\Domain\ConsentText;
use WWU\WithdrawalButton\Domain\ExceptionTypes;
use WWU\WithdrawalButton\Domain\ExemptionResolver;
use WWU\WithdrawalButton\Mail\ExemptionConfirmation;
use WWU\WithdrawalButton\Security\ClientInfo;
use WWU\WithdrawalButton\Storage\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout-consent capture (WooCommerce classic checkout).
 */
final class WooCheckoutConsent {

	/**
	 * Field name root for the consent checkboxes (`wwu_wb_consent[<reason>]`).
	 *
	 * @var string
	 */
	private const FIELD = 'wwu_wb_consent';

	/**
	 * Register the WooCommerce checkout hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_fields' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'attach_consent' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'record_evidence' ), 20, 3 );
	}

	/**
	 * Render one required acknowledgement checkbox per conditional reason in cart.
	 *
	 * @return void
	 */
	public function render_fields(): void {
		$detail = $this->cart_conditional_detail();
		if ( empty( $detail ) ) {
			return;
		}

		echo '<div class="wwu-wb-consent" style="margin:12px 0;">';
		foreach ( $detail as $reason => $info ) {
			$text = ConsentText::for_reason( (string) $reason );
			if ( '' === $text ) {
				continue;
			}
			$names = implode( ', ', array_map( 'sanitize_text_field', (array) $info['names'] ) );

			echo '<p class="wwu-wb-consent__row" style="margin:0 0 10px;">';
			echo '<label style="display:block;line-height:1.4;">';
			printf(
				'<input type="checkbox" class="wwu-wb-consent__input" name="%1$s[%2$s]" value="1" required /> ',
				esc_attr( self::FIELD ),
				esc_attr( (string) $reason )
			);
			echo '<span class="wwu-wb-consent__text">' . esc_html( $text ) . '</span>';
			echo '</label>';
			if ( '' !== $names ) {
				echo '<span class="wwu-wb-consent__items" style="display:block;font-size:12px;color:#555;margin-top:2px;">'
					. esc_html(
						sprintf(
							/* translators: %s: comma-separated list of product names. */
							__( 'Applies to: %s', 'wwu-withdrawal-button' ),
							$names
						)
					)
					. '</span>';
			}
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Block checkout until every required acknowledgement is ticked.
	 *
	 * Runs inside woocommerce_checkout_process, after WooCommerce has already
	 * verified its own checkout nonce, so reading our field from $_POST here is safe.
	 *
	 * @return void
	 */
	public function validate_fields(): void {
		$map = $this->cart_conditional_map();
		if ( empty( $map ) ) {
			return;
		}

		$posted = $this->posted_consent();
		foreach ( array_keys( $map ) as $reason ) {
			if ( empty( $posted[ $reason ] ) ) {
				$def   = ExceptionTypes::get( (string) $reason );
				$label = is_array( $def ) ? (string) ( $def['label'] ?? $reason ) : (string) $reason;
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice(
						sprintf(
							/* translators: %s: exemption reason label. */
							__( 'Please confirm the required acknowledgement for: %s', 'wwu-withdrawal-button' ),
							$label
						),
						'error'
					);
				}
			}
		}
	}

	/**
	 * Attach the consent entries to the order being created (persisted on save).
	 *
	 * @param mixed $order WC_Order under construction.
	 * @param mixed $data  Posted checkout data (unused).
	 * @return void
	 */
	public function attach_consent( $order, $data = array() ): void {
		unset( $data );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$map = $this->cart_conditional_map();
		if ( empty( $map ) ) {
			return;
		}

		$entries = self::build_consent_entries( $map, $this->posted_consent(), self::captured_ip() );
		if ( empty( $entries ) ) {
			return;
		}
		// update_meta_data on the in-flight order persists when WooCommerce saves it.
		$order->update_meta_data( WWU_WB_META_PREFIX . 'consent', $entries );
	}

	/**
	 * The client IP to store with the consent, honouring the merchant's setting.
	 *
	 * The IP is the most exposed field under the GDPR strict-necessity test, so the
	 * merchant can turn it off (`wwu_wb_settings['consent_capture_ip']`, default on).
	 * It is stored ONLY on the order meta (purgeable), never in the immutable log.
	 *
	 * @return string
	 */
	private static function captured_ip(): string {
		$main    = Settings::main();
		$capture = array_key_exists( 'consent_capture_ip', $main ) ? ! empty( $main['consent_capture_ip'] ) : true;
		return $capture ? ClientInfo::ip() : '';
	}

	/**
	 * After the order exists: add an order note + an immutable-log evidence event.
	 *
	 * @param int   $order_id    Created order id.
	 * @param array $posted_data Posted checkout data (unused).
	 * @param mixed $order       WC_Order (WC 3.0+ passes it; fall back to load).
	 * @return void
	 */
	public function record_evidence( $order_id, $posted_data = array(), $order = null ): void {
		unset( $posted_data );

		if ( ! $order instanceof \WC_Order ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$entries = $order->get_meta( WWU_WB_META_PREFIX . 'consent' );
		if ( ! is_array( $entries ) || empty( $entries ) ) {
			return;
		}

		// Idempotency: only record the evidence once, even if the hook re-fires.
		if ( '' !== (string) $order->get_meta( WWU_WB_META_PREFIX . 'consent_logged' ) ) {
			return;
		}

		// Human-readable order note.
		$lines = array();
		foreach ( $entries as $entry ) {
			$entry = (array) $entry;
			$def   = ExceptionTypes::get( (string) ( $entry['reason_id'] ?? '' ) );
			$label = is_array( $def ) ? (string) ( $def['label'] ?? $entry['reason_id'] ?? '' ) : (string) ( $entry['reason_id'] ?? '' );
			$lines[] = sprintf( '#%d — %s', (int) ( $entry['product_id'] ?? 0 ), $label );
		}
		$order->add_order_note(
			sprintf(
				/* translators: %s: list of "#<product id> — <reason>" entries. */
				__( 'Withdrawal-exemption consent captured at checkout for: %s', 'wwu-withdrawal-button' ),
				implode( '; ', $lines )
			)
		);

		// Immutable-log evidence event. PII-FREE on purpose: the IP + verbatim text
		// live ONLY on the order meta (_wwu_wb_consent), which the retention purge can
		// anonymise. The hash-chained log keeps just the text_hash + reason + timestamp,
		// so it stays tamper-evident AND purge-compatible (the chain is never rewritten).
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
				'request_uid'    => 'consent-' . (string) $order_id,
				'platform'       => 'woocommerce',
				'order_ref'      => (string) $order_id,
				'customer_email' => (string) $order->get_billing_email(),
				'event'          => 'exemption_consent',
				'payload'        => array( 'entries' => $payload ),
				'ip_address'     => '',
			)
		);

		// Durable-medium confirmation (Art. 8(7) CRD / Art. 51(7) CdC): constitutive
		// for the digital exemption, independent duty for services. Emitted now (before
		// performance begins) and logged as its own delivery event — the consent log
		// alone does not prove the confirmation was delivered.
		$confirmed = ExemptionConfirmation::send_for_order(
			'woocommerce',
			(string) $order_id,
			(string) $order->get_billing_email(),
			(string) $order->get_order_number(),
			$entries
		);

		$order->update_meta_data( WWU_WB_META_PREFIX . 'consent_logged', gmdate( 'c' ) );
		$order->update_meta_data( WWU_WB_META_PREFIX . 'consent_confirmation_sent', $confirmed ? gmdate( 'c' ) : '0' );
		$order->save();
	}

	/**
	 * Build the storable consent entries for the reasons the consumer ticked.
	 *
	 * Pure + deterministic (no WooCommerce calls) so it is unit-testable. One entry
	 * per (reason, product) so the evaluator can match each line item.
	 *
	 * @param array<string,int[]>   $reason_product_map reason id => product ids in cart.
	 * @param array<string,mixed>   $posted             Posted consent map (reason => '1').
	 * @param string                $ip                 Raw client IP (legal evidence).
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_consent_entries( array $reason_product_map, array $posted, string $ip ): array {
		$entries = array();
		$now     = gmdate( 'c' );

		foreach ( $reason_product_map as $reason => $product_ids ) {
			$reason = (string) $reason;
			if ( empty( $posted[ $reason ] ) ) {
				continue; // Only reasons the consumer actually acknowledged.
			}
			if ( ! ExceptionTypes::is_conditional( $reason ) ) {
				continue; // Defensive: only conditional reasons capture consent.
			}
			$def  = ExceptionTypes::get( $reason );
			$kind = is_array( $def ) ? (string) ( $def['consent_kind'] ?? '' ) : '';
			$text = ConsentText::for_reason( $reason );
			$hash = '' !== $text ? hash( 'sha256', $text ) : '';

			foreach ( (array) $product_ids as $pid ) {
				$entries[] = array(
					'product_id'   => (int) $pid,
					'reason_id'    => $reason,
					'consent_kind' => $kind,
					'text'         => $text,
					'text_hash'    => $hash,
					'consented_at' => $now,
					'ip'           => $ip,
				);
			}
		}

		return $entries;
	}

	/**
	 * Conditional-exempt items in the current cart: reason id => product ids.
	 *
	 * @return array<string,int[]>
	 */
	private function cart_conditional_map(): array {
		$map = array();
		foreach ( $this->cart_conditional_detail() as $reason => $info ) {
			$map[ (string) $reason ] = array_map( 'intval', (array) $info['product_ids'] );
		}
		return $map;
	}

	/**
	 * Conditional-exempt items in the current cart, with product names for the UI.
	 *
	 * @return array<string,array{product_ids:int[],names:string[]}>
	 */
	private function cart_conditional_detail(): array {
		$detail = array();

		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return $detail;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = ( isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ) ? $cart_item['data'] : null;
			$pid     = $product ? (int) $product->get_id() : (int) ( $cart_item['product_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			$cats   = $product ? array_map( 'intval', (array) $product->get_category_ids() ) : array();
			$reason = ExemptionResolver::reason_for( $pid, $cats );
			if ( null === $reason || ! ExceptionTypes::is_conditional( $reason ) ) {
				continue;
			}

			if ( ! isset( $detail[ $reason ] ) ) {
				$detail[ $reason ] = array(
					'product_ids' => array(),
					'names'       => array(),
				);
			}
			if ( ! in_array( $pid, $detail[ $reason ]['product_ids'], true ) ) {
				$detail[ $reason ]['product_ids'][] = $pid;
				$detail[ $reason ]['names'][]       = $product ? (string) $product->get_name() : ( '#' . $pid );
			}
		}

		return $detail;
	}

	/**
	 * The posted consent map (reason => '1'), unslashed and shape-guarded.
	 *
	 * Nonce: this is read inside WooCommerce checkout handlers, after WooCommerce
	 * has verified its own checkout nonce; we only read scalar flags by reason key.
	 *
	 * @return array<string,mixed>
	 */
	private function posted_consent(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce upstream; values are validated by reason key below.
		$raw = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- flags consumed as booleans by reason key.
		$out = array();
		foreach ( (array) $raw as $reason => $val ) {
			$out[ sanitize_key( (string) $reason ) ] = ! empty( $val );
		}
		return $out;
	}
}
