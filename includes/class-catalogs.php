<?php
/**
 * Named, shareable catalogs: each one is a hand-picked set of properties with
 * its own public link.
 *
 * A catalog is stored as a private custom post (no front-end or edit screen of
 * its own). The owner is the post author. Instead of deleting, catalogs are
 * archived: the link stops working but the catalog stays in the history and
 * can be restored.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Catalogs
 */
class Arriendo_Facil_Catalogs {

	const POST_TYPE = 'af_catalog';

	const META_PROPS    = '_af_cat_props';
	const META_TOKEN    = '_af_cat_token';
	const META_SLUG     = '_af_cat_slug';
	const META_ARCHIVED = '_af_cat_archived';
	const META_USED     = '_af_cat_used';

	const SLUG_MIN = 3;
	const SLUG_MAX = 40;
	const NAME_MAX = 80;

	const PAGE = 'af-catalog';

	/**
	 * Pre-existing single-link storage, kept only to migrate it.
	 */
	const LEGACY_TOKEN_META = '_af_catalog_share_token';
	const LEGACY_SLUG_META  = '_af_catalog_share_slug';
	const MIGRATED_OPTION   = 'af_catalogs_migrated';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 20 );

		add_action( 'admin_post_af_catalog_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_af_catalog_action', array( $this, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Registers the internal catalog post type.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Catálogos', 'arriendo-facil' ),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'author' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------- */

	/**
	 * @param int $catalog_id Catalog post ID.
	 * @return WP_Post|null
	 */
	public static function get( $catalog_id ) {
		$post = get_post( absint( $catalog_id ) );

		return ( $post instanceof WP_Post && self::POST_TYPE === $post->post_type ) ? $post : null;
	}

	/**
	 * @param int $catalog_id Catalog post ID.
	 * @param int $user_id    Acting user (defaults to current).
	 * @return bool
	 */
	public static function user_can_manage( $catalog_id, $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$post    = self::get( $catalog_id );

		if ( ! $post || ! $user_id || ! user_can( $user_id, Arriendo_Facil_Tenancy::CAP ) ) {
			return false;
		}

		return (int) $post->post_author === $user_id || Arriendo_Facil_Tenancy::can_manage_all( $user_id );
	}

	/**
	 * @param int $catalog_id Catalog post ID.
	 * @return int[]
	 */
	public static function property_ids( $catalog_id ) {
		$ids = get_post_meta( absint( $catalog_id ), self::META_PROPS, true );

		return is_array( $ids ) ? array_values( array_unique( array_map( 'absint', $ids ) ) ) : array();
	}

	/**
	 * @param int $catalog_id Catalog post ID.
	 * @return bool
	 */
	public static function is_archived( $catalog_id ) {
		return '1' === (string) get_post_meta( absint( $catalog_id ), self::META_ARCHIVED, true );
	}

	/**
	 * Catalogs of one owner, most recently used first.
	 *
	 * @param int  $owner_id Owner user ID.
	 * @param bool $archived Whether to list the archived ones.
	 * @return WP_Post[]
	 */
	public static function list_for_owner( $owner_id, $archived = false ) {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'author'         => absint( $owner_id ),
				'posts_per_page' => 200,
				'meta_key'       => self::META_USED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_ARCHIVED,
						'value' => $archived ? '1' : '0',
					),
				),
			)
		);
	}

	/**
	 * Public link for a catalog.
	 *
	 * @param int $catalog_id Catalog post ID.
	 * @return array{slug:string,url:string,pdf_url:string}
	 */
	public static function link( $catalog_id ) {
		$slug  = (string) get_post_meta( $catalog_id, self::META_SLUG, true );
		$token = (string) get_post_meta( $catalog_id, self::META_TOKEN, true );
		$url   = home_url( '/' . Arriendo_Facil_Catalog_Share::REWRITE_SLUG . '/' . ( $slug ? $slug : $token ) . '/' );

		return array(
			'slug'    => $slug,
			'url'     => $url,
			'pdf_url' => add_query_arg( Arriendo_Facil_Catalog_Share::PDF_ARG, '1', $url ),
		);
	}

	/**
	 * Resolves the last path segment of a public URL (slug or 64-hex token) to
	 * an active catalog. Archived catalogs never resolve.
	 *
	 * @param string $key Slug or token.
	 * @return WP_Post|null
	 */
	public static function find_by_public_key( $key ) {
		$key      = sanitize_key( (string) $key );
		$is_token = (bool) preg_match( '/^[a-f0-9]{64}$/', $key );

		if ( ! $is_token && ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key ) ) {
			return null;
		}

		$found = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => $is_token ? self::META_TOKEN : self::META_SLUG,
						'value' => $key,
					),
					array(
						'key'   => self::META_ARCHIVED,
						'value' => '0',
					),
				),
			)
		);

		return $found ? $found[0] : null;
	}

	/**
	 * Light summary for the list cards: live counts and up to three photos.
	 *
	 * @param int $catalog_id Catalog post ID.
	 * @return array{total:int,available:int,thumbs:string[]}
	 */
	public static function summary( $catalog_id ) {
		$total     = 0;
		$available = 0;
		$thumbs    = array();

		foreach ( self::property_ids( $catalog_id ) as $property_id ) {
			if ( 'publish' !== get_post_status( $property_id ) ) {
				continue;
			}

			$status = (string) get_post_meta( $property_id, '_af_status', true );
			if ( 'inactive' === $status ) {
				continue;
			}

			$total++;
			if ( '' === $status || 'available' === $status ) {
				$available++;
			}

			if ( count( $thumbs ) < 3 ) {
				$thumb = get_the_post_thumbnail_url( $property_id, 'medium' );
				if ( $thumb ) {
					$thumbs[] = $thumb;
				}
			}
		}

		return array(
			'total'     => $total,
			'available' => $available,
			'thumbs'    => $thumbs,
		);
	}

	/**
	 * Properties the owner can put in a catalog, ready for the picker.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function pickable_properties( $owner_id ) {
		$ids    = array_map( 'absint', Arriendo_Facil_Accommodation::get_owner_accommodation_ids( $owner_id ) );
		$labels = Arriendo_Facil_Catalog_Share::status_labels();
		$types  = Arriendo_Facil_Catalog_Share::type_labels();
		$rows   = array();

		foreach ( $ids as $property_id ) {
			if ( 'publish' !== get_post_status( $property_id ) ) {
				continue;
			}

			$status = (string) get_post_meta( $property_id, '_af_status', true );
			$status = '' === $status ? 'available' : $status;
			if ( 'inactive' === $status ) {
				continue;
			}

			$type    = (string) get_post_meta( $property_id, '_af_property_type', true );
			$address = trim( trim( (string) get_post_meta( $property_id, '_af_address', true ) ) . ( get_post_meta( $property_id, '_af_city', true ) ? ', ' . get_post_meta( $property_id, '_af_city', true ) : '' ) );

			$rows[] = array(
				'id'         => $property_id,
				'title'      => get_the_title( $property_id ),
				'address'    => $address,
				'status'     => $status,
				'status_lbl' => isset( $labels[ $status ] ) ? $labels[ $status ] : $status,
				'type_lbl'   => isset( $types[ $type ] ) ? $types[ $type ] : '',
				'rent'       => (float) get_post_meta( $property_id, '_af_monthly_rent', true ),
				'bedrooms'   => (int) get_post_meta( $property_id, '_af_bedrooms', true ),
				'thumb'      => (string) get_the_post_thumbnail_url( $property_id, 'thumbnail' ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( $a['title'], $b['title'] );
			}
		);

		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------- */

	/**
	 * Keeps only published properties that belong to the owner.
	 *
	 * @param mixed $raw      Submitted IDs.
	 * @param int   $owner_id Owner user ID.
	 * @return int[]
	 */
	public static function sanitize_property_ids( $raw, $owner_id ) {
		$wanted  = array_map( 'absint', (array) $raw );
		$allowed = array_map( 'absint', Arriendo_Facil_Accommodation::get_owner_accommodation_ids( $owner_id ) );

		return array_values( array_intersect( array_unique( $wanted ), $allowed ) );
	}

	/**
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function clean_name( $name ) {
		$name = trim( sanitize_text_field( (string) $name ) );
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::NAME_MAX ) : substr( $name, 0, self::NAME_MAX );

		if ( '' === $name ) {
			$name = sprintf(
				/* translators: %s: current date */
				__( 'Catálogo del %s', 'arriendo-facil' ),
				wp_date( 'j M Y' )
			);
		}

		return $name;
	}

	/**
	 * @return string 64-char hex token.
	 */
	private static function new_token() {
		$token = function_exists( 'random_bytes' ) ? bin2hex( random_bytes( 32 ) ) : '';

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			$token = md5( wp_salt( 'auth' ) . uniqid( '', true ) ) . md5( microtime() . wp_salt( 'secure_auth' ) );
		}

		return $token;
	}

	/**
	 * Creates a catalog with a fresh working link.
	 *
	 * @param int    $owner_id     Owner user ID.
	 * @param string $name         Catalog name.
	 * @param array  $property_ids Selected properties.
	 * @return int|WP_Error New catalog ID.
	 */
	public static function create( $owner_id, $name, array $property_ids ) {
		$owner_id = absint( $owner_id );
		if ( ! $owner_id ) {
			return new WP_Error( 'af_catalog_owner', __( 'Usuario no válido.', 'arriendo-facil' ) );
		}

		$name    = self::clean_name( $name );
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
				'post_author' => $owner_id,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_PROPS, self::sanitize_property_ids( $property_ids, $owner_id ) );
		update_post_meta( $post_id, self::META_TOKEN, self::new_token() );
		update_post_meta( $post_id, self::META_SLUG, self::suggest_slug( $name, $post_id ) );
		update_post_meta( $post_id, self::META_ARCHIVED, '0' );
		self::touch( $post_id );

		return (int) $post_id;
	}

	/**
	 * @param int      $catalog_id   Catalog post ID.
	 * @param string   $name         New name.
	 * @param array    $property_ids Selected properties.
	 * @param string|null $slug      New link name, or null to keep it.
	 * @return true|WP_Error
	 */
	public static function update( $catalog_id, $name, array $property_ids, $slug = null ) {
		$post = self::get( $catalog_id );
		if ( ! $post ) {
			return new WP_Error( 'af_catalog_missing', __( 'El catálogo no existe.', 'arriendo-facil' ) );
		}

		if ( null !== $slug ) {
			$slug = self::normalize_slug( $slug );
			if ( $slug !== (string) get_post_meta( $post->ID, self::META_SLUG, true ) ) {
				$valid = self::validate_slug( $slug, $post->ID );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
				update_post_meta( $post->ID, self::META_SLUG, $slug );
			}
		}

		wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => self::clean_name( $name ),
			)
		);
		update_post_meta( $post->ID, self::META_PROPS, self::sanitize_property_ids( $property_ids, (int) $post->post_author ) );
		self::touch( $post->ID );

		return true;
	}

	/**
	 * Marks the catalog as just used (drives the "most recent first" order).
	 *
	 * @param int $catalog_id Catalog post ID.
	 */
	public static function touch( $catalog_id ) {
		update_post_meta( absint( $catalog_id ), self::META_USED, time() );
	}

	/**
	 * Archiving switches the public link off; restoring switches it back on.
	 *
	 * @param int  $catalog_id Catalog post ID.
	 * @param bool $archived   Target state.
	 */
	public static function set_archived( $catalog_id, $archived ) {
		update_post_meta( absint( $catalog_id ), self::META_ARCHIVED, $archived ? '1' : '0' );
		self::touch( $catalog_id );
	}

	/**
	 * @param int $catalog_id Catalog post ID.
	 * @return int|WP_Error New catalog ID.
	 */
	public static function duplicate( $catalog_id ) {
		$post = self::get( $catalog_id );
		if ( ! $post ) {
			return new WP_Error( 'af_catalog_missing', __( 'El catálogo no existe.', 'arriendo-facil' ) );
		}

		return self::create(
			(int) $post->post_author,
			sprintf(
				/* translators: %s: catalog name */
				__( '%s (copia)', 'arriendo-facil' ),
				$post->post_title
			),
			self::property_ids( $post->ID )
		);
	}

	/**
	 * Permanent removal, only allowed for archived catalogs.
	 *
	 * @param int $catalog_id Catalog post ID.
	 * @return bool
	 */
	public static function delete( $catalog_id ) {
		if ( ! self::get( $catalog_id ) || ! self::is_archived( $catalog_id ) ) {
			return false;
		}

		return (bool) wp_delete_post( $catalog_id, true );
	}

	/* ---------------------------------------------------------------------
	 * Link name (slug)
	 * ------------------------------------------------------------------- */

	/**
	 * @return string[]
	 */
	private static function reserved_slugs() {
		return array( 'admin', 'wp-admin', 'login', 'wp-login', 'api', 'catalogo', 'catalogos', 'arriendo-facil', 'arriendofacil', 'soporte', 'ayuda', 'null', 'www', 'pdf' );
	}

	/**
	 * Lower-cases, strips accents and collapses the input into a URL-safe name.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function normalize_slug( $raw ) {
		$slug = sanitize_title( remove_accents( (string) $raw ) );
		$slug = preg_replace( '/[^a-z0-9-]+/', '', $slug );
		$slug = preg_replace( '/-+/', '-', (string) $slug );

		return trim( (string) $slug, '-' );
	}

	/**
	 * @param string $slug       Normalized slug.
	 * @param int    $catalog_id Catalog allowed to keep it.
	 * @return true|WP_Error
	 */
	public static function validate_slug( $slug, $catalog_id = 0 ) {
		$len = strlen( $slug );

		if ( $len < self::SLUG_MIN || $len > self::SLUG_MAX ) {
			return new WP_Error( 'length', sprintf( /* translators: 1: min, 2: max */ __( 'El nombre del enlace debe tener entre %1$d y %2$d caracteres.', 'arriendo-facil' ), self::SLUG_MIN, self::SLUG_MAX ) );
		}
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			return new WP_Error( 'format', __( 'El nombre del enlace solo admite letras, números y guiones.', 'arriendo-facil' ) );
		}
		if ( in_array( $slug, self::reserved_slugs(), true ) ) {
			return new WP_Error( 'reserved', __( 'Ese nombre de enlace está reservado por el sistema.', 'arriendo-facil' ) );
		}

		$taken = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post__not_in'   => array( absint( $catalog_id ) ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_SLUG,
						'value' => $slug,
					),
				),
			)
		);

		if ( $taken ) {
			return new WP_Error( 'taken', __( 'Ese nombre de enlace ya lo usa otro catálogo.', 'arriendo-facil' ) );
		}

		return true;
	}

	/**
	 * First free link name derived from a catalog name.
	 *
	 * @param string $name       Catalog name.
	 * @param int    $catalog_id Catalog that will own it.
	 * @return string
	 */
	public static function suggest_slug( $name, $catalog_id = 0 ) {
		$base = trim( substr( self::normalize_slug( $name ), 0, self::SLUG_MAX - 6 ), '-' );
		if ( strlen( $base ) < self::SLUG_MIN ) {
			$base = 'catalogo-inmuebles';
		}

		if ( true === self::validate_slug( $base, $catalog_id ) ) {
			return $base;
		}

		for ( $i = 2; $i < 1000; $i++ ) {
			$candidate = $base . '-' . $i;
			if ( true === self::validate_slug( $candidate, $catalog_id ) ) {
				return $candidate;
			}
		}

		return $base . '-' . wp_rand( 1000, 9999 );
	}

	/* ---------------------------------------------------------------------
	 * Migration of the former single link
	 * ------------------------------------------------------------------- */

	/**
	 * Turns every pre-existing personal link into a first catalog, keeping its
	 * address so links already sent keep working.
	 */
	public static function maybe_migrate() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		$user_ids = get_users(
			array(
				'meta_key'     => self::LEGACY_TOKEN_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => -1,
			)
		);

		foreach ( (array) $user_ids as $user_id ) {
			self::migrate_user( (int) $user_id );
		}

		update_option( self::MIGRATED_OPTION, '1' );
	}

	/**
	 * @param int $user_id Owner user ID.
	 */
	private static function migrate_user( $user_id ) {
		$token = (string) get_user_meta( $user_id, self::LEGACY_TOKEN_META, true );
		if ( '' === $token ) {
			return;
		}

		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_TOKEN,
						'value' => $token,
					),
				),
			)
		);
		if ( $existing ) {
			return;
		}

		// Everything the old link used to show: published, not opted out, not inactive.
		$selected = array();
		foreach ( Arriendo_Facil_Accommodation::get_owner_accommodation_ids( $user_id ) as $property_id ) {
			if ( 'publish' !== get_post_status( $property_id ) ) {
				continue;
			}
			if ( '0' === (string) get_post_meta( $property_id, '_af_in_public_catalog', true ) ) {
				continue;
			}
			if ( 'inactive' === (string) get_post_meta( $property_id, '_af_status', true ) ) {
				continue;
			}
			$selected[] = (int) $property_id;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => __( 'Mi catálogo', 'arriendo-facil' ),
				'post_author' => $user_id,
			)
		);

		if ( ! $post_id || is_wp_error( $post_id ) ) {
			return;
		}

		$slug = (string) get_user_meta( $user_id, self::LEGACY_SLUG_META, true );
		if ( '' === $slug || true !== self::validate_slug( $slug, $post_id ) ) {
			$slug = self::suggest_slug( 'mi-catalogo', $post_id );
		}

		update_post_meta( $post_id, self::META_PROPS, $selected );
		update_post_meta( $post_id, self::META_TOKEN, $token );
		update_post_meta( $post_id, self::META_SLUG, $slug );
		update_post_meta( $post_id, self::META_ARCHIVED, '0' );
		self::touch( $post_id );
	}

	/* ---------------------------------------------------------------------
	 * Admin handlers
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $args Query args for the catalog page.
	 * @return string
	 */
	public static function admin_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Aborts the request unless the current user may manage properties.
	 */
	private static function guard() {
		if ( ! is_user_logged_in() || ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Creates or updates a catalog from the editor form.
	 */
	public function handle_save() {
		self::guard();
		check_admin_referer( 'af_catalog_save' );

		$catalog_id = isset( $_POST['catalog_id'] ) ? absint( wp_unslash( $_POST['catalog_id'] ) ) : 0;
		$name       = isset( $_POST['catalog_name'] ) ? sanitize_text_field( wp_unslash( $_POST['catalog_name'] ) ) : '';
		$ids        = isset( $_POST['property_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['property_ids'] ) ) : array();
		$slug       = isset( $_POST['catalog_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['catalog_slug'] ) ) : null;

		if ( ! $catalog_id ) {
			$new_id = self::create( get_current_user_id(), $name, $ids );

			if ( is_wp_error( $new_id ) ) {
				wp_safe_redirect( self::admin_url( array( 'af_msg' => 'error' ) ) );
				exit;
			}

			wp_safe_redirect( self::admin_url( array( 'catalog' => $new_id, 'af_msg' => 'created' ) ) );
			exit;
		}

		if ( ! self::user_can_manage( $catalog_id ) ) {
			wp_die( esc_html__( 'No tienes permiso para editar este catálogo.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}

		$result = self::update( $catalog_id, $name, $ids, $slug );

		if ( is_wp_error( $result ) ) {
			// Everything but the link name was already rejected as a whole, so
			// save the rest and report only the name problem.
			self::update( $catalog_id, $name, $ids );
			wp_safe_redirect( self::admin_url( array( 'catalog' => $catalog_id, 'af_msg' => 'slug_' . $result->get_error_code() ) ) );
			exit;
		}

		wp_safe_redirect( self::admin_url( array( 'catalog' => $catalog_id, 'af_msg' => 'saved' ) ) );
		exit;
	}

	/**
	 * Duplicate / archive / restore / delete from the list.
	 */
	public function handle_action() {
		self::guard();

		$catalog_id = isset( $_POST['catalog_id'] ) ? absint( wp_unslash( $_POST['catalog_id'] ) ) : 0;
		$action     = isset( $_POST['catalog_action'] ) ? sanitize_key( wp_unslash( $_POST['catalog_action'] ) ) : '';

		check_admin_referer( 'af_catalog_action_' . $catalog_id );

		if ( ! self::user_can_manage( $catalog_id ) ) {
			wp_die( esc_html__( 'No tienes permiso para editar este catálogo.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}

		$msg = 'error';

		switch ( $action ) {
			case 'duplicate':
				$new_id = self::duplicate( $catalog_id );
				if ( ! is_wp_error( $new_id ) ) {
					wp_safe_redirect( self::admin_url( array( 'catalog' => $new_id, 'af_msg' => 'duplicated' ) ) );
					exit;
				}
				break;
			case 'archive':
				self::set_archived( $catalog_id, true );
				$msg = 'archived';
				break;
			case 'restore':
				self::set_archived( $catalog_id, false );
				$msg = 'restored';
				break;
			case 'delete':
				$msg = self::delete( $catalog_id ) ? 'deleted' : 'error';
				break;
		}

		wp_safe_redirect( self::admin_url( array( 'af_msg' => $msg ) ) );
		exit;
	}

	/**
	 * Assets for the two catalog screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'arriendo-facil_page_' . self::PAGE !== $hook ) {
			return;
		}

		$css = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-catalogs.css';
		$js  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-catalogs.js';

		if ( file_exists( $css ) ) {
			wp_enqueue_style( 'af-catalogs', ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-catalogs.css', array( 'af-tokens', 'af-shell', 'af-admin-chrome' ), filemtime( $css ) );
		}

		if ( file_exists( $js ) ) {
			wp_enqueue_script( 'af-catalogs', ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-catalogs.js', array(), filemtime( $js ), true );
			wp_localize_script(
				'af-catalogs',
				'afCatalogs',
				array(
					'copied'    => __( '¡Enlace copiado!', 'arriendo-facil' ),
					'copy'      => __( 'Copiar enlace', 'arriendo-facil' ),
					'selected'  => __( 'seleccionada', 'arriendo-facil' ),
					'selectedP' => __( 'seleccionadas', 'arriendo-facil' ),
					'none'      => __( 'Ninguna propiedad coincide con la búsqueda.', 'arriendo-facil' ),
					'confirm'   => __( '¿Eliminar este catálogo para siempre? Esta acción no se puede deshacer.', 'arriendo-facil' ),
				)
			);
		}
	}
}
