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
 * Signed PUT/GET against an S3-compatible bucket (SigV4), so sensitive files
 * (identity documents) never live at a guessable, publicly-reachable URL like
 * the default WordPress media library does.
 */
class Arriendo_Facil_Private_Storage {

	/**
	 * Whether R2/S3 credentials are configured on this site.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return ! is_wp_error( self::get_config() );
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

		$object_key   = ltrim( (string) $object_key, '/' );
		$payload_hash = hash( 'sha256', (string) $contents );
		$amz_date     = gmdate( 'Ymd\\THis\\Z' );
		$date_stamp   = gmdate( 'Ymd' );
		$canonical_uri = '/' . rawurlencode( $config['bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );

		$canonical_headers =
			'host:' . $config['host'] . "\n"
			. 'x-amz-content-sha256:' . $payload_hash . "\n"
			. 'x-amz-date:' . $amz_date . "\n";
		$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request = "PUT\n" . $canonical_uri . "\n\n" . $canonical_headers . "\n" . $signed_headers . "\n" . $payload_hash;
		$scope             = $date_stamp . '/' . $config['region'] . '/' . $config['service'] . '/aws4_request';
		$string_to_sign    = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical_request );
		$signing_key       = self::signing_key( $config['secret_key'], $date_stamp, $config['region'], $config['service'] );
		$signature         = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$authorization = "AWS4-HMAC-SHA256 Credential={$config['access_key']}/{$scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

		$response = wp_remote_request(
			$config['endpoint'] . $canonical_uri,
			array(
				'method'  => 'PUT',
				'timeout' => 45,
				'headers' => array(
					'Host'                 => $config['host'],
					'Content-Type'         => $mime_type,
					'x-amz-date'           => $amz_date,
					'x-amz-content-sha256' => $payload_hash,
					'Authorization'        => $authorization,
				),
				'body'    => $contents,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'af_storage_upload_failed', __( 'No se pudo subir el archivo al almacenamiento privado.', 'arriendo-facil' ) );
		}

		return true;
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
		$scope         = $date_stamp . '/' . $config['region'] . '/' . $config['service'] . '/aws4_request';
		$canonical_uri = '/' . rawurlencode( $config['bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );

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
		$string_to_sign    = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical_request );
		$signing_key       = self::signing_key( $config['secret_key'], $date_stamp, $config['region'], $config['service'] );
		$signature         = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		return $config['endpoint'] . $canonical_uri . '?' . $canonical_query . '&X-Amz-Signature=' . rawurlencode( $signature );
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
			return new WP_Error( 'af_storage_not_configured', __( 'Almacenamiento privado no configurado.', 'arriendo-facil' ) );
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
	 * @return string
	 */
	private static function setting( $constant_name, $option_name ) {
		if ( defined( $constant_name ) ) {
			$value = constant( $constant_name );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return trim( (string) get_option( $option_name, '' ) );
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
