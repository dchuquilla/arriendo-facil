<?php
/**
 * Pagos de servicios: alert hub of when each utility is due.
 *
 * Framed as a due-date hub rather than a consumption log: the operator configures
 * once per property + service the day of the month the utility is paid, and this
 * page answers "what is due, when, for which property and who owes it".
 *
 * The due-date rules live in af_service_schedules and are materialised as
 * af_charges by Arriendo_Facil_Billing_Ledger::generate_service_charges(), so
 * the payments, collections and overdue cron keep working unchanged. Meter
 * readings stay as a secondary block because the amount of a metered service
 * comes from them.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$ledger  = 'Arriendo_Facil_Billing_Ledger';
$catalog = $ledger::service_catalog();

$today  = current_time( 'Y-m-d' );
$period = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : current_time( 'Y-m' );
// Same validator the ledger uses, so ?period=2026-13 cannot reach the queries.
$period = $ledger::validate_period( $period );
if ( '' === $period ) {
	$period = current_time( 'Y-m' );
}

$service_filter = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : '';
if ( $service_filter && ! isset( $catalog[ $service_filter ] ) ) {
	$service_filter = '';
}

$status_filter = isset( $_GET['due_status'] ) ? sanitize_key( wp_unslash( $_GET['due_status'] ) ) : '';
$allowed_statuses = array( 'overdue', 'due_soon', 'pending', 'partial', 'paid', 'no_charge', 'no_lease', 'inactive' );
if ( $status_filter && ! in_array( $status_filter, $allowed_statuses, true ) ) {
	$status_filter = '';
}

$scope_ids    = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
$rows         = $ledger::get_service_due_rows( $period, $scope_ids );
$all_schedules = $ledger::get_service_schedules( array( 'accommodation_ids' => $scope_ids, 'active_only' => false ) );

// Property selector for the rule editor: the accommodations the operator can
// manage, so a rule can only be created where it will be readable back.
$property_options = array();
$accessible_posts = get_posts(
	array(
		'post_type'        => 'accommodation',
		'post_status'      => array( 'publish', 'draft', 'private' ),
		'posts_per_page'   => 500,
		'orderby'          => 'title',
		'order'            => 'ASC',
		'suppress_filters' => false,
		'fields'           => 'ids',
	)
);

foreach ( (array) $accessible_posts as $acc_id ) {
	if ( null !== $scope_ids && ! in_array( (int) $acc_id, array_map( 'absint', $scope_ids ), true ) ) {
		continue;
	}
	$property_options[] = array(
		'id'    => (int) $acc_id,
		'title' => get_the_title( (int) $acc_id ),
	);
}

/**
 * Human labels for the derived due status.
 */
$status_meta = array(
	'overdue'  => array(
		'label' => __( 'Vencido', 'arriendo-facil' ),
		'pill'  => 'danger',
		'icon'  => 'triangle-alert',
	),
	'due_soon' => array(
		'label' => __( 'Por vencer', 'arriendo-facil' ),
		'pill'  => 'warning',
		'icon'  => 'clock',
	),
	'pending'  => array(
		'label' => __( 'Programado', 'arriendo-facil' ),
		'pill'  => 'info',
		'icon'  => 'calendar',
	),
	'partial'  => array(
		'label' => __( 'Pago parcial', 'arriendo-facil' ),
		'pill'  => 'warning',
		'icon'  => 'credit-card',
	),
	'paid'     => array(
		'label' => __( 'Pagado', 'arriendo-facil' ),
		'pill'  => 'success',
		'icon'  => 'circle-check',
	),
	'no_charge' => array(
		'label' => __( 'Sin monto', 'arriendo-facil' ),
		'pill'  => 'neutral',
		'icon'  => 'circle-alert',
	),
	'no_lease' => array(
		'label' => __( 'Sin inquilino', 'arriendo-facil' ),
		'pill'  => 'neutral',
		'icon'  => 'user',
	),
	'inactive' => array(
		'label' => __( 'Regla inactiva', 'arriendo-facil' ),
		'pill'  => 'neutral',
		'icon'  => 'circle-alert',
	),
);

/**
 * Where the amount of a row comes from, so the operator can tell a metered
 * amount from a fixed one without opening anything.
 */
$amount_meta = array(
	'fixed'  => __( 'Tarifa fija', 'arriendo-facil' ),
	'metered' => __( 'Consumo medido', 'arriendo-facil' ),
	'auto'   => __( 'Lectura o tarifa fija', 'arriendo-facil' ),
);

// ---------------------------------------------------------------------------
// Filters and aggregates.
// ---------------------------------------------------------------------------

$visible = array_values(
	array_filter(
		$rows,
		static function ( $row ) use ( $service_filter, $status_filter ) {
			if ( $service_filter && $row['service'] !== $service_filter ) {
				return false;
			}
			if ( $status_filter && $row['status'] !== $status_filter ) {
				return false;
			}
			return true;
		}
	)
);

$sum_expected    = 0.0;
$sum_outstanding = 0.0;
$sum_overdue     = 0.0;
$sum_week        = 0.0;
$sum_paid        = 0.0;
$count_pending   = 0;
$count_overdue   = 0;
$count_week      = 0;
$count_paid      = 0;

foreach ( $visible as $row ) {
	if ( 'inactive' === $row['status'] ) {
		continue;
	}

	// Only the obligations that still owe money. Paid rows used to be counted
	// here, which made the counter disagree with the amount next to it.
	$open_amount = $row['has_charge'] ? $row['outstanding'] : $row['expected_amount'];

	if ( $open_amount > 0 ) {
		++$count_pending;
		$sum_outstanding += $open_amount;
	}

	switch ( $row['status'] ) {
		case 'overdue':
			++$count_overdue;
			$sum_overdue += $open_amount;
			break;
		case 'due_soon':
			++$count_week;
			$sum_week += $open_amount;
			break;
		case 'paid':
			++$count_paid;
			$sum_paid += $row['amount_paid'];
			break;
	}

	$sum_expected += $row['expected_amount'];
}

// Active leases with no rule at all: the gap that makes a service get forgotten.
$configured_scopes = array();
foreach ( $all_schedules as $schedule ) {
	$key = $schedule->accommodation_id ? 'acc:' . (int) $schedule->accommodation_id : 'unit:' . (int) $schedule->unit_id;
	$configured_scopes[ $key ] = true;
}

$unconfigured_leases = array();

/**
 * Scope of the accommodation-level queries below.
 *
 * null means "no filter" (administrator, full visibility) and an empty array
 * means "the operator manages nothing, so nothing is visible". Conflating the
 * two is what previously made a property admin without properties fall back to
 * seeing every property on the installation.
 */
$lease_scope_ids = null;

if ( is_array( $scope_ids ) ) {
	$lease_scope_ids = array_values( array_unique( array_filter( array_map( 'absint', $scope_ids ) ) ) );
}

$lease_scope_sql = '';
if ( is_array( $lease_scope_ids ) && $lease_scope_ids ) {
	$lease_scope_sql = ' AND l.accommodation_id IN (' . implode( ',', $lease_scope_ids ) . ')';
} elseif ( is_array( $lease_scope_ids ) ) {
	// Empty scope: skip the queries entirely instead of running them unfiltered.
	$unconfigured_leases = array();
	$leases              = array();
} else {
	$leases = (array) $wpdb->get_results(
		"SELECT l.accommodation_id, MAX(l.id) AS lease_id, p.post_title AS accommodation_title
		 FROM {$wpdb->prefix}af_leases l
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 WHERE l.status = 'active' AND l.deleted_at IS NULL
		   {$lease_scope_sql}
		 GROUP BY l.accommodation_id, p.post_title
		 ORDER BY p.post_title ASC
		 LIMIT 200" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);

	$lease_scope_keys = array();
	foreach ( $leases as $lease_row ) {
		$lease_scope_keys[ 'acc:' . (int) $lease_row->accommodation_id ] = $lease_row;
	}

	// A unit-scoped rule also covers the accommodation of that unit, so a lease
	// only counts as unconfigured when neither scope has any rule.
	$unit_scoped_covered = array();
	foreach ( $all_schedules as $schedule ) {
		if ( (int) $schedule->unit_id ) {
			$unit = Arriendo_Facil_Property_Structure::get_unit( (int) $schedule->unit_id );
			if ( $unit && ! empty( $unit->accommodation_id ) ) {
				$unit_scoped_covered[ (int) $unit->accommodation_id ] = true;
			}
		}
	}

	foreach ( $lease_scope_keys as $key => $lease_row ) {
		$acc_id = (int) $lease_row->accommodation_id;
		if ( isset( $configured_scopes[ $key ] ) || isset( $unit_scoped_covered[ $acc_id ] ) ) {
			continue;
		}
		$unconfigured_leases[] = $lease_row;
	}
}

$unconfigured_count = count( $unconfigured_leases );

// Readings of the period, kept as the secondary "consumo" block. The scope
// filter is mandatory: without it a property admin would see the consumption
// of every property in the installation.
$reading_scope_sql = '';
if ( is_array( $lease_scope_ids ) && $lease_scope_ids ) {
	$reading_scope_sql = ' AND COALESCE( NULLIF( r.accommodation_id, 0 ), u.accommodation_id ) IN (' . implode( ',', $lease_scope_ids ) . ')';
} elseif ( is_array( $lease_scope_ids ) ) {
	// Operator without properties: no readings to show, and no query to leak them.
	$reading_rows = array();
}

if ( ! isset( $reading_rows ) ) {
	$reading_rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT r.*, u.unit_code, p.post_title AS accommodation_title
			 FROM {$ledger::readings_table()} r
			 LEFT JOIN " . Arriendo_Facil_Property_Structure::units_table() . " u ON u.id = r.unit_id
			 LEFT JOIN {$wpdb->posts} p ON p.ID = COALESCE( NULLIF( r.accommodation_id, 0 ), u.accommodation_id )
			 WHERE r.period = %s{$reading_scope_sql}
			 ORDER BY p.post_title ASC, u.unit_code ASC, r.service ASC
			 LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$period
		)
	);
}

$reading_total = 0.0;
foreach ( $reading_rows as $reading_row ) {
	$reading_total += (float) $reading_row->calculated_amount;
}

$ledger_nonce = wp_create_nonce( 'af_ledger_nonce' );
?>

<div class="wrap af-shell af-service-payments">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Alertas de cobro', 'arriendo-facil' ),
			'title'    => __( 'Pagos de servicios', 'arriendo-facil' ),
			'subtitle' => __( 'Configura una vez el día de pago de cada servicio y esta vista te dice qué vence, cuándo y de quién, mes a mes. Registra el pago o avisa al inquilino sin salir de aquí.', 'arriendo-facil' ),
			'actions'  => array(
				array(
					'label'    => __( 'Registrar lectura', 'arriendo-facil' ),
					'url'      => admin_url( 'admin.php?page=af-cobros' ),
					'variant'  => 'ghost',
					'icon'     => 'gauge',
				),
				array(
					'label'    => __( 'Configurar vencimientos', 'arriendo-facil' ),
					'url'      => '#af-schedule-rules',
					'variant'  => 'ghost',
					'icon'     => 'settings',
				),
			),
		)
	);
	?>

	<?php if ( $unconfigured_count > 0 ) : ?>
		<div class="af-service-callout af-service-callout--warning">
			<span class="af-service-callout__icon" aria-hidden="true"><?php echo af_lucide( 'bell', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<div class="af-service-callout__body">
				<strong><?php echo esc_html( sprintf( /* translators: %d */ __( '%d contratos activos sin fecha de pago de servicios', 'arriendo-facil' ), $unconfigured_count ) ); ?></strong>
				<p><?php esc_html_e( 'Mientras un inmueble no tenga reglas, sus servicios no aparecen en las alertas ni se cobran solos. Configura el día de pago de agua, luz, gas o internet para no depender de la memoria.', 'arriendo-facil' ); ?></p>
			</div>
			<button type="button" class="button af-btn af-btn--primary" data-af-open-schedule="<?php echo esc_attr( $unconfigured_leases[0]->accommodation_id ); ?>">
				<?php esc_html_e( 'Configurar ahora', 'arriendo-facil' ); ?>
			</button>
		</div>
	<?php endif; ?>

	<div class="af-kpi-grid">
		<article class="af-kpi af-kpi--accent">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'A cobrar en el periodo', 'arriendo-facil' ); ?></span>
			</div>
			<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $sum_outstanding + $sum_expected, 2 ) ); ?></div>
			<div class="af-kpi__hint">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: count, 2: amount not yet charged */
					_n( '%1$d servicio por facturar', '%1$d servicios por facturar', $count_pending, 'arriendo-facil' ),
					$count_pending

					)
				);
				?>
				<?php if ( $sum_expected > 0 ) : ?>
					· <?php echo esc_html( sprintf( /* translators: %s */ __( '%s aún sin cargo', 'arriendo-facil' ), number_format_i18n( $sum_expected, 2 ) ) ); ?>
				<?php endif; ?>
			</div>
		</article>
		<article class="af-kpi <?php echo $count_overdue ? 'af-kpi--attention' : ''; ?>">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Vencidos', 'arriendo-facil' ); ?></span>
			</div>
			<div class="af-kpi__value" style="color:<?php echo $count_overdue ? '#b42318' : 'inherit'; ?>;">
				<?php echo esc_html( number_format_i18n( $count_overdue ) ); ?>
			</div>
			<div class="af-kpi__hint">
				<?php
				echo $count_overdue
					? esc_html( sprintf( /* translators: %s */ __( '%s USD sin cobrar', 'arriendo-facil' ), number_format_i18n( $sum_overdue, 2 ) ) )
					: esc_html__( 'Nada vencido', 'arriendo-facil' );
				?>
			</div>
		</article>
		<article class="af-kpi">
			<div class="af-kpi__head">
				<span class="af-kpi__label">
					<?php
					// Rendered from the shared constant so the card can never promise a
					// window the ledger does not actually flag.
					echo esc_html(
						sprintf(
							/* translators: %d: number of days */
							__( 'Próximos %d días', 'arriendo-facil' ),
							(int) Arriendo_Facil_Billing_Ledger::SERVICE_DUE_SOON_DAYS
						)
					);
					?>
				</span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $count_week ) ); ?></div>
			<div class="af-kpi__hint">
				<?php
				echo $count_week
					? esc_html( sprintf( /* translators: %s */ __( '%s USD por vencer', 'arriendo-facil' ), number_format_i18n( $sum_week, 2 ) ) )
					: esc_html__( 'Sin cobros próximos', 'arriendo-facil' );
				?>
			</div>
		</article>
		<article class="af-kpi af-kpi--success">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Pagados', 'arriendo-facil' ); ?></span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $count_paid ) ); ?></div>
			<div class="af-kpi__hint">
				<?php
				echo $count_paid
					? esc_html( sprintf( /* translators: %s */ __( '%s USD recibidos', 'arriendo-facil' ), number_format_i18n( $sum_paid, 2 ) ) )
					: esc_html__( 'Sin pagos registrados', 'arriendo-facil' );
				?>
			</div>
		</article>
	</div>

	<form method="get" class="af-section af-service-filters">
		<input type="hidden" name="page" value="af-meter-readings" />
		<div class="af-service-filters__grid">
			<div class="af-service-filters__field">
				<label for="af-filter-period"><?php esc_html_e( 'Periodo', 'arriendo-facil' ); ?></label>
				<input type="month" id="af-filter-period" name="period" value="<?php echo esc_attr( $period ); ?>" />
			</div>
			<div class="af-service-filters__field">
				<label for="af-filter-service"><?php esc_html_e( 'Servicio', 'arriendo-facil' ); ?></label>
				<select id="af-filter-service" name="service">
					<option value=""><?php esc_html_e( 'Todos los servicios', 'arriendo-facil' ); ?></option>
					<?php foreach ( $catalog as $service_key => $service_meta ) : ?>
						<option value="<?php echo esc_attr( $service_key ); ?>" <?php selected( $service_filter, $service_key ); ?>>
							<?php echo esc_html( $service_meta['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="af-service-filters__field">
				<label for="af-filter-status"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></label>
				<select id="af-filter-status" name="due_status">
					<option value=""><?php esc_html_e( 'Todos los estados', 'arriendo-facil' ); ?></option>
					<?php foreach ( $status_meta as $status_key => $status_info ) : ?>
						<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status_filter, $status_key ); ?>>
							<?php echo esc_html( $status_info['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="af-service-filters__actions">
				<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Filtrar', 'arriendo-facil' ); ?></button>
				<?php if ( $service_filter || $status_filter ) : ?>
					<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-meter-readings&period=' . $period ) ); ?>"><?php esc_html_e( 'Limpiar', 'arriendo-facil' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<div class="af-service-filters__summary">
			<span class="af-pill af-pill--neutral"><?php echo esc_html( sprintf( /* translators: 1: month label, 2: count */ __( '%1$s · %2$d servicios', 'arriendo-facil' ), $period, count( $visible ) ) ); ?></span>
			<button type="button" class="button af-btn af-btn--ghost" id="af-generate-services">
				<?php echo af_lucide( 'refresh-cw', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Generar vencimientos del mes', 'arriendo-facil' ); ?>
			</button>
		</div>
	</form>

	<section class="af-section" aria-labelledby="af-due-title">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title" id="af-due-title"><?php esc_html_e( 'Qué vence y cuándo', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Un renglón por inmueble y servicio, ordenado por fecha de pago. Registra el pago o avisa al inquilino con un clic.', 'arriendo-facil' ); ?></p>
			</div>
			<div class="af-section__actions">
				<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections' ) ); ?>"><?php esc_html_e( 'Control de pagos', 'arriendo-facil' ); ?></a>
				<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-buildings' ) ); ?>"><?php esc_html_e( 'Cobranza de inmuebles', 'arriendo-facil' ); ?></a>
			</div>
		</header>

		<?php if ( empty( $visible ) ) : ?>
			<div class="af-empty">
				<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'calendar', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h3 class="af-empty__title"><?php esc_html_e( 'Sin vencimientos que mostrar', 'arriendo-facil' ); ?></h3>
				<p class="af-empty__text">
					<?php
					if ( empty( $all_schedules ) ) {
						esc_html_e( 'Todavía no configuraste ninguna regla de pago. Agrega el día de pago de agua, luz, gas o internet de cada inmueble y aquí aparecerán las alertas de cada mes.', 'arriendo-facil' );
					} else {
						esc_html_e( 'No hay reglas que coincidan con los filtros. Prueba con otro periodo, servicio o estado.', 'arriendo-facil' );
					}
					?>
				</p>
				<?php if ( empty( $all_schedules ) ) : ?>
					<button type="button" class="button af-btn af-btn--primary" data-af-open-schedule>
						<?php esc_html_e( 'Agregar primera regla', 'arriendo-facil' ); ?>
					</button>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped af-data-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Servicio', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Vence', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Monto', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $visible as $row ) : ?>
						<?php
						$meta       = $status_meta[ $row['status'] ];
						$days       = $row['due_date'] ? (int) ( ( strtotime( $row['due_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS ) : null;
						$is_overdue = 'overdue' === $row['status'];
						$title      = $row['accommodation_title'] ? $row['accommodation_title'] : __( 'Inmueble', 'arriendo-facil' );
						$amount     = $row['has_charge'] ? $row['charge_amount'] : $row['expected_amount'];
						$origin     = isset( $amount_meta[ $row['amount_mode'] ] ) ? $amount_meta[ $row['amount_mode'] ] : '';

						if ( $row['has_charge' ] && ! $row['has_reading'] && 'fixed' !== $row['amount_mode'] && $row['expected_amount'] > 0 ) {
							$origin = __( 'Lectura pendiente', 'arriendo-facil' );
						}

						$aviso_url = admin_url(
							'admin.php?page=af-avisos&af_aviso_lease=' . (int) $row['lease_id'] . '&af_aviso_subject=' . rawurlencode(
								sprintf(
									/* translators: 1: service name, 2: date */
									__( 'Recordatorio: %1$s se paga el %2$s', 'arriendo-facil' ),
									$row['service_label'],
									$row['due_date'] ? gmdate( 'd/m/Y', strtotime( $row['due_date'] ) ) : ''
								)
							) . '&af_aviso_type=cobro'
						);
						?>
						<tr data-status="<?php echo esc_attr( $row['status'] ); ?>" data-service="<?php echo esc_attr( $row['service'] ); ?>">
							<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>">
								<strong><?php echo esc_html( '' !== $row['unit_code'] ? $row['unit_code'] : $title ); ?></strong>
								<?php if ( $row['unit_code'] && $row['accommodation_title'] ) : ?>
									<div class="af-td-meta"><?php echo esc_html( $row['accommodation_title'] ); ?></div>
								<?php endif; ?>
								<?php if ( $row['guest_name'] ) : ?>
									<div class="af-td-meta"><?php echo esc_html( $row['guest_name'] ); ?></div>
								<?php else : ?>
									<div class="af-td-meta"><?php esc_html_e( 'Sin inquilino activo', 'arriendo-facil' ); ?></div>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Servicio', 'arriendo-facil' ); ?>">
								<span class="af-service-chip">
									<?php
									$icon_key = isset( $catalog[ $row['service'] ]['icon'] ) ? $catalog[ $row['service'] ]['icon'] : 'circle-alert';
									echo af_lucide( $icon_key, 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
									<?php echo esc_html( $row['service_label'] ); ?>
								</span>
								<div class="af-td-meta"><?php echo esc_html( $origin ); ?></div>
							</td>
							<td data-label="<?php esc_attr_e( 'Vence', 'arriendo-facil' ); ?>">
								<?php if ( $row['due_date'] ) : ?>
									<strong><?php echo esc_html( gmdate( 'd/m/Y', strtotime( $row['due_date'] ) ) ); ?></strong>
									<div class="af-td-meta">
										<?php
										if ( 'paid' === $row['status'] ) {
											esc_html_e( 'Pagado', 'arriendo-facil' );
										} elseif ( $is_overdue ) {
											echo esc_html( sprintf( /* translators: %d */ __( 'hace %d días', 'arriendo-facil' ), abs( (int) $days ) ) );
										} elseif ( 0 === (int) $days ) {
											esc_html_e( 'vence hoy', 'arriendo-facil' );
										} else {
											echo esc_html( sprintf( /* translators: %d */ __( 'en %d días', 'arriendo-facil' ), (int) $days ) );
										}
										?>
									</div>
								<?php else : ?>
									<span class="af-td-meta">—</span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Monto', 'arriendo-facil' ); ?>">
								<?php if ( $amount > 0 ) : ?>
									<strong>$<?php echo esc_html( number_format_i18n( $amount, 2 ) ); ?></strong>
									<?php if ( $row['has_charge'] && $row['amount_paid'] > 0 ) : ?>
										<div class="af-td-meta">
											<?php echo esc_html( sprintf( /* translators: %s */ __( 'pagado %s', 'arriendo-facil' ), number_format_i18n( $row['amount_paid'], 2 ) ) ); ?>
										</div>
									<?php endif; ?>
								<?php else : ?>
									<span class="af-td-meta"><?php esc_html_e( 's/ref', 'arriendo-facil' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>">
								<span class="af-pill af-pill--<?php echo esc_attr( $meta['pill'] ); ?>">
									<?php echo esc_html( $meta['label'] ); ?>
								</span>
							</td>
							<td data-label="<?php esc_attr_e( 'Acciones', 'arriendo-facil' ); ?>">
								<div class="af-service-actions">
									<?php if ( $row['has_charge'] && $row['outstanding'] > 0 ) : ?>
										<button
											type="button"
											class="button button-small af-record-payment"
											data-charge="<?php echo esc_attr( (string) $row['charge_id'] ); ?>"
											data-outstanding="<?php echo esc_attr( (string) $row['outstanding'] ); ?>"
											data-concept="<?php echo esc_attr( $row['service_label'] . ' · ' . $period ); ?>"
											data-tenant="<?php echo esc_attr( $row['guest_name'] ? $row['guest_name'] : $title ); ?>"
										><?php esc_html_e( 'Registrar pago', 'arriendo-facil' ); ?></button>
									<?php elseif ( $row['needs_charge'] ) : ?>
										<button type="button" class="button button-small af-generate-services" data-period="<?php echo esc_attr( $period ); ?>">
											<?php esc_html_e( 'Generar cargo', 'arriendo-facil' ); ?>
										</button>
									<?php elseif ( 'paid' === $row['status'] ) : ?>
										<span class="af-service-actions__done"><?php echo af_lucide( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Saldado', 'arriendo-facil' ); ?></span>
									<?php endif; ?>

									<?php if ( $row['lease_id'] ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $aviso_url ); ?>"><?php esc_html_e( 'Avisar', 'arriendo-facil' ); ?></a>
									<?php endif; ?>

									<button
										type="button"
										class="button button-small af-edit-schedule"
										data-schedule="<?php echo esc_attr( (string) $row['schedule_id'] ); ?>"
										data-unit="<?php echo esc_attr( (string) $row['unit_id'] ); ?>"
										data-accommodation="<?php echo esc_attr( (string) $row['accommodation_id'] ); ?>"
										data-service="<?php echo esc_attr( $row['service'] ); ?>"
										data-due-day="<?php echo esc_attr( (string) $row['due_day'] ); ?>"
										data-flat="<?php echo esc_attr( (string) $row['flat_amount'] ); ?>"
										data-mode="<?php echo esc_attr( $row['amount_mode'] ); ?>"
										data-notes="<?php echo esc_attr( $row['notes'] ); ?>"
									><?php esc_html_e( 'Regla', 'arriendo-facil' ); ?></button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<?php if ( $unconfigured_count > 0 ) : ?>
		<section class="af-section" aria-labelledby="af-unconfigured-title">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-unconfigured-title"><?php esc_html_e( 'Inmuebles sin configurar', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'Contratos activos cuyo inmueble todavía no tiene ninguna regla de pago de servicios.', 'arriendo-facil' ); ?></p>
				</div>
			</header>
			<table class="wp-list-table widefat fixed striped af-data-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Acción', 'arriendo-facil' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $unconfigured_leases, 0, 25 ) as $missing ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>">
								<strong><?php echo esc_html( $missing->accommodation_title ? $missing->accommodation_title : '#' . (int) $missing->accommodation_id ); ?></strong>
							</td>
							<td data-label="<?php esc_attr_e( 'Acción', 'arriendo-facil' ); ?>">
								<button type="button" class="button button-small af-btn af-btn--primary" data-af-open-schedule="<?php echo esc_attr( (string) $missing->accommodation_id ); ?>">
									<?php esc_html_e( 'Configurar servicios', 'arriendo-facil' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>

	<section class="af-section" id="af-schedule-rules" aria-labelledby="af-schedules-title">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title" id="af-schedules-title"><?php esc_html_e( 'Reglas de vencimiento', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'El día del mes que se paga cada servicio, por inmueble. Se repite automáticamente cada mes.', 'arriendo-facil' ); ?></p>
			</div>
			<div class="af-section__actions">
				<button type="button" class="button af-btn af-btn--primary" data-af-open-schedule>
					<?php echo af_lucide( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Agregar regla', 'arriendo-facil' ); ?>
				</button>
			</div>
		</header>

		<?php if ( empty( $all_schedules ) ) : ?>
			<div class="af-empty">
				<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'sliders-horizontal', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h3 class="af-empty__title"><?php esc_html_e( 'Sin reglas de vencimiento', 'arriendo-facil' ); ?></h3>
				<p class="af-empty__text"><?php esc_html_e( 'Agrega el día de pago de cada servicio por inmueble. A partir de ahí el sistema genera el cargo de cada mes con esa fecha y te avisa cuando se acerca.', 'arriendo-facil' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped af-data-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Servicio', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Vence cada', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Tarifa fija', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Origen del monto', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $all_schedules as $schedule ) : ?>
						<?php
						$rule_title = get_the_title( (int) $schedule->accommodation_id );
						if ( ! $rule_title && (int) $schedule->unit_id ) {
							$rule_unit = Arriendo_Facil_Property_Structure::get_unit( (int) $schedule->unit_id );
							$rule_title = $rule_unit ? $rule_unit->unit_code : '';
						}
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>">
								<strong><?php echo esc_html( $rule_title ? $rule_title : __( 'Inmueble', 'arriendo-facil' ) ); ?></strong>
							</td>
							<td data-label="<?php esc_attr_e( 'Servicio', 'arriendo-facil' ); ?>">
								<?php echo esc_html( $ledger::service_label( $schedule->service ) ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Vence cada', 'arriendo-facil' ); ?>">
								<span class="af-pill af-pill--info">
									<?php echo esc_html( sprintf( /* translators: %d */ __( 'día %d', 'arriendo-facil' ), (int) $schedule->due_day ) ); ?>
								</span>
							</td>
							<td data-label="<?php esc_attr_e( 'Tarifa fija', 'arriendo-facil' ); ?>">
								<?php if ( (float) $schedule->flat_amount > 0 ) : ?>
									$<?php echo esc_html( number_format_i18n( (float) $schedule->flat_amount, 2 ) ); ?>
								<?php else : ?>
									<span class="af-td-meta"><?php esc_html_e( 's/ref', 'arriendo-facil' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Origen del monto', 'arriendo-facil' ); ?>">
								<?php
								$rule_mode = isset( $amount_meta[ $schedule->amount_mode ] ) ? $amount_meta[ $schedule->amount_mode ] : $schedule->amount_mode;
								echo esc_html( $rule_mode );
								?>
							</td>
							<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>">
								<?php if ( (int) $schedule->is_active ) : ?>
									<span class="af-pill af-pill--success"><?php esc_html_e( 'Activa', 'arriendo-facil' ); ?></span>
								<?php else : ?>
									<span class="af-pill af-pill--neutral"><?php esc_html_e( 'Inactiva', 'arriendo-facil' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Acciones', 'arriendo-facil' ); ?>">
								<div class="af-service-actions">
									<button
										type="button"
										class="button button-small af-edit-schedule"
										data-schedule="<?php echo esc_attr( (string) $schedule->id ); ?>"
										data-unit="<?php echo esc_attr( (string) $schedule->unit_id ); ?>"
										data-accommodation="<?php echo esc_attr( (string) $schedule->accommodation_id ); ?>"
										data-service="<?php echo esc_attr( $schedule->service ); ?>"
										data-due-day="<?php echo esc_attr( (string) $schedule->due_day ); ?>"
										data-flat="<?php echo esc_attr( (string) $schedule->flat_amount ); ?>"
										data-mode="<?php echo esc_attr( $schedule->amount_mode ); ?>"
									><?php esc_html_e( 'Editar', 'arriendo-facil' ); ?></button>
									<?php if ( (int) $schedule->is_active ) : ?>
										<button type="button" class="button button-small af-delete-schedule" data-schedule="<?php echo esc_attr( (string) $schedule->id ); ?>">
											<?php esc_html_e( 'Desactivar', 'arriendo-facil' ); ?>
										</button>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="af-section" aria-labelledby="af-consumption-title">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title" id="af-consumption-title"><?php esc_html_e( 'Consumo medido del periodo', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: total amount */
							__( 'Lecturas registradas en %s. El importe de los servicios medidos sale de aquí.', 'arriendo-facil' ),
							$period
						)
					);
					?>
				</p>
			</div>
			<div class="af-section__actions">
				<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-cobros' ) ); ?>"><?php esc_html_e( 'Registrar lectura', 'arriendo-facil' ); ?></a>
			</div>
		</header>

		<?php if ( empty( $reading_rows ) ) : ?>
			<div class="af-empty">
				<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'gauge', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h3 class="af-empty__title"><?php esc_html_e( 'Sin lecturas en el periodo', 'arriendo-facil' ); ?></h3>
				<p class="af-empty__text"><?php esc_html_e( 'Mientras no registres la lectura, los servicios medidos se cobrarán con la tarifa fija configurada, si la tienen.', 'arriendo-facil' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped af-data-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Servicio', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Anterior', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Actual', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Consumo', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Tarifa', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Importe', 'arriendo-facil' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $reading_rows as $reading_row ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>">
								<strong><?php echo esc_html( '' !== (string) $reading_row->unit_code ? (string) $reading_row->unit_code : (string) $reading_row->accommodation_title ); ?></strong>
							</td>
							<td data-label="<?php esc_attr_e( 'Servicio', 'arriendo-facil' ); ?>">
								<?php echo esc_html( $ledger::service_label( $reading_row->service ) ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Anterior', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $reading_row->previous_reading, 3 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Actual', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $reading_row->current_reading, 3 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Consumo', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $reading_row->consumption, 3 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Tarifa', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $reading_row->unit_rate, 4 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Importe', 'arriendo-facil' ); ?>"><strong>$<?php echo esc_html( number_format_i18n( (float) $reading_row->calculated_amount, 2 ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
					<tr class="af-table-total">
						<td colspan="6"><?php esc_html_e( 'Total del periodo', 'arriendo-facil' ); ?></td>
						<td data-label="<?php esc_attr_e( 'Importe', 'arriendo-facil' ); ?>"><strong>$<?php echo esc_html( number_format_i18n( $reading_total, 2 ) ); ?></strong></td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
</div>

<!-- Modal: regla de vencimiento -->
<div class="af-modal" id="af-modal-schedule" role="dialog" aria-modal="true" aria-labelledby="af-modal-schedule-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-schedule-title"><?php esc_html_e( 'Regla de pago del servicio', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle"><?php esc_html_e( 'Se repetirá cada mes con esta fecha de pago.', 'arriendo-facil' ); ?></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-schedule-status"></p>
			<input type="hidden" id="af-schedule-id" value="" />
			<input type="hidden" id="af-schedule-unit" value="0" />
			<div class="af-modal__field">
				<label for="af-schedule-property"><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></label>
				<select id="af-schedule-property" <?php echo empty( $property_options ) ? 'disabled' : ''; ?>>
					<option value=""><?php esc_html_e( 'Selecciona un inmueble', 'arriendo-facil' ); ?></option>
					<?php foreach ( $property_options as $property_option ) : ?>
						<option value="<?php echo esc_attr( (string) $property_option['id'] ); ?>"><?php echo esc_html( $property_option['title'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="af-modal__field">
				<label for="af-schedule-service"><?php esc_html_e( 'Servicio', 'arriendo-facil' ); ?></label>
				<select id="af-schedule-service">
					<?php foreach ( $catalog as $service_key => $service_meta ) : ?>
						<option value="<?php echo esc_attr( $service_key ); ?>" <?php echo $service_meta['metered'] ? '' : 'data-metered="0"'; ?>>
							<?php echo esc_html( $service_meta['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="af-modal__field">
				<label for="af-schedule-due-day"><?php esc_html_e( 'Día del mes en que se paga', 'arriendo-facil' ); ?></label>
				<input type="number" id="af-schedule-due-day" min="1" max="31" value="5" />
				<p class="af-modal__hint"><?php esc_html_e( 'En meses cortos se ajusta al último día del mes.', 'arriendo-facil' ); ?></p>
			</div>
			<div class="af-modal__field">
				<label for="af-schedule-mode"><?php esc_html_e( 'De dónde sale el monto', 'arriendo-facil' ); ?></label>
				<select id="af-schedule-mode">
					<option value="auto"><?php esc_html_e( 'De la lectura, y si no hay, tarifa fija', 'arriendo-facil' ); ?></option>
					<option value="fixed"><?php esc_html_e( 'Siempre tarifa fija', 'arriendo-facil' ); ?></option>
					<option value="metered"><?php esc_html_e( 'Solo de la lectura del medidor', 'arriendo-facil' ); ?></option>
				</select>
			</div>
			<div class="af-modal__field">
				<label for="af-schedule-amount"><?php esc_html_e( 'Tarifa fija mensual (USD)', 'arriendo-facil' ); ?></label>
				<input type="number" id="af-schedule-amount" step="0.01" min="0" value="0" />
				<p class="af-modal__hint"><?php esc_html_e( 'Se usa cuando todavía no hay lectura del mes, o siempre si elegiste tarifa fija.', 'arriendo-facil' ); ?></p>
			</div>
			<div class="af-modal__field">
				<label for="af-schedule-notes"><?php esc_html_e( 'Nota', 'arriendo-facil' ); ?></label>
				<input type="text" id="af-schedule-notes" placeholder="<?php esc_attr_e( 'Ej: la factura llega el día 5, se paga el 8', 'arriendo-facil' ); ?>" />
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-schedule-confirm"><?php esc_html_e( 'Guardar regla', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<!-- Modal: registrar pago -->
<div class="af-modal" id="af-modal-payment" role="dialog" aria-modal="true" aria-labelledby="af-modal-payment-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-payment-title"><?php esc_html_e( 'Registrar pago del servicio', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-payment-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-payment-status"></p>
			<div class="af-modal__field">
				<label for="af-payment-amount"><?php esc_html_e( 'Monto recibido (USD)', 'arriendo-facil' ); ?></label>
				<input type="number" id="af-payment-amount" step="0.01" min="0.01" />
				<p class="af-modal__hint" id="af-payment-outstanding"></p>
			</div>
			<div class="af-modal__field">
				<label for="af-payment-date"><?php esc_html_e( 'Fecha de pago', 'arriendo-facil' ); ?></label>
				<input type="date" id="af-payment-date" value="<?php echo esc_attr( $today ); ?>" />
			</div>
			<div class="af-modal__field">
				<label for="af-payment-method"><?php esc_html_e( 'Método', 'arriendo-facil' ); ?></label>
				<select id="af-payment-method">
					<?php foreach ( $ledger::payment_methods() as $method_key => $method_label ) : ?>
						<option value="<?php echo esc_attr( $method_key ); ?>"><?php echo esc_html( $method_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="af-modal__field">
				<label for="af-payment-reference"><?php esc_html_e( 'Referencia / comprobante', 'arriendo-facil' ); ?></label>
				<input type="text" id="af-payment-reference" placeholder="<?php esc_attr_e( 'Ej: transferencia #01234', 'arriendo-facil' ); ?>" />
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-payment-confirm"><?php esc_html_e( 'Registrar pago', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( $ledger_nonce ); ?>;
	const period = <?php echo wp_json_encode( $period ); ?>;
	const i18n = <?php echo wp_json_encode( array(
		'ruleSaved'      => __( 'Regla guardada.', 'arriendo-facil' ),
		'ruleRemoved'    => __( 'Regla desactivada.', 'arriendo-facil' ),
		'paymentSaved'   => __( 'Pago registrado correctamente.', 'arriendo-facil' ),
		'pickProperty'   => __( 'Selecciona un inmueble.', 'arriendo-facil' ),
		'amountRequired' => __( 'Ingresa un monto mayor a cero.', 'arriendo-facil' ),
		'outstanding'    => __( 'Saldo pendiente:', 'arriendo-facil' ),
		'overpay'        => __( 'puedes superarlo y el excedente quedará como saldo a favor.', 'arriendo-facil' ),
		'confirmRemove'  => __( '¿Desactivar esta regla? Dejará de generar cobros de este servicio.', 'arriendo-facil' ),
		'genericError'   => __( 'No se pudo completar la operacion.', 'arriendo-facil' ),
	) ); ?>;

	function post(payload) {
		const body = new URLSearchParams();
		Object.keys(payload).forEach(function (k) { body.append(k, payload[k]); });
		return fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then(function (r) { return r.json(); });
	}

	function setStatus(el, message, type) {
		el.textContent = message;
		el.className = 'af-modal__status is-' + type;
	}

	function clearStatus(el) {
		el.textContent = '';
		el.className = 'af-modal__status';
	}

	function openModal(modal) {
		modal.classList.add('is-open');
		const focusable = modal.querySelector('input, select, button.button-primary');
		if (focusable) { focusable.focus(); }
	}

	function closeModal(modal) {
		modal.classList.remove('is-open');
	}

	document.querySelectorAll('.af-modal').forEach(function (modal) {
		modal.querySelectorAll('[data-af-modal-close]').forEach(function (btn) {
			btn.addEventListener('click', function () { closeModal(modal); });
		});
	});

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		document.querySelectorAll('.af-modal.is-open').forEach(closeModal);
	});

	// ---- Generar vencimientos ----
	function generate(target) {
		if (target) { target.disabled = true; }
		post({ action: 'af_generate_service_charges', nonce: nonce, period: period }).then(function (json) {
			if (target) { target.disabled = false; }
			if (!json || !json.success) {
				window.alert((json && json.data && json.data.message) || i18n.genericError);
				return;
			}
			window.location.reload();
		}).catch(function () {
			if (target) { target.disabled = false; }
			window.alert(i18n.genericError);
		});
	}

	const generateButton = document.getElementById('af-generate-services');
	if (generateButton) {
		generateButton.addEventListener('click', function () { generate(generateButton); });
	}
	document.querySelectorAll('.af-generate-services').forEach(function (btn) {
		btn.addEventListener('click', function () { generate(btn); });
	});

	// ---- Regla de vencimiento ----
	const scheduleModal = document.getElementById('af-modal-schedule');
	const scheduleStatus = document.getElementById('af-schedule-status');
	const scheduleId = document.getElementById('af-schedule-id');
	const scheduleUnit = document.getElementById('af-schedule-unit');
	const scheduleProperty = document.getElementById('af-schedule-property');
	const scheduleService = document.getElementById('af-schedule-service');
	const scheduleDueDay = document.getElementById('af-schedule-due-day');
	const scheduleMode = document.getElementById('af-schedule-mode');
	const scheduleAmount = document.getElementById('af-schedule-amount');
	const scheduleNotes = document.getElementById('af-schedule-notes');
	const scheduleConfirm = document.getElementById('af-schedule-confirm');
	const defaultService = <?php echo wp_json_encode( array_key_first( $catalog ) ? array_key_first( $catalog ) : 'agua' ); ?>;

	// Accommodation the operator cannot change while the modal is open. Kept
	// separately from scheduleProperty.disabled because a disabled <select> is
	// not submitted and its locked value must still reach the endpoint.
	let lockedAccommodation = '';

	function lockProperty(propertyId) {
		lockedAccommodation = '';
		if (!scheduleProperty) {
			return;
		}
		scheduleProperty.value = propertyId ? String(propertyId) : '';
		scheduleProperty.disabled = !!propertyId;
		lockedAccommodation = propertyId ? String(propertyId) : '';
	}

	function openSchedule(propertyId) {
		scheduleId.value = '';
		scheduleUnit.value = '0';
		scheduleService.value = defaultService;
		scheduleDueDay.value = 5;
		scheduleMode.value = 'auto';
		scheduleAmount.value = '0';
		scheduleNotes.value = '';
		lockProperty(propertyId);
		clearStatus(scheduleStatus);
		openModal(scheduleModal);
	}

	document.querySelectorAll('[data-af-open-schedule]').forEach(function (btn) {
		btn.addEventListener('click', function () { openSchedule(btn.getAttribute('data-af-open-schedule')); });
	});

	document.querySelectorAll('.af-edit-schedule').forEach(function (btn) {
		btn.addEventListener('click', function () {
			const unit = btn.getAttribute('data-unit') || '0';
			const acc = btn.getAttribute('data-accommodation') || '0';

			scheduleId.value = btn.getAttribute('data-schedule') || '';
			scheduleUnit.value = unit;
			scheduleService.value = btn.getAttribute('data-service') || defaultService;
			scheduleDueDay.value = btn.getAttribute('data-due-day') || '5';
			scheduleMode.value = btn.getAttribute('data-mode') || 'auto';
			scheduleAmount.value = btn.getAttribute('data-flat') || '0';
			scheduleNotes.value = btn.getAttribute('data-notes') || '';
			// A unit-scoped rule has the property implicit, so the selector is
			// locked and the unit travels in the hidden field instead.
			lockProperty(unit === '0' ? acc : '');
			clearStatus(scheduleStatus);
			openModal(scheduleModal);
		});
	});

	document.querySelectorAll('.af-delete-schedule').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!window.confirm(i18n.confirmRemove)) { return; }
			btn.disabled = true;
			post({ action: 'af_delete_service_schedule', nonce: nonce, schedule_id: btn.getAttribute('data-schedule') }).then(function (json) {
				btn.disabled = false;
				if (!json || !json.success) {
					window.alert((json && json.data && json.data.message) || i18n.genericError);
					return;
				}
				window.location.reload();
			});
		});
	});

	scheduleConfirm.addEventListener('click', function () {
		const unit = scheduleUnit.value || '0';
		// A locked selector still owns its property, so lockedAccommodation wins
		// over the (unreadable) select value.
		const accommodation = unit !== '0'
			? ''
			: (lockedAccommodation || (scheduleProperty ? scheduleProperty.value : ''));

		if (unit === '0' && !accommodation) {
			setStatus(scheduleStatus, i18n.pickProperty, 'error');
			return;
		}

		scheduleConfirm.disabled = true;
		clearStatus(scheduleStatus);

		post({
			action: 'af_save_service_schedule',
			nonce: nonce,
			unit_id: unit,
			accommodation_id: accommodation,
			service: scheduleService.value,
			due_day: scheduleDueDay.value,
			amount_mode: scheduleMode.value,
			flat_amount: scheduleAmount.value,
			notes: scheduleNotes.value,
			is_active: '1'
		}).then(function (json) {
			scheduleConfirm.disabled = false;
			if (!json || !json.success) {
				setStatus(scheduleStatus, (json && json.data && json.data.message) || i18n.genericError, 'error');
				return;
			}
			setStatus(scheduleStatus, json.data.message || i18n.ruleSaved, 'success');
			setTimeout(function () { window.location.reload(); }, 700);
		});
	});

	// ---- Registrar pago ----
	const payModal = document.getElementById('af-modal-payment');
	const payStatus = document.getElementById('af-payment-status');
	const paySubtitle = document.getElementById('af-payment-subtitle');
	const payAmount = document.getElementById('af-payment-amount');
	const payOutstanding = document.getElementById('af-payment-outstanding');
	const payDate = document.getElementById('af-payment-date');
	const payMethod = document.getElementById('af-payment-method');
	const payReference = document.getElementById('af-payment-reference');
	const payConfirm = document.getElementById('af-payment-confirm');
	let payChargeId = 0;
	let payMax = 0;

	document.querySelectorAll('.af-record-payment').forEach(function (btn) {
		btn.addEventListener('click', function () {
			payChargeId = btn.getAttribute('data-charge');
			payMax = parseFloat(btn.getAttribute('data-outstanding')) || 0;
			paySubtitle.textContent = btn.getAttribute('data-concept') + ' — ' + btn.getAttribute('data-tenant');
			payAmount.value = payMax.toFixed(2);
			payOutstanding.textContent = i18n.outstanding + ' $' + payMax.toFixed(2) + ' — ' + i18n.overpay;
			payReference.value = '';
			clearStatus(payStatus);
			openModal(payModal);
		});
	});

	payConfirm.addEventListener('click', function () {
		const amount = parseFloat(payAmount.value);
		if (!amount || amount <= 0) {
			setStatus(payStatus, i18n.amountRequired, 'error');
			return;
		}
		payConfirm.disabled = true;
		clearStatus(payStatus);
		post({
			action: 'af_record_payment',
			nonce: nonce,
			charge_id: payChargeId,
			amount: amount,
			payment_date: payDate.value,
			method: payMethod.value,
			reference: payReference.value
		}).then(function (json) {
			payConfirm.disabled = false;
			if (!json || !json.success) {
				setStatus(payStatus, (json && json.data && json.data.message) || i18n.genericError, 'error');
				return;
			}
			setStatus(payStatus, json.data.message || i18n.paymentSaved, 'success');
			setTimeout(function () { window.location.reload(); }, 800);
		});
	});
})();
</script>
