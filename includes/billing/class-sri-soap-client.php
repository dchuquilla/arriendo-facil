<?php
/**
 * SRI Ecuador SOAP web-service client.
 *
 * Communicates with the SRI offline comprobantes web services using raw
 * SOAP over HTTP (via WordPress's wp_remote_post). No ext-soap dependency.
 *
 * Two-step process
 * ----------------
 * 1. enviar()     → validarComprobante   → SRI returns RECIBIDA | DEVUELTA
 * 2. autorizar()  → autorizacionComprobante → SRI returns AUTORIZADO | NO AUTORIZADO
 *
 * WSDL endpoints
 * ──────────────
 * Pruebas    https://celcer.sri.gob.ec/comprobantes-electronicos-ws/
 * Producción https://cel.sri.gob.ec/comprobantes-electronicos-ws/
 *
 * @package Arriendo_Facil\Billing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_SRI_Soap_Client
 */
class Arriendo_Facil_SRI_Soap_Client {

	// ─── Service path constants ──────────────────────────────────────────────

	const WS_PATH_RECEPCION    = 'RecepcionComprobantesOffline';
	const WS_PATH_AUTORIZACION = 'AutorizacionComprobantesOffline';

	const SOAP_NS_ENV    = 'http://schemas.xmlsoap.org/soap/envelope/';
	const SOAP_NS_RECEP  = 'http://ec.gob.sri.ws.recepcion';
	const SOAP_NS_AUTOR  = 'http://ec.gob.sri.ws.autorizacion';
	const SOAP_NS_QUERY  = 'http://ec.gob.sri.ws.consulta';

	/** @var string SRI base URL for this instance (resolved from ambiente). */
	private $base_url;

	/** @var string Ambiente code: '1' = pruebas, '2' = producción. */
	private $ambiente;

	/** @var int HTTP timeout in seconds. */
	private $timeout;

	/** @var int Maximum immediate retries for transient errors. */
	private $max_retries;

	/**
	 * Constructor.
	 *
	 * @param string $ambiente '1' (pruebas) or '2' (producción).
	 * @param int    $timeout  HTTP timeout in seconds (default 30).
	 */
	public function __construct( string $ambiente = '1', int $timeout = 0 ) {
		$this->ambiente = $ambiente;
		$this->base_url = ( '2' === $ambiente )
			? 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/'
			: 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/';

		$config             = class_exists( 'Arriendo_Facil_SRI_Config' ) ? Arriendo_Facil_SRI_Config::get() : array();
		$this->timeout      = $timeout > 0 ? $timeout : (int) ( $config['sri_soap_timeout'] ?? 30 );
		$this->max_retries  = (int) ( $config['sri_soap_max_retries'] ?? 3 );
	}

	// ─── Public API ──────────────────────────────────────────────────────────

	/**
	 * Returns the reception endpoint URL (for display / logging).
	 *
	 * @return string
	 */
	public function recepcion_url(): string {
		return $this->base_url . self::WS_PATH_RECEPCION;
	}

	/**
	 * Returns the authorization endpoint URL (for display / logging).
	 *
	 * @return string
	 */
	public function autorizacion_url(): string {
		return $this->base_url . self::WS_PATH_AUTORIZACION;
	}

	/**
	 * Sends a signed XML to the SRI reception service.
	 *
	 * @param string $xml_firmado Signed XML (UTF-8 string).
	 * @return array|WP_Error {
	 *   estado   string  'RECIBIDA' | 'DEVUELTA'
	 *   mensajes array   [ ['identificador' => '...', 'mensaje' => '...', 'tipo' => '...'], … ]
	 * }
	 */
	public function enviar( string $xml_firmado ) {
		$body = $this->build_recepcion_envelope( $xml_firmado );
		$raw  = $this->http_post( $this->recepcion_url(), $body );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return $this->parse_recepcion_response( $raw );
	}

	/**
	 * Queries the SRI authorization service for a previously sent document.
	 *
	 * @param string $clave_acceso 49-digit access key.
	 * @return array|WP_Error {
	 *   estado              string  'AUTORIZADO' | 'NO AUTORIZADO'
	 *   numero_autorizacion string
	 *   fecha_autorizacion  string  ISO datetime
	 *   ambiente            string  'PRUEBAS' | 'PRODUCCION'
	 *   xml_autorizacion    string  Authorized XML (CDATA from SRI)
	 *   mensajes            array
	 * }
	 */
	public function autorizar( string $clave_acceso ) {
		$body = $this->build_autorizacion_envelope( $clave_acceso );
		$raw  = $this->http_post( $this->autorizacion_url(), $body );

		if ( is_wp_error( $raw ) ) {
			// El endpoint autorizacionComprobante del SRI (especialmente en el entorno
			// de pruebas celcer) devuelve HTTP 500 con EntidadNoExisteException cuando
			// el comprobante existe en la cola de recepción (RECIBIDA) pero todavía no
			// tiene un registro en la tabla de autorizaciones (procesamiento asíncrono).
			// En lugar de marcar la factura como error, la tratamos como EN PROCESO
			// para que el cron de reintentos la consulte más tarde.
			if ( 'sri_http_500' === $raw->get_error_code() ) {
				return new WP_Error(
					'sri_en_proceso',
					'SRI devolvió HTTP 500 en autorización — comprobante posiblemente en cola. Se reintentará automáticamente.'
				);
			}
			return $raw;
		}

		return $this->parse_autorizacion_response( $raw );
	}

	/**
	 * Queries SRI for contributor/RUC information via SOAP, REST, then web scraping.
	 * Tries multiple methods in order of preference:
	 * 1. SOAP (official service, if available)
	 * 2. REST API (internal endpoint, improved headers)
	 * 3. Web scraping (public HTML interface - most reliable)
	 *
	 * @param string $ruc RUC to query.
	 * @return array|WP_Error {
	 *   razon_social          string
	 *   nombre_comercial      string
	 *   dir_establecimiento   string
	 *   dir_matriz            string
	 *   obligado_contabilidad string (SI/NO)
	 *   estado                string (ACTIVO/INACTIVO/SUSPENDIDO/CANCELADO)
	 *   tipo_contribuyente    string
	 *   agente_retencion      string (SI/NO)
	 * }
	 */
	public function consultar_contribuyente( string $ruc ) {
		$ruc = preg_replace( '/\D/', '', $ruc );

		if ( 13 !== strlen( $ruc ) ) {
			return new WP_Error( 'invalid_ruc', __( 'El RUC debe tener exactamente 13 dígitos.', 'arriendo-facil' ) );
		}

		// Try SOAP first (official method).
		$soap_result = $this->consultar_contribuyente_soap( $ruc );
		if ( ! is_wp_error( $soap_result ) ) {
			$this->log( 'info', sprintf( 'SOAP consulta succeeded for RUC %s', $ruc ) );
			return $soap_result;
		}

		$this->log( 'info', sprintf( 'SOAP consulta failed for RUC %s, falling back to REST', $ruc ) );

		// Fallback to REST with improved headers.
		$rest_result = $this->consultar_contribuyente_rest( $ruc );
		if ( ! is_wp_error( $rest_result ) ) {
			$this->log( 'info', sprintf( 'REST consulta succeeded for RUC %s', $ruc ) );
			return $rest_result;
		}

		$this->log( 'info', sprintf( 'REST consulta failed for RUC %s, falling back to web scraping', $ruc ) );

		// Final fallback: web scraping from public SRI interface (most reliable).
		return $this->consultar_contribuyente_webscrape( $ruc );
	}

	// ─── Contributor query (SOAP + REST fallback) ────────────────────────────

	/**
	 * Attempts to query contributor via SOAP.
	 *
	 * @param string $ruc RUC number.
	 * @return array|WP_Error
	 */
	private function consultar_contribuyente_soap( string $ruc ) {
		$body = $this->build_consulta_contribuyente_envelope( $ruc );
		$raw  = $this->http_post( $this->consulta_url(), $body );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return $this->parse_consulta_contribuyente_response( $raw );
	}

	/**
	 * Fallback: queries contributor via REST API.
	 * Uses two endpoints in sequence:
	 * 1. ConsolidadoContribuyente/obtenerPorNumerosRuc → basic info
	 * 2. Establecimiento/consultarPorNumeroRuc → addresses
	 *
	 * @param string $ruc RUC number.
	 * @return array|WP_Error
	 */
	private function consultar_contribuyente_rest( string $ruc ) {
		// Fetch basic contributor info.
		$contribuyente_data = $this->fetch_consolidado_contribuyente( $ruc );
		if ( is_wp_error( $contribuyente_data ) ) {
			return $contribuyente_data;
		}

		// Fetch establishment details (addresses).
		$establecimientos = $this->fetch_establecimientos( $ruc );
		if ( is_wp_error( $establecimientos ) ) {
			$this->log( 'warning', sprintf( 'Could not fetch establecimientos for RUC %s, proceeding with basic data', $ruc ) );
			$establecimientos = array();
		}

		// Combine and parse data.
		return $this->parse_combined_contributor_data( $contribuyente_data, $establecimientos );
	}

	/**
	 * Fetches contributor info from ConsolidadoContribuyente endpoint.
	 *
	 * @param string $ruc RUC number.
	 * @return array|WP_Error
	 */
	private function fetch_consolidado_contribuyente( string $ruc ) {
		$url = 'https://srienlinea.sri.gob.ec/sri-catastro-sujeto-servicio-internet/rest/ConsolidadoContribuyente/obtenerPorNumerosRuc?ruc=' . $ruc;

		$sslverify = $this->resolve_ssl_verify();

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $this->timeout,
				'redirection' => 2,
				'sslverify'   => $sslverify,
				'headers'     => array(
					'Accept'          => 'application/json',
					'Accept-Language' => 'es-ES,es;q=0.9',
					'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
					'Referer'         => 'https://srienlinea.sri.gob.ec/sri-en-linea/SriRucWeb/ConsultaRuc/Consultas/consultaRuc',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', sprintf( 'REST connection error for RUC %s: %s', $ruc, $response->get_error_message() ) );
			return new WP_Error(
				'sri_rest_connection_error',
				sprintf( __( 'No se pudo conectar con el SRI: %s', 'arriendo-facil' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$this->log( 'error', sprintf( 'SRI ConsolidadoContribuyente HTTP %d for RUC %s', $code, $ruc ) );
			return new WP_Error(
				'sri_rest_http_error',
				sprintf( __( 'El SRI respondió con error HTTP %d', 'arriendo-facil' ), $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			$this->log( 'error', sprintf( 'SRI ConsolidadoContribuyente returned empty body for RUC %s', $ruc ) );
			return new WP_Error( 'sri_rest_empty_response', __( 'El SRI devolvió una respuesta vacía.', 'arriendo-facil' ) );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			$this->log( 'error', sprintf( 'SRI ConsolidadoContribuyente invalid JSON for RUC %s', $ruc ) );
			return new WP_Error( 'sri_rest_invalid_response', __( 'Respuesta inválida del SRI.', 'arriendo-facil' ) );
		}

		return $data;
	}

	/**
	 * Fetches establishment details from Establecimiento endpoint.
	 *
	 * @param string $ruc RUC number.
	 * @return array|WP_Error Array of establishments, or WP_Error on failure.
	 */
	private function fetch_establecimientos( string $ruc ) {
		$url = 'https://srienlinea.sri.gob.ec/sri-catastro-sujeto-servicio-internet/rest/Establecimiento/consultarPorNumeroRuc?numeroRuc=' . $ruc;

		$sslverify = $this->resolve_ssl_verify();

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $this->timeout,
				'redirection' => 2,
				'sslverify'   => $sslverify,
				'headers'     => array(
					'Accept'          => 'application/json',
					'Accept-Language' => 'es-ES,es;q=0.9',
					'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
					'Referer'         => 'https://srienlinea.sri.gob.ec/sri-en-linea/SriRucWeb/ConsultaRuc/Consultas/consultaRuc',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'sri_rest_connection_error',
				$response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'sri_rest_http_error',
				sprintf( 'HTTP %d', $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'sri_rest_invalid_response', 'Invalid JSON response' );
		}

		return $data;
	}

	/**
	 * Returns the SRI contributor query endpoint URL.
	 *
	 * @return string
	 */
	private function consulta_url(): string {
		return $this->base_url . 'ConsultaContribuyente';
	}

	/**
	 * Final fallback: web scrapes the public SRI interface.
	 * This is the most reliable method as it uses the same interface that works
	 * when users consult manually at https://srienlinea.sri.gob.ec/
	 *
	 * @param string $ruc RUC number.
	 * @return array|WP_Error
	 */
	private function consultar_contribuyente_webscrape( string $ruc ) {
		$url = 'https://srienlinea.sri.gob.ec/sri-en-linea/SriRucWeb/ConsultaRuc/Consultas/consultaRuc';

		$sslverify = $this->resolve_ssl_verify();

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => $this->timeout,
				'sslverify'   => $sslverify,
				'redirection' => 5,
				'headers'     => array(
					'Accept'              => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language'     => 'es-ES,es;q=0.9',
					'Accept-Encoding'     => 'gzip, deflate, br',
					'Content-Type'        => 'application/x-www-form-urlencoded',
					'User-Agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
					'Referer'             => 'https://srienlinea.sri.gob.ec/sri-en-linea/SriRucWeb/ConsultaRuc/Consultas/consultaRuc',
					'Origin'              => 'https://srienlinea.sri.gob.ec',
					'X-Requested-With'    => 'XMLHttpRequest',
				),
				'body'        => array(
					'ruc'  => $ruc,
					'tipo' => '1',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'sri_webscrape_connection_error',
				sprintf( __( 'No se pudo conectar con el SRI: %s', 'arriendo-facil' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$this->log( 'error', sprintf( 'SRI web scrape HTTP %d for RUC %s', $code, $ruc ) );
			return new WP_Error(
				'sri_webscrape_http_error',
				sprintf( __( 'El SRI respondió con error HTTP %d', 'arriendo-facil' ), $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'sri_webscrape_empty_response', __( 'El SRI devolvió una respuesta vacía.', 'arriendo-facil' ) );
		}

		return $this->parse_webscrape_response( $body, $ruc );
	}

	/**
	 * Parses HTML response from SRI web interface.
	 * Extracts contributor information from form fields and table data.
	 *
	 * @param string $html HTML response body.
	 * @param string $ruc  RUC for context/logging.
	 * @return array|WP_Error
	 */
	private function parse_webscrape_response( string $html, string $ruc ) {
		libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		$dom->loadHTML( $html );

		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );

		// Extract data from HTML form fields and display areas.
		$razon_social = '';
		$nombre_comercial = '';
		$obligado_contabilidad = 'NO';
		$dir_establecimiento = '';
		$dir_matriz = '';

		// Look for input fields or display elements with RUC data.
		$fields = $xpath->query( '//input[@name="razonSocial"] | //span[contains(@class, "razon")] | //div[contains(@class, "razonSocial")]' );
		if ( $fields && $fields->length > 0 ) {
			$razon_social = trim( (string) $fields->item( 0 )->getAttribute( 'value' ) );
			if ( '' === $razon_social ) {
				$razon_social = trim( (string) $fields->item( 0 )->textContent );
			}
		}

		// Look for business name.
		$fields = $xpath->query( '//input[@name="nombreComercial"] | //span[contains(@class, "comercial")] | //div[contains(@class, "nombreComercial")]' );
		if ( $fields && $fields->length > 0 ) {
			$nombre_comercial = trim( (string) $fields->item( 0 )->getAttribute( 'value' ) );
			if ( '' === $nombre_comercial ) {
				$nombre_comercial = trim( (string) $fields->item( 0 )->textContent );
			}
		}

		// Look for accounting obligation field.
		$fields = $xpath->query( '//input[@name="obligadoContabilidad"] | //input[@name="obligado"]' );
		if ( $fields && $fields->length > 0 ) {
			$obligado = trim( strtoupper( (string) $fields->item( 0 )->getAttribute( 'value' ) ) );
			if ( 'SI' === $obligado || '1' === $obligado || 'TRUE' === $obligado ) {
				$obligado_contabilidad = 'SI';
			}
		}

		// Look for establishment address.
		$fields = $xpath->query( '//input[@name="direccion"] | //input[@name="dirEstablecimiento"]' );
		if ( $fields && $fields->length > 0 ) {
			$dir_establecimiento = trim( (string) $fields->item( 0 )->getAttribute( 'value' ) );
			if ( '' === $dir_establecimiento ) {
				$dir_establecimiento = trim( (string) $fields->item( 0 )->textContent );
			}
		}

		// Look for head office address.
		$fields = $xpath->query( '//input[@name="direccionMatriz"]' );
		if ( $fields && $fields->length > 0 ) {
			$dir_matriz = trim( (string) $fields->item( 0 )->getAttribute( 'value' ) );
			if ( '' === $dir_matriz ) {
				$dir_matriz = trim( (string) $fields->item( 0 )->textContent );
			}
		}

		// Fallback: look in table rows for data.
		if ( '' === $razon_social ) {
			$rows = $xpath->query( '//tr' );
			foreach ( $rows as $row ) {
				$cells = $xpath->query( 'td', $row );
				if ( $cells->length >= 2 ) {
					$label = trim( (string) $cells->item( 0 )->textContent );
					$value = trim( (string) $cells->item( 1 )->textContent );

					if ( preg_match( '/razon|razón/i', $label ) ) {
						$razon_social = $value;
					} elseif ( preg_match( '/comercial|fantasia/i', $label ) ) {
						$nombre_comercial = $value;
					} elseif ( preg_match( '/obligado|contabilidad/i', $label ) ) {
						$obligado_contabilidad = strtoupper( $value ) === 'SI' ? 'SI' : 'NO';
					} elseif ( preg_match( '/dirección|direccion.*establecimiento/i', $label ) ) {
						$dir_establecimiento = $value;
					} elseif ( preg_match( '/matriz/i', $label ) ) {
						$dir_matriz = $value;
					}
				}
			}
		}

		if ( '' === $razon_social ) {
			$this->log( 'error', sprintf( 'Web scrape failed to extract razon_social for RUC %s', $ruc ) );
			return new WP_Error( 'sri_webscrape_parse_error', __( 'No se encontró información para este RUC.', 'arriendo-facil' ) );
		}

		return array(
			'razon_social'          => $razon_social,
			'nombre_comercial'      => $nombre_comercial,
			'dir_establecimiento'   => $dir_establecimiento,
			'dir_matriz'            => $dir_matriz,
			'obligado_contabilidad' => $obligado_contabilidad,
		);
	}

	// ─── SOAP envelope builders ──────────────────────────────────────────────

	/**
	 * Builds the SOAP envelope for validarComprobante.
	 *
	 * @param string $xml_firmado Signed XML.
	 * @return string SOAP XML body.
	 */
	public function build_recepcion_envelope( string $xml_firmado ): string {
		$xml_b64 = base64_encode( $xml_firmado );
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soapenv:Envelope'
			. ' xmlns:soapenv="' . self::SOAP_NS_ENV . '"'
			. ' xmlns:ec="'      . self::SOAP_NS_RECEP . '">'
			. '<soapenv:Header/>'
			. '<soapenv:Body>'
			. '<ec:validarComprobante>'
			. '<xml>' . $xml_b64 . '</xml>'
			. '</ec:validarComprobante>'
			. '</soapenv:Body>'
			. '</soapenv:Envelope>';
	}

	/**
	 * Builds the SOAP envelope for autorizacionComprobante.
	 *
	 * @param string $clave_acceso 49-digit access key.
	 * @return string SOAP XML body.
	 */
	public function build_autorizacion_envelope( string $clave_acceso ): string {
		$clave_safe = htmlspecialchars( $clave_acceso, ENT_XML1, 'UTF-8' );
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soapenv:Envelope'
			. ' xmlns:soapenv="' . self::SOAP_NS_ENV . '"'
			. ' xmlns:ec="'      . self::SOAP_NS_AUTOR . '">'
			. '<soapenv:Header/>'
			. '<soapenv:Body>'
			. '<ec:autorizacionComprobante>'
			. '<claveAccesoComprobante>' . $clave_safe . '</claveAccesoComprobante>'
			. '</ec:autorizacionComprobante>'
			. '</soapenv:Body>'
			. '</soapenv:Envelope>';
	}

	/**
	 * Builds the SOAP envelope for consulta de contribuyente.
	 *
	 * @param string $ruc RUC number.
	 * @return string SOAP XML body.
	 */
	public function build_consulta_contribuyente_envelope( string $ruc ): string {
		$ruc_safe = htmlspecialchars( $ruc, ENT_XML1, 'UTF-8' );
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soapenv:Envelope'
			. ' xmlns:soapenv="' . self::SOAP_NS_ENV . '"'
			. ' xmlns:ec="'      . self::SOAP_NS_QUERY . '">'
			. '<soapenv:Header/>'
			. '<soapenv:Body>'
			. '<ec:consultaContribuyente>'
			. '<ruc>' . $ruc_safe . '</ruc>'
			. '</ec:consultaContribuyente>'
			. '</soapenv:Body>'
			. '</soapenv:Envelope>';
	}

	// ─── Response parsers ────────────────────────────────────────────────────

	/**
	 * Parses the SOAP response from validarComprobante.
	 *
	 * @param string $soap_response Raw SOAP XML response body.
	 * @return array|WP_Error
	 */
	public function parse_recepcion_response( string $soap_response ) {
		$dom = $this->load_soap_body( $soap_response );
		if ( is_wp_error( $dom ) ) {
			return $dom;
		}

		$xpath  = new DOMXPath( $dom );
		$estado = $this->xpath_text( $xpath, '//estado' );

		if ( '' === $estado ) {
			return new WP_Error( 'sri_parse_error', 'Respuesta SRI sin campo <estado>.' );
		}

		$mensajes = $this->parse_mensajes( $xpath, '//mensajes/mensaje' );

		return array(
			'estado'   => $estado,
			'mensajes' => $mensajes,
		);
	}

	/**
	 * Parses the SOAP response from autorizacionComprobante.
	 *
	 * @param string $soap_response Raw SOAP XML response body.
	 * @return array|WP_Error
	 */
	public function parse_autorizacion_response( string $soap_response ) {
		$dom = $this->load_soap_body( $soap_response );
		if ( is_wp_error( $dom ) ) {
			return $dom;
		}

		$xpath              = new DOMXPath( $dom );
		$autorizacion_nodes = $xpath->query( '//autorizaciones/autorizacion' );

		if ( ! $autorizacion_nodes || 0 === $autorizacion_nodes->length ) {
			// SRI has not yet processed the document (asynchronous queue). Caller should
			// keep the invoice as 'enviada' and retry later.
			return new WP_Error( 'sri_en_proceso', 'SRI aún no ha procesado el comprobante. Se reintentará automáticamente.' );
		}

		$auth = $autorizacion_nodes->item( 0 );

		$estado     = $this->xpath_text( $xpath, 'estado',              $auth );
		$num_auth   = $this->xpath_text( $xpath, 'numeroAutorizacion',  $auth );
		$fecha_auth = $this->xpath_text( $xpath, 'fechaAutorizacion',   $auth );
		$ambiente   = $this->xpath_text( $xpath, 'ambiente',            $auth );

		if ( 'EN PROCESO' === strtoupper( $estado ) ) {
			return new WP_Error( 'sri_en_proceso', 'SRI está procesando el comprobante. Se reintentará automáticamente.' );
		}

		// The <comprobante> node may contain the authorized XML in a CDATA section.
		$xml_auth_node = $xpath->query( 'comprobante', $auth )->item( 0 );
		$xml_auth      = $xml_auth_node ? (string) $xml_auth_node->textContent : '';

		$mensajes = $this->parse_mensajes( $xpath, 'mensajes/mensaje', $auth );

		return array(
			'estado'              => $estado,
			'numero_autorizacion' => $num_auth,
			'fecha_autorizacion'  => $fecha_auth,
			'ambiente'            => $ambiente,
			'xml_autorizacion'    => $xml_auth,
			'mensajes'            => $mensajes,
		);
	}

	/**
	 * Parses the SOAP response from consultaContribuyente.
	 *
	 * @param string $soap_response Raw SOAP XML response body.
	 * @return array|WP_Error
	 */
	public function parse_consulta_contribuyente_response( string $soap_response ) {
		$dom = $this->load_soap_body( $soap_response );
		if ( is_wp_error( $dom ) ) {
			return $dom;
		}

		$xpath = new DOMXPath( $dom );

		$contribuyente_nodes = $xpath->query( '//contribuyente' );
		if ( ! $contribuyente_nodes || 0 === $contribuyente_nodes->length ) {
			return new WP_Error( 'sri_contribuyente_not_found', __( 'No se encontró información para este RUC en el SRI.', 'arriendo-facil' ) );
		}

		$contribuyente_node = $contribuyente_nodes->item( 0 );

		$data = array(
			'razonSocial'                    => $this->xpath_text( $xpath, 'razonSocial', $contribuyente_node ),
			'nombreFantasia'                 => $this->xpath_text( $xpath, 'nombreFantasia', $contribuyente_node ),
			'obligadoLlevarContabilidad'     => $this->xpath_text( $xpath, 'obligadoLlevarContabilidad', $contribuyente_node ),
		);

		$establecimientos = $xpath->query( 'establecimientos/establecimiento', $contribuyente_node );
		$data['establecimientos'] = array();
		if ( $establecimientos ) {
			foreach ( $establecimientos as $estab ) {
				$data['establecimientos'][] = array(
					'tipoEstablecimiento' => $this->xpath_text( $xpath, 'tipoEstablecimiento', $estab ),
					'direccionCompleta'   => $this->xpath_text( $xpath, 'direccionCompleta', $estab ),
					'direccion'           => $this->xpath_text( $xpath, 'direccion', $estab ),
				);
			}
		}

		return $this->parse_consulta_contribuyente_data( $data );
	}

	/**
	 * Parses contributor data from array (works for both SOAP and REST responses).
	 * Handles both old SOAP format (with contribuyente wrapper) and REST format.
	 *
	 * @param array $data Contributor data.
	 * @return array|WP_Error
	 */
	/**
	 * Parses combined data from ConsolidadoContribuyente and Establecimiento endpoints.
	 *
	 * @param array $contribuyente_data From ConsolidadoContribuyente endpoint.
	 * @param array $establecimientos   From Establecimiento endpoint.
	 * @return array|WP_Error
	 */
	private function parse_combined_contributor_data( array $contribuyente_data, array $establecimientos ) {
		// Handle ConsolidadoContribuyente response: array of objects, extract first item.
		if ( isset( $contribuyente_data[0] ) && is_array( $contribuyente_data[0] ) ) {
			$contribuyente_data = $contribuyente_data[0];
		}

		// Extract main fields from ConsolidadoContribuyente.
		$razon_social = $this->extract_field( $contribuyente_data, array( 'razonSocial', 'nombreContribuyente', 'nombre' ) );
		$nombre_comercial = $this->extract_field( $contribuyente_data, array( 'nombreFantasia', 'nombreComercial' ) );
		if ( '' === $nombre_comercial ) {
			$nombre_comercial = $razon_social;
		}

		$obligado = strtoupper( $this->extract_field( $contribuyente_data, array( 'obligadoLlevarContabilidad' ) ) );
		$estado = strtoupper( $this->extract_field( $contribuyente_data, array( 'estadoContribuyenteRuc', 'estado' ) ) );
		$tipo_contribuyente = $this->extract_field( $contribuyente_data, array( 'tipoContribuyente' ) );
		$agente_retencion = strtoupper( $this->extract_field( $contribuyente_data, array( 'agenteRetencion' ) ) );

		// Validate RUC status.
		if ( '' === $estado ) {
			$estado = 'ACTIVO'; // Default to ACTIVO if not provided.
		}

		if ( 'ACTIVO' !== $estado ) {
			return new WP_Error(
				'sri_ruc_inactive',
				sprintf(
					__( 'Este RUC está %s en el SRI. No se puede usar para facturación.', 'arriendo-facil' ),
					strtolower( $estado )
				)
			);
		}

		// Extract addresses from Establecimiento endpoint.
		$dir_establecimiento = '';
		$dir_matriz = '';

		if ( is_array( $establecimientos ) && ! empty( $establecimientos ) ) {
			foreach ( $establecimientos as $estab ) {
				if ( ! is_array( $estab ) ) {
					continue;
				}

				$tipo = strtoupper( (string) ( $estab['tipoEstablecimiento'] ?? '' ) );
				$dir = sanitize_text_field( (string) ( $estab['direccionCompleta'] ?? $estab['direccion'] ?? '' ) );

				if ( '' === $dir ) {
					continue;
				}

				// Assign by establishment type.
				if ( 'MATRIZ' === $tipo ) {
					$dir_matriz = $dir;
				}

				// Use first non-empty direction as establecimiento.
				if ( '' === $dir_establecimiento ) {
					$dir_establecimiento = $dir;
				}
			}
		}

		// SRI requirement: dirMatriz cannot be empty. Default to dir_establecimiento.
		if ( '' === $dir_matriz && '' !== $dir_establecimiento ) {
			$dir_matriz = $dir_establecimiento;
		}

		if ( '' === $razon_social ) {
			return new WP_Error( 'sri_no_data', __( 'No se encontró información para este RUC en el SRI.', 'arriendo-facil' ) );
		}

		return array(
			'razon_social'          => sanitize_text_field( $razon_social ),
			'nombre_comercial'      => sanitize_text_field( $nombre_comercial ),
			'dir_establecimiento'   => sanitize_text_field( $dir_establecimiento ),
			'dir_matriz'            => sanitize_text_field( $dir_matriz ),
			'obligado_contabilidad' => ( 'SI' === $obligado || 'NO' === $obligado ) ? $obligado : 'NO',
			'estado'                => $estado,
			'tipo_contribuyente'    => sanitize_text_field( $tipo_contribuyente ),
			'agente_retencion'      => ( 'SI' === $agente_retencion || 'NO' === $agente_retencion ) ? $agente_retencion : 'NO',
		);
	}

	/**
	 * Parses SOAP response for contributor query (legacy, kept for SOAP fallback).
	 *
	 * @param array $data SOAP response data.
	 * @return array|WP_Error
	 */
	private function parse_consulta_contribuyente_data( array $data ) {
		// Handle SOAP response: data wrapped in 'contribuyente' key.
		$c = isset( $data['contribuyente'] ) && is_array( $data['contribuyente'] ) ? $data['contribuyente'] : $data;

		// Extract main fields.
		$razon_social = $this->extract_field( $c, array( 'razonSocial', 'nombreContribuyente', 'nombre' ) );
		$nombre_comercial = $this->extract_field( $c, array( 'nombreFantasia', 'nombreComercial' ) );
		if ( '' === $nombre_comercial ) {
			$nombre_comercial = $razon_social;
		}

		$obligado = strtoupper( $this->extract_field( $c, array( 'obligadoLlevarContabilidad' ) ) );
		$estado = strtoupper( $this->extract_field( $c, array( 'estadoContribuyenteRuc', 'estado' ) ) );
		$tipo_contribuyente = $this->extract_field( $c, array( 'tipoContribuyente' ) );
		$agente_retencion = strtoupper( $this->extract_field( $c, array( 'agenteRetencion' ) ) );

		// Validate RUC status.
		if ( '' === $estado ) {
			$estado = 'ACTIVO';
		}

		if ( 'ACTIVO' !== $estado ) {
			return new WP_Error(
				'sri_ruc_inactive',
				sprintf(
					__( 'Este RUC está %s en el SRI. No se puede usar para facturación.', 'arriendo-facil' ),
					strtolower( $estado )
				)
			);
		}

		// For SOAP, addresses may be embedded. Try to extract.
		$dir_establecimiento = '';
		$dir_matriz = '';

		$establecimientos = isset( $c['establecimientos'] ) && is_array( $c['establecimientos'] ) ? $c['establecimientos'] : array();
		foreach ( $establecimientos as $estab ) {
			if ( ! is_array( $estab ) ) {
				continue;
			}
			$tipo = strtoupper( (string) ( $estab['tipoEstablecimiento'] ?? '' ) );
			$dir = sanitize_text_field( (string) ( $estab['direccionCompleta'] ?? $estab['direccion'] ?? '' ) );
			if ( '' === $dir ) {
				continue;
			}
			if ( 'MATRIZ' === $tipo ) {
				$dir_matriz = $dir;
			}
			if ( '' === $dir_establecimiento ) {
				$dir_establecimiento = $dir;
			}
		}

		// SRI requirement: dirMatriz cannot be empty. Default to dir_establecimiento.
		if ( '' === $dir_matriz && '' !== $dir_establecimiento ) {
			$dir_matriz = $dir_establecimiento;
		}

		if ( '' === $razon_social ) {
			return new WP_Error( 'sri_no_data', __( 'No se encontró información para este RUC en el SRI.', 'arriendo-facil' ) );
		}

		return array(
			'razon_social'          => sanitize_text_field( $razon_social ),
			'nombre_comercial'      => sanitize_text_field( $nombre_comercial ),
			'dir_establecimiento'   => sanitize_text_field( $dir_establecimiento ),
			'dir_matriz'            => sanitize_text_field( $dir_matriz ),
			'obligado_contabilidad' => ( 'SI' === $obligado || 'NO' === $obligado ) ? $obligado : 'NO',
			'estado'                => $estado,
			'tipo_contribuyente'    => sanitize_text_field( $tipo_contribuyente ),
			'agente_retencion'      => ( 'SI' === $agente_retencion || 'NO' === $agente_retencion ) ? $agente_retencion : 'NO',
		);
	}

	// ─── HTTP + parse helpers ────────────────────────────────────────────────

	/**
	 * Sends a raw SOAP request using WordPress HTTP API with structured logging
	 * and immediate retries for transient errors.
	 *
	 * @param string $url  Endpoint URL.
	 * @param string $body SOAP XML body.
	 * @return string|WP_Error Response body string, or WP_Error on failure.
	 */
	private function http_post( string $url, string $body ) {
		$sslverify = $this->resolve_ssl_verify();

		$last_error = null;
		$attempts   = max( 1, $this->max_retries );

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$start = microtime( true );
			$this->log( 'info', sprintf( 'Intento %d/%d → %s', $attempt, $attempts, $url ) );

			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'Content-Type' => 'text/xml; charset=UTF-8',
						'SOAPAction'   => '""',
					),
					'body'      => $body,
					'timeout'   => $this->timeout,
					'sslverify' => $sslverify,
				)
			);

			$elapsed_ms = round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $response ) ) {
				$last_error = $this->classify_connection_error( $response, $elapsed_ms );
				$this->log( 'error', sprintf( '[%dms] %s', $elapsed_ms, $last_error->get_error_message() ) );

				if ( $attempt < $attempts && $this->is_transient_error( $response ) ) {
					$backoff = pow( 2, $attempt );
					sleep( $backoff );
					continue;
				}
				return $last_error;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = (string) wp_remote_retrieve_body( $response );

			$this->log( 'info', sprintf( 'HTTP %d (%dms) - %d bytes', $code, $elapsed_ms, strlen( $raw ) ) );

			if ( $code >= 500 ) {
				$last_error = new WP_Error(
					'sri_http_' . $code,
					sprintf( 'SRI devolvió HTTP %d. El servicio puede estar fuera de línea.', $code ),
					array( 'http_code' => $code, 'elapsed_ms' => $elapsed_ms )
				);
				if ( $attempt < $attempts ) {
					$backoff = pow( 2, $attempt );
					sleep( $backoff );
					continue;
				}
				return $last_error;
			}

			if ( $code >= 400 ) {
				return new WP_Error(
					'sri_http_' . $code,
					sprintf( 'SRI devolvió HTTP %d.', $code ),
					array( 'http_code' => $code, 'elapsed_ms' => $elapsed_ms )
				);
			}

			if ( '' === trim( $raw ) ) {
				$last_error = new WP_Error(
					'sri_empty_body',
					'SRI devolvió HTTP 200 pero con cuerpo vacío (posible timeout parcial).',
					array( 'elapsed_ms' => $elapsed_ms )
				);
				if ( $attempt < $attempts ) {
					$backoff = pow( 2, $attempt );
					sleep( $backoff );
					continue;
				}
				return $last_error;
			}

			return $raw;
		}

		return $last_error ?? new WP_Error( 'sri_http_error', 'Error desconocido de comunicación con SRI.' );
	}

	/**
	 * Resolves SSL verification setting with production safety guard.
	 *
	 * @return bool
	 */
	private function resolve_ssl_verify(): bool {
		if ( '2' === $this->ambiente ) {
			return true;
		}

		if ( ! function_exists( 'apply_filters' ) ) {
			return true;
		}

		return (bool) apply_filters( 'af_sri_sslverify', true );
	}

	/**
	 * Classifies a WP_Error from wp_remote_post into a user-actionable message.
	 *
	 * @param WP_Error $error      WordPress HTTP error.
	 * @param float    $elapsed_ms Elapsed time in ms.
	 * @return WP_Error Classified error with diagnostic data.
	 */
	private function classify_connection_error( WP_Error $error, float $elapsed_ms ): WP_Error {
		$msg  = $error->get_error_message();
		$code = 'sri_connection_error';

		if ( preg_match( '/cURL error 6/i', $msg ) ) {
			return new WP_Error( 'sri_dns_error', 'Error DNS: no se pudo resolver el servidor del SRI. Verifique la conexión a internet.', compact( 'elapsed_ms', 'msg' ) );
		}

		if ( preg_match( '/cURL error 28/i', $msg ) ) {
			return new WP_Error( 'sri_timeout', sprintf( 'Timeout: el SRI no respondió en %ds. Posible congestión del servicio.', $this->timeout ), compact( 'elapsed_ms', 'msg' ) );
		}

		if ( preg_match( '/cURL error 7/i', $msg ) ) {
			return new WP_Error( 'sri_connection_refused', 'Conexión rechazada por el SRI. El servicio puede estar fuera de línea.', compact( 'elapsed_ms', 'msg' ) );
		}

		if ( preg_match( '/ssl|certificate|handshake/i', $msg ) ) {
			return new WP_Error( 'sri_ssl_error', 'Error SSL/TLS con el SRI. Posible problema de certificado del servidor.', compact( 'elapsed_ms', 'msg' ) );
		}

		return new WP_Error( $code, 'Error de comunicación con SRI: ' . $msg, compact( 'elapsed_ms', 'msg' ) );
	}

	/**
	 * Determines if a connection error is transient and worth retrying.
	 *
	 * @param WP_Error $error WordPress HTTP error.
	 * @return bool
	 */
	private function is_transient_error( WP_Error $error ): bool {
		$msg = $error->get_error_message();
		// Timeout (28), connection refused (7), and partial transfer (18) are transient.
		return (bool) preg_match( '/cURL error (7|18|28|56)/i', $msg );
	}

	/**
	 * Logs a structured message for SRI SOAP operations.
	 *
	 * @param string $level   'info' or 'error'.
	 * @param string $message Log message.
	 */
	private function log( string $level, string $message ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		$entry = sprintf( '[SRI SOAP][%s][%s] %s', strtoupper( $level ), gmdate( 'Y-m-d H:i:s' ), $message );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( $entry ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		do_action( 'af_sri_soap_log', $level, $message, $this->ambiente );
	}

	/**
	 * Parses SOAP response XML and returns a DOMDocument loaded with
	 * the first non-empty child of <soapenv:Body>.
	 *
	 * @param string $soap_xml Raw SOAP response.
	 * @return DOMDocument|WP_Error
	 */
	private function load_soap_body( string $soap_xml ) {
		if ( '' === trim( $soap_xml ) ) {
			return new WP_Error( 'sri_empty_response', 'SRI devolvió una respuesta vacía.' );
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$ok  = $dom->loadXML( $soap_xml );
		libxml_clear_errors();

		if ( ! $ok ) {
			return new WP_Error( 'sri_invalid_xml', 'Respuesta SRI no es XML válido.' );
		}

		return $dom;
	}

	/**
	 * Extracts text content via XPath, optionally relative to a context node.
	 *
	 * @param DOMXPath    $xpath   XPath instance.
	 * @param string      $expr    XPath expression.
	 * @param DOMNode|null $context Context node (null = document root).
	 * @return string
	 */
	private function xpath_text( DOMXPath $xpath, string $expr, ?DOMNode $context = null ): string {
		$nodes = $context ? $xpath->query( $expr, $context ) : $xpath->query( $expr );
		if ( ! $nodes || 0 === $nodes->length ) {
			return '';
		}
		return (string) $nodes->item( 0 )->textContent;
	}

	/**
	 * Parses a list of <mensaje> nodes into a plain array.
	 *
	 * @param DOMXPath     $xpath   XPath instance.
	 * @param string       $expr    XPath expression for mensaje nodes.
	 * @param DOMNode|null $context Context node.
	 * @return array<int, array{identificador: string, mensaje: string, tipo: string}>
	 */
	private function parse_mensajes( DOMXPath $xpath, string $expr, ?DOMNode $context = null ): array {
		$nodes    = $context ? $xpath->query( $expr, $context ) : $xpath->query( $expr );
		$mensajes = array();

		if ( ! $nodes ) {
			return $mensajes;
		}

		foreach ( $nodes as $node ) {
			$mensajes[] = array(
				'identificador' => $this->xpath_text( $xpath, 'identificador', $node ),
				'mensaje'       => $this->xpath_text( $xpath, 'mensaje',       $node ),
				'tipo'          => $this->xpath_text( $xpath, 'tipo',          $node ),
				'adicional'     => $this->xpath_text( $xpath, 'informacionAdicional', $node ),
			);
		}

		return $mensajes;
	}

	/**
	 * Extracts the first non-empty field from an array using candidate keys.
	 *
	 * @param array $data Source array.
	 * @param array $keys Ordered list of candidate keys.
	 * @return string
	 */
	private function extract_field( array $data, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				return sanitize_text_field( (string) $data[ $key ] );
			}
		}
		return '';
	}
}
