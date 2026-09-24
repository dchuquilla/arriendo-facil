/** global afDashboardCalendar */
/**
 * Interactive host calendar for the Panel.
 * Renders a month grid from AJAX events and lets the host register visits and
 * blocks by clicking a day (Airbnb-style host calendar).
 */
(function () {
	'use strict';

	var container = document.getElementById('af-interactive-calendar');
	if (!container || typeof afDashboardCalendar === 'undefined') {
		return;
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
		blockFields: document.getElementById('af-cal-block-fields'),
		submitBtn: document.getElementById('af-cal-submit')
	};

	var SELECTED_DATE = null;
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

		// Bind day clicks.
		Array.prototype.forEach.call(el.grid.querySelectorAll('.af-cal__day'), function (node) {
			node.addEventListener('click', function (e) {
				e.preventDefault();
				selectDay(node.getAttribute('data-date'));
			});
		});

		refreshDrawerForSelected();
	}

	function cell(dateStr, dayNum, outside, isToday) {
		var evs = STATE.events[dateStr] || [];
		var classes = 'af-cal__day' + (outside ? ' is-outside' : '') + (isToday ? ' is-today' : '');
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
			var type = evs[k].type || 'visit';
			chips += '<span class="af-cal__chip af-cal__chip--' + type + '">' +
				escapeHtml(evs[k].label || (evs[k].title ? evs[k].title : ep(type))) + '</span>';
			shown++;
		}

		return '<button type="button" class="' + classes + '" data-date="' + dateStr + '">' +
			'<span class="af-cal__day-num">' + dayNum + '</span>' +
			(chips ? '<span class="af-cal__chips">' + chips + '</span>' : '') +
			'</button>';
	}

	var TYPE_META = {
		visit: { icon: '👤', label: 'Visita' },
		checkin: { icon: '🔑', label: 'Check-in' },
		checkout: { icon: '🔒', label: 'Check-out' },
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

		el.drawerDate.textContent = SELECTED_DATE;
		el.drawerTitle.textContent = formatLongDate(SELECTED_DATE);

		var evs = STATE.events[SELECTED_DATE] || [];
		if (!evs.length) {
			el.drawerBody.innerHTML = '<p class="af-cal__drawer-empty">Sin eventos programados para hoy.</p>';
		} else {
			var html = '';
			evs.forEach(function (ev) {
				var meta = TYPE_META[ev.type] || { icon: '•', label: ev.type };
				html += '<div class="af-cal__event is-' + ev.type + '">' +
					'<span class="af-cal__event-icon" aria-hidden="true">' + meta.icon + '</span>' +
					'<div class="af-cal__event-main">' +
					'<div class="af-cal__event-title">' + escapeHtml(ev.title) + '</div>' +
					'<div class="af-cal__event-meta">' + escapeHtml(meta.label) +
					(ev.meta ? ' · ' + escapeHtml(ev.meta) : '') +
					(ev.accommodation ? ' · ' + escapeHtml(ev.accommodation) : '') +
					'</div></div>';
				if (ev.removable) {
					html += '<button type="button" class="af-cal__event-remove" data-remove-type="' + ev.type + '" data-remove-id="' + ev.id + '" title="Quitar">×</button>';
				}
				html += '</div>';
			});
			el.drawerBody.innerHTML = html;

			Array.prototype.forEach.call(el.drawerBody.querySelectorAll('.af-cal__event-remove'), function (btn) {
				btn.addEventListener('click', function () { removeEvent(btn.getAttribute('data-remove-type'), btn.getAttribute('data-remove-id')); });
			});
		}

		el.drawerFooter.style.visibility = 'visible';
	}

	function removeEvent(type, id) {
		if (!window.confirm('¿Quitar este registro del calendario?')) {
			return;
		}
		var action = type === 'block' ? 'af_calendar_remove_block' : 'af_calendar_remove_visit';
		post(action, { id: id }).then(function (res) {
			if (res && res.success) {
				reload();
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

	// Modal: open on add actions ------------------------------------------------
	function openModal(dateStr, type) {
		SELECTED_DATE = dateStr;
		el.modalDate.textContent = formatLongDate(dateStr);
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

	function toggleTypeFields() {
		if (!el.visitFields || !el.blockFields) { return; }
		var isVisit = el.modalType.value === 'visit';
		el.visitFields.style.display = isVisit ? 'block' : 'none';
		el.blockFields.style.display = isVisit ? 'none' : 'block';
		el.submitBtn.textContent = isVisit ? 'Agendar visita' : 'Bloquear día';
	}

	if (el.modalType) {
		el.modalType.addEventListener('change', toggleTypeFields);
	}

	if (el.drawerFooter) {
		el.drawerFooter.querySelector('[data-cal-add]').addEventListener('click', function () {
			if (SELECTED_DATE) { openModal(SELECTED_DATE, 'visit'); }
		});
		el.drawerFooter.querySelector('[data-cal-block]').addEventListener('click', function () {
			if (SELECTED_DATE) { openModal(SELECTED_DATE, 'block'); }
		});
	}

	// Modal close handlers.
	var closeButtons = el.modal.querySelectorAll('[data-af-cal-close]');
	Array.prototype.forEach.call(closeButtons, function (btn) {
		btn.addEventListener('click', closeModal);
	});

	// Submit -------------------------------------------------------------------
	if (el.form) {
		el.form.addEventListener('submit', function (e) {
			e.preventDefault();

			var date = SELECTED_DATE || el.modalDate.getAttribute('data-date');
			if (!date) { return; }

			var type = el.modalType.value;
			var acc = el.accSelect.value;

			if (!acc) {
				setStatus('Elige un inmueble.', true);
				return;
			}

			if (type === 'visit') {
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
						closeModal();
						reload();
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
						reload();
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

	// Init ---------------------------------------------------------------------
	fetchEvents(STATE.year, STATE.month, render);
	// Subscribe to filter form so the calendar reloads with the new scope.
	var filterForm = document.querySelector('.af-calendar-filters');
	if (filterForm) {
		filterForm.addEventListener('submit', function () {
			// The page reloads via GET; nothing more to do here.
		});
	}
})();