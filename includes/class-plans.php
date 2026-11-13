<?php
/**
 * Planes de suscripción (Free / Básico / Profesional).
 *
 * El plan vive en el usermeta `af_plan` del gestor de propiedades. Una cuenta
 * sin ese campo (licencias anteriores a los planes) cuenta como Profesional;
 * las altas nuevas nacen en Free. El administrador de la plataforma activa los
 * planes a mano; el gestor puede solicitar un cambio o cancelar (vuelve a Free).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Plans {

	const META_PLAN    = 'af_plan';
	const META_SINCE   = 'af_plan_since';
	const META_REQUEST = 'af_plan_request';
	const META_LOCKED  = '_af_plan_locked';
	const META_HISTORY = 'af_plan_history';
	const OPT_MIGRATED = 'af_plans_legacy_migrated';
	const PAGE_SLUG    = 'af-plan';
	const NONCE        = 'af_plans';

	/**
	 * Admin screens (page slug => feature) that need a plan feature.
	 */
	const GATED_PAGES = array(
		'af-catalog'          => 'catalog',
		'af-billing'          => 'billing',
		'af-billing-settings' => 'billing',
		'af-maintenance'      => 'maintenance',
	);

	/**
	 * AJAX actions (action => feature) that need a plan feature.
	 */
	const GATED_AJAX = array(
		'af_catalog_assign_group'     => 'catalog',
		'af_catalog_bulk_action'      => 'catalog',
		'af_catalog_group_delete'     => 'catalog',
		'af_catalog_group_save'       => 'catalog',
		'af_catalog_share_stats'      => 'catalog',
		'af_create_maintenance'       => 'maintenance',
		'af_update_maintenance'       => 'maintenance',
		'af_update_maintenance_status' => 'maintenance',
		'af_billing_lease_search'     => 'billing',
		'af_billing_pending'          => 'billing',
		'af_get_invoices_async'       => 'billing',
		'af_invoice_mail_default'     => 'billing',
		'af_issue_charges_invoice'    => 'billing',
		'af_issue_invoice'            => 'billing',
		'af_preview_charges_invoice'  => 'billing',
		'af_preview_invoice'          => 'billing',
		'af_retry_invoice'            => 'billing',
		'af_sri_check_connection'     => 'billing',
		'af_sri_log_view'             => 'billing',
		'af_sri_ruc_lookup'           => 'billing',
	);

	/**
	 * Action prefixes that also belong to a feature, so a new endpoint of a
	 * locked module is blocked without having to be added to GATED_AJAX.
	 */
	const GATED_PREFIXES = array(
		'af_catalog_'            => 'catalog',
		'af_create_maintenance'  => 'maintenance',
		'af_update_maintenance'  => 'maintenance',
		'af_maintenance_'        => 'maintenance',
		'af_sri_'                => 'billing',
		'af_billing_'            => 'billing',
		'af_issue_'              => 'billing',
		'af_preview_'            => 'billing',
		'af_retry_invoice'       => 'billing',
	);

	/** @var array<int,string> Resolved plan per user for this request. */
	private static $plan_cache = array();

	/** @var array<int,string> Advisory locks held by this request (user ID => lock name). */
	private static $locks = array();

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 11 );
		add_action( 'admin_init', array( $this, 'guard' ), 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 30 );
		add_action( 'admin_footer', array( $this, 'render_modal' ) );
		add_filter( 'parent_file', array( $this, 'keep_parent_menu_open' ) );
		add_filter( 'submenu_file', array( $this, 'keep_submenu_selected' ) );

		add_action( 'wp_ajax_af_plan_request', array( $this, 'ajax_request' ) );
		add_action( 'wp_ajax_af_plan_cancel_request', array( $this, 'ajax_cancel_request' ) );
		add_action( 'wp_ajax_af_plan_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_af_plan_set', array( $this, 'ajax_set' ) );

		add_action( 'init', array( $this, 'maybe_migrate_legacy' ), 1 );
		add_filter( 'map_meta_cap', array( $this, 'deny_locked_property_caps' ), 10, 4 );
		add_filter( 'wp_insert_post_empty_content', array( $this, 'block_over_limit_insert' ), 10, 2 );
		add_filter( 'rest_pre_insert_accommodation', array( $this, 'block_rest_create' ), 10, 2 );

		// Después de Property_Trash_Relations (prioridad 10): primero se separan o devuelven
		// las relaciones del inmueble y luego se recalcula qué inmuebles están activos.
		add_action( 'trashed_post', array( $this, 'on_property_trashed' ), 20 );
		add_action( 'untrashed_post', array( $this, 'on_property_untrashed' ), 20 );
	}

	/* ------------------------------------------------------------------
	 * Catálogo de planes
	 * ------------------------------------------------------------------ */

	/**
	 * Plan definitions, ordered from the smallest to the largest.
	 *
	 * `limits` null = unlimited; `services` null = every service.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions() {
		return (array) apply_filters(
			'af_plan_definitions',
			array(
				'free'  => array(
					'label'    => __( 'Free', 'arriendo-facil' ),
					'tagline'  => __( 'Para empezar a probar', 'arriendo-facil' ),
					'price'    => 0,
					'limits'   => array(
						'properties' => 4,
						'guests'     => 4,
						'leases'     => 4,
					),
					'features' => array(
						'maintenance' => false,
						'catalog'     => false,
						'billing'     => false,
					),
					'services' => array( 'agua', 'luz' ),
				),
				'basic' => array(
					'label'    => __( 'Básico', 'arriendo-facil' ),
					'tagline'  => __( 'Para administrar con orden', 'arriendo-facil' ),
					'price'    => 10,
					'limits'   => array(
						'properties' => 15,
						'guests'     => 15,
						'leases'     => 15,
					),
					'features' => array(
						'maintenance' => true,
						'catalog'     => false,
						'billing'     => false,
					),
					'services' => null,
				),
				'pro'   => array(
					'label'    => __( 'Profesional', 'arriendo-facil' ),
					'tagline'  => __( 'Para crecer sin límites', 'arriendo-facil' ),
					'price'    => 20,
					'limits'   => array(
						'properties' => null,
						'guests'     => null,
						'leases'     => null,
					),
					'features' => array(
						'maintenance' => true,
						'catalog'     => true,
						'billing'     => true,
					),
					'services' => null,
				),
			)
		);
	}

	/**
	 * Labels of the gated features.
	 *
	 * @return array<string,string>
	 */
	public static function feature_labels() {
		return array(
			'maintenance' => __( 'Mantenimiento', 'arriendo-facil' ),
			'catalog'     => __( 'Catálogo', 'arriendo-facil' ),
			'billing'     => __( 'Facturación electrónica', 'arriendo-facil' ),
		);
	}

	/**
	 * Labels of the limited resources (plural, lowercase).
	 *
	 * @return array<string,string>
	 */
	public static function limit_labels() {
		return array(
			'properties' => __( 'inmuebles', 'arriendo-facil' ),
			'guests'     => __( 'inquilinos', 'arriendo-facil' ),
			'leases'     => __( 'contratos', 'arriendo-facil' ),
		);
	}

	/**
	 * Normalizes a plan key.
	 *
	 * @param string $plan Plan key.
	 * @return string Empty when unknown.
	 */
	public static function normalize( $plan ) {
		$plan = sanitize_key( (string) $plan );

		return isset( self::definitions()[ $plan ] ) ? $plan : '';
	}

	/**
	 * URL of the plan screen.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
	}

	/* ------------------------------------------------------------------
	 * Plan de un usuario
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the plan rules apply to the user (licensed property managers only).
	 *
	 * @param int $user_id User ID. Defaults to the current user.
	 * @return bool
	 */
	public static function applies_to( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		return $user_id > 0
			&& ! Arriendo_Facil_Tenancy::can_manage_all( $user_id )
			&& Arriendo_Facil_Tenancy::is_property_manager( $user_id );
	}

	public static function get_plan( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! isset( self::$plan_cache[ $user_id ] ) ) {
			self::$plan_cache[ $user_id ] = self::resolve_plan( $user_id );
		}

		return self::$plan_cache[ $user_id ];
	}

	/**
	 * Drops the per-request plan memo of a user (after a change).
	 *
	 * @param int $user_id User ID.
	 */
	public static function forget( $user_id ) {
		unset( self::$plan_cache[ absint( $user_id ) ] );
	}

	/**
	 * Reads the plan from user meta.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function resolve_plan( $user_id ) {
		if ( ! self::applies_to( $user_id ) ) {
			return 'pro';
		}

		$stored = self::normalize( (string) get_user_meta( $user_id, self::META_PLAN, true ) );
		if ( '' !== $stored ) {
			return $stored;
		}

		// Cuentas sin plan: Profesional solo mientras no corra la migración; después, Free.
		return get_option( self::OPT_MIGRATED ) ? 'free' : 'pro';
	}

	/**
	 * Definition of the user's current plan.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>
	 */
	public static function get_definition( $user_id = 0 ) {
		return self::definitions()[ self::get_plan( $user_id ) ];
	}

	public static function set_plan( $user_id, $plan, array $args = array() ) {
		$user_id = absint( $user_id );
		$plan    = self::normalize( $plan );

		if ( ! $user_id || '' === $plan ) {
			return false;
		}

		$by       = isset( $args['by'] ) ? (int) $args['by'] : get_current_user_id();
		$reason   = isset( $args['reason'] ) ? (string) $args['reason'] : 'admin';
		$previous = self::get_plan( $user_id );

		update_user_meta( $user_id, self::META_PLAN, $plan );
		update_user_meta( $user_id, self::META_SINCE, time() );
		delete_user_meta( $user_id, self::META_REQUEST );
		self::forget( $user_id );

		self::log_history( $user_id, $plan, $previous, $by, $reason );
		self::apply_property_limit( $user_id );
		self::flush_usage_cache( $user_id );

		do_action( 'af_plan_changed', $user_id, $plan, $previous, $by, $args );

		return true;
	}

	/**
	 * Forgets the short display cache of the usage meters.
	 *
	 * @param int $user_id User ID.
	 */
	public static function flush_usage_cache( $user_id ) {
		foreach ( array_keys( self::definitions() ) as $plan ) {
			delete_transient( 'af_plan_ur_' . absint( $user_id ) . '_' . $plan );
		}
	}

	/**
	 * Appends a change to the account's plan history (last 20 entries).
	 *
	 * @param int    $user_id User ID.
	 * @param string $plan    Plan after the change.
	 * @param string $from    Plan before the change.
	 * @param int    $by      Acting user (0 = system).
	 * @param string $reason  admin|cancel|lapsed|renew|signup.
	 */
	public static function log_history( $user_id, $plan, $from, $by, $reason ) {
		$history = get_user_meta( $user_id, self::META_HISTORY, true );
		$history = is_array( $history ) ? $history : array();

		$history[] = array(
			'plan'   => $plan,
			'from'   => $from,
			'by'     => (int) $by,
			'reason' => sanitize_key( (string) $reason ),
			'at'     => time(),
		);

		update_user_meta( $user_id, self::META_HISTORY, array_slice( $history, -20 ) );
	}

	/**
	 * Advisory lock per user: serializes "check the limit, then create" so two
	 * concurrent requests cannot both pass the check. Released at the end of the request.
	 *
	 * @param int $user_id User whose limits are being checked.
	 * @return bool False when it could not be acquired in time.
	 */
	public static function lock( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}
		if ( isset( self::$locks[ $user_id ] ) ) {
			return true;
		}

		$name = 'af_plan_' . get_current_blog_id() . '_' . $user_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) ) ) {
			return false;
		}

		if ( empty( self::$locks ) ) {
			register_shutdown_function( array( __CLASS__, 'release_locks' ) );
		}
		self::$locks[ $user_id ] = $name;

		return true;
	}

	/**
	 * Releases the advisory locks held by this request.
	 */
	public static function release_locks() {
		global $wpdb;

		foreach ( self::$locks as $name ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
		self::$locks = array();
	}

	/**
	 * Response sent when the advisory lock cannot be taken.
	 */
	public static function send_busy_error() {
		wp_send_json_error( array( 'message' => __( 'Estamos procesando otra operación de tu cuenta. Inténtalo de nuevo en unos segundos.', 'arriendo-facil' ) ), 409 );
	}

	/**
	 * Pending change request of a user.
	 *
	 * @param int $user_id User ID.
	 * @return array{plan:string,at:int}|null
	 */
	public static function get_request( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$request = get_user_meta( $user_id, self::META_REQUEST, true );

		if ( ! is_array( $request ) || '' === self::normalize( isset( $request['plan'] ) ? $request['plan'] : '' ) ) {
			return null;
		}

		return array(
			'plan' => (string) $request['plan'],
			'at'   => isset( $request['at'] ) ? (int) $request['at'] : 0,
		);
	}

	/* ------------------------------------------------------------------
	 * Permisos, límites y uso
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the user's plan includes a feature.
	 *
	 * @param string $feature maintenance|catalog|billing.
	 * @param int    $user_id User ID.
	 * @return bool
	 */
	public static function has_feature( $feature, $user_id = 0 ) {
		$definition = self::get_definition( $user_id );

		return ! isset( $definition['features'][ $feature ] ) || ! empty( $definition['features'][ $feature ] );
	}

	/**
	 * Whether the user's plan lets them manage a service payment.
	 *
	 * @param string $service Service key (catalog or custom).
	 * @param int    $user_id User ID.
	 * @return bool
	 */
	public static function service_allowed( $service, $user_id = 0 ) {
		$definition = self::get_definition( $user_id );

		return null === $definition['services'] || in_array( sanitize_key( (string) $service ), $definition['services'], true );
	}

	/**
	 * Maximum amount of a resource, or null when unlimited.
	 *
	 * @param string $key   properties|guests|leases.
	 * @param int    $user_id User ID.
	 * @return int|null
	 */
	public static function limit( $key, $user_id = 0 ) {
		$definition = self::get_definition( $user_id );

		return isset( $definition['limits'][ $key ] ) ? (int) $definition['limits'][ $key ] : null;
	}

	/**
	 * Whether a column exists (cached; some are added by migrations).
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private static function has_column( $table, $column ) {
		static $cache = array();
		global $wpdb;

		$id = $table . '.' . $column;
		if ( ! isset( $cache[ $id ] ) ) {
			$cache[ $id ] = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		}

		return $cache[ $id ];
	}

	/**
	 * How many of a resource the user already has.
	 *
	 * @param string $key   properties|guests|leases.
	 * @param int    $user_id User ID.
	 * @return int
	 */
	public static function usage( $key, $user_id = 0 ) {
		global $wpdb;

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( 'properties' === $key ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_af_owner_id' AND m.meta_value = %s
					 LEFT JOIN {$wpdb->postmeta} lk ON lk.post_id = p.ID AND lk.meta_key = '_af_plan_locked'
					 WHERE lk.meta_id IS NULL AND p.post_type = 'accommodation' AND p.post_status IN ('publish','private','draft','pending')",
					(string) $user_id
				)
			);
		}

		if ( 'leases' === $key ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}af_leases l
					 INNER JOIN {$wpdb->postmeta} m ON m.post_id = l.accommodation_id AND m.meta_key = '_af_owner_id' AND m.meta_value = %s
					 INNER JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id AND p.post_status <> 'trash'
					 LEFT JOIN {$wpdb->postmeta} lk ON lk.post_id = l.accommodation_id AND lk.meta_key = '_af_plan_locked'
					 WHERE lk.meta_id IS NULL AND l.status IN ('draft','active','pending_release') AND l.deleted_at IS NULL",
					(string) $user_id
				)
			);
		}

		if ( 'guests' === $key ) {
			$guests = $wpdb->prefix . 'af_guests';
			$where  = array();
			$args   = array();

			if ( self::has_column( $guests, 'created_by' ) ) {
				$where[] = 'g.created_by = %d';
				$args[]  = $user_id;
			}

			$join = '';
			if ( self::has_column( $guests, 'accommodation_id' ) ) {
				$join    = " LEFT JOIN {$wpdb->postmeta} m ON m.post_id = g.accommodation_id AND m.meta_key = '_af_owner_id'";
				$where[] = 'm.meta_value = %s';
				$args[]  = (string) $user_id;
			}

			if ( empty( $where ) ) {
				return 0;
			}

			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT g.id) FROM {$guests} g{$join} WHERE " . implode( ' OR ', $where ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fragments.
					$args
				)
			);
		}

		return 0;
	}

	/**
	 * Smallest plan above the current one that fulfils a condition.
	 *
	 * @param string   $current  Current plan key.
	 * @param callable $accepts  Receives a plan definition, returns bool.
	 * @return string Plan key (falls back to the top plan).
	 */
	private static function upgrade_target( $current, $accepts ) {
		$definitions = self::definitions();
		$keys        = array_keys( $definitions );
		$start       = array_search( $current, $keys, true );

		foreach ( array_slice( $keys, false === $start ? 0 : $start + 1 ) as $key ) {
			if ( $accepts( $definitions[ $key ] ) ) {
				return $key;
			}
		}

		return end( $keys );
	}

	/**
	 * Builds the payload that the upgrade modal renders.
	 *
	 * @param string              $type  feature|limit|service.
	 * @param string              $key   Feature / resource / service key.
	 * @param array<string,mixed> $extra Extra keys (used, limit).
	 * @param int                 $user_id User ID.
	 * @return array<string,mixed>
	 */
	private static function build_gate( $type, $key, array $extra = array(), $user_id = 0 ) {
		$definitions = self::definitions();
		$current     = self::get_plan( $user_id );
		$current_lbl = $definitions[ $current ]['label'];

		if ( 'limit' === $type ) {
			$limit  = isset( $extra['limit'] ) ? (int) $extra['limit'] : 0;
			$target = self::upgrade_target(
				$current,
				static function ( $definition ) use ( $key, $limit ) {
					return null === $definition['limits'][ $key ] || (int) $definition['limits'][ $key ] > $limit;
				}
			);
			$labels = self::limit_labels();
			$noun   = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;

			$title   = __( 'Llegaste al límite de tu plan', 'arriendo-facil' );
			$message = sprintf(
				/* translators: 1: plan, 2: limit, 3: noun, 4: used */
				__( 'Tu plan %1$s permite hasta %2$d %3$s y ya tienes %4$d. Cambia de plan para administrar más.', 'arriendo-facil' ),
				$current_lbl,
				(int) $extra['limit'],
				$noun,
				(int) $extra['used']
			);
		} elseif ( 'locked' === $type ) {
			$target  = self::upgrade_target(
				$current,
				static function ( $definition ) {
					return null === $definition['limits']['properties'];
				}
			);
			$title   = __( 'Inmueble bloqueado por tu plan', 'arriendo-facil' );
			$message = sprintf(
				/* translators: 1: plan, 2: limit */
				__( 'Tu plan %1$s permite administrar tus %2$d primeros inmuebles y este queda oculto. Sus datos están guardados: se reactiva al renovar o contratar un plan con capacidad suficiente.', 'arriendo-facil' ),
				$current_lbl,
				(int) self::limit( 'properties', $user_id )
			);
		} elseif ( 'service' === $type ) {
			$target  = self::upgrade_target(
				$current,
				static function ( $definition ) {
					return null === $definition['services'];
				}
			);
			$title   = __( 'Servicio no incluido en tu plan', 'arriendo-facil' );
			$message = sprintf(
				/* translators: %s: plan */
				__( 'Con el plan %s solo puedes gestionar los pagos de agua y luz. Cambia de plan para agregar gas, internet, teléfono y otros servicios.', 'arriendo-facil' ),
				$current_lbl
			);
		} else {
			$target  = self::upgrade_target(
				$current,
				static function ( $definition ) use ( $key ) {
					return ! empty( $definition['features'][ $key ] );
				}
			);
			$labels  = self::feature_labels();
			$feature = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			$title   = sprintf(
				/* translators: %s: plan */
				__( 'Disponible en el plan %s', 'arriendo-facil' ),
				$definitions[ $target ]['label']
			);
			$message = sprintf(
				/* translators: 1: feature, 2: current plan, 3: target plan */
				__( '«%1$s» no está incluido en tu plan %2$s. Cámbiate al plan %3$s para desbloquearlo.', 'arriendo-facil' ),
				$feature,
				$current_lbl,
				$definitions[ $target ]['label']
			);
		}

		return array_merge(
			array(
				'type'       => $type,
				'key'        => $key,
				'plan'       => $current,
				'title'      => $title,
				'message'    => $message,
				'target'     => $target,
				'targetName' => $definitions[ $target ]['label'],
				'url'        => self::url( array( 'af_target' => $target ) ),
			),
			$extra
		);
	}

	/**
	 * Gate for a locked feature, or null when the plan includes it.
	 *
	 * @param string $feature maintenance|catalog|billing.
	 * @param int    $user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public static function feature_gate( $feature, $user_id = 0 ) {
		return self::has_feature( $feature, $user_id ) ? null : self::build_gate( 'feature', $feature, array(), $user_id );
	}

	/**
	 * Gate for a resource limit already reached, or null while there is room.
	 *
	 * @param string $key   properties|guests|leases.
	 * @param int    $user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public static function limit_gate( $key, $user_id = 0 ) {
		$limit = self::limit( $key, $user_id );
		if ( null === $limit ) {
			return null;
		}

		$used = self::usage( $key, $user_id );

		return $used >= $limit ? self::build_gate( 'limit', $key, array( 'used' => $used, 'limit' => $limit ), $user_id ) : null;
	}

	/**
	 * Gate for a service the plan does not cover, or null when allowed.
	 *
	 * @param string $service Service key.
	 * @param int    $user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public static function service_gate( $service, $user_id = 0 ) {
		return self::service_allowed( $service, $user_id ) ? null : self::build_gate( 'service', sanitize_key( (string) $service ), array(), $user_id );
	}

	/**
	 * Ends an AJAX request with the gate payload the modal listens for.
	 *
	 * @param array<string,mixed> $gate Gate payload.
	 */
	public static function send_gate_error( array $gate ) {
		wp_send_json_error(
			array(
				'message'   => $gate['message'],
				'plan_gate' => $gate,
			),
			403
		);
	}

	/**
	 * Gates currently locked for the user (used by the sidebar and the modal).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function locked_gates() {
		$gates = array();
		foreach ( array_keys( self::feature_labels() ) as $feature ) {
			$gate = self::feature_gate( $feature );
			if ( $gate ) {
				$gates[ $feature ] = $gate;
			}
		}

		$services = self::service_gate( 'gas' );
		if ( $services ) {
			$gates['services'] = $services;
		}

		return $gates;
	}

	/**
	 * Gate shown when someone opens an accommodation their plan locked.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>
	 */
	public static function locked_property_gate( $user_id = 0 ) {
		return self::build_gate( 'locked', 'properties', array(), $user_id );
	}

	/* ------------------------------------------------------------------
	 * Endurecimiento: migración, REST/editor nativo y permisos
	 * ------------------------------------------------------------------ */

	/**
	 * One-time grandfathering: managers that predate plans keep Profesional.
	 * Any account without a plan afterwards is treated as Free (fail closed).
	 */
	public function maybe_migrate_legacy() {
		if ( get_option( self::OPT_MIGRATED ) ) {
			return;
		}

		foreach ( get_users( array( 'role' => Arriendo_Facil_Tenancy::PROPERTY_MANAGER_ROLE, 'fields' => 'ID' ) ) as $user_id ) {
			if ( '' === self::normalize( (string) get_user_meta( (int) $user_id, self::META_PLAN, true ) ) ) {
				update_user_meta( (int) $user_id, self::META_PLAN, 'pro' );
			}
		}

		update_option( self::OPT_MIGRATED, 1, false );
	}

	/**
	 * Locked properties cannot be edited or removed through native WP/REST routes.
	 *
	 * @param string[] $caps    Required primitive caps.
	 * @param string   $cap     Requested capability.
	 * @param int      $user_id User ID.
	 * @param array    $args    Context (the post ID comes first).
	 * @return string[]
	 */
	public function deny_locked_property_caps( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$post_id = absint( $args[0] );
		if ( 'accommodation' === get_post_type( $post_id ) && Arriendo_Facil_Tenancy::is_plan_locked( $post_id ) && self::applies_to( $user_id ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Backstop: no route may create a property beyond the plan limit.
	 *
	 * @param bool  $maybe_empty Core's emptiness verdict.
	 * @param array $postarr     Post data.
	 * @return bool
	 */
	public function block_over_limit_insert( $maybe_empty, $postarr ) {
		if ( $maybe_empty || ! is_array( $postarr ) || 'accommodation' !== ( isset( $postarr['post_type'] ) ? $postarr['post_type'] : '' ) ) {
			return $maybe_empty;
		}

		if ( ! empty( $postarr['ID'] ) || 'auto-draft' === ( isset( $postarr['post_status'] ) ? $postarr['post_status'] : '' ) ) {
			return $maybe_empty;
		}

		$author = isset( $postarr['post_author'] ) ? absint( $postarr['post_author'] ) : get_current_user_id();

		if ( ! self::applies_to( $author ) ) {
			return $maybe_empty;
		}

		// Sin bloqueo no se puede garantizar el tope: se rechaza.
		return ! self::lock( $author ) || self::limit_gate( 'properties', $author ) ? true : $maybe_empty;
	}

	/**
	 * Property managers register properties through the wizard only; REST
	 * creation would skip the owner assignment the limits rely on.
	 *
	 * @param object          $prepared_post Prepared post.
	 * @param WP_REST_Request $request       Request.
	 * @return object|WP_Error
	 */
	public function block_rest_create( $prepared_post, $request ) {
		if ( empty( $prepared_post->ID ) && self::applies_to() ) {
			return new WP_Error( 'rest_forbidden', __( 'Registra inmuebles desde el asistente de Arriendo Fácil.', 'arriendo-facil' ), array( 'status' => 403 ) );
		}

		return $prepared_post;
	}

	/* ------------------------------------------------------------------
	 * Inmuebles activos / bloqueados al bajar de plan
	 * ------------------------------------------------------------------ */

	/**
	 * Accommodation IDs of a user (locked included, trash excluded), oldest first.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function owned_ids( $user_id = 0 ) {
		global $wpdb;

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_af_owner_id' AND m.meta_value = %s
					 WHERE p.post_type = 'accommodation' AND p.post_status IN ('publish','private','draft','pending')
					 ORDER BY p.ID ASC",
					(string) $user_id
				)
			)
		);
	}

	public static function apply_property_limit( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! self::applies_to( $user_id ) ) {
			return;
		}

		$limit  = self::limit( 'properties', $user_id );
		$owned  = self::owned_ids( $user_id ); // Más antiguos primero.
		$active = array_flip( null === $limit ? $owned : array_slice( $owned, 0, $limit ) );

		foreach ( $owned as $id ) {
			$is_locked = Arriendo_Facil_Tenancy::is_plan_locked( $id );

			if ( ! isset( $active[ $id ] ) && ! $is_locked ) {
				update_post_meta( $id, self::META_LOCKED, '1' );
				Arriendo_Facil_Property_Trash_Relations::detach( $id, Arriendo_Facil_Property_Trash_Relations::LOCK_SNAPSHOT, 'property_locked' );
			} elseif ( isset( $active[ $id ] ) && $is_locked ) {
				delete_post_meta( $id, self::META_LOCKED );
				Arriendo_Facil_Property_Trash_Relations::reattach( $id, Arriendo_Facil_Property_Trash_Relations::LOCK_SNAPSHOT, 'property_unlocked' );
			}
		}
	}

	public function on_property_trashed( $post_id ) {
		$this->resync_owner_of( $post_id );
	}

	public function on_property_untrashed( $post_id ) {
		$this->resync_owner_of( $post_id );
	}

	private function resync_owner_of( $post_id ) {
		if ( 'accommodation' !== get_post_type( $post_id ) ) {
			return;
		}

		$owner = (int) get_post_meta( $post_id, '_af_owner_id', true );
		if ( $owner ) {
			self::apply_property_limit( $owner );
		}
	}

	/* ------------------------------------------------------------------
	 * Pantallas y protección
	 * ------------------------------------------------------------------ */

	/**
	 * Registers the hidden plan screen.
	 */
	public function register_page() {
		add_submenu_page(
			'',
			__( 'Mi plan', 'arriendo-facil' ),
			__( 'Mi plan', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Whether the request targets this plugin's screens.
	 *
	 * @return bool
	 */
	private function is_plugin_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 'arriendo-facil' === $page || 0 === strpos( $page, 'af-' );
	}

	/**
	 * Blocks locked screens and AJAX actions server-side.
	 */
	public function guard() {
		if ( ! self::applies_to() ) {
			return;
		}

		global $pagenow;

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$route  = wp_doing_ajax() ? 'ajax' : ( 'admin-post.php' === $pagenow ? 'post' : '' );

		if ( '' !== $route ) {
			$feature = self::feature_for_action( $action );
			$gate    = $feature ? self::feature_gate( $feature ) : null;
			if ( $gate ) {
				if ( 'ajax' === $route ) {
					self::send_gate_error( $gate );
				}
				wp_safe_redirect( self::url( array( 'af_gate' => $feature ) ) );
				exit;
			}
			return;
		}

		// Any method: a POST to a locked screen must not slip through.
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page ) {
			return;
		}

		if ( isset( self::GATED_PAGES[ $page ] ) && ! self::has_feature( self::GATED_PAGES[ $page ] ) ) {
			wp_safe_redirect( self::url( array( 'af_gate' => self::GATED_PAGES[ $page ] ) ) );
			exit;
		}

		if ( Arriendo_Facil_Accommodation_Wizard::PAGE_SLUG !== $page ) {
			return;
		}

		$property_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $property_id && Arriendo_Facil_Tenancy::is_plan_locked( $property_id ) ) {
			wp_safe_redirect( Arriendo_Facil_Properties_Page::url( array( 'af_gate' => 'locked' ) ) );
			exit;
		}

		if ( ! $property_id && self::limit_gate( 'properties' ) ) {
			wp_safe_redirect( Arriendo_Facil_Properties_Page::url( array( 'af_gate' => 'properties' ) ) );
			exit;
		}
	}

	/**
	 * Feature a request action belongs to (exact list first, then prefixes).
	 *
	 * @param string $action Action name.
	 * @return string Empty when the action is not feature-gated.
	 */
	private static function feature_for_action( $action ) {
		if ( '' === $action ) {
			return '';
		}

		if ( isset( self::GATED_AJAX[ $action ] ) ) {
			return self::GATED_AJAX[ $action ];
		}

		foreach ( self::GATED_PREFIXES as $prefix => $feature ) {
			if ( 0 === strpos( $action, $prefix ) ) {
				return $feature;
			}
		}

		return '';
	}

	/**
	 * Keeps the plugin menu open on the plan screen.
	 *
	 * @param string $parent_file Parent slug.
	 * @return string
	 */
	public function keep_parent_menu_open( $parent_file ) {
		return $this->is_plan_page() ? 'arriendo-facil' : $parent_file;
	}

	/**
	 * Keeps a stable submenu highlight.
	 *
	 * @param string $submenu_file Submenu slug.
	 * @return string
	 */
	public function keep_submenu_selected( $submenu_file ) {
		return $this->is_plan_page() ? 'arriendo-facil' : $submenu_file;
	}

	/**
	 * Whether the request is the plan screen.
	 *
	 * @return bool
	 */
	private function is_plan_page() {
		return isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Enqueues the modal/toast layer on the plugin's screens.
	 */
	public function enqueue_assets() {
		if ( ! $this->is_plugin_screen() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$css = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-plans.css';
		$js  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-plans.js';

		wp_enqueue_style(
			'af-plans',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-plans.css',
			array( 'af-tokens' ),
			file_exists( $css ) ? (string) filemtime( $css ) : ARRIENDO_FACIL_VERSION
		);
		wp_enqueue_script(
			'af-plans',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-plans.js',
			wp_script_is( 'gsap', 'registered' ) ? array( 'jquery', 'heartbeat', 'gsap' ) : array( 'jquery', 'heartbeat' ),
			file_exists( $js ) ? (string) filemtime( $js ) : ARRIENDO_FACIL_VERSION,
			true
		);

		$gate = null;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['af_gate'] ) && self::applies_to() ) {
			$requested = sanitize_key( wp_unslash( $_GET['af_gate'] ) );
			if ( 'locked' === $requested ) {
				$gate = self::locked_property_gate();
			} elseif ( isset( self::feature_labels()[ $requested ] ) ) {
				$gate = self::feature_gate( $requested );
			} elseif ( isset( self::limit_labels()[ $requested ] ) ) {
				$gate = self::limit_gate( $requested );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'af-plans',
			'afPlans',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'planUrl' => self::url(),
				'gates'   => self::applies_to() ? self::locked_gates() : array(),
				'boot'    => $gate,
				'state'   => self::applies_to() ? Arriendo_Facil_Plan_Billing::client_state( get_current_user_id() ) : null,
				'i18n'    => array(
					'viewPlans'    => __( 'Ver planes', 'arriendo-facil' ),
					'notNow'       => __( 'Ahora no', 'arriendo-facil' ),
					'close'        => __( 'Cerrar', 'arriendo-facil' ),
					'working'      => __( 'Procesando…', 'arriendo-facil' ),
					'networkError' => __( 'No se pudo completar la acción. Inténtalo de nuevo.', 'arriendo-facil' ),
					'usage'        => __( '%1$d de %2$d', 'arriendo-facil' ),
					'back'         => __( 'Volver', 'arriendo-facil' ),
					'requestTitle' => __( 'Solicitar plan %s', 'arriendo-facil' ),
					'requestText'  => __( 'Te activaremos el plan %1$s ($%2$s al mes) cuando confirmemos el pago. Mientras tanto sigues con tu plan actual.', 'arriendo-facil' ),
					'requestConfirm' => __( 'Solicitar plan', 'arriendo-facil' ),
					'toFreeText'   => __( 'Volverás al plan Free de inmediato. Conservas todos tus datos: se administran tus 4 primeros inmuebles y el resto queda oculto, sin borrarse, hasta que renueves o contrates un plan con capacidad suficiente.', 'arriendo-facil' ),
					'toFreeConfirm' => __( 'Cambiar a Free', 'arriendo-facil' ),
					'cancelTitle'  => __( '¿Cancelar tu plan %s?', 'arriendo-facil' ),
					'cancelText'   => __( 'Volverás al plan Free de inmediato. Conservas todos tus datos: se administran tus 4 primeros inmuebles y el resto queda oculto, sin borrarse. Se reactivan al contratar de nuevo un plan con capacidad suficiente.', 'arriendo-facil' ),
					'understood'   => __( 'Entendido', 'arriendo-facil' ),
					'later'        => __( 'Más tarde', 'arriendo-facil' ),
					'sendProof'    => __( 'Enviar comprobante', 'arriendo-facil' ),
					'planChanged'  => __( 'Actualizar', 'arriendo-facil' ),
					'cancelConfirm' => __( 'Sí, cancelar plan', 'arriendo-facil' ),
					'keepPlan'     => __( 'Mantener plan', 'arriendo-facil' ),
				),
			)
		);
	}

	/**
	 * Prints the shared modal shell (filled in by af-plans.js).
	 */
	public function render_modal() {
		if ( ! $this->is_plugin_screen() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		?>
		<div class="af-plan-modal" id="af-plan-modal" hidden>
			<div class="af-plan-modal__backdrop" data-af-plan-close></div>
			<div class="af-plan-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-plan-modal-title" aria-describedby="af-plan-modal-text" tabindex="-1">
				<button type="button" class="af-plan-modal__x" data-af-plan-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>"><?php echo af_lucide( 'x', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></button>
				<span class="af-plan-modal__icon" aria-hidden="true"><?php echo af_lucide( 'crown', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<h2 id="af-plan-modal-title"></h2>
				<p id="af-plan-modal-text"></p>
				<div class="af-plan-modal__meter" hidden>
					<div class="af-plan-modal__meter-bar"><i></i></div>
					<span class="af-plan-modal__meter-label"></span>
				</div>
				<div class="af-plan-modal__actions">
					<button type="button" class="button af-btn af-btn--ghost" data-af-plan-close></button>
					<button type="button" class="button af-btn af-plan-modal__primary"></button>
				</div>
			</div>
		</div>
		<?php
	}

	private static function usage_rows( $user_id ) {
		// Solo para mostrar (menú, perfil): una copia de 45 s evita 4 consultas por página.
		$cache_key = 'af_plan_ur_' . absint( $user_id ) . '_' . self::get_plan( $user_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows   = array();
		$labels = self::limit_labels();

		foreach ( $labels as $key => $label ) {
			$limit   = self::limit( $key, $user_id );
			$used    = self::usage( $key, $user_id );
			$percent = ( null === $limit || $limit <= 0 ) ? 0 : min( 100, (int) round( $used / $limit * 100 ) );

			$rows[] = array(
				'label'   => ucfirst( $label ),
				'used'    => $used,
				'limit'   => $limit,
				'percent' => $percent,
				'state'   => null !== $limit && $used >= $limit ? 'is-full' : ( $percent >= 80 ? 'is-near' : '' ),
			);
		}

		set_transient( $cache_key, $rows, 45 );

		return $rows;
	}

	public static function topbar_pill() {
		if ( ! self::applies_to() ) {
			return '';
		}

		$plan  = self::get_plan();
		$state = Arriendo_Facil_Plan_Billing::snapshot( get_current_user_id() )['state'];
		$alert = in_array( $state, array( 'soon', 'due' ), true );

		return '<span class="af-plan-pill af-plan-pill--' . esc_attr( $plan ) . ( $alert ? ' has-alert' : '' ) . '">'
			. ( 'pro' === $plan ? af_lucide( 'crown', 12 ) : '' )
			. esc_html( self::get_definition()['label'] )
			. '</span>';
	}

	/**
	 * Plan card inside the user dropdown: plan, usage and an unobtrusive CTA.
	 */
	public static function render_menu_card() {
		if ( ! self::applies_to() ) {
			return;
		}

		$user_id    = get_current_user_id();
		$plan       = self::get_plan( $user_id );
		$definition = self::get_definition( $user_id );
		$rows       = self::usage_rows( $user_id );
		$snap       = Arriendo_Facil_Plan_Billing::snapshot( $user_id );
		?>
		<a class="af-plan-mini af-plan-mini--<?php echo esc_attr( $plan ); ?>" href="<?php echo esc_url( self::url() ); ?>" role="menuitem">
			<span class="af-plan-mini__head">
				<span class="af-plan-mini__icon" aria-hidden="true"><?php echo af_lucide( 'crown', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-plan-mini__title">
					<strong><?php echo esc_html( sprintf( /* translators: %s: plan name */ __( 'Plan %s', 'arriendo-facil' ), $definition['label'] ) ); ?></strong>
					<small>
						<?php
						if ( $snap['expires'] ) {
							echo esc_html( sprintf( /* translators: %s: phrase like "en 12 días" */ __( 'Se renueva %s', 'arriendo-facil' ), Arriendo_Facil_Plan_Billing::days_phrase( $snap['days'] ) ) );
						} elseif ( 'pro' === $plan ) {
							esc_html_e( 'Tienes todo incluido', 'arriendo-facil' );
						} else {
							esc_html_e( 'Mejorar plan', 'arriendo-facil' );
						}
						?>
					</small>
				</span>
				<span class="af-plan-mini__arrow" aria-hidden="true"><?php echo af_lucide( 'arrow-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			</span>
			<?php foreach ( $rows as $row ) : ?>
				<?php if ( null !== $row['limit'] ) : ?>
					<span class="af-plan-mini__row <?php echo esc_attr( $row['state'] ); ?>">
						<span><?php echo esc_html( $row['label'] ); ?></span>
						<b><?php echo esc_html( $row['used'] . '/' . $row['limit'] ); ?></b>
						<span class="af-plan-mini__bar"><i style="width:<?php echo esc_attr( (string) $row['percent'] ); ?>%" data-af-fill="<?php echo esc_attr( (string) $row['percent'] ); ?>"></i></span>
					</span>
				<?php endif; ?>
			<?php endforeach; ?>
		</a>
		<?php
	}

	/**
	 * Plan strip shown on the profile page.
	 */
	public static function render_summary() {
		if ( ! self::applies_to() ) {
			return;
		}

		$user_id    = get_current_user_id();
		$plan       = self::get_plan( $user_id );
		$definition = self::get_definition( $user_id );
		$rows       = self::usage_rows( $user_id );
		$request    = self::get_request( $user_id );
		$snap       = Arriendo_Facil_Plan_Billing::snapshot( $user_id );
		$locked     = count( array_filter( self::owned_ids( $user_id ), array( 'Arriendo_Facil_Tenancy', 'is_plan_locked' ) ) );
		$defs       = self::definitions();
		?>
		<section class="af-plan-summary af-plan-summary--<?php echo esc_attr( $plan ); ?>" aria-label="<?php esc_attr_e( 'Mi plan', 'arriendo-facil' ); ?>">
			<div class="af-plan-summary__main">
				<span class="af-plan-summary__icon" aria-hidden="true"><?php echo af_lucide( 'crown', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<div>
					<p class="af-plan-summary__eyebrow"><?php esc_html_e( 'Tu plan', 'arriendo-facil' ); ?></p>
					<h2><?php echo esc_html( $definition['label'] ); ?> <small>$<?php echo esc_html( number_format_i18n( (float) $definition['price'], 0 ) ); ?> <?php esc_html_e( '/ mes', 'arriendo-facil' ); ?></small></h2>
					<?php if ( $snap['expires'] && 'ok' === $snap['state'] && ! $request ) : ?>
						<p class="af-plan-summary__meta"><?php echo esc_html( sprintf( /* translators: 1: date, 2: phrase */ __( 'Se renueva el %1$s (%2$s)', 'arriendo-facil' ), Arriendo_Facil_Plan_Billing::format_date( $snap['expires'] ), Arriendo_Facil_Plan_Billing::days_phrase( $snap['days'] ) ) ); ?></p>
					<?php endif; ?>
					<?php if ( $request ) : ?>
						<p class="af-plan-summary__note"><?php echo esc_html( sprintf( /* translators: %s: plan */ __( 'Solicitud pendiente: plan %s', 'arriendo-facil' ), $defs[ $request['plan'] ]['label'] ) ); ?></p>
					<?php elseif ( 'due' === $snap['state'] || 'soon' === $snap['state'] ) : ?>
						<p class="af-plan-summary__note"><?php echo esc_html( sprintf( /* translators: 1: date, 2: phrase */ __( 'Vence el %1$s (%2$s)', 'arriendo-facil' ), Arriendo_Facil_Plan_Billing::format_date( $snap['expires'] ), Arriendo_Facil_Plan_Billing::days_phrase( $snap['days'] ) ) ); ?></p>
					<?php elseif ( $locked > 0 ) : ?>
						<p class="af-plan-summary__note"><?php echo esc_html( sprintf( /* translators: %d: number of locked properties */ _n( '%d inmueble guardado sin administrar', '%d inmuebles guardados sin administrar', $locked, 'arriendo-facil' ), $locked ) ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<div class="af-plan-summary__meters">
				<?php foreach ( $rows as $row ) : ?>
					<div class="af-plan-summary__meter <?php echo esc_attr( $row['state'] ); ?>">
						<span><?php echo esc_html( $row['label'] ); ?></span>
						<b><?php echo null === $row['limit'] ? esc_html( $row['used'] . ' · ' . __( 'ilimitado', 'arriendo-facil' ) ) : esc_html( $row['used'] . ' / ' . $row['limit'] ); ?></b>
						<span class="af-plan-mini__bar"><i style="width:<?php echo esc_attr( (string) $row['percent'] ); ?>%" data-af-fill="<?php echo esc_attr( (string) $row['percent'] ); ?>"></i></span>
					</div>
				<?php endforeach; ?>
			</div>
			<a class="button af-btn <?php echo 'pro' === $plan ? 'af-btn--ghost' : 'af-btn--primary'; ?>" href="<?php echo esc_url( self::url() ); ?>"><?php echo 'pro' === $plan ? esc_html__( 'Administrar plan', 'arriendo-facil' ) : esc_html__( 'Ver planes', 'arriendo-facil' ); ?></a>
		</section>
		<?php
	}

	/**
	 * Renders the plan screen.
	 */
	public function render_page() {
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para ver esta página.', 'arriendo-facil' ) );
		}

		$user_id     = get_current_user_id();
		$definitions = self::definitions();
		$current     = self::get_plan( $user_id );
		$request     = self::get_request( $user_id );
		$since       = (int) get_user_meta( $user_id, self::META_SINCE, true );
		$managed     = self::applies_to( $user_id );
		$usage       = array();
		foreach ( array_keys( self::limit_labels() ) as $key ) {
			$usage[ $key ] = array(
				'used'  => $managed ? self::usage( $key, $user_id ) : 0,
				'limit' => self::limit( $key, $user_id ),
			);
		}
		$target = isset( $_GET['af_target'] ) ? self::normalize( wp_unslash( $_GET['af_target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$billing    = Arriendo_Facil_Plan_Billing::snapshot( $user_id );
		$owned_ids  = $managed ? self::owned_ids( $user_id ) : array();
		$locked_cnt = count( array_filter( $owned_ids, array( 'Arriendo_Facil_Tenancy', 'is_plan_locked' ) ) );

		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/plan.php';
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Common checks of the manager-facing actions.
	 *
	 * @return int User ID.
	 */
	private function authorize_manager() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) || ! self::applies_to() ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para esta acción.', 'arriendo-facil' ) ), 403 );
		}

		return get_current_user_id();
	}

	/**
	 * Manager asks to change plan; the platform admin activates it.
	 */
	public function ajax_request() {
		$user_id = $this->authorize_manager();
		$plan    = isset( $_POST['plan'] ) ? self::normalize( wp_unslash( $_POST['plan'] ) ) : '';

		if ( '' === $plan || $plan === self::get_plan( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Elige un plan distinto al actual.', 'arriendo-facil' ) ), 400 );
		}

		// Volver a Free no necesita aprobación: es cancelar.
		if ( 'free' === $plan ) {
			self::set_plan( $user_id, 'free', array( 'reason' => 'cancel' ) );
			wp_send_json_success( array( 'message' => __( 'Tu plan se canceló. Ahora estás en Free.', 'arriendo-facil' ) ) );
		}

		$previous = self::get_request( $user_id );
		if ( $previous && $previous['plan'] === $plan && time() - $previous['at'] < 10 * MINUTE_IN_SECONDS ) {
			wp_send_json_success( array( 'message' => __( 'Ya recibimos tu solicitud. Te activaremos el plan en breve.', 'arriendo-facil' ) ) );
		}

		update_user_meta( $user_id, self::META_REQUEST, array( 'plan' => $plan, 'at' => time() ) );

		$user        = wp_get_current_user();
		$definitions = self::definitions();
		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: plan */
				__( '[Arriendo Fácil] Solicitud de cambio al plan %s', 'arriendo-facil' ),
				$definitions[ $plan ]['label']
			),
			sprintf(
				/* translators: 1: name, 2: email, 3: current plan, 4: requested plan, 5: url */
				__( "%1\$s (%2\$s) pidió cambiar del plan %3\$s al plan %4\$s.\n\nActívalo en: %5\$s", 'arriendo-facil' ),
				$user->display_name,
				$user->user_email,
				$definitions[ self::get_plan( $user_id ) ]['label'],
				$definitions[ $plan ]['label'],
				admin_url( 'admin.php?page=af-property-admins' )
			)
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: plan */
					__( 'Solicitud enviada. Te activaremos el plan %s en breve.', 'arriendo-facil' ),
					$definitions[ $plan ]['label']
				),
			)
		);
	}

	/**
	 * Manager withdraws a pending request.
	 */
	public function ajax_cancel_request() {
		$user_id = $this->authorize_manager();

		if ( ! self::get_request( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes solicitudes pendientes.', 'arriendo-facil' ) ), 404 );
		}

		delete_user_meta( $user_id, self::META_REQUEST );
		wp_send_json_success( array( 'message' => __( 'Solicitud cancelada.', 'arriendo-facil' ) ) );
	}

	/**
	 * Manager cancels the paid plan: back to Free right away.
	 */
	public function ajax_cancel() {
		$user_id = $this->authorize_manager();

		if ( 'free' === self::get_plan( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Ya estás en el plan Free.', 'arriendo-facil' ) ), 400 );
		}

		self::set_plan( $user_id, 'free', array( 'reason' => 'cancel' ) );
		wp_send_json_success( array( 'message' => __( 'Tu plan se canceló. Ahora estás en Free; tus datos se conservan y los inmuebles que superan el límite quedan ocultos, sin borrarse.', 'arriendo-facil' ) ) );
	}

	/**
	 * Platform admin sets the plan of a property manager.
	 */
	public function ajax_set() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$plan    = isset( $_POST['plan'] ) ? self::normalize( wp_unslash( $_POST['plan'] ) ) : '';

		if ( ! $user_id || '' === $plan || ! Arriendo_Facil_Tenancy::is_property_manager( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Datos inválidos.', 'arriendo-facil' ) ), 400 );
		}

		self::set_plan( $user_id, $plan );

		$definitions = self::definitions();
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: plan */
					__( 'Plan actualizado a %s.', 'arriendo-facil' ),
					$definitions[ $plan ]['label']
				),
			)
		);
	}
}
