/**
 * Arriendo Fácil — Pre-registro público.
 * Envía el formulario por AJAX y, al terminar, abre WhatsApp hacia el gestor con
 * un mensaje listo para que le mande el enlace del formulario completo.
 */
(function () {
	'use strict';

	var cfg = window.afTenantPrereg || {};
	var i18n = cfg.i18n || {};
	var card = document.querySelector('[data-pr-form-card]');
	if (!card) { return; }

	var form = card.querySelector('[data-pr-form]');
	var alertBox = card.querySelector('[data-pr-alert]');
	var submit = card.querySelector('[data-pr-submit]');
	var done = document.querySelector('[data-pr-done]');

	function show(message) {
		alertBox.textContent = message || '';
		alertBox.hidden = !message;
		if (message) { alertBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
	}

	var rules = {
		first_name: function (v) { return /^[\p{L}\p{M}][\p{L}\p{M}\s'.\-]{1,79}$/u.test(v) ? '' : i18n.errName; },
		last_name: function (v) { return /^[\p{L}\p{M}][\p{L}\p{M}\s'.\-]{1,79}$/u.test(v) ? '' : i18n.errLast; },
		email: function (v) { return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v) ? '' : i18n.errEmail; },
		phone: function (v) { var d = v.replace(/\D+/g, ''); return d.length >= 7 && d.length <= 15 ? '' : i18n.errPhone; },
		accommodation_id: function (v) { return v ? '' : i18n.errProperty; }
	};

	function setError(name, message) {
		var field = form.querySelector('[name="' + name + '"]');
		var box = form.querySelector('[data-err-for="' + name + '"]');
		var wrap = field && field.closest('.af-pr__field');
		if (!wrap) { return; }
		wrap.classList.toggle('is-invalid', !!message);
		if (box) { box.textContent = message || ''; }
		field.setAttribute('aria-invalid', message ? 'true' : 'false');
	}

	function validate() {
		var first = null;
		Object.keys(rules).forEach(function (name) {
			var field = form.querySelector('[name="' + name + '"]');
			if (!field || field.type === 'hidden') { return; }
			var message = rules[name](String(field.value || '').trim());
			setError(name, message);
			if (message && !first) { first = field; }
		});
		if (first) { first.focus(); }
		return !first;
	}

	// El error desaparece en cuanto se corrige el campo.
	Object.keys(rules).forEach(function (name) {
		var field = form.querySelector('[name="' + name + '"]');
		if (field && field.type !== 'hidden') {
			field.addEventListener('input', function () { setError(name, rules[name](String(field.value || '').trim())); });
			field.addEventListener('change', function () { setError(name, rules[name](String(field.value || '').trim())); });
		}
	});

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		show('');

		if (!validate()) { return; }

		var read = form.querySelector('[name="consent_read"]');
		var agree = form.querySelector('[name="consent_agree"]');
		if ((read && !read.checked) || (agree && !agree.checked)) {
			show(i18n.errConsent);
			return;
		}

		var body = new URLSearchParams();
		body.append('action', 'af_tenant_prereg_submit');
		new FormData(form).forEach(function (value, key) { body.append(key, value); });

		submit.disabled = true;
		submit.textContent = i18n.sending;

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then(function (r) {
			return r.json().catch(function () { return { success: false, data: {} }; });
		}).then(function (res) {
			submit.disabled = false;
			submit.textContent = i18n.send;

			if (!res.success) {
				show((res.data && res.data.message) || i18n.network);
				return;
			}

			var data = res.data || {};
			card.hidden = true;
			done.hidden = false;
			done.scrollIntoView({ block: 'start' });

			var wa = done.querySelector('[data-pr-wa]');
			var text = done.querySelector('[data-pr-done-text]');

			if (data.whatsapp_url) {
				wa.href = data.whatsapp_url;
				wa.hidden = false;
				text.textContent = i18n.opening;
				// Se abre solo; el botón queda por si el navegador lo bloquea.
				window.setTimeout(function () { window.location.href = data.whatsapp_url; }, 1200);
			} else {
				done.querySelector('[data-pr-nowa]').hidden = false;
				text.textContent = '';
			}
		}).catch(function () {
			submit.disabled = false;
			submit.textContent = i18n.send;
			show(i18n.network);
		});
	});
}());
