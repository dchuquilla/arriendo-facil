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

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'accommodation_id' => $accommodation_id,
				'lease_id'         => isset( $data['lease_id'] ) ? absint( $data['lease_id'] ) : null,
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
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
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

		$updated = $wpdb->update( self::table(), $data, array( 'id' => $request_id ), $format, array( '%d' ) );

		if ( false === $updated ) {
			return new WP_Error( 'af_maintenance_update_failed', __( 'No se pudo actualizar la incidencia.', 'arriendo-facil' ) );
		}

		return true;
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
