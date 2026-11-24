/**
 * Arriendo Fácil — Página pública para interesados.
 *
 *  - Validación en el navegador con mensajes por campo (el servidor vuelve a validar todo).
 *  - Envío por AJAX y, al terminar, abre WhatsApp hacia el gestor con el mensaje listo.
 *  - Movimiento con el GSAP del sitio (si está cargado): solo opacity/transform, sin
 *    layout thrashing; respeta "reducir movimiento" y, sin GSAP, todo se ve igual.
 */
(function () {
	'use strict';

	var cfg = window.afTenantPrereg || {};
	var i18n = cfg.i18n || {};
	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function ready(fn) {
		// Los scripts del pie ya corrieron cuando dispara DOMContentLoaded: GSAP (del tema) ya existe.
		if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); } else { fn(); }
	}

	ready(function () {
		var card = document.querySelector('[data-pr-form-card]');
		if (!card) { return; }

		var g = window.gsap && !reduced ? window.gsap : null;
		var form = card.querySelector('[data-pr-form]');
		var alertBox = card.querySelector('[data-pr-alert]');
		var done = document.querySelector('[data-pr-done]');

		/* ── Movimiento ─────────────────────────────────────────────── */

		function intro() {
			if (!g) { return; }
			var wide = window.matchMedia('(min-width: 900px)').matches;
			var clear = 'transform,opacity,visibility';
			var tl = g.timeline({ defaults: { ease: 'power3.out' } });

			tl.from('[data-afp-story]', { opacity: 0, x: wide ? -36 : 0, y: wide ? 0 : 24, duration: 0.7, clearProps: clear })
				.from('[data-afp-prop]', { opacity: 0, y: 18, duration: 0.55, clearProps: clear }, '-=0.4')
				.from('[data-afp-prop] img', { scale: 1.12, duration: 1.3, ease: 'power2.out', clearProps: 'transform' }, '<')
				.from('[data-afp-step]', { opacity: 0, x: -14, duration: 0.45, stagger: 0.09, clearProps: clear }, '-=0.9')
				.from('[data-pr-form-card]', { opacity: 0, x: wide ? 36 : 0, y: wide ? 0 : 28, duration: 0.7, clearProps: clear }, 0.1)
				.from('[data-afp-field]', { opacity: 0, y: 14, duration: 0.45, stagger: 0.06, clearProps: clear }, '-=0.45')
				.from('[data-pr-submit]', { opacity: 0, y: 10, duration: 0.4, clearProps: clear }, '-=0.2');
		}

		function shake(el) {
			if (!g || !el) { return; }
			g.fromTo(el, { x: 0 }, { x: 0, duration: 0.45, ease: 'none', keyframes: { x: [0, -8, 7, -5, 3, 0] }, overwrite: 'auto', clearProps: 'transform' });
		}

		function showDone() {
			card.hidden = true;
			done.hidden = false;
			if (done.scrollIntoView && window.matchMedia('(max-width: 899px)').matches) { done.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' }); }
			if (!g) { return; }
			g.from(done, { opacity: 0, y: 24, duration: 0.55, ease: 'power3.out', clearProps: 'transform,opacity' });
			g.from('[data-afp-badge]', { scale: 0, rotate: -25, duration: 0.7, delay: 0.15, ease: 'back.out(2.2)', clearProps: 'transform' });
			g.from(done.querySelectorAll('h2, p, a'), { opacity: 0, y: 12, duration: 0.4, stagger: 0.08, delay: 0.3, clearProps: 'transform,opacity' });
		}

		intro();

		/* ── Validación ─────────────────────────────────────────────── */

		function show(message) {
			alertBox.textContent = message || '';
			alertBox.hidden = !message;
			if (message) {
				shake(alertBox);
				if (alertBox.scrollIntoView) { alertBox.scrollIntoView({ block: 'nearest', behavior: reduced ? 'auto' : 'smooth' }); }
			}
		}

		if (!form) { return; }
		var submit = form.querySelector('[data-pr-submit]');
		var label = submit.querySelector('[data-pr-label]') || submit;

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
			var wrap = field && field.closest('.afp-field');
			if (!wrap) { return; }
			var was = wrap.classList.contains('is-invalid');
			wrap.classList.toggle('is-invalid', !!message);
			if (box) { box.textContent = message || ''; }
			field.setAttribute('aria-invalid', message ? 'true' : 'false');
			if (message && !was) { shake(wrap); }
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
			if (first) { first.focus({ preventScroll: false }); }
			return !first;
		}

		// El error desaparece en cuanto se corrige el campo.
		Object.keys(rules).forEach(function (name) {
			var field = form.querySelector('[name="' + name + '"]');
			if (!field || field.type === 'hidden') { return; }
			var recheck = function () {
				var wrap = field.closest('.afp-field');
				if (wrap && wrap.classList.contains('is-invalid')) { setError(name, rules[name](String(field.value || '').trim())); }
			};
			field.addEventListener('input', recheck);
			field.addEventListener('change', recheck);
		});

		/* ── Envío ──────────────────────────────────────────────────── */

		var sending = false;

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (sending) { return; }
			show('');

			if (!validate()) { return; }

			var read = form.querySelector('[name="consent_read"]');
			var agree = form.querySelector('[name="consent_agree"]');
			var consentBox = form.querySelector('.afp-consent');
			if ((read && !read.checked) || (agree && !agree.checked)) {
				if (consentBox) { consentBox.classList.add('is-invalid'); shake(consentBox); }
				show(i18n.errConsent);
				return;
			}
			if (consentBox) { consentBox.classList.remove('is-invalid'); }

			var body = new URLSearchParams();
			body.append('action', 'af_tenant_prereg_submit');
			new FormData(form).forEach(function (value, key) { body.append(key, value); });

			sending = true;
			submit.disabled = true;
			label.textContent = i18n.sending;

			fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body
			}).then(function (r) {
				return r.json().catch(function () { return { success: false, data: {} }; });
			}).then(function (res) {
				sending = false;
				submit.disabled = false;
				label.textContent = i18n.send;

				if (!res.success) {
					show((res.data && res.data.message) || i18n.network);
					return;
				}

				var data = res.data || {};
				showDone();

				var wa = done.querySelector('[data-pr-wa]');
				var text = done.querySelector('[data-pr-done-text]');

				if (data.whatsapp_url && /^https:\/\/wa\.me\//.test(data.whatsapp_url)) {
					wa.href = data.whatsapp_url;
					wa.hidden = false;
					text.textContent = i18n.opening;
					// Se abre solo; el botón queda por si el navegador lo bloquea.
					window.setTimeout(function () { window.location.href = data.whatsapp_url; }, 1400);
				} else {
					done.querySelector('[data-pr-nowa]').hidden = false;
					text.textContent = '';
				}
			}).catch(function () {
				sending = false;
				submit.disabled = false;
				label.textContent = i18n.send;
				show(i18n.network);
			});
		});
	});
}());
