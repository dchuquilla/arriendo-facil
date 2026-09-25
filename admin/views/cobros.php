<?php
/**
 * Cobros y Servicios hub: recordatorios de "qué cobrar y cuándo".
 *
 * No registra pagos: agrega los cargos pendientes, la renta mensual aún no
 * cargada y las lecturas pendientes en una sola lista priorizada, con
 * accionables (enviar aviso de cobro, registrar pago) y la captura directa de
 * las lecturas de medidor que faltan.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$cobros_scope_ids    = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
$cobros_scope_clause = null === $cobros_scope_ids ? '' : ' AND l.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $cobros_scope_ids ) . ')';
$today               = current_time( 'Y-m-d' );
$next_window         = gmdate( 'Y-m-d', strtotime( '+15 days' ) );
$current_period      = current_time( 'Y-m' );

$charge_type_labels = array(
	'canon'    => __( 'Arriendo', 'arriendo-facil' ),
	'alicuota' => __( 'Alícuota / HOA', 'arriendo-facil' ),
	'agua'     => __( 'Agua', 'arriendo-facil' ),
	'luz'      => __( 'Luz', 'arriendo-facil' ),
	'gas'      => __( 'Gas', 'arriendo-facil' ),
	'internet' => __( 'Internet', 'arriendo-facil' ),
	'multa'    => __( 'Multa', 'arriendo-facil' ),
	'otro'     => __( 'Otro', 'arriendo-facil' ),
);

// 1) Cargos pendientes/vencidos (transacciones ya generadas).
$upcoming_charges = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT c.id, c.lease_id, c.accommodation_id, c.guest_id, c.charge_type, c.description,
		        c.amount, c.amount_paid, c.due_date, c.status,
		        p.post_title AS accommodation_title,
		        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
		 FROM {$wpdb->prefix}af_charges c
		 LEFT JOIN {$wpdb->prefix}af_leases l ON l.id = c.lease_id
		 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = COALESCE(c.guest_id, l.guest_id)
		 LEFT JOIN {$wpdb->posts} p ON p.ID = c.accommodation_id
		 WHERE c.status IN ('pending','partial','overdue')
		   AND c.due_date <= %s{$cobros_scope_clause}
		 ORDER BY c.due_date ASC
		 LIMIT 120", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		array_merge( array( $next_window ) )
	)
);

// 2) Renta mensual aún no cargada para contratos activos.
$rent_reminders = array();
$active_leases = (array) $wpdb->get_results(
	"SELECT l.id, l.accommodation_id, l.start_date,
	        p.post_title AS accommodation_title,
	        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
	 FROM {$wpdb->prefix}af_leases l
	 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
	 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
	 WHERE l.deleted_at IS NULL AND l.status = 'active'{$cobros_scope_clause}
	 ORDER BY l.end_date ASC
	 LIMIT 150" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

foreach ( $active_leases as $rent_lease ) {
	$has_canon = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}af_charges
			 WHERE lease_id = %d AND charge_type = 'canon' AND period = %s",
			(int) $rent_lease->id,
			$current_period
		)
	);
	if ( $has_canon ) {
		continue;
	}

	$rent_day = (int) gmdate( 'j', strtotime( (string) $rent_lease->start_date ) );
	$last_day = (int) gmdate( 't' );
	$rent_day = min( $rent_day, $last_day );
	$due_date = sprintf( '%s-%02d', $current_period, $rent_day );
	if ( $due_date > $next_window ) {
		continue;
	}

	$last_amount = (float) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT amount FROM {$wpdb->prefix}af_charges
			 WHERE lease_id = %d AND charge_type = 'canon'
			 ORDER BY period DESC LIMIT 1",
			(int) $rent_lease->id
		)
	);

	$rent_reminders[] = array(
		'lease_id'            => (int) $rent_lease->id,
		'accommodation_id'    => (int) $rent_lease->accommodation_id,
		'accommodation_title' => (string) $rent_lease->accommodation_title,
		'guest_name'          => trim( (string) $rent_lease->guest_name ),
		'charge_type'         => 'canon',
		'description'         => __( 'Cargo de arriendo del período (a generar)', 'arriendo-facil' ),
		'amount'              => $last_amount,
		'due_date'            => $due_date,
	);
}

// 3) Lecturas pendientes: medidores con historial pero sin lectura del período.
$pending_meters = array();
if ( '' === $cobros_scope_clause || ! empty( $cobros_scope_ids ) ) {
	$scope_sql = null === $cobros_scope_ids ? '' : ' AND accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $cobros_scope_ids ) . ')';
	$meter_groups = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT accommodation_id, service
			 FROM {$wpdb->prefix}af_meter_readings
			 WHERE 1=1{$scope_sql}
			 GROUP BY accommodation_id, service
			 HAVING MAX(period) < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array( $current_period )
		)
	);

	foreach ( $meter_groups as $mg ) {
		if ( ! (int) $mg->accommodation_id ) {
			continue;
		}
		$pending_meters[] = array(
			'accommodation_id' => (int) $mg->accommodation_id,
			'service'          => (string) $mg->service,
			'title'            => get_the_title( (int) $mg->accommodation_id ),
		);
	}
}

$sum_upcoming = 0.0;
$sum_overdue  = 0.0;
$count_overdue = 0;
foreach ( $upcoming_charges as $uc ) {
	$remaining = (float) $uc->amount - (float) $uc->amount_paid;
	if ( $uc->due_date < $today ) {
		$count_overdue++;
		$sum_overdue += $remaining;
	}
	$sum_upcoming += $remaining;
}

$unread_avisos = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}af_notification_messages WHERE status = 'sent'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

$items = array();

foreach ( $upcoming_charges as $uc ) {
	$remaining = (float) $uc->amount - (float) $uc->amount_paid;
	$items[] = array(
		'kind'          => 'charge',
		'lease_id'      => (int) $uc->lease_id,
		'acc_id'        => (int) $uc->accommodation_id,
		'acc_title'     => (string) $uc->accommodation_title,
		'guest'         => trim( (string) $uc->guest_name ),
		'concept'       => isset( $charge_type_labels[ $uc->charge_type ] ) ? $charge_type_labels[ $uc->charge_type ] : (string) $uc->charge_type,
		'detail'        => $uc->description ? (string) $uc->description : '',
		'amount'        => $remaining,
		'due_date'      => (string) $uc->due_date,
		'status'        => (string) $uc->status,
	);
}

foreach ( $rent_reminders as $rr ) {
	$items[] = array(
		'kind'      => 'rent',
		'lease_id'  => $rr['lease_id'],
		'acc_id'    => $rr['accommodation_id'],
		'acc_title' => $rr['accommodation_title'],
		'guest'     => $rr['guest_name'],
		'concept'   => __( 'Arriendo', 'arriendo-facil' ),
		'detail'    => $rr['description'],
		'amount'    => $rr['amount'],
		'due_date'  => $rr['due_date'],
	);
}

// Ordenar por fecha de vencimiento.
usort(
	$items,
	static function ( $a, $b ) {
		return strcmp( (string) $a['due_date'], (string) $b['due_date'] );
	}
);
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Cobranza', 'arriendo-facil' ),
			'title'    => __( 'Cobros y Servicios', 'arriendo-facil' ),
			'subtitle' => __( 'Recordatorios de qué cobrar y cuándo, enlazados a tus inquilinos. Registra aquí las lecturas de medidor pendientes y gestiona los pagos desde Control de pagos.', 'arriendo-facil' ),
		)
	);
	?>

	<?php if ( ! empty( $items ) ) : ?>
		<div class="af-banner af-banner--cobros" style="display:flex; gap:var(--af-space-4); align-items:center; flex-wrap:wrap; background:linear-gradient(90deg, #eff6ff, #f8fafc); border:1px solid #dbeafe; border-radius:12px; padding:16px 20px; margin-bottom:var(--af-space-4);">
			<span class="af-pill af-pill--success" style="font-size:13px;">📅 <?php echo esc_html( sprintf( /* translators: %d */ __( '%d recordatorios por atender', 'arriendo-facil' ), count( $items ) ) ); ?></span>
			<span style="font-size:13px; color:#475467;">
				<strong style="color:#17202a;"><?php echo esc_html( number_format_i18n( $sum_upcoming, 2 ) ); ?> USD</strong>
				<?php esc_html_e( 'por cobrar en los próximos 15 días', 'arriendo-facil' ); ?>
				<?php if ( $count_overdue ) : ?>
					— <span style="color:#b42318;"><?php echo esc_html( sprintf( /* translators: 1: count, 2: amount */ __( '%1$d vencidos (%2$s)', 'arriendo-facil' ), $count_overdue, number_format_i18n( $sum_overdue, 2 ) ) ); ?></span>
				<?php endif; ?>
			</span>
		</div>
	<?php endif; ?>

	<div class="af-kpi-grid">
		<article class="af-kpi af-kpi--success">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Por cobrar próximos 15 días', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $sum_upcoming, 2 ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Suma de saldos pendientes', 'arriendo-facil' ); ?></div>
		</article>
		<article class="af-kpi">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Cargos vencidos', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value" style="color:<?php echo $count_overdue ? '#b42318' : 'inherit'; ?>;"><?php echo esc_html( number_format_i18n( $count_overdue ) ); ?></div>
			<div class="af-kpi__hint"><?php echo $count_overdue ? esc_html__( 'Requieren seguimiento', 'arriendo-facil' ) : esc_html__( 'Nada vencido', 'arriendo-facil' ); ?></div>
		</article>
		<article class="af-kpi af-kpi--accent">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Lecturas pendientes', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( count( $pending_meters ) ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Medidores sin lectura del mes', 'arriendo-facil' ); ?></div>
		</article>
		<article class="af-kpi">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Avisos sin leer', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $unread_avisos ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Sin confirmación del inquilino', 'arriendo-facil' ); ?></div>
		</article>
	</div>

	<section class="af-section">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Qué cobrar y cuándo', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Cargos pendientes, renta del período por generar y lecturas del mes.', 'arriendo-facil' ); ?></p>
			</div>
			<div class="af-section__actions" style="display:flex; gap:8px;">
				<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections' ) ); ?>"><?php esc_html_e( 'Control de pagos', 'arriendo-facil' ); ?></a>
				<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-meter-readings' ) ); ?>"><?php esc_html_e( 'Ver alertas de lecturas', 'arriendo-facil' ); ?></a>
				<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-buildings' ) ); ?>"><?php esc_html_e( 'Cobranza por inmueble', 'arriendo-facil' ); ?></a>
			</div>
		</header>

		<?php if ( empty( $items ) ) : ?>
			<div class="af-empty">
				<span class="af-empty__icon" aria-hidden="true">✅</span>
				<h3 class="af-empty__title"><?php esc_html_e( 'Todo al día', 'arriendo-facil' ); ?></h3>
				<p class="af-empty__text"><?php esc_html_e( 'No hay cobros pendientes ni lecturas por registrar en los próximos 15 días.', 'arriendo-facil' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped af-data-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Inquilino', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Concepto', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Monto', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Vence', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Acción', 'arriendo-facil' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php
						$is_charge  = 'charge' === $item['kind'];
						$is_overdue = $item['due_date'] < $today;
						$days       = (int) ( ( strtotime( $item['due_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS );
						$aviso_url  = admin_url( 'admin.php?page=af-avisos&af_aviso_lease=' . $item['lease_id'] . '&af_aviso_subject=' . rawurlencode( sprintf( /* translators: 1: concepto, 2: fecha */ __( 'Recordatorio: %1$s vence el %2$s', 'arriendo-facil' ), $item['concept'], $item['due_date'] ) ) . '&af_aviso_type=cobro' );
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><?php echo esc_html( '' !== $item['acc_title'] ? $item['acc_title'] : ( '#' . $item['acc_id'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Inquilino', 'arriendo-facil' ); ?>"><strong><?php echo esc_html( $item['guest'] ? $item['guest'] : '—' ); ?></strong></td>
							<td data-label="<?php esc_attr_e( 'Concepto', 'arriendo-facil' ); ?>">
								<span class="af-pill af-pill--neutral"><?php echo esc_html( $item['concept'] ); ?></span>
								<?php if ( $item['detail'] ) : ?>
									<div class="af-td-meta" style="margin-top:2px;"><?php echo esc_html( $item['detail'] ); ?></div>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Monto', 'arriendo-facil' ); ?>">
								<?php if ( $item['amount'] > 0 ) : ?>
									$<?php echo esc_html( number_format_i18n( $item['amount'], 2 ) ); ?>
								<?php else : ?>
									<span class="af-td-meta"><?php esc_html_e( 's/ref', 'arriendo-facil' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Vence', 'arriendo-facil' ); ?>">
								<span class="af-pill <?php echo $is_overdue ? 'af-pill--danger' : ( $days <= 3 ? 'af-pill--warning' : 'af-pill--success' ); ?>">
									<?php
									if ( $is_overdue ) {
										echo esc_html( sprintf( /* translators: %d: days */ __( 'Vencido hace %d días', 'arriendo-facil' ), absint( $days ) ) );
									} elseif ( 0 === $days ) {
										esc_html_e( 'Vence hoy', 'arriendo-facil' );
									} else {
										echo esc_html( sprintf( /* translators: %d: days */ __( 'Vence en %d días', 'arriendo-facil' ), $days ) );
									}
									?>
								</span>
							</td>
							<td data-label="<?php esc_attr_e( 'Acción', 'arriendo-facil' ); ?>">
								<div style="display:flex; gap:6px; flex-wrap:wrap;">
									<a class="button button-small" href="<?php echo esc_url( $aviso_url ); ?>"><?php esc_html_e( 'Avisar', 'arriendo-facil' ); ?></a>
									<?php if ( $is_charge ) : ?>
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections' ) ); ?>"><?php esc_html_e( 'Registrar pago', 'arriendo-facil' ); ?></a>
									<?php else : ?>
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections' ) ); ?>"><?php esc_html_e( 'Generar cargo', 'arriendo-facil' ); ?></a>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<?php if ( ! empty( $pending_meters ) ) : ?>
	<section class="af-section">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Lecturas del mes pendientes', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Medidores con historial que aún no tienen lectura para el período actual. Anota el número que marca cada medidor y el precio de la unidad de consumo: se genera el cargo para el inquilino.', 'arriendo-facil' ); ?></p>
			</div>
		</header>
		<table class="wp-list-table widefat fixed striped af-data-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Servicio', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Lectura actual', 'arriendo-facil' ); ?> *</th>
					<th><?php esc_html_e( 'Precio por unidad', 'arriendo-facil' ); ?> *</th>
					<th style="text-align:right;"><?php esc_html_e( 'Registrar', 'arriendo-facil' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pending_meters as $meter ) : ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><strong><?php echo esc_html( $meter['title'] ? $meter['title'] : ( '#' . $meter['accommodation_id'] ) ); ?></strong></td>
						<td data-label="<?php esc_attr_e( 'Servicio', 'arriendo-facil' ); ?>"><span class="af-pill af-pill--neutral"><?php echo esc_html( ucfirst( $meter['service'] ) ); ?></span></td>
						<td colspan="3">
							<form class="af-cobros-reading" data-status-target="af-cobros-reading-status-<?php echo esc_attr( (int) $meter['accommodation_id'] . '-' . $meter['service'] ); ?>" style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
								<input type="hidden" name="accommodation_id" value="<?php echo esc_attr( (int) $meter['accommodation_id'] ); ?>" />
								<input type="hidden" name="service" value="<?php echo esc_attr( $meter['service'] ); ?>" />
								<input type="hidden" name="period" value="<?php echo esc_attr( $current_period ); ?>" />
								<input type="number" name="current_reading" required step="0.001" min="0" placeholder="0.000" style="min-height:30px; max-width:130px;" aria-label="Lectura actual" />
								<input type="number" name="unit_rate" required step="0.0001" min="0" placeholder="0.0000" style="min-height:30px; max-width:130px;" aria-label="Precio por unidad" />
								<button type="submit" class="button button-small"><?php esc_html_e( 'Guardar y cobrar', 'arriendo-facil' ); ?></button>
								<span id="af-cobros-reading-status-<?php echo esc_attr( (int) $meter['accommodation_id'] . '-' . $meter['service'] ); ?>" class="af-td-meta"></span>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php endif; ?>
</div>

<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_ledger_nonce' ) ); ?>;

	document.querySelectorAll('.af-cobros-reading').forEach(function (form) {
		const status = document.getElementById(form.getAttribute('data-status-target'));
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			const btn = form.querySelector('button[type="submit"]');
			btn.disabled = true;
			if (status) { status.textContent = ''; }

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
					if (status) { status.textContent = (json && json.data && json.data.message) || 'Error'; }
					return;
				}
				window.location.reload();
			}).catch(function () {
				btn.disabled = false;
				if (status) { status.textContent = 'Error'; }
			});
		});
	});
}());
</script>