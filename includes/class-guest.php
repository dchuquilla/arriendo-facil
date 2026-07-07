<?php
/**
 * Guest management (AI-assisted).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Guest
 *
 * Manages guest records and uses the AI service to score guests
 * and assist with guest management.
 */
class Arriendo_Facil_Guest {

	/**
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_create_guest', array( $this, 'ajax_create_guest' ) );
		add_action( 'wp_ajax_af_process_guest_post_submit_now', array( $this, 'ajax_process_guest_post_submit_now' ) );
		add_action( 'wp_ajax_nopriv_af_process_guest_post_submit_now', array( $this, 'ajax_process_guest_post_submit_now' ) );
		add_action( 'wp_ajax_af_send_guest_profile_link', array( $this, 'ajax_send_guest_profile_link' ) );
		add_action( 'wp_ajax_af_validate_guest_profile_token', array( $this, 'ajax_validate_guest_profile_token' ) );
		add_action( 'wp_ajax_nopriv_af_validate_guest_profile_token', array( $this, 'ajax_validate_guest_profile_token' ) );
		add_action( 'wp_ajax_af_submit_guest_profile_by_token', array( $this, 'ajax_submit_guest_profile_by_token' ) );
		add_action( 'wp_ajax_nopriv_af_submit_guest_profile_by_token', array( $this, 'ajax_submit_guest_profile_by_token' ) );
		add_action( 'wp_ajax_af_refresh_nonce', array( $this, 'ajax_refresh_nonce' ) );
		add_action( 'wp_ajax_nopriv_af_refresh_nonce', array( $this, 'ajax_refresh_nonce' ) );
		add_action( 'wp_ajax_af_get_guests', array( $this, 'ajax_get_guests' ) );
		add_action( 'wp_ajax_af_score_guest', array( $this, 'ajax_score_guest' ) );
		add_action( 'af_process_guest_post_submit', array( $this, 'process_guest_post_submit_async' ), 10, 1 );
	}

	public function ajax_refresh_nonce() {
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'af_guest_frontend_nonce' ) ) );
	}

	/**
	 * Sends unique legal-profile onboarding link to a guest email.
	 *
	 * Expected for owner/admin after visit confirmation.
	 */
	public function ajax_send_guest_profile_link() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$guest_id         = isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0;
		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;
		$visit_booking_id = isset( $_POST['visit_booking_id'] ) ? absint( wp_unslash( $_POST['visit_booking_id'] ) ) : 0;
		$expires_hours    = isset( $_POST['expires_hours'] ) ? absint( wp_unslash( $_POST['expires_hours'] ) ) : 72;
		$form_path        = isset( $_POST['form_path'] ) ? sanitize_text_field( wp_unslash( $_POST['form_path'] ) ) : '/completar-perfil-arriendo/';

		if ( ! $guest_id ) {
			wp_send_json_error( array( 'message' => __( 'ID de huesped invalido.', 'arriendo-facil' ) ), 400 );
		}

		global $wpdb;
		$guest = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d LIMIT 1",
				$guest_id
			)
		);

		if ( ! $guest || ! isset( $guest->email ) || ! is_email( (string) $guest->email ) ) {
			wp_send_json_error( array( 'message' => __( 'No se encontro un huesped valido para enviar el enlace.', 'arriendo-facil' ) ), 404 );
		}

		if ( ! $accommodation_id && isset( $guest->accommodation_id ) ) {
			$accommodation_id = absint( $guest->accommodation_id );
		}

		$expires_hours = max( 6, min( 168, $expires_hours ) );
		$token_data    = $this->create_guest_onboarding_token(
			$guest_id,
			$accommodation_id,
			$visit_booking_id,
			(string) $guest->email,
			$expires_hours
		);

		if ( is_wp_error( $token_data ) ) {
			wp_send_json_error( array( 'message' => $token_data->get_error_message() ), 500 );
		}

		$selector = isset( $token_data['selector'] ) ? (string) $token_data['selector'] : '';
		$token    = isset( $token_data['token'] ) ? (string) $token_data['token'] : '';

		if ( '' === $selector || '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo generar el token de onboarding.', 'arriendo-facil' ) ), 500 );
		}

		$form_url = home_url( '/' . ltrim( $form_path, '/' ) );
		$form_url = add_query_arg(
			array(
				'selector' => rawurlencode( $selector ),
				'token'    => rawurlencode( $token ),
			),
			$form_url
		);

		$sent = $this->send_guest_legal_profile_link_email(
			sanitize_email( (string) $guest->email ),
			sanitize_text_field( trim( (string) $guest->first_name . ' ' . (string) $guest->last_name ) ),
			$form_url,
			$accommodation_id,
			isset( $token_data['expires_at'] ) ? (string) $token_data['expires_at'] : ''
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo enviar el correo con el enlace de perfil legal.', 'arriendo-facil' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Enlace de perfil legal enviado al correo del arrendatario.', 'arriendo-facil' ),
				'guest_id'   => $guest_id,
				'expires_at' => isset( $token_data['expires_at'] ) ? (string) $token_data['expires_at'] : '',
			)
		);
	}

	/**
	 * Sends onboarding link for a confirmed visit booking without requiring AJAX.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param int    $visit_booking_id Visit booking ID.
	 * @param string $guest_name Guest full name.
	 * @param string $guest_email Guest email.
	 * @param string $guest_phone Guest phone.
	 * @param string $form_path Relative form path.
	 * @param int    $expires_hours Link lifetime in hours.
	 * @return array<string,mixed>
	 */
	public function send_guest_profile_link_for_booking( $accommodation_id, $visit_booking_id, $guest_name, $guest_email, $guest_phone = '', $form_path = '/completar-perfil-arriendo/', $expires_hours = 72 ) {
		$accommodation_id = absint( $accommodation_id );
		$visit_booking_id = absint( $visit_booking_id );
		$guest_name       = sanitize_text_field( (string) $guest_name );
		$guest_email      = sanitize_email( (string) $guest_email );
		$guest_phone      = sanitize_text_field( (string) $guest_phone );
		$form_path        = sanitize_text_field( (string) $form_path );
		$expires_hours    = max( 6, min( 168, absint( $expires_hours ) ) );

		if ( ! $accommodation_id || ! is_email( $guest_email ) ) {
			return array(
				'sent'  => false,
				'error' => 'invalid_booking_input',
			);
		}

		$schema_result = $this->ensure_guest_extra_columns();
		if ( is_wp_error( $schema_result ) ) {
			return array(
				'sent'  => false,
				'error' => $schema_result->get_error_message(),
			);
		}

		global $wpdb;
		$guest = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, first_name, last_name, email FROM {$wpdb->prefix}af_guests WHERE email = %s ORDER BY id DESC LIMIT 1",
				$guest_email
			)
		);

		$guest_id = $guest && isset( $guest->id ) ? absint( $guest->id ) : 0;
		if ( ! $guest_id ) {
			$name_parts = preg_split( '/\s+/', trim( $guest_name ) );
			if ( ! is_array( $name_parts ) ) {
				$name_parts = array();
			}

			$first_name = ! empty( $name_parts[0] ) ? sanitize_text_field( (string) $name_parts[0] ) : __( 'Arrendatario', 'arriendo-facil' );
			$last_name  = count( $name_parts ) > 1 ? sanitize_text_field( trim( implode( ' ', array_slice( $name_parts, 1 ) ) ) ) : '';

			$inserted = $wpdb->insert(
				$wpdb->prefix . 'af_guests',
				array(
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'email'      => $guest_email,
					'phone'      => $guest_phone,
					'id_number'  => '',
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);

			if ( ! $inserted ) {
				return array(
					'sent'  => false,
					'error' => 'guest_insert_failed',
				);
			}

			$guest_id = absint( $wpdb->insert_id );
		} else {
			$wpdb->update(
				$wpdb->prefix . 'af_guests',
				array( 'phone' => $guest_phone ),
				array( 'id' => $guest_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		if ( ! $guest_id ) {
			return array(
				'sent'  => false,
				'error' => 'guest_id_missing',
			);
		}

		$token_data = $this->create_guest_onboarding_token( $guest_id, $accommodation_id, $visit_booking_id, $guest_email, $expires_hours );
		if ( is_wp_error( $token_data ) ) {
			return array(
				'sent'  => false,
				'error' => $token_data->get_error_message(),
			);
		}

		$selector = isset( $token_data['selector'] ) ? (string) $token_data['selector'] : '';
		$token    = isset( $token_data['token'] ) ? (string) $token_data['token'] : '';
		if ( '' === $selector || '' === $token ) {
			return array(
				'sent'  => false,
				'error' => 'token_build_failed',
			);
		}

		$form_url = home_url( '/' . ltrim( $form_path, '/' ) );
		$form_url = add_query_arg(
			array(
				'selector' => rawurlencode( $selector ),
				'token'    => rawurlencode( $token ),
			),
			$form_url
		);

		$sent = $this->send_guest_legal_profile_link_email(
			$guest_email,
			$guest_name,
			$form_url,
			$accommodation_id,
			isset( $token_data['expires_at'] ) ? (string) $token_data['expires_at'] : ''
		);

		if ( ! $sent ) {
			return array(
				'sent'       => false,
				'guest_id'   => $guest_id,
				'expires_at' => isset( $token_data['expires_at'] ) ? (string) $token_data['expires_at'] : '',
				'error'      => 'wp_mail_failed',
			);
		}

		return array(
			'sent'       => true,
			'guest_id'   => $guest_id,
			'expires_at' => isset( $token_data['expires_at'] ) ? (string) $token_data['expires_at'] : '',
		);
	}

	/**
	 * Validates onboarding token and returns minimal context for frontend form.
	 */
	public function ajax_validate_guest_profile_token() {
		$selector = isset( $_REQUEST['selector'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['selector'] ) ) : '';
		$token    = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';

		$result = $this->resolve_guest_onboarding_token( $selector, $token, false );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 );
		}

		global $wpdb;
		$guest_id = isset( $result['guest_id'] ) ? absint( $result['guest_id'] ) : 0;
		$guest    = $guest_id ? $wpdb->get_row( $wpdb->prepare( "SELECT id, first_name, last_name, email, phone, accommodation_id FROM {$wpdb->prefix}af_guests WHERE id = %d LIMIT 1", $guest_id ) ) : null;

		if ( ! $guest ) {
			wp_send_json_error( array( 'message' => __( 'No se encontro el huesped asociado al token.', 'arriendo-facil' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'guest_id'          => (int) $guest->id,
				'accommodation_id'  => isset( $result['accommodation_id'] ) ? absint( $result['accommodation_id'] ) : absint( $guest->accommodation_id ),
				'name'              => sanitize_text_field( trim( (string) $guest->first_name . ' ' . (string) $guest->last_name ) ),
				'email'             => sanitize_email( (string) $guest->email ),
				'phone'             => sanitize_text_field( (string) $guest->phone ),
				'expires_at'        => isset( $result['expires_at'] ) ? (string) $result['expires_at'] : '',
			)
		);
	}

	/**
	 * Submits legal profile payload using a unique onboarding token.
	 *
	 * Reuses existing guest/lease contract generation logic.
	 */
	public function ajax_submit_guest_profile_by_token() {
		try {

		$selector = isset( $_POST['selector'] ) ? sanitize_text_field( wp_unslash( $_POST['selector'] ) ) : '';
		$token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		$resolved = $this->resolve_guest_onboarding_token( $selector, $token, true );
		if ( is_wp_error( $resolved ) ) {
			wp_send_json_error( array( 'message' => $resolved->get_error_message(), 'code' => $resolved->get_error_code() ), 400 );
		}

		$guest_id         = isset( $resolved['guest_id'] ) ? absint( $resolved['guest_id'] ) : 0;
		$accommodation_id = isset( $resolved['accommodation_id'] ) ? absint( $resolved['accommodation_id'] ) : 0;

		if ( ! $guest_id || ! $accommodation_id ) {
			wp_send_json_error( array( 'message' => __( 'Token invalido para completar perfil.', 'arriendo-facil' ) ), 400 );
		}

		global $wpdb;
		$guest = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d LIMIT 1",
				$guest_id
			)
		);

		if ( ! $guest ) {
			wp_send_json_error( array( 'message' => __( 'No se encontro el huesped para completar el perfil.', 'arriendo-facil' ) ), 404 );
		}

		// Ensure extra columns exist before attempting the UPDATE.
		$schema_result = $this->ensure_guest_extra_columns();
		if ( is_wp_error( $schema_result ) ) {
			wp_send_json_error( array( 'message' => $schema_result->get_error_message() ), 500 );
		}

		$name_input        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone             = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$id_number         = isset( $_POST['id_number'] ) ? sanitize_text_field( wp_unslash( $_POST['id_number'] ) ) : '';
		$rental_start_date = isset( $_POST['rental_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rental_start_date'] ) ) : '';
		$rental_years      = isset( $_POST['rental_years'] ) ? max( 1, min( 20, absint( wp_unslash( $_POST['rental_years'] ) ) ) ) : 1;
		$personas_viviran  = isset( $_POST['personas_viviran'] ) ? max( 1, absint( wp_unslash( $_POST['personas_viviran'] ) ) ) : 1;

		if ( '' === $name_input ) {
			$name_input = trim( (string) $guest->first_name . ' ' . (string) $guest->last_name );
		}

		if ( '' === $phone ) {
			$phone = isset( $guest->phone ) ? sanitize_text_field( (string) $guest->phone ) : '';
		}

		if ( '' === $id_number ) {
			$id_number = isset( $guest->id_number ) ? sanitize_text_field( (string) $guest->id_number ) : '';
		}

		if ( ! is_email( (string) $guest->email ) || '' === $phone || '' === $id_number || '' === $rental_start_date ) {
			wp_send_json_error( array( 'message' => __( 'Faltan datos obligatorios para completar el perfil legal.', 'arriendo-facil' ) ), 400 );
		}

		if ( 1 !== preg_match( '/^[0-9]{10}$/', (string) $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Telefono invalido. Debe tener exactamente 10 digitos.', 'arriendo-facil' ) ), 400 );
		}

		if ( 1 !== preg_match( '/^[0-9]{10}$/', (string) $id_number ) ) {
			wp_send_json_error( array( 'message' => __( 'Cedula invalida. Debe tener exactamente 10 digitos.', 'arriendo-facil' ) ), 400 );
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $rental_start_date ) ) {
			wp_send_json_error( array( 'message' => __( 'La fecha de inicio debe tener formato YYYY-MM-DD.', 'arriendo-facil' ) ), 400 );
		}

		// Validación del nombre completo: solo letras, mínimo dos palabras, sin insultos.
		$name_trimmed = trim( (string) $name_input );
		if ( '' === $name_trimmed ) {
			wp_send_json_error( array( 'message' => __( 'El nombre completo es obligatorio.', 'arriendo-facil' ) ), 400 );
		}
		if ( mb_strlen( $name_trimmed ) < 5 || mb_strlen( $name_trimmed ) > 80 ) {
			wp_send_json_error( array( 'message' => __( 'El nombre debe tener entre 5 y 80 caracteres.', 'arriendo-facil' ) ), 400 );
		}
		if ( 1 !== preg_match( '/^[A-Za-zÀ-ÿÑñ]+(?:\s+[A-Za-zÀ-ÿÑñ]+)+$/u', $name_trimmed ) ) {
			wp_send_json_error( array( 'message' => __( 'Ingresa nombres y apellidos completos, solo letras (mínimo dos palabras).', 'arriendo-facil' ) ), 400 );
		}
		$blocked_words = array( 'mierda', 'puta', 'puto', 'carajo', 'maldito', 'maldita', 'estupido', 'estupida', 'idiota', 'pendejo', 'pendeja', 'marica', 'coño', 'cabron', 'cabrón', 'joder', 'gilipollas', 'fuck', 'shit', 'bitch', 'asshole', 'damn' );
		$name_lower = mb_strtolower( $name_trimmed );
		foreach ( $blocked_words as $word ) {
			if ( false !== mb_strpos( $name_lower, $word ) ) {
				wp_send_json_error( array( 'message' => __( 'El nombre contiene lenguaje no permitido.', 'arriendo-facil' ) ), 400 );
			}
		}
		$name_input = $name_trimmed;

		$rental_end_date = gmdate( 'Y-m-d', strtotime( '+' . $rental_years . ' years', strtotime( $rental_start_date ) ) );

		$name_parts = preg_split( '/\s+/', trim( $name_input ) );
		$first_name = ! empty( $name_parts[0] ) ? sanitize_text_field( (string) $name_parts[0] ) : '';
		$last_name  = count( $name_parts ) > 1 ? sanitize_text_field( trim( implode( ' ', array_slice( $name_parts, 1 ) ) ) ) : '';

		$updated = $wpdb->update(
			$wpdb->prefix . 'af_guests',
			array(
				'first_name'            => $first_name,
				'last_name'             => $last_name,
				'phone'                 => $phone,
				'id_number'             => $id_number,
				'accommodation_id'      => $accommodation_id,
				'rental_mode'           => 'years',
				'rental_start_date'     => $rental_start_date,
				'rental_end_date'       => $rental_end_date,
				'rental_months'         => null,
				'rental_years'          => $rental_years,
				'desired_price'         => '',
				'guarantee_text'        => 'Garantia equivalente a dos (2) meses del canon de arrendamiento',
				'mascotas'              => 0,
				'personas_viviran'      => $personas_viviran,
				'referencia_personal_1' => '',
				'referencia_personal_2' => '',
			),
			array( 'id' => $guest_id ),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo actualizar el perfil legal del arrendatario.', 'arriendo-facil' ) ), 500 );
		}

		$upload_result = $this->upload_guest_documents( $guest_id );
		if ( is_wp_error( $upload_result ) ) {
			wp_send_json_error( array( 'message' => $upload_result->get_error_message() ), 400 );
		}

		$lease_payload = array(
			'accommodation_id'  => $accommodation_id,
			'rental_mode'       => 'years',
			'rental_start_date' => $rental_start_date,
			'rental_end_date'   => $rental_end_date,
			'rental_months'     => 0,
			'rental_years'      => $rental_years,
			'phone'             => $phone,
			'id_number'         => $id_number,
			'mascotas'          => 0,
			'personas_viviran'  => $personas_viviran,
			'name'              => trim( $first_name . ' ' . $last_name ),
			'email'             => sanitize_email( (string) $guest->email ),
		);

		// Consume the token before responding (one-time use).
		$this->consume_guest_onboarding_token( isset( $resolved['token_id'] ) ? absint( $resolved['token_id'] ) : 0 );

		$contract_info = array(
			'generated' => false,
			'status'    => 'processed_with_errors',
		);

		try {
			$contract_info = $this->create_lease_contract_for_guest( $guest_id, $lease_payload );
			$contract_info['status'] = ! empty( $contract_info['generated'] ) ? 'generated' : 'processed_without_document';
		} catch ( Throwable $throwable ) {
			error_log( 'Arriendo Facil sync token submit lease generation error: ' . $throwable->getMessage() );
		}

		wp_send_json_success(
			array(
				'guest_id'           => $guest_id,
				'uploaded_documents' => $upload_result,
				'contract'           => $contract_info,
				'message'            => __( 'Perfil legal completado. Tu contrato fue procesado inmediatamente.', 'arriendo-facil' ),
			)
		);

		} catch ( Throwable $throwable ) {
			error_log( 'Arriendo Facil ajax_submit_guest_profile_by_token exception: ' . $throwable->getMessage() . ' | ' . $throwable->getFile() . ':' . $throwable->getLine() );
			wp_send_json_error(
				array(
					'message' => __( 'Error interno procesando tu perfil legal. Intenta nuevamente en unos minutos.', 'arriendo-facil' ),
				),
				500
			);
		}
	}

	/**
	 * Schedules heavy post-submit processing so AJAX can return quickly.
	 *
	 * @param int   $guest_id Guest ID.
	 * @param array $lease_payload Lease creation payload.
	 * @param array $visit_payload Visit request payload.
	 * @return bool
	 */
	private function schedule_guest_post_submit_processing( $guest_id, array $lease_payload, array $visit_payload ) {
		$guest_id = absint( $guest_id );
		if ( ! $guest_id ) {
			return false;
		}

		$job_id = function_exists( 'wp_generate_password' )
			? strtolower( wp_generate_password( 20, false, false ) )
			: strtolower( substr( md5( uniqid( '', true ) ), 0, 20 ) );

		$event_payload = array(
			'job_id'        => $job_id,
			'guest_id'      => $guest_id,
			'lease_payload' => $lease_payload,
			'visit_payload' => $visit_payload,
		);

		$job_key = 'af_guest_job_' . $job_id;
		set_transient( $job_key, $event_payload, 15 * MINUTE_IN_SECONDS );

		// Cron fallback: keep an async backup job without forcing loopback HTTP
		// from this request (that can hang in some servers/environments).
		$scheduled = wp_schedule_single_event( time() + 30, 'af_process_guest_post_submit', array( $event_payload ) );

		// Primary path: execute right after this HTTP response is flushed.
		$self = $this;
		add_action(
			'shutdown',
			function () use ( $event_payload, $self ) {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				} elseif ( function_exists( 'litespeed_finish_request' ) ) {
					litespeed_finish_request();
				}

				$self->process_guest_post_submit_async( $event_payload );
			},
			99
		);

		return true;
	}

	/**
	 * Triggers immediate async processing through admin-ajax.
	 *
	 * The job_id is signed with HMAC-SHA256 (wp_salt('auth')) so that the public
	 * `wp_ajax_nopriv_*` endpoint can verify authenticity without a WP session.
	 * Prevents replay/forgery even if the job_id leaks via logs or referer.
	 *
	 * @param string $job_id Job ID.
	 * @return bool
	 */
	private function trigger_guest_post_submit_async_http( $job_id ) {
		$job_id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $job_id ) );
		if ( '' === $job_id ) {
			return false;
		}

		$signature = hash_hmac( 'sha256', $job_id, wp_salt( 'auth' ) );

		$response = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'  => 1,
				'blocking' => false,
				'body'     => array(
					'action' => 'af_process_guest_post_submit_now',
					'job_id' => $job_id,
					'sig'    => $signature,
				),
			)
		);

		return ! is_wp_error( $response );
	}

	/**
	 * Enforces a per-IP rate limit for the public async trigger endpoint.
	 * Uses REMOTE_ADDR only (not X-Forwarded-For) because the caller is always
	 * a loopback request from this server; spoofable headers must not be trusted.
	 *
	 * @return bool True when within limit; false when exceeded.
	 */
	private function guest_async_endpoint_within_rate_limit() {
		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		if ( '' === $remote_ip || false === filter_var( $remote_ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$rate_key = 'af_guest_async_rl_' . md5( $remote_ip );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 10 ) {
			return false;
		}
		set_transient( $rate_key, $attempts + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * AJAX endpoint to run queued guest post-submit processing immediately.
	 *
	 * Defense-in-depth (OWASP A01 / A04):
	 * - HMAC-SHA256 signature verification (constant-time via hash_equals) so
	 *   this public endpoint cannot be triggered by a forged/leaked job_id.
	 * - Per-IP rate limit (10 req/min) against burst/spam.
	 * - Uniform 50ms floor before responding to reduce timing side-channel
	 *   between "invalid" and "valid" job_ids.
	 * - Generic error responses; details go to error_log only.
	 *
	 * @return void
	 */
	public function ajax_process_guest_post_submit_now() {
		$start_time = microtime( true );
		$remote_ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';

		$respond = function ( $body, $code ) use ( $start_time ) {
			$elapsed_us = (int) ( ( microtime( true ) - $start_time ) * 1000000 );
			$min_us     = 50000;
			if ( $elapsed_us < $min_us ) {
				usleep( $min_us - $elapsed_us );
			}
			wp_die( $body, '', array( 'response' => $code ) );
		};

		if ( ! $this->guest_async_endpoint_within_rate_limit() ) {
			error_log( sprintf( '[AF Security] guest_async rate-limit exceeded from %s', $remote_ip ) );
			$respond( '0', 429 );
		}

		$job_id_raw = isset( $_REQUEST['job_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['job_id'] ) ) : '';
		$job_id     = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $job_id_raw ) );
		$signature  = isset( $_REQUEST['sig'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sig'] ) ) : '';
		$signature  = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $signature ) );

		if ( '' === $job_id || strlen( $job_id ) < 16 || '' === $signature || 64 !== strlen( $signature ) ) {
			error_log( sprintf( '[AF Security] guest_async malformed request from %s', $remote_ip ) );
			$respond( '0', 400 );
		}

		$expected = hash_hmac( 'sha256', $job_id, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $signature ) ) {
			error_log( sprintf( '[AF Security] guest_async invalid HMAC from %s', $remote_ip ) );
			$respond( '0', 403 );
		}

		$job_key = 'af_guest_job_' . $job_id;
		$payload = get_transient( $job_key );
		if ( ! is_array( $payload ) ) {
			$respond( '0', 404 );
		}

		delete_transient( $job_key );
		$this->process_guest_post_submit_async( $payload );
		$respond( '1', 200 );
	}

	/**
	 * Executes heavy guest processing asynchronously via WP-Cron.
	 *
	 * @param array $event_payload Scheduled payload.
	 * @return void
	 */
	public function process_guest_post_submit_async( $event_payload ) {
		if ( ! is_array( $event_payload ) ) {
			return;
		}

		$job_id = isset( $event_payload['job_id'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $event_payload['job_id'] ) ) : '';
		if ( '' !== $job_id ) {
			$done_key = 'af_guest_job_done_' . $job_id;
			$lock_key = 'af_guest_job_lock_' . $job_id;

			if ( get_transient( $done_key ) || get_transient( $lock_key ) ) {
				return;
			}

			set_transient( $lock_key, 1, 10 * MINUTE_IN_SECONDS );
		}

		$guest_id      = isset( $event_payload['guest_id'] ) ? absint( $event_payload['guest_id'] ) : 0;
		$lease_payload = isset( $event_payload['lease_payload'] ) && is_array( $event_payload['lease_payload'] )
			? $event_payload['lease_payload']
			: array();
		$visit_payload = isset( $event_payload['visit_payload'] ) && is_array( $event_payload['visit_payload'] )
			? $event_payload['visit_payload']
			: array();

		if ( ! $guest_id ) {
			if ( '' !== $job_id ) {
				delete_transient( 'af_guest_job_lock_' . $job_id );
			}
			return;
		}

		try {
			$this->create_lease_contract_for_guest( $guest_id, $lease_payload );
		} catch ( Throwable $throwable ) {
			error_log( 'Arriendo Facil async lease generation error: ' . $throwable->getMessage() );
		}

		if ( ! empty( $visit_payload ) ) {
			try {
				$this->create_or_update_visit_request(
					isset( $visit_payload['accommodation_id'] ) ? absint( $visit_payload['accommodation_id'] ) : 0,
					isset( $visit_payload['name'] ) ? (string) $visit_payload['name'] : '',
					isset( $visit_payload['email'] ) ? (string) $visit_payload['email'] : '',
					isset( $visit_payload['phone'] ) ? (string) $visit_payload['phone'] : '',
					isset( $visit_payload['preferred_date'] ) ? (string) $visit_payload['preferred_date'] : '',
					isset( $visit_payload['preferred_time'] ) ? (string) $visit_payload['preferred_time'] : '',
					isset( $visit_payload['visit_notes'] ) ? (string) $visit_payload['visit_notes'] : ''
				);
			} catch ( Throwable $throwable ) {
				error_log( 'Arriendo Facil async visit request error: ' . $throwable->getMessage() );
			}
		}

		if ( '' !== $job_id ) {
			delete_transient( 'af_guest_job_lock_' . $job_id );
			set_transient( 'af_guest_job_done_' . $job_id, 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Creates or refreshes a visit request in queue and notifies owner.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $name Lead name.
	 * @param string $email Lead email.
	 * @param string $phone Lead phone.
	 * @param string $preferred_date Preferred date.
	 * @param string $preferred_time Preferred time.
	 * @param string $visit_notes Optional notes.
	 * @return array<string,mixed>
	 */
	private function create_or_update_visit_request( $accommodation_id, $name, $email, $phone, $preferred_date, $preferred_time, $visit_notes ) {
		global $wpdb;

		$accommodation_id = absint( $accommodation_id );
		$name             = sanitize_text_field( (string) $name );
		$email            = sanitize_email( (string) $email );
		$phone            = sanitize_text_field( (string) $phone );
		$preferred_date   = sanitize_text_field( (string) $preferred_date );
		$preferred_time   = sanitize_text_field( (string) $preferred_time );
		$visit_notes      = sanitize_textarea_field( (string) $visit_notes );

		if ( ! $accommodation_id || ! is_email( $email ) || ! $this->is_valid_date( $preferred_date ) || ! $this->is_valid_time( $preferred_time ) ) {
			return array( 'saved' => false, 'message' => 'invalid_visit_request_data' );
		}

		$queue_table = $wpdb->prefix . 'af_interest_queue';
		$request_message = sprintf(
			/* translators: 1: preferred date, 2: preferred time */
			__( 'Solicitud de visita desde chatbot. Fecha sugerida: %1$s. Hora sugerida: %2$s.', 'arriendo-facil' ),
			$preferred_date,
			$preferred_time
		);

		if ( '' !== $visit_notes ) {
			$request_message .= ' ' . sprintf(
				/* translators: %s: notes */
				__( 'Notas del interesado: %s', 'arriendo-facil' ),
				$visit_notes
			);
		}

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				 FROM {$queue_table}
				 WHERE accommodation_id = %d AND email = %s
				 ORDER BY id DESC
				 LIMIT 1",
				$accommodation_id,
				$email
			)
		);

		if ( $existing_id > 0 ) {
			$wpdb->update(
				$queue_table,
				array(
					'name'    => $name,
					'phone'   => $phone,
					'message' => $request_message,
					'status'  => 'visit_requested',
				),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$queue_table,
				array(
					'accommodation_id' => $accommodation_id,
					'name'             => $name,
					'email'            => $email,
					'phone'            => $phone,
					'message'          => $request_message,
					'status'           => 'visit_requested',
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		$active_interested_count = $this->get_active_interest_count_for_accommodation( $accommodation_id );

		$this->send_owner_visit_request_email( $accommodation_id, $name, $email, $phone, $preferred_date, $preferred_time, $visit_notes, $active_interested_count );
		$this->send_tenant_visit_request_email( $accommodation_id, $name, $email, $preferred_date, $preferred_time );

		return array(
			'saved'          => true,
			'preferred_date' => $preferred_date,
			'preferred_time' => $preferred_time,
		);
	}

	/**
	 * Sends owner notification for a new visit request suggestion.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $name Lead name.
	 * @param string $email Lead email.
	 * @param string $phone Lead phone.
	 * @param string $preferred_date Preferred date.
	 * @param string $preferred_time Preferred time.
	 * @param string $visit_notes Optional notes.
	 * @return void
	 */
	private function send_owner_visit_request_email( $accommodation_id, $name, $email, $phone, $preferred_date, $preferred_time, $visit_notes, $active_interested_count = 0 ) {
		$accommodation_id = absint( $accommodation_id );
		$owner_id         = (int) get_post_meta( $accommodation_id, '_af_owner_id', true );
		$owner            = $owner_id ? get_user_by( 'ID', $owner_id ) : null;
		$owner_email      = ( $owner && ! empty( $owner->user_email ) ) ? sanitize_email( (string) $owner->user_email ) : '';

		if ( ! is_email( $owner_email ) ) {
			return;
		}

		$title            = get_the_title( $accommodation_id );
		$subject          = sprintf( __( '[Arriendo Facil] Nueva cita solicitada para %s', 'arriendo-facil' ), $title );
		$active_count_int = max( 1, absint( $active_interested_count ) );

		$visit_notes_html = '';
		if ( '' !== trim( (string) $visit_notes ) ) {
			$visit_notes_html = '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'Mensaje adicional del interesado:', 'arriendo-facil' ) . '</strong></p>';
			$visit_notes_html .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;line-height:1.6;color:#334155;">' . nl2br( esc_html( sanitize_textarea_field( (string) $visit_notes ) ) ) . '</div>';
		}

		$message = '<div style="margin:0;padding:24px;background:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">';
		$message .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">';
		$message .= '<div style="padding:18px 22px;background:linear-gradient(135deg,#0f766e,#0ea5a4);color:#ffffff;">';
		$message .= '<h2 style="margin:0;font-size:20px;line-height:1.3;">' . esc_html__( 'Nueva solicitud de cita desde Arriendo Facil', 'arriendo-facil' ) . '</h2>';
		$message .= '</div>';
		$message .= '<div style="padding:22px;">';
		$message .= '<p style="margin:0 0 12px;line-height:1.6;">' . sprintf( esc_html__( 'Tienes un nuevo interesado en %s.', 'arriendo-facil' ), '<strong>' . esc_html( (string) $title ) . '</strong>' ) . '</p>';
		$message .= '<p style="margin:0 0 16px;line-height:1.6;">' . sprintf( esc_html__( 'Actualmente esta acomodacion tiene %d interesado(s) activo(s) en cola. Revisa y contacta a esta persona cuanto antes.', 'arriendo-facil' ), $active_count_int ) . '</p>';

		$message .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:0 0 16px;">';
		$message .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;"><strong>' . esc_html__( 'Acomodacion', 'arriendo-facil' ) . ':</strong> ' . esc_html( (string) $title ) . '</td></tr>';
		$message .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;"><strong>' . esc_html__( 'Interesado', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_text_field( (string) $name ) ) . '</td></tr>';
		$message .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;"><strong>' . esc_html__( 'Correo', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_email( (string) $email ) ) . '</td></tr>';
		$message .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;"><strong>' . esc_html__( 'Telefono', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_text_field( (string) $phone ) ) . '</td></tr>';
		$message .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;"><strong>' . esc_html__( 'Fecha sugerida', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_text_field( (string) $preferred_date ) ) . '</td></tr>';
		$message .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;"><strong>' . esc_html__( 'Hora sugerida', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_text_field( (string) $preferred_time ) ) . '</td></tr>';
		$message .= '</table>';

		$message .= $visit_notes_html;
		$message .= '<p style="margin:16px 0 0;line-height:1.6;color:#334155;">' . esc_html__( 'El arrendatario espera tu contacto para confirmar o ajustar la cita.', 'arriendo-facil' ) . '</p>';
		$message .= '</div>';
		$message .= '</div>';
		$message .= '<p style="max-width:640px;margin:12px auto 0;font-size:12px;color:#64748b;text-align:center;">Arriendo Facil</p>';
		$message .= '</div>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $owner_email, $subject, $message, $headers );
	}

	/**
	 * Sends tenant confirmation email after visit request is registered.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $tenant_name Tenant name.
	 * @param string $tenant_email Tenant email.
	 * @param string $preferred_date Suggested visit date.
	 * @param string $preferred_time Suggested visit time.
	 * @return void
	 */
	private function send_tenant_visit_request_email( $accommodation_id, $tenant_name, $tenant_email, $preferred_date, $preferred_time ) {
		$tenant_email = sanitize_email( (string) $tenant_email );
		if ( ! is_email( $tenant_email ) ) {
			return;
		}

		$tenant_name = sanitize_text_field( (string) $tenant_name );
		if ( '' === trim( $tenant_name ) ) {
			$tenant_name = __( 'arrendatario', 'arriendo-facil' );
		}

		$accommodation_title = (string) get_the_title( absint( $accommodation_id ) );
		if ( '' === trim( $accommodation_title ) ) {
			$accommodation_title = __( 'la acomodacion seleccionada', 'arriendo-facil' );
		}

		$subject = sprintf( __( '[Arriendo Facil] Recibimos tu solicitud de cita para %s', 'arriendo-facil' ), $accommodation_title );

		$message = '<div style="margin:0;padding:24px;background:#eef2ff;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1e293b;">';
		$message .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #dbeafe;border-radius:12px;overflow:hidden;">';
		$message .= '<div style="padding:18px 22px;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#ffffff;">';
		$message .= '<h2 style="margin:0;font-size:20px;line-height:1.3;">' . esc_html__( 'Tu solicitud de cita fue registrada', 'arriendo-facil' ) . '</h2>';
		$message .= '</div>';
		$message .= '<div style="padding:22px;">';
		$message .= '<p style="margin:0 0 12px;line-height:1.6;">' . sprintf( esc_html__( 'Hola %s, gracias por tu interes.', 'arriendo-facil' ), esc_html( $tenant_name ) ) . '</p>';
		$message .= '<p style="margin:0 0 16px;line-height:1.6;">' . esc_html__( 'Recibimos tu solicitud para visitar la acomodacion. El propietario se pondra en contacto contigo para confirmar la cita o proponer ajustes de fecha/hora.', 'arriendo-facil' ) . '</p>';

		$message .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:0;">';
		$message .= '<tr><td style="padding:8px 0;border-top:1px solid #dbeafe;"><strong>' . esc_html__( 'Acomodacion', 'arriendo-facil' ) . ':</strong> ' . esc_html( $accommodation_title ) . '</td></tr>';
		$message .= '<tr><td style="padding:8px 0;border-top:1px solid #dbeafe;"><strong>' . esc_html__( 'Fecha sugerida', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_text_field( (string) $preferred_date ) ) . '</td></tr>';
		$message .= '<tr><td style="padding:8px 0;border-top:1px solid #dbeafe;border-bottom:1px solid #dbeafe;"><strong>' . esc_html__( 'Hora sugerida', 'arriendo-facil' ) . ':</strong> ' . esc_html( sanitize_text_field( (string) $preferred_time ) ) . '</td></tr>';
		$message .= '</table>';

		$message .= '<p style="margin:16px 0 0;line-height:1.6;color:#334155;">' . esc_html__( 'Si necesitas cambiar algun dato, responde este correo o contactanos por los canales oficiales.', 'arriendo-facil' ) . '</p>';
		$message .= '</div>';
		$message .= '</div>';
		$message .= '<p style="max-width:640px;margin:12px auto 0;font-size:12px;color:#64748b;text-align:center;">Arriendo Facil</p>';
		$message .= '</div>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $tenant_email, $subject, $message, $headers );
	}

	/**
	 * Counts active interested leads for an accommodation.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return int
	 */
	private function get_active_interest_count_for_accommodation( $accommodation_id ) {
		global $wpdb;

		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id ) {
			return 0;
		}

		$table = $wpdb->prefix . 'af_interest_queue';
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE accommodation_id = %d AND status IN ('queued','notified','visit_requested')",
				$accommodation_id
			)
		);

		return max( 0, $count );
	}

	/**
	 * Checks valid date format (YYYY-MM-DD).
	 *
	 * @param string $value Date text.
	 * @return bool
	 */
	private function is_valid_date( $value ) {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value );
	}

	/**
	 * Checks valid time format (HH:MM).
	 *
	 * @param string $value Time text.
	 * @return bool
	 */
	private function is_valid_time( $value ) {
		return 1 === preg_match( '/^\d{2}:\d{2}$/', (string) $value );
	}

	/**
	 * Validates prudent visit time window (09:00 to 18:00).
	 *
	 * @param string $value Time text.
	 * @return bool
	 */
	private function is_prudent_visit_time( $value ) {
		if ( ! $this->is_valid_time( $value ) ) {
			return false;
		}

		$minutes = (int) substr( (string) $value, 0, 2 ) * 60 + (int) substr( (string) $value, 3, 2 );
		return $minutes >= 540 && $minutes <= 1080;
	}

	/**
	 * Returns masked guest data for safe chatbot confirmations.
	 *
	 * @param object $guest Guest DB row.
	 * @return array<string,string>
	 */
	private function build_masked_guest_snapshot( $guest ) {
		$full_name = trim( sanitize_text_field( (string) ( ( isset( $guest->first_name ) ? $guest->first_name : '' ) . ' ' . ( isset( $guest->last_name ) ? $guest->last_name : '' ) ) ) );
		$email     = isset( $guest->email ) ? sanitize_email( (string) $guest->email ) : '';
		$phone     = isset( $guest->phone ) ? preg_replace( '/\D+/', '', (string) $guest->phone ) : '';

		return array(
			'name'  => $this->mask_tail_value( $full_name ),
			'email' => $this->mask_tail_value( $email ),
			'phone' => $this->mask_tail_value( $phone ),
		);
	}

	/**
	 * Masks a value preserving only its last 4 characters.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function mask_tail_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '****';
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		$tail = function_exists( 'mb_substr' ) ? mb_substr( $value, $length - 4, null, 'UTF-8' ) : substr( $value, -4 );

		return str_repeat( '*', $length - 4 ) . $tail;
	}

	/**
	 * Normalizes reference name.
	 *
	 * @param string $name Raw reference name.
	 * @return string
	 */
	private function normalize_reference_name( $name ) {
		$name = sanitize_text_field( (string) $name );
		$name = preg_replace( '/\s+/', ' ', trim( $name ) );

		return is_string( $name ) ? $name : '';
	}

	/**
	 * Normalizes reference phone by keeping digits only.
	 *
	 * @param string $phone Raw reference phone.
	 * @return string
	 */
	private function normalize_reference_phone( $phone ) {
		$phone = preg_replace( '/\D+/', '', (string) $phone );

		return is_string( $phone ) ? $phone : '';
	}

	/**
	 * Builds storage text for a reference entry.
	 *
	 * @param string $name Reference name.
	 * @param string $phone Reference phone.
	 * @return string
	 */
	private function build_reference_entry( $name, $phone ) {
		$name  = $this->normalize_reference_name( $name );
		$phone = $this->normalize_reference_phone( $phone );

		if ( '' === $name || '' === $phone ) {
			return '';
		}

		return $name . ' - ' . $phone;
	}

	/**
	 * Validates reference storage format.
	 *
	 * @param string $reference Stored reference text.
	 * @return bool
	 */
	private function is_valid_reference_entry( $reference ) {
		$reference = sanitize_text_field( (string) $reference );
		$parts     = preg_split( '/\s*-\s*/', $reference, 2 );

		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return false;
		}

		$name  = $this->normalize_reference_name( $parts[0] );
		$phone = $this->normalize_reference_phone( $parts[1] );

		if ( strlen( $name ) < 3 || strlen( $name ) > 80 ) {
			return false;
		}

		return 1 === preg_match( '/^0[0-9]{9}$/', $phone );
	}

	/**
	 * Creates a new guest record via AJAX.
	 */
	public function ajax_create_guest() {
		check_ajax_referer( 'af_guest_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$id_number  = isset( $_POST['id_number'] ) ? sanitize_text_field( wp_unslash( $_POST['id_number'] ) ) : '';
		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;
		$rental_mode = isset( $_POST['rental_mode'] ) ? sanitize_key( wp_unslash( $_POST['rental_mode'] ) ) : '';
		$rental_start_date = isset( $_POST['rental_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rental_start_date'] ) ) : '';
		$rental_end_date   = isset( $_POST['rental_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rental_end_date'] ) ) : '';
		$rental_months     = isset( $_POST['rental_months'] ) ? absint( wp_unslash( $_POST['rental_months'] ) ) : 0;
		$rental_years      = isset( $_POST['rental_years'] ) ? absint( wp_unslash( $_POST['rental_years'] ) ) : 0;
		$desired_price     = isset( $_POST['desired_price'] ) ? sanitize_text_field( wp_unslash( $_POST['desired_price'] ) ) : '';
		$guarantee_text    = isset( $_POST['guarantee_text'] ) ? sanitize_text_field( wp_unslash( $_POST['guarantee_text'] ) ) : '';
		$mascotas   = isset( $_POST['mascotas'] ) ? absint( wp_unslash( $_POST['mascotas'] ) ) : 0;
		$referencia_personal_1 = isset( $_POST['referencia_personal_1'] ) ? sanitize_text_field( wp_unslash( $_POST['referencia_personal_1'] ) ) : '';
		$referencia_personal_2 = isset( $_POST['referencia_personal_2'] ) ? sanitize_text_field( wp_unslash( $_POST['referencia_personal_2'] ) ) : '';
		$personas_viviran      = isset( $_POST['personas_viviran'] ) ? absint( wp_unslash( $_POST['personas_viviran'] ) ) : 0;

		$name_parts = preg_split( '/\s+/', trim( $name ) );
		$first_name = ! empty( $name_parts[0] ) ? $name_parts[0] : '';
		$last_name  = count( $name_parts ) > 1 ? trim( implode( ' ', array_slice( $name_parts, 1 ) ) ) : '';

		if ( ! $first_name || ! $email || ! $phone || ! $id_number ) {
			wp_send_json_error( array( 'message' => __( 'Faltan campos obligatorios.', 'arriendo-facil' ) ) );
		}

		if ( $accommodation_id && 'accommodation' !== get_post_type( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'ID de alojamiento invalido.', 'arriendo-facil' ) ) );
		}

		if ( ! $referencia_personal_1 || ! $referencia_personal_2 ) {
			wp_send_json_error( array( 'message' => __( 'Please provide at least two personal references.', 'arriendo-facil' ) ) );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Correo electronico invalido.', 'arriendo-facil' ) ) );
		}

		if ( 1 !== preg_match( '/^[0-9]{1,10}$/', $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Phone must contain only numbers with a maximum of 10 digits.', 'arriendo-facil' ) ) );
		}

		if ( 1 !== preg_match( '/^[0-9]{1,10}$/', $id_number ) ) {
			wp_send_json_error( array( 'message' => __( 'ID (National ID or Passport) must contain only numbers with a maximum of 10 digits.', 'arriendo-facil' ) ) );
		}

		if ( $mascotas < 1 || $mascotas > 10 ) {
			wp_send_json_error( array( 'message' => __( 'Pets must be between 1 and 10.', 'arriendo-facil' ) ) );
		}

		if ( $personas_viviran < 1 || $personas_viviran > 10 ) {
			wp_send_json_error( array( 'message' => __( 'How many people will live in or enter the property? must be between 1 and 10.', 'arriendo-facil' ) ) );
		}

		$schema_result = $this->ensure_guest_extra_columns();
		if ( is_wp_error( $schema_result ) ) {
			wp_send_json_error( array( 'message' => $schema_result->get_error_message() ) );
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'af_guests',
			array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'email'      => $email,
				'phone'      => $phone,
				'id_number'  => $id_number,
				'accommodation_id' => $accommodation_id,
				'rental_mode'       => $rental_mode,
				'rental_start_date' => $rental_start_date ? $rental_start_date : null,
				'rental_end_date'   => $rental_end_date ? $rental_end_date : null,
				'rental_months'     => $rental_months ? $rental_months : null,
				'rental_years'      => $rental_years ? $rental_years : null,
				'desired_price'     => $desired_price,
				'guarantee_text'    => $guarantee_text,
				'mascotas'   => $mascotas,
				'referencia_personal_1' => $referencia_personal_1,
				'referencia_personal_2' => $referencia_personal_2,
				'personas_viviran'      => $personas_viviran,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d' )
		);

		if ( $inserted ) {
			$guest_id      = (int) $wpdb->insert_id;
			$upload_result = $this->upload_guest_documents( $guest_id );

			if ( is_wp_error( $upload_result ) ) {
				wp_send_json_error( array( 'message' => $upload_result->get_error_message() ) );
			}

			wp_send_json_success(
				array(
					'id'                => $guest_id,
					'uploaded_documents' => $upload_result,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'No se pudo crear el huesped.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Ensures required extra columns exist on guests table.
	 *
	 * @return true|WP_Error
	 */
	private function ensure_guest_extra_columns() {
		global $wpdb;

		$table = $wpdb->prefix . 'af_guests';
		$columns = array(
			'accommodation_id'      => 'ALTER TABLE ' . $table . ' ADD COLUMN accommodation_id BIGINT(20) UNSIGNED DEFAULT NULL',
			'rental_mode'           => 'ALTER TABLE ' . $table . ' ADD COLUMN rental_mode VARCHAR(20) DEFAULT NULL',
			'rental_start_date'     => 'ALTER TABLE ' . $table . ' ADD COLUMN rental_start_date DATE DEFAULT NULL',
			'rental_end_date'       => 'ALTER TABLE ' . $table . ' ADD COLUMN rental_end_date DATE DEFAULT NULL',
			'rental_months'         => 'ALTER TABLE ' . $table . ' ADD COLUMN rental_months SMALLINT UNSIGNED DEFAULT NULL',
			'rental_years'          => 'ALTER TABLE ' . $table . ' ADD COLUMN rental_years SMALLINT UNSIGNED DEFAULT NULL',
			'desired_price'         => 'ALTER TABLE ' . $table . ' ADD COLUMN desired_price VARCHAR(100) DEFAULT NULL',
			'guarantee_text'        => 'ALTER TABLE ' . $table . ' ADD COLUMN guarantee_text VARCHAR(255) DEFAULT NULL',
			'mascotas'             => 'ALTER TABLE ' . $table . ' ADD COLUMN mascotas TINYINT UNSIGNED DEFAULT NULL',
			'referencia_personal_1'=> 'ALTER TABLE ' . $table . ' ADD COLUMN referencia_personal_1 VARCHAR(255) DEFAULT NULL',
			'referencia_personal_2'=> 'ALTER TABLE ' . $table . ' ADD COLUMN referencia_personal_2 VARCHAR(255) DEFAULT NULL',
			'personas_viviran'     => 'ALTER TABLE ' . $table . ' ADD COLUMN personas_viviran TINYINT UNSIGNED DEFAULT NULL',
		);

		foreach ( $columns as $column_name => $sql ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SHOW COLUMNS FROM {$table} LIKE %s",
					$column_name
				)
			);

			if ( $exists ) {
				continue;
			}

			$result = $wpdb->query( $sql );
			if ( false === $result ) {
				return new WP_Error( 'af_guest_schema_update_failed', __( 'No se pudo actualizar el esquema de la tabla de huespedes para campos adicionales.', 'arriendo-facil' ) );
			}
		}

		return true;
	}

	/**
	 * Creates a draft lease and attempts automatic contract generation.
	 *
	 * @param int   $guest_id Guest ID.
	 * @param array $data Guest rental data.
	 * @return array
	 */
	private function create_lease_contract_for_guest( $guest_id, array $data ) {
		$accommodation_id = isset( $data['accommodation_id'] ) ? absint( $data['accommodation_id'] ) : 0;
		$rental_mode      = isset( $data['rental_mode'] ) ? sanitize_key( $data['rental_mode'] ) : 'years';

		if ( ! $accommodation_id || ! $guest_id ) {
			return array( 'generated' => false );
		}

		$start_date = '';
		$end_date   = '';
		$today      = current_time( 'Y-m-d' );

		if ( 'dates' === $rental_mode ) {
			$start_date = isset( $data['rental_start_date'] ) ? sanitize_text_field( $data['rental_start_date'] ) : '';
			$end_date   = isset( $data['rental_end_date'] ) ? sanitize_text_field( $data['rental_end_date'] ) : '';
		} elseif ( 'months' === $rental_mode ) {
			$months = isset( $data['rental_months'] ) ? absint( $data['rental_months'] ) : 1;
			$start_date = isset( $data['rental_start_date'] ) ? sanitize_text_field( $data['rental_start_date'] ) : $today;
			$end_date   = gmdate( 'Y-m-d', strtotime( '+' . max( 1, $months ) . ' months', strtotime( $start_date ) ) );
		} else {
			$years = isset( $data['rental_years'] ) ? max( 1, absint( $data['rental_years'] ) ) : 1;
			$start_date = isset( $data['rental_start_date'] ) ? sanitize_text_field( $data['rental_start_date'] ) : $today;
			$end_date   = gmdate( 'Y-m-d', strtotime( '+' . $years . ' years', strtotime( $start_date ) ) );
		}

		if ( ! $start_date || ! $end_date ) {
			return array( 'generated' => false );
		}

		$monthly_rent = (float) get_post_meta( $accommodation_id, '_af_monthly_rent', true );

		global $wpdb;
		$lease_inserted = $wpdb->insert(
			$wpdb->prefix . 'af_leases',
			array(
				'accommodation_id' => $accommodation_id,
				'guest_id'         => $guest_id,
				'start_date'       => $start_date,
				'end_date'         => $end_date,
				'monthly_rent'     => $monthly_rent,
				'status'           => 'draft',
			),
			array( '%d', '%d', '%s', '%s', '%f', '%s' )
		);

		if ( ! $lease_inserted ) {
			return array( 'generated' => false );
		}

		$lease_id = (int) $wpdb->insert_id;

		// Auto-marcar acomodación como ocupada al crear contrato en borrador.
		update_post_meta( $accommodation_id, '_af_is_occupied', '1' );

		$this->send_tenant_processing_email(
			isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '',
			isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '',
			$accommodation_id,
			$lease_id
		);

		$owner_contract_example = $this->get_owner_contract_example_context( $accommodation_id );
		$owner_template_exists  = ! empty( $owner_contract_example['attachment_id'] );
		$owner_id_number        = $this->get_owner_identification_number( isset( $owner_contract_example['owner_user_id'] ) ? absint( $owner_contract_example['owner_user_id'] ) : 0 );
		$accommodation_address  = (string) get_post_meta( $accommodation_id, '_af_address', true );
		$accommodation_title    = (string) get_the_title( $accommodation_id );

		if ( $owner_template_exists ) {
			error_log( 'Arriendo Facil contract generation: owner template detected for lease_id=' . $lease_id . ', attachment_id=' . $owner_contract_example['attachment_id'] . ', owner_name=' . ( isset( $owner_contract_example['owner_name'] ) ? $owner_contract_example['owner_name'] : 'unknown' ) );
		} else {
			error_log( 'Arriendo Facil contract generation: no owner template found for lease_id=' . $lease_id . ', accommodation_id=' . $accommodation_id . '; will use AI generation' );
		}

		$ai_payload = array(
			'lease_id'          => $lease_id,
			'accommodation_id'  => $accommodation_id,
			'accommodation_title' => $accommodation_title,
			'accommodation_address' => sanitize_text_field( $accommodation_address ),
			'accommodation_city' => (string) get_post_meta( $accommodation_id, '_af_city', true ),
			'accommodation_square_meters' => (string) get_post_meta( $accommodation_id, '_af_square_meters', true ),
			'accommodation_bedrooms' => (string) get_post_meta( $accommodation_id, '_af_bedrooms', true ),
			'accommodation_bathrooms' => (string) get_post_meta( $accommodation_id, '_af_bathrooms', true ),
			'accommodation_property_type' => (string) get_post_meta( $accommodation_id, '_af_property_type', true ),
			'guest_id'          => $guest_id,
			'guest_name'        => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'guest_email'       => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '',
			'guest_phone'       => isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '',
			'guest_id_number'   => isset( $data['id_number'] ) ? sanitize_text_field( $data['id_number'] ) : '',
			'mascotas'          => isset( $data['mascotas'] ) ? absint( $data['mascotas'] ) : 0,
			'referencia_personal_1' => '',
			'referencia_personal_2' => '',
			'personas_viviran'  => isset( $data['personas_viviran'] ) ? absint( $data['personas_viviran'] ) : 0,
			'start_date'        => $start_date,
			'end_date'          => $end_date,
			'monthly_rent'      => $monthly_rent,
			'rental_mode'       => $rental_mode,
			'guarantee_text'    => 'Garantía equivalente a dos (2) meses del canon de arrendamiento',
			'template_available'=> ! empty( $owner_contract_example['attachment_id'] ),
			'template_name'     => isset( $owner_contract_example['file_name'] ) ? sanitize_text_field( (string) $owner_contract_example['file_name'] ) : '',
			'template_mime'     => isset( $owner_contract_example['mime_type'] ) ? sanitize_text_field( (string) $owner_contract_example['mime_type'] ) : '',
			'template_url'      => isset( $owner_contract_example['url'] ) ? esc_url_raw( (string) $owner_contract_example['url'] ) : '',
			'template_text'     => isset( $owner_contract_example['template_text'] ) ? (string) $owner_contract_example['template_text'] : '',
			'owner_user_id'     => isset( $owner_contract_example['owner_user_id'] ) ? absint( $owner_contract_example['owner_user_id'] ) : 0,
			'owner_name'        => isset( $owner_contract_example['owner_name'] ) ? sanitize_text_field( (string) $owner_contract_example['owner_name'] ) : '',
			'owner_email'       => isset( $owner_contract_example['owner_email'] ) ? sanitize_email( (string) $owner_contract_example['owner_email'] ) : '',
			'owner_id_number'   => $owner_id_number,
		);

		$ai_payload['legal_requirements'] = $this->get_contract_legal_requirements();
		$ai_payload['legal_template_base'] = $this->build_legal_contract_template( $ai_payload, '' );

		$document_result = new WP_Error( 'af_ai_not_executed', __( 'AI document generation was not executed.', 'arriendo-facil' ) );
		if ( ! $owner_template_exists && class_exists( 'Arriendo_Facil_AI_Service' ) ) {
			try {
				$ai = new Arriendo_Facil_AI_Service();
				$document_result = $ai->generate_document( $ai_payload );
			} catch ( Throwable $throwable ) {
				error_log( 'Arriendo Facil AI document generation exception: ' . $throwable->getMessage() );
				$document_result = new WP_Error( 'af_ai_exception', __( 'AI document generation failed unexpectedly.', 'arriendo-facil' ) );
			}
		}

		$document_url = '';
		if ( $owner_template_exists ) {
			$document_url = $this->create_filled_contract_from_owner_template( $lease_id, $owner_contract_example, $ai_payload );
			if ( '' === $document_url && isset( $owner_contract_example['url'] ) && is_string( $owner_contract_example['url'] ) ) {
				$document_url = esc_url_raw( (string) $owner_contract_example['url'] );
			}
		}

		$generated_contract_text = '';

		if ( ! $owner_template_exists ) {
			if ( ! is_wp_error( $document_result ) && isset( $document_result['contract_text'] ) && is_string( $document_result['contract_text'] ) ) {
				$generated_contract_text = trim( wp_strip_all_tags( $document_result['contract_text'] ) );
			}

			if ( '' === $generated_contract_text ) {
				$generated_contract_text = $this->build_fallback_contract_text( $ai_payload );
			}
		}

		$generated_contract_text = $this->normalize_generated_legal_contract_text( $generated_contract_text, $ai_payload );

		if ( '' === $document_url && ! $owner_template_exists && ! is_wp_error( $document_result ) && isset( $document_result['document_url'] ) && is_string( $document_result['document_url'] ) ) {
			$document_url = esc_url_raw( $document_result['document_url'] );
		}

		if ( '' === $document_url && ! $owner_template_exists && '' !== $generated_contract_text ) {
			try {
				$generated_file_url = $this->create_generated_contract_file( $lease_id, $generated_contract_text, $ai_payload );
				if ( $generated_file_url ) {
					$document_url = $generated_file_url;
				}
			} catch ( Throwable $throwable ) {
				error_log( 'Arriendo Facil contract file generation exception: ' . $throwable->getMessage() );
			}
		}

		if ( '' === $document_url && ! $owner_template_exists && '' !== $generated_contract_text ) {
			$last_resort_url = $this->create_last_resort_contract_file( $lease_id, $generated_contract_text );
			if ( $last_resort_url ) {
				$document_url = $last_resort_url;
			}
		}

		if ( $document_url ) {
			$this->force_attach_lease_document( $lease_id, $document_url );
		}

		return array(
			'generated'    => (bool) $document_url,
			'lease_id'     => $lease_id,
			'document_url' => $document_url,
			'template_used' => ! empty( $owner_contract_example['attachment_id'] ),
			'template_attachment_id' => isset( $owner_contract_example['attachment_id'] ) ? (int) $owner_contract_example['attachment_id'] : 0,
		);
	}

	/**
	 * Creates a lease document from owner's DOCX template preserving layout/styles.
	 *
	 * @param int   $lease_id Lease ID.
	 * @param array $owner_template Owner template context.
	 * @param array $payload Lease payload.
	 * @return string
	 */
	private function create_filled_contract_from_owner_template( $lease_id, array $owner_template, array $payload ) {
		$lease_id       = absint( $lease_id );
		$attachment_id  = isset( $owner_template['attachment_id'] ) ? absint( $owner_template['attachment_id'] ) : 0;
		$template_path  = $attachment_id ? get_attached_file( $attachment_id ) : '';
		$template_mime  = isset( $owner_template['mime_type'] ) ? strtolower( (string) $owner_template['mime_type'] ) : '';
		$template_ext   = strtolower( (string) pathinfo( (string) $template_path, PATHINFO_EXTENSION ) );
		$tmp_downloaded  = false;

		if ( ! $lease_id || ! $attachment_id ) {
			error_log( 'Arriendo Facil owner-template generation skipped: missing lease_id or attachment_id.' );
			return '';
		}

		// If local file is missing, download from R2.
		if ( ! $template_path || ! file_exists( $template_path ) ) {
			$template_path = $this->download_owner_template_from_r2( $attachment_id );
			if ( ! $template_path ) {
				error_log( 'Arriendo Facil owner-template generation failed: template file not found locally and R2 download failed. attachment_id=' . $attachment_id );
				return '';
			}
			$tmp_downloaded = true;
			$template_ext   = strtolower( (string) pathinfo( $template_path, PATHINFO_EXTENSION ) );
		}

		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' !== $template_mime && 'docx' !== $template_ext ) {
			if ( $tmp_downloaded ) {
				@unlink( $template_path );
			}
			error_log( 'Arriendo Facil owner-template generation failed: template is not DOCX. attachment_id=' . $attachment_id . ', mime=' . $template_mime . ', ext=' . $template_ext );
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			if ( $tmp_downloaded ) {
				@unlink( $template_path );
			}
			error_log( 'Arriendo Facil owner-template generation failed: wp_upload_dir unavailable.' );
			return '';
		}

		$contracts_dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/contracts';
		if ( ! wp_mkdir_p( $contracts_dir ) ) {
			if ( $tmp_downloaded ) {
				@unlink( $template_path );
			}
			error_log( 'Arriendo Facil owner-template generation failed: cannot create contracts dir.' );
			return '';
		}

		$file_name = sprintf( 'lease-%d-owner-template-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;

		// PRIMARY PATH: AI-driven direct fill (works with any template format).
		$phpword_success = false;

		// Check configured processing method (markdown is primary, direct_xml is fallback).
		$processing_method = defined( 'AF_CONTRACT_PROCESSING_METHOD' )
			? AF_CONTRACT_PROCESSING_METHOD
			: (string) get_option( 'af_contract_processing_method', 'markdown' );

		// MARKDOWN PATH: try pandoc-based flow first.
		if ( 'markdown' === $processing_method
			&& class_exists( 'Arriendo_Facil_DOCX_Template_Processor' )
			&& Arriendo_Facil_DOCX_Template_Processor::is_pandoc_available()
		) {
			try {
				$tpl_proc   = new Arriendo_Facil_DOCX_Template_Processor();
				$ai_service = class_exists( 'Arriendo_Facil_AI_Service' ) ? new Arriendo_Facil_AI_Service() : null;

				$md_payload = $payload;
				$md_payload['attachment_id'] = $attachment_id;

				if ( $ai_service && $tpl_proc->fill_template_with_markdown( $template_path, $file_path, $md_payload, $ai_service ) ) {
					$phpword_success = true;
					error_log( 'Arriendo Facil owner-template generation: fill_template_with_markdown succeeded for lease_id=' . $lease_id );
				} else {
					error_log( 'Arriendo Facil owner-template generation: fill_template_with_markdown failed for lease_id=' . $lease_id . '; falling through to direct XML methods' );
				}
			} catch ( \Throwable $e ) {
				error_log( 'Arriendo Facil owner-template generation: fill_template_with_markdown exception for lease_id=' . $lease_id . ': ' . $e->getMessage() );
			}
		}

		// DIRECT XML FALLBACK: existing P1/P2/P3 priority cascade.
		if ( ! $phpword_success && class_exists( 'Arriendo_Facil_DOCX_Template_Processor' ) ) {
			$tpl_proc   = new Arriendo_Facil_DOCX_Template_Processor();
			$ai_service = class_exists( 'Arriendo_Facil_AI_Service' ) ? new Arriendo_Facil_AI_Service() : null;

			// PRIORITY 1: Deterministic context-based filling (no AI, no saved map needed).
			if ( $tpl_proc->fill_template_with_context( $template_path, $file_path, $payload ) ) {
				$phpword_success = true;
				error_log( 'Arriendo Facil owner-template generation: fill_template_with_context succeeded for lease_id=' . $lease_id );
			}

			// PRIORITY 2: AI-driven direct fill.
			if ( ! $phpword_success && $ai_service && $tpl_proc->fill_template_with_ai( $template_path, $file_path, $payload, $ai_service ) ) {
				$phpword_success = true;
				error_log( 'Arriendo Facil owner-template generation: fill_template_with_ai succeeded for lease_id=' . $lease_id );
			}

			// PRIORITY 3: Legacy pre-processed template with PhpWord TemplateProcessor.
			if ( ! $phpword_success ) {
				error_log( 'Arriendo Facil owner-template generation: fill_template_with_ai failed or unavailable for lease_id=' . $lease_id . '; trying legacy path' );

				$processed_tpl_path = '';

				$processed_new = $tpl_proc->process_owner_template( $template_path, $ai_service, '', $payload );
				if ( '' !== $processed_new && file_exists( $processed_new ) ) {
					$processed_tpl_path = $processed_new;
					update_post_meta( $attachment_id, '_af_processed_template_path', $processed_tpl_path );
					error_log( 'Arriendo Facil owner-template generation: regenerated processed template for lease_id=' . $lease_id );
				}

				if ( '' !== $processed_tpl_path && file_exists( $processed_tpl_path ) ) {
					if ( $tpl_proc->fill_template( $processed_tpl_path, $file_path, $payload ) ) {
						$phpword_success = true;
						error_log( 'Arriendo Facil owner-template generation: legacy fill_template succeeded for lease_id=' . $lease_id );
					}
				}
			}
		}

		if ( $phpword_success ) {
			$validation = $this->validate_filled_contract( $file_path, $lease_id );
			if ( ! $validation['valid'] ) {
				error_log( 'Arriendo Facil owner-template generation: contract validation failed for lease_id=' . $lease_id . ', missing_count=' . $validation['missing_count'] );
			}
		}

		if ( $tmp_downloaded ) {
			@unlink( $template_path );
		}

		if ( ! $phpword_success ) {
			error_log( 'Arriendo Facil owner-template generation failed: could not produce a contract from the owner template.' );
			return '';
		}

		$mime_type    = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		$local_url    = trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name );
		$document_url = $local_url;
		$storage_meta = array(
			'provider'  => 'local',
			'file_name' => $file_name,
			'local_url' => $local_url,
			'mime_type' => $mime_type,
		);

		$storage_provider = $this->get_storage_setting( 'AF_STORAGE_PROVIDER', 'af_storage_provider', 'cloudflare_r2' );
		if ( 'cloudflare_r2' === $storage_provider ) {
			$r2_config = $this->get_r2_config();
			if ( ! is_wp_error( $r2_config ) ) {
				$contents = file_get_contents( $file_path );
				if ( false !== $contents ) {
					$object_key = sprintf( 'lease-contracts/%d/%s', $lease_id, sanitize_file_name( $file_name ) );
					$upload     = $this->upload_contents_to_r2( $contents, $object_key, $mime_type, $r2_config );
					if ( ! is_wp_error( $upload ) ) {
						$document_url = add_query_arg(
							array(
								'action'   => 'af_download_lease_contract',
								'lease_id' => $lease_id,
							),
							admin_url( 'admin-ajax.php' )
						);
						$storage_meta = array(
							'provider'   => 'cloudflare_r2',
							'object_key' => $object_key,
							'file_name'  => $file_name,
							'local_url'  => $local_url,
							'mime_type'  => $mime_type,
						);
					}
				}
			}
		}

		if ( class_exists( 'Arriendo_Facil_Lease' ) ) {
			$lease_service = new Arriendo_Facil_Lease();
			$lease_service->set_contract_storage_meta( $lease_id, $storage_meta );
		}

		return $document_url;
	}

	/**
	 * Validates that a filled DOCX contract contains critical lease fields.
	 *
	 * Returns validation result with count of missing critical fields.
	 * If 4+ critical fields are missing, contract may need fallback.
	 *
	 * @param string $file_path Path to the filled DOCX.
	 * @param int    $lease_id  Lease ID for logging.
	 * @return array { valid: bool, missing_count: int, missing_fields: array }
	 */
	private function validate_filled_contract( $file_path, $lease_id ) {
		$file_path = (string) $file_path;
		$lease_id  = absint( $lease_id );

		if ( ! $file_path || ! file_exists( $file_path ) || ! class_exists( 'ZipArchive' ) ) {
			error_log( 'Arriendo Facil contract validation: file not found or ZipArchive not available. lease_id=' . $lease_id . ', path=' . $file_path );
			return array( 'valid' => false, 'missing_count' => 99, 'missing_fields' => array() );
		}

		$critical_fields = array(
			'ARRENDATARIO'        => 'guest_name',
			'CEDULA_ARRENDATARIO' => 'guest_id_number',
			'ARRENDADOR'          => 'owner_name',
			'CANON'               => 'monthly_rent',
			'FECHA_INICIO'        => 'start_date',
			'DIRECCION'           => 'accommodation_address',
		);

		$blank_marker = '...............';
		$missing_fields = array();

		try {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $file_path ) ) {
				error_log( 'Arriendo Facil contract validation: cannot open DOCX. lease_id=' . $lease_id );
				return array( 'valid' => false, 'missing_count' => 99, 'missing_fields' => array() );
			}

			$xml = $zip->getFromName( 'word/document.xml' );
			$zip->close();

			if ( false === $xml || '' === $xml ) {
				error_log( 'Arriendo Facil contract validation: document.xml not found. lease_id=' . $lease_id );
				return array( 'valid' => false, 'missing_count' => 99, 'missing_fields' => array() );
			}

			$text = wp_strip_all_tags( (string) $xml );
			foreach ( $critical_fields as $placeholder => $field_name ) {
				if ( false === strpos( $text, $placeholder ) || false !== strpos( $text, '${' . $placeholder . '}' ) || false !== strpos( $text, $blank_marker ) ) {
					$missing_fields[] = $field_name . '(' . $placeholder . ')';
				}
			}

			$missing_count = count( $missing_fields );
			$valid = $missing_count < 4;

			error_log( 'Arriendo Facil contract validation: lease_id=' . $lease_id . ', missing_count=' . $missing_count . ', valid=' . ( $valid ? 'true' : 'false' ) . ', missing_fields=[' . implode( ', ', $missing_fields ) . ']' );

			return array(
				'valid'          => $valid,
				'missing_count'  => $missing_count,
				'missing_fields' => $missing_fields,
			);
		} catch ( \Throwable $e ) {
			error_log( 'Arriendo Facil contract validation exception: ' . $e->getMessage() . ' lease_id=' . $lease_id );
			return array( 'valid' => false, 'missing_count' => 99, 'missing_fields' => array() );
		}
	}

	/**
	 * Replaces known token formats inside DOCX XML parts without altering layout.
	 *
	 * @param string $docx_path Absolute DOCX path.
	 * @param array  $payload Lease payload.
	 * @return void
	 */
	private function replace_docx_template_tokens_in_place( $docx_path, array $payload ) {
		if ( ! class_exists( 'ZipArchive' ) || ! $docx_path || ! file_exists( $docx_path ) ) {
			return;
		}

		$token_map = $this->build_owner_template_token_map( $payload );
		if ( empty( $token_map ) ) {
			return;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $docx_path ) ) {
			return;
		}

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$entry_name = (string) $zip->getNameIndex( $index );
			if ( 1 !== preg_match( '#^word/(document|header[0-9]+|footer[0-9]+)\.xml$#i', $entry_name ) ) {
				continue;
			}

			$xml = $zip->getFromName( $entry_name );
			if ( false === $xml || '' === $xml ) {
				continue;
			}

			// Flatten split runs: Word sometimes splits placeholders across multiple <w:t> nodes.
			$flattened_xml = $this->flatten_split_placeholder_runs( (string) $xml );

			$updated_xml = preg_replace_callback(
				'/\{\{\s*([a-zA-Z0-9_\-\s]+)\s*\}\}|\[\[\s*([a-zA-Z0-9_\-\s]+)\s*\]\]|<<\s*([a-zA-Z0-9_\-\s]+)\s*>>/',
				function ( $matches ) use ( $token_map ) {
					$raw_token = '';
					if ( ! empty( $matches[1] ) ) {
						$raw_token = (string) $matches[1];
					} elseif ( ! empty( $matches[2] ) ) {
						$raw_token = (string) $matches[2];
					} elseif ( ! empty( $matches[3] ) ) {
						$raw_token = (string) $matches[3];
					}

					$normalized = $this->normalize_contract_placeholder_token( $raw_token );
					if ( '' !== $normalized && isset( $token_map[ $normalized ] ) ) {
						return esc_xml( (string) $token_map[ $normalized ] );
					}

					return $matches[0];
				},
				$flattened_xml
			);

			if ( is_string( $updated_xml ) ) {
				$updated_xml = $this->replace_instruction_bracket_placeholders_in_xml( $updated_xml, $payload );
			}

			if ( is_string( $updated_xml ) && $updated_xml !== (string) $xml ) {
				$zip->addFromString( $entry_name, $updated_xml );
			}
		}

		$zip->close();
	}

	/**
	 * Flattens adjacent <w:r> runs that split a placeholder across multiple <w:t> nodes.
	 *
	 * @param string $xml Word XML content.
	 * @return string
	 */
	private function flatten_split_placeholder_runs( $xml ) {
		$result = preg_replace_callback(
			'#(<w:p\b[^>]*>)(.*?)(</w:p>)#si',
			function ( $p_match ) {
				$inner = $p_match[2];
				$full_text = '';
				preg_match_all( '#<w:t[^>]*>(.*?)</w:t>#si', $inner, $t_matches );
				if ( ! empty( $t_matches[1] ) ) {
					$full_text = implode( '', $t_matches[1] );
				}

				if ( ! preg_match( '/\{\{[^}]+\}\}|\[\[[^\]]+\]\]|<<[^>]+>>|\[[^\]]+\]/', $full_text ) ) {
					return $p_match[0];
				}

				$merged = preg_replace_callback(
					'#(<w:r\b[^>]*>\s*(?:<w:rPr>.*?</w:rPr>\s*)?)<w:t[^>]*>([^<]*)</w:t>\s*</w:r>(?:\s*<w:r\b[^>]*>\s*(?:<w:rPr>.*?</w:rPr>\s*)?<w:t[^>]*>([^<]*)</w:t>\s*</w:r>)+#si',
					function ( $run_match ) {
						preg_match_all( '#<w:t[^>]*>([^<]*)</w:t>#si', $run_match[0], $texts );
						$combined = implode( '', $texts[1] );
						preg_match( '#(<w:r\b[^>]*>\s*(?:<w:rPr>.*?</w:rPr>\s*)?)#si', $run_match[0], $first_run );
						$run_start = isset( $first_run[1] ) ? $first_run[1] : '<w:r>';
						return $run_start . '<w:t xml:space="preserve">' . $combined . '</w:t></w:r>';
					},
					$inner
				);

				return $p_match[1] . $merged . $p_match[3];
			},
			(string) $xml
		);

		return is_string( $result ) ? $result : (string) $xml;
	}

	/**
	 * Completes blank fields identified by semantic labels in DOCX template.
	 *
	 * @param string $docx_path DOCX path.
	 * @param array  $owner_template Owner template context.
	 * @param array  $payload Lease payload.
	 * @return void
	 */
	private function replace_docx_semantic_label_blanks_in_place( $docx_path, array $owner_template, array $payload ) {
		if ( ! class_exists( 'ZipArchive' ) || ! $docx_path || ! file_exists( $docx_path ) ) {
			return;
		}

		$label_value_map = $this->build_semantic_label_value_map( $owner_template, $payload );
		if ( empty( $label_value_map ) ) {
			return;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $docx_path ) ) {
			return;
		}

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$entry_name = (string) $zip->getNameIndex( $index );
			if ( 1 !== preg_match( '#^word/(document|header[0-9]+|footer[0-9]+)\.xml$#i', $entry_name ) ) {
				continue;
			}

			$xml = $zip->getFromName( $entry_name );
			if ( false === $xml || '' === $xml ) {
				continue;
			}

			$updated_xml = $this->replace_semantic_blanks_in_docx_xml( (string) $xml, $label_value_map );
			if ( $updated_xml !== $xml ) {
				$zip->addFromString( $entry_name, $updated_xml );
			}
		}

		$zip->close();
	}

	/**
	 * Replaces blank placeholders associated to labels in a Word XML fragment.
	 *
	 * @param string $xml XML content.
	 * @param array  $label_value_map Label-to-value mapping.
	 * @return string
	 */
	private function replace_semantic_blanks_in_docx_xml( $xml, array $label_value_map ) {
		$updated = (string) $xml;

		foreach ( $label_value_map as $label => $value ) {
			$clean_label = trim( (string) $label );
			$clean_value = trim( (string) $value );

			if ( '' === $clean_label || '' === $clean_value ) {
				continue;
			}

			$label_pattern = preg_quote( $clean_label, '/' );
			$value_xml     = esc_xml( $clean_value );

			$pattern_same_node = '/(<w:t[^>]*>\s*' . $label_pattern . '\s*[:\-]?\s*)(_{3,}|\.{3,}|[\x{2026}]{2,})(\s*<\/w:t>)/iu';
			$replacement_same_node = '$1' . $value_xml . '$3';
			$updated = preg_replace( $pattern_same_node, $replacement_same_node, $updated );

			$pattern_next_node = '/(<w:t[^>]*>\s*' . $label_pattern . '\s*[:\-]?\s*<\/w:t>\s*<w:t[^>]*>)(_{3,}|\.{3,}|[\x{2026}]{2,})(\s*<\/w:t>)/iu';
			$replacement_next_node = '$1' . $value_xml . '$3';
			$updated = preg_replace( $pattern_next_node, $replacement_next_node, $updated );
		}

		return (string) $updated;
	}

	/**
	 * Builds semantic mapping between template labels and payload values.
	 *
	 * @param array $owner_template Owner template context.
	 * @param array $payload Lease payload.
	 * @return array<string,string>
	 */
	private function build_semantic_label_value_map( array $owner_template, array $payload ) {
		$template_text = isset( $owner_template['template_text'] ) ? (string) $owner_template['template_text'] : '';
		$template_text = trim( $template_text );
		if ( '' === $template_text ) {
			return array();
		}

		$canonical_values = $this->get_canonical_contract_value_map( $payload );
		$labels           = $this->extract_semantic_candidate_labels( $template_text );
		if ( empty( $labels ) ) {
			return array();
		}

		$field_map = array();
		foreach ( $labels as $label ) {
			$canonical_key = $this->infer_canonical_key_from_label( $label );
			if ( '' !== $canonical_key ) {
				$field_map[ $label ] = $canonical_key;
			}
		}

		if ( class_exists( 'Arriendo_Facil_AI_Service' ) ) {
			try {
				$ai_service = new Arriendo_Facil_AI_Service();
				$ai_result  = $ai_service->map_template_fields(
					array(
						'template_text'     => $template_text,
						'candidate_labels'  => $labels,
						'allowed_canonical' => array_keys( $canonical_values ),
					)
				);

				if ( ! is_wp_error( $ai_result ) && isset( $ai_result['field_map'] ) && is_array( $ai_result['field_map'] ) ) {
					foreach ( $ai_result['field_map'] as $label => $canonical_key ) {
						$label         = (string) $label;
						$canonical_key = sanitize_key( (string) $canonical_key );
						if ( '' !== $label && isset( $canonical_values[ $canonical_key ] ) ) {
							$field_map[ $label ] = $canonical_key;
						}
					}
				}
			} catch ( Throwable $throwable ) {
				error_log( 'Arriendo Facil semantic label mapping error: ' . $throwable->getMessage() );
			}
		}

		$label_value_map = array();
		foreach ( $field_map as $label => $canonical_key ) {
			if ( isset( $canonical_values[ $canonical_key ] ) ) {
				$value = trim( (string) $canonical_values[ $canonical_key ] );
				if ( '' !== $value ) {
					$label_value_map[ (string) $label ] = $value;
				}
			}
		}

		return $label_value_map;
	}

	/**
	 * Returns canonical payload values used for semantic completion.
	 *
	 * @param array $payload Lease payload.
	 * @return array<string,string>
	 */
	private function get_canonical_contract_value_map( array $payload ) {
		$monthly_rent = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '';
		if ( '' === $monthly_rent && isset( $payload['desired_price'] ) ) {
			$monthly_rent = sanitize_text_field( (string) $payload['desired_price'] );
		}

		return array(
			'owner_name'            => isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '',
			'owner_email'           => isset( $payload['owner_email'] ) ? sanitize_email( (string) $payload['owner_email'] ) : '',
			'owner_id_number'       => isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '',
			'guest_name'            => isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '',
			'guest_email'           => isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '',
			'guest_phone'           => isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '',
			'guest_id_number'       => isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '',
			'accommodation_title'   => isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '',
			'accommodation_address' => isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '',
			'start_date'            => isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '',
			'end_date'              => isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '',
			'monthly_rent'          => $monthly_rent,
			'guarantee_text'        => isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : '',
			'current_date'          => current_time( 'Y-m-d' ),
		);
	}

	/**
	 * Extracts probable field labels from template text.
	 *
	 * @param string $template_text Plain template text.
	 * @return array<int,string>
	 */
	private function extract_semantic_candidate_labels( $template_text ) {
		$template_text = (string) $template_text;
		if ( '' === trim( $template_text ) ) {
			return array();
		}

		$labels = array();
		if ( preg_match_all( '/([A-Za-zÁÉÍÓÚÑáéíóúñ\(\)\/\-\s]{3,70})\s*:/u', $template_text, $matches ) ) {
			foreach ( $matches[1] as $raw_label ) {
				$label = trim( preg_replace( '/\s+/', ' ', (string) $raw_label ) );
				if ( '' !== $label && strlen( $label ) >= 3 && strlen( $label ) <= 70 ) {
					$labels[ $label ] = $label;
				}
			}
		}

		return array_values( $labels );
	}

	/**
	 * Infers canonical key from a human label using local heuristics.
	 *
	 * @param string $label Field label.
	 * @return string
	 */
	private function infer_canonical_key_from_label( $label ) {
		$label_norm = strtolower( trim( (string) $label ) );

		if ( false !== strpos( $label_norm, 'arrendador' ) || false !== strpos( $label_norm, 'propietario' ) ) {
			if ( false !== strpos( $label_norm, 'correo' ) || false !== strpos( $label_norm, 'email' ) ) {
				return 'owner_email';
			}
			if ( false !== strpos( $label_norm, 'cedula' ) || false !== strpos( $label_norm, 'ruc' ) || false !== strpos( $label_norm, 'identificacion' ) ) {
				return 'owner_id_number';
			}
			return 'owner_name';
		}

		if ( false !== strpos( $label_norm, 'arrendatario' ) || false !== strpos( $label_norm, 'inquilino' ) || false !== strpos( $label_norm, 'tenant' ) ) {
			if ( false !== strpos( $label_norm, 'correo' ) || false !== strpos( $label_norm, 'email' ) ) {
				return 'guest_email';
			}
			if ( false !== strpos( $label_norm, 'telefono' ) || false !== strpos( $label_norm, 'celular' ) ) {
				return 'guest_phone';
			}
			if ( false !== strpos( $label_norm, 'cedula' ) || false !== strpos( $label_norm, 'identificacion' ) ) {
				return 'guest_id_number';
			}
			return 'guest_name';
		}

		if ( false !== strpos( $label_norm, 'direccion' ) ) {
			return 'accommodation_address';
		}

		if ( false !== strpos( $label_norm, 'inmueble' ) || false !== strpos( $label_norm, 'propiedad' ) ) {
			return 'accommodation_title';
		}

		if ( false !== strpos( $label_norm, 'inicio' ) || false !== strpos( $label_norm, 'desde' ) ) {
			return 'start_date';
		}

		if ( false !== strpos( $label_norm, 'fin' ) || false !== strpos( $label_norm, 'hasta' ) ) {
			return 'end_date';
		}

		if ( false !== strpos( $label_norm, 'canon' ) || false !== strpos( $label_norm, 'renta' ) || false !== strpos( $label_norm, 'valor' ) || false !== strpos( $label_norm, 'precio' ) ) {
			return 'monthly_rent';
		}

		if ( false !== strpos( $label_norm, 'garantia' ) ) {
			return 'guarantee_text';
		}

		if ( false !== strpos( $label_norm, 'fecha' ) ) {
			return 'current_date';
		}

		return '';
	}

	/**
	 * Builds normalized token map from lease payload.
	 *
	 * @param array $payload Lease payload.
	 * @return array<string,string>
	 */
	private function build_owner_template_token_map( array $payload ) {
		$field_values = array(
			'owner_name'            => isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '',
			'owner_email'           => isset( $payload['owner_email'] ) ? sanitize_email( (string) $payload['owner_email'] ) : '',
			'owner_id_number'       => isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '',
			'guest_name'            => isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '',
			'guest_email'           => isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '',
			'guest_phone'           => isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '',
			'guest_id_number'       => isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '',
			'accommodation_title'   => isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '',
			'accommodation_address' => isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '',
			'start_date'            => isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '',
			'end_date'              => isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '',
			'monthly_rent'          => isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '',
			'desired_price'         => isset( $payload['desired_price'] ) ? sanitize_text_field( (string) $payload['desired_price'] ) : '',
			'guarantee_text'        => isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : '',
			'current_date'          => current_time( 'Y-m-d' ),
		);

		if ( '' === $field_values['monthly_rent'] && '' !== $field_values['desired_price'] ) {
			$field_values['monthly_rent'] = $field_values['desired_price'];
		}

		$aliases = array(
			'owner_name' => array( 'owner_name', 'owner', 'landlord_name', 'nombre_arrendador', 'arrendador_nombre', 'propietario_nombre', 'nombre_propietario' ),
			'owner_email' => array( 'owner_email', 'landlord_email', 'correo_arrendador', 'email_arrendador', 'correo_propietario', 'email_propietario' ),
			'owner_id_number' => array( 'owner_id', 'owner_id_number', 'landlord_id', 'cedula_arrendador', 'ruc_arrendador', 'cedula_propietario', 'id_propietario' ),
			'guest_name' => array( 'guest_name', 'tenant_name', 'nombre_arrendatario', 'arrendatario_nombre', 'inquilino_nombre', 'nombre_inquilino' ),
			'guest_email' => array( 'guest_email', 'tenant_email', 'correo_arrendatario', 'email_arrendatario', 'correo_inquilino', 'email_inquilino' ),
			'guest_phone' => array( 'guest_phone', 'tenant_phone', 'telefono_arrendatario', 'celular_arrendatario', 'telefono_inquilino', 'celular_inquilino' ),
			'guest_id_number' => array( 'guest_id', 'guest_id_number', 'tenant_id', 'cedula_arrendatario', 'id_arrendatario', 'cedula_inquilino', 'id_inquilino' ),
			'accommodation_title' => array( 'property_name', 'accommodation_title', 'nombre_inmueble', 'inmueble', 'propiedad', 'nombre_propiedad' ),
			'accommodation_address' => array( 'property_address', 'accommodation_address', 'direccion_inmueble', 'direccion_propiedad', 'direccion' ),
			'start_date' => array( 'start_date', 'lease_start', 'fecha_inicio', 'fecha_inicio_arriendo', 'inicio_contrato' ),
			'end_date' => array( 'end_date', 'lease_end', 'fecha_fin', 'fecha_fin_arriendo', 'fin_contrato' ),
			'monthly_rent' => array( 'monthly_rent', 'rent', 'canon', 'canon_mensual', 'valor_arriendo', 'precio_mensual' ),
			'guarantee_text' => array( 'guarantee', 'guarantee_text', 'garantia', 'detalle_garantia' ),
			'current_date' => array( 'current_date', 'fecha_actual', 'fecha_hoy' ),
		);

		$token_map = array();
		foreach ( $aliases as $field_key => $tokens ) {
			$value = isset( $field_values[ $field_key ] ) ? (string) $field_values[ $field_key ] : '';
			if ( '' === $value ) {
				continue;
			}

			foreach ( $tokens as $token ) {
				$normalized = $this->normalize_contract_placeholder_token( (string) $token );
				if ( '' !== $normalized ) {
					$token_map[ $normalized ] = $value;
				}
			}
		}

		return $token_map;
	}

	/**
	 * Normalizes template token names to snake_case.
	 *
	 * @param string $token Token label.
	 * @return string
	 */
	private function normalize_contract_placeholder_token( $token ) {
		$token = strtolower( trim( (string) $token ) );
		$token = str_replace( '-', '_', $token );
		$token = preg_replace( '/\s+/', '_', $token );
		$token = preg_replace( '/[^a-z0-9_]/', '', (string) $token );
		$token = preg_replace( '/_+/', '_', (string) $token );

		return trim( (string) $token, '_' );
	}

	/**
	 * Replaces single-bracket instruction placeholders like [indicar fecha] in XML text runs.
	 *
	 * @param string $xml XML content.
	 * @param array  $payload Lease payload.
	 * @return string
	 */
	private function replace_instruction_bracket_placeholders_in_xml( $xml, array $payload ) {
		$updated = preg_replace_callback(
			'/\[(?!\[)([^\]\r\n]{2,180})\](?!\])/u',
			function ( $matches ) use ( $payload ) {
				$raw_instruction = isset( $matches[1] ) ? trim( (string) $matches[1] ) : '';
				if ( '' === $raw_instruction ) {
					return $matches[0];
				}

				$replacement = $this->get_instruction_placeholder_replacement( $raw_instruction, $payload );
				if ( '' === $replacement ) {
					return $matches[0];
				}

				return esc_xml( $replacement );
			},
			(string) $xml
		);

		return is_string( $updated ) ? $updated : (string) $xml;
	}

	/**
	 * Resolves value for instruction placeholders from a phrase like "indicar fecha".
	 *
	 * @param string $instruction Placeholder instruction text.
	 * @param array  $payload Lease payload.
	 * @return string
	 */
	private function get_instruction_placeholder_replacement( $instruction, array $payload ) {
		$normalized = $this->normalize_contract_instruction_text( $instruction );
		if ( '' === $normalized ) {
			return '';
		}

		if ( false !== strpos( $normalized, 'opcion a' ) || false !== strpos( $normalized, 'opcion b' ) || false !== strpos( $normalized, 'opcion c' ) ) {
			return '';
		}

		$values = array(
			'owner_name' => isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '',
			'owner_email' => isset( $payload['owner_email'] ) ? sanitize_email( (string) $payload['owner_email'] ) : '',
			'owner_id' => isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '',
			'guest_name' => isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '',
			'guest_email' => isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '',
			'guest_phone' => isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '',
			'guest_id' => isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '',
			'accommodation_title' => isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '',
			'accommodation_address' => isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '',
			'start_date' => isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '',
			'end_date' => isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '',
			'monthly_rent' => isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '',
			'current_date' => current_time( 'Y-m-d' ),
		);

		if ( '' === $values['monthly_rent'] && isset( $payload['desired_price'] ) ) {
			$values['monthly_rent'] = sanitize_text_field( (string) $payload['desired_price'] );
		}

		if ( false !== strpos( $normalized, 'correo' ) || false !== strpos( $normalized, 'email' ) ) {
			if ( false !== strpos( $normalized, 'arrendador' ) || false !== strpos( $normalized, 'propietario' ) ) {
				return $values['owner_email'];
			}
			return $values['guest_email'];
		}

		if ( false !== strpos( $normalized, 'telefono' ) || false !== strpos( $normalized, 'celular' ) ) {
			return $values['guest_phone'];
		}

		if ( false !== strpos( $normalized, 'cedula' ) || false !== strpos( $normalized, 'identidad' ) || false !== strpos( $normalized, 'ruc' ) || false !== strpos( $normalized, 'dni' ) ) {
			if ( false !== strpos( $normalized, 'arrendador' ) || false !== strpos( $normalized, 'propietario' ) ) {
				return $values['owner_id'];
			}
			return $values['guest_id'];
		}

		if ( false !== strpos( $normalized, 'arrendador' ) || false !== strpos( $normalized, 'propietario' ) ) {
			return $values['owner_name'];
		}

		if ( false !== strpos( $normalized, 'arrendatario' ) || false !== strpos( $normalized, 'inquilino' ) ) {
			return $values['guest_name'];
		}

		if ( false !== strpos( $normalized, 'direccion' ) ) {
			return $values['accommodation_address'];
		}

		if ( false !== strpos( $normalized, 'inmueble' ) || false !== strpos( $normalized, 'propiedad' ) || false !== strpos( $normalized, 'departamento' ) ) {
			return $values['accommodation_title'];
		}

		if ( false !== strpos( $normalized, 'fecha' ) ) {
			if ( false !== strpos( $normalized, 'inicio' ) || false !== strpos( $normalized, 'entrada en vigor' ) ) {
				return $values['start_date'];
			}
			if ( false !== strpos( $normalized, 'fin' ) || false !== strpos( $normalized, 'finalizacion' ) || false !== strpos( $normalized, 'terminacion' ) ) {
				return $values['end_date'];
			}
			return $values['current_date'];
		}

		if ( false !== strpos( $normalized, 'canon' ) || false !== strpos( $normalized, 'renta' ) || false !== strpos( $normalized, 'importe' ) || false !== strpos( $normalized, 'cantidad' ) || false !== strpos( $normalized, 'valor' ) || false !== strpos( $normalized, 'dolar' ) ) {
			return $values['monthly_rent'];
		}

		if ( false !== strpos( $normalized, 'ciudad' ) ) {
			return 'Quito';
		}

		return '';
	}

	/**
	 * Normalizes instruction text from DOCX placeholders.
	 *
	 * @param string $text Instruction text.
	 * @return string
	 */
	private function normalize_contract_instruction_text( $text ) {
		$text = strtolower( trim( (string) $text ) );
		$text = strtr(
			$text,
			array(
				'á' => 'a',
				'é' => 'e',
				'í' => 'i',
				'ó' => 'o',
				'ú' => 'u',
				'ü' => 'u',
				'ñ' => 'n',
			)
		);
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Fills blank fields (_____ or .....) in a DOCX template by detecting labeled blanks at paragraph level.
	 * Handles the most common template format: label text followed by blank underscores/dots.
	 *
	 * @param string $docx_path Absolute path to the DOCX file.
	 * @param array  $payload   Lease payload with guest, owner, and property data.
	 * @return void
	 */
	private function fill_docx_blank_fields_in_place( $docx_path, array $payload ) {
		if ( ! class_exists( 'ZipArchive' ) || ! $docx_path || ! file_exists( $docx_path ) ) {
			return;
		}

		$value_map = $this->build_blank_fill_value_map( $payload );
		if ( empty( $value_map ) ) {
			return;
		}

		$label_map = $this->build_label_blank_fill_map( $payload );
		if ( empty( $label_map ) ) {
			return;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $docx_path ) ) {
			return;
		}

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = (string) $zip->getNameIndex( $i );
			if ( 1 !== preg_match( '#^word/(document|header[0-9]+|footer[0-9]+)\.xml$#i', $entry ) ) {
				continue;
			}

			$xml = $zip->getFromName( $entry );
			if ( false === $xml || '' === $xml ) {
				continue;
			}

			$updated = preg_replace_callback(
				'#<w:p\b[^>]*>.*?</w:p>#si',
				function ( $match ) use ( $label_map, $value_map ) {
					return $this->apply_label_blank_fill_in_paragraph( $match[0], $label_map, $value_map );
				},
				(string) $xml
			);

			if ( is_string( $updated ) && $updated !== (string) $xml ) {
				$zip->addFromString( $entry, $updated );
			}
		}

		$zip->close();
	}

	/**
	 * Processes a single <w:p> element and fills blank markers.
	 *
	 * @param string $para_xml  Full <w:p>...</w:p> XML string.
	 * @param array  $label_map Ordered label (lowercase) => value map.
	 * @param array  $value_map Canonical key => value map.
	 * @return string
	 */
	private function apply_label_blank_fill_in_paragraph( $para_xml, array $label_map, array $value_map ) {
		preg_match_all( '#<w:t(?:\s[^>]*)?>.*?</w:t>#si', $para_xml, $node_matches );
		if ( empty( $node_matches[0] ) ) {
			return $para_xml;
		}

		$full_text = '';
		foreach ( $node_matches[0] as $node ) {
			preg_match( '#<w:t[^>]*>(.*?)</w:t>#si', $node, $inner );
			$full_text .= isset( $inner[1] ) ? html_entity_decode( (string) $inner[1], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) : '';
		}

		if ( ! preg_match( '/_{3,}|\.{5,}|[\x{2026}]{2,}/u', $full_text ) ) {
			return $para_xml;
		}

		$blank_count = $this->count_blank_markers( $full_text );
		if ( $blank_count <= 0 ) {
			return $para_xml;
		}

		$replacement_values = array();
		$ai_sequence = $this->get_ai_blank_key_sequence_for_line( $full_text, $blank_count, $value_map );
		if ( ! empty( $ai_sequence ) ) {
			foreach ( $ai_sequence as $canonical_key ) {
				if ( isset( $value_map[ $canonical_key ] ) && '' !== trim( (string) $value_map[ $canonical_key ] ) ) {
					$replacement_values[] = (string) $value_map[ $canonical_key ];
				}
			}
		}

		if ( empty( $replacement_values ) ) {
			$replacement_values = $this->infer_blank_values_from_line( $full_text, $blank_count, $value_map );
		}

		if ( empty( $replacement_values ) ) {
			$full_lower = strtr(
				mb_strtolower( $full_text ),
				array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' )
			);
			$replacement_value = null;
			foreach ( $label_map as $label => $value ) {
				if ( false !== mb_strpos( $full_lower, $label ) ) {
					$replacement_value = $value;
					break;
				}
			}
			if ( null === $replacement_value ) {
				return $para_xml;
			}
			for ( $i = 0; $i < $blank_count; $i++ ) {
				$replacement_values[] = (string) $replacement_value;
			}
		}

		$replace_index = 0;
		$updated  = preg_replace_callback(
			'#(<w:t(?:\s[^>]*)?>)(.*?)(</w:t>)#si',
			function ( $m ) use ( $replacement_values, &$replace_index ) {
				$original_text = html_entity_decode( (string) $m[2], ENT_XML1 | ENT_QUOTES, 'UTF-8' );
				$node_text = $original_text;
				while ( $replace_index < count( $replacement_values ) && preg_match( '/_{3,}|\.{5,}|[\x{2026}]{2,}/u', $node_text ) ) {
					$next_value = (string) $replacement_values[ $replace_index ];
					$filled     = preg_replace( '/_{3,}|\.{5,}|[\x{2026}]{2,}/u', $next_value, $node_text, 1 );
					if ( ! is_string( $filled ) || $filled === $node_text ) {
						break;
					}
					$node_text = $filled;
					$replace_index++;
				}
				if ( $node_text !== $original_text ) {
					$open_tag = preg_replace( '/\s+xml:space="[^"]*"/', '', $m[1] );
					$open_tag = rtrim( substr( $open_tag, 0, -1 ) ) . ' xml:space="preserve">';
					return $open_tag . esc_xml( (string) $node_text ) . $m[3];
				}
				return $m[0];
			},
			$para_xml
		);

		return is_string( $updated ) ? $updated : $para_xml;
	}

	/**
	 * Counts blank markers in a plain text string.
	 *
	 * @param string $text Text to inspect.
	 * @return int
	 */
	private function count_blank_markers( $text ) {
		$matches = array();
		preg_match_all( '/_{3,}|\.{5,}|[\x{2026}]{2,}/u', (string) $text, $matches );

		return isset( $matches[0] ) ? count( $matches[0] ) : 0;
	}

	/**
	 * Uses AI to resolve a line-by-line ordered blank key sequence.
	 *
	 * @param string $line_text Line text containing blanks.
	 * @param int    $blank_count Number of blanks in line.
	 * @param array  $value_map Canonical key => value map.
	 * @return array<int,string>
	 */
	private function get_ai_blank_key_sequence_for_line( $line_text, $blank_count, array $value_map ) {
		$line_text   = trim( (string) $line_text );
		$blank_count = absint( $blank_count );
		if ( '' === $line_text || $blank_count <= 0 || ! class_exists( 'Arriendo_Facil_AI_Service' ) ) {
			return array();
		}

		$allowed = array();
		foreach ( $value_map as $key => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$allowed[] = sanitize_key( (string) $key );
			}
		}
		if ( empty( $allowed ) ) {
			return array();
		}

		$cache_key = md5( strtolower( $line_text ) . '|' . $blank_count . '|' . implode( ',', $allowed ) );
		static $cache = array();
		if ( isset( $cache[ $cache_key ] ) && is_array( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		try {
			$ai_result = ( new Arriendo_Facil_AI_Service() )->map_template_line_blanks(
				array(
					'lines' => array(
						array(
							'id'          => 'line_1',
							'text'        => $line_text,
							'blank_count' => $blank_count,
						),
					),
					'allowed_canonical' => array_values( array_unique( $allowed ) ),
				)
			);

			if ( ! is_wp_error( $ai_result ) && isset( $ai_result['line_map']['line_1'] ) && is_array( $ai_result['line_map']['line_1'] ) ) {
				$keys = array();
				foreach ( $ai_result['line_map']['line_1'] as $raw_key ) {
					$key = sanitize_key( (string) $raw_key );
					if ( '' !== $key && in_array( $key, $allowed, true ) ) {
						$keys[] = $key;
					}
				}
				if ( ! empty( $keys ) ) {
					$cache[ $cache_key ] = $keys;
					return $keys;
				}
			}
		} catch ( Throwable $throwable ) {
			error_log( 'Arriendo Facil AI line blank mapping error: ' . $throwable->getMessage() );
		}

		$cache[ $cache_key ] = array();
		return array();
	}

	/**
	 * Builds canonical values available for blank completion.
	 *
	 * @param array $payload Lease payload.
	 * @return array<string,string>
	 */
	private function build_blank_fill_value_map( array $payload ) {
		$monthly_rent = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '';
		if ( '' === $monthly_rent && isset( $payload['desired_price'] ) ) {
			$monthly_rent = sanitize_text_field( (string) $payload['desired_price'] );
		}

		$current_date = (string) current_time( 'Y-m-d' );
		$current_parts = explode( '-', $current_date );
		$current_year  = isset( $current_parts[0] ) ? $current_parts[0] : '';
		$current_month = isset( $current_parts[1] ) ? $current_parts[1] : '';
		$current_day   = isset( $current_parts[2] ) ? $current_parts[2] : '';

		return array(
			'owner_name'            => isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '',
			'owner_email'           => isset( $payload['owner_email'] ) ? sanitize_email( (string) $payload['owner_email'] ) : '',
			'owner_id_number'       => isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '',
			'guest_name'            => isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '',
			'guest_email'           => isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '',
			'guest_phone'           => isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '',
			'guest_id_number'       => isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '',
			'accommodation_title'   => isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '',
			'accommodation_address' => isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '',
			'start_date'            => isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '',
			'end_date'              => isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '',
			'monthly_rent'          => $monthly_rent,
			'guarantee_text'        => isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : '',
			'rental_years'          => isset( $payload['rental_years'] ) ? (string) absint( $payload['rental_years'] ) : '',
			'current_date'          => $current_date,
			'current_day'           => (string) $current_day,
			'current_month'         => (string) $current_month,
			'current_year'          => (string) $current_year,
		);
	}

	/**
	 * Infers blank replacements from local line context when AI mapping is unavailable.
	 *
	 * @param string $line_text Full line text.
	 * @param int    $blank_count Number of blanks.
	 * @param array  $value_map Canonical key => value map.
	 * @return array<int,string>
	 */
	private function infer_blank_values_from_line( $line_text, $blank_count, array $value_map ) {
		$parts = preg_split( '/(_{3,}|\.{5,}|[\x{2026}]{2,})/u', (string) $line_text );
		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return array();
		}

		$results = array();
		for ( $i = 0; $i < $blank_count; $i++ ) {
			$before = isset( $parts[ $i ] ) ? (string) $parts[ $i ] : '';
			$after  = isset( $parts[ $i + 1 ] ) ? (string) $parts[ $i + 1 ] : '';
			$before_norm = strtr(
				mb_strtolower( $before ),
				array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' )
			);
			$after_norm = strtr(
				mb_strtolower( $after ),
				array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' )
			);
			$ctx = $before_norm . ' ' . $after_norm;

			$key = '';
			if ( preg_match( '/senor\s*$/u', trim( $before_norm ) ) && false !== strpos( $after_norm, 'arrendador' ) ) {
				$key = 'owner_name';
			} elseif ( preg_match( '/senor\s*$/u', trim( $before_norm ) ) && false !== strpos( $after_norm, 'arrendatario' ) ) {
				$key = 'guest_name';
			} elseif ( false !== strpos( $before_norm, 'como arrendador, el senor' ) ) {
				$key = 'owner_name';
			} elseif ( false !== strpos( $before_norm, 'como arrendatario el senor' ) || false !== strpos( $before_norm, 'al senor' ) || false !== strpos( $before_norm, 'arrendatario senor' ) ) {
				$key = 'guest_name';
			} elseif ( false !== strpos( $before_norm, 'propietario de' ) ) {
				$key = 'accommodation_title';
			} elseif ( false !== strpos( $before_norm, 'situada en' ) || false !== strpos( $before_norm, 'ubicado en' ) || false !== strpos( $before_norm, 'calle' ) || false !== strpos( $before_norm, 'direccion' ) ) {
				$key = 'accommodation_address';
			} elseif ( false !== strpos( $before_norm, 'consignado con el numero' ) || false !== strpos( $after_norm, 'consignado con el numero' ) || false !== strpos( $before_norm, 'cedula' ) || false !== strpos( $after_norm, 'cedula' ) ) {
				$key = 'guest_id_number';
			} elseif ( false !== strpos( $before_norm, 'cantidad de' ) && ( false !== strpos( $after_norm, 'garantia' ) || false !== strpos( $after_norm, 'usd que obliga' ) ) ) {
				$key = 'guarantee_text';
			} elseif ( false !== strpos( $before_norm, 'cantidad de' ) && false !== strpos( $after_norm, 'usd por mes' ) ) {
				$key = 'monthly_rent';
			} elseif ( false !== strpos( $before_norm, 'plazo de este contrato es de' ) && false !== strpos( $after_norm, 'anos' ) ) {
				$key = 'rental_years';
			} elseif ( false !== strpos( $ctx, 'arrendatario' ) && false !== strpos( $ctx, 'senor' ) ) {
				$key = 'guest_name';
			}

			if ( '' !== $key && isset( $value_map[ $key ] ) && '' !== trim( (string) $value_map[ $key ] ) ) {
				$results[] = (string) $value_map[ $key ];
			}
		}

		return $results;
	}

	/**
	 * Builds an ordered label => value map for DOCX blank field filling.
	 * More specific labels are listed first to prevent partial-keyword mis-matches.
	 *
	 * @param array $payload Lease payload.
	 * @return array<string,string>
	 */
	private function build_label_blank_fill_map( array $payload ) {
		$monthly_rent = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '';
		if ( '' === $monthly_rent && isset( $payload['desired_price'] ) ) {
			$monthly_rent = sanitize_text_field( (string) $payload['desired_price'] );
		}

		$values = array(
			'owner_name'            => isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '',
			'owner_id'              => isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '',
			'owner_email'           => isset( $payload['owner_email'] ) ? sanitize_email( (string) $payload['owner_email'] ) : '',
			'guest_name'            => isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '',
			'guest_id'              => isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '',
			'guest_phone'           => isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '',
			'guest_email'           => isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '',
			'accommodation_address' => isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '',
			'accommodation_title'   => isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '',
			'start_date'            => isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '',
			'end_date'              => isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '',
			'monthly_rent'          => $monthly_rent,
			'guarantee_text'        => isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : '',
			'current_date'          => current_time( 'Y-m-d' ),
		);

		// Ordered most-specific to least-specific; first match wins per paragraph.
		$patterns = array(
			// Owner - specific.
			array( 'nombre del arrendador',          'owner_name' ),
			array( 'nombre propietario',             'owner_name' ),
			array( 'cedula del arrendador',          'owner_id' ),
			array( 'c.i. del arrendador',            'owner_id' ),
			array( 'ci del arrendador',              'owner_id' ),
			array( 'ruc del arrendador',             'owner_id' ),
			array( 'cedula arrendador',              'owner_id' ),
			array( 'correo del arrendador',          'owner_email' ),
			array( 'correo arrendador',              'owner_email' ),
			array( 'correo del propietario',         'owner_email' ),
			// Guest - specific.
			array( 'nombre del arrendatario',        'guest_name' ),
			array( 'nombre del inquilino',           'guest_name' ),
			array( 'nombre arrendatario',            'guest_name' ),
			array( 'nombre inquilino',               'guest_name' ),
			array( 'cedula del arrendatario',        'guest_id' ),
			array( 'cedula del inquilino',           'guest_id' ),
			array( 'c.i. del arrendatario',          'guest_id' ),
			array( 'ci del arrendatario',            'guest_id' ),
			array( 'cedula de ciudadania',           'guest_id' ),
			array( 'cedula arrendatario',            'guest_id' ),
			array( 'cedula inquilino',               'guest_id' ),
			array( 'numero de cedula',               'guest_id' ),
			array( 'numero de identificacion',       'guest_id' ),
			array( 'documento de identidad',         'guest_id' ),
			array( 'telefono del arrendatario',      'guest_phone' ),
			array( 'celular del arrendatario',       'guest_phone' ),
			array( 'telefono del inquilino',         'guest_phone' ),
			array( 'celular del inquilino',          'guest_phone' ),
			array( 'telefono arrendatario',          'guest_phone' ),
			array( 'celular arrendatario',           'guest_phone' ),
			array( 'correo del arrendatario',        'guest_email' ),
			array( 'correo arrendatario',            'guest_email' ),
			array( 'correo inquilino',               'guest_email' ),
			array( 'email arrendatario',             'guest_email' ),
			array( 'email inquilino',                'guest_email' ),
			// Property.
			array( 'direccion del inmueble',         'accommodation_address' ),
			array( 'direccion de la propiedad',      'accommodation_address' ),
			array( 'direccion del bien inmueble',    'accommodation_address' ),
			array( 'ubicacion del inmueble',         'accommodation_address' ),
			array( 'nombre del inmueble',            'accommodation_title' ),
			array( 'nombre de la propiedad',         'accommodation_title' ),
			array( 'nombre del bien',                'accommodation_title' ),
			// Dates.
			array( 'fecha de inicio del contrato',   'start_date' ),
			array( 'fecha de inicio del arriendo',   'start_date' ),
			array( 'fecha de inicio',                'start_date' ),
			array( 'inicio del contrato',            'start_date' ),
			array( 'fecha inicio',                   'start_date' ),
			array( 'fecha de fin del contrato',      'end_date' ),
			array( 'fecha de terminacion',           'end_date' ),
			array( 'fecha de termino',               'end_date' ),
			array( 'fecha de vencimiento',           'end_date' ),
			array( 'fecha de fin',                   'end_date' ),
			array( 'fin del contrato',               'end_date' ),
			array( 'fecha fin',                      'end_date' ),
			// Rent.
			array( 'canon mensual de arrendamiento', 'monthly_rent' ),
			array( 'valor del canon',                'monthly_rent' ),
			array( 'canon de arrendamiento',         'monthly_rent' ),
			array( 'valor mensual del arriendo',     'monthly_rent' ),
			array( 'valor del arriendo',             'monthly_rent' ),
			array( 'canon mensual',                  'monthly_rent' ),
			array( 'valor mensual',                  'monthly_rent' ),
			array( 'renta mensual',                  'monthly_rent' ),
			array( 'precio mensual',                 'monthly_rent' ),
			// Guarantee.
			array( 'garantia del contrato',          'guarantee_text' ),
			array( 'detalle de garantia',            'guarantee_text' ),
			array( 'tipo de garantia',               'guarantee_text' ),
			array( 'garantia',                       'guarantee_text' ),
			// Contextual phrases used by owner contracts with free-form text and dotted lines.
			array( 'y por otra parte',               'guest_name' ),
			array( 'parte a la que en adelante se le denominara como el arrendatario', 'guest_name' ),
			array( 'representada por',               'guest_name' ),
			array( 'autoriza a',                     'guest_name' ),
			array( 'manifiesta que se somete',       'guest_name' ),
			array( 'pagara por la utilizacion',      'guest_name' ),
			// Generic (least specific - checked last).
			array( 'propietario',                    'owner_name' ),
			array( 'arrendador',                     'owner_name' ),
			array( 'arrendatario',                   'guest_name' ),
			array( 'inquilino',                      'guest_name' ),
			array( 'cedula',                         'guest_id' ),
			array( 'telefono',                       'guest_phone' ),
			array( 'celular',                        'guest_phone' ),
			array( 'direccion',                      'accommodation_address' ),
			array( 'inmueble',                       'accommodation_title' ),
		);

		$map = array();
		foreach ( $patterns as $pair ) {
			$label = strtr(
				mb_strtolower( (string) $pair[0] ),
				array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' )
			);
			$key = (string) $pair[1];
			if ( ! isset( $map[ $label ] ) && isset( $values[ $key ] ) && '' !== $values[ $key ] ) {
				$map[ $label ] = $values[ $key ];
			}
		}

		return $map;
	}

	/**
	 * Builds owner-template fallback text when attachment exists but no readable text was extracted.
	 *
	 * @param array $payload Lease and guest context.
	 * @return string
	 */
	private function build_owner_template_unreadable_fallback_text( array $payload ) {
		$owner_name   = isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : 'Propietario';
		$guest_name   = isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : 'Arrendatario';
		$guest_id     = isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : 'N/D';
		$property     = isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : 'Inmueble';
		$address      = isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '';
		$start_date   = isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '';
		$end_date     = isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '';
		$rent_value   = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '';
		$template_url = isset( $payload['template_url'] ) ? esc_url_raw( (string) $payload['template_url'] ) : '';

		$text  = "PLANTILLA DE CONTRATO DEL OWNER (SIN TEXTO EXTRAIBLE)\n";
		$text .= "\n";
		$text .= "Este contrato se genero usando el documento del owner como fuente obligatoria.\n";
		if ( '' !== $template_url ) {
			$text .= "Referencia de plantilla owner: " . $template_url . "\n";
		}
		$text .= "\n";
		$text .= "Datos para completar en la plantilla:\n";
		$text .= "Arrendador: " . $owner_name . "\n";
		$text .= "Arrendatario: " . $guest_name . " (Cedula: " . $guest_id . ")\n";
		$text .= "Inmueble: " . $property . "\n";
		if ( '' !== $address ) {
			$text .= "Direccion: " . $address . "\n";
		}
		if ( '' !== $start_date || '' !== $end_date ) {
			$text .= "Plazo: " . $start_date . " a " . $end_date . "\n";
		}
		if ( '' !== $rent_value ) {
			$text .= "Canon mensual: USD " . $rent_value . "\n";
		}

		return trim( $text );
	}

	/**
	 * Creates a DOCX fallback file when primary DOC/DOCX generation failed.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $contract_text Contract text.
	 * @return string
	 */
	private function create_last_resort_contract_file( $lease_id, $contract_text ) {
		$lease_id = absint( $lease_id );
		$text     = trim( (string) $contract_text );

		if ( ! $lease_id || '' === $text ) {
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		$contracts_dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/contracts';
		if ( ! wp_mkdir_p( $contracts_dir ) ) {
			return '';
		}

		$file_name = sprintf( 'lease-%d-fallback-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;

		if ( ! $this->write_contract_docx_file( $file_path, $text, array() ) ) {
			return '';
		}

		return esc_url_raw( trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name ) );
	}

	/**
	 * Persists lease document URL with primary and fallback DB update.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $document_url Document URL.
	 * @return void
	 */
	private function force_attach_lease_document( $lease_id, $document_url ) {
		$lease_id     = absint( $lease_id );
		$document_url = esc_url_raw( (string) $document_url );

		if ( ! $lease_id || '' === $document_url ) {
			return;
		}

		$attached = false;
		if ( class_exists( 'Arriendo_Facil_Lease' ) ) {
			$lease_service = new Arriendo_Facil_Lease();
			$attached      = (bool) $lease_service->attach_document( $lease_id, $document_url );
		}

		if ( ! $attached ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'af_leases',
				array( 'document_url' => $document_url ),
				array( 'id' => $lease_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Builds a deterministic fallback contract text when AI is unavailable.
	 *
	 * @param array $payload Lease and guest context.
	 * @return string
	 */
	private function build_fallback_contract_text( array $payload ) {
		return $this->build_legal_contract_template( $payload, '' );
	}

	/**
	 * Provides legal requirements used to guide AI contract drafting.
	 *
	 * @return array<int,string>
	 */
	private function get_contract_legal_requirements() {
		return array(
			'Usa lenguaje juridico formal ecuatoriano apto para revision legal y vigente al 2026.',
			'Fundamenta el contrato en el Codigo Civil del Ecuador Arts. 1857-1948 (Del Arrendamiento), la Ley de Inquilinato y el Codigo Organico General de Procesos (COGEP).',
			'Incluye clausulas numeradas con titulos en mayusculas y obligaciones claras para ambas partes.',
			'Identifica a las partes con nombre completo, numero de cedula/RUC, celular y correo.',
			'Clausula de objeto: descripcion del inmueble (nombre y direccion completa).',
			'Clausula de plazo: fecha de inicio, fecha de fin, condicion de prorroga automatica con aviso de 30 dias.',
			'Clausula de canon: valor mensual en USD, dia maximo de pago (primeros 5 dias del mes), interes de mora del 1% mensual por retraso.',
			'Clausula de garantia: tipo y monto de garantia, plazo de devolucion (15 dias habiles tras verificacion).',
			'Clausula de destino: uso exclusivo habitacional, numero de personas y mascotas, prohibicion de subarriendo.',
			'Clausula de servicios: agua, luz, gas e internet a cargo del arrendatario; predial y administracion a cargo del arrendador salvo pacto.',
			'Clausula de obligaciones del arrendatario: pago puntual, conservacion del inmueble, prohibicion de modificaciones sin autorizacion.',
			'Clausula de obligaciones del arrendador: posesion pacifica, reparaciones estructurales (Art. 1937 CC).',
			'Clausula de terminacion: vencimiento, mutuo acuerdo, incumplimiento, desahucio conforme COGEP, caso fortuito.',
			'Clausula de referencias personales del arrendatario.',
			'Clausula de jurisdiccion: jueces competentes del Ecuador, renuncia a domicilio y fuero especial.',
			'Bloque de firmas con lineas para firma, nombre completo y cedula de arrendador y arrendatario.',
		);
	}

	/**
	 * Builds a legal base template used by chatbot-generated contracts.
	 *
	 * @param array  $payload Lease and guest context.
	 * @param string $extra_clauses Optional extra clauses text.
	 * @return string
	 */
	private function build_legal_contract_template( array $payload, $extra_clauses = '' ) {
		$owner_name      = isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '________________________';
		$owner_id        = isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '________________________';
		$guest_name      = isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '________________________';
		$guest_id        = isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '________________________';
		$guest_phone     = isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '________________________';
		$guest_email     = isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '________________________';
		$property        = isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '________________________';
		$address         = isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '________________________';
		$start_date      = isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '________________________';
		$end_date        = isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '________________________';
		$monthly_rent    = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '0.00';
		$desired_price   = isset( $payload['desired_price'] ) ? sanitize_text_field( (string) $payload['desired_price'] ) : '';
		$guarantee_text  = isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : 'Garantia equivalente a dos (2) meses del canon de arrendamiento.';
		$mascotas        = isset( $payload['mascotas'] ) ? absint( $payload['mascotas'] ) : 0;
		$personas        = isset( $payload['personas_viviran'] ) ? absint( $payload['personas_viviran'] ) : 0;
		$reference_1     = isset( $payload['referencia_personal_1'] ) ? sanitize_text_field( (string) $payload['referencia_personal_1'] ) : '________________________';
		$reference_2     = isset( $payload['referencia_personal_2'] ) ? sanitize_text_field( (string) $payload['referencia_personal_2'] ) : '________________________';

		if ( '' !== trim( $desired_price ) ) {
			$monthly_rent = $desired_price;
		}

		$city_and_date = sprintf( 'Quito, %s', current_time( 'Y-m-d' ) );
		$extra_clauses = trim( (string) $extra_clauses );

		$contract  = "CONTRATO DE ARRENDAMIENTO DE INMUEBLE\n";
		$contract .= "(Conforme al Codigo Civil del Ecuador, Arts. 1857-1948, y la Ley de Inquilinato vigente con sus reformas)\n";
		$contract .= "\n";
		$contract .= $city_and_date . "\n";
		$contract .= "\n";
		$contract .= "COMPARECIENTES\n";
		$contract .= "\n";
		$contract .= "En la ciudad de Quito, Republica del Ecuador, comparecen a la celebracion del presente contrato:\n";
		$contract .= "ARRENDADOR: " . $owner_name . ", con numero de cedula de ciudadania o RUC: " . $owner_id . ", en calidad de propietario o representante autorizado del inmueble que se describe en este instrumento (en adelante \"EL ARRENDADOR\").\n";
		$contract .= "ARRENDATARIO: " . $guest_name . ", con numero de cedula de ciudadania: " . $guest_id . ", celular: " . $guest_phone . ", correo electronico: " . $guest_email . " (en adelante \"EL ARRENDATARIO\").\n";
		$contract .= "\n";
		$contract .= "Las partes, libres y voluntariamente, convienen en celebrar el presente CONTRATO DE ARRENDAMIENTO, sujeto a las siguientes clausulas:\n";
		$contract .= "\n";
		$contract .= "CLAUSULA PRIMERA - OBJETO DEL CONTRATO\n";
		$contract .= "EL ARRENDADOR da en arrendamiento a EL ARRENDATARIO el inmueble denominado \"" . $property . "\", ubicado en " . $address . ", Republica del Ecuador. EL ARRENDATARIO declara conocer el estado actual del inmueble y aceptarlo en las condiciones en que se encuentra, comprometiendose a restituirlo en iguales condiciones al termino del contrato, salvo el deterioro proveniente del uso normal y legitimo.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA SEGUNDA - PLAZO\n";
		$contract .= "El plazo de vigencia del presente contrato es de " . $start_date . " hasta el " . $end_date . ". Vencido el plazo, si ninguna de las partes notifica por escrito su voluntad de terminar el contrato con al menos treinta (30) dias de anticipacion, el contrato se entendera prorrogado automaticamente por periodos iguales, conforme lo dispuesto en el Art. 1885 del Codigo Civil ecuatoriano.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA TERCERA - CANON DE ARRENDAMIENTO Y FORMA DE PAGO\n";
		$contract .= "Las partes acuerdan un canon mensual de arrendamiento de USD " . $monthly_rent . " (dolares de los Estados Unidos de America), pagadero dentro de los primeros cinco (5) dias de cada mes calendario. El pago debera realizarse mediante transferencia bancaria, deposito o el medio que mutuamente convengan las partes por escrito. El retraso en el pago generara un interes de mora del 1% mensual sobre el valor adeudado, conforme lo permite la normativa civil ecuatoriana.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA CUARTA - GARANTIA\n";
		$contract .= "Como garantia del cumplimiento de las obligaciones contractuales, EL ARRENDATARIO entrega: " . $guarantee_text . ". Dicha garantia sera devuelta al termino del contrato, previa verificacion del estado del inmueble y la ausencia de valores pendientes de pago, en un plazo no mayor a quince (15) dias habiles.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA QUINTA - DESTINO Y USO DEL INMUEBLE\n";
		$contract .= "El inmueble objeto de este contrato sera destinado unica y exclusivamente para uso habitacional de EL ARRENDATARIO y su nucleo familiar autorizado, compuesto por " . $personas . " persona(s) y " . $mascotas . " mascota(s) declarada(s). Queda expresamente prohibido subarriendar total o parcialmente el inmueble, ceder este contrato o cambiar el destino del bien sin autorizacion previa y escrita de EL ARRENDADOR.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA SEXTA - SERVICIOS BASICOS Y GASTOS\n";
		$contract .= "Los servicios de energia electrica, agua potable, telefonia, internet y gas domiciliario seran de cargo exclusivo de EL ARRENDATARIO durante la vigencia del contrato. El impuesto predial y los gastos de administracion del inmueble (si aplican) corresponden a EL ARRENDADOR, salvo pacto expreso en contrario.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA SEPTIMA - OBLIGACIONES DE EL ARRENDATARIO\n";
		$contract .= "EL ARRENDATARIO se obliga a: (a) Pagar puntualmente el canon en la forma convenida; (b) Mantener el inmueble en buen estado de conservacion y limpieza; (c) No realizar obras ni modificaciones sin autorizacion escrita de EL ARRENDADOR; (d) Notificar de inmediato cualquier dano o averia que requiera reparacion urgente; (e) Permitir el acceso al inmueble de EL ARRENDADOR o sus representantes para inspeccion, con aviso previo de al menos 24 horas; (f) Cumplir las normas de convivencia del sector y el reglamento de la propiedad horizontal si aplica.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA OCTAVA - OBLIGACIONES DE EL ARRENDADOR\n";
		$contract .= "EL ARRENDADOR se obliga a: (a) Mantener al ARRENDATARIO en el uso pacifico del inmueble durante la vigencia del contrato; (b) Efectuar las reparaciones locativas que le correspondan conforme al Art. 1937 del Codigo Civil; (c) No perturbar la posesion del ARRENDATARIO; (d) Entregar el inmueble en condiciones habitables.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA NOVENA - TERMINACION DEL CONTRATO\n";
		$contract .= "El presente contrato terminara por: (a) Vencimiento del plazo acordado; (b) Mutuo acuerdo de las partes, por escrito; (c) Incumplimiento grave de las obligaciones contractuales o legales por cualquiera de las partes; (d) Desahucio conforme al procedimiento establecido en la Ley de Inquilinato y el Codigo Organico General de Procesos (COGEP); (e) Destruccion o inhabilitacion del inmueble por caso fortuito o fuerza mayor. En caso de desahucio voluntario, EL ARRENDATARIO debera notificar con al menos treinta (30) dias de anticipacion.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA DECIMA - REFERENCIAS PERSONALES DEL ARRENDATARIO\n";
		$contract .= "EL ARRENDATARIO declara como referencias personales: Referencia 1: " . $reference_1 . ". Referencia 2: " . $reference_2 . ".\n";

		if ( '' !== $extra_clauses ) {
			$contract .= "\n";
			$contract .= "CLAUSULA DECIMA PRIMERA - DISPOSICIONES ADICIONALES\n";
			$contract .= $extra_clauses . "\n";
			$next_clause = 'DECIMA SEGUNDA';
		} else {
			$next_clause = 'DECIMA PRIMERA';
		}

		$contract .= "\n";
		$contract .= "CLAUSULA " . $next_clause . " - JURISDICCION, COMPETENCIA Y LEY APLICABLE\n";
		$contract .= "Para todos los efectos legales derivados del presente contrato, las partes se someten expresamente a la jurisdiccion y competencia de los jueces y tribunales de la Republica del Ecuador, con sede en la ciudad pactada, y se regiran por el Codigo Civil (Arts. 1857-1948), la Ley de Inquilinato, el Codigo Organico General de Procesos (COGEP) y las demas normas conexas vigentes en 2026. Las partes renuncian expresamente a domicilio y fuero especial.\n";
		$contract .= "\n";
		$contract .= "En fe de lo cual, las partes suscriben el presente contrato en dos (2) ejemplares de igual tenor y valor legal, en la fecha indicada en el encabezado.\n";
		$contract .= "\n";
		$contract .= "FIRMAS\n";
		$contract .= "\n";
		$contract .= "EL ARRENDADOR:\n";
		$contract .= "Firma: ________________________\n";
		$contract .= "Nombre: " . $owner_name . "\n";
		$contract .= "Cedula/RUC: " . $owner_id . "\n";
		$contract .= "\n";
		$contract .= "EL ARRENDATARIO:\n";
		$contract .= "Firma: ________________________\n";
		$contract .= "Nombre: " . $guest_name . "\n";
		$contract .= "Cedula: " . $guest_id . "\n";

		return $contract;
	}

	/**
	 * Ensures generated contract text keeps legal format requirements.
	 *
	 * @param string $contract_text Raw contract text from AI.
	 * @param array  $payload Lease and guest context.
	 * @return string
	 */
	private function normalize_generated_legal_contract_text( $contract_text, array $payload ) {
		$contract_text = trim( preg_replace( '/\s+\n/', "\n", (string) $contract_text ) );
		if ( '' === $contract_text ) {
			return $this->build_legal_contract_template( $payload, '' );
		}

		$has_owner_template = ! empty( $payload['template_available'] )
			&& isset( $payload['template_text'] )
			&& is_string( $payload['template_text'] )
			&& '' !== trim( $payload['template_text'] );

		$lower_text = strtolower( $contract_text );
		$has_title  = false !== strpos( $lower_text, 'contrato de arrendamiento' );
		$has_clause = false !== strpos( $lower_text, 'clausula' );
		$has_sign   = false !== strpos( $lower_text, 'firma' ) || false !== strpos( $lower_text, 'arrendatario:' );

		if ( $has_owner_template ) {
			if ( ! $has_sign ) {
				$signature_block = "\n\nFIRMAS\n\nARRENDADOR: ________________________\nNombre: "
					. ( isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '________________________' )
					. "\nCedula/RUC: "
					. ( isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '________________________' )
					. "\n\nARRENDATARIO: ________________________\nNombre: "
					. ( isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '________________________' )
					. "\nCedula: "
					. ( isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '________________________' )
					. "\n";

				$contract_text .= $signature_block;
			}

			return $contract_text;
		}

		if ( ! $has_title || ! $has_clause || strlen( $contract_text ) < 700 ) {
			return $this->build_legal_contract_template( $payload, $contract_text );
		}

		if ( ! $has_sign ) {
			$signature_block = "\n\nFIRMAS\n\nARRENDADOR: ________________________\nNombre: "
				. ( isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '________________________' )
				. "\nCedula/RUC: "
				. ( isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '________________________' )
				. "\n\nARRENDATARIO: ________________________\nNombre: "
				. ( isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '________________________' )
				. "\nCedula: "
				. ( isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '________________________' )
				. "\n";

			$contract_text .= $signature_block;
		}

		return $contract_text;
	}

	/**
	 * Fills common owner-template placeholders with lease and guest values.
	 *
	 * @param string $template_text Owner template raw text.
	 * @param array  $payload Lease and guest context.
	 * @return string
	 */
	private function fill_owner_template_with_lease_data( $template_text, array $payload ) {
		$template_text = trim( (string) $template_text );
		if ( '' === $template_text ) {
			return '';
		}

		$field_values = array(
			'owner_name'            => isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '',
			'owner_email'           => isset( $payload['owner_email'] ) ? sanitize_email( (string) $payload['owner_email'] ) : '',
			'owner_id_number'       => isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '',
			'guest_name'            => isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '',
			'guest_email'           => isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '',
			'guest_phone'           => isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '',
			'guest_id_number'       => isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '',
			'accommodation_title'   => isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '',
			'accommodation_address' => isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '',
			'start_date'            => isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '',
			'end_date'              => isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '',
			'monthly_rent'          => isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '',
			'desired_price'         => isset( $payload['desired_price'] ) ? sanitize_text_field( (string) $payload['desired_price'] ) : '',
			'guarantee_text'        => isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : '',
			'current_date'          => current_time( 'Y-m-d' ),
		);

		if ( '' === $field_values['monthly_rent'] && '' !== $field_values['desired_price'] ) {
			$field_values['monthly_rent'] = $field_values['desired_price'];
		}

		$aliases = array(
			'owner_name' => array( 'owner_name', 'owner', 'landlord_name', 'nombre_arrendador', 'arrendador_nombre', 'propietario_nombre', 'nombre_propietario' ),
			'owner_email' => array( 'owner_email', 'landlord_email', 'correo_arrendador', 'email_arrendador', 'correo_propietario', 'email_propietario' ),
			'owner_id_number' => array( 'owner_id', 'owner_id_number', 'landlord_id', 'cedula_arrendador', 'ruc_arrendador', 'cedula_propietario', 'id_propietario' ),
			'guest_name' => array( 'guest_name', 'tenant_name', 'nombre_arrendatario', 'arrendatario_nombre', 'inquilino_nombre', 'nombre_inquilino' ),
			'guest_email' => array( 'guest_email', 'tenant_email', 'correo_arrendatario', 'email_arrendatario', 'correo_inquilino', 'email_inquilino' ),
			'guest_phone' => array( 'guest_phone', 'tenant_phone', 'telefono_arrendatario', 'celular_arrendatario', 'telefono_inquilino', 'celular_inquilino' ),
			'guest_id_number' => array( 'guest_id', 'guest_id_number', 'tenant_id', 'cedula_arrendatario', 'id_arrendatario', 'cedula_inquilino', 'id_inquilino' ),
			'accommodation_title' => array( 'property_name', 'accommodation_title', 'nombre_inmueble', 'inmueble', 'propiedad', 'nombre_propiedad' ),
			'accommodation_address' => array( 'property_address', 'accommodation_address', 'direccion_inmueble', 'direccion_propiedad', 'direccion' ),
			'start_date' => array( 'start_date', 'lease_start', 'fecha_inicio', 'fecha_inicio_arriendo', 'inicio_contrato' ),
			'end_date' => array( 'end_date', 'lease_end', 'fecha_fin', 'fecha_fin_arriendo', 'fin_contrato' ),
			'monthly_rent' => array( 'monthly_rent', 'rent', 'canon', 'canon_mensual', 'valor_arriendo', 'precio_mensual' ),
			'guarantee_text' => array( 'guarantee', 'guarantee_text', 'garantia', 'detalle_garantia' ),
			'current_date' => array( 'current_date', 'fecha_actual', 'fecha_hoy' ),
		);

		$token_map = array();
		foreach ( $aliases as $field_key => $tokens ) {
			$value = isset( $field_values[ $field_key ] ) ? (string) $field_values[ $field_key ] : '';
			if ( '' === $value ) {
				continue;
			}

			foreach ( $tokens as $token ) {
				$normalized = strtolower( preg_replace( '/[^a-z0-9]/', '', (string) $token ) );
				if ( '' !== $normalized ) {
					$token_map[ $normalized ] = $value;
				}
			}
		}

		$filled = preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_\-\s]+)\s*\}\}|\[\[\s*([a-zA-Z0-9_\-\s]+)\s*\]\]|<<\s*([a-zA-Z0-9_\-\s]+)\s*>>/',
			static function ( $matches ) use ( $token_map ) {
				$raw = '';
				if ( ! empty( $matches[1] ) ) {
					$raw = (string) $matches[1];
				} elseif ( ! empty( $matches[2] ) ) {
					$raw = (string) $matches[2];
				} elseif ( ! empty( $matches[3] ) ) {
					$raw = (string) $matches[3];
				}

				$key = strtolower( preg_replace( '/[^a-z0-9]/', '', $raw ) );
				if ( '' !== $key && isset( $token_map[ $key ] ) ) {
					return $token_map[ $key ];
				}

				return $matches[0];
			},
			$template_text
		);

		return trim( (string) $filled );
	}

	/**
	 * Finds the latest contract example uploaded by the accommodation owner.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return array<string,mixed>
	 */
	private function get_owner_contract_example_context( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		$owner_user_id = $this->resolve_accommodation_owner_user_id( $accommodation_id );

		if ( ! $owner_user_id ) {
			error_log( 'Arriendo Facil owner-template lookup: accommodation has no resolved owner. accommodation_id=' . $accommodation_id );
			return array();
		}

		$attachment_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_af_owner_contract_example',
						'value' => '1',
					),
					array(
						'key'   => '_af_owner_user_id',
						'value' => (string) $owner_user_id,
					),
				),
			)
		);

		$attachment_id = ! empty( $attachment_ids ) ? absint( $attachment_ids[0] ) : 0;

		if ( ! $attachment_id ) {
			$attachment_ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'   => '_af_sensitive_doc_type',
							'value' => 'contract_example',
						),
						array(
							'key'   => '_af_owner_user_id',
							'value' => (string) $owner_user_id,
						),
					),
				)
			);
			$attachment_id = ! empty( $attachment_ids ) ? absint( $attachment_ids[0] ) : 0;

			if ( $attachment_id ) {
				update_post_meta( $attachment_id, '_af_owner_contract_example', '1' );
			}
		}

		if ( ! $attachment_id ) {
			error_log( 'Arriendo Facil owner-template lookup: no owner contract attachment found. accommodation_id=' . $accommodation_id . ', owner_user_id=' . $owner_user_id );
			return array();
		}

		return $this->build_contract_template_context_from_attachment( $attachment_id, $owner_user_id );
	}

	/**
	 * Resolves owner user ID for an accommodation with safe fallbacks.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return int
	 */
	private function resolve_accommodation_owner_user_id( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id ) {
			return 0;
		}

		$owner_user_id = absint( get_post_meta( $accommodation_id, '_af_owner_id', true ) );
		if ( $owner_user_id > 0 ) {
			return $owner_user_id;
		}

		$legacy_owner_user_id = absint( get_post_meta( $accommodation_id, '_af_owner_user_id', true ) );
		if ( $legacy_owner_user_id > 0 ) {
			return $legacy_owner_user_id;
		}

		$post = get_post( $accommodation_id );
		if ( $post && ! empty( $post->post_author ) ) {
			$post_author_id = absint( $post->post_author );
			if ( $post_author_id > 0 ) {
				$author = get_user_by( 'id', $post_author_id );
				if ( $author && in_array( 'af_owner', (array) $author->roles, true ) ) {
					return $post_author_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Returns owner identification number from owner contacts table.
	 *
	 * @param int $owner_user_id Owner user ID.
	 * @return string
	 */
	private function get_owner_identification_number( $owner_user_id ) {
		$owner_user_id = absint( $owner_user_id );
		if ( ! $owner_user_id ) {
			return '';
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'af_owner_contacts';
		$owner_id   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT owner_id FROM {$table_name} WHERE wp_user_id = %d ORDER BY id DESC LIMIT 1",
				$owner_user_id
			)
		);

		return sanitize_text_field( (string) $owner_id );
	}

	/**
	 * Builds standardized contract template context from an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $fallback_owner_user_id Owner user fallback ID.
	 * @return array<string,mixed>
	 */
	private function build_contract_template_context_from_attachment( $attachment_id, $fallback_owner_user_id = 0 ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array();
		}

		$owner_user_id = absint( get_post_meta( $attachment_id, '_af_owner_user_id', true ) );
		if ( ! $owner_user_id ) {
			$owner_user_id = absint( $fallback_owner_user_id );
		}

		$path          = get_attached_file( $attachment_id );
		$mime_type     = (string) get_post_mime_type( $attachment_id );
		$cache_signature = '';
		if ( $path && file_exists( $path ) ) {
			$file_size = @filesize( $path );
			$file_mtime = @filemtime( $path );
			$cache_signature = sha1( $path . '|' . (string) $file_size . '|' . (string) $file_mtime . '|' . $mime_type );
		}

		$cached_signature = (string) get_post_meta( $attachment_id, '_af_template_text_cache_sig', true );
		$cached_text      = get_post_meta( $attachment_id, '_af_template_text_cache', true );
		$cached_text      = is_string( $cached_text ) ? $cached_text : '';

		if ( '' !== $cache_signature && '' !== $cached_text && hash_equals( $cached_signature, $cache_signature ) ) {
			$template_text = $cached_text;
		} else {
			$template_text = $this->extract_contract_template_text( $path, $mime_type );
			if ( '' === $template_text && '' !== $cached_text ) {
				$template_text = $cached_text;
			}

			if ( '' !== $cache_signature ) {
				update_post_meta( $attachment_id, '_af_template_text_cache_sig', $cache_signature );
				update_post_meta( $attachment_id, '_af_template_text_cache', $template_text );
			}
		}
		$owner_user    = get_user_by( 'id', $owner_user_id );

		return array(
			'attachment_id' => $attachment_id,
			'owner_user_id' => $owner_user_id,
			'owner_name'    => $owner_user ? (string) $owner_user->display_name : '',
			'owner_email'   => $owner_user ? (string) $owner_user->user_email : '',
			'file_name'     => $path ? wp_basename( $path ) : '',
			'mime_type'     => $mime_type,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'template_text' => $template_text,
		);
	}

	/**
	 * Sends processing-notification email to tenant after chatbot registration.
	 *
	 * @param string $tenant_email Tenant email.
	 * @param string $tenant_name Tenant full name.
	 * @param int    $accommodation_id Accommodation ID.
	 * @param int    $lease_id Lease ID.
	 * @return void
	 */
	private function send_tenant_processing_email( $tenant_email, $tenant_name, $accommodation_id, $lease_id ) {
		$tenant_email = sanitize_email( (string) $tenant_email );
		if ( ! is_email( $tenant_email ) ) {
			return;
		}

		$tenant_name = sanitize_text_field( (string) $tenant_name );
		$tenant_name = '' !== trim( $tenant_name ) ? $tenant_name : __( 'arrendatario', 'arriendo-facil' );

		$accommodation_title = (string) get_the_title( absint( $accommodation_id ) );
		if ( '' === trim( $accommodation_title ) ) {
			$accommodation_title = __( 'la propiedad solicitada', 'arriendo-facil' );
		}

		$subject = __( 'Estamos procesando tu solicitud de arriendo', 'arriendo-facil' );
		$message = sprintf(
			/* translators: 1: tenant name, 2: accommodation title, 3: lease ID */
			__( "Hola %1$s,\n\nRecibimos tu solicitud de arriendo para %2$s.\n\nTu contrato se encuentra en procesamiento y nuestro equipo revisara la informacion pronto.\n\nNumero de solicitud: %3$d\n\nGracias por usar Arriendo Facil.", 'arriendo-facil' ),
			$tenant_name,
			$accommodation_title,
			absint( $lease_id )
		);

		wp_mail( $tenant_email, $subject, $message );
	}

	/**
	 * Extracts plain text from a contract template file when possible.
	 *
	 * @param string $file_path Template file path.
	 * @param string $mime_type Mime type.
	 * @return string
	 */
	private function extract_contract_template_text( $file_path, $mime_type ) {
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return '';
		}

		$mime_type = strtolower( (string) $mime_type );
		$extension = strtolower( (string) pathinfo( (string) $file_path, PATHINFO_EXTENSION ) );

		if ( false !== strpos( $mime_type, 'text/' ) ) {
			$content = file_get_contents( $file_path );
			if ( false !== $content ) {
				return $this->limit_template_text( wp_strip_all_tags( (string) $content ) );
			}

			return '';
		}

		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime_type || 'docx' === $extension ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( true === $zip->open( $file_path ) ) {
					$xml = $zip->getFromName( 'word/document.xml' );
					$zip->close();

					if ( false !== $xml && '' !== $xml ) {
						$text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $xml ) );
						return $this->limit_template_text( (string) $text );
					}
				}
			}

			return '';
		}

		if ( false !== strpos( $mime_type, 'application/pdf' ) || 'pdf' === $extension ) {
			return $this->limit_template_text( $this->extract_text_from_pdf_file( $file_path ) );
		}

		if ( false !== strpos( $mime_type, 'application/msword' ) || 'doc' === $extension ) {
			return $this->limit_template_text( $this->extract_text_from_legacy_doc_file( $file_path ) );
		}

		$content = file_get_contents( $file_path );
		if ( false !== $content ) {
			$fallback_text = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', (string) $content );
			return $this->limit_template_text( (string) $fallback_text );
		}

		return '';
	}

	/**
	 * Extracts readable text from a PDF file using basic stream parsing.
	 *
	 * @param string $file_path PDF path.
	 * @return string
	 */
	private function extract_text_from_pdf_file( $file_path ) {
		$content = file_get_contents( $file_path );
		if ( false === $content || '' === $content ) {
			return '';
		}

		$buffers = array( (string) $content );
		if ( preg_match_all( '/stream(.*?)endstream/s', (string) $content, $stream_matches ) ) {
			foreach ( $stream_matches[1] as $stream ) {
				$stream = ltrim( (string) $stream, "\r\n" );
				$stream = rtrim( $stream, "\r\n" );

				$decoded = @gzuncompress( $stream );
				if ( false === $decoded ) {
					$decoded = @gzinflate( $stream );
				}

				$buffers[] = ( false !== $decoded && '' !== $decoded ) ? (string) $decoded : $stream;
			}
		}

		$text_chunks = array();
		foreach ( $buffers as $buffer ) {
			if ( preg_match_all( '/\((.*?)\)\s*Tj/s', (string) $buffer, $matches ) ) {
				foreach ( $matches[1] as $token ) {
					$decoded_token = $this->decode_pdf_text_token( (string) $token );
					if ( '' !== $decoded_token ) {
						$text_chunks[] = $decoded_token;
					}
				}
			}

			if ( preg_match_all( '/\[(.*?)\]\s*TJ/s', (string) $buffer, $matches ) ) {
				foreach ( $matches[1] as $array_body ) {
					if ( preg_match_all( '/\((.*?)\)/s', (string) $array_body, $token_matches ) ) {
						foreach ( $token_matches[1] as $token ) {
							$decoded_token = $this->decode_pdf_text_token( (string) $token );
							if ( '' !== $decoded_token ) {
								$text_chunks[] = $decoded_token;
							}
						}
					}
				}
			}
		}

		if ( empty( $text_chunks ) ) {
			return '';
		}

		return trim( preg_replace( '/\s+/', ' ', implode( ' ', $text_chunks ) ) );
	}

	/**
	 * Decodes escaped PDF text token content.
	 *
	 * @param string $token Token text.
	 * @return string
	 */
	private function decode_pdf_text_token( $token ) {
		$token = preg_replace_callback(
			'/\\\\([0-7]{1,3})/',
			static function ( $matches ) {
				return chr( octdec( $matches[1] ) );
			},
			(string) $token
		);

		$token = strtr(
			(string) $token,
			array(
				'\\n'   => "\n",
				'\\r'   => "\r",
				'\\t'   => "\t",
				'\\b'   => '',
				'\\f'   => '',
				'\\('   => '(',
				'\\)'   => ')',
				'\\\\' => '\\',
			)
		);

		$token = wp_strip_all_tags( $token );
		$token = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', (string) $token );

		return trim( (string) $token );
	}

	/**
	 * Extracts rough text from legacy .doc binary file.
	 *
	 * @param string $file_path DOC path.
	 * @return string
	 */
	private function extract_text_from_legacy_doc_file( $file_path ) {
		$content = file_get_contents( $file_path );
		if ( false === $content || '' === $content ) {
			return '';
		}

		$text = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', (string) $content );
		$text = preg_replace( '/\s+/', ' ', (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Limits template text length sent to AI.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function limit_template_text( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

		if ( '' === $text ) {
			return '';
		}

		if ( strlen( $text ) > 8000 ) {
			return substr( $text, 0, 8000 );
		}

		return $text;
	}

	/**
	 * Creates a generated contract DOCX file and returns a secure document URL.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $contract_text Contract body.
	 * @param array  $payload Lease and guest context used for formatting.
	 * @return string
	 */
	private function create_generated_contract_file( $lease_id, $contract_text, array $payload = array() ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id || '' === trim( $contract_text ) ) {
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		$contracts_dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/contracts';
		if ( ! wp_mkdir_p( $contracts_dir ) ) {
			return '';
		}

		$file_name = sprintf( 'lease-%d-contract-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;
		$mime_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

		if ( ! $this->write_contract_docx_file( $file_path, $contract_text, $payload ) ) {
			$file_name = sprintf( 'lease-%d-contract-%s.doc', $lease_id, gmdate( 'Ymd-His' ) );
			$file_path = trailingslashit( $contracts_dir ) . $file_name;
			$mime_type = 'application/msword';

			if ( ! $this->write_contract_doc_fallback_file( $file_path, $contract_text ) ) {
				return '';
			}
		}

		$local_url     = trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name );
		$document_url  = $local_url;
		$storage_meta  = array(
			'provider'  => 'local',
			'file_name' => $file_name,
			'local_url' => $local_url,
			'mime_type' => $mime_type,
		);

		$storage_provider = $this->get_storage_setting( 'AF_STORAGE_PROVIDER', 'af_storage_provider', 'cloudflare_r2' );
		if ( 'cloudflare_r2' === $storage_provider ) {
			$r2_config = $this->get_r2_config();
			if ( ! is_wp_error( $r2_config ) ) {
				$contents = file_get_contents( $file_path );
				if ( false !== $contents ) {
					$object_key = sprintf( 'lease-contracts/%d/%s', $lease_id, sanitize_file_name( $file_name ) );
					$upload     = $this->upload_contents_to_r2(
						$contents,
						$object_key,
						$mime_type,
						$r2_config
					);

					if ( ! is_wp_error( $upload ) ) {
						$document_url = add_query_arg(
							array(
								'action'   => 'af_download_lease_contract',
								'lease_id' => $lease_id,
							),
							admin_url( 'admin-ajax.php' )
						);
						$storage_meta = array(
							'provider'   => 'cloudflare_r2',
							'object_key' => $object_key,
							'file_name'  => $file_name,
							'local_url'  => $local_url,
							'mime_type'  => $mime_type,
						);
					}
				}
			}
		}

		if ( class_exists( 'Arriendo_Facil_Lease' ) ) {
			$lease_service = new Arriendo_Facil_Lease();
			$lease_service->set_contract_storage_meta( $lease_id, $storage_meta );
		}

		return $document_url;
	}

	/**
	 * Writes a fallback MS Word-compatible HTML document when DOCX is unavailable.
	 *
	 * @param string $file_path Destination file path.
	 * @param string $contract_text Contract text.
	 * @return bool
	 */
	private function write_contract_doc_fallback_file( $file_path, $contract_text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $contract_text );
		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $contract_text );
		}

		$body = '';
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				$body .= '<p>&nbsp;</p>';
				continue;
			}

			$body .= '<p>' . esc_html( $line ) . '</p>';
		}

		if ( '' === $body ) {
			$body = '<p>Contrato</p>';
		}

		$html = '<html><head><meta charset="UTF-8"></head><body style="font-family:Times New Roman, serif; font-size:12pt;">' . $body . '</body></html>';

		return false !== file_put_contents( $file_path, $html );
	}

	/**
	 * Writes a minimal DOCX file from plain contract text.
	 *
	 * @param string $file_path Destination file path.
	 * @param string $contract_text Contract text.
	 * @param array  $payload Lease and guest context for visual formatting.
	 * @return bool
	 */
	private function write_contract_docx_file( $file_path, $contract_text, array $payload = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$paragraphs = $this->build_contract_docx_paragraphs( $contract_text, $payload );
		if ( empty( $paragraphs ) ) {
			$paragraphs = array(
				array(
					'text'  => 'Contrato',
					'bold'  => false,
					'align' => 'left',
				),
			);
		}

		$doc_paragraphs_xml = '';
		foreach ( $paragraphs as $paragraph ) {
			$text  = isset( $paragraph['text'] ) ? (string) $paragraph['text'] : '';
			$bold  = ! empty( $paragraph['bold'] );
			$align = isset( $paragraph['align'] ) ? (string) $paragraph['align'] : 'both';
			$tab_stops = array();

			if ( isset( $paragraph['tab_stops'] ) && is_array( $paragraph['tab_stops'] ) ) {
				$tab_stops = $paragraph['tab_stops'];
			}

			if ( '' === $text ) {
				$doc_paragraphs_xml .= '<w:p/>';
				continue;
			}

			$paragraph_properties = '';
			if ( in_array( $align, array( 'left', 'center', 'right', 'both' ), true ) ) {
				$paragraph_properties .= '<w:jc w:val="' . esc_attr( $align ) . '"/>';
			}

			if ( ! empty( $tab_stops ) ) {
				$paragraph_properties .= '<w:tabs>';
				foreach ( $tab_stops as $tab_stop ) {
					$position = absint( $tab_stop );
					if ( $position > 0 ) {
						$paragraph_properties .= '<w:tab w:val="left" w:pos="' . $position . '"/>';
					}
				}
				$paragraph_properties .= '</w:tabs>';
			}

			$run_properties = '';
			if ( $bold ) {
				$run_properties = '<w:rPr><w:b/><w:bCs/></w:rPr>';
			}

			$doc_paragraphs_xml .= '<w:p>';
			if ( '' !== $paragraph_properties ) {
				$doc_paragraphs_xml .= '<w:pPr>' . $paragraph_properties . '</w:pPr>';
			}
			$doc_paragraphs_xml .= $this->build_docx_text_runs_xml( $text, $run_properties );
			$doc_paragraphs_xml .= '</w:p>';
		}

		$document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:w10="urn:schemas-microsoft-com:office:word" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml" xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup" xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk" xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml" xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" mc:Ignorable="w14 w15 wp14">'
			. '<w:body>' . $doc_paragraphs_xml . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/><w:cols w:space="720"/></w:sectPr></w:body></w:document>';

		$content_types_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '</Types>';

		$styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:styles xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" mc:Ignorable="">'
			. '<w:docDefaults>'
			. '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:rPrDefault>'
			. '<w:pPrDefault><w:pPr><w:spacing w:before="0" w:after="160" w:line="360" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr></w:pPrDefault>'
			. '</w:docDefaults>'
			. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
			. '</w:styles>';

		$rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			. '</Relationships>';

		$doc_rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';

		$zip->addFromString( '[Content_Types].xml', $content_types_xml );
		$zip->addFromString( '_rels/.rels', $rels_xml );
		$zip->addFromString( 'word/document.xml', $document_xml );
		$zip->addFromString( 'word/styles.xml', $styles_xml );
		$zip->addFromString( 'word/_rels/document.xml.rels', $doc_rels_xml );

		return $zip->close();
	}

	/**
	 * Builds DOCX paragraphs using strict contract format rules.
	 *
	 * @param string $contract_text Contract text.
	 * @param array  $payload Lease and guest context.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_contract_docx_paragraphs( $contract_text, array $payload = array() ) {
		$paragraphs = array();
		$lines      = preg_split( '/\r\n|\r|\n/', (string) $contract_text );

		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $contract_text );
		}

		$has_title = false;
		$last_was_empty = false;
		$title_inserted = false;

		foreach ( $lines as $raw_line ) {
			$line = trim( (string) $raw_line );

			if ( '' === $line ) {
				if ( $last_was_empty ) {
					continue;
				}
				$paragraphs[] = array(
					'text'  => '',
					'bold'  => false,
					'align' => 'both',
				);
				$last_was_empty = true;
				continue;
			}

			$upper_line = strtoupper( $line );
			$is_title   = false !== strpos( $upper_line, 'CONTRATO DE ARRENDAMIENTO' );
			$is_clause  = 0 === strpos( $upper_line, 'CLAUSULA ' );

			if ( 0 === strpos( $line, 'Quito,' ) ) {
				continue;
			}

			if ( $this->is_contract_signature_line( $line ) ) {
				continue;
			}

			if ( ! $has_title && $is_title ) {
				$paragraphs[] = array(
					'text'  => 'CONTRATO DE ARRENDAMIENTO',
					'bold'  => true,
					'align' => 'center',
				);
				$paragraphs[] = array(
					'text'  => $this->format_contract_date_line( '' ),
					'bold'  => false,
					'align' => 'right',
				);
				$has_title      = true;
				$title_inserted = true;
				$last_was_empty = false;
				continue;
			}

			$paragraphs[] = array(
				'text'  => $line,
				'bold'  => $is_clause,
				'align' => $is_clause ? 'left' : 'both',
			);
			$last_was_empty = false;
		}

		if ( ! $has_title || ! $title_inserted ) {
			array_unshift(
				$paragraphs,
				array(
					'text'  => 'CONTRATO DE ARRENDAMIENTO',
					'bold'  => true,
					'align' => 'center',
				),
				array(
					'text'  => $this->format_contract_date_line( '' ),
					'bold'  => false,
					'align' => 'right',
				)
			);
		}

		$paragraphs = array_merge( $paragraphs, $this->build_contract_signature_paragraphs( $payload ) );

		return $paragraphs;
	}

	/**
	 * Builds WordprocessingML run XML, preserving tab stops in content.
	 *
	 * @param string $text Paragraph text.
	 * @param string $run_properties Run properties XML.
	 * @return string
	 */
	private function build_docx_text_runs_xml( $text, $run_properties = '' ) {
		$parts = explode( "\t", (string) $text );
		if ( 1 === count( $parts ) ) {
			return '<w:r>' . $run_properties . '<w:t xml:space="preserve">' . esc_xml( $text ) . '</w:t></w:r>';
		}

		$xml = '';
		foreach ( $parts as $index => $part ) {
			$xml .= '<w:r>' . $run_properties . '<w:t xml:space="preserve">' . esc_xml( $part ) . '</w:t></w:r>';
			if ( $index < count( $parts ) - 1 ) {
				$xml .= '<w:r>' . $run_properties . '<w:tab/></w:r>';
			}
		}

		return $xml;
	}

	/**
	 * Detects whether a line belongs to the signature section.
	 *
	 * @param string $line Contract line.
	 * @return bool
	 */
	private function is_contract_signature_line( $line ) {
		$clean = strtoupper( trim( (string) $line ) );

		if ( '' === $clean ) {
			return false;
		}

		if ( in_array( $clean, array( 'FIRMAS', 'ARRENDADOR', 'ARRENDATARIO', 'EL ARRENDADOR', 'EL ARRENDATARIO' ), true ) ) {
			return true;
		}

		if ( 0 === strpos( $clean, 'ARRENDADOR:' ) || 0 === strpos( $clean, 'ARRENDATARIO:' ) ) {
			return true;
		}

		if ( 0 === strpos( $clean, 'EL ARRENDADOR' ) || 0 === strpos( $clean, 'EL ARRENDATARIO' ) ) {
			return true;
		}

		if ( 0 === strpos( $clean, 'FIRMA:' ) || 0 === strpos( $clean, 'NOMBRE:' ) ) {
			return true;
		}

		if ( 0 === strpos( $clean, 'CEDULA:' ) || 0 === strpos( $clean, 'CÉDULA:' ) || 0 === strpos( $clean, 'CEDULA/RUC:' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Builds a fixed signature block with two sections.
	 *
	 * @param array $payload Lease and guest context.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_contract_signature_paragraphs( array $payload ) {
		$owner_name = isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '________________________';
		$owner_id   = isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '________________________';
		$guest_name = isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '________________________';
		$guest_id   = isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '________________________';

		return array(
			array(
				'text'  => '',
				'bold'  => false,
				'align' => 'both',
			),
			array(
				'text'  => 'FIRMAS',
				'bold'  => true,
				'align' => 'left',
			),
			array(
				'text'  => '',
				'bold'  => false,
				'align' => 'both',
			),
			array(
				'text'  => 'ARRENDADOR\tARRENDATARIO',
				'bold'  => true,
				'align' => 'left',
				'tab_stops' => array( 6400 ),
			),
			array(
				'text'  => 'Firma: ________________________\tFirma: ________________________',
				'bold'  => false,
				'align' => 'left',
				'tab_stops' => array( 6400 ),
			),
			array(
				'text'  => 'Nombre: ' . $owner_name . "\t" . 'Nombre: ' . $guest_name,
				'bold'  => false,
				'align' => 'left',
				'tab_stops' => array( 6400 ),
			),
			array(
				'text'  => 'Cedula/RUC: ' . $owner_id . "\t" . 'Cedula: ' . $guest_id,
				'bold'  => false,
				'align' => 'left',
				'tab_stops' => array( 6400 ),
			),
		);
	}

	/**
	 * Formats contract date line as "Quito, d de mes de Y".
	 *
	 * @param string $line Raw date line.
	 * @return string
	 */
	private function format_contract_date_line( $line ) {
		$timestamp = current_time( 'timestamp' );

		$months = array(
			1  => 'enero',
			2  => 'febrero',
			3  => 'marzo',
			4  => 'abril',
			5  => 'mayo',
			6  => 'junio',
			7  => 'julio',
			8  => 'agosto',
			9  => 'septiembre',
			10 => 'octubre',
			11 => 'noviembre',
			12 => 'diciembre',
		);

		$day        = (int) gmdate( 'j', $timestamp );
		$month_idx  = (int) gmdate( 'n', $timestamp );
		$year       = (string) gmdate( 'Y', $timestamp );
		$month_name = isset( $months[ $month_idx ] ) ? $months[ $month_idx ] : gmdate( 'F', $timestamp );

		return sprintf( 'Quito, %d de %s de %s', $day, $month_name, $year );
	}

	/**
	 * Downloads the owner contract template DOCX from R2 to a local temp file.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|false Local temp file path or false on failure.
	 */
	private function download_owner_template_from_r2( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		$object_key = (string) get_post_meta( $attachment_id, '_af_r2_object_key', true );
		if ( '' === trim( $object_key ) ) {
			return false;
		}

		$r2_config = $this->get_r2_config();
		if ( is_wp_error( $r2_config ) ) {
			return false;
		}

		$object_key   = ltrim( $object_key, '/' );
		$amz_date     = gmdate( 'Ymd\\THis\\Z' );
		$date_stamp   = gmdate( 'Ymd' );
		$scope        = $date_stamp . '/' . $r2_config['region'] . '/' . $r2_config['service'] . '/aws4_request';
		$canonical_uri = '/' . rawurlencode( $r2_config['bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );

		$canonical_headers =
			'host:' . $r2_config['host'] . "\n"
			. 'x-amz-content-sha256:UNSIGNED-PAYLOAD' . "\n"
			. 'x-amz-date:' . $amz_date . "\n";
		$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request =
			"GET\n"
			. $canonical_uri . "\n"
			. "\n"
			. $canonical_headers . "\n"
			. $signed_headers . "\n"
			. 'UNSIGNED-PAYLOAD';

		$string_to_sign =
			'AWS4-HMAC-SHA256' . "\n"
			. $amz_date . "\n"
			. $scope . "\n"
			. hash( 'sha256', $canonical_request );

		$signing_key = $this->get_aws_v4_signing_key( $r2_config['secret_key'], $date_stamp, $r2_config['region'], $r2_config['service'] );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$authorization =
			'AWS4-HMAC-SHA256 '
			. 'Credential=' . $r2_config['access_key'] . '/' . $scope . ', '
			. 'SignedHeaders=' . $signed_headers . ', '
			. 'Signature=' . $signature;

		$url = $r2_config['endpoint'] . $canonical_uri;

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Host'                 => $r2_config['host'],
					'x-amz-date'           => $amz_date,
					'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
					'Authorization'        => $authorization,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'Arriendo Facil R2 template download error: ' . $response->get_error_message() );
			return false;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			error_log( 'Arriendo Facil R2 template download HTTP ' . $status_code );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return false;
		}

		$tmp_file = wp_tempnam( 'af_owner_template_' . $attachment_id . '.docx' );
		if ( ! $tmp_file ) {
			return false;
		}

		if ( false === file_put_contents( $tmp_file, $body ) ) {
			@unlink( $tmp_file );
			return false;
		}

		return $tmp_file;
	}

	/**
	 * Reads storage setting with constant priority.
	 *
	 * @param string $constant_name Constant name.
	 * @param string $option_name Option name.
	 * @param string $default Default value.
	 * @return string
	 */
	private function get_storage_setting( $constant_name, $option_name, $default = '' ) {
		if ( defined( $constant_name ) ) {
			$value = constant( $constant_name );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return trim( (string) get_option( $option_name, $default ) );
	}

	/**
	 * Loads and validates Cloudflare R2 credentials.
	 *
	 * @return array|WP_Error
	 */
	private function get_r2_config() {
		$access_key = $this->get_storage_setting( 'AF_R2_ACCESS_KEY_ID', 'af_r2_access_key_id', '' );
		$secret_key = $this->get_storage_setting( 'AF_R2_SECRET_ACCESS_KEY', 'af_r2_secret_access_key', '' );
		$endpoint   = untrailingslashit( $this->get_storage_setting( 'AF_R2_ENDPOINT_URL', 'af_r2_endpoint_url', '' ) );
		$bucket     = $this->get_storage_setting( 'AF_R2_BUCKET_NAME', 'af_r2_bucket_name', '' );

		if ( '' === $access_key || '' === $secret_key || '' === $endpoint || '' === $bucket ) {
			return new WP_Error( 'af_r2_missing_config', __( 'Faltan credenciales de Cloudflare R2. Revisa Ajustes > Proveedor en la nube.', 'arriendo-facil' ) );
		}

		$parsed = wp_parse_url( $endpoint );
		$host   = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		$scheme = isset( $parsed['scheme'] ) ? (string) $parsed['scheme'] : '';

		if ( '' === $host || '' === $scheme ) {
			return new WP_Error( 'af_r2_invalid_endpoint', __( 'URL de endpoint de Cloudflare R2 invalida.', 'arriendo-facil' ) );
		}

		return array(
			'access_key' => $access_key,
			'secret_key' => $secret_key,
			'endpoint'   => $scheme . '://' . $host,
			'host'       => $host,
			'bucket'     => $bucket,
			'region'     => 'auto',
			'service'    => 's3',
		);
	}

	/**
	 * Uploads raw contents to Cloudflare R2 using SigV4.
	 *
	 * @param string $contents File contents.
	 * @param string $object_key Object key path.
	 * @param string $mime_type Mime type.
	 * @param array  $r2_config Parsed R2 config.
	 * @return true|WP_Error
	 */
	private function upload_contents_to_r2( $contents, $object_key, $mime_type, array $r2_config ) {
		$payload_hash   = hash( 'sha256', $contents );
		$amz_date       = gmdate( 'Ymd\\THis\\Z' );
		$date_stamp     = gmdate( 'Ymd' );
		$canonical_uri  = '/' . rawurlencode( $r2_config['bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( (string) $object_key ) );

		$canonical_headers =
			'host:' . $r2_config['host'] . "\n"
			. 'x-amz-content-sha256:' . $payload_hash . "\n"
			. 'x-amz-date:' . $amz_date . "\n";
		$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request =
			"PUT\n"
			. $canonical_uri . "\n"
			. "\n"
			. $canonical_headers . "\n"
			. $signed_headers . "\n"
			. $payload_hash;

		$credential_scope = $date_stamp . '/' . $r2_config['region'] . '/' . $r2_config['service'] . '/aws4_request';
		$string_to_sign   =
			'AWS4-HMAC-SHA256' . "\n"
			. $amz_date . "\n"
			. $credential_scope . "\n"
			. hash( 'sha256', $canonical_request );

		$signing_key = $this->get_aws_v4_signing_key( $r2_config['secret_key'], $date_stamp, $r2_config['region'], $r2_config['service'] );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$authorization =
			'AWS4-HMAC-SHA256 '
			. 'Credential=' . $r2_config['access_key'] . '/' . $credential_scope . ', '
			. 'SignedHeaders=' . $signed_headers . ', '
			. 'Signature=' . $signature;

		$response = wp_remote_request(
			$r2_config['endpoint'] . $canonical_uri,
			array(
				'method'  => 'PUT',
				'timeout' => 45,
				'headers' => array(
					'Host'                 => $r2_config['host'],
					'Content-Type'         => $mime_type,
					'x-amz-date'           => $amz_date,
					'x-amz-content-sha256' => $payload_hash,
					'Authorization'        => $authorization,
				),
				'body' => $contents,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error( 'af_r2_upload_failed', __( 'Cloudflare R2 rejected the generated contract file.', 'arriendo-facil' ) );
		}

		return true;
	}

	/**
	 * Builds AWS Signature V4 signing key.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $date_stamp Date stamp.
	 * @param string $region Region.
	 * @param string $service Service.
	 * @return string
	 */
	private function get_aws_v4_signing_key( $secret_key, $date_stamp, $region, $service ) {
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );

		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}

	/**
	 * Parses numeric amount from free-form price text.
	 *
	 * @param string $price_text Raw price text.
	 * @return float
	 */
	private function parse_price_amount( $price_text ) {
		$normalized = str_replace( ',', '.', (string) $price_text );
		$numeric    = preg_replace( '/[^0-9.]/', '', $normalized );

		if ( '' === $numeric ) {
			return 0.0;
		}

		return (float) $numeric;
	}

	/**
	 * Uploads optional guest PDF documents and links them with metadata.
	 *
	 * @param int $guest_id Guest ID.
	 * @return array|WP_Error
	 */
	private function upload_guest_documents( $guest_id ) {
		$fields = array(
			'guest_garantia_alicuota_pdf'   => 'garantia_alicuota',
			'guest_cedula_papeleta_pdf'     => 'cedula_papeleta',
			'guest_certificado_bancario_pdf'=> 'certificado_bancario',
		);

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$uploaded = array();

		foreach ( $fields as $field_name => $doc_type ) {
			if ( ! isset( $_FILES[ $field_name ] ) || ! is_array( $_FILES[ $field_name ] ) ) {
				continue;
			}

			$file_data  = $_FILES[ $field_name ];
			$file_error = isset( $file_data['error'] ) ? (int) $file_data['error'] : UPLOAD_ERR_NO_FILE;

			if ( UPLOAD_ERR_NO_FILE === $file_error ) {
				continue;
			}

			if ( UPLOAD_ERR_OK !== $file_error ) {
				return new WP_Error( 'af_guest_pdf_upload_error', __( 'No se pudo subir uno de los documentos PDF del huesped.', 'arriendo-facil' ) );
			}

			if ( ! empty( $file_data['size'] ) && (int) $file_data['size'] > ( 10 * 1024 * 1024 ) ) {
				return new WP_Error( 'af_guest_pdf_upload_too_large', __( 'El PDF del huesped supera el tamano maximo (10 MB).', 'arriendo-facil' ) );
			}

			$checked = wp_check_filetype_and_ext( $file_data['tmp_name'], $file_data['name'], array( 'pdf' => 'application/pdf' ) );
			if ( 'pdf' !== (string) $checked['ext'] ) {
				return new WP_Error( 'af_guest_pdf_invalid_type', __( 'Solo se permiten archivos PDF para documentos del huesped.', 'arriendo-facil' ) );
			}

			$attachment_id = media_handle_upload(
				$field_name,
				0,
				array( 'post_title' => sprintf( 'guest-%d-%s', (int) $guest_id, $doc_type ) ),
				array(
					'test_form' => false,
					'mimes'     => array( 'pdf' => 'application/pdf' ),
				)
			);

			if ( is_wp_error( $attachment_id ) ) {
				return new WP_Error( 'af_guest_pdf_save_failed', __( 'No se pudo guardar uno de los documentos PDF del huesped.', 'arriendo-facil' ) );
			}

			update_post_meta( (int) $attachment_id, '_af_guest_id', (int) $guest_id );
			update_post_meta( (int) $attachment_id, '_af_guest_doc_type', $doc_type );

			$uploaded[ $doc_type ] = (int) $attachment_id;
		}

		return $uploaded;
	}

	/**
	 * Creates onboarding token row for post-visit legal profile completion.
	 *
	 * @param int    $guest_id Guest ID.
	 * @param int    $accommodation_id Accommodation ID.
	 * @param int    $visit_booking_id Visit booking ID.
	 * @param string $recipient_email Guest email.
	 * @param int    $expires_hours Expiration in hours.
	 * @return array|WP_Error
	 */
	private function create_guest_onboarding_token( $guest_id, $accommodation_id, $visit_booking_id, $recipient_email, $expires_hours = 72 ) {
		$guest_id         = absint( $guest_id );
		$accommodation_id = absint( $accommodation_id );
		$visit_booking_id = absint( $visit_booking_id );
		$recipient_email  = sanitize_email( (string) $recipient_email );
		$expires_hours    = max( 6, min( 168, absint( $expires_hours ) ) );

		if ( ! $guest_id || ! $accommodation_id || ! is_email( $recipient_email ) ) {
			return new WP_Error( 'af_guest_onboarding_invalid_input', __( 'Datos insuficientes para generar token seguro.', 'arriendo-facil' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_guest_onboarding_tokens';

		$selector = wp_generate_password( 18, false, false ) . dechex( random_int( 1000, 65535 ) );
		$token    = wp_generate_password( 48, false, false ) . dechex( random_int( 4096, 65535 ) );
		$hash     = password_hash( $token, PASSWORD_DEFAULT );

		if ( ! is_string( $hash ) || '' === $hash ) {
			return new WP_Error( 'af_guest_onboarding_hash_failed', __( 'No se pudo generar hash de seguridad para el token.', 'arriendo-facil' ) );
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $expires_hours . ' hours' ) );

		$inserted = $wpdb->insert(
			$table,
			array(
				'selector'         => sanitize_text_field( (string) $selector ),
				'token_hash'       => $hash,
				'guest_id'         => $guest_id,
				'accommodation_id' => $accommodation_id,
				'visit_booking_id' => $visit_booking_id ? $visit_booking_id : null,
				'purpose'          => 'legal_profile',
				'recipient_email'  => $recipient_email,
				'expires_at'       => $expires_at,
				'max_attempts'     => 8,
				'attempts'         => 0,
				'status'           => 'active',
				'created_by'       => get_current_user_id(),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_guest_onboarding_insert_failed', __( 'No se pudo guardar el token de onboarding.', 'arriendo-facil' ) );
		}

		return array(
			'token_id'    => (int) $wpdb->insert_id,
			'selector'    => (string) $selector,
			'token'       => (string) $token,
			'expires_at'  => (string) $expires_at,
		);
	}

	/**
	 * Resolves and validates onboarding token.
	 *
	 * @param string $selector Selector component.
	 * @param string $token Plain token component.
	 * @param bool   $increment_attempts Whether to increment attempt counter.
	 * @return array|WP_Error
	 */
	private function resolve_guest_onboarding_token( $selector, $token, $increment_attempts = false ) {
		$selector = sanitize_text_field( (string) $selector );
		$token    = sanitize_text_field( (string) $token );

		if ( '' === $selector || '' === $token ) {
			return new WP_Error( 'af_guest_onboarding_missing_token', __( 'Token incompleto.', 'arriendo-facil' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_guest_onboarding_tokens';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE selector = %s LIMIT 1",
				$selector
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'af_guest_onboarding_not_found', __( 'El enlace no es valido.', 'arriendo-facil' ) );
		}

		$status = isset( $row->status ) ? sanitize_key( (string) $row->status ) : '';
		if ( 'active' !== $status ) {
			return new WP_Error( 'af_guest_onboarding_inactive', __( 'Este enlace ya no esta activo.', 'arriendo-facil' ) );
		}

		$expires_at = isset( $row->expires_at ) ? strtotime( (string) $row->expires_at ) : false;
		if ( false === $expires_at || $expires_at < time() ) {
			$wpdb->update(
				$table,
				array( 'status' => 'expired' ),
				array( 'id' => absint( $row->id ) ),
				array( '%s' ),
				array( '%d' )
			);
			return new WP_Error( 'af_guest_onboarding_expired', __( 'Este enlace ya expiro. Solicita uno nuevo.', 'arriendo-facil' ) );
		}

		$max_attempts = isset( $row->max_attempts ) ? absint( $row->max_attempts ) : 8;
		$attempts     = isset( $row->attempts ) ? absint( $row->attempts ) : 0;

		if ( $attempts >= $max_attempts ) {
			$wpdb->update(
				$table,
				array( 'status' => 'blocked' ),
				array( 'id' => absint( $row->id ) ),
				array( '%s' ),
				array( '%d' )
			);
			return new WP_Error( 'af_guest_onboarding_blocked', __( 'Enlace bloqueado por demasiados intentos.', 'arriendo-facil' ) );
		}

		$verified = password_verify( $token, (string) $row->token_hash );

		if ( $increment_attempts ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d",
					absint( $row->id )
				)
			);
		}

		if ( ! $verified ) {
			return new WP_Error( 'af_guest_onboarding_invalid_token', __( 'Token de acceso invalido.', 'arriendo-facil' ) );
		}

		return array(
			'token_id'         => absint( $row->id ),
			'guest_id'         => absint( $row->guest_id ),
			'accommodation_id' => absint( $row->accommodation_id ),
			'visit_booking_id' => absint( $row->visit_booking_id ),
			'expires_at'       => sanitize_text_field( (string) $row->expires_at ),
		);
	}

	/**
	 * Marks onboarding token as consumed.
	 *
	 * @param int $token_id Token row ID.
	 * @return void
	 */
	private function consume_guest_onboarding_token( $token_id ) {
		$token_id = absint( $token_id );
		if ( ! $token_id ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_guest_onboarding_tokens';
		$wpdb->update(
			$table,
			array(
				'status'  => 'used',
				'used_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $token_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Sends onboarding email with secure legal-profile link.
	 *
	 * @param string $tenant_email Recipient email.
	 * @param string $tenant_name Recipient display name.
	 * @param string $form_url Link URL.
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $expires_at Expiration date/time.
	 * @return bool
	 */
	private function send_guest_legal_profile_link_email( $tenant_email, $tenant_name, $form_url, $accommodation_id, $expires_at ) {
		$tenant_email = sanitize_email( (string) $tenant_email );
		if ( ! is_email( $tenant_email ) ) {
			return false;
		}

		$tenant_name = sanitize_text_field( (string) $tenant_name );
		if ( '' === trim( $tenant_name ) ) {
			$tenant_name = __( 'arrendatario', 'arriendo-facil' );
		}

		$title   = (string) get_the_title( absint( $accommodation_id ) );
		$subject = sprintf( __( '[Arriendo Facil] Completa tu perfil legal para %s', 'arriendo-facil' ), $title ? $title : __( 'tu arriendo', 'arriendo-facil' ) );

		$expires_line = '';
		if ( '' !== trim( (string) $expires_at ) ) {
			$expires_line = '<p style="margin:0 0 14px;color:#334155;line-height:1.6;">' . sprintf( esc_html__( 'Este enlace estara disponible hasta: %s', 'arriendo-facil' ), esc_html( $expires_at ) ) . '</p>';
		}

		$message = '<div style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">';
		$message .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">';
		$message .= '<div style="padding:18px 22px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#ffffff;">';
		$message .= '<h2 style="margin:0;font-size:20px;line-height:1.3;">' . esc_html__( 'Completa tu perfil legal de arriendo', 'arriendo-facil' ) . '</h2>';
		$message .= '</div>';
		$message .= '<div style="padding:22px;">';
		$message .= '<p style="margin:0 0 12px;line-height:1.6;">' . sprintf( esc_html__( 'Hola %s, para continuar con tu proceso necesitamos tu informacion legal y documentos.', 'arriendo-facil' ), esc_html( $tenant_name ) ) . '</p>';
		$message .= '<p style="margin:0 0 16px;line-height:1.6;">' . esc_html__( 'Haz clic en el siguiente boton para completar el formulario seguro:', 'arriendo-facil' ) . '</p>';
		$message .= '<p style="margin:0 0 18px;"><a href="' . esc_url( (string) $form_url ) . '" style="display:inline-block;padding:12px 18px;border-radius:8px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:600;">' . esc_html__( 'Completar perfil legal', 'arriendo-facil' ) . '</a></p>';
		$message .= $expires_line;
		$message .= '<p style="margin:0;line-height:1.6;color:#475569;">' . esc_html__( 'Si el enlace expira, solicita uno nuevo al propietario o soporte.', 'arriendo-facil' ) . '</p>';
		$message .= '</div></div>';
		$message .= '<p style="max-width:640px;margin:12px auto 0;font-size:12px;color:#64748b;text-align:center;">Arriendo Facil</p>';
		$message .= '</div>';

		return (bool) wp_mail( $tenant_email, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Returns a paginated list of guests via AJAX.
	 */
	public function ajax_get_guests() {
		check_ajax_referer( 'af_guest_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$page     = isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;
		$per_page = 20;
		$offset   = ( $page - 1 ) * $per_page;

		global $wpdb;

		if ( Arriendo_Facil_Accommodation::user_is_owner() ) {
			$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
			if ( ! empty( $owner_ids ) ) {
				$ids_sql = implode( ',', array_map( 'intval', $owner_ids ) );
				$guests = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}af_guests WHERE accommodation_id IN ($ids_sql) ORDER BY created_at DESC LIMIT %d OFFSET %d",
						$per_page,
						$offset
					)
				);
			} else {
				$guests = array();
			}
		} else {
			$guests = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}af_guests ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
		}

		wp_send_json_success( $guests );
	}

	/**
	 * Scores a guest using the AI service via AJAX.
	 */
	public function ajax_score_guest() {
		check_ajax_referer( 'af_guest_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$guest_id = isset( $_POST['guest_id'] ) ? absint( $_POST['guest_id'] ) : 0;
		if ( ! $guest_id ) {
			wp_send_json_error( array( 'message' => __( 'ID de huesped invalido.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$guest = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d", $guest_id )
		);

		if ( ! $guest ) {
			wp_send_json_error( array( 'message' => __( 'Huesped no encontrado.', 'arriendo-facil' ) ) );
		}

		$ai      = new Arriendo_Facil_AI_Service();
		$result  = $ai->score_guest( (array) $guest );

		if ( isset( $result['score'] ) ) {
			$wpdb->update(
				$wpdb->prefix . 'af_guests',
				array( 'ai_score' => floatval( $result['score'] ) ),
				array( 'id' => $guest_id ),
				array( '%f' ),
				array( '%d' )
			);
			wp_send_json_success( array( 'score' => $result['score'], 'summary' => $result['summary'] ?? '' ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'AI scoring failed.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Returns a guest record by ID.
	 *
	 * @param int $guest_id Guest ID.
	 * @return object|null Guest row or null.
	 */
	public function get_guest( $guest_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d", $guest_id )
		);
	}
}
