( function () {
	'use strict';

	var cfgEl = document.getElementById( 'af-rv-config' );
	if ( ! cfgEl ) {
		return;
	}
	var cfg = JSON.parse( cfgEl.textContent );
	var L = cfg.labels;
	var page = document.querySelector( '.af-reviews-page' );

	/* ── Tabs, búsqueda y filtros ─────────────────────────────────────── */
	var tabs = page.querySelectorAll( '[data-rv-tab]' );
	var panels = page.querySelectorAll( '[data-rv-panel]' );
	var chips = page.querySelector( '[data-rv-chips]' );
	var search = document.getElementById( 'af-rv-search' );
	var noMatch = document.getElementById( 'af-rv-nomatch' );
	var current = '';
	var tone = '';

	function applyFilters() {
		var q = ( search.value || '' ).trim().toLowerCase();
		var panel = page.querySelector( '[data-rv-panel="' + current + '"]' );
		if ( ! panel ) {
			return;
		}
		var items = panel.querySelectorAll( '[data-rv-item]' );
		var visible = 0;
		items.forEach( function ( item ) {
			var okText = ! q || ( item.getAttribute( 'data-search' ) || '' ).indexOf( q ) !== -1;
			var okTone = ! tone || 'rated' !== current || item.getAttribute( 'data-tone' ) === tone;
			var show = okText && okTone;
			item.hidden = ! show;
			if ( show ) {
				visible++;
			}
		} );
		noMatch.hidden = ! ( items.length && 0 === visible );
		noMatch.textContent = L.noMatch;
	}

	function showTab( name ) {
		if ( ! page.querySelector( '[data-rv-panel="' + name + '"]' ) ) {
			name = panels.length ? panels[ 0 ].getAttribute( 'data-rv-panel' ) : '';
		}
		current = name;
		tabs.forEach( function ( t ) {
			var on = t.getAttribute( 'data-rv-tab' ) === name;
			t.classList.toggle( 'is-active', on );
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		panels.forEach( function ( p ) {
			p.hidden = p.getAttribute( 'data-rv-panel' ) !== name;
		} );
		if ( chips ) {
			chips.hidden = 'rated' !== name;
		}
		try {
			sessionStorage.setItem( 'afRvTab', name );
		} catch ( e ) {}
		applyFilters();
	}

	tabs.forEach( function ( t ) {
		t.addEventListener( 'click', function () {
			showTab( t.getAttribute( 'data-rv-tab' ) );
		} );
	} );
	page.querySelectorAll( '[data-rv-goto]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			showTab( b.getAttribute( 'data-rv-goto' ) );
		} );
	} );
	if ( chips ) {
		chips.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-rv-filter]' );
			if ( ! btn ) {
				return;
			}
			chips.querySelectorAll( '.af-rv-chip' ).forEach( function ( c ) {
				c.classList.toggle( 'is-active', c === btn );
			} );
			tone = btn.getAttribute( 'data-rv-filter' ) || '';
			applyFilters();
		} );
	}
	search.addEventListener( 'input', applyFilters );

	var saved = '';
	try {
		saved = sessionStorage.getItem( 'afRvTab' ) || '';
	} catch ( e ) {}
	showTab( saved && page.querySelector( '[data-rv-panel="' + saved + '"]' ) ? saved : window.afRvDefaultTab );

	/* ── Panel lateral de calificación ────────────────────────────────── */
	var drawer = document.getElementById( 'af-rv-drawer' );
	if ( ! drawer ) {
		return;
	}
	var elTitle = document.getElementById( 'af-rv-drawer-title' );
	var elSub = document.getElementById( 'af-rv-drawer-sub' );
	var elAvatar = document.getElementById( 'af-rv-drawer-avatar' );
	var elSuggest = document.getElementById( 'af-rv-suggest' );
	var elCriteria = document.getElementById( 'af-rv-criteria' );
	var elComment = document.getElementById( 'af-rv-comment' );
	var elCounter = document.getElementById( 'af-rv-counter' );
	var elTotal = document.getElementById( 'af-rv-total' );
	var elStatus = document.getElementById( 'af-rv-status' );
	var btnSave = document.getElementById( 'af-rv-save' );
	var mode = null;
	var targetId = 0;
	var keys = [];
	var lastFocus = null;

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function initials( name ) {
		var p = String( name || '' ).trim().split( /\s+/ ).filter( Boolean );
		if ( ! p.length ) {
			return '?';
		}
		return ( p[ 0 ].charAt( 0 ) + ( p.length > 1 ? p[ p.length - 1 ].charAt( 0 ) : '' ) ).toUpperCase();
	}

	function buildCriteria( conf ) {
		keys = Object.keys( conf.criteria );
		elCriteria.innerHTML = keys.map( function ( key ) {
			var stars = '';
			for ( var s = 5; s >= 1; s-- ) {
				var id = 'rv-' + key + '-' + s;
				stars += '<input type="radio" name="rv_' + key + '" id="' + id + '" value="' + s + '">' +
					'<label for="' + id + '" title="' + esc( L[ s ] ) + '"><span class="screen-reader-text">' + s + '</span>★</label>';
			}
			return '<div class="af-rv-field af-rv-crit-field" data-key="' + key + '">' +
				'<div class="af-rv-crit-field__text"><strong>' + esc( conf.criteria[ key ] ) + '</strong>' +
				( conf.hints && conf.hints[ key ] ? '<span>' + esc( conf.hints[ key ] ) + '</span>' : '' ) + '</div>' +
				'<div class="af-rv-rate"><div class="af-rv-starinput" role="radiogroup" aria-label="' + esc( conf.criteria[ key ] ) + '">' + stars + '</div>' +
				'<span class="af-rv-rate__word" data-word></span></div></div>';
		} ).join( '' );
	}

	function valueOf( key ) {
		var c = elCriteria.querySelector( 'input[name="rv_' + key + '"]:checked' );
		return c ? parseInt( c.value, 10 ) : 0;
	}

	function setValue( key, val ) {
		var input = elCriteria.querySelector( 'input[name="rv_' + key + '"][value="' + val + '"]' );
		if ( input ) {
			input.checked = true;
		}
	}

	function refresh() {
		var sum = 0;
		var done = 0;
		keys.forEach( function ( key ) {
			var v = valueOf( key );
			var word = elCriteria.querySelector( '[data-key="' + key + '"] [data-word]' );
			word.textContent = v ? L[ v ] : '';
			if ( v ) {
				sum += v;
				done++;
			}
		} );
		if ( done === keys.length ) {
			var avg = sum / done;
			var t = avg >= 4 ? 'good' : ( avg >= 3 ? 'mid' : 'bad' );
			elTotal.className = 'af-rv-total af-rv-tone--' + t;
			elTotal.innerHTML = '<span>' + esc( L.overall ) + '</span><strong>' + avg.toFixed( 1 ).replace( '.', ',' ) + '</strong>' +
				'<span class="af-rv-stars" style="--pct:' + Math.round( avg / 5 * 100 ) + '%"></span>';
		} else {
			elTotal.className = 'af-rv-total';
			elTotal.innerHTML = '<span>' + esc( L.pick ) + ' (' + done + '/' + keys.length + ')</span>';
		}
		btnSave.disabled = done !== keys.length;
	}

	function setStatus( msg, kind ) {
		elStatus.textContent = msg || '';
		elStatus.className = 'af-rv-status' + ( kind ? ' is-' + kind : '' );
	}

	function openDrawer( btn ) {
		mode = btn.getAttribute( 'data-rv-open' );
		var conf = cfg[ mode ];
		targetId = btn.getAttribute( 'data-id' );
		lastFocus = btn;

		buildCriteria( conf );
		elTitle.textContent = btn.getAttribute( 'data-name' );
		elSub.textContent = conf.title + ' · ' + ( btn.getAttribute( 'data-context' ) || '' );
		elAvatar.textContent = initials( btn.getAttribute( 'data-name' ) );
		elComment.placeholder = conf.placeholder;
		elComment.value = btn.getAttribute( 'data-comment' ) || '';
		elCounter.textContent = elComment.value.length + ' / 600';
		btnSave.textContent = conf.save;
		setStatus( '' );

		var existing = {};
		try {
			existing = JSON.parse( btn.getAttribute( 'data-scores' ) || '{}' ) || {};
		} catch ( e ) {}
		keys.forEach( function ( key ) {
			var v = parseInt( existing[ key ], 10 );
			if ( v >= 1 && v <= 5 ) {
				setValue( key, v );
			}
		} );

		elSuggest.hidden = true;
		elSuggest.innerHTML = '';
		var sug = parseInt( btn.getAttribute( 'data-suggested' ), 10 );
		if ( 'tenant' === mode && sug >= 1 && sug <= 5 ) {
			elSuggest.hidden = false;
			elSuggest.innerHTML = '<div><strong>' + esc( L.suggest ) + ': ' + sug + '★ ' + esc( L[ sug ] ) + '</strong>' +
				'<span>' + esc( btn.getAttribute( 'data-suggested-note' ) ) + '</span></div>' +
				'<button type="button" class="button" data-rv-apply="' + sug + '">' + esc( L.apply ) + '</button>';
			if ( ! valueOf( 'puntualidad_pago' ) ) {
				setValue( 'puntualidad_pago', sug );
				elSuggest.classList.add( 'is-applied' );
				elSuggest.querySelector( 'span' ).textContent = L.applied;
				elSuggest.querySelector( '[data-rv-apply]' ).hidden = true;
			} else {
				elSuggest.classList.remove( 'is-applied' );
			}
		}

		refresh();
		drawer.hidden = false;
		requestAnimationFrame( function () {
			drawer.classList.add( 'is-open' );
		} );
		document.body.classList.add( 'af-rv-lock' );
		var first = elCriteria.querySelector( 'input' );
		if ( first ) {
			first.focus( { preventScroll: true } );
		}
	}

	function closeDrawer() {
		drawer.classList.remove( 'is-open' );
		document.body.classList.remove( 'af-rv-lock' );
		setTimeout( function () {
			drawer.hidden = true;
		}, 220 );
		if ( lastFocus ) {
			lastFocus.focus();
		}
	}

	page.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-rv-open]' );
		if ( btn ) {
			openDrawer( btn );
		}
	} );
	drawer.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-rv-close]' ) ) {
			closeDrawer();
			return;
		}
		var apply = e.target.closest( '[data-rv-apply]' );
		if ( apply ) {
			setValue( 'puntualidad_pago', apply.getAttribute( 'data-rv-apply' ) );
			elSuggest.classList.add( 'is-applied' );
			elSuggest.querySelector( 'span' ).textContent = L.applied;
			apply.hidden = true;
			refresh();
		}
	} );
	elCriteria.addEventListener( 'change', function () {
		refresh();
		setStatus( '' );
	} );
	elComment.addEventListener( 'input', function () {
		elCounter.textContent = elComment.value.length + ' / 600';
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! drawer.hidden ) {
			closeDrawer();
		}
	} );

	btnSave.addEventListener( 'click', function () {
		var conf = cfg[ mode ];
		var body = new URLSearchParams();
		body.append( 'action', conf.action );
		body.append( 'nonce', conf.nonce );
		body.append( conf.idField, targetId );
		body.append( 'comment', elComment.value );
		keys.forEach( function ( key ) {
			body.append( key, valueOf( key ) );
		} );

		btnSave.disabled = true;
		setStatus( L.saving );
		fetch( cfg.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					btnSave.disabled = false;
					setStatus( ( json && json.data && json.data.message ) || L.error, 'error' );
					return;
				}
				setStatus( json.data.message, 'success' );
				try {
					sessionStorage.setItem( 'afRvTab', 'tenant' === mode ? 'rated' : 'managers' );
				} catch ( e ) {}
				setTimeout( function () {
					window.location.reload();
				}, 600 );
			} )
			.catch( function () {
				btnSave.disabled = false;
				setStatus( L.error, 'error' );
			} );
	} );
}() );
