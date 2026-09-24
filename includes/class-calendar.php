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
 * Exposes AJAX endpoints used by the dashboard interactive calendar:
 *  - af_calendar_events    -> events for a given month (visits, check-in/out, blocks)
 *  - af_calendar_add_visit -> registers a booked visit slot + booking
 *  - af_calendar_remove_visit -> removes a visit booking (and its slot)
 *  - af_calendar_add_block -> blocks a date for an accommodation
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
	 * Enqueues the interactive calendar assets only on the Panel screen.
	 *
	 * @param string $hook Current admin screen hook.
	 * @return void
	 */
	public function enqueue_dashboard_assets( $hook ) {
		if ( 'toplevel_page_arriendo-facil' !== $hook ) {
			return;
		}

		$css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-dashboard-calendar.css';
		$js_path  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-dashboard-calendar.js';

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
		$visits = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vs.visit_date, vs.start_time, vs.end_time, vb.guest_name, vb.accommodation_id,
				        p.post_title AS accommodation_title, vb.id AS booking_id, vs.id AS slot_id
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
				'meta'  => wp_date( 'H:i', strtotime( (string) $visit->start_time ) ),
				'accommodation' => (string) $visit->accommodation_title,
				'id'    => (int) $visit->booking_id,
				'removable' => true,
			);
		}

		// Check-in (inicio de contrato) — incluye borradores no eliminados.
		$checkins = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.start_date, l.accommodation_id, p.post_title AS accommodation_title,
				        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
				 FROM {$wpdb->prefix}af_leases l
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
				 WHERE l.deleted_at IS NULL AND l.start_date BETWEEN %s AND %s{$scope_clause}
				 ORDER BY l.start_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$from,
				$to
			)
		);
		foreach ( $checkins as $checkin ) {
			$events[ (string) $checkin->start_date ][] = array(
				'type'  => 'checkin',
				'label' => __( 'Check-in', 'arriendo-facil' ),
				'title' => trim( (string) $checkin->guest_name ) ? (string) $checkin->guest_name : __( 'Inquilino', 'arriendo-facil' ),
				'meta'  => '',
				'accommodation' => (string) $checkin->accommodation_title,
				'id'    => (int) $checkin->id,
			);
		}

		// Check-out (fin de contrato activo).
		$checkouts = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.end_date, l.accommodation_id, p.post_title AS accommodation_title,
				        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
				 FROM {$wpdb->prefix}af_leases l
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
				 WHERE l.deleted_at IS NULL AND l.status = 'active' AND l.end_date BETWEEN %s AND %s{$scope_clause}
				 ORDER BY l.end_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$from,
				$to
			)
		);
		foreach ( $checkouts as $checkout ) {
			$events[ (string) $checkout->end_date ][] = array(
				'type'  => 'checkout',
				'label' => __( 'Check-out', 'arriendo-facil' ),
				'title' => trim( (string) $checkout->guest_name ) ? (string) $checkout->guest_name : __( 'Inquilino', 'arriendo-facil' ),
				'meta'  => '',
				'accommodation' => (string) $checkout->accommodation_title,
				'id'    => (int) $checkout->id,
			);
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