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
		var select = document.getElementById('af-chatbot-select');
		var submit = document.getElementById('af-chatbot-submit');
		var message = document.getElementById('af-chatbot-message');
		var messages = document.getElementById('af-chatbot-messages');

		if (!toggle || !panel || !form || !input || !select || !submit || !messages || !window.afChatbot) {
			return;
		}

		var accommodations = Array.isArray(afChatbot.accommodations) ? afChatbot.accommodations : [];
		var currentAccommodationId = parseInt(afChatbot.currentAccommodationId || 0, 10);

		var state = {
			started: false,
			collecting: false,
			currentStep: 0,
			values: {}
		};

		var menuOptions = [
			{ key: 'rent', label: 'Arrendar una habitacion' },
			{ key: 'availability', label: 'Consultar disponibilidad' },
			{ key: 'advisor', label: 'Hablar con un asesor' }
		];

		var steps = [
			{
				key: 'accommodation_id',
				type: 'select',
				question: 'Selecciona la accommodation que te interesa.',
				validate: function (value) {
					var parsed = parseInt(value, 10);
					return !isNaN(parsed) && parsed > 0;
				},
				error: 'Selecciona una accommodation valida.'
			},
			{
				key: 'name',
				type: 'text',
				question: 'Para comenzar, cual es tu nombre completo?',
				placeholder: 'Ejemplo: Juan Perez',
				validate: function (value) {
					return value.length >= 3;
				},
				error: 'Escribe tu nombre completo.'
			},
			{
				key: 'email',
				type: 'text',
				question: 'Cual es tu correo electronico?',
				placeholder: 'correo@dominio.com',
				validate: function (value) {
					return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
				},
				error: 'Ingresa un correo valido.'
			},
			{
				key: 'phone',
				type: 'text',
				question: 'Cual es tu telefono? (solo numeros, max 10)',
				placeholder: '0991234567',
				validate: function (value) {
					return /^[0-9]{1,10}$/.test(value);
				},
				error: 'El telefono debe tener solo numeros y maximo 10 digitos.'
			},
			{
				key: 'id_number',
				type: 'text',
				question: 'Cual es tu numero de documento? (solo numeros, max 10)',
				placeholder: '1723456789',
				validate: function (value) {
					return /^[0-9]{1,10}$/.test(value);
				},
				error: 'El documento debe tener solo numeros y maximo 10 digitos.'
			},
			{
				key: 'mascotas',
				type: 'text',
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
				type: 'text',
				question: 'Comparte tu primera referencia personal.',
				placeholder: 'Nombre de referencia 1',
				validate: function (value) {
					return value.length >= 3;
				},
				error: 'Ingresa una referencia valida.'
			},
			{
				key: 'referencia_personal_2',
				type: 'text',
				question: 'Ahora tu segunda referencia personal.',
				placeholder: 'Nombre de referencia 2',
				validate: function (value) {
					return value.length >= 3;
				},
				error: 'Ingresa una segunda referencia valida.'
			},
			{
				key: 'personas_viviran',
				type: 'text',
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

		function botReply(text, callback) {
			appendBubble(text, 'bot');
			if (typeof callback === 'function') {
				callback();
			}
		}

		function setFormDisabled(disabled) {
			submit.disabled = disabled;
			submit.textContent = disabled ? (afChatbot.sendingText || 'Enviando...') : (afChatbot.buttonText || 'Enviar');
			input.disabled = disabled;
			select.disabled = disabled;
		}

		function showComposer(type) {
			if ('select' === type) {
				input.setAttribute('hidden', 'hidden');
				select.removeAttribute('hidden');
				select.focus();
				return;
			}

			select.setAttribute('hidden', 'hidden');
			input.removeAttribute('hidden');
			input.focus();
		}

		function showForm(visible) {
			if (visible) {
				form.removeAttribute('hidden');
				return;
			}
			form.setAttribute('hidden', 'hidden');
		}

		function appendOptions() {
			var wrap = document.createElement('div');
			wrap.className = 'af-chatbot-options';

			menuOptions.forEach(function (option) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'af-chatbot-option-btn';
				btn.setAttribute('data-option', option.key);
				btn.textContent = option.label;
				wrap.appendChild(btn);
			});

			messages.appendChild(wrap);
			scrollMessagesBottom();
		}

		function fillAccommodationSelect() {
			select.innerHTML = '';

			var placeholder = document.createElement('option');
			placeholder.value = '';
			placeholder.textContent = 'Selecciona una accommodation';
			select.appendChild(placeholder);

			accommodations.forEach(function (item) {
				var option = document.createElement('option');
				option.value = String(item.id);
				option.textContent = item.title;
				select.appendChild(option);
			});

			if (currentAccommodationId > 0) {
				select.value = String(currentAccommodationId);
			}
		}

		function getStepValue(step) {
			if ('select' === step.type) {
				return (select.value || '').trim();
			}
			return (input.value || '').trim();
		}

		function getStepLabel(step, value) {
			if ('select' !== step.type) {
				return value;
			}

			var selected = select.options[select.selectedIndex];
			return selected ? selected.text : value;
		}

		function askCurrentStep() {
			var step = steps[state.currentStep];
			if (!step) {
				return;
			}

			botReply(step.question, function () {
				showComposer(step.type);
				if ('text' === step.type) {
					input.placeholder = step.placeholder || 'Escribe tu respuesta';
					input.value = '';
				}
			});
		}

		function startConversation() {
			if (state.started) {
				if (state.collecting) {
					var currentStep = steps[state.currentStep];
					showComposer(currentStep ? currentStep.type : 'text');
				}
				return;
			}

			state.started = true;
			state.collecting = false;
			state.currentStep = 0;
			state.values = {};
			messages.innerHTML = '';
			message.textContent = '';
			message.className = '';
			fillAccommodationSelect();
			showForm(false);

			botReply(afChatbot.welcomeText || 'Bienvenid@ a Arriendo Facil, como podemos ayudarte?', function () {
				appendOptions();
			});
		}

		function handleMenuOption(key, label) {
			appendBubble(label, 'user');

			if ('rent' === key) {
				state.collecting = true;
				state.currentStep = 0;
				state.values = {};
				showForm(true);
				askCurrentStep();
				return;
			}

			if ('availability' === key) {
				botReply('Te ayudamos con la disponibilidad. Puedes contarme ciudad, zona o fecha, o elegir Arrendar una habitacion para registrar tus datos.', function () {
					appendOptions();
				});
				return;
			}

			botReply('Un asesor te contactara pronto. Si deseas, tambien puedes elegir Arrendar una habitacion para adelantar tu registro.', function () {
				appendOptions();
			});
		}

		function submitConversation() {
			var data = new FormData();
			data.append('action', 'af_create_guest_frontend');
			data.append('nonce', afChatbot.nonce);
			data.append('accommodation_id', state.values.accommodation_id || '');
			data.append('name', state.values.name || '');
			data.append('email', state.values.email || '');
			data.append('phone', state.values.phone || '');
			data.append('id_number', state.values.id_number || '');
			data.append('mascotas', state.values.mascotas || '0');
			data.append('referencia_personal_1', state.values.referencia_personal_1 || '');
			data.append('referencia_personal_2', state.values.referencia_personal_2 || '');
			data.append('personas_viviran', state.values.personas_viviran || '1');

			setFormDisabled(true);
			botReply(afChatbot.doneText || 'Perfecto, ya tengo tus datos. Estoy registrando tu solicitud...');

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
						state.collecting = false;
						state.currentStep = 0;
						state.values = {};
						input.value = '';
						select.value = '';
						showForm(false);
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

			if (!state.collecting) {
				botReply('Elige una opcion para continuar.', function () {
					appendOptions();
				});
				return;
			}

			var step = steps[state.currentStep];
			if (!step) {
				return;
			}

			var value = getStepValue(step);
			if (!step.validate(value)) {
				message.className = 'af-chatbot-error';
				message.textContent = step.error;
				botReply(step.error);
				return;
			}

			message.textContent = '';
			message.className = '';
			state.values[step.key] = value;
			appendBubble(getStepLabel(step, value), 'user');
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

		messages.addEventListener('click', function (event) {
			var target = event.target;
			if (!target || !target.classList || !target.classList.contains('af-chatbot-option-btn')) {
				return;
			}

			var optionKey = target.getAttribute('data-option') || '';
			var optionLabel = target.textContent || '';
			handleMenuOption(optionKey, optionLabel);
		});

		document.addEventListener('click', function (event) {
			var widget = document.getElementById('af-chatbot-widget');
			if (!widget || panel.hasAttribute('hidden')) {
				return;
			}

			if (!widget.contains(event.target)) {
				panel.setAttribute('hidden', 'hidden');
				toggle.setAttribute('aria-expanded', 'false');
			}
		});
	});
})();
