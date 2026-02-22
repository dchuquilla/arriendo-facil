<?php
/**
 * Lease management.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Lease
 *
 * Manages lease records stored in the af_leases table and provides
 * AJAX endpoints for creating and updating leases.
 */
class Arriendo_Facil_Lease {

	/**
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_create_lease', array( $this, 'ajax_create_lease' ) );
		add_action( 'wp_ajax_af_update_lease', array( $this, 'ajax_update_lease' ) );
		add_action( 'wp_ajax_af_get_leases', array( $this, 'ajax_get_leases' ) );
	}

	/**
	 * Creates a new lease record via AJAX.
	 */
	public function ajax_create_lease() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( $_POST['accommodation_id'] ) : 0;
		$guest_id         = isset( $_POST['guest_id'] ) ? absint( $_POST['guest_id'] ) : 0;
		$start_date       = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date         = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$monthly_rent     = isset( $_POST['monthly_rent'] ) ? floatval( wp_unslash( $_POST['monthly_rent'] ) ) : 0.0;

		if ( ! $accommodation_id || ! $guest_id || ! $start_date || ! $end_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'af_leases',
			array(
				'accommodation_id' => $accommodation_id,
				'guest_id'         => $guest_id,
				'start_date'       => $start_date,
				'end_date'         => $end_date,
				'monthly_rent'     => $monthly_rent,
				'status'           => 'draft',
			),
			array( '%d', '%d', '%s', '%s', '%f', '%s' )
		);

		if ( $inserted ) {
			wp_send_json_success( array( 'id' => $wpdb->insert_id ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not create lease.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Updates an existing lease via AJAX.
	 */
	public function ajax_update_lease() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		$allowed_statuses = array( 'draft', 'active', 'expired', 'terminated' );
		if ( ! $lease_id || ! in_array( $status, $allowed_statuses, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'af_leases',
			array( 'status' => $status ),
			array( 'id' => $lease_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not update lease.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Returns leases for a given accommodation via AJAX.
	 */
	public function ajax_get_leases() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_GET['accommodation_id'] ) ? absint( $_GET['accommodation_id'] ) : 0;

		global $wpdb;
		$leases = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_leases WHERE accommodation_id = %d ORDER BY start_date DESC",
				$accommodation_id
			)
		);

		wp_send_json_success( $leases );
	}

	/**
	 * Returns a single lease by ID.
	 *
	 * @param int $lease_id Lease ID.
	 * @return object|null Lease object or null.
	 */
	public function get_lease( $lease_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_leases WHERE id = %d",
				$lease_id
			)
		);
	}

	/**
	 * Attaches a generated document URL to a lease.
	 *
	 * @param int    $lease_id     Lease ID.
	 * @param string $document_url URL of the generated document.
	 * @return bool True on success.
	 */
	public function attach_document( $lease_id, $document_url ) {
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . 'af_leases',
			array( 'document_url' => esc_url_raw( $document_url ) ),
			array( 'id' => absint( $lease_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
