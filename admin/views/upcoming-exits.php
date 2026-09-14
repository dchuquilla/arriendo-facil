<?php
/**
 * Upcoming exits operational page.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$today  = gmdate( 'Y-m-d' );
$limit  = gmdate( 'Y-m-d', strtotime( '+90 days' ) );

$scope_ids    = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
$scope_clause = null === $scope_ids ? '' : ' AND l.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';

$leases = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT l.*, p.post_title AS accommodation_title, CONCAT(g.first_name, ' ', g.last_name) AS guest_name
		 FROM {$wpdb->prefix}af_leases l
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
		 WHERE l.status = 'active' AND l.deleted_at IS NULL AND l.end_date BETWEEN %s AND %s{$scope_clause}
		 ORDER BY l.end_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$today,
		$limit
	)
);

$buckets = array( '30' => 0, '60' => 0, '90' => 0 );
foreach ( $leases as $lease ) {
	$days = max( 0, (int) floor( ( strtotime( $lease->end_date ) - strtotime( $today ) ) / DAY_IN_SECONDS ) );
	if ( $days <= 30 ) {
		$buckets['30']++;
	} elseif ( $days <= 60 ) {
		$buckets['60']++;
	} else {
		$buckets['90']++;
	}
}

// ── Arma las tarjetas con todo lo que necesita la vista ───────────────────
$exit_cards = array();
foreach ( $leases as $lease ) {
	$days     = max( 0, (int) floor( ( strtotime( $lease->end_date ) - strtotime( $today ) ) / DAY_IN_SECONDS ) );
	$summary  = Arriendo_Facil_Lease_Operations::deposit_summary( $lease );
	$estimate = Arriendo_Facil_Accommodation::estimate_monthly_rent(
		(string) get_post_meta( $lease->accommodation_id, '_af_city', true ),
		(string) get_post_meta( $lease->accommodation_id, '_af_property_type', true ),
		(int) get_post_meta( $lease->accommodation_id, '_af_bedrooms', true ),
		(int) $lease->accommodation_id
	);

	$exit_cards[] = array(
		'lease'    => $lease,
		'days'     => $days,
		'summary'  => $summary,
		'estimate' => $estimate,
	);
}

$legal_statuses = Arriendo_Facil_Lease_Operations::legal_statuses();
$nonce          = wp_create_nonce( 'af_lease_operations_nonce' );
?>
<div class="wrap af-shell">
	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Operación preventiva', 'arriendo-facil' ),
			'title'    => __( 'Próximas salidas y garantías', 'arriendo-facil' ),
			'subtitle' => __( 'Anticipa vencimientos, prepara renovaciones y liquida garantías con saldos reales.', 'arriendo-facil' ),
		)
	);
	?>

	<div class="af-kpi-grid" role="list">
		<article class="af-kpi af-kpi--attention" role="listitem">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'En los próximos 30 días', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( $buckets['30'] ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Prioridad de decisión inmediata', 'arriendo-facil' ); ?></div>
		</article>
		<article class="af-kpi" role="listitem">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'De 31 a 60 días', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( $buckets['60'] ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Iniciar conversación de renovación', 'arriendo-facil' ); ?></div>
		</article>
		<article class="af-kpi af-kpi--info" role="listitem">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'De 61 a 90 días', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( $buckets['90'] ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Planificación anticipada', 'arriendo-facil' ); ?></div>
		</article>
	</div>

	<section class="af-section" aria-labelledby="af-exits-title">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title" id="af-exits-title"><?php esc_html_e( 'Contratos por vencer', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Las deducciones de garantía requieren revisión y autorización humana antes de marcarse como pagadas.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

		<?php if ( empty( $exit_cards ) ) : ?>
			<div class="af-empty" style="padding: var(--af-space-6) var(--af-space-4);">
				<span class="af-empty__icon" aria-hidden="true">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12l4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</span>
				<h3 class="af-empty__title"><?php esc_html_e( 'Sin vencimientos próximos', 'arriendo-facil' ); ?></h3>
				<p class="af-empty__text"><?php esc_html_e( 'Ningún contrato activo vence en los próximos 90 días.', 'arriendo-facil' ); ?></p>
			</div>
		<?php else : ?>
			<div class="af-exit-grid">
				<?php foreach ( $exit_cards as $card ) : ?>
					<?php
					$lease    = $card['lease'];
					$days     = $card['days'];
					$summary  = $card['summary'];
					$estimate = $card['estimate'];
					$urgency  = $days <= 30 ? 'danger' : ( $days <= 60 ? 'warning' : 'neutral' );
					$legal    = $lease->legal_status ?? 'pendiente';
					$legal_tier = 'notarizado' === $legal ? 'success' : ( 'en_notarizacion' === $legal ? 'warning' : 'neutral' );
					?>
					<article class="af-exit-card">
						<header class="af-exit-card__head">
							<span class="af-pill af-pill--<?php echo esc_attr( $urgency ); ?> af-pill--lg">
								<?php echo esc_html( sprintf( /* translators: %d: days remaining */ _n( 'Vence en %d día', 'Vence en %d días', $days, 'arriendo-facil' ), $days ) ); ?>
							</span>
							<span class="af-exit-card__date"><?php echo esc_html( $lease->end_date ); ?></span>
						</header>

						<h3 class="af-exit-card__title"><?php echo esc_html( $lease->accommodation_title ? $lease->accommodation_title : '#' . $lease->accommodation_id ); ?></h3>
						<p class="af-exit-card__tenant"><?php echo esc_html( trim( (string) $lease->guest_name ) ? trim( (string) $lease->guest_name ) : __( 'Sin inquilino', 'arriendo-facil' ) ); ?></p>

						<div class="af-exit-card__row">
							<span class="af-exit-card__label"><?php esc_html_e( 'Precio de renovación sugerido', 'arriendo-facil' ); ?></span>
							<span class="af-exit-card__value">
								<?php if ( $estimate['sample_size'] > 0 ) : ?>
									$<?php echo esc_html( number_format_i18n( $estimate['average'], 2 ) ); ?>
									<small><?php echo esc_html( sprintf( /* translators: %d: comparable count */ __( '(%d comparables)', 'arriendo-facil' ), $estimate['sample_size'] ) ); ?></small>
								<?php else : ?>
									<small><?php esc_html_e( 'Sin comparables internos', 'arriendo-facil' ); ?></small>
								<?php endif; ?>
							</span>
						</div>

						<div class="af-exit-card__row">
							<span class="af-exit-card__label"><?php esc_html_e( 'Devolución de garantía estimada', 'arriendo-facil' ); ?></span>
							<span class="af-exit-card__value">$<?php echo esc_html( number_format_i18n( $summary['refund'], 2 ) ); ?></span>
						</div>

						<footer class="af-exit-card__footer">
							<span class="af-pill af-pill--<?php echo esc_attr( $legal_tier ); ?>"><?php echo esc_html( $legal_statuses[ $legal ] ?? $legal ); ?></span>

							<div class="af-exit-card__actions">
								<button type="button" class="button af-btn af-btn--ghost af-btn--sm af-exit-open-renew"
									data-lease="<?php echo esc_attr( $lease->id ); ?>"
									data-title="<?php echo esc_attr( $lease->accommodation_title ); ?>"
									data-min-date="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( $lease->end_date . ' +1 day' ) ) ); ?>">
									<?php esc_html_e( 'Renovar', 'arriendo-facil' ); ?>
								</button>
								<button type="button" class="button af-btn af-btn--ghost af-btn--sm af-exit-open-legal"
									data-lease="<?php echo esc_attr( $lease->id ); ?>"
									data-title="<?php echo esc_attr( $lease->accommodation_title ); ?>"
									data-status="<?php echo esc_attr( $legal ); ?>"
									data-notes="<?php echo esc_attr( $lease->legal_notes ?? '' ); ?>">
									<?php esc_html_e( 'Estado legal', 'arriendo-facil' ); ?>
								</button>
								<button type="button" class="button af-btn af-btn--primary af-btn--sm af-exit-open-deposit"
									data-lease="<?php echo esc_attr( $lease->id ); ?>"
									data-title="<?php echo esc_attr( $lease->accommodation_title ); ?>"
									data-deposit="<?php echo esc_attr( $summary['deposit'] ); ?>"
									data-balance="<?php echo esc_attr( $summary['balance'] ); ?>"
									data-damages="<?php echo esc_attr( $summary['damages'] ); ?>"
									data-services="<?php echo esc_attr( $summary['services'] ); ?>"
									data-status="<?php echo esc_attr( $lease->deposit_settlement_status ?? 'pendiente' ); ?>"
									data-notes="<?php echo esc_attr( $lease->deposit_settlement_notes ?? '' ); ?>">
									<?php esc_html_e( 'Liquidar garantía', 'arriendo-facil' ); ?>
								</button>
							</div>
						</footer>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
</div>

<!-- Modal: renovar contrato -->
<div class="af-modal" id="af-modal-renew" role="dialog" aria-modal="true" aria-labelledby="af-modal-renew-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-renew-title"><?php esc_html_e( 'Renovar contrato', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-modal-renew-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-modal-renew-status"></p>
			<div class="af-modal__field">
				<label for="af-renew-end-date"><?php esc_html_e( 'Nueva fecha de fin', 'arriendo-facil' ); ?></label>
				<input type="date" id="af-renew-end-date" required />
			</div>
			<div class="af-modal__field">
				<label for="af-renew-reason"><?php esc_html_e( 'Motivo de la renovación', 'arriendo-facil' ); ?></label>
				<textarea id="af-renew-reason" rows="3" required></textarea>
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-renew-save"><?php esc_html_e( 'Renovar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<!-- Modal: estado legal -->
<div class="af-modal" id="af-modal-legal" role="dialog" aria-modal="true" aria-labelledby="af-modal-legal-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-legal-title"><?php esc_html_e( 'Estado legal del contrato', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-modal-legal-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-modal-legal-status-msg"></p>
			<div class="af-modal__field">
				<label for="af-legal-select"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></label>
				<select id="af-legal-select">
					<?php foreach ( $legal_statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="af-modal__field">
				<label for="af-legal-notes"><?php esc_html_e( 'Referencia o nota (notaría, número de trámite, etc.)', 'arriendo-facil' ); ?></label>
				<input type="text" id="af-legal-notes" />
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-legal-save"><?php esc_html_e( 'Guardar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<!-- Modal: liquidar garantía -->
<div class="af-modal" id="af-modal-deposit" role="dialog" aria-modal="true" aria-labelledby="af-modal-deposit-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-deposit-title"><?php esc_html_e( 'Liquidación de garantía', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-modal-deposit-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-modal-deposit-status-msg"></p>

			<div class="af-deposit-calc">
				<div class="af-deposit-calc__row"><span><?php esc_html_e( 'Depósito pactado', 'arriendo-facil' ); ?></span><strong id="af-deposit-amount">$0.00</strong></div>
				<div class="af-deposit-calc__row"><span><?php esc_html_e( 'Saldo pendiente del inquilino', 'arriendo-facil' ); ?></span><strong id="af-deposit-balance">$0.00</strong></div>
				<div class="af-modal__field">
					<label for="af-deposit-damages"><?php esc_html_e( 'Deducción por daños', 'arriendo-facil' ); ?></label>
					<input type="number" id="af-deposit-damages" min="0" step="0.01" />
				</div>
				<div class="af-modal__field">
					<label for="af-deposit-services"><?php esc_html_e( 'Deducción por servicios pendientes', 'arriendo-facil' ); ?></label>
					<input type="number" id="af-deposit-services" min="0" step="0.01" />
				</div>
				<div class="af-deposit-calc__row af-deposit-calc__row--total"><span><?php esc_html_e( 'A devolver al inquilino', 'arriendo-facil' ); ?></span><strong id="af-deposit-refund">$0.00</strong></div>
			</div>

			<div class="af-modal__field">
				<label for="af-deposit-status"><?php esc_html_e( 'Estado de la liquidación', 'arriendo-facil' ); ?></label>
				<select id="af-deposit-status">
					<option value="pendiente"><?php esc_html_e( 'Pendiente', 'arriendo-facil' ); ?></option>
					<option value="aprobada"><?php esc_html_e( 'Aprobada', 'arriendo-facil' ); ?></option>
					<option value="pagada"><?php esc_html_e( 'Pagada', 'arriendo-facil' ); ?></option>
				</select>
			</div>
			<div class="af-modal__field">
				<label for="af-deposit-notes"><?php esc_html_e( 'Notas', 'arriendo-facil' ); ?></label>
				<input type="text" id="af-deposit-notes" />
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-deposit-save"><?php esc_html_e( 'Calcular y guardar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<script>
(function () {
	const ajaxUrl     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const leaseNonce  = <?php echo wp_json_encode( wp_create_nonce( 'af_lease_nonce' ) ); ?>;
	const opsNonce    = <?php echo wp_json_encode( $nonce ); ?>;

	function post(action, nonce, payload) {
		const body = new URLSearchParams();
		Object.keys(payload).forEach((k) => body.append(k, payload[k]));
		body.append('action', action);
		body.append('nonce', nonce);
		return fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then((r) => r.json());
	}

	function openModal(modal) {
		modal.classList.add('is-open');
		document.body.classList.add('af-modal-open');
		const focusable = modal.querySelector('input, select, textarea, button.button-primary');
		if (focusable) { focusable.focus(); }
	}

	function closeModal(modal) {
		modal.classList.remove('is-open');
		document.body.classList.remove('af-modal-open');
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

	function setStatus(el, message, isError) {
		el.textContent = message;
		el.className = 'af-modal__status ' + (isError ? 'is-error' : 'is-success');
	}

	// ---- Renovar ----
	const renewModal = document.getElementById('af-modal-renew');
	document.querySelectorAll('.af-exit-open-renew').forEach(function (btn) {
		btn.addEventListener('click', function () {
			document.getElementById('af-modal-renew-subtitle').textContent = btn.dataset.title || '';
			document.getElementById('af-renew-end-date').min = btn.dataset.minDate || '';
			document.getElementById('af-renew-end-date').value = btn.dataset.minDate || '';
			document.getElementById('af-renew-reason').value = '';
			document.getElementById('af-modal-renew-status').textContent = '';
			renewModal.dataset.lease = btn.dataset.lease;
			openModal(renewModal);
		});
	});
	document.getElementById('af-renew-save').addEventListener('click', function () {
		const status = document.getElementById('af-modal-renew-status');
		const reason = document.getElementById('af-renew-reason').value.trim();
		const endDate = document.getElementById('af-renew-end-date').value;
		if (!reason || !endDate) { setStatus(status, '<?php echo esc_js( __( 'Completa la fecha y el motivo.', 'arriendo-facil' ) ); ?>', true); return; }
		post('af_renew_lease', leaseNonce, { lease_id: renewModal.dataset.lease, new_end_date: endDate, reason: reason }).then((res) => {
			if (res && res.success) { window.location.reload(); }
			else { setStatus(status, (res && res.data && res.data.message) || 'Error', true); }
		});
	});

	// ---- Estado legal ----
	const legalModal = document.getElementById('af-modal-legal');
	document.querySelectorAll('.af-exit-open-legal').forEach(function (btn) {
		btn.addEventListener('click', function () {
			document.getElementById('af-modal-legal-subtitle').textContent = btn.dataset.title || '';
			document.getElementById('af-legal-select').value = btn.dataset.status || 'pendiente';
			document.getElementById('af-legal-notes').value = btn.dataset.notes || '';
			document.getElementById('af-modal-legal-status-msg').textContent = '';
			legalModal.dataset.lease = btn.dataset.lease;
			openModal(legalModal);
		});
	});
	document.getElementById('af-legal-save').addEventListener('click', function () {
		const status = document.getElementById('af-modal-legal-status-msg');
		post('af_update_lease_legal_status', opsNonce, {
			lease_id: legalModal.dataset.lease,
			legal_status: document.getElementById('af-legal-select').value,
			legal_notes: document.getElementById('af-legal-notes').value
		}).then((res) => {
			if (res && res.success) { window.location.reload(); }
			else { setStatus(status, (res && res.data && res.data.message) || 'Error', true); }
		});
	});

	// ---- Liquidar garantía ----
	const depositModal = document.getElementById('af-modal-deposit');
	const depositState = {};

	function recalcDeposit() {
		const damages = parseFloat(document.getElementById('af-deposit-damages').value) || 0;
		const services = parseFloat(document.getElementById('af-deposit-services').value) || 0;
		const refund = Math.max(0, depositState.deposit - depositState.balance - damages - services);
		document.getElementById('af-deposit-refund').textContent = '$' + refund.toFixed(2);
	}

	document.querySelectorAll('.af-exit-open-deposit').forEach(function (btn) {
		btn.addEventListener('click', function () {
			depositState.deposit = parseFloat(btn.dataset.deposit) || 0;
			depositState.balance = parseFloat(btn.dataset.balance) || 0;
			document.getElementById('af-modal-deposit-subtitle').textContent = btn.dataset.title || '';
			document.getElementById('af-deposit-amount').textContent = '$' + depositState.deposit.toFixed(2);
			document.getElementById('af-deposit-balance').textContent = '$' + depositState.balance.toFixed(2);
			document.getElementById('af-deposit-damages').value = btn.dataset.damages || 0;
			document.getElementById('af-deposit-services').value = btn.dataset.services || 0;
			document.getElementById('af-deposit-status').value = btn.dataset.status || 'pendiente';
			document.getElementById('af-deposit-notes').value = btn.dataset.notes || '';
			document.getElementById('af-modal-deposit-status-msg').textContent = '';
			depositModal.dataset.lease = btn.dataset.lease;
			recalcDeposit();
			openModal(depositModal);
		});
	});

	document.getElementById('af-deposit-damages').addEventListener('input', recalcDeposit);
	document.getElementById('af-deposit-services').addEventListener('input', recalcDeposit);

	document.getElementById('af-deposit-save').addEventListener('click', function () {
		const status = document.getElementById('af-modal-deposit-status-msg');
		post('af_save_deposit_settlement', opsNonce, {
			lease_id: depositModal.dataset.lease,
			damages: document.getElementById('af-deposit-damages').value || 0,
			services: document.getElementById('af-deposit-services').value || 0,
			status: document.getElementById('af-deposit-status').value,
			notes: document.getElementById('af-deposit-notes').value
		}).then((res) => {
			if (res && res.success) { window.location.reload(); }
			else { setStatus(status, (res && res.data && res.data.message) || 'Error', true); }
		});
	});
})();
</script>
