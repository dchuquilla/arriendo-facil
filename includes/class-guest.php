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
		check_ajax_referer( 'af_guest_frontend_nonce', 'nonce' );

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

			wp_send_json_success(
				array(
					'id'      => $guest_id,
					'message' => __( 'Registro enviado. Pronto nos contactaremos contigo.', 'arriendo-facil' ),
					'contract' => $contract_info,
				)
			);
		}

		wp_send_json_error( array( 'message' => __( 'No se pudo registrar tu solicitud de arriendo.', 'arriendo-facil' ) ) );
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

		if ( ! class_exists( 'Arriendo_Facil_AI_Service' ) ) {
			return array( 'generated' => false, 'lease_id' => $lease_id );
		}

		$owner_contract_example = $this->get_owner_contract_example_context( $accommodation_id );
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

		$ai = new Arriendo_Facil_AI_Service();
		$document_result = $ai->generate_document( $ai_payload );

		$generated_contract_text = '';
		if ( ! is_wp_error( $document_result ) && isset( $document_result['contract_text'] ) && is_string( $document_result['contract_text'] ) ) {
			$generated_contract_text = trim( wp_strip_all_tags( $document_result['contract_text'] ) );
		}

		if ( '' === $generated_contract_text && isset( $ai_payload['template_text'] ) && is_string( $ai_payload['template_text'] ) && '' !== trim( $ai_payload['template_text'] ) ) {
			$generated_contract_text = $ai_payload['template_text'];
		}

		if ( '' === $generated_contract_text ) {
			$generated_contract_text = $this->build_fallback_contract_text( $ai_payload );
		}

		$generated_contract_text = $this->normalize_generated_legal_contract_text( $generated_contract_text, $ai_payload );

		$document_url = '';
		if ( ! is_wp_error( $document_result ) && isset( $document_result['document_url'] ) && is_string( $document_result['document_url'] ) ) {
			$document_url = esc_url_raw( $document_result['document_url'] );
		}

		if ( '' === $document_url && '' !== $generated_contract_text ) {
			$generated_file_url = $this->create_generated_contract_file( $lease_id, $generated_contract_text );
			if ( $generated_file_url ) {
				$document_url = $generated_file_url;
			}
		}

		if ( $document_url && class_exists( 'Arriendo_Facil_Lease' ) ) {
			$lease_service = new Arriendo_Facil_Lease();
			$lease_service->attach_document( $lease_id, $document_url );
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
		$property        = isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '________________________';
		$address         = isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '________________________';
		$start_date      = isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '________________________';
		$end_date        = isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '________________________';
		$monthly_rent    = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '0.00';
		$desired_price   = isset( $payload['desired_price'] ) ? sanitize_text_field( (string) $payload['desired_price'] ) : '';
		$guarantee_text  = isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : 'Garantia equivalente a _____ meses de canon.';

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

		if ( '' !== $extra_clauses ) {
			$contract .= "\n";
			$contract .= "CLAUSULA NOVENA - DISPOSICIONES ADICIONALES\n";
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

		$lower_text = strtolower( $contract_text );
		$has_title  = false !== strpos( $lower_text, 'contrato de arrendamiento' );
		$has_clause = false !== strpos( $lower_text, 'clausula' );
		$has_sign   = false !== strpos( $lower_text, 'firma' ) || false !== strpos( $lower_text, 'arrendatario:' );

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
	 * Finds the latest contract example uploaded by the accommodation owner.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return array<string,mixed>
	 */
	private function get_owner_contract_example_context( $accommodation_id ) {
		$owner_user_id = absint( get_post_meta( $accommodation_id, '_af_owner_id', true ) );

		if ( ! $owner_user_id ) {
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
			return array();
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

		if ( false !== strpos( $mime_type, 'text/' ) ) {
			$content = file_get_contents( $file_path );
			if ( false !== $content ) {
				return $this->limit_template_text( wp_strip_all_tags( (string) $content ) );
			}

			return '';
		}

		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime_type && class_exists( 'ZipArchive' ) ) {
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
	 * @return string
	 */
	private function create_generated_contract_file( $lease_id, $contract_text ) {
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

		if ( ! $this->write_contract_docx_file( $file_path, $contract_text ) ) {
			return '';
		}

		$local_url     = trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name );
		$document_url  = $local_url;
		$storage_meta  = array(
			'provider'  => 'local',
			'file_name' => $file_name,
			'local_url' => $local_url,
			'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
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
						'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
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
							'mime_type'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
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
	 * Writes a minimal DOCX file from plain contract text.
	 *
	 * @param string $file_path Destination file path.
	 * @param string $contract_text Contract text.
	 * @return bool
	 */
	private function write_contract_docx_file( $file_path, $contract_text ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$escaped_lines = array();
		$lines         = preg_split( '/\r\n|\r|\n/', (string) $contract_text );
		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $contract_text );
		}

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				$escaped_lines[] = '<w:p/>';
				continue;
			}

			$escaped_lines[] = '<w:p><w:r><w:t xml:space="preserve">' . esc_xml( $line ) . '</w:t></w:r></w:p>';
		}

		if ( empty( $escaped_lines ) ) {
			$escaped_lines[] = '<w:p><w:r><w:t xml:space="preserve">Contrato</w:t></w:r></w:p>';
		}

		$document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:w10="urn:schemas-microsoft-com:office:word" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml" xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup" xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk" xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml" xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" mc:Ignorable="w14 w15 wp14">'
			. '<w:body>' . implode( '', $escaped_lines ) . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>';

		$content_types_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '</Types>';

		$rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			. '</Relationships>';

		$doc_rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

		$zip->addFromString( '[Content_Types].xml', $content_types_xml );
		$zip->addFromString( '_rels/.rels', $rels_xml );
		$zip->addFromString( 'word/document.xml', $document_xml );
		$zip->addFromString( 'word/_rels/document.xml.rels', $doc_rels_xml );

		return $zip->close();
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
