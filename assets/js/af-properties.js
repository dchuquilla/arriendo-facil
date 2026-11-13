/**
 * Arriendo Fácil — Inmuebles: confirmaciones, orden automático y menús de tarjeta.
 */
(function () {
	'use strict';

	document.addEventListener('change', function (event) {
		var select = event.target.closest && event.target.closest('[data-af-autosubmit]');
		if (select && select.form) {
			select.form.submit();
		}
	});

	document.addEventListener('click', function (event) {
		var confirmLink = event.target.closest && event.target.closest('[data-af-confirm]');
		if (confirmLink && !window.confirm(confirmLink.getAttribute('data-af-confirm'))) {
			event.preventDefault();
			return;
		}

		// Un solo menú "⋯" abierto a la vez; se cierra al pulsar fuera.
		var open = event.target.closest && event.target.closest('.af-pc__more');
		Array.prototype.forEach.call(document.querySelectorAll('.af-pc__more[open]'), function (menu) {
			if (menu !== open) {
				menu.removeAttribute('open');
			}
		});
	});

	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Escape') {
			return;
		}
		Array.prototype.forEach.call(document.querySelectorAll('.af-pc__more[open]'), function (menu) {
			menu.removeAttribute('open');
		});
	});
}());
