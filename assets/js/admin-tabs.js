/**
 * Generic client-side tab filter: click a `.af-status-tabs__btn` to show only the
 * rows/cards inside its `data-tabs-target` whose `data-tab-group` matches
 * the button's `data-tab-value` (empty value = show all).
 */
( function () {
	function initTabs( tabs ) {
		var targetSelector = tabs.getAttribute( 'data-tabs-target' );
		var target = targetSelector ? document.querySelector( targetSelector ) : null;
		if ( ! target ) {
			return;
		}
		var rows = target.querySelectorAll( '[data-tab-group]' );

		tabs.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.af-status-tabs__btn' );
			if ( ! btn ) {
				return;
			}
			tabs.querySelectorAll( '.af-status-tabs__btn' ).forEach( function ( b ) {
				b.classList.remove( 'is-active' );
			} );
			btn.classList.add( 'is-active' );

			var value = btn.getAttribute( 'data-tab-value' ) || '';
			rows.forEach( function ( row ) {
				var matches = '' === value || row.getAttribute( 'data-tab-group' ) === value;
				row.style.display = matches ? '' : 'none';
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.af-status-tabs[data-tabs-target]' ).forEach( initTabs );
	} );
} )();
