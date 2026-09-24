<?php
/**
 * Shareable public catalog: tokenized URL + print/PDF export.
 *
 * Every property administrator can generate a public link
 * (https://site.tld/catalogo/<token>) that renders a clean grid of their
 * accommodations for sharing with prospective tenants. The link can be
 * rotated or revoked at any time.
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

	const META_KEY      = '_af_catalog_share_token';
	const QUERY_VAR     = 'af_catalog_share';
	const REWRITE_SLUG  = 'catalogo';

	/**
	 * Hooks into rewrites, AJAX and asset loading.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_routes' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'template_include', array( $this, 'load_public_template' ) );
		add_action( 'admin_init', array( $this, 'maybe_flush_rules' ) );

		add_action( 'wp_ajax_af_catalog_share_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_af_catalog_share_revoke', array( $this, 'ajax_revoke' ) );

		add_shortcode( 'af_catalog_share', array( $this, 'render_shortcode' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
	}

	/**
	 * Rewrite route: /catalogo/<64-hex token>/ -> index.php?af_catalog_share=<token>
	 */
	public function register_routes() {
		add_rewrite_rule(
			'^catalogo/([a-f0-9]{64})/?$',
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
		if ( get_option( 'af_catalog_share_rules_flushed' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'af_catalog_share_rules_flushed', 1, false );
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
			status_header( 404 );
			$GLOBALS['af_catalog_share_user'] = null;

			return $this->public_template_path( true );
		}

		$GLOBALS['af_catalog_share_user'] = $user;

		return $this->public_template_path( false );
	}

	/**
	 * @param bool $not_found Renders the invalid-token variant.
	 * @return string
	 */
	private function public_template_path( $not_found ) {
		global $af_catalog_share_not_found;

		$af_catalog_share_not_found = $not_found;

		return ARRIENDO_FACIL_PLUGIN_DIR . 'public/catalog-share.php';
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
	 * @return array{token:string,url:string}|null
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

		return array(
			'token' => $token,
			'url'   => home_url( '/' . self::REWRITE_SLUG . '/' . $token . '/' ),
		);
	}

	/**
	 * Removes the share token so the public link stops working.
	 *
	 * @param int $user_id User ID.
	 */
	public static function revoke_token( $user_id ) {
		delete_user_meta( absint( $user_id ), self::META_KEY );
	}

	/**
	 * Resolves a token to its owning WP_User.
	 *
	 * @param string $token Raw token.
	 * @return WP_User|null
	 */
	public function user_for_token( $token ) {
		$token = sanitize_key( (string) $token );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}

		$users = get_users(
			array(
				'meta_key'   => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $token,
				'number'     => 1,
				'fields'     => 'all',
			)
		);

		return ! empty( $users ) ? $users[0] : null;
	}

	/* ---------------------------------------------------------------------
	 * AJAX: generate/rotate and revoke from the catalog screen
	 * ------------------------------------------------------------------- */

	/**
	 * Generates or rotates the current user's share link.
	 */
	public function ajax_generate() {
		check_ajax_referer( 'af_catalog_share_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$rotate = isset( $_POST['rotate'] ) ? rest_sanitize_boolean( wp_unslash( $_POST['rotate'] ) ) : false;
		$result = self::generate_token( get_current_user_id(), $rotate );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo generar el enlace.', 'arriendo-facil' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => $rotate ? __( 'Enlace regenerado.', 'arriendo-facil' ) : __( 'Enlace generado.', 'arriendo-facil' ),
				'url'     => $result['url'],
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

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'arriendo-facil_page_af-catalog' !== $hook ) {
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
						'copied' => __( 'Enlace copiado.', 'arriendo-facil' ),
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
	}

	/**
	 * @return bool
	 */
	private static function is_shortcode_context() {
		global $post;

		return $post && has_shortcode( $post->post_content, 'af_catalog_share' );
	}
}