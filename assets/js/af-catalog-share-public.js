/**
 * Public shared catalog interactions: flip cards and client-side filters.
 */
( function () {
	'use strict';

	var i18n = window.afCatalogPublic || {};

	function label( key, fallback ) {
		return typeof i18n[ key ] === 'string' && i18n[ key ] ? i18n[ key ] : fallback;
	}

	/* -------------------------------------------------------------- Flip */

	function initFlip( root ) {
		var cards = root.querySelectorAll( '[data-af-cs-card]' );

		Array.prototype.forEach.call( cards, function ( card ) {
			var toggles = card.querySelectorAll( '[data-af-cs-flip]' );

			Array.prototype.forEach.call( toggles, function ( toggle ) {
				toggle.addEventListener( 'click', function () {
					var flipped = card.classList.toggle( 'is-flipped' );

					Array.prototype.forEach.call( card.querySelectorAll( '[data-af-cs-flip-text]' ), function ( el ) {
						el.textContent = flipped
							? label( 'backLabel', 'Volver' )
							: label( 'flipLabel', 'Ver más detalles' );
					} );

					Array.prototype.forEach.call( card.querySelectorAll( '[data-af-cs-flip]' ), function ( btn ) {
						btn.setAttribute( 'aria-expanded', flipped ? 'true' : 'false' );
					} );
				} );
			} );
		} );
	}

	/* ------------------------------------------------------------ Filter */

	function initFilters( root ) {
		var search = root.querySelector( '#af-cs-q' );
		var status = root.querySelector( '#af-cs-status' );
		var type = root.querySelector( '#af-cs-type' );
		var beds = root.querySelector( '#af-cs-beds' );
		var empty = root.querySelector( '[data-af-cs-empty]' );
		var counter = root.querySelector( '[data-af-cs-count]' );
		var groups = Array.prototype.slice.call( root.querySelectorAll( '[data-af-cs-group]' ) );

		if ( ! groups.length ) {
			return;
		}

		var counterText = function ( n ) {
			var noun = n === 1
				? label( 'countLabel', 'propiedad' )
				: label( 'countLabelPlural', 'propiedades' );

			return n + ' ' + noun;
		};

		var unitText = function ( n ) {
			var noun = n === 1
				? label( 'unitLabel', 'inmueble' )
				: label( 'unitLabelPlural', 'inmuebles' );

			return n + ' ' + noun;
		};

		function apply() {
			var q = ( search && search.value ? search.value : '' )
				.trim()
				.toLowerCase();
			var wantStatus = status && status.value ? status.value : '';
			var wantType = type && type.value ? type.value : '';
			var minBeds = beds && beds.value ? parseInt( beds.value, 10 ) : 0;

			var total = 0;

			groups.forEach( function ( group ) {
				var shownInGroup = 0;

				Array.prototype.forEach.call(
					group.querySelectorAll( '[data-af-cs-card]' ),
					function ( card ) {
						var haystack = card.getAttribute( 'data-af-cs-search' ) || '';
						var ok = true;

						if ( q && haystack.indexOf( q ) === -1 ) {
							ok = false;
						}

						if ( ok && wantStatus && card.getAttribute( 'data-af-cs-status' ) !== wantStatus ) {
							ok = false;
						}

						if ( ok && wantType && card.getAttribute( 'data-af-cs-type' ) !== wantType ) {
							ok = false;
						}

						if ( ok && minBeds ) {
							var cardBeds = parseInt( card.getAttribute( 'data-af-cs-beds' ) || '0', 10 );

							if ( cardBeds < minBeds ) {
								ok = false;
							}
						}

						card.hidden = ! ok;

						if ( ok ) {
							shownInGroup++;
							total++;
						}
					}
				);

				// Hide the whole section, heading included, when nothing inside it matches.
				group.hidden = shownInGroup === 0;

				var badge = group.querySelector( '[data-af-cs-group-count]' );

				if ( badge ) {
					badge.textContent = unitText( shownInGroup );
				}
			} );

			if ( empty ) {
				empty.hidden = total !== 0;
			}

			if ( counter ) {
				var filtering = !! q || !! wantStatus || !! wantType || !! minBeds;

				counter.hidden = ! filtering;
				counter.textContent = filtering ? counterText( total ) : '';
			}
		}

		[ search, status, type, beds ].forEach( function ( control ) {
			if ( ! control ) {
				return;
			}

			control.addEventListener( 'input', apply );
			control.addEventListener( 'change', apply );
		} );

		apply();
	}

	/* -------------------------------------------------------------- Boot */

	function boot() {
		var root = document.querySelector( '.af-cs-page' );

		if ( ! root ) {
			return;
		}

		initFlip( root );
		initFilters( root );

		var print = root.querySelector( '.af-cs-print' );

		if ( print ) {
			print.addEventListener( 'click', function () {
				window.print();
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
