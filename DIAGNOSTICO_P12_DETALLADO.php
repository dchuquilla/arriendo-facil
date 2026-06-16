<?php
/**
 * DIAGNÓSTICO DETALLADO DEL P12
 *
 * Herramienta para detectar exactamente qué está mal con el certificado P12
 * y las posibles causas de "FIRMA INVALIDA"
 *
 * Ejecutar desde la raíz de WordPress:
 *   php wp-cli.phar eval-file wp-content/plugins/arriendo-facil/DIAGNOSTICO_P12_DETALLADO.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( dirname( __FILE__ ) ) . '/wp-load.php';
}

require_once dirname( __FILE__ ) . '/includes/billing/class-sri-config.php';
require_once dirname( __FILE__ ) . '/includes/billing/class-sri-signer.php';

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║    DIAGNÓSTICO DETALLADO: PROBLEMA DE FIRMA P12                   ║\n";
echo "║    Detección de "FIRMA INVALIDA" por defecto en certificado       ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 1: VERIFICAR ARCHIVO P12
// ─────────────────────────────────────────────────────────────────────────

echo "📁 SECCIÓN 1: VERIFICAR ARCHIVO P12 ALMACENADO\n";
echo "─────────────────────────────────────────────\n\n";

$cert_path = Arriendo_Facil_SRI_Config::cert_path();
if ( '' === $cert_path ) {
	echo "❌ NO HAY CERTIFICADO P12 CARGADO\n\n";
	echo "Acción requerida:\n";
	echo "  1. Ve a Facturación > Configuración SRI\n";
	echo "  2. Sube tu certificado .p12\n";
	echo "  3. Ejecuta este diagnóstico de nuevo\n\n";
	exit;
}

printf( "✓ Archivo P12 encontrado: %s\n", basename( $cert_path ) );
printf( "  Tamaño: %s bytes\n", filesize( $cert_path ) );
printf( "  Última modificación: %s\n\n", date( 'd/m/Y H:i:s', filemtime( $cert_path ) ) );

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 2: INTENTAR LEER EL P12 (NATIVO)
// ─────────────────────────────────────────────────────────────────────────

echo "🔐 SECCIÓN 2: LECTURA DEL P12 (MÉTODO NATIVO PHP)\n";
echo "──────────────────────────────────────────────────\n\n";

$password = Arriendo_Facil_SRI_Config::cert_password();
if ( '' === $password ) {
	echo "❌ NO HAY CONTRASEÑA ALMACENADA\n\n";
	echo "El P12 requiere contraseña para extraer certificado y clave privada.\n";
	echo "Acción: Ve a Facturación > Configuración SRI y vuelve a ingresar la contraseña.\n\n";
	exit;
}

printf( "Usando contraseña: %s (primeros 3 chars)***\n\n", substr( $password, 0, 3 ) );

// Flush OpenSSL errors
while ( openssl_error_string() ) {
}

$contents = file_get_contents( $cert_path );
$certs_native = array();
$native_success = false;

if ( openssl_pkcs12_read( $contents, $certs_native, $password ) ) {
	$native_success = true;
	echo "✓ openssl_pkcs12_read() exitoso\n\n";

	printf( "  Certificado extraído:  %s bytes\n", strlen( $certs_native['cert'] ?? '' ) );
	printf( "  Clave privada extraída: %s bytes\n", strlen( $certs_native['pkey'] ?? '' ) );
	printf( "  CA interna en P12:      %s certificado(s)\n",
		! empty( $certs_native['extracerts'] ) ? count( (array) $certs_native['extracerts'] ) : '0'
	);

	// Parse certificado
	$cert_info = openssl_x509_parse( $certs_native['cert'] ?? '' );
	if ( $cert_info ) {
		echo "\n  ✓ Certificado parseado correctamente\n";
		echo "    CN: " . ( $cert_info['subject']['CN'] ?? '?' ) . "\n";
		echo "    O: " . ( $cert_info['subject']['O'] ?? '?' ) . "\n";
		echo "    Emisor: " . ( $cert_info['issuer']['CN'] ?? '?' ) . "\n";
		echo "    Serial: " . ( $cert_info['serialNumber'] ?? '?' ) . "\n";

		$valid_to = $cert_info['validTo_time_t'] ?? 0;
		$is_expired = ( $valid_to > 0 && $valid_to < time() );
		echo "    Vigencia: hasta " . date( 'd/m/Y', $valid_to );
		echo $is_expired ? " ❌ EXPIRADO\n" : " ✓ Vigente\n";

		// Verificar extensión AIA
		$extensions = $cert_info['extensions'] ?? array();
		if ( ! empty( $extensions['authorityInfoAccess'] ) ) {
			echo "    AIA: Sí (se puede obtener cadena automáticamente)\n";
		} else {
			echo "    AIA: No (se necesita cadena manual)\n";
		}
	} else {
		echo "  ❌ Error al parsear certificado\n";
	}

	echo "\n";
} else {
	echo "❌ openssl_pkcs12_read() FALLÓ\n";
	echo "  Error OpenSSL: " . openssl_error_string() . "\n\n";
	echo "  Posibles causas:\n";
	echo "    1. Contraseña incorrecta\n";
	echo "    2. Archivo P12 corrupto\n";
	echo "    3. OpenSSL versión incompatible\n\n";
}

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 3: INTENTAR LEER CON CLI (FALLBACK)
// ─────────────────────────────────────────────────────────────────────────

if ( ! $native_success ) {
	echo "⚙️  SECCIÓN 3: LECTURA DEL P12 (MÉTODO CLI - FALLBACK)\n";
	echo "──────────────────────────────────────────────────────\n\n";

	if ( ! function_exists( 'shell_exec' ) ) {
		echo "❌ shell_exec deshabilitado – no se puede intentar fallback CLI\n\n";
		echo "Soluciones:\n";
		echo "  1. Pide a tu hosting que habilite shell_exec\n";
		echo "  2. Convierte el P12 a formato moderno (OpenSSL 3.x compatible)\n";
		echo "  3. Usa un certificado de BCE/SecurityData en lugar de UANATACA\n\n";
		exit;
	}

	echo "Intentando vía OpenSSL CLI...\n\n";

	$pass_file = tempnam( sys_get_temp_dir(), 'af_pass_' );
	file_put_contents( $pass_file, $password, LOCK_EX );

	$escaped_path      = escapeshellarg( $cert_path );
	$escaped_pass_file = escapeshellarg( $pass_file );

	// Intenta con -legacy flag
	$cert_cmd = sprintf(
		'openssl pkcs12 -in %s -passin file:%s -clcerts -nokeys -legacy 2>&1',
		$escaped_path,
		$escaped_pass_file
	);

	$output = @shell_exec( $cert_cmd );
	@unlink( $pass_file );

	if ( null === $output || '' === trim( $output ) ) {
		echo "❌ CLI fallback también falló\n\n";
		echo "El P12 no se puede leer con OpenSSL CLI (ni sin -legacy ni con -legacy).\n";
		echo "Esto típicamente indica:\n\n";
		echo "  ❌ PROBLEMA 1: Certificado en formato PKCS#1 (sin estándar)\n";
		echo "     Solución: Convertir a PKCS#12 estándar\n\n";
		echo "  ❌ PROBLEMA 2: Encriptación no soportada\n";
		echo "     Solución: Re-emitir P12 desde BCE/SecurityData\n\n";
		echo "  ❌ PROBLEMA 3: Servidor sin OpenSSL CLI\n";
		echo "     Solución: Contactar hosting para habilitar\n\n";
		exit;
	}

	echo "✓ CLI fallback exitoso\n";
	echo "  (Este es un indicador de problema con la versión nativa de PHP)\n\n";
}

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 4: ESTADO DE ALMACENAMIENTO EN BD
// ─────────────────────────────────────────────────────────────────────────

echo "💾 SECCIÓN 4: ESTADO DE ALMACENAMIENTO EN BASE DE DATOS\n";
echo "────────────────────────────────────────────────────────\n\n";

$pems = Arriendo_Facil_SRI_Config::get_cert_pems();

printf( "Cert guardado en BD:  %s (%s bytes)\n",
	! empty( $pems['cert'] ) ? '✓ Sí' : '❌ No',
	strlen( $pems['cert'] ?? '' )
);

printf( "Pkey guardado en BD:  %s (%s bytes)\n",
	! empty( $pems['pkey'] ) ? '✓ Sí' : '❌ No',
	strlen( $pems['pkey'] ?? '' )
);

printf( "Chain guardado en BD: %s (%s bytes)\n\n",
	! empty( $pems['chain'] ) ? '✓ Sí' : '❌ No',
	strlen( $pems['chain'] ?? '' )
);

// Contar intermediarios
if ( ! empty( $pems['chain'] ) ) {
	$chain_count = (int) preg_match_all( '/-----BEGIN CERTIFICATE-----/', $pems['chain'] );
	printf( "  Certificados intermedios: %d\n\n", $chain_count );
}

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 5: PROBLEMAS ESPECÍFICOS DEL P12
// ─────────────────────────────────────────────────────────────────────────

echo "🔴 SECCIÓN 5: PROBLEMAS ESPECÍFICOS DETECTADOS\n";
echo "──────────────────────────────────────────────\n\n";

$issues = array();

// Issue 1: Certificado vacío
if ( empty( $pems['cert'] ) ) {
	$issues[] = array(
		'severity' => 'CRÍTICO',
		'problem'  => 'Certificado vacío o no se desencriptó',
		'cause'    => '1) Contraseña incorrecta\n    2) Encriptación en BD falló\n    3) Certificado no se extrajo del P12',
		'fix'      => 'Ve a Facturación > Config SRI > Sube P12 nuevamente con contraseña correcta',
	);
}

// Issue 2: Clave privada vacía
if ( empty( $pems['pkey'] ) ) {
	$issues[] = array(
		'severity' => 'CRÍTICO',
		'problem'  => 'Clave privada vacía o no se desencriptó',
		'cause'    => 'Mismo que Issue 1',
		'fix'      => 'Vuelve a cargar el P12 correctamente',
	);
}

// Issue 3: Cadena CA vacía (pero cert/pkey presentes)
if ( ! empty( $pems['cert'] ) && ! empty( $pems['pkey'] ) && empty( $pems['chain'] ) ) {
	$issues[] = array(
		'severity' => 'ALTO',
		'problem'  => 'Cadena CA vacía – intermediarios no presentes',
		'cause'    => '1) P12 no incluye CA interna\n    2) AIA falló (sin internet o URL inaccesible)\n    3) Certificado sin AIA y sin cadena manual',
		'fix'      => 'Intenta "Reconstruir cadena CA" o agrega manualmente vía "Cadena CA Manual"',
	);
}

// Issue 4: CN o emisor sospechoso
if ( ! empty( $pems['cert'] ) ) {
	$cert_info = openssl_x509_parse( $pems['cert'] );
	if ( $cert_info ) {
		$cn = $cert_info['subject']['CN'] ?? '';
		$issuer = $cert_info['issuer']['CN'] ?? '';

		// Detectar si es autofirmado
		$is_self = ( $cert_info['subject'] === $cert_info['issuer'] );
		if ( $is_self ) {
			$issues[] = array(
				'severity' => 'MEDIA',
				'problem'  => 'Certificado autofirmado (no emitido por CA)',
				'cause'    => 'El certificado está firmado por sí mismo',
				'fix'      => 'Si es intencional (testing), OK. Si no, obtén certificado de BCE/SecurityData/UANATACA',
			);
		}

		// Detectar UANATACA (conocido problema con SRI test)
		if ( strpos( $issuer, 'UANATACA' ) !== false ) {
			$issues[] = array(
				'severity' => 'MEDIA',
				'problem'  => 'Certificado emitido por UANATACA',
				'cause'    => 'SRI test env puede no reconocer UANATACA en su trust store',
				'fix'      => '1) Asegúrate que cadena CA esté completa\n    2) Si aún falla en test, usa prod o certificado de otra CA',
			);
		}
	}
}

// Issue 5: Certificado vencido
if ( ! empty( $pems['cert'] ) ) {
	$cert_info = openssl_x509_parse( $pems['cert'] );
	if ( $cert_info ) {
		$valid_to = $cert_info['validTo_time_t'] ?? 0;
		if ( $valid_to > 0 && $valid_to < time() ) {
			$issues[] = array(
				'severity' => 'CRÍTICO',
				'problem'  => 'Certificado EXPIRADO',
				'cause'    => 'Fecha de vencimiento ha pasado',
				'fix'      => 'Renew en Banco Central o entidad certificadora. El SRI rechazará firma de certs vencidos.',
			);
		}
	}
}

// Mostrar issues
if ( empty( $issues ) ) {
	echo "✓ NO SE DETECTARON PROBLEMAS CRÍTICOS\n\n";
	echo "El certificado se cargó correctamente.\n";
	echo "Si aún ves FIRMA INVALIDA:\n";
	echo "  1. Verifica en los logs si la cadena CA está completa (2+ intermediarios)\n";
	echo "  2. Si solo hay 1 cert en la firma, intenta reconstruir cadena\n";
	echo "  3. Si es UANATACA, prueba en ambiente producción\n\n";
} else {
	foreach ( $issues as $idx => $issue ) {
		printf( "\n❌ ISSUE %d: [%s] %s\n", $idx + 1, $issue['severity'], $issue['problem'] );
		printf( "   Causa probable:\n" );
		foreach ( explode( "\n", $issue['cause'] ) as $line ) {
			printf( "     %s\n", $line );
		}
		printf( "   Solución:\n" );
		foreach ( explode( "\n", $issue['fix'] ) as $line ) {
			printf( "     %s\n", $line );
		}
	}
	echo "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 6: VALIDACIÓN DE FIRMA (SIMULACIÓN)
// ─────────────────────────────────────────────────────────────────────────

if ( ! empty( $pems['cert'] ) && ! empty( $pems['pkey'] ) ) {
	echo "✍️  SECCIÓN 6: SIMULACIÓN DE FIRMA XADES-BES\n";
	echo "────────────────────────────────────────────\n\n";

	try {
		$test_xml = '<?xml version="1.0" encoding="UTF-8"?>' .
			'<comprobante id="comprobante" version="2.1.0">' .
			'<test>test</test></comprobante>';

		$signer = new Arriendo_Facil_SRI_Signer( $pems['cert'], $pems['pkey'], $pems['chain'] ?? '' );
		$signed = $signer->sign( $test_xml );

		echo "✓ Firma XAdES-BES generada correctamente\n\n";

		// Contar certificados en firma
		$cert_count = substr_count( $signed, '<X509Certificate>' );
		printf( "  Certificados en firma: %d\n", $cert_count );

		if ( $cert_count < 2 ) {
			echo "  ⚠️  ADVERTENCIA: Se esperan mínimo 2 (usuario + intermedio)\n";
			echo "     Esto causará rechazo del SRI\n";
		} else {
			echo "  ✓ Incluye certificados intermedios\n";
		}

		echo "\n";
	} catch ( Exception $e ) {
		echo "❌ Error en simulación de firma: " . $e->getMessage() . "\n\n";
	}
}

// ─────────────────────────────────────────────────────────────────────────
// SECCIÓN 7: RECOMENDACIONES FINALES
// ─────────────────────────────────────────────────────────────────────────

echo "📋 SECCIÓN 7: RECOMENDACIONES FINALES\n";
echo "──────────────────────────────────────\n\n";

if ( empty( $issues ) && ! empty( $pems['cert'] ) && ! empty( $pems['pkey'] ) ) {
	$chain_count = (int) preg_match_all( '/-----BEGIN CERTIFICATE-----/', $pems['chain'] ?? '' );

	if ( $chain_count < 2 ) {
		echo "PRIORIDAD 1: Obtener cadena CA completa\n";
		echo "  → Ve a Facturación > Config SRI > Reconstruir cadena CA\n";
		echo "  → Si falla, agrega manualmente en \"Cadena CA Manual\"\n\n";
	}

	echo "PRIORIDAD 2: Prueba emitir factura\n";
	echo "  → Si SRI devuelve FIRMA INVALIDA:\n";
	echo "     • Revisa que cadena tenga 2+ intermediarios\n";
	echo "     • Si es UANATACA, cambiar a producción\n\n";

	echo "PRIORIDAD 3: Valida externamente\n";
	echo "  → Los logs mostrarán el XML firmado\n";
	echo "  → Puedes validarlo en: https://dss.esig.europa.eu/validation/\n\n";
} else {
	echo "⚠️  PROBLEMAS DETECTADOS\n\n";
	echo "Acciones inmediatas:\n";
	echo "  1. Asegúrate de que el P12 sea válido (pruébalo con OpenSSL localmente)\n";
	echo "  2. Sube el P12 nuevamente en Facturación > Config SRI\n";
	echo "  3. Verifica que el servidor permita shell_exec (para fallback CLI)\n";
	echo "  4. Si persiste, contacta a tu hosting o a la entidad certificadora\n\n";
}

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN DEL DIAGNÓSTICO                                              ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";
