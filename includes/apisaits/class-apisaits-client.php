<?php
/**
 * APISaits Client — cliente HTTP nativo para Google Custom Search e Indexing API.
 *
 * @package Arriendo_Facil\APISaits
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APISaits_Client {

	const SEARCH_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	private $config;

	public function __construct( APISaits_Config $config ) {
		$this->config = $config;
	}

	/**
	 * Google Custom Search API (GET).
	 * Cachea la respuesta por query para reducir cuota y latencia.
	 */
	public function search( $query ) {
		$creds     = $this->config->get_credentials();
		$endpoints = $this->config->get_endpoints();
		$options   = $this->config->get_options();

		if ( empty( $creds['api_key'] ) || empty( $creds['search_engine_id'] ) ) {
			return new WP_Error( 'apisaits_missing_creds', 'Faltan credenciales en setmatt.xml' );
		}

		$cache_key = 'apisaits_search_' . md5( strtolower( $query ) . '|' . (int) $options['result_limit'] );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = add_query_arg(
			array(
				'key' => $creds['api_key'],
				'cx'  => $creds['search_engine_id'],
				'q'   => rawurlencode( $query ),
				'num' => (int) $options['result_limit'],
			),
			$endpoints['search']
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => (int) $options['timeout'],
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		$result = $this->handle_response( $response );

		if ( ! is_wp_error( $result ) ) {
			$ttl = (int) apply_filters( 'apisaits_search_cache_ttl', self::SEARCH_CACHE_TTL );
			set_transient( $cache_key, $result, $ttl );
		}

		return $result;
	}

	/**
	 * Google Indexing API (POST con OAuth Bearer). No se cachea (mutacion).
	 */
	public function submit_url_to_index( array $payload ) {
		$creds     = $this->config->get_credentials();
		$endpoints = $this->config->get_endpoints();
		$options   = $this->config->get_options();

		if ( empty( $creds['oauth_token'] ) ) {
			return new WP_Error( 'apisaits_missing_token', 'Falta OAuth token en setmatt.xml' );
		}

		$response = wp_remote_post(
			$endpoints['indexing'],
			array(
				'timeout' => (int) $options['timeout'],
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $creds['oauth_token'],
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		return $this->handle_response( $response );
	}

	/**
	 * Normaliza respuestas HTTP. Devuelve array decodificado o WP_Error.
	 */
	private function handle_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'apisaits_http_error', $msg, array( 'status' => $code, 'body' => $data ) );
		}

		return $data;
	}
}
