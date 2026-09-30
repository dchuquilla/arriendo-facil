<?php
/**
 * AJAX endpoints for managing tenant records from the panel.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Guest_Admin_Controller
 */
class Arriendo_Facil_Guest_Admin_Controller {

	/**
	 * Registers AJAX endpoints.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_create_guest', array( $this, 'ajax_create_guest' ) );
		add_action( 'wp_ajax_af_update_guest', array( $this, 'ajax_update_guest' ) );
		add_action( 'wp_ajax_af_update_guest_documents', array( $this, 'ajax_update_guest_documents' ) );
		add_action( 'wp_ajax_af_get_guests', array( $this, 'ajax_get_guests' ) );
		add_action( 'wp_ajax_af_score_guest', array( $this, 'ajax_score_guest' ) );
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
		$nationality = isset( $_POST['nationality'] ) ? sanitize_text_field( wp_unslash( $_POST['nationality'] ) ) : '';
		$birth_city  = isset( $_POST['birth_city'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_city'] ) ) : '';

		$name_parts = preg_split( '/\s+/', trim( $name ) );
		$first_name = ! empty( $name_parts[0] ) ? $name_parts[0] : '';
		$last_name  = count( $name_parts ) > 1 ? trim( implode( ' ', array_slice( $name_parts, 1 ) ) ) : '';

		if ( ! $first_name || ! $email || ! $phone || ! $id_number ) {
			wp_send_json_error( array( 'message' => __( 'Faltan campos obligatorios.', 'arriendo-facil' ) ) );
		}

		if ( $accommodation_id ) {
			if ( 'accommodation' !== get_post_type( $accommodation_id ) ) {
				wp_send_json_error( array( 'message' => __( 'ID de alojamiento invalido.', 'arriendo-facil' ) ) );
			}

			if ( ! Arriendo_Facil_Tenancy::can_access_accommodation( $accommodation_id ) ) {
				wp_send_json_error( array( 'message' => __( 'No puedes vincular este inquilino a un inmueble que no administras.', 'arriendo-facil' ) ), 403 );
			}
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Correo electronico invalido.', 'arriendo-facil' ) ) );
		}

		if ( 1 !== preg_match( '/^[0-9]{1,10}$/', $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'El telefono debe contener solo numeros, maximo 10 digitos.', 'arriendo-facil' ) ) );
		}

		if ( 1 !== preg_match( '/^[0-9]{10,13}$/', $id_number ) ) {
			wp_send_json_error( array( 'message' => __( 'La cedula debe tener 10 digitos y el RUC 13.', 'arriendo-facil' ) ) );
		}

		// Verifica el digito verificador real (algoritmo modulo 10/11 de Ecuador),
		// no solo el largo del numero, para bloquear identificaciones inventadas.
		$id_doc_type = 13 === strlen( $id_number ) ? 'ruc' : 'cedula';
		if ( ! Arriendo_Facil_Identity_Validator::validate( $id_doc_type, $id_number ) ) {
			wp_send_json_error( array( 'message' => __( 'El numero de cedula o RUC no es valido (digito verificador incorrecto).', 'arriendo-facil' ) ) );
		}

		// El operador registra lo basico y los documentos; el flujo por token se
		// mantiene solo como compatibilidad para el enlace legado.
		if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) {
			if ( ! $referencia_personal_1 || ! $referencia_personal_2 ) {
				wp_send_json_error( array( 'message' => __( 'Indica al menos dos referencias personales.', 'arriendo-facil' ) ) );
			}

			if ( $mascotas < 1 || $mascotas > 10 ) {
				wp_send_json_error( array( 'message' => __( 'Mascotas debe estar entre 1 y 10.', 'arriendo-facil' ) ) );
			}

			if ( $personas_viviran < 1 || $personas_viviran > 10 ) {
				wp_send_json_error( array( 'message' => __( 'El numero de personas debe estar entre 1 y 10.', 'arriendo-facil' ) ) );
			}
		}

		$schema_result = Arriendo_Facil_Guest::ensure_guest_extra_columns();
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
				'nationality'           => $nationality ? $nationality : null,
				'birth_city'            => $birth_city ? $birth_city : null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( $inserted ) {
			$guest_id      = (int) $wpdb->insert_id;
			$upload_result = Arriendo_Facil_Guest::upload_guest_documents( $guest_id );

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
	 * AJAX: edits an existing guest from the admin profile (contact, identity
	 * and property link). Corrections are routine: a wrong digit in the cedula
	 * or a phone typed badly must not require recreating the tenant.
	 *
	 * @return void
	 */
	public function ajax_update_guest() {
		check_ajax_referer( 'af_guest_edit_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$guest_id = isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0;
		if ( ! $guest_id ) {
			wp_send_json_error( array( 'message' => __( 'Huesped no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		if ( ! Arriendo_Facil_Tenancy::can_access_guest( $guest_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este huesped.', 'arriendo-facil' ) ), 403 );
		}

		global $wpdb;
		$guests_table = $wpdb->prefix . 'af_guests';

		$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$guests_table} WHERE id = %d", $guest_id ) );
		if ( ! $current ) {
			wp_send_json_error( array( 'message' => __( 'Huesped no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$id_number  = isset( $_POST['id_number'] ) ? sanitize_text_field( wp_unslash( $_POST['id_number'] ) ) : '';
		$nationality = isset( $_POST['nationality'] ) ? sanitize_text_field( wp_unslash( $_POST['nationality'] ) ) : '';
		$birth_city  = isset( $_POST['birth_city'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_city'] ) ) : '';
		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;

		if ( ! $first_name ) {
			wp_send_json_error( array( 'message' => __( 'El nombre es obligatorio.', 'arriendo-facil' ) ) );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Correo electronico invalido.', 'arriendo-facil' ) ) );
		}

		if ( $phone && 1 !== preg_match( '/^[0-9]{1,10}$/', $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'El telefono debe contener solo numeros, maximo 10 digitos.', 'arriendo-facil' ) ) );
		}

		if ( $id_number && 1 !== preg_match( '/^[0-9]{10,13}$/', $id_number ) ) {
			wp_send_json_error( array( 'message' => __( 'La cedula debe tener 10 digitos y el RUC 13.', 'arriendo-facil' ) ) );
		}

		// El digito verificador se revalida igual que en el alta: una cedula
		// inventada no debe quedar guardada por un descuido del operador.
		if ( $id_number ) {
			$id_doc_type = 13 === strlen( $id_number ) ? 'ruc' : 'cedula';
			if ( ! Arriendo_Facil_Identity_Validator::validate( $id_doc_type, $id_number ) ) {
				wp_send_json_error( array( 'message' => __( 'El numero de cedula o RUC no es valido (digito verificador incorrecto).', 'arriendo-facil' ) ) );
			}
		}

		$email_owner = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$guests_table} WHERE email = %s AND id <> %d", $email, $guest_id )
		);
		if ( $email_owner ) {
			wp_send_json_error(
				array( 'message' => __( 'Ese correo ya pertenece a otro inquilino registrado.', 'arriendo-facil' ) ),
				409
			);
		}

		if ( $accommodation_id ) {
			if ( 'accommodation' !== get_post_type( $accommodation_id ) ) {
				wp_send_json_error( array( 'message' => __( 'ID de alojamiento invalido.', 'arriendo-facil' ) ) );
			}

			if ( ! Arriendo_Facil_Tenancy::can_access_accommodation( $accommodation_id ) ) {
				wp_send_json_error( array( 'message' => __( 'No puedes vincular este inquilino a un inmueble que no administras.', 'arriendo-facil' ) ), 403 );
			}
		}

		$update  = array();
		$formats = array();

		$update['first_name']  = $first_name;
		$formats[]             = '%s';
		$update['last_name']   = $last_name;
		$formats[]             = '%s';
		$update['email']       = $email;
		$formats[]             = '%s';
		$update['phone']       = $phone;
		$formats[]             = '%s';
		$update['nationality'] = $nationality;
		$formats[]             = '%s';
		$update['birth_city']  = $birth_city;
		$formats[]             = '%s';
		// 0 = sin vincular, igual que en el alta.
		$update['accommodation_id'] = $accommodation_id;
		$formats[]                 = '%d';

		$identity_changed = false;
		if ( $id_number && (string) $current->id_number !== $id_number ) {
			$update['id_number'] = $id_number;
			$formats[]           = '%s';
			$identity_changed    = true;
		}

		// Cambiar la identificacion invalida la revision previa: el documento
		// verificado era de otra persona.
		if ( $identity_changed ) {
			$update['doc_status']            = 'pendiente';
			$formats[]                       = '%s';
			$update['identity_match_status'] = 'not_checked';
			$formats[]                       = '%s';
		}

		$updated = $wpdb->update(
			$guests_table,
			$update,
			array( 'id' => $guest_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo actualizar el huesped.', 'arriendo-facil' ) ) );
		}

		wp_send_json_success(
			array(
				'message'         => __( 'Ficha del inquilino actualizada.', 'arriendo-facil' ),
				'identity_changed' => $identity_changed,
			)
		);
	}

	/**
	 * AJAX: uploads identity/income PDFs for an existing guest from the admin
	 * profile, so operations never depends on emailing a token link.
	 *
	 * @return void
	 */
	public function ajax_update_guest_documents() {
		check_ajax_referer( 'af_document_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$guest_id = isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0;
		if ( ! $guest_id ) {
			wp_send_json_error( array( 'message' => __( 'Huesped no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		if ( ! Arriendo_Facil_Tenancy::can_access_guest( $guest_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este huesped.', 'arriendo-facil' ) ), 403 );
		}

		$uploaded = Arriendo_Facil_Guest::upload_guest_documents( $guest_id );
		if ( is_wp_error( $uploaded ) ) {
			wp_send_json_error( array( 'message' => $uploaded->get_error_message() ) );
		}

		if ( empty( $uploaded ) ) {
			wp_send_json_error( array( 'message' => __( 'Selecciona al menos un archivo PDF.', 'arriendo-facil' ) ) );
		}

		// Un documento nuevo invalida cualquier revision previa: vuelve a pendiente.
		global $wpdb;
		$guests_table = $wpdb->prefix . 'af_guests';
		$doc_status   = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT doc_status FROM {$guests_table} WHERE id = %d", $guest_id )
		);

		if ( in_array( $doc_status, array( 'verificado', 'rechazado' ), true ) ) {
			$wpdb->update(
				$guests_table,
				array( 'doc_status' => 'pendiente' ),
				array( 'id' => $guest_id ),
				array( '%s' ),
				array( '%d' )
			);
			$doc_status = 'pendiente';
		}

		wp_send_json_success(
			array(
				'uploaded_documents' => $uploaded,
				'doc_status'         => $doc_status,
				'message'            => __( 'Documentos cargados. Quedan pendientes de verificacion.', 'arriendo-facil' ),
			)
		);
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
}
