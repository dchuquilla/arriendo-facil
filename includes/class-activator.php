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
	 * Ensures the tenant role exists with least-privilege capabilities.
	 *
	 * @return void
	 */
	public static function ensure_tenant_role() {
		$role = get_role( 'af_tenant' );

		if ( ! $role ) {
			$role = add_role(
				'af_tenant',
				__( 'Inquilino', 'arriendo-facil' ),
				array(
					'read' => true,
				)
			);
		}

		if ( $role instanceof WP_Role ) {
			$required_caps = array(
				'read',
				'af_tenant_portal',
				'af_tenant_manage_profile',
				'af_tenant_view_own_leases',
				'af_tenant_view_own_reservations',
				'af_tenant_submit_reviews',
			);

			foreach ( $required_caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Ensures the property-admin (subadmin) role exists with required
	 * capabilities. This role is licensed per property-management company:
	 * each holder only sees the buildings/units/leases/tenants scoped to
	 * them (see Arriendo_Facil_Tenancy). Replaces the legacy `af_owner` role,
	 * which conflated "real estate owner" with "person who logs in".
	 *
	 * @return void
	 */
	public static function ensure_owner_role() {
		$role = get_role( 'af_property_admin' );

		if ( ! $role ) {
			$role = add_role(
				'af_property_admin',
				__( 'Administrador de Propiedades', 'arriendo-facil' ),
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
				'af_view_billing',
				Arriendo_Facil_Tenancy::CAP,
			);

			foreach ( $required_caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}

		$admin_role = get_role( 'administrator' );
		if ( $admin_role instanceof WP_Role ) {
			foreach ( array( 'af_view_billing', Arriendo_Facil_Tenancy::CAP ) as $cap ) {
				if ( ! $admin_role->has_cap( $cap ) ) {
					$admin_role->add_cap( $cap );
				}
			}
		}

		self::sync_existing_owner_users_to_role();
		self::migrate_legacy_af_owner_role();
		self::heal_current_user_capabilities();
	}

	/**
	 * Defensive self-heal: grants the required capabilities directly on the
	 * user object of the currently logged-in property admin/administrator.
	 * Guards against accounts whose role definition was updated with new
	 * caps (e.g. `af_manage_properties`) after the user's own capability
	 * cache (`wp_capabilities` user meta) was already populated.
	 *
	 * @return void
	 */
	private static function heal_current_user_capabilities() {
		if ( ! function_exists( 'wp_get_current_user' ) || ! is_user_logged_in() ) {
			return;
		}

		$current_user = wp_get_current_user();
		if ( ! ( $current_user instanceof WP_User ) || ! $current_user->exists() ) {
			return;
		}

		$roles = (array) $current_user->roles;
		if ( ! in_array( 'af_property_admin', $roles, true ) && ! in_array( 'administrator', $roles, true ) ) {
			return;
		}

		foreach ( array( 'af_view_billing', Arriendo_Facil_Tenancy::CAP ) as $cap ) {
			if ( ! $current_user->has_cap( $cap ) ) {
				$current_user->add_cap( $cap );
			}
		}
	}

	/**
	 * Migrates existing owner-contact users to the af_property_admin role.
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
			if ( in_array( 'administrator', $roles, true ) || in_array( 'af_property_admin', $roles, true ) ) {
				continue;
			}

			$user->set_role( 'af_property_admin' );
		}
	}

	/**
	 * One-time migration: moves any user still holding the legacy `af_owner`
	 * role to `af_property_admin`, then removes the legacy role definition
	 * so it can't be reassigned by mistake.
	 *
	 * @return void
	 */
	private static function migrate_legacy_af_owner_role() {
		if ( ! get_role( 'af_owner' ) ) {
			return;
		}

		$legacy_users = get_users( array( 'role' => 'af_owner', 'fields' => 'ID' ) );
		foreach ( $legacy_users as $legacy_user_id ) {
			$user = get_userdata( absint( $legacy_user_id ) );
			if ( $user instanceof WP_User ) {
				$user->set_role( 'af_property_admin' );
			}
		}

		remove_role( 'af_owner' );
	}

	/**
	 * Runs on plugin activation.
	 *
	 * Creates custom database tables for leases, cleaning services,
	 * owner contacts and AI logs.
	 */
	public static function activate() {
		self::ensure_tenant_role();
		self::ensure_owner_role();

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$tables = array(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_buildings (
				id                     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name                   VARCHAR(190) NOT NULL,
				address                VARCHAR(255) DEFAULT NULL,
				city                   VARCHAR(120) DEFAULT NULL,
				owner_id               BIGINT(20) UNSIGNED DEFAULT NULL,
				monthly_hoa_total      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Alicuota total mensual del edificio a prorratear',
				status                 VARCHAR(20) NOT NULL DEFAULT 'active',
				notes                  TEXT DEFAULT NULL,
				created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY owner_id (owner_id),
				KEY status (status)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_units (
				id                     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				building_id            BIGINT(20) UNSIGNED NOT NULL,
				accommodation_id       BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Post ID del CPT accommodation, si existe ficha',
				unit_code              VARCHAR(50) NOT NULL COMMENT 'Ej: A-101',
				hoa_coefficient        DECIMAL(7,4) NOT NULL DEFAULT 0.0000 COMMENT 'Porcentaje de prorrateo de alicuota',
				area_m2                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				status                 VARCHAR(20) NOT NULL DEFAULT 'active',
				created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_building_unit (building_id, unit_code),
				KEY building_id (building_id),
				KEY accommodation_id (accommodation_id),
				KEY status (status)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_charges (
				id                     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				lease_id               BIGINT(20) UNSIGNED DEFAULT NULL,
				unit_id                BIGINT(20) UNSIGNED DEFAULT NULL,
				guest_id               BIGINT(20) UNSIGNED DEFAULT NULL,
				charge_type            VARCHAR(30) NOT NULL COMMENT 'canon, alicuota, agua, luz, gas, internet, multa, otro',
				period                 CHAR(7) NOT NULL COMMENT 'YYYY-MM',
				description            VARCHAR(255) DEFAULT NULL,
				amount                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
				amount_paid            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
				due_date               DATE NOT NULL,
				status                 VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, partial, paid, overdue, void',
				created_by             BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_charge_period (lease_id, charge_type, period),
				KEY lease_id (lease_id),
				KEY unit_id (unit_id),
				KEY guest_id (guest_id),
				KEY charge_type (charge_type),
				KEY period (period),
				KEY status_due (status, due_date)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_payments (
				id                     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				charge_id              BIGINT(20) UNSIGNED NOT NULL,
				amount                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
				payment_date           DATE NOT NULL,
				method                 VARCHAR(30) NOT NULL DEFAULT 'transferencia' COMMENT 'transferencia, efectivo, deposito, cheque, otro',
				reference              VARCHAR(190) DEFAULT NULL COMMENT 'Numero de comprobante o referencia bancaria',
				receipt_url            VARCHAR(500) DEFAULT NULL,
				notes                  TEXT DEFAULT NULL,
				recorded_by            BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY charge_id (charge_id),
				KEY payment_date (payment_date),
				KEY method (method)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_meter_readings (
				id                     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				unit_id                BIGINT(20) UNSIGNED NOT NULL,
				service                VARCHAR(30) NOT NULL COMMENT 'agua, luz, gas',
				period                 CHAR(7) NOT NULL COMMENT 'YYYY-MM',
				previous_reading       DECIMAL(12,3) NOT NULL DEFAULT 0.000,
				current_reading        DECIMAL(12,3) NOT NULL DEFAULT 0.000,
				consumption            DECIMAL(12,3) NOT NULL DEFAULT 0.000,
				unit_rate              DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
				calculated_amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
				recorded_by            BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_unit_service_period (unit_id, service, period),
				KEY unit_id (unit_id),
				KEY period (period)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_guest_documents (
				id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				guest_id          BIGINT(20) UNSIGNED NOT NULL,
				doc_type          VARCHAR(40) NOT NULL COMMENT 'garantia_alicuota, cedula_papeleta, certificado_bancario, certificado_laboral',
				storage           VARCHAR(10) NOT NULL DEFAULT 'local' COMMENT 'r2 (privado) o local (fallback publico WP)',
				object_key        VARCHAR(255) NOT NULL COMMENT 'clave R2 o attachment_id (fallback local)',
				mime_type         VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
				file_size         INT UNSIGNED NOT NULL DEFAULT 0,
				checksum_sha256   CHAR(64) DEFAULT NULL,
				uploaded_by       BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY guest_id (guest_id),
				KEY doc_type (doc_type)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_leases (
				id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				accommodation_id BIGINT(20) UNSIGNED NOT NULL,
				guest_id      BIGINT(20) UNSIGNED NOT NULL,
				start_date    DATE NOT NULL,
				end_date      DATE NOT NULL,
				monthly_rent  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				status        VARCHAR(20) NOT NULL DEFAULT 'draft',
				deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				deposit_damage_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				deposit_service_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				deposit_refund_amount DECIMAL(10,2) DEFAULT NULL,
				deposit_settlement_status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
				deposit_settlement_notes TEXT DEFAULT NULL,
				deposit_settled_at DATETIME DEFAULT NULL,
				legal_status VARCHAR(30) NOT NULL DEFAULT 'pendiente',
				legal_notes TEXT DEFAULT NULL,
				legal_updated_at DATETIME DEFAULT NULL,
				document_url  VARCHAR(255) DEFAULT NULL,
				template_attachment_id BIGINT(20) UNSIGNED DEFAULT NULL,
				deleted_at    DATETIME DEFAULT NULL,
				created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY accommodation_id (accommodation_id),
				KEY guest_id (guest_id)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_cleaning_requests (
				id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				accommodation_id BIGINT(20) UNSIGNED NOT NULL,
				unit_id          BIGINT(20) UNSIGNED DEFAULT NULL,
				lease_id         BIGINT(20) UNSIGNED DEFAULT NULL,
				request_type     VARCHAR(30) NOT NULL DEFAULT 'limpieza',
				priority         VARCHAR(20) NOT NULL DEFAULT 'media',
				cost             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
				reported_by      VARCHAR(30) NOT NULL DEFAULT 'operador',
				requested_date   DATE NOT NULL,
				completed_date   DATE DEFAULT NULL,
				status           VARCHAR(20) NOT NULL DEFAULT 'pending',
				notes            TEXT DEFAULT NULL,
				created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY accommodation_id (accommodation_id),
				KEY lease_id (lease_id),
				KEY unit_id (unit_id)
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
				status      VARCHAR(20) NOT NULL DEFAULT 'inactive',
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

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_ai_processing_queue (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				action      VARCHAR(100) NOT NULL,
				context     LONGTEXT NOT NULL,
				result      LONGTEXT DEFAULT NULL,
				status      VARCHAR(20) NOT NULL DEFAULT 'pending',
				user_id     BIGINT(20) UNSIGNED DEFAULT NULL,
				attempts    TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
				error_msg   TEXT DEFAULT NULL,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY status_created (status, created_at),
				KEY user_id (user_id)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_guests (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED DEFAULT NULL,
				first_name  VARCHAR(100) NOT NULL,
				last_name   VARCHAR(100) NOT NULL,
				email       VARCHAR(200) NOT NULL,
				phone       VARCHAR(50) DEFAULT NULL,
				id_number   VARCHAR(100) DEFAULT NULL,
				nationality VARCHAR(100) DEFAULT NULL,
				birth_city  VARCHAR(150) DEFAULT NULL,
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

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_guest_onboarding_tokens (
				id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				selector          VARCHAR(64) NOT NULL,
				token_hash        VARCHAR(255) NOT NULL,
				guest_id          BIGINT(20) UNSIGNED NOT NULL,
				accommodation_id  BIGINT(20) UNSIGNED NOT NULL,
				visit_booking_id  BIGINT(20) UNSIGNED DEFAULT NULL,
				purpose           VARCHAR(50) NOT NULL DEFAULT 'legal_profile',
				recipient_email   VARCHAR(190) NOT NULL,
				expires_at        DATETIME NOT NULL,
				used_at           DATETIME DEFAULT NULL,
				max_attempts      SMALLINT(5) UNSIGNED NOT NULL DEFAULT 8,
				attempts          SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
				status            VARCHAR(20) NOT NULL DEFAULT 'active',
				created_by        BIGINT(20) UNSIGNED DEFAULT NULL,
				created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY selector (selector),
				KEY guest_id (guest_id),
				KEY accommodation_id (accommodation_id),
				KEY visit_booking_id (visit_booking_id),
				KEY recipient_email (recipient_email),
				KEY status_expires (status, expires_at)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_review_groups (
				id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				lease_id          BIGINT(20) UNSIGNED NOT NULL,
				accommodation_id  BIGINT(20) UNSIGNED NOT NULL,
				owner_user_id     BIGINT(20) UNSIGNED NOT NULL,
				tenant_user_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				tenant_email      VARCHAR(190) NOT NULL,
				reviewer_type     VARCHAR(20) NOT NULL COMMENT 'tenant or owner',
				status            VARCHAR(20) NOT NULL DEFAULT 'pending',
				due_at            DATETIME DEFAULT NULL,
				sent_at           DATETIME DEFAULT NULL,
				completed_at      DATETIME DEFAULT NULL,
				created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_lease_reviewer (lease_id, reviewer_type),
				KEY lease_id (lease_id),
				KEY accommodation_id (accommodation_id),
				KEY owner_user_id (owner_user_id),
				KEY tenant_user_id (tenant_user_id),
				KEY tenant_email (tenant_email),
				KEY status (status),
				KEY reviewer_type (reviewer_type)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_reviews (
				id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				review_group_id   BIGINT(20) UNSIGNED NOT NULL,
				lease_id          BIGINT(20) UNSIGNED NOT NULL,
				accommodation_id  BIGINT(20) UNSIGNED NOT NULL,
				owner_user_id     BIGINT(20) UNSIGNED NOT NULL,
				tenant_user_id    BIGINT(20) UNSIGNED DEFAULT NULL,
				tenant_email      VARCHAR(190) NOT NULL,
				review_direction  VARCHAR(30) NOT NULL,
				stars             TINYINT(2) UNSIGNED NOT NULL DEFAULT 0,
				comment_text      TEXT DEFAULT NULL,
				status            VARCHAR(20) NOT NULL DEFAULT 'pending',
				submitted_at      DATETIME DEFAULT NULL,
				created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_review_direction (lease_id, review_direction),
				KEY review_group_id (review_group_id),
				KEY lease_id (lease_id),
				KEY accommodation_id (accommodation_id),
				KEY owner_user_id (owner_user_id),
				KEY tenant_user_id (tenant_user_id),
				KEY tenant_email (tenant_email),
				KEY review_direction (review_direction),
				KEY status (status),
				KEY stars (stars)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_review_tokens (
				id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				review_group_id   BIGINT(20) UNSIGNED NOT NULL,
				selector          VARCHAR(64) NOT NULL,
				token_hash        VARCHAR(255) NOT NULL,
				expires_at        DATETIME NOT NULL,
				used_at           DATETIME DEFAULT NULL,
				attempts          SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
				max_attempts      SMALLINT(5) UNSIGNED NOT NULL DEFAULT 5,
				status            VARCHAR(20) NOT NULL DEFAULT 'active',
				sent_at           DATETIME DEFAULT NULL,
				created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY selector (selector),
				KEY review_group_id (review_group_id),
				KEY status (status),
				KEY expires_at (expires_at)
			) $charset_collate;",

			// ── Facturación Electrónica SRI ──────────────────────────────────

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_emission_points (
				id                       BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				owner_id                 BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = admin/global, otherwise wp_users.ID del owner',
				codigo_establecimiento   CHAR(3) NOT NULL DEFAULT '001',
				codigo_punto_emision     CHAR(3) NOT NULL DEFAULT '001',
				descripcion              VARCHAR(255) DEFAULT NULL,
				activo                   TINYINT(1) NOT NULL DEFAULT 1,
				secuencial_actual        BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_punto_owner (owner_id, codigo_establecimiento, codigo_punto_emision),
				KEY idx_owner_active (owner_id, activo)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_electronic_invoices (
				id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				lease_id             BIGINT(20) UNSIGNED DEFAULT NULL,
				cleaning_request_id  BIGINT(20) UNSIGNED DEFAULT NULL,
				billing_period       CHAR(7) DEFAULT NULL COMMENT 'YYYY-MM del periodo facturado',
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
				UNIQUE KEY uniq_lease_period (lease_id, billing_period),
				KEY lease_id (lease_id),
				KEY billing_period (billing_period),
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

			// ── Integraciones OTA (Booking.com, Airbnb) ──────────────────────

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_ota_credentials (
				id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				owner_id           BIGINT(20) UNSIGNED NOT NULL,
				ota_platform       VARCHAR(20) NOT NULL COMMENT 'booking, airbnb, etc',
				api_key_encrypted  VARCHAR(255) NOT NULL COMMENT 'Encrypted with Sodium',
				account_identifier VARCHAR(100) NOT NULL COMMENT 'Partner ID, account ID, etc',
				connected          TINYINT(1) NOT NULL DEFAULT 0,
				last_verified      DATETIME DEFAULT NULL,
				status             VARCHAR(20) NOT NULL DEFAULT 'inactive',
				created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_owner_platform (owner_id, ota_platform),
				KEY ota_platform (ota_platform),
				KEY owner_id (owner_id)
			) $charset_collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_otas_sync_log (
				id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				accommodation_id     BIGINT(20) UNSIGNED NOT NULL,
				ota_source           VARCHAR(20) NOT NULL COMMENT 'booking, airbnb',
				sync_type            VARCHAR(50) NOT NULL DEFAULT 'availability' COMMENT 'availability, full, manual',
				remote_property_id   VARCHAR(100) NOT NULL,
				status               VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, success, failed',
				local_was_occupied   TINYINT(1) DEFAULT NULL,
				remote_is_occupied   TINYINT(1) DEFAULT NULL,
				remote_booked_dates  JSON DEFAULT NULL COMMENT 'Array of booked date ranges',
				error_message        TEXT DEFAULT NULL,
				request_payload      LONGTEXT DEFAULT NULL,
				response_payload     LONGTEXT DEFAULT NULL,
				created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY accommodation_id (accommodation_id),
				KEY ota_source (ota_source),
				KEY status (status),
				KEY created_at (created_at)
			) $charset_collate;",

			// ── Dispersión de fondos: transferencias registradas al propietario ──
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_owner_transfers (
				id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				owner_id          BIGINT(20) UNSIGNED NOT NULL,
				period            CHAR(7) NOT NULL COMMENT 'YYYY-MM',
				amount            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
				reference         VARCHAR(190) DEFAULT NULL,
				notes             TEXT DEFAULT NULL,
				transferred_by    BIGINT(20) UNSIGNED DEFAULT NULL,
				transferred_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_owner_period (owner_id, period),
				KEY owner_id (owner_id),
				KEY period (period)
			) $charset_collate;",

			// ── Idempotency keys (anti-duplication guard) ────────────────────
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_idempotency_keys (
				id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				scope             VARCHAR(120) NOT NULL,
				idempotency_key   VARCHAR(128) NOT NULL,
				user_id           BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				request_hash      CHAR(64) NOT NULL DEFAULT '',
				status            VARCHAR(20) NOT NULL DEFAULT 'in_progress',
				response_body     LONGTEXT DEFAULT NULL,
				resource_id       VARCHAR(64) DEFAULT NULL,
				locked_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				completed_at      DATETIME DEFAULT NULL,
				expires_at        DATETIME NOT NULL,
				created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_scope_key (scope, idempotency_key),
				KEY idx_expires_at (expires_at),
				KEY idx_status (status)
			) $charset_collate;",
		);
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		$review_groups_table = $wpdb->prefix . 'af_review_groups';
		$review_rows_table   = $wpdb->prefix . 'af_reviews';

		$review_groups_tenant_user_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$review_groups_table,
				'tenant_user_id'
			)
		);
		if ( ! (int) $review_groups_tenant_user_col ) {
			$wpdb->query( "ALTER TABLE {$review_groups_table} ADD COLUMN tenant_user_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER owner_user_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$review_groups_tenant_user_idx = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND INDEX_NAME = %s",
				DB_NAME,
				$review_groups_table,
				'tenant_user_id'
			)
		);
		if ( ! (int) $review_groups_tenant_user_idx ) {
			$wpdb->query( "ALTER TABLE {$review_groups_table} ADD KEY tenant_user_id (tenant_user_id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$review_rows_tenant_user_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$review_rows_table,
				'tenant_user_id'
			)
		);
		if ( ! (int) $review_rows_tenant_user_col ) {
			$wpdb->query( "ALTER TABLE {$review_rows_table} ADD COLUMN tenant_user_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER owner_user_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$review_rows_tenant_user_idx = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND INDEX_NAME = %s",
				DB_NAME,
				$review_rows_table,
				'tenant_user_id'
			)
		);
		if ( ! (int) $review_rows_tenant_user_idx ) {
			$wpdb->query( "ALTER TABLE {$review_rows_table} ADD KEY tenant_user_id (tenant_user_id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$wpdb->query(
			"UPDATE {$review_groups_table} g
			 LEFT JOIN {$wpdb->prefix}af_guests gu ON gu.email = g.tenant_email
			 SET g.tenant_user_id = gu.user_id
			 WHERE (g.tenant_user_id IS NULL OR g.tenant_user_id = 0)
			   AND gu.user_id IS NOT NULL
			   AND gu.user_id > 0"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$wpdb->query(
			"UPDATE {$review_rows_table} r
			 LEFT JOIN {$wpdb->prefix}af_guests gu ON gu.email = r.tenant_email
			 SET r.tenant_user_id = gu.user_id
			 WHERE (r.tenant_user_id IS NULL OR r.tenant_user_id = 0)
			   AND gu.user_id IS NOT NULL
			   AND gu.user_id > 0"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$review_rows_criteria_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s",
				DB_NAME,
				$review_rows_table,
				'criteria_scores'
			)
		);
		if ( ! (int) $review_rows_criteria_col ) {
			$wpdb->query( "ALTER TABLE {$review_rows_table} ADD COLUMN criteria_scores TEXT DEFAULT NULL AFTER comment_text" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Estado de verificacion documental del inquilino.
		$guests_table = $wpdb->prefix . 'af_guests';
		$guest_doc_columns = array(
			'doc_status'      => "ALTER TABLE {$guests_table} ADD COLUMN doc_status VARCHAR(20) NOT NULL DEFAULT 'pendiente'",
			'doc_verified_by' => "ALTER TABLE {$guests_table} ADD COLUMN doc_verified_by BIGINT(20) UNSIGNED DEFAULT NULL",
			'doc_verified_at' => "ALTER TABLE {$guests_table} ADD COLUMN doc_verified_at DATETIME DEFAULT NULL",
			'doc_notes'       => "ALTER TABLE {$guests_table} ADD COLUMN doc_notes TEXT DEFAULT NULL",
			'identity_match_status' => "ALTER TABLE {$guests_table} ADD COLUMN identity_match_status VARCHAR(20) NOT NULL DEFAULT 'not_checked'",
			'nationality'     => "ALTER TABLE {$guests_table} ADD COLUMN nationality VARCHAR(100) DEFAULT NULL",
			'birth_city'      => "ALTER TABLE {$guests_table} ADD COLUMN birth_city VARCHAR(150) DEFAULT NULL",
		);

		foreach ( $guest_doc_columns as $column_name => $alter_sql ) {
			$column_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = %s
					   AND TABLE_NAME = %s
					   AND COLUMN_NAME = %s",
					DB_NAME,
					$guests_table,
					$column_name
				)
			);
			if ( ! (int) $column_exists ) {
				$wpdb->query( $alter_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		// Mantenimiento e incidencias: extiende las solicitudes de limpieza.
		$cleaning_table   = $wpdb->prefix . 'af_cleaning_requests';
		$cleaning_columns = array(
			'request_type' => "ALTER TABLE {$cleaning_table} ADD COLUMN request_type VARCHAR(30) NOT NULL DEFAULT 'limpieza'",
			'priority'     => "ALTER TABLE {$cleaning_table} ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'media'",
			'cost'         => "ALTER TABLE {$cleaning_table} ADD COLUMN cost DECIMAL(12,2) NOT NULL DEFAULT 0.00",
			'lease_id'     => "ALTER TABLE {$cleaning_table} ADD COLUMN lease_id BIGINT(20) UNSIGNED DEFAULT NULL",
			'reported_by'  => "ALTER TABLE {$cleaning_table} ADD COLUMN reported_by VARCHAR(30) NOT NULL DEFAULT 'operador'",
			'unit_id'      => "ALTER TABLE {$cleaning_table} ADD COLUMN unit_id BIGINT(20) UNSIGNED DEFAULT NULL",
		);

		foreach ( $cleaning_columns as $column_name => $alter_sql ) {
			$column_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = %s
					   AND TABLE_NAME = %s
					   AND COLUMN_NAME = %s",
					DB_NAME,
					$cleaning_table,
					$column_name
				)
			);
			if ( ! (int) $column_exists ) {
				$wpdb->query( $alter_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		// PMS legal and deposit operations added without changing the lease lifecycle.
		$leases_table = $wpdb->prefix . 'af_leases';
		$lease_operation_columns = array(
			'template_attachment_id'   => "ALTER TABLE {$leases_table} ADD COLUMN template_attachment_id BIGINT(20) UNSIGNED DEFAULT NULL",
			'deposit_amount'             => "ALTER TABLE {$leases_table} ADD COLUMN deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00",
			'deposit_damage_deduction'   => "ALTER TABLE {$leases_table} ADD COLUMN deposit_damage_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00",
			'deposit_service_deduction'  => "ALTER TABLE {$leases_table} ADD COLUMN deposit_service_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00",
			'deposit_refund_amount'      => "ALTER TABLE {$leases_table} ADD COLUMN deposit_refund_amount DECIMAL(10,2) DEFAULT NULL",
			'deposit_settlement_status'  => "ALTER TABLE {$leases_table} ADD COLUMN deposit_settlement_status VARCHAR(20) NOT NULL DEFAULT 'pendiente'",
			'deposit_settlement_notes'   => "ALTER TABLE {$leases_table} ADD COLUMN deposit_settlement_notes TEXT DEFAULT NULL",
			'deposit_settled_at'         => "ALTER TABLE {$leases_table} ADD COLUMN deposit_settled_at DATETIME DEFAULT NULL",
			'legal_status'               => "ALTER TABLE {$leases_table} ADD COLUMN legal_status VARCHAR(30) NOT NULL DEFAULT 'pendiente'",
			'legal_notes'                => "ALTER TABLE {$leases_table} ADD COLUMN legal_notes TEXT DEFAULT NULL",
			'legal_updated_at'           => "ALTER TABLE {$leases_table} ADD COLUMN legal_updated_at DATETIME DEFAULT NULL",
			'payment_due_day'            => "ALTER TABLE {$leases_table} ADD COLUMN payment_due_day TINYINT UNSIGNED DEFAULT NULL COMMENT 'Dia limite de pago mensual (1-28)'",
		);
		foreach ( $lease_operation_columns as $column_name => $alter_sql ) {
			$column_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s", DB_NAME, $leases_table, $column_name ) );
			if ( ! (int) $column_exists ) {
				$wpdb->query( $alter_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
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

		// Normalize legacy owner contact statuses to a single semantic model.
		$wpdb->query(
			"UPDATE {$owner_contacts_table}
			 SET status = 'active'
			 WHERE status = 'read'"
		);
		$wpdb->query(
			"UPDATE {$owner_contacts_table}
			 SET status = 'inactive'
			 WHERE status = 'unread' OR status IS NULL OR status = ''"
		);

		// Seed a default emission point if none exists yet.
		$emission_table = $wpdb->prefix . 'af_emission_points';

		// ── Multi-tenant upgrade for af_emission_points ────────────────────────
		// Ensure the owner_id column exists on installs created before v2026-07.
		$owner_id_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'owner_id'",
				DB_NAME,
				$emission_table
			)
		);
		if ( ! (int) $owner_id_col ) {
			$wpdb->query( "ALTER TABLE {$emission_table} ADD COLUMN owner_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER id" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Replace the legacy unique key (estab, pto_emi) with the owner-aware one so
		// multiple owners can share the same establishment / emission-point codes.
		$legacy_unique = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_punto'",
				DB_NAME,
				$emission_table
			)
		);
		if ( (int) $legacy_unique > 0 ) {
			$wpdb->query( "ALTER TABLE {$emission_table} DROP INDEX uniq_punto" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$owner_unique = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_punto_owner'",
				DB_NAME,
				$emission_table
			)
		);
		if ( ! (int) $owner_unique ) {
			$wpdb->query( "ALTER TABLE {$emission_table} ADD UNIQUE KEY uniq_punto_owner (owner_id, codigo_establecimiento, codigo_punto_emision)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$owner_active_idx = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_owner_active'",
				DB_NAME,
				$emission_table
			)
		);
		if ( ! (int) $owner_active_idx ) {
			$wpdb->query( "ALTER TABLE {$emission_table} ADD KEY idx_owner_active (owner_id, activo)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

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

		// Performance indexes on wp_postmeta (conditional – safe to re-run).
		self::maybe_add_performance_indexes();

		flush_rewrite_rules();
	}

	/**
	 * Creates performance-critical indexes on wp_postmeta for accommodation
	 * meta key lookups. Each index is guarded to avoid duplicate-key errors.
	 */
	private static function maybe_add_performance_indexes() {
		global $wpdb;

		$postmeta_table = $wpdb->postmeta;
		$leases_table   = $wpdb->prefix . 'af_leases';

		// Ensure deleted_at column exists on af_leases (added after initial schema).
		$col_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'deleted_at'",
				DB_NAME,
				$leases_table
			)
		);
		if ( ! $col_exists ) {
			$wpdb->query( "ALTER TABLE {$leases_table} ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER document_url" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$indexes = array(
			array(
				'table' => $postmeta_table,
				'name'  => 'idx_af_meta_key_value',
				'sql'   => "ALTER TABLE {$postmeta_table} ADD INDEX idx_af_meta_key_value (meta_key(64), meta_value(100))",
			),
			array(
				'table' => $postmeta_table,
				'name'  => 'idx_af_meta_key_post',
				'sql'   => "ALTER TABLE {$postmeta_table} ADD INDEX idx_af_meta_key_post (meta_key(64), post_id)",
			),
			array(
				'table' => $leases_table,
				'name'  => 'idx_af_leases_status_created',
				'sql'   => "ALTER TABLE {$leases_table} ADD INDEX idx_af_leases_status_created (status, created_at DESC)",
			),
		);

		foreach ( $indexes as $index ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
					 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
					DB_NAME,
					$index['table'],
					$index['name']
				)
			);

			if ( ! $exists ) {
				$wpdb->query( $index['sql'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'af_sri_retry_cron' );
			wp_clear_scheduled_hook( 'af_process_ai_queue' );
			wp_clear_scheduled_hook( 'af_review_dispatch_cron' );
			wp_clear_scheduled_hook( 'af_guest_reminders_cron' );
			wp_clear_scheduled_hook( 'af_admin_profile_reminders_cron' );
		}
		flush_rewrite_rules();
	}
}
