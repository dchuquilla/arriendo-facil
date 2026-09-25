<?php
/**
 * Meter readings admin page view.
 *
 * Alert hub for metered billing: what to charge per service (agua/luz/gas), to
 * whom, and which metered accounts are still missing their reading for the
 * period. Read-only: no transactions here, readings are recorded from the
 * Cobros hub (af-cobros).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$readings_table = Arriendo_Facil_Billing_Ledger::readings_table();
$charges_table  = Arriendo_Facil_Billing_Ledger::charges_table();
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

// Stand-alone accommodations: properties not linked to any building unit.
$units_table = Arriendo_Facil_Property_Structure::units_table();
$acc_where   = "post_type = %s AND post_status IN (%s,%s,%s) AND ID NOT IN (SELECT accommodation_id FROM {$units_table} WHERE accommodation_id IS NOT NULL AND accommodation_id > 0)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$acc_args    = array( 'accommodation', 'publish', 'private', 'draft' );

if ( null !== $accessible_building_ids ) {
	$acc_where .= ' AND ID IN ( SELECT post_id FROM ' . $wpdb->postmeta . ' WHERE meta_key = %s AND meta_value = %s )';
	$acc_args[] = '_af_owner_id';
	$acc_args[] = (string) get_current_user_id();
}

$standalone_accommodations = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT ID, post_title FROM {$wpdb->posts} WHERE {$acc_where} ORDER BY post_title ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$acc_args
	)
);

$reading_scope_clause = '';
if ( null !== $accessible_building_ids ) {
	$accessible_accommodation_ids = array_map(
		'absint',
		(array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_af_owner_id',
				(string) get_current_user_id()
			)
		)
	);
	$acc_clause                   = empty( $accessible_accommodation_ids ) ? '0' : implode( ',', $accessible_accommodation_ids );
	$reading_scope_clause         = ' AND ( b.id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $accessible_building_ids ) . ') OR r.accommodation_id IN (' . $acc_clause . ') )'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

$readings = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT r.*, u.unit_code, b.name AS building_name, p.post_title AS accommodation_title,
		        ( SELECT ch.status FROM {$charges_table} ch
		          WHERE ch.charge_type = r.service AND ch.period = r.period AND ch.status <> 'void'
		            AND ( ( ch.unit_id > 0 AND ch.unit_id = r.unit_id )
		                  OR ( ( ch.unit_id IS NULL OR ch.unit_id = 0 ) AND ch.lease_id IN ( SELECT l2.id FROM {$wpdb->prefix}af_leases l2 WHERE l2.accommodation_id = r.accommodation_id ) ) )
		          ORDER BY ch.id DESC LIMIT 1 ) AS charge_status
		 FROM {$readings_table} r
		 LEFT JOIN {$units_table} u ON u.id = r.unit_id
		 LEFT JOIN " . Arriendo_Facil_Property_Structure::buildings_table() . " b ON b.id = u.building_id
		 LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
		 WHERE r.period = %s{$reading_scope_clause}
		 ORDER BY b.name ASC, u.unit_code ASC, p.post_title ASC, r.service ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$period_filter
	)
);

$period_total = 0.0;
foreach ( (array) $readings as $reading ) {
	$period_total += (float) $reading->calculated_amount;
}

// Summary per service for the selected period: count, consumption, billed total.
$service_summary = array();
foreach ( array_keys( $services ) as $service_key ) {
	$service_summary[ $service_key ] = array(
		'count'       => 0,
		'consumption' => 0.0,
		'amount'      => 0.0,
	);
}
foreach ( (array) $readings as $reading ) {
	if ( ! isset( $service_summary[ $reading->service ] ) ) {
		continue;
	}
	$service_summary[ $reading->service ]['count']++;
	$service_summary[ $reading->service ]['consumption'] += (float) $reading->consumption;
	$service_summary[ $reading->service ]['amount']      += (float) $reading->calculated_amount;
}

// Accounts that are already being metered (they have reading history) with an
// active lease overlapping the period but no reading registered yet.
$pending_accounts = array();
if ( ! empty( $buildings ) || ! empty( $standalone_accommodations ) ) {
	$period_from = $period_filter . '-01';
	$period_last = gmdate( 'Y-m-t', strtotime( $period_from ) );

	$pending_scope = '';
	if ( null !== $accessible_building_ids ) {
		$accessible_acc_ids = array_map(
			'absint',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
					'_af_owner_id',
					(string) get_current_user_id()
				)
			)
		);
		$acc_in            = empty( $accessible_acc_ids ) ? '0' : implode( ',', $accessible_acc_ids );
		$pending_scope     = ' AND ( u.building_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $accessible_building_ids ) . ') OR l.accommodation_id IN (' . $acc_in . ') )'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	$active_leases = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.id, l.accommodation_id, l.monthly_rent,
			        CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
			        p.post_title AS accommodation_title,
			        u.id AS unit_id, u.unit_code, b.name AS building_name
			 FROM {$wpdb->prefix}af_leases l
			 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
			 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
			 LEFT JOIN {$units_table} u ON u.accommodation_id = l.accommodation_id AND u.status = 'active'
			 LEFT JOIN " . Arriendo_Facil_Property_Structure::buildings_table() . " b ON b.id = u.building_id
			 WHERE l.status = 'active' AND l.deleted_at IS NULL
			   AND l.start_date <= %s AND l.end_date >= %s{$pending_scope}
			 ORDER BY b.name ASC, u.unit_code ASC, p.post_title ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$period_last,
			$period_from
		)
	);

	foreach ( $active_leases as $lease ) {
		$unit_key = (int) $lease->unit_id;
		$acc_key  = $unit_key ? 0 : (int) $lease->accommodation_id;

		$prior_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$readings_table} WHERE unit_id = %d AND accommodation_id = %d AND period < %s",
				$unit_key,
				$acc_key,
				$period_filter
			)
		);
		if ( ! $prior_count ) {
			continue;
		}

		$month_services = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT service FROM {$readings_table} WHERE unit_id = %d AND accommodation_id = %d AND period = %s",
				$unit_key,
				$acc_key,
				$period_filter
			)
		);
		if ( ! empty( $month_services ) ) {
			continue;
		}

		$lease->missing_services = array_keys( $services );
		$lease->unit_key         = $unit_key;
		$lease->acc_key          = $acc_key;
		$pending_accounts[]      = $lease;
	}
}

$charge_status_map = array(
	'paid'    => array( 'success', __( 'Pagado', 'arriendo-facil' ) ),
	'partial' => array( 'warning', __( 'Parcial', 'arriendo-facil' ) ),
	'pending' => array( 'warning', __( 'Pendiente', 'arriendo-facil' ) ),
	'overdue' => array( 'danger', __( 'Vencido', 'arriendo-facil' ) ),
);
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Cobranza por servicios', 'arriendo-facil' ),
			'title'    => __( 'Lecturas de medidor', 'arriendo-facil' ),
			'subtitle' => __( 'Alertas de qué cobrar por agua, luz y gas: por servicio, cuánto se consumió y cobró en el periodo, y qué medidores quedaron sin lectura. Estas lecturas se registran en Cobros; aquí solo ves el estado.', 'arriendo-facil' ),
		)
	);
	?>

	<?php if ( empty( $buildings ) && empty( $standalone_accommodations ) ) : ?>
		<div class="af-empty">
			<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'gauge', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<h3 class="af-empty__title"><?php esc_html_e( 'Nada que medir todavía', 'arriendo-facil' ); ?></h3>
			<p class="af-empty__text">
				<?php
				printf(
					/* translators: %s: link to the buildings page */
					esc_html__( 'Esta sección alerta lo que se cobra por agua, luz o gas según lo que cada propiedad consume. Para empezar, registra un edificio con sus unidades en %s o crea una propiedad en el catálogo.', 'arriendo-facil' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=af-buildings' ) ) . '">' . esc_html__( 'Cobranza de inmuebles', 'arriendo-facil' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php else : ?>

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
						__( 'Total del periodo: $%s', 'arriendo-facil' ),
						number_format_i18n( $period_total, 2 )
					)
				);
				?>
			</span>
			<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-cobros' ) ); ?>"><?php esc_html_e( 'Ir a Cobros para registrar', 'arriendo-facil' ); ?></a>
		</form>

		<section class="af-section" style="margin-bottom: var(--af-space-5);">
			<header class="af-section__header">
				<div class="af-section__head">
					<h2 class="af-section__title"><?php esc_html_e( 'Resumen por servicio', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php echo esc_html( sprintf( /* translators: %s: period */ __( 'Cuánto se cobró por cada servicio en %s', 'arriendo-facil' ), $period_filter ) ); ?></p>
				</div>
			</header>
			<div class="af-kpi-grid af-service-summary">
				<?php
				$service_variants = array( 'agua' => 'info', 'luz' => 'accent', 'gas' => 'attention' );
				$service_icons    = array( 'agua' => 'bath', 'luz' => 'sparkles', 'gas' => 'trending-up' );
				foreach ( $services as $service_key => $service_label ) :
					$summary = $service_summary[ $service_key ];
					$variant = isset( $service_variants[ $service_key ] ) ? $service_variants[ $service_key ] : 'info';
					$icon    = isset( $service_icons[ $service_key ] ) ? $service_icons[ $service_key ] : 'gauge';
					?>
					<article class="af-kpi af-kpi--<?php echo esc_attr( $variant ); ?>">
						<div class="af-kpi__head">
							<span class="af-kpi__label"><?php echo esc_html( $service_label ); ?></span>
							<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
						</div>
						<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $summary['amount'], 2 ) ); ?></div>
						<div class="af-kpi__hint">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: reading count, 2: total consumption */
									_n( '%1$d lectura · %2$s de consumo', '%1$d lecturas · %2$s de consumo', $summary['count'], 'arriendo-facil' ),
									$summary['count'],
									number_format_i18n( $summary['consumption'], 3 )
								)
							);
							?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="af-section" style="margin-bottom: var(--af-space-5);">
			<header class="af-section__header">
				<div class="af-section__head">
					<h2 class="af-section__title"><?php esc_html_e( 'Medidores sin lectura', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php echo esc_html( sprintf( /* translators: %s: period */ __( 'Tienen contrato activo e histórico, pero aún falta su lectura en %s: no se puede cobrar el consumo.', 'arriendo-facil' ), $period_filter ) ); ?></p>
				</div>
				<span class="af-pill <?php echo 0 === count( $pending_accounts ) ? 'af-pill--success' : 'af-pill--warning'; ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: pending count */
							_n( '%d pendiente', '%d pendientes', count( $pending_accounts ), 'arriendo-facil' ),
							count( $pending_accounts )
						)
					);
					?>
				</span>
			</header>

			<?php if ( empty( $pending_accounts ) ) : ?>
				<p class="af-empty__text" style="margin:0;"><?php esc_html_e( 'Todos los medidores con contrato activo ya tienen su lectura de este mes. Si un inquilino no tiene histórico de medidor, aparecerá aquí recién después de su primera lectura.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped af-data-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Medidor', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Quién consume', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Servicios', 'arriendo-facil' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pending_accounts as $pending ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Medidor', 'arriendo-facil' ); ?>">
									<strong><?php echo esc_html( $pending->unit_code ? $pending->unit_code : ( $pending->accommodation_title ? $pending->accommodation_title : __( 'Inmueble', 'arriendo-facil' ) ) ); ?></strong>
									<span class="af-td-meta"><?php echo esc_html( $pending->unit_code && $pending->building_name ? $pending->building_name : __( 'Propiedad independiente', 'arriendo-facil' ) ); ?></span>
								</td>
								<td data-label="<?php esc_attr_e( 'Quién consume', 'arriendo-facil' ); ?>">
									<span class="af-td-meta"><?php echo esc_html( (string) trim( (string) $pending->guest_name ) !== '' ? $pending->guest_name : __( 'Inquilino de contrato activo', 'arriendo-facil' ) ); ?></span>
								</td>
								<td data-label="<?php esc_attr_e( 'Servicios', 'arriendo-facil' ); ?>">
									<?php foreach ( $pending->missing_services as $p_service ) : ?>
										<span class="af-pill af-pill--info"><?php echo esc_html( $services[ $p_service ] ?? $p_service ); ?></span>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<section class="af-section">
			<header class="af-section__header">
				<div class="af-section__head">
					<h2 class="af-section__title"><?php esc_html_e( 'Lecturas del periodo', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php echo esc_html( $period_filter ); ?></p>
				</div>
			</header>

			<table class="wp-list-table widefat fixed striped af-data-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Quién consume', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Servicio', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Anterior', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Actual', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Consumo', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Precio', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Importe', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Cobro', 'arriendo-facil' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $readings ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'Aún no hay lecturas en este periodo. Regístralas desde Cobros.', 'arriendo-facil' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $readings as $reading ) : ?>
							<?php
							$charge_pill = $charge_status_map[ $reading->charge_status ] ?? array( 'neutral', __( 'Sin cargo', 'arriendo-facil' ) );
							?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Quién consume', 'arriendo-facil' ); ?>">
									<strong><?php echo esc_html( $reading->unit_code ? $reading->unit_code : ( $reading->accommodation_title ? $reading->accommodation_title : __( 'Inmueble', 'arriendo-facil' ) ) ); ?></strong>
									<span class="af-td-meta"><?php echo esc_html( $reading->unit_code ? $reading->building_name : __( 'Propiedad independiente', 'arriendo-facil' ) ); ?></span>
								</td>
								<td data-label="<?php esc_attr_e( 'Servicio', 'arriendo-facil' ); ?>"><?php echo esc_html( $services[ $reading->service ] ?? $reading->service ); ?></td>
								<td data-label="<?php esc_attr_e( 'Anterior', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $reading->previous_reading, 3 ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Actual', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $reading->current_reading, 3 ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Consumo', 'arriendo-facil' ); ?>"><strong><?php echo esc_html( number_format_i18n( (float) $reading->consumption, 3 ) ); ?></strong></td>
								<td data-label="<?php esc_attr_e( 'Precio', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $reading->unit_rate, 4 ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Importe', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $reading->calculated_amount, 2 ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Cobro', 'arriendo-facil' ); ?>">
									<span class="af-pill af-pill--<?php echo esc_attr( $charge_pill[0] ); ?>"><?php echo esc_html( $charge_pill[1] ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>
</div>