<?php
/**
 * Builds the data for the durable-medium acknowledgement of receipt (Art. 11a(4)).
 *
 * The acknowledgement must reproduce the statement's content and the exact date
 * and time of submission. This assembles all fields the email/PDF templates need:
 * the consumer's declaration, the identified contract, the trader's details, the
 * submission timestamp, the immutable-log hash, and the verifiable link.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\DurableMedium;

use WWU\WithdrawalButton\Domain\WithdrawalRequest;
use WWU\WithdrawalButton\Platform\NormalizedOrder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receipt data assembler.
 */
final class ReceiptBuilder {

	/**
	 * Build the receipt data array.
	 *
	 * @param string            $request_uid  Request UUID.
	 * @param NormalizedOrder   $order        Order.
	 * @param WithdrawalRequest $req          Statement.
	 * @param array             $log_row      The confirmed immutable-log row (for hash + created_at).
	 * @param string            $submitted_at ISO 8601 submission timestamp.
	 * @return array
	 */
	public function data( string $request_uid, NormalizedOrder $order, WithdrawalRequest $req, array $log_row, string $submitted_at ): array {
		$row_hash    = (string) ( $log_row['row_hash'] ?? '' );
		$row_payload = isset( $log_row['payload_json'] ) ? (array) json_decode( (string) $log_row['payload_json'], true ) : array();
		$within      = (bool) ( $row_payload['within_window'] ?? true );

		return array(
			'within_window'     => $within,
			'request_uid'       => $request_uid,
			'order_number'      => $order->number,
			'name'              => $req->name,
			'email'             => $req->email,
			'reason'            => $req->reason,
			'products_selected' => implode(
				', ',
				array_map(
					static function ( $name ) use ( $req ) {
						$q = isset( $req->product_quantities[ $name ] ) ? (int) $req->product_quantities[ $name ] : 0;
						/* translators: 1: product name, 2: quantity the consumer is withdrawing. */
						return $q > 0 ? sprintf( __( '%1$s × %2$d', 'wwu-withdrawal-button' ), $name, $q ) : (string) $name;
					},
					$req->products
				)
			),
			'items'             => $this->items_summary( $order ),
			'submitted_at'      => $submitted_at,
			'submitted_local'   => $this->localize_datetime( $submitted_at ),
			'row_hash'          => $row_hash,
			'trader'            => $this->trader(),
			'download_url'      => VerifiableLink::download_url( $request_uid ),
			'verify_url'        => VerifiableLink::verify_url( $request_uid ),
			'site_name'         => get_bloginfo( 'name' ),
		);
	}

	/**
	 * A short, human items summary for the contract identification.
	 *
	 * @param NormalizedOrder $order Order.
	 * @return string
	 */
	private function items_summary( NormalizedOrder $order ): string {
		$names = array();
		foreach ( $order->items as $item ) {
			$qty   = (int) ( $item['qty'] ?? 1 );
			$names[] = ( $qty > 1 ? $qty . '× ' : '' ) . (string) ( $item['name'] ?? '' );
		}
		return implode( ', ', array_filter( $names ) );
	}

	/**
	 * Trader (professionista) details for the Annex I-B addressee.
	 *
	 * @return array{name:string,address:string,email:string}
	 */
	private function trader(): array {
		$address_parts = array_filter(
			array(
				(string) get_option( 'woocommerce_store_address', '' ),
				(string) get_option( 'woocommerce_store_address_2', '' ),
				trim( (string) get_option( 'woocommerce_store_postcode', '' ) . ' ' . (string) get_option( 'woocommerce_store_city', '' ) ),
				(string) get_option( 'woocommerce_default_country', '' ),
			)
		);

		$settings = (array) get_option( 'wwu_wb_settings', array() );

		return array(
			'name'    => (string) get_bloginfo( 'name' ),
			'address' => implode( ', ', $address_parts ),
			// merchant_email may hold a comma-separated notification list; the receipt
			// shows a single public trader contact, so take the first address.
			'email'   => \WWU\WithdrawalButton\Security\Sanitizer::first_email( (string) ( $settings['merchant_email'] ?? get_option( 'admin_email' ) ) ),
		);
	}

	/**
	 * Localise an ISO timestamp to the site timezone + locale for display.
	 *
	 * @param string $iso ISO 8601 (UTC) timestamp.
	 * @return string
	 */
	private function localize_datetime( string $iso ): string {
		try {
			$dt = new \DateTimeImmutable( $iso, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return $iso;
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dt->getTimestamp() );
	}
}
