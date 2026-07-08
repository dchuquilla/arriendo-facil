<?php
/**
 * OTA Notifications
 *
 * Sends notifications to owners when OTA sync events occur.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_OTA_Notifications
 *
 * Handles email and admin notifications for OTA events.
 */
class Arriendo_Facil_OTA_Notifications {

	/**
	 * Constructor - hooks into OTA sync actions.
	 */
	public function __construct() {
		add_action( 'af_accommodation_marked_occupied', array( $this, 'notify_marked_occupied' ), 10, 3 );
		add_action( 'af_occupancy_mismatch_detected', array( $this, 'notify_mismatch' ), 10, 3 );
		add_action( 'af_ota_sync_failed_critical', array( $this, 'notify_sync_failed' ), 10, 3 );
	}

	/**
	 * Notifies owner when accommodation is marked as occupied from remote sync.
	 *
	 * @param int    $accommodation_id Accommodation post ID.
	 * @param string $source          OTA platform (booking, airbnb).
	 * @param array  $remote_status   Remote status data.
	 * @return void
	 */
	public function notify_marked_occupied( $accommodation_id, $source, $remote_status ) {
		$accommodation_id = absint( $accommodation_id );
		$source = sanitize_key( $source );

		$post = get_post( $accommodation_id );
		if ( ! $post ) {
			return;
		}

		$owner_id = (int) get_post_meta( $accommodation_id, '_af_owner_id', true );
		if ( ! $owner_id ) {
			return;
		}

		$owner = get_userdata( $owner_id );
		if ( ! $owner ) {
			return;
		}

		// Get booked dates if available
		$booked_dates_str = '';
		if ( ! empty( $remote_status['booked_dates'] ) ) {
			$dates = $remote_status['booked_dates'];
			if ( is_array( $dates ) && ! empty( $dates ) ) {
				$booked_dates_str = implode( ', ', array_map( function( $date ) {
					return isset( $date['from'], $date['to'] )
						? sprintf( '%s a %s', $date['from'], $date['to'] )
						: '';
				}, $dates ) );
			}
		}

		// Send email
		$this->send_marked_occupied_email( $owner, $post, $source, $booked_dates_str );

		// Log admin notice
		$this->log_admin_notice(
			sprintf(
				__( 'Acomodación "%s" marcada como ocupada desde %s', 'arriendo-facil' ),
				$post->post_title,
				ucfirst( $source )
			),
			'info',
			$accommodation_id
		);
	}

	/**
	 * Notifies owner of occupancy mismatch (remote free, local occupied).
	 *
	 * @param int    $accommodation_id Accommodation post ID.
	 * @param string $source          OTA platform.
	 * @param array  $remote_status   Remote status data.
	 * @return void
	 */
	public function notify_mismatch( $accommodation_id, $source, $remote_status ) {
		$accommodation_id = absint( $accommodation_id );
		$source = sanitize_key( $source );

		$post = get_post( $accommodation_id );
		if ( ! $post ) {
			return;
		}

		$owner_id = (int) get_post_meta( $accommodation_id, '_af_owner_id', true );
		if ( ! $owner_id ) {
			return;
		}

		$owner = get_userdata( $owner_id );
		if ( ! $owner ) {
			return;
		}

		// Send email
		$this->send_mismatch_email( $owner, $post, $source );

		// Log admin notice
		$this->log_admin_notice(
			sprintf(
				__( 'Desacuerdo en disponibilidad de "%s" entre %s y ArriendoFacil. Revisa manualmente.', 'arriendo-facil' ),
				$post->post_title,
				ucfirst( $source )
			),
			'warning',
			$accommodation_id
		);
	}

	/**
	 * Notifies owner when sync fails after multiple retries.
	 *
	 * @param int       $accommodation_id Accommodation post ID.
	 * @param string    $source          OTA platform.
	 * @param Exception $error           Exception that occurred.
	 * @return void
	 */
	public function notify_sync_failed( $accommodation_id, $source, $error ) {
		$accommodation_id = absint( $accommodation_id );
		$source = sanitize_key( $source );

		$post = get_post( $accommodation_id );
		if ( ! $post ) {
			return;
		}

		$owner_id = (int) get_post_meta( $accommodation_id, '_af_owner_id', true );
		if ( ! $owner_id ) {
			return;
		}

		$owner = get_userdata( $owner_id );
		if ( ! $owner ) {
			return;
		}

		// Send error notification email
		$this->send_sync_error_email( $owner, $post, $source, $error );

		// Log admin notice
		$this->log_admin_notice(
			sprintf(
				__( 'Error crítico sincronizando "%s" con %s: %s', 'arriendo-facil' ),
				$post->post_title,
				ucfirst( $source ),
				$error->getMessage()
			),
			'error',
			$accommodation_id
		);
	}

	/**
	 * Sends email when accommodation marked as occupied.
	 *
	 * @param WP_User  $owner Owner user object.
	 * @param WP_Post  $post  Accommodation post.
	 * @param string   $source OTA platform.
	 * @param string   $booked_dates Booked dates string.
	 * @return bool True if email sent successfully.
	 */
	private function send_marked_occupied_email( $owner, $post, $source, $booked_dates ) {
		$to = $owner->user_email;
		$subject = sprintf(
			__( '[ArriendoFácil] %s marcada como ocupada - %s', 'arriendo-facil' ),
			$post->post_title,
			ucfirst( $source )
		);

		$message = sprintf(
			__( "Hola %s,\n\nTu acomodación \"%s\" ha sido sincronizada con %s y aparece como ocupada.\n\nDates ocupadas: %s\n\nPuedes revisar el estado en:\n%s\n\n---\nArriendoFácil", 'arriendo-facil' ),
			$owner->display_name,
			$post->post_title,
			ucfirst( $source ),
			$booked_dates ?: __( 'No especificadas', 'arriendo-facil' ),
			admin_url( 'edit.php?post_type=accommodation&p=' . $post->ID )
		);

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		return wp_mail( $to, $subject, $message, $headers );
	}

	/**
	 * Sends email when occupancy mismatch detected.
	 *
	 * @param WP_User $owner Owner user object.
	 * @param WP_Post $post  Accommodation post.
	 * @param string  $source OTA platform.
	 * @return bool True if email sent successfully.
	 */
	private function send_mismatch_email( $owner, $post, $source ) {
		$to = $owner->user_email;
		$subject = sprintf(
			__( '[ArriendoFácil] Desacuerdo de disponibilidad - %s', 'arriendo-facil' ),
			$post->post_title
		);

		$message = sprintf(
			__( "Hola %s,\n\nHemos detectado un desacuerdo en la disponibilidad de \"%s\":\n- En %s: Disponible (sin reservas)\n- En ArriendoFácil: Ocupada\n\nPor favor revisa manualmente y actualiza el estado si es necesario.\n\nURL: %s\n\n---\nArriendoFácil", 'arriendo-facil' ),
			$owner->display_name,
			$post->post_title,
			ucfirst( $source ),
			admin_url( 'edit.php?post_type=accommodation&p=' . $post->ID )
		);

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		return wp_mail( $to, $subject, $message, $headers );
	}

	/**
	 * Sends email when sync fails after max retries.
	 *
	 * @param WP_User  $owner Owner user object.
	 * @param WP_Post  $post  Accommodation post.
	 * @param string   $source OTA platform.
	 * @param Exception $error Error that occurred.
	 * @return bool True if email sent successfully.
	 */
	private function send_sync_error_email( $owner, $post, $source, $error ) {
		$to = $owner->user_email;
		$subject = sprintf(
			__( '[ArriendoFácil] Error sincronizando con %s - Acción requerida', 'arriendo-facil' ),
			ucfirst( $source )
		);

		$message = sprintf(
			__( "Hola %s,\n\nNo hemos podido sincronizar la disponibilidad de \"%s\" con %s después de varios intentos.\n\nError: %s\n\nPor favor verifica:\n1. Que tus credenciales de API sigan siendo válidas\n2. Que el ID de propiedad sea correcto\n3. Que tengas conexión a internet\n\nURL de configuración: %s\n\n---\nArriendoFácil", 'arriendo-facil' ),
			$owner->display_name,
			$post->post_title,
			ucfirst( $source ),
			$error->getMessage(),
			admin_url( 'admin.php?page=af-ota-integrations' )
		);

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		return wp_mail( $to, $subject, $message, $headers );
	}

	/**
	 * Logs an admin notice that appears on next admin page load.
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type (info, warning, error, success).
	 * @param int    $accommodation_id Accommodation ID for context.
	 * @return void
	 */
	private function log_admin_notice( $message, $type = 'info', $accommodation_id = 0 ) {
		$notices = (array) get_transient( 'af_ota_admin_notices' );

		$notices[] = array(
			'message' => $message,
			'type' => $type,
			'accommodation_id' => $accommodation_id,
			'timestamp' => current_time( 'mysql' ),
		);

		// Keep only last 10 notices
		$notices = array_slice( $notices, -10 );

		set_transient( 'af_ota_admin_notices', $notices, 12 * HOUR_IN_SECONDS );
	}
}
