/**
 * Arriendo Fácil — GSAP motion engine for the admin.
 *
 * One place owns every animation so screens stay consistent and calm:
 *  - staggered entrance of headers, KPIs, cards and tiles (scroll-revealed below the fold)
 *  - count-up for plain numeric KPIs
 *  - modal open and tab panel switch
 *  - soft page transition (+ progress bar) between admin screens
 *  - window.afMotion.reveal(nodes) for content rendered later by JS
 *
 * If GSAP is missing or the user prefers reduced motion nothing is hidden or
 * moved, so the CSS fallback in af-motion.css keeps working.
 */
(function () {
	'use strict';

	var g = window.gsap;
	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	window.afMotion = {
		reveal: function () {},
		pop: function () {},
		enabled: false
	};

	if (!g || reduced) {
		return;
	}

	if (window.ScrollTrigger) {
		g.registerPlugin(window.ScrollTrigger);
	}

	g.defaults({ ease: 'power3.out', duration: 0.5 });
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
		return r.top < window.innerHeight * 0.96 && r.bottom > 0;
	}

	/* ── Entrance ─────────────────────────────────────────────────────── */

	var CARD = '.af-kpi, .af-property-card, .af-catalog-card, .af-maint-card, .af-prov-card, .af-sp-tile, .af-exit-card, .af-review-list__item, .af-cobranza-alert, .af-wiz-card, .af-choice-card';
	var BLOCK = '.af-page-header, .af-section, .af-split > *, .af-info-strip, .af-status-tabs, .af-tabs__nav, .af-empty, .af-semaforo, .af-flow, .af-steps';

	function collectEntrance(root) {
		var list = [];

		$$(BLOCK, root).forEach(function (block) {
			if (block.closest('.af-modal') || !isShown(block)) { return; }
			if (block.querySelector(CARD)) {
				var head = block.querySelector(':scope > .af-section__header, :scope > header');
				if (head) { list.push(head); }
				return;
			}
			list.push(block);
		});

		$$(CARD, root).forEach(function (card) {
			if (card.closest('.af-modal') || !isShown(card)) { return; }
			list.push(card);
		});

		// Drop nodes that live inside another animated node and dedupe, keeping DOM order.
		var unique = list.filter(function (el, i) {
			return list.indexOf(el) === i && !list.some(function (other) { return other !== el && other.contains(el); });
		});

		unique.sort(function (a, b) {
			return a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
		});

		return unique;
	}

	function revealList(list, opts) {
		if (!list.length) { return; }
		opts = opts || {};
		g.fromTo(list,
			{ opacity: 0, y: opts.y === undefined ? 18 : opts.y },
			{
				opacity: 1,
				y: 0,
				duration: opts.duration || 0.55,
				stagger: { each: opts.each || 0.05, amount: Math.min(opts.amount || 0.6, list.length * (opts.each || 0.05)) },
				delay: opts.delay || 0,
				clearProps: CLEAR,
				overwrite: 'auto'
			}
		);
	}

	function runEntrance() {
		var all = collectEntrance(document);
		var visible = all.filter(inViewport);
		var below = all.filter(function (el) { return visible.indexOf(el) === -1; });

		revealList(visible, { delay: 0.05 });

		if (!below.length) { return; }

		if (!window.ScrollTrigger) {
			return;
		}

		g.set(below, { opacity: 0, y: 18 });
		window.ScrollTrigger.batch(below, {
			start: 'top 94%',
			once: true,
			onEnter: function (batch) {
				g.to(batch, { opacity: 1, y: 0, duration: 0.55, stagger: 0.06, clearProps: CLEAR, overwrite: 'auto' });
			}
		});

		// Safety net: never leave content hidden if scrolling never triggers (e.g. print, zoom).
		window.setTimeout(function () {
			below.forEach(function (el) {
				if (parseFloat(g.getProperty(el, 'opacity')) === 0 && inViewport(el)) {
					g.to(el, { opacity: 1, y: 0, duration: 0.4, clearProps: CLEAR });
				}
			});
		}, 2500);

		window.addEventListener('load', function () {
			window.ScrollTrigger.refresh();
		});
	}

	/* ── Counters (plain integers and percentages only) ───────────────── */

	function runCounters() {
		$$('.af-kpi__value, [data-af-count]').forEach(function (el) {
			if (el.children.length || el.closest('.af-modal')) { return; }
			var text = el.textContent.trim();
			var match = /^(\d{1,7})(%?)$/.exec(text);
			if (!match) { return; }
			var target = parseInt(match[1], 10);
			if (target < 2) { return; }
			var obj = { v: 0 };
			el.textContent = '0' + match[2];
			g.to(obj, {
				v: target,
				duration: 1,
				delay: 0.2,
				ease: 'power2.out',
				onUpdate: function () { el.textContent = Math.round(obj.v) + match[2]; },
				onComplete: function () { el.textContent = text; }
			});
		});
	}

	/* ── Shell chrome (first visit of the session only) ───────────────── */

	function runChrome() {
		var played = false;
		try { played = window.sessionStorage.getItem('af_chrome_in') === '1'; } catch (e) { played = false; }
		if (played) { return; }
		try { window.sessionStorage.setItem('af_chrome_in', '1'); } catch (e) { /* storage blocked */ }

		var items = $$('.af-app-sidebar__group, .af-app-sidebar > .af-app-sidebar__nav > .af-app-sidebar__item');
		if (items.length) {
			g.from(items, { x: -14, opacity: 0, duration: 0.5, stagger: 0.07, delay: 0.1, clearProps: CLEAR });
		}
		var topbar = document.querySelector('.af-app-topbar');
		if (topbar) {
			g.from(topbar, { y: -14, opacity: 0, duration: 0.5, clearProps: CLEAR });
		}
	}

	/* ── Modals ───────────────────────────────────────────────────────── */

	function animateModalIn(modal) {
		var dialog = modal.querySelector('.af-modal__dialog, .af-modal__container');
		var backdrop = modal.querySelector('.af-modal__backdrop');
		if (backdrop) {
			g.fromTo(backdrop, { opacity: 0 }, { opacity: 1, duration: 0.25, clearProps: 'opacity', overwrite: 'auto' });
		}
		if (dialog) {
			g.fromTo(dialog,
				{ opacity: 0, y: 28, scale: 0.97 },
				{ opacity: 1, y: 0, scale: 1, duration: 0.45, ease: 'back.out(1.25)', clearProps: CLEAR, overwrite: 'auto' }
			);
			var rows = $$('.af-modal__field, .af-choice, .af-cobranza-flow__step', dialog).slice(0, 10);
			if (rows.length) {
				g.from(rows, { opacity: 0, y: 10, duration: 0.35, stagger: 0.04, delay: 0.12, clearProps: CLEAR });
			}
		}
	}

	function watchModals() {
		if (!('MutationObserver' in window)) { return; }
		var seen = new WeakMap();

		function wasOpen(modal) {
			return !modal.hidden && !modal.classList.contains('is-closing') && window.getComputedStyle(modal).display !== 'none';
		}

		var observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (m) {
				var modal = m.target;
				var open = wasOpen(modal);
				if (open && !seen.get(modal)) {
					animateModalIn(modal);
				}
				seen.set(modal, open);
			});
		});

		$$('.af-modal').forEach(function (modal) {
			seen.set(modal, wasOpen(modal));
			observer.observe(modal, { attributes: true, attributeFilter: ['class', 'hidden', 'style'] });
		});
	}

	/* ── Tabs ─────────────────────────────────────────────────────────── */

	function watchTabs() {
		document.addEventListener('change', function (event) {
			var radio = event.target;
			if (!radio.classList || !radio.classList.contains('af-tabs__radio') || !radio.id) { return; }
			var panel = document.querySelector('.af-tabs__panel[aria-labelledby="' + radio.id + '"]');
			if (!panel) { return; }
			var kids = $$('.af-kpi, .af-property-card, .af-catalog-card, .af-section, .af-data-table tbody tr', panel).slice(0, 18);
			g.fromTo(panel, { opacity: 0, y: 10 }, { opacity: 1, y: 0, duration: 0.35, clearProps: CLEAR });
			if (kids.length) {
				g.from(kids, { opacity: 0, y: 12, duration: 0.4, stagger: 0.03, delay: 0.05, clearProps: CLEAR });
			}
		});

		document.addEventListener('click', function (event) {
			var tab = event.target.closest('.af-status-tabs__tab, [data-tab-group-target]');
			if (!tab) { return; }
			window.setTimeout(function () {
				var rows = $$('tbody tr, .af-sp-tile', tab.closest('.af-section, .wrap') || document).filter(function (el) {
					return isShown(el);
				}).slice(0, 16);
				if (rows.length) {
					g.fromTo(rows, { opacity: 0, y: 8 }, { opacity: 1, y: 0, duration: 0.3, stagger: 0.025, clearProps: CLEAR, overwrite: 'auto' });
				}
			}, 20);
		});
	}

	/* ── Page transition ──────────────────────────────────────────────── */

	function pageTransitions() {
		var bar = document.createElement('div');
		bar.id = 'af-nav-progress';
		bar.setAttribute('aria-hidden', 'true');
		document.body.appendChild(bar);

		var leaving = false;

		function content() {
			return document.getElementById('wpbody-content');
		}

		document.addEventListener('click', function (event) {
			if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
			var a = event.target.closest('a[href]');
			if (!a || leaving) { return; }
			if ((a.target && a.target !== '_self') || a.hasAttribute('download') || a.hasAttribute('data-no-transition')) { return; }
			if (a.closest('.af-modal, .af-alerts-panel')) { return; }

			var href = a.getAttribute('href') || '';
			if (!href || href.charAt(0) === '#' || /^(mailto:|tel:|javascript:)/i.test(href)) { return; }

			var url;
			try { url = new URL(a.href, window.location.href); } catch (e) { return; }
			if (url.origin !== window.location.origin || !/\/wp-admin\/(admin|edit|index)\.php$/.test(url.pathname)) { return; }
			if (url.searchParams.has('action') || url.searchParams.has('af_download')) { return; }
			if (url.pathname === window.location.pathname && url.search === window.location.search) { return; }

			var host = content();
			if (!host) { return; }

			event.preventDefault();
			leaving = true;

			var go = (function () {
				var done = false;
				return function () {
					if (done) { return; }
					done = true;
					window.location.href = url.href;
				};
			}());

			g.to(bar, { width: '72%', opacity: 1, duration: 0.9, ease: 'power1.out' });
			g.to(host, { opacity: 0, y: -8, duration: 0.18, ease: 'power2.in', onComplete: go });
			window.setTimeout(go, 450);
		});

		window.addEventListener('pageshow', function (event) {
			leaving = false;
			var host = content();
			if (host) { g.set(host, { clearProps: CLEAR }); }
			g.set(bar, { width: 0, opacity: 0 });
			if (event.persisted) { runEntrance(); }
		});
	}

	/* ── Public helpers ───────────────────────────────────────────────── */

	window.afMotion.reveal = function (nodes, opts) {
		var list = Array.prototype.slice.call(nodes || []).filter(function (n) { return n && n.nodeType === 1; });
		revealList(list, opts || { each: 0.035, y: 12, duration: 0.4, amount: 0.5 });
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
		var shell = !!document.getElementById('af-app-sidebar');

		if (shell) { runChrome(); }
		runEntrance();
		runCounters();
		watchModals();
		watchTabs();
		watchDynamicLists();
		if (shell) { pageTransitions(); }
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
