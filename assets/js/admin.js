/* global afAdmin */
( function ( $ ) {
	'use strict';

	// ── Predict accommodation rental cost (AI) ──────────────────────────────
	$( document ).on( 'click', '.af-predict-cost', function () {
		var $btn = $( this );
		var accommodationId = $btn.data( 'id' );
		var $result = $btn.siblings( '.af-predict-result' );

		$btn.prop( 'disabled', true ).text( afAdmin.i18n ? afAdmin.i18n.loading : 'Loading…' );

		$.post( afAdmin.ajaxUrl, {
			action: 'af_predict_cost',
			nonce: afAdmin.leaseNonce,
			accommodation_id: accommodationId,
		} )
			.done( function ( response ) {
				if ( response.success && response.data && response.data.predicted_cost ) {
					$result.text( '$' + parseFloat( response.data.predicted_cost ).toFixed( 2 ) );
					$( '#af_monthly_rent' ).val( parseFloat( response.data.predicted_cost ).toFixed( 2 ) );
				} else {
					$result.text( response.data && response.data.message ? response.data.message : 'Error' );
				}
			} )
			.fail( function () {
				$result.text( 'Request failed.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).text( 'Predict Cost (AI)' );
			} );
	} );

	// ── Generate lease document (AI) ────────────────────────────────────────
	$( document ).on( 'click', '.af-generate-document', function () {
		var $btn = $( this );
		var leaseId = $btn.data( 'lease-id' );

		if ( ! confirm( 'Generate AI lease document for lease #' + leaseId + '?' ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		$.post( afAdmin.ajaxUrl, {
			action: 'af_generate_document',
			nonce: afAdmin.leaseNonce,
			lease_id: leaseId,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					alert( 'Document generated. Reloading…' );
					window.location.reload();
				} else {
					alert( response.data && response.data.message ? response.data.message : 'Error generating document.' );
				}
			} )
			.fail( function () {
				alert( 'Request failed.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	// ── Upload manual lease version (.doc/.docx) via modal ─────────────────
	( function setupLeaseUploadModal() {
		var modalEl = document.getElementById( 'af-lease-upload-modal' );
		var titleEl = document.getElementById( 'af-lease-upload-modal-title' );
		var fileInputEl = document.getElementById( 'af-lease-upload-file' );
		var fileNameEl = document.getElementById( 'af-lease-upload-file-name' );
		var feedbackEl = document.getElementById( 'af-lease-upload-feedback' );
		var selectBtnEl = document.getElementById( 'af-lease-upload-select-btn' );
		var submitBtnEl = document.getElementById( 'af-lease-upload-submit' );

		var state = {
			leaseId: 0,
			nextVersion: 0,
			file: null,
		};

		function resetModalState() {
			state.leaseId = 0;
			state.nextVersion = 0;
			state.file = null;

			if ( fileInputEl ) {
				fileInputEl.value = '';
			}

			if ( titleEl ) {
				titleEl.textContent = 'Upload New Contract Version';
			}

			if ( fileNameEl ) {
				fileNameEl.textContent = 'No file selected.';
			}

			if ( feedbackEl ) {
				feedbackEl.textContent = '';
				feedbackEl.className = 'af-lease-upload-feedback';
			}

			if ( submitBtnEl ) {
				submitBtnEl.disabled = true;
				submitBtnEl.textContent = 'Upload Version';
			}
		}

		function closeModal() {
			if ( ! modalEl ) {
				return;
			}

			modalEl.setAttribute( 'hidden', 'hidden' );
			document.body.classList.remove( 'af-modal-open' );
			resetModalState();
		}

		function openModal( leaseId, nextVersion ) {
			if ( ! modalEl ) {
				return;
			}

			state.leaseId = leaseId;
			state.nextVersion = nextVersion;

			if ( titleEl ) {
				titleEl.textContent = 'Upload contract version v' + String( nextVersion );
			}

			modalEl.removeAttribute( 'hidden' );
			document.body.classList.add( 'af-modal-open' );
		}

		if ( ! modalEl || !fileInputEl || !fileNameEl || !feedbackEl || !selectBtnEl || !submitBtnEl ) {
			return;
		}

		$( document ).on( 'click', '.af-open-upload-version-modal', function () {
			var leaseId = parseInt( String( this.getAttribute( 'data-lease-id' ) || '0' ), 10 );
			var nextVersion = parseInt( String( this.getAttribute( 'data-next-version' ) || '1' ), 10 );
			if ( ! leaseId ) {
				return;
			}

			openModal( leaseId, nextVersion > 0 ? nextVersion : 1 );
		} );

		$( document ).on( 'click', '[data-af-close-upload-modal]', function () {
			closeModal();
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && modalEl && !modalEl.hasAttribute( 'hidden' ) ) {
				closeModal();
			}
		} );

		selectBtnEl.addEventListener( 'click', function () {
			fileInputEl.click();
		} );

		fileInputEl.addEventListener( 'change', function () {
			var file = fileInputEl.files && fileInputEl.files[0] ? fileInputEl.files[0] : null;
			state.file = file;

			if ( file ) {
				fileNameEl.textContent = file.name;
				submitBtnEl.disabled = false;
				feedbackEl.textContent = '';
				feedbackEl.className = 'af-lease-upload-feedback';
				return;
			}

			fileNameEl.textContent = 'No file selected.';
			submitBtnEl.disabled = true;
		} );

		submitBtnEl.addEventListener( 'click', function () {
			if ( ! state.leaseId || ! state.file ) {
				feedbackEl.textContent = 'Select a Word file first (.doc or .docx).';
				feedbackEl.className = 'af-lease-upload-feedback af-lease-upload-feedback-error';
				return;
			}

			submitBtnEl.disabled = true;
			submitBtnEl.textContent = 'Uploading...';
			feedbackEl.textContent = '';
			feedbackEl.className = 'af-lease-upload-feedback';

			var data = new FormData();
			data.append( 'action', 'af_upload_lease_contract_version' );
			data.append( 'nonce', afAdmin.leaseNonce );
			data.append( 'lease_id', String( state.leaseId ) );
			data.append( 'lease_contract_file', state.file );

			fetch( afAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						feedbackEl.textContent = ( payload.data && payload.data.message ) ? payload.data.message : 'Version uploaded successfully.';
						feedbackEl.className = 'af-lease-upload-feedback af-lease-upload-feedback-success';
						window.setTimeout( function () {
							window.location.reload();
						}, 500 );
						return;
					}

					feedbackEl.textContent = payload && payload.data && payload.data.message ? payload.data.message : 'Could not upload the new version.';
					feedbackEl.className = 'af-lease-upload-feedback af-lease-upload-feedback-error';
					submitBtnEl.disabled = false;
					submitBtnEl.textContent = 'Upload Version';
				} )
				.catch( function () {
					feedbackEl.textContent = 'Request failed.';
					feedbackEl.className = 'af-lease-upload-feedback af-lease-upload-feedback-error';
					submitBtnEl.disabled = false;
					submitBtnEl.textContent = 'Upload Version';
				} );
		} );
	} )();

	// ── Change lease status ─────────────────────────────────────────────────
	$( document ).on( 'click', '.af-approve-lease-document', function () {
		var $btn = $( this );
		if ( $btn.is( ':disabled' ) ) {
			return;
		}

		var leaseId = parseInt( String( $btn.data( 'lease-id' ) || 0 ), 10 );
		var activeVersion = parseInt( String( $btn.data( 'active-version' ) || 1 ), 10 );

		if ( ! leaseId ) {
			alert( 'Invalid lease.' );
			return;
		}

		if ( ! window.confirm( 'Approve active version v' + activeVersion + ' and convert to protected PDF with fixed security password?' ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( 'Approving...' );

		$.post( afAdmin.ajaxUrl, {
			action: 'af_approve_lease_contract',
			nonce: afAdmin.leaseNonce,
			lease_id: leaseId,
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					alert( response.data && response.data.message ? response.data.message : 'Document approved successfully.' );
					window.location.reload();
				} else {
					alert( response && response.data && response.data.message ? response.data.message : 'Could not approve document.' );
				}
			} )
			.fail( function () {
				alert( 'Request failed.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).text( 'Approve Document' );
			} );
	} );

	$( document ).on( 'click', '.af-change-lease-status', function () {
		var $btn = $( this );
		var leaseId = $btn.data( 'lease-id' );
		var status = $btn.data( 'status' );

		$btn.prop( 'disabled', true );

		$.post( afAdmin.ajaxUrl, {
			action: 'af_update_lease',
			nonce: afAdmin.leaseNonce,
			lease_id: leaseId,
			status: status,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					window.location.reload();
				} else {
					alert( response.data && response.data.message ? response.data.message : 'Error updating lease.' );
				}
			} )
			.fail( function () {
				alert( 'Request failed.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	// ── Update cleaning request status ──────────────────────────────────────
	$( document ).on( 'click', '.af-update-cleaning', function () {
		var $btn = $( this );
		var requestId = $btn.data( 'request-id' );
		var status = $btn.data( 'status' );

		$btn.prop( 'disabled', true );

		$.post( afAdmin.ajaxUrl, {
			action: 'af_update_cleaning_request',
			nonce: afAdmin.cleaningNonce,
			request_id: requestId,
			status: status,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					window.location.reload();
				} else {
					alert( response.data && response.data.message ? response.data.message : 'Error.' );
				}
			} )
			.fail( function () {
				alert( 'Request failed.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	// ── Create cleaning request with owner-based filtering ──────────────────
	$( document ).on( 'click', '#af-new-cleaning-request', function () {
		$( '#af-cleaning-request-form-card' ).show();
		$( '#af_cleaning_owner_select' ).trigger( 'focus' );
	} );

	$( document ).on( 'click', '#af-cancel-cleaning-request', function () {
		var formEl = document.getElementById( 'af-cleaning-request-form' );
		if ( formEl ) {
			formEl.reset();
		}

		resetCleaningRequestFilters();
		$( '#af-cleaning-request-form-card' ).hide();
	} );

	$( document ).on( 'input', '#af_cleaning_owner_search', function () {
		filterOwnersAndCleaningServices();
	} );

	$( document ).on( 'change', '#af_cleaning_owner_select', function () {
		filterOwnerAccommodations();
	} );

	$( document ).on( 'submit', '#af-cleaning-request-form', function ( event ) {
		event.preventDefault();

		var formEl = this;
		var $form = $( formEl );
		var $submit = $form.find( '#af-cleaning-request-submit' );
		var ownerText = $( '#af_cleaning_owner_select option:selected' ).text() || '';
		var serviceText = $( '#af_cleaning_service_select option:selected' ).text() || '';
		var baseNotes = $( '#af_cleaning_notes' ).val() || '';
		var computedNotes = baseNotes;

		if ( ! formEl.checkValidity() ) {
			formEl.reportValidity();
			return;
		}

		if ( ownerText ) {
			computedNotes += ( computedNotes ? '\n' : '' ) + 'Owner: ' + ownerText;
		}

		if ( serviceText ) {
			computedNotes += ( computedNotes ? '\n' : '' ) + 'Cleaning service: ' + serviceText;
		}

		$submit.prop( 'disabled', true );

		$.post( afAdmin.ajaxUrl, {
			action: 'af_create_cleaning_request',
			nonce: afAdmin.cleaningNonce,
			accommodation_id: $( '#af_cleaning_accommodation_id' ).val(),
			requested_date: $( '#af_cleaning_requested_date' ).val(),
			notes: computedNotes
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					var requestId = response.data && response.data.id ? parseInt( response.data.id, 10 ) : 0;

					if ( requestId > 0 ) {
						var contractUrl = afAdmin.ajaxUrl
							+ '?action=af_generate_cleaning_contract_word'
							+ '&nonce=' + encodeURIComponent( afAdmin.cleaningNonce || '' )
							+ '&request_id=' + encodeURIComponent( String( requestId ) );

						var win = window.open( contractUrl, '_blank' );

						if ( ! win ) {
							window.location.href = contractUrl;
							return;
						}
					}

					window.setTimeout( function () {
						window.location.reload();
					}, 350 );
					return;
				}

				alert( response && response.data && response.data.message ? response.data.message : 'Could not create cleaning request.' );
			} )
			.fail( function () {
				alert( 'Request failed.' );
			} )
			.always( function () {
				$submit.prop( 'disabled', false );
			} );
	} );

	function filterOwnersAndCleaningServices() {
		var term = String( $( '#af_cleaning_owner_search' ).val() || '' ).toLowerCase().trim();
		var ownerSelect = document.getElementById( 'af_cleaning_owner_select' );
		var serviceSelect = document.getElementById( 'af_cleaning_service_select' );
		var i;

		if ( ownerSelect ) {
			for ( i = 0; i < ownerSelect.options.length; i++ ) {
				var ownerOption = ownerSelect.options[ i ];
				if ( ! ownerOption.value ) {
					ownerOption.hidden = false;
					continue;
				}

				var ownerName = String( ownerOption.getAttribute( 'data-owner-name' ) || '' );
				var ownerRuc = String( ownerOption.getAttribute( 'data-owner-ruc' ) || '' );
				var ownerVisible = ! term || ownerName.indexOf( term ) !== -1 || ownerRuc.indexOf( term ) !== -1;
				ownerOption.hidden = ! ownerVisible;
			}

			if ( ownerSelect.selectedIndex >= 0 && ownerSelect.options[ ownerSelect.selectedIndex ] && ownerSelect.options[ ownerSelect.selectedIndex ].hidden ) {
				ownerSelect.value = '';
			}
		}

		if ( serviceSelect ) {
			for ( i = 0; i < serviceSelect.options.length; i++ ) {
				var serviceOption = serviceSelect.options[ i ];
				if ( ! serviceOption.value ) {
					serviceOption.hidden = false;
					continue;
				}

				var serviceName = String( serviceOption.getAttribute( 'data-company-name' ) || '' );
				var serviceRuc = String( serviceOption.getAttribute( 'data-company-ruc' ) || '' );
				var serviceVisible = ! term || serviceName.indexOf( term ) !== -1 || serviceRuc.indexOf( term ) !== -1;
				serviceOption.hidden = ! serviceVisible;
			}

			if ( serviceSelect.selectedIndex >= 0 && serviceSelect.options[ serviceSelect.selectedIndex ] && serviceSelect.options[ serviceSelect.selectedIndex ].hidden ) {
				serviceSelect.value = '';
			}
		}

		filterOwnerAccommodations();
	}

	function filterOwnerAccommodations() {
		var accommodationSelect = document.getElementById( 'af_cleaning_accommodation_id' );
		var i;

		if ( ! accommodationSelect ) {
			return;
		}

		for ( i = 0; i < accommodationSelect.options.length; i++ ) {
			var accommodationOption = accommodationSelect.options[ i ];
			accommodationOption.hidden = false;
		}
	}

	function resetCleaningRequestFilters() {
		var ownerSearch = document.getElementById( 'af_cleaning_owner_search' );
		var ownerSelect = document.getElementById( 'af_cleaning_owner_select' );
		var accommodationSelect = document.getElementById( 'af_cleaning_accommodation_id' );
		var serviceSelect = document.getElementById( 'af_cleaning_service_select' );
		var i;

		if ( ownerSearch ) {
			ownerSearch.value = '';
		}

		if ( ownerSelect ) {
			ownerSelect.value = '';
			for ( i = 0; i < ownerSelect.options.length; i++ ) {
				ownerSelect.options[ i ].hidden = false;
			}
		}

		if ( accommodationSelect ) {
			accommodationSelect.value = '';
			for ( i = 0; i < accommodationSelect.options.length; i++ ) {
				accommodationSelect.options[ i ].hidden = false;
			}
		}

		if ( serviceSelect ) {
			serviceSelect.value = '';
			for ( i = 0; i < serviceSelect.options.length; i++ ) {
				serviceSelect.options[ i ].hidden = false;
			}
		}
	}

	// Submit new owner contact form (admin page).
	$( document ).on( 'submit', '#af-owner-contact-form', function ( e ) {
        e.preventDefault();

        var $form = $( this );
        var $submit = $form.find( '#af-owner-contact-submit' );
        submitOwnerContactForm( $form, $submit );
    } );

	// Submit owner contact data via AJAX and keep UX smooth in new-contact mode.
	function submitOwnerContactForm( $form, $submit ) {
        var formEl = $form.get( 0 );
        var idEl = document.getElementById( 'af_owner_id' );
		var formData;
		var requiredDocumentsValidation;
		var fileValidation;
		var params = new URLSearchParams( window.location.search );
		var isNewMode = params.get( 'action' ) === 'new';
        if ( ! validateOwnerDocumentField() ) {
            if ( idEl ) {
                idEl.reportValidity();
                idEl.focus();
            }
            return;
        }

        if ( formEl && ! formEl.checkValidity() ) {
            formEl.reportValidity();
            return;
        }

		requiredDocumentsValidation = validateRequiredOwnerDocuments( formEl );
		if ( ! requiredDocumentsValidation.valid ) {
			alert( requiredDocumentsValidation.message );
			return;
		}

		fileValidation = validateOwnerUploadSize( formEl );
		if ( ! fileValidation.valid ) {
			alert( fileValidation.message );
			return;
		}

        $submit.prop( 'disabled', true );

		formData = new FormData( formEl );
		formData.set( 'action', 'af_send_owner_contact' );
		formData.set( 'nonce', afAdmin.ownerContactNonce || formData.get( 'nonce' ) || '' );

        $.ajax( {
            url: afAdmin.ajaxUrl,
            method: 'POST',
            dataType: 'json',
			data: formData,
			processData: false,
			contentType: false,
        } )
            .done( function ( response ) {
                if ( response && response.success ) {
                    if ( isNewMode && formEl ) {
						refreshOwnerContactsTable();
						formEl.reset();

						if ( idEl ) {
							idEl.setCustomValidity( '' );
						}

						// Re-apply dynamic field rules after reset.
						var ownerTypeEl = document.getElementById( 'af_owner_id_type' );
						if ( ownerTypeEl ) {
							ownerTypeEl.dispatchEvent( new Event( 'change' ) );
						}

						var legalNoRadio = document.querySelector( 'input[name="has_legal_agent"][value="0"]' );
						if ( legalNoRadio ) {
							legalNoRadio.checked = true;
							legalNoRadio.dispatchEvent( new Event( 'change' ) );
						}

						showOwnerContactNotice( 'success', 'Owner registered successfully.' );
						return;
					}

					var paged = params.get( 'paged' );
					var target = 'admin.php?page=af-owner-contacts';

					if ( paged ) {
						target += '&paged=' + encodeURIComponent( paged );
					}

					window.location.href = target;
                } else {
                    alert( response && response.data && response.data.message ? response.data.message : 'Error sending message.' );
                }
            } )
			.fail( function ( xhr ) {
				var body = ( xhr && typeof xhr.responseText === 'string' ) ? xhr.responseText.trim() : '';

				if ( xhr.status === 413 ) {
					alert( 'Request failed (413): upload is too large for server limits. Reduce file size or increase Nginx client_max_body_size and PHP post_max_size.' );
					return;
				}

				if ( xhr.status === 400 && body === '0' ) {
					alert( 'Request failed (400): admin-ajax did not receive a valid action or your session expired. Reload the page and try again.' );
					return;
				}

				if ( xhr.status === 403 && body === '-1' ) {
					alert( 'Nonce is invalid or expired. Reload the page and try again.' );
					return;
				}

				alert( 'Request failed (' + xhr.status + '): ' + ( body || 'no details' ) );
			} )
            .always( function () {
                $submit.prop( 'disabled', false );
            } );
    }

	function validateOwnerUploadSize( formEl ) {
		var maxFileBytes = parseInt( afAdmin.ownerMaxFileBytes, 10 ) || ( 10 * 1024 * 1024 );
		var maxTotalBytes = parseInt( afAdmin.ownerMaxTotalBytes, 10 ) || ( 8 * 1024 * 1024 );
		var safeTotalBytes = parseInt( afAdmin.ownerSafeTotalBytes, 10 ) || maxTotalBytes;
		var safetyMarginBytes = 128 * 1024;
		var effectiveTotalLimit = Math.max( 1, Math.min( maxTotalBytes, safeTotalBytes ) - safetyMarginBytes );
		var fieldNames = [
			'owner_bank_statement_pdf',
			'owner_police_record_pdf',
			'owner_additional_sensitive_pdf'
		];
		var totalBytes = 0;
		var i;

		for ( i = 0; i < fieldNames.length; i++ ) {
			var fileInput = formEl.querySelector( 'input[name="' + fieldNames[ i ] + '"]' );
			var file;

			if ( ! fileInput || ! fileInput.files || ! fileInput.files.length ) {
				continue;
			}

			file = fileInput.files[0];
			totalBytes += file.size;

			if ( file.size > maxFileBytes ) {
				return {
					valid: false,
					message: 'El archivo "' + file.name + '" supera el limite permitido. Maximo por archivo: ' + formatBytes( maxFileBytes ) + '.'
				};
			}
		}

		if ( totalBytes > effectiveTotalLimit ) {
			return {
				valid: false,
				message: 'La suma de archivos excede el limite del servidor (' + formatBytes( effectiveTotalLimit ) + '). Reduce tamano o sube menos archivos.'
			};
		}

		return { valid: true, message: '' };
	}

	function validateRequiredOwnerDocuments( formEl ) {
		var fieldNames = [
			'owner_bank_statement_pdf',
			'owner_police_record_pdf',
			'owner_additional_sensitive_pdf'
		];
		var uploadedCount = 0;
		var i;

		for ( i = 0; i < fieldNames.length; i++ ) {
			var fileInput = formEl.querySelector( 'input[name="' + fieldNames[ i ] + '"]' );

			if ( fileInput && fileInput.files && fileInput.files.length ) {
				uploadedCount++;
			}
		}

		if ( uploadedCount !== fieldNames.length ) {
			return {
				valid: false,
				message: 'Debes subir los 3 documentos PDF obligatorios antes de registrar al propietario.'
			};
		}

		return { valid: true, message: '' };
	}

	function formatBytes( bytes ) {
		if ( bytes <= 0 ) {
			return '0 B';
		}

		if ( bytes < 1024 * 1024 ) {
			return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
		}

		return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
	}

	// Render a runtime notice above the owner contact form.
	function showOwnerContactNotice( type, text ) {
		var formEl = document.getElementById( 'af-owner-contact-form' );
		if ( ! formEl ) {
			return;
		}

		var existingNotice = document.getElementById( 'af-owner-contact-runtime-notice' );
		if ( existingNotice ) {
			existingNotice.parentNode.removeChild( existingNotice );
		}

		var noticeEl = document.createElement( 'div' );
		noticeEl.id = 'af-owner-contact-runtime-notice';
		noticeEl.className = 'notice is-dismissible ' + ( type === 'success' ? 'notice-success' : 'notice-error' );
		noticeEl.innerHTML = '<p>' + text + '</p>';

		var cardEl = formEl.closest( '.card' );
		if ( cardEl ) {
			cardEl.insertBefore( noticeEl, formEl );
		}
	}

	// Refresh only owner contacts table body from server-rendered HTML.
	function refreshOwnerContactsTable() {
		var $tableBody = $( '.wp-list-table.widefat.fixed.striped tbody' ).first();
		if ( ! $tableBody.length ) {
			return;
		}

		$.get( window.location.href )
			.done( function ( html ) {
				var $doc = $( '<div></div>' ).append( $.parseHTML( html ) );
				var $newBody = $doc.find( '.wp-list-table.widefat.fixed.striped tbody' ).first();

				if ( $newBody.length ) {
					$tableBody.html( $newBody.html() );
				}
			} );
	}

	// Disable owner account from owner contacts table.
	$( document ).on( 'click', '.af-disable-owner', function () {
		var $btn = $( this );
		var userId = parseInt( $btn.data( 'user-id' ), 10 );
		var $row = $btn.closest( 'tr' );

		if ( ! userId ) {
			alert( 'Invalid user.' );
			return;
		}

		if ( ! window.confirm( 'Disable this account? The user will no longer be able to log in.' ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		$.post( afAdmin.ajaxUrl, {
			action: 'af_disable_owner_account',
			nonce: afAdmin.ownerContactNonce,
			user_id: userId,
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					$row.find( '.af-contact-status' ).text( response.data && response.data.contact_status ? response.data.contact_status : 'inactive' );
					$row.find( '.af-account-status' ).text( response.data && response.data.account_status ? response.data.account_status : 'disabled' );
					$row.find( '.af-account-actions' ).html( '<span class="description">-</span>' );
					return;
				}

				alert( response && response.data && response.data.message ? response.data.message : 'Could not disable account.' );
			} )
			.fail( function () {
				alert( 'Request failed.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	// Init owner + legal-agent form validation rules (owner contacts page).
	function initOwnerContactDocumentRules() {
        var typeEl = document.getElementById( 'af_owner_id_type' );
        var idEl = document.getElementById( 'af_owner_id' );
		var legalTypeEl = document.getElementById( 'af_legal_agent_id_type' );
		var legalIdEl = document.getElementById( 'af_legal_agent_id' );
		var legalFieldsEl = document.getElementById( 'af-legal-agent-fields' );
		var legalNameEl = document.getElementById( 'af_legal_agent_name' );
		var legalPhoneEl = document.getElementById( 'af_legal_agent_phone' );
		var legalEmailEl = document.getElementById( 'af_legal_agent_email' );
		var legalAgentRadios = document.querySelectorAll( 'input[name="has_legal_agent"]' );
		var formEl = document.getElementById( 'af-owner-contact-form' );

        if ( ! typeEl || ! idEl ) {
            return;
        }

		// Keep passport values uppercase while typing.
		function enforceUppercase() {
            idEl.value = idEl.value.toUpperCase();
        }

		// Apply HTML constraints according to selected owner document type.
        function applyRules() {
            idEl.removeEventListener( 'input', enforceUppercase );

            if ( typeEl.value === 'cedula' ) {
                idEl.setAttribute( 'pattern', '^[0-9]{10}$' );
                idEl.setAttribute( 'minlength', '10' );
                idEl.setAttribute( 'maxlength', '10' );
                idEl.setAttribute( 'title', 'Cedula: exactamente 10 digitos numericos' );
                return;
            }

            if ( typeEl.value === 'ruc' ) {
                idEl.setAttribute( 'pattern', '^[0-9]{13}$' );
                idEl.setAttribute( 'minlength', '13' );
                idEl.setAttribute( 'maxlength', '13' );
                idEl.setAttribute( 'title', 'RUC: exactamente 13 digitos numericos' );
                return;
            }

            idEl.setAttribute( 'pattern', '^[A-Za-z0-9]{6,15}$' );
            idEl.setAttribute( 'minlength', '6' );
            idEl.setAttribute( 'maxlength', '15' );
            idEl.setAttribute( 'title', 'Pasaporte: alfanumerico de 6 a 15 caracteres' );
            idEl.addEventListener( 'input', enforceUppercase );
						enforceUppercase();
        }

		// Keep legal-agent passport values uppercase while typing.
		function enforceLegalUppercase() {
			if ( legalIdEl ) {
				legalIdEl.value = legalIdEl.value.toUpperCase();
			}
		}

		// Apply HTML constraints according to selected legal-agent document type.
		function applyLegalIdRules() {
			if ( ! legalTypeEl || ! legalIdEl ) {
				return;
			}

			legalIdEl.removeEventListener( 'input', enforceLegalUppercase );

			if ( legalTypeEl.value === 'cedula' ) {
				legalIdEl.setAttribute( 'pattern', '^[0-9]{10}$' );
				legalIdEl.setAttribute( 'minlength', '10' );
				legalIdEl.setAttribute( 'maxlength', '10' );
				legalIdEl.setAttribute( 'title', 'Cedula: exactamente 10 digitos numericos' );
				return;
			}

			if ( legalTypeEl.value === 'ruc' ) {
				legalIdEl.setAttribute( 'pattern', '^[0-9]{13}$' );
				legalIdEl.setAttribute( 'minlength', '13' );
				legalIdEl.setAttribute( 'maxlength', '13' );
				legalIdEl.setAttribute( 'title', 'RUC: exactamente 13 digitos numericos' );
				return;
			}

			legalIdEl.setAttribute( 'pattern', '^[A-Za-z0-9]{6,15}$' );
			legalIdEl.setAttribute( 'minlength', '6' );
			legalIdEl.setAttribute( 'maxlength', '15' );
			legalIdEl.setAttribute( 'title', 'Pasaporte: alfanumerico de 6 a 15 caracteres' );
			legalIdEl.addEventListener( 'input', enforceLegalUppercase );
			enforceLegalUppercase();
		}

		// Check whether the user selected "Yes" for legal agent.
		function hasLegalAgentSelected() {
			var i;

			for ( i = 0; i < legalAgentRadios.length; i++ ) {
				if ( legalAgentRadios[ i ].checked && legalAgentRadios[ i ].value === '1' ) {
					return true;
				}
			}

			return false;
		}

		// Toggle required attributes for legal-agent fields.
		function setLegalRequired( required ) {
			if ( legalNameEl ) {
				legalNameEl.required = required;
			}
			if ( legalTypeEl ) {
				legalTypeEl.required = required;
			}
			if ( legalIdEl ) {
				legalIdEl.required = required;
			}
			if ( legalPhoneEl ) {
				legalPhoneEl.required = required;
			}
			if ( legalEmailEl ) {
				legalEmailEl.required = required;
			}
		}

		// Reset legal-agent field values when the section is hidden.
		function clearLegalFields() {
			if ( legalNameEl ) {
				legalNameEl.value = '';
			}
			if ( legalTypeEl ) {
				legalTypeEl.value = 'cedula';
			}
			if ( legalIdEl ) {
				legalIdEl.value = '';
			}
			if ( legalPhoneEl ) {
				legalPhoneEl.value = '';
			}
			if ( legalEmailEl ) {
				legalEmailEl.value = '';
			}

			applyLegalIdRules();
		}

		// Enforce numeric-only input for legal-agent phone.
		function enforceLegalPhoneNumeric() {
			if ( legalPhoneEl ) {
				legalPhoneEl.value = legalPhoneEl.value.replace( /[^0-9]/g, '' );
			}
		}

		// Show/hide legal-agent section and update related constraints.
		function toggleLegalAgentFields() {
			if ( ! legalFieldsEl ) {
				return;
			}

			if ( hasLegalAgentSelected() ) {
				legalFieldsEl.style.display = '';
				setLegalRequired( true );
				applyLegalIdRules();
				return;
			}

			legalFieldsEl.style.display = 'none';
			setLegalRequired( false );
			clearLegalFields();
		}

        typeEl.addEventListener( 'change', function () {
			idEl.value = '';
            applyRules();
            validateOwnerDocumentField();
        } );
				idEl.addEventListener( 'input', function () {
					validateOwnerDocumentField();
				} );

		if ( legalTypeEl ) {
			legalTypeEl.addEventListener( 'change', function () {
				if ( legalIdEl ) {
					legalIdEl.value = '';
				}
				applyLegalIdRules();
			} );
		}

		if ( legalPhoneEl ) {
			legalPhoneEl.addEventListener( 'input', enforceLegalPhoneNumeric );
		}

		var radioIndex;
		for ( radioIndex = 0; radioIndex < legalAgentRadios.length; radioIndex++ ) {
			legalAgentRadios[ radioIndex ].addEventListener( 'change', toggleLegalAgentFields );
		}

		if ( formEl ) {
			formEl.addEventListener( 'submit', function () {
				applyRules();
				toggleLegalAgentFields();
				if ( hasLegalAgentSelected() ) {
					applyLegalIdRules();
				}
			} );
		}

        applyRules();
		toggleLegalAgentFields();
    }

	// Show owner and legal-agent details in modal (owner contacts table).
	function initLegalAgentModal() {
		var modalEl = document.getElementById( 'af-legal-agent-modal' );

		if ( ! modalEl ) {
			return;
		}

		var ownerNameEl = modalEl.querySelector( '[data-af-field="owner-name"]' );
		var ownerIdEl = modalEl.querySelector( '[data-af-field="owner-id"]' );
		var ownerEmailEl = modalEl.querySelector( '[data-af-field="owner-email"]' );
		var ownerAccommodationsEl = modalEl.querySelector( '[data-af-field="owner-accommodations"]' );
		var legalSectionEl = modalEl.querySelector( '[data-af-legal-agent-section]' );
		var legalNameEl = modalEl.querySelector( '[data-af-field="legal-name"]' );
		var legalIdEl = modalEl.querySelector( '[data-af-field="legal-id"]' );
		var legalPhoneEl = modalEl.querySelector( '[data-af-field="legal-phone"]' );
		var legalEmailEl = modalEl.querySelector( '[data-af-field="legal-email"]' );

		// Normalize empty values to a fallback for modal display.
		function safeText( value ) {
			var text = value ? String( value ).trim() : '';
			return text || '-';
		}

		// Fill and open owner-details modal using clicked button dataset.
		function openModal( triggerEl ) {
			var ownerIdType = safeText( triggerEl.getAttribute( 'data-owner-id-type' ) );
			var ownerIdNumber = safeText( triggerEl.getAttribute( 'data-owner-id' ) );
			var hasLegalAgent = triggerEl.getAttribute( 'data-has-legal-agent' ) === '1';
			var accommodationsRaw = String( triggerEl.getAttribute( 'data-owner-accommodations' ) || '' ).trim();
			var accommodationsText = '-';
			var parsedAccommodations;

			ownerNameEl.textContent = safeText( triggerEl.getAttribute( 'data-owner-name' ) );
			ownerIdEl.textContent = ownerIdType + ': ' + ownerIdNumber;
			ownerEmailEl.textContent = safeText( triggerEl.getAttribute( 'data-owner-email' ) );

			if ( accommodationsRaw ) {
				try {
					parsedAccommodations = JSON.parse( accommodationsRaw );
					if ( Array.isArray( parsedAccommodations ) && parsedAccommodations.length ) {
						accommodationsText = parsedAccommodations.join( ', ' );
					}
				} catch ( error ) {
					accommodationsText = accommodationsRaw;
				}
			}

			if ( ownerAccommodationsEl ) {
				ownerAccommodationsEl.textContent = accommodationsText;
			}

			if ( legalSectionEl ) {
				legalSectionEl.style.display = hasLegalAgent ? '' : 'none';
			}

			if ( hasLegalAgent ) {
				legalNameEl.textContent = safeText( triggerEl.getAttribute( 'data-legal-agent-name' ) );
				legalIdEl.textContent = safeText( triggerEl.getAttribute( 'data-legal-agent-id-type' ) ) + ': ' + safeText( triggerEl.getAttribute( 'data-legal-agent-id' ) );
				legalPhoneEl.textContent = safeText( triggerEl.getAttribute( 'data-legal-agent-phone' ) );
				legalEmailEl.textContent = safeText( triggerEl.getAttribute( 'data-legal-agent-email' ) );
			}

			modalEl.hidden = false;
			document.body.classList.add( 'af-modal-open' );
		}

		// Close modal and restore body scrolling.
		function closeModal() {
			modalEl.hidden = true;
			document.body.classList.remove( 'af-modal-open' );
		}

		$( document ).on( 'click', '.af-open-owner-details-modal', function () {
			openModal( this );
		} );

		$( modalEl ).on( 'click', '[data-af-close-modal="1"]', function () {
			closeModal();
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && ! modalEl.hidden ) {
				closeModal();
			}
		} );
	}

	// Validate owner document field before submitting owner contact form.
	function validateOwnerDocumentField() {
        var typeEl = document.getElementById( 'af_owner_id_type' );
        var idEl = document.getElementById( 'af_owner_id' );

        if ( ! typeEl || ! idEl ) {
            return true;
        }

        var type = ( typeEl.value || '' ).trim();
        var value = ( idEl.value || '' ).trim();
        var ok = true;
        var msg = '';

        if ( type === 'cedula' ) {
            ok = /^[0-9]{10}$/.test( value );
            msg = 'Cedula: exactamente 10 digitos numericos.';
        } else if ( type === 'ruc' ) {
            ok = /^[0-9]{13}$/.test( value );
            msg = 'RUC: exactamente 13 digitos numericos.';
        } else if ( type === 'pasaporte' ) {
            ok = /^[A-Za-z0-9]{6,15}$/.test( value );
            msg = 'Pasaporte: alfanumerico de 6 a 15 caracteres.';
        } else {
            ok = false;
            msg = 'Seleccione un tipo de documento valido.';
        }

        idEl.setCustomValidity( ok ? '' : msg );
        return ok;
    }

	// Bootstrap owner contacts UI behaviors on admin page load.
	$( function () {
		try {
			initOwnerContactDocumentRules();
		} catch ( ownerContactsInitError ) {
			window.console && window.console.error && window.console.error( 'Owner contact rules init failed', ownerContactsInitError );
		}

		try {
			initLegalAgentModal();
		} catch ( legalAgentModalInitError ) {
			window.console && window.console.error && window.console.error( 'Legal agent modal init failed', legalAgentModalInitError );
		}
  } );

	// ── Score guest via AI ──────────────────────────────────────────────────
	$( document ).on( 'click', '.af-score-guest', function () {
		var $btn = $( this );
		var guestId = $btn.data( 'guest-id' );

		$btn.prop( 'disabled', true );

		$.post( afAdmin.ajaxUrl, {
			action: 'af_score_guest',
			nonce: afAdmin.guestNonce,
			guest_id: guestId,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					var score = parseFloat( response.data.score ).toFixed( 2 );
					$btn.closest( 'tr' ).find( 'td:nth-child(6)' ).text( score );
					if ( response.data.summary ) {
						alert( 'AI Summary: ' + response.data.summary );
					}
				} else {
					alert( response.data && response.data.message ? response.data.message : 'Error scoring guest.' );
				}
			} )
			.fail( function () {
				alert( 'Request failed.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	// ── Create guest from Guests page form ─────────────────────────────────
	$( document ).on( 'click', '#af-new-guest', function () {
		$( '#af-guest-form-card' ).show();
		$( '#af_guest_id_number' ).trigger( 'focus' );
	} );

	$( document ).on( 'click', '#af-cancel-new-guest', function () {
		var formEl = document.getElementById( 'af-guest-form' );
		if ( formEl ) {
			formEl.reset();
		}
		$( '#af-guest-form-card' ).hide();
	} );

	$( document ).on( 'input', '#af_guest_phone, #af_guest_id_number', function () {
		var sanitized = String( $( this ).val() || '' ).replace( /\D+/g, '' ).slice( 0, 10 );
		$( this ).val( sanitized );
	} );

	$( document ).on( 'submit', '#af-guest-form', function ( event ) {
		event.preventDefault();

		var formEl = this;
		var $form = $( formEl );
		var $submit = $form.find( 'button[type="submit"]' );
		var formData;

		if ( ! formEl.checkValidity() ) {
			formEl.reportValidity();
			return;
		}

		$submit.prop( 'disabled', true );

		formData = new FormData( formEl );
		formData.set( 'action', 'af_create_guest' );
		formData.set( 'nonce', afAdmin.guestNonce || formData.get( 'nonce' ) || '' );

		$.ajax( {
			url: afAdmin.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: formData,
			processData: false,
			contentType: false,
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
					return;
				}

				alert( response && response.data && response.data.message ? response.data.message : 'Could not create guest.' );
			} )
			.fail( function () {
				alert( 'Request failed.' );
			} )
			.always( function () {
				$submit.prop( 'disabled', false );
			} );
	} );

	// ── Show forms for new entries ──────────────────────────────────────────
	$( document ).on( 'click', '.af-new-entry', function () {
		var $btn = $( this );
		var entryType = $btn.data( 'entry-type' );

		// Example logic to show forms dynamically
		if ( entryType === 'cleaning-request' ) {
			alert( 'Show form for New Cleaning Request' );
		} else if ( entryType === 'owner-contact' ) {
			alert( 'Show form for New Owner Contact' );
		} else if ( entryType === 'lease' ) {
			alert( 'Show form for New Lease' );
		} else if ( entryType === 'guest' ) {
			alert( 'Show form for New Guest' );
		} else {
			alert( 'Unknown entry type.' );
		}
	} );

	// ── Template Preview Handler ─────────────────────────────────────────────

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

	/**
	 * Walks the DOM tree and collects all text nodes in document order.
	 */
	function afCollectTextNodes( root ) {
		var nodes = [];
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, null, false );
		var node;
		while ( ( node = walker.nextNode() ) ) {
			nodes.push( node );
		}
		return nodes;
	}

	/**
	 * Injects field-tag dropdowns into the mammoth-rendered HTML by replacing
	 * blank patterns (_____, ....., tabs) in text nodes with interactive selects.
	 * Blanks are matched in document order, 1:1 with the server's field_map.
	 */
	function afInjectFieldTagsIntoHtml( container, fieldMap ) {
		var blankPattern = /_{3,}|\.{4,}|\u2026{2,}|-{4,}|\t+/g;
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

	// ── SRI Configuration Validation ────────────────────────────────────────────

	// Email validation - show error message if invalid
	$( document ).on( 'change keyup', '#af_email_notificacion', function () {
		var $field = $( this );
		var email = $field.val().trim();
		var $error = $( '#af-email-error' );
		var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

		if ( email.length > 0 && ! emailRegex.test( email ) ) {
			$error.text( 'Formato de email inválido. Ej: admin@ejemplo.com' ).show();
		} else {
			$error.hide();
		}
	} );

	// ─── Auto-fill y badge: Dirección Matriz = Dirección del Establecimiento ─
	function afUpdateDirMatrizBadge() {
		var $estabEl = $( '#af_dir_establecimiento' );
		var $matrizEl = $( '#af_dir_matriz' );
		if ( ! $estabEl.length || ! $matrizEl.length ) {
			return;
		}
		var estab  = $estabEl.val().trim();
		var matriz = $matrizEl.val().trim();
		var $desc  = $( '#af-dir-matriz-desc' );

		if ( $desc.length === 0 ) {
			return;
		}

		if ( estab && estab === matriz ) {
			$desc.html( '<span style="color:#2e7d32; font-weight:500;">✓ Igual a la dirección del establecimiento.</span>' );
		} else {
			$desc.text( 'Opcional. Solo si es diferente a la dirección del establecimiento.' );
		}
	}

	// Cuando el usuario escribe en Dirección del Establecimiento,
	// auto-completar Dirección Matriz si estaba vacía o sincronizada.
	$( document ).on( 'input', '#af_dir_establecimiento', function () {
		var $matriz   = $( '#af_dir_matriz' );
		var prevEstab = $matriz.data( 'af-prev-estab' ) || '';
		var curMatriz = $matriz.val().trim();

		if ( curMatriz === '' || curMatriz === prevEstab ) {
			$matriz.val( $( this ).val() );
		}

		$matriz.data( 'af-prev-estab', $( this ).val().trim() );
		afUpdateDirMatrizBadge();
	} );

	// Cuando el usuario edita Dirección Matriz manualmente, solo actualizar badge.
	$( document ).on( 'input', '#af_dir_matriz', afUpdateDirMatrizBadge );

	// Inicializar al cargar la página (solo en billing-settings).
	if ( $( '#af_dir_establecimiento' ).length ) {
		var $afDirMatrizInit = $( '#af_dir_matriz' );
		var afEstabInit = $( '#af_dir_establecimiento' ).val().trim();
		if ( $afDirMatrizInit.val().trim() === '' && afEstabInit !== '' ) {
			$afDirMatrizInit.val( $( '#af_dir_establecimiento' ).val() );
		}
		$afDirMatrizInit.data( 'af-prev-estab', $( '#af_dir_establecimiento' ).val().trim() );
		afUpdateDirMatrizBadge();
	}

} )( jQuery );
