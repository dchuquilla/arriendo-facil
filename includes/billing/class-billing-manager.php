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
		$this->signer_factory = isset( $deps['signer_factory'] ) ? $deps['signer_factory'] : function( string $cert_pem, string $pkey_pem ) {
			return new Arriendo_Facil_SRI_Signer( $cert_pem, $pkey_pem );
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

		$payload = $this->build_payload_from_lease_context( $context, $overrides );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

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
			$signer = call_user_func( $this->signer_factory, $pems['cert'], $pems['pkey'] );
			$xml_signed = $signer->sign( $xml );
		} catch ( \RuntimeException $e ) {
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

		$soap = call_user_func( $this->soap_factory, $ambiente );

		$recepcion = $soap->enviar( $xml_signed );
		$this->log_sri( $invoice_id, 'recepcion', $xml_signed, $recepcion );

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

		return array(
			'estab'      => str_pad( (string) $row->codigo_establecimiento, 3, '0', STR_PAD_LEFT ),
			'pto_emi'    => str_pad( (string) $row->codigo_punto_emision, 3, '0', STR_PAD_LEFT ),
			'secuencial' => $current,
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
}
