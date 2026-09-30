<?php
/**
 * AJAX endpoints of the tenant onboarding link (operator + public form).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Guest_Onboarding_Controller
 */
class Arriendo_Facil_Guest_Onboarding_Controller {

	/**
	 * @var Arriendo_Facil_Guest_Onboarding
	 */
	private $onboarding;

	/**
	 * Registers AJAX endpoints.
	 *
	 * @param Arriendo_Facil_Guest_Onboarding|null $onboarding Onboarding service.
	 */
	public function __construct( $onboarding = null ) {
		$this->onboarding = $onboarding ? $onboarding : new Arriendo_Facil_Guest_Onboarding();

		add_action( 'wp_ajax_af_send_guest_profile_link', array( $this, 'ajax_send_guest_profile_link' ) );
		add_action( 'wp_ajax_af_validate_guest_profile_token', array( $this, 'ajax_validate_guest_profile_token' ) );
		add_action( 'wp_ajax_nopriv_af_validate_guest_profile_token', array( $this, 'ajax_validate_guest_profile_token' ) );
		add_action( 'wp_ajax_af_submit_guest_profile_by_token', array( $this, 'ajax_submit_guest_profile_by_token' ) );
		add_action( 'wp_ajax_nopriv_af_submit_guest_profile_by_token', array( $this, 'ajax_submit_guest_profile_by_token' ) );
		add_action( 'wp_ajax_af_refresh_nonce', array( $this, 'ajax_refresh_nonce' ) );
		add_action( 'wp_ajax_nopriv_af_refresh_nonce', array( $this, 'ajax_refresh_nonce' ) );
	}

	/**
	 * AJAX: fresh nonce for long-open public forms.
	 */
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
		$token_data    = $this->onboarding->create_guest_onboarding_token(
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

		$sent = $this->onboarding->send_guest_legal_profile_link_email(
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
	 * Validates onboarding token and returns minimal context for frontend form.
	 */
	public function ajax_validate_guest_profile_token() {
		$selector = isset( $_REQUEST['selector'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['selector'] ) ) : '';
		$token    = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';

		$result = $this->onboarding->resolve_guest_onboarding_token( $selector, $token, false );
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

		$resolved = $this->onboarding->resolve_guest_onboarding_token( $selector, $token, true );
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
		$schema_result = Arriendo_Facil_Guest::ensure_guest_extra_columns();
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

		// Verifica el digito verificador real, no solo el formato, para evitar
		// que se registre una identificacion inventada o mal transcrita.
		if ( ! Arriendo_Facil_Identity_Validator::validate_cedula( $id_number ) ) {
			wp_send_json_error( array( 'message' => __( 'El numero de cedula no es valido (digito verificador incorrecto). Verificalo e intenta nuevamente.', 'arriendo-facil' ) ), 400 );
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
		$first_name = ! empty( $name_parts[0] ) ? AF_Text_Normalizer::proper_name( (string) $name_parts[0] ) : '';
		$last_name  = count( $name_parts ) > 1 ? AF_Text_Normalizer::proper_name( trim( implode( ' ', array_slice( $name_parts, 1 ) ) ) ) : '';

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

		$upload_result = Arriendo_Facil_Guest::upload_guest_documents( $guest_id, $raw_documents );
		if ( is_wp_error( $upload_result ) ) {
			wp_send_json_error( array( 'message' => $upload_result->get_error_message() ), 400 );
		}

		// Cotejo automatico best-effort: busca el numero declarado dentro del texto
		// del documento subido. Solo es una senal de apoyo para el revisor humano;
		// nunca aprueba ni rechaza la identidad por si solo (ver Document_Verification).
		$identity_match_status = 'not_checked';
		if ( ! empty( $raw_documents['cedula_papeleta'] ) ) {
			$identity_match_status = Arriendo_Facil_Identity_Validator::cross_check_document(
				$id_number,
				(string) $raw_documents['cedula_papeleta']
			);
		}
		$wpdb->update(
			$wpdb->prefix . 'af_guests',
			array( 'identity_match_status' => $identity_match_status ),
			array( 'id' => $guest_id ),
			array( '%s' ),
			array( '%d' )
		);

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
		$this->onboarding->consume_guest_onboarding_token( isset( $resolved['token_id'] ) ? absint( $resolved['token_id'] ) : 0 );

		$contract_info = array(
			'generated' => false,
			'status'    => 'processed_with_errors',
		);

		try {
			$contract_info = $this->onboarding->create_lease_contract_for_guest( $guest_id, $lease_payload );
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
}
