<?php
/**
 * Global search: one box in the top bar that looks across the whole system.
 *
 * Typing shows a short, grouped list (inmuebles, inquilinos, contratos,
 * mantenimiento, técnicos, catálogos); Enter opens a full results page. Every
 * query is limited to what the user may see (their own portfolio for property
 * managers, everything for platform admins) and to the features of their plan.
 *
 * Matching is accent- and case-insensitive (the WordPress collation) and every
 * word of the query must match, in any order.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Global_Search {

	const PAGE_SLUG  = 'af-search';
	const NONCE      = 'af_global_search';
	const MIN_LENGTH = 2;
	const MAX_WORDS  = 5;

	/** Rows per group in the dropdown / on the results page. */
	const LIMIT_SUGGEST = 4;
	const LIMIT_PAGE    = 25;

	public function __construct() {
		add_action( 'wp_ajax_af_global_search', array( $this, 'ajax_search' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 30 );
		add_filter( 'parent_file', array( $this, 'keep_parent_menu_open' ) );
		add_filter( 'submenu_file', array( $this, 'keep_submenu_selected' ) );
	}

	/* ---------------------------------------------------------------------
	 * Query helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Splits a raw query into the words that must all match.
	 *
	 * @param string $raw Query typed by the user.
	 * @return string[] Empty when the query is too short to search.
	 */
	public static function words( $raw ) {
		$raw = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $raw ) ) );
		if ( '' === $raw || mb_strlen( $raw, 'UTF-8' ) < self::MIN_LENGTH ) {
			return array();
		}

		return array_slice( array_values( array_unique( explode( ' ', $raw ) ) ), 0, self::MAX_WORDS );
	}

	/**
	 * Builds "(expr LIKE %s AND expr LIKE %s ...)" for every word.
	 *
	 * @param string   $expression SQL expression to search in (trusted, built here).
	 * @param string[] $words      Words from words().
	 * @param array    $args       Receives the placeholder values.
	 * @return string
	 */
	private static function like_clause( $expression, array $words, array &$args ) {
		global $wpdb;

		$parts = array();
		foreach ( $words as $word ) {
			$parts[] = $expression . ' LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $word ) . '%';
		}

		return '(' . implode( ' AND ', $parts ) . ')';
	}

	/**
	 * Property IDs matching the query by title, address or city.
	 *
	 * Also used by the Inmuebles screen so both searches agree.
	 *
	 * @param string        $term     Query.
	 * @param string[]      $statuses Post statuses to include.
	 * @param int[]|null    $scope    Accessible accommodation IDs (null = all).
	 * @param int           $limit    Max IDs.
	 * @return int[]
	 */
	public static function property_ids( $term, array $statuses, $scope, $limit = 500 ) {
		global $wpdb;

		$words = self::words( $term );
		if ( empty( $words ) || ( null !== $scope && empty( $scope ) ) ) {
			return array();
		}

		$args       = array();
		$status_in  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args       = array_merge( $args, $statuses );
		$scope_sql  = null === $scope ? '' : ' AND p.ID IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope ) . ')';
		$like       = self::like_clause( "CONCAT_WS(' ', p.post_title, ma.meta_value, mc.meta_value)", $words, $args );
		$args[]     = max( 1, (int) $limit );

		$sql = "SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} ma ON ma.post_id = p.ID AND ma.meta_key = '_af_address'
			LEFT JOIN {$wpdb->postmeta} mc ON mc.post_id = p.ID AND mc.meta_key = '_af_city'
			WHERE p.post_type = 'accommodation' AND p.post_status IN ({$status_in}){$scope_sql} AND {$like}
			ORDER BY p.post_modified DESC
			LIMIT %d";

		return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above; ids cast to int.
	}

	/* ---------------------------------------------------------------------
	 * Searching
	 * ------------------------------------------------------------------- */

	/**
	 * Runs the search across every entity the current user can reach.
	 *
	 * @param string $term  Query.
	 * @param int    $limit Rows per group.
	 * @return array<int,array{key:string,label:string,icon:string,more:string,items:array}>
	 */
	public function search( $term, $limit ) {
		$words = self::words( $term );
		if ( empty( $words ) || ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			return array();
		}

		$limit  = max( 1, (int) $limit );
		$scope  = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
		$groups = array(
			$this->properties_group( $term, $scope, $limit ),
			$this->guests_group( $words, $scope, $limit, $term ),
			$this->leases_group( $words, $scope, $limit, $term ),
		);

		if ( ! class_exists( 'Arriendo_Facil_Plans' ) || ! Arriendo_Facil_Plans::applies_to() || Arriendo_Facil_Plans::has_feature( 'maintenance' ) ) {
			$groups[] = $this->maintenance_group( $words, $scope, $limit, $term );
			$groups[] = $this->providers_group( $words, $limit, $term );
		}
		if ( ! class_exists( 'Arriendo_Facil_Plans' ) || ! Arriendo_Facil_Plans::applies_to() || Arriendo_Facil_Plans::has_feature( 'catalog' ) ) {
			$groups[] = $this->catalogs_group( $term, $limit );
		}

		return array_values( array_filter( $groups, static function ( $group ) {
			return ! empty( $group['items'] );
		} ) );
	}

	/**
	 * Builds a group array.
	 *
	 * @param string $key   Group key.
	 * @param string $label Spanish heading.
	 * @param string $icon  af_lucide icon name.
	 * @param string $more  URL of the section filtered by the query.
	 * @param array  $items Rows: title, subtitle, url, badge.
	 * @return array
	 */
	private static function group( $key, $label, $icon, $more, array $items ) {
		return array(
			'key'   => $key,
			'label' => $label,
			'icon'  => $icon,
			'more'  => $more,
			'items' => $items,
		);
	}

	private function properties_group( $term, $scope, $limit ) {
		$ids   = self::property_ids( $term, array( 'publish', 'private', 'draft', 'pending' ), $scope, $limit );
		$items = array();

		foreach ( $ids as $id ) {
			$address = trim( trim( (string) get_post_meta( $id, '_af_address', true ) ) . ', ' . trim( (string) get_post_meta( $id, '_af_city', true ) ), ' ,' );
			$status  = get_post_status( $id );
			$items[] = array(
				'title'    => get_the_title( $id ),
				'subtitle' => $address,
				'badge'    => in_array( $status, array( 'draft', 'pending' ), true ) ? __( 'Borrador', 'arriendo-facil' ) : '',
				'url'      => add_query_arg( 'id', $id, admin_url( 'admin.php?page=' . Arriendo_Facil_Accommodation_Wizard::PAGE_SLUG ) ),
			);
		}

		return self::group( 'properties', __( 'Inmuebles', 'arriendo-facil' ), 'building', Arriendo_Facil_Properties_Page::url( array( 's' => $term ) ), $items );
	}

	private function guests_group( array $words, $scope, $limit, $term ) {
		global $wpdb;

		Arriendo_Facil_Guest::ensure_guest_extra_columns();

		$args  = array();
		$like  = self::like_clause( "CONCAT_WS(' ', g.first_name, g.last_name, g.email, g.phone, g.id_number)", $words, $args );
		$where = 'g.deleted_at IS NULL AND ' . $like;

		if ( null !== $scope ) {
			$ids_sql = Arriendo_Facil_Tenancy::ids_in_clause( $scope );
			$where  .= " AND ( g.accommodation_id IN ({$ids_sql}) OR g.created_by = %d OR g.id IN ( SELECT l.guest_id FROM {$wpdb->prefix}af_leases l WHERE l.accommodation_id IN ({$ids_sql}) AND l.deleted_at IS NULL ) )";
			$args[]  = get_current_user_id();
		}
		$args[] = $limit;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.id, g.first_name, g.last_name, g.email, g.phone FROM {$wpdb->prefix}af_guests g WHERE {$where} ORDER BY g.first_name ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragments built with placeholders; ids cast to int.
				$args
			)
		);

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'title'    => trim( $row->first_name . ' ' . $row->last_name ),
				'subtitle' => implode( ' · ', array_filter( array( (string) $row->email, (string) $row->phone ) ) ),
				'badge'    => '',
				'url'      => admin_url( 'admin.php?page=af-guests&view=profile&guest_id=' . (int) $row->id ),
			);
		}

		return self::group( 'guests', __( 'Inquilinos', 'arriendo-facil' ), 'users', admin_url( 'admin.php?page=af-guests&af_q=' . rawurlencode( $term ) ), $items );
	}

	private function leases_group( array $words, $scope, $limit, $term ) {
		global $wpdb;

		if ( null !== $scope && empty( $scope ) ) {
			return self::group( 'leases', '', '', '', array() );
		}

		$args  = array();
		$like  = self::like_clause( "CONCAT_WS(' ', g.first_name, g.last_name, g.email, p.post_title)", $words, $args );
		$where = 'l.deleted_at IS NULL AND ' . $like;
		if ( null !== $scope ) {
			$where .= ' AND l.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope ) . ')';
		}
		$args[] = $limit;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.status, l.start_date, l.end_date, TRIM(CONCAT(g.first_name, ' ', g.last_name)) AS guest_name, p.post_title
				 FROM {$wpdb->prefix}af_leases l
				 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 WHERE {$where}
				 ORDER BY l.id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragments built with placeholders; ids cast to int.
				$args
			)
		);

		$statuses = array(
			'draft'           => __( 'Borrador', 'arriendo-facil' ),
			'active'          => __( 'Activo', 'arriendo-facil' ),
			'pending_release' => __( 'Por liberar', 'arriendo-facil' ),
			'terminated'      => __( 'Terminado', 'arriendo-facil' ),
		);
		$items    = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'title'    => trim( $row->guest_name . ' — ' . $row->post_title, ' —' ),
				'subtitle' => trim( mysql2date( 'j M Y', $row->start_date ) . ' → ' . mysql2date( 'j M Y', $row->end_date ) ),
				'badge'    => isset( $statuses[ $row->status ] ) ? $statuses[ $row->status ] : '',
				'url'      => admin_url( 'admin.php?page=af-leases&af_q=' . rawurlencode( $term ) ),
			);
		}

		return self::group( 'leases', __( 'Contratos', 'arriendo-facil' ), 'file-text', admin_url( 'admin.php?page=af-leases&af_q=' . rawurlencode( $term ) ), $items );
	}

	private function maintenance_group( array $words, $scope, $limit, $term ) {
		global $wpdb;

		if ( null !== $scope && empty( $scope ) ) {
			return self::group( 'maintenance', '', '', '', array() );
		}

		$args  = array();
		$like  = self::like_clause( "CONCAT_WS(' ', r.asset_name, r.damage_details, r.asset_location, r.contact_name, r.notes, p.post_title)", $words, $args );
		$where = $like;
		if ( null !== $scope ) {
			$where .= ' AND r.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope ) . ')';
		}
		$args[] = $limit;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.id, r.asset_name, r.damage_details, r.status, p.post_title FROM ' . Arriendo_Facil_Maintenance::table() . " r
				 LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
				 WHERE {$where}
				 ORDER BY r.id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragments built with placeholders; ids cast to int.
				$args
			)
		);

		$statuses = Arriendo_Facil_Maintenance::statuses();
		$items    = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'title'    => '' !== (string) $row->asset_name ? (string) $row->asset_name : __( 'Solicitud sin detalle', 'arriendo-facil' ),
				'subtitle' => (string) $row->post_title,
				'badge'    => isset( $statuses[ $row->status ] ) ? $statuses[ $row->status ] : '',
				'url'      => admin_url( 'admin.php?page=af-maintenance&af_q=' . rawurlencode( $term ) ),
			);
		}

		return self::group( 'maintenance', __( 'Mantenimiento', 'arriendo-facil' ), 'wrench', admin_url( 'admin.php?page=af-maintenance&af_q=' . rawurlencode( $term ) ), $items );
	}

	private function providers_group( array $words, $limit, $term ) {
		global $wpdb;

		$args  = array();
		$like  = self::like_clause( "CONCAT_WS(' ', s.name, s.company, s.trade, s.phone, s.city, s.zone)", $words, $args );
		$where = $like . " AND s.status = 'active'";
		if ( ! Arriendo_Facil_Tenancy::can_manage_all() ) {
			// Their own contacts plus the ones Arriendo Fácil recommends (the same set they can assign).
			$where .= ' AND ( s.owner_id = %d OR s.is_recommended = 1 )';
			$args[] = get_current_user_id();
		}
		$args[] = $limit;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.name, s.trade, s.phone, s.city FROM {$wpdb->prefix}af_service_providers s WHERE {$where} ORDER BY s.name ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragments built with placeholders.
				$args
			)
		);

		$trades = Arriendo_Facil_Service_Providers::trades();
		$items  = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'title'    => (string) $row->name,
				'subtitle' => implode( ' · ', array_filter( array( isset( $trades[ $row->trade ] ) ? $trades[ $row->trade ] : '', (string) $row->phone, (string) $row->city ) ) ),
				'badge'    => '',
				'url'      => admin_url( 'admin.php?page=af-maintenance&af_tab=personal&af_q=' . rawurlencode( $term ) ),
			);
		}

		return self::group( 'providers', __( 'Técnicos', 'arriendo-facil' ), 'wrench', admin_url( 'admin.php?page=af-maintenance&af_tab=personal&af_q=' . rawurlencode( $term ) ), $items );
	}

	private function catalogs_group( $term, $limit ) {
		$args = array(
			'post_type'      => Arriendo_Facil_Catalogs::POST_TYPE,
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => $limit,
			's'              => $term,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		if ( ! Arriendo_Facil_Tenancy::can_manage_all() ) {
			$args['author'] = get_current_user_id();
		}

		$items = array();
		foreach ( get_posts( $args ) as $catalog ) {
			$items[] = array(
				'title'    => get_the_title( $catalog ),
				'subtitle' => '',
				'badge'    => '',
				'url'      => Arriendo_Facil_Catalogs::admin_url( array( 'catalog' => $catalog->ID ) ),
			);
		}

		return self::group( 'catalogs', __( 'Catálogos', 'arriendo-facil' ), 'layout-grid', Arriendo_Facil_Catalogs::admin_url(), $items );
	}

	/* ---------------------------------------------------------------------
	 * AJAX (dropdown)
	 * ------------------------------------------------------------------- */

	public function ajax_search() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para buscar.', 'arriendo-facil' ) ), 403 );
		}

		$term = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';

		wp_send_json_success(
			array(
				'groups'   => $this->search( $term, self::LIMIT_SUGGEST ),
				'resultsUrl' => self::results_url( $term ),
			)
		);
	}

	/**
	 * URL of the full results page.
	 *
	 * @param string $term Query.
	 * @return string
	 */
	public static function results_url( $term ) {
		return add_query_arg( array( 'page' => self::PAGE_SLUG, 'q' => $term ), admin_url( 'admin.php' ) );
	}

	/* ---------------------------------------------------------------------
	 * Results page
	 * ------------------------------------------------------------------- */

	public function register_page() {
		add_submenu_page(
			'',
			__( 'Resultados de búsqueda', 'arriendo-facil' ),
			__( 'Búsqueda', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	private function is_this_page() {
		return isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function keep_parent_menu_open( $parent_file ) {
		return $this->is_this_page() ? 'arriendo-facil' : $parent_file;
	}

	public function keep_submenu_selected( $submenu_file ) {
		return $this->is_this_page() ? 'arriendo-facil' : $submenu_file;
	}

	public function render() {
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para ver esta página.', 'arriendo-facil' ) );
		}

		$term      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$too_short = '' !== $term && empty( self::words( $term ) );
		$groups    = $too_short ? array() : $this->search( $term, self::LIMIT_PAGE );

		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/search-results.php';
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	public function enqueue_assets() {
		if ( ! wp_script_is( 'af-app-shell', 'enqueued' ) ) {
			return;
		}

		$css = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-global-search.css';
		$js  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-global-search.js';

		wp_enqueue_style(
			'af-global-search',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-global-search.css',
			array( 'af-app-shell' ),
			file_exists( $css ) ? (string) filemtime( $css ) : ARRIENDO_FACIL_VERSION
		);
		wp_enqueue_script(
			'af-global-search',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-global-search.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : ARRIENDO_FACIL_VERSION,
			true
		);
		wp_localize_script(
			'af-global-search',
			'afGlobalSearch',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'minLength' => self::MIN_LENGTH,
				'i18n'      => array(
					'searching' => __( 'Buscando…', 'arriendo-facil' ),
					'noResults' => __( 'No encontramos resultados.', 'arriendo-facil' ),
					'hint'      => __( 'Escribe al menos 2 letras.', 'arriendo-facil' ),
					'seeAll'    => __( 'Ver todos los resultados', 'arriendo-facil' ),
					'seeMore'   => __( 'Ver más', 'arriendo-facil' ),
					'error'     => __( 'No se pudo buscar. Inténtalo de nuevo.', 'arriendo-facil' ),
				),
			)
		);
	}
}
