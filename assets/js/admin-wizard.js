/**
 * Accommodation wizard: step navigation, validation, featured image picker.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.af-wizard' );
	if ( ! root ) {
		return;
	}

	document.getElementById( 'wpwrap' ).classList.add( 'af-wizard-mounted' );

	var cfg     = window.afWizard || {};
	var mode    = cfg.mode || 'create';
	var i18n    = cfg.i18n || {};
	var form    = root.querySelector( '.af-wizard__form' );
	var steps   = [].slice.call( root.querySelectorAll( '.af-wizard__step' ) );
	var navItems= [].slice.call( root.querySelectorAll( '.af-wizard__step-item' ) );
	var btnNext = root.querySelector( '[data-af-next]' );
	var btnPrev = root.querySelector( '[data-af-prev]' );
	var btnPub  = root.querySelector( '.af-wizard__publish' );
	var hiddenAction = document.getElementById( 'af_form_action' );

	var totalSteps = steps.length;
	var current    = 1;

	/* ---------- Step navigation (create mode) ---------- */

	function showStep( n ) {
		current = Math.max( 1, Math.min( totalSteps, n ) );
		steps.forEach( function ( s ) {
			s.classList.toggle( 'is-current', parseInt( s.dataset.step, 10 ) === current );
		} );
		navItems.forEach( function ( item ) {
			var stepNum = parseInt( item.dataset.step, 10 );
			item.classList.toggle( 'is-current', stepNum === current );
			item.classList.toggle( 'is-done', stepNum < current );
		} );
		if ( btnPrev ) {
			btnPrev.hidden = current === 1;
		}
		if ( btnNext && btnPub ) {
			var isLast = current === totalSteps;
			btnNext.hidden = isLast;
			btnPub.hidden  = ! isLast;
		}
		try { window.scrollTo( { top: 0, behavior: 'smooth' } ); } catch ( e ) { window.scrollTo( 0, 0 ); }
	}

	function goNext() {
		if ( ! validateStep( current ) ) return;
		if ( current < totalSteps ) showStep( current + 1 );
	}

	function goPrev() {
		if ( current > 1 ) showStep( current - 1 );
	}

	if ( 'create' === mode ) {
		showStep( 1 );
		if ( btnNext ) btnNext.addEventListener( 'click', goNext );
		if ( btnPrev ) btnPrev.addEventListener( 'click', goPrev );

		navItems.forEach( function ( item ) {
			var btn = item.querySelector( '[data-jump-step]' );
			if ( ! btn ) return;
			btn.addEventListener( 'click', function () {
				var target = parseInt( btn.getAttribute( 'data-jump-step' ), 10 );
				if ( target <= current ) {
					showStep( target );
					return;
				}
				// only allow jumping forward if all previous steps validate
				for ( var s = current; s < target; s++ ) {
					if ( ! validateStep( s ) ) { showStep( s ); return; }
				}
				showStep( target );
			} );
		} );
	} else {
		// Edit mode: always show publish button, jump nav scrolls to step
		if ( btnPub ) btnPub.hidden = false;
		if ( btnNext ) btnNext.hidden = true;
		if ( btnPrev ) btnPrev.hidden = true;

		navItems.forEach( function ( item ) {
			var btn = item.querySelector( '[data-jump-step]' );
			if ( ! btn ) return;
			btn.addEventListener( 'click', function () {
				var target = parseInt( btn.getAttribute( 'data-jump-step' ), 10 );
				var section = root.querySelector( '.af-wizard__step[data-step="' + target + '"]' );
				if ( section ) section.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				navItems.forEach( function ( i ) { i.classList.remove( 'is-current' ); } );
				item.classList.add( 'is-current' );
			} );
		} );
	}

	/* ---------- Per-step validation ---------- */

	function showError( msg, fieldEl ) {
		window.alert( msg ); // eslint-disable-line no-alert
		if ( fieldEl && fieldEl.focus ) fieldEl.focus();
	}

	function validateStep( n ) {
		if ( n === 1 ) {
			var title = document.getElementById( 'post_title' );
			if ( ! title || '' === title.value.trim() ) {
				showError( i18n.missingTitle || 'Falta el nombre.', title );
				return false;
			}
			var typeChecked = document.querySelector( 'input[name="af_property_type"]:checked' );
			if ( ! typeChecked ) {
				showError( i18n.missingType || 'Selecciona el tipo.' );
				return false;
			}
		}
		if ( n === 2 ) {
			var address = document.getElementById( 'af_address' );
			if ( ! address || '' === address.value.trim() ) {
				showError( i18n.missingAddress || 'Falta dirección.', address );
				return false;
			}
			var city = document.getElementById( 'af_city' );
			if ( ! city || '' === city.value.trim() ) {
				showError( i18n.missingCity || 'Falta ciudad.', city );
				return false;
			}
		}
		if ( n === 5 ) {
			var rent = document.getElementById( 'af_monthly_rent' );
			var rentValue = rent ? parseFloat( rent.value ) : 0;
			if ( ! rentValue || rentValue <= 0 ) {
				showError( i18n.missingRent || 'Falta arriendo mensual.', rent );
				return false;
			}
		}
		return true;
	}

	/* ---------- Submit action ---------- */

	form.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-af-action]' );
		if ( ! btn ) return;
		var action = btn.getAttribute( 'data-af-action' );
		if ( hiddenAction ) hiddenAction.value = action;
		if ( 'publish' === action ) {
			// Validate all steps before publishing
			for ( var s = 1; s <= totalSteps; s++ ) {
				if ( ! validateStep( s ) ) {
					e.preventDefault();
					if ( 'create' === mode ) showStep( s );
					return;
				}
			}
		}
		if ( 'cancel' === action ) {
			// Skip dirty guard on explicit cancel
			dirty = false;
		}
	} );

	/* ---------- Stepper buttons ---------- */
	root.querySelectorAll( '.af-stepper' ).forEach( function ( stepper ) {
		var input = stepper.querySelector( '.af-stepper__input' );
		var minus = stepper.querySelector( '.af-stepper__btn--minus' );
		var plus  = stepper.querySelector( '.af-stepper__btn--plus' );
		if ( minus ) {
			minus.addEventListener( 'click', function () {
				var v = parseInt( input.value, 10 ) || 0;
				var min = parseInt( input.min || 0, 10 );
				if ( v > min ) input.value = v - 1;
			} );
		}
		if ( plus ) {
			plus.addEventListener( 'click', function () {
				var v = parseInt( input.value, 10 ) || 0;
				var max = parseInt( input.max || 99, 10 );
				if ( v < max ) input.value = v + 1;
			} );
		}
	} );

	/* ---------- Property type & amenity & status visual selection ---------- */
	root.querySelectorAll( '.af-prop-type-card input[type="radio"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			root.querySelectorAll( '.af-prop-type-card' ).forEach( function ( c ) { c.classList.remove( 'is-selected' ); } );
			if ( this.checked ) this.closest( '.af-prop-type-card' ).classList.add( 'is-selected' );
		} );
	} );
	root.querySelectorAll( '.af-status-option input[type="radio"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			root.querySelectorAll( '.af-status-option' ).forEach( function ( o ) { o.classList.remove( 'is-selected' ); } );
			if ( this.checked ) this.closest( '.af-status-option' ).classList.add( 'is-selected' );
		} );
	} );
	root.querySelectorAll( '.af-amenity-chip input[type="checkbox"]' ).forEach( function ( cb ) {
		cb.addEventListener( 'change', function () {
			this.closest( '.af-amenity-chip' ).classList.toggle( 'is-selected', this.checked );
		} );
	} );

	/* ---------- Featured image picker ---------- */
	var featPick   = document.getElementById( 'af-featured-pick' );
	var featRemove = document.getElementById( 'af-featured-remove' );
	var featInput  = document.getElementById( 'af_featured_image_id' );
	var featPrev   = document.getElementById( 'af-featured-preview' );

	function renderFeatured( url ) {
		if ( ! featPrev ) return;
		if ( url ) {
			featPrev.innerHTML = '<img alt="" />';
			featPrev.querySelector( 'img' ).src = url;
			featPrev.classList.add( 'has-image' );
			if ( featRemove ) featRemove.hidden = false;
			if ( featPick ) featPick.textContent = i18n.useAsFeatured || 'Cambiar foto';
		} else {
			featPrev.innerHTML = '<span class="af-featured-preview__placeholder"><span aria-hidden="true">\u{1F5BC}</span>' +
				( i18n.pickFeatured || 'Sin foto principal' ) + '</span>';
			featPrev.classList.remove( 'has-image' );
			if ( featRemove ) featRemove.hidden = true;
			if ( featPick ) featPick.textContent = i18n.pickFeatured || 'Elegir foto principal';
		}
	}

	if ( featPick ) {
		featPick.addEventListener( 'click', function () {
			if ( typeof wp === 'undefined' || ! wp.media ) return;
			var frame = wp.media( {
				title: i18n.pickFeatured || 'Seleccionar foto principal',
				button: { text: i18n.useAsFeatured || 'Usar como portada' },
				multiple: false,
				library: { type: 'image' }
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				if ( ! att ) return;
				if ( featInput ) featInput.value = att.id;
				var url = ( att.sizes && att.sizes.medium ) ? att.sizes.medium.url : att.url;
				renderFeatured( url );
				dirty = true;
			} );
			frame.open();
		} );
	}
	if ( featRemove ) {
		featRemove.addEventListener( 'click', function () {
			if ( featInput ) featInput.value = '';
			renderFeatured( '' );
			dirty = true;
		} );
	}

	/* ---------- Dirty guard ---------- */
	var dirty = false;
	form.addEventListener( 'input',  function () { dirty = true; } );
	form.addEventListener( 'change', function () { dirty = true; } );
	form.addEventListener( 'submit', function () { dirty = false; } );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( ! dirty ) return undefined;
		e.preventDefault();
		e.returnValue = i18n.unsavedChanges || '';
		return i18n.unsavedChanges || '';
	} );

}() );
