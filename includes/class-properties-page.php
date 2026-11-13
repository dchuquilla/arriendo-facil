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
	const NONCE     = 'af_properties';
	const PURGE_HOOK = 'af_purge_trashed_properties';

	/**
	 * Hooks the hidden page, the list redirect and the assets.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 11 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_list' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_filter( 'parent_file', array( $this, 'keep_parent_menu_open' ) );
		add_filter( 'submenu_file', array( $this, 'keep_submenu_selected' ) );

		add_action( 'wp_ajax_af_property_trash', array( $this, 'ajax_trash' ) );
		add_action( 'wp_ajax_af_property_restore', array( $this, 'ajax_restore' ) );
		add_action( 'wp_ajax_af_property_delete', array( $this, 'ajax_delete' ) );

		add_action( self::PURGE_HOOK, array( $this, 'purge_expired_trash' ) );
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Days a property stays in the trash before it is deleted for good.
	 *
	 * @return int
	 */
	public static function retention_days() {
		return max( 1, (int) apply_filters( 'af_property_trash_days', 30 ) );
	}

	/**
	 * Timestamp at which a trashed property is deleted automatically.
	 *
	 * @param int $post_id Property ID.
	 * @return int
	 */
	public static function purge_timestamp( $post_id ) {
		$trashed_at = (int) get_post_meta( $post_id, '_wp_trash_meta_time', true );

		return ( $trashed_at ? $trashed_at : time() ) + self::retention_days() * DAY_IN_SECONDS;
	}

	/**
	 * Daily job: permanently deletes properties trashed longer than the retention window.
	 */
	public function purge_expired_trash() {
		$ids = get_posts(
			array(
				'post_type'      => 'accommodation',
				'post_status'    => 'trash',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wp_trash_meta_time',
						'value'   => time() - self::retention_days() * DAY_IN_SECONDS,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	/**
	 * Validates the request and returns the property ID, or ends with a JSON error.
	 *
	 * @param string $expected_status Required post status ('trash' or 'not-trash').
	 * @return int
	 */
	private function authorize_request( $expected_status ) {
		check_ajax_referer( self::NONCE, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || 'accommodation' !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'No encontramos el inmueble.', 'arriendo-facil' ) ), 404 );
		}

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) || ! Arriendo_Facil_Tenancy::owns_accommodation( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para esta acción.', 'arriendo-facil' ) ), 403 );
		}

		$in_trash = 'trash' === $post->post_status;
		if ( ( 'trash' === $expected_status ) !== $in_trash ) {
			wp_send_json_error( array( 'message' => __( 'El inmueble ya cambió de estado. Recarga la página.', 'arriendo-facil' ) ), 409 );
		}

		return $post_id;
	}

	/**
	 * Response payload shared by the three actions.
	 *
	 * @param string $message Success message.
	 * @param int    $post_id Affected property.
	 */
	private function respond( $message, $post_id ) {
		wp_send_json_success(
			array(
				'message' => $message,
				'id'      => $post_id,
				'counts'  => $this->counts( Arriendo_Facil_Tenancy::managed_accommodation_ids() ),
			)
		);
	}

	/**
	 * Moves a property to the trash (recoverable).
	 */
	public function ajax_trash() {
		$post_id = $this->authorize_request( 'not-trash' );

		if ( ! wp_trash_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo mover el inmueble a la papelera.', 'arriendo-facil' ) ), 500 );
		}

		$this->respond(
			sprintf(
				/* translators: %d: days in the trash */
				__( 'Movido a la papelera. Puedes restaurarlo durante %d días.', 'arriendo-facil' ),
				self::retention_days()
			),
			$post_id
		);
	}

	/**
	 * Restores a trashed property with the status it had before.
	 */
	public function ajax_restore() {
		$post_id = $this->authorize_request( 'trash' );

		$previous = (string) get_post_meta( $post_id, '_wp_trash_meta_status', true );
		$restore  = static function ( $new_status ) use ( $previous ) {
			return in_array( $previous, array( 'publish', 'private', 'draft', 'pending' ), true ) ? $previous : $new_status;
		};

		add_filter( 'wp_untrash_post_status', $restore );
		$result = wp_untrash_post( $post_id );
		remove_filter( 'wp_untrash_post_status', $restore );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo restaurar el inmueble.', 'arriendo-facil' ) ), 500 );
		}

		$this->respond( __( 'Inmueble restaurado.', 'arriendo-facil' ), $post_id );
	}

	/**
	 * Deletes a trashed property for good.
	 */
	public function ajax_delete() {
		$post_id = $this->authorize_request( 'trash' );

		if ( ! wp_delete_post( $post_id, true ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo eliminar el inmueble.', 'arriendo-facil' ) ), 500 );
		}

		$this->respond( __( 'Inmueble eliminado definitivamente.', 'arriendo-facil' ), $post_id );
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

		$messages = array(
			'trashed'   => __( 'El inmueble se movió a la papelera.', 'arriendo-facil' ),
			'untrashed' => __( 'El inmueble se restauró.', 'arriendo-facil' ),
		);
		$notice   = isset( $_GET['af_msg'] ) ? sanitize_key( wp_unslash( $_GET['af_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'af-properties',
			'afProperties',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'notice'  => isset( $messages[ $notice ] ) ? $messages[ $notice ] : '',
				'i18n'    => array(
					'trashTitle'    => __( '¿Eliminar este inmueble?', 'arriendo-facil' ),
					/* translators: %d: days in the trash */
					'trashText'     => sprintf( __( 'Se moverá a la papelera y se desvincularán su inquilino y contrato, si los tiene. Podrás restaurarlo con todo lo anterior durante %d días; después se eliminará definitivamente.', 'arriendo-facil' ), self::retention_days() ),
					'trashConfirm'  => __( 'Mover a la papelera', 'arriendo-facil' ),
					'deleteTitle'   => __( '¿Eliminar definitivamente?', 'arriendo-facil' ),
					'deleteText'    => __( 'Esta acción no se puede deshacer.', 'arriendo-facil' ),
					'deleteConfirm' => __( 'Eliminar para siempre', 'arriendo-facil' ),
					'working'       => __( 'Procesando…', 'arriendo-facil' ),
					'networkError'  => __( 'No se pudo completar la acción. Revisa tu conexión e inténtalo de nuevo.', 'arriendo-facil' ),
					'close'         => __( 'Cerrar', 'arriendo-facil' ),
					'emptyTitle'    => __( 'No hay inmuebles en esta vista', 'arriendo-facil' ),
				),
			)
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
			'locked'      => 0,
			'trash'       => 0,
		);

		$scope_sql = '';
		if ( null !== $scope ) {
			$scope_sql = ' AND p.ID IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope ) . ')';
		}

		$rows = (array) $wpdb->get_results(
			"SELECT p.post_status AS post_status, COALESCE(pm.meta_value, 'available') AS af_status, (lk.meta_id IS NOT NULL) AS is_locked, COUNT(*) AS total
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_af_status'
			 LEFT JOIN {$wpdb->postmeta} lk ON lk.post_id = p.ID AND lk.meta_key = '_af_plan_locked'
			 WHERE p.post_type = 'accommodation' AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'trash'){$scope_sql}
			 GROUP BY p.post_status, af_status, is_locked" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids are cast with absint().
		);

		foreach ( $rows as $row ) {
			$total = (int) $row->total;

			if ( 'trash' === $row->post_status ) {
				$counts['trash'] += $total;
				continue;
			}

			$counts['all'] += $total;

			if ( (int) $row->is_locked ) {
				$counts['locked'] += $total;
				continue;
			}

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

		$scope  = Arriendo_Facil_Tenancy::managed_accommodation_ids();
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

		if ( 'locked' === $filter ) {
			$args['post_status'] = array( 'publish', 'private', 'draft', 'pending' );
			$args['meta_query']  = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_af_plan_locked',
					'compare' => 'EXISTS',
				),
			);
		} elseif ( in_array( $filter, array( 'available', 'rented', 'maintenance', 'draft' ), true ) ) {
			$lock_clause         = array(
				'key'     => '_af_plan_locked',
				'compare' => 'NOT EXISTS',
			);
			$args['meta_query'] = isset( $args['meta_query'] ) ? array( 'relation' => 'AND', $args['meta_query'], $lock_clause ) : array( $lock_clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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
