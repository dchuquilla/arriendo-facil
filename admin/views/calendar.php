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

$calendar_visits_scope_clause = is_array( $calendar_scope_ids ) ? ' AND vb.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $calendar_scope_ids ) . ')' : '';

// ── Listas próximas (mudanzas registradas / salidas según contratos / visitas) ──
$upcoming_moves     = Arriendo_Facil_Calendar::upcoming_moves( $calendar_from, $calendar_to, $calendar_scope_ids, 20 );
$upcoming_checkouts = Arriendo_Facil_Calendar::upcoming_checkouts( $calendar_from, $calendar_to, $calendar_scope_ids, 20 );
$calendar_today     = current_time( 'Y-m-d' );
$legal_statuses     = Arriendo_Facil_Lease_Operations::legal_statuses();

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
			'subtitle' => __( 'Visitas, mudanzas, salidas de contratos, vencimientos de servicios y días bloqueados en un solo lugar. Selecciona un día para gestionarlo.', 'arriendo-facil' ),
			'actions'  => array(
				'<button type="button" class="button af-btn af-btn--primary" data-cal-open="move">' . af_lucide( 'plus', 16 ) . esc_html__( 'Registrar mudanza', 'arriendo-facil' ) . '</button>',
				'<button type="button" class="button af-btn af-btn--ghost" data-cal-open="visit">' . af_lucide( 'user-plus', 16 ) . esc_html__( 'Agendar visita', 'arriendo-facil' ) . '</button>',
			),
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
						<span><?php esc_html_e( 'Gestor', 'arriendo-facil' ); ?></span>
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

		<div class="af-calendar-cols" data-from="<?php echo esc_attr( $calendar_from ); ?>" data-to="<?php echo esc_attr( $calendar_to ); ?>">
			<article class="af-calendar-col af-calendar-col--in">
				<header class="af-calendar-col__head">
					<span class="af-calendar-col__icon" aria-hidden="true"><?php echo af_lucide( 'log-in', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<div class="af-calendar-col__title">
						<h3><?php esc_html_e( 'Próximas mudanzas (check-in)', 'arriendo-facil' ); ?></h3>
						<span class="af-calendar-col__count"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d registrada', '%d registradas', count( $upcoming_moves ), 'arriendo-facil' ), count( $upcoming_moves ) ) ); ?></span>
					</div>
				</header>
				<?php if ( empty( $upcoming_moves ) ) : ?>
					<p class="af-empty__text"><?php esc_html_e( 'Sin mudanzas registradas en el rango.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<div class="af-semaforo__table" role="table" aria-label="<?php esc_attr_e( 'Próximas mudanzas', 'arriendo-facil' ); ?>">
						<?php foreach ( $upcoming_moves as $move ) : ?>
							<?php
							$move_tasks = Arriendo_Facil_Calendar::move_checklist( $move );
							$move_done  = count( array_filter( wp_list_pluck( $move_tasks, 'done' ) ) );
							$move_when  = wp_date( 'd/m/Y', strtotime( $move->move_date ) ) . ( $move->start_time ? ' ' . substr( (string) $move->start_time, 0, 5 ) : '' );
							?>
							<div class="af-semaforo__row">
								<span class="af-semaforo__tenant">
									<strong><?php echo esc_html( $move->contact_name ? $move->contact_name : __( 'Inquilino', 'arriendo-facil' ) ); ?></strong>
									<small>
										<?php echo esc_html( $move->accommodation_title ? $move->accommodation_title : '—' ); ?>
										<?php if ( $move_tasks ) : ?>
											· <?php echo esc_html( sprintf( /* translators: 1: done, 2: total */ __( '%1$d/%2$d tareas', 'arriendo-facil' ), $move_done, count( $move_tasks ) ) ); ?>
										<?php endif; ?>
									</small>
								</span>
								<span class="af-pill af-pill--<?php echo esc_attr( 'done' === $move->status ? 'success' : 'info' ); ?>"><?php echo esc_html( 'done' === $move->status ? __( 'Realizada', 'arriendo-facil' ) : $move_when ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<button type="button" class="button af-btn af-btn--ghost af-calendar-col__cta" data-cal-open="move"><?php esc_html_e( '+ Registrar mudanza', 'arriendo-facil' ); ?></button>
			</article>

			<article class="af-calendar-col af-calendar-col--out">
				<header class="af-calendar-col__head">
					<span class="af-calendar-col__icon" aria-hidden="true"><?php echo af_lucide( 'log-out', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<div class="af-calendar-col__title">
						<h3><?php esc_html_e( 'Próximos a salir (según contratos)', 'arriendo-facil' ); ?></h3>
						<span class="af-calendar-col__count"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d contrato vence', '%d contratos vencen', count( $upcoming_checkouts ), 'arriendo-facil' ), count( $upcoming_checkouts ) ) ); ?></span>
					</div>
				</header>
				<?php if ( empty( $upcoming_checkouts ) ) : ?>
					<p class="af-empty__text"><?php esc_html_e( 'Ningún contrato activo termina en el rango.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<div class="af-semaforo__table" role="table" aria-label="<?php esc_attr_e( 'Próximos a salir', 'arriendo-facil' ); ?>">
						<?php foreach ( $upcoming_checkouts as $checkout ) : ?>
							<?php
							$exit_days    = max( 0, (int) floor( ( strtotime( $checkout->end_date ) - strtotime( $calendar_today ) ) / DAY_IN_SECONDS ) );
							$exit_tone    = $exit_days <= 30 ? 'danger' : ( $exit_days <= 60 ? 'warning' : 'neutral' );
							$exit_legal   = $checkout->legal_status ? (string) $checkout->legal_status : 'pendiente';
							$exit_title   = $checkout->accommodation_title ? $checkout->accommodation_title : '#' . (int) $checkout->accommodation_id;
							?>
							<div class="af-semaforo__row af-exit-row">
								<span class="af-semaforo__tenant">
									<strong><?php echo esc_html( trim( (string) $checkout->guest_name ) ? trim( (string) $checkout->guest_name ) : __( 'Inquilino', 'arriendo-facil' ) ); ?></strong>
									<small><?php echo esc_html( $exit_title . ' · ' . ( $legal_statuses[ $exit_legal ] ?? $exit_legal ) ); ?></small>
								</span>
								<span class="af-pill af-pill--<?php echo esc_attr( $exit_tone ); ?>" title="<?php echo esc_attr( wp_date( 'd/m/Y', strtotime( $checkout->end_date ) ) ); ?>">
									<?php echo esc_html( 0 === $exit_days ? __( 'Vence hoy', 'arriendo-facil' ) : sprintf( /* translators: %d: days remaining */ _n( 'En %d día', 'En %d días', $exit_days, 'arriendo-facil' ), $exit_days ) ); ?>
								</span>
								<span class="af-exit-row__actions">
									<button type="button" class="af-cal__link af-exit-open-renew"
										data-lease="<?php echo esc_attr( (int) $checkout->id ); ?>"
										data-title="<?php echo esc_attr( $exit_title ); ?>"
										data-min-date="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( $checkout->end_date . ' +1 day' ) ) ); ?>"><?php esc_html_e( 'Renovar', 'arriendo-facil' ); ?></button>
									<button type="button" class="af-cal__link af-exit-open-legal"
										data-lease="<?php echo esc_attr( (int) $checkout->id ); ?>"
										data-title="<?php echo esc_attr( $exit_title ); ?>"
										data-status="<?php echo esc_attr( $exit_legal ); ?>"
										data-notes="<?php echo esc_attr( (string) $checkout->legal_notes ); ?>"><?php esc_html_e( 'Estado legal', 'arriendo-facil' ); ?></button>
								</span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<a class="button af-btn af-btn--ghost af-calendar-col__cta" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>"><?php esc_html_e( 'Ver contratos', 'arriendo-facil' ); ?></a>
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

<!-- Modal: renovar contrato -->
<div class="af-modal" id="af-modal-renew" role="dialog" aria-modal="true" aria-labelledby="af-modal-renew-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-renew-title"><?php esc_html_e( 'Renovar contrato', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-modal-renew-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-modal-renew-status"></p>
			<div class="af-modal__field">
				<label for="af-renew-end-date"><?php esc_html_e( 'Nueva fecha de fin', 'arriendo-facil' ); ?></label>
				<input type="date" id="af-renew-end-date" required />
			</div>
			<div class="af-modal__field">
				<label for="af-renew-reason"><?php esc_html_e( 'Motivo de la renovación', 'arriendo-facil' ); ?></label>
				<textarea id="af-renew-reason" rows="3" required></textarea>
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-renew-save"><?php esc_html_e( 'Renovar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<!-- Modal: estado legal -->
<div class="af-modal" id="af-modal-legal" role="dialog" aria-modal="true" aria-labelledby="af-modal-legal-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-legal-title"><?php esc_html_e( 'Estado legal del contrato', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-modal-legal-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-modal-legal-status-msg"></p>
			<div class="af-modal__field">
				<label for="af-legal-select"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></label>
				<select id="af-legal-select">
					<?php foreach ( $legal_statuses as $legal_key => $legal_label ) : ?>
						<option value="<?php echo esc_attr( $legal_key ); ?>"><?php echo esc_html( $legal_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="af-modal__field">
				<label for="af-legal-notes"><?php esc_html_e( 'Referencia o nota (notaría, número de trámite, etc.)', 'arriendo-facil' ); ?></label>
				<input type="text" id="af-legal-notes" />
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-legal-save"><?php esc_html_e( 'Guardar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<script>
(function () {
	const ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const leaseNonce = <?php echo wp_json_encode( wp_create_nonce( 'af_lease_nonce' ) ); ?>;
	const opsNonce   = <?php echo wp_json_encode( wp_create_nonce( 'af_lease_operations_nonce' ) ); ?>;
	const modals     = ['af-modal-renew', 'af-modal-legal'].map(function (id) { return document.getElementById(id); });

	function post(action, nonce, payload) {
		const body = new URLSearchParams();
		Object.keys(payload).forEach(function (k) { body.append(k, payload[k]); });
		body.append('action', action);
		body.append('nonce', nonce);
		return fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then(function (r) { return r.json(); });
	}

	function openModal(modal) {
		modal.classList.add('is-open');
		document.body.classList.add('af-modal-open');
		const focusable = modal.querySelector('input, select, textarea, button.button-primary');
		if (focusable) { focusable.focus(); }
	}

	function closeModal(modal) {
		modal.classList.remove('is-open');
		document.body.classList.remove('af-modal-open');
	}

	modals.forEach(function (modal) {
		modal.querySelectorAll('[data-af-modal-close]').forEach(function (btn) {
			btn.addEventListener('click', function () { closeModal(modal); });
		});
	});
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		modals.forEach(closeModal);
	});

	function setStatus(el, message, isError) {
		el.textContent = message;
		el.className = 'af-modal__status ' + (isError ? 'is-error' : 'is-success');
	}

	function done(statusEl) {
		return function (res) {
			if (res && res.success) { window.location.reload(); return; }
			setStatus(statusEl, (res && res.data && res.data.message) || <?php echo wp_json_encode( __( 'No se pudo completar la operación.', 'arriendo-facil' ) ); ?>, true);
		};
	}

	// ---- Renovar ----
	const renewModal = document.getElementById('af-modal-renew');
	document.querySelectorAll('.af-exit-open-renew').forEach(function (btn) {
		btn.addEventListener('click', function () {
			document.getElementById('af-modal-renew-subtitle').textContent = btn.dataset.title || '';
			document.getElementById('af-renew-end-date').min = btn.dataset.minDate || '';
			document.getElementById('af-renew-end-date').value = btn.dataset.minDate || '';
			document.getElementById('af-renew-reason').value = '';
			document.getElementById('af-modal-renew-status').textContent = '';
			renewModal.dataset.lease = btn.dataset.lease;
			openModal(renewModal);
		});
	});
	document.getElementById('af-renew-save').addEventListener('click', function () {
		const status = document.getElementById('af-modal-renew-status');
		const reason = document.getElementById('af-renew-reason').value.trim();
		const endDate = document.getElementById('af-renew-end-date').value;
		if (!reason || !endDate) {
			setStatus(status, <?php echo wp_json_encode( __( 'Completa la fecha y el motivo.', 'arriendo-facil' ) ); ?>, true);
			return;
		}
		post('af_renew_lease', leaseNonce, { lease_id: renewModal.dataset.lease, new_end_date: endDate, reason: reason }).then(done(status));
	});

	// ---- Estado legal ----
	const legalModal = document.getElementById('af-modal-legal');
	document.querySelectorAll('.af-exit-open-legal').forEach(function (btn) {
		btn.addEventListener('click', function () {
			document.getElementById('af-modal-legal-subtitle').textContent = btn.dataset.title || '';
			document.getElementById('af-legal-select').value = btn.dataset.status || 'pendiente';
			document.getElementById('af-legal-notes').value = btn.dataset.notes || '';
			document.getElementById('af-modal-legal-status-msg').textContent = '';
			legalModal.dataset.lease = btn.dataset.lease;
			openModal(legalModal);
		});
	});
	document.getElementById('af-legal-save').addEventListener('click', function () {
		post('af_update_lease_legal_status', opsNonce, {
			lease_id: legalModal.dataset.lease,
			legal_status: document.getElementById('af-legal-select').value,
			legal_notes: document.getElementById('af-legal-notes').value
		}).then(done(document.getElementById('af-modal-legal-status-msg')));
	});
})();
</script>