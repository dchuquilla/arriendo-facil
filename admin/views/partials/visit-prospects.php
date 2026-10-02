<?php
/**
 * Visit history / prospects modal + post-visit result modal (Panel and Calendario).
 * Any element with [data-visit-outcome="<booking id>"] or [data-prospects-open] opens them.
 *
 * Expects (optional): $prospects from Arriendo_Facil_Calendar::prospects().
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$vp_prospects = isset( $prospects ) ? $prospects : Arriendo_Facil_Calendar::prospects( Arriendo_Facil_Tenancy::accessible_accommodation_ids() );
$vp_outcomes  = Arriendo_Facil_Calendar::visit_outcomes();
$vp_ratings   = Arriendo_Facil_Calendar::prospect_ratings();
?>
<?php // Hidden opener so "Agendar otra visita" works on pages without the header button. ?>
<button type="button" hidden data-cal-open="visit"></button>

<!-- Modal: historial de visitas / prospectos -->
<div class="af-modal" id="af-modal-prospects" role="dialog" aria-modal="true" aria-labelledby="af-modal-prospects-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog af-prospects__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-prospects-title"><?php esc_html_e( 'Historial de visitas y prospectos', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle"><?php esc_html_e( 'Cada persona agrupa todas sus visitas, aunque sean a inmuebles distintos. Califícalos A, B o C y regístralos como inquilinos cuando decidan.', 'arriendo-facil' ); ?></p>
		</div>
		<div class="af-modal__body">
			<div class="af-prospects__toolbar">
				<input type="search" id="af-prospects-search" placeholder="<?php esc_attr_e( 'Buscar por nombre, teléfono o inmueble…', 'arriendo-facil' ); ?>" />
				<div class="af-prospects__chips" role="group" aria-label="<?php esc_attr_e( 'Filtrar', 'arriendo-facil' ); ?>">
					<button type="button" class="af-chip is-active" data-prospect-filter="all"><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></button>
					<button type="button" class="af-chip" data-prospect-filter="pending"><?php esc_html_e( 'Sin resultado', 'arriendo-facil' ); ?></button>
					<button type="button" class="af-chip" data-prospect-filter="A">A</button>
					<button type="button" class="af-chip" data-prospect-filter="B">B</button>
					<button type="button" class="af-chip" data-prospect-filter="C">C</button>
					<button type="button" class="af-chip" data-prospect-filter="registered"><?php esc_html_e( 'Registrados', 'arriendo-facil' ); ?></button>
				</div>
			</div>
			<div class="af-prospects__list" id="af-prospects-list"></div>
		</div>
	</div>
</div>

<!-- Modal: resultado de una visita -->
<div class="af-modal" id="af-modal-visit-outcome" role="dialog" aria-modal="true" aria-labelledby="af-modal-outcome-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-outcome-title"><?php esc_html_e( '¿Cómo terminó la visita?', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-outcome-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-outcome-status"></p>

			<div class="af-outcome__choices" role="radiogroup">
				<button type="button" class="af-outcome__choice is-yes" data-outcome-choice="yes">
					<?php echo af_lucide( 'user-check', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
					<strong><?php esc_html_e( 'Sí, va a arrendar', 'arriendo-facil' ); ?></strong>
					<small><?php esc_html_e( 'Registrarlo como inquilino', 'arriendo-facil' ); ?></small>
				</button>
				<button type="button" class="af-outcome__choice is-no" data-outcome-choice="no">
					<?php echo af_lucide( 'users', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
					<strong><?php esc_html_e( 'No por ahora', 'arriendo-facil' ); ?></strong>
					<small><?php esc_html_e( 'Guardar como prospecto', 'arriendo-facil' ); ?></small>
				</button>
			</div>

			<div class="af-outcome__panel" data-outcome-panel="yes" hidden>
				<div class="af-outcome__grid">
					<div class="af-modal__field">
						<label for="af-reg-name"><?php esc_html_e( 'Nombre completo', 'arriendo-facil' ); ?></label>
						<input type="text" id="af-reg-name" autocomplete="off" />
					</div>
					<div class="af-modal__field">
						<label for="af-reg-id"><?php esc_html_e( 'Cédula o RUC', 'arriendo-facil' ); ?></label>
						<input type="text" id="af-reg-id" inputmode="numeric" maxlength="13" autocomplete="off" />
					</div>
					<div class="af-modal__field">
						<label for="af-reg-phone"><?php esc_html_e( 'Teléfono', 'arriendo-facil' ); ?></label>
						<input type="tel" id="af-reg-phone" inputmode="numeric" maxlength="10" />
					</div>
					<div class="af-modal__field">
						<label for="af-reg-email"><?php esc_html_e( 'Correo', 'arriendo-facil' ); ?></label>
						<input type="email" id="af-reg-email" />
					</div>
				</div>
				<p class="af-outcome__hint"><?php esc_html_e( 'Solo lo básico. Documentos y contrato se completan después desde su ficha.', 'arriendo-facil' ); ?></p>
			</div>

			<div class="af-outcome__panel" data-outcome-panel="no" hidden>
				<div class="af-modal__field">
					<span class="af-outcome__label"><?php esc_html_e( 'Motivo', 'arriendo-facil' ); ?></span>
					<div class="af-prospects__chips" role="radiogroup">
						<?php foreach ( array( 'thinking', 'not_closed', 'no_show' ) as $vp_key ) : ?>
							<button type="button" class="af-chip" data-outcome-reason="<?php echo esc_attr( $vp_key ); ?>"><?php echo esc_html( $vp_outcomes[ $vp_key ] ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="af-modal__field">
					<span class="af-outcome__label"><?php esc_html_e( 'Calificación del prospecto', 'arriendo-facil' ); ?></span>
					<div class="af-outcome__ratings" role="radiogroup">
						<?php foreach ( $vp_ratings as $vp_key => $vp_label ) : ?>
							<button type="button" class="af-outcome__rating af-rating--<?php echo esc_attr( strtolower( $vp_key ) ); ?>" data-outcome-rating="<?php echo esc_attr( $vp_key ); ?>">
								<b><?php echo esc_html( $vp_key ); ?></b><span><?php echo esc_html( $vp_label ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="af-modal__field">
					<label for="af-outcome-notes"><?php esc_html_e( 'Notas (qué le gustó, presupuesto, cuándo volver a contactar…)', 'arriendo-facil' ); ?></label>
					<textarea id="af-outcome-notes" rows="2"></textarea>
				</div>
			</div>

			<div class="af-outcome__done" hidden>
				<p id="af-outcome-done-msg"></p>
				<a class="button af-btn af-btn--primary" id="af-outcome-profile" href="#"><?php esc_html_e( 'Abrir ficha del inquilino', 'arriendo-facil' ); ?></a>
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cerrar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-outcome-save" hidden></button>
		</div>
	</div>
</div>

<script>
(function () {
	const ajaxUrl   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const calNonce  = <?php echo wp_json_encode( wp_create_nonce( Arriendo_Facil_Calendar::NONCE ) ); ?>;
	const prospects = <?php echo wp_json_encode( $vp_prospects, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
	const outLabels = <?php echo wp_json_encode( $vp_outcomes, JSON_HEX_TAG | JSON_HEX_AMP ); ?>;
	const rateLabels = <?php echo wp_json_encode( $vp_ratings, JSON_HEX_TAG | JSON_HEX_AMP ); ?>;
	const byVisit   = {};
	prospects.forEach(function (p) { p.visits.forEach(function (v) { byVisit[v.id] = { p: p, v: v }; }); });

	const prospectsModal = document.getElementById('af-modal-prospects');
	const outcomeModal   = document.getElementById('af-modal-visit-outcome');
	const list           = document.getElementById('af-prospects-list');
	const search         = document.getElementById('af-prospects-search');
	const saveBtn        = document.getElementById('af-outcome-save');
	const outStatus      = document.getElementById('af-outcome-status');
	let filter = 'all';
	let state  = {};

	function post(action, payload) {
		const body = new URLSearchParams();
		Object.keys(payload).forEach(function (k) { body.append(k, payload[k]); });
		body.append('action', action);
		body.append('nonce', calNonce);
		return fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then(function (r) { return r.json(); }).catch(function () {
			return { success: false, data: { message: 'Error de conexión.' } };
		});
	}
	function openModal(modal) {
		modal.classList.add('is-open');
		document.body.classList.add('af-modal-open');
	}
	function closeModal(modal) {
		modal.classList.remove('is-open');
		if (!document.querySelector('.af-modal.is-open')) {
			document.body.classList.remove('af-modal-open');
		}
		if (modal === outcomeModal && modal.dataset.reload) { window.location.reload(); }
	}
	[prospectsModal, outcomeModal].forEach(function (modal) {
		modal.querySelectorAll('[data-af-modal-close]').forEach(function (btn) {
			btn.addEventListener('click', function () { closeModal(modal); });
		});
	});
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		closeModal(outcomeModal.classList.contains('is-open') ? outcomeModal : prospectsModal);
	});
	function setStatus(message, isError) {
		outStatus.textContent = message;
		outStatus.className = 'af-modal__status ' + (isError ? 'is-error' : 'is-success');
	}

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function fmtDate(d) { return d ? d.split('-').reverse().join('/') : ''; }
	function waLink(phone) {
		let n = String(phone || '').replace(/\D+/g, '');
		if (n.length === 10 && n.charAt(0) === '0') { n = '593' + n.slice(1); }
		return n.length >= 9 ? 'https://wa.me/' + n : '';
	}
	function outPill(out) {
		const tone = { registered: 'success', thinking: 'warning', not_closed: 'neutral', no_show: 'danger' }[out] || 'info';
		return '<span class="af-pill af-pill--' + tone + '">' + esc(outLabels[out] || out) + '</span>';
	}

	function card(p, idx) {
		const last  = p.visits[0];
		const wa    = waLink(p.phone);
		const props = p.visits.map(function (v) { return v.accommodation; }).filter(function (a, i, all) { return a && all.indexOf(a) === i; });
		const badge = p.status === 'registered'
			? outPill('registered')
			: (p.pending ? '<span class="af-pill af-pill--warning">' + esc(p.pending + ' sin resultado') + '</span>' : outPill(p.status));
		const history = p.visits.map(function (v) {
			return '<li><span class="af-prospect__when">' + esc(fmtDate(v.date) + ' ' + v.time) + (v.upcoming ? ' · próxima' : '') + '</span>' +
				'<span class="af-prospect__acc">' + esc(v.accommodation || '—') + (v.notes ? '<small>' + esc(v.notes) + '</small>' : '') + '</span>' +
				outPill(v.outcome) +
				(v.outcome === 'registered' ? '' : '<button type="button" class="af-cal__link" data-visit-outcome="' + v.id + '">Resultado</button>') + '</li>';
		}).join('');
		const actions = p.status === 'registered'
			? '<a class="button af-btn af-btn--ghost af-btn--sm" href="' + esc(p.profile_url) + '">Ver ficha</a>'
			: '<button type="button" class="button af-btn af-btn--primary af-btn--sm" data-visit-outcome="' + last.id + '" data-mode="yes">Registrar</button>' +
			  '<button type="button" class="button af-btn af-btn--ghost af-btn--sm" data-visit-outcome="' + last.id + '" data-mode="no">Calificar</button>';

		return '<article class="af-prospect">' +
			'<div class="af-prospect__head">' +
				'<span class="af-rating af-rating--lg af-rating--' + (p.rating ? p.rating.toLowerCase() : 'none') + '" title="' + esc(rateLabels[p.rating] || 'Sin calificar') + '">' + esc(p.rating || '–') + '</span>' +
				'<div class="af-prospect__id"><strong>' + esc(p.name || 'Visitante') + '</strong>' +
					'<small>' + esc(p.visits.length + (p.visits.length === 1 ? ' visita' : ' visitas') + ' · ' + props.length + (props.length === 1 ? ' inmueble' : ' inmuebles') + ' · última ' + fmtDate(last.date)) + '</small>' +
					'<small class="af-prospect__contact">' +
						(p.phone ? (wa ? '<a href="' + esc(wa) + '" target="_blank" rel="noopener">WhatsApp ' + esc(p.phone) + '</a>' : esc(p.phone)) : '') +
						(p.email ? ' · <a href="mailto:' + esc(p.email) + '">' + esc(p.email) + '</a>' : '') +
					'</small></div>' + badge +
			'</div>' +
			'<details class="af-prospect__history"><summary>Historial de visitas</summary><ol>' + history + '</ol></details>' +
			'<div class="af-prospect__actions">' + actions +
				'<button type="button" class="button af-btn af-btn--ghost af-btn--sm" data-prospect-revisit="' + idx + '">Agendar otra visita</button></div>' +
		'</article>';
	}

	function renderProspects() {
		const q = search.value.trim().toLowerCase();
		const html = [];
		prospects.forEach(function (p, idx) {
			if (filter === 'pending' && !p.pending) { return; }
			if (filter === 'registered' && p.status !== 'registered') { return; }
			if (/^[ABC]$/.test(filter) && p.rating !== filter) { return; }
			if (q && [p.name, p.phone, p.email].concat(p.visits.map(function (v) { return v.accommodation; })).join(' ').toLowerCase().indexOf(q) === -1) { return; }
			html.push(card(p, idx));
		});
		list.innerHTML = html.length ? html.join('') : '<p class="af-empty__text">' + (prospects.length ? 'Ningún prospecto coincide con el filtro.' : 'Aún no hay visitas registradas.') + '</p>';
	}

	search.addEventListener('input', renderProspects);
	prospectsModal.querySelectorAll('[data-prospect-filter]').forEach(function (chip) {
		chip.addEventListener('click', function () {
			filter = chip.dataset.prospectFilter;
			prospectsModal.querySelectorAll('[data-prospect-filter]').forEach(function (c) { c.classList.toggle('is-active', c === chip); });
			renderProspects();
		});
	});
	document.querySelectorAll('[data-prospects-open]').forEach(function (btn) {
		btn.addEventListener('click', function () { renderProspects(); openModal(prospectsModal); });
	});

	// "Agendar otra visita": reabre el formulario del calendario con sus datos.
	list.addEventListener('click', function (e) {
		const btn = e.target.closest('[data-prospect-revisit]');
		if (!btn) { return; }
		const p = prospects[btn.dataset.prospectRevisit];
		const opener = document.querySelector('[data-cal-open="visit"]');
		if (!p || !opener) { return; }
		closeModal(prospectsModal);
		opener.click();
		[['af-cal-guest-name', p.name], ['af-cal-guest-phone', p.phone], ['af-cal-guest-email', p.email]].forEach(function (f) {
			const input = document.getElementById(f[0]);
			if (input) { input.value = f[1] || ''; }
		});
	});

	// ---- Resultado de la visita ----
	function paintOutcome() {
		outcomeModal.querySelectorAll('[data-outcome-choice]').forEach(function (b) { b.classList.toggle('is-selected', b.dataset.outcomeChoice === state.choice); });
		outcomeModal.querySelectorAll('[data-outcome-panel]').forEach(function (p) { p.hidden = p.dataset.outcomePanel !== state.choice; });
		outcomeModal.querySelectorAll('[data-outcome-reason]').forEach(function (b) { b.classList.toggle('is-active', b.dataset.outcomeReason === state.reason); });
		outcomeModal.querySelectorAll('[data-outcome-rating]').forEach(function (b) { b.classList.toggle('is-selected', b.dataset.outcomeRating === state.rating); });
		saveBtn.hidden = !state.choice;
		saveBtn.textContent = state.choice === 'yes' ? 'Registrar inquilino' : 'Guardar en historial';
	}

	function showDone(message, url) {
		outcomeModal.querySelector('.af-outcome__choices').hidden = true;
		state.choice = null;
		paintOutcome();
		document.getElementById('af-outcome-done-msg').textContent = message;
		document.getElementById('af-outcome-profile').href = url;
		outcomeModal.querySelector('.af-outcome__done').hidden = false;
	}

	function openOutcome(id, mode, fallback) {
		const hit = byVisit[id];
		const p = hit ? hit.p : { name: fallback.name || '', phone: '', email: '', rating: '' };
		const v = hit ? hit.v : { accommodation: fallback.accommodation || '', date: '', time: '', outcome: 'pending', notes: '' };

		state = { id: id, choice: mode || null, reason: ['thinking', 'not_closed', 'no_show'].indexOf(v.outcome) !== -1 ? v.outcome : null, rating: p.rating || '' };
		document.getElementById('af-outcome-subtitle').textContent = [p.name, v.accommodation, fmtDate(v.date) + (v.time ? ' ' + v.time : '')].filter(function (s) { return s && s.trim(); }).join(' · ');
		document.getElementById('af-reg-name').value  = p.name || '';
		document.getElementById('af-reg-phone').value = String(p.phone || '').replace(/\D+/g, '');
		document.getElementById('af-reg-email').value = p.email || '';
		document.getElementById('af-reg-id').value    = '';
		document.getElementById('af-outcome-notes').value = v.notes || '';
		outStatus.textContent = '';
		outStatus.className = 'af-modal__status';
		outcomeModal.querySelector('.af-outcome__choices').hidden = false;
		outcomeModal.querySelector('.af-outcome__done').hidden = true;
		paintOutcome();
		if (v.outcome === 'registered' && p.profile_url) {
			showDone('Esta visita ya terminó en registro de inquilino.', p.profile_url);
		}
		openModal(outcomeModal);
	}

	document.addEventListener('click', function (e) {
		const btn = e.target.closest('[data-visit-outcome]');
		if (!btn) { return; }
		e.preventDefault();
		openOutcome(parseInt(btn.dataset.visitOutcome, 10), btn.dataset.mode, { name: btn.dataset.name, accommodation: btn.dataset.accommodation });
	});
	outcomeModal.querySelectorAll('[data-outcome-choice]').forEach(function (b) {
		b.addEventListener('click', function () { state.choice = b.dataset.outcomeChoice; outStatus.textContent = ''; paintOutcome(); });
	});
	outcomeModal.querySelectorAll('[data-outcome-reason]').forEach(function (b) {
		b.addEventListener('click', function () { state.reason = b.dataset.outcomeReason; paintOutcome(); });
	});
	outcomeModal.querySelectorAll('[data-outcome-rating]').forEach(function (b) {
		b.addEventListener('click', function () { state.rating = state.rating === b.dataset.outcomeRating ? '' : b.dataset.outcomeRating; paintOutcome(); });
	});

	saveBtn.addEventListener('click', function () {
		let action, payload;
		if (state.choice === 'yes') {
			const idNumber = document.getElementById('af-reg-id').value.replace(/\D+/g, '');
			if (idNumber.length !== 10 && idNumber.length !== 13) {
				setStatus('Ingresa una cédula (10 dígitos) o RUC (13 dígitos).', true);
				return;
			}
			action  = 'af_calendar_register_prospect';
			payload = {
				id: state.id,
				name: document.getElementById('af-reg-name').value,
				id_number: idNumber,
				phone: document.getElementById('af-reg-phone').value,
				email: document.getElementById('af-reg-email').value,
				rating: 'A'
			};
		} else {
			if (!state.reason) {
				setStatus('Elige el motivo.', true);
				return;
			}
			action  = 'af_calendar_visit_outcome';
			payload = { id: state.id, outcome: state.reason, rating: state.rating, notes: document.getElementById('af-outcome-notes').value };
		}
		saveBtn.disabled = true;
		post(action, payload).then(function (res) {
			saveBtn.disabled = false;
			if (!res || !res.success) {
				setStatus((res && res.data && res.data.message) || 'No se pudo completar la operación.', true);
				return;
			}
			if (action === 'af_calendar_visit_outcome') { window.location.reload(); return; }
			outcomeModal.dataset.reload = '1';
			setStatus('', false);
			showDone(res.data.message, res.data.profile_url);
		});
	});
})();
</script>
