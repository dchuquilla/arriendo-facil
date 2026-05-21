(function () {
	'use strict';

	var config = window.afLocationPicker || {};
	var defaultLat = config.defaultLat || -0.1807;
	var defaultLng = config.defaultLng || -78.4678;
	var bounds = config.ecuadorBounds || { latMin: -5, latMax: 2, lngMin: -81, lngMax: -75 };

	var map, marker;
	var searchInput, suggestionsEl, latInput, lngInput, addressInput, locationTextInput, cityInput;
	var debounceTimer;
	var activeIndex = -1;
	var suggestions = [];

	function init() {
		searchInput = document.getElementById('af_location_search');
		suggestionsEl = document.getElementById('af-location-suggestions');
		latInput = document.getElementById('af_latitude');
		lngInput = document.getElementById('af_longitude');
		addressInput = document.getElementById('af_address');
		locationTextInput = document.getElementById('af_location_text');
		cityInput = document.getElementById('af_city');

		if (!searchInput || !document.getElementById('af-location-map')) {
			return;
		}

		var hasSavedCoords = latInput.value && lngInput.value &&
			parseFloat(latInput.value) !== 0 && parseFloat(lngInput.value) !== 0;

		// Disable address fields until location is set.
		if (!hasSavedCoords) {
			setAddressFieldsDisabled(true);
		}
		var startLat = hasSavedCoords ? parseFloat(latInput.value) : defaultLat;
		var startLng = hasSavedCoords ? parseFloat(lngInput.value) : defaultLng;

		map = L.map('af-location-map', { keyboard: false, scrollWheelZoom: true }).setView([startLat, startLng], 14);

		// Prevent Leaflet from stealing focus and scrolling the page.
		var mapEl = document.getElementById('af-location-map');
		mapEl.removeAttribute('tabindex');

		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; OpenStreetMap contributors',
			maxZoom: 19
		}).addTo(map);

		marker = L.marker([startLat, startLng], { draggable: true }).addTo(map);

		marker.on('dragend', function () {
			var pos = marker.getLatLng();
			if (!isInEcuador(pos.lat, pos.lng)) {
				marker.setLatLng([startLat, startLng]);
				return;
			}
			updateCoords(pos.lat, pos.lng);
			reverseGeocode(pos.lat, pos.lng);
		});

		setTimeout(function () { map.invalidateSize(); }, 100);
		setTimeout(function () { map.invalidateSize(); }, 500);
		setTimeout(function () { map.invalidateSize(); }, 1500);
		setTimeout(function () { map.invalidateSize(); }, 3000);

		// Observe container size changes — covers all rendering edge cases.
		var mapContainer = document.getElementById('af-location-map');
		if (window.ResizeObserver) {
			var ro = new ResizeObserver(function () {
				map.invalidateSize();
			});
			ro.observe(mapContainer);
		}

		// Observe parent visibility changes (meta box toggle, tabs, etc.).
		var metaBox = mapContainer.closest('.postbox');
		if (metaBox && window.MutationObserver) {
			var mo = new MutationObserver(function () {
				setTimeout(function () { map.invalidateSize(); }, 50);
			});
			mo.observe(metaBox, { attributes: true, attributeFilter: ['class', 'style'] });
		}

		jQuery(document).on('postbox-toggled', function () {
			setTimeout(function () { map.invalidateSize(); }, 200);
		});

		// Also invalidate when the window finishes loading all assets.
		window.addEventListener('load', function () {
			setTimeout(function () { map.invalidateSize(); }, 100);
		});

		// If no saved coordinates, center map on user's device location (visual only, does not save).
		if (!hasSavedCoords && navigator.geolocation) {
			navigator.geolocation.getCurrentPosition(function (position) {
				var lat = position.coords.latitude;
				var lng = position.coords.longitude;
				if (isInEcuador(lat, lng)) {
					map.setView([lat, lng], 13);
					marker.setLatLng([lat, lng]);
				}
			});
		}

		searchInput.addEventListener('input', onSearchInput);
		searchInput.addEventListener('paste', onSearchPaste);
		searchInput.addEventListener('keydown', onSearchKeydown);

		var searchBtn = document.getElementById('af_location_search_btn');
		if (searchBtn) {
			searchBtn.addEventListener('click', triggerSearch);
		}

		document.addEventListener('click', function (e) {
			if (!suggestionsEl.contains(e.target) && e.target !== searchInput) {
				closeSuggestions();
			}
		});
	}

	function setAddressFieldsDisabled(disabled) {
		[addressInput, locationTextInput, cityInput].forEach(function (el) {
			if (el) {
				el.readOnly = disabled;
				el.style.opacity = disabled ? '0.6' : '1';
			}
		});
	}

	function triggerSearch() {
		var query = searchInput.value.trim();
		if (!query || query.length < 3) return;

		if (handleUrlInput(query)) return;

		clearTimeout(debounceTimer);
		searchNominatim(query, true);
	}

	function isInEcuador(lat, lng) {
		return lat >= bounds.latMin && lat <= bounds.latMax &&
			lng >= bounds.lngMin && lng <= bounds.lngMax;
	}

	function updateCoords(lat, lng) {
		latInput.value = lat.toFixed(6);
		lngInput.value = lng.toFixed(6);
	}

	function parseGoogleMapsUrl(text) {
		// Pattern: @lat,lng or ?q=lat,lng or !3d<lat>!4d<lng>
		var patterns = [
			/@(-?\d+\.?\d*),(-?\d+\.?\d*)/,
			/[?&]q=(-?\d+\.?\d*),(-?\d+\.?\d*)/,
			/!3d(-?\d+\.?\d*)!4d(-?\d+\.?\d*)/
		];
		for (var i = 0; i < patterns.length; i++) {
			var match = text.match(patterns[i]);
			if (match) {
				var lat = parseFloat(match[1]);
				var lng = parseFloat(match[2]);
				if (isInEcuador(lat, lng)) {
					return { lat: lat, lng: lng };
				}
			}
		}
		return null;
	}

	function isShortMapUrl(text) {
		return /^https?:\/\/(goo\.gl\/maps|maps\.app\.goo\.gl)\//i.test(text);
	}

	function resolveShortUrl(url, callback) {
		jQuery.post(config.ajaxUrl, {
			action: 'af_resolve_short_url',
			nonce: config.nonce,
			url: url
		}, function (response) {
			if (response.success && response.data.resolved_url) {
				var coords = parseGoogleMapsUrl(response.data.resolved_url);
				callback(coords);
			} else {
				callback(null);
			}
		}).fail(function () {
			callback(null);
		});
	}

	function handleUrlInput(text) {
		var coords = parseGoogleMapsUrl(text);
		if (coords) {
			moveToCoords(coords.lat, coords.lng);
			return true;
		}

		if (isShortMapUrl(text)) {
			resolveShortUrl(text, function (resolvedCoords) {
				if (resolvedCoords) {
					moveToCoords(resolvedCoords.lat, resolvedCoords.lng);
				}
			});
			return true;
		}

		return false;
	}

	function onSearchPaste(e) {
		setTimeout(function () {
			var text = searchInput.value.trim();
			handleUrlInput(text);
		}, 0);
	}

	function moveToCoords(lat, lng) {
		updateCoords(lat, lng);
		marker.setLatLng([lat, lng]);
		map.setView([lat, lng], 16);
		reverseGeocode(lat, lng);
		closeSuggestions();
	}

	function onSearchInput() {
		var query = searchInput.value.trim();
		clearTimeout(debounceTimer);

		if (handleUrlInput(query)) return;

		if (query.length < 3) {
			closeSuggestions();
			return;
		}

		debounceTimer = setTimeout(function () {
			searchNominatim(query);
		}, 350);
	}

	function onSearchKeydown(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			if (suggestions.length && activeIndex >= 0) {
				selectSuggestion(suggestions[activeIndex]);
			} else if (suggestions.length) {
				selectSuggestion(suggestions[0]);
			} else {
				// Force search immediately on Enter.
				var query = searchInput.value.trim();
				if (query.length >= 3) {
					clearTimeout(debounceTimer);
					searchNominatim(query, true);
				}
			}
			return;
		}

		if (!suggestions.length) return;

		if (e.key === 'ArrowDown') {
			e.preventDefault();
			activeIndex = Math.min(activeIndex + 1, suggestions.length - 1);
			highlightSuggestion();
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			activeIndex = Math.max(activeIndex - 1, 0);
			highlightSuggestion();
		} else if (e.key === 'Escape') {
			closeSuggestions();
		}
	}

	function highlightSuggestion() {
		var items = suggestionsEl.querySelectorAll('.af-suggestion-item');
		items.forEach(function (item, i) {
			item.classList.toggle('af-suggestion-item--active', i === activeIndex);
		});
	}

	function closeSuggestions() {
		suggestionsEl.innerHTML = '';
		suggestions = [];
		activeIndex = -1;
	}

	function searchNominatim(query, autoSelect) {
		var queries = buildSearchVariants(query);
		searchWithFallback(queries, 0, autoSelect || false);
	}

	function buildSearchVariants(query) {
		var variants = [query];

		// For Ecuadorian intersections: "Calle X y Calle Y" → try "Calle X, Ecuador"
		var intersection = query.split(/\s+y\s+/i);
		if (intersection.length === 2) {
			variants.push(intersection[0].trim() + ', ' + intersection[1].trim() + ', Ecuador');
			variants.push(intersection[0].trim() + ', Ecuador');
		}

		// Append Ecuador if not already present.
		if (!/ecuador/i.test(query)) {
			variants.push(query + ', Ecuador');
		}

		return variants;
	}

	function searchWithFallback(queries, index, autoSelect) {
		if (index >= queries.length) {
			closeSuggestions();
			return;
		}

		var url = 'https://nominatim.openstreetmap.org/search?format=json&countrycodes=ec&limit=5&addressdetails=1&q=' + encodeURIComponent(queries[index]);

		fetch(url, { headers: { 'Accept-Language': 'es' } })
			.then(function (r) { return r.json(); })
			.then(function (results) {
				suggestions = results.filter(function (r) {
					return isInEcuador(parseFloat(r.lat), parseFloat(r.lon));
				});

				if (suggestions.length) {
					if (autoSelect) {
						selectSuggestion(suggestions[0]);
					} else {
						renderSuggestions();
					}
				} else {
					searchWithFallback(queries, index + 1, autoSelect);
				}
			})
			.catch(function () {
				searchWithFallback(queries, index + 1, autoSelect);
			});
	}

	function renderSuggestions() {
		suggestionsEl.innerHTML = '';
		activeIndex = -1;

		if (!suggestions.length) return;

		suggestions.forEach(function (item, i) {
			var div = document.createElement('div');
			div.className = 'af-suggestion-item';
			div.textContent = item.display_name;
			div.addEventListener('click', function () {
				selectSuggestion(item);
			});
			div.addEventListener('mouseenter', function () {
				activeIndex = i;
				highlightSuggestion();
			});
			suggestionsEl.appendChild(div);
		});
	}

	function selectSuggestion(item) {
		var lat = parseFloat(item.lat);
		var lng = parseFloat(item.lon);

		updateCoords(lat, lng);
		marker.setLatLng([lat, lng]);
		map.setView([lat, lng], 16);

		fillAddressFields(item);
		closeSuggestions();
		searchInput.value = item.display_name;
	}

	function fillAddressFields(item) {
		var addr = item.address || {};

		// Full address for frontend display.
		var parts = [];
		var road = addr.road || addr.pedestrian || addr.footway || '';
		var number = addr.house_number || '';
		if (road) parts.push(road + (number ? ' ' + number : ''));
		var suburb = addr.suburb || addr.neighbourhood || addr.quarter || '';
		if (suburb) parts.push(suburb);
		var city = addr.city || addr.town || addr.village || addr.municipality || '';
		if (city) parts.push(city);
		var state = addr.state || addr.province || '';
		if (state) parts.push(state);

		var fullAddress = parts.length ? parts.join(', ') : item.display_name;

		var locationText = city + (suburb ? ', ' + suburb : '');

		if (addressInput) addressInput.value = fullAddress;
		if (cityInput) cityInput.value = city;
		if (locationTextInput) locationTextInput.value = locationText;

		setAddressFieldsDisabled(false);
	}

	function reverseGeocode(lat, lng) {
		var url = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&addressdetails=1';

		fetch(url, { headers: { 'Accept-Language': 'es' } })
			.then(function (r) { return r.json(); })
			.then(function (result) {
				if (result && result.address) {
					fillAddressFields(result);
					searchInput.value = result.display_name || '';
				}
			})
			.catch(function () {});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
