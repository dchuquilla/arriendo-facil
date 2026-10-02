<?php
/**
 * Shareable public catalog: tokenized URL, building grouping and PDF export.
 *
 * Every property administrator can generate a public link
 * (https://site.tld/catalogo/<token>) that renders a clean, grouped grid of
 * their own accommodations for sharing with prospective tenants. The link can
 * be rotated or revoked at any time, and the exact same dataset powers the
 * downloadable PDF so both can never drift apart.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Catalog_Share
 */
class Arriendo_Facil_Catalog_Share {

	const META_KEY     = '_af_catalog_share_token';
	const META_SLUG    = '_af_catalog_share_slug';
	const SLUG_MIN     = 3;
	const SLUG_MAX     = 40;
	const QUERY_VAR    = 'af_catalog_share';
	const REWRITE_SLUG = 'catalogo';

	/**
	 * Query argument that switches the shared URL into a PDF download.
	 */
	const PDF_ARG = 'pdf';

	/**
	 * Hooks into rewrites, AJAX and asset loading.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_routes' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'template_include', array( $this, 'load_public_template' ) );
		add_action( 'template_redirect', array( $this, 'maybe_stream_pdf' ), 5 );
		add_action( 'admin_init', array( $this, 'maybe_flush_rules' ) );

		add_action( 'wp_ajax_af_catalog_share_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_af_catalog_share_revoke', array( $this, 'ajax_revoke' ) );
		add_action( 'wp_ajax_af_catalog_share_slug', array( $this, 'ajax_slug' ) );
		add_action( 'wp_ajax_af_catalog_share_stats', array( $this, 'ajax_stats' ) );

		add_shortcode( 'af_catalog_share', array( $this, 'render_shortcode' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
	}

	/* ---------------------------------------------------------------------
	 * Routing
	 * ------------------------------------------------------------------- */

	/**
	 * Rewrite route: /catalogo/<slug or 64-hex token>/ -> index.php?af_catalog_share=<value>
	 */
	public function register_routes() {
		add_rewrite_rule(
			'^' . self::REWRITE_SLUG . '/([a-z0-9][a-z0-9-]{1,62}[a-z0-9])/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Flushes rewrite rules once so the tokenized catalog route works
	 * on installations upgraded after this feature landed.
	 */
	public function maybe_flush_rules() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'af_catalog_share_rules_flushed_v2' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'af_catalog_share_rules_flushed_v2', 1, false );
	}

	/**
	 * Loads the public catalog template when the query var is present.
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function load_public_template( $template ) {
		$token = sanitize_key( (string) get_query_var( self::QUERY_VAR ) );
		if ( '' === $token ) {
			return $template;
		}

		$user = $this->user_for_token( $token );
		if ( ! $user ) {
			$GLOBALS['af_catalog_share_user']        = null;
			$GLOBALS['af_catalog_share_not_found']   = true;
			$GLOBALS['af_catalog_share_token']       = '';

			return $this->public_template_path();
		}

		$GLOBALS['af_catalog_share_user']      = $user;
		$GLOBALS['af_catalog_share_not_found'] = false;
		$GLOBALS['af_catalog_share_token']     = $token;

		return $this->public_template_path();
	}

	/**
	 * @return string Absolute path to the public template.
	 */
	private function public_template_path() {
		return ARRIENDO_FACIL_PLUGIN_DIR . 'public/catalog-share.php';
	}

	/**
	 * Streams a real, paginated PDF when the shared URL is requested with ?pdf=1.
	 *
	 * Runs on template_redirect so the document is produced before WordPress
	 * starts rendering any theme output.
	 */
	public function maybe_stream_pdf() {
		$token = sanitize_key( (string) get_query_var( self::QUERY_VAR ) );

		if ( '' === $token ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public download.
		if ( ! isset( $_GET[ self::PDF_ARG ] ) || '1' !== sanitize_text_field( wp_unslash( $_GET[ self::PDF_ARG ] ) ) ) {
			return;
		}

		$user = $this->user_for_token( $token );

		if ( ! $user ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'Este catálogo ya no está disponible.', 'arriendo-facil' );
			exit;
		}

		$catalog = self::get_catalog( $user );
		$pdf     = Arriendo_Facil_Catalog_Pdf::build( $catalog );

		if ( is_wp_error( $pdf ) ) {
			status_header( 500 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $pdf->get_error_message() );
			exit;
		}

		$slug = sanitize_title( $catalog['company'] );
		$slug = $slug ? $slug : 'catalogo';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $slug . '-catalogo.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );

		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw PDF bytes.
		exit;
	}

	/**
	 * Shortcode fallback: renders the shared catalog for the current user's token.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		if ( ! is_user_logged_in() || ! in_array( 'af_property_admin', (array) wp_get_current_user()->roles, true ) ) {
			return '<p>' . esc_html__( 'Catálogo no disponible.', 'arriendo-facil' ) . '</p>';
		}

		$GLOBALS['af_catalog_share_user']      = wp_get_current_user();
		$GLOBALS['af_catalog_share_not_found'] = false;
		$GLOBALS['af_catalog_share_token']     = self::token_for_user( get_current_user_id() );

		ob_start();
		require ARRIENDO_FACIL_PLUGIN_DIR . 'public/catalog-share.php';

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Token helpers
	 * ------------------------------------------------------------------- */

	/**
	 * @param int|null $user_id User ID or current user.
	 * @return string Token value or empty string.
	 */
	public static function token_for_user( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		return (string) get_user_meta( $user_id, self::META_KEY, true );
	}

	/**
	 * Creates (or rotates) the share token. Returns raw token + public URL.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $rotate  Force a new token.
	 * @return array{token:string,url:string,pdf_url:string}|null
	 */
	public static function generate_token( $user_id, $rotate = false ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return null;
		}

		$token = self::token_for_user( $user_id );
		if ( '' === $token || $rotate ) {
			$token = '';
			if ( function_exists( 'random_bytes' ) ) {
				$token = bin2hex( random_bytes( 32 ) );
			}
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
				$token = md5( wp_salt( 'auth' ) . uniqid( (string) $user_id, true ) ) . md5( microtime() . wp_salt( 'secure_auth' ) );
			}
			update_user_meta( $user_id, self::META_KEY, $token );
		}

		if ( '' === self::slug_for_user( $user_id ) ) {
			$suggestions = self::suggest_slugs( self::default_slug_base( $user_id ), $user_id, 1 );
			if ( $suggestions ) {
				update_user_meta( $user_id, self::META_SLUG, $suggestions[0] );
			}
		}

		return self::link_payload( $user_id );
	}

	/**
	 * Public URL + PDF URL for a user's active link.
	 *
	 * @param int $user_id User ID.
	 * @return array{token:string,slug:string,url:string,pdf_url:string}|null
	 */
	public static function link_payload( $user_id ) {
		$token = self::token_for_user( $user_id );
		if ( '' === $token ) {
			return null;
		}

		$slug = self::slug_for_user( $user_id );
		$base = home_url( '/' . self::REWRITE_SLUG . '/' . ( $slug ? $slug : $token ) . '/' );

		return array(
			'token'   => $token,
			'slug'    => $slug,
			'url'     => $base,
			'pdf_url' => add_query_arg( self::PDF_ARG, '1', $base ),
		);
	}

	/**
	 * Removes the share token (and short name) so the public link stops working.
	 *
	 * @param int $user_id User ID.
	 */
	public static function revoke_token( $user_id ) {
		delete_user_meta( absint( $user_id ), self::META_KEY );
		delete_user_meta( absint( $user_id ), self::META_SLUG );
	}

	/* ---------------------------------------------------------------------
	 * Short name (slug) helpers
	 * ------------------------------------------------------------------- */

	/**
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function slug_for_user( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		return (string) get_user_meta( $user_id, self::META_SLUG, true );
	}

	/**
	 * @return string[] Names that can never be used as a catalog link.
	 */
	private static function reserved_slugs() {
		return array( 'admin', 'wp-admin', 'login', 'wp-login', 'api', 'catalogo', 'catalogos', 'arriendo-facil', 'arriendofacil', 'soporte', 'ayuda', 'null', 'www', 'pdf' );
	}

	/**
	 * Lower-cases, strips accents and collapses the input into a URL-safe name.
	 *
	 * @param string $raw Raw user input.
	 * @return string
	 */
	public static function normalize_slug( $raw ) {
		$slug = sanitize_title( remove_accents( (string) $raw ) );
		$slug = preg_replace( '/[^a-z0-9-]+/', '', $slug );
		$slug = preg_replace( '/-+/', '-', (string) $slug );

		return trim( (string) $slug, '-' );
	}

	/**
	 * @param string $slug    Normalized slug.
	 * @param int    $user_id Owner allowed to keep it.
	 * @return true|WP_Error
	 */
	public static function validate_slug( $slug, $user_id ) {
		$len = strlen( $slug );

		if ( $len < self::SLUG_MIN || $len > self::SLUG_MAX ) {
			return new WP_Error( 'length', sprintf( /* translators: 1: min, 2: max */ __( 'Usa entre %1$d y %2$d caracteres.', 'arriendo-facil' ), self::SLUG_MIN, self::SLUG_MAX ) );
		}
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			return new WP_Error( 'format', __( 'Usa solo letras, números y guiones.', 'arriendo-facil' ) );
		}
		if ( in_array( $slug, self::reserved_slugs(), true ) ) {
			return new WP_Error( 'reserved', __( 'Ese nombre está reservado por el sistema.', 'arriendo-facil' ) );
		}

		$owners = get_users(
			array(
				'meta_key'   => self::META_SLUG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
				'number'     => 2,
			)
		);
		foreach ( (array) $owners as $owner_id ) {
			if ( (int) $owner_id !== (int) $user_id ) {
				return new WP_Error( 'taken', __( 'Ese nombre ya lo usa otro catálogo.', 'arriendo-facil' ) );
			}
		}

		return true;
	}

	/**
	 * Company name (or display name) as the starting point for suggestions.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function default_slug_base( $user_id ) {
		$company = (string) get_user_meta( $user_id, 'af_company_name', true );
		if ( '' === $company ) {
			$user    = get_userdata( $user_id );
			$company = $user ? $user->display_name : '';
		}

		$base = self::normalize_slug( $company );

		return '' !== $base ? $base : 'mis-inmuebles';
	}

	/**
	 * Available alternatives built from a base name.
	 *
	 * @param string $base    Normalized base (may be empty or invalid).
	 * @param int    $user_id Owner.
	 * @param int    $limit   Max suggestions.
	 * @return string[]
	 */
	public static function suggest_slugs( $base, $user_id, $limit = 3 ) {
		$base = substr( self::normalize_slug( $base ), 0, self::SLUG_MAX - 10 );
		$base = trim( $base, '-' );
		if ( strlen( $base ) < self::SLUG_MIN ) {
			$base = self::default_slug_base( $user_id );
		}

		$city       = self::normalize_slug( (string) get_user_meta( $user_id, 'af_city', true ) );
		$candidates = array( $base, $base . '-arriendos', $base . '-inmuebles' );
		if ( $city && false === strpos( $base, $city ) ) {
			$candidates[] = $base . '-' . $city;
		}
		$candidates[] = $base . '-' . gmdate( 'Y' );
		for ( $i = 2; $i <= 9; $i++ ) {
			$candidates[] = $base . '-' . $i;
		}

		$out = array();
		foreach ( array_unique( $candidates ) as $candidate ) {
			$candidate = substr( $candidate, 0, self::SLUG_MAX );
			if ( true === self::validate_slug( $candidate, $user_id ) ) {
				$out[] = $candidate;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Resolves a token to its owning WP_User.
	 *
	 * @param string $token Raw token.
	 * @return WP_User|null
	 */
	public function user_for_token( $token ) {
		$token = sanitize_key( (string) $token );
		$is_token = (bool) preg_match( '/^[a-f0-9]{64}$/', $token );

		if ( ! $is_token && ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $token ) ) {
			return null;
		}

		$users = get_users(
			array(
				'meta_key'   => $is_token ? self::META_KEY : self::META_SLUG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $token,
				'number'     => 1,
				'fields'     => 'all',
			)
		);

		if ( empty( $users ) ) {
			return null;
		}

		// A short name only resolves while the link is active.
		return ( $is_token || '' !== self::token_for_user( $users[0]->ID ) ) ? $users[0] : null;
	}

	/* ---------------------------------------------------------------------
	 * Catalog data
	 * ------------------------------------------------------------------- */

	/**
	 * Human labels for the accommodation status values.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels() {
		return array(
			'available'   => __( 'Disponible', 'arriendo-facil' ),
			'rented'      => __( 'Arrendado', 'arriendo-facil' ),
			'maintenance' => __( 'En mantenimiento', 'arriendo-facil' ),
			'inactive'    => __( 'Inactivo', 'arriendo-facil' ),
		);
	}

	/**
	 * Human labels for the accommodation type values.
	 *
	 * @return array<string,string>
	 */
	public static function type_labels() {
		return array(
			'apartment'  => __( 'Apartamento', 'arriendo-facil' ),
			'house'      => __( 'Casa', 'arriendo-facil' ),
			'office'     => __( 'Oficina', 'arriendo-facil' ),
			'room'       => __( 'Habitación', 'arriendo-facil' ),
			'commercial' => __( 'Comercial', 'arriendo-facil' ),
		);
	}

	/**
	 * Accent colour per status, used by the web cards and the PDF alike.
	 *
	 * @return array<string,array{label:string,fg:string,bg:string}>
	 */
	public static function status_styles() {
		return array(
			'available'   => array( 'label' => __( 'Disponible', 'arriendo-facil' ), 'fg' => '#ffffff', 'bg' => '#15803d' ),
			'rented'      => array( 'label' => __( 'Arrendado', 'arriendo-facil' ), 'fg' => '#ffffff', 'bg' => '#1d4ed8' ),
			'maintenance' => array( 'label' => __( 'En mantenimiento', 'arriendo-facil' ), 'fg' => '#ffffff', 'bg' => '#b45309' ),
		);
	}

	/**
	 * Builds the full public catalog payload for one owner.
	 *
	 * Ownership scoping is deliberately enforced three times, because this is
	 * the only unauthenticated surface in the plugin: (1) a meta_query on
	 * `_af_owner_id`, (2) an explicit `owner_id = ?` predicate inside the
	 * pivot query, and (3) a per-post re-check in PHP. A shared link can only
	 * ever expose properties owned by the token holder.
	 *
	 * @param WP_User $owner Token owner.
	 * @return array{
	 *   company:string, email:string, phone:string, whatsapp:string,
	 *   groups:array<int,array{id:int,name:string,cards:array<int,array<string,mixed>>}>,
	 *   total:int, available:int
	 * }
	 */
	public static function get_catalog( WP_User $owner ) {
		$owner_id = (int) $owner->ID;

		$company = (string) get_user_meta( $owner_id, 'af_company_name', true );
		if ( '' === trim( $company ) ) {
			$company = $owner->display_name ? $owner->display_name : $owner->user_login;
		}

		$phone    = (string) get_user_meta( $owner_id, 'af_contact_phone', true );
		$email    = (string) $owner->user_email;
		$whatsapp = self::whatsapp_link( $phone );

		$cards = self::get_cards( $owner_id );

		$groups = self::group_cards( $cards, $owner_id );

		$available = 0;
		foreach ( $cards as $card ) {
			if ( 'available' === $card['status'] ) {
				$available++;
			}
		}

		return array(
			'company'   => $company,
			'email'     => $email,
			'phone'     => $phone,
			'whatsapp'  => $whatsapp,
			'groups'    => $groups,
			'total'     => count( $cards ),
			'available' => $available,
		);
	}

	/**
	 * Builds a wa.me deep link, normalising Ecuadorian local numbers.
	 *
	 * @param string $phone Raw phone number.
	 * @return string
	 */
	private static function whatsapp_link( $phone ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );

		if ( ! $phone ) {
			return '';
		}

		if ( 0 === strpos( $phone, '+' ) ) {
			$digits = substr( $phone, 1 );
		} elseif ( 0 === strpos( $phone, '0' ) ) {
			// Local format: 09XXXXXXXX -> 5939XXXXXXXX.
			$digits = '593' . substr( $phone, 1 );
		} else {
			$digits = '593' . $phone;
		}

		$digits = preg_replace( '/\D+/', '', $digits );

		return ( $digits && strlen( $digits ) >= 10 ) ? 'https://wa.me/' . $digits : '';
	}

	/**
	 * Fetches and normalises every property the public catalog may expose.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_cards( $owner_id ) {
		global $wpdb;

		$owner_id = absint( $owner_id );
		if ( ! $owner_id ) {
			return array();
		}

		// 1. Candidate set: published properties owned by this administrator.
		$candidates = get_posts(
			array(
				'post_type'      => 'accommodation',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_af_owner_id', 'value' => $owner_id ),
				),
			)
		);

		if ( empty( $candidates ) ) {
			return array();
		}

		// 2. Pivot query, with ownership repeated as a hard SQL predicate.
		$ids_sql = Arriendo_Facil_Tenancy::ids_in_clause( $candidates );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID,
				 MAX(CASE WHEN pm.meta_key = '_af_monthly_rent'     THEN pm.meta_value END) AS monthly_rent,
				 MAX(CASE WHEN pm.meta_key = '_af_status'           THEN pm.meta_value END) AS status,
				 MAX(CASE WHEN pm.meta_key = '_af_property_type'    THEN pm.meta_value END) AS property_type,
				 MAX(CASE WHEN pm.meta_key = '_af_address'          THEN pm.meta_value END) AS address,
				 MAX(CASE WHEN pm.meta_key = '_af_city'             THEN pm.meta_value END) AS city,
				 MAX(CASE WHEN pm.meta_key = '_af_location_text'    THEN pm.meta_value END) AS location_text,
				 MAX(CASE WHEN pm.meta_key = '_af_bedrooms'         THEN pm.meta_value END) AS bedrooms,
				 MAX(CASE WHEN pm.meta_key = '_af_bathrooms'        THEN pm.meta_value END) AS bathrooms,
				 MAX(CASE WHEN pm.meta_key = '_af_square_meters'    THEN pm.meta_value END) AS square_meters,
				 MAX(CASE WHEN pm.meta_key = '_af_parking_spots'    THEN pm.meta_value END) AS parking_spots,
				 MAX(CASE WHEN pm.meta_key = '_af_floor_number'     THEN pm.meta_value END) AS floor_number,
				 MAX(CASE WHEN pm.meta_key = '_af_year_built'       THEN pm.meta_value END) AS year_built,
				 MAX(CASE WHEN pm.meta_key = '_af_furnished'        THEN pm.meta_value END) AS furnished,
				 MAX(CASE WHEN pm.meta_key = '_af_condition'        THEN pm.meta_value END) AS `condition`,
				 MAX(CASE WHEN pm.meta_key = '_af_amenities'        THEN pm.meta_value END) AS amenities,
				 MAX(CASE WHEN pm.meta_key = '_af_utilities_included' THEN pm.meta_value END) AS utilities,
				 MAX(CASE WHEN pm.meta_key = '_af_owner_id'         THEN pm.meta_value END) AS owner_id,
				 MAX(CASE WHEN pm.meta_key = '_thumbnail_id'        THEN pm.meta_value END) AS thumbnail_id
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} po ON po.post_id = p.ID
				  AND po.meta_key = '_af_owner_id' AND po.meta_value = %d
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				  AND pm.meta_key IN ('_af_monthly_rent','_af_status','_af_property_type','_af_address','_af_city','_af_location_text','_af_bedrooms','_af_bathrooms','_af_square_meters','_af_parking_spots','_af_floor_number','_af_year_built','_af_furnished','_af_condition','_af_amenities','_af_utilities_included','_af_owner_id','_thumbnail_id')
				 WHERE p.ID IN ({$ids_sql})
				   AND p.post_type = 'accommodation'
				   AND p.post_status = 'publish'
				 GROUP BY p.ID
				 ORDER BY p.post_title ASC",
				$owner_id
			)
		);
		// phpcs:enable

		$status_labels = self::status_labels();
		$type_labels   = self::type_labels();

		$amenity_labels = array(
			'elevator'        => __( 'Ascensor', 'arriendo-facil' ),
			'balcony'         => __( 'Balcón', 'arriendo-facil' ),
			'terrace'         => __( 'Terraza', 'arriendo-facil' ),
			'garden'          => __( 'Jardín', 'arriendo-facil' ),
			'pool'            => __( 'Piscina', 'arriendo-facil' ),
			'gym'             => __( 'Gimnasio', 'arriendo-facil' ),
			'security'        => __( 'Seguridad 24/7', 'arriendo-facil' ),
			'intercom'        => __( 'Intercomunicador', 'arriendo-facil' ),
			'furnished'       => __( 'Amueblado', 'arriendo-facil' ),
			'pet_friendly'    => __( 'Mascotas', 'arriendo-facil' ),
			'washer'          => __( 'Lavadora', 'arriendo-facil' ),
			'fridge'          => __( 'Refrigerador', 'arriendo-facil' ),
			'wifi'            => __( 'Internet', 'arriendo-facil' ),
			'parking'         => __( 'Parqueadero', 'arriendo-facil' ),
			'doorman'         => __( 'Guardianía', 'arriendo-facil' ),
			'garage'          => __( 'Garaje', 'arriendo-facil' ),
			'water_heater'    => __( 'Calentador de agua', 'arriendo-facil' ),
			'backyard'        => __( 'Patio', 'arriendo-facil' ),
			'rooftop'         => __( 'Azotea', 'arriendo-facil' ),
		);

		$furnished_labels = array(
			'unfurnished' => __( 'Sin amueblar', 'arriendo-facil' ),
			'semi'        => __( 'Semi amueblado', 'arriendo-facil' ),
			'furnished'   => __( 'Amueblado', 'arriendo-facil' ),
		);

		$condition_labels = array(
			'new'          => __( 'Nuevo', 'arriendo-facil' ),
			'very_good'    => __( 'Muy bueno', 'arriendo-facil' ),
			'good'         => __( 'Bueno', 'arriendo-facil' ),
			'needs_repair' => __( 'Necesita reparación', 'arriendo-facil' ),
		);

		$utility_labels = array(
			'water'    => __( 'Agua', 'arriendo-facil' ),
			'electric' => __( 'Luz', 'arriendo-facil' ),
			'internet' => __( 'Internet', 'arriendo-facil' ),
			'gas'      => __( 'Gas', 'arriendo-facil' ),
		);

		$cards = array();

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row->ID;

			// 3. Final per-post ownership re-check.
			if ( (int) $row->owner_id !== $owner_id ) {
				continue;
			}
			if ( (int) get_post_meta( $post_id, '_af_owner_id', true ) !== $owner_id ) {
				continue;
			}

			// Opt-out switch set from the admin catalog.
			if ( ! Arriendo_Facil_Catalog_Groups::is_in_public_catalog( $post_id ) ) {
				continue;
			}

			$status = $row->status ? (string) $row->status : 'available';
			if ( 'inactive' === $status ) {
				continue;
			}

			$type = $row->property_type ? (string) $row->property_type : '';

			$thumbnail = $row->thumbnail_id
				? wp_get_attachment_image_url( (int) $row->thumbnail_id, 'large' )
				: get_the_post_thumbnail_url( $post_id, 'large' );

			$photos  = $thumbnail ? array( $thumbnail ) : array();
			$gallery = get_post_meta( $post_id, '_af_gallery', true );
			foreach ( array_slice( array_unique( array_map( 'absint', (array) $gallery ) ), 0, 10 ) as $att_id ) {
				$url = $att_id ? wp_get_attachment_image_url( $att_id, 'large' ) : '';
				if ( $url && ! in_array( $url, $photos, true ) ) {
					$photos[] = $url;
				}
			}

			$address = trim( trim( (string) $row->address ) . ( $row->city ? ', ' . $row->city : '' ) );
			if ( ! $address && $row->location_text ) {
				$address = (string) $row->location_text;
			}

			$excerpt = has_excerpt( $post_id )
				? get_the_excerpt( $post_id )
				: wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 32 );

			$cards[] = array(
				'id'            => $post_id,
				'title'         => get_the_title( $post_id ),
				'thumb'         => $thumbnail ? $thumbnail : ( $photos ? $photos[0] : '' ),
				'photos'        => $photos,
				'status'        => $status,
				'status_lbl'    => isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status,
				'type'          => isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : ( $type ? ucfirst( $type ) : '' ),
				'address'       => $address,
				'excerpt'       => $excerpt,
				'bedrooms'      => (int) $row->bedrooms,
				'bathrooms'     => (int) $row->bathrooms,
				'square_meters' => (float) $row->square_meters,
				'parking'       => (int) $row->parking_spots,
				'floor'         => (int) $row->floor_number,
				'year_built'    => (int) $row->year_built,
				'furnished'     => isset( $furnished_labels[ $row->furnished ] ) ? $furnished_labels[ $row->furnished ] : '',
				'condition'     => isset( $condition_labels[ $row->condition ] ) ? $condition_labels[ $row->condition ] : '',
				'amenities'     => self::decode_list( $row->amenities, $amenity_labels ),
				'utilities'     => self::decode_list( $row->utilities, $utility_labels ),
				'monthly_rent'  => (float) $row->monthly_rent,
				'group_id'      => Arriendo_Facil_Catalog_Groups::get_group_id_for_property( $post_id ),
			);
		}

		return $cards;
	}

	/**
	 * Decodes a stored list meta value into human labels.
	 *
	 * @param string|array|null $raw    Stored value.
	 * @param array<string,string> $labels Label map.
	 * @return string[]
	 */
	private static function decode_list( $raw, array $labels ) {
		if ( empty( $raw ) ) {
			return array();
		}

		if ( is_string( $raw ) ) {
			$decoded = maybe_unserialize( $raw );
			$raw     = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $key ) {
			$key = is_array( $key ) ? '' : (string) $key;
			$key = trim( $key );

			if ( '' === $key ) {
				continue;
			}

			$out[] = isset( $labels[ $key ] ) ? $labels[ $key ] : ucwords( str_replace( '_', ' ', $key ) );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Buckets cards into their building groups, then a final bucket for the
	 * properties that are not part of any building.
	 *
	 * @param array<int,array<string,mixed>> $cards   Flat card list.
	 * @param int                           $owner_id Owner user ID.
	 * @return array<int,array{id:int,name:string,cards:array<int,array<string,mixed>>}>
	 */
	public static function group_cards( array $cards, $owner_id ) {
		$names = Arriendo_Facil_Catalog_Groups::get_name_map( $owner_id );

		$groups = array();
		$loose  = array();

		foreach ( $cards as $card ) {
			$group_id = isset( $card['group_id'] ) ? (int) $card['group_id'] : 0;

			if ( $group_id && isset( $names[ $group_id ] ) ) {
				if ( ! isset( $groups[ $group_id ] ) ) {
					$groups[ $group_id ] = array(
						'id'    => $group_id,
						'name'  => $names[ $group_id ],
						'cards' => array(),
					);
				}
				$groups[ $group_id ]['cards'][] = $card;
				continue;
			}

			// A stale group id (deleted group, or out of scope) is treated as
			// "independent" rather than leaking another owner's grouping.
			$loose[] = $card;
		}

		uasort(
			$groups,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		if ( ! empty( $loose ) ) {
			$groups[] = array(
				'id'    => 0,
				'name'  => __( 'Otros inmuebles', 'arriendo-facil' ),
				'cards' => $loose,
			);
		}

		return array_values( $groups );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * Generates or rotates the current user's share link.
	 */
	public function ajax_generate() {
		check_ajax_referer( 'af_catalog_share_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id = get_current_user_id();
		$wanted  = isset( $_POST['slug'] ) ? self::normalize_slug( sanitize_text_field( wp_unslash( $_POST['slug'] ) ) ) : '';

		if ( '' !== $wanted ) {
			$valid = self::validate_slug( $wanted, $user_id );
			if ( is_wp_error( $valid ) ) {
				wp_send_json_error(
					array(
						'message'     => $valid->get_error_message(),
						'suggestions' => self::suggest_slugs( $wanted, $user_id ),
					)
				);
			}
			update_user_meta( $user_id, self::META_SLUG, $wanted );
		}

		$result = self::generate_token( $user_id );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo generar el enlace.', 'arriendo-facil' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Enlace creado. Ya puedes compartirlo.', 'arriendo-facil' ),
				'slug'    => $result['slug'],
				'url'     => $result['url'],
				'pdfUrl'  => $result['pdf_url'],
			)
		);
	}

	/**
	 * Checks (check=1) or saves the short name of the current user's link.
	 */
	public function ajax_slug() {
		check_ajax_referer( 'af_catalog_share_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id = get_current_user_id();
		$raw     = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$slug    = self::normalize_slug( $raw );
		$check   = ! empty( $_POST['check'] );
		$valid   = self::validate_slug( $slug, $user_id );

		if ( is_wp_error( $valid ) ) {
			wp_send_json_error(
				array(
					'message'     => $valid->get_error_message(),
					'slug'        => $slug,
					'suggestions' => self::suggest_slugs( '' !== $slug ? $slug : $raw, $user_id ),
				)
			);
		}

		if ( $check ) {
			wp_send_json_success(
				array(
					'slug'    => $slug,
					'message' => __( 'Disponible.', 'arriendo-facil' ),
				)
			);
		}

		if ( '' === self::token_for_user( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Primero crea tu enlace.', 'arriendo-facil' ) ) );
		}

		update_user_meta( $user_id, self::META_SLUG, $slug );
		$payload = self::link_payload( $user_id );

		wp_send_json_success(
			array(
				'message' => __( 'Nombre del enlace guardado.', 'arriendo-facil' ),
				'slug'    => $payload['slug'],
				'url'     => $payload['url'],
				'pdfUrl'  => $payload['pdf_url'],
			)
		);
	}

	/**
	 * Revokes the current user's share link.
	 */
	public function ajax_revoke() {
		check_ajax_referer( 'af_catalog_share_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		self::revoke_token( get_current_user_id() );

		wp_send_json_success( array( 'message' => __( 'Enlace desactivado.', 'arriendo-facil' ) ) );
	}

	/**
	 * Live counters for the share panel (how many properties will be shown).
	 */
	public function ajax_stats() {
		check_ajax_referer( 'af_catalog_share_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$owner_id = get_current_user_id();

		// A super admin may inspect another administrator's counters.
		if ( ! empty( $_POST['owner_id'] ) ) {
			$requested = absint( wp_unslash( $_POST['owner_id'] ) );

			if ( $requested !== $owner_id && Arriendo_Facil_Tenancy::can_manage_all() ) {
				$owner_id = $requested;
			}
		}

		$owner = get_userdata( $owner_id );

		if ( ! $owner instanceof WP_User ) {
			wp_send_json_error( array( 'message' => __( 'Gestor no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		$catalog = self::get_catalog( $owner );

		/*
		 * The group list is read straight from the groups table rather than
		 * from $catalog['groups']: the public payload only carries groups
		 * that already hold at least one property, so a freshly created
		 * building would vanish from the panel and take the per-property
		 * dropdowns down with it (they are repainted from this same list).
		 * Counts are therefore resolved separately, and default to zero.
		 */
		$groups  = array();
		$counts  = self::group_counts_for_owner( $owner_id );
		$listed  = class_exists( 'Arriendo_Facil_Catalog_Groups' )
			? Arriendo_Facil_Catalog_Groups::get_for_owner( $owner_id )
			: array();

		foreach ( (array) $listed as $group ) {
			$group_id = (int) $group->id;

			$groups[] = array(
				'id'    => $group_id,
				'name'  => (string) $group->name,
				'count' => isset( $counts[ $group_id ] ) ? $counts[ $group_id ] : 0,
			);
		}

		wp_send_json_success(
			array(
				'total'     => $catalog['total'],
				'available' => $catalog['available'],
				'groups'    => $groups,
			)
		);
	}

	/**
	 * Counts the properties of one owner, bucketed by building group.
	 *
	 * Properties with no group (or a stale group id) are not counted here;
	 * the panel only needs per-building totals.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return array<int,int> group_id => property count.
	 */
	public static function group_counts_for_owner( $owner_id ) {
		global $wpdb;

		$owner_id = absint( $owner_id );
		if ( ! $owner_id ) {
			return array();
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT gm.meta_value AS group_id, COUNT(DISTINCT p.ID) AS total
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} om ON om.post_id = p.ID
				  AND om.meta_key = '_af_owner_id' AND om.meta_value = %d
				 INNER JOIN {$wpdb->postmeta} gm ON gm.post_id = p.ID
				  AND gm.meta_key = %s AND gm.meta_value <> '0'
				 WHERE p.post_type = 'accommodation'
				   AND p.post_status = 'publish'
				 GROUP BY gm.meta_value",
				$owner_id,
				Arriendo_Facil_Catalog_Groups::META_GROUP
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
	 * Assets
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		$screen      = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_listing  = 'edit.php' === $hook && $screen && 'accommodation' === $screen->post_type;
		if ( 'arriendo-facil_page_af-catalog' !== $hook && ! $is_listing ) {
			return;
		}

		$css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-catalog-share.css';
		$js_path  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-catalog-share.js';

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'af-catalog-share-admin',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-catalog-share.css',
				array(),
				filemtime( $css_path )
			);
		}

		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'af-catalog-share-admin',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-catalog-share.js',
				array(),
				filemtime( $js_path ),
				true
			);
			wp_localize_script(
				'af-catalog-share-admin',
				'afCatalogShare',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'af_catalog_share_nonce' ),
					'i18n'    => array(
						'copied'        => __( 'Enlace copiado.', 'arriendo-facil' ),
						'groupPrompt'   => __( 'Escribe el nombre del edificio o conjunto.', 'arriendo-facil' ),
						'renamePrompt'  => __( 'Escribe el nuevo nombre.', 'arriendo-facil' ),
						'deleteConfirm' => __( '¿Eliminar este grupo? Sus propiedades quedarán como inmuebles independientes.', 'arriendo-facil' ),
						'noGroups'      => __( 'Todavía no has creado ningún edificio o conjunto.', 'arriendo-facil' ),
						'noGroup'       => __( '— Sin grupo —', 'arriendo-facil' ),
						'rename'        => __( 'Renombrar', 'arriendo-facil' ),
						'remove'        => __( 'Eliminar', 'arriendo-facil' ),
						'unitLabel'     => __( 'inmueble', 'arriendo-facil' ),
						'unitLabelPlural' => __( 'inmuebles', 'arriendo-facil' ),
						'checking'      => __( 'Comprobando…', 'arriendo-facil' ),
						'tryThese'      => __( 'Prueba con:', 'arriendo-facil' ),
						'slugChange'    => __( 'El enlace anterior dejará de funcionar. ¿Guardar el nuevo nombre?', 'arriendo-facil' ),
					),
				)
			);
		}
	}

	/**
	 * Frontend assets for the shared catalog page.
	 */
	public function enqueue_public_assets() {
		if ( ! get_query_var( self::QUERY_VAR ) && ! self::is_shortcode_context() ) {
			return;
		}

		$css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-catalog-share.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'af-catalog-share-public',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-catalog-share.css',
				array(),
				filemtime( $css_path )
			);
		}

		$js_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-catalog-share-public.js';
		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'af-catalog-share-public',
				ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-catalog-share-public.js',
				array(),
				filemtime( $js_path ),
				true
			);

			wp_localize_script(
				'af-catalog-share-public',
				'afCatalogPublic',
				array(
					'flipLabel'        => __( 'Ver detalles', 'arriendo-facil' ),
					'backLabel'        => __( 'Volver', 'arriendo-facil' ),
					'moreLabel'        => __( 'Leer más', 'arriendo-facil' ),
					'lessLabel'        => __( 'Mostrar menos', 'arriendo-facil' ),
					'chipsLessLabel'   => __( 'Mostrar menos', 'arriendo-facil' ),
					'countLabel'       => __( 'propiedad', 'arriendo-facil' ),
					'countLabelPlural' => __( 'propiedades', 'arriendo-facil' ),
					'unitLabel'        => __( 'inmueble', 'arriendo-facil' ),
					'unitLabelPlural'  => __( 'inmuebles', 'arriendo-facil' ),
				)
			);
		}
	}

	/**
	 * @return bool
	 */
	private static function is_shortcode_context() {
		global $post;

		return $post && has_shortcode( $post->post_content, 'af_catalog_share' );
	}
}
