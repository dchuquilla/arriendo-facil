<?php
/**
 * Bitácora del portal del inquilino: línea de tiempo y auditoría. Solo se inserta.
 *
 * Guarda quién hizo qué y cuándo (inquilino, gestor o sistema). La IP y el
 * navegador se guardan como huella corta, nunca en claro.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Portal_Events
 */
class Arriendo_Facil_Portal_Events {

	/**
	 * Registra un evento.
	 *
	 * @param int                 $guest_id     Inquilino dueño del registro.
	 * @param int                 $lease_id     Contrato (0 si no aplica).
	 * @param string              $subject_type service_payment | incident | profile | document.
	 * @param int                 $subject_id   Registro afectado.
	 * @param string              $actor_type   tenant | manager | system.
	 * @param int                 $actor_id     Usuario WP del gestor (0 para inquilino/sistema).
	 * @param string              $action       Acción, p. ej. payment_submitted.
	 * @param array<string,mixed> $detail       Datos de contexto (sin secretos).
	 * @return int ID del evento o 0.
	 */
	public static function log( $guest_id, $lease_id, $subject_type, $subject_id, $actor_type, $actor_id, $action, array $detail = array() ) {
		global $wpdb;

		$ok = $wpdb->insert(
			Arriendo_Facil_Portal_Schema::events_table(),
			array(
				'guest_id'     => absint( $guest_id ),
				'lease_id'     => $lease_id ? absint( $lease_id ) : null,
				'subject_type' => sanitize_key( $subject_type ),
				'subject_id'   => absint( $subject_id ),
				'actor_type'   => in_array( $actor_type, array( 'tenant', 'manager', 'system' ), true ) ? $actor_type : 'system',
				'actor_id'     => $actor_id ? absint( $actor_id ) : null,
				'action'       => sanitize_key( $action ),
				'detail'       => $detail ? wp_json_encode( $detail ) : null,
				'ip_hash'      => self::fingerprint( 'REMOTE_ADDR' ),
				'ua_hash'      => self::fingerprint( 'HTTP_USER_AGENT' ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Línea de tiempo de un registro (del más antiguo al más reciente).
	 *
	 * @param string $subject_type Tipo.
	 * @param int    $subject_id   ID.
	 * @param int    $guest_id     Inquilino: siempre se filtra por él.
	 * @return array<int,object>
	 */
	public static function timeline( $subject_type, $subject_id, $guest_id ) {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, actor_type, action, detail, created_at FROM ' . Arriendo_Facil_Portal_Schema::events_table() . ' WHERE subject_type = %s AND subject_id = %d AND guest_id = %d ORDER BY id ASC LIMIT 100', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				sanitize_key( $subject_type ),
				absint( $subject_id ),
				absint( $guest_id )
			)
		);
	}

	/**
	 * Etiqueta legible de una acción.
	 *
	 * @param string $action Acción.
	 * @return string
	 */
	public static function label( $action ) {
		$labels = array(
			'payment_submitted'   => __( 'Pago enviado con comprobante', 'arriendo-facil' ),
			'payment_approved'    => __( 'Pago aprobado', 'arriendo-facil' ),
			'payment_rejected'    => __( 'Pago rechazado', 'arriendo-facil' ),
			'delivery_submitted'  => __( 'Entrega de dinero informada', 'arriendo-facil' ),
			'delivery_received'   => __( 'Dinero recibido: recibo emitido', 'arriendo-facil' ),
			'delivery_rejected'   => __( 'Entrega no confirmada', 'arriendo-facil' ),
			'incident_reported'   => __( 'Incidente reportado', 'arriendo-facil' ),
			'responsibility_set'  => __( 'Responsabilidad definida', 'arriendo-facil' ),
			'status_changed'      => __( 'Estado actualizado', 'arriendo-facil' ),
			'repair_evidence'     => __( 'Evidencia de reparación', 'arriendo-facil' ),
			'tenant_ack'          => __( 'Respuesta del inquilino', 'arriendo-facil' ),
			'evidence_removed'    => __( 'Evidencia retirada por el gestor', 'arriendo-facil' ),
			'profile_updated'     => __( 'Perfil actualizado', 'arriendo-facil' ),
			'email_changed'       => __( 'Correo cambiado', 'arriendo-facil' ),
			'document_uploaded'   => __( 'Documento subido', 'arriendo-facil' ),
			'correction_requested' => __( 'Corrección de datos solicitada', 'arriendo-facil' ),
		);

		return isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
	}

	/**
	 * @param string $server_key Clave de $_SERVER.
	 * @return string|null
	 */
	private static function fingerprint( $server_key ) {
		$value = isset( $_SERVER[ $server_key ] ) ? (string) wp_unslash( $_SERVER[ $server_key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- solo se usa para el hash.

		return '' === $value ? null : md5( $value . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ) );
	}
}
