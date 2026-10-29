/**
 * Arriendo Facil — Motion Layer (JS)
 *
 * Añade:
 *   1. Animación de cierre a los modales existentes (.af-modal) sin tocar su JS.
 *   2. IntersectionObserver para elementos marcados con .af-reveal.
 *
 * No tiene dependencias y respeta prefers-reduced-motion.
 */
(function () {
	'use strict';

	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	// ──────────────────────────────────────────────────────────────────
	// 1. Hook de cierre animado para modales .af-modal
	// Se intercepta cualquier intento de ocultar el modal con hidden=true
	// y se añade .is-closing + delay antes de ejecutar el hidden real.
	// ──────────────────────────────────────────────────────────────────
	if (!reduced) {
		var CLOSE_DELAY = 200;
		var patchedModals = new WeakSet();

		function patchModal(modal) {
			if (patchedModals.has(modal)) return;
			patchedModals.add(modal);

			var originalDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'hidden');
			if (!originalDescriptor) return;

			Object.defineProperty(modal, 'hidden', {
				configurable: true,
				get: function () {
					return originalDescriptor.get.call(this);
				},
				set: function (value) {
					if (value && !originalDescriptor.get.call(this)) {
						// Intento de cerrar: animar primero.
						var el = this;
						el.classList.add('is-closing');
						window.setTimeout(function () {
							originalDescriptor.set.call(el, true);
							el.classList.remove('is-closing');
						}, CLOSE_DELAY);
					} else {
						originalDescriptor.set.call(this, value);
					}
				}
			});
		}

		function scanModals() {
			var modals = document.querySelectorAll('.af-modal');
			for (var i = 0; i < modals.length; i++) {
				patchModal(modals[i]);
			}
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', scanModals);
		} else {
			scanModals();
		}

		// Nuevos modales que se añadan dinámicamente
		if ('MutationObserver' in window) {
			var mo = new MutationObserver(function (mutations) {
				for (var i = 0; i < mutations.length; i++) {
					var added = mutations[i].addedNodes;
					for (var j = 0; j < added.length; j++) {
						var node = added[j];
						if (node.nodeType !== 1) continue;
						if (node.classList && node.classList.contains('af-modal')) {
							patchModal(node);
						} else if (node.querySelectorAll) {
							var inner = node.querySelectorAll('.af-modal');
							for (var k = 0; k < inner.length; k++) {
								patchModal(inner[k]);
							}
						}
					}
				}
			});
			mo.observe(document.body, { childList: true, subtree: true });
		}
	}

	// ──────────────────────────────────────────────────────────────────
	// 2. Reveal on scroll para .af-reveal
	// ──────────────────────────────────────────────────────────────────
	if (!reduced && 'IntersectionObserver' in window) {
		function initReveal() {
			var observer = new IntersectionObserver(function (entries) {
				for (var i = 0; i < entries.length; i++) {
					if (entries[i].isIntersecting) {
						entries[i].target.classList.add('is-visible');
						observer.unobserve(entries[i].target);
					}
				}
			}, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

			var items = document.querySelectorAll('.af-reveal:not(.is-visible)');
			for (var i = 0; i < items.length; i++) {
				observer.observe(items[i]);
			}
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initReveal);
		} else {
			initReveal();
		}
	} else if (reduced) {
		// Reduced motion: mostrar todo inmediatamente
		var items = document.querySelectorAll('.af-reveal');
		for (var i = 0; i < items.length; i++) {
			items[i].classList.add('is-visible');
		}
	}
})();
