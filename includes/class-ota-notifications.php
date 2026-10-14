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

		return Arriendo_Facil_Email::send(
			$to,
			$subject,
			array(
				'preheader' => $subject,
				'eyebrow'   => ucfirst( $source ),
				'title'     => __( 'Acomodación marcada como ocupada', 'arriendo-facil' ),
				'status'    => array( 'type' => 'neutral', 'label' => __( 'Ocupada', 'arriendo-facil' ) ),
				'greeting'  => sprintf(
					/* translators: %s: owner name */
					__( 'Hola %s,', 'arriendo-facil' ),
					$owner->display_name
				),
				'body_text' => sprintf(
					/* translators: 1: accommodation title, 2: OTA platform */
					__( 'Tu acomodación "%1$s" ha sido sincronizada con %2$s y aparece como ocupada.', 'arriendo-facil' ),
					$post->post_title,
					ucfirst( $source )
				),
				'details'   => array( __( 'Fechas ocupadas', 'arriendo-facil' ) => $booked_dates ? $booked_dates : __( 'No especificadas', 'arriendo-facil' ) ),
				'button'    => array(
					'label' => __( 'Revisar estado', 'arriendo-facil' ),
					'url'   => admin_url( 'edit.php?post_type=accommodation&p=' . $post->ID ),
				),
			),
			Arriendo_Facil_Email::CAT_NOTIFICATION,
			array( 'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>' )
		);
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

		return Arriendo_Facil_Email::send(
			$to,
			$subject,
			array(
				'preheader' => $subject,
				'eyebrow'   => ucfirst( $source ),
				'title'     => __( 'Desacuerdo de disponibilidad', 'arriendo-facil' ),
				'status'    => array( 'type' => 'warning', 'label' => __( 'Pendiente', 'arriendo-facil' ) ),
				'greeting'  => sprintf(
					/* translators: %s: owner name */
					__( 'Hola %s,', 'arriendo-facil' ),
					$owner->display_name
				),
				'body_text' => sprintf(
					/* translators: %s: accommodation title */
					__( 'Hemos detectado un desacuerdo en la disponibilidad de "%s". Por favor revisa manualmente y actualiza el estado si es necesario.', 'arriendo-facil' ),
					$post->post_title
				),
				'details'   => array(
					ucfirst( $source )  => __( 'Disponible (sin reservas)', 'arriendo-facil' ),
					'Arriendo Fácil'    => __( 'Ocupada', 'arriendo-facil' ),
				),
				'button'    => array(
					'label' => __( 'Revisar acomodación', 'arriendo-facil' ),
					'url'   => admin_url( 'edit.php?post_type=accommodation&p=' . $post->ID ),
				),
			),
			Arriendo_Facil_Email::CAT_NOTIFICATION,
			array( 'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>' )
		);
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

		return Arriendo_Facil_Email::send(
			$to,
			$subject,
			array(
				'preheader' => $subject,
				'eyebrow'   => ucfirst( $source ),
				'title'     => __( 'Error de sincronización', 'arriendo-facil' ),
				'status'    => array( 'type' => 'error', 'label' => __( 'Acción requerida', 'arriendo-facil' ) ),
				'greeting'  => sprintf(
					/* translators: %s: owner name */
					__( 'Hola %s,', 'arriendo-facil' ),
					$owner->display_name
				),
				'body_text' => sprintf(
					/* translators: 1: accommodation title, 2: OTA platform */
					__( 'No hemos podido sincronizar la disponibilidad de "%1$s" con %2$s después de varios intentos.', 'arriendo-facil' ),
					$post->post_title,
					ucfirst( $source )
				),
				'details'   => array( __( 'Error', 'arriendo-facil' ) => $error->getMessage() ),
				'notice'    => array(
					'type' => 'warning',
					'text' => __( "Por favor verifica:\n1. Que tus credenciales de API sigan siendo válidas\n2. Que el ID de propiedad sea correcto\n3. Que tengas conexión a internet", 'arriendo-facil' ),
				),
				'button'    => array(
					'label' => __( 'Abrir configuración', 'arriendo-facil' ),
					'url'   => admin_url( 'admin.php?page=af-ota-integrations' ),
				),
			),
			Arriendo_Facil_Email::CAT_NOTIFICATION,
			array( 'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>' )
		);
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
