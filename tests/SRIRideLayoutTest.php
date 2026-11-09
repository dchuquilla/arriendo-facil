<?php
/**
 * RIDE layout: data comes from the XML sent to the SRI, never from placeholders.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

class SRIRideLayoutTest extends TestCase {

	private function xml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?><factura id="comprobante" version="1.1.0">'
			. '<infoTributaria><ambiente>1</ambiente><tipoEmision>1</tipoEmision><razonSocial>KATTY PEREZ</razonSocial><ruc>1717012890001</ruc>'
			. '<claveAcceso>0911202601171701289000110010010000000010556945111</claveAcceso><estab>001</estab><ptoEmi>001</ptoEmi><secuencial>000000001</secuencial><dirMatriz>Quito</dirMatriz></infoTributaria>'
			. '<infoFactura><fechaEmision>09/11/2026</fechaEmision><dirEstablecimiento>Quito</dirEstablecimiento><obligadoContabilidad>NO</obligadoContabilidad>'
			. '<tipoIdentificacionComprador>06</tipoIdentificacionComprador><razonSocialComprador>JOHN SMITH</razonSocialComprador><identificacionComprador>AB1234567</identificacionComprador>'
			. '<totalSinImpuestos>345.00</totalSinImpuestos><totalDescuento>0.00</totalDescuento><totalConImpuestos><totalImpuesto><codigo>2</codigo><codigoPorcentaje>0</codigoPorcentaje><baseImponible>345.00</baseImponible><valor>0.00</valor></totalImpuesto></totalConImpuestos>'
			. '<importeTotal>345.00</importeTotal><pagos><pago><formaPago>20</formaPago><total>345.00</total></pago></pagos></infoFactura>'
			. '<detalles><detalle><codigoPrincipal>CANON</codigoPrincipal><descripcion>Canon de arriendo 2026-10</descripcion><cantidad>1.000000</cantidad><precioUnitario>345.000000</precioUnitario><descuento>0.00</descuento><precioTotalSinImpuesto>345.00</precioTotalSinImpuesto><impuestos><impuesto><tarifa>0.00</tarifa></impuesto></impuestos></detalle></detalles>'
			. '<infoAdicional><campoAdicional nombre="email">john@example.com</campoAdicional></infoAdicional></factura>';
	}

	public function test_data_is_read_from_the_xml_sent_to_the_sri(): void {
		$data = Arriendo_Facil_SRI_Ride::data_from_xml( $this->xml() );

		$this->assertSame( 'KATTY PEREZ', $data['razon_social'] );
		$this->assertSame( '1717012890001', $data['ruc'] );
		$this->assertSame( 'JOHN SMITH', $data['razon_social_comprador'] );
		$this->assertSame( 'AB1234567', $data['identificacion_comprador'] );
		$this->assertSame( 'PRUEBAS', $data['ambiente_label'] );
		$this->assertSame( 'john@example.com', $data['info_adicional']['email'] );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( 345.0, $data['importe_total'] );
	}

	public function test_pdf_shows_issuer_buyer_barcode_and_test_banner(): void {
		$ride = new Arriendo_Facil_SRI_Ride();
		$data = Arriendo_Facil_SRI_Ride::data_from_xml( $this->xml() ) + array( 'numero_comprobante' => '001-001-000000001' );
		$pdf  = $ride->build_pdf_binary( $data );

		$this->assertStringStartsWith( '%PDF-1.4', $pdf );
		$this->assertStringContainsString( '(KATTY PEREZ)', $pdf );
		$this->assertStringContainsString( '(JOHN SMITH)', $pdf );
		$this->assertStringContainsString( 'SIN VALIDEZ TRIBUTARIA', $pdf );
		$this->assertGreaterThan( 50, substr_count( $pdf, ' re f' ) );
	}

	public function test_code128_matches_reference_encoding_of_the_access_key(): void {
		$symbols = ( new Arriendo_Facil_SRI_Ride() )->code128_symbols( '0911202601171701289000110010010000000010556945111' );

		$this->assertSame(
			array( 105, 9, 11, 20, 26, 1, 17, 17, 1, 28, 90, 0, 11, 0, 10, 1, 0, 0, 0, 0, 10, 55, 69, 45, 11, 100, 17, 23, 106 ),
			$symbols
		);
	}
}
