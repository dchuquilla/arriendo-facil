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
	var loading = document.getElementById( 'af-wizard-loading' );
	var loadingText = document.getElementById( 'af-wizard-loading-text' );

	var totalSteps = steps.length;
	var current    = 1;

	/* ---------- Step navigation (create mode) ---------- */

	function setPublishEnabled( on ) {
		if ( ! btnPub ) return;
		btnPub.disabled = ! on;
		btnPub.classList.toggle( 'is-disabled', ! on );
	}

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
		if ( btnNext ) {
			btnNext.hidden = current === totalSteps;
		}
		setPublishEnabled( current === totalSteps );
		if ( current === totalSteps ) {
			renderSummary();
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
				for ( var s = current; s < target; s++ ) {
					if ( ! validateStep( s ) ) { showStep( s ); return; }
				}
				showStep( target );
			} );
		} );

		// Summary "Editar" buttons jump back to a step
		root.querySelectorAll( '.af-summary__edit' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var target = parseInt( btn.getAttribute( 'data-jump-step' ), 10 );
				if ( target ) showStep( target );
			} );
		} );
	} else {
		// Edit mode: publish always enabled, jump nav scrolls to step
		setPublishEnabled( true );
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

		// In edit mode the summary is also visible (last section). Render it once.
		renderSummary();
		root.querySelectorAll( '.af-summary__edit' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var target = parseInt( btn.getAttribute( 'data-jump-step' ), 10 );
				var section = root.querySelector( '.af-wizard__step[data-step="' + target + '"]' );
				if ( section ) section.scrollIntoView( { behavior: 'smooth', block: 'start' } );
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

	/* ---------- Summary rendering ---------- */

	function getRadioLabel( name ) {
		var input = document.querySelector( 'input[name="' + name + '"]:checked' );
		if ( ! input ) return '';
		var label = input.closest( 'label' );
		if ( ! label ) return input.value;
		var labelText = label.querySelector( '.af-prop-type-card__label, .af-status-option__dot' );
		// Use full label textContent minus extra whitespace
		return ( label.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function getCheckboxLabels( name ) {
		var inputs = document.querySelectorAll( 'input[name="' + name + '"]:checked' );
		var out = [];
		inputs.forEach( function ( inp ) {
			var label = inp.closest( 'label' );
			out.push( label ? ( label.textContent || '' ).replace( /\s+/g, ' ' ).trim() : inp.value );
		} );
		return out;
	}

	function getSelectLabel( id ) {
		var sel = document.getElementById( id );
		if ( ! sel || ! sel.options ) return '';
		var opt = sel.options[ sel.selectedIndex ];
		return opt ? opt.textContent.trim() : '';
	}

	function val( id ) {
		var el = document.getElementById( id );
		return el ? ( el.value || '' ).trim() : '';
	}

	function setSummary( key, value ) {
		var el = root.querySelector( '[data-af-summary="' + key + '"]' );
		if ( ! el ) return;
		if ( ! value ) {
			el.textContent = '—';
			el.classList.add( 'is-empty' );
		} else {
			el.textContent = value;
			el.classList.remove( 'is-empty' );
		}
	}

	function renderSummary() {
		setSummary( 'post_title',        val( 'post_title' ) );
		setSummary( 'af_property_type',  getRadioLabel( 'af_property_type' ) );
		setSummary( 'af_status',         getRadioLabel( 'af_status' ) );

		setSummary( 'af_address',        val( 'af_address' ) );
		setSummary( 'af_city',           val( 'af_city' ) );
		setSummary( 'af_location_text',  val( 'af_location_text' ) );
		var lat = val( 'af_latitude' );
		var lng = val( 'af_longitude' );
		setSummary( 'coords', ( lat && lng ) ? ( lat + ', ' + lng ) : '' );

		setSummary( 'af_bedrooms',       val( 'af_bedrooms' ) );
		setSummary( 'af_bathrooms',      val( 'af_bathrooms' ) );
		var sqm = val( 'af_square_meters' );
		setSummary( 'af_square_meters',  sqm ? sqm + ' m²' : '' );
		setSummary( 'af_amenities',      getCheckboxLabels( 'af_amenities[]' ).join( ', ' ) );
		setSummary( 'post_content',      val( 'post_content' ) );

		// Photos: featured + gallery
		var photosWrap = root.querySelector( '[data-af-summary="photos"]' );
		if ( photosWrap ) {
			photosWrap.innerHTML = '';
			var featuredInput = document.getElementById( 'af_featured_image_id' );
			var featuredPreviewImg = root.querySelector( '#af-featured-preview img' );
			if ( featuredInput && featuredInput.value && featuredPreviewImg ) {
				var fImg = document.createElement( 'img' );
				fImg.src = featuredPreviewImg.src;
				fImg.alt = '';
				fImg.className = 'af-summary__photo af-summary__photo--featured';
				fImg.title = 'Portada';
				photosWrap.appendChild( fImg );
			}
			root.querySelectorAll( '#af-gallery-grid .af-gallery-item img' ).forEach( function ( img ) {
				var gImg = document.createElement( 'img' );
				gImg.src = img.src;
				gImg.alt = '';
				gImg.className = 'af-summary__photo';
				photosWrap.appendChild( gImg );
			} );
			if ( ! photosWrap.children.length ) {
				var empty = document.createElement( 'p' );
				empty.className = 'af-summary__empty';
				empty.textContent = i18n.photosRecommended || 'Sin fotos';
				photosWrap.appendChild( empty );
			}
		}

		var rent = val( 'af_monthly_rent' );
		setSummary( 'af_monthly_rent', rent ? '$' + parseFloat( rent ).toFixed( 2 ) : '' );
		var ownerSelect = document.getElementById( 'af_owner_id' );
		var ownerLabel = '';
		if ( ownerSelect && ownerSelect.tagName === 'SELECT' ) {
			ownerLabel = getSelectLabel( 'af_owner_id' );
		} else if ( ownerSelect ) {
			ownerLabel = i18n.ownerSelf || 'Tu cuenta';
		}
		setSummary( 'af_owner_id', ownerLabel );
	}

	/* ---------- Submit action & loading overlay ---------- */

	function showLoading( actionLabel ) {
		if ( ! loading ) return;
		if ( loadingText && actionLabel ) loadingText.textContent = actionLabel;
		loading.hidden = false;
	}

	form.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-af-action]' );
		if ( ! btn ) return;
		var action = btn.getAttribute( 'data-af-action' );
		if ( hiddenAction ) hiddenAction.value = action;

		if ( 'publish' === action ) {
			for ( var s = 1; s <= totalSteps; s++ ) {
				if ( ! validateStep( s ) ) {
					e.preventDefault();
					if ( 'create' === mode ) showStep( s );
					return;
				}
			}
		}
		if ( 'cancel' === action ) {
			dirty = false;
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		var action = hiddenAction ? hiddenAction.value : 'publish';
		dirty = false;
		var label;
		if ( 'publish' === action ) {
			label = ( 'edit' === mode ) ? ( i18n.savingChanges || 'Guardando cambios...' ) : ( i18n.publishing || 'Publicando inmueble...' );
		} else if ( 'draft' === action ) {
			label = i18n.savingDraft || 'Guardando borrador...';
		} else {
			label = '';
		}
		if ( label ) showLoading( label );
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

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( ! dirty ) return undefined;
		e.preventDefault();
		e.returnValue = i18n.unsavedChanges || '';
		return i18n.unsavedChanges || '';
	} );

}() );
