<?php
/**
 * OTA Webhook Handler - Processes webhooks from Booking and Airbnb
 *
 * Handles incoming webhook notifications from OTA platforms
 * to trigger immediate synchronization.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_OTA_Webhook_Handler
 *
 * Receives and processes OTA webhook events.
 */
class Arriendo_Facil_OTA_Webhook_Handler {

	/**
	 * Constructor - registers webhook routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_webhook_routes' ) );
	}

	/**
	 * Registers REST API endpoints for webhook handling.
	 */
	public function register_webhook_routes() {
		register_rest_route(
			'af/v1',
			'/ota/webhook/booking',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_booking_webhook' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'event'       => array(
						'type'     => 'string',
						'required' => false,
					),
					'property_id' => array(
						'type'     => 'integer',
						'required' => false,
					),
					'reservation' => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			'af/v1',
			'/ota/webhook/airbnb',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_airbnb_webhook' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'listings'  => array(
						'type'     => 'array',
						'required' => false,
					),
					'action'    => array(
						'type'     => 'string',
						'required' => false,
					),
					'listing'   => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Handles Booking.com webhooks.
	 *
	 * Booking sends notifications about reservations and calendar updates.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response REST response.
	 */
	public function handle_booking_webhook( WP_REST_Request $request ) {
		$data = $request->get_json_params();

		if ( empty( $data ) ) {
			return new WP_REST_Response( array( 'status' => 'ignored', 'reason' => 'empty_payload' ), 200 );
		}

		// Extract property ID from various possible locations in payload
		$property_id = $data['property_id'] ?? $data['prop_id'] ?? null;

		if ( ! $property_id ) {
			return new WP_REST_Response( array( 'status' => 'ignored', 'reason' => 'no_property_id' ), 200 );
		}

		// Find accommodation linked to this Booking property ID
		$accommodation = $this->find_accommodation_by_booking_id( $property_id );

		if ( ! $accommodation ) {
			return new WP_REST_Response( array( 'status' => 'ignored', 'reason' => 'property_not_found' ), 200 );
		}

		// Trigger sync for this accommodation
		$manager = new Arriendo_Facil_OTA_Sync_Manager();
		$manager->sync_accommodation( $accommodation->ID );

		return new WP_REST_Response(
			array(
				'status'            => 'processed',
				'accommodation_id'  => $accommodation->ID,
				'property_id'       => $property_id,
			),
			200
		);
	}

	/**
	 * Handles Airbnb webhooks.
	 *
	 * Airbnb sends notifications about calendar and reservation updates.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response REST response.
	 */
	public function handle_airbnb_webhook( WP_REST_Request $request ) {
		$data = $request->get_json_params();

		if ( empty( $data ) ) {
			return new WP_REST_Response( array( 'status' => 'ignored', 'reason' => 'empty_payload' ), 200 );
		}

		// Airbnb can send arrays of listings or single listing
		$listing_ids = array();

		if ( ! empty( $data['listings'] ) && is_array( $data['listings'] ) ) {
			$listing_ids = $data['listings'];
		} elseif ( ! empty( $data['listing'] ) && is_object( $data['listing'] ) ) {
			$listing_ids[] = $data['listing']->id ?? null;
		}

		if ( empty( $listing_ids ) ) {
			return new WP_REST_Response( array( 'status' => 'ignored', 'reason' => 'no_listings' ), 200 );
		}

		// Process each listing
		$processed = 0;
		$manager   = new Arriendo_Facil_OTA_Sync_Manager();

		foreach ( $listing_ids as $listing_id ) {
			if ( empty( $listing_id ) ) {
				continue;
			}

			// Find accommodation linked to this Airbnb listing ID
			$accommodation = $this->find_accommodation_by_airbnb_id( $listing_id );

			if ( ! $accommodation ) {
				continue;
			}

			// Trigger sync for this accommodation
			$manager->sync_accommodation( $accommodation->ID );
			$processed++;
		}

		return new WP_REST_Response(
			array(
				'status'      => 'processed',
				'processed'   => $processed,
				'total'       => count( $listing_ids ),
			),
			200
		);
	}

	/**
	 * Finds accommodation by Booking.com property ID.
	 *
	 * @param int $property_id Booking.com property ID.
	 * @return WP_Post|null Accommodation post or null.
	 */
	private function find_accommodation_by_booking_id( $property_id ) {
		$property_id = absint( $property_id );

		$posts = get_posts( array(
			'post_type'  => 'accommodation',
			'meta_key'   => '_af_booking_property_id',
			'meta_value' => $property_id,
			'num_posts'  => 1,
		) );

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Finds accommodation by Airbnb listing ID.
	 *
	 * @param string|int $listing_id Airbnb listing ID.
	 * @return WP_Post|null Accommodation post or null.
	 */
	private function find_accommodation_by_airbnb_id( $listing_id ) {
		$listing_id = sanitize_text_field( $listing_id );

		$posts = get_posts( array(
			'post_type'  => 'accommodation',
			'meta_key'   => '_af_airbnb_listing_id',
			'meta_value' => $listing_id,
			'num_posts'  => 1,
		) );

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Gets the webhook URL for configuration on OTA platform.
	 *
	 * @param string $platform OTA platform (booking, airbnb).
	 * @return string Full webhook URL.
	 */
	public static function get_webhook_url( $platform ) {
		$platform = sanitize_key( $platform );
		return rest_url( "af/v1/ota/webhook/{$platform}" );
	}
}
