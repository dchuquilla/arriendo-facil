( function () {
	'use strict';

	var i18n = window.afCatalogs || {};

	function copy( text, btn ) {
		var done = function () {
			var old = btn.textContent;
			btn.textContent = i18n.copied || 'OK';
			setTimeout( function () {
				btn.textContent = old;
			}, 1800 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
			return;
		}
		var t = document.createElement( 'textarea' );
		t.value = text;
		document.body.appendChild( t );
		t.select();
		try {
			document.execCommand( 'copy' );
			done();
		} catch ( e ) {}
		document.body.removeChild( t );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-af-copy]' );
		if ( btn ) {
			e.preventDefault();
			copy( btn.getAttribute( 'data-af-copy' ), btn );
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		if ( e.target.matches( '[data-af-confirm]' ) && ! window.confirm( i18n.confirm || '?' ) ) {
			e.preventDefault();
		}
	} );

	var editor = document.querySelector( '[data-af-cats-editor]' );
	if ( ! editor ) {
		return;
	}

	var rows = Array.prototype.slice.call( editor.querySelectorAll( '.af-cats-row' ) );
	var search = editor.querySelector( '[data-af-cats-search]' );
	var onlyAvail = editor.querySelector( '[data-af-cats-available]' );
	var count = editor.querySelector( '[data-af-cats-count]' );
	var empty = editor.querySelector( '.af-cats-empty' );

	function visible( row ) {
		return ! row.hidden;
	}

	function updateCount() {
		var n = editor.querySelectorAll( 'input[name="property_ids[]"]:checked' ).length;
		count.textContent = n + ' ' + ( 1 === n ? i18n.selected : i18n.selectedP );
	}

	function filter() {
		var q = search.value.trim().toLowerCase();
		var shown = 0;
		rows.forEach( function ( row ) {
			var ok = ( ! q || row.getAttribute( 'data-text' ).indexOf( q ) !== -1 ) &&
				( ! onlyAvail.checked || 'available' === row.getAttribute( 'data-status' ) );
			row.hidden = ! ok;
			if ( ok ) {
				shown++;
			}
		} );
		empty.hidden = shown > 0 || ! rows.length;
		empty.textContent = i18n.none || '';
	}

	function setAll( value ) {
		rows.filter( visible ).forEach( function ( row ) {
			row.querySelector( 'input' ).checked = value;
		} );
		updateCount();
	}

	search.addEventListener( 'input', filter );
	onlyAvail.addEventListener( 'change', filter );
	editor.addEventListener( 'change', updateCount );
	editor.querySelector( '[data-af-cats-all]' ).addEventListener( 'click', function () {
		setAll( true );
	} );
	editor.querySelector( '[data-af-cats-none]' ).addEventListener( 'click', function () {
		setAll( false );
	} );
	updateCount();
}() );
