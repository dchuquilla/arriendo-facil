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
 * Communicates with Anthropic Claude Messages API, with optional
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
	 * Default Anthropic Claude Messages endpoint.
	 *
	 * @var string
	 */
	private $default_claude_endpoint = 'https://api.anthropic.com/v1/messages';

	/**
	 * Claude model used for requests.
	 *
	 * @var string
	 */
	private $model = 'claude-3-5-sonnet-latest';

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
	 * Runs an agent-style mapping for DOCX blanks with stricter legal-template rules.
	 *
	 * @param array $line_context Context payload with blank occurrences and allowed keys.
	 * @return array|WP_Error Response array with key 'line_map'.
	 */
	public function map_template_word_agent( array $line_context ) {
		$payload = array(
			'action' => 'map_template_word_agent',
			'data'   => $line_context,
		);

		$response = $this->request( $payload );

		$this->log( 'map_template_word_agent', $line_context, $response );

		return $response;
	}

	/**
	 * Fills contract blanks by sending the full text + detected blanks + chatbot data to AI.
	 *
	 * The AI returns a JSON object mapping each blank_N to the value that should replace it.
	 *
	 * @param array $context {
	 *     @type string $contract_text    Full flat text of the contract.
	 *     @type array  $blanks           Array of blank context items (id, before, after, blank).
	 *     @type array  $available_values Placeholder values from build_placeholder_values().
	 *     @type array  $payload          Key chatbot fields (guest_name, owner_name, etc.).
	 * }
	 * @return array|WP_Error Response array with key 'replacements'.
	 */
	public function fill_contract_blanks( array $context ) {
		$payload = array(
			'action' => 'fill_contract_blanks',
			'data'   => $context,
		);

		$response = $this->request( $payload );

		$this->log( 'fill_contract_blanks', $context, $response );

		return $response;
	}

	public function fill_contract_blanks_markdown( array $context ) {
		$payload = array(
			'action' => 'fill_contract_blanks_markdown',
			'data'   => $context,
		);

		$response = $this->request( $payload );

		$this->log( 'fill_contract_blanks_markdown', $context, $response );

		return $response;
	}

	/**
	 * Analyzes a DOCX template at upload time to produce a field map for owner preview/approval.
	 *
	 * @param array $context {
	 *     @type string $contract_text Full flat text of the contract.
	 *     @type array  $blanks        Array of { blank_index, before, after, blank }.
	 * }
	 * @return array|WP_Error Response array with key 'field_map'.
	 */
	public function analyze_template_fields( array $context ) {
		$payload = array(
			'action' => 'analyze_template_fields',
			'data'   => $context,
		);

		$response = $this->request( $payload );

		$this->log( 'analyze_template_fields', $context, $response );

		return $response;
	}

	/**
	 * Analyzes contract text directly to infer where fields should be filled.
	 * Used when template has NO blanks but needs field mapping.
	 *
	 * @param array $context Contract text and reservation data.
	 * @return array|WP_Error Response array with key 'field_locations'.
	 */
	public function analyze_contract_for_fields( array $context ) {
		$payload = array(
			'action' => 'analyze_contract_for_fields',
			'data'   => $context,
		);

		$response = $this->request( $payload );

		$this->log( 'analyze_contract_for_fields', $context, $response );

		return $response;
	}

	/**
	 * Sends a POST request to Claude and expects JSON content in the response.
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error Decoded response array, or WP_Error on failure.
	 */
	private function request( array $payload ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Claude API key is not configured.', 'arriendo-facil' ) );
		}

		$endpoint = ! empty( $this->api_url ) ? $this->api_url : $this->default_claude_endpoint;
		$prompt   = $this->build_action_prompt( $payload );
		$action   = isset( $payload['action'] ) ? (string) $payload['action'] : '';

		$heavy_actions = array( 'fill_contract_blanks', 'generate_document', 'map_template_word_agent', 'analyze_template_fields', 'fill_contract_blanks_markdown' );
		$timeout    = in_array( $action, $heavy_actions, true ) ? 60 : 30;
		$max_tokens = 'fill_contract_blanks_markdown' === $action ? 8192 : ( in_array( $action, $heavy_actions, true ) ? 4096 : 2048 );

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Accept'            => 'application/json',
				'Content-Type'      => 'application/json',
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode(
				array(
					'model'           => $this->model,
					'max_tokens'      => $max_tokens,
					'temperature'     => 0,
					'system'          => 'You are a rental management assistant. Return strictly valid JSON only.',
					'messages'        => array(
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
				),
			),
			'timeout' => $timeout,
		);

		$response = wp_remote_post( esc_url_raw( $endpoint ), $args );

		// If a custom endpoint returns HTML (marketing/login page), retry once with official Claude endpoint.
		if ( ! is_wp_error( $response ) && $endpoint !== $this->default_claude_endpoint ) {
			$first_body = (string) wp_remote_retrieve_body( $response );
			if ( $this->is_probably_html_response( $first_body ) ) {
				$response = wp_remote_post( esc_url_raw( $this->default_claude_endpoint ), $args );
				$endpoint = $this->default_claude_endpoint;
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = $this->decode_json_flexible( $body );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Claude request failed.', 'arriendo-facil' );
			return new WP_Error( 'claude_http_error', $error_message );
		}

		if ( null === $data ) {
			$preview = $this->preview_body( $body );
			return new WP_Error( 'invalid_response', sprintf( __( 'Invalid AI API response from endpoint %1$s. Preview: %2$s', 'arriendo-facil' ), esc_url_raw( $endpoint ), $preview ) );
		}

		$content = $this->extract_message_content( $data );

		if ( '' === $content ) {
			return new WP_Error( 'invalid_response', __( 'Empty response from Claude.', 'arriendo-facil' ) );
		}

		$parsed_content = $this->decode_json_flexible( $content );
		if ( null === $parsed_content ) {
			$preview = $this->preview_body( $content );
			return new WP_Error( 'invalid_response', sprintf( __( 'Claude did not return valid JSON. Preview: %s', 'arriendo-facil' ), $preview ) );
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
	 * Extracts content text from Claude/OpenAI-compatible response payload.
	 *
	 * @param array $data Decoded response payload.
	 * @return string
	 */
	private function extract_message_content( array $data ) {
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			$parts = array();
			foreach ( $data['content'] as $item ) {
				if ( is_array( $item ) && isset( $item['type'] ) && 'text' === (string) $item['type'] && isset( $item['text'] ) && is_string( $item['text'] ) ) {
					$parts[] = $item['text'];
				}
			}

			return trim( implode( "\n", $parts ) );
		}

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
			|| false !== strpos( $sample, 'openai | openai' )
			|| false !== strpos( $sample, 'anthropic' );
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

		if ( 'analyze_template_fields' === $action ) {
			$blanks_json  = isset( $data['blanks'] ) ? wp_json_encode( $data['blanks'] ) : '[]';

			return "### AGENTE DE ANÁLISIS DE PLANTILLA DE CONTRATO ###\n\n"
				. "Rol: Eres un abogado ecuatoriano especialista en contratos de arrendamiento. "
				. "Tu tarea es analizar una plantilla de contrato y clasificar CADA espacio en blanco detectado.\n\n"
				. "## OBJETIVO\n"
				. "Para cada blanco (espacio subrayado, puntos suspensivos, tabulaciones) en el contrato, determina:\n"
				. "1. field_key: qué dato debe ir ahí\n"
				. "2. label: etiqueta descriptiva en español\n"
				. "3. source: quién provee ese dato\n\n"
				. "## FIELD_KEYS PERMITIDOS (usa SOLO estos):\n"
				. "- guest_name: Nombre completo del arrendatario/inquilino (source: chatbot)\n"
				. "- guest_id_number: Cédula/identificación del arrendatario (source: chatbot)\n"
				. "- guest_phone: Teléfono del arrendatario (source: chatbot)\n"
				. "- guest_email: Email del arrendatario (source: chatbot)\n"
				. "- owner_name: Nombre completo del arrendador/propietario (source: owner)\n"
				. "- owner_id_number: Cédula/identificación del arrendador (source: owner)\n"
				. "- accommodation_title: Nombre/tipo del inmueble (source: system)\n"
				. "- accommodation_address: Dirección del inmueble (source: system)\n"
				. "- accommodation_city: Ciudad del inmueble (source: system)\n"
				. "- accommodation_square_meters: Metros cuadrados del inmueble (source: system)\n"
				. "- accommodation_bedrooms: Número de habitaciones (source: system)\n"
				. "- accommodation_bathrooms: Número de baños (source: system)\n"
				. "- accommodation_property_type: Tipo de inmueble (source: system)\n"
				. "- monthly_rent: Canon/valor mensual de arriendo (source: system)\n"
				. "- guarantee_text: Texto de garantía/depósito (source: system)\n"
				. "- current_day: Día actual - número (source: system)\n"
				. "- current_month_name: Mes actual - nombre en español (source: system)\n"
				. "- current_year: Año actual (source: system)\n"
				. "- start_date: Fecha de inicio del arriendo (source: system)\n"
				. "- end_date: Fecha de finalización del arriendo (source: system)\n"
				. "- none: Dejar vacío - no se llena automáticamente (source: none)\n\n"
				. "## REGLAS DE CLASIFICACIÓN:\n\n"
				. "### 1. NOMBRES DE PERSONAS:\n"
				. "- 'señor/señora' + contexto 'arrendador'/'propietario' → owner_name\n"
				. "- 'señor/señora' + contexto 'arrendatario'/'inquilino' → guest_name\n"
				. "- El PRIMER nombre mencionado es el arrendador, el SEGUNDO es el arrendatario\n"
				. "- Línea de firma ARRENDADOR → owner_name, ARRENDATARIO → guest_name\n\n"
				. "### 2. CÉDULAS (NUNCA confundir con nombres):\n"
				. "- Después de 'cédula', 'C.C.', 'C.I.', 'consignado con el número' → es un NÚMERO\n"
				. "- Contexto arrendador → owner_id_number, arrendatario → guest_id_number\n\n"
				. "### 3. CIUDAD:\n"
				. "- 'En la ciudad de ___' / 'ciudad de ___' / 'jueces de la ciudad de ___' → accommodation_city\n\n"
				. "### 4. FECHAS DEL DOCUMENTO (fecha de firma, NO del arriendo):\n"
				. "- 'a los ___ días del mes de ___ de/del ___' → current_day, current_month_name, current_year\n"
				. "- '___ de ___ del ___' al final del contrato → current_day, current_month_name, current_year\n"
				. "- Son 3 blancos SEPARADOS: día(número), mes(nombre), año\n\n"
				. "### 5. FECHAS DEL ARRIENDO:\n"
				. "- 'a partir del ___' / 'desde el ___' / 'inicio ___' → start_date\n"
				. "- 'hasta el ___' / 'vence el ___' / 'finaliza ___' → end_date\n\n"
				. "### 6. PROPIEDAD:\n"
				. "- 'propietario de [BLANK]' → accommodation_title\n"
				. "- 'situada en' / 'ubicada en' / 'dirección' → accommodation_address\n"
				. "- 'de ___ m2' / 'metros cuadrados' → accommodation_square_meters\n"
				. "- 'habitaciones' / 'dormitorios' → accommodation_bedrooms\n"
				. "- 'baños' → accommodation_bathrooms\n\n"
				. "### 7. DINERO:\n"
				. "- 'USD ___' / '___ dólares' / 'canon de ___' → monthly_rent\n"
				. "- 'garantía de ___' / 'depósito de ___' → guarantee_text\n\n"
				. "### 8. BLANCOS QUE SON 'none':\n"
				. "- 'dedicarlo a ___', 'accesorios ___', 'Chapas con ___ llaves'\n"
				. "- 'uso y goce de ___', 'recibido ___ llave(s)', 'corresponde(n) a ___'\n"
				. "- 'Plazo es de ___ años', 'servicios básicos ___'\n"
				. "- 'estado civil ___', 'de profesión ___', 'domiciliado en ___'\n"
				. "- Conjunto/Etapa/Manzana/Casa/Villa, testigos, notario\n\n"
				. "## FORMATO DE SALIDA (solo JSON):\n"
				. "{\"field_map\": [{\"blank_index\": 0, \"field_key\": \"...\", \"label\": \"...\", \"source\": \"...\"}]}\n\n"
				. "Incluye TODOS los blancos. No omitas ninguno.\n\n"
				. "BLANCOS DETECTADOS:\n"
				. $blanks_json;
		}

		if ( 'fill_contract_blanks' === $action ) {
			$blanks_json  = isset( $data['blanks'] ) ? wp_json_encode( $data['blanks'] ) : '[]';
			$payload_json = isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : '{}';
			$contract     = isset( $data['contract_text'] ) ? (string) $data['contract_text'] : '';

			return "### AGENTE DE COMPLETACIÓN DE CONTRATO DE ARRENDAMIENTO ###\n\n"
				. "Rol: Eres un abogado experto en contratos de arrendamiento ecuatorianos. Tu tarea es completar SOLO los blancos que tienen un dato correspondiente en los datos de la reserva.\n\n"
				. "## REGLA CRÍTICA: DEJAR VACÍO POR DEFECTO\n"
				. "Si NO estás 100% seguro de qué dato va en un blanco, devuelve \"\" (cadena vacía). Es MUCHO mejor dejar un blanco vacío que poner un dato incorrecto. NUNCA rellenes un blanco con un valor \"por descarte\" o \"porque es el único que queda\".\n\n"
				. "## REGLAS DE MAPEO (lee el contexto ANTES y DESPUÉS de cada blanco):\n\n"
				. "### NOMBRES DE PERSONAS (CRÍTICO - distinguir arrendador de arrendatario):\n"
				. "- Antes: 'señor/señora' + Después: 'arrendador'/'propietario'/'dador' → owner_name\n"
				. "- Antes: 'señor/señora' + Después: 'arrendatario'/'inquilino'/'tomador' → guest_name\n"
				. "- Antes: 'como arrendador' o 'en calidad de arrendador' → owner_name\n"
				. "- Antes: 'arrendamiento al señor' o 'entrega en arrendamiento al' → guest_name\n"
				. "- Antes: 'propietario de' (el señor [BLANK], propietario de) → owner_name\n"
				. "- Antes: 'arrendatario señor' → guest_name\n"
				. "- Al final del contrato: línea de firma 'ARRENDADOR' → owner_name\n"
				. "- Al final del contrato: línea de firma 'ARRENDATARIO' → guest_name\n"
				. "- IMPORTANTE: El primer blanco suele ser arrendador (owner), el segundo arrendatario (guest)\n\n"
				. "### NÚMEROS DE CÉDULA (NUNCA confundir con nombres):\n"
				. "- Antes: 'cédula de ciudadanía' / 'identificación' / 'C.C.' / 'C.I.' + contexto arrendador → owner_id_number\n"
				. "- Antes: 'cédula de ciudadanía' / 'identificación' / 'C.C.' / 'C.I.' + contexto arrendatario → guest_id_number\n"
				. "- Antes: 'consignado con el número' → guest_id_number (es un número, NO un nombre)\n"
				. "- Contexto: después de un nombre y antes de 'de estado civil' → es cédula de esa persona\n\n"
				. "### PROPIEDAD Y UBICACIÓN:\n"
				. "- Antes: 'propietario de' (propietario de [BLANK]) → accommodation_title\n"
				. "- Antes: 'situada en' / 'ubicada en' / 'ubicado en' → accommodation_address\n"
				. "- Antes: 'Calle' / 'Avenida' / 'Av.' → accommodation_address\n"
				. "- Contexto: descripción de inmueble (departamento, casa, local) → accommodation_title\n\n"
				. "### DINERO:\n"
				. "- Antes o después: 'USD por mes' / 'mensual' / 'canon' → monthly_rent\n"
				. "- Antes: 'garantía, la cantidad de' / 'depósito' → guarantee_text\n\n"
				. "### FECHAS:\n"
				. "- Contexto de inicio/desde/a partir de → start_date\n"
				. "- Contexto de hasta/vencimiento/fin → end_date\n"
				. "- Al final: '[BLANK] de [BLANK] del [BLANK]' → current_date (día, mes, año)\n\n"
				. "### BLANCOS QUE SIEMPRE SE DEJAN VACÍOS (devolver \"\"):\n"
				. "- 'dedicarlo a ___' (uso del local)\n"
				. "- 'accesorios ___' (lista de accesorios)\n"
				. "- 'Chapas con ___ llaves' (cantidad de llaves)\n"
				. "- 'uso y goce de ___'\n"
				. "- 'recibido ___ llave(s)' (cantidad de llaves)\n"
				. "- 'corresponde(n) a ___' (descripción de llaves)\n"
				. "- 'Plazo es de ___ años' (duración del contrato)\n"
				. "- 'servicios básico ___' (lista de servicios)\n"
				. "- 'ciudad de ___' (jurisdicción)\n"
				. "- 'estado civil ___' (estado civil)\n"
				. "- 'de profesión ___' (profesión)\n"
				. "- 'Conjunto Habitacional ___' / 'Etapa ___' / 'Manzana ___' / 'Casa ___'\n"
				. "- Cualquier campo que describa características del inmueble (habitaciones, baños, etc.)\n"
				. "- Cualquier campo cuyo dato NO exista en DATOS_RESERVA\n\n"
				. "## ERRORES COMUNES QUE DEBES EVITAR:\n"
				. "1. NO pongas guest_name donde va owner_name o viceversa\n"
				. "2. NO pongas un nombre donde va un número de cédula\n"
				. "3. NO pongas un número de cédula donde va un nombre\n"
				. "4. NO rellenes blancos de accesorios, llaves, años, servicios con datos de la reserva\n"
				. "5. NO repitas el mismo valor en blancos consecutivos sin justificación del contexto\n"
				. "6. Si ves '[BLANK] consignado con el número [BLANK]', el primer blank NO es un nombre, es un DESCRIPTOR (dejar vacío), y el segundo es el número de cédula\n\n"
				. "## FORMATO DE SALIDA:\n"
				. "Devuelve ÚNICAMENTE JSON válido:\n"
				. "{\"replacements\": {\"blank_0\": \"valor o vacío\", \"blank_1\": \"valor o vacío\", ...}}\n\n"
				. "DATOS_RESERVA (usa SOLO estos valores, NUNCA inventes datos):\n"
				. $payload_json . "\n\n"
				. "BLANCOS DETECTADOS (con contexto antes/después):\n"
				. $blanks_json . "\n\n"
				. "TEXTO COMPLETO DEL CONTRATO:\n"
				. $contract;
		}

		if ( 'fill_contract_blanks_markdown' === $action ) {
			$md_text      = isset( $data['markdown_text'] ) ? (string) $data['markdown_text'] : '';
			$payload_json = isset( $data['payload'] ) ? wp_json_encode( $data['payload'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) : '{}';

			return "### AGENTE DE COMPLETACIÓN DE CONTRATO DE ARRENDAMIENTO ###\n\n"
				. "Eres un abogado ecuatoriano experto completando contratos de arrendamiento. Tu trabajo es REEMPLAZAR cada espacio en blanco (secuencias de \\_\\_\\_ o \\.\\.\\.) con el dato correcto de DATOS_RESERVA.\n\n"
				. "## REGLAS ABSOLUTAS (violarlas = documento inválido legalmente):\n"
				. "1. Lee el CONTEXTO CIRCUNDANTE de cada blanco para determinar QUÉ dato corresponde.\n"
				. "2. NUNCA pongas un dato donde no corresponde semánticamente. Un número NO va donde se espera un nombre. Una dirección NO va donde se espera un monto.\n"
				. "3. Si un dato no existe en DATOS_RESERVA, DEJA el blanco intacto (con sus guiones bajos originales).\n"
				. "4. Mantén TODA la estructura Markdown intacta.\n"
				. "5. NO inventes datos. NO añadas ni elimines texto.\n\n"
				. "## MAPEO POSICIONAL EXPLÍCITO:\n\n"
				. "### CLÁUSULA PRIMERA (Comparecientes):\n"
				. "- \"el/la señor/a \\_\\_\\_\" + antes de \"EL ARRENDADOR\" → owner_name\n"
				. "- \"cédula...No. \\_\\_\\_\" + cerca de ARRENDADOR → owner_id_number\n"
				. "- \"estado civil \\_\\_\\_\" → DEJAR EN BLANCO (no tenemos este dato)\n"
				. "- \"el/la señor/a \\_\\_\\_\" + antes de \"EL ARRENDATARIO\" → guest_name\n"
				. "- \"cédula...No. \\_\\_\\_\" + cerca de ARRENDATARIO → guest_id_number\n"
				. "- \"ciudad de \\_\\_\\_\" → accommodation_city\n\n"
				. "### CLÁUSULA SEGUNDA (Objeto - Ubicación):\n"
				. "- \"Provincia de \\_\\_\\_\" → accommodation_province\n"
				. "- \"Cantón \\_\\_\\_\" → accommodation_canton\n"
				. "- \"Parroquia \\_\\_\\_\" → accommodation_parish\n"
				. "- \"dirección exacta en: \\_\\_\\_\" → accommodation_address\n"
				. "- \"\\_\\_\\_ metros cuadrados\" → accommodation_square_meters (DEJAR EN BLANCO si está vacío)\n\n"
				. "### CLÁUSULA CUARTA (Plazo - FECHAS):\n"
				. "- \"a partir del día \\_\\_\\_\" → start_day (solo el número del día, ej: \"3\")\n"
				. "- \"de \\_\\_\\_\\_\\_\\_\\_\\_\\_ del año\" → start_month_name (nombre del mes en español, ej: \"junio\")\n"
				. "- \"del año 20\\_\\_\\_\" → los últimos 2 dígitos de start_year (ej: si start_year=2026, pon \"26\")\n"
				. "- \"fenecerá el día \\_\\_\\_\" → end_day (solo el número del día)\n"
				. "- \"de \\_\\_\\_\\_\\_\\_\\_\\_\\_ del año\" (segunda ocurrencia) → end_month_name\n"
				. "- \"del año 20\\_\\_\\_\" (segunda vez o año fijo) → últimos 2 dígitos de end_year\n"
				. "  IMPORTANTE: NUNCA pongas un monto de dinero, dirección o fecha completa ISO aquí.\n\n"
				. "### CLÁUSULA QUINTA (Canon - DINERO):\n"
				. "- \"\\_\\_\\_ DÓLARES DE LOS ESTADOS UNIDOS\" → monthly_rent_in_words (monto en LETRAS MAYÚSCULAS)\n"
				. "- \"(\\$\\_\\_\\_,00)\" → monthly_rent (solo el número, ej: \"400.00\")\n"
				. "  IMPORTANTE: NUNCA pongas una dirección o nombre donde va un monto de dinero.\n\n"
				. "### CLÁUSULA SEXTA (Garantía - DINERO):\n"
				. "- \"\\_\\_\\_ DÓLARES DE LOS ESTADOS UNIDOS\" (en contexto de garantía/depósito) → guarantee_in_words\n"
				. "- \"(\\$\\_\\_\\_,00)\" (en contexto de garantía/depósito) → guarantee_amount\n"
				. "  IMPORTANTE: NUNCA pongas una dirección o nombre donde va un monto de dinero.\n\n"
				. "### FIRMAS (final del documento):\n"
				. "- Nombre bajo \"EL ARRENDADOR\" → owner_name\n"
				. "- \"C.C. No. \\_\\_\\_\" bajo ARRENDADOR → owner_id_number\n"
				. "- Nombre bajo \"EL ARRENDATARIO\" → guest_name\n"
				. "- \"C.C. No. \\_\\_\\_\" bajo ARRENDATARIO → guest_id_number\n"
				. "- \"ciudad de \\_\\_\\_\" (firma) → accommodation_city\n"
				. "- \"\\_\\_\\_ días del mes\" → start_day (o día actual)\n"
				. "- \"del mes de \\_\\_\\_\" → start_month_name\n"
				. "- \"del año 20\\_\\_\\_\" → últimos dígitos de start_year\n\n"
				. "### OTROS BLANCOS:\n"
				. "- \"gastos...cubiertos por \\_\\_\\_\" → DEJAR EN BLANCO\n"
				. "- \"Jueces...cantón de \\_\\_\\_\" → accommodation_city\n"
				. "- Cuenta bancaria, Banco, estado civil, profesión → DEJAR EN BLANCO\n\n"
				. "## RESTRICCIONES DE TIPO (CRÍTICO):\n"
				. "- Donde se espera un NOMBRE de persona: solo poner owner_name o guest_name\n"
				. "- Donde se espera una CÉDULA: solo poner owner_id_number o guest_id_number\n"
				. "- Donde se espera un MONTO en letras: solo poner monthly_rent_in_words o guarantee_in_words\n"
				. "- Donde se espera un MONTO numérico (\$): solo poner monthly_rent o guarantee_amount\n"
				. "- Donde se espera un DÍA (número): solo poner start_day o end_day\n"
				. "- Donde se espera un MES (texto): solo poner start_month_name o end_month_name\n"
				. "- Donde se espera una CIUDAD/LUGAR: solo poner accommodation_city, accommodation_province, etc.\n"
				. "- Donde se espera una DIRECCIÓN: solo poner accommodation_address\n\n"
				. "## FORMATO DE SALIDA:\n"
				. "Devuelve ÚNICAMENTE un JSON válido (sin texto adicional antes o después):\n"
				. "{\"filled_markdown\": \"...texto completo del contrato con blancos completados...\"}\n\n"
				. "---\n\n"
				. "DATOS_RESERVA:\n" . $payload_json . "\n\n"
				. "---\n\n"
				. "CONTRATO EN MARKDOWN (completa los blancos):\n" . $md_text;
		}

		if ( 'analyze_contract_for_fields' === $action ) {
			return "### ANALISIS DE CONTRATO PARA DETECCION DE CAMPOS ###\n"
				. "Rol: Analiza un contrato legalmente para inferir DÓNDE y QUÉ datos deben ser completados.\n"
				. "Objetivo: Detectar ubicaciones de campos incluso sin blancos explícitos (___, ..., …).\n\n"
				. "Instrucciones:\n"
				. "1) Lee el texto completo del contrato.\n"
				. "2) Identifica patrones donde faltan valores, tales como:\n"
				. "   - Después de 'Arrendatario: ' o 'Sr. / Sra. ' => guest_name\n"
				. "   - Después de 'Cédula' y cerca de 'arrendatario' => guest_id_number\n"
				. "   - Después de 'Propietario: ' o 'Arrendador: ' => owner_name\n"
				. "   - Después de 'Cédula' y cerca de 'arrendador' => owner_id_number\n"
				. "   - Después de 'USD', 'canon', 'renta', 'mensual' => monthly_rent\n"
				. "   - Después de 'Ubicada en', 'Domicilio: ' => accommodation_address\n"
				. "   - Después de 'Inmueble: ', 'Propiedad: ' => accommodation_title\n"
				. "   - Después de 'Garantía: ', 'Depósito: ' => guarantee_text\n"
				. "   - Después de 'Desde', 'Inicio', 'A partir de' => start_date\n"
				. "   - Después de 'Hasta', 'Finaliza en', 'Vence' => end_date\n"
				. "   - Después de 'Email', 'Correo' => guest_email\n"
				. "   - Después de 'Teléfono', 'Celular' => guest_phone\n\n"
				. "3) Para CADA campo identificado:\n"
				. "   - Nombre del campo (canonical key)\n"
				. "   - Ubicación aproximada en el contrato (párrafo/línea)\n"
				. "   - Confianza: HIGH/MEDIUM/NONE\n\n"
				. "4) Salida JSON:\n"
				. "   {\"field_locations\": [\n"
				. "     {\"field\": \"guest_name\", \"location\": \"Párrafo 2, línea 5\", \"confidence\": \"HIGH\"},\n"
				. "     {\"field\": \"monthly_rent\", \"location\": \"Párrafo 4, línea 12\", \"confidence\": \"HIGH\"},\n"
				. "     ...\n"
				. "   ]}\n\n"
				. "Claves permitidas:\n"
				. implode( ', ', isset( $data['allowed_canonical'] ) && is_array( $data['allowed_canonical'] ) ? $data['allowed_canonical'] : array() )
				. "\n\nContrato a analizar:\n"
				. wp_json_encode( $data );
		}

		if ( 'map_template_word_agent' === $action ) {
			return "### AGENT WORD: MAPEO DE BLANCOS Y CAMPOS DOCX ###\n"
				. "Rol: Eres un agente experto en plantillas de contrato DOCX para arrendamiento en Ecuador.\n"
				. "Objetivo: mapear cada blank/espacio a su campo correspondiente para completarlo automáticamente.\n\n"
				. "Reglas:\n"
				. "1) Detecta todos los espacios a completar en el contrato, no solo blancos con guiones/puntos.\n"
				. "2) Busca palabras clave que indiquen qué campo va en cada lugar:\n"
				. "   - 'nombre', 'sr.', 'srta.' junto con 'arrendador'/'propietario' => owner_name\n"
				. "   - 'nombre', 'sr.', 'srta.' junto con 'arrendatario'/'inquilino' => guest_name\n"
				. "   - 'cedula', 'identificacion' junto con 'arrendador' => owner_id_number\n"
				. "   - 'cedula', 'identificacion' junto con 'arrendatario' => guest_id_number\n"
				. "   - 'canon', 'renta', 'monto', 'mensual', 'valor', 'usd' => monthly_rent\n"
				. "   - 'fecha', 'inicio', 'desde', 'comienza' => start_date\n"
				. "   - 'hasta', 'termina', 'vencimiento', 'fin', 'finaliza' => end_date\n"
				. "   - 'ubicada', 'direccion', 'domicilio', 'calle', 'avenida' => accommodation_address\n"
				. "   - 'inmueble', 'propiedad', 'departamento', 'casa', 'local' => accommodation_title\n"
				. "   - 'email', 'correo', 'mail' => guest_email o owner_email (según contexto)\n"
				. "   - 'telefono', 'celular', 'movil', 'contacto' => guest_phone\n"
				. "   - 'garantia', 'deposito', 'fianza', 'caucion' => guarantee_text\n\n"
				. "3) Para CADA blank/campo detectado:\n"
				. "   - Asigna confianza: HIGH (muy claro), MEDIUM (parcial), NONE (ambiguo)\n"
				. "   - Mapea a UNA sola clave canonica (ver lista abajo)\n"
				. "   - Si no puedes mapear, devuelve \"\" (quedará en blanco)\n\n"
				. "4) Prioridad: Campos críticos (nombres, montos, fechas, direcciones) = HIGH\n"
				. "5) NO inventar datos ni reescribir. Solo mapear dónde van.\n"
				. "6) Salida OBLIGATORIA: JSON válido con TODOS los blancos encontrados:\n"
				. "   {\"line_map\": {\"blank_0\": [\"guest_name\", \"HIGH\"], \"blank_1\": [\"monthly_rent\", \"HIGH\"], ...}}\n"
				. "7) Si encuentra 0 blancos, devolver {\"line_map\": {}}\n\n"
				. "Claves canónicas permitidas (SOLO estas):\n"
				. implode( ', ', isset( $data['allowed_canonical'] ) && is_array( $data['allowed_canonical'] ) ? $data['allowed_canonical'] : array() )
				. "\n\nDatos del contrato:\n"
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
	 * Sends owner data to Claude endpoint to validate connectivity.
	 *
	 * @return array Result of the operation.
	 */
	public function test_chatgpt_owner_connection() {
		$owners = $this->get_owner_data_for_ai();

		if ( empty( $owners ) ) {
			return array(
				'success' => false,
				'message' => __( 'No owner records found to test Claude.', 'arriendo-facil' ),
			);
		}

		if ( empty( $this->api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'Claude API key is missing.', 'arriendo-facil' ),
			);
		}

		$endpoint = ! empty( $this->api_url ) ? $this->api_url : $this->default_claude_endpoint;

		$prompt = 'Validate Claude connectivity and provide a one-line summary for these owner records: ' . wp_json_encode( $owners );

		$response = wp_remote_post(
			esc_url_raw( $endpoint ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $this->model,
						'max_tokens' => 256,
						'system'     => 'You are a concise assistant.',
						'messages' => array(
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
				'message' => __( 'Claude connection successful with owner payload.', 'arriendo-facil' ),
			);
		}

		$body         = wp_remote_retrieve_body( $response );
		$decoded_body = json_decode( $body, true );
		$error_text   = isset( $decoded_body['error']['message'] ) ? (string) $decoded_body['error']['message'] : '';

		return array(
			'success' => false,
			'message' => sprintf(
				/* translators: 1: HTTP status code from Claude endpoint, 2: optional error message */
				__( 'Claude connection failed (HTTP %1$d). %2$s', 'arriendo-facil' ),
				(int) $status_code,
				$error_text
			),
		);
	}
}
