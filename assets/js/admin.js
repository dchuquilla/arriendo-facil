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

	// ── Change lease status ─────────────────────────────────────────────────
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

	// Submit new owner contact form (admin page).
	$( document ).on( 'submit', '#af-owner-contact-form', function ( e ) {
        e.preventDefault();

        var $form = $( this );
        var $submit = $form.find( '#af-owner-contact-submit' );
        submitOwnerContactForm( $form, $submit );
    } );

    function submitOwnerContactForm( $form, $submit ) {
        var formEl = $form.get( 0 );
        var idEl = document.getElementById( 'af_owner_id' );
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

        $submit.prop( 'disabled', true );

        $.ajax( {
            url: afAdmin.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: $form.serialize(),
        } )
            .done( function ( response ) {
                if ( response && response.success ) {
                    var params = new URLSearchParams( window.location.search );
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
                alert( 'Request failed (' + xhr.status + ').' );
            } )
            .always( function () {
                $submit.prop( 'disabled', false );
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

        function enforceUppercase() {
            idEl.value = idEl.value.toUpperCase();
        }

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

		function enforceLegalUppercase() {
			if ( legalIdEl ) {
				legalIdEl.value = legalIdEl.value.toUpperCase();
			}
		}

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

		function hasLegalAgentSelected() {
			var i;

			for ( i = 0; i < legalAgentRadios.length; i++ ) {
				if ( legalAgentRadios[ i ].checked && legalAgentRadios[ i ].value === '1' ) {
					return true;
				}
			}

			return false;
		}

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

		function enforceLegalPhoneNumeric() {
			if ( legalPhoneEl ) {
				legalPhoneEl.value = legalPhoneEl.value.replace( /[^0-9]/g, '' );
			}
		}

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

		legalAgentRadios.forEach( function ( radioEl ) {
			radioEl.addEventListener( 'change', toggleLegalAgentFields );
		} );

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

	// Show legal agent details in modal (owner contacts table).
	function initLegalAgentModal() {
		var modalEl = document.getElementById( 'af-legal-agent-modal' );

		if ( ! modalEl ) {
			return;
		}

		var nameEl = modalEl.querySelector( '[data-af-field="name"]' );
		var idEl = modalEl.querySelector( '[data-af-field="id"]' );
		var phoneEl = modalEl.querySelector( '[data-af-field="phone"]' );
		var emailEl = modalEl.querySelector( '[data-af-field="email"]' );

		function safeText( value ) {
			var text = value ? String( value ).trim() : '';
			return text || '-';
		}

		function openModal( triggerEl ) {
			var idType = safeText( triggerEl.getAttribute( 'data-legal-agent-id-type' ) );
			var idNumber = safeText( triggerEl.getAttribute( 'data-legal-agent-id' ) );

			nameEl.textContent = safeText( triggerEl.getAttribute( 'data-legal-agent-name' ) );
			idEl.textContent = idType + ': ' + idNumber;
			phoneEl.textContent = safeText( triggerEl.getAttribute( 'data-legal-agent-phone' ) );
			emailEl.textContent = safeText( triggerEl.getAttribute( 'data-legal-agent-email' ) );

			modalEl.hidden = false;
			document.body.classList.add( 'af-modal-open' );
		}

		function closeModal() {
			modalEl.hidden = true;
			document.body.classList.remove( 'af-modal-open' );
		}

		$( document ).on( 'click', '.af-open-legal-agent-modal', function () {
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
        initOwnerContactDocumentRules();
		initLegalAgentModal();
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

} )( jQuery );
