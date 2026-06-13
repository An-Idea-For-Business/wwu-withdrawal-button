<?php
/**
 * Admin "Withdrawal requests" dashboard.
 *
 * Lists the confirmed withdrawal requests from the immutable log with the
 * consumer, order, submission time, late flag and a link to the evidence
 * receipt, plus a chain-integrity badge. Read-only over the evidence log.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Admin;

use WWU\WithdrawalButton\DurableMedium\VerifiableLink;
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
	 * Render the dashboard.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Authentication::capability() ) ) {
			return;
		}

		$repo  = new LogRepository();
		$per   = 50;
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows  = $repo->list_confirmed( $per, ( $page - 1 ) * $per );
		$total = $repo->count_confirmed();
		$broken = $repo->chain_status_cached();

		echo '<div class="wrap wwu-wb-wrap">';
		echo '<h1>' . esc_html__( 'Withdrawal requests', 'wwu-withdrawal-button' ) . '</h1>';

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
		echo '<th>' . esc_html__( 'Evidence', 'wwu-withdrawal-button' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$payload = (array) json_decode( (string) $row['payload_json'], true );
			$within  = (bool) ( $payload['within_window'] ?? true );
			$uid     = (string) $row['request_uid'];
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $payload['submitted_at'] ?? $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $payload['order_number'] ?? $row['order_ref'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['customer_email'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $payload['country'] ?? '' ) ) . '</td>';
			echo '<td>' . ( $within
				? '<span class="wwu-wb-badge wwu-wb-badge--ok">' . esc_html__( 'Yes', 'wwu-withdrawal-button' ) . '</span>'
				: '<span class="wwu-wb-badge wwu-wb-badge--warn">' . esc_html__( 'Flagged late', 'wwu-withdrawal-button' ) . '</span>' ) . '</td>';
			echo '<td><a href="' . esc_url( VerifiableLink::verify_url( $uid ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Verify', 'wwu-withdrawal-button' ) . '</a></td>';
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
}
