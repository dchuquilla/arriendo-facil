<?php
/**
 * Admin interface for Arriendo Fácil.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/af-view-helpers.php';

/**
 * Class Arriendo_Facil_Admin
 *
 * Sets up the top-level admin menu and sub-pages for the plugin.
 */
class Arriendo_Facil_Admin {

	/**
	 * Constructor – hooks into WordPress admin.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_menu', array( $this, 'rename_wp_dashboard_menu_for_portal_users' ), 1000 );
		add_action( 'admin_menu', array( $this, 'remove_menus_for_owner' ), 999 );
		add_action( 'admin_menu', array( $this, 'remove_menus_for_tenant' ), 999 );
		add_action( 'admin_menu', array( $this, 'register_tenant_dashboard_pages' ), 50 );
		add_filter( 'get_user_option_screen_layout_dashboard', array( $this, 'force_tenant_dashboard_single_column' ), 10, 3 );
		add_filter( 'login_redirect', array( $this, 'redirect_owner_after_login' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'redirect_owner_from_wp_dashboard' ) );
		add_action( 'admin_init', array( $this, 'suppress_owner_update_nag' ) );
		add_action( 'admin_bar_menu', array( $this, 'harden_admin_bar_for_owner' ), 999 );
		add_action( 'admin_head', array( $this, 'owner_hardening_css' ) );
		add_action( 'wp_head', array( $this, 'owner_hardening_css' ) );
		add_filter( 'admin_footer_text', array( $this, 'owner_admin_footer_text' ), 999 );
		add_filter( 'update_footer', array( $this, 'owner_admin_footer_text' ), 999 );
		add_filter( 'screen_options_show_screen', array( $this, 'owner_hide_screen_options' ), 999 );
		add_filter( 'admin_body_class', array( $this, 'tag_native_pages_body_class' ) );
		add_action( 'in_admin_header', array( $this, 'render_custom_shell_nav' ) );
		add_action( 'pre_get_posts', array( $this, 'restrict_accommodation_list_to_owner' ) );
		add_filter( 'views_edit-accommodation', array( $this, 'filter_accommodation_status_views_for_owner' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'remove_owner_dashboard_widgets' ), 999 );
		add_action( 'wp_dashboard_setup', array( $this, 'remove_tenant_dashboard_widgets' ), 999 );
		add_action( 'wp_dashboard_setup', array( $this, 'register_native_dashboard_widget' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_tenant_dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) {
			add_action( 'wp_ajax_af_predict_cost', array( $this, 'ajax_predict_cost' ) );
			add_action( 'wp_ajax_af_generate_document', array( $this, 'ajax_generate_document' ) );
		}
		add_action( 'wp_ajax_af_resolve_short_url', array( $this, 'ajax_resolve_short_url' ) );
		add_action( 'wp_ajax_af_create_property_admin', array( $this, 'ajax_create_property_admin' ) );
		add_action( 'wp_ajax_af_set_property_admin_status', array( $this, 'ajax_set_property_admin_status' ) );
		add_action( 'wp_ajax_af_save_dashboard_hero_video', array( $this, 'ajax_save_dashboard_hero_video' ) );
		add_action( 'wp_ajax_af_toggle_buildings_module', array( $this, 'ajax_toggle_buildings_module' ) );
		add_filter( 'wp_authenticate_user', array( $this, 'block_suspended_property_admin_login' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'pandoc_notice' ) );
	}

	/**
	 * Registers the plugin's top-level menu and sub-pages.
	 */
	public function add_menu() {
		$menu_icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">'
			. '<path d="M3 11l9-8 9 8v10a1 1 0 01-1 1h-5v-6H10v6H4a1 1 0 01-1-1V11z" stroke="#a7aaad" stroke-width="1.8" stroke-linejoin="round"/>'
			. '<circle cx="12" cy="14" r="1.6" fill="#a7aaad"/>'
			. '</svg>'
		);

		add_menu_page(
			__( 'Arriendo Fácil', 'arriendo-facil' ),
			__( 'Arriendo Fácil', 'arriendo-facil' ),
			'edit_posts',
			'arriendo-facil',
			array( $this, 'render_dashboard' ),
			$menu_icon_svg,
			30
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Panel', 'arriendo-facil' ),
			__( 'Panel', 'arriendo-facil' ),
			'edit_posts',
			'arriendo-facil',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Catálogo de propiedades', 'arriendo-facil' ),
			__( 'Catálogo', 'arriendo-facil' ),
			'edit_posts',
			'af-catalog',
			array( $this, 'render_catalog' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Calendario', 'arriendo-facil' ),
			__( 'Calendario', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			'af-calendar',
			array( $this, 'render_calendar' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Contratos', 'arriendo-facil' ),
			__( 'Contratos', 'arriendo-facil' ),
			'edit_posts',
			'af-leases',
			array( $this, 'render_leases' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Administradores de Propiedades', 'arriendo-facil' ),
			__( 'Administradores', 'arriendo-facil' ),
			'manage_options',
			'af-property-admins',
			array( $this, 'render_property_admins' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Próximas salidas', 'arriendo-facil' ),
			__( 'Próximas salidas', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			'af-upcoming-exits',
			array( $this, 'render_upcoming_exits' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Cobros y Servicios', 'arriendo-facil' ),
			__( 'Cobros y Servicios', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			'af-cobros',
			array( $this, 'render_cobros' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Cobranza de inmuebles', 'arriendo-facil' ),
			__( 'Cobranza de inmuebles', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			'af-buildings',
			array( $this, 'render_buildings' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Control de pagos', 'arriendo-facil' ),
			__( 'Control de pagos', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			'af-collections',
			array( $this, 'render_collections' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Pagos de servicios', 'arriendo-facil' ),
			__( 'Pagos de servicios', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			'af-meter-readings',
			array( $this, 'render_meter_readings' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Alertas operativas', 'arriendo-facil' ),
			__( 'Alertas', 'arriendo-facil' ),
			'edit_posts',
			'af-alerts-center',
			array( $this, 'render_alerts' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Comunicaciones con inquilinos', 'arriendo-facil' ),
			__( 'Comunicaciones', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			'af-avisos',
			array( $this, 'render_avisos' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Liquidación al propietario', 'arriendo-facil' ),
			__( 'Liquidaciones', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			'af-owner-settlements',
			array( $this, 'render_owner_settlements' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Mantenimiento e incidencias', 'arriendo-facil' ),
			__( 'Mantenimiento', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			'af-maintenance',
			array( $this, 'render_maintenance' )
		);

		if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) {
			add_submenu_page(
				'arriendo-facil',
				__( 'Solicitudes de limpieza', 'arriendo-facil' ),
				__( 'Solicitudes de limpieza', 'arriendo-facil' ),
				'edit_posts',
				'af-cleaning-requests',
				array( $this, 'render_cleaning_requests' )
			);
		}

		if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) {
			add_submenu_page(
				'arriendo-facil',
				__( 'Contactos de propietarios', 'arriendo-facil' ),
				__( 'Contactos de propietarios', 'arriendo-facil' ),
				'manage_options',
				'af-owner-contacts',
				array( $this, 'render_owner_contacts' )
			);
		}

		add_submenu_page(
			'arriendo-facil',
			__( 'Inquilinos', 'arriendo-facil' ),
			__( 'Inquilinos', 'arriendo-facil' ),
			'edit_posts',
			'af-guests',
			array( $this, 'render_guests' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Calificación de Inquilinos', 'arriendo-facil' ),
			__( 'Calificaciones', 'arriendo-facil' ),
			'edit_posts',
			'af-reviews',
			array( $this, 'render_reviews' )
		);

		if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) {
			add_submenu_page(
				'arriendo-facil',
				__( 'Ajustes de IA', 'arriendo-facil' ),
				__( 'Ajustes de IA', 'arriendo-facil' ),
				'manage_options',
				'af-ai-settings',
				array( $this, 'render_ai_settings' )
			);
		}

		add_submenu_page(
			'arriendo-facil',
			__( 'Facturación Electrónica', 'arriendo-facil' ),
			__( 'Facturación', 'arriendo-facil' ),
			(string) apply_filters( 'af_billing_capability', 'af_view_billing' ),
			'af-billing',
			array( $this, 'render_billing' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Configuración SRI', 'arriendo-facil' ),
			__( 'Config. SRI', 'arriendo-facil' ),
			(string) apply_filters( 'af_billing_capability', 'af_view_billing' ),
			'af-billing-settings',
			array( $this, 'render_billing_settings' )
		);

		// La configuración SRI vive dentro del hub de facturación (tabs internos).
		// La subpágina se conserva registrada para mantener el hook de assets,
		// pero no aparece en el menú.
		remove_submenu_page( 'arriendo-facil', 'af-billing-settings' );

		if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) {
			add_submenu_page(
				'arriendo-facil',
				__( 'Integraciones OTA', 'arriendo-facil' ),
				__( 'Integraciones OTA', 'arriendo-facil' ),
				'edit_posts',
				'af-ota-integrations',
				array( $this, 'render_ota_integrations' )
			);

			add_submenu_page(
				'arriendo-facil',
				__( 'Panel de Sincronización OTA', 'arriendo-facil' ),
				__( 'Sincronización OTA', 'arriendo-facil' ),
				'manage_options',
				'af-ota-sync-dashboard',
				array( $this, 'render_ota_sync_dashboard' )
			);
		}
	}

	/**
	 * Removes WordPress default menus and plugin CPT menus for owner users.
	 */
	public function remove_menus_for_owner() {
		if ( ! Arriendo_Facil_Accommodation::user_is_owner() ) {
			return;
		}

		remove_menu_page( 'index.php' );
		remove_menu_page( 'edit.php' );
		remove_menu_page( 'upload.php' );
		remove_menu_page( 'edit-comments.php' );
		remove_menu_page( 'tools.php' );
		remove_menu_page( 'edit.php?post_type=residencia' );

		// Ocultar subpáginas que el propietario no necesita ver.
		remove_submenu_page( 'arriendo-facil', 'edit.php?post_type=cleaning_service' );
		remove_submenu_page( 'arriendo-facil', 'af-owner-contacts' );
		remove_submenu_page( 'arriendo-facil', 'af-ai-settings' );
		remove_submenu_page( 'arriendo-facil', 'af-ota-integrations' );
		remove_submenu_page( 'arriendo-facil', 'af-ota-sync-dashboard' );
	}

	/**
	 * Removes WordPress menus not needed by tenant users.
	 *
	 * @return void
	 */
	public function remove_menus_for_tenant() {
		if ( ! $this->is_restricted_tenant() ) {
			return;
		}

		remove_menu_page( 'edit.php' );
		remove_menu_page( 'upload.php' );
		remove_menu_page( 'edit-comments.php' );
		remove_menu_page( 'tools.php' );
		remove_menu_page( 'plugins.php' );
		remove_menu_page( 'themes.php' );
		remove_menu_page( 'options-general.php' );
		remove_menu_page( 'edit.php?post_type=accommodation' );
		remove_menu_page( 'edit.php?post_type=cleaning_service' );
		remove_menu_page( 'arriendo-facil' );
	}

	/**
	 * Renames native WordPress Dashboard label for tenant users.
	 *
	 * @return void
	 */
	public function rename_wp_dashboard_menu_for_portal_users() {
		if ( ! $this->is_restricted_tenant() ) {
			return;
		}

		global $menu, $submenu;

		if ( isset( $menu[2][0] ) ) {
			$menu[2][0] = __( 'Arriendo Fácil', 'arriendo-facil' );
		}

		if ( isset( $submenu['index.php'][0][0] ) ) {
			$submenu['index.php'][0][0] = __( 'Arriendo Fácil', 'arriendo-facil' );
		}
	}

	/**
	 * Returns true when the current user should see the owner-restricted UI
	 * (owner role and NOT admin).
	 */
	private function is_restricted_owner() {
		return Arriendo_Facil_Accommodation::user_is_owner() && ! current_user_can( 'manage_options' );
	}

	/**
	 * Returns true when current user is a tenant (non-admin).
	 *
	 * @return bool
	 */
	private function is_restricted_tenant() {
		if ( current_user_can( 'manage_options' ) ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( ! ( $user instanceof WP_User ) ) {
			return false;
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();
		return in_array( 'af_tenant', $roles, true );
	}

	/**
	 * Returns true for restricted role-based dashboards (owner or tenant).
	 *
	 * @return bool
	 */
	private function is_restricted_portal_user() {
		return $this->is_restricted_owner() || $this->is_restricted_tenant();
	}

	/**
	 * Removes noisy WP-core nodes from the admin bar for restricted users.
	 * Keeps: site-name (link a home), user account, notifications.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function harden_admin_bar_for_owner( $wp_admin_bar ) {
		if ( ! $this->is_restricted_owner() && ! $this->is_restricted_tenant() ) {
			return;
		}

		$noisy_nodes = array(
			'wp-logo',
			'wp-logo-external',
			'about',
			'contribute',
			'wporg',
			'documentation',
			'support-forums',
			'feedback',
			'view-site',
			'dashboard',
			'themes',
			'menus',
			'widgets',
			'background',
			'header',
			'customize',
			'search',
			'comments',
			'new-content',
			'updates',
			'edit',
			'view',
			'view-store',
			'archive',
		);

		foreach ( $noisy_nodes as $node ) {
			$wp_admin_bar->remove_node( $node );
		}
	}

	/**
	 * Suppresses the "WordPress X.Y is available" nag for restricted users.
	 */
	public function suppress_owner_update_nag() {
		if ( ! $this->is_restricted_portal_user() ) {
			return;
		}

		remove_action( 'admin_notices', 'update_nag', 3 );
		remove_action( 'network_admin_notices', 'update_nag', 3 );
		remove_action( 'admin_notices', 'maintenance_nag', 10 );
	}

	/**
	 * CSS fallback that hides residual WP-core UI for restricted users
	 * (some nodes reappear via JS or are injected late).
	 */
	public function owner_hardening_css() {
		if ( ! $this->is_restricted_portal_user() ) {
			return;
		}

		echo '<style id="af-owner-hardening">'
			. '#update-nag, .update-nag, .php-update-nag, .plugin-update-tr,'
			. '#wp-admin-bar-wp-logo, #wp-admin-bar-updates, #wp-admin-bar-comments,'
			. '#wp-admin-bar-new-content, #wp-admin-bar-customize, #wp-admin-bar-search,'
			. '#wp-admin-bar-themes, #wp-admin-bar-menus, #wp-admin-bar-widgets,'
			. '#wp-admin-bar-about, #wp-admin-bar-wporg, #wp-admin-bar-documentation,'
			. '#wp-admin-bar-support-forums, #wp-admin-bar-feedback, #wp-admin-bar-contribute,'
			. '#wp-admin-bar-view, #wp-admin-bar-view-store, #wp-admin-bar-archive,'
			. '#screen-options-link-wrap, #contextual-help-link-wrap,'
			. '#screen-meta, #screen-meta-links,'
			. '#wpfooter, #footer-upgrade, #footer-thankyou, #wp-version-message,'
			. 'body.wp-admin .notice.notice-warning.update-message'
			. '{ display: none !important; }'
			. '</style>';
	}

	/**
	 * Empties the admin footer text (left "Thank you for creating with WordPress"
	 * and right version string) for restricted users.
	 *
	 * @param string $text Original footer text.
	 * @return string
	 */
	public function owner_admin_footer_text( $text ) {
		if ( ! $this->is_restricted_portal_user() ) {
			return $text;
		}
		return '';
	}

	/**
	 * Hides the "Screen Options" and "Help" tabs for restricted users.
	 *
	 * @param bool $show Whether to show screen options.
	 * @return bool
	 */
	public function owner_hide_screen_options( $show ) {
		if ( ! $this->is_restricted_portal_user() ) {
			return $show;
		}
		return false;
	}

	/**
	 * Adds contextual body classes so CSS can restyle native WP pages
	 * (accommodation list, profile edit) without touching their HTML.
	 *
	 * @param string $classes Existing space-separated body classes.
	 * @return string
	 */
	public function tag_native_pages_body_class( $classes ) {
		global $pagenow, $typenow;

		$extra = array();

		if ( 'edit.php' === $pagenow && 'accommodation' === $typenow ) {
			$extra[] = 'af-native-inmuebles';
			$extra[] = 'af-shell';

			// Los subadmins (af_property_admin) obtienen la vista simplificada
			// (sin bulk actions, checkboxes ni filtros nativos). El super admin
			// conserva la tabla nativa completa de WordPress.
			if ( ! current_user_can( 'manage_options' ) ) {
				$extra[] = 'af-restricted-list';
			}
		}

		if ( 'profile.php' === $pagenow || 'user-edit.php' === $pagenow ) {
			$extra[] = 'af-native-profile';
			if ( $this->is_restricted_owner() ) {
				$extra[] = 'af-owner-view';
			} elseif ( $this->is_restricted_tenant() ) {
				$extra[] = 'af-tenant-view';
			}
		}

		if ( 'index.php' === $pagenow && $this->is_restricted_tenant() ) {
			$extra[] = 'af-tenant-dashboard';
		}

		if ( $this->should_use_custom_shell() ) {
			$extra[] = 'af-custom-shell';
		}

		if ( ! empty( $extra ) ) {
			$classes .= ' ' . implode( ' ', $extra );
		}

		return $classes;
	}

	/**
	 * Whether the current request is one of the plugin's own admin screens
	 * (Panel, Contratos, Propiedades, Inmuebles, etc.), as opposed to core
	 * WordPress screens (Posts, Plugins, Users, Settings, Tools...).
	 *
	 * Uses an allow-list (only our own `admin.php?page=af-*`/`arriendo-facil`
	 * screens and the `accommodation` CPT) PLUS an explicit deny-list of
	 * core WP admin files as a second, independent guard — so core screens
	 * never lose their native chrome for ANY role, including administrators,
	 * even if the allow-list logic above ever changes.
	 *
	 * @return bool
	 */
	private function is_own_admin_screen() {
		global $pagenow, $typenow;

		$core_wp_pages = array(
			'index.php',
			'plugins.php',
			'plugin-install.php',
			'plugin-editor.php',
			'users.php',
			'user-new.php',
			'user-edit.php',
			'profile.php',
			'options-general.php',
			'options-writing.php',
			'options-reading.php',
			'options-discussion.php',
			'options-media.php',
			'options-permalink.php',
			'options-privacy.php',
			'tools.php',
			'import.php',
			'export.php',
			'site-health.php',
			'update-core.php',
			'themes.php',
			'theme-editor.php',
			'customize.php',
			'nav-menus.php',
			'widgets.php',
			'upload.php',
			'media-new.php',
			'edit-comments.php',
			'network',
		);

		if ( in_array( $pagenow, $core_wp_pages, true ) ) {
			return false;
		}

		if ( 'admin.php' === $pagenow ) {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			return 'arriendo-facil' === $page || 0 === strpos( $page, 'af-' );
		}

		if ( in_array( $pagenow, array( 'edit.php', 'post.php', 'post-new.php' ), true ) ) {
			return 'accommodation' === $typenow;
		}

		return false;
	}

	/**
	 * Returns the slug identifying the current screen for nav highlighting.
	 *
	 * @return string
	 */
	private function current_shell_slug() {
		global $pagenow, $typenow;

		if ( in_array( $pagenow, array( 'edit.php', 'post.php', 'post-new.php' ), true ) && 'accommodation' === $typenow ) {
			return 'edit-accommodation';
		}

		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Whether the current user/screen should get the branded app shell
	 * (custom sidebar) instead of the native WordPress admin chrome.
	 * Restricted to the plugin's own screens for administrators and
	 * property admins; tenants keep their dedicated dashboard styling.
	 *
	 * @return bool
	 */
	private function should_use_custom_shell() {
		if ( ! is_admin() || $this->is_restricted_tenant() ) {
			return false;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		if ( ! $this->is_own_admin_screen() ) {
			return false;
		}

		/**
		 * Lets external code (e.g. the site's active theme functions.php)
		 * toggle the branded admin shell on/off without touching plugin
		 * code. Defaults to enabled; kept in the plugin (not the theme) so
		 * the property-admin dashboard keeps working regardless of which
		 * public-facing theme is active.
		 *
		 * @param bool $enabled Whether to render the custom shell.
		 */
		return (bool) apply_filters( 'af_admin_shell_enabled', true );
	}

	/**
	 * Builds the nav items for the custom app shell sidebar, filtered by
	 * capability and by the property-admin onboarding gate.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * Module definitions for the custom app shell sidebar, mirroring the
	 * product prototype: Panel, Propiedades, Contratos, Huéspedes,
	 * Pagos y dispersión, Reviews, Facturación SRI y Configuración.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_shell_nav_groups() {
		return array(
			'panel'       => array(
				'label' => __( 'Panel', 'arriendo-facil' ),
				'icon'  => 'layout-dashboard',
			),
			'propiedades' => array(
				'label' => __( 'Propiedades', 'arriendo-facil' ),
				'icon'  => 'building-2',
			),
			'contratos'   => array(
				'label' => __( 'Contratos', 'arriendo-facil' ),
				'icon'  => 'file-text',
			),
			'huespedes'   => array(
				'label' => __( 'Huéspedes', 'arriendo-facil' ),
				'icon'  => 'users',
			),
			'pagos'       => array(
				'label' => __( 'Cobranza y pagos', 'arriendo-facil' ),
				'icon'  => 'credit-card',
			),
			'reviews'     => array(
				'label' => __( 'Reviews', 'arriendo-facil' ),
				'icon'  => 'star',
			),
			'facturacion' => array(
				'label' => __( 'Facturación SRI', 'arriendo-facil' ),
				'icon'  => 'receipt',
			),
			'config'      => array(
				'label' => __( 'Configuración', 'arriendo-facil' ),
				'icon'  => 'settings',
			),
		);
	}

	/**
	 * Builds the nav items for the custom app shell sidebar, assigned to the
	 * prototype modules via the 'group' key, filtered by capability and by
	 * the property-admin onboarding gate.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_shell_nav_items() {
		$gated = apply_filters( 'af_property_admin_gate_operational_menus', false )
			&& class_exists( 'Arriendo_Facil_Property_Admin_Onboarding' )
			&& Arriendo_Facil_Property_Admin_Onboarding::needs_identity_verification();

		$billing_cap = (string) apply_filters( 'af_billing_capability', 'af_view_billing' );

		$items = array(
			array(
				'slug'  => 'arriendo-facil',
				'label' => __( 'Panel', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=arriendo-facil' ),
				'icon'  => 'layout-dashboard',
				'group' => 'panel',
				'cap'   => 'edit_posts',
				'gate'  => false,
			),
			array(
				'slug'  => 'af-calendar',
				'label' => __( 'Calendario', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-calendar' ),
				'icon'  => 'calendar',
				'group' => 'panel',
				'cap'   => Arriendo_Facil_Tenancy::CAP,
				'gate'  => true,
			),
			array(
				'slug'  => 'edit-accommodation',
				'label' => __( 'Inmuebles', 'arriendo-facil' ),
				'url'   => admin_url( 'edit.php?post_type=accommodation' ),
				'icon'  => 'building-2',
				'group' => 'propiedades',
				'cap'   => 'edit_posts',
				'gate'  => false,
			),
			array(
				'slug'  => 'af-catalog',
				'label' => __( 'Catálogo', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-catalog' ),
				'icon'  => 'layout-grid',
				'group' => 'propiedades',
				'cap'   => 'edit_posts',
				'gate'  => true,
			),
			array(
				'slug'  => 'af-leases',
				'label' => __( 'Contratos', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-leases' ),
				'icon'  => 'file-text',
				'group' => 'contratos',
				'cap'   => 'edit_posts',
				'gate'  => true,
			),
array(
			'slug'  => 'af-buildings',
			'label' => __( 'Cobranza de inmuebles', 'arriendo-facil' ),
			'url'   => admin_url( 'admin.php?page=af-buildings' ),
			'icon'  => 'building',
			'group' => 'propiedades',
			'cap'   => Arriendo_Facil_Tenancy::CAP,
			'gate'  => true,
		),
		array(
			'slug'  => 'af-meter-readings',
			'label' => __( 'Pagos de servicios', 'arriendo-facil' ),
			'url'   => admin_url( 'admin.php?page=af-meter-readings' ),
			'icon'  => 'gauge',
			'group' => 'pagos',
			'cap'   => Arriendo_Facil_Tenancy::CAP,
			'gate'  => true,
		),
array(
			'slug'  => 'af-guests',
			'label' => __( 'Inquilinos', 'arriendo-facil' ),
			'url'   => admin_url( 'admin.php?page=af-guests' ),
			'icon'  => 'users',
			'group' => 'huespedes',
			'cap'   => 'edit_posts',
			'gate'  => true,
		),
		array(
			'slug'  => 'af-upcoming-exits',
			'label' => __( 'Próximas salidas', 'arriendo-facil' ),
			'url'   => admin_url( 'admin.php?page=af-upcoming-exits' ),
			'icon'  => 'calendar',
			'group' => 'contratos',
			'cap'   => Arriendo_Facil_Tenancy::CAP,
			'gate'  => true,
		),
			array(
				'slug'  => 'af-cobros',
				'label' => __( 'Cobros y Servicios', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-cobros' ),
				'icon'  => 'circle-alert',
				'group' => 'pagos',
				'cap'   => Arriendo_Facil_Tenancy::CAP,
				'gate'  => true,
			),
			array(
				'slug'  => 'af-collections',
				'label' => __( 'Pagos y dispersión', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-collections' ),
				'icon'  => 'credit-card',
				'group' => 'pagos',
				'cap'   => Arriendo_Facil_Tenancy::CAP,
				'gate'  => true,
			),
			array(
				'slug'  => 'af-owner-settlements',
				'label' => __( 'Liquidaciones', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-owner-settlements' ),
				'icon'  => 'wallet',
				'group' => 'pagos',
				'cap'   => Arriendo_Facil_Tenancy::CAP,
				'gate'  => true,
			),
			array(
				'slug'  => 'af-maintenance',
				'label' => __( 'Mantenimiento', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-maintenance' ),
				'icon'  => 'wrench',
				'group' => 'propiedades',
				'cap'   => Arriendo_Facil_Tenancy::CAP,
				'gate'  => true,
			),
			array(
				'slug'  => 'af-reviews',
				'label' => __( 'Reviews', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-reviews' ),
				'icon'  => 'star',
				'group' => 'reviews',
				'cap'   => 'edit_posts',
				'gate'  => true,
			),
			array(
				'slug'  => 'af-billing',
				'label' => __( 'Facturación', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-billing' ),
				'icon'  => 'receipt',
				'group' => 'facturacion',
				'cap'   => $billing_cap,
				'gate'  => true,
			),
			array(
				'slug'  => 'af-admin-profile',
				'label' => __( 'Mi perfil', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-admin-profile' ),
				'icon'  => 'user',
				'group' => 'config',
				'cap'   => 'edit_posts',
				'gate'  => false,
			),
			array(
				'slug'  => 'af-alerts-center',
				'label' => __( 'Alertas', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-alerts-center' ),
				'icon'  => 'bell',
				'group' => 'config',
				'cap'   => 'edit_posts',
				'gate'  => false,
			),
			array(
				'slug'  => 'af-property-admins',
				'label' => __( 'Administradores', 'arriendo-facil' ),
				'url'   => admin_url( 'admin.php?page=af-property-admins' ),
				'icon'  => 'user-check',
				'group' => 'config',
				'cap'   => 'manage_options',
				'gate'  => false,
			),
		);

		$current = $this->current_shell_slug();
		$output  = array();

		foreach ( $items as $item ) {
			if ( ! current_user_can( $item['cap'] ) ) {
				continue;
			}

			if ( $item['gate'] && $gated ) {
				continue;
			}

			$item['active'] = ( '' !== $current && $current === $item['slug'] );
			$output[]        = $item;
		}

		return $output;
	}

	/**
	 * Links back to core WordPress admin screens (Plugins, Users, Settings,
	 * Tools...), shown only to super admins (`manage_options`) at the bottom
	 * of the custom sidebar. Without this, a super admin browsing our own
	 * screens would have no visible way back to core wp-admin sections,
	 * since the custom shell hides the native #adminmenu there. Property
	 * admins never manage plugins/users/settings, so they never see this.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_wp_core_links() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}

		return array(
			array(
				'label' => __( 'Escritorio de WordPress', 'arriendo-facil' ),
				'url'   => admin_url( 'index.php' ),
				'icon'  => 'globe',
			),
			array(
				'label' => __( 'Plugins', 'arriendo-facil' ),
				'url'   => admin_url( 'plugins.php' ),
				'icon'  => 'puzzle',
			),
			array(
				'label' => __( 'Usuarios', 'arriendo-facil' ),
				'url'   => admin_url( 'users.php' ),
				'icon'  => 'users',
			),
			array(
				'label' => __( 'Ajustes', 'arriendo-facil' ),
				'url'   => admin_url( 'options-general.php' ),
				'icon'  => 'sliders-horizontal',
			),
			array(
				'label' => __( 'Herramientas', 'arriendo-facil' ),
				'url'   => admin_url( 'tools.php' ),
				'icon'  => 'wrench',
			),
		);
	}

	/**
	 * Prints the branded app-shell sidebar (replacing the native WP admin
	 * menu/toolbar visually via af-admin-shell-nav.css) on the plugin's own
	 * screens.
	 *
	 * @return void
	 */
	public function render_custom_shell_nav() {
		if ( ! $this->should_use_custom_shell() ) {
			return;
		}

		$items = $this->get_shell_nav_items();
		if ( empty( $items ) ) {
			return;
		}

		$groups  = $this->get_shell_nav_groups();
		$grouped = array();
		foreach ( array_keys( $groups ) as $group_slug ) {
			$grouped[ $group_slug ] = array();
		}
		foreach ( $items as $item ) {
			$group                    = isset( $item['group'] ) && isset( $grouped[ $item['group'] ] ) ? $item['group'] : 'panel';
			$grouped[ $group ][]      = $item;
		}

		$wp_core_links = $this->get_wp_core_links();
		$current_user  = wp_get_current_user();
		?>
		<div id="af-app-sidebar" class="af-app-sidebar af-shell" role="navigation" aria-label="<?php esc_attr_e( 'Arriendo Fácil', 'arriendo-facil' ); ?>">
			<div class="af-app-sidebar__brand">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=arriendo-facil' ) ); ?>" class="af-app-sidebar__logo">
					<?php echo af_lucide( 'home', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
					<span class="af-app-sidebar__logo-copy">
						<span class="af-app-sidebar__logo-text"><?php esc_html_e( 'Arriendo Fácil', 'arriendo-facil' ); ?></span>
						<span class="af-app-sidebar__logo-tagline"><?php echo esc_html( $current_user->display_name ); ?></span>
					</span>
				</a>
				<button type="button" class="af-app-sidebar__toggle" id="af-app-sidebar-toggle" aria-label="<?php esc_attr_e( 'Contraer menú', 'arriendo-facil' ); ?>">
					<?php echo af_lucide( 'chevrons-left', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
				</button>
			</div>
			<ul class="af-app-sidebar__nav">
				<?php foreach ( $groups as $group_slug => $group_meta ) : ?>
					<?php
					if ( empty( $grouped[ $group_slug ] ) ) {
						continue;
					}
					$is_single = 1 === count( $grouped[ $group_slug ] );
					?>
					<?php if ( $is_single ) : ?>
						<?php $nav_item = $grouped[ $group_slug ][0]; ?>
						<li class="af-app-sidebar__item<?php echo $nav_item['active'] ? ' is-active' : ''; ?>">
							<a href="<?php echo esc_url( $nav_item['url'] ); ?>">
								<?php echo af_lucide( $nav_item['icon'], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
								<span class="af-app-sidebar__label"><?php echo esc_html( $nav_item['label'] ); ?></span>
							</a>
						</li>
					<?php else : ?>
						<?php
						$group_active = false;
						foreach ( $grouped[ $group_slug ] as $nav_item ) {
							if ( ! empty( $nav_item['active'] ) ) {
								$group_active = true;
							}
						}
						?>
						<li class="af-app-sidebar__group<?php echo $group_active ? ' is-active' : ''; ?>">
							<a class="af-app-sidebar__group-link" href="<?php echo esc_url( $grouped[ $group_slug ][0]['url'] ); ?>">
								<?php echo af_lucide( $group_meta['icon'], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
								<span class="af-app-sidebar__group-label"><?php echo esc_html( $group_meta['label'] ); ?></span>
								<span class="af-app-sidebar__group-chevron"><?php echo af_lucide( 'chevron-down', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							</a>
							<ul class="af-app-sidebar__subnav">
								<?php foreach ( $grouped[ $group_slug ] as $nav_item ) : ?>
									<li class="af-app-sidebar__item<?php echo $nav_item['active'] ? ' is-active' : ''; ?>">
										<a href="<?php echo esc_url( $nav_item['url'] ); ?>">
											<span class="af-app-sidebar__label"><?php echo esc_html( $nav_item['label'] ); ?></span>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $wp_core_links ) ) : ?>
				<div class="af-app-sidebar__section-label"><?php esc_html_e( 'WordPress', 'arriendo-facil' ); ?></div>
				<ul class="af-app-sidebar__nav af-app-sidebar__nav--wp-core">
					<?php foreach ( $wp_core_links as $link ) : ?>
						<li class="af-app-sidebar__item">
							<a href="<?php echo esc_url( $link['url'] ); ?>">
								<?php echo af_lucide( $link['icon'], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
								<span class="af-app-sidebar__label"><?php echo esc_html( $link['label'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="af-app-sidebar__footer">
				<a class="af-app-sidebar__user" href="<?php echo esc_url( admin_url( 'admin.php?page=af-admin-profile' ) ); ?>">
					<?php echo get_avatar( $current_user->ID, 32 ); ?>
					<span class="af-app-sidebar__user-name"><?php echo esc_html( $current_user->display_name ); ?></span>
				</a>
				<a class="af-app-sidebar__logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" title="<?php esc_attr_e( 'Cerrar sesión', 'arriendo-facil' ); ?>">
					<?php echo af_lucide( 'log-out', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
				</a>
			</div>
		</div>
		<button type="button" id="af-app-sidebar-mobile-toggle" class="af-app-sidebar-mobile-toggle" aria-label="<?php esc_attr_e( 'Abrir menú', 'arriendo-facil' ); ?>" aria-expanded="false">
			<?php echo af_lucide( 'menu', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
		</button>

		<div class="af-app-topbar af-shell">
			<form class="af-app-topbar__search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
				<input type="hidden" name="page" value="af-catalog" />
				<?php echo af_lucide( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
				<input type="search" name="s" placeholder="<?php esc_attr_e( 'Buscar propiedades, contratos…', 'arriendo-facil' ); ?>" aria-label="<?php esc_attr_e( 'Buscar', 'arriendo-facil' ); ?>" />
			</form>
<div class="af-app-topbar__actions">
			<?php
			$af_alerts_unread = class_exists( 'Arriendo_Facil_Alerts' ) ? Arriendo_Facil_Alerts::count_unread( get_current_user_id() ) : 0;
			$af_alerts_recent = class_exists( 'Arriendo_Facil_Alerts' ) ? Arriendo_Facil_Alerts::get_for_user( get_current_user_id(), 6, true ) : array();
			$af_alerts_has_new = (int) $af_alerts_unread > 0;
			?>
			<div class="af-alerts" id="af-alerts">
				<button type="button" class="af-app-topbar__bell af-alerts-toggle" id="af-alerts-toggle" aria-expanded="false" aria-haspopup="true" aria-controls="af-alerts-panel" aria-label="<?php esc_attr_e( 'Alertas operativas', 'arriendo-facil' ); ?>">
					<?php echo af_lucide( 'bell', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
					<span class="af-alerts-badge"<?php echo $af_alerts_has_new ? '' : ' hidden'; ?> data-af-alerts-count><?php echo esc_html( number_format_i18n( $af_alerts_unread ) ); ?></span>
				</button>
				<div class="af-alerts-panel" id="af-alerts-panel" hidden>
					<div class="af-alerts-panel__head">
						<span class="af-alerts-panel__title"><?php esc_html_e( 'Alertas operativas', 'arriendo-facil' ); ?></span>
						<button type="button" class="af-alerts-panel__mark-all" data-af-alerts-mark-all><?php esc_html_e( 'Leer todas', 'arriendo-facil' ); ?></button>
					</div>
					<ul class="af-alerts-list" id="af-alerts-list" data-af-alerts-list>
						<?php if ( empty( $af_alerts_recent ) ) : ?>
							<li class="af-alerts-empty" data-af-alerts-empty>
								<?php echo af_lucide( 'bell-off', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
								<span><?php esc_html_e( 'No tienes alertas pendientes.', 'arriendo-facil' ); ?></span>
							</li>
						<?php else : ?>
							<?php foreach ( $af_alerts_recent as $af_alert ) : ?>
								<?php $af_alert_severity = isset( $af_alert->severity ) ? (string) $af_alert->severity : 'info'; ?>
								<li class="af-alerts-item af-alerts-item--<?php echo esc_attr( $af_alert_severity ); ?>" data-af-alert-id="<?php echo esc_attr( (int) $af_alert->id ); ?>">
									<span class="af-alerts-item__icon"><?php echo af_lucide( 'circle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
									<div class="af-alerts-item__body">
										<span class="af-alerts-item__title"><?php echo esc_html( (string) $af_alert->title ); ?></span>
										<?php if ( ! empty( $af_alert->message ) ) : ?>
											<span class="af-alerts-item__message"><?php echo esc_html( (string) $af_alert->message ); ?></span>
										<?php endif; ?>
									</div>
									<a class="af-alerts-item__open" href="<?php echo esc_url( ! empty( $af_alert->url ) ? (string) $af_alert->url : admin_url( 'admin.php?page=af-alerts-center' ) ); ?>" aria-label="<?php esc_attr_e( 'Abrir alerta', 'arriendo-facil' ); ?>">
										<?php echo af_lucide( 'chevron-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
									</a>
								</li>
							<?php endforeach; ?>
						<?php endif; ?>
					</ul>
					<div class="af-alerts-panel__notice" id="af-alerts-panel-notice" hidden aria-live="polite"></div>
					<a class="af-alerts-panel__footer" href="<?php echo esc_url( admin_url( 'admin.php?page=af-alerts-center' ) ); ?>">
						<span><?php esc_html_e( 'Ver todas y configurar recordatorios', 'arriendo-facil' ); ?></span>
						<?php echo af_lucide( 'arrow-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
					</a>
				</div>
			</div>
			<a class="af-app-topbar__user" href="<?php echo esc_url( admin_url( 'admin.php?page=af-admin-profile' ) ); ?>">
				<?php echo get_avatar( $current_user->ID, 28 ); ?>
				<span class="af-app-topbar__user-name"><?php echo esc_html( $current_user->display_name ); ?></span>
				<?php echo af_lucide( 'chevron-down', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
			</a>
		</div>
	</div>
		<?php
	}

	/**
	 * Forces the native dashboard to one column for tenant users so the
	 * tenant widget uses full available space.
	 *
	 * @param mixed  $result Existing option value.
	 * @param string $option Option key.
	 * @param WP_User $user  User object.
	 * @return mixed
	 */
	public function force_tenant_dashboard_single_column( $result, $option, $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $result;
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();
		if ( in_array( 'af_tenant', $roles, true ) && ! in_array( 'administrator', $roles, true ) ) {
			return 1;
		}

		return $result;
	}

	/**
	 * Restricts the accommodation admin list to posts authored by the owner.
	 *
	 * @param WP_Query $query
	 * @return void
	 */
	public function restrict_accommodation_list_to_owner( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow, $typenow;
		if ( 'edit.php' !== $pagenow || 'accommodation' !== $typenow ) {
			return;
		}
		if ( ! $this->is_restricted_owner() ) {
			return;
		}
		$query->set( 'author', get_current_user_id() );
	}

	/**
	 * Rewrites the status tab counts (All / Published / Draft…) so owners
	 * only see totals for their own accommodations.
	 *
	 * @param array $views
	 * @return array
	 */
	public function filter_accommodation_status_views_for_owner( $views ) {
		if ( ! $this->is_restricted_owner() ) {
			return $views;
		}

		$user_id = get_current_user_id();
		$counts  = wp_count_posts( 'accommodation', 'readable' );

		// Recount per status scoped to this author.
		$statuses = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );
		$scoped   = array_fill_keys( $statuses, 0 );
		$total    = 0;

		foreach ( $statuses as $status ) {
			$q = new WP_Query( array(
				'post_type'      => 'accommodation',
				'post_status'    => $status,
				'author'         => $user_id,
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
			) );
			$scoped[ $status ] = (int) $q->found_posts;
			if ( 'trash' !== $status ) {
				$total += $scoped[ $status ];
			}
		}

		$base_url = admin_url( 'edit.php?post_type=accommodation' );
		$current  = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';

		$out = array();

		// "All" view.
		if ( isset( $views['all'] ) ) {
			$class      = ( '' === $current || 'all' === $current ) ? 'current' : '';
			$out['all'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( $base_url ),
				esc_attr( $class ),
				esc_html__( 'Todos', 'arriendo-facil' ),
				$total
			);
		}

		$labels = array(
			'publish' => __( 'Publicados', 'arriendo-facil' ),
			'future'  => __( 'Programados', 'arriendo-facil' ),
			'draft'   => __( 'Borradores', 'arriendo-facil' ),
			'pending' => __( 'Pendientes', 'arriendo-facil' ),
			'private' => __( 'Privados', 'arriendo-facil' ),
			'trash'   => __( 'Papelera', 'arriendo-facil' ),
		);

		foreach ( $labels as $status => $label ) {
			if ( ! isset( $views[ $status ] ) ) {
				continue;
			}
			if ( 0 === $scoped[ $status ] ) {
				continue;
			}
			$class = ( $current === $status ) ? 'current' : '';
			$url   = add_query_arg( 'post_status', $status, $base_url );
			$out[ $status ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label ),
				$scoped[ $status ]
			);
		}

		unset( $counts );

		return $out;
	}

	/**
	 * Redirects owner/tenant users to their dashboard right after login.
	 *
	 * @param string           $redirect_to           Requested redirect destination.
	 * @param string           $requested_redirect_to Redirect destination passed to login form.
	 * @param WP_User|WP_Error $user                  Authenticated user object.
	 * @return string
	 */
	public function redirect_owner_after_login( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $redirect_to;
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();

		if ( in_array( 'af_tenant', $roles, true ) && ! in_array( 'administrator', $roles, true ) ) {
			return $this->get_tenant_dashboard_url();
		}

		if ( in_array( 'af_property_admin', $roles, true ) && ! in_array( 'administrator', $roles, true ) ) {
			return admin_url( 'admin.php?page=arriendo-facil' );
		}

		return $redirect_to;
	}

	/**
	 * Blocks login for property-admin (subadmin) licenses marked as
	 * suspended. Administrators are never affected.
	 *
	 * @param WP_User|WP_Error $user     Authenticated user or error.
	 * @param string            $password Raw password (unused).
	 * @return WP_User|WP_Error
	 */
	public function block_suspended_property_admin_login( $user, $password ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();
		if ( ! in_array( 'af_property_admin', $roles, true ) || in_array( 'administrator', $roles, true ) ) {
			return $user;
		}

		if ( 'suspended' === get_user_meta( $user->ID, 'af_license_status', true ) ) {
			return new WP_Error( 'af_license_suspended', __( '<strong>Error:</strong> tu licencia está suspendida. Contacta al administrador de la plataforma.', 'arriendo-facil' ) );
		}

		return $user;
	}

	/**
	 * Resolves tenant dashboard URL.
	 *
	 * @return string
	 */
	private function get_tenant_dashboard_url() {
		return admin_url();
	}

	/**
	 * Prevents owner users from landing on WordPress native dashboard.
	 *
	 * @return void
	 */
	public function redirect_owner_from_wp_dashboard() {
		if ( ! is_admin() || wp_doing_ajax() || current_user_can( 'manage_options' ) ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();
		if ( in_array( 'af_tenant', $roles, true ) ) {
			return;
		}

		if ( ! Arriendo_Facil_Accommodation::user_is_owner() ) {
			return;
		}

		global $pagenow;
		$is_dashboard = ( 'index.php' === $pagenow );

		if ( ! $is_dashboard ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=arriendo-facil' ) );
		exit;
	}

	/**
	 * Removes native WordPress dashboard widgets for owner users.
	 *
	 * @return void
	 */
	public function remove_owner_dashboard_widgets() {
		if ( ! Arriendo_Facil_Accommodation::user_is_owner() ) {
			return;
		}

		remove_meta_box( 'dashboard_right_now', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_incoming_links', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_plugins', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_secondary', 'dashboard', 'side' );
	}

	/**
	 * Registers tenant sections under the native Dashboard menu.
	 *
	 * @return void
	 */
	public function register_tenant_dashboard_pages() {
		if ( ! $this->is_restricted_tenant() ) {
			return;
		}

		add_dashboard_page(
			__( 'Mi perfil', 'arriendo-facil' ),
			__( 'Mi perfil', 'arriendo-facil' ),
			'af_tenant_portal',
			'af-tenant-profile',
			array( $this, 'render_tenant_profile_page' )
		);

		add_dashboard_page(
			__( 'Historial de arriendos', 'arriendo-facil' ),
			__( 'Historial de arriendos', 'arriendo-facil' ),
			'af_tenant_view_own_leases',
			'af-tenant-rentals',
			array( $this, 'render_tenant_rentals_page' )
		);

		add_dashboard_page(
			__( 'Reseñas', 'arriendo-facil' ),
			__( 'Reseñas', 'arriendo-facil' ),
			'af_tenant_submit_reviews',
			'af-tenant-reviews',
			array( $this, 'render_tenant_reviews_page' )
		);

		add_dashboard_page(
			__( 'Calendario', 'arriendo-facil' ),
			__( 'Calendario', 'arriendo-facil' ),
			'af_tenant_view_own_reservations',
			'af-tenant-calendar',
			array( $this, 'render_tenant_calendar_page' )
		);
	}

	/**
	 * Removes default dashboard widgets for tenant users.
	 *
	 * @return void
	 */
	public function remove_tenant_dashboard_widgets() {
		if ( ! $this->is_restricted_tenant() ) {
			return;
		}

		remove_meta_box( 'dashboard_right_now', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_incoming_links', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_plugins', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_secondary', 'dashboard', 'side' );
	}

	/**
	 * Registers tenant summary widget on native dashboard.
	 *
	 * @return void
	 */
	public function register_tenant_dashboard_widget() {
		if ( ! $this->is_restricted_tenant() ) {
			return;
		}

		global $wp_meta_boxes;

		wp_add_dashboard_widget(
			'af_tenant_dashboard_summary',
			__( 'Arriendo Fácil — Mi panel de inquilino', 'arriendo-facil' ),
			array( $this, 'render_tenant_dashboard_widget' )
		);

		if ( isset( $wp_meta_boxes['dashboard']['normal']['core'] ) && is_array( $wp_meta_boxes['dashboard']['normal']['core'] ) ) {
			$normal = $wp_meta_boxes['dashboard']['normal']['core'];
			if ( isset( $normal['af_tenant_dashboard_summary'] ) ) {
				$tenant_widget = array( 'af_tenant_dashboard_summary' => $normal['af_tenant_dashboard_summary'] );
				unset( $normal['af_tenant_dashboard_summary'] );
				$wp_meta_boxes['dashboard']['normal']['core'] = $tenant_widget + $normal;
			}
		}
	}

	/**
	 * Renders tenant summary widget with quick access sections.
	 *
	 * @return void
	 */
	public function render_tenant_dashboard_widget() {
		$context = $this->get_tenant_portal_context();
		$stats   = isset( $context['stats'] ) && is_array( $context['stats'] ) ? $context['stats'] : array();

		$lease_total       = isset( $stats['lease_total'] ) ? (int) $stats['lease_total'] : 0;
		$lease_active      = isset( $stats['lease_active'] ) ? (int) $stats['lease_active'] : 0;
		$visit_upcoming    = isset( $stats['visit_upcoming'] ) ? (int) $stats['visit_upcoming'] : 0;
		$reviews_pending   = isset( $stats['reviews_pending'] ) ? (int) $stats['reviews_pending'] : 0;

		$profile_url  = admin_url( 'admin.php?page=af-tenant-profile' );
		$rentals_url  = admin_url( 'admin.php?page=af-tenant-rentals' );
		$reviews_url  = admin_url( 'admin.php?page=af-tenant-reviews' );
		$calendar_url = admin_url( 'admin.php?page=af-tenant-calendar' );
		?>
		<div class="af-dash-widget af-tenant-dash-widget">
			<div class="af-dash-widget__hero">
				<span class="af-dash-widget__logo" aria-hidden="true">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3l8 4v5c0 5.25-3.5 8.9-8 10-4.5-1.1-8-4.75-8-10V7l8-4z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9.5 12.2l1.8 1.8 3.4-3.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</span>
				<div class="af-dash-widget__hero-text">
					<h3><?php esc_html_e( 'Tu experiencia de arriendo', 'arriendo-facil' ); ?></h3>
					<p><?php esc_html_e( 'Accede rápido a perfil, historial, reseñas y calendario.', 'arriendo-facil' ); ?></p>
				</div>
			</div>

			<div class="af-dash-widget__stats">
				<a class="af-dash-widget__stat" href="<?php echo esc_url( $rentals_url ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Arriendos', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $lease_total ) ); ?></span>
				</a>
				<a class="af-dash-widget__stat" href="<?php echo esc_url( $rentals_url ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Activos', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $lease_active ) ); ?></span>
				</a>
				<a class="af-dash-widget__stat <?php echo $visit_upcoming > 0 ? 'af-dash-widget__stat--attention' : ''; ?>" href="<?php echo esc_url( $calendar_url ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Próximas visitas', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $visit_upcoming ) ); ?></span>
				</a>
				<a class="af-dash-widget__stat <?php echo $reviews_pending > 0 ? 'af-dash-widget__stat--attention' : ''; ?>" href="<?php echo esc_url( $reviews_url ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Reseñas pendientes', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $reviews_pending ) ); ?></span>
				</a>
			</div>

			<div class="af-dash-widget__actions">
				<a class="af-dash-btn af-dash-btn--primary" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Editar perfil', 'arriendo-facil' ); ?></a>
				<a class="af-dash-btn af-dash-btn--ghost" href="<?php echo esc_url( $reviews_url ); ?>"><?php esc_html_e( 'Gestionar reseñas', 'arriendo-facil' ); ?></a>
				<a class="af-dash-btn af-dash-btn--ghost" href="<?php echo esc_url( $calendar_url ); ?>"><?php esc_html_e( 'Ver calendario', 'arriendo-facil' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders tenant profile section.
	 *
	 * @return void
	 */
	public function render_tenant_profile_page() {
		if ( ! $this->is_restricted_tenant() ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta sección.', 'arriendo-facil' ) );
		}

		$context       = $this->get_tenant_portal_context();
		$user          = isset( $context['user'] ) && $context['user'] instanceof WP_User ? $context['user'] : wp_get_current_user();
		$email_verified = (int) get_user_meta( (int) $user->ID, 'af_tenant_email_verified', true );
		$terms_at      = (string) get_user_meta( (int) $user->ID, 'af_tenant_terms_accepted_at', true );
		$profile_url   = admin_url( 'profile.php' );
		?>
		<div class="wrap af-shell af-tenant-portal">
			<div class="af-page-header">
				<div class="af-page-header__title">
					<span class="af-page-header__eyebrow"><?php esc_html_e( 'Inquilino', 'arriendo-facil' ); ?></span>
					<h1><?php esc_html_e( 'Mi perfil', 'arriendo-facil' ); ?></h1>
					<p class="af-page-header__subtitle"><?php esc_html_e( 'Administra tus datos personales y seguridad de cuenta.', 'arriendo-facil' ); ?></p>
				</div>
				<div class="af-page-header__actions">
					<a class="button af-btn af-btn--primary" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Abrir editor de perfil', 'arriendo-facil' ); ?></a>
				</div>
			</div>

			<div class="af-kpi-grid">
				<div class="af-kpi af-kpi--info">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( (string) $user->display_name ); ?></div>
				</div>
				<div class="af-kpi <?php echo $email_verified ? 'af-kpi--success' : 'af-kpi--attention'; ?>">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Correo verificado', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( $email_verified ? __( 'Sí', 'arriendo-facil' ) : __( 'Pendiente', 'arriendo-facil' ) ); ?></div>
				</div>
				<div class="af-kpi af-kpi--accent">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Términos aceptados', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( '' !== $terms_at ? wp_date( 'd/m/Y H:i', strtotime( $terms_at ) ) : __( 'Sin registro', 'arriendo-facil' ) ); ?></div>
				</div>
			</div>

			<section class="af-section">
				<div class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Edición de perfil recomendada', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Usa la vista nativa de WordPress, ya adaptada a la estética de Arriendo Fácil, para actualizar nombre, contraseña y datos de contacto.', 'arriendo-facil' ); ?></p>
					</div>
				</div>
				<p><a class="button af-btn af-btn--ghost" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Ir a editar perfil', 'arriendo-facil' ); ?></a></p>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders tenant rental history section.
	 *
	 * @return void
	 */
	public function render_tenant_rentals_page() {
		if ( ! $this->is_restricted_tenant() ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta sección.', 'arriendo-facil' ) );
		}

		$leases = $this->get_tenant_lease_rows( 60 );
		$visits = $this->get_tenant_visit_rows( 40 );
		?>
		<div class="wrap af-shell af-tenant-portal">
			<div class="af-page-header">
				<div class="af-page-header__title">
					<span class="af-page-header__eyebrow"><?php esc_html_e( 'Historial', 'arriendo-facil' ); ?></span>
					<h1><?php esc_html_e( 'Historial de arriendos', 'arriendo-facil' ); ?></h1>
					<p class="af-page-header__subtitle"><?php esc_html_e( 'Inspirado en Airbnb y Booking: revisa tus estancias, estado y fechas clave en un solo lugar.', 'arriendo-facil' ); ?></p>
				</div>
			</div>

			<section class="af-section">
				<div class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Estancias registradas', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Histórico de arriendos con fechas y estado de cada contrato.', 'arriendo-facil' ); ?></p>
					</div>
				</div>
				<?php if ( empty( $leases ) ) : ?>
					<p><?php esc_html_e( 'Aún no tienes arriendos registrados.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<table class="widefat striped af-data-table af-data-table--tenant">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Alojamiento', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Inicio', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Fin', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Valor mensual', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $leases as $lease ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Alojamiento', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $lease['accommodation_title'] ) ? (string) $lease['accommodation_title'] : __( 'Alojamiento', 'arriendo-facil' ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Inicio', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $lease['start_date'] ) ? (string) wp_date( 'd/m/Y', strtotime( (string) $lease['start_date'] ) ) : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Fin', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $lease['end_date'] ) ? (string) wp_date( 'd/m/Y', strtotime( (string) $lease['end_date'] ) ) : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Valor mensual', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $lease['monthly_rent'] ) ? '$' . number_format_i18n( (float) $lease['monthly_rent'], 2 ) : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $lease['status_label'] ) ? (string) $lease['status_label'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>

			<section class="af-section">
				<div class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Visitas y reservas', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Seguimiento de visitas agendadas y reservas confirmadas en tu proceso de arriendo.', 'arriendo-facil' ); ?></p>
					</div>
				</div>
				<?php if ( empty( $visits ) ) : ?>
					<p><?php esc_html_e( 'Aún no tienes visitas o reservas registradas.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<table class="widefat striped af-data-table af-data-table--tenant">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Alojamiento', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Hora', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $visits as $visit ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Alojamiento', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $visit['accommodation_title'] ) ? (string) $visit['accommodation_title'] : __( 'Alojamiento', 'arriendo-facil' ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Fecha', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $visit['visit_date'] ) ? (string) wp_date( 'd/m/Y', strtotime( (string) $visit['visit_date'] ) ) : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Hora', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $visit['start_time'] ) ? (string) substr( (string) $visit['start_time'], 0, 5 ) : '--:--' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $visit['status_label'] ) ? (string) $visit['status_label'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders tenant reviews section.
	 *
	 * @return void
	 */
	public function render_tenant_reviews_page() {
		if ( ! $this->is_restricted_tenant() ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta sección.', 'arriendo-facil' ) );
		}

		$summary = $this->get_tenant_reviews_summary();
		$nonce   = wp_create_nonce( 'af_guest_nonce' );
		?>
		<div class="wrap af-shell af-tenant-portal">
			<div class="af-page-header">
				<div class="af-page-header__title">
					<span class="af-page-header__eyebrow"><?php esc_html_e( 'Reputación', 'arriendo-facil' ); ?></span>
					<h1><?php esc_html_e( 'Reseñas', 'arriendo-facil' ); ?></h1>
					<p class="af-page-header__subtitle"><?php esc_html_e( 'Comparte tu experiencia como en Airbnb: califica al propietario y la propiedad cuando tengas reseñas pendientes.', 'arriendo-facil' ); ?></p>
				</div>
			</div>

			<div class="af-kpi-grid">
				<div class="af-kpi af-kpi--info">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Reseñas completadas', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( (int) $summary['completed'] ) ); ?></div>
				</div>
				<div class="af-kpi <?php echo (int) $summary['pending'] > 0 ? 'af-kpi--attention' : 'af-kpi--success'; ?>">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Reseñas pendientes', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( (int) $summary['pending'] ) ); ?></div>
				</div>
				<div class="af-kpi af-kpi--accent">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Promedio otorgado', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( number_format( (float) $summary['avg_stars'], 2 ) ); ?></div>
				</div>
			</div>

			<section class="af-section">
				<div class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Calificar estancia', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Este botón genera un enlace seguro y temporal del flujo de reseñas existente, sin alterar la lógica actual de tokens.', 'arriendo-facil' ); ?></p>
					</div>
				</div>
				<p>
					<button type="button" class="button af-btn af-btn--primary" id="af-tenant-open-review-flow" data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Abrir mis reseñas pendientes', 'arriendo-facil' ); ?>
					</button>
				</p>
				<p id="af-tenant-review-feedback" aria-live="polite"></p>
			</section>
		</div>

		<script>
		(function(){
			const button = document.getElementById('af-tenant-open-review-flow');
			const feedback = document.getElementById('af-tenant-review-feedback');
			if(!button || !feedback){ return; }

			button.addEventListener('click', async function(){
				button.disabled = true;
				feedback.textContent = <?php echo wp_json_encode( __( 'Generando enlace de reseña...', 'arriendo-facil' ) ); ?>;

				const payload = new URLSearchParams();
				payload.set('action', 'af_tenant_request_review_link');
				payload.set('nonce', button.getAttribute('data-nonce') || '');

				let json = null;
				try {
					const response = await fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
						body: payload.toString()
					});
					json = await response.json();
				} catch (err) {
					button.disabled = false;
					feedback.textContent = <?php echo wp_json_encode( __( 'No se pudo contactar al servidor. Intenta nuevamente.', 'arriendo-facil' ) ); ?>;
					return;
				}

				button.disabled = false;
				if(!json || !json.success){
					feedback.textContent = (json && json.data && json.data.message)
						? String(json.data.message)
						: <?php echo wp_json_encode( __( 'No fue posible abrir tus reseñas en este momento.', 'arriendo-facil' ) ); ?>;
					return;
				}

				const reviewUrl = json && json.data && json.data.review_url ? String(json.data.review_url) : '';
				feedback.textContent = (json && json.data && json.data.message)
					? String(json.data.message)
					: <?php echo wp_json_encode( __( 'Enlace generado correctamente.', 'arriendo-facil' ) ); ?>;

				if(reviewUrl){
					window.location.href = reviewUrl;
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Renders tenant calendar section.
	 *
	 * @return void
	 */
	public function render_tenant_calendar_page() {
		if ( ! $this->is_restricted_tenant() ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta sección.', 'arriendo-facil' ) );
		}

		$events = $this->get_tenant_calendar_events( 80 );
		?>
		<div class="wrap af-shell af-tenant-portal">
			<div class="af-page-header">
				<div class="af-page-header__title">
					<span class="af-page-header__eyebrow"><?php esc_html_e( 'Agenda', 'arriendo-facil' ); ?></span>
					<h1><?php esc_html_e( 'Calendario de inquilino', 'arriendo-facil' ); ?></h1>
					<p class="af-page-header__subtitle"><?php esc_html_e( 'Vista cronológica de estancias y visitas próximas, estilo experiencia de viaje.', 'arriendo-facil' ); ?></p>
				</div>
			</div>

			<section class="af-section">
				<div class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Eventos próximos', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Línea de tiempo de estancias y visitas confirmadas.', 'arriendo-facil' ); ?></p>
					</div>
				</div>
				<?php if ( empty( $events ) ) : ?>
					<p><?php esc_html_e( 'No tienes eventos próximos en el calendario.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<table class="widefat striped af-data-table af-data-table--tenant">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Detalle', 'arriendo-facil' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $events as $event ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Fecha', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $event['date'] ) ? wp_date( 'd/m/Y', strtotime( (string) $event['date'] ) ) : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Tipo', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $event['type_label'] ) ? (string) $event['type_label'] : '' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Detalle', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $event['detail'] ) ? (string) $event['detail'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Returns tenant context and summary stats for dashboard sections.
	 *
	 * @return array<string,mixed>
	 */
	private function get_tenant_portal_context() {
		$user = wp_get_current_user();
		if ( ! ( $user instanceof WP_User ) ) {
			return array(
				'user'     => null,
				'email'    => '',
				'guest_id' => 0,
				'stats'    => array(),
			);
		}

		global $wpdb;
		$email = sanitize_email( (string) $user->user_email );

		$guest_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}af_guests WHERE user_id = %d ORDER BY id DESC LIMIT 1",
				(int) $user->ID
			)
		);

		if ( ! $guest_id && is_email( $email ) ) {
			$guest_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}af_guests WHERE email = %s ORDER BY id DESC LIMIT 1",
					$email
				)
			);
		}

		$lease_total  = 0;
		$lease_active = 0;
		if ( $guest_id || is_email( $email ) ) {
			$lease_total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$wpdb->prefix}af_leases l
					 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
					 WHERE l.deleted_at IS NULL
					   AND ((%d > 0 AND l.guest_id = %d) OR g.email = %s)",
					$guest_id,
					$guest_id,
					$email
				)
			);

			$lease_active = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$wpdb->prefix}af_leases l
					 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
					 WHERE l.deleted_at IS NULL
					   AND l.status = %s
					   AND ((%d > 0 AND l.guest_id = %d) OR g.email = %s)",
					'active',
					$guest_id,
					$guest_id,
					$email
				)
			);
		}

		$today = gmdate( 'Y-m-d' );
		$visit_upcoming = is_email( $email ) ? (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}af_visit_bookings vb
				 LEFT JOIN {$wpdb->prefix}af_visit_slots vs ON vs.id = vb.slot_id
				 WHERE vb.guest_email = %s
				   AND vb.status IN ('confirmed','completed')
				   AND vs.visit_date >= %s",
				$email,
				$today
			)
		) : 0;

		$reviews_pending = 0;
		if ( is_email( $email ) ) {
			$review_now = gmdate( 'Y-m-d H:i:s' );
			$user_id    = (int) $user->ID;

			if ( class_exists( 'Arriendo_Facil_Review' ) && Arriendo_Facil_Review::groups_tenant_user_column_exists() ) {
				$reviews_pending = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}af_review_groups
						 WHERE reviewer_type = %s
						   AND status IN ('pending','sent')
						   AND (due_at IS NULL OR due_at >= %s)
						   AND (
							 tenant_user_id = %d
							 OR ((tenant_user_id IS NULL OR tenant_user_id = 0) AND tenant_email = %s)
						   )",
						'tenant',
						$review_now,
						$user_id,
						$email
					)
				);
			} else {
				$reviews_pending = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}af_review_groups
						 WHERE tenant_email = %s
						   AND reviewer_type = %s
						   AND status IN ('pending','sent')
						   AND (due_at IS NULL OR due_at >= %s)",
						$email,
						'tenant',
						$review_now
					)
				);
			}
		}

		return array(
			'user'     => $user,
			'email'    => $email,
			'guest_id' => $guest_id,
			'stats'    => array(
				'lease_total'      => $lease_total,
				'lease_active'     => $lease_active,
				'visit_upcoming'   => $visit_upcoming,
				'reviews_pending'  => $reviews_pending,
			),
		);
	}

	/**
	 * Returns tenant lease rows for history and calendar sections.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_tenant_lease_rows( $limit = 40 ) {
		$context = $this->get_tenant_portal_context();
		$email   = isset( $context['email'] ) ? sanitize_email( (string) $context['email'] ) : '';
		$guest_id = isset( $context['guest_id'] ) ? absint( $context['guest_id'] ) : 0;

		if ( ! $guest_id && ! is_email( $email ) ) {
			return array();
		}

		global $wpdb;
		$limit = max( 1, absint( $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.accommodation_id, l.start_date, l.end_date, l.monthly_rent, l.status,
				        COALESCE(NULLIF(p.post_title, ''), %s) AS accommodation_title
				 FROM {$wpdb->prefix}af_leases l
				 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 WHERE l.deleted_at IS NULL
				   AND ((%d > 0 AND l.guest_id = %d) OR g.email = %s)
				 ORDER BY l.start_date DESC, l.id DESC
				 LIMIT %d",
				__( 'Alojamiento', 'arriendo-facil' ),
				$guest_id,
				$guest_id,
				$email,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$status_map = array(
			'active'    => __( 'Activo', 'arriendo-facil' ),
			'draft'     => __( 'Borrador', 'arriendo-facil' ),
			'completed' => __( 'Completado', 'arriendo-facil' ),
			'cancelled' => __( 'Cancelado', 'arriendo-facil' ),
		);

		foreach ( $rows as &$row ) {
			$status = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
			$row['status_label'] = isset( $status_map[ $status ] ) ? $status_map[ $status ] : ucfirst( $status );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Returns tenant review summary.
	 *
	 * @return array<string,mixed>
	 */
	private function get_tenant_reviews_summary() {
		$context = $this->get_tenant_portal_context();
		$email   = isset( $context['email'] ) ? sanitize_email( (string) $context['email'] ) : '';
		$user_id = isset( $context['user'] ) && $context['user'] instanceof WP_User ? (int) $context['user']->ID : 0;

		if ( ! is_email( $email ) ) {
			return array(
				'completed' => 0,
				'pending'   => 0,
				'avg_stars' => 0,
			);
		}

		global $wpdb;

		$use_user_link_groups  = class_exists( 'Arriendo_Facil_Review' ) && Arriendo_Facil_Review::groups_tenant_user_column_exists();
		$use_user_link_reviews = class_exists( 'Arriendo_Facil_Review' ) && Arriendo_Facil_Review::reviews_tenant_user_column_exists();

		if ( $use_user_link_reviews ) {
			$completed = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews
					 WHERE review_direction IN ('tenant_to_owner','tenant_to_property')
					   AND status = 'completed'
					   AND (
						 tenant_user_id = %d
						 OR ((tenant_user_id IS NULL OR tenant_user_id = 0) AND tenant_email = %s)
					   )",
					$user_id,
					$email
				)
			);
		} else {
			$completed = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews
					 WHERE tenant_email = %s
					   AND review_direction IN ('tenant_to_owner','tenant_to_property')
					   AND status = 'completed'",
					$email
				)
			);
		}

		if ( $use_user_link_groups ) {
			$pending = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}af_review_groups
					 WHERE reviewer_type = %s
					   AND status IN ('pending','sent')
					   AND (due_at IS NULL OR due_at >= %s)
					   AND (
						 tenant_user_id = %d
						 OR ((tenant_user_id IS NULL OR tenant_user_id = 0) AND tenant_email = %s)
					   )",
					'tenant',
					gmdate( 'Y-m-d H:i:s' ),
					$user_id,
					$email
				)
			);
		} else {
			$pending = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}af_review_groups
					 WHERE tenant_email = %s
					   AND reviewer_type = %s
					   AND status IN ('pending','sent')
					   AND (due_at IS NULL OR due_at >= %s)",
					$email,
					'tenant',
					gmdate( 'Y-m-d H:i:s' )
				)
			);
		}

		if ( $use_user_link_reviews ) {
			$avg_stars = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT AVG(stars) FROM {$wpdb->prefix}af_reviews
					 WHERE review_direction IN ('tenant_to_owner','tenant_to_property')
					   AND status = 'completed'
					   AND (
						 tenant_user_id = %d
						 OR ((tenant_user_id IS NULL OR tenant_user_id = 0) AND tenant_email = %s)
					   )",
					$user_id,
					$email
				)
			);
		} else {
			$avg_stars = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT AVG(stars) FROM {$wpdb->prefix}af_reviews
					 WHERE tenant_email = %s
					   AND review_direction IN ('tenant_to_owner','tenant_to_property')
					   AND status = 'completed'",
					$email
				)
			);
		}

		return array(
			'completed' => $completed,
			'pending'   => $pending,
			'avg_stars' => $avg_stars,
		);
	}

	/**
	 * Returns tenant visit rows aligned to reservation-style tracking.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_tenant_visit_rows( $limit = 30 ) {
		$context = $this->get_tenant_portal_context();
		$email   = isset( $context['email'] ) ? sanitize_email( (string) $context['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return array();
		}

		global $wpdb;
		$limit = max( 1, absint( $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vb.id, vb.status, vs.visit_date, vs.start_time,
				        COALESCE(NULLIF(p.post_title, ''), %s) AS accommodation_title
				 FROM {$wpdb->prefix}af_visit_bookings vb
				 LEFT JOIN {$wpdb->prefix}af_visit_slots vs ON vs.id = vb.slot_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = vb.accommodation_id
				 WHERE vb.guest_email = %s
				 ORDER BY vs.visit_date DESC, vb.id DESC
				 LIMIT %d",
				__( 'Alojamiento', 'arriendo-facil' ),
				$email,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$status_map = array(
			'confirmed' => __( 'Confirmada', 'arriendo-facil' ),
			'completed' => __( 'Completada', 'arriendo-facil' ),
			'cancelled' => __( 'Cancelada', 'arriendo-facil' ),
		);

		foreach ( $rows as &$row ) {
			$status = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
			$row['status_label'] = isset( $status_map[ $status ] ) ? $status_map[ $status ] : ucfirst( $status );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Returns sorted tenant calendar events from leases and visit bookings.
	 *
	 * @param int $limit Max events.
	 * @return array<int,array<string,string>>
	 */
	private function get_tenant_calendar_events( $limit = 60 ) {
		$events = array();
		$today  = gmdate( 'Y-m-d' );

		$leases = $this->get_tenant_lease_rows( max( 20, absint( $limit ) ) );
		foreach ( $leases as $lease ) {
			$end_date = isset( $lease['end_date'] ) ? (string) $lease['end_date'] : '';
			if ( '' === $end_date || $end_date < $today ) {
				continue;
			}

			$events[] = array(
				'date'       => isset( $lease['start_date'] ) ? (string) $lease['start_date'] : $today,
				'type_label' => __( 'Inicio de estancia', 'arriendo-facil' ),
				'detail'     => sprintf(
					/* translators: 1: accommodation title 2: end date */
					__( '%1$s hasta %2$s', 'arriendo-facil' ),
					isset( $lease['accommodation_title'] ) ? (string) $lease['accommodation_title'] : __( 'Alojamiento', 'arriendo-facil' ),
					wp_date( 'd/m/Y', strtotime( $end_date ) )
				),
			);
		}

		$context = $this->get_tenant_portal_context();
		$email   = isset( $context['email'] ) ? sanitize_email( (string) $context['email'] ) : '';
		if ( is_email( $email ) ) {
			global $wpdb;
			$visit_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT vs.visit_date, vs.start_time, vb.status,
					        COALESCE(NULLIF(p.post_title, ''), %s) AS accommodation_title
					 FROM {$wpdb->prefix}af_visit_bookings vb
					 LEFT JOIN {$wpdb->prefix}af_visit_slots vs ON vs.id = vb.slot_id
					 LEFT JOIN {$wpdb->posts} p ON p.ID = vb.accommodation_id
					 WHERE vb.guest_email = %s
					   AND vb.status IN ('confirmed','completed')
					   AND vs.visit_date >= %s
					 ORDER BY vs.visit_date ASC
					 LIMIT %d",
					__( 'Alojamiento', 'arriendo-facil' ),
					$email,
					$today,
					max( 10, absint( $limit ) )
				),
				ARRAY_A
			);

			if ( is_array( $visit_rows ) ) {
				foreach ( $visit_rows as $visit ) {
					$visit_date = isset( $visit['visit_date'] ) ? (string) $visit['visit_date'] : '';
					if ( '' === $visit_date ) {
						continue;
					}

					$events[] = array(
						'date'       => $visit_date,
						'type_label' => __( 'Visita agendada', 'arriendo-facil' ),
						'detail'     => sprintf(
							/* translators: 1: accommodation title 2: start time */
							__( '%1$s a las %2$s', 'arriendo-facil' ),
							isset( $visit['accommodation_title'] ) ? (string) $visit['accommodation_title'] : __( 'Alojamiento', 'arriendo-facil' ),
							isset( $visit['start_time'] ) ? (string) substr( (string) $visit['start_time'], 0, 5 ) : '--:--'
						),
					);
				}
			}
		}

		usort(
			$events,
			static function ( $a, $b ) {
				$date_a = isset( $a['date'] ) ? strtotime( (string) $a['date'] ) : 0;
				$date_b = isset( $b['date'] ) ? strtotime( (string) $b['date'] ) : 0;
				if ( $date_a === $date_b ) {
					return 0;
				}
				return ( $date_a < $date_b ) ? -1 : 1;
			}
		);

		if ( count( $events ) > $limit ) {
			$events = array_slice( $events, 0, $limit );
		}

		return $events;
	}

	/**
	 * Registers the Arriendo Facil summary widget on the native WP dashboard,
	 * pinned to the top of the main column so it is the first thing admins see.
	 *
	 * @return void
	 */
	public function register_native_dashboard_widget() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		if ( Arriendo_Facil_Accommodation::user_is_owner() ) {
			return;
		}

		global $wp_meta_boxes;

		wp_add_dashboard_widget(
			'af_dashboard_summary',
			__( 'Arriendo Fácil — Resumen operativo', 'arriendo-facil' ),
			array( $this, 'render_native_dashboard_widget' )
		);

		if ( isset( $wp_meta_boxes['dashboard']['normal']['core'] ) && is_array( $wp_meta_boxes['dashboard']['normal']['core'] ) ) {
			$normal = $wp_meta_boxes['dashboard']['normal']['core'];
			if ( isset( $normal['af_dashboard_summary'] ) ) {
				$af_widget = array( 'af_dashboard_summary' => $normal['af_dashboard_summary'] );
				unset( $normal['af_dashboard_summary'] );
				$wp_meta_boxes['dashboard']['normal']['core'] = $af_widget + $normal;
			}
		}
	}

	/**
	 * Renders the AF summary widget on the native WP dashboard.
	 *
	 * @return void
	 */
	public function render_native_dashboard_widget() {
		global $wpdb;

		$accommodation_count = (int) wp_count_posts( 'accommodation' )->publish;
		$active_leases       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE status = 'active' AND deleted_at IS NULL" );
		$pending_cleaning    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_cleaning_requests WHERE status = 'pending'" );
		$pending_queue       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_interest_queue WHERE status = 'pending'" );

		$panel_url    = admin_url( 'admin.php?page=arriendo-facil' );
		$leases_url   = admin_url( 'admin.php?page=af-leases' );
		$cleaning_url = admin_url( 'admin.php?page=af-cleaning-requests' );
		$guests_url   = admin_url( 'admin.php?page=af-guests' );
		$new_url      = admin_url( 'post-new.php?post_type=accommodation' );
		?>
		<div class="af-dash-widget">

			<div class="af-dash-widget__hero">
				<span class="af-dash-widget__logo" aria-hidden="true">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 11l9-8 9 8v10a1 1 0 01-1 1h-5v-6H10v6H4a1 1 0 01-1-1V11z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
				</span>
				<div class="af-dash-widget__hero-text">
					<h3><?php esc_html_e( 'Panel de operaciones', 'arriendo-facil' ); ?></h3>
					<p><?php esc_html_e( 'El pulso de tu plataforma en un vistazo.', 'arriendo-facil' ); ?></p>
				</div>
			</div>

			<div class="af-dash-widget__stats">
				<a class="af-dash-widget__stat" href="<?php echo esc_url( admin_url( 'edit.php?post_type=accommodation' ) ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Alojamientos', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $accommodation_count ) ); ?></span>
				</a>
				<a class="af-dash-widget__stat" href="<?php echo esc_url( $leases_url ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Contratos activos', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $active_leases ) ); ?></span>
				</a>
				<a class="af-dash-widget__stat <?php echo $pending_cleaning > 0 ? 'af-dash-widget__stat--attention' : ''; ?>" href="<?php echo esc_url( $cleaning_url ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Limpiezas pendientes', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $pending_cleaning ) ); ?></span>
				</a>
				<a class="af-dash-widget__stat <?php echo $pending_queue > 0 ? 'af-dash-widget__stat--attention' : ''; ?>" href="<?php echo esc_url( $guests_url ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Huéspedes por aprobar', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $pending_queue ) ); ?></span>
				</a>
			</div>

			<div class="af-dash-widget__actions">
				<a class="af-dash-btn af-dash-btn--primary" href="<?php echo esc_url( $panel_url ); ?>">
					<?php esc_html_e( 'Abrir panel completo', 'arriendo-facil' ); ?>
				</a>
				<a class="af-dash-btn af-dash-btn--ghost" href="<?php echo esc_url( $new_url ); ?>">
					<?php esc_html_e( '+ Nuevo alojamiento', 'arriendo-facil' ); ?>
				</a>
			</div>

		</div>
		<?php
	}

	/**
	 * Enqueues plugin admin CSS and JS.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$tokens_css_path       = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-tokens.css';
		$shell_css_path        = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-shell.css';
		$chrome_css_path       = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-admin-chrome.css';
		$forms_css_path        = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-forms.css';
		$wp_dashboard_css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-wp-dashboard.css';
		$tenant_dash_css_path  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-tenant-dashboard.css';
		$admin_css_path        = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/admin.css';
		$admin_js_path         = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/admin.js';

		$tokens_css_version       = file_exists( $tokens_css_path ) ? (string) filemtime( $tokens_css_path ) : ARRIENDO_FACIL_VERSION;
		$shell_css_version        = file_exists( $shell_css_path ) ? (string) filemtime( $shell_css_path ) : ARRIENDO_FACIL_VERSION;
		$chrome_css_version       = file_exists( $chrome_css_path ) ? (string) filemtime( $chrome_css_path ) : ARRIENDO_FACIL_VERSION;
		$forms_css_version        = file_exists( $forms_css_path ) ? (string) filemtime( $forms_css_path ) : ARRIENDO_FACIL_VERSION;
		$wp_dashboard_css_version = file_exists( $wp_dashboard_css_path ) ? (string) filemtime( $wp_dashboard_css_path ) : ARRIENDO_FACIL_VERSION;
		$tenant_dash_css_version  = file_exists( $tenant_dash_css_path ) ? (string) filemtime( $tenant_dash_css_path ) : ARRIENDO_FACIL_VERSION;
		$admin_css_version        = file_exists( $admin_css_path ) ? (string) filemtime( $admin_css_path ) : ARRIENDO_FACIL_VERSION;
		$admin_js_version         = file_exists( $admin_js_path ) ? (string) filemtime( $admin_js_path ) : ARRIENDO_FACIL_VERSION;

		// Design tokens must load before any component styles.
		wp_enqueue_style(
			'af-tokens',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-tokens.css',
			array(),
			$tokens_css_version
		);

		// Inter webfont — af-tokens/af-admin-chrome reference it, but it was never
		// actually loaded, so every admin screen fell back to the OS system font.
		wp_enqueue_style(
			'af-google-font-inter',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap',
			array(),
			null
		);

		// Global chrome rebrand (sidebar, adminbar, notices, body bg) on ALL admin pages.
		wp_enqueue_style(
			'af-admin-chrome',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-admin-chrome.css',
			array( 'af-tokens' ),
			$chrome_css_version
		);

		wp_enqueue_style(
			'af-shell',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-shell.css',
			array( 'af-tokens' ),
			$shell_css_version
		);

		// Forms polish: applies globally so meta-boxes and wizard also benefit.
		wp_enqueue_style(
			'af-forms',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-forms.css',
			array( 'af-shell' ),
			$forms_css_version
		);

		// Rebrand the WordPress native dashboard only on index.php.
		if ( 'index.php' === $hook ) {
			wp_enqueue_style(
				'af-wp-dashboard',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-wp-dashboard.css',
				array( 'af-tokens', 'af-admin-chrome' ),
				$wp_dashboard_css_version
			);
		}

		$tenant_hooks = array(
			'index.php',
			'dashboard_page_af-tenant-profile',
			'dashboard_page_af-tenant-rentals',
			'dashboard_page_af-tenant-reviews',
			'dashboard_page_af-tenant-calendar',
		);
		if ( $this->is_restricted_tenant() && in_array( $hook, $tenant_hooks, true ) ) {
			wp_enqueue_style(
				'af-tenant-dashboard',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-tenant-dashboard.css',
				array( 'af-tokens', 'af-shell', 'af-admin-chrome' ),
				$tenant_dash_css_version
			);
		}

		if ( $this->should_use_custom_shell() ) {
			$shell_nav_css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-admin-shell-nav.css';
			$shell_nav_js_path  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-admin-shell-nav.js';
			$alerts_css_path    = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-admin-alerts.css';
			$alerts_js_path     = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-admin-alerts.js';

			wp_enqueue_style(
				'af-admin-shell-nav',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-admin-shell-nav.css',
				array( 'af-tokens', 'af-shell', 'af-admin-chrome' ),
				file_exists( $shell_nav_css_path ) ? (string) filemtime( $shell_nav_css_path ) : ARRIENDO_FACIL_VERSION
			);

			wp_enqueue_script(
				'af-admin-shell-nav',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-admin-shell-nav.js',
				array(),
				file_exists( $shell_nav_js_path ) ? (string) filemtime( $shell_nav_js_path ) : ARRIENDO_FACIL_VERSION,
				true
			);

			wp_enqueue_style(
				'af-admin-alerts',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-admin-alerts.css',
				array( 'af-admin-shell-nav' ),
				file_exists( $alerts_css_path ) ? (string) filemtime( $alerts_css_path ) : ARRIENDO_FACIL_VERSION
			);

			wp_enqueue_script(
				'af-admin-alerts',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-admin-alerts.js',
				array(),
				file_exists( $alerts_js_path ) ? (string) filemtime( $alerts_js_path ) : ARRIENDO_FACIL_VERSION,
				true
			);

			wp_localize_script(
				'af-admin-alerts',
				'afAlerts',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( class_exists( 'Arriendo_Facil_Alerts' ) ? Arriendo_Facil_Alerts::NONCE : 'af_alerts_nonce' ),
				)
			);
		}

		wp_enqueue_style(
			'af-admin',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/admin.css',
			array( 'af-shell' ),
			$admin_css_version
		);

		wp_enqueue_script(
			'mammoth',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/vendor/mammoth.browser.min.js',
			array(),
			'1.8.0',
			true
		);

		wp_enqueue_script(
			'af-admin',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'mammoth' ),
			$admin_js_version,
			true
		);

		$tabs_js_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/admin-tabs.js';
		wp_enqueue_script(
			'af-admin-tabs',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/admin-tabs.js',
			array(),
			file_exists( $tabs_js_path ) ? (string) filemtime( $tabs_js_path ) : ARRIENDO_FACIL_VERSION,
			true
		);

		$php_post_max_bytes = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		$safe_request_bytes = (int) apply_filters( 'af_owner_contact_safe_request_bytes', min( $php_post_max_bytes, 30 * 1024 * 1024 ) );

		wp_localize_script(
			'af-admin',
			'afAdmin',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'leaseNonce'         => wp_create_nonce( 'af_lease_nonce' ),
				'cleaningNonce'      => wp_create_nonce( 'af_cleaning_request_nonce' ),
				'ownerContactNonce'  => wp_create_nonce( 'af_owner_contact_nonce' ),
				'ownerMaxFileBytes'  => min( wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ), 10 * 1024 * 1024 ),
				'ownerMaxTotalBytes' => $php_post_max_bytes,
				'ownerSafeTotalBytes'=> max( 1, $safe_request_bytes ),
				'guestNonce'         => wp_create_nonce( 'af_guest_nonce' ),
			)
		);

		// Chart.js solo en las pantallas con gráficos (Panel y Administradores).
		if ( in_array( $hook, array( 'toplevel_page_arriendo-facil', 'arriendo-facil_page_af-property-admins' ), true ) ) {
			wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', array(), '4.4.4', true );
		}

		// Estilos del Panel (hero de cobranza, tarjetas de estado y métricas operativas).
		// Se cargan también en el Calendario porque comparte el mismo diseño y
		// desbloquea af-dashboard-calendar.css (depende de este handle).
		if ( in_array( $hook, array( 'toplevel_page_arriendo-facil', 'arriendo-facil_page_af-calendar' ), true ) ) {
			$dashboard_css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-dashboard.css';
			wp_enqueue_style(
				'af-dashboard',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-dashboard.css',
				array( 'af-tokens', 'af-shell', 'af-admin-chrome', 'af-admin-shell-nav' ),
				file_exists( $dashboard_css_path ) ? (string) filemtime( $dashboard_css_path ) : ARRIENDO_FACIL_VERSION
			);
		}

		// Estilos del catálogo de inmuebles.
		if ( 'arriendo-facil_page_af-catalog' === $hook ) {
			$catalog_css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-catalog.css';
			wp_enqueue_style(
				'af-catalog',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-catalog.css',
				array( 'af-tokens', 'af-shell', 'af-admin-chrome' ),
				file_exists( $catalog_css_path ) ? (string) filemtime( $catalog_css_path ) : ARRIENDO_FACIL_VERSION
			);
		}

		// Estilos de Cobranza de inmuebles.
		if ( 'arriendo-facil_page_af-buildings' === $hook ) {
			$buildings_css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-buildings.css';
			wp_enqueue_style(
				'af-buildings',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-buildings.css',
				array( 'af-tokens', 'af-shell', 'af-forms', 'af-admin-chrome' ),
				file_exists( $buildings_css_path ) ? (string) filemtime( $buildings_css_path ) : ARRIENDO_FACIL_VERSION
			);

			// Cobranza: modal con calendario de cobros del mes y anotación de pagos.
			$cobranza_js_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-buildings-cobranza.js';
			wp_enqueue_script(
				'af-buildings-cobranza',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-buildings-cobranza.js',
				array(),
				file_exists( $cobranza_js_path ) ? (string) filemtime( $cobranza_js_path ) : ARRIENDO_FACIL_VERSION,
				true
			);
			wp_localize_script(
				'af-buildings-cobranza',
				'afCobranza',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'af_ledger_nonce' ),
					'i18n'    => self::cobranza_calendar_strings(),
				)
			);
		}

		// Estilos del hub de Facturación Electrónica (comprobantes + config SRI).
		if ( in_array( $hook, array( 'arriendo-facil_page_af-billing', 'arriendo-facil_page_af-billing-settings' ), true ) ) {
			$billing_css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-billing.css';
			wp_enqueue_style(
				'af-billing',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-billing.css',
				array( 'af-tokens', 'af-shell', 'af-forms', 'af-admin-chrome' ),
				file_exists( $billing_css_path ) ? (string) filemtime( $billing_css_path ) : ARRIENDO_FACIL_VERSION
			);
		}

		// Estilos del hub de Pagos de servicios (callout de vencimiento, filtros,
		// chip de servicio y acentos de urgencia en la tabla de vencimientos).
		if ( 'arriendo-facil_page_af-meter-readings' === $hook ) {
			$service_payments_css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-service-payments.css';
			wp_enqueue_style(
				'af-service-payments',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-service-payments.css',
				array( 'af-tokens', 'af-shell', 'af-forms', 'af-admin-chrome' ),
				file_exists( $service_payments_css_path ) ? (string) filemtime( $service_payments_css_path ) : ARRIENDO_FACIL_VERSION
			);
		}

		$screen = get_current_screen();
		if ( $screen && 'accommodation' === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_media();
			wp_enqueue_style( 'leaflet-css', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css', array(), '1.9.4' );
			wp_enqueue_script( 'leaflet-js', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js', array(), '1.9.4', true );

			$picker_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/admin-location-picker.js';
			wp_enqueue_script(
				'af-location-picker',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/admin-location-picker.js',
				array( 'jquery', 'leaflet-js' ),
				file_exists( $picker_path ) ? (string) filemtime( $picker_path ) : ARRIENDO_FACIL_VERSION,
				true
			);

			wp_localize_script( 'af-location-picker', 'afLocationPicker', array(
				'defaultLat'    => -0.1807,
				'defaultLng'    => -78.4678,
				'ecuadorBounds' => array( 'latMin' => -5, 'latMax' => 2, 'lngMin' => -81, 'lngMax' => -75 ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'af_location_nonce' ),
			) );

			// OTA Sync
			$sync_js_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/admin-ota-sync.js';
			wp_enqueue_script(
				'af-ota-sync',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/admin-ota-sync.js',
				array( 'jquery', 'af-admin' ),
				file_exists( $sync_js_path ) ? (string) filemtime( $sync_js_path ) : ARRIENDO_FACIL_VERSION,
				true
			);

			wp_localize_script( 'af-ota-sync', 'afOtaSync', array(
				'nonce' => wp_create_nonce( 'af_ota_nonce' ),
			) );
		}
	}

	/**
	 * Registers plugin settings.
	 */
	public function register_settings() {
		register_setting( 'af_ai_settings', 'af_ai_api_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'af_ai_settings', 'af_ai_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	public function pandoc_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'arriendo-facil_page_af-ai-settings' !== $screen->id ) {
			return;
		}

		$method = defined( 'AF_CONTRACT_PROCESSING_METHOD' )
			? AF_CONTRACT_PROCESSING_METHOD
			: (string) get_option( 'af_contract_processing_method', 'markdown' );

		if ( 'markdown' !== $method ) {
			return;
		}

		if ( class_exists( 'Arriendo_Facil_DOCX_Template_Processor' ) && Arriendo_Facil_DOCX_Template_Processor::is_pandoc_available() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo '<strong>' . esc_html__( 'Arriendo Facil:', 'arriendo-facil' ) . '</strong> ';
		echo esc_html__( 'Pandoc is not installed. The Markdown contract processing method requires pandoc. Install it with: sudo apt-get install pandoc (Debian/Ubuntu) or brew install pandoc (macOS). The plugin will fall back to Direct XML until pandoc is available.', 'arriendo-facil' );
		echo '</p></div>';
	}

	/**
	 * Renders the main dashboard page.
	 */
	public function render_dashboard() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Renders the leases admin page.
	 */
	public function render_catalog() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/catalog.php';
	}

	/**
	 * Renders the standalone interactive calendar page.
	 */
	public function render_calendar() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/calendar.php';
	}

	public function render_leases() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/leases.php';
	}

	/**
	 * Renders the super-admin master panel: property-admin accounts
	 * (licenses) and their aggregated metrics.
	 */
	public function render_property_admins() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/property-admins.php';
	}

	/**
	 * Renders the collections (cobranza) admin page.
	 */
	public function render_collections() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/collections.php';
	}

	/**
	 * Renders contract expirations, renewal context and deposit settlement.
	 */
	public function render_upcoming_exits() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/upcoming-exits.php';
	}

	/**
	 * Renders the buildings and units admin page.
	 */
	public function render_buildings() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/buildings.php';
	}

	/**
	 * Renders the meter readings admin page.
	 */
	public function render_meter_readings() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/meter-readings.php';
	}

	/**
	 * Renders the alerts center page (history + account configuration).
	 */
	public function render_alerts() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/alerts.php';
	}

	/**
	 * Renders the tenant communications (avisos) admin page.
	 */
	public function render_avisos() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/avisos.php';
	}

	/**
	 * Renders the collections & services hub (cobros) admin page.
	 */
	public function render_cobros() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/cobros.php';
	}

	/**
	 * Renders the owner settlements admin page.
	 */
	public function render_owner_settlements() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/owner-settlements.php';
	}

	/**
	 * Renders the maintenance and incidents admin page.
	 */
	public function render_maintenance() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/maintenance.php';
	}

	/**
	 * Renders the cleaning requests admin page.
	 */
	public function render_cleaning_requests() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/cleaning-requests.php';
	}

	/**
	 * Renders the owner contacts admin page.
	 */
	public function render_owner_contacts() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/owner-contacts.php';
	}

	/**
	 * Renders the guests admin page (or the consolidated tenant profile
	 * when a guest_id + view=profile is requested).
	 */
	public function render_guests() {
		if ( isset( $_GET['view'], $_GET['guest_id'] ) && 'profile' === sanitize_key( wp_unslash( $_GET['view'] ) ) ) {
			include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/guest-profile.php';
			return;
		}
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/guests.php';
	}

	/**
	 * Renders the reviews admin page.
	 */
	public function render_reviews() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/reviews.php';
	}

	/**
	 * Renders the AI settings page.
	 */
	public function render_ai_settings() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/ai-settings.php';
	}

	/**
	 * Renders the electronic billing list page.
	 */
	public function render_billing() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/billing.php';
	}

	/**
	 * Renders the SRI configuration page.
	 *
	 * La configuración vive ahora dentro del hub de facturación (tab "sri").
	 * Mantiene la subpágina registrada para preservar el enqueue de assets,
	 * pero cualquier acceso directo redirige al hub.
	 */
	public function render_billing_settings() {
		wp_safe_redirect( admin_url( 'admin.php?page=af-billing&tab=sri' ) );
		exit;
	}

	/**
	 * AJAX handler: resolve a short Google Maps URL to extract the final redirect URL.
	 *
	 * Defense-in-depth against SSRF (OWASP A10):
	 * - Nonce + capability check (edit_posts).
	 * - Host allow-list: only Google Maps domains permitted.
	 * - DNS pre-resolution: rejects hosts resolving to loopback / private /
	 *   link-local / reserved IPs (blocks 169.254.169.254 metadata, LAN, etc.).
	 * - Redirects capped at 3 and re-validated via http_request_args filter.
	 * - Generic error messages; details logged with [AF Security] prefix.
	 */
	public function ajax_resolve_short_url() {
		check_ajax_referer( 'af_location_nonce', 'nonce' );

		$actor_id  = get_current_user_id();
		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';

		if ( ! current_user_can( 'edit_posts' ) ) {
			error_log( sprintf( '[AF Security] resolve_short_url denied (user=%d ip=%s)', $actor_id, $remote_ip ) );
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => __( 'URL no permitida.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->is_url_safe_for_short_resolve( $url ) ) {
			error_log( sprintf( '[AF Security] resolve_short_url blocked SSRF candidate (user=%d ip=%s url=%s)', $actor_id, $remote_ip, $url ) );
			wp_send_json_error( array( 'message' => __( 'URL no permitida.', 'arriendo-facil' ) ), 400 );
		}

		$block_redirect = function ( $args, $redirect_url ) {
			if ( is_string( $redirect_url ) && '' !== $redirect_url && ! $this->is_url_safe_for_short_resolve( $redirect_url ) ) {
				return new WP_Error( 'af_ssrf_blocked', 'Redirect target not allowed' );
			}
			return $args;
		};
		add_filter( 'http_request_redirection_count', array( $this, 'cap_short_resolve_redirects' ) );
		add_filter( 'http_request_args', $block_redirect, 10, 2 );

		$response = wp_remote_get(
			$url,
			array(
				'redirection' => 3,
				'timeout'     => 8,
				'user-agent'  => 'Mozilla/5.0 (compatible; ArriendoFacilResolver/1.0)',
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		remove_filter( 'http_request_args', $block_redirect, 10 );
		remove_filter( 'http_request_redirection_count', array( $this, 'cap_short_resolve_redirects' ) );

		if ( is_wp_error( $response ) ) {
			error_log( sprintf( '[AF Security] resolve_short_url wp_remote_get failed: %s', $response->get_error_message() ) );
			wp_send_json_error( array( 'message' => __( 'No se pudo resolver la URL.', 'arriendo-facil' ) ), 502 );
		}

		$body      = wp_remote_retrieve_body( $response );
		$final_url = '';

		// Try to find coordinates in the resolved page content.
		if ( preg_match( '/@(-?\d+\.\d+),(-?\d+\.\d+)/', $body, $m ) ) {
			$final_url = '@' . $m[1] . ',' . $m[2];
		} elseif ( preg_match( '/center=(-?\d+\.\d+)%2C(-?\d+\.\d+)/', $body, $m ) ) {
			$final_url = '@' . $m[1] . ',' . $m[2];
		} elseif ( preg_match( '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $body, $m ) ) {
			$final_url = '!3d' . $m[1] . '!4d' . $m[2];
		} elseif ( preg_match( '/ll=(-?\d+\.\d+),(-?\d+\.\d+)/', $body, $m ) ) {
			$final_url = '@' . $m[1] . ',' . $m[2];
		} elseif ( preg_match( '/href="([^"]*google\.com\/maps[^"]*)"/', $body, $m ) ) {
			$final_url = html_entity_decode( $m[1] );
		} elseif ( preg_match( '/content="0;\s*url=([^"]+)"/i', $body, $m ) ) {
			$final_url = $m[1];
		} elseif ( preg_match( '/window\.location\s*=\s*["\']([^"\']+)/', $body, $m ) ) {
			$final_url = $m[1];
		}

		if ( ! $final_url ) {
			$final_url = $url;
		}

		wp_send_json_success( array( 'resolved_url' => $final_url ) );
	}

	/**
	 * AJAX: sets (or clears) the branded intro video shown on the dashboard
	 * hero. Accepts a direct video file URL (mp4/webm/ogg) or a link from a
	 * supported oEmbed provider (YouTube, Vimeo).
	 *
	 * @return void
	 */
	public function ajax_save_dashboard_hero_video() {
		check_ajax_referer( 'af_dashboard_hero_video_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$url = isset( $_POST['video_url'] ) ? esc_url_raw( wp_unslash( $_POST['video_url'] ) ) : '';

		if ( '' === $url ) {
			delete_option( 'af_dashboard_hero_video_url' );
			wp_send_json_success( array( 'message' => __( 'Video removido.', 'arriendo-facil' ) ) );
		}

		$is_direct_file = (bool) preg_match( '/\.(mp4|webm|ogg)(\?.*)?$/i', $url );
		$is_oembed       = false;

		if ( ! $is_direct_file ) {
			$is_oembed = false !== wp_oembed_get( $url, array( 'width' => 640 ) );
		}

		if ( ! $is_direct_file && ! $is_oembed ) {
			wp_send_json_error( array( 'message' => __( 'URL no soportada. Usa un archivo .mp4/.webm o un enlace de YouTube/Vimeo.', 'arriendo-facil' ) ), 400 );
		}

		update_option( 'af_dashboard_hero_video_url', $url );

		wp_send_json_success( array( 'message' => __( 'Video guardado.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: enables or disables the buildings module (gastos comunes entre
	 * unidades dentro de un mismo edificio). Hides the section from the
	 * sidebar when it is not needed, keeping the property flow simple.
	 *
	 * @return void
	 */
	public function ajax_toggle_buildings_module() {
		check_ajax_referer( 'af_buildings_module_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$enabled = isset( $_POST['enabled'] ) ? ( '1' === sanitize_key( wp_unslash( $_POST['enabled'] ) ) ) : false;

		update_option( 'af_use_buildings', $enabled ? '1' : '0' );

		wp_send_json_success(
			array(
				'message'  => $enabled
					? __( 'Sección de cobranza activada. Ya puedes organizar departamentos dentro de un edificio.', 'arriendo-facil' )
					: __( 'Sección oculta. Puedes reactivarla cuando la necesites.', 'arriendo-facil' ),
				'enabled'  => $enabled,
				'show_menu' => Arriendo_Facil_Property_Structure::module_enabled(),
			)
		);
	}

	/**
	 * Filter helper: cap redirects at 3 for the short-url resolver.
	 *
	 * @param int $count Redirection count.
	 * @return int
	 */
	public function cap_short_resolve_redirects( $count ) {
		return min( (int) $count, 3 );
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

	/**
	 * Validates a URL for the short-URL resolver: allow-listed Google host,
	 * https/http scheme, and DNS resolves only to public IPs.
	 *
	 * @param string $url URL to validate.
	 * @return bool
	 */
	private function is_url_safe_for_short_resolve( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = strtolower( (string) $parts['host'] );

		$allowed_hosts = array(
			'maps.app.goo.gl',
			'goo.gl',
			'app.goo.gl',
			'maps.google.com',
			'www.google.com',
			'google.com',
			'g.co',
			'maps.googleapis.com',
		);

		$host_allowed = false;
		foreach ( $allowed_hosts as $allowed ) {
			if ( $host === $allowed || substr( $host, -( strlen( $allowed ) + 1 ) ) === '.' . $allowed ) {
				$host_allowed = true;
				break;
			}
		}
		if ( ! $host_allowed ) {
			return false;
		}

		$ips = @gethostbynamel( $host );
		if ( ! is_array( $ips ) || empty( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			$public = filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			if ( false === $public ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * AJAX handler: predict accommodation cost using AI.
	 */
	public function ajax_predict_cost() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( $_POST['accommodation_id'] ) : 0;
		if ( ! $accommodation_id ) {
			wp_send_json_error( array( 'message' => __( 'ID de alojamiento invalido.', 'arriendo-facil' ) ) );
		}

		$data = array(
			'post_id'      => $accommodation_id,
			'address'      => get_post_meta( $accommodation_id, '_af_address', true ),
			'bedrooms'     => get_post_meta( $accommodation_id, '_af_bedrooms', true ),
			'bathrooms'    => get_post_meta( $accommodation_id, '_af_bathrooms', true ),
			'monthly_rent' => get_post_meta( $accommodation_id, '_af_monthly_rent', true ),
		);

		$ai       = new Arriendo_Facil_AI_Service();
		$result   = $ai->predict_cost( $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: generate a lease document using AI.
	 */
	public function ajax_generate_document() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		if ( ! $lease_id ) {
			wp_send_json_error( array( 'message' => __( 'ID de contrato invalido.', 'arriendo-facil' ) ) );
		}

		$lease_obj = new Arriendo_Facil_Lease();
		$lease     = $lease_obj->get_lease( $lease_id );

		if ( ! $lease ) {
			wp_send_json_error( array( 'message' => __( 'Contrato no encontrado.', 'arriendo-facil' ) ) );
		}

		$ai_payload = $this->build_lease_ai_payload( $lease );
		$owner_template_exists = ! empty( $ai_payload['template_available'] );

		$result = new WP_Error( 'af_ai_not_executed', __( 'No se ejecuto la generacion del documento con IA.', 'arriendo-facil' ) );
		if ( class_exists( 'Arriendo_Facil_AI_Service' ) ) {
			try {
				$ai     = new Arriendo_Facil_AI_Service();
				$result = $ai->generate_document( $ai_payload );
			} catch ( Throwable $throwable ) {
				error_log( 'Arriendo Facil admin AI document generation exception: ' . $throwable->getMessage() );
				$result = new WP_Error( 'af_ai_exception', __( 'La generacion del documento con IA fallo inesperadamente.', 'arriendo-facil' ) );
			}
		}

		$document_url = '';
		$owner_template = array();

		// When owner template exists, copy the original DOCX and fill tokens in-place
		// to preserve all formatting, styles, tables, etc.
		if ( $owner_template_exists ) {
			$owner_template = $this->get_owner_contract_example_context( isset( $lease->accommodation_id ) ? absint( $lease->accommodation_id ) : 0 );
			$document_url   = $this->create_filled_contract_from_owner_template( $lease_id, $owner_template, $ai_payload );
			if ( '' === $document_url && isset( $owner_template['url'] ) && is_string( $owner_template['url'] ) ) {
				$document_url = esc_url_raw( (string) $owner_template['url'] );
			}
		}

		// Text-based fallback only when the DOCX copy did not succeed.
		if ( '' === $document_url ) {
			$generated_contract_text = '';

			if ( ! $owner_template_exists && ! is_wp_error( $result ) && isset( $result['document_url'] ) && is_string( $result['document_url'] ) ) {
				$document_url = esc_url_raw( $result['document_url'] );
			}

			if ( '' === $document_url && $owner_template_exists ) {
				if ( ! is_wp_error( $result ) && isset( $result['contract_text'] ) && is_string( $result['contract_text'] ) && '' !== trim( $result['contract_text'] ) ) {
					$generated_contract_text = trim( wp_strip_all_tags( $result['contract_text'] ) );
				}

				if ( isset( $ai_payload['template_text'] ) && is_string( $ai_payload['template_text'] ) && '' !== trim( $ai_payload['template_text'] ) ) {
					if ( '' === $generated_contract_text ) {
						$generated_contract_text = $this->fill_owner_template_with_lease_data( $ai_payload['template_text'], $ai_payload );
					}
				}

				if ( '' === $generated_contract_text && isset( $ai_payload['template_text'] ) && is_string( $ai_payload['template_text'] ) ) {
					$generated_contract_text = trim( (string) $ai_payload['template_text'] );
				}

				if ( '' === $generated_contract_text ) {
					$generated_contract_text = $this->build_owner_template_unreadable_fallback_text( $ai_payload );
				}
			} elseif ( '' === $document_url ) {
				if ( ! is_wp_error( $result ) && isset( $result['contract_text'] ) && is_string( $result['contract_text'] ) ) {
					$generated_contract_text = trim( wp_strip_all_tags( $result['contract_text'] ) );
				}
			}

			if ( '' === $document_url && ! $owner_template_exists && '' !== $generated_contract_text ) {
				$document_url = $this->create_generated_contract_file( $lease_id, $generated_contract_text );
			}

			if ( '' === $document_url && ! $owner_template_exists && '' !== $generated_contract_text ) {
				$document_url = $this->create_last_resort_contract_file( $lease_id, $generated_contract_text );
			}
		}

		if ( $document_url ) {
			$this->force_attach_lease_document( $lease_id, $document_url );
			$result['document_url'] = $document_url;
		} else {
			wp_send_json_error( array( 'message' => __( 'No se pudo generar un documento de contrato utilizable para este contrato.', 'arriendo-facil' ) ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Builds enriched AI payload for lease document generation.
	 *
	 * @param object $lease Lease row object.
	 * @return array<string,mixed>
	 */
	private function build_lease_ai_payload( $lease ) {
		$lease_arr         = (array) $lease;
		$accommodation_id  = isset( $lease_arr['accommodation_id'] ) ? absint( $lease_arr['accommodation_id'] ) : 0;
		$guest_id          = isset( $lease_arr['guest_id'] ) ? absint( $lease_arr['guest_id'] ) : 0;
		$owner_template    = $this->get_owner_contract_example_context( $accommodation_id );
		$owner_id_number   = $this->get_owner_identification_number( isset( $owner_template['owner_user_id'] ) ? absint( $owner_template['owner_user_id'] ) : 0 );
		$accommodation     = array(
			'title'   => (string) get_the_title( $accommodation_id ),
			'address' => (string) get_post_meta( $accommodation_id, '_af_address', true ),
		);

		$guest_payload = array();
		if ( $guest_id ) {
			global $wpdb;
			$guest_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d",
					$guest_id
				)
			);

			if ( $guest_row ) {
				$guest_payload = array(
					'guest_name' => trim( (string) $guest_row->first_name . ' ' . (string) $guest_row->last_name ),
					'guest_email' => (string) $guest_row->email,
					'guest_phone' => (string) $guest_row->phone,
					'guest_id_number' => (string) $guest_row->id_number,
					'mascotas' => isset( $guest_row->mascotas ) ? absint( $guest_row->mascotas ) : 0,
					'referencia_personal_1' => isset( $guest_row->referencia_personal_1 ) ? (string) $guest_row->referencia_personal_1 : '',
					'referencia_personal_2' => isset( $guest_row->referencia_personal_2 ) ? (string) $guest_row->referencia_personal_2 : '',
					'personas_viviran' => isset( $guest_row->personas_viviran ) ? absint( $guest_row->personas_viviran ) : 0,
					'rental_mode' => isset( $guest_row->rental_mode ) ? (string) $guest_row->rental_mode : '',
					'rental_start_date' => isset( $guest_row->rental_start_date ) ? (string) $guest_row->rental_start_date : '',
					'rental_end_date' => isset( $guest_row->rental_end_date ) ? (string) $guest_row->rental_end_date : '',
					'rental_months' => isset( $guest_row->rental_months ) ? absint( $guest_row->rental_months ) : 0,
					'rental_years' => isset( $guest_row->rental_years ) ? absint( $guest_row->rental_years ) : 0,
					'desired_price' => isset( $guest_row->desired_price ) ? (string) $guest_row->desired_price : '',
					'guarantee_text' => isset( $guest_row->guarantee_text ) ? (string) $guest_row->guarantee_text : '',
				);
			}
		}

		return array_merge(
			$lease_arr,
			$guest_payload,
			array(
				'accommodation_title' => sanitize_text_field( (string) $accommodation['title'] ),
				'accommodation_address' => sanitize_text_field( (string) $accommodation['address'] ),
				'template_available' => ! empty( $owner_template['attachment_id'] ),
				'template_name' => isset( $owner_template['file_name'] ) ? sanitize_text_field( (string) $owner_template['file_name'] ) : '',
				'template_mime' => isset( $owner_template['mime_type'] ) ? sanitize_text_field( (string) $owner_template['mime_type'] ) : '',
				'template_url' => isset( $owner_template['url'] ) ? esc_url_raw( (string) $owner_template['url'] ) : '',
				'template_text' => isset( $owner_template['template_text'] ) ? (string) $owner_template['template_text'] : '',
				'owner_user_id' => isset( $owner_template['owner_user_id'] ) ? absint( $owner_template['owner_user_id'] ) : 0,
				'owner_name' => isset( $owner_template['owner_name'] ) ? sanitize_text_field( (string) $owner_template['owner_name'] ) : '',
				'owner_email' => isset( $owner_template['owner_email'] ) ? sanitize_email( (string) $owner_template['owner_email'] ) : '',
				'owner_id_number' => $owner_id_number,
			)
		);
	}

	/**
	 * Creates a lease document by copying the owner's original DOCX template
	 * and replacing tokens/blanks in-place, preserving all formatting.
	 *
	 * @param int   $lease_id Lease ID.
	 * @param array $owner_template Owner template context from get_owner_contract_example_context().
	 * @param array $payload Lease payload.
	 * @return string Document URL or '' on failure.
	 */
	private function create_filled_contract_from_owner_template( $lease_id, array $owner_template, array $payload ) {
		$lease_id       = absint( $lease_id );
		$attachment_id  = isset( $owner_template['attachment_id'] ) ? absint( $owner_template['attachment_id'] ) : 0;
		$template_path  = $attachment_id ? get_attached_file( $attachment_id ) : '';
		$template_mime  = isset( $owner_template['mime_type'] ) ? strtolower( (string) $owner_template['mime_type'] ) : '';
		$template_ext   = strtolower( (string) pathinfo( (string) $template_path, PATHINFO_EXTENSION ) );
		$tmp_downloaded  = false;

		if ( ! $lease_id || ! $attachment_id ) {
			error_log( 'Arriendo Facil admin owner-template generation skipped: missing lease_id or attachment_id.' );
			return '';
		}

		// If local file is missing, download from R2.
		if ( ! $template_path || ! file_exists( $template_path ) ) {
			$template_path = Arriendo_Facil_Contract_File_Store::fetch_owner_template( $attachment_id );
			if ( ! $template_path ) {
				error_log( 'Arriendo Facil admin owner-template generation failed: template file not found locally and R2 download failed. attachment_id=' . $attachment_id );
				return '';
			}
			$tmp_downloaded = true;
			$template_ext   = strtolower( (string) pathinfo( $template_path, PATHINFO_EXTENSION ) );
		}

		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' !== $template_mime && 'docx' !== $template_ext ) {
			if ( $tmp_downloaded ) {
				@unlink( $template_path );
			}
			error_log( 'Arriendo Facil admin owner-template generation failed: template is not DOCX. attachment_id=' . $attachment_id . ', mime=' . $template_mime . ', ext=' . $template_ext );
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			if ( $tmp_downloaded ) {
				@unlink( $template_path );
			}
			error_log( 'Arriendo Facil admin owner-template generation failed: wp_upload_dir unavailable.' );
			return '';
		}

		$contracts_dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/contracts';
		if ( ! wp_mkdir_p( $contracts_dir ) ) {
			if ( $tmp_downloaded ) {
				@unlink( $template_path );
			}
			error_log( 'Arriendo Facil admin owner-template generation failed: cannot create contracts dir.' );
			return '';
		}

		$file_name = sprintf( 'lease-%d-owner-template-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;

		$phpword_success = false;

		// Check configured processing method (markdown is primary, direct_xml is fallback).
		$processing_method = defined( 'AF_CONTRACT_PROCESSING_METHOD' )
			? AF_CONTRACT_PROCESSING_METHOD
			: (string) get_option( 'af_contract_processing_method', 'markdown' );

		// MARKDOWN PATH: try pandoc-based flow first.
		if ( 'markdown' === $processing_method
			&& class_exists( 'Arriendo_Facil_DOCX_Template_Processor' )
			&& Arriendo_Facil_DOCX_Template_Processor::is_pandoc_available()
		) {
			$tpl_proc   = new Arriendo_Facil_DOCX_Template_Processor();
			$ai_service = class_exists( 'Arriendo_Facil_AI_Service' ) ? new Arriendo_Facil_AI_Service() : null;

			$md_payload = $payload;
			$md_payload['attachment_id'] = $attachment_id;

			if ( $ai_service && $tpl_proc->fill_template_with_markdown( $template_path, $file_path, $md_payload, $ai_service ) ) {
				$phpword_success = true;
				error_log( 'Arriendo Facil admin owner-template generation: fill_template_with_markdown succeeded for lease_id=' . $lease_id );
			} else {
				error_log( 'Arriendo Facil admin owner-template generation: fill_template_with_markdown failed for lease_id=' . $lease_id . '; falling through to legacy path' );
			}
		}

		// CONTEXT-BASED FALLBACK: deterministic filling without AI.
		if ( ! $phpword_success && class_exists( 'Arriendo_Facil_DOCX_Template_Processor' ) ) {
			$tpl_proc = new Arriendo_Facil_DOCX_Template_Processor();
			if ( $tpl_proc->fill_template_with_context( $template_path, $file_path, $payload ) ) {
				$phpword_success = true;
				error_log( 'Arriendo Facil admin owner-template generation: fill_template_with_context succeeded for lease_id=' . $lease_id );
			}
		}

		// DIRECT XML FALLBACK: legacy pre-processed template with PhpWord TemplateProcessor.
		if ( ! $phpword_success ) {
			$processed_tpl_path = (string) get_post_meta( $attachment_id, '_af_processed_template_path', true );

			if ( class_exists( 'Arriendo_Facil_DOCX_Template_Processor' ) ) {
				$ai_svc        = class_exists( 'Arriendo_Facil_AI_Service' ) ? new Arriendo_Facil_AI_Service() : null;
				$tpl_proc      = new Arriendo_Facil_DOCX_Template_Processor();
				$processed_new = $tpl_proc->process_owner_template( $template_path, $ai_svc, $processed_tpl_path, $payload );
				if ( '' !== $processed_new && file_exists( $processed_new ) ) {
					$processed_tpl_path = $processed_new;
					update_post_meta( $attachment_id, '_af_processed_template_path', $processed_tpl_path );
				}
			}

			if ( '' !== $processed_tpl_path
				&& file_exists( $processed_tpl_path )
				&& class_exists( 'Arriendo_Facil_DOCX_Template_Processor' )
			) {
				$tpl_proc = new Arriendo_Facil_DOCX_Template_Processor();
				if ( $tpl_proc->fill_template( $processed_tpl_path, $file_path, $payload ) ) {
					$phpword_success = true;
					if ( $tmp_downloaded ) {
						@unlink( $template_path );
					}
				}
			}
		}

		if ( ! $phpword_success ) {
			if ( $tmp_downloaded ) {
				@unlink( $template_path );
			}
			error_log( 'Arriendo Facil admin owner-template generation failed: PHPWord fill could not produce a contract from the owner template.' );
			return '';
		}

		$local_url = trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name );

		return Arriendo_Facil_Contract_File_Store::persist( $lease_id, $file_path, $file_name, $local_url, Arriendo_Facil_Contract_Text_Extractor::DOCX_MIME );
	}

	/**
	 * Fills common owner-template placeholders with lease and guest values.
	 *
	 * @param string $template_text Owner template raw text.
	 * @param array  $payload Lease and guest context.
	 * @return string
	 */
	private function fill_owner_template_with_lease_data( $template_text, array $payload ) {
		$template_text = trim( (string) $template_text );
		if ( '' === $template_text ) {
			return '';
		}

		$field_values = array(
			'owner_name'            => isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '',
			'owner_email'           => isset( $payload['owner_email'] ) ? sanitize_email( (string) $payload['owner_email'] ) : '',
			'owner_id_number'       => isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '',
			'guest_name'            => isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '',
			'guest_email'           => isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '',
			'guest_phone'           => isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '',
			'guest_id_number'       => isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '',
			'accommodation_title'   => isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '',
			'accommodation_address' => isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '',
			'start_date'            => isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '',
			'end_date'              => isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '',
			'monthly_rent'          => isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '',
			'desired_price'         => isset( $payload['desired_price'] ) ? sanitize_text_field( (string) $payload['desired_price'] ) : '',
			'guarantee_text'        => isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : '',
			'current_date'          => current_time( 'Y-m-d' ),
		);

		if ( '' === $field_values['monthly_rent'] && '' !== $field_values['desired_price'] ) {
			$field_values['monthly_rent'] = $field_values['desired_price'];
		}

		$aliases = array(
			'owner_name' => array( 'owner_name', 'owner', 'landlord_name', 'nombre_arrendador', 'arrendador_nombre', 'propietario_nombre', 'nombre_propietario' ),
			'owner_email' => array( 'owner_email', 'landlord_email', 'correo_arrendador', 'email_arrendador', 'correo_propietario', 'email_propietario' ),
			'owner_id_number' => array( 'owner_id', 'owner_id_number', 'landlord_id', 'cedula_arrendador', 'ruc_arrendador', 'cedula_propietario', 'id_propietario' ),
			'guest_name' => array( 'guest_name', 'tenant_name', 'nombre_arrendatario', 'arrendatario_nombre', 'inquilino_nombre', 'nombre_inquilino' ),
			'guest_email' => array( 'guest_email', 'tenant_email', 'correo_arrendatario', 'email_arrendatario', 'correo_inquilino', 'email_inquilino' ),
			'guest_phone' => array( 'guest_phone', 'tenant_phone', 'telefono_arrendatario', 'celular_arrendatario', 'telefono_inquilino', 'celular_inquilino' ),
			'guest_id_number' => array( 'guest_id', 'guest_id_number', 'tenant_id', 'cedula_arrendatario', 'id_arrendatario', 'cedula_inquilino', 'id_inquilino' ),
			'accommodation_title' => array( 'property_name', 'accommodation_title', 'nombre_inmueble', 'inmueble', 'propiedad', 'nombre_propiedad' ),
			'accommodation_address' => array( 'property_address', 'accommodation_address', 'direccion_inmueble', 'direccion_propiedad', 'direccion' ),
			'start_date' => array( 'start_date', 'lease_start', 'fecha_inicio', 'fecha_inicio_arriendo', 'inicio_contrato' ),
			'end_date' => array( 'end_date', 'lease_end', 'fecha_fin', 'fecha_fin_arriendo', 'fin_contrato' ),
			'monthly_rent' => array( 'monthly_rent', 'rent', 'canon', 'canon_mensual', 'valor_arriendo', 'precio_mensual' ),
			'guarantee_text' => array( 'guarantee', 'guarantee_text', 'garantia', 'detalle_garantia' ),
			'current_date' => array( 'current_date', 'fecha_actual', 'fecha_hoy' ),
		);

		$token_map = array();
		foreach ( $aliases as $field_key => $tokens ) {
			$value = isset( $field_values[ $field_key ] ) ? (string) $field_values[ $field_key ] : '';
			if ( '' === $value ) {
				continue;
			}

			foreach ( $tokens as $token ) {
				$normalized = strtolower( preg_replace( '/[^a-z0-9]/', '', (string) $token ) );
				if ( '' !== $normalized ) {
					$token_map[ $normalized ] = $value;
				}
			}
		}

		$filled = preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_\-\s]+)\s*\}\}|\[\[\s*([a-zA-Z0-9_\-\s]+)\s*\]\]|<<\s*([a-zA-Z0-9_\-\s]+)\s*>>/',
			static function ( $matches ) use ( $token_map ) {
				$raw = '';
				if ( ! empty( $matches[1] ) ) {
					$raw = (string) $matches[1];
				} elseif ( ! empty( $matches[2] ) ) {
					$raw = (string) $matches[2];
				} elseif ( ! empty( $matches[3] ) ) {
					$raw = (string) $matches[3];
				}

				$key = strtolower( preg_replace( '/[^a-z0-9]/', '', $raw ) );
				if ( '' !== $key && isset( $token_map[ $key ] ) ) {
					return $token_map[ $key ];
				}

				return $matches[0];
			},
			$template_text
		);

		return trim( (string) $filled );
	}

	/**
	 * Builds a legal fallback contract when owner template cannot be read.
	 *
	 * @param array $payload Lease and guest context.
	 * @return string
	 */
	private function build_owner_template_unreadable_fallback_text( array $payload ) {
		$owner_name     = isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '________________________';
		$owner_id       = isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '________________________';
		$guest_name     = isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '________________________';
		$guest_id       = isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '________________________';
		$guest_phone    = isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '________________________';
		$guest_email    = isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '________________________';
		$property       = isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '________________________';
		$address        = isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '________________________';
		$start_date     = isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '________________________';
		$end_date       = isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '________________________';
		$monthly_rent   = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '0.00';
		$guarantee_text = isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : 'Garantia equivalente a dos (2) meses del canon de arrendamiento.';

		$text  = "CONTRATO DE ARRENDAMIENTO DE INMUEBLE\n";
		$text .= "(Conforme al Codigo Civil del Ecuador, Arts. 1857-1948, y la Ley de Inquilinato vigente con sus reformas)\n\n";
		$text .= sprintf( "Quito, %s\n\n", current_time( 'Y-m-d' ) );
		$text .= "CLAUSULA PRIMERA - COMPARECIENTES\n";
		$text .= "ARRENDADOR: " . $owner_name . " (Cedula/RUC: " . $owner_id . ")\n";
		$text .= "ARRENDATARIO: " . $guest_name . " (Cedula: " . $guest_id . ", Celular: " . $guest_phone . ", Correo: " . $guest_email . ")\n\n";
		$text .= "CLAUSULA SEGUNDA - OBJETO\n";
		$text .= "El ARRENDADOR da en arrendamiento el inmueble \"" . $property . "\", ubicado en " . $address . ".\n\n";
		$text .= "CLAUSULA TERCERA - PLAZO\n";
		$text .= "El plazo contractual inicia el " . $start_date . " y termina el " . $end_date . ".\n\n";
		$text .= "CLAUSULA CUARTA - CANON\n";
		$text .= "El canon mensual es USD " . $monthly_rent . ", pagadero dentro de los primeros cinco (5) dias de cada mes.\n\n";
		$text .= "CLAUSULA QUINTA - GARANTIA\n";
		$text .= $guarantee_text . "\n\n";
		$text .= "CLAUSULA SEXTA - OBLIGACIONES Y TERMINACION\n";
		$text .= "Las partes se obligan conforme la Ley de Inquilinato, Codigo Civil y COGEP vigentes. El contrato podra terminar por vencimiento, mutuo acuerdo o incumplimiento.\n\n";
		$text .= "CLAUSULA SEPTIMA - JURISDICCION\n";
		$text .= "Las partes se someten a los jueces competentes del Ecuador.\n\n";
		$text .= "FIRMAS\n\n";
		$text .= "ARRENDADOR: ________________________\nNombre: " . $owner_name . "\nCedula/RUC: " . $owner_id . "\n\n";
		$text .= "ARRENDATARIO: ________________________\nNombre: " . $guest_name . "\nCedula: " . $guest_id . "\n";

		return trim( $text );
	}

	/**
	 * Creates a DOCX fallback file when primary DOC/DOCX generation failed.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $contract_text Contract text.
	 * @return string
	 */
	private function create_last_resort_contract_file( $lease_id, $contract_text ) {
		$lease_id = absint( $lease_id );
		$text     = trim( (string) $contract_text );

		if ( ! $lease_id || '' === $text ) {
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

		$file_name = sprintf( 'lease-%d-fallback-admin-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;

		if ( ! $this->write_contract_docx_file( $file_path, $text ) ) {
			return '';
		}

		return esc_url_raw( trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name ) );
	}

	/**
	 * Persists lease document URL with primary and fallback DB update.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $document_url Document URL.
	 * @return void
	 */
	private function force_attach_lease_document( $lease_id, $document_url ) {
		$lease_id     = absint( $lease_id );
		$document_url = esc_url_raw( (string) $document_url );

		if ( ! $lease_id || '' === $document_url ) {
			return;
		}

		$lease_obj = new Arriendo_Facil_Lease();
		$attached  = (bool) $lease_obj->attach_document( $lease_id, $document_url );

		if ( ! $attached ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'af_leases',
				array( 'document_url' => $document_url ),
				array( 'id' => $lease_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Finds latest contract example uploaded by the owner of an accommodation.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return array<string,mixed>
	 */
	private function get_owner_contract_example_context( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		$owner_user_id = $this->resolve_accommodation_owner_user_id( $accommodation_id );

		if ( ! $owner_user_id ) {
			error_log( 'Arriendo Facil admin owner-template lookup: accommodation has no resolved owner. accommodation_id=' . $accommodation_id );
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
							'key'   => '_af_sensitive_doc_type',
							'value' => 'contract_example',
						),
						array(
							'key'   => '_af_owner_user_id',
							'value' => (string) $owner_user_id,
						),
					),
				)
			);
			$attachment_id = ! empty( $attachment_ids ) ? absint( $attachment_ids[0] ) : 0;

			if ( $attachment_id ) {
				update_post_meta( $attachment_id, '_af_owner_contract_example', '1' );
			}
		}

		if ( ! $attachment_id ) {
			error_log( 'Arriendo Facil admin owner-template lookup: no owner contract attachment found. accommodation_id=' . $accommodation_id . ', owner_user_id=' . $owner_user_id );
			return array();
		}

		return $this->build_contract_template_context_from_attachment( $attachment_id, $owner_user_id );
	}

	/**
	 * Resolves owner user ID for an accommodation with safe fallbacks.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return int
	 */
	private function resolve_accommodation_owner_user_id( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id ) {
			return 0;
		}

		$owner_user_id = absint( get_post_meta( $accommodation_id, '_af_owner_id', true ) );
		if ( $owner_user_id > 0 ) {
			return $owner_user_id;
		}

		$legacy_owner_user_id = absint( get_post_meta( $accommodation_id, '_af_owner_user_id', true ) );
		if ( $legacy_owner_user_id > 0 ) {
			return $legacy_owner_user_id;
		}

		$post = get_post( $accommodation_id );
		if ( $post && ! empty( $post->post_author ) ) {
			$post_author_id = absint( $post->post_author );
			if ( $post_author_id > 0 ) {
				$author = get_user_by( 'id', $post_author_id );
				if ( $author && in_array( 'af_property_admin', (array) $author->roles, true ) ) {
					return $post_author_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Returns owner identification number from owner contacts table.
	 *
	 * @param int $owner_user_id Owner user ID.
	 * @return string
	 */
	private function get_owner_identification_number( $owner_user_id ) {
		$owner_user_id = absint( $owner_user_id );
		if ( ! $owner_user_id ) {
			return '';
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'af_owner_contacts';
		$owner_id   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT owner_id FROM {$table_name} WHERE wp_user_id = %d ORDER BY id DESC LIMIT 1",
				$owner_user_id
			)
		);

		return sanitize_text_field( (string) $owner_id );
	}

	/**
	 * Builds standardized contract template context from an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $fallback_owner_user_id Owner user fallback ID.
	 * @return array<string,mixed>
	 */
	private function build_contract_template_context_from_attachment( $attachment_id, $fallback_owner_user_id = 0 ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array();
		}

		$owner_user_id = absint( get_post_meta( $attachment_id, '_af_owner_user_id', true ) );
		if ( ! $owner_user_id ) {
			$owner_user_id = absint( $fallback_owner_user_id );
		}

		$path          = get_attached_file( $attachment_id );
		$mime_type     = (string) get_post_mime_type( $attachment_id );
		$template_text = Arriendo_Facil_Contract_Text_Extractor::extract( $path, $mime_type );
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
	 * Saves AI-generated contract into DOCX and returns secure URL.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $contract_text Contract text.
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

		$file_name = sprintf( 'lease-%d-contract-admin-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;
		$mime_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		if ( ! $this->write_contract_docx_file( $file_path, $contract_text ) ) {
			$file_name = sprintf( 'lease-%d-contract-admin-%s.doc', $lease_id, gmdate( 'Ymd-His' ) );
			$file_path = trailingslashit( $contracts_dir ) . $file_name;
			$mime_type = 'application/msword';
			if ( ! $this->write_contract_doc_fallback_file( $file_path, $contract_text ) ) {
				return '';
			}
		}

		$local_url = trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name );

		return Arriendo_Facil_Contract_File_Store::persist( $lease_id, $file_path, $file_name, $local_url, $mime_type );
	}

	/**
	 * Writes a fallback MS Word-compatible HTML document when DOCX is unavailable.
	 *
	 * @param string $file_path Destination path.
	 * @param string $contract_text Contract text.
	 * @return bool
	 */
	private function write_contract_doc_fallback_file( $file_path, $contract_text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $contract_text );
		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $contract_text );
		}

		$body = '';
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				$body .= '<p>&nbsp;</p>';
				continue;
			}

			$body .= '<p>' . esc_html( $line ) . '</p>';
		}

		if ( '' === $body ) {
			$body = '<p>Contrato</p>';
		}

		$html = '<html><head><meta charset="UTF-8"></head><body style="font-family:Times New Roman, serif; font-size:12pt;">' . $body . '</body></html>';

		return false !== file_put_contents( $file_path, $html );
	}

	/**
	 * Writes a minimal DOCX file from plain contract text.
	 *
	 * @param string $file_path Destination path.
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

		$paragraphs = $this->build_contract_docx_paragraphs( $contract_text );
		if ( empty( $paragraphs ) ) {
			$paragraphs = array(
				array( 'text' => 'Contrato', 'bold' => false, 'align' => 'left' ),
			);
		}

		$doc_paragraphs_xml = '';
		foreach ( $paragraphs as $paragraph ) {
			$text  = isset( $paragraph['text'] ) ? (string) $paragraph['text'] : '';
			$bold  = ! empty( $paragraph['bold'] );
			$align = isset( $paragraph['align'] ) ? (string) $paragraph['align'] : 'both';

			if ( '' === $text ) {
				$doc_paragraphs_xml .= '<w:p/>';
				continue;
			}

			$paragraph_properties = '';
			if ( in_array( $align, array( 'left', 'center', 'right', 'both' ), true ) ) {
				$paragraph_properties .= '<w:jc w:val="' . esc_attr( $align ) . '"/>';
			}

			if ( isset( $paragraph['tab_stops'] ) && is_array( $paragraph['tab_stops'] ) ) {
				$paragraph_properties .= '<w:tabs>';
				foreach ( $paragraph['tab_stops'] as $tab_stop ) {
					$position = absint( $tab_stop );
					if ( $position > 0 ) {
						$paragraph_properties .= '<w:tab w:val="left" w:pos="' . $position . '"/>';
					}
				}
				$paragraph_properties .= '</w:tabs>';
			}

			$run_properties = '';
			if ( $bold ) {
				$run_properties = '<w:rPr><w:b/><w:bCs/></w:rPr>';
			}

			$doc_paragraphs_xml .= '<w:p>';
			if ( '' !== $paragraph_properties ) {
				$doc_paragraphs_xml .= '<w:pPr>' . $paragraph_properties . '</w:pPr>';
			}
			$doc_paragraphs_xml .= $this->build_docx_text_runs_xml( $text, $run_properties );
			$doc_paragraphs_xml .= '</w:p>';
		}

		$document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:w10="urn:schemas-microsoft-com:office:word" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml" xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup" xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk" xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml" xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" mc:Ignorable="w14 w15 wp14">'
			. '<w:body>' . $doc_paragraphs_xml . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/><w:cols w:space="720"/></w:sectPr></w:body></w:document>';

		$content_types_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '</Types>';

		$styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:styles xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" mc:Ignorable="">'
			. '<w:docDefaults>'
			. '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:rPrDefault>'
			. '<w:pPrDefault><w:pPr><w:spacing w:before="0" w:after="160" w:line="360" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr></w:pPrDefault>'
			. '</w:docDefaults>'
			. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
			. '</w:styles>';

		$rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			. '</Relationships>';

		$doc_rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';

		$zip->addFromString( '[Content_Types].xml', $content_types_xml );
		$zip->addFromString( '_rels/.rels', $rels_xml );
		$zip->addFromString( 'word/document.xml', $document_xml );
		$zip->addFromString( 'word/styles.xml', $styles_xml );
		$zip->addFromString( 'word/_rels/document.xml.rels', $doc_rels_xml );

		return $zip->close();
	}

	/**
	 * Builds DOCX paragraphs using strict contract format rules.
	 *
	 * @param string $contract_text Contract text.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_contract_docx_paragraphs( $contract_text ) {
		$paragraphs = array();
		$lines      = preg_split( '/\r\n|\r|\n/', (string) $contract_text );
		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $contract_text );
		}

		$has_title      = false;
		$last_was_empty = false;
		$title_inserted = false;

		foreach ( $lines as $raw_line ) {
			$line = trim( (string) $raw_line );

			if ( '' === $line ) {
				if ( $last_was_empty ) {
					continue;
				}
				$paragraphs[]   = array( 'text' => '', 'bold' => false, 'align' => 'both' );
				$last_was_empty = true;
				continue;
			}

			$upper_line = strtoupper( $line );
			$is_title   = false !== strpos( $upper_line, 'CONTRATO DE ARRENDAMIENTO' );
			$is_clause  = 0 === strpos( $upper_line, 'CLAUSULA ' );
			$last_was_empty = false;

			if ( 0 === strpos( $line, 'Quito,' ) ) {
				continue;
			}

			if ( $this->is_contract_signature_line( $line ) ) {
				continue;
			}

			if ( ! $has_title && $is_title ) {
				$paragraphs[] = array( 'text' => 'CONTRATO DE ARRENDAMIENTO', 'bold' => true, 'align' => 'center' );
				$paragraphs[] = array( 'text' => $this->format_contract_date_line( '' ), 'bold' => false, 'align' => 'right' );
				$has_title      = true;
				$title_inserted = true;
				continue;
			}

			$paragraphs[] = array(
				'text'  => $line,
				'bold'  => $is_clause,
				'align' => $is_clause ? 'left' : 'both',
			);
		}

		if ( ! $has_title || ! $title_inserted ) {
			array_unshift(
				$paragraphs,
				array( 'text' => 'CONTRATO DE ARRENDAMIENTO', 'bold' => true, 'align' => 'center' ),
				array( 'text' => $this->format_contract_date_line( '' ), 'bold' => false, 'align' => 'right' )
			);
		}

		$paragraphs = array_merge( $paragraphs, $this->build_contract_signature_paragraphs() );
		return $paragraphs;
	}

	/**
	 * Builds WordprocessingML run XML, preserving tab stops in content.
	 *
	 * @param string $text Paragraph text.
	 * @param string $run_properties Run properties XML.
	 * @return string
	 */
	private function build_docx_text_runs_xml( $text, $run_properties = '' ) {
		$parts = explode( "\t", (string) $text );
		if ( 1 === count( $parts ) ) {
			return '<w:r>' . $run_properties . '<w:t xml:space="preserve">' . esc_xml( $text ) . '</w:t></w:r>';
		}

		$xml = '';
		foreach ( $parts as $index => $part ) {
			$xml .= '<w:r>' . $run_properties . '<w:t xml:space="preserve">' . esc_xml( $part ) . '</w:t></w:r>';
			if ( $index < count( $parts ) - 1 ) {
				$xml .= '<w:r>' . $run_properties . '<w:tab/></w:r>';
			}
		}
		return $xml;
	}

	/**
	 * Detects whether a line belongs to the signature section.
	 *
	 * @param string $line Contract line.
	 * @return bool
	 */
	private function is_contract_signature_line( $line ) {
		$clean = strtoupper( trim( (string) $line ) );
		if ( '' === $clean ) {
			return false;
		}
		if ( in_array( $clean, array( 'FIRMAS', 'ARRENDADOR', 'ARRENDATARIO', 'EL ARRENDADOR', 'EL ARRENDATARIO' ), true ) ) {
			return true;
		}
		if ( 0 === strpos( $clean, 'ARRENDADOR:' ) || 0 === strpos( $clean, 'ARRENDATARIO:' ) ) {
			return true;
		}
		if ( 0 === strpos( $clean, 'EL ARRENDADOR' ) || 0 === strpos( $clean, 'EL ARRENDATARIO' ) ) {
			return true;
		}
		if ( 0 === strpos( $clean, 'FIRMA:' ) || 0 === strpos( $clean, 'NOMBRE:' ) ) {
			return true;
		}
		if ( 0 === strpos( $clean, 'CEDULA:' ) || 0 === strpos( $clean, 'CÉDULA:' ) || 0 === strpos( $clean, 'CEDULA/RUC:' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Builds a fixed signature block with two sections.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function build_contract_signature_paragraphs() {
		return array(
			array( 'text' => '', 'bold' => false, 'align' => 'both' ),
			array( 'text' => 'FIRMAS', 'bold' => true, 'align' => 'left' ),
			array( 'text' => '', 'bold' => false, 'align' => 'both' ),
			array(
				'text'      => 'ARRENDADOR' . "\t" . 'ARRENDATARIO',
				'bold'      => true,
				'align'     => 'left',
				'tab_stops' => array( 6400 ),
			),
			array(
				'text'      => 'Firma: ________________________' . "\t" . 'Firma: ________________________',
				'bold'      => false,
				'align'     => 'left',
				'tab_stops' => array( 6400 ),
			),
			array(
				'text'      => 'Nombre: ________________________' . "\t" . 'Nombre: ________________________',
				'bold'      => false,
				'align'     => 'left',
				'tab_stops' => array( 6400 ),
			),
			array(
				'text'      => 'Cédula: ________________________' . "\t" . 'Cédula: ________________________',
				'bold'      => false,
				'align'     => 'left',
				'tab_stops' => array( 6400 ),
			),
		);
	}

	/**
	 * Formats the contract date line in Spanish.
	 *
	 * @param string $line Original line (unused, always rebuilds).
	 * @return string
	 */
	private function format_contract_date_line( $line ) {
		$timestamp = current_time( 'timestamp' );
		$months    = array(
			1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
			5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
			9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
		);
		$day        = (int) gmdate( 'j', $timestamp );
		$month_idx  = (int) gmdate( 'n', $timestamp );
		$year       = (string) gmdate( 'Y', $timestamp );
		$month_name = isset( $months[ $month_idx ] ) ? $months[ $month_idx ] : gmdate( 'F', $timestamp );
		return sprintf( 'Quito, %d de %s de %s', $day, $month_name, $year );
	}

	/**
	 * Renders OTA Integrations settings page.
	 */
	public function render_ota_integrations() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'arriendo-facil' ) );
		}
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/ota-integrations-settings.php';
	}

	/**
	 * Renders the OTA Sync Dashboard page.
	 */
	public function render_ota_sync_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'arriendo-facil' ) );
		}
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/ota-sync-dashboard.php';
	}

	/**
	 * Translated strings for the interactive collection calendar.
	 *
	 * They travel to af-buildings-cobranza.js so month names, weekday
	 * abbreviations, statuses and amounts stay localised and pluralised by
	 * PHP instead of being hard-coded in Spanish inside the script.
	 *
	 * @return array<string,mixed>
	 */
	private static function cobranza_calendar_strings() {
		$weekdays = array();
		// 2024-01-01 fue un lunes: seis pasos dan los siete días en orden.
		for ( $i = 0; $i < 7; $i++ ) {
			$weekdays[] = wp_date( 'D', strtotime( '2024-01-01 +' . $i . ' day' ) );
		}

		$months = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$months[] = wp_date( 'M', mktime( 0, 0, 0, $m, 1, 2024 ) );
		}

		return array(
			'weekdays'          => $weekdays,
			'months'            => $months,
			'paid'              => __( 'Pagado', 'arriendo-facil' ),
			'pending'           => __( 'Pendiente', 'arriendo-facil' ),
			'overdue'           => __( 'Vencido', 'arriendo-facil' ),
			'partial'           => __( 'Parcial', 'arriendo-facil' ),
			'payday'            => __( 'Día de pago', 'arriendo-facil' ),
			'charged'           => __( 'Facturado', 'arriendo-facil' ),
			'settled'           => __( 'Cancelado', 'arriendo-facil' ),
			'noCharges'         => __( 'Sin cargos para este periodo.', 'arriendo-facil' ),
			'noMatches'         => __( 'Ningún cargo coincide con el filtro.', 'arriendo-facil' ),
			'tenant'            => __( 'Arrendatario:', 'arriendo-facil' ),
			'recordPayment'     => __( 'Anotar pago', 'arriendo-facil' ),
			'noActiveLease'     => __( 'Sin contrato activo: este inmueble no genera cobros.', 'arriendo-facil' ),
			'balance'           => __( 'Saldo', 'arriendo-facil' ),
			'showAll'           => __( 'Ver todos los días', 'arriendo-facil' ),
			'clearFilters'      => __( 'Quitar filtros', 'arriendo-facil' ),
			'dayCharges'        => __( 'Cargos del %s', 'arriendo-facil' ),
			'outstanding'       => __( 'Saldo del cargo: %s', 'arriendo-facil' ),
			'overpayHint'       => __( '— puedes superarlo y el excedente quedará como saldo a favor.', 'arriendo-facil' ),
			'amountRequired'    => __( 'Ingresa un monto mayor a cero.', 'arriendo-facil' ),
			'payFailed'         => __( 'Error de red al registrar el pago.', 'arriendo-facil' ),
			'monthFailed'       => __( 'No se pudo cargar el mes solicitado.', 'arriendo-facil' ),
			'monthNetFailed'    => __( 'Error de red al cargar el mes.', 'arriendo-facil' ),
			/* translators: 1: day label with charges, 2: pending amount. */
			'dayTotals'         => __( '%1$s facturados · %2$s pendientes', 'arriendo-facil' ),
			/* translators: %d: day of the month the rent is due. */
			'paydayOn'          => __( 'Día de pago: %d', 'arriendo-facil' ),
		);
	}

}
