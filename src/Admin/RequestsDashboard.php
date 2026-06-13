<?php
/**
 * Admin "Withdrawal requests" dashboard.
 *
 * Lists the confirmed withdrawal requests from the immutable log (consumer,
 * order, submission time, late flag, evidence link, chain-integrity badge) and
 * lets the merchant PROCESS each one. Important: a withdrawal is a UNILATERAL
 * consumer right — there is no "approve/accept" step. Once validly exercised, the
 * contract is dissolved by law and the trader must reimburse within 14 days. So
 * the actions here are operational, not an approval: mark the request as handled,
 * resend the acknowledgement (e.g. after fixing SMTP), and jump to the order's
 * refund screen. Every state change is written to the immutable log.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Admin;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\DurableMedium\ConfirmationDispatcher;
use WWU\WithdrawalButton\DurableMedium\VerifiableLink;
use WWU\WithdrawalButton\Platform\OrderDataSource;
use WWU\WithdrawalButton\REST\Authentication;
use WWU\WithdrawalButton\Storage\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests dashboard page.
 */
final class RequestsDashboard {

	/**
	 * Nonce action for the row actions (mark processed / resend).
	 *
	 * @var string
	 */
	private const ACTION_NONCE = 'wwu_wb_request_action';

	/**
	 * Per-render cache of platform adapters keyed by platform name.
	 *
	 * @var array<string,OrderDataSource|null>
	 */
	private $adapters = array();

	/**
	 * Render the dashboard.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			return;
		}

		$repo   = new LogRepository();
		$per    = 50;
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows   = $repo->list_confirmed( $per, ( $page - 1 ) * $per );
		$total  = $repo->count_confirmed();
		$broken = $repo->chain_status_cached();

		echo '<div class="wrap wwu-wb-wrap">';
		echo '<h1>' . esc_html__( 'Withdrawal requests', 'wwu-withdrawal-button' ) . '</h1>';

		$this->maybe_render_notice();

		// Plain-language reminder of the legal nature of the action.
		echo '<p class="description" style="max-width:820px;">' . esc_html__( 'A withdrawal is the consumer\'s unilateral right — there is nothing to approve. Once exercised within the period, reimburse the consumer within 14 days (same payment method). Use "Refund order" to issue the refund, then mark the request as processed.', 'wwu-withdrawal-button' ) . '</p>';

		// Chain-integrity badge.
		if ( 0 === $broken ) {
			echo '<p><span class="wwu-wb-badge wwu-wb-badge--ok">' . esc_html__( 'Evidence log: chain intact', 'wwu-withdrawal-button' ) . '</span></p>';
		} else {
			echo '<p><span class="wwu-wb-badge wwu-wb-badge--err">' . esc_html(
				sprintf(
					/* translators: %d: row id. */
					__( 'Evidence log: integrity broken at row #%d — investigate.', 'wwu-withdrawal-button' ),
					$broken
				)
			) . '</span></p>';
		}

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No withdrawal requests yet.', 'wwu-withdrawal-button' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Date/time (submitted)', 'wwu-withdrawal-button' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'wwu-withdrawal-button' ) . '</th>';
		echo '<th>' . esc_html__( 'Consumer', 'wwu-withdrawal-button' ) . '</th>';
		echo '<th>' . esc_html__( 'Country', 'wwu-withdrawal-button' ) . '</th>';
		echo '<th>' . esc_html__( 'In time', 'wwu-withdrawal-button' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'wwu-withdrawal-button' ) . '</th>';
		echo '<th>' . esc_html__( 'Evidence', 'wwu-withdrawal-button' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'wwu-withdrawal-button' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$payload  = (array) json_decode( (string) $row['payload_json'], true );
			$within   = (bool) ( $payload['within_window'] ?? true );
			$uid      = (string) $row['request_uid'];
			$platform = (string) $row['platform'];
			$order_ref = (string) $row['order_ref'];
			$adapter  = $this->adapter( $platform );

			$processed_at = $adapter ? (string) $adapter->get_meta( $order_ref, 'processed_at' ) : '';

			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $payload['submitted_at'] ?? $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $payload['order_number'] ?? $order_ref ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['customer_email'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $payload['country'] ?? '' ) ) . '</td>';
			echo '<td>' . ( $within
				? '<span class="wwu-wb-badge wwu-wb-badge--ok">' . esc_html__( 'Yes', 'wwu-withdrawal-button' ) . '</span>'
				: '<span class="wwu-wb-badge wwu-wb-badge--warn">' . esc_html__( 'Flagged late', 'wwu-withdrawal-button' ) . '</span>' ) . '</td>';

			// Processing status (refunded > processed > open).
			echo '<td>' . $this->status_cell( $order_ref, $processed_at ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped parts + wc_price.

			echo '<td><a href="' . esc_url( VerifiableLink::verify_url( $uid ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Verify', 'wwu-withdrawal-button' ) . '</a></td>';

			// Row actions.
			echo '<td>' . $this->row_actions( $uid, $order_ref, '' !== $processed_at ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped parts.
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Simple pagination.
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<p class="wwu-wb-pagination">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = add_query_arg( array( 'page' => AdminController::REQUESTS_SLUG, 'paged' => $i ), admin_url( 'admin.php' ) );
				echo ( $i === $page )
					? '<strong>' . esc_html( (string) $i ) . '</strong> '
					: '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
			}
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Build the processing-status cell: refunded (with amount) wins over the
	 * manual "processed" flag, which wins over "open". The refunded amount is read
	 * live from WooCommerce (source of truth); the refund event itself is recorded
	 * in the immutable log by WooRefundRecorder.
	 *
	 * @param string $order_ref    Order reference.
	 * @param string $processed_at Processed timestamp ('' if not processed).
	 * @return string
	 */
	private function status_cell( string $order_ref, string $processed_at ): string {
		$refunded = 0.0;
		$currency = '';
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_ref );
			if ( $order instanceof \WC_Order ) {
				$refunded = (float) $order->get_total_refunded();
				$currency = (string) $order->get_currency();
			}
		}

		if ( $refunded > 0 ) {
			$amount = function_exists( 'wc_price' )
				? wp_kses_post( wc_price( $refunded, array( 'currency' => $currency ) ) )
				: esc_html( number_format_i18n( $refunded, 2 ) . ' ' . $currency );
			return '<span class="wwu-wb-badge wwu-wb-badge--ok">' . esc_html__( 'Refunded', 'wwu-withdrawal-button' ) . ' ' . $amount . '</span>';
		}

		if ( '' !== $processed_at ) {
			return '<span class="wwu-wb-badge wwu-wb-badge--ok">' . esc_html__( 'Processed', 'wwu-withdrawal-button' ) . '</span>';
		}

		return '<span class="wwu-wb-badge wwu-wb-badge--warn">' . esc_html__( 'Open', 'wwu-withdrawal-button' ) . '</span>';
	}

	/**
	 * Build the action links for a request row.
	 *
	 * @param string $uid       Request UUID.
	 * @param string $order_ref Order reference.
	 * @param bool   $processed Whether it is already processed.
	 * @return string
	 */
	private function row_actions( string $uid, string $order_ref, bool $processed ): string {
		$base = admin_url( 'admin-post.php' );
		$out  = array();

		if ( ! $processed ) {
			$mark = wp_nonce_url( add_query_arg( array( 'action' => 'wwu_wb_mark_processed', 'uid' => $uid ), $base ), self::ACTION_NONCE );
			$out[] = '<a href="' . esc_url( $mark ) . '">' . esc_html__( 'Mark processed', 'wwu-withdrawal-button' ) . '</a>';
		}

		$resend = wp_nonce_url( add_query_arg( array( 'action' => 'wwu_wb_resend', 'uid' => $uid ), $base ), self::ACTION_NONCE );
		$out[]  = '<a href="' . esc_url( $resend ) . '">' . esc_html__( 'Resend email', 'wwu-withdrawal-button' ) . '</a>';

		$refund = $this->refund_url( $order_ref );
		if ( '' !== $refund ) {
			$out[] = '<a href="' . esc_url( $refund ) . '" target="_blank" rel="noopener">' . esc_html__( 'Refund order', 'wwu-withdrawal-button' ) . '</a>';
		}

		return implode( ' &nbsp;|&nbsp; ', $out );
	}

	/**
	 * WooCommerce order edit URL (where the merchant issues the refund), or ''.
	 *
	 * @param string $order_ref Order reference.
	 * @return string
	 */
	private function refund_url( string $order_ref ): string {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return '';
		}
		$order = wc_get_order( $order_ref );
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		return method_exists( $order, 'get_edit_order_url' ) ? (string) $order->get_edit_order_url() : '';
	}

	/**
	 * Handle "Mark processed": record the operational state + log it.
	 *
	 * @return void
	 */
	public function handle_mark_processed(): void {
		$this->guard();
		$uid  = $this->request_uid();
		$repo = new LogRepository();
		$row  = '' !== $uid ? $repo->find( $uid, 'confirmed' ) : null;

		$done = false;
		if ( $row ) {
			$adapter = $this->adapter( (string) $row['platform'] );
			if ( $adapter ) {
				$now = gmdate( 'Y-m-d\TH:i:s\Z' );
				$adapter->set_meta( (string) $row['order_ref'], 'processed_at', $now );
				$repo->append(
					array(
						'request_uid'    => $uid,
						'platform'       => (string) $row['platform'],
						'order_ref'      => (string) $row['order_ref'],
						'customer_email' => (string) $row['customer_email'],
						'event'          => 'request_processed',
						'payload'        => array(
							'by' => get_current_user_id(),
							'at' => $now,
						),
					)
				);
				$done = true;
			}
		}

		// Honest feedback: don't claim success if nothing was written.
		$this->redirect_back( $done ? 'processed' : 'mark_failed' );
	}

	/**
	 * Handle "Resend email": re-dispatch the acknowledgement for a request.
	 *
	 * @return void
	 */
	public function handle_resend(): void {
		$this->guard();
		$uid = $this->request_uid();
		if ( '' === $uid ) {
			$this->redirect_back( 'resend_failed' );
		}

		// Debounce accidental double-clicks: a resend sends a real email to the
		// consumer, so suppress repeats within a short window.
		$throttle = 'wwu_wb_resend_' . md5( $uid );
		if ( get_transient( $throttle ) ) {
			$this->redirect_back( 'resend_throttled' );
		}
		set_transient( $throttle, 1, 20 );

		$ok = ( new ConfirmationDispatcher() )->resend( $uid );
		$this->redirect_back( $ok ? 'resent' : 'resend_failed' );
	}

	/**
	 * Capability + nonce gate shared by the row actions.
	 *
	 * @return void
	 */
	private function guard(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wwu-withdrawal-button' ) );
		}
		check_admin_referer( self::ACTION_NONCE );
	}

	/**
	 * Read + sanitise the request uid from the action link.
	 *
	 * @return string
	 */
	private function request_uid(): string {
		return isset( $_GET['uid'] ) ? sanitize_text_field( wp_unslash( $_GET['uid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked in guard().
	}

	/**
	 * PRG redirect back to the Requests page with a result flag.
	 *
	 * @param string $flag Result flag.
	 * @return void
	 */
	private function redirect_back( string $flag ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => AdminController::REQUESTS_SLUG,
					'wwu_wb_msg'  => $flag,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the result notice for the last action.
	 *
	 * @return void
	 */
	private function maybe_render_notice(): void {
		$msg = isset( $_GET['wwu_wb_msg'] ) ? sanitize_key( wp_unslash( $_GET['wwu_wb_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $msg ) {
			return;
		}
		$map = array(
			'processed'        => array( 'success', __( 'Request marked as processed.', 'wwu-withdrawal-button' ) ),
			'mark_failed'      => array( 'error', __( 'Could not mark the request as processed — the order could not be loaded.', 'wwu-withdrawal-button' ) ),
			'resent'           => array( 'success', __( 'Acknowledgement email resent.', 'wwu-withdrawal-button' ) ),
			'resend_failed'    => array( 'error', __( 'Could not resend the email — check your email/SMTP configuration.', 'wwu-withdrawal-button' ) ),
			'resend_throttled' => array( 'warning', __( 'Please wait a few seconds before resending again.', 'wwu-withdrawal-button' ) ),
		);
		if ( ! isset( $map[ $msg ] ) ) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr( $map[ $msg ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $msg ][1] ) . '</p></div>';
	}

	/**
	 * Resolve (and cache) the platform adapter for a row.
	 *
	 * @param string $platform Platform key.
	 * @return OrderDataSource|null
	 */
	private function adapter( string $platform ): ?OrderDataSource {
		if ( ! array_key_exists( $platform, $this->adapters ) ) {
			$this->adapters[ $platform ] = Services::instance()->platforms->get( $platform );
		}
		return $this->adapters[ $platform ];
	}
}
