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

// Time constants WordPress defines and the plugin relies on for date maths.
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
defined( 'MONTH_IN_SECONDS' ) || define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
defined( 'YEAR_IN_SECONDS' ) || define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );

// The acting user is shared by every test file. Stubs like this have to live
// here rather than in an individual test file: whichever test file PHPUnit
// happens to load first would otherwise win the function_exists() race and
// silently change the behaviour of the others.
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * @return int
	 */
	function get_current_user_id() {
		return isset( $GLOBALS['af_test_user_id'] ) ? (int) $GLOBALS['af_test_user_id'] : 5;
	}
}

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

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
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

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Merges user arguments over defaults, filling missing keys.
	 *
	 * @param array|object $args     Provided arguments.
	 * @param array        $defaults Default arguments.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		} elseif ( ! is_array( $args ) ) {
			parse_str( (string) $args, $args );
		}

		return array_merge( (array) $defaults, (array) $args );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		if ( ! isset( $GLOBALS['af_test_options'] ) || ! is_array( $GLOBALS['af_test_options'] ) ) {
			$GLOBALS['af_test_options'] = array();
		}
		return array_key_exists( $option, $GLOBALS['af_test_options'] )
			? $GLOBALS['af_test_options'][ $option ]
			: $default;
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

		/** @var string Options table name. */
		public $options = 'wp_options';

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

		public function get_var( $query ) {
			return 0;
		}

		public function query( $query ) {
			return 1;
		}
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new WPDB_Stub();
}

// ── Constants required by billing classes ──────────────────────────────────

if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-arriendo-facil-billing' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-arriendo-facil-billing' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/af-test-wp-content' );
}

// ── Additional WP function stubs ─────────────────────────────────────────────

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		if ( ! isset( $GLOBALS['af_test_options'] ) || ! is_array( $GLOBALS['af_test_options'] ) ) {
			$GLOBALS['af_test_options'] = array();
		}
		$GLOBALS['af_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0755, true );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) {
		return gmdate( $format, $timestamp ?? time() );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		$base = WP_CONTENT_DIR . '/uploads';
		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0755, true );
		}
		return array(
			'basedir' => $base,
			'baseurl' => 'http://example.com/wp-content/uploads',
			'error'   => false,
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['response']['code'] ?? 200 ) : 0;
	}
}

// Load classes under test.
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-ai-service.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-config.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-clave-acceso.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-xml-factura.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-signer.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-soap-client.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-ride.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-billing-manager.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-catalog-groups.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-catalog-share.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-security-hardening.php';
