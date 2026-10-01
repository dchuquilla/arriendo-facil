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
		add_action( 'wp_ajax_af_update_maintenance', array( $this, 'ajax_update_request' ) );
	}

	/**
	 * Builds the SELECT shared by the admin list and the AJAX detail panel.
	 *
	 * @param string $where_sql Already-built WHERE clause (no keyword).
	 * @param array  $args      Placeholder values.
	 * @return string
	 */
	private static function select_with_context( $where_sql, $args = array() ) {
		global $wpdb;

		$sql = "SELECT r.*, p.post_title AS accommodation_title, u.unit_code,
				s.name  AS provider_name,
				s.trade AS provider_trade,
				s.phone AS provider_phone,
				s.whatsapp AS provider_whatsapp
			FROM " . self::table() . " r
			LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
			LEFT JOIN {$wpdb->prefix}af_units u ON u.id = r.unit_id
			LEFT JOIN {$wpdb->prefix}af_service_providers s ON s.id = r.provider_id
			WHERE {$where_sql}
			ORDER BY FIELD(r.priority, 'alta', 'media', 'baja'),
				COALESCE(r.scheduled_date, r.requested_date) DESC";

		return empty( $args ) ? $sql : $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns the maintenance requests visible to the current user, with the
	 * accommodation title, unit code and assigned provider joined in.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function get_requests_for_current_user( $limit = 300 ) {
		global $wpdb;

		$ids = Arriendo_Facil_Tenancy::accessible_accommodation_ids();

		if ( null !== $ids ) {
			if ( empty( $ids ) ) {
				return array();
			}
			$where = 'r.accommodation_id IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')';
		} else {
			$where = '1=1';
		}

		$sql    = self::select_with_context( $where ) . ' LIMIT ' . absint( $limit );
		$rows   = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (array) $rows;
	}

	/**
	 * Returns a single request with its context, honouring tenancy.
	 *
	 * @param int $request_id Request ID.
	 * @return object|null
	 */
	public static function get_request( $request_id ) {
		global $wpdb;

		$request_id = absint( $request_id );
		if ( ! $request_id ) {
			return null;
		}

		$row = $wpdb->get_row( self::select_with_context( 'r.id = %d', array( $request_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $row ? $row : null;
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
	 * Asset categories (activos a reparar).
	 *
	 * @return array<string,string>
	 */
	public static function asset_categories() {
		return array(
			'inmueble'       => __( 'Inmueble', 'arriendo-facil' ),
			'instalacion'    => __( 'Instalación (agua/luz/gas)', 'arriendo-facil' ),
			'electrodomestico' => __( 'Electrodoméstico', 'arriendo-facil' ),
			'mueble'         => __( 'Mueble', 'arriendo-facil' ),
			'puerta_ventana' => __( 'Puerta/Ventana', 'arriendo-facil' ),
			'jardin'         => __( 'Jardín', 'arriendo-facil' ),
			'seguridad'      => __( 'Seguridad', 'arriendo-facil' ),
			'otro'           => __( 'Otro', 'arriendo-facil' ),
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
		$category = isset( $data['asset_category'] ) ? sanitize_key( (string) $data['asset_category'] ) : '';

		// Auto-resolve the active lease for the accommodation when not provided,
		// so the final cost can be anchored to the correct garantia.
		$lease_id = isset( $data['lease_id'] ) ? absint( $data['lease_id'] ) : 0;
		if ( ! $lease_id && class_exists( 'Arriendo_Facil_Lease' ) ) {
			$lease_id = (int) Arriendo_Facil_Lease::get_active_lease_id_for_accommodation( $accommodation_id );
		}

		$provider_id = isset( $data['provider_id'] ) ? absint( $data['provider_id'] ) : 0;
		if ( $provider_id && ! self::can_access_provider( $provider_id ) ) {
			$provider_id = 0;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'accommodation_id' => $accommodation_id,
				'unit_id'          => isset( $data['unit_id'] ) ? absint( $data['unit_id'] ) : null,
				'lease_id'         => $lease_id ? $lease_id : null,
				'provider_id'      => $provider_id ? $provider_id : null,
				'requested_date'   => isset( $data['requested_date'] ) && $data['requested_date']
					? sanitize_text_field( (string) $data['requested_date'] )
					: gmdate( 'Y-m-d' ),
				'scheduled_date'   => isset( $data['scheduled_date'] ) && $data['scheduled_date']
					? sanitize_text_field( (string) $data['scheduled_date'] )
					: null,
				'request_type'     => array_key_exists( $type, self::types() ) ? $type : 'otro',
				'priority'         => array_key_exists( $priority, self::priorities() ) ? $priority : 'media',
				'reported_by'      => array_key_exists( $reporter, self::reporters() ) ? $reporter : 'operador',
				'asset_category'   => array_key_exists( $category, self::asset_categories() ) ? $category : '',
				'asset_name'       => isset( $data['asset_name'] ) ? sanitize_text_field( (string) $data['asset_name'] ) : null,
				'damage_details'   => isset( $data['damage_details'] ) ? sanitize_textarea_field( (string) $data['damage_details'] ) : null,
				'asset_specs'      => isset( $data['asset_specs'] ) ? sanitize_text_field( (string) $data['asset_specs'] ) : null,
				'asset_location'   => isset( $data['asset_location'] ) ? sanitize_text_field( (string) $data['asset_location'] ) : null,
				'contact_name'     => isset( $data['contact_name'] ) && $data['contact_name']
					? sanitize_text_field( (string) $data['contact_name'] )
					: null,
				'contact_phone'    => isset( $data['contact_phone'] ) && $data['contact_phone']
					? sanitize_text_field( (string) $data['contact_phone'] )
					: null,
				'cost'             => isset( $data['cost'] ) ? round( (float) $data['cost'], 2 ) : 0.0,
				'notes'            => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : null,
				'status'           => 'pending',
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_maintenance_insert_failed', __( 'No se pudo registrar la incidencia.', 'arriendo-facil' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Whether the current user may assign a given provider to a request.
	 *
	 * @param int $provider_id Provider ID.
	 * @return bool
	 */
	private static function can_access_provider( $provider_id ) {
		if ( ! class_exists( 'Arriendo_Facil_Tenancy' ) ) {
			return false;
		}

		return (bool) Arriendo_Facil_Tenancy::can_access_service_provider( $provider_id );
	}

	/**
	 * Updates the editable fields of a maintenance request.
	 *
	 * Only whitelisted columns are written, so a crafted payload cannot touch
	 * accommodation_id, cost settlement fields or timestamps.
	 *
	 * @param int                 $request_id Request ID.
	 * @param array<string,mixed> $data       Field map.
	 * @return true|WP_Error
	 */
	public static function update( $request_id, array $data ) {
		global $wpdb;

		$request_id = absint( $request_id );
		if ( ! $request_id ) {
			return new WP_Error( 'af_maintenance_not_found', __( 'Incidencia no encontrada.', 'arriendo-facil' ) );
		}

		$updates = array();
		$format  = array();

		if ( isset( $data['asset_name'] ) ) {
			$updates['asset_name']     = sanitize_text_field( (string) $data['asset_name'] );
			$format[]                  = '%s';
		}
		if ( isset( $data['damage_details'] ) ) {
			$updates['damage_details'] = sanitize_textarea_field( (string) $data['damage_details'] );
			$format[]                  = '%s';
		}
		if ( isset( $data['asset_specs'] ) ) {
			$updates['asset_specs']    = sanitize_text_field( (string) $data['asset_specs'] );
			$format[]                  = '%s';
		}
		if ( isset( $data['asset_location'] ) ) {
			$updates['asset_location'] = sanitize_text_field( (string) $data['asset_location'] );
			$format[]                  = '%s';
		}
		if ( isset( $data['asset_category'] ) ) {
			$category = sanitize_key( (string) $data['asset_category'] );
			$updates['asset_category'] = array_key_exists( $category, self::asset_categories() ) ? $category : '';
			$format[]                  = '%s';
		}
		if ( isset( $data['scheduled_date'] ) ) {
			$updates['scheduled_date'] = $data['scheduled_date']
				? sanitize_text_field( (string) $data['scheduled_date'] )
				: null;
			$format[] = '%s';
		}
		if ( isset( $data['contact_name'] ) ) {
			$updates['contact_name'] = $data['contact_name']
				? sanitize_text_field( (string) $data['contact_name'] )
				: null;
			$format[] = '%s';
		}
		if ( isset( $data['contact_phone'] ) ) {
			$updates['contact_phone'] = $data['contact_phone']
				? sanitize_text_field( (string) $data['contact_phone'] )
				: null;
			$format[] = '%s';
		}
		if ( isset( $data['provider_id'] ) ) {
			$provider_id = absint( $data['provider_id'] );
			if ( $provider_id && ! self::can_access_provider( $provider_id ) ) {
				return new WP_Error( 'af_maintenance_provider_denied', __( 'Ese contacto no está en tu catálogo.', 'arriendo-facil' ) );
			}
			$updates['provider_id'] = $provider_id ? $provider_id : null;
			$format[]              = '%d';
		}
		if ( isset( $data['priority'] ) ) {
			$priority = sanitize_key( (string) $data['priority'] );
			$updates['priority'] = array_key_exists( $priority, self::priorities() ) ? $priority : 'media';
			$format[]            = '%s';
		}
		if ( isset( $data['notes'] ) ) {
			$updates['notes'] = sanitize_textarea_field( (string) $data['notes'] );
			$format[]          = '%s';
		}

		if ( empty( $updates ) ) {
			return true;
		}

		$updated = $wpdb->update( self::table(), $updates, array( 'id' => $request_id ), $format, array( '%d' ) );

		if ( false === $updated ) {
			return new WP_Error( 'af_maintenance_update_failed', __( 'No se pudo actualizar la incidencia.', 'arriendo-facil' ) );
		}

		return true;
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
				'scheduled_date'   => isset( $_POST['scheduled_date'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_date'] ) ) : '',
				'asset_category'   => isset( $_POST['asset_category'] ) ? sanitize_key( wp_unslash( $_POST['asset_category'] ) ) : '',
				'asset_name'       => isset( $_POST['asset_name'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_name'] ) ) : '',
				'damage_details'   => isset( $_POST['damage_details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['damage_details'] ) ) : '',
				'asset_specs'      => isset( $_POST['asset_specs'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_specs'] ) ) : '',
				'asset_location'   => isset( $_POST['asset_location'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_location'] ) ) : '',
				'provider_id'      => isset( $_POST['provider_id'] ) ? absint( wp_unslash( $_POST['provider_id'] ) ) : 0,
				'contact_name'     => isset( $_POST['contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_name'] ) ) : '',
				'contact_phone'    => isset( $_POST['contact_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_phone'] ) ) : '',
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

	/**
	 * AJAX: updates the editable fields of a request (asset, damage, specs,
	 * scheduled date, assigned provider or ad-hoc contact).
	 *
	 * @return void
	 */
	public function ajax_update_request() {
		check_ajax_referer( 'af_maintenance_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$request_id = isset( $_POST['request_id'] ) ? absint( wp_unslash( $_POST['request_id'] ) ) : 0;
		if ( ! Arriendo_Facil_Tenancy::can_access_maintenance( $request_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a esta incidencia.', 'arriendo-facil' ) ), 403 );
		}

		// Only the whitelisted fields are forwarded; update() ignores anything else.
		$fields = array(
			'asset_name',
			'asset_category',
			'damage_details',
			'asset_specs',
			'asset_location',
			'scheduled_date',
			'contact_name',
			'contact_phone',
			'notes',
			'priority',
		);

		$payload = array();
		foreach ( $fields as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$value             = wp_unslash( $_POST[ $field ] );
			$payload[ $field ] = is_string( $value ) ? $value : '';
		}

		if ( isset( $_POST['provider_id'] ) ) {
			$payload['provider_id'] = absint( wp_unslash( $_POST['provider_id'] ) );
		}

		$result = self::update( $request_id, $payload );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Solicitud actualizada.', 'arriendo-facil' ) ) );
	}
}
