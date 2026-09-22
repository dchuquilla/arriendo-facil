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

$accessible_building_ids = Arriendo_Facil_Tenancy::accessible_building_ids();
$scope_clause            = null === $accessible_building_ids ? '' : ' AND id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $accessible_building_ids ) . ')';

$buildings   = (array) $wpdb->get_results( "SELECT * FROM {$buildings_table} WHERE status = 'active'{$scope_clause} ORDER BY name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$selected_id = isset( $_GET['building_id'] ) ? absint( wp_unslash( $_GET['building_id'] ) ) : 0;

if ( $selected_id && ! Arriendo_Facil_Tenancy::can_access_building( $selected_id ) ) {
	$selected_id = 0;
}

if ( ! $selected_id && ! empty( $buildings ) ) {
	$selected_id = (int) $buildings[0]->id;
}

$selected_building = $selected_id ? Arriendo_Facil_Property_Structure::get_building( $selected_id ) : null;
$units             = $selected_id ? Arriendo_Facil_Property_Structure::get_units_by_building( $selected_id ) : array();
$coefficient_total = $selected_id ? Arriendo_Facil_Property_Structure::get_coefficient_total( $selected_id ) : 0.0;

$is_super_admin  = Arriendo_Facil_Tenancy::can_manage_all();
$property_admins = $is_super_admin ? Arriendo_Facil_Tenancy::get_property_admins() : array();

$accommodation_args = array(
	'post_type'      => 'accommodation',
	'post_status'    => array( 'publish', 'draft', 'private' ),
	'posts_per_page' => 200,
	'orderby'        => 'title',
	'order'          => 'ASC',
);
$accessible_accommodation_ids_for_dropdown = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
if ( null !== $accessible_accommodation_ids_for_dropdown ) {
	$accommodation_args['post__in'] = ! empty( $accessible_accommodation_ids_for_dropdown ) ? $accessible_accommodation_ids_for_dropdown : array( 0 );
}
$accommodations = get_posts( $accommodation_args );

// Unit count per building, used by the building switcher chips.
$unit_counts = array();
if ( ! empty( $buildings ) ) {
	$building_ids = array_map( 'absint', wp_list_pluck( $buildings, 'id' ) );
	$ids_sql      = Arriendo_Facil_Tenancy::ids_in_clause( $building_ids );
	$unit_rows    = $wpdb->get_results(
		'SELECT building_id, COUNT(*) AS total FROM ' . Arriendo_Facil_Property_Structure::units_table() . " WHERE building_id IN ({$ids_sql}) AND status = 'active' GROUP BY building_id" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
	foreach ( (array) $unit_rows as $row ) {
		$unit_counts[ (int) $row->building_id ] = (int) $row->total;
	}
}
?>
<div class="wrap af-shell af-buildings-page">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Estructura', 'arriendo-facil' ),
			'title'    => __( 'Edificios y unidades', 'arriendo-facil' ),
			'subtitle' => __( 'Opcional. Sirve para propiedades que están dentro de un edificio: departamentos, oficinas o locales. Aquí registras el edificio y sus unidades para repartir entre ellos los gastos comunes (mantenimiento, guardianía, etc.).', 'arriendo-facil' ),
		)
	);
	?>

	<section class="af-section" style="display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding: var(--af-space-4) var(--af-space-5); margin-bottom: var(--af-space-4);">
		<span class="af-section__icon af-section__icon--slate" aria-hidden="true"><?php echo af_lucide( 'building', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		<div style="flex:1; min-width:220px;">
			<h2 class="af-section__title" style="margin:0;">
				<?php if ( Arriendo_Facil_Property_Structure::module_enabled() ) : ?>
					<?php esc_html_e( 'Sección activa', 'arriendo-facil' ); ?> <span class="af-pill af-pill--success"><?php esc_html_e( 'Elegiste usarla', 'arriendo-facil' ); ?></span>
				<?php else : ?>
					<?php esc_html_e( 'Sección opcional', 'arriendo-facil' ); ?> <span class="af-pill af-pill--info"><?php esc_html_e( 'No elegida todavía', 'arriendo-facil' ); ?></span>
				<?php endif; ?>
			</h2>
			<p class="af-section__subtitle" style="margin:2px 0 0;">
				<?php
				if ( Arriendo_Facil_Property_Structure::module_enabled() ) {
					esc_html_e( 'La usas para repartir los gastos comunes de un edificio entre sus departamentos u oficinas. Puedes desactivarla cuando quieras y seguir igual con casas o propiedades sueltas.', 'arriendo-facil' );
				} else {
					esc_html_e( 'Solo elígela si arriendas varias propiedades dentro de un mismo edificio y necesitas repartir entre ellas los gastos comunes (mantenimiento, guardianía, agua del edificio, etc.). Si solo tienes casas o locales sueltos, no la necesitas: puedes ignorarla.', 'arriendo-facil' );
				}
				?>
			</p>
			<?php if ( ! Arriendo_Facil_Property_Structure::module_enabled() && Arriendo_Facil_Property_Structure::has_active_buildings() ) : ?>
				<p class="af-section__subtitle" style="margin:4px 0 0; font-weight:600;">
					<?php esc_html_e( 'Nota: ya tienes edificios guardados. No se borran; los volverás a ver al activar la sección.', 'arriendo-facil' ); ?>
				</p>
			<?php endif; ?>
			<p class="af-modal__status" id="af-buildings-module-status" style="margin:6px 0 0;"></p>
		</div>
		<button
			type="button"
			id="af-buildings-module-toggle"
			class="button af-btn <?php echo Arriendo_Facil_Property_Structure::module_enabled() ? 'af-btn--ghost' : 'af-btn--primary'; ?>"
			data-enable="<?php echo Arriendo_Facil_Property_Structure::module_enabled() ? '0' : '1'; ?>"
		>
			<?php echo Arriendo_Facil_Property_Structure::module_enabled() ? esc_html_e( 'Apagar (no la uso)', 'arriendo-facil' ) : esc_html_e( 'Activar sección', 'arriendo-facil' ); ?>
		</button>
	</section>

	<?php if ( ! Arriendo_Facil_Property_Structure::module_enabled() ) : ?>

		<div class="af-empty">
			<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'building-2', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<h3 class="af-empty__title"><?php esc_html_e( 'Esta sección no está activada', 'arriendo-facil' ); ?></h3>
			<p class="af-empty__text"><?php esc_html_e( 'Pulsa el botón "Activar sección" de arriba si quieres usarla para organizar departamentos dentro de un edificio y repartir los gastos comunes.', 'arriendo-facil' ); ?></p>
		</div>

	<?php elseif ( empty( $buildings ) ) : ?>

		<div class="af-empty" style="margin-bottom: var(--af-space-5);">
			<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'building-2', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<h3 class="af-empty__title"><?php esc_html_e( 'Aún no hay edificios registrados', 'arriendo-facil' ); ?></h3>
			<p class="af-empty__text"><?php esc_html_e( 'Aquí organizas los edificios con varios arriendos adentro (por ejemplo, un edificio con departamentos). Si tus propiedades son casas o locales sueltos, no necesitas usar esta sección.', 'arriendo-facil' ); ?></p>
		</div>

		<section class="af-section">
			<header class="af-section__header">
				<div class="af-section__head">
					<h2 class="af-section__title"><?php esc_html_e( 'Nuevo edificio', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'Pon el nombre del edificio y cuánto se gasta por mes en mantenerlo. Después añades cada departamento y cuánto le toca pagar.', 'arriendo-facil' ); ?></p>
				</div>
			</header>
			<p class="af-modal__status" id="af-building-status"></p>
			<form id="af-building-form" class="af-buildings-form">
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-name"><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?> <span class="af-required" aria-hidden="true">*</span></label>
					<input type="text" id="af-building-name" name="name" required class="regular-text" />
				</div>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-address"><?php esc_html_e( 'Dirección', 'arriendo-facil' ); ?></label>
					<input type="text" id="af-building-address" name="address" class="regular-text" />
				</div>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-city"><?php esc_html_e( 'Ciudad', 'arriendo-facil' ); ?></label>
					<input type="text" id="af-building-city" name="city" class="regular-text" />
				</div>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-hoa"><?php esc_html_e( 'Gastos comunes mensuales (USD)', 'arriendo-facil' ); ?></label>
					<input type="number" id="af-building-hoa" name="monthly_hoa_total" step="0.01" min="0" value="0.00" />
					<p class="af-form-field__hint"><?php esc_html_e( 'Cuánto cuesta al mes mantener el edificio entre todos: mantenimiento, guardianía, agua del edificio, etc. El sistema lo reparte entre las unidades.', 'arriendo-facil' ); ?></p>
				</div>
				<?php if ( $is_super_admin && ! empty( $property_admins ) ) : ?>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-admin"><?php esc_html_e( 'Administrador de propiedades', 'arriendo-facil' ); ?></label>
					<select id="af-building-admin" name="assigned_admin_id">
						<option value=""><?php esc_html_e( '— Asignar a mí mismo —', 'arriendo-facil' ); ?></option>
						<?php foreach ( $property_admins as $pa ) : ?>
							<option value="<?php echo esc_attr( $pa->ID ); ?>"><?php echo esc_html( get_user_meta( $pa->ID, 'af_company_name', true ) ? get_user_meta( $pa->ID, 'af_company_name', true ) : $pa->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Crear edificio', 'arriendo-facil' ); ?></button>
			</form>
		</section>

	<?php else : ?>

		<nav class="af-building-switcher" aria-label="<?php esc_attr_e( 'Seleccionar edificio', 'arriendo-facil' ); ?>">
			<?php foreach ( $buildings as $building ) : ?>
				<?php
				$is_active = $selected_id === (int) $building->id;
				$count     = isset( $unit_counts[ (int) $building->id ] ) ? $unit_counts[ (int) $building->id ] : 0;
				$place     = trim( trim( (string) $building->address ) . ( ! empty( $building->city ) ? ', ' . $building->city : '' ) );
				?>
				<a class="af-building-chip <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'af-buildings', 'building_id' => (int) $building->id ), admin_url( 'admin.php' ) ) ); ?>">
					<span class="af-building-chip__icon" aria-hidden="true"><?php echo af_lucide( 'building-2', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<span class="af-building-chip__body">
						<span class="af-building-chip__name"><?php echo esc_html( $building->name ); ?></span>
						<?php if ( '' !== $place ) : ?>
							<span class="af-building-chip__meta"><?php echo esc_html( $place ); ?></span>
						<?php endif; ?>
					</span>
					<span class="af-building-chip__count"><?php echo esc_html( sprintf( /* translators: %d: number of units */ _n( '%d unidad', '%d unidades', $count, 'arriendo-facil' ), $count ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( $selected_building ) : ?>

			<div class="af-kpi-grid af-buildings-kpis">
				<article class="af-kpi af-kpi--accent">
					<div class="af-kpi__head">
						<span class="af-kpi__label"><?php esc_html_e( 'Gastos comunes', 'arriendo-facil' ); ?></span>
						<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( 'receipt', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					</div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( (float) $selected_building->monthly_hoa_total, 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Por mes, se reparte entre las unidades', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi af-kpi--info">
					<div class="af-kpi__head">
						<span class="af-kpi__label"><?php esc_html_e( 'Unidades', 'arriendo-facil' ); ?></span>
						<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( 'layout-grid', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					</div>
					<div class="af-kpi__value"><?php echo esc_html( count( $units ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Activas', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi <?php echo abs( $coefficient_total - 100 ) > 0.01 ? 'af-kpi--attention' : 'af-kpi--success'; ?>">
					<div class="af-kpi__head">
						<span class="af-kpi__label"><?php esc_html_e( 'Suma de porcentajes', 'arriendo-facil' ); ?></span>
						<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( 'trending-up', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					</div>
					<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $coefficient_total, 2 ) ); ?>%</div>
					<div class="af-kpi__hint">
						<?php
						echo esc_html(
							abs( $coefficient_total - 100 ) > 0.01
								? __( 'Todas deben sumar 100% para repartir bien los gastos', 'arriendo-facil' )
								: __( 'Reparto correcto', 'arriendo-facil' )
						);
						?>
					</div>
				</article>
			</div>

			<section class="af-section" style="margin-bottom: var(--af-space-5);">
				<header class="af-section__header">
					<div class="af-section__head">
						<h2 class="af-section__title"><?php esc_html_e( 'Agregar unidad', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Una unidad es un departamento, local u oficina dentro del edificio. Dile cómo se identifica (por ejemplo A-101) y qué parte de los gastos comunes le corresponde.', 'arriendo-facil' ); ?></p>
					</div>
				</header>
				<p class="af-modal__status" id="af-unit-status"></p>
				<form id="af-unit-form" class="af-buildings-form">
					<input type="hidden" name="building_id" value="<?php echo esc_attr( $selected_id ); ?>" />
					<div class="af-form-field">
						<label class="af-form-field__label" for="af-unit-code"><?php esc_html_e( 'Código', 'arriendo-facil' ); ?> <span class="af-required" aria-hidden="true">*</span></label>
						<input type="text" id="af-unit-code" name="unit_code" required placeholder="A-101" />
					</div>
					<div class="af-form-field">
						<label class="af-form-field__label" for="af-unit-coef"><?php esc_html_e( 'Porción de los gastos comunes (%)', 'arriendo-facil' ); ?></label>
						<input type="number" id="af-unit-coef" name="hoa_coefficient" step="0.0001" min="0" max="100" value="0" />
						<p class="af-form-field__hint"><?php esc_html_e( 'Cuánto le toca de los gastos comunes a esta unidad. Entre todas las unidades debe sumar 100%.', 'arriendo-facil' ); ?></p>
					</div>
					<div class="af-form-field">
						<label class="af-form-field__label" for="af-unit-area"><?php esc_html_e( 'Área (m²)', 'arriendo-facil' ); ?></label>
						<input type="number" id="af-unit-area" name="area_m2" step="0.01" min="0" value="0" />
					</div>
					<div class="af-form-field">
						<label class="af-form-field__label" for="af-unit-accommodation"><?php esc_html_e( 'Vincular a propiedad', 'arriendo-facil' ); ?></label>
						<select id="af-unit-accommodation" name="accommodation_id">
							<option value="0"><?php esc_html_e( '— Ninguna —', 'arriendo-facil' ); ?></option>
							<?php foreach ( $accommodations as $accommodation ) : ?>
								<option value="<?php echo esc_attr( (int) $accommodation->ID ); ?>"><?php echo esc_html( $accommodation->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="af-form-field__hint"><?php esc_html_e( 'Si este departamento ya está registrado en el Catálogo de propiedades, elígela aquí para que los dos queden unidos.', 'arriendo-facil' ); ?></p>
					</div>
					<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Agregar unidad', 'arriendo-facil' ); ?></button>
				</form>
			</section>

			<section class="af-section">
				<header class="af-section__header">
					<div class="af-section__head">
						<h2 class="af-section__title"><?php esc_html_e( 'Unidades del edificio', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php echo esc_html( $selected_building->name ); ?></p>
					</div>
					<span class="af-pill af-pill--info"><?php echo esc_html( sprintf( /* translators: %d: number of units */ _n( '%d unidad', '%d unidades', count( $units ), 'arriendo-facil' ), count( $units ) ) ); ?></span>
				</header>

				<table class="wp-list-table widefat fixed striped af-data-table af-units-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Unidad', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Propiedad vinculada', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Gastos comunes %', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Área', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Le toca por mes', 'arriendo-facil' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $units ) ) : ?>
							<tr>
								<td colspan="5" class="no-items-cell">
									<?php esc_html_e( 'Este edificio aún no tiene unidades. Añade la primera con el formulario de arriba.', 'arriendo-facil' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php
							$coef_sum = 0.0;
							$area_sum = 0.0;
							$hoa_sum  = 0.0;
							foreach ( $units as $unit ) :
								$linked_title = $unit->accommodation_id ? get_the_title( (int) $unit->accommodation_id ) : '';
								$unit_hoa     = Arriendo_Facil_Property_Structure::calculate_unit_hoa( (int) $unit->id );
								$unit_coef    = (float) $unit->hoa_coefficient;
								$coef_sum    += $unit_coef;
								$area_sum    += (float) $unit->area_m2;
								$hoa_sum     += $unit_hoa;
								?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Unidad', 'arriendo-facil' ); ?>">
										<span class="af-unit-code">
											<?php echo af_lucide( 'home', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
											<?php echo esc_html( $unit->unit_code ); ?>
										</span>
									</td>
									<td data-label="<?php esc_attr_e( 'Propiedad vinculada', 'arriendo-facil' ); ?>">
										<span class="af-unit-linked">
											<?php echo $linked_title ? esc_html( $linked_title ) : esc_html__( 'Sin vincular', 'arriendo-facil' ); ?>
										</span>
										<?php if ( $linked_title ) : ?>
											<?php echo af_pill( 'active', __( 'Vinculada', 'arriendo-facil' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup. ?>
										<?php endif; ?>
									</td>
<td data-label="<?php esc_attr_e( 'Gastos comunes %', 'arriendo-facil' ); ?>">
						<span class="af-coef <?php echo $unit_coef > 100 ? 'is-over' : ''; ?>">
							<strong><?php echo esc_html( number_format_i18n( $unit_coef, 4 ) ); ?>%</strong>
							<span class="af-coef__bar" aria-hidden="true"><span style="width: <?php echo esc_attr( max( 0, min( 100, $unit_coef ) ) ); ?>%;"></span></span>
						</span>
					</td>
					<td data-label="<?php esc_attr_e( 'Área', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $unit->area_m2, 2 ) ); ?> m²</td>
					<td data-label="<?php esc_attr_e( 'Le toca por mes', 'arriendo-facil' ); ?>"><span class="af-unit-hoa">$<?php echo esc_html( number_format_i18n( $unit_hoa, 2 ) ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
					<?php if ( ! empty( $units ) ) : ?>
						<tfoot>
							<tr>
								<td colspan="2"><span class="af-totals-label"><?php esc_html_e( 'Totales', 'arriendo-facil' ); ?></span></td>
								<td data-label="<?php esc_attr_e( 'Gastos comunes %', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( $coef_sum, 4 ) ); ?>%</td>
								<td data-label="<?php esc_attr_e( 'Área', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( $area_sum, 2 ) ); ?> m²</td>
								<td data-label="<?php esc_attr_e( 'Le toca por mes', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( $hoa_sum, 2 ) ); ?></td>
							</tr>
						</tfoot>
					<?php endif; ?>
				</table>
			</section>

		<?php endif; ?>

		<section class="af-section" style="margin-top: var(--af-space-5);">
			<header class="af-section__header">
				<div class="af-section__head">
					<h2 class="af-section__title"><?php esc_html_e( 'Nuevo edificio', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( '¿Tienes otro edificio? Regístralo de la misma manera: datos, gastos comunes y después sus unidades.', 'arriendo-facil' ); ?></p>
				</div>
			</header>
			<p class="af-modal__status" id="af-building-status"></p>
			<form id="af-building-form" class="af-buildings-form">
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-name"><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?> <span class="af-required" aria-hidden="true">*</span></label>
					<input type="text" id="af-building-name" name="name" required class="regular-text" />
				</div>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-address"><?php esc_html_e( 'Dirección', 'arriendo-facil' ); ?></label>
					<input type="text" id="af-building-address" name="address" class="regular-text" />
				</div>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-city"><?php esc_html_e( 'Ciudad', 'arriendo-facil' ); ?></label>
					<input type="text" id="af-building-city" name="city" class="regular-text" />
				</div>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-hoa"><?php esc_html_e( 'Gastos comunes mensuales (USD)', 'arriendo-facil' ); ?></label>
					<input type="number" id="af-building-hoa" name="monthly_hoa_total" step="0.01" min="0" value="0.00" />
					<p class="af-form-field__hint"><?php esc_html_e( 'Cuánto cuesta al mes mantener el edificio entre todos: mantenimiento, guardianía, agua del edificio, etc. El sistema lo reparte entre las unidades.', 'arriendo-facil' ); ?></p>
				</div>
				<?php if ( $is_super_admin && ! empty( $property_admins ) ) : ?>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-admin"><?php esc_html_e( 'Administrador de propiedades', 'arriendo-facil' ); ?></label>
					<select id="af-building-admin" name="assigned_admin_id">
						<option value=""><?php esc_html_e( '— Asignar a mí mismo —', 'arriendo-facil' ); ?></option>
						<?php foreach ( $property_admins as $pa ) : ?>
							<option value="<?php echo esc_attr( $pa->ID ); ?>"><?php echo esc_html( get_user_meta( $pa->ID, 'af_company_name', true ) ? get_user_meta( $pa->ID, 'af_company_name', true ) : $pa->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Crear edificio', 'arriendo-facil' ); ?></button>
			</form>
		</section>

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

<script>
(function () {
	const btn = document.getElementById('af-buildings-module-toggle');
	if (!btn) { return; }
	const status = document.getElementById('af-buildings-module-status');
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_buildings_module_nonce' ) ); ?>;

	btn.addEventListener('click', function () {
		btn.disabled = true;
		const body = new URLSearchParams();
		body.append('action', 'af_toggle_buildings_module');
		body.append('nonce', nonce);
		body.append('enabled', btn.getAttribute('data-enable') === '1' ? '1' : '0');
		fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then(function (r) { return r.json(); }).then(function (json) {
			if (status) {
				status.textContent = (json && json.success && json.data && json.data.message) ? json.data.message : 'Error';
			}
			window.location.reload();
		}).catch(function () {
			btn.disabled = false;
			if (status) { status.textContent = 'Error'; }
		});
	});
}());
</script>