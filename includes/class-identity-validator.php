<?php
/**
 * Ecuadorian identity document validation and best-effort cross-check.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Identity_Validator
 *
 * Validates cédula/RUC numbers using the real Ecuadorian check-digit
 * algorithm (not just digit count), and attempts a best-effort, non-binding
 * cross-check between a declared ID number and the text embedded in an
 * uploaded PDF. This assists human review — it never substitutes it.
 */
class Arriendo_Facil_Identity_Validator {

	/**
	 * Validates an Ecuadorian cédula (10 digits) using the modulus 10 algorithm.
	 *
	 * @param string $number Raw cédula number.
	 * @return bool
	 */
	public static function validate_cedula( $number ) {
		$number = preg_replace( '/\D/', '', (string) $number );

		if ( 10 !== strlen( $number ) ) {
			return false;
		}

		$province = (int) substr( $number, 0, 2 );
		$third    = (int) $number[2];

		if ( ( $province < 1 || $province > 24 ) && 30 !== $province ) {
			return false;
		}

		if ( $third > 5 ) {
			return false;
		}

		return self::modulus10_check( $number );
	}

	/**
	 * Validates an Ecuadorian RUC (13 digits). Supports natural person,
	 * private juridical, and public entity RUC formats.
	 *
	 * @param string $number Raw RUC number.
	 * @return bool
	 */
	public static function validate_ruc( $number ) {
		$number = preg_replace( '/\D/', '', (string) $number );

		if ( 13 !== strlen( $number ) ) {
			return false;
		}

		$province = (int) substr( $number, 0, 2 );
		if ( ( $province < 1 || $province > 24 ) && 30 !== $province ) {
			return false;
		}

		$third = (int) $number[2];

		// Natural person: first 10 digits are a valid cédula, establishment must be 001+.
		if ( $third <= 5 ) {
			return self::validate_cedula( substr( $number, 0, 10 ) ) && '000' !== substr( $number, 10, 3 );
		}

		// Public entity RUC (third digit 6).
		if ( 6 === $third ) {
			return self::modulus11_check( $number, array( 3, 2, 7, 6, 5, 4, 3, 2 ), 9 );
		}

		// Private juridical RUC (third digit 9).
		if ( 9 === $third ) {
			return self::modulus11_check( $number, array( 4, 3, 2, 7, 6, 5, 4, 3, 2 ), 10 );
		}

		return false;
	}

	/**
	 * Validates a passport number used by foreign residents.
	 *
	 * Clear limits: 6 to 12 alphanumeric characters (letters and digits),
	 * no spaces. Dashes/hyphens are ignored before validation so formats
	 * like "A1 234567" or "AB-123456" are accepted and normalized.
	 *
	 * @param string $number Raw passport number.
	 * @return bool
	 */
	public static function validate_pasaporte( $number ) {
		$number = strtoupper( preg_replace( '/[\s\-\.]/', '', (string) $number ) );

		return (bool) preg_match( '/^[A-Z0-9]{6,12}$/', $number );
	}

	/**
	 * Validates a document number against its declared type.
	 *
	 * @param string $type   'cedula', 'ruc', or 'pasaporte'.
	 * @param string $number Raw document number.
	 * @return bool
	 */
	public static function validate( $type, $number ) {
		$type = strtolower( trim( (string) $type ) );

		if ( 'ruc' === $type ) {
			return self::validate_ruc( $number );
		}

		if ( 'pasaporte' === $type ) {
			return self::validate_pasaporte( $number );
		}

		return self::validate_cedula( $number );
	}

	/**
	 * Modulus 10 check-digit algorithm used for cédula validation.
	 *
	 * @param string $number 10-digit number.
	 * @return bool
	 */
	private static function modulus10_check( $number ) {
		$coefficients = array( 2, 1, 2, 1, 2, 1, 2, 1, 2 );
		$sum          = 0;

		for ( $i = 0; $i < 9; $i++ ) {
			$value = (int) $number[ $i ] * $coefficients[ $i ];
			if ( $value >= 10 ) {
				$value -= 9;
			}
			$sum += $value;
		}

		$verifier = ( 10 - ( $sum % 10 ) ) % 10;

		return $verifier === (int) $number[9];
	}

	/**
	 * Modulus 11 check-digit algorithm used for RUC (public/private juridical).
	 *
	 * @param string $number       13-digit RUC.
	 * @param array  $coefficients Per-digit weights.
	 * @param int    $check_index  Index of the check digit within the number.
	 * @return bool
	 */
	private static function modulus11_check( $number, array $coefficients, $check_index ) {
		$sum = 0;
		foreach ( $coefficients as $i => $coefficient ) {
			$sum += (int) $number[ $i ] * $coefficient;
		}

		$remainder = $sum % 11;
		$verifier  = 0 === $remainder ? 0 : 11 - $remainder;

		if ( 10 === $verifier ) {
			return false;
		}

		return $verifier === (int) $number[ $check_index ];
	}

	/**
	 * Best-effort extraction of readable text from a PDF binary.
	 *
	 * Only recovers text when the PDF has an embedded text layer (e.g. a
	 * digitally generated document). Scanned/photographed IDs have no text
	 * layer and will correctly return an empty string — that is expected,
	 * not a failure, and must not be treated as a negative signal.
	 *
	 * @param string $binary Raw PDF file contents.
	 * @return string Extracted text (may be empty).
	 */
	public static function extract_text_from_pdf( $binary ) {
		$binary = (string) $binary;
		if ( '' === $binary || false === strpos( $binary, '%PDF-' ) ) {
			return '';
		}

		$text = '';

		if ( preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $binary, $stream_matches ) ) {
			foreach ( $stream_matches[1] as $stream ) {
				$decoded = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$source  = false !== $decoded ? $decoded : $stream;

				if ( preg_match_all( '/\((?:[^()\\\\]|\\\\.)*\)/', $source, $text_matches ) ) {
					foreach ( $text_matches[0] as $chunk ) {
						$chunk = substr( $chunk, 1, -1 );
						$chunk = str_replace( array( '\\(', '\\)', '\\\\' ), array( '(', ')', '\\' ), $chunk );
						$text .= $chunk . ' ';
					}
				}
			}
		}

		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Attempts to confirm that a declared ID number appears in an uploaded
	 * document's text. Returns 'undetermined' whenever no text layer could
	 * be recovered (typical for scanned IDs) rather than a false negative.
	 *
	 * @param string $id_number Declared ID number.
	 * @param string $binary    Raw PDF file contents.
	 * @return string One of: 'match', 'no_match', 'undetermined'.
	 */
	public static function cross_check_document( $id_number, $binary ) {
		$digits = preg_replace( '/\D/', '', (string) $id_number );
		if ( '' === $digits ) {
			return 'undetermined';
		}

		$text = self::extract_text_from_pdf( $binary );
		if ( '' === $text ) {
			return 'undetermined';
		}

		$text_digits_only = preg_replace( '/[^0-9]/', '', $text );

		return ( false !== strpos( $text_digits_only, $digits ) ) ? 'match' : 'no_match';
	}
}
