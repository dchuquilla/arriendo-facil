<?php
/**
 * Unit tests for Fase 2: SRI access-key generator and XML factura builder.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

/**
 * Class SRIBillingTest
 */
class SRIBillingTest extends TestCase {

	// ─── Fixtures ────────────────────────────────────────────────────────────

	private function make_fecha(): DateTime {
		return new DateTime( '2026-06-01' );
	}

	private function base_clave_params(): array {
		return array(
			'tipo_comprobante'    => Arriendo_Facil_SRI_Clave_Acceso::TIPO_FACTURA,
			'ruc'                 => '0912345678001',
			'ambiente'            => '1',
			'cod_establecimiento' => '001',
			'cod_punto_emision'   => '001',
			'secuencial'          => 1,
		);
	}

	private function minimal_invoice_data( string $clave ): array {
		$totals = Arriendo_Facil_SRI_XML_Factura::compute_totals(
			array(
				array(
					'codigo_principal' => 'ARRIENDO',
					'descripcion'      => 'Arriendo mes Junio 2026 – Apto 3B',
					'cantidad'         => 1,
					'precio_unitario'  => 500.00,
					'descuento'        => 0.00,
				),
			),
			'0'  // IVA 0% (vivienda)
		);

		return array_merge(
			array(
				// Issuer
				'ambiente'              => '1',
				'tipo_emision'          => '1',
				'razon_social'          => 'ARRIENDO FACIL S.A.',
				'nombre_comercial'      => 'Arriendo Fácil',
				'ruc'                   => '0912345678001',
				'clave_acceso'          => $clave,
				'estab'                 => '001',
				'pto_emi'               => '001',
				'secuencial'            => '000000001',
				'dir_matriz'            => 'Quito, Ecuador',
				// Header
				'fecha_emision'         => '01/06/2026',
				'dir_establecimiento'   => 'Quito, Ecuador',
				'obligado_contabilidad' => 'NO',
				// Buyer
				'tipo_id_comprador'           => '05',
				'razon_social_comprador'      => 'JUAN PEREZ',
				'identificacion_comprador'    => '1712345678',
				// Payment
				'forma_pago'   => '01',
				'plazo'        => '30',
				'unidad_tiempo' => 'dias',
				// Additional
				'info_adicional' => array( 'Email' => 'juan@example.com' ),
			),
			$totals
		);
	}

	// ─── Arriendo_Facil_SRI_Clave_Acceso ────────────────────────────────────

	public function test_generate_returns_49_digits() {
		$p     = $this->base_clave_params();
		$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
			$this->make_fecha(),
			$p['tipo_comprobante'],
			$p['ruc'],
			$p['ambiente'],
			$p['cod_establecimiento'],
			$p['cod_punto_emision'],
			$p['secuencial']
		);
		$this->assertSame( 49, strlen( $clave ), 'Clave de acceso must be 49 digits.' );
		$this->assertMatchesRegularExpression( '/^\d{49}$/', $clave, 'Clave must contain only digits.' );
	}

	public function test_generate_check_digit_is_valid() {
		$p     = $this->base_clave_params();
		$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
			$this->make_fecha(),
			$p['tipo_comprobante'],
			$p['ruc'],
			$p['ambiente'],
			$p['cod_establecimiento'],
			$p['cod_punto_emision'],
			$p['secuencial']
		);
		$this->assertTrue(
			Arriendo_Facil_SRI_Clave_Acceso::validate( $clave ),
			'Freshly generated key must pass validate().'
		);
	}

	public function test_validate_rejects_wrong_length() {
		$this->assertFalse( Arriendo_Facil_SRI_Clave_Acceso::validate( '12345678' ) );
		$this->assertFalse( Arriendo_Facil_SRI_Clave_Acceso::validate( '' ) );
	}

	public function test_validate_rejects_bad_check_digit() {
		$p     = $this->base_clave_params();
		$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
			$this->make_fecha(),
			$p['tipo_comprobante'],
			$p['ruc'],
			$p['ambiente'],
			$p['cod_establecimiento'],
			$p['cod_punto_emision'],
			1
		);
		// Flip the last digit.
		$bad_digit = ( (int) $clave[48] + 1 ) % 10;
		$tampered  = substr( $clave, 0, 48 ) . $bad_digit;
		$this->assertFalse( Arriendo_Facil_SRI_Clave_Acceso::validate( $tampered ) );
	}

	public function test_extract_parts_date_matches_input() {
		$fecha = new DateTime( '2026-01-15' );
		$p     = $this->base_clave_params();
		$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
			$fecha,
			$p['tipo_comprobante'],
			$p['ruc'],
			$p['ambiente'],
			$p['cod_establecimiento'],
			$p['cod_punto_emision'],
			$p['secuencial']
		);
		$parts = Arriendo_Facil_SRI_Clave_Acceso::extract_parts( $clave );
		$this->assertSame( '15012026', $parts['fecha_emision'], 'Extracted date must be ddmmYYYY.' );
		$this->assertSame( '01',       $parts['tipo_comprobante'] );
		$this->assertSame( '0912345678001', $parts['ruc'] );
		$this->assertSame( '1',        $parts['ambiente'] );
		$this->assertSame( '001',      $parts['cod_establecimiento'] );
		$this->assertSame( '001',      $parts['cod_punto_emision'] );
		$this->assertSame( '000000001', $parts['secuencial'] );
	}

	public function test_modulo11_known_value() {
		// All-1s string: since every digit is equal, L→R and R→L give the same sum,
		// so this test validates the formula but not the direction.
		// 48/6 = 8 complete cycles; each cycle sums 2+3+4+5+6+7 = 27 → total 216.
		// 216 % 11 = 7 → dígito = 11 - 7 = 4.
		$base  = str_repeat( '1', 48 );
		$digit = Arriendo_Facil_SRI_Clave_Acceso::modulo11( $base );
		$this->assertSame( '4', $digit );
	}

	public function test_modulo11_direction_right_to_left() {
		// Asymmetric string: 46 zeros, then '1', then '0' (positions 46 and 47).
		// The SRI spec requires RIGHT-TO-LEFT: last digit (pos 47, '0') × 2 = 0,
		// second-to-last (pos 46, '1') × 3 = 3.  Sum = 3.
		// 3 % 11 = 3 → dígito = 11 - 3 = 8.
		// (Left-to-right would yield pos 46 × factor[4]=6 → sum=6 → dígito=5, which is WRONG.)
		$base  = str_repeat( '0', 46 ) . '10'; // 48 digits
		$digit = Arriendo_Facil_SRI_Clave_Acceso::modulo11( $base );
		$this->assertSame( '8', $digit, 'Módulo 11 must apply factors right-to-left per SRI spec.' );
	}

	public function test_format_numero_comprobante() {
		$num = Arriendo_Facil_SRI_Clave_Acceso::format_numero_comprobante( '001', '001', 1 );
		$this->assertSame( '001-001-000000001', $num );

		$num2 = Arriendo_Facil_SRI_Clave_Acceso::format_numero_comprobante( '2', '3', 150 );
		$this->assertSame( '002-003-000000150', $num2 );
	}

	// ─── Arriendo_Facil_SRI_XML_Factura – helpers ────────────────────────────

	public function test_iva_tarifa_from_codigo() {
		$this->assertSame( 0.00,  Arriendo_Facil_SRI_XML_Factura::iva_tarifa_from_codigo( '0' ) );
		$this->assertSame( 12.00, Arriendo_Facil_SRI_XML_Factura::iva_tarifa_from_codigo( '2' ) );
		$this->assertSame( 15.00, Arriendo_Facil_SRI_XML_Factura::iva_tarifa_from_codigo( '4' ) );
		$this->assertSame( 5.00,  Arriendo_Facil_SRI_XML_Factura::iva_tarifa_from_codigo( '5' ) );
		$this->assertSame( 0.00,  Arriendo_Facil_SRI_XML_Factura::iva_tarifa_from_codigo( 'X' ) );
	}

	public function test_infer_buyer_id_type() {
		$this->assertSame( '04', Arriendo_Facil_SRI_XML_Factura::infer_buyer_id_type( '0912345678001' ) ); // RUC
		$this->assertSame( '05', Arriendo_Facil_SRI_XML_Factura::infer_buyer_id_type( '1712345678' ) );    // cédula
		$this->assertSame( '06', Arriendo_Facil_SRI_XML_Factura::infer_buyer_id_type( 'AB123456' ) );     // pasaporte
		$this->assertSame( '07', Arriendo_Facil_SRI_XML_Factura::infer_buyer_id_type( '' ) );             // consumidor final
		$this->assertSame( '07', Arriendo_Facil_SRI_XML_Factura::infer_buyer_id_type( '9999999999999' ) );
	}

	public function test_compute_totals_iva_0() {
		$items = array(
			array( 'codigo_principal' => 'A', 'descripcion' => 'Item', 'cantidad' => 1, 'precio_unitario' => 500.00 ),
		);
		$totals = Arriendo_Facil_SRI_XML_Factura::compute_totals( $items, '0' );

		$this->assertSame( 500.00, $totals['total_sin_impuestos'] );
		$this->assertSame( 0.00,   $totals['iva_valor'] );
		$this->assertSame( 500.00, $totals['importe_total'] );
		$this->assertSame( '0',    $totals['iva_codigo_porcentaje'] );
		$this->assertCount( 1,     $totals['items'] );
		$this->assertSame( 0.00,   $totals['items'][0]['iva_valor'] );
	}

	public function test_compute_totals_iva_15() {
		$items = array(
			array( 'codigo_principal' => 'B', 'descripcion' => 'Comercial', 'cantidad' => 1, 'precio_unitario' => 200.00 ),
		);
		$totals = Arriendo_Facil_SRI_XML_Factura::compute_totals( $items, '4' ); // 15%

		$this->assertSame( 200.00, $totals['total_sin_impuestos'] );
		$this->assertSame( 30.00,  $totals['iva_valor'] );     // 200 * 0.15
		$this->assertSame( 230.00, $totals['importe_total'] );
	}

	public function test_compute_totals_multiple_items() {
		$items = array(
			array( 'codigo_principal' => 'C', 'descripcion' => 'X', 'cantidad' => 2, 'precio_unitario' => 100.00 ),
			array( 'codigo_principal' => 'D', 'descripcion' => 'Y', 'cantidad' => 1, 'precio_unitario' => 50.00, 'descuento' => 10.00 ),
		);
		$totals = Arriendo_Facil_SRI_XML_Factura::compute_totals( $items, '4' ); // 15%

		// Item 1: 2 * 100 = 200, IVA = 30
		// Item 2: 1 * 50 - 10 = 40, IVA = 6
		$this->assertSame( 240.00, $totals['total_sin_impuestos'] );
		$this->assertSame( 36.00,  $totals['iva_valor'] );
		$this->assertSame( 276.00, $totals['importe_total'] );
	}

	// ─── Arriendo_Facil_SRI_XML_Factura – XML build ─────────────────────────

	private function build_test_xml(): string {
		$p     = $this->base_clave_params();
		$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
			$this->make_fecha(),
			$p['tipo_comprobante'],
			$p['ruc'],
			$p['ambiente'],
			$p['cod_establecimiento'],
			$p['cod_punto_emision'],
			$p['secuencial']
		);
		$data = $this->minimal_invoice_data( $clave );
		return ( new Arriendo_Facil_SRI_XML_Factura() )->build( $data );
	}

	public function test_build_returns_non_empty_string() {
		$xml = $this->build_test_xml();
		$this->assertIsString( $xml );
		$this->assertNotEmpty( $xml );
	}

	public function test_build_produces_valid_xml() {
		$xml = $this->build_test_xml();
		$dom = new DOMDocument();
		$result = $dom->loadXML( $xml );
		$this->assertTrue( $result, 'build() must produce well-formed XML.' );
	}

	public function test_build_root_element_and_attributes() {
		$xml = $this->build_test_xml();
		$dom = new DOMDocument();
		$dom->loadXML( $xml );

		$root = $dom->documentElement;
		$this->assertSame( 'factura', $root->tagName );
		$this->assertSame( 'comprobante', $root->getAttribute( 'id' ) );
		$this->assertSame( '2.1.0', $root->getAttribute( 'version' ) );
	}

	public function test_build_info_tributaria_ruc() {
		$xml  = $this->build_test_xml();
		$dom  = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );

		$ruc = $xpath->evaluate( 'string(//infoTributaria/ruc)' );
		$this->assertSame( '0912345678001', $ruc );
	}

	public function test_build_info_factura_totals() {
		$xml  = $this->build_test_xml();
		$dom  = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );

		$total = $xpath->evaluate( 'string(//infoFactura/importeTotal)' );
		$this->assertSame( '500.00', $total );

		$iva = $xpath->evaluate( 'string(//infoFactura/totalConImpuestos/totalImpuesto/valor)' );
		$this->assertSame( '0.00', $iva, 'IVA 0% invoice should have zero IVA.' );
	}

	public function test_build_contains_one_detalle() {
		$xml = $this->build_test_xml();
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath    = new DOMXPath( $dom );
		$detalles = $xpath->query( '//detalles/detalle' );
		$this->assertSame( 1, $detalles->length );
	}

	public function test_build_detalle_amounts() {
		$xml  = $this->build_test_xml();
		$dom  = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );

		$base = $xpath->evaluate( 'string(//detalles/detalle/precioTotalSinImpuesto)' );
		$this->assertSame( '500.00', $base );

		$qty = $xpath->evaluate( 'string(//detalles/detalle/cantidad)' );
		$this->assertSame( '1.000000', $qty );
	}

	public function test_build_info_adicional_email() {
		$xml  = $this->build_test_xml();
		$dom  = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );

		$email = $xpath->evaluate( 'string(//infoAdicional/campoAdicional[@nombre="Email"])' );
		$this->assertSame( 'juan@example.com', $email );
	}

	public function test_build_fecha_emision_datetime_input() {
		// Verify DateTime input is normalized to dd/mm/YYYY.
		$p     = $this->base_clave_params();
		$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
			$this->make_fecha(),
			$p['tipo_comprobante'],
			$p['ruc'],
			$p['ambiente'],
			$p['cod_establecimiento'],
			$p['cod_punto_emision'],
			2
		);
		$data                  = $this->minimal_invoice_data( $clave );
		$data['fecha_emision'] = new DateTime( '2026-03-25' );

		$xml = ( new Arriendo_Facil_SRI_XML_Factura() )->build( $data );
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );

		$fecha = $xpath->evaluate( 'string(//infoFactura/fechaEmision)' );
		$this->assertSame( '25/03/2026', $fecha );
	}

	public function test_build_escapes_special_characters() {
		$p     = $this->base_clave_params();
		$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
			$this->make_fecha(),
			$p['tipo_comprobante'],
			$p['ruc'],
			$p['ambiente'],
			$p['cod_establecimiento'],
			$p['cod_punto_emision'],
			3
		);
		$data                         = $this->minimal_invoice_data( $clave );
		$data['razon_social']         = 'EMPRESA & CÍA <S.A.>';
		$data['info_adicional']['Dir'] = 'Av. 10 de Agosto & Colón';

		$xml = ( new Arriendo_Facil_SRI_XML_Factura() )->build( $data );
		$dom = new DOMDocument();
		$loaded = $dom->loadXML( $xml );

		$this->assertTrue( $loaded, 'XML with special chars must still be well-formed.' );

		$xpath    = new DOMXPath( $dom );
		$razon    = $xpath->evaluate( 'string(//infoTributaria/razonSocial)' );
		$this->assertSame( 'EMPRESA & CÍA <S.A.>', $razon, 'Text content must be round-tripped correctly.' );
	}

	public function test_build_iva_15_amounts() {
		$p     = $this->base_clave_params();
		$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
			$this->make_fecha(),
			$p['tipo_comprobante'],
			$p['ruc'],
			$p['ambiente'],
			$p['cod_establecimiento'],
			$p['cod_punto_emision'],
			4
		);

		$raw_items = array(
			array( 'codigo_principal' => 'ARRIENDO-COM', 'descripcion' => 'Local comercial', 'cantidad' => 1, 'precio_unitario' => 800.00 ),
		);
		$totals = Arriendo_Facil_SRI_XML_Factura::compute_totals( $raw_items, '4' ); // 15%

		$data = array_merge(
			array(
				'ambiente'              => '1',
				'tipo_emision'          => '1',
				'razon_social'          => 'EMPRESA TEST',
				'ruc'                   => '0912345678001',
				'clave_acceso'          => $clave,
				'estab'                 => '001',
				'pto_emi'               => '001',
				'secuencial'            => '000000004',
				'dir_matriz'            => 'Quito',
				'fecha_emision'         => '01/06/2026',
				'dir_establecimiento'   => 'Quito',
				'obligado_contabilidad' => 'NO',
				'tipo_id_comprador'           => '05',
				'razon_social_comprador'      => 'CLIENTE FINAL',
				'identificacion_comprador'    => '1712345678',
				'forma_pago'    => '01',
				'plazo'         => '30',
				'unidad_tiempo' => 'dias',
			),
			$totals
		);

		$xml  = ( new Arriendo_Facil_SRI_XML_Factura() )->build( $data );
		$dom  = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );

		$importe = $xpath->evaluate( 'string(//infoFactura/importeTotal)' );
		$this->assertSame( '920.00', $importe ); // 800 + 15%

		$iva = $xpath->evaluate( 'string(//infoFactura/totalConImpuestos/totalImpuesto/valor)' );
		$this->assertSame( '120.00', $iva );

		$codigo_pct = $xpath->evaluate( 'string(//infoFactura/totalConImpuestos/totalImpuesto/codigoPorcentaje)' );
		$this->assertSame( '4', $codigo_pct );
	}
}
