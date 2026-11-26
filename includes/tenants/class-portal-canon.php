<?php
/**
 * Portal del inquilino — pago de cánones de arriendo.
 *
 * El inquilino envía el comprobante de cada canon pendiente. El gestor lo
 * revisa y lo aprueba o rechaza. Al aprobar se genera el pago real en
 * af_payments via record_payment() y se recalcula el estado del cargo.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Portal_Canon
 */
class Arriendo_Facil_Portal_Canon {

	/** Nombre de la tabla de envíos pendientes. */
	public static function submissions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'af_payment_submissions';
	}

	/**
	 * Registra la acción AJAX.
	 */
	public function __construct() {
		add_action( 'wp_ajax_nopriv_af_tpa_canon_pay', array( $this, 'ajax_pay' ) );
		add_action( 'wp_ajax_af_tpa_canon_pay', array( $this, 'ajax_pay' ) );
	}

	/**
	 * Cargos de arriendo del inquilino con estado de envío.
	 *
	 * @param int   $guest_id  Inquilino de la sesión.
	 * @param int[] $lease_ids Contratos vigentes.
	 * @param array $charges   Cargos ya cargados por get_session_data().
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_guest( int $guest_id, array $lease_ids, array $charges ): array {
		global $wpdb;

		if ( ! $guest_id || empty( $lease_ids ) || empty( $charges ) ) {
			return array();
		}

		$charge_ids = array();
		foreach ( $charges as $c ) {
			$charge_ids[] = (int) $c->id;
		}
		if ( empty( $charge_ids ) ) {
			return array();
		}

		// Estado de cada cargo desde la tabla de envíos.
		$in   = implode( ',', array_map( 'intval', $charge_ids ) );
		$subs = (array) $wpdb->get_results(
			"SELECT * FROM " . self::submissions_table() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			" WHERE charge_id IN ({$in}) ORDER BY created_at DESC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		// Índice: charge_id → la última submission (ya ordenadas DESC por created_at).
		$by_charge = array();
		foreach ( $subs as $sub ) {
			$cid = (int) $sub->charge_id;
			if ( ! isset( $by_charge[ $cid ] ) ) {
				$by_charge[ $cid ] = $sub;
			}
		}

		$list = array();
		foreach ( $charges as $c ) {
			$sub  = $by_charge[ (int) $c->id ] ?? null;
			$list[] = array(
				'id'            => (int) $c->id,
				'lease_id'      => (int) $c->lease_id,
				'charge_type'   => (string) $c->charge_type,
				'period'        => (string) $c->period,
				'description'   => (string) $c->description,
				'amount'        => (float) $c->amount,
				'amount_paid'   => (float) $c->amount_paid,
				'due_date'      => (string) $c->due_date,
				'charge_status' => (string) $c->status,
				'sub_id'        => $sub ? (int) $sub->id : 0,
				'pay_status'    => $sub ? (string) $sub->status : 'pending',
				'review_note'   => $sub && ! empty( $sub->review_note ) ? (string) $sub->review_note : '',
				'paid_on'       => $sub ? (string) $sub->paid_on : '',
				'paid_amount'   => $sub ? (float) $sub->amount : 0.0,
				'paid_method'   => $sub ? (string) $sub->method : '',
				'paid_reference' => $sub ? (string) $sub->reference : '',
				'evidence'      => $sub ? Arriendo_Facil_Portal_Evidence::for_subject( 'canon_payment', (int) $sub->id, $guest_id ) : array(),
				'timeline'      => $sub ? Arriendo_Facil_Portal_Events::timeline( 'canon_payment', (int) $sub->id, $guest_id ) : array(),
			);
		}

		// Pendientes/rechazados primero, luego por periodo.
		usort(
			$list,
			static function ( $a, $b ) {
				$rank = array( 'pending' => 0, 'rejected' => 0, 'submitted' => 1, 'approved' => 2 );
				$ra   = $rank[ $a['pay_status'] ] ?? 1;
				$rb   = $rank[ $b['pay_status'] ] ?? 1;
				return $ra === $rb ? strcmp( $b['period'], $a['period'] ) : $ra <=> $rb;
			}
		);

		return $list;
	}

	/**
	 * Cuántos cargos esperan acción del inquilino.
	 *
	 * @param array $rows Salida de for_guest().
	 * @return int
	 */
	public static function count_actionable( array $rows ): int {
		return count(
			array_filter(
				$rows,
				static function ( $r ) {
					return in_array( $r['pay_status'], array( 'pending', 'rejected' ), true )
						&& ! in_array( $r['charge_status'], array( 'paid', 'void' ), true );
				}
			)
		);
	}

	/**
	 * AJAX: el inquilino envía el comprobante de un canon.
	 *
	 * @return void
	 */
	public function ajax_pay(): void {
		global $wpdb;

		$session = Arriendo_Facil_Tenant_Access::current_session();
		if ( ! $session || ! Arriendo_Facil_Tenant_Access::csrf_ok( $session ) ) {
			wp_send_json_error( array( 'code' => 'session', 'message' => __( 'Tu sesión venció. Vuelve a entrar con un código.', 'arriendo-facil' ) ), 401 );
		}
		$guest_id = (int) $session->guest_id;

		if ( Arriendo_Facil_Tenant_Access::throttled( 'upload', (string) $guest_id, Arriendo_Facil_Portal_Evidence::DAILY_UPLOADS, DAY_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Llegaste al máximo de envíos por hoy. Inténtalo mañana o contacta a tu administrador.', 'arriendo-facil' ) ), 429 );
		}

		$charge_id = isset( $_POST['charge_id'] ) ? absint( wp_unslash( $_POST['charge_id'] ) ) : 0;
		$paid_on   = isset( $_POST['paid_on'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_on'] ) ) : '';
		$amount    = isset( $_POST['amount'] ) ? round( (float) str_replace( ',', '.', (string) wp_unslash( $_POST['amount'] ) ), 2 ) : 0.0;
		$method    = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'transferencia';
		$reference = isset( $_POST['reference'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['reference'] ) ), 0, 190 ) : '';

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $paid_on, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || $paid_on > current_time( 'Y-m-d' ) ) {
			wp_send_json_error( array( 'message' => __( 'La fecha de pago no es válida.', 'arriendo-facil' ) ), 400 );
		}
		if ( $amount <= 0 || $amount > 1000000 ) {
			wp_send_json_error( array( 'message' => __( 'Escribe el monto que pagaste.', 'arriendo-facil' ) ), 400 );
		}
		if ( ! array_key_exists( $method, Arriendo_Facil_Billing_Ledger::payment_methods() ) ) {
			$method = 'transferencia';
		}

		// Verificar que el cargo pertenece al inquilino.
		$charge = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT c.id, c.lease_id, c.charge_type, c.period, c.amount, c.amount_paid, c.status, l.accommodation_id
				 FROM {$wpdb->prefix}af_charges c
				 INNER JOIN {$wpdb->prefix}af_leases l ON l.id = c.lease_id
				 WHERE c.id = %d AND l.guest_id = %d AND l.deleted_at IS NULL AND c.status <> 'void'",
				$charge_id,
				$guest_id
			)
		);
		if ( ! $charge ) {
			wp_send_json_error( array( 'message' => __( 'Ese cargo no existe o no te pertenece.', 'arriendo-facil' ) ), 403 );
		}
		if ( 'paid' === $charge->status ) {
			wp_send_json_error( array( 'message' => __( 'Este cargo ya está saldado.', 'arriendo-facil' ) ), 409 );
		}

		// Verificar comprobante requerido.
		$files = Arriendo_Facil_Portal_Evidence::from_request( 'files', 1 );
		if ( is_wp_error( $files ) ) {
			wp_send_json_error( array( 'message' => $files->get_error_message() ), 400 );
		}

		// Un envío por cargo: si ya existe uno rechazado, se reabre.
		$table = self::submissions_table();
		$data  = array(
			'amount'       => $amount,
			'paid_on'      => $paid_on,
			'method'       => $method,
			'reference'    => '' !== $reference ? $reference : null,
			'status'       => 'submitted',
			'reviewed_by'  => null,
			'reviewed_at'  => null,
			'review_note'  => null,
		);

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE charge_id = %d AND guest_id = %d ORDER BY created_at DESC LIMIT 1", $charge_id, $guest_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $existing && 'submitted' === $existing->status ) {
			wp_send_json_error( array( 'message' => __( 'Ya enviaste un comprobante para este cargo. Espera la revisión de tu administrador.', 'arriendo-facil' ) ), 409 );
		}
		if ( $existing ) {
			$done   = $wpdb->update( $table, $data, array( 'id' => (int) $existing->id, 'guest_id' => $guest_id ) );
			$sub_id = (int) $existing->id;
		} else {
			$done   = $wpdb->insert( $table, array_merge( $data, array( 'charge_id' => $charge_id, 'guest_id' => $guest_id, 'lease_id' => (int) $charge->lease_id ) ) );
			$sub_id = (int) $wpdb->insert_id;
		}
		if ( false === $done || ! $sub_id ) {
			wp_send_json_error( array( 'message' => __( 'No pudimos registrar tu pago. Inténtalo de nuevo.', 'arriendo-facil' ) ), 500 );
		}

		$saved = Arriendo_Facil_Portal_Evidence::save( $files, $guest_id, (int) $charge->lease_id, 'canon_payment', $sub_id, 'payment', 'tenant' );
		if ( is_wp_error( $saved ) ) {
			if ( ! $existing ) {
				$wpdb->delete( $table, array( 'id' => $sub_id ), array( '%d' ) );
			}
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 500 );
		}

		$type_labels = Arriendo_Facil_Billing_Ledger::charge_types();
		$type_label  = $type_labels[ $charge->charge_type ] ?? $charge->charge_type;

		Arriendo_Facil_Portal_Events::log(
			$guest_id,
			(int) $charge->lease_id,
			'canon_payment',
			$sub_id,
			'tenant',
			0,
			'payment_submitted',
			array( 'charge_type' => $type_label, 'period' => $charge->period, 'amount' => $amount, 'files' => count( $saved ) )
		);

		Arriendo_Facil_Portal_Manager::notify_manager(
			(int) $charge->accommodation_id,
			'canon_payment',
			__( 'Un inquilino envió un comprobante de arriendo', 'arriendo-facil' ),
			sprintf(
				/* translators: 1: type, 2: period, 3: amount */
				__( '%1$s %2$s: $%3$s. Revisa el comprobante y apruébalo o recházalo.', 'arriendo-facil' ),
				$type_label,
				$charge->period,
				number_format_i18n( $amount, 2 )
			),
			'af-meter-readings',
			'canon-payment-' . $sub_id . '-' . time()
		);

		wp_send_json_success( array( 'message' => __( 'Pago enviado. Tu administrador lo revisará pronto.', 'arriendo-facil' ) ) );
	}
}
