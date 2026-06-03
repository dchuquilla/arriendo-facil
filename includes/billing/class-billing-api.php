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
	 * Ensures cron event for SRI retries exists.
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
	 *
	 * @param int $lease_id Lease ID.
	 */
	public function handle_lease_activated( $lease_id ): void {
		$lease_id = absint( $lease_id );
		if ( $lease_id <= 0 ) {
			return;
		}

		$existing = $this->manager->get_latest_invoice_by_lease( $lease_id );
		if ( $existing && in_array( (string) $existing->estado, array( 'autorizada', 'firmada', 'enviada' ), true ) ) {
			return;
		}

		$result = $this->manager->issue_lease_invoice( $lease_id );
		if ( is_wp_error( $result ) ) {
			error_log( 'Arriendo Facil billing: lease invoice issue failed for lease ' . $lease_id . ' => ' . $result->get_error_message() );
		}
	}

	/**
	 * AJAX: issue invoice manually for a lease.
	 */
	public function ajax_issue_invoice(): void {
		check_ajax_referer( 'af_billing_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		if ( $lease_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Lease ID invalido.', 'arriendo-facil' ) ), 400 );
		}

		$result = $this->manager->issue_lease_invoice( $lease_id );
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

		if ( ! current_user_can( 'edit_posts' ) ) {
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

		if ( ! current_user_can( 'edit_posts' ) ) {
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

		nocache_headers();
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

		if ( ! current_user_can( 'edit_posts' ) ) {
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
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="comprobante-' . (int) $invoice->id . '.xml"' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Cron worker: retries pending invoices.
	 */
	public function process_retry_queue(): void {
		$candidates = $this->manager->get_retry_candidates( 20 );
		if ( empty( $candidates ) ) {
			return;
		}

		foreach ( $candidates as $invoice ) {
			if ( ! isset( $invoice->id ) ) {
				continue;
			}
			$result = $this->manager->retry_invoice( (int) $invoice->id );
			if ( is_wp_error( $result ) ) {
				error_log( 'Arriendo Facil billing retry failed invoice ' . (int) $invoice->id . ' => ' . $result->get_error_message() );
			}
		}
	}
}
