<?php
/**
 * Owner Admin Access Control - Security Module
 * Blocks owner access to wp-admin and wp-login.php
 * Redirects to login page when access is attempted
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Owner_Admin_Redirect {

	/**
	 * Constructor - registers hooks
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'block_owner_admin_access' ), 1 );
		add_action( 'init', array( $this, 'block_owner_login_page' ), 1 );
	}

	/**
	 * Blocks owner access to wp-admin when logged in
	 * Runs at admin_init (earliest possible hook)
	 */
	public function block_owner_admin_access() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User || empty( $user->ID ) ) {
			return;
		}

		if ( $this->user_has_owner_role( $user ) ) {
			$this->redirect_blocked_user();
		}
	}

	/**
	 * Blocks owner access to wp-login.php via GET/POST
	 * Also prevents accessing password reset pages via direct URL
	 */
	public function block_owner_login_page() {
		$pagenow = isset( $GLOBALS['pagenow'] ) ? sanitize_file_name( $GLOBALS['pagenow'] ) : '';

		if ( 'wp-login.php' !== $pagenow ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : 'login';

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( $user instanceof WP_User && $this->user_has_owner_role( $user ) ) {
				$this->redirect_blocked_user();
			}
		}
	}

	/**
	 * Check if user has the af_owner role
	 *
	 * @param WP_User $user The user object
	 * @return bool True if user has af_owner role
	 */
	private function user_has_owner_role( WP_User $user ) {
		if ( ! is_array( $user->roles ) ) {
			return false;
		}
		return in_array( 'af_owner', $user->roles, true );
	}

	/**
	 * Redirect blocked owner to login page
	 * Uses WPS Hide Login custom login URL if available
	 * Falls back to wp-login.php if WPS Hide Login is not active
	 */
	private function redirect_blocked_user() {
		$redirect_url = $this->get_login_url();

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Get the correct login URL
	 * Checks if WPS Hide Login is active and uses custom URL
	 *
	 * @return string Login URL
	 */
	private function get_login_url() {
		$option_key = 'wps_hide_login_url';
		$custom_url = get_option( $option_key );

		if ( ! empty( $custom_url ) && is_string( $custom_url ) ) {
			$custom_url = sanitize_text_field( $custom_url );
			return home_url( '/' . trim( $custom_url, '/' ) );
		}

		return wp_login_url();
	}
}
