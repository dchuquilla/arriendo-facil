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

$af_composer_autoload = ARRIENDO_FACIL_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $af_composer_autoload ) ) {
	require_once $af_composer_autoload;
}

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-activator.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation-wizard.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation-featured-admin.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation-occupied-admin.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation-search-api.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-cleaning-service.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-docx-template-processor.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-lease.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-rental-workflow.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-owner-contact.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-owner-register-api.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-guest.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-ai-service.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-config.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-clave-acceso.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-xml-factura.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-signer.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-soap-client.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-ride.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-billing-manager.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-billing-api.php';
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
	Arriendo_Facil_Activator::ensure_owner_role();

	new Arriendo_Facil_Accommodation();
	new Arriendo_Facil_Accommodation_Wizard();
	new Arriendo_Facil_Accommodation_Featured_Admin();
	new Arriendo_Facil_Accommodation_Occupied_Admin();
	new Arriendo_Facil_Accommodation_Search_API();
	new Arriendo_Facil_Cleaning_Service();
	new Arriendo_Facil_Lease();
	new Arriendo_Facil_Rental_Workflow();
	new Arriendo_Facil_Owner_Contact();
	new Arriendo_Facil_Owner_Register_API();
	new Arriendo_Facil_Guest();
	new Arriendo_Facil_Billing_API();
	new Arriendo_Facil_Admin();
}
add_action( 'plugins_loaded', 'arriendo_facil_init' );

/**
 * Runs one-time schema upgrades for ZIP-based plugin updates.
 *
 * This ensures new tables/columns are created even when the plugin is updated
 * from ZIP without triggering activation hooks.
 */
function arriendo_facil_maybe_upgrade_schema() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$target_schema_version = '2026-07-owner-sri-multi-tenant-v1';
	$current_schema_version = (string) get_option( 'af_db_schema_version', '' );

	if ( $current_schema_version === $target_schema_version ) {
		return;
	}

	Arriendo_Facil_Activator::activate();
	update_option( 'af_db_schema_version', $target_schema_version, false );
}
add_action( 'admin_init', 'arriendo_facil_maybe_upgrade_schema' );
function arriendo_facil_remove_rentabilizar_cta() {
	if ( is_admin() ) {
		return;
	}

	$script = "(function(){
		function shouldRemove(el){
			if(!el){ return false; }
			var text = (el.textContent || '').toLowerCase().replace(/\s+/g,' ').trim();
			return text.indexOf('quiero rentabilizar mi propiedad') !== -1 || text.indexOf('quiero rentabilizar') !== -1;
		}

		function removeNodes(){
			var nodes = document.querySelectorAll('a,button');
			for(var i=0; i<nodes.length; i++){
				if(shouldRemove(nodes[i])){
					nodes[i].remove();
				}
			}
		}

		if(document.readyState === 'loading'){
			document.addEventListener('DOMContentLoaded', removeNodes);
		}else{
			removeNodes();
		}
	})();";

	wp_register_script( 'af-frontend-cleanup', '', array(), ARRIENDO_FACIL_VERSION, true );
	wp_enqueue_script( 'af-frontend-cleanup' );
	wp_add_inline_script( 'af-frontend-cleanup', $script );
}
add_action( 'wp_enqueue_scripts', 'arriendo_facil_remove_rentabilizar_cta', 30 );

/**
 * WP-Cron callback: processes pending AI queue tasks in the background.
 */
add_action( 'af_process_ai_queue', array( 'Arriendo_Facil_AI_Service', 'process_queued_ai_tasks' ) );

/**
 * REST endpoint: returns the status of a queued AI task.
 *
 * GET /wp-json/af/v1/ai-queue/{id}
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'af/v1',
			'/ai-queue/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => function ( WP_REST_Request $request ) {
					$queue_id = absint( $request->get_param( 'id' ) );
					$service  = new Arriendo_Facil_AI_Service();
					$status   = $service->get_queue_status( $queue_id );

					if ( is_wp_error( $status ) ) {
						return new WP_REST_Response( array( 'error' => $status->get_error_message() ), 404 );
					}

					return new WP_REST_Response( $status, 200 );
				},
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}
);


