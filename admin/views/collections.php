<?php
/**
 * Collections (cobranza) admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$charges_table = Arriendo_Facil_Billing_Ledger::charges_table();
$leases_table  = $wpdb->prefix . 'af_leases';
$guests_table  = $wpdb->prefix . 'af_guests';
$charge_types  = Arriendo_Facil_Billing_Ledger::charge_types();
$methods       = Arriendo_Facil_Billing_Ledger::payment_methods();

$period_filter = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : gmdate( 'Y-m' );
$status_filter = isset( $_GET['charge_status'] ) ? sanitize_key( wp_unslash( $_GET['charge_status'] ) ) : '';

if ( ! preg_match( '/^\d{4}-\d{2}$/', $period_filter ) ) {
	$period_filter = gmdate( 'Y-m' );
}

$where_clauses = array( "c.status != 'void'", 'c.period = %s' );
$where_args    = array( $period_filter );

if ( in_array( $status_filter, array( 'pending', 'partial', 'paid', 'overdue' ), true ) ) {
	$where_clauses[] = 'c.status = %s';
	$where_args[]    = $status_filter;
}

$where_sql = implode( ' AND ', $where_clauses );

$summary = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT
			COUNT(*) AS total_charges,
			COALESCE(SUM(c.amount), 0) AS total_charged,
			COALESCE(SUM(c.amount_paid), 0) AS total_paid
		 FROM {$charges_table} c
		 WHERE {$where_sql}",
		$where_args
	)
);

$total_charged = isset( $summary->total_charged ) ? (float) $summary->total_charged : 0.0;
$total_paid    = isset( $summary->total_paid ) ? (float) $summary->total_paid : 0.0;
$balance       = round( $total_charged - $total_paid, 2 );
$collect_rate  = $total_charged > 0 ? ( $total_paid / $total_charged ) * 100 : 0;

$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT
			c.*,
			p.post_title AS accommodation_title,
			CONCAT(g.first_name, ' ', g.last_name) AS guest_name
		 FROM {$charges_table} c
		 LEFT JOIN {$leases_table} l ON l.id = c.lease_id
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 LEFT JOIN {$guests_table} g ON g.id = c.guest_id
		 WHERE {$where_sql}
		 ORDER BY c.status = 'overdue' DESC, c.due_date ASC
		 LIMIT 200",
		$where_args
	)
);

$aging = Arriendo_Facil_Billing_Ledger::get_aging_report();

// Estado de cuenta de un contrato concreto.
$statement_lease_id = isset( $_GET['statement_lease'] ) ? absint( wp_unslash( $_GET['statement_lease'] ) ) : 0;
$statement          = $statement_lease_id ? Arriendo_Facil_Billing_Ledger::get_statement_by_lease( $statement_lease_id ) : null;
$statement_context  = null;

if ( $statement_lease_id ) {
	$statement_context = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT l.id, l.start_date, l.end_date, l.monthly_rent,
			        p.post_title AS accommodation_title,
			        CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
			        g.email AS guest_email
			 FROM {$leases_table} l
			 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
			 LEFT JOIN {$guests_table} g ON g.id = l.guest_id
			 WHERE l.id = %d",
			$statement_lease_id
		)
	);
}
?>
<?php if ( $statement && $statement_context ) : ?>
<div class="wrap af-shell">
	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Cobranza', 'arriendo-facil' ),
			'title'    => __( 'Estado de cuenta', 'arriendo-facil' ),
			'subtitle' => sprintf(
				/* translators: 1: property title, 2: tenant name */
				__( '%1$s · %2$s', 'arriendo-facil' ),
				$statement_context->accommodation_title,
				$statement_context->guest_name
			),
			'actions'  => array(
				sprintf(
					'<a href="%s" class="button af-btn af-btn--ghost">%s</a>',
					esc_url( admin_url( 'admin.php?page=af-collections' ) ),
					esc_html__( '← Volver', 'arriendo-facil' )
				),
				sprintf(
					'<button type="button" class="button af-btn af-btn--primary" onclick="window.print()">%s</button>',
					esc_html__( 'Imprimir', 'arriendo-facil' )
				),
			),
		)
	);
	?>

	<div class="af-kpi-grid">
		<article class="af-kpi">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Total facturado', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $statement['total_charged'], 2 ) ); ?></div>
		</article>
		<article class="af-kpi af-kpi--success">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Total pagado', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $statement['total_paid'], 2 ) ); ?></div>
		</article>
		<article class="af-kpi <?php echo $statement['balance'] > 0 ? 'af-kpi--attention' : 'af-kpi--success'; ?>">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Saldo', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $statement['balance'], 2 ) ); ?></div>
		</article>
	</div>

	<section class="af-section">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Movimientos', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: start date, 2: end date */
							__( 'Contrato del %1$s al %2$s', 'arriendo-facil' ),
							$statement_context->start_date,
							$statement_context->end_date
						)
					);
					?>
				</p>
			</div>
		</header>

		<table class="wp-list-table widefat fixed striped af-data-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Periodo', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Concepto', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Vence', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Monto', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Pagado', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Saldo', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $statement['charges'] ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Sin movimientos registrados.', 'arriendo-facil' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $statement['charges'] as $movement ) : ?>
						<?php
						$movement_balance = round( (float) $movement->amount - (float) $movement->amount_paid, 2 );
						$movement_pill    = 'af-pill--neutral';
						if ( 'paid' === $movement->status ) {
							$movement_pill = 'af-pill--success';
						} elseif ( 'overdue' === $movement->status ) {
							$movement_pill = 'af-pill--danger';
						} elseif ( 'partial' === $movement->status ) {
							$movement_pill = 'af-pill--warning';
						}
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Periodo', 'arriendo-facil' ); ?>"><?php echo esc_html( $movement->period ); ?></td>
							<td data-label="<?php esc_attr_e( 'Concepto', 'arriendo-facil' ); ?>"><?php echo esc_html( $charge_types[ $movement->charge_type ] ?? $movement->charge_type ); ?></td>
							<td data-label="<?php esc_attr_e( 'Vence', 'arriendo-facil' ); ?>"><?php echo esc_html( (string) $movement->due_date ); ?></td>
							<td data-label="<?php esc_attr_e( 'Monto', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $movement->amount, 2 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Pagado', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $movement->amount_paid, 2 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Saldo', 'arriendo-facil' ); ?>"><strong>$<?php echo esc_html( number_format_i18n( $movement_balance, 2 ) ); ?></strong></td>
							<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>"><span class="af-pill <?php echo esc_attr( $movement_pill ); ?>"><?php echo esc_html( $movement->status ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</section>
</div>
<?php return; endif; ?>

<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Operación', 'arriendo-facil' ),
			'title'    => __( 'Control de pagos', 'arriendo-facil' ),
			'subtitle' => __( 'Cánones, alícuotas y servicios por periodo. Registra pagos y controla la mora.', 'arriendo-facil' ),
		)
	);
	?>

	<form method="get" class="af-section" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding: var(--af-space-4) var(--af-space-5); margin-bottom: var(--af-space-4);">
		<input type="hidden" name="page" value="af-collections" />
		<label style="display:flex; align-items:center; gap:8px; font-weight:600;">
			<?php esc_html_e( 'Periodo', 'arriendo-facil' ); ?>
			<input type="month" name="period" value="<?php echo esc_attr( $period_filter ); ?>" style="min-height:36px;" />
		</label>
		<label style="display:flex; align-items:center; gap:8px; font-weight:600;">
			<?php esc_html_e( 'Estado', 'arriendo-facil' ); ?>
			<select name="charge_status" style="min-height:36px;">
				<option value=""><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
				<option value="pending" <?php selected( $status_filter, 'pending' ); ?>><?php esc_html_e( 'Pendiente', 'arriendo-facil' ); ?></option>
				<option value="partial" <?php selected( $status_filter, 'partial' ); ?>><?php esc_html_e( 'Parcial', 'arriendo-facil' ); ?></option>
				<option value="paid" <?php selected( $status_filter, 'paid' ); ?>><?php esc_html_e( 'Pagado', 'arriendo-facil' ); ?></option>
				<option value="overdue" <?php selected( $status_filter, 'overdue' ); ?>><?php esc_html_e( 'Vencido', 'arriendo-facil' ); ?></option>
			</select>
		</label>
		<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Filtrar', 'arriendo-facil' ); ?></button>
		<button type="button" id="af-generate-charges" class="button af-btn af-btn--ghost" data-period="<?php echo esc_attr( $period_filter ); ?>">
			<?php esc_html_e( 'Generar cargos del periodo', 'arriendo-facil' ); ?>
		</button>
	</form>

	<div class="af-kpi-grid">
		<article class="af-kpi">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Total facturado', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $total_charged, 2 ) ); ?></div>
			<div class="af-kpi__hint"><?php echo esc_html( $period_filter ); ?></div>
		</article>

		<article class="af-kpi af-kpi--success">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Cobrado', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $total_paid, 2 ) ); ?></div>
			<div class="af-kpi__hint"><?php echo esc_html( number_format_i18n( $collect_rate, 1 ) ); ?>% <?php esc_html_e( 'de cobranza', 'arriendo-facil' ); ?></div>
		</article>

		<article class="af-kpi <?php echo $balance > 0 ? 'af-kpi--attention' : ''; ?>">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Por cobrar', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Saldo del periodo', 'arriendo-facil' ); ?></div>
		</article>

		<article class="af-kpi <?php echo ! empty( $aging['90mas'] ) ? 'af-kpi--attention' : ''; ?>">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Mora', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( count( $aging['1_30'] ) + count( $aging['31_60'] ) + count( $aging['61_90'] ) + count( $aging['90mas'] ) ); ?></div>
			<div class="af-kpi__hint">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: 1-30 days, 2: 31-60 days, 3: 61-90 days, 4: 90+ days */
						__( '%1$d a 30d · %2$d a 60d · %3$d a 90d · %4$d +90d', 'arriendo-facil' ),
						count( $aging['1_30'] ),
						count( $aging['31_60'] ),
						count( $aging['61_90'] ),
						count( $aging['90mas'] )
					)
				);
				?>
			</div>
		</article>
	</div>

	<section class="af-section">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Cargos del periodo', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Vencidos primero. Máximo 200 registros.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

		<table class="wp-list-table widefat fixed striped af-data-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Propiedad', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Inquilino', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Concepto', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Monto', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Pagado', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Vence', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Acción', 'arriendo-facil' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No hay cargos para este periodo. Usa "Generar cargos del periodo".', 'arriendo-facil' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$status_pill = 'af-pill--neutral';
						if ( 'paid' === $row->status ) {
							$status_pill = 'af-pill--success';
						} elseif ( 'overdue' === $row->status ) {
							$status_pill = 'af-pill--danger';
						} elseif ( 'partial' === $row->status ) {
							$status_pill = 'af-pill--warning';
						}
						$outstanding = round( (float) $row->amount - (float) $row->amount_paid, 2 );
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Propiedad', 'arriendo-facil' ); ?>"><?php echo esc_html( $row->accommodation_title ? $row->accommodation_title : '—' ); ?></td>
							<td data-label="<?php esc_attr_e( 'Inquilino', 'arriendo-facil' ); ?>"><?php echo esc_html( trim( (string) $row->guest_name ) ? $row->guest_name : '—' ); ?></td>
							<td data-label="<?php esc_attr_e( 'Concepto', 'arriendo-facil' ); ?>"><?php echo esc_html( $charge_types[ $row->charge_type ] ?? $row->charge_type ); ?></td>
							<td data-label="<?php esc_attr_e( 'Monto', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $row->amount, 2 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Pagado', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $row->amount_paid, 2 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Vence', 'arriendo-facil' ); ?>"><?php echo esc_html( (string) $row->due_date ); ?></td>
							<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>"><span class="af-pill <?php echo esc_attr( $status_pill ); ?>"><?php echo esc_html( $row->status ); ?></span></td>
							<td data-label="<?php esc_attr_e( 'Acción', 'arriendo-facil' ); ?>">
								<?php if ( $outstanding > 0 ) : ?>
									<button type="button" class="button af-record-payment"
										data-charge="<?php echo esc_attr( (int) $row->id ); ?>"
										data-outstanding="<?php echo esc_attr( $outstanding ); ?>"
										data-concept="<?php echo esc_attr( $charge_types[ $row->charge_type ] ?? $row->charge_type ); ?>"
										data-tenant="<?php echo esc_attr( trim( (string) $row->guest_name ) ? $row->guest_name : __( 'Sin inquilino', 'arriendo-facil' ) ); ?>">
										<?php esc_html_e( 'Registrar pago', 'arriendo-facil' ); ?>
									</button>
								<?php endif; ?>
								<?php if ( ! empty( $row->lease_id ) ) : ?>
									<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections&statement_lease=' . (int) $row->lease_id ) ); ?>">
										<?php esc_html_e( 'Estado de cuenta', 'arriendo-facil' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</section>
</div>

<!-- Modal: registrar pago -->
<div class="af-modal" id="af-modal-payment" role="dialog" aria-modal="true" aria-labelledby="af-modal-payment-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-payment-title"><?php esc_html_e( 'Registrar pago', 'arriendo-facil' ); ?></h2>
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
				<input type="date" id="af-payment-date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
			</div>
			<div class="af-modal__field">
				<label for="af-payment-method"><?php esc_html_e( 'Método', 'arriendo-facil' ); ?></label>
				<select id="af-payment-method">
					<?php foreach ( $methods as $method_key => $method_label ) : ?>
						<option value="<?php echo esc_attr( $method_key ); ?>"><?php echo esc_html( $method_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="af-modal__field">
				<label for="af-payment-reference"><?php esc_html_e( 'Referencia / comprobante', 'arriendo-facil' ); ?></label>
				<input type="text" id="af-payment-reference" placeholder="<?php esc_attr_e( 'Ej: transferencia #01234', 'arriendo-facil' ); ?>" />
				<p class="af-modal__hint"><?php esc_html_e( 'Opcional, pero recomendado para auditoría.', 'arriendo-facil' ); ?></p>
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-payment-confirm"><?php esc_html_e( 'Registrar pago', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<!-- Modal: generar cargos del periodo -->
<div class="af-modal" id="af-modal-generate" role="dialog" aria-modal="true" aria-labelledby="af-modal-generate-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-generate-title"><?php esc_html_e( 'Generar cargos del periodo', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle"><?php esc_html_e( 'Se creará el canon de arriendo y la alícuota de cada contrato activo. Los cargos que ya existan se omiten automáticamente.', 'arriendo-facil' ); ?></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-generate-status"></p>
			<div class="af-modal__field">
				<label><?php esc_html_e( 'Periodo a generar', 'arriendo-facil' ); ?></label>
				<p style="margin:0;font-size:15px;font-weight:600;color:#1d2327;" id="af-generate-period"></p>
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-generate-confirm"><?php esc_html_e( 'Generar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_ledger_nonce' ) ); ?>;

	function post(payload) {
		const body = new URLSearchParams();
		Object.keys(payload).forEach((k) => body.append(k, payload[k]));
		return fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then((r) => r.json());
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
			payAmount.max = payMax;
			payOutstanding.textContent = <?php echo wp_json_encode( __( 'Saldo pendiente:', 'arriendo-facil' ) ); ?> + ' $' + payMax.toFixed(2);
			payReference.value = '';
			clearStatus(payStatus);
			openModal(payModal);
		});
	});

	payConfirm.addEventListener('click', function () {
		const amount = parseFloat(payAmount.value);

		if (!amount || amount <= 0) {
			setStatus(payStatus, <?php echo wp_json_encode( __( 'Ingresa un monto mayor a cero.', 'arriendo-facil' ) ); ?>, 'error');
			return;
		}

		if (amount > payMax) {
			setStatus(payStatus, <?php echo wp_json_encode( __( 'El monto no puede superar el saldo pendiente.', 'arriendo-facil' ) ); ?>, 'error');
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
				setStatus(payStatus, (json && json.data && json.data.message) || 'Error', 'error');
				return;
			}
			setStatus(payStatus, json.data.message, 'success');
			setTimeout(function () { window.location.reload(); }, 900);
		});
	});

	// ---- Generar cargos ----
	const genModal = document.getElementById('af-modal-generate');
	const genStatus = document.getElementById('af-generate-status');
	const genPeriod = document.getElementById('af-generate-period');
	const genConfirm = document.getElementById('af-generate-confirm');
	const genBtn = document.getElementById('af-generate-charges');

	if (genBtn) {
		genBtn.addEventListener('click', function () {
			genPeriod.textContent = genBtn.getAttribute('data-period');
			clearStatus(genStatus);
			openModal(genModal);
		});

		genConfirm.addEventListener('click', function () {
			genConfirm.disabled = true;
			clearStatus(genStatus);

			post({
				action: 'af_generate_period_charges',
				nonce: nonce,
				period: genBtn.getAttribute('data-period')
			}).then(function (json) {
				genConfirm.disabled = false;
				if (!json || !json.success) {
					setStatus(genStatus, (json && json.data && json.data.message) || 'Error', 'error');
					return;
				}
				setStatus(genStatus, json.data.message, 'success');
				setTimeout(function () { window.location.reload(); }, 1200);
			});
		});
	}
}());
</script>
