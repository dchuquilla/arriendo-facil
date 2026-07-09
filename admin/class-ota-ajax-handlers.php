<?php
/**
 * OTA AJAX Handlers - Simplified iCal-based handlers
 *
 * Handles AJAX requests for iCal testing and manual sync.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_OTA_AJAX_Handlers
 *
 * Handles AJAX requests for OTA synchronization.
 */
class Arriendo_Facil_OTA_AJAX_Handlers {

	/**
	 * Constructor - registers AJAX handlers.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_test_ical_url', array( $this, 'test_ical_url' ) );
		add_action( 'wp_ajax_af_sync_accommodation_now', array( $this, 'sync_accommodation_now' ) );
	}

	/**
	 * Tests an iCal URL to verify it works.
	 *
	 * AJAX action: af_test_ical_url
	 *
	 * @return void
	 */
	public function test_ical_url() {
		check_ajax_referer( 'af_ota_nonce', 'nonce', true );

		$platform = sanitize_key( $_POST['platform'] ?? '' );
		$ical_url = esc_url_raw( $_POST['ical_url'] ?? '' );
		$accommodation_id = absint( $_POST['accommodation_id'] ?? 0 );

		if ( empty( $platform ) || empty( $ical_url ) || ! $accommodation_id ) {
			wp_send_json_error( 'Datos incompletos' );
		}

		// Test the iCal URL by parsing it
		$result = Arriendo_Facil_iCal_Parser::parse_ical_url( $ical_url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// Return the parsed result
		wp_send_json_success( array(
			'is_occupied'  => $result['is_occupied'],
			'booked_count' => count( $result['booked_dates'] ?? array() ),
			'platform'     => $platform,
		) );
	}

	/**
	 * Manually triggers synchronization for an accommodation.
	 *
	 * AJAX action: af_sync_accommodation_now
	 *
	 * @return void
	 */
	public function sync_accommodation_now() {
		check_ajax_referer( 'af_ota_nonce', 'nonce', true );

		$accommodation_id = absint( $_POST['accommodation_id'] ?? 0 );

		if ( ! $accommodation_id ) {
			wp_send_json_error( 'ID de acomodación inválido' );
		}

		// Check user can edit this accommodation
		$post = get_post( $accommodation_id );
		if ( ! $post || 'accommodation' !== $post->post_type ) {
			wp_send_json_error( 'Acomodación no encontrada' );
		}

		// For owners, only allow syncing their own accommodations
		if ( current_user_can( 'edit_post', $accommodation_id ) ) {
			// User can sync this accommodation
		} else {
			wp_send_json_error( 'No tienes permisos para sincronizar esta acomodación' );
		}

		// Perform the sync
		$manager = new Arriendo_Facil_OTA_Sync_Manager();
		$result = $manager->sync_accommodation( $accommodation_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array(
			'message' => 'Sincronización completada',
			'results' => $result,
		) );
	}
}

new Arriendo_Facil_OTA_AJAX_Handlers();
