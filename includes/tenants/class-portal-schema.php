<?php
/**
 * Esquema del portal del inquilino v2: evidencia, bitácora y columnas nuevas.
 *
 * Una sola versión de esquema (`af_portal_v2_db_version`). Las columnas nuevas de
 * tablas existentes se añaden solo si faltan, con valores por defecto que dejan
 * el comportamiento anterior intacto (servicios = los paga el propietario, pagos
 * históricos = aprobados por el gestor).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Portal_Schema
 */
class Arriendo_Facil_Portal_Schema {

	const DB_VERSION = '3';
	const OPTION     = 'af_portal_v2_db_version';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 6 );
	}

	/**
	 * @return string
	 */
	public static function evidence_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_evidence';
	}

	/**
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_portal_events';
	}

	/**
	 * Entregas de dinero del inquilino al propietario/gestor (servicios que el gestor paga).
	 *
	 * @return string
	 */
	public static function collections_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_service_collections';
	}

	/**
	 * Crea tablas y columnas una sola vez por versión.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::evidence_table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				guest_id BIGINT UNSIGNED NOT NULL,
				lease_id BIGINT UNSIGNED DEFAULT NULL,
				subject_type VARCHAR(20) NOT NULL,
				subject_id BIGINT UNSIGNED NOT NULL,
				stage VARCHAR(12) NOT NULL,
				uploaded_by VARCHAR(10) NOT NULL,
				storage VARCHAR(10) NOT NULL,
				object_key VARCHAR(255) NOT NULL,
				mime_type VARCHAR(100) NOT NULL,
				file_size INT UNSIGNED NOT NULL DEFAULT 0,
				checksum_sha256 CHAR(64) NOT NULL,
				removed_at DATETIME DEFAULT NULL,
				removed_by BIGINT UNSIGNED DEFAULT NULL,
				removed_reason VARCHAR(190) DEFAULT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY subject (subject_type, subject_id),
				KEY guest_id (guest_id)
			) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . self::events_table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				guest_id BIGINT UNSIGNED NOT NULL,
				lease_id BIGINT UNSIGNED DEFAULT NULL,
				subject_type VARCHAR(20) NOT NULL,
				subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				actor_type VARCHAR(10) NOT NULL,
				actor_id BIGINT UNSIGNED DEFAULT NULL,
				action VARCHAR(40) NOT NULL,
				detail LONGTEXT DEFAULT NULL,
				ip_hash CHAR(32) DEFAULT NULL,
				ua_hash CHAR(32) DEFAULT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY subject (subject_type, subject_id),
				KEY guest_id (guest_id)
			) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . self::collections_table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				schedule_id BIGINT UNSIGNED NOT NULL,
				period VARCHAR(7) NOT NULL,
				guest_id BIGINT UNSIGNED NOT NULL,
				lease_id BIGINT UNSIGNED DEFAULT NULL,
				amount DECIMAL(10,2) NOT NULL DEFAULT 0,
				method VARCHAR(12) NOT NULL DEFAULT 'efectivo',
				delivered_on DATE NOT NULL,
				reference VARCHAR(190) DEFAULT NULL,
				submitted_by VARCHAR(10) NOT NULL DEFAULT 'tenant',
				status VARCHAR(12) NOT NULL DEFAULT 'submitted',
				receipt_no VARCHAR(24) DEFAULT NULL,
				receipt_hash CHAR(64) DEFAULT NULL,
				reviewed_by BIGINT UNSIGNED DEFAULT NULL,
				reviewed_at DATETIME DEFAULT NULL,
				review_note VARCHAR(255) DEFAULT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY schedule_period (schedule_id, period),
				KEY guest_id (guest_id),
				KEY receipt_no (receipt_no)
			) {$charset};"
		);

		self::add_columns(
			$wpdb->prefix . 'af_service_schedules',
			array(
				'payer' => "VARCHAR(10) NOT NULL DEFAULT 'owner'",
			)
		);

		self::add_columns(
			$wpdb->prefix . 'af_service_bill_payments',
			array(
				'status'       => "VARCHAR(12) NOT NULL DEFAULT 'approved'",
				'submitted_by' => "VARCHAR(10) NOT NULL DEFAULT 'manager'",
				'guest_id'     => 'BIGINT UNSIGNED DEFAULT NULL',
				'reviewed_by'  => 'BIGINT UNSIGNED DEFAULT NULL',
				'reviewed_at'  => 'DATETIME DEFAULT NULL',
				'review_note'  => 'VARCHAR(255) DEFAULT NULL',
			)
		);

		self::add_columns(
			$wpdb->prefix . 'af_cleaning_requests',
			array(
				'responsibility'    => "VARCHAR(12) NOT NULL DEFAULT 'por_definir'",
				'responsibility_by' => 'BIGINT UNSIGNED DEFAULT NULL',
				'responsibility_at' => 'DATETIME DEFAULT NULL',
				'responsibility_note' => 'VARCHAR(255) DEFAULT NULL',
				'recurrence_of_id'  => 'BIGINT UNSIGNED DEFAULT NULL',
				'tenant_ack'        => 'VARCHAR(12) DEFAULT NULL',
				'tenant_ack_at'     => 'DATETIME DEFAULT NULL',
				'tenant_ack_note'   => 'VARCHAR(255) DEFAULT NULL',
			)
		);

		self::add_columns(
			$wpdb->prefix . 'af_guests',
			array(
				'profile_updated_at' => 'DATETIME DEFAULT NULL',
				'profile_updated_review' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
			)
		);

		update_option( self::OPTION, self::DB_VERSION, false );
	}

	/**
	 * ¿Existe la columna? (con caché). Permite que el tablero de servicios siga funcionando
	 * aunque la migración todavía no haya corrido en esta instalación.
	 *
	 * @param string $table  Tabla.
	 * @param string $column Columna.
	 * @return bool
	 */
	public static function column_exists( $table, $column ) {
		global $wpdb;
		static $cache = array();

		if ( ! isset( $cache[ $table ] ) ) {
			$cols            = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cache[ $table ] = is_array( $cols ) ? array_map( 'strtolower', $cols ) : array();
		}

		return in_array( strtolower( $column ), $cache[ $table ], true );
	}

	/**
	 * Añade columnas que faltan; ignora tablas que aún no existen.
	 *
	 * @param string               $table   Tabla.
	 * @param array<string,string> $columns Columna => definición.
	 * @return void
	 */
	private static function add_columns( $table, array $columns ) {
		global $wpdb;

		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}

		$existing = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = is_array( $existing ) ? array_flip( array_map( 'strtolower', $existing ) ) : array();

		foreach ( $columns as $name => $definition ) {
			if ( ! isset( $existing[ strtolower( $name ) ] ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}
	}
}
