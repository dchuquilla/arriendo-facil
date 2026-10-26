<?php
/**
 * Registro de auditoría de la configuración SRI.
 *
 * Guarda únicamente quién hizo qué y cuándo. Nunca almacena contraseñas,
 * certificados ni valores de campos: solo nombres de campos modificados.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_SRI_Audit {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'af_sri_audit_db_version';

	/**
	 * Etiquetas legibles de los eventos registrados.
	 *
	 * @return array<string,string>
	 */
	public static function event_labels(): array {
		return array(
			'config_saved'         => __( 'Datos del emisor / ambiente guardados', 'arriendo-facil' ),
			'cert_uploaded'        => __( 'Certificado cargado', 'arriendo-facil' ),
			'cert_password'        => __( 'Contraseña del certificado actualizada', 'arriendo-facil' ),
			'cert_tested'          => __( 'Certificado comprobado', 'arriendo-facil' ),
			'emission_point_saved' => __( 'Punto de emisión guardado', 'arriendo-facil' ),
			'connection_checked'   => __( 'Conexión con el SRI comprobada', 'arriendo-facil' ),
		);
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'af_sri_audit';
	}

	private static function ensure_table(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				scope_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				actor_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				event         VARCHAR(40) NOT NULL,
				detail        VARCHAR(255) NOT NULL DEFAULT '',
				created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY scope_created (scope_user_id, created_at)
			) {$charset};"
		);
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Registra un evento. $detail debe ser texto corto sin datos sensibles.
	 *
	 * @param string   $event         Clave de evento (ver event_labels()).
	 * @param string   $detail        Detalle no sensible.
	 * @param int|null $scope_user_id Propietario al que pertenece la configuración (null = global).
	 */
	public static function log( string $event, string $detail = '', ?int $scope_user_id = null ): void {
		global $wpdb;
		self::ensure_table();
		$wpdb->insert(
			self::table(),
			array(
				'scope_user_id' => (int) $scope_user_id,
				'actor_id'      => get_current_user_id(),
				'event'         => sanitize_key( $event ),
				'detail'        => mb_substr( sanitize_text_field( $detail ), 0, 255 ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Últimos eventos del ámbito indicado.
	 *
	 * @param int|null $scope_user_id Propietario (null = configuración global).
	 * @param int      $limit         Máximo de filas.
	 * @return array<int,object>
	 */
	public static function recent( ?int $scope_user_id = null, int $limit = 20 ): array {
		global $wpdb;
		self::ensure_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.*, u.display_name FROM ' . self::table() . ' a LEFT JOIN ' . $wpdb->users . ' u ON u.ID = a.actor_id WHERE a.scope_user_id = %d ORDER BY a.id DESC LIMIT %d',
				(int) $scope_user_id,
				$limit
			)
		);
	}
}
