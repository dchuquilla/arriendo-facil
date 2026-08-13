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
		add_action( 'admin_menu', array( $this, 'remove_menus_for_owner' ), 999 );
		add_action( 'admin_menu', array( $this, 'remove_menus_for_tenant' ), 999 );
		add_action( 'admin_menu', array( $this, 'register_tenant_dashboard_pages' ), 50 );
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
		add_action( 'pre_get_posts', array( $this, 'restrict_accommodation_list_to_owner' ) );
		add_filter( 'views_edit-accommodation', array( $this, 'filter_accommodation_status_views_for_owner' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'remove_owner_dashboard_widgets' ), 999 );
		add_action( 'wp_dashboard_setup', array( $this, 'remove_tenant_dashboard_widgets' ), 999 );
		add_action( 'wp_dashboard_setup', array( $this, 'register_native_dashboard_widget' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_tenant_dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_af_predict_cost', array( $this, 'ajax_predict_cost' ) );
		add_action( 'wp_ajax_af_generate_document', array( $this, 'ajax_generate_document' ) );
		add_action( 'wp_ajax_af_resolve_short_url', array( $this, 'ajax_resolve_short_url' ) );
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
			__( 'Contratos', 'arriendo-facil' ),
			__( 'Contratos', 'arriendo-facil' ),
			'edit_posts',
			'af-leases',
			array( $this, 'render_leases' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Solicitudes de limpieza', 'arriendo-facil' ),
			__( 'Solicitudes de limpieza', 'arriendo-facil' ),
			'edit_posts',
			'af-cleaning-requests',
			array( $this, 'render_cleaning_requests' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Contactos de propietarios', 'arriendo-facil' ),
			__( 'Contactos de propietarios', 'arriendo-facil' ),
			'manage_options',
			'af-owner-contacts',
			array( $this, 'render_owner_contacts' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Huespedes', 'arriendo-facil' ),
			__( 'Huespedes', 'arriendo-facil' ),
			'edit_posts',
			'af-guests',
			array( $this, 'render_guests' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Valoraciones', 'arriendo-facil' ),
			__( 'Valoraciones', 'arriendo-facil' ),
			'edit_posts',
			'af-reviews',
			array( $this, 'render_reviews' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Ajustes de IA', 'arriendo-facil' ),
			__( 'Ajustes de IA', 'arriendo-facil' ),
			'manage_options',
			'af-ai-settings',
			array( $this, 'render_ai_settings' )
		);

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

		// Hide Cleaning Services CPT (admin-managed, not for owners).
		remove_submenu_page( 'arriendo-facil', 'edit.php?post_type=cleaning_service' );
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
	 * Removes noisy WP-core nodes from the admin bar for owners.
	 * Keeps: site-name (link a home), user account, notifications.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function harden_admin_bar_for_owner( $wp_admin_bar ) {
		if ( ! $this->is_restricted_owner() ) {
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
	 * Suppresses the "WordPress X.Y is available" nag for owners.
	 */
	public function suppress_owner_update_nag() {
		if ( ! $this->is_restricted_owner() ) {
			return;
		}

		remove_action( 'admin_notices', 'update_nag', 3 );
		remove_action( 'network_admin_notices', 'update_nag', 3 );
		remove_action( 'admin_notices', 'maintenance_nag', 10 );
	}

	/**
	 * CSS fallback that hides residual WP-core UI for owners
	 * (some nodes reappear via JS or are injected late).
	 */
	public function owner_hardening_css() {
		if ( ! $this->is_restricted_owner() ) {
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
	 * and right version string) for owners.
	 *
	 * @param string $text Original footer text.
	 * @return string
	 */
	public function owner_admin_footer_text( $text ) {
		if ( ! $this->is_restricted_owner() ) {
			return $text;
		}
		return '';
	}

	/**
	 * Hides the "Screen Options" and "Help" tabs for owners.
	 *
	 * @param bool $show Whether to show screen options.
	 * @return bool
	 */
	public function owner_hide_screen_options( $show ) {
		if ( ! $this->is_restricted_owner() ) {
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
		}

		if ( 'profile.php' === $pagenow || 'user-edit.php' === $pagenow ) {
			$extra[] = 'af-native-profile';
			if ( $this->is_restricted_owner() ) {
				$extra[] = 'af-owner-view';
			} elseif ( $this->is_restricted_tenant() ) {
				$extra[] = 'af-tenant-view';
			}
		}

		if ( ! empty( $extra ) ) {
			$classes .= ' ' . implode( ' ', $extra );
		}

		return $classes;
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

		if ( in_array( 'af_owner', $roles, true ) && ! in_array( 'administrator', $roles, true ) ) {
			return admin_url( 'admin.php?page=arriendo-facil' );
		}

		return $redirect_to;
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
				<?php if ( empty( $leases ) ) : ?>
					<p><?php esc_html_e( 'Aún no tienes arriendos registrados.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
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
									<td><?php echo esc_html( isset( $lease['accommodation_title'] ) ? (string) $lease['accommodation_title'] : __( 'Alojamiento', 'arriendo-facil' ) ); ?></td>
									<td><?php echo esc_html( isset( $lease['start_date'] ) ? (string) wp_date( 'd/m/Y', strtotime( (string) $lease['start_date'] ) ) : '-' ); ?></td>
									<td><?php echo esc_html( isset( $lease['end_date'] ) ? (string) wp_date( 'd/m/Y', strtotime( (string) $lease['end_date'] ) ) : '-' ); ?></td>
									<td><?php echo esc_html( isset( $lease['monthly_rent'] ) ? '$' . number_format_i18n( (float) $lease['monthly_rent'], 2 ) : '-' ); ?></td>
									<td><?php echo esc_html( isset( $lease['status_label'] ) ? (string) $lease['status_label'] : '' ); ?></td>
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
					<table class="widefat striped">
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
									<td><?php echo esc_html( isset( $visit['accommodation_title'] ) ? (string) $visit['accommodation_title'] : __( 'Alojamiento', 'arriendo-facil' ) ); ?></td>
									<td><?php echo esc_html( isset( $visit['visit_date'] ) ? (string) wp_date( 'd/m/Y', strtotime( (string) $visit['visit_date'] ) ) : '-' ); ?></td>
									<td><?php echo esc_html( isset( $visit['start_time'] ) ? (string) substr( (string) $visit['start_time'], 0, 5 ) : '--:--' ); ?></td>
									<td><?php echo esc_html( isset( $visit['status_label'] ) ? (string) $visit['status_label'] : '' ); ?></td>
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
				<?php if ( empty( $events ) ) : ?>
					<p><?php esc_html_e( 'No tienes eventos próximos en el calendario.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
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
									<td><?php echo esc_html( isset( $event['date'] ) ? wp_date( 'd/m/Y', strtotime( (string) $event['date'] ) ) : '-' ); ?></td>
									<td><?php echo esc_html( isset( $event['type_label'] ) ? (string) $event['type_label'] : '' ); ?></td>
									<td><?php echo esc_html( isset( $event['detail'] ) ? (string) $event['detail'] : '' ); ?></td>
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

		$reviews_pending = is_email( $email ) ? (int) $wpdb->get_var(
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
		) : 0;

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

		if ( ! is_email( $email ) ) {
			return array(
				'completed' => 0,
				'pending'   => 0,
				'avg_stars' => 0,
			);
		}

		global $wpdb;

		$completed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews
				 WHERE tenant_email = %s
				   AND review_direction IN ('tenant_to_owner','tenant_to_property')
				   AND status = 'completed'",
				$email
			)
		);

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

		$avg_stars = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(stars) FROM {$wpdb->prefix}af_reviews
				 WHERE tenant_email = %s
				   AND review_direction IN ('tenant_to_owner','tenant_to_property')
				   AND status = 'completed'",
				$email
			)
		);

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
	public function render_leases() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/leases.php';
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
	 * Renders the guests admin page.
	 */
	public function render_guests() {
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
	 */
	public function render_billing_settings() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/billing-settings.php';
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
	 * Filter helper: cap redirects at 3 for the short-url resolver.
	 *
	 * @param int $count Redirection count.
	 * @return int
	 */
	public function cap_short_resolve_redirects( $count ) {
		return min( (int) $count, 3 );
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
			$template_path = $this->download_owner_template_from_r2( $attachment_id );
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

		$mime_type    = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		$local_url    = trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name );
		$document_url = $local_url;
		$storage_meta = array(
			'provider'  => 'local',
			'file_name' => $file_name,
			'local_url' => $local_url,
			'mime_type' => $mime_type,
		);

		$storage_provider = $this->get_storage_setting( 'AF_STORAGE_PROVIDER', 'af_storage_provider', 'cloudflare_r2' );
		if ( 'cloudflare_r2' === $storage_provider ) {
			$r2_config = $this->get_r2_config();
			if ( ! is_wp_error( $r2_config ) ) {
				$contents = file_get_contents( $file_path );
				if ( false !== $contents ) {
					$object_key = sprintf( 'lease-contracts/%d/%s', $lease_id, sanitize_file_name( $file_name ) );
					$upload     = $this->upload_contents_to_r2( $contents, $object_key, $mime_type, $r2_config );
					if ( ! is_wp_error( $upload ) ) {
						$document_url = add_query_arg(
							array(
								'action'   => 'af_download_lease_contract',
								'lease_id' => $lease_id,
							),
							admin_url( 'admin-ajax.php' )
						);
						$storage_meta = array(
							'provider'   => 'cloudflare_r2',
							'object_key' => $object_key,
							'file_name'  => $file_name,
							'local_url'  => $local_url,
							'mime_type'  => $mime_type,
						);
					}
				}
			}
		}

		if ( class_exists( 'Arriendo_Facil_Lease' ) ) {
			$lease_service = new Arriendo_Facil_Lease();
			$lease_service->set_contract_storage_meta( $lease_id, $storage_meta );
		}

		return $document_url;
	}

	/**
	 * Replaces known token formats inside DOCX XML parts without altering layout.
	 *
	 * @param string $docx_path Absolute DOCX path.
	 * @param array  $payload Lease payload.
	 * @return void
	 */
	private function replace_docx_template_tokens_in_place( $docx_path, array $payload ) {
		if ( ! class_exists( 'ZipArchive' ) || ! $docx_path || ! file_exists( $docx_path ) ) {
			return;
		}

		$token_map = $this->build_owner_template_token_map( $payload );
		if ( empty( $token_map ) ) {
			return;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $docx_path ) ) {
			return;
		}

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$entry_name = (string) $zip->getNameIndex( $index );
			if ( 1 !== preg_match( '#^word/(document|header[0-9]+|footer[0-9]+)\.xml$#i', $entry_name ) ) {
				continue;
			}

			$xml = $zip->getFromName( $entry_name );
			if ( false === $xml || '' === $xml ) {
				continue;
			}

			// Flatten split runs: Word sometimes splits placeholders across multiple <w:t> nodes.
			$flattened_xml = $this->flatten_split_placeholder_runs( (string) $xml );

			$updated_xml = preg_replace_callback(
				'/\{\{\s*([a-zA-Z0-9_\-\s]+)\s*\}\}|\[\[\s*([a-zA-Z0-9_\-\s]+)\s*\]\]|<<\s*([a-zA-Z0-9_\-\s]+)\s*>>/',
				function ( $matches ) use ( $token_map ) {
					$raw_token = '';
					if ( ! empty( $matches[1] ) ) {
						$raw_token = (string) $matches[1];
					} elseif ( ! empty( $matches[2] ) ) {
						$raw_token = (string) $matches[2];
					} elseif ( ! empty( $matches[3] ) ) {
						$raw_token = (string) $matches[3];
					}

					$normalized = $this->normalize_contract_placeholder_token( $raw_token );
					if ( '' !== $normalized && isset( $token_map[ $normalized ] ) ) {
						return esc_xml( (string) $token_map[ $normalized ] );
					}

					return $matches[0];
				},
				$flattened_xml
			);

			if ( is_string( $updated_xml ) ) {
				$updated_xml = $this->replace_instruction_bracket_placeholders_in_xml( $updated_xml, $payload );
			}

			if ( is_string( $updated_xml ) && $updated_xml !== $xml ) {
				$zip->addFromString( $entry_name, $updated_xml );
			}
		}

		$zip->close();
	}

	/**
	 * Flattens adjacent <w:r> runs that split a placeholder across multiple <w:t> nodes.
	 *
	 * Word may break "{{guest_name}}" into "<w:t>{{</w:t>...<w:t>guest_name</w:t>...<w:t>}}</w:t>"
	 * This merges adjacent runs with identical formatting into single <w:t> nodes.
	 *
	 * @param string $xml Word XML content.
	 * @return string
	 */
	private function flatten_split_placeholder_runs( $xml ) {
		// Merge text content of adjacent <w:t> nodes within the same <w:p>.
		$result = preg_replace_callback(
			'#(<w:p\b[^>]*>)(.*?)(</w:p>)#si',
			function ( $p_match ) {
				$inner = $p_match[2];
				// Extract all text from <w:t> nodes.
				$full_text = '';
				preg_match_all( '#<w:t[^>]*>(.*?)</w:t>#si', $inner, $t_matches );
				if ( ! empty( $t_matches[1] ) ) {
					$full_text = implode( '', $t_matches[1] );
				}

				// Only flatten if the combined text contains a placeholder pattern.
				if ( ! preg_match( '/\{\{[^}]+\}\}|\[\[[^\]]+\]\]|<<[^>]+>>|\[[^\]]+\]/', $full_text ) ) {
					return $p_match[0];
				}

				// Replace runs containing placeholder fragments with a single merged run.
				$merged = preg_replace_callback(
					'#(<w:r\b[^>]*>\s*(?:<w:rPr>.*?</w:rPr>\s*)?)<w:t[^>]*>([^<]*)</w:t>\s*</w:r>(?:\s*<w:r\b[^>]*>\s*(?:<w:rPr>.*?</w:rPr>\s*)?<w:t[^>]*>([^<]*)</w:t>\s*</w:r>)+#si',
					function ( $run_match ) {
						// Collect all <w:t> content.
						preg_match_all( '#<w:t[^>]*>([^<]*)</w:t>#si', $run_match[0], $texts );
						$combined = implode( '', $texts[1] );
						// Keep the first run's rPr (formatting).
						preg_match( '#(<w:r\b[^>]*>\s*(?:<w:rPr>.*?</w:rPr>\s*)?)#si', $run_match[0], $first_run );
						$run_start = isset( $first_run[1] ) ? $first_run[1] : '<w:r>';
						return $run_start . '<w:t xml:space="preserve">' . $combined . '</w:t></w:r>';
					},
					$inner
				);

				return $p_match[1] . $merged . $p_match[3];
			},
			(string) $xml
		);

		return is_string( $result ) ? $result : (string) $xml;
	}

	/**
	 * Completes blank fields identified by semantic labels in DOCX template.
	 *
	 * @param string $docx_path DOCX path.
	 * @param array  $owner_template Owner template context.
	 * @param array  $payload Lease payload.
	 * @return void
	 */
	private function replace_docx_semantic_label_blanks_in_place( $docx_path, array $owner_template, array $payload ) {
		if ( ! class_exists( 'ZipArchive' ) || ! $docx_path || ! file_exists( $docx_path ) ) {
			return;
		}

		$label_value_map = $this->build_semantic_label_value_map( $owner_template, $payload );
		if ( empty( $label_value_map ) ) {
			return;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $docx_path ) ) {
			return;
		}

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$entry_name = (string) $zip->getNameIndex( $index );
			if ( 1 !== preg_match( '#^word/(document|header[0-9]+|footer[0-9]+)\.xml$#i', $entry_name ) ) {
				continue;
			}

			$xml = $zip->getFromName( $entry_name );
			if ( false === $xml || '' === $xml ) {
				continue;
			}

			$updated_xml = $this->replace_semantic_blanks_in_docx_xml( (string) $xml, $label_value_map );
			if ( $updated_xml !== $xml ) {
				$zip->addFromString( $entry_name, $updated_xml );
			}
		}

		$zip->close();
	}

	/**
	 * Replaces blank placeholders associated to labels in a Word XML fragment.
	 *
	 * @param string $xml XML content.
	 * @param array  $label_value_map Label-to-value mapping.
	 * @return string
	 */
	private function replace_semantic_blanks_in_docx_xml( $xml, array $label_value_map ) {
		$updated = (string) $xml;

		foreach ( $label_value_map as $label => $value ) {
			$clean_label = trim( (string) $label );
			$clean_value = trim( (string) $value );

			if ( '' === $clean_label || '' === $clean_value ) {
				continue;
			}

			$label_pattern = preg_quote( $clean_label, '/' );
			$value_xml     = esc_xml( $clean_value );

			$pattern_same_node = '/(<w:t[^>]*>\s*' . $label_pattern . '\s*[:\-]?\s*)(_{3,}|\.{3,}|[\x{2026}]{2,})(\s*<\/w:t>)/iu';
			$replacement_same_node = '$1' . $value_xml . '$3';
			$updated = preg_replace( $pattern_same_node, $replacement_same_node, $updated );

			$pattern_next_node = '/(<w:t[^>]*>\s*' . $label_pattern . '\s*[:\-]?\s*<\/w:t>\s*<w:t[^>]*>)(_{3,}|\.{3,}|[\x{2026}]{2,})(\s*<\/w:t>)/iu';
			$replacement_next_node = '$1' . $value_xml . '$3';
			$updated = preg_replace( $pattern_next_node, $replacement_next_node, $updated );
		}

		return (string) $updated;
	}

	/**
	 * Builds semantic mapping between template labels and payload values.
	 *
	 * @param array $owner_template Owner template context.
	 * @param array $payload Lease payload.
	 * @return array<string,string>
	 */
	private function build_semantic_label_value_map( array $owner_template, array $payload ) {
		$template_text = isset( $owner_template['template_text'] ) ? (string) $owner_template['template_text'] : '';
		$template_text = trim( $template_text );
		if ( '' === $template_text ) {
			return array();
		}

		$canonical_values = $this->get_canonical_contract_value_map( $payload );
		$labels           = $this->extract_semantic_candidate_labels( $template_text );
		if ( empty( $labels ) ) {
			return array();
		}

		$field_map = array();
		foreach ( $labels as $label ) {
			$canonical_key = $this->infer_canonical_key_from_label( $label );
			if ( '' !== $canonical_key ) {
				$field_map[ $label ] = $canonical_key;
			}
		}

		if ( class_exists( 'Arriendo_Facil_AI_Service' ) ) {
			try {
				$ai_service = new Arriendo_Facil_AI_Service();
				$ai_result  = $ai_service->map_template_fields(
					array(
						'template_text'     => $template_text,
						'candidate_labels'  => $labels,
						'allowed_canonical' => array_keys( $canonical_values ),
					)
				);

				if ( ! is_wp_error( $ai_result ) && isset( $ai_result['field_map'] ) && is_array( $ai_result['field_map'] ) ) {
					foreach ( $ai_result['field_map'] as $label => $canonical_key ) {
						$label         = (string) $label;
						$canonical_key = sanitize_key( (string) $canonical_key );
						if ( '' !== $label && isset( $canonical_values[ $canonical_key ] ) ) {
							$field_map[ $label ] = $canonical_key;
						}
					}
				}
			} catch ( Throwable $throwable ) {
				error_log( 'Arriendo Facil admin semantic label mapping error: ' . $throwable->getMessage() );
			}
		}

		$label_value_map = array();
		foreach ( $field_map as $label => $canonical_key ) {
			if ( isset( $canonical_values[ $canonical_key ] ) ) {
				$value = trim( (string) $canonical_values[ $canonical_key ] );
				if ( '' !== $value ) {
					$label_value_map[ (string) $label ] = $value;
				}
			}
		}

		return $label_value_map;
	}

	/**
	 * Returns canonical contract field-to-value map from payload.
	 *
	 * @param array $payload Lease payload.
	 * @return array<string,string>
	 */
	private function get_canonical_contract_value_map( array $payload ) {
		return array(
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
			'mascotas'              => isset( $payload['mascotas'] ) ? (string) absint( $payload['mascotas'] ) : '0',
			'personas_viviran'      => isset( $payload['personas_viviran'] ) ? (string) absint( $payload['personas_viviran'] ) : '0',
			'referencia_personal_1' => isset( $payload['referencia_personal_1'] ) ? sanitize_text_field( (string) $payload['referencia_personal_1'] ) : '',
			'referencia_personal_2' => isset( $payload['referencia_personal_2'] ) ? sanitize_text_field( (string) $payload['referencia_personal_2'] ) : '',
			'current_date'          => current_time( 'Y-m-d' ),
		);
	}

	/**
	 * Extracts candidate semantic labels from template text (labels followed by blanks).
	 *
	 * @param string $text Template text.
	 * @return string[]
	 */
	private function extract_semantic_candidate_labels( $text ) {
		$labels = array();
		if ( preg_match_all( '/([A-ZÁÉÍÓÚÑa-záéíóúñ][A-ZÁÉÍÓÚÑa-záéíóúñ\s\/]{2,40})\s*[:\-]?\s*(?:_{3,}|\.{3,}|[\x{2026}]{2,})/u', $text, $matches ) ) {
			foreach ( $matches[1] as $label ) {
				$trimmed = trim( (string) $label );
				if ( '' !== $trimmed ) {
					$labels[] = $trimmed;
				}
			}
		}
		return array_unique( $labels );
	}

	/**
	 * Infers a canonical field key from a Spanish/English label using heuristics.
	 *
	 * @param string $label Label text.
	 * @return string Canonical key or ''.
	 */
	private function infer_canonical_key_from_label( $label ) {
		$lower = mb_strtolower( trim( (string) $label ) );
		$mapping = array(
			'arrendador'        => 'owner_name',
			'propietario'       => 'owner_name',
			'nombre del arrendador' => 'owner_name',
			'nombre del propietario' => 'owner_name',
			'cedula arrendador' => 'owner_id_number',
			'cedula del arrendador' => 'owner_id_number',
			'ci arrendador'     => 'owner_id_number',
			'ruc arrendador'    => 'owner_id_number',
			'arrendatario'      => 'guest_name',
			'inquilino'         => 'guest_name',
			'nombre del arrendatario' => 'guest_name',
			'nombre del inquilino' => 'guest_name',
			'cedula arrendatario' => 'guest_id_number',
			'cedula del arrendatario' => 'guest_id_number',
			'ci arrendatario'   => 'guest_id_number',
			'correo arrendatario' => 'guest_email',
			'email arrendatario' => 'guest_email',
			'correo inquilino'  => 'guest_email',
			'telefono arrendatario' => 'guest_phone',
			'celular arrendatario' => 'guest_phone',
			'telefono inquilino' => 'guest_phone',
			'celular inquilino' => 'guest_phone',
			'direccion'         => 'accommodation_address',
			'direccion del inmueble' => 'accommodation_address',
			'direccion de la propiedad' => 'accommodation_address',
			'ubicacion'         => 'accommodation_address',
			'inmueble'          => 'accommodation_title',
			'propiedad'         => 'accommodation_title',
			'nombre del inmueble' => 'accommodation_title',
			'fecha de inicio'   => 'start_date',
			'fecha inicio'      => 'start_date',
			'inicio del contrato' => 'start_date',
			'inicio del arriendo' => 'start_date',
			'fecha de fin'      => 'end_date',
			'fecha fin'         => 'end_date',
			'fin del contrato'  => 'end_date',
			'fin del arriendo'  => 'end_date',
			'canon'             => 'monthly_rent',
			'canon mensual'     => 'monthly_rent',
			'valor del arriendo' => 'monthly_rent',
			'precio mensual'    => 'monthly_rent',
			'renta mensual'     => 'monthly_rent',
			'garantia'          => 'guarantee_text',
			'detalle garantia'  => 'guarantee_text',
			'mascotas'          => 'mascotas',
			'numero de mascotas' => 'mascotas',
			'personas que viviran' => 'personas_viviran',
			'numero de personas' => 'personas_viviran',
			'referencia personal 1' => 'referencia_personal_1',
			'referencia personal 2' => 'referencia_personal_2',
			'fecha'             => 'current_date',
			'fecha actual'      => 'current_date',
		);

		if ( isset( $mapping[ $lower ] ) ) {
			return $mapping[ $lower ];
		}

		foreach ( $mapping as $keyword => $canonical ) {
			if ( false !== mb_strpos( $lower, $keyword ) ) {
				return $canonical;
			}
		}

		return '';
	}

	/**
	 * Builds token map for owner template placeholder replacement.
	 *
	 * @param array $payload Lease payload.
	 * @return array<string,string>
	 */
	private function build_owner_template_token_map( array $payload ) {
		$canonical = $this->get_canonical_contract_value_map( $payload );

		$aliases = array(
			'owner_name'            => array( 'owner_name', 'owner', 'landlord_name', 'nombre_arrendador', 'arrendador_nombre', 'propietario_nombre', 'nombre_propietario', 'arrendador' ),
			'owner_email'           => array( 'owner_email', 'landlord_email', 'correo_arrendador', 'email_arrendador', 'correo_propietario', 'email_propietario' ),
			'owner_id_number'       => array( 'owner_id', 'owner_id_number', 'landlord_id', 'cedula_arrendador', 'ruc_arrendador', 'cedula_propietario', 'id_propietario' ),
			'guest_name'            => array( 'guest_name', 'tenant_name', 'nombre_arrendatario', 'arrendatario_nombre', 'inquilino_nombre', 'nombre_inquilino', 'arrendatario' ),
			'guest_email'           => array( 'guest_email', 'tenant_email', 'correo_arrendatario', 'email_arrendatario', 'correo_inquilino', 'email_inquilino' ),
			'guest_phone'           => array( 'guest_phone', 'tenant_phone', 'telefono_arrendatario', 'celular_arrendatario', 'telefono_inquilino', 'celular_inquilino' ),
			'guest_id_number'       => array( 'guest_id', 'guest_id_number', 'tenant_id', 'cedula_arrendatario', 'id_arrendatario', 'cedula_inquilino', 'id_inquilino' ),
			'accommodation_title'   => array( 'property_name', 'accommodation_title', 'nombre_inmueble', 'inmueble', 'propiedad', 'nombre_propiedad' ),
			'accommodation_address' => array( 'property_address', 'accommodation_address', 'direccion_inmueble', 'direccion_propiedad', 'direccion' ),
			'start_date'            => array( 'start_date', 'lease_start', 'fecha_inicio', 'fecha_inicio_arriendo', 'inicio_contrato' ),
			'end_date'              => array( 'end_date', 'lease_end', 'fecha_fin', 'fecha_fin_arriendo', 'fin_contrato' ),
			'monthly_rent'          => array( 'monthly_rent', 'rent', 'canon', 'canon_mensual', 'valor_arriendo', 'precio_mensual' ),
			'guarantee_text'        => array( 'guarantee', 'guarantee_text', 'garantia', 'detalle_garantia' ),
			'current_date'          => array( 'current_date', 'fecha_actual', 'fecha_hoy' ),
			'mascotas'              => array( 'mascotas', 'pets', 'numero_mascotas' ),
			'personas_viviran'      => array( 'personas_viviran', 'personas', 'occupants', 'numero_personas' ),
			'referencia_personal_1' => array( 'referencia_personal_1', 'referencia_1', 'ref_personal_1' ),
			'referencia_personal_2' => array( 'referencia_personal_2', 'referencia_2', 'ref_personal_2' ),
		);

		$token_map = array();
		foreach ( $aliases as $field_key => $tokens ) {
			$value = isset( $canonical[ $field_key ] ) ? (string) $canonical[ $field_key ] : '';
			if ( '' === $value ) {
				continue;
			}
			foreach ( $tokens as $token ) {
				$normalized = $this->normalize_contract_placeholder_token( $token );
				if ( '' !== $normalized ) {
					$token_map[ $normalized ] = $value;
				}
			}
		}

		return $token_map;
	}

	/**
	 * Normalizes a placeholder token for matching.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private function normalize_contract_placeholder_token( $token ) {
		return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $token ) );
	}

	/**
	 * Replaces single-bracket instruction placeholders like [indicar fecha] in XML text runs.
	 *
	 * @param string $xml XML content.
	 * @param array  $payload Lease payload.
	 * @return string
	 */
	private function replace_instruction_bracket_placeholders_in_xml( $xml, array $payload ) {
		$updated = preg_replace_callback(
			'/\[(?!\[)([^\]\r\n]{2,180})\](?!\])/u',
			function ( $matches ) use ( $payload ) {
				$raw_instruction = isset( $matches[1] ) ? trim( (string) $matches[1] ) : '';
				if ( '' === $raw_instruction ) {
					return $matches[0];
				}

				$replacement = $this->get_instruction_placeholder_replacement( $raw_instruction, $payload );
				if ( '' === $replacement ) {
					return $matches[0];
				}

				return esc_xml( $replacement );
			},
			(string) $xml
		);

		return is_string( $updated ) ? $updated : (string) $xml;
	}

	/**
	 * Resolves value for instruction placeholders from phrases like "indicar fecha".
	 *
	 * @param string $instruction Placeholder instruction text.
	 * @param array  $payload Lease payload.
	 * @return string
	 */
	private function get_instruction_placeholder_replacement( $instruction, array $payload ) {
		$normalized = $this->normalize_contract_instruction_text( $instruction );
		if ( '' === $normalized ) {
			return '';
		}

		if ( false !== strpos( $normalized, 'opcion a' ) || false !== strpos( $normalized, 'opcion b' ) || false !== strpos( $normalized, 'opcion c' ) ) {
			return '';
		}

		$values = array(
			'owner_name' => isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '',
			'owner_email' => isset( $payload['owner_email'] ) ? sanitize_email( (string) $payload['owner_email'] ) : '',
			'owner_id' => isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '',
			'guest_name' => isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '',
			'guest_email' => isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '',
			'guest_phone' => isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '',
			'guest_id' => isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '',
			'accommodation_title' => isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '',
			'accommodation_address' => isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '',
			'start_date' => isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '',
			'end_date' => isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '',
			'monthly_rent' => isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '',
			'current_date' => current_time( 'Y-m-d' ),
		);

		if ( '' === $values['monthly_rent'] && isset( $payload['desired_price'] ) ) {
			$values['monthly_rent'] = sanitize_text_field( (string) $payload['desired_price'] );
		}

		if ( false !== strpos( $normalized, 'correo' ) || false !== strpos( $normalized, 'email' ) ) {
			if ( false !== strpos( $normalized, 'arrendador' ) || false !== strpos( $normalized, 'propietario' ) ) {
				return $values['owner_email'];
			}
			return $values['guest_email'];
		}

		if ( false !== strpos( $normalized, 'telefono' ) || false !== strpos( $normalized, 'celular' ) ) {
			return $values['guest_phone'];
		}

		if ( false !== strpos( $normalized, 'cedula' ) || false !== strpos( $normalized, 'identidad' ) || false !== strpos( $normalized, 'ruc' ) || false !== strpos( $normalized, 'dni' ) ) {
			if ( false !== strpos( $normalized, 'arrendador' ) || false !== strpos( $normalized, 'propietario' ) ) {
				return $values['owner_id'];
			}
			return $values['guest_id'];
		}

		if ( false !== strpos( $normalized, 'arrendador' ) || false !== strpos( $normalized, 'propietario' ) ) {
			return $values['owner_name'];
		}

		if ( false !== strpos( $normalized, 'arrendatario' ) || false !== strpos( $normalized, 'inquilino' ) ) {
			return $values['guest_name'];
		}

		if ( false !== strpos( $normalized, 'direccion' ) ) {
			return $values['accommodation_address'];
		}

		if ( false !== strpos( $normalized, 'inmueble' ) || false !== strpos( $normalized, 'propiedad' ) || false !== strpos( $normalized, 'departamento' ) ) {
			return $values['accommodation_title'];
		}

		if ( false !== strpos( $normalized, 'fecha' ) ) {
			if ( false !== strpos( $normalized, 'inicio' ) || false !== strpos( $normalized, 'entrada en vigor' ) ) {
				return $values['start_date'];
			}
			if ( false !== strpos( $normalized, 'fin' ) || false !== strpos( $normalized, 'finalizacion' ) || false !== strpos( $normalized, 'terminacion' ) ) {
				return $values['end_date'];
			}
			return $values['current_date'];
		}

		if ( false !== strpos( $normalized, 'canon' ) || false !== strpos( $normalized, 'renta' ) || false !== strpos( $normalized, 'importe' ) || false !== strpos( $normalized, 'cantidad' ) || false !== strpos( $normalized, 'valor' ) || false !== strpos( $normalized, 'dolar' ) ) {
			return $values['monthly_rent'];
		}

		if ( false !== strpos( $normalized, 'ciudad' ) ) {
			return 'Quito';
		}

		return '';
	}

	/**
	 * Normalizes instruction text from DOCX placeholders.
	 *
	 * @param string $text Instruction text.
	 * @return string
	 */
	private function normalize_contract_instruction_text( $text ) {
		$text = strtolower( trim( (string) $text ) );
		$text = strtr(
			$text,
			array(
				'á' => 'a',
				'é' => 'e',
				'í' => 'i',
				'ó' => 'o',
				'ú' => 'u',
				'ü' => 'u',
				'ñ' => 'n',
			)
		);
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Fills blank fields (_____ or .....) in a DOCX template by detecting labeled blanks at paragraph level.
	 *
	 * @param string $docx_path Absolute path to the DOCX file.
	 * @param array  $payload   Lease payload.
	 * @return void
	 */
	private function fill_docx_blank_fields_in_place( $docx_path, array $payload ) {
		if ( ! class_exists( 'ZipArchive' ) || ! $docx_path || ! file_exists( $docx_path ) ) {
			return;
		}

		$value_map = $this->build_blank_fill_value_map( $payload );
		if ( empty( $value_map ) ) {
			return;
		}

		$label_map = $this->build_label_blank_fill_map( $payload );
		if ( empty( $label_map ) ) {
			return;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $docx_path ) ) {
			return;
		}

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = (string) $zip->getNameIndex( $i );
			if ( 1 !== preg_match( '#^word/(document|header[0-9]+|footer[0-9]+)\.xml$#i', $entry ) ) {
				continue;
			}

			$xml = $zip->getFromName( $entry );
			if ( false === $xml || '' === $xml ) {
				continue;
			}

			$updated = preg_replace_callback(
				'#<w:p\b[^>]*>.*?</w:p>#si',
				function ( $match ) use ( $label_map, $value_map ) {
					return $this->apply_label_blank_fill_in_paragraph( $match[0], $label_map, $value_map );
				},
				(string) $xml
			);

			if ( is_string( $updated ) && $updated !== (string) $xml ) {
				$zip->addFromString( $entry, $updated );
			}
		}

		$zip->close();
	}

	/**
	 * Processes a single <w:p> element and fills blank markers.
	 *
	 * @param string $para_xml  Full <w:p>...</w:p> XML string.
	 * @param array  $label_map Ordered label (normalized lowercase) => value map.
	 * @param array  $value_map Canonical key => value map.
	 * @return string
	 */
	private function apply_label_blank_fill_in_paragraph( $para_xml, array $label_map, array $value_map ) {
		preg_match_all( '#<w:t(?:\s[^>]*)?>.*?</w:t>#si', $para_xml, $node_matches );
		if ( empty( $node_matches[0] ) ) {
			return $para_xml;
		}

		$full_text = '';
		foreach ( $node_matches[0] as $node ) {
			preg_match( '#<w:t[^>]*>(.*?)</w:t>#si', $node, $inner );
			$full_text .= isset( $inner[1] ) ? html_entity_decode( (string) $inner[1], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) : '';
		}

		if ( ! preg_match( '/_{3,}|\.{5,}|[\x{2026}]{2,}/u', $full_text ) ) {
			return $para_xml;
		}

		$blank_count = $this->count_blank_markers( $full_text );
		if ( $blank_count <= 0 ) {
			return $para_xml;
		}

		$replacement_values = array();
		$ai_sequence = $this->get_ai_blank_key_sequence_for_line( $full_text, $blank_count, $value_map );
		if ( ! empty( $ai_sequence ) ) {
			foreach ( $ai_sequence as $canonical_key ) {
				if ( isset( $value_map[ $canonical_key ] ) && '' !== trim( (string) $value_map[ $canonical_key ] ) ) {
					$replacement_values[] = (string) $value_map[ $canonical_key ];
				}
			}
		}

		if ( empty( $replacement_values ) ) {
			$replacement_values = $this->infer_blank_values_from_line( $full_text, $blank_count, $value_map );
		}

		if ( empty( $replacement_values ) ) {
			$full_lower = strtr(
				mb_strtolower( $full_text ),
				array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' )
			);
			$replacement_value = null;
			foreach ( $label_map as $label => $value ) {
				if ( false !== mb_strpos( $full_lower, $label ) ) {
					$replacement_value = $value;
					break;
				}
			}
			if ( null === $replacement_value ) {
				return $para_xml;
			}
			for ( $i = 0; $i < $blank_count; $i++ ) {
				$replacement_values[] = (string) $replacement_value;
			}
		}

		$replace_index = 0;
		$updated  = preg_replace_callback(
			'#(<w:t(?:\s[^>]*)?>)(.*?)(</w:t>)#si',
			function ( $m ) use ( $replacement_values, &$replace_index ) {
				$original_text = html_entity_decode( (string) $m[2], ENT_XML1 | ENT_QUOTES, 'UTF-8' );
				$node_text = $original_text;
				while ( $replace_index < count( $replacement_values ) && preg_match( '/_{3,}|\.{5,}|[\x{2026}]{2,}/u', $node_text ) ) {
					$next_value = (string) $replacement_values[ $replace_index ];
					$filled     = preg_replace( '/_{3,}|\.{5,}|[\x{2026}]{2,}/u', $next_value, $node_text, 1 );
					if ( ! is_string( $filled ) || $filled === $node_text ) {
						break;
					}
					$node_text = $filled;
					$replace_index++;
				}
				if ( $node_text !== $original_text ) {
					$open_tag = preg_replace( '/\s+xml:space="[^"]*"/', '', $m[1] );
					$open_tag = rtrim( substr( $open_tag, 0, -1 ) ) . ' xml:space="preserve">';
					return $open_tag . esc_xml( (string) $node_text ) . $m[3];
				}
				return $m[0];
			},
			$para_xml
		);

		return is_string( $updated ) ? $updated : $para_xml;
	}

	/**
	 * Counts blank markers in a plain text string.
	 *
	 * @param string $text Text to inspect.
	 * @return int
	 */
	private function count_blank_markers( $text ) {
		$matches = array();
		preg_match_all( '/_{3,}|\.{5,}|[\x{2026}]{2,}/u', (string) $text, $matches );

		return isset( $matches[0] ) ? count( $matches[0] ) : 0;
	}

	/**
	 * Uses AI to resolve a line-by-line ordered blank key sequence.
	 *
	 * @param string $line_text Line text containing blanks.
	 * @param int    $blank_count Number of blanks in line.
	 * @param array  $value_map Canonical key => value map.
	 * @return array<int,string>
	 */
	private function get_ai_blank_key_sequence_for_line( $line_text, $blank_count, array $value_map ) {
		$line_text   = trim( (string) $line_text );
		$blank_count = absint( $blank_count );
		if ( '' === $line_text || $blank_count <= 0 || ! class_exists( 'Arriendo_Facil_AI_Service' ) ) {
			return array();
		}

		$allowed = array();
		foreach ( $value_map as $key => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$allowed[] = sanitize_key( (string) $key );
			}
		}
		if ( empty( $allowed ) ) {
			return array();
		}

		$cache_key = md5( strtolower( $line_text ) . '|' . $blank_count . '|' . implode( ',', $allowed ) );
		static $cache = array();
		if ( isset( $cache[ $cache_key ] ) && is_array( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		try {
			$ai_result = ( new Arriendo_Facil_AI_Service() )->map_template_line_blanks(
				array(
					'lines' => array(
						array(
							'id'          => 'line_1',
							'text'        => $line_text,
							'blank_count' => $blank_count,
						),
					),
					'allowed_canonical' => array_values( array_unique( $allowed ) ),
				)
			);

			if ( ! is_wp_error( $ai_result ) && isset( $ai_result['line_map']['line_1'] ) && is_array( $ai_result['line_map']['line_1'] ) ) {
				$keys = array();
				foreach ( $ai_result['line_map']['line_1'] as $raw_key ) {
					$key = sanitize_key( (string) $raw_key );
					if ( '' !== $key && in_array( $key, $allowed, true ) ) {
						$keys[] = $key;
					}
				}
				if ( ! empty( $keys ) ) {
					$cache[ $cache_key ] = $keys;
					return $keys;
				}
			}
		} catch ( Throwable $throwable ) {
			error_log( 'Arriendo Facil admin AI line blank mapping error: ' . $throwable->getMessage() );
		}

		$cache[ $cache_key ] = array();
		return array();
	}

	/**
	 * Builds canonical values available for blank completion.
	 *
	 * @param array $payload Lease payload.
	 * @return array<string,string>
	 */
	private function build_blank_fill_value_map( array $payload ) {
		$monthly_rent = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '';
		if ( '' === $monthly_rent && isset( $payload['desired_price'] ) ) {
			$monthly_rent = sanitize_text_field( (string) $payload['desired_price'] );
		}

		$current_date = (string) current_time( 'Y-m-d' );
		$current_parts = explode( '-', $current_date );
		$current_year  = isset( $current_parts[0] ) ? $current_parts[0] : '';
		$current_month = isset( $current_parts[1] ) ? $current_parts[1] : '';
		$current_day   = isset( $current_parts[2] ) ? $current_parts[2] : '';

		return array(
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
			'monthly_rent'          => $monthly_rent,
			'guarantee_text'        => isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : '',
			'rental_years'          => isset( $payload['rental_years'] ) ? (string) absint( $payload['rental_years'] ) : '',
			'current_date'          => $current_date,
			'current_day'           => (string) $current_day,
			'current_month'         => (string) $current_month,
			'current_year'          => (string) $current_year,
		);
	}

	/**
	 * Infers blank replacements from local line context when AI mapping is unavailable.
	 *
	 * @param string $line_text Full line text.
	 * @param int    $blank_count Number of blanks.
	 * @param array  $value_map Canonical key => value map.
	 * @return array<int,string>
	 */
	private function infer_blank_values_from_line( $line_text, $blank_count, array $value_map ) {
		$parts = preg_split( '/(_{3,}|\.{5,}|[\x{2026}]{2,})/u', (string) $line_text );
		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return array();
		}

		$results = array();
		for ( $i = 0; $i < $blank_count; $i++ ) {
			$before = isset( $parts[ $i ] ) ? (string) $parts[ $i ] : '';
			$after  = isset( $parts[ $i + 1 ] ) ? (string) $parts[ $i + 1 ] : '';
			$before_norm = strtr(
				mb_strtolower( $before ),
				array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' )
			);
			$after_norm = strtr(
				mb_strtolower( $after ),
				array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' )
			);
			$ctx = $before_norm . ' ' . $after_norm;

			$key = '';
			if ( preg_match( '/senor\s*$/u', trim( $before_norm ) ) && false !== strpos( $after_norm, 'arrendador' ) ) {
				$key = 'owner_name';
			} elseif ( preg_match( '/senor\s*$/u', trim( $before_norm ) ) && false !== strpos( $after_norm, 'arrendatario' ) ) {
				$key = 'guest_name';
			} elseif ( false !== strpos( $before_norm, 'como arrendador, el senor' ) ) {
				$key = 'owner_name';
			} elseif ( false !== strpos( $before_norm, 'como arrendatario el senor' ) || false !== strpos( $before_norm, 'al senor' ) || false !== strpos( $before_norm, 'arrendatario senor' ) ) {
				$key = 'guest_name';
			} elseif ( false !== strpos( $before_norm, 'propietario de' ) ) {
				$key = 'accommodation_title';
			} elseif ( false !== strpos( $before_norm, 'situada en' ) || false !== strpos( $before_norm, 'ubicado en' ) || false !== strpos( $before_norm, 'calle' ) || false !== strpos( $before_norm, 'direccion' ) ) {
				$key = 'accommodation_address';
			} elseif ( false !== strpos( $before_norm, 'consignado con el numero' ) || false !== strpos( $after_norm, 'consignado con el numero' ) || false !== strpos( $before_norm, 'cedula' ) || false !== strpos( $after_norm, 'cedula' ) ) {
				$key = 'guest_id_number';
			} elseif ( false !== strpos( $before_norm, 'cantidad de' ) && ( false !== strpos( $after_norm, 'garantia' ) || false !== strpos( $after_norm, 'usd que obliga' ) ) ) {
				$key = 'guarantee_text';
			} elseif ( false !== strpos( $before_norm, 'cantidad de' ) && false !== strpos( $after_norm, 'usd por mes' ) ) {
				$key = 'monthly_rent';
			} elseif ( false !== strpos( $before_norm, 'plazo de este contrato es de' ) && false !== strpos( $after_norm, 'anos' ) ) {
				$key = 'rental_years';
			} elseif ( false !== strpos( $ctx, 'arrendatario' ) && false !== strpos( $ctx, 'senor' ) ) {
				$key = 'guest_name';
			}

			if ( '' !== $key && isset( $value_map[ $key ] ) && '' !== trim( (string) $value_map[ $key ] ) ) {
				$results[] = (string) $value_map[ $key ];
			}
		}

		return $results;
	}

	/**
	 * Builds an ordered label => value map for DOCX blank field filling.
	 *
	 * @param array $payload Lease payload.
	 * @return array<string,string>
	 */
	private function build_label_blank_fill_map( array $payload ) {
		$monthly_rent = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '';
		if ( '' === $monthly_rent && isset( $payload['desired_price'] ) ) {
			$monthly_rent = sanitize_text_field( (string) $payload['desired_price'] );
		}

		$values = array(
			'owner_name'            => isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '',
			'owner_id'              => isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '',
			'owner_email'           => isset( $payload['owner_email'] ) ? sanitize_email( (string) $payload['owner_email'] ) : '',
			'guest_name'            => isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '',
			'guest_id'              => isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '',
			'guest_phone'           => isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '',
			'guest_email'           => isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '',
			'accommodation_address' => isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '',
			'accommodation_title'   => isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '',
			'start_date'            => isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '',
			'end_date'              => isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '',
			'monthly_rent'          => $monthly_rent,
			'guarantee_text'        => isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : '',
			'current_date'          => current_time( 'Y-m-d' ),
		);

		$patterns = array(
			// Owner - specific.
			array( 'nombre del arrendador',          'owner_name' ),
			array( 'nombre propietario',             'owner_name' ),
			array( 'cedula del arrendador',          'owner_id' ),
			array( 'c.i. del arrendador',            'owner_id' ),
			array( 'ci del arrendador',              'owner_id' ),
			array( 'ruc del arrendador',             'owner_id' ),
			array( 'cedula arrendador',              'owner_id' ),
			array( 'correo del arrendador',          'owner_email' ),
			array( 'correo arrendador',              'owner_email' ),
			array( 'correo del propietario',         'owner_email' ),
			// Guest - specific.
			array( 'nombre del arrendatario',        'guest_name' ),
			array( 'nombre del inquilino',           'guest_name' ),
			array( 'nombre arrendatario',            'guest_name' ),
			array( 'nombre inquilino',               'guest_name' ),
			array( 'cedula del arrendatario',        'guest_id' ),
			array( 'cedula del inquilino',           'guest_id' ),
			array( 'c.i. del arrendatario',          'guest_id' ),
			array( 'ci del arrendatario',            'guest_id' ),
			array( 'cedula de ciudadania',           'guest_id' ),
			array( 'cedula arrendatario',            'guest_id' ),
			array( 'cedula inquilino',               'guest_id' ),
			array( 'numero de cedula',               'guest_id' ),
			array( 'numero de identificacion',       'guest_id' ),
			array( 'documento de identidad',         'guest_id' ),
			array( 'telefono del arrendatario',      'guest_phone' ),
			array( 'celular del arrendatario',       'guest_phone' ),
			array( 'telefono del inquilino',         'guest_phone' ),
			array( 'celular del inquilino',          'guest_phone' ),
			array( 'telefono arrendatario',          'guest_phone' ),
			array( 'celular arrendatario',           'guest_phone' ),
			array( 'correo del arrendatario',        'guest_email' ),
			array( 'correo arrendatario',            'guest_email' ),
			array( 'correo inquilino',               'guest_email' ),
			array( 'email arrendatario',             'guest_email' ),
			array( 'email inquilino',                'guest_email' ),
			// Property.
			array( 'direccion del inmueble',         'accommodation_address' ),
			array( 'direccion de la propiedad',      'accommodation_address' ),
			array( 'direccion del bien inmueble',    'accommodation_address' ),
			array( 'ubicacion del inmueble',         'accommodation_address' ),
			array( 'nombre del inmueble',            'accommodation_title' ),
			array( 'nombre de la propiedad',         'accommodation_title' ),
			array( 'nombre del bien',                'accommodation_title' ),
			// Dates.
			array( 'fecha de inicio del contrato',   'start_date' ),
			array( 'fecha de inicio del arriendo',   'start_date' ),
			array( 'fecha de inicio',                'start_date' ),
			array( 'inicio del contrato',            'start_date' ),
			array( 'fecha inicio',                   'start_date' ),
			array( 'fecha de fin del contrato',      'end_date' ),
			array( 'fecha de terminacion',           'end_date' ),
			array( 'fecha de termino',               'end_date' ),
			array( 'fecha de vencimiento',           'end_date' ),
			array( 'fecha de fin',                   'end_date' ),
			array( 'fin del contrato',               'end_date' ),
			array( 'fecha fin',                      'end_date' ),
			// Rent.
			array( 'canon mensual de arrendamiento', 'monthly_rent' ),
			array( 'valor del canon',                'monthly_rent' ),
			array( 'canon de arrendamiento',         'monthly_rent' ),
			array( 'valor mensual del arriendo',     'monthly_rent' ),
			array( 'valor del arriendo',             'monthly_rent' ),
			array( 'canon mensual',                  'monthly_rent' ),
			array( 'valor mensual',                  'monthly_rent' ),
			array( 'renta mensual',                  'monthly_rent' ),
			array( 'precio mensual',                 'monthly_rent' ),
			// Guarantee.
			array( 'garantia del contrato',          'guarantee_text' ),
			array( 'detalle de garantia',            'guarantee_text' ),
			array( 'tipo de garantia',               'guarantee_text' ),
			array( 'garantia',                       'guarantee_text' ),
			// Contextual phrases used by owner contracts with free-form text and dotted lines.
			array( 'y por otra parte',               'guest_name' ),
			array( 'parte a la que en adelante se le denominara como el arrendatario', 'guest_name' ),
			array( 'representada por',               'guest_name' ),
			array( 'autoriza a',                     'guest_name' ),
			array( 'manifiesta que se somete',       'guest_name' ),
			array( 'pagara por la utilizacion',      'guest_name' ),
			// Generic (least specific - checked last).
			array( 'propietario',                    'owner_name' ),
			array( 'arrendador',                     'owner_name' ),
			array( 'arrendatario',                   'guest_name' ),
			array( 'inquilino',                      'guest_name' ),
			array( 'cedula',                         'guest_id' ),
			array( 'telefono',                       'guest_phone' ),
			array( 'celular',                        'guest_phone' ),
			array( 'direccion',                      'accommodation_address' ),
			array( 'inmueble',                       'accommodation_title' ),
		);

		$map = array();
		foreach ( $patterns as $pair ) {
			$label = strtr(
				mb_strtolower( (string) $pair[0] ),
				array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' )
			);
			$key = (string) $pair[1];
			if ( ! isset( $map[ $label ] ) && isset( $values[ $key ] ) && '' !== $values[ $key ] ) {
				$map[ $label ] = $values[ $key ];
			}
		}

		return $map;
	}

	/**
	 * Downloads the owner contract template DOCX from R2 to a local temp file.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|false Local temp file path or false on failure.
	 */
	private function download_owner_template_from_r2( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		$object_key = (string) get_post_meta( $attachment_id, '_af_r2_object_key', true );
		if ( '' === trim( $object_key ) ) {
			return false;
		}

		$r2_config = $this->get_r2_config();
		if ( is_wp_error( $r2_config ) ) {
			return false;
		}

		$object_key   = ltrim( $object_key, '/' );
		$amz_date     = gmdate( 'Ymd\\THis\\Z' );
		$date_stamp   = gmdate( 'Ymd' );
		$scope        = $date_stamp . '/' . $r2_config['region'] . '/' . $r2_config['service'] . '/aws4_request';
		$canonical_uri = '/' . rawurlencode( $r2_config['bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );

		$canonical_headers =
			'host:' . $r2_config['host'] . "\n"
			. 'x-amz-content-sha256:UNSIGNED-PAYLOAD' . "\n"
			. 'x-amz-date:' . $amz_date . "\n";
		$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request =
			"GET\n"
			. $canonical_uri . "\n"
			. "\n"
			. $canonical_headers . "\n"
			. $signed_headers . "\n"
			. 'UNSIGNED-PAYLOAD';

		$string_to_sign =
			'AWS4-HMAC-SHA256' . "\n"
			. $amz_date . "\n"
			. $scope . "\n"
			. hash( 'sha256', $canonical_request );

		$signing_key = $this->get_aws_v4_signing_key( $r2_config['secret_key'], $date_stamp, $r2_config['region'], $r2_config['service'] );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$authorization =
			'AWS4-HMAC-SHA256 '
			. 'Credential=' . $r2_config['access_key'] . '/' . $scope . ', '
			. 'SignedHeaders=' . $signed_headers . ', '
			. 'Signature=' . $signature;

		$url = $r2_config['endpoint'] . $canonical_uri;

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Host'                 => $r2_config['host'],
					'x-amz-date'           => $amz_date,
					'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
					'Authorization'        => $authorization,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'Arriendo Facil admin R2 template download error: ' . $response->get_error_message() );
			return false;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			error_log( 'Arriendo Facil admin R2 template download HTTP ' . $status_code );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return false;
		}

		$tmp_file = wp_tempnam( 'af_owner_template_' . $attachment_id . '.docx' );
		if ( ! $tmp_file ) {
			return false;
		}

		if ( false === file_put_contents( $tmp_file, $body ) ) {
			@unlink( $tmp_file );
			return false;
		}

		return $tmp_file;
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
				if ( $author && in_array( 'af_owner', (array) $author->roles, true ) ) {
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
		$template_text = $this->extract_contract_template_text( $path, $mime_type );
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
	 * Extracts plain text from contract template when supported.
	 *
	 * @param string $file_path File path.
	 * @param string $mime_type Mime type.
	 * @return string
	 */
	private function extract_contract_template_text( $file_path, $mime_type ) {
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return '';
		}

		$mime_type = strtolower( (string) $mime_type );
		$extension = strtolower( (string) pathinfo( (string) $file_path, PATHINFO_EXTENSION ) );

		if ( false !== strpos( $mime_type, 'text/' ) ) {
			$content = file_get_contents( $file_path );
			if ( false !== $content ) {
				return $this->limit_template_text( wp_strip_all_tags( (string) $content ) );
			}

			return '';
		}

		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime_type || 'docx' === $extension ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( true === $zip->open( $file_path ) ) {
					$xml = $zip->getFromName( 'word/document.xml' );
					$zip->close();

					if ( false !== $xml && '' !== $xml ) {
						$text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $xml ) );
						return $this->limit_template_text( (string) $text );
					}
				}
			}

			return '';
		}

		if ( false !== strpos( $mime_type, 'application/pdf' ) || 'pdf' === $extension ) {
			return $this->limit_template_text( $this->extract_text_from_pdf_file( $file_path ) );
		}

		if ( false !== strpos( $mime_type, 'application/msword' ) || 'doc' === $extension ) {
			return $this->limit_template_text( $this->extract_text_from_legacy_doc_file( $file_path ) );
		}

		$content = file_get_contents( $file_path );
		if ( false !== $content ) {
			$fallback_text = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', (string) $content );
			return $this->limit_template_text( (string) $fallback_text );
		}

		return '';
	}

	/**
	 * Extracts readable text from a PDF file using basic stream parsing.
	 *
	 * @param string $file_path PDF path.
	 * @return string
	 */
	private function extract_text_from_pdf_file( $file_path ) {
		$content = file_get_contents( $file_path );
		if ( false === $content || '' === $content ) {
			return '';
		}

		$buffers = array( (string) $content );
		if ( preg_match_all( '/stream(.*?)endstream/s', (string) $content, $stream_matches ) ) {
			foreach ( $stream_matches[1] as $stream ) {
				$stream = ltrim( (string) $stream, "\r\n" );
				$stream = rtrim( $stream, "\r\n" );

				$decoded = @gzuncompress( $stream );
				if ( false === $decoded ) {
					$decoded = @gzinflate( $stream );
				}

				$buffers[] = ( false !== $decoded && '' !== $decoded ) ? (string) $decoded : $stream;
			}
		}

		$text_chunks = array();
		foreach ( $buffers as $buffer ) {
			if ( preg_match_all( '/\((.*?)\)\s*Tj/s', (string) $buffer, $matches ) ) {
				foreach ( $matches[1] as $token ) {
					$decoded_token = $this->decode_pdf_text_token( (string) $token );
					if ( '' !== $decoded_token ) {
						$text_chunks[] = $decoded_token;
					}
				}
			}

			if ( preg_match_all( '/\[(.*?)\]\s*TJ/s', (string) $buffer, $matches ) ) {
				foreach ( $matches[1] as $array_body ) {
					if ( preg_match_all( '/\((.*?)\)/s', (string) $array_body, $token_matches ) ) {
						foreach ( $token_matches[1] as $token ) {
							$decoded_token = $this->decode_pdf_text_token( (string) $token );
							if ( '' !== $decoded_token ) {
								$text_chunks[] = $decoded_token;
							}
						}
					}
				}
			}
		}

		if ( empty( $text_chunks ) ) {
			return '';
		}

		return trim( preg_replace( '/\s+/', ' ', implode( ' ', $text_chunks ) ) );
	}

	/**
	 * Decodes escaped PDF text token content.
	 *
	 * @param string $token Token text.
	 * @return string
	 */
	private function decode_pdf_text_token( $token ) {
		$token = preg_replace_callback(
			'/\\\\([0-7]{1,3})/',
			static function ( $matches ) {
				return chr( octdec( $matches[1] ) );
			},
			(string) $token
		);

		$token = strtr(
			(string) $token,
			array(
				'\\n'   => "\n",
				'\\r'   => "\r",
				'\\t'   => "\t",
				'\\b'   => '',
				'\\f'   => '',
				'\\('   => '(',
				'\\)'   => ')',
				'\\\\' => '\\',
			)
		);

		$token = wp_strip_all_tags( $token );
		$token = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', (string) $token );

		return trim( (string) $token );
	}

	/**
	 * Extracts rough text from legacy .doc binary file.
	 *
	 * @param string $file_path DOC path.
	 * @return string
	 */
	private function extract_text_from_legacy_doc_file( $file_path ) {
		$content = file_get_contents( $file_path );
		if ( false === $content || '' === $content ) {
			return '';
		}

		$text = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', (string) $content );
		$text = preg_replace( '/\s+/', ' ', (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Truncates template text before sending it to AI.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function limit_template_text( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

		if ( '' === $text ) {
			return '';
		}

		if ( strlen( $text ) > 8000 ) {
			return substr( $text, 0, 8000 );
		}

		return $text;
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

		$local_url     = trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name );
		$document_url  = $local_url;
		$storage_meta  = array(
			'provider'  => 'local',
			'file_name' => $file_name,
			'local_url' => $local_url,
			'mime_type' => $mime_type,
		);

		$storage_provider = $this->get_storage_setting( 'AF_STORAGE_PROVIDER', 'af_storage_provider', 'cloudflare_r2' );
		if ( 'cloudflare_r2' === $storage_provider ) {
			$r2_config = $this->get_r2_config();
			if ( ! is_wp_error( $r2_config ) ) {
				$contents = file_get_contents( $file_path );
				if ( false !== $contents ) {
					$object_key = sprintf( 'lease-contracts/%d/%s', $lease_id, sanitize_file_name( $file_name ) );
					$upload     = $this->upload_contents_to_r2(
						$contents,
						$object_key,
						$mime_type,
						$r2_config
					);
					if ( ! is_wp_error( $upload ) ) {
						$document_url = add_query_arg(
							array(
								'action'   => 'af_download_lease_contract',
								'lease_id' => $lease_id,
							),
							admin_url( 'admin-ajax.php' )
						);
						$storage_meta = array(
							'provider'   => 'cloudflare_r2',
							'object_key' => $object_key,
							'file_name'  => $file_name,
							'local_url'  => $local_url,
							'mime_type'  => $mime_type,
						);
					}
				}
			}
		}

		if ( class_exists( 'Arriendo_Facil_Lease' ) ) {
			$lease_service = new Arriendo_Facil_Lease();
			$lease_service->set_contract_storage_meta( $lease_id, $storage_meta );
		}

		return $document_url;
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
	 * Reads storage setting with constant priority.
	 *
	 * @param string $constant_name Constant name.
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
			return new WP_Error( 'af_r2_missing_config', __( 'Faltan credenciales de Cloudflare R2. Revisa Ajustes > Proveedor en la nube.', 'arriendo-facil' ) );
		}

		$parsed = wp_parse_url( $endpoint );
		$host   = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		$scheme = isset( $parsed['scheme'] ) ? (string) $parsed['scheme'] : '';

		if ( '' === $host || '' === $scheme ) {
			return new WP_Error( 'af_r2_invalid_endpoint', __( 'URL de endpoint de Cloudflare R2 invalida.', 'arriendo-facil' ) );
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
	 * Uploads raw contents to Cloudflare R2 using SigV4.
	 *
	 * @param string $contents File contents.
	 * @param string $object_key Object key path.
	 * @param string $mime_type Mime type.
	 * @param array  $r2_config Parsed R2 config.
	 * @return true|WP_Error
	 */
	private function upload_contents_to_r2( $contents, $object_key, $mime_type, array $r2_config ) {
		$payload_hash   = hash( 'sha256', $contents );
		$amz_date       = gmdate( 'Ymd\\THis\\Z' );
		$date_stamp     = gmdate( 'Ymd' );
		$canonical_uri  = '/' . rawurlencode( $r2_config['bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( (string) $object_key ) );

		$canonical_headers =
			'host:' . $r2_config['host'] . "\n"
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

		$response = wp_remote_request(
			$r2_config['endpoint'] . $canonical_uri,
			array(
				'method'  => 'PUT',
				'timeout' => 45,
				'headers' => array(
					'Host'                 => $r2_config['host'],
					'Content-Type'         => $mime_type,
					'x-amz-date'           => $amz_date,
					'x-amz-content-sha256' => $payload_hash,
					'Authorization'        => $authorization,
				),
				'body' => $contents,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error( 'af_r2_upload_failed', __( 'Cloudflare R2 rejected the generated contract file.', 'arriendo-facil' ) );
		}

		return true;
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
	 * Builds AWS Signature V4 signing key.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $date_stamp Date stamp.
	 * @param string $region Region.
	 * @param string $service Service.
	 * @return string
	 */
	private function get_aws_v4_signing_key( $secret_key, $date_stamp, $region, $service ) {
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );

		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}
}
