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

use WWU\WithdrawalButton\Domain\ExceptionTypes;
use WWU\WithdrawalButton\REST\Authentication;

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

		if ( ! function_exists( 'wc_get_orders' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'WooCommerce is not active. Checkout consent is captured on WooCommerce today, so there are no records to show.', 'wwu-withdrawal-button' ) . '</p></div></div>';
			return;
		}

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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$result = wc_get_orders(
			array(
				'limit'      => self::PER_PAGE,
				'paged'      => $paged,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'paginate'   => true,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin screen, not a hot path.
					array(
						'key'     => WWU_WB_META_PREFIX . 'consent',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$orders = ( is_object( $result ) && isset( $result->orders ) ) ? $result->orders : array();
		$max    = ( is_object( $result ) && isset( $result->max_num_pages ) ) ? (int) $result->max_num_pages : 1;

		if ( empty( $orders ) ) {
			echo '<p>' . esc_html__( 'No consent records yet.', 'wwu-withdrawal-button' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Order', 'wwu-withdrawal-button' ),
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

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$entries = $order->get_meta( WWU_WB_META_PREFIX . 'consent' );
			if ( ! is_array( $entries ) || empty( $entries ) ) {
				continue;
			}

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

			$confirmed = (string) $order->get_meta( WWU_WB_META_PREFIX . 'consent_confirmation_sent' );
			$purged    = (string) $order->get_meta( WWU_WB_META_PREFIX . 'consent_purged' );
			$date      = $order->get_date_created();

			$ip_cell = $has_ip
				? __( 'stored', 'wwu-withdrawal-button' )
				: ( '' !== $purged ? __( 'anonymised', 'wwu-withdrawal-button' ) : __( 'not stored', 'wwu-withdrawal-button' ) );

			$confirm_cell = ( '' !== $confirmed && '0' !== $confirmed )
				? __( 'sent', 'wwu-withdrawal-button' )
				: __( 'not sent', 'wwu-withdrawal-button' );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $date ? $date->date_i18n( 'Y-m-d H:i' ) : '' ) . '</td>';
			echo '<td>' . esc_html( $order->get_billing_email() ) . '</td>';
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

		$redirect = admin_url( 'admin.php?page=' . AdminController::CONSENT_SLUG );
		if ( ! function_exists( 'wc_get_orders' ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => self::EXPORT_CAP,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded manual export.
					array(
						'key'     => WWU_WB_META_PREFIX . 'consent',
						'compare' => 'EXISTS',
					),
				),
			)
		);

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
			array( 'order_id', 'order_number', 'order_date_gmt', 'customer_email', 'product_id', 'reason_id', 'reason_label', 'consent_kind', 'text_hash', 'consented_at', 'ip', 'confirmation_sent' )
		);

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$entries = $order->get_meta( WWU_WB_META_PREFIX . 'consent' );
			if ( ! is_array( $entries ) || empty( $entries ) ) {
				continue;
			}
			$confirmed   = (string) $order->get_meta( WWU_WB_META_PREFIX . 'consent_confirmation_sent' );
			$confirm_str = ( '' !== $confirmed && '0' !== $confirmed ) ? $confirmed : 'no';
			$created     = $order->get_date_created();

			foreach ( $entries as $entry ) {
				$entry = (array) $entry;
				$rid   = (string) ( $entry['reason_id'] ?? '' );
				$def   = ExceptionTypes::get( $rid );
				$label = is_array( $def ) ? (string) ( $def['label'] ?? $rid ) : $rid;

				fputcsv(
					$out,
					array(
						self::csv_safe( (string) $order->get_id() ),
						self::csv_safe( (string) $order->get_order_number() ),
						self::csv_safe( $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : '' ),
						self::csv_safe( (string) $order->get_billing_email() ),
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
