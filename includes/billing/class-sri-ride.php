<?php
/**
 * SRI RIDE (PDF representation) generator.
 *
 * Renders the "Representación Impresa del Documento Electrónico" of an
 * authorized factura as a one-page A4 PDF without external libraries: issuer
 * and authorization boxes, Code 128 barcode of the access key, buyer data,
 * line items, additional info, payment form and tax totals.
 *
 * @package Arriendo_Facil\Billing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_SRI_Ride
 */
class Arriendo_Facil_SRI_Ride {

	const PAGE_W = 595;
	const PAGE_H = 842;
	const MARGIN = 36;

	/** Helvetica glyph widths (1/1000 em) for ASCII 32..126. */
	const W_REGULAR = array(
		278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
		556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
		1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
		667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
		333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
		556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
	);

	/** Helvetica-Bold glyph widths (1/1000 em) for ASCII 32..126. */
	const W_BOLD = array(
		278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
		556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
		975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
		667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
		333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
		611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
	);

	/** Accented letters measured with their base glyph. */
	const ACCENT_MAP = array(
		'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
		'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
	);

	/** Code 128 bar/space widths, symbol values 0..106 (106 = stop). */
	const CODE128 = array(
		'212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
		'221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
		'221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
		'212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
		'231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
		'231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
		'314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
		'112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
		'111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
		'214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
		'114131', '311141', '411131', '211412', '211214', '211232', '2331112',
	);

	/** @var string Content stream being built. */
	private $ops = '';

	// ─── Public API ──────────────────────────────────────────────────────────

	/**
	 * Generates a RIDE PDF file on disk.
	 *
	 * @param array $data RIDE data.
	 * @return array|WP_Error { path, filename }
	 */
	public function generate( array $data ) {
		$dir = $this->ride_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$numero = isset( $data['numero_comprobante'] ) ? (string) $data['numero_comprobante'] : '000-000-000000000';
		$clave  = isset( $data['clave_acceso'] ) ? (string) $data['clave_acceso'] : '';
		$safe   = preg_replace( '/[^0-9\-]/', '', $numero );
		if ( '' === $safe ) {
			$safe = '000-000-000000000';
		}

		$filename = sprintf( 'RIDE_%s_%s.pdf', $safe, substr( preg_replace( '/\D/', '', $clave ), 0, 10 ) );
		$path     = trailingslashit( $dir ) . $filename;

		$pdf_binary = $this->build_pdf_binary( $data );
		$ok         = file_put_contents( $path, $pdf_binary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $ok ) {
			return new WP_Error( 'ride_write_failed', __( 'No se pudo escribir el archivo RIDE en disco.', 'arriendo-facil' ) );
		}

		return array(
			'path'     => $path,
			'filename' => $filename,
		);
	}

	/**
	 * Returns (and creates if needed) the local folder used for RIDE files.
	 *
	 * @return string|WP_Error
	 */
	public function ride_dir() {
		$base = '';

		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();
			if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				$base = (string) $uploads['basedir'];
			}
		}

		if ( '' === $base ) {
			$base = WP_CONTENT_DIR;
		}

		$dir = trailingslashit( $base ) . 'af-rides';
		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'ride_dir_failed', __( 'No se pudo crear el directorio para RIDE.', 'arriendo-facil' ) );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	/**
	 * Extracts RIDE data from a (signed) factura XML, so a RIDE can always be
	 * rebuilt faithfully from the document that was sent to the SRI.
	 *
	 * @param string $xml Factura XML.
	 * @return array Same keys the XML builder uses, plus info_adicional.
	 */
	public static function data_from_xml( string $xml ): array {
		$dom = new DOMDocument();
		if ( '' === trim( $xml ) || ! @$dom->loadXML( $xml, LIBXML_NONET ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array();
		}
		$xp = new DOMXPath( $dom );
		$v  = static function ( string $query ) use ( $xp ): string {
			return trim( (string) $xp->evaluate( 'string(' . $query . ')' ) );
		};

		$items = array();
		foreach ( $xp->query( '//detalles/detalle' ) as $node ) {
			$f = static function ( string $tag ) use ( $xp, $node ): string {
				return trim( (string) $xp->evaluate( 'string(' . $tag . ')', $node ) );
			};
			$items[] = array(
				'codigo_principal'          => $f( 'codigoPrincipal' ),
				'descripcion'               => $f( 'descripcion' ),
				'cantidad'                  => (float) $f( 'cantidad' ),
				'precio_unitario'           => (float) $f( 'precioUnitario' ),
				'descuento'                 => (float) $f( 'descuento' ),
				'precio_total_sin_impuesto' => (float) $f( 'precioTotalSinImpuesto' ),
				'iva_tarifa'                => (float) $f( 'impuestos/impuesto/tarifa' ),
			);
		}

		$info = array();
		foreach ( $xp->query( '//infoAdicional/campoAdicional' ) as $node ) {
			$info[ (string) $node->getAttribute( 'nombre' ) ] = trim( (string) $node->textContent );
		}

		return array(
			'ambiente_label'           => '2' === $v( '//infoTributaria/ambiente' ) ? 'PRODUCCION' : 'PRUEBAS',
			'tipo_emision'             => $v( '//infoTributaria/tipoEmision' ),
			'razon_social'             => $v( '//infoTributaria/razonSocial' ),
			'nombre_comercial'         => $v( '//infoTributaria/nombreComercial' ),
			'ruc'                      => $v( '//infoTributaria/ruc' ),
			'clave_acceso'             => $v( '//infoTributaria/claveAcceso' ),
			'estab'                    => $v( '//infoTributaria/estab' ),
			'pto_emi'                  => $v( '//infoTributaria/ptoEmi' ),
			'secuencial'               => $v( '//infoTributaria/secuencial' ),
			'dir_matriz'               => $v( '//infoTributaria/dirMatriz' ),
			'fecha_emision'            => $v( '//infoFactura/fechaEmision' ),
			'dir_establecimiento'      => $v( '//infoFactura/dirEstablecimiento' ),
			'obligado_contabilidad'    => $v( '//infoFactura/obligadoContabilidad' ),
			'tipo_id_comprador'        => $v( '//infoFactura/tipoIdentificacionComprador' ),
			'razon_social_comprador'   => $v( '//infoFactura/razonSocialComprador' ),
			'identificacion_comprador' => $v( '//infoFactura/identificacionComprador' ),
			'dir_comprador'            => $v( '//infoFactura/direccionComprador' ),
			'total_sin_impuestos'      => (float) $v( '//infoFactura/totalSinImpuestos' ),
			'total_descuento'          => (float) $v( '//infoFactura/totalDescuento' ),
			'iva_codigo_porcentaje'    => $v( '//infoFactura/totalConImpuestos/totalImpuesto[1]/codigoPorcentaje' ),
			'iva_base_imponible'       => (float) $v( '//infoFactura/totalConImpuestos/totalImpuesto[1]/baseImponible' ),
			'iva_valor'                => (float) $v( '//infoFactura/totalConImpuestos/totalImpuesto[1]/valor' ),
			'iva_tarifa'               => isset( $items[0] ) ? (float) $items[0]['iva_tarifa'] : 0.0,
			'importe_total'            => (float) $v( '//infoFactura/importeTotal' ),
			'forma_pago'               => $v( '//infoFactura/pagos/pago[1]/formaPago' ),
			'items'                    => $items,
			'info_adicional'           => $info,
		);
	}

	// ─── PDF composition ─────────────────────────────────────────────────────

	/**
	 * Builds the single-page PDF binary.
	 *
	 * @param array $data Invoice data.
	 * @return string Raw PDF bytes.
	 */
	public function build_pdf_binary( array $data ): string {
		$this->ops = '';
		$this->compose( $data );

		$content = $this->ops;
		$info    = '(RIDE - FACTURA ELECTRONICA ' . $this->pdf_escape( $this->to_latin1( (string) ( $data['numero_comprobante'] ?? '' ) ) ) . ')';

		$objects   = array();
		$objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
		$objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
		$objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>\nendobj\n";
		$objects[] = "4 0 obj\n<< /Length " . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream\nendobj\n";
		$objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
		$objects[] = "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";
		$objects[] = "7 0 obj\n<< /Title " . $info . " /Producer (Arriendo Facil) >>\nendobj\n";

		$pdf     = "%PDF-1.4\n";
		$offsets = array( 0 );
		foreach ( $objects as $obj ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= $obj;
		}

		$count       = count( $objects ) + 1;
		$xref_offset = strlen( $pdf );
		$pdf        .= "xref\n0 {$count}\n0000000000 65535 f \n";
		for ( $i = 1; $i < $count; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n<< /Size {$count} /Root 1 0 R /Info 7 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF";

		return $pdf;
	}

	/**
	 * Lays out the whole page.
	 *
	 * @param array $d Invoice data.
	 */
	private function compose( array $d ): void {
		$m    = self::MARGIN;
		$w    = self::PAGE_W - 2 * $m;
		$top  = $m;
		$amb  = strtoupper( (string) ( $d['ambiente_label'] ?? '' ) );
		$prod = false !== strpos( $amb, 'PRODUCCI' );

		if ( ! $prod && '' !== $amb ) {
			$this->rect( $m, $top, $w, 16, array( 1, 0.93, 0.7 ), true );
			$this->text_center( $m + $w / 2, $top + 11.5, 'DOCUMENTO DE PRUEBAS - SIN VALIDEZ TRIBUTARIA', 8.5, true, array( 0.45, 0.3, 0 ) );
			$top += 22;
		}

		$ruc    = (string) ( $d['ruc'] ?? '' );
		$numero = (string) ( $d['numero_comprobante'] ?? '' );
		if ( '' === $numero && ! empty( $d['estab'] ) ) {
			$numero = $d['estab'] . '-' . $d['pto_emi'] . '-' . $d['secuencial'];
		}
		$clave = (string) ( $d['clave_acceso'] ?? '' );

		// ── Cuadro del emisor (izquierda) ───────────────────────────────────
		$box_h  = 196;
		$left_w = 262;
		$this->rect( $m, $top, $left_w, $box_h, null, false );
		$y = $top + 22;
		foreach ( $this->wrap( (string) ( $d['razon_social'] ?? '' ), $left_w - 24, 12, true ) as $line ) {
			$this->text( $m + 12, $y, $line, 12, true );
			$y += 15;
		}
		if ( ! empty( $d['nombre_comercial'] ) ) {
			foreach ( $this->wrap( (string) $d['nombre_comercial'], $left_w - 24, 9, true ) as $line ) {
				$this->text( $m + 12, $y, $line, 9, true, array( 0.35, 0.35, 0.35 ) );
				$y += 12;
			}
		}
		$y         += 8;
		$dir_matriz = (string) ( $d['dir_matriz'] ?? '' );
		$dir_estab  = (string) ( $d['dir_establecimiento'] ?? '' );
		$y          = $this->label_value( $m + 12, $y, $left_w - 24, 'Dirección Matriz', $dir_matriz );
		$y          = $this->label_value( $m + 12, $y, $left_w - 24, 'Dirección Sucursal', '' !== $dir_estab ? $dir_estab : $dir_matriz );
		$obligado   = strtoupper( (string) ( $d['obligado_contabilidad'] ?? 'NO' ) );
		$this->label_value( $m + 12, $y, $left_w - 24, 'OBLIGADO A LLEVAR CONTABILIDAD', '' !== $obligado ? $obligado : 'NO' );

		// ── Cuadro de autorización (derecha) ────────────────────────────────
		$rx = $m + $left_w + 10;
		$rw = $w - $left_w - 10;
		$this->rect( $rx, $top, $rw, $box_h, null, false );
		$y = $top + 20;
		$this->text( $rx + 10, $y, 'R.U.C.: ' . $ruc, 11, true );
		$y += 22;
		$this->text( $rx + 10, $y, 'FACTURA', 16, true );
		$y += 17;
		$this->text( $rx + 10, $y, 'No. ' . $numero, 10, true );
		$y += 17;
		$this->text( $rx + 10, $y, 'NÚMERO DE AUTORIZACIÓN', 7.5, true, array( 0.35, 0.35, 0.35 ) );
		$y += 10;
		$this->text( $rx + 10, $y, (string) ( $d['numero_autorizacion'] ?? '' ), 7.5, false );
		$y += 14;
		$this->text( $rx + 10, $y, 'FECHA Y HORA DE AUTORIZACIÓN:', 7.5, true, array( 0.35, 0.35, 0.35 ) );
		$this->text( $rx + 10 + $this->text_width( 'FECHA Y HORA DE AUTORIZACIÓN: ', 7.5, true ), $y, $this->format_datetime( (string) ( $d['fecha_autorizacion'] ?? '' ) ), 7.5, false );
		$y += 13;
		$this->text( $rx + 10, $y, 'AMBIENTE:', 7.5, true, array( 0.35, 0.35, 0.35 ) );
		$this->text( $rx + 10 + $this->text_width( 'AMBIENTE: ', 7.5, true ), $y, '' !== $amb ? $amb : 'PRUEBAS', 7.5, false );
		$y += 12;
		$this->text( $rx + 10, $y, 'EMISIÓN:', 7.5, true, array( 0.35, 0.35, 0.35 ) );
		$this->text( $rx + 10 + $this->text_width( 'EMISIÓN: ', 7.5, true ), $y, 'NORMAL', 7.5, false );
		$y += 14;
		$this->text( $rx + 10, $y, 'CLAVE DE ACCESO', 7.5, true, array( 0.35, 0.35, 0.35 ) );
		$y    += 6;
		$bar_h = 34;
		$this->barcode128( $rx + 10, $y, $rw - 20, $bar_h, $clave );
		$y += $bar_h + 10;
		$this->text_center( $rx + $rw / 2, $y, $clave, 7, false );

		$top += $box_h + 10;

		// ── Comprador ───────────────────────────────────────────────────────
		$buyer_h = 64;
		$this->rect( $m, $top, $w, $buyer_h, null, false );
		$y   = $top + 16;
		$col = ( $w - 24 ) / 2;
		$this->label_value( $m + 12, $y, $w - 24, 'Razón social / Nombres y apellidos', (string) ( $d['razon_social_comprador'] ?? '' ) );
		$y       += 24;
		$id_label = $this->id_type_label( (string) ( $d['tipo_id_comprador'] ?? '' ) );
		$this->label_value( $m + 12, $y, $col - 8, $id_label, (string) ( $d['identificacion_comprador'] ?? '' ) );
		$this->label_value( $m + 12 + $col, $y, $col, 'Fecha de emisión', (string) ( $d['fecha_emision'] ?? '' ) );
		$top += $buyer_h;
		if ( ! empty( $d['dir_comprador'] ) ) {
			$this->rect( $m, $top, $w, 24, null, false );
			$this->label_value( $m + 12, $top + 16, $w - 24, 'Dirección', (string) $d['dir_comprador'] );
			$top += 24;
		}
		$top += 12;

		// ── Detalle ─────────────────────────────────────────────────────────
		$cols = array(
			array( 'Cód. principal', 62, 'L' ),
			array( 'Cant.', 38, 'R' ),
			array( 'Descripción', 238, 'L' ),
			array( 'P. unitario', 62, 'R' ),
			array( 'Descuento', 55, 'R' ),
			array( 'Precio total', 68, 'R' ),
		);
		$this->rect( $m, $top, $w, 20, array( 0.9, 0.92, 0.92 ), true );
		$this->rect( $m, $top, $w, 20, null, false );
		$x = $m;
		foreach ( $cols as $c ) {
			$this->cell_text( $x, $top + 13.5, $c[1], $c[0], 8, true, $c[2] );
			$x += $c[1];
		}
		$row_top = $top + 20;
		$items   = isset( $d['items'] ) && is_array( $d['items'] ) ? $d['items'] : array();
		foreach ( array_slice( $items, 0, 24 ) as $item ) {
			$desc_lines = $this->wrap( (string) ( $item['descripcion'] ?? '' ), $cols[2][1] - 10, 8.5, false );
			$row_h      = max( 20, count( $desc_lines ) * 11 + 9 );
			$x          = $m;
			$vals       = array(
				(string) ( $item['codigo_principal'] ?? '' ),
				$this->num( (float) ( $item['cantidad'] ?? 1 ), 2 ),
				'',
				$this->num( (float) ( $item['precio_unitario'] ?? 0 ), 2 ),
				$this->num( (float) ( $item['descuento'] ?? 0 ), 2 ),
				$this->num( (float) ( $item['precio_total_sin_impuesto'] ?? 0 ), 2 ),
			);
			foreach ( $cols as $i => $c ) {
				if ( 2 === $i ) {
					$yy = $row_top + 13;
					foreach ( $desc_lines as $line ) {
						$this->text( $x + 5, $yy, $line, 8.5, false );
						$yy += 11;
					}
				} else {
					$this->cell_text( $x, $row_top + 13, $c[1], $vals[ $i ], 8.5, false, $c[2] );
				}
				$x += $c[1];
			}
			$row_top += $row_h;
			$this->line( $m, $row_top, $m + $w, $row_top, array( 0.82, 0.85, 0.85 ) );
		}
		$this->rect( $m, $top, $w, $row_top - $top, null, false );
		$top = $row_top + 14;

		// ── Información adicional + forma de pago (izq.) y totales (der.) ──
		$left_w      = 290;
		$ty          = $top;
		$extra       = isset( $d['info_adicional'] ) && is_array( $d['info_adicional'] ) ? $d['info_adicional'] : array();
		$extra_lines = array();
		foreach ( $extra as $name => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$extra_lines[] = array( (string) $name, (string) $value );
			}
		}
		$this->rect( $m, $ty, $left_w, 18, array( 0.9, 0.92, 0.92 ), true );
		$this->rect( $m, $ty, $left_w, 18, null, false );
		$this->text( $m + 8, $ty + 12.5, 'Información adicional', 8.5, true );
		$ey     = $ty + 18;
		$body_h = 12;
		foreach ( $extra_lines as $row ) {
			$body_h += 12 * count( $this->wrap( ucfirst( $row[0] ) . ': ' . $row[1], $left_w - 16, 8.5, false ) );
		}
		$body_h = max( 30, $body_h );
		$this->rect( $m, $ey, $left_w, $body_h, null, false );
		$yy = $ey + 14;
		foreach ( $extra_lines as $row ) {
			foreach ( $this->wrap( ucfirst( $row[0] ) . ': ' . $row[1], $left_w - 16, 8.5, false ) as $line ) {
				$this->text( $m + 8, $yy, $line, 8.5, false );
				$yy += 12;
			}
		}
		$py = $ey + $body_h + 10;
		$this->rect( $m, $py, $left_w, 18, array( 0.9, 0.92, 0.92 ), true );
		$this->rect( $m, $py, $left_w, 18, null, false );
		$this->text( $m + 8, $py + 12.5, 'Forma de pago', 8.5, true );
		$this->text( $m + $left_w - 8 - $this->text_width( 'Valor', 8.5, true ), $py + 12.5, 'Valor', 8.5, true );
		$this->rect( $m, $py + 18, $left_w, 24, null, false );
		$total = (float) ( $d['importe_total'] ?? $d['total'] ?? 0 );
		$this->text( $m + 8, $py + 34, $this->payment_label( (string) ( $d['forma_pago'] ?? '' ) ), 7.5, false );
		$this->text( $m + $left_w - 8 - $this->text_width( $this->num( $total, 2 ), 8.5, false ), $py + 34, $this->num( $total, 2 ), 8.5, false );

		$tarifa   = isset( $d['iva_tarifa'] ) ? (float) $d['iva_tarifa'] : 0.0;
		$sin_imp  = (float) ( $d['total_sin_impuestos'] ?? ( ( $d['subtotal_0'] ?? 0 ) + ( $d['subtotal_iva'] ?? 0 ) ) );
		$base_iva = isset( $d['iva_base_imponible'] ) && $tarifa > 0 ? (float) $d['iva_base_imponible'] : (float) ( $d['subtotal_iva'] ?? 0 );
		$sub_0    = $tarifa > 0 ? max( 0.0, $sin_imp - $base_iva ) : $sin_imp;
		$rate     = $tarifa > 0 ? $tarifa : 15.0;
		$rows     = array(
			array( 'SUBTOTAL ' . $this->num( $rate, 0 ) . '%', $base_iva, false ),
			array( 'SUBTOTAL 0%', $sub_0, false ),
			array( 'SUBTOTAL SIN IMPUESTOS', $sin_imp, false ),
			array( 'TOTAL DESCUENTO', (float) ( $d['total_descuento'] ?? 0 ), false ),
			array( 'IVA ' . $this->num( $rate, 0 ) . '%', (float) ( $d['iva_valor'] ?? 0 ), false ),
			array( 'VALOR TOTAL', $total, true ),
		);
		$tx = $m + $left_w + 14;
		$tw = $w - $left_w - 14;
		foreach ( $rows as $r ) {
			if ( $r[2] ) {
				$this->rect( $tx, $ty, $tw, 22, array( 0.9, 0.92, 0.92 ), true );
			}
			$this->rect( $tx, $ty, $tw, 22, null, false );
			$this->text( $tx + 8, $ty + 15, $r[0], 8.5, (bool) $r[2] );
			$val = $this->num( (float) $r[1], 2 );
			$this->text( $tx + $tw - 8 - $this->text_width( $val, 9, (bool) $r[2] ), $ty + 15, $val, 9, (bool) $r[2] );
			$ty += 22;
		}

		$this->text_center( self::PAGE_W / 2, self::PAGE_H - 28, 'Representación impresa del comprobante electrónico autorizado por el SRI · Generado con Arriendo Fácil', 7, false, array( 0.5, 0.5, 0.5 ) );
	}

	// ─── Drawing primitives (y measured from the top of the page) ────────────

	private function py( float $top ): float {
		return self::PAGE_H - $top;
	}

	private function rect( float $x, float $top, float $w, float $h, ?array $fill, bool $filled = false ): void {
		if ( $filled && $fill ) {
			$this->ops .= sprintf( "%.3F %.3F %.3F rg\n%.2F %.2F %.2F %.2F re f\n0 g\n", $fill[0], $fill[1], $fill[2], $x, $this->py( $top + $h ), $w, $h );
			return;
		}
		$this->ops .= sprintf( "0.6 G 0.7 w\n%.2F %.2F %.2F %.2F re S\n", $x, $this->py( $top + $h ), $w, $h );
	}

	private function line( float $x1, float $top1, float $x2, float $top2, array $color ): void {
		$this->ops .= sprintf( "%.3F %.3F %.3F RG 0.5 w\n%.2F %.2F m %.2F %.2F l S\n", $color[0], $color[1], $color[2], $x1, $this->py( $top1 ), $x2, $this->py( $top2 ) );
	}

	private function text( float $x, float $top, string $s, float $size, bool $bold, array $color = array( 0, 0, 0 ) ): void {
		if ( '' === $s ) {
			return;
		}
		$this->ops .= sprintf(
			"BT %.3F %.3F %.3F rg /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n",
			$color[0],
			$color[1],
			$color[2],
			$bold ? 'F2' : 'F1',
			$size,
			$x,
			$this->py( $top ),
			$this->pdf_escape( $this->to_latin1( $s ) )
		);
	}

	private function text_center( float $cx, float $top, string $s, float $size, bool $bold, array $color = array( 0, 0, 0 ) ): void {
		$this->text( $cx - $this->text_width( $s, $size, $bold ) / 2, $top, $s, $size, $bold, $color );
	}

	private function cell_text( float $x, float $top, float $width, string $s, float $size, bool $bold, string $align ): void {
		if ( 'R' === $align ) {
			$this->text( $x + $width - 5 - $this->text_width( $s, $size, $bold ), $top, $s, $size, $bold );
			return;
		}
		$this->text( $x + 5, $top, $s, $size, $bold );
	}

	/**
	 * Draws "Label: value" (label bold, value wrapped) and returns the next y.
	 */
	private function label_value( float $x, float $top, float $width, string $label, string $value ): float {
		$this->text( $x, $top, $label . ':', 8, true, array( 0.35, 0.35, 0.35 ) );
		$lw    = $this->text_width( $label . ': ', 8, true );
		$lines = $this->wrap( $value, max( 40, $width - $lw ), 8.5, false );
		$y     = $top;
		foreach ( $lines as $line ) {
			$this->text( $x + $lw, $y, $line, 8.5, false );
			$y += 11;
		}
		return $y + 4;
	}

	/**
	 * Code 128 barcode as filled bars.
	 */
	private function barcode128( float $x, float $top, float $max_w, float $h, string $digits ): void {
		$symbols = $this->code128_symbols( $digits );
		if ( empty( $symbols ) ) {
			return;
		}
		$patterns = array();
		$modules  = 0;
		foreach ( $symbols as $s ) {
			$p          = self::CODE128[ $s ];
			$patterns[] = $p;
			$modules   += array_sum( array_map( 'intval', str_split( $p ) ) );
		}
		$mw         = min( 0.9, $max_w / $modules );
		$cx         = $x + ( $max_w - $modules * $mw ) / 2;
		$this->ops .= "0 g\n";
		foreach ( $patterns as $p ) {
			$bar = true;
			foreach ( str_split( $p ) as $width ) {
				$wd = (int) $width * $mw;
				if ( $bar ) {
					$this->ops .= sprintf( "%.3F %.2F %.3F %.2F re f\n", $cx, $this->py( $top + $h ), $wd, $h );
				}
				$cx += $wd;
				$bar = ! $bar;
			}
		}
	}

	/**
	 * Code 128 symbol values: set C for digit pairs, set B for a trailing odd digit.
	 *
	 * @return int[]
	 */
	public function code128_symbols( string $digits ): array {
		$digits = preg_replace( '/\D/', '', $digits );
		if ( '' === $digits ) {
			return array();
		}
		$odd     = 1 === strlen( $digits ) % 2;
		$pairs   = $odd ? substr( $digits, 0, -1 ) : $digits;
		$symbols = array( 105 );
		if ( '' !== $pairs ) {
			foreach ( str_split( $pairs, 2 ) as $pair ) {
				$symbols[] = (int) $pair;
			}
		}
		if ( $odd ) {
			$symbols[] = 100; // CODE B.
			$symbols[] = ord( substr( $digits, -1 ) ) - 32;
		}
		$sum = $symbols[0];
		foreach ( $symbols as $i => $s ) {
			if ( $i > 0 ) {
				$sum += $s * $i;
			}
		}
		$symbols[] = $sum % 103;
		$symbols[] = 106;
		return $symbols;
	}

	// ─── Text helpers ────────────────────────────────────────────────────────

	private function text_width( string $s, float $size, bool $bold ): float {
		$table = $bold ? self::W_BOLD : self::W_REGULAR;
		$l1    = $this->to_latin1( strtr( $s, self::ACCENT_MAP ) );
		$total = 0;
		$len   = strlen( $l1 );
		for ( $i = 0; $i < $len; $i++ ) {
			$c      = ord( $l1[ $i ] );
			$total += ( $c >= 32 && $c <= 126 ) ? $table[ $c - 32 ] : ( $bold ? 611 : 556 );
		}
		return $total * $size / 1000;
	}

	/**
	 * Greedy word wrap by rendered width.
	 *
	 * @return string[]
	 */
	private function wrap( string $text, float $width, float $size, bool $bold ): array {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( '' === $text ) {
			return array( '' );
		}
		$lines = array();
		$line  = '';
		foreach ( explode( ' ', $text ) as $word ) {
			while ( $this->text_width( $word, $size, $bold ) > $width && mb_strlen( $word ) > 1 ) {
				$cut = mb_strlen( $word );
				while ( $cut > 1 && $this->text_width( mb_substr( $word, 0, $cut ), $size, $bold ) > $width ) {
					--$cut;
				}
				if ( '' !== $line ) {
					$lines[] = $line;
					$line    = '';
				}
				$lines[] = mb_substr( $word, 0, $cut );
				$word    = mb_substr( $word, $cut );
			}
			$try = '' === $line ? $word : $line . ' ' . $word;
			if ( '' !== $line && $this->text_width( $try, $size, $bold ) > $width ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = $try;
			}
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return $lines;
	}

	private function num( float $n, int $decimals ): string {
		return number_format( $n, $decimals, '.', '' );
	}

	private function id_type_label( string $type ): string {
		$labels = array(
			'04' => 'RUC',
			'05' => 'Cédula',
			'06' => 'Pasaporte',
			'07' => 'Identificación',
			'08' => 'Identificación del exterior',
		);
		return $labels[ $type ] ?? 'Identificación';
	}

	private function payment_label( string $code ): string {
		$labels = array(
			'01' => 'SIN UTILIZACION DEL SISTEMA FINANCIERO',
			'15' => 'COMPENSACION DE DEUDAS',
			'16' => 'TARJETA DE DEBITO',
			'17' => 'DINERO ELECTRONICO',
			'18' => 'TARJETA PREPAGO',
			'19' => 'TARJETA DE CREDITO',
			'20' => 'OTROS CON UTILIZACION DEL SISTEMA FINANCIERO',
			'21' => 'ENDOSO DE TITULOS',
		);
		return $labels[ $code ] ?? ( '' !== $code ? $code : '-' );
	}

	private function format_datetime( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		try {
			return ( new DateTime( $value ) )->format( 'd/m/Y H:i:s' );
		} catch ( \Exception $e ) {
			return $value;
		}
	}

	/**
	 * Escapes special PDF string characters.
	 */
	private function pdf_escape( string $text ): string {
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( '(', '\\(', $text );
		$text = str_replace( ')', '\\)', $text );
		return $text;
	}

	/**
	 * Converts UTF-8 text to Latin-1 for the core PDF fonts (WinAnsi).
	 */
	private function to_latin1( string $text ): string {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $text, 'ISO-8859-1', 'UTF-8' );
		}
		if ( function_exists( 'iconv' ) ) {
			$converted = iconv( 'UTF-8', 'ISO-8859-1//TRANSLIT', $text );
			if ( false !== $converted ) {
				return $converted;
			}
		}
		return preg_replace( '/[^\x20-\x7E]/', '?', $text );
	}
}
