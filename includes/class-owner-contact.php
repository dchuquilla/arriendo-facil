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

		$redirect_to = isset( $_POST['redirect_to'] )
			? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
			: admin_url( 'admin.php?page=af-owner-contacts' );

		$is_xhr = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] )
			&& 'xmlhttprequest' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) );

		$owner_id = isset( $_POST['owner_id'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['owner_id'] ) ): '';
		$subject  = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$message  = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		$is_owner_id_valid = (bool) preg_match( '/^[0-9]{10,13}$/', $owner_id );

		if ( ! $is_owner_id_valid || ! $subject || ! $message ) {
			if ( $is_xhr ) {
				wp_send_json_error( array( 'message' => __( 'Owner ID must be 10 to 13 digits.', 'arriendo-facil' ) ) );
			}
			wp_safe_redirect( $redirect_to );
			exit;
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
			array( '%s', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			$owner_email = $this->find_owner_email_by_cedula( $owner_id );
			if ( $owner_email ) {
				wp_mail( $owner_email, $subject, $message );
			}

			if ( $is_xhr ) {
				wp_send_json_success(
					array(
						'id'          => $wpdb->insert_id,
						'redirect_to' => $redirect_to,
					)
				);
			}

			wp_safe_redirect( $redirect_to );
			exit;
		}

		if ( $is_xhr ) {
			wp_send_json_error( array( 'message' => __( 'Could not send message.', 'arriendo-facil' ) ) );
		}

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Returns all contacts for the current owner via AJAX.
	 */
	public function ajax_get_contacts() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$owner_id = isset( $_GET['owner_id'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_GET['owner_id'] ) ) : '';

		if ( ! preg_match( '/^[0-9]{10,13}$/', $owner_id ) ) {
			wp_send_json_success( array() );
		}

		global $wpdb;
		$contacts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_owner_contacts WHERE owner_id = %s ORDER BY created_at DESC",
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

	private function find_owner_email_by_cedula( $cedula ) {
		$users = get_users(
			array(
				'number'     => 1,
				'fields'     => array( 'user_email' ),
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'   => 'cedula',
						'value' => $cedula,
					),
					array(
						'key'   => 'id_number',
						'value' => $cedula,
					),
					array(
						'key'   => 'document',
						'value' => $cedula,
					),
					array(
						'key'   => 'document_id',
						'value' => $cedula,
					),
					array(
						'key'   => 'af_cedula',
						'value' => $cedula,
					),
				),
			)
		);

		if ( ! empty( $users ) && ! empty( $users[0]->user_email ) ) {
			return sanitize_email( $users[0]->user_email );
		}

		return '';
	}
}
