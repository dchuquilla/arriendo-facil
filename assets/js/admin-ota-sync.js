(function($) {
	'use strict';

	$(document).ready(function() {
		// Test iCal URL button
		$(document).on('click', '.af-test-ical-btn', function(e) {
			e.preventDefault();

			const $btn = $(this);
			const platform = $btn.data('platform');
			const accommodationId = $btn.data('accommodation-id');
			const $result = $btn.siblings('.af-test-result[data-platform="' + platform + '"]');

			const urlField = platform === 'booking' ? '#af_booking_ical_url' : '#af_airbnb_ical_url';
			const icalUrl = $(urlField).val();

			if (!icalUrl) {
				$result.removeClass('success error loading').addClass('error').text('❌ Por favor ingresa una URL iCal').show();
				return;
			}

			$btn.prop('disabled', true);
			$result.removeClass('success error').addClass('loading').text('⏳ Probando...').show();

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'af_test_ical_url',
					platform: platform,
					ical_url: icalUrl,
					accommodation_id: accommodationId,
					nonce: afOtaSync.nonce
				},
				success: function(response) {
					if (response.success) {
						$result.removeClass('loading error').addClass('success')
							.text('✓ URL válida. Ocupación: ' + (response.data.is_occupied ? 'Ocupada' : 'Disponible'))
							.show();
					} else {
						$result.removeClass('loading success').addClass('error')
							.text('❌ ' + (response.data || 'Error desconocido'))
							.show();
					}
				},
				error: function() {
					$result.removeClass('loading success').addClass('error')
						.text('❌ Error de conexión')
						.show();
				},
				complete: function() {
					$btn.prop('disabled', false);
				}
			});
		});

		// Manual sync button
		$(document).on('click', '.af-sync-now-btn', function(e) {
			e.preventDefault();

			const $btn = $(this);
			const accommodationId = $btn.data('accommodation-id');
			const $msg = $btn.siblings('.af-sync-message');

			$btn.prop('disabled', true).text('⏳ Sincronizando...');
			$msg.removeClass('success error').addClass('loading').text('Sincronizando...').show();

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'af_sync_accommodation_now',
					accommodation_id: accommodationId,
					nonce: afOtaSync.nonce
				},
				success: function(response) {
					if (response.success) {
						$msg.removeClass('loading error').addClass('success')
							.text('✓ Sincronización completada')
							.show();
						setTimeout(function() {
							location.reload();
						}, 2000);
					} else {
						$msg.removeClass('loading success').addClass('error')
							.text('❌ ' + (response.data || 'Error en sincronización'))
							.show();
					}
				},
				error: function() {
					$msg.removeClass('loading success').addClass('error')
						.text('❌ Error de conexión')
						.show();
				},
				complete: function() {
					$btn.prop('disabled', false).text('Sincronizar Ahora');
				}
			});
		});
	});
})(jQuery);
