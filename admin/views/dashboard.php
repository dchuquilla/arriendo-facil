<?php
/**
 * Dashboard admin view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var wpdb $wpdb */
global $wpdb;

$current_user = wp_get_current_user();
$is_owner     = Arriendo_Facil_Accommodation::user_is_owner();

$owner_ids = array();
if ( $is_owner ) {
	$owner_ids = array_map( 'intval', Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() ) );
}

$ids_sql = ( $is_owner && ! empty( $owner_ids ) ) ? implode( ',', $owner_ids ) : '';

// ── KPI counts ────────────────────────────────────────────────────────────
if ( $is_owner ) {
	$accommodation_count = count( $owner_ids );

	if ( '' !== $ids_sql ) {
		$lease_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($ids_sql) AND deleted_at IS NULL" );
		$active_leases    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($ids_sql) AND status = 'active' AND deleted_at IS NULL" );
		$draft_leases     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($ids_sql) AND status = 'draft' AND deleted_at IS NULL" );
		$guest_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_guests WHERE accommodation_id IN ($ids_sql)" );
		$pending_cleaning = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_cleaning_requests WHERE accommodation_id IN ($ids_sql) AND status = 'pending'" );
		$review_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews WHERE accommodation_id IN ($ids_sql) AND status = 'completed'" );
		$avg_stars        = (float) $wpdb->get_var( "SELECT AVG(stars) FROM {$wpdb->prefix}af_reviews WHERE accommodation_id IN ($ids_sql) AND status = 'completed'" );
		$positive_reviews = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews WHERE accommodation_id IN ($ids_sql) AND status = 'completed' AND stars >= 4" );
		$pending_queue    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_interest_queue WHERE accommodation_id IN ($ids_sql) AND status = 'pending'" );
	} else {
		$lease_count = $active_leases = $draft_leases = $guest_count = $pending_cleaning = $review_count = $positive_reviews = $pending_queue = 0;
		$avg_stars   = 0.0;
	}
	$active_contacts = null;
} else {
	$accommodation_count = (int) wp_count_posts( 'accommodation' )->publish;
	$lease_count         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE deleted_at IS NULL" );
	$active_leases       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE status = 'active' AND deleted_at IS NULL" );
	$draft_leases        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE status = 'draft' AND deleted_at IS NULL" );
	$guest_count         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_guests" );
	$pending_cleaning    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_cleaning_requests WHERE status = 'pending'" );
	$review_count        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews WHERE status = 'completed'" );
	$avg_stars           = (float) $wpdb->get_var( "SELECT AVG(stars) FROM {$wpdb->prefix}af_reviews WHERE status = 'completed'" );
	$positive_reviews    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews WHERE status = 'completed' AND stars >= 4" );
	$active_contacts     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_owner_contacts WHERE status = 'active'" );
	$pending_queue       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_interest_queue WHERE status = 'pending'" );
}

$positive_rate = $review_count > 0 ? (int) round( $positive_reviews / $review_count * 100 ) : 0;

// ── KPIs de administración (cobranza, mora, vencimientos, documentos) ─────
$is_management_model = ! ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES );
$current_period      = gmdate( 'Y-m' );
$charges_table       = $wpdb->prefix . 'af_charges';
$has_ledger          = class_exists( 'Arriendo_Facil_Billing_Ledger' );

$period_charged = 0.0;
$period_paid    = 0.0;
$overdue_count  = 0;
$overdue_amount = 0.0;
$expiring_count = 0;
$docs_pending   = 0;
$scope_ids      = Arriendo_Facil_Tenancy::accessible_accommodation_ids();

// ── Filtro por administrador asignado (solo super admin) ────────────────
$filter_admin_id = 0;
$filter_admins   = array();
if ( Arriendo_Facil_Tenancy::can_manage_all() ) {
	$filter_admins   = Arriendo_Facil_Tenancy::get_property_admins();
	$filter_admin_id = isset( $_GET['af_admin_id'] ) ? absint( wp_unslash( $_GET['af_admin_id'] ) ) : 0;
	if ( $filter_admin_id ) {
		$admin_scope_ids = array_map( 'absint', Arriendo_Facil_Accommodation::get_owner_accommodation_ids( $filter_admin_id ) );
		$scope_ids       = is_array( $scope_ids ) ? array_intersect( $scope_ids, $admin_scope_ids ) : $admin_scope_ids;

		// Los KPIs globales deben reflejar el alcance filtrado.
		if ( ! $is_owner ) {
			$accommodation_count = count( $scope_ids );
			if ( empty( $scope_ids ) ) {
				$lease_count = $active_leases = $draft_leases = $guest_count = 0;
			} else {
				$filter_ids_sql = Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids );
				$lease_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($filter_ids_sql) AND deleted_at IS NULL" );
				$active_leases  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($filter_ids_sql) AND status = 'active' AND deleted_at IS NULL" );
				$draft_leases   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($filter_ids_sql) AND status = 'draft' AND deleted_at IS NULL" );
				$guest_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_guests WHERE accommodation_id IN ($filter_ids_sql)" );
			}
		}
	}
}

if ( $has_ledger ) {
	$scope_clause = null === $scope_ids ? '' : ' AND lease_id IN (SELECT id FROM ' . $wpdb->prefix . 'af_leases WHERE accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . '))';

	$period_totals = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COALESCE(SUM(amount), 0) AS charged, COALESCE(SUM(amount_paid), 0) AS paid
			 FROM {$charges_table}
			 WHERE period = %s AND status != 'void'{$scope_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$current_period
		)
	);
	$period_charged = isset( $period_totals->charged ) ? (float) $period_totals->charged : 0.0;
	$period_paid    = isset( $period_totals->paid ) ? (float) $period_totals->paid : 0.0;

	$overdue = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COUNT(*) AS total, COALESCE(SUM(amount - amount_paid), 0) AS balance
			 FROM {$charges_table}
			 WHERE status IN ('pending', 'partial', 'overdue') AND due_date < %s{$scope_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			gmdate( 'Y-m-d' )
		)
	);
	$overdue_count  = isset( $overdue->total ) ? (int) $overdue->total : 0;
	$overdue_amount = isset( $overdue->balance ) ? (float) $overdue->balance : 0.0;
}

// Contratos que vencen en los próximos 60 días.
$expiring_where = "status = 'active' AND deleted_at IS NULL AND end_date BETWEEN %s AND %s";
$expiring_args  = array( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', strtotime( '+60 days' ) ) );
if ( null !== $scope_ids ) {
	$expiring_where .= ' AND accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';
}

$expiring_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE {$expiring_where}", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$expiring_args
	)
);

if ( class_exists( 'Arriendo_Facil_Document_Verification' ) ) {
	$docs_where = "doc_status IS NULL OR doc_status = 'pendiente'";
	if ( isset( $scope_ids ) && null !== $scope_ids ) {
		$docs_where = '(' . $docs_where . ') AND accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';
	}
	$docs_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_guests WHERE {$docs_where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

// ── Filtros de alertas de calendario (rango de fechas + inmueble/edificio) ─
$calendar_from  = isset( $_GET['af_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['af_date_from'] ) ) : gmdate( 'Y-m-d' );
$calendar_to    = isset( $_GET['af_date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['af_date_to'] ) ) : gmdate( 'Y-m-d', strtotime( '+30 days' ) );
if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $calendar_from ) ) {
	$calendar_from = gmdate( 'Y-m-d' );
}
if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $calendar_to ) || $calendar_to < $calendar_from ) {
	$calendar_to = gmdate( 'Y-m-d', strtotime( $calendar_from . ' +30 days' ) );
}

$calendar_accommodation_id = isset( $_GET['af_accommodation_id'] ) ? absint( wp_unslash( $_GET['af_accommodation_id'] ) ) : 0;
$calendar_building_id      = isset( $_GET['af_building_id'] ) ? absint( wp_unslash( $_GET['af_building_id'] ) ) : 0;

$calendar_scope_ids = $scope_ids;
if ( $calendar_building_id && class_exists( 'Arriendo_Facil_Property_Structure' ) ) {
	$building_units = Arriendo_Facil_Property_Structure::get_units_by_building( $calendar_building_id );
	$building_accom_ids = array_filter( array_map( static function ( $unit ) { return (int) $unit->accommodation_id; }, (array) $building_units ) );
	$calendar_scope_ids = is_array( $calendar_scope_ids ) ? array_intersect( $calendar_scope_ids, $building_accom_ids ) : $building_accom_ids;
}
if ( $calendar_accommodation_id ) {
	$calendar_scope_ids = is_array( $calendar_scope_ids ) ? array_intersect( $calendar_scope_ids, array( $calendar_accommodation_id ) ) : array( $calendar_accommodation_id );
}

$calendar_scope_clause = is_array( $calendar_scope_ids ) ? ' AND l.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $calendar_scope_ids ) . ')' : '';

$upcoming_checkins = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT l.id, l.start_date, l.accommodation_id, p.post_title AS accommodation_title,
		        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
		 FROM {$wpdb->prefix}af_leases l
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
		 WHERE l.deleted_at IS NULL AND l.start_date BETWEEN %s AND %s{$calendar_scope_clause}
		 ORDER BY l.start_date ASC
		 LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$calendar_from,
		$calendar_to
	)
);

$upcoming_checkouts = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT l.id, l.end_date, l.accommodation_id, p.post_title AS accommodation_title,
		        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
		 FROM {$wpdb->prefix}af_leases l
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
		 WHERE l.deleted_at IS NULL AND l.status = 'active' AND l.end_date BETWEEN %s AND %s{$calendar_scope_clause}
		 ORDER BY l.end_date ASC
		 LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$calendar_from,
		$calendar_to
	)
);

// Visitas agendadas dentro del rango (pueden existir aunque los endpoints
// de reserva esten desactivados en el modelo de administracion).
$calendar_visits_scope_clause = is_array( $calendar_scope_ids ) ? ' AND vb.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $calendar_scope_ids ) . ')' : '';
$upcoming_visits              = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT vb.id, vs.visit_date, vs.start_time, vb.accommodation_id,
		        vb.guest_name, p.post_title AS accommodation_title
		 FROM {$wpdb->prefix}af_visit_bookings vb
		 LEFT JOIN {$wpdb->prefix}af_visit_slots vs ON vs.id = vb.slot_id
		 LEFT JOIN {$wpdb->posts} p ON p.ID = vb.accommodation_id
		 WHERE vs.visit_date BETWEEN %s AND %s
		   AND vb.status IN ('confirmed', 'completed'){$calendar_visits_scope_clause}
		 ORDER BY vs.visit_date ASC, vs.start_time ASC
		 LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$calendar_from,
		$calendar_to
	)
);

// ── Cronograma de proximas salidas 30/60/90 ──────────────────────────────
$schedule_today  = gmdate( 'Y-m-d' );
$schedule_limit  = gmdate( 'Y-m-d', strtotime( '+90 days' ) );
$schedule_scope  = null === $scope_ids ? '' : ' AND l.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';
$exit_buckets    = array( '30' => 0, '60' => 0, '90' => 0 );
$exit_cards      = array();
$schedule_leases = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT l.id, l.end_date, l.accommodation_id, l.legal_status,
		        p.post_title AS accommodation_title, CONCAT(g.first_name, ' ', g.last_name) AS guest_name
		 FROM {$wpdb->prefix}af_leases l
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
		 WHERE l.status = 'active' AND l.deleted_at IS NULL AND l.end_date BETWEEN %s AND %s{$schedule_scope}
		 ORDER BY l.end_date ASC
		 LIMIT 8", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$schedule_today,
		$schedule_limit
	)
);
foreach ( (array) $schedule_leases as $schedule_lease ) {
	$days = max( 0, (int) floor( ( strtotime( $schedule_lease->end_date ) - strtotime( $schedule_today ) ) / DAY_IN_SECONDS ) );
	$exit_buckets[ $days <= 30 ? '30' : ( $days <= 60 ? '60' : '90' ) ]++;
	$exit_cards[] = array(
		'lease'  => $schedule_lease,
		'days'   => $days,
		'bucket' => $days <= 30 ? '30' : ( $days <= 60 ? '60' : '90' ),
	);
}

// Dropdown options for the calendar filter (buildings + properties in scope).
$calendar_buildings = class_exists( 'Arriendo_Facil_Property_Structure' )
	? $wpdb->get_results( 'SELECT id, name FROM ' . Arriendo_Facil_Property_Structure::buildings_table() . " WHERE status = 'active' ORDER BY name ASC" )
	: array();
$calendar_properties_query = array(
	'post_type'      => 'accommodation',
	'post_status'    => array( 'publish', 'draft', 'private' ),
	'posts_per_page' => 200,
	'orderby'        => 'title',
	'order'          => 'ASC',
	'fields'         => 'ids',
);
if ( is_array( $scope_ids ) ) {
	$calendar_properties_query['post__in'] = ! empty( $scope_ids ) ? $scope_ids : array( 0 );
}
$calendar_property_ids = get_posts( $calendar_properties_query );

// ── Resumen de mantenimientos por prioridad ───────────────────────────────
$maintenance_priority = array( 'alta' => 0, 'media' => 0, 'baja' => 0 );
if ( class_exists( 'Arriendo_Facil_Maintenance' ) ) {
	$maintenance_scope_clause = null === $scope_ids ? '' : ' AND accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';
	$maintenance_rows = (array) $wpdb->get_results(
		'SELECT priority, COUNT(*) AS total FROM ' . Arriendo_Facil_Maintenance::table() . "
		 WHERE status IN ('pending', 'in_progress'){$maintenance_scope_clause}
		 GROUP BY priority" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);
	foreach ( $maintenance_rows as $row ) {
		if ( isset( $maintenance_priority[ $row->priority ] ) ) {
			$maintenance_priority[ $row->priority ] = (int) $row->total;
		}
	}
}
$maintenance_urgent_total = $maintenance_priority['alta'];
$maintenance_open_total   = array_sum( $maintenance_priority );

$period_balance   = round( $period_charged - $period_paid, 2 );
$collection_rate  = $period_charged > 0 ? (int) round( $period_paid / $period_charged * 100 ) : 0;

// ── Semáforo de cobros: cobrado / pendiente / atrasado + top mora ────────
$semaforo = array(
	'cobrado'   => array( 'count' => 0, 'amount' => 0.0 ),
	'pendiente' => array( 'count' => 0, 'amount' => 0.0 ),
	'atrasado'  => array( 'count' => $overdue_count, 'amount' => $overdue_amount ),
);
$top_mora = array();

if ( $is_management_model && $has_ledger ) {
	$semaforo_scope = null === $scope_ids ? '' : ' AND c.lease_id IN (SELECT id FROM ' . $wpdb->prefix . 'af_leases WHERE accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . '))';

	$status_totals = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT c.status, COUNT(*) AS total, COALESCE(SUM(c.amount), 0) AS amount
			 FROM {$charges_table} c
			 WHERE c.period = %s AND c.status != 'void'{$semaforo_scope}
			 GROUP BY c.status", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$current_period
		)
	);

	foreach ( (array) $status_totals as $row ) {
		if ( 'paid' === $row->status ) {
			$semaforo['cobrado']['count']  += (int) $row->total;
			$semaforo['cobrado']['amount'] += (float) $row->amount;
		} elseif ( in_array( $row->status, array( 'pending', 'partial' ), true ) ) {
			$semaforo['pendiente']['count']  += (int) $row->total;
			$semaforo['pendiente']['amount'] += (float) $row->amount;
		}
	}

	$top_mora = (array) $wpdb->get_results(
		"SELECT c.id, c.amount, c.amount_paid, c.due_date, DATEDIFF(CURDATE(), c.due_date) AS days_overdue,
		        p.post_title AS accommodation_title,
		        CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
		        l.id AS lease_id
		 FROM {$charges_table} c
		 LEFT JOIN {$wpdb->prefix}af_leases l ON l.id = c.lease_id
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = c.guest_id
		 WHERE c.status IN ('pending', 'partial', 'overdue') AND c.due_date < CURDATE(){$semaforo_scope}
		 ORDER BY days_overdue DESC
		 LIMIT 6" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);
}

// ── Owner property preview (top 6) ────────────────────────────────────────
$owner_properties = array();
if ( $is_owner && '' !== $ids_sql ) {
	$owner_properties = $wpdb->get_results(
		"SELECT p.ID, p.post_title, pm_rent.meta_value AS monthly_rent, pm_status.meta_value AS availability
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm_rent   ON pm_rent.post_id = p.ID   AND pm_rent.meta_key = '_af_monthly_rent'
		 LEFT JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_af_status'
		 WHERE p.post_type = 'accommodation'
		   AND p.post_status = 'publish'
		   AND p.ID IN ($ids_sql)
		 ORDER BY p.post_date DESC
		 LIMIT 6"
	);
}

// ── Greeting ──────────────────────────────────────────────────────────────
$hour = (int) current_time( 'H' );
if ( $hour < 12 ) {
	$greeting = __( 'Buenos días', 'arriendo-facil' );
} elseif ( $hour < 19 ) {
	$greeting = __( 'Buenas tardes', 'arriendo-facil' );
} else {
	$greeting = __( 'Buenas noches', 'arriendo-facil' );
}

$first_name = $current_user->first_name ? $current_user->first_name : $current_user->display_name;
$today_str  = wp_date( 'l, j \d\e F' );

// ── Video de marca en el hero (opcional, configurable por el super admin) ─
$hero_video_url    = (string) get_option( 'af_dashboard_hero_video_url', '' );
$hero_video_embed  = '';
$hero_video_direct = false;

if ( '' !== $hero_video_url ) {
	if ( preg_match( '/\.(mp4|webm|ogg)(\?.*)?$/i', $hero_video_url ) ) {
		$hero_video_direct = true;
	} else {
		$oembed_cache_key = 'af_hero_oembed_' . md5( $hero_video_url );
		$hero_video_embed  = get_transient( $oembed_cache_key );

		if ( false === $hero_video_embed ) {
			$hero_video_embed = (string) wp_oembed_get( $hero_video_url, array( 'width' => 640 ) );
			set_transient( $oembed_cache_key, $hero_video_embed, DAY_IN_SECONDS );
		}
	}
}

// ── Tasks list ────────────────────────────────────────────────────────────
$tasks = array();

if ( $is_management_model ) {
	if ( $overdue_count > 0 ) {
		$tasks[] = array(
			'label' => _n( 'cargo vencido por cobrar', 'cargos vencidos por cobrar', $overdue_count, 'arriendo-facil' ),
			'count' => $overdue_count,
			'url'   => admin_url( 'admin.php?page=af-collections&charge_status=overdue' ),
		);
	}
	if ( $docs_pending > 0 ) {
		$tasks[] = array(
			'label' => _n( 'inquilino con documentos por verificar', 'inquilinos con documentos por verificar', $docs_pending, 'arriendo-facil' ),
			'count' => $docs_pending,
			'url'   => admin_url( 'admin.php?page=af-guests' ),
		);
	}
	if ( $expiring_count > 0 ) {
		$tasks[] = array(
			'label' => _n( 'contrato por vencer en 60 días', 'contratos por vencer en 60 días', $expiring_count, 'arriendo-facil' ),
			'count' => $expiring_count,
			'url'   => admin_url( 'admin.php?page=af-leases' ),
		);
	}
}

if ( $draft_leases > 0 ) {
	$tasks[] = array(
		'label' => _n( 'contrato en borrador por revisar', 'contratos en borrador por revisar', $draft_leases, 'arriendo-facil' ),
		'count' => $draft_leases,
		'url'   => admin_url( 'admin.php?page=af-leases' ),
	);
}
if ( $pending_cleaning > 0 ) {
	$tasks[] = array(
		'label' => _n( 'solicitud de limpieza pendiente', 'solicitudes de limpieza pendientes', $pending_cleaning, 'arriendo-facil' ),
		'count' => $pending_cleaning,
		'url'   => admin_url( 'admin.php?page=af-cleaning-requests' ),
	);
}
if ( $maintenance_urgent_total > 0 ) {
	$tasks[] = array(
		'label' => _n( 'incidencia crítica de mantenimiento', 'incidencias críticas de mantenimiento', $maintenance_urgent_total, 'arriendo-facil' ),
		'count' => $maintenance_urgent_total,
		'url'   => admin_url( 'admin.php?page=af-maintenance' ),
	);
}
if ( $pending_queue > 0 && ! $is_management_model ) {
	$tasks[] = array(
		'label' => _n( 'huésped interesado por aprobar', 'huéspedes interesados por aprobar', $pending_queue, 'arriendo-facil' ),
		'count' => $pending_queue,
		'url'   => admin_url( 'admin.php?page=af-guests' ),
	);
}

// ── Datos para gráficos (Resumen de ocupación + Ingresos por arriendos) ──
$occupancy_chart = array( 'available' => 0, 'occupied' => 0, 'maintenance' => 0 );
$occupancy_query = array(
	'post_type'      => 'accommodation',
	'post_status'    => array( 'publish', 'private' ),
	'posts_per_page' => -1,
	'fields'         => 'ids',
);
if ( is_array( $scope_ids ) ) {
	$occupancy_query['post__in'] = ! empty( $scope_ids ) ? $scope_ids : array( 0 );
}
foreach ( get_posts( $occupancy_query ) as $occupancy_post_id ) {
	$occ_status = (string) get_post_meta( $occupancy_post_id, '_af_status', true );
	if ( in_array( $occ_status, array( 'occupied', 'rented' ), true ) ) {
		$occupancy_chart['occupied']++;
	} elseif ( 'maintenance' === $occ_status ) {
		$occupancy_chart['maintenance']++;
	} else {
		$occupancy_chart['available']++;
	}
}

$revenue_chart = array( 'labels' => array(), 'values' => array() );
if ( $has_ledger ) {
	$revenue_scope_clause = null === $scope_ids ? '' : ' AND lease_id IN (SELECT id FROM ' . $wpdb->prefix . 'af_leases WHERE accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . '))';
	for ( $months_ago = 5; $months_ago >= 0; $months_ago-- ) {
		$chart_period            = gmdate( 'Y-m', strtotime( "-{$months_ago} months" ) );
		$revenue_chart['labels'][] = wp_date( 'M', strtotime( $chart_period . '-01' ) );
		$revenue_chart['values'][] = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount_paid), 0) FROM {$charges_table} WHERE period = %s{$revenue_scope_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$chart_period
			)
		);
	}
}

// ── Contratos recientes (tabla compacta, últimos 6) ──────────────────────
$recent_leases_scope = null === $scope_ids ? '' : ' AND l.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';
$recent_leases        = (array) $wpdb->get_results(
	"SELECT l.id, l.start_date, l.end_date, l.status, l.accommodation_id,
	        p.post_title AS accommodation_title, CONCAT(g.first_name, ' ', g.last_name) AS guest_name
	 FROM {$wpdb->prefix}af_leases l
	 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
	 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
	 WHERE l.deleted_at IS NULL{$recent_leases_scope}
	 ORDER BY l.id DESC
	 LIMIT 6" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

// ── Reviews recientes (últimas 5 completadas admin→inquilino) ────────────
$recent_reviews_scope = null === $scope_ids ? '' : ' AND r.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';
$recent_reviews        = (array) $wpdb->get_results(
	"SELECT r.id, r.stars, r.comment_text, r.accommodation_id, p.post_title AS accommodation_title,
	        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
	 FROM {$wpdb->prefix}af_reviews r
	 LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
	 LEFT JOIN {$wpdb->prefix}af_leases l ON l.id = r.lease_id
	 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
	 WHERE r.status = 'completed' AND r.review_direction = 'owner_to_tenant'{$recent_reviews_scope}
	 ORDER BY r.id DESC
	 LIMIT 5" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);
?>
<div class="wrap af-shell af-dashboard">

	<header class="af-page-header">
		<div class="af-page-header__title">
			<span class="af-page-header__eyebrow"><?php echo esc_html( $today_str ); ?></span>
			<h1>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: greeting (e.g. Buenos días), 2: user first name */
						__( '%1$s, %2$s', 'arriendo-facil' ),
						$greeting,
						$first_name
					)
				);
				?>
			</h1>
			<p class="af-page-header__subtitle">
				<?php
				echo esc_html(
					$is_owner
						? __( 'Un resumen rápido de tus propiedades y todo lo que necesita tu atención.', 'arriendo-facil' )
						: ( $is_management_model
							? __( 'Cobranza, mora y vencimientos de la operación en un vistazo.', 'arriendo-facil' )
							: __( 'Panel de operaciones — el pulso de Arriendo Fácil en un vistazo.', 'arriendo-facil' ) )
				);
				?>
			</p>
		</div>

		<div class="af-page-header__actions">
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=accommodation' ) ); ?>" class="button af-btn af-btn--primary">
				<span class="af-btn__icon" aria-hidden="true">
					<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
				</span>
				<?php esc_html_e( 'Nuevo alojamiento', 'arriendo-facil' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>" class="button af-btn af-btn--ghost">
				<?php esc_html_e( 'Ver contratos', 'arriendo-facil' ); ?>
			</a>
		</div>
	</header>

	<?php if ( '' !== $hero_video_url ) : ?>
		<section class="af-hero-media" aria-label="<?php esc_attr_e( 'Video de bienvenida', 'arriendo-facil' ); ?>">
			<div class="af-hero-media__frame">
				<?php if ( $hero_video_direct ) : ?>
					<video class="af-hero-media__video" src="<?php echo esc_url( $hero_video_url ); ?>" autoplay muted loop playsinline></video>
				<?php elseif ( '' !== $hero_video_embed ) : ?>
					<div class="af-hero-media__embed"><?php echo $hero_video_embed; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_oembed_get() sanitizes provider markup. */ ?></div>
				<?php endif; ?>
			</div>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<button type="button" class="af-hero-media__edit" id="af-hero-video-edit"><?php esc_html_e( 'Cambiar video', 'arriendo-facil' ); ?></button>
			<?php endif; ?>
		</section>
	<?php elseif ( current_user_can( 'manage_options' ) ) : ?>
		<section class="af-hero-media af-hero-media--empty">
			<p><?php esc_html_e( 'Añade un video corto de bienvenida (mp4/webm o enlace de YouTube/Vimeo) para que el panel se sienta 100% Arriendo Fácil.', 'arriendo-facil' ); ?></p>
			<button type="button" class="button af-btn af-btn--ghost" id="af-hero-video-edit"><?php esc_html_e( 'Añadir video', 'arriendo-facil' ); ?></button>
		</section>
	<?php endif; ?>

	<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<div class="af-modal" id="af-hero-video-modal" role="dialog" aria-modal="true" aria-labelledby="af-hero-video-modal-title">
			<div class="af-modal__backdrop" data-af-modal-close></div>
			<div class="af-modal__dialog">
				<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
				<div class="af-modal__header">
					<h2 class="af-modal__title" id="af-hero-video-modal-title"><?php esc_html_e( 'Video de bienvenida', 'arriendo-facil' ); ?></h2>
					<p class="af-modal__subtitle"><?php esc_html_e( 'Un video corto (mp4/webm o YouTube/Vimeo) que aparecerá arriba del panel para todos los usuarios.', 'arriendo-facil' ); ?></p>
				</div>
				<div class="af-modal__body">
					<p class="af-modal__status" id="af-hero-video-status"></p>
					<div class="af-modal__field">
						<label for="af-hero-video-url"><?php esc_html_e( 'URL del video', 'arriendo-facil' ); ?></label>
						<input type="url" id="af-hero-video-url" class="regular-text" style="width:100%;" value="<?php echo esc_attr( $hero_video_url ); ?>" placeholder="https://..." />
					</div>
				</div>
				<div class="af-modal__footer">
					<button type="button" class="button" id="af-hero-video-clear"><?php esc_html_e( 'Quitar video', 'arriendo-facil' ); ?></button>
					<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button type="button" class="button button-primary" id="af-hero-video-save"><?php esc_html_e( 'Guardar', 'arriendo-facil' ); ?></button>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( $is_owner && 0 === $accommodation_count ) : ?>
		<section class="af-welcome" aria-labelledby="af-welcome-title">
			<div class="af-welcome__art" aria-hidden="true">
				<svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M8 30l24-20 24 20v22a4 4 0 01-4 4H40V38H24v18H12a4 4 0 01-4-4V30z" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/>
					<circle cx="32" cy="24" r="3" fill="currentColor"/>
				</svg>
			</div>
			<div class="af-welcome__body">
				<span class="af-welcome__eyebrow"><?php esc_html_e( 'Bienvenido a Arriendo Fácil', 'arriendo-facil' ); ?></span>
				<h2 id="af-welcome-title" class="af-welcome__title">
					<?php esc_html_e( 'Publica tu primera propiedad en minutos', 'arriendo-facil' ); ?>
				</h2>
				<p class="af-welcome__subtitle">
					<?php esc_html_e( 'Añade fotos, describe los amenities y define tu tarifa. Nosotros te ayudamos a que aparezca donde importa.', 'arriendo-facil' ); ?>
				</p>
				<div class="af-welcome__actions">
					<a class="button af-btn af-btn--primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=accommodation' ) ); ?>">
						<?php esc_html_e( 'Publicar propiedad', 'arriendo-facil' ); ?>
					</a>
					<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-guests' ) ); ?>">
						<?php esc_html_e( 'Ver solicitudes de visita', 'arriendo-facil' ); ?>
					</a>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<div class="af-overview">

		<?php if ( $is_management_model ) : ?>

			<section class="af-overview-hero" aria-labelledby="af-overview-collected-title">
				<div class="af-overview-hero__main">
					<span class="af-overview-hero__eyebrow" id="af-overview-collected-title"><?php esc_html_e( 'Cobrado este mes', 'arriendo-facil' ); ?></span>
					<span class="af-overview-hero__value">$<?php echo esc_html( number_format_i18n( $period_paid, 2 ) ); ?></span>
					<span class="af-overview-hero__meta">
						<?php echo esc_html( sprintf( /* translators: %s: total charged for the period */ __( 'de $%s facturados', 'arriendo-facil' ), number_format_i18n( $period_charged, 2 ) ) ); ?>
					</span>
					<div class="af-overview-hero__bar" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: collection rate */ __( 'Tasa de cobro: %d%%', 'arriendo-facil' ), (int) $collection_rate ) ); ?>">
						<span style="width: <?php echo esc_attr( min( 100, max( 0, (float) $collection_rate ) ) ); ?>%"></span>
					</div>
					<div class="af-overview-hero__actions">
						<a class="af-overview-hero__cta" href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections' ) ); ?>">
							<?php esc_html_e( 'Ver cobranza', 'arriendo-facil' ); ?> <span aria-hidden="true">&rarr;</span>
						</a>
					</div>
				</div>
				<div class="af-overview-hero__side">
					<span class="af-overview-hero__rate"><?php echo esc_html( $collection_rate ); ?>%</span>
					<span class="af-overview-hero__rate-label"><?php esc_html_e( 'de cobro del periodo', 'arriendo-facil' ); ?></span>
					<span class="af-pill af-pill--hero"><?php echo esc_html( $current_period ); ?></span>
				</div>
			</section>

			<div class="af-overview-stats">
				<article class="af-overview-stat">
					<span class="af-overview-stat__icon" aria-hidden="true"><?php echo af_lucide( 'credit-card', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<span class="af-overview-stat__body">
						<span class="af-overview-stat__label"><?php esc_html_e( 'Por cobrar', 'arriendo-facil' ); ?></span>
						<span class="af-overview-stat__value">$<?php echo esc_html( number_format_i18n( $period_balance, 2 ) ); ?></span>
						<span class="af-overview-stat__hint"><?php esc_html_e( 'Saldo del periodo actual', 'arriendo-facil' ); ?></span>
					</span>
				</article>

				<article class="af-overview-stat <?php echo $overdue_count > 0 ? 'is-danger' : 'is-ok'; ?>">
					<span class="af-overview-stat__icon" aria-hidden="true"><?php echo af_lucide( 'circle-alert', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<span class="af-overview-stat__body">
						<span class="af-overview-stat__label"><?php esc_html_e( 'En mora', 'arriendo-facil' ); ?></span>
						<span class="af-overview-stat__value">$<?php echo esc_html( number_format_i18n( $overdue_amount, 2 ) ); ?></span>
						<span class="af-overview-stat__hint">
							<?php echo esc_html( sprintf( /* translators: %d: overdue charges count */ _n( '%d cargo vencido', '%d cargos vencidos', $overdue_count, 'arriendo-facil' ), $overdue_count ) ); ?>
						</span>
					</span>
					<?php if ( $overdue_count > 0 ) : ?>
						<a class="af-overview-stat__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections&charge_status=overdue' ) ); ?>"><?php esc_html_e( 'Gestionar', 'arriendo-facil' ); ?></a>
					<?php endif; ?>
				</article>

				<article class="af-overview-stat <?php echo $expiring_count > 0 ? 'is-warn' : ''; ?>">
					<span class="af-overview-stat__icon" aria-hidden="true"><?php echo af_lucide( 'calendar', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<span class="af-overview-stat__body">
						<span class="af-overview-stat__label"><?php esc_html_e( 'Contratos por vencer', 'arriendo-facil' ); ?></span>
						<span class="af-overview-stat__value"><?php echo esc_html( number_format_i18n( $expiring_count ) ); ?></span>
						<span class="af-overview-stat__hint"><?php esc_html_e( 'En los próximos 60 días', 'arriendo-facil' ); ?></span>
					</span>
					<?php if ( $expiring_count > 0 ) : ?>
						<a class="af-overview-stat__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>"><?php esc_html_e( 'Ver contratos', 'arriendo-facil' ); ?></a>
					<?php endif; ?>
				</article>
			</div>

		<?php endif; ?>

		<div class="af-overview-chips">
			<a class="af-overview-chip" href="<?php echo esc_url( admin_url( 'edit.php?post_type=accommodation' ) ); ?>">
				<span class="af-overview-chip__icon" aria-hidden="true"><?php echo af_lucide( 'building-2', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-overview-chip__value"><?php echo esc_html( number_format_i18n( $accommodation_count ) ); ?></span>
				<span class="af-overview-chip__label"><?php esc_html_e( 'Alojamientos', 'arriendo-facil' ); ?></span>
				<span class="af-overview-chip__meta">
					<?php echo esc_html( sprintf( /* translators: %d: active leases count */ _n( '%d activo', '%d activos', $active_leases, 'arriendo-facil' ), $active_leases ) ); ?>
				</span>
			</a>

			<a class="af-overview-chip <?php echo $draft_leases > 0 ? 'is-warn' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>">
				<span class="af-overview-chip__icon" aria-hidden="true"><?php echo af_lucide( 'file-text', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-overview-chip__value"><?php echo esc_html( number_format_i18n( $lease_count ) ); ?></span>
				<span class="af-overview-chip__label"><?php esc_html_e( 'Contratos', 'arriendo-facil' ); ?></span>
				<span class="af-overview-chip__meta">
					<?php
					if ( $draft_leases > 0 ) {
						echo esc_html( sprintf( /* translators: %d: draft leases */ _n( '%d borrador', '%d borradores', $draft_leases, 'arriendo-facil' ), $draft_leases ) );
					} else {
						esc_html_e( 'Todo al día', 'arriendo-facil' );
					}
					?>
				</span>
			</a>

			<a class="af-overview-chip <?php echo $maintenance_urgent_total > 0 ? 'is-danger' : ( $maintenance_open_total > 0 ? 'is-warn' : 'is-ok' ); ?>" href="<?php echo esc_url( admin_url( $is_management_model ? 'admin.php?page=af-maintenance' : 'admin.php?page=af-cleaning-requests' ) ); ?>">
				<span class="af-overview-chip__icon" aria-hidden="true"><?php echo af_lucide( 'wrench', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-overview-chip__value"><?php echo esc_html( number_format_i18n( $maintenance_open_total ) ); ?></span>
				<span class="af-overview-chip__label"><?php echo esc_html( $is_management_model ? __( 'Mantenimiento', 'arriendo-facil' ) : __( 'Limpiezas pendientes', 'arriendo-facil' ) ); ?></span>
				<span class="af-overview-chip__meta">
					<?php
					if ( $maintenance_urgent_total > 0 ) {
						esc_html_e( 'Prioridad crítica', 'arriendo-facil' );
					} elseif ( $maintenance_open_total > 0 ) {
						esc_html_e( 'Acción requerida', 'arriendo-facil' );
					} else {
						esc_html_e( 'Al día', 'arriendo-facil' );
					}
					?>
				</span>
			</a>

			<a class="af-overview-chip" href="<?php echo esc_url( admin_url( 'admin.php?page=af-reviews' ) ); ?>">
				<span class="af-overview-chip__icon" aria-hidden="true"><?php echo af_lucide( 'star', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-overview-chip__value">
					<?php echo esc_html( $review_count > 0 ? number_format_i18n( $avg_stars, 1 ) : '—' ); ?>
					<?php if ( $review_count > 0 ) : ?><small class="af-overview-chip__unit">/ 5</small><?php endif; ?>
				</span>
				<span class="af-overview-chip__label"><?php esc_html_e( 'Valoraciones', 'arriendo-facil' ); ?></span>
				<span class="af-overview-chip__meta">
					<?php echo esc_html( sprintf( /* translators: 1: review count, 2: positive % */ _n( '%1$d reseña · %2$d%% positivas', '%1$d reseñas · %2$d%% positivas', $review_count, 'arriendo-facil' ), $review_count, $positive_rate ) ); ?>
				</span>
			</a>

			<?php if ( ! $is_owner && null !== $active_contacts && defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) : ?>
				<a class="af-overview-chip" href="<?php echo esc_url( admin_url( 'admin.php?page=af-owner-contacts' ) ); ?>">
					<span class="af-overview-chip__icon" aria-hidden="true"><?php echo af_lucide( 'user-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<span class="af-overview-chip__value"><?php echo esc_html( number_format_i18n( $active_contacts ) ); ?></span>
					<span class="af-overview-chip__label"><?php esc_html_e( 'Propietarios', 'arriendo-facil' ); ?></span>
					<span class="af-overview-chip__meta"><?php esc_html_e( 'Contactos activos', 'arriendo-facil' ); ?></span>
				</a>
			<?php endif; ?>

			<a class="af-overview-chip <?php echo $pending_queue > 0 ? 'is-warn' : 'is-ok'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=af-guests' ) ); ?>">
				<span class="af-overview-chip__icon" aria-hidden="true"><?php echo af_lucide( 'users', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-overview-chip__value"><?php echo esc_html( number_format_i18n( $guest_count ) ); ?></span>
				<span class="af-overview-chip__label"><?php esc_html_e( 'Inquilinos', 'arriendo-facil' ); ?></span>
				<span class="af-overview-chip__meta">
					<?php
					if ( $is_management_model ) {
						echo esc_html( sprintf( /* translators: %d: tenants with pending documents */ _n( '%d con documentos pendientes', '%d con documentos pendientes', $docs_pending, 'arriendo-facil' ), $docs_pending ) );
					} elseif ( $pending_queue > 0 ) {
						echo esc_html( sprintf( /* translators: %d: pending queue */ _n( '%d en cola', '%d en cola', $pending_queue, 'arriendo-facil' ), $pending_queue ) );
					} else {
						esc_html_e( 'Al día', 'arriendo-facil' );
					}
					?>
				</span>
			</a>
		</div>

	</div>

	<div class="af-split af-charts-row">
		<section class="af-section" aria-labelledby="af-chart-occupancy-title">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-chart-occupancy-title"><?php esc_html_e( 'Resumen de ocupación', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'Disponibles, ocupadas y en mantenimiento.', 'arriendo-facil' ); ?></p>
				</div>
			</header>
			<div class="af-chart-canvas af-chart-canvas--donut">
				<canvas id="af-chart-occupancy" role="img" aria-label="<?php esc_attr_e( 'Gráfico de ocupación de propiedades', 'arriendo-facil' ); ?>"></canvas>
			</div>
		</section>

		<?php if ( $has_ledger ) : ?>
		<section class="af-section" aria-labelledby="af-chart-revenue-title">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-chart-revenue-title"><?php esc_html_e( 'Ingresos por arriendos', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'Cobrado en los últimos 6 meses.', 'arriendo-facil' ); ?></p>
				</div>
			</header>
			<div class="af-chart-canvas">
				<canvas id="af-chart-revenue" role="img" aria-label="<?php esc_attr_e( 'Gráfico de ingresos por arriendos', 'arriendo-facil' ); ?>"></canvas>
			</div>
		</section>
		<?php endif; ?>
	</div>

	<script type="application/json" id="af-dashboard-chart-data"><?php echo wp_json_encode( array( 'occupancy' => $occupancy_chart, 'revenue' => $revenue_chart ) ); ?></script>
	<script>
	document.addEventListener( 'DOMContentLoaded', function() {
		if ( typeof Chart === 'undefined' ) {
			return;
		}
		var dataEl = document.getElementById( 'af-dashboard-chart-data' );
		if ( ! dataEl ) {
			return;
		}
		var data = JSON.parse( dataEl.textContent );

		var occupancyEl = document.getElementById( 'af-chart-occupancy' );
		if ( occupancyEl ) {
			new Chart( occupancyEl, {
				type: 'doughnut',
				data: {
					labels: [ '<?php echo esc_js( __( 'Disponibles', 'arriendo-facil' ) ); ?>', '<?php echo esc_js( __( 'Ocupadas', 'arriendo-facil' ) ); ?>', '<?php echo esc_js( __( 'Mantenimiento', 'arriendo-facil' ) ); ?>' ],
					datasets: [ {
						data: [ data.occupancy.available, data.occupancy.occupied, data.occupancy.maintenance ],
						backgroundColor: [ '#CBD5E1', '#00A884', '#F59E0B' ],
						borderWidth: 0
					} ]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					cutout: '68%',
					plugins: { legend: { position: 'bottom' } }
				}
			} );
		}

		var revenueEl = document.getElementById( 'af-chart-revenue' );
		if ( revenueEl && data.revenue.labels.length ) {
			new Chart( revenueEl, {
				type: 'line',
				data: {
					labels: data.revenue.labels,
					datasets: [ {
						label: '<?php echo esc_js( __( 'Cobrado', 'arriendo-facil' ) ); ?>',
						data: data.revenue.values,
						borderColor: '#00A884',
						backgroundColor: 'rgba(0,168,132,0.12)',
						fill: true,
						tension: 0.35,
						pointRadius: 3
					} ]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: { y: { beginAtZero: true } }
				}
			} );
		}
	} );
	</script>

	<section class="af-section" aria-labelledby="af-recent-leases-title">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title" id="af-recent-leases-title"><?php esc_html_e( 'Contratos recientes', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Las últimas altas de contrato.', 'arriendo-facil' ); ?></p>
			</div>
			<a class="af-kpi__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>"><?php esc_html_e( 'Ver todos →', 'arriendo-facil' ); ?></a>
		</header>

		<?php if ( empty( $recent_leases ) ) : ?>
			<p class="af-empty__text"><?php esc_html_e( 'Aún no hay contratos registrados.', 'arriendo-facil' ); ?></p>
		<?php else : ?>
			<div class="af-semaforo__table" role="table" aria-label="<?php esc_attr_e( 'Contratos recientes', 'arriendo-facil' ); ?>">
				<?php foreach ( $recent_leases as $recent_lease ) : ?>
					<a class="af-semaforo__row" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>">
						<span class="af-semaforo__tenant">
							<strong><?php echo esc_html( trim( (string) $recent_lease->guest_name ) ? trim( (string) $recent_lease->guest_name ) : __( 'Inquilino', 'arriendo-facil' ) ); ?></strong>
							<small><?php echo esc_html( $recent_lease->accommodation_title ? $recent_lease->accommodation_title : '—' ); ?></small>
						</span>
						<?php echo af_pill( $recent_lease->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- af_pill() escapes internally. ?>
						<span class="af-semaforo__amount"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $recent_lease->end_date ) ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( $is_management_model && $has_ledger ) : ?>
	<section class="af-section af-semaforo" aria-labelledby="af-semaforo-title">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title" id="af-semaforo-title"><?php esc_html_e( 'Semáforo de cobros', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php echo esc_html( sprintf( /* translators: %s: period */ __( 'Estado de los cargos de %s en tiempo real.', 'arriendo-facil' ), $current_period ) ); ?></p>
			</div>
			<a class="af-kpi__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections' ) ); ?>"><?php esc_html_e( 'Ver control de pagos →', 'arriendo-facil' ); ?></a>
		</header>

		<div class="af-semaforo__lights" role="list">
			<article class="af-semaforo__light af-semaforo__light--success" role="listitem">
				<span class="af-semaforo__dot" aria-hidden="true"></span>
				<div>
					<span class="af-semaforo__label"><?php esc_html_e( 'Cobrado', 'arriendo-facil' ); ?></span>
					<span class="af-semaforo__value">$<?php echo esc_html( number_format_i18n( $semaforo['cobrado']['amount'], 2 ) ); ?></span>
					<span class="af-semaforo__meta"><?php echo esc_html( sprintf( /* translators: %d: charge count */ _n( '%d cargo', '%d cargos', $semaforo['cobrado']['count'], 'arriendo-facil' ), $semaforo['cobrado']['count'] ) ); ?></span>
				</div>
			</article>
			<article class="af-semaforo__light af-semaforo__light--warning" role="listitem">
				<span class="af-semaforo__dot" aria-hidden="true"></span>
				<div>
					<span class="af-semaforo__label"><?php esc_html_e( 'Pendiente', 'arriendo-facil' ); ?></span>
					<span class="af-semaforo__value">$<?php echo esc_html( number_format_i18n( $semaforo['pendiente']['amount'], 2 ) ); ?></span>
					<span class="af-semaforo__meta"><?php echo esc_html( sprintf( /* translators: %d: charge count */ _n( '%d cargo', '%d cargos', $semaforo['pendiente']['count'], 'arriendo-facil' ), $semaforo['pendiente']['count'] ) ); ?></span>
				</div>
			</article>
			<article class="af-semaforo__light af-semaforo__light--danger<?php echo $semaforo['atrasado']['count'] > 0 ? ' is-pulsing' : ''; ?>" role="listitem">
				<span class="af-semaforo__dot" aria-hidden="true"></span>
				<div>
					<span class="af-semaforo__label"><?php esc_html_e( 'Atrasado', 'arriendo-facil' ); ?></span>
					<span class="af-semaforo__value">$<?php echo esc_html( number_format_i18n( $semaforo['atrasado']['amount'], 2 ) ); ?></span>
					<span class="af-semaforo__meta"><?php echo esc_html( sprintf( /* translators: %d: charge count */ _n( '%d cargo', '%d cargos', $semaforo['atrasado']['count'], 'arriendo-facil' ), $semaforo['atrasado']['count'] ) ); ?></span>
				</div>
			</article>
		</div>

		<?php if ( ! empty( $top_mora ) ) : ?>
			<div class="af-semaforo__table" role="table" aria-label="<?php esc_attr_e( 'Inquilinos con mayor mora', 'arriendo-facil' ); ?>">
				<?php foreach ( $top_mora as $mora_row ) : ?>
					<?php
					$days = (int) $mora_row->days_overdue;
					$tier = $days > 60 ? 'danger' : ( $days > 30 ? 'warning' : 'neutral' );
					$due  = (float) $mora_row->amount - (float) $mora_row->amount_paid;
					?>
					<a class="af-semaforo__row" href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections&statement_lease=' . (int) $mora_row->lease_id ) ); ?>">
						<span class="af-semaforo__tenant">
							<strong><?php echo esc_html( trim( (string) $mora_row->guest_name ) ? trim( (string) $mora_row->guest_name ) : __( 'Inquilino', 'arriendo-facil' ) ); ?></strong>
							<small><?php echo esc_html( $mora_row->accommodation_title ? $mora_row->accommodation_title : '—' ); ?></small>
						</span>
						<span class="af-pill af-pill--<?php echo esc_attr( $tier ); ?>">
							<?php echo esc_html( sprintf( /* translators: %d: days overdue */ _n( '%d día de mora', '%d días de mora', $days, 'arriendo-facil' ), $days ) ); ?>
						</span>
						<span class="af-semaforo__amount">$<?php echo esc_html( number_format_i18n( $due, 2 ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="af-semaforo__empty"><?php esc_html_e( 'Ningún inquilino en mora. Excelente gestión de cobranza.', 'arriendo-facil' ); ?></p>
		<?php endif; ?>
	</section>
	<?php endif; ?>

	<section class="af-section" aria-labelledby="af-calendar-title" id="af-alerts">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title" id="af-calendar-title"><?php esc_html_e( 'Alertas operativas de calendario', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Visitas, check-in y check-out dentro del rango seleccionado.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

		<form method="get" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom: var(--af-space-4);">
			<input type="hidden" name="page" value="arriendo-facil" />
			<label style="display:flex; flex-direction:column; gap:4px; font-weight:600; font-size: var(--af-text-sm);">
				<?php esc_html_e( 'Desde', 'arriendo-facil' ); ?>
				<input type="date" name="af_date_from" value="<?php echo esc_attr( $calendar_from ); ?>" />
			</label>
			<label style="display:flex; flex-direction:column; gap:4px; font-weight:600; font-size: var(--af-text-sm);">
				<?php esc_html_e( 'Hasta', 'arriendo-facil' ); ?>
				<input type="date" name="af_date_to" value="<?php echo esc_attr( $calendar_to ); ?>" />
			</label>
			<?php if ( ! empty( $filter_admins ) ) : ?>
				<label style="display:flex; flex-direction:column; gap:4px; font-weight:600; font-size: var(--af-text-sm);">
					<?php esc_html_e( 'Administrador', 'arriendo-facil' ); ?>
					<select name="af_admin_id">
						<option value="0"><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
						<?php foreach ( $filter_admins as $admin_user ) : ?>
							<option value="<?php echo esc_attr( (int) $admin_user->ID ); ?>" <?php selected( $filter_admin_id, (int) $admin_user->ID ); ?>><?php echo esc_html( $admin_user->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<?php if ( ! empty( $calendar_buildings ) ) : ?>
				<label style="display:flex; flex-direction:column; gap:4px; font-weight:600; font-size: var(--af-text-sm);">
					<?php esc_html_e( 'Edificio', 'arriendo-facil' ); ?>
					<select name="af_building_id">
						<option value="0"><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
						<?php foreach ( $calendar_buildings as $building ) : ?>
							<option value="<?php echo esc_attr( (int) $building->id ); ?>" <?php selected( $calendar_building_id, (int) $building->id ); ?>><?php echo esc_html( $building->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<label style="display:flex; flex-direction:column; gap:4px; font-weight:600; font-size: var(--af-text-sm);">
				<?php esc_html_e( 'Propiedad', 'arriendo-facil' ); ?>
				<select name="af_accommodation_id">
					<option value="0"><?php esc_html_e( 'Todas', 'arriendo-facil' ); ?></option>
					<?php foreach ( $calendar_property_ids as $prop_id ) : ?>
						<option value="<?php echo esc_attr( (int) $prop_id ); ?>" <?php selected( $calendar_accommodation_id, (int) $prop_id ); ?>><?php echo esc_html( get_the_title( $prop_id ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Filtrar', 'arriendo-facil' ); ?></button>
		</form>

		<div class="af-calendar-columns af-split">
			<div>
				<h3 style="margin-top:0;"><?php esc_html_e( 'Próximos check-in (mudanza)', 'arriendo-facil' ); ?></h3>
				<?php if ( empty( $upcoming_checkins ) ) : ?>
					<p class="af-empty__text"><?php esc_html_e( 'Sin check-ins programados en el rango.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<div class="af-semaforo__table" role="table" aria-label="<?php esc_attr_e( 'Próximos check-in', 'arriendo-facil' ); ?>">
						<?php foreach ( $upcoming_checkins as $checkin ) : ?>
							<a class="af-semaforo__row" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>">
								<span class="af-semaforo__tenant">
									<strong><?php echo esc_html( trim( (string) $checkin->guest_name ) ? trim( (string) $checkin->guest_name ) : __( 'Inquilino', 'arriendo-facil' ) ); ?></strong>
									<small><?php echo esc_html( $checkin->accommodation_title ? $checkin->accommodation_title : '—' ); ?></small>
								</span>
								<span class="af-pill af-pill--info"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $checkin->start_date ) ) ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<div>
				<h3 style="margin-top:0;"><?php esc_html_e( 'Próximos check-out (salida)', 'arriendo-facil' ); ?></h3>
				<?php if ( empty( $upcoming_checkouts ) ) : ?>
					<p class="af-empty__text"><?php esc_html_e( 'Sin check-outs programados en el rango.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<div class="af-semaforo__table" role="table" aria-label="<?php esc_attr_e( 'Próximos check-out', 'arriendo-facil' ); ?>">
						<?php foreach ( $upcoming_checkouts as $checkout ) : ?>
							<a class="af-semaforo__row" href="<?php echo esc_url( admin_url( 'admin.php?page=af-upcoming-exits' ) ); ?>">
								<span class="af-semaforo__tenant">
									<strong><?php echo esc_html( trim( (string) $checkout->guest_name ) ? trim( (string) $checkout->guest_name ) : __( 'Inquilino', 'arriendo-facil' ) ); ?></strong>
									<small><?php echo esc_html( $checkout->accommodation_title ? $checkout->accommodation_title : '—' ); ?></small>
								</span>
								<span class="af-pill af-pill--warning"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $checkout->end_date ) ) ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<div>
				<h3 style="margin-top:0;"><?php esc_html_e( 'Visitas agendadas', 'arriendo-facil' ); ?></h3>
				<?php if ( empty( $upcoming_visits ) ) : ?>
					<p class="af-empty__text"><?php esc_html_e( 'Sin visitas confirmadas en el rango.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<div class="af-semaforo__table" role="table" aria-label="<?php esc_attr_e( 'Visitas agendadas', 'arriendo-facil' ); ?>">
						<?php foreach ( $upcoming_visits as $visit ) : ?>
							<a class="af-semaforo__row" href="<?php echo esc_url( admin_url( 'admin.php?page=af-guests' ) ); ?>">
								<span class="af-semaforo__tenant">
									<strong><?php echo esc_html( trim( (string) $visit->guest_name ) ? trim( (string) $visit->guest_name ) : __( 'Visitante', 'arriendo-facil' ) ); ?></strong>
									<small><?php echo esc_html( $visit->accommodation_title ? $visit->accommodation_title : '—' ); ?></small>
								</span>
								<span class="af-pill af-pill--info"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $visit->visit_date . ' ' . $visit->start_time ) ) ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</section>

	<section class="af-section af-schedule" aria-labelledby="af-schedule-title">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title" id="af-schedule-title"><?php esc_html_e( 'Contratos por vencer — 30/60/90', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Cronograma de próximas salidas para anticipar renovaciones y liquidar garantías.', 'arriendo-facil' ); ?></p>
			</div>
			<a class="af-kpi__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-upcoming-exits' ) ); ?>"><?php esc_html_e( 'Ver próximas salidas →', 'arriendo-facil' ); ?></a>
		</header>

		<div class="af-schedule__buckets" role="list">
			<?php foreach ( array( '30' => 'danger', '60' => 'warning', '90' => 'neutral' ) as $bucket_key => $bucket_tone ) : ?>
				<article class="af-schedule__bucket af-schedule__bucket--<?php echo esc_attr( $bucket_tone ); ?>" role="listitem">
					<span class="af-schedule__days"><?php echo esc_html( sprintf( /* translators: %s: days range */ __( '≤ %s días', 'arriendo-facil' ), $bucket_key ) ); ?></span>
					<span class="af-schedule__count"><?php echo esc_html( number_format_i18n( $exit_buckets[ $bucket_key ] ) ); ?></span>
					<?php
					$bucket_label = '30' === $bucket_key
						? __( 'Decisión inmediata', 'arriendo-facil' )
						: ( '60' === $bucket_key ? __( 'Iniciar renovación', 'arriendo-facil' ) : __( 'Planificación', 'arriendo-facil' ) );
					?>
					<span class="af-schedule__label"><?php echo esc_html( $bucket_label ); ?></span>
				</article>
			<?php endforeach; ?>
		</div>

		<?php if ( ! empty( $exit_cards ) ) : ?>
			<div class="af-schedule__list" role="table" aria-label="<?php esc_attr_e( 'Próximos vencimientos', 'arriendo-facil' ); ?>">
				<?php foreach ( $exit_cards as $exit_card ) : ?>
					<?php
					$lease   = $exit_card['lease'];
					$urgency = '30' === $exit_card['bucket'] ? 'af-pill--danger' : ( '60' === $exit_card['bucket'] ? 'af-pill--warning' : 'af-pill--neutral' );
					?>
					<a class="af-semaforo__row" href="<?php echo esc_url( admin_url( 'admin.php?page=af-upcoming-exits' ) ); ?>">
						<span class="af-semaforo__tenant">
							<strong><?php echo esc_html( $lease->accommodation_title ? $lease->accommodation_title : '#' . (int) $lease->accommodation_id ); ?></strong>
							<small><?php echo esc_html( trim( (string) $lease->guest_name ) ? trim( (string) $lease->guest_name ) : __( 'Sin inquilino', 'arriendo-facil' ) ); ?></small>
						</span>
						<span class="af-pill <?php echo esc_attr( $urgency ); ?>">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: days remaining */
									_n( 'Vence en %d día', 'Vence en %d días', $exit_card['days'], 'arriendo-facil' ),
									$exit_card['days']
								)
							);
							?>
						</span>
						<span class="af-semaforo__amount"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $lease->end_date ) ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<div class="af-split">

		<section class="af-section" aria-labelledby="af-dashboard-focus">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-dashboard-focus">
						<?php echo esc_html( $is_owner ? __( 'Tus propiedades', 'arriendo-facil' ) : __( 'Accesos rápidos', 'arriendo-facil' ) ); ?>
					</h2>
					<p class="af-section__subtitle">
						<?php
						echo esc_html(
							$is_owner
								? __( 'Un vistazo a lo que tienes publicado. Toca una tarjeta para gestionarla.', 'arriendo-facil' )
								: __( 'Las acciones que más usas para operar la plataforma.', 'arriendo-facil' )
						);
						?>
					</p>
				</div>
			</header>

			<?php if ( $is_owner ) : ?>

				<?php if ( ! empty( $owner_properties ) ) : ?>
					<div class="af-property-grid">
						<?php
						foreach ( $owner_properties as $prop ) :
							$thumb  = get_the_post_thumbnail_url( (int) $prop->ID, 'medium_large' );
							$rent   = $prop->monthly_rent ? (float) $prop->monthly_rent : 0;
							$status = $prop->availability ? (string) $prop->availability : 'available';
							$status_pill = 'af-pill--success';
							$status_lbl  = __( 'Disponible', 'arriendo-facil' );
							if ( 'rented' === $status || 'occupied' === $status ) {
								$status_pill = 'af-pill--danger';
								$status_lbl  = __( 'Ocupado', 'arriendo-facil' );
							} elseif ( 'maintenance' === $status ) {
								$status_pill = 'af-pill--warning';
								$status_lbl  = __( 'En mantenimiento', 'arriendo-facil' );
							} elseif ( 'inactive' === $status ) {
								$status_pill = 'af-pill--neutral';
								$status_lbl  = __( 'Inactivo', 'arriendo-facil' );
							}
							?>
							<a class="af-property-card" href="<?php echo esc_url( get_edit_post_link( (int) $prop->ID ) ); ?>">
								<div class="af-property-card__media">
									<?php if ( $thumb ) : ?>
										<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $prop->post_title ); ?>" loading="lazy" />
									<?php else : ?>
										<div class="af-property-card__placeholder" aria-hidden="true">
											<svg width="42" height="42" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 11l9-8 9 8v10a1 1 0 01-1 1h-5v-6H10v6H4a1 1 0 01-1-1V11z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
										</div>
									<?php endif; ?>
									<div class="af-property-card__badges">
										<span class="af-pill <?php echo esc_attr( $status_pill ); ?>"><?php echo esc_html( $status_lbl ); ?></span>
									</div>
								</div>
								<div class="af-property-card__body">
									<h3 class="af-property-card__title"><?php echo esc_html( $prop->post_title ); ?></h3>
									<p class="af-property-card__meta"><?php esc_html_e( 'Toca para ver detalles y actividad', 'arriendo-facil' ); ?></p>
									<div class="af-property-card__footer">
										<span class="af-property-card__price">
											<?php echo $rent > 0 ? esc_html( '$' . number_format_i18n( $rent, 2 ) ) : '—'; ?>
											<?php if ( $rent > 0 ) : ?>
												<small><?php esc_html_e( '/ mes', 'arriendo-facil' ); ?></small>
											<?php endif; ?>
										</span>
										<span class="af-kpi__link"><?php esc_html_e( 'Editar', 'arriendo-facil' ); ?></span>
									</div>
								</div>
							</a>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<div class="af-empty">
						<span class="af-empty__icon" aria-hidden="true">
							<svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 11l9-8 9 8v10a1 1 0 01-1 1h-5v-6H10v6H4a1 1 0 01-1-1V11z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
						</span>
						<h3 class="af-empty__title"><?php esc_html_e( 'Aún no tienes propiedades', 'arriendo-facil' ); ?></h3>
						<p class="af-empty__text"><?php esc_html_e( 'Publica tu primer alojamiento y comienza a recibir huéspedes verificados en cuestión de días.', 'arriendo-facil' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=accommodation' ) ); ?>" class="button af-btn af-btn--primary">
							<?php esc_html_e( 'Registrar mi primera propiedad', 'arriendo-facil' ); ?>
						</a>
					</div>
				<?php endif; ?>

			<?php else : ?>

				<div class="af-property-grid">
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=accommodation' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( '+ Nuevo alojamiento', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Publicar una propiedad en la plataforma.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( 'Gestionar contratos', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Ver, activar y facturar contratos vigentes.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'admin.php?page=af-cleaning-requests' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( 'Solicitudes de limpieza', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Asigna, programa y da seguimiento.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'admin.php?page=af-billing' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( 'Facturación electrónica', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Emitir y firmar comprobantes SRI del período.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'admin.php?page=af-ota-sync-dashboard' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( 'Sincronización OTA', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Airbnb y Booking en tiempo real.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'admin.php?page=af-ai-settings' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( 'Ajustes de IA', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Modelos y credenciales para automatización.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
				</div>

			<?php endif; ?>
		</section>

		<div class="af-aside-stack">
		<aside class="af-section" aria-labelledby="af-dashboard-tasks">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-dashboard-tasks"><?php esc_html_e( 'Requiere tu atención', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'Prioridad del día. Toca para resolver.', 'arriendo-facil' ); ?></p>
				</div>
			</header>

			<?php if ( ! empty( $tasks ) ) : ?>
				<ul class="af-tasklist">
					<?php foreach ( $tasks as $task ) : ?>
						<li>
							<a class="af-tasklist__item" href="<?php echo esc_url( $task['url'] ); ?>">
								<span class="af-tasklist__badge"><?php echo esc_html( number_format_i18n( $task['count'] ) ); ?></span>
								<span class="af-tasklist__label"><?php echo esc_html( $task['label'] ); ?></span>
								<span class="af-tasklist__arrow" aria-hidden="true">→</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<div class="af-empty" style="padding: var(--af-space-6) var(--af-space-4);">
					<span class="af-empty__icon" aria-hidden="true">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12l4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</span>
					<h3 class="af-empty__title"><?php esc_html_e( 'Todo bajo control', 'arriendo-facil' ); ?></h3>
					<p class="af-empty__text"><?php esc_html_e( 'No hay pendientes urgentes. Buen momento para revisar reseñas o programar limpiezas.', 'arriendo-facil' ); ?></p>
				</div>
			<?php endif; ?>
		</aside>

		<aside class="af-section" aria-labelledby="af-recent-reviews-title">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-recent-reviews-title"><?php esc_html_e( 'Reviews recientes', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'Últimas calificaciones registradas a inquilinos.', 'arriendo-facil' ); ?></p>
				</div>
				<a class="af-kpi__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-reviews' ) ); ?>"><?php esc_html_e( 'Ver todas →', 'arriendo-facil' ); ?></a>
			</header>

			<?php if ( empty( $recent_reviews ) ) : ?>
				<p class="af-empty__text"><?php esc_html_e( 'Aún no hay calificaciones registradas.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<ul class="af-review-list">
					<?php foreach ( $recent_reviews as $recent_review ) : ?>
						<li class="af-review-list__item">
							<div class="af-review-list__head">
								<strong><?php echo esc_html( trim( (string) $recent_review->guest_name ) ? trim( (string) $recent_review->guest_name ) : __( 'Inquilino', 'arriendo-facil' ) ); ?></strong>
								<span class="af-review-list__stars" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: stars */ __( '%d de 5 estrellas', 'arriendo-facil' ), (int) $recent_review->stars ) ); ?>">
									<?php echo esc_html( str_repeat( '★', (int) $recent_review->stars ) . str_repeat( '☆', 5 - (int) $recent_review->stars ) ); ?>
								</span>
							</div>
							<p class="af-review-list__meta"><?php echo esc_html( $recent_review->accommodation_title ? $recent_review->accommodation_title : '—' ); ?></p>
							<?php if ( ! empty( $recent_review->comment_text ) ) : ?>
								<p class="af-review-list__comment"><?php echo esc_html( wp_trim_words( $recent_review->comment_text, 16 ) ); ?></p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</aside>
		</div>

	</div>

</div>

<?php if ( current_user_can( 'manage_options' ) ) : ?>
<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_dashboard_hero_video_nonce' ) ); ?>;

	function post(payload) {
		const body = new URLSearchParams();
		Object.keys(payload).forEach((k) => body.append(k, payload[k]));
		body.append('action', 'af_save_dashboard_hero_video');
		body.append('nonce', nonce);
		return fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then((r) => r.json());
	}

	function openModal(modal) {
		modal.classList.add('is-open');
		const focusable = modal.querySelector('input, button.button-primary');
		if (focusable) { focusable.focus(); }
	}

	function closeModal(modal) {
		modal.classList.remove('is-open');
	}

	const modal = document.getElementById('af-hero-video-modal');
	if (!modal) { return; }

	modal.querySelectorAll('[data-af-modal-close]').forEach(function (btn) {
		btn.addEventListener('click', function () { closeModal(modal); });
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { closeModal(modal); }
	});

	document.querySelectorAll('#af-hero-video-edit').forEach(function (btn) {
		btn.addEventListener('click', function () { openModal(modal); });
	});

	const status = document.getElementById('af-hero-video-status');
	const input  = document.getElementById('af-hero-video-url');

	function setStatus(message, type) {
		status.textContent = message;
		status.className = 'af-modal__status is-' + type;
	}

	document.getElementById('af-hero-video-save').addEventListener('click', function () {
		post({ video_url: input.value.trim() }).then((res) => {
			if (res && res.success) {
				window.location.reload();
			} else {
				setStatus((res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
			}
		});
	});

	document.getElementById('af-hero-video-clear').addEventListener('click', function () {
		input.value = '';
		post({ video_url: '' }).then((res) => {
			if (res && res.success) {
				window.location.reload();
			} else {
				setStatus((res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
			}
		});
	});
})();
</script>
<?php endif; ?>

