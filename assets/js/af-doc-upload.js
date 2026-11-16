/**
 * Arriendo Fácil — Carga de documentos con tarjetas (arrastrar y soltar).
 *
 * Progresivo: sin GSAP todo funciona igual (solo sin animación). El <input>
 * real sigue enviando el formulario; aquí solo se refleja su estado.
 */
(function () {
	'use strict';

	var MAX_BYTES = 10 * 1024 * 1024;
	var g = window.gsap;
	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var animate = !!g && !reduced;

	function notify(message) {
		if (window.afNotify) { window.afNotify(message, 'error'); }
	}

	function formatSize(bytes) {
		if (bytes >= 1024 * 1024) { return (bytes / (1024 * 1024)).toFixed(1).replace('.', ',') + ' MB'; }
		return Math.max(1, Math.round(bytes / 1024)) + ' KB';
	}

	function isPdf(file) {
		return file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
	}

	function updateGroups(root) {
		root.querySelectorAll('[data-af-doc-group]').forEach(function (group) {
			var ok = Array.prototype.some.call(group.querySelectorAll('.af-doc__input'), function (i) {
				return i.files && i.files.length;
			});
			group.classList.toggle('is-ok', ok);
		});
	}

	function showFile(card, file) {
		var row = card.querySelector('.af-doc__file');
		card.querySelector('.af-doc__file-name').textContent = file.name;
		card.querySelector('.af-doc__file-size').textContent = formatSize(file.size);
		card.classList.remove('is-error');
		card.classList.add('is-filled');
		row.hidden = false;

		if (animate) {
			g.fromTo(card.querySelector('.af-doc__icon'), { scale: 0.7 }, { scale: 1, duration: 0.45, ease: 'back.out(2.2)', clearProps: 'transform' });
			g.fromTo(card.querySelector('.af-doc__check path'), { strokeDashoffset: 24 }, { strokeDashoffset: 0, duration: 0.4, delay: 0.1, ease: 'power2.out' });
			g.fromTo(row, { height: 0, opacity: 0 }, { height: 'auto', opacity: 1, duration: 0.3, clearProps: 'height,opacity' });
		}
	}

	function clearFile(card, input) {
		input.value = '';
		var row = card.querySelector('.af-doc__file');
		card.classList.remove('is-filled', 'is-error');
		if (animate && !row.hidden) {
			g.to(row, { height: 0, opacity: 0, duration: 0.2, onComplete: function () {
				row.hidden = true;
				g.set(row, { clearProps: 'height,opacity' });
			} });
		} else {
			row.hidden = true;
		}
	}

	function bind(card) {
		if (card.__afDoc) { return; }
		card.__afDoc = true;

		var input = card.querySelector('.af-doc__input');
		var root = card.closest('form') || document;

		input.addEventListener('change', function () {
			var file = input.files && input.files[0];
			if (!file) { clearFile(card, input); updateGroups(root); return; }

			if (!isPdf(file)) {
				clearFile(card, input);
				card.classList.add('is-error');
				notify('El archivo debe ser un PDF.');
			} else if (file.size > MAX_BYTES) {
				clearFile(card, input);
				card.classList.add('is-error');
				notify('El PDF supera el tamaño máximo (10 MB).');
			} else {
				showFile(card, file);
			}
			updateGroups(root);
		});

		['dragenter', 'dragover'].forEach(function (ev) {
			card.addEventListener(ev, function () { card.classList.add('is-drag'); });
		});
		['dragleave', 'drop', 'dragend'].forEach(function (ev) {
			card.addEventListener(ev, function () { card.classList.remove('is-drag'); });
		});

		card.querySelector('.af-doc__remove').addEventListener('click', function () {
			clearFile(card, input);
			updateGroups(root);
		});

		// Tarjeta condicional (extranjeros): entra con animación al mostrarse.
		if (card.hasAttribute('hidden') || card.classList.contains('af-guest-foreign-field')) {
			new MutationObserver(function () {
				if (!card.hidden && animate) {
					g.fromTo(card, { opacity: 0, y: 12 }, { opacity: 1, y: 0, duration: 0.4, clearProps: 'opacity,transform' });
				} else if (card.hidden) {
					clearFile(card, input);
					updateGroups(root);
				}
			}).observe(card, { attributes: true, attributeFilter: ['hidden'] });
		}
	}

	function init() {
		document.querySelectorAll('[data-af-doc]').forEach(bind);

		document.querySelectorAll('[data-af-docs]').forEach(function (wrap) {
			var form = wrap.closest('form');
			if (form) {
				form.addEventListener('reset', function () {
					setTimeout(function () {
						wrap.querySelectorAll('[data-af-doc]').forEach(function (card) {
							card.classList.remove('is-filled', 'is-error', 'is-drag');
							card.querySelector('.af-doc__file').hidden = true;
						});
						updateGroups(form);
					}, 0);
				});
			}

			// Entrada escalonada cuando el formulario (oculto al cargar) se abre.
			var host = wrap.closest('#af-guest-form-card');
			if (host && animate) {
				var played = false;
				new MutationObserver(function () {
					if (host.style.display !== 'none' && !played) {
						played = true;
						g.from(wrap.querySelectorAll('.af-doc:not([hidden]), .af-docs__group'), {
							opacity: 0, y: 16, duration: 0.45, stagger: 0.08, delay: 0.1, clearProps: 'opacity,transform'
						});
					} else if (host.style.display === 'none') {
						played = false;
					}
				}).observe(host, { attributes: true, attributeFilter: ['style'] });
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
