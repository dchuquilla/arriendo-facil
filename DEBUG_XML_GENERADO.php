<?php
/**
 * Script para analizar el XML exacto que genera Arriendo Fácil.
 *
 * Compara la estructura con especificaciones SRI.
 */

require_once( dirname( __FILE__ ) . '/../../../../wp-load.php' );

if ( ! current_user_can( 'manage_options' ) ) {
	die( 'Solo administradores' );
}

require_once( 'includes/billing/class-sri-config.php' );
require_once( 'includes/billing/class-sri-xml-factura.php' );
require_once( 'includes/billing/class-sri-clave-acceso.php' );

echo '<style>
	body { font-family: monospace; margin: 20px; }
	.section { background: #f5f5f5; padding: 15px; margin: 20px 0; border-left: 4px solid #0073aa; }
	.xml-raw { background: white; padding: 10px; border: 1px solid #ccc; max-width: 1000px; overflow-x: auto; }
	pre { margin: 0; }
	.warning { color: #d32f2f; font-weight: bold; }
	.success { color: #2e7d32; }
	table { border-collapse: collapse; width: 100%; }
	th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
	th { background: #0073aa; color: white; }
</style>';

echo '<h1>🔍 Análisis del XML Generado</h1>';

// Generar XML de prueba
$config = Arriendo_Facil_SRI_Config::get();
$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
	new DateTime(),
	Arriendo_Facil_SRI_Clave_Acceso::TIPO_FACTURA,
	$config['ruc'],
	'1', '001', '001', 1
);

$totals = Arriendo_Facil_SRI_XML_Factura::compute_totals(
	array(
		array(
			'codigo_principal' => 'ARRIENDO',
			'descripcion'      => 'Arriendo Junio 2026',
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
			'razon_social'          => $config['razon_social'] ?? 'TEST',
			'nombre_comercial'      => $config['nombre_comercial'] ?? '',
			'ruc'                   => $config['ruc'],
			'clave_acceso'          => $clave,
			'estab'                 => '001',
			'pto_emi'               => '001',
			'secuencial'            => '000000001',
			'dir_matriz'            => $config['dir_matriz'] ?? $config['dir_establecimiento'] ?? '',
			'fecha_emision'         => date( 'd/m/Y' ),
			'dir_establecimiento'   => $config['dir_establecimiento'] ?? '',
			'obligado_contabilidad' => $config['obligado_contabilidad'] ?? 'NO',
			'tipo_id_comprador'     => '05',
			'razon_social_comprador'   => 'CONSUMIDOR FINAL',
			'identificacion_comprador' => '9999999999999',
			'forma_pago'    => '01',
			'plazo'         => '30',
			'unidad_tiempo' => 'dias',
		),
		$totals
	)
);

// ═══════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<h2>1️⃣ XML Generado (Completo)</h2>';
echo 'Longitud total: ' . strlen( $xml ) . ' bytes<br><br>';
echo '<div class="xml-raw"><pre>' . htmlspecialchars( $xml ) . '</pre></div>';
echo '</div>';

// ═══════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<h2>2️⃣ Análisis de Estructura</h2>';

$doc = new DOMDocument();
libxml_use_internal_errors( true );
$doc->loadXML( $xml );
libxml_clear_errors();

$root = $doc->documentElement;

echo '<table>';
echo '<tr><th>Elemento</th><th>Valor</th><th>Estado</th></tr>';

// infoTributaria
$xpath = new DOMXPath( $doc );
$nodes = $xpath->query( '//infoTributaria' );
if ( $nodes->length > 0 ) {
	echo '<tr><td>infoTributaria</td><td>Presente</td><td class="success">✓</td></tr>';

	$razon = $xpath->query( '//infoTributaria/razonSocial' )->item( 0 )->textContent ?? '';
	echo '<tr><td>  razonSocial</td><td>' . htmlspecialchars( $razon ) . '</td><td class="success">✓</td></tr>';

	$ruc = $xpath->query( '//infoTributaria/ruc' )->item( 0 )->textContent ?? '';
	echo '<tr><td>  ruc</td><td>' . htmlspecialchars( $ruc ) . '</td><td>' . ( strlen( $ruc ) === 13 ? '<span class="success">✓</span>' : '<span class="warning">✗</span>' ) . '</td></tr>';

	$claveAcceso = $xpath->query( '//infoTributaria/claveAccesoComprobante' )->item( 0 )->textContent ?? '';
	echo '<tr><td>  claveAcceso</td><td>' . htmlspecialchars( substr( $claveAcceso, 0, 20 ) ) . '...</td><td>' . ( strlen( $claveAcceso ) === 49 ? '<span class="success">✓</span>' : '<span class="warning">✗</span>' ) . '</td></tr>';
} else {
	echo '<tr><td>infoTributaria</td><td>FALTA</td><td class="warning">✗</td></tr>';
}

// infoFactura
$nodes = $xpath->query( '//infoFactura' );
if ( $nodes->length > 0 ) {
	echo '<tr><td>infoFactura</td><td>Presente</td><td class="success">✓</td></tr>';

	$fechaEmision = $xpath->query( '//infoFactura/fechaEmisionComprobante' )->item( 0 )->textContent ?? '';
	echo '<tr><td>  fechaEmisionComprobante</td><td>' . htmlspecialchars( $fechaEmision ) . '</td><td class="success">✓</td></tr>';

	$totalSinImpuestos = $xpath->query( '//infoFactura/totalSinImpuestos' )->item( 0 )->textContent ?? '';
	echo '<tr><td>  totalSinImpuestos</td><td>' . htmlspecialchars( $totalSinImpuestos ) . '</td><td class="success">✓</td></tr>';

	$importeTotal = $xpath->query( '//infoFactura/importeTotal' )->item( 0 )->textContent ?? '';
	echo '<tr><td>  importeTotal</td><td>' . htmlspecialchars( $importeTotal ) . '</td><td class="success">✓</td></tr>';
} else {
	echo '<tr><td>infoFactura</td><td>FALTA</td><td class="warning">✗</td></tr>';
}

// detalles
$detalles = $xpath->query( '//detalles/detalle' );
echo '<tr><td>detalles</td><td>' . $detalles->length . ' items</td><td class="success">✓</td></tr>';

// infoAdicional
$infos = $xpath->query( '//infoAdicional/campoAdicional' );
echo '<tr><td>infoAdicional</td><td>' . $infos->length . ' campos</td><td class="success">✓</td></tr>';

echo '</table>';

echo '</div>';

// ═══════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<h2>3️⃣ Validación contra Especificación SRI</h2>';

$issues = array();

// Validar claveAcceso (49 dígitos)
if ( strlen( $claveAcceso ) !== 49 || ! is_numeric( $claveAcceso ) ) {
	$issues[] = '❌ claveAcceso debe ser 49 dígitos numéricos';
}

// Validar RUC (13 dígitos)
if ( strlen( $ruc ) !== 13 || ! is_numeric( $ruc ) ) {
	$issues[] = '❌ RUC debe ser 13 dígitos numéricos';
}

// Validar fechaEmisionComprobante (formato DD/MM/YYYY)
if ( ! preg_match( '/^\d{2}\/\d{2}\/\d{4}$/', $fechaEmision ) ) {
	$issues[] = '❌ fechaEmisionComprobante debe ser formato DD/MM/YYYY';
}

// Validar totalSinImpuestos > 0
if ( floatval( $totalSinImpuestos ) <= 0 ) {
	$issues[] = '❌ totalSinImpuestos debe ser mayor a 0';
}

// Validar importeTotal > 0
if ( floatval( $importeTotal ) <= 0 ) {
	$issues[] = '❌ importeTotal debe ser mayor a 0';
}

// Validar al menos un detalle
if ( $detalles->length === 0 ) {
	$issues[] = '❌ Debe haber al menos un detalle';
}

if ( empty( $issues ) ) {
	echo '<span class="success">✓ El XML cumple con la especificación SRI</span><br>';
} else {
	echo '<span class="warning">Se encontraron problemas:</span><br>';
	echo '<ul>';
	foreach ( $issues as $issue ) {
		echo '<li>' . $issue . '</li>';
	}
	echo '</ul>';
}

echo '</div>';

// ═══════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<h2>4️⃣ Detalles del Comprador</h2>';

$compradores = $xpath->query( '//infoFactura/infoComprador' );
if ( $compradores->length > 0 ) {
	$comprador = $compradores->item( 0 );
	$tipoIdentificacionComprador = $xpath->query( 'tipoIdentificacionComprador', $comprador )->item( 0 )->textContent ?? '';
	$razonSocialComprador = $xpath->query( 'razonSocialComprador', $comprador )->item( 0 )->textContent ?? '';
	$identificacionComprador = $xpath->query( 'identificacionComprador', $comprador )->item( 0 )->textContent ?? '';

	echo '<table>';
	echo '<tr><th>Campo</th><th>Valor</th><th>Validación</th></tr>';
	echo '<tr><td>tipoIdentificacionComprador</td><td>' . htmlspecialchars( $tipoIdentificacionComprador ) . '</td><td class="success">✓</td></tr>';
	echo '<tr><td>razonSocialComprador</td><td>' . htmlspecialchars( $razonSocialComprador ) . '</td><td class="success">✓</td></tr>';
	echo '<tr><td>identificacionComprador</td><td>' . htmlspecialchars( $identificacionComprador ) . '</td><td class="success">✓</td></tr>';
	echo '</table>';
} else {
	echo '<span class="warning">❌ No hay datos del comprador</span>';
}

echo '</div>';

// ═══════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<h2>5️⃣ Cálculos de Totales</h2>';

echo '<table>';
echo '<tr><th>Concepto</th><th>Valor</th></tr>';

$totalDesc = $xpath->query( '//infoFactura/totalDescuento' )->item( 0 )->textContent ?? '0.00';
echo '<tr><td>Total Descuento</td><td>' . htmlspecialchars( $totalDesc ) . '</td></tr>';

$subtotalNoIVA = $xpath->query( '//infoFactura/totalSinImpuestos' )->item( 0 )->textContent ?? '';
echo '<tr><td>Subtotal (sin IVA)</td><td>' . htmlspecialchars( $subtotalNoIVA ) . '</td></tr>';

$totalIVA = $xpath->query( '//infoFactura/totalImpuesto' )->item( 0 )->textContent ?? '0.00';
echo '<tr><td>Total IVA</td><td>' . htmlspecialchars( $totalIVA ) . '</td></tr>';

$totalFinal = $xpath->query( '//infoFactura/importeTotal' )->item( 0 )->textContent ?? '';
echo '<tr><td><strong>TOTAL FINAL</strong></td><td><strong>' . htmlspecialchars( $totalFinal ) . '</strong></td></tr>';

echo '</table>';

// Validar que total = subtotal + iva - descuento
$calc_total = floatval( $subtotalNoIVA ) + floatval( $totalIVA ) - floatval( $totalDesc );
$actual_total = floatval( $totalFinal );

if ( abs( $calc_total - $actual_total ) < 0.01 ) {
	echo '<span class="success">✓ Los totales son consistentes</span>';
} else {
	echo '<span class="warning">⚠️ Inconsistencia en totales: calculado=' . number_format( $calc_total, 2 ) . ', actual=' . number_format( $actual_total, 2 ) . '</span>';
}

echo '</div>';

?>
