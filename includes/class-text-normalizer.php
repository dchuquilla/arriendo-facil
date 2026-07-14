<?php
/**
 * Text Normalizer – case and formatting utility.
 *
 * Centralizes all text-normalization rules for form input before DB persistence.
 *
 * Rules:
 *  - Proper names / addresses : Title Case (each word capitalized).
 *  - Emails                   : strict lowercase.
 *  - Documents (cedula/ruc)   : digits only.
 *  - Documents (pasaporte)    : strip spaces/dashes, uppercase.
 *  - Search queries           : lowercase + remove accents/diacritics.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AF_Text_Normalizer {

	/**
	 * Normalize a proper name or address: capitalize each word.
	 * Handles all-caps ("WALTER"), all-lowercase ("walter") and mixed ("waLter").
	 *
	 * @param string $raw Raw input (may already be sanitized).
	 * @return string
	 */
	public static function proper_name( string $raw ): string {
		$raw = sanitize_text_field( trim( $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		// mb_strtolower first so ALL-CAPS words are handled correctly,
		// then MB_CASE_TITLE capitalizes the first letter of each word.
		return mb_convert_case( mb_strtolower( $raw, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
	}

	/**
	 * Normalize an email address to strict lowercase.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function email( string $raw ): string {
		return strtolower( sanitize_email( trim( $raw ) ) );
	}

	/**
	 * Normalize a document number.
	 *  - cedula / ruc : strip non-digits.
	 *  - pasaporte    : strip spaces and dashes, force uppercase.
	 *
	 * @param string $type Document type: 'cedula', 'ruc', or 'pasaporte'.
	 * @param string $raw  Raw input.
	 * @return string
	 */
	public static function document( string $type, string $raw ): string {
		$raw = trim( $raw );
		if ( 'cedula' === $type || 'ruc' === $type ) {
			return preg_replace( '/\D/', '', $raw );
		}
		// pasaporte: remove spaces/dashes, uppercase.
		return strtoupper( preg_replace( '/[\s\-]/', '', $raw ) );
	}

	/**
	 * Normalize a free-text search query for accent/case-insensitive matching.
	 * Returns the string in lowercase with accents removed.
	 *
	 * @param string $raw Raw query string.
	 * @return string
	 */
	public static function search_query( string $raw ): string {
		$raw = sanitize_text_field( trim( $raw ) );
		$raw = mb_strtolower( $raw, 'UTF-8' );
		// WordPress built-in: removes diacritics (tildes, ü, ñ, etc.)
		return remove_accents( $raw );
	}
}
