<?php
/**
 * Tenant notifications ("avisos") with read confirmation.
 *
 * The admin sends a notice (charge reminder, services, general) to a tenant.
 * Every notice carries a tokenized public URL; when the tenant clicks
 * "Confirmo que recibí y leí" the row flips to `read`, a read receipt alert
 * is raised for the sending admin, and the history shows the confirmation.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Aviso
 */
class Arriendo_Facil_Aviso {

	const TABLE_PREFIX = 'af_notification_messages';
	const QUERY_CONFIRM = 'af_aviso';

	/**
	 * Hooks into AJAX + the public confirm endpoint.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_send_aviso', array( $this, 'ajax_send' ) );
		add_action( 'wp_ajax_af_get_avisos', array( $this, 'ajax_get' ) );
		add_action( 'template_redirect', array( $this, 'handle_public_confirm' ) );
	}

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_PREFIX;
	}

	/**
	 * Supported notice types.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'cobro'     => __( 'Cobro / pago', 'arriendo-facil' ),
			'servicios' => __( 'Servicios / mantenimiento', 'arriendo-facil' ),
			'aviso'     => __( 'Aviso general', 'arriendo-facil' ),
		);
	}

	/**
	 * Resolves the target guest + email for the given lease.
	 *
	 * @param int $lease_id Lease ID.
	 * @return array{guest_id:int,email:string}|null
	 */
	public static function guest_for_lease( $lease_id ) {
		global $wpdb;

		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return null;
		}

		$lease = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT guest_id FROM {$wpdb->prefix}af_leases WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table prefix.
				$lease_id
			)
		);

		if ( ! $lease || ! (int) $lease->guest_id ) {
			return null;
		}

		$guest = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, email FROM {$wpdb->prefix}af_guests WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table prefix.
				(int) $lease->guest_id
			)
		);

		if ( ! $guest ) {
			return null;
		}

		return array(
			'guest_id' => (int) $guest->id,
			'email'    => (string) $guest->email,
		);
	}

	/**
	 * Creates a notice and emails the tenant.
	 *
	 * @param array{accommodation_id:int,lease_id:int,guest_id:int,type:string,subject:string,body:string,email:string} $args.
	 * @return array{id:int}|WP_Error
	 */
	public static function send( $args ) {
		global $wpdb;

		$accommodation_id = isset( $args['accommodation_id'] ) ? absint( $args['accommodation_id'] ) : 0;
		$lease_id         = isset( $args['lease_id'] ) ? absint( $args['lease_id'] ) : 0;
		$guest_id         = isset( $args['guest_id'] ) ? absint( $args['guest_id'] ) : 0;
		$type             = isset( $args['type'] ) && isset( self::types()[ $args['type'] ] ) ? sanitize_key( $args['type'] ) : 'aviso';
		$subject          = sanitize_text_field( (string) ( $args['subject'] ?? '' ) );
		$body             = sanitize_textarea_field( (string) ( $args['body'] ?? '' ) );
		$email            = sanitize_email( (string) ( $args['email'] ?? '' ) );

		if ( ! $accommodation_id || ! $email || '' === $subject ) {
			return new WP_Error( 'invalid_aviso', __( 'Faltan datos: inmueble, asunto y correo del inquilino.', 'arriendo-facil' ) );
		}

		$selector = bin2hex( random_bytes( 5 ) );
		$token    = bin2hex( random_bytes( 20 ) );
		$token_hash = self::hash_token( $selector, $token );

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'accommodation_id' => $accommodation_id,
				'lease_id'         => $lease_id ? $lease_id : null,
				'guest_id'         => $guest_id ? $guest_id : null,
				'type'             => $type,
				'subject'          => $subject,
				'body'             => $body,
				'status'           => 'sent',
				'recipient_email'  => $email,
				'sent_to_user_id'  => 0,
				'selector'         => $selector,
				'token_hash'       => $token_hash,
				'created_by'       => get_current_user_id(),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'aviso_insert_failed', __( 'No se pudo guardar el aviso.', 'arriendo-facil' ) );
		}

		$notice_id = (int) $wpdb->insert_id;

		self::deliver_email( $notice_id, $selector, $token, $email, $subject, $body );

		return array( 'id' => $notice_id );
	}

	/**
	 * Sends the notification email with the confirm link.
	 */
	private static function deliver_email( $notice_id, $selector, $token, $email, $subject, $body ) {
		$confirm_url = home_url( '/?' . self::QUERY_CONFIRM . '=1&sid=' . rawurlencode( $selector ) . '&t=' . rawurlencode( $token ) );

		$body_html = wpautop( $body );

		$message_html = '<!DOCTYPE html><html><body style="margin:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fa;padding:24px 0;">
			<tr><td align="center">
			<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="background:#ffffff;border:1px solid #e4e7ec;border-radius:12px;overflow:hidden;">
			<tr><td style="padding:28px 32px;">
			<h2 style="margin:0 0 8px;color:#17202a;font-size:20px;">' . esc_html( $subject ) . '</h2>
			<p style="margin:0 0 16px;color:#667085;font-size:13px;">' . esc_html__( 'Aviso de tu administración de arriendos', 'arriendo-facil' ) . '</p>
			<div style="color:#344054;font-size:15px;line-height:1.6;">' . $body_html . '</div>
			<p style="margin:24px 0 0;">
				<a href="' . esc_url( $confirm_url ) . '" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:12px 20px;border-radius:8px;">' . esc_html__( 'Confirmo que recibí y leí este aviso', 'arriendo-facil' ) . '</a>
			</p>
			<p style="margin:8px 0 0;font-size:12px;color:#98a2b3;">' . esc_html__( 'Si el botón no funciona, copia este enlace:', 'arriendo-facil' ) . '<br />
			<a href="' . esc_url( $confirm_url ) . '" style="color:#1d4ed8;word-break:break-all;">' . esc_html( $confirm_url ) . '</a></p>
			</td></tr></table>
			</td></tr></table></body></html>';

		wp_mail(
			$email,
			'[Arriendo Fácil] ' . $subject,
			$message_html,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * @param string $selector  Raw selector.
	 * @param string $token     Raw token.
	 * @return string
	 */
	public static function hash_token( $selector, $token ) {
		return hash_hmac( 'sha256', $selector . '|' . $token, wp_salt( 'auth' ) . 'af_aviso_v1' );
	}

	/**
	 * Public confirmation endpoint: marks the notice read.
	 */
	public function handle_public_confirm() {
		if ( empty( $_GET[ self::QUERY_CONFIRM ] ) ) {
			return;
		}

		$selector = sanitize_key( (string) ( $_GET['sid'] ?? '' ) );
		$token    = sanitize_key( (string) ( $_GET['t'] ?? '' ) );

		if ( '' === $selector || '' === $token ) {
			$this->render_public_result( 'invalid' );

			return;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, token_hash, status, lease_id, accommodation_id, subject
				 FROM " . self::table() . " WHERE selector = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table.
				$selector
			)
		);

		if ( ! $row ) {
			$this->render_public_result( 'invalid' );

			return;
		}

		if ( ! hash_equals( (string) $row->token_hash, self::hash_token( $selector, $token ) ) ) {
			$this->render_public_result( 'invalid' );

			return;
		}

		$already_read = ( 'read' === $row->status );

		if ( ! $already_read ) {
			$wpdb->update(
				self::table(),
				array(
					'status'     => 'read',
					'read_at'    => current_time( 'mysql' ),
					'read_ip'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			// Palanca de conocimiento para el administrador que envió el aviso.
			if ( class_exists( 'Arriendo_Facil_Alerts' ) ) {
				Arriendo_Facil_Alerts::add(
					(int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT created_by FROM " . self::table() . " WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table.
							(int) $row->id
						)
					),
					'aviso_read',
					'info',
					sprintf(
						/* translators: %s: notice subject */
						__( 'Aviso leído: %s', 'arriendo-facil' ),
						(string) $row->subject
					),
					__( 'El inquilino confirmó que recibió y leyó el aviso.', 'arriendo-facil' ),
					admin_url( 'admin.php?page=af-avisos' ),
					'aviso_read_' . (int) $row->id
				);
			}
		}

		$this->render_public_result( $already_read ? 'already' : 'ok' );
	}

	/**
	 * Minimal standalone confirmation page (no theme dependencies).
	 *
	 * @param string $state invalid|ok|already.
	 */
	private function render_public_result( $state ) {
		nocache_headers();
		status_header( 'invalid' === $state ? 404 : 200 );

		$title   = __( 'Confirmación de lectura', 'arriendo-facil' );
		$heading = __( 'Aviso confirmado', 'arriendo-facil' );
		$message = __( 'Gracias por confirmar. Tu administración quedó notificada de que leíste el aviso.', 'arriendo-facil' );

		if ( 'invalid' === $state ) {
			$heading = __( 'Enlace inválido', 'arriendo-facil' );
			$message = __( 'Este enlace no es válido o el aviso ya no está disponible.', 'arriendo-facil' );
		} elseif ( 'already' === $state ) {
			$heading = __( 'Ya habías confirmado', 'arriendo-facil' );
			$message = __( 'Este aviso ya había sido confirmado como leído.', 'arriendo-facil' );
		}

		$img = 'invalid' === $state ? '&#9888;&#xFE0F;' : '&#9989;';

		echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<style>body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f5f7fa;display:flex;min-height:100vh;align-items:center;justify-content:center;}';
		echo '.afc{text-align:center;background:#fff;border:1px solid #e4e7ec;border-radius:16px;padding:40px 32px;max-width:420px;margin:24px;box-shadow:0 10px 30px rgba(16,24,40,.06);}';
		echo '.afc_icon{font-size:52px;line-height:1;margin-bottom:12px;}';
		echo '.afc_h{margin:0 0 8px;font-size:20px;color:#17202a;}';
		echo '.afc_p{margin:0;color:#667085;font-size:14px;line-height:1.6;}</style></head><body>';
		echo '<div class="afc"><div class="afc_icon" aria-hidden="true">' . $img . '</div>';
		echo '<h1 class="afc_h">' . esc_html( $heading ) . '</h1>';
		echo '<p class="afc_p">' . esc_html( $message ) . '</p></div></body></html>';
		exit;
	}

	/**
	 * AJAX: send a notice. Exposed to administrators (and super-admin).
	 */
	public function ajax_send() {
		check_ajax_referer( 'af_aviso_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;

		if ( $lease_id && ! Arriendo_Facil_Tenancy::can_access_lease( $lease_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este contrato.', 'arriendo-facil' ) ), 403 );
		}

		$guest = self::guest_for_lease( $lease_id );
		if ( ! $guest ) {
			wp_send_json_error( array( 'message' => __( 'No se encontró el inquilino del contrato.', 'arriendo-facil' ) ), 400 );
		}

		$accommodation_id = 0;
		if ( $lease_id ) {
			global $wpdb;

			$accommodation_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT accommodation_id FROM {$wpdb->prefix}af_leases WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table prefix.
					$lease_id
				)
			);
		}

		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'aviso';
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';

		$result = self::send(
			array(
				'accommodation_id' => $accommodation_id,
				'lease_id'         => $lease_id,
				'guest_id'         => $guest['guest_id'],
				'type'             => $type,
				'subject'          => $subject,
				'body'             => $body,
				'email'            => $guest['email'],
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Aviso enviado. El inquilino recibió el correo con el botón de confirmación.', 'arriendo-facil' ),
				'id'      => $result['id'],
			)
		);
	}

	/**
	 * AJAX: last notices filtered by accommodation (for the collections hub).
	 */
	public function ajax_get() {
		check_ajax_referer( 'af_aviso_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		global $wpdb;

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;
		$limit            = min( 20, isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 10 );

		$where  = '1=1';
		$params = array();
		if ( $accommodation_id && Arriendo_Facil_Tenancy::can_access_accommodation( $accommodation_id ) ) {
			$where  .= ' AND accommodation_id = %d';
			$params[] = $accommodation_id;
		} elseif ( $accommodation_id ) {
			wp_send_json_error( array( 'message' => __( 'Sin acceso al inmueble.', 'arriendo-facil' ) ), 403 );
		}

		$where  .= ' ORDER BY id DESC LIMIT %d';
		$params[] = $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ' . $where, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		wp_send_json_success( array( 'avisos' => $rows ) );
	}
}