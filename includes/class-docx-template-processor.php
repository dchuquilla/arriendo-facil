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
	 * @param array<string,mixed>             $reservation_data Chatbot/lease data used to disambiguate blanks.
	 * @return string Absolute path to the processed DOCX, or '' on failure.
	 */
	public function process_owner_template( $source_path, $ai_service = null, $output_path = '', array $reservation_data = array() ) {
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
		$ordered_placeholders = $this->resolve_blank_to_placeholder_order( (string) $doc_xml, $ai_service, $reservation_data );

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
			$autoload_candidates = array(
				defined( 'ARRIENDO_FACIL_PLUGIN_DIR' ) ? trailingslashit( ARRIENDO_FACIL_PLUGIN_DIR ) . 'vendor/autoload.php' : '',
				dirname( __DIR__ ) . '/vendor/autoload.php',
			);

			foreach ( $autoload_candidates as $autoload_file ) {
				if ( $autoload_file && file_exists( $autoload_file ) ) {
					require_once $autoload_file;
					if ( class_exists( '\PhpOffice\PhpWord\TemplateProcessor' ) ) {
						break;
					}
				}
			}
		}

		if ( ! class_exists( '\PhpOffice\PhpWord\TemplateProcessor' ) ) {
			error_log( 'Arriendo Facil DOCX processor fill_template: PhpOffice\PhpWord not available.' );
			return false;
		}

		try {
			$processor = new \PhpOffice\PhpWord\TemplateProcessor( $template_path );
			$values    = $this->build_placeholder_values( $payload );

			if ( method_exists( $processor, 'getVariables' ) ) {
				foreach ( $processor->getVariables() as $template_var ) {
					$template_var = (string) $template_var;
					if ( '' !== $template_var && ! isset( $values[ $template_var ] ) ) {
						$values[ $template_var ] = '________________________';
					}
				}
			}

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
	 * Extracts paragraphs that contain blank sequences (underscores, dots or ellipsis)
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
			$blank_count = (int) preg_match_all( '/_{3,}|\.{5,}|…{3,}/u', $text );

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
	 * Uses local context around each blank and prefers leaving ambiguous fields empty.
	 *
	 * @param string $doc_xml Raw word/document.xml content.
	 * @return list<string> One placeholder name per blank, in document order.
	 */
	private function resolve_blank_to_placeholder_order( $doc_xml, $ai_service = null, array $reservation_data = array() ) {
		$flat_text = $this->extract_flat_text_from_doc_xml( $doc_xml );
		$ordered   = array();
		$ai_line_map = array();

		if ( '' === $flat_text ) {
			return $ordered;
		}

		if ( ! preg_match_all( '/_{3,}|\.{5,}|…{3,}|\t+/u', $flat_text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $ordered;
		}

		$ai_lines = array();
		foreach ( $matches[0] as $blank_index => $match ) {
			$blank_text = (string) $match[0];
			$offset     = (int) $match[1];
			$before     = substr( $flat_text, max( 0, $offset - 140 ), min( 140, $offset ) );
			$after      = substr( $flat_text, $offset + strlen( $blank_text ), 180 );
			$blank_type = 'other';
			if ( false !== strpos( $blank_text, "\t" ) ) {
				$blank_type = 'tab';
			} elseif ( false !== strpos( $blank_text, '_' ) ) {
				$blank_type = 'underscore';
			} elseif ( false !== strpos( $blank_text, '.' ) || false !== strpos( $blank_text, '…' ) ) {
				$blank_type = 'dots';
			}

			$ai_lines[] = array(
				'id'          => 'blank_' . $blank_index,
				'text'        => $before . ' <<<BLANK>>> ' . $after,
				'blank_count' => 1,
				'blank_type'  => $blank_type,
			);
		}

		if ( $ai_service && ! empty( $ai_lines ) ) {
			try {
				$agent_payload = array(
					'contract_text'     => $flat_text,
					'reservation_data'  => $reservation_data,
					'lines'             => $ai_lines,
					'allowed_canonical' => array_keys( self::CANONICAL_TO_PLACEHOLDER ),
				);

				if ( method_exists( $ai_service, 'map_template_word_agent' ) ) {
					$agent_result = $ai_service->map_template_word_agent( $agent_payload );
					if ( ! is_wp_error( $agent_result )
						&& isset( $agent_result['line_map'] )
						&& is_array( $agent_result['line_map'] )
					) {
						$ai_line_map = $agent_result['line_map'];
					}
				}

				if ( empty( $ai_line_map ) ) {
					$ai_result = $ai_service->map_template_line_blanks(
					array(
						'contract_text'     => $flat_text,
						'reservation_data'  => $reservation_data,
						'lines'             => $ai_lines,
						'allowed_canonical' => array_keys( self::CANONICAL_TO_PLACEHOLDER ),
					)
					);

					if ( ! is_wp_error( $ai_result )
						&& isset( $ai_result['line_map'] )
						&& is_array( $ai_result['line_map'] )
					) {
						$ai_line_map = $ai_result['line_map'];
					}
				}
			} catch ( \Throwable $throwable ) {
				error_log( 'Arriendo Facil DOCX processor AI mapping exception: ' . $throwable->getMessage() );
			}
		}

		foreach ( $matches[0] as $blank_index => $match ) {
			$blank_text = (string) $match[0];
			$offset     = (int) $match[1];
			$before     = substr( $flat_text, max( 0, $offset - 140 ), min( 140, $offset ) );
			$after      = substr( $flat_text, $offset + strlen( $blank_text ), 180 );

			$ai_key = '';
			if ( isset( $ai_line_map[ 'blank_' . $blank_index ] ) && is_array( $ai_line_map[ 'blank_' . $blank_index ] ) ) {
				$ai_key = isset( $ai_line_map[ 'blank_' . $blank_index ][0] ) ? trim( (string) $ai_line_map[ 'blank_' . $blank_index ][0] ) : '';
			}

			if ( '' !== $ai_key && isset( self::CANONICAL_TO_PLACEHOLDER[ $ai_key ] ) ) {
				$ordered[] = self::CANONICAL_TO_PLACEHOLDER[ $ai_key ];
				continue;
			}

			$ordered[] = $this->infer_placeholder_from_context( $before, $after, $blank_index );
		}

		return $ordered;
	}

	/**
	 * Infers a placeholder name from local before/after context around one blank.
	 * Ambiguous blanks stay as CAMPO_* so they render empty in the final contract.
	 *
	 * @param string $before    Text immediately before the blank.
	 * @param string $after     Text immediately after the blank.
	 * @param int    $blank_idx Global blank index.
	 * @return string Placeholder name.
	 */
	private function infer_placeholder_from_context( $before, $after, $blank_idx ) {
		$before = $this->normalize_context_text( $before );
		$after  = $this->normalize_context_text( $after );
		$context = trim( $before . ' ' . $after );

		if ( 1 === preg_match( '/como\s+arrendatario\s+el\s+senor\s*$/', $before ) ) {
			return 'ARRENDATARIO';
		}

		if ( 1 === preg_match( '/y\s+el\s+senor\s*$/', $before ) && false !== strpos( $after, 'arrendatario' ) ) {
			return 'ARRENDATARIO';
		}

		if ( 1 === preg_match( '/arrendamiento\s+al\s+senor\s*$/', $before ) ) {
			return 'ARRENDATARIO';
		}

		if ( false !== strpos( $before, 'arrendamiento al senor' ) && false !== strpos( $after, 'consignado con el numero' ) ) {
			return 'ARRENDATARIO';
		}

		if ( false !== strpos( $after, 'propietario de' ) ) {
			return 'ARRENDADOR';
		}

		if ( false !== strpos( $after, 'que en adelante se denominara el arrendador' ) ) {
			return 'ARRENDADOR';
		}

		if ( false !== strpos( $after, 'que en adelante se denominara el arrendatario' ) ) {
			return 'ARRENDATARIO';
		}

		if ( false !== strpos( $before, 'como arrendador, el senor' ) ) {
			return 'ARRENDADOR';
		}

		if ( false !== strpos( $before, 'como arrendatario el senor' ) ) {
			return 'ARRENDATARIO';
		}

		if ( false !== strpos( $before, 'el senor' ) && false !== strpos( $after, 'propietario de' ) ) {
			return 'ARRENDADOR';
		}

		if ( false !== strpos( $before, 'propietario de' ) && false !== strpos( $after, 'situada en' ) ) {
			return 'INMUEBLE';
		}

		if ( false !== strpos( $before, 'situada en' ) && false !== strpos( $after, 'de esta ciudad' ) ) {
			return 'DIRECCION';
		}

		if ( false !== strpos( $after, 'en calidad de arrendador' ) ) {
			return 'ARRENDADOR';
		}

		if ( false !== strpos( $before, 'da y entrega en arrendamiento al senor' ) ) {
			return 'ARRENDATARIO';
		}

		if ( false !== strpos( $after, 'consignado con el numero' ) ) {
			return 'CAMPO_' . $blank_idx;
		}

		if ( false !== strpos( $after, 'con cedula de ciudadania n' ) ) {
			if ( false !== strpos( $before, 'srta' ) || false !== strpos( $before, 'sra' ) || false !== strpos( $before, 'arrendamiento a la' ) ) {
				return 'ARRENDATARIO';
			}

			return 'ARRENDADOR';
		}

		if ( false !== strpos( $before, 'cedula de ciudadania n' ) && false !== strpos( $after, 'arrendador' ) ) {
			return 'CEDULA_ARRENDADOR';
		}

		if ( false !== strpos( $before, 'cedula de ciudadania n' ) && false !== strpos( $after, 'arrendatario' ) ) {
			return 'CEDULA_ARRENDATARIO';
		}

		if ( false !== strpos( $before, 'consignado con el numero' ) ) {
			return 'CEDULA_ARRENDATARIO';
		}

		if ( false !== strpos( $before, 'ubicado en' ) && false !== strpos( $after, 'antes descrita' ) ) {
			return 'INMUEBLE';
		}

		if ( false !== strpos( $before, 'calle' ) ) {
			return 'DIRECCION';
		}

		if ( false !== strpos( $before, 'arrendatario senor' ) ) {
			return 'ARRENDATARIO';
		}

		if ( false !== strpos( $before, 'plazo de este contrato es de' ) && false !== strpos( $after, 'anos' ) ) {
			return 'CAMPO_' . $blank_idx;
		}

		if ( false !== strpos( $before, 'dedicarlo a' ) ) {
			return 'CAMPO_' . $blank_idx;
		}

		if ( false !== strpos( $before, 'y da en garantia, la cantidad de' ) ) {
			return 'GARANTIA';
		}

		if ( false !== strpos( $before, 'la cantidad de' ) && false !== strpos( $after, 'usd por mes' ) ) {
			return 'CANON';
		}

		if ( false !== strpos( $before, 'siguientes accesorios' ) ) {
			return 'CAMPO_' . $blank_idx;
		}

		if ( false !== strpos( $before, 'chapas con' ) && false !== strpos( $after, 'llaves' ) ) {
			return 'CAMPO_' . $blank_idx;
		}

		if ( false !== strpos( $before, 'servicios basico' ) ) {
			return 'CAMPO_' . $blank_idx;
		}

		if ( false !== strpos( $before, 'jueces competentes de la ciudad de' ) ) {
			return 'CAMPO_' . $blank_idx;
		}

		$keyword_guess = $this->infer_placeholder_by_keywords( $context );
		if ( '' !== $keyword_guess ) {
			return $keyword_guess;
		}

		return 'CAMPO_' . $blank_idx;
	}

	/**
	 * Performs a conservative keyword-based inference for unknown blank contexts.
	 *
	 * @param string $context Normalized local context around the blank.
	 * @return string Placeholder name or empty string if uncertain.
	 */
	private function infer_placeholder_by_keywords( $context ) {
		$context = (string) $context;
		if ( '' === $context ) {
			return '';
		}

		if ( false !== strpos( $context, 'arrendador' ) && false !== strpos( $context, 'arrendatario' ) ) {
			return '';
		}

		$rules = array(
			'CEDULA_ARRENDATARIO' => array( 'cedula', 'identificacion', 'documento', 'arrendatario', 'inquilino' ),
			'CEDULA_ARRENDADOR'   => array( 'cedula', 'identificacion', 'documento', 'arrendador', 'propietario' ),
			'ARRENDATARIO'        => array( 'arrendatario', 'inquilino', 'locatario', 'tomador' ),
			'ARRENDADOR'          => array( 'arrendador', 'propietario', 'dador', 'locador' ),
			'CANON'               => array( 'canon', 'renta', 'mensualidad', 'valor', 'monto', 'precio', 'usd', 'dolar' ),
			'FECHA_INICIO'        => array( 'fecha de inicio', 'iniciara', 'inicio', 'desde', 'comienza', 'vigencia desde' ),
			'FECHA_FIN'           => array( 'fecha de fin', 'hasta', 'termina', 'vencimiento', 'fin de contrato' ),
			'DIRECCION'           => array( 'direccion', 'calle', 'avenida', 'sector', 'parroquia', 'canton', 'provincia', 'ubicada en' ),
			'INMUEBLE'            => array( 'inmueble', 'departamento', 'casa', 'local', 'oficina', 'propiedad', 'predio', 'bien' ),
			'GARANTIA'            => array( 'garantia', 'deposito', 'fianza', 'caucion' ),
			'TELEFONO'            => array( 'telefono', 'celular', 'movil', 'contacto' ),
			'EMAIL'               => array( 'correo', 'email', 'e-mail' ),
			'FECHA_ACTUAL'        => array( 'fecha actual', 'suscribe', 'firma en', 'celebrado en' ),
		);

		$scores = array();
		foreach ( $rules as $placeholder => $keywords ) {
			$score = 0;
			foreach ( $keywords as $keyword ) {
				if ( false !== strpos( $context, $keyword ) ) {
					$score++;
				}
			}

			if ( $score > 0 ) {
				$scores[ $placeholder ] = $score;
			}
		}

		if ( empty( $scores ) ) {
			return '';
		}

		arsort( $scores );
		$top_keys   = array_keys( $scores );
		$top_key    = isset( $top_keys[0] ) ? (string) $top_keys[0] : '';
		$top_score  = isset( $scores[ $top_key ] ) ? (int) $scores[ $top_key ] : 0;
		$next_key   = isset( $top_keys[1] ) ? (string) $top_keys[1] : '';
		$next_score = '' !== $next_key && isset( $scores[ $next_key ] ) ? (int) $scores[ $next_key ] : 0;

		if ( $top_score < 2 ) {
			return '';
		}

		if ( $top_score === $next_score ) {
			return '';
		}

		return $top_key;
	}

	/**
	 * Extracts flat readable text from DOCX XML by concatenating text runs.
	 *
	 * @param string $doc_xml Raw XML.
	 * @return string
	 */
	private function extract_flat_text_from_doc_xml( $doc_xml ) {
		$chunks = array();
		if ( preg_match_all( '/<w:t(?:[^>]*)>([^<]*)<\/w:t>|<w:tab(?:[^>]*)\/>/u', (string) $doc_xml, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( isset( $match[1] ) && '' !== $match[1] ) {
					$chunks[] = html_entity_decode( (string) $match[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
				} else {
					$chunks[] = "\t";
				}
			}
		}

		return implode( '', $chunks );
	}

	/**
	 * Normalizes nearby context text for reliable rule matching.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function normalize_context_text( $text ) {
		$text = (string) $text;
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}

		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = strtolower( $text );
		$text = str_replace( array( '~', "'", '"' ), '', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Replaces blank sequences (underscores, long dots or ellipsis) inside <w:t> XML elements with
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

		$dom_result = $this->inject_placeholders_with_dom( $doc_xml, $ordered_placeholders );
		if ( '' !== $dom_result ) {
			return $dom_result;
		}

		$counter = 0;

		return (string) preg_replace_callback(
			'/(<w:t(?:[^>]*)>)([\s\S]*?)(<\/w:t>)/',
			function ( $m ) use ( &$counter, $ordered_placeholders ) {
				$open    = $m[1];
				$content = $m[2];
				$close   = $m[3];

				$new_content = (string) preg_replace_callback(
					'/_{3,}|\.{5,}|…{3,}/u',
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

	/**
	 * Injects placeholders by traversing text runs with DOM so blank sequences
	 * split across multiple <w:t> nodes are still replaced.
	 *
	 * @param string       $doc_xml              Raw word/document.xml.
	 * @param list<string> $ordered_placeholders Placeholder names in document order.
	 * @return string Updated XML or empty string when DOM path cannot be applied.
	 */
	private function inject_placeholders_with_dom( $doc_xml, array $ordered_placeholders ) {
		if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
			return '';
		}

		$dom = new DOMDocument();
		if ( ! @$dom->loadXML( (string) $doc_xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
			return '';
		}

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
		$text_nodes = $xpath->query( '//w:t|//w:tab' );

		if ( ! $text_nodes || 0 === $text_nodes->length ) {
			return '';
		}

		$nodes = array();
		foreach ( $text_nodes as $node ) {
			$nodes[] = $node;
		}

		$entries = array();
		$nodes_chars = array();
		$node_kind = array();
		foreach ( $nodes as $node_index => $node ) {
			$local_name = (string) $node->localName;
			if ( 'tab' === $local_name ) {
				$node_kind[ $node_index ] = 'tab';
				$entries[] = array(
					'node' => $node_index,
					'char' => 0,
					'text' => "\t",
					'kind' => 'tab',
				);
				continue;
			}

			$node_kind[ $node_index ] = 'text';
			$text = (string) $node->textContent;
			$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
			if ( ! is_array( $chars ) ) {
				$chars = array();
			}

			$nodes_chars[ $node_index ] = $chars;
			foreach ( $chars as $char_index => $char ) {
				$entries[] = array(
					'node' => $node_index,
					'char' => $char_index,
					'text' => $char,
					'kind' => 'text',
				);
			}
		}

		if ( empty( $entries ) ) {
			return '';
		}

		$replace_at = array();
		$remove_at  = array();
		$tab_replace_nodes = array();
		$tab_remove_nodes  = array();
		$counter    = 0;
		$total      = count( $entries );
		$i          = 0;

		while ( $i < $total ) {
			$char = $entries[ $i ]['text'];
			if ( ! $this->is_blank_marker_char( $char ) ) {
				$i++;
				continue;
			}

			$start      = $i;
			$blank_count = 1;
			$j          = $i + 1;

			while ( $j < $total ) {
				$next_char = $entries[ $j ]['text'];
				if ( $this->is_blank_marker_char( $next_char ) ) {
					$blank_count++;
					$j++;
					continue;
				}

				if ( preg_match( '/\s/u', $next_char ) ) {
					$j++;
					continue;
				}

				break;
			}

			$start_char = isset( $entries[ $start ]['text'] ) ? (string) $entries[ $start ]['text'] : '';
			$min_count  = "\t" === $start_char ? 1 : 3;

			if ( $blank_count >= $min_count ) {
				$placeholder = isset( $ordered_placeholders[ $counter ] )
					? (string) $ordered_placeholders[ $counter ]
					: ( 'CAMPO_' . $counter );
				$counter++;
				$replace_at[ $start ] = '${' . $placeholder . '}';
				for ( $k = $start; $k < $j; $k++ ) {
					$remove_at[ $k ] = true;
				}
				$remove_at[ $start ] = false;
			}

			$i = $j;
		}

		if ( empty( $replace_at ) ) {
			return '';
		}

		foreach ( $entries as $entry_index => $entry ) {
			$node_index = (int) $entry['node'];
			$char_index = (int) $entry['char'];
			$kind       = isset( $entry['kind'] ) ? (string) $entry['kind'] : 'text';

			if ( 'tab' === $kind ) {
				if ( isset( $replace_at[ $entry_index ] ) ) {
					$tab_replace_nodes[ $node_index ] = (string) $replace_at[ $entry_index ];
					continue;
				}

				if ( ! empty( $remove_at[ $entry_index ] ) ) {
					$tab_remove_nodes[ $node_index ] = true;
				}

				continue;
			}

			if ( isset( $replace_at[ $entry_index ] ) ) {
				$nodes_chars[ $node_index ][ $char_index ] = $replace_at[ $entry_index ];
				continue;
			}

			if ( ! empty( $remove_at[ $entry_index ] ) ) {
				$nodes_chars[ $node_index ][ $char_index ] = '';
			}
		}

		foreach ( $nodes as $node_index => $node ) {
			if ( isset( $node_kind[ $node_index ] ) && 'text' === $node_kind[ $node_index ] ) {
				$node->nodeValue = implode( '', $nodes_chars[ $node_index ] );
			}
		}

		foreach ( $nodes as $node_index => $node ) {
			if ( ! isset( $node_kind[ $node_index ] ) || 'tab' !== $node_kind[ $node_index ] ) {
				continue;
			}

			$parent = $node->parentNode;
			if ( ! $parent ) {
				continue;
			}

			if ( isset( $tab_replace_nodes[ $node_index ] ) ) {
				$text_node = $dom->createElementNS( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t' );
				$text_node->appendChild( $dom->createTextNode( $tab_replace_nodes[ $node_index ] ) );
				$parent->replaceChild( $text_node, $node );
				continue;
			}

			if ( isset( $tab_remove_nodes[ $node_index ] ) ) {
				$parent->removeChild( $node );
			}
		}

		return (string) $dom->saveXML( $dom->documentElement );
	}

	/**
	 * Checks whether a character is a supported blank marker.
	 *
	 * @param string $char One UTF-8 character.
	 * @return bool
	 */
	private function is_blank_marker_char( $char ) {
		return '_' === $char || '.' === $char || '…' === $char || "\t" === $char;
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
