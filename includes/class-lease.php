<?php
/**
 * Lease management.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Lease
 *
 * Manages lease records stored in the af_leases table and provides
 * AJAX endpoints for creating and updating leases.
 */
class Arriendo_Facil_Lease {

	/**
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_create_lease', array( $this, 'ajax_create_lease' ) );
		add_action( 'wp_ajax_af_update_lease', array( $this, 'ajax_update_lease' ) );
		add_action( 'wp_ajax_af_get_leases', array( $this, 'ajax_get_leases' ) );
		add_action( 'wp_ajax_af_download_lease_contract', array( $this, 'ajax_download_lease_contract' ) );
	}

	/**
	 * Creates a new lease record via AJAX.
	 */
	public function ajax_create_lease() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( $_POST['accommodation_id'] ) : 0;
		$guest_id         = isset( $_POST['guest_id'] ) ? absint( $_POST['guest_id'] ) : 0;
		$start_date       = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date         = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$monthly_rent     = isset( $_POST['monthly_rent'] ) ? floatval( wp_unslash( $_POST['monthly_rent'] ) ) : 0.0;

		if ( ! $accommodation_id || ! $guest_id || ! $start_date || ! $end_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'af_leases',
			array(
				'accommodation_id' => $accommodation_id,
				'guest_id'         => $guest_id,
				'start_date'       => $start_date,
				'end_date'         => $end_date,
				'monthly_rent'     => $monthly_rent,
				'status'           => 'draft',
			),
			array( '%d', '%d', '%s', '%s', '%f', '%s' )
		);

		if ( $inserted ) {
			wp_send_json_success( array( 'id' => $wpdb->insert_id ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not create lease.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Updates an existing lease via AJAX.
	 */
	public function ajax_update_lease() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		$allowed_statuses = array( 'draft', 'active', 'expired', 'terminated' );
		if ( ! $lease_id || ! in_array( $status, $allowed_statuses, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'af_leases',
			array( 'status' => $status ),
			array( 'id' => $lease_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not update lease.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Returns leases for a given accommodation via AJAX.
	 */
	public function ajax_get_leases() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_GET['accommodation_id'] ) ? absint( $_GET['accommodation_id'] ) : 0;

		global $wpdb;
		$leases = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_leases WHERE accommodation_id = %d ORDER BY start_date DESC",
				$accommodation_id
			)
		);

		wp_send_json_success( $leases );
	}

	/**
	 * Returns a single lease by ID.
	 *
	 * @param int $lease_id Lease ID.
	 * @return object|null Lease object or null.
	 */
	public function get_lease( $lease_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_leases WHERE id = %d",
				$lease_id
			)
		);
	}

	/**
	 * Attaches a generated document URL to a lease.
	 *
	 * @param int    $lease_id     Lease ID.
	 * @param string $document_url URL of the generated document.
	 * @return bool True on success.
	 */
	public function attach_document( $lease_id, $document_url ) {
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . 'af_leases',
			array( 'document_url' => esc_url_raw( $document_url ) ),
			array( 'id' => absint( $lease_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Saves storage metadata for a lease contract.
	 *
	 * @param int   $lease_id Lease ID.
	 * @param array $meta Storage metadata.
	 * @return bool
	 */
	public function set_contract_storage_meta( $lease_id, array $meta ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return false;
		}

		$option_name = $this->get_contract_storage_option_name( $lease_id );
		$clean_meta  = array(
			'provider'   => isset( $meta['provider'] ) ? sanitize_key( (string) $meta['provider'] ) : '',
			'object_key' => isset( $meta['object_key'] ) ? sanitize_text_field( (string) $meta['object_key'] ) : '',
			'mime_type'  => isset( $meta['mime_type'] ) ? sanitize_text_field( (string) $meta['mime_type'] ) : '',
			'file_name'  => isset( $meta['file_name'] ) ? sanitize_file_name( (string) $meta['file_name'] ) : '',
			'local_url'  => isset( $meta['local_url'] ) ? esc_url_raw( (string) $meta['local_url'] ) : '',
			'updated_at' => current_time( 'mysql' ),
		);

		if ( false === get_option( $option_name, false ) ) {
			return add_option( $option_name, $clean_meta, '', false );
		}

		return update_option( $option_name, $clean_meta, false );
	}

	/**
	 * Returns storage metadata for a lease contract.
	 *
	 * @param int $lease_id Lease ID.
	 * @return array<string,string>
	 */
	public function get_contract_storage_meta( $lease_id ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return array();
		}

		$meta = get_option( $this->get_contract_storage_option_name( $lease_id ), array() );
		if ( ! is_array( $meta ) ) {
			return array();
		}

		return $meta;
	}

	/**
	 * Downloads a lease contract via secure redirect.
	 */
	public function ajax_download_lease_contract() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'arriendo-facil' ), 403 );
		}

		$lease_id = isset( $_GET['lease_id'] ) ? absint( wp_unslash( $_GET['lease_id'] ) ) : 0;
		if ( ! $lease_id ) {
			wp_die( esc_html__( 'Invalid lease ID.', 'arriendo-facil' ), 400 );
		}

		$lease = $this->get_lease( $lease_id );
		if ( ! $lease ) {
			wp_die( esc_html__( 'Lease not found.', 'arriendo-facil' ), 404 );
		}

		$meta = $this->get_contract_storage_meta( $lease_id );
		if ( isset( $meta['provider'], $meta['object_key'] ) && 'cloudflare_r2' === $meta['provider'] && '' !== trim( (string) $meta['object_key'] ) ) {
			$presigned_url = $this->build_r2_presigned_get_url( (string) $meta['object_key'], 600 );
			if ( ! is_wp_error( $presigned_url ) && is_string( $presigned_url ) && '' !== $presigned_url ) {
				wp_safe_redirect( $presigned_url );
				exit;
			}
		}

		$document_url = isset( $lease->document_url ) ? esc_url_raw( (string) $lease->document_url ) : '';
		if ( '' !== $document_url ) {
			wp_safe_redirect( $document_url );
			exit;
		}

		wp_die( esc_html__( 'Contract document is not available.', 'arriendo-facil' ), 404 );
	}

	/**
	 * Builds option name for lease contract storage metadata.
	 *
	 * @param int $lease_id Lease ID.
	 * @return string
	 */
	private function get_contract_storage_option_name( $lease_id ) {
		return 'af_lease_contract_storage_' . absint( $lease_id );
	}

	/**
	 * Reads setting with wp-config constant priority.
	 *
	 * @param string $constant_name Constant name.
	 * @param string $option_name Option name.
	 * @param string $default Default value.
	 * @return string
	 */
	private function get_storage_setting( $constant_name, $option_name, $default = '' ) {
		if ( defined( $constant_name ) ) {
			$value = constant( $constant_name );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return trim( (string) get_option( $option_name, $default ) );
	}

	/**
	 * Loads and validates Cloudflare R2 credentials.
	 *
	 * @return array|WP_Error
	 */
	private function get_r2_config() {
		$access_key = $this->get_storage_setting( 'AF_R2_ACCESS_KEY_ID', 'af_r2_access_key_id', '' );
		$secret_key = $this->get_storage_setting( 'AF_R2_SECRET_ACCESS_KEY', 'af_r2_secret_access_key', '' );
		$endpoint   = untrailingslashit( $this->get_storage_setting( 'AF_R2_ENDPOINT_URL', 'af_r2_endpoint_url', '' ) );
		$bucket     = $this->get_storage_setting( 'AF_R2_BUCKET_NAME', 'af_r2_bucket_name', '' );

		if ( '' === $access_key || '' === $secret_key || '' === $endpoint || '' === $bucket ) {
			return new WP_Error( 'af_r2_missing_config', __( 'Missing Cloudflare R2 credentials. Check Settings > Cloud Provider.', 'arriendo-facil' ) );
		}

		$parsed = wp_parse_url( $endpoint );
		$host   = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		$scheme = isset( $parsed['scheme'] ) ? (string) $parsed['scheme'] : '';

		if ( '' === $host || '' === $scheme ) {
			return new WP_Error( 'af_r2_invalid_endpoint', __( 'Invalid Cloudflare R2 endpoint URL.', 'arriendo-facil' ) );
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
	 * Builds pre-signed GET URL for a private R2 object.
	 *
	 * @param string $object_key R2 object key.
	 * @param int    $expires Expiration in seconds.
	 * @return string|WP_Error
	 */
	private function build_r2_presigned_get_url( $object_key, $expires = 600 ) {
		$r2_config = $this->get_r2_config();
		if ( is_wp_error( $r2_config ) ) {
			return $r2_config;
		}

		$object_key = ltrim( (string) $object_key, '/' );
		if ( '' === $object_key ) {
			return new WP_Error( 'af_r2_missing_object_key', __( 'Missing R2 object key.', 'arriendo-facil' ) );
		}

		$expires      = max( 60, min( 3600, absint( $expires ) ) );
		$amz_date     = gmdate( 'Ymd\\THis\\Z' );
		$date_stamp   = gmdate( 'Ymd' );
		$scope        = $date_stamp . '/' . $r2_config['region'] . '/' . $r2_config['service'] . '/aws4_request';
		$canonical_uri = '/' . rawurlencode( $r2_config['bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );

		$query_params = array(
			'X-Amz-Algorithm'  => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential' => rawurlencode( $r2_config['access_key'] . '/' . $scope ),
			'X-Amz-Date'       => $amz_date,
			'X-Amz-Expires'    => (string) $expires,
			'X-Amz-SignedHeaders' => 'host',
		);

		ksort( $query_params );
		$canonical_query = '';
		foreach ( $query_params as $key => $value ) {
			if ( '' !== $canonical_query ) {
				$canonical_query .= '&';
			}
			$canonical_query .= rawurlencode( (string) $key ) . '=' . (string) $value;
		}

		$canonical_request =
			"GET\n"
			. $canonical_uri . "\n"
			. $canonical_query . "\n"
			. 'host:' . $r2_config['host'] . "\n\n"
			. 'host' . "\n"
			. 'UNSIGNED-PAYLOAD';

		$string_to_sign =
			'AWS4-HMAC-SHA256' . "\n"
			. $amz_date . "\n"
			. $scope . "\n"
			. hash( 'sha256', $canonical_request );

		$signing_key = $this->get_aws_v4_signing_key( $r2_config['secret_key'], $date_stamp, $r2_config['region'], $r2_config['service'] );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		return $r2_config['endpoint'] . $canonical_uri . '?' . $canonical_query . '&X-Amz-Signature=' . rawurlencode( $signature );
	}

	/**
	 * Builds AWS Signature V4 signing key.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $date_stamp Date stamp.
	 * @param string $region Region.
	 * @param string $service Service.
	 * @return string
	 */
	private function get_aws_v4_signing_key( $secret_key, $date_stamp, $region, $service ) {
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );

		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}
}
