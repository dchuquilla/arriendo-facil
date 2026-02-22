<?php
/**
 * Handles plugin activation and deactivation.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Activator
 *
 * Creates required database tables on activation and cleans up on deactivation.
 */
class Arriendo_Facil_Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * Creates custom database tables for leases, cleaning services,
	 * owner contacts and AI logs.
	 */
	public static function activate() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$tables = array(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_leases (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				accommodation_id BIGINT(20) UNSIGNED NOT NULL,
				guest_id      BIGINT(20) UNSIGNED NOT NULL,
				start_date    DATE NOT NULL,
				end_date      DATE NOT NULL,
				monthly_rent  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				status        VARCHAR(20) NOT NULL DEFAULT 'draft',
				document_url  VARCHAR(255) DEFAULT NULL,
				created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY accommodation_id (accommodation_id),
				KEY guest_id (guest_id)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_cleaning_requests (
				id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				accommodation_id BIGINT(20) UNSIGNED NOT NULL,
				requested_date   DATE NOT NULL,
				completed_date   DATE DEFAULT NULL,
				status           VARCHAR(20) NOT NULL DEFAULT 'pending',
				notes            TEXT DEFAULT NULL,
				created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY accommodation_id (accommodation_id)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_owner_contacts (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				owner_id    BIGINT(20) UNSIGNED NOT NULL,
				subject     VARCHAR(255) NOT NULL,
				message     TEXT NOT NULL,
				status      VARCHAR(20) NOT NULL DEFAULT 'unread',
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY owner_id (owner_id)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_ai_logs (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				action      VARCHAR(100) NOT NULL,
				input_data  LONGTEXT DEFAULT NULL,
				output_data LONGTEXT DEFAULT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_guests (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED DEFAULT NULL,
				first_name  VARCHAR(100) NOT NULL,
				last_name   VARCHAR(100) NOT NULL,
				email       VARCHAR(200) NOT NULL,
				phone       VARCHAR(50) DEFAULT NULL,
				id_number   VARCHAR(100) DEFAULT NULL,
				ai_score    DECIMAL(5,2) DEFAULT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY email (email)
			) $charset_collate;",
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		add_option( 'arriendo_facil_version', ARRIENDO_FACIL_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
