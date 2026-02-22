<?php
/**
 * Bootstrap file for plugin unit tests (no WordPress dependency).
 *
 * Defines minimal WordPress stubs so the plugin classes can be loaded
 * and unit-tested without a full WordPress installation.
 *
 * @package Arriendo_Facil
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'ARRIENDO_FACIL_VERSION', '1.0.0' );
define( 'ARRIENDO_FACIL_PLUGIN_DIR', __DIR__ . '/../' );
define( 'ARRIENDO_FACIL_PLUGIN_URL', 'http://example.com/wp-content/plugins/arriendo-facil/' );

// ── Minimal WordPress function stubs ────────────────────────────────────────

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( trim( $email ), FILTER_SANITIZE_EMAIL );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( $str );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $val ) {
		return abs( (int) $val );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		return new WP_Error( 'no_http', 'HTTP requests disabled in tests.' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_code() {
			return $this->code;
		}
	}
}

// ── Minimal $wpdb stub ───────────────────────────────────────────────────────

if ( ! class_exists( 'WPDB_Stub' ) ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	class WPDB_Stub {
		/** @var string Table prefix. */
		public $prefix = 'wp_';

		/** Silently accept inserts (used by AI log, etc.). */
		public function insert( $table, $data, $format = null ) {
			return 1;
		}

		/** Minimal prepare stub – returns the query string as-is. */
		public function prepare( $query, ...$args ) {
			return vsprintf( str_replace( array( '%d', '%f' ), '%s', $query ), $args );
		}

		public function get_row( $query ) {
			return null;
		}

		public function get_results( $query ) {
			return array();
		}

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			return 1;
		}
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new WPDB_Stub();
}

// Load classes under test.
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-ai-service.php';
