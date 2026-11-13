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
		var mobileQuery     = window.matchMedia('(max-width: 782px)');

		var closeDrawer = function () {
			body.classList.remove('af-sidebar-open');
			if (mobileToggle) {
				mobileToggle.setAttribute('aria-expanded', 'false');
			}
		};

		var stored = window.localStorage ? localStorage.getItem(storageKey) : null;
		var tabletQuery = window.matchMedia('(min-width: 783px) and (max-width: 1100px)');

		// Sin preferencia guardada, en tablets el menú arranca como riel de iconos para dar espacio al contenido.
		if (stored === '1' || (stored === null && tabletQuery.matches)) {
			body.classList.add('af-sidebar-collapsed');
		}

		if (collapseToggle) {
			collapseToggle.addEventListener('click', function () {
				if (mobileQuery.matches) {
					closeDrawer();
					return;
				}
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

		document.addEventListener('click', function (event) {
			if (body.classList.contains('af-sidebar-open') && !event.target.closest('.af-app-sidebar') && event.target !== mobileToggle && !(mobileToggle && mobileToggle.contains(event.target))) {
				closeDrawer();
			}
		});

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeDrawer();
			}
		});

		// Sections: every group starts open so all sub-sections are visible at a
		// glance. A header folds/unfolds its own list (several can stay open) and
		// the choice is remembered. The active group can never stay folded.
		var groupsKey = 'af_admin_nav_groups';
		var saved = {};
		try {
			saved = JSON.parse(window.localStorage.getItem(groupsKey) || '{}') || {};
		} catch (e) {
			saved = {};
		}

		var persistGroups = function () {
			try {
				window.localStorage.setItem(groupsKey, JSON.stringify(saved));
			} catch (e) { /* storage blocked */ }
		};

		var hasGsap = !!window.gsap && !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

		var setGroup = function (group, open, animate) {
			var link = group.firstElementChild;
			var list = group.querySelector('.af-app-sidebar__subnav');
			link.setAttribute('aria-expanded', open ? 'true' : 'false');

			if (!list || !hasGsap || !animate) {
				group.classList.toggle('is-open', open);
				return;
			}

			if (open) {
				group.classList.add('is-open');
				window.gsap.fromTo(list,
					{ height: 0, opacity: 0 },
					{ height: 'auto', opacity: 1, duration: 0.3, ease: 'power2.out', clearProps: 'height,opacity', overwrite: true }
				);
			} else {
				window.gsap.to(list, {
					height: 0,
					opacity: 0,
					duration: 0.22,
					ease: 'power2.in',
					overwrite: true,
					onComplete: function () {
						group.classList.remove('is-open');
						window.gsap.set(list, { clearProps: 'height,opacity' });
					}
				});
			}
		};

		Array.prototype.forEach.call(document.querySelectorAll('.af-app-sidebar__group'), function (group) {
			var slug = group.getAttribute('data-group') || '';
			var active = group.classList.contains('is-active');
			setGroup(group, active || saved[slug] !== false, false);

			group.firstElementChild.addEventListener('click', function (event) {
				if (body.classList.contains('af-sidebar-collapsed') && !mobileQuery.matches) {
					return;
				}
				event.preventDefault();
				var willOpen = !group.classList.contains('is-open');
				saved[slug] = willOpen;
				persistGroups();
				setGroup(group, willOpen, true);
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
