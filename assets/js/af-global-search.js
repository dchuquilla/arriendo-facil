/**
 * Arriendo Fácil — Buscador global.
 *
 *  - Barra superior: al escribir muestra resultados agrupados (inmuebles,
 *    inquilinos, contratos, mantenimiento, técnicos, catálogos). Flechas para
 *    moverse, Enter para abrir, Esc para cerrar. Enter sin elegir nada abre la
 *    página de resultados completa.
 *  - ?af_q=… (y ?af_tab=personal) deja escrita la búsqueda en el buscador de la
 *    sección a la que llega el resultado.
 *  - [data-af-listfilter="selector de filas"]: filtro de texto de una tabla o
 *    lista, sin tildes y con todas las palabras en cualquier orden.
 */
(function () {
	'use strict';

	var cfg = window.afGlobalSearch || {};
	var i18n = cfg.i18n || {};

	function fold(text) {
		return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
	}

	function matchesTerm(haystack, term) {
		var words = fold(term).split(/\s+/).filter(Boolean);
		if (!words.length) { return true; }
		var text = fold(haystack);
		return words.every(function (word) { return text.indexOf(word) !== -1; });
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (text !== undefined) { node.textContent = text; }
		return node;
	}

	/* ── Desplegable de la barra superior ───────────────────────────── */

	function initTopbarSearch() {
		var form = document.querySelector('.af-app-topbar__search');
		var input = form && form.querySelector('input[type="search"]');
		if (!form || !input || !cfg.ajaxUrl) { return; }

		var panel = el('div', 'af-gsearch__panel');
		panel.id = 'af-gsearch-panel';
		panel.setAttribute('role', 'listbox');
		panel.hidden = true;
		form.appendChild(panel);

		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-controls', panel.id);
		input.setAttribute('autocomplete', 'off');

		var timer = null;
		var request = 0;
		var active = -1;
		var resultsUrl = '';

		function links() { return Array.prototype.slice.call(panel.querySelectorAll('a')); }

		function close() {
			panel.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			active = -1;
		}

		function open() {
			panel.hidden = false;
			input.setAttribute('aria-expanded', 'true');
		}

		function status(message) {
			panel.replaceChildren(el('p', 'af-gsearch__status', message));
			open();
		}

		function setActive(index) {
			var items = links();
			if (!items.length) { return; }
			active = (index + items.length) % items.length;
			items.forEach(function (item, i) {
				item.classList.toggle('is-active', i === active);
				if (i === active) {
					item.scrollIntoView({ block: 'nearest' });
				}
			});
		}

		function render(groups) {
			var frag = document.createDocumentFragment();

			groups.forEach(function (group) {
				var section = el('div', 'af-gsearch__group');
				section.appendChild(el('p', 'af-gsearch__heading', group.label));

				group.items.forEach(function (item) {
					var link = el('a', 'af-gsearch__item');
					link.href = item.url;
					link.setAttribute('role', 'option');

					var main = el('span');
					main.appendChild(el('strong', '', item.title));
					if (item.subtitle) { main.appendChild(el('small', '', item.subtitle)); }
					link.appendChild(main);
					if (item.badge) { link.appendChild(el('span', 'af-pill af-pill--neutral', item.badge)); }

					section.appendChild(link);
				});
				frag.appendChild(section);
			});

			var all = el('a', 'af-gsearch__all', i18n.seeAll);
			all.href = resultsUrl;
			frag.appendChild(all);

			panel.replaceChildren(frag);
			active = -1;
			open();
		}

		function search() {
			var term = input.value.trim();
			if (term.length < (cfg.minLength || 2)) {
				if (term.length) { status(i18n.hint); } else { close(); }
				return;
			}

			var ticket = ++request;
			status(i18n.searching);

			var body = new URLSearchParams();
			body.append('action', 'af_global_search');
			body.append('nonce', cfg.nonce);
			body.append('q', term);

			fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body
			}).then(function (r) { return r.json(); }).then(function (json) {
				if (ticket !== request) { return; } // llegó tarde: ya hay una búsqueda más nueva
				if (!json || !json.success) { status(i18n.error); return; }
				resultsUrl = json.data.resultsUrl;
				if (!json.data.groups.length) { status(i18n.noResults); return; }
				render(json.data.groups);
			}).catch(function () {
				if (ticket === request) { status(i18n.error); }
			});
		}

		input.addEventListener('input', function () {
			window.clearTimeout(timer);
			timer = window.setTimeout(search, 220);
		});

		input.addEventListener('focus', function () {
			if (input.value.trim().length >= (cfg.minLength || 2) && panel.childElementCount) { open(); }
		});

		input.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { close(); return; }
			if (e.key === 'ArrowDown') { e.preventDefault(); if (panel.hidden) { search(); } else { setActive(active + 1); } return; }
			if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); return; }
			if (e.key === 'Enter' && active >= 0) {
				e.preventDefault();
				var items = links();
				if (items[active]) { window.location.href = items[active].href; }
			}
		});

		document.addEventListener('click', function (e) {
			if (!form.contains(e.target)) { close(); }
		});
	}

	/* ── Llegar a una sección con la búsqueda ya escrita ────────────── */

	function applyDeepLink() {
		var params = new URLSearchParams(window.location.search);
		var term = params.get('af_q');
		if (!term) { return; }

		var page = params.get('page');
		var personal = params.get('af_tab') === 'personal';
		var targets = {
			'af-guests': '#af-guests-filter',
			'af-leases': '#af-leases-filter',
			'af-maintenance': personal ? '#af-prov-search-input' : '#af-maint-search-input'
		};
		var selector = targets[page];
		if (!selector) { return; }

		if (page === 'af-maintenance' && personal) {
			var tab = document.getElementById('af-tab-personal');
			if (tab) { tab.checked = true; }
		}

		var field = document.querySelector(selector);
		if (!field) { return; }
		field.value = term;
		field.dispatchEvent(new Event('input', { bubbles: true }));
	}

	/* ── Filtro de texto de tablas y listas de una sección ──────────── */

	function initListFilters() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-af-listfilter]'), function (field) {
			var selector = field.getAttribute('data-af-listfilter');
			var emptyNote = null;

			function apply() {
				var rows = Array.prototype.slice.call(document.querySelectorAll(selector));
				var term = field.value.trim();
				var shown = 0;

				rows.forEach(function (row) {
					var ok = matchesTerm(row.textContent, term);
					row.classList.toggle('af-row-nomatch', !ok);
					if (ok) { shown++; }
				});

				if (!emptyNote && rows.length) {
					emptyNote = el('p', 'af-listfilter__empty', field.getAttribute('data-af-empty') || i18n.noResults);
					emptyNote.hidden = true;
					var anchor = rows[0].closest('table') || rows[0].parentNode;
					anchor.parentNode.insertBefore(emptyNote, anchor.nextSibling);
				}
				if (emptyNote) { emptyNote.hidden = !term || shown > 0; }
			}

			field.addEventListener('input', apply);
			field.form && field.form.addEventListener('submit', function (e) { e.preventDefault(); });
		});
	}

	function init() {
		initTopbarSearch();
		initListFilters();
		applyDeepLink();
	}

	// Las pantallas enlazan sus buscadores en DOMContentLoaded: se espera a que terminen.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { window.setTimeout(init, 0); });
	} else {
		window.setTimeout(init, 0);
	}
}());
