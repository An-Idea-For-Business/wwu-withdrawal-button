<?php
/**
 * Easy Digital Downloads checkout capture of the exemption consent.
 *
 * The EDD counterpart of {@see WooCheckoutConsent}, on the verified EDD hooks
 * (docs/specs/webwakeupwdb-edd-integration-SPEC.md):
 *   - render   → `edd_purchase_form_before_submit` (the checkout form);
 *   - validate → `edd_checkout_error_checks` (`edd_set_error()` blocks the purchase);
 *   - capture  → `edd_built_order` ($order_id + $_POST are both available here, during
 *                the checkout request — unlike `edd_complete_purchase`, which also runs
 *                later on the gateway return where our $_POST field is absent).
 *
 * EDD line items resolve `download_category` terms, so the capture re-derives the
 * order's conditional items category-aware via the adapter. Consent is stored in EDD
 * order meta through the adapter, which {@see ConsentReader} reads platform-agnostically.
 * The durable-medium confirmation + PII-free immutable-log event are reused.
 *
 * @package WebWakeUpWdb\WithdrawalButton
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
 * Checkout-consent capture (Easy Digital Downloads).
 */
final class EddCheckoutConsent {

	/**
	 * Field name root for the consent checkboxes (`webwakeupwdb_consent[<reason>]`).
	 *
	 * @var string
	 */
	private const FIELD = 'webwakeupwdb_consent';

	/**
	 * Register the EDD checkout hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'edd_purchase_form_before_submit', array( $this, 'render_fields' ) );
		add_action( 'edd_checkout_error_checks', array( $this, 'validate' ), 10, 2 );
		add_action( 'edd_built_order', array( $this, 'capture' ), 20, 2 );
	}

	/**
	 * Render one required acknowledgement checkbox per conditional reason in cart.
	 *
	 * @return void
	 */
	public function render_fields(): void {
		$map = $this->cart_conditional_map();
		if ( empty( $map ) ) {
			return;
		}

		echo '<div class="webwakeupwdb-consent" style="margin:12px 0;">';
		foreach ( array_keys( $map ) as $reason ) {
			$text = ConsentText::for_reason( (string) $reason );
			if ( '' === $text ) {
				continue;
			}
			echo '<p class="webwakeupwdb-consent__row" style="margin:0 0 10px;"><label style="display:block;line-height:1.4;">';
			printf(
				'<input type="checkbox" class="webwakeupwdb-consent__input" name="%1$s[%2$s]" value="1" /> ',
				esc_attr( self::FIELD ),
				esc_attr( (string) $reason )
			);
			echo '<span class="webwakeupwdb-consent__text">' . esc_html( $text ) . '</span>';
			echo '</label></p>';
		}
		echo '</div>';
	}

	/**
	 * Block the purchase until every required acknowledgement is ticked.
	 *
	 * @param array $valid_data Validated checkout data (unused).
	 * @param array $posted     Posted checkout data ($_POST).
	 * @return void
	 */
	public function validate( $valid_data = array(), $posted = array() ): void {
		unset( $valid_data );
		$map = $this->cart_conditional_map();
		if ( empty( $map ) ) {
			return;
		}

		$consent = $this->posted_consent( is_array( $posted ) ? $posted : array() );
		foreach ( array_keys( $map ) as $reason ) {
			if ( empty( $consent[ $reason ] ) ) {
				$def   = ExceptionTypes::get( (string) $reason );
				$label = is_array( $def ) ? (string) ( $def['label'] ?? $reason ) : (string) $reason;
				if ( function_exists( 'edd_set_error' ) ) {
					edd_set_error(
						'webwakeupwdb_consent_required_' . sanitize_key( (string) $reason ),
						sprintf(
							/* translators: %s: exemption reason label. */
							__( 'Please confirm the required acknowledgement for: %s', 'wwu-withdrawal-button' ),
							$label
						)
					);
				}
			}
		}
	}

	/**
	 * Capture the consent onto the just-built EDD order + confirmation + log.
	 *
	 * @param int   $order_id   Built order id.
	 * @param mixed $order_data Order data array (unused).
	 * @return void
	 */
	public function capture( $order_id, $order_data = array() ): void {
		unset( $order_data );
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}

		$adapter = Services::instance()->platforms->get( 'edd' );
		if ( null === $adapter ) {
			return;
		}

		// Idempotency.
		if ( '' !== (string) $adapter->get_meta( (string) $order_id, 'consent_logged' ) ) {
			return;
		}

		$normalized = $adapter->get_order( (string) $order_id );
		if ( null === $normalized ) {
			return;
		}

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

		$entries = WooCheckoutConsent::build_consent_entries( $map, $this->posted_consent(), $this->captured_ip() );
		if ( empty( $entries ) ) {
			return;
		}

		$adapter->set_meta( (string) $order_id, 'consent', $entries );

		// PII-free immutable-log event (IP lives only on the purgeable EDD order meta).
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
				'request_uid'    => 'consent-edd-' . (string) $order_id,
				'platform'       => 'edd',
				'order_ref'      => (string) $order_id,
				'customer_email' => (string) $normalized->email,
				'event'          => 'exemption_consent',
				'payload'        => array( 'entries' => $payload ),
				'ip_address'     => '',
			)
		);

		$confirmed = ExemptionConfirmation::send_for_order(
			'edd',
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
	 * Conditional-exempt downloads in the current EDD cart: reason id => product ids.
	 *
	 * @return array<string,int[]>
	 */
	private function cart_conditional_map(): array {
		$map = array();
		if ( ! function_exists( 'edd_get_cart_contents' ) ) {
			return $map;
		}

		$contents = edd_get_cart_contents();
		if ( ! is_array( $contents ) ) {
			return $map;
		}

		foreach ( $contents as $item ) {
			$pid = (int) ( ( is_array( $item ) ? ( $item['id'] ?? 0 ) : 0 ) );
			if ( $pid <= 0 ) {
				continue;
			}
			$cats = array();
			if ( function_exists( 'wp_get_post_terms' ) ) {
				$terms = wp_get_post_terms( $pid, 'download_category', array( 'fields' => 'ids' ) );
				if ( is_array( $terms ) ) {
					$cats = array_map( 'intval', $terms );
				}
			}
			$reason = ExemptionResolver::reason_for( $pid, $cats );
			if ( null !== $reason && ExceptionTypes::is_conditional( $reason ) ) {
				$map[ $reason ][ $pid ] = $pid;
			}
		}

		foreach ( $map as $reason => $set ) {
			$map[ $reason ] = array_values( $set );
		}
		return $map;
	}

	/**
	 * The posted consent map (reason => bool), from a payload or $_POST.
	 *
	 * Nonce: EDD verifies its own checkout nonce before these hooks fire.
	 *
	 * @param array $posted Optional explicit posted data (from the validate hook).
	 * @return array<string,bool>
	 */
	private function posted_consent( array $posted = array() ): array {
		$raw = array();
		if ( isset( $posted[ self::FIELD ] ) && is_array( $posted[ self::FIELD ] ) ) {
			$raw = $posted[ self::FIELD ];
		} elseif ( isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verifies its checkout nonce upstream; values read as booleans by reason key.
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
