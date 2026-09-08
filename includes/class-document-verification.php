<?php
/**
 * Tenant document verification.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Document_Verification
 *
 * Tracks the review state of the identity documents a tenant uploads through
 * the secure onboarding link, so operations can confirm identity before a
 * lease is administered.
 */
class Arriendo_Facil_Document_Verification {

	/**
	 * Hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_set_document_status', array( $this, 'ajax_set_document_status' ) );
	}

	/**
	 * Supported verification states.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'pendiente'  => __( 'Pendiente', 'arriendo-facil' ),
			'verificado' => __( 'Verificado', 'arriendo-facil' ),
			'rechazado'  => __( 'Rechazado', 'arriendo-facil' ),
		);
	}

	/**
	 * Updates the verification state of a tenant's documents.
	 *
	 * @param int    $guest_id Guest ID.
	 * @param string $status   New status.
	 * @param string $notes    Optional reviewer notes.
	 * @return true|WP_Error
	 */
	public static function set_status( $guest_id, $status, $notes = '' ) {
		global $wpdb;

		$guest_id = absint( $guest_id );
		$status   = sanitize_key( (string) $status );

		if ( ! $guest_id ) {
			return new WP_Error( 'af_doc_guest_invalid', __( 'Inquilino invalido.', 'arriendo-facil' ) );
		}

		if ( ! array_key_exists( $status, self::statuses() ) ) {
			return new WP_Error( 'af_doc_status_invalid', __( 'Estado de verificacion invalido.', 'arriendo-facil' ) );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'af_guests',
			array(
				'doc_status'      => $status,
				'doc_verified_by' => get_current_user_id(),
				'doc_verified_at' => current_time( 'mysql', true ),
				'doc_notes'       => sanitize_textarea_field( (string) $notes ),
			),
			array( 'id' => $guest_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'af_doc_update_failed', __( 'No se pudo actualizar el estado de verificacion.', 'arriendo-facil' ) );
		}

		return true;
	}

	/**
	 * Returns the verification state for a tenant.
	 *
	 * @param int $guest_id Guest ID.
	 * @return string
	 */
	public static function get_status( $guest_id ) {
		global $wpdb;

		$guest_id = absint( $guest_id );
		if ( ! $guest_id ) {
			return 'pendiente';
		}

		$status = $wpdb->get_var(
			$wpdb->prepare( "SELECT doc_status FROM {$wpdb->prefix}af_guests WHERE id = %d", $guest_id )
		);

		return $status ? (string) $status : 'pendiente';
	}

	/**
	 * Whether a tenant's identity documents are verified.
	 *
	 * @param int $guest_id Guest ID.
	 * @return bool
	 */
	public static function is_verified( $guest_id ) {
		return 'verificado' === self::get_status( $guest_id );
	}

	/**
	 * AJAX: sets the verification state for a tenant.
	 *
	 * @return void
	 */
	public function ajax_set_document_status() {
		check_ajax_referer( 'af_document_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$result = self::set_status(
			isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0,
			isset( $_POST['doc_status'] ) ? sanitize_key( wp_unslash( $_POST['doc_status'] ) ) : '',
			isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : ''
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Estado de verificacion actualizado.', 'arriendo-facil' ) ) );
	}
}
