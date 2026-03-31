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
		var input = document.getElementById('af-chatbot-input');
		var submit = document.getElementById('af-chatbot-submit');
		var message = document.getElementById('af-chatbot-message');
		var messages = document.getElementById('af-chatbot-messages');
		var typing = document.getElementById('af-chatbot-typing');

		if (!toggle || !panel || !form || !input || !submit || !messages || !typing || !window.afChatbot) {
			return;
		}

		var state = {
			started: false,
			currentStep: 0,
			values: {}
		};

		var steps = [
			{
				key: 'name',
				question: 'Para comenzar, cual es tu nombre completo?',
				placeholder: 'Ejemplo: Juan Perez',
				validate: function (value) {
					return value.length >= 3;
				},
				error: 'Escribe tu nombre completo.'
			},
			{
				key: 'email',
				question: 'Cual es tu correo electronico?',
				placeholder: 'correo@dominio.com',
				validate: function (value) {
					return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
				},
				error: 'Ingresa un correo valido.'
			},
			{
				key: 'phone',
				question: 'Cual es tu telefono? (solo numeros, max 10)',
				placeholder: '0991234567',
				validate: function (value) {
					return /^[0-9]{1,10}$/.test(value);
				},
				error: 'El telefono debe tener solo numeros y maximo 10 digitos.'
			},
			{
				key: 'id_number',
				question: 'Cual es tu numero de documento? (solo numeros, max 10)',
				placeholder: '1723456789',
				validate: function (value) {
					return /^[0-9]{1,10}$/.test(value);
				},
				error: 'El documento debe tener solo numeros y maximo 10 digitos.'
			},
			{
				key: 'mascotas',
				question: 'Cuantas mascotas tienes? (0 a 10)',
				placeholder: '0',
				validate: function (value) {
					var num = parseInt(value, 10);
					return !isNaN(num) && num >= 0 && num <= 10;
				},
				error: 'Mascotas debe estar entre 0 y 10.'
			},
			{
				key: 'referencia_personal_1',
				question: 'Comparte tu primera referencia personal.',
				placeholder: 'Nombre de referencia 1',
				validate: function (value) {
					return value.length >= 3;
				},
				error: 'Ingresa una referencia valida.'
			},
			{
				key: 'referencia_personal_2',
				question: 'Ahora tu segunda referencia personal.',
				placeholder: 'Nombre de referencia 2',
				validate: function (value) {
					return value.length >= 3;
				},
				error: 'Ingresa una segunda referencia valida.'
			},
			{
				key: 'personas_viviran',
				question: 'Cuantas personas viviran en la propiedad? (1 a 10)',
				placeholder: '2',
				validate: function (value) {
					var num = parseInt(value, 10);
					return !isNaN(num) && num >= 1 && num <= 10;
				},
				error: 'Personas debe estar entre 1 y 10.'
			}
		];

		function scrollMessagesBottom() {
			messages.scrollTop = messages.scrollHeight;
		}

		function appendBubble(text, type) {
			var bubble = document.createElement('div');
			bubble.className = 'af-chatbot-bubble af-chatbot-bubble-' + type;
			bubble.textContent = text;
			messages.appendChild(bubble);
			scrollMessagesBottom();
		}

		function setTyping(visible) {
			if (visible) {
				typing.removeAttribute('hidden');
			} else {
				typing.setAttribute('hidden', 'hidden');
			}
			scrollMessagesBottom();
		}

		function setFormDisabled(disabled) {
			submit.disabled = disabled;
			submit.textContent = disabled ? (afChatbot.sendingText || 'Enviando...') : (afChatbot.buttonText || 'Enviar');
			input.disabled = disabled;
		}

		function askCurrentStep() {
			var step = steps[state.currentStep];
			if (!step) {
				return;
			}

			setTyping(true);
			window.setTimeout(function () {
				setTyping(false);
				appendBubble(step.question, 'bot');
				input.placeholder = step.placeholder;
				input.value = '';
				input.focus();
			}, 700);
		}

		function startConversation() {
			if (state.started) {
				input.focus();
				return;
			}

			state.started = true;
			state.currentStep = 0;
			state.values = {};
			messages.innerHTML = '';
			message.textContent = '';
			message.className = '';

			setTyping(true);
			window.setTimeout(function () {
				setTyping(false);
				appendBubble(afChatbot.welcomeText || 'Hola, te ayudare a registrar tus datos.', 'bot');
				askCurrentStep();
			}, 600);
		}

		function submitConversation() {
			var data = new FormData();
			data.append('action', 'af_create_guest_frontend');
			data.append('nonce', afChatbot.nonce);
			data.append('name', state.values.name || '');
			data.append('email', state.values.email || '');
			data.append('phone', state.values.phone || '');
			data.append('id_number', state.values.id_number || '');
			data.append('mascotas', state.values.mascotas || '0');
			data.append('referencia_personal_1', state.values.referencia_personal_1 || '');
			data.append('referencia_personal_2', state.values.referencia_personal_2 || '');
			data.append('personas_viviran', state.values.personas_viviran || '1');

			appendBubble(afChatbot.doneText || 'Perfecto, ya tengo tus datos. Estoy registrando tu solicitud...', 'bot');
			setFormDisabled(true);

			fetch(afChatbot.ajaxUrl, {
				method: 'POST',
				body: data,
				credentials: 'same-origin'
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (payload) {
					if (payload && payload.success) {
						message.className = 'af-chatbot-success';
						message.textContent = (payload.data && payload.data.message) || afChatbot.successText;
						appendBubble(message.textContent, 'bot');
						state.started = false;
						state.currentStep = 0;
						state.values = {};
						input.value = '';
						input.placeholder = 'Escribe tu respuesta';
						return;
					}

					message.className = 'af-chatbot-error';
					message.textContent = (payload && payload.data && payload.data.message) || afChatbot.errorText;
					appendBubble(message.textContent, 'bot');
				})
				.catch(function () {
					message.className = 'af-chatbot-error';
					message.textContent = afChatbot.errorText || 'No se pudo enviar el registro.';
					appendBubble(message.textContent, 'bot');
				})
				.finally(function () {
					setFormDisabled(false);
					input.focus();
				});
		}

		toggle.addEventListener('click', function () {
			var isHidden = panel.hasAttribute('hidden');
			if (isHidden) {
				panel.removeAttribute('hidden');
				toggle.setAttribute('aria-expanded', 'true');
				startConversation();
			} else {
				panel.setAttribute('hidden', 'hidden');
				toggle.setAttribute('aria-expanded', 'false');
			}
		});

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			if (!state.started) {
				startConversation();
				return;
			}

			var step = steps[state.currentStep];
			if (!step) {
				return;
			}

			var value = (input.value || '').trim();
			if (!step.validate(value)) {
				message.className = 'af-chatbot-error';
				message.textContent = step.error;
				appendBubble(step.error, 'bot');
				input.focus();
				return;
			}

			message.textContent = '';
			message.className = '';
			state.values[step.key] = value;
			appendBubble(value, 'user');
			state.currentStep += 1;

			if (state.currentStep >= steps.length) {
				submitConversation();
				return;
			}

			askCurrentStep();
		});

		input.addEventListener('keydown', function (event) {
			if ('Escape' === event.key) {
				panel.setAttribute('hidden', 'hidden');
				toggle.setAttribute('aria-expanded', 'false');
			}
		});
	});
})();
