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

	/* ------------------------------------------------------------ Sizing */

	/*
	 * A flip card keeps both faces out of the flow, so nothing knows how tall
	 * the details side really is. Rather than let it scroll inside a fixed
	 * card, the tallest face is measured once and applied as a min-height:
	 * the grid then stretches every card in the row to the same height and
	 * the row never changes when a card is flipped.
	 */
	function initSizing( root ) {
		var cards = root.querySelectorAll( '[data-af-cs-card]' );

		if ( ! cards.length ) {
			return;
		}

		/*
		 * The description is clamped, so the button that unfolds it only makes
		 * sense while there is something hidden behind the clamp.
		 */
		function refreshClamp( card ) {
			var excerpt = card.querySelector( '[data-af-cs-excerpt]' );
			var more = card.querySelector( '[data-af-cs-more]' );

			if ( ! excerpt || ! more ) {
				return;
			}

			if ( excerpt.classList.contains( 'is-expanded' ) ) {
				more.hidden = false;
				return;
			}

			more.hidden = excerpt.scrollHeight <= excerpt.clientHeight + 1;
		}

		function measure( card ) {
			var front = card.querySelector( '.af-cs-card__face--front' );
			var back = card.querySelector( '.af-cs-card__face--back' );

			[ front, back ].forEach( function ( face ) {
				if ( face ) {
					face.style.position = 'static';
				}
			} );

			// One read pass, so the batch of writes above costs a single reflow.
			var needed = Math.max(
				front ? front.offsetHeight : 0,
				back ? back.offsetHeight : 0
			);

			[ front, back ].forEach( function ( face ) {
				if ( face ) {
					face.style.position = '';
				}
			} );

			if ( needed > 0 ) {
				card.style.minHeight = needed + 'px';
			}

			refreshClamp( card );
		}

		function sizeAll() {
			var visible = [];

			Array.prototype.forEach.call( cards, function ( card ) {
				if ( ! card.hidden ) {
					visible.push( card );
				}
			} );

			visible.forEach( function ( card ) {
				[ '.af-cs-card__face--front', '.af-cs-card__face--back' ].forEach( function ( selector ) {
					var face = card.querySelector( selector );

					if ( face ) {
						face.style.position = 'static';
					}
				} );
			} );

			visible.forEach( measure );
		}

		function initExpanders() {
			Array.prototype.forEach.call( cards, function ( card ) {
				bindExpander(
					card,
					'[data-af-cs-more]',
					'[data-af-cs-excerpt]',
					'[data-af-cs-more-text]',
					'moreLabel', 'Leer más',
					'lessLabel', 'Mostrar menos',
					true
				);
				bindExpander(
					card,
					'[data-af-cs-more-chips]',
					'[data-af-cs-chips-rest]',
					'[data-af-cs-more-chips-text]',
					'chipsMoreLabel', '',
					'chipsLessLabel', 'Mostrar menos',
					false
				);
			} );
		}

		/*
		 * Both expanders share the same contract: reveal something the card
		 * was hiding, then let the card grow to fit it instead of scrolling.
		 * An empty fallback means the server already wrote a better label
		 * (the chip count, for instance) and it is left alone.
		 */
		function bindExpander( card, buttonSelector, targetSelector, textSelector, openKey, openFallback, closeKey, closeFallback, isClass ) {
			var button = card.querySelector( buttonSelector );
			var target = card.querySelector( targetSelector );

			if ( ! button || ! target ) {
				return;
			}

			var text = button.querySelector( textSelector );

			// The server label wins when no translated one exists (chip count).
			var original = text ? text.textContent : '';

			button.addEventListener( 'click', function () {
				var expanded;

				if ( isClass ) {
					expanded = target.classList.toggle( 'is-expanded' );
				} else {
					expanded = target.hasAttribute( 'hidden' );

					if ( expanded ) {
						target.removeAttribute( 'hidden' );
					} else {
						target.setAttribute( 'hidden', 'hidden' );
					}
				}

				button.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );

				if ( text ) {
					// Expanded means the panel is open, so the label offers to close it.
					text.textContent = expanded
						? label( closeKey, closeFallback )
						: label( openKey, openFallback ) || original;
				}

				measure( card );
			} );
		}

		sizeAll();
		initExpanders();

		// Web fonts and images can still be settling on DOMContentLoaded.
		window.addEventListener( 'load', sizeAll );

		var timer = null;

		window.addEventListener( 'resize', function () {
			if ( timer ) {
				clearTimeout( timer );
			}

			timer = setTimeout( sizeAll, 150 );
		} );
	}

	/* ---------------------------------------------------------- Carousel */

	function initCarousels( root ) {
		Array.prototype.forEach.call( root.querySelectorAll( '[data-af-cs-carousel]' ), function ( carousel ) {
			var track = carousel.querySelector( '[data-af-cs-track]' );
			var dots = carousel.querySelectorAll( '.af-cs-carousel__dot' );
			var total = track ? track.children.length : 0;

			if ( ! track || total < 2 ) {
				return;
			}

			function current() {
				return Math.round( track.scrollLeft / Math.max( 1, track.clientWidth ) );
			}

			function go( index ) {
				var next = ( index + total ) % total;
				track.scrollTo( { left: next * track.clientWidth, behavior: 'smooth' } );
			}

			carousel.querySelector( '[data-af-cs-prev]' ).addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				go( current() - 1 );
			} );
			carousel.querySelector( '[data-af-cs-next]' ).addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				go( current() + 1 );
			} );

			track.addEventListener( 'scroll', function () {
				var idx = current();
				Array.prototype.forEach.call( dots, function ( dot, i ) {
					dot.classList.toggle( 'is-active', i === idx );
				} );
			}, { passive: true } );
		} );
	}

	/* -------------------------------------------------------------- Boot */

	function boot() {
		var root = document.querySelector( '.af-cs-page' );

		if ( ! root ) {
			return;
		}

		initFlip( root );
		initCarousels( root );
		initSizing( root );
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
