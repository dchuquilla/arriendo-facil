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

	// ── Mark owner contact as read ──────────────────────────────────────────
	$( document ).on( 'click', '.af-mark-read', function () {
		var $btn = $( this );
		var contactId = $btn.data( 'contact-id' );

		$btn.prop( 'disabled', true );

		$.post( afAdmin.ajaxUrl, {
			action: 'af_mark_contact_read',
			nonce: afAdmin.ownerContactNonce,
			contact_id: contactId,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					var $row = $btn.closest( 'tr' );
					$row.removeClass( 'af-unread' );
					$row.find( '.af-contact-status' ).text( 'read' );
					$btn.remove();
				} else {
					alert( response.data && response.data.message ? response.data.message : 'Error.' );
					$btn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				alert( 'Request failed.' );
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
                    window.location.href = 'admin.php?page=af-owner-contacts';
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

    function initOwnerContactDocumentRules() {
        var typeEl = document.getElementById( 'af_owner_id_type' );
        var idEl = document.getElementById( 'af_owner_id' );

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
        }

        typeEl.addEventListener( 'change', function () {
            applyRules();
            validateOwnerDocumentField();
        } );
				idEl.addEventListener( 'input', function () {
					validateOwnerDocumentField();
				} );

        applyRules();
    }

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

    $( function () {
        initOwnerContactDocumentRules();
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
