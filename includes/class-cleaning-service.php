<?php
/**
 * Cleaning Service Custom Post Type and request management.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Cleaning_Service
 *
 * Registers the 'cleaning_service' CPT and manages cleaning requests
 * stored in the af_cleaning_requests table.
 */
class Arriendo_Facil_Cleaning_Service {

	/**
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_cleaning_service', array( $this, 'save_meta' ) );
		add_action( 'wp_ajax_af_create_cleaning_request', array( $this, 'ajax_create_request' ) );
		add_action( 'wp_ajax_af_update_cleaning_request', array( $this, 'ajax_update_request' ) );
		add_action( 'wp_ajax_af_generate_cleaning_contract_word', array( $this, 'ajax_generate_cleaning_contract_word' ) );
	}

	/**
	 * Registers the cleaning_service CPT.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Servicios de limpieza', 'arriendo-facil' ),
			'singular_name'      => __( 'Servicio de limpieza', 'arriendo-facil' ),
			'menu_name'          => __( 'Servicios de limpieza', 'arriendo-facil' ),
			'add_new'            => __( 'Agregar nuevo', 'arriendo-facil' ),
			'add_new_item'       => __( 'Agregar nuevo servicio de limpieza', 'arriendo-facil' ),
			'edit_item'          => __( 'Editar servicio de limpieza', 'arriendo-facil' ),
			'new_item'           => __( 'Nuevo servicio de limpieza', 'arriendo-facil' ),
			'view_item'          => __( 'Ver servicio de limpieza', 'arriendo-facil' ),
			'search_items'       => __( 'Buscar servicios de limpieza', 'arriendo-facil' ),
			'not_found'          => __( 'No se encontraron servicios de limpieza', 'arriendo-facil' ),
			'not_found_in_trash' => __( 'No se encontraron servicios de limpieza en la papelera', 'arriendo-facil' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'arriendo-facil',
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor' ),
			// Keep classic editor so the custom details table is always visible.
			'show_in_rest'       => false,
		);

		register_post_type( 'cleaning_service', $args );
	}

	/**
	 * Adds meta boxes for cleaning service details.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'af_cleaning_service_details',
			__( 'Detalles del servicio de limpieza', 'arriendo-facil' ),
			array( $this, 'render_meta_box' ),
			'cleaning_service',
			'normal',
			'high'
		);
	}

	/**
	 * Renders the cleaning service details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'af_save_cleaning_service_meta', 'af_cleaning_service_nonce' );

		$company_name         = get_post_meta( $post->ID, '_af_company_name', true );
		$company_ruc          = get_post_meta( $post->ID, '_af_company_ruc', true );
		$services_description = get_post_meta( $post->ID, '_af_services_description', true );

		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/cleaning-service-meta-box.php';
	}

	/**
	 * Saves the cleaning service meta data.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['af_cleaning_service_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['af_cleaning_service_nonce'] ) ), 'af_save_cleaning_service_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['af_company_name'] ) ) {
			update_post_meta( $post_id, '_af_company_name', sanitize_text_field( wp_unslash( $_POST['af_company_name'] ) ) );
		}
		if ( isset( $_POST['af_company_ruc'] ) ) {
			$ruc = preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['af_company_ruc'] ) );
			if ( 1 === preg_match( '/^[0-9]{13}$/', $ruc ) ) {
				update_post_meta( $post_id, '_af_company_ruc', $ruc );
			}
		}
		if ( isset( $_POST['af_services_description'] ) ) {
			update_post_meta( $post_id, '_af_services_description', sanitize_textarea_field( wp_unslash( $_POST['af_services_description'] ) ) );
		}
	}

	/**
	 * Creates a new cleaning request via AJAX.
	 */
	public function ajax_create_request() {
		check_ajax_referer( 'af_cleaning_request_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( $_POST['accommodation_id'] ) : 0;
		$requested_date   = isset( $_POST['requested_date'] ) ? sanitize_text_field( wp_unslash( $_POST['requested_date'] ) ) : '';
		$notes            = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( ! $accommodation_id || ! $requested_date ) {
			wp_send_json_error( array( 'message' => __( 'Faltan campos obligatorios.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'af_cleaning_requests',
			array(
				'accommodation_id' => $accommodation_id,
				'requested_date'   => $requested_date,
				'notes'            => $notes,
				'status'           => 'pending',
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			wp_send_json_success( array( 'id' => $wpdb->insert_id ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'No se pudo crear la solicitud de limpieza.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Updates a cleaning request status via AJAX.
	 */
	public function ajax_update_request() {
		check_ajax_referer( 'af_cleaning_request_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		$status     = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		$allowed_statuses = array( 'pending', 'in_progress', 'completed', 'cancelled' );
		if ( ! $request_id || ! in_array( $status, $allowed_statuses, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Datos invalidos.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$data = array( 'status' => $status );
		if ( 'completed' === $status ) {
			$data['completed_date'] = current_time( 'Y-m-d' );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'af_cleaning_requests',
			$data,
			array( 'id' => $request_id ),
			array_fill( 0, count( $data ), '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'No se pudo actualizar la solicitud de limpieza.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Returns cleaning requests for a given accommodation.
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return array Array of request objects.
	 */
	public function get_requests_for_accommodation( $accommodation_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_cleaning_requests WHERE accommodation_id = %d ORDER BY requested_date DESC",
				$accommodation_id
			)
		);
	}

	/**
	 * Generates a Word document with cleaning contract request text.
	 */
	public function ajax_generate_cleaning_contract_word() {
		check_ajax_referer( 'af_cleaning_request_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permiso denegado.', 'arriendo-facil' ) );
		}

		$request_id = isset( $_GET['request_id'] ) ? absint( $_GET['request_id'] ) : 0;
		if ( ! $request_id ) {
			wp_die( esc_html__( 'Solicitud de limpieza invalida.', 'arriendo-facil' ) );
		}

		global $wpdb;
		$request = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.*, p.post_title AS accommodation_title
				 FROM {$wpdb->prefix}af_cleaning_requests r
				 LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
				 WHERE r.id = %d
				 LIMIT 1",
				$request_id
			)
		);

		if ( ! $request ) {
			wp_die( esc_html__( 'Solicitud de limpieza no encontrada.', 'arriendo-facil' ) );
		}

		$contract_text = __( 'El cliente solicita un contrato de servicio de limpieza para el inmueble indicado, con alcance y condiciones por definir entre las partes.', 'arriendo-facil' );

		$ai = new Arriendo_Facil_AI_Service();
		$ai_result = $ai->generate_cleaning_contract(
			array(
				'request_id'         => (int) $request->id,
				'accommodation'      => (string) $request->accommodation_title,
				'requested_date'     => (string) $request->requested_date,
				'status'             => (string) $request->status,
				'notes'              => (string) $request->notes,
			)
		);

		if ( ! is_wp_error( $ai_result ) && ! empty( $ai_result['contract_text'] ) ) {
			$contract_text = (string) $ai_result['contract_text'];
		}

		$file_name = 'cleaning-contract-request-' . (int) $request->id . '.doc';

		nocache_headers();
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/msword; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $file_name );

		$title = __( 'Contrato de Servicio de Limpieza', 'arriendo-facil' );

		echo "<html><head><meta charset='utf-8'></head><body>";
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p><strong>' . esc_html__( 'Solicitud:', 'arriendo-facil' ) . '</strong> ' . esc_html( $contract_text ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Alojamiento:', 'arriendo-facil' ) . '</strong> ' . esc_html( (string) $request->accommodation_title ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Fecha solicitada:', 'arriendo-facil' ) . '</strong> ' . esc_html( (string) $request->requested_date ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Estado:', 'arriendo-facil' ) . '</strong> ' . esc_html( (string) $request->status ) . '</p>';
		if ( ! empty( $request->notes ) ) {
			echo '<p><strong>' . esc_html__( 'Notas:', 'arriendo-facil' ) . '</strong> ' . nl2br( esc_html( (string) $request->notes ) ) . '</p>';
		}
		echo '</body></html>';
		exit;
	}
}
