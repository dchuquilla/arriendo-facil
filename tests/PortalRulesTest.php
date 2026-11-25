<?php
/**
 * Pruebas de las reglas puras del portal: reincidencia de incidentes y teléfono.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/tenants/class-portal-incidents.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/tenants/class-portal-profile.php';

class PortalRulesTest extends TestCase {

	public function test_asset_names_are_compared_without_accents_case_or_punctuation() {
		$this->assertSame( 'calefon', Arriendo_Facil_Portal_Incidents::normalize_asset( '  Calefón. ' ) );
		$this->assertTrue( Arriendo_Facil_Portal_Incidents::same_asset( 'Calefón', 'baño', 'calefon', 'Baño' ) );
	}

	public function test_same_name_in_another_place_is_not_the_same_asset() {
		$this->assertFalse( Arriendo_Facil_Portal_Incidents::same_asset( 'Llave', 'cocina', 'llave', 'baño' ) );
	}

	public function test_same_name_with_one_location_missing_still_matches() {
		$this->assertTrue( Arriendo_Facil_Portal_Incidents::same_asset( 'Calefón', '', 'calefón', 'baño' ) );
	}

	public function test_different_assets_do_not_match() {
		$this->assertFalse( Arriendo_Facil_Portal_Incidents::same_asset( 'Calefón', 'baño', 'Ventana', 'baño' ) );
	}

	public function test_without_names_only_an_identical_location_matches() {
		$this->assertTrue( Arriendo_Facil_Portal_Incidents::same_asset( '', 'Baño principal', '', 'baño principal' ) );
		$this->assertFalse( Arriendo_Facil_Portal_Incidents::same_asset( '', '', '', '' ) );
		// Un incidente con nombre no se confunde con uno sin nombre solo por el lugar.
		$this->assertFalse( Arriendo_Facil_Portal_Incidents::same_asset( 'Calefón', 'baño', '', 'baño' ) );
	}

	public function test_tenant_action_follows_responsibility_and_status() {
		$mk = static function ( $resp, $status, $ack = null, $repair = array() ) {
			return (object) array( 'responsibility' => $resp, 'status' => $status, 'tenant_ack' => $ack, 'repair_evidence' => $repair );
		};

		$this->assertSame( 'upload_repair', Arriendo_Facil_Portal_Incidents::tenant_action( $mk( 'tenant', 'in_progress' ) ) );
		$this->assertSame( 'none', Arriendo_Facil_Portal_Incidents::tenant_action( $mk( 'tenant', 'completed' ) ) );
		$this->assertSame( 'confirm', Arriendo_Facil_Portal_Incidents::tenant_action( $mk( 'owner', 'completed', null, array( 1 ) ) ) );
		// Sin evidencia del propietario no hay nada que confirmar.
		$this->assertSame( 'none', Arriendo_Facil_Portal_Incidents::tenant_action( $mk( 'owner', 'completed', null, array() ) ) );
		$this->assertSame( 'none', Arriendo_Facil_Portal_Incidents::tenant_action( $mk( 'owner', 'completed', 'conforme', array( 1 ) ) ) );
		$this->assertSame( 'none', Arriendo_Facil_Portal_Incidents::tenant_action( $mk( 'por_definir', 'pending' ) ) );
	}

	public function test_phone_normalization() {
		$this->assertSame( '0998855640', Arriendo_Facil_Portal_Profile::normalize_phone( '099 885-5640' ) );
		$this->assertSame( '+593998855640', Arriendo_Facil_Portal_Profile::normalize_phone( '+593 99 885 5640' ) );
		$this->assertSame( '', Arriendo_Facil_Portal_Profile::normalize_phone( '12345' ) );
		$this->assertSame( '', Arriendo_Facil_Portal_Profile::normalize_phone( 'abc' ) );
		$this->assertSame( '', Arriendo_Facil_Portal_Profile::normalize_phone( '1234567890123456' ) );
	}

	public function test_only_safe_profile_fields_are_editable() {
		$fields = array_keys( Arriendo_Facil_Portal_Profile::editable_fields() );
		$this->assertNotContains( 'email', $fields );
		$this->assertNotContains( 'first_name', $fields );
		$this->assertNotContains( 'id_number', $fields );
	}
}
