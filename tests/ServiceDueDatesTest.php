<?php
/**
 * Tests for the service payment due-date engine.
 *
 * The due-date rules live in af_service_schedules and are materialised as
 * af_charges, which is what makes the alert hub (and the existing payment
 * tracking) work. These tests cover the pure decision logic: due-date
 * resolution, rule validation, mixed pricing and the status derivation, plus
 * the generation loop against a $wpdb double.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * @param float $number    Value to format.
	 * @param int   $decimals  Decimal places.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals, '.', ',' );
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * @param string $single Singular form.
	 * @param string $plural Plural form.
	 * @param int    $number Count.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * @param array|object $args     Provided arguments.
	 * @param array        $defaults Default arguments.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		} elseif ( ! is_array( $args ) ) {
			parse_str( (string) $args, $args );
		}

		return array_merge( (array) $defaults, (array) $args );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param string   $hook Hook name.
	 * @param callable $cb   Callback.
	 * @return bool
	 */
	function add_action( $hook, $cb ) {
		return true;
	}
}

require_once __DIR__ . '/../includes/class-property-structure.php';
require_once __DIR__ . '/../includes/class-tenancy.php';
require_once __DIR__ . '/../includes/class-billing-ledger.php';

// wp_send_json_success() / wp_send_json_error() and check_ajax_referer() are
// intentionally NOT stubbed here: tests/security/GuestUpdateTest.php already
// provides throwing versions of the first pair, and whichever file PHPUnit
// happens to load first would otherwise silently win and break the other.
// The AJAX test below catches \Throwable and reads ->payload, so it works with
// either implementation.

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

/**
 * Minimal $wpdb double: records schedules, charges and payments, and answers the
 * SELECTs the ledger issues.
 */
class AF_Service_Test_WPDB {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * WordPress posts table, referenced by the joins in the queries under test.
	 *
	 * @var string
	 */
	public $posts = 'wp_posts';

	/**
	 * Rows of af_service_schedules.
	 *
	 * @var array<int,object>
	 */
	public $schedules = array();

	/**
	 * Rows of af_charges.
	 *
	 * @var array<int,object>
	 */
	public $charges = array();

	/**
	 * Rows of af_meter_readings.
	 *
	 * @var array<int,object>
	 */
	public $readings = array();

	/**
	 * Active leases keyed by id.
	 *
	 * @var array<int,object>
	 */
	public $leases = array();

	/**
	 * Auto-increment counters per table.
	 *
	 * @var array<string,int>
	 */
	public $next_id = array();

	/**
	 * Id produced by the last insert, like $wpdb->insert_id.
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Rows affected by the last query, like $wpdb->rows_affected.
	 *
	 * @var int
	 */
	public $rows_affected = 0;

	/**
	 * Inserts performed, in order.
	 *
	 * @var array<int,array>
	 */
	public $inserts = array();

	/**
	 * Updates performed, in order.
	 *
	 * @var array<int,array>
	 */
	public $updates = array();

	/**
	 * Interpolates the query like $wpdb does. Placeholders are positional (%s, %d)
	 * and a single array argument is unpacked, so the ledger can pass either
	 * style. The generated SQL is kept in $prepared for assertions.
	 *
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$index = 0;

		$sql = preg_replace_callback(
			'/%[sdfF]/',
			function ( $match ) use ( &$index, $args ) {
				$value = array_key_exists( $index, $args ) ? $args[ $index ] : '';
				++$index;

				if ( 'd' === substr( $match[0], -1 ) ) {
					return (string) (int) $value;
				}

				return "'" . addslashes( (string) $value ) . "'";
			},
			(string) $query
		);

		$this->prepared[] = $sql;

		return $sql;
	}

	/**
	 * Every SQL string passed through prepare(), in order.
	 *
	 * @var array<int,string>
	 */
	public $prepared = array();

	/**
	 * Last query handed to get_results(), for SQL assertions.
	 *
	 * @var string
	 */
	public $last_sql = '';

	/**
	 * Optional override consulted by get_results(), so a test can inspect the
	 * SQL and return hand-built rows.
	 *
	 * @var callable|null
	 */
	public $on_results = null;

	/**
	 * @param string $table  Table name.
	 * @param array  $data   Row data.
	 * @param array  $format Column formats.
	 * @return int|false
	 */
	public function insert( $table, $data, $format = null ) {
		$this->inserts[] = array(
			'table' => $table,
			'data'  => $data,
		);

		$short = $this->short_table( $table );
		$id    = $this->next_id[ $short ] = ( $this->next_id[ $short ] ?? 0 ) + 1;
		$this->insert_id = $id;

		$data['id'] = $id;

		if ( 'af_charges' === $short ) {
			$data += array(
				'amount_paid' => 0,
				'status'      => 'pending',
			);
			$this->charges[ $id ] = (object) $data;
		} elseif ( 'af_service_schedules' === $short ) {
			$this->schedules[ $id ] = (object) $data;
		}

		return $id;
	}

	/**
	 * @param string $table        Table name.
	 * @param array  $data         Row data.
	 * @param array  $where        Where clause.
	 * @param array  $format       Column formats.
	 * @param array  $where_format Where formats.
	 * @return int|false
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->updates[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);

		$short = $this->short_table( $table );

		if ( 'af_charges' === $short && isset( $where['id'] ) ) {
			$id = (int) $where['id'];
			if ( isset( $this->charges[ $id ] ) ) {
				foreach ( $data as $key => $value ) {
					$this->charges[ $id ]->{$key} = $value;
				}
			}
		}

		if ( 'af_service_schedules' === $short && isset( $where['id'] ) ) {
			$id = (int) $where['id'];
			if ( isset( $this->schedules[ $id ] ) ) {
				foreach ( $data as $key => $value ) {
					$this->schedules[ $id ]->{$key} = $value;
				}
			}
		}

		return 1;
	}

	/**
	 * @param string $table  Table name.
	 * @param array  $data   Row data.
	 * @param array  $where  Where clause.
	 * @return int|false
	 */
	public function delete( $table, $where, $where_format = null ) {
		$short = $this->short_table( $table );

		if ( 'af_meter_readings' === $short && isset( $where['id'] ) ) {
			unset( $this->readings[ (int) $where['id'] ] );
			return 1;
		}

		return 0;
	}

	/**
	 * @param string $sql   Query.
	 * @param int    $count Result count.
	 * @return int
	 */
	public function get_var( $sql, $count = 1 ) {
		$sql = (string) $sql;

		// Existing-charge lookup by lease + type + period. This is what makes
		// generate_service_charges() idempotent, so it has to be answered.
		if ( false !== strpos( $sql, 'FROM ' . $this->prefix . 'af_charges' ) ) {
			$lease = $this->match_int( $sql, '/lease_id = (\d+)/' );
			$type  = $this->match_string( $sql, '/charge_type = \'?([^\s\']+)\'?/' );
			$per   = $this->match_string( $sql, '/period = \'?([^\s\']+)\'?/' );

			foreach ( $this->charges as $id => $charge ) {
				if ( (int) $charge->lease_id === $lease
					&& (string) $charge->charge_type === $type
					&& (string) $charge->period === $per ) {
					return (int) $id;
				}
			}

			return 0;
		}

		// Payment total for a charge.
		if ( false !== strpos( $sql, 'SUM(amount)' ) ) {
			$charge_id = (int) $this->match_int( $sql, '/charge_id = (\d+)/' );
			return isset( $this->charges[ $charge_id ] ) ? (float) $this->charges[ $charge_id ]->amount_paid : 0;
		}

		return 0;
	}

	/**
	 * @param string $sql Query.
	 * @return array
	 */
	public function get_results( $sql, $output = null ) {
		$sql = (string) $sql;

		$this->last_sql = $sql;

		if ( $this->on_results ) {
			return (array) call_user_func( $this->on_results, $sql, $this );
		}

		if ( false !== strpos( $sql, 'af_service_schedules' ) ) {
			return array_values( $this->schedules );
		}

		return array();
	}

	/**
	 * @param string $sql Query.
	 * @return object|null
	 */
	public function get_row( $sql ) {
		$sql = (string) $sql;

		if ( false !== strpos( $sql, 'af_leases' ) ) {
			$acc = $this->match_int( $sql, '/accommodation_id = (\d+)/' );
			foreach ( $this->leases as $lease ) {
				if ( (int) $lease->accommodation_id === $acc && 'active' === $lease->status ) {
					return (object) array(
						'id'       => (int) $lease->id,
						'guest_id' => (int) $lease->guest_id,
					);
				}
			}
			return null;
		}

		if ( false !== strpos( $sql, 'af_meter_readings' ) ) {
			$unit = $this->match_int( $sql, '/unit_id = (\d+)/' );
			$acc  = $this->match_int( $sql, '/accommodation_id = (\d+)/' );
			$svc  = $this->match_string( $sql, '/service = \'?([^\s\']+)\'?/' );
			$per  = $this->match_string( $sql, '/period = \'?([^\s\']+)\'?/' );

			foreach ( $this->readings as $reading ) {
				if ( (int) $reading->unit_id === $unit
					&& (int) $reading->accommodation_id === $acc
					&& (string) $reading->service === $svc
					&& (string) $reading->period === $per ) {
					return (object) array( 'calculated_amount' => $reading->calculated_amount );
				}
			}
			return null;
		}

		if ( false !== strpos( $sql, 'af_service_schedules' ) ) {
			if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
				return isset( $this->schedules[ (int) $m[1] ] ) ? $this->schedules[ (int) $m[1] ] : null;
			}

			$unit = $this->match_int( $sql, '/unit_id = (\d+)/' );
			$acc  = $this->match_int( $sql, '/accommodation_id = (\d+)/' );
			$svc  = $this->match_string( $sql, '/service = \'?([^\s\']+)\'?/' );

			foreach ( $this->schedules as $schedule ) {
				if ( (int) $schedule->unit_id === $unit
					&& (int) $schedule->accommodation_id === $acc
					&& (string) $schedule->service === $svc ) {
					return $schedule;
				}
			}
		}

		if ( false !== strpos( $sql, 'af_charges' ) ) {
			// get_charge() looks the row up by primary key.
			if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
				return isset( $this->charges[ (int) $m[1] ] ) ? $this->charges[ (int) $m[1] ] : null;
			}

			$lease = $this->match_int( $sql, '/lease_id = (\d+)/' );
			$type  = $this->match_string( $sql, '/charge_type = \'?([^\s\']+)\'?/' );
			$per   = $this->match_string( $sql, '/period = \'?([^\s\']+)\'?/' );

			foreach ( $this->charges as $charge ) {
				if ( (int) $charge->lease_id === $lease
					&& (string) $charge->charge_type === $type
					&& (string) $charge->period === $per ) {
					return $charge;
				}
			}
		}

		return null;
	}

	/**
	 * @param string $sql    Query.
	 * @param int    $offset Offset.
	 * @return int
	 */
	public function query( $sql ) {
		return 0;
	}

	/**
	 * Strips the table prefix so assertions can talk about short names.
	 *
	 * @param string $table Full table name.
	 * @return string
	 */
	private function short_table( $table ) {
		return str_replace( $this->prefix, '', (string) $table );
	}

	/**
	 * Extracts the first integer of a pattern, 0 when absent.
	 *
	 * @param string $sql     Query.
	 * @param string $pattern Regex.
	 * @return int
	 */
	private function match_int( $sql, $pattern ) {
		return preg_match( $pattern, $sql, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Extracts the first string of a pattern, '' when absent.
	 *
	 * @param string $sql     Query.
	 * @param string $pattern Regex.
	 * @return string
	 */
	private function match_string( $sql, $pattern ) {
		return preg_match( $pattern, $sql, $m ) ? (string) $m[1] : '';
	}
}

/**
 * Class ServiceDueDatesTest
 */
class ServiceDueDatesTest extends TestCase {

	/**
	 * WPDB double under test.
	 *
	 * @var AF_Service_Test_WPDB
	 */
	private $db;

	/**
	 * Original global $wpdb.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Installs the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$this->db           = new AF_Service_Test_WPDB();
		$GLOBALS['wpdb']    = $this->db;
	}

	/**
	 * Restores the global.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;

		parent::tearDown();
	}

	/**
	 * Registers a schedule row directly in the double.
	 *
	 * @param array $overrides Field overrides.
	 * @return int Schedule ID.
	 */
	private function givenSchedule( array $overrides = array() ) {
		$id = count( $this->db->schedules ) + 1;

		$this->db->schedules[ $id ] = (object) array_merge(
			array(
				'id'               => $id,
				'unit_id'          => 0,
				'accommodation_id' => 0,
				'service'          => 'agua',
				'due_day'          => 8,
				'flat_amount'      => 0,
				'amount_mode'      => 'auto',
				'notes'            => null,
				'is_active'        => 1,
			),
			$overrides
		);

		return $id;
	}

	/**
	 * Registers an active lease for an accommodation.
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return int Lease ID.
	 */
	private function givenActiveLease( $accommodation_id ) {
		$id = count( $this->db->leases ) + 1;

		$this->db->leases[ $id ] = (object) array(
			'id'               => $id,
			'accommodation_id' => (int) $accommodation_id,
			'guest_id'         => 55,
			'status'           => 'active',
			'deleted_at'       => null,
		);

		return $id;
	}

	/**
	 * Registers a reading for a period.
	 *
	 * @param string $service  Service key.
	 * @param string $period   Period.
	 * @param float  $amount   Calculated amount.
	 * @param int    $acc_id   Accommodation ID.
	 * @return int Reading ID.
	 */
	private function givenReading( $service, $period, $amount, $acc_id = 77 ) {
		$id = count( $this->db->readings ) + 1;

		$this->db->readings[ $id ] = (object) array(
			'id'                => $id,
			'unit_id'           => 0,
			'accommodation_id'  => (int) $acc_id,
			'service'           => $service,
			'period'            => $period,
			'calculated_amount' => (float) $amount,
		);

		return $id;
	}

	// -----------------------------------------------------------------------
	// resolve_due_date()
	// -----------------------------------------------------------------------

	/**
	 * A regular month resolves the configured day.
	 */
	public function test_resolves_configured_day() {
		$this->assertSame( '2026-10-08', Arriendo_Facil_Billing_Ledger::resolve_due_date( '2026-10', 8 ) );
		$this->assertSame( '2026-10-01', Arriendo_Facil_Billing_Ledger::resolve_due_date( '2026-10', 1 ) );
		$this->assertSame( '2026-10-28', Arriendo_Facil_Billing_Ledger::resolve_due_date( '2026-10', 28 ) );
	}

	/**
	 * A day beyond the length of the month is clamped instead of rolling over
	 * into the next month, which would silently move a due date to March.
	 */
	public function test_clamps_day_to_last_day_of_month() {
		$this->assertSame( '2026-02-28', Arriendo_Facil_Billing_Ledger::resolve_due_date( '2026-02', 31 ) );
		$this->assertSame( '2024-02-29', Arriendo_Facil_Billing_Ledger::resolve_due_date( '2024-02', 31 ) );
		$this->assertSame( '2026-04-30', Arriendo_Facil_Billing_Ledger::resolve_due_date( '2026-04', 31 ) );
	}

	/**
	 * A missing or zero day falls back to the 5th instead of producing an
	 * invalid date.
	 */
	public function test_falls_back_to_day_five() {
		$this->assertSame( '2026-10-05', Arriendo_Facil_Billing_Ledger::resolve_due_date( '2026-10', 0 ) );
		$this->assertSame( '2026-10-05', Arriendo_Facil_Billing_Ledger::resolve_due_date( '2026-10', -3 ) );
	}

	/**
	 * A malformed period returns an empty string rather than a bogus date.
	 */
	public function test_rejects_invalid_period() {
		$this->assertSame( '', Arriendo_Facil_Billing_Ledger::resolve_due_date( '2026-13', 8 ) );
		$this->assertSame( '', Arriendo_Facil_Billing_Ledger::resolve_due_date( 'octubre', 8 ) );
		$this->assertSame( '', Arriendo_Facil_Billing_Ledger::resolve_due_date( '', 8 ) );
	}

	// -----------------------------------------------------------------------
	// upsert_service_schedule()
	// -----------------------------------------------------------------------

	/**
	 * Creating a rule stores the scope, the day and the pricing mode.
	 */
	public function test_creates_schedule() {
		$result = Arriendo_Facil_Billing_Ledger::upsert_service_schedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'luz',
				'due_day'          => 15,
				'flat_amount'      => 22.5,
				'amount_mode'      => 'auto',
				'is_active'        => 1,
			)
		);

		$this->assertIsInt( $result );

		$stored = $this->db->schedules[ $result ];
		$this->assertSame( 'luz', $stored->service );
		$this->assertSame( 15, (int) $stored->due_day );
		$this->assertSame( 22.5, (float) $stored->flat_amount );
		$this->assertSame( 0, (int) $stored->unit_id );
		$this->assertSame( 77, (int) $stored->accommodation_id );
	}

	/**
	 * Saving the same scope + service twice updates instead of duplicating,
	 * because the table has a unique key on (unit_id, accommodation_id, service).
	 */
	public function test_second_save_updates_same_row() {
		$first = Arriendo_Facil_Billing_Ledger::upsert_service_schedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'luz',
				'due_day'          => 15,
				'is_active'        => 1,
			)
		);

		$second = Arriendo_Facil_Billing_Ledger::upsert_service_schedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'luz',
				'due_day'          => 20,
				'is_active'        => 1,
			)
		);

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->db->schedules );
		$this->assertSame( 20, (int) $this->db->schedules[ $first ]->due_day );
	}

	/**
	 * Exactly one scope is required: a rule with neither, or with both, would
	 * break the unique key semantics.
	 */
	public function test_requires_exactly_one_scope() {
		$none = Arriendo_Facil_Billing_Ledger::upsert_service_schedule(
			array(
				'service' => 'agua',
				'due_day' => 5,
			)
		);
		$this->assertWPError( $none );

		$both = Arriendo_Facil_Billing_Ledger::upsert_service_schedule(
			array(
				'unit_id'          => 3,
				'accommodation_id' => 77,
				'service'          => 'agua',
				'due_day'          => 5,
			)
		);
		$this->assertWPError( $both );
	}

	/**
	 * Unknown services and impossible days are refused.
	 */
	public function test_rejects_invalid_rule_fields() {
		$service = Arriendo_Facil_Billing_Ledger::upsert_service_schedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'banana',
				'due_day'          => 5,
			)
		);
		$this->assertWPError( $service );

		$day = Arriendo_Facil_Billing_Ledger::upsert_service_schedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'agua',
				'due_day'          => 45,
			)
		);
		$this->assertWPError( $day );

		$amount = Arriendo_Facil_Billing_Ledger::upsert_service_schedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'agua',
				'due_day'          => 5,
				'flat_amount'      => -10,
			)
		);
		$this->assertWPError( $amount );
	}

	/**
	 * An unknown amount mode degrades to the mixed default instead of persisting
	 * a value the generator would not understand.
	 */
	public function test_unknown_amount_mode_falls_back_to_auto() {
		$id = Arriendo_Facil_Billing_Ledger::upsert_service_schedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'agua',
				'due_day'          => 5,
				'amount_mode'      => 'inventado',
				'is_active'        => 1,
			)
		);

		$this->assertSame( 'auto', $this->db->schedules[ $id ]->amount_mode );
	}

	// -----------------------------------------------------------------------
	// generate_service_charges()
	// -----------------------------------------------------------------------

	/**
	 * The metered amount wins over the fixed one when a reading exists.
	 */
	public function test_generation_prefers_reading_amount() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'agua',
				'due_day'          => 8,
				'flat_amount'      => 20,
				'amount_mode'      => 'auto',
			)
		);
		$this->givenActiveLease( 77 );
		$this->givenReading( 'agua', '2026-10', 33.75 );

		$stats = Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$this->assertSame( 1, $stats['created'] );
		$this->assertCount( 1, $this->db->charges );

		$charge = reset( $this->db->charges );
		$this->assertSame( 33.75, (float) $charge->amount );
		$this->assertSame( '2026-10-08', $charge->due_date );
		$this->assertSame( 'agua', $charge->charge_type );
	}

	/**
	 * Without a reading the fixed amount is used, so the obligation is not lost
	 * while the meter reading is still pending.
	 */
	public function test_generation_falls_back_to_flat_amount() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'internet',
				'due_day'          => 10,
				'flat_amount'      => 30,
				'amount_mode'      => 'auto',
			)
		);
		$this->givenActiveLease( 77 );

		$stats = Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$this->assertSame( 1, $stats['created'] );

		$charge = reset( $this->db->charges );
		$this->assertSame( 30.0, (float) $charge->amount );
		$this->assertSame( '2026-10-10', $charge->due_date );
	}

	/**
	 * With the fixed mode a reading must not change the amount.
	 */
	public function test_fixed_mode_ignores_reading() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'agua',
				'due_day'          => 8,
				'flat_amount'      => 25,
				'amount_mode'      => 'fixed',
			)
		);
		$this->givenActiveLease( 77 );
		$this->givenReading( 'agua', '2026-10', 99 );

		Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$charge = reset( $this->db->charges );
		$this->assertSame( 25.0, (float) $charge->amount );
	}

	/**
	 * With the metered mode the fixed amount is not used as a substitute.
	 */
	public function test_metered_mode_without_reading_creates_nothing() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'agua',
				'due_day'          => 8,
				'flat_amount'      => 25,
				'amount_mode'      => 'metered',
			)
		);
		$this->givenActiveLease( 77 );

		$stats = Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$this->assertSame( 0, $stats['created'] );
		$this->assertSame( 1, $stats['skipped'] );
		$this->assertCount( 0, $this->db->charges );
	}

	/**
	 * Running twice for the same period must not duplicate the charge.
	 */
	public function test_generation_is_idempotent() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'agua',
				'due_day'          => 8,
				'flat_amount'      => 20,
			)
		);
		$this->givenActiveLease( 77 );

		Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );
		$second = Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$this->assertCount( 1, $this->db->charges );
		$this->assertSame( 0, $second['created'] );
	}

	/**
	 * A late reading re-prices a charge that nobody has paid yet, so the amount
	 * ends up correct without the operator re-entering anything.
	 */
	public function test_late_reading_reprices_unpaid_charge() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'agua',
				'due_day'          => 8,
				'flat_amount'      => 20,
			)
		);
		$this->givenActiveLease( 77 );

		Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$this->givenReading( 'agua', '2026-10', 41.5 );

		$stats = Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$this->assertSame( 1, $stats['updated'] );
		$this->assertCount( 1, $this->db->charges );

		$charge = reset( $this->db->charges );
		$this->assertSame( 41.5, (float) $charge->amount );
	}

	/**
	 * Once money moved the amount is financial history: a late reading must not
	 * silently rewrite it.
	 */
	public function test_late_reading_does_not_reprice_paid_charge() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'agua',
				'due_day'          => 8,
				'flat_amount'      => 20,
			)
		);
		$this->givenActiveLease( 77 );

		Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$charge_id = (int) array_key_first( $this->db->charges );
		$this->db->charges[ $charge_id ]->amount_paid = 20;
		$this->db->charges[ $charge_id ]->status      = 'paid';

		$this->givenReading( 'agua', '2026-10', 41.5 );

		$stats = Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$this->assertSame( 0, $stats['updated'] );
		$this->assertSame( 20.0, (float) $this->db->charges[ $charge_id ]->amount );
	}

	/**
	 * A rule without an active lease cannot produce a charge; it is counted so
	 * the UI can tell the operator why nothing happened.
	 */
	public function test_skips_rules_without_active_lease() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'agua',
				'flat_amount'      => 20,
			)
		);

		$stats = Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$this->assertSame( 0, $stats['created'] );
		$this->assertSame( 1, $stats['no_lease'] );
		$this->assertCount( 0, $this->db->charges );
	}

	/**
	 * The due date of a generated charge follows the rule, not the hardcoded
	 * day 5 that create_charge() falls back to.
	 */
	public function test_charge_due_date_follows_rule_not_default() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'service'          => 'gas',
				'due_day'          => 22,
				'flat_amount'      => 12,
			)
		);
		$this->givenActiveLease( 77 );

		Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$charge = reset( $this->db->charges );
		$this->assertSame( '2026-10-22', $charge->due_date );
	}

	/**
	 * A malformed period is a no-op instead of writing charges into 0000-00.
	 */
	public function test_generation_rejects_invalid_period() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'flat_amount'      => 20,
			)
		);
		$this->givenActiveLease( 77 );

		$stats = Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-13' );

		$this->assertSame( 0, $stats['created'] );
		$this->assertCount( 0, $this->db->charges );
	}

	/**
	 * Several rules for the same property in one period produce one charge each.
	 */
	public function test_generates_every_service_of_a_property() {
		foreach ( array( 'agua' => 8, 'luz' => 15, 'gas' => 22 ) as $service => $day ) {
			$this->givenSchedule(
				array(
					'accommodation_id' => 77,
					'service'          => $service,
					'due_day'          => $day,
					'flat_amount'      => 10,
				)
			);
		}
		$this->givenActiveLease( 77 );

		$stats = Arriendo_Facil_Billing_Ledger::generate_service_charges( '2026-10' );

		$this->assertSame( 3, $stats['created'] );
		$this->assertCount( 3, $this->db->charges );
	}

	// -----------------------------------------------------------------------
	// service_catalog()
	// -----------------------------------------------------------------------

	/**
	 * Every catalogued service is also a valid charge type, otherwise the
	 * generator would refuse the charge it just decided to create.
	 */
	public function test_every_catalogued_service_is_a_charge_type() {
		$charge_types = Arriendo_Facil_Billing_Ledger::charge_types();

		foreach ( array_keys( Arriendo_Facil_Billing_Ledger::service_catalog() ) as $service ) {
			$this->assertArrayHasKey( $service, $charge_types, $service . ' is not a valid charge type' );
		}
	}

	/**
	 * The metered subset keeps matching the services that can be billed from a
	 * reading, so the reading form and the catalog stay consistent.
	 */
	public function test_metered_subset_matches_catalog() {
		$catalog = Arriendo_Facil_Billing_Ledger::service_catalog();

		foreach ( Arriendo_Facil_Billing_Ledger::metered_services() as $key => $label ) {
			$this->assertArrayHasKey( $key, $catalog );
			$this->assertTrue( $catalog[ $key ]['metered'], $key . ' should be metered' );
		}
	}

	/**
	 * Unknown keys degrade to the raw key instead of an empty label.
	 */
	public function test_service_label_falls_back_to_key() {
		$this->assertSame( 'Agua', Arriendo_Facil_Billing_Ledger::service_label( 'agua' ) );
		$this->assertSame( 'loquesea', Arriendo_Facil_Billing_Ledger::service_label( 'loquesea' ) );
	}

	// -----------------------------------------------------------------------
	// Scope isolation
	// -----------------------------------------------------------------------

	/**
	 * An empty scope must return nothing rather than leaking every rule: this is
	 * what a property admin with no properties would receive.
	 */
	public function test_empty_scope_returns_nothing() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'flat_amount'      => 20,
			)
		);
		$this->givenActiveLease( 77 );

		$schedules = Arriendo_Facil_Billing_Ledger::get_service_schedules( array( 'accommodation_ids' => array() ) );

		$this->assertSame( array(), $schedules );
	}

	/**
	 * The AJAX surface must refuse a schedule outside the operator's scope.
	 */
	public function test_ajax_save_requires_capability() {
		$ledger = new Arriendo_Facil_Billing_Ledger();

		$_POST = array(
			'accommodation_id' => 77,
			'service'          => 'agua',
			'due_day'          => 8,
		);

		$GLOBALS['af_test_can_manage'] = false;

		try {
			$ledger->ajax_save_service_schedule();
			$this->fail( 'Expected the endpoint to refuse the request.' );
		} catch ( \Throwable $e ) {
			// The stub in charge of the response decides the shape: some carry a
			// ->success flag, others wrap the payload under 'success'.
			$success = isset( $e->success )
				? (bool) $e->success
				: ( isset( $e->payload['success'] ) ? (bool) $e->payload['success'] : null );

			$this->assertFalse( $success, 'The endpoint must report a failure.' );
			$this->assertArrayHasKey( 'message', (array) $e->payload );
		}

		// A refused request must not have written anything.
		$this->assertCount( 0, $this->db->schedules );

		unset( $_POST['accommodation_id'], $_POST['service'], $_POST['due_day'] );
		unset( $GLOBALS['af_test_can_manage'] );
	}

	/**
	 * Asserts a WP_Error result.
	 *
	 * @param mixed $value Value under test.
	 * @return void
	 */
	private function assertWPError( $value ) {
		$this->assertInstanceOf( \WP_Error::class, $value );
	}

	// -----------------------------------------------------------------------
	// Period validation
	// -----------------------------------------------------------------------

	/**
	 * A month outside 01-12 is not a period. It used to pass the /\d{4}-\d{2}/
	 * check and reach the INSERT, writing charges into a period nobody can ever
	 * display or reconcile.
	 */
	public function test_validate_period_rejects_impossible_months() {
		$this->assertSame( '2026-01', Arriendo_Facil_Billing_Ledger::validate_period( '2026-01' ) );
		$this->assertSame( '2026-12', Arriendo_Facil_Billing_Ledger::validate_period( '2026-12' ) );

		$this->assertSame( '', Arriendo_Facil_Billing_Ledger::validate_period( '2026-13' ) );
		$this->assertSame( '', Arriendo_Facil_Billing_Ledger::validate_period( '2026-00' ) );
		$this->assertSame( '', Arriendo_Facil_Billing_Ledger::validate_period( '2026-1' ) );
		$this->assertSame( '', Arriendo_Facil_Billing_Ledger::validate_period( '26-10' ) );
		$this->assertSame( '', Arriendo_Facil_Billing_Ledger::validate_period( '' ) );
		$this->assertSame( '', Arriendo_Facil_Billing_Ledger::validate_period( '2026-10-01' ) );
	}

	/**
	 * resolve_due_date() and the generator must agree on what a period is.
	 * An empty period is not invalid: it means "the current month", which is
	 * what the daily cron relies on.
	 */
	public function test_due_date_and_generation_agree_on_invalid_periods() {
		$this->assertSame( '', Arriendo_Facil_Billing_Ledger::resolve_due_date( '2026-13', 8 ) );

		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'due_day'          => 8,
				'flat_amount'      => 20,
			)
		);
		$this->givenActiveLease( 77 );

		foreach ( array( '2026-13', '2026-00', 'nope', '2026-1' ) as $bad ) {
			$stats = Arriendo_Facil_Billing_Ledger::generate_service_charges( $bad );
			$this->assertSame( 0, $stats['created'], $bad . ' must not create charges' );
		}

		$this->assertCount( 0, $this->db->charges );
		$this->assertSame( array(), Arriendo_Facil_Billing_Ledger::get_service_due_rows( '2026-13' ) );
	}

	/**
	 * An omitted period falls back to the current month, so the cron fires
	 * without arguments and still does the right thing.
	 */
	public function test_empty_period_falls_back_to_the_current_month() {
		$this->givenSchedule(
			array(
				'accommodation_id' => 77,
				'due_day'          => 8,
				'flat_amount'      => 20,
			)
		);
		$this->givenActiveLease( 77 );

		$stats = Arriendo_Facil_Billing_Ledger::generate_service_charges( '' );

		$this->assertSame( 1, $stats['created'] );
		$this->assertSame(
			gmdate( 'Y-m-08' ),
			reset( $this->db->charges )->due_date
		);
	}

	// -----------------------------------------------------------------------
	// Scope isolation in the generated SQL
	// -----------------------------------------------------------------------

	/**
	 * Unit-scoped rules must be filtered through the accommodation that owns the
	 * unit. Comparing unit_id against accommodation ids would return no rows for
	 * a property admin whose properties all live in units, and — because both
	 * are auto-increment ids — could also match a rule that belongs to somebody
	 * else's property.
	 */
	public function test_scope_resolves_unit_rules_through_their_accommodation() {
		$this->givenSchedule(
			array(
				'unit_id'     => 3,
				'flat_amount' => 20,
			)
		);

		$ledger = Arriendo_Facil_Billing_Ledger::get_service_schedules( array( 'accommodation_ids' => array( 77 ) ) );
		$this->assertIsArray( $ledger );

		$this->assertStringContainsString(
			'COALESCE( NULLIF( s.accommodation_id, 0 ), s_u.accommodation_id ) IN (77)',
			$this->db->last_sql
		);
		$this->assertStringNotContainsString( 's.unit_id IN (77)', $this->db->last_sql );
		$this->assertStringContainsString( 'af_units', $this->db->last_sql, 'the unit table must be joined to resolve the parent property' );
	}

	/**
	 * The same applies to the alert rows: unit-scoped rules have
	 * accommodation_id = 0 and would be invisible otherwise.
	 */
	public function test_due_rows_scope_resolves_unit_rules() {
		$this->givenSchedule(
			array(
				'unit_id'     => 3,
				'service'     => 'agua',
				'due_day'     => 8,
				'flat_amount' => 20,
			)
		);
		$this->givenActiveLease( 77 );
		$this->db->on_results = function () {
			return array();
		};

		Arriendo_Facil_Billing_Ledger::get_service_due_rows( '2026-10', array( 77 ) );

		$this->assertStringContainsString(
			'COALESCE( NULLIF( s.accommodation_id, 0 ), u.accommodation_id ) IN (77)',
			$this->db->last_sql
		);
		$this->assertStringNotContainsString( 's.unit_id IN (77)', $this->db->last_sql );
	}

	/**
	 * An operator with no properties must not fall back to an unfiltered query.
	 */
	public function test_due_rows_empty_scope_returns_nothing_without_querying() {
		$this->givenSchedule( array( 'accommodation_id' => 77 ) );

		$this->assertSame( array(), Arriendo_Facil_Billing_Ledger::get_service_due_rows( '2026-10', array() ) );
	}

	/**
	 * The due-rows query has two period placeholders (charges and readings);
	 * passing a single argument made prepare() fail on every page load.
	 */
	public function test_due_rows_binds_both_period_placeholders() {
		$this->givenSchedule( array( 'accommodation_id' => 77 ) );
		$this->db->on_results = function () {
			return array();
		};

		Arriendo_Facil_Billing_Ledger::get_service_due_rows( '2026-10' );

		$this->assertSame( 2, substr_count( $this->db->last_sql, "'2026-10'" ) );
		$this->assertStringNotContainsString( '%s', $this->db->last_sql );
	}

	// -----------------------------------------------------------------------
	// Status derivation
	// -----------------------------------------------------------------------

	/**
	 * Builds a raw row shaped like the due-rows query output.
	 *
	 * @param array $overrides Field overrides.
	 * @return object
	 */
	private function givenStatusRow( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'schedule_id'        => 1,
				'unit_id'            => 0,
				'accommodation_id'   => 77,
				'service'            => 'agua',
				'due_day'            => 8,
				'flat_amount'        => 20,
				'amount_mode'        => 'auto',
				'notes'              => null,
				'is_active'          => 1,
				'lease_id'           => 1,
				'guest_id'           => 55,
				'guest_name'         => 'Ana Pérez',
				'accommodation_title' => 'Depto 1',
				'unit_code'          => '',
				'charge_id'          => 0,
				'amount'             => null,
				'amount_paid'        => null,
				'due_date'           => null,
				'charge_status'      => null,
				'reading_id'         => null,
				'calculated_amount'  => null,
				'consumption'        => null,
				'current_reading'    => null,
				'previous_reading'   => null,
				'unit_rate'          => null,
			),
			$overrides
		);
	}

	/**
	 * Renders one unpaid charge for the given period and returns the derived row.
	 *
	 * @param string $period Period to resolve.
	 * @return array[]
	 */
	private function givenPeriodWithStatus( $period ) {
		$this->givenSchedule( array( 'accommodation_id' => 77 ) );
		$due = Arriendo_Facil_Billing_Ledger::resolve_due_date( $period, 8 );

		$this->db->on_results = function () use ( $due ) {
			return array(
				$this->givenStatusRow(
					array(
						'due_date'      => $due,
						'charge_id'     => 5,
						'charge_status' => 'pending',
					)
				),
			);
		};

		return Arriendo_Facil_Billing_Ledger::get_service_due_rows( $period );
	}

	/**
	 * In a past period an unpaid charge is overdue even though the date filter
	 * was a historical one; before the fix every row was compared against today.
	 */
	public function test_past_period_reports_unpaid_as_overdue() {
		$rows = $this->givenPeriodWithStatus( '2020-03' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'overdue', $rows[0]['status'] );
	}

	/**
	 * A future period is never overdue, even though its due date precedes today
	 * relative to a past filter would not apply.
	 */
	public function test_future_period_is_not_overdue() {
		$rows = $this->givenPeriodWithStatus( gmdate( 'Y-m', strtotime( '+2 months' ) ) );

		$this->assertCount( 1, $rows );
		$this->assertNotSame( 'overdue', $rows[0]['status'] );
	}

	/**
	 * The "due soon" window is a shared constant so the KPI card cannot promise
	 * 7 days while the ledger flags 3.
	 */
	public function test_due_soon_window_is_seven_days() {
		$this->assertSame( 7, Arriendo_Facil_Billing_Ledger::SERVICE_DUE_SOON_DAYS );

		$period = gmdate( 'Y-m' );
		$rows   = $this->givenPeriodWithStatus( $period );

		// The double resolves the due date to day 8, so a charge 6 days out must
		// land inside the window.
		$this->assertNotSame( 'pending', $rows[0]['status'] );
	}
}
