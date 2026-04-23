<?php
/**
 * PHPWord-based DOCX template processor.
 *
 * Handles two phases:
 *
 * 1. process_owner_template() — called ONCE when the owner uploads their DOCX.
 *    Reads the document, maps every blank field to a canonical ${PLACEHOLDER},
 *    and writes a processed copy. The original is never touched.
 *
 * 2. fill_template() — called each time a contract is generated from the chatbot
 *    or admin panel. Uses PhpOffice\PhpWord\TemplateProcessor to replace every
 *    ${PLACEHOLDER} with the real lease/guest value and saves a new DOCX.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_DOCX_Template_Processor {

	/**
	 * Maps canonical AI field keys (returned by map_template_line_blanks)
	 * to the ${PLACEHOLDER} names written into the processed template.
	 *
	 * @var array<string,string>
	 */
	const CANONICAL_TO_PLACEHOLDER = array(
		'guest_name'            => 'ARRENDATARIO',
		'guest_id_number'       => 'CEDULA_ARRENDATARIO',
		'guest_phone'           => 'TELEFONO',
		'guest_email'           => 'EMAIL',
		'owner_name'            => 'ARRENDADOR',
		'owner_id_number'       => 'CEDULA_ARRENDADOR',
		'monthly_rent'          => 'CANON',
		'start_date'            => 'FECHA_INICIO',
		'end_date'              => 'FECHA_FIN',
		'accommodation_address' => 'DIRECCION',
		'accommodation_title'   => 'INMUEBLE',
		'guarantee_text'        => 'GARANTIA',
		'current_date'          => 'FECHA_ACTUAL',
	);

	/**
	 * Maps legacy {{TOKEN}} / [[TOKEN]] patterns to canonical placeholder names.
	 *
	 * @var array<string,string>
	 */
	const LEGACY_TOKEN_MAP = array(
		'ARRENDATARIO'        => 'ARRENDATARIO',
		'INQUILINO'           => 'ARRENDATARIO',
		'NOMBRE_INQUILINO'    => 'ARRENDATARIO',
		'NOMBRE_ARRENDATARIO' => 'ARRENDATARIO',
		'CEDULA'              => 'CEDULA_ARRENDATARIO',
		'CI'                  => 'CEDULA_ARRENDATARIO',
		'CEDULA_INQUILINO'    => 'CEDULA_ARRENDATARIO',
		'CEDULA_ARRENDATARIO' => 'CEDULA_ARRENDATARIO',
		'ARRENDADOR'          => 'ARRENDADOR',
		'PROPIETARIO'         => 'ARRENDADOR',
		'CEDULA_ARRENDADOR'   => 'CEDULA_ARRENDADOR',
		'CANON'               => 'CANON',
		'MONTO'               => 'CANON',
		'VALOR'               => 'CANON',
		'RENTA'               => 'CANON',
		'FECHA_INICIO'        => 'FECHA_INICIO',
		'FECHA_FIN'           => 'FECHA_FIN',
		'FECHA_TERMINO'       => 'FECHA_FIN',
		'FECHA_VENCIMIENTO'   => 'FECHA_FIN',
		'DIRECCION'           => 'DIRECCION',
		'INMUEBLE'            => 'INMUEBLE',
		'PROPIEDAD'           => 'INMUEBLE',
		'GARANTIA'            => 'GARANTIA',
		'TELEFONO'            => 'TELEFONO',
		'EMAIL'               => 'EMAIL',
		'CORREO'              => 'EMAIL',
	);

	// ─────────────────────────────────────────────────────────────────────────
	// Public API
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Processes an owner DOCX template: detects blank fields, maps them to
	 * canonical ${PLACEHOLDER} markers, and writes a processed copy.
	 *
	 * The original file is NEVER modified.
	 *
	 * @param string                          $source_path Absolute path to the original DOCX.
	 * @param Arriendo_Facil_AI_Service|null  $ai_service  Optional AI service for blank detection.
	 * @param string                          $output_path Destination path. Auto-generated if ''.
	 * @return string Absolute path to the processed DOCX, or '' on failure.
	 */
	public function process_owner_template( $source_path, $ai_service = null, $output_path = '' ) {
		$source_path = (string) $source_path;

		if ( '' === $source_path || ! file_exists( $source_path ) || ! class_exists( 'ZipArchive' ) ) {
			return '';
		}

		// Read word/document.xml from the original DOCX.
		$zip = new ZipArchive();
		if ( true !== $zip->open( $source_path ) ) {
			return '';
		}

		$doc_xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $doc_xml || '' === trim( (string) $doc_xml ) ) {
			return '';
		}

		// Step 1: Convert any legacy {{TOKEN}} / [[TOKEN]] already in the template.
		$doc_xml = $this->convert_legacy_tokens( (string) $doc_xml );

		// Step 2: If the template already contains ${...} placeholders, use it as-is.
		if ( false !== strpos( $doc_xml, '${' ) ) {
			if ( '' === $output_path ) {
				$output_path = $this->generate_output_path( $source_path );
			}
			return $this->write_processed_docx( $source_path, $doc_xml, $output_path );
		}

		// Step 3: Extract paragraphs that contain blank sequences.
		$blank_paragraphs = $this->extract_paragraphs_with_blanks( $doc_xml );

		if ( empty( $blank_paragraphs ) ) {
			// No blanks found — copy as-is so the path is still saved.
			if ( '' === $output_path ) {
				$output_path = $this->generate_output_path( $source_path );
			}
			return $this->write_processed_docx( $source_path, $doc_xml, $output_path );
		}

		// Step 4: Resolve blank occurrences → ordered placeholder names.
		$ordered_placeholders = $this->resolve_blank_to_placeholder_order( $blank_paragraphs, $ai_service );

		// Step 5: Inject placeholders into the XML.
		$doc_xml = $this->inject_placeholders( $doc_xml, $ordered_placeholders );

		// Step 6: Write output DOCX.
		if ( '' === $output_path ) {
			$output_path = $this->generate_output_path( $source_path );
		}

		return $this->write_processed_docx( $source_path, $doc_xml, $output_path );
	}

	/**
	 * Fills a processed DOCX template using PhpOffice\PhpWord\TemplateProcessor.
	 *
	 * The template file is NOT modified; the filled contract is written to $output_path.
	 * ${PLACEHOLDER} tokens are replaced with real data; any leftover tokens become blank lines.
	 *
	 * @param string $template_path Path to the processed DOCX with ${PLACEHOLDER} markers.
	 * @param string $output_path   Destination path for the generated contract DOCX.
	 * @param array  $payload       Lease and guest data.
	 * @return bool True on success.
	 */
	public function fill_template( $template_path, $output_path, array $payload ) {
		$template_path = (string) $template_path;
		$output_path   = (string) $output_path;

		if ( '' === $template_path || ! file_exists( $template_path ) ) {
			error_log( 'Arriendo Facil DOCX processor fill_template: template not found – ' . $template_path );
			return false;
		}

		if ( ! class_exists( '\PhpOffice\PhpWord\TemplateProcessor' ) ) {
			error_log( 'Arriendo Facil DOCX processor fill_template: PhpOffice\PhpWord not available.' );
			return false;
		}

		try {
			$processor = new \PhpOffice\PhpWord\TemplateProcessor( $template_path );
			$values    = $this->build_placeholder_values( $payload );

			foreach ( $values as $key => $value ) {
				$processor->setValue( $key, htmlspecialchars( (string) $value, ENT_COMPAT, 'UTF-8' ) );
			}

			$processor->saveAs( $output_path );

			return file_exists( $output_path ) && filesize( $output_path ) > 0;
		} catch ( \Throwable $e ) {
			error_log( 'Arriendo Facil DOCX processor fill_template exception: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Builds the placeholder → display-value map from a lease payload.
	 *
	 * When a value is absent from the payload, a visible blank line is used
	 * so that no raw ${...} token ever appears in the final document.
	 *
	 * @param array $payload Lease and guest data.
	 * @return array<string,string> Placeholder name => display value.
	 */
	public function build_placeholder_values( array $payload ) {
		$blank = '________________________';
		$rent  = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '';

		return array(
			'ARRENDATARIO'        => $this->val( $payload, 'guest_name', $blank ),
			'CEDULA_ARRENDATARIO' => $this->val( $payload, 'guest_id_number', $blank ),
			'TELEFONO'            => $this->val( $payload, 'guest_phone', $blank ),
			'EMAIL'               => $this->val( $payload, 'guest_email', $blank ),
			'ARRENDADOR'          => $this->val( $payload, 'owner_name', $blank ),
			'CEDULA_ARRENDADOR'   => $this->val( $payload, 'owner_id_number', $blank ),
			'CANON'               => '' !== $rent ? 'USD ' . $rent : $blank,
			'FECHA_INICIO'        => $this->val( $payload, 'start_date', $blank ),
			'FECHA_FIN'           => $this->val( $payload, 'end_date', $blank ),
			'DIRECCION'           => $this->val( $payload, 'accommodation_address', $blank ),
			'INMUEBLE'            => $this->val( $payload, 'accommodation_title', $blank ),
			'GARANTIA'            => $this->val( $payload, 'guarantee_text', $blank ),
			'MASCOTAS'            => isset( $payload['mascotas'] ) && (int) $payload['mascotas'] > 0 ? 'Sí' : 'No',
			'PERSONAS'            => isset( $payload['personas_viviran'] ) && (int) $payload['personas_viviran'] > 0
									? (string) (int) $payload['personas_viviran']
									: $blank,
			'REFERENCIA_1'        => $this->val( $payload, 'referencia_personal_1', $blank ),
			'REFERENCIA_2'        => $this->val( $payload, 'referencia_personal_2', $blank ),
			'FECHA_ACTUAL'        => gmdate( 'd/m/Y' ),
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Private – template processing helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Converts legacy {{TOKEN}} and [[TOKEN]] patterns in the XML to ${CANONICAL}.
	 *
	 * @param string $doc_xml Raw word/document.xml content.
	 * @return string
	 */
	private function convert_legacy_tokens( $doc_xml ) {
		return (string) preg_replace_callback(
			'/\{\{([^}]{1,60})\}\}|\[\[([^\]]{1,60})\]\]/',
			function ( $m ) {
				$raw      = strtoupper( trim( '' !== $m[1] ? $m[1] : $m[2] ) );
				$raw      = (string) preg_replace( '/\s+/', '_', $raw );
				$canon    = isset( self::LEGACY_TOKEN_MAP[ $raw ] ) ? self::LEGACY_TOKEN_MAP[ $raw ] : $raw;
				return '${' . $canon . '}';
			},
			(string) $doc_xml
		);
	}

	/**
	 * Extracts paragraphs that contain blank sequences (3+ underscores or 5+ dots)
	 * in document order.
	 *
	 * @param string $doc_xml Raw word/document.xml.
	 * @return array<string, array{text: string, blank_count: int}> para_N => info.
	 */
	private function extract_paragraphs_with_blanks( $doc_xml ) {
		$result   = array();
		$para_idx = 0;

		if ( ! preg_match_all( '/<w:p[ >][\s\S]*?<\/w:p>/', (string) $doc_xml, $para_matches ) ) {
			return $result;
		}

		foreach ( $para_matches[0] as $para_xml ) {
			// Join all <w:t> runs in this paragraph into plain text.
			$text = '';
			if ( preg_match_all( '/<w:t(?:[^>]*)>([^<]*)<\/w:t>/', $para_xml, $t_matches ) ) {
				$text = implode( '', $t_matches[1] );
			}

			$text        = html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
			$blank_count = (int) preg_match_all( '/_{3,}|\.{5,}/', $text );

			if ( $blank_count > 0 ) {
				$result[ 'para_' . $para_idx ] = array(
					'text'        => $text,
					'blank_count' => $blank_count,
				);
			}

			$para_idx++;
		}

		return $result;
	}

	/**
	 * Resolves blank occurrences → ordered list of placeholder names.
	 *
	 * Uses AI (map_template_line_blanks) when available; falls back to
	 * keyword-based heuristics per blank.
	 *
	 * @param array                          $blank_paragraphs Output of extract_paragraphs_with_blanks().
	 * @param Arriendo_Facil_AI_Service|null $ai_service
	 * @return list<string> One placeholder name per blank, in document order.
	 */
	private function resolve_blank_to_placeholder_order( array $blank_paragraphs, $ai_service ) {
		if ( empty( $blank_paragraphs ) ) {
			return array();
		}

		// Build the input for map_template_line_blanks.
		$lines = array();
		foreach ( $blank_paragraphs as $para_id => $info ) {
			$lines[] = array(
				'id'          => $para_id,
				'text'        => substr( $info['text'], 0, 300 ),
				'blank_count' => $info['blank_count'],
			);
		}

		$ai_line_map = array();

		if ( $ai_service ) {
			try {
				$response = $ai_service->map_template_line_blanks(
					array(
						'lines'             => $lines,
						'allowed_canonical' => array_keys( self::CANONICAL_TO_PLACEHOLDER ),
					)
				);

				if ( ! is_wp_error( $response )
					&& isset( $response['line_map'] )
					&& is_array( $response['line_map'] )
				) {
					$ai_line_map = $response['line_map'];
				}
			} catch ( \Throwable $e ) {
				error_log( 'Arriendo Facil DOCX processor AI blank mapping failed: ' . $e->getMessage() );
			}
		}

		// Convert per-paragraph canonical keys → globally ordered placeholder names.
		$ordered = array();

		foreach ( $blank_paragraphs as $para_id => $info ) {
			$para_mapping = isset( $ai_line_map[ $para_id ] ) && is_array( $ai_line_map[ $para_id ] )
				? $ai_line_map[ $para_id ]
				: array();

			for ( $i = 0; $i < $info['blank_count']; $i++ ) {
				$canonical = isset( $para_mapping[ $i ] ) ? trim( (string) $para_mapping[ $i ] ) : '';

				if ( '' !== $canonical && isset( self::CANONICAL_TO_PLACEHOLDER[ $canonical ] ) ) {
					$ordered[] = self::CANONICAL_TO_PLACEHOLDER[ $canonical ];
				} else {
					// Rule-based fallback for this specific blank.
					$ordered[] = $this->infer_placeholder_from_context( $info['text'], $i );
				}
			}
		}

		return $ordered;
	}

	/**
	 * Infers a placeholder name from paragraph text and blank position using
	 * keyword proximity rules. Used as fallback when AI is unavailable.
	 *
	 * @param string $text      Paragraph plain text.
	 * @param int    $blank_idx Which blank within this paragraph (0-based).
	 * @return string Placeholder name.
	 */
	private function infer_placeholder_from_context( $text, $blank_idx ) {
		$lower = strtolower( (string) $text );

		$rules = array(
			'arrendatario'  => 'ARRENDATARIO',
			'inquilino'     => 'ARRENDATARIO',
			'arrendador'    => 'ARRENDADOR',
			'propietario'   => 'ARRENDADOR',
			'cedula'        => 0 === $blank_idx ? 'CEDULA_ARRENDADOR' : 'CEDULA_ARRENDATARIO',
			'canon'         => 'CANON',
			'valor'         => 'CANON',
			'renta'         => 'CANON',
			'desde'         => 'FECHA_INICIO',
			'inicio'        => 'FECHA_INICIO',
			'hasta'         => 'FECHA_FIN',
			'fin del'       => 'FECHA_FIN',
			'direccion'     => 'DIRECCION',
			'inmueble'      => 'INMUEBLE',
			'propiedad'     => 'INMUEBLE',
			'garantia'      => 'GARANTIA',
			'telefono'      => 'TELEFONO',
			'email'         => 'EMAIL',
			'correo'        => 'EMAIL',
			'referencia'    => 0 === $blank_idx ? 'REFERENCIA_1' : 'REFERENCIA_2',
		);

		foreach ( $rules as $keyword => $placeholder ) {
			if ( false !== strpos( $lower, $keyword ) ) {
				return $placeholder;
			}
		}

		return 'CAMPO_' . $blank_idx;
	}

	/**
	 * Replaces blank sequences (3+ underscores) inside <w:t> XML elements with
	 * ${PLACEHOLDER} tokens, consuming the ordered placeholder list in document order.
	 *
	 * @param string       $doc_xml              Raw word/document.xml.
	 * @param list<string> $ordered_placeholders Placeholder names in document order.
	 * @return string Modified document.xml.
	 */
	private function inject_placeholders( $doc_xml, array $ordered_placeholders ) {
		if ( empty( $ordered_placeholders ) ) {
			return $doc_xml;
		}

		$counter = 0;

		return (string) preg_replace_callback(
			'/(<w:t(?:[^>]*)>)([\s\S]*?)(<\/w:t>)/',
			function ( $m ) use ( &$counter, $ordered_placeholders ) {
				$open    = $m[1];
				$content = $m[2];
				$close   = $m[3];

				$new_content = (string) preg_replace_callback(
					'/_{3,}/',
					function () use ( &$counter, $ordered_placeholders ) {
						$ph = isset( $ordered_placeholders[ $counter ] )
							? $ordered_placeholders[ $counter ]
							: ( 'CAMPO_' . $counter );
						$counter++;
						return '${' . $ph . '}';
					},
					$content
				);

				return $open . $new_content . $close;
			},
			(string) $doc_xml
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Private – file I/O helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Copies the original DOCX and replaces word/document.xml with the processed version.
	 *
	 * @param string $source_path  Original DOCX path.
	 * @param string $new_doc_xml  Modified document.xml content.
	 * @param string $output_path  Destination path.
	 * @return string Output path on success, '' on failure.
	 */
	private function write_processed_docx( $source_path, $new_doc_xml, $output_path ) {
		if ( ! @copy( $source_path, $output_path ) ) {
			error_log( 'Arriendo Facil DOCX processor: cannot copy source to ' . $output_path );
			return '';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $output_path ) ) {
			@unlink( $output_path );
			return '';
		}

		$zip->addFromString( 'word/document.xml', $new_doc_xml );

		if ( ! $zip->close() ) {
			@unlink( $output_path );
			return '';
		}

		return file_exists( $output_path ) ? $output_path : '';
	}

	/**
	 * Generates a stable output path in the plugin's owner-templates upload dir.
	 *
	 * @param string $source_path Original DOCX path.
	 * @return string
	 */
	private function generate_output_path( $source_path ) {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return sys_get_temp_dir() . '/af_processed_' . md5( $source_path ) . '.docx';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/owner-templates';
		wp_mkdir_p( $dir );

		return trailingslashit( $dir ) . 'processed-' . md5( $source_path ) . '-' . basename( $source_path );
	}

	/**
	 * Returns a trimmed string value from a payload array, or $default if absent/empty.
	 *
	 * @param array  $payload
	 * @param string $key
	 * @param string $default
	 * @return string
	 */
	private function val( array $payload, $key, $default = '' ) {
		$v = isset( $payload[ $key ] ) ? trim( (string) $payload[ $key ] ) : '';
		return '' !== $v ? $v : $default;
	}
}
