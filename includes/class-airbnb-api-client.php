<?php
/**
 * Airbnb API Client
 *
 * Integrates with Airbnb API to fetch availability and reservation data.
 * Note: Airbnb's official API is limited. This implementation supports:
 * - Official Airbnb API (if available in region)
 * - Airbnb Connect (OAuth-based partnership program)
 * - Webhook notifications
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Airbnb_API_Client
 *
 * Implements Airbnb-specific API integration.
 */
class Arriendo_Facil_Airbnb_API_Client extends Arriendo_Facil_OTA_API_Client_Base {

	/**
	 * Airbnb API base URL (Public API v2 where available)
	 */
	const API_BASE_URL = 'https://api.airbnb.com/v2/';

	/**
	 * Airbnb Connect OAuth base URL
	 */
	const OAUTH_BASE_URL = 'https://www.airbnb.com/oauth';

	/**
	 * Webhook endpoint path for receiving notifications
	 */
	const WEBHOOK_PATH = '/wp-json/af/v1/ota/webhook/airbnb';

	/**
	 * OAuth access token (may differ from api_key)
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Constructor
	 *
	 * @param string $api_key Airbnb API key or access token.
	 * @param string $account_id Airbnb account/host ID.
	 */
	public function __construct( $api_key, $account_id ) {
		parent::__construct( $api_key, $account_id, 'airbnb' );
		$this->access_token = $api_key;
	}

	/**
	 * Validates Airbnb credentials by making a test API call.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function validate_credentials() {
		// Test with get_listings endpoint to verify credentials
		$response = $this->request( 'me/listings', 'GET', array(
			'_limit' => 1,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check if we got a valid response
		if ( ! isset( $response['listings'] ) || ! is_array( $response['listings'] ) ) {
			return new WP_Error(
				'invalid_airbnb_response',
				'Invalid response from Airbnb API'
			);
		}

		return true;
	}

	/**
	 * Gets availability for a listing within a date range.
	 *
	 * @param string $listing_id Airbnb listing ID.
	 * @param string $date_from Start date (YYYY-MM-DD).
	 * @param string $date_to End date (YYYY-MM-DD).
	 * @return array|WP_Error Availability data or error.
	 */
	public function get_availability( $listing_id, $date_from, $date_to ) {
		$listing_id = sanitize_text_field( $listing_id );
		$date_from = sanitize_text_field( $date_from );
		$date_to = sanitize_text_field( $date_to );

		if ( ! $listing_id || ! $date_from || ! $date_to ) {
			return new WP_Error( 'invalid_params', 'Missing required parameters' );
		}

		// Validate date format
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			return new WP_Error( 'invalid_date_format', 'Dates must be in YYYY-MM-DD format' );
		}

		// Airbnb calendar endpoint
		$response = $this->request( "listings/{$listing_id}/availability_calendar", 'GET', array(
			'start_date' => $date_from,
			'end_date' => $date_to,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse availability from response
		$availability = $this->parse_availability_response( $response );
		return $availability;
	}

	/**
	 * Checks if a listing is occupied (has any reservations) in date range.
	 *
	 * @param string $listing_id Airbnb listing ID.
	 * @param string $date_from Start date (optional, default: today).
	 * @param string $date_to End date (optional, default: today + 30 days).
	 * @return array|WP_Error Array with 'is_occupied' bool and additional data.
	 */
	public function check_property_occupied( $listing_id, $date_from = null, $date_to = null ) {
		$listing_id = sanitize_text_field( $listing_id );

		// Default date range: today to 30 days from now
		if ( ! $date_from ) {
			$date_from = wp_date( 'Y-m-d' );
		}
		if ( ! $date_to ) {
			$date_to = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
		}

		// Get reservations for the listing
		$response = $this->request( "listings/{$listing_id}/reservations", 'GET', array(
			'start_date' => $date_from,
			'end_date' => $date_to,
			'status' => 'accepted',
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check if there are any accepted reservations
		$reservations = $response['reservations'] ?? array();
		$is_occupied = ! empty( $reservations );

		// Extract booked dates
		$booked_dates = array();
		if ( is_array( $reservations ) ) {
			foreach ( $reservations as $reservation ) {
				if ( isset( $reservation['start_date'], $reservation['end_date'] ) ) {
					$booked_dates[] = array(
						'from' => $reservation['start_date'],
						'to' => $reservation['end_date'],
					);
				}
			}
		}

		return array(
			'is_occupied' => $is_occupied,
			'property_id' => $listing_id,
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
	 * Builds request arguments with Airbnb authentication.
	 *
	 * Airbnb uses Bearer token authentication in Authorization header.
	 *
	 * @param string $method HTTP method.
	 * @param array  $args   Additional arguments/query parameters.
	 * @return array Request arguments for wp_remote_request.
	 */
	protected function build_request_args( $method, $args ) {
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
				'Authorization' => 'Bearer ' . $this->access_token,
			),
		);

		// Add query parameters
		if ( ! empty( $args ) && 'GET' === $method ) {
			$request_args['args'] = $args;
		} elseif ( ! empty( $args ) && 'POST' === $method ) {
			$request_args['body'] = wp_json_encode( $args );
		}

		return $request_args;
	}

	/**
	 * Parses availability response from Airbnb API.
	 *
	 * @param array $response Raw API response.
	 * @return array Parsed availability data.
	 */
	private function parse_availability_response( $response ) {
		$availability = array(
			'available_dates' => array(),
			'unavailable_dates' => array(),
		);

		if ( ! isset( $response['calendar'] ) || ! is_array( $response['calendar'] ) ) {
			return $availability;
		}

		// Extract availability data from response
		foreach ( $response['calendar'] as $date => $status ) {
			if ( 'available' === $status || true === $status || 1 === $status ) {
				$availability['available_dates'][] = $date;
			} else {
				$availability['unavailable_dates'][] = $date;
			}
		}

		return $availability;
	}

	/**
	 * Gets listing details from Airbnb.
	 *
	 * @param string $listing_id Airbnb listing ID.
	 * @return array|WP_Error Listing details or error.
	 */
	public function get_listing_details( $listing_id ) {
		$listing_id = sanitize_text_field( $listing_id );

		if ( ! $listing_id ) {
			return new WP_Error( 'invalid_listing_id', 'Listing ID required' );
		}

		$response = $this->request( "listings/{$listing_id}", 'GET' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * Syncs availability between Airbnb and local accommodation.
	 *
	 * Wrapper method that combines check_property_occupied with logging.
	 *
	 * @param string $listing_id Airbnb listing ID.
	 * @param int    $accommodation_id WordPress accommodation post ID.
	 * @return array|WP_Error Sync result.
	 */
	public function sync_property_availability( $listing_id, $accommodation_id ) {
		$listing_id = sanitize_text_field( $listing_id );
		$accommodation_id = absint( $accommodation_id );

		if ( ! $listing_id || ! $accommodation_id ) {
			return new WP_Error( 'invalid_params', 'Listing ID and accommodation ID required' );
		}

		// Get current availability status
		$status = $this->check_property_occupied( $listing_id );

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		// Log the sync result
		$this->log_request(
			"listings/{$listing_id}/availability_calendar",
			array( 'listing_id' => $listing_id ),
			$status,
			'success'
		);

		return $status;
	}

	/**
	 * Gets all listings for the host account.
	 *
	 * @return array|WP_Error Array of listings or error.
	 */
	public function get_host_listings() {
		$response = $this->request( 'me/listings', 'GET', array(
			'_limit' => 100,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['listings'] ?? array();
	}
}
