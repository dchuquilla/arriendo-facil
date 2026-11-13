<?php
/**
 * Panel: lo esencial de la operación en una sola pantalla.
 *
 * Orden de lectura: cuánto se cobró y cuánto falta, qué requiere acción,
 * qué viene esta semana y cómo evolucionan los ingresos. Todo lo demás vive
 * en su propia sección del menú.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var wpdb $wpdb */
global $wpdb;

$current_user = wp_get_current_user();
$scope_ids    = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
$has_ledger   = class_exists( 'Arriendo_Facil_Billing_Ledger' );
$today        = current_time( 'Y-m-d' );
$period       = current_time( 'Y-m' );
$charges_t    = $wpdb->prefix . 'af_charges';
$leases_t     = $wpdb->prefix . 'af_leases';

// Fragmento SQL ya validado con absint(); null = sin restricción.
$ids_sql      = null === $scope_ids ? null : Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids );
$lease_scope  = null === $ids_sql ? '' : " AND accommodation_id IN ({$ids_sql})";
$charge_scope = null === $ids_sql ? '' : " AND lease_id IN (SELECT id FROM {$leases_t} WHERE accommodation_id IN ({$ids_sql}))";

// ── Inmuebles y ocupación ────────────────────────────────────────────────
$property_total    = null === $scope_ids ? (int) wp_count_posts( 'accommodation' )->publish : count( $scope_ids );
$property_occupied = 0;

if ( $property_total > 0 ) {
	$occupied_scope    = null === $ids_sql ? '' : " AND p.ID IN ({$ids_sql})";
	$property_occupied = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT p.ID)
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_af_status' AND pm.meta_value IN ('occupied', 'rented')
		 WHERE p.post_type = 'accommodation' AND p.post_status IN ('publish', 'private'){$occupied_scope}" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);
}
$occupancy_rate = $property_total > 0 ? (int) min( 100, round( $property_occupied / $property_total * 100 ) ) : 0;

// ── Cobranza del mes ─────────────────────────────────────────────────────
$period_charged = 0.0;
$period_paid    = 0.0;
$overdue_count  = 0;
$overdue_amount = 0.0;

if ( $has_ledger ) {
	$totals = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COALESCE(SUM(amount), 0) AS charged, COALESCE(SUM(amount_paid), 0) AS paid
			 FROM {$charges_t} WHERE period = %s AND status <> 'void'{$charge_scope}", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$period
		)
	);
	$period_charged = $totals ? (float) $totals->charged : 0.0;
	$period_paid    = $totals ? (float) $totals->paid : 0.0;

	$overdue        = Arriendo_Facil_Billing_Ledger::overdue_summary( $scope_ids );
	$overdue_count  = (int) $overdue['count'];
	$overdue_amount = (float) $overdue['amount'];
}

$period_pending  = max( 0.0, round( $period_charged - $period_paid, 2 ) );
$collection_rate = $period_charged > 0 ? (int) min( 100, round( $period_paid / $period_charged * 100 ) ) : 0;

// ── Pendientes que requieren acción ──────────────────────────────────────
$expiring_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$leases_t} WHERE status = 'active' AND deleted_at IS NULL AND end_date BETWEEN %s AND %s{$lease_scope}", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$today,
		gmdate( 'Y-m-d', strtotime( $today . ' +60 days' ) )
	)
);

$draft_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leases_t} WHERE status = 'draft' AND deleted_at IS NULL{$lease_scope}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$docs_pending = 0;
if ( class_exists( 'Arriendo_Facil_Document_Verification' ) ) {
	$docs_scope   = null === $ids_sql ? '' : " AND accommodation_id IN ({$ids_sql})";
	$docs_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_guests WHERE (doc_status IS NULL OR doc_status = 'pendiente'){$docs_scope}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

$maintenance_open   = 0;
$maintenance_urgent = 0;
if ( class_exists( 'Arriendo_Facil_Maintenance' ) ) {
	$maint_scope = null === $ids_sql ? '' : " AND accommodation_id IN ({$ids_sql})";
	$maint_rows  = (array) $wpdb->get_results(
		'SELECT priority, COUNT(*) AS total FROM ' . Arriendo_Facil_Maintenance::table() . "
		 WHERE status IN ('pending', 'in_progress'){$maint_scope} GROUP BY priority" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);
	foreach ( $maint_rows as $maint_row ) {
		$maintenance_open += (int) $maint_row->total;
		if ( 'alta' === $maint_row->priority ) {
			$maintenance_urgent += (int) $maint_row->total;
		}
	}
}

$tasks = array();
if ( $overdue_count > 0 ) {
	$tasks[] = array(
		'icon'  => 'credit-card',
		'count' => $overdue_count,
		'label' => sprintf( /* translators: %s: overdue amount */ __( 'cobros vencidos ($%s)', 'arriendo-facil' ), number_format_i18n( $overdue_amount, 2 ) ),
		'url'   => admin_url( 'admin.php?page=af-buildings' ),
		'tone'  => 'danger',
	);
}
if ( $maintenance_open > 0 ) {
	$tasks[] = array(
		'icon'  => 'wrench',
		'count' => $maintenance_open,
		'label' => $maintenance_urgent > 0
			? _n( 'reparación abierta (hay críticas)', 'reparaciones abiertas (hay críticas)', $maintenance_open, 'arriendo-facil' )
			: _n( 'reparación abierta', 'reparaciones abiertas', $maintenance_open, 'arriendo-facil' ),
		'url'   => admin_url( 'admin.php?page=af-maintenance' ),
		'tone'  => $maintenance_urgent > 0 ? 'danger' : 'warn',
	);
}
if ( $expiring_count > 0 ) {
	$tasks[] = array(
		'icon'  => 'calendar',
		'count' => $expiring_count,
		'label' => _n( 'contrato vence en 60 días', 'contratos vencen en 60 días', $expiring_count, 'arriendo-facil' ),
		'url'   => admin_url( 'admin.php?page=af-leases' ),
		'tone'  => 'warn',
	);
}
if ( $docs_pending > 0 ) {
	$tasks[] = array(
		'icon'  => 'user',
		'count' => $docs_pending,
		'label' => _n( 'inquilino con documentos por verificar', 'inquilinos con documentos por verificar', $docs_pending, 'arriendo-facil' ),
		'url'   => admin_url( 'admin.php?page=af-guests' ),
		'tone'  => '',
	);
}
if ( $draft_count > 0 ) {
	$tasks[] = array(
		'icon'  => 'file-text',
		'count' => $draft_count,
		'label' => _n( 'contrato en borrador', 'contratos en borrador', $draft_count, 'arriendo-facil' ),
		'url'   => admin_url( 'admin.php?page=af-leases' ),
		'tone'  => '',
	);
}

// ── Próximos 7 días: mudanzas, salidas y vencimientos de servicios ───────
$week_end = gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) );
$agenda   = array();
$push     = static function ( $date, $icon, $kind, $tone, $who, $where, $url ) use ( &$agenda, $today, $week_end ) {
	if ( empty( $date ) || $date < $today || $date > $week_end ) {
		return;
	}
	$agenda[] = compact( 'date', 'icon', 'kind', 'tone', 'who', 'where', 'url' );
};

if ( class_exists( 'Arriendo_Facil_Calendar' ) ) {
	foreach ( (array) Arriendo_Facil_Calendar::upcoming_moves( $today, $week_end, $scope_ids, 8 ) as $move ) {
		$push( $move->move_date, 'log-in', __( 'Mudanza', 'arriendo-facil' ), 'info', (string) $move->contact_name, (string) $move->accommodation_title, admin_url( 'admin.php?page=af-calendar' ) );
	}
	foreach ( (array) Arriendo_Facil_Calendar::upcoming_checkouts( $today, $week_end, $scope_ids, 8 ) as $checkout ) {
		$push( $checkout->end_date, 'log-out', __( 'Fin de contrato', 'arriendo-facil' ), 'warn', (string) $checkout->guest_name, (string) $checkout->accommodation_title, admin_url( 'admin.php?page=af-leases' ) );
	}
}

if ( $has_ledger ) {
	foreach ( array_unique( array( $period, gmdate( 'Y-m', strtotime( $week_end ) ) ) ) as $service_period ) {
		foreach ( Arriendo_Facil_Billing_Ledger::get_service_due_rows( $service_period, $scope_ids ) as $service_row ) {
			if ( ! $service_row['is_active'] || $service_row['bill_paid'] || 'paid' === $service_row['status'] || $service_row['expected_amount'] <= 0 ) {
				continue;
			}
			$push(
				$service_row['due_date'],
				'receipt',
				$service_row['service_label'],
				'info',
				'$' . number_format_i18n( (float) $service_row['expected_amount'], 2 ),
				(string) $service_row['accommodation_title'],
				admin_url( 'admin.php?page=af-meter-readings' )
			);
		}
	}
}

usort(
	$agenda,
	static function ( $a, $b ) {
		return strcmp( $a['date'], $b['date'] );
	}
);
$agenda = array_slice( $agenda, 0, 6 );

// ── Contratos por vencer ─────────────────────────────────────────────────
$expiring_leases = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT l.id, l.end_date, p.post_title AS property, CONCAT(g.first_name, ' ', g.last_name) AS guest_name
		 FROM {$leases_t} l
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
		 WHERE l.status = 'active' AND l.deleted_at IS NULL AND l.end_date BETWEEN %s AND %s" . ( null === $ids_sql ? '' : " AND l.accommodation_id IN ({$ids_sql})" ) . '
		 ORDER BY l.end_date ASC LIMIT 4', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$today,
		gmdate( 'Y-m-d', strtotime( $today . ' +60 days' ) )
	)
);

// ── Ingresos de los últimos 6 meses ──────────────────────────────────────
$revenue = array( 'labels' => array(), 'values' => array() );
if ( $has_ledger ) {
	$months = array();
	for ( $i = 5; $i >= 0; $i-- ) {
		$months[] = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01', strtotime( $today ) ) . " -{$i} months" ) );
	}
	$paid_by_month = array();
	$revenue_rows  = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT period, COALESCE(SUM(amount_paid), 0) AS paid FROM {$charges_t}
			 WHERE period BETWEEN %s AND %s AND status <> 'void'{$charge_scope} GROUP BY period", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$months[0],
			end( $months )
		)
	);
	foreach ( $revenue_rows as $revenue_row ) {
		$paid_by_month[ (string) $revenue_row->period ] = (float) $revenue_row->paid;
	}
	foreach ( $months as $month ) {
		$revenue['labels'][] = ucfirst( wp_date( 'M', strtotime( $month . '-01' ) ) );
		$revenue['values'][] = round( isset( $paid_by_month[ $month ] ) ? $paid_by_month[ $month ] : 0.0, 2 );
	}
}
$has_revenue = array_sum( $revenue['values'] ) > 0;

// ── Saludo ───────────────────────────────────────────────────────────────
$hour       = (int) current_time( 'H' );
$greeting   = $hour < 12 ? __( 'Buenos días', 'arriendo-facil' ) : ( $hour < 19 ? __( 'Buenas tardes', 'arriendo-facil' ) : __( 'Buenas noches', 'arriendo-facil' ) );
$first_name = $current_user->first_name ? $current_user->first_name : $current_user->display_name;
$day_names  = array( 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado' );
$mon_names  = array( 1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
$today_str  = ucfirst( $day_names[ (int) wp_date( 'w' ) ] ) . ', ' . wp_date( 'j' ) . ' de ' . $mon_names[ (int) wp_date( 'n' ) ];

$money = static function ( $amount ) {
	return '$' . number_format_i18n( (float) $amount, 2 );
};
?>
<div class="wrap af-shell af-panel">

	<header class="af-panel__head">
		<div>
			<span class="af-panel__eyebrow"><?php echo esc_html( $today_str ); ?></span>
			<h1 class="af-panel__title"><?php echo esc_html( sprintf( /* translators: 1: greeting, 2: first name */ __( '%1$s, %2$s', 'arriendo-facil' ), $greeting, $first_name ) ); ?></h1>
		</div>
		<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=accommodation' ) ); ?>" class="button af-btn af-btn--primary">
			<span class="af-btn__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
			<?php esc_html_e( 'Nuevo inmueble', 'arriendo-facil' ); ?>
		</a>
	</header>

	<?php if ( 0 === $property_total ) : ?>

		<section class="af-pcard af-pstart">
			<h2 class="af-pcard__title"><?php esc_html_e( 'Empecemos por lo básico', 'arriendo-facil' ); ?></h2>
			<p class="af-pcard__sub"><?php esc_html_e( 'Tres pasos y tu panel se llena solo.', 'arriendo-facil' ); ?></p>
			<ol class="af-pstart__steps">
				<li class="af-pstart__step">
					<span class="af-pstart__num">1</span>
					<div><strong><?php esc_html_e( 'Registra un inmueble', 'arriendo-facil' ); ?></strong><small><?php esc_html_e( 'Fotos, dirección y canon.', 'arriendo-facil' ); ?></small></div>
					<a class="button af-btn af-btn--primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=accommodation' ) ); ?>"><?php esc_html_e( 'Registrar', 'arriendo-facil' ); ?></a>
				</li>
				<li class="af-pstart__step">
					<span class="af-pstart__num">2</span>
					<div><strong><?php esc_html_e( 'Añade a tu inquilino', 'arriendo-facil' ); ?></strong><small><?php esc_html_e( 'Nombre, cédula y contacto.', 'arriendo-facil' ); ?></small></div>
					<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-guests' ) ); ?>"><?php esc_html_e( 'Inquilinos', 'arriendo-facil' ); ?></a>
				</li>
				<li class="af-pstart__step">
					<span class="af-pstart__num">3</span>
					<div><strong><?php esc_html_e( 'Crea el contrato', 'arriendo-facil' ); ?></strong><small><?php esc_html_e( 'Desde ahí se generan los cobros.', 'arriendo-facil' ); ?></small></div>
					<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>"><?php esc_html_e( 'Contratos', 'arriendo-facil' ); ?></a>
				</li>
			</ol>
		</section>

	<?php else : ?>

		<section class="af-pk-grid" aria-label="<?php esc_attr_e( 'Resumen del mes', 'arriendo-facil' ); ?>">

			<a class="af-pk af-pk--hero" href="<?php echo esc_url( admin_url( 'admin.php?page=af-buildings' ) ); ?>">
				<span class="af-pk__label"><?php esc_html_e( 'Cobrado este mes', 'arriendo-facil' ); ?></span>
				<span class="af-pk__value" data-pn="<?php echo esc_attr( $period_paid ); ?>" data-pn-money="1"><?php echo esc_html( $money( $period_paid ) ); ?></span>
				<span class="af-pk__meta"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'de %s facturados', 'arriendo-facil' ), $money( $period_charged ) ) ); ?></span>
				<span class="af-pk__bar" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percent */ __( '%d%% cobrado', 'arriendo-facil' ), $collection_rate ) ); ?>"><i data-pbar style="width: <?php echo esc_attr( $collection_rate ); ?>%"></i></span>
				<span class="af-pk__chip"><span data-pn="<?php echo esc_attr( $collection_rate ); ?>"><?php echo esc_html( $collection_rate ); ?></span>%</span>
			</a>

			<a class="af-pk" href="<?php echo esc_url( admin_url( 'admin.php?page=af-buildings' ) ); ?>">
				<span class="af-pk__icon" aria-hidden="true"><?php echo af_lucide( 'clock', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-pk__label"><?php esc_html_e( 'Por cobrar', 'arriendo-facil' ); ?></span>
				<span class="af-pk__value" data-pn="<?php echo esc_attr( $period_pending ); ?>" data-pn-money="1"><?php echo esc_html( $money( $period_pending ) ); ?></span>
				<span class="af-pk__meta"><?php esc_html_e( 'Pendiente del mes en curso', 'arriendo-facil' ); ?></span>
			</a>

			<a class="af-pk <?php echo $overdue_count > 0 ? 'af-pk--danger' : 'af-pk--ok'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=af-buildings' ) ); ?>">
				<span class="af-pk__icon" aria-hidden="true"><?php echo af_lucide( $overdue_count > 0 ? 'triangle-alert' : 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-pk__label"><?php esc_html_e( 'En mora', 'arriendo-facil' ); ?></span>
				<span class="af-pk__value" data-pn="<?php echo esc_attr( $overdue_amount ); ?>" data-pn-money="1"><?php echo esc_html( $money( $overdue_amount ) ); ?></span>
				<span class="af-pk__meta">
					<?php
					echo esc_html(
						$overdue_count > 0
							? sprintf( /* translators: %d: charge count */ _n( '%d cobro vencido', '%d cobros vencidos', $overdue_count, 'arriendo-facil' ), $overdue_count )
							: __( 'Sin cobros vencidos', 'arriendo-facil' )
					);
					?>
				</span>
			</a>

			<a class="af-pk" href="<?php echo esc_url( Arriendo_Facil_Properties_Page::url() ); ?>">
				<span class="af-pk__ring" data-pring="<?php echo esc_attr( $occupancy_rate ); ?>" style="--pct: <?php echo esc_attr( $occupancy_rate ); ?>;" aria-hidden="true"><b><span data-pn="<?php echo esc_attr( $occupancy_rate ); ?>"><?php echo esc_html( $occupancy_rate ); ?></span>%</b></span>
				<span class="af-pk__label"><?php esc_html_e( 'Ocupación', 'arriendo-facil' ); ?></span>
				<span class="af-pk__meta"><?php echo esc_html( sprintf( /* translators: 1: occupied, 2: total */ __( '%1$d de %2$d inmuebles ocupados', 'arriendo-facil' ), $property_occupied, $property_total ) ); ?></span>
			</a>

		</section>

		<div class="af-panel__grid">

			<div class="af-panel__col">

				<section class="af-pcard" aria-labelledby="af-p-revenue">
					<header class="af-pcard__head">
						<div>
							<h2 class="af-pcard__title" id="af-p-revenue"><?php esc_html_e( 'Ingresos cobrados', 'arriendo-facil' ); ?></h2>
							<p class="af-pcard__sub"><?php esc_html_e( 'Últimos 6 meses', 'arriendo-facil' ); ?></p>
						</div>
					</header>
					<?php if ( $has_revenue ) : ?>
						<div class="af-pchart"><canvas id="af-p-revenue-chart" role="img" aria-label="<?php esc_attr_e( 'Ingresos cobrados por mes', 'arriendo-facil' ); ?>"></canvas></div>
					<?php else : ?>
						<p class="af-pempty"><?php esc_html_e( 'Aún no hay cobros registrados. Cuando registres pagos aparecerán aquí.', 'arriendo-facil' ); ?></p>
					<?php endif; ?>
				</section>

				<section class="af-pcard" aria-labelledby="af-p-expiring">
					<header class="af-pcard__head">
						<div>
							<h2 class="af-pcard__title" id="af-p-expiring"><?php esc_html_e( 'Contratos por vencer', 'arriendo-facil' ); ?></h2>
							<p class="af-pcard__sub"><?php esc_html_e( 'Próximos 60 días', 'arriendo-facil' ); ?></p>
						</div>
						<a class="af-pcard__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>"><?php esc_html_e( 'Ver contratos', 'arriendo-facil' ); ?></a>
					</header>
					<?php if ( empty( $expiring_leases ) ) : ?>
						<p class="af-pempty"><?php esc_html_e( 'Ningún contrato vence pronto.', 'arriendo-facil' ); ?></p>
					<?php else : ?>
						<ul class="af-plist">
							<?php foreach ( $expiring_leases as $lease ) : ?>
								<?php
								$days_left  = max( 0, (int) floor( ( strtotime( $lease->end_date ) - strtotime( $today ) ) / DAY_IN_SECONDS ) );
								$lease_tone = $days_left <= 15 ? 'danger' : ( $days_left <= 30 ? 'warn' : '' );
								$guest_name = trim( (string) $lease->guest_name );
								?>
								<li class="af-plist__item">
									<a class="af-plist__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>">
										<span class="af-plist__main">
											<strong><?php echo esc_html( $guest_name ? $guest_name : __( 'Inquilino', 'arriendo-facil' ) ); ?></strong>
											<small><?php echo esc_html( $lease->property ? $lease->property : '—' ); ?></small>
										</span>
										<span class="af-plist__pill <?php echo esc_attr( $lease_tone ? 'is-' . $lease_tone : '' ); ?>"><?php echo esc_html( sprintf( /* translators: %d: days */ _n( '%d día', '%d días', $days_left, 'arriendo-facil' ), $days_left ) ); ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>

			</div>

			<div class="af-panel__col">

				<section class="af-pcard" aria-labelledby="af-p-attention">
					<header class="af-pcard__head">
						<h2 class="af-pcard__title" id="af-p-attention"><?php esc_html_e( 'Requiere tu atención', 'arriendo-facil' ); ?></h2>
						<?php if ( ! empty( $tasks ) ) : ?>
							<span class="af-pcard__count"><?php echo esc_html( number_format_i18n( count( $tasks ) ) ); ?></span>
						<?php endif; ?>
					</header>
					<?php if ( empty( $tasks ) ) : ?>
						<p class="af-pempty af-pempty--ok"><?php echo af_lucide( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?> <?php esc_html_e( 'Todo al día. No hay pendientes.', 'arriendo-facil' ); ?></p>
					<?php else : ?>
						<ul class="af-plist">
							<?php foreach ( $tasks as $task ) : ?>
								<li class="af-plist__item">
									<a class="af-plist__link <?php echo esc_attr( $task['tone'] ? 'is-' . $task['tone'] : '' ); ?>" href="<?php echo esc_url( $task['url'] ); ?>">
										<span class="af-plist__icon" aria-hidden="true"><?php echo af_lucide( $task['icon'], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
										<span class="af-plist__main"><strong><?php echo esc_html( number_format_i18n( $task['count'] ) ); ?></strong> <?php echo esc_html( $task['label'] ); ?></span>
										<span class="af-plist__arrow" aria-hidden="true">&rarr;</span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>

				<section class="af-pcard" aria-labelledby="af-p-agenda">
					<header class="af-pcard__head">
						<div>
							<h2 class="af-pcard__title" id="af-p-agenda"><?php esc_html_e( 'Esta semana', 'arriendo-facil' ); ?></h2>
							<p class="af-pcard__sub"><?php esc_html_e( 'Mudanzas, salidas y pagos de servicios', 'arriendo-facil' ); ?></p>
						</div>
						<a class="af-pcard__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-calendar' ) ); ?>"><?php esc_html_e( 'Calendario', 'arriendo-facil' ); ?></a>
					</header>
					<?php if ( empty( $agenda ) ) : ?>
						<p class="af-pempty"><?php esc_html_e( 'Sin eventos en los próximos 7 días.', 'arriendo-facil' ); ?></p>
					<?php else : ?>
						<ul class="af-plist">
							<?php foreach ( $agenda as $event ) : ?>
								<li class="af-plist__item">
									<a class="af-plist__link" href="<?php echo esc_url( $event['url'] ); ?>">
										<span class="af-pdate">
											<b><?php echo esc_html( wp_date( 'j', strtotime( $event['date'] ) ) ); ?></b>
											<small><?php echo esc_html( ucfirst( wp_date( 'M', strtotime( $event['date'] ) ) ) ); ?></small>
										</span>
										<span class="af-plist__main">
											<strong><?php echo esc_html( $event['kind'] ); ?></strong>
											<small><?php echo esc_html( trim( $event['who'] . ( $event['where'] ? ' · ' . $event['where'] : '' ) ) ); ?></small>
										</span>
										<span class="af-plist__icon <?php echo esc_attr( 'is-' . $event['tone'] ); ?>" aria-hidden="true"><?php echo af_lucide( $event['icon'], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>

			</div>
		</div>

		<?php if ( $has_revenue ) : ?>
			<script type="application/json" id="af-panel-chart"><?php echo wp_json_encode( $revenue ); ?></script>
		<?php endif; ?>

	<?php endif; ?>
</div>
