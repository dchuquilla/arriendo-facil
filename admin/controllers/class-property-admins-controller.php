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
	public function __construct() {
		add_action( 'wp_ajax_af_create_property_admin', array( $this, 'ajax_create_property_admin' ) );
		add_action( 'wp_ajax_af_set_property_admin_status', array( $this, 'ajax_set_property_admin_status' ) );
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
		update_user_meta( $user_id, 'af_signup_source', 'manual' );
		update_user_meta( $user_id, 'af_admin_email_verified', 1 );
		update_user_meta( $user_id, 'af_admin_doc_status', 'manual' );

		wp_send_json_success(
			array(
				'message'  => __( 'Administrador de propiedades creado. Comparte estas credenciales por un canal seguro (no se envía correo).', 'arriendo-facil' ),
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
			wp_send_json_error( array( 'message' => __( 'Administrador no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		update_user_meta( $user_id, 'af_license_status', $status );

		wp_send_json_success( array( 'message' => __( 'Estado de licencia actualizado.', 'arriendo-facil' ) ) );
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
