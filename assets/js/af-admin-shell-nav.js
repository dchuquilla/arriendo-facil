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

		// Accordion: clicking a group header expands/collapses its sub-items.
		// When the sidebar is collapsed to icons the header keeps navigating.
		var groupLinks = document.querySelectorAll('.af-app-sidebar__group-link');
		Array.prototype.forEach.call(groupLinks, function (link) {
			link.addEventListener('click', function (event) {
				if (body.classList.contains('af-sidebar-collapsed')) {
					return;
				}
				event.preventDefault();
				var group = link.parentNode;
				var open  = group.classList.toggle('is-open');
				link.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
		});

		// User dropdown (top bar).
		var userMenu   = document.getElementById('af-user-menu');
		var userToggle = document.getElementById('af-user-menu-toggle');
		var userPanel  = document.getElementById('af-user-menu-panel');

		if (userMenu && userToggle && userPanel) {
			var setUserMenu = function (open) {
				userPanel.hidden = !open;
				userToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			};

			userToggle.addEventListener('click', function (event) {
				event.stopPropagation();
				setUserMenu(userPanel.hidden);
			});

			document.addEventListener('click', function (event) {
				if (!userPanel.hidden && !userMenu.contains(event.target)) {
					setUserMenu(false);
				}
			});

			document.addEventListener('keydown', function (event) {
				if (event.key === 'Escape' && !userPanel.hidden) {
					setUserMenu(false);
					userToggle.focus();
				}
			});
		}
	});
})();
