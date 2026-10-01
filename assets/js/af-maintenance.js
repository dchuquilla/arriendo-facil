/**
 * Mantenimiento: tabla interactiva de solicitudes y catálogo de personal.
 *
 * No build step and no framework: the plugin already ships plain assets, so this
 * file uses the same style (IIFE, DOM ready, no globals leaked beyond the two
 * localized config objects).
 *
 * Everything is progressive enhancement. Without JS the table still renders as a
 * plain sortable-looking table and the forms still POST through the same AJAX
 * endpoints as before.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	/**
	 * Sends a POST to admin-ajax with a URL-encoded body.
	 *
	 * @param {Object} payload Action + nonce + fields.
	 * @return {Promise<Object>} Parsed JSON response.
	 */
	function post( payload ) {
		var body = new URLSearchParams();
		Object.keys( payload ).forEach( function ( key ) {
			body.append( key, payload[ key ] );
		} );

		return fetch( afMaintenance.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	/**
	 * Mirrors the variant part of the af_pill() server helper. A status the
	 * server does not map falls back to 'neutral' there, so it must here too.
	 *
	 * @param {string} status Status key.
	 * @return {string} CSS variant suffix.
	 */
	function statusVariant( status ) {
		return {
			pending: 'warning',
			in_progress: 'warning',
			completed: 'success'
		}[ status ] || 'neutral';
	}

	// ── Solicitudes: sort, search, detail, CSV, print ───────────────────────

	function initRequests() {
		var root = document.getElementById( 'af-maint-requests' );
		if ( ! root ) {
			return;
		}

		var search = document.getElementById( 'af-maint-search-input' );
		var searchClear = document.getElementById( 'af-maint-search-clear' );
		var countEl = document.getElementById( 'af-maint-count' );
		var statusFilter = document.getElementById( 'af-maint-status-filter' );
		var typeFilter = document.getElementById( 'af-maint-type-filter' );

		// The table is not rendered at all when the portfolio has no requests yet,
		// so the toolbar (search, filters, CSV) is all that exists here.
		var table = root.querySelector( '.af-maint-table' );
		if ( ! table ) {
			return;
		}

		var rows = Array.prototype.slice.call( table.querySelectorAll( 'tr.af-maint-row' ) );

		// The summary strip doubles as a filter. 'open' covers two states, which
		// a single <select> cannot express, so this axis is kept apart from the
		// status select and the two combine.
		var stats = document.getElementById( 'af-maint-stats' );
		var quickFilter = '';

		function rowText( row ) {
			return ( row.dataset.search || row.textContent ).toLowerCase();
		}

		function isOpen( row ) {
			return 'pending' === row.dataset.status || 'in_progress' === row.dataset.status;
		}

		function isOverdue( row ) {
			return isOpen( row ) && '1' === row.dataset.scheduledFlag && row.dataset.scheduled < afMaintenance.today;
		}

		// Keeps the chips and row accents true after inline status changes.
		function recount() {
			var totals = { open: 0, alta: 0, scheduled: 0, overdue: 0 };

			rows.forEach( function ( row ) {
				var overdue = isOverdue( row );
				row.classList.toggle( 'af-maint-row--overdue', overdue );

				if ( ! isOpen( row ) ) {
					return;
				}
				totals.open++;
				if ( 'alta' === row.dataset.priority ) {
					totals.alta++;
				}
				if ( '1' === row.dataset.scheduledFlag ) {
					totals.scheduled++;
				}
				if ( overdue ) {
					totals.overdue++;
				}
			} );

			if ( ! stats ) {
				return;
			}
			Object.keys( totals ).forEach( function ( key ) {
				var el = stats.querySelector( '[data-stat="' + key + '"]' );
				if ( ! el ) {
					return;
				}
				el.textContent = totals[ key ];
				var chip = el.closest( '.af-maint-stat' );
				if ( chip && chip.dataset.attention ) {
					chip.classList.toggle( 'af-maint-stat--attention', totals[ key ] > 0 );
				}
			} );
		}

		function matchesQuick( row ) {
			if ( ! quickFilter ) {
				return true;
			}

			if ( 'open' === quickFilter ) {
				return isOpen( row );
			}

			if ( 'alta' === quickFilter ) {
				return isOpen( row ) && 'alta' === row.dataset.priority;
			}

			if ( 'scheduled' === quickFilter ) {
				return isOpen( row ) && '1' === row.dataset.scheduledFlag;
			}

			if ( 'overdue' === quickFilter ) {
				return isOverdue( row );
			}

			if ( 'month' === quickFilter ) {
				return '1' === row.dataset.month;
			}

			return true;
		}

		function matches( row ) {
			var term = search ? search.value.trim().toLowerCase() : '';

			if ( term && rowText( row ).indexOf( term ) === -1 ) {
				return false;
			}

			if ( statusFilter && statusFilter.value && row.dataset.status !== statusFilter.value ) {
				return false;
			}

			if ( typeFilter && typeFilter.value && row.dataset.type !== typeFilter.value ) {
				return false;
			}

			return matchesQuick( row );
		}

		function refresh() {
			var visible = 0;

			rows.forEach( function ( row ) {
				var show = matches( row );

				row.classList.toggle( 'is-hidden', ! show );

				var detail = row.nextElementSibling;
				if ( detail && detail.classList.contains( 'af-maint-detail' ) ) {
					// Collapse an expanded detail row whenever its request is filtered
					// out, so reopening it later starts from a predictable state.
					if ( ! show ) {
						detail.classList.add( 'is-hidden' );
						row.classList.remove( 'is-expanded' );
						var rowToggle = row.querySelector( '.af-maint-toggle' );
						if ( rowToggle ) {
							rowToggle.setAttribute( 'aria-expanded', 'false' );
						}
					}
				}

				if ( show ) {
					visible++;
				}
			} );

			if ( countEl ) {
				countEl.textContent = visible === rows.length
					? afMaintenance.i18n.countAll.replace( '%d', rows.length )
					: afMaintenance.i18n.countFiltered.replace( '%1$d', visible ).replace( '%2$d', rows.length );
			}

			var empty = document.getElementById( 'af-maint-empty' );
			if ( empty ) {
				empty.hidden = visible !== 0;
			}
		}

		// Sortable columns. Numeric and date columns sort on data-sort so we never
		// rely on locale-aware parsing of formatted text.
		root.querySelectorAll( '[data-sort-key]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var key = button.getAttribute( 'data-sort-key' );
				var isNumeric = 'number' === button.getAttribute( 'data-sort-type' );
				var current = button.getAttribute( 'aria-sort' );
				var dir = 'ascending' === current ? 'descending' : 'ascending';

				root.querySelectorAll( '[data-sort-key]' ).forEach( function ( other ) {
					other.setAttribute( 'aria-sort', 'none' );
				} );
				button.setAttribute( 'aria-sort', dir );

				var tbody = table.tBodies[ 0 ];
				var fragment = document.createDocumentFragment();

				// Rows and their detail siblings must travel together, so sort the
				// request rows and re-attach each detail row right after its own.
				var sorted = rows.slice().sort( function ( a, b ) {
					var av = a.dataset[ key ];
					var bv = b.dataset[ key ];

					var result = isNumeric
						? ( parseFloat( av ) || 0 ) - ( parseFloat( bv ) || 0 )
						: String( av ).localeCompare( String( bv ), undefined, { numeric: true } );
					return 'descending' === dir ? -result : result;
				} );

				sorted.forEach( function ( row ) {
					fragment.appendChild( row );

					var detail = row.nextElementSibling;
					if ( detail && detail.classList.contains( 'af-maint-detail' ) ) {
						fragment.appendChild( detail );
					}
				} );

				tbody.appendChild( fragment );
			} );
		} );

		// Expandable detail row per request.
		rows.forEach( function ( row ) {
			var toggle = row.querySelector( '.af-maint-toggle' );
			var detail = row.nextElementSibling;

			if ( ! toggle || ! detail || ! detail.classList.contains( 'af-maint-detail' ) ) {
				return;
			}

			detail.classList.add( 'is-hidden' );

			toggle.addEventListener( 'click', function () {
				var expanded = 'true' === toggle.getAttribute( 'aria-expanded' );

				toggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
				detail.classList.toggle( 'is-hidden', expanded );
				row.classList.toggle( 'is-expanded', ! expanded );
			} );
		} );

		// Clicking anywhere on the row opens it. The button stays for keyboard and
		// screen-reader users; this only adds a larger target for the mouse.
		rows.forEach( function ( row ) {
			row.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '.af-maint-toggle' ) || e.target.closest( 'a, button, select' ) ) {
					return;
				}

				var toggle = row.querySelector( '.af-maint-toggle' );
				if ( toggle ) {
					toggle.click();
				}
			} );
		} );

		// Status change. The pill and the status filter both read from the row's
		// data-status, so the row is refreshed from the response instead of
		// reloading the page: an operator working through a list of properties
		// should not lose the open panel, the sort order or the filter.
		root.addEventListener( 'change', function ( e ) {
			var select = e.target.closest( '.af-maintenance-status' );
			if ( ! select ) {
				return;
			}

			var row = select.closest( 'tr.af-maint-row' );
			var previous = row ? row.dataset.status : '';
			var next = select.value;

			select.disabled = true;

			post( {
				action: 'af_update_maintenance_status',
				nonce: afMaintenance.maintenanceNonce,
				request_id: select.getAttribute( 'data-request' ),
				status: next
			} ).then( function ( json ) {
				select.disabled = false;

				if ( ! json || ! json.success ) {
					// Roll the control back so the UI never claims a state the
					// database rejected.
					select.value = previous;

					window.alert( ( json && json.data && json.data.message ) || afMaintenance.i18n.genericError );
					return;
				}

				if ( row ) {
					row.dataset.status = next;
					var pill = row.querySelector( '.af-pill' );
					var label = select.options[ select.selectedIndex ].textContent;
					if ( pill ) {
						pill.textContent = label;
						pill.className = 'af-pill af-pill--' + statusVariant( next );
					}
					row.classList.add( 'is-flash' );
					window.setTimeout( function () {
						row.classList.remove( 'is-flash' );
					}, 1200 );
					recount();
					refresh();
				}
			} ).catch( function () {
				select.disabled = false;
				select.value = previous;
				window.alert( afMaintenance.i18n.networkError );
			} );
		} );

		// CSV export. Generated in the browser from the rows currently visible, so
		// the export always matches what the user is looking at — no stale file,
		// no server round-trip.
		var csvBtn = document.getElementById( 'af-maint-export-csv' );
		if ( csvBtn ) {
			csvBtn.addEventListener( 'click', function () {
				var visible = rows.filter( function ( row ) {
					return ! row.classList.contains( 'is-hidden' );
				} );

				if ( ! visible.length ) {
					window.alert( afMaintenance.i18n.nothingToExport );
					return;
				}

				var lines = [ visible[ 0 ].dataset.csvHeader ];
				visible.forEach( function ( row ) {
					lines.push( row.dataset.csvRow );
				} );

				// BOM keeps Excel happy with UTF-8 accents.
				var blob = new Blob( [ '﻿' + lines.join( '\r\n' ) ], { type: 'text/csv;charset=utf-8;' } );
				var url = URL.createObjectURL( blob );
				var link = document.createElement( 'a' );

				link.href = url;
				link.download = afMaintenance.csvFilename;
				document.body.appendChild( link );
				link.click();
				document.body.removeChild( link );
				URL.revokeObjectURL( url );
			} );
		}

		if ( search ) {
			search.addEventListener( 'input', function () {
				if ( searchClear ) {
					searchClear.hidden = ! search.value;
				}
				refresh();
			} );
		}

		// Search clear button.
		if ( search && searchClear ) {
			searchClear.addEventListener( 'click', function () {
				search.value = '';
				searchClear.hidden = true;
				refresh();
				search.focus();
			} );
		}

		if ( statusFilter ) {
			statusFilter.addEventListener( 'change', function () {
				syncFiltersBadge();
				refresh();
			} );
		}
		if ( typeFilter ) {
			typeFilter.addEventListener( 'change', function () {
				syncFiltersBadge();
				refresh();
			} );
		}

		// Filters popover: the two selects moved out of the toolbar so the bar
		// reads as one search box instead of three controls competing for
		// attention.
		var filtersTrigger = document.getElementById( 'af-maint-filters-trigger' );
		var filtersPanel = document.getElementById( 'af-maint-filters-panel' );
		var filtersBadge = document.getElementById( 'af-maint-filters-badge' );

		function activeFilterCount() {
			var n = 0;
			if ( statusFilter && statusFilter.value ) {
				n++;
			}
			if ( typeFilter && typeFilter.value ) {
				n++;
			}
			return n;
		}

		function syncFiltersBadge() {
			var n = activeFilterCount();

			if ( filtersBadge ) {
				filtersBadge.textContent = n;
				filtersBadge.hidden = 0 === n;
			}
			if ( filtersTrigger ) {
				filtersTrigger.classList.toggle( 'is-active', n > 0 );
			}
		}

		if ( filtersTrigger && filtersPanel ) {
			filtersTrigger.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var open = filtersPanel.hidden;
				filtersPanel.hidden = ! open;
				filtersTrigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );

			// Close on outside click or Escape — the standard popover contract.
			document.addEventListener( 'click', function ( e ) {
				if ( filtersPanel.hidden || filtersPanel.contains( e.target ) ) {
					return;
				}
				filtersPanel.hidden = true;
				filtersTrigger.setAttribute( 'aria-expanded', 'false' );
			} );

			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && ! filtersPanel.hidden ) {
					filtersPanel.hidden = true;
					filtersTrigger.setAttribute( 'aria-expanded', 'false' );
					filtersTrigger.focus();
				}
			} );
		}

		var filtersClear = document.getElementById( 'af-maint-filters-clear' );
		if ( filtersClear ) {
			filtersClear.addEventListener( 'click', function () {
				if ( statusFilter ) {
					statusFilter.value = '';
				}
				if ( typeFilter ) {
					typeFilter.value = '';
				}
				syncFiltersBadge();
				refresh();
			} );
		}

		// Quick filters from the summary strip. Clicking the active one clears it,
		// so the strip never leaves the user in a state they cannot undo by
		// clicking the same thing twice.
		if ( stats ) {
			stats.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '[data-quick-filter]' );
				if ( ! btn ) {
					return;
				}

				var next = btn.getAttribute( 'data-quick-filter' );
				quickFilter = quickFilter === next ? '' : next;

				stats.querySelectorAll( '[data-quick-filter]' ).forEach( function ( other ) {
					other.setAttribute( 'aria-pressed', other === btn && quickFilter ? 'true' : 'false' );
				} );

				refresh();
			} );
		}

		// Escape clears the search from anywhere, which is what people try first
		// when a filtered list comes back empty. "/" jumps to the search box.
		document.addEventListener( 'keydown', function ( e ) {
			if ( '/' === e.key && search && ! e.target.closest( 'input, textarea, select, [contenteditable]' ) ) {
				e.preventDefault();
				search.focus();
				return;
			}
			if ( 'Escape' !== e.key || ! search || ! search.value ) {
				return;
			}
			search.value = '';
			if ( searchClear ) {
				searchClear.hidden = true;
			}
			refresh();
		} );

		recount();
		syncFiltersBadge();
		refresh();
	}

	// ── Solicitudes: form toggle + submit ───────────────────────────────────

	function initRequestForm() {
		var toggle = document.getElementById( 'af-maint-new-request' );
		var card = document.getElementById( 'af-maint-request-form-card' );
		var form = document.getElementById( 'af-maint-request-form' );

		if ( ! toggle || ! card ) {
			return;
		}

		function openCard() {
			var tab = document.getElementById( 'af-tab-solicitudes' );
			if ( tab ) {
				tab.checked = true;
			}
			card.hidden = false;
			card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			var first = form && form.querySelector( '[name="accommodation_id"]' );
			if ( first ) {
				first.focus( { preventScroll: true } );
			}
		}

		toggle.addEventListener( 'click', function () {
			if ( card.hidden ) {
				openCard();
			} else {
				card.hidden = true;
			}
		} );

		document.querySelectorAll( '[data-af-open-request]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', openCard );
		} );

		var cancel = document.getElementById( 'af-maint-request-cancel' );
		function closeCard() {
			card.hidden = true;
			if ( form ) {
				form.reset();
				form.dispatchEvent( new Event( 'af:reset' ) );
			}
		}
		if ( cancel ) {
			cancel.addEventListener( 'click', closeCard );
		}
		card.querySelectorAll( '[data-af-close-request]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', closeCard );
		} );

		if ( ! form ) {
			return;
		}

		// Switching between "pick from catalog" and "type it myself".
		var providerSelect = form.querySelector( '[name="provider_id"]' );
		var manual = document.getElementById( 'af-maint-manual-contact' );

		function syncManual() {
			if ( ! providerSelect || ! manual ) {
				return;
			}
			manual.classList.toggle( 'is-visible', 'manual' === providerSelect.value );
		}

		if ( providerSelect ) {
			providerSelect.addEventListener( 'change', syncManual );
		}
		syncManual();

		// Units depend on the chosen property.
		var propertySelect = form.querySelector( '[name="accommodation_id"]' );
		var unitSelect = document.getElementById( 'af-maint-unit' );

		function syncUnits() {
			if ( ! propertySelect || ! unitSelect ) {
				return;
			}
			var units = ( afMaintenance.units || {} )[ propertySelect.value ] || [];

			unitSelect.innerHTML = '';
			unitSelect.appendChild( new Option( afMaintenance.i18n.noUnit, '' ) );
			units.forEach( function ( unit ) {
				unitSelect.appendChild( new Option( afMaintenance.i18n.unitPrefix + ' ' + unit.code, unit.id ) );
			} );
			unitSelect.disabled = ! units.length;
			var unitWrap = document.getElementById( 'af-maint-unit-wrap' );
			if ( unitWrap ) {
				unitWrap.hidden = ! units.length;
			}
			if ( 1 === units.length ) {
				unitSelect.value = String( units[ 0 ].id );
			}
		}

		if ( propertySelect ) {
			propertySelect.addEventListener( 'change', syncUnits );
		}
		syncUnits();

		form.addEventListener( 'af:reset', function () {
			syncUnits();
			syncManual();
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var btn = form.querySelector( 'button[type="submit"]' );
			var status = document.getElementById( 'af-maint-request-status' );

			if ( btn ) {
				btn.disabled = true;
			}
			if ( status ) {
				status.textContent = '';
				status.className = 'af-modal__status';
			}

			var body = new URLSearchParams( new FormData( form ) );
			body.append( 'action', 'af_create_maintenance' );
			body.append( 'nonce', afMaintenance.maintenanceNonce );

			post( Object.fromEntries( body ) )
				.then( function ( json ) {
					if ( btn ) {
						btn.disabled = false;
					}

					if ( ! json || ! json.success ) {
						if ( status ) {
							status.textContent = ( json && json.data && json.data.message ) || afMaintenance.i18n.genericError;
							status.className = 'af-modal__status is-error';
						}
						return;
					}

					if ( status ) {
						status.textContent = json.data.message;
						status.className = 'af-modal__status is-success';
					}
					window.setTimeout( function () {
						window.location.reload();
					}, 700 );
				} )
				.catch( function () {
					if ( btn ) {
						btn.disabled = false;
					}
					if ( status ) {
						status.textContent = afMaintenance.i18n.networkError;
						status.className = 'af-modal__status is-error';
					}
				} );
		} );
	}

	// ── Catálogo de personal ────────────────────────────────────────────────

	function initProviders() {
		var root = document.getElementById( 'af-maint-providers' );
		if ( ! root ) {
			return;
		}

		var search = document.getElementById( 'af-prov-search-input' );
		var tradeFilter = document.getElementById( 'af-prov-trade-filter' );
		var cards = Array.prototype.slice.call( root.querySelectorAll( '.af-prov-card' ) );
		var countEl = document.getElementById( 'af-prov-count' );

		function refresh() {
			var term = search ? search.value.trim().toLowerCase() : '';
			var trade = tradeFilter ? tradeFilter.value : '';
			var visible = 0;

			cards.forEach( function ( card ) {
				var show = true;

				if ( term && ( card.dataset.search || '' ).indexOf( term ) === -1 ) {
					show = false;
				}
				if ( trade && card.dataset.trade !== trade ) {
					show = false;
				}

				card.classList.toggle( 'is-hidden', ! show );

				if ( show ) {
					visible++;
				}
			} );

			if ( countEl ) {
				countEl.textContent = visible === cards.length
					? afMaintenance.i18n.countAll.replace( '%d', cards.length )
					: afMaintenance.i18n.countFiltered.replace( '%1$d', visible ).replace( '%2$d', cards.length );
			}

			var empty = document.getElementById( 'af-prov-empty' );
			if ( empty ) {
				empty.hidden = visible !== 0;
			}
		}

		if ( search ) {
			search.addEventListener( 'input', refresh );
		}
		if ( tradeFilter ) {
			tradeFilter.addEventListener( 'change', refresh );
		}

		refresh();

		// Create form.
		var toggle = document.getElementById( 'af-prov-new' );
		var formCard = document.getElementById( 'af-prov-form-card' );
		var form = document.getElementById( 'af-prov-form' );

		var idField = document.getElementById( 'af-prov-id' );
		var formTitle = document.getElementById( 'af-prov-form-title' );
		var submitBtn = form ? form.querySelector( 'button[type="submit"]' ) : null;

		/**
		 * Puts the form back in "create" mode. Called before every fresh open so
		 * an edit left half-finished never leaks its id into a new contact.
		 */
		function resetForm() {
			if ( form ) {
				form.reset();
			}
			if ( idField ) {
				idField.value = '';
			}
			if ( formTitle ) {
				formTitle.textContent = afMaintenance.i18n.newContactTitle;
			}
			if ( submitBtn ) {
				submitBtn.textContent = afMaintenance.i18n.saveContact;
			}
		}

		var cancel = document.getElementById( 'af-prov-cancel' );
		if ( cancel && formCard ) {
			cancel.addEventListener( 'click', function () {
				formCard.hidden = true;
				resetForm();
			} );
		}

		if ( toggle && formCard ) {
			toggle.addEventListener( 'click', function () {
				if ( formCard.hidden ) {
					resetForm();
				}
				formCard.hidden = ! formCard.hidden;
			} );
		}

		var headerNew = document.getElementById( 'af-maint-new-contact' );
		if ( headerNew && formCard ) {
			headerNew.addEventListener( 'click', function () {
				var tab = document.getElementById( 'af-tab-personal' );
				if ( tab ) {
					tab.checked = true;
				}
				resetForm();
				formCard.hidden = false;
				formCard.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				if ( form ) {
					form.elements.name.focus( { preventScroll: true } );
				}
			} );
		}

		if ( ! form ) {
			return;
		}

		// Edit: load the card's data into the same form. Reusing one form keeps a
		// single set of fields and a single submit handler.
		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.af-prov-edit' );
			if ( ! btn || ! formCard ) {
				return;
			}

			var card = btn.closest( '.af-prov-card' );
			if ( ! card ) {
				return;
			}

			resetForm();

			// data-* keys are camelCase-mapped by dataset; the rates carry an
			// explicit float because the DB stores them as decimal.
			form.elements.name.value = card.dataset.name || '';
			form.elements.trade.value = card.dataset.tradeValue || 'general';
			form.elements.company.value = card.dataset.company || '';
			form.elements.phone.value = card.dataset.phone || '';
			form.elements.whatsapp.value = card.dataset.whatsapp || '';
			form.elements.email.value = card.dataset.email || '';
			form.elements.city.value = card.dataset.city || '';
			form.elements.zone.value = card.dataset.zone || '';
			form.elements.hourly_rate.value = card.dataset.hourlyRate || '0.00';
			form.elements.job_price.value = card.dataset.jobPrice || '0.00';
			form.elements.notes.value = card.dataset.notes || '';

			if ( idField ) {
				idField.value = btn.getAttribute( 'data-id' );
			}
			if ( formTitle ) {
				formTitle.textContent = afMaintenance.i18n.editContactTitle;
			}
			if ( submitBtn ) {
				submitBtn.textContent = afMaintenance.i18n.saveChanges;
			}

			formCard.hidden = false;
			form.elements.name.focus();
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var btn = form.querySelector( 'button[type="submit"]' );
			var status = document.getElementById( 'af-prov-status' );

			if ( btn ) {
				btn.disabled = true;
			}
			if ( status ) {
				status.textContent = '';
				status.className = 'af-modal__status';
			}

			// Same endpoint for both modes; the id decides which one the server runs.
			var editing = idField && idField.value;

			var body = new URLSearchParams( new FormData( form ) );
			body.append( 'action', editing ? 'af_sp_update' : 'af_sp_create' );
			body.append( 'nonce', afMaintenance.providerNonce );

			post( Object.fromEntries( body ) )
				.then( function ( json ) {
					if ( btn ) {
						btn.disabled = false;
					}

					if ( ! json || ! json.success ) {
						if ( status ) {
							status.textContent = ( json && json.data && json.data.message ) || afMaintenance.i18n.genericError;
							status.className = 'af-modal__status is-error';
						}
						return;
					}

					if ( status ) {
						status.textContent = json.data.message;
						status.className = 'af-modal__status is-success';
					}
					window.setTimeout( function () {
						window.location.reload();
					}, 700 );
				} )
				.catch( function () {
					if ( btn ) {
						btn.disabled = false;
					}
					if ( status ) {
						status.textContent = afMaintenance.i18n.networkError;
						status.className = 'af-modal__status is-error';
					}
				} );
		} );

		// Delete (delegated: the grid is re-rendered on every page load).
		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.af-prov-delete' );
			if ( ! btn ) {
				return;
			}

			if ( ! window.confirm( afMaintenance.i18n.confirmDelete ) ) {
				return;
			}

			btn.disabled = true;

			post( {
				action: 'af_sp_delete',
				nonce: afMaintenance.providerNonce,
				id: btn.getAttribute( 'data-id' )
			} ).then( function ( json ) {
				if ( json && json.success ) {
					window.location.reload();
					return;
				}

				btn.disabled = false;
				window.alert( ( json && json.data && json.data.message ) || afMaintenance.i18n.genericError );
			} ).catch( function () {
				btn.disabled = false;
				window.alert( afMaintenance.i18n.networkError );
			} );
		} );
	}

	ready( function () {
		if ( 'undefined' === typeof afMaintenance ) {
			return;
		}

		initRequests();
		initRequestForm();
		initProviders();
	} );
} )();