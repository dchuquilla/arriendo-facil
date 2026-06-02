<?php
/**
 * Unit tests for Fase 3: XAdES-BES signer and SRI SOAP client.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

/**
 * Class SRIPhase3Test
 */
class SRIPhase3Test extends TestCase {

	// ─── Test helpers ────────────────────────────────────────────────────────

	/**
	 * Generates a self-signed RSA test certificate and exports it as P12.
	 * Writes the P12 to a temp file.
	 *
	 * @return array{ path: string, password: string }
	 */
	private function generate_test_p12(): array {
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 1024,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$this->assertNotFalse( $key, 'openssl_pkey_new() must succeed.' );

		$csr = openssl_csr_new(
			array( 'CN' => 'Test SRI', 'O' => 'TestOrg', 'C' => 'EC' ),
			$key,
			array( 'digest_alg' => 'sha256' )
		);
		$this->assertNotFalse( $csr, 'openssl_csr_new() must succeed.' );

		$cert = openssl_csr_sign( $csr, null, $key, 1, array( 'digest_alg' => 'sha256' ), 1 );
		$this->assertNotFalse( $cert, 'openssl_csr_sign() must succeed.' );

		$p12_data = '';
		$ok = openssl_pkcs12_export( $cert, $p12_data, $key, 'testpass1234' );
		$this->assertTrue( $ok, 'openssl_pkcs12_export() must succeed.' );

		$path = sys_get_temp_dir() . '/af_test_cert_' . uniqid() . '.p12';
		file_put_contents( $path, $p12_data );

		return array( 'path' => $path, 'password' => 'testpass1234' );
	}

	/** Returns a minimal but valid unsigned factura XML. */
	private function sample_xml(): string {
		$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
			new DateTime( '2026-06-01' ),
			Arriendo_Facil_SRI_Clave_Acceso::TIPO_FACTURA,
			'0912345678001',
			'1', '001', '001', 1
		);

		$totals = Arriendo_Facil_SRI_XML_Factura::compute_totals(
			array(
				array(
					'codigo_principal' => 'ARRIENDO',
					'descripcion'      => 'Arriendo mes Junio 2026',
					'cantidad'         => 1,
					'precio_unitario'  => 400.00,
				),
			),
			'0'
		);

		return ( new Arriendo_Facil_SRI_XML_Factura() )->build(
			array_merge(
				array(
					'ambiente'              => '1',
					'tipo_emision'          => '1',
					'razon_social'          => 'EMPRESA PRUEBA S.A.',
					'ruc'                   => '0912345678001',
					'clave_acceso'          => $clave,
					'estab'                 => '001',
					'pto_emi'               => '001',
					'secuencial'            => '000000001',
					'dir_matriz'            => 'Quito',
					'fecha_emision'         => '01/06/2026',
					'dir_establecimiento'   => 'Quito',
					'obligado_contabilidad' => 'NO',
					'tipo_id_comprador'     => '05',
					'razon_social_comprador'   => 'INQUILINO TEST',
					'identificacion_comprador' => '1712345678',
					'forma_pago'    => '01',
					'plazo'         => '30',
					'unidad_tiempo' => 'dias',
				),
				$totals
			)
		);
	}

	// ─── Arriendo_Facil_SRI_Signer ───────────────────────────────────────────

	public function test_signer_throws_when_p12_not_found() {
		$this->expectException( RuntimeException::class );
		( new Arriendo_Facil_SRI_Signer( '/nonexistent/cert.p12', 'pass' ) )->sign( '<x/>' );
	}

	public function test_signer_throws_on_wrong_password() {
		$cert = $this->generate_test_p12();
		try {
			$this->expectException( RuntimeException::class );
			( new Arriendo_Facil_SRI_Signer( $cert['path'], 'WRONG_PASSWORD' ) )->sign( $this->sample_xml() );
		} finally {
			@unlink( $cert['path'] );
		}
	}

	public function test_signed_xml_is_well_formed() {
		$cert   = $this->generate_test_p12();
		$signer = new Arriendo_Facil_SRI_Signer( $cert['path'], $cert['password'] );
		$signed = $signer->sign( $this->sample_xml() );
		@unlink( $cert['path'] );

		$dom    = new DOMDocument();
		libxml_use_internal_errors( true );
		$ok     = $dom->loadXML( $signed );
		libxml_clear_errors();

		$this->assertTrue( $ok, 'Signed XML must be well-formed.' );
	}

	public function test_signed_xml_has_signature_element() {
		$cert   = $this->generate_test_p12();
		$signer = new Arriendo_Facil_SRI_Signer( $cert['path'], $cert['password'] );
		$signed = $signer->sign( $this->sample_xml() );
		@unlink( $cert['path'] );

		$dom = new DOMDocument();
		$dom->loadXML( $signed );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'ds', Arriendo_Facil_SRI_Signer::XMLDSIG_NS );

		$sig_nodes = $xpath->query( '//ds:Signature' );
		$this->assertSame( 1, $sig_nodes->length, 'Exactly one <Signature> element must be present.' );
	}

	public function test_signed_xml_signature_is_last_child_of_root() {
		$cert   = $this->generate_test_p12();
		$signer = new Arriendo_Facil_SRI_Signer( $cert['path'], $cert['password'] );
		$signed = $signer->sign( $this->sample_xml() );
		@unlink( $cert['path'] );

		$dom  = new DOMDocument();
		$dom->loadXML( $signed );
		$last = $dom->documentElement->lastChild;
		// Allow for text/whitespace nodes.
		while ( $last && XML_TEXT_NODE === $last->nodeType ) {
			$last = $last->previousSibling;
		}
		$this->assertNotNull( $last );
		$this->assertSame( 'Signature', $last->localName );
	}

	public function test_signed_xml_contains_key_info_with_certificate() {
		$cert   = $this->generate_test_p12();
		$signer = new Arriendo_Facil_SRI_Signer( $cert['path'], $cert['password'] );
		$signed = $signer->sign( $this->sample_xml() );
		@unlink( $cert['path'] );

		$dom = new DOMDocument();
		$dom->loadXML( $signed );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'ds', Arriendo_Facil_SRI_Signer::XMLDSIG_NS );

		$x509 = $xpath->query( '//ds:X509Certificate' );
		$this->assertSame( 1, $x509->length );
		$this->assertNotEmpty( trim( $x509->item( 0 )->textContent ) );
	}

	public function test_signed_xml_contains_xades_signed_properties() {
		$cert   = $this->generate_test_p12();
		$signer = new Arriendo_Facil_SRI_Signer( $cert['path'], $cert['password'] );
		$signed = $signer->sign( $this->sample_xml() );
		@unlink( $cert['path'] );

		$dom = new DOMDocument();
		$dom->loadXML( $signed );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'xades', Arriendo_Facil_SRI_Signer::XADES_NS );

		$sp = $xpath->query( '//*[@Id="Signature-XAdES-SignedProperties"]' );
		$this->assertSame( 1, $sp->length, 'Must contain <SignedProperties> with correct Id.' );

		$st = $xpath->query( '//*[local-name()="SigningTime"]' );
		$this->assertSame( 1, $st->length );
		$this->assertNotEmpty( trim( $st->item( 0 )->textContent ) );
	}

	public function test_digest_values_are_non_empty() {
		$cert   = $this->generate_test_p12();
		$signer = new Arriendo_Facil_SRI_Signer( $cert['path'], $cert['password'] );
		$signed = $signer->sign( $this->sample_xml() );
		@unlink( $cert['path'] );

		$dom = new DOMDocument();
		$dom->loadXML( $signed );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'ds', Arriendo_Facil_SRI_Signer::XMLDSIG_NS );

		$digest_values = $xpath->query( '//ds:DigestValue' );
		$this->assertGreaterThan( 0, $digest_values->length );

		// The three Reference digest values (not the cert digest inside XAdES) must be non-empty.
		$ref_digests = $xpath->query( '//ds:SignedInfo//ds:DigestValue' );
		foreach ( $ref_digests as $dv ) {
			$this->assertNotEmpty( trim( $dv->textContent ), 'All Reference DigestValues must be filled.' );
		}
	}

	/**
	 * The most important test: verifies the RSA-SHA1 signature cryptographically.
	 */
	public function test_signature_verifies_with_openssl() {
		$cert   = $this->generate_test_p12();
		$signer = new Arriendo_Facil_SRI_Signer( $cert['path'], $cert['password'] );
		$signed = $signer->sign( $this->sample_xml() );
		@unlink( $cert['path'] );

		$dom = new DOMDocument();
		$dom->loadXML( $signed );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'ds', Arriendo_Facil_SRI_Signer::XMLDSIG_NS );

		// Extract SignatureValue bytes.
		$sv_node = $xpath->query( '//ds:SignatureValue' )->item( 0 );
		$this->assertNotNull( $sv_node, 'SignatureValue must exist.' );
		$sig_bytes = base64_decode( trim( $sv_node->textContent ) );
		$this->assertNotEmpty( $sig_bytes );

		// Compute SignedInfo C14N from the signed document.
		$si_node = $xpath->query( '//ds:SignedInfo' )->item( 0 );
		$this->assertNotNull( $si_node );
		$si_c14n = $si_node->C14N( false, false );

		// Extract the public key from the embedded X509Certificate.
		$x509_node = $xpath->query( '//ds:X509Certificate' )->item( 0 );
		$this->assertNotNull( $x509_node );
		$cert_b64 = preg_replace( '/\s+/', '', $x509_node->textContent );
		$cert_pem = "-----BEGIN CERTIFICATE-----\n"
			. chunk_split( $cert_b64, 64, "\n" )
			. "-----END CERTIFICATE-----\n";

		$pub_key = openssl_pkey_get_public( $cert_pem );
		$this->assertNotFalse( $pub_key, 'Must be able to extract public key from embedded cert.' );

		$result = openssl_verify( $si_c14n, $sig_bytes, $pub_key, OPENSSL_ALGO_SHA1 );
		$this->assertSame( 1, $result, 'RSA-SHA1 signature over C14N(SignedInfo) must verify successfully.' );
	}

	public function test_signing_preserves_original_xml_content() {
		$xml    = $this->sample_xml();
		$cert   = $this->generate_test_p12();
		$signed = ( new Arriendo_Facil_SRI_Signer( $cert['path'], $cert['password'] ) )->sign( $xml );
		@unlink( $cert['path'] );

		$dom  = new DOMDocument();
		$dom->loadXML( $signed );
		$xpath = new DOMXPath( $dom );

		// Core business fields must be intact.
		$ruc   = $xpath->evaluate( 'string(//infoTributaria/ruc)' );
		$total = $xpath->evaluate( 'string(//infoFactura/importeTotal)' );
		$this->assertSame( '0912345678001', $ruc );
		$this->assertSame( '400.00', $total );
	}

	// ─── Arriendo_Facil_SRI_Soap_Client ─────────────────────────────────────

	public function test_soap_client_urls_for_pruebas() {
		$client = new Arriendo_Facil_SRI_Soap_Client( '1' );
		$this->assertStringContainsString( 'celcer.sri.gob.ec',          $client->recepcion_url() );
		$this->assertStringContainsString( 'RecepcionComprobantesOffline', $client->recepcion_url() );
		$this->assertStringContainsString( 'AutorizacionComprobantesOffline', $client->autorizacion_url() );
	}

	public function test_soap_client_urls_for_produccion() {
		$client = new Arriendo_Facil_SRI_Soap_Client( '2' );
		$this->assertStringContainsString( 'cel.sri.gob.ec',             $client->recepcion_url() );
		$this->assertStringNotContainsString( 'celcer.sri.gob.ec',       $client->recepcion_url() );
	}

	public function test_build_recepcion_envelope_contains_base64_xml() {
		$client = new Arriendo_Facil_SRI_Soap_Client( '1' );
		$xml    = '<factura id="comprobante"><test>data</test></factura>';
		$env    = $client->build_recepcion_envelope( $xml );

		$this->assertStringContainsString( 'validarComprobante', $env );
		$this->assertStringContainsString( base64_encode( $xml ), $env );
	}

	public function test_build_autorizacion_envelope_contains_clave() {
		$client = new Arriendo_Facil_SRI_Soap_Client( '1' );
		$clave  = str_repeat( '0', 49 );
		$env    = $client->build_autorizacion_envelope( $clave );

		$this->assertStringContainsString( 'autorizacionComprobante', $env );
		$this->assertStringContainsString( $clave,                    $env );
	}

	public function test_enviar_returns_wp_error_when_http_fails() {
		// bootstrap.php stubs wp_remote_post to always return WP_Error.
		$client = new Arriendo_Facil_SRI_Soap_Client( '1' );
		$result = $client->enviar( '<factura/>' );
		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_autorizar_returns_wp_error_when_http_fails() {
		$client = new Arriendo_Facil_SRI_Soap_Client( '1' );
		$result = $client->autorizar( str_repeat( '1', 49 ) );
		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_parse_recepcion_response_recibida() {
		$client = new Arriendo_Facil_SRI_Soap_Client( '1' );
		$mock   = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <ns2:validarComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.recepcion">
      <RespuestaRecepcionComprobante>
        <estado>RECIBIDA</estado>
        <comprobantes>
          <comprobante>
            <claveAcceso>0000000000000000000000000000000000000000000000001</claveAcceso>
            <mensajes/>
          </comprobante>
        </comprobantes>
      </RespuestaRecepcionComprobante>
    </ns2:validarComprobanteResponse>
  </soap:Body>
</soap:Envelope>
XML;
		$result = $client->parse_recepcion_response( $mock );
		$this->assertIsArray( $result );
		$this->assertSame( 'RECIBIDA', $result['estado'] );
		$this->assertIsArray( $result['mensajes'] );
	}

	public function test_parse_recepcion_response_devuelta_with_error() {
		$client = new Arriendo_Facil_SRI_Soap_Client( '1' );
		$mock   = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <RespuestaRecepcionComprobante>
      <estado>DEVUELTA</estado>
      <comprobantes>
        <comprobante>
          <claveAcceso>0000000000000000000000000000000000000000000000001</claveAcceso>
          <mensajes>
            <mensaje>
              <identificador>43</identificador>
              <mensaje>CLAVE DE ACCESO REGISTRADA</mensaje>
              <tipo>ERROR</tipo>
            </mensaje>
          </mensajes>
        </comprobante>
      </comprobantes>
    </RespuestaRecepcionComprobante>
  </soap:Body>
</soap:Envelope>
XML;
		$result = $client->parse_recepcion_response( $mock );
		$this->assertSame( 'DEVUELTA', $result['estado'] );
		$this->assertCount( 1, $result['mensajes'] );
		$this->assertSame( '43',    $result['mensajes'][0]['identificador'] );
		$this->assertSame( 'ERROR', $result['mensajes'][0]['tipo'] );
	}

	public function test_parse_autorizacion_response_autorizado() {
		$client = new Arriendo_Facil_SRI_Soap_Client( '1' );
		$mock   = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <RespuestaAutorizacionComprobante>
      <claveAccesoConsultada>0000000000000000000000000000000000000000000000001</claveAccesoConsultada>
      <numeroAutorizaciones>1</numeroAutorizaciones>
      <autorizaciones>
        <autorizacion>
          <estado>AUTORIZADO</estado>
          <numeroAutorizacion>0000000000000000000000000000000000000000000000001</numeroAutorizacion>
          <fechaAutorizacion>2026-06-01T12:00:00.000-05:00</fechaAutorizacion>
          <ambiente>PRUEBAS</ambiente>
          <comprobante><![CDATA[<?xml version="1.0"?><factura/>]]></comprobante>
          <mensajes/>
        </autorizacion>
      </autorizaciones>
    </RespuestaAutorizacionComprobante>
  </soap:Body>
</soap:Envelope>
XML;
		$result = $client->parse_autorizacion_response( $mock );
		$this->assertIsArray( $result );
		$this->assertSame( 'AUTORIZADO', $result['estado'] );
		$this->assertNotEmpty( $result['numero_autorizacion'] );
		$this->assertSame( '2026-06-01T12:00:00.000-05:00', $result['fecha_autorizacion'] );
		$this->assertSame( 'PRUEBAS', $result['ambiente'] );
		$this->assertStringContainsString( '<factura/>', $result['xml_autorizacion'] );
		$this->assertIsArray( $result['mensajes'] );
	}

	public function test_parse_autorizacion_response_returns_error_if_no_autorizacion_node() {
		$client = new Arriendo_Facil_SRI_Soap_Client( '1' );
		$mock   = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><Empty/></soap:Body></soap:Envelope>';
		$result = $client->parse_autorizacion_response( $mock );
		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_parse_recepcion_response_returns_error_on_empty_input() {
		$client = new Arriendo_Facil_SRI_Soap_Client( '1' );
		$result = $client->parse_recepcion_response( '' );
		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_parse_recepcion_response_returns_error_on_invalid_xml() {
		$client = new Arriendo_Facil_SRI_Soap_Client( '1' );
		$result = $client->parse_recepcion_response( 'NOT XML AT ALL <<<' );
		$this->assertTrue( is_wp_error( $result ) );
	}
}
