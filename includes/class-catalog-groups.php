<?php
/**
 * Catalog grouping: "edificios y conjuntos" used only to organise the
 * properties that appear in the shareable public catalog.
 *
 * Deliberately minimal: a group is nothing more than a *name* owned by a
 * property administrator. No address, no units, no HOA data -- the billing
 * side of the product already has its own (separate) buildings structure.
 * Properties opt in or out of the public catalog individually, and may either
 * belong to one group or be left independent.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Catalog_Groups
 */
class Arriendo_Facil_Catalog_Groups {

	/**
	 * Post meta holding the group a property belongs to (0 = independent).
	 */
	const META_GROUP = '_af_catalog_group_id';

	/**
	 * Post meta flag: whether the property is exposed in the public catalog.
	 */
	const META_INCLUDED = '_af_in_public_catalog';

	/**
	 * Wire AJAX endpoints for group + inclusion management.
	 */
	public function __construct() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_table' ) );

		add_action( 'wp_ajax_af_catalog_group_save', array( $this, 'ajax_save_group' ) );
		add_action( 'wp_ajax_af_catalog_group_delete', array( $this, 'ajax_delete_group' ) );
		add_action( 'wp_ajax_af_catalog_assign_group', array( $this, 'ajax_assign_property' ) );
		add_action( 'wp_ajax_af_catalog_toggle_include', array( $this, 'ajax_toggle_include' ) );
		add_action( 'wp_ajax_af_catalog_bulk_action', array( $this, 'ajax_bulk_action' ) );
	}

	/* ---------------------------------------------------------------------
	 * Table + access helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Creates the groups table on demand for installations upgraded from a
	 * version that predates it.
	 *
	 * The global schema upgrade is gated behind manage_options, but a property
	 * administrator must still be able to use the catalog screen, so the table
	 * is created here for anyone who can manage properties. A versioned option
	 * keeps this to a single extra query per install.
	 */
	public static function maybe_create_table() {
		if ( ! is_admin() ) {
			return;
		}

		if ( get_option( 'af_catalog_groups_schema' ) === '1' ) {
			return;
		}

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::create_table();

		update_option( 'af_catalog_groups_schema', '1', false );
	}

	/**
	 * Creates the groups table if it does not already exist.
	 */
	public static function create_table() {
		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name        VARCHAR(190) NOT NULL,
				owner_id    BIGINT(20) UNSIGNED NOT NULL,
				status      VARCHAR(20) NOT NULL DEFAULT 'active',
				sort_order  INT(11) NOT NULL DEFAULT 0,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_owner_name (owner_id, name(150)),
				KEY owner_id (owner_id),
				KEY status (status)
			) {$collate}"
		);
	}

	/**
	 * @return string Fully qualified groups table name.
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'af_catalog_groups';
	}

	/**
	 * Whether the given user may read/write a group owned by $owner_id.
	 *
	 * @param int $group_id Group row ID.
	 * @param int $user_id  Acting user. Defaults to current user.
	 * @return bool
	 */
	public static function can_access_group( $group_id, $user_id = 0 ) {
		$group = self::get( $group_id );

		return $group && self::user_can_manage_owner( $group->owner_id, $user_id );
	}

	/**
	 * Whether the acting user manages the properties of $owner_id.
	 *
	 * Super admins manage everyone; everyone else only their own scope.
	 *
	 * @param int $owner_id Property owner (tenant) user ID.
	 * @param int $user_id  Acting user. Defaults to current user.
	 * @return bool
	 */
	public static function user_can_manage_owner( $owner_id, $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$owner_id = absint( $owner_id );

		if ( ! $user_id || ! $owner_id ) {
			return false;
		}

		if ( class_exists( 'Arriendo_Facil_Tenancy' ) && Arriendo_Facil_Tenancy::can_manage_all( $user_id ) ) {
			return true;
		}

		return $owner_id === $user_id;
	}

	/* ---------------------------------------------------------------------
	 * CRUD
	 * ------------------------------------------------------------------- */

	/**
	 * Creates (or returns the existing) group with the given name.
	 *
	 * @param int    $owner_id Owner user ID.
	 * @param string $name     Group name, e.g. "La Regina".
	 * @return int|WP_Error Group ID on success.
	 */
	public static function create( $owner_id, $name ) {
		global $wpdb;

		$owner_id = absint( $owner_id );
		$name     = self::sanitize_name( $name );

		if ( ! $owner_id ) {
			return new WP_Error( 'af_group_owner_required', __( 'No se pudo identificar el propietario.', 'arriendo-facil' ) );
		}

		if ( '' === $name ) {
			return new WP_Error( 'af_group_name_required', __( 'Escribe el nombre del edificio o conjunto.', 'arriendo-facil' ) );
		}

		$existing = self::get_by_name( $owner_id, $name );
		if ( $existing ) {
			return (int) $existing->id;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'name'     => $name,
				'owner_id' => $owner_id,
				'status'   => 'active',
			),
			array( '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_group_insert_failed', __( 'No se pudo crear el grupo.', 'arriendo-facil' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Renames a group.
	 *
	 * @param int    $group_id Group ID.
	 * @param string $name     New name.
	 * @return true|WP_Error
	 */
	public static function rename( $group_id, $name ) {
		global $wpdb;

		$group = self::get( $group_id );
		if ( ! $group ) {
			return new WP_Error( 'af_group_not_found', __( 'El grupo no existe.', 'arriendo-facil' ) );
		}

		$name = self::sanitize_name( $name );
		if ( '' === $name ) {
			return new WP_Error( 'af_group_name_required', __( 'Escribe el nombre del edificio o conjunto.', 'arriendo-facil' ) );
		}

		$clash = self::get_by_name( (int) $group->owner_id, $name );
		if ( $clash && (int) $clash->id !== (int) $group->id ) {
			return new WP_Error( 'af_group_duplicate', __( 'Ya existe un grupo con ese nombre.', 'arriendo-facil' ) );
		}

		$wpdb->update( self::table(), array( 'name' => $name ), array( 'id' => (int) $group->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return true;
	}

	/**
	 * Deletes a group and detaches every property assigned to it.
	 *
	 * @param int $group_id Group ID.
	 * @return bool
	 */
	public static function delete( $group_id ) {
		global $wpdb;

		$group = self::get( $group_id );
		if ( ! $group ) {
			return false;
		}

		$wpdb->delete( self::table(), array( 'id' => (int) $group->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$property_ids = get_posts(
			array(
				'post_type'      => 'accommodation',
				'post_status'    => 'any',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => self::META_GROUP, 'value' => (int) $group->id ),
				),
			)
		);

		foreach ( (array) $property_ids as $property_id ) {
			delete_post_meta( (int) $property_id, self::META_GROUP );
		}

		return true;
	}

	/**
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function sanitize_name( $name ) {
		$name = sanitize_text_field( (string) $name );
		$name = preg_replace( '/\s+/u', ' ', $name );

		return trim( (string) $name );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------- */

	/**
	 * @param int $group_id Group ID.
	 * @return object|null
	 */
	public static function get( $group_id ) {
		global $wpdb;

		$group_id = absint( $group_id );
		if ( ! $group_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $group_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * @param int    $owner_id Owner user ID.
	 * @param string $name     Group name.
	 * @return object|null
	 */
	public static function get_by_name( $owner_id, $name ) {
		global $wpdb;

		$name = self::sanitize_name( $name );
		if ( '' === $name ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE owner_id = %d AND name = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $owner_id ),
				$name
			)
		);
	}

	/**
	 * Lists the groups owned by a user, alphabetical.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return object[]
	 */
	public static function get_for_owner( $owner_id ) {
		global $wpdb;

		$owner_id = absint( $owner_id );
		if ( ! $owner_id ) {
			return array();
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE owner_id = %d AND status = 'active' ORDER BY name ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id
			)
		);
	}

	/**
	 * Returns id => name for an owner's groups, ready for <select> options.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return array<int,string>
	 */
	public static function get_name_map( $owner_id ) {
		$map = array();

		foreach ( self::get_for_owner( $owner_id ) as $group ) {
			$map[ (int) $group->id ] = (string) $group->name;
		}

		return $map;
	}

	/**
	 * Counts how many properties each group holds, for a given property set.
	 *
	 * @param int[] $property_ids Accommodation post IDs.
	 * @return array<int,int> group_id => count.
	 */
	public static function count_map( array $property_ids ) {
		global $wpdb;

		$property_ids = array_values( array_filter( array_map( 'absint', $property_ids ) ) );
		if ( ! $property_ids ) {
			return array();
		}

		$ids_sql = Arriendo_Facil_Tenancy::ids_in_clause( $property_ids );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT pm.meta_value AS group_id, COUNT(DISTINCT pm.post_id) AS total
				 FROM {$wpdb->postmeta} pm
				 WHERE pm.meta_key = %s
				   AND pm.post_id IN ({$ids_sql})
				   AND pm.meta_value <> '0'
				 GROUP BY pm.meta_value",
				self::META_GROUP
			)
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$group_id = absint( $row->group_id );
			if ( $group_id ) {
				$counts[ $group_id ] = absint( $row->total );
			}
		}

		return $counts;
	}

	/* ---------------------------------------------------------------------
	 * Property <-> group linkage
	 * ------------------------------------------------------------------- */

	/**
	 * @param int $property_id Accommodation post ID.
	 * @return int Group ID, 0 when independent.
	 */
	public static function get_group_id_for_property( $property_id ) {
		return absint( get_post_meta( absint( $property_id ), self::META_GROUP, true ) );
	}

	/**
	 * Assigns (or clears, with 0) the group of a property after access checks.
	 *
	 * @param int $property_id Accommodation post ID.
	 * @param int $group_id    Group ID, 0 to detach.
	 * @param int $user_id     Acting user. Defaults to current user.
	 * @return true|WP_Error
	 */
	public static function set_group_for_property( $property_id, $group_id, $user_id = 0 ) {
		$property_id = absint( $property_id );
		$group_id    = absint( $group_id );
		$user_id     = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $property_id || 'accommodation' !== get_post_type( $property_id ) ) {
			return new WP_Error( 'af_property_invalid', __( 'La propiedad no es válida.', 'arriendo-facil' ) );
		}

		if ( ! class_exists( 'Arriendo_Facil_Tenancy' ) || ! Arriendo_Facil_Tenancy::can_access_accommodation( $property_id, $user_id ) ) {
			return new WP_Error( 'af_property_forbidden', __( 'No tienes acceso a esa propiedad.', 'arriendo-facil' ) );
		}

		$property_owner = absint( get_post_meta( $property_id, '_af_owner_id', true ) );

		if ( $group_id ) {
			$group = self::get( $group_id );
			if ( ! $group || ! self::user_can_manage_owner( $group->owner_id, $user_id ) ) {
				return new WP_Error( 'af_group_forbidden', __( 'Ese edificio o conjunto no está disponible.', 'arriendo-facil' ) );
			}

			// A group must never span two owners: a super admin could otherwise
			// attach an administrator's property to somebody else's building
			// and leak that building name into the other catalog.
			if ( $property_owner && (int) $group->owner_id !== $property_owner ) {
				return new WP_Error( 'af_group_owner_mismatch', __( 'Ese edificio pertenece a otro gestor.', 'arriendo-facil' ) );
			}
		}

		if ( $group_id ) {
			update_post_meta( $property_id, self::META_GROUP, $group_id );
		} else {
			delete_post_meta( $property_id, self::META_GROUP );
		}

		return true;
	}

	/**
	 * Whether a property is exposed in the public catalog.
	 *
	 * Defaults to true: a property is visible until the owner opts out.
	 *
	 * @param int $property_id Accommodation post ID.
	 * @return bool
	 */
	public static function is_in_public_catalog( $property_id ) {
		$raw = get_post_meta( absint( $property_id ), self::META_INCLUDED, true );

		// No stored value => included by default.
		if ( '' === $raw || null === $raw ) {
			return true;
		}

		return '0' !== (string) $raw;
	}

	/**
	 * Stores the public-catalog opt-in flag for a property.
	 *
	 * @param int  $property_id Accommodation post ID.
	 * @param bool $included    Whether to expose it.
	 * @param int  $user_id     Acting user. Defaults to current user.
	 * @return true|WP_Error
	 */
	public static function set_in_public_catalog( $property_id, $included, $user_id = 0 ) {
		$property_id = absint( $property_id );
		$user_id     = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $property_id || 'accommodation' !== get_post_type( $property_id ) ) {
			return new WP_Error( 'af_property_invalid', __( 'La propiedad no es válida.', 'arriendo-facil' ) );
		}

		if ( ! class_exists( 'Arriendo_Facil_Tenancy' ) || ! Arriendo_Facil_Tenancy::can_access_accommodation( $property_id, $user_id ) ) {
			return new WP_Error( 'af_property_forbidden', __( 'No tienes acceso a esa propiedad.', 'arriendo-facil' ) );
		}

		update_post_meta( $property_id, self::META_INCLUDED, $included ? '1' : '0' );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * Common guards for every group AJAX endpoint.
	 */
	private function guard() {
		check_ajax_referer( 'af_catalog_share_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}
	}

	/**
	 * Resolves the owner scope for a request: a super admin may pass
	 * ?owner_id= to manage another administrator's catalog; everyone else is
	 * locked to their own scope.
	 *
	 * @return int Owner user ID.
	 */
	private function resolve_owner_id() {
		$current = get_current_user_id();

		if ( ! empty( $_POST['owner_id'] ) ) {
			$requested = absint( wp_unslash( $_POST['owner_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
			if ( $requested === $current ) {
				return $current;
			}
			if ( Arriendo_Facil_Tenancy::can_manage_all() ) {
				return $requested;
			}
		}

		return $current;
	}

	/**
	 * Creates or renames a group.
	 */
	public function ajax_save_group() {
		$this->guard();

		$owner_id = $this->resolve_owner_id();
		$group_id = isset( $_POST['group_id'] ) ? absint( wp_unslash( $_POST['group_id'] ) ) : 0;
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( $group_id ) {
			if ( ! self::can_access_group( $group_id ) ) {
				wp_send_json_error( array( 'message' => __( 'No tienes acceso a ese grupo.', 'arriendo-facil' ) ), 403 );
			}
			$result = self::rename( $group_id, $name );

			// rename() returns true on success, not the ID: keep the requested ID.
			$resolved_id = $group_id;
		} else {
			$result     = self::create( $owner_id, $name );
			$resolved_id = is_wp_error( $result ) ? 0 : (int) $result;
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$group = $resolved_id ? self::get( $resolved_id ) : null;

		if ( ! $group ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar el grupo.', 'arriendo-facil' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Guardado.', 'arriendo-facil' ),
				'group'   => array(
					'id'    => (int) $group->id,
					'name'  => (string) $group->name,
					'count' => 0,
				),
			)
		);
	}

	/**
	 * Deletes a group.
	 */
	public function ajax_delete_group() {
		$this->guard();

		$group_id = isset( $_POST['group_id'] ) ? absint( wp_unslash( $_POST['group_id'] ) ) : 0;

		if ( ! self::can_access_group( $group_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a ese grupo.', 'arriendo-facil' ) ), 403 );
		}

		self::delete( $group_id );

		wp_send_json_success( array( 'message' => __( 'Grupo eliminado.', 'arriendo-facil' ) ) );
	}

	/**
	 * Assigns a group to one property.
	 */
	public function ajax_assign_property() {
		$this->guard();

		$property_id = isset( $_POST['property_id'] ) ? absint( wp_unslash( $_POST['property_id'] ) ) : 0;
		$group_id    = isset( $_POST['group_id'] ) ? absint( wp_unslash( $_POST['group_id'] ) ) : 0;

		$result = self::set_group_for_property( $property_id, $group_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Grupo actualizado.', 'arriendo-facil' ) ) );
	}

	/**
	 * Toggles public-catalog inclusion for one property.
	 */
	public function ajax_toggle_include() {
		$this->guard();

		$property_id = isset( $_POST['property_id'] ) ? absint( wp_unslash( $_POST['property_id'] ) ) : 0;
		$included    = ! empty( $_POST['included'] ) && '0' !== wp_unslash( $_POST['included'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().

		$result = self::set_in_public_catalog( $property_id, $included );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'  => $included ? __( 'Visible en el catálogo público.', 'arriendo-facil' ) : __( 'Oculta del catálogo público.', 'arriendo-facil' ),
				'included' => $included,
			)
		);
	}

	/**
	 * Applies a group assignment / inclusion flag to many properties at once.
	 */
	public function ajax_bulk_action() {
		$this->guard();

		$owner_id  = $this->resolve_owner_id();
		$action    = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$group_id  = isset( $_POST['group_id'] ) ? absint( wp_unslash( $_POST['group_id'] ) ) : 0;
		$included  = isset( $_POST['included'] ) && '0' !== wp_unslash( $_POST['included'] );
		$has_scope = class_exists( 'Arriendo_Facil_Tenancy' );
		$scope_ids = $has_scope ? Arriendo_Facil_Tenancy::accessible_accommodation_ids( $owner_id ) : array();

		// An explicit list must always be re-validated: never trust the browser.
		if ( ! empty( $_POST['property_ids'] ) && is_array( $_POST['property_ids'] ) ) {
			$requested = array_map( 'absint', wp_unslash( $_POST['property_ids'] ) );
		} else {
			$requested = $scope_ids;
		}

		if ( $has_scope ) {
			$requested = array_values( array_intersect( $requested, $scope_ids ) );
		}

		$updated = 0;

		if ( 'assign_group' === $action ) {
			if ( $group_id && ! self::can_access_group( $group_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Ese grupo no está disponible.', 'arriendo-facil' ) ), 403 );
			}
			foreach ( $requested as $property_id ) {
				$result = self::set_group_for_property( $property_id, $group_id, $owner_id );
				if ( ! is_wp_error( $result ) ) {
					$updated++;
				}
			}
		} elseif ( 'set_include' === $action ) {
			foreach ( $requested as $property_id ) {
				$result = self::set_in_public_catalog( $property_id, $included, $owner_id );
				if ( ! is_wp_error( $result ) ) {
					$updated++;
				}
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'Acción no reconocida.', 'arriendo-facil' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of properties updated */
					_n( '%d propiedad actualizada.', '%d propiedades actualizadas.', $updated, 'arriendo-facil' ),
					$updated
				),
				'updated' => $updated,
			)
		);
	}
}
