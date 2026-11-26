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

		// wp_localize_script entrega los números como texto: se fuerza a número.
		var codeLen = parseInt(cfg.codeLength, 10) || 6;

		// Solo dígitos, con espacio visual opcional.
		codeInput.addEventListener('input', function () {
			codeInput.value = codeInput.value.replace(/\D+/g, '').slice(0, codeLen);
			if (codeInput.value.length === codeLen) { codeForm.requestSubmit(); }
		});

		codeForm.addEventListener('submit', function (e) {
			e.preventDefault();
			show(alertBox, '');
			var code = codeInput.value.replace(/\D+/g, '');
			if (code.length !== codeLen) {
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
		var names = tabs.map(function (t) { return t.getAttribute('data-tp-tab'); });

		/* Movimiento (GSAP autoalojado). Sin GSAP o con movimiento reducido no se oculta ni se mueve nada. */
		var g = window.gsap;
		var calm = !g || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
		var motion = {
			reveal: function (nodes) {
				if (calm || !nodes.length) { return; }
				g.fromTo(nodes,
					{ opacity: 0, y: 14 },
					{ opacity: 1, y: 0, duration: 0.42, ease: 'power3.out', stagger: 0.055, clearProps: 'opacity,transform' }
				);
			},
			revealPanel: function (panel) {
				if (calm || !panel) { return; }
				var children = Array.prototype.slice.call(panel.children);
				g.fromTo(panel,
					{ opacity: 0 },
					{ opacity: 1, duration: 0.22, ease: 'power2.out' }
				);
				g.fromTo(children,
					{ opacity: 0, y: 10 },
					{ opacity: 1, y: 0, duration: 0.36, ease: 'power3.out', stagger: 0.04, delay: 0.08, clearProps: 'opacity,transform' }
				);
			},
			count: function (nodes) {
				if (calm) { return; }
				nodes.forEach(function (n) {
					var end = parseInt(n.textContent.replace(/\D+/g, ''), 10);
					if (!end) { return; }
					var box = { v: 0 };
					g.to(box, {
						v: end,
						duration: 0.9,
						ease: 'power2.out',
						onUpdate: function () { n.textContent = Math.round(box.v); },
						onComplete: function () { n.textContent = end; }
					});
				});
			},
			statsEntrance: function (stats) {
				if (calm || !stats.length) { return; }
				g.fromTo(stats,
					{ opacity: 0, y: 20, scale: 0.96 },
					{ opacity: 1, y: 0, scale: 1, duration: 0.5, ease: 'back.out(1.4)', stagger: 0.07, clearProps: 'opacity,transform' }
				);
			},
			headEntrance: function (el) {
				if (calm || !el) { return; }
				g.fromTo(el,
					{ opacity: 0, y: -10 },
					{ opacity: 1, y: 0, duration: 0.4, ease: 'power3.out', clearProps: 'opacity,transform' }
				);
			}
		};

		function open(name, silent) {
			tabs.forEach(function (t) {
				var on = t.getAttribute('data-tp-tab') === name;
				t.classList.toggle('is-active', on);
				t.setAttribute('aria-selected', on ? 'true' : 'false');
			});
			var activePanel = null;
			panels.forEach(function (p) {
				var isActive = p.getAttribute('data-tp-panel') === name;
				p.hidden = !isActive;
				if (isActive) { activePanel = p; }
			});
			try { window.history.replaceState(null, '', '#' + name); } catch (e) { /* sin historial */ }
			if (!silent) { window.scrollTo({ top: 0, behavior: 'smooth' }); }
			motion.revealPanel(activePanel);
		}

		tabs.forEach(function (t) {
			t.addEventListener('click', function () { open(t.getAttribute('data-tp-tab')); });
		});
		$$('[data-tp-goto]').forEach(function (b) {
			b.addEventListener('click', function () { open(b.getAttribute('data-tp-goto')); });
		});
		var hash = (window.location.hash || '').replace('#', '');
		if (hash && names.indexOf(hash) !== -1) { open(hash, true); }

		var logout = $('[data-tp-logout]');
		if (logout) {
			logout.addEventListener('click', function () {
				post('af_tpa_logout', { csrf: cfg.csrf }).then(function (res) {
					window.location.href = (res.data && res.data.redirect) || window.location.pathname;
				});
			});
		}

		/* Vista previa de archivos y validación antes de enviar. */
		$$('[data-tp-files]').forEach(function (input) {
			var box = input.parentNode.querySelector('[data-tp-previews]');
			input.addEventListener('change', function () {
				if (!box) { return; }
				box.innerHTML = '';
				Array.prototype.forEach.call(input.files, function (f) {
					var chip = document.createElement('span');
					chip.className = 'af-tp-chip';
					if (/^image\//.test(f.type)) {
						var img = document.createElement('img');
						img.alt = '';
						img.width = 56; img.height = 56;
						img.src = URL.createObjectURL(f);
						img.onload = function () { URL.revokeObjectURL(img.src); };
						chip.appendChild(img);
					} else {
						chip.textContent = f.name.slice(0, 18);
					}
					box.appendChild(chip);
				});
			});
		});

		function checkFiles(form) {
			var input = $('[data-tp-files]', form);
			if (!input) { return ''; }
			if (input.files.length > (parseInt(cfg.maxFiles, 10) || 6)) { return i18n.tooMany; }
			var sum = 0;
			for (var i = 0; i < input.files.length; i++) {
				sum += input.files[i].size;
				if (sum > 20 * 1048576) { return i18n.tooBig; }
				if (input.files[i].size > (parseInt(cfg.maxBytes, 10) || 8388608)) { return i18n.tooBig; }
				if (!/^(image\/(jpeg|png|webp|heic|heif)|application\/pdf)$/.test(input.files[i].type)) { return i18n.badType; }
			}
			return '';
		}

		/* Diálogos (pago y documento). */
		function dialog(name) { return $('[data-tp-dialog="' + name + '"]'); }
		function openDialog(name, setup) {
			var d = dialog(name);
			if (!d) { return; }
			var form = $('form', d);
			form.reset();
			$$('[data-tp-previews]', form).forEach(function (b) { b.innerHTML = ''; });
			show($('[data-tp-msg]', form), '');
			setup(form, d);
			if (typeof d.showModal === 'function') { d.showModal(); } else { d.setAttribute('open', ''); }
		}
		$$('[data-tp-open-pay]').forEach(function (b) {
			b.addEventListener('click', function () {
				openDialog('pay', function (form, d) {
					form.elements.schedule_id.value = b.getAttribute('data-schedule');
					form.elements.period.value = b.getAttribute('data-period');
					form.elements.amount.value = b.getAttribute('data-amount') || '';
					$('[data-tp-dialog-label]', d).textContent = b.getAttribute('data-label');
				});
			});
		});
		$$('[data-tp-open-delivery]').forEach(function (b) {
			b.addEventListener('click', function () {
				openDialog('delivery', function (form, d) {
					form.elements.schedule_id.value = b.getAttribute('data-schedule');
					form.elements.period.value = b.getAttribute('data-period');
					form.elements.amount.value = b.getAttribute('data-amount') || '';
					$('[data-tp-dialog-label]', d).textContent = b.getAttribute('data-label');
				});
			});
		});
		$$('[data-tp-open-doc]').forEach(function (b) {
			b.addEventListener('click', function () {
				openDialog('doc', function (form, d) {
					form.elements.doc_type.value = b.getAttribute('data-type');
					$('[data-tp-dialog-label]', d).textContent = b.getAttribute('data-label');
				});
			});
		});
		$$('[data-tp-open-docs]').forEach(function (b) {
			b.addEventListener('click', function () {
				var d = dialog('docs');
				if (!d) { return; }
				if (typeof d.showModal === 'function') { d.showModal(); } else { d.setAttribute('open', ''); }
			});
		});
		$$('[data-tp-close]').forEach(function (b) {
			b.addEventListener('click', function () { var d = b.closest('dialog'); if (d) { d.close ? d.close() : d.removeAttribute('open'); } });
		});

		/* Lightbox: comprobantes de historial */
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-af-lightbox-src]');
			if (!btn) { return; }
			var src   = btn.getAttribute('data-af-lightbox-src');
			var isPdf = btn.getAttribute('data-af-lightbox-pdf') === '1';
			var lb    = dialog('lightbox');
			var body  = document.getElementById('af-tp-lightbox-body');
			if (!lb || !body) { return; }
			body.innerHTML = isPdf
				? '<embed src="' + src + '" type="application/pdf">'
				: '<img src="' + src + '" alt="">';
			if (typeof lb.showModal === 'function') { lb.showModal(); } else { lb.setAttribute('open', ''); }
		});

		/* Un solo manejador para todos los formularios del portal. */
		$$('form[data-tp-form]').forEach(function (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var msg = $('[data-tp-msg]', form);
				show(msg, '');
				msg.classList.remove('is-ok');

				var fileError = checkFiles(form);
				if (fileError) { show(msg, fileError); return; }
				var fileInput = $('[data-tp-files]', form);
				var action = form.getAttribute('data-tp-form');
				if (fileInput && fileInput.required && !fileInput.files.length) { show(msg, i18n.needFile); return; }

				var data = new FormData(form);
				data.append('action', action);
				data.append('csrf', cfg.csrf);
				if (e.submitter && e.submitter.name) { data.append(e.submitter.name, e.submitter.value); }

				var buttons = $$('[data-tp-submit]', form);
				buttons.forEach(function (b) { b.disabled = true; b.dataset.label = b.textContent; });
				if (e.submitter) { e.submitter.textContent = fileInput && fileInput.files.length ? i18n.uploading : i18n.saving; }

				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function (r) { return r.json().catch(function () { return { success: false, data: {} }; }); })
					.then(function (res) {
						buttons.forEach(function (b) { b.disabled = false; b.textContent = b.dataset.label; });
						if (res.success) {
							var reveal = form.getAttribute('data-tp-reveal');
							msg.classList.add('is-ok');
							show(msg, (res.data && res.data.message) || '');
							if (reveal) {
								var next = $(reveal);
								if (next) { next.hidden = false; var f = $('input', next); if (f) { f.focus(); } }
								return;
							}
							window.setTimeout(function () { window.location.reload(); }, 900);
							return;
						}
						if (res.data && res.data.code === 'session') { window.location.reload(); return; }
						show(msg, (res.data && res.data.message) || i18n.network);
					})
					.catch(function () {
						buttons.forEach(function (b) { b.disabled = false; b.textContent = b.dataset.label; });
						show(msg, i18n.network);
					});
			});
		});

		/* Aviso antes de que la sesión se cierre por inactividad. */
		var idle = $('[data-tp-idle]');
		if (idle) {
			var total = (parseInt(cfg.idleSeconds, 10) || 1800) * 1000;
			var timer = null;
			var arm = function () {
				window.clearTimeout(timer);
				idle.hidden = true;
				timer = window.setTimeout(function () { idle.hidden = false; }, Math.max(60000, total - 120000));
			};
			$('[data-tp-stay]', idle).addEventListener('click', function () {
				post('af_tpa_ping', { csrf: cfg.csrf }).then(function (res) {
					if (res.success) { arm(); } else { window.location.reload(); }
				});
			});
			arm();
		}

		/* Animaciones de entrada del portal */
		var headEl = $('.af-tp-head');
		motion.headEntrance(headEl);
		motion.statsEntrance($$('.af-tp-stat'));
		motion.reveal($$('.af-tp-tabs'));
		var firstPanel = $('[data-tp-panel]:not([hidden])');
		if (firstPanel) {
			window.setTimeout(function () { motion.revealPanel(firstPanel); }, 180);
		}
		window.setTimeout(function () { motion.count($$('[data-tp-count]')); }, 220);
	}

	if (root.getAttribute('data-state') === 'login') { initLogin(); } else { initPortal(); }
}());
