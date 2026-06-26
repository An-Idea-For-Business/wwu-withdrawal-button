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
		'exemptions'     => 'suite_exemptions',
		'consent'        => 'suite_consent',
		'subscriptions'       => 'suite_subscriptions',
		'withdrawal_request'  => 'suite_withdrawal_request',
		'exemption_note'      => 'suite_exemption_note',
		'automations'         => 'suite_automations',
		'policy'              => 'suite_policy',
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
	 * Suite: the consolidated withdrawal policy (PolicyBuilder + PolicyDocument).
	 *
	 * @return array
	 */
	private function suite_policy(): array {
		$tests   = array();
		$builder = '\WWU\WithdrawalButton\Legal\PolicyBuilder';
		$ids_of  = static function ( $doc ) {
			return array_map(
				static function ( $section ) {
					return isset( $section['id'] ) ? (string) $section['id'] : '';
				},
				$doc->sections()
			);
		};

		$doc = $builder::build( 'en' );
		$ids = $ids_of( $doc );

		$tests[] = $this->assert( 'policy.has_title', '' !== $doc->title(), 'Document has a title.' );
		foreach ( array( 'right', 'how', 'refund', 'privacy', 'trader' ) as $must ) {
			$tests[] = $this->assert( 'policy.section.' . $must, in_array( $must, $ids, true ), 'Section "' . $must . '" present (got: ' . wp_json_encode( $ids ) . ').' );
		}
		$tests[] = $this->assert( 'policy.html_wrapped', false !== strpos( $doc->to_html(), 'wwu-wb-policy' ), 'to_html() wraps content in .wwu-wb-policy.' );
		$tests[] = $this->assert( 'policy.plain_nonempty', '' !== trim( $doc->to_plain() ), 'to_plain() is non-empty.' );

		// Exceptions section: present iff at least one exemption reason has targets.
		$saved = get_option( 'wwu_wb_exclusions' );

		update_option( 'wwu_wb_exclusions', array( 'by_reason' => array() ) );
		$tests[] = $this->assert( 'policy.exceptions.absent_when_none', ! in_array( 'exceptions', $ids_of( $builder::build( 'en' ) ), true ), 'Exceptions section omitted when no exemptions configured.' );

		update_option( 'wwu_wb_exclusions', array( 'by_reason' => array( '59_c' => array( 'products' => array( 123 ), 'categories' => array() ) ) ) );
		$tests[] = $this->assert( 'policy.exceptions.present_when_set', in_array( 'exceptions', $ids_of( $builder::build( 'en' ) ), true ), 'Exceptions section present when an exemption reason has targets.' );

		if ( false === $saved ) {
			delete_option( 'wwu_wb_exclusions' );
		} else {
			update_option( 'wwu_wb_exclusions', $saved );
		}

		// Section allow-list (shortcode sections="right").
		$only_ids = $ids_of( $builder::build( 'en', array( 'sections' => array( 'right' ) ) ) );
		$tests[] = $this->assert( 'policy.sections_allowlist', array( 'right' ) === $only_ids, 'sections allow-list keeps only the requested ids (got: ' . wp_json_encode( $only_ids ) . ').' );

		// PDF surface (skip when Dompdf is not bundled, e.g. a plain source install).
		if ( \WWU\WithdrawalButton\DurableMedium\PdfBuilder::is_available() ) {
			$pdf_html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $builder::build( 'en' )->to_html() . '</body></html>';
			$pdf      = ( new \WWU\WithdrawalButton\DurableMedium\PdfBuilder() )->render( $pdf_html );
			$tests[]  = $this->assert( 'policy.pdf.renders', is_string( $pdf ) && 0 === strpos( (string) $pdf, '%PDF' ), 'PolicyBuilder HTML renders to a %PDF document.' );
		} else {
			$tests[] = array(
				'name'   => 'policy.pdf.renders',
				'status' => 'skip',
				'output' => 'Dompdf not available (source install) — PDF render skipped.',
			);
		}

		return $tests;
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

		// Handling mode (Auto / Always / Off) + native-add-on auto-defer. Mutates the
		// settings option then restores it, so the live site is unchanged afterwards.
		$saved    = get_option( 'wwu_wb_settings', array() );
		$set_mode = static function ( $mode ) {
			$s                    = (array) get_option( 'wwu_wb_settings', array() );
			$s['fluentcart_mode'] = $mode;
			update_option( 'wwu_wb_settings', $s );
			\WWU\WithdrawalButton\Core\Settings::flush( 'wwu_wb_settings' );
		};

		$tests[] = $this->assert( 'fluentcart.mode_whitelisted', in_array( $adapter::mode(), array( 'auto', 'always', 'off' ), true ), 'mode() is one of auto/always/off (got "' . $adapter::mode() . '").' );

		// native_addon_active(): false by default, forced true via the filter.
		$native_default = $adapter::native_addon_active();
		add_filter( 'wwu_wb_fluentcart_native_active', '__return_true' );
		$native_filtered = $adapter::native_addon_active();
		remove_filter( 'wwu_wb_fluentcart_native_active', '__return_true' );
		$tests[] = $this->assert( 'fluentcart.native_default_false', false === $native_default, 'No native add-on signal by default → false.' );
		$tests[] = $this->assert( 'fluentcart.native_filterable', true === $native_filtered, 'wwu_wb_fluentcart_native_active filter forces detection true.' );

		// should_render(): off → never; always → always (even with native present);
		// auto → defers to the native add-on.
		$set_mode( 'off' );
		$off = $adapter::should_render();
		$set_mode( 'always' );
		add_filter( 'wwu_wb_fluentcart_native_active', '__return_true' );
		$always_native = $adapter::should_render();
		remove_filter( 'wwu_wb_fluentcart_native_active', '__return_true' );
		$set_mode( 'auto' );
		$auto_plain = $adapter::should_render();
		add_filter( 'wwu_wb_fluentcart_native_active', '__return_true' );
		$auto_native = $adapter::should_render();
		remove_filter( 'wwu_wb_fluentcart_native_active', '__return_true' );

		$tests[] = $this->assert( 'fluentcart.render_off', false === $off, 'Off mode → never render our FluentCart surfaces.' );
		$tests[] = $this->assert( 'fluentcart.render_always_overrides_native', true === $always_native, 'Always mode renders even when a native add-on is detected.' );
		$tests[] = $this->assert( 'fluentcart.render_auto_plain', true === $auto_plain, 'Auto mode renders when no native add-on is present.' );
		$tests[] = $this->assert( 'fluentcart.render_auto_defers', false === $auto_native, 'Auto mode steps aside when the native add-on is detected.' );

		// Restore the option exactly as it was before this suite ran.
		update_option( 'wwu_wb_settings', $saved );
		\WWU\WithdrawalButton\Core\Settings::flush( 'wwu_wb_settings' );

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

		// --- Type-aware window start (1.2.9): digital = order date, physical = delivery (completed). ---
		// Distinct paid vs completed dates so each branch is observable.
		$cls         = '\\WWU\\WithdrawalButton\\Platform\\NormalizedOrder';
		$d_paid      = new \DateTimeImmutable( '-10 days' );
		$d_completed = new \DateTimeImmutable( '-2 days' );
		$mk          = function ( array $items, $completed, string $status = 'completed' ) use ( $cls, $d_paid ) {
			// Args: platform, ref, number, email, customer_id, country, status, locale,
			// created, paid, completed, items, has_vat, is_renewal, subscription_ref.
			return new $cls( 'woocommerce', 'T', 'T', 'b@example.com', 0, 'IT', $status, 'en_US', $d_paid, $d_paid, $completed, $items, false, false, '' );
		};

		$digital   = $mk( array( $this->fake_item( true ) ), $d_completed );
		$physical  = $mk( array( $this->fake_item( false ) ), $d_completed );
		$mixed     = $mk( array( $this->fake_item( true ), $this->fake_item( false ) ), $d_completed );
		$phys_open = $mk( array( $this->fake_item( false ) ), null, 'processing' );
		$no_items  = $mk( array(), $d_completed );

		$tests[] = $this->assert( 'window.digital_uses_order_date', $digital->window_start() == $d_paid, 'All-digital order starts the clock at the order date (paid), not the later completed date.' );
		$tests[] = $this->assert( 'window.physical_uses_completed', $physical->window_start() == $d_completed, 'Physical order starts at the completed (delivery proxy) date — unchanged behaviour.' );
		$tests[] = $this->assert( 'window.mixed_treated_physical', $mixed->window_start() == $d_completed, 'Mixed cart (digital + physical) is treated as physical (completed date).' );
		$tests[] = $this->assert( 'window.physical_uncompleted_fallback', $phys_open->window_start() == $d_paid, 'Physical order not yet completed falls back to the order date (countdown preserved).' );
		$tests[] = $this->assert( 'window.empty_items_physical_fallback', $no_items->window_start() == $d_completed, 'Unknown/empty items are treated as physical (completed → paid → created).' );

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

		// Chain v2: the row hash is keyed (HMAC) and differs from the legacy v1
		// (unkeyed SHA-256) form; the default version is the current one.
		$h_v1 = \WWU\WithdrawalButton\Storage\LogChain::compute( $prev, $ev_a, 1 );
		$h_v2 = \WWU\WithdrawalButton\Storage\LogChain::compute( $prev, $ev_a, 2 );
		$tests[] = $this->assert(
			'log.v2_keyed',
			$h_v1 !== $h_v2 && $h_a === $h_v2,
			'v2 row hash is HMAC-keyed (differs from v1) and is the current default.'
		);

		// IP anonymisation for the hashed evidence (full IP kept separately).
		$tests[] = $this->assert(
			'log.ip_anonymized',
			'203.0.113.0' === \WWU\WithdrawalButton\Security\ClientInfo::anonymize_ip( '203.0.113.45' )
				&& '' === \WWU\WithdrawalButton\Security\ClientInfo::anonymize_ip( '' ),
			'IPv4 is anonymised (last octet zeroed); empty stays empty.'
		);

		// Schema v3: the new columns exist (ip_full purgeable + per-row chain_version).
		global $wpdb;
		$log_table = \WWU\WithdrawalButton\Storage\Database\LogTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is constant-derived; read-only column introspection.
		$cols   = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$log_table}" );
		$has_v3 = in_array( 'ip_full', $cols, true ) && in_array( 'chain_version', $cols, true );
		$tests[] = $this->assert( 'log.schema_v3_columns', $has_v3, 'Log table has ip_full + chain_version (schema v3).' );

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

		$el = \WWU\WithdrawalButton\Security\Sanitizer::email_list( ' a@x.com , not-an-email , b@y.com, a@x.com ' );
		$tests[] = $this->assert(
			'durable_medium.email_list_multi',
			'a@x.com, b@y.com' === $el,
			'email_list() keeps valid addresses, drops invalid, trims and de-duplicates (got "' . $el . '").'
		);
		$tests[] = $this->assert(
			'durable_medium.email_list_single',
			'solo@x.com' === \WWU\WithdrawalButton\Security\Sanitizer::email_list( 'solo@x.com' ),
			'email_list() leaves a single valid address intact (back-compatible).'
		);
		$tests[] = $this->assert(
			'durable_medium.first_email',
			'a@x.com' === \WWU\WithdrawalButton\Security\Sanitizer::first_email( 'a@x.com, b@y.com' )
				&& '' === \WWU\WithdrawalButton\Security\Sanitizer::first_email( '' ),
			'first_email() returns the primary address (the receipt trader contact) and "" for an empty list.'
		);

		return $tests;
	}

	/**
	 * Suite: exemptions (Art. 59 registry + per-reason resolver + evaluator gates).
	 *
	 * @return array
	 */
	private function suite_exemptions(): array {
		$tests = array();
		$types = '\\WWU\\WithdrawalButton\\Domain\\ExceptionTypes';
		$ev    = new \WWU\WithdrawalButton\Domain\ArticleFiftyNineEvaluator();

		// Registry sanity.
		$tests[] = $this->assert( 'exemptions.registry_digital_conditional', $types::is_conditional( '59_o' ), 'Digital-immediate (59_o) is conditional.' );
		$tests[] = $this->assert( 'exemptions.registry_service_conditional', $types::is_conditional( '59_a' ), 'Service-performed (59_a) is conditional.' );
		$tests[] = $this->assert( 'exemptions.registry_custom_unconditional', ! $types::is_conditional( '59_c' ), 'Custom-made (59_c) is unconditional.' );
		$tests[] = $this->assert( 'exemptions.registry_hygiene_seal', $types::is_seal_based( '59_e' ), 'Sealed hygiene (59_e) is seal-based.' );

		// Helper to build a single-item / multi-item order.
		$mk = function ( array $product_ids ) {
			$items = array();
			foreach ( $product_ids as $pid ) {
				$items[] = array( 'product_id' => (int) $pid, 'name' => 'P' . $pid, 'qty' => 1, 'virtual' => false, 'downloadable' => false, 'type' => 'simple', 'category_ids' => array() );
			}
			return $this->fake_order( 'IT', 'completed', false, $items );
		};

		// Back-compat filter → 'manual' (unconditional) → exempt.
		$f = static function ( $ids ) {
			$ids[] = 4242;
			return $ids;
		};
		add_filter( 'wwu_wb_excluded_product_ids', $f );
		$tests[] = $this->assert( 'exemptions.filter_excludes_single', ! $ev->has_withdrawable_item( $mk( array( 4242 ) ) ), 'Filter-excluded single-item order has no withdrawable item.' );
		$tests[] = $this->assert( 'exemptions.mixed_cart_still_shows', $ev->has_withdrawable_item( $mk( array( 4242, 1 ) ) ), 'Mixed cart (excluded + normal) stays withdrawable.' );
		remove_filter( 'wwu_wb_excluded_product_ids', $f );

		// Per-reason via option: tag product 7777 under the conditional 59_o.
		$saved = get_option( 'wwu_wb_exclusions' );
		update_option(
			'wwu_wb_exclusions',
			array(
				'by_reason'           => array( '59_o' => array( 'products' => array( 7777 ), 'categories' => array() ) ),
				'auto_detect_virtual' => false,
			)
		);
		\WWU\WithdrawalButton\Core\Settings::flush();

		$tests[] = $this->assert( 'exemptions.conditional_no_consent_keeps_button', $ev->has_withdrawable_item( $mk( array( 7777 ) ) ), 'Conditional reason WITHOUT captured consent keeps the button (fail-safe).' );

		$cf = static function ( $consent ) {
			$consent[] = array( 'product_id' => 7777, 'reason_id' => '59_o' );
			return $consent;
		};
		add_filter( 'wwu_wb_exemption_consent', $cf );
		$tests[] = $this->assert( 'exemptions.conditional_with_consent_exempts', ! $ev->has_withdrawable_item( $mk( array( 7777 ) ) ), 'Conditional reason WITH captured consent exempts the item.' );
		remove_filter( 'wwu_wb_exemption_consent', $cf );

		// Tag a product under a seal-based reason → never auto-hidden.
		update_option(
			'wwu_wb_exclusions',
			array(
				'by_reason'           => array( '59_e' => array( 'products' => array( 8888 ), 'categories' => array() ) ),
				'auto_detect_virtual' => false,
			)
		);
		\WWU\WithdrawalButton\Core\Settings::flush();
		$tests[] = $this->assert( 'exemptions.seal_based_keeps_button', $ev->has_withdrawable_item( $mk( array( 8888 ) ) ), 'Seal-based reason never auto-hides the button.' );

		// Unconditional reason → exempt.
		update_option(
			'wwu_wb_exclusions',
			array(
				'by_reason'           => array( '59_c' => array( 'products' => array( 9999 ), 'categories' => array() ) ),
				'auto_detect_virtual' => false,
			)
		);
		\WWU\WithdrawalButton\Core\Settings::flush();
		$tests[] = $this->assert( 'exemptions.unconditional_exempts', ! $ev->has_withdrawable_item( $mk( array( 9999 ) ) ), 'Unconditional reason (custom-made) exempts the item.' );

		// Restore.
		update_option( 'wwu_wb_exclusions', is_array( $saved ) ? $saved : array() );
		\WWU\WithdrawalButton\Core\Settings::flush();

		return $tests;
	}

	/**
	 * Suite: checkout-consent capture (P2) — wording, cart reason lookup, entry build.
	 *
	 * Covers the pure, WooCommerce-independent pieces of the consent-capture layer:
	 * the statutory wording resolver, the order-independent reason lookup used at
	 * checkout, and the storable-entry builder. The evaluator round-trip (consent →
	 * exempt) is already covered by suite_exemptions via the consent filter.
	 *
	 * @return array
	 */
	private function suite_consent(): array {
		$tests = array();
		$ct    = '\\WWU\\WithdrawalButton\\Domain\\ConsentText';
		$res   = '\\WWU\\WithdrawalButton\\Domain\\ExemptionResolver';
		$cc    = '\\WWU\\WithdrawalButton\\Frontend\\WooCheckoutConsent';

		// Wording: conditional reasons get text; unconditional/seal-based get ''.
		$digital = (string) $ct::for_reason( '59_o' );
		$service = (string) $ct::for_reason( '59_a' );
		$tests[] = $this->assert( 'consent.text_digital_present', '' !== $digital, 'Digital-immediate (59_o) has acknowledgement wording.' );
		$tests[] = $this->assert( 'consent.text_service_present', '' !== $service, 'Service-performed (59_a) has acknowledgement wording.' );
		$tests[] = $this->assert( 'consent.text_kinds_differ', $digital !== $service && '' !== $digital, 'Digital and service wordings differ.' );
		$tests[] = $this->assert( 'consent.text_unconditional_empty', '' === (string) $ct::for_reason( '59_c' ), 'Unconditional reason (59_c) has no acknowledgement wording.' );

		// Wording is filterable.
		$flt = static function () {
			return 'CUSTOM-ACK';
		};
		add_filter( 'wwu_wb_consent_text', $flt, 10, 3 );
		$tests[] = $this->assert( 'consent.text_filterable', 'CUSTOM-ACK' === (string) $ct::for_reason( '59_o' ), 'wwu_wb_consent_text overrides the wording.' );
		remove_filter( 'wwu_wb_consent_text', $flt, 10 );

		// Order-independent reason lookup (checkout path).
		$saved = get_option( 'wwu_wb_exclusions' );
		update_option(
			'wwu_wb_exclusions',
			array(
				'by_reason'           => array(
					'59_o' => array(
						'products'   => array( 7777 ),
						'categories' => array(),
					),
					'59_c' => array(
						'products'   => array(),
						'categories' => array( 55 ),
					),
				),
				'auto_detect_virtual' => false,
			)
		);
		\WWU\WithdrawalButton\Core\Settings::flush();
		\WWU\WithdrawalButton\Domain\ExceptionTypes::reset_cache();

		$tests[] = $this->assert( 'consent.reason_for_product', '59_o' === (string) $res::reason_for( 7777, array() ), 'reason_for() resolves a tagged product to its reason.' );
		$tests[] = $this->assert( 'consent.reason_for_category', '59_c' === (string) $res::reason_for( 0, array( 55 ) ), 'reason_for() resolves a tagged category to its reason.' );
		$tests[] = $this->assert( 'consent.reason_for_none', null === $res::reason_for( 4321, array( 9 ) ), 'reason_for() returns null for an untagged product.' );

		// Entry builder: only ticked + conditional reasons produce entries.
		$map     = array(
			'59_o' => array( 7777, 7778 ),
			'59_c' => array( 9999 ), // unconditional → must be skipped even if "ticked".
		);
		$posted  = array(
			'59_o' => true,
			'59_c' => true,
		);
		$entries = $cc::build_consent_entries( $map, $posted, '203.0.113.7' );
		$tests[] = $this->assert( 'consent.entries_count', 2 === count( $entries ), 'Two entries built (one per conditional product), unconditional skipped.' );
		$first   = $entries[0] ?? array();
		$tests[] = $this->assert( 'consent.entry_shape', '59_o' === ( $first['reason_id'] ?? '' ) && 7777 === (int) ( $first['product_id'] ?? 0 ) && '' !== ( $first['text_hash'] ?? '' ) && '203.0.113.7' === ( $first['ip'] ?? '' ), 'Entry carries reason, product, text hash and IP.' );

		// Unticked reason → no entry.
		$none = $cc::build_consent_entries( array( '59_o' => array( 7777 ) ), array( '59_o' => false ), '' );
		$tests[] = $this->assert( 'consent.untiked_no_entry', empty( $none ), 'An un-ticked reason produces no consent entry.' );

		// ClauseLibrary: the exemption-consent privacy clause exists in IT + EN.
		$cl      = '\\WWU\\WithdrawalButton\\Legal\\ClauseLibrary';
		$tests[] = $this->assert( 'consent.clause_registered', in_array( 'consent_privacy', $cl::types(), true ), 'consent_privacy clause type is registered.' );
		$tests[] = $this->assert( 'consent.clause_it', '' !== trim( (string) $cl::get( 'consent_privacy', 'it' ) ), 'IT exemption-consent privacy clause is present.' );
		$tests[] = $this->assert( 'consent.clause_en', '' !== trim( (string) $cl::get( 'consent_privacy', 'en' ) ), 'EN exemption-consent privacy clause is present.' );

		// ClauseLibrary override (Settings → Legal clauses): a saved override is
		// returned verbatim (no disclaimer), has_override() flips, and default_text()
		// still returns the built-in template. The option is restored BEFORE asserting
		// so a failing assertion never leaks test data into the merchant's clauses.
		$clause_opt    = $cl::OPTION;
		$clause_backup = get_option( $clause_opt, null );
		$marker        = 'WWU_WB_SMOKE_OVERRIDE_' . wp_generate_password( 6, false );
		update_option( $clause_opt, array( 'terms' => array( 'it' => $marker ) ), false );
		$got = (string) $cl::get( 'terms', 'it' );
		$has = (bool) $cl::has_override( 'terms', 'it' );
		$def = (string) $cl::default_text( 'terms', 'it' );
		if ( null === $clause_backup ) {
			delete_option( $clause_opt );
		} else {
			update_option( $clause_opt, $clause_backup, false );
		}
		$tests[] = $this->assert( 'clauses.override_verbatim', $marker === $got, 'A saved clause override is returned verbatim (no disclaimer appended).' );
		$tests[] = $this->assert( 'clauses.has_override', true === $has, 'has_override() reports a saved override.' );
		$tests[] = $this->assert( 'clauses.default_text_is_template', '' !== trim( $def ) && $def !== $marker, 'default_text() returns the built-in template, not the override.' );

		// ExemptionConfirmation: no-op guards (no email / no entries → no send, no side effects).
		$ec      = '\\WWU\\WithdrawalButton\\Mail\\ExemptionConfirmation';
		$tests[] = $this->assert( 'consent.confirmation_guard_no_email', false === $ec::send_for_order( 'woocommerce', '1', '', '#1', array( array( 'reason_id' => '59_o' ) ) ), 'Confirmation is not sent without a valid e-mail.' );
		$tests[] = $this->assert( 'consent.confirmation_guard_no_entries', false === $ec::send_for_order( 'woocommerce', '1', 'buyer@example.com', '#1', array() ), 'Confirmation is not sent without entries.' );

		// Grouping derivation + per-reason examples (exemptions-UX bundle).
		$et      = '\\WWU\\WithdrawalButton\\Domain\\ExceptionTypes';
		$tests[] = $this->assert( 'consent.group_conditional', 'conditional' === $et::group( '59_o' ) && 'conditional' === $et::group( '59_a' ), 'Digital/service reasons group as conditional.' );
		$tests[] = $this->assert( 'consent.group_seal', 'seal_based' === $et::group( '59_e' ) && 'seal_based' === $et::group( '59_i' ), 'Sealed reasons group as seal_based.' );
		$tests[] = $this->assert( 'consent.group_unconditional', 'unconditional' === $et::group( '59_c' ) && 'unconditional' === $et::group( 'manual' ), 'Custom-made / manual group as unconditional.' );
		$all_have_example = true;
		foreach ( $et::all() as $rdef ) {
			if ( empty( $rdef['example'] ) ) {
				$all_have_example = false;
				break;
			}
		}
		$tests[] = $this->assert( 'consent.every_reason_has_example', $all_have_example, 'Every registered reason ships a Standard #12 example.' );

		// Consumer preview reuses the real e-mail builder.
		$tests[] = $this->assert( 'consent.preview_digital_present', '' !== (string) $ec::preview_html( '59_o' ), 'Digital reason has a consumer preview.' );
		$tests[] = $this->assert( 'consent.preview_unconditional_empty', '' === (string) $ec::preview_html( '59_c' ), 'Unconditional reason has no preview.' );

		// EDD adapter status mapping (pure, no EDD needed).
		$tests[] = $this->assert( 'consent.edd_eligible_status', 'completed' === \WWU\WithdrawalButton\Platform\EddAdapter::eligible_status( 'complete' ), 'EDD "complete" maps to the eligible "completed" status.' );

		// Restore.
		update_option( 'wwu_wb_exclusions', is_array( $saved ) ? $saved : array() );
		\WWU\WithdrawalButton\Core\Settings::flush();
		\WWU\WithdrawalButton\Domain\ExceptionTypes::reset_cache();

		return $tests;
	}

	/**
	 * Build a synthetic normalized order for tests.
	 *
	 * @param string                  $country          Country code.
	 * @param string                  $status           Status.
	 * @param bool                    $vat              Whether a VAT number is present.
	 * @param array                   $items            Line items.
	 * @param \DateTimeImmutable|null $created          Created date.
	 * @param bool                    $is_renewal       Whether this is a subscription renewal order.
	 * @param string                  $subscription_ref Subscription id tied to the order, or ''.
	 * @return \WWU\WithdrawalButton\Platform\NormalizedOrder
	 */
	private function fake_order( string $country, string $status, bool $vat, array $items, ?\DateTimeImmutable $created = null, bool $is_renewal = false, string $subscription_ref = '' ): \WWU\WithdrawalButton\Platform\NormalizedOrder {
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
			$vat,
			$is_renewal,
			$subscription_ref
		);
	}

	/**
	 * Suite: subscriptions (renewal-suppression gate + flag defaults + toggle).
	 *
	 * Pure resolver-level checks (no subscription plugin needed): a renewal order is
	 * suppressed by default, the initial order keeps the button, and the opt-in
	 * `treat_renewals_as_withdrawable` toggle flips the renewal case.
	 *
	 * @return array
	 */
	private function suite_subscriptions(): array {
		$tests    = array();
		$resolver = new \WWU\WithdrawalButton\Domain\ApplicabilityResolver();
		$item     = array( $this->fake_item( false ) );

		// NormalizedOrder back-compat: the new flags default to false/'' so every
		// pre-subscription constructor call is unaffected.
		$plain   = $this->fake_order( 'IT', 'completed', false, $item );
		$tests[] = $this->assert( 'subscriptions.flags_default', false === $plain->is_renewal && '' === $plain->subscription_ref, 'NormalizedOrder defaults: is_renewal=false, subscription_ref="".' );

		// Save + clear the toggle so the default (suppress on renewal) is exercised.
		$settings = (array) get_option( 'wwu_wb_settings', array() );
		$saved    = $settings;
		$settings['treat_renewals_as_withdrawable'] = false;
		update_option( 'wwu_wb_settings', $settings );
		\WWU\WithdrawalButton\Core\Settings::flush();

		// Initial order (not a renewal, but linked to a subscription) → shown + mandatory.
		$initial = $this->fake_order( 'IT', 'completed', false, $item, null, false, 'SUB-1' );
		$d_init  = $resolver->decide( $initial );
		$tests[] = $this->assert( 'subscriptions.initial_shown', $d_init->show && $d_init->mandatory, 'Initial subscription order is shown + mandatory (subscription_ref does not suppress).' );

		// Renewal order → suppressed with the renewal_order reason.
		$renewal = $this->fake_order( 'IT', 'completed', false, $item, null, true, 'SUB-1' );
		$d_renew = $resolver->decide( $renewal );
		$tests[] = $this->assert( 'subscriptions.renewal_suppressed', ! $d_renew->show && 'renewal_order' === $d_renew->reason, 'Renewal order is suppressed (reason: ' . $d_renew->reason . ').' );

		// Opt-in: treating renewals as withdrawable flips the renewal case to shown.
		$settings['treat_renewals_as_withdrawable'] = true;
		update_option( 'wwu_wb_settings', $settings );
		\WWU\WithdrawalButton\Core\Settings::flush();
		$d_renew_on = $resolver->decide( $this->fake_order( 'IT', 'completed', false, $item, null, true, 'SUB-1' ) );
		$tests[]    = $this->assert( 'subscriptions.renewal_optin_shown', $d_renew_on->show, 'With treat_renewals_as_withdrawable on, the renewal order is shown again.' );

		// A non-EU renewal is out of scope before the renewal gate is even reached
		// (status → renewal → B2B → Art.59 → scope ordering); reason is country-based.
		$d_ch_renew = $resolver->decide( $this->fake_order( 'CH', 'completed', false, $item, null, true, 'SUB-1' ) );
		$tests[]    = $this->assert( 'subscriptions.noneu_renewal_not_mandatory', ! $d_ch_renew->mandatory, 'A non-EU renewal is never mandatory (reason: ' . $d_ch_renew->reason . ').' );

		// Restore.
		update_option( 'wwu_wb_settings', $saved );
		\WWU\WithdrawalButton\Core\Settings::flush();

		return $tests;
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

		// HTTPS is required by default (the TSA signature is verified out-of-band).
		try {
			$http_prov = new \WWU\WithdrawalButton\Timestamp\Rfc3161Provider( array( 'endpoint' => 'http://timestamp.example.com' ) );
			$valid     = new \ReflectionMethod( $http_prov, 'endpoint_is_valid' );
			$valid->setAccessible( true );
			$tests[] = $this->assert( 'rfc3161.https_required', false === $valid->invoke( $http_prov ), 'A plaintext http TSA endpoint is rejected by default.' );
		} catch ( \Throwable $e ) {
			$tests[] = $this->assert( 'rfc3161.https_required', false, 'Reflection check failed: ' . $e->getMessage() );
		}

		// Un-anchored confirmed-row count (surfaced in admin; cron retries them).
		$unanchored = ( new \WWU\WithdrawalButton\Timestamp\TimestampService() )->count_unanchored();
		$tests[] = $this->assert( 'timestamp.unanchored_count', is_int( $unanchored ) && $unanchored >= 0, 'Un-anchored confirmed-row count is a non-negative int (got ' . var_export( $unanchored, true ) . ').' );

		// Return the flat test array like every other suite — run() wraps it in
		// { name, tests }. Returning the wrapped shape here double-wrapped it, so
		// the JSON `tests` became an object and the Inspector's forEach threw.
		return $tests;
	}

	/**
	 * Suite: withdrawal_request (WithdrawalRequest::from_input products pipeline).
	 *
	 * Covers: normal round-trip, DoS cap (>50 truncated), per-element cap (>200 chars
	 * truncated), and absent/empty input resulting in an empty array while is_valid()
	 * remains unaffected.
	 *
	 * @return array
	 */
	private function suite_withdrawal_request(): array {
		$tests = array();
		$class = '\\WWU\\WithdrawalButton\\Domain\\WithdrawalRequest';

		/* (a) Normal products array round-trips via to_array(). */
		$req_a = $class::from_input(
			array(
				'order_ref' => '99',
				'name'     => 'Jane',
				'email'    => 'jane@example.com',
				'country'  => 'IT',
				'reason'   => '',
				'products' => array( 'Widget A', 'Widget B' ),
			)
		);
		$arr_a   = $req_a->to_array();
		$tests[] = $this->assert(
			'withdrawal_request.products_roundtrip',
			isset( $arr_a['products'] ) && array( 'Widget A', 'Widget B' ) === $arr_a['products'],
			'Products round-trip via to_array() (got: ' . wp_json_encode( $arr_a['products'] ?? null ) . ').'
		);

		/* (a2) Per-line quantity: kept for a selected product, dropped when invalid (0) or orphan. */
		$req_q = $class::from_input(
			array(
				'order_ref'   => '99',
				'name'        => 'Jane',
				'email'       => 'jane@example.com',
				'reason'      => '',
				'products'    => array( 'Widget A', 'Widget B' ),
				'product_qty' => array(
					'Widget A' => '2',  // selected + positive  => kept
					'Widget B' => '0',  // selected but invalid => dropped (whole line)
					'Ghost'    => '5',  // not selected         => dropped (orphan)
				),
			)
		);
		$arr_q   = $req_q->to_array();
		$tests[] = $this->assert(
			'withdrawal_request.product_quantities.partial_kept',
			isset( $arr_q['product_quantities']['Widget A'] ) && 2 === $arr_q['product_quantities']['Widget A'],
			'Partial quantity for a selected product is recorded (got: ' . wp_json_encode( $arr_q['product_quantities'] ?? null ) . ').'
		);
		$tests[] = $this->assert(
			'withdrawal_request.product_quantities.invalid_dropped',
			empty( $arr_q['product_quantities']['Widget B'] ),
			'A zero/blank quantity is dropped (treated as the whole line).'
		);
		$tests[] = $this->assert(
			'withdrawal_request.product_quantities.orphan_dropped',
			! isset( $arr_q['product_quantities']['Ghost'] ),
			'A quantity for a non-selected product is dropped.'
		);
		$tests[] = $this->assert(
			'withdrawal_request.products.shape_unchanged_with_qty',
			array( 'Widget A', 'Widget B' ) === $arr_q['products'],
			'products[] keeps its flat string-array shape when product_qty is present (back-compat).'
		);

		/* (b) Array with more than 50 elements is capped to 50. */
		$long_list = array_map( static function ( $i ) {
			return 'Product ' . $i;
		}, range( 1, 75 ) );
		$req_b   = $class::from_input(
			array(
				'order_ref' => '99',
				'name'     => 'Jane',
				'email'    => 'jane@example.com',
				'country'  => 'IT',
				'reason'   => '',
				'products' => $long_list,
			)
		);
		$arr_b   = $req_b->to_array();
		$tests[] = $this->assert(
			'withdrawal_request.products_cap_50',
			isset( $arr_b['products'] ) && 50 === count( $arr_b['products'] ),
			'Oversized array (75 items) capped to 50 (got: ' . count( $arr_b['products'] ?? array() ) . ').'
		);

		/* (c) An element longer than 200 chars is truncated to ≤ 200 chars. */
		$long_name = str_repeat( 'X', 300 );
		$req_c     = $class::from_input(
			array(
				'order_ref' => '99',
				'name'     => 'Jane',
				'email'    => 'jane@example.com',
				'country'  => 'IT',
				'reason'   => '',
				'products' => array( $long_name ),
			)
		);
		$arr_c         = $req_c->to_array();
		$first_product = isset( $arr_c['products'][0] ) ? (string) $arr_c['products'][0] : '';
		$tests[]       = $this->assert(
			'withdrawal_request.product_element_cap_200',
			mb_strlen( $first_product ) <= 200 && mb_strlen( $first_product ) > 0,
			'300-char element truncated to ≤ 200 chars (got: ' . mb_strlen( $first_product ) . ').'
		);

		/* (d) Absent/empty products → to_array()['products'] === [] and is_valid() is still true. */
		$req_d = $class::from_input(
			array(
				'order_ref' => '99',
				'name'     => 'Jane',
				'email'    => 'jane@example.com',
				'country'  => 'IT',
				'reason'   => '',
			)
		);
		$arr_d   = $req_d->to_array();
		$tests[] = $this->assert(
			'withdrawal_request.products_absent_empty_array',
			isset( $arr_d['products'] ) && array() === $arr_d['products'],
			'Absent products key yields empty array in to_array().'
		);
		$tests[] = $this->assert(
			'withdrawal_request.is_valid_unaffected',
			$req_d->is_valid(),
			'is_valid() remains true when no products are selected.'
		);

		return $tests;
	}

	/**
	 * Suite: Art. 59 exemption transparency note (ExemptionNoteRenderer).
	 *
	 * Verifies that:
	 * (a) an order with NO matched reason returns '' (fail-safe / no phantom note),
	 * (b) an order with an unconditional reason returns a non-empty HTML note that
	 *     names the statutory exception,
	 * (c) a custom_exemption_note setting overrides the built-in copy.
	 *
	 * @return array
	 */
	private function suite_exemption_note(): array {
		$tests    = array();
		$renderer = '\\WWU\\WithdrawalButton\\Frontend\\ExemptionNoteRenderer';

		// Save + restore option state so the tests don't pollute each other.
		$saved_exclusions = get_option( 'wwu_wb_exclusions' );
		$saved_settings   = (array) get_option( 'wwu_wb_settings', array() );

		/* (a) No exemption rule for product 111 → renderer returns ''. */
		update_option(
			'wwu_wb_exclusions',
			array(
				'by_reason'           => array(),
				'auto_detect_virtual' => false,
			)
		);
		\WWU\WithdrawalButton\Core\Settings::flush();
		$order_plain = $this->fake_order(
			'IT',
			'completed',
			false,
			array( array( 'product_id' => 111, 'name' => 'Plain product', 'qty' => 1, 'virtual' => false, 'downloadable' => false, 'type' => 'simple', 'category_ids' => array() ) )
		);
		$note_a  = $renderer::render( $order_plain );
		$tests[] = $this->assert(
			'exemption_note.fail_safe_empty',
			'' === $note_a,
			'No exemption rule → renderer returns empty string (got: ' . ( '' === $note_a ? 'empty' : mb_substr( $note_a, 0, 80 ) ) . ').'
		);

		/* (b) Unconditional reason (59_c custom-made) → note names the exception. */
		update_option(
			'wwu_wb_exclusions',
			array(
				'by_reason'           => array( '59_c' => array( 'products' => array( 222 ), 'categories' => array() ) ),
				'auto_detect_virtual' => false,
			)
		);
		\WWU\WithdrawalButton\Core\Settings::flush();
		// Ensure no custom override is active.
		$settings_b = $saved_settings;
		$settings_b['custom_exemption_note'] = '';
		update_option( 'wwu_wb_settings', $settings_b );
		\WWU\WithdrawalButton\Core\Settings::flush();

		$order_exempt = $this->fake_order(
			'IT',
			'completed',
			false,
			array( array( 'product_id' => 222, 'name' => 'Custom-made product', 'qty' => 1, 'virtual' => false, 'downloadable' => false, 'type' => 'simple', 'category_ids' => array() ) )
		);
		$note_b  = $renderer::render( $order_exempt );
		$tests[] = $this->assert(
			'exemption_note.reason_names_exception',
			'' !== $note_b && false !== strpos( $note_b, 'wwu-wb-exempt-note' ),
			'Exempt order → note HTML contains .wwu-wb-exempt-note (got: ' . mb_substr( $note_b, 0, 120 ) . ').'
		);

		/* (c) custom_exemption_note in settings overrides built-in copy. */
		$settings_c = $saved_settings;
		$settings_c['custom_exemption_note'] = '<p>Custom store policy note.</p>';
		update_option( 'wwu_wb_settings', $settings_c );
		\WWU\WithdrawalButton\Core\Settings::flush();

		$note_c  = $renderer::render( $order_exempt );
		$tests[] = $this->assert(
			'exemption_note.custom_override_wins',
			'' !== $note_c && false !== strpos( $note_c, 'Custom store policy note.' ),
			'Custom note override wins over built-in copy (got: ' . mb_substr( $note_c, 0, 120 ) . ').'
		);

		// Restore.
		update_option( 'wwu_wb_exclusions', is_array( $saved_exclusions ) ? $saved_exclusions : array() );
		update_option( 'wwu_wb_settings', $saved_settings );
		\WWU\WithdrawalButton\Core\Settings::flush();

		return $tests;
	}

	/**
	 * Suite: automations (read API + webhook). All assertions are read-only — they
	 * never write to the append-only evidence log. The privacy crux (no email / no
	 * IP in the list shape) is asserted against whatever real confirmed rows exist,
	 * so it holds on live data too.
	 *
	 * @return array
	 */
	private function suite_automations(): array {
		$tests   = array();
		$webhook = '\\WWU\\WithdrawalButton\\Api\\Webhook';
		$guard   = '\\WWU\\WithdrawalButton\\Security\\OutboundUrlGuard';

		// Wiring: the classes are present.
		$tests[] = $this->assert(
			'automations.classes_present',
			class_exists( '\\WWU\\WithdrawalButton\\Api\\RequestReader' )
				&& class_exists( '\\WWU\\WithdrawalButton\\Api\\Webhook' )
				&& class_exists( '\\WWU\\WithdrawalButton\\Api\\WebhookDispatcher' )
				&& class_exists( '\\WWU\\WithdrawalButton\\REST\\Routes\\ApiRoutes' ),
			'RequestReader / Webhook / WebhookDispatcher / ApiRoutes all autoload.'
		);

		// HMAC signature format + correctness (known vector).
		$body     = '{"event":"withdrawal.confirmed"}';
		$secret   = 'test-secret-0123456789';
		$sig      = $webhook::sign( $body, $secret );
		$expected = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		$tests[]  = $this->assert(
			'automations.hmac_signature_correct',
			$sig === $expected && 0 === strpos( $sig, 'sha256=' ) && 64 === strlen( substr( $sig, 7 ) ),
			'sign() returns sha256=<64-hex HMAC-SHA256(body, secret)>.'
		);
		$tests[] = $this->assert(
			'automations.hmac_deterministic',
			$webhook::sign( $body, $secret ) === $sig,
			'sign() is deterministic for the same body + secret.'
		);

		// Masking never reveals more than the last 4 characters.
		$plain  = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$masked = $webhook::masked_secret( $plain );
		$tests[] = $this->assert(
			'automations.secret_masked',
			false === strpos( $masked, $plain ) && substr( $masked, -4 ) === substr( $plain, -4 ) && false !== strpos( $masked, "\u{2022}" ),
			'masked_secret() hides everything but the last 4 chars (got: ' . $masked . ').'
		);
		$tests[] = $this->assert(
			'automations.generated_secret_strong',
			40 === strlen( $webhook::generate_secret() ) && 1 === preg_match( '/^[A-Za-z0-9]+$/', $webhook::generate_secret() ),
			'generate_secret() returns a 40-char alphanumeric key.'
		);

		// Default config is inactive (no accidental deliveries on a fresh install).
		$cfg     = $webhook::config();
		$tests[] = $this->assert(
			'automations.webhook_inactive_by_default',
			isset( $cfg['enabled'], $cfg['url'], $cfg['secret'] ) && ( ! $webhook::is_active() || '' !== $cfg['url'] ),
			'Webhook config has a stable shape and is not active unless fully configured.'
		);

		// SSRF guard refuses internal targets (defence shared with the webhook send path).
		$tests[] = $this->assert(
			'automations.ssrf_blocks_metadata',
			false === $guard::is_safe_url( 'http://169.254.169.254/latest/meta-data/' ),
			'OutboundUrlGuard rejects the cloud-metadata address.'
		);
		$tests[] = $this->assert(
			'automations.ssrf_blocks_private_loopback',
			false === $guard::is_safe_url( 'http://10.0.0.1/hook' ) && false === $guard::is_safe_url( 'http://[::1]/hook' ),
			'OutboundUrlGuard rejects private + loopback hosts (IPv4 + IPv6).'
		);

		// Reader: unknown lookups return null (read-only, no writes).
		$reader  = new \WWU\WithdrawalButton\Api\RequestReader();
		$tests[] = $this->assert(
			'automations.detail_unknown_null',
			null === $reader->detail( 'no-such-uid-0000' ),
			'detail() returns null for an unknown request_uid.'
		);
		$tests[] = $this->assert(
			'automations.order_status_unknown_null',
			null === $reader->order_status( 'wwu_wb_no_platform', 'nonexistent-ref' ),
			'order_status() returns null for an unknown platform/order.'
		);
		$tests[] = $this->assert(
			'automations.webhook_payload_unknown_null',
			null === $reader->webhook_payload( 'no-such-uid-0000' ),
			'webhook_payload() returns null for an unknown request_uid.'
		);

		// List: per_page is clamped to the cap, the structure is well-formed, and the
		// status-filter EXISTS subqueries are valid SQL (they must execute clean).
		$listed  = $reader->list( array(), 1, 999 );
		$tests[] = $this->assert(
			'automations.list_per_page_clamped',
			isset( $listed['per_page'] ) && \WWU\WithdrawalButton\Api\RequestReader::MAX_PER_PAGE === (int) $listed['per_page'],
			'list() clamps per_page to MAX_PER_PAGE (got: ' . ( $listed['per_page'] ?? 'n/a' ) . ').'
		);
		$filtered = $reader->list( array( 'status' => 'refunded' ), 1, 10 );
		$tests[]  = $this->assert(
			'automations.list_status_filter_sql_valid',
			is_array( $filtered['rows'] ?? null ) && is_int( $filtered['total'] ?? null ),
			'list() with a status filter executes the EXISTS subqueries without error.'
		);

		// Privacy crux: NO list row ever carries the email or the raw IP, and every
		// row has the public request_uid. Asserted against real data when present.
		$leaks = false;
		foreach ( (array) ( $listed['rows'] ?? array() ) as $row ) {
			if ( array_key_exists( 'consumer_email', $row ) || array_key_exists( 'ip_address', $row ) || array_key_exists( 'ip', $row ) || ! array_key_exists( 'request_uid', $row ) ) {
				$leaks = true;
				break;
			}
		}
		$tests[] = $this->assert(
			'automations.list_rows_omit_pii',
			! $leaks,
			'List rows never expose consumer_email or the raw IP (checked ' . count( (array) ( $listed['rows'] ?? array() ) ) . ' row(s)).'
		);

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
