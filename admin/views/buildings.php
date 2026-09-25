<?php
/**
 * Collection hub per property ("Cobranza de inmuebles").
 *
 * Framed as a billing hub: each unit is a tenant to charge (canon + alicuota
 * + services) and the page shows who lives in each unit and what is pending
 * for the current period.
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

// ---- Billing snapshot for the selected building --------------------------
//
// Active leases for the units' linked accommodations, the charges generated
// for the current period and how much is still pending per unit.
$current_period = current_time( 'Y-m' );

$units_leases          = array();
$charges_by_lease      = array();
$active_lease_count    = 0;
$pending_total_period  = 0.0;
$alicuota_total        = 0.0;

$linked_accommodation_ids = array_values(
	array_unique(
		array_filter(
			array_map( 'absint', wp_list_pluck( $units, 'accommodation_id' ) )
		)
	)
);

if ( ! empty( $linked_accommodation_ids ) && ! empty( $units ) ) {
	$acc_ph    = implode( ',', array_fill( 0, count( $linked_accommodation_ids ), '%d' ) );
	$lease_rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.id, l.accommodation_id, l.guest_id, l.monthly_rent, l.start_date, l.end_date,
			        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
			 FROM {$wpdb->prefix}af_leases l
			 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
			 WHERE l.status = 'active' AND l.deleted_at IS NULL AND l.accommodation_id IN ({$acc_ph})",
			$linked_accommodation_ids
		) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built safely.
	);

	foreach ( $lease_rows as $lease_row ) {
		$units_leases[ (int) $lease_row->accommodation_id ] = $lease_row;
	}

	$lease_ids = array_map( 'absint', wp_list_pluck( $lease_rows, 'id' ) );
	if ( ! empty( $lease_ids ) ) {
		$active_lease_count = count( $lease_ids );
		$lease_ph           = implode( ',', array_fill( 0, count( $lease_ids ), '%d' ) );
		$charge_rows        = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT lease_id, charge_type, amount, amount_paid, status
				 FROM {$wpdb->prefix}af_charges
				 WHERE lease_id IN ({$lease_ph}) AND period = %s",
				array_merge( $lease_ids, array( $current_period ) )
			) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built safely.
		);

		foreach ( $charge_rows as $charge_row ) {
			$charges_by_lease[ (int) $charge_row->lease_id ][ (string) $charge_row->charge_type ] = $charge_row;
		}
	}
}

$units_meta = array();
foreach ( $units as $unit ) {
	$lease     = isset( $units_leases[ (int) $unit->accommodation_id ] ) ? $units_leases[ (int) $unit->accommodation_id ] : null;
	$unit_hoa  = Arriendo_Facil_Property_Structure::calculate_unit_hoa( (int) $unit->id );
	$alicuota_total += $unit_hoa;

	$charges = array();
	if ( $lease && isset( $charges_by_lease[ (int) $lease->id ] ) ) {
		$charges = $charges_by_lease[ (int) $lease->id ];
	}

	$pending = 0.0;
	foreach ( $charges as $charge ) {
		if ( in_array( (string) $charge->status, array( 'pending', 'partial', 'overdue' ), true ) ) {
			$pending += (float) $charge->amount - (float) $charge->amount_paid;
		}
	}
	$pending_total_period += $pending;

	if ( null === $lease ) {
		$badge = empty( (int) $unit->accommodation_id ) ? 'unlinked' : 'available';
	} elseif ( $pending > 0.01 ) {
		$badge = 'pending';
	} else {
		$badge = 'aldia';
	}

	$units_meta[ (int) $unit->id ] = array(
		'lease'   => $lease,
		'hoa'     => $unit_hoa,
		'pending' => $pending,
		'badge'   => $badge,
	);
}
// ---------------------------------------------------------------------
// Cobranza: snapshot por inmueble (qué cobrar y cuándo) del tab "Cobranza".
//
// El motor vive en Arriendo_Facil_Billing_Ledger::cobranza_snapshot() y es el
// mismo que responde al endpoint AJAX af_cobranza_snapshot. Compartirlo
// garantiza que el render inicial y la actualización en vivo (tras cobrar un
// pago) nunca discrepen en datos, importes ni textos.
// ---------------------------------------------------------------------
$cob_snapshot = Arriendo_Facil_Billing_Ledger::cobranza_snapshot();
$cob_today    = $cob_snapshot['today'];
$cob_props    = $cob_snapshot['props'];
$cob_summary  = $cob_snapshot['summary'];
$cob_statuses = $cob_snapshot['statuses'];
$cob_alerts   = $cob_snapshot['display'];

// Los inmuebles sin contrato se listan aparte, en su propio bloque colapsable:
// pintarlos también en la grilla principal los duplicaba.
$cob_active_props    = array();
$cob_available_props = array();
foreach ( $cob_props as $cob_acc_id => $cob_prop ) {
	if ( 'available' === $cob_prop['status'] ) {
		$cob_available_props[ $cob_acc_id ] = $cob_prop;
	} else {
		$cob_active_props[ $cob_acc_id ] = $cob_prop;
	}
}
?>

<div class="wrap af-shell af-buildings-page">
	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Cobranza', 'arriendo-facil' ),
			'title'    => __( 'Cobranza de inmuebles', 'arriendo-facil' ),
			'subtitle' => __( 'Cada tarjeta es un inmueble y su borde indica su estado de pago: rojo vencido, naranja por vencer, azul pendiente, verde al día y gris sin contrato. Pulsa una tarjeta para abrir su calendario de cobros, navega entre meses y registra pagos al instante.', 'arriendo-facil' ),
		)
	);
	?>

	<div class="af-tabs af-buildings-tabs">
		<input type="radio" class="af-tabs__radio" id="af-tab-cobranza" name="af-buildings-tabs" checked />
		<div class="af-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'Secciones de cobranza de inmuebles', 'arriendo-facil' ); ?>">
			<label class="af-tabs__tab" for="af-tab-cobranza" role="tab" aria-selected="true">
				<span class="af-tabs__icon" aria-hidden="true"><?php echo af_lucide( 'wallet', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-tabs__label"><?php esc_html_e( 'Cobranza', 'arriendo-facil' ); ?></span>
				<?php $cob_attention = (int) $cob_summary['vencido_count'] + (int) $cob_summary['proximo_count']; ?>
				<span class="af-tabs__badge af-tabs__badge--attention" data-cobranza-attention<?php echo $cob_attention > 0 ? '' : ' hidden'; ?>><?php echo esc_html( number_format_i18n( $cob_attention ) ); ?></span>
			</label>
		</div>
		<div class="af-tabs__panels">
			<section class="af-tabs__panel af-tabs__panel--cobranza" role="tabpanel" aria-labelledby="af-tab-cobranza">
				<?php if ( empty( $cob_active_props ) && empty( $cob_available_props ) ) : ?>
					<div class="af-empty">
						<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'building', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
						<h3 class="af-empty__title"><?php esc_html_e( 'Aún no tienes inmuebles registrados', 'arriendo-facil' ); ?></h3>
						<p class="af-empty__text"><?php esc_html_e( 'Registra tus propiedades en el Catálogo de inmuebles para que aparezcan aquí, con su canon y sus fechas de cobro.', 'arriendo-facil' ); ?></p>
						<a class="button af-btn af-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=af-catalog' ) ); ?>"><?php esc_html_e( 'Ir al catálogo', 'arriendo-facil' ); ?></a>
					</div>
				<?php else : ?>
					<section class="af-cobranza-alerts" id="af-cobranza-alerts" aria-live="polite">
						<div class="af-cobranza-alert af-cobranza-alert--danger" data-alert="vencido"<?php echo empty( $cob_alerts['vencido'] ) ? ' hidden' : ''; ?>>
							<span class="af-cobranza-alert__icon" aria-hidden="true"><?php echo af_lucide( 'triangle-alert', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							<div class="af-cobranza-alert__body">
								<strong data-alert="vencido-title"><?php echo esc_html( $cob_alerts['vencido'] ? $cob_alerts['vencido']['title'] : '' ); ?></strong>
								<span data-alert="vencido-text"><?php echo esc_html( $cob_alerts['vencido'] ? $cob_alerts['vencido']['text'] : '' ); ?></span>
							</div>
						</div>

						<div class="af-cobranza-alert af-cobranza-alert--warning" data-alert="proximo"<?php echo empty( $cob_alerts['proximo'] ) ? ' hidden' : ''; ?>>
							<span class="af-cobranza-alert__icon" aria-hidden="true"><?php echo af_lucide( 'clock', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							<div class="af-cobranza-alert__body">
								<strong data-alert="proximo-title"><?php echo esc_html( $cob_alerts['proximo'] ? $cob_alerts['proximo']['title'] : '' ); ?></strong>
								<span data-alert="proximo-text"><?php echo esc_html( $cob_alerts['proximo'] ? $cob_alerts['proximo']['text'] : '' ); ?></span>
							</div>
						</div>

						<div class="af-cobranza-alert af-cobranza-alert--ok" data-alert="mes">
							<span class="af-cobranza-alert__icon" aria-hidden="true"><?php echo af_lucide( 'calendar', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							<div class="af-cobranza-alert__body">
								<strong data-alert="mes-title"><?php echo esc_html( $cob_alerts['mes']['title'] ); ?></strong>
								<span data-alert="mes-text"><?php echo esc_html( $cob_alerts['mes']['text'] ); ?></span>
							</div>
						</div>
					</section>

					<div class="af-property-grid af-cobranza-grid" id="af-cobranza-grid">
						<?php if ( empty( $cob_active_props ) ) : ?>
							<p class="af-cobranza-grid__empty">
								<?php
								printf(
									/* translators: %d: number of properties without an active lease. */
									esc_html( _n( 'No hay inmuebles con contrato activo todavía. El %d inmueble disponible está en el bloque de abajo.', 'No hay inmuebles con contrato activo todavía. Los %d inmuebles disponibles están en el bloque de abajo.', count( $cob_available_props ), 'arriendo-facil' ) ),
									esc_html( number_format_i18n( count( $cob_available_props ) ) )
								);
								?>
							</p>
						<?php endif; ?>
						<?php foreach ( $cob_active_props as $acc_id => $prop ) : ?>
							<?php
							$csm       = $cob_statuses[ $prop['status'] ];
							$address   = trim( trim( (string) $prop['address'] ) . ( $prop['city'] ? ', ' . $prop['city'] : '' ) );
							$due_hint  = $prop['due_hint'];
							$due_class = $prop['due_class'];
							?>
							<article
								class="af-property-card af-cobranza-card <?php echo esc_attr( $csm['card'] ); ?>"
								data-prop="<?php echo esc_attr( $acc_id ); ?>"
								data-status="<?php echo esc_attr( $prop['status'] ); ?>"
								role="button"
								tabindex="0"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: property title */ __( 'Ver cobranza de %s', 'arriendo-facil' ), $prop['title'] ) ); ?>"
							>
								<div class="af-property-card__media">
									<?php if ( $prop['thumb'] ) : ?>
										<img src="<?php echo esc_url( $prop['thumb'] ); ?>" alt="<?php echo esc_attr( $prop['title'] ); ?>" loading="lazy" />
									<?php else : ?>
										<div class="af-property-card__placeholder" aria-hidden="true"><?php echo af_lucide( 'building', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></div>
									<?php endif; ?>
									<div class="af-property-card__badges">
										<span class="af-pill af-pill--<?php echo esc_attr( $csm['pill'] ); ?>" data-card-pill><?php echo esc_html( $csm['label'] ); ?></span>
									</div>
								</div>
								<div class="af-property-card__body">
									<div class="af-catalog-card__type">
										<span class="af-catalog-card__type-icon" aria-hidden="true"><?php echo af_lucide( $prop['type_icon'], 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
										<?php echo esc_html( $prop['type_label'] ); ?>
									</div>
									<h3 class="af-property-card__title"><?php echo esc_html( $prop['title'] ); ?></h3>
									<?php if ( $prop['unit_code'] ) : ?>
										<p class="af-catalog-card__address">
											<span class="af-catalog-card__address-icon" aria-hidden="true"><?php echo af_lucide( 'home', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
											<span class="af-catalog-card__address-text"><?php echo esc_html( $prop['unit_code'] . ( $prop['building_name'] ? ' · ' . $prop['building_name'] : '' ) ); ?></span>
										</p>
									<?php endif; ?>
									<?php if ( $address ) : ?>
										<p class="af-catalog-card__address">
											<span class="af-catalog-card__address-icon" aria-hidden="true"><?php echo af_lucide( 'map-pin', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
											<span class="af-catalog-card__address-text"><?php echo esc_html( $address ); ?></span>
										</p>
									<?php endif; ?>
									<p class="af-catalog-card__specs">
										<?php if ( $prop['guest'] ) : ?>
											<span class="af-catalog-card__spec">
												<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'user', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
												<?php echo esc_html( $prop['guest'] ); ?>
											</span>
										<?php endif; ?>
										<?php if ( $prop['bedrooms'] ) : ?>
											<span class="af-catalog-card__spec">
												<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'bed', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
												<?php echo esc_html( sprintf( /* translators: %d: bedrooms */ _n( '%d dorm.', '%d dorm.', $prop['bedrooms'], 'arriendo-facil' ), $prop['bedrooms'] ) ); ?>
											</span>
										<?php endif; ?>
										<?php if ( $prop['lease_end'] ) : ?>
											<span class="af-catalog-card__spec">
												<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'calendar', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
												<?php echo esc_html( sprintf( /* translators: %s: formatted date */ __( 'Contrato hasta %s', 'arriendo-facil' ), date_i18n( 'j M Y', strtotime( $prop['lease_end'] ) ) ) ); ?>
											</span>
										<?php endif; ?>
									</p>
								</div>
								<div class="af-property-card__footer">
									<span class="af-cobranza-card__amount <?php echo esc_attr( $due_class ); ?>" data-card-amount>
										$<?php echo esc_html( number_format_i18n( $prop['amount_total'], 2 ) ); ?>
										<small><?php echo esc_html( $prop['amount_label'] ); ?></small>
									</span>
									<span class="af-cobranza-card__due <?php echo esc_attr( $due_class ); ?>" data-card-due><?php echo esc_html( $due_hint ); ?></span>
									<span class="af-catalog-card__hint">
										<?php esc_html_e( 'Ver cobranza', 'arriendo-facil' ); ?>
										<span class="af-catalog-card__hint-arrow" aria-hidden="true">→</span>
									</span>
								</div>
							</article>
						<?php endforeach; ?>
					</div>

					<?php if ( ! empty( $cob_available_props ) ) : ?>
						<details class="af-collapse af-section af-cobranza-avail" id="af-cobranza-avail" style="margin-top: var(--af-space-5);">
							<summary class="af-collapse__summary">
								<span class="af-section__icon af-section__icon--slate" aria-hidden="true"><?php echo af_lucide( 'building', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
								<span class="af-collapse__label" data-avail-count><?php echo esc_html( sprintf( /* translators: %d: number of properties */ _n( '%d inmueble disponible (sin contrato)', '%d inmuebles disponibles (sin contrato)', count( $cob_available_props ), 'arriendo-facil' ), count( $cob_available_props ) ) ); ?></span>
								<span class="af-collapse__chevron" aria-hidden="true"><?php echo af_lucide( 'chevron-down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							</summary>
							<div class="af-collapse__body">
								<div class="af-property-grid" id="af-cobranza-avail-grid">
									<?php foreach ( $cob_available_props as $acc_id => $prop ) : ?>
										<?php $csm = $cob_statuses[ $prop['status'] ]; ?>
										<article class="af-property-card af-cobranza-card <?php echo esc_attr( $csm['card'] ); ?>" data-prop="<?php echo esc_attr( $acc_id ); ?>" data-status="<?php echo esc_attr( $prop['status'] ); ?>" data-avail-card role="button" tabindex="0" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: property title */ __( 'Ver cobranza de %s', 'arriendo-facil' ), $prop['title'] ) ); ?>">
											<div class="af-property-card__media">
												<?php if ( $prop['thumb'] ) : ?>
													<img src="<?php echo esc_url( $prop['thumb'] ); ?>" alt="<?php echo esc_attr( $prop['title'] ); ?>" loading="lazy" />
												<?php else : ?>
													<div class="af-property-card__placeholder" aria-hidden="true"><?php echo af_lucide( 'building', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></div>
												<?php endif; ?>
												<div class="af-property-card__badges"><span class="af-pill af-pill--<?php echo esc_attr( $csm['pill'] ); ?>" data-card-pill><?php echo esc_html( $csm['label'] ); ?></span></div>
											</div>
											<div class="af-property-card__body">
												<div class="af-catalog-card__type">
													<span class="af-catalog-card__type-icon" aria-hidden="true"><?php echo af_lucide( $prop['type_icon'], 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
													<?php echo esc_html( $prop['type_label'] ); ?>
												</div>
												<h3 class="af-property-card__title"><?php echo esc_html( $prop['title'] ); ?></h3>
											</div>
										</article>
									<?php endforeach; ?>
								</div>
							</div>
						</details>
					<?php endif; ?>
				<?php endif; ?>
			</section>

		</div><!-- .af-tabs__panels -->
	</div><!-- .af-tabs .af-buildings-tabs -->
</div>

<!-- Modal: cobranza del inmueble (calendario de cobros del mes) -->
<div class="af-modal" id="af-cobranza-modal" role="dialog" aria-modal="true" aria-labelledby="af-cobranza-modal-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog af-cobranza-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-cobranza-modal-title"></h2>
			<p class="af-modal__subtitle" id="af-cobranza-modal-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-cobranza-status" style="display:none;"></p>

			<div class="af-cobranza-cal__nav">
				<button type="button" class="button af-cobranza-cal__nav-btn" data-cal="prev" aria-label="<?php esc_attr_e( 'Mes anterior', 'arriendo-facil' ); ?>">&larr;</button>
				<h3 class="af-cobranza-cal__period">
					<span id="af-cobranza-period"></span>
					<span class="af-cobranza-cal__spinner" id="af-cobranza-spinner" hidden aria-hidden="true"></span>
				</h3>
				<button type="button" class="button af-cobranza-cal__nav-btn" data-cal="next" aria-label="<?php esc_attr_e( 'Mes siguiente', 'arriendo-facil' ); ?>">&rarr;</button>
			</div>
			<div class="af-cobranza-cal__today-row">
				<button type="button" class="button af-cobranza-cal__today" data-cal="today"><?php esc_html_e( 'Hoy', 'arriendo-facil' ); ?></button>
			</div>

			<div class="af-calendar-mini" id="af-cobranza-calendar" role="group" aria-label="<?php esc_attr_e( 'Calendario de cobros', 'arriendo-facil' ); ?>"></div>

			<div class="af-calendar-mini__legend" id="af-cobranza-legend"></div>

			<div class="af-cobranza-summary" id="af-cobranza-summary" hidden>
				<div class="af-cobranza-summary__row">
					<span class="af-cobranza-summary__item">
						<span class="af-cobranza-summary__k"><?php esc_html_e( 'Facturado', 'arriendo-facil' ); ?></span>
						<span class="af-cobranza-summary__v" data-sum="charged">$0,00</span>
					</span>
					<span class="af-cobranza-summary__item">
						<span class="af-cobranza-summary__k"><?php esc_html_e( 'Pagado', 'arriendo-facil' ); ?></span>
						<span class="af-cobranza-summary__v is-ok" data-sum="paid">$0,00</span>
					</span>
					<span class="af-cobranza-summary__item">
						<span class="af-cobranza-summary__k"><?php esc_html_e( 'Pendiente', 'arriendo-facil' ); ?></span>
						<span class="af-cobranza-summary__v is-danger" data-sum="pending">$0,00</span>
					</span>
				</div>
				<div class="af-cobranza-summary__bar" role="img" aria-label="<?php esc_attr_e( 'Progreso de cobranza del mes', 'arriendo-facil' ); ?>" id="af-cobranza-progress">
					<span class="af-cobranza-summary__fill" data-sum="fill" style="width:0%"></span>
				</div>
			</div>

			<div class="af-cobranza-dayfilter" id="af-cobranza-dayfilter" hidden></div>

			<h4 class="af-cobranza-charges__title">
				<?php esc_html_e( 'Cargos del mes', 'arriendo-facil' ); ?>
				<span class="af-cobranza-charges__total" id="af-cobranza-charges-total"></span>
			</h4>
			<ul class="af-cobranza-charges" id="af-cobranza-charges" aria-live="polite"></ul>

			<div class="af-cobranza-pay" id="af-cobranza-pay" hidden>
				<h4 class="af-cobranza-pay__title"><?php esc_html_e( 'Anotar pago', 'arriendo-facil' ); ?></h4>
				<p class="af-modal__status" id="af-cobranza-pay-status" style="display:none;"></p>
				<input type="hidden" id="af-cobranza-pay-charge-id" />
				<div class="af-modal__field">
					<label for="af-cobranza-pay-amount"><?php esc_html_e( 'Monto recibido (USD)', 'arriendo-facil' ); ?></label>
					<input type="number" id="af-cobranza-pay-amount" step="0.01" min="0.01" />
					<p class="af-modal__hint" id="af-cobranza-pay-outstanding"></p>
				</div>
				<div class="af-modal__field">
					<label for="af-cobranza-pay-date"><?php esc_html_e( 'Fecha de pago', 'arriendo-facil' ); ?></label>
					<input type="date" id="af-cobranza-pay-date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
				</div>
				<div class="af-modal__field">
					<label for="af-cobranza-pay-method"><?php esc_html_e( 'Método', 'arriendo-facil' ); ?></label>
					<select id="af-cobranza-pay-method">
						<?php foreach ( Arriendo_Facil_Billing_Ledger::payment_methods() as $method_key => $method_label ) : ?>
							<option value="<?php echo esc_attr( $method_key ); ?>"><?php echo esc_html( $method_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="af-modal__field">
					<label for="af-cobranza-pay-reference"><?php esc_html_e( 'Referencia / comprobante', 'arriendo-facil' ); ?></label>
					<input type="text" id="af-cobranza-pay-reference" placeholder="<?php esc_attr_e( 'Ej: transferencia #01234', 'arriendo-facil' ); ?>" />
				</div>
				<div class="af-cobranza-pay__actions">
					<button type="button" class="button" id="af-cobranza-pay-cancel"><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button type="button" class="button button-primary" id="af-cobranza-pay-confirm"><?php esc_html_e( 'Registrar pago', 'arriendo-facil' ); ?></button>
				</div>
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cerrar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
	<div class="af-cobranza-tip" id="af-cobranza-tip" role="tooltip" hidden></div>
</div>

<script>
	/* Snapshot inicial de cobranza. El JS lo refresca con af_cobranza_snapshot
	   cada vez que cambia de mes o se registra un pago, de modo que la grilla,
	   las alertas y el modal nunca dependen de un HTML estático. */
	window.afBuildingsCobranza = <?php echo wp_json_encode( $cob_props ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Datos JSON para el JS del calendario. ?>;
	window.afBuildingsCobranzaToday = <?php echo wp_json_encode( $cob_today ); ?>;
	window.afBuildingsCobranzaPeriod = <?php echo wp_json_encode( $cob_snapshot['period'] ); ?>;
	window.afBuildingsCobranzaStatuses = <?php echo wp_json_encode( $cob_statuses ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Presentación de estados para el JS. ?>;
</script>

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