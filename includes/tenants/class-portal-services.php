<?php
/**
 * Portal del inquilino — pagos de servicios que le corresponden a él.
 *
 * Solo aparecen los servicios cuya regla tiene `payer = tenant` y que pertenecen
 * al contrato vigente del inquilino de la sesión. El inquilino envía el pago con
 * su comprobante; únicamente el gestor lo aprueba o rechaza.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Portal_Services
 */
class Arriendo_Facil_Portal_Services {

	const PERIODS_BACK = 2; // Mes actual y los dos anteriores.

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_nopriv_af_tpa_service_pay', array( $this, 'ajax_pay' ) );
		add_action( 'wp_ajax_af_tpa_service_pay', array( $this, 'ajax_pay' ) );
	}

	/**
	 * Períodos que el inquilino puede ver/pagar (YYYY-MM), el más reciente primero.
	 *
	 * @return string[]
	 */
	public static function periods() {
		$out = array();
		for ( $i = 0; $i <= self::PERIODS_BACK; $i++ ) {
			$out[] = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01' ) . " -{$i} month" ) );
		}

		return $out;
	}

	/**
	 * Servicios que paga este inquilino, con el estado de su comprobante.
	 *
	 * @param int $guest_id Inquilino de la sesión.
	 * @param int[] $accommodation_ids Inmuebles de sus contratos vigentes.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_guest( $guest_id, array $accommodation_ids ) {
		global $wpdb;

		$guest_id          = absint( $guest_id );
		$accommodation_ids = array_values( array_unique( array_filter( array_map( 'absint', $accommodation_ids ) ) ) );
		if ( ! $guest_id || empty( $accommodation_ids ) ) {
			return array();
		}

		$list = array();
		foreach ( self::periods() as $period ) {
			foreach ( Arriendo_Facil_Billing_Ledger::get_service_due_rows( $period, $accommodation_ids ) as $row ) {
				// El contrato vigente de la fila debe ser el de este inquilino y la regla estar activa.
				if ( (int) $row['guest_id'] !== $guest_id || ! (int) $row['is_active'] || ! in_array( $row['payer'], array( 'tenant', 'via_owner' ), true ) ) {
					continue;
				}
				$row['period'] = $period;
				$list[]        = $row;
			}
		}

		if ( empty( $list ) ) {
			return array();
		}

		// Estado del pago de cada servicio/período.
		$schedule_ids = implode( ',', array_map( 'intval', wp_list_pluck( $list, 'schedule_id' ) ) );
		$bills        = (array) $wpdb->get_results( 'SELECT * FROM ' . Arriendo_Facil_Billing_Ledger::bill_payments_table() . " WHERE schedule_id IN ({$schedule_ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- enteros.
		$by_key       = array();
		foreach ( $bills as $bill ) {
			$by_key[ $bill->schedule_id . '|' . $bill->period ] = $bill;
		}

		foreach ( $list as &$row ) {
			$bill                  = isset( $by_key[ $row['schedule_id'] . '|' . $row['period'] ] ) ? $by_key[ $row['schedule_id'] . '|' . $row['period'] ] : null;
			$row['pay_status']     = $bill ? (string) $bill->status : 'pending'; // pending | submitted | approved | rejected.
			$row['bill_id']        = $bill ? (int) $bill->id : 0;
			$row['review_note']    = $bill && ! empty( $bill->review_note ) ? (string) $bill->review_note : '';
			$row['paid_on']        = $bill ? (string) $bill->paid_on : '';
			$row['paid_amount']    = $bill ? (float) $bill->amount : 0.0;
			$row['evidence']       = $bill ? Arriendo_Facil_Portal_Evidence::for_subject( 'service_payment', (int) $bill->id, $guest_id ) : array();
			$row['timeline']       = $bill ? Arriendo_Facil_Portal_Events::timeline( 'service_payment', (int) $bill->id, $guest_id ) : array();
			$row['deliveries']     = array();
			if ( 'via_owner' === $row['payer'] ) {
				// El inquilino entrega el dinero al propietario, que paga a la empresa: el estado sale de las
				// entregas y de si el gestor ya marcó el pago a la empresa.
				$deliveries         = Arriendo_Facil_Portal_Collections::for_service( (int) $row['schedule_id'], $row['period'], $guest_id );
				$row['deliveries']  = $deliveries;
				$row['pay_status']  = Arriendo_Facil_Portal_Collections::card_status( $bill ? (string) $bill->status : '', $deliveries );
				$row['delivered']   = array_sum( array_map( static function ( $d ) { return 'received' === $d->status ? (float) $d->amount : 0.0; }, $deliveries ) );
				$row['evidence']    = array();
				$row['timeline']    = array();
				foreach ( $deliveries as $d ) {
					$row['evidence'] = array_merge( $row['evidence'], Arriendo_Facil_Portal_Evidence::for_subject( 'delivery', (int) $d->id, $guest_id ) );
					$row['timeline'] = array_merge( $row['timeline'], Arriendo_Facil_Portal_Events::timeline( 'delivery', (int) $d->id, $guest_id ) );
				}
				usort( $row['timeline'], static function ( $a, $b ) { return (int) $a->id <=> (int) $b->id; } );
				$row['paid_amount'] = 0.0; // En este modo "paid" es el pago a la empresa, que informa el gestor.
				$row['review_note'] = '';
				if ( 'approved' === $row['pay_status'] && $bill ) {
					$row['company_paid_on'] = (string) $bill->paid_on;
				}
			}
		}
		unset( $row );

		// Pendientes primero, luego por vencimiento.
		usort(
			$list,
			static function ( $a, $b ) {
				$rank = array( 'pending' => 0, 'rejected' => 0, 'submitted' => 1, 'received' => 1, 'approved' => 2 );
				$ra   = isset( $rank[ $a['pay_status'] ] ) ? $rank[ $a['pay_status'] ] : 1;
				$rb   = isset( $rank[ $b['pay_status'] ] ) ? $rank[ $b['pay_status'] ] : 1;

				return $ra === $rb ? strcmp( (string) $a['due_date'], (string) $b['due_date'] ) : $ra <=> $rb;
			}
		);

		return $list;
	}

	/**
	 * Cuántos servicios esperan acción del inquilino.
	 *
	 * @param array<int,array<string,mixed>> $rows Salida de for_guest().
	 * @return int
	 */
	public static function count_actionable( array $rows ) {
		return count(
			array_filter(
				$rows,
				static function ( $r ) {
					return in_array( $r['pay_status'], array( 'pending', 'rejected' ), true );
				}
			)
		);
	}

	/**
	 * AJAX: el inquilino envía el pago con comprobante.
	 *
	 * @return void
	 */
	public function ajax_pay() {
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
		$paid_on     = isset( $_POST['paid_on'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_on'] ) ) : '';
		$amount      = isset( $_POST['amount'] ) ? round( (float) str_replace( ',', '.', wp_unslash( $_POST['amount'] ) ), 2 ) : 0.0;
		$reference   = isset( $_POST['reference'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['reference'] ) ), 0, 190 ) : '';

		if ( ! in_array( $period, self::periods(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Ese mes ya no se puede registrar desde el portal. Contacta a tu administrador.', 'arriendo-facil' ) ), 400 );
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $paid_on, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || $paid_on > current_time( 'Y-m-d' ) ) {
			wp_send_json_error( array( 'message' => __( 'La fecha de pago no es válida.', 'arriendo-facil' ) ), 400 );
		}
		if ( $amount <= 0 || $amount > 100000 ) {
			wp_send_json_error( array( 'message' => __( 'Escribe el monto que pagaste.', 'arriendo-facil' ) ), 400 );
		}

		// El servicio debe ser uno de los que paga ESTE inquilino en SU contrato vigente.
		$lease_rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, accommodation_id FROM {$wpdb->prefix}af_leases WHERE guest_id = %d AND status IN ('active','pending_release') AND deleted_at IS NULL", $guest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$mine       = null;
		foreach ( self::for_guest( $guest_id, array_map( 'intval', wp_list_pluck( $lease_rows, 'accommodation_id' ) ) ) as $row ) {
			if ( (int) $row['schedule_id'] === $schedule_id && $row['period'] === $period ) {
				$mine = $row;
				break;
			}
		}
		if ( ! $mine || 'tenant' !== $mine['payer'] ) {
			wp_send_json_error( array( 'message' => __( 'Ese servicio no está a tu cargo.', 'arriendo-facil' ) ), 403 );
		}
		if ( in_array( $mine['pay_status'], array( 'submitted', 'approved' ), true ) ) {
			wp_send_json_error( array( 'message' => 'approved' === $mine['pay_status'] ? __( 'Este pago ya fue aprobado.', 'arriendo-facil' ) : __( 'Ya enviaste este pago: espera la revisión de tu administrador.', 'arriendo-facil' ) ), 409 );
		}

		$files = Arriendo_Facil_Portal_Evidence::from_request( 'files', 1 );
		if ( is_wp_error( $files ) ) {
			wp_send_json_error( array( 'message' => $files->get_error_message() ), 400 );
		}

		// Un envío por servicio y mes: la fila existente (rechazada) se reabre, no se duplica.
		$table = Arriendo_Facil_Billing_Ledger::bill_payments_table();
		$data  = array(
			'paid_on'      => $paid_on,
			'amount'       => $amount,
			'reference'    => '' !== $reference ? $reference : null,
			'status'       => 'submitted',
			'submitted_by' => 'tenant',
			'guest_id'     => $guest_id,
			'reviewed_by'  => null,
			'reviewed_at'  => null,
			'review_note'  => null,
		);
		if ( $mine['bill_id'] ) {
			$done    = $wpdb->update( $table, $data, array( 'id' => (int) $mine['bill_id'], 'guest_id' => $guest_id ) );
			$bill_id = (int) $mine['bill_id'];
		} else {
			$done    = $wpdb->insert( $table, array_merge( $data, array( 'schedule_id' => $schedule_id, 'period' => $period, 'recorded_by' => null ) ) );
			$bill_id = (int) $wpdb->insert_id;
		}
		if ( false === $done || ! $bill_id ) {
			wp_send_json_error( array( 'message' => __( 'No pudimos registrar tu pago. Inténtalo de nuevo.', 'arriendo-facil' ) ), 500 );
		}

		$saved = Arriendo_Facil_Portal_Evidence::save( $files, $guest_id, (int) $mine['lease_id'], 'service_payment', $bill_id, 'payment', 'tenant' );
		if ( is_wp_error( $saved ) ) {
			if ( ! $mine['bill_id'] ) {
				$wpdb->delete( $table, array( 'id' => $bill_id ), array( '%d' ) );
			}
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 500 );
		}

		Arriendo_Facil_Portal_Events::log( $guest_id, (int) $mine['lease_id'], 'service_payment', $bill_id, 'tenant', 0, 'payment_submitted', array( 'service' => $mine['service_label'], 'period' => $period, 'amount' => $amount, 'files' => count( $saved ) ) );

		Arriendo_Facil_Portal_Manager::notify_manager(
			(int) $mine['resolved_accommodation_id'],
			'tenant_payment',
			__( 'Un inquilino envió un comprobante de pago', 'arriendo-facil' ),
			sprintf( /* translators: 1: service, 2: amount */ __( '%1$s: $%2$s. Revisa el comprobante y apruébalo o recházalo.', 'arriendo-facil' ), $mine['service_label'], number_format_i18n( $amount, 2 ) ),
			'af-meter-readings',
			'tenant-payment-' . $bill_id . '-' . time()
		);

		wp_send_json_success( array( 'message' => __( 'Pago enviado. Tu administrador lo revisará.', 'arriendo-facil' ) ) );
	}
}
