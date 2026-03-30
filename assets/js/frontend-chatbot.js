(function () {
	'use strict';

	function onReady(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
			return;
		}
		fn();
	}

	onReady(function () {
		var toggle = document.getElementById('af-chatbot-toggle');
		var panel = document.getElementById('af-chatbot-panel');
		var form = document.getElementById('af-chatbot-form');
		var message = document.getElementById('af-chatbot-message');
		var submit = document.getElementById('af-chatbot-submit');

		if (!toggle || !panel || !form || !window.afChatbot) {
			return;
		}

		toggle.addEventListener('click', function () {
			var isHidden = panel.hasAttribute('hidden');
			if (isHidden) {
				panel.removeAttribute('hidden');
				toggle.setAttribute('aria-expanded', 'true');
			} else {
				panel.setAttribute('hidden', 'hidden');
				toggle.setAttribute('aria-expanded', 'false');
			}
		});

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var data = new FormData(form);
			data.append('action', 'af_create_guest_frontend');
			data.append('nonce', afChatbot.nonce);

			if (submit) {
				submit.disabled = true;
				submit.textContent = afChatbot.sendingText || 'Enviando...';
			}
			if (message) {
				message.textContent = '';
				message.className = '';
			}

			fetch(afChatbot.ajaxUrl, {
				method: 'POST',
				body: data,
				credentials: 'same-origin'
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (payload) {
					if (!message) {
						return;
					}

					if (payload && payload.success) {
						message.className = 'af-chatbot-success';
						message.textContent = (payload.data && payload.data.message) || afChatbot.successText;
						form.reset();
						return;
					}

					message.className = 'af-chatbot-error';
					message.textContent = (payload && payload.data && payload.data.message) || afChatbot.errorText;
				})
				.catch(function () {
					if (message) {
						message.className = 'af-chatbot-error';
						message.textContent = afChatbot.errorText || 'No se pudo enviar el registro.';
					}
				})
				.finally(function () {
					if (submit) {
						submit.disabled = false;
						submit.textContent = afChatbot.buttonText || 'Enviar';
					}
				});
		});
	});
})();
