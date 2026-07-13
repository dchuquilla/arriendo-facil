<?php
/**
 * APISaits Config — parser de setmatt.xml con cache en transients.
 *
 * Modulo integrado en Arriendo Facil.
 *
 * @package Arriendo_Facil\APISaits
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton que carga y expone la configuracion de setmatt.xml.
 *
 * Estrategia de cache:
 *  1) Intenta leer un transient de WordPress (get_transient).
 *  2) Si existe, valida el mtime del XML: si el archivo cambio, invalida el cache.
 *  3) Si no hay cache o esta obsoleto, parsea el XML y guarda con set_transient.
 *
 * Filtros:
 *  - apisaits_cache_ttl  (int, segundos) — TTL del transient.
 *  - apisaits_config_path (string)       — Ruta absoluta al setmatt.xml.
 */
class APISaits_Config {

	const CACHE_KEY   = 'apisaits_config_v1';
	const DEFAULT_TTL = 12 * HOUR_IN_SECONDS;

	private static $instance = null;
	private $data   = array();
	private $loaded = false;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load();
	}

	/**
	 * Ruta al archivo de configuracion.
	 * Por defecto: raiz del plugin arriendo-facil / setmatt.xml.
	 */
	public static function get_config_path() {
		$default = trailingslashit( ARRIENDO_FACIL_PLUGIN_DIR ) . 'setmatt.xml';
		return (string) apply_filters( 'apisaits_config_path', $default );
	}

	/**
	 * Purga el transient. Llamar tras editar setmatt.xml en produccion.
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
		self::$instance = null;
	}

	/**
	 * Flujo: transient -> mtime check -> parse XML -> cachear.
	 */
	private function load() {
		$path = self::get_config_path();
		if ( ! file_exists( $path ) ) {
			return;
		}

		$current_mtime = filemtime( $path );
		$cached        = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && isset( $cached['mtime'], $cached['data'] ) && (int) $cached['mtime'] === (int) $current_mtime ) {
			$this->data   = $cached['data'];
			$this->loaded = true;
			return;
		}

		$parsed = $this->parse_xml_from_disk( $path );
		if ( false === $parsed ) {
			return;
		}

		$this->data   = $parsed;
		$this->loaded = true;

		$ttl = (int) apply_filters( 'apisaits_cache_ttl', self::DEFAULT_TTL );
		set_transient(
			self::CACHE_KEY,
			array(
				'mtime' => $current_mtime,
				'data'  => $parsed,
			),
			$ttl
		);
	}

	/**
	 * Lee y parsea setmatt.xml de forma segura (WP_Filesystem + XXE guard).
	 *
	 * @param string $path Ruta absoluta al XML.
	 * @return array|false
	 */
	private function parse_xml_from_disk( $path ) {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$raw = $wp_filesystem->get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return false;
		}

		// XXE guard compatible con PHP >= 8 (libxml_disable_entity_loader es no-op / deprecated).
		if ( \PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) ) {
			$prev_loader = libxml_disable_entity_loader( true );
		}
		libxml_use_internal_errors( true );

		$xml = simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );

		if ( \PHP_VERSION_ID < 80000 && isset( $prev_loader ) ) {
			libxml_disable_entity_loader( $prev_loader );
		}

		if ( false === $xml ) {
			error_log( '[APISaits] Error parseando setmatt.xml: ' . print_r( libxml_get_errors(), true ) );
			libxml_clear_errors();
			return false;
		}

		return array(
			'credentials' => array(
				'api_key'          => sanitize_text_field( (string) $xml->credentials->api_key ),
				'search_engine_id' => sanitize_text_field( (string) $xml->credentials->search_engine_id ),
				'oauth_token'      => sanitize_text_field( (string) $xml->credentials->oauth_token ),
			),
			'endpoints'   => array(
				'search'   => esc_url_raw( (string) $xml->endpoints->search ),
				'indexing' => esc_url_raw( (string) $xml->endpoints->indexing ),
			),
			'mapping'     => $this->parse_mapping( $xml->mapping ),
			'options'     => array(
				'timeout'      => absint( (string) $xml->options->timeout ) ?: 15,
				'result_limit' => absint( (string) $xml->options->result_limit ) ?: 10,
			),
		);
	}

	/**
	 * Convierte <mapping><field wp="..." google="..." /></mapping> en array asociativo.
	 */
	private function parse_mapping( $mapping_node ) {
		$rules = array();
		if ( ! isset( $mapping_node->field ) ) {
			return $rules;
		}
		foreach ( $mapping_node->field as $field ) {
			$wp     = sanitize_key( (string) $field['wp'] );
			$google = sanitize_key( (string) $field['google'] );
			if ( $wp && $google ) {
				$rules[ $wp ] = $google;
			}
		}
		return $rules;
	}

	public function is_loaded()       { return $this->loaded; }
	public function get_credentials() { return isset( $this->data['credentials'] ) ? $this->data['credentials'] : array(); }
	public function get_endpoints()   { return isset( $this->data['endpoints'] )   ? $this->data['endpoints']   : array(); }
	public function get_mapping()     { return isset( $this->data['mapping'] )     ? $this->data['mapping']     : array(); }
	public function get_options()     { return isset( $this->data['options'] )     ? $this->data['options']     : array(); }
}
