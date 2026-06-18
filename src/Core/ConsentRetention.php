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
 * What is purged:
 *   - Order-meta consent records (`_wwu_wb_consent`): the stored IP is anonymised
 *     once the horizon passes. The verbatim wording + its SHA-256 hash are KEPT
 *     (they let the trader reconstruct what was agreed and are not, alone,
 *     identifying).
 *   - Immutable log: the NON-HASHED PII columns — the full IP (`ip_full`) and
 *     `customer_email` — are blanked on withdrawal-event rows past the horizon. The
 *     hashed evidence commits only to the ANONYMISED IP, so the chain is never
 *     rewritten and stays verifiable. (The full IP is retained until the horizon for
 *     legal evidence, then erased per GDPR storage limitation.)
 *
 * The log sweep is platform-agnostic; the consent-record sweep runs over WooCommerce
 * orders (consent is captured on WooCommerce today) and is a no-op without it.
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
		// Record that the sweep ran (for the exemptions status panel).
		update_option( 'wwu_wb_consent_last_purge', gmdate( 'c' ), false );

		$years  = (int) ( Settings::main()['retention_years'] ?? 10 );
		$years  = max( 1, min( 30, $years ) );
		$cutoff = time() - ( $years * YEAR_IN_SECONDS );

		// Immutable log: erase the non-hashed PII columns (full IP + customer email)
		// on rows past the horizon. Platform-agnostic, so it runs even when
		// WooCommerce is absent (the log can hold FluentCart / EDD rows too).
		$this->purge_log( $cutoff );

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return; // Consent records are captured on WooCommerce only (today).
		}

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

	/**
	 * Erase the non-hashed PII columns on immutable-log rows past the retention
	 * horizon (GDPR Art. 5(1)(e) storage limitation).
	 *
	 * ONLY `ip_full` (the full IP, never part of the hash) and `customer_email`
	 * (also never hashed) are blanked. The hashed evidence — which commits to the
	 * ANONYMISED IP and the event/contract data — is never touched, so the chain
	 * stays verifiable. Bounded per run; re-queues if the batch was full.
	 *
	 * @param int $cutoff Unix timestamp; rows created before it are purged.
	 * @return void
	 */
	private function purge_log( int $cutoff ): void {
		global $wpdb;
		$table      = \WWU\WithdrawalButton\Storage\Database\LogTable::name();
		$cutoff_gmt = gmdate( 'Y-m-d H:i:s', $cutoff );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is constant-derived; all values are bound.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE created_at < %s AND ( ip_full <> '' OR customer_email <> '' ) ORDER BY id ASC LIMIT %d",
				$cutoff_gmt,
				self::BATCH
			)
		);
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			$wpdb->update(
				$table,
				array(
					'ip_full'        => '',
					'customer_email' => '',
				),
				array( 'id' => (int) $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		Debug::log( 'retention', 'log.purged', array( 'count' => count( $ids ), 'cutoff_gmt' => $cutoff_gmt ) );

		// More may remain — nudge a follow-up run (the daily cron continues anyway).
		if ( count( $ids ) >= self::BATCH ) {
			wp_schedule_single_event( time() + ( 5 * MINUTE_IN_SECONDS ), self::CRON_HOOK );
		}
	}
}
