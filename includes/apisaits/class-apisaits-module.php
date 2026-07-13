<?php
/**
 * APISaits Module — bootstrap del modulo de Google Search dentro de Arriendo Facil.
 *
 * Registra endpoints AJAX y expone el nonce al frontend.
 *
 * @package Arriendo_Facil\APISaits
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APISaits_Module {

	/**
	 * Registra todos los hooks del modulo. Se invoca desde arriendo-facil.php.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'boot' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Inicializacion diferida: verifica configuracion y registra AJAX.
	 */
	public static function boot() {
		$config = APISaits_Config::get_instance();
		if ( ! $config->is_loaded() ) {
			error_log( '[APISaits] No se pudo cargar setmatt.xml en: ' . APISaits_Config::get_config_path() );
			return;
		}

		add_action( 'wp_ajax_apisaits_search',        array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_apisaits_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_apisaits_index_url',     array( __CLASS__, 'ajax_index_url' ) );
		add_action( 'wp_ajax_apisaits_flush_cache',   array( __CLASS__, 'ajax_flush_cache' ) );
	}

	/**
	 * Expone ajax_url + nonce al frontend.
	 */
	public static function enqueue_scripts() {
		wp_register_script( 'apisaits-frontend', false, array(), ARRIENDO_FACIL_VERSION, true );
		wp_enqueue_script( 'apisaits-frontend' );

		wp_localize_script(
			'apisaits-frontend',
			'APISaitsData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'apisaits_nonce' ),
			)
		);
	}

	/**
	 * AJAX: busqueda via Google Custom Search API.
	 */
	public static function ajax_search() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'apisaits_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Nonce invalido' ), 403 );
		}

		$query = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( '' === $query ) {
			wp_send_json_error( array( 'message' => 'Query vacio' ), 400 );
		}

		$client   = new APISaits_Client( APISaits_Config::get_instance() );
		$response = $client->search( $query );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 500 );
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: envia URL a Google Indexing API (solo administradores).
	 */
	public static function ajax_index_url() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permisos insuficientes' ), 403 );
		}
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'apisaits_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Nonce invalido' ), 403 );
		}

		$url    = isset( $_POST['url'] )         ? esc_url_raw( wp_unslash( $_POST['url'] ) )                 : '';
		$action = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : 'URL_UPDATED';

		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => 'URL vacia' ), 400 );
		}

		$serializer = new APISaits_Serializer( APISaits_Config::get_instance() );
		$payload    = $serializer->build_indexing_payload( $url, $action );

		$client   = new APISaits_Client( APISaits_Config::get_instance() );
		$response = $client->submit_url_to_index( $payload );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 500 );
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: purga manual del transient (solo administradores).
	 */
	public static function ajax_flush_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permisos insuficientes' ), 403 );
		}
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'apisaits_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Nonce invalido' ), 403 );
		}

		APISaits_Config::flush_cache();
		wp_send_json_success( array( 'message' => 'Cache purgado' ) );
	}
}
