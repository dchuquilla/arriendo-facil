<?php
/**
 * SRI XML Factura generator — schema version 2.1.0.
 *
 * Generates the unsigned XML for an Ecuadorian electronic invoice following
 * the SRI technical specification. This class is purely presentational:
 * given a well-formed data array it produces the XML string. Signing is
 * handled in Phase 3 (Arriendo_Facil_SRI_Signer).
 *
 * ── Expected $data array keys ─────────────────────────────────────────────
 *
 * Issuer (from SRI config + emission point):
 *   ambiente              string  '1'|'2'
 *   tipo_emision          string  '1'
 *   razon_social          string
 *   nombre_comercial      string  (optional)
 *   ruc                   string  13 digits
 *   clave_acceso          string  49 digits
 *   estab                 string  3 digits
 *   pto_emi               string  3 digits
 *   secuencial            string  9 digits zero-padded
 *   dir_matriz            string
 *
 * Invoice header:
 *   fecha_emision         string|DateTime  'dd/mm/YYYY' or DateTime
 *   dir_establecimiento   string
 *   obligado_contabilidad string  'SI'|'NO'
 *   contribuyente_especial string (optional – resolution number)
 *
 * Buyer:
 *   tipo_id_comprador        string  '04'=RUC '05'=cédula '06'=pasaporte '07'=consumidor final
 *   razon_social_comprador   string
 *   identificacion_comprador string
 *   dir_comprador            string  (optional)
 *
 * Totals (pre-computed, e.g. via compute_totals()):
 *   total_sin_impuestos   float
 *   total_descuento       float
 *   iva_codigo            string  '2' (IVA)
 *   iva_codigo_porcentaje string  '0'=0% '2'=12% '3'=14% '4'=15% '5'=5%
 *   iva_tarifa            float   (0.00, 12.00, 14.00, 15.00, 5.00)
 *   iva_base_imponible    float
 *   iva_valor             float
 *   importe_total         float
 *
 * Payment:
 *   forma_pago            string  '01'=sin sistema financiero, '16'=transferencia …
 *   plazo                 string  (default '30')
 *   unidad_tiempo         string  (default 'dias')
 *
 * Line items (array of arrays):
 *   items[n]['codigo_principal']           string
 *   items[n]['codigo_auxiliar']            string  (optional)
 *   items[n]['descripcion']                string
 *   items[n]['cantidad']                   float
 *   items[n]['precio_unitario']            float
 *   items[n]['descuento']                  float   (default 0)
 *   items[n]['precio_total_sin_impuesto']  float   (pre-computed)
 *   items[n]['iva_codigo']                 string  '2'
 *   items[n]['iva_codigo_porcentaje']      string
 *   items[n]['iva_tarifa']                 float
 *   items[n]['iva_base_imponible']         float
 *   items[n]['iva_valor']                  float
 *
 * Additional info (optional):
 *   info_adicional  array<string,string>  [ 'nombre' => 'valor', … ]
 *
 * @package Arriendo_Facil\Billing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_SRI_XML_Factura
 */
class Arriendo_Facil_SRI_XML_Factura {

	// ─── IVA rate catalogue ──────────────────────────────────────────────────

	/** Maps SRI codigoPorcentaje to its numeric percentage. */
	const IVA_RATES = array(
		'0' => 0.00,   // 0%  – residential rental, basic goods
		'2' => 12.00,  // 12% – historic standard rate (pre-2024)
		'3' => 14.00,  // 14% – transitional rate
		'4' => 15.00,  // 15% – current standard rate (2024+)
		'5' => 5.00,   // 5%  – reduced rate for some goods
	);

	// ─── Static helpers ──────────────────────────────────────────────────────

	/**
	 * Returns the numeric IVA percentage for a given SRI codigoPorcentaje.
	 *
	 * @param string $codigo_porcentaje SRI IVA percentage code.
	 * @return float
	 */
	public static function iva_tarifa_from_codigo( string $codigo_porcentaje ): float {
		return isset( self::IVA_RATES[ $codigo_porcentaje ] )
			? self::IVA_RATES[ $codigo_porcentaje ]
			: 0.00;
	}

	/**
	 * Infers the SRI buyer identification type from the ID string.
	 *
	 *   '04' = RUC          (13 digits)
	 *   '05' = Cédula       (10 digits)
	 *   '06' = Pasaporte    (other non-empty string)
	 *   '07' = Consumidor final (empty or '9999999999999')
	 *
	 * @param string $id Buyer identification number.
	 * @return string Two-character SRI type code.
	 */
	public static function infer_buyer_id_type( string $id ): string {
		$digits = preg_replace( '/\D/', '', $id );
		if ( '' === $digits || '9999999999999' === $digits ) {
			return '07'; // consumidor final
		}
		if ( 13 === strlen( $digits ) ) {
			return '04'; // RUC
		}
		if ( 10 === strlen( $digits ) ) {
			return '05'; // cédula
		}
		return '06'; // pasaporte / identificación del exterior
	}

	/**
	 * Computes line-item tax amounts AND invoice totals in one pass.
	 *
	 * Accepts raw items (only cantidad, precio_unitario, descuento,
	 * descripcion, codigo_principal are needed) and returns the same
	 * items enriched with tax fields, plus header totals.
	 *
	 * The header iva_valor is the sum of per-item iva_valor (rounded to 2dp),
	 * which is what SRI uses for validation.
	 *
	 * @param array  $raw_items            Items without pre-computed tax.
	 * @param string $iva_codigo_porcentaje SRI IVA percentage code.
	 * @return array {
	 *   items: array,
	 *   total_sin_impuestos: float,
	 *   total_descuento: float,
	 *   iva_tarifa: float,
	 *   iva_codigo_porcentaje: string,
	 *   iva_codigo: string,
	 *   iva_base_imponible: float,
	 *   iva_valor: float,
	 *   importe_total: float,
	 * }
	 */
	public static function compute_totals( array $raw_items, string $iva_codigo_porcentaje ): array {
		$tarifa         = self::iva_tarifa_from_codigo( $iva_codigo_porcentaje );
		$subtotal       = 0.00;
		$total_iva      = 0.00;
		$items_with_tax = array();

		foreach ( $raw_items as $item ) {
			$qty   = (float) ( $item['cantidad'] ?? 1 );
			$price = (float) ( $item['precio_unitario'] ?? 0.00 );
			$disc  = (float) ( $item['descuento'] ?? 0.00 );
			$base  = round( $qty * $price - $disc, 2 );
			$iva   = round( $base * $tarifa / 100, 2 );

			$item['precio_total_sin_impuesto'] = $base;
			$item['iva_codigo']                = '2';
			$item['iva_codigo_porcentaje']     = $iva_codigo_porcentaje;
			$item['iva_tarifa']                = $tarifa;
			$item['iva_base_imponible']        = $base;
			$item['iva_valor']                 = $iva;

			$items_with_tax[] = $item;
			$subtotal         += $base;
			$total_iva        += $iva;
		}

		$subtotal  = round( $subtotal, 2 );
		$total_iva = round( $total_iva, 2 );

		return array(
			'items'                 => $items_with_tax,
			'total_sin_impuestos'   => $subtotal,
			'total_descuento'       => 0.00,
			'iva_codigo'            => '2',
			'iva_codigo_porcentaje' => $iva_codigo_porcentaje,
			'iva_tarifa'            => $tarifa,
			'iva_base_imponible'    => $subtotal,
			'iva_valor'             => $total_iva,
			'importe_total'         => round( $subtotal + $total_iva, 2 ),
		);
	}

	// ─── XML builder ─────────────────────────────────────────────────────────

	/**
	 * Builds the unsigned XML string for an SRI factura (schema v2.1.0).
	 *
	 * @param array $data Structured invoice data (see class docblock).
	 * @return string Well-formed UTF-8 XML string.
	 * @throws RuntimeException If XML construction fails.
	 */
	public function build( array $data ): string {
		$dom               = new DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = false;

		// ── Root ────────────────────────────────────────────────────────────
		$root = $dom->createElement( 'factura' );
		$root->setAttribute( 'id', 'comprobante' );
		$root->setAttribute( 'version', '2.1.0' );
		$dom->appendChild( $root );

		// ── infoTributaria ──────────────────────────────────────────────────
		$info_trib = $dom->createElement( 'infoTributaria' );
		$root->appendChild( $info_trib );

		$this->txt( $dom, $info_trib, 'ambiente',    $data['ambiente'] );
		$this->txt( $dom, $info_trib, 'tipoEmision', $data['tipo_emision'] );
		$this->txt( $dom, $info_trib, 'razonSocial', $data['razon_social'] );

		if ( ! empty( $data['nombre_comercial'] ) ) {
			$this->txt( $dom, $info_trib, 'nombreComercial', $data['nombre_comercial'] );
		}

		$this->txt( $dom, $info_trib, 'ruc',         $data['ruc'] );
		$this->txt( $dom, $info_trib, 'claveAcceso', $data['clave_acceso'] );
		$this->txt( $dom, $info_trib, 'codDoc',      '01' );
		$this->txt( $dom, $info_trib, 'estab',       $data['estab'] );
		$this->txt( $dom, $info_trib, 'ptoEmi',      $data['pto_emi'] );
		$this->txt( $dom, $info_trib, 'secuencial',  $data['secuencial'] );
		$this->txt( $dom, $info_trib, 'dirMatriz',   $data['dir_matriz'] );

		// ── infoFactura ─────────────────────────────────────────────────────
		$info_fac = $dom->createElement( 'infoFactura' );
		$root->appendChild( $info_fac );

		$this->txt( $dom, $info_fac, 'fechaEmision',     self::normalize_fecha( $data['fecha_emision'] ) );
		$this->txt( $dom, $info_fac, 'dirEstablecimiento', $data['dir_establecimiento'] );

		if ( ! empty( $data['contribuyente_especial'] ) ) {
			$this->txt( $dom, $info_fac, 'contribuyenteEspecial', $data['contribuyente_especial'] );
		}

		$this->txt( $dom, $info_fac, 'obligadoContabilidad',       strtoupper( $data['obligado_contabilidad'] ) );
		$this->txt( $dom, $info_fac, 'tipoIdentificacionComprador', $data['tipo_id_comprador'] );

		if ( ! empty( $data['guia_remision'] ) ) {
			$this->txt( $dom, $info_fac, 'guiaRemision', $data['guia_remision'] );
		}

		$this->txt( $dom, $info_fac, 'razonSocialComprador',    $data['razon_social_comprador'] );
		$this->txt( $dom, $info_fac, 'identificacionComprador', $data['identificacion_comprador'] );

		if ( ! empty( $data['dir_comprador'] ) ) {
			$this->txt( $dom, $info_fac, 'direccionComprador', $data['dir_comprador'] );
		}

		$this->txt( $dom, $info_fac, 'totalSinImpuestos', $this->fmt2( $data['total_sin_impuestos'] ) );
		$this->txt( $dom, $info_fac, 'totalDescuento',    $this->fmt2( $data['total_descuento'] ?? 0 ) );

		// totalConImpuestos
		$total_con_imp  = $dom->createElement( 'totalConImpuestos' );
		$info_fac->appendChild( $total_con_imp );

		$total_impuesto = $dom->createElement( 'totalImpuesto' );
		$total_con_imp->appendChild( $total_impuesto );
		$this->txt( $dom, $total_impuesto, 'codigo',             $data['iva_codigo'] ?? '2' );
		$this->txt( $dom, $total_impuesto, 'codigoPorcentaje',   $data['iva_codigo_porcentaje'] );
		$this->txt( $dom, $total_impuesto, 'descuentoAdicional', '0.00' );
		$this->txt( $dom, $total_impuesto, 'baseImponible',      $this->fmt2( $data['iva_base_imponible'] ) );
		$this->txt( $dom, $total_impuesto, 'valor',              $this->fmt2( $data['iva_valor'] ) );

		$this->txt( $dom, $info_fac, 'propina',      '0.00' );
		$this->txt( $dom, $info_fac, 'importeTotal', $this->fmt2( $data['importe_total'] ) );
		$this->txt( $dom, $info_fac, 'moneda',       'DOLAR' );

		// pagos
		$pagos = $dom->createElement( 'pagos' );
		$info_fac->appendChild( $pagos );
		$pago = $dom->createElement( 'pago' );
		$pagos->appendChild( $pago );
		$this->txt( $dom, $pago, 'formaPago',    $data['forma_pago'] ?? '01' );
		$this->txt( $dom, $pago, 'total',        $this->fmt2( $data['importe_total'] ) );
		$this->txt( $dom, $pago, 'plazo',        (string) ( $data['plazo'] ?? '30' ) );
		$this->txt( $dom, $pago, 'unidadTiempo', $data['unidad_tiempo'] ?? 'dias' );

		// ── detalles ────────────────────────────────────────────────────────
		$detalles = $dom->createElement( 'detalles' );
		$root->appendChild( $detalles );

		foreach ( (array) $data['items'] as $item ) {
			$det = $dom->createElement( 'detalle' );
			$detalles->appendChild( $det );

			$this->txt( $dom, $det, 'codigoPrincipal', $item['codigo_principal'] ?? 'SERV' );

			if ( ! empty( $item['codigo_auxiliar'] ) ) {
				$this->txt( $dom, $det, 'codigoAuxiliar', $item['codigo_auxiliar'] );
			}

			$this->txt( $dom, $det, 'descripcion',           $item['descripcion'] );
			$this->txt( $dom, $det, 'cantidad',              $this->fmt6( $item['cantidad'] ) );
			$this->txt( $dom, $det, 'precioUnitario',        $this->fmt6( $item['precio_unitario'] ) );
			$this->txt( $dom, $det, 'descuento',             $this->fmt2( $item['descuento'] ?? 0 ) );
			$this->txt( $dom, $det, 'precioTotalSinImpuesto', $this->fmt2( $item['precio_total_sin_impuesto'] ) );

			$impuestos = $dom->createElement( 'impuestos' );
			$det->appendChild( $impuestos );
			$impuesto = $dom->createElement( 'impuesto' );
			$impuestos->appendChild( $impuesto );
			$this->txt( $dom, $impuesto, 'codigo',           $item['iva_codigo'] ?? '2' );
			$this->txt( $dom, $impuesto, 'codigoPorcentaje', $item['iva_codigo_porcentaje'] );
			$this->txt( $dom, $impuesto, 'tarifa',           $this->fmt2( $item['iva_tarifa'] ) );
			$this->txt( $dom, $impuesto, 'baseImponible',    $this->fmt2( $item['iva_base_imponible'] ) );
			$this->txt( $dom, $impuesto, 'valor',            $this->fmt2( $item['iva_valor'] ) );
		}

		// ── infoAdicional ───────────────────────────────────────────────────
		if ( ! empty( $data['info_adicional'] ) ) {
			$info_adic = $dom->createElement( 'infoAdicional' );
			$root->appendChild( $info_adic );

			foreach ( (array) $data['info_adicional'] as $nombre => $valor ) {
				if ( '' === (string) $valor ) {
					continue;
				}
				$campo = $dom->createElement( 'campoAdicional' );
				$campo->setAttribute( 'nombre', (string) $nombre );
				$campo->appendChild( $dom->createTextNode( (string) $valor ) );
				$info_adic->appendChild( $campo );
			}
		}

		$xml = $dom->saveXML();
		if ( false === $xml ) {
			throw new RuntimeException( 'Failed to serialize XML document.' );
		}
		return $xml;
	}

	// ─── Private helpers ────────────────────────────────────────────────────

	/**
	 * Appends a child element with a text node to a parent.
	 *
	 * @param DOMDocument $dom    Document.
	 * @param DOMElement  $parent Parent element.
	 * @param string      $tag   Child element tag name.
	 * @param string      $text  Text content (will be XML-escaped by DOMDocument).
	 */
	private function txt( DOMDocument $dom, DOMElement $parent, string $tag, string $text ): void {
		$el = $dom->createElement( $tag );
		$el->appendChild( $dom->createTextNode( $text ) );
		$parent->appendChild( $el );
	}

	/**
	 * Formats a number with 2 decimal places, dot separator, no thousands separator.
	 *
	 * @param float|int|string $value Numeric value.
	 * @return string
	 */
	private function fmt2( $value ): string {
		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * Formats a number with 6 decimal places (quantities / unit prices).
	 *
	 * @param float|int|string $value Numeric value.
	 * @return string
	 */
	private function fmt6( $value ): string {
		return number_format( (float) $value, 6, '.', '' );
	}

	/**
	 * Normalizes various date representations to the SRI format 'dd/mm/YYYY'.
	 *
	 * Accepts: DateTime, 'dd/mm/YYYY' string, or 'YYYY-MM-DD' string.
	 *
	 * @param mixed $fecha Date value.
	 * @return string
	 */
	private static function normalize_fecha( $fecha ): string {
		if ( $fecha instanceof DateTime ) {
			return $fecha->format( 'd/m/Y' );
		}
		$fecha = (string) $fecha;
		// Already in dd/mm/YYYY.
		if ( preg_match( '/^\d{2}\/\d{2}\/\d{4}$/', $fecha ) ) {
			return $fecha;
		}
		// ISO format YYYY-MM-DD.
		$dt = DateTime::createFromFormat( 'Y-m-d', $fecha );
		if ( $dt instanceof DateTime ) {
			return $dt->format( 'd/m/Y' );
		}
		return $fecha;
	}
}
