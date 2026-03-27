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
		$this->api_url = get_option( 'af_ai_api_url', '' );
		$this->api_key = get_option( 'af_ai_api_key', '' );
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

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			),
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
	 * Collects owner data and sends it to the Gemini AI API.
	 *
	 * @return array Result of the operation.
	 */
	function af_gemini_collect_owner_data() {
		global $wpdb;

		// Query to fetch owner data.
		$owners = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}owners", ARRAY_A );

		if ( empty( $owners ) ) {
			return array(
				'success' => false,
				'message' => __( 'No owner data found.', 'arriendo-facil' ),
			);
		}

		// Prepare data for the Gemini AI API.
		$api_url = get_option( 'af_ai_api_url' );
		$api_key = get_option( 'af_ai_api_key' );

		if ( empty( $api_url ) || empty( $api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'Gemini API configuration is incomplete.', 'arriendo-facil' ),
			);
		}

		$response = wp_remote_post(
			$api_url . '/owners',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $owners ),
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
				'message' => __( 'Owner data successfully sent.', 'arriendo-facil' ),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Error sending data to Gemini AI.', 'arriendo-facil' ),
		);
	}
}
