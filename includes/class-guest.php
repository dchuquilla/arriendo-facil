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
		add_action( 'wp_ajax_af_get_guests', array( $this, 'ajax_get_guests' ) );
		add_action( 'wp_ajax_af_score_guest', array( $this, 'ajax_score_guest' ) );
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
			wp_send_json_error( array( 'message' => __( 'ID (cedula o pasaporte) must contain only numbers with a maximum of 10 digits.', 'arriendo-facil' ) ) );
		}

		if ( $mascotas < 1 || $mascotas > 10 ) {
			wp_send_json_error( array( 'message' => __( 'Mascotas must be between 1 and 10.', 'arriendo-facil' ) ) );
		}

		if ( $personas_viviran < 1 || $personas_viviran > 10 ) {
			wp_send_json_error( array( 'message' => __( 'Cuantas personas van a vivir o ingresar en la propiedad? must be between 1 and 10.', 'arriendo-facil' ) ) );
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
				'mascotas'   => $mascotas,
				'referencia_personal_1' => $referencia_personal_1,
				'referencia_personal_2' => $referencia_personal_2,
				'personas_viviran'      => $personas_viviran,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
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
