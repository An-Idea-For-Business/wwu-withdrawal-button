<?php
/**
 * Smoke-test runner (Standard #11 + wwu-tools contract).
 *
 * Produces the canonical report shape:
 *   { summary:{pass,fail,skip,total}, suites:[{name,tests:[{name,status,output}]}] }
 *
 * F0 ships the foundation suites (constants, options, tables, collector,
 * audience). Each implementation phase appends its own suite (log chain,
 * timestamps, applicability, labels, durable medium, platforms, compat).
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

namespace WWU\WithdrawalButton\Debug;

use WWU\WithdrawalButton\Core\Migrator;
use WWU\WithdrawalButton\Storage\Database\LogTable;
use WWU\WithdrawalButton\Storage\Database\TimestampTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * In-process smoke tests.
 */
final class SmokeTests {

	/**
	 * Map of suite name → method.
	 *
	 * @var array<string,string>
	 */
	private const SUITES = array(
		'foundation' => 'suite_foundation',
		'tables'     => 'suite_tables',
		'collector'  => 'suite_collector',
		'audience'   => 'suite_audience',
	);

	/**
	 * Run a suite ('all' or a specific name) and return the canonical report.
	 *
	 * @param string $suite Suite name or 'all'.
	 * @return array
	 */
	public function run( string $suite = 'all' ): array {
		$suites_to_run = ( 'all' === $suite || ! isset( self::SUITES[ $suite ] ) )
			? array_keys( self::SUITES )
			: array( $suite );

		$report  = array();
		$summary = array(
			'pass'  => 0,
			'fail'  => 0,
			'skip'  => 0,
			'total' => 0,
		);

		foreach ( $suites_to_run as $name ) {
			$method = self::SUITES[ $name ];
			$tests  = $this->{$method}();

			foreach ( $tests as $test ) {
				++$summary['total'];
				$status = $test['status'] ?? 'fail';
				if ( isset( $summary[ $status ] ) ) {
					++$summary[ $status ];
				}
			}

			$report[] = array(
				'name'  => $name,
				'tests' => $tests,
			);
		}

		return array(
			'summary' => $summary,
			'suites'  => $report,
		);
	}

	/**
	 * Suite: foundation (constants + seeded options + per-site secret).
	 *
	 * @return array
	 */
	private function suite_foundation(): array {
		$tests = array();

		$tests[] = $this->assert(
			'foundation.constants_defined',
			defined( 'WWU_WB_VERSION' ) && defined( 'WWU_WB_REST_NAMESPACE' ) && defined( 'WWU_WB_SCHEMA_VERSION' ),
			'Core constants are defined.'
		);

		$settings = get_option( 'wwu_wb_settings' );
		$tests[]  = $this->assert(
			'foundation.settings_seeded',
			is_array( $settings ) && array_key_exists( 'enabled', $settings ),
			is_array( $settings ) ? 'wwu_wb_settings present.' : 'wwu_wb_settings missing.'
		);

		$tests[] = $this->assert(
			'foundation.secret_present',
			(bool) get_option( 'wwu_wb_secret' ),
			'Per-site secret generated.'
		);

		$db_version = (int) get_option( Migrator::OPTION_DB_VERSION, 0 );
		$tests[]    = $this->assert(
			'foundation.db_version_current',
			$db_version === (int) WWU_WB_SCHEMA_VERSION,
			sprintf( 'db_version=%d, target=%d.', $db_version, (int) WWU_WB_SCHEMA_VERSION )
		);

		return $tests;
	}

	/**
	 * Suite: tables (exist + immutability shape: no updated_at on the log).
	 *
	 * @return array
	 */
	private function suite_tables(): array {
		global $wpdb;
		$tests = array();

		$log_table = LogTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$log_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) );
		$tests[]    = $this->assert( 'tables.log_exists', $log_exists, $log_exists ? "Found {$log_table}." : "Missing {$log_table}." );

		$ts_table = TimestampTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ts_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ts_table ) );
		$tests[]   = $this->assert( 'tables.timestamps_exists', $ts_exists, $ts_exists ? "Found {$ts_table}." : "Missing {$ts_table}." );

		if ( $log_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$columns = $wpdb->get_col( "DESC {$log_table}", 0 );
			$columns = is_array( $columns ) ? $columns : array();

			$tests[] = $this->assert(
				'tables.log_append_only',
				! in_array( 'updated_at', $columns, true ) && ! in_array( 'deleted_at', $columns, true ),
				'Log table has no updated_at/deleted_at (append-only).'
			);

			$tests[] = $this->assert(
				'tables.log_chain_columns',
				in_array( 'prev_hash', $columns, true ) && in_array( 'row_hash', $columns, true ),
				'Log table has prev_hash + row_hash (hash chain).'
			);

			$tests[] = $this->assert(
				'tables.log_evidence_columns',
				in_array( 'ip_address', $columns, true ) && in_array( 'created_at', $columns, true ),
				'Log table stores ip_address + created_at (evidence).'
			);
		}

		return $tests;
	}

	/**
	 * Suite: collector (record round-trip + secret masking).
	 *
	 * @return array
	 */
	private function suite_collector(): array {
		$tests     = array();
		$collector = Collector::instance();

		$before = count( $collector->snapshot()['entries'] );
		$collector->record( 'debug', 'smoketest', 'roundtrip', array( 'foo' => 'bar' ) );
		$after = count( $collector->snapshot()['entries'] );
		$tests[] = $this->assert( 'collector.record_roundtrip', $after === $before + 1, 'Entry recorded.' );

		$collector->record( 'debug', 'smoketest', 'masking', array( 'api_key' => 'SECRET-1234' ) );
		$snapshot = $collector->snapshot();
		$last     = end( $snapshot['entries'] );
		$masked   = isset( $last['context']['api_key'] ) ? (string) $last['context']['api_key'] : '';
		$tests[]  = $this->assert(
			'collector.secret_masked',
			false === strpos( $masked, 'SECRET' ) && false !== strpos( $masked, '1234' ),
			'Secret-looking key masked to ••••••••••1234.'
		);

		return $tests;
	}

	/**
	 * Suite: audience (config shape + default closed).
	 *
	 * @return array
	 */
	private function suite_audience(): array {
		$tests  = array();
		$config = Audience::config();

		$tests[] = $this->assert(
			'audience.config_shape',
			is_array( $config ) && array_key_exists( 'mode', $config ) && array_key_exists( 'enabled', $config ),
			'Audience config has mode + enabled.'
		);

		$tests[] = $this->assert(
			'audience.valid_mode',
			in_array(
				$config['mode'],
				array(
					Audience::MODE_ALL_ADMINS,
					Audience::MODE_SPECIFIC_ROLES,
					Audience::MODE_SPECIFIC_USERS,
					Audience::MODE_CURRENT_USER_ONLY,
				),
				true
			),
			'Audience mode is a known value: ' . $config['mode'] . '.'
		);

		return $tests;
	}

	/**
	 * Build a single test result.
	 *
	 * @param string $name      Dotted test name.
	 * @param bool   $condition Pass condition.
	 * @param string $output    Human-readable output.
	 * @return array
	 */
	private function assert( string $name, bool $condition, string $output ): array {
		return array(
			'name'   => $name,
			'status' => $condition ? 'pass' : 'fail',
			'output' => $output,
		);
	}
}
