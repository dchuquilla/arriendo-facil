<?php
/**
 * Owner Registration REST API (public endpoint).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Owner_Register_API {

	private $uploaded_document_ids = array();

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'af/v1',
			'/owner-register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_register' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_register( $request ) {
		$nonce = $request->get_param( 'nonce' );
		if ( ! $nonce || false === wp_verify_nonce( $nonce, 'af_owner_register' ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Invalid or expired security token. Please reload and try again.', 'arriendo-facil' ) ),
				403
			);
		}

		$ip = $this->get_client_ip();
		$rate_key = 'af_owner_reg_' . md5( $ip );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 5 ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Too many registration attempts. Please try again in a few minutes.', 'arriendo-facil' ) ),
				429
			);
		}
		set_transient( $rate_key, $attempts + 1, 5 * MINUTE_IN_SECONDS );

		$owner_id_type   = sanitize_key( $request->get_param( 'id_type' ) );
		$owner_id_raw    = sanitize_text_field( $request->get_param( 'id_number' ) );
		$owner_id        = $this->normalize_document( $owner_id_type, $owner_id_raw );
		$client_name     = sanitize_text_field( $request->get_param( 'client_name' ) );
		$owner_email     = sanitize_email( $request->get_param( 'email' ) );
		$observations    = sanitize_textarea_field( $request->get_param( 'observations' ) );
		$has_legal_agent = 'yes' === $request->get_param( 'has_legal_agent' ) ? 1 : 0;

		if ( ! in_array( $owner_id_type, array( 'cedula', 'ruc', 'pasaporte' ), true ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Invalid document type.', 'arriendo-facil' ) ),
				400
			);
		}

		if ( ! $this->is_valid_document( $owner_id_type, $owner_id ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Invalid document number.', 'arriendo-facil' ) ),
				400
			);
		}

		if ( ! $client_name ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Client name is required.', 'arriendo-facil' ) ),
				400
			);
		}

		if ( ! is_email( $owner_email ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'A valid email is required.', 'arriendo-facil' ) ),
				400
			);
		}

		if ( ! $observations ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Observations field is required.', 'arriendo-facil' ) ),
				400
			);
		}

		$legal_agent_name    = '';
		$legal_agent_id_type = '';
		$legal_agent_id      = '';
		$legal_agent_phone   = '';
		$legal_agent_email   = '';

		if ( $has_legal_agent ) {
			$legal_agent_name    = sanitize_text_field( $request->get_param( 'legal_agent_name' ) );
			$legal_agent_id_type = sanitize_key( $request->get_param( 'legal_agent_id_type' ) );
			$legal_agent_id_raw  = sanitize_text_field( $request->get_param( 'legal_agent_id_number' ) );
			$legal_agent_id      = $this->normalize_document( $legal_agent_id_type, $legal_agent_id_raw );
			$legal_agent_phone   = preg_replace( '/\D+/', '', sanitize_text_field( $request->get_param( 'legal_agent_phone' ) ) );
			$legal_agent_email   = sanitize_email( $request->get_param( 'legal_agent_email' ) );

			if ( ! $legal_agent_name
				|| ! in_array( $legal_agent_id_type, array( 'cedula', 'ruc', 'pasaporte' ), true )
				|| ! $this->is_valid_document( $legal_agent_id_type, $legal_agent_id )
				|| ! $legal_agent_phone
				|| ! is_email( $legal_agent_email )
			) {
				return new WP_REST_Response(
					array( 'success' => false, 'message' => __( 'Invalid legal agent data. All fields are required.', 'arriendo-facil' ) ),
					400
				);
			}
		}

		global $wpdb;
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}af_owner_contacts WHERE owner_email = %s LIMIT 1",
				$owner_email
			)
		);

		if ( $existing || get_user_by( 'email', $owner_email ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'This email is already registered.', 'arriendo-facil' ) ),
				409
			);
		}

		if ( class_exists( 'Arriendo_Facil_Activator' ) ) {
			Arriendo_Facil_Activator::ensure_owner_role();
		}

		$temp_password = wp_generate_password( 14, true, true );
		$base_login    = sanitize_user( current( explode( '@', $owner_email ) ), true );
		$user_login    = $this->generate_unique_login( $base_login ? $base_login : 'owner', $owner_id );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $user_login,
				'user_pass'    => $temp_password,
				'user_email'   => $owner_email,
				'display_name' => $client_name,
				'role'         => 'af_owner',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $user_id->get_error_message() ),
				500
			);
		}

		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Could not load created user.', 'arriendo-facil' ) ),
				500
			);
		}

		$reset_key = get_password_reset_key( $user );
		if ( is_wp_error( $reset_key ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $reset_key->get_error_message() ),
				500
			);
		}

		$owner_data = array(
			'owner_id_type'       => $owner_id_type,
			'owner_id'            => $owner_id,
			'owner_email'         => $owner_email,
			'has_legal_agent'     => $has_legal_agent,
			'legal_agent_name'    => $legal_agent_name,
			'legal_agent_id_type' => $legal_agent_id_type,
			'legal_agent_id'      => $legal_agent_id,
			'legal_agent_phone'   => $legal_agent_phone,
			'legal_agent_email'   => $legal_agent_email,
			'wp_user_id'          => (int) $user->ID,
			'temp_password_hash'  => wp_hash_password( $temp_password ),
			'subject'             => $client_name,
			'message'             => $observations,
			'status'              => 'unread',
		);

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'af_owner_contacts',
			$owner_data,
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_delete_user( (int) $user->ID );
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Could not save registration.', 'arriendo-facil' ) ),
				500
			);
		}

		$contact_id = (int) $wpdb->insert_id;

		$upload_error = $this->process_file_uploads( $contact_id, (int) $user->ID );
		if ( is_wp_error( $upload_error ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $upload_error->get_error_message() ),
				400
			);
		}

		update_user_meta( (int) $user->ID, 'af_owner_account_status', 'inactive' );

		$reset_url = add_query_arg(
			array(
				'action' => 'rp',
				'key'    => $reset_key,
				'login'  => rawurlencode( $user->user_login ),
			),
			wp_login_url()
		);

		$owner_contact = new Arriendo_Facil_Owner_Contact();
		$mail_result = $owner_contact->send_owner_activation_email( $owner_email, $client_name, $reset_url );
		if ( is_wp_error( $mail_result ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $mail_result->get_error_message() ),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'success'    => true,
				'message'    => __( 'Registration successful. Please check your email to activate your account.', 'arriendo-facil' ),
				'contact_id' => $contact_id,
			),
			201
		);
	}

	private function process_file_uploads( $contact_id, $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$pdf_fields = array(
			'doc_servicios_basicos' => 'servicios_basicos',
			'doc_identidad'         => 'identidad',
			'doc_contratos'         => 'contratos',
		);

		foreach ( $pdf_fields as $field_name => $doc_type ) {
			if ( ! isset( $_FILES[ $field_name ] ) || ! is_array( $_FILES[ $field_name ] ) ) {
				continue;
			}

			$file_data = $_FILES[ $field_name ];
			if ( UPLOAD_ERR_NO_FILE === (int) $file_data['error'] ) {
				continue;
			}

			$error = $this->validate_and_upload_pdf( $field_name, $file_data, $contact_id, $user_id, $doc_type );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}

		if ( isset( $_FILES['doc_contrato_ejemplo'] ) && is_array( $_FILES['doc_contrato_ejemplo'] ) ) {
			$file_data = $_FILES['doc_contrato_ejemplo'];
			if ( UPLOAD_ERR_NO_FILE !== (int) $file_data['error'] ) {
				$error = $this->validate_and_upload_docx( 'doc_contrato_ejemplo', $file_data, $contact_id, $user_id );
				if ( is_wp_error( $error ) ) {
					return $error;
				}
			}
		}

		return true;
	}

	private function validate_and_upload_pdf( $field_name, $file_data, $contact_id, $user_id, $doc_type ) {
		if ( UPLOAD_ERR_INI_SIZE === (int) $file_data['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file_data['error'] ) {
			return new WP_Error( 'af_upload_too_large', __( 'The uploaded file exceeds the size limit.', 'arriendo-facil' ) );
		}

		if ( UPLOAD_ERR_OK !== (int) $file_data['error'] ) {
			return new WP_Error( 'af_upload_error', __( 'File upload failed.', 'arriendo-facil' ) );
		}

		if ( ! empty( $file_data['size'] ) && (int) $file_data['size'] > ( 5 * 1024 * 1024 ) ) {
			return new WP_Error( 'af_upload_too_large', __( 'File exceeds maximum size (5 MB).', 'arriendo-facil' ) );
		}

		$checked = wp_check_filetype_and_ext( $file_data['tmp_name'], $file_data['name'], array( 'pdf' => 'application/pdf' ) );
		if ( 'pdf' !== (string) $checked['ext'] ) {
			return new WP_Error( 'af_upload_invalid_type', __( 'Only PDF files are allowed for this field.', 'arriendo-facil' ) );
		}

		$attachment_id = media_handle_upload(
			$field_name,
			0,
			array( 'post_title' => sprintf( 'owner-register-%d-%s', $contact_id, $doc_type ) ),
			array( 'test_form' => false, 'mimes' => array( 'pdf' => 'application/pdf' ) )
		);

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'af_upload_failed', __( 'Could not save uploaded file.', 'arriendo-facil' ) );
		}

		update_post_meta( (int) $attachment_id, '_af_sensitive_doc', '1' );
		update_post_meta( (int) $attachment_id, '_af_sensitive_doc_type', $doc_type );
		update_post_meta( (int) $attachment_id, '_af_owner_contact_id', $contact_id );
		update_post_meta( (int) $attachment_id, '_af_owner_user_id', $user_id );

		$this->uploaded_document_ids[ $doc_type ] = (int) $attachment_id;
		return true;
	}

	private function validate_and_upload_docx( $field_name, $file_data, $contact_id, $user_id ) {
		if ( UPLOAD_ERR_INI_SIZE === (int) $file_data['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file_data['error'] ) {
			return new WP_Error( 'af_upload_too_large', __( 'The uploaded file exceeds the size limit.', 'arriendo-facil' ) );
		}

		if ( UPLOAD_ERR_OK !== (int) $file_data['error'] ) {
			return new WP_Error( 'af_upload_error', __( 'File upload failed.', 'arriendo-facil' ) );
		}

		if ( ! empty( $file_data['size'] ) && (int) $file_data['size'] > ( 5 * 1024 * 1024 ) ) {
			return new WP_Error( 'af_upload_too_large', __( 'File exceeds maximum size (5 MB).', 'arriendo-facil' ) );
		}

		$allowed_mimes = array( 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
		$checked = wp_check_filetype_and_ext( $file_data['tmp_name'], $file_data['name'], $allowed_mimes );
		if ( 'docx' !== (string) $checked['ext'] ) {
			return new WP_Error( 'af_upload_invalid_type', __( 'Only Word (.docx) files are allowed for the contract template.', 'arriendo-facil' ) );
		}

		$attachment_id = media_handle_upload(
			$field_name,
			0,
			array( 'post_title' => sprintf( 'owner-register-%d-contract-example', $contact_id ) ),
			array( 'test_form' => false, 'mimes' => $allowed_mimes )
		);

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'af_upload_failed', __( 'Could not save uploaded contract file.', 'arriendo-facil' ) );
		}

		update_post_meta( (int) $attachment_id, '_af_sensitive_doc', '1' );
		update_post_meta( (int) $attachment_id, '_af_sensitive_doc_type', 'contract_example' );
		update_post_meta( (int) $attachment_id, '_af_owner_contract_example', '1' );
		update_post_meta( (int) $attachment_id, '_af_owner_contact_id', $contact_id );
		update_post_meta( (int) $attachment_id, '_af_owner_user_id', $user_id );

		if ( class_exists( 'Arriendo_Facil_DOCX_Template_Processor' ) ) {
			$raw_tpl_path = get_attached_file( (int) $attachment_id );
			if ( $raw_tpl_path && file_exists( $raw_tpl_path ) ) {
				$tpl_processor = new Arriendo_Facil_DOCX_Template_Processor();
				$upload_ai_service = class_exists( 'Arriendo_Facil_AI_Service' ) ? new Arriendo_Facil_AI_Service() : null;
				$processed_path = $tpl_processor->process_owner_template( $raw_tpl_path, $upload_ai_service );
				if ( '' !== $processed_path ) {
					update_post_meta( (int) $attachment_id, '_af_processed_template_path', $processed_path );
				}

				if ( Arriendo_Facil_DOCX_Template_Processor::is_pandoc_available() ) {
					$md_path = $tpl_processor->convert_and_store_markdown( $raw_tpl_path, (int) $attachment_id );
					if ( '' !== $md_path ) {
						update_post_meta( (int) $attachment_id, '_af_template_markdown_path', $md_path );
					}
				}
			}
		}

		$this->uploaded_document_ids['contract_example'] = (int) $attachment_id;
		return true;
	}

	private function normalize_document( $type, $raw ) {
		$raw = preg_replace( '/[\s\-]/', '', trim( $raw ) );
		if ( 'ruc' === $type || 'cedula' === $type ) {
			$raw = preg_replace( '/\D/', '', $raw );
		}
		return $raw;
	}

	private function is_valid_document( $type, $value ) {
		if ( ! $value ) {
			return false;
		}
		if ( 'cedula' === $type ) {
			return (bool) preg_match( '/^\d{10}$/', $value );
		}
		if ( 'ruc' === $type ) {
			return (bool) preg_match( '/^\d{13}$/', $value );
		}
		if ( 'pasaporte' === $type ) {
			return strlen( $value ) >= 5 && strlen( $value ) <= 15;
		}
		return false;
	}

	private function generate_unique_login( $base_login, $owner_id ) {
		$base_login = sanitize_user( (string) $base_login, true );
		if ( '' === $base_login ) {
			$base_login = 'owner';
		}

		$candidate = $base_login;
		if ( username_exists( $candidate ) ) {
			$candidate = sanitize_user( $base_login . '_' . $owner_id, true );
		}

		$suffix = 1;
		while ( username_exists( $candidate ) ) {
			$candidate = sanitize_user( $base_login . '_' . $suffix, true );
			$suffix++;
		}

		return $candidate;
	}

	private function get_client_ip() {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( current( explode( ',', $ip ) ) );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
