<?php
/**
 * Portal del inquilino — incidentes: reporte con fotos, responsabilidad,
 * evidencia de reparación y acuse de conformidad.
 *
 * Reglas de responsabilidad:
 *  - Primera avería de un bien: la repara el propietario/gestor.
 *  - Si el MISMO bien vuelve a fallar después de que el propietario lo reparó, el
 *    sistema marca "reincidencia" y SUGIERE que corresponde al inquilino.
 *  - El sistema solo sugiere. La decisión es del gestor, por escrito y con motivo, y
 *    queda en la bitácora visible para ambas partes.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Portal_Incidents
 */
class Arriendo_Facil_Portal_Incidents {

	const REPORTS_PER_DAY = 10;

	/**
	 * Hooks.
	 */
	public function __construct() {
		foreach ( array( 'report', 'evidence', 'ack' ) as $action ) {
			add_action( 'wp_ajax_nopriv_af_tpa_incident_' . $action, array( $this, 'ajax_' . $action ) );
			add_action( 'wp_ajax_af_tpa_incident_' . $action, array( $this, 'ajax_' . $action ) );
		}
		add_action( 'wp_ajax_nopriv_af_tpa_evidence_view', array( $this, 'ajax_evidence_view' ) );
		add_action( 'wp_ajax_af_tpa_evidence_view', array( $this, 'ajax_evidence_view' ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Reincidencia (pura, probada sin WordPress)
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Texto comparable: minúsculas, sin tildes, signos ni espacios sobrantes.
	 *
	 * @param string $text Texto escrito por una persona.
	 * @return string
	 */
	public static function normalize_asset( $text ) {
		$text = mb_strtolower( trim( (string) $text ) );
		$text = strtr( $text, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' ) );
		$text = (string) preg_replace( '/[^a-z0-9 ]+/', ' ', $text );

		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * ¿Es el mismo bien? Coincide el nombre del bien o, si no lo hay, su ubicación.
	 *
	 * @param string $name_a Nombre del bien (incidente nuevo).
	 * @param string $loc_a  Ubicación (incidente nuevo).
	 * @param string $name_b Nombre del bien (incidente previo).
	 * @param string $loc_b  Ubicación (incidente previo).
	 * @return bool
	 */
	public static function same_asset( $name_a, $loc_a, $name_b, $loc_b ) {
		$na = self::normalize_asset( $name_a );
		$nb = self::normalize_asset( $name_b );
		$la = self::normalize_asset( $loc_a );
		$lb = self::normalize_asset( $loc_b );

		if ( '' !== $na && '' !== $nb ) {
			// Mismo nombre y, si ambos dicen dónde está, también la ubicación.
			return $na === $nb && ( '' === $la || '' === $lb || $la === $lb );
		}

		return '' !== $la && $la === $lb && '' === $na && '' === $nb;
	}

	/**
	 * Incidente anterior, reparado por el propietario, del mismo bien en el mismo inmueble.
	 *
	 * @param int    $accommodation_id Inmueble.
	 * @param int    $exclude_id       Incidente nuevo.
	 * @param string $asset_name       Nombre del bien.
	 * @param string $asset_location   Ubicación.
	 * @return int ID previo o 0.
	 */
	public static function find_recurrence( $accommodation_id, $exclude_id, $asset_name, $asset_location ) {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, asset_name, asset_location FROM ' . Arriendo_Facil_Maintenance::table() . " WHERE accommodation_id = %d AND id <> %d AND status = 'completed' AND responsibility = 'owner' ORDER BY id DESC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				absint( $accommodation_id ),
				absint( $exclude_id )
			)
		);

		foreach ( $rows as $row ) {
			if ( self::same_asset( $asset_name, $asset_location, (string) $row->asset_name, (string) $row->asset_location ) ) {
				return (int) $row->id;
			}
		}

		return 0;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Lectura para el portal
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Incidentes de los contratos del inquilino, con evidencia y línea de tiempo.
	 *
	 * @param int   $guest_id  Inquilino de la sesión.
	 * @param int[] $lease_ids Sus contratos.
	 * @return array<int,object>
	 */
	public static function for_guest( $guest_id, array $lease_ids ) {
		global $wpdb;

		$lease_ids = array_values( array_filter( array_map( 'absint', $lease_ids ) ) );
		if ( empty( $lease_ids ) ) {
			return array();
		}

		$in   = implode( ',', $lease_ids );
		$rows = (array) $wpdb->get_results(
			'SELECT id, lease_id, accommodation_id, request_type, priority, status, requested_date, scheduled_date, completed_date, asset_name, asset_location, damage_details, responsibility, responsibility_at, responsibility_note, recurrence_of_id, tenant_ack, tenant_ack_at, tenant_ack_note
			 FROM ' . Arriendo_Facil_Maintenance::table() . " WHERE lease_id IN ({$in}) ORDER BY id DESC LIMIT 40" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- enteros.
		);

		foreach ( $rows as $row ) {
			$row->report_evidence = Arriendo_Facil_Portal_Evidence::for_subject( 'incident', (int) $row->id, $guest_id, 'report' );
			$row->repair_evidence = Arriendo_Facil_Portal_Evidence::for_subject( 'incident', (int) $row->id, $guest_id, 'repair' );
			$row->timeline        = Arriendo_Facil_Portal_Events::timeline( 'incident', (int) $row->id, $guest_id );
		}

		return $rows;
	}

	/**
	 * Qué le toca hacer al inquilino con un incidente.
	 *
	 * @param object $row Incidente.
	 * @return string upload_repair | confirm | none
	 */
	public static function tenant_action( $row ) {
		if ( 'tenant' === $row->responsibility && ! in_array( $row->status, array( 'completed', 'cancelled' ), true ) ) {
			return 'upload_repair';
		}
		if ( 'owner' === $row->responsibility && 'completed' === $row->status && empty( $row->tenant_ack ) && ! empty( $row->repair_evidence ) ) {
			return 'confirm';
		}

		return 'none';
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * AJAX del inquilino
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Sesión + CSRF. Termina si falla.
	 *
	 * @return int Inquilino de la sesión.
	 */
	private static function guard() {
		$session = Arriendo_Facil_Tenant_Access::current_session();
		if ( ! $session || ! Arriendo_Facil_Tenant_Access::csrf_ok( $session ) ) {
			wp_send_json_error( array( 'code' => 'session', 'message' => __( 'Tu sesión venció. Vuelve a entrar con un código.', 'arriendo-facil' ) ), 401 );
		}
		$guest_id = (int) $session->guest_id;

		if ( Arriendo_Facil_Tenant_Access::throttled( 'upload', (string) $guest_id, Arriendo_Facil_Portal_Evidence::DAILY_UPLOADS, DAY_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Llegaste al máximo de envíos por hoy. Inténtalo mañana o contacta a tu administrador.', 'arriendo-facil' ) ), 429 );
		}

		return $guest_id;
	}

	/**
	 * Incidente del inquilino (por contrato), o corta la petición.
	 *
	 * @param int $request_id Incidente.
	 * @param int $guest_id   Inquilino de la sesión.
	 * @return object
	 */
	private static function own_incident( $request_id, $guest_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT r.* FROM ' . Arriendo_Facil_Maintenance::table() . " r INNER JOIN {$wpdb->prefix}af_leases l ON l.id = r.lease_id WHERE r.id = %d AND l.guest_id = %d AND l.deleted_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				absint( $request_id ),
				absint( $guest_id )
			)
		);

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Ese incidente no es tuyo.', 'arriendo-facil' ) ), 403 );
		}

		return $row;
	}

	/**
	 * AJAX: reporta un incidente con fotos.
	 *
	 * @return void
	 */
	public function ajax_report() {
		global $wpdb;
		$guest_id = self::guard();

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		$lease    = $lease_id ? $wpdb->get_row( $wpdb->prepare( "SELECT id, accommodation_id FROM {$wpdb->prefix}af_leases WHERE id = %d AND guest_id = %d AND status IN ('active','pending_release') AND deleted_at IS NULL", $lease_id, $guest_id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $lease ) {
			wp_send_json_error( array( 'message' => __( 'Elige uno de tus contratos vigentes.', 'arriendo-facil' ) ), 400 );
		}

		if ( Arriendo_Facil_Tenant_Access::throttled( 'report', (string) $guest_id, self::REPORTS_PER_DAY, DAY_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Llegaste al máximo de incidentes por hoy. Contacta a tu administrador.', 'arriendo-facil' ) ), 429 );
		}

		$type     = isset( $_POST['request_type'] ) ? sanitize_key( wp_unslash( $_POST['request_type'] ) ) : '';
		$priority = isset( $_POST['priority'] ) ? sanitize_key( wp_unslash( $_POST['priority'] ) ) : 'media';
		$details  = isset( $_POST['damage_details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['damage_details'] ) ) : '';
		$asset    = isset( $_POST['asset_name'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['asset_name'] ) ), 0, 190 ) : '';
		$where    = isset( $_POST['asset_location'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['asset_location'] ) ), 0, 120 ) : '';

		if ( ! in_array( $type, array( 'reparacion', 'emergencia', 'limpieza', 'otro' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Elige el tipo de incidente.', 'arriendo-facil' ) ), 400 );
		}
		if ( ! array_key_exists( $priority, Arriendo_Facil_Maintenance::priorities() ) ) {
			$priority = 'media';
		}
		if ( 'emergencia' === $type ) {
			$priority = 'alta';
		}
		$length = mb_strlen( $details );
		if ( $length < 10 || $length > 1000 ) {
			wp_send_json_error( array( 'message' => __( 'Describe el incidente en 10 a 1000 caracteres.', 'arriendo-facil' ) ), 400 );
		}

		// Una foto como mínimo (salvo emergencia, donde avisar rápido importa más).
		$files = Arriendo_Facil_Portal_Evidence::from_request( 'files', 'emergencia' === $type ? 0 : 1 );
		if ( is_wp_error( $files ) ) {
			wp_send_json_error( array( 'message' => $files->get_error_message() ), 400 );
		}

		$id = Arriendo_Facil_Maintenance::create(
			array(
				'accommodation_id' => (int) $lease->accommodation_id,
				'lease_id'         => (int) $lease->id,
				'request_type'     => $type,
				'priority'         => $priority,
				'reported_by'      => 'inquilino',
				'asset_name'       => $asset,
				'damage_details'   => $details,
				'asset_location'   => $where,
				'notes'            => __( 'Reportado por el inquilino desde su portal.', 'arriendo-facil' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 500 );
		}

		if ( $files ) {
			$saved = Arriendo_Facil_Portal_Evidence::save( $files, $guest_id, (int) $lease->id, 'incident', (int) $id, 'report', 'tenant' );
			if ( is_wp_error( $saved ) ) {
				$wpdb->delete( Arriendo_Facil_Maintenance::table(), array( 'id' => (int) $id ), array( '%d' ) );
				wp_send_json_error( array( 'message' => $saved->get_error_message() ), 500 );
			}
		}

		$prev = self::find_recurrence( (int) $lease->accommodation_id, (int) $id, $asset, $where );
		if ( $prev ) {
			$wpdb->update( Arriendo_Facil_Maintenance::table(), array( 'recurrence_of_id' => $prev ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
		}

		Arriendo_Facil_Portal_Events::log( $guest_id, (int) $lease->id, 'incident', (int) $id, 'tenant', 0, 'incident_reported', array( 'type' => $type, 'photos' => $files ? count( $files ) : 0, 'recurrence_of' => $prev ) );

		Arriendo_Facil_Portal_Manager::notify_manager(
			(int) $lease->accommodation_id,
			'tenant_incident',
			$prev ? __( 'Incidente reincidente reportado', 'arriendo-facil' ) : __( 'Un inquilino reportó un incidente', 'arriendo-facil' ),
			$prev
				? __( 'El mismo bien ya fue reparado antes por el propietario. Revisa el incidente y define la responsabilidad.', 'arriendo-facil' )
				: wp_trim_words( $details, 30 ),
			'af-maintenance',
			'tenant-incident-' . $id
		);

		wp_send_json_success( array( 'id' => (int) $id, 'recurrence' => (bool) $prev ) );
	}

	/**
	 * AJAX: el inquilino sube la evidencia de una reparación que le corresponde.
	 *
	 * @return void
	 */
	public function ajax_evidence() {
		$guest_id = self::guard();
		$row      = self::own_incident( isset( $_POST['request_id'] ) ? absint( wp_unslash( $_POST['request_id'] ) ) : 0, $guest_id );

		if ( 'tenant' !== $row->responsibility || in_array( $row->status, array( 'completed', 'cancelled' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Este incidente no está a tu cargo o ya fue cerrado.', 'arriendo-facil' ) ), 409 );
		}

		$files = Arriendo_Facil_Portal_Evidence::from_request( 'files', 1 );
		if ( is_wp_error( $files ) ) {
			wp_send_json_error( array( 'message' => $files->get_error_message() ), 400 );
		}

		$saved = Arriendo_Facil_Portal_Evidence::save( $files, $guest_id, (int) $row->lease_id, 'incident', (int) $row->id, 'repair', 'tenant' );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 500 );
		}

		Arriendo_Facil_Portal_Events::log( $guest_id, (int) $row->lease_id, 'incident', (int) $row->id, 'tenant', 0, 'repair_evidence', array( 'files' => count( $saved ) ) );
		Arriendo_Facil_Portal_Manager::notify_manager( (int) $row->accommodation_id, 'tenant_repair', __( 'El inquilino subió evidencia de reparación', 'arriendo-facil' ), __( 'Verifica la reparación y cierra el incidente si quedó resuelto.', 'arriendo-facil' ), 'af-maintenance', 'tenant-repair-' . $row->id . '-' . time() );

		wp_send_json_success( array( 'message' => __( 'Evidencia enviada. Tu administrador la verificará.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: acuse de conformidad con una reparación del propietario.
	 *
	 * @return void
	 */
	public function ajax_ack() {
		global $wpdb;
		$guest_id = self::guard();
		$row      = self::own_incident( isset( $_POST['request_id'] ) ? absint( wp_unslash( $_POST['request_id'] ) ) : 0, $guest_id );

		$answer = isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( $_POST['answer'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ) ), 0, 255 ) : '';

		if ( 'completed' !== $row->status || 'owner' !== $row->responsibility || ! empty( $row->tenant_ack ) ) {
			wp_send_json_error( array( 'message' => __( 'Este incidente no admite confirmación.', 'arriendo-facil' ) ), 409 );
		}
		if ( ! in_array( $answer, array( 'conforme', 'no_conforme' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Elige una respuesta.', 'arriendo-facil' ) ), 400 );
		}
		if ( 'no_conforme' === $answer && mb_strlen( $note ) < 10 ) {
			wp_send_json_error( array( 'message' => __( 'Cuéntanos qué falta o qué quedó mal (mínimo 10 caracteres).', 'arriendo-facil' ) ), 400 );
		}

		$wpdb->update(
			Arriendo_Facil_Maintenance::table(),
			array( 'tenant_ack' => $answer, 'tenant_ack_at' => gmdate( 'Y-m-d H:i:s' ), 'tenant_ack_note' => '' !== $note ? $note : null ),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		Arriendo_Facil_Portal_Events::log( $guest_id, (int) $row->lease_id, 'incident', (int) $row->id, 'tenant', 0, 'tenant_ack', array( 'answer' => $answer, 'note' => $note ) );

		if ( 'no_conforme' === $answer ) {
			Arriendo_Facil_Portal_Manager::notify_manager( (int) $row->accommodation_id, 'tenant_not_ok', __( 'El inquilino no está conforme con una reparación', 'arriendo-facil' ), $note, 'af-maintenance', 'tenant-notok-' . $row->id . '-' . time() );
		}

		wp_send_json_success( array( 'message' => __( 'Gracias, tu respuesta quedó registrada.', 'arriendo-facil' ) ) );
	}

	/**
	 * Entrega una evidencia al inquilino dueño de ella (lectura, sin cambios de estado).
	 *
	 * @return void
	 */
	public function ajax_evidence_view() {
		$session = Arriendo_Facil_Tenant_Access::current_session();
		$id      = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lectura; se exige sesión y propiedad.
		$row     = $session && $id ? Arriendo_Facil_Portal_Evidence::get( $id ) : null;

		// Misma respuesta para "no existe" y "no es tuyo": no se revela qué IDs existen.
		if ( ! $row || (int) $row->guest_id !== (int) $session->guest_id || ! empty( $row->removed_at ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'Archivo no disponible.', 'arriendo-facil' ), '', array( 'response' => 404 ) );
		}

		$result = Arriendo_Facil_Portal_Evidence::stream( $row );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html__( 'Archivo no disponible.', 'arriendo-facil' ), '', array( 'response' => 404 ) );
		}
	}
}
