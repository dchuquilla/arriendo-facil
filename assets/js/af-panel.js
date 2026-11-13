/**
 * Arriendo Fácil — Panel: coreografía GSAP y gráfico de ingresos.
 * Sin GSAP o con "reducir movimiento" el contenido ya está en su estado final.
 */
(function () {
	'use strict';

	var g = window.gsap;
	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function $$(selector) {
		return Array.prototype.slice.call(document.querySelectorAll(selector));
	}

	function formatMoney(value) {
		return '$' + value.toLocaleString('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function drawChart() {
		var dataEl = document.getElementById('af-panel-chart');
		var canvas = document.getElementById('af-p-revenue-chart');
		if (!dataEl || !canvas || typeof window.Chart === 'undefined') { return; }

		var data;
		try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }

		var ctx = canvas.getContext('2d');
		var gradient = ctx.createLinearGradient(0, 0, 0, canvas.parentNode.clientHeight || 260);
		gradient.addColorStop(0, '#00a884');
		gradient.addColorStop(1, 'rgba(0, 168, 132, 0.25)');

		new window.Chart(canvas, {
			type: 'bar',
			data: {
				labels: data.labels,
				datasets: [{
					data: data.values,
					backgroundColor: gradient,
					borderRadius: 10,
					borderSkipped: false,
					maxBarThickness: 44
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				animation: reduced ? false : { duration: 900, easing: 'easeOutQuart', delay: function (c) { return c.dataIndex * 80; } },
				plugins: {
					legend: { display: false },
					tooltip: { callbacks: { label: function (c) { return formatMoney(c.parsed.y); } } }
				},
				scales: {
					x: { grid: { display: false }, border: { display: false } },
					y: { beginAtZero: true, border: { display: false }, grid: { color: 'rgba(148, 163, 184, 0.2)' }, ticks: { callback: function (v) { return '$' + v; } } }
				}
			}
		});
	}

	function motion() {
		if (!g || reduced) { return; }

		var clear = 'transform,opacity,visibility';
		var tl = g.timeline({ defaults: { ease: 'power3.out' } });

		tl.from('.af-panel__head > *', { y: 14, opacity: 0, duration: 0.5, stagger: 0.08, clearProps: clear })
			.from('.af-pk', { y: 28, opacity: 0, scale: 0.97, duration: 0.6, stagger: 0.09, clearProps: clear }, '-=0.25')
			.from('.af-pcard', { y: 26, opacity: 0, duration: 0.6, stagger: 0.1, clearProps: clear }, '-=0.35')
			.from('.af-plist__item', { x: -12, opacity: 0, duration: 0.4, stagger: 0.05, clearProps: clear }, '-=0.5')
			.from('.af-pstart__step', { y: 12, opacity: 0, duration: 0.4, stagger: 0.08, clearProps: clear }, '-=0.4');

		$$('[data-pbar]').forEach(function (bar) {
			g.from(bar, { scaleX: 0, duration: 1.1, delay: 0.5, ease: 'power3.out', clearProps: 'transform' });
		});

		$$('[data-pring]').forEach(function (ring) {
			var target = parseFloat(ring.getAttribute('data-pring')) || 0;
			var state = { v: 0 };
			ring.style.setProperty('--pct', 0);
			g.to(state, {
				v: target,
				duration: 1.2,
				delay: 0.45,
				ease: 'power2.out',
				onUpdate: function () { ring.style.setProperty('--pct', state.v); },
				onComplete: function () { ring.style.setProperty('--pct', target); }
			});
		});

		$$('[data-pn]').forEach(function (el) {
			var target = parseFloat(el.getAttribute('data-pn')) || 0;
			var money = el.hasAttribute('data-pn-money');
			var finalText = el.textContent;
			if (target <= 0) { return; }

			var state = { v: 0 };
			g.to(state, {
				v: target,
				duration: 1.1,
				delay: 0.4,
				ease: 'power2.out',
				onUpdate: function () { el.textContent = money ? formatMoney(state.v) : String(Math.round(state.v)); },
				onComplete: function () { el.textContent = finalText; }
			});
		});

		$$('.af-pk--danger .af-pk__icon').forEach(function (icon) {
			g.to(icon, { scale: 1.12, duration: 0.9, repeat: -1, yoyo: true, ease: 'sine.inOut', delay: 1.5 });
		});
	}

	function init() {
		if (!document.querySelector('.af-panel')) { return; }
		drawChart();
		motion();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
