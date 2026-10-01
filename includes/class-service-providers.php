<?php
/**
 * Service providers (personal de mantenimiento) catalog.
 *
 * Tenant-scoped by owner_id (WP user). Only platform admins (manage_options)
 * can see all providers. Property managers see only their own.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Service_Providers {

	const NONCE = 'af_service_provider_nonce';

	/**
	 * Hook AJAX actions.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_sp_create', array( $this, 'ajax_create' ) );
		add_action( 'wp_ajax_af_sp_update', array( $this, 'ajax_update' ) );
		add_action( 'wp_ajax_af_sp_delete', array( $this, 'ajax_delete' ) );
	}

	/**
	 * Returns the table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'af_service_providers';
	}

	/**
	 * Common trades in Spanish (plomería, albañilería, carpintería...).
	 *
	 * @return array<string,string>
	 */
	public static function trades() {
		return array(
			'general'      => __( 'General', 'arriendo-facil' ),
			'plomero'      => __( 'Plomero', 'arriendo-facil' ),
			'albanil'      => __( 'Albañil', 'arriendo-facil' ),
			'carpintero'   => __( 'Carpintero', 'arriendo-facil' ),
			'electricista' => __( 'Electricista', 'arriendo-facil' ),
			'pintor'       => __( 'Pintor', 'arriendo-facil' ),
			'vidriero'     => __( 'Vidriero', 'arriendo-facil' ),
			'cerrajero'    => __( 'Cerrajero', 'arriendo-facil' ),
			'jardinero'    => __( 'Jardinero', 'arriendo-facil' ),
			'tecnico'      => __( 'Técnico (electrodomésticos)', 'arriendo-facil' ),
			'calefaccion'  => __( 'Calefacción/ACS', 'arriendo-facil' ),
			'control_plagas' => __( 'Control de plagas', 'arriendo-facil' ),
			'limpieza'     => __( 'Limpieza', 'arriendo-facil' ),
			'otro'         => __( 'Otro', 'arriendo-facil' ),
		);
	}

	/**
	 * Lists providers scoped to the current user.
	 *
	 * @param array $args Optional filters (trade,status,search,owner_id).
	 * @return array
	 */
	public static function list( $args = array() ) {
		global $wpdb;

		$where = array( '1=1' );
		$vals  = array();

		if ( Arriendo_Facil_Tenancy::can_manage_all() ) {
			if ( isset( $args['owner_id'] ) && $args['owner_id'] > 0 ) {
				$where[] = 'owner_id = %d';
				$vals[]  = absint( $args['owner_id'] );
			}
		} else {
			$user_id = get_current_user_id();
			$where[] = 'owner_id = %d';
			$vals[]  = absint( $user_id );
		}

		if ( ! empty( $args['trade'] ) ) {
			$where[] = 'trade = %s';
			$vals[]  = sanitize_key( $args['trade'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$vals[]  = sanitize_key( $args['status'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like  = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[] = '(name LIKE %s OR company LIKE %s OR phone LIKE %s OR city LIKE %s)';
			$vals[]  = $like;
			$vals[]  = $like;
			$vals[]  = $like;
			$vals[]  = $like;
		}

		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY name ASC';
		if ( $vals ) {
			$sql = $wpdb->prepare( $sql, $vals ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (array) $wpdb->get_results( $sql );
	}

	/**
	 * Creates a provider.
	 *
	 * @param array $data
	 * @return int|WP_Error
	 */
	public static function create( $data ) {
		global $wpdb;

		$name    = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$owner_id = isset( $data['owner_id'] ) ? absint( $data['owner_id'] ) : get_current_user_id();

		if ( empty( $name ) ) {
			return new WP_Error( 'af_sp_name_required', __( 'El nombre del contacto es obligatorio.', 'arriendo-facil' ) );
		}

		if ( ! Arriendo_Facil_Tenancy::can_manage_all() && $owner_id !== get_current_user_id() ) {
			return new WP_Error( 'af_sp_permission', __( 'Permiso denegado.', 'arriendo-facil' ) );
		}

		$trade = isset( $data['trade'] ) ? sanitize_key( $data['trade'] ) : 'general';
		$trades = self::trades();
		if ( ! array_key_exists( $trade, $trades ) ) {
			$trade = 'general';
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'owner_id'    => $owner_id,
				'name'        => $name,
				'trade'       => $trade,
				'company'     => isset( $data['company'] ) ? sanitize_text_field( $data['company'] ) : null,
				'phone'       => isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : null,
				'whatsapp'    => isset( $data['whatsapp'] ) ? sanitize_text_field( $data['whatsapp'] ) : null,
				'email'       => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : null,
				'city'        => isset( $data['city'] ) ? sanitize_text_field( $data['city'] ) : null,
				'zone'        => isset( $data['zone'] ) ? sanitize_text_field( $data['zone'] ) : null,
				'hourly_rate' => isset( $data['hourly_rate'] ) ? round( (float) $data['hourly_rate'], 2 ) : 0.0,
				'job_price'   => isset( $data['job_price'] ) ? round( (float) $data['job_price'], 2 ) : 0.0,
				'rating'      => isset( $data['rating'] ) ? min( 5, max( 0, (int) $data['rating'] ) ) : 0,
				'notes'       => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
				'status'      => 'active',
			),
			array( '%d','%s','%s','%s','%s','%s','%s','%s','%s','%f','%f','%d','%s','%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_sp_insert_failed', __( 'No se pudo guardar el contacto.', 'arriendo-facil' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a provider.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return true|WP_Error
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id || ! class_exists( 'Arriendo_Facil_Tenancy' ) || ! Arriendo_Facil_Tenancy::can_access_service_provider( $id ) ) {
			return new WP_Error( 'af_sp_not_found', __( 'Contacto no encontrado o sin permiso.', 'arriendo-facil' ) );
		}

		$up = array();

		if ( isset( $data['name'] ) ) {
			$up['name'] = sanitize_text_field( $data['name'] );
			if ( empty( $up['name'] ) ) {
				return new WP_Error( 'af_sp_name_required', __( 'El nombre es obligatorio.', 'arriendo-facil' ) );
			}
		}
		if ( array_key_exists( 'trade', $data ) ) {
			$trade = sanitize_key( $data['trade'] );
			$up['trade'] = array_key_exists( $trade, self::trades() ) ? $trade : 'general';
		}
		if ( array_key_exists( 'company', $data ) ) {
			$up['company'] = $data['company'] === '' ? null : sanitize_text_field( $data['company'] );
		}
		if ( array_key_exists( 'phone', $data ) ) {
			$up['phone'] = $data['phone'] === '' ? null : sanitize_text_field( $data['phone'] );
		}
		if ( array_key_exists( 'whatsapp', $data ) ) {
			$up['whatsapp'] = $data['whatsapp'] === '' ? null : sanitize_text_field( $data['whatsapp'] );
		}
		if ( array_key_exists( 'email', $data ) ) {
			$up['email'] = $data['email'] === '' ? null : sanitize_email( $data['email'] );
		}
		if ( array_key_exists( 'city', $data ) ) {
			$up['city'] = $data['city'] === '' ? null : sanitize_text_field( $data['city'] );
		}
		if ( array_key_exists( 'zone', $data ) ) {
			$up['zone'] = $data['zone'] === '' ? null : sanitize_text_field( $data['zone'] );
		}
		if ( array_key_exists( 'hourly_rate', $data ) ) {
			$up['hourly_rate'] = round( (float) $data['hourly_rate'], 2 );
		}
		if ( array_key_exists( 'job_price', $data ) ) {
			$up['job_price'] = round( (float) $data['job_price'], 2 );
		}
		if ( array_key_exists( 'rating', $data ) ) {
			$up['rating'] = min( 5, max( 0, (int) $data['rating'] ) );
		}
		if ( array_key_exists( 'notes', $data ) ) {
			$up['notes'] = $data['notes'] === '' ? null : sanitize_textarea_field( $data['notes'] );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$s = sanitize_key( $data['status'] );
			$up['status'] = in_array( $s, array( 'active','inactive' ), true ) ? $s : 'active';
		}

		if ( empty( $up ) ) {
			return true;
		}

		$wpdb->update( self::table(), $up, array( 'id' => $id ), null, array( '%d' ) );
		return true;
	}

	public static function delete( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id || ! Arriendo_Facil_Tenancy::can_access_service_provider( $id ) ) {
			return new WP_Error( 'af_sp_not_found', __( 'Contacto no encontrado o sin permiso.', 'arriendo-facil' ) );
		}
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	public function ajax_create() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}
		$res = self::create( wp_unslash( $_POST ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'id' => $res, 'message' => __( 'Contacto guardado.', 'arriendo-facil' ) ) );
	}

	public function ajax_update() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}
		$id  = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$res = self::update( $id, wp_unslash( $_POST ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'message' => __( 'Contacto actualizado.', 'arriendo-facil' ) ) );
	}

	public function ajax_delete() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}
		$id  = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$res = self::delete( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'message' => __( 'Contacto eliminado.', 'arriendo-facil' ) ) );
	}
}
