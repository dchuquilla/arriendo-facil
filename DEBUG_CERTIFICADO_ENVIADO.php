<?php
/**
 * Script para ver exactamente qué certificado se está enviando al SRI.
 *
 * Genera una factura de prueba y muestra:
 * 1. El certificado PEM que se recupera de la BD
 * 2. El certificado que se incluye en el XML
 * 3. El certificado que se envía al SRI
 * 4. Comparación con lo esperado
 */

require_once( dirname( __FILE__ ) . '/../../../../wp-load.php' );

if ( ! current_user_can( 'manage_options' ) ) {
	die( 'Solo administradores' );
}

require_once( 'includes/billing/class-sri-config.php' );
require_once( 'includes/billing/class-sri-signer.php' );
require_once( 'includes/billing/class-sri-xml-factura.php' );
require_once( 'includes/billing/class-sri-clave-acceso.php' );

echo '<style>
	body { font-family: monospace; margin: 20px; }
	.section { background: #f5f5f5; padding: 15px; margin: 20px 0; border-left: 4px solid #0073aa; }
	.cert-info { background: #fffacd; padding: 10px; margin: 10px 0; }
	.warning { color: #d32f2f; font-weight: bold; }
	.success { color: #2e7d32; }
	pre { background: white; padding: 10px; overflow-x: auto; border: 1px solid #ccc; max-width: 900px; }
	code { background: #f0f0f0; padding: 2px 5px; }
</style>';

echo '<h1>🔍 Análisis Detallado: Certificado Enviado al SRI</h1>';

// ═══════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<h2>1️⃣ Certificado Recuperado de la BD</h2>';

$pems = Arriendo_Facil_SRI_Config::get_cert_pems();

echo 'Longitud PEM cert: ' . strlen( $pems['cert'] ) . ' bytes<br>';
echo 'Primeros 100 caracteres:<br>';
echo '<pre>' . htmlspecialchars( substr( $pems['cert'], 0, 100 ) ) . '</pre>';

echo 'Últimos 100 caracteres:<br>';
echo '<pre>' . htmlspecialchars( substr( $pems['cert'], -100 ) ) . '</pre>';

// Parsear certificado
$cert_info = openssl_x509_parse( $pems['cert'] );
if ( false === $cert_info ) {
	echo '<span class="warning">❌ ERROR: No se puede parsear el certificado PEM</span><br>';
} else {
	echo '<div class="cert-info">';
	echo '<strong>Información del Certificado:</strong><br>';
	echo 'Sujeto CN: ' . ( $cert_info['subject']['CN'] ?? '?' ) . '<br>';
	echo 'Serial: ' . ( $cert_info['serialNumber'] ?? '?' ) . '<br>';
	echo 'Versión: ' . ( $cert_info['version'] ?? '?' ) . '<br>';
	echo 'Válido desde: ' . wp_date( 'd/m/Y H:i:s', $cert_info['validFrom_time_t'] ?? 0 ) . '<br>';
	echo 'Válido hasta: ' . wp_date( 'd/m/Y H:i:s', $cert_info['validTo_time_t'] ?? 0 ) . '<br>';

	$now = time();
	$valid_to = $cert_info['validTo_time_t'] ?? 0;
	if ( $now > $valid_to ) {
		echo '<span class="warning">❌ CERTIFICADO VENCIDO</span><br>';
	} else {
		echo '<span class="success">✓ Certificado vigente</span><br>';
	}

	echo '</div>';
}

// ═══════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<h2>2️⃣ Conversión a DER y Base64</h2>';

// Convertir a DER (como hace el signer)
$pem_clean = $pems['cert'];
$pem_clean = preg_replace( '/-----[^-]+-----|[\r\n\s]/', '', $pem_clean );
$der = base64_decode( $pem_clean );
$der_b64 = base64_encode( $der );

echo 'DER bytes: ' . strlen( $der ) . '<br>';
echo 'Base64 bytes: ' . strlen( $der_b64 ) . '<br>';
echo '<br>';
echo 'Base64 primeras 200 caracteres:<br>';
echo '<pre>' . htmlspecialchars( substr( $der_b64, 0, 200 ) ) . '</pre>';

echo 'Base64 últimas 100 caracteres:<br>';
echo '<pre>' . htmlspecialchars( substr( $der_b64, -100 ) ) . '</pre>';

// ═══════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<h2>3️⃣ Certificado en Formato PEM Estándar</h2>';

// Reformatear el base64 con saltos de línea cada 76 caracteres (formato PEM)
$pem_formatted = chunk_split( $der_b64, 76, "\n" );
$pem_output = "-----BEGIN CERTIFICATE-----\n" . $pem_formatted . "-----END CERTIFICATE-----\n";

echo 'Longitud PEM formateado: ' . strlen( $pem_output ) . ' bytes<br>';
echo 'Primeras 300 caracteres:<br>';
echo '<pre>' . htmlspecialchars( substr( $pem_output, 0, 300 ) ) . '</pre>';

// Validar que sea igual al original
if ( str_replace( "\r\n", "\n", str_replace( "\r", "\n", $pems['cert'] ) ) === str_replace( "\r\n", "\n", str_replace( "\r", "\n", $pem_output ) ) ) {
	echo '<span class="success">✓ El PEM formateado coincide con el original</span><br>';
} else {
	echo '<span class="warning">❌ El PEM formateado NO coincide con el original</span><br>';
	echo 'Esto podría ser un problema.<br>';
}

// ═══════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<h2>4️⃣ Simulación de Firma (como lo hace la app)</h2>';

try {
	// Crear un XML simple de prueba
	$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
		new DateTime( '2026-06-' . date( 'd' ) ),
		Arriendo_Facil_SRI_Clave_Acceso::TIPO_FACTURA,
		'0912345678001',
		'1', '001', '001', 1
	);

	$totals = Arriendo_Facil_SRI_XML_Factura::compute_totals(
		array(
			array(
				'codigo_principal' => 'ARRIENDO',
				'descripcion'      => 'Test',
				'cantidad'         => 1,
				'precio_unitario'  => 500.00,
			),
		),
		'0'
	);

	$xml_builder = new Arriendo_Facil_SRI_XML_Factura();
	$xml = $xml_builder->build(
		array_merge(
			array(
				'ambiente'              => '1',
				'tipo_emision'          => '1',
				'razon_social'          => 'TEST',
				'ruc'                   => '0912345678001',
				'clave_acceso'          => $clave,
				'estab'                 => '001',
				'pto_emi'               => '001',
				'secuencial'            => '000000001',
				'dir_matriz'            => 'Test',
				'fecha_emision'         => date( 'd/m/Y' ),
				'dir_establecimiento'   => 'Test',
				'obligado_contabilidad' => 'NO',
				'tipo_id_comprador'     => '05',
				'razon_social_comprador'   => 'TEST',
				'identificacion_comprador' => '9999999999999',
				'forma_pago'    => '01',
				'plazo'         => '30',
				'unidad_tiempo' => 'dias',
			),
			$totals
		)
	);

	// Firmar
	$signer = new Arriendo_Facil_SRI_Signer( $pems['cert'], $pems['pkey'], $pems['chain'] ?? '' );
	$signed = $signer->sign( $xml );

	echo '<span class="success">✓ XML firmado exitosamente</span><br>';
	echo 'Tamaño XML firmado: ' . strlen( $signed ) . ' bytes<br>';

	// Extraer el certificado del XML firmado
	$doc = new DOMDocument();
	$doc->loadXML( $signed );
	$xpath = new DOMXPath( $doc );
	$xpath->registerNamespace( 'ds', 'http://www.w3.org/2000/09/xmldsig#' );

	$x509_nodes = $xpath->query( '//ds:X509Certificate' );
	echo 'Certificados en la firma: ' . $x509_nodes->length . '<br>';

	if ( $x509_nodes->length > 0 ) {
		$first_cert_b64 = trim( $x509_nodes->item( 0 )->textContent );
		echo '<br>Primer certificado en XML (primeros 200 chars):<br>';
		echo '<pre>' . htmlspecialchars( substr( $first_cert_b64, 0, 200 ) ) . '</pre>';

		// Comparar
		if ( $first_cert_b64 === $der_b64 ) {
			echo '<span class="success">✓ El certificado en el XML coincide exactamente con el esperado</span><br>';
		} else {
			echo '<span class="warning">❌ El certificado en el XML NO coincide con el esperado</span><br>';

			// Detallar diferencias
			$len1 = strlen( $first_cert_b64 );
			$len2 = strlen( $der_b64 );
			echo "Longitud en XML: $len1 bytes<br>";
			echo "Longitud esperado: $len2 bytes<br>";

			if ( $len1 !== $len2 ) {
				echo '<span class="warning">⚠️ Las longitudes no coinciden! Diferencia: ' . abs( $len1 - $len2 ) . ' bytes</span><br>';
			}
		}
	}

} catch ( Exception $e ) {
	echo '<span class="warning">❌ Error al firmar: ' . esc_html( $e->getMessage() ) . '</span><br>';
}

// ═══════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<h2>5️⃣ Validación de Compatibilidad con SRI</h2>';

// Verificar extensiones requeridas
echo 'Extensiones del certificado:<br>';
$extensions = $cert_info['extensions'] ?? array();
echo 'KeyUsage: ' . ( $extensions['keyUsage'] ?? 'No definido' ) . '<br>';
echo 'ExtendedKeyUsage: ' . ( $extensions['extendedKeyUsage'] ?? 'No definido' ) . '<br>';

// Verificar que tenga Digital Signature
if ( strpos( $extensions['keyUsage'] ?? '', 'Digital Signature' ) !== false ) {
	echo '<span class="success">✓ Digital Signature habilitada</span><br>';
} else {
	echo '<span class="warning">⚠️ Digital Signature NO está habilitada</span><br>';
}

// ═══════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<h2>📋 Resumen y Recomendaciones</h2>';

$issues = array();

if ( strlen( $pems['cert'] ) === 0 ) {
	$issues[] = '❌ Certificado recuperado está vacío';
}
if ( strlen( $pems['pkey'] ) === 0 ) {
	$issues[] = '❌ Clave privada recuperada está vacía';
}
if ( isset( $cert_info['validTo_time_t'] ) && $cert_info['validTo_time_t'] < time() ) {
	$issues[] = '❌ Certificado está vencido';
}
if ( empty( $extensions['keyUsage'] ) || strpos( $extensions['keyUsage'], 'Digital Signature' ) === false ) {
	$issues[] = '⚠️ Certificado no tiene Digital Signature habilitado';
}

if ( empty( $issues ) ) {
	echo '<span class="success">✓ No se encontraron problemas con el certificado</span><br>';
	echo '<br>Si el SRI sigue rechazando, el problema podría ser:<br>';
	echo '<ul>';
	echo '<li>El certificado fue revocado por la AC</li>';
	echo '<li>El SRI no reconoce ese certificado como válido para firmar (AC no autorizada)</li>';
	echo '<li>Los datos del XML (RUC, fechas, etc) son incorrectos</li>';
	echo '<li>El problema está en cómo otros sistemas construyen el XML diferente</li>';
	echo '</ul>';
} else {
	echo '<span class="warning">Se encontraron problemas:</span><br>';
	echo '<ul>';
	foreach ( $issues as $issue ) {
		echo '<li>' . $issue . '</li>';
	}
	echo '</ul>';
}

echo '</div>';

?>
