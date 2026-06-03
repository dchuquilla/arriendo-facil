<?php
/**
 * Billing API layer (AJAX + hooks + cron retries).
 *
 * @package Arriendo_Facil\Billing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Billing_API
 */
class Arriendo_Facil_Billing_API {

	/** Retry meta option key. */
	const RETRY_META_OPTION = 'af_sri_retry_meta';

	/** Cron lock transient key. */
	const RETRY_LOCK_TRANSIENT = 'af_sri_retry_lock';

	/** @var Arriendo_Facil_Billing_Manager */
	private $manager;

	/**
	 * Constructor.
	 *
	 * @param Arriendo_Facil_Billing_Manager|null $manager Optional manager injection.
	 */
	public function __construct( ?Arriendo_Facil_Billing_Manager $manager = null ) {
		$this->manager = $manager ?: new Arriendo_Facil_Billing_Manager();

		add_action( 'af_lease_activated', array( $this, 'handle_lease_activated' ), 10, 1 );
		add_action( 'wp_ajax_af_issue_invoice', array( $this, 'ajax_issue_invoice' ) );
		add_action( 'wp_ajax_af_retry_invoice', array( $this, 'ajax_retry_invoice' ) );
		add_action( 'wp_ajax_af_download_ride', array( $this, 'ajax_download_ride' ) );
		add_action( 'wp_ajax_af_download_xml', array( $this, 'ajax_download_xml' ) );
		add_action( 'wp_ajax_af_sri_ruc_lookup', array( $this, 'ajax_sri_ruc_lookup' ) );
		add_action( 'wp_ajax_af_billing_lease_search', array( $this, 'ajax_billing_lease_search' ) );

		add_filter( 'cron_schedules', array( $this, 'register_retry_schedule' ) );
		add_action( 'init', array( $this, 'maybe_schedule_retry_cron' ) );
		add_action( 'af_sri_retry_cron', array( $this, 'process_retry_queue' ) );
	}

	/**
	 * Registers a 15-minute schedule for billing retry queue.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_retry_schedule( $schedules ) {
		if ( ! isset( $schedules['af_every_fifteen_minutes'] ) ) {
			$schedules['af_every_fifteen_minutes'] = array(
				'interval' => 15 * 60,
				'display'  => __( 'Every 15 Minutes (Arriendo Facil)', 'arriendo-facil' ),
			);
		}
		return $schedules;
	}

	/**
	 * Ensures cron event for SRI error retries exists (every 15 min).
	 * Billing issuance is always manual — no automatic monthly generation.
	 */
	public function maybe_schedule_retry_cron(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'af_sri_retry_cron' ) ) {
			wp_schedule_event( time() + 120, 'af_every_fifteen_minutes', 'af_sri_retry_cron' );
		}
	}

	/**
	 * Triggered when lease transitions to active state.
	 * Billing is fully manual — this hook does NOT auto-issue an invoice.
	 *
	 * @param int $lease_id Lease ID.
	 */
	public function handle_lease_activated( $lease_id ): void {
		// Intentionally left empty: invoices must be issued manually via the Leases admin page.
	}

	/**
	 * AJAX: issue invoice manually for a lease.
	 */
	public function ajax_issue_invoice(): void {
		check_ajax_referer( 'af_billing_nonce', 'nonce' );

		if ( ! $this->can_manage_billing() ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		if ( $lease_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Lease ID invalido.', 'arriendo-facil' ) ), 400 );
		}

		// Server-side lock: prevents duplicate submissions within 30 s (double-click, race condition).
		$period   = Arriendo_Facil_Billing_Manager::billing_period();
		$lock_key = 'af_inv_lock_' . $lease_id . '_' . substr( md5( $period ), 0, 8 );
		if ( get_transient( $lock_key ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Operación en progreso. Espere un momento antes de reintentar.', 'arriendo-facil' ),
					'code'    => 'request_locked',
				),
				429
			);
		}
		set_transient( $lock_key, 1, 30 );

		$result = $this->manager->issue_lease_invoice( $lease_id, array( 'billing_period' => $period ) );

		// Release lock immediately if the request failed (allows fast retry on real errors).
		if ( is_wp_error( $result ) ) {
			delete_transient( $lock_key );
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: retry an invoice flow.
	 */
	public function ajax_retry_invoice(): void {
		check_ajax_referer( 'af_billing_nonce', 'nonce' );

		if ( ! $this->can_manage_billing() ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$invoice_id = isset( $_POST['invoice_id'] ) ? absint( wp_unslash( $_POST['invoice_id'] ) ) : 0;
		if ( $invoice_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invoice ID invalido.', 'arriendo-facil' ) ), 400 );
		}

		$result = $this->manager->retry_invoice( $invoice_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: downloads invoice RIDE PDF.
	 */
	public function ajax_download_ride(): void {
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'af_billing_nonce' ) ) {
			wp_die( esc_html__( 'Nonce invalido.', 'arriendo-facil' ), 403 );
		}

		if ( ! $this->can_manage_billing() ) {
			wp_die( esc_html__( 'Permiso denegado.', 'arriendo-facil' ), 403 );
		}

		$invoice_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$invoice    = $this->manager->get_invoice( $invoice_id );
		if ( ! $invoice || empty( $invoice->ride_path ) ) {
			wp_die( esc_html__( 'RIDE no disponible.', 'arriendo-facil' ), 404 );
		}

		$path = (string) $invoice->ride_path;
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Archivo RIDE no encontrado.', 'arriendo-facil' ), 404 );
		}

		$ride_dir = ( new Arriendo_Facil_SRI_Ride() )->ride_dir();
		$path_real = realpath( $path );
		$dir_real  = is_string( $ride_dir ) ? realpath( $ride_dir ) : false;
		if ( false === $path_real || false === $dir_real || 0 !== strpos( $path_real, $dir_real . DIRECTORY_SEPARATOR ) ) {
			wp_die( esc_html__( 'Ruta de archivo no autorizada.', 'arriendo-facil' ), 403 );
		}

		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="RIDE-' . (int) $invoice->id . '.pdf"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * AJAX: downloads invoice XML (authorization XML first, signed XML fallback).
	 */
	public function ajax_download_xml(): void {
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'af_billing_nonce' ) ) {
			wp_die( esc_html__( 'Nonce invalido.', 'arriendo-facil' ), 403 );
		}

		if ( ! $this->can_manage_billing() ) {
			wp_die( esc_html__( 'Permiso denegado.', 'arriendo-facil' ), 403 );
		}

		$invoice_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$invoice    = $this->manager->get_invoice( $invoice_id );
		if ( ! $invoice ) {
			wp_die( esc_html__( 'Comprobante no encontrado.', 'arriendo-facil' ), 404 );
		}

		$xml = '';
		if ( ! empty( $invoice->xml_autorizacion ) ) {
			$xml = (string) $invoice->xml_autorizacion;
		} elseif ( ! empty( $invoice->xml_firmado ) ) {
			$xml = (string) $invoice->xml_firmado;
		}

		if ( '' === trim( $xml ) ) {
			wp_die( esc_html__( 'XML no disponible.', 'arriendo-facil' ), 404 );
		}

		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="comprobante-' . (int) $invoice->id . '.xml"' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Cron worker: retries pending invoices.
	 */
	public function process_retry_queue(): void {
		if ( get_transient( self::RETRY_LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::RETRY_LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

		$candidates = $this->manager->get_retry_candidates( 20 );
		if ( empty( $candidates ) ) {
			delete_transient( self::RETRY_LOCK_TRANSIENT );
			return;
		}

		$retry_meta = $this->get_retry_meta();
		foreach ( $candidates as $invoice ) {
			if ( ! isset( $invoice->id ) ) {
				continue;
			}

			$invoice_id = (int) $invoice->id;
			$meta       = isset( $retry_meta[ $invoice_id ] ) && is_array( $retry_meta[ $invoice_id ] ) ? $retry_meta[ $invoice_id ] : array();
			$next_ts    = isset( $meta['next_ts'] ) ? (int) $meta['next_ts'] : 0;
			if ( $next_ts > time() ) {
				continue;
			}

			$result = $this->manager->retry_invoice( (int) $invoice->id );
			if ( is_wp_error( $result ) ) {
				$attempts = isset( $meta['attempts'] ) ? (int) $meta['attempts'] + 1 : 1;
				$delay    = min( 12 * HOUR_IN_SECONDS, (int) pow( 2, min( 10, $attempts ) ) * 60 );
				$retry_meta[ $invoice_id ] = array(
					'attempts' => $attempts,
					'next_ts'  => time() + $delay,
				);
				error_log( 'Arriendo Facil billing retry failed invoice ' . $invoice_id . ' => ' . $result->get_error_message() );
			} else {
				unset( $retry_meta[ $invoice_id ] );
			}
		}

		$this->save_retry_meta( $retry_meta );
		delete_transient( self::RETRY_LOCK_TRANSIENT );
	}

	/**
	 * Returns whether current user can manage electronic billing operations.
	 *
	 * @return bool
	 */
	private function can_manage_billing(): bool {
		$default_capability = apply_filters( 'af_billing_capability', 'manage_options' );
		return current_user_can( (string) $default_capability );
	}

	/**
	 * Reads retry control metadata.
	 *
	 * @return array
	 */
	private function get_retry_meta(): array {
		$meta = get_option( self::RETRY_META_OPTION, array() );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Persists retry control metadata.
	 *
	 * @param array $meta Retry metadata.
	 */
	private function save_retry_meta( array $meta ): void {
		update_option( self::RETRY_META_OPTION, $meta, false );
	}

	/**
	 * AJAX: consults SRI public API to auto-fill issuer data by RUC.
	 * Only accessible by admins (manage_options).
	 */
	public function ajax_sri_ruc_lookup(): void {
		check_ajax_referer( 'af_sri_ruc_lookup', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$ruc = isset( $_POST['ruc'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['ruc'] ) ) ) : '';

		if ( strlen( $ruc ) !== 13 ) {
			wp_send_json_error( array( 'message' => __( 'El RUC debe tener exactamente 13 dígitos.', 'arriendo-facil' ) ), 400 );
		}

		$url = add_query_arg(
			array( 'ruc' => rawurlencode( $ruc ) ),
			'https://srienlinea.sri.gob.ec/sri-catastro-sujeto-servicio-internet/rest/ConsolidadoContribuyente/obtenerPorNumerosRuc'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 12,
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Mozilla/5.0 (compatible; ArriendoFacil/1.0; +https://arriendofacil.ec)',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo conectar con el SRI. Verifique su conexión a internet.', 'arriendo-facil' ) ), 503 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_send_json_error(
				array( 'message' => sprintf( __( 'El SRI respondió con error HTTP %d. Intente manualmente.', 'arriendo-facil' ), $code ) ),
				502
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Respuesta inválida del SRI. Intente manualmente.', 'arriendo-facil' ) ), 502 );
		}

		// SRI API wraps data in 'contribuyente' key.
		$c = isset( $data['contribuyente'] ) && is_array( $data['contribuyente'] ) ? $data['contribuyente'] : $data;

		$razon_social     = $this->sri_field( $c, array( 'razonSocial', 'nombreContribuyente' ) );
		$nombre_comercial = $this->sri_field( $c, array( 'nombreFantasia', 'nombreComercial' ) );
		$obligado         = strtoupper( $this->sri_field( $c, array( 'obligadoLlevarContabilidad' ) ) );

		$dir_establecimiento = '';
		$dir_matriz          = '';

		$establecimientos = isset( $c['establecimientos'] ) && is_array( $c['establecimientos'] ) ? $c['establecimientos'] : array();
		foreach ( $establecimientos as $estab ) {
			if ( ! is_array( $estab ) ) {
				continue;
			}
			$tipo = strtoupper( (string) ( $estab['tipoEstablecimiento'] ?? '' ) );
			$dir  = sanitize_text_field( (string) ( $estab['direccionCompleta'] ?? $estab['direccion'] ?? '' ) );
			if ( '' === $dir ) {
				continue;
			}
			if ( 'MATRIZ' === $tipo ) {
				$dir_matriz = $dir;
			}
			if ( '' === $dir_establecimiento ) {
				$dir_establecimiento = $dir;
			}
		}

		if ( '' === $razon_social ) {
			wp_send_json_error( array( 'message' => __( 'No se encontró información para este RUC en el SRI. Verifique el número o ingrese los datos manualmente.', 'arriendo-facil' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'razon_social'          => $razon_social,
				'nombre_comercial'      => $nombre_comercial,
				'dir_establecimiento'   => $dir_establecimiento,
				'dir_matriz'            => $dir_matriz,
				'obligado_contabilidad' => ( 'SI' === $obligado ) ? 'SI' : 'NO',
			)
		);
	}

	/**
	 * Extracts the first non-empty field from an array using a list of candidate keys.
	 *
	 * @param array  $data      Source data array.
	 * @param array  $keys      Ordered list of candidate keys.
	 * @return string
	 */
	private function sri_field( array $data, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				return sanitize_text_field( (string) $data[ $key ] );
			}
		}
		return '';
	}

	/**
	 * AJAX: searches leases by guest id_number / name for billing issue form.
	 */
	public function ajax_billing_lease_search(): void {
		check_ajax_referer( 'af_billing_nonce', 'nonce' );

		if ( ! $this->can_manage_billing() ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		global $wpdb;

		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( array( 'leases' => array() ) );
			return;
		}

		$like = '%' . $wpdb->esc_like( $q ) . '%';

		$where_owner = '';
		if ( class_exists( 'Arriendo_Facil_Accommodation' ) && Arriendo_Facil_Accommodation::user_is_owner() ) {
			$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
			if ( empty( $owner_ids ) ) {
				wp_send_json_success( array( 'leases' => array() ) );
				return;
			}
			$ids_sql     = implode( ',', array_map( 'intval', $owner_ids ) );
			$where_owner = " AND l.accommodation_id IN ($ids_sql)";
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.monthly_rent, l.status,
				        CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
				        g.id_number,
				        p.post_title AS accommodation_title
				 FROM {$wpdb->prefix}af_leases l
				 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 WHERE (
				     g.id_number LIKE %s
				     OR g.first_name LIKE %s
				     OR g.last_name LIKE %s
				     OR CONCAT(g.first_name, ' ', g.last_name) LIKE %s
				 )
				 $where_owner
				 ORDER BY l.id DESC
				 LIMIT 20",
				$like, $like, $like, $like
			)
		);

		wp_send_json_success( array( 'leases' => array_values( (array) $results ) ) );
	}
}
