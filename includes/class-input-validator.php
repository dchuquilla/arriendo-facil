<?php
/**
 * Input Validator
 *
 * Centralized input validation and sanitization.
 *
 * @package Arriendo_Facil
 * @since 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Input_Validator
 *
 * Provides centralized input validation with consistent error handling.
 */
class Arriendo_Facil_Input_Validator {

	/**
	 * Validation rules registry.
	 *
	 * @var array
	 */
	private static $rules = array();

	/**
	 * Errors from last validation.
	 *
	 * @var array
	 */
	private static $errors = array();

	/**
	 * Validate an entire request body against a schema.
	 *
	 * @param array $data Data to validate.
	 * @param array $schema Validation schema.
	 *
	 * @return bool True if valid, false if validation fails.
	 */
	public static function validate( $data, $schema ) {
		self::$errors = array();

		foreach ( $schema as $field => $rules ) {
			if ( ! self::validate_field( $field, $data[ $field ] ?? null, $rules ) ) {
				self::$errors[ $field ] = self::get_last_error();
			}
		}

		return empty( self::$errors );
	}

	/**
	 * Validate a single field.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Field value.
	 * @param array  $rules Validation rules.
	 *
	 * @return bool
	 */
	private static function validate_field( $field, $value, $rules ) {
		if ( isset( $rules['required'] ) && $rules['required'] && empty( $value ) ) {
			self::$errors[ $field ] = sprintf( __( '%s is required', 'arriendo-facil' ), $field );
			return false;
		}

		if ( empty( $value ) ) {
			return true; // Non-required field is empty, that's OK.
		}

		// Type checking.
		if ( isset( $rules['type'] ) ) {
			$type = $rules['type'];
			$valid_types = array( 'string', 'int', 'float', 'bool', 'array', 'email', 'url', 'phone' );

			if ( ! in_array( $type, $valid_types, true ) ) {
				self::$errors[ $field ] = sprintf( __( 'Invalid type for %s', 'arriendo-facil' ), $field );
				return false;
			}

			if ( ! self::check_type( $value, $type ) ) {
				self::$errors[ $field ] = sprintf( __( '%s must be %s', 'arriendo-facil' ), $field, $type );
				return false;
			}
		}

		// Length checking.
		if ( isset( $rules['min_length'] ) && strlen( (string) $value ) < $rules['min_length'] ) {
			self::$errors[ $field ] = sprintf( __( '%s must be at least %d characters', 'arriendo-facil' ), $field, $rules['min_length'] );
			return false;
		}

		if ( isset( $rules['max_length'] ) && strlen( (string) $value ) > $rules['max_length'] ) {
			self::$errors[ $field ] = sprintf( __( '%s must be at most %d characters', 'arriendo-facil' ), $field, $rules['max_length'] );
			return false;
		}

		// Numeric range checking.
		if ( isset( $rules['min'] ) && (int) $value < $rules['min'] ) {
			self::$errors[ $field ] = sprintf( __( '%s must be at least %d', 'arriendo-facil' ), $field, $rules['min'] );
			return false;
		}

		if ( isset( $rules['max'] ) && (int) $value > $rules['max'] ) {
			self::$errors[ $field ] = sprintf( __( '%s must be at most %d', 'arriendo-facil' ), $field, $rules['max'] );
			return false;
		}

		// Pattern matching.
		if ( isset( $rules['pattern'] ) && ! preg_match( $rules['pattern'], (string) $value ) ) {
			self::$errors[ $field ] = sprintf( __( '%s does not match required format', 'arriendo-facil' ), $field );
			return false;
		}

		// Custom validation function.
		if ( isset( $rules['validate'] ) && is_callable( $rules['validate'] ) ) {
			if ( ! call_user_func( $rules['validate'], $value ) ) {
				self::$errors[ $field ] = sprintf( __( '%s is invalid', 'arriendo-facil' ), $field );
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if value matches type.
	 *
	 * @param mixed  $value Value to check.
	 * @param string $type Type name.
	 *
	 * @return bool
	 */
	private static function check_type( $value, $type ) {
		switch ( $type ) {
			case 'string':
				return is_string( $value );
			case 'int':
				return is_numeric( $value ) && (int) $value == $value; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			case 'float':
				return is_numeric( $value );
			case 'bool':
				return is_bool( $value );
			case 'array':
				return is_array( $value );
			case 'email':
				return is_email( $value );
			case 'url':
				return filter_var( $value, FILTER_VALIDATE_URL );
			case 'phone':
				return preg_match( '/^[+]?[(]?[0-9]{1,4}[)]?[-\s.]?[(]?[0-9]{1,4}[)]?[-\s.]?[0-9]{1,9}$/', (string) $value );
			default:
				return true;
		}
	}

	/**
	 * Get all validation errors.
	 *
	 * @return array
	 */
	public static function get_errors() {
		return self::$errors;
	}

	/**
	 * Get last error message.
	 *
	 * @return string
	 */
	private static function get_last_error() {
		return end( self::$errors ) ?: __( 'Validation failed', 'arriendo-facil' );
	}

	/**
	 * Check if there are any errors.
	 *
	 * @return bool
	 */
	public static function has_errors() {
		return ! empty( self::$errors );
	}

	/**
	 * Sanitize string input (remove dangerous characters).
	 *
	 * @param string $value Value to sanitize.
	 *
	 * @return string
	 */
	public static function sanitize_string( $value ) {
		$value = sanitize_text_field( wp_unslash( $value ) );
		// Remove null bytes and control characters.
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		return $value;
	}

	/**
	 * Sanitize email.
	 *
	 * @param string $value Value to sanitize.
	 *
	 * @return string
	 */
	public static function sanitize_email( $value ) {
		return sanitize_email( wp_unslash( $value ) );
	}

	/**
	 * Sanitize URL.
	 *
	 * @param string $value Value to sanitize.
	 *
	 * @return string
	 */
	public static function sanitize_url( $value ) {
		return esc_url_raw( wp_unslash( $value ) );
	}

	/**
	 * Sanitize array (recursively).
	 *
	 * @param array $array Array to sanitize.
	 *
	 * @return array
	 */
	public static function sanitize_array( $array ) {
		if ( ! is_array( $array ) ) {
			return array();
		}

		return array_map(
			function ( $value ) {
				if ( is_array( $value ) ) {
					return self::sanitize_array( $value );
				}
				return self::sanitize_string( $value );
			},
			$array
		);
	}
}
