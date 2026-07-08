<?php
/**
 * OTA Credentials management with encryption.
 *
 * Handles secure storage and retrieval of API credentials for OTA platforms
 * (Booking.com, Airbnb, etc.) using Sodium encryption (PHP 7.2+).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_OTA_Credentials
 *
 * Encrypts/decrypts and stores OTA API credentials in database.
 */
class Arriendo_Facil_OTA_Credentials {

	/**
	 * Saves encrypted credentials for an owner and OTA platform.
	 *
	 * @param int    $owner_id         WordPress user ID.
	 * @param string $platform         OTA platform identifier ('booking', 'airbnb').
	 * @param string $api_key          API key to encrypt.
	 * @param string $account_id       Account identifier on platform.
	 * @return bool True if saved, false otherwise.
	 */
	public static function save_encrypted( $owner_id, $platform, $api_key, $account_id ) {
		$owner_id = absint( $owner_id );
		$platform = sanitize_key( $platform );
		$api_key  = sanitize_text_field( $api_key );
		$account_id = sanitize_text_field( $account_id );

		if ( ! $owner_id || ! $platform || ! $api_key ) {
			return false;
		}

		$encrypted = self::encrypt( $api_key );
		if ( ! $encrypted ) {
			return false;
		}

		global $wpdb;
		$result = $wpdb->replace(
			$wpdb->prefix . 'af_ota_credentials',
			array(
				'owner_id'             => $owner_id,
				'ota_platform'         => $platform,
				'api_key_encrypted'    => $encrypted,
				'account_identifier'   => $account_id,
				'connected'            => 0,
				'status'               => 'inactive',
				'updated_at'           => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Retrieves and decrypts credentials for an owner and platform.
	 *
	 * @param int    $owner_id WordPress user ID.
	 * @param string $platform OTA platform identifier.
	 * @return array|null Array with 'api_key' and 'account_id' on success, null otherwise.
	 */
	public static function get_decrypted( $owner_id, $platform ) {
		$owner_id = absint( $owner_id );
		$platform = sanitize_key( $platform );

		if ( ! $owner_id || ! $platform ) {
			return null;
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT api_key_encrypted, account_identifier, status FROM {$wpdb->prefix}af_ota_credentials
				 WHERE owner_id = %d AND ota_platform = %s",
				$owner_id,
				$platform
			)
		);

		if ( ! $row ) {
			return null;
		}

		$decrypted_key = self::decrypt( $row->api_key_encrypted );
		if ( ! $decrypted_key ) {
			return null;
		}

		return array(
			'api_key' => $decrypted_key,
			'account_id' => $row->account_identifier,
			'status' => $row->status,
		);
	}

	/**
	 * Checks if credentials are configured and active for an owner and platform.
	 *
	 * @param int    $owner_id WordPress user ID.
	 * @param string $platform OTA platform identifier.
	 * @return bool True if configured and active.
	 */
	public static function is_configured( $owner_id, $platform ) {
		$owner_id = absint( $owner_id );
		$platform = sanitize_key( $platform );

		global $wpdb;
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}af_ota_credentials
				 WHERE owner_id = %d AND ota_platform = %s",
				$owner_id,
				$platform
			)
		);

		return 'active' === $status;
	}

	/**
	 * Marks credentials as connected/verified after successful test.
	 *
	 * @param int    $owner_id WordPress user ID.
	 * @param string $platform OTA platform identifier.
	 * @return bool True if updated, false otherwise.
	 */
	public static function mark_verified( $owner_id, $platform ) {
		$owner_id = absint( $owner_id );
		$platform = sanitize_key( $platform );

		global $wpdb;
		$result = $wpdb->update(
			$wpdb->prefix . 'af_ota_credentials',
			array(
				'connected'      => 1,
				'last_verified'  => current_time( 'mysql' ),
				'status'         => 'active',
				'updated_at'     => current_time( 'mysql' ),
			),
			array(
				'owner_id'     => $owner_id,
				'ota_platform' => $platform,
			),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Marks credentials as disconnected/unverified.
	 *
	 * @param int    $owner_id WordPress user ID.
	 * @param string $platform OTA platform identifier.
	 * @return bool True if updated, false otherwise.
	 */
	public static function mark_disconnected( $owner_id, $platform ) {
		$owner_id = absint( $owner_id );
		$platform = sanitize_key( $platform );

		global $wpdb;
		$result = $wpdb->update(
			$wpdb->prefix . 'af_ota_credentials',
			array(
				'connected'      => 0,
				'status'         => 'inactive',
				'updated_at'     => current_time( 'mysql' ),
			),
			array(
				'owner_id'     => $owner_id,
				'ota_platform' => $platform,
			),
			array( '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Deletes credentials for an owner and platform.
	 *
	 * @param int    $owner_id WordPress user ID.
	 * @param string $platform OTA platform identifier.
	 * @return bool True if deleted, false otherwise.
	 */
	public static function delete_credentials( $owner_id, $platform ) {
		$owner_id = absint( $owner_id );
		$platform = sanitize_key( $platform );

		global $wpdb;
		$result = $wpdb->delete(
			$wpdb->prefix . 'af_ota_credentials',
			array(
				'owner_id'     => $owner_id,
				'ota_platform' => $platform,
			),
			array( '%d', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Encrypts data using Sodium (PHP 7.2+) or wp_hash fallback.
	 *
	 * Sodium provides authenticated encryption (XChaCha20-Poly1305).
	 * Falls back to wp_hash if Sodium not available.
	 *
	 * @param string $data Plain text to encrypt.
	 * @return string|bool Encrypted data (base64) or false on failure.
	 */
	private static function encrypt( $data ) {
		if ( ! is_string( $data ) || empty( $data ) ) {
			return false;
		}

		// Prefer Sodium if available (PHP 7.2+)
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			try {
				$key = self::get_encryption_key();
				if ( ! $key || 32 !== strlen( $key ) ) {
					return false;
				}

				$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$encrypted = sodium_crypto_secretbox( $data, $nonce );

				// Encode: nonce + encrypted data, all in base64
				return base64_encode( $nonce . $encrypted );
			} catch ( Exception $e ) {
				error_log( 'OTA encryption error: ' . $e->getMessage() );
				return false;
			}
		}

		// Fallback: use wp_hash (less secure, but better than plaintext)
		return wp_hash( $data );
	}

	/**
	 * Decrypts data encrypted with encrypt().
	 *
	 * @param string $data Encrypted data (base64).
	 * @return string|bool Decrypted plain text or false on failure.
	 */
	private static function decrypt( $data ) {
		if ( ! is_string( $data ) || empty( $data ) ) {
			return false;
		}

		// Try Sodium decryption first
		if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
			try {
				$key = self::get_encryption_key();
				if ( ! $key || 32 !== strlen( $key ) ) {
					return false;
				}

				$decoded = base64_decode( $data, true );
				if ( false === $decoded || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
					return false;
				}

				$nonce = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$encrypted = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

				$decrypted = sodium_crypto_secretbox_open( $encrypted, $nonce, $key );
				return false !== $decrypted ? $decrypted : false;
			} catch ( Exception $e ) {
				error_log( 'OTA decryption error: ' . $e->getMessage() );
				return false;
			}
		}

		// Fallback: return as-is (was stored with wp_hash)
		return $data;
	}

	/**
	 * Gets the encryption key from WordPress constants or derives one.
	 *
	 * Uses SECURE_AUTH_KEY and SECURE_AUTH_SALT if available,
	 * otherwise hashes combination of other security constants.
	 *
	 * @return string 32-byte key for Sodium encryption.
	 */
	private static function get_encryption_key() {
		// Use WordPress security constants
		$key_material = '';

		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$key_material .= SECURE_AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_SALT' ) ) {
			$key_material .= SECURE_AUTH_SALT;
		}

		// Fallback if security constants are weak
		if ( strlen( $key_material ) < 32 ) {
			if ( defined( 'AUTH_KEY' ) ) {
				$key_material .= AUTH_KEY;
			}
			if ( defined( 'AUTH_SALT' ) ) {
				$key_material .= AUTH_SALT;
			}
		}

		if ( empty( $key_material ) ) {
			return false;
		}

		// Hash to get consistent 32-byte key
		return hash( 'sha256', $key_material, true );
	}
}
