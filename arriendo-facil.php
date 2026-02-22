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
