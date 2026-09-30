<?php
/**
 * AJAX endpoints of the legacy AI tools (only with AF_LEGACY_MODULES).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Legacy_AI_Controller
 */
class Arriendo_Facil_Legacy_AI_Controller {

	/**
	 * Registers AJAX endpoints.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_predict_cost', array( $this, 'ajax_predict_cost' ) );
		add_action( 'wp_ajax_af_generate_document', array( $this, 'ajax_generate_document' ) );
	}

	/**
	 * AJAX: (re)generates a lease document, drafting with AI when no owner template exists.
	 */
	public function ajax_generate_document() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		$lease    = $lease_id ? ( new Arriendo_Facil_Lease() )->get_lease( $lease_id ) : null;
		if ( ! $lease ) {
			wp_send_json_error( array( 'message' => __( 'Contrato no encontrado.', 'arriendo-facil' ) ) );
		}

		if ( ! Arriendo_Facil_Tenancy::can_access_accommodation( (int) $lease->accommodation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$result = ( new Arriendo_Facil_Contract_Generator() )->generate_for_lease( $lease_id, array( 'use_ai' => true ) );
		if ( empty( $result['generated'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo generar un documento de contrato utilizable para este contrato.', 'arriendo-facil' ) ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: predict accommodation cost using AI.
	 */
	public function ajax_predict_cost() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( $_POST['accommodation_id'] ) : 0;
		if ( ! $accommodation_id ) {
			wp_send_json_error( array( 'message' => __( 'ID de alojamiento invalido.', 'arriendo-facil' ) ) );
		}

		$data = array(
			'post_id'      => $accommodation_id,
			'address'      => get_post_meta( $accommodation_id, '_af_address', true ),
			'bedrooms'     => get_post_meta( $accommodation_id, '_af_bedrooms', true ),
			'bathrooms'    => get_post_meta( $accommodation_id, '_af_bathrooms', true ),
			'monthly_rent' => get_post_meta( $accommodation_id, '_af_monthly_rent', true ),
		);

		$ai       = new Arriendo_Facil_AI_Service();
		$result   = $ai->predict_cost( $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}
}
