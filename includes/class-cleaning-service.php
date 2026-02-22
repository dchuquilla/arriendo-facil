<?php
/**
 * Cleaning Service Custom Post Type and request management.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Cleaning_Service
 *
 * Registers the 'cleaning_service' CPT and manages cleaning requests
 * stored in the af_cleaning_requests table.
 */
class Arriendo_Facil_Cleaning_Service {

	/**
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_cleaning_service', array( $this, 'save_meta' ) );
		add_action( 'wp_ajax_af_create_cleaning_request', array( $this, 'ajax_create_request' ) );
		add_action( 'wp_ajax_af_update_cleaning_request', array( $this, 'ajax_update_request' ) );
	}

	/**
	 * Registers the cleaning_service CPT.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Cleaning Services', 'arriendo-facil' ),
			'singular_name'      => __( 'Cleaning Service', 'arriendo-facil' ),
			'menu_name'          => __( 'Cleaning Services', 'arriendo-facil' ),
			'add_new'            => __( 'Add New', 'arriendo-facil' ),
			'add_new_item'       => __( 'Add New Cleaning Service', 'arriendo-facil' ),
			'edit_item'          => __( 'Edit Cleaning Service', 'arriendo-facil' ),
			'new_item'           => __( 'New Cleaning Service', 'arriendo-facil' ),
			'view_item'          => __( 'View Cleaning Service', 'arriendo-facil' ),
			'search_items'       => __( 'Search Cleaning Services', 'arriendo-facil' ),
			'not_found'          => __( 'No cleaning services found', 'arriendo-facil' ),
			'not_found_in_trash' => __( 'No cleaning services found in Trash', 'arriendo-facil' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'arriendo-facil',
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor' ),
			'show_in_rest'       => true,
		);

		register_post_type( 'cleaning_service', $args );
	}

	/**
	 * Adds meta boxes for cleaning service details.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'af_cleaning_service_details',
			__( 'Cleaning Service Details', 'arriendo-facil' ),
			array( $this, 'render_meta_box' ),
			'cleaning_service',
			'normal',
			'high'
		);
	}

	/**
	 * Renders the cleaning service details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'af_save_cleaning_service_meta', 'af_cleaning_service_nonce' );

		$price_per_hour = get_post_meta( $post->ID, '_af_price_per_hour', true );
		$duration_hours = get_post_meta( $post->ID, '_af_duration_hours', true );
		$service_type   = get_post_meta( $post->ID, '_af_service_type', true );

		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/cleaning-service-meta-box.php';
	}

	/**
	 * Saves the cleaning service meta data.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['af_cleaning_service_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['af_cleaning_service_nonce'] ) ), 'af_save_cleaning_service_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['af_price_per_hour'] ) ) {
			update_post_meta( $post_id, '_af_price_per_hour', floatval( wp_unslash( $_POST['af_price_per_hour'] ) ) );
		}
		if ( isset( $_POST['af_duration_hours'] ) ) {
			update_post_meta( $post_id, '_af_duration_hours', floatval( wp_unslash( $_POST['af_duration_hours'] ) ) );
		}
		if ( isset( $_POST['af_service_type'] ) ) {
			update_post_meta( $post_id, '_af_service_type', sanitize_text_field( wp_unslash( $_POST['af_service_type'] ) ) );
		}
	}

	/**
	 * Creates a new cleaning request via AJAX.
	 */
	public function ajax_create_request() {
		check_ajax_referer( 'af_cleaning_request_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( $_POST['accommodation_id'] ) : 0;
		$requested_date   = isset( $_POST['requested_date'] ) ? sanitize_text_field( wp_unslash( $_POST['requested_date'] ) ) : '';
		$notes            = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( ! $accommodation_id || ! $requested_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'af_cleaning_requests',
			array(
				'accommodation_id' => $accommodation_id,
				'requested_date'   => $requested_date,
				'notes'            => $notes,
				'status'           => 'pending',
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			wp_send_json_success( array( 'id' => $wpdb->insert_id ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not create cleaning request.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Updates a cleaning request status via AJAX.
	 */
	public function ajax_update_request() {
		check_ajax_referer( 'af_cleaning_request_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		$status     = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		$allowed_statuses = array( 'pending', 'in_progress', 'completed', 'cancelled' );
		if ( ! $request_id || ! in_array( $status, $allowed_statuses, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$data = array( 'status' => $status );
		if ( 'completed' === $status ) {
			$data['completed_date'] = current_time( 'Y-m-d' );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'af_cleaning_requests',
			$data,
			array( 'id' => $request_id ),
			array_fill( 0, count( $data ), '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not update cleaning request.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Returns cleaning requests for a given accommodation.
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return array Array of request objects.
	 */
	public function get_requests_for_accommodation( $accommodation_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_cleaning_requests WHERE accommodation_id = %d ORDER BY requested_date DESC",
				$accommodation_id
			)
		);
	}
}
