/* global afAdmin, mammoth, jQuery */
/*
 * DOCX template analyzer — extracted from admin.js so this ~338-line block
 * (plus the 628 KB mammoth.browser.min.js dependency) only loads on the
 * owner-contacts / contracts screens where it is actually used.
 *
 * Pre-split, every admin page had to parse this + mammoth on first paint
 * even though nothing on, say, the dashboard calls into it.
 */
( function ( $ ) {
	'use strict';

	if ( ! $ || typeof afAdmin === 'undefined' ) {
		return;
	}

	var afTemplateFieldOptions = [
		{ group: 'Datos del arrendatario', options: [
			{ key: 'guest_name', label: 'Nombre del arrendatario', source: 'chatbot' },
			{ key: 'guest_id_number', label: 'Cédula del arrendatario', source: 'chatbot' },
			{ key: 'guest_phone', label: 'Teléfono del arrendatario', source: 'chatbot' },
			{ key: 'guest_email', label: 'Email del arrendatario', source: 'chatbot' }
		] },
		{ group: 'Datos del propietario', options: [
			{ key: 'owner_name', label: 'Nombre del arrendador/propietario', source: 'owner' },
			{ key: 'owner_id_number', label: 'Cédula del arrendador', source: 'owner' }
		] },
		{ group: 'Datos del inmueble', options: [
			{ key: 'accommodation_title', label: 'Nombre del inmueble', source: 'system' },
			{ key: 'accommodation_address', label: 'Dirección del inmueble', source: 'system' },
			{ key: 'accommodation_city', label: 'Ciudad', source: 'system' },
			{ key: 'accommodation_square_meters', label: 'Metros cuadrados (m²)', source: 'system' },
			{ key: 'accommodation_bedrooms', label: 'Número de habitaciones', source: 'system' },
			{ key: 'accommodation_bathrooms', label: 'Número de baños', source: 'system' },
			{ key: 'accommodation_property_type', label: 'Tipo de inmueble', source: 'system' }
		] },
		{ group: 'Valores económicos', options: [
			{ key: 'monthly_rent', label: 'Canon mensual (valor numérico)', source: 'system' },
			{ key: 'guarantee_text', label: 'Texto de garantía', source: 'system' }
		] },
		{ group: 'Fecha del documento (auto)', options: [
			{ key: 'current_day', label: 'Día (ej: 13)', source: 'system' },
			{ key: 'current_month_name', label: 'Mes (ej: mayo)', source: 'system' },
			{ key: 'current_year', label: 'Año (ej: 2026)', source: 'system' },
			{ key: 'current_date', label: 'Fecha completa (13/05/2026)', source: 'system' }
		] },
		{ group: 'Fechas del arriendo', options: [
			{ key: 'start_date', label: 'Inicio del arriendo (YYYY-MM-DD)', source: 'system' },
			{ key: 'end_date', label: 'Fin del arriendo (YYYY-MM-DD)', source: 'system' }
		] },
		{ group: 'Sin asignar', options: [
			{ key: 'none', label: 'Dejar vacío (no llenar)', source: 'none' }
		] }
	];

	var afFieldSourceMap = {};
	afTemplateFieldOptions.forEach( function ( g ) {
		g.options.forEach( function ( o ) {
			afFieldSourceMap[ o.key ] = o.source;
		} );
	} );

	function afGetSourceForKey( key ) {
		return afFieldSourceMap[ key ] || 'none';
	}

	function afBuildFieldSelect( blankIndex, selectedKey ) {
		var select = document.createElement( 'select' );
		select.setAttribute( 'data-blank-index', blankIndex );
		select.className = 'af-field-select';

		afTemplateFieldOptions.forEach( function ( group ) {
			var optgroup = document.createElement( 'optgroup' );
			optgroup.label = group.group;

			group.options.forEach( function ( opt ) {
				var option = document.createElement( 'option' );
				option.value = opt.key;
				option.textContent = opt.label;
				if ( opt.key === selectedKey ) {
					option.selected = true;
				}
				optgroup.appendChild( option );
			} );

			select.appendChild( optgroup );
		} );

		select.addEventListener( 'change', function () {
			var tag = this.closest( '.af-field-tag' );
			if ( tag ) {
				tag.className = 'af-field-tag af-tag-' + afGetSourceForKey( this.value );
			}
		} );

		return select;
	}

	function afCollectTextNodes( root ) {
		var nodes = [];
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, null, false );
		var node;
		while ( ( node = walker.nextNode() ) ) {
			nodes.push( node );
		}
		return nodes;
	}

	function afInjectFieldTagsIntoHtml( container, fieldMap ) {
		var blankPattern = /_{3,}|\.{4,}|…{2,}|-{4,}|\t+/g;
		var blankCounter = 0;

		var fieldMapByIndex = {};
		if ( Array.isArray( fieldMap ) ) {
			fieldMap.forEach( function ( fm ) {
				fieldMapByIndex[ fm.blank_index ] = fm;
			} );
		}

		var textNodes = afCollectTextNodes( container );

		textNodes.forEach( function ( textNode ) {
			var text = textNode.nodeValue;
			if ( ! text ) {
				return;
			}

			blankPattern.lastIndex = 0;
			var match = blankPattern.exec( text );
			if ( ! match ) {
				return;
			}

			var parent = textNode.parentNode;
			if ( ! parent ) {
				return;
			}

			var frag = document.createDocumentFragment();
			var lastIndex = 0;

			blankPattern.lastIndex = 0;
			while ( ( match = blankPattern.exec( text ) ) !== null ) {
				if ( match.index > lastIndex ) {
					frag.appendChild( document.createTextNode( text.substring( lastIndex, match.index ) ) );
				}

				var fm = fieldMapByIndex[ blankCounter ] || { field_key: 'none', source: 'none' };
				var fieldKey = fm.field_key || 'none';
				var source = fm.source || afGetSourceForKey( fieldKey );

				var tag = document.createElement( 'span' );
				tag.className = 'af-field-tag af-tag-' + source;
				tag.appendChild( afBuildFieldSelect( blankCounter, fieldKey ) );
				frag.appendChild( tag );

				blankCounter++;
				lastIndex = blankPattern.lastIndex;
			}

			if ( lastIndex < text.length ) {
				frag.appendChild( document.createTextNode( text.substring( lastIndex ) ) );
			}

			parent.replaceChild( frag, textNode );
		} );
	}

	function afRenderTemplatePreview( html, fieldMap ) {
		var container = document.getElementById( 'af-template-preview-content' );
		if ( ! container ) {
			return;
		}

		container.innerHTML = html;
		afInjectFieldTagsIntoHtml( container, fieldMap );

		var modal = document.getElementById( 'af-template-preview-modal' );
		if ( modal ) {
			modal.removeAttribute( 'hidden' );
			document.body.classList.add( 'af-modal-open' );
		}
	}

	function afAutoApproveFieldMap( fieldMap ) {
		var hiddenInput = document.getElementById( 'af_template_field_map' );
		if ( hiddenInput ) {
			hiddenInput.value = JSON.stringify( fieldMap );
		}

		var approvedStatus = document.getElementById( 'af-template-approved-status' );
		if ( approvedStatus ) {
			approvedStatus.style.display = 'block';
		}
	}

	function afCloseTemplatePreview() {
		var modal = document.getElementById( 'af-template-preview-modal' );
		if ( modal ) {
			modal.setAttribute( 'hidden', '' );
			document.body.classList.remove( 'af-modal-open' );
		}
	}

	function afApproveTemplateFields() {
		var selects = document.querySelectorAll( '#af-template-preview-content .af-field-select' );
		var fieldMap = [];

		selects.forEach( function ( sel ) {
			var blankIndex = parseInt( sel.getAttribute( 'data-blank-index' ), 10 );
			var fieldKey = sel.value;
			var source = afGetSourceForKey( fieldKey );
			var label = sel.options[ sel.selectedIndex ] ? sel.options[ sel.selectedIndex ].textContent : fieldKey;

			fieldMap.push( {
				blank_index: blankIndex,
				field_key: fieldKey,
				label: label,
				source: source
			} );
		} );

		var hiddenInput = document.getElementById( 'af_template_field_map' );
		if ( hiddenInput ) {
			hiddenInput.value = JSON.stringify( fieldMap );
		}

		afCloseTemplatePreview();

		var approvedStatus = document.getElementById( 'af-template-approved-status' );
		if ( approvedStatus ) {
			approvedStatus.style.display = 'block';
		}
	}

	$( document ).on( 'change', '#af_owner_contract_example_file', function () {
		var fileInput = this;
		var file = fileInput.files && fileInput.files[0];

		if ( ! file ) {
			return;
		}

		var analyzeStatus = document.getElementById( 'af-template-analyze-status' );
		var approvedStatus = document.getElementById( 'af-template-approved-status' );
		var hiddenInput = document.getElementById( 'af_template_field_map' );

		if ( approvedStatus ) {
			approvedStatus.style.display = 'none';
		}
		if ( hiddenInput ) {
			hiddenInput.value = '';
		}
		if ( analyzeStatus ) {
			analyzeStatus.style.display = 'block';
		}

		// Run mammoth (DOCX→HTML) and AI analysis in parallel.
		var mammothPromise = new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onload = function ( e ) {
				if ( typeof mammoth === 'undefined' ) {
					reject( new Error( 'mammoth.js not loaded' ) );
					return;
				}
				mammoth.convertToHtml( { arrayBuffer: e.target.result } )
					.then( function ( result ) {
						resolve( result.value );
					} )
					.catch( reject );
			};
			reader.onerror = function () {
				reject( new Error( 'FileReader error' ) );
			};
			reader.readAsArrayBuffer( file );
		} );

		var aiPromise = new Promise( function ( resolve, reject ) {
			var formData = new FormData();
			formData.append( 'action', 'af_analyze_owner_template' );
			formData.append( 'nonce', afAdmin.ownerContactNonce || '' );
			formData.append( 'template_file', file );

			$.ajax( {
				url: afAdmin.ajaxUrl,
				method: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				dataType: 'json'
			} )
				.done( function ( response ) {
					if ( response && response.success && response.data && response.data.field_map ) {
						resolve( response.data.field_map );
					} else {
						var msg = ( response && response.data && response.data.message )
							? response.data.message
							: 'Error al analizar la plantilla.';
						reject( new Error( msg ) );
					}
				} )
				.fail( function () {
					reject( new Error( 'Error de conexión al analizar la plantilla.' ) );
				} );
		} );

		Promise.all( [ mammothPromise, aiPromise ] )
			.then( function ( results ) {
				var html = results[0];
				var fieldMap = results[1];

				if ( analyzeStatus ) {
					analyzeStatus.style.display = 'none';
				}

				// Auto-approve the AI field map (no manual review required).
				afAutoApproveFieldMap( fieldMap );

				// Store html and fieldMap for optional manual review.
				window._afTemplatePreviewHtml = html;
				window._afTemplatePreviewFieldMap = fieldMap;
			} )
			.catch( function ( err ) {
				if ( analyzeStatus ) {
					analyzeStatus.style.display = 'none';
				}
				alert( err.message || 'Error al procesar la plantilla.' );
			} );
	} );

	$( document ).on( 'click', '[data-af-close-template-preview]', function () {
		afCloseTemplatePreview();
	} );

	$( document ).on( 'click', '#af-template-review-link', function ( e ) {
		e.preventDefault();
		if ( window._afTemplatePreviewHtml && window._afTemplatePreviewFieldMap ) {
			afRenderTemplatePreview( window._afTemplatePreviewHtml, window._afTemplatePreviewFieldMap );
		}
	} );

	$( document ).on( 'click', '#af-template-approve', function () {
		afApproveTemplateFields();
	} );
} )( window.jQuery );
