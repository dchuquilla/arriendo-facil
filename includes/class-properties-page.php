<?php
/**
 * "Inmuebles": card grid that replaces the native accommodation list table.
 *
 * The WP list screen (edit.php?post_type=accommodation) is redirected here so
 * every entry point (menu, wizard, dashboard, notices) lands on one calm,
 * catalog-style screen. Super admins can still reach the classic table with
 * af_classic_editor=1.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Properties_Page {

	const PAGE_SLUG = 'af-properties';
	const PER_PAGE  = 12;

	/**
	 * Hooks the hidden page, the list redirect and the assets.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 11 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_list' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_filter( 'parent_file', array( $this, 'keep_parent_menu_open' ) );
		add_filter( 'submenu_file', array( $this, 'keep_submenu_selected' ) );
	}

	/**
	 * URL of the screen.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
	}

	/**
	 * Registers the hidden page.
	 */
	public function register_page() {
		add_submenu_page(
			'',
			__( 'Inmuebles', 'arriendo-facil' ),
			__( 'Inmuebles', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Sends the native list (and its trash/untrash notices) to the card screen.
	 */
	public function maybe_redirect_list() {
		global $pagenow;

		if ( 'edit.php' !== $pagenow || wp_doing_ajax() || 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( ! isset( $_GET['post_type'] ) || 'accommodation' !== sanitize_key( wp_unslash( $_GET['post_type'] ) ) ) {
			return;
		}
		if ( ! empty( $_GET['af_classic_editor'] ) && current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			return;
		}

		$args = array();
		if ( ! empty( $_GET['s'] ) ) {
			$args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}
		if ( ! empty( $_GET['trashed'] ) ) {
			$args['af_msg'] = 'trashed';
		} elseif ( ! empty( $_GET['untrashed'] ) ) {
			$args['af_msg'] = 'untrashed';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Keeps the plugin menu open on this screen.
	 *
	 * @param string $parent_file Parent slug.
	 * @return string
	 */
	public function keep_parent_menu_open( $parent_file ) {
		return $this->is_this_page() ? 'arriendo-facil' : $parent_file;
	}

	/**
	 * Keeps a stable submenu highlight.
	 *
	 * @param string $submenu_file Submenu slug.
	 * @return string
	 */
	public function keep_submenu_selected( $submenu_file ) {
		return $this->is_this_page() ? 'arriendo-facil' : $submenu_file;
	}

	/**
	 * Whether the current request is this screen.
	 *
	 * @return bool
	 */
	private function is_this_page() {
		return isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Enqueues the screen assets (plus the building-group tools it hosts).
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'admin_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$css = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-properties.css';
		$js  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-properties.js';

		wp_enqueue_style(
			'af-properties',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-properties.css',
			array( 'af-tokens', 'af-shell', 'af-forms', 'af-admin-chrome' ),
			file_exists( $css ) ? (string) filemtime( $css ) : ARRIENDO_FACIL_VERSION
		);
		wp_enqueue_script(
			'af-properties',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-properties.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : ARRIENDO_FACIL_VERSION,
			true
		);
	}

	/**
	 * Status counts for the filter tabs, scoped to the operator's portfolio.
	 *
	 * @param int[]|null $scope Accessible accommodation IDs (null = all).
	 * @return array<string,int> Keys: all, available, rented, maintenance, draft, trash.
	 */
	private function counts( $scope ) {
		global $wpdb;

		$counts = array(
			'all'         => 0,
			'available'   => 0,
			'rented'      => 0,
			'maintenance' => 0,
			'draft'       => 0,
			'trash'       => 0,
		);

		$scope_sql = '';
		if ( null !== $scope ) {
			$scope_sql = ' AND p.ID IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope ) . ')';
		}

		$rows = (array) $wpdb->get_results(
			"SELECT p.post_status AS post_status, COALESCE(pm.meta_value, 'available') AS af_status, COUNT(*) AS total
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_af_status'
			 WHERE p.post_type = 'accommodation' AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'trash'){$scope_sql}
			 GROUP BY p.post_status, af_status" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids are cast with absint().
		);

		foreach ( $rows as $row ) {
			$total = (int) $row->total;

			if ( 'trash' === $row->post_status ) {
				$counts['trash'] += $total;
				continue;
			}

			$counts['all'] += $total;

			if ( in_array( $row->post_status, array( 'draft', 'pending' ), true ) ) {
				$counts['draft'] += $total;
			} elseif ( in_array( $row->af_status, array( 'rented', 'occupied' ), true ) ) {
				$counts['rented'] += $total;
			} elseif ( 'maintenance' === $row->af_status ) {
				$counts['maintenance'] += $total;
			} else {
				$counts['available'] += $total;
			}
		}

		return $counts;
	}

	/**
	 * Renders the screen.
	 */
	public function render() {
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para ver esta página.', 'arriendo-facil' ) );
		}

		$scope  = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
		$counts = $this->counts( $scope );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$filter = isset( $_GET['estado'] ) ? sanitize_key( wp_unslash( $_GET['estado'] ) ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$order  = isset( $_GET['orden'] ) ? sanitize_key( wp_unslash( $_GET['orden'] ) ) : 'recent';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$notice = isset( $_GET['af_msg'] ) ? sanitize_key( wp_unslash( $_GET['af_msg'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! array_key_exists( $filter, $counts ) ) {
			$filter = 'all';
		}
		if ( ! in_array( $order, array( 'recent', 'name', 'rent_desc', 'rent_asc' ), true ) ) {
			$order = 'recent';
		}

		$args = array(
			'post_type'      => 'accommodation',
			'post_status'    => 'trash' === $filter ? 'trash' : ( 'draft' === $filter ? array( 'draft', 'pending' ) : array( 'publish', 'private', 'draft', 'pending' ) ),
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			's'              => $search,
		);

		if ( null !== $scope ) {
			$args['post__in'] = ! empty( $scope ) ? array_map( 'absint', $scope ) : array( 0 );
		}

		if ( 'available' === $filter ) {
			$args['post_status'] = array( 'publish', 'private' );
			$args['meta_query']  = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'   => '_af_status',
					'value' => 'available',
				),
				array(
					'key'     => '_af_status',
					'compare' => 'NOT EXISTS',
				),
			);
		} elseif ( in_array( $filter, array( 'rented', 'maintenance' ), true ) ) {
			$args['post_status'] = array( 'publish', 'private' );
			$args['meta_query']  = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_af_status',
					'value'   => 'rented' === $filter ? array( 'rented', 'occupied' ) : array( 'maintenance' ),
					'compare' => 'IN',
				),
			);
		}

		if ( 'name' === $order ) {
			$args['orderby'] = 'title';
			$args['order']   = 'ASC';
		} elseif ( in_array( $order, array( 'rent_desc', 'rent_asc' ), true ) ) {
			$args['meta_key'] = '_af_monthly_rent'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = 'meta_value_num';
			$args['order']    = 'rent_asc' === $order ? 'ASC' : 'DESC';
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}

		$query = new WP_Query( $args );
		$posts = $query->posts;
		$ids   = array_map( 'absint', wp_list_pluck( $posts, 'ID' ) );

		// One query for who lives in each property of this page.
		$tenants = array();
		if ( ! empty( $ids ) ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows         = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.accommodation_id, TRIM(CONCAT(g.first_name, ' ', g.last_name)) AS guest_name
					 FROM {$wpdb->prefix}af_leases l
					 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
					 WHERE l.status = 'active' AND l.deleted_at IS NULL AND l.accommodation_id IN ({$placeholders})
					 ORDER BY l.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a count.
					$ids
				)
			);
			foreach ( $rows as $row ) {
				$tenants[ (int) $row->accommodation_id ] = (string) $row->guest_name;
			}
			update_meta_cache( 'post', $ids );
		}

		$view = array(
			'posts'    => $posts,
			'query'    => $query,
			'counts'   => $counts,
			'filter'   => $filter,
			'search'   => $search,
			'order'    => $order,
			'paged'    => $paged,
			'notice'   => $notice,
			'tenants'  => $tenants,
			'owner_id' => Arriendo_Facil_Accommodation::user_is_owner() ? get_current_user_id() : 0,
		);

		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/properties.php';
	}
}
