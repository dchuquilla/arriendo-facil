<?php
/**
 * Meter readings admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$readings_table = Arriendo_Facil_Billing_Ledger::readings_table();
$services       = Arriendo_Facil_Billing_Ledger::metered_services();

$period_filter = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : gmdate( 'Y-m' );
if ( ! preg_match( '/^\d{4}-\d{2}$/', $period_filter ) ) {
	$period_filter = gmdate( 'Y-m' );
}

$accessible_building_ids = Arriendo_Facil_Tenancy::accessible_building_ids();
$building_scope_clause   = null === $accessible_building_ids ? '' : ' AND id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $accessible_building_ids ) . ')';

$buildings = (array) $wpdb->get_results(
	'SELECT id, name FROM ' . Arriendo_Facil_Property_Structure::buildings_table() . " WHERE status = 'active'{$building_scope_clause} ORDER BY name ASC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

$reading_scope_clause = null === $accessible_building_ids ? '' : ' AND b.id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $accessible_building_ids ) . ')';

$readings = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT r.*, u.unit_code, b.name AS building_name
		 FROM {$readings_table} r
		 LEFT JOIN " . Arriendo_Facil_Property_Structure::units_table() . ' u ON u.id = r.unit_id
		 LEFT JOIN ' . Arriendo_Facil_Property_Structure::buildings_table() . " b ON b.id = u.building_id
		 WHERE r.period = %s{$reading_scope_clause}
		 ORDER BY b.name ASC, u.unit_code ASC, r.service ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$period_filter
	)
);

$period_total = 0.0;
foreach ( (array) $readings as $reading ) {
	$period_total += (float) $reading->calculated_amount;
}
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Servicios básicos', 'arriendo-facil' ),
			'title'    => __( 'Lecturas de medidor', 'arriendo-facil' ),
			'subtitle' => __( 'Registra el consumo de agua, luz y gas por unidad. El cargo al inquilino se genera automáticamente.', 'arriendo-facil' ),
		)
	);
	?>

	<?php if ( empty( $buildings ) ) : ?>
		<div class="af-section" style="padding: var(--af-space-5);">
			<p>
				<?php
				printf(
					/* translators: %s: link to the buildings page */
					esc_html__( 'Primero registra un edificio y sus unidades en %s.', 'arriendo-facil' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=af-buildings' ) ) . '">' . esc_html__( 'Edificios y unidades', 'arriendo-facil' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php else : ?>

		<div class="af-section" style="padding: var(--af-space-5); margin-bottom: var(--af-space-4);">
			<h2 class="af-section__title" style="margin-top:0;"><?php esc_html_e( 'Registrar lectura', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__hint" style="margin:0 0 14px;">
				<?php esc_html_e( 'La lectura anterior se toma automáticamente del último periodo registrado.', 'arriendo-facil' ); ?>
			</p>
			<p class="af-modal__status" id="af-reading-status"></p>

			<form id="af-reading-form" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap:14px; align-items:end;">
				<label>
					<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Unidad', 'arriendo-facil' ); ?> *</span>
					<select name="unit_id" required style="width:100%;">
						<option value=""><?php esc_html_e( '— Seleccionar —', 'arriendo-facil' ); ?></option>
						<?php foreach ( $buildings as $building ) : ?>
							<optgroup label="<?php echo esc_attr( $building->name ); ?>">
								<?php foreach ( Arriendo_Facil_Property_Structure::get_units_by_building( (int) $building->id ) as $unit ) : ?>
									<option value="<?php echo esc_attr( (int) $unit->id ); ?>"><?php echo esc_html( $unit->unit_code ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Servicio', 'arriendo-facil' ); ?> *</span>
					<select name="service" required style="width:100%;">
						<?php foreach ( $services as $service_key => $service_label ) : ?>
							<option value="<?php echo esc_attr( $service_key ); ?>"><?php echo esc_html( $service_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Periodo', 'arriendo-facil' ); ?> *</span>
					<input type="month" name="period" value="<?php echo esc_attr( $period_filter ); ?>" required style="width:100%;" />
				</label>

				<label>
					<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Lectura actual', 'arriendo-facil' ); ?> *</span>
					<input type="number" name="current_reading" step="0.001" min="0" required style="width:100%;" />
				</label>

				<label>
					<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Tarifa por unidad', 'arriendo-facil' ); ?> *</span>
					<input type="number" name="unit_rate" step="0.0001" min="0" required style="width:100%;" placeholder="0.0000" />
				</label>

				<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar y facturar', 'arriendo-facil' ); ?></button>
			</form>
		</div>

		<form method="get" class="af-section" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding: var(--af-space-4) var(--af-space-5); margin-bottom: var(--af-space-4);">
			<input type="hidden" name="page" value="af-meter-readings" />
			<label style="display:flex; align-items:center; gap:8px; font-weight:600;">
				<?php esc_html_e( 'Periodo', 'arriendo-facil' ); ?>
				<input type="month" name="period" value="<?php echo esc_attr( $period_filter ); ?>" style="min-height:36px;" onchange="this.form.submit()" />
			</label>
			<span class="af-pill af-pill--info">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: total billed for the period */
						__( 'Total facturado: $%s', 'arriendo-facil' ),
						number_format_i18n( $period_total, 2 )
					)
				);
				?>
			</span>
		</form>

		<section class="af-section">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title"><?php esc_html_e( 'Lecturas del periodo', 'arriendo-facil' ); ?></h2>
				</div>
			</header>

			<table class="wp-list-table widefat fixed striped af-data-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Unidad', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Servicio', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Anterior', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Actual', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Consumo', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Tarifa', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Importe', 'arriendo-facil' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $readings ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No hay lecturas registradas en este periodo.', 'arriendo-facil' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $readings as $reading ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Unidad', 'arriendo-facil' ); ?>">
									<strong><?php echo esc_html( $reading->unit_code ); ?></strong>
									<span class="af-td-meta"><?php echo esc_html( $reading->building_name ); ?></span>
								</td>
								<td data-label="<?php esc_attr_e( 'Servicio', 'arriendo-facil' ); ?>"><?php echo esc_html( $services[ $reading->service ] ?? $reading->service ); ?></td>
								<td data-label="<?php esc_attr_e( 'Anterior', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $reading->previous_reading, 3 ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Actual', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $reading->current_reading, 3 ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Consumo', 'arriendo-facil' ); ?>"><strong><?php echo esc_html( number_format_i18n( (float) $reading->consumption, 3 ) ); ?></strong></td>
								<td data-label="<?php esc_attr_e( 'Tarifa', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $reading->unit_rate, 4 ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Importe', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $reading->calculated_amount, 2 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>
</div>

<script>
(function () {
	const form = document.getElementById('af-reading-form');
	const status = document.getElementById('af-reading-status');
	if (!form) { return; }

	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_ledger_nonce' ) ); ?>;

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		const btn = form.querySelector('button[type="submit"]');
		btn.disabled = true;
		status.textContent = '';
		status.className = 'af-modal__status';

		const body = new URLSearchParams(new FormData(form));
		body.append('action', 'af_record_meter_reading');
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
			setTimeout(function () { window.location.reload(); }, 1200);
		});
	});
}());
</script>
