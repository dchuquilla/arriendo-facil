<?php
/**
 * Tests for the Mantenimiento redesign: the service-provider catalog and the
 * repair-request fields that replaced the flat "incidencia" form.
 *
 * These assert the sanitisation/whitelisting paths, which is where the risk
 * lives: everything user-supplied must be normalised before it reaches $wpdb,
 * update() must never write a column outside its allow-list, and a provider or
 * property belonging to another owner must never be linkable.
 *
 * Tenancy is exercised through the real Arriendo_Facil_Tenancy, not a double:
 * tests/bootstrap.php + tests/security/GuestUpdateTest.php already define
 * user_can() to return false, so can_manage_all() is false and ownership is
 * decided by the owner_id the $wpdb stub returns.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var string */
		public $code;
		/** @var string */
		public $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( trim( (string) $email ), FILTER_SANITIZE_EMAIL );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true;
	}
}

/**
 * Minimal $wpdb double that records writes and fakes tenancy lookups.
 */
class Maintenance_WPDB_Stub {

	/** @var string */
	public $prefix = 'wp_';
	/** @var string */
	public $posts = 'wp_posts';
	/** @var array */
	public $inserted = array();
	/** @var array */
	public $updates = array();
	/** @var int */
	public $insert_id = 4242;
	/** @var mixed Value returned by get_var(): fakes owner_id lookups. */
	public $var_result = 0;

	public function insert( $table, $data, $format = null ) {
		$this->inserted[] = array(
			'table'  => $table,
			'data'   => $data,
			'format' => $format,
		);
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->updates[] = array(
			'table'         => $table,
			'data'          => $data,
			'where'         => $where,
			'format'        => $format,
			'where_format'  => $where_format,
		);
		return 1;
	}

	public function get_row( $query, $output = null ) {
		return null;
	}

	public function get_results( $query, $output = null ) {
		return array();
	}

	public function get_col( $query, $x = 0 ) {
		return array();
	}

	public function get_var( $query, $x = 0, $y = 0 ) {
		return $this->var_result;
	}

	public function delete( $table, $where, $where_format = null ) {
		return 1;
	}

	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( array( '%d', '%f' ), '%s', $query ), $args );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}
}

if ( ! class_exists( 'Arriendo_Facil_Lease' ) ) {
	/**
	 * Lease double: every accommodation resolves to lease 11.
	 */
	class Arriendo_Facil_Lease {
		public static function get_active_lease_id_for_accommodation( $accommodation_id ) {
			return 11;
		}
	}
}

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-tenancy.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-maintenance.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-service-providers.php';

/**
 * Class MaintenanceModuleTest
 */
class MaintenanceModuleTest extends TestCase {

	/**
	 * @var Maintenance_WPDB_Stub
	 */
	private $wpdb_backup;

	protected function setUp(): void {
		global $wpdb;

		$this->wpdb_backup = $wpdb;
		$wpdb              = new Maintenance_WPDB_Stub();

		// The current user is 5 (bootstrap default); no provider is theirs yet.
		$GLOBALS['af_test_user_id'] = 5;
		$GLOBALS['wpdb']->var_result = 0;
	}

	protected function tearDown(): void {
		global $wpdb;

		$wpdb = $this->wpdb_backup;
		unset( $GLOBALS['af_test_user_id'] );
	}

	/**
	 * The last row handed to $wpdb->insert().
	 *
	 * @return array
	 */
	private function last_insert() {
		return end( $GLOBALS['wpdb']->inserted );
	}

	/**
	 * The last row handed to $wpdb->update().
	 *
	 * @return array
	 */
	private function last_update() {
		return end( $GLOBALS['wpdb']->updates );
	}

	// ── Taxonomies ────────────────────────────────────────────────────────

	public function test_trades_cover_the_services_the_owner_listed() {
		$trades = Arriendo_Facil_Service_Providers::trades();

		foreach ( array( 'plomero', 'albanil', 'carpintero', 'electricista' ) as $trade ) {
			$this->assertArrayHasKey( $trade, $trades );
		}
	}

	public function test_asset_categories_cover_property_and_appliance_repairs() {
		$categories = Arriendo_Facil_Maintenance::asset_categories();

		foreach ( array( 'inmueble', 'instalacion', 'electrodomestico', 'mueble' ) as $category ) {
			$this->assertArrayHasKey( $category, $categories );
		}
	}

	// ── create() ──────────────────────────────────────────────────────────

	public function test_create_persists_the_repair_details_the_owner_asked_for() {
		$id = Arriendo_Facil_Maintenance::create(
			array(
				'accommodation_id' => 5,
				'request_type'     => 'reparacion',
				'asset_category'   => 'electrodomestico',
				'asset_name'       => 'Lavadora LG',
				'damage_details'   => 'No drena y hace ruido al centrifugar.',
				'asset_specs'      => 'LG 2.5kg, blanca, serie X1',
				'asset_location'   => 'Cocina',
				'scheduled_date'   => '2026-10-05',
				'contact_name'     => 'Ana (vecina)',
				'contact_phone'    => '0999999999',
			)
		);

		$this->assertSame( 4242, $id );

		$data = $this->last_insert()['data'];

		$this->assertSame( 'Lavadora LG', $data['asset_name'] );
		$this->assertSame( 'No drena y hace ruido al centrifugar.', $data['damage_details'] );
		$this->assertSame( 'LG 2.5kg, blanca, serie X1', $data['asset_specs'] );
		$this->assertSame( 'Cocina', $data['asset_location'] );
		$this->assertSame( '2026-10-05', $data['scheduled_date'] );
		$this->assertSame( 'electrodomestico', $data['asset_category'] );
		$this->assertSame( 'Ana (vecina)', $data['contact_name'] );
		$this->assertSame( '0999999999', $data['contact_phone'] );
		$this->assertSame( 'pending', $data['status'] );
	}

	/**
	 * $wpdb->insert() binds positionally: a format array shorter or longer than
	 * the data array either drops a column or leaves a value unbound, which is a
	 * silent data-corruption bug rather than an error.
	 */
	public function test_create_passes_one_format_per_column() {
		Arriendo_Facil_Maintenance::create( array( 'accommodation_id' => 5 ) );

		$row = $this->last_insert();

		$this->assertCount( count( $row['data'] ), $row['format'] );
		$this->assertSame( '%d', $row['format'][0] );
		$this->assertSame( '%f', $row['format'][ array_search( 'cost', array_keys( $row['data'] ), true ) ] );
	}

	public function test_create_requires_an_accommodation() {
		$result = Arriendo_Facil_Maintenance::create( array( 'asset_name' => 'Lavadora' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'af_maintenance_property_required', $result->get_error_code() );
	}

	public function test_create_falls_back_to_otro_for_an_unknown_type() {
		Arriendo_Facil_Maintenance::create(
			array(
				'accommodation_id' => 5,
				'request_type'     => 'no-existe',
				'asset_category'   => 'categoria-inventada',
			)
		);

		$data = $this->last_insert()['data'];

		$this->assertSame( 'otro', $data['request_type'] );
		$this->assertSame( '', $data['asset_category'] );
	}

	public function test_create_drops_a_provider_owned_by_someone_else() {
		// var_result 0: can_access_service_provider() finds no owner row.
		Arriendo_Facil_Maintenance::create(
			array(
				'accommodation_id' => 5,
				'asset_name'       => 'Lavadora',
				'provider_id'      => 99,
			)
		);

		$this->assertNull( $this->last_insert()['data']['provider_id'] );
	}

	public function test_create_keeps_a_provider_owned_by_the_current_user() {
		$GLOBALS['wpdb']->var_result = 5;

		Arriendo_Facil_Maintenance::create(
			array(
				'accommodation_id' => 5,
				'asset_name'       => 'Lavadora',
				'provider_id'      => 3,
			)
		);

		$this->assertSame( 3, $this->last_insert()['data']['provider_id'] );
	}

	// ── update() ──────────────────────────────────────────────────────────

	public function test_update_writes_only_whitelisted_columns() {
		Arriendo_Facil_Maintenance::update(
			7,
			array(
				'asset_name'       => 'Puerta principal',
				'damage_details'   => 'Bisagras sueltas',
				'cost'             => 999,
				'status'           => 'completed',
				'lease_id'         => 123,
				'accommodation_id' => 456,
			)
		);

		$update = $this->last_update();

		$this->assertSame( array( 'asset_name', 'damage_details' ), array_keys( $update['data'] ) );
		$this->assertArrayNotHasKey( 'cost', $update['data'] );
		$this->assertArrayNotHasKey( 'status', $update['data'] );
		$this->assertArrayNotHasKey( 'lease_id', $update['data'] );
		$this->assertArrayNotHasKey( 'accommodation_id', $update['data'] );
		$this->assertSame( array( 'id' => 7 ), $update['where'] );
	}

	public function test_update_rejects_a_provider_owned_by_someone_else() {
		$result = Arriendo_Facil_Maintenance::update( 7, array( 'provider_id' => 99 ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'af_maintenance_provider_denied', $result->get_error_code() );
		$this->assertEmpty( $GLOBALS['wpdb']->updates );
	}

	public function test_update_accepts_a_provider_owned_by_the_current_user() {
		$GLOBALS['wpdb']->var_result = 5;

		$result = Arriendo_Facil_Maintenance::update( 7, array( 'provider_id' => 3 ) );

		$this->assertTrue( $result );
		$this->assertSame( 3, $this->last_update()['data']['provider_id'] );
	}

	public function test_update_is_a_noop_without_fields() {
		$result = Arriendo_Facil_Maintenance::update( 7, array() );

		$this->assertTrue( $result );
		$this->assertEmpty( $GLOBALS['wpdb']->updates );
	}

	public function test_update_normalises_an_unknown_asset_category() {
		Arriendo_Facil_Maintenance::update( 7, array( 'asset_category' => 'inventado' ) );

		$this->assertSame( '', $this->last_update()['data']['asset_category'] );
	}

	public function test_update_rejects_a_missing_request_id() {
		$result = Arriendo_Facil_Maintenance::update( 0, array( 'asset_name' => 'X' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEmpty( $GLOBALS['wpdb']->updates );
	}

	public function test_update_passes_one_format_per_column() {
		$GLOBALS['wpdb']->var_result = 5;

		Arriendo_Facil_Maintenance::update(
			7,
			array(
				'asset_name'     => 'Puerta',
				'asset_category' => 'inmueble',
				'provider_id'    => 3,
				'priority'       => 'alta',
			)
		);

		$update = $this->last_update();

		$this->assertCount( count( $update['data'] ), $update['format'] );
		$this->assertSame( array( '%d' ), $update['where_format'] );
	}

	// ── Service providers ─────────────────────────────────────────────────

	public function test_provider_create_requires_a_name() {
		$result = Arriendo_Facil_Service_Providers::create( array( 'trade' => 'plomero' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'af_sp_name_required', $result->get_error_code() );
	}

	public function test_provider_create_falls_back_to_general_for_an_unknown_trade() {
		Arriendo_Facil_Service_Providers::create(
			array(
				'name'  => 'Juan Pérez',
				'trade' => 'fontanero-exotico',
			)
		);

		$data = $this->last_insert()['data'];

		$this->assertSame( 'Juan Pérez', $data['name'] );
		$this->assertSame( 'general', $data['trade'] );
		$this->assertSame( 'active', $data['status'] );
		$this->assertSame( 5, $data['owner_id'] );
	}

	public function test_provider_create_strips_tags_from_the_name() {
		Arriendo_Facil_Service_Providers::create(
			array(
				'name' => '<script>alert(1)</script>Juan',
			)
		);

		$this->assertStringNotContainsString( '<script>', $this->last_insert()['data']['name'] );
	}

	public function test_provider_create_rejects_another_owners_user_id() {
		$result = Arriendo_Facil_Service_Providers::create(
			array(
				'name'     => 'Intruso',
				'owner_id' => 99,
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'af_sp_permission', $result->get_error_code() );
		$this->assertEmpty( $GLOBALS['wpdb']->inserted );
	}

	public function test_provider_update_only_touches_supplied_fields() {
		$GLOBALS['wpdb']->var_result = 5;

		Arriendo_Facil_Service_Providers::update(
			3,
			array(
				'name'  => 'Ana Ruiz',
				'email' => 'ana@example.com',
			)
		);

		$keys = array_keys( $this->last_update()['data'] );

		$this->assertContains( 'name', $keys );
		$this->assertContains( 'email', $keys );
		$this->assertNotContains( 'trade', $keys );
		$this->assertNotContains( 'owner_id', $keys );
	}

	public function test_provider_update_clears_a_field_sent_as_empty_string() {
		$GLOBALS['wpdb']->var_result = 5;

		Arriendo_Facil_Service_Providers::update( 3, array( 'phone' => '' ) );

		$update = $this->last_update();

		$this->assertArrayHasKey( 'phone', $update['data'] );
		$this->assertNull( $update['data']['phone'] );
	}

	public function test_provider_update_requires_a_name_when_provided() {
		$GLOBALS['wpdb']->var_result = 5;

		$result = Arriendo_Facil_Service_Providers::update( 3, array( 'name' => '' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'af_sp_name_required', $result->get_error_code() );
	}

	public function test_provider_update_is_denied_for_a_provider_of_another_owner() {
		// var_result 0: can_access_service_provider() finds no owner row.
		$result = Arriendo_Facil_Service_Providers::update( 3, array( 'name' => 'X' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'af_sp_not_found', $result->get_error_code() );
		$this->assertEmpty( $GLOBALS['wpdb']->updates );
	}

	public function test_provider_update_clamps_the_rating_to_five() {
		$GLOBALS['wpdb']->var_result = 5;

		Arriendo_Facil_Service_Providers::update( 3, array( 'rating' => 99 ) );

		$this->assertSame( 5, $this->last_update()['data']['rating'] );
	}

	public function test_provider_update_normalises_an_invalid_status() {
		$GLOBALS['wpdb']->var_result = 5;

		Arriendo_Facil_Service_Providers::update( 3, array( 'status' => 'hackeado' ) );

		$this->assertSame( 'active', $this->last_update()['data']['status'] );
	}

	public function test_provider_delete_is_denied_for_a_provider_of_another_owner() {
		$result = Arriendo_Facil_Service_Providers::delete( 3 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'af_sp_not_found', $result->get_error_code() );
	}

	public function test_provider_delete_is_allowed_for_an_owned_provider() {
		$GLOBALS['wpdb']->var_result = 5;

		$this->assertTrue( Arriendo_Facil_Service_Providers::delete( 3 ) );
	}
}