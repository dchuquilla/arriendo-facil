<?php
/**
 * Pagos de servicios: alert board of when each utility is due.
 *
 * One card per property with one tile per service. Each tile is both the rule
 * (day of the month the service is paid) and this month's alert, so the
 * operator configures and follows up in the same place.
 *
 * Rules live in af_service_schedules and are materialised as af_charges by
 * Arriendo_Facil_Billing_Ledger::generate_service_charges(), so payments,
 * collections and the overdue cron keep working unchanged.
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

$year           = (int) substr( $period, 0, 4 );
$month          = (int) substr( $period, 5, 2 );
$period_ts      = gmmktime( 0, 0, 0, $month, 1, $year );
$current_period = current_time( 'Y-m' );
$prev_period    = gmdate( 'Y-m', gmmktime( 0, 0, 0, $month - 1, 1, $year ) );
$next_period    = gmdate( 'Y-m', gmmktime( 0, 0, 0, $month + 1, 1, $year ) );
$days_in_month  = (int) gmdate( 't', $period_ts );
$base_url       = admin_url( 'admin.php?page=af-meter-readings' );
$soon_days      = (int) $ledger::SERVICE_DUE_SOON_DAYS;

$scope_ids     = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
$rows          = $ledger::get_service_due_rows( $period, $scope_ids );
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
 * Alert buckets, derived from the due date against today so a service is
 * flagged even before its charge has been generated.
 */
$bucket_meta = array(
	'overdue'  => array(
		'label' => __( 'Vencidos', 'arriendo-facil' ),
		'tag'   => __( 'Vencido', 'arriendo-facil' ),
		'pill'  => 'danger',
		'hint'  => __( '%s sin pagar', 'arriendo-facil' ),
	),
	'due_soon' => array(
		/* translators: %d: number of days */
		'label' => sprintf( __( 'Próximos %d días', 'arriendo-facil' ), $soon_days ),
		'tag'   => __( 'Por vencer', 'arriendo-facil' ),
		'pill'  => 'warning',
		'hint'  => __( '%s por vencer', 'arriendo-facil' ),
	),
	'upcoming' => array(
		'label' => __( 'Más adelante', 'arriendo-facil' ),
		'tag'   => __( 'Programado', 'arriendo-facil' ),
		'pill'  => 'info',
		'hint'  => __( '%s programados', 'arriendo-facil' ),
	),
	'paid'     => array(
		'label' => __( 'Pagados', 'arriendo-facil' ),
		'tag'   => __( 'Pagado', 'arriendo-facil' ),
		'pill'  => 'success',
		'hint'  => __( '%s recibidos', 'arriendo-facil' ),
	),
);
$bucket_rank = array(
	'overdue'  => 0,
	'due_soon' => 1,
	'upcoming' => 2,
	'paid'     => 3,
);

$totals = array();
foreach ( array_keys( $bucket_meta ) as $bucket_key ) {
	$totals[ $bucket_key ] = array(
		'count'  => 0,
		'amount' => 0.0,
	);
}

$groups         = array();
$paused         = array();
$timeline       = array();
$configured_map = array();
$active_count   = 0;
$today_ts       = strtotime( $today );

foreach ( $rows as $row ) {
	if ( ! $row['is_active'] ) {
		$paused[] = $row;
		continue;
	}

	$days = $row['due_date'] ? (int) round( ( strtotime( $row['due_date'] ) - $today_ts ) / DAY_IN_SECONDS ) : 0;

	if ( 'paid' === $row['status'] || $row['bill_paid'] ) {
		$bucket = 'paid';
	} elseif ( $days < 0 ) {
		$bucket = 'overdue';
	} elseif ( $days <= $soon_days ) {
		$bucket = 'due_soon';
	} else {
		$bucket = 'upcoming';
	}

	$row['bucket']      = $bucket;
	$row['days']        = $days;
	$row['open_amount'] = $row['has_charge'] ? $row['outstanding'] : $row['expected_amount'];

	++$totals[ $bucket ]['count'];
	$totals[ $bucket ]['amount'] += 'paid' === $bucket ? ( $row['bill_amount'] > 0 ? $row['bill_amount'] : $row['amount_paid'] ) : $row['open_amount'];
	++$active_count;

	$key = $row['unit_id'] ? 'unit:' . $row['unit_id'] : 'acc:' . $row['accommodation_id'];
	if ( ! isset( $groups[ $key ] ) ) {
		$has_unit       = '' !== $row['unit_code'];
		$groups[ $key ] = array(
			'title'            => $has_unit ? $row['unit_code'] : ( $row['accommodation_title'] ? $row['accommodation_title'] : __( 'Inmueble', 'arriendo-facil' ) ),
			'subtitle'         => $has_unit ? $row['accommodation_title'] : '',
			'guest'            => $row['guest_name'],
			'accommodation_id' => $row['accommodation_id'],
			'unit_id'          => $row['unit_id'],
			'rank'             => 9,
			'rows'             => array(),
			'services'         => array(),
		);
	}

	$groups[ $key ]['rows'][]                     = $row;
	$groups[ $key ]['services'][ $row['service'] ] = true;
	$groups[ $key ]['rank']                       = min( $groups[ $key ]['rank'], $bucket_rank[ $bucket ] );

	if ( ! $row['unit_id'] ) {
		$configured_map[ $row['accommodation_id'] ][] = $row['service'];
	}

	if ( $row['due_date'] && substr( $row['due_date'], 0, 7 ) === $period ) {
		$due_day_num = (int) substr( $row['due_date'], 8, 2 );
		if ( ! isset( $timeline[ $due_day_num ] ) ) {
			$timeline[ $due_day_num ] = array(
				'count'  => 0,
				'bucket' => $bucket,
			);
		}
		++$timeline[ $due_day_num ]['count'];
		if ( $bucket_rank[ $bucket ] < $bucket_rank[ $timeline[ $due_day_num ]['bucket'] ] ) {
			$timeline[ $due_day_num ]['bucket'] = $bucket;
		}
	}
}

// Active leases with no rule at all: the gap that makes a service get forgotten.
$configured_scopes = array();
foreach ( $all_schedules as $schedule ) {
	$key = $schedule->accommodation_id ? 'acc:' . (int) $schedule->accommodation_id : 'unit:' . (int) $schedule->unit_id;
	$configured_scopes[ $key ] = true;
}

$unconfigured_leases = array();

// null = full visibility (administrator); an empty array = the operator manages
// nothing, so the query must not run unfiltered.
$lease_scope_ids = is_array( $scope_ids )
	? array_values( array_unique( array_filter( array_map( 'absint', $scope_ids ) ) ) )
	: null;

if ( null === $lease_scope_ids || $lease_scope_ids ) {
	$lease_scope_sql = $lease_scope_ids ? ' AND l.accommodation_id IN (' . implode( ',', $lease_scope_ids ) . ')' : '';

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

	foreach ( $leases as $lease_row ) {
		$acc_id = (int) $lease_row->accommodation_id;
		if ( isset( $configured_scopes[ 'acc:' . $acc_id ] ) || isset( $unit_scoped_covered[ $acc_id ] ) ) {
			continue;
		}
		$unconfigured_leases[] = $lease_row;
	}
}

$unconfigured_count = count( $unconfigured_leases );

foreach ( $unconfigured_leases as $lease_row ) {
	$key = 'acc:' . (int) $lease_row->accommodation_id;
	if ( isset( $groups[ $key ] ) ) {
		continue;
	}
	$groups[ $key ] = array(
		'title'            => $lease_row->accommodation_title ? $lease_row->accommodation_title : '#' . (int) $lease_row->accommodation_id,
		'subtitle'         => '',
		'guest'            => '',
		'accommodation_id' => (int) $lease_row->accommodation_id,
		'unit_id'          => 0,
		'rank'             => 5,
		'rows'             => array(),
		'services'         => array(),
	);
}

// Most urgent property first, then alphabetical.
uasort(
	$groups,
	static function ( $a, $b ) {
		if ( $a['rank'] !== $b['rank'] ) {
			return $a['rank'] <=> $b['rank'];
		}
		return strcasecmp( $a['title'], $b['title'] );
	}
);

$ledger_nonce = wp_create_nonce( 'af_ledger_nonce' );
?>

<div class="wrap af-shell af-service-payments">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Alertas de cobro', 'arriendo-facil' ),
			'title'    => __( 'Pagos de servicios', 'arriendo-facil' ),
			'subtitle' => __( 'Indica una sola vez qué día se paga cada servicio (agua, luz, gas, internet…) y aquí verás, mes a mes, qué vence, cuándo y a quién avisar.', 'arriendo-facil' ),
			'actions'  => array(
				'<button type="button" class="button af-btn af-btn--primary" data-af-open-schedule>' . af_lucide( 'plus', 16 ) . esc_html__( 'Agregar servicio', 'arriendo-facil' ) . '</button>',
			),
		)
	);
	?>

	<?php if ( $unconfigured_count > 0 ) : ?>
		<div class="af-service-callout af-service-callout--warning">
			<span class="af-service-callout__icon" aria-hidden="true"><?php echo af_lucide( 'bell', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<div class="af-service-callout__body">
				<strong><?php echo esc_html( sprintf( /* translators: %d */ _n( '%d inmueble con contrato activo no tiene servicios configurados', '%d inmuebles con contrato activo no tienen servicios configurados', $unconfigured_count, 'arriendo-facil' ), $unconfigured_count ) ); ?></strong>
				<p><?php esc_html_e( 'Sin fecha de pago no hay alerta. Los encontrarás abajo marcados como “Por configurar”.', 'arriendo-facil' ); ?></p>
			</div>
			<button type="button" class="button af-btn af-btn--primary" data-af-open-schedule="<?php echo esc_attr( (string) $unconfigured_leases[0]->accommodation_id ); ?>">
				<?php esc_html_e( 'Configurar ahora', 'arriendo-facil' ); ?>
			</button>
		</div>
	<?php endif; ?>

	<div class="af-sp-bar">
		<nav class="af-sp-month" aria-label="<?php esc_attr_e( 'Cambiar de mes', 'arriendo-facil' ); ?>">
			<a class="af-sp-month__btn" href="<?php echo esc_url( add_query_arg( 'period', $prev_period, $base_url ) ); ?>" aria-label="<?php esc_attr_e( 'Mes anterior', 'arriendo-facil' ); ?>">
				<?php echo af_lucide( 'chevron-left', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
			<div class="af-sp-month__label">
				<span><?php echo $period === $current_period ? esc_html__( 'Mes actual', 'arriendo-facil' ) : esc_html__( 'Viendo', 'arriendo-facil' ); ?></span>
				<strong><?php echo esc_html( ucfirst( date_i18n( 'F Y', $period_ts ) ) ); ?></strong>
			</div>
			<a class="af-sp-month__btn" href="<?php echo esc_url( add_query_arg( 'period', $next_period, $base_url ) ); ?>" aria-label="<?php esc_attr_e( 'Mes siguiente', 'arriendo-facil' ); ?>">
				<?php echo af_lucide( 'chevron-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
			<?php if ( $period !== $current_period ) : ?>
				<a class="af-sp-month__today" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Volver a hoy', 'arriendo-facil' ); ?></a>
			<?php endif; ?>
		</nav>
		<button type="button" class="button af-btn af-btn--ghost" id="af-generate-services" title="<?php esc_attr_e( 'Crea el cobro de cada servicio con monto en la cuenta del inquilino, para poder registrar su pago.', 'arriendo-facil' ); ?>">
			<?php echo af_lucide( 'refresh-cw', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php esc_html_e( 'Generar cobros del mes', 'arriendo-facil' ); ?>
		</button>
	</div>

	<div class="af-sp-tabs" role="group" aria-label="<?php esc_attr_e( 'Filtrar por estado', 'arriendo-facil' ); ?>">
		<button type="button" class="af-sp-tab af-sp-tab--all is-active" data-bucket="all" aria-pressed="true">
			<span class="af-sp-tab__label"><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></span>
			<span class="af-sp-tab__count"><?php echo esc_html( number_format_i18n( $active_count ) ); ?></span>
			<span class="af-sp-tab__hint"><?php esc_html_e( 'servicios este mes', 'arriendo-facil' ); ?></span>
		</button>
		<?php foreach ( $bucket_meta as $bucket_key => $bucket_info ) : ?>
			<button type="button" class="af-sp-tab af-sp-tab--<?php echo esc_attr( $bucket_key ); ?>" data-bucket="<?php echo esc_attr( $bucket_key ); ?>" aria-pressed="false">
				<span class="af-sp-tab__label"><?php echo esc_html( $bucket_info['label'] ); ?></span>
				<span class="af-sp-tab__count"><?php echo esc_html( number_format_i18n( $totals[ $bucket_key ]['count'] ) ); ?></span>
				<span class="af-sp-tab__hint">
					<?php
					echo $totals[ $bucket_key ]['amount'] > 0
						? esc_html( sprintf( $bucket_info['hint'], '$' . number_format_i18n( $totals[ $bucket_key ]['amount'], 2 ) ) )
						: '—';
					?>
				</span>
			</button>
		<?php endforeach; ?>
	</div>

	<section class="af-sp-calendar" aria-labelledby="af-sp-calendar-title">
		<header class="af-sp-calendar__head">
			<h2 id="af-sp-calendar-title"><?php esc_html_e( 'Calendario de vencimientos', 'arriendo-facil' ); ?></h2>
			<div class="af-sp-legend" aria-hidden="true">
				<?php foreach ( $bucket_meta as $bucket_key => $bucket_info ) : ?>
					<span class="af-sp-legend__item is-<?php echo esc_attr( $bucket_key ); ?>"><?php echo esc_html( $bucket_info['tag'] ); ?></span>
				<?php endforeach; ?>
			</div>
			<button type="button" class="af-sp-link" id="af-sp-clear-day" hidden><?php esc_html_e( 'Ver todo el mes', 'arriendo-facil' ); ?></button>
		</header>
		<div class="af-sp-days">
			<?php for ( $d = 1; $d <= $days_in_month; $d++ ) : ?>
				<?php
				$day_ts   = gmmktime( 0, 0, 0, $month, $d, $year );
				$day_info = isset( $timeline[ $d ] ) ? $timeline[ $d ] : null;
				$classes  = 'af-sp-day';
				if ( $day_info ) {
					$classes .= ' has-due is-' . $day_info['bucket'];
				}
				if ( $period === $current_period && (int) current_time( 'j' ) === $d ) {
					$classes .= ' is-today';
				}
				$day_label = date_i18n( 'l j', $day_ts );
				if ( $day_info ) {
					/* translators: 1: day label, 2: count */
					$day_label = sprintf( _n( '%1$s: %2$d servicio vence', '%1$s: %2$d servicios vencen', $day_info['count'], 'arriendo-facil' ), $day_label, $day_info['count'] );
				}
				?>
				<button type="button" class="<?php echo esc_attr( $classes ); ?>" data-day="<?php echo esc_attr( (string) $d ); ?>" aria-pressed="false" aria-label="<?php echo esc_attr( $day_label ); ?>" <?php disabled( null === $day_info ); ?>>
					<span class="af-sp-day__dow"><?php echo esc_html( mb_substr( date_i18n( 'D', $day_ts ), 0, 2 ) ); ?></span>
					<span class="af-sp-day__num"><?php echo esc_html( (string) $d ); ?></span>
					<span class="af-sp-day__dot"><?php echo $day_info && $day_info['count'] > 1 ? esc_html( (string) $day_info['count'] ) : ''; ?></span>
				</button>
			<?php endfor; ?>
		</div>
	</section>

	<?php if ( empty( $groups ) ) : ?>
		<div class="af-sp-onboarding">
			<div class="af-sp-onboarding__icons" aria-hidden="true">
				<?php foreach ( $catalog as $service_key => $service_meta ) : ?>
					<span class="af-sp-icon af-sp-icon--<?php echo esc_attr( $service_key ); ?>"><?php echo af_lucide( $service_meta['icon'], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php endforeach; ?>
			</div>
			<h2><?php esc_html_e( 'Aún no tienes servicios con fecha de pago', 'arriendo-facil' ); ?></h2>
			<p><?php esc_html_e( 'Agrega el día en que se paga el agua, la luz, el gas o el internet de cada inmueble. Se repetirá solo cada mes y te avisaremos cuando se acerque.', 'arriendo-facil' ); ?></p>
			<button type="button" class="button af-btn af-btn--primary" data-af-open-schedule>
				<?php echo af_lucide( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Agregar primer servicio', 'arriendo-facil' ); ?>
			</button>
		</div>
	<?php else : ?>
		<div class="af-sp-toolbar">
			<label class="af-sp-search">
				<span class="screen-reader-text"><?php esc_html_e( 'Buscar', 'arriendo-facil' ); ?></span>
				<?php echo af_lucide( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="search" id="af-sp-search" placeholder="<?php esc_attr_e( 'Buscar inmueble o inquilino…', 'arriendo-facil' ); ?>" autocomplete="off" />
			</label>
			<div class="af-sp-chips" role="group" aria-label="<?php esc_attr_e( 'Filtrar por servicio', 'arriendo-facil' ); ?>">
				<button type="button" class="af-sp-chip is-active" data-service="" aria-pressed="true"><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></button>
				<?php foreach ( $catalog as $service_key => $service_meta ) : ?>
					<button type="button" class="af-sp-chip af-sp-chip--<?php echo esc_attr( $service_key ); ?>" data-service="<?php echo esc_attr( $service_key ); ?>" aria-pressed="false">
						<?php echo af_lucide( $service_meta['icon'], 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo esc_html( $service_meta['label'] ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="af-sp-board" id="af-sp-board">
			<?php foreach ( $groups as $group ) : ?>
				<?php
				$is_unconfigured = empty( $group['rows'] );
				$missing         = array_diff_key( $catalog, $group['services'] );
				$open_attrs      = $group['unit_id']
					? 'data-af-open-schedule="" data-unit="' . esc_attr( (string) $group['unit_id'] ) . '" data-scope-title="' . esc_attr( $group['title'] ) . '"'
					: 'data-af-open-schedule="' . esc_attr( (string) $group['accommodation_id'] ) . '"';
				?>
				<section class="af-sp-property<?php echo $is_unconfigured ? ' is-unconfigured' : ''; ?>" data-search="<?php echo esc_attr( trim( $group['title'] . ' ' . $group['subtitle'] . ' ' . $group['guest'] ) ); ?>">
					<header class="af-sp-property__head">
						<span class="af-sp-property__avatar" aria-hidden="true"><?php echo af_lucide( 'home', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div class="af-sp-property__title">
							<h3><?php echo esc_html( $group['title'] ); ?></h3>
							<p>
								<?php if ( $group['subtitle'] ) : ?>
									<?php echo esc_html( $group['subtitle'] ); ?> ·
								<?php endif; ?>
								<?php if ( $group['guest'] ) : ?>
									<?php echo af_lucide( 'user', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php echo esc_html( $group['guest'] ); ?>
								<?php elseif ( $is_unconfigured ) : ?>
									<span class="af-pill af-pill--warning"><?php esc_html_e( 'Por configurar', 'arriendo-facil' ); ?></span>
								<?php else : ?>
									<?php esc_html_e( 'Sin inquilino activo', 'arriendo-facil' ); ?>
								<?php endif; ?>
							</p>
						</div>
						<?php if ( ! $is_unconfigured && $missing ) : ?>
							<button type="button" class="button af-btn af-btn--ghost af-sp-property__add" <?php echo $open_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<?php echo af_lucide( 'plus', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php esc_html_e( 'Servicio', 'arriendo-facil' ); ?>
							</button>
						<?php endif; ?>
					</header>

					<?php if ( $is_unconfigured ) : ?>
						<div class="af-sp-property__empty">
							<p><?php esc_html_e( '¿Qué servicios se pagan en este inmueble? Elige uno para indicar su día de pago:', 'arriendo-facil' ); ?></p>
							<div class="af-sp-quick">
								<?php foreach ( $catalog as $service_key => $service_meta ) : ?>
									<button type="button" class="af-sp-quick__btn af-sp-chip--<?php echo esc_attr( $service_key ); ?>" <?php echo $open_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-service="<?php echo esc_attr( $service_key ); ?>">
										<?php echo af_lucide( $service_meta['icon'], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php echo esc_html( $service_meta['label'] ); ?>
									</button>
								<?php endforeach; ?>
							</div>
						</div>
					<?php else : ?>
						<div class="af-sp-tiles">
							<?php foreach ( $group['rows'] as $row ) : ?>
								<?php
								$icon_key = isset( $catalog[ $row['service'] ]['icon'] ) ? $catalog[ $row['service'] ]['icon'] : 'circle-alert';
								$days     = $row['days'];
								$meta     = $bucket_meta[ $row['bucket'] ];
								$amount   = $row['has_charge'] ? $row['charge_amount'] : $row['expected_amount'];
								$due_ts   = $row['due_date'] ? strtotime( $row['due_date'] . ' UTC' ) : 0;
								$progress = ( $row['has_charge'] && $row['charge_amount'] > 0 ) ? (int) min( 100, round( $row['amount_paid'] / $row['charge_amount'] * 100 ) ) : 0;

								if ( 'paid' === $row['bucket'] ) {
									$countdown = $row['bill_paid_on']
										/* translators: %s: date */
										? sprintf( __( 'Pagado el %s', 'arriendo-facil' ), date_i18n( 'j M', strtotime( $row['bill_paid_on'] . ' UTC' ) ) )
										: __( 'Pagado', 'arriendo-facil' );
								} elseif ( $days < 0 ) {
									/* translators: %d: days */
									$countdown = sprintf( _n( 'Venció hace %d día', 'Venció hace %d días', abs( $days ), 'arriendo-facil' ), abs( $days ) );
								} elseif ( 0 === $days ) {
									$countdown = __( 'Vence hoy', 'arriendo-facil' );
								} elseif ( 1 === $days ) {
									$countdown = __( 'Vence mañana', 'arriendo-facil' );
								} else {
									/* translators: %d: days */
									$countdown = sprintf( _n( 'Vence en %d día', 'Vence en %d días', $days, 'arriendo-facil' ), $days );
								}

								$aviso_url = admin_url(
									'admin.php?page=af-avisos&af_aviso_lease=' . (int) $row['lease_id'] . '&af_aviso_subject=' . rawurlencode(
										sprintf(
											/* translators: 1: service name, 2: date */
											__( 'Recordatorio: %1$s se paga el %2$s', 'arriendo-facil' ),
											$row['service_label'],
											$due_ts ? gmdate( 'd/m/Y', $due_ts ) : ''
										)
									) . '&af_aviso_type=cobro'
								);
								?>
								<article class="af-sp-tile is-<?php echo esc_attr( $row['bucket'] ); ?>" data-bucket="<?php echo esc_attr( $row['bucket'] ); ?>" data-service="<?php echo esc_attr( $row['service'] ); ?>" data-day="<?php echo esc_attr( $row['due_date'] && substr( $row['due_date'], 0, 7 ) === $period ? (string) (int) substr( $row['due_date'], 8, 2 ) : '' ); ?>">
									<div class="af-sp-tile__top">
										<span class="af-sp-icon af-sp-icon--<?php echo esc_attr( $row['service'] ); ?>" aria-hidden="true"><?php echo af_lucide( $icon_key, 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
										<div class="af-sp-tile__name">
											<strong><?php echo esc_html( $row['service_label'] ); ?></strong>
											<span><?php echo esc_html( sprintf( /* translators: %d */ __( 'Día %d de cada mes', 'arriendo-facil' ), (int) $row['due_day'] ) ); ?></span>
										</div>
										<span class="af-pill af-pill--<?php echo esc_attr( 'partial' === $row['status'] ? 'warning' : $meta['pill'] ); ?>">
											<?php echo 'partial' === $row['status'] ? esc_html__( 'Pago parcial', 'arriendo-facil' ) : esc_html( $meta['tag'] ); ?>
										</span>
									</div>

									<div class="af-sp-tile__due">
										<span class="af-sp-tile__countdown"><?php echo esc_html( $countdown ); ?></span>
										<?php if ( $due_ts ) : ?>
											<span class="af-sp-tile__date"><?php echo af_lucide( 'calendar', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( date_i18n( 'D j M', $due_ts ) ); ?></span>
										<?php endif; ?>
									</div>

									<div class="af-sp-tile__amount">
										<?php if ( $amount > 0 ) : ?>
											<strong>$<?php echo esc_html( number_format_i18n( $amount, 2 ) ); ?></strong>
											<span><?php echo $row['has_charge'] ? esc_html__( 'cobro generado', 'arriendo-facil' ) : esc_html__( 'monto estimado', 'arriendo-facil' ); ?></span>
										<?php else : ?>
											<span><?php esc_html_e( 'Sin monto definido · solo recordatorio', 'arriendo-facil' ); ?></span>
										<?php endif; ?>
									</div>

									<?php if ( $progress > 0 && $progress < 100 ) : ?>
										<div class="af-sp-progress" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $progress ); ?>" aria-valuemin="0" aria-valuemax="100">
											<span style="width: <?php echo esc_attr( (string) $progress ); ?>%;"></span>
										</div>
										<p class="af-sp-tile__meta"><?php echo esc_html( sprintf( /* translators: 1: paid, 2: total */ __( 'Pagado $%1$s de $%2$s', 'arriendo-facil' ), number_format_i18n( $row['amount_paid'], 2 ), number_format_i18n( $row['charge_amount'], 2 ) ) ); ?></p>
									<?php endif; ?>

									<?php if ( '' !== $row['notes'] ) : ?>
										<p class="af-sp-tile__note"><?php echo esc_html( $row['notes'] ); ?></p>
									<?php endif; ?>

									<?php if ( $row['bill_paid'] && ( $row['bill_amount'] > 0 || '' !== $row['bill_reference'] ) ) : ?>
										<p class="af-sp-tile__meta">
											<?php
											$bill_bits = array();
											if ( $row['bill_amount'] > 0 ) {
												$bill_bits[] = '$' . number_format_i18n( $row['bill_amount'], 2 );
											}
											if ( '' !== $row['bill_reference'] ) {
												$bill_bits[] = $row['bill_reference'];
											}
											echo esc_html( implode( ' · ', $bill_bits ) );
											?>
										</p>
									<?php endif; ?>

									<div class="af-sp-tile__actions">
										<?php if ( 'paid' === $row['bucket'] ) : ?>
											<span class="af-service-actions__done"><?php echo af_lucide( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Pagado', 'arriendo-facil' ); ?></span>
											<?php if ( $row['bill_paid'] ) : ?>
												<button type="button" class="af-sp-link af-sp-unmark" data-schedule="<?php echo esc_attr( (string) $row['schedule_id'] ); ?>"><?php esc_html_e( 'Deshacer', 'arriendo-facil' ); ?></button>
											<?php endif; ?>
										<?php else : ?>
											<button
												type="button"
												class="button button-small button-primary af-sp-mark-paid"
												data-schedule="<?php echo esc_attr( (string) $row['schedule_id'] ); ?>"
												data-amount="<?php echo esc_attr( (string) ( $row['open_amount'] > 0 ? $row['open_amount'] : $row['expected_amount'] ) ); ?>"
												data-concept="<?php echo esc_attr( $row['service_label'] . ' · ' . $group['title'] ); ?>"
											><?php echo af_lucide( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Marcar pagado', 'arriendo-facil' ); ?></button>
											<?php if ( $row['has_charge'] && $row['outstanding'] > 0 ) : ?>
												<button
													type="button"
													class="button button-small af-record-payment"
													title="<?php esc_attr_e( 'Registrar lo que pagó el inquilino por este cobro', 'arriendo-facil' ); ?>"
													data-charge="<?php echo esc_attr( (string) $row['charge_id'] ); ?>"
													data-outstanding="<?php echo esc_attr( (string) $row['outstanding'] ); ?>"
													data-concept="<?php echo esc_attr( $row['service_label'] . ' · ' . $period ); ?>"
													data-tenant="<?php echo esc_attr( $row['guest_name'] ? $row['guest_name'] : $group['title'] ); ?>"
												><?php esc_html_e( 'Pago inquilino', 'arriendo-facil' ); ?></button>
											<?php endif; ?>
										<?php endif; ?>

										<span class="af-sp-tile__tools">
											<?php if ( $row['needs_charge'] && $row['lease_id'] && 'paid' !== $row['bucket'] ) : ?>
												<button type="button" class="af-sp-icon-btn af-generate-services" title="<?php esc_attr_e( 'Generar cobro al inquilino', 'arriendo-facil' ); ?>" aria-label="<?php esc_attr_e( 'Generar cobro al inquilino', 'arriendo-facil' ); ?>">
													<?php echo af_lucide( 'receipt', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</button>
											<?php endif; ?>
											<?php if ( $row['lease_id'] && 'paid' !== $row['bucket'] ) : ?>
												<a class="af-sp-icon-btn" href="<?php echo esc_url( $aviso_url ); ?>" title="<?php esc_attr_e( 'Avisar al inquilino', 'arriendo-facil' ); ?>" aria-label="<?php esc_attr_e( 'Avisar al inquilino', 'arriendo-facil' ); ?>">
													<?php echo af_lucide( 'bell', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</a>
											<?php endif; ?>
											<button
												type="button"
												class="af-sp-icon-btn af-edit-schedule"
												title="<?php esc_attr_e( 'Editar', 'arriendo-facil' ); ?>"
												aria-label="<?php esc_attr_e( 'Editar', 'arriendo-facil' ); ?>"
												data-schedule="<?php echo esc_attr( (string) $row['schedule_id'] ); ?>"
												data-unit="<?php echo esc_attr( (string) $row['unit_id'] ); ?>"
												data-accommodation="<?php echo esc_attr( (string) $row['accommodation_id'] ); ?>"
												data-scope-title="<?php echo esc_attr( $group['title'] ); ?>"
												data-service="<?php echo esc_attr( $row['service'] ); ?>"
												data-due-day="<?php echo esc_attr( (string) $row['due_day'] ); ?>"
												data-flat="<?php echo esc_attr( (string) $row['flat_amount'] ); ?>"
												data-mode="<?php echo esc_attr( $row['amount_mode'] ); ?>"
												data-notes="<?php echo esc_attr( $row['notes'] ); ?>"
											><?php echo af_lucide( 'pencil', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
											<button type="button" class="af-sp-icon-btn af-delete-schedule" data-schedule="<?php echo esc_attr( (string) $row['schedule_id'] ); ?>" title="<?php esc_attr_e( 'Pausar alertas', 'arriendo-facil' ); ?>" aria-label="<?php esc_attr_e( 'Pausar alertas', 'arriendo-facil' ); ?>">
												<?php echo af_lucide( 'pause', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</button>
										</span>
									</div>
								</article>
							<?php endforeach; ?>

							<?php if ( $missing ) : ?>
								<button type="button" class="af-sp-tile af-sp-tile--add" <?php echo $open_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
									<span class="af-sp-tile--add__plus"><?php echo af_lucide( 'plus', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<span><?php esc_html_e( 'Agregar servicio', 'arriendo-facil' ); ?></span>
									<span class="af-sp-tile--add__icons" aria-hidden="true">
										<?php foreach ( $missing as $service_key => $service_meta ) : ?>
											<span class="af-sp-icon af-sp-icon--sm af-sp-icon--<?php echo esc_attr( $service_key ); ?>"><?php echo af_lucide( $service_meta['icon'], 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
										<?php endforeach; ?>
									</span>
								</button>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>

		<div class="af-sp-noresults" id="af-sp-noresults" hidden>
			<?php echo af_lucide( 'search', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><?php esc_html_e( 'Ningún servicio coincide con los filtros.', 'arriendo-facil' ); ?></p>
			<button type="button" class="button af-btn af-btn--ghost" id="af-sp-reset"><?php esc_html_e( 'Quitar filtros', 'arriendo-facil' ); ?></button>
		</div>
	<?php endif; ?>

	<?php if ( $paused ) : ?>
		<details class="af-sp-paused">
			<summary>
				<?php echo af_lucide( 'pause', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<strong><?php echo esc_html( sprintf( /* translators: %d */ __( 'Servicios pausados (%d)', 'arriendo-facil' ), count( $paused ) ) ); ?></strong>
				<span><?php esc_html_e( 'No generan alertas ni cobros hasta que los reactives.', 'arriendo-facil' ); ?></span>
			</summary>
			<ul class="af-sp-paused__list">
				<?php foreach ( $paused as $row ) : ?>
					<?php
					$paused_title = '' !== $row['unit_code'] ? $row['unit_code'] : ( $row['accommodation_title'] ? $row['accommodation_title'] : __( 'Inmueble', 'arriendo-facil' ) );
					$icon_key     = isset( $catalog[ $row['service'] ]['icon'] ) ? $catalog[ $row['service'] ]['icon'] : 'circle-alert';
					?>
					<li>
						<span class="af-sp-icon af-sp-icon--sm af-sp-icon--<?php echo esc_attr( $row['service'] ); ?>" aria-hidden="true"><?php echo af_lucide( $icon_key, 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<strong><?php echo esc_html( $row['service_label'] ); ?></strong>
						<span><?php echo esc_html( $paused_title ); ?> · <?php echo esc_html( sprintf( /* translators: %d */ __( 'día %d', 'arriendo-facil' ), (int) $row['due_day'] ) ); ?></span>
						<button
							type="button"
							class="button button-small af-sp-reactivate"
							data-unit="<?php echo esc_attr( (string) $row['unit_id'] ); ?>"
							data-accommodation="<?php echo esc_attr( (string) $row['accommodation_id'] ); ?>"
							data-service="<?php echo esc_attr( $row['service'] ); ?>"
							data-due-day="<?php echo esc_attr( (string) $row['due_day'] ); ?>"
							data-flat="<?php echo esc_attr( (string) $row['flat_amount'] ); ?>"
							data-mode="<?php echo esc_attr( $row['amount_mode'] ); ?>"
							data-notes="<?php echo esc_attr( $row['notes'] ); ?>"
						>
							<?php echo af_lucide( 'play', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Reactivar', 'arriendo-facil' ); ?>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
</div>

<!-- Modal: servicio (día de pago) -->
<div class="af-modal" id="af-modal-schedule" role="dialog" aria-modal="true" aria-labelledby="af-modal-schedule-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-schedule-title"><?php esc_html_e( 'Agregar servicio', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle"><?php esc_html_e( 'La alerta se repetirá sola cada mes en esta fecha.', 'arriendo-facil' ); ?></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-schedule-status"></p>
			<input type="hidden" id="af-schedule-id" value="" />
			<input type="hidden" id="af-schedule-unit" value="0" />
			<input type="hidden" id="af-schedule-mode" value="auto" />
			<div class="af-modal__field" id="af-schedule-property-field">
				<label for="af-schedule-property"><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></label>
				<select id="af-schedule-property" <?php echo empty( $property_options ) ? 'disabled' : ''; ?>>
					<option value=""><?php esc_html_e( 'Selecciona un inmueble', 'arriendo-facil' ); ?></option>
					<?php foreach ( $property_options as $property_option ) : ?>
						<option value="<?php echo esc_attr( (string) $property_option['id'] ); ?>"><?php echo esc_html( $property_option['title'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<p class="af-sp-scope" id="af-schedule-scope" hidden></p>
			<fieldset class="af-modal__field af-sp-picker">
				<legend><?php esc_html_e( 'Servicio', 'arriendo-facil' ); ?></legend>
				<div class="af-sp-picker__grid">
					<?php foreach ( $catalog as $service_key => $service_meta ) : ?>
						<label class="af-sp-picker__item af-sp-chip--<?php echo esc_attr( $service_key ); ?>">
							<input type="radio" name="af-schedule-service" value="<?php echo esc_attr( $service_key ); ?>" />
							<span class="af-sp-icon af-sp-icon--<?php echo esc_attr( $service_key ); ?>" aria-hidden="true"><?php echo af_lucide( $service_meta['icon'], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="af-sp-picker__label"><?php echo esc_html( $service_meta['label'] ); ?></span>
							<span class="af-sp-picker__badge"><?php esc_html_e( 'ya existe', 'arriendo-facil' ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
			<div class="af-modal__field">
				<label for="af-schedule-due-day"><?php esc_html_e( '¿Qué día del mes se paga?', 'arriendo-facil' ); ?></label>
				<div class="af-sp-dayfield">
					<input type="number" id="af-schedule-due-day" min="1" max="31" value="5" inputmode="numeric" />
					<div class="af-sp-daypicks" role="group" aria-label="<?php esc_attr_e( 'Días frecuentes', 'arriendo-facil' ); ?>">
						<?php foreach ( array( 1, 5, 10, 15, 20, 25, 30 ) as $pick ) : ?>
							<button type="button" data-pick-day="<?php echo esc_attr( (string) $pick ); ?>"><?php echo esc_html( (string) $pick ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>
				<p class="af-sp-preview" id="af-schedule-preview" aria-live="polite"></p>
			</div>
			<div class="af-modal__field">
				<label for="af-schedule-amount"><?php esc_html_e( 'Monto aproximado (opcional)', 'arriendo-facil' ); ?></label>
				<input type="number" id="af-schedule-amount" step="0.01" min="0" placeholder="0.00" />
				<p class="af-modal__hint"><?php esc_html_e( 'Déjalo vacío si solo quieres el recordatorio. Si lo indicas, podrás generar el cobro al inquilino.', 'arriendo-facil' ); ?></p>
			</div>
			<div class="af-modal__field">
				<label for="af-schedule-notes"><?php esc_html_e( 'Nota', 'arriendo-facil' ); ?></label>
				<input type="text" id="af-schedule-notes" placeholder="<?php esc_attr_e( 'Ej: se paga en el banco, cuenta #12345', 'arriendo-facil' ); ?>" />
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-schedule-confirm"><?php esc_html_e( 'Guardar', 'arriendo-facil' ); ?></button>
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

<!-- Modal: marcar servicio como pagado -->
<div class="af-modal" id="af-modal-bill" role="dialog" aria-modal="true" aria-labelledby="af-modal-bill-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-bill-title"><?php esc_html_e( 'Marcar como pagado', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-bill-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-bill-status"></p>
			<div class="af-modal__field">
				<label for="af-bill-date"><?php esc_html_e( 'Fecha de pago', 'arriendo-facil' ); ?></label>
				<input type="date" id="af-bill-date" value="<?php echo esc_attr( $today ); ?>" max="<?php echo esc_attr( $today ); ?>" />
			</div>
			<div class="af-modal__field">
				<label for="af-bill-amount"><?php esc_html_e( 'Monto pagado (opcional)', 'arriendo-facil' ); ?></label>
				<input type="number" id="af-bill-amount" step="0.01" min="0" placeholder="0.00" />
			</div>
			<div class="af-modal__field">
				<label for="af-bill-reference"><?php esc_html_e( 'Comprobante o nota (opcional)', 'arriendo-facil' ); ?></label>
				<input type="text" id="af-bill-reference" placeholder="<?php esc_attr_e( 'Ej: pagado en línea, ref. 98765', 'arriendo-facil' ); ?>" />
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-bill-confirm"><?php esc_html_e( 'Confirmar pago', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( $ledger_nonce ); ?>;
	const period = <?php echo wp_json_encode( $period ); ?>;
	const i18n = <?php echo wp_json_encode( array(
		'ruleSaved'      => __( 'Servicio guardado.', 'arriendo-facil' ),
		'paymentSaved'   => __( 'Pago registrado correctamente.', 'arriendo-facil' ),
		'pickProperty'   => __( 'Selecciona un inmueble.', 'arriendo-facil' ),
		'pickService'    => __( 'Elige un servicio.', 'arriendo-facil' ),
		'amountRequired' => __( 'Ingresa un monto mayor a cero.', 'arriendo-facil' ),
		'outstanding'    => __( 'Saldo pendiente:', 'arriendo-facil' ),
		'overpay'        => __( 'puedes superarlo y el excedente quedará como saldo a favor.', 'arriendo-facil' ),
		'confirmRemove'  => __( '¿Pausar las alertas de este servicio? Dejará de avisar y de generar cobros hasta que lo reactives.', 'arriendo-facil' ),
		'genericError'   => __( 'No se pudo completar la operación.', 'arriendo-facil' ),
		'titleNew'       => __( 'Agregar servicio', 'arriendo-facil' ),
		'titleEdit'      => __( 'Editar servicio', 'arriendo-facil' ),
		'dayInvalid'     => __( 'Indica un día entre 1 y 31.', 'arriendo-facil' ),
		'nextDue'        => __( 'Próximo vencimiento: %s', 'arriendo-facil' ),
		'dueToday'       => __( 'hoy', 'arriendo-facil' ),
		'dueTomorrow'    => __( 'mañana', 'arriendo-facil' ),
		'dueIn'          => __( 'en %d días', 'arriendo-facil' ),
		'shortMonth'     => __( 'En meses más cortos se usará el último día del mes.', 'arriendo-facil' ),
		'confirmUnmark'  => __( '¿Deshacer el pago? El servicio volverá a aparecer como pendiente.', 'arriendo-facil' ),
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

	// ---- Filtros del tablero ----
	const board = document.getElementById('af-sp-board');
	const noResults = document.getElementById('af-sp-noresults');
	const clearDay = document.getElementById('af-sp-clear-day');
	const searchInput = document.getElementById('af-sp-search');
	const state = { bucket: 'all', service: '', day: '', q: '' };

	function norm(value) {
		return (value || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
	}

	function setPressed(nodes, active) {
		nodes.forEach(function (node) {
			const on = node === active;
			node.classList.toggle('is-active', on);
			node.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
	}

	function applyFilters() {
		if (clearDay) { clearDay.hidden = !state.day; }
		if (!board) { return; }

		const filtering = state.bucket !== 'all' || state.service !== '' || state.day !== '';
		let shown = 0;

		board.querySelectorAll('.af-sp-property').forEach(function (card) {
			const textOk = !state.q || norm(card.getAttribute('data-search')).indexOf(state.q) !== -1;
			let tiles = 0;

			card.querySelectorAll('.af-sp-tile[data-bucket]').forEach(function (tile) {
				const ok = textOk
					&& (state.bucket === 'all' || tile.getAttribute('data-bucket') === state.bucket)
					&& (!state.service || tile.getAttribute('data-service') === state.service)
					&& (!state.day || tile.getAttribute('data-day') === state.day);
				tile.hidden = !ok;
				if (ok) { tiles++; }
			});

			card.querySelectorAll('.af-sp-tile--add').forEach(function (add) { add.hidden = filtering; });

			const visible = tiles > 0 || (!filtering && textOk);
			card.hidden = !visible;
			if (visible) { shown++; }
		});

		if (noResults) { noResults.hidden = shown > 0; }
	}

	const tabs = Array.prototype.slice.call(document.querySelectorAll('.af-sp-tab'));
	tabs.forEach(function (tab) {
		tab.addEventListener('click', function () {
			state.bucket = tab.getAttribute('data-bucket');
			setPressed(tabs, tab);
			applyFilters();
		});
	});

	const chips = Array.prototype.slice.call(document.querySelectorAll('.af-sp-chip[data-service]'));
	chips.forEach(function (chip) {
		chip.addEventListener('click', function () {
			state.service = chip.getAttribute('data-service');
			setPressed(chips, chip);
			applyFilters();
		});
	});

	const days = Array.prototype.slice.call(document.querySelectorAll('.af-sp-day'));
	days.forEach(function (dayBtn) {
		dayBtn.addEventListener('click', function () {
			const value = dayBtn.getAttribute('data-day');
			state.day = state.day === value ? '' : value;
			setPressed(days, state.day ? dayBtn : null);
			applyFilters();
			if (state.day && board) { board.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
		});
	});

	if (clearDay) {
		clearDay.addEventListener('click', function () {
			state.day = '';
			setPressed(days, null);
			applyFilters();
		});
	}

	if (searchInput) {
		searchInput.addEventListener('input', function () {
			state.q = norm(searchInput.value);
			applyFilters();
		});
	}

	const resetButton = document.getElementById('af-sp-reset');
	if (resetButton) {
		resetButton.addEventListener('click', function () {
			state.bucket = 'all'; state.service = ''; state.day = ''; state.q = '';
			if (searchInput) { searchInput.value = ''; }
			setPressed(tabs, tabs[0]);
			setPressed(chips, chips[0]);
			setPressed(days, null);
			applyFilters();
		});
	}

	// ---- Servicio (día de pago) ----
	const scheduleModal = document.getElementById('af-modal-schedule');
	const scheduleTitle = document.getElementById('af-modal-schedule-title');
	const scheduleStatus = document.getElementById('af-schedule-status');
	const scheduleId = document.getElementById('af-schedule-id');
	const scheduleUnit = document.getElementById('af-schedule-unit');
	const scheduleProperty = document.getElementById('af-schedule-property');
	const schedulePropertyField = document.getElementById('af-schedule-property-field');
	const scheduleScope = document.getElementById('af-schedule-scope');
	const scheduleDueDay = document.getElementById('af-schedule-due-day');
	const scheduleMode = document.getElementById('af-schedule-mode');
	const scheduleAmount = document.getElementById('af-schedule-amount');
	const scheduleNotes = document.getElementById('af-schedule-notes');
	const schedulePreview = document.getElementById('af-schedule-preview');
	const scheduleConfirm = document.getElementById('af-schedule-confirm');
	const serviceInputs = Array.prototype.slice.call(scheduleModal.querySelectorAll('input[name="af-schedule-service"]'));
	const dayPicks = Array.prototype.slice.call(scheduleModal.querySelectorAll('[data-pick-day]'));
	const configuredMap = <?php echo wp_json_encode( (object) $configured_map ); ?>;
	const dateFormat = new Intl.DateTimeFormat(document.documentElement.lang || 'es', { weekday: 'long', day: 'numeric', month: 'long' });

	// Accommodation the operator cannot change while the modal is open. Kept
	// apart from the <select> because a disabled control is not readable back.
	let lockedAccommodation = '';
	let editing = false;

	function lockProperty(propertyId) {
		lockedAccommodation = propertyId ? String(propertyId) : '';
		if (!scheduleProperty) { return; }
		scheduleProperty.value = lockedAccommodation;
		scheduleProperty.disabled = !!lockedAccommodation;
	}

	function showScope(unitTitle) {
		const isUnit = !!unitTitle;
		schedulePropertyField.hidden = isUnit;
		scheduleScope.hidden = !isUnit;
		scheduleScope.textContent = unitTitle || '';
	}

	function currentAccommodation() {
		return lockedAccommodation || (scheduleProperty ? scheduleProperty.value : '');
	}

	function getService() {
		const checked = serviceInputs.filter(function (input) { return input.checked; })[0];
		return checked ? checked.value : '';
	}

	function setService(value, locked) {
		serviceInputs.forEach(function (input) {
			input.checked = input.value === value;
			input.disabled = !!locked && input.value !== value;
		});
	}

	function markConfigured() {
		const list = (!editing && scheduleUnit.value === '0' && configuredMap[currentAccommodation()]) || [];
		serviceInputs.forEach(function (input) {
			input.closest('.af-sp-picker__item').classList.toggle('is-configured', list.indexOf(input.value) !== -1);
		});
	}

	function firstFreeService() {
		const list = configuredMap[currentAccommodation()] || [];
		const free = serviceInputs.filter(function (input) { return list.indexOf(input.value) === -1; })[0];
		return free ? free.value : serviceInputs[0].value;
	}

	function updatePreview() {
		const day = parseInt(scheduleDueDay.value, 10);
		dayPicks.forEach(function (btn) { btn.classList.toggle('is-active', parseInt(btn.getAttribute('data-pick-day'), 10) === day); });

		if (!day || day < 1 || day > 31) {
			schedulePreview.textContent = i18n.dayInvalid;
			schedulePreview.classList.add('is-error');
			return;
		}
		schedulePreview.classList.remove('is-error');

		const today = new Date();
		today.setHours(0, 0, 0, 0);
		function resolve(year, monthIndex) {
			const last = new Date(year, monthIndex + 1, 0).getDate();
			return new Date(year, monthIndex, Math.min(day, last));
		}
		let next = resolve(today.getFullYear(), today.getMonth());
		if (next < today) { next = resolve(today.getFullYear(), today.getMonth() + 1); }

		const diff = Math.round((next - today) / 86400000);
		const when = diff === 0 ? i18n.dueToday : (diff === 1 ? i18n.dueTomorrow : i18n.dueIn.replace('%d', diff));
		let text = i18n.nextDue.replace('%s', dateFormat.format(next)) + ' · ' + when + '.';
		if (day > 28) { text += ' ' + i18n.shortMonth; }
		schedulePreview.textContent = text;
	}

	scheduleDueDay.addEventListener('input', updatePreview);
	dayPicks.forEach(function (btn) {
		btn.addEventListener('click', function () {
			scheduleDueDay.value = btn.getAttribute('data-pick-day');
			updatePreview();
		});
	});

	if (scheduleProperty) {
		scheduleProperty.addEventListener('change', function () {
			markConfigured();
			if (!editing) { setService(firstFreeService(), false); }
		});
	}

	function openSchedule(propertyId, unitId, unitTitle, service) {
		editing = false;
		scheduleTitle.textContent = i18n.titleNew;
		scheduleId.value = '';
		scheduleUnit.value = unitId ? String(unitId) : '0';
		lockProperty(unitId ? '' : propertyId);
		showScope(unitId ? unitTitle : '');
		setService(service || firstFreeService(), false);
		scheduleDueDay.value = 5;
		scheduleMode.value = 'auto';
		scheduleAmount.value = '';
		scheduleNotes.value = '';
		markConfigured();
		updatePreview();
		clearStatus(scheduleStatus);
		openModal(scheduleModal);
	}

	document.querySelectorAll('[data-af-open-schedule]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			openSchedule(
				btn.getAttribute('data-af-open-schedule'),
				btn.getAttribute('data-unit'),
				btn.getAttribute('data-scope-title'),
				btn.getAttribute('data-service')
			);
		});
	});

	document.querySelectorAll('.af-edit-schedule').forEach(function (btn) {
		btn.addEventListener('click', function () {
			const unit = btn.getAttribute('data-unit') || '0';
			const acc = btn.getAttribute('data-accommodation') || '0';

			editing = true;
			scheduleTitle.textContent = i18n.titleEdit;
			scheduleId.value = btn.getAttribute('data-schedule') || '';
			scheduleUnit.value = unit;
			lockProperty(unit === '0' ? acc : '');
			showScope(unit === '0' ? '' : btn.getAttribute('data-scope-title'));
			// The rule is keyed by scope + service, so changing the service while
			// editing would silently create a second rule instead.
			setService(btn.getAttribute('data-service'), true);
			scheduleDueDay.value = btn.getAttribute('data-due-day') || '5';
			scheduleMode.value = btn.getAttribute('data-mode') || 'auto';
			const flat = parseFloat(btn.getAttribute('data-flat')) || 0;
			scheduleAmount.value = flat > 0 ? flat.toFixed(2) : '';
			scheduleNotes.value = btn.getAttribute('data-notes') || '';
			markConfigured();
			updatePreview();
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

	document.querySelectorAll('.af-sp-reactivate').forEach(function (btn) {
		btn.addEventListener('click', function () {
			const unit = btn.getAttribute('data-unit') || '0';
			btn.disabled = true;
			post({
				action: 'af_save_service_schedule',
				nonce: nonce,
				unit_id: unit,
				accommodation_id: unit === '0' ? btn.getAttribute('data-accommodation') : '',
				service: btn.getAttribute('data-service'),
				due_day: btn.getAttribute('data-due-day'),
				amount_mode: btn.getAttribute('data-mode') || 'auto',
				flat_amount: btn.getAttribute('data-flat') || '0',
				notes: btn.getAttribute('data-notes') || '',
				is_active: '1'
			}).then(function (json) {
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
		const accommodation = unit !== '0' ? '' : currentAccommodation();
		const service = getService();

		if (unit === '0' && !accommodation) {
			setStatus(scheduleStatus, i18n.pickProperty, 'error');
			return;
		}
		if (!service) {
			setStatus(scheduleStatus, i18n.pickService, 'error');
			return;
		}
		const day = parseInt(scheduleDueDay.value, 10);
		if (!day || day < 1 || day > 31) {
			setStatus(scheduleStatus, i18n.dayInvalid, 'error');
			return;
		}

		scheduleConfirm.disabled = true;
		clearStatus(scheduleStatus);

		post({
			action: 'af_save_service_schedule',
			nonce: nonce,
			unit_id: unit,
			accommodation_id: accommodation,
			service: service,
			due_day: day,
			amount_mode: scheduleMode.value,
			flat_amount: scheduleAmount.value || '0',
			notes: scheduleNotes.value,
			is_active: '1'
		}).then(function (json) {
			scheduleConfirm.disabled = false;
			if (!json || !json.success) {
				setStatus(scheduleStatus, (json && json.data && json.data.message) || i18n.genericError, 'error');
				return;
			}
			setStatus(scheduleStatus, i18n.ruleSaved, 'success');
			setTimeout(function () { window.location.reload(); }, 600);
		}).catch(function () {
			scheduleConfirm.disabled = false;
			setStatus(scheduleStatus, i18n.genericError, 'error');
		});
	});

	// ---- Marcar servicio como pagado ----
	const billModal = document.getElementById('af-modal-bill');
	const billStatus = document.getElementById('af-bill-status');
	const billSubtitle = document.getElementById('af-bill-subtitle');
	const billDate = document.getElementById('af-bill-date');
	const billAmount = document.getElementById('af-bill-amount');
	const billReference = document.getElementById('af-bill-reference');
	const billConfirm = document.getElementById('af-bill-confirm');
	let billSchedule = '';

	document.querySelectorAll('.af-sp-mark-paid').forEach(function (btn) {
		btn.addEventListener('click', function () {
			billSchedule = btn.getAttribute('data-schedule');
			billSubtitle.textContent = btn.getAttribute('data-concept') + ' · ' + period;
			const suggested = parseFloat(btn.getAttribute('data-amount')) || 0;
			billAmount.value = suggested > 0 ? suggested.toFixed(2) : '';
			billReference.value = '';
			clearStatus(billStatus);
			openModal(billModal);
		});
	});

	billConfirm.addEventListener('click', function () {
		billConfirm.disabled = true;
		clearStatus(billStatus);
		post({
			action: 'af_mark_service_paid',
			nonce: nonce,
			schedule_id: billSchedule,
			period: period,
			paid_on: billDate.value,
			amount: billAmount.value || '0',
			reference: billReference.value
		}).then(function (json) {
			billConfirm.disabled = false;
			if (!json || !json.success) {
				setStatus(billStatus, (json && json.data && json.data.message) || i18n.genericError, 'error');
				return;
			}
			setStatus(billStatus, json.data.message, 'success');
			setTimeout(function () { window.location.reload(); }, 500);
		}).catch(function () {
			billConfirm.disabled = false;
			setStatus(billStatus, i18n.genericError, 'error');
		});
	});

	document.querySelectorAll('.af-sp-unmark').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!window.confirm(i18n.confirmUnmark)) { return; }
			btn.disabled = true;
			post({ action: 'af_unmark_service_paid', nonce: nonce, schedule_id: btn.getAttribute('data-schedule'), period: period }).then(function (json) {
				btn.disabled = false;
				if (!json || !json.success) {
					window.alert((json && json.data && json.data.message) || i18n.genericError);
					return;
				}
				window.location.reload();
			});
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
