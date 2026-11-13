/**
 * Arriendo Fácil — asistente de inmueble: progreso visible y transición entre pasos (GSAP).
 * Solo observa las clases que ya pone admin-wizard.js; no cambia su lógica.
 */
(function () {
	'use strict';

	function init() {
	var root = document.querySelector('.af-wizard');
	if (!root) { return; }

	var g = window.gsap;
	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var isCreate = root.classList.contains('af-wizard--create');
	var steps = Array.prototype.slice.call(root.querySelectorAll('.af-wizard__step'));
	var titles = Array.prototype.slice.call(root.querySelectorAll('.af-wizard__step-label'));
	var header = root.querySelector('.af-wizard__header');
	if (!isCreate || !steps.length || !header) { return; }

	var progress = document.createElement('div');
	progress.className = 'af-wizard__progress';
	progress.innerHTML = '<div class="af-wizard__progress-meta"><strong></strong><span></span></div>' +
		'<div class="af-wizard__progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><i></i></div>';
	header.appendChild(progress);

	var label = progress.querySelector('strong');
	var hint = progress.querySelector('span');
	var bar = progress.querySelector('.af-wizard__progress-bar');
	var fill = bar.querySelector('i');
	var lastIndex = -1;

	function currentIndex() {
		for (var i = 0; i < steps.length; i++) {
			if (steps[i].classList.contains('is-current')) { return i; }
		}
		return 0;
	}

	function update() {
		var index = currentIndex();
		if (index === lastIndex) { return; }
		var first = lastIndex === -1;
		lastIndex = index;

		var pct = Math.round(((index + 1) / steps.length) * 100);
		label.textContent = 'Paso ' + (index + 1) + ' de ' + steps.length + (titles[index] ? ' · ' + titles[index].textContent.trim() : '');
		hint.textContent = index === steps.length - 1 ? 'Último paso' : 'Faltan ' + (steps.length - index - 1);
		bar.setAttribute('aria-valuenow', String(pct));

		if (!g || reduced) {
			fill.style.transform = 'scaleX(' + (pct / 100) + ')';
			return;
		}

		g.to(fill, { scaleX: pct / 100, duration: 0.6, ease: 'power3.out' });

		if (!first) {
			var fields = steps[index].querySelectorAll('.af-field, .af-prop-type-card, .af-summary__card');
			g.fromTo(steps[index], { opacity: 0, y: 18 }, { opacity: 1, y: 0, duration: 0.45, ease: 'power3.out', clearProps: 'opacity,transform' });
			if (fields.length) {
				g.from(Array.prototype.slice.call(fields, 0, 12), { opacity: 0, y: 14, duration: 0.4, stagger: 0.045, delay: 0.08, ease: 'power3.out', clearProps: 'opacity,transform' });
			}
		}
	}

	update();

	if ('MutationObserver' in window) {
		var observer = new MutationObserver(update);
		steps.forEach(function (step) {
			observer.observe(step, { attributes: true, attributeFilter: ['class'] });
		});
	}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
