<?php
/**
 * Verificación criptográfica local de la firma XAdES-BES del SRI.
 *
 * Ejecuta las mismas comprobaciones que haría el validador del SRI:
 *   1. DigestValue del comprobante  (#comprobante)
 *   2. DigestValue del KeyInfo      (#Certificate1)
 *   3. DigestValue de SignedProperties (#Signature-XAdES-SignedProperties)
 *   4. Firma RSA-SHA256 sobre SignedInfo
 *
 * Cada verificación es independiente: si una falla se ve exactamente cuál
 * parámetro está mal sin necesidad de herramientas externas.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

class SRISignatureVerifyTest extends TestCase {

	// ── Constantes de namespace ──────────────────────────────────────────────

	const DS   = 'http://www.w3.org/2000/09/xmldsig#';
	const ETSI = 'http://uri.etsi.org/01903/v1.3.2#';

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Genera un certificado autofirmado real con clave RSA 2048 bits.
	 * Se usa 2048 en lugar de 1024 para que la clave tenga el mismo tamaño
	 * que los certificados reales del SRI (Uanataca / Security Data).
	 *
	 * @return array{ cert_pem: string, pkey_pem: string }
	 */
	private function make_cert(): array {
		$key = openssl_pkey_new( array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		) );
		$this->assertNotFalse( $key, 'openssl_pkey_new falló' );

		$csr = openssl_csr_new(
			array( 'CN' => 'Test SRI Verify', 'O' => 'Arriendo Facil', 'C' => 'EC' ),
			$key,
			array( 'digest_alg' => 'sha256' )
		);
		$this->assertNotFalse( $csr, 'openssl_csr_new falló' );

		$cert = openssl_csr_sign( $csr, null, $key, 365, array( 'digest_alg' => 'sha256' ), 1 );
		$this->assertNotFalse( $cert, 'openssl_csr_sign falló' );

		$cert_pem = '';
		openssl_x509_export( $cert, $cert_pem );
		$pkey_pem = '';
		openssl_pkey_export( $key, $pkey_pem );

		return array( 'cert_pem' => $cert_pem, 'pkey_pem' => $pkey_pem );
	}

	/** XML de factura sin firmar, idéntico al que genera el plugin en producción. */
	private function make_unsigned_xml(): string {
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
					'descripcion'      => 'Arriendo mensual Junio 2026',
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
					'razon_social_comprador'    => 'INQUILINO TEST',
					'identificacion_comprador'  => '1712345678',
					'forma_pago'    => '01',
					'plazo'         => '30',
					'unidad_tiempo' => 'dias',
				),
				$totals
			)
		);
	}

	/**
	 * Construye un DOMXPath con los namespaces ds: y etsi: registrados.
	 */
	private function xpath_for( DOMDocument $doc ): DOMXPath {
		$xp = new DOMXPath( $doc );
		$xp->registerNamespace( 'ds',   self::DS );
		$xp->registerNamespace( 'etsi', self::ETSI );
		return $xp;
	}

	/**
	 * Lee el contenido de texto de un nodo encontrado por XPath.
	 * Lanza AssertionError si no se encuentra el nodo.
	 */
	private function xtext( DOMXPath $xp, string $query ): string {
		$node = $xp->query( $query )->item( 0 );
		$this->assertNotNull( $node, "Nodo no encontrado: $query" );
		return trim( (string) $node->textContent );
	}

	// ── Tests de verificación ────────────────────────────────────────────────

	/**
	 * TEST 1 — El XML firmado tiene todos los nodos obligatorios.
	 * Si falta alguno, los tests de digest fallarían con mensajes confusos;
	 * este test detecta la causa raíz antes.
	 */
	public function test_01_estructura_firma_completa(): void {
		$cert = $this->make_cert();
		$xml  = ( new Arriendo_Facil_SRI_Signer( $cert['cert_pem'], $cert['pkey_pem'] ) )
			->sign( $this->make_unsigned_xml() );

		$doc = new DOMDocument();
		$doc->loadXML( $xml );
		$xp = $this->xpath_for( $doc );

		$nodos_obligatorios = array(
			'ds:Signature'                                              => '//ds:Signature',
			'ds:SignedInfo'                                             => '//ds:SignedInfo',
			'ds:SignatureValue'                                         => '//ds:SignatureValue[@Id="Signature-SignatureValue"]',
			'ds:KeyInfo'                                                => '//ds:KeyInfo[@Id="Certificate1"]',
			'ds:X509Certificate (entidad)'                             => '//ds:KeyInfo/ds:X509Data/ds:X509Certificate',
			'ds:X509IssuerSerial dentro de X509Data'                   => '//ds:KeyInfo/ds:X509Data/ds:X509IssuerSerial',
			'etsi:SignedProperties'                                     => '//etsi:SignedProperties[@Id="Signature-XAdES-SignedProperties"]',
			'etsi:SigningTime'                                          => '//etsi:SigningTime',
			'etsi:IssuerSerial'                                        => '//etsi:IssuerSerial',
			'Reference #comprobante'                                   => '//ds:Reference[@Id="comprobante-ref0"]',
			'Reference #Certificate1'                                   => '//ds:Reference[@URI="#Certificate1"]',
			'Reference #SignedProperties'                               => '//ds:Reference[@URI="#Signature-XAdES-SignedProperties"]',
			'ds:CanonicalizationMethod'                                 => '//ds:CanonicalizationMethod',
			'ds:SignatureMethod RSA-SHA256'                             => '//ds:SignatureMethod[@Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"]',
		);

		foreach ( $nodos_obligatorios as $desc => $query ) {
			$this->assertNotNull(
				$xp->query( $query )->item( 0 ),
				"Falta nodo obligatorio: $desc  [$query]"
			);
		}
	}

	/**
	 * TEST 2 — DigestValue del comprobante (#comprobante).
	 *
	 * El SRI aplica la transformación enveloped-signature (elimina <ds:Signature>)
	 * y luego C14N al elemento raíz. Verificamos que nuestro digest coincide.
	 */
	public function test_02_digest_comprobante(): void {
		$cert       = $this->make_cert();
		$signed_xml = ( new Arriendo_Facil_SRI_Signer( $cert['cert_pem'], $cert['pkey_pem'] ) )
			->sign( $this->make_unsigned_xml() );

		// Parsear el XML firmado en una copia para modificarla sin destruirla.
		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		$doc->loadXML( $signed_xml );
		$xp = $this->xpath_for( $doc );

		// Leer el digest almacenado.
		$stored = $this->xtext( $xp, '//ds:Reference[@Id="comprobante-ref0"]/ds:DigestValue' );

		// Computar el digest esperado: enveloped-signature + C14N.
		$doc_verify = new DOMDocument();
		$doc_verify->preserveWhiteSpace = false;
		$doc_verify->loadXML( $signed_xml );
		$xp_verify = $this->xpath_for( $doc_verify );

		$root      = $doc_verify->documentElement;
		$sig_node  = $xp_verify->query( '//ds:Signature' )->item( 0 );
		$this->assertNotNull( $sig_node, 'No se encontró ds:Signature para eliminar en transform' );
		$root->removeChild( $sig_node );  // enveloped-signature transform
		$c14n     = $root->C14N( false, false );
		$expected = base64_encode( hash( 'sha256', $c14n, true ) );

		$this->assertSame(
			$expected,
			$stored,
			"DigestValue del comprobante NO coincide.\n" .
			"  Esperado : $expected\n" .
			"  Guardado : $stored\n" .
			"  → el SRI rechazará con código 39 FIRMA INVALIDA en la referencia al comprobante."
		);
	}

	/**
	 * TEST 3 — DigestValue del KeyInfo (#Certificate1).
	 *
	 * El SRI aplica C14N al nodo <ds:KeyInfo> y verifica el digest.
	 * Este test detecta si el namespace xmlns:ds está correctamente en scope
	 * al momento de computar el digest.
	 */
	public function test_03_digest_keyinfo(): void {
		$cert       = $this->make_cert();
		$signed_xml = ( new Arriendo_Facil_SRI_Signer( $cert['cert_pem'], $cert['pkey_pem'] ) )
			->sign( $this->make_unsigned_xml() );

		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		$doc->loadXML( $signed_xml );
		$xp = $this->xpath_for( $doc );

		$stored  = $this->xtext( $xp, '//ds:Reference[@URI="#Certificate1"]/ds:DigestValue' );
		$ki_node = $xp->query( '//ds:KeyInfo[@Id="Certificate1"]' )->item( 0 );
		$this->assertNotNull( $ki_node, 'No se encontró ds:KeyInfo[@Id="Certificate1"]' );

		$expected = base64_encode( hash( 'sha256', $ki_node->C14N( false, false ), true ) );

		$this->assertSame(
			$expected,
			$stored,
			"DigestValue del KeyInfo NO coincide.\n" .
			"  Esperado : $expected\n" .
			"  Guardado : $stored\n" .
			"  → posible bug de namespace xmlns:ds en C14N del KeyInfo."
		);
	}

	/**
	 * TEST 4 — DigestValue de SignedProperties (#Signature-XAdES-SignedProperties).
	 *
	 * Este es el digest que con más frecuencia falla en implementaciones PHP
	 * por el bug de DOMNode::C14N() con namespaces declarados via setAttributeNS.
	 * El fix double-parse debe garantizar que xmlns:etsi está en scope.
	 */
	public function test_04_digest_signed_properties(): void {
		$cert       = $this->make_cert();
		$signed_xml = ( new Arriendo_Facil_SRI_Signer( $cert['cert_pem'], $cert['pkey_pem'] ) )
			->sign( $this->make_unsigned_xml() );

		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		$doc->loadXML( $signed_xml );
		$xp = $this->xpath_for( $doc );

		$stored  = $this->xtext( $xp, '//ds:Reference[@URI="#Signature-XAdES-SignedProperties"]/ds:DigestValue' );
		$sp_node = $xp->query( '//etsi:SignedProperties[@Id="Signature-XAdES-SignedProperties"]' )->item( 0 );
		$this->assertNotNull( $sp_node, 'No se encontró etsi:SignedProperties' );

		$sp_c14n  = $sp_node->C14N( false, false );
		$expected = base64_encode( hash( 'sha256', $sp_c14n, true ) );

		// Diagnóstico extra: mostrar si xmlns:etsi está presente en el C14N.
		$has_etsi_ns = strpos( $sp_c14n, 'xmlns:etsi=' ) !== false;
		$has_ds_ns   = strpos( $sp_c14n, 'xmlns:ds=' ) !== false;

		$this->assertSame(
			$expected,
			$stored,
			"DigestValue de SignedProperties NO coincide.\n" .
			"  Esperado : $expected\n" .
			"  Guardado : $stored\n" .
			"  xmlns:etsi en C14N: " . ( $has_etsi_ns ? 'SÍ ✓' : 'NO ✗ ← causa probable' ) . "\n" .
			"  xmlns:ds  en C14N: " . ( $has_ds_ns  ? 'SÍ ✓' : 'NO ✗' ) . "\n" .
			"  → el fix double-parse debe resolver esto."
		);

		// Adicionalmente verifica que el C14N incluye ambos namespaces en scope.
		$this->assertTrue(
			$has_etsi_ns,
			"El C14N de <etsi:SignedProperties> NO incluye xmlns:etsi — " .
			"el SRI computará un digest diferente. Bug de namespace PHP no resuelto."
		);
		$this->assertTrue(
			$has_ds_ns,
			"El C14N de <etsi:SignedProperties> NO incluye xmlns:ds — " .
			"debería estar en scope desde el ancestro <ds:Signature>."
		);
	}

	/**
	 * TEST 5 — Firma RSA-SHA256 sobre SignedInfo.
	 *
	 * Verifica que el valor en <ds:SignatureValue> es la firma correcta del
	 * C14N de <ds:SignedInfo> con la clave privada del certificado.
	 * Si este test falla, el problema está en openssl_sign o en los DigestValues
	 * (que alteran el contenido de SignedInfo antes de firmar).
	 */
	public function test_05_rsa_sha256_sobre_signed_info(): void {
		$cert       = $this->make_cert();
		$signed_xml = ( new Arriendo_Facil_SRI_Signer( $cert['cert_pem'], $cert['pkey_pem'] ) )
			->sign( $this->make_unsigned_xml() );

		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		$doc->loadXML( $signed_xml );
		$xp = $this->xpath_for( $doc );

		$si_node  = $xp->query( '//ds:SignedInfo[@Id="Signature-SignedInfo"]' )->item( 0 );
		$this->assertNotNull( $si_node, 'No se encontró ds:SignedInfo' );

		$si_c14n  = $si_node->C14N( false, false );
		$sig_b64  = $this->xtext( $xp, '//ds:SignatureValue[@Id="Signature-SignatureValue"]' );
		$sig_raw  = base64_decode( $sig_b64 );

		$pub_key = openssl_pkey_get_public( $cert['cert_pem'] );
		$this->assertNotFalse( $pub_key, 'No se pudo obtener la clave pública del certificado' );

		$result = openssl_verify( $si_c14n, $sig_raw, $pub_key, OPENSSL_ALGO_SHA256 );

		$this->assertSame(
			1,
			$result,
			"Firma RSA-SHA256 sobre SignedInfo INVÁLIDA (openssl_verify devolvió $result).\n" .
			"  Si los tests 2-4 pasan pero este falla → problema en openssl_sign.\n" .
			"  Si los tests 2-4 también fallan → los DigestValues incorrectos corrompen el SignedInfo."
		);
	}

	/**
	 * TEST 6 — SigningTime usa timezone de Ecuador (-05:00).
	 *
	 * El SRI rechaza comprobantes con hora UTC (+00:00) porque el timestamp
	 * debe estar en la zona horaria del emisor (America/Guayaquil = -05:00).
	 */
	public function test_06_signing_time_timezone_ecuador(): void {
		$cert       = $this->make_cert();
		$signed_xml = ( new Arriendo_Facil_SRI_Signer( $cert['cert_pem'], $cert['pkey_pem'] ) )
			->sign( $this->make_unsigned_xml() );

		$doc = new DOMDocument();
		$doc->loadXML( $signed_xml );
		$xp = $this->xpath_for( $doc );

		$signing_time = $this->xtext( $xp, '//etsi:SigningTime' );

		$this->assertStringContainsString(
			'-05:00',
			$signing_time,
			"SigningTime '$signing_time' no usa timezone de Ecuador (-05:00).\n" .
			"  Si muestra +00:00 → el servidor PHP está en UTC y DateTimeZone('America/Guayaquil') no se aplica."
		);
	}

	/**
	 * TEST 7 — ds:X509IssuerSerial está dentro de ds:X509Data (requerido por SRI).
	 *
	 * El SRI error "El certificado firmante no es válido" ocurre cuando
	 * X509IssuerSerial falta dentro de X509Data en KeyInfo.
	 */
	public function test_07_x509_issuer_serial_dentro_de_x509data(): void {
		$cert       = $this->make_cert();
		$signed_xml = ( new Arriendo_Facil_SRI_Signer( $cert['cert_pem'], $cert['pkey_pem'] ) )
			->sign( $this->make_unsigned_xml() );

		$doc = new DOMDocument();
		$doc->loadXML( $signed_xml );
		$xp = $this->xpath_for( $doc );

		$issuer_in_x509data = $xp->query( '//ds:KeyInfo/ds:X509Data/ds:X509IssuerSerial' )->item( 0 );
		$this->assertNotNull(
			$issuer_in_x509data,
			'ds:X509IssuerSerial NO está dentro de ds:X509Data en ds:KeyInfo — ' .
			'el SRI rechazará con "El certificado firmante no es válido".'
		);

		// Verifica sub-elementos.
		$this->assertNotNull(
			$xp->query( '//ds:X509IssuerSerial/ds:X509IssuerName' )->item( 0 ),
			'Falta ds:X509IssuerName dentro de ds:X509IssuerSerial'
		);
		$this->assertNotNull(
			$xp->query( '//ds:X509IssuerSerial/ds:X509SerialNumber' )->item( 0 ),
			'Falta ds:X509SerialNumber dentro de ds:X509IssuerSerial'
		);
	}

	/**
	 * TEST 8 — Verificación completa de extremo a extremo.
	 *
	 * Ejecuta los 4 digest + la firma RSA en secuencia y reporta
	 * cuántos pasaron. Útil para ver el panorama completo de una vez.
	 */
	public function test_08_verificacion_completa_e2e(): void {
		$cert       = $this->make_cert();
		$unsigned   = $this->make_unsigned_xml();
		$signed_xml = ( new Arriendo_Facil_SRI_Signer( $cert['cert_pem'], $cert['pkey_pem'] ) )
			->sign( $unsigned );

		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		$doc->loadXML( $signed_xml );
		$xp = $this->xpath_for( $doc );

		$errores = array();

		// ── Digest #comprobante ──────────────────────────────────────────────
		$doc_env = new DOMDocument();
		$doc_env->preserveWhiteSpace = false;
		$doc_env->loadXML( $signed_xml );
		$xp_env  = $this->xpath_for( $doc_env );
		$root    = $doc_env->documentElement;
		$sig     = $xp_env->query( '//ds:Signature' )->item( 0 );
		$root->removeChild( $sig );
		$digest_comprobante_expected = base64_encode( hash( 'sha256', $root->C14N( false, false ), true ) );
		$digest_comprobante_stored   = $this->xtext( $xp, '//ds:Reference[@Id="comprobante-ref0"]/ds:DigestValue' );
		if ( $digest_comprobante_expected !== $digest_comprobante_stored ) {
			$errores[] = '❌ DigestValue #comprobante NO coincide';
		} else {
			echo "  ✓ DigestValue #comprobante correcto\n";
		}

		// ── Digest #Certificate1 ─────────────────────────────────────────────
		$ki = $xp->query( '//ds:KeyInfo[@Id="Certificate1"]' )->item( 0 );
		$digest_ki_expected = base64_encode( hash( 'sha256', $ki->C14N( false, false ), true ) );
		$digest_ki_stored   = $this->xtext( $xp, '//ds:Reference[@URI="#Certificate1"]/ds:DigestValue' );
		if ( $digest_ki_expected !== $digest_ki_stored ) {
			$errores[] = '❌ DigestValue #Certificate1 (KeyInfo) NO coincide';
		} else {
			echo "  ✓ DigestValue #Certificate1 correcto\n";
		}

		// ── Digest SignedProperties ──────────────────────────────────────────
		$sp = $xp->query( '//etsi:SignedProperties[@Id="Signature-XAdES-SignedProperties"]' )->item( 0 );
		$sp_c14n = $sp->C14N( false, false );
		$digest_sp_expected = base64_encode( hash( 'sha256', $sp_c14n, true ) );
		$digest_sp_stored   = $this->xtext( $xp, '//ds:Reference[@URI="#Signature-XAdES-SignedProperties"]/ds:DigestValue' );
		$has_etsi = strpos( $sp_c14n, 'xmlns:etsi=' ) !== false;
		if ( $digest_sp_expected !== $digest_sp_stored ) {
			$errores[] = '❌ DigestValue SignedProperties NO coincide' .
				( ! $has_etsi ? ' (xmlns:etsi ausente del C14N ← causa probable)' : '' );
		} else {
			echo "  ✓ DigestValue SignedProperties correcto (xmlns:etsi en scope: " . ( $has_etsi ? 'SÍ' : 'NO' ) . ")\n";
		}

		// ── Firma RSA-SHA256 ─────────────────────────────────────────────────
		$si      = $xp->query( '//ds:SignedInfo[@Id="Signature-SignedInfo"]' )->item( 0 );
		$sig_raw = base64_decode( $this->xtext( $xp, '//ds:SignatureValue' ) );
		$pub_key = openssl_pkey_get_public( $cert['cert_pem'] );
		$rsa_ok  = openssl_verify( $si->C14N( false, false ), $sig_raw, $pub_key, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $rsa_ok ) {
			$errores[] = "❌ Firma RSA-SHA256 inválida (openssl_verify = $rsa_ok)";
		} else {
			echo "  ✓ Firma RSA-SHA256 válida\n";
		}

		// ── Resultado ────────────────────────────────────────────────────────
		$this->assertEmpty(
			$errores,
			"\nVERIFICACIÓN FALLIDA — errores encontrados:\n" .
			implode( "\n", $errores ) . "\n\n" .
			"Si todos los tests de arriba pasan con certificado de prueba pero\n" .
			"el SRI rechaza el XML real, el problema está en el certificado\n" .
			"de producción (cadena CA no reconocida por el SRI)."
		);
	}
}
