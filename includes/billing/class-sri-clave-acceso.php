<?php
/**
 * SRI access-key (clave de acceso) generator.
 *
 * Builds the 49-digit authorization key required by the Ecuadorian SRI for
 * every electronic document and validates it using the Module-11 algorithm.
 *
 * Key structure (49 digits):
 *   [ddmmYYYY(8)] [tipoComprobante(2)] [ruc(13)] [ambiente(1)]
 *   [estab(3)] [ptoEmi(3)] [secuencial(9)] [codigoNumerico(8)]
 *   [tipoEmision(1)] [digitoVerificador(1)]
 *
 * @package Arriendo_Facil\Billing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_SRI_Clave_Acceso
 */
class Arriendo_Facil_SRI_Clave_Acceso {

	// ─── Document type codes (codDoc) ───────────────────────────────────────

	const TIPO_FACTURA         = '01';
	const TIPO_LIQUIDACION     = '03';
	const TIPO_NOTA_CREDITO    = '04';
	const TIPO_NOTA_DEBITO     = '05';
	const TIPO_GUIA_REMISION   = '06';
	const TIPO_COMP_RETENCION  = '07';

	// ─── Public API ─────────────────────────────────────────────────────────

	/**
	 * Generates a 49-digit SRI access key.
	 *
	 * @param DateTime $fecha              Emission date.
	 * @param string   $tipo_comprobante   Document type (e.g. '01' = factura).
	 * @param string   $ruc               13-digit RUC of the issuer.
	 * @param string   $ambiente           '1' = pruebas, '2' = producción.
	 * @param string   $cod_establecimiento 3-digit establishment code.
	 * @param string   $cod_punto_emision  3-digit emission point code.
	 * @param int      $secuencial         Sequential number (1-999999999).
	 * @param string   $tipo_emision       '1' = normal (offline).
	 * @return string  49-character numeric string.
	 */
	public static function generate(
		DateTime $fecha,
		string   $tipo_comprobante,
		string   $ruc,
		string   $ambiente,
		string   $cod_establecimiento,
		string   $cod_punto_emision,
		int      $secuencial,
		string   $tipo_emision = '1'
	): string {
		$fecha_str    = $fecha->format( 'dmY' );  // ddmmYYYY — 8 digits.
		$ruc_norm     = str_pad( preg_replace( '/\D/', '', $ruc ), 13, '0', STR_PAD_LEFT );
		$estab        = str_pad( preg_replace( '/\D/', '', $cod_establecimiento ), 3, '0', STR_PAD_LEFT );
		$pto          = str_pad( preg_replace( '/\D/', '', $cod_punto_emision ), 3, '0', STR_PAD_LEFT );
		$sec_str      = str_pad( (string) $secuencial, 9, '0', STR_PAD_LEFT );
		$cod_numerico = str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );

		// Assemble the 48-digit base.
		$base = $fecha_str           // 8
			. $tipo_comprobante      // 2
			. $ruc_norm              // 13
			. $ambiente              // 1
			. $estab                 // 3
			. $pto                   // 3
			. $sec_str               // 9
			. $cod_numerico          // 8
			. $tipo_emision;         // 1  → total 48

		return $base . self::modulo11( $base );  // 49
	}

	/**
	 * Validates a 49-digit key by recomputing its check digit.
	 *
	 * @param string $clave 49-digit key string.
	 * @return bool
	 */
	public static function validate( string $clave ): bool {
		$clave = preg_replace( '/\D/', '', $clave );
		if ( 49 !== strlen( $clave ) ) {
			return false;
		}
		return self::modulo11( substr( $clave, 0, 48 ) ) === $clave[48];
	}

	/**
	 * Decomposes a 49-digit key into its labelled parts.
	 *
	 * @param string $clave 49-digit key string.
	 * @return array<string, string>
	 */
	public static function extract_parts( string $clave ): array {
		return array(
			'fecha_emision'       => substr( $clave, 0, 8 ),   // ddmmYYYY
			'tipo_comprobante'    => substr( $clave, 8, 2 ),
			'ruc'                 => substr( $clave, 10, 13 ),
			'ambiente'            => $clave[23],
			'cod_establecimiento' => substr( $clave, 24, 3 ),
			'cod_punto_emision'   => substr( $clave, 27, 3 ),
			'secuencial'          => substr( $clave, 30, 9 ),
			'codigo_numerico'     => substr( $clave, 39, 8 ),
			'tipo_emision'        => $clave[47],
			'digito_verificador'  => $clave[48],
		);
	}

	/**
	 * Formats the human-readable comprobante number shown on the RIDE.
	 * Format: "001-001-000000001"
	 *
	 * @param string $estab      3-digit establishment code.
	 * @param string $pto_emi    3-digit emission-point code.
	 * @param int    $secuencial Sequential number.
	 * @return string
	 */
	public static function format_numero_comprobante( string $estab, string $pto_emi, int $secuencial ): string {
		return str_pad( $estab, 3, '0', STR_PAD_LEFT )
			. '-' . str_pad( $pto_emi, 3, '0', STR_PAD_LEFT )
			. '-' . str_pad( (string) $secuencial, 9, '0', STR_PAD_LEFT );
	}

	// ─── Module-11 algorithm ────────────────────────────────────────────────

	/**
	 * Computes the Module-11 check digit over an arbitrary numeric string.
	 *
	 * Coefficients 2..7 are assigned left-to-right cycling over the input.
	 *
	 * @param string $base Numeric string (typically 48 digits).
	 * @return string Single-digit string ('0'–'9').
	 */
	public static function modulo11( string $base ): string {
		$factors = array( 2, 3, 4, 5, 6, 7 );
		$sum     = 0;
		$len     = strlen( $base );

		for ( $i = 0; $i < $len; $i++ ) {
			$sum += (int) $base[ $i ] * $factors[ $i % 6 ];
		}

		$verificador = 11 - ( $sum % 11 );
		if ( 11 === $verificador ) {
			return '0';
		}
		if ( 10 === $verificador ) {
			return '1';
		}
		return (string) $verificador;
	}
}
