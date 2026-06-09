<?php
/**
 * SRI configuration management.
 *
 * Handles all configuration for the Ecuadorian electronic billing system (SRI):
 * company/issuer data, P12 digital certificate, emission environment, and
 * utility helpers (RUC validation, password encryption).
 *
 * @package Arriendo_Facil\Billing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_SRI_Config
 */
class Arriendo_Facil_SRI_Config {

	/** WordPress option key that stores all SRI general settings. */
	const OPTION_KEY = 'af_sri_config';

	/** OpenSSL cipher used to protect the certificate password at rest. */
	const CIPHER = 'aes-256-cbc';

	/** Authenticated cipher used for sensitive data at rest. */
	const AEAD_CIPHER = 'aes-256-gcm';

	/** Prefix for authenticated encrypted payloads. */
	const AEAD_PREFIX = 'ENC2:';

	/** Prefix for legacy CBC encrypted payloads. */
	const LEGACY_PREFIX = 'ENC1:';

	// ─── Config read / write ─────────────────────────────────────────────────

	/**
	 * Returns the full SRI configuration merged with defaults.
	 *
	 * @return array<string, string>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge(
			array(
				'ruc'                   => '',
				'razon_social'          => '',
				'nombre_comercial'      => '',
				'dir_establecimiento'   => '',
				'dir_matriz'            => '',
				'obligado_contabilidad' => 'NO',
				'ambiente'              => '1',   // 1 = pruebas, 2 = producción
				'tipo_emision'          => '1',   // 1 = normal (offline)
				'cert_filename'         => '',    // filename within cert_dir()
				'cert_password_enc'     => '',    // AES-256-GCM encrypted password
				'cert_pem_enc'          => '',    // AES-256-GCM encrypted certificate PEM
				'pkey_pem_enc'          => '',    // AES-256-GCM encrypted private key PEM
				'email_notificacion'    => '',
				'sri_soap_timeout'      => '30',  // seconds
				'sri_soap_max_retries'  => '3',   // immediate retries for transient errors
			),
			$saved
		);
	}

	/**
	 * Persists the general SRI configuration (does NOT touch cert fields).
	 *
	 * @param array<string, string> $data Unsanitized input values.
	 * @return bool
	 */
	public static function save( array $data ): bool {
		$current = self::get();

		$allowed_text = array(
			'ruc', 'razon_social', 'nombre_comercial',
			'dir_establecimiento', 'dir_matriz', 'email_notificacion',
		);

		foreach ( $allowed_text as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$current[ $key ] = sanitize_text_field( wp_unslash( (string) $data[ $key ] ) );
			}
		}

		if ( isset( $data['ambiente'] ) && in_array( $data['ambiente'], array( '1', '2' ), true ) ) {
			$current['ambiente'] = $data['ambiente'];
		}

		if ( isset( $data['obligado_contabilidad'] ) ) {
			$current['obligado_contabilidad'] = ( 'SI' === strtoupper( sanitize_text_field( (string) $data['obligado_contabilidad'] ) ) ) ? 'SI' : 'NO';
		}

		return (bool) update_option( self::OPTION_KEY, $current );
	}

	/**
	 * Encrypts and persists the certificate password.
	 * Only updates if a non-empty password is provided.
	 *
	 * @param string $plain_password Plain-text P12 password.
	 * @return bool
	 */
	public static function save_cert_password( string $plain_password ): bool {
		if ( '' === trim( $plain_password ) ) {
			return false;
		}
		$current                      = self::get();
		$current['cert_password_enc'] = self::encrypt_password( $plain_password );
		return (bool) update_option( self::OPTION_KEY, $current );
	}

	// ─── Certificate management ──────────────────────────────────────────────

	/**
	 * Handles a P12/PFX certificate file upload.
	 * Validates the file, moves it to the secure directory, and updates config.
	 *
	 * @param array $file $_FILES element (e.g. $_FILES['af_cert_file']).
	 * @return true|WP_Error
	 */
	public static function upload_certificate( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'No se recibió ningún archivo.', 'arriendo-facil' ) );
		}

		$ext = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'p12', 'pfx' ), true ) ) {
			return new WP_Error( 'invalid_ext', __( 'El certificado debe ser un archivo .p12 o .pfx.', 'arriendo-facil' ) );
		}

		if ( isset( $file['size'] ) && (int) $file['size'] > 1 * 1024 * 1024 ) {
			return new WP_Error( 'file_too_large', __( 'El certificado no puede superar 1 MB.', 'arriendo-facil' ) );
		}

		$cert_dir = self::cert_dir();
		if ( ! $cert_dir ) {
			return new WP_Error( 'dir_error', __( 'No se pudo crear el directorio seguro para certificados.', 'arriendo-facil' ) );
		}

		// Randomize filename to prevent enumeration.
		$filename  = 'cert_' . bin2hex( random_bytes( 8 ) ) . '.p12';
		$dest_path = $cert_dir . DIRECTORY_SEPARATOR . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
			return new WP_Error( 'move_failed', __( 'No se pudo mover el certificado al directorio seguro.', 'arriendo-facil' ) );
		}

		// Remove old certificate file if one existed.
		$current = self::get();
		if ( ! empty( $current['cert_filename'] ) ) {
			$old_path = $cert_dir . DIRECTORY_SEPARATOR . basename( (string) $current['cert_filename'] );
			if ( file_exists( $old_path ) ) {
				@unlink( $old_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		$current['cert_filename'] = $filename;
		update_option( self::OPTION_KEY, $current );

		return true;
	}

	/**
	 * Returns the absolute path to the active certificate, or empty string if none.
	 *
	 * @return string
	 */
	public static function cert_path(): string {
		$config = self::get();
		if ( empty( $config['cert_filename'] ) ) {
			return '';
		}
		$path = self::cert_dir() . DIRECTORY_SEPARATOR . basename( (string) $config['cert_filename'] );
		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Returns the decrypted certificate password, or empty string.
	 *
	 * @return string
	 */
	public static function cert_password(): string {
		$config = self::get();
		if ( empty( $config['cert_password_enc'] ) ) {
			return '';
		}
		return self::decrypt_password( (string) $config['cert_password_enc'] );
	}

	/**
	 * Encrypts and persists the extracted PEM certificate and private key.
	 *
	 * @param string $cert_pem PEM-encoded certificate.
	 * @param string $pkey_pem PEM-encoded private key.
	 * @return bool
	 */
	public static function save_cert_pems( string $cert_pem, string $pkey_pem ): bool {
		if ( '' === $cert_pem || '' === $pkey_pem ) {
			return false;
		}
		$current                 = self::get();
		$current['cert_pem_enc'] = self::protect_sensitive( $cert_pem );
		$current['pkey_pem_enc'] = self::protect_sensitive( $pkey_pem );
		return (bool) update_option( self::OPTION_KEY, $current );
	}

	/**
	 * Returns the decrypted PEM certificate and private key.
	 *
	 * @return array{cert: string, pkey: string} Both empty strings if not available.
	 */
	public static function get_cert_pems(): array {
		$config = self::get();
		$cert   = '';
		$pkey   = '';
		if ( ! empty( $config['cert_pem_enc'] ) ) {
			$cert = self::unprotect_sensitive( (string) $config['cert_pem_enc'] );
		}
		if ( ! empty( $config['pkey_pem_enc'] ) ) {
			$pkey = self::unprotect_sensitive( (string) $config['pkey_pem_enc'] );
		}
		return array( 'cert' => $cert, 'pkey' => $pkey );
	}

	/**
	 * Tests that a P12 certificate can be opened with the given password and
	 * checks whether it is still within its validity period.
	 *
	 * @param string $path     Absolute path to the .p12 file.
	 * @param string $password Certificate password.
	 * @return true|WP_Error
	 */
	public static function test_certificate( string $path, string $password ) {
		$result = self::read_p12( $path, $password );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::validate_cert_pem( $result['cert'], $result['pkey'] );
	}

	/**
	 * Validates stored PEM certificate: checks it is complete and not expired.
	 *
	 * @return true|WP_Error
	 */
	public static function test_stored_certificate() {
		$pems = self::get_cert_pems();
		if ( '' === $pems['cert'] || '' === $pems['pkey'] ) {
			return new WP_Error( 'no_pems', __( 'No hay datos de certificado almacenados. Suba el certificado nuevamente.', 'arriendo-facil' ) );
		}
		return self::validate_cert_pem( $pems['cert'], $pems['pkey'] );
	}

	/**
	 * Validates a PEM certificate + key pair: checks completeness and expiry.
	 *
	 * @param string $cert_pem PEM certificate.
	 * @param string $pkey_pem PEM private key.
	 * @return true|WP_Error
	 */
	private static function validate_cert_pem( string $cert_pem, string $pkey_pem ) {
		if ( '' === $cert_pem || '' === $pkey_pem ) {
			return new WP_Error( 'incomplete_cert', __( 'El certificado no contiene los datos esperados (certificado + clave privada).', 'arriendo-facil' ) );
		}

		$cert_info = openssl_x509_parse( $cert_pem );
		if ( false === $cert_info ) {
			return new WP_Error( 'parse_error', __( 'No se pudo analizar el certificado X.509.', 'arriendo-facil' ) );
		}

		$valid_to = isset( $cert_info['validTo_time_t'] ) ? (int) $cert_info['validTo_time_t'] : 0;
		if ( $valid_to > 0 && $valid_to < time() ) {
			return new WP_Error(
				'cert_expired',
				sprintf(
					/* translators: %s: certificate expiration date */
					__( 'El certificado venció el %s. Renuévelo en el Banco Central del Ecuador o en una entidad de certificación autorizada.', 'arriendo-facil' ),
					wp_date( 'd/m/Y', $valid_to )
				)
			);
		}

		return true;
	}

	/**
	 * Returns the secure directory for P12 certificates, creating it if needed.
	 * Places a .htaccess and index.php to block direct HTTP access.
	 *
	 * @return string Absolute path, or empty string on failure.
	 */
	public static function cert_dir(): string {
		$dir = WP_CONTENT_DIR . '/af-certs';

		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// Ensure protection files always exist.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	// ─── RUC validation ──────────────────────────────────────────────────────

	/**
	 * Validates an Ecuadorian RUC number (all three entity types).
	 *
	 * @param string $ruc RUC to validate.
	 * @return true|WP_Error
	 */
	public static function validate_ruc( string $ruc ) {
		$ruc = preg_replace( '/\D/', '', $ruc );

		if ( 13 !== strlen( $ruc ) ) {
			return new WP_Error( 'invalid_length', __( 'El RUC debe tener exactamente 13 dígitos.', 'arriendo-facil' ) );
		}

		$province = (int) substr( $ruc, 0, 2 );
		if ( $province < 1 || $province > 24 ) {
			return new WP_Error( 'invalid_province', __( 'Los dos primeros dígitos del RUC no corresponden a una provincia válida (01-24).', 'arriendo-facil' ) );
		}

		$third = (int) $ruc[2];

		if ( $third <= 5 ) {
			// Persona natural: validate cédula (first 10) + suffix >= 001.
			$cedula_result = self::validate_cedula_digits( substr( $ruc, 0, 10 ) );
			if ( is_wp_error( $cedula_result ) ) {
				return $cedula_result;
			}
			if ( (int) substr( $ruc, 10, 3 ) < 1 ) {
				return new WP_Error( 'invalid_suffix', __( 'Los últimos 3 dígitos del RUC de persona natural deben ser ≥ 001.', 'arriendo-facil' ) );
			}
		} elseif ( 9 === $third ) {
			// Sociedad privada: coeficientes 4,3,2,7,6,5,4,3,2 sobre los primeros 9 dígitos.
			$coefficients = array( 4, 3, 2, 7, 6, 5, 4, 3, 2 );
			$sum          = 0;
			for ( $i = 0; $i < 9; $i++ ) {
				$sum += (int) $ruc[ $i ] * $coefficients[ $i ];
			}
			$verifier = 11 - ( $sum % 11 );
			if ( 11 === $verifier ) {
				$verifier = 0;
			}
			if ( 10 === $verifier || $verifier !== (int) $ruc[9] ) {
				return new WP_Error( 'invalid_ruc', __( 'El dígito verificador del RUC de sociedad privada no es correcto.', 'arriendo-facil' ) );
			}
			if ( (int) substr( $ruc, 10, 3 ) < 1 ) {
				return new WP_Error( 'invalid_suffix', __( 'Los últimos 3 dígitos del RUC de persona jurídica deben ser ≥ 001.', 'arriendo-facil' ) );
			}
		} elseif ( 6 === $third ) {
			// Entidad pública: coeficientes 3,2,7,6,5,4,3,2 sobre los primeros 8 dígitos.
			$coefficients = array( 3, 2, 7, 6, 5, 4, 3, 2 );
			$sum          = 0;
			for ( $i = 0; $i < 8; $i++ ) {
				$sum += (int) $ruc[ $i ] * $coefficients[ $i ];
			}
			$verifier = 11 - ( $sum % 11 );
			if ( 11 === $verifier ) {
				$verifier = 0;
			}
			if ( $verifier !== (int) $ruc[8] ) {
				return new WP_Error( 'invalid_ruc', __( 'El dígito verificador del RUC de entidad pública no es correcto.', 'arriendo-facil' ) );
			}
		} else {
			return new WP_Error( 'invalid_third_digit', __( 'El tercer dígito del RUC no es válido (debe ser 0-6 o 9).', 'arriendo-facil' ) );
		}

		return true;
	}

	/**
	 * Validates the check digit of a 10-digit Ecuadorian cédula string.
	 *
	 * @param string $cedula 10-digit cédula.
	 * @return true|WP_Error
	 */
	private static function validate_cedula_digits( string $cedula ) {
		$sum = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$digit = (int) $cedula[ $i ];
			if ( 0 === $i % 2 ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}
			$sum += $digit;
		}
		$verifier = ( 10 - ( $sum % 10 ) ) % 10;
		if ( $verifier !== (int) $cedula[9] ) {
			return new WP_Error( 'invalid_cedula', __( 'La cédula incluida en el RUC no pasa la validación del dígito verificador.', 'arriendo-facil' ) );
		}
		return true;
	}

	// ─── Encryption helpers ──────────────────────────────────────────────────

	/**
	 * Encrypts a plain-text password using AES-256-CBC with a key derived
	 * from WordPress authentication salts.
	 *
	 * @param string $plain Plain-text password.
	 * @return string Base64-encoded blob (IV prepended to ciphertext).
	 */
	public static function encrypt_password( string $plain ): string {
		return self::protect_sensitive( $plain );
	}

	/**
	 * Decrypts a password encrypted by encrypt_password().
	 *
	 * @param string $encrypted Base64-encoded blob.
	 * @return string Plain-text password, or empty string on failure.
	 */
	public static function decrypt_password( string $encrypted ): string {
		if ( self::starts_with( $encrypted, self::AEAD_PREFIX ) ) {
			return self::unprotect_sensitive( $encrypted );
		}

		if ( self::starts_with( $encrypted, self::LEGACY_PREFIX ) ) {
			return self::legacy_decrypt( substr( $encrypted, strlen( self::LEGACY_PREFIX ) ) );
		}

		// Backward compatibility for legacy AES-256-CBC payloads (without prefix).
		$raw = base64_decode( $encrypted, true );
		if ( false === $raw ) {
			return '';
		}

		$iv_len = (int) openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $raw ) <= $iv_len ) {
			return '';
		}

		$iv         = substr( $raw, 0, $iv_len );
		$ciphertext = substr( $raw, $iv_len );
		$key        = substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ), 0, 32 );
		$plain      = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Encrypts sensitive data with authenticated encryption (AES-256-GCM).
	 *
	 * @param string $plain Plain text.
	 * @return string Protected payload prefixed with AEAD_PREFIX.
	 */
	public static function protect_sensitive( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$key = substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ), 0, 32 );

		$iv_len = (int) openssl_cipher_iv_length( self::AEAD_CIPHER );
		if ( $iv_len <= 0 ) {
			return '';
		}

		$iv  = random_bytes( $iv_len );
		$tag = '';
		$enc = openssl_encrypt( $plain, self::AEAD_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $enc || '' === $tag ) {
			return self::legacy_encrypt( $plain );
		}

		return self::AEAD_PREFIX . base64_encode( $iv . $tag . $enc );
	}

	/**
	 * Decrypts sensitive data protected by protect_sensitive().
	 *
	 * @param string $protected Protected payload.
	 * @return string Decrypted text or empty string on failure.
	 */
	public static function unprotect_sensitive( string $protected ): string {
		if ( '' === $protected ) {
			return '';
		}

		if ( self::starts_with( $protected, self::AEAD_PREFIX ) ) {
			$protected = substr( $protected, strlen( self::AEAD_PREFIX ) );
		} elseif ( self::starts_with( $protected, self::LEGACY_PREFIX ) ) {
			return self::legacy_decrypt( substr( $protected, strlen( self::LEGACY_PREFIX ) ) );
		}

		$raw = base64_decode( $protected, true );
		if ( false === $raw ) {
			return '';
		}

		$iv_len = (int) openssl_cipher_iv_length( self::AEAD_CIPHER );
		$tag_len = 16;
		if ( strlen( $raw ) <= ( $iv_len + $tag_len ) ) {
			return '';
		}

		$iv         = substr( $raw, 0, $iv_len );
		$tag        = substr( $raw, $iv_len, $tag_len );
		$ciphertext = substr( $raw, $iv_len + $tag_len );
		$key        = substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ), 0, 32 );

		$plain = openssl_decrypt( $ciphertext, self::AEAD_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : (string) $plain;
	}

	/**
	 * Legacy AES-256-CBC encryption helper.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	private static function legacy_encrypt( string $plain ): string {
		$key    = substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ), 0, 32 );
		$iv_len = (int) openssl_cipher_iv_length( self::CIPHER );
		$iv     = random_bytes( $iv_len );
		$enc    = (string) openssl_encrypt( $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		return self::LEGACY_PREFIX . base64_encode( $iv . $enc );
	}

	/**
	 * Legacy AES-256-CBC decryption helper.
	 *
	 * @param string $encrypted Base64 blob.
	 * @return string
	 */
	private static function legacy_decrypt( string $encrypted ): string {
		$raw = base64_decode( $encrypted, true );
		if ( false === $raw ) {
			return '';
		}

		$iv_len = (int) openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $raw ) <= $iv_len ) {
			return '';
		}

		$iv         = substr( $raw, 0, $iv_len );
		$ciphertext = substr( $raw, $iv_len );
		$key        = substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ), 0, 32 );
		$plain      = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return ( false === $plain ) ? '' : (string) $plain;
	}

	/**
	 * PHP 7.4-safe starts-with helper.
	 *
	 * @param string $haystack Full string.
	 * @param string $needle Prefix to match.
	 * @return bool
	 */
	private static function starts_with( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}
		return 0 === strpos( $haystack, $needle );
	}

	/**
	 * Reads a P12/PFX certificate, falling back to OpenSSL CLI with -legacy
	 * flag when PHP's openssl_pkcs12_read() fails (OpenSSL 3.x + legacy certs).
	 *
	 * @param string $path     Absolute path to the .p12 file.
	 * @param string $password Certificate password.
	 * @return array|WP_Error Array with keys 'cert' and 'pkey', or WP_Error on failure.
	 */
	public static function read_p12( string $path, string $password ) {
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'not_found', __( 'Archivo de certificado no encontrado.', 'arriendo-facil' ) );
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return new WP_Error( 'read_error', __( 'No se pudo leer el archivo de certificado.', 'arriendo-facil' ) );
		}

		// Flush stale OpenSSL errors.
		while ( openssl_error_string() ) {
			// flush
		}

		// Try native PHP first.
		$certs = array();
		if ( openssl_pkcs12_read( $contents, $certs, $password ) ) {
			return $certs;
		}

		// Native failed — try CLI with -legacy flag (OpenSSL 3.x compat).
		return self::read_p12_legacy_cli( $path, $password );
	}

	/**
	 * Extracts cert + private key from a P12 file using the openssl CLI tool
	 * with the -legacy flag to support old RC2/3DES algorithms.
	 * Tries multiple PHP execution functions for maximum hosting compatibility.
	 *
	 * @param string $path     Absolute path to the .p12 file.
	 * @param string $password Certificate password.
	 * @return array|WP_Error Array with keys 'cert' and 'pkey', or WP_Error.
	 */
	private static function read_p12_legacy_cli( string $path, string $password ) {
		$pass_file = tempnam( sys_get_temp_dir(), 'af_pass_' );
		if ( false === $pass_file ) {
			return new WP_Error( 'tmp_error', __( 'No se pudo crear archivo temporal.', 'arriendo-facil' ) );
		}
		file_put_contents( $pass_file, $password ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$escaped_path      = escapeshellarg( $path );
		$escaped_pass_file = escapeshellarg( $pass_file );

		$cert_cmd = sprintf(
			'openssl pkcs12 -in %s -passin file:%s -clcerts -nokeys -legacy 2>&1',
			$escaped_path,
			$escaped_pass_file
		);
		$key_cmd = sprintf(
			'openssl pkcs12 -in %s -passin file:%s -nocerts -nodes -legacy 2>&1',
			$escaped_path,
			$escaped_pass_file
		);

		$cert_output = self::run_shell_command( $cert_cmd );
		$key_output  = self::run_shell_command( $key_cmd );

		@unlink( $pass_file );

		if ( null === $cert_output || null === $key_output ) {
			return new WP_Error(
				'shell_disabled',
				__( 'No se puede ejecutar comandos de shell en este servidor. Suba un certificado .p12 convertido a formato moderno o contacte a su proveedor de hosting para habilitar shell_exec/exec.', 'arriendo-facil' )
			);
		}

		$cert_pem = self::extract_pem_block( $cert_output, 'CERTIFICATE' );
		$key_pem  = self::extract_pem_block( $key_output, 'PRIVATE KEY' );

		if ( '' === $cert_pem || '' === $key_pem ) {
			$combined_output = trim( $cert_output . "\n" . $key_output );
			return new WP_Error(
				'legacy_cli_failed',
				sprintf(
					__( 'No se pudo extraer el certificado con openssl -legacy. Salida: %s', 'arriendo-facil' ),
					substr( $combined_output, 0, 300 )
				)
			);
		}

		return array(
			'cert' => $cert_pem,
			'pkey' => $key_pem,
		);
	}

	/**
	 * Attempts to run a shell command using multiple PHP functions for
	 * compatibility across hosting environments.
	 *
	 * @param string $command The command to run.
	 * @return string|null Output string or null if all methods are unavailable.
	 */
	private static function run_shell_command( string $command ): ?string {
		// Try shell_exec.
		if ( function_exists( 'shell_exec' ) && ! self::is_function_disabled( 'shell_exec' ) ) {
			$result = @shell_exec( $command ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( null !== $result ) {
				return (string) $result;
			}
		}

		// Try exec.
		if ( function_exists( 'exec' ) && ! self::is_function_disabled( 'exec' ) ) {
			$output     = array();
			$return_var = -1;
			@exec( $command, $output, $return_var ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return implode( "\n", $output );
		}

		// Try proc_open.
		if ( function_exists( 'proc_open' ) && ! self::is_function_disabled( 'proc_open' ) ) {
			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			$proc = @proc_open( $command, $descriptors, $pipes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_resource( $proc ) ) {
				fclose( $pipes[0] );
				$stdout = stream_get_contents( $pipes[1] );
				$stderr = stream_get_contents( $pipes[2] );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				proc_close( $proc );
				return $stdout . $stderr;
			}
		}

		return null;
	}

	/**
	 * Checks if a function is in the disabled_functions list.
	 *
	 * @param string $function Function name.
	 * @return bool
	 */
	private static function is_function_disabled( string $function ): bool {
		$disabled = ini_get( 'disable_functions' );
		if ( false === $disabled || '' === $disabled ) {
			return false;
		}
		return in_array( $function, array_map( 'trim', explode( ',', $disabled ) ), true );
	}

	/**
	 * Extracts a PEM block from CLI output.
	 *
	 * @param string $output  Raw CLI output.
	 * @param string $type    PEM type (e.g. 'CERTIFICATE', 'PRIVATE KEY').
	 * @return string PEM block or empty string.
	 */
	private static function extract_pem_block( string $output, string $type ): string {
		$begin = "-----BEGIN {$type}-----";
		$end   = "-----END {$type}-----";
		$start = strpos( $output, $begin );
		if ( false === $start ) {
			return '';
		}
		$finish = strpos( $output, $end, $start );
		if ( false === $finish ) {
			return '';
		}
		return substr( $output, $start, $finish - $start + strlen( $end ) );
	}
}
