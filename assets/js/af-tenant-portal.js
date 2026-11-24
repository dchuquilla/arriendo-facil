/**
 * Arriendo Fácil — Portal del inquilino: acceso por código y novedades.
 *
 *  - Paso 1: pedir el código (por correo, o directo si el enlace trae la referencia firmada).
 *  - Paso 2: escribir los 6 dígitos. Errores claros, intentos restantes, vencimiento.
 *  - Reenvío con cuenta regresiva (el servidor aplica la espera real).
 *  - Ya dentro: pestañas, reporte de novedad y salida.
 */
(function () {
	'use strict';

	var cfg = window.afTenantPortal || {};
	var i18n = cfg.i18n || {};
	var root = document.getElementById('af-tp');
	if (!root) { return; }

	function $(sel, ctx) { return (ctx || root).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }

	function post(action, fields) {
		var body = new URLSearchParams();
		body.append('action', action);
		Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then(function (r) {
			return r.json().catch(function () { return { success: false, data: {} }; });
		});
	}

	function show(el, message) {
		if (!el) { return; }
		el.textContent = message || '';
		el.hidden = !message;
	}

	function busy(form, on, label) {
		var btn = $('[data-tp-submit]', form);
		if (!btn) { return; }
		if (on) {
			btn.dataset.label = btn.textContent;
			btn.textContent = label;
		} else if (btn.dataset.label) {
			btn.textContent = btn.dataset.label;
		}
		btn.disabled = on;
	}

	/* ── Acceso ─────────────────────────────────────────────────────── */

	function initLogin() {
		var alertBox = $('[data-tp-alert]');
		var emailForm = $('[data-tp-step="email"]');
		var codeForm = $('[data-tp-step="code"]');
		if (!emailForm || !codeForm) { return; }

		var emailInput = $('input[name="email"]', emailForm);
		var codeInput = $('input[name="code"]', codeForm);
		var resendBtn = $('[data-tp-resend]', codeForm);
		var sentNote = $('[data-tp-sent]', codeForm);
		var currentEmail = '';
		var timer = null;

		function countdown(seconds) {
			window.clearInterval(timer);
			var left = Math.max(0, parseInt(seconds, 10) || 0);
			function paint() {
				if (left > 0) {
					resendBtn.disabled = true;
					resendBtn.textContent = (i18n.resendIn || '%s').replace('%s', left);
				} else {
					resendBtn.disabled = false;
					resendBtn.textContent = i18n.resend;
					window.clearInterval(timer);
				}
			}
			paint();
			timer = window.setInterval(function () { left -= 1; paint(); }, 1000);
		}

		function identity() {
			return cfg.ref ? { ref: cfg.ref } : { email: currentEmail };
		}

		function requestCode(isResend) {
			show(alertBox, '');
			if (!cfg.ref) {
				currentEmail = (emailInput.value || '').trim().toLowerCase();
				if (isResend !== true && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(currentEmail)) {
					show(alertBox, i18n.badEmail);
					emailInput.focus();
					return;
				}
			}

			var form = isResend === true ? codeForm : emailForm;
			busy(form, true, i18n.sending);
			post('af_tpa_request', identity()).then(function (res) {
				busy(form, false);
				if (!res.success) {
					show(alertBox, (res.data && res.data.message) || i18n.network);
					return;
				}
				var masked = res.data.masked || '';
				sentNote.textContent = masked ? (i18n.sentTo || '%s').replace('%s', masked) : i18n.sentGeneric;
				sentNote.hidden = false;
				emailForm.hidden = true;
				codeForm.hidden = false;
				codeInput.value = '';
				codeInput.focus();
				countdown(res.data.resend_in);
			}).catch(function () {
				busy(form, false);
				show(alertBox, i18n.network);
			});
		}

		emailForm.addEventListener('submit', function (e) { e.preventDefault(); requestCode(false); });
		resendBtn.addEventListener('click', function () { requestCode(true); });

		$('[data-tp-back]', codeForm).addEventListener('click', function () {
			window.clearInterval(timer);
			show(alertBox, '');
			codeForm.hidden = true;
			emailForm.hidden = false;
			if (emailInput) { emailInput.focus(); }
		});

		// Solo dígitos, con espacio visual opcional.
		codeInput.addEventListener('input', function () {
			codeInput.value = codeInput.value.replace(/\D+/g, '').slice(0, cfg.codeLength || 6);
			if (codeInput.value.length === (cfg.codeLength || 6)) { codeForm.requestSubmit(); }
		});

		codeForm.addEventListener('submit', function (e) {
			e.preventDefault();
			show(alertBox, '');
			var code = codeInput.value.replace(/\D+/g, '');
			if (code.length !== (cfg.codeLength || 6)) {
				show(alertBox, i18n.badCode);
				codeInput.focus();
				return;
			}

			var fields = identity();
			fields.code = code;
			busy(codeForm, true, i18n.verifying);
			post('af_tpa_verify', fields).then(function (res) {
				if (res.success) {
					window.location.href = res.data.redirect || window.location.pathname;
					return;
				}
				busy(codeForm, false);
				var data = res.data || {};
				show(alertBox, data.message || i18n.network);
				codeInput.value = '';
				if (data.code === 'expired' || data.code === 'locked') {
					countdown(0); // Puede pedir uno nuevo de inmediato.
				} else {
					codeInput.focus();
				}
			}).catch(function () {
				busy(codeForm, false);
				show(alertBox, i18n.network);
			});
		});

		// Con referencia firmada el correo ya es conocido: un solo toque para pedir el código.
		if (cfg.ref) { emailForm.querySelector('[data-tp-submit]').focus(); }
	}

	/* ── Portal ─────────────────────────────────────────────────────── */

	function initPortal() {
		var tabs = $$('[data-tp-tab]');
		var panels = $$('[data-tp-panel]');

		function open(name) {
			tabs.forEach(function (t) {
				var on = t.getAttribute('data-tp-tab') === name;
				t.classList.toggle('is-active', on);
				t.setAttribute('aria-selected', on ? 'true' : 'false');
			});
			panels.forEach(function (p) { p.hidden = p.getAttribute('data-tp-panel') !== name; });
			try { window.history.replaceState(null, '', '#' + name); } catch (e) { /* sin historial */ }
		}

		tabs.forEach(function (t) {
			t.addEventListener('click', function () { open(t.getAttribute('data-tp-tab')); });
		});
		var hash = (window.location.hash || '').replace('#', '');
		if (hash && tabs.some(function (t) { return t.getAttribute('data-tp-tab') === hash; })) { open(hash); }

		var logout = $('[data-tp-logout]');
		if (logout) {
			logout.addEventListener('click', function () {
				post('af_tpa_logout', { csrf: cfg.csrf }).then(function (res) {
					window.location.href = (res.data && res.data.redirect) || window.location.pathname;
				});
			});
		}

		var report = $('[data-tp-report]');
		if (!report) { return; }
		var alertBox = $('[data-tp-report-alert]');

		report.addEventListener('submit', function (e) {
			e.preventDefault();
			show(alertBox, '');
			alertBox.classList.remove('is-ok');

			var fields = { csrf: cfg.csrf };
			new FormData(report).forEach(function (v, k) { fields[k] = v; });
			if (String(fields.damage_details || '').trim().length < 10) {
				show(alertBox, report.querySelector('textarea').validationMessage || '');
				return;
			}

			busy(report, true, i18n.sending);
			post('af_tpa_report', fields).then(function (res) {
				busy(report, false);
				if (res.success) {
					alertBox.classList.add('is-ok');
					show(alertBox, i18n.reported);
					report.reset();
					window.setTimeout(function () { window.location.hash = 'maintenance'; window.location.reload(); }, 1200);
					return;
				}
				if (res.data && res.data.code === 'session') {
					window.location.reload();
					return;
				}
				show(alertBox, (res.data && res.data.message) || i18n.network);
			}).catch(function () {
				busy(report, false);
				show(alertBox, i18n.network);
			});
		});
	}

	if (root.getAttribute('data-state') === 'login') { initLogin(); } else { initPortal(); }
}());
