<?php
/**
 * Owner contact management.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Owner_Contact
 *
 * Handles contact messages directed to property owners stored in
 * the af_owner_contacts table.
 */
class Arriendo_Facil_Owner_Contact {

	/**
	 * Last upload error from sensitive document processing.
	 *
	 * @var WP_Error|null
	 */
	private $last_upload_error = null;

	/**
	 * Uploaded sensitive docs keyed by type.
	 *
	 * @var array<string,int>
	 */
	private $uploaded_document_ids = array();

	/**
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_send_owner_contact', array( $this, 'ajax_send_contact' ) );
		add_action( 'wp_ajax_nopriv_af_send_owner_contact', array( $this, 'ajax_send_contact' ) );
		add_action( 'wp_ajax_af_get_owner_contacts', array( $this, 'ajax_get_contacts' ) );
		add_action( 'wp_ajax_af_disable_owner_account', array( $this, 'ajax_disable_owner_account' ) );
		add_action( 'af_owner_contact_saved', array( $this, 'upload_sensitive_documents' ), 10, 2 );
		add_action( 'admin_post_af_disable_owner_account', array( $this, 'handle_disable_owner_account_post' ) );
		add_action( 'after_password_reset', array( $this, 'handle_owner_password_reset' ), 10, 2 );
		add_filter( 'authenticate', array( $this, 'block_disabled_owner_login' ), 30, 3 );
	}

	/**
	 * Handles sending a contact message to an owner via AJAX.
	 */
	public function ajax_send_contact() {
		if ( empty( $_POST ) ) {
			$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
			$post_max_size  = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );

			if ( $content_length > 0 && $post_max_size > 0 && $content_length > $post_max_size ) {
				wp_send_json_error(
					array( 'message' => __( 'Request too large for server limits (post_max_size).', 'arriendo-facil' ) ),
					413
				);
			}

			wp_send_json_error(
				array( 'message' => __( 'Empty request received. Session cookie or server limit issue.', 'arriendo-facil' ) ),
				400
			);
		}

		if ( false === check_ajax_referer( 'af_owner_contact_nonce', 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid or expired nonce. Reload and try again.', 'arriendo-facil' ) ),
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}
		$redirect_to = isset( $_POST['redirect_to'] )
			? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
			: admin_url( 'admin.php?page=af-owner-contacts' );

		$is_xhr = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] )
			&& 'xmlhttprequest' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) );

		$owner_id_type = isset( $_POST['owner_id_type'] ) ? sanitize_key( wp_unslash( $_POST['owner_id_type'] ) ) : '';
		$owner_id_raw  = isset( $_POST['owner_id'] ) ? sanitize_text_field( wp_unslash( $_POST['owner_id'] ) ) : '';
		$owner_id      = $this->normalize_owner_document( $owner_id_type, $owner_id_raw );
		$owner_email   = isset( $_POST['owner_email'] ) ? sanitize_email( wp_unslash( $_POST['owner_email'] ) ) : '';
		$has_legal_agent = isset( $_POST['has_legal_agent'] ) && '1' === wp_unslash( $_POST['has_legal_agent'] ) ? 1 : 0;

		$legal_agent_name_raw    = isset( $_POST['legal_agent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['legal_agent_name'] ) ) : '';
		$legal_agent_id_type     = isset( $_POST['legal_agent_id_type'] ) ? sanitize_key( wp_unslash( $_POST['legal_agent_id_type'] ) ) : '';
		$legal_agent_id_raw      = isset( $_POST['legal_agent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['legal_agent_id'] ) ) : '';
		$legal_agent_phone_raw   = isset( $_POST['legal_agent_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['legal_agent_phone'] ) ) : '';
		$legal_agent_email_raw   = isset( $_POST['legal_agent_email'] ) ? sanitize_email( wp_unslash( $_POST['legal_agent_email'] ) ) : '';
		$legal_agent_name        = trim( $legal_agent_name_raw );
		$legal_agent_id          = $this->normalize_owner_document( $legal_agent_id_type, $legal_agent_id_raw );
		$legal_agent_phone       = preg_replace( '/\D+/', '', trim( $legal_agent_phone_raw ) );
		$legal_agent_email       = trim( $legal_agent_email_raw );

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( ! $this->is_valid_owner_document( $owner_id_type, $owner_id ) || ! is_email( $owner_email ) || ! $subject || ! $message ) {
			if ( $is_xhr ) {
				wp_send_json_error( array( 'message' => __( 'Invalid owner registration data.', 'arriendo-facil' ) ) );
			}
			wp_safe_redirect( $redirect_to );
			exit;
		}

		if ( $has_legal_agent ) {
			$is_numeric_phone = '' !== $legal_agent_phone && 1 === preg_match( '/^[0-9]+$/', $legal_agent_phone );

			$legal_agent_data_valid =
				$legal_agent_name
				&& in_array( $legal_agent_id_type, array( 'cedula', 'ruc', 'pasaporte' ), true )
				&& $this->is_valid_owner_document( $legal_agent_id_type, $legal_agent_id )
				&& $is_numeric_phone
				&& is_email( $legal_agent_email );

			if ( ! $legal_agent_data_valid ) {
				if ( $is_xhr ) {
					wp_send_json_error( array( 'message' => __( 'Invalid legal agent data.', 'arriendo-facil' ) ) );
				}

				wp_safe_redirect( $redirect_to );
				exit;
			}
		} else {
			$legal_agent_name    = '';
			$legal_agent_id_type = '';
			$legal_agent_id      = '';
			$legal_agent_phone   = '';
			$legal_agent_email   = '';
		}

		global $wpdb;
		$existing_contact_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				 FROM {$wpdb->prefix}af_owner_contacts
				 WHERE owner_email = %s
				 ORDER BY id DESC
				 LIMIT 1",
				$owner_email
			)
		);

		$existing_user = get_user_by( 'email', $owner_email );
		if ( $existing_user || $existing_contact_id > 0 ) {
			if ( $is_xhr ) {
				wp_send_json_error( array( 'message' => __( 'This email is already registered.', 'arriendo-facil' ) ) );
			}

			wp_safe_redirect( $redirect_to );
			exit;
		}

		/** User WordPress */
		if ( class_exists( 'Arriendo_Facil_Activator' ) ) {
			Arriendo_Facil_Activator::ensure_owner_role();
		}

		$temp_password_plain = wp_generate_password( 14, true, true );

		$base_login = sanitize_user( current( explode( '@', $owner_email ) ), true );
		$user_login = $this->generate_unique_login( $base_login ? $base_login : 'owner', $owner_id );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $user_login,
				'user_pass'    => $temp_password_plain,
				'user_email'   => $owner_email,
				'display_name' => $subject,
				'role'         => 'af_owner',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			if ( $is_xhr ) {
				wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
			}

			wp_safe_redirect( $redirect_to );
			exit;
		}

		$user = get_user_by( 'id', (int) $user_id );

		if ( ! $user ) {
			if ( $is_xhr ) {
				wp_send_json_error( array( 'message' => __( 'Could not load owner user.', 'arriendo-facil' ) ) );
			}

			wp_safe_redirect( $redirect_to );
			exit;
		}
 /** links for emails */
		$reset_key = get_password_reset_key( $user );
		if ( is_wp_error( $reset_key ) ) {
			if ( $is_xhr ) {
				wp_send_json_error( array( 'message' => $reset_key->get_error_message() ) );
			}

			wp_safe_redirect( $redirect_to );
			exit;
		}

		$reset_url = add_query_arg(
			array(
				'action' => 'rp',
				'key'    => $reset_key,
				'login'  => rawurlencode( $user->user_login ),
			),
			wp_login_url()
		);

		$owner_data = array(
			'owner_id_type'      => $owner_id_type,
			'owner_id'           => $owner_id,
			'owner_email'        => $owner_email,
			'has_legal_agent'    => $has_legal_agent,
			'legal_agent_name'   => $legal_agent_name,
			'legal_agent_id_type'=> $legal_agent_id_type,
			'legal_agent_id'     => $legal_agent_id,
			'legal_agent_phone'  => $legal_agent_phone,
			'legal_agent_email'  => $legal_agent_email,
			'wp_user_id'         => (int) $user->ID,
			'temp_password_hash' => wp_hash_password( $temp_password_plain ),
			'subject'            => $subject,
			'message'            => $message,
			'status'             => 'unread',
		);

		$owner_formats = array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'af_owner_contacts',
			$owner_data,
			$owner_formats
		);

		if ( false !== $inserted ) {
			$contact_id = (int) $wpdb->insert_id;

			$this->last_upload_error = null;
			$this->uploaded_document_ids = array();

			do_action( 'af_owner_contact_saved', $contact_id, (int) $user->ID );

			if ( $this->last_upload_error instanceof WP_Error ) {
				if ( $is_xhr ) {
					wp_send_json_error( array( 'message' => $this->last_upload_error->get_error_message() ) );
				}

				wp_safe_redirect( $redirect_to );
				exit;
			}

			$mail_result = $this->send_owner_activation_email(
				$owner_email,
				$subject,
				$reset_url
			);

			if ( is_wp_error( $mail_result ) ) {
				if ( $is_xhr ) {
					wp_send_json_error(
						array(
							'message' => $mail_result->get_error_message(),
						)
					);
				}

				wp_safe_redirect( $redirect_to );
				exit;
			}

			update_user_meta( (int) $user->ID, 'af_owner_account_status', 'inactive' );

			$contact    = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT *
					 FROM {$wpdb->prefix}af_owner_contacts
					 WHERE id = %d
					 LIMIT 1",
					$contact_id
				),
				ARRAY_A
			);

			if ( $is_xhr ) {
				wp_send_json_success(
					array(
						'id'          => $contact_id,
						'redirect_to' => $redirect_to,
						'contact'     => $contact,
						'account_status' => 'inactive',
						'uploaded_documents' => $this->uploaded_document_ids,
					)
				);
			}

			wp_safe_redirect( $redirect_to );
			exit;
		}

		if ( $is_xhr ) {
			wp_send_json_error(
				array(
					'message' => __( 'Could not register owner.', 'arriendo-facil' ),
					'error'   => $wpdb->last_error,
				)
			);
		}

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Hook callback for uploading sensitive owner documents.
	 *
	 * @param int $contact_id Contact ID.
	 * @param int $user_id    Owner user ID.
	 */
	public function upload_sensitive_documents( $contact_id, $user_id ) {
		$fields = array(
			'owner_bank_statement_pdf'       => 'bank_statement',
			'owner_police_record_pdf'        => 'police_record',
			'owner_additional_sensitive_pdf' => 'additional_sensitive',
		);
		$optional_contract_example_field = 'owner_contract_example_file';
		$optional_contract_doc_type      = 'contract_example';
		$allowed_contract_mimes          = array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		);
		$selected_documents = 0;

		$storage_provider = $this->get_storage_setting( 'AF_STORAGE_PROVIDER', 'af_storage_provider', 'cloudflare_r2' );
		if ( 'cloudflare_r2' !== $storage_provider ) {
			$this->last_upload_error = new WP_Error( 'af_r2_provider_invalid', __( 'Cloud provider is not configured as Cloudflare R2.', 'arriendo-facil' ) );
			return;
		}

		$r2_config = $this->get_r2_config();
		if ( is_wp_error( $r2_config ) ) {
			$this->last_upload_error = $r2_config;
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		foreach ( $fields as $field_name => $doc_type ) {
			if ( ! isset( $_FILES[ $field_name ] ) || ! is_array( $_FILES[ $field_name ] ) ) {
				$this->last_upload_error = new WP_Error( 'af_pdf_upload_required', __( 'You must upload all three required PDF documents.', 'arriendo-facil' ) );
				return;
			}

			$file_data = $_FILES[ $field_name ];
			$file_name = isset( $file_data['name'] ) ? (string) $file_data['name'] : '';
			$file_error = isset( $file_data['error'] ) ? (int) $file_data['error'] : UPLOAD_ERR_NO_FILE;

			if ( UPLOAD_ERR_NO_FILE === $file_error && '' === $file_name ) {
				$this->last_upload_error = new WP_Error( 'af_pdf_upload_required', __( 'You must upload all three required PDF documents.', 'arriendo-facil' ) );
				return;
			}

			$selected_documents++;

			if ( UPLOAD_ERR_INI_SIZE === $file_error || UPLOAD_ERR_FORM_SIZE === $file_error ) {
				$this->last_upload_error = new WP_Error( 'af_pdf_upload_too_large', __( 'The uploaded PDF exceeds the server upload limit.', 'arriendo-facil' ) );
				return;
			}

			if ( UPLOAD_ERR_OK !== (int) $file_data['error'] ) {
				$this->last_upload_error = new WP_Error( 'af_pdf_upload_error', __( 'Could not upload PDF document.', 'arriendo-facil' ) );
				return;
			}

			if ( ! empty( $file_data['size'] ) && (int) $file_data['size'] > ( 10 * 1024 * 1024 ) ) {
				$this->last_upload_error = new WP_Error( 'af_pdf_upload_too_large', __( 'PDF exceeds maximum size (10 MB).', 'arriendo-facil' ) );
				return;
			}

			$checked = wp_check_filetype_and_ext( $file_data['tmp_name'], $file_data['name'], array( 'pdf' => 'application/pdf' ) );
			if ( 'pdf' !== (string) $checked['ext'] ) {
				$this->last_upload_error = new WP_Error( 'af_pdf_upload_invalid_type', __( 'Only PDF files are allowed.', 'arriendo-facil' ) );
				return;
			}

			$attachment_id = media_handle_upload(
				$field_name,
				0,
				array( 'post_title' => sprintf( 'owner-contact-%d-%s', (int) $contact_id, $doc_type ) ),
				array(
					'test_form' => false,
					'mimes'     => array( 'pdf' => 'application/pdf' ),
				)
			);

			if ( is_wp_error( $attachment_id ) ) {
				$this->last_upload_error = new WP_Error( 'af_pdf_upload_failed', __( 'Could not save PDF document.', 'arriendo-facil' ) );
				return;
			}

			update_post_meta( (int) $attachment_id, '_af_sensitive_doc', '1' );
			update_post_meta( (int) $attachment_id, '_af_sensitive_doc_type', $doc_type );
			update_post_meta( (int) $attachment_id, '_af_owner_contact_id', (int) $contact_id );
			update_post_meta( (int) $attachment_id, '_af_owner_user_id', (int) $user_id );

			$r2_upload = $this->upload_attachment_to_r2( (int) $attachment_id, (int) $contact_id, (string) $doc_type, $r2_config );
			if ( is_wp_error( $r2_upload ) ) {
				$this->last_upload_error = $r2_upload;
				return;
			}

			$this->uploaded_document_ids[ $doc_type ] = (int) $attachment_id;
		}

		if ( isset( $_FILES[ $optional_contract_example_field ] ) && is_array( $_FILES[ $optional_contract_example_field ] ) ) {
			$file_data  = $_FILES[ $optional_contract_example_field ];
			$file_name  = isset( $file_data['name'] ) ? (string) $file_data['name'] : '';
			$file_error = isset( $file_data['error'] ) ? (int) $file_data['error'] : UPLOAD_ERR_NO_FILE;

			if ( ! ( UPLOAD_ERR_NO_FILE === $file_error && '' === $file_name ) ) {
				if ( UPLOAD_ERR_INI_SIZE === $file_error || UPLOAD_ERR_FORM_SIZE === $file_error ) {
					$this->last_upload_error = new WP_Error( 'af_contract_example_upload_too_large', __( 'The uploaded contract example exceeds the server upload limit.', 'arriendo-facil' ) );
					return;
				}

				if ( UPLOAD_ERR_OK !== $file_error ) {
					$this->last_upload_error = new WP_Error( 'af_contract_example_upload_error', __( 'Could not upload contract example.', 'arriendo-facil' ) );
					return;
				}

				if ( ! empty( $file_data['size'] ) && (int) $file_data['size'] > ( 10 * 1024 * 1024 ) ) {
					$this->last_upload_error = new WP_Error( 'af_contract_example_upload_too_large', __( 'Contract example exceeds maximum size (10 MB).', 'arriendo-facil' ) );
					return;
				}

				$checked = wp_check_filetype_and_ext( $file_data['tmp_name'], $file_data['name'], $allowed_contract_mimes );
				if ( ! in_array( (string) $checked['ext'], array( 'pdf', 'doc', 'docx' ), true ) ) {
					$this->last_upload_error = new WP_Error( 'af_contract_example_invalid_type', __( 'Contract example must be a PDF or Word file (.pdf, .doc, .docx).', 'arriendo-facil' ) );
					return;
				}

				$attachment_id = media_handle_upload(
					$optional_contract_example_field,
					0,
					array( 'post_title' => sprintf( 'owner-contact-%d-%s', (int) $contact_id, $optional_contract_doc_type ) ),
					array(
						'test_form' => false,
						'mimes'     => $allowed_contract_mimes,
					)
				);

				if ( is_wp_error( $attachment_id ) ) {
					$this->last_upload_error = new WP_Error( 'af_contract_example_upload_failed', __( 'Could not save contract example file.', 'arriendo-facil' ) );
					return;
				}

				update_post_meta( (int) $attachment_id, '_af_owner_contract_example', '1' );
				update_post_meta( (int) $attachment_id, '_af_owner_contact_id', (int) $contact_id );
				update_post_meta( (int) $attachment_id, '_af_owner_user_id', (int) $user_id );

				$r2_upload = $this->upload_attachment_to_r2( (int) $attachment_id, (int) $contact_id, (string) $optional_contract_doc_type, $r2_config );
				if ( is_wp_error( $r2_upload ) ) {
					$this->last_upload_error = $r2_upload;
					return;
				}

				$this->uploaded_document_ids[ $optional_contract_doc_type ] = (int) $attachment_id;
			}
		}

		if ( $selected_documents !== count( $fields ) && ! ( $this->last_upload_error instanceof WP_Error ) ) {
			$this->last_upload_error = new WP_Error( 'af_pdf_upload_required', __( 'You must upload all three required PDF documents.', 'arriendo-facil' ) );
			return;
		}

		if ( count( $this->uploaded_document_ids ) !== $selected_documents && ! ( $this->last_upload_error instanceof WP_Error ) ) {
			$this->last_upload_error = new WP_Error( 'af_pdf_upload_incomplete', __( 'One or more selected PDFs were not uploaded to Cloudflare R2.', 'arriendo-facil' ) );
		}
	}

	/**
	 * Reads storage settings with constant priority.
	 *
	 * @param string $constant_name wp-config constant name.
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
	 * Uploads an attachment to Cloudflare R2 using SigV4.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $contact_id Contact ID.
	 * @param string $doc_type Document type key.
	 * @param array  $r2_config Parsed R2 config.
	 * @return true|WP_Error
	 */
	private function upload_attachment_to_r2( $attachment_id, $contact_id, $doc_type, $r2_config ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'af_r2_file_missing', __( 'Uploaded file is missing on server before R2 transfer.', 'arriendo-facil' ) );
		}

		$contents = file_get_contents( $file_path );
		if ( false === $contents ) {
			return new WP_Error( 'af_r2_file_read_failed', __( 'Could not read file content for R2 transfer.', 'arriendo-facil' ) );
		}

		$original_name = (string) wp_basename( $file_path );
		$safe_name     = sanitize_file_name( $original_name );
		$object_key    = sprintf( 'owner-contacts/%d/%s-%d-%s', (int) $contact_id, sanitize_key( $doc_type ), (int) $attachment_id, $safe_name );

		$mime_type = (string) get_post_mime_type( $attachment_id );
		if ( '' === $mime_type ) {
			$mime_type = 'application/pdf';
		}

		$amz_date       = gmdate( 'Ymd\\THis\\Z' );
		$date_stamp     = gmdate( 'Ymd' );
		$payload_hash   = hash( 'sha256', $contents );
		$canonical_uri  = '/' . rawurlencode( $r2_config['bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );
		$canonical_host = $r2_config['host'];

		$canonical_headers =
			'host:' . $canonical_host . "\n"
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

		$upload_url = $r2_config['endpoint'] . $canonical_uri;

		$response = wp_remote_request(
			$upload_url,
			array(
				'method'  => 'PUT',
				'timeout' => 45,
				'headers' => array(
					'Host'                 => $canonical_host,
					'Content-Type'         => $mime_type,
					'x-amz-date'           => $amz_date,
					'x-amz-content-sha256' => $payload_hash,
					'Authorization'        => $authorization,
				),
				'body'    => $contents,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'af_r2_upload_failed', $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$response_body = (string) wp_remote_retrieve_body( $response );
			$error_detail  = '' !== trim( $response_body ) ? ' ' . wp_strip_all_tags( $response_body ) : '';
			return new WP_Error( 'af_r2_upload_failed', sprintf( '%s (HTTP %d).%s', __( 'Cloudflare R2 rejected the uploaded file.', 'arriendo-facil' ), $status_code, $error_detail ) );
		}

		update_post_meta( (int) $attachment_id, '_af_r2_uploaded', '1' );
		update_post_meta( (int) $attachment_id, '_af_r2_object_key', $object_key );
		update_post_meta( (int) $attachment_id, '_af_r2_bucket', $r2_config['bucket'] );
		update_post_meta( (int) $attachment_id, '_af_r2_uploaded_at', current_time( 'mysql' ) );

		return true;
	}

	/**
	 * Builds AWS Signature V4 signing key.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $date_stamp Date stamp (Ymd).
	 * @param string $region Region (auto for R2).
	 * @param string $service Service (s3).
	 * @return string
	 */
	private function get_aws_v4_signing_key( $secret_key, $date_stamp, $region, $service ) {
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );

		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}

	/**
	 * Returns all contacts for the current owner via AJAX.
	 */
	public function ajax_get_contacts() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$owner_id_type = isset( $_GET['owner_id_type'] ) ? sanitize_key( wp_unslash( $_GET['owner_id_type'] ) ) : '';
		$owner_id_raw  = isset( $_GET['owner_id'] ) ? sanitize_text_field( wp_unslash( $_GET['owner_id'] ) ) : '';
		$owner_id      = $this->normalize_owner_document( $owner_id_type, $owner_id_raw );

		if ( ! $this->is_valid_owner_document( $owner_id_type, $owner_id ) ) {
			wp_send_json_success( array() );
		}

		global $wpdb;
		$contacts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_owner_contacts
				 WHERE owner_id_type = %s AND owner_id = %s
				 ORDER BY created_at DESC",
				$owner_id_type,
				$owner_id
			)
		);

		wp_send_json_success( $contacts );
	}

	/**
	 * Disables an owner account without deleting the user.
	 */
	public function ajax_disable_owner_account() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid user ID.', 'arriendo-facil' ) ) );
		}

		$result = $this->disable_owner_account_internal( $user_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handles owner account disable action through a normal admin POST request.
	 */
	public function handle_disable_owner_account_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'arriendo-facil' ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( ! $user_id ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'      => 'af-owner-contacts',
						'af_notice' => 'owner_disable_error',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		check_admin_referer( 'af_disable_owner_account_' . $user_id, 'af_disable_owner_account_nonce' );

		$result = $this->disable_owner_account_internal( $user_id );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'af-owner-contacts',
						'af_notice'  => 'owner_disable_error',
						'af_message' => rawurlencode( $result->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'af-owner-contacts',
					'af_notice' => 'owner_disabled',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Applies all persistence changes needed to disable an owner account.
	 *
	 * @param int $user_id User ID.
	 * @return array|WP_Error
	 */
	private function disable_owner_account_internal( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'af_owner_not_found', __( 'User not found.', 'arriendo-facil' ) );
		}

		update_user_meta( $user_id, 'af_owner_account_status', 'disabled' );

		global $wpdb;
		$table          = $wpdb->prefix . 'af_owner_contacts';
		$contacts_query = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s
				 WHERE wp_user_id = %d",
				'inactive',
				$user_id
			)
		);

		if ( false === $contacts_query ) {
			return new WP_Error( 'af_owner_disable_contact_update_failed', __( 'Could not update contact status.', 'arriendo-facil' ) );
		}

		if ( class_exists( 'WP_Session_Tokens' ) ) {
			$tokens = WP_Session_Tokens::get_instance( $user_id );
			if ( $tokens ) {
				$tokens->destroy_all();
			}
		}

		return array(
			'user_id'        => $user_id,
			'contact_status' => 'inactive',
			'account_status' => 'disabled',
		);
	}

	/**
	 * Activates owner account status after successful password reset.
	 *
	 * @param WP_User $user User whose password was reset.
	 * @param string  $new_pass New password value.
	 */
	public function handle_owner_password_reset( $user, $new_pass ) {
		if ( ! $user || empty( $user->ID ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_owner_contacts';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s,
				     temp_password_hash = NULL
				 WHERE wp_user_id = %d",
				'read',
				(int) $user->ID
			)
		);

		$current_status = get_user_meta( (int) $user->ID, 'af_owner_account_status', true );
		if ( 'disabled' !== $current_status ) {
			update_user_meta( (int) $user->ID, 'af_owner_account_status', 'active' );
		}
	}

	/**
	 * Blocks authentication for disabled owner accounts.
	 *
	 * @param WP_User|WP_Error|null $user     Authenticated user object.
	 * @param string                $username Login username.
	 * @param string                $password Login password.
	 * @return WP_User|WP_Error|null
	 */
	public function block_disabled_owner_login( $user, $username, $password ) {
		if ( $user instanceof WP_Error || ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		$account_status = get_user_meta( (int) $user->ID, 'af_owner_account_status', true );
		if ( 'disabled' === $account_status ) {
			return new WP_Error(
				'af_owner_account_disabled',
				__( '<strong>Error:</strong> Your account is disabled. Please contact support.', 'arriendo-facil' )
			);
		}

		return $user;
	}

	/**
	 * Sends the activation email with retries and HTML formatting.
	 *
	 * @param string $owner_email Owner email address.
	 * @param string $owner_name  Owner display name.
	 * @param string $reset_url   One-time password reset URL.
	 * @return true|WP_Error
	 */
	private function send_owner_activation_email( $owner_email, $owner_name, $reset_url ) {
		$mail_subject = __( 'Activa tu cuenta en Arriendo Facil', 'arriendo-facil' );

		$safe_name     = esc_html( $owner_name );
		$safe_email    = esc_html( $owner_email );
		$safe_reset    = esc_url( $reset_url );

		$mail_body =
			'<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.6;color:#111;max-width:640px">'
			. '<p>Hola ' . $safe_name . ',</p>'
			. '<p>Te damos la bienvenida a Arriendo Facil.</p>'
			. '<p>Hemos creado tu cuenta exitosamente. Para comenzar a utilizar el sistema, es necesario que actives tu cuenta y establezcas tu contrasena.</p>'
			. '<p><strong>Usuario:</strong> ' . $safe_email . '</p>'
			. '<p>Por favor, haz clic en el siguiente enlace:</p>'
			. '<p><a href="' . $safe_reset . '" style="display:inline-block;background:#0f766e;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:700">Activar mi cuenta</a></p>'
			. '<p>Si el boton no funciona, copia y pega este enlace en tu navegador:<br><a href="' . $safe_reset . '">' . $safe_reset . '</a></p>'
			. '<p>Por seguridad, este enlace es de uso unico y puede tener un tiempo de expiracion.</p>'
			. '<p>Si no solicitaste la creacion de esta cuenta, puedes ignorar este mensaje sin ningun problema.</p>'
			. '<p>Saludos cordiales,<br>Equipo de Arriendo Facil</p>'
			. '</div>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$max_attempts = 3;
		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$sent = wp_mail( $owner_email, $mail_subject, $mail_body, $headers );
			if ( true === $sent ) {
				return true;
			}

			if ( $attempt < $max_attempts ) {
				sleep( 1 );
			}
		}

		error_log( sprintf( 'Arriendo Facil: failed to send owner activation email to %s after %d attempts.', $owner_email, $max_attempts ) );

		return new WP_Error(
			'af_owner_email_failed',
			__( 'No se pudo enviar el correo de activacion. Intenta nuevamente en unos segundos.', 'arriendo-facil' )
		);
	}

	private function find_owner_email_by_document( $type, $value ) {
		$meta_keys = array();

		if ( 'cedula' === $type ) {
			$meta_keys = array( 'cedula', 'id_number', 'af_cedula', 'document_id' );
		} elseif ( 'ruc' === $type ) {
			$meta_keys = array( 'ruc', 'tax_id', 'id_number', 'document_id' );
		} elseif ( 'pasaporte' === $type ) {
			$meta_keys = array( 'pasaporte', 'passport', 'document', 'document_id' );
		}

		foreach ( $meta_keys as $meta_key ) {
			$users = get_users(
				array(
					'number'     => 1,
					'fields'     => array( 'user_email' ),
					'meta_key'   => $meta_key,
					'meta_value' => $value,
				)
			);

			if ( ! empty( $users ) && ! empty( $users[0]->user_email ) ) {
				return sanitize_email( $users[0]->user_email );
			}
		}

		return '';
	}

	private function is_valid_owner_document( $type, $value ) {
		if ( 'cedula' === $type ) {
			return 1 === preg_match( '/^[0-9]{10}$/', $value );
		}

		if ( 'ruc' === $type ) {
			return 1 === preg_match( '/^[0-9]{13}$/', $value );
		}

		if ( 'pasaporte' === $type ) {
			return 1 === preg_match( '/^[A-Za-z0-9]{6,15}$/', $value );
		}

		return false;
	}

	private function normalize_owner_document( $type, $value ) {
		$value = trim( (string) $value );

		if ( 'cedula' === $type || 'ruc' === $type ) {
			return preg_replace( '/\D+/', '', $value );
		}

		if ( 'pasaporte' === $type ) {
			return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $value ) );
		}

		return $value;
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
}
