<?php
/**
 * Admin "Consent records" view — the merchant's evidence surface for the
 * withdrawal-exemption consents captured at checkout.
 *
 * Framed deliberately as EVIDENCE (to discharge the trader's burden of proof,
 * Art. 6(9) CRD + GDPR accountability Art. 5(2)) — NOT a legally-named "register".
 * Physical products never appear here. Lists the orders that carry a captured
 * consent and exports the full per-entry evidence to CSV (with CSV-injection
 * guard). The append-only immutable log remains the tamper-evident anchor; this
 * page is the human-readable, queryable read model on top of the order meta.
 *
 * @package WWU\WithdrawalButton
 *
 * @see docs/legal/wwu-wb-exemption-consent-evidence-NOTE.md
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Admin;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Domain\ExceptionTypes;
use WWU\WithdrawalButton\REST\Authentication;
use WWU\WithdrawalButton\Storage\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consent-records admin page.
 */
final class ConsentRecordsPage {

	/**
	 * Nonce action for the CSV export.
	 *
	 * @var string
	 */
	private const EXPORT_NONCE = 'wwu_wb_export_consents';

	/**
	 * Orders shown per page.
	 *
	 * @var int
	 */
	private const PER_PAGE = 50;

	/**
	 * Max orders scanned for a CSV export (bounded; surfaced to the user).
	 *
	 * @var int
	 */
	private const EXPORT_CAP = 5000;

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			return;
		}

		echo '<div class="wrap wwu-wb-wrap">';
		echo '<h1>' . esc_html__( 'Consent records', 'wwu-withdrawal-button' ) . '</h1>';
		echo '<p class="description" style="max-width:900px;">' . esc_html__( 'Evidence of the consumers\' express consent + acknowledgement captured at checkout for the two conditional Art. 59 exemptions (digital content with immediate access; service fully performed). Keep it to discharge your burden of proof — it is evidence, not a legally-named "register". Physical products never appear here: they always keep the 14-day right of withdrawal.', 'wwu-withdrawal-button' ) . '</p>';

		// Source of truth: the immutable, cross-platform evidence log. Every platform's
		// checkout-consent capture appends an `exemption_consent` row, so reading by
		// event surfaces WooCommerce, EDD and FluentCart uniformly. This page only READS
		// the log — it never writes to or mutates the append-only chain.
		$total = ( new LogRepository() )->count_by_event( 'exemption_consent' );

		// CSV export button.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 1em;">';
		echo '<input type="hidden" name="action" value="wwu_wb_export_consents" />';
		wp_nonce_field( self::EXPORT_NONCE );
		echo '<button type="submit" class="button">' . esc_html__( 'Export to CSV', 'wwu-withdrawal-button' ) . '</button> ';
		echo '<span class="description">' . esc_html(
			sprintf(
				/* translators: %d: maximum number of orders exported. */
				__( 'One row per consent; up to the %d most recent orders.', 'wwu-withdrawal-button' ),
				self::EXPORT_CAP
			)
		) . '</span>';
		echo '</form>';

		if ( 0 === $total ) {
			echo '<p>' . esc_html__( 'No consent records yet. Consent is captured at checkout for the conditional Art. 59 exemptions on WooCommerce, Easy Digital Downloads and FluentCart.', 'wwu-withdrawal-button' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$max   = (int) max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$rows  = $this->fetch_consent_rows( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Order', 'wwu-withdrawal-button' ),
			__( 'Platform', 'wwu-withdrawal-button' ),
			__( 'Date', 'wwu-withdrawal-button' ),
			__( 'Customer', 'wwu-withdrawal-button' ),
			__( 'Reason(s)', 'wwu-withdrawal-button' ),
			__( 'Items', 'wwu-withdrawal-button' ),
			__( 'Confirmation e-mail', 'wwu-withdrawal-button' ),
			__( 'IP', 'wwu-withdrawal-button' ),
		) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$entries = (array) $row['entries'];

			$reasons = array();
			$has_ip  = false;
			foreach ( $entries as $entry ) {
				$entry = (array) $entry;
				$rid   = (string) ( $entry['reason_id'] ?? '' );
				if ( '' !== $rid && ! isset( $reasons[ $rid ] ) ) {
					$def             = ExceptionTypes::get( $rid );
					$reasons[ $rid ] = is_array( $def ) ? (string) ( $def['label'] ?? $rid ) : $rid;
				}
				if ( '' !== (string) ( $entry['ip'] ?? '' ) ) {
					$has_ip = true;
				}
			}

			$ip_cell = $has_ip
				? __( 'stored', 'wwu-withdrawal-button' )
				: ( '' !== (string) $row['purged'] ? __( 'anonymised', 'wwu-withdrawal-button' ) : __( 'not stored', 'wwu-withdrawal-button' ) );

			$confirm_cell = ( '' !== (string) $row['confirmed'] && '0' !== (string) $row['confirmed'] )
				? __( 'sent', 'wwu-withdrawal-button' )
				: __( 'not sent', 'wwu-withdrawal-button' );

			echo '<tr>';
			echo '<td>#' . esc_html( (string) $row['number'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['platform_label'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['date'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['email'] ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', array_values( $reasons ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) count( $entries ) ) . '</td>';
			echo '<td>' . esc_html( $confirm_cell ) . '</td>';
			echo '<td>' . esc_html( $ip_cell ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Simple prev/next pagination.
		if ( $max > 1 ) {
			$base = admin_url( 'admin.php?page=' . AdminController::CONSENT_SLUG );
			echo '<p style="margin-top:1em;">';
			if ( $paged > 1 ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged - 1, $base ) ) . '">&laquo; ' . esc_html__( 'Previous', 'wwu-withdrawal-button' ) . '</a> ';
			}
			echo '<span style="margin:0 8px;">' . esc_html( sprintf( /* translators: 1: current page, 2: total pages. */ __( 'Page %1$d of %2$d', 'wwu-withdrawal-button' ), $paged, $max ) ) . '</span>';
			if ( $paged < $max ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged + 1, $base ) ) . '">' . esc_html__( 'Next', 'wwu-withdrawal-button' ) . ' &raquo;</a>';
			}
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Handle the CSV export (admin-post). One row per consent entry.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wwu-withdrawal-button' ) );
		}
		check_admin_referer( self::EXPORT_NONCE );

		$rows = $this->fetch_consent_rows( self::EXPORT_CAP, 0 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wwu-wb-consents-' . gmdate( 'Ymd-His' ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		fputcsv(
			$out,
			array( 'platform', 'order_ref', 'order_number', 'consent_logged_gmt', 'customer_email', 'product_id', 'reason_id', 'reason_label', 'consent_kind', 'text_hash', 'consented_at', 'ip', 'confirmation_sent' )
		);

		foreach ( $rows as $row ) {
			$confirm_str = ( '' !== (string) $row['confirmed'] && '0' !== (string) $row['confirmed'] ) ? (string) $row['confirmed'] : 'no';

			foreach ( (array) $row['entries'] as $entry ) {
				$entry = (array) $entry;
				$rid   = (string) ( $entry['reason_id'] ?? '' );
				$def   = ExceptionTypes::get( $rid );
				$label = is_array( $def ) ? (string) ( $def['label'] ?? $rid ) : $rid;

				fputcsv(
					$out,
					array(
						self::csv_safe( (string) $row['platform'] ),
						self::csv_safe( (string) $row['order_ref'] ),
						self::csv_safe( (string) $row['number'] ),
						self::csv_safe( (string) $row['date_gmt'] ),
						self::csv_safe( (string) $row['email'] ),
						self::csv_safe( (string) ( $entry['product_id'] ?? '' ) ),
						self::csv_safe( $rid ),
						self::csv_safe( $label ),
						self::csv_safe( (string) ( $entry['consent_kind'] ?? '' ) ),
						self::csv_safe( (string) ( $entry['text_hash'] ?? '' ) ),
						self::csv_safe( (string) ( $entry['consented_at'] ?? '' ) ),
						self::csv_safe( (string) ( $entry['ip'] ?? '' ) ),
						self::csv_safe( $confirm_str ),
					)
				);
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	/**
	 * Fetch consent records cross-platform from the immutable evidence log (READ-ONLY).
	 *
	 * The `exemption_consent` log rows are the authoritative, cross-platform index of
	 * which orders carry a captured consent — WooCommerce, EDD and FluentCart all append
	 * them. The full per-entry evidence (including the IP) lives on the order and is read
	 * back via the platform adapter when the order still exists; if the order was later
	 * deleted, the log's PII-free entries are used so the evidence still appears. Nothing
	 * here writes to or mutates the append-only log.
	 *
	 * @param int $limit  Max log rows.
	 * @param int $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_consent_rows( int $limit, int $offset ): array {
		$rows = ( new LogRepository() )->list_by_event( 'exemption_consent', $limit, $offset );
		$out  = array();

		foreach ( $rows as $row ) {
			$platform  = (string) ( $row['platform'] ?? '' );
			$order_ref = (string) ( $row['order_ref'] ?? '' );
			$email     = (string) ( $row['customer_email'] ?? '' );
			$date_gmt  = (string) ( $row['created_at'] ?? '' );
			$number    = $order_ref;
			$entries   = array();
			$confirmed = '';
			$purged    = '';

			$adapter = Services::instance()->platforms->get( $platform );
			if ( $adapter ) {
				$meta = $adapter->get_meta( $order_ref, 'consent' );
				if ( is_array( $meta ) && ! empty( $meta ) ) {
					$entries = $meta; // Full entries (incl. IP) from the order meta.
				}
				$confirmed = (string) $adapter->get_meta( $order_ref, 'consent_confirmation_sent' );
				$purged    = (string) $adapter->get_meta( $order_ref, 'consent_purged' );
				$order     = $adapter->get_order( $order_ref );
				if ( $order ) {
					$number = '' !== (string) $order->number ? (string) $order->number : $order_ref;
					if ( '' === $email ) {
						$email = (string) $order->email;
					}
				}
			}

			// Fallback to the log's PII-free entries when the order/meta is gone.
			if ( empty( $entries ) ) {
				$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
				if ( is_array( $payload ) && isset( $payload['entries'] ) && is_array( $payload['entries'] ) ) {
					$entries = $payload['entries'];
				}
			}
			if ( empty( $entries ) ) {
				continue;
			}

			$out[] = array(
				'platform'       => $platform,
				'platform_label' => $this->platform_label( $platform ),
				'order_ref'      => $order_ref,
				'number'         => $number,
				'email'          => $email,
				'date'           => '' !== $date_gmt ? get_date_from_gmt( $date_gmt, 'Y-m-d H:i' ) : '',
				'date_gmt'       => $date_gmt,
				'entries'        => $entries,
				'confirmed'      => $confirmed,
				'purged'         => $purged,
			);
		}

		return $out;
	}

	/**
	 * Human label for a platform key.
	 *
	 * @param string $platform Platform key.
	 * @return string
	 */
	private function platform_label( string $platform ): string {
		$labels = array(
			'woocommerce' => 'WooCommerce',
			'edd'         => 'Easy Digital Downloads',
			'fluentcart'  => 'FluentCart',
		);
		return isset( $labels[ $platform ] ) ? $labels[ $platform ] : ( '' !== $platform ? $platform : '—' );
	}

	/**
	 * Neutralise CSV-injection: prefix a leading formula trigger with a quote.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	private static function csv_safe( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
