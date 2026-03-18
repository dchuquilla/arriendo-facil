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
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_send_owner_contact', array( $this, 'ajax_send_contact' ) );
		/*add_action( 'wp_ajax_nopriv_af_send_owner_contact', array( $this, 'ajax_send_contact' ) );*/ // Only logged-in users can send contacts for now.
		add_action( 'wp_ajax_af_get_owner_contacts', array( $this, 'ajax_get_contacts' ) );
		add_action( 'after_password_reset', array( $this, 'handle_owner_password_reset' ), 10, 2 );
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

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( ! $this->is_valid_owner_document( $owner_id_type, $owner_id ) || ! is_email( $owner_email ) || ! $subject || ! $message ) {
			if ( $is_xhr ) {
				wp_send_json_error( array( 'message' => __( 'Invalid owner registration data.', 'arriendo-facil' ) ) );
			}
			wp_safe_redirect( $redirect_to );
			exit;
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
			'wp_user_id'         => (int) $user->ID,
			'temp_password_hash' => wp_hash_password( $temp_password_plain ),
			'subject'            => $subject,
			'message'            => $message,
			'status'             => 'unread',
		);

		$owner_formats = array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

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
			$mail_subject = __( 'Owner account created', 'arriendo-facil' );
			$mail_body    = sprintf(
				"Hola %s,\n\nTu cuenta ha sido creada.\n\nUsuario: %s\nContrasena temporal: %s\n\nActiva tu cuenta y cambia tu contrasena aqui:\n%s\n\nSi no reconoces este registro, ignora este correo.",
				$subject,
				$user->user_login,
				$temp_password_plain,
				$reset_url
			);

			wp_mail( $owner_email, $mail_subject, $mail_body );

			if ( $is_xhr ) {
				wp_send_json_success(
					array(
						'id'          => $existing_contact_id > 0 ? $existing_contact_id : $wpdb->insert_id,
						'redirect_to' => $redirect_to,
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
