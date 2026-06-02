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
		$this->signer_factory = isset( $deps['signer_factory'] ) ? $deps['signer_factory'] : function( string $p12, string $password ) {
			return new Arriendo_Facil_SRI_Signer( $p12, $password );
		};
		$this->soap_factory   = isset( $deps['soap_factory'] ) ? $deps['soap_factory'] : function( string $ambiente ) {
			return new Arriendo_Facil_SRI_Soap_Client( $ambiente );
		};
	}

	/**
	 * Full end-to-end issuance for a lease invoice.
	 *
	 * @param int   $lease_id Lease ID.
	 * @param array $overrides Optional overrides: items, iva_codigo_porcentaje, fecha_emision, etc.
	 * @return array|WP_Error
	 */
	public function issue_lease_invoice( int $lease_id, array $overrides = array() ) {
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
				'lease_id' => $lease_id,
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
		$cert   = Arriendo_Facil_SRI_Config::cert_path();
		$pass   = Arriendo_Facil_SRI_Config::cert_password();

		if ( empty( $config['ruc'] ) ) {
			return new WP_Error( 'sri_config_missing_ruc', __( 'Debe configurar el RUC del emisor en Facturacion SRI.', 'arriendo-facil' ) );
		}
		if ( '' === $cert || '' === $pass ) {
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
		$xml      = $this->xml_builder->build( $xml_data );

		$signer     = call_user_func( $this->signer_factory, $cert, $pass );
		$xml_signed = $signer->sign( $xml );

		$invoice_id = $this->insert_invoice_row(
			array_merge(
				$context,
				array(
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
			'numero_autorizacion' => (string) ( $autorizacion['numero_autorizacion'] ?? '' ),
			'ride_path'           => (string) $ride_result['path'],
			'xml_firmado'         => $xml_signed,
			'xml_autorizacion'    => (string) ( $autorizacion['xml_autorizacion'] ?? '' ),
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
			'dir_matriz'               => (string) ( $config['dir_matriz'] ?? '' ),
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
			'tipo_comprobante'    => (string) $row['tipo_comprobante'],
			'clave_acceso'        => (string) $row['clave_acceso'],
			'ambiente'            => (int) $row['ambiente'],
			'estado'              => (string) $row['estado'],
			'numero_comprobante'  => (string) $row['numero_comprobante'],
			'subtotal_0'          => (float) $row['subtotal_0'],
			'subtotal_iva'        => (float) $row['subtotal_iva'],
			'iva_valor'           => (float) $row['iva_valor'],
			'total'               => (float) $row['total'],
			'xml_firmado'         => (string) $row['xml_firmado'],
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
		$this->wpdb->insert(
			$this->wpdb->prefix . 'af_sri_log',
			array(
				'invoice_id'       => $invoice_id,
				'tipo_operacion'   => $tipo_operacion,
				'request_payload'  => is_scalar( $request_payload ) ? (string) $request_payload : wp_json_encode( $request_payload ),
				'response_payload' => is_scalar( $response_payload ) ? (string) $response_payload : wp_json_encode( $response_payload ),
			)
		);
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
