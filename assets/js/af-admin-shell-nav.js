/**
 * Arriendo Facil — Custom admin app shell sidebar behaviour.
 *
 * Handles the desktop collapse toggle (persisted in localStorage) and the
 * mobile drawer toggle for the branded sidebar rendered by
 * Arriendo_Facil_Admin::render_custom_shell_nav().
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var body           = document.body;
		var collapseToggle = document.getElementById('af-app-sidebar-toggle');
		var mobileToggle    = document.getElementById('af-app-sidebar-mobile-toggle');
		var storageKey      = 'af_admin_sidebar_collapsed';

		if (window.localStorage && localStorage.getItem(storageKey) === '1') {
			body.classList.add('af-sidebar-collapsed');
		}

		if (collapseToggle) {
			collapseToggle.addEventListener('click', function () {
				var collapsed = body.classList.toggle('af-sidebar-collapsed');
				if (window.localStorage) {
					localStorage.setItem(storageKey, collapsed ? '1' : '0');
				}
			});
		}

		if (mobileToggle) {
			mobileToggle.addEventListener('click', function () {
				var open = body.classList.toggle('af-sidebar-open');
				mobileToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
		}
	});
})();
