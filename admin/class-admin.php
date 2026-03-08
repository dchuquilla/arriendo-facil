<?php
/**
 * Admin interface for Arriendo Fácil.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Admin
 *
 * Sets up the top-level admin menu and sub-pages for the plugin.
 */
class Arriendo_Facil_Admin {

	/**
	 * Constructor – hooks into WordPress admin.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_af_predict_cost', array( $this, 'ajax_predict_cost' ) );
		add_action( 'wp_ajax_af_generate_document', array( $this, 'ajax_generate_document' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Registers the plugin's top-level menu and sub-pages.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Arriendo Fácil', 'arriendo-facil' ),
			__( 'Arriendo Fácil', 'arriendo-facil' ),
			'edit_posts',
			'arriendo-facil',
			array( $this, 'render_dashboard' ),
			'dashicons-building',
			30
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Dashboard', 'arriendo-facil' ),
			__( 'Dashboard', 'arriendo-facil' ),
			'edit_posts',
			'arriendo-facil',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Leases', 'arriendo-facil' ),
			__( 'Leases', 'arriendo-facil' ),
			'edit_posts',
			'af-leases',
			array( $this, 'render_leases' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Cleaning Requests', 'arriendo-facil' ),
			__( 'Cleaning Requests', 'arriendo-facil' ),
			'edit_posts',
			'af-cleaning-requests',
			array( $this, 'render_cleaning_requests' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Owner Contacts', 'arriendo-facil' ),
			__( 'Owner Contacts', 'arriendo-facil' ),
			'manage_options',
			'af-owner-contacts',
			array( $this, 'render_owner_contacts' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Guests', 'arriendo-facil' ),
			__( 'Guests', 'arriendo-facil' ),
			'edit_posts',
			'af-guests',
			array( $this, 'render_guests' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'AI Settings', 'arriendo-facil' ),
			__( 'AI Settings', 'arriendo-facil' ),
			'manage_options',
			'af-ai-settings',
			array( $this, 'render_ai_settings' )
		);
	}

	/**
	 * Enqueues plugin admin CSS and JS.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		wp_enqueue_style(
			'af-admin',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ARRIENDO_FACIL_VERSION
		);

		wp_enqueue_script(
			'af-admin',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			ARRIENDO_FACIL_VERSION,
			true
		);

		wp_localize_script(
			'af-admin',
			'afAdmin',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'leaseNonce'         => wp_create_nonce( 'af_lease_nonce' ),
				'cleaningNonce'      => wp_create_nonce( 'af_cleaning_request_nonce' ),
				'ownerContactNonce'  => wp_create_nonce( 'af_owner_contact_nonce' ),
				'guestNonce'         => wp_create_nonce( 'af_guest_nonce' ),
			)
		);
	}

	/**
	 * Registers plugin settings.
	 */
	public function register_settings() {
		register_setting( 'af_ai_settings', 'af_ai_api_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'af_ai_settings', 'af_ai_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	/**
	 * Renders the main dashboard page.
	 */
	public function render_dashboard() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Renders the leases admin page.
	 */
	public function render_leases() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/leases.php';
	}

	/**
	 * Renders the cleaning requests admin page.
	 */
	public function render_cleaning_requests() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/cleaning-requests.php';
	}

	/**
	 * Renders the owner contacts admin page.
	 */
	public function render_owner_contacts() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/owner-contacts.php';
	}

	/**
	 * Renders the guests admin page.
	 */
	public function render_guests() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/guests.php';
	}

	/**
	 * Renders the AI settings page.
	 */
	public function render_ai_settings() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/ai-settings.php';
	}

	/**
	 * AJAX handler: predict accommodation cost using AI.
	 */
	public function ajax_predict_cost() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( $_POST['accommodation_id'] ) : 0;
		if ( ! $accommodation_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid accommodation ID.', 'arriendo-facil' ) ) );
		}

		$data = array(
			'post_id'      => $accommodation_id,
			'address'      => get_post_meta( $accommodation_id, '_af_address', true ),
			'bedrooms'     => get_post_meta( $accommodation_id, '_af_bedrooms', true ),
			'bathrooms'    => get_post_meta( $accommodation_id, '_af_bathrooms', true ),
			'monthly_rent' => get_post_meta( $accommodation_id, '_af_monthly_rent', true ),
		);

		$ai       = new Arriendo_Facil_AI_Service();
		$result   = $ai->predict_cost( $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: generate a lease document using AI.
	 */
	public function ajax_generate_document() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		if ( ! $lease_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid lease ID.', 'arriendo-facil' ) ) );
		}

		$lease_obj = new Arriendo_Facil_Lease();
		$lease     = $lease_obj->get_lease( $lease_id );

		if ( ! $lease ) {
			wp_send_json_error( array( 'message' => __( 'Lease not found.', 'arriendo-facil' ) ) );
		}

		$ai     = new Arriendo_Facil_AI_Service();
		$result = $ai->generate_document( (array) $lease );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( isset( $result['document_url'] ) ) {
			$lease_obj->attach_document( $lease_id, $result['document_url'] );
		}

		wp_send_json_success( $result );
	}
}
