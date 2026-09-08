<?php
/**
 * Charges and payments (cobranza) engine.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Billing_Ledger
 *
 * Manages recurring charges (canon, alicuota, utilities) and the payments
 * applied against them. Independent from the SRI electronic invoicing module.
 */
class Arriendo_Facil_Billing_Ledger {

	/**
	 * Hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_record_payment', array( $this, 'ajax_record_payment' ) );
		add_action( 'wp_ajax_af_create_charge', array( $this, 'ajax_create_charge' ) );
		add_action( 'wp_ajax_af_generate_period_charges', array( $this, 'ajax_generate_period_charges' ) );
		add_action( 'wp_ajax_af_record_meter_reading', array( $this, 'ajax_record_meter_reading' ) );
		add_action( 'af_generate_monthly_charges', array( __CLASS__, 'generate_monthly_charges' ) );
		add_action( 'af_flag_overdue_charges', array( __CLASS__, 'flag_overdue_charges' ) );
	}

	/**
	 * Metered services that can be billed from readings.
	 *
	 * @return array<string,string>
	 */
	public static function metered_services() {
		return array(
			'agua' => __( 'Agua', 'arriendo-facil' ),
			'luz'  => __( 'Luz', 'arriendo-facil' ),
			'gas'  => __( 'Gas', 'arriendo-facil' ),
		);
	}

	/**
	 * Records a meter reading and creates the matching service charge.
	 * The previous reading is looked up automatically from the last period.
	 *
	 * @param array<string,mixed> $data Reading data.
	 * @return array{reading_id:int,charge_id:int,amount:float}|WP_Error
	 */
	public static function record_meter_reading( array $data ) {
		global $wpdb;

		$unit_id = isset( $data['unit_id'] ) ? absint( $data['unit_id'] ) : 0;
		$service = isset( $data['service'] ) ? sanitize_key( (string) $data['service'] ) : '';
		$period  = isset( $data['period'] ) ? sanitize_text_field( (string) $data['period'] ) : '';
		$current = isset( $data['current_reading'] ) ? (float) $data['current_reading'] : 0.0;
		$rate    = isset( $data['unit_rate'] ) ? (float) $data['unit_rate'] : 0.0;

		if ( ! $unit_id || ! array_key_exists( $service, self::metered_services() ) ) {
			return new WP_Error( 'af_reading_invalid', __( 'Unidad o servicio invalido.', 'arriendo-facil' ) );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
			return new WP_Error( 'af_reading_period_invalid', __( 'Periodo invalido. Usa el formato YYYY-MM.', 'arriendo-facil' ) );
		}

		if ( $rate <= 0 ) {
			return new WP_Error( 'af_reading_rate_invalid', __( 'La tarifa debe ser mayor a cero.', 'arriendo-facil' ) );
		}

		$previous = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT current_reading FROM ' . self::readings_table() . '
				 WHERE unit_id = %d AND service = %s AND period < %s
				 ORDER BY period DESC LIMIT 1',
				$unit_id,
				$service,
				$period
			)
		);

		if ( $current < $previous ) {
			return new WP_Error(
				'af_reading_lower_than_previous',
				sprintf(
					/* translators: %s: previous meter reading */
					__( 'La lectura actual no puede ser menor a la anterior (%s).', 'arriendo-facil' ),
					number_format_i18n( $previous, 3 )
				)
			);
		}

		$consumption = round( $current - $previous, 3 );
		$amount      = round( $consumption * $rate, 2 );

		$inserted = $wpdb->insert(
			self::readings_table(),
			array(
				'unit_id'           => $unit_id,
				'service'           => $service,
				'period'            => $period,
				'previous_reading'  => $previous,
				'current_reading'   => $current,
				'consumption'       => $consumption,
				'unit_rate'         => $rate,
				'calculated_amount' => $amount,
				'recorded_by'       => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_reading_duplicate', __( 'Ya existe una lectura de ese servicio para esta unidad y periodo.', 'arriendo-facil' ) );
		}

		$reading_id = (int) $wpdb->insert_id;
		$charge_id  = 0;

		// Bill the consumption to the active lease of the unit, when there is one.
		$lease = self::get_active_lease_for_unit( $unit_id );
		if ( $lease && $amount > 0 ) {
			$charge = self::create_charge(
				array(
					'lease_id'    => (int) $lease->id,
					'unit_id'     => $unit_id,
					'guest_id'    => (int) $lease->guest_id,
					'charge_type' => $service,
					'period'      => $period,
					'amount'      => $amount,
					'description' => sprintf(
						/* translators: 1: consumption, 2: unit rate */
						__( 'Consumo %1$s x tarifa %2$s', 'arriendo-facil' ),
						number_format_i18n( $consumption, 3 ),
						number_format_i18n( $rate, 4 )
					),
				)
			);

			if ( ! is_wp_error( $charge ) ) {
				$charge_id = (int) $charge;
			}
		}

		return array(
			'reading_id' => $reading_id,
			'charge_id'  => $charge_id,
			'amount'     => $amount,
		);
	}

	/**
	 * Finds the active lease tied to a unit through its linked accommodation.
	 *
	 * @param int $unit_id Unit ID.
	 * @return object|null
	 */
	public static function get_active_lease_for_unit( $unit_id ) {
		global $wpdb;

		if ( ! class_exists( 'Arriendo_Facil_Property_Structure' ) ) {
			return null;
		}

		$unit = Arriendo_Facil_Property_Structure::get_unit( $unit_id );
		if ( ! $unit || empty( $unit->accommodation_id ) ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, guest_id FROM {$wpdb->prefix}af_leases
				 WHERE accommodation_id = %d AND status = 'active' AND deleted_at IS NULL
				 ORDER BY id DESC LIMIT 1",
				(int) $unit->accommodation_id
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

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$result = self::record_meter_reading(
			array(
				'unit_id'         => isset( $_POST['unit_id'] ) ? absint( wp_unslash( $_POST['unit_id'] ) ) : 0,
				'service'         => isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '',
				'period'          => isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '',
				'current_reading' => isset( $_POST['current_reading'] ) ? (float) wp_unslash( $_POST['current_reading'] ) : 0,
				'unit_rate'       => isset( $_POST['unit_rate'] ) ? (float) wp_unslash( $_POST['unit_rate'] ) : 0,
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
					: __( 'Lectura guardada. No hay contrato activo en la unidad, no se genero cargo.', 'arriendo-facil' ),
			)
		);
	}

	/**
	 * Returns the charges table name.
	 *
	 * @return string
	 */
	public static function charges_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_charges';
	}

	/**
	 * Returns the payments table name.
	 *
	 * @return string
	 */
	public static function payments_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_payments';
	}

	/**
	 * Returns the meter readings table name.
	 *
	 * @return string
	 */
	public static function readings_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_meter_readings';
	}

	/**
	 * Supported charge types.
	 *
	 * @return array<string,string>
	 */
	public static function charge_types() {
		return array(
			'canon'    => __( 'Canon de arriendo', 'arriendo-facil' ),
			'alicuota' => __( 'Alicuota', 'arriendo-facil' ),
			'agua'     => __( 'Agua', 'arriendo-facil' ),
			'luz'      => __( 'Luz', 'arriendo-facil' ),
			'gas'      => __( 'Gas', 'arriendo-facil' ),
			'internet' => __( 'Internet', 'arriendo-facil' ),
			'multa'    => __( 'Multa', 'arriendo-facil' ),
			'otro'     => __( 'Otro', 'arriendo-facil' ),
		);
	}

	/**
	 * Supported payment methods.
	 *
	 * @return array<string,string>
	 */
	public static function payment_methods() {
		return array(
			'transferencia' => __( 'Transferencia', 'arriendo-facil' ),
			'efectivo'      => __( 'Efectivo', 'arriendo-facil' ),
			'deposito'      => __( 'Deposito', 'arriendo-facil' ),
			'cheque'        => __( 'Cheque', 'arriendo-facil' ),
			'otro'          => __( 'Otro', 'arriendo-facil' ),
		);
	}

	/**
	 * Creates a charge. Idempotent per (lease_id, charge_type, period) via the
	 * table's unique key, so re-running generation never duplicates.
	 *
	 * @param array<string,mixed> $data Charge data.
	 * @return int|WP_Error Charge ID.
	 */
	public static function create_charge( array $data ) {
		global $wpdb;

		$charge_type = isset( $data['charge_type'] ) ? sanitize_key( (string) $data['charge_type'] ) : '';
		$period      = isset( $data['period'] ) ? sanitize_text_field( (string) $data['period'] ) : '';
		$amount      = isset( $data['amount'] ) ? round( (float) $data['amount'], 2 ) : 0.0;

		if ( ! array_key_exists( $charge_type, self::charge_types() ) ) {
			return new WP_Error( 'af_charge_type_invalid', __( 'Tipo de cargo invalido.', 'arriendo-facil' ) );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
			return new WP_Error( 'af_charge_period_invalid', __( 'Periodo invalido. Usa el formato YYYY-MM.', 'arriendo-facil' ) );
		}

		if ( $amount <= 0 ) {
			return new WP_Error( 'af_charge_amount_invalid', __( 'El monto debe ser mayor a cero.', 'arriendo-facil' ) );
		}

		$due_date = isset( $data['due_date'] ) ? sanitize_text_field( (string) $data['due_date'] ) : '';
		if ( ! $due_date ) {
			$due_date = gmdate( 'Y-m-d', strtotime( $period . '-05' ) );
		}

		$inserted = $wpdb->insert(
			self::charges_table(),
			array(
				'lease_id'    => isset( $data['lease_id'] ) ? absint( $data['lease_id'] ) : null,
				'unit_id'     => isset( $data['unit_id'] ) ? absint( $data['unit_id'] ) : null,
				'guest_id'    => isset( $data['guest_id'] ) ? absint( $data['guest_id'] ) : null,
				'charge_type' => $charge_type,
				'period'      => $period,
				'description' => isset( $data['description'] ) ? sanitize_text_field( (string) $data['description'] ) : null,
				'amount'      => $amount,
				'amount_paid' => 0,
				'due_date'    => $due_date,
				'status'      => 'pending',
				'created_by'  => get_current_user_id(),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_charge_duplicate', __( 'Ya existe un cargo de ese tipo para este contrato y periodo.', 'arriendo-facil' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Records a payment against a charge and recalculates its status.
	 *
	 * @param array<string,mixed> $data Payment data.
	 * @return int|WP_Error Payment ID.
	 */
	public static function record_payment( array $data ) {
		global $wpdb;

		$charge_id = isset( $data['charge_id'] ) ? absint( $data['charge_id'] ) : 0;
		$amount    = isset( $data['amount'] ) ? round( (float) $data['amount'], 2 ) : 0.0;
		$method    = isset( $data['method'] ) ? sanitize_key( (string) $data['method'] ) : 'transferencia';

		$charge = self::get_charge( $charge_id );
		if ( ! $charge ) {
			return new WP_Error( 'af_payment_charge_invalid', __( 'Cargo invalido.', 'arriendo-facil' ) );
		}

		if ( 'void' === $charge->status ) {
			return new WP_Error( 'af_payment_charge_void', __( 'No se puede pagar un cargo anulado.', 'arriendo-facil' ) );
		}

		if ( $amount <= 0 ) {
			return new WP_Error( 'af_payment_amount_invalid', __( 'El monto del pago debe ser mayor a cero.', 'arriendo-facil' ) );
		}

		$outstanding = round( (float) $charge->amount - (float) $charge->amount_paid, 2 );
		if ( $amount > $outstanding ) {
			return new WP_Error(
				'af_payment_amount_exceeds',
				sprintf(
					/* translators: %s: outstanding balance */
					__( 'El pago excede el saldo pendiente (%s).', 'arriendo-facil' ),
					number_format_i18n( $outstanding, 2 )
				)
			);
		}

		if ( ! array_key_exists( $method, self::payment_methods() ) ) {
			$method = 'otro';
		}

		$payment_date = isset( $data['payment_date'] ) ? sanitize_text_field( (string) $data['payment_date'] ) : gmdate( 'Y-m-d' );

		$inserted = $wpdb->insert(
			self::payments_table(),
			array(
				'charge_id'    => $charge_id,
				'amount'       => $amount,
				'payment_date' => $payment_date,
				'method'       => $method,
				'reference'    => isset( $data['reference'] ) ? sanitize_text_field( (string) $data['reference'] ) : null,
				'receipt_url'  => isset( $data['receipt_url'] ) ? esc_url_raw( (string) $data['receipt_url'] ) : null,
				'notes'        => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : null,
				'recorded_by'  => get_current_user_id(),
			),
			array( '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_payment_insert_failed', __( 'No se pudo registrar el pago.', 'arriendo-facil' ) );
		}

		self::recalculate_charge_status( $charge_id );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Recalculates amount_paid and status for a charge from its payments.
	 *
	 * @param int $charge_id Charge ID.
	 * @return void
	 */
	public static function recalculate_charge_status( $charge_id ) {
		global $wpdb;

		$charge_id = absint( $charge_id );
		$charge    = self::get_charge( $charge_id );
		if ( ! $charge ) {
			return;
		}

		$total_paid = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount), 0) FROM ' . self::payments_table() . ' WHERE charge_id = %d',
				$charge_id
			)
		);

		$amount = (float) $charge->amount;
		if ( $total_paid >= $amount ) {
			$status = 'paid';
		} elseif ( $total_paid > 0 ) {
			$status = 'partial';
		} elseif ( strtotime( (string) $charge->due_date ) < strtotime( gmdate( 'Y-m-d' ) ) ) {
			$status = 'overdue';
		} else {
			$status = 'pending';
		}

		$wpdb->update(
			self::charges_table(),
			array(
				'amount_paid' => round( $total_paid, 2 ),
				'status'      => $status,
			),
			array( 'id' => $charge_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Flags unpaid charges past their due date as overdue.
	 *
	 * @return int Number of charges updated.
	 */
	public static function flag_overdue_charges() {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::charges_table() . "
				 SET status = 'overdue'
				 WHERE status IN ('pending', 'partial')
				   AND due_date < %s",
				gmdate( 'Y-m-d' )
			)
		);
	}

	/**
	 * Generates canon + alicuota charges for every active lease for a period.
	 * Safe to re-run: duplicates are rejected by the table's unique key.
	 *
	 * @param string $period Period in YYYY-MM format. Defaults to current month.
	 * @return array{created:int,skipped:int}
	 */
	public static function generate_monthly_charges( $period = '' ) {
		global $wpdb;

		$period = $period ? sanitize_text_field( $period ) : gmdate( 'Y-m' );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
			return array(
				'created' => 0,
				'skipped' => 0,
			);
		}

		$leases = (array) $wpdb->get_results(
			"SELECT id, accommodation_id, guest_id, monthly_rent
			 FROM {$wpdb->prefix}af_leases
			 WHERE status = 'active' AND deleted_at IS NULL"
		);

		$created = 0;
		$skipped = 0;

		foreach ( $leases as $lease ) {
			$unit    = class_exists( 'Arriendo_Facil_Property_Structure' )
				? Arriendo_Facil_Property_Structure::get_unit_by_accommodation( (int) $lease->accommodation_id )
				: null;
			$unit_id = $unit ? (int) $unit->id : 0;

			$canon = self::create_charge(
				array(
					'lease_id'    => (int) $lease->id,
					'unit_id'     => $unit_id,
					'guest_id'    => (int) $lease->guest_id,
					'charge_type' => 'canon',
					'period'      => $period,
					'amount'      => (float) $lease->monthly_rent,
				)
			);
			is_wp_error( $canon ) ? $skipped++ : $created++;

			if ( ! $unit_id ) {
				continue;
			}

			$hoa_amount = Arriendo_Facil_Property_Structure::calculate_unit_hoa( $unit_id );
			if ( $hoa_amount <= 0 ) {
				continue;
			}

			$hoa = self::create_charge(
				array(
					'lease_id'    => (int) $lease->id,
					'unit_id'     => $unit_id,
					'guest_id'    => (int) $lease->guest_id,
					'charge_type' => 'alicuota',
					'period'      => $period,
					'amount'      => $hoa_amount,
				)
			);
			is_wp_error( $hoa ) ? $skipped++ : $created++;
		}

		return array(
			'created' => $created,
			'skipped' => $skipped,
		);
	}

	/**
	 * Retrieves a charge row.
	 *
	 * @param int $charge_id Charge ID.
	 * @return object|null
	 */
	public static function get_charge( $charge_id ) {
		global $wpdb;

		$charge_id = absint( $charge_id );
		if ( ! $charge_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::charges_table() . ' WHERE id = %d', $charge_id )
		);
	}

	/**
	 * Returns the account statement (charges + balance) for a lease.
	 *
	 * @param int $lease_id Lease ID.
	 * @return array{charges:array<int,object>,total_charged:float,total_paid:float,balance:float}
	 */
	public static function get_statement_by_lease( $lease_id ) {
		global $wpdb;

		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return array(
				'charges'       => array(),
				'total_charged' => 0.0,
				'total_paid'    => 0.0,
				'balance'       => 0.0,
			);
		}

		$charges = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::charges_table() . " WHERE lease_id = %d AND status != 'void' ORDER BY period DESC, charge_type ASC",
				$lease_id
			)
		);

		$total_charged = 0.0;
		$total_paid    = 0.0;
		foreach ( $charges as $charge ) {
			$total_charged += (float) $charge->amount;
			$total_paid    += (float) $charge->amount_paid;
		}

		return array(
			'charges'       => $charges,
			'total_charged' => round( $total_charged, 2 ),
			'total_paid'    => round( $total_paid, 2 ),
			'balance'       => round( $total_charged - $total_paid, 2 ),
		);
	}

	/**
	 * Returns overdue charges bucketed by aging (30/60/90+ days).
	 *
	 * @return array<string,array<int,object>>
	 */
	public static function get_aging_report() {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.*, DATEDIFF(%s, c.due_date) AS days_overdue
				 FROM ' . self::charges_table() . " c
				 WHERE c.status IN ('pending', 'partial', 'overdue')
				   AND c.due_date < %s
				 ORDER BY c.due_date ASC",
				gmdate( 'Y-m-d' ),
				gmdate( 'Y-m-d' )
			)
		);

		$buckets = array(
			'1_30'  => array(),
			'31_60' => array(),
			'61_90' => array(),
			'90mas' => array(),
		);

		foreach ( $rows as $row ) {
			$days = (int) $row->days_overdue;
			if ( $days <= 30 ) {
				$buckets['1_30'][] = $row;
			} elseif ( $days <= 60 ) {
				$buckets['31_60'][] = $row;
			} elseif ( $days <= 90 ) {
				$buckets['61_90'][] = $row;
			} else {
				$buckets['90mas'][] = $row;
			}
		}

		return $buckets;
	}

	/**
	 * AJAX: records a payment against a charge.
	 *
	 * @return void
	 */
	public function ajax_record_payment() {
		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		// manage_options: af_owner tiene edit_posts y podria operar cargos de
		// propiedades ajenas si se permitiera aqui (control de acceso roto/IDOR).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$result = self::record_payment(
			array(
				'charge_id'    => isset( $_POST['charge_id'] ) ? absint( wp_unslash( $_POST['charge_id'] ) ) : 0,
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
	 * AJAX: creates a manual charge.
	 *
	 * @return void
	 */
	public function ajax_create_charge() {
		check_ajax_referer( 'af_ledger_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$result = self::create_charge(
			array(
				'lease_id'    => isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0,
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

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		$result = self::generate_monthly_charges( $period );

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
}
