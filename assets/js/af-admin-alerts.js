/* Global helpers for the Arriendo Fácil operational alerts:
   top-bar bell dropdown + "Centro de alertas" page. No dependencies. */
(function () {
	'use strict';

	if (typeof window.afAlerts === 'undefined' || !window.afAlerts) {
		return;
	}

	var CONFIG = window.afAlerts;

	var ICONS = {
		info: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
		'circle-alert': '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>',
		'triangle-alert': '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
		'bell-off': '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.7 3A6 6 0 0 1 18 8a21.3 21.3 0 0 0 .6 5M17 17H3s3-2 3-9a4.7 4.7 0 0 1 .3-1.7M10.3 21a1.94 1.94 0 0 0 3.4 0"/><path d="m2 2 20 20"/></svg>',
		'chevron-right': '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>'
	};

	function iconSVG(name, size) {
		var svg = ICONS[name] || ICONS['circle-alert'];
		if (size && svg) {
			return svg.replace('width="16"', 'width="' + size + '"').replace('height="16"', 'height="' + size + '"');
		}
		return svg;
	}

	function api(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', CONFIG.nonce || '');
		if (data) {
			Object.keys(data).forEach(function (key) {
				body.append(key, data[key]);
			});
		}

		return fetch(CONFIG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	function setBadge(count) {
		document.querySelectorAll('[data-af-alerts-count]').forEach(function (el) {
			var n = parseInt(count, 10) || 0;
			el.textContent = n;
			if (n > 0) {
				el.hidden = false;
			} else {
				el.hidden = true;
			}
		});
	}

	function itemMarkup(item) {
		var sev = item.severity || 'info';
		var li = document.createElement('li');
		li.className = 'af-alerts-item af-alerts-item--' + sev;
		li.setAttribute('data-af-alert-id', String(item.id));
		li.setAttribute('role', 'listitem');

		li.innerHTML =
			'<span class="af-alerts-item__icon">' + iconSVG(item.icon || 'circle-alert', 16) + '</span>' +
			'<div class="af-alerts-item__body">' +
			'<span class="af-alerts-item__title"></span>' +
			(item.message ? '<span class="af-alerts-item__message"></span>' : '') +
			'</div>' +
			(item.url ? '<a class="af-alerts-item__open" href="' + item.url + '" aria-label="Abrir alerta">' + iconSVG('chevron-right', 14) + '</a>' : '');

		li.querySelector('.af-alerts-item__title').textContent = item.title || '';
		if (item.message) {
			li.querySelector('.af-alerts-item__message').textContent = item.message;
		}
		return li;
	}

	/* ── Top bar bell ─────────────────────────────────────────────────────── */

	var toggle = document.getElementById('af-alerts-toggle');
	var panel = document.getElementById('af-alerts-panel');
	var listEl = document.getElementById('af-alerts-list');

	function panelOpen() {
		return panel && !panel.hidden;
	}

	function openPanel() {
		if (!panel) { return; }
		panel.hidden = false;
		if (toggle) {
			toggle.setAttribute('aria-expanded', 'true');
		}
		refreshAlerts();
	}

	function closePanel() {
		if (!panel) { return; }
		panel.hidden = true;
		if (toggle) {
			toggle.setAttribute('aria-expanded', 'false');
		}
	}

	function flashNotice(message, kind) {
		var notice = document.getElementById('af-alerts-panel-notice');
		if (!notice) { return; }
		notice.hidden = false;
		notice.textContent = message;
		notice.className = 'af-alerts-panel__notice' + (kind === 'error' ? ' is-error' : ' is-success');
		setTimeout(function () {
			notice.hidden = true;
			notice.className = 'af-alerts-panel__notice';
		}, 3000);
	}

	function refreshAlerts() {
		if (!listEl) {
			if (panelOpen()) { refreshBadgeOnly(); }
			return;
		}

		api('af_alerts_get', { limit: 6 }).then(function (res) {
			if (!res || !res.success) { return; }
			var count = parseInt(res.data.unread, 10) || 0;
			setBadge(count);

			var items = res.data.items || [];
			listEl.innerHTML = '';

			if (!items.length) {
				var empty = document.createElement('li');
				empty.className = 'af-alerts-empty';
				empty.innerHTML = iconSVG('bell-off', 20) + '<span>No tienes alertas pendientes.</span>';
				listEl.appendChild(empty);
				updateMarkAllButton(true);
				return;
			}

			items.forEach(function (item) {
				listEl.appendChild(itemMarkup(item));
			});
			updateMarkAllButton(false);
		}).catch(function () {
			if (panelOpen()) { flashNotice('No se pudieron cargar las alertas.', 'error'); }
		});
	}

	function refreshBadgeOnly() {
		api('af_alerts_get', { limit: 1 }).then(function (res) {
			if (res && res.success) {
				setBadge(res.data.unread);
			}
		});
	}

	function updateMarkAllButton(disabled) {
		var btn = document.querySelector('.af-alerts-panel__mark-all');
		if (!btn) { return; }
		btn.disabled = !!disabled;
	}

	if (toggle && panel) {
		toggle.addEventListener('click', function () {
			if (panelOpen()) {
				closePanel();
			} else {
				openPanel();
			}
		});

		document.addEventListener('click', function (e) {
			if (panelOpen() && !e.target.closest('.af-alerts')) {
				closePanel();
			}
		});

		document.addEventListener('keydown', function (e) {
			if ('Escape' === e.key && panelOpen()) {
				closePanel();
			}
		});
	}

	document.addEventListener('click', function (e) {
		// Mark all read (dropdown + any page).
		var markAll = e.target.closest('[data-af-alerts-mark-all]');
		if (markAll) {
			e.preventDefault();
			markAll.disabled = true;
			api('af_alerts_mark_all_read', {}).then(function (res) {
				if (res && res.success) {
					setBadge(0);
					refreshAlerts();
					flashNotice('Todas las alertas marcadas como leídas.');
				}
			}).catch(function () { markAll.disabled = false; });
			return;
		}

		// Mark one alert as read when opening it.
		var openLink = e.target.closest('.af-alerts-item__open');
		if (openLink) {
			var li = openLink.closest('.af-alerts-item');
			if (!li) { return; }
			var alertId = li.getAttribute('data-af-alert-id');
			if (alertId) {
				api('af_alerts_mark_read', { alert_id: alertId }).then(function (res) {
					if (res && res.success) {
						setBadge(res.data.unread);
					}
				});
			}
		}
	});

	/* ── Centro de alertas ─────────────────────────────────────────────────── */

	var settingsForm = document.getElementById('af-alerts-settings-form');
	if (settingsForm) {
		var settingsStatus = document.getElementById('af-alerts-settings-status');

		settingsForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var button = settingsForm.querySelector('button[type="submit"]');
			var enabledEl = settingsForm.elements.email_enabled;
			var emailEl = settingsForm.elements.email_address;
			if (!settingsStatus) { return; }

			settingsStatus.textContent = 'Guardando…';
			settingsStatus.className = 'af-alerts-center__status';

			button.disabled = true;

			api('af_alerts_save_settings', {
				enabled: enabledEl.checked ? '1' : '0',
				email: emailEl.value
			}).then(function (res) {
				button.disabled = false;
				if (res && res.success) {
					settingsStatus.textContent = res.data.message || 'Preferencias guardadas.';
					settingsStatus.className = 'af-alerts-center__status is-success';
				} else {
					settingsStatus.textContent = (res && res.data && res.data.message) || 'No se pudo guardar.';
					settingsStatus.className = 'af-alerts-center__status is-error';
				}
			}).catch(function () {
				button.disabled = false;
				settingsStatus.textContent = 'Error de red.';
				settingsStatus.className = 'af-alerts-center__status is-error';
			});
		});
	}

	var sendButton = document.getElementById('af-alerts-send-reminder');
	if (sendButton) {
		sendButton.addEventListener('click', function () {
			var statusEl = document.getElementById('af-alerts-send-status');
			sendButton.disabled = true;
			if (statusEl) {
				statusEl.textContent = 'Enviando…';
				statusEl.className = 'af-alerts-center__status';
			}

			api('af_alerts_send_reminder', {}).then(function (res) {
				sendButton.disabled = false;
				if (!statusEl) { return; }
				if (res && res.success) {
					statusEl.textContent = res.data.message || 'Recordatorio enviado.';
					statusEl.className = 'af-alerts-center__status is-success';
					setBadge(0);
				} else {
					statusEl.textContent = (res && res.data && res.data.message) || 'No hay alertas por enviar.';
					statusEl.className = 'af-alerts-center__status is-error';
				}
			}).catch(function () {
				sendButton.disabled = false;
				if (statusEl) {
					statusEl.textContent = 'Error de red.';
					statusEl.className = 'af-alerts-center__status is-error';
				}
			});
		});
	}

	// Center page: mark one alert as read (button per item).
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-af-alerts-center-mark-read]');
		if (!btn) { return; }
		var alertId = btn.getAttribute('data-af-alerts-center-mark-read');
		var item = btn.closest('.af-alerts-center__item');
		btn.disabled = true;

		api('af_alerts_mark_read', { alert_id: alertId }).then(function (res) {
			if (res && res.success) {
				setBadge(res.data.unread);
				if (item) {
					item.classList.add('is-read');
					btn.remove();
				}
			} else {
				btn.disabled = false;
			}
		}).catch(function () { btn.disabled = false; });
	});

	// Center page: mark all read button reloads after confirmation.
	document.addEventListener('click', function (e) {
		var markAllCenter = e.target.closest('[data-af-alerts-center-mark-all]');
		if (!markAllCenter) { return; }
		markAllCenter.disabled = true;
		api('af_alerts_mark_all_read', {}).then(function (res) {
			if (res && res.success) {
				setBadge(0);
				setTimeout(function () { window.location.reload(); }, 250);
			} else {
				markAllCenter.disabled = false;
			}
		}).catch(function () { markAllCenter.disabled = false; });
	});

	// Refresh the badge once on load (keeps it truthful across page hops).
	refreshBadgeOnly();
})();