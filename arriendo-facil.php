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

	wp_enqueue_style(
		'af-chatbot-frontend',
		ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/frontend-chatbot.css',
		array(),
		ARRIENDO_FACIL_VERSION
	);

	wp_enqueue_script(
		'af-chatbot-frontend',
		ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/frontend-chatbot.js',
		array(),
		ARRIENDO_FACIL_VERSION,
		true
	);

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
			<span><?php esc_html_e( 'Arrienda ahora', 'arriendo-facil' ); ?></span>
		</button>

		<div id="af-chatbot-panel" hidden>
			<div class="af-chatbot-header">
				<strong><?php esc_html_e( 'Arrienda ahora', 'arriendo-facil' ); ?></strong>
				<p><?php esc_html_e( 'Completa tus datos y te contactamos para continuar tu arriendo.', 'arriendo-facil' ); ?></p>
			</div>

			<form id="af-chatbot-form">
				<input type="text" name="name" placeholder="<?php esc_attr_e( 'Nombre completo', 'arriendo-facil' ); ?>" required />
				<input type="email" name="email" placeholder="<?php esc_attr_e( 'Correo', 'arriendo-facil' ); ?>" required />
				<input type="text" name="phone" placeholder="<?php esc_attr_e( 'Telefono', 'arriendo-facil' ); ?>" maxlength="10" required />
				<input type="text" name="id_number" placeholder="<?php esc_attr_e( 'Documento', 'arriendo-facil' ); ?>" maxlength="10" required />
				<input type="number" name="mascotas" placeholder="<?php esc_attr_e( 'Mascotas', 'arriendo-facil' ); ?>" min="0" max="10" required />
				<input type="text" name="referencia_personal_1" placeholder="<?php esc_attr_e( 'Referencia personal 1', 'arriendo-facil' ); ?>" required />
				<input type="text" name="referencia_personal_2" placeholder="<?php esc_attr_e( 'Referencia personal 2', 'arriendo-facil' ); ?>" required />
				<input type="number" name="personas_viviran" placeholder="<?php esc_attr_e( 'Cuantas personas viviran', 'arriendo-facil' ); ?>" min="1" max="10" required />
				<button type="submit" id="af-chatbot-submit"><?php esc_html_e( 'Enviar', 'arriendo-facil' ); ?></button>
			</form>

			<p id="af-chatbot-message"></p>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'arriendo_facil_render_chatbot_widget' );
add_action( 'wp_body_open', 'arriendo_facil_render_chatbot_widget' );
