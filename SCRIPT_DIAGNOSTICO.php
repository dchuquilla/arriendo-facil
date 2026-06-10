<?php
/**
 * Script de diagnóstico para problemas de firma digital en SRI.
 *
 * USO:
 * 1. Copia este archivo al raíz del plugin
 * 2. En el navegador, ve a: /wp-content/plugins/arriendo-facil/SCRIPT_DIAGNOSTICO.php
 * 3. Verifica cada sección del diagnóstico
 *
 * IMPORTANTE: Borra este archivo después de usar - contiene información sensible del certificado
 */

// Seguridad básica
if ( ! isset( $_GET['debug_key'] ) || 'CAMBIAR_ESTO_POR_UNA_CLAVE_SEGURA' === $_GET['debug_key'] ) {
	die( 'Acceso denegado. Añade ?debug_key=CAMBIAR_ESTO_POR_UNA_CLAVE_SEGURA a la URL después de cambiar la clave.' );
}

// Cargar WordPress
require_once( dirname( __FILE__ ) . '/../../../../wp-load.php' );

// Verificar permisos
if ( ! current_user_can( 'manage_options' ) ) {
	die( 'Solo administradores pueden ejecutar este diagnóstico.' );
}

// Estilos CSS
echo '<style>
	body { font-family: monospace; margin: 20px; background: #f5f5f5; }
	.section { background: white; margin: 20px 0; padding: 20px; border-radius: 5px; border-left: 4px solid #0073aa; }
	.title { font-size: 18px; font-weight: bold; margin-bottom: 10px; }
	.success { color: #2e7d32; font-weight: bold; }
	.error { color: #c62828; font-weight: bold; }
	.warning { color: #f57f17; font-weight: bold; }
	.info { color: #1976d2; }
	pre { background: #f0f0f0; padding: 10px; overflow-x: auto; }
	code { background: #f0f0f0; padding: 2px 5px; }
</style>';

echo '<h1>🔍 Diagnóstico de Firma Digital SRI</h1>';

// ─── SECCIÓN 1: Estado Básico ────────────────────────────────────────────────

echo '<div class="section">';
echo '<div class="title">1️⃣ Estado Básico de Configuración</div>';

require_once( 'includes/billing/class-sri-config.php' );
require_once( 'includes/billing/class-sri-signer.php' );

$config = Arriendo_Facil_SRI_Config::get();
$pems = Arriendo_Facil_SRI_Config::get_cert_pems();

echo 'RUC Configurado: ' . ( ! empty( $config['ruc'] ) ? '<span class="success">✓ ' . esc_html( $config['ruc'] ) . '</span>' : '<span class="error">✗ No configurado</span>' ) . '<br>';
echo 'Ambiente: ' . ( '1' === $config['ambiente'] ? '<span class="info">Pruebas</span>' : '<span class="warning">Producción</span>' ) . '<br>';
echo 'Email Notificación: ' . ( ! empty( $config['email_notificacion'] ) ? '<span class="success">✓ Configurado</span>' : '<span class="error">✗ No configurado</span>' ) . '<br>';

echo '</div>';

// ─── SECCIÓN 2: Estado del Certificado ───────────────────────────────────────

echo '<div class="section">';
echo '<div class="title">2️⃣ Estado del Certificado en Base de Datos</div>';

$cert_path = Arriendo_Facil_SRI_Config::cert_path();
echo 'Archivo P12: ' . ( ! empty( $cert_path ) ? '<span class="success">✓ Presente</span> (' . esc_html( basename( $cert_path ) ) . ')' : '<span class="error">✗ No cargado</span>' ) . '<br>';

echo 'Datos Encriptados en BD:<br>';
echo '  - cert_pem_enc: ' . ( ! empty( $config['cert_pem_enc'] ) ? '<span class="success">✓ ' . strlen( $config['cert_pem_enc'] ) . ' bytes</span>' : '<span class="error">✗ Vacío</span>' ) . '<br>';
echo '  - pkey_pem_enc: ' . ( ! empty( $config['pkey_pem_enc'] ) ? '<span class="success">✓ ' . strlen( $config['pkey_pem_enc'] ) . ' bytes</span>' : '<span class="error">✗ Vacío</span>' ) . '<br>';
echo '  - chain_pem_enc: ' . ( ! empty( $config['chain_pem_enc'] ) ? '<span class="success">✓ ' . strlen( $config['chain_pem_enc'] ) . ' bytes</span>' : '<span class="warning">⚠ Vacío (intentará obtener vía AIA)</span>' ) . '<br>';
echo 'cert_password_enc: ' . ( ! empty( $config['cert_password_enc'] ) ? '<span class="success">✓ Presente</span>' : '<span class="error">✗ No guardada</span>' ) . '<br>';

echo '</div>';

// ─── SECCIÓN 3: Desencriptación del Certificado ──────────────────────────────

echo '<div class="section">';
echo '<div class="title">3️⃣ Desencriptación del Certificado (CRÍTICO)</div>';

$pems = Arriendo_Facil_SRI_Config::get_cert_pems();

$cert_len = strlen( $pems['cert'] );
$pkey_len = strlen( $pems['pkey'] );
$chain_len = strlen( $pems['chain'] );

echo '✓ Certificado desencriptado: ' . ( $cert_len > 0 ? '<span class="success">' . $cert_len . ' bytes</span>' : '<span class="error">✗ VACÍO - LA DESENCRIPTACIÓN FALLÓ</span>' ) . '<br>';
echo '✓ Clave privada desencriptada: ' . ( $pkey_len > 0 ? '<span class="success">' . $pkey_len . ' bytes</span>' : '<span class="error">✗ VACÍO - LA DESENCRIPTACIÓN FALLÓ</span>' ) . '<br>';
echo '✓ Cadena CA desencriptada: ' . ( $chain_len > 0 ? '<span class="success">' . $chain_len . ' bytes</span>' : '<span class="warning">⚠ VACÍA - Se intentará obtener vía AIA</span>' ) . '<br>';

if ( $cert_len === 0 || $pkey_len === 0 ) {
	echo '<p><span class="error">⚠️ PROBLEMA CRÍTICO DETECTADO:</span> El certificado o la clave privada se están desencriptando como vacíos. Posibles causas:</p>';
	echo '<ul>';
	echo '<li>Las claves de encriptación de WordPress (AUTH_KEY, SECURE_AUTH_KEY) han cambiado</li>';
	echo '<li>Se migró la BD a otro servidor con diferentes salts</li>';
	echo '<li>Problema de integridad de datos encriptados</li>';
	echo '</ul>';
	echo '<p><strong>Solución:</strong> Vuelve a cargar el certificado P12 con su contraseña.</p>';
}

echo '</div>';

// ─── SECCIÓN 4: Análisis del Certificado ────────────────────────────────────

if ( $cert_len > 0 ) {
	echo '<div class="section">';
	echo '<div class="title">4️⃣ Análisis del Certificado X.509</div>';

	$cert_info = openssl_x509_parse( $pems['cert'] );

	if ( false === $cert_info ) {
		echo '<span class="error">✗ No se pudo analizar el certificado (PEM corrupto)</span>';
	} else {
		$subject = $cert_info['subject'] ?? array();
		$issuer = $cert_info['issuer'] ?? array();
		$extensions = $cert_info['extensions'] ?? array();

		echo 'Sujeto (Subject):<br>';
		echo '  CN: ' . ( $subject['CN'] ?? '?' ) . '<br>';
		echo '  O: ' . ( $subject['O'] ?? '?' ) . '<br>';
		echo '  Serial/RUC: ' . ( $subject['serialNumber'] ?? ( $subject['UID'] ?? 'No encontrado' ) ) . '<br>';

		echo 'Emisor (Issuer):<br>';
		echo '  CN: ' . ( $issuer['CN'] ?? '?' ) . '<br>';
		echo '  O: ' . ( $issuer['O'] ?? '?' ) . '<br>';

		// Validar vigencia
		$valid_from = $cert_info['validFrom_time_t'] ?? 0;
		$valid_to = $cert_info['validTo_time_t'] ?? 0;
		$now = time();

		echo 'Vigencia:<br>';
		echo '  Desde: ' . wp_date( 'd/m/Y H:i:s', $valid_from ) . '<br>';
		echo '  Hasta: ' . wp_date( 'd/m/Y H:i:s', $valid_to );

		if ( $now > $valid_to ) {
			echo ' <span class="error">✗ VENCIDO</span>';
		} elseif ( $now < $valid_from ) {
			echo ' <span class="warning">⚠ Aún no válido</span>';
		} else {
			echo ' <span class="success">✓ Vigente</span>';
		}
		echo '<br>';

		// Verificar Key Usage
		$key_usage = $extensions['keyUsage'] ?? 'No definido';
		echo 'Key Usage: ' . ( strpos( $key_usage, 'Digital Signature' ) !== false ? '<span class="success">✓ Digital Signature habilitada</span>' : '<span class="warning">⚠ ' . $key_usage . '</span>' ) . '<br>';

		// Verificar Extended Key Usage
		$ext_key_usage = $extensions['extendedKeyUsage'] ?? 'No definido';
		echo 'Extended Key Usage: ' . ( $ext_key_usage ) . '<br>';
	}

	echo '</div>';
}

// ─── SECCIÓN 5: Análisis de la Cadena CA ────────────────────────────────────

if ( $chain_len > 0 ) {
	echo '<div class="section">';
	echo '<div class="title">5️⃣ Análisis de la Cadena CA</div>';

	$chain_count = preg_match_all( '/-----BEGIN CERTIFICATE-----/', $pems['chain'] );
	echo 'Certificados intermedios en cadena: <span class="success">' . $chain_count . '</span><br>';

	if ( $chain_count > 0 ) {
		// Parsear cada certificado en la cadena
		if ( preg_match_all( '/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pems['chain'], $matches ) ) {
			foreach ( $matches[1] as $i => $cert_body ) {
				$cert_pem = "-----BEGIN CERTIFICATE-----" . $cert_body . "-----END CERTIFICATE-----";
				$info = openssl_x509_parse( $cert_pem );
				if ( $info ) {
					$issuer = $info['issuer'] ?? array();
					echo '<br>Certificado ' . ( $i + 1 ) . ':<br>';
					echo '  CN: ' . ( $issuer['CN'] ?? '?' ) . '<br>';
					echo '  O: ' . ( $issuer['O'] ?? '?' ) . '<br>';
				}
			}
		}
	}

	echo '</div>';
} else {
	echo '<div class="section warning">';
	echo '<div class="title">⚠️ 5️⃣ Cadena CA Vacía</div>';
	echo 'La cadena CA no está almacenada. El SRI podría no poder validar el certificado.<br>';
	echo 'Haz clic en "Reconstruir cadena CA" en la configuración de SRI.<br>';
	echo '</div>';
}

// ─── SECCIÓN 6: Test de Firma ───────────────────────────────────────────────

if ( $cert_len > 0 && $pkey_len > 0 ) {
	echo '<div class="section">';
	echo '<div class="title">6️⃣ Test de Firma Local (RSA-SHA1)</div>';

	try {
		require_once( 'includes/billing/class-sri-clave-acceso.php' );
		require_once( 'includes/billing/class-sri-xml-factura.php' );

		// Crear un XML de prueba mínimo
		$test_xml = '<?xml version="1.0" encoding="UTF-8"?><factura id="comprobante" version="2.1.0"><infoTributaria><ambiente>1</ambiente><razonSocial>TEST</razonSocial></infoTributaria></factura>';

		$signer = new Arriendo_Facil_SRI_Signer( $pems['cert'], $pems['pkey'], $pems['chain'] ?? '' );
		$signed = $signer->sign( $test_xml );

		// Verificar que la firma sea válida localmente
		$doc = new DOMDocument();
		$doc->loadXML( $signed );
		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'ds', 'http://www.w3.org/2000/09/xmldsig#' );

		$si_node = $xpath->query( '//ds:SignedInfo' )->item( 0 );
		$sv_node = $xpath->query( '//ds:SignatureValue' )->item( 0 );

		if ( $si_node && $sv_node ) {
			$si_c14n = $si_node->C14N( false, false );
			$sig_b64 = trim( $sv_node->textContent );

			$pub_key = openssl_pkey_get_public( $pems['cert'] );
			$verify = openssl_verify( $si_c14n, base64_decode( $sig_b64 ), $pub_key, OPENSSL_ALGO_SHA1 );

			if ( 1 === $verify ) {
				echo '<span class="success">✓ Firma RSA-SHA1 verificada correctamente localmente</span><br>';

				// Contar certificados en la firma
				$x509_count = substr_count( $signed, '<ds:X509Certificate>' );
				echo 'Certificados incluidos en la firma: <span class="' . ( $x509_count > 1 ? 'success' : 'warning' ) . '">' . $x509_count . '</span><br>';
				if ( $x509_count === 1 ) {
					echo '<span class="warning">⚠ Solo se incluye el certificado final. La cadena intermedios NO está en la firma.</span><br>';
				}

				// Verificar que la firma sea enveloped
				if ( strpos( $signed, 'enveloped-signature' ) !== false ) {
					echo 'Tipo de firma: <span class="success">✓ Enveloped</span><br>';
				} else {
					echo 'Tipo de firma: <span class="error">✗ No es enveloped</span><br>';
				}
			} else {
				echo '<span class="error">✗ La firma NO se verificó correctamente. Error: ' . openssl_error_string() . '</span><br>';
			}
		} else {
			echo '<span class="error">✗ No se encontraron elementos de firma en el XML.</span><br>';
		}
	} catch ( Exception $e ) {
		echo '<span class="error">✗ Error al firmar: ' . esc_html( $e->getMessage() ) . '</span><br>';
	}

	echo '</div>';
}

// ─── SECCIÓN 7: Recomendaciones ─────────────────────────────────────────────

echo '<div class="section">';
echo '<div class="title">7️⃣ Recomendaciones</div>';

$issues = array();

if ( $cert_len === 0 ) {
	$issues[] = 'El certificado se está desencriptando como vacío - Vuelve a cargar el P12';
}
if ( $pkey_len === 0 ) {
	$issues[] = 'La clave privada se está desencriptando como vacía - Vuelve a cargar el P12';
}
if ( $chain_len === 0 ) {
	$issues[] = 'La cadena CA está vacía - Haz clic en "Reconstruir cadena CA"';
}
if ( false !== $cert_info && isset( $cert_info['validTo_time_t'] ) && $cert_info['validTo_time_t'] < time() ) {
	$issues[] = 'El certificado está VENCIDO - Renuévalo en el Banco Central del Ecuador';
}

if ( empty( $issues ) ) {
	echo '<span class="success">✓ No se encontraron problemas evidentes en el diagnóstico local.</span><br>';
	echo 'Si aun así el SRI rechaza la firma, el problema es probablemente:<br>';
	echo '<ul>';
	echo '<li><strong>Trust Store del SRI:</strong> El SRI test no tiene en su trust store los certificados intermedios de UANATACA</li>';
	echo '<li><strong>Solución:</strong> Contacta al Banco Central del Ecuador para que agreguen tu certificado/CA al trust store del ambiente de pruebas</li>';
	echo '<li><strong>Alternativa:</strong> Usa un certificado de otra entidad de certificación que el SRI reconozca</li>';
	echo '</ul>';
} else {
	echo '<span class="error">Se encontraron problemas:</span><br>';
	echo '<ul>';
	foreach ( $issues as $issue ) {
		echo '<li>' . esc_html( $issue ) . '</li>';
	}
	echo '</ul>';
}

echo '</div>';

// ─── SECCIÓN 8: Información del Servidor ────────────────────────────────────

echo '<div class="section">';
echo '<div class="title">8️⃣ Información del Servidor</div>';

echo 'PHP Version: ' . phpversion() . '<br>';
echo 'OpenSSL: ' . openssl_get_vendor() . ' ' . openssl_get_version() . '<br>';
echo 'WordPress Version: ' . get_bloginfo( 'version' ) . '<br>';
echo 'AUTH_KEY definido: ' . ( defined( 'AUTH_KEY' ) ? '<span class="success">✓</span>' : '<span class="error">✗</span>' ) . '<br>';
echo 'SECURE_AUTH_KEY definido: ' . ( defined( 'SECURE_AUTH_KEY' ) ? '<span class="success">✓</span>' : '<span class="error">✗</span>' ) . '<br>';

echo '</div>';

// ─── Advertencia Final ───────────────────────────────────────────────────────

echo '<div class="section warning">';
echo '<h3>⚠️ IMPORTANTE</h3>';
echo '<p><strong>Este archivo contiene información sensible sobre certificados digitales.</strong></p>';
echo '<p>Debes eliminarlo después de terminar el diagnóstico:</p>';
echo '<code>rm ' . __FILE__ . '</code>';
echo '</p>';
echo '</div>';

?>
