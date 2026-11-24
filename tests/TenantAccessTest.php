<?php
/**
 * Pruebas de las piezas puras del acceso por código (OTP) y del pre-registro.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}
}

if ( ! class_exists( 'Arriendo_Facil_Tenancy' ) ) {
	class Arriendo_Facil_Tenancy {
		const CAP = 'af_manage_properties';
	}
}

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/tenants/class-tenant-access.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/tenants/class-tenant-prereg.php';

class TenantAccessTest extends TestCase {

	public function test_code_is_six_digits_and_varies() {
		$seen = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$code = Arriendo_Facil_Tenant_Access::new_code();
			$this->assertMatchesRegularExpression( '/^\d{6}$/', $code );
			$seen[ $code ] = true;
		}
		$this->assertGreaterThan( 20, count( $seen ) );
	}

	public function test_normalize_code_keeps_digits_only_and_caps_length() {
		$this->assertSame( '123456', Arriendo_Facil_Tenant_Access::normalize_code( ' 123 456 ' ) );
		$this->assertSame( '123456', Arriendo_Facil_Tenant_Access::normalize_code( '12-34-56-78' ) );
		$this->assertSame( '', Arriendo_Facil_Tenant_Access::normalize_code( 'abc' ) );
	}

	public function test_code_matches_only_for_the_right_code_and_guest() {
		$hash = Arriendo_Facil_Tenant_Access::hash_code( '048213', 7 );

		$this->assertNotSame( '048213', $hash );
		$this->assertTrue( Arriendo_Facil_Tenant_Access::code_matches( '048213', $hash, 7 ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Access::code_matches( '048214', $hash, 7 ) );
		// El mismo código no sirve para otro inquilino.
		$this->assertFalse( Arriendo_Facil_Tenant_Access::code_matches( '048213', $hash, 8 ) );
		// Un código incompleto nunca coincide.
		$this->assertFalse( Arriendo_Facil_Tenant_Access::code_matches( '0482', $hash, 7 ) );
	}

	public function test_expiry_uses_utc_dates() {
		$now = strtotime( '2026-11-24 10:00:00 UTC' );

		$this->assertTrue( Arriendo_Facil_Tenant_Access::is_expired( '2026-11-24 09:59:59', $now ) );
		$this->assertTrue( Arriendo_Facil_Tenant_Access::is_expired( '2026-11-24 10:00:00', $now ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Access::is_expired( '2026-11-24 10:10:00', $now ) );
		$this->assertTrue( Arriendo_Facil_Tenant_Access::is_expired( 'no es fecha', $now ) );
	}

	public function test_mask_email_hides_the_local_part() {
		$this->assertSame( 'j••••••@gmail.com', Arriendo_Facil_Tenant_Access::mask_email( 'juan.perez@gmail.com' ) );
		$this->assertSame( 'a•••@x.ec', Arriendo_Facil_Tenant_Access::mask_email( 'ab@x.ec' ) );
		$this->assertSame( '', Arriendo_Facil_Tenant_Access::mask_email( 'no-es-correo' ) );
	}

	public function test_signed_reference_round_trips_and_rejects_tampering() {
		$ref = Arriendo_Facil_Tenant_Access::sign_ref( 42 );

		$this->assertSame( 42, Arriendo_Facil_Tenant_Access::parse_ref( $ref ) );
		// Cambiar el ID invalida la firma.
		$this->assertSame( 0, Arriendo_Facil_Tenant_Access::parse_ref( '43.' . substr( $ref, strpos( $ref, '.' ) + 1 ) ) );
		$this->assertSame( 0, Arriendo_Facil_Tenant_Access::parse_ref( '42.' . str_repeat( 'a', 24 ) ) );
		$this->assertSame( 0, Arriendo_Facil_Tenant_Access::parse_ref( '42' ) );
		$this->assertSame( 0, Arriendo_Facil_Tenant_Access::parse_ref( '' ) );
	}

	public function test_csrf_token_depends_on_the_session() {
		$a = Arriendo_Facil_Tenant_Access::csrf_for( 'sesion-a' );
		$b = Arriendo_Facil_Tenant_Access::csrf_for( 'sesion-b' );

		$this->assertSame( 32, strlen( $a ) );
		$this->assertNotSame( $a, $b );
		$this->assertSame( $a, Arriendo_Facil_Tenant_Access::csrf_for( 'sesion-a' ) );
	}

	public function test_prereg_name_validation() {
		$this->assertTrue( Arriendo_Facil_Tenant_Prereg::valid_name( 'María José' ) );
		$this->assertTrue( Arriendo_Facil_Tenant_Prereg::valid_name( "O'Brien-Núñez" ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Prereg::valid_name( 'A' ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Prereg::valid_name( 'Juan123' ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Prereg::valid_name( '<script>' ) );
	}

	public function test_prereg_phone_normalization() {
		$this->assertSame( '0991234567', Arriendo_Facil_Tenant_Prereg::normalize_phone( '099 123-4567' ) );
		$this->assertSame( '+593991234567', Arriendo_Facil_Tenant_Prereg::normalize_phone( '+593 99 123 4567' ) );
		$this->assertSame( '', Arriendo_Facil_Tenant_Prereg::normalize_phone( '123' ) );
		$this->assertSame( '', Arriendo_Facil_Tenant_Prereg::normalize_phone( '1234567890123456' ) );
	}

	public function test_prereg_form_timing_rejects_bots_and_stale_forms() {
		$ts  = 1000000;
		$sig = Arriendo_Facil_Tenant_Prereg::form_sig( $ts );

		$this->assertFalse( Arriendo_Facil_Tenant_Prereg::form_fresh( $ts, $sig, $ts + 1 ), 'Demasiado rápido.' );
		$this->assertTrue( Arriendo_Facil_Tenant_Prereg::form_fresh( $ts, $sig, $ts + 10 ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Prereg::form_fresh( $ts, $sig, $ts + 3 * HOUR_IN_SECONDS ), 'Formulario viejo.' );
		$this->assertFalse( Arriendo_Facil_Tenant_Prereg::form_fresh( $ts, 'firma-falsa', $ts + 10 ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Prereg::form_fresh( $ts + 1, $sig, $ts + 10 ), 'La hora no coincide con la firma.' );
	}

	public function test_prereg_messages_mention_name_and_link() {
		$this->assertStringContainsString( 'Ana', Arriendo_Facil_Tenant_Prereg::interested_message( 'Ana', 'Casa Sol' ) );
		$this->assertStringContainsString( 'Casa Sol', Arriendo_Facil_Tenant_Prereg::interested_message( 'Ana', 'Casa Sol' ) );
		$this->assertStringNotContainsString( '""', Arriendo_Facil_Tenant_Prereg::interested_message( 'Ana' ) );
		$this->assertStringContainsString( 'https://x.ec/f', Arriendo_Facil_Tenant_Prereg::manager_message( 'Ana', 'https://x.ec/f' ) );
	}

	public function test_property_signature_binds_manager_and_property() {
		$sig = Arriendo_Facil_Tenant_Prereg::property_sig( 5, 100 );

		$this->assertSame( $sig, Arriendo_Facil_Tenant_Prereg::property_sig( 5, 100 ) );
		$this->assertNotSame( $sig, Arriendo_Facil_Tenant_Prereg::property_sig( 5, 101 ), 'Otro inmueble.' );
		$this->assertNotSame( $sig, Arriendo_Facil_Tenant_Prereg::property_sig( 6, 100 ), 'Otro gestor.' );
	}

	public function test_same_person_ignores_accents_case_and_missing_words() {
		$this->assertTrue( Arriendo_Facil_Tenant_Prereg::same_person( 'Melissa Carmendia', 'melissa  CARMENDIA' ) );
		$this->assertTrue( Arriendo_Facil_Tenant_Prereg::same_person( 'Ana Pérez', 'Ana María Pérez Loor' ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Prereg::same_person( 'Melissa Carmendia', 'Jason Aldaz' ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Prereg::same_person( '', 'Jason Aldaz' ) );
	}

	public function test_ip_in_ranges_acepta_solo_rangos_de_cloudflare() {
		$ranges = array( '173.245.48.0/20', '104.16.0.0/13', '2606:4700::/32' );

		$this->assertTrue( Arriendo_Facil_Tenant_Access::ip_in_ranges( '173.245.48.10', $ranges ) );
		$this->assertTrue( Arriendo_Facil_Tenant_Access::ip_in_ranges( '104.23.255.1', $ranges ) );
		$this->assertTrue( Arriendo_Facil_Tenant_Access::ip_in_ranges( '2606:4700:10::1', $ranges ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Access::ip_in_ranges( '104.24.0.1', $ranges ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Access::ip_in_ranges( '8.8.8.8', $ranges ) );
		$this->assertFalse( Arriendo_Facil_Tenant_Access::ip_in_ranges( 'no-es-ip', $ranges ) );
	}
}
