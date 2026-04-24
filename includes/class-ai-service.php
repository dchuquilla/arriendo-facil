<?php
/**
 * AI Service integration.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_AI_Service
 *
 * Provides AI-powered functionality:
 *  - Cost prediction for accommodations
 *  - Lease document generation
 *  - Guest scoring / management
 *
 * Communicates with OpenAI Chat Completions (ChatGPT), with optional
 * custom endpoint override from plugin settings.
 */
class Arriendo_Facil_AI_Service {

	/**
	 * Base URL override for AI API endpoint.
	 *
	 * @var string
	 */
	private $api_url;

	/**
	 * API key for authenticating with the AI endpoint.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Default OpenAI chat completions endpoint.
	 *
	 * @var string
	 */
	private $default_openai_endpoint = 'https://api.openai.com/v1/chat/completions';

	/**
	 * OpenAI model used for requests.
	 *
	 * @var string
	 */
	private $model = 'gpt-4o-mini';

	/**
	 * Constructor - loads API configuration from plugin options.
	 */
	public function __construct() {
		$this->api_url = $this->get_setting_value( 'AF_AI_API_URL', 'af_ai_api_url' );
		$this->api_key = $this->get_setting_value( 'AF_AI_API_KEY', 'af_ai_api_key' );
	}

	/**
	 * Returns a setting value, prioritizing wp-config constants.
	 *
	 * @param string $constant_name Constant name.
	 * @param string $option_name   Option name.
	 * @return string
	 */
	private function get_setting_value( $constant_name, $option_name ) {
		if ( defined( $constant_name ) ) {
			$value = constant( $constant_name );
			return is_string( $value ) ? $value : '';
		}

		return (string) get_option( $option_name, '' );
	}

	/**
	 * Predicts the monthly rental cost for an accommodation.
	 *
	 * @param array $accommodation_data Associative array with accommodation attributes.
	 * @return array|WP_Error Response array with 'predicted_cost' key, or WP_Error.
	 */
	public function predict_cost( array $accommodation_data ) {
		$payload = array(
			'action' => 'predict_cost',
			'data'   => $accommodation_data,
		);

		$response = $this->request( $payload );

		$this->log( 'predict_cost', $accommodation_data, $response );

		return $response;
	}

	/**
	 * Generates a lease document for the given lease data.
	 *
	 * @param array $lease_data Associative array with lease fields.
	 * @return array|WP_Error Response array with 'document_url' key, or WP_Error.
	 */
	public function generate_document( array $lease_data ) {
		$payload = array(
			'action' => 'generate_document',
			'data'   => $lease_data,
		);

		$response = $this->request( $payload );

		$this->log( 'generate_document', $lease_data, $response );

		return $response;
	}

	/**
	 * Scores a guest based on their profile data.
	 *
	 * @param array $guest_data Associative array with guest profile fields.
	 * @return array|WP_Error Response array with 'score' and 'summary' keys, or WP_Error.
	 */
	public function score_guest( array $guest_data ) {
		$payload = array(
			'action' => 'score_guest',
			'data'   => $guest_data,
		);

		$response = $this->request( $payload );

		$this->log( 'score_guest', $guest_data, $response );

		return $response;
	}

	/**
	 * Generates cleaning service contract text.
	 *
	 * @param array $contract_data Contract context.
	 * @return array|WP_Error Response array with 'contract_text' key, or WP_Error.
	 */
	public function generate_cleaning_contract( array $contract_data ) {
		$payload = array(
			'action' => 'generate_cleaning_contract',
			'data'   => $contract_data,
		);

		$response = $this->request( $payload );

		$this->log( 'generate_cleaning_contract', $contract_data, $response );

		return $response;
	}

	/**
	 * Maps semantic labels from owner template to canonical lease fields.
	 *
	 * @param array $template_context Template context payload.
	 * @return array|WP_Error Response array with key 'field_map'.
	 */
	public function map_template_fields( array $template_context ) {
		$payload = array(
			'action' => 'map_template_fields',
			'data'   => $template_context,
		);

		$response = $this->request( $payload );

		$this->log( 'map_template_fields', $template_context, $response );

		return $response;
	}

	/**
	 * Maps each template line with blanks to an ordered list of canonical keys.
	 *
	 * @param array $line_context Context payload with lines and allowed canonical keys.
	 * @return array|WP_Error Response array with key 'line_map'.
	 */
	public function map_template_line_blanks( array $line_context ) {
		$payload = array(
			'action' => 'map_template_line_blanks',
			'data'   => $line_context,
		);

		$response = $this->request( $payload );

		$this->log( 'map_template_line_blanks', $line_context, $response );

		return $response;
	}

	/**
	 * Sends a POST request to ChatGPT and expects JSON content in the response.
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error Decoded response array, or WP_Error on failure.
	 */
	private function request( array $payload ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'OpenAI API key is not configured.', 'arriendo-facil' ) );
		}

		$endpoint = ! empty( $this->api_url ) ? $this->api_url : $this->default_openai_endpoint;
		$prompt   = $this->build_action_prompt( $payload );

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			),
			'body'    => wp_json_encode(
				array(
					'model'           => $this->model,
					'response_format' => array( 'type' => 'json_object' ),
					'messages'        => array(
						array(
							'role'    => 'system',
							'content' => 'You are a rental management assistant. Return strictly valid JSON only.',
						),
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
				),
			),
			'timeout' => 30,
		);

		$response = wp_remote_post( esc_url_raw( $endpoint ), $args );

		// If a custom endpoint returns HTML (marketing/login page), retry once with official API endpoint.
		if ( ! is_wp_error( $response ) && $endpoint !== $this->default_openai_endpoint ) {
			$first_body = (string) wp_remote_retrieve_body( $response );
			if ( $this->is_probably_html_response( $first_body ) ) {
				$response = wp_remote_post( esc_url_raw( $this->default_openai_endpoint ), $args );
				$endpoint = $this->default_openai_endpoint;
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = $this->decode_json_flexible( $body );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'OpenAI request failed.', 'arriendo-facil' );
			return new WP_Error( 'openai_http_error', $error_message );
		}

		if ( null === $data ) {
			$preview = $this->preview_body( $body );
			return new WP_Error( 'invalid_response', sprintf( __( 'Invalid AI API response from endpoint %1$s. Preview: %2$s', 'arriendo-facil' ), esc_url_raw( $endpoint ), $preview ) );
		}

		$content = $this->extract_message_content( $data );

		if ( '' === $content ) {
			return new WP_Error( 'invalid_response', __( 'Empty response from ChatGPT.', 'arriendo-facil' ) );
		}

		$parsed_content = $this->decode_json_flexible( $content );
		if ( null === $parsed_content ) {
			$preview = $this->preview_body( $content );
			return new WP_Error( 'invalid_response', sprintf( __( 'ChatGPT did not return valid JSON. Preview: %s', 'arriendo-facil' ), $preview ) );
		}

		return $parsed_content;
	}

	/**
	 * Decodes JSON with support for BOM and embedded JSON fragments.
	 *
	 * @param string $raw Raw text body.
	 * @return array|null
	 */
	private function decode_json_flexible( $raw ) {
		$text = trim( (string) $raw );

		if ( '' === $text ) {
			return null;
		}

		// Strip UTF-8 BOM when upstream adds it.
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );

		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/\{[\s\S]*\}/', $text, $matches ) ) {
			$decoded = json_decode( $matches[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Extracts content text from OpenAI-compatible response payload.
	 *
	 * @param array $data Decoded response payload.
	 * @return string
	 */
	private function extract_message_content( array $data ) {
		if ( isset( $data['choices'][0]['message']['content'] ) && is_string( $data['choices'][0]['message']['content'] ) ) {
			return trim( $data['choices'][0]['message']['content'] );
		}

		if ( isset( $data['choices'][0]['message']['content'] ) && is_array( $data['choices'][0]['message']['content'] ) ) {
			$parts = array();
			foreach ( $data['choices'][0]['message']['content'] as $item ) {
				if ( is_array( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) ) {
					$parts[] = $item['text'];
				}
			}

			return trim( implode( "\n", $parts ) );
		}

		return '';
	}

	/**
	 * Builds a short safe preview from response text for diagnostics.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function preview_body( $text ) {
		$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );

		if ( '' === $plain ) {
			return '[empty body]';
		}

		if ( strlen( $plain ) > 180 ) {
			return substr( $plain, 0, 180 ) . '...';
		}

		return $plain;
	}

	/**
	 * Detects whether a response body looks like HTML instead of API JSON.
	 *
	 * @param string $text Raw response body.
	 * @return bool
	 */
	private function is_probably_html_response( $text ) {
		$sample = strtolower( (string) $text );

		if ( '' === trim( $sample ) ) {
			return false;
		}

		return false !== strpos( $sample, '<!doctype html' )
			|| false !== strpos( $sample, '<html' )
			|| false !== strpos( $sample, '<head' )
			|| false !== strpos( $sample, 'openai | openai' );
	}

	/**
	 * Builds a deterministic prompt based on plugin action payload.
	 *
	 * @param array $payload AI action payload.
	 * @return string
	 */
	private function build_action_prompt( array $payload ) {
		$action = isset( $payload['action'] ) ? (string) $payload['action'] : '';
		$data   = isset( $payload['data'] ) ? $payload['data'] : array();

		if ( 'predict_cost' === $action ) {
			return "Task: Predict monthly rent based on provided accommodation data. Return JSON with key 'predicted_cost' as numeric value only. Input: " . wp_json_encode( $data );
		}

		if ( 'generate_document' === $action ) {
			$template_content = ( ! empty( $data['template_available'] ) && isset( $data['template_text'] ) && '' !== trim( (string) $data['template_text'] ) )
				? (string) $data['template_text']
				: 'null';

			$reservation_data = $data;
			unset( $reservation_data['template_text'] );

			return "Eres un Asistente Legal Automatizado integrado en una plataforma de reservas de inmuebles. Tu función es generar o completar contratos de arrendamiento con precisión absoluta.\n\n"
				. "### LÓGICA DE ACTUACIÓN:\n"
				. "1. ESCENARIO A (Plantilla existente): Si se proporciona una [PLANTILLA], completa los espacios marcados o puntos suspensivos (......) usando exclusivamente los [DATOS_RESERVA]. Mantén el formato, negrillas y estructura original intactos.\n"
				. "2. ESCENARIO B (Sin plantilla): Si [PLANTILLA] es null o está vacío, redacta un contrato de arrendamiento estándar, formal y profesional basado en los [DATOS_RESERVA], usando la plantilla base incluida en legal_template_base si está disponible, incluyendo cláusulas de Objeto, Plazo, Canon, Garantía y Firmas conforme a la normativa ecuatoriana vigente 2026.\n\n"
				. "### REGLAS CRÍTICAS:\n"
				. "- NO parafrasees el texto legal existente en el Escenario A.\n"
				. "- Los datos insertados deben integrarse de forma natural y profesional.\n"
				. "- No incluyas introducciones, saludos ni comentarios. La salida debe ser directamente el cuerpo del documento dentro del JSON.\n"
				. "- Si el documento original tiene tablas o líneas de firma, presérvalas.\n"
				. "- Devuelve ÚNICAMENTE JSON estrictamente válido con las claves: 'contract_text' (requerido, texto completo del contrato) y 'document_url' (cadena vacía \"\").\n\n"
				. "### DATOS_RESERVA:\n"
				. wp_json_encode( $reservation_data ) . "\n\n"
				. "### PLANTILLA:\n"
				. $template_content;
		}

		if ( 'score_guest' === $action ) {
			return "Task: Score guest suitability from 0 to 100 and summarize briefly. Return JSON with keys 'score' (number) and 'summary' (string). Input: " . wp_json_encode( $data );
		}

		if ( 'generate_cleaning_contract' === $action ) {
			return "Task: Draft a concise professional Spanish cleaning-service contract request text. Return JSON with key 'contract_text' as plain text only. Input: " . wp_json_encode( $data );
		}

		if ( 'map_template_fields' === $action ) {
			return "Task: Analyze owner lease-template labels and map each detected label to one canonical key when possible. Use only these canonical keys: owner_name, owner_email, owner_id_number, guest_name, guest_email, guest_phone, guest_id_number, accommodation_title, accommodation_address, start_date, end_date, monthly_rent, guarantee_text, current_date. Return strictly JSON with key 'field_map' as an object where each key is the original detected label and each value is one canonical key from the list. If a label has no confident mapping, do not include it. Input: " . wp_json_encode( $data );
		}

		if ( 'map_template_line_blanks' === $action ) {
			return "### SYSTEM PROMPT ###\n"
				. "Actua como un procesador de documentos legales especializado en mapeo de plantillas .docx.\n\n"
				. "ENTRADA:\n"
				. "1. Texto plano del contrato del Owner.\n"
				. "2. Datos capturados por el chatbot (si existen).\n"
				. "3. Lista de ocurrencias de blancos con contexto cercano antes y despues.\n\n"
				. "OBJETIVO:\n"
				. "Para cada ocurrencia de blanco, identificar cual clave canonica corresponde.\n\n"
				. "REGLAS DE ORO:\n"
				. "1. IDENTIFICACION DE CAMPOS: Busca guiones bajos (_______), puntos suspensivos (..........), o etiquetas entre corchetes/paréntesis que indiquen un espacio a llenar.\n"
				. "2. NO REESCRIBIR: No generes el contrato ni redactes frases. Solo mapea cada blanco a UNA clave canonica permitida.\n"
				. "3. CONSERVADOR: Si el contexto no permite certeza alta, devuelve cadena vacia \"\" para ese blanco. Nunca adivines.\n"
				. "4. DIFERENCIA CAMPOS PARECIDOS: owner_name no es guest_name; guarantee_text no es destino de uso; accommodation_title no es ciudad; guest_id_number no debe reemplazar el nombre del arrendatario.\n"
				. "5. SALIDA: Devuelve UNICAMENTE JSON con la forma {\"line_map\": {\"id\": [\"canonical_key\"]}}.\n\n"
				. "CLAVES CANONICAS PERMITIDAS:\n"
				. implode( ', ', isset( $data['allowed_canonical'] ) && is_array( $data['allowed_canonical'] ) ? $data['allowed_canonical'] : array() )
				. "\n\nDATOS:\n"
				. wp_json_encode( $data );
		}

		return "Task: Analyze provided data and return JSON object. Input: " . wp_json_encode( $payload );
	}

	/**
	 * Logs an AI action to the af_ai_logs table.
	 *
	 * @param string       $action      Action identifier.
	 * @param array        $input_data  Input payload.
	 * @param array|WP_Error $output_data Response from the API.
	 */
	private function log( $action, $input_data, $output_data ) {
		global $wpdb;

		$output = is_wp_error( $output_data )
			? array( 'error' => $output_data->get_error_message() )
			: $output_data;

		$wpdb->insert(
			$wpdb->prefix . 'af_ai_logs',
			array(
				'action'      => sanitize_text_field( $action ),
				'input_data'  => wp_json_encode( $input_data ),
				'output_data' => wp_json_encode( $output ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Returns owner contact rows formatted for AI processing.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_owner_data_for_ai() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'af_owner_contacts';

		$rows = $wpdb->get_results(
			"SELECT id, owner_id_type, owner_id, owner_email, subject, message, status, created_at
			 FROM {$table_name}
			 ORDER BY id DESC
			 LIMIT 100",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sends owner data to ChatGPT endpoint to validate connectivity.
	 *
	 * @return array Result of the operation.
	 */
	public function test_chatgpt_owner_connection() {
		$owners = $this->get_owner_data_for_ai();

		if ( empty( $owners ) ) {
			return array(
				'success' => false,
				'message' => __( 'No owner records found to test ChatGPT.', 'arriendo-facil' ),
			);
		}

		if ( empty( $this->api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'OpenAI API key is missing.', 'arriendo-facil' ),
			);
		}

		$endpoint = ! empty( $this->api_url ) ? $this->api_url : $this->default_openai_endpoint;

		$prompt = 'Validate ChatGPT connectivity and provide a one-line summary for these owner records: ' . wp_json_encode( $owners );

		$response = wp_remote_post(
			esc_url_raw( $endpoint ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode(
					array(
						'model'    => $this->model,
						'messages' => array(
							array(
								'role'    => 'system',
								'content' => 'You are a concise assistant.',
							),
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 200 && $status_code < 300 ) {
			return array(
				'success' => true,
				'message' => __( 'ChatGPT connection successful with owner payload.', 'arriendo-facil' ),
			);
		}

		$body         = wp_remote_retrieve_body( $response );
		$decoded_body = json_decode( $body, true );
		$error_text   = isset( $decoded_body['error']['message'] ) ? (string) $decoded_body['error']['message'] : '';

		return array(
			'success' => false,
			'message' => sprintf(
				/* translators: 1: HTTP status code from ChatGPT endpoint, 2: optional error message */
				__( 'ChatGPT connection failed (HTTP %1$d). %2$s', 'arriendo-facil' ),
				(int) $status_code,
				$error_text
			),
		);
	}
}
