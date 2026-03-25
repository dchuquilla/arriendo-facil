<?php
/**
 * Plugin Name: Arriendo Fácil
 * Plugin URI:  https://github.com/dchuquilla/arriendo-facil
 * Description: Manage accommodations, cleaning services, leases, owner contacts, and AI-powered cost prediction, document generation, and guest management.
 * Version:     1.0.0
 * Author:      Arriendo Fácil Team
 * Text Domain: arriendo-facil
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ARRIENDO_FACIL_VERSION', '1.0.0' );
define( 'ARRIENDO_FACIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARRIENDO_FACIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-activator.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-cleaning-service.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-lease.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-owner-contact.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-guest.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-ai-service.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'admin/class-admin.php';

register_activation_hook( __FILE__, array( 'Arriendo_Facil_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Arriendo_Facil_Activator', 'deactivate' ) );

/**
 * Applies runtime PHP limits used by owner PDF uploads.
 */
function arriendo_facil_apply_runtime_upload_limits() {
	if ( function_exists( 'ini_set' ) ) {
		@ini_set( 'upload_max_filesize', '12M' );
		@ini_set( 'post_max_size', '36M' );
		@ini_set( 'memory_limit', '256M' );
		@ini_set( 'max_execution_time', '120' );
	}
}
add_action( 'init', 'arriendo_facil_apply_runtime_upload_limits', 1 );

/**
 * Raises WordPress-level upload limit where possible.
 *
 * @param int $size_bytes Current max bytes.
 * @return int
 */
function arriendo_facil_max_upload_size( $size_bytes ) {
	$target = 12 * 1024 * 1024;
	return max( (int) $size_bytes, $target );
}
add_filter( 'upload_size_limit', 'arriendo_facil_max_upload_size' );
add_filter( 'wp_max_upload_size', 'arriendo_facil_max_upload_size' );

/**
 * Initialises all plugin components.
 */
function arriendo_facil_init() {
	load_plugin_textdomain( 'arriendo-facil', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	new Arriendo_Facil_Accommodation();
	new Arriendo_Facil_Cleaning_Service();
	new Arriendo_Facil_Lease();
	new Arriendo_Facil_Owner_Contact();
	new Arriendo_Facil_Guest();
	new Arriendo_Facil_Admin();
}
add_action( 'plugins_loaded', 'arriendo_facil_init' );
