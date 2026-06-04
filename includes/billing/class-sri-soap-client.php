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
			return $raw;
		}

		return $this->parse_autorizacion_response( $raw );
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
}
