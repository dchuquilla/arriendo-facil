<?php
/**
 * Owner settlements admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$owners = Arriendo_Facil_Owner_Settlement::get_active_owners( Arriendo_Facil_Tenancy::accessible_accommodation_ids() );

$period_filter = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : gmdate( 'Y-m' );
if ( ! preg_match( '/^\d{4}-\d{2}$/', $period_filter ) ) {
	$period_filter = gmdate( 'Y-m' );
}

$selected_owner = isset( $_GET['owner_id'] ) ? absint( wp_unslash( $_GET['owner_id'] ) ) : 0;
if ( $selected_owner && ! in_array( $selected_owner, wp_list_pluck( $owners, 'id' ), true ) ) {
	$selected_owner = 0;
}
if ( ! $selected_owner && ! empty( $owners ) ) {
	$selected_owner = $owners[0]['id'];
}

$settlement = $selected_owner
	? Arriendo_Facil_Owner_Settlement::build( $selected_owner, $period_filter )
	: null;

$owner_user   = $selected_owner ? get_userdata( $selected_owner ) : null;
$charge_types = class_exists( 'Arriendo_Facil_Billing_Ledger' ) ? Arriendo_Facil_Billing_Ledger::charge_types() : array();
$transfer     = $selected_owner ? Arriendo_Facil_Owner_Settlement::get_transfer( $selected_owner, $period_filter ) : null;
$transfer_nonce = wp_create_nonce( 'af_owner_settlement_nonce' );
$can_transfer   = current_user_can( 'manage_options' );
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Propietarios', 'arriendo-facil' ),
			'title'    => __( 'Liquidación al propietario', 'arriendo-facil' ),
			'subtitle' => __( 'Lo cobrado a los inquilinos, los gastos del periodo y el neto que le corresponde al propietario.', 'arriendo-facil' ),
		)
	);
	?>

	<?php if ( empty( $owners ) ) : ?>
		<div class="af-section" style="padding: var(--af-space-5);">
			<p>
				<?php
				printf(
					/* translators: %s: link to the properties list */
					esc_html__( 'Aún no hay propietarios con inmuebles asignados. Asigna un propietario al editar un inmueble en %s.', 'arriendo-facil' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=accommodation' ) ) . '">' . esc_html__( 'Inmuebles', 'arriendo-facil' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php else : ?>

		<form method="get" class="af-section" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding: var(--af-space-4) var(--af-space-5); margin-bottom: var(--af-space-4);">
			<input type="hidden" name="page" value="af-owner-settlements" />
			<label style="display:flex; align-items:center; gap:8px; font-weight:600;">
				<?php esc_html_e( 'Propietario', 'arriendo-facil' ); ?>
				<select name="owner_id" style="min-height:36px;">
					<?php foreach ( $owners as $owner ) : ?>
						<option value="<?php echo esc_attr( $owner['id'] ); ?>" <?php selected( $selected_owner, $owner['id'] ); ?>>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: owner name, 2: property count */
									_n( '%1$s (%2$d inmueble)', '%1$s (%2$d inmuebles)', $owner['property_count'], 'arriendo-facil' ),
									$owner['name'],
									$owner['property_count']
								)
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label style="display:flex; align-items:center; gap:8px; font-weight:600;">
				<?php esc_html_e( 'Periodo', 'arriendo-facil' ); ?>
				<input type="month" name="period" value="<?php echo esc_attr( $period_filter ); ?>" style="min-height:36px;" />
			</label>
			<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Ver liquidación', 'arriendo-facil' ); ?></button>
			<button type="button" class="button af-btn af-btn--ghost" onclick="window.print()"><?php esc_html_e( 'Imprimir', 'arriendo-facil' ); ?></button>
		</form>

		<?php if ( $settlement ) : ?>
			<div class="af-kpi-grid">
				<article class="af-kpi af-kpi--success">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Cobrado', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $settlement['collected'], 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Recibido de los inquilinos', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi <?php echo $settlement['pending'] > 0 ? 'af-kpi--attention' : ''; ?>">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Por cobrar', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $settlement['pending'], 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Aún no ingresa', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Gastos', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $settlement['expenses'], 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Mantenimiento del periodo', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi af-kpi--accent">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Neto a liquidar', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $settlement['net'], 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Cobrado menos gastos', 'arriendo-facil' ); ?></div>
				</article>
			</div>

			<section class="af-section" style="padding: var(--af-space-5); margin-bottom: var(--af-space-4); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
				<div>
					<h2 class="af-section__title" style="margin:0 0 4px;"><?php esc_html_e( 'Dispersión de fondos', 'arriendo-facil' ); ?></h2>
					<?php if ( $transfer ) : ?>
						<p class="af-section__subtitle" style="margin:0;">
							<span class="af-pill af-pill--success"><?php esc_html_e( 'Transferido', 'arriendo-facil' ); ?></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: amount, 2: reference, 3: date */
									__( '$%1$s · ref. %2$s · %3$s', 'arriendo-facil' ),
									number_format_i18n( (float) $transfer->amount, 2 ),
									$transfer->reference ? $transfer->reference : '—',
									mysql2date( 'd/m/Y', $transfer->transferred_at )
								)
							);
							?>
						</p>
					<?php else : ?>
						<p class="af-section__subtitle" style="margin:0;"><span class="af-pill af-pill--warning"><?php esc_html_e( 'Pendiente de transferir', 'arriendo-facil' ); ?></span> <?php esc_html_e( 'Aún no se registra el envío del neto a este propietario.', 'arriendo-facil' ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $can_transfer ) : ?>
					<button type="button" class="button af-btn af-btn--primary" id="af-open-transfer"
						data-owner="<?php echo esc_attr( $selected_owner ); ?>"
						data-period="<?php echo esc_attr( $period_filter ); ?>"
						data-net="<?php echo esc_attr( $settlement['net'] ); ?>"
						data-amount="<?php echo esc_attr( $transfer ? $transfer->amount : $settlement['net'] ); ?>"
						data-reference="<?php echo esc_attr( $transfer ? $transfer->reference : '' ); ?>"
						data-notes="<?php echo esc_attr( $transfer ? $transfer->notes : '' ); ?>">
						<?php echo esc_html( $transfer ? __( 'Editar transferencia', 'arriendo-facil' ) : __( 'Marcar como transferido', 'arriendo-facil' ) ); ?>
					</button>
				<?php endif; ?>
			</section>

			<section class="af-section">
				<header class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Ingresos del periodo', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: owner name, 2: period */
									__( '%1$s · %2$s', 'arriendo-facil' ),
									$owner_user ? $owner_user->display_name : '',
									$period_filter
								)
							);
							?>
						</p>
					</div>
				</header>

				<table class="wp-list-table widefat fixed striped af-data-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Concepto', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Facturado', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Cobrado', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $settlement['charges'] ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No hay cargos en este periodo para los inmuebles de este propietario.', 'arriendo-facil' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $settlement['charges'] as $charge ) : ?>
								<?php
								$charge_pill = 'af-pill--neutral';
								if ( 'paid' === $charge->status ) {
									$charge_pill = 'af-pill--success';
								} elseif ( 'overdue' === $charge->status ) {
									$charge_pill = 'af-pill--danger';
								} elseif ( 'partial' === $charge->status ) {
									$charge_pill = 'af-pill--warning';
								}
								?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><?php echo esc_html( $charge->accommodation_title ? $charge->accommodation_title : '—' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Concepto', 'arriendo-facil' ); ?>"><?php echo esc_html( $charge_types[ $charge->charge_type ] ?? $charge->charge_type ); ?></td>
									<td data-label="<?php esc_attr_e( 'Facturado', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $charge->amount, 2 ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Cobrado', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $charge->amount_paid, 2 ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>"><span class="af-pill <?php echo esc_attr( $charge_pill ); ?>"><?php echo esc_html( $charge->status ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>

			<section class="af-section">
				<header class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Gastos del periodo', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Incidencias completadas que se descuentan de la liquidación.', 'arriendo-facil' ); ?></p>
					</div>
				</header>

				<table class="wp-list-table widefat fixed striped af-data-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Detalle', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Costo', 'arriendo-facil' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $settlement['expenses_detail'] ) ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'Sin gastos registrados en este periodo.', 'arriendo-facil' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $settlement['expenses_detail'] as $expense ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><?php echo esc_html( $expense->accommodation_title ? $expense->accommodation_title : '—' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Detalle', 'arriendo-facil' ); ?>"><?php echo esc_html( $expense->notes ? $expense->notes : '—' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Fecha', 'arriendo-facil' ); ?>"><?php echo esc_html( (string) $expense->completed_date ); ?></td>
									<td data-label="<?php esc_attr_e( 'Costo', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) ( $expense->cost ?? 0 ), 2 ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>
	<?php endif; ?>
</div>

<!-- Modal: marcar como transferido -->
<div class="af-modal" id="af-modal-transfer" role="dialog" aria-modal="true" aria-labelledby="af-modal-transfer-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-transfer-title"><?php esc_html_e( 'Registrar transferencia al propietario', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle"><?php esc_html_e( 'Deja constancia del monto y la referencia bancaria enviados.', 'arriendo-facil' ); ?></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-modal-transfer-status"></p>
			<div class="af-modal__field">
				<label for="af-transfer-amount"><?php esc_html_e( 'Monto transferido (USD)', 'arriendo-facil' ); ?></label>
				<input type="number" id="af-transfer-amount" min="0" step="0.01" />
			</div>
			<div class="af-modal__field">
				<label for="af-transfer-reference"><?php esc_html_e( 'Referencia bancaria', 'arriendo-facil' ); ?></label>
				<input type="text" id="af-transfer-reference" />
			</div>
			<div class="af-modal__field">
				<label for="af-transfer-notes"><?php esc_html_e( 'Notas', 'arriendo-facil' ); ?></label>
				<input type="text" id="af-transfer-notes" />
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-transfer-save"><?php esc_html_e( 'Guardar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<script>
(function () {
	const openBtn = document.getElementById('af-open-transfer');
	const modal = document.getElementById('af-modal-transfer');
	if (!openBtn || !modal) { return; }

	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( $transfer_nonce ); ?>;

	openBtn.addEventListener('click', function () {
		document.getElementById('af-transfer-amount').value = openBtn.dataset.amount || openBtn.dataset.net || 0;
		document.getElementById('af-transfer-reference').value = openBtn.dataset.reference || '';
		document.getElementById('af-transfer-notes').value = openBtn.dataset.notes || '';
		document.getElementById('af-modal-transfer-status').textContent = '';
		modal.classList.add('is-open');
		document.body.classList.add('af-modal-open');
	});

	modal.querySelectorAll('[data-af-modal-close]').forEach(function (el) {
		el.addEventListener('click', function () {
			modal.classList.remove('is-open');
			document.body.classList.remove('af-modal-open');
		});
	});

	document.getElementById('af-transfer-save').addEventListener('click', function () {
		const statusEl = document.getElementById('af-modal-transfer-status');
		const body = new URLSearchParams();
		body.append('action', 'af_record_owner_transfer');
		body.append('nonce', nonce);
		body.append('owner_id', openBtn.dataset.owner);
		body.append('period', openBtn.dataset.period);
		body.append('amount', document.getElementById('af-transfer-amount').value || 0);
		body.append('reference', document.getElementById('af-transfer-reference').value || '');
		body.append('notes', document.getElementById('af-transfer-notes').value || '');

		fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then((r) => r.json()).then(function (json) {
			if (!json || !json.success) {
				statusEl.textContent = (json && json.data && json.data.message) || 'Error';
				statusEl.className = 'af-modal__status is-error';
				return;
			}
			statusEl.textContent = json.data.message;
			statusEl.className = 'af-modal__status is-success';
			setTimeout(function () { window.location.reload(); }, 700);
		});
	});
}());
</script>
