<?php
/**
 * Retention / purge routine for the stored exemption-consent records.
 *
 * GDPR storage limitation (Art. 5(1)(e) + recital 39) requires a defined erasure
 * horizon — an "immutable forever" record is itself a defect. The defensible
 * period is tied to the limitation/prescription window for a contractual claim
 * (Italy: ordinary 10 years, art. 2946 c.c.), so the merchant-configurable
 * `wwu_wb_settings['retention_years']` (default 10) drives it.
 *
 * What is purged: the personal data on the order meta `_wwu_wb_consent` — the
 * stored IP is anonymised once the horizon passes. The verbatim wording + its
 * SHA-256 hash are KEPT (they let the trader reconstruct exactly what was agreed
 * and are not, by themselves, identifying). The append-only hash-chained immutable
 * log is NEVER rewritten — it deliberately holds no IP for the consent events, so
 * there is nothing to purge there and the chain stays verifiable.
 *
 * Consent is captured on WooCommerce today, so the sweep runs over WooCommerce
 * orders; it is a no-op when WooCommerce is absent.
 *
 * @package WWU\WithdrawalButton
 *
 * @see docs/legal/wwu-wb-exemption-consent-evidence-NOTE.md
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Core;

use WWU\WithdrawalButton\Debug\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily consent-retention purge.
 */
final class ConsentRetention {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'wwu_wb_consent_retention_purge';

	/**
	 * Orders processed per run (bounded; re-queues if more remain).
	 *
	 * @var int
	 */
	private const BATCH = 100;

	/**
	 * Register the cron callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'purge' ) );
	}

	/**
	 * Schedule the daily sweep (idempotent). Called on activation.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the schedule. Called on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Anonymise the IP on consent records older than the retention horizon.
	 *
	 * @return void
	 */
	public function purge(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return; // Consent is captured on WooCommerce only (today).
		}

		// Record that the sweep ran (for the exemptions status panel).
		update_option( 'wwu_wb_consent_last_purge', gmdate( 'c' ), false );

		$years  = (int) ( Settings::main()['retention_years'] ?? 10 );
		$years  = max( 1, min( 30, $years ) );
		$cutoff = time() - ( $years * YEAR_IN_SECONDS );

		$order_ids = wc_get_orders(
			array(
				'limit'        => self::BATCH,
				'return'       => 'ids',
				'orderby'      => 'date',
				'order'        => 'ASC',
				'date_created' => '<' . $cutoff,
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded daily cron, not a hot path.
					array(
						'key'     => WWU_WB_META_PREFIX . 'consent',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => WWU_WB_META_PREFIX . 'consent_purged',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		if ( empty( $order_ids ) || ! is_array( $order_ids ) ) {
			return;
		}

		$purged = 0;
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$entries = $order->get_meta( WWU_WB_META_PREFIX . 'consent' );
			if ( is_array( $entries ) && ! empty( $entries ) ) {
				$changed = false;
				foreach ( $entries as $i => $entry ) {
					$entry = (array) $entry;
					if ( '' !== (string) ( $entry['ip'] ?? '' ) ) {
						$entry['ip']           = '';
						$entry['ip_purged_at'] = gmdate( 'c' );
						$entries[ $i ]         = $entry;
						$changed               = true;
					}
				}
				if ( $changed ) {
					$order->update_meta_data( WWU_WB_META_PREFIX . 'consent', $entries );
				}
			}

			$order->update_meta_data( WWU_WB_META_PREFIX . 'consent_purged', gmdate( 'c' ) );
			$order->save();
			++$purged;
		}

		Debug::log(
			'retention',
			'consent.purged',
			array(
				'count'         => $purged,
				'years'         => $years,
				'cutoff_gmt'    => gmdate( 'Y-m-d H:i:s', $cutoff ),
			)
		);

		// If the batch was full there may be more — run again shortly.
		if ( count( $order_ids ) >= self::BATCH ) {
			wp_schedule_single_event( time() + ( 5 * MINUTE_IN_SECONDS ), self::CRON_HOOK );
		}
	}
}
