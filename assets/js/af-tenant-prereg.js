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

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		show('');

		if (!form.checkValidity()) {
			form.reportValidity();
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
