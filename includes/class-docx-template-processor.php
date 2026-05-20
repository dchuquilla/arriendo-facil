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
		'accommodation_city'    => 'CIUDAD',
		'accommodation_square_meters' => 'METROS_CUADRADOS',
		'accommodation_bedrooms'      => 'HABITACIONES',
		'accommodation_bathrooms'     => 'BANOS',
		'accommodation_property_type' => 'TIPO_INMUEBLE',
		'guarantee_text'        => 'GARANTIA',
		'current_date'          => 'FECHA_ACTUAL',
		'current_day'           => 'DIA_ACTUAL',
		'current_month_name'    => 'MES_ACTUAL',
		'current_year'          => 'ANO_ACTUAL',
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
		'CIUDAD'              => 'CIUDAD',
		'METROS_CUADRADOS'    => 'METROS_CUADRADOS',
		'M2'                  => 'METROS_CUADRADOS',
		'AREA'                => 'METROS_CUADRADOS',
		'HABITACIONES'        => 'HABITACIONES',
		'DORMITORIOS'         => 'HABITACIONES',
		'BANOS'               => 'BANOS',
		'TIPO_INMUEBLE'       => 'TIPO_INMUEBLE',
		'DIA'                 => 'DIA_ACTUAL',
		'DIA_ACTUAL'          => 'DIA_ACTUAL',
		'MES'                 => 'MES_ACTUAL',
		'MES_ACTUAL'          => 'MES_ACTUAL',
		'ANO'                 => 'ANO_ACTUAL',
		'ANO_ACTUAL'          => 'ANO_ACTUAL',
	);

	// ─────────────────────────────────────────────────────────────────────────
	// Public API
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Fills a DOCX contract directly using AI to detect and replace blanks.
	 *
	 * This is the PRIMARY path for owner templates. It:
	 * 1. Extracts the full text from the DOCX.
	 * 2. Detects ALL blank markers (underscores, dots, dashes, tabs, spaces).
	 * 3. Sends the text + chatbot data to AI for field mapping.
	 * 4. Replaces each blank with the actual value in the XML (preserving format).
	 * 5. Writes the filled DOCX.
	 *
	 * Works with ANY template format — no pre-processing step needed.
	 *
	 * @param string                         $source_path  Original DOCX path.
	 * @param string                         $output_path  Destination path for filled contract.
	 * @param array                          $payload      Lease and guest data from chatbot.
	 * @param Arriendo_Facil_AI_Service|null $ai_service   AI service for field detection.
	 * @return bool True on success.
	 */
	public function fill_template_with_ai( $source_path, $output_path, array $payload, $ai_service = null ) {
		$source_path = (string) $source_path;
		$output_path = (string) $output_path;
		$lease_id    = isset( $payload['lease_id'] ) ? (int) $payload['lease_id'] : 0;

		if ( '' === $source_path || ! file_exists( $source_path ) || ! class_exists( 'ZipArchive' ) ) {
			$this->log_docx_event( 'fill_with_ai_failed', array( 'reason' => 'source_invalid', 'lease_id' => $lease_id ) );
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $source_path ) ) {
			$this->log_docx_event( 'fill_with_ai_failed', array( 'reason' => 'zip_open_failed', 'lease_id' => $lease_id ) );
			return false;
		}

		$doc_xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $doc_xml || '' === trim( (string) $doc_xml ) ) {
			$this->log_docx_event( 'fill_with_ai_failed', array( 'reason' => 'xml_empty', 'lease_id' => $lease_id ) );
			return false;
		}

		$doc_xml = (string) $doc_xml;

		// Step 1: Convert legacy tokens first.
		$doc_xml = $this->convert_legacy_tokens( $doc_xml );

		// Step 2: Extract flat text and detect ALL blank sequences.
		$flat_text = $this->extract_flat_text_from_doc_xml( $doc_xml );
		if ( '' === $flat_text ) {
			$this->log_docx_event( 'fill_with_ai_failed', array( 'reason' => 'flat_text_empty', 'lease_id' => $lease_id ) );
			return false;
		}

		// Detect blanks: underscores, dots, dashes, ellipsis, tabs (2+ consecutive).
		$blank_pattern = '/_{3,}|\.{4,}|…{2,}|-{4,}|\t{2,}/u';
		if ( ! preg_match_all( $blank_pattern, $flat_text, $matches, PREG_OFFSET_CAPTURE ) ) {
			$this->log_docx_event( 'fill_with_ai_no_blanks', array( 'lease_id' => $lease_id, 'text_length' => strlen( $flat_text ) ) );
			return false;
		}

		$blank_count = count( $matches[0] );
		$this->log_docx_event( 'fill_with_ai_blanks_found', array( 'lease_id' => $lease_id, 'blank_count' => $blank_count ) );

		// Step 2b: Filter out likely decorative blanks (PDF-to-Word artifacts).
		$matches[0] = $this->filter_decorative_blanks( $matches[0], $flat_text );

		if ( empty( $matches[0] ) ) {
			$this->log_docx_event( 'fill_with_ai_no_blanks_after_filter', array( 'lease_id' => $lease_id, 'original_count' => $blank_count ) );
			return false;
		}

		// Step 3: Build context for each blank.
		$ai_lines = array();
		foreach ( $matches[0] as $idx => $match ) {
			$blank_text = (string) $match[0];
			$offset     = (int) $match[1];
			$before     = substr( $flat_text, max( 0, $offset - 200 ), min( 200, $offset ) );
			$after      = substr( $flat_text, $offset + strlen( $blank_text ), 200 );

			$ai_lines[] = array(
				'id'      => 'blank_' . $idx,
				'before'  => $before,
				'after'   => $after,
				'blank'   => $blank_text,
			);
		}

		// Step 4: Build the values map from chatbot payload.
		$values = $this->build_placeholder_values( $payload );

		// Step 5: Use AI to map each blank to its value.
		$ai_replacements = array();
		if ( $ai_service && method_exists( $ai_service, 'fill_contract_blanks' ) ) {
			try {
				$ai_result = $ai_service->fill_contract_blanks( array(
					'contract_text'    => $flat_text,
					'blanks'           => $ai_lines,
					'available_values' => $values,
					'payload'          => array(
						'guest_name'            => $this->val( $payload, 'guest_name' ),
						'guest_id_number'       => $this->val( $payload, 'guest_id_number' ),
						'guest_phone'           => $this->val( $payload, 'guest_phone' ),
						'guest_email'           => $this->val( $payload, 'guest_email' ),
						'owner_name'            => $this->val( $payload, 'owner_name' ),
						'owner_id_number'       => $this->val( $payload, 'owner_id_number' ),
						'monthly_rent'          => isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '',
						'start_date'            => $this->val( $payload, 'start_date' ),
						'end_date'              => $this->val( $payload, 'end_date' ),
						'accommodation_address' => $this->val( $payload, 'accommodation_address' ),
						'accommodation_title'   => $this->val( $payload, 'accommodation_title' ),
						'guarantee_text'        => $this->val( $payload, 'guarantee_text' ),
					),
				) );

				if ( ! is_wp_error( $ai_result ) && isset( $ai_result['replacements'] ) && is_array( $ai_result['replacements'] ) ) {
					$ai_replacements = $this->validate_ai_replacements( $ai_result['replacements'], $ai_lines, $payload );
					$this->log_docx_event( 'fill_with_ai_mapped', array(
						'lease_id'     => $lease_id,
						'mapped_count' => count( $ai_replacements ),
					) );
				} else {
					$error_msg = is_wp_error( $ai_result ) ? $ai_result->get_error_message() : 'no replacements in response';
					$this->log_docx_event( 'fill_with_ai_mapping_failed', array( 'lease_id' => $lease_id, 'error' => $error_msg ) );
				}
			} catch ( \Throwable $e ) {
				$this->log_docx_event( 'fill_with_ai_exception', array( 'lease_id' => $lease_id, 'error' => $e->getMessage() ) );
			}
		}

		// If AI returned no useful mappings, fall back to context rules.
		if ( empty( $ai_replacements ) ) {
			$this->log_docx_event( 'fill_with_ai_using_context_fallback', array( 'lease_id' => $lease_id ) );
			$ai_replacements = $this->map_blanks_by_context_rules( $ai_lines, $values );
		}

		if ( empty( $ai_replacements ) ) {
			$this->log_docx_event( 'fill_with_ai_no_replacements', array( 'lease_id' => $lease_id ) );
			return false;
		}

		// Step 6: Build ordered replacement values matching blank positions.
		$ordered_values = array();
		foreach ( $matches[0] as $idx => $match ) {
			$blank_id = 'blank_' . $idx;
			if ( isset( $ai_replacements[ $blank_id ] ) && '' !== trim( (string) $ai_replacements[ $blank_id ] ) ) {
				$ordered_values[] = (string) $ai_replacements[ $blank_id ];
			} else {
				$ordered_values[] = null; // Keep original blank.
			}
		}

		// Step 7: Replace blanks in the XML with actual values.
		$filled_xml = $this->replace_blanks_in_xml_with_values( $doc_xml, $ordered_values );
		if ( '' === $filled_xml ) {
			$this->log_docx_event( 'fill_with_ai_xml_replace_failed', array( 'lease_id' => $lease_id ) );
			return false;
		}

		// Step 8: Write the filled DOCX.
		$result = $this->write_processed_docx( $source_path, $filled_xml, $output_path );
		if ( '' === $result ) {
			$this->log_docx_event( 'fill_with_ai_write_failed', array( 'lease_id' => $lease_id ) );
			return false;
		}

		$filled_count = 0;
		foreach ( $ordered_values as $v ) {
			if ( null !== $v ) {
				$filled_count++;
			}
		}

		$this->log_docx_event( 'fill_with_ai_success', array(
			'lease_id'     => $lease_id,
			'blanks_total' => $blank_count,
			'blanks_filled'=> $filled_count,
			'output_path'  => $result,
		) );

		return true;
	}

	/**
	 * Analyzes a DOCX template at upload time and returns segments for preview.
	 *
	 * Each segment is either plain text or a detected blank with its AI-inferred field.
	 * The owner can review and edit the assignments before saving.
	 *
	 * @param string                    $source_path Path to the DOCX file.
	 * @param Arriendo_Facil_AI_Service $ai_service  AI service for field analysis.
	 * @return array|WP_Error { segments: array, field_map: array } or WP_Error.
	 */
	public function analyze_template_for_preview( $source_path, $ai_service ) {
		$source_path = (string) $source_path;

		if ( '' === $source_path || ! file_exists( $source_path ) || ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'invalid_source', __( 'Invalid DOCX file.', 'arriendo-facil' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $source_path ) ) {
			return new WP_Error( 'zip_failed', __( 'Cannot open DOCX file.', 'arriendo-facil' ) );
		}

		$doc_xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $doc_xml || '' === trim( (string) $doc_xml ) ) {
			return new WP_Error( 'xml_empty', __( 'DOCX contains no document content.', 'arriendo-facil' ) );
		}

		$doc_xml   = (string) $doc_xml;
		$flat_text = $this->extract_flat_text_from_doc_xml( $doc_xml );

		if ( '' === $flat_text ) {
			return new WP_Error( 'text_empty', __( 'Could not extract text from document.', 'arriendo-facil' ) );
		}

		$blank_pattern = '/_{3,}|\.{4,}|…{2,}|-{4,}|\t{2,}/u';
		if ( ! preg_match_all( $blank_pattern, $flat_text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return new WP_Error( 'no_blanks', __( 'No blank fields detected in the document.', 'arriendo-facil' ) );
		}

		// Filter decorative blanks (PDF-to-Word artifacts).
		$matches[0] = $this->filter_decorative_blanks( $matches[0], $flat_text );

		if ( empty( $matches[0] ) ) {
			return new WP_Error( 'no_blanks', __( 'No blank fields detected in the document.', 'arriendo-facil' ) );
		}

		$blanks_for_ai = array();
		foreach ( $matches[0] as $idx => $match ) {
			$blank_text = (string) $match[0];
			$offset     = (int) $match[1];
			$before     = substr( $flat_text, max( 0, $offset - 200 ), min( 200, $offset ) );
			$after      = substr( $flat_text, $offset + strlen( $blank_text ), 200 );

			$blanks_for_ai[] = array(
				'blank_index' => $idx,
				'before'      => $before,
				'after'       => $after,
				'blank'       => $blank_text,
			);
		}

		$field_map = array();

		if ( $ai_service && method_exists( $ai_service, 'analyze_template_fields' ) ) {
			try {
				$ai_result = $ai_service->analyze_template_fields( array(
					'contract_text' => $flat_text,
					'blanks'        => $blanks_for_ai,
				) );

				if ( ! is_wp_error( $ai_result ) && isset( $ai_result['field_map'] ) && is_array( $ai_result['field_map'] ) ) {
					$field_map = $this->validate_ai_field_map( $ai_result['field_map'], $blanks_for_ai );
				}
			} catch ( \Throwable $e ) {
				$this->log_docx_event( 'analyze_preview_ai_exception', array( 'error' => $e->getMessage() ) );
			}
		}

		if ( empty( $field_map ) ) {
			foreach ( $blanks_for_ai as $blank_info ) {
				$idx         = (int) $blank_info['blank_index'];
				$before      = isset( $blank_info['before'] ) ? (string) $blank_info['before'] : '';
				$after       = isset( $blank_info['after'] ) ? (string) $blank_info['after'] : '';
				$placeholder = $this->infer_placeholder_from_context( $before, $after, $idx );

				$field_key = 'none';
				$label     = 'Dejar vacío';
				$source    = 'none';

				if ( 0 !== strpos( $placeholder, 'CAMPO_' ) ) {
					$canonical = array_flip( self::CANONICAL_TO_PLACEHOLDER );
					if ( isset( $canonical[ $placeholder ] ) ) {
						$field_key = $canonical[ $placeholder ];
						$label     = $this->get_field_label( $field_key );
						$source    = $this->get_field_source( $field_key );
					}
				}

				$field_map[] = array(
					'blank_index' => $idx,
					'field_key'   => $field_key,
					'label'       => $label,
					'source'      => $source,
				);
			}
		}

		$segments    = array();
		$last_offset = 0;

		foreach ( $matches[0] as $idx => $match ) {
			$blank_text = (string) $match[0];
			$offset     = (int) $match[1];

			if ( $offset > $last_offset ) {
				$segments[] = array(
					'type'    => 'text',
					'content' => substr( $flat_text, $last_offset, $offset - $last_offset ),
				);
			}

			$field_info = null;
			foreach ( $field_map as $fm ) {
				if ( isset( $fm['blank_index'] ) && (int) $fm['blank_index'] === $idx ) {
					$field_info = $fm;
					break;
				}
			}

			if ( ! $field_info ) {
				$field_info = array(
					'blank_index' => $idx,
					'field_key'   => 'none',
					'label'       => 'Dejar vacío',
					'source'      => 'none',
				);
			}

			$segments[] = array(
				'type'        => 'field',
				'blank_index' => $idx,
				'field_key'   => $field_info['field_key'],
				'label'       => $field_info['label'],
				'source'      => $field_info['source'],
			);

			$last_offset = $offset + strlen( $blank_text );
		}

		if ( $last_offset < strlen( $flat_text ) ) {
			$segments[] = array(
				'type'    => 'text',
				'content' => substr( $flat_text, $last_offset ),
			);
		}

		$this->log_docx_event( 'analyze_preview_success', array(
			'blanks_found' => count( $matches[0] ),
			'segments'     => count( $segments ),
		) );

		return array(
			'segments'  => $segments,
			'field_map' => $field_map,
		);
	}

	/**
	 * Fills a DOCX template using a pre-approved field map (no AI at generation time).
	/**
	 * Deterministic context-based filling (no AI needed).
	 * Reads surrounding text of each blank and assigns the correct value via keyword rules.
	 */
	public function fill_template_with_context( $source_path, $output_path, array $payload ) {
		$source_path = (string) $source_path;
		$output_path = (string) $output_path;
		$lease_id    = isset( $payload['lease_id'] ) ? (int) $payload['lease_id'] : 0;

		if ( '' === $source_path || ! file_exists( $source_path ) || ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $source_path ) ) {
			return false;
		}
		$doc_xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $doc_xml || '' === trim( (string) $doc_xml ) ) {
			return false;
		}

		$doc_xml   = (string) $doc_xml;
		$flat_text = $this->extract_flat_text_from_doc_xml( $doc_xml );
		if ( '' === $flat_text ) {
			return false;
		}

		$blank_pattern = '/_{3,}|\.{4,}|…{2,}|-{4,}|\t{2,}/u';
		if ( ! preg_match_all( $blank_pattern, $flat_text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}

		$matches[0] = $this->filter_decorative_blanks( $matches[0], $flat_text );
		if ( empty( $matches[0] ) ) {
			return false;
		}

		$decomposed = $this->build_decomposed_values( $payload );
		$ordered_values = array();
		$state = array(
			'arrendador_name_placed'   => false,
			'arrendatario_name_placed' => false,
			'arrendador_id_placed'     => false,
			'arrendatario_id_placed'   => false,
			'start_date_state'         => 0,
			'end_date_state'           => 0,
			'rent_state'               => 0,
			'guarantee_state'          => 0,
		);

		foreach ( $matches[0] as $idx => $match ) {
			$offset = $match[1];
			$before = mb_strtolower( mb_substr( $flat_text, max( 0, $offset - 120 ), min( $offset, 120 ) ) );
			$after  = mb_strtolower( mb_substr( $flat_text, $offset + mb_strlen( $match[0] ), 80 ) );

			$value = $this->determine_value_from_context( $before, $after, $decomposed, $state );
			$ordered_values[] = $value;
		}

		$filled_xml = $this->replace_blanks_in_xml_with_values( $doc_xml, $ordered_values );
		if ( '' === $filled_xml ) {
			return false;
		}

		$result = $this->write_processed_docx( $source_path, $filled_xml, $output_path );
		if ( '' === $result ) {
			return false;
		}

		$filled_count = count( array_filter( $ordered_values, function( $v ) { return null !== $v; } ) );
		$this->log_docx_event( 'fill_with_context_success', array(
			'lease_id'      => $lease_id,
			'blanks_total'  => count( $matches[0] ),
			'blanks_filled' => $filled_count,
			'output_path'   => $result,
		) );

		return true;
	}

	private function build_decomposed_values( array $payload ) {
		$months_es = array(
			1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
			5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
			9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
		);

		$start_date = $this->val( $payload, 'start_date' );
		$end_date   = $this->val( $payload, 'end_date' );

		$start_day = '';
		$start_month = '';
		$start_year = '';
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $start_date, $m ) ) {
			$start_day   = ltrim( $m[3], '0' );
			$start_month = isset( $months_es[ (int) $m[2] ] ) ? $months_es[ (int) $m[2] ] : $m[2];
			$start_year  = $m[1];
		}

		$end_day = '';
		$end_month = '';
		$end_year = '';
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $end_date, $m ) ) {
			$end_day   = ltrim( $m[3], '0' );
			$end_month = isset( $months_es[ (int) $m[2] ] ) ? $months_es[ (int) $m[2] ] : $m[2];
			$end_year  = $m[1];
		}

		$monthly_rent = isset( $payload['monthly_rent'] ) ? (float) $payload['monthly_rent'] : 0.0;
		$guarantee_amount = $monthly_rent * 2;

		$city    = $this->val( $payload, 'accommodation_city' );
		$address = $this->val( $payload, 'accommodation_address' );
		if ( '' === $city && '' !== $address ) {
			$parts = array_map( 'trim', explode( ',', $address ) );
			if ( count( $parts ) >= 2 ) {
				$city = end( $parts );
			}
		}

		return array(
			'owner_name'       => $this->val( $payload, 'owner_name' ),
			'owner_id_number'  => $this->val( $payload, 'owner_id_number' ),
			'guest_name'       => $this->val( $payload, 'guest_name' ),
			'guest_id_number'  => $this->val( $payload, 'guest_id_number' ),
			'guest_phone'      => $this->val( $payload, 'guest_phone' ),
			'guest_email'      => $this->val( $payload, 'guest_email' ),
			'address'          => $address,
			'city'             => $city,
			'square_meters'    => $this->val( $payload, 'accommodation_square_meters' ),
			'start_day'        => $start_day,
			'start_month'      => $start_month,
			'start_year'       => $start_year,
			'end_day'          => $end_day,
			'end_month'        => $end_month,
			'end_year'         => $end_year,
			'rent_number'      => $monthly_rent > 0 ? number_format( $monthly_rent, 2, '.', '' ) : '',
			'rent_words'       => $monthly_rent > 0 ? $this->number_to_spanish_words( $monthly_rent ) : '',
			'guarantee_number' => $guarantee_amount > 0 ? number_format( $guarantee_amount, 2, '.', '' ) : '',
			'guarantee_words'  => $guarantee_amount > 0 ? $this->number_to_spanish_words( $guarantee_amount ) : '',
		);
	}

	private function determine_value_from_context( $before, $after, array $vals, array &$state ) {
		// ESTADO CIVIL - always leave blank (check first to avoid false matches)
		if ( $this->ctx_matches( $before, array( 'estado civil' ) ) ) {
			return null;
		}

		// NAMES: detect arrendador vs arrendatario name context
		if ( $this->ctx_matches( $before, array( 'señor', 'sr.', 'sra.' ) ) || $this->ctx_matches( $after, array( ', portador' ) ) ) {
			if ( ! $state['arrendador_name_placed'] && ( $this->ctx_is_arrendador( $before, $after ) || ! $state['arrendador_name_placed'] ) ) {
				$state['arrendador_name_placed'] = true;
				return $vals['owner_name'] ?: null;
			}
			if ( ! $state['arrendatario_name_placed'] ) {
				$state['arrendatario_name_placed'] = true;
				return $vals['guest_name'] ?: null;
			}
		}

		// CEDULA / ID numbers
		if ( $this->ctx_matches( $before, array( 'cédula', 'cedula', 'c.c.', 'c.i.', 'identidad no' ) ) ) {
			if ( ! $state['arrendador_id_placed'] && ( $this->ctx_is_arrendador( $before, $after ) || ! $state['arrendador_id_placed'] ) ) {
				$state['arrendador_id_placed'] = true;
				return $vals['owner_id_number'] ?: null;
			}
			if ( ! $state['arrendatario_id_placed'] ) {
				$state['arrendatario_id_placed'] = true;
				return $vals['guest_id_number'] ?: null;
			}
		}

		// CITY - "ciudad de ___"
		if ( $this->ctx_matches( $before, array( 'ciudad de' ) ) ) {
			return $vals['city'] ?: null;
		}

		// PROVINCE / CANTON / PARISH
		if ( $this->ctx_matches( $before, array( 'provincia de' ) ) ) {
			return $vals['city'] ?: null;
		}
		if ( $this->ctx_matches( $before, array( 'cantón ', 'canton ' ) ) && ! $this->ctx_matches( $before, array( 'jueces', 'jurisdicción', 'competencia' ) ) ) {
			return $vals['city'] ?: null;
		}
		if ( $this->ctx_matches( $before, array( 'parroquia' ) ) ) {
			return $vals['city'] ?: null;
		}

		// ADDRESS
		if ( $this->ctx_matches( $before, array( 'dirección exacta', 'direccion exacta', 'dirección en', 'ubicado en:', 'ubicada en:' ) ) ) {
			return $vals['address'] ?: null;
		}

		// SQUARE METERS
		if ( $this->ctx_matches( $after, array( 'metros cuadrados', 'm²', 'm2' ) ) ) {
			return $vals['square_meters'] ?: null;
		}

		// START DATE - "el día ___ de ___ del año 20___"
		if ( 0 === $state['start_date_state'] && $this->ctx_matches( $before, array( 'a partir del día', 'regir a partir', 'empezará a regir' ) ) ) {
			$state['start_date_state'] = 1;
			return $vals['start_day'] ?: null;
		}
		if ( 1 === $state['start_date_state'] && $this->ctx_matches( $after, array( 'del año' ) ) ) {
			$state['start_date_state'] = 2;
			return $vals['start_month'] ?: null;
		}
		if ( 2 === $state['start_date_state'] && $this->ctx_matches( $before, array( 'del año', 'año 20' ) ) ) {
			$state['start_date_state'] = 3;
			return '' !== $vals['start_year'] ? substr( $vals['start_year'], -2 ) : null;
		}

		// END DATE - "fenecerá el día ___"
		if ( 0 === $state['end_date_state'] && $this->ctx_matches( $before, array( 'fenecerá el día', 'fenecera el dia', 'vencerá el día', 'terminará el' ) ) ) {
			$state['end_date_state'] = 1;
			return $vals['end_day'] ?: null;
		}
		if ( 1 === $state['end_date_state'] && $this->ctx_matches( $after, array( 'del año' ) ) ) {
			$state['end_date_state'] = 2;
			return $vals['end_month'] ?: null;
		}
		if ( 2 === $state['end_date_state'] && $this->ctx_matches( $before, array( 'del año', 'año 20' ) ) ) {
			$state['end_date_state'] = 3;
			return '' !== $vals['end_year'] ? substr( $vals['end_year'], -2 ) : null;
		}

		// GUARANTEE (check BEFORE rent - guarantee context is more specific)
		if ( 0 === $state['guarantee_state'] && $this->ctx_matches( $after, array( 'dólares', 'dolares' ) ) && $this->ctx_matches( $before, array( 'garantía', 'garantia', 'depósito', 'deposito', 'fianza', 'equivalente a' ) ) ) {
			$state['guarantee_state'] = 1;
			return $vals['guarantee_words'] ?: null;
		}
		if ( 1 === $state['guarantee_state'] && ( $this->ctx_matches( $before, array( '($', '(\\$', '$ ' ) ) || $this->ctx_matches( $after, array( ',00)' ) ) ) ) {
			$state['guarantee_state'] = 2;
			return $vals['guarantee_number'] ?: null;
		}

		// MONTHLY RENT - "_____ DÓLARES" (words) then "($_____,00)" (number)
		if ( 0 === $state['rent_state'] && $this->ctx_matches( $after, array( 'dólares', 'dolares' ) ) ) {
			$state['rent_state'] = 1;
			return $vals['rent_words'] ?: null;
		}
		if ( 1 === $state['rent_state'] && ( $this->ctx_matches( $before, array( '($', '(\\$', '$ ' ) ) || $this->ctx_matches( $after, array( ',00)' ) ) ) ) {
			$state['rent_state'] = 2;
			return $vals['rent_number'] ?: null;
		}

		// CANTON in jurisdiction clause
		if ( $this->ctx_matches( $before, array( 'cantón de', 'canton de' ) ) ) {
			return $vals['city'] ?: null;
		}

		// PHONE
		if ( $this->ctx_matches( $before, array( 'teléfono', 'telefono', 'celular', 'móvil' ) ) ) {
			return $vals['guest_phone'] ?: null;
		}

		// EMAIL
		if ( $this->ctx_matches( $before, array( 'correo', 'email', 'e-mail' ) ) ) {
			return $vals['guest_email'] ?: null;
		}

		// "días del mes de ___" (signing date at the end)
		if ( $this->ctx_matches( $before, array( 'días del mes de', 'del mes de' ) ) && $this->ctx_matches( $after, array( 'del año', 'año' ) ) ) {
			return $vals['start_month'] ?: null;
		}

		return null;
	}

	private function ctx_matches( $text, array $keywords ) {
		foreach ( $keywords as $kw ) {
			if ( false !== mb_strpos( $text, $kw ) ) {
				return true;
			}
		}
		return false;
	}

	private function ctx_is_arrendador( $before, $after ) {
		return $this->ctx_matches( $before, array( 'arrendador', 'propietario' ) )
			|| $this->ctx_matches( $after, array( 'arrendador', 'propietario' ) );
	}

	private function ctx_is_arrendatario( $before, $after ) {
		return $this->ctx_matches( $before, array( 'arrendatario', 'inquilino' ) )
			|| $this->ctx_matches( $after, array( 'arrendatario', 'inquilino' ) );
	}

	/**
	 * @param string $source_path Path to original DOCX template.
	 * @param string $output_path Destination for filled contract.
	 * @param array  $payload     Lease and guest data from chatbot.
	 * @param array  $field_map   Approved field map from owner preview.
	 * @return bool True on success.
	 */
	public function fill_template_with_field_map( $source_path, $output_path, array $payload, array $field_map ) {
		$source_path = (string) $source_path;
		$output_path = (string) $output_path;
		$lease_id    = isset( $payload['lease_id'] ) ? (int) $payload['lease_id'] : 0;

		if ( '' === $source_path || ! file_exists( $source_path ) || ! class_exists( 'ZipArchive' ) ) {
			$this->log_docx_event( 'fill_with_field_map_failed', array( 'reason' => 'source_invalid', 'lease_id' => $lease_id ) );
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $source_path ) ) {
			$this->log_docx_event( 'fill_with_field_map_failed', array( 'reason' => 'zip_open_failed', 'lease_id' => $lease_id ) );
			return false;
		}

		$doc_xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $doc_xml || '' === trim( (string) $doc_xml ) ) {
			$this->log_docx_event( 'fill_with_field_map_failed', array( 'reason' => 'xml_empty', 'lease_id' => $lease_id ) );
			return false;
		}

		$doc_xml   = (string) $doc_xml;
		$flat_text = $this->extract_flat_text_from_doc_xml( $doc_xml );

		if ( '' === $flat_text ) {
			$this->log_docx_event( 'fill_with_field_map_failed', array( 'reason' => 'flat_text_empty', 'lease_id' => $lease_id ) );
			return false;
		}

		$blank_pattern = '/_{3,}|\.{4,}|…{2,}|-{4,}|\t{2,}/u';
		if ( ! preg_match_all( $blank_pattern, $flat_text, $matches, PREG_OFFSET_CAPTURE ) ) {
			$this->log_docx_event( 'fill_with_field_map_failed', array( 'reason' => 'no_blanks', 'lease_id' => $lease_id ) );
			return false;
		}

		// Filter decorative blanks (PDF-to-Word artifacts).
		$matches[0] = $this->filter_decorative_blanks( $matches[0], $flat_text );

		if ( empty( $matches[0] ) ) {
			$this->log_docx_event( 'fill_with_field_map_failed', array( 'reason' => 'no_blanks_after_filter', 'lease_id' => $lease_id ) );
			return false;
		}

		$values = $this->build_placeholder_values( $payload );

		$field_map_by_index = array();
		foreach ( $field_map as $fm ) {
			if ( isset( $fm['blank_index'] ) ) {
				$field_map_by_index[ (int) $fm['blank_index'] ] = $fm;
			}
		}

		$ordered_values = array();
		$filled_count   = 0;

		foreach ( $matches[0] as $idx => $match ) {
			if ( ! isset( $field_map_by_index[ $idx ] ) ) {
				$ordered_values[] = null;
				continue;
			}

			$fm        = $field_map_by_index[ $idx ];
			$field_key = isset( $fm['field_key'] ) ? (string) $fm['field_key'] : 'none';

			if ( 'none' === $field_key || '' === $field_key ) {
				$ordered_values[] = null;
				continue;
			}

			if ( isset( self::CANONICAL_TO_PLACEHOLDER[ $field_key ] ) ) {
				$placeholder = self::CANONICAL_TO_PLACEHOLDER[ $field_key ];
				if ( isset( $values[ $placeholder ] ) && '...............' !== $values[ $placeholder ] ) {
					$ordered_values[] = $values[ $placeholder ];
					$filled_count++;
				} else {
					$ordered_values[] = null;
				}
			} else {
				$ordered_values[] = null;
			}
		}

		$filled_xml = $this->replace_blanks_in_xml_with_values( $doc_xml, $ordered_values );
		if ( '' === $filled_xml ) {
			$this->log_docx_event( 'fill_with_field_map_failed', array( 'reason' => 'xml_replace_failed', 'lease_id' => $lease_id ) );
			return false;
		}

		$result = $this->write_processed_docx( $source_path, $filled_xml, $output_path );
		if ( '' === $result ) {
			$this->log_docx_event( 'fill_with_field_map_failed', array( 'reason' => 'write_failed', 'lease_id' => $lease_id ) );
			return false;
		}

		$this->log_docx_event( 'fill_with_field_map_success', array(
			'lease_id'      => $lease_id,
			'blanks_total'  => count( $matches[0] ),
			'blanks_filled' => $filled_count,
			'output_path'   => $result,
		) );

		return true;
	}

	/**
	 * Returns a human-readable label for a canonical field key.
	 *
	 * @param string $field_key Canonical field key.
	 * @return string
	 */
	private function get_field_label( $field_key ) {
		$labels = array(
			'guest_name'            => 'Nombre del arrendatario',
			'guest_id_number'       => 'Cédula del arrendatario',
			'guest_phone'           => 'Teléfono del arrendatario',
			'guest_email'           => 'Email del arrendatario',
			'owner_name'            => 'Nombre del arrendador',
			'owner_id_number'       => 'Cédula del arrendador',
			'accommodation_title'   => 'Nombre del inmueble',
			'accommodation_address' => 'Dirección del inmueble',
			'accommodation_city'    => 'Ciudad',
			'accommodation_square_meters' => 'Metros cuadrados (m²)',
			'accommodation_bedrooms'      => 'Número de habitaciones',
			'accommodation_bathrooms'     => 'Número de baños',
			'accommodation_property_type' => 'Tipo de inmueble',
			'monthly_rent'          => 'Canon mensual',
			'guarantee_text'        => 'Garantía',
			'start_date'            => 'Inicio del arriendo',
			'end_date'              => 'Fin del arriendo',
			'current_date'          => 'Fecha actual completa',
			'current_day'           => 'Día actual',
			'current_month_name'    => 'Mes actual',
			'current_year'          => 'Año actual',
			'none'                  => 'Dejar vacío',
		);

		return isset( $labels[ $field_key ] ) ? $labels[ $field_key ] : $field_key;
	}

	/**
	 * Returns the source type for a canonical field key.
	 *
	 * @param string $field_key Canonical field key.
	 * @return string chatbot|owner|system|none
	 */
	private function get_field_source( $field_key ) {
		$sources = array(
			'guest_name'            => 'chatbot',
			'guest_id_number'       => 'chatbot',
			'guest_phone'           => 'chatbot',
			'guest_email'           => 'chatbot',
			'owner_name'            => 'owner',
			'owner_id_number'       => 'owner',
			'accommodation_title'   => 'system',
			'accommodation_address' => 'system',
			'accommodation_city'    => 'system',
			'accommodation_square_meters' => 'system',
			'accommodation_bedrooms'      => 'system',
			'accommodation_bathrooms'     => 'system',
			'accommodation_property_type' => 'system',
			'monthly_rent'          => 'system',
			'guarantee_text'        => 'system',
			'start_date'            => 'system',
			'end_date'              => 'system',
			'current_date'          => 'system',
			'current_day'           => 'system',
			'current_month_name'    => 'system',
			'current_year'          => 'system',
			'none'                  => 'none',
		);

		return isset( $sources[ $field_key ] ) ? $sources[ $field_key ] : 'none';
	}

	/**
	 * Validates AI replacements by cross-checking each value against its context.
	 *
	 * Catches errors like: name in an ID field, ID number in a name field,
	 * same value repeated for unrelated blanks, values in leave-blank fields.
	 *
	 * @param array $replacements AI-returned blank_id => value map.
	 * @param array $ai_lines     Blank context items (id, before, after).
	 * @param array $payload      Original chatbot payload.
	 * @return array Validated replacements (bad ones removed).
	 */
	private function validate_ai_replacements( array $replacements, array $ai_lines, array $payload ) {
		$guest_name  = $this->val( $payload, 'guest_name' );
		$owner_name  = $this->val( $payload, 'owner_name' );
		$guest_id    = $this->val( $payload, 'guest_id_number' );
		$owner_id    = $this->val( $payload, 'owner_id_number' );

		$lines_by_id = array();
		foreach ( $ai_lines as $line ) {
			$id = isset( $line['id'] ) ? (string) $line['id'] : '';
			if ( '' !== $id ) {
				$lines_by_id[ $id ] = $line;
			}
		}

		$leave_blank_keywords = array(
			'dedicarlo a', 'accesorios', 'chapas con', 'uso y goce',
			'recibido', 'corresponde', 'plazo', 'servicios basico',
			'jueces competentes', 'estado civil', 'profesion',
			'conjunto habitacional', 'etapa', 'manzana',
			'firma del presente contrato el dia', 'primer mes de garantia',
			'segundo mes de garantia', 'entregadas las llaves',
		);

		$validated = array();

		foreach ( $replacements as $blank_id => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			if ( ! isset( $lines_by_id[ $blank_id ] ) ) {
				$validated[ $blank_id ] = $value;
				continue;
			}

			$before = $this->normalize_context_text( isset( $lines_by_id[ $blank_id ]['before'] ) ? (string) $lines_by_id[ $blank_id ]['before'] : '' );
			$after  = $this->normalize_context_text( isset( $lines_by_id[ $blank_id ]['after'] ) ? (string) $lines_by_id[ $blank_id ]['after'] : '' );

			// Rule 1: Leave-blank fields should never be filled.
			$skip = false;
			foreach ( $leave_blank_keywords as $kw ) {
				if ( false !== strpos( $before, $kw ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				$this->log_docx_event( 'validate_ai_rejected', array( 'blank' => $blank_id, 'reason' => 'leave_blank_field', 'value' => substr( $value, 0, 30 ) ) );
				continue;
			}

			// Rule 2: If context says "cédula"/"número"/"C.C." → value must look like a number, not a name.
			$is_id_context = false !== strpos( $before, 'cedula' )
				|| false !== strpos( $before, 'identificacion' )
				|| false !== strpos( $before, 'consignado con el numero' )
				|| 1 === preg_match( '/c\.?\s*[ci]\.?\s*$/', $before );

			if ( $is_id_context ) {
				$is_numeric_value = (bool) preg_match( '/^\d[\d\-\.]+$/', $value );
				if ( ! $is_numeric_value ) {
					// Value looks like a name but context wants an ID number.
					if ( '' !== $guest_id && $value === $guest_name ) {
						$value = $guest_id;
						$this->log_docx_event( 'validate_ai_corrected', array( 'blank' => $blank_id, 'from' => 'guest_name', 'to' => 'guest_id_number' ) );
					} elseif ( '' !== $owner_id && $value === $owner_name ) {
						$value = $owner_id;
						$this->log_docx_event( 'validate_ai_corrected', array( 'blank' => $blank_id, 'from' => 'owner_name', 'to' => 'owner_id_number' ) );
					} else {
						$this->log_docx_event( 'validate_ai_rejected', array( 'blank' => $blank_id, 'reason' => 'name_in_id_field', 'value' => substr( $value, 0, 30 ) ) );
						continue;
					}
				}
			}

			// Rule 3: If context says "señor"/"nombre" → value should not be a pure number.
			$is_name_context = ( false !== strpos( $before, 'senor' ) || false !== strpos( $before, 'senora' )
				|| false !== strpos( $before, 'sr.' ) || false !== strpos( $before, 'sra.' )
				|| false !== strpos( $before, 'srta' ) || false !== strpos( $before, 'arrendatario senor' ) )
				&& false === strpos( $before, 'cedula' ) && false === strpos( $before, 'numero' );

			if ( $is_name_context && preg_match( '/^\d[\d\-\.]+$/', $value ) ) {
				// Value looks like an ID number but context wants a name.
				if ( '' !== $guest_name && $value === $guest_id ) {
					$value = $guest_name;
					$this->log_docx_event( 'validate_ai_corrected', array( 'blank' => $blank_id, 'from' => 'guest_id', 'to' => 'guest_name' ) );
				} elseif ( '' !== $owner_name && $value === $owner_id ) {
					$value = $owner_name;
					$this->log_docx_event( 'validate_ai_corrected', array( 'blank' => $blank_id, 'from' => 'owner_id', 'to' => 'owner_name' ) );
				} else {
					$this->log_docx_event( 'validate_ai_rejected', array( 'blank' => $blank_id, 'reason' => 'id_in_name_field', 'value' => substr( $value, 0, 30 ) ) );
					continue;
				}
			}

			// Rule 4: If context says "arrendador"/"propietario" and value = guest_name, swap.
			$is_owner_context = ( false !== strpos( $after, 'arrendador' ) && false === strpos( $after, 'arrendatario' ) )
				|| false !== strpos( $after, 'propietario' )
				|| false !== strpos( $before, 'como arrendador' )
				|| false !== strpos( $after, 'en calidad de arrendador' );

			if ( $is_owner_context && '' !== $guest_name && $value === $guest_name && '' !== $owner_name ) {
				$value = $owner_name;
				$this->log_docx_event( 'validate_ai_corrected', array( 'blank' => $blank_id, 'from' => 'guest_name', 'to' => 'owner_name' ) );
			}

			// Rule 5: If context says "arrendatario"/"inquilino" and value = owner_name, swap.
			$is_guest_context = ( false !== strpos( $after, 'arrendatario' ) && false === strpos( $after, 'arrendador' ) )
				|| false !== strpos( $before, 'arrendamiento al senor' )
				|| false !== strpos( $before, 'como arrendatario' )
				|| false !== strpos( $after, 'en calidad de arrendatario' );

			if ( $is_guest_context && '' !== $owner_name && $value === $owner_name && '' !== $guest_name ) {
				$value = $guest_name;
				$this->log_docx_event( 'validate_ai_corrected', array( 'blank' => $blank_id, 'from' => 'owner_name', 'to' => 'guest_name' ) );
			}

			// Rule 6: Date format correction — full date in a day-only context.
			$is_day_context = ( 1 === preg_match( '/a\s+los\s*$/', $before ) && false !== strpos( $after, 'dias' ) )
				|| ( 1 === preg_match( '/(,|el)\s*$/', $before ) && 1 === preg_match( '/^\s*de\s+[a-z]/', $after ) );
			if ( $is_day_context && preg_match( '#^\d{1,2}/\d{1,2}/\d{4}$#', $value ) ) {
				$value = gmdate( 'j' );
				$this->log_docx_event( 'validate_ai_corrected', array( 'blank' => $blank_id, 'reason' => 'full_date_in_day_field' ) );
			}

			// Rule 7: Month context — replace full date or numeric with month name.
			$is_month_context = ( false !== strpos( $before, 'del mes de' ) || false !== strpos( $before, 'dias del mes de' ) )
				&& ( 1 === preg_match( '/^\s*de[l\s]/', $after ) );
			if ( $is_month_context && preg_match( '#^\d{1,2}/\d{1,2}/\d{4}$#', $value ) ) {
				$value = $this->get_spanish_month_name( (int) gmdate( 'n' ) );
				$this->log_docx_event( 'validate_ai_corrected', array( 'blank' => $blank_id, 'reason' => 'full_date_in_month_field' ) );
			}

			// Rule 8: Year context — replace full date with year only.
			$is_year_context = 1 === preg_match( '/del?\s*$/', $before )
				&& ( false !== strpos( $before, 'mes de' ) || false !== strpos( $before, 'dias' ) );
			if ( $is_year_context && preg_match( '#^\d{1,2}/\d{1,2}/\d{4}$#', $value ) ) {
				$value = gmdate( 'Y' );
				$this->log_docx_event( 'validate_ai_corrected', array( 'blank' => $blank_id, 'reason' => 'full_date_in_year_field' ) );
			}

			// Rule 9: Reject values that look like names in date/city contexts.
			$is_date_or_city_context = $is_day_context || $is_month_context || $is_year_context
				|| false !== strpos( $before, 'ciudad de' );
			if ( $is_date_or_city_context && '' !== $guest_name && $value === $guest_name ) {
				$this->log_docx_event( 'validate_ai_rejected', array( 'blank' => $blank_id, 'reason' => 'name_in_date_city_field' ) );
				continue;
			}

			// Rule 10: Strip duplicate currency prefix when context already has "USD".
			if ( 1 === preg_match( '/usd\s*\$?\s*$/', $before ) && 1 === preg_match( '/^USD\s*/i', $value ) ) {
				$value = preg_replace( '/^USD\s*/i', '', $value );
			}

			$validated[ $blank_id ] = $value;
		}

		return $validated;
	}

	/**
	 * Maps blanks to values using context rules when AI is unavailable.
	 *
	 * @param array $ai_lines  Blank context items.
	 * @param array $values    Available placeholder values.
	 * @return array<string,string> blank_id => replacement value.
	 */
	private function map_blanks_by_context_rules( array $ai_lines, array $values ) {
		$result = array();

		foreach ( $ai_lines as $line ) {
			$blank_id = isset( $line['id'] ) ? (string) $line['id'] : '';
			$before   = isset( $line['before'] ) ? (string) $line['before'] : '';
			$after    = isset( $line['after'] ) ? (string) $line['after'] : '';

			$idx = 0;
			if ( preg_match( '/(\d+)$/', $blank_id, $m ) ) {
				$idx = (int) $m[1];
			}

			$placeholder = $this->infer_placeholder_from_context( $before, $after, $idx );

			if ( 0 === strpos( $placeholder, 'CAMPO_' ) ) {
				continue;
			}

			if ( isset( $values[ $placeholder ] ) && '...............' !== $values[ $placeholder ] ) {
				$result[ $blank_id ] = $values[ $placeholder ];
			}
		}

		return $result;
	}

	/**
	 * Replaces blank sequences in the DOCX XML with actual values, preserving formatting.
	 *
	 * Uses DOM traversal to handle blanks split across multiple <w:t> nodes.
	 *
	 * @param string     $doc_xml        Raw word/document.xml content.
	 * @param list<string|null> $ordered_values Values in document order. null = keep original.
	 * @return string Modified XML, or '' on failure.
	 */
	private function replace_blanks_in_xml_with_values( $doc_xml, array $ordered_values ) {
		if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
			return $this->replace_blanks_in_xml_with_values_regex( $doc_xml, $ordered_values );
		}

		$dom = new DOMDocument();
		if ( ! @$dom->loadXML( (string) $doc_xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
			return $this->replace_blanks_in_xml_with_values_regex( $doc_xml, $ordered_values );
		}

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
		$text_nodes = $xpath->query( '//w:t|//w:tab' );

		if ( ! $text_nodes || 0 === $text_nodes->length ) {
			return $this->replace_blanks_in_xml_with_values_regex( $doc_xml, $ordered_values );
		}

		// Collect all nodes and build char-level entries.
		$nodes = array();
		foreach ( $text_nodes as $node ) {
			$nodes[] = $node;
		}

		$entries     = array();
		$nodes_chars = array();
		$node_kind   = array();

		foreach ( $nodes as $node_index => $node ) {
			$local_name = (string) $node->localName;
			if ( 'tab' === $local_name ) {
				$node_kind[ $node_index ] = 'tab';
				$entries[] = array( 'node' => $node_index, 'char' => 0, 'text' => "\t", 'kind' => 'tab' );
				continue;
			}

			$node_kind[ $node_index ] = 'text';
			$text  = (string) $node->textContent;
			$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
			if ( ! is_array( $chars ) ) {
				$chars = array();
			}

			$nodes_chars[ $node_index ] = $chars;
			foreach ( $chars as $char_index => $char ) {
				$entries[] = array( 'node' => $node_index, 'char' => $char_index, 'text' => $char, 'kind' => 'text' );
			}
		}

		if ( empty( $entries ) ) {
			return '';
		}

		// Find blank sequences and map to ordered_values.
		$replace_at = array();
		$remove_at  = array();
		$counter    = 0;
		$total      = count( $entries );
		$i          = 0;

		while ( $i < $total ) {
			$char = $entries[ $i ]['text'];
			if ( ! $this->is_blank_marker_char_extended( $char ) ) {
				$i++;
				continue;
			}

			$start       = $i;
			$blank_count = 1;
			$j           = $i + 1;

			while ( $j < $total ) {
				$next_char = $entries[ $j ]['text'];
				if ( $this->is_blank_marker_char_extended( $next_char ) ) {
					$blank_count++;
					$j++;
					continue;
				}
				if ( preg_match( '/\s/u', $next_char ) && $blank_count < 3 ) {
					$j++;
					continue;
				}
				break;
			}

			$start_char = isset( $entries[ $start ]['text'] ) ? (string) $entries[ $start ]['text'] : '';
			$min_count  = "\t" === $start_char ? 2 : 3;

			if ( $blank_count >= $min_count ) {
				$replacement = isset( $ordered_values[ $counter ] ) ? $ordered_values[ $counter ] : null;
				$counter++;

				if ( null === $replacement ) {
					$i = $j;
					continue;
				}

				// Smart spacing: ensure replacement doesn't collide with adjacent text.
				$char_before_blank = ( $start > 0 && isset( $entries[ $start - 1 ]['text'] ) ) ? $entries[ $start - 1 ]['text'] : ' ';
				$char_after_blank  = ( $j < $total && isset( $entries[ $j ]['text'] ) ) ? $entries[ $j ]['text'] : ' ';

				$needs_space_before = ! preg_match( '/[\s\(\[\"\']/u', $char_before_blank );
				$needs_space_after  = ! preg_match( '/[\s\)\]\.\,\;\:\"\']/u', $char_after_blank );

				if ( $needs_space_before ) {
					$replacement = ' ' . $replacement;
				}
				if ( $needs_space_after ) {
					$replacement = $replacement . ' ';
				}

				$replace_at[ $start ] = $replacement;
				for ( $k = $start; $k < $j; $k++ ) {
					$remove_at[ $k ] = true;
				}
				$remove_at[ $start ] = false;
			}

			$i = $j;
		}

		if ( empty( $replace_at ) ) {
			return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $dom->saveXML( $dom->documentElement );
		}

		// Apply replacements.
		$tab_replace_nodes = array();
		$tab_remove_nodes  = array();

		foreach ( $entries as $entry_index => $entry ) {
			$node_index = (int) $entry['node'];
			$char_index = (int) $entry['char'];
			$kind       = isset( $entry['kind'] ) ? (string) $entry['kind'] : 'text';

			if ( 'tab' === $kind ) {
				if ( isset( $replace_at[ $entry_index ] ) ) {
					$tab_replace_nodes[ $node_index ] = (string) $replace_at[ $entry_index ];
				} elseif ( ! empty( $remove_at[ $entry_index ] ) ) {
					$tab_remove_nodes[ $node_index ] = true;
				}
				continue;
			}

			if ( isset( $replace_at[ $entry_index ] ) ) {
				$nodes_chars[ $node_index ][ $char_index ] = $replace_at[ $entry_index ];
			} elseif ( ! empty( $remove_at[ $entry_index ] ) ) {
				$nodes_chars[ $node_index ][ $char_index ] = '';
			}
		}

		foreach ( $nodes as $node_index => $node ) {
			if ( isset( $node_kind[ $node_index ] ) && 'text' === $node_kind[ $node_index ] && isset( $nodes_chars[ $node_index ] ) ) {
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
			} elseif ( isset( $tab_remove_nodes[ $node_index ] ) ) {
				$parent->removeChild( $node );
			}
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $dom->saveXML( $dom->documentElement );
	}

	/**
	 * Regex fallback for replacing blanks when DOM is unavailable.
	 *
	 * @param string          $doc_xml        Raw XML.
	 * @param list<string|null> $ordered_values Values in document order.
	 * @return string
	 */
	private function replace_blanks_in_xml_with_values_regex( $doc_xml, array $ordered_values ) {
		$counter = 0;

		return (string) preg_replace_callback(
			'/(<w:t(?:[^>]*)>)([\s\S]*?)(<\/w:t>)/',
			function ( $m ) use ( &$counter, $ordered_values ) {
				$open    = $m[1];
				$content = $m[2];
				$close   = $m[3];

				$new_content = (string) preg_replace_callback(
					'/(.)?(_{3,}|\.{4,}|…{2,}|-{4,})(.)?/u',
					function ( $blank_match ) use ( &$counter, $ordered_values ) {
						$replacement = isset( $ordered_values[ $counter ] ) ? $ordered_values[ $counter ] : null;
						$counter++;
						if ( null === $replacement ) {
							return $blank_match[0];
						}
						$value = htmlspecialchars( $replacement, ENT_COMPAT, 'UTF-8' );
						$before_char = isset( $blank_match[1] ) ? $blank_match[1] : '';
						$after_char  = isset( $blank_match[3] ) ? $blank_match[3] : '';
						$prefix = ( '' !== $before_char && ! preg_match( '/[\s\(\["\']/', $before_char ) ) ? ' ' : '';
						$suffix = ( '' !== $after_char && ! preg_match( '/[\s\)\]\.,;:"\']/', $after_char ) ) ? ' ' : '';
						return $before_char . $prefix . $value . $suffix . $after_char;
					},
					$content
				);

				return $open . $new_content . $close;
			},
			(string) $doc_xml
		);
	}

	/**
	 * Extended blank marker detection including dashes.
	 *
	 * @param string $char One UTF-8 character.
	 * @return bool
	 */
	private function is_blank_marker_char_extended( $char ) {
		return '_' === $char || '.' === $char || '…' === $char || '-' === $char || "\t" === $char;
	}

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
			$this->log_docx_event( 'process_owner_template_failed', array( 'reason' => 'source_invalid' ) );
			return '';
		}

		// Read word/document.xml from the original DOCX.
		$zip = new ZipArchive();
		if ( true !== $zip->open( $source_path ) ) {
			$this->log_docx_event( 'process_owner_template_failed', array( 'reason' => 'zip_open_failed' ) );
			return '';
		}

		$doc_xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $doc_xml || '' === trim( (string) $doc_xml ) ) {
			$this->log_docx_event( 'process_owner_template_failed', array( 'reason' => 'document_xml_empty' ) );
			return '';
		}

		// Step 1: Convert any legacy {{TOKEN}} / [[TOKEN]] already in the template.
		$doc_xml = $this->convert_legacy_tokens( (string) $doc_xml );

		// Step 2: If the template already contains ${...} placeholders, use it as-is.
		if ( false !== strpos( $doc_xml, '${' ) ) {
			if ( '' === $output_path ) {
				$output_path = $this->generate_output_path( $source_path );
			}
			$this->log_docx_event( 'process_owner_template_skipped', array( 'reason' => 'already_has_placeholders' ) );
			return $this->write_processed_docx( $source_path, $doc_xml, $output_path );
		}

		// Step 3: Extract paragraphs that contain blank sequences.
		$blank_paragraphs = $this->extract_paragraphs_with_blanks( $doc_xml );

		if ( empty( $blank_paragraphs ) ) {
			// No blanks found — copy as-is so the path is still saved.
			if ( '' === $output_path ) {
				$output_path = $this->generate_output_path( $source_path );
			}
			error_log( 'Arriendo Facil process_owner_template: no blanks detected in document - will copy as-is' );
			return $this->write_processed_docx( $source_path, $doc_xml, $output_path );
		}

		$blank_count = 0;
		foreach ( $blank_paragraphs as $para_info ) {
			$blank_count += isset( $para_info['blank_count'] ) ? (int) $para_info['blank_count'] : 0;
		}

		error_log( 'Arriendo Facil process_owner_template: detected ' . $blank_count . ' blanks in ' . count( $blank_paragraphs ) . ' paragraphs' );

		// Step 4: Resolve blank occurrences → ordered placeholder names.
		$ordered_placeholders = $this->resolve_blank_to_placeholder_order( (string) $doc_xml, $ai_service, $reservation_data );
		error_log( 'Arriendo Facil process_owner_template: resolved ' . count( $ordered_placeholders ) . ' placeholder mappings: [' . implode( ',', array_slice( $ordered_placeholders, 0, 10 ) ) . ']' );

		// Step 5: Inject placeholders into the XML.
		$doc_xml = $this->inject_placeholders( $doc_xml, $ordered_placeholders );

		// Step 6: Write output DOCX.
		if ( '' === $output_path ) {
			$output_path = $this->generate_output_path( $source_path );
		}

		$result = $this->write_processed_docx( $source_path, $doc_xml, $output_path );
		if ( '' !== $result ) {
			$this->log_docx_event( 'process_owner_template_success', array(
				'blanks_detected'       => $blank_count,
				'placeholders_injected' => count( $ordered_placeholders ),
				'output_path'           => $result,
			) );
		}

		return $result;
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
			$this->log_docx_event( 'fill_template_failed', array( 'reason' => 'template_not_found', 'path' => $template_path ) );
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
			$this->log_docx_event( 'fill_template_failed', array( 'reason' => 'phpword_not_available' ) );
			return false;
		}

		try {
			$processor = new \PhpOffice\PhpWord\TemplateProcessor( $template_path );
			$values    = $this->build_placeholder_values( $payload );

			$lease_id = isset( $payload['lease_id'] ) ? (int) $payload['lease_id'] : 0;
			error_log( 'Arriendo Facil fill_template: starting fill for lease_id=' . $lease_id . ', payload_keys=[' . implode( ',', array_keys( $payload ) ) . ']' );

			$template_vars = array();
			if ( method_exists( $processor, 'getVariables' ) ) {
				foreach ( $processor->getVariables() as $template_var ) {
					$template_var = (string) $template_var;
					if ( '' !== $template_var ) {
						$template_vars[] = $template_var;
						if ( ! isset( $values[ $template_var ] ) ) {
							$values[ $template_var ] = '...............';
						}
					}
				}
			}

			error_log( 'Arriendo Facil fill_template: template found ' . count( $template_vars ) . ' variables: [' . implode( ',', $template_vars ) . ']' );
			error_log( 'Arriendo Facil fill_template: prepared ' . count( $values ) . ' values to set' );

			$vars_set = 0;
			$vars_blank = 0;
			$set_details = array();
			foreach ( $values as $key => $value ) {
				$value_str = (string) $value;
				$processor->setValue( $key, htmlspecialchars( $value_str, ENT_COMPAT, 'UTF-8' ) );
				if ( '...............' !== $value_str ) {
					$vars_set++;
					$set_details[] = $key . '=' . substr( $value_str, 0, 30 );
				} else {
					$vars_blank++;
					$set_details[] = $key . '=BLANK';
				}
			}

			error_log( 'Arriendo Facil fill_template: vars_set=' . $vars_set . ', vars_blank=' . $vars_blank . ', details=[' . implode( ';', array_slice( $set_details, 0, 10 ) ) . ']' );

			$processor->saveAs( $output_path );

			$success = file_exists( $output_path ) && filesize( $output_path ) > 0;
			if ( $success ) {
				$lease_id = isset( $payload['lease_id'] ) ? (int) $payload['lease_id'] : 0;
				$this->log_docx_event( 'fill_template_success', array(
					'lease_id'       => $lease_id,
					'vars_total'     => count( $values ),
					'vars_set'       => $vars_set,
					'vars_blank'     => $vars_blank,
					'output_size'    => filesize( $output_path ),
				) );
			} else {
				$this->log_docx_event( 'fill_template_failed', array( 'reason' => 'output_file_invalid' ) );
			}

			return $success;
		} catch ( \Throwable $e ) {
			$this->log_docx_event( 'fill_template_exception', array( 'error' => $e->getMessage() ) );
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
		$blank = '...............';
		$rent  = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '';

		$values = array(
			'ARRENDATARIO'        => $this->val( $payload, 'guest_name', $blank ),
			'CEDULA_ARRENDATARIO' => $this->val( $payload, 'guest_id_number', $blank ),
			'TELEFONO'            => $this->val( $payload, 'guest_phone', $blank ),
			'EMAIL'               => $this->val( $payload, 'guest_email', $blank ),
			'ARRENDADOR'          => $this->val( $payload, 'owner_name', $blank ),
			'CEDULA_ARRENDADOR'   => $this->val( $payload, 'owner_id_number', $blank ),
			'CANON'               => '' !== $rent ? $rent : $blank,
			'FECHA_INICIO'        => $this->val( $payload, 'start_date', $blank ),
			'FECHA_FIN'           => $this->val( $payload, 'end_date', $blank ),
			'DIRECCION'           => $this->val( $payload, 'accommodation_address', $blank ),
			'INMUEBLE'            => $this->val( $payload, 'accommodation_title', $blank ),
			'CIUDAD'              => $this->val( $payload, 'accommodation_city', $blank ),
			'METROS_CUADRADOS'    => $this->val( $payload, 'accommodation_square_meters', $blank ),
			'HABITACIONES'        => $this->val( $payload, 'accommodation_bedrooms', $blank ),
			'BANOS'               => $this->val( $payload, 'accommodation_bathrooms', $blank ),
			'TIPO_INMUEBLE'       => $this->val( $payload, 'accommodation_property_type', $blank ),
			'GARANTIA'            => $this->val( $payload, 'guarantee_text', $blank ),
			'MASCOTAS'            => isset( $payload['mascotas'] ) && (int) $payload['mascotas'] > 0 ? 'Sí' : 'No',
			'PERSONAS'            => isset( $payload['personas_viviran'] ) && (int) $payload['personas_viviran'] > 0
									? (string) (int) $payload['personas_viviran']
									: $blank,
			'REFERENCIA_1'        => $this->val( $payload, 'referencia_personal_1', $blank ),
			'REFERENCIA_2'        => $this->val( $payload, 'referencia_personal_2', $blank ),
			'FECHA_ACTUAL'        => gmdate( 'd/m/Y' ),
			'DIA_ACTUAL'          => gmdate( 'j' ),
			'MES_ACTUAL'          => $this->get_spanish_month_name( (int) gmdate( 'n' ) ),
			'ANO_ACTUAL'          => gmdate( 'Y' ),
		);

		$missing_fields = array();
		foreach ( $values as $placeholder => $value ) {
			if ( $blank === $value ) {
				$missing_fields[] = $placeholder;
			}
		}

		if ( ! empty( $missing_fields ) ) {
			$lease_id = isset( $payload['lease_id'] ) ? (int) $payload['lease_id'] : 0;
			$this->log_docx_event( 'placeholder_values_missing_fields', array(
				'lease_id'        => $lease_id,
				'missing_count'   => count( $missing_fields ),
				'missing_fields'  => $missing_fields,
			) );
		}

		return $values;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Private – template processing helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Centralizes DOCX processing event logging with structured context.
	 *
	 * @param string $event_type Event identifier.
	 * @param array  $context    Event context.
	 * @return void
	 */
	private function log_docx_event( $event_type, array $context = array() ) {
		$context_str = ! empty( $context ) ? ' | ' . wp_json_encode( $context ) : '';
		error_log( 'Arriendo Facil DOCX: ' . $event_type . $context_str );
	}

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

		if ( ! preg_match_all( '/_{3,}|\.{5,}|…{3,}|\t{2,}/u', $flat_text, $matches, PREG_OFFSET_CAPTURE ) ) {
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
			$ai_confidence = '';
			if ( isset( $ai_line_map[ 'blank_' . $blank_index ] ) && is_array( $ai_line_map[ 'blank_' . $blank_index ] ) ) {
				$ai_key = isset( $ai_line_map[ 'blank_' . $blank_index ][0] ) ? trim( (string) $ai_line_map[ 'blank_' . $blank_index ][0] ) : '';
				$ai_confidence = isset( $ai_line_map[ 'blank_' . $blank_index ][1] ) ? trim( strtoupper( (string) $ai_line_map[ 'blank_' . $blank_index ][1] ) ) : '';
			}

			// Prioritize HIGH-confidence AI mapping.
			// For MEDIUM/NONE, fall back to context rules (conservative approach).
			$use_ai_mapping = '' !== $ai_key && isset( self::CANONICAL_TO_PLACEHOLDER[ $ai_key ] ) && ( 'HIGH' === $ai_confidence );

			if ( $use_ai_mapping ) {
				$ordered[] = self::CANONICAL_TO_PLACEHOLDER[ $ai_key ];
			} else {
				// Use context rules for LOW confidence or AI mapping empty.
				$ordered[] = $this->infer_placeholder_from_context( $before, $after, $blank_index );
			}
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

		// Use only the last ~80 chars of before for leave-blank checks to avoid
		// matching patterns from distant earlier blanks in the 200-char window.
		$before_tail = strlen( $before ) > 80 ? substr( $before, -80 ) : $before;

		// ── Blanks that should ALWAYS stay empty (no chatbot data applies) ──

		$leave_blank_patterns = array(
			'dedicarlo a',
			'siguientes accesorios',
			'chapas con',
			'uso y goce de',
			'haber recibido',
			'corresponde(n) a',
			'plazo de este contrato es de',
			'plazo de duracion',
			'servicios basico',
			'estado civil',
			'de profesion',
			'conjunto habitacional',
			'etapa',
			'manzana',
			'firma del presente contrato el dia',
			'primer mes de garantia',
			'segundo mes de garantia',
			'seran entregadas las llaves',
		);

		foreach ( $leave_blank_patterns as $pat ) {
			if ( false !== strpos( $before_tail, $pat ) ) {
				return 'CAMPO_' . $blank_idx;
			}
		}

		if ( false !== strpos( $after, 'llaves' ) && false !== strpos( $before_tail, 'con' ) ) {
			return 'CAMPO_' . $blank_idx;
		}

		if ( false !== strpos( $after, 'llave(s)' ) || false !== strpos( $after, 'llaves' ) ) {
			if ( false !== strpos( $before_tail, 'recibido' ) ) {
				return 'CAMPO_' . $blank_idx;
			}
		}

		if ( false !== strpos( $after, 'anos' ) && ( false !== strpos( $before_tail, 'plazo' ) || false !== strpos( $before_tail, 'duracion' ) ) ) {
			return 'CAMPO_' . $blank_idx;
		}

		// ── OWNER (arrendador) name ──

		if ( false !== strpos( $after, 'que en adelante se denominara el arrendador' ) ) {
			return 'ARRENDADOR';
		}

		if ( 1 === preg_match( '/como\s+arrendador.*?senor\s*$/', $before ) ) {
			return 'ARRENDADOR';
		}

		if ( false !== strpos( $before, 'como arrendador, el senor' ) || false !== strpos( $before, 'como arrendador el senor' ) ) {
			return 'ARRENDADOR';
		}

		if ( false !== strpos( $after, 'en calidad de arrendador' ) ) {
			return 'ARRENDADOR';
		}

		if ( false !== strpos( $before, 'el senor' ) && false !== strpos( $after, 'propietario de' ) ) {
			return 'ARRENDADOR';
		}

		if ( false !== strpos( $after, 'propietario de' ) ) {
			return 'ARRENDADOR';
		}

		// "el señor [BLANK], propietario de" pattern.
		if ( 1 === preg_match( '/el\s+senor\s*$/', $before ) && false !== strpos( $after, 'propietario' ) ) {
			return 'ARRENDADOR';
		}

		// "El señor [BLANK] , en calidad de arrendador" pattern.
		if ( 1 === preg_match( '/el\s+senor\s*$/', $before ) && false !== strpos( $after, 'arrendador' ) && false === strpos( $after, 'arrendatario' ) ) {
			return 'ARRENDADOR';
		}

		// "SR. [BLANK] da en arrendamiento" pattern.
		if ( 1 === preg_match( '/sr\.?\s*$/', $before ) && false !== strpos( $after, 'da en arrendamiento' ) ) {
			return 'ARRENDADOR';
		}

		// ── GUEST (arrendatario) name ──

		if ( false !== strpos( $after, 'que en adelante se denominara el arrendatario' ) ) {
			return 'ARRENDATARIO';
		}

		if ( 1 === preg_match( '/como\s+arrendatario\s+el\s+senor\s*$/', $before ) ) {
			return 'ARRENDATARIO';
		}

		if ( false !== strpos( $before, 'como arrendatario el senor' ) || false !== strpos( $before, 'como arrendatario, el senor' ) ) {
			return 'ARRENDATARIO';
		}

		if ( 1 === preg_match( '/y\s+el\s+senor\s*$/', $before ) && false !== strpos( $after, 'arrendatario' ) ) {
			return 'ARRENDATARIO';
		}

		if ( 1 === preg_match( '/arrendamiento\s+al?\s+(?:senor|senora|sr|sra|srta)\s*$/', $before ) ) {
			return 'ARRENDATARIO';
		}

		if ( false !== strpos( $before, 'arrendamiento al senor' ) || false !== strpos( $before, 'arrendamiento a la srta' ) ) {
			return 'ARRENDATARIO';
		}

		if ( false !== strpos( $before, 'da y entrega en arrendamiento al senor' ) ) {
			return 'ARRENDATARIO';
		}

		if ( false !== strpos( $before, 'arrendatario senor' ) || false !== strpos( $before, 'arrendatario sr' ) ) {
			return 'ARRENDATARIO';
		}

		// "Srta. [BLANK] , con Cedula" in arrendatario context.
		if ( 1 === preg_match( '/(srta|sra)\.?\s*$/', $before ) && false !== strpos( $after, 'cedula' ) ) {
			return 'ARRENDATARIO';
		}

		// ── CÉDULA (ID numbers — NEVER a name) ──

		if ( false !== strpos( $before, 'cedula de ciudadania n' ) || false !== strpos( $before, 'cedula de identidad n' ) ) {
			if ( false !== strpos( $after, 'arrendador' ) || false !== strpos( $before, 'arrendador' ) ) {
				return 'CEDULA_ARRENDADOR';
			}
			if ( false !== strpos( $after, 'arrendatario' ) || false !== strpos( $before, 'arrendatario' ) ) {
				return 'CEDULA_ARRENDATARIO';
			}
			// If context mentions propietario/dador → owner.
			if ( false !== strpos( $after, 'propietario' ) || false !== strpos( $before, 'propietario' ) ) {
				return 'CEDULA_ARRENDADOR';
			}
			// Default: first cédula without context = owner, but safer as CAMPO.
			return 'CAMPO_' . $blank_idx;
		}

		if ( false !== strpos( $before, 'consignado con el numero' ) ) {
			return 'CEDULA_ARRENDATARIO';
		}

		// "C.C. [BLANK]" or "C.I. [BLANK]" patterns.
		if ( 1 === preg_match( '/c\.?\s*c\.?\s*$/', $before ) || 1 === preg_match( '/c\.?\s*i\.?\s*$/', $before ) ) {
			if ( false !== strpos( $before, 'arrendador' ) || false !== strpos( $before, 'propietario' ) ) {
				return 'CEDULA_ARRENDADOR';
			}
			if ( false !== strpos( $before, 'arrendatario' ) || false !== strpos( $before, 'inquilino' ) ) {
				return 'CEDULA_ARRENDATARIO';
			}
			// Check after context for signature section: "C.C. [BLANK] LA ARRENDADORA/EL ARRENDATARIO"
			if ( false !== strpos( $after, 'arrendadora' ) || ( false !== strpos( $after, 'arrendador' ) && false === strpos( $after, 'arrendatario' ) ) ) {
				return 'CEDULA_ARRENDADOR';
			}
			if ( false !== strpos( $after, 'arrendatario' ) ) {
				return 'CEDULA_ARRENDATARIO';
			}
			return 'CAMPO_' . $blank_idx;
		}

		// Blank BEFORE "consignado con el número" — this is often a descriptor, NOT a person name.
		if ( false !== strpos( $after, 'consignado con el numero' ) ) {
			return 'CAMPO_' . $blank_idx;
		}

		// "con cedula de ciudadania" after a name blank.
		if ( false !== strpos( $after, 'con cedula de ciudadania n' ) || false !== strpos( $after, 'con cedula' ) ) {
			if ( false !== strpos( $before, 'srta' ) || false !== strpos( $before, 'sra' ) || false !== strpos( $before, 'arrendamiento' ) || false !== strpos( $before, 'arrendatario' ) ) {
				return 'ARRENDATARIO';
			}
			if ( false !== strpos( $before, 'arrendador' ) || false !== strpos( $before, 'propietario' ) ) {
				return 'ARRENDADOR';
			}
			// Positional: first occurrence near beginning is usually arrendador.
			return 'ARRENDADOR';
		}

		// ── PROPERTY ──

		if ( false !== strpos( $before, 'propietario de' ) && ( false !== strpos( $after, 'situada' ) || false !== strpos( $after, 'ubicad' ) ) ) {
			return 'INMUEBLE';
		}

		if ( false !== strpos( $before, 'propietario de' ) ) {
			return 'INMUEBLE';
		}

		if ( false !== strpos( $before, 'ubicado en' ) && false !== strpos( $after, 'antes descrita' ) ) {
			return 'INMUEBLE';
		}

		// ── PROPERTY DETAILS (m2, ubicación, edificio) ──

		// "de [BLANK] m2" or "de [BLANK]m2" — square meters
		if ( 1 === preg_match( '/de\s*$/', $before ) && 1 === preg_match( '/^\s*m2/', $after ) ) {
			return 'METROS_CUADRADOS';
		}

		// "ubicado en la [BLANK]" or "ubicado en [BLANK]" — address/location
		if ( 1 === preg_match( '/ubicad[oa]\s+en\s+(la\s+)?$/', $before ) ) {
			return 'DIRECCION';
		}

		// "Edificio [BLANK]" — building name (part of address)
		if ( 1 === preg_match( '/edificio\s*$/', $before ) ) {
			return 'DIRECCION';
		}

		// "Ubicada en el [BLANK]" — location context
		if ( 1 === preg_match( '/ubicada?\s+en\s+el\s*$/', $before ) ) {
			return 'DIRECCION';
		}

		// ── ADDRESS ──

		if ( false !== strpos( $before, 'situada en' ) || false !== strpos( $before, 'ubicada en' ) ) {
			return 'DIRECCION';
		}

		if ( 1 === preg_match( '/\bcalle\s*$/', $before ) || 1 === preg_match( '/\bavenida\s*$/', $before ) || 1 === preg_match( '/\bav\.?\s*$/', $before ) ) {
			return 'DIRECCION';
		}

		// ── MONEY ──

		if ( false !== strpos( $before, 'y da en garantia, la cantidad de' ) || false !== strpos( $before, 'garantia' ) && false !== strpos( $after, 'usd' ) ) {
			return 'GARANTIA';
		}

		if ( false !== strpos( $before, 'la cantidad de' ) && false !== strpos( $after, 'usd por mes' ) ) {
			return 'CANON';
		}

		if ( false !== strpos( $after, 'usd por mes' ) || false !== strpos( $after, 'mensuales' ) ) {
			return 'CANON';
		}

		// "USD [BLANK] DÓLARES/dolares" — Alfil format (already has USD before)
		if ( 1 === preg_match( '/usd\s*\$?\s*$/', $before ) && false !== strpos( $after, 'dolares' ) ) {
			return 'CANON';
		}

		// "canon de arrendamiento mensual en [BLANK]" — generic
		if ( false !== strpos( $before, 'canon' ) && false !== strpos( $before, 'mensual' ) ) {
			return 'CANON';
		}

		// ── SIGNATURES at end of contract ──

		if ( 1 === preg_match( '/arrendatario\s*$/', $after ) || false !== strpos( $after, '"el arrendatario"' ) ) {
			return 'ARRENDATARIO';
		}

		if ( 1 === preg_match( '/arrendador\s*$/', $after ) || false !== strpos( $after, '"el arrendador"' ) ) {
			return 'ARRENDADOR';
		}

		// ── CITY ──

		if ( false !== strpos( $before, 'ciudad de' ) ) {
			return 'CIUDAD';
		}

		// ── START DATE ──

		// "a partir del [BLANK] fecha en la cual" or "a partir del [BLANK]"
		if ( false !== strpos( $before, 'a partir del' ) || false !== strpos( $before, 'a partir de' ) ) {
			return 'FECHA_INICIO';
		}

		// ── CONTRACT DATE PARTS ──
		// Covers multiple Spanish date formats:
		// "a los ___ días del mes de ___ de/del ___"
		// "Quito, ___ de ___ de ___"
		// "firmado el ___ de ___ de ___"
		// "___ de ___ del ___"

		// DAY: "a los [BLANK] dias" or after comma/article + before "de [month]"
		if ( 1 === preg_match( '/a\s+los\s*$/', $before ) && false !== strpos( $after, 'dias' ) ) {
			return 'DIA_ACTUAL';
		}
		if ( 1 === preg_match( '/(,|el)\s*$/', $before ) && 1 === preg_match( '/^\s*de\s+[a-z]/', $after ) ) {
			return 'DIA_ACTUAL';
		}
		if ( 1 === preg_match( '/(,|el)\s*$/', $before ) && 1 === preg_match( '/^\s*dias?\s+del?\s+mes/', $after ) ) {
			return 'DIA_ACTUAL';
		}
		// EcuadorLegal: "[BLANK]de [month/BLANK] del" — day before "de" at end of document
		if ( 1 === preg_match( '/^\s*de\s/', $after ) && 1 === preg_match( '/(arrendador|arrendatario|constancia|firmado|suscri)/i', $before ) ) {
			return 'DIA_ACTUAL';
		}

		// MONTH: after "del mes de" / "dias del mes de" / "de [day] de" + before "de/del [year]"
		if ( ( false !== strpos( $before, 'del mes de' ) || false !== strpos( $before, 'dias del mes de' ) )
			&& ( 1 === preg_match( '/^\s*de[l\s]/', $after ) || 1 === preg_match( '/^\s*del?\s/', $after ) )
		) {
			return 'MES_ACTUAL';
		}
		// Generic: number + "de [BLANK] de/del" (month between two "de")
		if ( 1 === preg_match( '/\d+\s+de\s*$/', $before ) && 1 === preg_match( '/^\s*de[l\s]/', $after ) ) {
			return 'MES_ACTUAL';
		}
		if ( 1 === preg_match( '/dias\s+de\s*$/', $before ) && 1 === preg_match( '/^\s*de[l\s]/', $after ) ) {
			return 'MES_ACTUAL';
		}

		// YEAR: after "de/del/del." when preceded by month-like context
		if ( 1 === preg_match( '/del?\.?\s*$/', $before ) && ( false !== strpos( $before, 'mes de' ) || false !== strpos( $before, 'dias' ) ) ) {
			return 'ANO_ACTUAL';
		}
		// Generic: "de [month_name] de/del [BLANK]" — year at end of date
		if ( 1 === preg_match( '/de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)\s+del?\.?\s*$/', $before ) ) {
			return 'ANO_ACTUAL';
		}
		if ( 1 === preg_match( '/del?\.?\s*$/', $before ) && 1 === preg_match( '/\bde\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)\b/', $before ) ) {
			return 'ANO_ACTUAL';
		}

		// ── Fallback keyword inference (conservative) ──

		$keyword_guess = $this->infer_placeholder_by_keywords_strict( $before, $after );
		if ( '' !== $keyword_guess ) {
			return $keyword_guess;
		}

		return 'CAMPO_' . $blank_idx;
	}

	/**
	 * Stricter keyword inference that checks before/after separately to avoid confusion.
	 *
	 * @param string $before Normalized text before blank.
	 * @param string $after  Normalized text after blank.
	 * @return string Placeholder name or empty string.
	 */
	private function infer_placeholder_by_keywords_strict( $before, $after ) {
		$before = (string) $before;
		$after  = (string) $after;

		if ( '' === $before && '' === $after ) {
			return '';
		}

		// If both arrendador and arrendatario mentioned, skip (ambiguous).
		$combined = $before . ' ' . $after;
		if ( false !== strpos( $combined, 'arrendador' ) && false !== strpos( $combined, 'arrendatario' ) ) {
			return '';
		}

		// Phone.
		if ( false !== strpos( $before, 'telefono' ) || false !== strpos( $before, 'celular' ) || false !== strpos( $before, 'movil' ) ) {
			return 'TELEFONO';
		}

		// Email.
		if ( false !== strpos( $before, 'correo' ) || false !== strpos( $before, 'email' ) || false !== strpos( $before, 'e-mail' ) ) {
			return 'EMAIL';
		}

		// Address patterns.
		if ( false !== strpos( $before, 'direccion' ) || false !== strpos( $before, 'domicilio' ) ) {
			return 'DIRECCION';
		}

		return '';
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
			$min_count  = "\t" === $start_char ? 2 : 3;

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

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $dom->saveXML( $dom->documentElement );
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

		$this->strip_docx_protection( $zip );

		if ( ! $zip->close() ) {
			@unlink( $output_path );
			return '';
		}

		return file_exists( $output_path ) ? $output_path : '';
	}

	/**
	 * Removes all document protection and write-protection from a DOCX zip.
	 *
	 * Handles: w:documentProtection, w:writeProtection in word/settings.xml
	 * and any encryption entries in EncryptedPackage.
	 *
	 * @param ZipArchive $zip Open DOCX zip archive.
	 */
	private function strip_docx_protection( ZipArchive $zip ) {
		$settings_xml = $zip->getFromName( 'word/settings.xml' );
		if ( false === $settings_xml || '' === trim( (string) $settings_xml ) ) {
			return;
		}

		$settings_xml = (string) $settings_xml;

		if ( class_exists( 'DOMDocument' ) ) {
			$dom = new DOMDocument();
			if ( @$dom->loadXML( $settings_xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
				$changed  = false;
				$xpath    = new DOMXPath( $dom );
				$ns_uri   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
				$xpath->registerNamespace( 'w', $ns_uri );

				$protection_nodes = $xpath->query( '//w:documentProtection' );
				if ( $protection_nodes && $protection_nodes->length > 0 ) {
					foreach ( $protection_nodes as $node ) {
						$node->parentNode->removeChild( $node );
					}
					$changed = true;
				}

				$write_nodes = $xpath->query( '//w:writeProtection' );
				if ( $write_nodes && $write_nodes->length > 0 ) {
					foreach ( $write_nodes as $node ) {
						$node->parentNode->removeChild( $node );
					}
					$changed = true;
				}

				if ( $changed ) {
					$zip->addFromString( 'word/settings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $dom->saveXML( $dom->documentElement ) );
				}
				return;
			}
		}

		$original = $settings_xml;
		$settings_xml = preg_replace( '/<w:documentProtection\b[^>]*\/>/si', '', $settings_xml );
		$settings_xml = preg_replace( '/<w:documentProtection\b[^>]*>.*?<\/w:documentProtection>/si', '', $settings_xml );
		$settings_xml = preg_replace( '/<w:writeProtection\b[^>]*\/>/si', '', $settings_xml );
		$settings_xml = preg_replace( '/<w:writeProtection\b[^>]*>.*?<\/w:writeProtection>/si', '', $settings_xml );

		if ( $settings_xml !== $original ) {
			$zip->addFromString( 'word/settings.xml', $settings_xml );
		}
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

	/**
	 * Post-validates AI field_map assignments using rule-based inference.
	 * When rules have high confidence (non-CAMPO_ result), they override the AI.
	 */
	private function validate_ai_field_map( array $ai_map, array $blanks_info ) {
		$canonical_flip = array_flip( self::CANONICAL_TO_PLACEHOLDER );

		foreach ( $ai_map as &$entry ) {
			$idx   = isset( $entry['blank_index'] ) ? (int) $entry['blank_index'] : -1;
			$blank = isset( $blanks_info[ $idx ] ) ? $blanks_info[ $idx ] : null;
			if ( ! $blank ) {
				continue;
			}

			$before = isset( $blank['before'] ) ? (string) $blank['before'] : '';
			$after  = isset( $blank['after'] ) ? (string) $blank['after'] : '';

			$rule_result = $this->infer_placeholder_from_context( $before, $after, $idx );

			if ( 0 !== strpos( $rule_result, 'CAMPO_' ) && isset( $canonical_flip[ $rule_result ] ) ) {
				$field_key = $canonical_flip[ $rule_result ];
				$entry['field_key'] = $field_key;
				$entry['label']     = $this->get_field_label( $field_key );
				$entry['source']    = $this->get_field_source( $field_key );
			}
		}
		unset( $entry );

		return $ai_map;
	}

	/**
	 * Returns the Spanish month name for a given month number.
	 *
	 * @param int $month_number 1-12.
	 * @return string
	 */
	private function get_spanish_month_name( $month_number ) {
		$months = array(
			1 => 'enero', 2 => 'febrero', 3 => 'marzo',
			4 => 'abril', 5 => 'mayo', 6 => 'junio',
			7 => 'julio', 8 => 'agosto', 9 => 'septiembre',
			10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
		);
		return isset( $months[ $month_number ] ) ? $months[ $month_number ] : '';
	}

	private function filter_decorative_blanks( array $raw_matches, $flat_text ) {
		$filtered = array();
		foreach ( $raw_matches as $match ) {
			$blank_text = (string) $match[0];
			$offset     = (int) $match[1];
			$len        = strlen( $blank_text );

			// Long dash sequences (>20 chars) are decorative separators, not fields.
			if ( $len > 20 && '-' === $blank_text[0] ) {
				continue;
			}

			if ( $len <= 4 && '_' === $blank_text[0] ) {
				$char_before    = $offset > 0 ? $flat_text[ $offset - 1 ] : '';
				$after_snippet  = substr( $flat_text, $offset + $len, 60 );
				$before_snippet = substr( $flat_text, max( 0, $offset - 60 ), min( 60, $offset ) );
				$has_field_context = (bool) preg_match(
					'/(?:senor|cedula|ciudad|telefono|direccion|ubicad|situad|canon|garantia|arrendador|arrendatario|nombre|correo|fecha)/i',
					$before_snippet . $after_snippet
				);
				if ( ! $has_field_context && ( '.' === $char_before || ';' === $char_before || ':' === $char_before || '' === trim( $char_before ) ) ) {
					continue;
				}
			}

			$filtered[] = $match;
		}
		return $filtered;
	}

	// ─── Markdown/Pandoc-based flow ─────────────────────────────────────────────

	private static $pandoc_path_cache = null;

	public static function is_pandoc_available() {
		if ( null !== self::$pandoc_path_cache ) {
			return '' !== self::$pandoc_path_cache;
		}

		if ( ! function_exists( 'exec' ) ) {
			self::$pandoc_path_cache = '';
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'exec', $disabled, true ) ) {
			self::$pandoc_path_cache = '';
			return false;
		}

		try {
			$output     = array();
			$return_var = 1;
			@exec( 'which pandoc 2>/dev/null', $output, $return_var );

			if ( 0 === $return_var && ! empty( $output[0] ) ) {
				self::$pandoc_path_cache = trim( $output[0] );
				return true;
			}
		} catch ( \Throwable $e ) {
			self::$pandoc_path_cache = '';
			return false;
		}

		$common_paths = array( '/usr/bin/pandoc', '/usr/local/bin/pandoc', '/opt/homebrew/bin/pandoc' );
		foreach ( $common_paths as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				self::$pandoc_path_cache = $path;
				return true;
			}
		}

		self::$pandoc_path_cache = '';
		return false;
	}

	public static function get_pandoc_path() {
		self::is_pandoc_available();
		return self::$pandoc_path_cache;
	}

	public function convert_and_store_markdown( $docx_path, $attachment_id ) {
		$md_content = $this->convert_docx_to_markdown( $docx_path );
		if ( is_wp_error( $md_content ) ) {
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$md_dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/owner-templates';
		wp_mkdir_p( $md_dir );

		$md_filename = 'template-' . $attachment_id . '-' . md5( $docx_path ) . '.md';
		$md_path     = trailingslashit( $md_dir ) . $md_filename;

		if ( false === file_put_contents( $md_path, $md_content ) ) {
			return '';
		}

		return $md_path;
	}

	private function build_markdown_ai_payload( array $payload ) {
		$months_es = array(
			1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
			5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
			9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
		);

		$start_date = $this->val( $payload, 'start_date' );
		$end_date   = $this->val( $payload, 'end_date' );

		$start_day = '';
		$start_month_name = '';
		$start_year = '';
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $start_date, $m ) ) {
			$start_day        = ltrim( $m[3], '0' );
			$start_month_name = isset( $months_es[ (int) $m[2] ] ) ? $months_es[ (int) $m[2] ] : $m[2];
			$start_year       = $m[1];
		}

		$end_day = '';
		$end_month_name = '';
		$end_year = '';
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $end_date, $m ) ) {
			$end_day        = ltrim( $m[3], '0' );
			$end_month_name = isset( $months_es[ (int) $m[2] ] ) ? $months_es[ (int) $m[2] ] : $m[2];
			$end_year       = $m[1];
		}

		$monthly_rent = isset( $payload['monthly_rent'] ) ? (float) $payload['monthly_rent'] : 0.0;
		$rent_formatted = $monthly_rent > 0 ? number_format( $monthly_rent, 2, '.', '' ) : '';
		$rent_in_words  = $monthly_rent > 0 ? $this->number_to_spanish_words( $monthly_rent ) : '';

		$guarantee_months = 2;
		$guarantee_amount = $monthly_rent * $guarantee_months;
		$guarantee_formatted = $guarantee_amount > 0 ? number_format( $guarantee_amount, 2, '.', '' ) : '';
		$guarantee_in_words  = $guarantee_amount > 0 ? $this->number_to_spanish_words( $guarantee_amount ) : '';

		$city = $this->val( $payload, 'accommodation_city' );
		$address = $this->val( $payload, 'accommodation_address' );

		// If city is empty, try to extract it from the last part of the address.
		if ( '' === $city && '' !== $address ) {
			$parts = array_map( 'trim', explode( ',', $address ) );
			if ( count( $parts ) >= 2 ) {
				$city = end( $parts );
			}
		}

		return array(
			'owner_name'               => $this->val( $payload, 'owner_name' ),
			'owner_id_number'          => $this->val( $payload, 'owner_id_number' ),
			'guest_name'               => $this->val( $payload, 'guest_name' ),
			'guest_id_number'          => $this->val( $payload, 'guest_id_number' ),
			'guest_phone'              => $this->val( $payload, 'guest_phone' ),
			'guest_email'              => $this->val( $payload, 'guest_email' ),
			'accommodation_address'    => $this->val( $payload, 'accommodation_address' ),
			'accommodation_city'       => $city,
			'accommodation_province'   => $this->val( $payload, 'accommodation_province', $city ),
			'accommodation_canton'     => $this->val( $payload, 'accommodation_canton', $city ),
			'accommodation_parish'     => $this->val( $payload, 'accommodation_parish', $city ),
			'accommodation_square_meters' => $this->val( $payload, 'accommodation_square_meters' ),
			'monthly_rent'             => $rent_formatted,
			'monthly_rent_in_words'    => $rent_in_words,
			'guarantee_amount'         => $guarantee_formatted,
			'guarantee_in_words'       => $guarantee_in_words,
			'start_day'                => $start_day,
			'start_month_name'         => $start_month_name,
			'start_year'               => $start_year,
			'end_day'                  => $end_day,
			'end_month_name'           => $end_month_name,
			'end_year'                 => $end_year,
		);
	}

	private function number_to_spanish_words( $number ) {
		$number = (float) $number;
		$integer_part = (int) floor( $number );
		$decimal_part = (int) round( ( $number - $integer_part ) * 100 );

		$units = array( '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve' );
		$teens = array( 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve' );
		$tens  = array( '', 'diez', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa' );
		$hundreds = array( '', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos' );

		if ( 0 === $integer_part ) {
			return 'cero';
		}

		$words = '';

		if ( $integer_part >= 1000 ) {
			$thousands = (int) floor( $integer_part / 1000 );
			if ( 1 === $thousands ) {
				$words .= 'mil';
			} else {
				$words .= $this->number_under_thousand_to_words( $thousands, $units, $teens, $tens, $hundreds ) . ' mil';
			}
			$integer_part %= 1000;
			if ( $integer_part > 0 ) {
				$words .= ' ';
			}
		}

		if ( $integer_part > 0 ) {
			$words .= $this->number_under_thousand_to_words( $integer_part, $units, $teens, $tens, $hundreds );
		}

		$words = strtoupper( trim( $words ) );

		if ( $decimal_part > 0 ) {
			$words .= ' CON ' . str_pad( (string) $decimal_part, 2, '0', STR_PAD_LEFT ) . '/100';
		}

		return $words;
	}

	private function number_under_thousand_to_words( $n, $units, $teens, $tens, $hundreds ) {
		$n = (int) $n;
		if ( $n <= 0 ) {
			return '';
		}
		if ( 100 === $n ) {
			return 'cien';
		}

		$result = '';
		if ( $n >= 100 ) {
			$result .= $hundreds[ (int) floor( $n / 100 ) ];
			$n %= 100;
			if ( $n > 0 ) {
				$result .= ' ';
			}
		}

		if ( $n >= 10 && $n <= 19 ) {
			$result .= $teens[ $n - 10 ];
		} elseif ( $n >= 20 && $n <= 29 ) {
			$result .= ( 20 === $n ) ? 'veinte' : 'veinti' . $units[ $n - 20 ];
		} elseif ( $n >= 30 ) {
			$result .= $tens[ (int) floor( $n / 10 ) ];
			$remainder = $n % 10;
			if ( $remainder > 0 ) {
				$result .= ' y ' . $units[ $remainder ];
			}
		} elseif ( $n >= 1 ) {
			$result .= $units[ $n ];
		}

		return $result;
	}

	public function fill_template_with_markdown( $source_path, $output_path, array $payload, $ai_service = null ) {
		$source_path = (string) $source_path;
		$output_path = (string) $output_path;
		$lease_id    = isset( $payload['lease_id'] ) ? (int) $payload['lease_id'] : 0;

		if ( ! self::is_pandoc_available() ) {
			$this->log_docx_event( 'fill_with_markdown_failed', array( 'reason' => 'pandoc_unavailable', 'lease_id' => $lease_id ) );
			return false;
		}

		if ( '' === $source_path || ! file_exists( $source_path ) ) {
			$this->log_docx_event( 'fill_with_markdown_failed', array( 'reason' => 'source_invalid', 'lease_id' => $lease_id ) );
			return false;
		}

		// Use pre-converted MD if available (stored at upload time).
		$attachment_id = isset( $payload['attachment_id'] ) ? (int) $payload['attachment_id'] : 0;
		$stored_md_path = $attachment_id ? (string) get_post_meta( $attachment_id, '_af_template_markdown_path', true ) : '';

		if ( '' !== $stored_md_path && file_exists( $stored_md_path ) ) {
			$md_content = file_get_contents( $stored_md_path );
			if ( false === $md_content || '' === trim( $md_content ) ) {
				$md_content = null;
			}
		}

		// Fallback: convert on-the-fly if stored MD is not available.
		if ( empty( $md_content ) ) {
			$md_content = $this->convert_docx_to_markdown( $source_path );
			if ( is_wp_error( $md_content ) ) {
				$this->log_docx_event( 'fill_with_markdown_failed', array( 'reason' => 'docx_to_md_failed', 'error' => $md_content->get_error_message(), 'lease_id' => $lease_id ) );
				return false;
			}
		}

		if ( null === $ai_service || ! method_exists( $ai_service, 'fill_contract_blanks_markdown' ) ) {
			$this->log_docx_event( 'fill_with_markdown_failed', array( 'reason' => 'ai_service_unavailable', 'lease_id' => $lease_id ) );
			return false;
		}

		$ai_payload = $this->build_markdown_ai_payload( $payload );

		try {
			$ai_result = $ai_service->fill_contract_blanks_markdown( array(
				'markdown_text' => $md_content,
				'payload'       => $ai_payload,
			) );
		} catch ( \Throwable $e ) {
			$this->log_docx_event( 'fill_with_markdown_exception', array( 'lease_id' => $lease_id, 'error' => $e->getMessage() ) );
			return false;
		}

		if ( is_wp_error( $ai_result ) ) {
			$this->log_docx_event( 'fill_with_markdown_ai_failed', array( 'lease_id' => $lease_id, 'error' => $ai_result->get_error_message() ) );
			return false;
		}

		$filled_md = isset( $ai_result['filled_markdown'] ) ? trim( (string) $ai_result['filled_markdown'] ) : '';
		if ( '' === $filled_md ) {
			$this->log_docx_event( 'fill_with_markdown_ai_failed', array( 'lease_id' => $lease_id, 'error' => 'empty filled_markdown' ) );
			return false;
		}

		$filled_md = $this->sanitize_filled_markdown( $filled_md, $ai_payload );

		$conversion_result = $this->convert_markdown_to_docx( $filled_md, $source_path, $output_path );
		if ( is_wp_error( $conversion_result ) ) {
			$this->log_docx_event( 'fill_with_markdown_failed', array( 'reason' => 'md_to_docx_failed', 'error' => $conversion_result->get_error_message(), 'lease_id' => $lease_id ) );
			return false;
		}

		$this->copy_headers_footers_media( $source_path, $output_path );

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $output_path ) ) {
				$this->strip_docx_protection( $zip );
				$zip->close();
			}
		}

		$this->log_docx_event( 'fill_with_markdown_success', array( 'lease_id' => $lease_id, 'output_path' => $output_path ) );
		return true;
	}

	private function sanitize_filled_markdown( $md, array $ai_payload ) {
		$owner_name   = isset( $ai_payload['owner_name'] ) ? $ai_payload['owner_name'] : '';
		$guest_name   = isset( $ai_payload['guest_name'] ) ? $ai_payload['guest_name'] : '';
		$rent         = isset( $ai_payload['monthly_rent'] ) ? $ai_payload['monthly_rent'] : '';
		$guarantee    = isset( $ai_payload['guarantee_amount'] ) ? $ai_payload['guarantee_amount'] : '';
		$address      = isset( $ai_payload['accommodation_address'] ) ? $ai_payload['accommodation_address'] : '';
		$city         = isset( $ai_payload['accommodation_city'] ) ? $ai_payload['accommodation_city'] : '';
		$start_day    = isset( $ai_payload['start_day'] ) ? $ai_payload['start_day'] : '';
		$start_month  = isset( $ai_payload['start_month_name'] ) ? $ai_payload['start_month_name'] : '';
		$start_year   = isset( $ai_payload['start_year'] ) ? $ai_payload['start_year'] : '';
		$end_day      = isset( $ai_payload['end_day'] ) ? $ai_payload['end_day'] : '';
		$end_month    = isset( $ai_payload['end_month_name'] ) ? $ai_payload['end_month_name'] : '';
		$end_year     = isset( $ai_payload['end_year'] ) ? $ai_payload['end_year'] : '';
		$rent_words   = isset( $ai_payload['monthly_rent_in_words'] ) ? $ai_payload['monthly_rent_in_words'] : '';
		$guar_words   = isset( $ai_payload['guarantee_in_words'] ) ? $ai_payload['guarantee_in_words'] : '';

		// Fix: If AI put a full ISO date where day/month/year components go.
		$full_start = isset( $ai_payload['start_year'] ) ? $ai_payload['start_year'] . '-' . sprintf( '%02d', array_search( strtolower( $start_month ), array( 1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre' ) ) ) . '-' . sprintf( '%02d', (int) $start_day ) : '';

		if ( '' !== $full_start ) {
			// "el día 2026-06-03 de 2026-06-03 del año 20 2026-06-03" → fix
			$md = preg_replace(
				'/el día\s+' . preg_quote( $full_start, '/' ) . '\s+de\s+' . preg_quote( $full_start, '/' ) . '\s+del año\s+20\s*' . preg_quote( $full_start, '/' ) . '/u',
				'el día ' . $start_day . ' de ' . $start_month . ' del año ' . $start_year,
				$md
			);
			// Simpler patterns where full date appears in day slot
			$md = str_replace( 'el día ' . $full_start, 'el día ' . $start_day, $md );
		}

		// Fix: If AI put monthly_rent where date fields go.
		if ( '' !== $rent && '' !== $end_day ) {
			$md = preg_replace(
				'/fenecerá el día\s+' . preg_quote( $rent, '/' ) . '\s+de\s+' . preg_quote( $rent, '/' ) . '/u',
				'fenecerá el día ' . $end_day . ' de ' . $end_month,
				$md
			);
		}

		// Fix: If AI put address in dollar amount field ($address,00).
		if ( '' !== $address && '' !== $guarantee ) {
			$md = str_replace( '($' . $address . ',00)', '($' . $guarantee . ',00)', $md );
			$md = str_replace( '(\\$' . $address . ',00)', '(\\$' . $guarantee . ',00)', $md );
		}

		// Fix: If AI put owner_name as city.
		if ( '' !== $owner_name && '' !== $city ) {
			$md = preg_replace(
				'/ciudad de\s+' . preg_quote( $owner_name, '/' ) . '/u',
				'ciudad de ' . $city,
				$md
			);
		}

		return $md;
	}

	private function convert_docx_to_markdown( $docx_path ) {
		$pandoc = self::get_pandoc_path();
		if ( '' === $pandoc ) {
			return new \WP_Error( 'pandoc_unavailable', 'Pandoc is not installed.' );
		}

		$tmp_md = wp_tempnam( 'af_md_' ) . '.md';

		$cmd = sprintf(
			'%s --from=docx --to=markdown --wrap=none %s -o %s 2>&1',
			escapeshellarg( $pandoc ),
			escapeshellarg( $docx_path ),
			escapeshellarg( $tmp_md )
		);

		$output     = array();
		$return_var = 1;
		@exec( $cmd, $output, $return_var );

		if ( 0 !== $return_var || ! file_exists( $tmp_md ) ) {
			@unlink( $tmp_md );
			return new \WP_Error( 'pandoc_conversion_failed', 'DOCX to MD failed: ' . implode( "\n", $output ) );
		}

		$md_content = file_get_contents( $tmp_md );
		@unlink( $tmp_md );

		if ( false === $md_content || '' === trim( $md_content ) ) {
			return new \WP_Error( 'md_empty', 'Pandoc produced empty markdown.' );
		}

		return $md_content;
	}

	private function convert_markdown_to_docx( $md_content, $reference_docx_path, $output_path ) {
		$pandoc = self::get_pandoc_path();
		if ( '' === $pandoc ) {
			return new \WP_Error( 'pandoc_unavailable', 'Pandoc is not installed.' );
		}

		$tmp_md = wp_tempnam( 'af_filled_md_' ) . '.md';
		file_put_contents( $tmp_md, $md_content );

		$cmd = sprintf(
			'%s --from=markdown --to=docx --reference-doc=%s %s -o %s 2>&1',
			escapeshellarg( $pandoc ),
			escapeshellarg( $reference_docx_path ),
			escapeshellarg( $tmp_md ),
			escapeshellarg( $output_path )
		);

		$output     = array();
		$return_var = 1;
		@exec( $cmd, $output, $return_var );

		@unlink( $tmp_md );

		if ( 0 !== $return_var || ! file_exists( $output_path ) ) {
			return new \WP_Error( 'pandoc_md_to_docx_failed', 'MD to DOCX failed: ' . implode( "\n", $output ) );
		}

		return true;
	}

	private function copy_headers_footers_media( $source_docx, $output_docx ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$src_zip = new ZipArchive();
		if ( true !== $src_zip->open( $source_docx ) ) {
			return false;
		}

		$out_zip = new ZipArchive();
		if ( true !== $out_zip->open( $output_docx ) ) {
			$src_zip->close();
			return false;
		}

		$header_footer_parts = array();
		$media_files         = array();

		for ( $i = 0; $i < $src_zip->numFiles; $i++ ) {
			$name = $src_zip->getNameIndex( $i );
			if ( preg_match( '#^word/(header\d*\.xml|footer\d*\.xml)$#', $name ) ) {
				$header_footer_parts[] = $name;
			} elseif ( 0 === strpos( $name, 'word/media/' ) ) {
				$media_files[] = $name;
			}
		}

		if ( empty( $header_footer_parts ) ) {
			$src_zip->close();
			$out_zip->close();
			return true;
		}

		foreach ( $header_footer_parts as $part ) {
			$content = $src_zip->getFromName( $part );
			if ( false !== $content ) {
				$out_zip->addFromString( $part, $content );
			}
		}

		foreach ( $media_files as $media ) {
			$content = $src_zip->getFromName( $media );
			if ( false !== $content ) {
				$out_zip->addFromString( $media, $content );
			}
		}

		// Also copy header/footer relationship files if they exist.
		for ( $i = 0; $i < $src_zip->numFiles; $i++ ) {
			$name = $src_zip->getNameIndex( $i );
			if ( preg_match( '#^word/_rels/(header\d*\.xml\.rels|footer\d*\.xml\.rels)$#', $name ) ) {
				$content = $src_zip->getFromName( $name );
				if ( false !== $content ) {
					$out_zip->addFromString( $name, $content );
				}
			}
		}

		$src_rels_xml = $src_zip->getFromName( 'word/_rels/document.xml.rels' );
		$out_rels_xml = $out_zip->getFromName( 'word/_rels/document.xml.rels' );

		if ( false !== $src_rels_xml && false !== $out_rels_xml ) {
			$merged_rels = $this->merge_header_footer_rels( (string) $src_rels_xml, (string) $out_rels_xml );
			if ( '' !== $merged_rels ) {
				$out_zip->addFromString( 'word/_rels/document.xml.rels', $merged_rels );
			}
		}

		$out_doc_xml = $out_zip->getFromName( 'word/document.xml' );
		$src_doc_xml = $src_zip->getFromName( 'word/document.xml' );
		if ( false !== $out_doc_xml && false !== $src_doc_xml ) {
			$patched_doc = $this->inject_header_footer_refs_into_sectpr( (string) $src_doc_xml, (string) $out_doc_xml );
			if ( '' !== $patched_doc ) {
				$out_zip->addFromString( 'word/document.xml', $patched_doc );
			}
		}

		$src_ct = $src_zip->getFromName( '[Content_Types].xml' );
		$out_ct = $out_zip->getFromName( '[Content_Types].xml' );
		if ( false !== $src_ct && false !== $out_ct ) {
			$merged_ct = $this->merge_content_types_for_headers( (string) $src_ct, (string) $out_ct );
			if ( '' !== $merged_ct ) {
				$out_zip->addFromString( '[Content_Types].xml', $merged_ct );
			}
		}

		$src_zip->close();
		$out_zip->close();

		return true;
	}

	private function merge_header_footer_rels( $src_rels_xml, $out_rels_xml ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return '';
		}

		$src_dom = new DOMDocument();
		$out_dom = new DOMDocument();

		if ( ! @$src_dom->loadXML( $src_rels_xml, LIBXML_NONET | LIBXML_NOERROR ) ) {
			return '';
		}
		if ( ! @$out_dom->loadXML( $out_rels_xml, LIBXML_NONET | LIBXML_NOERROR ) ) {
			return '';
		}

		$hf_rels     = array();
		$src_root    = $src_dom->documentElement;
		$existing_ids = array();

		foreach ( $out_dom->documentElement->childNodes as $node ) {
			if ( $node instanceof DOMElement && $node->hasAttribute( 'Id' ) ) {
				$existing_ids[ $node->getAttribute( 'Id' ) ] = true;
			}
		}

		foreach ( $src_root->childNodes as $node ) {
			if ( ! ( $node instanceof DOMElement ) ) {
				continue;
			}
			$type = $node->getAttribute( 'Type' );
			if ( false !== strpos( $type, '/header' ) || false !== strpos( $type, '/footer' ) ) {
				$hf_rels[] = $node;
			}
		}

		if ( empty( $hf_rels ) ) {
			return '';
		}

		foreach ( $hf_rels as $rel_node ) {
			$id = $rel_node->getAttribute( 'Id' );
			if ( isset( $existing_ids[ $id ] ) ) {
				continue;
			}
			$imported = $out_dom->importNode( $rel_node, true );
			$out_dom->documentElement->appendChild( $imported );
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $out_dom->saveXML( $out_dom->documentElement );
	}

	private function inject_header_footer_refs_into_sectpr( $src_doc_xml, $out_doc_xml ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return '';
		}

		$ns_w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
		$ns_r = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

		$src_dom = new DOMDocument();
		$out_dom = new DOMDocument();

		if ( ! @$src_dom->loadXML( $src_doc_xml, LIBXML_NONET | LIBXML_NOERROR ) ) {
			return '';
		}
		if ( ! @$out_dom->loadXML( $out_doc_xml, LIBXML_NONET | LIBXML_NOERROR ) ) {
			return '';
		}

		$src_xpath = new DOMXPath( $src_dom );
		$src_xpath->registerNamespace( 'w', $ns_w );
		$src_xpath->registerNamespace( 'r', $ns_r );

		$out_xpath = new DOMXPath( $out_dom );
		$out_xpath->registerNamespace( 'w', $ns_w );
		$out_xpath->registerNamespace( 'r', $ns_r );

		$src_refs = $src_xpath->query( '//w:sectPr/w:headerReference | //w:sectPr/w:footerReference' );
		if ( ! $src_refs || 0 === $src_refs->length ) {
			return '';
		}

		$out_sectpr_nodes = $out_xpath->query( '//w:sectPr' );
		if ( ! $out_sectpr_nodes || 0 === $out_sectpr_nodes->length ) {
			return '';
		}

		$out_sectpr = $out_sectpr_nodes->item( $out_sectpr_nodes->length - 1 );

		$existing_ref_ids = array();
		$existing_refs = $out_xpath->query( 'w:headerReference | w:footerReference', $out_sectpr );
		if ( $existing_refs ) {
			foreach ( $existing_refs as $ref ) {
				$existing_ref_ids[] = $ref->getAttributeNS( $ns_r, 'id' );
			}
		}

		foreach ( $src_refs as $ref ) {
			$rid = $ref->getAttributeNS( $ns_r, 'id' );
			if ( in_array( $rid, $existing_ref_ids, true ) ) {
				continue;
			}
			$imported = $out_dom->importNode( $ref, true );
			$first_child = $out_sectpr->firstChild;
			if ( $first_child ) {
				$out_sectpr->insertBefore( $imported, $first_child );
			} else {
				$out_sectpr->appendChild( $imported );
			}
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $out_dom->saveXML( $out_dom->documentElement );
	}

	private function merge_content_types_for_headers( $src_ct, $out_ct ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return '';
		}

		$src_dom = new DOMDocument();
		$out_dom = new DOMDocument();

		if ( ! @$src_dom->loadXML( $src_ct, LIBXML_NONET | LIBXML_NOERROR ) ) {
			return '';
		}
		if ( ! @$out_dom->loadXML( $out_ct, LIBXML_NONET | LIBXML_NOERROR ) ) {
			return '';
		}

		$existing_parts = array();
		foreach ( $out_dom->documentElement->childNodes as $node ) {
			if ( $node instanceof DOMElement && $node->hasAttribute( 'PartName' ) ) {
				$existing_parts[ $node->getAttribute( 'PartName' ) ] = true;
			}
		}

		$changed = false;
		foreach ( $src_dom->documentElement->childNodes as $node ) {
			if ( ! ( $node instanceof DOMElement ) || ! $node->hasAttribute( 'PartName' ) ) {
				continue;
			}
			$part_name = $node->getAttribute( 'PartName' );
			if ( ! preg_match( '#/word/(header|footer)\d*\.xml$#', $part_name ) ) {
				continue;
			}
			if ( isset( $existing_parts[ $part_name ] ) ) {
				continue;
			}
			$imported = $out_dom->importNode( $node, true );
			$out_dom->documentElement->appendChild( $imported );
			$changed = true;
		}

		if ( ! $changed ) {
			return '';
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $out_dom->saveXML( $out_dom->documentElement );
	}
}
