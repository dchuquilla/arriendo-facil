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
	 * Ensures the owner role exists with required capabilities.
	 *
	 * @return void
	 */
	public static function ensure_owner_role() {
		$role = get_role( 'af_owner' );

		if ( ! $role ) {
			$role = add_role(
				'af_owner',
				__( 'Property Owner', 'arriendo-facil' ),
				array(
					'read'                 => true,
					'upload_files'         => true,
					'edit_posts'           => true,
					'edit_published_posts' => true,
					'publish_posts'        => true,
					'delete_posts'         => true,
				)
			);
		}

		if ( $role instanceof WP_Role ) {
			$required_caps = array(
				'read',
				'upload_files',
				'edit_posts',
				'edit_published_posts',
				'publish_posts',
				'delete_posts',
			);

			foreach ( $required_caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}

		self::sync_existing_owner_users_to_role();
	}

	/**
	 * Migrates existing owner users to af_owner role.
	 *
	 * @return void
	 */
	private static function sync_existing_owner_users_to_role() {
		global $wpdb;

		$owner_user_ids = $wpdb->get_col(
			"SELECT DISTINCT wp_user_id FROM {$wpdb->prefix}af_owner_contacts WHERE wp_user_id IS NOT NULL AND wp_user_id > 0"
		);

		if ( ! is_array( $owner_user_ids ) || empty( $owner_user_ids ) ) {
			return;
		}

		foreach ( $owner_user_ids as $owner_user_id ) {
			$owner_user_id = absint( $owner_user_id );
			if ( ! $owner_user_id ) {
				continue;
			}

			$user = get_userdata( $owner_user_id );
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();
			if ( in_array( 'administrator', $roles, true ) || in_array( 'af_owner', $roles, true ) ) {
				continue;
			}

			$user->set_role( 'af_owner' );
		}
	}

	/**
	 * Runs on plugin activation.
	 *
	 * Creates custom database tables for leases, cleaning services,
	 * owner contacts and AI logs.
	 */
	public static function activate() {
		self::ensure_owner_role();

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
				owner_id_type VARCHAR(20) NOT NULL DEFAULT 'cedula',
				owner_id    VARCHAR(15) NOT NULL,
				owner_email VARCHAR(190) NOT NULL,
				wp_user_id  BIGINT UNSIGNED DEFAULT NULL,
				temp_password_hash VARCHAR(255) DEFAULT NULL,
				subject     VARCHAR(255) NOT NULL,
				message     TEXT NOT NULL,
				status      VARCHAR(20) NOT NULL DEFAULT 'unread',
				has_legal_agent TINYINT(1) NOT NULL DEFAULT 0,
				legal_agent_name VARCHAR(190) DEFAULT NULL,
				legal_agent_id_type VARCHAR(20) DEFAULT NULL,
				legal_agent_id VARCHAR(15) DEFAULT NULL,
				legal_agent_phone VARCHAR(50) DEFAULT NULL,
				legal_agent_email VARCHAR(190) DEFAULT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY owner_id (owner_id),
				KEY owner_id_type (owner_id_type),
				KEY owner_email (owner_email),
				KEY wp_user_id (wp_user_id)
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

		$owner_contacts_table = $wpdb->prefix . 'af_owner_contacts';

		$owner_id_type_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'owner_id_type'
			)
		);

		if ( ! $owner_id_type_exists ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 ADD COLUMN owner_id_type VARCHAR(20) NOT NULL DEFAULT 'cedula' AFTER id"
			);
		}

		$owner_email_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'owner_email'
			)
		);

		if ( ! $owner_email_exists ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 ADD COLUMN owner_email VARCHAR(190) NOT NULL AFTER owner_id"
			);
		}

		$wp_user_id_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'wp_user_id'
			)
		);

		if ( ! $wp_user_id_exists ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 ADD COLUMN wp_user_id BIGINT UNSIGNED DEFAULT NULL AFTER owner_email"
			);
		}

		$temp_password_hash_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'temp_password_hash'
			)
		);

		if ( ! $temp_password_hash_exists ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 ADD COLUMN temp_password_hash VARCHAR(255) DEFAULT NULL AFTER wp_user_id"
			);
		}

		$has_legal_agent_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'has_legal_agent'
			)
		);

		if ( ! $has_legal_agent_exists ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 ADD COLUMN has_legal_agent TINYINT(1) NOT NULL DEFAULT 0 AFTER status"
			);
		}

		$legal_agent_name_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'legal_agent_name'
			)
		);

		if ( ! $legal_agent_name_exists ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 ADD COLUMN legal_agent_name VARCHAR(190) DEFAULT NULL AFTER has_legal_agent"
			);
		}

		$legal_agent_id_type_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'legal_agent_id_type'
			)
		);

		if ( ! $legal_agent_id_type_exists ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 ADD COLUMN legal_agent_id_type VARCHAR(20) DEFAULT NULL AFTER legal_agent_name"
			);
		}

		$legal_agent_id_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'legal_agent_id'
			)
		);

		if ( ! $legal_agent_id_exists ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 ADD COLUMN legal_agent_id VARCHAR(15) DEFAULT NULL AFTER legal_agent_id_type"
			);
		}

		$legal_agent_phone_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'legal_agent_phone'
			)
		);

		if ( ! $legal_agent_phone_exists ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 ADD COLUMN legal_agent_phone VARCHAR(50) DEFAULT NULL AFTER legal_agent_id"
			);
		}

		$legal_agent_email_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'legal_agent_email'
			)
		);

		if ( ! $legal_agent_email_exists ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 ADD COLUMN legal_agent_email VARCHAR(190) DEFAULT NULL AFTER legal_agent_phone"
			);
		}

		// Keep legal-agent columns physically ordered after status in existing installations.
		if ( $has_legal_agent_exists && $legal_agent_name_exists && $legal_agent_id_type_exists && $legal_agent_id_exists && $legal_agent_phone_exists && $legal_agent_email_exists ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 MODIFY COLUMN has_legal_agent TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
				 MODIFY COLUMN legal_agent_name VARCHAR(190) DEFAULT NULL AFTER has_legal_agent,
				 MODIFY COLUMN legal_agent_id_type VARCHAR(20) DEFAULT NULL AFTER legal_agent_name,
				 MODIFY COLUMN legal_agent_id VARCHAR(15) DEFAULT NULL AFTER legal_agent_id_type,
				 MODIFY COLUMN legal_agent_phone VARCHAR(50) DEFAULT NULL AFTER legal_agent_id,
				 MODIFY COLUMN legal_agent_email VARCHAR(190) DEFAULT NULL AFTER legal_agent_phone"
			);
		}

		$owner_id_type = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT DATA_TYPE
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'owner_id'
			)
		);

		if ( 'varchar' !== strtolower( (string) $owner_id_type ) ) {
			$wpdb->query(
				"ALTER TABLE {$owner_contacts_table}
				 MODIFY owner_id VARCHAR(15) NOT NULL"
			);
		}

		$owner_email_index_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND INDEX_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'owner_email'
			)
		);

		if ( ! $owner_email_index_exists ) {
			$wpdb->query( "ALTER TABLE {$owner_contacts_table} ADD KEY owner_email (owner_email)" );
		}

		$wp_user_id_index_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND INDEX_NAME = %s",
				DB_NAME,
				$owner_contacts_table,
				'wp_user_id'
			)
		);

		if ( ! $wp_user_id_index_exists ) {
			$wpdb->query( "ALTER TABLE {$owner_contacts_table} ADD KEY wp_user_id (wp_user_id)" );
		}

		add_option( 'arriendo_facil_version', ARRIENDO_FACIL_VERSION );
		update_option( 'arriendo_facil_version', ARRIENDO_FACIL_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
