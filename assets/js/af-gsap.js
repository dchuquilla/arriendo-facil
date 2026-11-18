/**
 * Arriendo Fácil — Motor de movimiento (GSAP).
 *
 * Un solo lugar decide cómo se mueve el panel, para que todas las pantallas
 * se sientan iguales: rápidas, suaves y sin esconder nada.
 *
 *  - Entrada escalonada de cada pantalla: título, luego bloques, luego filas.
 *    Lo que está bajo el pliegue aparece al llegar con el scroll (IntersectionObserver).
 *  - Cifras que cuentan hasta su valor (enteros, porcentajes y dinero).
 *  - Barras de progreso que se llenan hasta su porcentaje.
 *  - Modales: en el teléfono suben como hoja; en escritorio aparecen con escala.
 *  - Respuesta al toque en botones, tarjetas y filas.
 *  - Transición entre pantallas con barra de progreso.
 *  - window.afMotion.reveal(nodes) / .pop(node) para contenido que llega luego.
 *
 * Sin GSAP o con prefers-reduced-motion no se oculta ni se mueve nada.
 */
(function () {
	'use strict';

	var g = window.gsap;
	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var phone = window.matchMedia('(max-width: 782px)');

	window.afMotion = {
		reveal: function () {},
		pop: function () {},
		enabled: false
	};

	if (!g || reduced) {
		return;
	}

	g.defaults({ ease: 'power3.out', duration: 0.45 });
	document.documentElement.classList.add('af-gsap');
	window.afMotion.enabled = true;

	var CLEAR = 'opacity,transform,visibility';

	function $$(selector, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	}

	function isShown(el) {
		return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length) && !el.closest('[hidden]');
	}

	function inViewport(el) {
		var r = el.getBoundingClientRect();
		return r.top < window.innerHeight * 0.95 && r.bottom > 0;
	}

	function outsideOverlays(el) {
		return !el.closest('.af-modal, .af-sheet, .af-alerts-panel, .af-user-menu__panel');
	}

	/* ── Entrada de la pantalla ─────────────────────────────────────── */

	var HEAD = '.af-page-header, .wrap > h1, .wrap > .wp-heading-inline, .af-dash-head, .af-greeting';
	var CARD = [
		'.af-kpi', '.af-property-card', '.af-catalog-card', '.af-maint-card', '.af-prov-card',
		'.af-sp-tile', '.af-exit-card', '.af-review-list__item', '.af-cobranza-alert', '.af-wiz-card',
		'.af-choice-card', '.af-pc', '.af-pnew', '.af-plan-card', '.af-plan-summary', '.af-plan-current',
		'.af-plan-meter', '.af-rv-card', '.af-integration-card', '.af-sri-status-card', '.af-pcard'
	].join(', ');
	var BLOCK = [
		'.af-section', '.af-split > *', '.af-info-strip', '.af-info-banner', '.af-status-tabs', '.af-tabs__nav',
		'.af-empty', '.af-semaforo', '.af-flow', '.af-steps', '.af-ptoolbar', '.af-pempty-state',
		'.af-plan-select', '.af-kpi-grid', '.wrap > .card', '.wrap > form', '.wrap > .notice',
		'.af-dash-grid > *'
	].join(', ');
	var ROWS = '.af-data-table tbody tr, .wp-list-table tbody tr';

	function collect(root) {
		var list = [];
		var push = function (el) {
			if (outsideOverlays(el) && isShown(el)) { list.push(el); }
		};

		$$(HEAD, root).forEach(push);

		$$(BLOCK, root).forEach(function (block) {
			// Un bloque que contiene tarjetas se queda quieto: se animan sus tarjetas.
			if (block.querySelector(CARD)) {
				var head = block.querySelector(':scope > .af-section__header, :scope > header, :scope > h2, :scope > h3');
				if (head) { push(head); }
				return;
			}
			push(block);
		});

		$$(CARD, root).forEach(push);

		var unique = list.filter(function (el, i) {
			return list.indexOf(el) === i && !list.some(function (other) { return other !== el && other.contains(el); });
		});

		unique.sort(function (a, b) {
			return a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
		});

		return unique;
	}

	function reveal(list, opts) {
		if (!list.length) { return; }
		opts = opts || {};
		var each = opts.each || 0.04;
		g.fromTo(list,
			{ opacity: 0, y: opts.y === undefined ? 12 : opts.y },
			{
				opacity: 1,
				y: 0,
				duration: opts.duration || 0.38,
				ease: 'power3.out',
				stagger: { each: each, amount: Math.min(opts.amount || 0.3, list.length * each) },
				delay: opts.delay || 0,
				clearProps: CLEAR,
				overwrite: 'auto'
			}
		);
	}

	function revealRows(root, delay) {
		$$('.af-data-table tbody, .wp-list-table tbody', root).forEach(function (tbody) {
			if (!outsideOverlays(tbody) || !isShown(tbody) || !inViewport(tbody)) { return; }
			var rows = $$(':scope > tr', tbody).filter(isShown).slice(0, 12);
			if (rows.length) {
				g.fromTo(rows, { opacity: 0, y: 6 }, { opacity: 1, y: 0, duration: 0.3, stagger: 0.025, delay: delay || 0.1, clearProps: CLEAR });
			}
		});
	}

	function runEntrance() {
		// Pantallas con coreografía propia (el Panel usa af-panel.js).
		if (document.querySelector('.wrap.af-panel')) { return; }

		var host = document.getElementById('wpbody-content') || document.body;
		var all = collect(host);
		var visible = all.filter(inViewport);
		var below = all.filter(function (el) { return visible.indexOf(el) === -1; });

		reveal(visible);
		revealRows(host, 0.1);

		if (!below.length || !('IntersectionObserver' in window)) { return; }

		// Lo que está bajo el pliegue se anima al entrar en pantalla (observador nativo:
		// sin cálculos en cada scroll). Los que entran juntos se escalonan.
		g.set(below, { opacity: 0, y: 14 });
		var pending = [];
		var flush = function () {
			g.to(pending, { opacity: 1, y: 0, duration: 0.38, stagger: 0.05, clearProps: CLEAR, overwrite: 'auto' });
			pending = [];
		};
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) { return; }
				io.unobserve(entry.target);
				if (!pending.length) { window.requestAnimationFrame(flush); }
				pending.push(entry.target);
			});
		}, { rootMargin: '0px 0px -6% 0px' });
		below.forEach(function (el) { io.observe(el); });
	}

	/* ── Cifras que cuentan ─────────────────────────────────────────── */

	// "$1,234.56", "1.234,56 $", "85%", "12": se cuenta solo si el texto es una cifra limpia.
	var FIGURE = /^([^\d\-]{0,3})(\d{1,3}(?:[.,]\d{3})*|\d+)([.,]\d{1,2})?(\s?%|\s?\$)?$/;

	function runCounters() {
		$$('.af-kpi__value, [data-af-count], .af-hero-value, .af-dash-kpi__value').forEach(function (el) {
			if (el.children.length || !outsideOverlays(el) || el.dataset.afCounted) { return; }
			var text = el.textContent.trim();
			var m = FIGURE.exec(text);
			if (!m) { return; }

			var prefix = m[1] || '';
			var intPart = m[2];
			var decPart = m[3] || '';
			var suffix = m[4] || '';
			var thousands = /[.,]/.test(intPart) ? intPart.replace(/\d/g, '').charAt(0) : (decPart.charAt(0) === ',' ? '.' : ',');
			var decimalSep = decPart ? decPart.charAt(0) : '';
			var decimals = decPart ? decPart.length - 1 : 0;
			var target = parseFloat(intPart.replace(/[.,]/g, '') + (decPart ? '.' + decPart.slice(1) : ''));
			if (!isFinite(target) || target < 2) { return; }

			var format = function (v) {
				var fixed = v.toFixed(decimals).split('.');
				var whole = fixed[0].replace(/\B(?=(\d{3})+(?!\d))/g, /[.,]/.test(intPart) ? thousands : '');
				return prefix + whole + (decimals ? decimalSep + fixed[1] : '') + suffix;
			};

			el.dataset.afCounted = '1';
			var start = function () {
				var obj = { v: 0 };
				el.textContent = format(0);
				g.to(obj, {
					v: target,
					duration: Math.min(1.4, 0.7 + String(Math.round(target)).length * 0.08),
					ease: 'power2.out',
					onUpdate: function () { el.textContent = format(obj.v); },
					onComplete: function () { el.textContent = text; }
				});
			};

			if (inViewport(el) || !('IntersectionObserver' in window)) {
				g.delayedCall(0.1, start);
			} else {
				var watch = new IntersectionObserver(function (entries) {
					if (entries[0].isIntersecting) { watch.disconnect(); start(); }
				});
				watch.observe(el);
			}
		});
	}

	/* ── Barras de progreso ─────────────────────────────────────────── */

	function runBars(root) {
		$$('.af-progress__bar, .af-kpi__bar > i, .af-hero-progress > i', root || document).forEach(function (bar) {
			if (!outsideOverlays(bar) && !root) { return; }
			var width = bar.style.width;
			if (!width || width === '0%' || bar.dataset.afFilled) { return; }
			bar.dataset.afFilled = '1';
			g.fromTo(bar, { width: '0%' }, { width: width, duration: 0.9, delay: 0.25, ease: 'power3.out' });
		});
	}

	/* ── Respuesta al toque ─────────────────────────────────────────── */

	var PRESSABLE = '.af-btn, .button, .af-kpi, .af-property-card, .af-catalog-card, .af-pc, .af-quick-list__link, .af-menu-group__link, .af-sheet__foot-link, .af-app-nav__link';

	function pressFeedback() {
		var held = null;

		document.addEventListener('pointerdown', function (event) {
			var el = event.target.closest(PRESSABLE);
			if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') { return; }
			held = el;
			var big = el.offsetWidth > 260;
			g.to(el, { scale: big ? 0.985 : 0.96, duration: 0.12, ease: 'power2.out', overwrite: 'auto' });
		}, { passive: true });

		var release = function () {
			if (!held) { return; }
			var el = held;
			held = null;
			g.to(el, { scale: 1, duration: 0.45, ease: 'elastic.out(1, 0.5)', overwrite: 'auto', clearProps: 'transform' });
		};

		document.addEventListener('pointerup', release, { passive: true });
		document.addEventListener('pointercancel', release, { passive: true });
		document.addEventListener('dragstart', release, { passive: true });
	}

	/* ── Modales ────────────────────────────────────────────────────── */

	function animateModalIn(modal) {
		var dialog = modal.querySelector('.af-modal__dialog, .af-modal__container, .af-modal__content');
		var backdrop = modal.querySelector('.af-modal__backdrop, .af-modal__overlay');

		if (backdrop) {
			g.fromTo(backdrop, { opacity: 0 }, { opacity: 1, duration: 0.25, clearProps: 'opacity', overwrite: 'auto' });
		}
		if (!dialog) { return; }

		if (phone.matches) {
			g.fromTo(dialog, { yPercent: 100 }, { yPercent: 0, duration: 0.5, ease: 'expo.out', clearProps: 'transform', overwrite: 'auto' });
		} else {
			g.fromTo(dialog,
				{ opacity: 0, y: 18, scale: 0.97 },
				{ opacity: 1, y: 0, scale: 1, duration: 0.42, ease: 'back.out(1.3)', clearProps: CLEAR, overwrite: 'auto' }
			);
		}

		var rows = $$('.af-modal__field, .af-field, .af-form-field, .af-choice, .af-cobranza-flow__step', dialog).filter(isShown).slice(0, 10);
		if (rows.length) {
			g.fromTo(rows, { opacity: 0, y: 10 }, { opacity: 1, y: 0, duration: 0.32, stagger: 0.035, delay: 0.12, clearProps: CLEAR });
		}
		runBars(dialog);
	}

	function watchModals() {
		if (!('MutationObserver' in window)) { return; }
		var seen = new WeakMap();

		var isOpen = function (modal) {
			return !modal.hidden && window.getComputedStyle(modal).display !== 'none';
		};

		var observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (m) {
				var modal = m.target;
				var open = isOpen(modal);
				if (open && !seen.get(modal)) { animateModalIn(modal); }
				seen.set(modal, open);
			});
		});

		$$('.af-modal').forEach(function (modal) {
			seen.set(modal, isOpen(modal));
			observer.observe(modal, { attributes: true, attributeFilter: ['class', 'hidden', 'style'] });
		});
	}

	/* ── Tarjetas que se despliegan (formularios "nuevo …") ─────────── */

	function watchInlineCards() {
		if (!('MutationObserver' in window)) { return; }
		var cards = $$('#af-lease-form-card, #af-guest-form-card, .af-maint-formcard, [data-af-animate-open]');
		cards.forEach(function (card) {
			var open = isShown(card);
			new MutationObserver(function () {
				var now = isShown(card);
				if (now && !open) {
					g.fromTo(card, { opacity: 0, y: -8 }, { opacity: 1, y: 0, duration: 0.4, clearProps: CLEAR });
					var fields = $$('.af-field, .af-form-field, tr, .af-field-group', card).filter(isShown).slice(0, 12);
					g.fromTo(fields, { opacity: 0, y: 8 }, { opacity: 1, y: 0, duration: 0.3, stagger: 0.03, delay: 0.08, clearProps: CLEAR });
				}
				open = now;
			}).observe(card, { attributes: true, attributeFilter: ['style', 'hidden', 'class'] });
		});
	}

	/* ── Pestañas ───────────────────────────────────────────────────── */

	function watchTabs() {
		document.addEventListener('change', function (event) {
			var radio = event.target;
			if (!radio.classList || !radio.classList.contains('af-tabs__radio') || !radio.id) { return; }
			var panel = document.querySelector('.af-tabs__panel[aria-labelledby="' + radio.id + '"]');
			if (!panel) { return; }
			g.fromTo(panel, { opacity: 0, y: 8 }, { opacity: 1, y: 0, duration: 0.32, clearProps: CLEAR });
			var kids = $$(CARD + ', .af-section, ' + ROWS, panel).filter(isShown).slice(0, 18);
			if (kids.length) {
				g.fromTo(kids, { opacity: 0, y: 10 }, { opacity: 1, y: 0, duration: 0.36, stagger: 0.03, delay: 0.04, clearProps: CLEAR });
			}
			runBars(panel);
		});

		document.addEventListener('click', function (event) {
			var tab = event.target.closest('.af-status-tabs__tab, .af-status-tabs__btn, [data-tab-group-target], .af-rv-tab, .af-maint-seg button');
			if (!tab) { return; }
			window.setTimeout(function () {
				var scope = tab.closest('.af-section, .wrap') || document;
				var rows = $$(ROWS + ', .af-sp-tile, .af-maint-card, .af-rv-card', scope).filter(isShown).slice(0, 16);
				if (rows.length) {
					g.fromTo(rows, { opacity: 0, y: 8 }, { opacity: 1, y: 0, duration: 0.3, stagger: 0.025, clearProps: CLEAR, overwrite: 'auto' });
				}
			}, 20);
		});
	}

	/* ── Transición entre pantallas ─────────────────────────────────── */

	function pageTransitions() {
		var bar = document.createElement('div');
		bar.id = 'af-nav-progress';
		bar.setAttribute('aria-hidden', 'true');
		document.body.appendChild(bar);

		var leaving = false;

		document.addEventListener('click', function (event) {
			if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
			var a = event.target.closest('a[href]');
			if (!a || leaving) { return; }
			if ((a.target && a.target !== '_self') || a.hasAttribute('download') || a.hasAttribute('data-no-transition')) { return; }
			if (a.closest('.af-modal, .af-alerts-panel') || a.hasAttribute('data-af-plan-lock')) { return; }

			var href = a.getAttribute('href') || '';
			if (!href || href.charAt(0) === '#' || /^(mailto:|tel:|javascript:)/i.test(href)) { return; }

			var url;
			try { url = new URL(a.href, window.location.href); } catch (e) { return; }
			if (url.origin !== window.location.origin || !/\/wp-admin\/(admin|edit|post|post-new|profile|index)\.php$/.test(url.pathname)) { return; }
			if (url.searchParams.has('action') || url.searchParams.has('af_download') || url.searchParams.has('_wpnonce')) { return; }
			if (url.pathname === window.location.pathname && url.search === window.location.search) { return; }

			var host = document.getElementById('wpbody-content');
			if (!host) { return; }

			event.preventDefault();
			leaving = true;

			// La navegación arranca ya: la animación corre mientras llega la página
			// siguiente, nunca la retrasa.
			window.location.href = url.href;

			var sheet = document.querySelector('.af-sheet:not([hidden])');
			g.fromTo(bar, { width: '0%', opacity: 1 }, { width: '78%', duration: 1.2, ease: 'power2.out' });
			g.to(host, { opacity: 0.35, y: phone.matches ? 6 : -4, duration: 0.18, ease: 'power2.in' });
			if (sheet) {
				g.to(sheet.querySelector('.af-sheet__panel'), { yPercent: 100, duration: 0.22, ease: 'power3.in' });
				g.to(sheet.querySelector('.af-sheet__scrim'), { opacity: 0, duration: 0.2 });
			}
		});

		window.addEventListener('pageshow', function (event) {
			leaving = false;
			var host = document.getElementById('wpbody-content');
			if (host) { g.set(host, { clearProps: CLEAR }); }
			g.set(bar, { width: 0, opacity: 0 });
			if (event.persisted) { runEntrance(); }
		});
	}

	/* ── Ayudantes públicos ─────────────────────────────────────────── */

	window.afMotion.reveal = function (nodes, opts) {
		var list = Array.prototype.slice.call(nodes || []).filter(function (n) { return n && n.nodeType === 1; });
		reveal(list, opts || { each: 0.035, y: 10, duration: 0.38, amount: 0.45 });
	};

	window.afMotion.pop = function (node) {
		if (node) {
			g.fromTo(node, { scale: 0.92, opacity: 0.4 }, { scale: 1, opacity: 1, duration: 0.45, ease: 'back.out(2)', clearProps: CLEAR });
		}
	};

	function watchDynamicLists() {
		if (!('MutationObserver' in window)) { return; }
		$$('[data-af-stagger]').forEach(function (list) {
			new MutationObserver(function (mutations) {
				var added = [];
				mutations.forEach(function (m) {
					Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) { added.push(n); } });
				});
				if (added.length) { window.afMotion.reveal(added); }
			}).observe(list, { childList: true });
		});
	}

	function init() {
		var shell = document.body.classList.contains('af-custom-shell');

		runEntrance();
		runCounters();
		runBars();
		watchModals();
		watchInlineCards();
		watchTabs();
		watchDynamicLists();
		pressFeedback();
		if (shell) { pageTransitions(); }
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
