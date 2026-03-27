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
 * Communicates with an external AI API endpoint configured via
 * the plugin settings (Settings > Arriendo Fácil > AI Settings).
 */
class Arriendo_Facil_AI_Service {

	/**
	 * Base URL of the AI API endpoint.
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
	 * Constructor – loads API configuration from plugin options.
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
	 * Sends a POST request to the AI API.
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error Decoded response array, or WP_Error on failure.
	 */
	private function request( array $payload ) {
		if ( empty( $this->api_url ) ) {
			return new WP_Error( 'no_api_url', __( 'AI API URL is not configured.', 'arriendo-facil' ) );
		}

		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( '' !== $this->api_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		$args = array(
			'method'  => 'POST',
			'headers' => $headers,
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
		);

		$response = wp_remote_post( esc_url_raw( $this->api_url ), $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data ) {
			return new WP_Error( 'invalid_response', __( 'Invalid AI API response.', 'arriendo-facil' ) );
		}

		return $data;
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
	 * Sends owner data to Gemini endpoint to validate connectivity.
	 *
	 * @return array Result of the operation.
	 */
	public function test_gemini_owner_connection() {
		$owners = $this->get_owner_data_for_ai();

		if ( empty( $owners ) ) {
			return array(
				'success' => false,
				'message' => __( 'No owner records found to test Gemini.', 'arriendo-facil' ),
			);
		}

		if ( empty( $this->api_url ) ) {
			return array(
				'success' => false,
				'message' => __( 'Gemini API URL is missing.', 'arriendo-facil' ),
			);
		}

		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( '' !== $this->api_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		$response = wp_remote_post(
			esc_url_raw( $this->api_url ),
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'action' => 'test_owner_connection',
						'data'   => array(
							'owners' => $owners,
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
			$message = '' !== $this->api_key
				? __( 'Gemini connection successful with owner payload.', 'arriendo-facil' )
				: __( 'Gemini connection successful (without API key).', 'arriendo-facil' );

			return array(
				'success' => true,
				'message' => $message,
			);
		}

		return array(
			'success' => false,
			'message' => sprintf(
				/* translators: %d: HTTP status code from Gemini endpoint */
				__( 'Gemini connection failed (HTTP %d).', 'arriendo-facil' ),
				(int) $status_code
			),
		);
	}
}
