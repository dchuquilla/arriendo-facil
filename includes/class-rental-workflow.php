<?php
/**
 * Rental workflow orchestration.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Rental_Workflow
 *
 * Handles visit slots, visit bookings, interest queue, reservation holds,
 * lease lifecycle actions (renew/finalize), and manual accommodation release.
 */
class Arriendo_Facil_Rental_Workflow {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_create_visit_slot', array( $this, 'ajax_create_visit_slot' ) );
		add_action( 'wp_ajax_af_get_visit_slots', array( $this, 'ajax_get_visit_slots' ) );
		add_action( 'wp_ajax_nopriv_af_get_visit_slots', array( $this, 'ajax_get_visit_slots' ) );
		add_action( 'wp_ajax_af_book_visit_slot', array( $this, 'ajax_book_visit_slot' ) );
		add_action( 'wp_ajax_nopriv_af_book_visit_slot', array( $this, 'ajax_book_visit_slot' ) );
		add_action( 'wp_ajax_af_create_reservation_intent', array( $this, 'ajax_create_reservation_intent' ) );
		add_action( 'wp_ajax_nopriv_af_create_reservation_intent', array( $this, 'ajax_create_reservation_intent' ) );
		add_action( 'wp_ajax_af_join_interest_queue', array( $this, 'ajax_join_interest_queue' ) );
		add_action( 'wp_ajax_nopriv_af_join_interest_queue', array( $this, 'ajax_join_interest_queue' ) );
		add_action( 'wp_ajax_af_get_interest_stats', array( $this, 'ajax_get_interest_stats' ) );
		add_action( 'wp_ajax_af_get_interest_queue', array( $this, 'ajax_get_interest_queue' ) );
		add_action( 'wp_ajax_af_create_reservation_hold', array( $this, 'ajax_create_reservation_hold' ) );
		add_action( 'wp_ajax_af_release_reservation_hold', array( $this, 'ajax_release_reservation_hold' ) );
		add_action( 'wp_ajax_af_finalize_lease_manual_release', array( $this, 'ajax_finalize_lease_manual_release' ) );
		add_action( 'wp_ajax_af_renew_lease', array( $this, 'ajax_renew_lease' ) );
		add_action( 'wp_ajax_af_execute_manual_release', array( $this, 'ajax_execute_manual_release' ) );
		add_action( 'wp_ajax_af_get_accommodation_availability', array( $this, 'ajax_get_accommodation_availability' ) );
		add_action( 'wp_ajax_nopriv_af_get_accommodation_availability', array( $this, 'ajax_get_accommodation_availability' ) );
	}

	/**
	 * Returns whether an accommodation can start a new rental flow.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $guest_email      Optional guest email to validate appointment requirement.
	 * @return array<string,mixed>
	 */
	public static function get_availability_summary( $accommodation_id, $guest_email = '' ) {
		$accommodation_id = absint( $accommodation_id );
		$guest_email      = sanitize_email( (string) $guest_email );
		if ( ! $accommodation_id || 'accommodation' !== get_post_type( $accommodation_id ) ) {
			return array(
				'can_start_flow' => false,
				'reason_code'    => 'invalid_accommodation',
				'message'        => __( 'Inmueble invalido.', 'arriendo-facil' ),
				'state'          => 'unavailable',
			);
		}

		$status = sanitize_key( (string) get_post_meta( $accommodation_id, '_af_status', true ) );
		$commercial_status = sanitize_key( (string) get_post_meta( $accommodation_id, '_af_commercial_status', true ) );

		if ( '' === $commercial_status ) {
			$legacy_state      = sanitize_key( (string) get_post_meta( $accommodation_id, '_af_commercial_state', true ) );
			$legacy_visibility = sanitize_key( (string) get_post_meta( $accommodation_id, '_af_commercial_visibility', true ) );
			if ( 'private' === $legacy_visibility ) {
				$commercial_status = 'private';
			} elseif ( in_array( $legacy_state, array( 'available', 'reserved', 'rented' ), true ) ) {
				$commercial_status = $legacy_state;
			}
		}

		if ( in_array( $commercial_status, array( 'reserved', 'rented', 'private' ), true ) ) {
			return array(
				'can_start_flow' => false,
				'reason_code'    => $commercial_status,
				'message'        => __( 'Este inmueble no esta disponible en este momento para iniciar un nuevo proceso de arriendo.', 'arriendo-facil' ),
				'state'          => $commercial_status,
				'status'         => $status,
			);
		}

		if ( in_array( $status, array( 'maintenance', 'inactive' ), true ) ) {
			return array(
				'can_start_flow' => false,
				'reason_code'    => 'maintenance_or_inactive',
				'message'        => __( 'Este inmueble no esta disponible en este momento.', 'arriendo-facil' ),
				'state'          => 'not_available',
				'status'         => $status,
			);
		}

		if ( self::has_active_lease_for_accommodation( $accommodation_id ) ) {
			return array(
				'can_start_flow' => false,
				'reason_code'    => 'active_lease',
				'message'        => __( 'Este inmueble ya tiene un contrato activo.', 'arriendo-facil' ),
				'state'          => 'rented',
				'status'         => $status,
			);
		}

		$reservation = self::get_active_reservation_for_accommodation( $accommodation_id );
		if ( $reservation ) {
			$hold_until = isset( $reservation->hold_until ) ? (string) $reservation->hold_until : '';
			return array(
				'can_start_flow' => false,
				'reason_code'    => 'reserved_hold',
				'message'        => __( 'Este inmueble se encuentra reservado en este momento.', 'arriendo-facil' ),
				'state'          => 'reserved',
				'status'         => $status,
				'hold_until'     => $hold_until,
			);
		}

		$visit_is_required = (bool) apply_filters( 'af_require_visit_before_rental_flow', true, $accommodation_id );
		if ( $visit_is_required && '' !== $guest_email && ! self::has_required_visit_booking( $accommodation_id, $guest_email ) ) {
			return array(
				'can_start_flow'      => false,
				'reason_code'         => 'requires_visit_booking',
				'message'             => __( 'Se requiere una visita confirmada antes de continuar el proceso de arriendo.', 'arriendo-facil' ),
				'state'               => 'requires_visit',
				'status'              => $status,
				'requires_visit_step' => true,
			);
		}

		return array(
			'can_start_flow' => true,
			'reason_code'    => 'available',
			'message'        => __( 'Inmueble disponible.', 'arriendo-facil' ),
			'state'          => 'available',
			'status'         => $status,
		);
	}

	/**
	 * AJAX: availability summary.
	 */
	public function ajax_get_accommodation_availability() {
		$this->verify_frontend_nonce_optional();

		$accommodation_id = isset( $_REQUEST['accommodation_id'] ) ? absint( wp_unslash( $_REQUEST['accommodation_id'] ) ) : 0;
		$guest_email      = isset( $_REQUEST['guest_email'] ) ? sanitize_email( wp_unslash( $_REQUEST['guest_email'] ) ) : '';
		wp_send_json_success( self::get_availability_summary( $accommodation_id, $guest_email ) );
	}

	/**
	 * AJAX: create visit slot.
	 */
	public function ajax_create_visit_slot() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;
		$visit_date       = isset( $_POST['visit_date'] ) ? sanitize_text_field( wp_unslash( $_POST['visit_date'] ) ) : '';
		$start_time       = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
		$end_time         = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';

		if ( ! $this->can_manage_accommodation( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No puedes gestionar este inmueble.', 'arriendo-facil' ) ), 403 );
		}

		if ( ! $this->is_valid_date( $visit_date ) || ! $this->is_valid_time( $start_time ) || ! $this->is_valid_time( $end_time ) || $end_time <= $start_time ) {
			wp_send_json_error( array( 'message' => __( 'Rango de fecha u hora invalido.', 'arriendo-facil' ) ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_visit_slots';

		$inserted = $wpdb->insert(
			$table,
			array(
				'accommodation_id' => $accommodation_id,
				'visit_date'       => $visit_date,
				'start_time'       => $start_time,
				'end_time'         => $end_time,
				'status'           => 'open',
				'created_by'       => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			$db_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
			$message  = '' !== $db_error && false !== stripos( $db_error, 'Duplicate' )
				? __( 'Esa fecha y hora ya estan reservadas.', 'arriendo-facil' )
				: __( 'No se pudo crear el cupo de visita.', 'arriendo-facil' );
			wp_send_json_error( array( 'message' => $message ), 400 );
		}

		wp_send_json_success(
			array(
				'id'      => (int) $wpdb->insert_id,
				'message' => __( 'Cupo de visita creado.', 'arriendo-facil' ),
			)
		);
	}

	/**
	 * AJAX: get visit slots.
	 */
	public function ajax_get_visit_slots() {
		$this->verify_frontend_nonce_optional();

		$accommodation_id = isset( $_REQUEST['accommodation_id'] ) ? absint( wp_unslash( $_REQUEST['accommodation_id'] ) ) : 0;
		if ( ! $accommodation_id ) {
			wp_send_json_error( array( 'message' => __( 'Inmueble invalido.', 'arriendo-facil' ) ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_visit_slots';
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		$is_admin_owner_request = is_user_logged_in() && current_user_can( 'edit_posts' ) && '' !== $nonce && wp_verify_nonce( $nonce, 'af_owner_contact_nonce' );

		if ( $is_admin_owner_request && ! $this->can_manage_accommodation( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		if ( $is_admin_owner_request ) {
			$slots = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, accommodation_id, visit_date, start_time, end_time, status
					 FROM {$table}
					 WHERE accommodation_id = %d
					 ORDER BY visit_date ASC, start_time ASC",
					$accommodation_id
				)
			);
		} else {
			$slots = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, accommodation_id, visit_date, start_time, end_time, status
					 FROM {$table}
					 WHERE accommodation_id = %d
					   AND status = %s
					   AND visit_date >= %s
					 ORDER BY visit_date ASC, start_time ASC",
					$accommodation_id,
					'open',
					gmdate( 'Y-m-d' )
				)
			);
		}

		$slots = is_array( $slots ) ? array_map(
			static function ( $slot ) {
				if ( ! is_object( $slot ) ) {
					return $slot;
				}
				$slot->available = ( isset( $slot->status ) && 'open' === (string) $slot->status );
				return $slot;
			},
			$slots
		) : array();

		wp_send_json_success( array( 'slots' => $slots ) );
	}

	/**
	 * AJAX: book visit slot.
	 */
	public function ajax_book_visit_slot() {
		$this->verify_frontend_nonce_required();

		$slot_id           = isset( $_POST['slot_id'] ) ? absint( wp_unslash( $_POST['slot_id'] ) ) : 0;
		$guest_name        = isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '';
		$guest_email       = isset( $_POST['guest_email'] ) ? sanitize_email( wp_unslash( $_POST['guest_email'] ) ) : '';
		$guest_phone       = isset( $_POST['guest_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_phone'] ) ) : '';
		$guest_id_number   = isset( $_POST['guest_id_number'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_id_number'] ) ) : '';
		$notes             = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( ! $slot_id || '' === $guest_name || ! is_email( $guest_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Faltan datos obligatorios para agendar la visita.', 'arriendo-facil' ) ), 400 );
		}

		global $wpdb;
		$slots_table    = $wpdb->prefix . 'af_visit_slots';
		$bookings_table = $wpdb->prefix . 'af_visit_bookings';

		$slot = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$slots_table} WHERE id = %d LIMIT 1",
				$slot_id
			)
		);

		if ( ! $slot || 'open' !== (string) $slot->status ) {
			wp_send_json_error( array( 'message' => __( 'Este cupo ya no esta disponible.', 'arriendo-facil' ), 'code' => 409 ), 409 );
		}

		$inserted_booking = $wpdb->insert(
			$bookings_table,
			array(
				'slot_id'         => $slot_id,
				'accommodation_id' => (int) $slot->accommodation_id,
				'guest_name'      => $guest_name,
				'guest_email'     => $guest_email,
				'guest_phone'     => $guest_phone,
				'guest_id_number' => $guest_id_number,
				'status'          => 'confirmed',
				'notes'           => $notes,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted_booking ) {
			wp_send_json_error( array( 'message' => __( 'Ese cupo ya fue reservado.', 'arriendo-facil' ), 'code' => 409 ), 409 );
		}

		$wpdb->update(
			$slots_table,
			array( 'status' => 'booked' ),
			array( 'id' => $slot_id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->notify_owner_about_interest( (int) $slot->accommodation_id, __( 'Nueva visita agendada.', 'arriendo-facil' ), $guest_name, $guest_email, $guest_phone, (string) $slot->visit_date, (string) $slot->start_time, $notes );
		$this->send_booking_confirmation_email( $guest_email, $guest_name, (int) $slot->accommodation_id, (string) $slot->visit_date, (string) $slot->start_time, (string) $slot->end_time );
		$this->dispatch_guest_profile_link_email_after_booking( (int) $slot->accommodation_id, (int) $wpdb->insert_id, $guest_name, $guest_email, $guest_phone );

		wp_send_json_success(
			array(
				'message' => __( 'Visita agendada correctamente.', 'arriendo-facil' ),
			)
		);
	}

	/**
	 * AJAX: creates a lightweight reservation intent from frontend CTA.
	 *
	 * Allows two paths:
	 * - Book a concrete visit slot when slot_id is provided.
	 * - Register a visit request in interest queue with preferred date/time.
	 */
	public function ajax_create_reservation_intent() {
		$this->verify_frontend_nonce_required();

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;
		$slot_id          = isset( $_POST['slot_id'] ) ? absint( wp_unslash( $_POST['slot_id'] ) ) : 0;
		$guest_name       = isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '';
		$guest_email      = isset( $_POST['guest_email'] ) ? sanitize_email( wp_unslash( $_POST['guest_email'] ) ) : '';
		$guest_phone      = isset( $_POST['guest_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_phone'] ) ) : '';
		$notes            = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$preferred_date   = isset( $_POST['preferred_date'] ) ? sanitize_text_field( wp_unslash( $_POST['preferred_date'] ) ) : '';
		$preferred_time   = isset( $_POST['preferred_time'] ) ? sanitize_text_field( wp_unslash( $_POST['preferred_time'] ) ) : '';

		if ( ! $accommodation_id || '' === $guest_name || ! is_email( $guest_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Faltan datos obligatorios para reservar.', 'arriendo-facil' ) ), 400 );
		}

		$availability = self::get_availability_summary( $accommodation_id, $guest_email );
		if ( empty( $availability['can_start_flow'] ) && ! in_array( (string) ( $availability['reason_code'] ?? '' ), array( 'requires_visit_booking', 'available' ), true ) ) {
			wp_send_json_error(
				array(
					'code'         => (string) ( $availability['reason_code'] ?? 'accommodation_unavailable' ),
					'message'      => (string) ( $availability['message'] ?? __( 'La acomodacion no esta disponible.', 'arriendo-facil' ) ),
					'availability' => $availability,
				),
				409
			);
		}

		global $wpdb;
		$slots_table    = $wpdb->prefix . 'af_visit_slots';
		$bookings_table = $wpdb->prefix . 'af_visit_bookings';
		$queue_table    = $wpdb->prefix . 'af_interest_queue';

		if ( $slot_id > 0 ) {
			$slot = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$slots_table} WHERE id = %d LIMIT 1",
					$slot_id
				)
			);

			if ( ! $slot || (int) $slot->accommodation_id !== $accommodation_id || 'open' !== (string) $slot->status ) {
				wp_send_json_error( array( 'message' => __( 'El cupo seleccionado ya no esta disponible.', 'arriendo-facil' ), 'code' => 409 ), 409 );
			}

			$inserted_booking = $wpdb->insert(
				$bookings_table,
				array(
					'slot_id'          => $slot_id,
					'accommodation_id' => $accommodation_id,
					'guest_name'       => $guest_name,
					'guest_email'      => $guest_email,
					'guest_phone'      => $guest_phone,
					'guest_id_number'  => '',
					'status'           => 'confirmed',
					'notes'            => $notes,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( ! $inserted_booking ) {
				wp_send_json_error( array( 'message' => __( 'Ese cupo ya fue reservado.', 'arriendo-facil' ), 'code' => 409 ), 409 );
			}

			$wpdb->update(
				$slots_table,
				array( 'status' => 'booked' ),
				array( 'id' => $slot_id ),
				array( '%s' ),
				array( '%d' )
			);

			$this->notify_owner_about_interest( $accommodation_id, __( 'Nueva visita agendada.', 'arriendo-facil' ), $guest_name, $guest_email, $guest_phone );
			$this->send_booking_confirmation_email( $guest_email, $guest_name, $accommodation_id, (string) $slot->visit_date, (string) $slot->start_time, (string) $slot->end_time );
			$this->dispatch_guest_profile_link_email_after_booking( $accommodation_id, (int) $wpdb->insert_id, $guest_name, $guest_email, $guest_phone );

			wp_send_json_success(
				array(
					'status'       => 'visit_booked',
					'booking_id'   => (int) $wpdb->insert_id,
					'accommodation_id' => $accommodation_id,
					'message'      => __( 'Reserva registrada. Tu visita ya esta confirmada.', 'arriendo-facil' ),
				)
			);
		}

		$request_message = __( 'Solicitud de visita desde boton de reserva.', 'arriendo-facil' );
		if ( '' !== $preferred_date && $this->is_valid_date( $preferred_date ) ) {
			$request_message .= ' ' . sprintf( __( 'Fecha sugerida: %s.', 'arriendo-facil' ), $preferred_date );
		}
		if ( '' !== $preferred_time && $this->is_valid_time( $preferred_time ) ) {
			$request_message .= ' ' . sprintf( __( 'Hora sugerida: %s.', 'arriendo-facil' ), $preferred_time );
		}
		if ( '' !== trim( $notes ) ) {
			$request_message .= ' ' . sprintf( __( 'Notas: %s', 'arriendo-facil' ), $notes );
		}

		$existing_queue_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$queue_table} WHERE accommodation_id = %d AND email = %s ORDER BY id DESC LIMIT 1",
				$accommodation_id,
				$guest_email
			)
		);

		if ( $existing_queue_id > 0 ) {
			$wpdb->update(
				$queue_table,
				array(
					'name'    => $guest_name,
					'phone'   => $guest_phone,
					'message' => $request_message,
					'status'  => 'visit_requested',
				),
				array( 'id' => $existing_queue_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$queue_table,
				array(
					'accommodation_id' => $accommodation_id,
					'name'             => $guest_name,
					'email'            => $guest_email,
					'phone'            => $guest_phone,
					'message'          => $request_message,
					'status'           => 'visit_requested',
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		$this->notify_owner_about_interest( $accommodation_id, __( 'Nueva solicitud de visita recibida.', 'arriendo-facil' ), $guest_name, $guest_email, $guest_phone, $preferred_date, $preferred_time, $notes );

		// Profile link is sent after the admin confirms the visit (via admin panel or ajax_book_visit_slot).
		wp_send_json_success(
			array(
				'status'           => 'visit_requested',
				'accommodation_id' => $accommodation_id,
				'message'          => __( 'Solicitud registrada. Te contactaremos para confirmar la visita.', 'arriendo-facil' ),
			)
		);
	}

	/**
	 * AJAX: add lead to interest queue.
	 */
	public function ajax_join_interest_queue() {
		$this->verify_frontend_nonce_required();

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;
		$name             = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email            = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone            = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$message          = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( ! $accommodation_id || '' === $name || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Faltan datos obligatorios para la cola de interesados.', 'arriendo-facil' ) ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_interest_queue';

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE accommodation_id = %d AND email = %s AND status IN ('queued','notified','visit_requested') LIMIT 1",
				$accommodation_id,
				$email
			)
		);

		if ( $exists ) {
			wp_send_json_success( array( 'message' => __( 'Ya estas en la cola de interesados para este inmueble.', 'arriendo-facil' ) ) );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'accommodation_id' => $accommodation_id,
				'name'             => $name,
				'email'            => $email,
				'phone'            => $phone,
				'message'          => $message,
				'status'           => 'queued',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo agregar a la cola de interesados.', 'arriendo-facil' ) ) );
		}

		$this->notify_owner_about_interest( $accommodation_id, __( 'Nuevo interesado agregado a la cola.', 'arriendo-facil' ), $name, $email, $phone, '', '', $message );

		wp_send_json_success( array( 'message' => __( 'Agregado a la cola. Te notificaremos cuando este disponible.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: owner/admin interest stats.
	 */
	public function ajax_get_interest_stats() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_GET['accommodation_id'] ) ? absint( wp_unslash( $_GET['accommodation_id'] ) ) : 0;
		if ( ! $accommodation_id || ! $this->can_manage_accommodation( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		global $wpdb;
		$queue_table    = $wpdb->prefix . 'af_interest_queue';
		$bookings_table = $wpdb->prefix . 'af_visit_bookings';

		$queued_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$queue_table} WHERE accommodation_id = %d AND status IN ('queued','notified','visit_requested')",
				$accommodation_id
			)
		);

		$booked_visits_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings_table} WHERE accommodation_id = %d AND status IN ('confirmed','completed')",
				$accommodation_id
			)
		);

		$completed_visits_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings_table} WHERE accommodation_id = %d AND status = 'completed'",
				$accommodation_id
			)
		);

		wp_send_json_success(
			array(
				'queue_count'      => $queued_count,
				'scheduled_visits' => $booked_visits_count,
				'completed_visits' => $completed_visits_count,
				'queued'           => $queued_count,
				'bookedVisits'     => $booked_visits_count,
				'total'            => $queued_count + $booked_visits_count,
			)
		);
	}

	/**
	 * AJAX: owner/admin detailed interest queue list.
	 */
	public function ajax_get_interest_queue() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_GET['accommodation_id'] ) ? absint( wp_unslash( $_GET['accommodation_id'] ) ) : 0;
		if ( ! $accommodation_id || ! $this->can_manage_accommodation( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_interest_queue';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, email, phone, message, status, created_at
				 FROM {$table}
				 WHERE accommodation_id = %d
				 ORDER BY created_at DESC, id DESC
				 LIMIT 100",
				$accommodation_id
			)
		);

		wp_send_json_success(
			array(
				'items' => is_array( $rows ) ? $rows : array(),
			)
		);
	}

	/**
	 * AJAX: create reservation hold after partial payment.
	 */
	public function ajax_create_reservation_hold() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id  = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;
		$guest_id          = isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0;
		$deposit_amount    = isset( $_POST['deposit_amount'] ) ? floatval( wp_unslash( $_POST['deposit_amount'] ) ) : 0.0;
		$hold_until        = isset( $_POST['hold_until'] ) ? sanitize_text_field( wp_unslash( $_POST['hold_until'] ) ) : '';
		$payment_reference = isset( $_POST['payment_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_reference'] ) ) : '';
		$notes             = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( ! $this->can_manage_accommodation( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No puedes gestionar este inmueble.', 'arriendo-facil' ) ), 403 );
		}

		if ( ! $this->is_valid_datetime( $hold_until ) || $deposit_amount < 0 ) {
			wp_send_json_error( array( 'message' => __( 'Datos de reserva invalidos.', 'arriendo-facil' ) ), 400 );
		}

		$availability = self::get_availability_summary( $accommodation_id );
		if ( ! empty( $availability['reason_code'] ) && in_array( $availability['reason_code'], array( 'active_lease', 'reserved_hold' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'El inmueble no puede reservarse en este momento.', 'arriendo-facil' ) ), 409 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_reservations';

		$inserted = $wpdb->insert(
			$table,
			array(
				'accommodation_id'  => $accommodation_id,
				'guest_id'          => $guest_id,
				'deposit_amount'    => $deposit_amount,
				'hold_until'        => gmdate( 'Y-m-d H:i:s', strtotime( $hold_until ) ),
				'payment_reference' => $payment_reference,
				'payment_status'    => $deposit_amount > 0 ? 'received' : 'pending',
				'status'            => 'reserved',
				'reservation_status'=> 'reserved',
				'notes'             => $notes,
				'created_by'        => get_current_user_id(),
			),
			array( '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo crear la reserva temporal.', 'arriendo-facil' ) ) );
		}

		self::set_commercial_state( $accommodation_id, 'reserved', 'public' );
		$this->cancel_and_notify_interest_queue_unavailable( $accommodation_id );
		self::log_lease_event( 0, $accommodation_id, 'reservation_created', array( 'reservation_id' => (int) $wpdb->insert_id, 'hold_until' => $hold_until ) );

		wp_send_json_success(
			array(
				'id'      => (int) $wpdb->insert_id,
				'message' => __( 'Reserva temporal creada.', 'arriendo-facil' ),
			)
		);
	}

	/**
	 * AJAX: release reservation hold manually.
	 */
	public function ajax_release_reservation_hold() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$reason         = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		if ( ! $reservation_id || '' === $reason ) {
			wp_send_json_error( array( 'message' => __( 'El ID de reserva y el motivo son obligatorios.', 'arriendo-facil' ) ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_reservations';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $reservation_id ) );

		if ( ! $row || ! $this->can_manage_accommodation( (int) $row->accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Reserva no encontrada.', 'arriendo-facil' ) ), 404 );
		}

		$updated = $wpdb->update(
			$table,
			array(
				'status'             => 'released',
				'reservation_status' => 'released',
				'release_reason'     => $reason,
				'released_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $reservation_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo liberar la reserva.', 'arriendo-facil' ) ) );
		}

		self::set_commercial_state( (int) $row->accommodation_id, 'available', 'public' );
		$this->notify_interest_queue_release( (int) $row->accommodation_id );
		self::log_lease_event( 0, (int) $row->accommodation_id, 'reservation_released', array( 'reservation_id' => $reservation_id, 'reason' => $reason ) );

		wp_send_json_success( array( 'message' => __( 'Reserva liberada e inmueble reabierto.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: finalize lease with manual release plan.
	 */
	public function ajax_finalize_lease_manual_release() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id      = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		$release_date  = isset( $_POST['release_date'] ) ? sanitize_text_field( wp_unslash( $_POST['release_date'] ) ) : '';
		$reason        = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( ! $lease_id || ! $this->is_valid_date( $release_date ) || '' === trim( $reason ) ) {
			wp_send_json_error( array( 'message' => __( 'El contrato, la fecha de liberacion y el motivo son obligatorios.', 'arriendo-facil' ) ), 400 );
		}

		$lease_service = class_exists( 'Arriendo_Facil_Lease' ) ? new Arriendo_Facil_Lease() : null;
		$lease         = $lease_service ? $lease_service->get_lease( $lease_id ) : null;
		if ( ! $lease || ! $this->can_manage_accommodation( (int) $lease->accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Contrato no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'af_leases',
			array(
				'status' => 'pending_release',
			),
			array( 'id' => $lease_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo finalizar el contrato.', 'arriendo-facil' ) ) );
		}

		update_post_meta( (int) $lease->accommodation_id, '_af_release_date', $release_date );
		update_post_meta( (int) $lease->accommodation_id, '_af_release_reason', $reason );
		self::set_commercial_state( (int) $lease->accommodation_id, 'rented', 'private' );
		self::log_lease_event( $lease_id, (int) $lease->accommodation_id, 'lease_finalized_pending_release', array( 'release_date' => $release_date, 'reason' => $reason ) );

		wp_send_json_success( array( 'message' => __( 'Contrato finalizado. Esperando fecha de liberacion manual.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: renew lease end date.
	 */
	public function ajax_renew_lease() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id      = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		$new_end_date  = isset( $_POST['new_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['new_end_date'] ) ) : '';
		$reason        = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( ! $lease_id || ! $this->is_valid_date( $new_end_date ) || '' === trim( $reason ) ) {
			wp_send_json_error( array( 'message' => __( 'El contrato, la nueva fecha de fin y el motivo son obligatorios.', 'arriendo-facil' ) ), 400 );
		}

		$lease_service = class_exists( 'Arriendo_Facil_Lease' ) ? new Arriendo_Facil_Lease() : null;
		$lease         = $lease_service ? $lease_service->get_lease( $lease_id ) : null;
		if ( ! $lease || ! $this->can_manage_accommodation( (int) $lease->accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Contrato no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		if ( $new_end_date <= (string) $lease->end_date ) {
			wp_send_json_error( array( 'message' => __( 'La nueva fecha de fin debe ser posterior a la fecha de fin actual.', 'arriendo-facil' ) ), 400 );
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'af_leases',
			array(
				'end_date' => $new_end_date,
				'status'   => 'active',
			),
			array( 'id' => $lease_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo renovar el contrato.', 'arriendo-facil' ) ) );
		}

		delete_post_meta( (int) $lease->accommodation_id, '_af_release_date' );
		delete_post_meta( (int) $lease->accommodation_id, '_af_release_reason' );
		self::set_commercial_state( (int) $lease->accommodation_id, 'rented', 'private' );
		self::log_lease_event( $lease_id, (int) $lease->accommodation_id, 'lease_renewed', array( 'new_end_date' => $new_end_date, 'reason' => $reason ) );

		wp_send_json_success( array( 'message' => __( 'Contrato renovado correctamente.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: execute manual accommodation release.
	 */
	public function ajax_execute_manual_release() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;
		$reason           = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( ! $this->can_manage_accommodation( $accommodation_id ) || '' === trim( $reason ) ) {
			wp_send_json_error( array( 'message' => __( 'El inmueble y el motivo de liberacion son obligatorios.', 'arriendo-facil' ) ), 400 );
		}

		$this->release_accommodation_manually( $accommodation_id, $reason );
		wp_send_json_success( array( 'message' => __( 'Inmueble liberado y publicado como disponible.', 'arriendo-facil' ) ) );
	}

	/**
	 * Manual release helper.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $reason Release reason.
	 * @return void
	 */
	private function release_accommodation_manually( $accommodation_id, $reason ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id ) {
			return;
		}

		self::set_commercial_state( $accommodation_id, 'available', 'public' );
		update_post_meta( $accommodation_id, '_af_status', 'available' );
		delete_post_meta( $accommodation_id, '_af_release_date' );
		delete_post_meta( $accommodation_id, '_af_release_reason' );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'af_leases',
			array( 'status' => 'terminated' ),
			array(
				'accommodation_id' => $accommodation_id,
				'status'           => 'pending_release',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		self::log_lease_event( 0, $accommodation_id, 'accommodation_released_manually', array( 'reason' => $reason ) );
		$this->notify_interest_queue_release( $accommodation_id );
	}

	/**
	 * Updates commercial state meta.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $state State.
	 * @param string $visibility Visibility.
	 * @return void
	 */
	public static function set_commercial_state( $accommodation_id, $state, $visibility ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id ) {
			return;
		}

		$allowed_states      = array( 'available', 'reserved', 'rented' );
		$allowed_visibility  = array( 'public', 'private' );
		$state               = in_array( $state, $allowed_states, true ) ? $state : 'available';
		$visibility          = in_array( $visibility, $allowed_visibility, true ) ? $visibility : 'public';

		update_post_meta( $accommodation_id, '_af_commercial_state', $state );
		update_post_meta( $accommodation_id, '_af_commercial_visibility', $visibility );
		update_post_meta( $accommodation_id, '_af_commercial_status', 'private' === $visibility ? 'private' : $state );

		if ( 'rented' === $state ) {
			update_post_meta( $accommodation_id, '_af_status', 'rented' );
		}
	}

	/**
	 * Logs lease/accommodation events.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $event_type Event type.
	 * @param array  $payload Payload.
	 * @return void
	 */
	public static function log_lease_event( $lease_id, $accommodation_id, $event_type, array $payload = array() ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'af_lease_events',
			array(
				'lease_id'          => absint( $lease_id ),
				'accommodation_id'  => absint( $accommodation_id ),
				'event_type'        => sanitize_key( $event_type ),
				'event_payload'     => wp_json_encode( $payload ),
				'created_by'        => get_current_user_id(),
			),
			array( '%d', '%d', '%s', '%s', '%d' )
		);
	}

	/**
	 * Sends queue-release notifications and marks rows as notified.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return void
	 */
	private function notify_interest_queue_release( $accommodation_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'af_interest_queue';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, email FROM {$table}
				 WHERE accommodation_id = %d
				   AND status IN ('queued','notified','visit_requested')
				 ORDER BY id ASC
				 LIMIT 100",
				$accommodation_id
			)
		);

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return;
		}

		$title   = get_the_title( $accommodation_id );
		$subject = sprintf( __( '[Arriendo Facil] Buenas noticias: %s vuelve a estar disponible', 'arriendo-facil' ), $title );

		foreach ( $rows as $row ) {
			$email = isset( $row->email ) ? sanitize_email( (string) $row->email ) : '';
			if ( ! is_email( $email ) ) {
				continue;
			}

			$name    = isset( $row->name ) ? sanitize_text_field( (string) $row->name ) : __( 'Interesado', 'arriendo-facil' );
			$message = sprintf(
				/* translators: 1: interested name, 2: accommodation title */
				__( "Hola %1\$s,\n\nTe contamos que la acomodacion '%2\$s' vuelve a estar disponible.\n\nSi aun te interesa, puedes continuar tu proceso y coordinar tu visita desde la plataforma.\n\nGracias por confiar en Arriendo Facil.", 'arriendo-facil' ),
				$name,
				$title
			);

			$sent = wp_mail( $email, $subject, $message );
			$this->log_notification( $accommodation_id, 'accommodation_released', $email, $sent ? 'sent' : 'failed' );

			$wpdb->update(
				$table,
				array( 'status' => 'notified' ),
				array( 'id' => (int) $row->id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Sends booking confirmation email.
	 *
	 * @param string $email Guest email.
	 * @param string $name Guest name.
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $visit_date Visit date.
	 * @param string $start_time Start time.
	 * @param string $end_time End time.
	 * @return void
	 */
	private function send_booking_confirmation_email( $email, $name, $accommodation_id, $visit_date, $start_time, $end_time ) {
		if ( ! is_email( $email ) ) {
			return;
		}

		$title   = get_the_title( $accommodation_id );
		$subject = sprintf( __( '[Arriendo Facil] Tu visita esta confirmada: %s', 'arriendo-facil' ), $title );
		$message = sprintf(
			/* translators: 1: guest name, 2: title, 3: date, 4: start time, 5: end time */
			__( "Hola %1\$s,\n\nTu visita para la acomodacion '%2\$s' ha sido confirmada.\n\nFecha: %3\$s\nHora: %4\$s - %5\$s\n\nSi necesitas reprogramar, responde a este correo o contacta al propietario.\n\nTe esperamos.", 'arriendo-facil' ),
			sanitize_text_field( $name ),
			sanitize_text_field( $title ),
			sanitize_text_field( $visit_date ),
			sanitize_text_field( $start_time ),
			sanitize_text_field( $end_time )
		);

		$sent = wp_mail( $email, $subject, $message );
		$this->log_notification( $accommodation_id, 'visit_confirmation', $email, $sent ? 'sent' : 'failed' );
	}

	/**
	 * Sends the secure legal-profile form link after visit booking confirmation.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param int    $visit_booking_id Visit booking ID.
	 * @param string $guest_name Guest full name.
	 * @param string $guest_email Guest email.
	 * @param string $guest_phone Guest phone.
	 * @return void
	 */
	private function dispatch_guest_profile_link_email_after_booking( $accommodation_id, $visit_booking_id, $guest_name, $guest_email, $guest_phone ) {
		$accommodation_id = absint( $accommodation_id );
		$visit_booking_id = absint( $visit_booking_id );
		$guest_name       = sanitize_text_field( (string) $guest_name );
		$guest_email      = sanitize_email( (string) $guest_email );
		$guest_phone      = sanitize_text_field( (string) $guest_phone );

		if ( ! $accommodation_id || ! $visit_booking_id || ! is_email( $guest_email ) || ! class_exists( 'Arriendo_Facil_Guest' ) ) {
			return;
		}

		$guest_service = new Arriendo_Facil_Guest();
		if ( ! method_exists( $guest_service, 'send_guest_profile_link_for_booking' ) ) {
			return;
		}

		$result = $guest_service->send_guest_profile_link_for_booking(
			$accommodation_id,
			$visit_booking_id,
			$guest_name,
			$guest_email,
			$guest_phone,
			'/completar-perfil-arriendo/',
			72
		);

		if ( is_array( $result ) ) {
			$status = ! empty( $result['sent'] ) ? 'sent' : 'failed';
			$this->log_notification( $accommodation_id, 'guest_profile_link', $guest_email, $status );

			if ( 'failed' === $status && ! empty( $result['error'] ) ) {
				error_log( 'Arriendo Facil onboarding email failed: ' . sanitize_text_field( (string) $result['error'] ) );
			}
		}
	}

	/**
	 * Notifies owner with interest summary via a formatted HTML email.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $context          Context message shown in the email header.
	 * @param string $lead_name        Name of the interested lead.
	 * @param string $lead_email       Email of the interested lead.
	 * @param string $lead_phone       Phone of the interested lead.
	 * @param string $preferred_date   Preferred visit date (optional).
	 * @param string $preferred_time   Preferred visit time (optional).
	 * @param string $lead_notes       Additional notes from the lead (optional).
	 * @return void
	 */
	private function notify_owner_about_interest( $accommodation_id, $context, $lead_name = '', $lead_email = '', $lead_phone = '', $preferred_date = '', $preferred_time = '', $lead_notes = '' ) {
		$accommodation_id = absint( $accommodation_id );
		$owner_id         = (int) get_post_meta( $accommodation_id, '_af_owner_id', true );
		$owner            = $owner_id ? get_user_by( 'ID', $owner_id ) : null;
		if ( ! $owner || empty( $owner->user_email ) ) {
			return;
		}

		global $wpdb;
		$queue_table    = $wpdb->prefix . 'af_interest_queue';
		$bookings_table = $wpdb->prefix . 'af_visit_bookings';

		$acc_title    = (string) get_the_title( $accommodation_id );
		$queued_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE accommodation_id = %d AND status IN ('queued','notified','visit_requested')", $accommodation_id )
		);
		$visit_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$bookings_table} WHERE accommodation_id = %d AND status IN ('confirmed','completed')", $accommodation_id )
		);

		// Collect stats for owner's OTHER accommodations.
		$other_acc_rows = array();
		if ( class_exists( 'Arriendo_Facil_Accommodation' ) && $owner_id ) {
			$all_owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( $owner_id );
			foreach ( $all_owner_ids as $other_id ) {
				$other_id = absint( $other_id );
				if ( $other_id === $accommodation_id ) {
					continue;
				}
				$other_queued = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE accommodation_id = %d AND status IN ('queued','notified','visit_requested')", $other_id )
				);
				$other_visits = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$bookings_table} WHERE accommodation_id = %d AND status IN ('confirmed','completed')", $other_id )
				);
				$other_acc_rows[] = array(
					'title'   => (string) get_the_title( $other_id ),
					'queued'  => $other_queued,
					'visited' => $other_visits,
				);
			}
		}

		$owner_email = sanitize_email( (string) $owner->user_email );
		$subject     = sprintf( __( '[Arriendo Facil] Nueva solicitud recibida para %s', 'arriendo-facil' ), $acc_title );

		// --- HTML email ---
		$html  = '<div style="margin:0;padding:24px;background:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">';
		$html .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">';

		// Header.
		$html .= '<div style="padding:18px 22px;background:linear-gradient(135deg,#0f766e,#0ea5a4);color:#ffffff;">';
		$html .= '<h2 style="margin:0;font-size:20px;line-height:1.3;">' . esc_html__( 'Nueva solicitud recibida en Arriendo Facil', 'arriendo-facil' ) . '</h2>';
		$html .= '</div>';

		// Body.
		$html .= '<div style="padding:22px;">';
		$html .= '<p style="margin:0 0 16px;line-height:1.6;">' . esc_html( sanitize_text_field( $context ) ) . '</p>';

		// Lead details table (only when we have a name/email).
		if ( '' !== trim( $lead_name ) || '' !== trim( $lead_email ) ) {
			$html .= '<h3 style="margin:0 0 10px;font-size:15px;color:#0f172a;">' . esc_html__( 'Datos del interesado', 'arriendo-facil' ) . '</h3>';
			$html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:0 0 20px;">';
			if ( '' !== trim( $lead_name ) ) {
				$html .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;"><strong>' . esc_html__( 'Nombre', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_text_field( $lead_name ) ) . '</td></tr>';
			}
			if ( '' !== trim( $lead_email ) ) {
				$html .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;"><strong>' . esc_html__( 'Correo', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_email( $lead_email ) ) . '</td></tr>';
			}
			if ( '' !== trim( $lead_phone ) ) {
				$html .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;"><strong>' . esc_html__( 'Telefono', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_text_field( $lead_phone ) ) . '</td></tr>';
			}
			if ( '' !== trim( $preferred_date ) ) {
				$html .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;"><strong>' . esc_html__( 'Fecha sugerida', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_text_field( $preferred_date ) ) . '</td></tr>';
			}
			if ( '' !== trim( $preferred_time ) ) {
				$html .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;"><strong>' . esc_html__( 'Hora sugerida', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_text_field( $preferred_time ) ) . '</td></tr>';
			}
			$html .= '</table>';

			if ( '' !== trim( $lead_notes ) ) {
				$html .= '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'Mensaje adicional:', 'arriendo-facil' ) . '</strong></p>';
				$html .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;line-height:1.6;color:#334155;margin-bottom:20px;">' . nl2br( esc_html( sanitize_textarea_field( $lead_notes ) ) ) . '</div>';
			}
		}

		// Current accommodation summary box.
		$html .= '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px;margin-bottom:20px;">';
		$html .= '<h3 style="margin:0 0 10px;font-size:15px;color:#166534;">' . esc_html( $acc_title ) . '</h3>';
		$html .= '<p style="margin:4px 0;color:#14532d;">';
		$html .= esc_html__( 'Interesados en cola:', 'arriendo-facil' ) . ' <strong>' . absint( $queued_count ) . '</strong>';
		$html .= ' &nbsp;|&nbsp; ';
		$html .= esc_html__( 'Visitas agendadas:', 'arriendo-facil' ) . ' <strong>' . absint( $visit_count ) . '</strong>';
		$html .= '</p>';
		$html .= '</div>';

		// Other accommodations summary.
		if ( ! empty( $other_acc_rows ) ) {
			$html .= '<h3 style="margin:0 0 10px;font-size:14px;color:#475569;">' . esc_html__( 'Resumen de tus otras acomodaciones', 'arriendo-facil' ) . '</h3>';
			$html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:0 0 16px;font-size:13px;">';
			$html .= '<tr style="background:#f1f5f9;">';
			$html .= '<th style="padding:8px 10px;text-align:left;border:1px solid #e2e8f0;">' . esc_html__( 'Acomodacion', 'arriendo-facil' ) . '</th>';
			$html .= '<th style="padding:8px 10px;text-align:center;border:1px solid #e2e8f0;">' . esc_html__( 'En cola', 'arriendo-facil' ) . '</th>';
			$html .= '<th style="padding:8px 10px;text-align:center;border:1px solid #e2e8f0;">' . esc_html__( 'Visitas', 'arriendo-facil' ) . '</th>';
			$html .= '</tr>';
			foreach ( $other_acc_rows as $row ) {
				$html .= '<tr>';
				$html .= '<td style="padding:7px 10px;border:1px solid #e2e8f0;">' . esc_html( $row['title'] ) . '</td>';
				$html .= '<td style="padding:7px 10px;text-align:center;border:1px solid #e2e8f0;">' . absint( $row['queued'] ) . '</td>';
				$html .= '<td style="padding:7px 10px;text-align:center;border:1px solid #e2e8f0;">' . absint( $row['visited'] ) . '</td>';
				$html .= '</tr>';
			}
			$html .= '</table>';
		}

		$html .= '<p style="margin:16px 0 0;line-height:1.6;color:#334155;">' . esc_html__( 'Revisa los detalles en tu panel para continuar con tranquilidad y sin cruces.', 'arriendo-facil' ) . '</p>';
		$html .= '</div>';
		$html .= '</div>';
		$html .= '<p style="max-width:640px;margin:12px auto 0;font-size:12px;color:#64748b;text-align:center;">Arriendo Facil</p>';
		$html .= '</div>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $owner_email, $subject, $html, $headers );
		$this->log_notification( $accommodation_id, 'owner_interest_update', $owner_email, $sent ? 'sent' : 'failed' );
	}

	/**
	 * Notifies and removes pending requests when accommodation is reserved.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return void
	 */
	private function cancel_and_notify_interest_queue_unavailable( $accommodation_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'af_interest_queue';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, email
				 FROM {$table}
				 WHERE accommodation_id = %d
				   AND status IN ('queued','notified','visit_requested')
				 ORDER BY id ASC
				 LIMIT 200",
				$accommodation_id
			)
		);

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return;
		}

		$title   = get_the_title( $accommodation_id );
		$subject = sprintf( __( '[Arriendo Facil] Actualizacion: %s ya no esta disponible', 'arriendo-facil' ), $title );

		foreach ( $rows as $row ) {
			$email = isset( $row->email ) ? sanitize_email( (string) $row->email ) : '';
			if ( ! is_email( $email ) ) {
				continue;
			}

			$name = isset( $row->name ) ? sanitize_text_field( (string) $row->name ) : __( 'Interesado', 'arriendo-facil' );
			$message = sprintf(
				/* translators: 1: interested name, 2: accommodation title */
				__( "Hola %1\$s,\n\nQueremos avisarte que la acomodacion '%2\$s' acaba de ser reservada y ya no se encuentra disponible.\n\nSi deseas, puedes seguir buscando otras opciones dentro de Arriendo Facil.\n\nGracias por tu interes.", 'arriendo-facil' ),
				$name,
				sanitize_text_field( (string) $title )
			);

			$sent = wp_mail( $email, $subject, $message );
			$this->log_notification( $accommodation_id, 'accommodation_reserved_unavailable', $email, $sent ? 'sent' : 'failed' );
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE accommodation_id = %d
				   AND status IN ('queued','notified','visit_requested')",
				$accommodation_id
			)
		);
	}

	/**
	 * Logs notification delivery results.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $type Notification type.
	 * @param string $recipient Recipient email.
	 * @param string $status Delivery status.
	 * @return void
	 */
	private function log_notification( $accommodation_id, $type, $recipient, $status ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'af_notifications_log',
			array(
				'accommodation_id' => absint( $accommodation_id ),
				'notification_type'=> sanitize_key( $type ),
				'recipient'        => sanitize_email( $recipient ),
				'delivery_status'  => sanitize_key( $status ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Checks if current user can manage accommodation.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return bool
	 */
	private function can_manage_accommodation( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id || ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( class_exists( 'Arriendo_Facil_Accommodation' ) && Arriendo_Facil_Accommodation::user_is_owner() ) {
			$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
			return in_array( $accommodation_id, $owner_ids, true );
		}

		return true;
	}

	/**
	 * Checks if accommodation has active lease.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return bool
	 */
	private static function has_active_lease_for_accommodation( $accommodation_id ) {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}af_leases
				 WHERE accommodation_id = %d
				   AND status IN ('active','pending_release')",
				absint( $accommodation_id )
			)
		);

		return $count > 0;
	}

	/**
	 * Returns active reservation row when exists.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return object|null
	 */
	private static function get_active_reservation_for_accommodation( $accommodation_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				 FROM {$wpdb->prefix}af_reservations
				 WHERE accommodation_id = %d
				   AND (reservation_status IN ('reserved','pending_payment') OR status IN ('reserved','pending_payment'))
				 ORDER BY id DESC
				 LIMIT 1",
				absint( $accommodation_id )
			)
		);
	}

	/**
	 * Returns whether a guest already has a visit booking for accommodation.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $guest_email      Guest email.
	 * @return bool
	 */
	public static function has_required_visit_booking( $accommodation_id, $guest_email ) {
		$accommodation_id = absint( $accommodation_id );
		$guest_email      = sanitize_email( (string) $guest_email );

		if ( ! $accommodation_id || ! is_email( $guest_email ) ) {
			return false;
		}

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}af_visit_bookings
				 WHERE accommodation_id = %d
				   AND guest_email = %s
				   AND status IN ('confirmed','completed')",
				$accommodation_id,
				$guest_email
			)
		);

		return $count > 0;
	}

	/**
	 * Checks valid YYYY-MM-DD.
	 *
	 * @param string $value Date text.
	 * @return bool
	 */
	private function is_valid_date( $value ) {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value );
	}

	/**
	 * Checks valid HH:MM.
	 *
	 * @param string $value Time text.
	 * @return bool
	 */
	private function is_valid_time( $value ) {
		return 1 === preg_match( '/^\d{2}:\d{2}$/', (string) $value );
	}

	/**
	 * Checks valid datetime string.
	 *
	 * @param string $value Datetime.
	 * @return bool
	 */
	private function is_valid_datetime( $value ) {
		if ( '' === trim( (string) $value ) ) {
			return false;
		}

		$timestamp = strtotime( (string) $value );
		return false !== $timestamp;
	}

	/**
	 * Validates optional frontend nonce when present.
	 *
	 * @return void
	 */
	private function verify_frontend_nonce_optional() {
		if ( ! isset( $_REQUEST['nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'af_guest_frontend_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Token de seguridad invalido.', 'arriendo-facil' ) ), 403 );
		}
	}

	/**
	 * Validates required frontend nonce.
	 *
	 * @return void
	 */
	private function verify_frontend_nonce_required() {
		if ( false === check_ajax_referer( 'af_guest_frontend_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'La sesion expiro. Recarga la pagina e intenta nuevamente.', 'arriendo-facil' ) ), 403 );
		}
	}
}
