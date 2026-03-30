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

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'OpenAI request failed.', 'arriendo-facil' );
			return new WP_Error( 'openai_http_error', $error_message );
		}

		if ( null === $data ) {
			return new WP_Error( 'invalid_response', __( 'Invalid AI API response.', 'arriendo-facil' ) );
		}

		$content = '';
		if ( isset( $data['choices'][0]['message']['content'] ) && is_string( $data['choices'][0]['message']['content'] ) ) {
			$content = $data['choices'][0]['message']['content'];
		}

		if ( '' === $content ) {
			return new WP_Error( 'invalid_response', __( 'Empty response from ChatGPT.', 'arriendo-facil' ) );
		}

		$parsed_content = json_decode( $content, true );
		if ( null === $parsed_content ) {
			return new WP_Error( 'invalid_response', __( 'ChatGPT did not return valid JSON.', 'arriendo-facil' ) );
		}

		return $parsed_content;
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
			return "Task: Generate a lease document URL suggestion. Return JSON with key 'document_url' as string URL. Input: " . wp_json_encode( $data );
		}

		if ( 'score_guest' === $action ) {
			return "Task: Score guest suitability from 0 to 100 and summarize briefly. Return JSON with keys 'score' (number) and 'summary' (string). Input: " . wp_json_encode( $data );
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
