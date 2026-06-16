<?php
/**
 * CLI Diagnostic Tool: Cadena CA Validation
 *
 * Ejecutar desde línea de comandos en la raíz del WordPress:
 *   php wp-cli.phar eval-file CLI_DIAGNOSTICO_CADENA.php
 *
 * O desde PHP directo si estás en el raíz del plugin:
 *   require_once 'CLI_DIAGNOSTICO_CADENA.php';
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Si no está en WordPress, cargar WordPress
	require_once dirname( dirname( __FILE__ ) ) . '/wp-load.php';
}

if ( ! function_exists( 'get_option' ) ) {
	die( "Error: No se pudo cargar WordPress.\n" );
}

// Incluir las clases necesarias
require_once dirname( __FILE__ ) . '/includes/billing/class-sri-config.php';
require_once dirname( __FILE__ ) . '/includes/billing/class-sri-signer.php';

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║     DIAGNÓSTICO: CADENA CA EN FIRMA XAdES-BES                     ║\n";
echo "║     Arriendo Fácil SRI - 16 Junio 2026                            ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 1: ESTADO DE ALMACENAMIENTO
// ─────────────────────────────────────────────────────────────────────────

echo "📦 SECCIÓN 1: ESTADO DE ALMACENAMIENTO\n";
echo "────────────────────────────────────────\n\n";

$config = Arriendo_Facil_SRI_Config::get();
$has_cert = ! empty( $config['cert_pem_enc'] );
$has_pkey = ! empty( $config['pkey_pem_enc'] );
$has_chain = ! empty( $config['chain_pem_enc'] );

printf( "Certificado PEM encriptado:  %s (%s bytes)\n",
	$has_cert ? '✓ PRESENTE' : '✗ FALTA',
	strlen( (string) $config['cert_pem_enc'] )
);

printf( "Clave privada encriptada:    %s (%s bytes)\n",
	$has_pkey ? '✓ PRESENTE' : '✗ FALTA',
	strlen( (string) $config['pkey_pem_enc'] )
);

printf( "Cadena CA encriptada:        %s (%s bytes)\n",
	$has_chain ? '✓ PRESENTE' : '✗ FALTA',
	strlen( (string) $config['chain_pem_enc'] )
);

echo "\n";

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 2: DESENCRIPTACIÓN
// ─────────────────────────────────────────────────────────────────────────

echo "🔓 SECCIÓN 2: DESENCRIPTACIÓN\n";
echo "──────────────────────────────\n\n";

$pems = Arriendo_Facil_SRI_Config::get_cert_pems();

printf( "Certificado desencriptado:  %s (%s bytes)\n",
	! empty( $pems['cert'] ) ? '✓ OK' : '✗ VACÍO',
	strlen( $pems['cert'] )
);

printf( "Clave privada desencriptada: %s (%s bytes)\n",
	! empty( $pems['pkey'] ) ? '✓ OK' : '✗ VACÍO',
	strlen( $pems['pkey'] )
);

printf( "Cadena CA desencriptada:    %s (%s bytes)\n",
	! empty( $pems['chain'] ) ? '✓ OK' : '✗ VACÍO',
	strlen( $pems['chain'] )
);

echo "\n";

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 3: ANÁLISIS DE CERTIFICADO
// ─────────────────────────────────────────────────────────────────────────

if ( ! empty( $pems['cert'] ) ) {
	echo "📄 SECCIÓN 3: ANÁLISIS DE CERTIFICADO\n";
	echo "─────────────────────────────────────\n\n";

	$cert_info = openssl_x509_parse( $pems['cert'] );
	if ( $cert_info ) {
		$subject = $cert_info['subject'] ?? array();
		$issuer  = $cert_info['issuer'] ?? array();

		printf( "CN (Subject):         %s\n", $subject['CN'] ?? '(desconocido)' );
		printf( "O (Organización):     %s\n", $subject['O'] ?? '(desconocido)' );
		printf( "Serial (RUC/UID):     %s\n", $subject['UID'] ?? $subject['serialNumber'] ?? '(desconocido)' );
		printf( "\n" );
		printf( "Emisor CN:            %s\n", $issuer['CN'] ?? '(desconocido)' );
		printf( "Emisor O:             %s\n", $issuer['O'] ?? '(desconocido)' );
		printf( "\n" );

		$valid_to = $cert_info['validTo_time_t'] ?? 0;
		$is_expired = ( $valid_to > 0 && $valid_to < time() );
		printf( "Válido hasta:         %s %s\n",
			gmdate( 'Y-m-d', (int) $valid_to ),
			$is_expired ? '❌ EXPIRADO' : '✓ VIGENTE'
		);

		// Extensión AIA
		$extensions = $cert_info['extensions'] ?? array();
		$aia = $extensions['authorityInfoAccess'] ?? '';
		if ( '' !== $aia ) {
			echo "\nAIA (Authority Info Access):\n";
			echo "  " . $aia . "\n";
		} else {
			echo "\n⚠️  No tiene extensión AIA – no se puede descargar cadena automáticamente\n";
		}
	} else {
		echo "❌ No se pudo analizar el certificado\n";
	}
	echo "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 4: ANÁLISIS DE CADENA CA
// ─────────────────────────────────────────────────────────────────────────

echo "🔗 SECCIÓN 4: ANÁLISIS DE CADENA CA\n";
echo "───────────────────────────────────\n\n";

if ( empty( $pems['chain'] ) ) {
	echo "❌ CADENA VACÍA\n\n";
	echo "Estado: Sin certificados intermedios\n";
	echo "Riesgo: CRÍTICO - El SRI rechazará la firma con 'FIRMA INVALIDA'\n\n";
	echo "Soluciones:\n";
	echo "  1. Si el certificado tiene AIA:\n";
	echo "     → Ve a Facturación > Configuración SRI\n";
	echo "     → Haz clic en 'Reconstruir cadena CA'\n\n";
	echo "  2. Si el certificado NO tiene AIA:\n";
	echo "     → Obtén la cadena del emisor del certificado\n";
	echo "     → Copia los PEM en 'Cadena CA Manual'\n\n";
} else {
	$chain_lines = explode( "\n", $pems['chain'] );
	$cert_count = (int) preg_match_all( '/-----BEGIN CERTIFICATE-----/', $pems['chain'] );

	printf( "✓ PRESENTE\n\n" );
	printf( "Certificados intermedios: %d\n", $cert_count );

	// Analizar cada certificado
	echo "\nDetalles:\n";
	preg_match_all(
		'/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s',
		$pems['chain'],
		$matches
	);

	foreach ( $matches[1] as $idx => $body ) {
		$pem = "-----BEGIN CERTIFICATE-----\n" . $body . "-----END CERTIFICATE-----";
		$info = openssl_x509_parse( $pem );

		if ( $info ) {
			$subject = $info['subject'] ?? array();
			$issuer  = $info['issuer'] ?? array();
			$is_self = ( $subject === $issuer );

			printf( "\n  [Cert %d] %s\n", $idx + 1, $subject['CN'] ?? $subject['O'] ?? '(desconocido)' );
			printf( "    CN: %s\n", $subject['CN'] ?? '(none)' );
			printf( "    Emisor: %s\n", $issuer['CN'] ?? '(none)' );
			printf( "    Tipo: %s\n", $is_self ? 'ROOT (autofirmado)' : 'INTERMEDIA' );

			$valid_to = $info['validTo_time_t'] ?? 0;
			$is_expired = ( $valid_to > 0 && $valid_to < time() );
			printf( "    Vencimiento: %s %s\n",
				gmdate( 'Y-m-d', (int) $valid_to ),
				$is_expired ? '❌ EXPIRADO' : '✓ OK'
			);
		} else {
			printf( "  [Cert %d] ❌ No se pudo analizar\n", $idx + 1 );
		}
	}

	// Advertencia si solo hay 1
	if ( 1 === $cert_count ) {
		echo "\n⚠️  ADVERTENCIA: Solo 1 certificado intermedio\n";
		echo "   Esto podría ser insuficiente para la validación del SRI\n";
	}
}

echo "\n";

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 5: SIMULACIÓN DE FIRMA
// ─────────────────────────────────────────────────────────────────────────

echo "✍️  SECCIÓN 5: SIMULACIÓN DE FIRMA XAdES-BES\n";
echo "───────────────────────────────────────────\n\n";

if ( empty( $pems['cert'] ) || empty( $pems['pkey'] ) ) {
	echo "❌ No se puede simular: Certificado o clave privada vacíos\n";
} else {
	try {
		$signer = new Arriendo_Facil_SRI_Signer( $pems['cert'], $pems['pkey'], $pems['chain'] );

		// XML de prueba mínimo
		$test_xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
			'<comprobante id="comprobante" version="2.1.0">' . "\n" .
			'  <infoTributaria><ruc>1234567890001</ruc></infoTributaria>' . "\n" .
			'  <infoComprobante><fechaEmision>16/06/2026</fechaEmision></infoComprobante>' . "\n" .
			'</comprobante>';

		$signed = $signer->sign( $test_xml );

		// Contar X509Certificate en la firma
		$dom = new DOMDocument();
		$dom->loadXML( $signed );

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'ds', 'http://www.w3.org/2000/09/xmldsig#' );

		$certs = $xpath->query( '//ds:X509Certificate' );
		$cert_count_in_sig = $certs->length;

		printf( "Simulación de firma: ✓ EXITOSA\n\n" );
		printf( "Certificados en firma (X509Certificate): %d\n", $cert_count_in_sig );

		if ( $cert_count_in_sig < 2 ) {
			echo "\n⚠️  ADVERTENCIA: Se espera mínimo 2 (usuario + intermedio)\n";
			echo "   La firma será rechazada por el SRI\n";
		} else {
			echo "\n✓ La firma incluye certificados intermedios\n";
		}

		// Verificar estructura XAdES
		$sig_node = $xpath->query( '//ds:Signature' )->item( 0 );
		if ( $sig_node ) {
			echo "\n✓ <ds:Signature> presente\n";
		}

		$etsi_qp = $xpath->query( '//etsi:QualifyingProperties', null );
		$xpath->registerNamespace( 'etsi', 'http://uri.etsi.org/01903/v1.3.2#' );
		$etsi_qp = $xpath->query( '//etsi:QualifyingProperties' );
		if ( $etsi_qp->length > 0 ) {
			echo "✓ <etsi:QualifyingProperties> presente (XAdES)\n";
		}

	} catch ( Exception $e ) {
		printf( "❌ Error al simular firma: %s\n", $e->getMessage() );
	}
}

echo "\n";

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 6: RECOMENDACIONES
// ─────────────────────────────────────────────────────────────────────────

echo "📋 SECCIÓN 6: RECOMENDACIONES\n";
echo "─────────────────────────────\n\n";

$issues = array();

if ( empty( $pems['cert'] ) ) {
	$issues[] = "✗ Certificado no cargado o desencriptación falló";
}

if ( empty( $pems['pkey'] ) ) {
	$issues[] = "✗ Clave privada no cargada o desencriptación falló";
}

if ( empty( $pems['chain'] ) ) {
	$issues[] = "✗ Cadena CA vacía";
}

if ( ! empty( $pems['cert'] ) ) {
	$cert_info = openssl_x509_parse( $pems['cert'] );
	if ( $cert_info ) {
		$valid_to = $cert_info['validTo_time_t'] ?? 0;
		if ( $valid_to > 0 && $valid_to < time() ) {
			$issues[] = "✗ Certificado expirado";
		}
	}
}

if ( empty( $issues ) ) {
	echo "✓ Todos los controles pasaron\n\n";
	echo "Próximos pasos:\n";
	echo "  1. Prueba emitir una factura de prueba\n";
	echo "  2. Si el SRI aún rechaza con FIRMA INVALIDA:\n";
	echo "     → Verifica que la cadena CA sea completa\n";
	echo "     → Contacta al Banco Central si es certificado de UANATACA\n";
} else {
	echo "Problemas detectados:\n\n";
	foreach ( $issues as $issue ) {
		echo "  " . $issue . "\n";
	}
	echo "\nAcciones:\n";
	echo "  1. Ve a Facturación > Configuración SRI\n";
	echo "  2. Sube el certificado P12 nuevamente\n";
	echo "  3. Ejecuta este diagnóstico de nuevo\n";
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN DEL DIAGNÓSTICO                                              ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";
