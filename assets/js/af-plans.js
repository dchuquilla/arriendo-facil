/**
 * Arriendo Fácil — planes: modal de mejora, bloqueos, límites y cambio/cancelación de plan.
 */
(function () {
	'use strict';

	var cfg = window.afPlans || {};
	var i18n = cfg.i18n || {};
	var STORE_KEY = 'afPlanToast';
	var busy = false;

	/* ---------- Toasts ---------- */

	var stack = null;

	function toast(type, message) {
		if (!stack) {
			stack = document.createElement('div');
			stack.className = 'af-plan-toasts';
			stack.setAttribute('aria-live', 'polite');
			document.body.appendChild(stack);
		}

		var el = document.createElement('div');
		el.className = 'af-plan-toast af-plan-toast--' + type;
		el.setAttribute('role', type === 'error' ? 'alert' : 'status');

		var icon = document.createElement('span');
		icon.className = 'af-plan-toast__icon';
		icon.setAttribute('aria-hidden', 'true');
		icon.textContent = type === 'error' ? '!' : '\u2713';

		var text = document.createElement('p');
		text.textContent = message;

		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'af-plan-toast__close';
		close.setAttribute('aria-label', i18n.close || 'Cerrar');
		close.innerHTML = '&times;';

		el.appendChild(icon);
		el.appendChild(text);
		el.appendChild(close);
		stack.appendChild(el);

		var timer = null;
		function dismiss() {
			clearTimeout(timer);
			el.classList.remove('is-in');
			setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 250);
		}
		close.addEventListener('click', dismiss);
		requestAnimationFrame(function () { el.classList.add('is-in'); });
		timer = setTimeout(dismiss, type === 'error' ? 8000 : 5000);
	}

	function flashOnNextLoad(type, message) {
		try { sessionStorage.setItem(STORE_KEY, JSON.stringify({ type: type, message: message })); } catch (e) { /* storage unavailable */ }
	}

	function showPending() {
		try {
			var raw = sessionStorage.getItem(STORE_KEY);
			if (raw) {
				sessionStorage.removeItem(STORE_KEY);
				var data = JSON.parse(raw);
				toast(data.type, data.message);
			}
		} catch (e) { /* ignore */ }
	}

	/* ---------- Modal ---------- */

	var modal = document.getElementById('af-plan-modal');
	var dialog = modal ? modal.querySelector('.af-plan-modal__dialog') : null;
	var lastFocus = null;
	var onConfirm = null;

	function closeModal() {
		if (!modal || modal.hidden) { return; }
		modal.classList.remove('is-open');
		onConfirm = null;
		setTimeout(function () { modal.hidden = true; if (queue.length) { showNext(); } }, 180);
		if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* element gone */ } }
	}

	/**
	 * @param {Object} o { tone, title, text, meter:{used,limit}, primaryLabel, primaryHref, onConfirm, secondaryLabel }
	 */
	function openModal(o) {
		if (!modal) { return; }
		var primary = modal.querySelector('.af-plan-modal__primary');
		var secondary = modal.querySelector('.af-plan-modal__actions [data-af-plan-close]');
		var meter = modal.querySelector('.af-plan-modal__meter');

		modal.setAttribute('data-tone', o.tone || 'gate');
		modal.querySelector('#af-plan-modal-title').textContent = o.title || '';
		modal.querySelector('#af-plan-modal-text').textContent = o.text || '';
		secondary.textContent = o.secondaryLabel || i18n.notNow;
		secondary.hidden = !!o.hideSecondary;
		primary.textContent = o.primaryLabel || i18n.viewPlans;
		primary.disabled = false;

		if (o.meter && o.meter.limit) {
			var pct = Math.min(100, Math.round(o.meter.used / o.meter.limit * 100));
			meter.hidden = false;
			meter.querySelector('i').style.width = pct + '%';
			meter.querySelector('.af-plan-modal__meter-label').textContent = String(i18n.usage || '%1$d / %2$d').replace('%1$d', o.meter.used).replace('%2$d', o.meter.limit);
		} else {
			meter.hidden = true;
		}

		onConfirm = o.onConfirm || null;
		primary.onclick = function () {
			if (onConfirm) {
				onConfirm(primary);
			} else if (o.primaryHref && o.newTab) {
				window.open(o.primaryHref, '_blank', 'noopener,noreferrer');
				closeModal();
			} else if (o.primaryHref) {
				window.location.href = o.primaryHref;
			}
		};

		lastFocus = document.activeElement;
		modal.hidden = false;
		requestAnimationFrame(function () { modal.classList.add('is-open'); });
		dialog.focus();
	}

	function openGate(gate) {
		if (!gate) { return; }
		openModal({
			tone: 'gate',
			title: gate.title,
			text: gate.message,
			meter: gate.type === 'limit' ? { used: gate.used, limit: gate.limit } : null,
			primaryLabel: i18n.viewPlans,
			primaryHref: gate.url || cfg.planUrl
		});
	}

	/* ---------- Animaciones (GSAP, con respaldo sin él) ---------- */

	var gsap = window.gsap;
	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function animateFills(root) {
		if (!gsap || reduced) { return; }
		Array.prototype.forEach.call((root || document).querySelectorAll('[data-af-fill]'), function (bar) {
			var pct = parseFloat(bar.getAttribute('data-af-fill')) || 0;
			gsap.fromTo(bar, { width: '0%' }, { width: pct + '%', duration: 0.9, ease: 'power3.out', delay: 0.1, overwrite: true });
		});
	}

	function watchUserMenu() {
		var panel = document.getElementById('af-user-menu-panel');
		var mini = panel && panel.querySelector('.af-plan-mini');
		if (!panel || !mini || !gsap || reduced || !window.MutationObserver) { return; }

		new MutationObserver(function () {
			if (panel.hidden) { return; }
			gsap.fromTo(mini, { y: 10, opacity: 0, scale: 0.97 }, { y: 0, opacity: 1, scale: 1, duration: 0.4, ease: 'back.out(1.6)', clearProps: 'transform,opacity' });
			animateFills(mini);
		}).observe(panel, { attributes: true, attributeFilter: ['hidden'] });
	}

	function animateRings() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-af-ring]'), function (ring) {
			var pct = parseFloat(ring.getAttribute('data-af-ring')) || 0;
			if (!gsap || reduced) { ring.style.setProperty('--p', pct); return; }
			var state = { p: 0 };
			gsap.to(state, {
				p: pct, duration: 1.1, ease: 'power3.out', delay: 0.15,
				onUpdate: function () { ring.style.setProperty('--p', state.p.toFixed(1)); }
			});
		});
	}

	/* ---------- Estado del plan: avisos de cambio y de renovación ---------- */

	var queue = [];

	function showNext() {
		var next = queue.shift();
		if (next) { next(); }
	}

	function planNotice(notice, fromHeartbeat) {
		if (!notice) { return; }
		queue.push(function () {
			openModal({
				tone: notice.tone === 'danger' ? 'danger' : 'confirm',
				title: notice.title,
				text: notice.text,
				primaryLabel: fromHeartbeat ? i18n.planChanged : i18n.understood,
				secondaryLabel: fromHeartbeat ? i18n.later : null,
				onConfirm: function () { window.location.reload(); }
			});
		});
		if (modal && modal.hidden) { showNext(); }
	}

	function initPlanState() {
		var state = cfg.state;
		if (!state) { return; }

		if (state.notice) {
			// Se muestra tal cual y se queda en la página: ya cargó con el plan nuevo.
			queue.push(function () {
				openModal({
					tone: state.notice.tone === 'danger' ? 'danger' : 'confirm',
					title: state.notice.title,
					text: state.notice.text,
					primaryLabel: i18n.understood,
					hideSecondary: true,
					onConfirm: closeModal
				});
			});
		}

		if (state.renewal) {
			queue.push(function () {
				openModal({
					tone: 'gate',
					title: state.renewal.title,
					text: state.renewal.text,
					primaryLabel: i18n.sendProof,
					primaryHref: state.renewal.url,
					newTab: true,
					secondaryLabel: i18n.later
				});
			});
		}

		if (!cfg.boot) { setTimeout(showNext, 600); }
	}

	// Cambios hechos mientras el usuario trabaja: Heartbeat (sin consultas extra si no hay cambios).
	if (window.jQuery) {
		window.jQuery(document).on('heartbeat-send', function (_e, data) {
			if (cfg.state) { data.af_plan_rev = cfg.state.rev; }
		});
		window.jQuery(document).on('heartbeat-tick', function (_e, data) {
			if (data && data.af_plan) {
				if (cfg.state) { cfg.state.rev = data.af_plan.rev; }
				planNotice(data.af_plan.notice, true);
			}
		});
	}

	/* ---------- Peticiones ---------- */

	function post(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (key) {
			if (Array.isArray(data[key])) {
				data[key].forEach(function (v) { body.append(key + '[]', v); });
			} else {
				body.set(key, data[key]);
			}
		});

		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) { return res.json().catch(function () { return null; }); })
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || i18n.networkError);
				}
				return json.data;
			});
	}

	function act(action, data, trigger) {
		if (busy) { return; }
		busy = true;
		if (trigger) { trigger.disabled = true; trigger.setAttribute('aria-busy', 'true'); }

		post(action, data).then(function (res) {
			flashOnNextLoad('success', res.message);
			window.location.reload();
		}).catch(function (err) {
			busy = false;
			if (trigger) { trigger.disabled = false; trigger.removeAttribute('aria-busy'); }
			closeModal();
			toast('error', err && err.message ? err.message : i18n.networkError);
		});
	}

	/* ---------- Eventos ---------- */

	// Capture phase: stops the original handler of a locked control before it runs.
	document.addEventListener('click', function (event) {
		var el = event.target.closest && event.target.closest('[data-af-plan-lock],[data-af-plan-gate]');
		if (!el) { return; }

		var gate = null;
		if (el.hasAttribute('data-af-plan-gate')) {
			try { gate = JSON.parse(el.getAttribute('data-af-plan-gate')); } catch (e) { gate = null; }
		} else {
			gate = (cfg.gates || {})[el.getAttribute('data-af-plan-lock')];
		}
		if (!gate) { return; }

		event.preventDefault();
		event.stopPropagation();
		event.stopImmediatePropagation();
		openGate(gate);
	}, true);

	document.addEventListener('click', function (event) {
		var target = event.target;
		if (!target.closest) { return; }

		if (target.closest('[data-af-plan-close]')) {
			closeModal();
			return;
		}

		var choose = target.closest('[data-af-plan-choose]');
		if (choose) {
			var plan = choose.getAttribute('data-af-plan-choose');
			var label = choose.getAttribute('data-plan-label');
			var price = choose.getAttribute('data-plan-price');
			openModal({
				tone: 'confirm',
				title: String(i18n.requestTitle || '').replace('%s', label),
				text: plan === 'free'
					? i18n.toFreeText
					: String(i18n.requestText || '').replace('%1$s', label).replace('%2$s', price),
				primaryLabel: plan === 'free' ? i18n.toFreeConfirm : i18n.requestConfirm,
				secondaryLabel: i18n.back,
				onConfirm: function (btn) { act('af_plan_request', { plan: plan }, btn); }
			});
			return;
		}

		var cancel = target.closest('[data-af-plan-cancel]');
		if (cancel) {
			openModal({
				tone: 'danger',
				title: String(i18n.cancelTitle || '').replace('%s', cancel.getAttribute('data-plan-label')),
				text: i18n.cancelText,
				primaryLabel: i18n.cancelConfirm,
				secondaryLabel: i18n.keepPlan,
				onConfirm: function (btn) { act('af_plan_cancel', {}, btn); }
			});
			return;
		}

		var cancelRequest = target.closest('[data-af-plan-cancel-request]');
		if (cancelRequest) {
			act('af_plan_cancel_request', {}, cancelRequest);
		}
	});

	document.addEventListener('change', function (event) {
		var select = event.target.closest && event.target.closest('.af-plan-select');
		if (!select) { return; }
		select.disabled = true;
		post('af_plan_set', { user_id: select.getAttribute('data-user-id'), plan: select.value }).then(function (res) {
			flashOnNextLoad('success', res.message);
			window.location.reload();
		}).catch(function (err) {
			select.disabled = false;
			toast('error', err && err.message ? err.message : i18n.networkError);
		});
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') { closeModal(); }
	});

	/* Respuestas AJAX de otros módulos con un bloqueo de plan (HTTP 403 + plan_gate). */
	if (window.jQuery) {
		window.jQuery(document).ajaxComplete(function (_event, xhr) {
			if (!xhr || xhr.status !== 403) { return; }
			try {
				var json = xhr.responseJSON || JSON.parse(xhr.responseText);
				if (json && json.data && json.data.plan_gate) { openGate(json.data.plan_gate); }
			} catch (e) { /* not JSON */ }
		});
	}

	if (window.fetch) {
		var nativeFetch = window.fetch;
		window.fetch = function () {
			return nativeFetch.apply(this, arguments).then(function (res) {
				if (res.status === 403) {
					res.clone().json().then(function (json) {
						if (json && json.data && json.data.plan_gate) { openGate(json.data.plan_gate); }
					}).catch(function () { /* not JSON */ });
				}
				return res;
			});
		};
	}

	cfg.openGate = openGate;
	cfg.toast = toast;
	window.afPlans = cfg;

	showPending();
	animateFills(document);
	animateRings();
	watchUserMenu();
	initPlanState();

	if (cfg.boot) { openGate(cfg.boot); }

	var targetCard = document.querySelector('.af-plan-card.is-target');
	if (targetCard && targetCard.scrollIntoView) {
		targetCard.scrollIntoView({ block: 'center', behavior: 'smooth' });
	}

	/* ---------- Panel maestro: renovar y número de WhatsApp ---------- */

	document.addEventListener('click', function (event) {
		var renew = event.target.closest && event.target.closest('.af-plan-renew');
		if (renew) {
			renew.disabled = true;
			post('af_plan_renew', { user_id: renew.getAttribute('data-user-id'), expires: renew.getAttribute('data-expires') || '0' }).then(function (res) {
				flashOnNextLoad('success', res.message);
				window.location.reload();
			}).catch(function (err) {
				renew.disabled = false;
				toast('error', err && err.message ? err.message : i18n.networkError);
			});
			return;
		}

		var save = event.target.closest && event.target.closest('[data-af-plan-save-wa]');
		if (save) {
			var input = document.getElementById('af-plan-wa-input');
			save.disabled = true;
			post('af_plan_save_whatsapp', { number: input ? input.value : '' }).then(function (res) {
				save.disabled = false;
				if (input) { input.value = ''; input.placeholder = res.masked; }
				toast('success', res.message);
			}).catch(function (err) {
				save.disabled = false;
				toast('error', err && err.message ? err.message : i18n.networkError);
			});
		}
	});
}());
