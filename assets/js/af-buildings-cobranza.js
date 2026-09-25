/* Cobranza de inmuebles: modal con calendario de cobros por inmueble y
   anotación de pagos (af_record_payment).

   El calendario es vivo: cada cambio de mes pide un snapshot fresco a
   af_cobranza_snapshot, de modo que se puede navegar cualquier periodo (no
   solo la ventana ±4 meses que trae el HTML) y los datos nunca quedan
   desfasados. Tras registrar un pago se refresca en el sitio, sin recargar.

   Los días son pulsables y filtran la lista de cargos; la leyenda filtra por
   estado; el resumen del mes y el detalle del día se recalculan en cada
   render. Sin dependencias. */
(function () {
	'use strict';

	var CONFIG = window.afCobranza || {};
	var T = (CONFIG && CONFIG.i18n) || {};

	/* Meses que se puede navegar desde hoy, hacia atrás y hacia adelante. */
	var MAX_MONTHS_BACK = 24;
	var MAX_MONTHS_FWD = 24;

	var state = {
		props: window.afBuildingsCobranza || {},
		statuses: window.afBuildingsCobranzaStatuses || {},
		today: window.afBuildingsCobranzaToday || '',
		period: window.afBuildingsCobranzaPeriod || '',
		propId: 0,
		day: '',
		filters: {},
		loading: false
	};

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

	function todayPeriod() {
		if (state.today) {
			return String(state.today).slice(0, 7);
		}
		var now = new Date();
		return periodString(now);
	}

	function money(n) {
		return '$' + Number(n || 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function shortMoney(n) {
		var v = Number(n || 0);
		if (v >= 1000) {
			return '$' + v.toLocaleString('es-ES', { maximumFractionDigits: 1 }) ;
		}
		return money(v);
	}

	/* Fechas en texto: el día del mes siempre en texto ("12 de marzo de 2026")
	   para no depender del idioma del navegador. */
	var MONTH_LONG = (function () {
		try {
			return new Intl.DateTimeFormat('es-ES', { month: 'long' });
		} catch (e) {
			return null;
		}
	}());

	function monthLongName(y, m) {
		if (MONTH_LONG) {
			return MONTH_LONG.format(new Date(y, m - 1, 1));
		}
		return (T.months && T.months[m - 1]) || '';
	}

	function monthLabel(p) {
		var x = parsePeriod(p);
		var name = monthLongName(x.y, x.m);
		return name ? name + ' ' + x.y : p;
	}

	function longDate(ymd) {
		if (!ymd) { return ''; }
		var parts = String(ymd).split('-');
		var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
		var name = monthLongName(d.getFullYear(), d.getMonth() + 1);
		return name ? d.getDate() + ' de ' + name + ' de ' + d.getFullYear() : ymd;
	}

	function shortDate(ymd) {
		if (!ymd) { return ''; }
		var parts = String(ymd).split('-');
		var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
		var name = (T.months && T.months[d.getMonth()]) || '';
		return d.getDate() + ' ' + name;
	}

	function plural(count, key) {
		var s = T[key] || '%d';
		return s.replace('%d', count);
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

	function chargeIcon(type) {
		return ICONS[type] || ICONS.otro;
	}

	/* ── Estado DOM ──────────────────────────────────────────────────────── */

	var modal = document.getElementById('af-cobranza-modal');
	var calendarEl = document.getElementById('af-cobranza-calendar');
	if (!modal || !calendarEl) { return; }

	var periodLabel = document.getElementById('af-cobranza-period');
	var spinner = document.getElementById('af-cobranza-spinner');
	var legendEl = document.getElementById('af-cobranza-legend');
	var chargesEl = document.getElementById('af-cobranza-charges');
	var chargesTotalEl = document.getElementById('af-cobranza-charges-total');
	var summaryEl = document.getElementById('af-cobranza-summary');
	var dayFilterEl = document.getElementById('af-cobranza-dayfilter');
	var tipEl = document.getElementById('af-cobranza-tip');
	var titleEl = document.getElementById('af-cobranza-modal-title');
	var subtitleEl = document.getElementById('af-cobranza-modal-subtitle');
	var statusEl = document.getElementById('af-cobranza-status');
	var navBtns = modal.querySelectorAll('[data-cal]');

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

	var gridEl = document.getElementById('af-cobranza-grid');
	var attentionEl = document.querySelector('[data-cobranza-attention]');

	/* Clases de marco que el snapshot puede asignar a una tarjeta. */
	var RING_CLASSES = (function () {
		var out = [];
		Object.keys(state.statuses).forEach(function (k) {
			var c = state.statuses[k] && state.statuses[k].card;
			if (c && out.indexOf(c) === -1) { out.push(c); }
		});
		return out;
	}());

	/* ── Datos ───────────────────────────────────────────────────────────── */

	function currentProp() {
		return state.propId ? state.props[state.propId] || null : null;
	}

	function chargesOf(period) {
		var prop = currentProp();
		if (!prop || !prop.charges) { return []; }
		return prop.charges[period] || [];
	}

	function balanceOf(c) {
		return (Number(c.amount) || 0) - (Number(c.paid) || 0);
	}

	/* Un cargo está vencido si su saldo no está saldado y su vencimiento ya pasó. */
	function isOverdue(c) {
		if ('overdue' === c.status) { return true; }
		if (c.dueDate && state.today && c.dueDate < state.today) {
			return balanceOf(c) > 0.01;
		}
		return false;
	}

	function isPaid(c) {
		return 'paid' === c.status || balanceOf(c) <= 0.01;
	}

	function toneOf(c) {
		if (isPaid(c)) { return 'paid'; }
		if (isOverdue(c)) { return 'overdue'; }
		return 'pending';
	}

	function statusLabel(c) {
		var tone = toneOf(c);
		if ('paid' === tone) { return T.paid || 'Pagado'; }
		if ('overdue' === tone) { return T.overdue || 'Vencido'; }
		if ('partial' === c.status) { return T.partial || 'Parcial'; }
		return T.pending || 'Pendiente';
	}

	function statusClass(c) {
		return 'is-' + toneOf(c);
	}

	/* Agrupa los cargos del periodo por día (YYYY-MM-DD). */
	function chargesByDay(period) {
		var map = {};
		chargesOf(period).forEach(function (c) {
			if (!c.dueDate) { return; }
			if (!map[c.dueDate]) { map[c.dueDate] = []; }
			map[c.dueDate].push(c);
		});
		return map;
	}

	/* ── Totales del mes ─────────────────────────────────────────────────── */

	function monthTotals(charges) {
		var t = { charged: 0, paid: 0, pending: 0, overdue: 0, count: 0 };
		(charges || []).forEach(function (c) {
			var bal = balanceOf(c);
			t.charged += Number(c.amount) || 0;
			t.paid += Number(c.paid) || 0;
			t.pending += bal > 0 ? bal : 0;
			if (isOverdue(c)) { t.overdue += bal > 0 ? bal : 0; }
			t.count += 1;
		});
		return t;
	}

	function renderSummary(charges) {
		if (!summaryEl) { return; }
		var t = monthTotals(charges);
		if (!t.count) {
			summaryEl.hidden = true;
			return;
		}
		summaryEl.hidden = false;

		var pct = t.charged > 0 ? Math.min(100, (t.paid / t.charged) * 100) : 0;
		var values = {
			charged: money(t.charged),
			paid: money(t.paid),
			pending: money(t.pending)
		};
		summaryEl.querySelectorAll('[data-sum]').forEach(function (el) {
			var k = el.getAttribute('data-sum');
			if ('fill' === k) {
				el.style.width = pct.toFixed(1) + '%';
			} else if (values[k]) {
				el.textContent = values[k];
			}
		});

		var bar = document.getElementById('af-cobranza-progress');
		if (bar) {
			bar.setAttribute('aria-label', (T.paid || 'Pagado') + ': ' + pct.toFixed(0) + '%');
		}
	}

	/* ── Leyenda: filtros por estado ─────────────────────────────────────── */

	function renderLegend(charges) {
		if (!legendEl) { return; }
		var t = { paid: 0, pending: 0, overdue: 0 };
		(charges || []).forEach(function (c) {
			t[toneOf(c)] += 1;
		});

		var items = [
			{ key: 'paid', label: T.paid, dot: 'is-paid' },
			{ key: 'pending', label: T.pending, dot: 'is-pending' },
			{ key: 'overdue', label: T.overdue, dot: 'is-overdue' }
		];
		var prop = currentProp();
		if (prop && prop.has_lease) {
			items.push({ key: 'payday', label: T.payday, dot: 'is-payday', static: true });
		}

		legendEl.innerHTML = '';
		items.forEach(function (item) {
			if (item.static) {
				var s = document.createElement('span');
				s.className = 'af-calendar-mini__legend-item';
				s.innerHTML = '<i class="' + item.dot + '"></i> ' + item.label;
				legendEl.appendChild(s);
				return;
			}
			if (!t[item.key]) { return; }

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'af-calendar-mini__filter';
			btn.setAttribute('data-filter', item.key);
			btn.setAttribute('aria-pressed', state.filters[item.key] ? 'true' : 'false');
			if (state.filters[item.key]) { btn.classList.add('is-active'); }
			btn.innerHTML = '<i class="' + item.dot + '"></i> ' + item.label +
				' <span class="af-calendar-mini__filter-n">' + t[item.key] + '</span>';
			legendEl.appendChild(btn);
		});
	}

	/* ── Calendario ───────────────────────────────────────────────────────── */

	/* Filtros de estado. La leyenda es de INCLUSIÓN: sin filtros visibles se
	   muestran todos los cargos; con al menos un filtro activo sólo pasan los
	   tonos marcados. Calendario y lista comparten esta regla para que no se
	   desincronicen. */
	function hasActiveFilters() {
		return !!(state.filters.paid || state.filters.pending || state.filters.overdue);
	}

	function toneVisible(c) {
		return !hasActiveFilters() || !!state.filters[toneOf(c)];
	}

	function atLimit(delta) {
		var base = todayPeriod();
		if (delta < 0) {
			return state.period <= addMonths(base, -MAX_MONTHS_BACK);
		}
		return state.period >= addMonths(base, MAX_MONTHS_FWD);
	}

	function renderNavState() {
		navBtns.forEach(function (btn) {
			var dir = btn.getAttribute('data-cal');
			if ('prev' === dir) { btn.disabled = state.loading || atLimit(-1); }
			if ('next' === dir) { btn.disabled = state.loading || atLimit(1); }
			if ('today' === dir) { btn.disabled = state.loading || state.period === todayPeriod(); }
		});
	}

	function dayAriaLabel(ymd, dayCharges) {
		var parts = ymd.split('-');
		var base = parts[2] + ' de ' + monthLongName(parseInt(parts[0], 10), parseInt(parts[1], 10));
		if (!dayCharges.length) { return base; }
		return base + ': ' + dayCharges.map(function (c) {
			return c.label + ' ' + statusLabel(c);
		}).join(', ');
	}

	function renderCalendar() {
		var p = state.period;
		var prop = currentProp();
		var x = parsePeriod(p);
		var first = new Date(x.y, x.m - 1, 1);
		var daysInMonth = new Date(x.y, x.m, 0).getDate();
		var startWeekday = (first.getDay() + 6) % 7; // lunes = 0

		var byDay = prop ? chargesByDay(p) : {};
		var dueDay = prop && prop.has_lease ? Number(prop.payment_due_day) : 0;
		var todayKey = String(state.today || '').slice(0, 10);

		var wdHtml = (T.weekdays || []).map(function (w) {
			return '<span class="af-calendar-mini__wd">' + w + '</span>';
		}).join('');

		var cells = '';
		for (var i = 0; i < startWeekday; i++) {
			cells += '<span class="af-calendar-mini__day is-outside" aria-hidden="true"></span>';
		}

		for (var day = 1; day <= daysInMonth; day++) {
			var dayStr = x.y + '-' + pad(x.m) + '-' + pad(day);
			var dayCharges = byDay[dayStr] || [];
			// Con filtros de estado activos, un día puede quedar sin cargos
			// visibles aunque tenga cargos: se atenúa en vez de desaparecer.
			var visible = dayCharges.filter(toneVisible);

			var cls = 'af-calendar-mini__day';
			if (dayCharges.length) { cls += ' has-charges'; }
			if (dayStr === todayKey) { cls += ' is-today'; }
			if (dayStr === state.day) { cls += ' is-selected'; }
			if (state.day && dayStr !== state.day) { cls += ' is-dimmed'; }
			if (dayCharges.length && !visible.length && !state.day) { cls += ' is-dimmed'; }
			if (dueDay === day) { cls += ' is-payday'; }

			var dots = '';
			visible.forEach(function (c) {
				dots += '<i class="af-calendar-mini__day-dot ' + statusClass(c) + '"></i>';
			});
			if (dueDay === day) {
				dots = '<i class="af-calendar-mini__day-dot is-payday"></i>' + dots;
			}

			var amt = '';
			var dayTotals = monthTotals(visible);
			if (visible.length) {
				var amtClass = dayTotals.overdue > 0.01 ? 'is-overdue'
					: (dayTotals.pending > 0.01 ? 'is-pending' : 'is-paid');
				amt = '<span class="af-calendar-mini__day-amt ' + amtClass + '">' +
					shortMoney(dayTotals.pending > 0.01 ? dayTotals.pending : dayTotals.charged) + '</span>';
			}

			var pressed = dayStr === state.day ? 'true' : 'false';
			cells += '<button type="button" class="' + cls + '" data-day="' + dayStr + '" aria-pressed="' + pressed + '" aria-label="' +
				escapeAttr(dayAriaLabel(dayStr, dayCharges)) + '">' +
				'<span class="af-calendar-mini__day-num">' + day + '</span>' +
				'<span class="af-calendar-mini__dots">' + dots + '</span>' +
				amt +
				'</button>';
		}

		var trailing = (7 - ((startWeekday + daysInMonth) % 7)) % 7;
		for (var t2 = 0; t2 < trailing; t2++) {
			cells += '<span class="af-calendar-mini__day is-outside" aria-hidden="true"></span>';
		}

		if (periodLabel) { periodLabel.textContent = monthLabel(p); }
		calendarEl.innerHTML = wdHtml + cells;
	}

	function escapeAttr(s) {
		return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	/* ── Tooltip del día ─────────────────────────────────────────────────── */

	function hideTip() {
		if (tipEl) { tipEl.hidden = true; }
	}

	function showTip(cell, dayStr, dayCharges) {
		if (!tipEl || !dayCharges.length) {
			hideTip();
			return;
		}
		var lines = dayCharges.map(function (c) {
			return c.label + ' · ' + statusLabel(c) + ' · ' + money(c.amount);
		});
		var prop = currentProp();
		if (prop && prop.has_lease && prop.payment_due_day === Number(dayStr.slice(-2))) {
			lines.push((T.paydayOn || 'Día de pago: %d').replace('%d', prop.payment_due_day));
		}
		tipEl.innerHTML = lines.join('<br>');
		tipEl.hidden = false;

		var box = cell.getBoundingClientRect();
		var host = modal.getBoundingClientRect();
		tipEl.style.top = (box.top - host.top - tipEl.offsetHeight - 8) + 'px';
		tipEl.style.left = Math.max(8, Math.min(
			box.left - host.left + box.width / 2 - tipEl.offsetWidth / 2,
			host.width - tipEl.offsetWidth - 8
		)) + 'px';
	}

	/* ── Lista de cargos ─────────────────────────────────────────────────── */

	function filteredCharges() {
		var list = chargesOf(state.period);
		return list.filter(function (c) {
			if (state.day && c.dueDate !== state.day) { return false; }
			if (!toneVisible(c)) { return false; }
			return true;
		});
	}

	function renderDayFilter() {
		if (!dayFilterEl) { return; }
		var hasDay = !!state.day;
		var hasFilters = hasActiveFilters();
		if (!hasDay && !hasFilters) {
			dayFilterEl.hidden = true;
			dayFilterEl.innerHTML = '';
			return;
		}

		dayFilterEl.hidden = false;
		dayFilterEl.innerHTML = '';

		var chip = document.createElement('span');
		chip.className = 'af-cobranza-dayfilter__chip';
		var text = hasDay ? (T.dayCharges || 'Cargos del %s').replace('%s', longDate(state.day)) : '';
		if (hasFilters) {
			text = text ? text + ' · ' : '';
		}
		chip.textContent = text;
		if (text) { dayFilterEl.appendChild(chip); }

		var clear = document.createElement('button');
		clear.type = 'button';
		clear.className = 'button af-btn af-btn--ghost';
		clear.setAttribute('data-clear-filters', '1');
		clear.textContent = hasDay ? (T.showAll || 'Ver todos los días') : (T.clearFilters || 'Quitar filtros');
		dayFilterEl.appendChild(clear);
	}

	function renderCharges() {
		var all = chargesOf(state.period);
		var list = filteredCharges();

		chargesEl.innerHTML = '';

		if (!all.length) {
			appendEmpty(chargesEl, T.noCharges || 'Sin cargos para este periodo.');
			if (chargesTotalEl) { chargesTotalEl.textContent = ''; }
			return;
		}
		if (!list.length) {
			appendEmpty(chargesEl, T.noMatches || 'Ningún cargo coincide con el filtro.');
			if (chargesTotalEl) { chargesTotalEl.textContent = ''; }
			return;
		}

		if (chargesTotalEl) {
			var tt = monthTotals(list);
			chargesTotalEl.textContent = (T.dayTotals || '%1$s facturados · %2$s pendientes')
				.replace('%1$s', shortMoney(tt.charged))
				.replace('%2$s', shortMoney(tt.pending));
		}

		list.forEach(function (c) {
			var paid = isPaid(c);
			var bal = balanceOf(c);
			var li = document.createElement('li');
			li.className = 'af-cobranza-charge ' + statusClass(c);
			li.setAttribute('data-day', c.dueDate || '');

			li.innerHTML =
				'<span class="af-cobranza-charge__icon">' + chargeIcon(c.type) + '</span>' +
				'<div class="af-cobranza-charge__body">' +
				'<span class="af-cobranza-charge__label"></span>' +
				'<span class="af-cobranza-charge__meta"></span>' +
				'</div>' +
				'<div class="af-cobranza-charge__amount">' +
				'<span class="af-cobranza-charge__value">' + money(c.amount) + '</span>' +
				'<span class="af-cobranza-charge__balance"></span>' +
				'</div>' +
				'<div class="af-cobranza-charge__actions">' +
				(paid ? '' : '<button type="button" class="button af-btn af-cobranza-pay-open" data-charge="' + c.id + '" data-outstanding="' + bal.toFixed(2) + '">' + (T.recordPayment || 'Anotar pago') + '</button>') +
				'</div>';

			li.querySelector('.af-cobranza-charge__label').textContent = c.label + (c.desc ? ' · ' + c.desc : '');
			li.querySelector('.af-cobranza-charge__meta').textContent =
				(c.dueDate ? shortDate(c.dueDate) + ' · ' : '') + statusLabel(c);
			li.querySelector('.af-cobranza-charge__balance').textContent =
				paid ? (T.paid || 'Pagado') : ((T.balance || 'Saldo') + ' ' + money(bal));

			chargesEl.appendChild(li);
		});
	}

	function appendEmpty(list, text) {
		var li = document.createElement('li');
		li.className = 'af-cobranza-empty';
		li.textContent = text;
		list.appendChild(li);
	}

	/* ── Render ──────────────────────────────────────────────────────────── */

	function render() {
		renderNavState();
		if (spinner) { spinner.hidden = !state.loading; }
		calendarEl.classList.toggle('is-loading', state.loading);

		var charges = chargesOf(state.period);
		renderCalendar();
		renderLegend(charges);
		renderSummary(charges);
		renderDayFilter();
		renderCharges();
	}

	/* ── Modal: abrir / cerrar ───────────────────────────────────────────── */

	function openModal() {
		modal.classList.add('is-open');
		document.body.classList.add('af-modal-open');
	}

	function closeModal() {
		modal.classList.remove('is-open');
		document.body.classList.remove('af-modal-open');
		payPanel.hidden = true;
		state.day = '';
		hideTip();
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

	/* ── Snapshot por AJAX ───────────────────────────────────────────────── */

	function post(action, extra) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', CONFIG.nonce || '');
		Object.keys(extra || {}).forEach(function (k) {
			body.append(k, extra[k]);
		});
		return fetch(CONFIG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	/* Navegación de mes: pide los cargos del periodo pedido y los guarda en el
	   estado del inmueble abierto. */
	function loadMonth(period) {
		if (state.loading) { return; }
		state.loading = true;
		state.period = period;
		render();

		post('af_cobranza_snapshot', { period: period }).then(function (json) {
			state.loading = false;
			if (!json || !json.success) {
				showStatus((json && json.data && json.data.message) || (T.monthFailed || 'No se pudo cargar el mes.'), 'error');
				render();
				return;
			}
			state.today = json.data.today || state.today;
			state.statuses = json.data.statuses || state.statuses;
			var prop = json.data.props && json.data.props[state.propId];
			if (prop && currentProp()) {
				currentProp().charges = prop.charges || {};
			}
			hideStatus();
			render();
		}).catch(function () {
			state.loading = false;
			showStatus(T.monthNetFailed || 'Error de red al cargar el mes.', 'error');
			render();
		});
	}

	/* Tras cobrar: snapshot fresco del mes en curso, tarjetas y alertas
	   repintadas en el sitio y modal al día, sin recargar la página. */
	function refreshCurrent() {
		var period = todayPeriod();
		return post('af_cobranza_snapshot', { period: period }).then(function (json) {
			if (!json || !json.success) { return false; }

			state.today = json.data.today || state.today;
			state.statuses = json.data.statuses || state.statuses;
			state.props = json.data.props || state.props;

			paintAlerts(json.data.display, json.data.summary);
			paintCards(json.data.props);

			// Si el modal está mirando un mes fuera de la ventana recién cargada,
			// se vuelve a pedir ese mes para no mostrar cargos rancios.
			if (modal.classList.contains('is-open') && state.period !== period) {
				var prop = json.data.props && json.data.props[state.propId];
				var inWindow = prop && prop.charges && prop.charges[state.period];
				if (!inWindow) {
					post('af_cobranza_snapshot', { period: state.period }).then(function (j2) {
						if (j2 && j2.success && j2.data.props && j2.data.props[state.propId] && currentProp()) {
							currentProp().charges = j2.data.props[state.propId].charges || {};
						}
						render();
					});
				}
			}
			render();
			return true;
		});
	}

	/* ── Pintar tarjetas y alertas en el sitio ───────────────────────────── */

	function paintAlerts(display, summary) {
		if (!display) { return; }

		['vencido', 'proximo', 'mes'].forEach(function (key) {
			var box = document.querySelector('[data-alert="' + key + '"]');
			if (!box) { return; }
			var data = display[key];
			if (!data) {
				box.hidden = true;
				return;
			}
			box.hidden = false;
			var title = box.querySelector('[data-alert="' + key + '-title"]');
			var text = box.querySelector('[data-alert="' + key + '-text"]');
			if (title) { title.textContent = data.title; }
			if (text) { text.textContent = data.text; }
		});

		if (summary && attentionEl) {
			var n = Number(summary.vencido_count) + Number(summary.proximo_count);
			attentionEl.textContent = n;
			attentionEl.hidden = n <= 0;
		}
	}

	function paintCards(props) {
		if (!gridEl || !props) { return; }
		var cards = gridEl.querySelectorAll('.af-cobranza-card[data-prop]');

		Array.prototype.forEach.call(cards, function (card) {
			var id = card.getAttribute('data-prop');
			var prop = props[id];
			if (!prop) {
				card.hidden = true;
				return;
			}
			// Todos los inmuebles viven en la misma grilla: si el snapshot ya no
			// trae el inmueble se oculta, si no se repinta. El estado "available"
			// no es un caso aparte, solo cambia el color del borde.
			card.hidden = false;

			var meta = state.statuses[prop.status] || {};
			card.setAttribute('data-status', prop.status);
			RING_CLASSES.forEach(function (c) { card.classList.remove(c); });
			if (meta.card) { card.classList.add(meta.card); }

			var pill = card.querySelector('[data-card-pill]');
			if (pill && meta.pill) {
				var pillTone = pill.className.match(/af-pill--(\w+)/);
				if (pillTone) { pill.classList.remove('af-pill--' + pillTone[1]); }
				pill.classList.add('af-pill--' + meta.pill);
				pill.textContent = meta.label || '';
			}

			var due = card.querySelector('[data-card-due]');
			if (due) {
				due.textContent = prop.due_hint || '';
				due.className = 'af-cobranza-card__due ' + (prop.due_class || '');
			}

			var amount = card.querySelector('[data-card-amount]');
			if (amount) {
				amount.className = 'af-cobranza-card__amount ' + (prop.due_class || '');
				amount.textContent = '';
				amount.appendChild(document.createTextNode(money(prop.amount_total)));
				var small = document.createElement('small');
				small.textContent = prop.amount_label || '';
				amount.appendChild(small);
			}
		});

		// Reordena la grilla: el snapshot ya viene ordenado por urgencia.
		Object.keys(props).forEach(function (id) {
			var card = gridEl.querySelector('.af-cobranza-card[data-prop="' + id + '"]');
			if (card) { gridEl.appendChild(card); }
		});
	}

	/* ── Abrir el modal desde una tarjeta ────────────────────────────────── */

	function openForProp(id) {
		var prop = state.props[id];
		if (!prop) { return; }

		state.propId = id;
		state.filters = {};
		state.period = todayPeriod();

		// Si el mes en curso no tiene cargos, abre el primer mes con cargos.
		if (!prop.charges || !prop.charges[state.period]) {
			var keys = prop.charges ? Object.keys(prop.charges).sort() : [];
			if (keys.length) { state.period = keys[0]; }
		}

		titleEl.textContent = prop.title;
		var sub = [];
		if (prop.unit_code) { sub.push(prop.unit_code + (prop.building_name ? ' · ' + prop.building_name : '')); }
		if (prop.guest) { sub.push((T.tenant || 'Arrendatario:') + ' ' + prop.guest); }
		subtitleEl.textContent = sub.join(' — ');

		payPanel.hidden = true;
		hideStatus();
		render();
		openModal();
	}

	document.querySelectorAll('.af-cobranza-card[data-prop]').forEach(function (card) {
		card.addEventListener('click', function () {
			openForProp(card.getAttribute('data-prop'));
		});
		card.addEventListener('keydown', function (e) {
			if ('Enter' === e.key || ' ' === e.key) {
				e.preventDefault();
				card.click();
			}
		});
	});

	/* ── Interacción del calendario ──────────────────────────────────────── */

	calendarEl.addEventListener('click', function (e) {
		var cell = e.target.closest('[data-day]');
		if (!cell) { return; }
		var day = cell.getAttribute('data-day');
		state.day = (state.day === day) ? '' : day;
		render();
	});

	calendarEl.addEventListener('mouseover', function (e) {
		var cell = e.target.closest('[data-day]');
		if (!cell) { return; }
		var day = cell.getAttribute('data-day');
		showTip(cell, day, chargesByDay(state.period)[day] || []);
	});

	calendarEl.addEventListener('mouseout', function (e) {
		if (e.target.closest('[data-day]')) { hideTip(); }
	});

	calendarEl.addEventListener('focusin', function (e) {
		var cell = e.target.closest('[data-day]');
		if (!cell) { return; }
		var day = cell.getAttribute('data-day');
		showTip(cell, day, chargesByDay(state.period)[day] || []);
	});

	calendarEl.addEventListener('focusout', hideTip);

	legendEl.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-filter]');
		if (!btn) { return; }
		var key = btn.getAttribute('data-filter');
		state.filters[key] = !state.filters[key];
		if (state.filters.paid && state.filters.pending && state.filters.overdue) {
			state.filters = {};
		}
		render();
	});

	dayFilterEl.addEventListener('click', function (e) {
		if (!e.target.closest('[data-clear-filters]')) { return; }
		state.day = '';
		state.filters = {};
		render();
	});

	/* Navegación de mes: siempre por AJAX para poder recorrer cualquier
	   periodo, no solo los cargados en el HTML inicial. */
	navBtns.forEach(function (btn) {
		btn.addEventListener('click', function () {
			var dir = btn.getAttribute('data-cal');
			if ('prev' === dir) { loadMonth(addMonths(state.period, -1)); }
			if ('next' === dir) { loadMonth(addMonths(state.period, 1)); }
			if ('today' === dir) { loadMonth(todayPeriod()); }
		});
	});

	/* Flechas del teclado para moverse por los días. */
	calendarEl.addEventListener('keydown', function (e) {
		var cell = e.target.closest('[data-day]');
		if (!cell) { return; }
		var keys = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
		if (!(e.key in keys)) { return; }
		e.preventDefault();
		var all = calendarEl.querySelectorAll('[data-day]');
		var idx = Array.prototype.indexOf.call(all, cell);
		var next = all[idx + keys[e.key]];
		if (next) { next.focus(); }
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
		payDate.value = state.today || '';
		payOutstanding.textContent = (T.outstanding || 'Saldo del cargo: %s')
			.replace('%s', money(outstanding)) + ' ' + (T.overpayHint || '');
		payReference.value = '';
		hidePayStatus();
		payPanel.hidden = false;
		if (payPanel.scrollIntoView) {
			payPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	});

	payCancel.addEventListener('click', function () {
		payPanel.hidden = true;
		hidePayStatus();
	});

	payConfirm.addEventListener('click', function () {
		var amount = parseFloat(payAmount.value);
		if (!amount || amount <= 0) {
			setPayStatus(T.amountRequired || 'Ingresa un monto mayor a cero.', 'error');
			return;
		}

		payConfirm.disabled = true;
		hidePayStatus();

		post('af_record_payment', {
			charge_id: payChargeId.value,
			amount: amount,
			payment_date: payDate.value,
			method: payMethod.value,
			reference: payReference.value
		}).then(function (json) {
			payConfirm.disabled = false;
			if (!json || !json.success) {
				setPayStatus((json && json.data && json.data.message) || (T.payFailed || 'Error de red al registrar el pago.'), 'error');
				return;
			}
			setPayStatus(json.data.message || 'Pago registrado correctamente.', 'success');
			payPanel.hidden = true;
			state.day = '';

			// Se refresca todo en el sitio: modal, tarjetas, alertas y contadores.
			refreshCurrent().then(function (ok) {
				if (ok) { hidePayStatus(); }
			});
		}).catch(function () {
			payConfirm.disabled = false;
			setPayStatus(T.payFailed || 'Error de red al registrar el pago.', 'error');
		});
	});
}());
