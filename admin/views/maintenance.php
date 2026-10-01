<?php
/**
 * Mantenimiento admin page view.
 *
 * Two tabs sharing one screen:
 *  1. Solicitudes  — repair requests for the managed properties, rendered as an
 *     interactive table (sort / search / expandable detail / CSV / print).
 *  2. Personal     — the tenant-scoped catalog of maintenance providers
 *     (plumbers, carpenters, ...) that requests can be linked to.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$table       = Arriendo_Facil_Maintenance::table();
$types       = Arriendo_Facil_Maintenance::types();
$priorities  = Arriendo_Facil_Maintenance::priorities();
$statuses    = Arriendo_Facil_Maintenance::statuses();
$reporters   = Arriendo_Facil_Maintenance::reporters();
$categories  = Arriendo_Facil_Maintenance::asset_categories();

$requests   = Arriendo_Facil_Maintenance::get_requests_for_current_user();
$providers  = class_exists( 'Arriendo_Facil_Service_Providers' ) ? Arriendo_Facil_Service_Providers::list() : array();
$trades     = class_exists( 'Arriendo_Facil_Service_Providers' ) ? Arriendo_Facil_Service_Providers::trades() : array();

$open_count   = 0;
$urgent_count = 0;
$month_cost   = 0.0;
$scheduled_count = 0;
$overdue_count   = 0;
$today           = current_time( 'Y-m-d' );

foreach ( $requests as $request ) {
	$status = (string) $request->status;

	if ( 'pending' === $status || 'in_progress' === $status ) {
		$open_count++;
		if ( 'alta' === (string) $request->priority ) {
			$urgent_count++;
		}
		if ( ! empty( $request->scheduled_date ) ) {
			$scheduled_count++;
			if ( (string) $request->scheduled_date < $today ) {
				$overdue_count++;
			}
		}
	}

	if ( 'completed' === $status && (string) $request->completed_date >= gmdate( 'Y-m-01' ) ) {
		$month_cost += (float) $request->cost;
	}
}

$provider_args = array(
	'post_type'      => 'accommodation',
	'post_status'    => array( 'publish', 'draft', 'private' ),
	'posts_per_page' => 200,
	'orderby'        => 'title',
	'order'          => 'ASC',
);

$accessible_ids = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
if ( null !== $accessible_ids ) {
	$provider_args['post__in'] = ! empty( $accessible_ids ) ? $accessible_ids : array( 0 );
}

$maintenance_properties = get_posts( $provider_args );

// Units index (accommodation_id => unit codes) for the dynamic unit select.
$units_index = array();
if ( class_exists( 'Arriendo_Facil_Property_Structure' ) ) {
	foreach ( $maintenance_properties as $accommodation ) {
		$unit = Arriendo_Facil_Property_Structure::get_unit_by_accommodation( (int) $accommodation->ID );
		if ( $unit && ! empty( $unit->unit_code ) ) {
			$units_index[ (int) $accommodation->ID ][] = array(
				'id'   => (int) $unit->id,
				'code' => (string) $unit->unit_code,
			);
		}
	}
}

$active_providers = array_values(
	array_filter(
		$providers,
		static function ( $provider ) {
			return 'active' === $provider->status;
		}
	)
);
?>
<div class="wrap af-shell af-maint">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Operación', 'arriendo-facil' ),
			'title'    => __( 'Mantenimiento', 'arriendo-facil' ),
			'subtitle' => __( 'Registra qué hay que reparar en cada inmueble, vincúlalo a tu catálogo de personal y lleva las fechas y costos al día.', 'arriendo-facil' ),
			'actions'  => array(
				sprintf(
					'<button type="button" class="button af-btn af-btn--primary" id="af-maint-new-request">%s</button>',
					esc_html__( 'Nueva solicitud', 'arriendo-facil' )
				),
				sprintf(
					'<button type="button" class="button af-btn af-btn--ghost" id="af-maint-new-contact">%s</button>',
					esc_html__( 'Nuevo contacto', 'arriendo-facil' )
				),
			),
		)
	);
	?>

	<div class="af-tabs">
		<input type="radio" class="af-tabs__radio" id="af-tab-solicitudes" name="af-maint-tabs" checked />
		<input type="radio" class="af-tabs__radio" id="af-tab-personal" name="af-maint-tabs" />

		<div class="af-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'Secciones de mantenimiento', 'arriendo-facil' ); ?>">
			<label class="af-tabs__tab" for="af-tab-solicitudes" role="tab">
				<span class="af-tabs__icon" aria-hidden="true"><?php echo af_lucide( 'wrench', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-tabs__label"><?php esc_html_e( 'Solicitudes', 'arriendo-facil' ); ?></span>
				<?php if ( $open_count > 0 ) : ?>
					<span class="af-tabs__badge af-tabs__badge--attention"><?php echo esc_html( number_format_i18n( $open_count ) ); ?></span>
				<?php endif; ?>
			</label>
			<label class="af-tabs__tab" for="af-tab-personal" role="tab">
				<span class="af-tabs__icon" aria-hidden="true"><?php echo af_lucide( 'users', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-tabs__label"><?php esc_html_e( 'Personal', 'arriendo-facil' ); ?></span>
				<span class="af-tabs__badge"><?php echo esc_html( number_format_i18n( count( $providers ) ) ); ?></span>
			</label>
		</div>

		<div class="af-tabs__panels">

			<!-- ══════════════════════════════════════════════════════════════
			     PANEL 1 — Solicitudes de reparación
			     ══════════════════════════════════════════════════════════════ -->
			<section class="af-tabs__panel af-tabs__panel--solicitudes" role="tabpanel" aria-labelledby="af-tab-solicitudes">

				<div class="af-maint-stats" id="af-maint-stats" role="group" aria-label="<?php esc_attr_e( 'Resumen y filtros rápidos de solicitudes', 'arriendo-facil' ); ?>">
					<button type="button" class="af-maint-stat af-maint-stat--<?php echo $open_count > 0 ? 'attention' : 'success'; ?>"
						data-quick-filter="open" aria-pressed="false">
						<span class="af-maint-stat__value" data-stat="open"><?php echo esc_html( number_format_i18n( $open_count ) ); ?></span>
						<span class="af-maint-stat__label"><?php esc_html_e( 'abiertas', 'arriendo-facil' ); ?></span>
					</button>

					<button type="button" class="af-maint-stat <?php echo $overdue_count > 0 ? 'af-maint-stat--attention' : ''; ?>"
						data-quick-filter="overdue" data-attention="1" aria-pressed="false">
						<span class="af-maint-stat__value" data-stat="overdue"><?php echo esc_html( number_format_i18n( $overdue_count ) ); ?></span>
						<span class="af-maint-stat__label"><?php esc_html_e( 'atrasadas', 'arriendo-facil' ); ?></span>
					</button>

					<button type="button" class="af-maint-stat <?php echo $urgent_count > 0 ? 'af-maint-stat--attention' : ''; ?>"
						data-quick-filter="alta" data-attention="1" aria-pressed="false">
						<span class="af-maint-stat__value" data-stat="alta"><?php echo esc_html( number_format_i18n( $urgent_count ) ); ?></span>
						<span class="af-maint-stat__label"><?php esc_html_e( 'prioridad alta', 'arriendo-facil' ); ?></span>
					</button>

					<button type="button" class="af-maint-stat <?php echo $scheduled_count > 0 ? 'af-maint-stat--info' : ''; ?>"
						data-quick-filter="scheduled" aria-pressed="false">
						<span class="af-maint-stat__value" data-stat="scheduled"><?php echo esc_html( number_format_i18n( $scheduled_count ) ); ?></span>
						<span class="af-maint-stat__label"><?php esc_html_e( 'con fecha pactada', 'arriendo-facil' ); ?></span>
					</button>

					<button type="button" class="af-maint-stat" data-quick-filter="month" aria-pressed="false">
						<span class="af-maint-stat__value">$<?php echo esc_html( number_format_i18n( $month_cost, 2 ) ); ?></span>
						<span class="af-maint-stat__label"><?php esc_html_e( 'gasto del mes', 'arriendo-facil' ); ?></span>
					</button>
				</div>

				<div class="af-section af-maint-formcard" id="af-maint-request-form-card" hidden>
					<header class="af-maint-formcard__head">
						<div>
							<h2 class="af-maint-formcard__title"><?php esc_html_e( 'Nueva solicitud de reparación', 'arriendo-facil' ); ?></h2>
							<p class="af-maint-formcard__sub"><?php esc_html_e( 'Solo 2 datos son obligatorios. Lo demás puedes completarlo después.', 'arriendo-facil' ); ?></p>
						</div>
						<button type="button" class="af-maint-formcard__close" data-af-close-request aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
					</header>
					<p class="af-modal__status" id="af-maint-request-status" aria-live="polite"></p>

					<form id="af-maint-request-form" class="af-maint-stepform">

						<fieldset class="af-maint-step">
							<legend class="af-maint-step__title"><span class="af-maint-step__num">1</span><?php esc_html_e( '¿Dónde?', 'arriendo-facil' ); ?></legend>

							<div class="af-maint-step__grid">
								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'obligatorio', 'arriendo-facil' ); ?>">*</abbr></span>
									<select name="accommodation_id" required>
										<option value=""><?php esc_html_e( 'Elige el inmueble…', 'arriendo-facil' ); ?></option>
										<?php foreach ( $maintenance_properties as $maintenance_property ) : ?>
											<option value="<?php echo esc_attr( (int) $maintenance_property->ID ); ?>"><?php echo esc_html( $maintenance_property->post_title ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>

								<label id="af-maint-unit-wrap" hidden>
									<span class="af-maint-label"><?php esc_html_e( 'Unidad', 'arriendo-facil' ); ?></span>
									<select name="unit_id" id="af-maint-unit">
										<option value=""><?php esc_html_e( '— Sin unidad —', 'arriendo-facil' ); ?></option>
									</select>
								</label>

								<label>
									<span class="af-maint-label"><?php esc_html_e( '¿En qué parte del inmueble?', 'arriendo-facil' ); ?></span>
									<input type="text" name="asset_location" placeholder="<?php esc_attr_e( 'Ej: cocina, baño principal', 'arriendo-facil' ); ?>" />
								</label>
							</div>
						</fieldset>

						<fieldset class="af-maint-step">
							<legend class="af-maint-step__title"><span class="af-maint-step__num">2</span><?php esc_html_e( '¿Qué pasó?', 'arriendo-facil' ); ?></legend>

							<span class="af-maint-label"><?php esc_html_e( 'Es un problema de…', 'arriendo-facil' ); ?></span>
							<div class="af-maint-chips" role="radiogroup">
								<?php foreach ( $categories as $category_key => $category_label ) : ?>
									<label class="af-maint-chip">
										<input type="radio" name="asset_category" value="<?php echo esc_attr( $category_key ); ?>" />
										<span><?php echo esc_html( $category_label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>

							<label>
								<span class="af-maint-label"><?php esc_html_e( '¿Qué hay que reparar?', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'obligatorio', 'arriendo-facil' ); ?>">*</abbr></span>
								<input type="text" name="asset_name" required maxlength="190" placeholder="<?php esc_attr_e( 'Ej: lavadora, puerta principal, calentador de agua', 'arriendo-facil' ); ?>" />
							</label>

							<label>
								<span class="af-maint-label"><?php esc_html_e( 'Describe el daño', 'arriendo-facil' ); ?></span>
								<textarea name="damage_details" rows="3" placeholder="<?php esc_attr_e( 'Ej: no drena, se queda con agua y hace ruido al centrifugar.', 'arriendo-facil' ); ?>"></textarea>
								<span class="af-maint-help"><?php esc_html_e( 'Mientras más claro, más rápido cotiza el técnico.', 'arriendo-facil' ); ?></span>
							</label>
						</fieldset>

						<fieldset class="af-maint-step">
							<legend class="af-maint-step__title"><span class="af-maint-step__num">3</span><?php esc_html_e( '¿Qué tan urgente es?', 'arriendo-facil' ); ?></legend>

							<?php
							$priority_hints = array(
								'baja'  => __( 'Puede esperar', 'arriendo-facil' ),
								'media' => __( 'Resolver esta semana', 'arriendo-facil' ),
								'alta'  => __( 'Hoy: riesgo o sin servicio', 'arriendo-facil' ),
							);
							?>
							<div class="af-maint-segment" role="radiogroup">
								<?php foreach ( $priorities as $priority_key => $priority_label ) : ?>
									<label class="af-maint-segment__opt af-maint-segment__opt--<?php echo esc_attr( $priority_key ); ?>">
										<input type="radio" name="priority" value="<?php echo esc_attr( $priority_key ); ?>" <?php checked( 'media', $priority_key ); ?> />
										<span class="af-maint-segment__label"><?php echo esc_html( $priority_label ); ?></span>
										<span class="af-maint-segment__hint"><?php echo esc_html( $priority_hints[ $priority_key ] ?? '' ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</fieldset>

						<details class="af-maint-optional">
							<summary class="af-maint-optional__summary">
								<span class="af-maint-optional__label"><?php esc_html_e( 'Asignar técnico, fecha y costo', 'arriendo-facil' ); ?></span>
								<span class="af-maint-optional__hint"><?php esc_html_e( 'Opcional', 'arriendo-facil' ); ?></span>
							</summary>

							<div class="af-maint-optional__body">
								<label class="af-maint-form__full">
									<span class="af-maint-label"><?php esc_html_e( '¿Quién lo va a reparar?', 'arriendo-facil' ); ?></span>
									<select name="provider_id">
										<option value=""><?php esc_html_e( '— Sin asignar —', 'arriendo-facil' ); ?></option>
										<?php foreach ( $trades as $trade_key => $trade_label ) : ?>
											<?php
											$trade_providers = array_values(
												array_filter(
													$active_providers,
													static function ( $provider ) use ( $trade_key ) {
														return $provider->trade === $trade_key;
													}
												)
											);
											?>
											<?php if ( ! empty( $trade_providers ) ) : ?>
												<optgroup label="<?php echo esc_attr( $trade_label ); ?>">
													<?php foreach ( $trade_providers as $provider ) : ?>
														<option value="<?php echo esc_attr( (int) $provider->id ); ?>">
															<?php
															echo esc_html(
																$provider->name
																. ( ! empty( $provider->city ) ? ' — ' . $provider->city : '' )
																. ( ! empty( $provider->phone ) ? ' — ' . $provider->phone : '' )
															);
															?>
														</option>
													<?php endforeach; ?>
												</optgroup>
											<?php endif; ?>
										<?php endforeach; ?>
										<option value="manual"><?php esc_html_e( '✍️ Escribir un contacto nuevo…', 'arriendo-facil' ); ?></option>
									</select>
								</label>

								<div class="af-maint-manual" id="af-maint-manual-contact">
									<label>
										<span class="af-maint-label"><?php esc_html_e( 'Nombre del contacto', 'arriendo-facil' ); ?></span>
										<input type="text" name="contact_name" />
									</label>
									<label>
										<span class="af-maint-label"><?php esc_html_e( 'Teléfono', 'arriendo-facil' ); ?></span>
										<input type="tel" name="contact_phone" />
									</label>
								</div>

								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Fecha acordada con el técnico', 'arriendo-facil' ); ?></span>
									<input type="date" name="scheduled_date" min="<?php echo esc_attr( $today ); ?>" />
									<span class="af-maint-help"><?php esc_html_e( 'Déjala vacía si aún no hay fecha.', 'arriendo-facil' ); ?></span>
								</label>

								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Costo estimado', 'arriendo-facil' ); ?></span>
									<span class="af-maint-money"><span aria-hidden="true">$</span><input type="number" name="cost" step="0.01" min="0" placeholder="0.00" inputmode="decimal" /></span>
								</label>

								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Tipo de trabajo', 'arriendo-facil' ); ?></span>
									<select name="request_type">
										<?php foreach ( $types as $type_key => $type_label ) : ?>
											<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( 'reparacion', $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>

								<label>
									<span class="af-maint-label"><?php esc_html_e( '¿Quién lo reportó?', 'arriendo-facil' ); ?></span>
									<select name="reported_by">
										<?php foreach ( $reporters as $reporter_key => $reporter_label ) : ?>
											<option value="<?php echo esc_attr( $reporter_key ); ?>" <?php selected( 'operador', $reporter_key ); ?>><?php echo esc_html( $reporter_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>

								<label class="af-maint-form__full">
									<span class="af-maint-label"><?php esc_html_e( 'Marca, modelo o color', 'arriendo-facil' ); ?></span>
									<input type="text" name="asset_specs" placeholder="<?php esc_attr_e( 'Ej: LG 18 kg, blanca', 'arriendo-facil' ); ?>" />
								</label>

								<label class="af-maint-form__full">
									<span class="af-maint-label"><?php esc_html_e( 'Notas', 'arriendo-facil' ); ?></span>
									<textarea name="notes" rows="2" placeholder="<?php esc_attr_e( 'Contexto adicional, accesos, disponibilidad…', 'arriendo-facil' ); ?>"></textarea>
								</label>
							</div>
						</details>

						<footer class="af-maint-formcard__foot">
							<span class="af-maint-help"><abbr>*</abbr> <?php esc_html_e( 'Obligatorio', 'arriendo-facil' ); ?></span>
							<button type="button" class="button af-btn af-btn--ghost" id="af-maint-request-cancel"><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
							<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Registrar solicitud', 'arriendo-facil' ); ?></button>
						</footer>
					</form>
				</div>

				<div class="af-section" id="af-maint-requests">
					<div class="af-maint-toolbar" id="af-maint-toolbar"<?php echo empty( $requests ) ? ' hidden' : ''; ?>>
						<div class="af-maint-toolbar__filters">
							<label class="screen-reader-text" for="af-maint-search-input"><?php esc_html_e( 'Buscar solicitudes', 'arriendo-facil' ); ?></label>
							<span class="af-maint-search">
								<span class="dashicons dashicons-search" aria-hidden="true"></span>
								<input type="search" id="af-maint-search-input" placeholder="<?php esc_attr_e( 'Buscar por inmueble, bien, daño o contacto…', 'arriendo-facil' ); ?>" />
								<button type="button" class="af-maint-search__clear" id="af-maint-search-clear" hidden aria-label="<?php esc_attr_e( 'Limpiar búsqueda', 'arriendo-facil' ); ?>">&times;</button>
							</span>

							<div class="af-maint-filters">
								<button type="button" class="button af-btn af-btn--sm af-maint-filters__trigger" id="af-maint-filters-trigger"
									aria-expanded="false" aria-controls="af-maint-filters-panel">
									<?php esc_html_e( 'Filtros', 'arriendo-facil' ); ?>
									<span class="af-maint-filters__badge" id="af-maint-filters-badge" hidden>0</span>
								</button>

								<div class="af-maint-filters__panel" id="af-maint-filters-panel" hidden>
									<label>
										<span class="af-maint-label"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></span>
										<select id="af-maint-status-filter">
											<option value=""><?php esc_html_e( 'Todos los estados', 'arriendo-facil' ); ?></option>
											<?php foreach ( $statuses as $status_key => $status_label ) : ?>
												<option value="<?php echo esc_attr( $status_key ); ?>"><?php echo esc_html( $status_label ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>

									<label>
										<span class="af-maint-label"><?php esc_html_e( 'Tipo de trabajo', 'arriendo-facil' ); ?></span>
										<select id="af-maint-type-filter">
											<option value=""><?php esc_html_e( 'Todos los tipos', 'arriendo-facil' ); ?></option>
											<?php foreach ( $types as $type_key => $type_label ) : ?>
												<option value="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>

									<button type="button" class="button af-btn af-btn--sm" id="af-maint-filters-clear"><?php esc_html_e( 'Limpiar filtros', 'arriendo-facil' ); ?></button>
								</div>
							</div>
						</div>

						<div class="af-maint-toolbar__actions">
							<span class="af-maint-count" id="af-maint-count" aria-live="polite"></span>
							<button type="button" class="button af-btn af-btn--sm" id="af-maint-export-csv"><?php esc_html_e( 'Exportar CSV', 'arriendo-facil' ); ?></button>
							<button type="button" class="button af-btn af-btn--sm" onclick="window.print();"><?php esc_html_e( 'Imprimir', 'arriendo-facil' ); ?></button>
						</div>
					</div>

					<?php if ( empty( $requests ) ) : ?>
						<div class="af-empty" id="af-maint-empty">
							<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'wrench', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							<h3 class="af-empty__title"><?php esc_html_e( 'Sin solicitudes', 'arriendo-facil' ); ?></h3>
							<p class="af-empty__text"><?php esc_html_e( 'Crea la primera solicitud de reparación para un inmueble de tu cartera.', 'arriendo-facil' ); ?></p>
							<button type="button" class="button af-btn af-btn--primary" data-af-open-request><?php esc_html_e( 'Registrar primera solicitud', 'arriendo-facil' ); ?></button>
						</div>
					<?php else : ?>
						<div class="af-maint-tablewrap">
							<table class="af-maint-table">
								<caption class="screen-reader-text"><?php esc_html_e( 'Solicitudes de reparación', 'arriendo-facil' ); ?></caption>
								<thead>
									<tr>
										<th scope="col"><button type="button" class="af-maint-sort" data-sort-key="assetName" aria-sort="none"><?php esc_html_e( 'Qué reparar', 'arriendo-facil' ); ?><span class="dashicons dashicons-arrow-up-alt2 af-maint-sort__icon" aria-hidden="true"></span></button></th>
										<th scope="col"><button type="button" class="af-maint-sort" data-sort-key="property" aria-sort="none"><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?><span class="dashicons dashicons-arrow-up-alt2 af-maint-sort__icon" aria-hidden="true"></span></button></th>
										<th scope="col"><button type="button" class="af-maint-sort" data-sort-key="status" aria-sort="none"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?><span class="dashicons dashicons-arrow-up-alt2 af-maint-sort__icon" aria-hidden="true"></span></button></th>
										<th scope="col"><button type="button" class="af-maint-sort" data-sort-key="scheduled" aria-sort="none"><?php esc_html_e( 'Fecha pactada', 'arriendo-facil' ); ?><span class="dashicons dashicons-arrow-up-alt2 af-maint-sort__icon" aria-hidden="true"></span></button></th>
										<th scope="col"><?php esc_html_e( 'Contacto', 'arriendo-facil' ); ?></th>
										<th scope="col"><button type="button" class="af-maint-sort" data-sort-key="cost" data-sort-type="number" aria-sort="none"><?php esc_html_e( 'Costo', 'arriendo-facil' ); ?><span class="dashicons dashicons-arrow-up-alt2 af-maint-sort__icon" aria-hidden="true"></span></button></th>
										<th scope="col" class="af-maint-table__actions"><span class="screen-reader-text"><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></span></th>
									</tr>
								</thead>
								<tbody>
									<?php
									foreach ( $requests as $request ) :
										$request_id        = (int) $request->id;
										$request_status    = (string) $request->status;
										$request_priority  = (string) $request->priority;
										$request_type      = (string) $request->request_type;
										$asset_label       = ! empty( $request->asset_name ) ? (string) $request->asset_name : (string) $request->notes;
										$property_label    = ! empty( $request->accommodation_title )
											? (string) $request->accommodation_title
											: '#' . (int) $request->accommodation_id;
										$category_label    = ! empty( $request->asset_category ) && isset( $categories[ $request->asset_category ] )
											? $categories[ $request->asset_category ]
											: '';

										$contact_name  = ! empty( $request->provider_name ) ? (string) $request->provider_name : (string) $request->contact_name;
										$contact_phone = ! empty( $request->provider_whatsapp )
											? (string) $request->provider_whatsapp
											: ( ! empty( $request->provider_phone )
												? (string) $request->provider_phone
												: (string) $request->contact_phone );
										$contact_trade = ! empty( $request->provider_trade ) && isset( $trades[ $request->provider_trade ] )
											? $trades[ $request->provider_trade ]
											: '';

										$search_blob = strtolower(
											implode(
												' ',
												array_filter(
													array(
														$asset_label,
														$property_label,
														$request->damage_details,
														$request->asset_specs,
														$request->asset_location,
														$contact_name,
														$contact_phone,
														$request->notes,
														$category_label,
													)
												)
											)
										);

										$csv_row = array(
											$request_id,
											$asset_label,
											$category_label,
											$request->damage_details,
											$request->asset_specs,
											$request->asset_location,
											$property_label,
											$request->scheduled_date ? $request->scheduled_date : $request->requested_date,
											$contact_name,
											$contact_phone,
											$statuses[ $request_status ] ?? $request_status,
											number_format_i18n( (float) $request->cost, 2 ),
										);
										?>
										<tr class="af-maint-row<?php echo 'alta' === $request_priority ? ' af-maint-row--alta' : ''; ?><?php echo '' !== (string) $request->scheduled_date ? ' af-maint-row--scheduled' : ''; ?>"
											data-status="<?php echo esc_attr( $request_status ); ?>"
											data-type="<?php echo esc_attr( $request_type ); ?>"
											data-priority="<?php echo esc_attr( $request_priority ); ?>"
											data-scheduled-flag="<?php echo '' !== (string) $request->scheduled_date ? '1' : '0'; ?>"
											data-month="<?php echo esc_attr( (string) ( ( 'completed' === $request_status && (string) $request->completed_date >= gmdate( 'Y-m-01' ) ) ? '1' : '0' ) ); ?>"
											data-search="<?php echo esc_attr( $search_blob ); ?>"
											data-asset-name="<?php echo esc_attr( $asset_label ); ?>"
											data-property="<?php echo esc_attr( $property_label ); ?>"
											data-scheduled="<?php echo esc_attr( (string) ( $request->scheduled_date ? $request->scheduled_date : $request->requested_date ) ); ?>"
											data-cost="<?php echo esc_attr( (string) (float) $request->cost ); ?>"
											data-csv-header="<?php
												echo esc_attr(
													implode(
														',',
														array(
															__( 'ID', 'arriendo-facil' ),
															__( 'Qué reparar', 'arriendo-facil' ),
															__( 'Categoría', 'arriendo-facil' ),
															__( 'Qué está dañado', 'arriendo-facil' ),
															__( 'Características', 'arriendo-facil' ),
															__( 'Ubicación', 'arriendo-facil' ),
															__( 'Inmueble', 'arriendo-facil' ),
															__( 'Fecha pactada', 'arriendo-facil' ),
															__( 'Contacto', 'arriendo-facil' ),
															__( 'Teléfono', 'arriendo-facil' ),
															__( 'Estado', 'arriendo-facil' ),
															__( 'Costo', 'arriendo-facil' ),
														)
													)
												);
											?>"
											data-csv-row="<?php
												$csv_escaped = array_map(
													static function ( $cell ) {
														$cell = str_replace( array( "\r", "\n" ), ' ', (string) $cell );

														return '"' . str_replace( '"', '""', $cell ) . '"';
													},
													$csv_row
												);
												echo esc_attr( implode( ',', $csv_escaped ) );
											?>">

											<td>
												<span class="af-maint-table__asset"><?php echo esc_html( $asset_label ? $asset_label : __( 'Sin detalle', 'arriendo-facil' ) ); ?></span>
												<?php if ( $category_label ) : ?>
													<span class="af-maint-table__sub"><?php echo esc_html( $category_label ); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<?php echo esc_html( $property_label ); ?>
												<?php if ( ! empty( $request->unit_code ) ) : ?>
													<span class="af-maint-table__sub"><?php echo esc_html( sprintf( /* translators: %s = unit code */ __( 'Unidad %s', 'arriendo-facil' ), $request->unit_code ) ); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<?php echo af_pill( $request_status, $statuses[ $request_status ] ?? $request_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes internally. ?>
												<?php if ( 'alta' === $request_priority ) : ?>
													<span class="af-maint-table__sub"><?php echo esc_html( $priorities[ $request_priority ] ); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( ! empty( $request->scheduled_date ) ) : ?>
													<?php echo esc_html( (string) $request->scheduled_date ); ?>
													<span class="af-maint-overdue"><?php esc_html_e( 'Atrasada', 'arriendo-facil' ); ?></span>
												<?php else : ?>
													<span class="af-maint-table__sub"><?php esc_html_e( 'Sin pactar', 'arriendo-facil' ); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( $contact_name ) : ?>
													<?php echo esc_html( $contact_name ); ?>
													<?php if ( $contact_trade ) : ?>
														<span class="af-maint-table__sub"><?php echo esc_html( $contact_trade ); ?></span>
													<?php endif; ?>
												<?php else : ?>
													<span class="af-maint-table__sub"><?php esc_html_e( 'Sin asignar', 'arriendo-facil' ); ?></span>
												<?php endif; ?>
											</td>
											<td>$<?php echo esc_html( number_format_i18n( (float) $request->cost, 2 ) ); ?></td>
											<td class="af-maint-table__actions">
												<button type="button" class="af-maint-toggle" aria-expanded="false"
													aria-label="<?php echo esc_attr( sprintf( /* translators: %s: asset name */ __( 'Ver detalle de %s', 'arriendo-facil' ), $asset_label ) ); ?>">
													<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
												</button>
											</td>
										</tr>

										<tr class="af-maint-detail is-hidden">
											<td colspan="7">
												<div class="af-maint-detail__inner">
													<div class="af-maint-detail__block">
														<span class="af-maint-detail__label"><?php esc_html_e( 'Qué está dañado', 'arriendo-facil' ); ?></span>
														<span class="af-maint-detail__value"><?php echo esc_html( $request->damage_details ? $request->damage_details : __( '—', 'arriendo-facil' ) ); ?></span>
													</div>
													<div class="af-maint-detail__block">
														<span class="af-maint-detail__label"><?php esc_html_e( 'Características', 'arriendo-facil' ); ?></span>
														<span class="af-maint-detail__value"><?php echo esc_html( $request->asset_specs ? $request->asset_specs : __( '—', 'arriendo-facil' ) ); ?></span>
													</div>
													<div class="af-maint-detail__block">
														<span class="af-maint-detail__label"><?php esc_html_e( 'Ubicación', 'arriendo-facil' ); ?></span>
														<span class="af-maint-detail__value"><?php echo esc_html( $request->asset_location ? $request->asset_location : __( '—', 'arriendo-facil' ) ); ?></span>
													</div>
													<div class="af-maint-detail__block">
														<span class="af-maint-detail__label"><?php esc_html_e( 'Contacto', 'arriendo-facil' ); ?></span>
														<span class="af-maint-detail__value">
															<?php echo esc_html( $contact_name ? $contact_name : __( 'Sin asignar', 'arriendo-facil' ) ); ?>
															<?php if ( $contact_phone ) : ?>
																<span class="af-prov-card__contacts">
																	<a class="af-prov-card__contact" href="tel:<?php echo esc_attr( $contact_phone ); ?>"><span class="dashicons dashicons-phone" aria-hidden="true"></span><?php echo esc_html( $contact_phone ); ?></a>
																	<a class="af-prov-card__contact" href="https://wa.me/<?php echo esc_attr( preg_replace( '/\D+/', '', $contact_phone ) ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><?php esc_html_e( 'WhatsApp', 'arriendo-facil' ); ?></a>
																</span>
															<?php endif; ?>
														</span>
													</div>
													<div class="af-maint-detail__block">
														<span class="af-maint-detail__label"><?php esc_html_e( 'Notas', 'arriendo-facil' ); ?></span>
														<span class="af-maint-detail__value"><?php echo esc_html( $request->notes ? $request->notes : __( '—', 'arriendo-facil' ) ); ?></span>
													</div>
													<div class="af-maint-detail__block">
														<span class="af-maint-detail__label"><?php esc_html_e( 'Cambiar estado', 'arriendo-facil' ); ?></span>
														<select class="af-maintenance-status" data-request="<?php echo esc_attr( $request_id ); ?>">
															<?php foreach ( $statuses as $status_key => $status_label ) : ?>
																<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $request_status, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<!-- ══════════════════════════════════════════════════════════════
			     PANEL 2 — Catálogo de personal
			     ══════════════════════════════════════════════════════════════ -->
			<section class="af-tabs__panel af-tabs__panel--personal" role="tabpanel" aria-labelledby="af-tab-personal">

				<div class="af-maint-stats">
					<span class="af-maint-stat af-maint-stat--info" aria-hidden="false">
						<span class="af-maint-stat__value"><?php echo esc_html( number_format_i18n( count( $providers ) ) ); ?></span>
						<span class="af-maint-stat__label"><?php esc_html_e( 'contactos', 'arriendo-facil' ); ?></span>
					</span>

					<span class="af-maint-stat af-maint-stat--success">
						<span class="af-maint-stat__value"><?php echo esc_html( number_format_i18n( count( $active_providers ) ) ); ?></span>
						<span class="af-maint-stat__label"><?php esc_html_e( 'activos', 'arriendo-facil' ); ?></span>
					</span>

					<span class="af-maint-stat">
						<span class="af-maint-stat__value">
							<?php
							$used_trades = array_unique(
								array_map(
									static function ( $provider ) {
										return $provider->trade;
									},
									$providers
								)
							);
							echo esc_html( number_format_i18n( count( $used_trades ) ) );
							?>
						</span>
						<span class="af-maint-stat__label"><?php esc_html_e( 'oficios cubiertos', 'arriendo-facil' ); ?></span>
					</span>
				</div>

				<div class="af-section af-maint-formcard" id="af-prov-form-card" hidden>
					<header class="af-maint-formcard__head">
						<div>
							<h2 class="af-maint-formcard__title" id="af-prov-form-title"><?php esc_html_e( 'Nuevo contacto de mantenimiento', 'arriendo-facil' ); ?></h2>
							<p class="af-maint-formcard__sub"><?php esc_html_e( 'Con nombre, oficio y un teléfono ya puedes asignarlo a una solicitud.', 'arriendo-facil' ); ?></p>
						</div>
						<button type="button" class="af-maint-formcard__close" data-af-close-prov aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
					</header>
					<p class="af-modal__status" id="af-prov-status" aria-live="polite"></p>

					<form id="af-prov-form" class="af-maint-stepform">
						<input type="hidden" name="id" id="af-prov-id" value="" />

						<fieldset class="af-maint-step">
							<legend class="af-maint-step__title"><span class="af-maint-step__num">1</span><?php esc_html_e( '¿Quién es?', 'arriendo-facil' ); ?></legend>
							<div class="af-maint-step__grid">
								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'obligatorio', 'arriendo-facil' ); ?>">*</abbr></span>
									<input type="text" name="name" id="af-prov-name" required placeholder="<?php esc_attr_e( 'Ej: Juan Pérez', 'arriendo-facil' ); ?>" />
								</label>
								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Oficio', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'obligatorio', 'arriendo-facil' ); ?>">*</abbr></span>
									<select name="trade" required>
										<?php foreach ( $trades as $trade_key => $trade_label ) : ?>
											<option value="<?php echo esc_attr( $trade_key ); ?>"><?php echo esc_html( $trade_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Empresa', 'arriendo-facil' ); ?></span>
									<input type="text" name="company" placeholder="<?php esc_attr_e( 'Si trabaja para una', 'arriendo-facil' ); ?>" />
								</label>
							</div>
						</fieldset>

						<fieldset class="af-maint-step">
							<legend class="af-maint-step__title"><span class="af-maint-step__num">2</span><?php esc_html_e( '¿Cómo lo contacto?', 'arriendo-facil' ); ?></legend>
							<div class="af-maint-step__grid">
								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Teléfono', 'arriendo-facil' ); ?></span>
									<input type="tel" name="phone" placeholder="<?php esc_attr_e( 'Ej: 0987654321', 'arriendo-facil' ); ?>" />
								</label>
								<label>
									<span class="af-maint-label"><?php esc_html_e( 'WhatsApp', 'arriendo-facil' ); ?></span>
									<input type="tel" name="whatsapp" placeholder="<?php esc_attr_e( 'Si es distinto al teléfono', 'arriendo-facil' ); ?>" />
								</label>
								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Correo', 'arriendo-facil' ); ?></span>
									<input type="email" name="email" />
								</label>
							</div>
						</fieldset>

						<details class="af-maint-optional">
							<summary class="af-maint-optional__summary">
								<span class="af-maint-optional__label"><?php esc_html_e( 'Zona, tarifas y notas', 'arriendo-facil' ); ?></span>
								<span class="af-maint-optional__hint"><?php esc_html_e( 'Opcional', 'arriendo-facil' ); ?></span>
							</summary>
							<div class="af-maint-optional__body">
								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Ciudad', 'arriendo-facil' ); ?></span>
									<input type="text" name="city" />
								</label>
								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Sector / barrio', 'arriendo-facil' ); ?></span>
									<input type="text" name="zone" />
								</label>
								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Cobra por hora', 'arriendo-facil' ); ?></span>
									<span class="af-maint-money"><span aria-hidden="true">$</span><input type="number" name="hourly_rate" step="0.01" min="0" placeholder="0.00" inputmode="decimal" /></span>
								</label>
								<label>
									<span class="af-maint-label"><?php esc_html_e( 'Cobra por trabajo (aprox.)', 'arriendo-facil' ); ?></span>
									<span class="af-maint-money"><span aria-hidden="true">$</span><input type="number" name="job_price" step="0.01" min="0" placeholder="0.00" inputmode="decimal" /></span>
								</label>
								<label class="af-maint-form__full">
									<span class="af-maint-label"><?php esc_html_e( 'Notas', 'arriendo-facil' ); ?></span>
									<textarea name="notes" rows="2" placeholder="<?php esc_attr_e( 'Horario, formas de pago, cobertura…', 'arriendo-facil' ); ?>"></textarea>
								</label>
							</div>
						</details>

						<footer class="af-maint-formcard__foot">
							<span class="af-maint-help"><abbr>*</abbr> <?php esc_html_e( 'Obligatorio', 'arriendo-facil' ); ?></span>
							<button type="button" class="button af-btn af-btn--ghost" id="af-prov-cancel"><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
							<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Guardar contacto', 'arriendo-facil' ); ?></button>
						</footer>
					</form>
				</div>

				<div class="af-section" id="af-maint-providers">
					<header class="af-section__header">
						<div>
							<h2 class="af-section__title"><?php esc_html_e( 'Catálogo de personal', 'arriendo-facil' ); ?></h2>
							<p class="af-section__subtitle"><?php esc_html_e( 'Estos contactos aparecen al crear una solicitud, para que puedas vincularlos o escribir uno nuevo.', 'arriendo-facil' ); ?></p>
						</div>
						<button type="button" class="button af-btn af-btn--primary" id="af-prov-new"><?php esc_html_e( 'Añadir contacto', 'arriendo-facil' ); ?></button>
					</header>

					<div class="af-maint-toolbar">
						<div class="af-maint-toolbar__filters">
							<label class="screen-reader-text" for="af-prov-search-input"><?php esc_html_e( 'Buscar contactos', 'arriendo-facil' ); ?></label>
							<span class="af-maint-search">
								<span class="dashicons dashicons-search" aria-hidden="true"></span>
								<input type="search" id="af-prov-search-input" placeholder="<?php esc_attr_e( 'Buscar por nombre, empresa o teléfono…', 'arriendo-facil' ); ?>" />
							</span>

							<label class="screen-reader-text" for="af-prov-trade-filter"><?php esc_html_e( 'Filtrar por oficio', 'arriendo-facil' ); ?></label>
							<select id="af-prov-trade-filter">
								<option value=""><?php esc_html_e( 'Todos los oficios', 'arriendo-facil' ); ?></option>
								<?php foreach ( $trades as $trade_key => $trade_label ) : ?>
									<option value="<?php echo esc_attr( $trade_key ); ?>"><?php echo esc_html( $trade_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="af-maint-toolbar__actions">
							<span class="af-maint-count" id="af-prov-count" aria-live="polite"></span>
						</div>
					</div>

					<?php if ( empty( $providers ) ) : ?>
						<div class="af-empty" id="af-prov-empty">
							<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'users', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							<h3 class="af-empty__title"><?php esc_html_e( 'Tu catálogo está vacío', 'arriendo-facil' ); ?></h3>
							<p class="af-empty__text"><?php esc_html_e( 'Registra a tus plomeros, albañiles y técnicos de confianza para vincularlos a cada reparación sin escribir sus datos cada vez.', 'arriendo-facil' ); ?></p>
						</div>
					<?php else : ?>
						<div class="af-prov-grid">
							<?php foreach ( $providers as $provider ) : ?>
								<?php
								$prov_trade   = (string) $provider->trade;
								$prov_name    = (string) $provider->name;
								$prov_phone   = (string) $provider->phone;
								$prov_wa      = (string) $provider->whatsapp;
								$prov_search  = strtolower(
									implode(
										' ',
										array_filter(
											array(
												$prov_name,
												$provider->company,
												$prov_phone,
												$prov_wa,
												$provider->city,
												$provider->zone,
												$trades[ $prov_trade ] ?? $prov_trade,
											)
										)
									)
								);
								?>
<article class="af-prov-card"
								data-trade="<?php echo esc_attr( $prov_trade ); ?>"
								data-search="<?php echo esc_attr( $prov_search ); ?>"
								data-id="<?php echo esc_attr( (int) $provider->id ); ?>"
								data-name="<?php echo esc_attr( $prov_name ); ?>"
								data-trade-value="<?php echo esc_attr( $prov_trade ); ?>"
								data-company="<?php echo esc_attr( (string) $provider->company ); ?>"
								data-phone="<?php echo esc_attr( $prov_phone ); ?>"
								data-whatsapp="<?php echo esc_attr( $prov_wa ); ?>"
								data-email="<?php echo esc_attr( (string) $provider->email ); ?>"
								data-city="<?php echo esc_attr( (string) $provider->city ); ?>"
								data-zone="<?php echo esc_attr( (string) $provider->zone ); ?>"
								data-hourly-rate="<?php echo esc_attr( (string) (float) $provider->hourly_rate ); ?>"
								data-job-price="<?php echo esc_attr( (string) (float) $provider->job_price ); ?>"
								data-notes="<?php echo esc_attr( (string) $provider->notes ); ?>">

									<header class="af-prov-card__head">
										<div>
											<h3 class="af-prov-card__name"><?php echo esc_html( $prov_name ); ?></h3>
											<?php if ( ! empty( $provider->company ) ) : ?>
												<p class="af-prov-card__company"><?php echo esc_html( (string) $provider->company ); ?></p>
											<?php endif; ?>
										</div>
										<?php echo af_pill( 'active' === (string) $provider->status ? 'active' : 'inactive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes internally. ?>
									</header>

									<p class="af-prov-card__trade">
										<span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
										<span class="af-prov-card__trade-label"><?php echo esc_html( $trades[ $prov_trade ] ?? $prov_trade ); ?></span>
									</p>

									<?php if ( ! empty( $provider->city ) || ! empty( $provider->zone ) ) : ?>
										<div class="af-prov-card__meta">
											<?php if ( ! empty( $provider->city ) ) : ?>
												<span><?php echo esc_html( (string) $provider->city ); ?></span>
											<?php endif; ?>
											<?php if ( ! empty( $provider->zone ) ) : ?>
												<span><?php echo esc_html( (string) $provider->zone ); ?></span>
											<?php endif; ?>
										</div>
									<?php endif; ?>

									<?php if ( (float) $provider->hourly_rate > 0 || (float) $provider->job_price > 0 ) : ?>
										<div class="af-prov-card__meta">
											<?php if ( (float) $provider->hourly_rate > 0 ) : ?>
												<span><?php echo esc_html( sprintf( /* translators: %s: hourly rate */ __( '$%s/hora', 'arriendo-facil' ), number_format_i18n( (float) $provider->hourly_rate, 2 ) ) ); ?></span>
											<?php endif; ?>
											<?php if ( (float) $provider->job_price > 0 ) : ?>
												<span><?php echo esc_html( sprintf( /* translators: %s: job price */ __( '~$%s por trabajo', 'arriendo-facil' ), number_format_i18n( (float) $provider->job_price, 2 ) ) ); ?></span>
											<?php endif; ?>
										</div>
									<?php endif; ?>

									<div class="af-prov-card__contacts">
										<?php if ( $prov_phone ) : ?>
											<a class="af-prov-card__contact" href="tel:<?php echo esc_attr( $prov_phone ); ?>">
												<span class="dashicons dashicons-phone" aria-hidden="true"></span><?php echo esc_html( $prov_phone ); ?>
											</a>
										<?php endif; ?>
										<?php if ( $prov_wa ) : ?>
											<a class="af-prov-card__contact" href="https://wa.me/<?php echo esc_attr( preg_replace( '/\D+/', '', $prov_wa ) ); ?>" target="_blank" rel="noopener noreferrer">
												<span class="dashicons dashicons-format-chat" aria-hidden="true"></span><?php esc_html_e( 'WhatsApp', 'arriendo-facil' ); ?>
											</a>
										<?php endif; ?>
										<?php if ( ! empty( $provider->email ) ) : ?>
											<a class="af-prov-card__contact" href="mailto:<?php echo esc_attr( (string) $provider->email ); ?>">
												<span class="dashicons dashicons-email" aria-hidden="true"></span><?php esc_html_e( 'Correo', 'arriendo-facil' ); ?>
											</a>
										<?php endif; ?>
									</div>

									<?php if ( ! empty( $provider->notes ) ) : ?>
										<p class="af-maint-table__sub"><?php echo esc_html( (string) $provider->notes ); ?></p>
									<?php endif; ?>

									<footer class="af-prov-card__actions">
										<button type="button" class="button af-btn af-btn--sm af-prov-edit" data-id="<?php echo esc_attr( (int) $provider->id ); ?>">
											<?php esc_html_e( 'Editar', 'arriendo-facil' ); ?>
										</button>
										<button type="button" class="button af-btn af-btn--sm af-prov-delete" data-id="<?php echo esc_attr( (int) $provider->id ); ?>">
											<?php esc_html_e( 'Eliminar', 'arriendo-facil' ); ?>
										</button>
									</footer>
								</article>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</section>

		</div>
	</div>
</div>

<?php
wp_enqueue_script( 'af-maintenance' );
wp_localize_script(
	'af-maintenance',
	'afMaintenance',
	array(
		'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
		'maintenanceNonce' => wp_create_nonce( 'af_maintenance_nonce' ),
		'providerNonce'   => wp_create_nonce( class_exists( 'Arriendo_Facil_Service_Providers' ) ? Arriendo_Facil_Service_Providers::NONCE : 'af_service_provider_nonce' ),
		'csvFilename'     => 'solicitudes-mantenimiento-' . gmdate( 'Y-m-d' ) . '.csv',
		'today'           => $today,
		'units'           => $units_index,
		'i18n'            => array(
			'countAll'      => __( '%d solicitudes', 'arriendo-facil' ),
			'countOne'      => __( '1 solicitud', 'arriendo-facil' ),
			'countContacts' => __( '%d contactos', 'arriendo-facil' ),
			'countContact'  => __( '1 contacto', 'arriendo-facil' ),
			'countFiltered' => __( '%1$d de %2$d', 'arriendo-facil' ),
			'noUnit'        => __( '— Sin unidad —', 'arriendo-facil' ),
			'unitPrefix'    => __( 'Unidad', 'arriendo-facil' ),
			'nothingToExport' => __( 'No hay solicitudes visibles para exportar.', 'arriendo-facil' ),
			'confirmDelete' => __( '¿Eliminar este contacto del catálogo? Esta acción no se puede deshacer.', 'arriendo-facil' ),
			'newContactTitle' => __( 'Nuevo contacto de mantenimiento', 'arriendo-facil' ),
			'editContactTitle' => __( 'Editar contacto de mantenimiento', 'arriendo-facil' ),
			'saveContact' => __( 'Guardar contacto', 'arriendo-facil' ),
			'saveChanges' => __( 'Guardar cambios', 'arriendo-facil' ),
			'genericError'  => __( 'Ocurrió un error. Intenta de nuevo.', 'arriendo-facil' ),
			'networkError'  => __( 'No se pudo conectar con el servidor.', 'arriendo-facil' ),
		),
	)
);
?>