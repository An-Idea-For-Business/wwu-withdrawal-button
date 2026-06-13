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
		'foundation'    => 'suite_foundation',
		'tables'        => 'suite_tables',
		'collector'     => 'suite_collector',
		'audience'      => 'suite_audience',
		'labels'        => 'suite_labels',
		'applicability' => 'suite_applicability',
		'window'        => 'suite_window',
		'log'           => 'suite_log',
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
	 * Suite: labels (statutory wording per country + confirmation "only words").
	 *
	 * @return array
	 */
	private function suite_labels(): array {
		$tests    = array();
		$resolver = new \WWU\WithdrawalButton\Domain\LabelResolver();

		$cases = array(
			array( 'IT', 'it_IT', 'recedere dal contratto qui', 'conferma recesso' ),
			array( 'DE', 'de_DE', 'Vertrag widerrufen', 'Widerruf bestätigen' ),
			array( 'FR', 'fr_FR', 'renoncer au contrat ici', 'confirmer la rétractation' ),
			array( 'ES', 'es_ES', 'desistir del contrato aquí', 'confirmar desistimiento' ),
			array( 'IE', 'en_US', 'withdraw from contract here', 'confirm withdrawal' ),
		);
		foreach ( $cases as $case ) {
			list( $country, $locale, $withdraw, $confirm ) = $case;
			$tests[] = $this->assert(
				'labels.withdraw.' . strtolower( $country ),
				$resolver->withdraw_label( $country, $locale ) === $withdraw,
				$country . ' → "' . $resolver->withdraw_label( $country, $locale ) . '"'
			);
			$tests[] = $this->assert(
				'labels.confirm.' . strtolower( $country ),
				$resolver->confirm_label( $country, $locale ) === $confirm,
				$country . ' → "' . $resolver->confirm_label( $country, $locale ) . '"'
			);
		}

		// The German withdrawal label must NOT contain "hier".
		$tests[] = $this->assert(
			'labels.de_no_hier',
			false === stripos( $resolver->withdraw_label( 'DE', 'de_DE' ), 'hier' ),
			'German withdrawal label correctly omits "hier".'
		);

		return $tests;
	}

	/**
	 * Suite: applicability (EU mandatory, CH voluntary, B2B + Art.59 exclusions).
	 *
	 * @return array
	 */
	private function suite_applicability(): array {
		$tests    = array();
		$resolver = new \WWU\WithdrawalButton\Domain\ApplicabilityResolver();

		// Relies on the default seeded mode (eu_eea_only). Cases below are deterministic.
		$it   = $this->fake_order( 'IT', 'completed', false, array( $this->fake_item( false ) ) );
		$ch   = $this->fake_order( 'CH', 'completed', false, array( $this->fake_item( false ) ) );
		$b2b  = $this->fake_order( 'IT', 'completed', true, array( $this->fake_item( false ) ) );
		$only_digital = $this->fake_order( 'IT', 'completed', false, array( $this->fake_item( true ) ) );

		$d_it  = $resolver->decide( $it );
		$d_ch  = $resolver->decide( $ch );
		$d_b2b = $resolver->decide( $b2b );
		$d_dig = $resolver->decide( $only_digital );

		$tests[] = $this->assert( 'applicability.it_mandatory', $d_it->show && $d_it->mandatory, 'IT consumer: shown + mandatory.' );
		$tests[] = $this->assert( 'applicability.ch_not_mandatory', ! $d_ch->mandatory, 'CH consumer: not mandatory (reason: ' . $d_ch->reason . ').' );
		$tests[] = $this->assert( 'applicability.b2b_excluded', ! $d_b2b->show, 'B2B (VAT) excluded (reason: ' . $d_b2b->reason . ').' );
		$tests[] = $this->assert( 'applicability.art59_digital_excluded', ! $d_dig->show, 'Completed digital-only order excluded (reason: ' . $d_dig->reason . ').' );

		return $tests;
	}

	/**
	 * Suite: window (deadline computation + late detection).
	 *
	 * @return array
	 */
	private function suite_window(): array {
		$tests = array();
		$calc  = new \WWU\WithdrawalButton\Domain\WindowCalculator();

		$recent = $this->fake_order( 'IT', 'completed', false, array( $this->fake_item( false ) ), new \DateTimeImmutable( '-2 days' ) );
		$old    = $this->fake_order( 'IT', 'completed', false, array( $this->fake_item( false ) ), new \DateTimeImmutable( '-30 days' ) );

		$tests[] = $this->assert( 'window.recent_within', $calc->is_within_window( $recent ), 'Order 2 days old is within the 14-day window.' );
		$tests[] = $this->assert( 'window.old_outside', ! $calc->is_within_window( $old ), 'Order 30 days old is outside the window.' );
		$days = $calc->days_remaining( $recent );
		$tests[] = $this->assert( 'window.days_remaining', is_int( $days ) && $days >= 11 && $days <= 12, 'Days remaining ≈ 12 (got ' . var_export( $days, true ) . ').' );

		return $tests;
	}

	/**
	 * Suite: log (hash-chain logic — pure, does NOT write to the evidence table).
	 *
	 * @return array
	 */
	private function suite_log(): array {
		$tests = array();

		$prev = \WWU\WithdrawalButton\Storage\LogChain::genesis();
		$ev_a = array( 'event' => 'confirmed', 'payload' => array( 'b' => 2, 'a' => 1 ), 'ip_address' => '1.2.3.4' );
		$ev_b = array( 'event' => 'confirmed', 'payload' => array( 'a' => 1, 'b' => 2 ), 'ip_address' => '1.2.3.4' );

		$h_a = \WWU\WithdrawalButton\Storage\LogChain::compute( $prev, $ev_a );
		$h_b = \WWU\WithdrawalButton\Storage\LogChain::compute( $prev, $ev_b );

		$tests[] = $this->assert( 'log.hash_is_sha256', 64 === strlen( $h_a ) && ctype_xdigit( $h_a ), 'row_hash is 64 hex chars.' );
		$tests[] = $this->assert( 'log.order_independent', $h_a === $h_b, 'Canonicalisation makes the hash key-order independent.' );

		$ev_tampered = $ev_a;
		$ev_tampered['ip_address'] = '9.9.9.9';
		$h_t = \WWU\WithdrawalButton\Storage\LogChain::compute( $prev, $ev_tampered );
		$tests[] = $this->assert( 'log.tamper_sensitive', $h_a !== $h_t, 'Changing any evidence field changes the hash.' );

		$tests[] = $this->assert( 'log.genesis_stable', \WWU\WithdrawalButton\Storage\LogChain::genesis() === $prev, 'Genesis hash is stable per site.' );

		// Verify the live chain is intact (0 = no broken row).
		$broken = ( new \WWU\WithdrawalButton\Storage\LogRepository() )->verify_chain();
		$tests[] = $this->assert( 'log.chain_intact', 0 === $broken, 0 === $broken ? 'Live chain intact.' : 'Chain broken at row ' . $broken . '.' );

		return $tests;
	}

	/**
	 * Build a synthetic normalized order for tests.
	 *
	 * @param string                  $country Country code.
	 * @param string                  $status  Status.
	 * @param bool                    $vat     Whether a VAT number is present.
	 * @param array                   $items   Line items.
	 * @param \DateTimeImmutable|null $created Created date.
	 * @return \WWU\WithdrawalButton\Platform\NormalizedOrder
	 */
	private function fake_order( string $country, string $status, bool $vat, array $items, ?\DateTimeImmutable $created = null ): \WWU\WithdrawalButton\Platform\NormalizedOrder {
		$created = $created ?? new \DateTimeImmutable( '-1 day' );
		return new \WWU\WithdrawalButton\Platform\NormalizedOrder(
			'woocommerce',
			'TEST-1',
			'TEST-1',
			'buyer@example.com',
			0,
			$country,
			$status,
			'en_US',
			$created,
			$created,
			$status === 'completed' ? $created : null,
			$items,
			$vat
		);
	}

	/**
	 * Build a synthetic line item.
	 *
	 * @param bool $digital Whether the item is virtual/downloadable.
	 * @return array
	 */
	private function fake_item( bool $digital ): array {
		return array(
			'product_id'   => 123,
			'name'         => 'Test product',
			'qty'          => 1,
			'virtual'      => $digital,
			'downloadable' => $digital,
			'type'         => 'simple',
			'category_ids' => array(),
		);
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
