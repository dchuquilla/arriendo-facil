<?php
/**
 * Plugin Name: AFR Fijar acceso Mi perfil (temporal)
 * Description: Garantiza que admin.php?page=af-admin-profile quede registrado con
 * edit_posts y sin _wp_submenu_nopriv, aunque una copia vieja del plugin lo haya
 * registrado con af_manage_properties (causa del 403 "Sorry, you are not allowed
 * to access this page."). El render sigue validando el rol (admin/af_property_admin).
 *
 * USO: subir este archivo a /wp-content/mu-plugins/af-fix-profile-access.php
 * y abrir https://SITIO/wp-admin/admin.php?page=af-admin-profile
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AFR_Force_Profile_Access {

	const PAGE   = 'af-admin-profile';
	const PARENT = 'arriendo-facil';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'force' ), PHP_INT_MAX );
	}

	public function force() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		global $submenu, $_wp_submenu_nopriv;

		if ( is_array( $_wp_submenu_nopriv ) ) {
			foreach ( array_keys( $_wp_submenu_nopriv ) as $parent ) {
				if ( isset( $_wp_submenu_nopriv[ $parent ][ self::PAGE ] ) ) {
					unset( $_wp_submenu_nopriv[ $parent ][ self::PAGE ] );
				}
			}
		}

		$registered = false;
		if ( ! empty( $submenu[ self::PARENT ] ) && is_array( $submenu[ self::PARENT ] ) ) {
			foreach ( $submenu[ self::PARENT ] as &$item ) {
				if ( isset( $item[2] ) && self::PAGE === $item[2] ) {
					// Cap fallback: edit_posts always passes for logged-in operators.
					$item[1]    = 'edit_posts';
					$registered = true;
					break;
				}
			}
			unset( $item );
		}

		if ( ! $registered ) {
			add_submenu_page(
				self::PARENT,
				__( 'Mi perfil', 'arriendo-facil' ),
				__( 'Mi perfil', 'arriendo-facil' ),
				'edit_posts',
				self::PAGE,
				array( $this, 'render' )
			);
		}
	}

	public function render() {
		if ( class_exists( 'Arriendo_Facil_Property_Admin_Onboarding' )
			&& method_exists( 'Arriendo_Facil_Property_Admin_Onboarding', 'render_profile_page' )
		) {
			$page = new Arriendo_Facil_Property_Admin_Onboarding();
			$page->render_profile_page();
			return;
		}

		if ( current_user_can( 'edit_posts' ) ) {
			wp_safe_redirect( admin_url( 'profile.php' ) );
			exit;
		}

		wp_die( esc_html__( 'Permiso denegado.', 'arriendo-facil' ) );
	}
}

new AFR_Force_Profile_Access();