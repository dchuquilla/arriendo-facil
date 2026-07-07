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
		add_action( 'wp_ajax_af_preview_invoice', array( $this, 'ajax_preview_invoice' ) );
		add_action( 'wp_ajax_af_retry_invoice', array( $this, 'ajax_retry_invoice' ) );
		add_action( 'wp_ajax_af_download_ride', array( $this, 'ajax_download_ride' ) );
		add_action( 'wp_ajax_af_download_xml', array( $this, 'ajax_download_xml' ) );
		add_action( 'wp_ajax_af_sri_ruc_lookup', array( $this, 'ajax_sri_ruc_lookup' ) );
		add_action( 'wp_ajax_af_billing_lease_search', array( $this, 'ajax_billing_lease_search' ) );
		add_action( 'wp_ajax_af_sri_log_view',         array( $this, 'ajax_sri_log_view' ) );
		add_action( 'wp_ajax_af_get_invoices_async',   array( $this, 'ajax_get_invoices_async' ) );

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
			wp_send_json_error( array( 'message' => __( 'ID de contrato invalido.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->current_user_owns_lease( $lease_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este contrato.', 'arriendo-facil' ) ), 403 );
		}

		// Parse user-edited overrides from the preview modal.
		$overrides_raw = isset( $_POST['overrides'] ) ? wp_unslash( $_POST['overrides'] ) : '';
		$flat_overrides = array();
		if ( '' !== $overrides_raw && is_string( $overrides_raw ) ) {
			$decoded = json_decode( $overrides_raw, true );
			if ( is_array( $decoded ) ) {
				// Sanitize each allowed field.
				if ( isset( $decoded['descripcion'] ) ) {
					$flat_overrides['descripcion'] = sanitize_text_field( (string) $decoded['descripcion'] );
				}
				if ( isset( $decoded['precio_unitario'] ) ) {
					$flat_overrides['precio_unitario'] = (float) $decoded['precio_unitario'];
				}
				if ( isset( $decoded['cantidad'] ) ) {
					$flat_overrides['cantidad'] = (float) $decoded['cantidad'];
				}
				if ( isset( $decoded['descuento'] ) ) {
					$flat_overrides['descuento'] = (float) $decoded['descuento'];
				}
				if ( isset( $decoded['email'] ) ) {
					$flat_overrides['email'] = sanitize_email( (string) $decoded['email'] );
				}
			}
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

		$result = $this->manager->issue_lease_invoice(
			$lease_id,
			array_merge( array( 'billing_period' => $period ), $flat_overrides )
		);

		// Release lock immediately if the request failed (allows fast retry on real errors).
		if ( is_wp_error( $result ) ) {
			delete_transient( $lock_key );

			$response = array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			);

			$error_data = $result->get_error_data();
			if ( ! empty( $error_data['mensajes'] ) ) {
				$response['detalle_error'] = $error_data['mensajes'];
			}

			if ( 'sri_no_autorizada' === $result->get_error_code() || 'sri_devuelta' === $result->get_error_code() ) {
				$pems = Arriendo_Facil_SRI_Config::get_cert_pems();
				$cert_info = openssl_x509_parse( $pems['cert'] );
				if ( false !== $cert_info ) {
					$response['diagnostico_cert'] = array(
						'cn'       => $cert_info['subject']['CN'] ?? '?',
						'emisor'   => $cert_info['issuer']['CN'] ?? ( $cert_info['issuer']['O'] ?? '?' ),
						'vigencia' => wp_date( 'd/m/Y', $cert_info['validFrom_time_t'] ?? 0 ) . ' → ' . wp_date( 'd/m/Y', $cert_info['validTo_time_t'] ?? 0 ),
						'serial'   => $cert_info['serialNumberHex'] ?? '?',
						'chain_bytes' => strlen( $pems['chain'] ?? '' ),
					);
				}
			}

			wp_send_json_error( $response, 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: returns preview data for a lease invoice without issuing it.
	 */
	public function ajax_preview_invoice(): void {
		check_ajax_referer( 'af_billing_nonce', 'nonce' );

		if ( ! $this->can_manage_billing() ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		if ( $lease_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'ID de contrato invalido.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->current_user_owns_lease( $lease_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este contrato.', 'arriendo-facil' ) ), 403 );
		}

		$overrides_raw  = isset( $_POST['overrides'] ) ? wp_unslash( $_POST['overrides'] ) : '';
		$flat_overrides = array();
		if ( '' !== $overrides_raw && is_string( $overrides_raw ) ) {
			$decoded = json_decode( $overrides_raw, true );
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['descripcion'] ) ) {
					$flat_overrides['descripcion'] = sanitize_text_field( (string) $decoded['descripcion'] );
				}
				if ( isset( $decoded['precio_unitario'] ) ) {
					$flat_overrides['precio_unitario'] = (float) $decoded['precio_unitario'];
				}
				if ( isset( $decoded['cantidad'] ) ) {
					$flat_overrides['cantidad'] = (float) $decoded['cantidad'];
				}
				if ( isset( $decoded['descuento'] ) ) {
					$flat_overrides['descuento'] = (float) $decoded['descuento'];
				}
				if ( isset( $decoded['email'] ) ) {
					$flat_overrides['email'] = sanitize_email( (string) $decoded['email'] );
				}
			}
		}

		$result = $this->manager->preview_lease_invoice( $lease_id, $flat_overrides );

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

		if ( ! $this->current_user_owns_invoice( $invoice_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este comprobante.', 'arriendo-facil' ) ), 403 );
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
		if ( ! $this->current_user_owns_invoice( $invoice_id ) ) {
			wp_die( esc_html__( 'No tienes acceso a este comprobante.', 'arriendo-facil' ), 403 );
		}
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
		if ( ! $this->current_user_owns_invoice( $invoice_id ) ) {
			wp_die( esc_html__( 'No tienes acceso a este comprobante.', 'arriendo-facil' ), 403 );
		}
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
				// sri_en_proceso: SRI still queuing the document — no backoff, just try next cron run.
				if ( 'sri_en_proceso' === $result->get_error_code() ) {
					continue;
				}
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
		$default_capability = apply_filters( 'af_billing_capability', 'af_view_billing' );
		return current_user_can( (string) $default_capability );
	}

	/**
	 * Returns whether the current user owns the given lease. Admins always pass.
	 *
	 * @param int $lease_id Lease ID.
	 * @return bool
	 */
	private function current_user_owns_lease( int $lease_id ): bool {
		if ( $lease_id <= 0 ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( ! class_exists( 'Arriendo_Facil_Accommodation' ) ) {
			return false;
		}
		if ( ! Arriendo_Facil_Accommodation::user_is_owner() ) {
			return false;
		}

		global $wpdb;
		$accommodation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT accommodation_id FROM {$wpdb->prefix}af_leases WHERE id = %d LIMIT 1",
				$lease_id
			)
		);
		if ( $accommodation_id <= 0 ) {
			return false;
		}

		$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
		return in_array( $accommodation_id, array_map( 'intval', (array) $owner_ids ), true );
	}

	/**
	 * Returns whether the current user owns the invoice's related lease.
	 *
	 * @param int $invoice_id Invoice ID.
	 * @return bool
	 */
	private function current_user_owns_invoice( int $invoice_id ): bool {
		if ( $invoice_id <= 0 ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$invoice = $this->manager->get_invoice( $invoice_id );
		if ( ! $invoice || empty( $invoice->lease_id ) ) {
			return false;
		}
		return $this->current_user_owns_lease( (int) $invoice->lease_id );
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
	 * AJAX: consults SRI via SOAP (with REST fallback) to auto-fill issuer data by RUC.
	 * Uses the same SOAP infrastructure as invoice emission for consistency.
	 * Accessible by admins and by owners with the `af_view_billing` capability
	 * (owners need this to prefill their own scoped SRI config).
	 */
	public function ajax_sri_ruc_lookup(): void {
		check_ajax_referer( 'af_sri_ruc_lookup', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'af_view_billing' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$ruc = isset( $_POST['ruc'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['ruc'] ) ) ) : '';

		if ( strlen( $ruc ) !== 13 ) {
			wp_send_json_error( array( 'message' => __( 'El RUC debe tener exactamente 13 dígitos.', 'arriendo-facil' ) ), 400 );
		}

		// Read ambiente from the caller's scope: admins → global, owners → their own.
		$config   = Arriendo_Facil_SRI_Config::get();
		$ambiente = isset( $config['ambiente'] ) ? (string) $config['ambiente'] : '1';

		$soap_client = new Arriendo_Facil_SRI_Soap_Client( $ambiente );
		$result = $soap_client->consultar_contribuyente( $ruc );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$message = $result->get_error_message();

			// Translate internal error codes to user-friendly messages.
			$http_code = 503;
			$user_msg = __( 'No se pudo consultar la información del RUC. Intente ingresando los datos manualmente.', 'arriendo-facil' );

			if ( 'invalid_ruc' === $code ) {
				$user_msg = __( 'El RUC debe tener exactamente 13 dígitos.', 'arriendo-facil' );
				$http_code = 400;
			} elseif ( 'sri_ruc_inactive' === $code ) {
				// RUC status validation failed (not ACTIVO).
				$user_msg = $message; // Use the detailed message from the error.
				$http_code = 400;
			} elseif ( 'sri_contribuyente_not_found' === $code || 'sri_no_data' === $code ) {
				$user_msg = __( 'No se encontró información para este RUC en el SRI. Verifique el número o ingrese los datos manualmente.', 'arriendo-facil' );
				$http_code = 404;
			} elseif ( preg_match( '/dns|DNS/i', $message ) ) {
				$user_msg = __( 'Error DNS: no se pudo resolver el servidor del SRI. Verifique su conexión a internet.', 'arriendo-facil' );
				$http_code = 503;
			} elseif ( preg_match( '/timeout|Timeout/i', $message ) ) {
				$user_msg = __( 'El SRI no respondió a tiempo. Intente de nuevo en unos minutos.', 'arriendo-facil' );
				$http_code = 504;
			} elseif ( preg_match( '/ssl|SSL|certificate/i', $message ) ) {
				$user_msg = __( 'Error de certificado SSL con el SRI. Contacte al administrador.', 'arriendo-facil' );
				$http_code = 503;
			}

			wp_send_json_error( array( 'message' => $user_msg ), $http_code );
		}

		wp_send_json_success( $result );
	}


	/**
	 * AJAX: devuelve las últimas entradas de af_sri_log para una factura.
	 * Permite diagnosticar errores del SRI desde el admin sin acceso a debug.log.
	 */
	public function ajax_sri_log_view(): void {
		check_ajax_referer( 'af_billing_nonce', 'nonce' );

		if ( ! $this->can_manage_billing() ) {
			wp_send_json_error( array( 'message' => 'Permiso denegado.' ), 403 );
		}

		$invoice_id = isset( $_POST['invoice_id'] ) ? absint( wp_unslash( $_POST['invoice_id'] ) ) : 0;
		if ( $invoice_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invoice ID inválido.' ), 400 );
		}

		if ( ! $this->current_user_owns_invoice( $invoice_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este comprobante.', 'arriendo-facil' ) ), 403 );
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tipo_operacion, response_payload, http_status, created_at
				 FROM {$wpdb->prefix}af_sri_log
				 WHERE invoice_id = %d
				 ORDER BY id DESC
				 LIMIT 10",
				$invoice_id
			)
		);

		$entries = array();
		foreach ( (array) $rows as $row ) {
			$payload = (string) ( $row->response_payload ?? '' );

			// Desencriptar si está protegido.
			if ( method_exists( 'Arriendo_Facil_SRI_Config', 'unprotect_sensitive' ) ) {
				$decoded = Arriendo_Facil_SRI_Config::unprotect_sensitive( $payload );
				if ( '' !== $decoded ) {
					$payload = $decoded;
				}
			}

			// Eliminar el hash SHA256 del prefijo si está presente.
			$payload = (string) preg_replace( '/^\[sha256:[a-f0-9]{64}\] /', '', $payload );

			$entries[] = array(
				'tipo'       => (string) ( $row->tipo_operacion ?? '' ),
				'respuesta'  => $payload,
				'http'       => (int) ( $row->http_status ?? 0 ),
				'fecha'      => (string) ( $row->created_at ?? '' ),
			);
		}

		wp_send_json_success( array( 'entries' => $entries ) );
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

	/**
	 * AJAX: get invoices async for live UI updates (tab counts, status changes).
	 * Returns basic invoice data to detect state changes without full page refresh.
	 */
	public function ajax_get_invoices_async(): void {
		check_ajax_referer( 'af_billing_nonce', 'nonce' );

		if ( ! $this->can_manage_billing() ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		global $wpdb;

		$is_owner_view = class_exists( 'Arriendo_Facil_Accommodation' ) && Arriendo_Facil_Accommodation::user_is_owner();
		$owner_where   = '';
		if ( $is_owner_view ) {
			$owner_acc_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
			if ( empty( $owner_acc_ids ) ) {
				wp_send_json_success( array( 'invoices' => array() ) );
				return;
			}
			$owner_ids_sql = implode( ',', array_map( 'intval', $owner_acc_ids ) );
			$owner_where   = " AND l.accommodation_id IN ($owner_ids_sql)";
		}

		$results = $wpdb->get_results(
			"SELECT ei.id, ei.estado, ei.numero_comprobante
			 FROM {$wpdb->prefix}af_electronic_invoices ei
			 LEFT JOIN {$wpdb->prefix}af_leases l ON l.id = ei.lease_id
			 WHERE 1=1 {$owner_where}
			 ORDER BY ei.created_at DESC
			 LIMIT 200"
		);

		$estado_labels = array(
			'generada'   => 'en_proceso',
			'firmada'    => 'en_proceso',
			'enviada'    => 'en_proceso',
			'autorizada' => 'autorizadas',
			'autorizada_sin_ride' => 'autorizadas',
			'error_envio' => 'error',
			'error_autorizacion' => 'error',
			'devuelta'   => 'error',
			'no_autorizada' => 'error',
			'rechazada'  => 'error',
			'anulada'    => 'error',
		);

		$invoices = array_map( function ( $inv ) use ( $estado_labels ) {
			return array(
				'id'    => (int) $inv->id,
				'estado' => (string) $inv->estado,
				'grupo' => $estado_labels[ (string) $inv->estado ] ?? 'otro',
				'numero' => (string) $inv->numero_comprobante,
			);
		}, (array) $results );

		wp_send_json_success( array( 'invoices' => $invoices ) );
	}
}
