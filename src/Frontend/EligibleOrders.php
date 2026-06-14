<?php
/**
 * Builds the list of a logged-in customer's orders that are relevant to the
 * withdrawal function: those eligible to start a withdrawal now, plus those that
 * already have a withdrawal request (shown with their status).
 *
 * Shared by the WooCommerce My Account "Right of withdrawal" tab (WooMyAccount),
 * the FluentCart customer-portal endpoint (FluentCartPortal) and the public
 * [wwu_wb_form] page when opened without a specific order, so every surface
 * presents the customer with something actionable instead of an empty page.
 * Platform-agnostic: it merges the customer's WooCommerce and FluentCart orders,
 * each source guarded so an inactive platform simply contributes nothing.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Frontend;

use WWU\WithdrawalButton\Core\Services;
use WWU\WithdrawalButton\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eligible-orders list builder.
 */
final class EligibleOrders {

	/**
	 * How many recent orders to scan for the list.
	 *
	 * @var int
	 */
	private const SCAN_LIMIT = 20;

	/**
	 * Render the customer's withdrawal-relevant orders (or guidance when none).
	 *
	 * @param int $user_id Current user id.
	 * @return string
	 */
	public static function render_for_user( int $user_id ): string {
		// Merge orders from every active platform so a customer sees their
		// WooCommerce AND FluentCart orders in one chooser.
		$rows = array_merge( self::collect_woocommerce( $user_id ), self::collect_fluentcart( $user_id ) );

		$html = Template::render(
			'myaccount/withdrawal-list.php',
			array(
				'rows'       => $rows,
				'logged_in'  => $user_id > 0,
				'orders_url' => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : '',
			)
		);

		// Opt-in admin diagnostic (?wwu_wb_diag=1): explains, per FluentCart order,
		// what was found and why it is shown/hidden. Admin-only, read-only, never
		// rendered for customers. Use it on the standalone public form page.
		if ( isset( $_GET['wwu_wb_diag'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin diagnostic, no state change.
			$html .= self::render_diagnostic( $user_id );
		}

		return $html;
	}

	/**
	 * Admin diagnostic block: why FluentCart orders are shown/hidden for a user.
	 *
	 * Re-runs the FluentCart customer/order lookup and reports, per order, the
	 * normalized status, country, item count and the applicability decision +
	 * reason — the exact information needed to tell which gate hides an order.
	 *
	 * @param int $user_id User id.
	 * @return string Escaped HTML block.
	 */
	private static function render_diagnostic( int $user_id ): string {
		$lines   = array();
		$wp_user = get_userdata( $user_id );
		$lines[] = 'WP user: #' . $user_id . ' <' . ( $wp_user ? $wp_user->user_email : '?' ) . '>';
		$lines[] = 'WooCommerce rows shown: ' . count( self::collect_woocommerce( $user_id ) );

		$adapter = Services::instance()->platforms->get( 'fluentcart' );
		if ( ! $adapter ) {
			$lines[] = 'FluentCart: adapter NOT active for this request.';
		} else {
			$customer_model = '\\FluentCart\\App\\Models\\Customer';
			$order_model    = '\\FluentCart\\App\\Models\\Order';
			if ( ! class_exists( $customer_model ) || ! class_exists( $order_model ) ) {
				$lines[] = 'FluentCart: models NOT found.';
			} else {
				try {
					$customer = $customer_model::where( 'user_id', $user_id )->first();
					$matched  = $customer ? 'by user_id' : '';
					if ( ! $customer && $wp_user ) {
						$customer = $customer_model::where( 'email', (string) $wp_user->user_email )->first();
						$matched  = $customer ? 'by email' : '';
					}
					if ( ! $customer || ! isset( $customer->id ) ) {
						$lines[] = 'FluentCart: NO customer row for this user (neither user_id nor email). Orders cannot be matched.';
					} else {
						$lines[]  = 'FluentCart customer: #' . (int) $customer->id . ' (matched ' . $matched . ')';
						$orders   = $order_model::where( 'customer_id', $customer->id )->orderBy( 'created_at', 'desc' )->take( self::SCAN_LIMIT )->get();
						$count    = is_countable( $orders ) ? count( $orders ) : 0;
						$lines[]  = 'FluentCart orders for customer: ' . $count;
						$services = Services::instance();
						foreach ( (array) $orders as $fc ) {
							$ref = isset( $fc->id ) ? (string) $fc->id : '';
							$no  = '' !== $ref ? $adapter->get_order( $ref ) : null;
							if ( ! $no ) {
								$lines[] = '  - order ' . $ref . ': get_order() returned null';
								continue;
							}
							$d       = $services->applicability->decide( $no );
							$lines[] = sprintf(
								'  - #%s: status="%s" country="%s" items=%d enabled=%s -> show=%s reason=%s',
								$no->number,
								$no->status,
								$no->country,
								count( (array) $no->items ),
								Settings::enabled() ? 'yes' : 'NO',
								$d->show ? 'YES' : 'no',
								$d->reason
							);
						}
					}
				} catch ( \Throwable $e ) {
					$lines[] = 'FluentCart probe threw: ' . $e->getMessage();
				}
			}
		}

		$body = esc_html( implode( "\n", $lines ) );
		return '<pre class="wwu-wb-diag" style="margin-top:24px;padding:12px;border:1px solid #ccd0d4;background:#fff;color:#1d2327;font:12px/1.5 monospace;white-space:pre-wrap;overflow:auto;">'
			. "WWU Withdrawal — diagnostic (admin only)\n" . $body . '</pre>';
	}

	/**
	 * Collect the customer's withdrawal-relevant FluentCart orders.
	 *
	 * @param int $user_id Current user id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function collect_fluentcart( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$adapter = Services::instance()->platforms->get( 'fluentcart' );
		if ( ! $adapter ) {
			return array();
		}
		$customer_model = '\\FluentCart\\App\\Models\\Customer';
		$order_model    = '\\FluentCart\\App\\Models\\Order';
		if ( ! class_exists( $customer_model ) || ! class_exists( $order_model ) ) {
			return array();
		}

		$rows = array();
		try {
			$customer = $customer_model::where( 'user_id', $user_id )->first();
			// Some FluentCart customers are not linked to a WP user id (created via
			// guest / manual checkout). Fall back to matching by the user's email.
			if ( ! $customer || ! isset( $customer->id ) ) {
				$wp_user = get_userdata( $user_id );
				$wp_mail = $wp_user ? (string) $wp_user->user_email : '';
				if ( '' !== $wp_mail ) {
					$customer = $customer_model::where( 'email', $wp_mail )->first();
				}
			}
			if ( ! $customer || ! isset( $customer->id ) ) {
				return array();
			}
			$orders = $order_model::where( 'customer_id', $customer->id )
				->orderBy( 'created_at', 'desc' )
				->take( self::SCAN_LIMIT )
				->get();
		} catch ( \Throwable $e ) {
			return array();
		}

		$services = Services::instance();
		$enabled  = Settings::enabled();

		foreach ( (array) $orders as $fc_order ) {
			$order_id = isset( $fc_order->id ) ? (string) $fc_order->id : '';
			if ( '' === $order_id ) {
				continue;
			}
			$order = $adapter->get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$status    = (string) $adapter->get_meta( $order->order_ref, 'status' );
			$processed = (string) $adapter->get_meta( $order->order_ref, 'processed_at' );
			$eligible  = $enabled && $services->applicability->decide( $order )->show;

			if ( '' === $status && ! $eligible ) {
				continue;
			}

			if ( '' !== $processed ) {
				$status_label = __( 'Withdrawal handled', 'wwu-withdrawal-button' );
			} elseif ( '' !== $status ) {
				$status_label = __( 'Withdrawal requested', 'wwu-withdrawal-button' );
			} else {
				$status_label = '';
			}

			$locale = '' !== $order->locale ? $order->locale : determine_locale();
			$rows[] = array(
				'number'   => (string) $order->number,
				'date'     => $order->created ? wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $order->created->getTimestamp() ) : '',
				'status'   => $status_label,
				'eligible' => $eligible,
				'url'      => self::fluentcart_form_url( $order->order_ref ),
				'label'    => $services->labels->withdraw_label( $order->country, $locale ),
			);
		}

		return $rows;
	}

	/**
	 * Collect the customer's withdrawal-relevant WooCommerce orders.
	 *
	 * @param int $user_id Current user id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function collect_woocommerce( int $user_id ): array {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$adapter = Services::instance()->platforms->get( 'woocommerce' );
		if ( ! $adapter ) {
			return array();
		}

		$wc_orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => self::SCAN_LIMIT,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'type'        => 'shop_order',
			)
		);

		$services = Services::instance();
		$enabled  = Settings::enabled();
		$rows     = array();

		foreach ( (array) $wc_orders as $wc_order ) {
			if ( ! $wc_order instanceof \WC_Order ) {
				continue;
			}
			$order = $adapter->get_order( (string) $wc_order->get_id() );
			if ( ! $order ) {
				continue;
			}

			$status    = (string) $adapter->get_meta( $order->order_ref, 'status' );
			$processed = (string) $adapter->get_meta( $order->order_ref, 'processed_at' );
			$refunded  = (float) $wc_order->get_total_refunded();
			$eligible  = $enabled && $services->applicability->decide( $order )->show;

			// Only surface orders the customer can act on or has already acted on;
			// hide orders that are simply out of scope to avoid noise.
			if ( '' === $status && ! $eligible ) {
				continue;
			}

			// Consumer-facing, localized status — never the raw internal 'pending'
			// value, and reflecting the merchant's progress (refund / handled).
			if ( $refunded > 0 ) {
				$status_label = __( 'Refunded', 'wwu-withdrawal-button' );
			} elseif ( '' !== $processed ) {
				$status_label = __( 'Withdrawal handled', 'wwu-withdrawal-button' );
			} elseif ( '' !== $status ) {
				$status_label = __( 'Withdrawal requested', 'wwu-withdrawal-button' );
			} else {
				$status_label = '';
			}

			$created = $wc_order->get_date_created();
			$locale  = '' !== $order->locale ? $order->locale : determine_locale();

			$rows[] = array(
				'number'   => (string) $order->number,
				'date'     => $created ? wc_format_datetime( $created ) : '',
				'status'   => $status_label,
				'eligible' => $eligible,
				'url'      => self::form_url( $order->order_ref ),
				'label'    => $services->labels->withdraw_label( $order->country, $locale ),
			);
		}

		return $rows;
	}

	/**
	 * URL of the withdrawal form for a FluentCart order.
	 *
	 * Prefers the standalone public form page (a normal WordPress page that always
	 * renders the two-step form with our CSS/JS enqueued) so the link works on
	 * FluentCart-only stores too — where the WooCommerce account endpoint that
	 * {@see self::form_url()} relies on does not exist. Falls back to form_url().
	 *
	 * @param string $order_ref Order reference.
	 * @return string
	 */
	private static function fluentcart_form_url( string $order_ref ): string {
		$settings = (array) get_option( 'wwu_wb_settings', array() );
		$page_id  = (int) ( $settings['public_form_page_id'] ?? 0 );
		if ( $page_id > 0 ) {
			$permalink = get_permalink( $page_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return add_query_arg( 'wwu_wb_order', rawurlencode( $order_ref ), $permalink );
			}
		}
		return self::form_url( $order_ref );
	}

	/**
	 * URL of the withdrawal form for an order (account endpoint + order ref).
	 *
	 * @param string $order_ref Order reference.
	 * @return string
	 */
	private static function form_url( string $order_ref ): string {
		$settings = (array) get_option( 'wwu_wb_settings', array() );
		$slug     = sanitize_title( (string) ( $settings['endpoint_slug'] ?? 'wwu-withdrawal' ) );
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return add_query_arg( 'wwu_wb_order', rawurlencode( $order_ref ), wc_get_account_endpoint_url( $slug ) );
		}
		return add_query_arg( 'wwu_wb_order', rawurlencode( $order_ref ) );
	}
}
