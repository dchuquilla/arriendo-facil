<?php
/**
 * Unit tests for Fase 4: RIDE PDF + billing orchestrator.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

/**
 * Class SRIPhase4Test
 */
class SRIPhase4Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Reset test option store.
		$GLOBALS['af_test_options'] = array();

		// Ensure cert and uploads dirs exist for deterministic tests.
		@mkdir( WP_CONTENT_DIR, 0755, true );
		@mkdir( WP_CONTENT_DIR . '/af-certs', 0755, true );
		@mkdir( WP_CONTENT_DIR . '/uploads', 0755, true );
	}

	public function test_ride_build_pdf_binary_has_valid_header() {
		$ride = new Arriendo_Facil_SRI_Ride();
		$pdf  = $ride->build_pdf_binary(
			array(
				'numero_comprobante'      => '001-001-000000001',
				'clave_acceso'            => str_repeat( '1', 49 ),
				'numero_autorizacion'     => str_repeat( '2', 49 ),
				'fecha_autorizacion'      => '2026-06-02T10:00:00-05:00',
				'ruc'                     => '0912345678001',
				'razon_social'            => 'EMPRESA PRUEBA S.A.',
				'razon_social_comprador'  => 'Cliente Prueba',
				'identificacion_comprador'=> '1712345678',
				'subtotal_0'              => 400.00,
				'subtotal_iva'            => 0.00,
				'iva_valor'               => 0.00,
				'total'                   => 400.00,
				'items'                   => array(),
			)
		);

		$this->assertStringStartsWith( '%PDF-1.4', $pdf );
		$this->assertStringContainsString( 'trailer', $pdf );
		$this->assertStringContainsString( 'RIDE - FACTURA ELECTRONICA', $pdf );
	}

	public function test_ride_generate_writes_pdf_file() {
		$ride   = new Arriendo_Facil_SRI_Ride();
		$result = $ride->generate(
			array(
				'numero_comprobante' => '001-001-000000123',
				'clave_acceso'       => str_repeat( '9', 49 ),
				'items'              => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'path', $result );
		$this->assertFileExists( $result['path'] );

		$header = file_get_contents( $result['path'], false, null, 0, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( '%PDF-1.4', $header );
	}

	public function test_issue_from_payload_requires_sri_config() {
		$manager = new Arriendo_Facil_Billing_Manager();
		$result  = $manager->issue_from_payload(
			array(
				'razon_social_comprador'   => 'Cliente',
				'identificacion_comprador' => '1712345678',
				'iva_codigo_porcentaje'    => '0',
				'items'                    => array(
					array(
						'codigo_principal' => 'ARRIENDO',
						'descripcion'      => 'Canon mensual',
						'cantidad'         => 1,
						'precio_unitario'  => 400,
					),
				),
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'sri_config_missing_ruc', $result->get_error_code() );
	}

	public function test_issue_from_payload_successful_flow() {
		$cert_file = WP_CONTENT_DIR . '/af-certs/test_cert.p12';
		file_put_contents( $cert_file, 'dummy-cert' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		update_option(
			Arriendo_Facil_SRI_Config::OPTION_KEY,
			array(
				'ruc'                   => '0912345678001',
				'razon_social'          => 'EMPRESA PRUEBA S.A.',
				'nombre_comercial'      => 'Arriendo Facil',
				'dir_establecimiento'   => 'Quito',
				'dir_matriz'            => 'Quito',
				'obligado_contabilidad' => 'NO',
				'ambiente'              => '1',
				'tipo_emision'          => '1',
				'cert_filename'         => 'test_cert.p12',
				'cert_password_enc'     => Arriendo_Facil_SRI_Config::encrypt_password( 'testpass' ),
			)
		);

		$manager = new Arriendo_Facil_Billing_Manager_Test_Double(
			array(
				'signer_factory' => function() {
					return new Arriendo_Facil_Signer_Test_Double();
				},
				'soap_factory' => function() {
					return new Arriendo_Facil_Soap_Test_Double();
				},
				'ride_generator' => new Arriendo_Facil_Ride_Test_Double(),
			)
		);

		$result = $manager->issue_from_payload(
			array(
				'fecha_emision'            => '02/06/2026',
				'razon_social_comprador'   => 'Cliente Demo',
				'identificacion_comprador' => '1712345678',
				'iva_codigo_porcentaje'    => '0',
				'items'                    => array(
					array(
						'codigo_principal' => 'ARRIENDO',
						'descripcion'      => 'Canon mensual demo',
						'cantidad'         => 1,
						'precio_unitario'  => 500.00,
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'autorizada', $result['estado'] );
		$this->assertSame( 999, $result['invoice_id'] );
		$this->assertArrayHasKey( 'ride_path', $result );
		$this->assertNotEmpty( $manager->updates );
		$this->assertSame( 'autorizada', $manager->last_estado );
	}

	public function test_issue_from_payload_handles_sri_devuelta() {
		$cert_file = WP_CONTENT_DIR . '/af-certs/test_cert_2.p12';
		file_put_contents( $cert_file, 'dummy-cert' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		update_option(
			Arriendo_Facil_SRI_Config::OPTION_KEY,
			array(
				'ruc'                   => '0912345678001',
				'razon_social'          => 'EMPRESA PRUEBA S.A.',
				'ambiente'              => '1',
				'tipo_emision'          => '1',
				'cert_filename'         => 'test_cert_2.p12',
				'cert_password_enc'     => Arriendo_Facil_SRI_Config::encrypt_password( 'testpass' ),
			)
		);

		$manager = new Arriendo_Facil_Billing_Manager_Test_Double(
			array(
				'signer_factory' => function() {
					return new Arriendo_Facil_Signer_Test_Double();
				},
				'soap_factory' => function() {
					return new Arriendo_Facil_Soap_Devuelta_Test_Double();
				},
				'ride_generator' => new Arriendo_Facil_Ride_Test_Double(),
			)
		);

		$result = $manager->issue_from_payload(
			array(
				'fecha_emision'            => '02/06/2026',
				'razon_social_comprador'   => 'Cliente Demo',
				'identificacion_comprador' => '1712345678',
				'iva_codigo_porcentaje'    => '0',
				'items'                    => array(
					array(
						'codigo_principal' => 'ARRIENDO',
						'descripcion'      => 'Canon mensual demo',
						'cantidad'         => 1,
						'precio_unitario'  => 500.00,
					),
				),
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'sri_devuelta', $result->get_error_code() );
		$this->assertSame( 'devuelta', $manager->last_estado );
	}
}

/**
 * Test double for billing manager internals that touch DB.
 */
class Arriendo_Facil_Billing_Manager_Test_Double extends Arriendo_Facil_Billing_Manager {
	public $updates = array();
	public $logs = array();
	public $last_estado = '';

	protected function reserve_next_sequence() {
		return array(
			'estab'      => '001',
			'pto_emi'    => '001',
			'secuencial' => 1,
		);
	}

	protected function insert_invoice_row( array $row ): int {
		return 999;
	}

	protected function update_invoice_row( int $invoice_id, array $data ): void {
		$this->updates[] = array(
			'invoice_id' => $invoice_id,
			'data'       => $data,
		);
		if ( isset( $data['estado'] ) ) {
			$this->last_estado = (string) $data['estado'];
		}
	}

	protected function log_sri( int $invoice_id, string $tipo_operacion, $request_payload, $response_payload ): void {
		$this->logs[] = array(
			'invoice_id'      => $invoice_id,
			'tipo_operacion'  => $tipo_operacion,
			'request_payload' => $request_payload,
			'response_payload'=> $response_payload,
		);
	}
}

/**
 * Fake signer.
 */
class Arriendo_Facil_Signer_Test_Double {
	public function sign( string $xml ): string {
		return $xml . '<!-- signed -->';
	}
}

/**
 * Fake SOAP service for successful flow.
 */
class Arriendo_Facil_Soap_Test_Double {
	public function enviar( string $xml_firmado ) {
		return array(
			'estado'   => 'RECIBIDA',
			'mensajes' => array(),
		);
	}

	public function autorizar( string $clave_acceso ) {
		return array(
			'estado'              => 'AUTORIZADO',
			'numero_autorizacion' => str_repeat( '7', 49 ),
			'fecha_autorizacion'  => '2026-06-02T11:00:00-05:00',
			'ambiente'            => 'PRUEBAS',
			'xml_autorizacion'    => '<autorizacion/>',
			'mensajes'            => array(),
		);
	}
}

/**
 * Fake SOAP service for rejected reception.
 */
class Arriendo_Facil_Soap_Devuelta_Test_Double {
	public function enviar( string $xml_firmado ) {
		return array(
			'estado'   => 'DEVUELTA',
			'mensajes' => array(
				array(
					'identificador' => '43',
					'mensaje'       => 'CLAVE REGISTRADA',
					'tipo'          => 'ERROR',
				),
			),
		);
	}

	public function autorizar( string $clave_acceso ) {
		return new WP_Error( 'not_called', 'Not expected' );
	}
}

/**
 * Fake RIDE generator.
 */
class Arriendo_Facil_Ride_Test_Double {
	public function generate( array $data ) {
		return array(
			'path'     => WP_CONTENT_DIR . '/uploads/fake-ride.pdf',
			'filename' => 'fake-ride.pdf',
		);
	}
}
