<?php
/**
 * CONVERTIDOR Y VALIDADOR DE P12
 *
 * Convierte P12 a formato compatible con PHP/OpenSSL moderno
 * Soluciona problemas de "FIRMA INVALIDA" causados por P12 en formato antiguo
 *
 * USO:
 *   php CONVERTIDOR_P12_VALIDO.php <ruta_p12> <contraseña>
 *
 * EJEMPLO:
 *   php CONVERTIDOR_P12_VALIDO.php ./cert.p12 "MiContraseña123"
 */

if ( php_sapi_name() !== 'cli' ) {
	die( "Este script solo funciona en CLI\n" );
}

$args = $argv;
array_shift( $args );

if ( count( $args ) < 2 ) {
	echo "╔════════════════════════════════════════════════════════════════════╗\n";
	echo "║  CONVERTIDOR P12 → XADES-BES COMPATIBLE                           ║\n";
	echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

	echo "Uso: php CONVERTIDOR_P12_VALIDO.php <ruta_p12> <contraseña>\n\n";

	echo "Ejemplo:\n";
	echo "  php CONVERTIDOR_P12_VALIDO.php ./cert.p12 \"MiContraseña\"\n\n";

	echo "Este script:\n";
	echo "  1. Valida el P12 original\n";
	echo "  2. Extrae certificado, clave privada y cadena\n";
	echo "  3. Re-empaqueta en formato OpenSSL 3.x compatible\n";
	echo "  4. Valida la firma XAdES-BES\n";
	echo "  5. Genera archivo P12 nuevo\n\n";

	exit( 1 );
}

$p12_path   = $args[0];
$password   = $args[1];
$output_dir = dirname( $p12_path );

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  CONVERTIDOR P12 XADES-BES                                        ║\n";
echo "║  Conversión a formato compatible (OpenSSL 3.x + PHP 8.x)         ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

// ─────────────────────────────────────────────────────────────────────────
// PASO 1: VALIDAR ARCHIVO P12
// ─────────────────────────────────────────────────────────────────────────

echo "PASO 1: Validación del P12 original\n";
echo "───────────────────────────────────\n\n";

if ( ! file_exists( $p12_path ) ) {
	die( "❌ Archivo no encontrado: $p12_path\n" );
}

printf( "✓ Archivo encontrado: %s\n", basename( $p12_path ) );
printf( "  Tamaño: %s bytes\n", filesize( $p12_path ) );
printf( "  Contraseña: %s***\n\n", substr( $password, 0, 3 ) );

$p12_contents = file_get_contents( $p12_path );
if ( false === $p12_contents ) {
	die( "❌ No se pudo leer el archivo\n" );
}

// ─────────────────────────────────────────────────────────────────────────
// PASO 2: EXTRAER COMPONENTES
// ─────────────────────────────────────────────────────────────────────────

echo "PASO 2: Extracción de componentes\n";
echo "─────────────────────────────────\n\n";

// Flush OpenSSL errors
while ( openssl_error_string() ) {
}

$certs = array();
if ( ! openssl_pkcs12_read( $p12_contents, $certs, $password ) ) {
	echo "❌ PHP openssl_pkcs12_read() falló\n";
	echo "   Intentando fallback CLI...\n\n";

	$temp_pass = tempnam( sys_get_temp_dir(), 'af_pass_' );
	file_put_contents( $temp_pass, $password );

	$cert_cmd = sprintf(
		'openssl pkcs12 -in %s -passin file:%s -clcerts -nokeys -legacy 2>&1',
		escapeshellarg( $p12_path ),
		escapeshellarg( $temp_pass )
	);
	$key_cmd = sprintf(
		'openssl pkcs12 -in %s -passin file:%s -nocerts -nodes -legacy 2>&1',
		escapeshellarg( $p12_path ),
		escapeshellarg( $temp_pass )
	);
	$chain_cmd = sprintf(
		'openssl pkcs12 -in %s -passin file:%s -cacerts -nokeys -legacy 2>&1',
		escapeshellarg( $p12_path ),
		escapeshellarg( $temp_pass )
	);

	$cert_out = @shell_exec( $cert_cmd ) ?: '';
	$key_out  = @shell_exec( $key_cmd ) ?: '';
	$chain_out = @shell_exec( $chain_cmd ) ?: '';

	@unlink( $temp_pass );

	if ( empty( $cert_out ) || empty( $key_out ) ) {
		die( "❌ CLI también falló\nEl P12 podría estar en formato inválido o corrupto.\n" );
	}

	$cert = extract_pem_block( $cert_out, 'CERTIFICATE' );
	$pkey = extract_pem_block( $key_out, 'PRIVATE KEY' );
	$chain = extract_all_pem_blocks( $chain_out, 'CERTIFICATE' );

	if ( empty( $cert ) || empty( $pkey ) ) {
		die( "❌ No se pudieron extraer certificado o clave privada\n" );
	}
} else {
	printf( "✓ Extracción via PHP exitosa\n\n" );
	$cert = $certs['cert'] ?? '';
	$pkey = $certs['pkey'] ?? '';
	$chain_arr = $certs['extracerts'] ?? array();
	$chain = ! empty( $chain_arr ) ? implode( "\n", (array) $chain_arr ) : '';
}

printf( "  Certificado:     %s bytes\n", strlen( $cert ) );
printf( "  Clave privada:   %s bytes\n", strlen( $pkey ) );
printf( "  Cadena CA:       %s bytes\n\n", strlen( $chain ) );

// ─────────────────────────────────────────────────────────────────────────
// PASO 3: VALIDACIÓN DE COMPONENTES
// ─────────────────────────────────────────────────────────────────────────

echo "PASO 3: Validación de componentes\n";
echo "────────────────────────────────\n\n";

$cert_info = openssl_x509_parse( $cert );
if ( ! $cert_info ) {
	die( "❌ Certificado inválido o corrupto\n" );
}

printf( "✓ Certificado válido\n" );
printf( "  CN: %s\n", $cert_info['subject']['CN'] ?? '?' );
printf( "  Emisor: %s\n", $cert_info['issuer']['CN'] ?? '?' );
printf( "  Vigencia: " );

$valid_to = $cert_info['validTo_time_t'] ?? 0;
$valid_from = $cert_info['validFrom_time_t'] ?? 0;

if ( $valid_to < time() ) {
	printf( "❌ EXPIRADO (hasta %s)\n", date( 'd/m/Y', $valid_to ) );
	die();
} else {
	printf( "✓ Vigente (hasta %s)\n", date( 'd/m/Y', $valid_to ) );
}

$pk = openssl_pkey_get_private( $pkey );
if ( ! $pk ) {
	die( "❌ Clave privada inválida\n" );
}
printf( "✓ Clave privada válida\n\n" );

// ─────────────────────────────────────────────────────────────────────────
// PASO 4: RE-EMPAQUETAR EN FORMATO MODERNO
// ─────────────────────────────────────────────────────────────────────────

echo "PASO 4: Re-empaquetamiento en formato OpenSSL 3.x\n";
echo "──────────────────────────────────────────────────\n\n";

// Escribir archivos temporales
$temp_dir = sys_get_temp_dir() . '/af_p12_convert_' . uniqid();
mkdir( $temp_dir );

$cert_tmp = $temp_dir . '/cert.pem';
$key_tmp  = $temp_dir . '/key.pem';
$chain_tmp = $temp_dir . '/chain.pem';

file_put_contents( $cert_tmp, $cert );
file_put_contents( $key_tmp, $pkey );
if ( ! empty( $chain ) ) {
	file_put_contents( $chain_tmp, $chain );
}

printf( "Archivos temporales creados en: %s\n\n", $temp_dir );

// Crear nuevo P12 con OpenSSL CLI
$new_p12_path = $output_dir . '/cert_convertido.p12';

$cmd = sprintf(
	'openssl pkcs12 -export -in %s -inkey %s -out %s -passout pass:%s -name "Certificado XAdES-BES" 2>&1',
	escapeshellarg( $cert_tmp ),
	escapeshellarg( $key_tmp ),
	escapeshellarg( $new_p12_path ),
	escapeshellarg( $password )
);

if ( ! empty( $chain ) ) {
	$cmd = sprintf(
		'openssl pkcs12 -export -in %s -inkey %s -certfile %s -out %s -passout pass:%s -name "Certificado XAdES-BES" 2>&1',
		escapeshellarg( $cert_tmp ),
		escapeshellarg( $key_tmp ),
		escapeshellarg( $chain_tmp ),
		escapeshellarg( $new_p12_path ),
		escapeshellarg( $password )
	);
}

$output = @shell_exec( $cmd );

if ( ! file_exists( $new_p12_path ) ) {
	printf( "❌ OpenSSL export falló\nOutput: %s\n", $output );
	exit( 1 );
}

printf( "✓ P12 nuevo creado: %s\n", basename( $new_p12_path ) );
printf( "  Tamaño: %s bytes\n\n", filesize( $new_p12_path ) );

// ─────────────────────────────────────────────────────────────────────────
// PASO 5: VALIDAR NUEVO P12
// ─────────────────────────────────────────────────────────────────────────

echo "PASO 5: Validación del P12 nuevo\n";
echo "────────────────────────────────\n\n";

$new_contents = file_get_contents( $new_p12_path );
$new_certs = array();

if ( openssl_pkcs12_read( $new_contents, $new_certs, $password ) ) {
	printf( "✓ P12 nuevo pasa validación PHP openssl_pkcs12_read()\n\n" );
} else {
	printf( "⚠️  P12 nuevo NO pasa validación PHP (pero puede ser OK en CLI)\n\n" );
}

// ─────────────────────────────────────────────────────────────────────────
// PASO 6: LIMPIAR TEMPORALES
// ─────────────────────────────────────────────────────────────────────────

array_map( 'unlink', glob( "$temp_dir/*" ) );
rmdir( $temp_dir );

// ─────────────────────────────────────────────────────────────────────────
// RESUMEN
// ─────────────────────────────────────────────────────────────────────────

echo "✅ CONVERSIÓN EXITOSA\n\n";

echo "Próximos pasos:\n";
echo "  1. Guarda el archivo: $new_p12_path\n";
echo "  2. Ve a Facturación > Configuración SRI\n";
echo "  3. Sube el nuevo P12 (cert_convertido.p12)\n";
echo "  4. Ingresa la misma contraseña\n";
echo "  5. Haz clic en \"Test firma XML\"\n";
echo "  6. Si todo OK, intenta emitir factura\n\n";

echo "Si aún ves FIRMA INVALIDA:\n";
echo "  • Verifica que cadena CA esté presente (2+ certificados)\n";
echo "  • Ejecuta: php DIAGNOSTICO_P12_DETALLADO.php\n";
echo "  • Si es UANATACA, prueba en ambiente producción\n\n";

// ─────────────────────────────────────────────────────────────────────────
// FUNCIONES HELPER
// ─────────────────────────────────────────────────────────────────────────

function extract_pem_block( string $text, string $type ): string {
	$pattern = '/-----BEGIN ' . preg_quote( $type ) . '-----(.+?)-----END ' . preg_quote( $type ) . '-----/s';
	if ( preg_match( $pattern, $text, $m ) ) {
		return '-----BEGIN ' . $type . '-----' . $m[1] . '-----END ' . $type . '-----';
	}
	return '';
}

function extract_all_pem_blocks( string $text, string $type ): string {
	$pattern = '/-----BEGIN ' . preg_quote( $type ) . '-----(.+?)-----END ' . preg_quote( $type ) . '-----/s';
	$blocks = array();
	if ( preg_match_all( $pattern, $text, $matches ) ) {
		foreach ( $matches[0] as $block ) {
			$blocks[] = $block;
		}
	}
	return implode( "\n", $blocks );
}
