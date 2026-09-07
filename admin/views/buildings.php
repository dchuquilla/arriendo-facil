<?php
/**
 * Buildings and units admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$buildings_table = Arriendo_Facil_Property_Structure::buildings_table();

global $wpdb;

$buildings   = (array) $wpdb->get_results( "SELECT * FROM {$buildings_table} WHERE status = 'active' ORDER BY name ASC" );
$selected_id = isset( $_GET['building_id'] ) ? absint( wp_unslash( $_GET['building_id'] ) ) : 0;

if ( ! $selected_id && ! empty( $buildings ) ) {
	$selected_id = (int) $buildings[0]->id;
}

$selected_building = $selected_id ? Arriendo_Facil_Property_Structure::get_building( $selected_id ) : null;
$units             = $selected_id ? Arriendo_Facil_Property_Structure::get_units_by_building( $selected_id ) : array();
$coefficient_total = $selected_id ? Arriendo_Facil_Property_Structure::get_coefficient_total( $selected_id ) : 0.0;

$accommodations = get_posts(
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
			'eyebrow'  => __( 'Estructura', 'arriendo-facil' ),
			'title'    => __( 'Edificios y unidades', 'arriendo-facil' ),
			'subtitle' => __( 'Define el edificio, su alícuota mensual total y el coeficiente de prorrateo de cada unidad.', 'arriendo-facil' ),
		)
	);
	?>

	<div class="af-section" style="padding: var(--af-space-5); margin-bottom: var(--af-space-4);">
		<h2 class="af-section__title" style="margin-top:0;"><?php esc_html_e( 'Nuevo edificio', 'arriendo-facil' ); ?></h2>
		<p class="af-modal__status" id="af-building-status"></p>
		<form id="af-building-form" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?> *</span>
				<input type="text" name="name" required class="regular-text" style="width:100%;" />
			</label>
			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Dirección', 'arriendo-facil' ); ?></span>
				<input type="text" name="address" class="regular-text" style="width:100%;" />
			</label>
			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Ciudad', 'arriendo-facil' ); ?></span>
				<input type="text" name="city" class="regular-text" style="width:100%;" />
			</label>
			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Alícuota mensual total (USD)', 'arriendo-facil' ); ?></span>
				<input type="number" name="monthly_hoa_total" step="0.01" min="0" value="0.00" style="width:100%;" />
			</label>
			<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Crear edificio', 'arriendo-facil' ); ?></button>
		</form>
	</div>

	<?php if ( empty( $buildings ) ) : ?>
		<div class="af-section" style="padding: var(--af-space-5);">
			<p><?php esc_html_e( 'Aún no hay edificios registrados. Crea el primero para empezar a prorratear alícuotas.', 'arriendo-facil' ); ?></p>
		</div>
	<?php else : ?>

		<form method="get" class="af-section" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding: var(--af-space-4) var(--af-space-5); margin-bottom: var(--af-space-4);">
			<input type="hidden" name="page" value="af-buildings" />
			<label style="display:flex; align-items:center; gap:8px; font-weight:600;">
				<?php esc_html_e( 'Edificio', 'arriendo-facil' ); ?>
				<select name="building_id" style="min-height:36px;" onchange="this.form.submit()">
					<?php foreach ( $buildings as $building ) : ?>
						<option value="<?php echo esc_attr( (int) $building->id ); ?>" <?php selected( $selected_id, (int) $building->id ); ?>>
							<?php echo esc_html( $building->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		</form>

		<?php if ( $selected_building ) : ?>
			<div class="af-kpi-grid">
				<article class="af-kpi">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Alícuota total', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( (float) $selected_building->monthly_hoa_total, 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Mensual, a prorratear', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Unidades', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( count( $units ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Activas', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi <?php echo abs( $coefficient_total - 100 ) > 0.01 ? 'af-kpi--attention' : 'af-kpi--success'; ?>">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Suma de coeficientes', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $coefficient_total, 2 ) ); ?>%</div>
					<div class="af-kpi__hint">
						<?php
						echo esc_html(
							abs( $coefficient_total - 100 ) > 0.01
								? __( 'Debe sumar 100% para prorratear bien', 'arriendo-facil' )
								: __( 'Configuración correcta', 'arriendo-facil' )
						);
						?>
					</div>
				</article>
			</div>

			<div class="af-section" style="padding: var(--af-space-5); margin-bottom: var(--af-space-4);">
				<h2 class="af-section__title" style="margin-top:0;"><?php esc_html_e( 'Agregar unidad', 'arriendo-facil' ); ?></h2>
				<p class="af-modal__status" id="af-unit-status"></p>
				<form id="af-unit-form" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:12px; align-items:end;">
					<input type="hidden" name="building_id" value="<?php echo esc_attr( $selected_id ); ?>" />
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Código', 'arriendo-facil' ); ?> *</span>
						<input type="text" name="unit_code" required placeholder="A-101" style="width:100%;" />
					</label>
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Coeficiente (%)', 'arriendo-facil' ); ?></span>
						<input type="number" name="hoa_coefficient" step="0.0001" min="0" max="100" value="0" style="width:100%;" />
					</label>
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Área (m²)', 'arriendo-facil' ); ?></span>
						<input type="number" name="area_m2" step="0.01" min="0" value="0" style="width:100%;" />
					</label>
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Vincular a propiedad', 'arriendo-facil' ); ?></span>
						<select name="accommodation_id" style="width:100%;">
							<option value="0"><?php esc_html_e( '— Ninguna —', 'arriendo-facil' ); ?></option>
							<?php foreach ( $accommodations as $accommodation ) : ?>
								<option value="<?php echo esc_attr( (int) $accommodation->ID ); ?>"><?php echo esc_html( $accommodation->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Agregar unidad', 'arriendo-facil' ); ?></button>
				</form>
			</div>

			<section class="af-section">
				<header class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Unidades del edificio', 'arriendo-facil' ); ?></h2>
					</div>
				</header>

				<table class="wp-list-table widefat fixed striped af-data-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Código', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Propiedad vinculada', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Coeficiente', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Área', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Alícuota mensual', 'arriendo-facil' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $units ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'Este edificio aún no tiene unidades.', 'arriendo-facil' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $units as $unit ) : ?>
								<?php
								$linked_title = $unit->accommodation_id ? get_the_title( (int) $unit->accommodation_id ) : '';
								$unit_hoa     = Arriendo_Facil_Property_Structure::calculate_unit_hoa( (int) $unit->id );
								?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Código', 'arriendo-facil' ); ?>"><strong><?php echo esc_html( $unit->unit_code ); ?></strong></td>
									<td data-label="<?php esc_attr_e( 'Propiedad vinculada', 'arriendo-facil' ); ?>"><?php echo esc_html( $linked_title ? $linked_title : '—' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Coeficiente', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $unit->hoa_coefficient, 4 ) ); ?>%</td>
									<td data-label="<?php esc_attr_e( 'Área', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $unit->area_m2, 2 ) ); ?> m²</td>
									<td data-label="<?php esc_attr_e( 'Alícuota mensual', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( $unit_hoa, 2 ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>
	<?php endif; ?>
</div>

<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_structure_nonce' ) ); ?>;

	function submitForm(form, action) {
		const body = new URLSearchParams(new FormData(form));
		body.append('action', action);
		body.append('nonce', nonce);

		return fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then((r) => r.json());
	}

	function bind(formId, action, statusId) {
		const form = document.getElementById(formId);
		const status = document.getElementById(statusId);
		if (!form || !status) { return; }

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			const btn = form.querySelector('button[type="submit"]');
			btn.disabled = true;
			status.textContent = '';
			status.className = 'af-modal__status';

			submitForm(form, action).then(function (json) {
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

	bind('af-building-form', 'af_create_building', 'af-building-status');
	bind('af-unit-form', 'af_create_unit', 'af-unit-status');
}());
</script>
