<?php
/**
 * OTA Integration AJAX Handlers
 *
 * Handles AJAX requests for OTA settings, testing, and synchronization.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_OTA_Handlers
 *
 * AJAX handlers for OTA operations.
 */
class Arriendo_Facil_OTA_Handlers {

	/**
	 * Constructor - registers AJAX actions.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_test_ota_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'wp_ajax_af_disconnect_ota', array( $this, 'handle_disconnect' ) );
		add_action( 'wp_ajax_af_sync_accommodation_manual', array( $this, 'handle_manual_sync' ) );
		add_action( 'admin_post_af_save_ota_credentials', array( $this, 'handle_save_credentials' ) );
	}

	/**
	 * Tests connection to an OTA platform.
	 *
	 * AJAX: af_test_ota_connection
	 * POST params: platform, api_key, account_id, nonce
	 *
	 * @return void Sends JSON response.
	 */
	public function handle_test_connection() {
		// Verify nonce
		check_ajax_referer( 'af_test_connection', 'nonce' );

		// Get current user
		$current_user_id = get_current_user_id();
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		// Get parameters
		$platform = sanitize_key( $_POST['platform'] ?? '' );
		$api_key = sanitize_text_field( $_POST['api_key'] ?? '' );
		$account_id = sanitize_text_field( $_POST['account_id'] ?? '' );

		// Validate parameters
		if ( ! in_array( $platform, array( 'booking', 'airbnb' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid platform' ), 400 );
		}

		if ( ! $api_key || ! $account_id ) {
			wp_send_json_error( array( 'message' => 'Missing credentials' ), 400 );
		}

		try {
			// Get appropriate client
			$client = $this->get_client( $platform, $api_key, $account_id );

			if ( is_wp_error( $client ) ) {
				wp_send_json_error(
					array( 'message' => $client->get_error_message() ),
					400
				);
			}

			// Test credentials by validating them
			$result = $client->validate_credentials();

			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array( 'message' => $result->get_error_message() ),
					400
				);
			}

			// Success - save credentials
			Arriendo_Facil_OTA_Credentials::save_encrypted( $current_user_id, $platform, $api_key, $account_id );
			Arriendo_Facil_OTA_Credentials::mark_verified( $current_user_id, $platform );

			wp_send_json_success( array(
				'message' => sprintf(
					__( 'Conexión exitosa con %s', 'arriendo-facil' ),
					ucfirst( $platform )
				),
			) );

		} catch ( Exception $e ) {
			wp_send_json_error(
				array( 'message' => $e->getMessage() ),
				500
			);
		}
	}

	/**
	 * Disconnects OTA credentials.
	 *
	 * AJAX: af_disconnect_ota
	 * POST params: platform, nonce
	 *
	 * @return void Sends JSON response.
	 */
	public function handle_disconnect() {
		// Verify nonce
		check_ajax_referer( 'af_disconnect_ota', 'nonce' );

		// Get current user
		$current_user_id = get_current_user_id();
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		// Get platform
		$platform = sanitize_key( $_POST['platform'] ?? '' );

		if ( ! in_array( $platform, array( 'booking', 'airbnb' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid platform' ), 400 );
		}

		// Disconnect
		Arriendo_Facil_OTA_Credentials::delete_credentials( $current_user_id, $platform );

		wp_send_json_success( array(
			'message' => sprintf(
				__( '%s desconectado', 'arriendo-facil' ),
				ucfirst( $platform )
			),
		) );
	}

	/**
	 * Manually triggers sync for an accommodation.
	 *
	 * AJAX: af_sync_accommodation_manual
	 * POST params: accommodation_id, nonce
	 *
	 * @return void Sends JSON response.
	 */
	public function handle_manual_sync() {
		// Verify nonce
		check_ajax_referer( 'af_sync_accommodation_now', 'nonce' );

		// Get current user
		$current_user_id = get_current_user_id();
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		// Get accommodation ID
		$accommodation_id = absint( $_POST['accommodation_id'] ?? 0 );

		if ( ! $accommodation_id ) {
			wp_send_json_error( array( 'message' => 'Invalid accommodation ID' ), 400 );
		}

		// Verify ownership
		$post = get_post( $accommodation_id );
		if ( ! $post || 'accommodation' !== $post->post_type ) {
			wp_send_json_error( array( 'message' => 'Accommodation not found' ), 404 );
		}

		// Check ownership (owners can only sync their own)
		if ( ! current_user_can( 'manage_options' ) ) {
			$owner_id = (int) get_post_meta( $accommodation_id, '_af_owner_id', true );
			if ( $owner_id !== $current_user_id ) {
				wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
			}
		}

		try {
			// Trigger sync
			$manager = new Arriendo_Facil_OTA_Sync_Manager();
			$result = $manager->sync_accommodation( $accommodation_id );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array( 'message' => $result->get_error_message() ),
					400
				);
			}

			// Get updated timestamp
			$last_sync = get_post_meta( $accommodation_id, '_af_last_sync_timestamp', true );

			wp_send_json_success( array(
				'message' => __( 'Sincronización completada', 'arriendo-facil' ),
				'timestamp' => $last_sync ? wp_date( 'd/m/Y H:i', (int) $last_sync ) : '',
				'result' => $result,
			) );

		} catch ( Exception $e ) {
			wp_send_json_error(
				array( 'message' => $e->getMessage() ),
				500
			);
		}
	}

	/**
	 * Handles credential save form submission.
	 *
	 * POST action: af_save_ota_credentials
	 * POST params: platform, api_key/access_token, partner_id/account_id, nonce
	 *
	 * @return void Redirects back with message.
	 */
	public function handle_save_credentials() {
		// Verify nonce
		if ( ! isset( $_POST['af_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['af_nonce'] ) ), 'af_ota_credentials_nonce' ) ) {
			wp_die( 'Security check failed' );
		}

		// Get current user
		$current_user_id = get_current_user_id();
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Unauthorized' );
		}

		// Get parameters
		$platform = sanitize_key( $_POST['platform'] ?? '' );

		if ( ! in_array( $platform, array( 'booking', 'airbnb' ), true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=af-ota-integrations&error=invalid_platform' ) );
			exit;
		}

		// Get credentials based on platform
		if ( 'booking' === $platform ) {
			$api_key = sanitize_text_field( $_POST['booking_api_key'] ?? '' );
			$account_id = sanitize_text_field( $_POST['booking_partner_id'] ?? '' );
		} else {
			$api_key = sanitize_text_field( $_POST['airbnb_api_key'] ?? '' );
			$account_id = sanitize_text_field( $_POST['airbnb_account_id'] ?? '' );
		}

		if ( ! $api_key || ! $account_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=af-ota-integrations&error=missing_fields' ) );
			exit;
		}

		// Save credentials
		$saved = Arriendo_Facil_OTA_Credentials::save_encrypted( $current_user_id, $platform, $api_key, $account_id );

		if ( ! $saved ) {
			wp_safe_redirect( admin_url( 'admin.php?page=af-ota-integrations&error=save_failed' ) );
			exit;
		}

		// Redirect with success message
		wp_safe_redirect( admin_url( 'admin.php?page=af-ota-integrations&updated=true&platform=' . $platform ) );
		exit;
	}

	/**
	 * Gets an OTA client instance.
	 *
	 * @param string $platform OTA platform.
	 * @param string $api_key API key/token.
	 * @param string $account_id Account identifier.
	 * @return Arriendo_Facil_OTA_API_Client_Base|WP_Error Client or error.
	 */
	private function get_client( $platform, $api_key, $account_id ) {
		try {
			switch ( $platform ) {
				case 'booking':
					return new Arriendo_Facil_Booking_API_Client( $api_key, $account_id );

				case 'airbnb':
					return new Arriendo_Facil_Airbnb_API_Client( $api_key, $account_id );

				default:
					return new WP_Error( 'unknown_platform', "Unknown platform: {$platform}" );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'client_error', $e->getMessage() );
		}
	}
}
