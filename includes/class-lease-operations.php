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
 * Keeps legal tracking and deposit settlement separate from the lease lifecycle.
 */
class Arriendo_Facil_Lease_Operations {

	public function __construct() {
		add_action( 'wp_ajax_af_update_lease_legal_status', array( $this, 'ajax_update_legal_status' ) );
		add_action( 'wp_ajax_af_save_deposit_settlement', array( $this, 'ajax_save_deposit_settlement' ) );
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

	/**
	 * Builds the refundable-deposit calculation from the current ledger balance.
	 *
	 * @param object $lease Lease row.
	 * @return array<string,float>
	 */
	public static function deposit_summary( $lease ) {
		$deposit = isset( $lease->deposit_amount ) ? (float) $lease->deposit_amount : 0.0;
		$balance = class_exists( 'Arriendo_Facil_Billing_Ledger' )
			? (float) Arriendo_Facil_Billing_Ledger::get_statement_by_lease( (int) $lease->id )['balance']
			: 0.0;
		$damages = isset( $lease->deposit_damage_deduction ) ? (float) $lease->deposit_damage_deduction : 0.0;
		$services = isset( $lease->deposit_service_deduction ) ? (float) $lease->deposit_service_deduction : 0.0;
		$refund = max( 0, round( $deposit - $balance - $damages - $services, 2 ) );

		return array(
			'deposit'  => $deposit,
			'balance'  => max( 0, $balance ),
			'damages'  => $damages,
			'services' => $services,
			'refund'   => $refund,
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

	public function ajax_save_deposit_settlement() {
		check_ajax_referer( 'af_lease_operations_nonce', 'nonce' );
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		if ( ! Arriendo_Facil_Tenancy::can_access_lease( $lease_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este contrato.', 'arriendo-facil' ) ), 403 );
		}

		$damages  = isset( $_POST['damages'] ) ? max( 0, (float) wp_unslash( $_POST['damages'] ) ) : 0.0;
		$services = isset( $_POST['services'] ) ? max( 0, (float) wp_unslash( $_POST['services'] ) ) : 0.0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'pendiente';
		$notes    = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		if ( ! $lease_id || ! in_array( $status, array( 'pendiente', 'aprobada', 'pagada' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Datos de liquidacion invalidos.', 'arriendo-facil' ) ), 400 );
		}

		$lease = ( new Arriendo_Facil_Lease() )->get_lease( $lease_id );
		if ( ! $lease ) {
			wp_send_json_error( array( 'message' => __( 'Contrato no encontrado.', 'arriendo-facil' ) ), 404 );
		}
		$lease->deposit_damage_deduction  = $damages;
		$lease->deposit_service_deduction = $services;
		$summary = self::deposit_summary( $lease );

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'af_leases',
			array(
				'deposit_damage_deduction'  => $damages,
				'deposit_service_deduction' => $services,
				'deposit_refund_amount'     => $summary['refund'],
				'deposit_settlement_status' => $status,
				'deposit_settlement_notes'  => $notes,
				'deposit_settled_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => $lease_id ),
			array( '%f', '%f', '%f', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar la liquidacion.', 'arriendo-facil' ) ), 500 );
		}
		wp_send_json_success( array( 'message' => __( 'Liquidacion de garantia guardada.', 'arriendo-facil' ), 'refund' => $summary['refund'] ) );
	}
}
