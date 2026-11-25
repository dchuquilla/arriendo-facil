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

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
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

		if ( ! Arriendo_Facil_Tenancy::can_access_guest( $guest_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este inquilino.', 'arriendo-facil' ) ), 403 );
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
				'property'          => sanitize_text_field( (string) get_the_title( isset( $result['accommodation_id'] ) ? absint( $result['accommodation_id'] ) : absint( $guest->accommodation_id ) ) ),
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

		$raw_documents = array();

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

		// Los mismos datos que pide el registro interno de inquilinos (sin fechas ni personas:
		// la vigencia del arriendo la define el administrador al crear el contrato).
		$name_input   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone        = isset( $_POST['phone'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) : '';
		$id_type      = isset( $_POST['id_type'] ) ? sanitize_key( wp_unslash( $_POST['id_type'] ) ) : '';
		$id_number    = isset( $_POST['id_number'] ) ? sanitize_text_field( wp_unslash( $_POST['id_number'] ) ) : '';
		$nationality  = isset( $_POST['nationality'] ) ? sanitize_text_field( wp_unslash( $_POST['nationality'] ) ) : '';
		$birth_city   = isset( $_POST['birth_city'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['birth_city'] ) ), 0, 150 ) : '';
		$income_range = isset( $_POST['income_range'] ) ? sanitize_key( wp_unslash( $_POST['income_range'] ) ) : '';

		if ( '' === $name_input ) {
			$name_input = trim( (string) $guest->first_name . ' ' . (string) $guest->last_name );
		}

		if ( '' === $phone && ! empty( $guest->phone ) ) {
			$phone = preg_replace( '/\D+/', '', (string) $guest->phone );
		}

		if ( ! is_email( (string) $guest->email ) || '' === $phone || '' === $id_number ) {
			wp_send_json_error( array( 'message' => __( 'Faltan datos obligatorios para completar tu registro.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! Arriendo_Facil_Consent::is_given() ) {
			wp_send_json_error( array( 'message' => Arriendo_Facil_Consent::error_message() ), 400 );
		}

		if ( 1 !== preg_match( '/^[0-9]{7,10}$/', (string) $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'El teléfono debe tener entre 7 y 10 dígitos, solo números.', 'arriendo-facil' ) ), 400 );
		}

		// Tipo de documento: el elegido o, si falta, el que sugiere el número.
		if ( ! in_array( $id_type, array( 'cedula', 'ruc', 'pasaporte' ), true ) ) {
			$digits  = preg_replace( '/\D/', '', $id_number );
			$id_type = 13 === strlen( $digits ) ? 'ruc' : ( ctype_digit( $id_number ) ? 'cedula' : 'pasaporte' );
		}
		$id_number = AF_Text_Normalizer::document( $id_type, $id_number );
		if ( ! Arriendo_Facil_Identity_Validator::validate_format( $id_type, $id_number ) ) {
			$id_messages = array(
				'pasaporte' => __( 'El pasaporte debe tener entre 6 y 12 caracteres alfanuméricos.', 'arriendo-facil' ),
				'ruc'       => __( 'El RUC debe tener 13 dígitos.', 'arriendo-facil' ),
				'cedula'    => __( 'La cédula debe tener 10 dígitos.', 'arriendo-facil' ),
			);
			wp_send_json_error( array( 'message' => $id_messages[ $id_type ] ), 400 );
		}

		// Una ficha ya identificada no cambia de número por un enlace público: ese ajuste lo hace el administrador.
		$current_id = AF_Text_Normalizer::document( $id_type, (string) $guest->id_number );
		if ( '' !== $current_id && $current_id !== $id_number ) {
			wp_send_json_error( array( 'message' => __( 'Tu ficha ya tiene una identificación registrada distinta. Comunícate con tu administrador para corregirla.', 'arriendo-facil' ) ), 409 );
		}

		// Ficha en revisión o aprobada: nombre e identificación solo los cambia el administrador.
		if ( Arriendo_Facil_Document_Verification::identity_locked( $guest_id ) ) {
			$current_name = trim( (string) $guest->first_name . ' ' . (string) $guest->last_name );
			if ( '' !== $current_name && 0 !== strcasecmp( AF_Text_Normalizer::proper_name( $name_input ), AF_Text_Normalizer::proper_name( $current_name ) ) ) {
				wp_send_json_error( array( 'message' => __( 'Tu ficha ya está en revisión o aprobada: el nombre no se puede cambiar desde aquí. Pide la corrección a tu administrador.', 'arriendo-facil' ) ), 409 );
			}
			$locked_id = AF_Text_Normalizer::document( $id_type, (string) $guest->id_number );
			if ( '' !== $locked_id && $locked_id !== $id_number ) {
				wp_send_json_error( array( 'message' => __( 'Tu identificación ya está en revisión o aprobada. Pide la corrección a tu administrador.', 'arriendo-facil' ) ), 409 );
			}
		}

		if ( '' === $nationality || ! array_key_exists( $nationality, Arriendo_Facil_Guest::get_nationality_options() ) ) {
			wp_send_json_error( array( 'message' => __( 'Selecciona tu nacionalidad de la lista.', 'arriendo-facil' ) ), 400 );
		}

		if ( '' === $income_range || ! array_key_exists( $income_range, Arriendo_Facil_Guest::get_income_range_options() ) ) {
			wp_send_json_error( array( 'message' => __( 'Selecciona tu rango de ingresos de la lista.', 'arriendo-facil' ) ), 400 );
		}

		// Documentos: copia de cédula siempre; un comprobante de ingresos; antecedentes si es extranjero.
		$has_pdf = static function ( $field ) {
			return isset( $_FILES[ $field ]['error'] ) && UPLOAD_ERR_OK === (int) $_FILES[ $field ]['error'];
		};
		if ( ! $has_pdf( 'guest_cedula_papeleta_pdf' ) ) {
			wp_send_json_error( array( 'message' => __( 'Adjunta la copia de tu documento de identidad en PDF.', 'arriendo-facil' ) ), 400 );
		}
		if ( ! $has_pdf( 'guest_certificado_laboral_pdf' ) && ! $has_pdf( 'guest_certificado_bancario_pdf' ) ) {
			wp_send_json_error( array( 'message' => __( 'Adjunta un comprobante de ingresos en PDF: certificado laboral o certificado bancario.', 'arriendo-facil' ) ), 400 );
		}
		if ( Arriendo_Facil_Guest::is_foreign_nationality( $nationality ) && ! $has_pdf( 'guest_antecedentes_penales_pdf' ) ) {
			wp_send_json_error( array( 'message' => __( 'Para extranjeros, adjunta el certificado de antecedentes penales en PDF.', 'arriendo-facil' ) ), 400 );
		}

		// El inmueble ligado al enlace no debe estar bloqueado por el plan de su administrador.
		$plan_owner = (int) get_post_meta( $accommodation_id, '_af_owner_id', true );
		if ( $plan_owner && Arriendo_Facil_Plans::applies_to( $plan_owner ) && Arriendo_Facil_Tenancy::is_plan_locked( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Este inmueble no puede recibir más solicitudes por ahora. Comunícate con el administrador.', 'arriendo-facil' ) ), 409 );
		}

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

		// Primero los documentos: si no se pueden guardar, la ficha del inquilino queda intacta
		// (nada de registros a medias que el administrador vea sin sus documentos).
		$upload_result = Arriendo_Facil_Guest::upload_guest_documents( $guest_id, $raw_documents );
		if ( is_wp_error( $upload_result ) ) {
			$server_side = in_array( $upload_result->get_error_code(), array( 'af_guest_pdf_save_failed', 'af_guest_pdf_record_failed' ), true );
			wp_send_json_error( array( 'message' => $upload_result->get_error_message() ), $server_side ? 500 : 400 );
		}

		$name_parts = preg_split( '/\s+/', trim( $name_input ) );
		$first_name = ! empty( $name_parts[0] ) ? AF_Text_Normalizer::proper_name( (string) $name_parts[0] ) : '';
		$last_name  = count( $name_parts ) > 1 ? AF_Text_Normalizer::proper_name( trim( implode( ' ', array_slice( $name_parts, 1 ) ) ) ) : '';

		$updated = $wpdb->update(
			$wpdb->prefix . 'af_guests',
			array(
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'phone'        => $phone,
				'id_number'    => $id_number,
				'nationality'  => $nationality,
				'birth_city'   => '' !== $birth_city ? $birth_city : null,
				'income_range' => $income_range,
			),
			array( 'id' => $guest_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			// Sin ficha actualizada no se dejan documentos sueltos: el reintento empezaría limpio.
			foreach ( (array) $upload_result as $document_row_id ) {
				$wpdb->delete( $wpdb->prefix . 'af_guest_documents', array( 'id' => (int) $document_row_id ), array( '%d' ) );
			}
			wp_send_json_error( array( 'message' => __( 'No se pudo actualizar el perfil legal del arrendatario.', 'arriendo-facil' ) ), 500 );
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

		// Consume the token before responding (one-time use).
		$this->onboarding->consume_guest_onboarding_token( isset( $resolved['token_id'] ) ? absint( $resolved['token_id'] ) : 0 );

		/**
		 * El inquilino terminó su formulario completo (el gestor aún debe revisarlo).
		 *
		 * @param int $guest_id         Inquilino.
		 * @param int $accommodation_id Inmueble.
		 */
		do_action( 'af_guest_profile_completed', $guest_id, $accommodation_id );

		Arriendo_Facil_Consent::record( 'tenant_signup', 0, 'guest', $guest_id );

		wp_send_json_success(
			array(
				'guest_id'           => $guest_id,
				'uploaded_documents' => $upload_result,
				'message'            => __( '¡Gracias! Recibimos tu información. Tu administrador la revisará y se pondrá en contacto contigo.', 'arriendo-facil' ),
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
