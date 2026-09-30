<?php
/**
 * Shared private object storage (Cloudflare R2 / S3-compatible), with a safe
 * local fallback when no credentials are configured.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Private_Storage
 *
 * Single SigV4 implementation for the S3-compatible bucket (Cloudflare R2).
 * Every module that stores contracts, identity documents or owner templates
 * goes through here, so files are shared across web nodes instead of living
 * on one server's local disk.
 */
class Arriendo_Facil_Private_Storage {

	const PROVIDER_R2 = 'cloudflare_r2';

	/**
	 * Whether R2/S3 credentials are configured on this site.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return ! is_wp_error( self::get_config() );
	}

	/**
	 * Whether R2 is the selected provider AND its credentials are valid.
	 *
	 * @return bool
	 */
	public static function is_r2_enabled() {
		return self::PROVIDER_R2 === self::setting( 'AF_STORAGE_PROVIDER', 'af_storage_provider', self::PROVIDER_R2 )
			&& self::is_configured();
	}

	/**
	 * Configured bucket name ('' when not configured).
	 *
	 * @return string
	 */
	public static function bucket() {
		$config = self::get_config();
		return is_wp_error( $config ) ? '' : $config['bucket'];
	}

	/**
	 * Validates the storage configuration and returns the error, if any.
	 *
	 * @return true|WP_Error
	 */
	public static function check_config() {
		$config = self::get_config();
		return is_wp_error( $config ) ? $config : true;
	}

	/**
	 * Uploads raw contents to the private bucket.
	 *
	 * @param string $contents   File contents.
	 * @param string $object_key Object key (path) within the bucket.
	 * @param string $mime_type  Mime type.
	 * @return true|WP_Error
	 */
	public static function upload( $contents, $object_key, $mime_type ) {
		$config = self::get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$response = self::signed_request( $config, 'PUT', $object_key, (string) $contents, (string) $mime_type, 45 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'af_storage_upload_failed',
				sprintf( '%s (HTTP %d)', __( 'No se pudo subir el archivo al almacenamiento privado.', 'arriendo-facil' ), $status )
			);
		}

		return true;
	}

	/**
	 * Downloads a private object.
	 *
	 * @param string $object_key Object key (path) within the bucket.
	 * @param int    $timeout    Request timeout in seconds.
	 * @return string|WP_Error Raw bytes.
	 */
	public static function download( $object_key, $timeout = 60 ) {
		$config = self::get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( '' === trim( (string) $object_key ) ) {
			return new WP_Error( 'af_storage_missing_key', __( 'Falta la clave de objeto.', 'arriendo-facil' ) );
		}

		$response = self::signed_request( $config, 'GET', $object_key, '', '', $timeout );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'af_storage_download_failed',
				sprintf( '%s (HTTP %d)', __( 'No se pudo descargar el archivo del almacenamiento privado.', 'arriendo-facil' ), $status )
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new WP_Error( 'af_storage_empty_object', __( 'El archivo descargado está vacío.', 'arriendo-facil' ) );
		}

		return $body;
	}

	/**
	 * Downloads a private object into a request-scoped temp file. The caller
	 * owns the file and must unlink() it.
	 *
	 * @param string $object_key Object key.
	 * @param string $name_hint  Temp file name hint.
	 * @return string|WP_Error Absolute temp file path.
	 */
	public static function download_to_temp_file( $object_key, $name_hint = 'af_object' ) {
		$body = self::download( $object_key );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp_file = wp_tempnam( $name_hint );
		if ( ! $tmp_file || false === file_put_contents( $tmp_file, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( $tmp_file ) {
				wp_delete_file( $tmp_file );
			}
			return new WP_Error( 'af_storage_temp_write_failed', __( 'No se pudo escribir el archivo temporal.', 'arriendo-facil' ) );
		}

		return $tmp_file;
	}

	/**
	 * Builds a short-lived, signed GET URL for a private object.
	 *
	 * @param string $object_key Object key (path) within the bucket.
	 * @param int    $expires    Expiration in seconds (max 3600).
	 * @return string|WP_Error
	 */
	public static function presigned_get_url( $object_key, $expires = 300 ) {
		$config = self::get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$object_key = ltrim( (string) $object_key, '/' );
		if ( '' === $object_key ) {
			return new WP_Error( 'af_storage_missing_key', __( 'Falta la clave de objeto.', 'arriendo-facil' ) );
		}

		$expires       = max( 60, min( 3600, absint( $expires ) ) );
		$amz_date      = gmdate( 'Ymd\\THis\\Z' );
		$date_stamp    = gmdate( 'Ymd' );
		$scope         = self::credential_scope( $config, $date_stamp );
		$canonical_uri = self::canonical_uri( $config, $object_key );

		$query_params = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => rawurlencode( $config['access_key'] . '/' . $scope ),
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string) $expires,
			'X-Amz-SignedHeaders' => 'host',
		);
		ksort( $query_params );

		$canonical_query = '';
		foreach ( $query_params as $key => $value ) {
			$canonical_query .= ( '' !== $canonical_query ? '&' : '' ) . rawurlencode( (string) $key ) . '=' . (string) $value;
		}

		$canonical_request = "GET\n{$canonical_uri}\n{$canonical_query}\nhost:{$config['host']}\n\nhost\nUNSIGNED-PAYLOAD";
		$signature         = self::sign( $config, $date_stamp, $amz_date, $scope, $canonical_request );

		return $config['endpoint'] . $canonical_uri . '?' . $canonical_query . '&X-Amz-Signature=' . rawurlencode( $signature );
	}

	/**
	 * Executes a header-signed (SigV4) request against the bucket.
	 *
	 * @param array  $config     Validated config.
	 * @param string $method     PUT|GET.
	 * @param string $object_key Object key.
	 * @param string $body       Request body (PUT only).
	 * @param string $mime_type  Content type (PUT only).
	 * @param int    $timeout    Timeout in seconds.
	 * @return array|WP_Error
	 */
	private static function signed_request( array $config, $method, $object_key, $body, $mime_type, $timeout ) {
		$is_put         = 'PUT' === $method;
		$payload_hash   = $is_put ? hash( 'sha256', $body ) : 'UNSIGNED-PAYLOAD';
		$amz_date       = gmdate( 'Ymd\\THis\\Z' );
		$date_stamp     = gmdate( 'Ymd' );
		$canonical_uri  = self::canonical_uri( $config, $object_key );
		$scope          = self::credential_scope( $config, $date_stamp );
		$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request = $method . "\n" . $canonical_uri . "\n\n"
			. 'host:' . $config['host'] . "\n"
			. 'x-amz-content-sha256:' . $payload_hash . "\n"
			. 'x-amz-date:' . $amz_date . "\n\n"
			. $signed_headers . "\n"
			. $payload_hash;

		$signature = self::sign( $config, $date_stamp, $amz_date, $scope, $canonical_request );

		$headers = array(
			'Host'                 => $config['host'],
			'x-amz-date'           => $amz_date,
			'x-amz-content-sha256' => $payload_hash,
			'Authorization'        => "AWS4-HMAC-SHA256 Credential={$config['access_key']}/{$scope}, SignedHeaders={$signed_headers}, Signature={$signature}",
		);
		if ( $is_put ) {
			$headers['Content-Type'] = $mime_type;
		}

		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => $headers,
		);
		if ( $is_put ) {
			$args['body'] = $body;
		}

		return wp_remote_request( $config['endpoint'] . $canonical_uri, $args );
	}

	/**
	 * @param array  $config     Validated config.
	 * @param string $object_key Object key.
	 * @return string
	 */
	private static function canonical_uri( array $config, $object_key ) {
		$object_key = ltrim( (string) $object_key, '/' );
		return '/' . rawurlencode( $config['bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );
	}

	/**
	 * @param array  $config     Validated config.
	 * @param string $date_stamp Ymd.
	 * @return string
	 */
	private static function credential_scope( array $config, $date_stamp ) {
		return $date_stamp . '/' . $config['region'] . '/' . $config['service'] . '/aws4_request';
	}

	/**
	 * @param array  $config            Validated config.
	 * @param string $date_stamp        Ymd.
	 * @param string $amz_date          ISO8601 basic timestamp.
	 * @param string $scope             Credential scope.
	 * @param string $canonical_request Canonical request.
	 * @return string Hex signature.
	 */
	private static function sign( array $config, $date_stamp, $amz_date, $scope, $canonical_request ) {
		$string_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical_request );
		$signing_key    = self::signing_key( $config['secret_key'], $date_stamp, $config['region'], $config['service'] );

		return hash_hmac( 'sha256', $string_to_sign, $signing_key );
	}

	/**
	 * Loads and validates the storage credentials (constants take priority
	 * over options, matching the pattern used for the lease contract store).
	 *
	 * @return array|WP_Error
	 */
	private static function get_config() {
		$access_key = self::setting( 'AF_R2_ACCESS_KEY_ID', 'af_r2_access_key_id' );
		$secret_key = self::setting( 'AF_R2_SECRET_ACCESS_KEY', 'af_r2_secret_access_key' );
		$endpoint   = untrailingslashit( self::setting( 'AF_R2_ENDPOINT_URL', 'af_r2_endpoint_url' ) );
		$bucket     = self::setting( 'AF_R2_BUCKET_NAME', 'af_r2_bucket_name' );

		if ( '' === $access_key || '' === $secret_key || '' === $endpoint || '' === $bucket ) {
			return new WP_Error( 'af_r2_missing_config', __( 'Faltan credenciales de Cloudflare R2. Revisa Ajustes > Proveedor en la nube.', 'arriendo-facil' ) );
		}

		$parsed = wp_parse_url( $endpoint );
		$host   = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		$scheme = isset( $parsed['scheme'] ) ? (string) $parsed['scheme'] : '';

		if ( '' === $host || '' === $scheme ) {
			return new WP_Error( 'af_storage_invalid_endpoint', __( 'URL de endpoint invalida.', 'arriendo-facil' ) );
		}

		return array(
			'access_key' => $access_key,
			'secret_key' => $secret_key,
			'endpoint'   => $scheme . '://' . $host,
			'host'       => $host,
			'bucket'     => $bucket,
			'region'     => 'auto',
			'service'    => 's3',
		);
	}

	/**
	 * Reads a setting, prioritizing a wp-config constant over a DB option.
	 *
	 * @param string $constant_name Constant name.
	 * @param string $option_name   Option name.
	 * @param string $default       Default value.
	 * @return string
	 */
	public static function setting( $constant_name, $option_name, $default = '' ) {
		if ( defined( $constant_name ) ) {
			$value = constant( $constant_name );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return trim( (string) get_option( $option_name, $default ) );
	}

	/**
	 * Builds the AWS Signature V4 signing key.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $date_stamp Date stamp (Ymd).
	 * @param string $region     Region.
	 * @param string $service    Service.
	 * @return string
	 */
	private static function signing_key( $secret_key, $date_stamp, $region, $service ) {
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );

		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}
}
