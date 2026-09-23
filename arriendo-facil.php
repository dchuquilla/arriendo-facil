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

// Módulos heredados — cambiar a true para reactivarlos durante análisis independiente.
if ( ! defined( 'AF_LEGACY_MODULES' ) ) {
	define( 'AF_LEGACY_MODULES', false );
}

$af_composer_autoload = ARRIENDO_FACIL_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $af_composer_autoload ) ) {
	require_once $af_composer_autoload;
}

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-activator.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-text-normalizer.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-identity-validator.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-private-storage.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-idempotency.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-tenancy.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-property-structure.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-billing-ledger.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-document-verification.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-maintenance.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-owner-settlement.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation-wizard.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation-featured-admin.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation-occupied-admin.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation-list-admin.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation-search-api.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-matching-engine.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-cleaning-service.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-docx-template-processor.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-lease.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-lease-operations.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-alerts.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-rental-workflow.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-owner-contact.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-owner-register-api.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-guest.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-property-admin-registration.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-property-admin-demo.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-property-admin-onboarding.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-review.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-ai-service.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-ical-parser.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-ota-sync-manager.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-ota-webhook-handler.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-ota-notifications.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'admin/class-ota-ajax-handlers.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-config.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-clave-acceso.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-xml-factura.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-signer.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-soap-client.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-sri-ride.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-billing-manager.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/billing/class-billing-api.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/apisaits/class-apisaits-config.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/apisaits/class-apisaits-serializer.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/apisaits/class-apisaits-client.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/apisaits/class-apisaits-module.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'admin/class-admin.php';

// Registrar el modulo APISaits (Google Search Integration).
APISaits_Module::register();

register_activation_hook( __FILE__, array( 'Arriendo_Facil_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Arriendo_Facil_Activator', 'deactivate' ) );

/**
 * Applies runtime PHP limits used by owner PDF uploads.
 *
 * Emits an admin notice when a directive cannot be raised so that hosting
 * misconfigurations surface instead of silently being swallowed.
 */
function arriendo_facil_apply_runtime_upload_limits() {
	if ( ! function_exists( 'ini_set' ) ) {
		return;
	}

	$failed = array();

	if ( false === ini_set( 'upload_max_filesize', '12M' ) ) {
		$failed[] = 'upload_max_filesize';
	}
	if ( false === ini_set( 'post_max_size', '36M' ) ) {
		$failed[] = 'post_max_size';
	}
	if ( false === ini_set( 'memory_limit', '256M' ) ) {
		$failed[] = 'memory_limit';
	}
	if ( false === ini_set( 'max_execution_time', '120' ) ) {
		$failed[] = 'max_execution_time';
	}

	if ( ! empty( $failed ) && is_admin() ) {
		add_action(
			'admin_notices',
			static function () use ( $failed ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				echo '<div class="notice notice-warning is-dismissible"><p>' .
					esc_html(
						sprintf(
							/* translators: %s: comma-separated list of PHP directives that could not be changed */
							__( 'Arriendo Fácil: no se pudo elevar la configuración PHP para: %s. Verifica la configuración del hosting (php.ini o .htaccess).', 'arriendo-facil' ),
							implode( ', ', $failed )
						)
					) .
					'</p></div>';
			}
		);
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
 * Registers OTA sync cron jobs.
 */
function arriendo_facil_register_cron_jobs() {
	// OTA synchronization belongs only to the retired marketplace model.
	if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) {
		add_action( 'af_sync_ota_availability', array( 'Arriendo_Facil_OTA_Sync_Manager', 'process_scheduled_sync' ) );
		add_action( 'af_retry_ota_sync', array( 'Arriendo_Facil_OTA_Sync_Manager', 'process_retry_sync' ), 10, 2 );
		if ( ! wp_next_scheduled( 'af_sync_ota_availability' ) ) {
			wp_schedule_event( time(), 'every_30_minutes', 'af_sync_ota_availability' );
		}
	} else {
		wp_clear_scheduled_hook( 'af_sync_ota_availability' );
		wp_clear_scheduled_hook( 'af_retry_ota_sync' );
	}

	// Daily purge of expired idempotency keys.
	add_action( 'af_idempotency_purge', array( 'Arriendo_Facil_Idempotency', 'purge_expired' ) );
	if ( ! wp_next_scheduled( 'af_idempotency_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'af_idempotency_purge' );
	}

	// Daily review dispatch for lease-based, tokenized ratings.
	if ( ! wp_next_scheduled( 'af_review_dispatch_cron' ) ) {
		wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'daily', 'af_review_dispatch_cron' );
	}

	// Daily overdue flagging for outstanding charges.
	if ( ! wp_next_scheduled( 'af_flag_overdue_charges' ) ) {
		wp_schedule_event( time() + 20 * MINUTE_IN_SECONDS, 'daily', 'af_flag_overdue_charges' );
	}

	// Automatic monthly billing: canon + alícuota + servicios ya cargados por lectura.
	// Se ejecuta a diario porque generate_monthly_charges() es idempotente (clave única
	// lease+tipo+periodo), así que basta con que corra una vez al mes real sin lógica de fechas.
	add_action( 'af_generate_monthly_charges_cron', array( 'Arriendo_Facil_Billing_Ledger', 'generate_monthly_charges' ) );
	if ( ! wp_next_scheduled( 'af_generate_monthly_charges_cron' ) ) {
		wp_schedule_event( time() + 30 * MINUTE_IN_SECONDS, 'daily', 'af_generate_monthly_charges_cron' );
	}

	// Automatic daily reminders: pending docs + lease renewal near end.
	if ( ! wp_next_scheduled( 'af_guest_reminders_cron' ) ) {
		wp_schedule_event( time() + 25 * MINUTE_IN_SECONDS, 'daily', 'af_guest_reminders_cron' );
	}

	// Automatic daily reminders: property-admin self-registration onboarding.
	if ( ! wp_next_scheduled( 'af_admin_profile_reminders_cron' ) ) {
		wp_schedule_event( time() + 35 * MINUTE_IN_SECONDS, 'daily', 'af_admin_profile_reminders_cron' );
	}

	// Daily digest of pending operational alerts.
	if ( ! wp_next_scheduled( 'af_alerts_email_cron' ) ) {
		wp_schedule_event( time() + 40 * MINUTE_IN_SECONDS, 'daily', 'af_alerts_email_cron' );
	}
}

/**
 * Registers custom cron interval for every 30 minutes.
 */
function arriendo_facil_add_cron_intervals( $schedules ) {
	$schedules['every_30_minutes'] = array(
		'interval' => 30 * MINUTE_IN_SECONDS,
		'display' => esc_html__( 'Every 30 Minutes', 'arriendo-facil' ),
	);

	return $schedules;
}
add_filter( 'cron_schedules', 'arriendo_facil_add_cron_intervals' );

/**
 * Initialises all plugin components.
 */
function arriendo_facil_init() {
	load_plugin_textdomain( 'arriendo-facil', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	Arriendo_Facil_Activator::ensure_tenant_role();
	Arriendo_Facil_Activator::ensure_owner_role();

	// Register cron jobs
	arriendo_facil_register_cron_jobs();

	// Instantiate each component defensively so a missing/renamed class does
	// not tumble the entire site with a fatal error during plugin_loaded.
	$components = array(
		'Arriendo_Facil_Accommodation',
		'Arriendo_Facil_Property_Structure',
		'Arriendo_Facil_Accommodation_Wizard',
		'Arriendo_Facil_Accommodation_Featured_Admin',
		'Arriendo_Facil_Accommodation_Occupied_Admin',
		'Arriendo_Facil_Accommodation_List_Admin',
		'Arriendo_Facil_Cleaning_Service',
		'Arriendo_Facil_Maintenance',
		'Arriendo_Facil_Lease',
		'Arriendo_Facil_Lease_Operations',
		'Arriendo_Facil_Billing_Ledger',
		'Arriendo_Facil_Rental_Workflow',
		'Arriendo_Facil_Owner_Contact',
		'Arriendo_Facil_Owner_Settlement',
		'Arriendo_Facil_Guest',
		'Arriendo_Facil_Property_Admin_Registration',
		'Arriendo_Facil_Property_Admin_Onboarding',
		'Arriendo_Facil_Document_Verification',
		'Arriendo_Facil_Review',
		'Arriendo_Facil_Billing_API',
		'Arriendo_Facil_Alerts',
		'Arriendo_Facil_Admin',
	);

	// Captación/marketplace: sin catálogo público ni OTAs en el modelo de administración.
	if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) {
		$components[] = 'Arriendo_Facil_Accommodation_Search_API';
		$components[] = 'Arriendo_Facil_Owner_Register_API';
		$components[] = 'Arriendo_Facil_OTA_Webhook_Handler';
		$components[] = 'Arriendo_Facil_OTA_Notifications';
		$components[] = 'Arriendo_Facil_OTA_AJAX_Handlers';
	}

	foreach ( $components as $component_class ) {
		if ( class_exists( $component_class ) ) {
			new $component_class();
		} elseif ( function_exists( 'error_log' ) ) {
			error_log( sprintf( '[arriendo-facil] Skipped missing component class: %s', $component_class ) );
		}
	}
}
add_action( 'plugins_loaded', 'arriendo_facil_init' );

/**
 * Re-runs the per-user capability self-heal on `init`. `is_user_logged_in()`
 * and `wp_get_current_user()` are unreliable during `plugins_loaded` (the
 * auth cookie hasn't been validated into `$current_user` yet), so the copy
 * called from `ensure_owner_role()` above silently no-ops on every request.
 * `admin_menu` (where WordPress caches the capability check for each
 * registered page) fires after `init`, so this timing is early enough.
 *
 * @return void
 */
function arriendo_facil_heal_current_user_capabilities_on_init() {
	if ( class_exists( 'Arriendo_Facil_Activator' ) ) {
		Arriendo_Facil_Activator::heal_current_user_capabilities();
	}
}
add_action( 'init', 'arriendo_facil_heal_current_user_capabilities_on_init', 5 );

/**
 * Detects drift on the lease table that would otherwise be hidden once the
 * stored schema version matches the target. Each check is cheap and the
 * activator migration is idempotent (CREATE IF NOT EXISTS + column-existence
 * checks), so re-running it is always safe.
 *
 * @return bool True when the lease table is missing columns the code needs.
 */
function arriendo_facil_has_lease_schema_drift() {
	if ( ! class_exists( 'Arriendo_Facil_Activator' ) ) {
		return false;
	}

	global $wpdb;
	$leases_table = $wpdb->prefix . 'af_leases';
	$required     = array( 'template_attachment_id', 'payment_due_day' );

	foreach ( $required as $column_name ) {
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$leases_table,
				$column_name
			)
		);
		if ( ! (int) $found ) {
			return true;
		}
	}

	return false;
}

/**
 * Runs one-time schema upgrades for ZIP-based plugin updates.
 *
 * This ensures new tables/columns are created even when the plugin is updated
 * from ZIP without triggering activation hooks. It also re-runs the
 * idempotent migration when the leases table is missing columns required by
 * the contracts module, so environments that copied the schema version option
 * before running the ALTERs (schema drift) self-heal on the next admin visit.
 */
function arriendo_facil_maybe_upgrade_schema() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$target_schema_version = '2026-09-alerts-v1';
	$current_schema_version = (string) get_option( 'af_db_schema_version', '' );

	if ( $current_schema_version === $target_schema_version && ! arriendo_facil_has_lease_schema_drift() ) {
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
