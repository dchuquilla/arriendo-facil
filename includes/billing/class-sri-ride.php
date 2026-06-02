<?php
/**
 * SRI RIDE (PDF representation) generator.
 *
 * Generates a lightweight PDF file for authorized electronic invoices without
 * external PDF libraries. The output is a plain one-page summary document.
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
	 * Builds a minimal single-page PDF binary.
	 *
	 * @param array $data Invoice data.
	 * @return string Raw PDF bytes.
	 */
	public function build_pdf_binary( array $data ): string {
		$lines = $this->build_lines( $data );

		$content = "BT\n/F1 10 Tf\n50 800 Td\n";
		$first   = true;
		foreach ( $lines as $line ) {
			$chunks = $this->wrap_text( $line, 92 );
			foreach ( $chunks as $chunk ) {
				if ( ! $first ) {
					$content .= "0 -14 Td\n";
				}
				$content .= '(' . $this->pdf_escape( $this->to_latin1( $chunk ) ) . ") Tj\n";
				$first = false;
			}
		}
		$content .= "ET";

		$objects = array();
		$objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
		$objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
		$objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>\nendobj\n";
		$objects[] = "4 0 obj\n<< /Length " . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream\nendobj\n";
		$objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

		$pdf     = "%PDF-1.4\n";
		$offsets = array( 0 );

		foreach ( $objects as $obj ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= $obj;
		}

		$xref_offset = strlen( $pdf );
		$pdf        .= "xref\n0 6\n";
		$pdf        .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= 5; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}

		$pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF";

		return $pdf;
	}

	/**
	 * Creates text lines included in the RIDE.
	 *
	 * @param array $data Invoice data.
	 * @return array<int, string>
	 */
	private function build_lines( array $data ): array {
		$items   = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$lines   = array();
		$lines[] = 'RIDE - FACTURA ELECTRONICA';
		$lines[] = 'Arriendo Facil';
		$lines[] = ' '; // blank line
		$lines[] = 'RUC Emisor: ' . (string) ( $data['ruc'] ?? '' );
		$lines[] = 'Razon Social: ' . (string) ( $data['razon_social'] ?? '' );
		$lines[] = 'Comprobante: ' . (string) ( $data['numero_comprobante'] ?? '' );
		$lines[] = 'Clave de Acceso: ' . (string) ( $data['clave_acceso'] ?? '' );
		$lines[] = 'No. Autorizacion: ' . (string) ( $data['numero_autorizacion'] ?? '' );
		$lines[] = 'Fecha Autorizacion: ' . (string) ( $data['fecha_autorizacion'] ?? '' );
		$lines[] = 'Ambiente: ' . (string) ( $data['ambiente_label'] ?? '' );
		$lines[] = ' '; 
		$lines[] = 'Comprador: ' . (string) ( $data['razon_social_comprador'] ?? '' );
		$lines[] = 'Identificacion: ' . (string) ( $data['identificacion_comprador'] ?? '' );
		$lines[] = ' '; 
		$lines[] = 'DETALLE';

		foreach ( $items as $item ) {
			$desc  = isset( $item['descripcion'] ) ? (string) $item['descripcion'] : 'Item';
			$cant  = number_format( (float) ( $item['cantidad'] ?? 1 ), 2, '.', '' );
			$unit  = number_format( (float) ( $item['precio_unitario'] ?? 0 ), 2, '.', '' );
			$total = number_format( (float) ( $item['precio_total_sin_impuesto'] ?? 0 ), 2, '.', '' );
			$lines[] = sprintf( '- %s | Cant: %s | P.U: %s | Base: %s', $desc, $cant, $unit, $total );
		}

		$lines[] = ' ';
		$lines[] = 'Subtotal 0%: ' . number_format( (float) ( $data['subtotal_0'] ?? 0 ), 2, '.', '' );
		$lines[] = 'Subtotal IVA: ' . number_format( (float) ( $data['subtotal_iva'] ?? 0 ), 2, '.', '' );
		$lines[] = 'IVA: ' . number_format( (float) ( $data['iva_valor'] ?? 0 ), 2, '.', '' );
		$lines[] = 'TOTAL: ' . number_format( (float) ( $data['total'] ?? 0 ), 2, '.', '' );

		return $lines;
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
	 * Converts UTF-8 text to a Latin1-safe representation for core PDF fonts.
	 */
	private function to_latin1( string $text ): string {
		if ( function_exists( 'iconv' ) ) {
			$converted = iconv( 'UTF-8', 'ISO-8859-1//TRANSLIT', $text );
			if ( false !== $converted ) {
				return $converted;
			}
		}
		return preg_replace( '/[^\x20-\x7E]/', '?', $text );
	}

	/**
	 * Wraps text into fixed-size chunks.
	 *
	 * @param string $text Input line.
	 * @param int    $size Max chars per chunk.
	 * @return array<int, string>
	 */
	private function wrap_text( string $text, int $size ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array( '' );
		}
		$wrapped = wordwrap( $text, $size, "\n", true );
		return explode( "\n", $wrapped );
	}
}
