<?php
/**
 * AJAX endpoints for property-admin (subadmin) licenses.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Property_Admins_Controller
 */
class Arriendo_Facil_Property_Admins_Controller {

	/**
	 * Registers AJAX endpoints.
	 */
	const MUST_CHANGE_META = 'af_must_change_password';

	public function __construct() {
		add_action( 'wp_ajax_af_create_property_admin', array( $this, 'ajax_create_property_admin' ) );
		add_action( 'wp_ajax_af_set_property_admin_status', array( $this, 'ajax_set_property_admin_status' ) );
		add_action( 'wp_ajax_af_delete_property_admin', array( $this, 'ajax_delete_property_admin' ) );
		add_action( 'admin_init', array( $this, 'require_password_change' ), 1 );
		add_action( 'admin_notices', array( $this, 'render_password_change_notice' ) );
		add_action( 'profile_update', array( $this, 'clear_password_change_flag' ), 10, 2 );
		add_action( 'after_password_reset', array( $this, 'clear_password_change_after_reset' ) );
	}

	/**
	 * AJAX: creates a property-admin (subadmin) account — a license sold to
	 * a property-management company. No email is sent; the temporary
	 * password is returned once in the response for the super admin to hand
	 * over manually (WhatsApp, in person, etc.).
	 *
	 * @return void
	 */
	public function ajax_create_property_admin() {
		check_ajax_referer( 'af_property_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
		$contact_name = isset( $_POST['contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_name'] ) ) : '';
		$email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone        = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		if ( '' === $contact_name || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Nombre y correo válido son obligatorios.', 'arriendo-facil' ) ), 400 );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Ya existe una cuenta con ese correo.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! get_role( 'af_property_admin' ) && class_exists( 'Arriendo_Facil_Activator' ) ) {
			Arriendo_Facil_Activator::ensure_owner_role();
		}

		$temp_password = wp_generate_password( 14, true, true );
		$base_login    = sanitize_user( current( explode( '@', $email ) ), true );
		$user_login    = $this->generate_unique_login( $base_login ? $base_login : 'admin', 0 );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $user_login,
				'user_pass'    => $temp_password,
				'user_email'   => $email,
				'display_name' => '' !== $company_name ? $company_name : $contact_name,
				'role'         => 'af_property_admin',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ), 400 );
		}

		update_user_meta( $user_id, 'af_company_name', $company_name );
		update_user_meta( $user_id, 'af_contact_name', $contact_name );
		update_user_meta( $user_id, 'af_contact_phone', $phone );
		update_user_meta( $user_id, 'af_license_status', 'active' );
		Arriendo_Facil_Plans::set_plan( $user_id, isset( $_POST['plan'] ) ? wp_unslash( $_POST['plan'] ) : 'free', array( 'reason' => 'signup' ) ) || Arriendo_Facil_Plans::set_plan( $user_id, 'free', array( 'reason' => 'signup' ) );
		update_user_meta( $user_id, 'af_signup_source', 'manual' );
		update_user_meta( $user_id, 'af_admin_email_verified', 1 );
		update_user_meta( $user_id, 'af_admin_doc_status', 'manual' );
		// The temporary password travels over chat/WhatsApp: it must be replaced on first login.
		update_user_meta( $user_id, self::MUST_CHANGE_META, 1 );

		wp_send_json_success(
			array(
				'message'  => __( 'Gestor de propiedades creado. Comparte estas credenciales por un canal seguro (no se envía correo).', 'arriendo-facil' ),
				'user_id'  => $user_id,
				'username' => $user_login,
				'password' => $temp_password,
			)
		);
	}

	/**
	 * AJAX: activates or suspends a property-admin license.
	 *
	 * @return void
	 */
	public function ajax_set_property_admin_status() {
		check_ajax_referer( 'af_property_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$status  = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! $user_id || ! in_array( $status, array( 'active', 'suspended' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Datos inválidos.', 'arriendo-facil' ) ), 400 );
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Gestor no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		update_user_meta( $user_id, 'af_license_status', $status );

		wp_send_json_success( array( 'message' => __( 'Estado de licencia actualizado.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: deletes a property-admin license (platform owners only).
	 *
	 * Uses the account-deletion lifecycle instead of an immediate wipe: access
	 * is revoked at once (sessions closed, license suspended, properties
	 * hidden) and the account moves to "Cuentas dadas de baja", where it can be
	 * reactivated or purged after the grace period, keeping contracts and SRI
	 * invoices for the legal retention period.
	 *
	 * @return void
	 */
	public function ajax_delete_property_admin() {
		check_ajax_referer( 'af_property_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
		$user    = $user_id ? get_userdata( $user_id ) : false;

		if (
			! $user
			|| get_current_user_id() === $user_id
			|| user_can( $user, 'manage_options' )
			|| is_super_admin( $user_id )
			|| ! in_array( 'af_property_admin', (array) $user->roles, true )
		) {
			wp_send_json_error( array( 'message' => __( 'Gestor no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		// The typed confirmation is checked on the server too, not only in the dialog.
		if ( 0 !== strcasecmp( trim( $confirm ), (string) $user->user_email ) ) {
			wp_send_json_error( array( 'message' => __( 'El correo de confirmación no coincide.', 'arriendo-facil' ) ), 400 );
		}

		if ( Arriendo_Facil_Account_Deletion::is_deactivated( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Esta licencia ya fue eliminada.', 'arriendo-facil' ) ), 409 );
		}

		Arriendo_Facil_Account_Deletion::deactivate_user( $user_id );

		wp_send_json_success( array( 'message' => __( 'Licencia eliminada. La cuenta pasó a "Cuentas dadas de baja".', 'arriendo-facil' ) ) );
	}

	/**
	 * Sends a manager who still has the temporary password to the profile
	 * screen until a new password is saved. AJAX and the profile screen itself
	 * stay reachable so the change can be made.
	 *
	 * @return void
	 */
	public function require_password_change() {
		if ( wp_doing_ajax() || ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_user_meta( get_current_user_id(), self::MUST_CHANGE_META, true ) ) {
			return;
		}

		global $pagenow;
		if ( 'profile.php' === $pagenow ) {
			return;
		}

		wp_safe_redirect( admin_url( 'profile.php?af_change_password=1#password' ) );
		exit;
	}

	/**
	 * Explains on the profile screen why the password must be changed.
	 *
	 * @return void
	 */
	public function render_password_change_notice() {
		global $pagenow;
		if ( 'profile.php' !== $pagenow || ! get_user_meta( get_current_user_id(), self::MUST_CHANGE_META, true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Cambia tu contraseña temporal.', 'arriendo-facil' ),
			esc_html__( 'Por seguridad, define una contraseña nueva en "Gestión de la cuenta" y guarda los cambios para continuar.', 'arriendo-facil' )
		);
	}

	/**
	 * Clears the flag once the user really saved a different password.
	 *
	 * @param int     $user_id       User ID.
	 * @param WP_User $old_user_data User object before the update.
	 * @return void
	 */
	public function clear_password_change_flag( $user_id, $old_user_data ) {
		$user = get_userdata( $user_id );
		if ( $user && $old_user_data instanceof WP_User && $user->user_pass !== $old_user_data->user_pass ) {
			delete_user_meta( $user_id, self::MUST_CHANGE_META );
		}
	}

	/**
	 * Clears the flag when the password is set through "¿Olvidaste tu contraseña?".
	 *
	 * @param WP_User $user User.
	 * @return void
	 */
	public function clear_password_change_after_reset( $user ) {
		if ( $user instanceof WP_User ) {
			delete_user_meta( $user->ID, self::MUST_CHANGE_META );
		}
	}

	/**
	 * Generates a unique username from a base slug, appending a numeric
	 * suffix (or the user ID) when the base login is already taken.
	 *
	 * @param string $base_login Suggested login (already sanitized).
	 * @param int    $seed       Fallback seed appended when needed.
	 * @return string
	 */
	private function generate_unique_login( $base_login, $seed = 0 ) {
		$base_login = $base_login ? $base_login : 'user';
		$login      = $base_login;
		$suffix     = 0;

		while ( username_exists( $login ) ) {
			++$suffix;
			$login = $base_login . $suffix;
		}

		return $login;
	}
}
