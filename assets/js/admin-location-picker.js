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

		var startLat = parseFloat(latInput.value) || defaultLat;
		var startLng = parseFloat(lngInput.value) || defaultLng;

		map = L.map('af-location-map').setView([startLat, startLng], 14);

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

		setTimeout(function () {
			map.invalidateSize();
		}, 100);
		setTimeout(function () {
			map.invalidateSize();
		}, 500);
		setTimeout(function () {
			map.invalidateSize();
		}, 1500);

		// Fix for WordPress meta box toggle (open/close).
		jQuery(document).on('postbox-toggled', function () {
			setTimeout(function () { map.invalidateSize(); }, 200);
		});

		searchInput.addEventListener('input', onSearchInput);
		searchInput.addEventListener('keydown', onSearchKeydown);
		document.addEventListener('click', function (e) {
			if (!suggestionsEl.contains(e.target) && e.target !== searchInput) {
				closeSuggestions();
			}
		});
	}

	function isInEcuador(lat, lng) {
		return lat >= bounds.latMin && lat <= bounds.latMax &&
			lng >= bounds.lngMin && lng <= bounds.lngMax;
	}

	function updateCoords(lat, lng) {
		latInput.value = lat.toFixed(6);
		lngInput.value = lng.toFixed(6);
	}

	function onSearchInput() {
		var query = searchInput.value.trim();
		clearTimeout(debounceTimer);

		if (query.length < 3) {
			closeSuggestions();
			return;
		}

		debounceTimer = setTimeout(function () {
			searchNominatim(query);
		}, 350);
	}

	function onSearchKeydown(e) {
		if (!suggestions.length) return;

		if (e.key === 'ArrowDown') {
			e.preventDefault();
			activeIndex = Math.min(activeIndex + 1, suggestions.length - 1);
			highlightSuggestion();
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			activeIndex = Math.max(activeIndex - 1, 0);
			highlightSuggestion();
		} else if (e.key === 'Enter') {
			e.preventDefault();
			if (activeIndex >= 0 && activeIndex < suggestions.length) {
				selectSuggestion(suggestions[activeIndex]);
			}
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

	function searchNominatim(query) {
		var url = 'https://nominatim.openstreetmap.org/search?format=json&countrycodes=ec&limit=5&addressdetails=1&q=' + encodeURIComponent(query);

		fetch(url, { headers: { 'Accept-Language': 'es' } })
			.then(function (r) { return r.json(); })
			.then(function (results) {
				suggestions = results.filter(function (r) {
					return isInEcuador(parseFloat(r.lat), parseFloat(r.lon));
				});
				renderSuggestions();
			})
			.catch(function () {
				closeSuggestions();
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
		var road = addr.road || addr.pedestrian || addr.footway || '';
		var number = addr.house_number || '';
		var fullAddress = (road + (number ? ' ' + number : '')).trim() || item.display_name;

		var city = addr.city || addr.town || addr.village || addr.municipality || '';
		var suburb = addr.suburb || addr.neighbourhood || addr.quarter || '';
		var locationText = city + (suburb ? ', ' + suburb : '');

		if (addressInput) addressInput.value = fullAddress;
		if (cityInput) cityInput.value = city;
		if (locationTextInput) locationTextInput.value = locationText;
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
