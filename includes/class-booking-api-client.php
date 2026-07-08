<?php
/**
 * Booking.com API Client
 *
 * Integrates with Booking.com Partner API to fetch availability and reservation data.
 * API Reference: https://developer.booking.com/
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Booking_API_Client
 *
 * Implements Booking.com-specific API integration.
 */
class Arriendo_Facil_Booking_API_Client extends Arriendo_Facil_OTA_API_Client_Base {

	/**
	 * Booking API base URL
	 */
	const API_BASE_URL = 'https://api.booking.com/v2/';

	/**
	 * Webhook endpoint path for receiving notifications
	 */
	const WEBHOOK_PATH = '/wp-json/af/v1/ota/webhook/booking';

	/**
	 * Constructor
	 *
	 * @param string $api_key Booking Partner API key.
	 * @param string $partner_id Booking Partner ID.
	 */
	public function __construct( $api_key, $partner_id ) {
		parent::__construct( $api_key, $partner_id, 'booking' );
	}

	/**
	 * Validates Booking credentials by making a test API call.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function validate_credentials() {
		// Test with a properties endpoint to verify credentials
		$response = $this->request( 'properties', 'GET', array(
			'limit' => 1,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check if we got a valid response
		if ( ! isset( $response['properties'] ) || ! is_array( $response['properties'] ) ) {
			return new WP_Error(
				'invalid_booking_response',
				'Invalid response from Booking.com API'
			);
		}

		return true;
	}

	/**
	 * Gets availability for a property within a date range.
	 *
	 * @param string $property_id Booking property ID.
	 * @param string $date_from Start date (YYYY-MM-DD).
	 * @param string $date_to End date (YYYY-MM-DD).
	 * @return array|WP_Error Availability data or error.
	 */
	public function get_availability( $property_id, $date_from, $date_to ) {
		$property_id = sanitize_text_field( $property_id );
		$date_from = sanitize_text_field( $date_from );
		$date_to = sanitize_text_field( $date_to );

		if ( ! $property_id || ! $date_from || ! $date_to ) {
			return new WP_Error( 'invalid_params', 'Missing required parameters' );
		}

		// Validate date format
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			return new WP_Error( 'invalid_date_format', 'Dates must be in YYYY-MM-DD format' );
		}

		$response = $this->request( "properties/{$property_id}/availability", 'GET', array(
			'date_from' => $date_from,
			'date_to' => $date_to,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse availability from response
		$availability = $this->parse_availability_response( $response );
		return $availability;
	}

	/**
	 * Checks if a property is occupied (has any reservations) in date range.
	 *
	 * @param string $property_id Booking property ID.
	 * @param string $date_from Start date (optional, default: today).
	 * @param string $date_to End date (optional, default: today + 30 days).
	 * @return array|WP_Error Array with 'is_occupied' bool and additional data.
	 */
	public function check_property_occupied( $property_id, $date_from = null, $date_to = null ) {
		$property_id = sanitize_text_field( $property_id );

		// Default date range: today to 30 days from now
		if ( ! $date_from ) {
			$date_from = wp_date( 'Y-m-d' );
		}
		if ( ! $date_to ) {
			$date_to = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
		}

		// Get reservations for the property
		$response = $this->request( "properties/{$property_id}/reservations", 'GET', array(
			'status' => 'confirmed',
			'date_from' => $date_from,
			'date_to' => $date_to,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check if there are any confirmed reservations
		$reservations = $response['reservations'] ?? array();
		$is_occupied = ! empty( $reservations );

		// Extract booked dates
		$booked_dates = array();
		if ( is_array( $reservations ) ) {
			foreach ( $reservations as $reservation ) {
				if ( isset( $reservation['date_from'], $reservation['date_to'] ) ) {
					$booked_dates[] = array(
						'from' => $reservation['date_from'],
						'to' => $reservation['date_to'],
					);
				}
			}
		}

		return array(
			'is_occupied' => $is_occupied,
			'property_id' => $property_id,
			'booked_dates' => $booked_dates,
			'date_range' => array(
				'from' => $date_from,
				'to' => $date_to,
			),
			'reservation_count' => count( $reservations ),
		);
	}

	/**
	 * Builds full request URL from endpoint.
	 *
	 * @param string $endpoint Relative endpoint path.
	 * @return string Full URL.
	 */
	protected function build_request_url( $endpoint ) {
		// Remove leading slash if present
		$endpoint = ltrim( $endpoint, '/' );
		return self::API_BASE_URL . $endpoint;
	}

	/**
	 * Builds request arguments with Booking authentication.
	 *
	 * Booking.com uses query parameter authentication with Partner ID and API Key.
	 *
	 * @param string $method HTTP method.
	 * @param array  $args   Additional arguments/query parameters.
	 * @return array Request arguments for wp_remote_request.
	 */
	protected function build_request_args( $method, $args ) {
		// Add authentication to query parameters
		$args['partner_id'] = $this->account_identifier; // Partner ID
		$args['key'] = $this->api_key; // API Key

		// Build request arguments
		$request_args = array(
			'method' => $method,
			'timeout' => $this->timeout,
			'redirection' => 5,
			'httpversion' => '1.1',
			'user-agent' => 'Arriendo-Facil/1.0 (+https://arriendofacil.ec)',
			'headers' => array(
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			),
		);

		// Add query parameters to URL
		if ( ! empty( $args ) && 'GET' === $method ) {
			// Will be added to URL by wp_remote_request when built
			$request_args['args'] = $args;
		} elseif ( ! empty( $args ) && 'POST' === $method ) {
			$request_args['body'] = wp_json_encode( $args );
		}

		return $request_args;
	}

	/**
	 * Parses availability response from Booking API.
	 *
	 * @param array $response Raw API response.
	 * @return array Parsed availability data.
	 */
	private function parse_availability_response( $response ) {
		$availability = array(
			'available_dates' => array(),
			'unavailable_dates' => array(),
		);

		if ( ! isset( $response['properties'] ) || ! is_array( $response['properties'] ) ) {
			return $availability;
		}

		// Extract availability data from response
		// This depends on Booking API response format
		foreach ( $response['properties'] as $date => $status ) {
			if ( 'available' === $status || 1 === $status ) {
				$availability['available_dates'][] = $date;
			} else {
				$availability['unavailable_dates'][] = $date;
			}
		}

		return $availability;
	}

	/**
	 * Gets property details from Booking.
	 *
	 * @param string $property_id Booking property ID.
	 * @return array|WP_Error Property details or error.
	 */
	public function get_property_details( $property_id ) {
		$property_id = sanitize_text_field( $property_id );

		if ( ! $property_id ) {
			return new WP_Error( 'invalid_property_id', 'Property ID required' );
		}

		$response = $this->request( "properties/{$property_id}", 'GET' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * Syncs availability between Booking and local accommodation.
	 *
	 * Wrapper method that combines check_property_occupied with logging.
	 *
	 * @param string $property_id Booking property ID.
	 * @param int    $accommodation_id WordPress accommodation post ID.
	 * @return array|WP_Error Sync result.
	 */
	public function sync_property_availability( $property_id, $accommodation_id ) {
		$property_id = sanitize_text_field( $property_id );
		$accommodation_id = absint( $accommodation_id );

		if ( ! $property_id || ! $accommodation_id ) {
			return new WP_Error( 'invalid_params', 'Property ID and accommodation ID required' );
		}

		// Get current availability status
		$status = $this->check_property_occupied( $property_id );

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		// Log the sync result
		$this->log_request(
			"properties/{$property_id}/availability",
			array( 'property_id' => $property_id ),
			$status,
			'success'
		);

		return $status;
	}
}
