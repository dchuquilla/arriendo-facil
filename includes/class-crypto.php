<?php
/**
 * Central key management and authenticated encryption.
 *
 * Until now every module derived its encryption key from the WordPress salts in
 * wp-config.php. Anyone who learns those salts (a leaked config file or repository)
 * could decrypt a copy of the database and forge signed links. This class adds a
 * dedicated master key, AF_ENCRYPTION_KEY, that lives only in the server's wp-config.php
 * and is independent from the salts, so rotating the salts (or leaking them) no longer
 * affects stored data.
 *
 * - Subkeys are derived per purpose with HKDF-SHA256, so a leak of one purpose's
 *   ciphertext format cannot be replayed against another.
 * - New values are sealed with AES-256-GCM and carry the 'ENC3:' prefix. The purpose is
 *   authenticated as additional data, so a blob sealed for one purpose does not open
 *   under another.
 * - Without AF_ENCRYPTION_KEY the plugin keeps working exactly as before (legacy
 *   salt-derived keys). Old values stay readable at all times; the administrator-run
 *   re-encryption tool (Arriendo_Facil_Crypto_Migration) converts them.
 *
 * Generate a key once and keep a copy in a password manager (losing it makes sealed
 * data unreadable):   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 * wp-config.php:      define( 'AF_ENCRYPTION_KEY', '<64 hex characters>' );
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Crypto
 */
class Arriendo_Facil_Crypto {

	const PREFIX = 'ENC3:';

	const PURPOSE_SRI    = 'sri';
	const PURPOSE_OTA    = 'ota';
	const PURPOSE_ADMIN  = 'admin-pii';
	const PURPOSE_TENANT = 'tenant-pii';

	/**
	 * Whether a valid master key is configured.
	 *
	 * @return bool
	 */
	public static function has_master() {
		return 32 === strlen( self::master() );
	}

	/**
	 * Why the master key is not usable ('' when it is, or when it is simply not set).
	 *
	 * @return string
	 */
	public static function master_problem() {
		if ( ! defined( 'AF_ENCRYPTION_KEY' ) || '' === (string) AF_ENCRYPTION_KEY ) {
			return '';
		}

		return self::has_master() ? '' : 'AF_ENCRYPTION_KEY no es válida: debe tener 64 caracteres hexadecimales (32 bytes) y no ser repetitiva.';
	}

	/**
	 * Raw 32-byte master key, or '' when absent/invalid.
	 *
	 * @return string
	 */
	private static function master() {
		if ( ! defined( 'AF_ENCRYPTION_KEY' ) || ! is_string( AF_ENCRYPTION_KEY ) ) {
			return '';
		}

		$value = trim( AF_ENCRYPTION_KEY );
		if ( 0 === strpos( $value, 'base64:' ) ) {
			$raw = base64_decode( substr( $value, 7 ), true );
		} elseif ( 1 === preg_match( '/^[0-9a-fA-F]{64}$/', $value ) ) {
			$raw = hex2bin( $value );
		} else {
			$raw = false;
		}

		if ( ! is_string( $raw ) || 32 !== strlen( $raw ) || 1 === count( array_unique( str_split( $raw ) ) ) ) {
			return '';
		}

		return $raw;
	}

	/**
	 * Derives the 32-byte key for a purpose. '' when no master key is configured.
	 *
	 * @param string $purpose Purpose slug.
	 * @return string
	 */
	public static function key( $purpose ) {
		$master = self::master();
		if ( '' === $master ) {
			return '';
		}

		return hash_hkdf( 'sha256', $master, 32, 'arriendo-facil/v3/enc/' . (string) $purpose );
	}

	/**
	 * Whether a stored value was sealed by this class.
	 *
	 * @param string $blob Stored value.
	 * @return bool
	 */
	public static function is_sealed( $blob ) {
		return is_string( $blob ) && 0 === strpos( $blob, self::PREFIX );
	}

	/**
	 * Encrypts a value for a purpose (AES-256-GCM, purpose bound as AAD).
	 *
	 * @param string $plain   Plain text.
	 * @param string $purpose Purpose slug.
	 * @return string 'ENC3:…' payload, or '' when sealing is not possible.
	 */
	public static function seal( $plain, $purpose ) {
		$plain = (string) $plain;
		$key   = self::key( $purpose );
		if ( '' === $plain || '' === $key ) {
			return '';
		}

		$iv  = random_bytes( 12 );
		$tag = '';
		$enc = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, (string) $purpose, 16 );
		if ( false === $enc || 16 !== strlen( $tag ) ) {
			return '';
		}

		return self::PREFIX . base64_encode( $iv . $tag . $enc );
	}

	/**
	 * Decrypts a sealed value.
	 *
	 * @param string $blob    'ENC3:…' payload.
	 * @param string $purpose Purpose slug it was sealed for.
	 * @return string|false Plain text, or false when invalid, tampered or the key is missing.
	 */
	public static function open( $blob, $purpose ) {
		$key = self::key( $purpose );
		if ( '' === $key || ! self::is_sealed( $blob ) ) {
			return false;
		}

		$raw = base64_decode( substr( (string) $blob, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= 28 ) {
			return false;
		}

		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), (string) $purpose );

		return false === $plain ? false : (string) $plain;
	}

	/**
	 * Key for HMAC signatures: derived from the master key when configured, otherwise the
	 * legacy (salt-based) secret supplied by the caller, so behaviour is unchanged until the
	 * key is set.
	 *
	 * @param string $purpose       Purpose slug.
	 * @param string $legacy_secret Secret used before AF_ENCRYPTION_KEY existed.
	 * @return string
	 */
	public static function hmac_secret( $purpose, $legacy_secret ) {
		$master = self::master();
		if ( '' === $master ) {
			return (string) $legacy_secret;
		}

		return hash_hkdf( 'sha256', $master, 32, 'arriendo-facil/v3/hmac/' . (string) $purpose );
	}

	/**
	 * Deterministic identity fingerprint (HMAC of the normalised document number).
	 *
	 * @param string $normalized    Upper-case alphanumeric document number.
	 * @param string $purpose       Purpose slug.
	 * @param string $legacy_secret Legacy secret used before the master key existed.
	 * @return string Hex digest.
	 */
	public static function fingerprint( $normalized, $purpose, $legacy_secret ) {
		return hash_hmac( 'sha256', (string) $normalized, self::hmac_secret( $purpose, $legacy_secret ) );
	}

	/**
	 * Legacy salt-derived key used by the 'v1:' sodium payloads.
	 *
	 * @param string $slug 'af_admin_sensitive_v1' or 'af_tenant_sensitive_v1'.
	 * @return string 32 bytes.
	 */
	public static function legacy_v1_key( $slug ) {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . $slug, true );
	}

	/**
	 * Opens a legacy 'v1:' sodium payload.
	 *
	 * @param string $blob 'v1:' payload.
	 * @param string $slug Key slug.
	 * @return string|false
	 */
	public static function open_legacy_v1( $blob, $slug ) {
		$blob = (string) $blob;
		if ( 0 !== strpos( $blob, 'v1:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return false;
		}

		$payload = base64_decode( substr( $blob, 3 ), true );
		if ( false === $payload || strlen( $payload ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}

		$plain = sodium_crypto_secretbox_open( substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::legacy_v1_key( $slug ) );

		return false === $plain ? false : (string) $plain;
	}

	/**
	 * Re-encrypts a legacy 'v1:' payload under the master key, verifying the round trip.
	 *
	 * @param string $blob    'v1:' payload.
	 * @param string $slug    Legacy key slug.
	 * @param string $purpose New purpose.
	 * @return string 'ENC3:…' payload or '' when it could not be converted safely.
	 */
	public static function rekey_legacy_v1( $blob, $slug, $purpose ) {
		$plain = self::open_legacy_v1( $blob, $slug );
		if ( false === $plain || '' === $plain ) {
			return '';
		}

		$sealed = self::seal( $plain, $purpose );

		return ( '' !== $sealed && self::open( $sealed, $purpose ) === $plain ) ? $sealed : '';
	}
}
