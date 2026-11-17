<?php
/**
 * Regression tests for the ownership and isolation defects found in audit run 3.
 *
 * Each test runs in its own process because it defines its own minimal WordPress
 * doubles (other test files define conflicting global functions); the real plugin
 * classes are loaded so the assertions exercise production logic.
 *
 * @package Arriendo_Facil
 */

namespace ArriendoFacil\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AuditRun3FixesTest extends TestCase {

	/**
	 * Defines the WordPress doubles shared by these tests.
	 *
	 * @param array<string,mixed> $opts caps: capabilities held by the current user; meta: post meta map.
	 * @return void
	 */
	private function boot( array $opts = array() ) {
		$GLOBALS['af_t_caps'] = isset( $opts['caps'] ) ? $opts['caps'] : array();
		$GLOBALS['af_t_meta'] = isset( $opts['meta'] ) ? $opts['meta'] : array();

		if ( ! function_exists( 'current_user_can' ) ) {
			eval( 'function current_user_can( $cap ) { return in_array( $cap, $GLOBALS["af_t_caps"], true ); }' );
		}
		if ( ! function_exists( 'get_current_user_id' ) ) {
			eval( 'function get_current_user_id() { return 5; }' );
		}
		if ( ! function_exists( 'user_can' ) ) {
			eval( 'function user_can( $user_id, $cap ) { return false; }' );
		}
		if ( ! function_exists( 'get_post_meta' ) ) {
			eval( 'function get_post_meta( $id, $key = "", $single = false ) { return isset( $GLOBALS["af_t_meta"][ (int) $id ][ $key ] ) ? $GLOBALS["af_t_meta"][ (int) $id ][ $key ] : ""; }' );
		}
		if ( ! function_exists( 'add_action' ) ) {
			eval( 'function add_action() { return true; }' );
		}
		if ( ! function_exists( 'add_filter' ) ) {
			eval( 'function add_filter() { return true; }' );
		}
		if ( ! function_exists( 'absint' ) ) {
			eval( 'function absint( $v ) { return abs( (int) $v ); }' );
		}
		if ( ! function_exists( 'wp_unslash' ) ) {
			eval( 'function wp_unslash( $v ) { return $v; }' );
		}
		if ( ! function_exists( 'check_ajax_referer' ) ) {
			eval( 'function check_ajax_referer() { return true; }' );
		}
		if ( ! class_exists( 'AF_T_Json' ) ) {
			eval( 'class AF_T_Json extends \Exception { public $ok; public $data; public function __construct( $ok, $data ) { parent::__construct( "json" ); $this->ok = $ok; $this->data = $data; } }' );
		}
		if ( ! function_exists( 'wp_send_json_success' ) ) {
			eval( 'function wp_send_json_success( $data = null, $code = null ) { throw new AF_T_Json( true, $data ); }' );
		}
		if ( ! function_exists( 'wp_send_json_error' ) ) {
			eval( 'function wp_send_json_error( $data = null, $code = null ) { throw new AF_T_Json( false, $data ); }' );
		}
		if ( ! function_exists( '__' ) ) {
			eval( 'function __( $t ) { return $t; }' );
		}

		$GLOBALS['wpdb'] = new class() {
			public $prefix  = 'wp_';
			public $queries = array();
			public $updates = array();
			public $rows    = array();
			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return vsprintf( str_replace( array( '%d', '%f' ), '%s', $query ), array_map( 'strval', $args ) );
			}
			public function get_var( $query ) {
				$this->queries[] = $query;
				foreach ( $this->rows as $needle => $row ) {
					if ( false !== strpos( (string) $query, (string) $needle ) && isset( $row->id ) ) {
						return $row->id;
					}
				}
				return 0;
			}
			public function get_results( $query ) {
				$this->queries[] = $query;
				return array();
			}
			public function get_row( $query ) {
				$this->queries[] = $query;
				foreach ( $this->rows as $needle => $row ) {
					if ( false !== strpos( (string) $query, (string) $needle ) ) {
						return $row;
					}
				}
				return null;
			}
			public function update( $table, $data, $where, $f = null, $wf = null ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
			public function query( $q ) {
				$this->queries[] = $q;
				return 1;
			}
			public function get_col( $q, $x = 0 ) {
				return array( 'id', 'guest_id', 'accommodation_id', 'user_id' );
			}
			public function insert( $t, $d, $f = null ) {
				return 1;
			}
			public function delete( $t, $w, $f = null ) {
				return 1;
			}
		};
	}

	/**
	 * A user with edit_posts who is not a property manager must not manage any accommodation.
	 */
	public function test_can_manage_accommodation_fails_closed_for_plain_edit_posts_user() {
		$this->boot( array( 'caps' => array( 'edit_posts' ) ) );
		require_once dirname( __DIR__, 2 ) . '/includes/class-tenancy.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-rental-workflow.php';

		$ref    = new \ReflectionClass( 'Arriendo_Facil_Rental_Workflow' );
		$obj    = $ref->newInstanceWithoutConstructor();
		$method = $ref->getMethod( 'can_manage_accommodation' );

		$this->assertFalse( $method->invoke( $obj, 999 ) );
	}

	/**
	 * A property manager keeps access to their own accommodation only.
	 */
	public function test_can_manage_accommodation_allows_only_own_accommodation_for_manager() {
		$this->boot(
			array(
				'caps' => array( 'edit_posts', 'af_manage_properties' ),
				'meta' => array( 100 => array( '_af_owner_id' => 5 ), 200 => array( '_af_owner_id' => 9 ) ),
			)
		);
		require_once dirname( __DIR__, 2 ) . '/includes/class-tenancy.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-rental-workflow.php';

		$ref    = new \ReflectionClass( 'Arriendo_Facil_Rental_Workflow' );
		$obj    = $ref->newInstanceWithoutConstructor();
		$method = $ref->getMethod( 'can_manage_accommodation' );

		$this->assertTrue( $method->invoke( $obj, 100 ) );
		$this->assertFalse( $method->invoke( $obj, 200 ) );
	}

	/**
	 * Voiding a charge after a meter reading is deleted must stay inside the reading's own leases.
	 */
	public function test_delete_meter_reading_scopes_charge_lookup_to_reading_accommodation() {
		$this->boot();
		require_once dirname( __DIR__, 2 ) . '/includes/class-billing-ledger.php';

		$GLOBALS['wpdb']->rows['readings'] = (object) array(
			'id'               => 1,
			'service'          => 'agua',
			'period'           => '2026-10',
			'unit_id'          => 5,
			'accommodation_id' => 100,
		);

		\Arriendo_Facil_Billing_Ledger::delete_meter_reading( 1 );

		$charge_query = '';
		foreach ( $GLOBALS['wpdb']->queries as $q ) {
			if ( false !== strpos( (string) $q, 'Consumo' ) ) {
				$charge_query = (string) $q;
			}
		}

		$this->assertNotSame( '', $charge_query, 'The void lookup query was not issued.' );
		// The lease scope must be a mandatory condition ahead of the unit match, not only an
		// alternative branch used when the charge has no unit.
		$this->assertMatchesRegularExpression( "/status <> 'void'\\s+AND lease_id IN \\( SELECT id FROM wp_af_leases WHERE accommodation_id = 100 \\)/", $charge_query );
	}

	/**
	 * Anonymous signup must not rewrite a guest row that belongs to someone else.
	 */
	public function test_signup_does_not_overwrite_existing_unlinked_guest_row() {
		$this->boot();
		require_once dirname( __DIR__, 2 ) . '/includes/tenants/class-tenant-signup.php';

		$GLOBALS['wpdb']->rows['FROM wp_af_guests WHERE email'] = (object) array(
			'id'      => 1,
			'user_id' => null,
		);

		$ref    = new \ReflectionClass( 'Arriendo_Facil_Tenant_Signup' );
		$obj    = $ref->newInstanceWithoutConstructor();
		$method = $ref->getMethod( 'upsert_guest_row_for_tenant' );
		$method->invoke( $obj, 999, 'Atacante', 'X', 'victim@example.test', '0987654321', '1799999999', 'enc', 'enc' );

		$this->assertSame( array(), $GLOBALS['wpdb']->updates, 'Existing guest row was modified before e-mail verification.' );
	}

	/**
	 * Forwarded-for headers are only honoured when the immediate peer is a local/trusted proxy.
	 */
	public function test_rate_limiter_ignores_spoofed_forwarded_headers_from_public_peer() {
		$this->boot();
		if ( ! function_exists( 'sanitize_text_field' ) ) {
			eval( 'function sanitize_text_field( $v ) { return trim( (string) $v ); }' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/class-rate-limiter.php';

		$method = new \ReflectionMethod( 'Arriendo_Facil_Rate_Limiter', 'get_client_ip' );

		// Direct connection from a public address that forges the headers.
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.8';
		$this->assertSame( '203.0.113.9', $method->invoke( null ) );

		// Local reverse proxy / tunnel connector: the forwarded client address is used.
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$this->assertSame( '198.51.100.8', $method->invoke( null ) );

		// Garbage in the header is ignored.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.7, 10.0.0.1';
		$this->assertSame( '198.51.100.7', $method->invoke( null ) );
	}
}
