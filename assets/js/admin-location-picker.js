(function () {
	'use strict';

	var config = window.afLocationPicker || {};
	var defaultLat = config.defaultLat || -0.1807;
	var defaultLng = config.defaultLng || -78.4678;
	var bounds = config.ecuadorBounds || { latMin: -5.1, latMax: 1.7, lngMin: -92.2, lngMax: -75 };

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

		var startLat = hasSavedCoords ? parseFloat(latInput.value) : defaultLat;
		var startLng = hasSavedCoords ? parseFloat(lngInput.value) : defaultLng;

		map = L.map('af-location-map', { keyboard: false, scrollWheelZoom: true }).setView([startLat, startLng], 14);

		// Prevent Leaflet from stealing focus and scrolling the page.
		var mapEl = document.getElementById('af-location-map');
		mapEl.removeAttribute('tabindex');

		// CARTO's free raster tiles now return an "API KEY REQUIRED" watermark, so use OSM tiles.
		L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			maxZoom: 19
		}).addTo(map);

		marker = L.marker([startLat, startLng], { draggable: true, title: 'Arrastra para ajustar la ubicación' }).addTo(map);
		marker.on('dragstart', function () {
			var hint = document.getElementById('af-map-hint');
			if (hint) hint.classList.add('is-hidden');
		});
		map.on('click', function (e) {
			if (!isInEcuador(e.latlng.lat, e.latlng.lng)) return;
			marker.setLatLng(e.latlng);
			updateCoords(e.latlng.lat, e.latlng.lng);
			reverseGeocode(e.latlng.lat, e.latlng.lng);
		});

		var lastValid = [startLat, startLng];
		marker.on('dragend', function () {
			var pos = marker.getLatLng();
			if (!isInEcuador(pos.lat, pos.lng)) {
				marker.setLatLng(lastValid);
				return;
			}
			lastValid = [pos.lat, pos.lng];
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

		// Wizard steps: re-measure when the step containing the map becomes visible.
		var stepEl = mapContainer.closest('.af-wizard__step');
		if (stepEl && window.MutationObserver) {
			new MutationObserver(function () {
				setTimeout(function () {
					map.invalidateSize();
					map.setView(marker.getLatLng(), map.getZoom());
				}, 50);
			}).observe(stepEl, { attributes: true, attributeFilter: ['class', 'style', 'hidden'] });
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

	function isEcuadorResult(r) {
		var code = r.address && r.address.country_code;
		if (code && code.toLowerCase() !== 'ec') return false;
		return isInEcuador(parseFloat(r.lat), parseFloat(r.lon));
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
			searchSeq++;
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
			} else if (suggestions.length === 1) {
				selectSuggestion(suggestions[0]);
			} else if (suggestions.length) {
				return;
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

	var searchSeq = 0;

	function stripAccents(t) {
		return t.normalize ? t.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : t;
	}

	function fetchJson(url, opts) {
		return fetch(url, opts).then(function (r) {
			if (!r.ok) throw new Error('http ' + r.status);
			return r.json();
		});
	}

	function nominatimQuery(q) {
		var c = map ? map.getCenter() : { lat: defaultLat, lng: defaultLng };
		var vb = [c.lng - 0.5, c.lat + 0.5, c.lng + 0.5, c.lat - 0.5].join(',');
		var url = 'https://nominatim.openstreetmap.org/search?format=json&countrycodes=ec&limit=8&addressdetails=1&viewbox=' + vb + '&q=' + encodeURIComponent(q);
		return fetchJson(url, { headers: { 'Accept-Language': 'es' } }).catch(function () { return []; });
	}

	// Photon (OSM-based) tolerates typos and partial names much better than Nominatim.
	function photonQuery(q) {
		var c = map ? map.getCenter() : { lat: defaultLat, lng: defaultLng };
		var url = 'https://photon.komoot.io/api/?limit=8&lat=' + c.lat + '&lon=' + c.lng + '&q=' + encodeURIComponent(q);
		return fetchJson(url).then(function (data) {
			return (data.features || []).map(function (f) {
				var p = f.properties || {};
				var label = [p.name || p.street, p.district || p.locality, p.city || p.county, p.state].filter(Boolean).join(', ');
				return {
					lat: f.geometry.coordinates[1],
					lon: f.geometry.coordinates[0],
					display_name: label + ', Ecuador',
					address: {
						road: p.street || p.name,
						house_number: p.housenumber,
						suburb: p.district || p.locality,
						city: p.city || p.county,
						state: p.state,
						country_code: (p.countrycode || '').toLowerCase()
					}
				};
			});
		}).catch(function () { return []; });
	}

	function searchBoth(q) {
		return Promise.all([nominatimQuery(q), photonQuery(q)]).then(function (res) {
			var seen = {};
			return res[0].concat(res[1]).filter(function (r) {
				if (!isEcuadorResult(r)) return false;
				var key = parseFloat(r.lat).toFixed(4) + ',' + parseFloat(r.lon).toFixed(4);
				if (seen[key]) return false;
				seen[key] = true;
				return true;
			});
		});
	}

	function distKm(a, b) {
		var dx = (a.lon - b.lon) * 111 * Math.cos(a.lat * Math.PI / 180);
		var dy = (a.lat - b.lat) * 111;
		return Math.sqrt(dx * dx + dy * dy);
	}

	// "Calle A y Calle B": find A and B, then pick the closest pair as the intersection.
	function searchIntersection(partA, partB) {
		return Promise.all([searchBoth(partA), searchBoth(partB)]).then(function (res) {
			var best = null;
			res[0].forEach(function (x) {
				var xp = { lat: parseFloat(x.lat), lon: parseFloat(x.lon) };
				res[1].forEach(function (y) {
					var d = distKm(xp, { lat: parseFloat(y.lat), lon: parseFloat(y.lon) });
					if (d < 1.5 && (!best || d < best.d)) best = { d: d, x: x, y: y };
				});
			});
			if (!best) return [];
			var r = JSON.parse(JSON.stringify(best.x));
			r.lat = (parseFloat(best.x.lat) + parseFloat(best.y.lat)) / 2;
			r.lon = (parseFloat(best.x.lon) + parseFloat(best.y.lon)) / 2;
			var road = (best.x.address && best.x.address.road) || partA;
			var other = (best.y.address && best.y.address.road) || partB;
			r.address = r.address || {};
			r.address.road = road + ' y ' + other;
			r.display_name = road + ' y ' + other + ', ' + (best.x.display_name || '').split(',').slice(1).join(',').trim();
			return [r];
		});
	}

	function searchNominatim(query, autoSelect) {
		var seq = ++searchSeq;
		var clean = stripAccents(query).replace(/\s+/g, ' ').trim();
		var parts = clean.split(/\s+(?:y|e|&|esq\.?|esquina)\s+/i);
		var steps = [];

		if (parts.length === 2 && parts[0] && parts[1]) {
			steps.push(function () { return searchIntersection(parts[0], parts[1]); });
		}
		steps.push(function () { return searchBoth(query); });
		if (!/ecuador/i.test(query)) {
			steps.push(function () { return searchBoth(query + ', Ecuador'); });
		}
		if (parts.length === 2) {
			steps.push(function () { return searchBoth(parts[0]); });
		}

		var i = 0;
		function next() {
			if (seq !== searchSeq) return;
			if (i >= steps.length) {
				closeSuggestions();
				showNoResults();
				return;
			}
			steps[i++]().then(function (results) {
				if (seq !== searchSeq) return;
				if (results.length) {
					suggestions = results.slice(0, 6);
					suggestionsEl.innerHTML = '';
					if (autoSelect && suggestions.length === 1) {
						selectSuggestion(suggestions[0]);
					} else {
						renderSuggestions();
					}
				} else {
					next();
				}
			}).catch(next);
		}
		next();
	}

	function showNoResults() {
		var div = document.createElement('div');
		div.className = 'af-suggestion-item';
		div.textContent = 'No se encontró la ubicación en Ecuador. Añada ciudad/sector (ej. "La Regina, Quito"), pegue un enlace de Google Maps o mueva el marcador en el mapa.';
		suggestionsEl.appendChild(div);
		setAddressFieldsDisabled(false);
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
		searchSeq++;
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
				if (result && result.address && isEcuadorResult(result)) {
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
