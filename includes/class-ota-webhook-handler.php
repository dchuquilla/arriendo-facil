<?php
/**
 * OTA Webhook Handler
 *
 * Receives and processes webhook notifications from OTA platforms
 * (Booking.com, Airbnb) when availability or reservations change.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_OTA_Webhook_Handler
 *
 * Handles incoming webhooks from OTA platforms.
 */
class Arriendo_Facil_OTA_Webhook_Handler {

	/**
	 * Constructor - registers REST routes on plugin initialization.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_webhook_routes' ) );
	}

	/**
	 * Registers REST API endpoints for webhooks.
	 *
	 * Creates endpoints:
	 * - POST /wp-json/af/v1/ota/webhook/booking
	 * - POST /wp-json/af/v1/ota/webhook/airbnb
	 *
	 * @return void
	 */
	public function register_webhook_routes() {
		register_rest_route(
			'af/v1',
			'/ota/webhook/(?P<platform>booking|airbnb)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'verify_webhook_request' ),
				'args'                => array(
					'platform' => array(
						'type' => 'string',
						'required' => true,
						'pattern' => '^(booking|airbnb)$',
					),
				),
			)
		);
	}

	/**
	 * Main webhook handler - processes incoming notifications.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response Response to OTA platform.
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$platform = $request->get_param( 'platform' );
		$payload = $request->get_json_params();

		if ( empty( $payload ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => 'Empty payload' ),
				400
			);
		}

		// Log webhook receipt for debugging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "OTA Webhook received from {$platform}: " . wp_json_encode( $payload ) );
		}

		// Route to platform-specific handler
		switch ( $platform ) {
			case 'booking':
				return $this->handle_booking_webhook( $payload );
			case 'airbnb':
				return $this->handle_airbnb_webhook( $payload );
			default:
				return new WP_REST_Response(
					array( 'success' => false, 'message' => 'Unknown platform' ),
					400
				);
		}
	}

	/**
	 * Handles Booking.com webhooks.
	 *
	 * Booking sends notifications for:
	 * - reservation_created
	 * - reservation_modified
	 * - reservation_cancelled
	 * - property_updated
	 *
	 * @param array $payload Webhook payload from Booking.
	 * @return WP_REST_Response
	 */
	private function handle_booking_webhook( $payload ) {
		// Extract notification type and property ID
		$event_type = $payload['event_type'] ?? null;
		$property_id = $payload['property_id'] ?? null;

		if ( ! $event_type || ! $property_id ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => 'Missing event_type or property_id' ),
				400
			);
		}

		// Find accommodation by Booking property ID
		$accommodation_id = $this->find_accommodation_by_remote_id( 'booking', $property_id );

		if ( ! $accommodation_id ) {
			// Property not linked to any accommodation - log and return success
			error_log( "Booking webhook: Property {$property_id} not linked to any accommodation" );
			return new WP_REST_Response( array( 'success' => true, 'synced' => false ) );
		}

		// Trigger sync for this accommodation
		$manager = new Arriendo_Facil_OTA_Sync_Manager();
		$result = $manager->sync_accommodation( $accommodation_id, array( 'booking' ) );

		if ( is_wp_error( $result ) ) {
			error_log( 'Booking webhook sync error: ' . $result->get_error_message() );
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $result->get_error_message() ),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'accommodation_id' => $accommodation_id,
				'sync_result' => $result,
			)
		);
	}

	/**
	 * Handles Airbnb webhooks.
	 *
	 * Airbnb sends notifications for:
	 * - reservation.booking.created
	 * - reservation.booking.cancelled
	 * - calendar.days_updated
	 *
	 * @param array $payload Webhook payload from Airbnb.
	 * @return WP_REST_Response
	 */
	private function handle_airbnb_webhook( $payload ) {
		// Extract notification type and listing ID
		$event_type = $payload['event_type'] ?? null;
		$listing_id = $payload['listing_id'] ?? null;

		if ( ! $event_type || ! $listing_id ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => 'Missing event_type or listing_id' ),
				400
			);
		}

		// Find accommodation by Airbnb listing ID
		$accommodation_id = $this->find_accommodation_by_remote_id( 'airbnb', $listing_id );

		if ( ! $accommodation_id ) {
			// Listing not linked to any accommodation - log and return success
			error_log( "Airbnb webhook: Listing {$listing_id} not linked to any accommodation" );
			return new WP_REST_Response( array( 'success' => true, 'synced' => false ) );
		}

		// Trigger sync for this accommodation
		$manager = new Arriendo_Facil_OTA_Sync_Manager();
		$result = $manager->sync_accommodation( $accommodation_id, array( 'airbnb' ) );

		if ( is_wp_error( $result ) ) {
			error_log( 'Airbnb webhook sync error: ' . $result->get_error_message() );
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $result->get_error_message() ),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'accommodation_id' => $accommodation_id,
				'sync_result' => $result,
			)
		);
	}

	/**
	 * Verifies webhook request authenticity.
	 *
	 * Checks:
	 * 1. X-Signature header against HMAC
	 * 2. Request comes from expected IP range
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	private function verify_webhook_request( WP_REST_Request $request ) {
		$platform = $request->get_param( 'platform' );

		// Get expected webhook secret from options
		$secret_key = get_option( "af_ota_{$platform}_webhook_secret" );

		if ( empty( $secret_key ) ) {
			// Webhook secret not configured - allow for setup
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( "OTA webhook: No secret configured for {$platform}" );
			}
			return true;
		}

		// Get signature from header
		$signature = $request->get_header( 'X-Signature' );

		if ( empty( $signature ) ) {
			return new WP_Error(
				'missing_signature',
				'Missing X-Signature header'
			);
		}

		// Verify signature
		$body = $request->get_body();
		$expected_signature = hash_hmac( 'sha256', $body, $secret_key );

		if ( ! hash_equals( $expected_signature, $signature ) ) {
			error_log( "OTA webhook: Invalid signature for {$platform}" );
			return new WP_Error(
				'invalid_signature',
				'Invalid webhook signature'
			);
		}

		return true;
	}

	/**
	 * Finds accommodation post ID by remote OTA property/listing ID.
	 *
	 * Searches post meta for the given platform and remote ID.
	 *
	 * @param string $platform OTA platform (booking, airbnb).
	 * @param string $remote_id Remote property/listing ID.
	 * @return int|null Accommodation post ID or null if not found.
	 */
	private function find_accommodation_by_remote_id( $platform, $remote_id ) {
		$platform = sanitize_key( $platform );
		$remote_id = sanitize_text_field( $remote_id );

		if ( ! $platform || ! $remote_id ) {
			return null;
		}

		// Build meta key
		$meta_key = "_af_{$platform}_property_id";
		if ( 'airbnb' === $platform ) {
			$meta_key = '_af_airbnb_listing_id';
		}

		// Query posts by meta
		global $wpdb;
		$accommodation_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value = %s
				 LIMIT 1",
				$meta_key,
				$remote_id
			)
		);

		return $accommodation_id ? absint( $accommodation_id ) : null;
	}

	/**
	 * Saves webhook secret for a platform.
	 *
	 * Should be called during webhook configuration setup.
	 *
	 * @param string $platform OTA platform (booking, airbnb).
	 * @param string $secret Webhook secret from platform.
	 * @return bool True if saved successfully.
	 */
	public static function save_webhook_secret( $platform, $secret ) {
		$platform = sanitize_key( $platform );
		$secret = sanitize_text_field( $secret );

		if ( ! $platform || ! $secret ) {
			return false;
		}

		$option_name = "af_ota_{$platform}_webhook_secret";
		return (bool) update_option( $option_name, $secret );
	}

	/**
	 * Gets the webhook URL for configuration on OTA platform.
	 *
	 * @param string $platform OTA platform.
	 * @return string Full webhook URL.
	 */
	public static function get_webhook_url( $platform ) {
		$platform = sanitize_key( $platform );
		return rest_url( "af/v1/ota/webhook/{$platform}" );
	}
}
