<?php
/**
 * Billing orchestrator for Ecuador SRI electronic invoices.
 *
 * Coordinates all billing steps:
 * 1) reserve sequence / generate access key,
 * 2) build XML,
 * 3) sign XML (XAdES-BES),
 * 4) send + authorize with SRI SOAP,
 * 5) generate RIDE PDF,
 * 6) persist invoice status and logs.
 *
 * @package Arriendo_Facil\Billing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Billing_Manager
 */
class Arriendo_Facil_Billing_Manager {

	/** @var wpdb|null */
	protected $wpdb;

	/** @var Arriendo_Facil_SRI_XML_Factura */
	protected $xml_builder;

	/** @var Arriendo_Facil_SRI_Ride */
	protected $ride_generator;

	/** @var callable */
	protected $signer_factory;

	/** @var callable */
	protected $soap_factory;

	/**
	 * Constructor.
	 *
	 * @param array $deps Optional dependency injection map for testing.
	 */
	public function __construct( array $deps = array() ) {
		$this->wpdb           = isset( $deps['wpdb'] ) ? $deps['wpdb'] : ( $GLOBALS['wpdb'] ?? null );
		$this->xml_builder    = isset( $deps['xml_builder'] ) ? $deps['xml_builder'] : new Arriendo_Facil_SRI_XML_Factura();
		$this->ride_generator = isset( $deps['ride_generator'] ) ? $deps['ride_generator'] : new Arriendo_Facil_SRI_Ride();
		$this->signer_factory = isset( $deps['signer_factory'] ) ? $deps['signer_factory'] : function( string $cert_pem, string $pkey_pem, string $chain_pem = '' ) {
			return new Arriendo_Facil_SRI_Signer( $cert_pem, $pkey_pem, $chain_pem );
		};
		$this->soap_factory   = isset( $deps['soap_factory'] ) ? $deps['soap_factory'] : function( string $ambiente ) {
			return new Arriendo_Facil_SRI_Soap_Client( $ambiente );
		};
	}

	/**
	 * Returns the YYYY-MM period string for a given date (defaults to today).
	 *
	 * @param DateTime|null $date Date to derive period from.
	 * @return string e.g. "2026-06"
	 */
	public static function billing_period( ?DateTime $date = null ): string {
		$d = $date ?? new DateTime();
		return $d->format( 'Y-m' );
	}

	/**
	 * Returns the invoice for a specific lease + billing period, or null.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $period   YYYY-MM period string.
	 * @return object|null
	 */
	public function get_invoice_by_lease_period( int $lease_id, string $period ) {
		if ( ! $this->wpdb || $lease_id <= 0 || '' === $period ) {
			return null;
		}
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}af_electronic_invoices
				 WHERE lease_id = %d AND billing_period = %s
				 ORDER BY id DESC LIMIT 1",
				$lease_id,
				$period
			)
		);
	}

	/**
	 * Full end-to-end issuance for a lease invoice.
	 *
	 * @param int   $lease_id  Lease ID.
	 * @param array $overrides Optional overrides. Use key 'billing_period' (YYYY-MM) to target a specific month.
	 * @return array|WP_Error
	 */
	public function issue_lease_invoice( int $lease_id, array $overrides = array() ) {
		// Determine and extract billing period from overrides.
		$period = isset( $overrides['billing_period'] )
			? (string) $overrides['billing_period']
			: self::billing_period();
		unset( $overrides['billing_period'] );

		// Duplicate guard: block re-issue for same period if a non-failed invoice exists.
		$existing_period = $this->get_invoice_by_lease_period( $lease_id, $period );
		if ( $existing_period ) {
			$estado_existente = (string) $existing_period->estado;
			$estados_validos  = array( 'generada', 'firmada', 'enviada', 'autorizada', 'autorizada_sin_ride' );
			if ( in_array( $estado_existente, $estados_validos, true ) ) {
				return new WP_Error(
					'duplicate_invoice',
					sprintf(
						/* translators: 1: period YYYY-MM, 2: estado */
						__( 'Ya existe un comprobante para el periodo %1$s (estado: %2$s). No se emite uno nuevo.', 'arriendo-facil' ),
						$period,
						$estado_existente
					)
				);
			}
		}

		$context = $this->fetch_lease_context( $lease_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		// Translate flat frontend overrides (descripcion, precio_unitario, etc.) to nested payload structure.
		$nested_overrides = $this->translate_flat_overrides( $overrides );

		$payload = $this->build_payload_from_lease_context( $context, $nested_overrides );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Logging mejorado para diagnóstico
		error_log( '=== Arriendo Facil: Emitiendo factura para lease ' . $lease_id . ' periodo ' . $period . ' ===' );

		return $this->issue_from_payload(
			$payload,
			array(
				'lease_id'       => $lease_id,
				'billing_period' => $period,
			)
		);
	}

	/**
	 * Full end-to-end issuance from a prepared business payload.
	 * Useful in tests and for future cleaning service invoices.
	 *
	 * @param array $payload Business payload (buyer/items/totals).
	 * @param array $context Optional context keys: lease_id, cleaning_request_id.
	 * @return array|WP_Error
	 */
	public function issue_from_payload( array $payload, array $context = array() ) {
		$config = Arriendo_Facil_SRI_Config::get();
		$pems   = Arriendo_Facil_SRI_Config::get_cert_pems();

		if ( empty( $config['ruc'] ) ) {
			return new WP_Error( 'sri_config_missing_ruc', __( 'Debe configurar el RUC del emisor en Facturacion SRI.', 'arriendo-facil' ) );
		}
		if ( '' === $pems['cert'] || '' === $pems['pkey'] ) {
			return new WP_Error( 'sri_cert_missing', __( 'Debe cargar y configurar el certificado digital (.p12).', 'arriendo-facil' ) );
		}

		// ── Cadena CA obligatoria ─────────────────────────────────────────────
		// Sin los certificados CA intermedios en el XML firmado el SRI devuelve:
		// error 39 "FIRMA INVALIDA – El certificado firmante no es válido".
		// Solo aplica cuando el certificado NO es autofirmado (tiene un emisor
		// distinto al sujeto, es decir, viene de una CA como BCE o SecurityData).
		if ( '' === trim( $pems['chain'] ?? '' ) ) {
			$cert_info  = openssl_x509_parse( $pems['cert'] );
			$is_self_signed = is_array( $cert_info )
				&& isset( $cert_info['subject'], $cert_info['issuer'] )
				&& ( $cert_info['subject'] === $cert_info['issuer'] );

			if ( ! $is_self_signed ) {
				error_log( '[AF Billing] ADVERTENCIA: cadena CA vacía – intentando reconstruir desde AIA…' );
				$rebuild = Arriendo_Facil_SRI_Config::rebuild_chain();
				if ( ! is_wp_error( $rebuild ) ) {
					$pems        = Arriendo_Facil_SRI_Config::get_cert_pems();
					$chain_count = (int) preg_match_all( '/-----BEGIN CERTIFICATE-----/', $pems['chain'] );
					error_log( '[AF Billing] Cadena CA reconstruida: ' . $chain_count . ' certificado(s) intermedio(s).' );
				} else {
					error_log( '[AF Billing] ERROR: no se pudo reconstruir la cadena CA: ' . $rebuild->get_error_message() );
					return new WP_Error(
						'sri_chain_missing',
						__(
							'Falta la cadena de certificados CA intermedios. ' .
							'Ve a Facturación → Configuración → "Reconstruir cadena CA". ' .
							'Si el botón falla, tu servidor no puede alcanzar los servidores BCE/SecurityData; ' .
							'contacta a tu hosting para habilitar salidas HTTPS al puerto 443. ' .
							'Sin esta cadena el SRI rechaza la firma con "El certificado firmante no es válido".',
							'arriendo-facil'
						)
					);
				}
			}
		}

		$emission = $this->reserve_next_sequence();
		if ( is_wp_error( $emission ) ) {
			return $emission;
		}

		$ambiente = isset( $config['ambiente'] ) ? (string) $config['ambiente'] : '1';
		$tipo_doc = Arriendo_Facil_SRI_Clave_Acceso::TIPO_FACTURA;
		$fecha    = isset( $payload['fecha_emision'] )
			? new DateTime( str_replace( '/', '-', (string) $payload['fecha_emision'] ) )
			: new DateTime();

		$clave = Arriendo_Facil_SRI_Clave_Acceso::generate(
			$fecha,
			$tipo_doc,
			(string) $config['ruc'],
			$ambiente,
			$emission['estab'],
			$emission['pto_emi'],
			(int) $emission['secuencial']
		);

		$numero_comprobante = Arriendo_Facil_SRI_Clave_Acceso::format_numero_comprobante(
			$emission['estab'],
			$emission['pto_emi'],
			(int) $emission['secuencial']
		);

		$iva_codigo_porcentaje = isset( $payload['iva_codigo_porcentaje'] )
			? (string) $payload['iva_codigo_porcentaje']
			: '0';

		$totals = Arriendo_Facil_SRI_XML_Factura::compute_totals(
			(array) ( $payload['items'] ?? array() ),
			$iva_codigo_porcentaje
		);

		$xml_data = $this->build_xml_data( $config, $payload, $totals, $clave, $emission );

		try {
			$xml    = $this->xml_builder->build( $xml_data );

			// ═══════════════════════════════════════════════════════════════════
			// LOGGING DETALLADO PARA DIAGNÓSTICO
			// ═══════════════════════════════════════════════════════════════════
			error_log( '╔════════════════════════════════════════════════════════════════╗' );
			error_log( '║              DIAGNÓSTICO COMPLETO DE FACTURA                   ║' );
			error_log( '╚════════════════════════════════════════════════════════════════╝' );

			// 1. XML SIN FIRMAR
			error_log( '[1] XML SIN FIRMAR' );
			error_log( '    Longitud: ' . strlen( $xml ) . ' bytes' );
			error_log( '    Primeras 300 caracteres:' );
			error_log( '    ' . substr( $xml, 0, 300 ) );

			// 2. CERTIFICADO
			error_log( '[2] CERTIFICADO RECUPERADO' );
			error_log( '    Cert bytes: ' . strlen( $pems['cert'] ) );
			error_log( '    Pkey bytes: ' . strlen( $pems['pkey'] ) );
			error_log( '    Chain bytes: ' . strlen( $pems['chain'] ?? '' ) );

			if ( strlen( $pems['cert'] ) === 0 ) {
				error_log( '    ❌ CRÍTICO: Certificado está VACÍO' );
				return new WP_Error( 'sri_cert_empty', 'Certificado recuperado está vacío - Vuelve a cargarlo' );
			}
			if ( strlen( $pems['pkey'] ) === 0 ) {
				error_log( '    ❌ CRÍTICO: Clave privada está VACÍA' );
				return new WP_Error( 'sri_pkey_empty', 'Clave privada recuperada está vacía - Vuelve a cargarla' );
			}

			// 3. VALIDACIÓN DE CERTIFICADO
			error_log( '[3] VALIDACIÓN DE CERTIFICADO' );
			$cert_test = openssl_x509_parse( $pems['cert'] );
			if ( false === $cert_test ) {
				error_log( '    ❌ No se puede parsear certificado: ' . openssl_error_string() );
				return new WP_Error( 'sri_cert_parse_error', 'Certificado corrupto: ' . openssl_error_string() );
			}

			$subject = $cert_test['subject'] ?? array();
			$issuer = $cert_test['issuer'] ?? array();
			error_log( '    ✓ CN Sujeto: ' . ( $subject['CN'] ?? '?' ) );
			error_log( '    ✓ Emisor: ' . ( $issuer['O'] ?? ( $issuer['CN'] ?? '?' ) ) );
			error_log( '    ✓ Vigencia: ' . wp_date( 'd/m/Y', $cert_test['validFrom_time_t'] ?? 0 ) . ' → ' . wp_date( 'd/m/Y', $cert_test['validTo_time_t'] ?? 0 ) );

			if ( isset( $cert_test['validTo_time_t'] ) && $cert_test['validTo_time_t'] < time() ) {
				error_log( '    ❌ CERTIFICADO VENCIDO' );
				return new WP_Error( 'sri_cert_expired', 'Certificado vencido' );
			}

			// 4. VALIDACIÓN DE CLAVE PRIVADA
			error_log( '[4] VALIDACIÓN DE CLAVE PRIVADA' );
			$key_test = openssl_pkey_get_private( $pems['pkey'] );
			if ( false === $key_test ) {
				error_log( '    ❌ Clave privada inválida: ' . openssl_error_string() );
				return new WP_Error( 'sri_pkey_invalid', 'Clave privada inválida: ' . openssl_error_string() );
			}
			error_log( '    ✓ Clave privada válida' );

			// 5. FIRMA
			error_log( '[5] PROCESO DE FIRMA' );
			$signer = call_user_func( $this->signer_factory, $pems['cert'], $pems['pkey'], $pems['chain'] ?? '' );
			$xml_signed = $signer->sign( $xml );
			error_log( '    ✓ XML firmado exitosamente' );
			error_log( '    Tamaño XML firmado: ' . strlen( $xml_signed ) . ' bytes' );

			// 6. VERIFICACIÓN DE ESTRUCTURA DE FIRMA
			error_log( '[6] ESTRUCTURA DE FIRMA' );
			$has_signature = strpos( $xml_signed, '<Signature' ) !== false;
			$has_cert = strpos( $xml_signed, '<X509Certificate>' ) !== false;
			error_log( '    Elemento <Signature>: ' . ( $has_signature ? '✓ Presente' : '❌ Falta' ) );
			error_log( '    Elemento <X509Certificate>: ' . ( $has_cert ? '✓ Presente' : '❌ Falta' ) );

			// Contar certificados incluidos
			$cert_count = substr_count( $xml_signed, '<X509Certificate>' );
			error_log( '    Certificados en firma: ' . $cert_count );
			if ( $cert_count === 0 ) {
				error_log( '    ⚠️ ADVERTENCIA: No hay certificados en la firma' );
			} elseif ( $cert_count === 1 ) {
				error_log( '    ⚠️ ADVERTENCIA: Solo hay el certificado principal, sin intermedios' );
			} else {
				error_log( '    ✓ Certificado + ' . ( $cert_count - 1 ) . ' intermedios incluidos' );
			}

			// 7. CLAVE DE ACCESO
			error_log( '[7] DATOS DEL COMPROBANTE' );
			error_log( '    Clave de acceso: ' . $clave );
			error_log( '    Número comprobante: ' . $numero_comprobante );
			error_log( '    RUC emisor: ' . ( $config['ruc'] ?? '?' ) );

			// 8. LISTOS PARA ENVIAR AL SRI
			error_log( '[8] LISTOS PARA ENVIAR AL SRI' );
			error_log( '    ✓ Todo validado correctamente' );
			error_log( '    Próximo paso: envío a SRI via SOAP' );
			error_log( '╔════════════════════════════════════════════════════════════════╗' );

		} catch ( \RuntimeException $e ) {
			error_log( '❌ EXCEPTION al firmar: ' . $e->getMessage() );
			return new WP_Error( 'sri_sign_error', $e->getMessage() );
		}

		$invoice_id = $this->insert_invoice_row(
			array_merge(
				$context,
				array(
					'billing_period'     => isset( $context['billing_period'] ) ? (string) $context['billing_period'] : null,
					'tipo_comprobante'   => $tipo_doc,
					'clave_acceso'       => $clave,
					'ambiente'           => (int) $ambiente,
					'estado'             => 'firmada',
					'numero_comprobante' => $numero_comprobante,
					'subtotal_0'         => $this->subtotal_0_from_totals( $totals, $iva_codigo_porcentaje ),
					'subtotal_iva'       => $this->subtotal_iva_from_totals( $totals, $iva_codigo_porcentaje ),
					'iva_valor'          => (float) $totals['iva_valor'],
					'total'              => (float) $totals['importe_total'],
					'xml_firmado'        => $xml_signed,
				)
			)
		);

		if ( $invoice_id <= 0 ) {
			$db_error = ( $this->wpdb && isset( $this->wpdb->last_error ) ) ? (string) $this->wpdb->last_error : '';
			if ( '' !== $db_error ) {
				error_log( '[AF Billing] Error insertando factura: ' . $db_error );
			}
			return new WP_Error(
				'invoice_insert_failed',
				__( 'No se pudo guardar el comprobante en la base de datos. Intente nuevamente.', 'arriendo-facil' )
			);
		}

		// LOGGING: XML que se envía al SRI
		// El XML completo se guarda en un archivo temporal de diagnóstico para
		// poder validarlo en herramientas externas como:
		//   https://dss.esig.europa.eu/validation/detailed-report
		// Una vez validada la firma, eliminar este bloque de diagnóstico.
		$this->dump_signed_xml_for_debug( $xml_signed, $clave );

		error_log( '╔════════════════════════════════════════════════════════════════╗' );
		error_log( '║              XML QUE SE ENVÍA AL SRI (PRIMEROS 1000 CHARS)     ║' );
		error_log( '╚════════════════════════════════════════════════════════════════╝' );
		error_log( substr( $xml_signed, 0, 1000 ) );
		error_log( '... (XML truncado) ...' );
		error_log( '╔════════════════════════════════════════════════════════════════╗' );

		$soap = call_user_func( $this->soap_factory, $ambiente );

		$recepcion = $soap->enviar( $xml_signed );
		$this->log_sri( $invoice_id, 'recepcion', $xml_signed, $recepcion );

		// LOGGING: Respuesta del SRI
		error_log( '╔════════════════════════════════════════════════════════════════╗' );
		error_log( '║              RESPUESTA DEL SRI (RECEPCIÓN)                     ║' );
		error_log( '╚════════════════════════════════════════════════════════════════╝' );
		error_log( 'Respuesta completa: ' . wp_json_encode( $recepcion ) );
		if ( is_wp_error( $recepcion ) ) {
			error_log( '❌ ERROR: ' . $recepcion->get_error_message() );
		} else {
			error_log( 'Estado: ' . ( $recepcion['estado'] ?? '?' ) );
			error_log( 'Mensajes: ' . wp_json_encode( $recepcion['mensajes'] ?? array() ) );
		}
		error_log( '╔════════════════════════════════════════════════════════════════╗' );
		error_log( '' );

		if ( is_wp_error( $recepcion ) ) {
			$this->update_invoice_row(
				$invoice_id,
				array(
					'estado'  => 'error_envio',
					'errores' => $recepcion->get_error_message(),
				)
			);
			return $recepcion;
		}

		if ( 'DEVUELTA' === strtoupper( (string) ( $recepcion['estado'] ?? '' ) ) ) {
			$errores = wp_json_encode( (array) ( $recepcion['mensajes'] ?? array() ) );
			$this->update_invoice_row(
				$invoice_id,
				array(
					'estado'  => 'devuelta',
					'errores' => $errores,
				)
			);
			return new WP_Error( 'sri_devuelta', __( 'SRI devolvio el comprobante.', 'arriendo-facil' ), $recepcion );
		}

		$autorizacion = $soap->autorizar( $clave );
		$this->log_sri( $invoice_id, 'autorizacion', $clave, $autorizacion );

		if ( is_wp_error( $autorizacion ) ) {
			// SRI queued the document but hasn't authorized it yet (EN PROCESO or empty response).
			// Mark as 'enviada' so the retry cron polls for authorization without backoff penalty.
			if ( 'sri_en_proceso' === $autorizacion->get_error_code() ) {
				$this->update_invoice_row( $invoice_id, array( 'estado' => 'enviada' ) );
				return array(
					'invoice_id'         => $invoice_id,
					'estado'             => 'enviada',
					'clave_acceso'       => $clave,
					'numero_comprobante' => $numero_comprobante,
					'billing_period'     => isset( $context['billing_period'] ) ? (string) $context['billing_period'] : null,
					'message'            => __( 'Comprobante enviado al SRI. La autorización se procesará en breve.', 'arriendo-facil' ),
				);
			}
			$this->update_invoice_row(
				$invoice_id,
				array(
					'estado'  => 'error_autorizacion',
					'errores' => $autorizacion->get_error_message(),
				)
			);
			return $autorizacion;
		}

		if ( 'AUTORIZADO' !== strtoupper( (string) ( $autorizacion['estado'] ?? '' ) ) ) {
			$errores = wp_json_encode( (array) ( $autorizacion['mensajes'] ?? array() ) );
			$this->update_invoice_row(
				$invoice_id,
				array(
					'estado'  => 'no_autorizada',
					'errores' => $errores,
				)
			);
			return new WP_Error( 'sri_no_autorizada', __( 'SRI no autorizo el comprobante.', 'arriendo-facil' ), $autorizacion );
		}

		$ride_result = $this->ride_generator->generate(
			array_merge(
				$xml_data,
				array(
					'numero_comprobante'  => $numero_comprobante,
					'clave_acceso'        => $clave,
					'numero_autorizacion' => (string) ( $autorizacion['numero_autorizacion'] ?? '' ),
					'fecha_autorizacion'  => (string) ( $autorizacion['fecha_autorizacion'] ?? '' ),
					'ambiente_label'      => (string) ( $autorizacion['ambiente'] ?? ( '2' === $ambiente ? 'PRODUCCION' : 'PRUEBAS' ) ),
					'subtotal_0'          => $this->subtotal_0_from_totals( $totals, $iva_codigo_porcentaje ),
					'subtotal_iva'        => $this->subtotal_iva_from_totals( $totals, $iva_codigo_porcentaje ),
					'iva_valor'           => (float) $totals['iva_valor'],
					'total'               => (float) $totals['importe_total'],
				)
			)
		);

		if ( is_wp_error( $ride_result ) ) {
			$this->update_invoice_row(
				$invoice_id,
				array(
					'estado'  => 'autorizada_sin_ride',
					'numero_autorizacion' => (string) ( $autorizacion['numero_autorizacion'] ?? '' ),
					'fecha_autorizacion'  => $this->normalize_datetime( (string) ( $autorizacion['fecha_autorizacion'] ?? '' ) ),
					'xml_autorizacion'    => (string) ( $autorizacion['xml_autorizacion'] ?? '' ),
					'errores'             => $ride_result->get_error_message(),
				)
			);
			return $ride_result;
		}

		$this->update_invoice_row(
			$invoice_id,
			array(
				'estado'              => 'autorizada',
				'numero_autorizacion' => (string) ( $autorizacion['numero_autorizacion'] ?? '' ),
				'fecha_autorizacion'  => $this->normalize_datetime( (string) ( $autorizacion['fecha_autorizacion'] ?? '' ) ),
				'xml_autorizacion'    => (string) ( $autorizacion['xml_autorizacion'] ?? '' ),
				'ride_path'           => (string) $ride_result['path'],
				'errores'             => '',
			)
		);

		return array(
			'invoice_id'          => $invoice_id,
			'estado'              => 'autorizada',
			'clave_acceso'        => $clave,
			'numero_comprobante'  => $numero_comprobante,
			'billing_period'      => isset( $context['billing_period'] ) ? (string) $context['billing_period'] : null,
			'numero_autorizacion' => (string) ( $autorizacion['numero_autorizacion'] ?? '' ),
			'ride_path'           => (string) $ride_result['path'],
			'xml_firmado'         => $xml_signed,
			'xml_autorizacion'    => (string) ( $autorizacion['xml_autorizacion'] ?? '' ),
		);
	}

	/**
	 * Returns one invoice by ID.
	 *
	 * @param int $invoice_id Invoice ID.
	 * @return object|null
	 */
	public function get_invoice( int $invoice_id ) {
		if ( ! $this->wpdb || $invoice_id <= 0 ) {
			return null;
		}

		$invoice = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}af_electronic_invoices WHERE id = %d LIMIT 1",
				$invoice_id
			)
		);

		if ( $invoice ) {
			if ( isset( $invoice->xml_firmado ) ) {
				$invoice->xml_firmado = $this->unprotect_db_text( (string) $invoice->xml_firmado );
			}
			if ( isset( $invoice->xml_autorizacion ) ) {
				$invoice->xml_autorizacion = $this->unprotect_db_text( (string) $invoice->xml_autorizacion );
			}
		}

		return $invoice;
	}

	/**
	 * Returns latest invoice for a lease.
	 *
	 * @param int $lease_id Lease ID.
	 * @return object|null
	 */
	public function get_latest_invoice_by_lease( int $lease_id ) {
		if ( ! $this->wpdb || $lease_id <= 0 ) {
			return null;
		}

		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}af_electronic_invoices
				 WHERE lease_id = %d
				 ORDER BY id DESC
				 LIMIT 1",
				$lease_id
			)
		);
	}

	/**
	 * Returns invoices eligible for retry.
	 *
	 * @param int $limit Maximum row count.
	 * @return array<int, object>
	 */
	public function get_retry_candidates( int $limit = 20 ): array {
		if ( ! $this->wpdb ) {
			return array();
		}

		$limit = max( 1, min( 200, $limit ) );
		return (array) $this->wpdb->get_results(
			"SELECT * FROM {$this->wpdb->prefix}af_electronic_invoices
			 WHERE estado IN ('enviada', 'error_envio', 'error_autorizacion', 'no_autorizada', 'devuelta', 'autorizada_sin_ride')
			 ORDER BY updated_at ASC
			 LIMIT " . (int) $limit
		);
	}

	/**
	 * Retries a previously generated invoice against SRI.
	 *
	 * @param int $invoice_id Invoice ID.
	 * @return array|WP_Error
	 */
	public function retry_invoice( int $invoice_id ) {
		$invoice = $this->get_invoice( $invoice_id );
		if ( ! $invoice ) {
			return new WP_Error( 'invoice_not_found', __( 'Comprobante no encontrado.', 'arriendo-facil' ) );
		}

		if ( empty( $invoice->clave_acceso ) ) {
			return new WP_Error( 'missing_access_key', __( 'El comprobante no tiene clave de acceso.', 'arriendo-facil' ) );
		}

		if ( 'autorizada' === (string) $invoice->estado && ! empty( $invoice->ride_path ) && file_exists( (string) $invoice->ride_path ) ) {
			return array(
				'invoice_id' => (int) $invoice->id,
				'estado'     => 'autorizada',
				'message'    => __( 'El comprobante ya esta autorizado.', 'arriendo-facil' ),
			);
		}

		$soap = call_user_func( $this->soap_factory, (string) $invoice->ambiente );

		if ( in_array( (string) $invoice->estado, array( 'error_envio', 'devuelta' ), true ) && ! empty( $invoice->xml_firmado ) ) {
			$recepcion = $soap->enviar( (string) $invoice->xml_firmado );
			$this->log_sri( (int) $invoice->id, 'recepcion_retry', (string) $invoice->xml_firmado, $recepcion );

			if ( is_wp_error( $recepcion ) ) {
				$this->update_invoice_row(
					(int) $invoice->id,
					array(
						'estado'  => 'error_envio',
						'errores' => $recepcion->get_error_message(),
					)
				);
				return $recepcion;
			}

			if ( 'DEVUELTA' === strtoupper( (string) ( $recepcion['estado'] ?? '' ) ) ) {
				$errores = wp_json_encode( (array) ( $recepcion['mensajes'] ?? array() ) );
				$this->update_invoice_row(
					(int) $invoice->id,
					array(
						'estado'  => 'devuelta',
						'errores' => $errores,
					)
				);
				return new WP_Error( 'sri_devuelta', __( 'SRI devolvio el comprobante en reintento.', 'arriendo-facil' ), $recepcion );
			}
		}

		$autorizacion = $soap->autorizar( (string) $invoice->clave_acceso );
		$this->log_sri( (int) $invoice->id, 'autorizacion_retry', (string) $invoice->clave_acceso, $autorizacion );

		if ( is_wp_error( $autorizacion ) ) {
			if ( 'sri_en_proceso' === $autorizacion->get_error_code() ) {
				// SRI still processing — keep invoice as 'enviada', retry cron will try again without backoff.
				$this->update_invoice_row( (int) $invoice->id, array( 'estado' => 'enviada' ) );
				return $autorizacion;
			}
			$this->update_invoice_row(
				(int) $invoice->id,
				array(
					'estado'  => 'error_autorizacion',
					'errores' => $autorizacion->get_error_message(),
				)
			);
			return $autorizacion;
		}

		if ( 'AUTORIZADO' !== strtoupper( (string) ( $autorizacion['estado'] ?? '' ) ) ) {
			$errores = wp_json_encode( (array) ( $autorizacion['mensajes'] ?? array() ) );
			$this->update_invoice_row(
				(int) $invoice->id,
				array(
					'estado'  => 'no_autorizada',
					'errores' => $errores,
				)
			);
			return new WP_Error( 'sri_no_autorizada', __( 'SRI no autorizo el comprobante en reintento.', 'arriendo-facil' ), $autorizacion );
		}

		$ride_path = (string) ( $invoice->ride_path ?? '' );
		if ( '' === $ride_path || ! file_exists( $ride_path ) ) {
			$ride_result = $this->ride_generator->generate(
				array(
					'razon_social'            => '',
					'ruc'                     => '',
					'numero_comprobante'      => (string) ( $invoice->numero_comprobante ?? '' ),
					'clave_acceso'            => (string) $invoice->clave_acceso,
					'numero_autorizacion'     => (string) ( $autorizacion['numero_autorizacion'] ?? '' ),
					'fecha_autorizacion'      => (string) ( $autorizacion['fecha_autorizacion'] ?? '' ),
					'ambiente_label'          => (string) ( $autorizacion['ambiente'] ?? '' ),
					'razon_social_comprador'  => 'CONSUMIDOR FINAL',
					'identificacion_comprador'=> '9999999999999',
					'subtotal_0'              => (float) ( $invoice->subtotal_0 ?? 0 ),
					'subtotal_iva'            => (float) ( $invoice->subtotal_iva ?? 0 ),
					'iva_valor'               => (float) ( $invoice->iva_valor ?? 0 ),
					'total'                   => (float) ( $invoice->total ?? 0 ),
					'items'                   => array(),
				)
			);

			if ( ! is_wp_error( $ride_result ) ) {
				$ride_path = (string) $ride_result['path'];
			}
		}

		$this->update_invoice_row(
			(int) $invoice->id,
			array(
				'estado'              => 'autorizada',
				'numero_autorizacion' => (string) ( $autorizacion['numero_autorizacion'] ?? '' ),
				'fecha_autorizacion'  => $this->normalize_datetime( (string) ( $autorizacion['fecha_autorizacion'] ?? '' ) ),
				'xml_autorizacion'    => (string) ( $autorizacion['xml_autorizacion'] ?? '' ),
				'ride_path'           => $ride_path,
				'errores'             => '',
			)
		);

		return array(
			'invoice_id'          => (int) $invoice->id,
			'estado'              => 'autorizada',
			'numero_autorizacion' => (string) ( $autorizacion['numero_autorizacion'] ?? '' ),
			'ride_path'           => $ride_path,
		);
	}

	/**
	 * Builds XML payload from issuer config + business payload.
	 */
	protected function build_xml_data( array $config, array $payload, array $totals, string $clave, array $emission ): array {
		$buyer_id = isset( $payload['identificacion_comprador'] ) ? (string) $payload['identificacion_comprador'] : '9999999999999';
		$buyer_t  = isset( $payload['tipo_id_comprador'] ) && '' !== (string) $payload['tipo_id_comprador']
			? (string) $payload['tipo_id_comprador']
			: Arriendo_Facil_SRI_XML_Factura::infer_buyer_id_type( $buyer_id );

		return array(
			'ambiente'                 => (string) $config['ambiente'],
			'tipo_emision'             => (string) ( $config['tipo_emision'] ?? '1' ),
			'razon_social'             => (string) ( $config['razon_social'] ?? '' ),
			'nombre_comercial'         => (string) ( $config['nombre_comercial'] ?? '' ),
			'ruc'                      => (string) $config['ruc'],
			'clave_acceso'             => $clave,
			'estab'                    => (string) $emission['estab'],
			'pto_emi'                  => (string) $emission['pto_emi'],
			'secuencial'               => str_pad( (string) $emission['secuencial'], 9, '0', STR_PAD_LEFT ),
			'dir_matriz'               => (string) ( $config['dir_matriz'] ?: $config['dir_establecimiento'] ?? '' ),
			'fecha_emision'            => (string) ( $payload['fecha_emision'] ?? wp_date( 'd/m/Y' ) ),
			'dir_establecimiento'      => (string) ( $config['dir_establecimiento'] ?? '' ),
			'obligado_contabilidad'    => (string) ( $config['obligado_contabilidad'] ?? 'NO' ),
			'tipo_id_comprador'        => $buyer_t,
			'razon_social_comprador'   => (string) ( $payload['razon_social_comprador'] ?? 'CONSUMIDOR FINAL' ),
			'identificacion_comprador' => $buyer_id,
			'dir_comprador'            => (string) ( $payload['dir_comprador'] ?? '' ),
			'total_sin_impuestos'      => (float) $totals['total_sin_impuestos'],
			'total_descuento'          => (float) $totals['total_descuento'],
			'iva_codigo'               => (string) $totals['iva_codigo'],
			'iva_codigo_porcentaje'    => (string) $totals['iva_codigo_porcentaje'],
			'iva_tarifa'               => (float) $totals['iva_tarifa'],
			'iva_base_imponible'       => (float) $totals['iva_base_imponible'],
			'iva_valor'                => (float) $totals['iva_valor'],
			'importe_total'            => (float) $totals['importe_total'],
			'forma_pago'               => (string) ( $payload['forma_pago'] ?? '01' ),
			'plazo'                    => (string) ( $payload['plazo'] ?? '30' ),
			'unidad_tiempo'            => (string) ( $payload['unidad_tiempo'] ?? 'dias' ),
			'items'                    => (array) $totals['items'],
			'info_adicional'           => (array) ( $payload['info_adicional'] ?? array() ),
		);
	}

	/**
	 * Builds a preview data structure for a lease invoice without issuing it.
	 *
	 * @param int   $lease_id  Lease ID.
	 * @param array $overrides Optional flat overrides: descripcion, precio_unitario, cantidad, descuento, email.
	 * @return array|WP_Error
	 */
	public function preview_lease_invoice( int $lease_id, array $overrides = array() ) {
		$period = isset( $overrides['billing_period'] )
			? (string) $overrides['billing_period']
			: self::billing_period();
		unset( $overrides['billing_period'] );

		// Check for existing non-failed invoice.
		$existing  = $this->get_invoice_by_lease_period( $lease_id, $period );
		$can_issue = true;
		$warning   = '';
		if ( $existing ) {
			$non_failed = array( 'generada', 'firmada', 'enviada', 'autorizada', 'autorizada_sin_ride' );
			if ( in_array( (string) $existing->estado, $non_failed, true ) ) {
				$can_issue = false;
				$warning   = sprintf(
					/* translators: 1: period YYYY-MM, 2: estado */
					__( 'Ya existe un comprobante para el periodo %1$s (estado: %2$s). No se emitirá uno nuevo.', 'arriendo-facil' ),
					$period,
					(string) $existing->estado
				);
			}
		}

		$context = $this->fetch_lease_context( $lease_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$nested_overrides = $this->translate_flat_overrides( $overrides );
		$payload          = $this->build_payload_from_lease_context( $context, $nested_overrides );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$iva_codigo_porcentaje = isset( $payload['iva_codigo_porcentaje'] ) ? (string) $payload['iva_codigo_porcentaje'] : '0';
		$totals = Arriendo_Facil_SRI_XML_Factura::compute_totals(
			(array) ( $payload['items'] ?? array() ),
			$iva_codigo_porcentaje
		);

		$item = ! empty( $payload['items'] ) ? (array) $payload['items'][0] : array();

		return array(
			'can_issue'      => $can_issue,
			'warning'        => $warning,
			'billing_period' => $period,
			'buyer'          => array(
				'name'           => (string) ( $payload['razon_social_comprador'] ?? '' ),
				'identification' => (string) ( $payload['identificacion_comprador'] ?? '' ),
			),
			'item'           => array(
				'descripcion'      => (string) ( $item['descripcion'] ?? '' ),
				'precio_unitario'  => (float) ( $item['precio_unitario'] ?? 0 ),
				'cantidad'         => (float) ( $item['cantidad'] ?? 1 ),
				'descuento'        => (float) ( $item['descuento'] ?? 0 ),
			),
			'totals'         => array(
				'total_sin_impuestos' => (float) ( $totals['total_sin_impuestos'] ?? 0 ),
				'iva_valor'           => (float) ( $totals['iva_valor'] ?? 0 ),
				'importe_total'       => (float) ( $totals['importe_total'] ?? 0 ),
			),
			'info_adicional' => (array) ( $payload['info_adicional'] ?? array() ),
		);
	}

	/**
	 * Translates flat frontend overrides to the nested payload structure.
	 *
	 * @param array $flat Flat overrides: descripcion, precio_unitario, cantidad, descuento, email.
	 * @return array Nested payload overrides.
	 */
	protected function translate_flat_overrides( array $flat ): array {
		$nested = array();

		$item_keys    = array( 'descripcion', 'precio_unitario', 'cantidad', 'descuento' );
		$item_override = array();
		foreach ( $item_keys as $key ) {
			if ( array_key_exists( $key, $flat ) ) {
				$item_override[ $key ] = $flat[ $key ];
			}
		}
		if ( ! empty( $item_override ) ) {
			$nested['items'] = array( $item_override );
		}

		if ( array_key_exists( 'email', $flat ) && '' !== (string) $flat['email'] ) {
			$nested['info_adicional'] = array( 'email' => (string) $flat['email'] );
		}

		return $nested;
	}

	/**
	 * Default payload builder for lease invoices.
	 */
	protected function build_payload_from_lease_context( array $context, array $overrides ) {
		$lease = isset( $context['lease'] ) ? $context['lease'] : null;
		if ( ! is_object( $lease ) ) {
			return new WP_Error( 'lease_not_found', __( 'No se encontro el contrato para facturar.', 'arriendo-facil' ) );
		}

		$guest_name = trim( (string) ( $context['guest_name'] ?? '' ) );
		if ( '' === $guest_name ) {
			$guest_name = 'CONSUMIDOR FINAL';
		}

		$guest_id = isset( $context['guest_id_number'] ) ? (string) $context['guest_id_number'] : '9999999999999';
		if ( '' === trim( $guest_id ) ) {
			$guest_id = '9999999999999';
		}

		$accommodation = isset( $context['accommodation_title'] ) ? (string) $context['accommodation_title'] : 'Inmueble';
		$rent          = isset( $lease->monthly_rent ) ? (float) $lease->monthly_rent : 0.0;

		$payload = array(
			'fecha_emision'            => wp_date( 'd/m/Y' ),
			'razon_social_comprador'   => $guest_name,
			'identificacion_comprador' => $guest_id,
			'tipo_id_comprador'        => Arriendo_Facil_SRI_XML_Factura::infer_buyer_id_type( $guest_id ),
			'iva_codigo_porcentaje'    => '0',
			'forma_pago'               => '01',
			'plazo'                    => '30',
			'unidad_tiempo'            => 'dias',
			'items'                    => array(
				array(
					'codigo_principal' => 'ARRIENDO',
					'descripcion'      => sprintf( 'Canon de arriendo - %s', $accommodation ),
					'cantidad'         => 1,
					'precio_unitario'  => $rent,
					'descuento'        => 0,
				),
			),
			'info_adicional' => array(
				'email' => (string) ( $context['guest_email'] ?? '' ),
			),
		);

		return array_replace_recursive( $payload, $overrides );
	}

	/**
	 * Fetches lease + guest + accommodation context from database.
	 */
	protected function fetch_lease_context( int $lease_id ) {
		if ( ! $this->wpdb ) {
			return new WP_Error( 'db_unavailable', __( 'No hay conexion de base de datos.', 'arriendo-facil' ) );
		}

		$query = $this->wpdb->prepare(
			"SELECT l.*, g.first_name, g.last_name, g.id_number, g.email, p.post_title AS accommodation_title
			 FROM {$this->wpdb->prefix}af_leases l
			 LEFT JOIN {$this->wpdb->prefix}af_guests g ON g.id = l.guest_id
			 LEFT JOIN {$this->wpdb->posts} p ON p.ID = l.accommodation_id
			 WHERE l.id = %d
			 LIMIT 1",
			$lease_id
		);

		$lease = $this->wpdb->get_row( $query );
		if ( ! $lease ) {
			return new WP_Error( 'lease_not_found', __( 'Contrato no encontrado.', 'arriendo-facil' ) );
		}

		$first = isset( $lease->first_name ) ? (string) $lease->first_name : '';
		$last  = isset( $lease->last_name ) ? (string) $lease->last_name : '';

		return array(
			'lease'                => $lease,
			'guest_name'           => trim( $first . ' ' . $last ),
			'guest_email'          => (string) ( $lease->email ?? '' ),
			'guest_id_number'      => (string) ( $lease->id_number ?? '' ),
			'accommodation_title'  => (string) ( $lease->accommodation_title ?? '' ),
		);
	}

	/**
	 * Gets active emission point and reserves next sequence atomically.
	 */
	protected function reserve_next_sequence() {
		if ( ! $this->wpdb ) {
			return new WP_Error( 'db_unavailable', __( 'No hay conexion de base de datos.', 'arriendo-facil' ) );
		}

		$max_attempts = 5;
		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$row = $this->wpdb->get_row(
				"SELECT id, codigo_establecimiento, codigo_punto_emision, secuencial_actual
				 FROM {$this->wpdb->prefix}af_emission_points
				 WHERE activo = 1
				 ORDER BY id ASC
				 LIMIT 1"
			);

			if ( ! $row ) {
				return new WP_Error( 'no_emission_point', __( 'No existe un punto de emision activo configurado.', 'arriendo-facil' ) );
			}

			$current = (int) $row->secuencial_actual;
			if ( $current < 1 ) {
				$current = 1;
			}

			$updated = $this->wpdb->update(
				$this->wpdb->prefix . 'af_emission_points',
				array( 'secuencial_actual' => $current + 1 ),
				array( 'id' => (int) $row->id, 'secuencial_actual' => (int) $row->secuencial_actual ),
				array( '%d' ),
				array( '%d', '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error( 'sequence_update_failed', __( 'No se pudo reservar el secuencial del punto de emision.', 'arriendo-facil' ) );
			}

			if ( 1 === (int) $updated ) {
				return array(
					'estab'      => str_pad( (string) $row->codigo_establecimiento, 3, '0', STR_PAD_LEFT ),
					'pto_emi'    => str_pad( (string) $row->codigo_punto_emision, 3, '0', STR_PAD_LEFT ),
					'secuencial' => $current,
				);
			}
		}

		return new WP_Error(
			'sequence_race_conflict',
			__( 'No se pudo reservar un secuencial unico en este momento. Intente nuevamente.', 'arriendo-facil' )
		);
	}

	/**
	 * Persists a new invoice row.
	 */
	protected function insert_invoice_row( array $row ): int {
		if ( ! $this->wpdb ) {
			return 0;
		}

		$data = array(
			'lease_id'            => isset( $row['lease_id'] ) ? (int) $row['lease_id'] : null,
			'cleaning_request_id' => isset( $row['cleaning_request_id'] ) ? (int) $row['cleaning_request_id'] : null,
			'billing_period'      => isset( $row['billing_period'] ) && '' !== $row['billing_period'] ? (string) $row['billing_period'] : null,
			'tipo_comprobante'    => (string) $row['tipo_comprobante'],
			'clave_acceso'        => (string) $row['clave_acceso'],
			'ambiente'            => (int) $row['ambiente'],
			'estado'              => (string) $row['estado'],
			'numero_comprobante'  => (string) $row['numero_comprobante'],
			'subtotal_0'          => (float) $row['subtotal_0'],
			'subtotal_iva'        => (float) $row['subtotal_iva'],
			'iva_valor'           => (float) $row['iva_valor'],
			'total'               => (float) $row['total'],
			'xml_firmado'         => $this->protect_db_text( (string) $row['xml_firmado'] ),
		);

		$this->wpdb->insert( $this->wpdb->prefix . 'af_electronic_invoices', $data );
		return isset( $this->wpdb->insert_id ) ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * Updates an invoice row.
	 */
	protected function update_invoice_row( int $invoice_id, array $data ): void {
		if ( ! $this->wpdb || $invoice_id <= 0 || empty( $data ) ) {
			return;
		}

		if ( isset( $data['xml_firmado'] ) ) {
			$data['xml_firmado'] = $this->protect_db_text( (string) $data['xml_firmado'] );
		}
		if ( isset( $data['xml_autorizacion'] ) ) {
			$data['xml_autorizacion'] = $this->protect_db_text( (string) $data['xml_autorizacion'] );
		}

		$this->wpdb->update(
			$this->wpdb->prefix . 'af_electronic_invoices',
			$data,
			array( 'id' => $invoice_id )
		);
	}

	/**
	 * Inserts SRI request/response log row.
	 */
	protected function log_sri( int $invoice_id, string $tipo_operacion, $request_payload, $response_payload ): void {
		if ( ! $this->wpdb || $invoice_id <= 0 ) {
			return;
		}

		$request_summary  = $this->summarize_payload( $request_payload );
		$response_summary = $this->summarize_payload( $response_payload );

		$this->wpdb->insert(
			$this->wpdb->prefix . 'af_sri_log',
			array(
				'invoice_id'       => $invoice_id,
				'tipo_operacion'   => $tipo_operacion,
				'request_payload'  => $this->protect_db_text( $request_summary ),
				'response_payload' => $this->protect_db_text( $response_summary ),
			)
		);
	}

	/**
	 * Encrypts sensitive DB text using authenticated encryption.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	protected function protect_db_text( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$enc = Arriendo_Facil_SRI_Config::protect_sensitive( $plain );
		return '' !== $enc ? $enc : $plain;
	}

	/**
	 * Decrypts DB text when stored with encryption prefix; keeps plaintext untouched.
	 *
	 * @param string $value Stored DB value.
	 * @return string
	 */
	protected function unprotect_db_text( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( 0 === strpos( $value, Arriendo_Facil_SRI_Config::AEAD_PREFIX ) || 0 === strpos( $value, Arriendo_Facil_SRI_Config::LEGACY_PREFIX ) ) {
			$dec = Arriendo_Facil_SRI_Config::unprotect_sensitive( $value );
			return '' !== $dec ? $dec : '';
		}

		return $value;
	}

	/**
	 * Creates a redacted, bounded-size summary for audit logs.
	 *
	 * @param mixed $payload Request/response payload.
	 * @return string
	 */
	protected function summarize_payload( $payload ): string {
		// Redact large XML blobs from arrays BEFORE JSON-encoding so mensajes SRI
		// are never truncated. xml_autorizacion is already stored in its own column.
		if ( is_array( $payload ) ) {
			foreach ( array( 'xml_autorizacion', 'xml_firmado', 'comprobante' ) as $large_key ) {
				if ( isset( $payload[ $large_key ] ) && strlen( (string) $payload[ $large_key ] ) > 200 ) {
					$payload[ $large_key ] = '[XML redactado: ' . strlen( (string) $payload[ $large_key ] ) . ' bytes]';
				}
			}
		}

		if ( is_scalar( $payload ) ) {
			$text = (string) $payload;
		} else {
			$text = wp_json_encode( $payload );
		}

		if ( false === $text ) {
			$text = '';
		}

		$text = preg_replace( '/\s+/', ' ', $text );
		$text = preg_replace( '/<X509Certificate>.*?<\/X509Certificate>/i', '<X509Certificate>[redacted]</X509Certificate>', $text );
		$text = preg_replace( '/<SignatureValue>.*?<\/SignatureValue>/i', '<SignatureValue>[redacted]</SignatureValue>', $text );
		$text = preg_replace( '/<claveAccesoComprobante>.*?<\/claveAccesoComprobante>/i', '<claveAccesoComprobante>[redacted]</claveAccesoComprobante>', $text );

		if ( strlen( $text ) > 4000 ) {
			$text = substr( $text, 0, 4000 ) . '...[truncated]';
		}

		$hash = hash( 'sha256', $text );
		return '[sha256:' . $hash . '] ' . $text;
	}

	/**
	 * Converts SRI datetime to MySQL datetime format when possible.
	 */
	protected function normalize_datetime( string $value ): string {
		if ( '' === trim( $value ) ) {
			return '';
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Returns subtotal with 0% VAT.
	 */
	protected function subtotal_0_from_totals( array $totals, string $iva_codigo_porcentaje ): float {
		if ( '0' === $iva_codigo_porcentaje ) {
			return (float) $totals['total_sin_impuestos'];
		}
		return 0.0;
	}

	/**
	 * Returns subtotal with taxable VAT.
	 */
	protected function subtotal_iva_from_totals( array $totals, string $iva_codigo_porcentaje ): float {
		if ( '0' === $iva_codigo_porcentaje ) {
			return 0.0;
		}
		return (float) $totals['total_sin_impuestos'];
	}

	/**
	 * Guarda el XML firmado completo en un archivo de diagnóstico temporal.
	 *
	 * DIAGNÓSTICO TEMPORAL — Eliminar este método cuando la firma sea confirmada.
	 *
	 * El archivo se guarda en wp-content/uploads/arriendo-facil/debug/
	 * con nombre basado en la clave de acceso, inaccessible desde el navegador
	 * porque el directorio tiene un .htaccess que bloquea acceso web.
	 *
	 * Pasos para validar externamente:
	 *   1. Descargar el archivo por FTP/SSH desde esa carpeta.
	 *   2. Subirlo a https://dss.esig.europa.eu/validation/detailed-report
	 *   3. Si dice "TOTAL_PASSED" → la firma es válida, el problema está en otro lado.
	 *      Si dice "INDETERMINATE/FAILED" → la firma tiene problemas estructurales.
	 *
	 * @param string $xml_firmado XML firmado completo.
	 * @param string $clave_acceso Clave de acceso (usada para el nombre del archivo).
	 */
	protected function dump_signed_xml_for_debug( string $xml_firmado, string $clave_acceso ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$debug_dir  = trailingslashit( $upload_dir['basedir'] ) . 'arriendo-facil/debug';

		if ( ! is_dir( $debug_dir ) ) {
			wp_mkdir_p( $debug_dir );
			// Bloquear acceso web directo al directorio.
			file_put_contents( $debug_dir . '/.htaccess', "Deny from all\n" );
		}

		$filename  = $debug_dir . '/xml-firmado-' . preg_replace( '/[^0-9]/', '', $clave_acceso ) . '.xml';
		$resultado = file_put_contents( $filename, $xml_firmado );

		if ( false !== $resultado ) {
			error_log( '[DIAGNÓSTICO] XML firmado guardado en: ' . $filename );
			error_log( '[DIAGNÓSTICO] Validar en: https://dss.esig.europa.eu/validation/detailed-report' );
		} else {
			error_log( '[DIAGNÓSTICO] No se pudo guardar el XML de diagnóstico en: ' . $debug_dir );
		}
	}
}
