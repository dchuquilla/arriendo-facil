<?php
/**
 * Maintenance and incident tracking.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Maintenance
 *
 * Tracks maintenance work and incidents reported on managed properties.
 * Backed by the af_cleaning_requests table, extended with type/priority/cost.
 */
class Arriendo_Facil_Maintenance {

	/**
	 * Hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_create_maintenance', array( $this, 'ajax_create_maintenance' ) );
		add_action( 'wp_ajax_af_update_maintenance_status', array( $this, 'ajax_update_status' ) );
	}

	/**
	 * Returns the maintenance requests table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'af_cleaning_requests';
	}

	/**
	 * Supported request types.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'limpieza'      => __( 'Limpieza', 'arriendo-facil' ),
			'reparacion'    => __( 'Reparación', 'arriendo-facil' ),
			'mantenimiento' => __( 'Mantenimiento preventivo', 'arriendo-facil' ),
			'emergencia'    => __( 'Emergencia', 'arriendo-facil' ),
			'otro'          => __( 'Otro', 'arriendo-facil' ),
		);
	}

	/**
	 * Supported priorities.
	 *
	 * @return array<string,string>
	 */
	public static function priorities() {
		return array(
			'baja'  => __( 'Baja', 'arriendo-facil' ),
			'media' => __( 'Media', 'arriendo-facil' ),
			'alta'  => __( 'Crítica', 'arriendo-facil' ),
		);
	}

	/**
	 * Supported statuses.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'pending'     => __( 'Pendiente', 'arriendo-facil' ),
			'in_progress' => __( 'En proceso', 'arriendo-facil' ),
			'completed'   => __( 'Completado', 'arriendo-facil' ),
			'cancelled'   => __( 'Cancelado', 'arriendo-facil' ),
		);
	}

	/**
	 * Who reported the incident.
	 *
	 * @return array<string,string>
	 */
	public static function reporters() {
		return array(
			'operador'    => __( 'Operador', 'arriendo-facil' ),
			'inquilino'   => __( 'Inquilino', 'arriendo-facil' ),
			'propietario' => __( 'Propietario', 'arriendo-facil' ),
		);
	}

	/**
	 * Creates a maintenance request.
	 *
	 * @param array<string,mixed> $data Request data.
	 * @return int|WP_Error Request ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$accommodation_id = isset( $data['accommodation_id'] ) ? absint( $data['accommodation_id'] ) : 0;
		if ( ! $accommodation_id ) {
			return new WP_Error( 'af_maintenance_property_required', __( 'Selecciona el inmueble.', 'arriendo-facil' ) );
		}

		$type     = isset( $data['request_type'] ) ? sanitize_key( (string) $data['request_type'] ) : 'limpieza';
		$priority = isset( $data['priority'] ) ? sanitize_key( (string) $data['priority'] ) : 'media';
		$reporter = isset( $data['reported_by'] ) ? sanitize_key( (string) $data['reported_by'] ) : 'operador';

		// Auto-resolve the active lease for the accommodation when not provided,
		// so the final cost can be anchored to the correct garantia.
		$lease_id = isset( $data['lease_id'] ) ? absint( $data['lease_id'] ) : 0;
		if ( ! $lease_id && class_exists( 'Arriendo_Facil_Lease' ) ) {
			$lease_id = (int) Arriendo_Facil_Lease::get_active_lease_id_for_accommodation( $accommodation_id );
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'accommodation_id' => $accommodation_id,
				'unit_id'          => isset( $data['unit_id'] ) ? absint( $data['unit_id'] ) : null,
				'lease_id'         => $lease_id ? $lease_id : null,
				'requested_date'   => isset( $data['requested_date'] ) && $data['requested_date']
					? sanitize_text_field( (string) $data['requested_date'] )
					: gmdate( 'Y-m-d' ),
				'request_type'     => array_key_exists( $type, self::types() ) ? $type : 'otro',
				'priority'         => array_key_exists( $priority, self::priorities() ) ? $priority : 'media',
				'reported_by'      => array_key_exists( $reporter, self::reporters() ) ? $reporter : 'operador',
				'cost'             => isset( $data['cost'] ) ? round( (float) $data['cost'], 2 ) : 0.0,
				'notes'            => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : null,
				'status'           => 'pending',
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_maintenance_insert_failed', __( 'No se pudo registrar la incidencia.', 'arriendo-facil' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates the status of a request, stamping the completion date when closed.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $status     New status.
	 * @param float  $cost       Optional final cost.
	 * @return true|WP_Error
	 */
	public static function update_status( $request_id, $status, $cost = null ) {
		global $wpdb;

		$request_id = absint( $request_id );
		$status     = sanitize_key( (string) $status );

		if ( ! $request_id || ! array_key_exists( $status, self::statuses() ) ) {
			return new WP_Error( 'af_maintenance_status_invalid', __( 'Estado invalido.', 'arriendo-facil' ) );
		}

		$data   = array( 'status' => $status );
		$format = array( '%s' );

		if ( 'completed' === $status ) {
			$data['completed_date'] = gmdate( 'Y-m-d' );
			$format[]               = '%s';
		}

		if ( null !== $cost ) {
			$data['cost'] = round( (float) $cost, 2 );
			$format[]     = '%f';
		}

		$request = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $request_id )
		);

		$updated = $wpdb->update( self::table(), $data, array( 'id' => $request_id ), $format, array( '%d' ) );

		if ( false === $updated ) {
			return new WP_Error( 'af_maintenance_update_failed', __( 'No se pudo actualizar la incidencia.', 'arriendo-facil' ) );
		}

		// On completion, any final cost is automatically charged against the
		// lease garantia (deposit.service_deduction) so it shows up in the deposit
		// settlement and the owner report.
		if ( 'completed' === $status && (float) ( $request->cost ?? 0 ) > 0 && (int) ( $request->lease_id ?? 0 ) > 0 ) {
			Arriendo_Facil_Maintenance::deduct_from_deposit( (int) $request->lease_id, round( (float) $request->cost, 2 ) );
		}

		return true;
	}

	/**
	 * Applies a maintenance cost as a service deduction on the lease garantia.
	 * Idempotent per (lease_id, maintenance_request) pair via a ledger charge
	 * of type 'multa' dated to the request period; the deposit deduction column
	 * is reconciled from those charges.
	 *
	 * @param int   $lease_id Lease ID.
	 * @param float $cost     Cost to deduct.
	 * @return void
	 */
	public static function deduct_from_deposit( $lease_id, $cost ) {
		global $wpdb;

		$lease_id = absint( $lease_id );
		$cost     = round( (float) $cost, 2 );
		if ( ! $lease_id || $cost <= 0 || ! class_exists( 'Arriendo_Facil_Billing_Ledger' ) ) {
			return;
		}

		// Achoring charge to the current period keeps the UNIQUE
		// (lease_id, charge_type, period) constraint per month.
		$prefix  = $wpdb->prefix;
		$period  = gmdate( 'Y-m' );

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$prefix}af_charges
				 WHERE lease_id = %d AND charge_type = 'multa' AND period = %s",
				$lease_id,
				$period
			)
		);
		if ( $exists > 0 ) {
			return;
		}

		Arriendo_Facil_Billing_Ledger::create_charge(
			array(
				'lease_id'    => $lease_id,
				'period'      => $period,
				'charge_type' => 'multa',
				'amount'      => $cost,
				'description' => __( 'Mantenimiento deducido de la garantia.', 'arriendo-facil' ),
				'due_date'    => gmdate( 'Y-m-d' ),
			)
		);

		// Reconcile the garantia column from all non-void multa charges.
		$total_multa = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$prefix}af_charges
				 WHERE lease_id = %d AND charge_type = 'multa' AND status != 'void'",
				$lease_id
			)
		);

		$wpdb->update(
			$prefix . 'af_leases',
			array( 'deposit_service_deduction' => round( $total_multa, 2 ) ),
			array( 'id' => $lease_id ),
			array( '%f' ),
			array( '%d' )
		);
	}

	/**
	 * AJAX: creates a maintenance request.
	 *
	 * @return void
	 */
	public function ajax_create_maintenance() {
		check_ajax_referer( 'af_maintenance_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;
		if ( ! Arriendo_Facil_Tenancy::can_access_accommodation( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este inmueble.', 'arriendo-facil' ) ), 403 );
		}

		$result = self::create(
			array(
				'accommodation_id' => $accommodation_id,
				'unit_id'          => isset( $_POST['unit_id'] ) ? absint( wp_unslash( $_POST['unit_id'] ) ) : 0,
				'request_type'     => isset( $_POST['request_type'] ) ? sanitize_key( wp_unslash( $_POST['request_type'] ) ) : '',
				'priority'         => isset( $_POST['priority'] ) ? sanitize_key( wp_unslash( $_POST['priority'] ) ) : '',
				'reported_by'      => isset( $_POST['reported_by'] ) ? sanitize_key( wp_unslash( $_POST['reported_by'] ) ) : '',
				'requested_date'   => isset( $_POST['requested_date'] ) ? sanitize_text_field( wp_unslash( $_POST['requested_date'] ) ) : '',
				'cost'             => isset( $_POST['cost'] ) ? (float) wp_unslash( $_POST['cost'] ) : 0,
				'notes'            => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Incidencia registrada.', 'arriendo-facil' ),
				'request_id' => $result,
			)
		);
	}

	/**
	 * AJAX: updates the status of a request.
	 *
	 * @return void
	 */
	public function ajax_update_status() {
		check_ajax_referer( 'af_maintenance_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$request_id = isset( $_POST['request_id'] ) ? absint( wp_unslash( $_POST['request_id'] ) ) : 0;
		if ( ! Arriendo_Facil_Tenancy::can_access_maintenance( $request_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a esta incidencia.', 'arriendo-facil' ) ), 403 );
		}

		$cost   = isset( $_POST['cost'] ) && '' !== $_POST['cost'] ? (float) wp_unslash( $_POST['cost'] ) : null;
		$result = self::update_status(
			$request_id,
			isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '',
			$cost
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Incidencia actualizada.', 'arriendo-facil' ) ) );
	}
}
