/**
 * Arriendo Fácil — Inmuebles: menús de tarjeta, modal de confirmación, papelera y toasts.
 */
(function () {
	'use strict';

	var cfg = window.afProperties || {};
	var i18n = cfg.i18n || {};
	var STORE_KEY = 'afPropToast';
	var busy = false;

	/* ---------- Toasts ---------- */

	var stack = null;

	function toast(type, message, duration) {
		if (!stack) {
			stack = document.createElement('div');
			stack.className = 'af-ptoasts';
			stack.setAttribute('aria-live', 'polite');
			document.body.appendChild(stack);
		}

		var el = document.createElement('div');
		el.className = 'af-ptoast af-ptoast--' + type;
		el.setAttribute('role', type === 'error' ? 'alert' : 'status');

		var icon = document.createElement('span');
		icon.className = 'af-ptoast__icon';
		icon.setAttribute('aria-hidden', 'true');
		icon.textContent = type === 'error' ? '!' : '\u2713';

		var text = document.createElement('p');
		text.textContent = message;

		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'af-ptoast__close';
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
			setTimeout(function () {
				if (el.parentNode) { el.parentNode.removeChild(el); }
			}, 250);
		}

		close.addEventListener('click', dismiss);
		requestAnimationFrame(function () { el.classList.add('is-in'); });
		timer = setTimeout(dismiss, duration || (type === 'error' ? 8000 : 5000));
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
		if (cfg.notice) { toast('success', cfg.notice); }
	}

	/* ---------- Modal ---------- */

	var modal = document.getElementById('af-pmodal');
	var dialog = modal ? modal.querySelector('.af-pmodal__dialog') : null;
	var pending = null;
	var lastFocus = null;

	function openModal(action, id, name) {
		if (!modal) { return; }
		var isDelete = action === 'delete';
		modal.querySelector('#af-pmodal-title').textContent = isDelete ? i18n.deleteTitle : i18n.trashTitle;
		modal.querySelector('.af-pmodal__name').textContent = name || '';
		modal.querySelector('#af-pmodal-text').textContent = isDelete ? i18n.deleteText : i18n.trashText;
		var confirm = modal.querySelector('[data-af-modal-confirm]');
		confirm.textContent = isDelete ? i18n.deleteConfirm : i18n.trashConfirm;
		confirm.disabled = false;
		modal.classList.toggle('is-permanent', isDelete);

		pending = { action: action, id: id };
		lastFocus = document.activeElement;
		modal.hidden = false;
		requestAnimationFrame(function () { modal.classList.add('is-open'); });
		dialog.focus();
	}

	function closeModal() {
		if (!modal || modal.hidden) { return; }
		modal.classList.remove('is-open');
		setTimeout(function () { modal.hidden = true; }, 180);
		pending = null;
		if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* element gone */ } }
	}

	/* ---------- Acciones ---------- */

	function setCount(key, value) {
		var node = document.querySelector('[data-af-count="' + key + '"]');
		if (node) { node.textContent = String(value); }
	}

	function applyCounts(counts) {
		Object.keys(counts || {}).forEach(function (key) { setCount(key, counts[key]); });
		var tab = document.querySelector('[data-af-tab="trash"]');
		if (tab && !tab.classList.contains('is-active')) {
			tab.hidden = !counts.trash;
		}
	}

	function removeCard(id, done) {
		var card = document.querySelector('.af-pc[data-property="' + id + '"]');
		if (!card) { done(); return; }
		card.classList.add('is-leaving');
		setTimeout(function () {
			card.parentNode.removeChild(card);
			done();
		}, 260);
	}

	function request(action, id) {
		var body = new URLSearchParams();
		body.set('action', 'af_property_' + action);
		body.set('nonce', cfg.nonce || '');
		body.set('post_id', String(id));

		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) { return res.json().catch(function () { return null; }); })
			.then(function (json) {
				if (!json) { throw new Error(i18n.networkError); }
				if (!json.success) {
					throw new Error((json.data && json.data.message) || i18n.networkError);
				}
				return json.data;
			});
	}

	function run(action, id, trigger) {
		if (busy) { return; }
		busy = true;
		if (trigger) { trigger.disabled = true; }

		request(action, id).then(function (data) {
			applyCounts(data.counts);
			closeModal();
			removeCard(id, function () {
				busy = false;
				if (!document.querySelector('.af-pc')) {
					flashOnNextLoad('success', data.message);
					window.location.reload();
					return;
				}
				toast('success', data.message);
			});
		}).catch(function (err) {
			busy = false;
			if (trigger) { trigger.disabled = false; }
			closeModal();
			toast('error', err && err.message ? err.message : i18n.networkError);
		});
	}

	/* ---------- Eventos ---------- */

	document.addEventListener('change', function (event) {
		var select = event.target.closest && event.target.closest('[data-af-autosubmit]');
		if (select && select.form) { select.form.submit(); }
	});

	document.addEventListener('click', function (event) {
		var target = event.target;
		if (!target.closest) { return; }

		var actionBtn = target.closest('[data-af-prop-action]');
		if (actionBtn) {
			var action = actionBtn.getAttribute('data-af-prop-action');
			var id = actionBtn.getAttribute('data-id');
			if (action === 'restore') {
				run('restore', id, actionBtn);
			} else {
				openModal(action, id, actionBtn.getAttribute('data-name'));
			}
			var openMenu = actionBtn.closest('.af-pc__more');
			if (openMenu) { openMenu.removeAttribute('open'); }
			return;
		}

		if (target.closest('[data-af-modal-confirm]') && pending) {
			var confirmBtn = target.closest('[data-af-modal-confirm]');
			run(pending.action === 'delete' ? 'delete' : 'trash', pending.id, confirmBtn);
			return;
		}

		if (target.closest('[data-af-modal-close]')) {
			closeModal();
			return;
		}

		// Un solo menú "⋯" abierto a la vez.
		var open = target.closest('.af-pc__more');
		Array.prototype.forEach.call(document.querySelectorAll('.af-pc__more[open]'), function (menu) {
			if (menu !== open) { menu.removeAttribute('open'); }
		});
	});

	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Escape') { return; }
		closeModal();
		Array.prototype.forEach.call(document.querySelectorAll('.af-pc__more[open]'), function (menu) {
			menu.removeAttribute('open');
		});
	});

	showPending();
}());
