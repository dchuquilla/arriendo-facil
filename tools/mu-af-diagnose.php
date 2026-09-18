<?php
/**
 * Plugin Name: AFR Diagnostico (temporal)
 * Description: Sonda para detectar la fuente del 403 "Sorry, you are not allowed to access this page."
 *
 * USO:
 * 1. Sube este archivo a /wp-content/mu-plugins/af-diagnose.php
 * 2. Abre la pagina que falla anadiendo &af_diag=1 a la URL:
 *    https://SITIO/wp-admin/admin.php?page=af-admin-profile&af_diag=1
 * 3. Revisa /wp-content/af-diagnose.log (boton de cPanel / FTP).
 * 4. Cuando termines, borra el archivo de mu-plugins y el log.
 * Solo escribe log si la URL lleva af_diag=1 (o si AF_DIAGNOSE esta definida en true).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AFR_Diagnose {

	const LOG_NAME = 'af-diagnose.log';

	/** @var array */
	private $seen = array();

	public function __construct() {
		add_action( 'muplugins_loaded', array( $this, 'start' ), 1 );
	}

	public function start() {
		if ( ! $this->enabled() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'capture_state' ), 999999 );
		add_action( 'admin_init', array( $this, 'capture_state' ), 999999 );
		add_filter( 'wp_die_handler', array( $this, 'wrap_die' ), 999999 );
		add_filter( 'wp_die_ajax_handler', array( $this, 'wrap_die_ajax' ), 999999 );
		add_filter( 'wp_die_xmlrpc_handler', array( $this, 'wrap_die' ), 999999 );

		$this->log(
			'REQ',
			array(
				'uri'   => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				'auth'  => array(
					'logged_in' => is_user_logged_in(),
					'super'     => function_exists( 'is_super_admin' ) && is_super_admin(),
				),
			)
		);
	}

	private function enabled() {
		if ( defined( 'AF_DIAGNOSE' ) && AF_DIAGNOSE ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		return false !== strpos( $uri, 'af_diag=1' );
	}

	public function capture_state() {
		$this->log( 'STATE', $this->current_state() );
	}

	/**
	 * @return array
	 */
	private function current_state() {
		$u  = wp_get_current_user();
		$sub = array();
		if ( isset( $GLOBALS['submenu']['arriendo-facil'] ) && is_array( $GLOBALS['submenu']['arriendo-facil'] ) ) {
			foreach ( $GLOBALS['submenu']['arriendo-facil'] as $item ) {
				$sub[] = array( 'cap' => isset( $item[1] ) ? $item[1] : '', 'slug' => isset( $item[2] ) ? $item[2] : '' );
			}
		}

		$active = (array) get_option( 'active_plugins', array() );
		$stores = array(
			'arriendo-facil.php',
			'includes/class-activator.php',
			'includes/class-property-admin-onboarding.php',
			'admin/class-admin.php',
		);
		$files  = array();
		foreach ( $active as $plugin ) {
			if ( false !== strpos( $plugin, 'arriendo-facil' ) ) {
				$dir = dirname( $plugin );
				foreach ( $stores as $rel ) {
					$path = WP_PLUGIN_DIR . '/' . $dir . '/' . $rel;
					$files[ $rel ] = is_file( $path )
						? array(
							'sha256' => hash_file( 'sha256', $path ),
							'mtime'  => gmdate( 'c', filemtime( $path ) ),
						)
						: 'MISSING';
				}
				break;
			}
		}

		return array(
			'user'  => array(
				'id'       => $u instanceof WP_User ? $u->ID : 0,
				'login'    => $u instanceof WP_User ? $u->user_login : '',
				'roles'    => $u instanceof WP_User ? array_values( $u->roles ) : array(),
			),
			'caps'  => array(
				'af_manage_properties' => current_user_can( 'af_manage_properties' ),
				'af_view_billing'      => current_user_can( 'af_view_billing' ),
				'edit_posts'           => current_user_can( 'edit_posts' ),
				'read'                 => current_user_can( 'read' ),
				'manage_options'       => current_user_can( 'manage_options' ),
			),
			'screen' => isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '',
			'pagehook' => get_plugin_page_hook(
				isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : '',
				'admin.php'
			),
			'submenu_arriendo_facil' => $sub,
			'active_plugins'         => $active,
			'plugin_files'           => $files,
		);
	}

	public function wrap_die_ajax( $handler ) {
		return $this->wrap_die( $handler );
	}

	public function wrap_die( $handler ) {
		if ( ! is_callable( $handler ) ) {
			return $handler;
		}
		$self = $this;
		return function ( $message, $title = '', $args = array() ) use ( $handler, $self ) {
			$self->log(
				'DIE',
				array(
					'message'   => is_string( $message ) ? $message : gettype( $message ),
					'title'     => $title,
					'emitter'   => $self->die_emitter(),
					'backtrace' => wp_debug_backtrace_summary(),
				)
			);
			return call_user_func( $handler, $message, $title, $args );
		};
	}

	/**
	 * @return array
	 */
	private function die_emitter() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		$out    = array();
		$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
		foreach ( $frames as $f ) {
			$file = isset( $f['file'] ) ? $f['file'] : '';
			if ( ! $file ) {
				continue;
			}
			$nf   = wp_normalize_path( $file );
			$line = isset( $f['line'] ) ? $f['line'] : 0;
			if ( 0 === strpos( $nf, $plugin_dir ) ) {
				$out[] = str_replace( $plugin_dir . '/', '', $nf ) . ':' . $line . ' [' . ( isset( $f['function'] ) ? $f['function'] : '' ) . ']';
			}
		}
		return $out;
	}

	private function log( $tag, array $data ) {
		$key = $tag . '|' . wp_json_encode( $data );
		if ( isset( $this->seen[ $key ] ) ) {
			return;
		}
		$this->seen[ $key ] = true;
		$line = '[' . gmdate( 'c' ) . '] ' . $tag . ' ' . wp_json_encode( $data ) . PHP_EOL;
		@file_put_contents(
			WP_CONTENT_DIR . '/' . self::LOG_NAME,
			$line,
			FILE_APPEND | LOCK_EX
		);
	}
}

new AFR_Diagnose();