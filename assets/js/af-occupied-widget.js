/**
 * Arriendo Fácil — Disponible/Ocupado switch (widget).
 *
 * Reusable across the plugin admin (catalog, leases, dashboard).
 * Marks an accommodation as occupied (simple toggle) or frees it, running the
 * early-termination flow when an active lease exists.
 *
 * Markup:
 *   <span class="af-occupied-widget" data-af-occupied="123" data-state="1">
 *     <button ...></button>
 *   </span>
 *
 * @package Arriendo_Facil
 */
(function () {
	'use strict';

	var cfg = window.afOccupiedCfg || {};
	if (!cfg.ajaxUrl || !cfg.nonce) {
		return;
	}

	var modalEl = null;

	function post(body) {
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (r) {
			return r.json();
		});
	}

	function i18n(key, fallback) {
		return (cfg.i18n && cfg.i18n[key]) || fallback;
	}

	function setBusy(widget, busy) {
		widget.classList.toggle('is-busy', busy);
	}

	function setState(widget, occupied) {
		widget.setAttribute('data-state', occupied ? '1' : '0');
		var btn = widget.querySelector('.af-occupied-widget__btn');
		var label = widget.querySelector('.af-occupied-widget__label');
		if (btn) {
			btn.setAttribute('aria-pressed', occupied ? 'true' : 'false');
		}
		if (label) {
			label.textContent = occupied ? i18n('occupied', 'Ocupada') : i18n('available', 'Disponible');
		}
		widget.classList.toggle('is-occupied', occupied);
	}

	/* ── Modal for unoccupy confirmation (active lease termination) ── */
	function buildModal() {
		if (modalEl) {
			return;
		}
		modalEl = document.createElement('div');
		modalEl.className = 'af-occupied-modal';
		modalEl.innerHTML =
			'<div class="af-occupied-modal__backdrop"></div>' +
			'<div class="af-occupied-modal__box" role="dialog" aria-modal="true" aria-labelledby="af-occ-modal-title">' +
			'<h2 id="af-occ-modal-title">' + i18n('unoccupyTitle', 'Liberar propiedad') + '</h2>' +
			'<p class="af-occupied-modal__warning">' + i18n('unoccupyWarning', 'Si existe un contrato activo, será terminado anticipadamente y el inmueble quedará disponible.') + '</p>' +
			'<label class="af-occupied-modal__label" for="af-occ-reason">' + i18n('reasonLabel', 'Motivo (obligatorio si hay contrato activo):') + '</label>' +
			'<textarea id="af-occ-reason" rows="3" placeholder="' + i18n('reasonPlaceholder', 'Ej: Acuerdo mutuo, venta del inmueble, otro...') + '"></textarea>' +
			'<p class="af-occupied-modal__feedback" aria-live="polite"></p>' +
			'<div class="af-occupied-modal__actions">' +
			'<button type="button" class="button af-occupied-modal__cancel">' + i18n('btnCancel', 'Cancelar') + '</button>' +
			'<button type="button" class="button af-occupied-modal__confirm">' + i18n('btnConfirm', 'Confirmar') + '</button>' +
			'</div>' +
			'</div>';
		document.body.appendChild(modalEl);

		modalEl.querySelector('.af-occupied-modal__backdrop').addEventListener('click', closeModal);
		modalEl.querySelector('.af-occupied-modal__cancel').addEventListener('click', closeModal);
		document.addEventListener('keydown', function (e) {
			if ('Escape' === e.key && modalEl && !modalEl.classList.contains('is-hidden')) {
				closeModal();
			}
		});
	}

	function openModal(widget) {
		buildModal();
		modalEl._widget = widget;
		modalEl.querySelector('#af-occ-reason').value = '';
		modalEl.querySelector('.af-occupied-modal__feedback').textContent = '';
		modalEl.querySelector('.af-occupied-modal__confirm').disabled = false;
		modalEl.classList.remove('is-hidden');
		setTimeout(function () {
			var r = modalEl.querySelector('#af-occ-reason');
			if (r) {
				r.focus();
			}
		}, 30);
	}

	function closeModal() {
		if (modalEl) {
			modalEl.classList.add('is-hidden');
		}
	}

	/* ── Events ── */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.af-occupied-widget .af-occupied-widget__btn') : null;
		if (!btn) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		var widget = btn.closest('.af-occupied-widget');
		if (!widget || widget.classList.contains('is-busy')) {
			return;
		}

		var postId = parseInt(widget.getAttribute('data-af-occupied'), 10);
		var occupied = '1' === widget.getAttribute('data-state');
		if (!postId) {
			return;
		}

		// Occupy → direct toggle.
		if (!occupied) {
			setBusy(widget, true);
			var body = new FormData();
			body.append('action', cfg.action || 'af_toggle_occupied');
			body.append('nonce', cfg.nonce);
			body.append('post_id', postId);
			body.append('occupied', 1);
			post(body)
				.then(function (res) {
					if (!res || !res.success) {
						throw new Error((res && res.data && res.data.message) || i18n('error', 'No se pudo actualizar.'));
					}
					setState(widget, true);
				})
				.catch(function (err) {
					window.alert(err.message || i18n('error', 'No se pudo actualizar.'));
				})
				.finally(function () {
					setBusy(widget, false);
				});
			return;
		}

		// Free → confirm (early-terminate first, then toggle meta).
		openModal(widget);
	});

	document.addEventListener('click', function (e) {
		if (!modalEl || !e.target.classList.contains('af-occupied-modal__confirm')) {
			return;
		}
		var widget = modalEl._widget;
		if (!widget) {
			return;
		}
		var reason = modalEl.querySelector('#af-occ-reason').value.trim();
		var fbEl = modalEl.querySelector('.af-occupied-modal__feedback');
		var confirmBtn = modalEl.querySelector('.af-occupied-modal__confirm');

		fbEl.textContent = '';
		confirmBtn.disabled = true;
		confirmBtn.textContent = i18n('processing', 'Procesando…');
		setBusy(widget, true);

		var postId = parseInt(widget.getAttribute('data-af-occupied'), 10);

		var early = new FormData();
		early.append('action', 'af_early_terminate_lease');
		early.append('nonce', cfg.leaseNonce || '');
		early.append('accommodation_id', postId);
		early.append('reason', reason !== '' ? reason : 'Liberado por el administrador/propietario.');

		post(early)
			.then(function (res) {
				if (!res || !res.success) {
					var msg = (res && res.data && res.data.message) || i18n('error', 'No se pudo actualizar.');
					fbEl.textContent = msg;
					confirmBtn.disabled = false;
					confirmBtn.textContent = i18n('btnConfirm', 'Confirmar');
					setBusy(widget, false);
					return;
				}

				var toggleBody = new FormData();
				toggleBody.append('action', cfg.action || 'af_toggle_occupied');
				toggleBody.append('nonce', cfg.nonce);
				toggleBody.append('post_id', postId);
				toggleBody.append('occupied', 0);

				return post(toggleBody)
					.then(function () {
						closeModal();
						setState(widget, false);
						setBusy(widget, false);
						var msg = (res.data && res.data.message) || i18n('available', 'Disponible');
						window.location.reload();
						return undefined;
					})
					.catch(function () {
						closeModal();
						setState(widget, false);
						setBusy(widget, false);
						window.location.reload();
						return undefined;
					});
			})
			.catch(function () {
				fbEl.textContent = i18n('error', 'No se pudo actualizar.');
				confirmBtn.disabled = false;
				confirmBtn.textContent = i18n('btnConfirm', 'Confirmar');
				setBusy(widget, false);
			});
	});
})();