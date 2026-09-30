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
		add_action( 'wp_dashboard_setup', array( $this, 'register_native_dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
		return Arriendo_Facil_Tenant_Portal::is_restricted_tenant();
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
			return Arriendo_Facil_Tenant_Portal::dashboard_url();
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
