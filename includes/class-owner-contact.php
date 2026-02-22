<?php
/**
 * Owner contact management.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Owner_Contact
 *
 * Handles contact messages directed to property owners stored in
 * the af_owner_contacts table.
 */
class Arriendo_Facil_Owner_Contact {

	/**
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_send_owner_contact', array( $this, 'ajax_send_contact' ) );
		add_action( 'wp_ajax_nopriv_af_send_owner_contact', array( $this, 'ajax_send_contact' ) );
		add_action( 'wp_ajax_af_get_owner_contacts', array( $this, 'ajax_get_contacts' ) );
		add_action( 'wp_ajax_af_mark_contact_read', array( $this, 'ajax_mark_read' ) );
	}

	/**
	 * Handles sending a contact message to an owner via AJAX.
	 */
	public function ajax_send_contact() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		$owner_id = isset( $_POST['owner_id'] ) ? absint( $_POST['owner_id'] ) : 0;
		$subject  = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$message  = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( ! $owner_id || ! $subject || ! $message ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'af_owner_contacts',
			array(
				'owner_id' => $owner_id,
				'subject'  => $subject,
				'message'  => $message,
				'status'   => 'unread',
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			$owner = get_userdata( $owner_id );
			if ( $owner ) {
				wp_mail(
					$owner->user_email,
					$subject,
					$message
				);
			}
			wp_send_json_success( array( 'id' => $wpdb->insert_id ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not send message.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Returns all contacts for the current owner via AJAX.
	 */
	public function ajax_get_contacts() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$owner_id = isset( $_GET['owner_id'] ) ? absint( $_GET['owner_id'] ) : get_current_user_id();

		global $wpdb;
		$contacts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_owner_contacts WHERE owner_id = %d ORDER BY created_at DESC",
				$owner_id
			)
		);

		wp_send_json_success( $contacts );
	}

	/**
	 * Marks a contact message as read via AJAX.
	 */
	public function ajax_mark_read() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
		if ( ! $contact_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid contact ID.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'af_owner_contacts',
			array( 'status' => 'read' ),
			array( 'id' => $contact_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not mark as read.', 'arriendo-facil' ) ) );
		}
	}
}
