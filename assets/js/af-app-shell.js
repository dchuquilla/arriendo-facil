/**
 * Arriendo Fácil — App shell behaviour.
 *
 *  - Barra lateral: contraer a riel (recordado; en tableta arranca contraída).
 *  - Menú de cuenta en la barra superior.
 *  - Hojas del teléfono (acciones rápidas y menú): entran desde abajo con
 *    GSAP, se cierran arrastrando hacia abajo, con Esc o tocando el fondo.
 *  - Barra inferior: la píldora tintada viaja a la pestaña tocada y el botón
 *    central responde al toque.
 *  - La barra superior del teléfono se esconde al bajar y vuelve al subir.
 *  - ?af_quick=… abre el formulario "nuevo" de la pantalla destino.
 *
 * Sin GSAP o con movimiento reducido todo funciona igual, sin animación.
 */
(function () {
	'use strict';

	var gsap = window.gsap;
	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var animate = !!gsap && !reduced;
	var phone = window.matchMedia('(max-width: 782px)');
	var tablet = window.matchMedia('(min-width: 783px) and (max-width: 1100px)');

	function $(selector, root) { return (root || document).querySelector(selector); }
	function $$(selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }

	function store(key, value) {
		try {
			if (value === undefined) { return window.localStorage.getItem(key); }
			window.localStorage.setItem(key, value);
		} catch (e) { /* storage blocked */ }
		return null;
	}

	/* ── Barra lateral ──────────────────────────────────────────────── */

	function initSidebar() {
		var body = document.body;
		var toggle = document.getElementById('af-app-sidebar-toggle');
		var key = 'af_admin_sidebar_collapsed';
		var saved = store(key);

		if (saved === '1' || (saved === null && tablet.matches)) {
			body.classList.add('af-sidebar-collapsed');
		}

		if (!toggle) { return; }

		var sync = function () {
			var collapsed = body.classList.contains('af-sidebar-collapsed');
			toggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
			toggle.setAttribute('aria-label', collapsed ? 'Expandir menú' : 'Contraer menú');
		};
		sync();

		toggle.addEventListener('click', function () {
			var collapsed = body.classList.toggle('af-sidebar-collapsed');
			store(key, collapsed ? '1' : '0');
			sync();
			if (animate && !collapsed) {
				gsap.fromTo('.af-app-nav__text, .af-app-brand__copy, .af-app-nav__label',
					{ opacity: 0, x: -6 },
					{ opacity: 1, x: 0, duration: 0.28, stagger: 0.012, ease: 'power2.out', clearProps: 'opacity,transform', delay: 0.08 });
			}
		});
	}

	/* ── Menú de cuenta ─────────────────────────────────────────────── */

	function initUserMenu() {
		var menu = document.getElementById('af-user-menu');
		var toggle = document.getElementById('af-user-menu-toggle');
		var panel = document.getElementById('af-user-menu-panel');
		if (!menu || !toggle || !panel) { return; }

		var set = function (open) {
			if (open === !panel.hidden) { return; }
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) {
				panel.hidden = false;
				if (animate) {
					gsap.fromTo(panel, { opacity: 0, y: -6, scale: 0.98 }, { opacity: 1, y: 0, scale: 1, duration: 0.22, ease: 'power3.out', clearProps: 'all' });
					gsap.fromTo($$('.af-user-menu__item', panel), { opacity: 0, y: 4 }, { opacity: 1, y: 0, duration: 0.24, stagger: 0.03, delay: 0.04, ease: 'power2.out', clearProps: 'all' });
				}
			} else if (animate) {
				gsap.to(panel, { opacity: 0, y: -4, duration: 0.14, ease: 'power2.in', onComplete: function () { panel.hidden = true; gsap.set(panel, { clearProps: 'all' }); } });
			} else {
				panel.hidden = true;
			}
		};

		toggle.addEventListener('click', function (event) {
			event.stopPropagation();
			set(panel.hidden);
		});

		document.addEventListener('click', function (event) {
			if (!panel.hidden && !menu.contains(event.target)) { set(false); }
		});

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !panel.hidden) {
				set(false);
				toggle.focus();
			}
		});
	}

	/* ── Hojas ──────────────────────────────────────────────────────── */

	var openSheet = null;
	var lastOpener = null;

	function focusables(root) {
		return $$('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])', root)
			.filter(function (el) { return el.offsetParent !== null; });
	}

	function setOpeners(sheet, expanded) {
		$$('[data-af-sheet-open="' + sheet.id + '"]').forEach(function (btn) {
			btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
		});
	}

	function showSheet(sheet, opener) {
		if (!sheet || openSheet === sheet) { return; }
		if (openSheet) { hideSheet(openSheet, true); }

		var panel = $('.af-sheet__panel', sheet);
		var scrim = $('.af-sheet__scrim', sheet);
		var rows = $$('.af-quick-list li, .af-menu-group, .af-sheet__plan, .af-sheet__foot', sheet);

		openSheet = sheet;
		lastOpener = opener || document.activeElement;
		sheet.hidden = false;
		document.body.classList.add('af-sheet-open');
		setOpeners(sheet, true);
		panel.scrollTop = 0;

		if (animate) {
			gsap.killTweensOf([panel, scrim]);
			gsap.fromTo(scrim, { opacity: 0 }, { opacity: 1, duration: 0.24, ease: 'power1.out' });
			gsap.fromTo(panel, { yPercent: 100 }, { yPercent: 0, duration: 0.46, ease: 'expo.out' });
			gsap.fromTo(rows, { opacity: 0, y: 14 }, { opacity: 1, y: 0, duration: 0.36, stagger: 0.045, delay: 0.1, ease: 'power3.out', clearProps: 'opacity,transform' });
		}

		// El foco entra al diálogo (no a un control), así no aparece un anillo sin haber tabulado.
		if (!panel.hasAttribute('tabindex')) { panel.setAttribute('tabindex', '-1'); }
		window.setTimeout(function () { panel.focus({ preventScroll: true }); }, animate ? 60 : 0);
	}

	function hideSheet(sheet, instant) {
		if (!sheet || sheet.hidden) { return; }
		var panel = $('.af-sheet__panel', sheet);
		var scrim = $('.af-sheet__scrim', sheet);

		var done = function () {
			sheet.hidden = true;
			if (animate) { gsap.set([panel, scrim], { clearProps: 'all' }); }
		};

		if (openSheet === sheet) { openSheet = null; }
		document.body.classList.remove('af-sheet-open');
		setOpeners(sheet, false);

		if (animate && !instant) {
			gsap.killTweensOf([panel, scrim]);
			gsap.to(scrim, { opacity: 0, duration: 0.22, ease: 'power1.in' });
			gsap.to(panel, { yPercent: 100, y: 0, duration: 0.3, ease: 'power3.in', onComplete: done });
		} else {
			done();
		}

		if (lastOpener && typeof lastOpener.focus === 'function') {
			lastOpener.focus({ preventScroll: true });
		}
		lastOpener = null;
	}

	function enableDrag(sheet) {
		var panel = $('.af-sheet__panel', sheet);
		var scrim = $('.af-sheet__scrim', sheet);
		var startY = 0;
		var lastY = 0;
		var lastT = 0;
		var velocity = 0;
		var dragging = false;

		panel.addEventListener('pointerdown', function (event) {
			if (event.pointerType === 'mouse' || panel.scrollTop > 0) { return; }
			if (event.target.closest('input, select, textarea')) { return; }
			dragging = true;
			startY = lastY = event.clientY;
			lastT = event.timeStamp;
			velocity = 0;
		});

		panel.addEventListener('pointermove', function (event) {
			if (!dragging) { return; }
			var dy = Math.max(0, event.clientY - startY);
			if (dy > 6 && !panel.hasPointerCapture(event.pointerId)) {
				panel.setPointerCapture(event.pointerId);
			}
			velocity = (event.clientY - lastY) / Math.max(1, event.timeStamp - lastT);
			lastY = event.clientY;
			lastT = event.timeStamp;
			if (animate) {
				gsap.set(panel, { y: dy });
				gsap.set(scrim, { opacity: Math.max(0.2, 1 - dy / (panel.offsetHeight || 1)) });
			}
		});

		var end = function (event) {
			if (!dragging) { return; }
			dragging = false;
			var dy = Math.max(0, event.clientY - startY);
			if (panel.hasPointerCapture && panel.hasPointerCapture(event.pointerId)) {
				panel.releasePointerCapture(event.pointerId);
			}
			if (dy > panel.offsetHeight * 0.28 || velocity > 0.6) {
				hideSheet(sheet);
			} else if (animate) {
				gsap.to(panel, { y: 0, duration: 0.4, ease: 'back.out(1.6)' });
				gsap.to(scrim, { opacity: 1, duration: 0.2 });
			}
		};

		panel.addEventListener('pointerup', end);
		panel.addEventListener('pointercancel', end);
	}

	function initSheets() {
		var sheets = $$('.af-sheet');
		if (!sheets.length) { return; }

		sheets.forEach(function (sheet) {
			// Las hojas viven fuera de #wpwrap para que ningún overflow las recorte.
			document.body.appendChild(sheet);
			enableDrag(sheet);

			sheet.addEventListener('click', function (event) {
				if (event.target.closest('[data-af-sheet-close]')) {
					hideSheet(sheet);
					return;
				}
				// Un enlace bloqueado por plan abre el aviso de mejora: la hoja se aparta.
				if (event.target.closest('[data-af-plan-lock]')) {
					hideSheet(sheet, true);
				}
			});

			sheet.addEventListener('keydown', function (event) {
				if (event.key !== 'Tab') { return; }
				var items = focusables(sheet);
				if (!items.length) { return; }
				var first = items[0];
				var last = items[items.length - 1];
				if (event.shiftKey && document.activeElement === first) {
					event.preventDefault();
					last.focus();
				} else if (!event.shiftKey && document.activeElement === last) {
					event.preventDefault();
					first.focus();
				}
			});
		});

		document.addEventListener('click', function (event) {
			var opener = event.target.closest('[data-af-sheet-open]');
			if (!opener) { return; }
			event.preventDefault();
			var sheet = document.getElementById(opener.getAttribute('data-af-sheet-open'));
			if (sheet && !sheet.hidden) {
				hideSheet(sheet);
			} else {
				showSheet(sheet, opener);
			}
		});

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && openSheet) { hideSheet(openSheet); }
		});

		phone.addEventListener('change', function (event) {
			if (!event.matches && openSheet) { hideSheet(openSheet, true); }
		});
	}

	/* ── Barra inferior ─────────────────────────────────────────────── */

	function initTabbar() {
		var bar = document.getElementById('af-tabbar');
		if (!bar) { return; }
		var pill = $('.af-tabbar__indicator', bar);
		var fab = $('.af-tabbar__fab', bar);

		var place = function (item, tween) {
			if (!pill || !item) { return; }
			var icon = $('.af-tabbar__icon', item);
			if (!icon || icon.classList.contains('af-tabbar__icon--avatar')) {
				if (animate && tween) { gsap.to(pill, { opacity: 0, duration: 0.15 }); }
				else { pill.style.opacity = '0'; }
				return;
			}
			var barBox = bar.getBoundingClientRect();
			var box = icon.getBoundingClientRect();
			var x = box.left - barBox.left;
			var y = box.top - barBox.top;
			if (animate && tween) {
				gsap.to(pill, { x: x, y: y - 6, opacity: 1, width: box.width, duration: 0.42, ease: 'expo.out' });
				gsap.fromTo(pill, { scaleX: 1.25 }, { scaleX: 1, duration: 0.5, ease: 'elastic.out(1, 0.6)' });
			} else {
				pill.style.transform = 'translate(' + x + 'px,' + (y - 6) + 'px)';
				pill.style.width = box.width + 'px';
				pill.style.opacity = '1';
			}
		};

		var current = $('.af-tabbar__item.is-active', bar);
		var sync = function () {
			if (!phone.matches) { return; }
			bar.classList.add('has-indicator');
			if (animate) { gsap.set(pill, { clearProps: 'transform' }); }
			place(current, false);
		};
		sync();
		window.addEventListener('resize', sync);
		phone.addEventListener('change', sync);

		$$('a.af-tabbar__item', bar).forEach(function (link) {
			link.addEventListener('click', function (event) {
				if (event.metaKey || event.ctrlKey || event.shiftKey || link.classList.contains('is-active')) { return; }
				$$('.af-tabbar__item', bar).forEach(function (el) { el.classList.toggle('is-active', el === link); });
				place(link, true);
				if (animate) {
					gsap.fromTo($('.af-tabbar__icon .af-icon', link), { y: 0 }, { y: -3, duration: 0.14, yoyo: true, repeat: 1, ease: 'power2.out' });
				}
			});
		});

		if (fab && animate) {
			var disc = $('.af-tabbar__fab-disc', fab);
			var plus = $('.af-icon', disc);
			fab.addEventListener('pointerdown', function () { gsap.to(disc, { scale: 0.9, duration: 0.12, ease: 'power2.out' }); });
			['pointerup', 'pointerleave', 'pointercancel'].forEach(function (type) {
				fab.addEventListener(type, function () { gsap.to(disc, { scale: 1, duration: 0.5, ease: 'elastic.out(1.1, 0.45)' }); });
			});
			new MutationObserver(function () {
				var open = fab.getAttribute('aria-expanded') === 'true';
				gsap.to(plus, { rotate: open ? 135 : 0, duration: 0.45, ease: 'back.out(2)' });
			}).observe(fab, { attributes: true, attributeFilter: ['aria-expanded'] });

			// Entrada de la barra: sube y el botón central aterriza con rebote.
			gsap.from(bar, { yPercent: 100, duration: 0.5, ease: 'expo.out', delay: 0.05, clearProps: 'transform' });
			gsap.from(disc, { scale: 0.4, opacity: 0, duration: 0.7, ease: 'back.out(2.2)', delay: 0.22, clearProps: 'transform,opacity' });
		}

		if (fab) {
			fab.addEventListener('click', function () {
				if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) { /* not allowed */ } }
			});
		}
	}

	/* ── Barra superior del teléfono ────────────────────────────────── */

	function initTopbarScroll() {
		var lastY = window.scrollY;
		var ticking = false;
		var body = document.body;

		window.addEventListener('scroll', function () {
			if (ticking) { return; }
			ticking = true;
			window.requestAnimationFrame(function () {
				var y = window.scrollY;
				var delta = y - lastY;
				if (!phone.matches || openSheet || y < 80) {
					body.classList.remove('af-topbar-hidden');
				} else if (delta > 6) {
					body.classList.add('af-topbar-hidden');
				} else if (delta < -6) {
					body.classList.remove('af-topbar-hidden');
				}
				lastY = y;
				ticking = false;
			});
		}, { passive: true });
	}

	/* ── Título grande que se acopla a la barra (teléfono) ─────────── */

	function initDockedTitle() {
		var body = document.body;
		var heading = document.querySelector('#wpbody-content h1');
		if (!heading || !('IntersectionObserver' in window)) {
			body.classList.add('af-title-docked');
			return;
		}
		new IntersectionObserver(function (entries) {
			var entry = entries[0];
			body.classList.toggle('af-title-docked', !entry.isIntersecting && entry.boundingClientRect.top < 80);
		}, { rootMargin: '-64px 0px 0px 0px', threshold: 0 }).observe(heading);
	}

	/* ── Acciones rápidas por enlace (?af_quick=) ───────────────────── */

	var QUICK = {
		'new-lease': { button: '#af-new-lease', target: '#af-lease-form-card' },
		'new-guest': { button: '#af-new-guest', target: '#af-guest-form-card' },
		'new-request': { button: '#af-maint-new-request', target: '.af-maint-formcard' }
	};

	function initQuickLinks() {
		var params = new URLSearchParams(window.location.search);
		var key = params.get('af_quick');
		if (!key || !QUICK[key]) { return; }

		params.delete('af_quick');
		var clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
		try { window.history.replaceState(null, '', clean); } catch (e) { /* ignore */ }

		var tries = 0;
		var run = function () {
			var btn = document.querySelector(QUICK[key].button);
			if (btn && btn.offsetParent !== null) {
				btn.click();
				window.setTimeout(function () {
					var target = document.querySelector(QUICK[key].target) || btn;
					if (target && target.scrollIntoView) {
						target.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
					}
					var field = target && target.querySelector && target.querySelector('input:not([type="hidden"]), select, textarea');
					if (field) { field.focus({ preventScroll: true }); }
				}, 260);
			} else if (tries++ < 20) {
				window.setTimeout(run, 120);
			}
		};
		run();
	}

	/*
	 * Pantallas donde quien administra escribe datos de OTRA persona (inquilinos, contactos, visitas…).
	 * El navegador no debe rellenarlas con los datos de quien administra: se desactiva el autocompletado
	 * en los campos personales que no lo declaren. Los datos propios (facturación, perfil) no se tocan.
	 */
	function initNoAutofill() {
		var page = (new URLSearchParams(window.location.search).get('page') || '');
		if (!/^af-(guests|leases|maintenance|calendar|property-admins|cleaning-requests|buildings|owner-contacts|reviews|catalog)$/.test(page)) { return; }

		$$('.af-shell form, .af-modal').forEach(function (scope) {
			if (scope.tagName === 'FORM' && !scope.hasAttribute('autocomplete')) { scope.setAttribute('autocomplete', 'off'); }
			$$('input[type="text"], input[type="email"], input[type="tel"], input:not([type])', scope).forEach(function (input) {
				if (!input.hasAttribute('autocomplete')) { input.setAttribute('autocomplete', 'off'); }
			});
		});
	}

	function init() {
		initNoAutofill();
		initSidebar();
		initUserMenu();
		initSheets();
		initTabbar();
		initTopbarScroll();
		initDockedTitle();
		// Las pantallas enlazan sus botones en DOMContentLoaded: se espera a que todas terminen.
		if (document.readyState === 'complete') {
			window.setTimeout(initQuickLinks, 0);
		} else {
			window.addEventListener('load', function () { window.setTimeout(initQuickLinks, 0); });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
