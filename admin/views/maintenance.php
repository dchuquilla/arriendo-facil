<?php
/**
 * Maintenance and incidents admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$table      = Arriendo_Facil_Maintenance::table();
$types      = Arriendo_Facil_Maintenance::types();
$priorities = Arriendo_Facil_Maintenance::priorities();
$statuses   = Arriendo_Facil_Maintenance::statuses();
$reporters  = Arriendo_Facil_Maintenance::reporters();

$status_filter = isset( $_GET['req_status'] ) ? sanitize_key( wp_unslash( $_GET['req_status'] ) ) : '';

$where_clauses = array( '1=1' );
$where_args    = array();

if ( array_key_exists( $status_filter, $statuses ) ) {
	$where_clauses[] = 'r.status = %s';
	$where_args[]    = $status_filter;
}

$is_owner = Arriendo_Facil_Accommodation::user_is_owner();
if ( $is_owner ) {
	$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
	$ids_sql   = ! empty( $owner_ids ) ? implode( ',', array_map( 'intval', $owner_ids ) ) : '0';
	$where_clauses[] = "r.accommodation_id IN ({$ids_sql})";
}

$where_sql = implode( ' AND ', $where_clauses );

$list_query = "SELECT r.*, p.post_title AS accommodation_title
	FROM {$table} r
	LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
	WHERE {$where_sql}
	ORDER BY FIELD(r.priority, 'alta', 'media', 'baja'), r.requested_date DESC
	LIMIT 200";

$requests = empty( $where_args )
	? $wpdb->get_results( $list_query )
	: $wpdb->get_results( $wpdb->prepare( $list_query, $where_args ) );

$open_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','in_progress')" );
$urgent_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','in_progress') AND priority = 'alta'" );
$month_cost      = (float) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COALESCE(SUM(cost), 0) FROM {$table} WHERE status = 'completed' AND completed_date >= %s",
		gmdate( 'Y-m-01' )
	)
);

$maintenance_properties = get_posts(
	array(
		'post_type'      => 'accommodation',
		'post_status'    => array( 'publish', 'draft', 'private' ),
		'posts_per_page' => 200,
		'orderby'        => 'title',
		'order'          => 'ASC',
	)
);
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Operación', 'arriendo-facil' ),
			'title'    => __( 'Mantenimiento e incidencias', 'arriendo-facil' ),
			'subtitle' => __( 'Reparaciones, limpiezas y emergencias de los inmuebles administrados. Registra el costo para reportarlo al propietario.', 'arriendo-facil' ),
			'actions'  => array(
				sprintf(
					'<button type="button" class="button af-btn af-btn--primary" id="af-new-maintenance">%s</button>',
					esc_html__( 'Nueva incidencia', 'arriendo-facil' )
				),
			),
		)
	);
	?>

	<div id="af-maintenance-form-card" class="af-section" style="padding: var(--af-space-5); margin-bottom: var(--af-space-4); display:none;">
		<h2 class="af-section__title" style="margin-top:0;"><?php esc_html_e( 'Nueva incidencia', 'arriendo-facil' ); ?></h2>
		<p class="af-modal__status" id="af-maintenance-status"></p>

		<form id="af-maintenance-form" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap:14px; align-items:end;">
			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?> *</span>
				<select name="accommodation_id" required style="width:100%;">
					<option value=""><?php esc_html_e( '— Seleccionar —', 'arriendo-facil' ); ?></option>
					<?php foreach ( $maintenance_properties as $maintenance_property ) : ?>
						<option value="<?php echo esc_attr( (int) $maintenance_property->ID ); ?>"><?php echo esc_html( $maintenance_property->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></span>
				<select name="request_type" style="width:100%;">
					<?php foreach ( $types as $type_key => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Prioridad', 'arriendo-facil' ); ?></span>
				<select name="priority" style="width:100%;">
					<?php foreach ( $priorities as $priority_key => $priority_label ) : ?>
						<option value="<?php echo esc_attr( $priority_key ); ?>" <?php selected( 'media', $priority_key ); ?>><?php echo esc_html( $priority_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Reportado por', 'arriendo-facil' ); ?></span>
				<select name="reported_by" style="width:100%;">
					<?php foreach ( $reporters as $reporter_key => $reporter_label ) : ?>
						<option value="<?php echo esc_attr( $reporter_key ); ?>"><?php echo esc_html( $reporter_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></span>
				<input type="date" name="requested_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" style="width:100%;" />
			</label>

			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Costo estimado (USD)', 'arriendo-facil' ); ?></span>
				<input type="number" name="cost" step="0.01" min="0" value="0.00" style="width:100%;" />
			</label>

			<label style="grid-column: 1 / -1;">
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Descripción', 'arriendo-facil' ); ?></span>
				<textarea name="notes" rows="3" style="width:100%;" placeholder="<?php esc_attr_e( 'Ej: fuga en el baño principal, requiere plomero.', 'arriendo-facil' ); ?>"></textarea>
			</label>

			<div style="display:flex; gap:8px;">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Registrar', 'arriendo-facil' ); ?></button>
				<button type="button" class="button" id="af-maintenance-cancel"><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			</div>
		</form>
	</div>

	<div class="af-kpi-grid">
		<article class="af-kpi <?php echo $open_count > 0 ? 'af-kpi--attention' : 'af-kpi--success'; ?>">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Abiertas', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $open_count ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Pendientes o en proceso', 'arriendo-facil' ); ?></div>
		</article>

		<article class="af-kpi <?php echo $urgent_count > 0 ? 'af-kpi--attention' : ''; ?>">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Prioridad alta', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $urgent_count ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Requieren atención inmediata', 'arriendo-facil' ); ?></div>
		</article>

		<article class="af-kpi">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Gasto del mes', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $month_cost, 2 ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Incidencias completadas', 'arriendo-facil' ); ?></div>
		</article>
	</div>

	<form method="get" class="af-section" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding: var(--af-space-4) var(--af-space-5); margin-bottom: var(--af-space-4);">
		<input type="hidden" name="page" value="af-maintenance" />
		<label style="display:flex; align-items:center; gap:8px; font-weight:600;">
			<?php esc_html_e( 'Estado', 'arriendo-facil' ); ?>
			<select name="req_status" style="min-height:36px;" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
				<?php foreach ( $statuses as $status_key => $status_label ) : ?>
					<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status_filter, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	</form>

	<section class="af-section">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Incidencias', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Ordenadas por prioridad y fecha.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

		<table class="wp-list-table widefat fixed striped af-data-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Prioridad', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Reportó', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Costo', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Acción', 'arriendo-facil' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $requests ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No hay incidencias registradas.', 'arriendo-facil' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $requests as $request ) : ?>
						<?php
						$request_type     = isset( $request->request_type ) ? (string) $request->request_type : 'limpieza';
						$request_priority = isset( $request->priority ) ? (string) $request->priority : 'media';
						$request_reporter = isset( $request->reported_by ) ? (string) $request->reported_by : 'operador';
						$request_status   = isset( $request->status ) ? (string) $request->status : 'pending';

						$priority_pill = 'af-pill--neutral';
						if ( 'alta' === $request_priority ) {
							$priority_pill = 'af-pill--danger';
						} elseif ( 'media' === $request_priority ) {
							$priority_pill = 'af-pill--warning';
						}

						$status_pill = 'af-pill--neutral';
						if ( 'completed' === $request_status ) {
							$status_pill = 'af-pill--success';
						} elseif ( 'in_progress' === $request_status ) {
							$status_pill = 'af-pill--info';
						} elseif ( 'pending' === $request_status ) {
							$status_pill = 'af-pill--warning';
						}
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><?php echo esc_html( $request->accommodation_title ? $request->accommodation_title : '#' . (int) $request->accommodation_id ); ?></td>
							<td data-label="<?php esc_attr_e( 'Tipo', 'arriendo-facil' ); ?>"><?php echo esc_html( $types[ $request_type ] ?? $request_type ); ?></td>
							<td data-label="<?php esc_attr_e( 'Prioridad', 'arriendo-facil' ); ?>"><span class="af-pill <?php echo esc_attr( $priority_pill ); ?>"><?php echo esc_html( $priorities[ $request_priority ] ?? $request_priority ); ?></span></td>
							<td data-label="<?php esc_attr_e( 'Reportó', 'arriendo-facil' ); ?>"><?php echo esc_html( $reporters[ $request_reporter ] ?? $request_reporter ); ?></td>
							<td data-label="<?php esc_attr_e( 'Fecha', 'arriendo-facil' ); ?>"><?php echo esc_html( (string) $request->requested_date ); ?></td>
							<td data-label="<?php esc_attr_e( 'Costo', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) ( $request->cost ?? 0 ), 2 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>"><span class="af-pill <?php echo esc_attr( $status_pill ); ?>"><?php echo esc_html( $statuses[ $request_status ] ?? $request_status ); ?></span></td>
							<td data-label="<?php esc_attr_e( 'Acción', 'arriendo-facil' ); ?>">
								<?php if ( 'completed' !== $request_status && 'cancelled' !== $request_status ) : ?>
									<select class="af-maintenance-status" data-request="<?php echo esc_attr( (int) $request->id ); ?>" style="max-width:150px;">
										<?php foreach ( $statuses as $status_key => $status_label ) : ?>
											<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $request_status, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</section>
</div>

<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_maintenance_nonce' ) ); ?>;

	function post(payload) {
		const body = new URLSearchParams();
		Object.keys(payload).forEach((k) => body.append(k, payload[k]));
		return fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then((r) => r.json());
	}

	const card = document.getElementById('af-maintenance-form-card');
	const form = document.getElementById('af-maintenance-form');
	const status = document.getElementById('af-maintenance-status');
	const openBtn = document.getElementById('af-new-maintenance');
	const cancelBtn = document.getElementById('af-maintenance-cancel');

	if (openBtn && card) {
		openBtn.addEventListener('click', function () {
			card.style.display = card.style.display === 'none' ? 'block' : 'none';
		});
		cancelBtn.addEventListener('click', function () { card.style.display = 'none'; });
	}

	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			const btn = form.querySelector('button[type="submit"]');
			btn.disabled = true;
			status.textContent = '';
			status.className = 'af-modal__status';

			const body = new URLSearchParams(new FormData(form));
			body.append('action', 'af_create_maintenance');
			body.append('nonce', nonce);

			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body
			}).then((r) => r.json()).then(function (json) {
				btn.disabled = false;
				if (!json || !json.success) {
					status.textContent = (json && json.data && json.data.message) || 'Error';
					status.className = 'af-modal__status is-error';
					return;
				}
				status.textContent = json.data.message;
				status.className = 'af-modal__status is-success';
				setTimeout(function () { window.location.reload(); }, 800);
			});
		});
	}

	document.querySelectorAll('.af-maintenance-status').forEach(function (select) {
		select.addEventListener('change', function () {
			const newStatus = select.value;
			let cost = '';

			if (newStatus === 'completed') {
				cost = window.prompt(<?php echo wp_json_encode( __( 'Costo final de la incidencia (USD). Deja vacío para mantener el estimado.', 'arriendo-facil' ) ); ?>, '');
				if (cost === null) { window.location.reload(); return; }
			}

			select.disabled = true;
			post({
				action: 'af_update_maintenance_status',
				nonce: nonce,
				request_id: select.getAttribute('data-request'),
				status: newStatus,
				cost: cost
			}).then(function (json) {
				if (!json || !json.success) {
					select.disabled = false;
					window.alert((json && json.data && json.data.message) || 'Error');
					return;
				}
				window.location.reload();
			});
		});
	});
}());
</script>
