<?php
/**
 * Internal post-contract operations.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps legal tracking separate from the lease lifecycle.
 */
class Arriendo_Facil_Lease_Operations {

	public function __construct() {
		add_action( 'wp_ajax_af_update_lease_legal_status', array( $this, 'ajax_update_legal_status' ) );
	}

	/**
	 * Legal states are deliberately independent from commercial contract status.
	 *
	 * @return array<string,string>
	 */
	public static function legal_statuses() {
		return array(
			'pendiente'       => __( 'Pendiente', 'arriendo-facil' ),
			'en_notarizacion' => __( 'En proceso de notarizacion', 'arriendo-facil' ),
			'notarizado'      => __( 'Notarizado', 'arriendo-facil' ),
		);
	}

	public function ajax_update_legal_status() {
		check_ajax_referer( 'af_lease_operations_nonce', 'nonce' );
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		if ( ! Arriendo_Facil_Tenancy::can_access_lease( $lease_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este contrato.', 'arriendo-facil' ) ), 403 );
		}

		$status   = isset( $_POST['legal_status'] ) ? sanitize_key( wp_unslash( $_POST['legal_status'] ) ) : '';
		$notes    = isset( $_POST['legal_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['legal_notes'] ) ) : '';
		if ( ! $lease_id || ! array_key_exists( $status, self::legal_statuses() ) ) {
			wp_send_json_error( array( 'message' => __( 'Datos legales invalidos.', 'arriendo-facil' ) ), 400 );
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'af_leases',
			array( 'legal_status' => $status, 'legal_notes' => $notes, 'legal_updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $lease_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo actualizar el estado legal.', 'arriendo-facil' ) ), 500 );
		}
		wp_send_json_success( array( 'message' => __( 'Estado legal actualizado.', 'arriendo-facil' ) ) );
	}
}
