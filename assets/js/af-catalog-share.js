/**
 * Catalog admin screen: share link management, building groups and the
 * per-property "in catalog" / "building" controls.
 */
( function () {
	'use strict';

	var config = window.afCatalogShare;
	if ( ! config || ! config.ajaxUrl ) {
		return;
	}

	var i18n = config.i18n || {};

	function t( key, fallback ) {
		return typeof i18n[ key ] === 'string' && i18n[ key ] ? i18n[ key ] : fallback;
	}

	function post( action, payload ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );

		Object.keys( payload || {} ).forEach( function ( key ) {
			body.append( key, payload[ key ] );
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function errorOf( json, fallback ) {
		return ( json && json.data && json.data.message ) || fallback || 'Error';
	}

	/* ================================================================== */
	/* Share link                                                          */
	/* ================================================================== */

	var intro = document.querySelector( '[data-af-move-under-title]' );
	var headerEnd = document.querySelector( '.wp-header-end' );

	if ( intro && headerEnd ) {
		headerEnd.parentNode.insertBefore( intro, headerEnd.nextSibling );
	}

	var card = document.getElementById( 'af-share-card' );

	if ( card ) {
		initShareLink( card );
	}

	function initShareLink( card ) {
		var urlInput = document.getElementById( 'af-share-url' );
		var actions = document.getElementById( 'af-share-actions' );
		var generateWrap = document.getElementById( 'af-share-generate-wrap' );
		var emptyState = document.getElementById( 'af-share-empty' );
		var hint = document.getElementById( 'af-share-hint' );
		var primary = document.getElementById( 'af-share-primary' );
		var stateTag = document.getElementById( 'af-share-state' );
		var status = document.getElementById( 'af-share-status' );
		var generateBtn = document.getElementById( 'af-share-generate' );

		var timer = null;

		function setMessage( text, isError ) {
			if ( ! status ) {
				return;
			}
			status.textContent = text || '';
			status.className = 'af-share-status' + ( isError ? ' is-error' : ' is-success' );
		}

		function flash( text, isError ) {
			setMessage( text, isError );
			if ( timer ) {
				clearTimeout( timer );
			}
			timer = setTimeout( function () {
				setMessage( '', false );
			}, 4000 );
		}

		function setActiveUi( on ) {
			card.classList.toggle( 'is-active', on );
			if ( primary ) {
				primary.hidden = ! on;
			}
			if ( stateTag ) {
				stateTag.textContent = stateTag.getAttribute( on ? 'data-on' : 'data-off' );
			}
		}

		function showUrl( url ) {
			if ( ! url || ! urlInput ) {
				return;
			}
			urlInput.value = url;
			setActiveUi( true );
			card.classList.remove( 'is-hidden' );
			if ( actions ) {
				actions.hidden = false;
			}
			if ( generateWrap ) {
				generateWrap.hidden = true;
			}
			if ( emptyState ) {
				emptyState.hidden = true;
			}
			if ( hint ) {
				hint.hidden = false;
			}

			// Keep the download link in sync with the (possibly rotated) URL.
			var pdfLink = document.getElementById( 'af-share-pdf' );
			if ( pdfLink ) {
				pdfLink.href = url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'pdf=1';
			}
		}

		function showEmpty() {
			setActiveUi( false );
			card.classList.remove( 'is-hidden' );
			if ( urlInput ) {
				urlInput.value = '';
			}
			if ( actions ) {
				actions.hidden = true;
			}
			if ( generateWrap ) {
				generateWrap.hidden = false;
			}
			if ( emptyState ) {
				emptyState.hidden = false;
			}
			if ( hint ) {
				hint.hidden = true;
			}
		}

		if ( generateBtn ) {
			if ( urlInput && ! urlInput.value.trim() ) {
				showEmpty();
			}

			generateBtn.addEventListener( 'click', function () {
				generateBtn.disabled = true;
				post( 'af_catalog_share_generate', { slug: slugInput ? slugInput.value : '' } ).then( function ( json ) {
					generateBtn.disabled = false;
					if ( ! json || ! json.success ) {
						showSlugError( json );
						return;
					}
					applySaved( json.data );
					showUrl( json.data.url );
					flash( json.data.message, false );
				} );
			} );
		}

		/* --------------------------- Short name (slug) --------------------------- */

		var slugInput = document.getElementById( 'af-share-slug' );
		var slugMsg = document.getElementById( 'af-slug-msg' );
		var slugSuggest = document.getElementById( 'af-slug-suggest' );
		var slugSave = document.getElementById( 'af-share-slug-save' );
		var checkTimer = null;
		var checkSeq = 0;

		function isActive() {
			return card.getAttribute( 'data-active' ) === '1';
		}

		function savedSlug() {
			return card.getAttribute( 'data-saved-slug' ) || '';
		}

		function setSlugMsg( text, state ) {
			if ( ! slugMsg ) {
				return;
			}
			slugMsg.textContent = text || '';
			slugMsg.className = 'af-slug__msg' + ( state ? ' is-' + state : '' );
			card.classList.toggle( 'has-slug-error', 'error' === state );
		}

		function renderSuggestions( list ) {
			if ( ! slugSuggest ) {
				return;
			}
			slugSuggest.innerHTML = '';
			if ( ! list || ! list.length ) {
				slugSuggest.hidden = true;
				return;
			}
			var label = document.createElement( 'span' );
			label.textContent = t( 'tryThese', 'Prueba con:' );
			slugSuggest.appendChild( label );
			list.forEach( function ( value ) {
				var chip = document.createElement( 'button' );
				chip.type = 'button';
				chip.className = 'af-slug__chip';
				chip.textContent = value;
				chip.addEventListener( 'click', function () {
					slugInput.value = value;
					slugInput.focus();
					onSlugInput();
				} );
				slugSuggest.appendChild( chip );
			} );
			slugSuggest.hidden = false;
		}

		function showSlugError( json ) {
			setSlugMsg( errorOf( json ), 'error' );
			renderSuggestions( json && json.data ? json.data.suggestions : [] );
		}

		function applySaved( data ) {
			card.setAttribute( 'data-active', '1' );
			card.setAttribute( 'data-saved-slug', data.slug || '' );
			if ( slugInput && data.slug ) {
				slugInput.value = data.slug;
			}
			if ( slugSave ) {
				slugSave.hidden = true;
			}
			var copy = document.getElementById( 'af-share-copy' );
			if ( copy ) {
				copy.hidden = false;
			}
			setSlugMsg( '', '' );
			renderSuggestions( [] );
		}

		function onSlugInput() {
			var value = slugInput.value.trim().toLowerCase();
			var changed = value !== savedSlug();

			if ( slugSave ) {
				slugSave.hidden = ! ( isActive() && changed );
				var copy = document.getElementById( 'af-share-copy' );
				if ( copy ) {
					copy.hidden = ! slugSave.hidden;
				}
			}
			renderSuggestions( [] );

			if ( checkTimer ) {
				clearTimeout( checkTimer );
			}
			if ( ! changed ) {
				setSlugMsg( '', '' );
				return;
			}

			setSlugMsg( t( 'checking', 'Comprobando…' ), '' );
			checkTimer = setTimeout( function () {
				var seq = ++checkSeq;
				post( 'af_catalog_share_slug', { slug: value, check: '1' } ).then( function ( json ) {
					if ( seq !== checkSeq ) {
						return;
					}
					if ( ! json || ! json.success ) {
						showSlugError( json );
						return;
					}
					if ( json.data.slug && json.data.slug !== value ) {
						slugInput.value = json.data.slug;
					}
					setSlugMsg( '✓ ' + json.data.message, 'ok' );
				} );
			}, 400 );
		}

		if ( slugInput ) {
			slugInput.addEventListener( 'input', onSlugInput );
			slugInput.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' !== e.key ) {
					return;
				}
				e.preventDefault();
				if ( isActive() && slugSave && ! slugSave.hidden ) {
					slugSave.click();
				} else if ( ! isActive() && generateBtn ) {
					generateBtn.click();
				}
			} );
		}

		if ( slugSave ) {
			slugSave.addEventListener( 'click', function () {
				if ( ! window.confirm( t( 'slugChange', 'El enlace anterior dejará de funcionar. ¿Guardar el nuevo nombre?' ) ) ) {
					return;
				}
				slugSave.disabled = true;
				post( 'af_catalog_share_slug', { slug: slugInput.value } ).then( function ( json ) {
					slugSave.disabled = false;
					if ( ! json || ! json.success ) {
						showSlugError( json );
						return;
					}
					applySaved( json.data );
					showUrl( json.data.url );
					flash( json.data.message, false );
				} );
			} );
		}

		var copyBtn = document.getElementById( 'af-share-copy' );
		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', function () {
				if ( ! urlInput || ! urlInput.value ) {
					return;
				}
				var value = urlInput.value;
				var label = copyBtn.querySelector( '[data-label]' );
				var original = label ? label.textContent : '';

				function done() {
					copyBtn.classList.add( 'is-copied' );
					if ( label ) {
						label.textContent = t( 'copiedShort', '¡Copiado!' );
					}
					setTimeout( function () {
						copyBtn.classList.remove( 'is-copied' );
						if ( label ) {
							label.textContent = original;
						}
					}, 2000 );
				}

				function fallback() {
					var tmp = document.createElement( 'textarea' );
					tmp.value = value;
					tmp.setAttribute( 'readonly', '' );
					tmp.style.position = 'fixed';
					tmp.style.opacity = '0';
					document.body.appendChild( tmp );
					tmp.select();
					try {
						document.execCommand( 'copy' );
						done();
					} catch ( e ) {
						flash( value, false );
					}
					document.body.removeChild( tmp );
				}

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( value ).then( done, fallback );
				} else {
					fallback();
				}
			} );
		}

		var revokeBtn = document.getElementById( 'af-share-revoke' );
		if ( revokeBtn ) {
			revokeBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( '¿Desactivar el enlace compartido? Dejará de ser accesible.' ) ) {
					return;
				}
				revokeBtn.disabled = true;
				post( 'af_catalog_share_revoke', {} ).then( function ( json ) {
					revokeBtn.disabled = false;
					if ( ! json || ! json.success ) {
						flash( errorOf( json ), true );
						return;
					}
					showEmpty();
					card.setAttribute( 'data-active', '0' );
					card.setAttribute( 'data-saved-slug', '' );
					flash( json.data.message, false );
				} );
			} );
		}

		var previewBtn = document.getElementById( 'af-share-preview' );
		if ( previewBtn ) {
			previewBtn.addEventListener( 'click', function () {
				if ( urlInput && urlInput.value ) {
					window.open( urlInput.value, '_blank', 'noopener' );
				}
			} );
		}
	}

	/* ================================================================== */
	/* Building groups                                                     */
	/* ================================================================== */

	var groupList = document.getElementById( 'af-cs-group-list' );

	if ( groupList || document.querySelector( '[data-af-cs-include], [data-af-cs-assign]' ) ) {
		initGroups( groupList );
	}

	function initGroups( list ) {
		var ownerId = list ? ( list.getAttribute( 'data-owner-id' ) || '0' ) : '0';
		var status = document.getElementById( 'af-cs-group-status' ) || document.getElementById( 'af-share-status' );
		var newBtn = document.getElementById( 'af-cs-group-new' );
		var timer = null;

		function setMessage( text, isError ) {
			if ( ! status ) {
				return;
			}
			status.textContent = text || '';
			status.className = 'af-share-status' + ( isError ? ' is-error' : ' is-success' );
		}

		function flash( text, isError ) {
			setMessage( text, isError );
			if ( timer ) {
				clearTimeout( timer );
			}
			timer = setTimeout( function () {
				setMessage( '', false );
			}, 4000 );
		}

		/**
		 * Repaints the group list and refreshes every property dropdown so a
		 * new or renamed building is immediately selectable.
		 */
		function repaint( groups ) {
			var empty = list.querySelector( '.af-cs-group-list__empty' );

			if ( ! groups || ! groups.length ) {
				if ( ! empty ) {
					var li = document.createElement( 'li' );
					li.className = 'af-cs-group-list__empty';
					li.id = 'af-cs-group-empty';
					li.textContent = t( 'noGroups', 'Todavía no has creado ningún edificio o conjunto.' );
					list.innerHTML = '';
					list.appendChild( li );
				}
				syncSelects( [] );
				return;
			}

			list.innerHTML = '';

			groups.forEach( function ( group ) {
				var li = document.createElement( 'li' );
				li.className = 'af-cs-group-row';
				li.setAttribute( 'data-group-id', group.id );

				var name = document.createElement( 'span' );
				name.className = 'af-cs-group-row__name';
				name.textContent = group.name;

				var count = document.createElement( 'span' );
				count.className = 'af-cs-group-row__count';
				count.setAttribute( 'data-group-count', '' );
				count.textContent = group.count + ' ' + ( group.count === 1
					? t( 'unitLabel', 'inmueble' )
					: t( 'unitLabelPlural', 'inmuebles' ) );

				var actions = document.createElement( 'span' );
				actions.className = 'af-cs-group-row__actions';

				actions.appendChild( actionButton(
					'af-cs-group-rename',
					t( 'rename', 'Renombrar' ),
					function () {
						var next = window.prompt( t( 'renamePrompt', 'Escribe el nuevo nombre.' ), group.name );

						if ( null === next ) {
							return;
						}

						save( { group_id: group.id, name: next } );
					}
				) );

				actions.appendChild( actionButton(
					'af-cs-group-delete',
					t( 'remove', 'Eliminar' ),
					function () {
						if ( ! window.confirm( t( 'deleteConfirm', '¿Eliminar este grupo? Sus propiedades quedarán como inmuebles independientes.' ) ) ) {
							return;
						}
						post( 'af_catalog_group_delete', { group_id: group.id } ).then( function ( json ) {
							if ( ! json || ! json.success ) {
								flash( errorOf( json ), true );
								return;
							}
							flash( json.data.message, false );
							refresh();
						} );
					}
				) );

				li.appendChild( name );
				li.appendChild( count );
				li.appendChild( actions );
				list.appendChild( li );
			} );

			syncSelects( groups );
		}

		function actionButton( className, text, onClick ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button-link ' + className;
			btn.textContent = text;
			btn.addEventListener( 'click', onClick );
			return btn;
		}

		/**
		 * Rewrites the <option> list of every property dropdown, preserving
		 * whatever each property currently has selected.
		 */
		function syncSelects( groups ) {
			document.querySelectorAll( '[data-af-cs-assign]' ).forEach( function ( select ) {
				var current = select.value;
				var noneText = select.getAttribute( 'data-none-label' ) || t( 'noGroup', '— Sin grupo —' );

				select.innerHTML = '';

				var none = document.createElement( 'option' );
				none.value = '0';
				none.textContent = noneText;
				select.appendChild( none );

				groups.forEach( function ( group ) {
					var opt = document.createElement( 'option' );
					opt.value = String( group.id );
					opt.textContent = group.name;
					select.appendChild( opt );
				} );

				// A group the property belonged to may have just been deleted.
				var stillExists = groups.some( function ( group ) {
					return String( group.id ) === String( current );
				} );

				select.value = stillExists ? current : '0';
			} );
		}

		function save( payload ) {
			payload.owner_id = ownerId;

			return post( 'af_catalog_group_save', payload ).then( function ( json ) {
				if ( ! json || ! json.success ) {
					flash( errorOf( json ), true );
					return;
				}
				flash( json.data.message, false );
				refresh();
			} );
		}

		function refresh() {
			if ( ! list ) {
				return;
			}
			post( 'af_catalog_share_stats', { owner_id: ownerId } ).then( function ( json ) {
				if ( ! json || ! json.success ) {
					return;
				}
				repaint( json.data.groups || [] );
			} );
		}

		if ( newBtn ) {
			newBtn.addEventListener( 'click', function () {
				var name = window.prompt( t( 'groupPrompt', 'Escribe el nombre del edificio o conjunto.' ) );

				if ( ! name ) {
					return;
				}

				save( { name: name } );
			} );
		}

		// Seed the dropdowns with the "no group" caption so syncSelects()
		// can restore it after repainting.
		document.querySelectorAll( '[data-af-cs-assign]' ).forEach( function ( select ) {
			var first = select.querySelector( 'option' );

			if ( first ) {
				select.setAttribute( 'data-none-label', first.textContent.trim() );
			}
		} );

		// Per-property building assignment.
		document.querySelectorAll( '[data-af-cs-assign]' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				var propertyId = select.getAttribute( 'data-property-id' );

				select.disabled = true;

				post( 'af_catalog_assign_group', {
					property_id: propertyId,
					group_id: select.value
				} ).then( function ( json ) {
					select.disabled = false;

					if ( ! json || ! json.success ) {
						flash( errorOf( json ), true );
						refresh();
						return;
					}
					flash( json.data.message, false );
					refresh();
				} );
			} );
		} );

		// Per-property catalog visibility.
		document.querySelectorAll( '[data-af-cs-include]' ).forEach( function ( box ) {
			box.addEventListener( 'change', function () {
				var propertyId = box.getAttribute( 'data-property-id' );

				box.disabled = true;

				post( 'af_catalog_toggle_include', {
					property_id: propertyId,
					included: box.checked ? '1' : '0'
				} ).then( function ( json ) {
					box.disabled = false;

					if ( ! json || ! json.success ) {
						flash( errorOf( json ), true );
						box.checked = ! box.checked;
						return;
					}
					flash( json.data.message, false );
				} );
			} );
		} );

		// Server-rendered chips have no handlers yet; repaint wires them.
		refresh();
	}
}() );
