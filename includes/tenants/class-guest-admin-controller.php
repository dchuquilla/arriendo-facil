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
		add_action( 'wp_ajax_af_delete_guest', array( $this, 'ajax_delete_guest' ) );
		add_action( 'wp_ajax_af_update_guest_documents', array( $this, 'ajax_update_guest_documents' ) );
		add_action( 'wp_ajax_af_get_guests', array( $this, 'ajax_get_guests' ) );
		add_action( 'wp_ajax_af_score_guest', array( $this, 'ajax_score_guest' ) );
	}

	/**
	 * Creates a new guest record via AJAX.
	 */
	public function ajax_create_guest() {
		check_ajax_referer( 'af_guest_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$id_type    = isset( $_POST['id_type'] ) ? sanitize_key( wp_unslash( $_POST['id_type'] ) ) : '';
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
		$income_range = isset( $_POST['income_range'] ) ? sanitize_key( wp_unslash( $_POST['income_range'] ) ) : '';

		$name_parts = preg_split( '/\s+/', trim( $name ) );
		$first_name = ! empty( $name_parts[0] ) ? $name_parts[0] : '';
		$last_name  = count( $name_parts ) > 1 ? trim( implode( ' ', array_slice( $name_parts, 1 ) ) ) : '';

		if ( ! $first_name || ! $email || ! $phone || ! $id_number ) {
			wp_send_json_error( array( 'message' => __( 'Faltan campos obligatorios.', 'arriendo-facil' ) ) );
		}

		if ( ! Arriendo_Facil_Consent::is_given() ) {
			wp_send_json_error( array( 'message' => Arriendo_Facil_Consent::error_message() ), 400 );
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

		if ( ! $nationality || ! array_key_exists( $nationality, Arriendo_Facil_Guest::get_nationality_options() ) ) {
			wp_send_json_error( array( 'message' => __( 'Selecciona una nacionalidad de la lista.', 'arriendo-facil' ) ) );
		}

		if ( ! $income_range || ! array_key_exists( $income_range, Arriendo_Facil_Guest::get_income_range_options() ) ) {
			wp_send_json_error( array( 'message' => __( 'Selecciona un rango de ingresos de la lista.', 'arriendo-facil' ) ) );
		}

		// Copia de cédula (PDF) obligatoria para todos; antecedentes penales solo si es extranjero.
		$has_pdf = static function ( $field ) {
			return isset( $_FILES[ $field ]['error'] ) && UPLOAD_ERR_OK === (int) $_FILES[ $field ]['error'];
		};
		if ( ! $has_pdf( 'guest_cedula_papeleta_pdf' ) ) {
			wp_send_json_error( array( 'message' => __( 'Adjunta la copia de la cédula en PDF.', 'arriendo-facil' ) ) );
		}
		if ( ! $has_pdf( 'guest_certificado_laboral_pdf' ) && ! $has_pdf( 'guest_certificado_bancario_pdf' ) ) {
			wp_send_json_error( array( 'message' => __( 'Adjunta un comprobante de ingresos en PDF: certificado laboral o certificado bancario.', 'arriendo-facil' ) ) );
		}
		if ( Arriendo_Facil_Guest::is_foreign_nationality( $nationality ) && ! $has_pdf( 'guest_antecedentes_penales_pdf' ) ) {
			wp_send_json_error( array( 'message' => __( 'Para inquilinos extranjeros adjunta el certificado de antecedentes penales en PDF.', 'arriendo-facil' ) ) );
		}

		// Mismo criterio que el contrato: al vincular un inmueble se necesita la vigencia.
		if ( $accommodation_id ) {
			$date_ok = static function ( $value ) {
				$parsed = DateTime::createFromFormat( 'Y-m-d', $value );
				return $parsed && $parsed->format( 'Y-m-d' ) === $value;
			};

			if ( ! $date_ok( $rental_start_date ) || ! $date_ok( $rental_end_date ) ) {
				wp_send_json_error( array( 'message' => __( 'Indica las fechas de inicio y fin del arriendo para vincular el inmueble.', 'arriendo-facil' ) ) );
			}

			if ( $rental_end_date <= $rental_start_date ) {
				wp_send_json_error( array( 'message' => __( 'La fecha de fin debe ser posterior a la de inicio.', 'arriendo-facil' ) ) );
			}

			if ( '' !== $desired_price && ( ! is_numeric( $desired_price ) || (float) $desired_price < 0 ) ) {
				wp_send_json_error( array( 'message' => __( 'El canon mensual debe ser un numero valido.', 'arriendo-facil' ) ) );
			}

			if ( '' !== $guarantee_text && ( ! is_numeric( $guarantee_text ) || (float) $guarantee_text < 0 ) ) {
				wp_send_json_error( array( 'message' => __( 'La garantia debe ser un numero valido.', 'arriendo-facil' ) ) );
			}
		} else {
			$rental_start_date = '';
			$rental_end_date   = '';
			$desired_price     = '';
			$guarantee_text    = '';
		}

		if ( 1 !== preg_match( '/^[0-9]{1,10}$/', $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'El telefono debe contener solo numeros, maximo 10 digitos.', 'arriendo-facil' ) ) );
		}

		$id_number = $this->normalize_id_number( $id_type, $id_number );
		if ( ! $this->is_valid_id_number( $id_type, $id_number ) ) {
			wp_send_json_error( array( 'message' => $this->id_number_error_message( $id_type ) ) );
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

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, deleted_at FROM {$wpdb->prefix}af_guests WHERE email = %s LIMIT 1", $email )
		);
		// A tenant removed earlier is brought back with the new data instead of blocking the e-mail.
		$reactivate_id = ( $existing && ! empty( $existing->deleted_at ) ) ? (int) $existing->id : 0;
		if ( $existing && ! $reactivate_id ) {
			wp_send_json_error( array( 'message' => __( 'Ya existe un inquilino registrado con ese correo.', 'arriendo-facil' ) ), 409 );
		}
		if ( $reactivate_id && ! Arriendo_Facil_Tenancy::can_manage_all() ) {
			$mine = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}af_guests WHERE id = %d AND deleted_by = %d",
					$reactivate_id,
					get_current_user_id()
				)
			);
			if ( ! $mine ) {
				wp_send_json_error( array( 'message' => __( 'Ya existe un inquilino registrado con ese correo.', 'arriendo-facil' ) ), 409 );
			}
		}

		if ( Arriendo_Facil_Plans::applies_to() ) {
			if ( ! Arriendo_Facil_Plans::lock( get_current_user_id() ) ) {
				Arriendo_Facil_Plans::send_busy_error();
			}
			$plan_gate = Arriendo_Facil_Plans::limit_gate( 'guests' );
			if ( $plan_gate ) {
				Arriendo_Facil_Plans::send_gate_error( $plan_gate );
			}
		}

		$guest_row = array(
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
			'income_range'          => $income_range ? $income_range : null,
			'created_by'            => get_current_user_id(),
		);
		$guest_formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d' );

		if ( $reactivate_id ) {
			$guest_row['deleted_at'] = null;
			$guest_row['deleted_by'] = null;
			$guest_formats[]         = '%s';
			$guest_formats[]         = '%s';
			$inserted                = false !== $wpdb->update( $wpdb->prefix . 'af_guests', $guest_row, array( 'id' => $reactivate_id ), $guest_formats, array( '%d' ) );
		} else {
			$inserted = $wpdb->insert( $wpdb->prefix . 'af_guests', $guest_row, $guest_formats );
		}

		if ( $inserted ) {
			$guest_id      = $reactivate_id ? $reactivate_id : (int) $wpdb->insert_id;
				Arriendo_Facil_Consent::record( 'tenant_record', get_current_user_id(), 'guest', $guest_id );
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
			error_log( 'Arriendo Facil: no se pudo crear el inquilino: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			wp_send_json_error( array( 'message' => __( 'No se pudo crear el huesped.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * AJAX: removes a tenant from the lists (soft delete).
	 *
	 * The row stays: past leases, charges and invoices keep pointing at it, and
	 * invoices must be kept for tax purposes. A tenant with a contract still in
	 * force cannot be removed, and neither can one shared with another manager.
	 *
	 * @return void
	 */
	public function ajax_delete_guest() {
		check_ajax_referer( 'af_guest_edit_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$guest_id = isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0;
		if ( ! $guest_id || ! Arriendo_Facil_Tenancy::can_access_guest( $guest_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No encontramos al inquilino o no tienes acceso.', 'arriendo-facil' ) ), 404 );
		}

		$schema_result = Arriendo_Facil_Guest::ensure_guest_extra_columns();
		if ( is_wp_error( $schema_result ) ) {
			wp_send_json_error( array( 'message' => $schema_result->get_error_message() ), 500 );
		}

		global $wpdb;
		$guests_table = $wpdb->prefix . 'af_guests';
		$leases_table = $wpdb->prefix . 'af_leases';

		$guest = $wpdb->get_row( $wpdb->prepare( "SELECT id, deleted_at FROM {$guests_table} WHERE id = %d", $guest_id ) );
		if ( ! $guest || ! empty( $guest->deleted_at ) ) {
			wp_send_json_error( array( 'message' => __( 'El inquilino ya fue eliminado. Recarga la página.', 'arriendo-facil' ) ), 409 );
		}

		$statuses_in = "'" . implode( "','", Arriendo_Facil_Property_Trash_Relations::LEASE_STATUSES ) . "'";
		$open_leases = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$leases_table} WHERE guest_id = %d AND status IN ({$statuses_in}) AND deleted_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant list.
				$guest_id
			)
		);
		if ( $open_leases > 0 ) {
			wp_send_json_error(
				array( 'message' => __( 'Este inquilino tiene un contrato vigente o en trámite. Finaliza o elimina el contrato primero y luego podrás eliminar al inquilino.', 'arriendo-facil' ) ),
				409
			);
		}

		// The row is global (one per e-mail): never hide a tenant another manager still works with.
		$scope = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
		if ( null !== $scope ) {
			$foreign = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$leases_table} WHERE guest_id = %d AND deleted_at IS NULL AND accommodation_id NOT IN (" . Arriendo_Facil_Tenancy::ids_in_clause( $scope ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids cast to int.
					$guest_id
				)
			);
			if ( $foreign > 0 ) {
				wp_send_json_error(
					array( 'message' => __( 'Este inquilino también tiene contratos con otro gestor, por eso no se puede eliminar.', 'arriendo-facil' ) ),
					409
				);
			}
		}

		$updated = $wpdb->update(
			$guests_table,
			array(
				'deleted_at' => current_time( 'mysql' ),
				'deleted_by' => get_current_user_id(),
			),
			array( 'id' => $guest_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			error_log( 'Arriendo Facil: no se pudo eliminar el inquilino: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			wp_send_json_error( array( 'message' => __( 'No se pudo eliminar al inquilino.', 'arriendo-facil' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Inquilino eliminado.', 'arriendo-facil' ),
				'id'      => $guest_id,
			)
		);
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
		$id_type    = isset( $_POST['id_type'] ) ? sanitize_key( wp_unslash( $_POST['id_type'] ) ) : '';
		$id_number  = isset( $_POST['id_number'] ) ? sanitize_text_field( wp_unslash( $_POST['id_number'] ) ) : '';
		$nationality = isset( $_POST['nationality'] ) ? sanitize_text_field( wp_unslash( $_POST['nationality'] ) ) : '';
		$birth_city  = isset( $_POST['birth_city'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_city'] ) ) : '';
		$income_range = isset( $_POST['income_range'] ) ? sanitize_key( wp_unslash( $_POST['income_range'] ) ) : '';
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

		if ( $id_number ) {
			$id_number = $this->normalize_id_number( $id_type, $id_number );
			if ( ! $this->is_valid_id_number( $id_type, $id_number ) ) {
				wp_send_json_error( array( 'message' => $this->id_number_error_message( $id_type ) ) );
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
		if ( isset( $_POST['income_range'] ) && ( '' === $income_range || array_key_exists( $income_range, Arriendo_Facil_Guest::get_income_range_options() ) ) ) {
			Arriendo_Facil_Guest::ensure_guest_extra_columns();
			$update['income_range'] = '' === $income_range ? null : $income_range;
			$formats[]              = '%s';
		}
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

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$page     = isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;
		$per_page = 20;
		$offset   = ( $page - 1 ) * $per_page;

		global $wpdb;

		if ( ! Arriendo_Facil_Tenancy::can_manage_all() ) {
			Arriendo_Facil_Guest::ensure_guest_extra_columns();
			$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
			$ids_sql   = $owner_ids ? implode( ',', array_map( 'intval', $owner_ids ) ) : '0';
			$guests    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}af_guests WHERE deleted_at IS NULL AND ( accommodation_id IN ($ids_sql) OR created_by = %d OR id IN ( SELECT guest_id FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($ids_sql) AND deleted_at IS NULL AND guest_id IS NOT NULL ) ) ORDER BY created_at DESC LIMIT %d OFFSET %d",
					get_current_user_id(),
					$per_page,
					$offset
				)
			);
		} else {
			Arriendo_Facil_Guest::ensure_guest_extra_columns();
			$guests = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}af_guests WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT %d OFFSET %d",
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

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$guest_id = isset( $_POST['guest_id'] ) ? absint( $_POST['guest_id'] ) : 0;
		if ( ! $guest_id ) {
			wp_send_json_error( array( 'message' => __( 'ID de huesped invalido.', 'arriendo-facil' ) ) );
		}

		if ( ! Arriendo_Facil_Tenancy::can_access_guest( $guest_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este inquilino.', 'arriendo-facil' ) ), 403 );
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
	 * Resolves the document type; falls back to the number length when absent.
	 *
	 * @param string $id_type   Submitted type.
	 * @param string $id_number Raw number.
	 * @return string cedula|ruc|pasaporte
	 */
	private function resolve_id_type( $id_type, $id_number ) {
		if ( in_array( $id_type, array( 'cedula', 'ruc', 'pasaporte' ), true ) ) {
			return $id_type;
		}

		$digits = preg_replace( '/\D/', '', (string) $id_number );
		if ( 13 === strlen( $digits ) ) {
			return 'ruc';
		}

		return ctype_digit( (string) $id_number ) ? 'cedula' : 'pasaporte';
	}

	private function normalize_id_number( $id_type, $id_number ) {
		return AF_Text_Normalizer::document( $this->resolve_id_type( $id_type, $id_number ), (string) $id_number );
	}

	private function is_valid_id_number( $id_type, $id_number ) {
		return Arriendo_Facil_Identity_Validator::validate_format( $this->resolve_id_type( $id_type, $id_number ), $id_number );
	}

	private function id_number_error_message( $id_type ) {
		if ( 'pasaporte' === $id_type ) {
			return __( 'El pasaporte debe tener entre 6 y 12 caracteres alfanumericos.', 'arriendo-facil' );
		}

		if ( 'ruc' === $id_type ) {
			return __( 'El RUC debe tener 13 digitos.', 'arriendo-facil' );
		}

		return __( 'La cedula debe tener 10 digitos.', 'arriendo-facil' );
	}
}
