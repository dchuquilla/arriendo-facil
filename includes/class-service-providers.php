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
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
		add_action( 'wp_ajax_af_sp_create', array( $this, 'ajax_create' ) );
		add_action( 'wp_ajax_af_sp_update', array( $this, 'ajax_update' ) );
		add_action( 'wp_ajax_af_sp_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_af_sp_upload_photo', array( $this, 'ajax_upload_photo' ) );
		add_action( 'wp_ajax_af_sp_review', array( $this, 'ajax_review' ) );
	}

	const PHOTO_MAX_BYTES = 5242880;
	const PHOTO_MIN_SIDE  = 300;

	/**
	 * Adds the photo/verification columns once, for any admin-area user, so the
	 * catalog never queries columns an older install does not have yet.
	 */
	public static function maybe_migrate() {
		if ( '1' === get_option( 'af_sp_photo_schema' ) ) {
			return;
		}

		Arriendo_Facil_Activator::maybe_add_service_provider_columns();
		update_option( 'af_sp_photo_schema', '1', false );
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
		$photo_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT photo_id FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		if ( $photo_id ) {
			wp_delete_attachment( $photo_id, true );
		}
		return true;
	}

	/**
	 * Providers Arriendo Facil recommends (verified by the platform team).
	 * Visible to every property manager, read-only for them.
	 *
	 * @return array
	 */
	public static function list_recommended() {
		global $wpdb;

		return (array) $wpdb->get_results(
			'SELECT * FROM ' . self::table() . " WHERE is_recommended = 1 AND status = 'active' ORDER BY trade ASC, name ASC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Public URL of the provider's photo, only when the platform verified it.
	 *
	 * @param object $provider Provider row.
	 * @param bool   $any      Also return photos still pending review (owner/admin views).
	 * @return string
	 */
	public static function photo_url( $provider, $any = false ) {
		if ( empty( $provider->photo_id ) ) {
			return '';
		}
		if ( ! $any && 'verified' !== (string) $provider->photo_status ) {
			return '';
		}

		$url = wp_get_attachment_image_url( (int) $provider->photo_id, 'thumbnail' );

		return $url ? $url : '';
	}

	/**
	 * Validates and stores the provider's photo. It stays "pending" until the
	 * Arriendo Facil team reviews it.
	 *
	 * @param int   $id   Provider ID.
	 * @param array $file Entry of $_FILES.
	 * @return true|WP_Error
	 */
	public static function set_photo( $id, $file ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id || ! Arriendo_Facil_Tenancy::can_access_service_provider( $id ) ) {
			return new WP_Error( 'af_sp_not_found', __( 'Contacto no encontrado o sin permiso.', 'arriendo-facil' ) );
		}
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'af_sp_photo_upload', __( 'No se pudo recibir la foto. Intenta de nuevo.', 'arriendo-facil' ) );
		}
		if ( (int) $file['size'] > self::PHOTO_MAX_BYTES ) {
			return new WP_Error( 'af_sp_photo_size', __( 'La foto pesa más de 5 MB.', 'arriendo-facil' ) );
		}

		$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mime = $info ? (string) $info['mime'] : '';
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return new WP_Error( 'af_sp_photo_type', __( 'Sube una foto JPG, PNG o WebP.', 'arriendo-facil' ) );
		}
		if ( min( (int) $info[0], (int) $info[1] ) < self::PHOTO_MIN_SIDE ) {
			return new WP_Error( 'af_sp_photo_small', __( 'La foto es muy pequeña. Usa una de al menos 300 × 300 px, donde se vea claramente el rostro.', 'arriendo-facil' ) );
		}

		$hash      = hash_file( 'sha256', $file['tmp_name'] );
		$duplicate = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE photo_hash = %s AND id <> %d LIMIT 1', $hash, $id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		if ( $duplicate ) {
			return new WP_Error( 'af_sp_photo_duplicate', __( 'Esta foto ya está usada en otro técnico. Cada técnico debe tener su propia foto real.', 'arriendo-facil' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$_FILES['af_sp_photo'] = $file;
		$attachment_id         = media_handle_upload( 'af_sp_photo', 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$old = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT photo_id FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// A new photo always invalidates the previous verification.
		$wpdb->update(
			self::table(),
			array(
				'photo_id'       => (int) $attachment_id,
				'photo_hash'     => $hash,
				'photo_status'   => 'pending',
				'is_recommended' => 0,
				'verified_at'    => null,
				'verified_by'    => null,
			),
			array( 'id' => $id )
		);

		if ( $old && $old !== (int) $attachment_id ) {
			wp_delete_attachment( $old, true );
		}

		return true;
	}

	/**
	 * Platform-team review: approve/reject the photo, or toggle "recommended".
	 * A provider can only be recommended once its photo is verified.
	 *
	 * @param int    $id     Provider ID.
	 * @param string $action approve_photo | reject_photo | recommend | unrecommend.
	 * @return true|WP_Error
	 */
	public static function review( $id, $action ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! Arriendo_Facil_Tenancy::can_manage_all() ) {
			return new WP_Error( 'af_sp_permission', __( 'Permiso denegado.', 'arriendo-facil' ) );
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, photo_status FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return new WP_Error( 'af_sp_not_found', __( 'Contacto no encontrado.', 'arriendo-facil' ) );
		}

		$now = current_time( 'mysql' );

		switch ( $action ) {
			case 'approve_photo':
				if ( 'none' === $row->photo_status ) {
					return new WP_Error( 'af_sp_no_photo', __( 'Este técnico no tiene foto.', 'arriendo-facil' ) );
				}
				$data = array( 'photo_status' => 'verified' );
				break;
			case 'reject_photo':
				$data = array(
					'photo_status'   => 'rejected',
					'is_recommended' => 0,
					'verified_at'    => null,
					'verified_by'    => null,
				);
				break;
			case 'recommend':
				if ( 'verified' !== $row->photo_status ) {
					return new WP_Error( 'af_sp_not_verified', __( 'Verifica primero la foto del técnico para recomendarlo.', 'arriendo-facil' ) );
				}
				$data = array(
					'is_recommended' => 1,
					'verified_at'    => $now,
					'verified_by'    => get_current_user_id(),
				);
				break;
			case 'unrecommend':
				$data = array(
					'is_recommended' => 0,
					'verified_at'    => null,
					'verified_by'    => null,
				);
				break;
			default:
				return new WP_Error( 'af_sp_bad_action', __( 'Acción no válida.', 'arriendo-facil' ) );
		}

		$wpdb->update( self::table(), $data, array( 'id' => $id ) );

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

	public function ajax_upload_photo() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}
		$id  = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$res = self::set_photo( $id, isset( $_FILES['photo'] ) ? $_FILES['photo'] : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'message' => __( 'Foto guardada.', 'arriendo-facil' ) ) );
	}

	public function ajax_review() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! Arriendo_Facil_Tenancy::can_manage_all() ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}
		$id     = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$action = isset( $_POST['review'] ) ? sanitize_key( wp_unslash( $_POST['review'] ) ) : '';
		$res    = self::review( $id, $action );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'message' => __( 'Listo.', 'arriendo-facil' ) ) );
	}
}
