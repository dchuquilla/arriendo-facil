<?php
/**
 * Operational alerts for the Arriendo Fácil admin experience.
 *
 * Stores per-account alerts in the shared {prefix}af_alerts table, renders
 * them through the top-bar bell + a dedicated "Centro de alertas" page, and
 * sends the pending ones by email as daily reminders (or on demand).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles alert storage, generation, AJAX endpoints, the daily email digest
 * and per-account configuration.
 */
class Arriendo_Facil_Alerts {

	/**
	 * Action name used for every alerts AJAX request.
	 *
	 * @var string
	 */
	const NONCE = 'af_alerts_nonce';

	/**
	 * Days ahead a lease ending is flagged as "por vencer".
	 *
	 * @var int
	 */
	const LEASE_WINDOW_DAYS = 60;

	/**
	 * Minimum seconds between operational alert generation passes.
	 *
	 * @var int
	 */
	const GENERATION_THROTTLE = HOUR_IN_SECONDS;

	/**
	 * Hook everything up.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_alerts_get', array( $this, 'ajax_get' ) );
		add_action( 'wp_ajax_af_alerts_mark_read', array( $this, 'ajax_mark_read' ) );
		add_action( 'wp_ajax_af_alerts_mark_all_read', array( $this, 'ajax_mark_all_read' ) );
		add_action( 'wp_ajax_af_alerts_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_af_alerts_send_reminder', array( $this, 'ajax_send_reminder' ) );

		// Daily digest of pending alerts (registered in arriendo-facil.php).
		add_action( 'af_alerts_email_cron', array( $this, 'dispatch_alert_emails' ) );

		// Auto-configure the account + (re)generate operational alerts once per
		// throttle window on the operator's own admin screen.
		add_action( 'admin_init', array( $this, 'setup_current_account_and_generate' ), 20 );
	}

	/**
	 * The alerts table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'af_alerts';
	}

	/**
	 * Creates the alerts table on demand. Safe to call on any request; the
	 * activator also creates it during activation and schema upgrades.
	 *
	 * @return bool True when the table exists.
	 */
	public static function ensure_schema() {
		static $ensured = false;

		if ( $ensured ) {
			return true;
		}

		global $wpdb;

		$table           = self::table();
		$existing        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $existing === $table ) {
			$ensured = true;
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id     BIGINT(20) UNSIGNED NOT NULL,
				type        VARCHAR(40) NOT NULL DEFAULT 'operational' COMMENT 'lease_ending, lease_expired, charge_overdue, reading_missing, system, example',
				severity    VARCHAR(10) NOT NULL DEFAULT 'info' COMMENT 'info, warning, danger',
				title       VARCHAR(190) NOT NULL,
				message     TEXT DEFAULT NULL,
				url         VARCHAR(500) DEFAULT NULL,
				source_key  VARCHAR(190) DEFAULT NULL COMMENT 'Clave de idempotencia por usuario',
				is_read     TINYINT(1) NOT NULL DEFAULT 0,
				email_sent  TINYINT(1) NOT NULL DEFAULT 0,
				created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_alert_source (user_id, source_key),
				KEY user_read_created (user_id, is_read, created_at)
			) {$wpdb->get_charset_collate()};"
		);

		$ensured = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

		return $ensured;
	}

	/**
	 * Severity metadata used across the bell, the center page and the email.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function severity_meta() {
		return array(
			'info'    => array(
				'label' => __( 'Informativa', 'arriendo-facil' ),
				'icon'  => 'info',
			),
			'warning' => array(
				'label' => __( 'Atención', 'arriendo-facil' ),
				'icon'  => 'circle-alert',
			),
			'danger'  => array(
				'label' => __( 'Crítica', 'arriendo-facil' ),
				'icon'  => 'triangle-alert',
			),
		);
	}

	/**
	 * Inserts an alert for a specific user, deduplicated by source_key.
	 *
	 * @param int    $user_id    WP user ID.
	 * @param string $type       Alert type slug.
	 * @param string $severity   info|warning|danger.
	 * @param string $title      Short title.
	 * @param string $message    Optional longer description.
	 * @param string $url        Optional deep-link URL.
	 * @param string $source_key Idempotency key (must be unique per user).
	 * @return int|false Alert ID on insert, existing ID when duplicated, false on failure.
	 */
	public static function add( $user_id, $type, $severity, $title, $message = '', $url = '', $source_key = '' ) {
		if ( ! self::ensure_schema() || ! absint( $user_id ) ) {
			return false;
		}

		global $wpdb;

		$user_id    = absint( $user_id );
		$source_key = (string) $source_key;
		$existing   = '';

		if ( '' !== $source_key ) {
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::table() . ' WHERE user_id = %d AND source_key = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from the class.
					$user_id,
					$source_key
				)
			);
			if ( $existing > 0 ) {
				return $existing;
			}
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'user_id'     => $user_id,
				'type'        => sanitize_key( $type ),
				'severity'    => in_array( $severity, array( 'info', 'warning', 'danger' ), true ) ? $severity : 'info',
				'title'       => $title,
				'message'     => $message,
				'url'         => $url,
				'source_key'  => $source_key,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Recent unfired alerts for the dropdown.
	 *
	 * @param int  $user_id WP user ID.
	 * @param int  $limit   Max rows (cap at 20).
	 * @param bool $unread  Only unread alerts.
	 * @return array<int, object>
	 */
	public static function get_for_user( $user_id = 0, $limit = 10, $unread = true ) {
		if ( ! self::ensure_schema() ) {
			return array();
		}

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$limit   = max( 1, min( 50, absint( $limit ) ) );

		global $wpdb;

		$where = 'WHERE user_id = %d';
		if ( $unread ) {
			$where .= ' AND is_read = 0';
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " {$where} ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from the class.
				$user_id,
				$limit
			)
		);
	}

	/**
	 * Number of unread alerts for a user.
	 *
	 * @param int $user_id WP user ID.
	 * @return int
	 */
	public static function count_unread( $user_id = 0 ) {
		if ( ! self::ensure_schema() ) {
			return 0;
		}

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id = %d AND is_read = 0', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from the class.
				$user_id
			)
		);
	}

	/**
	 * Marks a single alert as read for its owner.
	 *
	 * @param int $alert_id Alert ID.
	 * @param int $user_id  WP user ID.
	 * @return bool
	 */
	public static function mark_read( $alert_id, $user_id = 0 ) {
		if ( ! self::ensure_schema() ) {
			return false;
		}

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		global $wpdb;

		return (bool) $wpdb->update(
			self::table(),
			array( 'is_read' => 1 ),
			array( 'id' => absint( $alert_id ), 'user_id' => $user_id ),
			array( '%d' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Marks everything as read for a user.
	 *
	 * @param int $user_id WP user ID.
	 * @return bool
	 */
	public static function mark_all_read( $user_id = 0 ) {
		if ( ! self::ensure_schema() ) {
			return false;
		}

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		global $wpdb;

		return (bool) $wpdb->update(
			self::table(),
			array( 'is_read' => 1 ),
			array( 'user_id' => $user_id, 'is_read' => 0 ),
			array( '%d' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Alerts the user is allowed to see, scoped by tenancy.
	 *
	 * @param int $user_id WP user ID.
	 * @return int[]|null Null when unrestricted, otherwise a list of IDs.
	 */
	private static function user_accommodation_scope( $user_id ) {
		if ( ! class_exists( 'Arriendo_Facil_Tenancy' ) ) {
			return null;
		}

		return Arriendo_Facil_Tenancy::accessible_accommodation_ids( $user_id );
	}

	/**
	 * Auto-configures the operator's account (email + system) on first run and
	 * (re)generates operational alerts, throttled to one pass per window.
	 *
	 * @return void
	 */
	public function setup_current_account_and_generate() {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		// First-time account configuration: email + internal system.
		if ( ! get_user_meta( $user_id, '_af_alerts_configured', true ) ) {
			$user      = wp_get_current_user();
			$user_email = is_object( $user ) && $user->user_email ? (string) $user->user_email : '';

			update_user_meta( $user_id, '_af_alerts_email_enabled', 1 );
			update_user_meta( $user_id, '_af_alerts_email_address', $user_email );
			update_user_meta( $user_id, '_af_alerts_configured', time() );

			self::add(
				$user_id,
				'example',
				'info',
				__( 'Tu cuenta está configurada', 'arriendo-facil' ),
				sprintf(
					/* translators: %s: account email */
					__( 'Las alertas operativas llegan al ícono de campana del sistema y, como recordatorio, a %s.', 'arriendo-facil' ),
					$user_email ? $user_email : __( 'tu correo', 'arriendo-facil' )
				),
				admin_url( 'admin.php?page=af-alerts-center' ),
				'account-configured-' . $user_id
			);
		}

		// Generate operational alerts, but only once per throttle window.
		$last_run = (int) get_option( 'af_alerts_generated_at', 0 );
		if ( time() - $last_run < self::GENERATION_THROTTLE ) {
			return;
		}

		update_option( 'af_alerts_generated_at', time(), false );
		self::generate_operational_alerts( $user_id );
	}

	/**
	 * Generates the operational alerts a single user should see.
	 *
	 * @param int $user_id WP user ID.
	 * @return int Number of alerts created.
	 */
	public static function generate_operational_alerts( $user_id = 0 ) {
		if ( ! self::ensure_schema() ) {
			return 0;
		}

		global $wpdb;

		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
		$scope    = self::user_accommodation_scope( $user_id );
		$created  = 0;
		$limit    = 20;

		$scope_sql = '';
		if ( is_array( $scope ) ) {
			if ( empty( $scope ) ) {
				return 0;
			}
			$scope_sql = ' AND l.accommodation_id IN (' . implode( ',', array_map( 'intval', $scope ) ) . ')';
		}

		// 1) Leases active and ending soon (today → +N days).
		$ending = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id AS lease_id, l.end_date, l.accommodation_id,
				        COALESCE(p.post_title, l.accommodation_id) AS accommodation_title
				 FROM {$wpdb->prefix}af_leases l
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 WHERE l.deleted_at IS NULL
				   AND l.status = 'active'
				   AND l.start_date <= end_date{$scope_sql}
				   AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL %d DAY)
				 ORDER BY l.end_date ASC
				 LIMIT 25",
				self::LEASE_WINDOW_DAYS
			)
		);

		foreach ( $ending as $lease ) {
			$days_left = max( 0, (int) floor( ( strtotime( (string) $lease->end_date ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) );
			$url       = admin_url( 'admin.php?page=af-leases' );

			self::add(
				$user_id,
				'lease_ending',
				$days_left <= 30 ? 'warning' : 'info',
				sprintf(
					/* translators: 1: accommodation title, 2: remaining days */
					__( 'Contrato por vencer en %1$s', 'arriendo-facil' ),
					$lease->accommodation_title
				),
				sprintf(
					/* translators: 1: days, 2: end date */
					__( 'Termina en %1$d día(s), el %2$s. Prepara la renovación o la salida del inquilino.', 'arriendo-facil' ),
					$days_left,
					date_i18n( get_option( 'date_format' ), strtotime( (string) $lease->end_date ) )
				),
				$url,
				'lease-ending-' . (int) $lease->lease_id
			);
			$created++;
		}

		// 2) Leases started but already past their end date (still marked active).
		$expired = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id AS lease_id, l.end_date, l.accommodation_id,
				        COALESCE(p.post_title, l.accommodation_id) AS accommodation_title
				 FROM {$wpdb->prefix}af_leases l
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 WHERE l.deleted_at IS NULL
				   AND l.status = 'active'
				   AND l.start_date <= end_date{$scope_sql}
				   AND l.end_date < CURDATE()
				 ORDER BY l.end_date ASC
				 LIMIT 10"
			)
		);

		foreach ( $expired as $lease ) {
			self::add(
				$user_id,
				'lease_expired',
				'danger',
				sprintf(
					/* translators: %s: accommodation title */
					__( 'Contrato vencido en %1$s', 'arriendo-facil' ),
					$lease->accommodation_title
				),
				sprintf(
					/* translators: %s: end date */
					__( 'El contrato venció el %s y sigue marcado como activo. Renueva o formaliza la salida.', 'arriendo-facil' ),
					date_i18n( get_option( 'date_format' ), strtotime( (string) $lease->end_date ) )
				),
				admin_url( 'admin.php?page=af-leases' ),
				'lease-expired-' . (int) $lease->lease_id
			);
			$created++;
		}

		// 3) Outstanding charges past their due date.
		$overdue = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id AS charge_id, c.amount, c.amount_paid, c.due_date, c.period,
				        l.id AS lease_id, COALESCE(p.post_title, l.accommodation_id) AS accommodation_title
				 FROM {$wpdb->prefix}af_charges c
				 INNER JOIN {$wpdb->prefix}af_leases l ON l.id = c.lease_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 WHERE c.status IN ('pending', 'partial')
				   AND c.amount > c.amount_paid
				   AND c.due_date < CURDATE()
				   AND l.deleted_at IS NULL{$scope_sql}
				   AND l.accommodation_id > 0
				 ORDER BY c.due_date ASC
				 LIMIT 20",
				self::LEASE_WINDOW_DAYS
			)
		);

		foreach ( $overdue as $charge ) {
			$due = (float) ( $charge->amount - $charge->amount_paid );
			self::add(
				$user_id,
				'charge_overdue',
				'danger',
				sprintf(
					/* translators: %s: accommodation title */
					__( 'Cargo vencido en %1$s', 'arriendo-facil' ),
					$charge->accommodation_title
				),
				sprintf(
					/* translators: 1: amount, 2: period, 3: due date */
					__( 'Quedan USD %1$s pendientes del período %2$s (venció el %3$s).', 'arriendo-facil' ),
					number_format_i18n( $due, 2 ),
					$charge->period,
					date_i18n( get_option( 'date_format' ), strtotime( (string) $charge->due_date ) )
				),
				admin_url( 'admin.php?page=af-collections&statement_lease=' . (int) $charge->lease_id ),
				'charge-overdue-' . (int) $charge->lease_id
			);
			$created++;
		}

		// 4) Charges due in the next few days (upcoming within 5 days).
		$due_soon = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id AS charge_id, c.amount, c.amount_paid, c.due_date, c.period,
				        l.id AS lease_id, COALESCE(p.post_title, l.accommodation_id) AS accommodation_title
				 FROM {$wpdb->prefix}af_charges c
				 INNER JOIN {$wpdb->prefix}af_leases l ON l.id = c.lease_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 WHERE c.status IN ('pending', 'partial')
				   AND c.amount > c.amount_paid
				   AND c.due_date >= CURDATE()
				   AND c.due_date <= DATE_ADD(CURDATE(), INTERVAL %d DAY)
				   AND l.deleted_at IS NULL{$scope_sql}
				   AND l.accommodation_id > 0
				 ORDER BY c.due_date ASC
				 LIMIT 20",
				(int) apply_filters( 'af_charge_due_soon_days', 5 )
			)
		);

		foreach ( $due_soon as $charge ) {
			$days_left = max( 0, (int) ceil( ( strtotime( (string) $charge->due_date ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) );
			$due       = (float) ( $charge->amount - $charge->amount_paid );
			self::add(
				$user_id,
				'charge_due_soon',
				'warning',
				sprintf(
					/* translators: %s: accommodation title */
					__( 'Cargo por vencer en %1$s', 'arriendo-facil' ),
					$charge->accommodation_title
				),
				sprintf(
					/* translators: 1: amount, 2: period, 3: days, 4: due date */
					__( 'Quedan USD %1$s por cobrar del período %2$s. Vence en %3$d día(s), el %4$s.', 'arriendo-facil' ),
					number_format_i18n( $due, 2 ),
					$charge->period,
					$days_left,
					date_i18n( get_option( 'date_format' ), strtotime( (string) $charge->due_date ) )
				),
				admin_url( 'admin.php?page=af-buildings' ),
				'charge-due-soon-' . (int) $charge->lease_id
			);
			$created++;
		}

		// 5) Meter readings missing for the current period (only for services
		//    the property has read before, to avoid noise on new setups).
		if ( class_exists( 'Arriendo_Facil_Billing_Ledger' ) ) {
			$readings_table = Arriendo_Facil_Billing_Ledger::readings_table();
			$current_period = gmdate( 'Y-m' );

			$missing = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.unit_id, r.accommodation_id, r.service, r.period AS last_period,
					        COALESCE(p.post_title, r.accommodation_id) AS accommodation_title
					 FROM {$readings_table} r
					 LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
					 WHERE NOT EXISTS (
					     SELECT 1 FROM {$readings_table} r2
					     WHERE r2.unit_id = r.unit_id
					       AND r2.accommodation_id = r.accommodation_id
					       AND r2.service = r.service
					       AND r2.period = %s
					 )
					   AND r.period <> %s
					 GROUP BY r.unit_id, r.accommodation_id, r.service
					 ORDER BY r.accommodation_id, r.service
					 LIMIT 25",
					$current_period,
					$current_period
				)
			);

			$service_labels = array(
				'agua' => __( 'agua', 'arriendo-facil' ),
				'luz'  => __( 'luz', 'arriendo-facil' ),
				'gas'  => __( 'gas', 'arriendo-facil' ),
			);

			foreach ( $missing as $reading_row ) {
				$acc_id = (int) $reading_row->accommodation_id;
				if ( is_array( $scope ) && $acc_id > 0 && ! in_array( $acc_id, $scope, true ) ) {
					continue;
				}

				$service_label = isset( $service_labels[ $reading_row->service ] ) ? $service_labels[ $reading_row->service ] : $reading_row->service;

				self::add(
					$user_id,
					'reading_missing',
					'warning',
					sprintf(
						/* translators: 1: service name, 2: accommodation title */
						__( 'Falta la lectura de %1$s de %2$s', 'arriendo-facil' ),
						$service_label,
						$reading_row->accommodation_title
					),
					sprintf(
						/* translators: %s: billing period */
						__( 'No se registró lectura del período %s. Regístrala para poder generar el cargo.', 'arriendo-facil' ),
						$current_period
					),
					admin_url( 'admin.php?page=af-meter-readings' ),
					'reading-missing-' . (int) $reading_row->unit_id . '-' . $acc_id . '-' . sanitize_key( (string) $reading_row->service ) . '-' . $current_period
				);
				$created++;
			}
		}

		if ( $created > $limit ) {
			return $limit;
		}

		return $created;
	}

	/**
	 * Per-account settings snapshot.
	 *
	 * @param int $user_id WP user ID.
	 * @return array<string, mixed>
	 */
	public static function get_settings( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		$user = get_userdata( $user_id );

		return array(
			'email_enabled' => (bool) get_user_meta( $user_id, '_af_alerts_email_enabled', true ),
			'email'         => (string) get_user_meta( $user_id, '_af_alerts_email_address', true ),
			'default_email' => $user instanceof WP_User ? (string) $user->user_email : '',
		);
	}

	/**
	 * Severity rank used to order the digest: the actionable items first.
	 *
	 * @param string $severity Alert severity.
	 * @return int Lower means more urgent.
	 */
	private static function severity_rank( $severity ) {
		$ranks = array(
			'danger'  => 0,
			'warning' => 1,
			'info'    => 2,
		);

		return isset( $ranks[ $severity ] ) ? $ranks[ $severity ] : 3;
	}

	/**
	 * Builds and sends the digest email for a user. Sends at most once per day
	 * unless $force is true (AJAX "Enviar recordatorio ahora").
	 *
	 * @param int  $user_id WP user ID.
	 * @param bool $force   Ignore the daily throttle.
	 * @return array<string, mixed> Result summary with 'sent' => int.
	 */
	public static function dispatch_for_user( $user_id = 0, $force = false ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		$settings = self::get_settings( $user_id );

		if ( ! $settings['email_enabled'] ) {
			return array( 'sent' => 0, 'message' => __( 'Los recordatorios por correo están desactivados para tu cuenta.', 'arriendo-facil' ) );
		}

		$to   = sanitize_email( (string) $settings['email'] );
		$from = $settings['default_email'] ? $settings['default_email'] : (string) $to;

		if ( ! $to ) {
			return array( 'sent' => 0, 'message' => __( 'Tu cuenta no tiene un correo configurado.', 'arriendo-facil' ) );
		}

		$alerts = self::get_for_user( $user_id, 20, true );
		if ( empty( $alerts ) ) {
			return array( 'sent' => 0, 'message' => __( 'No hay alertas pendientes por enviar.', 'arriendo-facil' ) );
		}

		// Lo que exige acción va primero: vencidos (danger), luego atención
		// (warning) y por último lo informativo. A igual severidad se respeta
		// el orden de creación (usort es estable en PHP 8; el índice ata el
		// orden en versiones anteriores).
		$indexed = array();
		foreach ( array_values( $alerts ) as $i => $alert ) {
			$indexed[] = array( 'rank' => self::severity_rank( isset( $alert->severity ) ? $alert->severity : 'info' ), 'i' => $i, 'alert' => $alert );
		}
		usort(
			$indexed,
			static function ( $a, $b ) {
				if ( $a['rank'] === $b['rank'] ) {
					return $a['i'] <=> $b['i'];
				}
				return $a['rank'] <=> $b['rank'];
			}
		);
		$alerts = array_column( $indexed, 'alert' );

		$daily_key = 'af_alerts_sent_at_' . $user_id;
		if ( ! $force && ( (int) get_option( $daily_key, 0 ) > strtotime( 'today' ) ) ) {
			return array( 'sent' => 0, 'message' => __( 'El recordatorio diario ya fue enviado hoy.', 'arriendo-facil' ) );
		}

		$sent = wp_mail( $to, self::email_subject( count( $alerts ) ), self::build_email_html( $alerts, $from ), self::email_headers() );

		if ( $sent ) {
			update_option( $daily_key, time(), false );

			// Flag the emailed alerts so the next pass only sends new ones.
			foreach ( $alerts as $alert ) {
				$wpdb = $GLOBALS['wpdb'];
				$wpdb->update( self::table(), array( 'email_sent' => 1 ), array( 'id' => (int) $alert->id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore
			}
		}

		return array(
			'sent'    => $sent ? count( $alerts ) : 0,
			'emailed' => $to,
			'message' => $sent
				? sprintf(
					/* translators: %d: number of alerts */
					__( 'Recordatorio enviado a %s (%d alertas).', 'arriendo-facil' ),
					$to,
					count( $alerts )
				)
				: __( 'El correo no pudo enviarse. Revisa la configuración SMTP.', 'arriendo-facil' ),
		);
	}

	/**
	 * Cron callback: sends the daily digest to everyone with pending alerts.
	 *
	 * @return void
	 */
	public function dispatch_alert_emails() {
		if ( ! self::ensure_schema() ) {
			return;
		}

		global $wpdb;

		$user_ids = $wpdb->get_col( 'SELECT DISTINCT user_id FROM ' . self::table() . ' WHERE is_read = 0 AND email_sent = 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from the class.

		// Only operators with an enabled email config.
		$enabled = array();
		foreach ( array_map( 'absint', (array) $user_ids ) as $user_id ) {
			if ( $user_id && (bool) get_user_meta( $user_id, '_af_alerts_email_enabled', true ) ) {
				$enabled[] = $user_id;
			}
		}

		foreach ( $enabled as $user_id ) {
			self::dispatch_for_user( $user_id, false );
		}
	}

	/**
	 * Email subject line.
	 *
	 * @param int $count Number of pending alerts.
	 * @return string
	 */
	private static function email_subject( $count ) {
		return sprintf(
			/* translators: %d: number of pending alerts */
			_n( '[Arriendo Fácil] Tienes %d alerta operativa pendiente', '[Arriendo Fácil] Tienes %d alertas operativas pendientes', $count, 'arriendo-facil' ),
			$count
		);
	}

	/**
	 * Email headers (HTML + From).
	 *
	 * @return array<string, string>
	 */
	private static function email_headers() {
		$site_name = get_bloginfo( 'name' );
		$from_name = $site_name ? $site_name : 'Arriendo Fácil';
		$from      = get_option( 'admin_email' ) ? get_option( 'admin_email' ) : 'noreply@' . wp_parse_url( home_url(), PHP_URL_HOST );

		return array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from ),
		);
	}

	/**
	 * Renders the digest email body.
	 *
	 * @param array<int, object> $alerts Alerts to include.
	 * @param string             $to     Destination address (unused placeholder for footer).
	 * @return string
	 */
	private static function build_email_html( $alerts, $to = '' ) {
		$html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1f2d3d;max-width:600px;margin:0 auto;">';
		$html .= '<h2 style="margin:0 0 6px;">Arriendo Fácil</h2>';
		$html .= '<p style="margin:0 0 18px;color:#52606d;">';
		$html .= esc_html(
			sprintf(
				/* translators: %d: number of pending alerts */
				_n( 'Tienes %d alerta operativa pendiente de revisión.', 'Tienes %d alertas operativas pendientes de revisión.', count( $alerts ), 'arriendo-facil' ),
				count( $alerts )
			)
		);
		$html .= '</p>';

		$html .= '<div style="border:1px solid #e1e7ee;border-radius:8px;overflow:hidden;">';
		foreach ( (array) $alerts as $alert ) {
			$severity = isset( $alert->severity ) ? $alert->severity : 'info';
			$color    = 'danger' === $severity ? '#b91c1c' : ( 'warning' === $severity ? '#b45309' : '#2563eb' );
			$icon     = 'danger' === $severity ? '&#9888;' : ( 'warning' === $severity ? '&#9888;' : '&#9432;' );

			$html .= '<div style="padding:12px 14px;border-bottom:1px solid #eef2f6;">';
			$html .= '<div style="font-weight:700;color:' . $color . ';">' . $icon . ' ' . esc_html( (string) ( $alert->title ?? '' ) ) . '</div>';
			$html .= '<div style="margin-top:4px;font-size:13px;color:#3c4856;line-height:1.5;">' . esc_html( (string) ( $alert->message ?? '' ) ) . '</div>';
			if ( ! empty( $alert->url ) ) {
				$html .= '<a href="' . esc_url( $alert->url ) . '" style="display:inline-block;margin-top:8px;font-size:13px;font-weight:600;color:#2563eb;text-decoration:none;">' . esc_html__( 'Ver en el sistema', 'arriendo-facil' ) . ' &rarr;</a>';
			}
			$html .= '</div>';
		}
		$html .= '</div>';

		$html .= '<p style="margin:18px 0 0;font-size:12px;color:#7a8694;">';
		$html .= esc_html__( 'Este es un recordatorio automático del sistema de alertas de Arriendo Fácil.', 'arriendo-facil' );
		$html .= '</p></div>';

		return $html;
	}

	/**
	 * Shared AJAX guard (nonce + capability).
	 *
	 * @return bool
	 */
	private function verify_request() {
		check_ajax_referer( self::NONCE, 'nonce' );

		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET: alerts payload for the bell dropdown.
	 *
	 * @return void
	 */
	public function ajax_get() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'arriendo-facil' ) ), 403 );
			return;
		}

		$user_id = get_current_user_id();
		$limit   = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 6; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guarded by verify_request()
		$alerts  = self::get_for_user( $user_id, $limit, true );

		$items = array();
		foreach ( $alerts as $alert ) {
			$items[] = array(
				'id'        => (int) $alert->id,
				'severity'  => (string) $alert->severity,
				'icon'      => isset( self::severity_meta()[ $alert->severity ]['icon'] ) ? self::severity_meta()[ $alert->severity ]['icon'] : 'info',
				'title'     => (string) $alert->title,
				'message'   => (string) ( $alert->message ?? '' ),
				'url'       => ! empty( $alert->url ) ? esc_url_raw( (string) $alert->url ) : '',
				'created'   => mysql2date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), (string) $alert->created_at ),
				'readable'  => (bool) $alert->is_read,
			);
		}

		wp_send_json_success(
			array(
				'unread' => self::count_unread( $user_id ),
				'items'  => $items,
			)
		);
	}

	/**
	 * Marks a single alert as read.
	 *
	 * @return void
	 */
	public function ajax_mark_read() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'arriendo-facil' ) ), 403 );
			return;
		}

		$alert_id = isset( $_POST['alert_id'] ) ? absint( wp_unslash( $_POST['alert_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guarded by verify_request()
		if ( ! $alert_id ) {
			wp_send_json_error( array( 'message' => __( 'Alerta no válida.', 'arriendo-facil' ) ) );
			return;
		}

		self::mark_read( $alert_id, get_current_user_id() );

		wp_send_json_success( array( 'unread' => self::count_unread( get_current_user_id() ) ) );
	}

	/**
	 * Marks every alert as read.
	 *
	 * @return void
	 */
	public function ajax_mark_all_read() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'arriendo-facil' ) ), 403 );
			return;
		}

		self::mark_all_read( get_current_user_id() );

		wp_send_json_success( array( 'unread' => 0 ) );
	}

	/**
	 * Saves per-account alert settings (email + toggle).
	 *
	 * @return void
	 */
	public function ajax_save_settings() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'arriendo-facil' ) ), 403 );
			return;
		}

		$user_id   = get_current_user_id();
		$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guarded by verify_request()
		$enabled   = isset( $_POST['enabled'] ) ? ( '1' === (string) wp_unslash( $_POST['enabled'] ) || 'true' === (string) wp_unslash( $_POST['enabled'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guarded by verify_request()

		if ( $email ) {
			update_user_meta( $user_id, '_af_alerts_email_address', $email );
		}
		update_user_meta( $user_id, '_af_alerts_email_enabled', $enabled ? 1 : 0 );

		wp_send_json_success(
			array(
				'message' => __( 'Preferencias guardadas.', 'arriendo-facil' ),
				'settings' => self::get_settings( $user_id ),
			)
		);
	}

	/**
	 * Sends the pending alerts to the operator's configured email right away.
	 *
	 * @return void
	 */
	public function ajax_send_reminder() {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'arriendo-facil' ) ), 403 );
			return;
		}

		$result = self::dispatch_for_user( get_current_user_id(), true );

		if ( $result['sent'] > 0 ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}
}