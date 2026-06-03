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

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_visit_slots (
				id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				accommodation_id BIGINT(20) UNSIGNED NOT NULL,
				visit_date       DATE NOT NULL,
				start_time       TIME NOT NULL,
				end_time         TIME NOT NULL,
				status           VARCHAR(20) NOT NULL DEFAULT 'open',
				created_by       BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_slot (accommodation_id, visit_date, start_time),
				KEY accommodation_id (accommodation_id),
				KEY status (status)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_visit_bookings (
				id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				slot_id          BIGINT(20) UNSIGNED NOT NULL,
				accommodation_id BIGINT(20) UNSIGNED NOT NULL,
				guest_name       VARCHAR(190) NOT NULL,
				guest_email      VARCHAR(190) NOT NULL,
				guest_phone      VARCHAR(50) DEFAULT NULL,
				guest_id_number  VARCHAR(100) DEFAULT NULL,
				status           VARCHAR(20) NOT NULL DEFAULT 'confirmed',
				notes            TEXT DEFAULT NULL,
				created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_slot_booking (slot_id),
				KEY accommodation_id (accommodation_id),
				KEY guest_email (guest_email),
				KEY status (status)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_interest_queue (
				id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				accommodation_id BIGINT(20) UNSIGNED NOT NULL,
				name             VARCHAR(190) NOT NULL,
				email            VARCHAR(190) NOT NULL,
				phone            VARCHAR(50) DEFAULT NULL,
				message          TEXT DEFAULT NULL,
				status           VARCHAR(20) NOT NULL DEFAULT 'queued',
				created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY accommodation_id (accommodation_id),
				KEY email (email),
				KEY status (status)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_reservations (
				id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				accommodation_id   BIGINT(20) UNSIGNED NOT NULL,
				guest_id           BIGINT(20) UNSIGNED DEFAULT NULL,
				deposit_amount     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				hold_until         DATETIME NOT NULL,
				payment_reference  VARCHAR(190) DEFAULT NULL,
				payment_status     VARCHAR(30) NOT NULL DEFAULT 'pending',
				status             VARCHAR(30) NOT NULL DEFAULT 'reserved',
				reservation_status VARCHAR(30) NOT NULL DEFAULT 'reserved',
				notes              TEXT DEFAULT NULL,
				release_reason     TEXT DEFAULT NULL,
				released_at        DATETIME DEFAULT NULL,
				created_by         BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY accommodation_id (accommodation_id),
				KEY guest_id (guest_id),
				KEY status (status),
				KEY reservation_status (reservation_status),
				KEY hold_until (hold_until)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_lease_events (
				id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				lease_id         BIGINT(20) UNSIGNED DEFAULT NULL,
				accommodation_id BIGINT(20) UNSIGNED DEFAULT NULL,
				event_type       VARCHAR(80) NOT NULL,
				event_payload    LONGTEXT DEFAULT NULL,
				created_by       BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY lease_id (lease_id),
				KEY accommodation_id (accommodation_id),
				KEY event_type (event_type)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_notifications_log (
				id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				accommodation_id  BIGINT(20) UNSIGNED DEFAULT NULL,
				notification_type VARCHAR(80) NOT NULL,
				recipient         VARCHAR(190) NOT NULL,
				delivery_status   VARCHAR(20) NOT NULL DEFAULT 'pending',
				created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY accommodation_id (accommodation_id),
				KEY notification_type (notification_type),
				KEY delivery_status (delivery_status)
			) $charset_collate;",

			// ── Facturación Electrónica SRI ──────────────────────────────────

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_emission_points (
				id                       BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				codigo_establecimiento   CHAR(3) NOT NULL DEFAULT '001',
				codigo_punto_emision     CHAR(3) NOT NULL DEFAULT '001',
				descripcion              VARCHAR(255) DEFAULT NULL,
				activo                   TINYINT(1) NOT NULL DEFAULT 1,
				secuencial_actual        BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_punto (codigo_establecimiento, codigo_punto_emision)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_electronic_invoices (
				id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				lease_id             BIGINT(20) UNSIGNED DEFAULT NULL,
				cleaning_request_id  BIGINT(20) UNSIGNED DEFAULT NULL,
				tipo_comprobante     VARCHAR(2) NOT NULL DEFAULT '01',
				clave_acceso         CHAR(49) DEFAULT NULL,
				numero_autorizacion  VARCHAR(49) DEFAULT NULL,
				fecha_autorizacion   DATETIME DEFAULT NULL,
				ambiente             TINYINT(1) NOT NULL DEFAULT 1,
				estado               VARCHAR(20) NOT NULL DEFAULT 'generada',
				numero_comprobante   VARCHAR(17) DEFAULT NULL,
				subtotal_0           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
				subtotal_iva         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
				iva_valor            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
				total                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
				xml_firmado          LONGTEXT DEFAULT NULL,
				xml_autorizacion     LONGTEXT DEFAULT NULL,
				ride_path            VARCHAR(500) DEFAULT NULL,
				errores              TEXT DEFAULT NULL,
				created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY clave_acceso (clave_acceso),
				KEY lease_id (lease_id),
				KEY cleaning_request_id (cleaning_request_id),
				KEY estado (estado),
				KEY tipo_comprobante (tipo_comprobante)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_sri_log (
				id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				invoice_id        BIGINT(20) UNSIGNED NOT NULL,
				tipo_operacion    VARCHAR(20) NOT NULL,
				request_payload   LONGTEXT DEFAULT NULL,
				response_payload  LONGTEXT DEFAULT NULL,
				http_status       INT DEFAULT NULL,
				created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY invoice_id (invoice_id),
				KEY tipo_operacion (tipo_operacion)
			) $charset_collate;",
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		$reservations_table = $wpdb->prefix . 'af_reservations';
		$reservation_status_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$reservations_table,
				'reservation_status'
			)
		);

		$reservation_simple_status_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$reservations_table,
				'status'
			)
		);

		if ( ! $reservation_simple_status_exists ) {
			$wpdb->query(
				"ALTER TABLE {$reservations_table}
				 ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'reserved' AFTER payment_status"
			);
		}

		if ( $reservation_status_exists ) {
			$wpdb->query(
				"UPDATE {$reservations_table}
				 SET status = reservation_status
				 WHERE (status IS NULL OR status = '')"
			);
		}

		$status_index_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND INDEX_NAME = %s",
				DB_NAME,
				$reservations_table,
				'status'
			)
		);

		if ( ! $status_index_exists ) {
			$wpdb->query( "ALTER TABLE {$reservations_table} ADD KEY status (status)" );
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

		// Seed a default emission point if none exists yet.
		$emission_table = $wpdb->prefix . 'af_emission_points';
		$ep_count       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$emission_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 0 === $ep_count ) {
			$wpdb->insert(
				$emission_table,
				array(
					'codigo_establecimiento' => '001',
					'codigo_punto_emision'   => '001',
					'descripcion'            => 'Punto de emisión principal',
					'activo'                 => 1,
					'secuencial_actual'      => 1,
				),
				array( '%s', '%s', '%s', '%d', '%d' )
			);
		}

		add_option( 'arriendo_facil_version', ARRIENDO_FACIL_VERSION );
		update_option( 'arriendo_facil_version', ARRIENDO_FACIL_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'af_sri_retry_cron' );
		}
		flush_rewrite_rules();
	}
}
