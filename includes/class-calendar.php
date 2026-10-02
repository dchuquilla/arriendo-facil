<?php
/**
 * Interactive host calendar: month grid with visits, check-in/check-out and
 * availability blocks, plus quick actions to register visits and blocks.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Calendar
 *
 * Exposes AJAX endpoints used by the interactive calendar:
 *  - af_calendar_events       -> events for a given month (visits, move-ins,
 *                                contract check-outs, service due dates, blocks)
 *  - af_calendar_add_visit    -> registers a booked visit slot + booking
 *  - af_calendar_remove_visit -> removes a visit booking (and its slot)
 *  - af_calendar_add_move     -> registers a move-in (mudanza)
 *  - af_calendar_update_move  -> updates a move-in checklist / status
 *  - af_calendar_remove_move  -> removes a move-in
 *  - af_calendar_add_block    -> blocks a date for an accommodation
 *  - af_calendar_remove_block -> removes a calendar block
 */
class Arriendo_Facil_Calendar {

	const NONCE = 'af_calendar_nonce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_calendar_events', array( $this, 'ajax_events' ) );
		add_action( 'wp_ajax_af_calendar_add_visit', array( $this, 'ajax_add_visit' ) );
		add_action( 'wp_ajax_af_calendar_remove_visit', array( $this, 'ajax_remove_visit' ) );
		add_action( 'wp_ajax_af_calendar_visit_outcome', array( $this, 'ajax_visit_outcome' ) );
		add_action( 'wp_ajax_af_calendar_register_prospect', array( $this, 'ajax_register_prospect' ) );
		add_action( 'wp_ajax_af_calendar_add_move', array( $this, 'ajax_add_move' ) );
		add_action( 'wp_ajax_af_calendar_update_move', array( $this, 'ajax_update_move' ) );
		add_action( 'wp_ajax_af_calendar_remove_move', array( $this, 'ajax_remove_move' ) );
		add_action( 'wp_ajax_af_calendar_add_block', array( $this, 'ajax_add_block' ) );
		add_action( 'wp_ajax_af_calendar_remove_block', array( $this, 'ajax_remove_block' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ) );
	}

	/**
	 * Returns the calendar blocks table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'af_calendar_blocks';
	}

	/**
	 * Returns the move-ins (mudanzas) table name.
	 *
	 * @return string
	 */
	public static function moves_table() {
		global $wpdb;
		return $wpdb->prefix . 'af_calendar_moves';
	}

	/**
	 * Creates the move-ins table on first use, so installs updated without
	 * re-activation keep working.
	 *
	 * @return bool Whether the table can be queried.
	 */
	public static function ensure_moves_table() {
		global $wpdb;
		static $ready = null;

		if ( null !== $ready ) {
			return $ready;
		}

		$table = self::moves_table();

		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			$ready = true;
			return $ready;
		}

		$created = $wpdb->query( self::moves_table_sql( $wpdb->get_charset_collate() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$ready = false !== $created;

		return $ready;
	}

	/**
	 * CREATE TABLE statement of the move-ins table (shared with the activator).
	 *
	 * @param string $charset_collate Charset/collation clause.
	 * @return string
	 */
	public static function moves_table_sql( $charset_collate ) {
		return 'CREATE TABLE IF NOT EXISTS ' . self::moves_table() . " (
			id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			accommodation_id BIGINT(20) UNSIGNED NOT NULL,
			lease_id         BIGINT(20) UNSIGNED DEFAULT NULL,
			guest_id         BIGINT(20) UNSIGNED DEFAULT NULL,
			move_date        DATE NOT NULL,
			start_time       TIME DEFAULT NULL,
			end_time         TIME DEFAULT NULL,
			contact_name     VARCHAR(190) NOT NULL DEFAULT '',
			contact_phone    VARCHAR(50) DEFAULT NULL,
			contact_email    VARCHAR(190) DEFAULT NULL,
			tasks            VARCHAR(255) NOT NULL DEFAULT '',
			tasks_done       VARCHAR(255) NOT NULL DEFAULT '',
			status           VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			notes            TEXT DEFAULT NULL,
			created_by       BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY move_date (move_date),
			KEY accommodation_id (accommodation_id),
			KEY lease_id (lease_id)
		) {$charset_collate}";
	}

	/**
	 * Checklist of a move-in, in display order.
	 *
	 * @return array<string,string>
	 */
	public static function move_tasks() {
		return array(
			'keys'      => __( 'Entrega de llaves', 'arriendo-facil' ),
			'inventory' => __( 'Inventario y acta de entrega firmados', 'arriendo-facil' ),
			'meters'    => __( 'Lectura inicial de medidores', 'arriendo-facil' ),
			'deposit'   => __( 'Garantía y primer canon recibidos', 'arriendo-facil' ),
			'photos'    => __( 'Fotos del estado del inmueble', 'arriendo-facil' ),
			'access'    => __( 'Registro en portería / administración', 'arriendo-facil' ),
		);
	}

	/**
	 * Keeps only known checklist keys from a comma list or array.
	 *
	 * @param string|string[] $raw Raw keys.
	 * @return string[]
	 */
	private static function clean_task_keys( $raw ) {
		$keys = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$keys = array_map( 'sanitize_key', array_map( 'trim', $keys ) );

		return array_values( array_intersect( array_keys( self::move_tasks() ), $keys ) );
	}

	/**
	 * Builds the checklist payload of a move-in row.
	 *
	 * @param object $move Move row.
	 * @return array<int,array<string,mixed>>
	 */
	public static function move_checklist( $move ) {
		$labels = self::move_tasks();
		$done   = self::clean_task_keys( (string) $move->tasks_done );
		$out    = array();

		foreach ( self::clean_task_keys( (string) $move->tasks ) as $key ) {
			$out[] = array(
				'key'   => $key,
				'label' => $labels[ $key ],
				'done'  => in_array( $key, $done, true ),
			);
		}

		return $out;
	}

	/**
	 * Registered move-ins between two dates.
	 *
	 * @param string     $from      Y-m-d.
	 * @param string     $to        Y-m-d.
	 * @param int[]|null $scope_ids Accommodation scope, null = all.
	 * @param int        $limit     Max rows (0 = no limit).
	 * @return object[]
	 */
	public static function upcoming_moves( $from, $to, $scope_ids, $limit = 10 ) {
		global $wpdb;

		if ( ! self::ensure_moves_table() ) {
			return array();
		}

		$scope = is_array( $scope_ids ) ? ' AND m.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')' : '';
		$limit = $limit > 0 ? ' LIMIT ' . absint( $limit ) : '';

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.*, p.post_title AS accommodation_title
				 FROM ' . self::moves_table() . " m
				 LEFT JOIN {$wpdb->posts} p ON p.ID = m.accommodation_id
				 WHERE m.move_date BETWEEN %s AND %s AND m.status <> 'cancelled'{$scope}
				 ORDER BY m.move_date ASC, m.start_time ASC{$limit}", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$from,
				$to
			)
		);
	}

	/**
	 * Active contracts ending between two dates (the "próximos a salir").
	 *
	 * @param string     $from      Y-m-d.
	 * @param string     $to        Y-m-d.
	 * @param int[]|null $scope_ids Accommodation scope, null = all.
	 * @param int        $limit     Max rows (0 = no limit).
	 * @return object[]
	 */
	public static function upcoming_checkouts( $from, $to, $scope_ids, $limit = 10 ) {
		global $wpdb;

		$scope = is_array( $scope_ids ) ? ' AND l.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')' : '';
		$limit = $limit > 0 ? ' LIMIT ' . absint( $limit ) : '';

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, p.post_title AS accommodation_title,
				        CONCAT(g.first_name, ' ', g.last_name) AS guest_name, g.phone AS guest_phone
				 FROM {$wpdb->prefix}af_leases l
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
				 WHERE l.deleted_at IS NULL AND l.status = 'active' AND l.end_date BETWEEN %s AND %s{$scope}
				 ORDER BY l.end_date ASC{$limit}", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$from,
				$to
			)
		);
	}

	/**
	 * Contracts a move-in can be linked to (active or draft, in scope).
	 *
	 * @param int[]|null $scope_ids Accommodation scope, null = all.
	 * @return object[]
	 */
	public static function lease_options( $scope_ids ) {
		global $wpdb;

		$scope = is_array( $scope_ids ) ? ' AND l.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')' : '';

		return (array) $wpdb->get_results(
			"SELECT l.id, l.accommodation_id, l.start_date, l.status, p.post_title AS accommodation_title,
			        CONCAT(g.first_name, ' ', g.last_name) AS guest_name, g.phone AS guest_phone, g.email AS guest_email
			 FROM {$wpdb->prefix}af_leases l
			 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
			 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
			 WHERE l.deleted_at IS NULL AND l.guest_id > 0 AND l.status IN ('active', 'draft'){$scope}
			 ORDER BY l.start_date DESC
			 LIMIT 200" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Ecuador national holidays for a year (official dates, no decree-based bridge moves).
	 *
	 * @param int $year Four-digit year.
	 * @return array<string,string> Y-m-d => name.
	 */
	public static function ecuador_holidays( $year ) {
		$year = (int) $year;

		// Anonymous Gregorian (Meeus/Jones/Butcher) computus.
		$a = $year % 19;
		$b = intdiv( $year, 100 );
		$c = $year % 100;
		$d = intdiv( $b, 4 );
		$e = $b % 4;
		$f = intdiv( $b + 8, 25 );
		$g = intdiv( $b - $f + 1, 3 );
		$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 );
		$k = $c % 4;
		$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$easter = gmmktime( 0, 0, 0, intdiv( $h + $l - 7 * $m + 114, 31 ), ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1, $year );

		$rel = static function ( $days ) use ( $easter ) {
			return gmdate( 'Y-m-d', $easter + $days * DAY_IN_SECONDS );
		};

		$holidays = array(
			$year . '-01-01' => __( 'Año Nuevo', 'arriendo-facil' ),
			$rel( -48 )      => __( 'Carnaval (lunes)', 'arriendo-facil' ),
			$rel( -47 )      => __( 'Carnaval (martes)', 'arriendo-facil' ),
			$rel( -2 )       => __( 'Viernes Santo', 'arriendo-facil' ),
			$year . '-05-01' => __( 'Día del Trabajo', 'arriendo-facil' ),
			$year . '-05-24' => __( 'Batalla de Pichincha', 'arriendo-facil' ),
			$year . '-08-10' => __( 'Primer Grito de Independencia', 'arriendo-facil' ),
			$year . '-10-09' => __( 'Independencia de Guayaquil', 'arriendo-facil' ),
			$year . '-11-02' => __( 'Día de los Difuntos', 'arriendo-facil' ),
			$year . '-11-03' => __( 'Independencia de Cuenca', 'arriendo-facil' ),
			$year . '-12-25' => __( 'Navidad', 'arriendo-facil' ),
		);
		ksort( $holidays );

		return $holidays;
	}

	/**
	 * Enqueues the interactive calendar assets on the Panel and the
	 * standalone "Calendario" page.
	 *
	 * @param string $hook Current admin screen hook.
	 * @return void
	 */
	public function enqueue_dashboard_assets( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_arriendo-facil', 'arriendo-facil_page_af-calendar' ), true ) ) {
			return;
		}

		$is_dashboard = ( 'toplevel_page_arriendo-facil' === $hook );

		$css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-dashboard-calendar.css';
		$js_path  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-dashboard-calendar.js';

		if ( ! $is_dashboard ) {
			wp_enqueue_style( 'af-dashboard' );
		}

		wp_enqueue_style(
			'af-dashboard-calendar',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-dashboard-calendar.css',
			array( 'af-dashboard' ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : ARRIENDO_FACIL_VERSION
		);

		wp_enqueue_script(
			'af-dashboard-calendar',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-dashboard-calendar.js',
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : ARRIENDO_FACIL_VERSION,
			true
		);

		wp_localize_script(
			'af-dashboard-calendar',
			'afDashboardCalendar',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'holidays' => array_merge(
					self::ecuador_holidays( (int) wp_date( 'Y' ) - 1 ),
					self::ecuador_holidays( (int) wp_date( 'Y' ) ),
					self::ecuador_holidays( (int) wp_date( 'Y' ) + 1 ),
					self::ecuador_holidays( (int) wp_date( 'Y' ) + 2 )
				),
			)
		);
	}

	/**
	 * Guards a calendar AJAX request: nonce + capability.
	 *
	 * @return void
	 */
	private function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos para esta acción.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * AJAX: events for a month.
	 *
	 * @return void
	 */
	public function ajax_events() {
		$this->guard();

		$month = isset( $_REQUEST['month'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['month'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			wp_send_json_error( array( 'message' => __( 'Mes inválido.', 'arriendo-facil' ) ) );
		}

		$from = $month . '-01';
		$to   = gmdate( 'Y-m-d', strtotime( $from . ' +1 month -1 day' ) );

		wp_send_json_success( $this->events_between( $from, $to ) );
	}

	/**
	 * Builds the events payload between two inclusive dates.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function events_between( $from, $to ) {
		global $wpdb;

		$scope_ids = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
		$scope_clause = null === $scope_ids ? '' : ' AND accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';
		$visit_scope_clause = null === $scope_ids ? '' : ' AND vb.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';

		$events = array();

		// Visitas agendadas (confirmadas/completadas).
		$has_outcome = self::ensure_visit_outcome_columns();
		$visits = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vs.visit_date, vs.start_time, vs.end_time, vb.guest_name, vb.accommodation_id,
				        p.post_title AS accommodation_title, vb.id AS booking_id, vs.id AS slot_id" . ( $has_outcome ? ', vb.outcome, vb.rating' : '' ) . "
				 FROM {$wpdb->prefix}af_visit_bookings vb
				 LEFT JOIN {$wpdb->prefix}af_visit_slots vs ON vs.id = vb.slot_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = vb.accommodation_id
				 WHERE vs.visit_date BETWEEN %s AND %s AND vb.status IN ('confirmed', 'completed'){$visit_scope_clause}
				 ORDER BY vs.visit_date ASC, vs.start_time ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$from,
				$to
			)
		);
		foreach ( $visits as $visit ) {
			$events[ (string) $visit->visit_date ][] = array(
				'type'  => 'visit',
				'label' => __( 'Visita', 'arriendo-facil' ),
				'title' => trim( (string) $visit->guest_name ) ? (string) $visit->guest_name : __( 'Visitante', 'arriendo-facil' ),
				'time'  => substr( (string) $visit->start_time, 0, 5 ),
				'meta'  => substr( (string) $visit->start_time, 0, 5 ),
				'accommodation' => (string) $visit->accommodation_title,
				'id'    => (int) $visit->booking_id,
				'outcome' => isset( $visit->outcome ) ? (string) $visit->outcome : 'pending',
				'rating'  => isset( $visit->rating ) ? (string) $visit->rating : '',
				'removable' => true,
			);
		}

		// Mudanzas (check-in) registradas desde el calendario.
		foreach ( self::upcoming_moves( $from, $to, $scope_ids, 0 ) as $move ) {
			$checklist = self::move_checklist( $move );
			$done      = count( array_filter( wp_list_pluck( $checklist, 'done' ) ) );
			$time      = $move->start_time ? substr( (string) $move->start_time, 0, 5 ) : '';
			if ( $time && $move->end_time ) {
				$time .= '–' . substr( (string) $move->end_time, 0, 5 );
			}

			$events[ (string) $move->move_date ][] = array(
				'type'          => 'checkin',
				'label'         => __( 'Mudanza', 'arriendo-facil' ),
				'title'         => trim( (string) $move->contact_name ) ? (string) $move->contact_name : __( 'Inquilino', 'arriendo-facil' ),
				'time'          => $move->start_time ? substr( (string) $move->start_time, 0, 5 ) : '',
				'meta'          => trim( $time . ( $checklist ? ' · ' . sprintf( /* translators: 1: done, 2: total */ __( '%1$d/%2$d tareas', 'arriendo-facil' ), $done, count( $checklist ) ) : '' ), ' ·' ),
				'accommodation' => (string) $move->accommodation_title,
				'phone'         => (string) $move->contact_phone,
				'notes'         => (string) $move->notes,
				'status'        => 'done' === $move->status ? 'done' : 'scheduled',
				'checklist'     => $checklist,
				'id'            => (int) $move->id,
				'removable'     => true,
			);
		}

		// Check-out: fin de los contratos activos registrados en Contratos.
		foreach ( self::upcoming_checkouts( $from, $to, $scope_ids, 0 ) as $checkout ) {
			$events[ (string) $checkout->end_date ][] = array(
				'type'          => 'checkout',
				'label'         => __( 'Salida', 'arriendo-facil' ),
				'title'         => trim( (string) $checkout->guest_name ) ? (string) $checkout->guest_name : __( 'Inquilino', 'arriendo-facil' ),
				'meta'          => __( 'Fin de contrato', 'arriendo-facil' ),
				'accommodation' => (string) $checkout->accommodation_title,
				'url'           => admin_url( 'admin.php?page=af-leases' ),
				'id'            => (int) $checkout->id,
			);
		}

		// Vencimientos de servicios (agua, luz, gas, internet…) configurados en Pagos de servicios.
		if ( class_exists( 'Arriendo_Facil_Billing_Ledger' ) ) {
			$today  = current_time( 'Y-m-d' );
			$period = substr( $from, 0, 7 );
			$last   = substr( $to, 0, 7 );

			while ( $period <= $last ) {
				foreach ( Arriendo_Facil_Billing_Ledger::get_service_due_rows( $period, $scope_ids ) as $row ) {
					if ( empty( $row['is_active'] ) || ! $row['due_date'] || $row['due_date'] < $from || $row['due_date'] > $to ) {
						continue;
					}

					if ( 'paid' === $row['status'] || ! empty( $row['bill_paid'] ) ) {
						$state       = 'paid';
						$state_label = __( 'Pagado', 'arriendo-facil' );
					} elseif ( $row['due_date'] < $today ) {
						$state       = 'overdue';
						$state_label = __( 'Vencido', 'arriendo-facil' );
					} else {
						$state       = 'pending';
						$state_label = __( 'Por pagar', 'arriendo-facil' );
					}

					$amount = $row['has_charge'] ? (float) $row['charge_amount'] : (float) $row['expected_amount'];
					$place  = '' !== $row['unit_code'] ? $row['unit_code'] : (string) $row['accommodation_title'];

					$events[ (string) $row['due_date'] ][] = array(
						'type'          => 'service',
						'label'         => (string) $row['service_label'],
						'title'         => $row['service_label'] . ( $place ? ' · ' . $place : '' ),
						'meta'          => $state_label . ( $amount > 0 ? ' · $' . number_format_i18n( $amount, 2 ) : '' ),
						'accommodation' => (string) $row['accommodation_title'],
						'status'        => $state,
						'url'           => admin_url( 'admin.php?page=af-meter-readings&period=' . $period ),
						'id'            => (int) $row['schedule_id'],
					);
				}

				$period = gmdate( 'Y-m', strtotime( $period . '-01 +1 month' ) );
			}
		}

		// Bloqueos de disponibilidad.
		$blocks = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.block_date, b.reason, b.accommodation_id, p.post_title AS accommodation_title
				 FROM {$wpdb->prefix}af_calendar_blocks b
				 LEFT JOIN {$wpdb->posts} p ON p.ID = b.accommodation_id
				 WHERE b.block_date BETWEEN %s AND %s{$scope_clause}
				 ORDER BY b.block_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$from,
				$to
			)
		);
		foreach ( $blocks as $block ) {
			$events[ (string) $block->block_date ][] = array(
				'type'  => 'block',
				'label' => __( 'Bloqueado', 'arriendo-facil' ),
				'title' => trim( (string) $block->reason ) ? (string) $block->reason : (string) $block->accommodation_title,
				'meta'  => '',
				'accommodation' => (string) $block->accommodation_title,
				'id'    => (int) $block->id,
				'removable' => true,
			);
		}

		ksort( $events );

		// Within a day, timed events first in chronological order (agenda style).
		foreach ( $events as $day => $day_events ) {
			usort(
				$day_events,
				static function ( $a, $b ) {
					$ta = isset( $a['time'] ) && '' !== $a['time'] ? $a['time'] : '99:99';
					$tb = isset( $b['time'] ) && '' !== $b['time'] ? $b['time'] : '99:99';
					return strcmp( $ta, $tb );
				}
			);
			$events[ $day ] = $day_events;
		}

		return $events;
	}

	/**
	 * Validates that an accommodation is within the caller's scope.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return bool
	 */
	private function accommodation_in_scope( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id || 'accommodation' !== get_post_type( $accommodation_id ) ) {
			return false;
		}
		$scope_ids = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
		return null === $scope_ids || in_array( $accommodation_id, array_map( 'intval', $scope_ids ), true );
	}

	/**
	 * AJAX: register a visit (booked slot + booking with guest contact).
	 *
	 * @return void
	 */
	public function ajax_add_visit() {
		$this->guard();

		global $wpdb;

		$accommodation_id = isset( $_REQUEST['accommodation_id'] ) ? absint( $_REQUEST['accommodation_id'] ) : 0;
		$date             = isset( $_REQUEST['date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date'] ) ) : '';
		$time             = isset( $_REQUEST['time'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['time'] ) ) : '10:00';
		$guest_name       = isset( $_REQUEST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['guest_name'] ) ) : '';
		$guest_email      = isset( $_REQUEST['guest_email'] ) ? sanitize_email( wp_unslash( $_REQUEST['guest_email'] ) ) : '';
		$guest_phone      = isset( $_REQUEST['guest_phone'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['guest_phone'] ) ) : '';
		$notes            = isset( $_REQUEST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['notes'] ) ) : '';

		if ( ! $this->accommodation_in_scope( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Inmueble inválido o fuera de tu alcance.', 'arriendo-facil' ) ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( array( 'message' => __( 'Fecha inválida.', 'arriendo-facil' ) ) );
		}
		if ( $date < wp_date( 'Y-m-d' ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pueden programar visitas en fechas pasadas.', 'arriendo-facil' ) ) );
		}
		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			wp_send_json_error( array( 'message' => __( 'Hora inválida.', 'arriendo-facil' ) ) );
		}
		if ( '' === trim( $guest_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Escribe el nombre del visitante.', 'arriendo-facil' ) ) );
		}

		$current_user_id = get_current_user_id();

		// Reutiliza el slot existente cuando ya fue creado para esa fecha/hora.
		$slot_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}af_visit_slots
				 WHERE accommodation_id = %d AND visit_date = %s AND start_time = %s",
				$accommodation_id,
				$date,
				$time
			)
		);

		if ( ! $slot_id ) {
			$wpdb->insert(
				$wpdb->prefix . 'af_visit_slots',
				array(
					'accommodation_id' => $accommodation_id,
					'visit_date'       => $date,
					'start_time'       => $time,
					'end_time'         => gmdate( 'H:i', strtotime( $time . ' +1 hour' ) ),
					'status'           => 'open',
					'created_by'       => $current_user_id,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d' )
			);
			$slot_id = (int) $wpdb->insert_id;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}af_visit_bookings WHERE slot_id = %d",
				$slot_id
			)
		);
		if ( $existing ) {
			wp_send_json_error( array( 'message' => __( 'Ya existe una visita confirmada en ese horario.', 'arriendo-facil' ) ) );
		}

		$wpdb->insert(
			$wpdb->prefix . 'af_visit_bookings',
			array(
				'slot_id'          => $slot_id,
				'accommodation_id' => $accommodation_id,
				'guest_name'       => $guest_name,
				'guest_email'      => $guest_email ? $guest_email : 'visita@local',
				'guest_phone'      => $guest_phone,
				'guest_id_number'  => '',
				'status'           => 'confirmed',
				'notes'            => $notes,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: visitor name, 2: time, 3: date */
					__( 'Visita de %1$s agendada a las %2$s el %3$s.', 'arriendo-facil' ),
					$guest_name,
					$time,
					wp_date( 'd/m/Y', strtotime( $date ) )
				),
				'date'    => $date,
			)
		);
	}

	/**
	 * AJAX: remove a visit booking (and its slot when left empty).
	 *
	 * @return void
	 */
	public function ajax_remove_visit() {
		$this->guard();

		global $wpdb;

		$booking_id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
		if ( ! $booking_id ) {
			wp_send_json_error( array( 'message' => __( 'Registro inválido.', 'arriendo-facil' ) ) );
		}

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, slot_id, accommodation_id FROM {$wpdb->prefix}af_visit_bookings WHERE id = %d",
				$booking_id
			)
		);
		if ( ! $booking || ! $this->accommodation_in_scope( (int) $booking->accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No se encontró la visita.', 'arriendo-facil' ) ) );
		}

		$wpdb->delete( $wpdb->prefix . 'af_visit_bookings', array( 'id' => $booking_id ), array( '%d' ) );
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}af_visit_bookings WHERE slot_id = %d",
				(int) $booking->slot_id
			)
		);
		if ( 0 === $remaining ) {
			$wpdb->delete( $wpdb->prefix . 'af_visit_slots', array( 'id' => (int) $booking->slot_id ), array( '%d' ) );
		}

		wp_send_json_success( array( 'message' => __( 'Visita cancelada.', 'arriendo-facil' ) ) );
	}

	/**
	 * Post-visit results a booking can have.
	 *
	 * @return array<string,string>
	 */
	public static function visit_outcomes() {
		return array(
			'pending'    => __( 'Sin resultado', 'arriendo-facil' ),
			'thinking'   => __( 'Indeciso · volverá', 'arriendo-facil' ),
			'not_closed' => __( 'No concretada', 'arriendo-facil' ),
			'no_show'    => __( 'No asistió', 'arriendo-facil' ),
			'registered' => __( 'Registrado como inquilino', 'arriendo-facil' ),
		);
	}

	/**
	 * Prospect ratings.
	 *
	 * @return array<string,string>
	 */
	public static function prospect_ratings() {
		return array(
			'A' => __( 'Muy interesado', 'arriendo-facil' ),
			'B' => __( 'Interesado, indeciso', 'arriendo-facil' ),
			'C' => __( 'Poco probable', 'arriendo-facil' ),
		);
	}

	/**
	 * Adds the post-visit columns to af_visit_bookings on first use.
	 *
	 * @return bool
	 */
	public static function ensure_visit_outcome_columns() {
		global $wpdb;
		static $ready = null;

		if ( null !== $ready ) {
			return $ready;
		}

		$table   = $wpdb->prefix . 'af_visit_bookings';
		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $columns ) {
			$ready = false;
			return $ready;
		}

		$add = array(
			'outcome'    => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
			'rating'     => 'CHAR(1) DEFAULT NULL',
			'guest_id'   => 'BIGINT(20) UNSIGNED DEFAULT NULL',
			'outcome_at' => 'DATETIME DEFAULT NULL',
		);
		foreach ( $add as $column => $definition ) {
			if ( ! in_array( $column, $columns, true ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		$ready = true;
		return $ready;
	}

	/**
	 * Identity used to group visits of the same person: phone, else real email, else name.
	 *
	 * @param object $row Booking row.
	 * @return string
	 */
	private static function prospect_key( $row ) {
		$phone = preg_replace( '/\D+/', '', (string) $row->guest_phone );
		if ( strlen( $phone ) >= 7 ) {
			return 'p:' . substr( $phone, -9 );
		}
		$email = strtolower( trim( (string) $row->guest_email ) );
		if ( is_email( $email ) && 'visita@local' !== $email ) {
			return 'e:' . $email;
		}
		return 'n:' . remove_accents( strtolower( trim( preg_replace( '/\s+/', ' ', (string) $row->guest_name ) ) ) );
	}

	/**
	 * Visit history grouped by person (prospects), most recent activity first.
	 *
	 * @param int[]|null $scope_ids Accommodation scope, null = all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function prospects( $scope_ids ) {
		global $wpdb;

		if ( ! self::ensure_visit_outcome_columns() ) {
			return array();
		}

		$scope = is_array( $scope_ids ) ? ' AND vb.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')' : '';
		$rows  = (array) $wpdb->get_results(
			"SELECT vb.id, vb.accommodation_id, vb.guest_name, vb.guest_email, vb.guest_phone, vb.notes,
			        vb.outcome, vb.rating, vb.guest_id, vs.visit_date, vs.start_time, p.post_title AS accommodation_title
			 FROM {$wpdb->prefix}af_visit_bookings vb
			 LEFT JOIN {$wpdb->prefix}af_visit_slots vs ON vs.id = vb.slot_id
			 LEFT JOIN {$wpdb->posts} p ON p.ID = vb.accommodation_id
			 WHERE vb.status IN ('confirmed', 'completed'){$scope}
			 ORDER BY vs.visit_date DESC, vs.start_time DESC
			 LIMIT 600" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$outcomes = self::visit_outcomes();
		$today    = current_time( 'Y-m-d' );
		$people   = array();

		foreach ( $rows as $row ) {
			$key   = self::prospect_key( $row );
			$email = 'visita@local' === (string) $row->guest_email ? '' : (string) $row->guest_email;
			$out   = isset( $outcomes[ (string) $row->outcome ] ) ? (string) $row->outcome : 'pending';

			if ( ! isset( $people[ $key ] ) ) {
				// Rows come newest first, so the first one carries the latest contact data.
				$people[ $key ] = array(
					'key'      => $key,
					'name'     => (string) $row->guest_name,
					'phone'    => (string) $row->guest_phone,
					'email'    => $email,
					'rating'   => '',
					'guest_id' => 0,
					'pending'  => 0,
					'visits'   => array(),
				);
			}

			$person = &$people[ $key ];
			if ( '' === $person['rating'] && $row->rating ) {
				$person['rating'] = (string) $row->rating;
			}
			if ( '' === $person['email'] && $email ) {
				$person['email'] = $email;
			}
			if ( ! $person['guest_id'] && $row->guest_id ) {
				$person['guest_id'] = (int) $row->guest_id;
			}
			if ( 'pending' === $out && (string) $row->visit_date <= $today ) {
				++$person['pending'];
			}
			$person['visits'][] = array(
				'id'            => (int) $row->id,
				'date'          => (string) $row->visit_date,
				'time'          => substr( (string) $row->start_time, 0, 5 ),
				'accommodation' => (string) $row->accommodation_title,
				'outcome'       => $out,
				'notes'         => (string) $row->notes,
				'upcoming'      => (string) $row->visit_date > $today,
			);
			unset( $person );
		}

		foreach ( $people as &$person ) {
			$person['status'] = $person['guest_id'] ? 'registered' : $person['visits'][0]['outcome'];
			if ( $person['guest_id'] ) {
				$person['profile_url'] = admin_url( 'admin.php?page=af-guests&view=profile&guest_id=' . $person['guest_id'] );
			}
		}
		unset( $person );

		return array_values( $people );
	}

	/**
	 * Loads a booking within the caller's scope or ends the request.
	 *
	 * @param int $booking_id Booking ID.
	 * @return object
	 */
	private function scoped_booking( $booking_id ) {
		global $wpdb;

		self::ensure_visit_outcome_columns();

		$booking = $booking_id ? $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}af_visit_bookings WHERE id = %d", $booking_id )
		) : null;
		if ( ! $booking || ! $this->accommodation_in_scope( (int) $booking->accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No se encontró la visita.', 'arriendo-facil' ) ) );
		}

		return $booking;
	}

	/**
	 * AJAX: saves the result of a visit (outcome, A/B/C rating, notes).
	 *
	 * @return void
	 */
	public function ajax_visit_outcome() {
		$this->guard();

		global $wpdb;

		$booking = $this->scoped_booking( isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0 );
		$outcome = isset( $_REQUEST['outcome'] ) ? sanitize_key( wp_unslash( $_REQUEST['outcome'] ) ) : '';
		$rating  = isset( $_REQUEST['rating'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['rating'] ) ) ) : '';
		$notes   = isset( $_REQUEST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['notes'] ) ) : null;

		if ( ! isset( self::visit_outcomes()[ $outcome ] ) || 'registered' === $outcome ) {
			wp_send_json_error( array( 'message' => __( 'Resultado inválido.', 'arriendo-facil' ) ) );
		}
		if ( '' !== $rating && ! isset( self::prospect_ratings()[ $rating ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Calificación inválida.', 'arriendo-facil' ) ) );
		}

		$data = array(
			'outcome'    => $outcome,
			'rating'     => '' === $rating ? null : $rating,
			'outcome_at' => current_time( 'mysql' ),
			'status'     => 'no_show' === $outcome ? 'confirmed' : 'completed',
		);
		if ( null !== $notes ) {
			$data['notes'] = $notes;
		}
		$wpdb->update( $wpdb->prefix . 'af_visit_bookings', $data, array( 'id' => (int) $booking->id ) );

		wp_send_json_success( array( 'message' => __( 'Resultado de la visita guardado.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: turns a visitor into a tenant (af_guests) in one step, reusing
	 * an existing record with the same cédula or email.
	 *
	 * @return void
	 */
	public function ajax_register_prospect() {
		$this->guard();

		global $wpdb;

		$booking   = $this->scoped_booking( isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0 );
		$name      = isset( $_REQUEST['name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['name'] ) ) : '';
		$email     = isset( $_REQUEST['email'] ) ? sanitize_email( wp_unslash( $_REQUEST['email'] ) ) : '';
		$phone     = isset( $_REQUEST['phone'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_REQUEST['phone'] ) ) ) : '';
		$id_number = isset( $_REQUEST['id_number'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_REQUEST['id_number'] ) ) ) : '';
		$rating    = isset( $_REQUEST['rating'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['rating'] ) ) ) : '';

		$parts = preg_split( '/\s+/', trim( $name ) );
		if ( '' === $parts[0] || ! $email || ! $phone || ! $id_number ) {
			wp_send_json_error( array( 'message' => __( 'Completa nombre, cédula, teléfono y correo.', 'arriendo-facil' ) ) );
		}
		if ( ! is_email( $email ) || 'visita@local' === $email ) {
			wp_send_json_error( array( 'message' => __( 'Correo electrónico inválido.', 'arriendo-facil' ) ) );
		}
		if ( 1 !== preg_match( '/^[0-9]{7,10}$/', $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'El teléfono debe tener entre 7 y 10 dígitos.', 'arriendo-facil' ) ) );
		}
		$doc_type = 13 === strlen( $id_number ) ? 'ruc' : 'cedula';
		if ( 1 !== preg_match( '/^[0-9]{10}([0-9]{3})?$/', $id_number ) || ! Arriendo_Facil_Identity_Validator::validate( $doc_type, $id_number ) ) {
			wp_send_json_error( array( 'message' => __( 'La cédula o RUC no es válido.', 'arriendo-facil' ) ) );
		}

		$schema = Arriendo_Facil_Guest::ensure_guest_extra_columns();
		if ( is_wp_error( $schema ) ) {
			wp_send_json_error( array( 'message' => $schema->get_error_message() ) );
		}

		$guests   = $wpdb->prefix . 'af_guests';
		$guest_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$guests} WHERE id_number = %s OR email = %s ORDER BY id_number = %s DESC LIMIT 1", $id_number, $email, $id_number ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $guest_id ) {
			if ( ! Arriendo_Facil_Tenancy::can_access_guest( $guest_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Ya existe un inquilino con esa cédula o correo administrado por otra cuenta.', 'arriendo-facil' ) ) );
			}
			$reused = true;
		} else {
			$inserted = $wpdb->insert(
				$guests,
				array(
					'first_name'       => $parts[0],
					'last_name'        => trim( implode( ' ', array_slice( $parts, 1 ) ) ),
					'email'            => $email,
					'phone'            => $phone,
					'id_number'        => $id_number,
					'accommodation_id' => (int) $booking->accommodation_id,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d' )
			);
			if ( ! $inserted ) {
				wp_send_json_error( array( 'message' => __( 'No se pudo registrar el inquilino.', 'arriendo-facil' ) ) );
			}
			$guest_id = (int) $wpdb->insert_id;
			$reused   = false;
		}

		$wpdb->update(
			$wpdb->prefix . 'af_visit_bookings',
			array(
				'outcome'    => 'registered',
				'rating'     => isset( self::prospect_ratings()[ $rating ] ) ? $rating : 'A',
				'guest_id'   => $guest_id,
				'outcome_at' => current_time( 'mysql' ),
				'status'     => 'completed',
			),
			array( 'id' => (int) $booking->id )
		);

		wp_send_json_success(
			array(
				'message'     => $reused
					? __( 'Ya estaba registrado: se vinculó la visita a su ficha.', 'arriendo-facil' )
					: __( 'Inquilino registrado. Completa documentos y contrato desde su ficha.', 'arriendo-facil' ),
				'guest_id'    => $guest_id,
				'profile_url' => admin_url( 'admin.php?page=af-guests&view=profile&guest_id=' . $guest_id ),
			)
		);
	}

	/**
	 * Normalises an optional HH:MM time.
	 *
	 * @param string $key Request key.
	 * @return string|null Null when empty.
	 */
	private static function request_time( $key ) {
		$value = isset( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guarded by caller.
		if ( '' === $value ) {
			return null;
		}
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
			wp_send_json_error( array( 'message' => __( 'Hora inválida.', 'arriendo-facil' ) ) );
		}
		return $value;
	}

	/**
	 * AJAX: register a move-in (mudanza), optionally linked to a contract.
	 *
	 * @return void
	 */
	public function ajax_add_move() {
		$this->guard();

		global $wpdb;

		if ( ! self::ensure_moves_table() ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo preparar el registro de mudanzas.', 'arriendo-facil' ) ) );
		}

		$accommodation_id = isset( $_REQUEST['accommodation_id'] ) ? absint( $_REQUEST['accommodation_id'] ) : 0;
		$lease_id         = isset( $_REQUEST['lease_id'] ) ? absint( $_REQUEST['lease_id'] ) : 0;
		$date             = isset( $_REQUEST['date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date'] ) ) : '';
		$contact_name     = isset( $_REQUEST['contact_name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['contact_name'] ) ) : '';
		$contact_phone    = isset( $_REQUEST['contact_phone'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['contact_phone'] ) ) : '';
		$contact_email    = isset( $_REQUEST['contact_email'] ) ? sanitize_email( wp_unslash( $_REQUEST['contact_email'] ) ) : '';
		$notes            = isset( $_REQUEST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['notes'] ) ) : '';
		$tasks            = self::clean_task_keys( isset( $_REQUEST['tasks'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tasks'] ) ) : '' );
		$start_time       = self::request_time( 'start_time' );
		$end_time         = self::request_time( 'end_time' );
		$guest_id         = 0;

		if ( ! $lease_id ) {
			wp_send_json_error( array( 'message' => __( 'Primero registra al inquilino y su contrato; luego podrás programar la mudanza.', 'arriendo-facil' ) ) );
		}

		if ( $lease_id ) {
			if ( ! Arriendo_Facil_Tenancy::can_access_lease( $lease_id ) ) {
				wp_send_json_error( array( 'message' => __( 'No tienes acceso a ese contrato.', 'arriendo-facil' ) ) );
			}
			$lease = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT l.id, l.accommodation_id, l.guest_id, g.first_name, g.last_name, g.phone, g.email
					 FROM {$wpdb->prefix}af_leases l
					 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
					 WHERE l.id = %d AND l.deleted_at IS NULL",
					$lease_id
				)
			);
			if ( ! $lease ) {
				wp_send_json_error( array( 'message' => __( 'Contrato no encontrado.', 'arriendo-facil' ) ) );
			}
			// The contract decides the property, so both can never disagree.
			$accommodation_id = (int) $lease->accommodation_id;
			$guest_id         = (int) $lease->guest_id;
			if ( ! $guest_id ) {
				wp_send_json_error( array( 'message' => __( 'El contrato no tiene un inquilino registrado.', 'arriendo-facil' ) ) );
			}
			$contact_name  = trim( $lease->first_name . ' ' . $lease->last_name );
			$contact_phone = (string) $lease->phone;
			$contact_email = (string) $lease->email;
		}

		if ( ! $this->accommodation_in_scope( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Inmueble inválido o fuera de tu alcance.', 'arriendo-facil' ) ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( array( 'message' => __( 'Fecha inválida.', 'arriendo-facil' ) ) );
		}
		if ( $date < wp_date( 'Y-m-d' ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pueden programar mudanzas en fechas pasadas.', 'arriendo-facil' ) ) );
		}
		if ( $start_time && $end_time && $end_time <= $start_time ) {
			wp_send_json_error( array( 'message' => __( 'La hora de fin debe ser posterior a la de inicio.', 'arriendo-facil' ) ) );
		}
		if ( '' === trim( $contact_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Selecciona quién se muda.', 'arriendo-facil' ) ) );
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::moves_table() . " WHERE accommodation_id = %d AND move_date = %s AND status <> 'cancelled'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$accommodation_id,
				$date
			)
		);
		if ( $existing ) {
			wp_send_json_error( array( 'message' => __( 'Ya hay una mudanza registrada ese día para el inmueble.', 'arriendo-facil' ) ) );
		}

		$inserted = $wpdb->insert(
			self::moves_table(),
			array(
				'accommodation_id' => $accommodation_id,
				'lease_id'         => $lease_id ? $lease_id : null,
				'guest_id'         => $guest_id ? $guest_id : null,
				'move_date'        => $date,
				'start_time'       => $start_time,
				'end_time'         => $end_time,
				'contact_name'     => $contact_name,
				'contact_phone'    => $contact_phone,
				'contact_email'    => $contact_email,
				'tasks'            => implode( ',', $tasks ),
				'tasks_done'       => '',
				'status'           => 'scheduled',
				'notes'            => $notes,
				'created_by'       => get_current_user_id(),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo registrar la mudanza.', 'arriendo-facil' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: person name, 2: date */
					__( 'Mudanza de %1$s registrada para el %2$s.', 'arriendo-facil' ),
					$contact_name,
					wp_date( 'd/m/Y', strtotime( $date ) )
				),
				'date'    => $date,
			)
		);
	}

	/**
	 * Loads a move-in the caller can manage, or ends the request.
	 *
	 * @return object
	 */
	private function require_move() {
		global $wpdb;

		$move_id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guarded by caller.
		$move    = ( $move_id && self::ensure_moves_table() )
			? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::moves_table() . ' WHERE id = %d', $move_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: null;

		if ( ! $move || ! $this->accommodation_in_scope( (int) $move->accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No se encontró la mudanza.', 'arriendo-facil' ) ) );
		}

		return $move;
	}

	/**
	 * AJAX: tick checklist tasks and/or mark a move-in done.
	 *
	 * @return void
	 */
	public function ajax_update_move() {
		$this->guard();

		global $wpdb;

		$move = $this->require_move();
		$data = array();

		if ( isset( $_REQUEST['tasks_done'] ) ) {
			$planned            = self::clean_task_keys( (string) $move->tasks );
			$done               = self::clean_task_keys( sanitize_text_field( wp_unslash( $_REQUEST['tasks_done'] ) ) );
			$data['tasks_done'] = implode( ',', array_values( array_intersect( $planned, $done ) ) );
		}

		if ( isset( $_REQUEST['status'] ) ) {
			$status = sanitize_key( wp_unslash( $_REQUEST['status'] ) );
			if ( ! in_array( $status, array( 'scheduled', 'done' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Estado inválido.', 'arriendo-facil' ) ) );
			}
			$data['status'] = $status;
		}

		if ( ! $data ) {
			wp_send_json_error( array( 'message' => __( 'Nada que actualizar.', 'arriendo-facil' ) ) );
		}

		$wpdb->update( self::moves_table(), $data, array( 'id' => (int) $move->id ), null, array( '%d' ) );

		wp_send_json_success(
			array(
				'message' => __( 'Mudanza actualizada.', 'arriendo-facil' ),
				'date'    => (string) $move->move_date,
			)
		);
	}

	/**
	 * AJAX: remove a move-in.
	 *
	 * @return void
	 */
	public function ajax_remove_move() {
		$this->guard();

		global $wpdb;

		$move = $this->require_move();
		$wpdb->delete( self::moves_table(), array( 'id' => (int) $move->id ), array( '%d' ) );

		wp_send_json_success( array( 'message' => __( 'Mudanza eliminada.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: block a date for an accommodation.
	 *
	 * @return void
	 */
	public function ajax_add_block() {
		$this->guard();

		global $wpdb;

		$accommodation_id = isset( $_REQUEST['accommodation_id'] ) ? absint( $_REQUEST['accommodation_id'] ) : 0;
		$date             = isset( $_REQUEST['date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date'] ) ) : '';
		$reason           = isset( $_REQUEST['reason'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['reason'] ) ) : '';

		if ( ! $this->accommodation_in_scope( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Inmueble inválido o fuera de tu alcance.', 'arriendo-facil' ) ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( array( 'message' => __( 'Fecha inválida.', 'arriendo-facil' ) ) );
		}
		if ( $date < wp_date( 'Y-m-d' ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pueden bloquear días en fechas pasadas.', 'arriendo-facil' ) ) );
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}af_calendar_blocks WHERE accommodation_id = %d AND block_date = %s",
				$accommodation_id,
				$date
			)
		);
		if ( $existing ) {
			wp_send_json_error( array( 'message' => __( 'Ese día ya está bloqueado para el inmueble.', 'arriendo-facil' ) ) );
		}

		$wpdb->insert(
			$wpdb->prefix . 'af_calendar_blocks',
			array(
				'accommodation_id' => $accommodation_id,
				'block_date'       => $date,
				'reason'           => $reason,
				'created_by'       => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d' )
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: date */
					__( 'Día %s bloqueado.', 'arriendo-facil' ),
					wp_date( 'd/m/Y', strtotime( $date ) )
				),
				'date'    => $date,
			)
		);
	}

	/**
	 * AJAX: remove a calendar block.
	 *
	 * @return void
	 */
	public function ajax_remove_block() {
		$this->guard();

		global $wpdb;

		$block_id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
		if ( ! $block_id ) {
			wp_send_json_error( array( 'message' => __( 'Registro inválido.', 'arriendo-facil' ) ) );
		}

		$block = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, accommodation_id FROM {$wpdb->prefix}af_calendar_blocks WHERE id = %d",
				$block_id
			)
		);
		if ( ! $block || ! $this->accommodation_in_scope( (int) $block->accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No se encontró el bloqueo.', 'arriendo-facil' ) ) );
		}

		$wpdb->delete( $wpdb->prefix . 'af_calendar_blocks', array( 'id' => $block_id ), array( '%d' ) );

		wp_send_json_success( array( 'message' => __( 'Bloqueo eliminado.', 'arriendo-facil' ) ) );
	}
}