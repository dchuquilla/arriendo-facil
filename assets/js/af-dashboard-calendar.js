/** global afDashboardCalendar */
/**
 * Interactive operations calendar (Panel + Calendario).
 * Google Calendar-style month grid fed by AJAX: visits, move-ins, contract
 * check-outs, service due dates and blocks, with per-type toggles. Clicking a
 * day opens its agenda to register visits, move-ins or blocks.
 */
(function () {
	'use strict';

	var container = document.getElementById('af-interactive-calendar');
	if (!container || typeof afDashboardCalendar === 'undefined') {
		return;
	}

	var HIDDEN_KEY = 'afCalHiddenTypes';
	var hiddenTypes = {};
	try {
		(JSON.parse(window.localStorage.getItem(HIDDEN_KEY) || '[]') || []).forEach(function (t) { hiddenTypes[t] = true; });
	} catch (e) {
		hiddenTypes = {};
	}

	var STATE = {
		year: parseInt(container.getAttribute('data-year') || '', 10) || new Date().getFullYear(),
		month: parseInt(container.getAttribute('data-month') || '', 10) || (new Date().getMonth() + 1),
		events: {}, // { 'YYYY-MM-DD': [event,...] }
		loading: false
	};

	var today = new Date();
	var todayStr = fmtDate(today);

	var el = {
		grid: document.getElementById('af-cal-grid'),
		title: document.getElementById('af-cal-month-title'),
		prev: document.getElementById('af-cal-prev'),
		next: document.getElementById('af-cal-next'),
		todayBtn: document.getElementById('af-cal-today'),
		drawer: document.getElementById('af-cal-drawer'),
		drawerDate: document.getElementById('af-cal-drawer-date'),
		drawerTitle: document.getElementById('af-cal-drawer-title'),
		drawerBody: document.getElementById('af-cal-drawer-body'),
		drawerFooter: document.getElementById('af-cal-drawer-actions'),
		modal: document.getElementById('af-cal-modal'),
		modalDate: document.getElementById('af-cal-modal-date'),
		modalType: document.getElementById('af-cal-action-type'),
		accSelect: document.getElementById('af-cal-accommodation'),
		form: document.getElementById('af-cal-form'),
		status: document.getElementById('af-cal-status'),
		visitFields: document.getElementById('af-cal-visit-fields'),
		moveFields: document.getElementById('af-cal-move-fields'),
		blockFields: document.getElementById('af-cal-block-fields'),
		leaseField: document.getElementById('af-cal-lease-field'),
		leaseSelect: document.getElementById('af-cal-lease'),
		dateInput: document.getElementById('af-cal-date'),
		submitBtn: document.getElementById('af-cal-submit')
	};

	// Start on today (or the 1st of the shown month) so the agenda and its actions are visible right away.
	var SELECTED_DATE = (STATE.year === today.getFullYear() && STATE.month === today.getMonth() + 1)
		? todayStr
		: STATE.year + '-' + pad(STATE.month) + '-01';
	var dragIndicator = {
		start: false
	};

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function fmtDate(d) {
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}

	function monthLabel(year, month) {
		var names = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
		return names[month] + ' ' + year;
	}

	function daysInMonth(year, month) {
		return new Date(year, month, 0).getDate();
	}

	function fetchEvents(year, month, cb) {
		var body = new URLSearchParams();
		body.append('action', 'af_calendar_events');
		body.append('nonce', afDashboardCalendar.nonce);
		body.append('month', year + '-' + pad(month));

		fetch(afDashboardCalendar.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success && res.data) {
					STATE.events = res.data;
				}
				cb();
			})
			.catch(function () { cb(); });
	}

	function render() {
		var year = STATE.year;
		var month = STATE.month;
		var first = new Date(year, month - 1, 1);
		var startWeekday = (first.getDay() + 6) % 7; // Monday = 0
		var dim = daysInMonth(year, month);
		var prevDim = daysInMonth(year, month - 1 === 0 ? year - 1 : year, month - 1 === 0 ? 12 : month - 1);

		el.title.textContent = monthLabel(year, month);

		var html = '';
		var day;
		var offset;
		var dateStr;
		var evs;
		var chips;
		var chip;
		var shown;

		for (var i = 0; i < 42; i++) {
			offset = i - startWeekday;
			if (offset < 0) {
				// Prev month day.
				var pMonth = month - 1;
				var pYear = year;
				if (pMonth === 0) { pMonth = 12; pYear = year - 1; }
				day = prevDim + offset + 1;
				dateStr = '' + pYear + '-' + pad(pMonth) + '-' + pad(day);
				html += cell(dateStr, day, true, false);
				continue;
			}
			day = offset + 1;
			if (day > dim) {
				var nMonth = month + 1;
				var nYear = year;
				if (nMonth === 13) { nMonth = 1; nYear = year + 1; }
				day = day - dim;
				dateStr = '' + nYear + '-' + pad(nMonth) + '-' + pad(day);
				html += cell(dateStr, day, true, false);
				continue;
			}
			dateStr = year + '-' + pad(month) + '-' + pad(day);
			html += cell(dateStr, day, false, dateStr === todayStr);
		}

		el.grid.innerHTML = html;

		// Bind day clicks. A day of an adjacent month (a "ghost" cell) moves
		// the grid to that month, Airbnb/Google style, so everything is
		// scheduled in context.
		Array.prototype.forEach.call(el.grid.querySelectorAll('.af-cal__day'), function (node) {
			node.addEventListener('click', function (e) {
				e.preventDefault();
				dayClicked(node.getAttribute('data-date'));
			});
		});

		refreshDrawerForSelected();
	}

	function visibleEvents(dateStr) {
		return (STATE.events[dateStr] || []).filter(function (ev) { return !hiddenTypes[ev.type]; });
	}

	function cell(dateStr, dayNum, outside, isToday) {
		var evs = visibleEvents(dateStr);
		var classes = 'af-cal__day' + (outside ? ' is-outside' : '') + (isToday ? ' is-today' : '') + (dateStr < todayStr ? ' is-past' : '');
		if (SELECTED_DATE === dateStr) {
			classes += ' is-selected';
		}

		var chips = '';
		var shown = 0;
		for (var k = 0; k < evs.length; k++) {
			if (shown >= 3) {
				chips += '<span class="af-cal__chip af-cal__chip--more">+ ' + (evs.length - shown) + ' más</span>';
				break;
			}
			var ev = evs[k];
			var type = ev.type || 'visit';
			var text = (ev.time ? ev.time + ' ' : '') + (ev.title || ep(type));
			chips += '<span class="af-cal__chip af-cal__chip--' + type + (ev.status ? ' is-' + ev.status : '') + '" title="' +
				escapeHtml(ep(type) + ' · ' + (ev.title || '') + (ev.accommodation ? ' · ' + ev.accommodation : '')) + '">' +
				escapeHtml(text) + '</span>';
			shown++;
		}

		return '<button type="button" class="' + classes + '" data-date="' + dateStr + '">' +
			'<span class="af-cal__day-num">' + dayNum + '</span>' +
			(chips ? '<span class="af-cal__chips">' + chips + '</span>' : '') +
			'</button>';
	}

	var TYPE_META = {
		visit: { icon: '👤', label: 'Visita' },
		checkin: { icon: '🔑', label: 'Mudanza' },
		checkout: { icon: '📦', label: 'Salida' },
		service: { icon: '💡', label: 'Servicio' },
		block: { icon: '🚫', label: 'Bloqueado' }
	};

	function ep(t) {
		return TYPE_META[t] ? TYPE_META[t].label : t;
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = str == null ? '' : String(str);
		return div.innerHTML;
	}

	function selectDay(dateStr) {
		SELECTED_DATE = dateStr;
		Array.prototype.forEach.call(el.grid.querySelectorAll('.af-cal__day'), function (node) {
			node.classList.toggle('is-selected', node.getAttribute('data-date') === dateStr);
		});
		refreshDrawerForSelected();
	}

	// When the clicked day belongs to an adjacent month, navigate to that
	// month and select it there instead of keeping the current view.
	function dayClicked(dateStr) {
		var parts = dateStr.split('-');
		var y = parseInt(parts[0], 10);
		var m = parseInt(parts[1], 10);
		if (y === STATE.year && m === STATE.month) {
			selectDay(dateStr);
			return;
		}
		SELECTED_DATE = dateStr;
		reload(y, m);
	}

	function formatLongDate(dateStr) {
		var parts = dateStr.split('-');
		var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
		var names = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
		var months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
		return names[d.getDay()] + ', ' + d.getDate() + ' de ' + months[d.getMonth()] + ' de ' + d.getFullYear();
	}

	function refreshDrawerForSelected() {
		if (!SELECTED_DATE) {
			el.drawerDate.textContent = '';
			el.drawerTitle.textContent = 'Selecciona un día';
			el.drawerBody.innerHTML = '<p class="af-cal__drawer-empty">Toca una fecha del calendario para ver o programar eventos.</p>';
			el.drawerFooter.style.visibility = 'hidden';
			return;
		}

		el.drawerDate.textContent = SELECTED_DATE === todayStr ? 'Hoy' : 'Día seleccionado';
		el.drawerTitle.textContent = formatLongDate(SELECTED_DATE);

		// Las fechas pasadas solo se pueden consultar, no programar.
		var isPast = SELECTED_DATE < todayStr;
		var pastNotice = isPast ? '<p class="af-cal__drawer-past">No se pueden programar visitas ni bloqueos en fechas pasadas.</p>' : '';
		var hint = isPast ? '' : '<p class="af-cal__drawer-hint">Para programar en otra fecha, toca ese día en el calendario.</p>';

		var evs = visibleEvents(SELECTED_DATE);
		if (!evs.length) {
			el.drawerBody.innerHTML = '<p class="af-cal__drawer-empty">Sin eventos este día.' + (isPast ? '' : ' Usa los botones de abajo para agendar una visita, registrar una mudanza o bloquear el día.') + '</p>' + pastNotice + hint;
		} else {
			var html = '';
			evs.forEach(function (ev) {
				var meta = TYPE_META[ev.type] || { icon: '•', label: ev.type };
				var typeLabel = ev.type === 'service' ? meta.label : (ev.label || meta.label);
				var title = escapeHtml(ev.title);
				if (ev.url) {
					title = '<a href="' + escapeHtml(ev.url) + '">' + title + '</a>';
				}
				html += '<div class="af-cal__event is-' + ev.type + (ev.status ? ' is-' + ev.status : '') + '">' +
					'<span class="af-cal__event-icon" aria-hidden="true">' + meta.icon + '</span>' +
					'<div class="af-cal__event-main">' +
					'<div class="af-cal__event-title">' + title + '</div>' +
					'<div class="af-cal__event-meta">' + escapeHtml(typeLabel) +
					(ev.meta ? ' · ' + escapeHtml(ev.meta) : '') +
					(ev.accommodation ? ' · ' + escapeHtml(ev.accommodation) : '') +
					'</div>' + moveDetails(ev) + visitDetails(ev) + '</div>';
				if (ev.removable) {
					html += '<button type="button" class="af-cal__event-remove" data-remove-type="' + ev.type + '" data-remove-id="' + ev.id + '" title="Quitar">×</button>';
				}
				html += '</div>';
			});
			el.drawerBody.innerHTML = html + pastNotice + hint;

			Array.prototype.forEach.call(el.drawerBody.querySelectorAll('.af-cal__event-remove'), function (btn) {
				btn.addEventListener('click', function () { removeEvent(btn.getAttribute('data-remove-type'), btn.getAttribute('data-remove-id')); });
			});
			Array.prototype.forEach.call(el.drawerBody.querySelectorAll('[data-move-task]'), function (box) {
				box.addEventListener('change', function () { saveMoveTasks(box.getAttribute('data-move-id')); });
			});
			Array.prototype.forEach.call(el.drawerBody.querySelectorAll('[data-move-status]'), function (btn) {
				btn.addEventListener('click', function () {
					updateMove(btn.getAttribute('data-move-id'), { status: btn.getAttribute('data-move-status') });
				});
			});
		}

		el.drawerFooter.style.visibility = isPast ? 'hidden' : 'visible';
	}

	// Post-visit result shortcut; the Calendario page owns the modal that handles it.
	var VISIT_OUTCOMES = { thinking: 'Indeciso · volverá', not_closed: 'No concretada', no_show: 'No asistió', registered: '✓ Registrado como inquilino' };
	function visitDetails(ev) {
		if (ev.type !== 'visit' || !document.getElementById('af-modal-visit-outcome')) {
			return '';
		}
		var badge = VISIT_OUTCOMES[ev.outcome] ? escapeHtml(VISIT_OUTCOMES[ev.outcome]) + (ev.rating ? ' · ' + escapeHtml(ev.rating) : '') + ' · ' : '';
		return '<div class="af-cal__event-meta">' + badge +
			'<button type="button" class="af-cal__link" data-visit-outcome="' + ev.id + '" data-name="' + escapeHtml(ev.title) +
			'" data-accommodation="' + escapeHtml(ev.accommodation || '') + '">' +
			(ev.outcome === 'registered' ? 'Ver resultado' : '¿Cómo terminó la visita?') + '</button></div>';
	}

	// Checklist + contact of a move-in, editable in place from the agenda.
	function moveDetails(ev) {
		if (ev.type !== 'checkin') {
			return '';
		}
		var html = '';
		if (ev.phone) {
			html += '<div class="af-cal__event-meta">📞 ' + escapeHtml(ev.phone) + '</div>';
		}
		if (ev.notes) {
			html += '<div class="af-cal__event-meta">' + escapeHtml(ev.notes) + '</div>';
		}
		if (ev.checklist && ev.checklist.length) {
			html += '<ul class="af-cal__checklist">';
			ev.checklist.forEach(function (task) {
				html += '<li><label><input type="checkbox" data-move-task="' + escapeHtml(task.key) + '" data-move-id="' + ev.id + '"' +
					(task.done ? ' checked' : '') + ' /> ' + escapeHtml(task.label) + '</label></li>';
			});
			html += '</ul>';
		}
		html += ev.status === 'done'
			? '<button type="button" class="af-cal__link" data-move-status="scheduled" data-move-id="' + ev.id + '">✓ Realizada · Reabrir</button>'
			: '<button type="button" class="af-cal__link" data-move-status="done" data-move-id="' + ev.id + '">Marcar mudanza como realizada</button>';
		return html;
	}

	function saveMoveTasks(moveId) {
		var done = Array.prototype.filter.call(
			el.drawerBody.querySelectorAll('[data-move-task][data-move-id="' + moveId + '"]'),
			function (box) { return box.checked; }
		).map(function (box) { return box.getAttribute('data-move-task'); });
		updateMove(moveId, { tasks_done: done.join(',') });
	}

	function updateMove(moveId, data) {
		data.id = moveId;
		post('af_calendar_update_move', data).then(function (res) {
			if (res && res.success) {
				refreshFor(SELECTED_DATE);
			} else {
				window.alert((res && res.data && res.data.message) ? res.data.message : 'No se pudo actualizar la mudanza.');
			}
		});
	}

	function removeEvent(type, id) {
		if (!window.confirm('¿Quitar este registro del calendario?')) {
			return;
		}
		var actions = { block: 'af_calendar_remove_block', checkin: 'af_calendar_remove_move', visit: 'af_calendar_remove_visit' };
		var action = actions[type];
		if (!action) {
			return;
		}
		post(action, { id: id }).then(function (res) {
			if (res && res.success) {
				refreshFor(SELECTED_DATE);
			} else {
				window.alert((res && res.data && res.data.message) ? res.data.message : 'No se pudo quitar el registro.');
			}
		});
	}

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', afDashboardCalendar.nonce);
		Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
		return fetch(afDashboardCalendar.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); }).catch(function () {
			return { success: false, data: { message: 'Error de conexión.' } };
		});
	}

	function setLoading(on) {
		STATE.loading = on;
		el.grid.style.opacity = on ? '.45' : '1';
		el.prev.disabled = on;
		el.next.disabled = on;
	}

	function reload(nextYear, nextMonth) {
		if (STATE.loading) {
			return false;
		}
		if (nextYear !== undefined && nextMonth !== undefined) {
			STATE.year = nextYear;
			STATE.month = nextMonth;
		}
		setLoading(true);
		fetchEvents(STATE.year, STATE.month, function () {
			render();
			setLoading(false);
		});
		return true;
	}

	// Prev / next / today nav -------------------------------------------------
	if (el.prev) {
		el.prev.addEventListener('click', function () {
			var m = STATE.month - 1;
			var y = STATE.year;
			if (m === 0) { m = 12; y = y - 1; }
			reload(y, m);
		});
	}
	if (el.next) {
		el.next.addEventListener('click', function () {
			var m = STATE.month + 1;
			var y = STATE.year;
			if (m === 13) { m = 1; y = y + 1; }
			reload(y, m);
		});
	}
	if (el.todayBtn) {
		el.todayBtn.addEventListener('click', function () {
			reload(today.getFullYear(), today.getMonth() + 1);
		});
	}

	// After saving, bring the target day's month into view so the new event is
	// visible immediately (e.g. a day that belongs to an adjacent month).
	function gotoDate(dateStr) {
		if (!dateStr) { reload(); return; }
		var parts = dateStr.split('-');
		var y = parseInt(parts[0], 10);
		var m = parseInt(parts[1], 10);
		if (y === STATE.year && m === STATE.month) {
			reload();
		} else {
			reload(y, m);
		}
	}

	// Modal: open on add actions ------------------------------------------------
	function openModal(dateStr, type) {
		if (dateStr < todayStr) {
			dateStr = todayStr;
		}
		SELECTED_DATE = dateStr;
		el.modalDate.textContent = formatLongDate(dateStr);
		if (el.dateInput) { el.dateInput.value = dateStr; }
		el.modalType.value = type || 'visit';
		el.status.textContent = '';
		el.status.className = 'af-cal__status';
		toggleTypeFields();
		el.modal.removeAttribute('hidden');
		el.modal.style.display = 'flex';

		// Default the accommodation to the currently-selected filter when possible.
		var filterSelect = document.querySelector('select[name="af_accommodation_id"]');
		if (filterSelect && el.accSelect) {
			var match = Array.prototype.find.call(el.accSelect.options, function (o) { return o.value === filterSelect.value; });
			if (match) { el.accSelect.value = filterSelect.value; }
		}
	}

	function closeModal() {
		el.modal.setAttribute('hidden', '');
		el.modal.style.display = 'none';
	}

	var SUBMIT_LABELS = { visit: 'Agendar visita', move: 'Registrar mudanza', block: 'Bloquear día' };

	function toggleTypeFields() {
		if (!el.visitFields || !el.blockFields) { return; }
		var type = el.modalType.value;
		el.visitFields.style.display = type === 'visit' ? 'block' : 'none';
		if (el.moveFields) { el.moveFields.style.display = type === 'move' ? 'block' : 'none'; }
		if (el.leaseField) { el.leaseField.hidden = type !== 'move'; }
		el.blockFields.style.display = type === 'block' ? 'block' : 'none';
		el.submitBtn.textContent = SUBMIT_LABELS[type] || SUBMIT_LABELS.visit;
	}

	if (el.modalType) {
		el.modalType.addEventListener('change', toggleTypeFields);
	}

	if (el.dateInput) {
		el.dateInput.addEventListener('change', function () {
			if (el.dateInput.value) { el.modalDate.textContent = formatLongDate(el.dateInput.value); }
		});
	}

	// Picking a contract fills the property, tenant and contact in one go.
	if (el.leaseSelect) {
		el.leaseSelect.addEventListener('change', function () {
			var opt = el.leaseSelect.options[el.leaseSelect.selectedIndex];
			if (!opt || !opt.value) { return; }
			var acc = opt.getAttribute('data-accommodation');
			if (acc && Array.prototype.some.call(el.accSelect.options, function (o) { return o.value === acc; })) {
				el.accSelect.value = acc;
			}
			var fill = { 'af-cal-move-name': 'data-name', 'af-cal-move-phone': 'data-phone', 'af-cal-move-email': 'data-email' };
			Object.keys(fill).forEach(function (id) {
				var input = document.getElementById(id);
				if (input) { input.value = opt.getAttribute(fill[id]) || ''; }
			});
			var start = opt.getAttribute('data-start');
			if (start && start >= todayStr && el.dateInput) {
				el.dateInput.value = start;
				el.modalDate.textContent = formatLongDate(start);
			}
		});
	}

	if (el.drawerFooter) {
		el.drawerFooter.querySelector('[data-cal-add]').addEventListener('click', function () {
			if (SELECTED_DATE) { openModal(SELECTED_DATE, 'visit'); }
		});
		var moveBtn = el.drawerFooter.querySelector('[data-cal-move]');
		if (moveBtn) {
			moveBtn.addEventListener('click', function () {
				if (SELECTED_DATE) { openModal(SELECTED_DATE, 'move'); }
			});
		}
		el.drawerFooter.querySelector('[data-cal-block]').addEventListener('click', function () {
			if (SELECTED_DATE) { openModal(SELECTED_DATE, 'block'); }
		});
	}

	// Shortcut buttons outside the calendar (e.g. "Registrar mudanza").
	Array.prototype.forEach.call(document.querySelectorAll('[data-cal-open]'), function (btn) {
		btn.addEventListener('click', function () {
			openModal(SELECTED_DATE && SELECTED_DATE >= todayStr ? SELECTED_DATE : todayStr, btn.getAttribute('data-cal-open'));
		});
	});

	// Type toggles (legend) ----------------------------------------------------
	var toggles = container.querySelectorAll('[data-cal-toggle]');
	function paintToggles() {
		Array.prototype.forEach.call(toggles, function (btn) {
			var off = !!hiddenTypes[btn.getAttribute('data-cal-toggle')];
			btn.classList.toggle('is-off', off);
			btn.setAttribute('aria-pressed', off ? 'false' : 'true');
		});
	}
	Array.prototype.forEach.call(toggles, function (btn) {
		btn.addEventListener('click', function () {
			var type = btn.getAttribute('data-cal-toggle');
			if (hiddenTypes[type]) {
				delete hiddenTypes[type];
			} else {
				hiddenTypes[type] = true;
			}
			try {
				window.localStorage.setItem(HIDDEN_KEY, JSON.stringify(Object.keys(hiddenTypes)));
			} catch (e) { /* storage unavailable: keep in memory only */ }
			paintToggles();
			render();
		});
	});
	paintToggles();

	// Modal close handlers.
	var closeButtons = el.modal.querySelectorAll('[data-af-cal-close]');
	Array.prototype.forEach.call(closeButtons, function (btn) {
		btn.addEventListener('click', closeModal);
	});

	// Submit -------------------------------------------------------------------
	if (el.form) {
		el.form.addEventListener('submit', function (e) {
			e.preventDefault();

			var date = (el.dateInput && el.dateInput.value) || SELECTED_DATE;
			if (!date) {
				setStatus('Elige una fecha.', true);
				return;
			}

			if (date < todayStr) {
				setStatus('No se pueden programar eventos en fechas pasadas.', true);
				return;
			}

			var type = el.modalType.value;
			var acc = el.accSelect.value;
			var lease = (type === 'move' && el.leaseSelect) ? el.leaseSelect.value : '';

			if (!acc && !lease) {
				setStatus('Elige un inmueble.', true);
				return;
			}

			if (type === 'move') {
				var val = function (id) { return (document.getElementById(id) || {}).value || ''; };
				if (!val('af-cal-move-name').trim()) {
					setStatus('Escribe el nombre de quien se muda.', true);
					return;
				}
				var tasks = Array.prototype.filter.call(el.form.querySelectorAll('input[name="af-cal-move-task"]'), function (b) { return b.checked; })
					.map(function (b) { return b.value; });
				el.submitBtn.disabled = true;
				post('af_calendar_add_move', {
					accommodation_id: acc,
					lease_id: lease,
					date: date,
					start_time: val('af-cal-move-start'),
					end_time: val('af-cal-move-end'),
					contact_name: val('af-cal-move-name'),
					contact_phone: val('af-cal-move-phone'),
					contact_email: val('af-cal-move-email'),
					tasks: tasks.join(','),
					notes: val('af-cal-move-notes')
				}).then(function (res) {
					el.submitBtn.disabled = false;
					if (res && res.success) {
						afterSave(res.data.date);
					} else {
						setStatus((res && res.data && res.data.message) || 'No se pudo registrar la mudanza.', true);
					}
				});
			} else if (type === 'visit') {
				var name = (el.form.querySelector('#af-cal-guest-name') || {}).value || '';
				var time = (el.form.querySelector('#af-cal-visit-time') || {}).value || '10:00';
				var email = (el.form.querySelector('#af-cal-guest-email') || {}).value || '';
				var phone = (el.form.querySelector('#af-cal-guest-phone') || {}).value || '';
				var notes = (el.form.querySelector('#af-cal-visit-notes') || {}).value || '';
				if (!name.trim()) {
					setStatus('Escribe el nombre del visitante.', true);
					return;
				}
				el.submitBtn.disabled = true;
				post('af_calendar_add_visit', {
					accommodation_id: acc,
					date: date,
					time: time,
					guest_name: name,
					guest_email: email,
					guest_phone: phone,
					notes: notes
				}).then(function (res) {
					el.submitBtn.disabled = false;
					if (res && res.success) {
						setStatus(res.data.message || 'Visita agendada.', false);
						afterSave(res.data.date);
					} else {
						setStatus((res && res.data && res.data.message) || 'No se pudo agendar la visita.', true);
					}
				});
			} else {
				var reason = (el.form.querySelector('#af-cal-block-reason') || {}).value || '';
				el.submitBtn.disabled = true;
				post('af_calendar_add_block', {
					accommodation_id: acc,
					date: date,
					reason: reason
				}).then(function (res) {
					el.submitBtn.disabled = false;
					if (res && res.success) {
						setStatus(res.data.message || 'Día bloqueado.', false);
						closeModal();
						gotoDate(res.data.date);
					} else {
						setStatus((res && res.data && res.data.message) || 'No se pudo bloquear el día.', true);
					}
				});
			}
		});
	}

	function setStatus(msg, isError) {
		el.status.textContent = msg;
		el.status.className = 'af-cal__status' + (isError ? ' is-error' : ' is-success');
	}

	// The lists under the grid are server-rendered for the filtered range, so a
	// new event inside that range needs a page refresh to show up there too.
	function afterSave(dateStr) {
		closeModal();
		refreshFor(dateStr);
	}

	function refreshFor(dateStr) {
		var cols = document.querySelector('.af-calendar-cols[data-from][data-to]');
		if (cols && dateStr && dateStr >= cols.getAttribute('data-from') && dateStr <= cols.getAttribute('data-to')) {
			window.location.reload();
			return;
		}
		gotoDate(dateStr);
	}

	// Init ---------------------------------------------------------------------
	fetchEvents(STATE.year, STATE.month, render);
})();