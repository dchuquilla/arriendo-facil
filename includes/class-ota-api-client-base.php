<?php
/**
 * Base class for OTA (Online Travel Agency) API clients.
 *
 * Provides standard interface for Booking.com, Airbnb, and other OTA platforms.
 * Implements retry logic, rate limiting, and error handling.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_OTA_API_Client_Base
 *
 * Abstract base class for OTA API integrations.
 */
abstract class Arriendo_Facil_OTA_API_Client_Base {

	/**
	 * API key for authentication.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Account identifier (partner ID, account ID, etc).
	 *
	 * @var string
	 */
	protected $account_identifier;

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	protected $timeout = 15;

	/**
	 * Maximum retry attempts for failed requests.
	 *
	 * @var int
	 */
	protected $max_retries = 3;

	/**
	 * Base delay between retries in seconds (exponential backoff).
	 *
	 * @var int
	 */
	protected $retry_delay_seconds = 2;

	/**
	 * OTA platform identifier ('booking', 'airbnb', etc).
	 *
	 * @var string
	 */
	protected $platform;

	/**
	 * Constructor.
	 *
	 * @param string $api_key              API key for platform.
	 * @param string $account_identifier   Account ID on platform.
	 * @param string $platform             Platform identifier.
	 */
	public function __construct( $api_key, $account_identifier, $platform ) {
		$this->api_key = $api_key;
		$this->account_identifier = $account_identifier;
		$this->platform = $platform;
	}

	/**
	 * Validates that credentials are correct and account is accessible.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	abstract public function validate_credentials();

	/**
	 * Gets availability status for a property and date range.
	 *
	 * @param string $property_id  Property/listing ID on platform.
	 * @param string $date_from    Start date (YYYY-MM-DD).
	 * @param string $date_to      End date (YYYY-MM-DD).
	 * @return array|WP_Error Array with availability data or WP_Error on failure.
	 */
	abstract public function get_availability( $property_id, $date_from, $date_to );

	/**
	 * Checks if a property is occupied (any bookings in date range).
	 *
	 * @param string $property_id  Property/listing ID on platform.
	 * @param string $date_from    Start date (optional, YYYY-MM-DD).
	 * @param string $date_to      End date (optional, YYYY-MM-DD).
	 * @return array|WP_Error Array with 'is_occupied' bool or WP_Error.
	 */
	abstract public function check_property_occupied( $property_id, $date_from = null, $date_to = null );

	/**
	 * Makes an HTTP request with retry logic and rate limiting.
	 *
	 * Implements exponential backoff for retries and respects rate limit headers.
	 *
	 * @param string $endpoint   API endpoint (relative URL).
	 * @param string $method     HTTP method (GET, POST, PUT, DELETE).
	 * @param array  $args       Additional arguments for wp_remote_request.
	 * @return array|WP_Error Response body as array or WP_Error on failure.
	 */
	protected function request( $endpoint, $method = 'GET', $args = array() ) {
		// Check rate limiting
		$rate_check = $this->check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Setup request
		$url = $this->build_request_url( $endpoint );
		$request_args = $this->build_request_args( $method, $args );

		$attempt = 0;
		while ( $attempt < $this->max_retries ) {
			// Make request
			$response = wp_remote_request( $url, $request_args );

			// Check for network errors
			if ( is_wp_error( $response ) ) {
				$attempt++;
				if ( $attempt < $this->max_retries ) {
					$delay = $this->retry_delay_seconds * pow( 2, $attempt - 1 );
					sleep( $delay );
					continue;
				}
				return $response;
			}

			// Get response code
			$code = wp_remote_retrieve_response_code( $response );

			// Success
			if ( $code >= 200 && $code < 300 ) {
				$this->update_rate_limit( $response );
				return json_decode( wp_remote_retrieve_body( $response ), true );
			}

			// Rate limit hit - wait and retry
			if ( 429 === $code ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$delay = max( $retry_after, $this->retry_delay_seconds * pow( 2, $attempt ) );
				sleep( $delay );
				$attempt++;
				continue;
			}

			// 5xx error - retry
			if ( $code >= 500 ) {
				$attempt++;
				if ( $attempt < $this->max_retries ) {
					$delay = $this->retry_delay_seconds * pow( 2, $attempt - 1 );
					sleep( $delay );
					continue;
				}
			}

			// Client error (4xx except 429) - don't retry
			if ( $code >= 400 && $code < 500 ) {
				$body = wp_remote_retrieve_body( $response );
				return new WP_Error(
					"api_client_error_{$code}",
					"API error $code: " . $body,
					array( 'response_code' => $code, 'response_body' => $body )
				);
			}

			// Other error
			$attempt++;
		}

		return new WP_Error(
			'api_max_retries_exceeded',
			"Max retries exceeded for endpoint: $endpoint"
		);
	}

	/**
	 * Builds full request URL from endpoint.
	 *
	 * Should be overridden by subclasses to provide platform-specific base URL.
	 *
	 * @param string $endpoint Relative endpoint path.
	 * @return string Full URL.
	 */
	abstract protected function build_request_url( $endpoint );

	/**
	 * Builds request arguments with authentication headers.
	 *
	 * Should be overridden by subclasses for platform-specific auth.
	 *
	 * @param string $method HTTP method.
	 * @param array  $args   Additional arguments.
	 * @return array Request arguments for wp_remote_request.
	 */
	abstract protected function build_request_args( $method, $args );

	/**
	 * Checks rate limit status for this platform.
	 *
	 * Returns error if rate limit exceeded.
	 *
	 * @return bool|WP_Error True if OK, WP_Error if rate limited.
	 */
	protected function check_rate_limit() {
		$transient_key = "af_ota_rate_limit_{$this->platform}";
		$current_count = (int) get_transient( $transient_key );

		// Default limit: 100 requests per minute
		$limit = apply_filters( "af_ota_{$this->platform}_rate_limit", 100 );

		if ( $current_count >= $limit ) {
			return new WP_Error(
				'rate_limit_exceeded',
				"Rate limit exceeded for {$this->platform}. Max {$limit} requests/minute."
			);
		}

		set_transient( $transient_key, $current_count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Updates rate limit info from response headers.
	 *
	 * @param array $response WP remote response.
	 * @return void
	 */
	protected function update_rate_limit( $response ) {
		$remaining = wp_remote_retrieve_header( $response, 'x-rate-limit-remaining' );
		$reset = wp_remote_retrieve_header( $response, 'x-rate-limit-reset' );

		if ( $remaining ) {
			// Store remaining requests in transient
			set_transient( "af_ota_{$this->platform}_remaining", $remaining, MINUTE_IN_SECONDS );
		}

		if ( $reset ) {
			// Store reset time in transient
			set_transient( "af_ota_{$this->platform}_reset", $reset, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Logs API request and response for debugging.
	 *
	 * @param string $endpoint  API endpoint.
	 * @param array  $request   Request data.
	 * @param array  $response  Response data.
	 * @param string $status    Status (success, failed, etc).
	 * @return void
	 */
	protected function log_request( $endpoint, $request, $response, $status = 'success' ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$log_entry = array(
				'timestamp' => current_time( 'mysql' ),
				'platform' => $this->platform,
				'endpoint' => $endpoint,
				'status' => $status,
				'request' => $request,
				'response' => $response,
			);

			error_log( 'OTA API: ' . wp_json_encode( $log_entry ) );
		}
	}
}
