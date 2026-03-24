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
		/*add_action( 'wp_ajax_nopriv_af_send_owner_contact', array( $this, 'ajax_send_contact' ) );*/ // Only logged-in users can send contacts for now.
		add_action( 'wp_ajax_af_get_owner_contacts', array( $this, 'ajax_get_contacts' ) );
		add_action( 'wp_ajax_af_disable_owner_account', array( $this, 'ajax_disable_owner_account' ) );
		add_action( 'af_owner_contact_saved', array( $this, 'upload_sensitive_documents' ), 10, 2 );
		add_filter( 'as3cf_upload_acl', array( $this, 'force_private_acl_for_sensitive_docs' ), 10, 2 );
		add_action( 'admin_post_af_disable_owner_account', array( $this, 'handle_disable_owner_account_post' ) );
		add_action( 'after_password_reset', array( $this, 'handle_owner_password_reset' ), 10, 2 );
		add_filter( 'authenticate', array( $this, 'block_disabled_owner_login' ), 30, 3 );
	}

	/**
	 * Handles sending a contact message to an owner via AJAX.
	 */
	public function ajax_send_contact() {
		check_ajax_referer( 'af_owner_contact_nonce', 'nonce' );

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
 /** User WordPress */
		$user               = get_user_by( 'email', $owner_email );
		$temp_password_plain = wp_generate_password( 14, true, true );

		if ( ! $user ) {
			$base_login = sanitize_user( current( explode( '@', $owner_email ) ), true );
			$user_login = $this->generate_unique_login( $base_login ? $base_login : 'owner', $owner_id );

			$user_id = wp_insert_user(
				array(
					'user_login'   => $user_login,
					'user_pass'    => $temp_password_plain,
					'user_email'   => $owner_email,
					'display_name' => $subject,
					'role'         => 'subscriber',
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
		} else {
			$user_id = (int) $user->ID;
			wp_set_password( $temp_password_plain, $user_id );
			$user = get_user_by( 'id', $user_id );
		}

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

		if ( $existing_contact_id > 0 ) {
			$inserted = $wpdb->update(
				$wpdb->prefix . 'af_owner_contacts',
				$owner_data,
				array( 'id' => $existing_contact_id ),
				$owner_formats,
				array( '%d' )
			);
		} else {
			$inserted = $wpdb->insert(
				$wpdb->prefix . 'af_owner_contacts',
				$owner_data,
				$owner_formats
			);
		}

		if ( false !== $inserted ) {
			$contact_id = $existing_contact_id > 0 ? $existing_contact_id : (int) $wpdb->insert_id;

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

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		foreach ( $fields as $field_name => $doc_type ) {
			if ( empty( $_FILES[ $field_name ]['name'] ) ) {
				continue;
			}

			$file_data = $_FILES[ $field_name ];
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

			$this->uploaded_document_ids[ $doc_type ] = (int) $attachment_id;
		}
	}

	/**
	 * Offload hook: enforce private ACL for sensitive documents.
	 *
	 * @param string $acl ACL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function force_private_acl_for_sensitive_docs( $acl, $attachment_id ) {
		if ( '1' === get_post_meta( (int) $attachment_id, '_af_sensitive_doc', true ) ) {
			return 'private';
		}

		return $acl;
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
