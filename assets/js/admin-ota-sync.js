/**
 * OTA Sync Manager - Admin JavaScript
 *
 * Handles AJAX interactions for OTA integration in admin.
 *
 * @package Arriendo_Facil
 */

(function ($) {
	'use strict';

	$(document).ready(function () {
		// Manual sync button in accommodation meta box
		$('#af-sync-ota-now').on('click', function (e) {
			e.preventDefault();

			var btn = $(this);
			var accommodationId = btn.data('accommodation-id');
			var nonce = btn.data('nonce');
			var resultEl = $('#af-sync-result');

			if (!accommodationId) {
				return;
			}

			// Disable button and show loading state
			btn.prop('disabled', true).addClass('loading');
			resultEl
				.removeClass('success error')
				.addClass('loading')
				.text(afAdmin.i18n.syncing || 'Sincronizando...')
				.show();

			// AJAX request
			$.post(
				afAdmin.ajaxUrl,
				{
					action: 'af_sync_accommodation_manual',
					accommodation_id: accommodationId,
					nonce: nonce,
				},
				function (response) {
					if (response.success) {
						resultEl
							.removeClass('loading error')
							.addClass('success')
							.text('✓ ' + (response.data.message || 'Sincronizado'))
							.show();

						// Reload page after 2 seconds to show updated status
						setTimeout(function () {
							location.reload();
						}, 2000);
					} else {
						resultEl
							.removeClass('loading success')
							.addClass('error')
							.text('✗ ' + (response.data.message || 'Error en sincronización'))
							.show();
					}
				}
			).fail(function () {
				resultEl
					.removeClass('loading success')
					.addClass('error')
					.text('✗ ' + (afAdmin.i18n.error || 'Error de conexión'))
					.show();
			}).always(function () {
				btn.prop('disabled', false).removeClass('loading');
			});
		});
	});
})(jQuery);
