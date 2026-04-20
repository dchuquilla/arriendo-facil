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
		add_action( 'wp_ajax_af_create_guest_frontend', array( $this, 'ajax_create_guest_frontend' ) );
		add_action( 'wp_ajax_nopriv_af_create_guest_frontend', array( $this, 'ajax_create_guest_frontend' ) );
		add_action( 'wp_ajax_af_get_guests', array( $this, 'ajax_get_guests' ) );
		add_action( 'wp_ajax_af_score_guest', array( $this, 'ajax_score_guest' ) );
	}

	/**
	 * Creates a new guest record from frontend chatbot via AJAX.
	 */
	public function ajax_create_guest_frontend() {
		if ( false === check_ajax_referer( 'af_guest_frontend_nonce', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Tu sesion expiro. Recarga la pagina y vuelve a intentarlo.', 'arriendo-facil' ),
				),
				403
			);
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
		$reference_1_name  = isset( $_POST['reference_1_name'] ) ? $this->normalize_reference_name( wp_unslash( $_POST['reference_1_name'] ) ) : '';
		$reference_1_phone = isset( $_POST['reference_1_phone'] ) ? $this->normalize_reference_phone( wp_unslash( $_POST['reference_1_phone'] ) ) : '';
		$reference_2_name  = isset( $_POST['reference_2_name'] ) ? $this->normalize_reference_name( wp_unslash( $_POST['reference_2_name'] ) ) : '';
		$reference_2_phone = isset( $_POST['reference_2_phone'] ) ? $this->normalize_reference_phone( wp_unslash( $_POST['reference_2_phone'] ) ) : '';
		$referencia_personal_1 = $this->build_reference_entry( $reference_1_name, $reference_1_phone );
		$referencia_personal_2 = $this->build_reference_entry( $reference_2_name, $reference_2_phone );

		if ( '' === $referencia_personal_1 ) {
			$referencia_personal_1 = isset( $_POST['referencia_personal_1'] ) ? sanitize_text_field( wp_unslash( $_POST['referencia_personal_1'] ) ) : '';
		}

		if ( '' === $referencia_personal_2 ) {
			$referencia_personal_2 = isset( $_POST['referencia_personal_2'] ) ? sanitize_text_field( wp_unslash( $_POST['referencia_personal_2'] ) ) : '';
		}
		$personas_viviran      = isset( $_POST['personas_viviran'] ) ? absint( wp_unslash( $_POST['personas_viviran'] ) ) : 0;

		$name_parts = preg_split( '/\s+/', trim( $name ) );
		$first_name = ! empty( $name_parts[0] ) ? $name_parts[0] : '';
		$last_name  = count( $name_parts ) > 1 ? trim( implode( ' ', array_slice( $name_parts, 1 ) ) ) : '';

		if ( ! $first_name || ! $email || ! $phone || ! $id_number ) {
			wp_send_json_error( array( 'message' => __( 'Faltan campos requeridos.', 'arriendo-facil' ) ) );
		}

		if ( ! $accommodation_id || 'accommodation' !== get_post_type( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Debes seleccionar una propiedad o habitacion valida.', 'arriendo-facil' ) ) );
		}

		// Allow registration for any accommodation status; operational review is handled later.

		if ( ! in_array( $rental_mode, array( 'dates', 'months', 'years' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Debes indicar una modalidad de arriendo valida.', 'arriendo-facil' ) ) );
		}

		if ( 'dates' === $rental_mode ) {
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $rental_start_date ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $rental_end_date ) ) {
				wp_send_json_error( array( 'message' => __( 'Las fechas de arriendo no son validas.', 'arriendo-facil' ) ) );
			}

			$start_ts = strtotime( $rental_start_date );
			$end_ts   = strtotime( $rental_end_date );
			if ( ! $start_ts || ! $end_ts || $end_ts < $start_ts ) {
				wp_send_json_error( array( 'message' => __( 'La fecha final debe ser mayor o igual a la inicial.', 'arriendo-facil' ) ) );
			}
		} elseif ( 'months' === $rental_mode ) {
			if ( $rental_months < 1 || $rental_months > 120 ) {
				wp_send_json_error( array( 'message' => __( 'Los meses deben estar entre 1 y 120.', 'arriendo-facil' ) ) );
			}
		} else {
			if ( $rental_years < 1 || $rental_years > 20 ) {
				wp_send_json_error( array( 'message' => __( 'Los anos deben estar entre 1 y 20.', 'arriendo-facil' ) ) );
			}
		}

		if ( '' === $desired_price || '' === $guarantee_text ) {
			wp_send_json_error( array( 'message' => __( 'Debes ingresar tu presupuesto y como garantizas el pago.', 'arriendo-facil' ) ) );
		}

		if ( ! $referencia_personal_1 || ! $referencia_personal_2 ) {
			wp_send_json_error( array( 'message' => __( 'Debes ingresar dos referencias personales con nombre y contacto.', 'arriendo-facil' ) ) );
		}

		if ( ! $this->is_valid_reference_entry( $referencia_personal_1 ) || ! $this->is_valid_reference_entry( $referencia_personal_2 ) ) {
			wp_send_json_error( array( 'message' => __( 'Cada referencia debe incluir nombre y celular de 10 digitos (ejemplo: Ana Perez - 0991234567).', 'arriendo-facil' ) ) );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Correo invalido.', 'arriendo-facil' ) ) );
		}

		if ( 1 !== preg_match( '/^[0-9]{10}$/', $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Telefono invalido. Debe tener exactamente 10 digitos numericos.', 'arriendo-facil' ) ) );
		}

		if ( 1 !== preg_match( '/^[0-9]{10}$/', $id_number ) ) {
			wp_send_json_error( array( 'message' => __( 'Documento invalido. La cedula debe tener exactamente 10 digitos numericos.', 'arriendo-facil' ) ) );
		}

		if ( $mascotas < 0 || $mascotas > 10 ) {
			wp_send_json_error( array( 'message' => __( 'Mascotas debe estar entre 0 y 10.', 'arriendo-facil' ) ) );
		}

		if ( $personas_viviran < 1 || $personas_viviran > 10 ) {
			wp_send_json_error( array( 'message' => __( 'Personas debe estar entre 1 y 10.', 'arriendo-facil' ) ) );
		}

		$schema_result = $this->ensure_guest_extra_columns();
		if ( is_wp_error( $schema_result ) ) {
			wp_send_json_error( array( 'message' => $schema_result->get_error_message() ) );
		}

		global $wpdb;
		$existing_guest_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}af_guests WHERE email = %s LIMIT 1",
				$email
			)
		);

		if ( $existing_guest_id > 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Ya existe una solicitud con ese correo. Si necesitas actualizar tus datos, contactanos para ayudarte.', 'arriendo-facil' ),
				)
			);
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'af_guests',
			array(
				'first_name'            => $first_name,
				'last_name'             => $last_name,
				'email'                 => $email,
				'phone'                 => $phone,
				'id_number'             => $id_number,
				'accommodation_id'      => $accommodation_id,
				'rental_mode'           => $rental_mode,
				'rental_start_date'     => $rental_start_date ? $rental_start_date : null,
				'rental_end_date'       => $rental_end_date ? $rental_end_date : null,
				'rental_months'         => $rental_months ? $rental_months : null,
				'rental_years'          => $rental_years ? $rental_years : null,
				'desired_price'         => $desired_price,
				'guarantee_text'        => $guarantee_text,
				'mascotas'              => $mascotas,
				'referencia_personal_1' => $referencia_personal_1,
				'referencia_personal_2' => $referencia_personal_2,
				'personas_viviran'      => $personas_viviran,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d' )
		);

		if ( $inserted ) {
			$guest_id = (int) $wpdb->insert_id;
			try {
				$contract_info = $this->create_lease_contract_for_guest(
					$guest_id,
					array(
						'accommodation_id'  => $accommodation_id,
						'rental_mode'       => $rental_mode,
						'rental_start_date' => $rental_start_date,
						'rental_end_date'   => $rental_end_date,
						'rental_months'     => $rental_months,
						'rental_years'      => $rental_years,
						'desired_price'     => $desired_price,
						'guarantee_text'    => $guarantee_text,
						'phone'             => $phone,
						'id_number'         => $id_number,
						'mascotas'          => $mascotas,
						'referencia_personal_1' => $referencia_personal_1,
						'referencia_personal_2' => $referencia_personal_2,
						'personas_viviran'  => $personas_viviran,
						'name'              => trim( $first_name . ' ' . $last_name ),
						'email'             => $email,
					)
				);
			} catch ( Throwable $throwable ) {
				error_log( 'Arriendo Facil lease auto-generation error: ' . $throwable->getMessage() );
				$contract_info = array(
					'generated' => false,
					'error'     => 'lease_generation_failed',
				);
			}

			wp_send_json_success(
				array(
					'id'      => $guest_id,
					'message' => __( 'Registro enviado. Pronto nos contactaremos contigo.', 'arriendo-facil' ),
					'contract' => $contract_info,
				)
			);
		}

		$db_error = isset( $wpdb->last_error ) ? trim( (string) $wpdb->last_error ) : '';
		if ( '' !== $db_error && false !== stripos( $db_error, 'duplicate' ) && false !== stripos( $db_error, 'email' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Ya existe una solicitud con ese correo. Si necesitas actualizar tus datos, contactanos para ayudarte.', 'arriendo-facil' ),
				)
			);
		}

		if ( '' !== $db_error ) {
			error_log( 'Arriendo Facil guest registration DB error: ' . $db_error );
		}

		wp_send_json_error(
			array(
				'message' => __( 'No se pudo registrar tu solicitud de arriendo. Intenta nuevamente en unos minutos.', 'arriendo-facil' ),
			)
		);
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
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
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
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'arriendo-facil' ) ) );
		}

		if ( $accommodation_id && 'accommodation' !== get_post_type( $accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid accommodation ID.', 'arriendo-facil' ) ) );
		}

		if ( ! $referencia_personal_1 || ! $referencia_personal_2 ) {
			wp_send_json_error( array( 'message' => __( 'Please provide at least two personal references.', 'arriendo-facil' ) ) );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid email.', 'arriendo-facil' ) ) );
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
			wp_send_json_error( array( 'message' => __( 'Could not create guest.', 'arriendo-facil' ) ) );
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
				return new WP_Error( 'af_guest_schema_update_failed', __( 'Could not update guests table schema for additional fields.', 'arriendo-facil' ) );
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
		$rental_mode      = isset( $data['rental_mode'] ) ? sanitize_key( $data['rental_mode'] ) : '';

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
			$start_date = $today;
			$end_date   = gmdate( 'Y-m-d', strtotime( '+' . max( 1, $months ) . ' months', strtotime( $today ) ) );
		} elseif ( 'years' === $rental_mode ) {
			$years = isset( $data['rental_years'] ) ? absint( $data['rental_years'] ) : 1;
			$start_date = $today;
			$end_date   = gmdate( 'Y-m-d', strtotime( '+' . max( 1, $years ) . ' years', strtotime( $today ) ) );
		}

		if ( ! $start_date || ! $end_date ) {
			return array( 'generated' => false );
		}

		$monthly_rent = $this->parse_price_amount( isset( $data['desired_price'] ) ? (string) $data['desired_price'] : '' );

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

		$this->send_tenant_processing_email(
			isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '',
			isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '',
			$accommodation_id,
			$lease_id
		);

		$owner_contract_example = $this->get_owner_contract_example_context( $accommodation_id );
		$owner_template_exists  = ! empty( $owner_contract_example['attachment_id'] );
		$accommodation_address  = (string) get_post_meta( $accommodation_id, '_af_address', true );
		$accommodation_title    = (string) get_the_title( $accommodation_id );

		$ai_payload = array(
			'lease_id'          => $lease_id,
			'accommodation_id'  => $accommodation_id,
			'accommodation_title' => $accommodation_title,
			'accommodation_address' => sanitize_text_field( $accommodation_address ),
			'guest_id'          => $guest_id,
			'guest_name'        => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'guest_email'       => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '',
			'guest_phone'       => isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '',
			'guest_id_number'   => isset( $data['id_number'] ) ? sanitize_text_field( $data['id_number'] ) : '',
			'mascotas'          => isset( $data['mascotas'] ) ? absint( $data['mascotas'] ) : 0,
			'referencia_personal_1' => isset( $data['referencia_personal_1'] ) ? sanitize_text_field( $data['referencia_personal_1'] ) : '',
			'referencia_personal_2' => isset( $data['referencia_personal_2'] ) ? sanitize_text_field( $data['referencia_personal_2'] ) : '',
			'personas_viviran'  => isset( $data['personas_viviran'] ) ? absint( $data['personas_viviran'] ) : 0,
			'start_date'        => $start_date,
			'end_date'          => $end_date,
			'monthly_rent'      => $monthly_rent,
			'rental_mode'       => $rental_mode,
			'desired_price'     => isset( $data['desired_price'] ) ? sanitize_text_field( $data['desired_price'] ) : '',
			'guarantee_text'    => isset( $data['guarantee_text'] ) ? sanitize_text_field( $data['guarantee_text'] ) : '',
			'template_available'=> ! empty( $owner_contract_example['attachment_id'] ),
			'template_name'     => isset( $owner_contract_example['file_name'] ) ? sanitize_text_field( (string) $owner_contract_example['file_name'] ) : '',
			'template_mime'     => isset( $owner_contract_example['mime_type'] ) ? sanitize_text_field( (string) $owner_contract_example['mime_type'] ) : '',
			'template_url'      => isset( $owner_contract_example['url'] ) ? esc_url_raw( (string) $owner_contract_example['url'] ) : '',
			'template_text'     => isset( $owner_contract_example['template_text'] ) ? (string) $owner_contract_example['template_text'] : '',
			'owner_user_id'     => isset( $owner_contract_example['owner_user_id'] ) ? absint( $owner_contract_example['owner_user_id'] ) : 0,
			'owner_name'        => isset( $owner_contract_example['owner_name'] ) ? sanitize_text_field( (string) $owner_contract_example['owner_name'] ) : '',
			'owner_email'       => isset( $owner_contract_example['owner_email'] ) ? sanitize_email( (string) $owner_contract_example['owner_email'] ) : '',
		);

		$ai_payload['legal_requirements'] = $this->get_contract_legal_requirements();
		$ai_payload['legal_template_base'] = $this->build_legal_contract_template( $ai_payload, '' );

		$document_result = new WP_Error( 'af_ai_not_executed', __( 'AI document generation was not executed.', 'arriendo-facil' ) );
		if ( class_exists( 'Arriendo_Facil_AI_Service' ) ) {
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
		}

		$generated_contract_text = '';

		// Strict business rule: if owner template exists, always build the final contract from that template.
		if ( $owner_template_exists ) {
			if ( isset( $ai_payload['template_text'] ) && is_string( $ai_payload['template_text'] ) && '' !== trim( $ai_payload['template_text'] ) ) {
				$generated_contract_text = $this->fill_owner_template_with_lease_data( $ai_payload['template_text'], $ai_payload );
			}

			if ( '' === $generated_contract_text && isset( $ai_payload['template_text'] ) && is_string( $ai_payload['template_text'] ) ) {
				$generated_contract_text = trim( (string) $ai_payload['template_text'] );
			}

			if ( '' === $generated_contract_text ) {
				$generated_contract_text = $this->build_owner_template_unreadable_fallback_text( $ai_payload );
			}
		} else {
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

		if ( '' === $document_url && '' !== $generated_contract_text ) {
			try {
				$generated_file_url = $this->create_generated_contract_file( $lease_id, $generated_contract_text, $ai_payload );
				if ( $generated_file_url ) {
					$document_url = $generated_file_url;
				}
			} catch ( Throwable $throwable ) {
				error_log( 'Arriendo Facil contract file generation exception: ' . $throwable->getMessage() );
			}
		}

		if ( '' === $document_url && '' !== $generated_contract_text ) {
			$last_resort_url = $this->create_last_resort_contract_file( $lease_id, $generated_contract_text );
			if ( $last_resort_url ) {
				$document_url = $last_resort_url;
			}
		}

		if ( '' === $document_url && $owner_template_exists && ! empty( $owner_contract_example['url'] ) ) {
			$document_url = esc_url_raw( (string) $owner_contract_example['url'] );
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

		if ( ! $lease_id || ! $attachment_id || ! $template_path || ! file_exists( $template_path ) ) {
			return '';
		}

		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' !== $template_mime && 'docx' !== $template_ext ) {
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

		$file_name = sprintf( 'lease-%d-owner-template-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;

		if ( ! @copy( $template_path, $file_path ) ) {
			return '';
		}

		$this->replace_docx_template_tokens_in_place( $file_path, $payload );
		$this->replace_docx_semantic_label_blanks_in_place( $file_path, $owner_template, $payload );

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
				(string) $xml
			);

			if ( is_string( $updated_xml ) && $updated_xml !== $xml ) {
				$zip->addFromString( $entry_name, $updated_xml );
			}
		}

		$zip->close();
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
	 * Creates a plain-text fallback file when DOC/DOCX generation failed.
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

		$file_name = sprintf( 'lease-%d-contract-%s.txt', $lease_id, gmdate( 'Ymd-His' ) );
		$upload    = wp_upload_bits( $file_name, null, $text );

		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['url'] ) ) {
			return '';
		}

		return esc_url_raw( (string) $upload['url'] );
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
			'Use formal Ecuador rental-contract language suitable for legal review.',
			'Align legal wording with Ecuador regulations applicable in 2026, including Ley de Inquilinato and relevant Civil Code provisions.',
			'Keep numbered clauses with clear obligations for both parties.',
			'Include parties identification (full name and ID number placeholders).',
			'Include lease object, term, monthly rent, payment method and due date.',
			'Include deposit/guarantee clause and property use restrictions.',
			'Include maintenance and utilities responsibilities.',
			'Include termination causes and penalties for breach.',
			'Include jurisdiction and applicable law clause.',
			'Include signature block for landlord and tenant with ID and signature lines.',
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
		$guarantee_text  = isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : 'Garantia equivalente a _____ meses de canon.';
		$mascotas        = isset( $payload['mascotas'] ) ? absint( $payload['mascotas'] ) : 0;
		$personas        = isset( $payload['personas_viviran'] ) ? absint( $payload['personas_viviran'] ) : 0;
		$reference_1     = isset( $payload['referencia_personal_1'] ) ? sanitize_text_field( (string) $payload['referencia_personal_1'] ) : '________________________';
		$reference_2     = isset( $payload['referencia_personal_2'] ) ? sanitize_text_field( (string) $payload['referencia_personal_2'] ) : '________________________';

		if ( '' !== trim( $desired_price ) ) {
			$monthly_rent = $desired_price;
		}

		$city_and_date = sprintf( 'Quito, %s', current_time( 'Y-m-d' ) );
		$extra_clauses = trim( (string) $extra_clauses );

		$contract = "CONTRATO DE ARRENDAMIENTO\n";
		$contract .= "\n";
		$contract .= "Entre los comparecientes, por una parte " . $owner_name . " (ARRENDADOR), con cedula/RUC " . $owner_id . ", y por otra parte " . $guest_name . " (ARRENDATARIO), con cedula " . $guest_id . ", se celebra el presente contrato de arrendamiento de conformidad con la normativa ecuatoriana aplicable.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA PRIMERA - OBJETO\n";
		$contract .= "El ARRENDADOR da en arrendamiento al ARRENDATARIO el inmueble identificado como " . $property . ", ubicado en " . $address . ".\n";
		$contract .= "\n";
		$contract .= "CLAUSULA SEGUNDA - PLAZO\n";
		$contract .= "El plazo de vigencia del presente contrato inicia el " . $start_date . " y termina el " . $end_date . ".\n";
		$contract .= "\n";
		$contract .= "CLAUSULA TERCERA - CANON Y FORMA DE PAGO\n";
		$contract .= "El canon de arrendamiento acordado es de USD " . $monthly_rent . " mensuales, pagaderos dentro de los primeros cinco dias de cada mes, por el medio acordado por las partes.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA CUARTA - GARANTIA\n";
		$contract .= $guarantee_text . "\n";
		$contract .= "\n";
		$contract .= "CLAUSULA QUINTA - DESTINO Y USO\n";
		$contract .= "El inmueble sera destinado exclusivamente para uso habitacional del ARRENDATARIO y su nucleo autorizado, quedando prohibido subarrendar o cambiar el destino sin autorizacion escrita del ARRENDADOR.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA SEXTA - OBLIGACIONES DE LAS PARTES\n";
		$contract .= "El ARRENDATARIO se obliga al pago puntual del canon, cuidado del inmueble y servicios basicos que le correspondan. El ARRENDADOR se obliga a mantener la posesion pacifica y atender reparaciones estructurales que legalmente le correspondan.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA SEPTIMA - TERMINACION\n";
		$contract .= "El contrato podra darse por terminado por vencimiento del plazo, mutuo acuerdo o incumplimiento de obligaciones contractuales y legales, conforme normativa aplicable.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA OCTAVA - JURISDICCION Y LEY APLICABLE\n";
		$contract .= "Para todos los efectos legales, las partes se someten a la jurisdiccion de los jueces competentes del Ecuador y a la Ley de Inquilinato y normas conexas vigentes.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA NOVENA - DATOS DECLARADOS POR EL ARRENDATARIO\n";
		$contract .= "Celular del arrendatario: " . $guest_phone . ". Correo electronico: " . $guest_email . ". Numero de personas que habitaran el inmueble: " . $personas . ". Numero de mascotas declaradas: " . $mascotas . ". Referencia personal 1: " . $reference_1 . ". Referencia personal 2: " . $reference_2 . ".\n";

		if ( '' !== $extra_clauses ) {
			$contract .= "\n";
			$contract .= "CLAUSULA DECIMA - DISPOSICIONES ADICIONALES\n";
			$contract .= $extra_clauses . "\n";
		}

		$contract .= "\n";
		$contract .= "En constancia de conformidad, las partes suscriben el presente contrato en dos ejemplares del mismo tenor.\n";
		$contract .= "\n";
		$contract .= $city_and_date . "\n";
		$contract .= "\n";
		$contract .= "FIRMAS\n";
		$contract .= "\n";
		$contract .= "ARRENDADOR: ________________________\n";
		$contract .= "Nombre: " . $owner_name . "\n";
		$contract .= "Cedula/RUC: " . $owner_id . "\n";
		$contract .= "\n";
		$contract .= "ARRENDATARIO: ________________________\n";
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
		$owner_user_id = absint( get_post_meta( $accommodation_id, '_af_owner_id', true ) );

		if ( ! $owner_user_id ) {
			global $wpdb;
			$owner_user_id = (int) $wpdb->get_var(
				"SELECT CAST(meta_value AS UNSIGNED)
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = '_af_owner_user_id'
				   AND meta_value IS NOT NULL
				   AND meta_value <> ''
				 ORDER BY meta_id DESC
				 LIMIT 1"
			);

			if ( ! $owner_user_id ) {
				return array();
			}
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
			return array();
		}

		return $this->build_contract_template_context_from_attachment( $attachment_id, $owner_user_id );
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
		$template_text = $this->extract_contract_template_text( $path, $mime_type );
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

		if ( in_array( $clean, array( 'FIRMAS', 'ARRENDADOR', 'ARRENDATARIO' ), true ) ) {
			return true;
		}

		if ( 0 === strpos( $clean, 'ARRENDADOR:' ) || 0 === strpos( $clean, 'ARRENDATARIO:' ) ) {
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
			return new WP_Error( 'af_r2_missing_config', __( 'Missing Cloudflare R2 credentials. Check Settings > Cloud Provider.', 'arriendo-facil' ) );
		}

		$parsed = wp_parse_url( $endpoint );
		$host   = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		$scheme = isset( $parsed['scheme'] ) ? (string) $parsed['scheme'] : '';

		if ( '' === $host || '' === $scheme ) {
			return new WP_Error( 'af_r2_invalid_endpoint', __( 'Invalid Cloudflare R2 endpoint URL.', 'arriendo-facil' ) );
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
				return new WP_Error( 'af_guest_pdf_upload_error', __( 'Could not upload one of the guest PDF documents.', 'arriendo-facil' ) );
			}

			if ( ! empty( $file_data['size'] ) && (int) $file_data['size'] > ( 10 * 1024 * 1024 ) ) {
				return new WP_Error( 'af_guest_pdf_upload_too_large', __( 'Guest PDF exceeds maximum size (10 MB).', 'arriendo-facil' ) );
			}

			$checked = wp_check_filetype_and_ext( $file_data['tmp_name'], $file_data['name'], array( 'pdf' => 'application/pdf' ) );
			if ( 'pdf' !== (string) $checked['ext'] ) {
				return new WP_Error( 'af_guest_pdf_invalid_type', __( 'Only PDF files are allowed for guest documents.', 'arriendo-facil' ) );
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
				return new WP_Error( 'af_guest_pdf_save_failed', __( 'Could not save one of the guest PDF documents.', 'arriendo-facil' ) );
			}

			update_post_meta( (int) $attachment_id, '_af_guest_id', (int) $guest_id );
			update_post_meta( (int) $attachment_id, '_af_guest_doc_type', $doc_type );

			$uploaded[ $doc_type ] = (int) $attachment_id;
		}

		return $uploaded;
	}

	/**
	 * Returns a paginated list of guests via AJAX.
	 */
	public function ajax_get_guests() {
		check_ajax_referer( 'af_guest_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$page     = isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;
		$per_page = 20;
		$offset   = ( $page - 1 ) * $per_page;

		global $wpdb;
		$guests = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_guests ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		wp_send_json_success( $guests );
	}

	/**
	 * Scores a guest using the AI service via AJAX.
	 */
	public function ajax_score_guest() {
		check_ajax_referer( 'af_guest_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$guest_id = isset( $_POST['guest_id'] ) ? absint( $_POST['guest_id'] ) : 0;
		if ( ! $guest_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid guest ID.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$guest = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d", $guest_id )
		);

		if ( ! $guest ) {
			wp_send_json_error( array( 'message' => __( 'Guest not found.', 'arriendo-facil' ) ) );
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
