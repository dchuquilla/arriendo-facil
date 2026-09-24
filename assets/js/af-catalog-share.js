/**
 * Shareable catalog admin widget (catalog screen).
 */
(function () {
	'use strict';

	var config = window.afCatalogShare;
	if (!config || !config.ajaxUrl) { return; }

	var card = document.getElementById('af-share-card');
	if (!card) { return; }

	var urlInput = document.getElementById('af-share-url');
	var actions = document.getElementById('af-share-actions');
	var emptyState = document.getElementById('af-share-empty');
	var hint = document.getElementById('af-share-hint');
	var status = document.getElementById('af-share-status');

	function post(action, payload) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', config.nonce);
		Object.keys(payload || {}).forEach(function (key) {
			body.append(key, payload[key]);
		});

		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then(function (r) { return r.json(); });
	}

	function setMessage(text, isError) {
		if (!status) { return; }
		status.textContent = text || '';
		status.className = 'af-share-status' + (isError ? ' is-error' : ' is-success');
	}

	var timer = null;
	function flash(text, isError) {
		setMessage(text, isError);
		if (timer) { clearTimeout(timer); }
		timer = setTimeout(function () { setMessage('', false); }, 4000);
	}

	function showUrl(url) {
		if (urlInput && url) {
			urlInput.value = url;
			card.classList.remove('is-hidden');
			if (actions) { actions.hidden = false; }
			if (emptyState) { emptyState.hidden = true; }
			if (hint) { hint.hidden = false; }
		}
	}

	function showEmpty() {
		card.classList.remove('is-hidden');
		if (urlInput) { urlInput.value = ''; }
		if (actions) { actions.hidden = true; }
		if (emptyState) { emptyState.hidden = false; }
		if (hint) { hint.hidden = true; }
	}

	if (urlInput && !urlInput.value.trim()) {
		showEmpty();
	}

	document.getElementById('af-share-generate').addEventListener('click', function (btn) {
		btn.disabled = true;
		post('af_catalog_share_generate', { rotate: '0' }).then(function (json) {
			btn.disabled = false;
			if (!json || !json.success) {
				flash((json && json.data && json.data.message) || 'Error', true);
				return;
			}
			showUrl(json.data.url);
			flash(json.data.message, false);
		});
	});

	document.getElementById('af-share-rotate').addEventListener('click', function (btn) {
		if (!window.confirm('¿Regenerar el enlace? El enlace actual dejará de funcionar.')) { return; }
		btn.disabled = true;
		post('af_catalog_share_generate', { rotate: '1' }).then(function (json) {
			btn.disabled = false;
			if (!json || !json.success) {
				flash((json && json.data && json.data.message) || 'Error', true);
				return;
			}
			showUrl(json.data.url);
			flash(json.data.message, false);
		});
	});

	document.getElementById('af-share-copy').addEventListener('click', function () {
		if (!urlInput || !urlInput.value) { return; }
		var value = urlInput.value;

		function fallback() {
			urlInput.focus();
			urlInput.select();
			try {
				document.execCommand('copy');
				flash(config.i18n.copied, false);
			} catch (e) {
				flash(value, false);
			}
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(value).then(function () {
				flash(config.i18n.copied, false);
			}, fallback);
		} else {
			fallback();
		}
	});

	document.getElementById('af-share-revoke').addEventListener('click', function (btn) {
		if (!window.confirm('¿Desactivar el enlace compartido? Dejará de ser accesible.')) { return; }
		btn.disabled = true;
		post('af_catalog_share_revoke', {}).then(function (json) {
			btn.disabled = false;
			if (!json || !json.success) {
				flash((json && json.data && json.data.message) || 'Error', true);
				return;
			}
			showEmpty();
			flash(json.data.message, false);
		});
	});

	if (document.getElementById('af-share-preview')) {
		document.getElementById('af-share-preview').addEventListener('click', function () {
			if (urlInput && urlInput.value) {
				window.open(urlInput.value, '_blank', 'noopener');
			}
		});
	}
}());