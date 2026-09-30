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
	 * Days before the due date at which a service is flagged as "due soon".
	 *
	 * Shared with the alert hub so the badges, the KPI counters and the
	 * dashboard alerts all use the same window instead of drifting apart.
	 *
	 * @var int
	 */
	const SERVICE_DUE_SOON_DAYS = 7;

	/**
	 * Hooks into WordPress.
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
		add_action( 'af_generate_monthly_charges', array( __CLASS__, 'generate_monthly_charges' ) );
		add_action( 'af_flag_overdue_charges', array( __CLASS__, 'flag_overdue_charges' ) );
		add_action( 'af_generate_service_charges', array( __CLASS__, 'generate_service_charges' ) );
	}

	/**
	 * Metered services that can be billed from readings.
	 *
	 * @return array<string,string>
	 */
	public static function metered_services() {
		return array(
			'agua' => __( 'Agua', 'arriendo-facil' ),
			'luz'  => __( 'Luz',  'arriendo-facil' ),
			'gas'  => __( 'Gas',  'arriendo-facil' ),
		);
	}

	/**
	 * Canonical catalog of billable services and their due-date rules.
	 *
	 * This is the single source of truth for the service domain: metered
	 * services can be priced from a reading, while flat services always use the
	 * configured fixed amount. `due_day` is only a fallback for installations
	 * that have no schedule row yet.
	 *
	 * @return array<string,array{label:string,metered:bool,icon:string,due_day:int}>
	 */
	public static function service_catalog() {
		return array(
			'agua'     => array(
				'label'   => __( 'Agua', 'arriendo-facil' ),
				'metered' => true,
				'icon'    => 'droplets',
				'due_day' => 5,
			),
			'luz'      => array(
				'label'   => __( 'Luz', 'arriendo-facil' ),
				'metered' => true,
				'icon'    => 'zap',
				'due_day' => 5,
			),
			'gas'      => array(
				'label'   => __( 'Gas', 'arriendo-facil' ),
				'metered' => true,
				'icon'    => 'trending-up',
				'due_day' => 5,
			),
			'internet' => array(
				'label'   => __( 'Internet', 'arriendo-facil' ),
				'metered' => false,
				'icon'    => 'wifi',
				'due_day' => 5,
			),
			'telefono' => array(
				'label'   => __( 'Teléfono', 'arriendo-facil' ),
				'metered' => false,
				'icon'    => 'phone',
				'due_day' => 5,
			),
		);
	}

	/**
	 * Human label for a service key, falling back to the raw key.
	 *
	 * @param string $service Service key.
	 * @return string
	 */
	public static function service_label( $service ) {
		$catalog = self::service_catalog();

		return isset( $catalog[ $service ] ) ? $catalog[ $service ]['label'] : (string) $service;
	}

	/**
	 * Validates a billing period.
	 *
	 * A plain /\d{4}-\d{2}/ match is not enough: "2026-13" passes it and would
	 * write charges into a period that can never be displayed or reconciled.
	 *
	 * @param string $period Candidate period.
	 * @return string The period when valid, '' otherwise.
	 */
	public static function validate_period( $period ) {
		$period = (string) $period;

		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $period, $m ) ) {
			return '';
		}

		if ( (int) $m[2] < 1 || (int) $m[2] > 12 ) {
			return '';
		}

		return $period;
	}

	/**
	 * Resolves the due date for a service in a period.
	 *
	 * The day is clamped to the last day of the month so a "day 31" rule still
	 * resolves in February instead of rolling over into March.
	 *
	 * @param string $period  Period in YYYY-MM format.
	 * @param int    $due_day Configured day of month (1-31).
	 * @return string Date in YYYY-MM-DD, or '' when the period is invalid.
	 */
	public static function resolve_due_date( $period, $due_day ) {
		$period = self::validate_period( $period );

		if ( '' === $period ) {
			return '';
		}

		$year  = (int) substr( $period, 0, 4 );
		$month = (int) substr( $period, 5, 2 );

		$day = (int) $due_day;
		if ( $day < 1 ) {
			$day = 5;
		}

		$last_day = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
		$day      = min( $day, $last_day );

		return gmdate( 'Y-m-d', gmmktime( 0, 0, 0, $month, $day, $year ) );
	}

	/**
	 * Returns the service schedules table name.
	 *
	 * @return string
	 */
	public static function schedules_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_service_schedules';
	}

	/**
	 * Fetches the due-date rules for a scope.
	 *
	 * @param array<string,mixed> $args {
	 *     Optional query arguments.
	 *
	 *     @type int[]|null $accommodation_ids Restrict to these accommodations.
	 *     @type string     $service           Restrict to one service key.
	 *     @type bool       $active_only       Only rules still active.
	 * }
	 * @return array<int,object>
	 */
	public static function get_service_schedules( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'accommodation_ids' => null,
				'service'           => '',
				'active_only'       => true,
			)
		);

		$where  = ' WHERE 1 = 1';
		$params = array();
		$join   = '';

		if ( is_array( $args['accommodation_ids'] ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $args['accommodation_ids'] ) ) ) );

			if ( empty( $ids ) ) {
				return array();
			}

			// A rule is unit-scoped (unit_id, accommodation_id = 0) or property-scoped
			// (accommodation_id). The caller only knows accommodation post ids, so
			// unit rules have to be resolved through their parent unit; matching
			// unit_id against accommodation ids would cross tenant boundaries.
			$units_table = Arriendo_Facil_Property_Structure::units_table();
			$in          = implode( ',', $ids );

			$join  = " LEFT JOIN {$units_table} s_u ON s_u.id = s.unit_id";
			$where .= ' AND COALESCE( NULLIF( s.accommodation_id, 0 ), s_u.accommodation_id ) IN (' . $in . ')';
		}

		if ( $args['service'] ) {
			$where   .= ' AND s.service = %s';
			$params[] = sanitize_key( $args['service'] );
		}

		if ( $args['active_only'] ) {
			$where .= ' AND s.is_active = 1';
		}

		$sql = 'SELECT s.* FROM ' . self::schedules_table() . ' s' . $join . $where . ' ORDER BY s.accommodation_id ASC, s.unit_id ASC, s.service ASC';

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns the due-date rule for one scope + service, if any.
	 *
	 * @param int    $unit_id          Unit ID, or 0 for standalone properties.
	 * @param int    $accommodation_id Accommodation post ID, or 0 for unit-scoped rules.
	 * @param string $service          Service key.
	 * @return object|null
	 */
	public static function get_service_schedule( $unit_id, $accommodation_id, $service ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::schedules_table() . ' WHERE unit_id = %d AND accommodation_id = %d AND service = %s',
				absint( $unit_id ),
				absint( $accommodation_id ),
				sanitize_key( $service )
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Creates or updates a due-date rule for a scope + service.
	 *
	 * @param array<string,mixed> $data Schedule data.
	 * @return int|WP_Error Schedule ID.
	 */
	public static function upsert_service_schedule( array $data ) {
		global $wpdb;

		$unit_id          = isset( $data['unit_id'] ) ? absint( $data['unit_id'] ) : 0;
		$accommodation_id = isset( $data['accommodation_id'] ) ? absint( $data['accommodation_id'] ) : 0;
		$service          = isset( $data['service'] ) ? sanitize_key( (string) $data['service'] ) : '';
		$catalog          = self::service_catalog();

		// Exactly one scope, mirroring af_meter_readings: a unit-scoped rule
		// covers the whole building, an accommodation-scoped rule a standalone
		// property.
		if ( ! $unit_id === ! $accommodation_id ) {
			return new WP_Error( 'af_schedule_scope_invalid', __( 'Selecciona un inmueble.', 'arriendo-facil' ) );
		}

		if ( ! isset( $catalog[ $service ] ) ) {
			return new WP_Error( 'af_schedule_service_invalid', __( 'Servicio no valido.', 'arriendo-facil' ) );
		}

		$due_day = isset( $data['due_day'] ) ? (int) $data['due_day'] : 5;
		if ( $due_day < 1 || $due_day > 31 ) {
			return new WP_Error( 'af_schedule_due_day_invalid', __( 'El dia de pago debe estar entre 1 y 31.', 'arriendo-facil' ) );
		}

		$amount_mode = isset( $data['amount_mode'] ) ? sanitize_key( (string) $data['amount_mode'] ) : 'auto';
		if ( ! in_array( $amount_mode, array( 'auto', 'fixed', 'metered' ), true ) ) {
			$amount_mode = 'auto';
		}

		$flat_amount = isset( $data['flat_amount'] ) ? round( (float) $data['flat_amount'], 2 ) : 0.0;
		if ( $flat_amount < 0 ) {
			return new WP_Error( 'af_schedule_amount_invalid', __( 'El monto no puede ser negativo.', 'arriendo-facil' ) );
		}

		$notes      = isset( $data['notes'] ) ? sanitize_text_field( (string) $data['notes'] ) : '';
		$is_active  = ! empty( $data['is_active'] ) ? 1 : 0;
		$row_format = array( '%d', '%d', '%s', '%d', '%f', '%s', '%s', '%d', '%d' );
		$row        = array(
			'unit_id'          => $unit_id,
			'accommodation_id' => $accommodation_id,
			'service'          => $service,
			'due_day'          => $due_day,
			'flat_amount'      => $flat_amount,
			'amount_mode'      => $amount_mode,
			'notes'            => $notes ? $notes : null,
			'is_active'        => $is_active,
			'created_by'       => get_current_user_id(),
		);

		$existing = self::get_service_schedule( $unit_id, $accommodation_id, $service );

		if ( $existing ) {
			unset( $row['created_by'] );
			$row_format = array( '%d', '%d', '%s', '%d', '%f', '%s', '%s', '%d' );

			$updated = $wpdb->update( self::schedules_table(), $row, array( 'id' => (int) $existing->id ), $row_format, array( '%d' ) );

			if ( false === $updated ) {
				return new WP_Error( 'af_schedule_update_failed', __( 'No se pudo guardar la regla de vencimiento.', 'arriendo-facil' ) );
			}

			return (int) $existing->id;
		}

		$inserted = $wpdb->insert( self::schedules_table(), $row, $row_format );

		if ( ! $inserted ) {
			return new WP_Error( 'af_schedule_insert_failed', __( 'No se pudo guardar la regla de vencimiento.', 'arriendo-facil' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Deactivates a due-date rule. Kept soft so the historical charges it
	 * generated are never orphaned.
	 *
	 * @param int $schedule_id Schedule ID.
	 * @return true|WP_Error
	 */
	public static function deactivate_service_schedule( $schedule_id ) {
		global $wpdb;

		$schedule_id = absint( $schedule_id );
		$schedule    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::schedules_table() . ' WHERE id = %d', $schedule_id )
		);

		if ( ! $schedule ) {
			return new WP_Error( 'af_schedule_not_found', __( 'La regla de vencimiento no existe.', 'arriendo-facil' ) );
		}

		$updated = $wpdb->update(
			self::schedules_table(),
			array( 'is_active' => 0 ),
			array( 'id' => $schedule_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'af_schedule_deactivate_failed', __( 'No se pudo desactivar la regla.', 'arriendo-facil' ) );
		}

		return true;
	}

	/**
	 * Materialises the due-date rules of a period as charges.
	 *
	 * Pricing is mixed: when a reading exists for the scope + service + period
	 * the metered amount wins, otherwise the configured fixed amount is used.
	 * Safe to re-run — the charges table rejects duplicates and an existing
	 * charge is only re-priced while nothing has been paid against it.
	 *
	 * @param string     $period            Period in YYYY-MM format.
	 * @param int[]|null $accommodation_ids Restrict generation to this scope. Null = all.
	 * @return array{created:int,updated:int,skipped:int,no_lease:int}
	 */
	public static function generate_service_charges( $period = '', $accommodation_ids = null ) {
		global $wpdb;

		$period = $period ? sanitize_text_field( $period ) : gmdate( 'Y-m' );
		$period = self::validate_period( $period );
		if ( '' === $period ) {
			return array(
				'created'  => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'no_lease' => 0,
			);
		}

		$schedules = self::get_service_schedules( array( 'accommodation_ids' => $accommodation_ids ) );

		$created  = 0;
		$updated  = 0;
		$skipped  = 0;
		$no_lease = 0;

		foreach ( $schedules as $schedule ) {
			$unit_id          = (int) $schedule->unit_id;
			$accommodation_id = (int) $schedule->accommodation_id;
			$service          = (string) $schedule->service;

			$lease = $unit_id
				? self::get_active_lease_for_unit( $unit_id )
				: self::get_active_lease_for_accommodation( $accommodation_id );

			if ( ! $lease ) {
				++$no_lease;
				continue;
			}

			$reading = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT calculated_amount FROM ' . self::readings_table() . '
					 WHERE unit_id = %d AND accommodation_id = %d AND service = %s AND period = %s',
					$unit_id,
					$accommodation_id,
					$service,
					$period
				)
			);

			$reading_amount = $reading ? (float) $reading->calculated_amount : 0.0;
			$flat_amount    = round( (float) $schedule->flat_amount, 2 );
			$amount_mode    = (string) $schedule->amount_mode;

			if ( 'fixed' === $amount_mode ) {
				$amount = $flat_amount;
			} elseif ( 'metered' === $amount_mode ) {
				$amount = $reading_amount;
			} else {
				$amount = $reading_amount > 0 ? $reading_amount : $flat_amount;
			}

			$amount = round( $amount, 2 );

			if ( $amount <= 0 ) {
				// Nothing to charge yet: the reading is still pending or the
				// rule has no fixed amount. The alert hub still surfaces it.
				++$skipped;
				continue;
			}

			$due_date = self::resolve_due_date( $period, (int) $schedule->due_day );
			$existing = self::get_charge_for_period( (int) $lease->id, $service, $period );

			if ( $existing ) {
				// Only re-price while the charge is untouched. Once money moved
				// the amount is financial history and a late reading must not
				// silently rewrite it.
				if ( 'void' !== $existing->status && 0.0 === (float) $existing->amount_paid
					&& ( (float) $existing->amount !== $amount || (string) $existing->due_date !== $due_date ) ) {
					$wpdb->update(
						self::charges_table(),
						array(
							'amount'      => $amount,
							'due_date'    => $due_date,
							'description' => self::service_charge_description( $service, $reading_amount, $flat_amount, $amount_mode ),
						),
						array( 'id' => (int) $existing->id ),
						array( '%f', '%s', '%s' ),
						array( '%d' )
					);
					++$updated;
				} else {
					++$skipped;
				}
				continue;
			}

			$charge_id = self::create_charge(
				array(
					'lease_id'     => (int) $lease->id,
					'unit_id'      => $unit_id ? $unit_id : 0,
					'guest_id'     => (int) $lease->guest_id,
					'charge_type'  => $service,
					'period'       => $period,
					'amount'       => $amount,
					'due_date'     => $due_date,
					'description'  => self::service_charge_description( $service, $reading_amount, $flat_amount, $amount_mode ),
				)
			);

			if ( is_wp_error( $charge_id ) ) {
				++$skipped;
				continue;
			}

			++$created;
		}

		return array(
			'created'  => $created,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'no_lease' => $no_lease,
		);
	}

	/**
	 * Describes where the amount of a service charge came from, so the operator
	 * can tell a metered amount from a flat one at a glance.
	 *
	 * @param string $service       Service key.
	 * @param float  $reading_amount Amount calculated from the reading.
	 * @param float  $flat_amount   Configured fixed amount.
	 * @param string $amount_mode   Rule pricing mode.
	 * @return string
	 */
	private static function service_charge_description( $service, $reading_amount, $flat_amount, $amount_mode ) {
		$label = self::service_label( $service );

		if ( 'fixed' === $amount_mode ) {
			return sprintf(
				/* translators: 1: service name, 2: amount */
				__( '%1$s (tarifa fija)', 'arriendo-facil' ),
				$label,
				number_format_i18n( $flat_amount, 2 )
			);
		}

		if ( 'metered' === $amount_mode ) {
			return sprintf(
				/* translators: 1: service name, 2: amount */
				__( '%1$s (consumo medido)', 'arriendo-facil' ),
				$label,
				number_format_i18n( $reading_amount, 2 )
			);
		}

		if ( $reading_amount > 0 ) {
			return sprintf(
				/* translators: 1: service name, 2: amount */
				__( '%1$s (consumo medido)', 'arriendo-facil' ),
				$label,
				number_format_i18n( $reading_amount, 2 )
			);
		}

		return sprintf(
			/* translators: 1: service name, 2: amount */
			__( '%1$s (tarifa fija)', 'arriendo-facil' ),
			$label,
			number_format_i18n( $flat_amount, 2 )
		);
	}

	/**
	 * Returns the charge of a type for a lease and period, if it exists.
	 *
	 * @param int    $lease_id    Lease ID.
	 * @param string $charge_type Charge type key.
	 * @param string $period      Period in YYYY-MM format.
	 * @return object|null
	 */
	public static function get_charge_for_period( $lease_id, $charge_type, $period ) {
		global $wpdb;

		$charge_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::charges_table() . '
				 WHERE lease_id = %d AND charge_type = %s AND period = %s
				 ORDER BY id DESC LIMIT 1',
				absint( $lease_id ),
				sanitize_key( $charge_type ),
				sanitize_text_field( (string) $period )
			)
		);

		return $charge_id ? self::get_charge( (int) $charge_id ) : null;
	}

	/**
	 * Builds the alert rows of the service payment hub.
	 *
	 * The result merges the due-date rule, the reading of the period, the
	 * materialised charge and the active lease, so a row can exist even before
	 * anything has been charged (no reading yet, or no rule configured at all).
	 *
	 * @param string     $period            Period in YYYY-MM format.
	 * @param int[]|null $accommodation_ids Restrict to this scope. Null = all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_service_due_rows( $period, $accommodation_ids = null ) {
		global $wpdb;

		$period = $period ? sanitize_text_field( $period ) : gmdate( 'Y-m' );
		$period = self::validate_period( $period );
		if ( '' === $period ) {
			return array();
		}

		$scope  = '';
		$params = array( $period, $period );

		if ( is_array( $accommodation_ids ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $accommodation_ids ) ) ) );

			if ( empty( $ids ) ) {
				return array();
			}

			$in     = implode( ',', $ids );
			// Unit-scoped rules belong to the accommodation that owns the unit, so
			// the filter has to resolve the same expression the joins use instead
			// of matching unit ids against accommodation ids.
			$scope  = ' AND COALESCE( NULLIF( s.accommodation_id, 0 ), u.accommodation_id ) IN (' . $in . ')';
		}

		$readings_table = self::readings_table();
		$charges_table  = self::charges_table();
		$leases_table   = $wpdb->prefix . 'af_leases';
		$units_table    = Arriendo_Facil_Property_Structure::units_table();

		// The active lease of an accommodation is resolved to the newest one so a
		// duplicated active lease cannot multiply the rows.
		$active_leases = "( SELECT l1.*
			FROM {$leases_table} l1
			WHERE l1.status = 'active' AND l1.deleted_at IS NULL
			  AND l1.id = (
			      SELECT MAX(l2.id) FROM {$leases_table} l2
			      WHERE l2.accommodation_id = l1.accommodation_id
			        AND l2.status = 'active' AND l2.deleted_at IS NULL
			  ) )";

		$sql = $wpdb->prepare(
			"SELECT s.id AS schedule_id, s.unit_id, s.accommodation_id, s.service, s.due_day,
			        s.flat_amount, s.amount_mode, s.is_active,
			        l.id AS lease_id, l.guest_id,
			        c.id AS charge_id, c.amount, c.amount_paid, c.due_date, c.status AS charge_status,
			        r.id AS reading_id, r.calculated_amount, r.consumption, r.current_reading, r.previous_reading, r.unit_rate,
			        u.unit_code,
			        p.post_title AS accommodation_title,
			        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
			 FROM " . self::schedules_table() . " s
			 LEFT JOIN {$units_table} u ON u.id = s.unit_id
			 LEFT JOIN {$active_leases} l
			        ON l.accommodation_id = COALESCE( NULLIF( s.accommodation_id, 0 ), u.accommodation_id )
			 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
			 LEFT JOIN {$wpdb->posts} p ON p.ID = COALESCE( NULLIF( s.accommodation_id, 0 ), u.accommodation_id )
			 LEFT JOIN {$charges_table} c
			        ON c.lease_id = l.id AND c.charge_type = s.service AND c.period = %s AND c.status <> 'void'
			 LEFT JOIN {$readings_table} r
			        ON r.unit_id = s.unit_id AND r.accommodation_id = s.accommodation_id
			        AND r.service = s.service AND r.period = %s
			 WHERE 1 = 1{$scope}
			 ORDER BY s.due_day ASC, s.service ASC, p.post_title ASC, u.unit_code ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$params
		);

		$rows = (array) $wpdb->get_results( $sql );
		$out  = array();

		// The reference date makes the status correct for the period being viewed:
		// today for the current month, the end of the month for a past period (so
		// unpaid rows read as overdue) and the first day for a future period (so
		// nothing is overdue before it even happens).
		$today = gmdate( 'Y-m-d' );
		if ( $period > gmdate( 'Y-m' ) ) {
			$reference = $period . '-01';
		} elseif ( $period < gmdate( 'Y-m' ) ) {
			$reference = self::resolve_due_date( $period, 31 );
		} else {
			$reference = $today;
		}

		foreach ( $rows as $row ) {
			$reading_amount = null === $row->calculated_amount ? 0.0 : (float) $row->calculated_amount;
			$has_reading     = (bool) ( $row->reading_id && null !== $row->calculated_amount );
			$charge_amount   = null === $row->amount ? 0.0 : (float) $row->amount;
			$amount_paid     = null === $row->amount_paid ? 0.0 : (float) $row->amount_paid;
			$has_charge      = (bool) ( $row->charge_id && 'void' !== $row->charge_status );

			// The due date shown is the charge date when the obligation already
			// exists, otherwise the date the rule would produce.
			$due_date = ( $has_charge && $row->due_date )
				? (string) $row->due_date
				: self::resolve_due_date( $period, (int) $row->due_day );

			// Mixed pricing, mirroring generate_service_charges().
			if ( 'fixed' === $row->amount_mode ) {
				$expected = (float) $row->flat_amount;
			} elseif ( 'metered' === $row->amount_mode ) {
				$expected = $reading_amount;
			} else {
				$expected = $reading_amount > 0 ? $reading_amount : (float) $row->flat_amount;
			}

			$expected      = round( $expected, 2 );
			$outstanding   = $has_charge ? round( $charge_amount - $amount_paid, 2 ) : 0.0;
			$needs_charge  = $has_charge ? false : ( $expected > 0 );
			$status        = self::status_key( $row, $has_charge, $reference );

			$out[] = array(
				'schedule_id'      => (int) $row->schedule_id,
				'unit_id'          => (int) $row->unit_id,
				'accommodation_id' => (int) $row->accommodation_id,
				'service'          => (string) $row->service,
				'service_label'    => self::service_label( $row->service ),
				'due_day'          => (int) $row->due_day,
				'flat_amount'      => (float) $row->flat_amount,
				'notes'            => null === $row->notes ? '' : (string) $row->notes,
				'due_date'         => $due_date,
				'accommodation_title' => (string) $row->accommodation_title,
				'unit_code'        => (string) $row->unit_code,
				'lease_id'         => (int) $row->lease_id,
				'guest_id'         => (int) $row->guest_id,
				'guest_name'       => trim( (string) $row->guest_name ),
				'charge_id'        => (int) $row->charge_id,
				'has_charge'       => $has_charge,
				'charge_amount'    => $charge_amount,
				'amount_paid'      => $amount_paid,
				'outstanding'      => $outstanding,
				'expected_amount'  => $expected,
				'needs_charge'     => $needs_charge,
				'charge_status'    => (string) $row->charge_status,
				'status'           => $status,
				'has_reading'      => $has_reading,
				'reading_amount'   => $reading_amount,
				'consumption'      => null === $row->consumption ? 0.0 : (float) $row->consumption,
				'current_reading'  => null === $row->current_reading ? 0.0 : (float) $row->current_reading,
				'previous_reading' => null === $row->previous_reading ? 0.0 : (float) $row->previous_reading,
				'unit_rate'        => null === $row->unit_rate ? 0.0 : (float) $row->unit_rate,
				'is_active'        => (int) $row->is_active,
			);
		}

		return $out;
	}

	/**
	 * Derives the alert status of a due row.
	 *
	 * @param object $row       Raw row from the due-rows query.
	 * @param bool   $has_charge Whether a materialised charge exists.
	 * @param string $reference  Date the due date is compared against (Y-m-d).
	 * @return string One of paid, partial, overdue, due_soon, pending, no_charge, no_lease, inactive.
	 */
	private static function status_key( $row, $has_charge, $reference = '' ) {
		if ( ! (int) $row->is_active ) {
			return 'inactive';
		}

		if ( ! $row->lease_id ) {
			return 'no_lease';
		}

		if ( $has_charge ) {
			if ( 'paid' === $row->charge_status ) {
				return 'paid';
			}

			if ( 'partial' === $row->charge_status ) {
				return 'partial';
			}

			$due_date  = (string) $row->due_date;
			$reference = $reference ? $reference : gmdate( 'Y-m-d' );

			if ( $due_date && $due_date < $reference ) {
				return 'overdue';
			}

			$days = (int) ( ( strtotime( $due_date ) - strtotime( $reference ) ) / DAY_IN_SECONDS );
			if ( $days <= self::SERVICE_DUE_SOON_DAYS ) {
				return 'due_soon';
			}

			return 'pending';
		}

		return 'no_charge';
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

		$unit_id          = isset( $data['unit_id'] ) ? absint( $data['unit_id'] ) : 0;
		$accommodation_id = isset( $data['accommodation_id'] ) ? absint( $data['accommodation_id'] ) : 0;
		$service          = isset( $data['service'] ) ? sanitize_key( (string) $data['service'] ) : '';
		$period           = isset( $data['period'] ) ? sanitize_text_field( (string) $data['period'] ) : '';
		$current          = isset( $data['current_reading'] ) ? (float) $data['current_reading'] : 0.0;
		$rate             = isset( $data['unit_rate'] ) ? (float) $data['unit_rate'] : 0.0;

		if ( ! $unit_id && ! $accommodation_id ) {
			return new WP_Error( 'af_reading_invalid', __( 'Selecciona una unidad o un inmueble.', 'arriendo-facil' ) );
		}

		if ( $unit_id && $accommodation_id ) {
			return new WP_Error( 'af_reading_invalid', __( 'La lectura se registra contra una unidad o un inmueble, no ambos.', 'arriendo-facil' ) );
		}

		if ( ! array_key_exists( $service, self::metered_services() ) ) {
			return new WP_Error( 'af_reading_invalid', __( 'Servicio invalido.', 'arriendo-facil' ) );
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
				 WHERE unit_id = %d AND accommodation_id = %d AND service = %s AND period < %s
				 ORDER BY period DESC LIMIT 1',
				$unit_id,
				$accommodation_id,
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
				'accommodation_id'  => $accommodation_id,
				'service'           => $service,
				'period'            => $period,
				'previous_reading'  => $previous,
				'current_reading'   => $current,
				'consumption'       => $consumption,
				'unit_rate'         => $rate,
				'calculated_amount' => $amount,
				'recorded_by'       => get_current_user_id(),
			),
			array( '%d', '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_reading_duplicate', __( 'Ya existe una lectura de ese servicio para esta unidad o inmueble y periodo.', 'arriendo-facil' ) );
		}

		$reading_id = (int) $wpdb->insert_id;
		$charge_id  = 0;

		// Bill the consumption to the active lease of the unit or stand-alone
		// accommodation, when there is one.
		$lease = $unit_id ? self::get_active_lease_for_unit( $unit_id ) : self::get_active_lease_for_accommodation( $accommodation_id );
		if ( $lease && $amount > 0 ) {
			// The charge created from a reading honours the configured due day
			// of the scope, so the obligation does not silently fall back to the
			// hardcoded day 5.
			$schedule = self::get_service_schedule( $unit_id, $accommodation_id, $service );

			$charge = self::create_charge(
				array(
					'lease_id'    => (int) $lease->id,
					'unit_id'     => $unit_id ? $unit_id : null,
					'guest_id'    => (int) $lease->guest_id,
					'charge_type' => $service,
					'period'      => $period,
					'amount'      => $amount,
					'due_date'    => $schedule ? self::resolve_due_date( $period, (int) $schedule->due_day ) : '',
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
	 * Deletes a meter reading. When the reading generated a service charge it
	 * is voided too (only if it has no payments), so the billing ledger stays
	 * consistent with the recorded readings.
	 *
	 * @param int $reading_id Reading row ID.
	 * @return true|WP_Error
	 */
	public static function delete_meter_reading( $reading_id ) {
		global $wpdb;

		$reading_id = absint( $reading_id );
		$reading    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::readings_table() . ' WHERE id = %d',
				$reading_id
			)
		);

		if ( ! $reading ) {
			return new WP_Error( 'af_reading_not_found', __( 'Lectura no encontrada.', 'arriendo-facil' ) );
		}

		$charge_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::charges_table() . "
				 WHERE charge_type = %s AND period = %s AND status <> 'void'
				   AND ( ( unit_id > 0 AND unit_id = %d )
				         OR ( ( unit_id IS NULL OR unit_id = 0 ) AND lease_id IN ( SELECT id FROM {$wpdb->prefix}af_leases WHERE accommodation_id = %d ) ) )
				   AND description LIKE %s
				 ORDER BY id DESC LIMIT 1",
				$reading->service,
				$reading->period,
				(int) $reading->unit_id,
				(int) $reading->accommodation_id,
				'Consumo%'
			)
		);

		if ( $charge_id ) {
			$voided = self::void_charge( $charge_id );
			if ( is_wp_error( $voided ) ) {
				return $voided;
			}
		}

		$deleted = $wpdb->delete(
			self::readings_table(),
			array( 'id' => $reading_id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error( 'af_reading_delete_failed', __( 'No se pudo borrar la lectura.', 'arriendo-facil' ) );
		}

		return true;
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
	 * Finds the active lease tied to an accommodation directly (used for
	 * meter readings of stand-alone properties that are not in a building).
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return object|null
	 */
	public static function get_active_lease_for_accommodation( $accommodation_id ) {
		global $wpdb;

		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, guest_id FROM {$wpdb->prefix}af_leases
				 WHERE accommodation_id = %d AND status = 'active' AND deleted_at IS NULL
				 ORDER BY id DESC LIMIT 1",
				$accommodation_id
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

		$result = self::record_meter_reading(
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
				'SELECT * FROM ' . self::readings_table() . ' WHERE id = %d',
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

		$result = self::delete_meter_reading( $reading_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Lectura borrada y cargo asociado anulado.', 'arriendo-facil' ) ) );
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
			'canon'     => __( 'Canon de arriendo', 'arriendo-facil' ),
			'alicuota'  => __( 'Alicuota', 'arriendo-facil' ),
			'agua'      => __( 'Agua', 'arriendo-facil' ),
			'luz'       => __( 'Luz', 'arriendo-facil' ),
			'gas'       => __( 'Gas', 'arriendo-facil' ),
			'internet'  => __( 'Internet', 'arriendo-facil' ),
			'telefono'  => __( 'Teléfono', 'arriendo-facil' ),
			'multa'     => __( 'Multa', 'arriendo-facil' ),
			'otro'      => __( 'Otro', 'arriendo-facil' ),
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

		// Overpayments are allowed: the excess becomes a credit to the lease and
		// is applied to future charges (see get_lease_credit()).
		$outstanding = round( (float) $charge->amount - (float) $charge->amount_paid, 2 );
		if ( $outstanding <= 0 ) {
			return new WP_Error(
				'af_payment_charge_fully_paid',
				__( 'Este cargo ya está saldado; registra el excedente como un pago anticipado sobre el próximo cargo.', 'arriendo-facil' )
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
	 * Voids a charge. Only charges with no recorded payments can be voided,
	 * otherwise the accounting trail (payments) would become orphaned.
	 *
	 * @param int $charge_id Charge ID.
	 * @return true|WP_Error
	 */
	public static function void_charge( $charge_id ) {
		global $wpdb;

		$charge_id = absint( $charge_id );
		$charge    = self::get_charge( $charge_id );
		if ( ! $charge ) {
			return new WP_Error( 'af_charge_invalid', __( 'Cargo invalido.', 'arriendo-facil' ) );
		}

		if ( 'void' === $charge->status ) {
			return new WP_Error( 'af_charge_already_void', __( 'El cargo ya esta anulado.', 'arriendo-facil' ) );
		}

		if ( (float) $charge->amount_paid > 0 ) {
			return new WP_Error(
				'af_charge_has_payments',
				__( 'No se puede anular un cargo que ya tiene pagos registrados.', 'arriendo-facil' )
			);
		}

		$updated = $wpdb->update(
			self::charges_table(),
			array( 'status' => 'void' ),
			array( 'id' => $charge_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'af_charge_void_failed', __( 'No se pudo anular el cargo.', 'arriendo-facil' ) );
		}

		return true;
	}

	/**
	 * Returns the total outstanding credit a lease has accumulated from
	 * overpayments. Computed as the sum of positive differences between the
	 * amount paid and the amount due across all non-void charges.
	 *
	 * @param int $lease_id Lease ID.
	 * @return float
	 */
	public static function get_lease_credit( $lease_id ) {
		global $wpdb;

		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return 0.0;
		}

		$credit = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount_paid - amount), 0)
				 FROM ' . self::charges_table() . "
				 WHERE lease_id = %d AND status != 'void' AND amount_paid > amount",
				$lease_id
			)
		);

		return round( $credit, 2 );
	}

	/**
	 * Generates canon + alicuota charges for every active lease for a period.
	 * Safe to re-run: duplicates are rejected by the table's unique key.
	 *
	 * @param string   $period            Period in YYYY-MM format. Defaults to current month.
	 * @param int[]|null $accommodation_ids Restrict generation to these accommodation IDs
	 *                                       (property-admin scope). Null = all leases.
	 * @return array{created:int,skipped:int}
	 */
	public static function generate_monthly_charges( $period = '', $accommodation_ids = null ) {
		global $wpdb;

		$period = $period ? sanitize_text_field( $period ) : gmdate( 'Y-m' );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
			return array(
				'created' => 0,
				'skipped' => 0,
			);
		}

		$scope_clause = '';
		if ( is_array( $accommodation_ids ) ) {
			$scope_clause = empty( $accommodation_ids )
				? ' AND 1 = 0'
				: ' AND accommodation_id IN (' . implode( ',', array_map( 'absint', $accommodation_ids ) ) . ')';
		}

		$leases = (array) $wpdb->get_results(
			"SELECT id, accommodation_id, guest_id, monthly_rent, payment_due_day
			 FROM {$wpdb->prefix}af_leases
			 WHERE status = 'active' AND deleted_at IS NULL{$scope_clause}" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$created = 0;
		$skipped = 0;

		foreach ( $leases as $lease ) {
			$unit    = class_exists( 'Arriendo_Facil_Property_Structure' )
				? Arriendo_Facil_Property_Structure::get_unit_by_accommodation( (int) $lease->accommodation_id )
				: null;
			$unit_id = $unit ? (int) $unit->id : 0;

			$due_day  = ! empty( $lease->payment_due_day ) ? min( 28, max( 1, (int) $lease->payment_due_day ) ) : 5;
			$due_date = gmdate( 'Y-m-d', strtotime( $period . '-' . str_pad( (string) $due_day, 2, '0', STR_PAD_LEFT ) ) );

			// Cargo unificado: canon + alicuota en una sola linea del periodo.
			$canon_amount = (float) $lease->monthly_rent;
			$description  = null;

			if ( $unit_id ) {
				$hoa_amount = Arriendo_Facil_Property_Structure::calculate_unit_hoa( $unit_id );
				if ( $hoa_amount > 0 ) {
					$canon_amount += $hoa_amount;
					$description   = __( 'Canon + alicuota', 'arriendo-facil' );
				}
			}

			$canon = self::create_charge(
				array(
					'lease_id'    => (int) $lease->id,
					'unit_id'     => $unit_id,
					'guest_id'    => (int) $lease->guest_id,
					'charge_type' => 'canon',
					'period'      => $period,
					'amount'      => $canon_amount,
					'due_date'    => $due_date,
					'description' => $description,
				)
			);
			is_wp_error( $canon ) ? $skipped++ : $created++;
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
	 * Returns non-void charges for a lease in a given period (YYYY-MM).
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $period   Period in YYYY-MM format.
	 * @return object[]
	 */
	public static function get_charges_by_lease_period( $lease_id, $period ) {
		global $wpdb;

		$lease_id = absint( $lease_id );
		$period   = sanitize_text_field( (string) $period );
		if ( ! $lease_id || ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
			return array();
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::charges_table() . ' WHERE lease_id = %d AND period = %s AND status != %s ORDER BY charge_type ASC',
				$lease_id,
				$period,
				'void'
			)
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

		$credit = self::get_lease_credit( $lease_id );

		return array(
			'charges'       => $charges,
			'total_charged' => round( $total_charged, 2 ),
			'total_paid'    => round( $total_paid, 2 ),
			'balance'       => round( $total_charged - $total_paid, 2 ),
			'credit'        => $credit,
		);
	}

	/**
	 * Returns overdue charges bucketed by aging (30/60/90+ days).
	 *
	 * @param int[]|null $accommodation_ids Restrict to these accommodations
	 *                                       (property-admin scope). Null = all.
	 * @return array<string,array<int,object>>
	 */
	public static function get_aging_report( $accommodation_ids = null ) {
		global $wpdb;

		$scope_clause = '';
		if ( is_array( $accommodation_ids ) ) {
			$scope_clause = empty( $accommodation_ids )
				? ' AND 1 = 0'
				: ' AND c.lease_id IN (SELECT id FROM ' . $wpdb->prefix . 'af_leases WHERE accommodation_id IN (' . implode( ',', array_map( 'absint', $accommodation_ids ) ) . '))';
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.*, DATEDIFF(%s, c.due_date) AS days_overdue
				 FROM ' . self::charges_table() . " c
				 WHERE c.status IN ('pending', 'partial', 'overdue')
				   AND c.due_date < %s{$scope_clause}
				 ORDER BY c.due_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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

		$result = self::void_charge( $charge_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Cargo anulado correctamente.', 'arriendo-facil' ) ) );
	}

	/**
	 * Human labels for the charge types.
	 *
	 * @return array<string,string>
	 */
	public static function charge_labels() {
		return array(
			'canon'     => __( 'Canon', 'arriendo-facil' ),
			'alicuota'  => __( 'Alícuota', 'arriendo-facil' ),
			'agua'      => __( 'Agua', 'arriendo-facil' ),
			'luz'       => __( 'Luz', 'arriendo-facil' ),
			'gas'       => __( 'Gas', 'arriendo-facil' ),
			'internet'  => __( 'Internet', 'arriendo-facil' ),
			'multa'     => __( 'Multa', 'arriendo-facil' ),
			'otro'      => __( 'Otro', 'arriendo-facil' ),
		);
	}

	/**
	 * Presentation metadata for the per-property collection status.
	 *
	 * `card` is the CSS modifier that colours the card, `pill` the badge tone.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function cobranza_statuses() {
		return array(
			'vencido'       => array( 'pill' => 'danger',  'card' => 'is-danger',    'label' => __( 'Vencido', 'arriendo-facil' ) ),
			'vence_hoy'     => array( 'pill' => 'danger',  'card' => 'is-danger',    'label' => __( 'Vence hoy', 'arriendo-facil' ) ),
			'vence_proximo' => array( 'pill' => 'warning', 'card' => 'is-warning',   'label' => __( 'Vence pronto', 'arriendo-facil' ) ),
			'por_cobrar'    => array( 'pill' => 'info',    'card' => 'is-info',      'label' => __( 'Por cobrar', 'arriendo-facil' ) ),
			'sin_cargo'     => array( 'pill' => 'neutral', 'card' => 'is-muted',     'label' => __( 'Sin cargo generado', 'arriendo-facil' ) ),
			'aldia'         => array( 'pill' => 'success', 'card' => 'is-ok',        'label' => __( 'Al día', 'arriendo-facil' ) ),
			'available'     => array( 'pill' => 'neutral', 'card' => 'is-available', 'label' => __( 'Disponible', 'arriendo-facil' ) ),
		);
	}

	/**
	 * Property types with their label and icon key.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function accommodation_types() {
		return array(
			'apartment'  => array( 'label' => __( 'Apartamento', 'arriendo-facil' ), 'icon' => 'building' ),
			'house'      => array( 'label' => __( 'Casa', 'arriendo-facil' ), 'icon' => 'home' ),
			'office'     => array( 'label' => __( 'Oficina', 'arriendo-facil' ), 'icon' => 'building-2' ),
			'room'       => array( 'label' => __( 'Habitación', 'arriendo-facil' ), 'icon' => 'bed' ),
			'commercial' => array( 'label' => __( 'Comercial', 'arriendo-facil' ), 'icon' => 'store' ),
		);
	}

	/**
	 * Builds the per-property collection snapshot used by the "Cobranza por
	 * inmueble" hub: what to charge, how much is still owed and when it is due.
	 *
	 * Shared by the admin view and by the af_cobranza_snapshot endpoint, so the
	 * page can refresh itself after a payment is recorded.
	 *
	 * @param string|null $period Billing period (Y-m). Defaults to the current one.
	 * @return array{period:string,today:string,props:array,summary:array,statuses:array}
	 */
	public static function cobranza_snapshot( $period = null ) {
		global $wpdb;

		$current_period = $period ? (string) $period : current_time( 'Y-m' );
		$today          = current_time( 'Y-m-d' );
		$prev_pe        = gmdate( 'Y-m', strtotime( $current_period . '-01 -1 month' ) );
		$last_pe        = gmdate( 'Y-m', strtotime( $current_period . '-01 +4 months' ) );

		$scope   = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
		$acc_ids = array();

		$args = array(
			'post_type'      => 'accommodation',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 500,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);
		if ( null !== $scope ) {
			$args['post__in'] = ! empty( $scope ) ? array_map( 'absint', $scope ) : array( 0 );
		}
		$acc_ids = (array) get_posts( $args );

		$meta = array();
		if ( ! empty( $acc_ids ) ) {
			$ids_sql = Arriendo_Facil_Tenancy::ids_in_clause( $acc_ids );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results(
				"SELECT p.ID,
				 MAX(CASE WHEN pm.meta_key = '_af_monthly_rent'  THEN pm.meta_value END) AS monthly_rent,
				 MAX(CASE WHEN pm.meta_key = '_af_property_type' THEN pm.meta_value END) AS property_type,
				 MAX(CASE WHEN pm.meta_key = '_af_address'       THEN pm.meta_value END) AS address,
				 MAX(CASE WHEN pm.meta_key = '_af_city'          THEN pm.meta_value END) AS city,
				 MAX(CASE WHEN pm.meta_key = '_af_bedrooms'      THEN pm.meta_value END) AS bedrooms,
				 MAX(CASE WHEN pm.meta_key = '_af_thumbnail_id'  THEN pm.meta_value END) AS thumbnail_id
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				  AND pm.meta_key IN ('_af_monthly_rent','_af_property_type','_af_address','_af_city','_af_bedrooms','_af_thumbnail_id')
				 WHERE p.ID IN ({$ids_sql})
				 GROUP BY p.ID
				 ORDER BY p.post_title ASC"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( $rows as $row ) {
				$meta[ (int) $row->ID ] = $row;
			}
		}

		$units = array();
		if ( ! empty( $acc_ids ) ) {
			$ph = implode( ',', array_fill( 0, count( $acc_ids ), '%d' ) );
			$urows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.accommodation_id, u.unit_code, b.name AS building_name
					 FROM {$wpdb->prefix}af_units u
					 LEFT JOIN {$wpdb->prefix}af_buildings b ON b.id = u.building_id
					 WHERE u.accommodation_id IN ({$ph}) AND u.status = 'active'",
					$acc_ids
				) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built safely.
			);
			foreach ( $urows as $row ) {
				$units[ (int) $row->accommodation_id ] = $row;
			}
		}

		$leases      = array();
		$lease_ids   = array();
		$by_acc      = array();
		if ( ! empty( $acc_ids ) ) {
			$ph = implode( ',', array_fill( 0, count( $acc_ids ), '%d' ) );
			$lrows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.accommodation_id, l.guest_id, l.monthly_rent, l.payment_due_day, l.end_date,
					        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
					 FROM {$wpdb->prefix}af_leases l
					 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
					 WHERE l.status = 'active' AND l.deleted_at IS NULL AND l.accommodation_id IN ({$ph})
					 ORDER BY l.start_date DESC",
					$acc_ids
				) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built safely.
			);
			foreach ( $lrows as $row ) {
				if ( ! isset( $leases[ (int) $row->accommodation_id ] ) ) {
					$leases[ (int) $row->accommodation_id ] = $row;
				}
				$lease_ids[] = (int) $row->id;
			}

			if ( ! empty( $lease_ids ) ) {
				$cph = implode( ',', array_fill( 0, count( $lease_ids ), '%d' ) );
				$crows = (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT c.id, c.lease_id, c.charge_type, c.description, c.amount, c.amount_paid, c.due_date, c.status, c.period,
						        l.accommodation_id
						 FROM {$wpdb->prefix}af_charges c
						 INNER JOIN {$wpdb->prefix}af_leases l ON l.id = c.lease_id
						 WHERE c.lease_id IN ({$cph}) AND c.period BETWEEN %s AND %s AND c.status <> 'void'
						 ORDER BY c.period ASC, c.due_date ASC, c.id ASC",
						array_merge( $lease_ids, array( $prev_pe, $last_pe ) )
					) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built safely.
				);
				foreach ( $crows as $row ) {
					$by_acc[ (int) $row->accommodation_id ][] = $row;
				}
			}
		}

		$types  = self::accommodation_types();
		$labels = self::charge_labels();

		$props = array();
		foreach ( $acc_ids as $acc_id ) {
			$acc_id = (int) $acc_id;
			$m      = isset( $meta[ $acc_id ] ) ? $meta[ $acc_id ] : null;
			$type   = $m ? (string) $m->property_type : '';
			$unit   = isset( $units[ $acc_id ] ) ? $units[ $acc_id ] : null;
			$lease  = isset( $leases[ $acc_id ] ) ? $leases[ $acc_id ] : null;
			$has_ls = null !== $lease;
			$pay_day = $has_ls ? (int) $lease->payment_due_day : 0;
			$pay_day = ( $pay_day >= 1 && $pay_day <= 28 ) ? $pay_day : 5;
			$rows   = isset( $by_acc[ $acc_id ] ) ? $by_acc[ $acc_id ] : array();

			$unpaid       = array();
			$cur_charges  = array();
			$cur_pending  = 0.0;
			$periods      = array();

			foreach ( $rows as $c ) {
				$ctype = (string) $c->charge_type;
				$per   = (string) $c->period;
				$bal   = (float) $c->amount - (float) $c->amount_paid;

				$periods[ $per ][] = array(
					'id'      => (int) $c->id,
					'type'    => $ctype,
					'label'   => isset( $labels[ $ctype ] ) ? $labels[ $ctype ] : $ctype,
					'desc'    => (string) $c->description,
					'amount'  => (float) $c->amount,
					'paid'    => (float) $c->amount_paid,
					'dueDate' => (string) $c->due_date,
					'status'  => (string) $c->status,
				);

				if ( $per === $current_period ) {
					$cur_charges[] = $c;
				}
				if ( in_array( (string) $c->status, array( 'pending', 'partial' ), true ) && $bal > 0.01 ) {
					$unpaid[] = array( 'balance' => $bal, 'due' => (string) $c->due_date );
					if ( $per === $current_period ) {
						$cur_pending += $bal;
					}
				}
			}

			$overdue_total = 0.0;
			$next_due      = '';
			$pending_total = 0.0;
			foreach ( $unpaid as $item ) {
				$pending_total += $item['balance'];
				if ( $item['due'] < $today ) {
					$overdue_total += $item['balance'];
				}
				if ( '' === $next_due || $item['due'] < $next_due ) {
					$next_due = $item['due'];
				}
			}

			if ( ! $has_ls && empty( $cur_charges ) ) {
				$status = 'available';
			} elseif ( $overdue_total > 0.01 || ( '' !== $next_due && $next_due < $today ) ) {
				$status = 'vencido';
			} elseif ( $next_due === $today ) {
				$status = 'vence_hoy';
			} elseif ( '' !== $next_due && $next_due > $today && strtotime( $next_due ) <= strtotime( $today . ' +5 days' ) ) {
				$status = 'vence_proximo';
			} elseif ( $has_ls && empty( $cur_charges ) ) {
				$status = 'sin_cargo';
			} elseif ( $pending_total > 0.01 ) {
				$status = 'por_cobrar';
			} else {
				$status = 'aldia';
			}

			$props[ $acc_id ] = array(
				'id'              => $acc_id,
				'title'           => get_the_title( $acc_id ),
				'address'         => $m ? (string) $m->address : '',
				'city'            => $m ? (string) $m->city : '',
				'type'            => $type,
				'type_icon'       => isset( $types[ $type ] ) ? $types[ $type ]['icon'] : 'building',
				'type_label'      => isset( $types[ $type ] ) ? $types[ $type ]['label'] : ucfirst( $type ),
				'bedrooms'        => $m ? (int) $m->bedrooms : 0,
				'thumb'           => $m && $m->thumbnail_id ? wp_get_attachment_image_url( (int) $m->thumbnail_id, 'medium_large' ) : get_the_post_thumbnail_url( $acc_id, 'medium_large' ),
				'unit_code'       => $unit ? (string) $unit->unit_code : '',
				'building_name'   => $unit ? (string) $unit->building_name : '',
				'guest'           => $has_ls ? trim( (string) $lease->guest_name ) : '',
				'monthly_rent'    => $has_ls ? (float) $lease->monthly_rent : ( $m && $m->monthly_rent ? (float) $m->monthly_rent : 0.0 ),
				'payment_due_day' => $pay_day,
				'lease_end'       => $has_ls ? (string) $lease->end_date : '',
				'has_lease'       => $has_ls,
				'status'          => $status,
				'pending_total'   => $pending_total,
				'pending_month'   => $cur_pending,
				'overdue_total'   => $overdue_total,
				'next_due'        => $next_due,
				'charges'         => $periods,
			);
		}

		$statuses = self::cobranza_statuses();
		foreach ( $props as $pid => $prop ) {
			$props[ $pid ]['due_hint']  = self::cobranza_due_hint( $prop, $today );
			$props[ $pid ]['due_class'] = self::cobranza_due_class( $prop['status'] );
			$has_pending              = $prop['pending_total'] > 0.01;
			$props[ $pid ]['amount_total'] = $has_pending ? $prop['pending_total'] : $prop['monthly_rent'];
			$props[ $pid ]['amount_label'] = $has_pending
				/* translators: label under the pending amount on a property card. */
				? __( 'por cobrar', 'arriendo-facil' )
				/* translators: suffix when the property has nothing pending. */
				: __( '/ mes', 'arriendo-facil' );
		}

		$order = array(
			'vencido'       => 0,
			'vence_hoy'     => 1,
			'vence_proximo' => 2,
			'por_cobrar'    => 3,
			'sin_cargo'     => 4,
			'aldia'         => 5,
			'available'     => 6,
		);
		uasort(
			$props,
			static function ( $a, $b ) use ( $order ) {
				$sa = isset( $order[ $a['status'] ] ) ? $order[ $a['status'] ] : 9;
				$sb = isset( $order[ $b['status'] ] ) ? $order[ $b['status'] ] : 9;
				if ( $sa !== $sb ) {
					return $sa <=> $sb;
				}
				$da = $a['next_due'] ? strtotime( $a['next_due'] ) : PHP_INT_MAX;
				$db = $b['next_due'] ? strtotime( $b['next_due'] ) : PHP_INT_MAX;
				return $da <=> $db;
			}
		);

		$summary = array(
			'vencido_count' => 0,
			'vencido_total' => 0.0,
			'proximo_count' => 0,
			'proximo_total' => 0.0,
			'pending_month' => 0.0,
			'aldia_count'   => 0,
			'disponibles'   => 0,
		);
		foreach ( $props as $prop ) {
			if ( 'vencido' === $prop['status'] ) {
				$summary['vencido_count']++;
				$summary['vencido_total'] += $prop['pending_total'];
			} elseif ( in_array( $prop['status'], array( 'vence_hoy', 'vence_proximo' ), true ) ) {
				$summary['proximo_count']++;
				$summary['proximo_total'] += $prop['pending_total'];
			} elseif ( 'aldia' === $prop['status'] ) {
				$summary['aldia_count']++;
			} elseif ( 'available' === $prop['status'] ) {
				$summary['disponibles']++;
			}
			$summary['pending_month'] += $prop['pending_month'];
		}

		return array(
			'period'   => $current_period,
			'today'    => $today,
			'props'    => $props,
			'summary'  => $summary,
			'statuses' => $statuses,
			'display'  => self::cobranza_alert_strings( $summary, $current_period ),
		);
	}

	/**
	 * Tone applied to the date/amount text of a property card.
	 *
	 * @param string $status Collection status.
	 * @return string
	 */
	private static function cobranza_due_class( $status ) {
		if ( in_array( $status, array( 'vencido', 'vence_hoy' ), true ) ) {
			return 'is-danger';
		}
		if ( 'vence_proximo' === $status ) {
			return 'is-warning';
		}
		return '';
	}

	/**
	 * Human sentence describing when the property is due.
	 *
	 * @param array  $prop  Property row from cobranza_snapshot().
	 * @param string $today Current date (Y-m-d).
	 * @return string
	 */
	private static function cobranza_due_hint( $prop, $today ) {
		$status = $prop['status'];
		$due    = $prop['next_due'];

		if ( 'vencido' === $status && $due ) {
			return sprintf(
				/* translators: %s: formatted date. */
				__( 'Vencido desde el %s', 'arriendo-facil' ),
				date_i18n( 'j M', strtotime( $due ) )
			);
		}
		if ( 'vence_hoy' === $status ) {
			return __( 'Vence hoy', 'arriendo-facil' );
		}
		if ( 'vence_proximo' === $status && $due ) {
			$days = (int) ceil( ( strtotime( $due ) - strtotime( $today ) ) / DAY_IN_SECONDS );
			$rel  = 1 === $days
				? __( 'mañana', 'arriendo-facil' )
				/* translators: %d: number of days. */
				: sprintf( _n( 'en %d día', 'en %d días', $days, 'arriendo-facil' ), $days );
			return sprintf(
				/* translators: 1: relative time, 2: formatted date. */
				__( 'Vence %1$s (%2$s)', 'arriendo-facil' ),
				$rel,
				date_i18n( 'j M', strtotime( $due ) )
			);
		}
		if ( 'por_cobrar' === $status && $due ) {
			return sprintf(
				/* translators: %s: formatted date. */
				__( 'Vence el %s', 'arriendo-facil' ),
				date_i18n( 'j M', strtotime( $due ) )
			);
		}
		if ( 'sin_cargo' === $status ) {
			return sprintf(
				/* translators: %d: day of the month the rent is due. */
				__( 'Día de pago: %d de cada mes', 'arriendo-facil' ),
				(int) $prop['payment_due_day']
			);
		}
		if ( 'aldia' === $status ) {
			return __( 'Todo al día', 'arriendo-facil' );
		}
		return __( 'Sin contrato activo', 'arriendo-facil' );
	}

	/**
	 * Ready-to-render strings for the alert strip at the top of the hub.
	 *
	 * They are produced here (server side) so the initial render and the
	 * in-place AJAX refresh can never disagree, and so pluralisation and
	 * translations stay in PHP.
	 *
	 * @param array  $summary Summary counters.
	 * @param string $period  Billing period (Y-m).
	 * @return array<string,array|null>
	 */
	private static function cobranza_alert_strings( $summary, $period ) {
		$vencido = null;
		if ( $summary['vencido_count'] > 0 ) {
			$vencido = array(
				'title' => sprintf(
					/* translators: %d: number of properties. */
					_n( '%d inmueble con cobros vencidos', '%d inmuebles con cobros vencidos', (int) $summary['vencido_count'], 'arriendo-facil' ),
					(int) $summary['vencido_count']
				),
				'text' => sprintf(
					/* translators: %s: amount. */
					__( '%s por cobrar. Revisa las tarjetas en rojo.', 'arriendo-facil' ),
					'$' . number_format_i18n( (float) $summary['vencido_total'], 2 )
				),
			);
		}

		$proximo = null;
		if ( $summary['proximo_count'] > 0 ) {
			$proximo = array(
				'title' => sprintf(
					/* translators: %d: number of properties. */
					_n( '%d inmueble vence en los próximos días', '%d inmuebles vencen en los próximos días', (int) $summary['proximo_count'], 'arriendo-facil' ),
					(int) $summary['proximo_count']
				),
				'text' => sprintf(
					/* translators: %s: amount. */
					__( '%s por cobrar. Prepara la cobranza.', 'arriendo-facil' ),
					'$' . number_format_i18n( (float) $summary['proximo_total'], 2 )
				),
			);
		}

		if ( $summary['vencido_count'] > 0 || $summary['proximo_count'] > 0 ) {
			$mes_text = sprintf(
				/* translators: %d: number of properties. */
				_n( '%d inmueble al día', '%d inmuebles al día', (int) $summary['aldia_count'], 'arriendo-facil' ),
				(int) $summary['aldia_count']
			);
		} else {
			$mes_text = __( 'Sin vencidos ni cobros próximos. Todo al día.', 'arriendo-facil' );
		}

		$mes = array(
			'title' => sprintf(
				/* translators: 1: amount, 2: billing period. */
				__( 'Total a cobrar este mes: %1$s (%2$s)', 'arriendo-facil' ),
				'$' . number_format_i18n( (float) $summary['pending_month'], 2 ),
				$period
			),
			'text'  => $mes_text,
		);

		return array(
			'vencido' => $vencido,
			'proximo' => $proximo,
			'mes'     => $mes,
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

		$result = self::upsert_service_schedule(
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
			$wpdb->prepare( 'SELECT * FROM ' . self::schedules_table() . ' WHERE id = %d', $schedule_id )
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

		$result = self::deactivate_service_schedule( $schedule_id );

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
		if ( '' === self::validate_period( $period ) ) {
			wp_send_json_error( array( 'message' => __( 'Periodo invalido. Usa el formato YYYY-MM.', 'arriendo-facil' ) ), 400 );
		}

		$scope = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
		$stats = self::generate_service_charges( $period, $scope );

		wp_send_json_success(
			array(
				/* translators: 1: created, 2: updated, 3: skipped, 4: without lease */
				'message' => sprintf( __( 'Vencimientos generados: %1$d nuevos, %2$d actualizados, %3$d sin monto, %4$d sin inquilino.', 'arriendo-facil' ), $stats['created'], $stats['updated'], $stats['skipped'], $stats['no_lease'] ),
				'stats'   => $stats,
			)
		);
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

		$result = self::record_payment(
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

		$result = self::create_charge(
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
		$result = self::generate_monthly_charges( $period, Arriendo_Facil_Tenancy::accessible_accommodation_ids() );

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
