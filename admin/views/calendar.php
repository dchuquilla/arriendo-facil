<?php
/**
 * Standalone interactive calendar admin page (same host calendar as the Panel).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var wpdb $wpdb */
global $wpdb;

// ── Alcance del usuario ───────────────────────────────────────────────────
$scope_ids       = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
$filter_admin_id = 0;
$filter_admins   = array();
if ( Arriendo_Facil_Tenancy::can_manage_all() ) {
	$filter_admins   = Arriendo_Facil_Tenancy::get_property_admins();
	$filter_admin_id = isset( $_GET['af_admin_id'] ) ? absint( wp_unslash( $_GET['af_admin_id'] ) ) : 0;
	if ( $filter_admin_id ) {
		$admin_scope_ids = array_map( 'absint', Arriendo_Facil_Accommodation::get_owner_accommodation_ids( $filter_admin_id ) );
		$scope_ids       = is_array( $scope_ids ) ? array_intersect( $scope_ids, $admin_scope_ids ) : $admin_scope_ids;
	}
}

// ── Filtros de rango ──────────────────────────────────────────────────────
$calendar_from = isset( $_GET['af_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['af_date_from'] ) ) : gmdate( 'Y-m-d' );
$calendar_to   = isset( $_GET['af_date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['af_date_to'] ) ) : gmdate( 'Y-m-d', strtotime( '+30 days' ) );
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
	$building_units     = Arriendo_Facil_Property_Structure::get_units_by_building( $calendar_building_id );
	$building_accom_ids = array_filter( array_map( static function ( $unit ) { return (int) $unit->accommodation_id; }, (array) $building_units ) );
	$calendar_scope_ids = is_array( $calendar_scope_ids ) ? array_intersect( $calendar_scope_ids, $building_accom_ids ) : $building_accom_ids;
}
if ( $calendar_accommodation_id ) {
	$calendar_scope_ids = is_array( $calendar_scope_ids ) ? array_intersect( $calendar_scope_ids, array( $calendar_accommodation_id ) ) : array( $calendar_accommodation_id );
}

$calendar_scope_clause     = is_array( $calendar_scope_ids ) ? ' AND l.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $calendar_scope_ids ) . ')' : '';
$calendar_visits_scope_clause = is_array( $calendar_scope_ids ) ? ' AND vb.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $calendar_scope_ids ) . ')' : '';

// ── Listas próximas (check-in / check-out / visitas) ─────────────────────
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

$upcoming_visits = (array) $wpdb->get_results(
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

// ── Opciones de los filtros ──────────────────────────────────────────────
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

$calendar_month_anchor = $calendar_from;
?>
<div class="wrap af-shell af-calendar-page">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Operación', 'arriendo-facil' ),
			'title'    => __( 'Calendario', 'arriendo-facil' ),
			'subtitle' => __( 'Visitas, entradas, salidas y días bloqueados en un solo lugar. Selecciona un día para gestionarlo.', 'arriendo-facil' ),
		)
	);
	?>

	<section class="af-section" aria-labelledby="af-calendar-page-title">
		<header class="af-section__header">
			<div class="af-section__head">
				<span class="af-section__icon" aria-hidden="true"><?php echo af_lucide( 'calendar', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<div>
					<h2 class="af-section__title" id="af-calendar-page-title"><?php esc_html_e( 'Agenda operativa', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'Filtra por rango, edificio o propiedad y programa visitas o bloqueos sin salir de aquí.', 'arriendo-facil' ); ?></p>
				</div>
			</div>
		</header>

		<form class="af-calendar-filters" method="get">
			<input type="hidden" name="page" value="af-calendar" />
			<div class="af-calendar-filters__group">
				<label class="af-calendar-filters__field">
					<span><?php esc_html_e( 'Desde', 'arriendo-facil' ); ?></span>
					<input type="date" name="af_date_from" value="<?php echo esc_attr( $calendar_from ); ?>" />
				</label>
				<label class="af-calendar-filters__field">
					<span><?php esc_html_e( 'Hasta', 'arriendo-facil' ); ?></span>
					<input type="date" name="af_date_to" value="<?php echo esc_attr( $calendar_to ); ?>" />
				</label>
				<?php if ( ! empty( $filter_admins ) ) : ?>
					<label class="af-calendar-filters__field">
						<span><?php esc_html_e( 'Administrador', 'arriendo-facil' ); ?></span>
						<select name="af_admin_id">
							<option value="0"><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
							<?php foreach ( $filter_admins as $admin_user ) : ?>
								<option value="<?php echo esc_attr( (int) $admin_user->ID ); ?>" <?php selected( $filter_admin_id, (int) $admin_user->ID ); ?>><?php echo esc_html( $admin_user->display_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<?php if ( ! empty( $calendar_buildings ) ) : ?>
					<label class="af-calendar-filters__field">
						<span><?php esc_html_e( 'Edificio', 'arriendo-facil' ); ?></span>
						<select name="af_building_id">
							<option value="0"><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
							<?php foreach ( $calendar_buildings as $building ) : ?>
								<option value="<?php echo esc_attr( (int) $building->id ); ?>" <?php selected( $calendar_building_id, (int) $building->id ); ?>><?php echo esc_html( $building->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<label class="af-calendar-filters__field">
					<span><?php esc_html_e( 'Propiedad', 'arriendo-facil' ); ?></span>
					<select name="af_accommodation_id">
						<option value="0"><?php esc_html_e( 'Todas', 'arriendo-facil' ); ?></option>
						<?php foreach ( $calendar_property_ids as $prop_id ) : ?>
							<option value="<?php echo esc_attr( (int) $prop_id ); ?>" <?php selected( $calendar_accommodation_id, (int) $prop_id ); ?>><?php echo esc_html( get_the_title( $prop_id ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Filtrar', 'arriendo-facil' ); ?></button>
		</form>

		<?php include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/partials/host-calendar.php'; ?>

		<div class="af-calendar-cols">
			<article class="af-calendar-col af-calendar-col--in">
				<header class="af-calendar-col__head">
					<span class="af-calendar-col__icon" aria-hidden="true"><?php echo af_lucide( 'log-in', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<div class="af-calendar-col__title">
						<h3><?php esc_html_e( 'Próximos check-in (mudanza)', 'arriendo-facil' ); ?></h3>
						<span class="af-calendar-col__count"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d programado', '%d programados', count( $upcoming_checkins ), 'arriendo-facil' ), count( $upcoming_checkins ) ) ); ?></span>
					</div>
				</header>
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
			</article>

			<article class="af-calendar-col af-calendar-col--out">
				<header class="af-calendar-col__head">
					<span class="af-calendar-col__icon" aria-hidden="true"><?php echo af_lucide( 'log-out', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<div class="af-calendar-col__title">
						<h3><?php esc_html_e( 'Próximos check-out (salida)', 'arriendo-facil' ); ?></h3>
						<span class="af-calendar-col__count"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d programado', '%d programados', count( $upcoming_checkouts ), 'arriendo-facil' ), count( $upcoming_checkouts ) ) ); ?></span>
					</div>
				</header>
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
			</article>

			<article class="af-calendar-col af-calendar-col--visit">
				<header class="af-calendar-col__head">
					<span class="af-calendar-col__icon" aria-hidden="true"><?php echo af_lucide( 'user-plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<div class="af-calendar-col__title">
						<h3><?php esc_html_e( 'Visitas agendadas', 'arriendo-facil' ); ?></h3>
						<span class="af-calendar-col__count"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d agendada', '%d agendadas', count( $upcoming_visits ), 'arriendo-facil' ), count( $upcoming_visits ) ) ); ?></span>
					</div>
				</header>
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
			</article>
		</div>
	</section>

</div>