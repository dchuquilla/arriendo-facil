<?php
/**
 * AJAX endpoints of the charges and payments ledger (cobranza).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Billing_Ledger_Controller
 *
 * HTTP layer only: validates the request and delegates to the static domain
 * API in Arriendo_Facil_Billing_Ledger.
 */
class Arriendo_Facil_Billing_Ledger_Controller {

	/**
	 * Registers AJAX endpoints.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_record_payment', array( $this, 'ajax_record_payment' ) );
		add_action( 'wp_ajax_af_cobranza_snapshot', array( $this, 'ajax_cobranza_snapshot' ) );
		add_action( 'wp_ajax_af_create_charge', array( $this, 'ajax_create_charge' ) );
		add_action( 'wp_ajax_af_generate_period_charges', array( $this, 'ajax_generate_period_charges' ) );
		add_action( 'wp_ajax_af_record_meter_reading', array( $this, 'ajax_record_meter_reading' ) );
		add_action( 'wp_ajax_af_delete_meter_reading', array( $this, 'ajax_delete_meter_reading' ) );
		add_action( 'wp_ajax_af_void_charge', array( $this, 'ajax_void_charge' ) );
		add_action( 'wp_ajax_af_save_service_schedule', array( $this, 'ajax_save_service_schedule' ) );
		add_action( 'wp_ajax_af_delete_service_schedule', array( $this, 'ajax_delete_service_schedule' ) );
		add_action( 'wp_ajax_af_generate_service_charges', array( $this, 'ajax_generate_service_charges' ) );
		add_action( 'wp_ajax_af_mark_service_paid', array( $this, 'ajax_mark_service_paid' ) );
		add_action( 'wp_ajax_af_unmark_service_paid', array( $this, 'ajax_unmark_service_paid' ) );
	}

	/**
	 * AJAX: records a payment against a charge.
	 *
	 * @return void
	 */
	public function ajax_record_payment() {
		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$charge_id = isset( $_POST['charge_id'] ) ? absint( wp_unslash( $_POST['charge_id'] ) ) : 0;
		if ( ! Arriendo_Facil_Tenancy::can_access_charge( $charge_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este cargo.', 'arriendo-facil' ) ), 403 );
		}

		$result = Arriendo_Facil_Billing_Ledger::record_payment(
			array(
				'charge_id'    => $charge_id,
				'amount'       => isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0,
				'payment_date' => isset( $_POST['payment_date'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_date'] ) ) : '',
				'method'       => isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'transferencia',
				'reference'    => isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '',
				'notes'        => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Pago registrado correctamente.', 'arriendo-facil' ),
				'payment_id' => $result,
			)
		);
	}

	/**
	 * AJAX: fresh collection snapshot, so the hub can refresh itself in place
	 * after a payment is recorded instead of reloading the page.
	 *
	 * @return void
	 */
	public function ajax_cobranza_snapshot() {
		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
			$period = '';
		}

		wp_send_json_success( Arriendo_Facil_Billing_Ledger::cobranza_snapshot( $period ? $period : null ) );
	}

	/**
	 * AJAX: creates a manual charge.
	 *
	 * @return void
	 */
	public function ajax_create_charge() {
		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		if ( ! Arriendo_Facil_Tenancy::can_access_lease( $lease_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este contrato.', 'arriendo-facil' ) ), 403 );
		}

		$result = Arriendo_Facil_Billing_Ledger::create_charge(
			array(
				'lease_id'    => $lease_id,
				'unit_id'     => isset( $_POST['unit_id'] ) ? absint( wp_unslash( $_POST['unit_id'] ) ) : 0,
				'guest_id'    => isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0,
				'charge_type' => isset( $_POST['charge_type'] ) ? sanitize_key( wp_unslash( $_POST['charge_type'] ) ) : '',
				'period'      => isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '',
				'amount'      => isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0,
				'due_date'    => isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '',
				'description' => isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'   => __( 'Cargo creado correctamente.', 'arriendo-facil' ),
				'charge_id' => $result,
			)
		);
	}

	/**
	 * AJAX: generates canon + alicuota charges for a period.
	 *
	 * @return void
	 */
	public function ajax_generate_period_charges() {
		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		$result = Arriendo_Facil_Billing_Ledger::generate_monthly_charges( $period, Arriendo_Facil_Tenancy::accessible_accommodation_ids() );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: created count, 2: skipped count */
					__( '%1$d cargos creados, %2$d omitidos (ya existian).', 'arriendo-facil' ),
					$result['created'],
					$result['skipped']
				),
				'created' => $result['created'],
				'skipped' => $result['skipped'],
			)
		);
	}

	/**
	 * AJAX: records a meter reading.
	 *
	 * @return void
	 */
	public function ajax_record_meter_reading() {
		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$unit_id          = isset( $_POST['unit_id'] ) ? absint( wp_unslash( $_POST['unit_id'] ) ) : 0;
		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;

		if ( $unit_id ) {
			if ( ! Arriendo_Facil_Tenancy::can_access_unit( $unit_id ) ) {
				wp_send_json_error( array( 'message' => __( 'No tienes acceso a esta unidad.', 'arriendo-facil' ) ), 403 );
			}
		} elseif ( $accommodation_id ) {
			if ( ! Arriendo_Facil_Tenancy::can_access_accommodation( $accommodation_id ) ) {
				wp_send_json_error( array( 'message' => __( 'No tienes acceso a este inmueble.', 'arriendo-facil' ) ), 403 );
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'Selecciona una unidad o un inmueble.', 'arriendo-facil' ) ), 400 );
		}

		$result = Arriendo_Facil_Billing_Ledger::record_meter_reading(
			array(
				'unit_id'          => $unit_id,
				'accommodation_id' => $accommodation_id,
				'service'          => isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '',
				'period'           => isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '',
				'current_reading'  => isset( $_POST['current_reading'] ) ? (float) wp_unslash( $_POST['current_reading'] ) : 0,
				'unit_rate'        => isset( $_POST['unit_rate'] ) ? (float) wp_unslash( $_POST['unit_rate'] ) : 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => $result['charge_id']
					? sprintf(
						/* translators: %s: billed amount */
						__( 'Lectura guardada y cargo de $%s generado.', 'arriendo-facil' ),
						number_format_i18n( $result['amount'], 2 )
					)
					: __( 'Lectura guardada. No hay contrato activo, no se genero cargo.', 'arriendo-facil' ),
			)
		);
	}

	/**
	 * AJAX: deletes a meter reading (voiding its auto-generated charge when it
	 * has no payments yet).
	 *
	 * @return void
	 */
	public function ajax_delete_meter_reading() {
		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		global $wpdb;

		$reading_id = isset( $_POST['reading_id'] ) ? absint( wp_unslash( $_POST['reading_id'] ) ) : 0;

		$reading = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM ' . Arriendo_Facil_Billing_Ledger::readings_table() . ' WHERE id = %d',
				$reading_id
			)
		);
		if ( ! $reading ) {
			wp_send_json_error( array( 'message' => __( 'Lectura no encontrada.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! empty( $reading->unit_id ) ) {
			if ( ! Arriendo_Facil_Tenancy::can_access_unit( (int) $reading->unit_id ) ) {
				wp_send_json_error( array( 'message' => __( 'No tienes acceso a esta unidad.', 'arriendo-facil' ) ), 403 );
			}
		} elseif ( ! Arriendo_Facil_Tenancy::can_access_accommodation( (int) $reading->accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este inmueble.', 'arriendo-facil' ) ), 403 );
		}

		$result = Arriendo_Facil_Billing_Ledger::delete_meter_reading( $reading_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Lectura borrada y cargo asociado anulado.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: voids a charge (only if it has no payments yet).
	 *
	 * @return void
	 */
	public function ajax_void_charge() {
		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$charge_id = isset( $_POST['charge_id'] ) ? absint( wp_unslash( $_POST['charge_id'] ) ) : 0;
		if ( ! Arriendo_Facil_Tenancy::can_access_charge( $charge_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este cargo.', 'arriendo-facil' ) ), 403 );
		}

		$result = Arriendo_Facil_Billing_Ledger::void_charge( $charge_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Cargo anulado correctamente.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: creates or updates a service due-date rule.
	 *
	 * @return void
	 */
	public function ajax_save_service_schedule() {
		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$unit_id          = isset( $_POST['unit_id'] ) ? absint( wp_unslash( $_POST['unit_id'] ) ) : 0;
		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;

		if ( $unit_id ) {
			if ( ! Arriendo_Facil_Tenancy::can_access_unit( $unit_id ) ) {
				wp_send_json_error( array( 'message' => __( 'No tienes acceso a esa unidad.', 'arriendo-facil' ) ), 403 );
			}
		} elseif ( $accommodation_id ) {
			if ( ! Arriendo_Facil_Tenancy::can_access_accommodation( $accommodation_id ) ) {
				wp_send_json_error( array( 'message' => __( 'No tienes acceso a ese inmueble.', 'arriendo-facil' ) ), 403 );
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'Selecciona un inmueble.', 'arriendo-facil' ) ), 400 );
		}

		$result = Arriendo_Facil_Billing_Ledger::upsert_service_schedule(
			array(
				'unit_id'          => $unit_id,
				'accommodation_id' => $accommodation_id,
				'service'          => isset( $_POST['service'] ) ? wp_unslash( $_POST['service'] ) : '',
				'due_day'          => isset( $_POST['due_day'] ) ? absint( wp_unslash( $_POST['due_day'] ) ) : 5,
				'flat_amount'      => isset( $_POST['flat_amount'] ) ? (float) wp_unslash( $_POST['flat_amount'] ) : 0,
				'amount_mode'      => isset( $_POST['amount_mode'] ) ? wp_unslash( $_POST['amount_mode'] ) : 'auto',
				'notes'            => isset( $_POST['notes'] ) ? wp_unslash( $_POST['notes'] ) : '',
				'is_active'        => ! empty( $_POST['is_active'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Regla de vencimiento guardada.', 'arriendo-facil' ),
				'schedule_id' => $result,
			)
		);
	}

	/**
	 * AJAX: deactivates a service due-date rule.
	 *
	 * @return void
	 */
	public function ajax_delete_service_schedule() {
		global $wpdb;

		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$schedule_id = isset( $_POST['schedule_id'] ) ? absint( wp_unslash( $_POST['schedule_id'] ) ) : 0;
		$schedule    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Arriendo_Facil_Billing_Ledger::schedules_table() . ' WHERE id = %d', $schedule_id )
		);

		if ( ! $schedule ) {
			wp_send_json_error( array( 'message' => __( 'La regla de vencimiento no existe.', 'arriendo-facil' ) ), 404 );
		}

		$allowed = $schedule->unit_id
			? Arriendo_Facil_Tenancy::can_access_unit( (int) $schedule->unit_id )
			: Arriendo_Facil_Tenancy::can_access_accommodation( (int) $schedule->accommodation_id );

		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a ese inmueble.', 'arriendo-facil' ) ), 403 );
		}

		$result = Arriendo_Facil_Billing_Ledger::deactivate_service_schedule( $schedule_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Regla desactivada.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: materialises the service due dates of a period as charges.
	 *
	 * @return void
	 */
	public function ajax_generate_service_charges() {
		global $wpdb;

		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		if ( '' === Arriendo_Facil_Billing_Ledger::validate_period( $period ) ) {
			wp_send_json_error( array( 'message' => __( 'Periodo invalido. Usa el formato YYYY-MM.', 'arriendo-facil' ) ), 400 );
		}

		$scope = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
		$stats = Arriendo_Facil_Billing_Ledger::generate_service_charges( $period, $scope );

		wp_send_json_success(
			array(
				/* translators: 1: created, 2: updated, 3: skipped, 4: without lease */
				'message' => sprintf( __( 'Vencimientos generados: %1$d nuevos, %2$d actualizados, %3$d sin monto, %4$d sin inquilino.', 'arriendo-facil' ), $stats['created'], $stats['updated'], $stats['skipped'], $stats['no_lease'] ),
				'stats'   => $stats,
			)
		);
	}

	/**
	 * AJAX: marks the bill of a service as paid for a period.
	 *
	 * @return void
	 */
	public function ajax_mark_service_paid() {
		$schedule = $this->authorize_schedule_request();
		$period   = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';

		$result = Arriendo_Facil_Billing_Ledger::mark_service_paid(
			(int) $schedule->id,
			$period,
			array(
				'paid_on'   => isset( $_POST['paid_on'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_on'] ) ) : '',
				'amount'    => isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0,
				'reference' => isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Servicio marcado como pagado.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: removes the paid mark of a service for a period.
	 *
	 * @return void
	 */
	public function ajax_unmark_service_paid() {
		$schedule = $this->authorize_schedule_request();
		$period   = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';

		$result = Arriendo_Facil_Billing_Ledger::unmark_service_paid( (int) $schedule->id, $period );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Pago deshecho.', 'arriendo-facil' ) ) );
	}

	/**
	 * Checks nonce, capability and scope access for a schedule_id request.
	 * Sends a JSON error and exits when any check fails.
	 *
	 * @return object Schedule row.
	 */
	private function authorize_schedule_request() {
		global $wpdb;

		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$schedule_id = isset( $_POST['schedule_id'] ) ? absint( wp_unslash( $_POST['schedule_id'] ) ) : 0;
		$schedule    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Arriendo_Facil_Billing_Ledger::schedules_table() . ' WHERE id = %d', $schedule_id )
		);

		if ( ! $schedule ) {
			wp_send_json_error( array( 'message' => __( 'El servicio no existe.', 'arriendo-facil' ) ), 404 );
		}

		$allowed = $schedule->unit_id
			? Arriendo_Facil_Tenancy::can_access_unit( (int) $schedule->unit_id )
			: Arriendo_Facil_Tenancy::can_access_accommodation( (int) $schedule->accommodation_id );

		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a ese inmueble.', 'arriendo-facil' ) ), 403 );
		}

		return $schedule;
	}
}
