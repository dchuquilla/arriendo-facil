/* Cobranza de Edificios y unidades: modal con calendario de cobros del mes
   por inmueble y anotación de pagos (af_record_payment). No dependencias. */
(function () {
	'use strict';

	var DATA = window.afBuildingsCobranza || {};
	var TODAY = window.afBuildingsCobranzaToday || '';
	var CONFIG = window.afCobranza || {};

	if (!Object.keys(DATA).length) {
		return;
	}

	/* ── Helpers ─────────────────────────────────────────────────────────── */

	function pad(n) {
		return ('0' + n).slice(-2);
	}

	function periodString(d) {
		return d.getFullYear() + '-' + pad(d.getMonth() + 1);
	}

	function parsePeriod(p) {
		var parts = String(p).split('-');
		return { y: parseInt(parts[0], 10), m: parseInt(parts[1], 10) };
	}

	function periodDate(p) {
		var x = parsePeriod(p);
		return new Date(x.y, x.m - 1, 1);
	}

	function addMonths(p, delta) {
		var d = periodDate(p);
		d.setMonth(d.getMonth() + delta);
		return periodString(d);
	}

	var MONTH_FMT = new Intl.DateTimeFormat('es-ES', { month: 'long', year: 'numeric' });
	function monthLabel(p) {
		var s = MONTH_FMT.format(periodDate(p));
		return s.charAt(0).toUpperCase() + s.slice(1);
	}

	function monthMoney(n) {
		return Number(n || 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function dateLabel(ymd) {
		if (!ymd) { return ''; }
		var parts = String(ymd).split('-');
		var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
		return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' });
	}

	/* ── Icons (estilo lucide) ───────────────────────────────────────────── */

	var ICONS = {
		canon: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>',
		alicuota: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17V7"/></svg>',
		agua: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>',
		luz: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>',
		gas: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
		internet: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><path d="M12 20h.01"/></svg>',
		multa: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
		otro: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12h8M12 8v8"/></svg>'
	};

	function chargeIcon(type, paid) {
		var svg = ICONS[type] || ICONS.otro;
		return svg;
	}

	/* ── Estado DOM ──────────────────────────────────────────────────────── */

	var modal = document.getElementById('af-cobranza-modal');
	if (!modal) { return; }

	var periodLabel = document.getElementById('af-cobranza-period');
	var calendarEl = document.getElementById('af-cobranza-calendar');
	var legendEl = document.getElementById('af-cobranza-legend');
	var chargesEl = document.getElementById('af-cobranza-charges');
	var titleEl = document.getElementById('af-cobranza-modal-title');
	var subtitleEl = document.getElementById('af-cobranza-modal-subtitle');
	var statusEl = document.getElementById('af-cobranza-status');

	var payPanel = document.getElementById('af-cobranza-pay');
	var payChargeId = document.getElementById('af-cobranza-pay-charge-id');
	var payAmount = document.getElementById('af-cobranza-pay-amount');
	var payDate = document.getElementById('af-cobranza-pay-date');
	var payMethod = document.getElementById('af-cobranza-pay-method');
	var payReference = document.getElementById('af-cobranza-pay-reference');
	var payOutstanding = document.getElementById('af-cobranza-pay-outstanding');
	var payStatus = document.getElementById('af-cobranza-pay-status');
	var payCancel = document.getElementById('af-cobranza-pay-cancel');
	var payConfirm = document.getElementById('af-cobranza-pay-confirm');

	var state = { prop: null, period: '' };

	/* ── Modal: open / close ─────────────────────────────────────────────── */

	function openModal() {
		modal.classList.add('is-open');
	}

	function closeModal() {
		modal.classList.remove('is-open');
		payPanel.hidden = true;
		hideStatus();
	}

	function showStatus(message, type) {
		if (!statusEl) { return; }
		statusEl.style.display = '';
		statusEl.textContent = message;
		statusEl.className = 'af-modal__status is-' + (type || 'success');
	}

	function hideStatus() {
		if (!statusEl) { return; }
		statusEl.textContent = '';
		statusEl.style.display = 'none';
		statusEl.className = 'af-modal__status';
	}

	modal.querySelectorAll('[data-af-modal-close]').forEach(function (btn) {
		btn.addEventListener('click', closeModal);
	});
	document.addEventListener('keydown', function (e) {
		if ('Escape' === e.key && modal.classList.contains('is-open')) {
			closeModal();
		}
	});

	/* ── Calendario ───────────────────────────────────────────────────────── */

	var WEEKDAYS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];

	function dotClassFor(charge, period) {
		var status = charge.status;
		if ('paid' === status) { return 'is-paid'; }
		if ('overdue' === status) { return 'is-overdue'; }
		var due = charge.dueDate || '';
		if (due && TODAY && due < TODAY) { return 'is-overdue'; }
		return 'is-pending';
	}

	function renderCalendar() {
		var p = state.period;
		var x = parsePeriod(p);
		var first = new Date(x.y, x.m - 1, 1);
		var daysInMonth = new Date(x.y, x.m, 0).getDate();
		var startWeekday = (first.getDay() + 6) % 7; // lunes = 0

		var charges = (state.prop.charges && state.prop.charges[p]) || [];
		var byDay = {};
		charges.forEach(function (c) {
			var d = String(c.dueDate || '').slice(-2).replace(/^0/, '');
			if (!byDay[d]) { byDay[d] = []; }
			byDay[d].push(c);
		});

		var todayKey = String(TODAY).slice(0, 10);
		var weekDaysHtml = WEEKDAYS.map(function (w) {
			return '<span class="af-calendar-mini__wd">' + w + '</span>';
		}).join('');

		var cells = '';
		for (var i = 0; i < startWeekday; i++) {
			cells += '<div class="af-calendar-mini__day is-outside"></div>';
		}
		for (var day = 1; day <= daysInMonth; day++) {
			var dayStr = x.y + '-' + pad(x.m) + '-' + pad(day);
			var cls = 'af-calendar-mini__day';
			if (dayStr === todayKey) { cls += ' is-today'; }

			var dots = '';
			var dayCharges = byDay[String(day)] || [];
			dayCharges.forEach(function (c) {
				dots += '<i class="af-calendar-mini__day-dot ' + dotClassFor(c, p) + '"></i>';
			});
			if (state.prop.has_lease && state.prop.payment_due_day === day) {
				dots = '<i class="af-calendar-mini__day-dot is-payday"></i>' + dots;
			}

			cells += '<div class="' + cls + '" data-day="' + dayStr + '">' +
				'<span class="af-calendar-mini__day-num">' + day + '</span>' +
				'<span class="af-calendar-mini__dots">' + dots + '</span>' +
				'</div>';
		}
		var trailing = (7 - ((startWeekday + daysInMonth) % 7)) % 7;
		for (var t = 0; t < trailing; t++) {
			cells += '<div class="af-calendar-mini__day is-outside"></div>';
		}

		periodLabel.textContent = monthLabel(p);
		calendarEl.innerHTML = weekDaysHtml + cells;
		legendEl.innerHTML =
			'<span><i class="is-paid"></i> ' + 'Pagado' + '</span>' +
			'<span><i class="is-pending"></i> ' + 'Pendiente' + '</span>' +
			'<span><i class="is-overdue"></i> ' + 'Vencido' + '</span>' +
			(state.prop.has_lease ? '<span><i class="is-payday"></i> ' + 'Día de pago' + '</span>' : '');
	}

	/* ── Lista de cargos del mes ─────────────────────────────────────────── */

	function balanceOf(c) {
		return (Number(c.amount) || 0) - (Number(c.paid) || 0);
	}

	function chargeStatusLabel(c) {
		if ('paid' === c.status) { return 'Pagado'; }
		if ('overdue' === c.status) { return 'Vencido'; }
		var bal = balanceOf(c);
		if ('partial' === c.status && bal > 0.01) { return 'Parcial'; }
		return 'Pendiente';
	}

	function chargeStatusClass(c) {
		if ('paid' === c.status) { return 'is-paid'; }
		if ('overdue' === c.status) { return 'is-overdue'; }
		return 'is-pending';
	}

	function renderCharges() {
		var p = state.period;
		var charges = (state.prop.charges && state.prop.charges[p]) || [];

		chargesEl.innerHTML = '';
		if (!charges.length) {
			var empty = document.createElement('li');
			empty.className = 'af-cobranza-empty';
			empty.textContent = 'Sin cargos para este periodo.';
			chargesEl.appendChild(empty);
			payPanel.hidden = true;
			return;
		}

		charges.forEach(function (c) {
			var paid = 'paid' === c.status;
			var bal = balanceOf(c);
			var li = document.createElement('li');
			li.className = 'af-cobranza-charge ' + (paid ? 'is-paid' : '');

			li.innerHTML =
				'<span class="af-cobranza-charge__icon">' + chargeIcon(c.type, paid) + '</span>' +
				'<div class="af-cobranza-charge__body">' +
				'<span class="af-cobranza-charge__label">' + '</span>' +
				'<span class="af-cobranza-charge__meta"></span>' +
				'</div>' +
				'<div class="af-cobranza-charge__amount">' +
				'<span class="af-cobranza-charge__value">$' + monthMoney(c.amount) + '</span>' +
				'<span class="af-cobranza-charge__balance"></span>' +
				'</div>' +
				'<div class="af-cobranza-charge__actions">' +
				(paid || bal <= 0.01 ? '' : '<button type="button" class="button af-btn af-cobranza-pay-open" data-charge="' + c.id + '" data-outstanding="' + bal.toFixed(2) + '">Anotar pago</button>') +
				'</div>';

			li.querySelector('.af-cobranza-charge__label').textContent = c.label + (c.desc ? ' · ' + c.desc : '');
			li.querySelector('.af-cobranza-charge__meta').textContent =
				(c.dueDate ? dateLabel(c.dueDate) + ' · ' : '') + chargeStatusLabel(c);
			li.querySelector('.af-cobranza-charge__balance').textContent =
				paid ? 'Pagado' : ('Saldo $' + monthMoney(bal));

			chargesEl.appendChild(li);
		});
	}

	function render() {
		renderCalendar();
		renderCharges();
	}

	/* ── Abrir modal desde tarjeta ───────────────────────────────────────── */

	function openForProp(prop) {
		state.prop = prop;
		state.period = periodString(new Date());

		// Si el mes actual no tiene cargos, abre el primer mes con cargos.
		if (!prop.charges || !prop.charges[state.period]) {
			var keys = prop.charges ? Object.keys(prop.charges).sort() : [];
			if (keys.length) {
				state.period = keys[0];
			}
		}

		titleEl.textContent = prop.title;
		var sub = [];
		if (prop.unit_code) { sub.push(prop.unit_code + (prop.building_name ? ' · ' + prop.building_name : '')); }
		if (prop.guest) { sub.push('Arrendatario: ' + prop.guest); }
		subtitleEl.textContent = sub.join(' — ');

		payPanel.hidden = true;
		hideStatus();
		render();
		openModal();
	}

	document.querySelectorAll('.af-cobranza-card[data-prop]').forEach(function (card) {
		card.addEventListener('click', function () {
			var prop = DATA[card.getAttribute('data-prop')];
			if (prop) { openForProp(prop); }
		});
		card.addEventListener('keydown', function (e) {
			if ('Enter' === e.key || ' ' === e.key) {
				e.preventDefault();
				card.click();
			}
		});
	});

	/* ── Navegación del calendario ───────────────────────────────────────── */

	var todayPeriod = TODAY ? String(TODAY).slice(0, 7) : periodString(new Date());

	document.querySelectorAll('[data-cal]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var dir = btn.getAttribute('data-cal');
			if ('prev' === dir) { state.period = addMonths(state.period, -1); }
			if ('next' === dir) { state.period = addMonths(state.period, 1); }
			if ('today' === dir) { state.period = todayPeriod; }
			render();
		});
	});

	/* ── Anotar pago ─────────────────────────────────────────────────────── */

	function setPayStatus(message, type) {
		payStatus.style.display = '';
		payStatus.textContent = message;
		payStatus.className = 'af-modal__status is-' + (type || 'success');
	}

	function hidePayStatus() {
		payStatus.textContent = '';
		payStatus.style.display = 'none';
		payStatus.className = 'af-modal__status';
	}

	chargesEl.addEventListener('click', function (e) {
		var btn = e.target.closest('.af-cobranza-pay-open');
		if (!btn) { return; }

		payChargeId.value = btn.getAttribute('data-charge');
		var outstanding = parseFloat(btn.getAttribute('data-outstanding')) || 0;
		payAmount.value = outstanding.toFixed(2);
		payDate.value = TODAY || '';
		payOutstanding.textContent = 'Saldo del cargo: $' + monthMoney(outstanding) +
			' — puedes superarlo y el excedente quedará como saldo a favor.';
		payReference.value = '';
		hidePayStatus();
		payPanel.hidden = false;
		payPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	});

	payCancel.addEventListener('click', function () {
		payPanel.hidden = true;
		hidePayStatus();
	});

	payConfirm.addEventListener('click', function () {
		var amount = parseFloat(payAmount.value);
		if (!amount || amount <= 0) {
			setPayStatus('Ingresa un monto mayor a cero.', 'error');
			return;
		}

		payConfirm.disabled = true;
		hidePayStatus();

		var body = new URLSearchParams();
		body.append('action', 'af_record_payment');
		body.append('nonce', CONFIG.nonce || '');
		body.append('charge_id', payChargeId.value);
		body.append('amount', amount);
		body.append('payment_date', payDate.value);
		body.append('method', payMethod.value);
		body.append('reference', payReference.value);

		fetch(CONFIG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); }).then(function (json) {
			payConfirm.disabled = false;
			if (!json || !json.success) {
				setPayStatus((json && json.data && json.data.message) || 'Error al registrar el pago.', 'error');
				return;
			}
			setPayStatus(json.data.message || 'Pago registrado correctamente.', 'success');
			setTimeout(function () { window.location.reload(); }, 900);
		}).catch(function () {
			payConfirm.disabled = false;
			setPayStatus('Error de red al registrar el pago.', 'error');
		});
	});
})();