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
		'window'         => 'suite_window',
		'log'            => 'suite_log',
		'durable_medium' => 'suite_durable_medium',
		'rfc3161'        => 'suite_rfc3161',
		'fluentcart'     => 'suite_fluentcart',
	);

	/**
	 * List the available suite names (single source of truth for the UI buttons,
	 * so the Inspector never drifts out of sync with the registered suites).
	 *
	 * @return string[]
	 */
	public static function suite_names(): array {
		return array_keys( self::SUITES );
	}

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

		// Digital auto-exclusion now defaults OFF (the right is the default; the
		// digital exemption needs captured consent). A completed digital order is
		// shown UNLESS the merchant has opted in. Invariant: shown iff auto-detect off.
		$exclusions = (array) \WWU\WithdrawalButton\Core\Settings::get( 'wwu_wb_exclusions' );
		$auto_on    = ! empty( $exclusions['auto_detect_virtual'] );
		$tests[]    = $this->assert( 'applicability.digital_matches_auto_detect', $d_dig->show === ! $auto_on, 'Completed digital order shown iff auto-detect off (auto_detect=' . ( $auto_on ? 'on' : 'off' ) . ', show=' . ( $d_dig->show ? 'yes' : 'no' ) . ').' );

		// Deterministic exclusion via the wwu_wb_excluded_product_ids filter.
		$excl_filter = static function ( $ids ) {
			$ids[] = 123;
			return $ids;
		};
		add_filter( 'wwu_wb_excluded_product_ids', $excl_filter );
		$d_excl = $resolver->decide( $this->fake_order( 'IT', 'completed', false, array( $this->fake_item( false ) ) ) );
		remove_filter( 'wwu_wb_excluded_product_ids', $excl_filter );
		$tests[] = $this->assert( 'applicability.excluded_product_hidden', ! $d_excl->show && 'no_withdrawal_right' === $d_excl->reason, 'Product in the excluded list is hidden (reason: ' . $d_excl->reason . ').' );

		// Regression (alpha.20): an order with NO readable items must still be
		// withdrawable by default — the right is the default, Art.59 is the exception.
		$d_noitem = $resolver->decide( $this->fake_order( 'IT', 'paid', false, array() ) );
		$tests[]  = $this->assert( 'applicability.empty_items_withdrawable', $d_noitem->show, 'Order with no readable items defaults to withdrawable (reason: ' . $d_noitem->reason . ').' );

		// Regression (alpha.20): FluentCart signals a concluded contract via a 'paid'
		// status — it must read as an eligible, mandatory case for an EU consumer.
		$d_paid  = $resolver->decide( $this->fake_order( 'IT', 'paid', false, array( $this->fake_item( false ) ) ) );
		$tests[] = $this->assert( 'applicability.paid_status_eligible', $d_paid->show && $d_paid->mandatory, "'paid' status is an eligible concluded contract." );

		// Regression (alpha.20): an undeterminable country is out of scope (hidden)
		// in the default eu_eea_only mode.
		$d_noc   = $resolver->decide( $this->fake_order( '', 'paid', false, array( $this->fake_item( false ) ) ) );
		$tests[] = $this->assert( 'applicability.empty_country_out_of_scope', ! $d_noc->show && 'out_of_scope' === $d_noc->reason, 'Empty country → out_of_scope/hidden (reason: ' . $d_noc->reason . ').' );

		return $tests;
	}

	/**
	 * Suite: fluentcart (platform-agnostic adapter helpers — Eloquent collection
	 * unwrap + payment-status normalization). Runs without FluentCart active.
	 *
	 * @return array
	 */
	private function suite_fluentcart(): array {
		$tests   = array();
		$adapter = '\\WWU\\WithdrawalButton\\Platform\\FluentCartAdapter';

		// Collection unwrap: a fake Eloquent-like collection exposing ->all().
		$collection = new class() {
			/**
			 * Mimic Illuminate\Support\Collection::all().
			 *
			 * @return array
			 */
			public function all(): array {
				return array( (object) array( 'id' => 7 ), (object) array( 'id' => 8 ) );
			}
		};
		$unwrapped = $adapter::unwrap_collection( $collection );
		$tests[]   = $this->assert(
			'fluentcart.collection_unwrap',
			is_array( $unwrapped ) && 2 === count( $unwrapped ) && isset( $unwrapped[0]->id ) && 7 === $unwrapped[0]->id,
			'Eloquent collection unwrapped to its models via ->all() (not (array) internals).'
		);
		$tests[] = $this->assert( 'fluentcart.unwrap_array_passthrough', array( 'a', 'b' ) === $adapter::unwrap_collection( array( 'a', 'b' ) ), 'Plain array passes through unwrap.' );
		$tests[] = $this->assert( 'fluentcart.unwrap_scalar_empty', array() === $adapter::unwrap_collection( 'x' ), 'Scalar unwraps to empty array (no crash).' );

		// Status: payment_status drives eligibility.
		$tests[] = $this->assert( 'fluentcart.status_paid', 'paid' === $adapter::eligible_status( 'pending', 'paid' ), 'pending fulfillment + paid payment → "paid" (eligible).' );
		$tests[] = $this->assert( 'fluentcart.status_keep_completed', 'completed' === $adapter::eligible_status( 'completed', 'paid' ), 'completed fulfillment preserved.' );
		$tests[] = $this->assert( 'fluentcart.status_unpaid', 'pending' === $adapter::eligible_status( 'pending', 'unpaid' ), 'unpaid order keeps its non-eligible status.' );

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
	 * Suite: durable_medium (link token round-trip, PDF availability, store path).
	 *
	 * @return array
	 */
	private function suite_durable_medium(): array {
		$tests = array();
		$uid   = '00000000-0000-4000-8000-000000000000';

		$token = \WWU\WithdrawalButton\DurableMedium\VerifiableLink::token( $uid );
		$tests[] = $this->assert(
			'durable_medium.link_token_roundtrip',
			\WWU\WithdrawalButton\DurableMedium\VerifiableLink::verify( $uid, $token ) && ! \WWU\WithdrawalButton\DurableMedium\VerifiableLink::verify( $uid, $token . 'x' ),
			'Receipt link token verifies and rejects tampering.'
		);

		$available = \WWU\WithdrawalButton\DurableMedium\PdfBuilder::is_available();
		$tests[] = array(
			'name'   => 'durable_medium.pdf_library',
			'status' => $available ? 'pass' : 'skip',
			'output' => $available ? 'Dompdf available — PDF receipts enabled.' : 'Dompdf not installed (run composer install). Email-only durable medium still works.',
		);

		$path = ( new \WWU\WithdrawalButton\DurableMedium\ReceiptStore() )->path_for( $uid );
		$tests[] = $this->assert(
			'durable_medium.store_path',
			false !== strpos( $path, 'wwu-wb/receipts' ) && false !== strpos( $path, $uid . '.pdf' ),
			'Receipt store path is confined and uid-named.'
		);

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
	/**
	 * RFC 3161 provider: request building + status parsing + guards (offline).
	 *
	 * @return array
	 */
	private function suite_rfc3161(): array {
		$tests = array();

		// Guard: empty endpoint makes no request and returns null.
		$empty = new \WWU\WithdrawalButton\Timestamp\Rfc3161Provider( array( 'endpoint' => '' ) );
		$tests[] = $this->assert( 'rfc3161.empty_endpoint_null', null === $empty->stamp( str_repeat( 'a', 64 ) ), 'Empty endpoint returns null (no request made).' );

		// Guard: a non-hex digest returns null before any network call.
		$prov = new \WWU\WithdrawalButton\Timestamp\Rfc3161Provider( array( 'endpoint' => 'http://timestamp.sectigo.com' ) );
		$tests[] = $this->assert( 'rfc3161.bad_hex_null', null === $prov->stamp( 'not-a-hex' ), 'Non-hex digest returns null.' );

		// Pure-logic checks via reflection (no network).
		try {
			$ref    = new \ReflectionClass( $prov );
			$build  = $ref->getMethod( 'build_request' );
			$build->setAccessible( true );
			$status = $ref->getMethod( 'read_status' );
			$status->setAccessible( true );

			$digest = hash( 'sha256', 'wwu', true );
			$der    = (string) $build->invoke( $prov, $digest, "\x12\x34\x56\x78" );

			$tests[] = $this->assert( 'rfc3161.request_is_sequence', isset( $der[0] ) && "\x30" === $der[0], 'TimeStampReq is a DER SEQUENCE.' );
			$tests[] = $this->assert( 'rfc3161.request_has_sha256_oid', false !== strpos( $der, "\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01" ), 'Request carries the SHA-256 OID.' );
			$tests[] = $this->assert( 'rfc3161.request_embeds_digest', false !== strpos( $der, $digest ), 'Request embeds the 32-byte digest.' );

			// SEQUENCE { SEQUENCE { INTEGER n } } — PKIStatus.
			$tests[] = $this->assert( 'rfc3161.status_granted', 0 === $status->invoke( $prov, "\x30\x06\x30\x03\x02\x01\x00" ), 'Parses PKIStatus 0 (granted).' );
			$tests[] = $this->assert( 'rfc3161.status_rejected', 2 === $status->invoke( $prov, "\x30\x06\x30\x03\x02\x01\x02" ), 'Parses PKIStatus 2 (rejection).' );
			$tests[] = $this->assert( 'rfc3161.status_garbage_null', null === $status->invoke( $prov, 'xx' ), 'Garbage response yields null status.' );
		} catch ( \Throwable $e ) {
			$tests[] = $this->assert( 'rfc3161.reflection', false, 'Reflection check failed: ' . $e->getMessage() );
		}

		// Return the flat test array like every other suite — run() wraps it in
		// { name, tests }. Returning the wrapped shape here double-wrapped it, so
		// the JSON `tests` became an object and the Inspector's forEach threw.
		return $tests;
	}

	private function assert( string $name, bool $condition, string $output ): array {
		return array(
			'name'   => $name,
			'status' => $condition ? 'pass' : 'fail',
			'output' => $output,
		);
	}
}
