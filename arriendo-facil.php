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

/**
 * Determines if chatbot should be shown in current frontend request.
 *
 * @return bool
 */
function arriendo_facil_should_show_chatbot() {
	if ( is_admin() ) {
		return false;
	}

	return is_front_page() || is_home() || is_post_type_archive( 'accommodation' ) || is_singular( 'accommodation' );
}

/**
 * Enqueues frontend chatbot assets on accommodation pages.
 */
function arriendo_facil_enqueue_chatbot_assets() {
	if ( ! arriendo_facil_should_show_chatbot() ) {
		return;
	}

	$chatbot_css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/frontend-chatbot.css';
	$chatbot_js_path  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/frontend-chatbot.js';
	$chatbot_css_ver  = file_exists( $chatbot_css_path ) ? (string) filemtime( $chatbot_css_path ) : ARRIENDO_FACIL_VERSION;
	$chatbot_js_ver   = file_exists( $chatbot_js_path ) ? (string) filemtime( $chatbot_js_path ) : ARRIENDO_FACIL_VERSION;

	wp_enqueue_style(
		'af-chatbot-frontend',
		ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/frontend-chatbot.css',
		array(),
		$chatbot_css_ver
	);

	wp_enqueue_script(
		'af-chatbot-frontend',
		ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/frontend-chatbot.js',
		array(),
		$chatbot_js_ver,
		true
	);

	$accommodation_posts = get_posts(
		array(
			'post_type'      => 'accommodation',
			'post_status'    => 'publish',
			'numberposts'    => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'suppress_filters' => false,
		)
	);

	$accommodations = array();
	foreach ( $accommodation_posts as $accommodation_post ) {
		$accommodations[] = array(
			'id'    => (int) $accommodation_post->ID,
			'title' => get_the_title( $accommodation_post->ID ),
		);
	}

	$current_accommodation_id = is_singular( 'accommodation' ) ? (int) get_queried_object_id() : 0;

	wp_localize_script(
		'af-chatbot-frontend',
		'afChatbot',
		array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'af_guest_frontend_nonce' ),
			'successText'   => __( 'Registro enviado. Pronto nos contactaremos contigo.', 'arriendo-facil' ),
			'errorText'     => __( 'No se pudo enviar el registro. Intenta nuevamente.', 'arriendo-facil' ),
			'sendingText'   => __( 'Enviando...', 'arriendo-facil' ),
			'buttonText'    => __( 'Enviar', 'arriendo-facil' ),
			'welcomeText'   => __( 'Buenos dias, como podemos ayudarte?', 'arriendo-facil' ),
			'doneText'      => __( 'Perfecto, ya tengo tus datos. Estoy registrando tu solicitud...', 'arriendo-facil' ),
			'accommodations' => $accommodations,
			'currentAccommodationId' => $current_accommodation_id,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'arriendo_facil_enqueue_chatbot_assets' );

/**
 * Renders frontend chatbot widget in accommodation pages.
 */
function arriendo_facil_render_chatbot_widget() {
	static $rendered = false;

	if ( $rendered || ! arriendo_facil_should_show_chatbot() ) {
		return;
	}

	$rendered = true;
	?>
	<div id="af-chatbot-widget" aria-live="polite">
		<button type="button" id="af-chatbot-toggle" aria-expanded="false" aria-controls="af-chatbot-panel">
			<span class="af-chatbot-logo" aria-hidden="true">AF</span>
			<span><?php esc_html_e( 'CHATBOT', 'arriendo-facil' ); ?></span>
		</button>

		<div id="af-chatbot-panel" hidden>
			<div class="af-chatbot-header">
				<strong><?php esc_html_e( 'Asistente Arriendo Facil', 'arriendo-facil' ); ?></strong>
			</div>

			<div id="af-chatbot-messages" class="af-chatbot-messages"></div>

			<div id="af-chatbot-typing" class="af-chatbot-typing" hidden>
				<span class="af-chatbot-dots" aria-hidden="true"><i></i><i></i><i></i></span>
			</div>

			<form id="af-chatbot-form" autocomplete="off">
				<input type="text" id="af-chatbot-input" placeholder="<?php esc_attr_e( 'Escribe tu respuesta', 'arriendo-facil' ); ?>" required />
				<select id="af-chatbot-select" hidden></select>
				<button type="submit" id="af-chatbot-submit"><?php esc_html_e( 'Enviar', 'arriendo-facil' ); ?></button>
			</form>

			<p id="af-chatbot-message"></p>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'arriendo_facil_render_chatbot_widget' );
add_action( 'wp_body_open', 'arriendo_facil_render_chatbot_widget' );
