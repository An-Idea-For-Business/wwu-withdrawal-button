<?php
/**
 * WooCommerce BLOCK Checkout (Store API) capture of the exemption consent.
 *
 * The classic-checkout path lives in {@see WooCheckoutConsent} (it uses the
 * shortcode-checkout hooks, which do NOT fire on the block checkout). This class
 * is the block-checkout counterpart, built on the official, verified
 * **Additional Checkout Fields API** (pure PHP, no JS build — WooCommerce 9.9.0+):
 *
 *  - register a required `checkbox` field (location `order`) per CONDITIONAL reason,
 *    gated by a JSON-Schema `required`/`hidden` on `cart.items` so it only appears,
 *    and is only required, when the cart contains a product tagged under that reason
 *    (product-ID gating — the document object exposes product IDs, not categories);
 *  - WooCommerce validates the required field SERVER-SIDE on the Store API request
 *    (a tampered client cannot bypass it) and persists it to order meta
 *    `_wc_other/webwakeupwdb/<name>` automatically;
 *  - on `woocommerce_store_api_checkout_order_processed` we read those values, build
 *    the canonical `_webwakeupwdb_consent` entries (re-deriving the order's conditional
 *    items via the verified order path, which DOES resolve categories), send the
 *    durable-medium confirmation and append the PII-free immutable-log event —
 *    reusing {@see WooCheckoutConsent::build_consent_entries} + {@see ExemptionConfirmation}.
 *
 * Classic and block checkouts are mutually exclusive per order, and both paths share
 * the `_webwakeupwdb_consent`/`consent_logged` order meta + the idempotency guard, so there
 * is never a double capture. If the API is unavailable (WC < 9.9 or shortcode
 * checkout) this class no-ops and the classic path / fail-safe applies.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 *
 * @see docs/analysis/webwakeupwdb-fluentcart-hooks-ANALYSIS.md (cross-platform capture notes)
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Frontend;

use WebWakeUpWdb\WithdrawalButton\Core\Services;
use WebWakeUpWdb\WithdrawalButton\Core\Settings;
use WebWakeUpWdb\WithdrawalButton\Domain\ConsentText;
use WebWakeUpWdb\WithdrawalButton\Domain\ExceptionTypes;
use WebWakeUpWdb\WithdrawalButton\Domain\ExemptionResolver;
use WebWakeUpWdb\WithdrawalButton\Mail\ExemptionConfirmation;
use WebWakeUpWdb\WithdrawalButton\Security\ClientInfo;
use WebWakeUpWdb\WithdrawalButton\Storage\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block-checkout consent capture (WooCommerce Additional Checkout Fields API).
 */
final class WooBlockCheckoutConsent {

	/**
	 * Field-id namespace (Additional Checkout Fields require `namespace/name`).
	 *
	 * @var string
	 */
	private const NS = 'webwakeupwdb';

	/**
	 * Transient caching the conditional reason → product-id map (bounded query).
	 *
	 * @var string
	 */
	private const CACHE = 'webwakeupwdb_conditional_product_ids';

	/**
	 * Max product ids enumerated into a field's JSON Schema (DoS / payload guard).
	 *
	 * @var int
	 */
	private const MAX_IDS = 300;

	/**
	 * Register hooks. No-op when the Additional Checkout Fields API (with the
	 * conditional JSON-Schema support, WC 9.9.0+) is unavailable.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9.0', '<' ) ) {
			return; // Conditional (hidden/required) JSON Schema needs WC 9.9.0+.
		}
		add_action( 'woocommerce_init', array( $this, 'register_fields' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture' ), 20, 1 );
	}

	/**
	 * Register one conditional, required acknowledgement checkbox per conditional
	 * reason that has tagged products. The field is shown + required only when the
	 * cart contains one of those products (JSON Schema on `cart.items`).
	 *
	 * @return void
	 */
	public function register_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		foreach ( $this->conditional_reason_product_ids() as $reason => $pids ) {
			$pids = array_values( array_unique( array_map( 'intval', (array) $pids ) ) );
			if ( empty( $pids ) ) {
				continue;
			}
			$text = ConsentText::for_reason( (string) $reason );
			if ( '' === $text ) {
				continue;
			}

			$enum         = array_slice( $pids, 0, self::MAX_IDS );
			$contains     = $this->cart_items_contains_schema( $enum, false );
			$not_contains = $this->cart_items_contains_schema( $enum, true );

			woocommerce_register_additional_checkout_field(
				array(
					'id'            => self::NS . '/' . $this->field_name( (string) $reason ),
					'label'         => $text,
					'location'      => 'order',
					'type'          => 'checkbox',
					'required'      => $contains,     // required when cart contains a tagged product.
					'hidden'        => $not_contains, // hidden when it does not.
					'error_message' => __( 'Please confirm the required acknowledgement to proceed.', 'wwu-withdrawal-button' ),
				)
			);
		}
	}

	/**
	 * Capture the consent onto the order created via the Store API.
	 *
	 * @param mixed $order WC_Order created by the block checkout.
	 * @return void
	 */
	public function capture( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$order_id = (int) $order->get_id();
		if ( $order_id <= 0 ) {
			return;
		}
		// Shared idempotency guard with the classic path.
		if ( '' !== (string) $order->get_meta( WEBWAKEUPWDB_META_PREFIX . 'consent_logged' ) ) {
			return;
		}

		$adapter = Services::instance()->platforms->get( 'woocommerce' );
		if ( null === $adapter ) {
			return;
		}
		$normalized = $adapter->get_order( (string) $order_id );
		if ( null === $normalized ) {
			return;
		}

		// Re-derive the order's conditional-exempt items (this path DOES resolve
		// categories, so it is authoritative even where the field gating was by id).
		$map = array();
		foreach ( (array) $normalized->items as $item ) {
			$item   = (array) $item;
			$pid    = (int) ( $item['product_id'] ?? 0 );
			$cats   = array_map( 'intval', (array) ( $item['category_ids'] ?? array() ) );
			$reason = ExemptionResolver::reason_for( $pid, $cats );
			if ( null !== $reason && ExceptionTypes::is_conditional( $reason ) ) {
				$map[ $reason ][] = $pid;
			}
		}
		if ( empty( $map ) ) {
			return;
		}

		// Read the Additional Checkout Field values WooCommerce saved to order meta.
		$posted = array();
		foreach ( array_keys( $map ) as $reason ) {
			$val               = (string) $order->get_meta( '_wc_other/' . self::NS . '/' . $this->field_name( (string) $reason ) );
			$posted[ $reason ] = ( '1' === $val || 'true' === $val || 'yes' === $val );
		}

		$entries = WooCheckoutConsent::build_consent_entries( $map, $posted, $this->captured_ip() );
		if ( empty( $entries ) ) {
			return;
		}

		$order->update_meta_data( WEBWAKEUPWDB_META_PREFIX . 'consent', $entries );

		// PII-free immutable-log event (IP lives only on the purgeable order meta).
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

		$confirmed = ExemptionConfirmation::send_for_order(
			'woocommerce',
			(string) $order_id,
			(string) $order->get_billing_email(),
			(string) $order->get_order_number(),
			$entries
		);

		$order->update_meta_data( WEBWAKEUPWDB_META_PREFIX . 'consent_logged', gmdate( 'c' ) );
		$order->update_meta_data( WEBWAKEUPWDB_META_PREFIX . 'consent_confirmation_sent', $confirmed ? gmdate( 'c' ) : '0' );
		$order->save();
	}

	/**
	 * Invalidate the cached conditional product-id map (call after settings save).
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * Conditional reason → product ids (direct tags + products resolved from tagged
	 * categories), cached in a transient so the category query is bounded.
	 *
	 * @return array<string,int[]>
	 */
	private function conditional_reason_product_ids(): array {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map = array();
		foreach ( ExemptionResolver::map() as $reason => $sets ) {
			if ( ! ExceptionTypes::is_conditional( (string) $reason ) ) {
				continue;
			}
			$products   = array_map( 'intval', (array) ( $sets['products'] ?? array() ) );
			$categories = array_map( 'intval', (array) ( $sets['categories'] ?? array() ) );

			if ( ! empty( $categories ) && function_exists( 'wc_get_products' ) ) {
				$in_cats = wc_get_products(
					array(
						'limit'     => self::MAX_IDS,
						'return'    => 'ids',
						'status'    => 'publish',
						'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded, cached.
							array(
								'taxonomy' => 'product_cat',
								'field'    => 'term_id',
								'terms'    => $categories,
							),
						),
					)
				);
				if ( is_array( $in_cats ) ) {
					$products = array_merge( $products, array_map( 'intval', $in_cats ) );
				}
			}

			$products = array_values( array_unique( array_filter( $products ) ) );
			if ( ! empty( $products ) ) {
				$map[ (string) $reason ] = array_slice( $products, 0, self::MAX_IDS );
			}
		}

		set_transient( self::CACHE, $map, HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * JSON-Schema fragment: cart.items array contains (or, inverted, does not
	 * contain) one of the given product ids.
	 *
	 * @param int[] $enum    Product ids.
	 * @param bool  $inverse When true, build the "does NOT contain" schema.
	 * @return array<string,mixed>
	 */
	private function cart_items_contains_schema( array $enum, bool $inverse ): array {
		$items = $inverse
			? array(
				'type' => 'array',
				'not'  => array( 'contains' => array( 'enum' => array_values( $enum ) ) ),
			)
			: array(
				'type'     => 'array',
				'contains' => array( 'enum' => array_values( $enum ) ),
			);

		return array(
			'type'       => 'object',
			'properties' => array(
				'cart' => array(
					'type'       => 'object',
					'properties' => array(
						'items' => $items,
					),
				),
			),
		);
	}

	/**
	 * The field name (alphanumeric) for a reason id, e.g. '59_o' → 'c59o'.
	 *
	 * @param string $reason Reason id.
	 * @return string
	 */
	private function field_name( string $reason ): string {
		return 'c' . preg_replace( '/[^a-z0-9]/i', '', $reason );
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
