<?php
/**
 * Servicios que paga el propietario/gestor con dinero que le entrega el inquilino.
 *
 * Cubre el caso del agua (u otro) en que el inquilino no paga a la empresa: entrega el
 * dinero al propietario/gestor y este paga. Cada entrega queda registrada con su método,
 * evidencia opcional y un RECIBO numerado que respalda a las dos partes:
 *
 *   inquilino informa la entrega  →  el gestor confirma que la recibió  →  recibo emitido
 *   (o el gestor la registra al recibir el dinero en persona)
 *
 * El pago a la empresa lo sigue marcando el gestor en el tablero de servicios; el tramo
 * del inquilino vive aquí, separado, para no mezclar "me entregó" con "pagué a la empresa".
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Portal_Collections
 */
class Arriendo_Facil_Portal_Collections {

	const MAX_AMOUNT = 100000;

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_nopriv_af_tpa_delivery', array( $this, 'ajax_delivery' ) );
		add_action( 'wp_ajax_af_tpa_delivery', array( $this, 'ajax_delivery' ) );
		add_action( 'wp_ajax_nopriv_af_tpa_receipt', array( $this, 'ajax_tenant_receipt' ) );
		add_action( 'wp_ajax_af_tpa_receipt', array( $this, 'ajax_tenant_receipt' ) );
		add_action( 'wp_ajax_af_portal_delivery_review', array( $this, 'ajax_review' ) );
		add_action( 'wp_ajax_af_portal_delivery_register', array( $this, 'ajax_register' ) );
		add_action( 'wp_ajax_af_portal_receipt', array( $this, 'ajax_manager_receipt' ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Piezas puras
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * @return array<string,string>
	 */
	public static function methods() {
		return array(
			'efectivo'      => __( 'Efectivo', 'arriendo-facil' ),
			'transferencia' => __( 'Transferencia', 'arriendo-facil' ),
			'deposito'      => __( 'Depósito', 'arriendo-facil' ),
		);
	}

	/**
	 * El efectivo puede entregarse en mano y confirmarse con el recibo; los medios
	 * bancarios exigen un comprobante.
	 *
	 * @param string $method Método.
	 * @return bool
	 */
	public static function needs_proof( $method ) {
		return in_array( $method, array( 'transferencia', 'deposito' ), true );
	}

	/**
	 * @param int    $id   Entrega.
	 * @param string $date Fecha de emisión (Y-m-d).
	 * @return string Ej.: RC-202611-00042.
	 */
	public static function receipt_number( $id, $date ) {
		return 'RC-' . gmdate( 'Ym', strtotime( $date . ' UTC' ) ) . '-' . str_pad( (string) absint( $id ), 5, '0', STR_PAD_LEFT );
	}

	/**
	 * Sello de integridad del recibo: cualquier cambio a los datos lo invalida.
	 *
	 * @param object $row Fila de la entrega (con receipt_no, reviewed_at ya definidos).
	 * @return string
	 */
	public static function receipt_hash( $row ) {
		$payload = implode(
			'|',
			array(
				(string) $row->receipt_no,
				(int) $row->id,
				(int) $row->schedule_id,
				(string) $row->period,
				(int) $row->guest_id,
				number_format( (float) $row->amount, 2, '.', '' ),
				(string) $row->method,
				(string) $row->delivered_on,
				(int) $row->reviewed_by,
				(string) $row->reviewed_at,
			)
		);

		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	/**
	 * ¿El recibo mostrado coincide con lo emitido?
	 *
	 * @param object $row Fila.
	 * @return bool
	 */
	public static function receipt_valid( $row ) {
		return ! empty( $row->receipt_hash ) && hash_equals( (string) $row->receipt_hash, self::receipt_hash( $row ) );
	}

	/**
	 * Estado de una tarjeta de servicio "vía propietario", según sus entregas y el pago a la empresa.
	 *
	 * @param string                  $bill_status Estado del pago a la empresa (approved | otro).
	 * @param array<int,object>       $rows        Entregas del servicio/período.
	 * @return string pending | submitted | received | approved
	 */
	public static function card_status( $bill_status, array $rows ) {
		if ( 'approved' === $bill_status ) {
			return 'approved';
		}
		$has_received = false;
		foreach ( $rows as $r ) {
			if ( 'submitted' === $r->status ) {
				return 'submitted';
			}
			if ( 'received' === $r->status ) {
				$has_received = true;
			}
		}

		return $has_received ? 'received' : 'pending';
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Lectura
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Entregas de un servicio y período, la más reciente primero.
	 *
	 * @param int    $schedule_id Regla.
	 * @param string $period      YYYY-MM.
	 * @param int    $guest_id    Inquilino (0 = sin filtro; solo para el gestor).
	 * @return array<int,object>
	 */
	public static function for_service( $schedule_id, $period, $guest_id = 0 ) {
		global $wpdb;

		$sql = 'SELECT * FROM ' . Arriendo_Facil_Portal_Schema::collections_table() . ' WHERE schedule_id = %d AND period = %s'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$arg = array( absint( $schedule_id ), (string) $period );
		if ( $guest_id ) {
			$sql  .= ' AND guest_id = %d';
			$arg[] = absint( $guest_id );
		}

		return (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id DESC', $arg ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Entregas por confirmar, para la cola del gestor.
	 *
	 * @return array<int,object>
	 */
	public static function pending() {
		global $wpdb;

		$ids = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
		if ( is_array( $ids ) && empty( $ids ) ) {
			return array();
		}
		$scope = is_array( $ids ) ? ' AND COALESCE( NULLIF( s.accommodation_id, 0 ), u.accommodation_id ) IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')' : '';

		return (array) $wpdb->get_results(
			'SELECT c.*, s.service, s.service_label, p.post_title AS property, CONCAT( g.first_name, " ", g.last_name ) AS guest_name
			 FROM ' . Arriendo_Facil_Portal_Schema::collections_table() . ' c
			 INNER JOIN ' . Arriendo_Facil_Billing_Ledger::schedules_table() . ' s ON s.id = c.schedule_id
			 LEFT JOIN ' . Arriendo_Facil_Property_Structure::units_table() . " u ON u.id = s.unit_id
			 LEFT JOIN {$wpdb->posts} p ON p.ID = COALESCE( NULLIF( s.accommodation_id, 0 ), u.accommodation_id )
			 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = c.guest_id
			 WHERE c.status = 'submitted'{$scope}
			 ORDER BY c.created_at ASC LIMIT 100" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids enteros.
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Emisión del recibo
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Confirma la recepción del dinero y emite el recibo.
	 *
	 * @param int $id      Entrega.
	 * @param int $user_id Gestor que recibe.
	 * @return object|WP_Error Fila actualizada.
	 */
	private static function issue( $id, $user_id ) {
		global $wpdb;

		$table = Arriendo_Facil_Portal_Schema::collections_table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$no    = self::receipt_number( $id, $now );

		$wpdb->update( $table, array( 'status' => 'received', 'receipt_no' => $no, 'reviewed_by' => (int) $user_id, 'reviewed_at' => $now, 'review_note' => null ), array( 'id' => (int) $id ), array( '%s', '%s', '%d', '%s', '%s' ), array( '%d' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || 'received' !== $row->status ) {
			return new WP_Error( 'af_receipt_failed', __( 'No se pudo emitir el recibo.', 'arriendo-facil' ) );
		}
		$wpdb->update( $table, array( 'receipt_hash' => self::receipt_hash( $row ) ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
		$row->receipt_hash = self::receipt_hash( $row );

		return $row;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Inquilino
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Servicio "vía propietario" de ESTE inquilino, en un período visible.
	 *
	 * @param int    $guest_id    Inquilino.
	 * @param int    $schedule_id Regla.
	 * @param string $period      Período.
	 * @return array<string,mixed>|null
	 */
	private static function tenant_service( $guest_id, $schedule_id, $period ) {
		global $wpdb;

		$leases = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, accommodation_id FROM {$wpdb->prefix}af_leases WHERE guest_id = %d AND status IN ('active','pending_release') AND deleted_at IS NULL", $guest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( Arriendo_Facil_Portal_Services::for_guest( $guest_id, array_map( 'intval', wp_list_pluck( $leases, 'accommodation_id' ) ) ) as $row ) {
			if ( 'via_owner' === $row['payer'] && (int) $row['schedule_id'] === $schedule_id && $row['period'] === $period ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * AJAX: el inquilino informa que entregó dinero al propietario/gestor.
	 *
	 * @return void
	 */
	public function ajax_delivery() {
		global $wpdb;

		$session = Arriendo_Facil_Tenant_Access::current_session();
		if ( ! $session || ! Arriendo_Facil_Tenant_Access::csrf_ok( $session ) ) {
			wp_send_json_error( array( 'code' => 'session', 'message' => __( 'Tu sesión venció. Vuelve a entrar con un código.', 'arriendo-facil' ) ), 401 );
		}
		$guest_id = (int) $session->guest_id;

		if ( Arriendo_Facil_Tenant_Access::throttled( 'upload', (string) $guest_id, Arriendo_Facil_Portal_Evidence::DAILY_UPLOADS, DAY_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Llegaste al máximo de envíos por hoy. Inténtalo mañana o contacta a tu administrador.', 'arriendo-facil' ) ), 429 );
		}

		$schedule_id = isset( $_POST['schedule_id'] ) ? absint( wp_unslash( $_POST['schedule_id'] ) ) : 0;
		$period      = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		$method      = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
		$date        = isset( $_POST['delivered_on'] ) ? sanitize_text_field( wp_unslash( $_POST['delivered_on'] ) ) : '';
		$amount      = isset( $_POST['amount'] ) ? round( (float) str_replace( ',', '.', wp_unslash( $_POST['amount'] ) ), 2 ) : 0.0;
		$reference   = isset( $_POST['reference'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['reference'] ) ), 0, 190 ) : '';

		if ( ! in_array( $period, Arriendo_Facil_Portal_Services::periods(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Ese mes ya no se puede registrar desde el portal. Contacta a tu administrador.', 'arriendo-facil' ) ), 400 );
		}
		if ( ! array_key_exists( $method, self::methods() ) ) {
			wp_send_json_error( array( 'message' => __( 'Elige cómo entregaste el dinero.', 'arriendo-facil' ) ), 400 );
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || $date > current_time( 'Y-m-d' ) ) {
			wp_send_json_error( array( 'message' => __( 'La fecha de entrega no es válida.', 'arriendo-facil' ) ), 400 );
		}
		if ( $amount <= 0 || $amount > self::MAX_AMOUNT ) {
			wp_send_json_error( array( 'message' => __( 'Escribe el monto que entregaste.', 'arriendo-facil' ) ), 400 );
		}

		$mine = self::tenant_service( $guest_id, $schedule_id, $period );
		if ( ! $mine ) {
			wp_send_json_error( array( 'message' => __( 'Ese servicio no está a tu cargo.', 'arriendo-facil' ) ), 403 );
		}
		if ( 'approved' === $mine['pay_status'] ) {
			wp_send_json_error( array( 'message' => __( 'Este servicio ya fue pagado a la empresa.', 'arriendo-facil' ) ), 409 );
		}
		if ( 'submitted' === $mine['pay_status'] ) {
			wp_send_json_error( array( 'message' => __( 'Ya informaste una entrega: espera que tu administrador la confirme.', 'arriendo-facil' ) ), 409 );
		}

		$min   = self::needs_proof( $method ) ? 1 : 0;
		$files = Arriendo_Facil_Portal_Evidence::from_request( 'files', $min );
		if ( is_wp_error( $files ) ) {
			wp_send_json_error( array( 'message' => $files->get_error_message() ), 400 );
		}

		$table = Arriendo_Facil_Portal_Schema::collections_table();
		$done  = $wpdb->insert(
			$table,
			array(
				'schedule_id'  => $schedule_id,
				'period'       => $period,
				'guest_id'     => $guest_id,
				'lease_id'     => (int) $mine['lease_id'],
				'amount'       => $amount,
				'method'       => $method,
				'delivered_on' => $date,
				'reference'    => '' !== $reference ? $reference : null,
				'submitted_by' => 'tenant',
				'status'       => 'submitted',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$id    = (int) $wpdb->insert_id;
		if ( ! $done || ! $id ) {
			wp_send_json_error( array( 'message' => __( 'No pudimos registrar tu entrega. Inténtalo de nuevo.', 'arriendo-facil' ) ), 500 );
		}

		if ( ! empty( $files ) ) {
			$saved = Arriendo_Facil_Portal_Evidence::save( $files, $guest_id, (int) $mine['lease_id'], 'delivery', $id, 'payment', 'tenant' );
			if ( is_wp_error( $saved ) ) {
				$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
				wp_send_json_error( array( 'message' => $saved->get_error_message() ), 500 );
			}
		}

		Arriendo_Facil_Portal_Events::log( $guest_id, (int) $mine['lease_id'], 'delivery', $id, 'tenant', 0, 'delivery_submitted', array( 'service' => $mine['service_label'], 'period' => $period, 'amount' => $amount, 'method' => $method ) );

		Arriendo_Facil_Portal_Manager::notify_manager(
			(int) $mine['resolved_accommodation_id'],
			'tenant_delivery',
			__( 'Un inquilino informó que te entregó dinero', 'arriendo-facil' ),
			sprintf( /* translators: 1: service, 2: amount */ __( '%1$s: $%2$s. Confirma que lo recibiste para emitirle su recibo.', 'arriendo-facil' ), $mine['service_label'], number_format_i18n( $amount, 2 ) ),
			'af-meter-readings',
			'tenant-delivery-' . $id
		);

		wp_send_json_success( array( 'message' => __( 'Entrega informada. Cuando tu administrador la confirme recibirás tu recibo.', 'arriendo-facil' ) ) );
	}

	/**
	 * Recibo para el inquilino (solo los suyos; misma respuesta si no existe o no es suyo).
	 *
	 * @return void
	 */
	public function ajax_tenant_receipt() {
		$session = Arriendo_Facil_Tenant_Access::current_session();
		$id      = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.NonceVerification.Recommended -- lectura; exige sesión y propiedad.
		$row     = $session && $id ? self::get( $id ) : null;

		if ( ! $row || (int) $row->guest_id !== (int) $session->guest_id || 'received' !== $row->status ) {
			status_header( 404 );
			wp_die( esc_html__( 'Recibo no disponible.', 'arriendo-facil' ), '', array( 'response' => 404 ) );
		}
		self::render_receipt( $row );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Gestor
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * @param int $id Entrega.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Arriendo_Facil_Portal_Schema::collections_table() . ' WHERE id = %d', absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Regla de servicio de una entrega, con el acceso del gestor ya validado.
	 *
	 * @param int $schedule_id Regla.
	 * @return object Regla.
	 */
	private static function manager_schedule( $schedule_id ) {
		global $wpdb;

		check_ajax_referer( Arriendo_Facil_Portal_Manager::NONCE, 'nonce' );
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}
		$schedule = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Arriendo_Facil_Billing_Ledger::schedules_table() . ' WHERE id = %d', absint( $schedule_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$acc      = $schedule ? Arriendo_Facil_Portal_Manager::schedule_accommodation( $schedule ) : 0;
		if ( ! $schedule || ! $acc || ! Arriendo_Facil_Tenancy::can_access_accommodation( $acc ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este servicio.', 'arriendo-facil' ) ), 403 );
		}

		return $schedule;
	}

	/**
	 * AJAX: confirma (emite recibo) o no confirma una entrega informada por el inquilino.
	 *
	 * @return void
	 */
	public function ajax_review() {
		$id       = isset( $_POST['delivery_id'] ) ? absint( wp_unslash( $_POST['delivery_id'] ) ) : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$note     = isset( $_POST['note'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ) ), 0, 255 ) : '';
		$row      = self::get( $id );

		if ( ! $row || ! in_array( $decision, array( 'confirm', 'reject' ), true ) ) {
			check_ajax_referer( Arriendo_Facil_Portal_Manager::NONCE, 'nonce' );
			wp_send_json_error( array( 'message' => __( 'Datos no válidos.', 'arriendo-facil' ) ), 400 );
		}
		$schedule = self::manager_schedule( (int) $row->schedule_id );

		if ( 'submitted' !== $row->status ) {
			wp_send_json_error( array( 'message' => __( 'Esta entrega ya fue revisada.', 'arriendo-facil' ) ), 409 );
		}
		if ( 'reject' === $decision && mb_strlen( $note ) < 5 ) {
			wp_send_json_error( array( 'message' => __( 'Explica brevemente por qué no se confirma (por ejemplo, el monto recibido).', 'arriendo-facil' ) ), 400 );
		}

		$label = Arriendo_Facil_Billing_Ledger::service_label( $schedule->service, isset( $schedule->service_label ) ? $schedule->service_label : '' );

		if ( 'reject' === $decision ) {
			global $wpdb;
			$wpdb->update( Arriendo_Facil_Portal_Schema::collections_table(), array( 'status' => 'rejected', 'reviewed_by' => get_current_user_id(), 'reviewed_at' => gmdate( 'Y-m-d H:i:s' ), 'review_note' => $note ), array( 'id' => $id, 'status' => 'submitted' ), array( '%s', '%d', '%s', '%s' ), array( '%d', '%s' ) );
			self::record( $row, $label, 'delivery_rejected', array( 'note' => $note ) );
			Arriendo_Facil_Portal_Manager::notify_tenant( (int) $row->guest_id, __( 'Tu entrega no fue confirmada', 'arriendo-facil' ), sprintf( /* translators: 1: service, 2: note */ __( 'Tu entrega de dinero para %1$s no fue confirmada: %2$s. Puedes informarla de nuevo desde tu portal.', 'arriendo-facil' ), $label, $note ) );
			wp_send_json_success( array( 'message' => __( 'No se confirmó. Se avisó al inquilino.', 'arriendo-facil' ) ) );
		}

		$issued = self::issue( $id, get_current_user_id() );
		if ( is_wp_error( $issued ) ) {
			wp_send_json_error( array( 'message' => $issued->get_error_message() ), 500 );
		}
		self::record( $issued, $label, 'delivery_received', array( 'receipt' => $issued->receipt_no ) );
		self::notify_receipt( $issued, $label );

		wp_send_json_success( array( 'message' => sprintf( /* translators: %s: receipt number */ __( 'Recibido. Recibo %s emitido al inquilino.', 'arriendo-facil' ), $issued->receipt_no ) ) );
	}

	/**
	 * AJAX: el gestor registra dinero que recibió en persona y emite el recibo de inmediato.
	 *
	 * @return void
	 */
	public function ajax_register() {
		global $wpdb;

		$schedule_id = isset( $_POST['schedule_id'] ) ? absint( wp_unslash( $_POST['schedule_id'] ) ) : 0;
		$schedule    = self::manager_schedule( $schedule_id );
		$period      = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		$method      = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'efectivo';
		$date        = isset( $_POST['delivered_on'] ) ? sanitize_text_field( wp_unslash( $_POST['delivered_on'] ) ) : current_time( 'Y-m-d' );
		$amount      = isset( $_POST['amount'] ) ? round( (float) str_replace( ',', '.', wp_unslash( $_POST['amount'] ) ), 2 ) : 0.0;
		$reference   = isset( $_POST['reference'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['reference'] ) ), 0, 190 ) : '';

		if ( 'via_owner' !== ( isset( $schedule->payer ) ? $schedule->payer : '' ) ) {
			wp_send_json_error( array( 'message' => __( 'Este servicio no está configurado como "el inquilino te entrega el dinero".', 'arriendo-facil' ) ), 400 );
		}
		if ( 1 !== preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $period ) || ! array_key_exists( $method, self::methods() ) ) {
			wp_send_json_error( array( 'message' => __( 'Datos no válidos.', 'arriendo-facil' ) ), 400 );
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || $date > current_time( 'Y-m-d' ) ) {
			wp_send_json_error( array( 'message' => __( 'La fecha no es válida.', 'arriendo-facil' ) ), 400 );
		}
		if ( $amount <= 0 || $amount > self::MAX_AMOUNT ) {
			wp_send_json_error( array( 'message' => __( 'Escribe el monto recibido.', 'arriendo-facil' ) ), 400 );
		}

		// El contrato vigente de ese inmueble: a quien se le emite el recibo.
		$lease = Arriendo_Facil_Billing_Ledger::get_service_due_rows( $period, array( Arriendo_Facil_Portal_Manager::schedule_accommodation( $schedule ) ) );
		$lease = array_values( array_filter( $lease, static function ( $r ) use ( $schedule_id ) { return (int) $r['schedule_id'] === $schedule_id && ! empty( $r['guest_id'] ); } ) );
		if ( empty( $lease ) ) {
			wp_send_json_error( array( 'message' => __( 'No hay un inquilino con contrato vigente en este inmueble.', 'arriendo-facil' ) ), 409 );
		}
		$guest_id = (int) $lease[0]['guest_id'];

		$table = Arriendo_Facil_Portal_Schema::collections_table();
		$wpdb->insert(
			$table,
			array(
				'schedule_id'  => $schedule_id,
				'period'       => $period,
				'guest_id'     => $guest_id,
				'lease_id'     => (int) $lease[0]['lease_id'],
				'amount'       => $amount,
				'method'       => $method,
				'delivered_on' => $date,
				'reference'    => '' !== $reference ? $reference : null,
				'submitted_by' => 'manager',
				'status'       => 'submitted',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo registrar.', 'arriendo-facil' ) ), 500 );
		}

		$issued = self::issue( $id, get_current_user_id() );
		if ( is_wp_error( $issued ) ) {
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			wp_send_json_error( array( 'message' => $issued->get_error_message() ), 500 );
		}

		$label = Arriendo_Facil_Billing_Ledger::service_label( $schedule->service, isset( $schedule->service_label ) ? $schedule->service_label : '' );
		self::record( $issued, $label, 'delivery_received', array( 'receipt' => $issued->receipt_no, 'registered_by_manager' => true ) );
		self::notify_receipt( $issued, $label );

		wp_send_json_success(
			array(
				'message' => sprintf( /* translators: %s: receipt number */ __( 'Registrado. Recibo %s emitido al inquilino.', 'arriendo-facil' ), $issued->receipt_no ),
				'receipt' => self::manager_receipt_url( $id ),
			)
		);
	}

	/**
	 * Bitácora de una entrega.
	 *
	 * @param object               $row    Entrega.
	 * @param string               $label  Nombre del servicio.
	 * @param string               $action Acción.
	 * @param array<string,mixed>  $extra  Detalle adicional.
	 * @return void
	 */
	private static function record( $row, $label, $action, array $extra = array() ) {
		$detail = array_merge( array( 'service' => $label, 'period' => $row->period, 'amount' => (float) $row->amount, 'method' => $row->method ), $extra );
		Arriendo_Facil_Portal_Events::log( (int) $row->guest_id, (int) $row->lease_id, 'delivery', (int) $row->id, 'manager', get_current_user_id(), $action, $detail );
	}

	/**
	 * Aviso al inquilino con su recibo.
	 *
	 * @param object $row   Entrega recibida.
	 * @param string $label Servicio.
	 * @return void
	 */
	private static function notify_receipt( $row, $label ) {
		Arriendo_Facil_Portal_Manager::notify_tenant(
			(int) $row->guest_id,
			__( 'Tu administrador recibió tu dinero', 'arriendo-facil' ),
			sprintf( /* translators: 1: amount, 2: service, 3: receipt number */ __( 'Recibió $%1$s para %2$s. Tu recibo es el %3$s: puedes verlo y descargarlo desde tu portal, en Servicios.', 'arriendo-facil' ), number_format_i18n( (float) $row->amount, 2 ), $label, $row->receipt_no )
		);
	}

	/**
	 * Recibo para el gestor (enlace firmado).
	 *
	 * @return void
	 */
	public function ajax_manager_receipt() {
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		if ( ! $id || ! check_ajax_referer( 'af_portal_rc_' . $id, 'n', false ) ) {
			wp_die( esc_html__( 'Solicitud inválida.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}
		$row = self::get( $id );
		if ( ! $row || 'received' !== $row->status || ! current_user_can( Arriendo_Facil_Tenancy::CAP ) || ! Arriendo_Facil_Tenancy::can_access_guest( (int) $row->guest_id ) ) {
			wp_die( esc_html__( 'No tienes acceso a este recibo.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}
		self::render_receipt( $row );
	}

	/**
	 * @param int $id Entrega.
	 * @return string
	 */
	public static function manager_receipt_url( $id ) {
		return add_query_arg( array( 'action' => 'af_portal_receipt', 'id' => absint( $id ), 'n' => wp_create_nonce( 'af_portal_rc_' . absint( $id ) ) ), admin_url( 'admin-ajax.php' ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Recibo imprimible
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Página independiente e imprimible. Termina la petición.
	 *
	 * @param object $row Entrega con recibo emitido.
	 * @return void
	 */
	private static function render_receipt( $row ) {
		global $wpdb;

		$schedule = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Arriendo_Facil_Billing_Ledger::schedules_table() . ' WHERE id = %d', (int) $row->schedule_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$guest    = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name, id_number FROM {$wpdb->prefix}af_guests WHERE id = %d", (int) $row->guest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$label    = $schedule ? Arriendo_Facil_Billing_Ledger::service_label( $schedule->service, isset( $schedule->service_label ) ? $schedule->service_label : '' ) : '';
		$acc      = $schedule ? Arriendo_Facil_Portal_Manager::schedule_accommodation( $schedule ) : 0;
		$user     = $row->reviewed_by ? get_userdata( (int) $row->reviewed_by ) : false;
		$methods  = self::methods();
		$valid    = self::receipt_valid( $row );
		$id_plain = $guest ? (string) $guest->id_number : '';
		$id_mask  = $id_plain ? str_repeat( '•', max( 0, strlen( $id_plain ) - 3 ) ) . substr( $id_plain, -3 ) : '—';
		$issued   = wp_date( 'j \d\e F \d\e Y, H:i', strtotime( $row->reviewed_at . ' UTC' ) );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( sprintf( /* translators: %s: number */ __( 'Recibo %s', 'arriendo-facil' ), $row->receipt_no ) ); ?></title>
<style>
	body{margin:0;background:#f3f6f5;font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#17303a}
	.rc{max-width:560px;margin:24px auto;padding:28px;background:#fff;border:1px solid #d6e0de;border-radius:14px}
	h1{font-size:20px;margin:0 0 2px}.no{font:600 14px ui-monospace,monospace;color:#164a55}
	dl{display:grid;grid-template-columns:150px 1fr;gap:8px 12px;margin:20px 0}dt{color:#5b7077}dd{margin:0;font-weight:600;word-break:break-word}
	.amt{font-size:26px;color:#164a55}.txt{font-size:13px;color:#4f6267;border-top:1px solid #e1e9e7;padding-top:14px}
	.seal{font:12px ui-monospace,monospace;color:#5b7077;word-break:break-all}.bad{color:#b03a36;font-weight:700}
	button{margin-top:16px;min-height:42px;padding:0 18px;border:0;border-radius:10px;background:#164a55;color:#fff;font:600 14px inherit;cursor:pointer}
	@media print{body{background:#fff}.rc{border:0;margin:0}button{display:none}}
	@media(max-width:520px){dl{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="rc">
	<h1><?php esc_html_e( 'Recibo de dinero recibido', 'arriendo-facil' ); ?></h1>
	<div class="no"><?php echo esc_html( $row->receipt_no ); ?></div>
	<?php if ( ! $valid ) : ?>
		<p class="bad"><?php esc_html_e( 'Atención: el sello de este recibo no coincide. Sus datos pudieron alterarse.', 'arriendo-facil' ); ?></p>
	<?php endif; ?>
	<dl>
		<dt><?php esc_html_e( 'Monto', 'arriendo-facil' ); ?></dt><dd class="amt">$<?php echo esc_html( number_format_i18n( (float) $row->amount, 2 ) ); ?></dd>
		<dt><?php esc_html_e( 'Concepto', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $label . ' · ' . $row->period ); ?></dd>
		<dt><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $acc ? get_the_title( $acc ) : '—' ); ?></dd>
		<dt><?php esc_html_e( 'Entregado por', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $guest ? trim( $guest->first_name . ' ' . $guest->last_name ) : '—' ); ?> · <?php echo esc_html( $id_mask ); ?></dd>
		<dt><?php esc_html_e( 'Recibido por', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $user ? $user->display_name : '—' ); ?></dd>
		<dt><?php esc_html_e( 'Forma de entrega', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( isset( $methods[ $row->method ] ) ? $methods[ $row->method ] : $row->method ); ?><?php echo $row->reference ? ' · ' . esc_html( $row->reference ) : ''; ?></dd>
		<dt><?php esc_html_e( 'Fecha de entrega', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( wp_date( 'j \d\e F \d\e Y', strtotime( $row->delivered_on . ' 12:00:00' ) ) ); ?></dd>
		<dt><?php esc_html_e( 'Emitido', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $issued ); ?></dd>
	</dl>
	<p class="txt"><?php esc_html_e( 'Este recibo deja constancia de que el administrador o propietario recibió el dinero indicado del inquilino, para pagar el servicio señalado a la empresa proveedora. Queda registrado en Arriendo Fácil junto con su historial.', 'arriendo-facil' ); ?></p>
	<p class="seal"><?php echo esc_html( sprintf( /* translators: %s: code */ __( 'Código de verificación: %s', 'arriendo-facil' ), strtoupper( substr( (string) $row->receipt_hash, 0, 24 ) ) ) ); ?></p>
	<button type="button" onclick="window.print()"><?php esc_html_e( 'Imprimir o guardar PDF', 'arriendo-facil' ); ?></button>
</main>
</body>
</html>
		<?php
		exit;
	}
}
