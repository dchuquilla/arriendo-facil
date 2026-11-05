/**
 * Property listing: building groups (create / rename / delete / assign).
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

	var intro = document.querySelector( '[data-af-move-under-title]' );
	var headerEnd = document.querySelector( '.wp-header-end' );

	if ( intro && headerEnd ) {
		headerEnd.parentNode.insertBefore( intro, headerEnd.nextSibling );
	}

	/* ================================================================== */
	/* Building groups                                                     */
	/* ================================================================== */

	var groupList = document.getElementById( 'af-cs-group-list' );

	if ( groupList || document.querySelector( '[data-af-cs-assign]' ) ) {
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

		// Server-rendered chips have no handlers yet; repaint wires them.
		refresh();
	}
}() );
