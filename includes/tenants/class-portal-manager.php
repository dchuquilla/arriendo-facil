<?php
/**
 * Portal del inquilino — lado del gestor: quién paga cada servicio, revisión de
 * comprobantes, responsabilidad de incidentes, evidencia de reparación y avisos.
 *
 * Todo pasa por los mismos controles del panel: nonce, capacidad y alcance por
 * inmueble (Arriendo_Facil_Tenancy).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Portal_Manager
 */
class Arriendo_Facil_Portal_Manager {

	const NONCE = 'af_portal_mgr';

	/**
	 * Hooks.
	 */
	public function __construct() {
		foreach ( array( 'set_payer', 'review_payment', 'set_responsibility', 'repair_evidence', 'remove_evidence', 'review_profile' ) as $action ) {
			add_action( 'wp_ajax_af_portal_' . $action, array( $this, 'ajax_' . $action ) );
		}
		add_action( 'wp_ajax_af_portal_evidence_view', array( $this, 'ajax_evidence_view' ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Avisos
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Gestor responsable de un inmueble.
	 *
	 * @param int $accommodation_id Inmueble.
	 * @return int ID de usuario o 0.
	 */
	public static function owner_user_id( $accommodation_id ) {
		return (int) get_post_meta( absint( $accommodation_id ), '_af_owner_id', true );
	}

	/**
	 * Aviso al gestor: campana del panel + correo.
	 *
	 * @param int    $accommodation_id Inmueble.
	 * @param string $type             Tipo de alerta.
	 * @param string $title            Título.
	 * @param string $message          Mensaje corto.
	 * @param string $page             Slug de la pantalla de destino.
	 * @param string $source_key       Clave de idempotencia.
	 * @return void
	 */
	public static function notify_manager( $accommodation_id, $type, $title, $message, $page, $source_key ) {
		$owner_id = self::owner_user_id( $accommodation_id );
		$owner    = $owner_id ? get_userdata( $owner_id ) : null;
		if ( ! $owner ) {
			return;
		}

		$url = admin_url( 'admin.php?page=' . $page );

		if ( class_exists( 'Arriendo_Facil_Alerts' ) ) {
			Arriendo_Facil_Alerts::add( $owner_id, $type, 'info', $title, $message, $url, $source_key );
		}

		if ( is_email( $owner->user_email ) ) {
			Arriendo_Facil_Email::send(
				$owner->user_email,
				$title,
				array(
					'title'     => $title,
					'body_text' => sprintf( /* translators: 1: property, 2: message */ __( 'Inmueble: %1$s' . "\n\n" . '%2$s', 'arriendo-facil' ), get_the_title( $accommodation_id ), $message ),
					'button'    => array(
						'label' => __( 'Revisar en el panel', 'arriendo-facil' ),
						'url'   => $url,
					),
				),
				Arriendo_Facil_Email::CAT_NOTIFICATION
			);
		}
	}

	/**
	 * Aviso al inquilino (sin detalles sensibles: solo invita a entrar al portal).
	 *
	 * @param int    $guest_id Inquilino.
	 * @param string $title    Asunto y título.
	 * @param string $message  Texto.
	 * @return void
	 */
	public static function notify_tenant( $guest_id, $title, $message ) {
		$guest = Arriendo_Facil_Tenant_Access::guest_by_id( $guest_id );
		if ( ! $guest || ! is_email( $guest->email ) ) {
			return;
		}

		Arriendo_Facil_Email::send(
			$guest->email,
			$title,
			array(
				'title'     => $title,
				'greeting'  => sprintf( /* translators: %s: first name */ __( 'Hola %s,', 'arriendo-facil' ), $guest->first_name ),
				'body_text' => $message,
				'button'    => array(
					'label' => __( 'Entrar a mi portal', 'arriendo-facil' ),
					'url'   => Arriendo_Facil_Tenant_Access::portal_url( (int) $guest->id ),
				),
			),
			Arriendo_Facil_Email::CAT_NOTIFICATION
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Autorización
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Nonce + capacidad. Termina la petición si falla.
	 *
	 * @return void
	 */
	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}
	}

	/**
	 * @param int $accommodation_id Inmueble.
	 * @return void
	 */
	private static function guard_accommodation( $accommodation_id ) {
		if ( ! $accommodation_id || ! Arriendo_Facil_Tenancy::can_access_accommodation( (int) $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este inmueble.', 'arriendo-facil' ) ), 403 );
		}
	}

	/**
	 * Inmueble al que pertenece una regla de servicio (directa o por la unidad).
	 *
	 * @param object $schedule Fila de af_service_schedules.
	 * @return int
	 */
	public static function schedule_accommodation( $schedule ) {
		global $wpdb;

		if ( (int) $schedule->accommodation_id ) {
			return (int) $schedule->accommodation_id;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT accommodation_id FROM ' . Arriendo_Facil_Property_Structure::units_table() . ' WHERE id = %d', (int) $schedule->unit_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Quién paga el servicio
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * AJAX: cambia quién paga un servicio.
	 *
	 * @return void
	 */
	public function ajax_set_payer() {
		global $wpdb;
		self::guard();

		$schedule_id = isset( $_POST['schedule_id'] ) ? absint( wp_unslash( $_POST['schedule_id'] ) ) : 0;
		$payer       = isset( $_POST['payer'] ) ? sanitize_key( wp_unslash( $_POST['payer'] ) ) : '';
		$schedule    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Arriendo_Facil_Billing_Ledger::schedules_table() . ' WHERE id = %d', $schedule_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $schedule || ! in_array( $payer, array( 'owner', 'tenant', 'via_owner' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Datos no válidos.', 'arriendo-facil' ) ), 400 );
		}
		self::guard_accommodation( self::schedule_accommodation( $schedule ) );

		$wpdb->update( Arriendo_Facil_Billing_Ledger::schedules_table(), array( 'payer' => $payer ), array( 'id' => $schedule_id ), array( '%s' ), array( '%d' ) );

		wp_send_json_success(
			array(
				'payer'   => $payer,
				'message' => 'tenant' === $payer
					? __( 'Ahora lo paga el inquilino directo a la empresa: le aparece en su portal.', 'arriendo-facil' )
					: ( 'via_owner' === $payer
						? __( 'El inquilino te entrega el dinero y tú pagas a la empresa. Cada entrega genera un recibo.', 'arriendo-facil' )
						: __( 'Ahora lo paga el propietario.', 'arriendo-facil' ) ),
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Comprobantes de pago
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Comprobantes enviados por inquilinos que esperan revisión.
	 *
	 * @return array<int,object>
	 */
	public static function pending_payments() {
		global $wpdb;

		$ids = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
		if ( is_array( $ids ) && empty( $ids ) ) {
			return array();
		}

		$scope = is_array( $ids ) ? ' AND COALESCE( NULLIF( s.accommodation_id, 0 ), u.accommodation_id ) IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')' : '';

		return (array) $wpdb->get_results(
			'SELECT bp.id, bp.schedule_id, bp.period, bp.paid_on, bp.amount, bp.reference, bp.guest_id, bp.created_at,
			        s.service, s.service_label, COALESCE( NULLIF( s.accommodation_id, 0 ), u.accommodation_id ) AS accommodation_id,
			        p.post_title AS property, CONCAT( g.first_name, " ", g.last_name ) AS guest_name
			 FROM ' . Arriendo_Facil_Billing_Ledger::bill_payments_table() . ' bp
			 INNER JOIN ' . Arriendo_Facil_Billing_Ledger::schedules_table() . ' s ON s.id = bp.schedule_id
			 LEFT JOIN ' . Arriendo_Facil_Property_Structure::units_table() . " u ON u.id = s.unit_id
			 LEFT JOIN {$wpdb->posts} p ON p.ID = COALESCE( NULLIF( s.accommodation_id, 0 ), u.accommodation_id )
			 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = bp.guest_id
			 WHERE bp.status = 'submitted'{$scope}
			 ORDER BY bp.created_at ASC LIMIT 100" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids enteros.
		);
	}

	/**
	 * AJAX: aprueba o rechaza un comprobante.
	 *
	 * @return void
	 */
	public function ajax_review_payment() {
		global $wpdb;
		self::guard();

		$bill_id  = isset( $_POST['bill_id'] ) ? absint( wp_unslash( $_POST['bill_id'] ) ) : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$note     = isset( $_POST['note'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ) ), 0, 255 ) : '';

		$bill = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Arriendo_Facil_Billing_Ledger::bill_payments_table() . ' WHERE id = %d', $bill_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $bill || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Datos no válidos.', 'arriendo-facil' ) ), 400 );
		}

		$schedule = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Arriendo_Facil_Billing_Ledger::schedules_table() . ' WHERE id = %d', (int) $bill->schedule_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $schedule ) {
			wp_send_json_error( array( 'message' => __( 'El servicio ya no existe.', 'arriendo-facil' ) ), 404 );
		}
		self::guard_accommodation( self::schedule_accommodation( $schedule ) );

		if ( 'submitted' !== $bill->status ) {
			wp_send_json_error( array( 'message' => __( 'Este comprobante ya fue revisado.', 'arriendo-facil' ) ), 409 );
		}
		if ( 'reject' === $decision && mb_strlen( $note ) < 5 ) {
			wp_send_json_error( array( 'message' => __( 'Explica brevemente por qué se rechaza para que el inquilino pueda corregirlo.', 'arriendo-facil' ) ), 400 );
		}

		$status = 'approve' === $decision ? 'approved' : 'rejected';
		$wpdb->update(
			Arriendo_Facil_Billing_Ledger::bill_payments_table(),
			array(
				'status'      => $status,
				'reviewed_by' => get_current_user_id(),
				'reviewed_at' => gmdate( 'Y-m-d H:i:s' ),
				'review_note' => '' !== $note ? $note : null,
			),
			array( 'id' => $bill_id, 'status' => 'submitted' ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		$label = Arriendo_Facil_Billing_Ledger::service_label( $schedule->service, isset( $schedule->service_label ) ? $schedule->service_label : '' );
		Arriendo_Facil_Portal_Events::log( (int) $bill->guest_id, 0, 'service_payment', $bill_id, 'manager', get_current_user_id(), 'payment_' . $status, array( 'service' => $label, 'period' => $bill->period, 'note' => $note ) );

		Arriendo_Facil_Portal_Manager::notify_tenant(
			(int) $bill->guest_id,
			'approved' === $status ? __( 'Tu pago fue aprobado', 'arriendo-facil' ) : __( 'Tu comprobante necesita corrección', 'arriendo-facil' ),
			'approved' === $status
				? sprintf( /* translators: 1: service, 2: period */ __( 'Tu pago de %1$s (%2$s) fue aprobado.', 'arriendo-facil' ), $label, $bill->period )
				: sprintf( /* translators: 1: service, 2: period, 3: note */ __( 'Tu comprobante de %1$s (%2$s) no fue aceptado: %3$s. Puedes subir uno nuevo desde tu portal.', 'arriendo-facil' ), $label, $bill->period, $note )
		);

		wp_send_json_success( array( 'message' => 'approved' === $status ? __( 'Pago aprobado.', 'arriendo-facil' ) : __( 'Comprobante rechazado. Se avisó al inquilino.', 'arriendo-facil' ) ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Incidentes
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Carga un incidente ligado a un contrato y valida el alcance del gestor.
	 *
	 * @param int $request_id Incidente.
	 * @return object Fila con guest_id.
	 */
	private static function incident_for_manager( $request_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT r.*, l.guest_id FROM ' . Arriendo_Facil_Maintenance::table() . " r
				 LEFT JOIN {$wpdb->prefix}af_leases l ON l.id = r.lease_id
				 WHERE r.id = %d",
				absint( $request_id )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Incidente no encontrado.', 'arriendo-facil' ) ), 404 );
		}
		self::guard_accommodation( (int) $row->accommodation_id );

		if ( ! (int) $row->guest_id ) {
			wp_send_json_error( array( 'message' => __( 'Este incidente no está vinculado a un contrato, así que no tiene portal de inquilino.', 'arriendo-facil' ) ), 400 );
		}

		return $row;
	}

	/**
	 * AJAX: el gestor define quién responde por el incidente.
	 *
	 * @return void
	 */
	public function ajax_set_responsibility() {
		global $wpdb;
		self::guard();

		$row  = self::incident_for_manager( isset( $_POST['request_id'] ) ? absint( wp_unslash( $_POST['request_id'] ) ) : 0 );
		$who  = isset( $_POST['responsibility'] ) ? sanitize_key( wp_unslash( $_POST['responsibility'] ) ) : '';
		$note = isset( $_POST['note'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ) ), 0, 255 ) : '';

		if ( ! in_array( $who, array( 'owner', 'tenant' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Elige quién responde.', 'arriendo-facil' ) ), 400 );
		}
		// Una decisión con consecuencias económicas siempre lleva su razón por escrito.
		if ( mb_strlen( $note ) < 10 ) {
			wp_send_json_error( array( 'message' => __( 'Explica el motivo (mínimo 10 caracteres). Queda como constancia para ambas partes.', 'arriendo-facil' ) ), 400 );
		}

		$wpdb->update(
			Arriendo_Facil_Maintenance::table(),
			array(
				'responsibility'      => $who,
				'responsibility_by'   => get_current_user_id(),
				'responsibility_at'   => gmdate( 'Y-m-d H:i:s' ),
				'responsibility_note' => $note,
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		Arriendo_Facil_Portal_Events::log( (int) $row->guest_id, (int) $row->lease_id, 'incident', (int) $row->id, 'manager', get_current_user_id(), 'responsibility_set', array( 'responsibility' => $who, 'note' => $note ) );

		self::notify_tenant(
			(int) $row->guest_id,
			__( 'Se definió la responsabilidad de tu incidente', 'arriendo-facil' ),
			'tenant' === $who
				? __( 'Tu administrador indicó que la reparación de un incidente te corresponde. Entra a tu portal para ver el motivo y subir la evidencia cuando esté reparado.', 'arriendo-facil' )
				: __( 'Tu administrador se hará cargo de la reparación de un incidente que reportaste. Entra a tu portal para seguirlo.', 'arriendo-facil' )
		);

		wp_send_json_success( array( 'message' => __( 'Responsabilidad guardada y notificada al inquilino.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: el gestor sube la evidencia de la reparación (antes/después, factura).
	 *
	 * @return void
	 */
	public function ajax_repair_evidence() {
		self::guard();

		$row   = self::incident_for_manager( isset( $_POST['request_id'] ) ? absint( wp_unslash( $_POST['request_id'] ) ) : 0 );
		$files = Arriendo_Facil_Portal_Evidence::from_request( 'files', 1 );
		if ( is_wp_error( $files ) ) {
			wp_send_json_error( array( 'message' => $files->get_error_message() ), 400 );
		}

		$saved = Arriendo_Facil_Portal_Evidence::save( $files, (int) $row->guest_id, (int) $row->lease_id, 'incident', (int) $row->id, 'repair', 'manager' );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 500 );
		}

		// Si el propietario repara, el inquilino debe poder confirmar de nuevo.
		global $wpdb;
		$wpdb->update( Arriendo_Facil_Maintenance::table(), array( 'tenant_ack' => null, 'tenant_ack_at' => null, 'tenant_ack_note' => null ), array( 'id' => (int) $row->id ), array( '%s', '%s', '%s' ), array( '%d' ) );

		Arriendo_Facil_Portal_Events::log( (int) $row->guest_id, (int) $row->lease_id, 'incident', (int) $row->id, 'manager', get_current_user_id(), 'repair_evidence', array( 'files' => count( $saved ) ) );
		self::notify_tenant( (int) $row->guest_id, __( 'Tu incidente tiene evidencia de reparación', 'arriendo-facil' ), __( 'Tu administrador subió la evidencia de la reparación. Entra a tu portal para revisarla y confirmar si quedó conforme.', 'arriendo-facil' ) );

		wp_send_json_success( array( 'message' => __( 'Evidencia guardada. Se avisó al inquilino.', 'arriendo-facil' ), 'count' => count( $saved ) ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Evidencia
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Valida que el gestor pueda ver una evidencia (por el inmueble del contrato).
	 *
	 * @param object $evidence Fila de af_evidence.
	 * @return bool
	 */
	private static function manager_can_see( $evidence ) {
		global $wpdb;

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			return false;
		}
		if ( 'incident' === $evidence->subject_type ) {
			$acc = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT accommodation_id FROM ' . Arriendo_Facil_Maintenance::table() . ' WHERE id = %d', (int) $evidence->subject_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			return $acc && Arriendo_Facil_Tenancy::can_access_accommodation( $acc );
		}

		return Arriendo_Facil_Tenancy::can_access_guest( (int) $evidence->guest_id );
	}

	/**
	 * Descarga/vista de una evidencia para el gestor.
	 *
	 * @return void
	 */
	public function ajax_evidence_view() {
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		if ( ! $id || ! check_ajax_referer( 'af_portal_ev_' . $id, 'n', false ) ) {
			wp_die( esc_html__( 'Solicitud inválida.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}

		$row = Arriendo_Facil_Portal_Evidence::get( $id );
		if ( ! $row || ! self::manager_can_see( $row ) ) {
			wp_die( esc_html__( 'No tienes acceso a este archivo.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}

		$result = Arriendo_Facil_Portal_Evidence::stream( $row );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 404 ) );
		}
	}

	/**
	 * URL firmada para que el gestor abra una evidencia.
	 *
	 * @param int $evidence_id Evidencia.
	 * @return string
	 */
	public static function evidence_url( $evidence_id ) {
		return add_query_arg(
			array(
				'action' => 'af_portal_evidence_view',
				'id'     => absint( $evidence_id ),
				'n'      => wp_create_nonce( 'af_portal_ev_' . absint( $evidence_id ) ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * AJAX: retira una evidencia (queda registrada con el motivo).
	 *
	 * @return void
	 */
	public function ajax_remove_evidence() {
		self::guard();

		$row    = Arriendo_Facil_Portal_Evidence::get( isset( $_POST['evidence_id'] ) ? absint( wp_unslash( $_POST['evidence_id'] ) ) : 0 );
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( ! $row || ! self::manager_can_see( $row ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este archivo.', 'arriendo-facil' ) ), 403 );
		}
		if ( mb_strlen( $reason ) < 5 ) {
			wp_send_json_error( array( 'message' => __( 'Indica el motivo para retirar el archivo.', 'arriendo-facil' ) ), 400 );
		}

		Arriendo_Facil_Portal_Evidence::remove( (int) $row->id, get_current_user_id(), $reason );
		Arriendo_Facil_Portal_Events::log( (int) $row->guest_id, (int) $row->lease_id, $row->subject_type, (int) $row->subject_id, 'manager', get_current_user_id(), 'evidence_removed', array( 'evidence' => (int) $row->id, 'reason' => $reason ) );

		wp_send_json_success( array( 'message' => __( 'Archivo retirado. Queda registrado el motivo.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: el gestor marca como revisados los cambios de perfil del inquilino.
	 *
	 * @return void
	 */
	public function ajax_review_profile() {
		global $wpdb;
		self::guard();

		$guest_id = isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0;
		if ( ! $guest_id || ! Arriendo_Facil_Tenancy::can_access_guest( $guest_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este inquilino.', 'arriendo-facil' ) ), 403 );
		}

		$wpdb->update( $wpdb->prefix . 'af_guests', array( 'profile_updated_review' => 0 ), array( 'id' => $guest_id ), array( '%d' ), array( '%d' ) );
		wp_send_json_success( array( 'message' => __( 'Marcado como revisado.', 'arriendo-facil' ) ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Panel dentro de Mantenimiento
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Bloque "Portal del inquilino" de una tarjeta de Mantenimiento.
	 *
	 * @param object $request Fila de af_cleaning_requests.
	 * @return void
	 */
	public static function render_incident_panel( $request ) {
		global $wpdb;
		static $script_done = false;

		$lease_id = (int) ( $request->lease_id ?? 0 );
		$guest_id = $lease_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT guest_id FROM {$wpdb->prefix}af_leases WHERE id = %d", $lease_id ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $guest_id || ! isset( $request->responsibility ) ) {
			return;
		}

		$id        = (int) $request->id;
		$report    = Arriendo_Facil_Portal_Evidence::for_subject( 'incident', $id, $guest_id, 'report' );
		$repair    = Arriendo_Facil_Portal_Evidence::for_subject( 'incident', $id, $guest_id, 'repair' );
		$timeline  = Arriendo_Facil_Portal_Events::timeline( 'incident', $id, $guest_id );
		$resp      = (string) $request->responsibility;
		$labels    = array(
			'por_definir' => __( 'Por definir', 'arriendo-facil' ),
			'owner'       => __( 'Propietario', 'arriendo-facil' ),
			'tenant'      => __( 'Inquilino', 'arriendo-facil' ),
		);
		$recurrence = (int) ( $request->recurrence_of_id ?? 0 );
		$nonce      = wp_create_nonce( self::NONCE );
		$thumbs     = static function ( array $items ) {
			foreach ( $items as $ev ) {
				$url = esc_url( self::evidence_url( (int) $ev->id ) );
				if ( 'application/pdf' === $ev->mime_type ) {
					echo '<a class="af-portal-file" href="' . $url . '" target="_blank" rel="noopener">PDF</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '<a class="af-portal-thumb" href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="" loading="lazy" width="72" height="72"></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
		};
		?>
		<details class="af-maint-card__notes af-portal-incident" data-af-portal-incident data-request="<?php echo esc_attr( (string) $id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" <?php echo ( 'por_definir' === $resp && 'inquilino' === ( $request->reported_by ?? '' ) ) || $recurrence ? 'open' : ''; ?>>
			<summary>
				<?php esc_html_e( 'Portal del inquilino', 'arriendo-facil' ); ?>
				· <?php echo esc_html( $labels[ $resp ] ?? $resp ); ?>
				<?php if ( $recurrence ) : ?>
					<strong style="color:#8e2b27;">· <?php esc_html_e( 'Reincidencia', 'arriendo-facil' ); ?></strong>
				<?php endif; ?>
			</summary>

			<?php if ( $recurrence ) : ?>
				<p><strong><?php echo esc_html( sprintf( /* translators: %d: request id */ __( 'Este bien ya fue reparado por el propietario en el incidente #%d.', 'arriendo-facil' ), $recurrence ) ); ?></strong>
				<?php esc_html_e( 'Por regla, una nueva falla del mismo bien suele corresponder al inquilino. Es una sugerencia: la decisión es tuya y debe quedar por escrito.', 'arriendo-facil' ); ?></p>
			<?php endif; ?>

			<?php if ( $report ) : ?>
				<p><strong><?php esc_html_e( 'Fotos del reporte', 'arriendo-facil' ); ?></strong></p>
				<div class="af-portal-gallery"><?php $thumbs( $report ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $request->responsibility_note ) ) : ?>
				<p><strong><?php esc_html_e( 'Motivo registrado:', 'arriendo-facil' ); ?></strong> <?php echo esc_html( (string) $request->responsibility_note ); ?></p>
			<?php endif; ?>

			<div class="af-portal-row">
				<select data-f="responsibility">
					<option value="owner" <?php selected( 'owner', $resp ); ?>><?php esc_html_e( 'Lo repara el propietario', 'arriendo-facil' ); ?></option>
					<option value="tenant" <?php selected( 'tenant', 'por_definir' === $resp && $recurrence ? 'tenant' : $resp ); ?>><?php esc_html_e( 'Le corresponde al inquilino', 'arriendo-facil' ); ?></option>
				</select>
				<input type="text" data-f="note" maxlength="255" placeholder="<?php esc_attr_e( 'Motivo (obligatorio, lo ven ambas partes)', 'arriendo-facil' ); ?>">
				<button type="button" class="button button-small" data-act="set_responsibility"><?php esc_html_e( 'Guardar', 'arriendo-facil' ); ?></button>
			</div>

			<?php if ( $repair ) : ?>
				<p><strong><?php esc_html_e( 'Evidencia de reparación', 'arriendo-facil' ); ?></strong></p>
				<div class="af-portal-gallery"><?php $thumbs( $repair ); ?></div>
			<?php endif; ?>

			<div class="af-portal-row">
				<input type="file" data-f="files" accept="image/jpeg,image/png,image/webp,application/pdf" multiple>
				<button type="button" class="button button-small" data-act="repair_evidence"><?php esc_html_e( 'Subir evidencia de reparación', 'arriendo-facil' ); ?></button>
			</div>

			<?php if ( ! empty( $request->tenant_ack ) ) : ?>
				<p><strong><?php esc_html_e( 'Respuesta del inquilino:', 'arriendo-facil' ); ?></strong>
					<?php echo esc_html( 'conforme' === $request->tenant_ack ? __( 'Recibió conforme', 'arriendo-facil' ) : __( 'No está conforme', 'arriendo-facil' ) ); ?>
					<?php echo ! empty( $request->tenant_ack_note ) ? '— ' . esc_html( (string) $request->tenant_ack_note ) : ''; ?></p>
			<?php endif; ?>

			<?php if ( $timeline ) : ?>
				<ol class="af-portal-timeline">
					<?php foreach ( $timeline as $ev ) : ?>
						<li><?php echo esc_html( wp_date( 'j M Y H:i', strtotime( $ev->created_at . ' UTC' ) ) . ' · ' . ( 'tenant' === $ev->actor_type ? __( 'Inquilino', 'arriendo-facil' ) : ( 'manager' === $ev->actor_type ? __( 'Gestor', 'arriendo-facil' ) : __( 'Sistema', 'arriendo-facil' ) ) ) . ' · ' . Arriendo_Facil_Portal_Events::label( $ev->action ) ); ?></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
			<p class="af-portal-msg" data-msg role="status"></p>
		</details>
		<?php if ( ! $script_done ) : $script_done = true; ?>
		<style>
			.af-portal-gallery{display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 10px}
			.af-portal-thumb img{width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid #c9d5d2}
			.af-portal-file{display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border:1px solid #c9d5d2;border-radius:8px;font-weight:600}
			.af-portal-row{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}
			.af-portal-row input[type=text]{flex:1 1 200px;min-width:0}
			.af-portal-timeline{margin:8px 0 0 18px;font-size:12px;color:#4f6267}
			.af-portal-msg{font-size:12px;margin:6px 0 0}
		</style>
		<script>
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-af-portal-incident] [data-act]');
			if (!btn) { return; }
			var box = btn.closest('[data-af-portal-incident]');
			var msg = box.querySelector('[data-msg]');
			var fd = new FormData();
			fd.append('action', 'af_portal_' + btn.getAttribute('data-act'));
			fd.append('nonce', box.getAttribute('data-nonce'));
			fd.append('request_id', box.getAttribute('data-request'));
			box.querySelectorAll('[data-f]').forEach(function (el) {
				if (el.type === 'file') { Array.prototype.forEach.call(el.files, function (f) { fd.append('files[]', f); }); }
				else { fd.append(el.getAttribute('data-f'), el.value); }
			});
			btn.disabled = true;
			fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					btn.disabled = false;
					msg.textContent = (j && j.data && j.data.message) || '';
					msg.style.color = j && j.success ? '#1f6e63' : '#b03a36';
					if (j && j.success) { setTimeout(function () { window.location.reload(); }, 900); }
				})
				.catch(function () { btn.disabled = false; msg.textContent = <?php echo wp_json_encode( __( 'No se pudo conectar. Inténtalo de nuevo.', 'arriendo-facil' ) ); ?>; msg.style.color = '#b03a36'; });
		});
		</script>
		<?php endif;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Pantalla "Pagos de servicios": selector de responsable y cola de revisión
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Selector "Lo paga" para la tarjeta de un servicio.
	 *
	 * @param int    $schedule_id Regla.
	 * @param string $payer       owner | tenant | via_owner.
	 * @param string $period      Período en pantalla (YYYY-MM).
	 * @return void
	 */
	public static function render_payer_toggle( $schedule_id, $payer, $period = '' ) {
		$payer = in_array( $payer, array( 'tenant', 'via_owner' ), true ) ? $payer : 'owner';
		?>
		<div class="af-payer" role="group" aria-label="<?php esc_attr_e( 'Quién paga este servicio', 'arriendo-facil' ); ?>" data-af-payer data-schedule="<?php echo esc_attr( (string) absint( $schedule_id ) ); ?>">
			<span class="af-payer__label"><?php esc_html_e( 'Lo paga', 'arriendo-facil' ); ?></span>
			<button type="button" class="af-payer__opt<?php echo 'owner' === $payer ? ' is-on' : ''; ?>" data-payer="owner" aria-pressed="<?php echo 'owner' === $payer ? 'true' : 'false'; ?>"><?php esc_html_e( 'Propietario', 'arriendo-facil' ); ?></button>
			<button type="button" class="af-payer__opt<?php echo 'tenant' === $payer ? ' is-on' : ''; ?>" data-payer="tenant" aria-pressed="<?php echo 'tenant' === $payer ? 'true' : 'false'; ?>" title="<?php esc_attr_e( 'El inquilino paga directo a la empresa y sube su comprobante', 'arriendo-facil' ); ?>"><?php esc_html_e( 'Inquilino (a la empresa)', 'arriendo-facil' ); ?></button>
			<button type="button" class="af-payer__opt<?php echo 'via_owner' === $payer ? ' is-on' : ''; ?>" data-payer="via_owner" aria-pressed="<?php echo 'via_owner' === $payer ? 'true' : 'false'; ?>" title="<?php esc_attr_e( 'El inquilino te entrega el dinero y tú pagas; se emite un recibo', 'arriendo-facil' ); ?>"><?php esc_html_e( 'Inquilino (te lo entrega)', 'arriendo-facil' ); ?></button>
		</div>
		<?php if ( 'via_owner' === $payer ) : ?>
			<?php self::render_collect_form( $schedule_id, $period ); ?>
		<?php endif; ?>
		<?php
		self::render_inline_review( $schedule_id, $period );
	}

	/**
	 * Comprobante o entrega pendiente de este servicio y mes, dentro de la propia tarjeta.
	 * Usa los mismos botones que la cola de arriba (mismo manejador).
	 *
	 * @param int    $schedule_id Regla.
	 * @param string $period      YYYY-MM.
	 * @return void
	 */
	private static function render_inline_review( $schedule_id, $period ) {
		global $wpdb;

		if ( 1 !== preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period ) ) {
			return;
		}
		$bill = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Arriendo_Facil_Billing_Ledger::bill_payments_table() . " WHERE schedule_id = %d AND period = %s AND status = 'submitted'", absint( $schedule_id ), $period ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$dels = array_filter(
			Arriendo_Facil_Portal_Collections::for_service( (int) $schedule_id, $period ),
			static function ( $d ) {
				return 'submitted' === $d->status;
			}
		);
		if ( ! $bill && empty( $dels ) ) {
			return;
		}
		$methods = Arriendo_Facil_Portal_Collections::methods();
		$gallery = static function ( array $items ) {
			echo '<div class="af-portal-gallery">';
			foreach ( $items as $ev ) {
				$url = esc_url( Arriendo_Facil_Portal_Manager::evidence_url( (int) $ev->id ) );
				if ( 'application/pdf' === $ev->mime_type ) {
					echo '<a class="af-portal-file" href="' . $url . '" target="_blank" rel="noopener">PDF</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '<a class="af-portal-thumb" href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="" loading="lazy" width="72" height="72"></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			echo '</div>';
		};
		?>
		<div class="af-inline-review" data-af-portal-queue>
			<?php if ( $bill ) : ?>
				<div class="af-portal-queue__card" data-bill="<?php echo esc_attr( (string) $bill->id ); ?>">
					<strong><?php esc_html_e( 'El inquilino envió su comprobante', 'arriendo-facil' ); ?></strong>
					<span><?php echo esc_html( sprintf( /* translators: 1: amount, 2: date */ __( 'Pagó $%1$s el %2$s', 'arriendo-facil' ), number_format_i18n( (float) $bill->amount, 2 ), wp_date( 'j M Y', strtotime( $bill->paid_on . ' 12:00:00' ) ) ) . ( $bill->reference ? ' · ' . $bill->reference : '' ) ); ?></span>
					<?php $gallery( Arriendo_Facil_Portal_Evidence::for_subject( 'service_payment', (int) $bill->id, (int) $bill->guest_id ) ); ?>
					<input type="text" class="af-portal-queue__note" maxlength="255" placeholder="<?php esc_attr_e( 'Nota (obligatoria si rechazas)', 'arriendo-facil' ); ?>">
					<div class="af-portal-row">
						<button type="button" class="button button-primary button-small" data-decision="approve"><?php esc_html_e( 'Aprobar', 'arriendo-facil' ); ?></button>
						<button type="button" class="button button-small" data-decision="reject"><?php esc_html_e( 'Rechazar', 'arriendo-facil' ); ?></button>
					</div>
					<p class="af-portal-msg" data-msg role="status"></p>
				</div>
			<?php endif; ?>
			<?php foreach ( $dels as $d ) : ?>
				<div class="af-portal-queue__card" data-delivery="<?php echo esc_attr( (string) $d->id ); ?>">
					<strong><?php esc_html_e( 'El inquilino dice que te entregó dinero', 'arriendo-facil' ); ?></strong>
					<span><?php echo esc_html( sprintf( /* translators: 1: amount, 2: method */ __( '$%1$s · %2$s', 'arriendo-facil' ), number_format_i18n( (float) $d->amount, 2 ), isset( $methods[ $d->method ] ) ? $methods[ $d->method ] : $d->method ) ); ?></span>
					<?php $gallery( Arriendo_Facil_Portal_Evidence::for_subject( 'delivery', (int) $d->id, (int) $d->guest_id ) ); ?>
					<input type="text" class="af-portal-queue__note" maxlength="255" placeholder="<?php esc_attr_e( 'Nota (obligatoria si no lo confirmas)', 'arriendo-facil' ); ?>">
					<div class="af-portal-row">
						<button type="button" class="button button-primary button-small" data-decision="confirm"><?php esc_html_e( 'Sí, lo recibí: emitir recibo', 'arriendo-facil' ); ?></button>
						<button type="button" class="button button-small" data-decision="reject"><?php esc_html_e( 'No confirmar', 'arriendo-facil' ); ?></button>
					</div>
					<p class="af-portal-msg" data-msg role="status"></p>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Entregas de dinero de un servicio "te lo entrega": registrar lo recibido y ver los recibos.
	 *
	 * @param int    $schedule_id Regla.
	 * @param string $period      YYYY-MM.
	 * @return void
	 */
	public static function render_collect_form( $schedule_id, $period ) {
		if ( 1 !== preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period ) ) {
			return;
		}
		$rows    = Arriendo_Facil_Portal_Collections::for_service( (int) $schedule_id, $period );
		$methods = Arriendo_Facil_Portal_Collections::methods();
		?>
		<details class="af-collect" data-af-collect data-schedule="<?php echo esc_attr( (string) absint( $schedule_id ) ); ?>" data-period="<?php echo esc_attr( $period ); ?>">
			<summary><?php echo esc_html( sprintf( /* translators: %d: count */ _n( 'Dinero del inquilino (%d recibo)', 'Dinero del inquilino (%d recibos)', count( array_filter( $rows, static function ( $r ) { return 'received' === $r->status; } ) ), 'arriendo-facil' ), count( array_filter( $rows, static function ( $r ) { return 'received' === $r->status; } ) ) ) ); ?></summary>
			<?php if ( ! empty( $rows ) ) : ?>
				<ul class="af-collect__list">
					<?php foreach ( $rows as $r ) : ?>
						<li>
							<?php echo esc_html( '$' . number_format_i18n( (float) $r->amount, 2 ) . ' · ' . ( isset( $methods[ $r->method ] ) ? $methods[ $r->method ] : $r->method ) . ' · ' . wp_date( 'j M', strtotime( $r->delivered_on . ' 12:00:00' ) ) ); ?>
							<?php if ( 'received' === $r->status ) : ?>
								<a href="<?php echo esc_url( Arriendo_Facil_Portal_Collections::manager_receipt_url( (int) $r->id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r->receipt_no ); ?></a>
							<?php elseif ( 'submitted' === $r->status ) : ?>
								<em><?php esc_html_e( 'por confirmar (ver cola arriba)', 'arriendo-facil' ); ?></em>
							<?php else : ?>
								<em><?php esc_html_e( 'no confirmada', 'arriendo-facil' ); ?></em>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="af-collect__form">
				<input type="text" inputmode="decimal" data-f="amount" placeholder="<?php esc_attr_e( 'Monto recibido ($)', 'arriendo-facil' ); ?>" aria-label="<?php esc_attr_e( 'Monto recibido', 'arriendo-facil' ); ?>">
				<select data-f="method" aria-label="<?php esc_attr_e( 'Forma de entrega', 'arriendo-facil' ); ?>">
					<?php foreach ( $methods as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="date" data-f="delivered_on" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" aria-label="<?php esc_attr_e( 'Fecha', 'arriendo-facil' ); ?>">
				<input type="text" data-f="reference" maxlength="190" placeholder="<?php esc_attr_e( 'Referencia (opcional)', 'arriendo-facil' ); ?>">
				<button type="button" class="button button-small" data-collect-save><?php esc_html_e( 'Registrar y emitir recibo', 'arriendo-facil' ); ?></button>
				<span class="af-portal-msg" data-msg role="status"></span>
			</div>
		</details>
		<?php
	}

	/**
	 * Cola de comprobantes por revisar (arriba de Pagos de servicios).
	 *
	 * @return void
	 */
	public static function render_review_queue() {
		$rows       = self::pending_payments();
		$deliveries = Arriendo_Facil_Portal_Collections::pending();
		if ( empty( $rows ) && empty( $deliveries ) ) {
			return;
		}
		$methods = Arriendo_Facil_Portal_Collections::methods();
		?>
		<section class="af-section af-portal-queue" aria-labelledby="af-portal-queue-title" data-af-portal-queue>
			<h2 class="af-section__title" id="af-portal-queue-title"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d comprobante por revisar', '%d comprobantes por revisar', count( $rows ) + count( $deliveries ), 'arriendo-facil' ), count( $rows ) + count( $deliveries ) ) ); ?></h2>
			<div class="af-portal-queue__grid">
				<?php foreach ( $deliveries as $d ) : ?>
					<?php
					$d_label = Arriendo_Facil_Billing_Ledger::service_label( $d->service, isset( $d->service_label ) ? $d->service_label : '' );
					$d_files = Arriendo_Facil_Portal_Evidence::for_subject( 'delivery', (int) $d->id, (int) $d->guest_id );
					?>
					<article class="af-portal-queue__card" data-delivery="<?php echo esc_attr( (string) $d->id ); ?>">
						<strong><?php echo esc_html( $d_label . ' · ' . $d->period ); ?></strong>
						<span><?php echo esc_html( trim( (string) $d->guest_name ) . ' · ' . $d->property ); ?></span>
						<span><?php echo esc_html( sprintf( /* translators: 1: amount, 2: method, 3: date */ __( 'Dice que te entregó $%1$s (%2$s) el %3$s', 'arriendo-facil' ), number_format_i18n( (float) $d->amount, 2 ), isset( $methods[ $d->method ] ) ? $methods[ $d->method ] : $d->method, wp_date( 'j M Y', strtotime( $d->delivered_on . ' 12:00:00' ) ) ) . ( $d->reference ? ' · ' . $d->reference : '' ) ); ?></span>
						<div class="af-portal-gallery">
							<?php foreach ( $d_files as $ev ) : $url = esc_url( self::evidence_url( (int) $ev->id ) ); ?>
								<?php if ( 'application/pdf' === $ev->mime_type ) : ?>
									<a class="af-portal-file" href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" target="_blank" rel="noopener">PDF</a>
								<?php else : ?>
									<a class="af-portal-thumb" href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" target="_blank" rel="noopener"><img src="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" alt="" loading="lazy" width="72" height="72"></a>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
						<input type="text" class="af-portal-queue__note" maxlength="255" placeholder="<?php esc_attr_e( 'Nota (obligatoria si no lo confirmas)', 'arriendo-facil' ); ?>">
						<div class="af-portal-row">
							<button type="button" class="button button-primary button-small" data-decision="confirm"><?php esc_html_e( 'Sí, lo recibí: emitir recibo', 'arriendo-facil' ); ?></button>
							<button type="button" class="button button-small" data-decision="reject"><?php esc_html_e( 'No confirmar', 'arriendo-facil' ); ?></button>
						</div>
						<p class="af-portal-msg" data-msg role="status"></p>
					</article>
				<?php endforeach; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$label = Arriendo_Facil_Billing_Ledger::service_label( $row->service, isset( $row->service_label ) ? $row->service_label : '' );
					$files = Arriendo_Facil_Portal_Evidence::for_subject( 'service_payment', (int) $row->id, (int) $row->guest_id );
					?>
					<article class="af-portal-queue__card" data-bill="<?php echo esc_attr( (string) $row->id ); ?>">
						<strong><?php echo esc_html( $label . ' · ' . $row->period ); ?></strong>
						<span><?php echo esc_html( trim( (string) $row->guest_name ) . ' · ' . $row->property ); ?></span>
						<span><?php echo esc_html( sprintf( /* translators: 1: amount, 2: date */ __( 'Pagó $%1$s el %2$s', 'arriendo-facil' ), number_format_i18n( (float) $row->amount, 2 ), wp_date( 'j M Y', strtotime( $row->paid_on . ' 12:00:00' ) ) ) . ( $row->reference ? ' · ' . $row->reference : '' ) ); ?></span>
						<div class="af-portal-gallery">
							<?php foreach ( $files as $ev ) : $url = esc_url( self::evidence_url( (int) $ev->id ) ); ?>
								<?php if ( 'application/pdf' === $ev->mime_type ) : ?>
									<a class="af-portal-file" href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" target="_blank" rel="noopener">PDF</a>
								<?php else : ?>
									<a class="af-portal-thumb" href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" target="_blank" rel="noopener"><img src="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" alt="" loading="lazy" width="72" height="72"></a>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
						<input type="text" class="af-portal-queue__note" maxlength="255" placeholder="<?php esc_attr_e( 'Nota (obligatoria si rechazas)', 'arriendo-facil' ); ?>">
						<div class="af-portal-row">
							<button type="button" class="button button-primary button-small" data-decision="approve"><?php esc_html_e( 'Aprobar', 'arriendo-facil' ); ?></button>
							<button type="button" class="button button-small" data-decision="reject"><?php esc_html_e( 'Rechazar', 'arriendo-facil' ); ?></button>
						</div>
						<p class="af-portal-msg" data-msg role="status"></p>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Historial de pagos de un servicio — últimos 6 meses aprobados.
	 *
	 * @param int    $schedule_id Regla de servicio.
	 * @param string $period      Período actual YYYY-MM (excluido del historial).
	 * @return void
	 */
	/**
	 * Renders payment history for a service schedule.
	 *
	 * @param int           $schedule_id    Schedule ID.
	 * @param string        $period         Current period (YYYY-MM). Used as upper bound.
	 * @param callable|null $ev_url         Optional. Receives int evidence_id, returns URL string.
	 *                                       Defaults to manager evidence_url(). Pass null for manager.
	 * @param bool          $include_current Whether to include $period itself (true) or only prior (false).
	 * @return void
	 */
	public static function render_service_history( $schedule_id, $period, $ev_url = null, $include_current = true ) {
		global $wpdb;

		$schedule_id = absint( $schedule_id );
		if ( ! $schedule_id ) {
			return;
		}

		if ( null === $ev_url ) {
			$ev_url = array( __CLASS__, 'evidence_url' );
		}

		$table     = Arriendo_Facil_Billing_Ledger::bill_payments_table();
		$op        = $include_current ? '<=' : '<';
		$rows      = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"SELECT id, period, amount, paid_on, reference FROM {$table} WHERE schedule_id = %d AND period {$op} %s AND status IN ('approved','paid') ORDER BY period DESC LIMIT 6", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$schedule_id,
			$period
		) );

		if ( empty( $rows ) ) {
			return;
		}

		$ev_table = Arriendo_Facil_Portal_Schema::evidence_table();
		foreach ( $rows as $r ) {
			$r->evidence = $r->id ? (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT id, mime_type FROM {$ev_table} WHERE subject_type = 'service_payment' AND subject_id = %d AND removed_at IS NULL ORDER BY id ASC LIMIT 6", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				absint( $r->id )
			) ) : array();
		}
		?>
		<details class="af-sp-history">
			<summary><?php esc_html_e( 'Historial de pagos', 'arriendo-facil' ); ?> <span>(<?php echo esc_html( count( $rows ) ); ?>)</span></summary>
			<ul class="af-sp-history__list">
				<?php foreach ( $rows as $r ) : ?>
					<li class="af-sp-history__item">
						<div class="af-sp-history__meta">
							<span class="af-sp-history__period"><?php echo esc_html( wp_date( 'M Y', strtotime( $r->period . '-01 12:00:00' ) ) ); ?></span>
							<?php if ( $r->amount > 0 ) : ?>
								<span class="af-sp-history__amount">$<?php echo esc_html( number_format_i18n( (float) $r->amount, 2 ) ); ?></span>
							<?php endif; ?>
							<?php if ( $r->paid_on ) : ?>
								<span class="af-sp-history__date"><?php echo esc_html( wp_date( 'j M', strtotime( $r->paid_on . ' 12:00:00' ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( $r->reference ) : ?>
								<span class="af-sp-history__ref" title="<?php echo esc_attr( $r->reference ); ?>"><?php echo esc_html( $r->reference ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $r->evidence ) ) : ?>
							<div class="af-sp-history__proof">
								<?php foreach ( $r->evidence as $ev ) : ?>
									<?php
									$src   = esc_url( call_user_func( $ev_url, (int) $ev->id ) );
									$is_pdf = 'application/pdf' === $ev->mime_type;
									?>
									<button type="button" class="af-sp-ev-thumb" data-af-lightbox-src="<?php echo $src; ?>" data-af-lightbox-pdf="<?php echo $is_pdf ? '1' : '0'; ?>" aria-label="<?php echo $is_pdf ? esc_attr__( 'Ver PDF del comprobante', 'arriendo-facil' ) : esc_attr__( 'Ver foto del comprobante', 'arriendo-facil' ); ?>">
										<?php if ( $is_pdf ) : ?>
											<span class="af-sp-ev-thumb__pdf">PDF</span>
										<?php else : ?>
											<img src="<?php echo $src; ?>" alt="<?php esc_attr_e( 'Comprobante', 'arriendo-facil' ); ?>" loading="lazy" width="52" height="52">
										<?php endif; ?>
									</button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * Estilos y script de la cola y del selector (una vez por pantalla).
	 *
	 * @return void
	 */
	public static function render_services_assets() {
		?>
		<style>
			.af-portal-queue{margin-bottom:20px}
			.af-portal-queue__grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(260px,1fr))}
			.af-portal-queue__card{display:flex;flex-direction:column;gap:6px;padding:14px;border:1px solid #e1e9e7;border-radius:14px;background:#fff}
			.af-portal-queue__card span{font-size:13px;color:#4f6267}
			.af-portal-queue__note{width:100%}
			.af-portal-gallery{display:flex;flex-wrap:wrap;gap:6px}
			.af-portal-thumb img{width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid #c9d5d2}
			.af-portal-file{display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border:1px solid #c9d5d2;border-radius:8px;font-weight:600}
			.af-portal-row{display:flex;flex-wrap:wrap;gap:6px}
			.af-portal-msg{font-size:12px;margin:0}
			.af-payer{display:flex;align-items:center;gap:4px;margin:4px 0;overflow-x:auto;scrollbar-width:none;padding-bottom:1px}
			.af-payer::-webkit-scrollbar{display:none}
			.af-payer__label{font-size:11px;color:#4f6267;font-weight:600;white-space:nowrap;flex-shrink:0;margin-right:2px}
			.af-payer__opt{min-height:26px;padding:0 8px;border:1px solid #c9d5d2;border-radius:999px;background:#fff;font-size:11px;cursor:pointer;white-space:nowrap;flex-shrink:0;font-family:inherit}
			.af-payer__opt.is-on{background:#164a55;border-color:#164a55;color:#fff}
			.af-collect{margin:4px 0;font-size:12px}.af-collect summary{cursor:pointer;color:#164a55;font-weight:600}
			.af-collect__list{margin:6px 0;padding-left:16px}.af-collect__form{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
			.af-collect__form input,.af-collect__form select{max-width:130px;min-height:30px}
		</style>
		<script>
		(function () {
			var url = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			function send(data) {
				var fd = new FormData();
				Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
				fd.append('nonce', nonce);
				return fetch(url, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
			}
			document.addEventListener('click', function (e) {
				var opt = e.target.closest('[data-af-payer] [data-payer]');
				if (opt) {
					var box = opt.closest('[data-af-payer]');
					box.querySelectorAll('[data-payer]').forEach(function (b) { b.disabled = true; });
					send({ action: 'af_portal_set_payer', schedule_id: box.getAttribute('data-schedule'), payer: opt.getAttribute('data-payer') }).then(function (j) {
						box.querySelectorAll('[data-payer]').forEach(function (b) { b.disabled = false; });
						if (j && j.success) {
							var was = box.querySelector('.is-on'), wasVia = was && was.getAttribute('data-payer') === 'via_owner';
							if (wasVia || j.data.payer === 'via_owner') { setTimeout(function () { window.location.reload(); }, 600); }
							box.querySelectorAll('[data-payer]').forEach(function (b) {
								var on = b.getAttribute('data-payer') === j.data.payer;
								b.classList.toggle('is-on', on);
								b.setAttribute('aria-pressed', on ? 'true' : 'false');
							});
						}
						(window.afNotify || window.alert)((j && j.data && j.data.message) || '', j && j.success ? 'success' : 'error');
					});
					return;
				}
				var save = e.target.closest('[data-collect-save]');
				if (save) {
					var box2 = save.closest('[data-af-collect]'), out = box2.querySelector('[data-msg]');
					var data = { action: 'af_portal_delivery_register', schedule_id: box2.getAttribute('data-schedule'), period: box2.getAttribute('data-period') };
					box2.querySelectorAll('[data-f]').forEach(function (el) { data[el.getAttribute('data-f')] = el.value; });
					save.disabled = true;
					send(data).then(function (j) {
						out.textContent = (j && j.data && j.data.message) || '';
						out.style.color = j && j.success ? '#1f6e63' : '#b03a36';
						if (j && j.success) { setTimeout(function () { window.location.reload(); }, 900); } else { save.disabled = false; }
					});
					return;
				}
				var btn = e.target.closest('[data-af-portal-queue] [data-decision]');
				if (!btn) { return; }
				var card = btn.closest('[data-bill],[data-delivery]');
				var msg = card.querySelector('[data-msg]');
				card.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
				var isDelivery = card.hasAttribute('data-delivery');
				send(isDelivery
					? { action: 'af_portal_delivery_review', delivery_id: card.getAttribute('data-delivery'), decision: btn.getAttribute('data-decision'), note: card.querySelector('.af-portal-queue__note').value }
					: { action: 'af_portal_review_payment', bill_id: card.getAttribute('data-bill'), decision: btn.getAttribute('data-decision'), note: card.querySelector('.af-portal-queue__note').value }).then(function (j) {
					msg.textContent = (j && j.data && j.data.message) || '';
					msg.style.color = j && j.success ? '#1f6e63' : '#b03a36';
					if (j && j.success) { setTimeout(function () { window.location.reload(); }, 700); }
					else { card.querySelectorAll('button').forEach(function (b) { b.disabled = false; }); }
				});
			});
		}());
		</script>
		<?php
	}

	/**
	 * Aviso "Actualizado por el inquilino" en la ficha, con lo que cambió.
	 *
	 * @param int $guest_id Inquilino.
	 * @return void
	 */
	public static function render_profile_notice( $guest_id ) {
		global $wpdb;

		$guest_id = absint( $guest_id );
		$flag     = $wpdb->get_row( $wpdb->prepare( "SELECT profile_updated_at, profile_updated_review FROM {$wpdb->prefix}af_guests WHERE id = %d", $guest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $flag || empty( $flag->profile_updated_review ) ) {
			return;
		}

		$events = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT action, detail, created_at FROM ' . Arriendo_Facil_Portal_Schema::events_table() . " WHERE guest_id = %d AND subject_type IN ('profile','document') ORDER BY id DESC LIMIT 8", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$guest_id
			)
		);
		$field_labels = array(
			'phone'             => __( 'teléfono', 'arriendo-facil' ),
			'name'              => __( 'nombre', 'arriendo-facil' ),
			'id_number'         => __( 'identificación', 'arriendo-facil' ),
		);
		?>
		<div class="notice notice-info" style="margin:12px 0;padding:12px 14px;" data-af-profile-notice data-guest="<?php echo esc_attr( (string) $guest_id ); ?>">
			<p><strong><?php esc_html_e( 'Actualizado por el inquilino', 'arriendo-facil' ); ?></strong> · <?php echo esc_html( wp_date( 'j M Y, H:i', strtotime( $flag->profile_updated_at . ' UTC' ) ) ); ?></p>
			<ul style="margin:6px 0 8px 18px;list-style:disc;">
				<?php foreach ( $events as $ev ) : ?>
					<?php
					$detail = $ev->detail ? json_decode( $ev->detail, true ) : array();
					$text   = Arriendo_Facil_Portal_Events::label( $ev->action );
					if ( ! empty( $detail['fields'] ) && is_array( $detail['fields'] ) ) {
						$text .= ': ' . implode( ', ', array_map( static function ( $f ) use ( $field_labels ) {
							return isset( $field_labels[ $f ] ) ? $field_labels[ $f ] : $f;
						}, $detail['fields'] ) );
					}
					if ( 'correction_requested' === $ev->action && ! empty( $detail['field'] ) ) {
						$value = isset( $detail['value'] ) ? (string) $detail['value'] : '';
						if ( class_exists( 'Arriendo_Facil_Crypto' ) && Arriendo_Facil_Crypto::is_sealed( $value ) ) {
							$opened = Arriendo_Facil_Crypto::open( $value, 'portal_correction' );
							$value  = is_string( $opened ) ? $opened : '';
						}
						$text .= ' (' . ( $field_labels[ $detail['field'] ] ?? $detail['field'] ) . ' → ' . $value . ( ! empty( $detail['note'] ) ? ' · ' . $detail['note'] : '' ) . ')';
					}
					?>
					<li><?php echo esc_html( wp_date( 'j M, H:i', strtotime( $ev->created_at . ' UTC' ) ) . ' — ' . $text ); ?></li>
				<?php endforeach; ?>
			</ul>
			<button type="button" class="button button-small" data-af-profile-reviewed><?php esc_html_e( 'Marcar como revisado', 'arriendo-facil' ); ?></button>
		</div>
		<script>
		document.addEventListener('click', function (e) {
			var b = e.target.closest('[data-af-profile-reviewed]');
			if (!b) { return; }
			var box = b.closest('[data-af-profile-notice]');
			var fd = new FormData();
			fd.append('action', 'af_portal_review_profile');
			fd.append('nonce', <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>);
			fd.append('guest_id', box.getAttribute('data-guest'));
			b.disabled = true;
			fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (j) { if (j && j.success) { box.remove(); } else { b.disabled = false; } });
		});
		</script>
		<?php
	}
}
