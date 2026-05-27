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
			var formControls = null;
			var backButton = null;
			var cancelButton = null;
		var message = document.getElementById('af-chatbot-message');
		var messages = document.getElementById('af-chatbot-messages');

		if (!toggle || !panel || !form || !input || !select || !submit || !message || !messages || !window.afChatbot) {
			return;
		}

		var accommodations = Array.isArray(afChatbot.accommodations) ? afChatbot.accommodations : [];
		var currentAccommodationId = parseInt(afChatbot.currentAccommodationId || 0, 10);

		var state = {
			started: false,
			collecting: false,
			currentStep: 0,
			values: {},
			pendingExistingChoice: null,
				lastSubmitFailed: false,
				isSubmitting: false,
				activeRequestController: null
		};

		var menuOptions = [];

		var steps = [
			{
				key: 'name',
				type: 'text',
				question: '\u00bfPara comenzar, cu\u00e1l es tu nombre completo?',
				placeholder: 'Ejemplo: Juan P\u00e9rez',
				validate: function (value) { return value.length >= 3; },
				error: 'Escribe tu nombre completo (m\u00ednimo 3 caracteres).'
			},
			{
				key: 'email',
				type: 'text',
				question: '\u00bfCu\u00e1l es tu correo electr\u00f3nico? Lo usaremos para enviarte informaci\u00f3n del arriendo.',
				placeholder: 'correo@dominio.com',
				validate: function (value) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value); },
				error: 'Ingresa un correo v\u00e1lido.'
			},
			{
				key: 'phone',
				type: 'text',
				question: '\u00bfCu\u00e1l es tu n\u00famero de celular? (10 d\u00edgitos, ejemplo: 0991234567)',
				placeholder: '0991234567',
				validate: function (value) { return /^[0-9]{10}$/.test(value); },
				error: 'El tel\u00e9fono debe tener exactamente 10 d\u00edgitos num\u00e9ricos.'
			},
			{
				key: 'id_number',
				type: 'text',
				question: '\u00bfCu\u00e1l es tu n\u00famero de c\u00e9dula? (10 d\u00edgitos)',
				placeholder: '1723456789',
				validate: function (value) { return /^[0-9]{10}$/.test(value); },
				error: 'La c\u00e9dula debe tener exactamente 10 d\u00edgitos num\u00e9ricos.'
			},
			{
				key: 'personas_viviran',
				type: 'text',
				question: 'Cont\u00e1ndote a ti, \u00bfcu\u00e1ntas personas vivir\u00edan en la propiedad? (1 a 10)',
				placeholder: '2',
				validate: function (value) {
					var num = parseInt(value, 10);
					return !isNaN(num) && num >= 1 && num <= 10;
				},
				error: 'Personas debe estar entre 1 y 10.'
			},
			{
				key: 'accommodation_id',
				type: 'select',
				question: 'Selecciona la propiedad que te interesa arrendar.',
				shouldAsk: function () { return true; },
				options: function () {
					var opts = [{ value: '', label: 'Selecciona una opci\u00f3n' }];
					accommodations.forEach(function (item) {
						opts.push({ value: String(item.id), label: item.title });
					});
					return opts;
				},
				defaultValue: function () {
					return currentAccommodationId > 0 ? String(currentAccommodationId) : '';
				},
				validate: function (value) {
					var parsed = parseInt(value, 10);
					return !isNaN(parsed) && parsed > 0;
				},
				error: 'Selecciona una propiedad v\u00e1lida.'
			},
			{
				key: 'mascotas',
				type: 'text',
				question: '\u00bfCu\u00e1ntas mascotas tendr\u00edas en la propiedad? (escribe 0 si ninguna)',
				placeholder: '0',
				shouldAsk: function () {
					var selectedId = parseInt(state.values.accommodation_id, 10);
					var selected = accommodations.find(function (a) { return a.id === selectedId; });
					if (selected && !selected.petFriendly) {
						state.values.mascotas = '0';
						return false;
					}
					return true;
				},
				skipMessage: 'La propiedad seleccionada no permite mascotas. Se registrar\u00e1 0 mascotas.',
				validate: function (value) {
					var num = parseInt(value, 10);
					return !isNaN(num) && num >= 0 && num <= 10;
				},
				error: 'Mascotas debe estar entre 0 y 10.'
			},
			{
				key: 'visit_preferred_date',
				type: 'date',
				question: 'Que fecha prefieres para que te atiendan la visita de la propiedad?',
				validate: function (value) {
					if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) { return false; }
					var selected = new Date(value + 'T00:00:00');
					var today = new Date();
					today.setHours(0, 0, 0, 0);
					return selected >= today;
				},
				error: 'Selecciona una fecha valida para la visita (hoy o en el futuro).'
			},
			{
				key: 'visit_preferred_time',
				type: 'text',
				question: 'Que hora prefieres para la visita? (09:00 a 18:00, formato HH:MM)',
				placeholder: '10:30',
				validate: function (value) {
					var match = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(value);
					if (!match) { return false; }
					var hour = parseInt(match[1], 10);
					var minute = parseInt(match[2], 10);
					var total = (hour * 60) + minute;
					return total >= 540 && total <= 1080;
				},
				error: 'La hora sugerida debe estar entre 09:00 y 18:00.'
			},
			{
				key: 'visit_notes',
				type: 'text',
				question: 'Agrega un comentario corto para el propietario (opcional). Si no tienes, escribe "ninguna".',
				placeholder: 'Ejemplo: Me sirve mejor luego de las 5pm',
				validate: function () { return true; },
				error: ''
			},
			{
				key: 'rental_start_date',
				type: 'date',
				question: 'Selecciona la fecha en la que te gustar\u00eda iniciar el arriendo.',
				validate: function (value) {
					if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) { return false; }
					var selected = new Date(value + 'T00:00:00');
					var today = new Date();
					today.setHours(0, 0, 0, 0);
					return selected >= today;
				},
				error: 'Selecciona una fecha v\u00e1lida (debe ser hoy o en el futuro).'
			},
			{
				key: 'rental_years',
				type: 'text',
				question: '\u00bfPor cu\u00e1ntos a\u00f1os deseas arrendar? (m\u00ednimo 1 a\u00f1o)',
				placeholder: '1',
				validate: function (value) {
					var num = parseInt(value, 10);
					return !isNaN(num) && num >= 1 && num <= 20;
				},
				error: 'La duraci\u00f3n m\u00ednima es 1 a\u00f1o y la m\u00e1xima es 20 a\u00f1os.'
			}
		];

			function normalizeText(value) {
				return String(value || '').toLowerCase().trim().replace(/\s+/g, ' ');
			}

			function normalizePhoneDigits(value) {
				return String(value || '').replace(/\D+/g, '');
			}

			function normalizeCommand(value) {
				return String(value || '').toLowerCase().trim();
			}

			function isBackCommand(value) {
				var command = normalizeCommand(value);
				return command === 'volver' || command === 'atras' || command === 'regresar';
			}

			function isCancelCommand(value) {
				var command = normalizeCommand(value);
				return command === 'cancelar' || command === 'cancel' || command === 'salir' || command === 'reiniciar';
			}

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
				if (backButton) {
					backButton.disabled = disabled;
				}
				if (cancelButton) {
					cancelButton.disabled = false;
					cancelButton.textContent = state.isSubmitting ? 'Cancelar envio' : 'Cancelar';
				}
		}

			function ensureFormControls() {
				if (formControls) {
					return;
				}

				formControls = document.createElement('div');
				formControls.className = 'af-chatbot-form-controls';

				backButton = document.createElement('button');
				backButton.type = 'button';
				backButton.id = 'af-chatbot-back';
				backButton.className = 'af-chatbot-secondary';
				backButton.textContent = 'Volver';

				cancelButton = document.createElement('button');
				cancelButton.type = 'button';
				cancelButton.id = 'af-chatbot-cancel';
				cancelButton.className = 'af-chatbot-secondary';
				cancelButton.textContent = 'Cancelar';

				formControls.appendChild(backButton);
				formControls.appendChild(cancelButton);
				form.insertAdjacentElement('afterend', formControls);

				backButton.addEventListener('click', function () {
					goToPreviousStep();
				});

				cancelButton.addEventListener('click', function () {
					cancelCurrentOperation();
				});
			}

		function showComposer(type) {
			if ('select' === type) {
				input.setAttribute('hidden', 'hidden');
				select.removeAttribute('hidden');
				input.type = 'text';
				select.focus();
				return;
			}
			select.setAttribute('hidden', 'hidden');
			input.removeAttribute('hidden');
			if ('date' === type) {
				input.type = 'date';
				var today = new Date();
				input.min = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
				input.value = '';
			} else {
				input.type = 'text';
				input.removeAttribute('min');
			}
			input.focus();
		}

		function showForm(visible) {
				ensureFormControls();
			if (visible) {
				form.removeAttribute('hidden');
					formControls.removeAttribute('hidden');
				return;
			}
			form.setAttribute('hidden', 'hidden');
				formControls.setAttribute('hidden', 'hidden');
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

		function renderExistingGuestChoice(existing) {
			state.pendingExistingChoice = existing || null;
			showForm(false);

			var summary = [
				'Se encontro una solicitud previa con este correo.',
				'Datos enmascarados encontrados:',
				'- Nombre: ' + ((existing && existing.name) ? existing.name : '****'),
				'- Correo: ' + ((existing && existing.email) ? existing.email : '****'),
				'- Celular: ' + ((existing && existing.phone) ? existing.phone : '****')
			].join('\n');

			botReply(summary);

			var wrap = document.createElement('div');
			wrap.className = 'af-chatbot-options';

			var reuseButton = document.createElement('button');
			reuseButton.type = 'button';
			reuseButton.className = 'af-chatbot-option-btn af-chatbot-existing-choice';
			reuseButton.setAttribute('data-existing-choice', 'reuse');
			reuseButton.textContent = 'Si, continuar con la informacion existente';

			var refreshButton = document.createElement('button');
			refreshButton.type = 'button';
			refreshButton.className = 'af-chatbot-option-btn af-chatbot-existing-choice';
			refreshButton.setAttribute('data-existing-choice', 'refresh');
			refreshButton.textContent = 'No, continuar con un proceso nuevo';

			wrap.appendChild(reuseButton);
			wrap.appendChild(refreshButton);
			messages.appendChild(wrap);
			scrollMessagesBottom();
		}

		function resolveExistingGuestChoice(choice) {
			if (!state.pendingExistingChoice) {
				return;
			}

			if ('reuse' === choice) {
				state.values.existing_mode = 'reuse';
				appendBubble('Si, continuar con la informacion existente', 'user');
				botReply('Perfecto. Usaremos tus datos ya registrados para continuar el proceso.');
			} else {
				state.values.existing_mode = 'refresh';
				appendBubble('No, continuar con un proceso nuevo', 'user');
				botReply('Perfecto. Guardaremos los datos nuevos para este proceso.');
			}

			state.pendingExistingChoice = null;
			showForm(true);
			submitConversation();
		}

		function fillSelectOptions(options, defaultValue) {
			select.innerHTML = '';
			options.forEach(function (opt) {
				var option = document.createElement('option');
				option.value = String(opt.value);
				option.textContent = opt.label;
				select.appendChild(option);
			});
			if (typeof defaultValue === 'string') {
				select.value = defaultValue;
			}
		}

		function getCurrentStep() {
			while (state.currentStep < steps.length) {
				var step = steps[state.currentStep];
				if (!step.shouldAsk || step.shouldAsk(state.values)) {
					return step;
				}
				if (step.skipMessage) {
					appendBubble(step.skipMessage, 'bot');
				}
				state.currentStep += 1;
			}
			return null;
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

		function parseAjaxPayload(rawText) {
			var text = String(rawText || '').trim();
			if (!text) {
				return null;
			}

			try {
				return JSON.parse(text);
			} catch (parseError) {
				// Continue with recovery attempts.
			}

			var firstBrace = text.indexOf('{');
			var lastBrace = text.lastIndexOf('}');
			if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
				var candidate = text.slice(firstBrace, lastBrace + 1);
				try {
					return JSON.parse(candidate);
				} catch (recoveredError) {
					// Keep trying below.
				}
			}

			return null;
		}

		function askCurrentStep() {
			var step = getCurrentStep();
			if (!step) {
				submitConversation();
				return;
			}

			botReply(step.question, function () {
				showComposer(step.type);
					message.className = 'af-chatbot-hint';
					message.textContent = step.helperText ? step.helperText : '';
				if ('select' === step.type) {
					fillSelectOptions(step.options(), step.defaultValue ? step.defaultValue() : '');
				} else {
					input.placeholder = step.placeholder || 'Escribe tu respuesta';
					input.value = '';
				}
			});
		}

		function startConversation() {
			if (state.started) {
				return;
			}
			state.started = true;
			state.collecting = true;
			state.currentStep = 0;
			state.values = {};
			state.pendingExistingChoice = null;
			state.lastSubmitFailed = false;
			if (currentAccommodationId > 0) {
				state.values.accommodation_id = String(currentAccommodationId);
			}
			messages.innerHTML = '';
			message.textContent = '';
			message.className = '';
			showForm(true);
				botReply(afChatbot.welcomeText || 'Hola! Soy el asistente de Arriendo Facil.', function () {
					botReply('Estoy aqui para facilitar el proceso de arriendo entre tu y el propietario. Con tus datos podre generar el contrato de forma rapida y sencilla.', function () {
						botReply('Solo necesito algunos datos basicos. Si te equivocas en algo, puedes escribir "volver" para corregir o "cancelar" para empezar de nuevo.');
						askCurrentStep();
					});
				});
			}

			function resetCollectionState() {
				state.collecting = false;
				state.currentStep = 0;
				state.values = {};
				state.pendingExistingChoice = null;
				state.lastSubmitFailed = false;
				state.isSubmitting = false;
				state.activeRequestController = null;
				if (currentAccommodationId > 0) {
					state.values.accommodation_id = String(currentAccommodationId);
				}
			}

			function cancelCurrentOperation() {
				if (state.isSubmitting && state.activeRequestController) {
					state.activeRequestController.abort();
				}

				resetCollectionState();
				state.collecting = true;
				setFormDisabled(false);
				showForm(true);
				message.className = '';
				message.textContent = '';
				botReply('Operacion cancelada. Empecemos de nuevo.', function () {
					askCurrentStep();
				});
			}

			function clearDependentValues() {
			}

			function goToPreviousStep() {
				if (!state.collecting || state.isSubmitting) {
					botReply('No puedo regresar mientras se esta enviando. Usa "Cancelar envio" si deseas detenerlo.');
					return;
				}

				var fromIndex = state.currentStep;
				if (!getCurrentStep()) {
					fromIndex = steps.length;
				}

				var index = fromIndex - 1;
				while (index >= 0) {
					var candidate = steps[index];
					var wasAsked = !candidate.shouldAsk || candidate.shouldAsk(state.values);
					if (wasAsked && typeof state.values[candidate.key] !== 'undefined') {
						break;
					}
					index -= 1;
				}

				if (index < 0) {
					botReply('Estas en la primera pregunta. Si quieres, usa "Cancelar" para reiniciar.');
					return;
				}

				clearDependentValues(steps[index].key);
				delete state.values[steps[index].key];
				state.currentStep = index;
				botReply('Listo, regresamos un paso.');
				askCurrentStep();
			}

			function findFirstInvalidStep() {
				for (var i = 0; i < steps.length; i += 1) {
					var step = steps[i];
					if (step.shouldAsk && !step.shouldAsk(state.values)) {
						continue;
					}

					var value = state.values[step.key] || '';
					if (!step.validate(value, state.values)) {
						return { index: i, error: step.error };
					}
				}

				return null;
			}

			function handleInlineCommands(rawValue) {
				if (isCancelCommand(rawValue)) {
					appendBubble(rawValue, 'user');
					cancelCurrentOperation();
					return true;
				}

				if (isBackCommand(rawValue)) {
					appendBubble(rawValue, 'user');
					goToPreviousStep();
					return true;
				}

				return false;
			}

			function handleMenuOption(key, label) {
				appendBubble(label, 'user');
				state.collecting = true;
				state.currentStep = 0;
				state.values = {};
				if (currentAccommodationId > 0) {
					state.values.accommodation_id = String(currentAccommodationId);
				}
				showForm(true);
				botReply('Vamos paso a paso. Si te equivocas, puedes usar "Volver" o escribir "cancelar" para reiniciar.');
				askCurrentStep();
		}

		function submitConversation() {
				var validationError = findFirstInvalidStep();
				if (validationError) {
					state.currentStep = validationError.index;
					message.className = 'af-chatbot-error';
					message.textContent = validationError.error;
					botReply('Hay un dato pendiente o invalido. Regresaremos a esa pregunta para corregirlo.');
					askCurrentStep();
					return;
				}

			var data = new FormData();
			data.append('action', 'af_create_guest_frontend');
			data.append('name', state.values.name || '');
			data.append('email', state.values.email || '');
			data.append('phone', state.values.phone || '');
			data.append('id_number', state.values.id_number || '');
			data.append('mascotas', state.values.mascotas || '0');
			data.append('personas_viviran', state.values.personas_viviran || '1');
			data.append('accommodation_id', state.values.accommodation_id || '');
			data.append('visit_preferred_date', state.values.visit_preferred_date || '');
			data.append('visit_preferred_time', state.values.visit_preferred_time || '');
			data.append('visit_notes', state.values.visit_notes || '');
			data.append('rental_start_date', state.values.rental_start_date || '');
			data.append('rental_years', state.values.rental_years || '1');
			data.append('existing_mode', state.values.existing_mode || '');

			state.isSubmitting = true;
			state.activeRequestController = new AbortController();
			setFormDisabled(true);
			state.lastSubmitFailed = false;
			botReply(afChatbot.doneText || 'Perfecto, ya tengo tus datos. Estoy enviando tu solicitud de arriendo...');

			// Fetch a fresh nonce before submitting (avoids stale nonce from cached pages).
			fetch(afChatbot.ajaxUrl + '?action=af_refresh_nonce', { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (nonceResp) {
					if (nonceResp && nonceResp.success && nonceResp.data && nonceResp.data.nonce) {
						data.append('nonce', nonceResp.data.nonce);
					} else {
						data.append('nonce', afChatbot.nonce);
					}
					return fetch(afChatbot.ajaxUrl, {
						method: 'POST',
						body: data,
						credentials: 'same-origin',
						signal: state.activeRequestController.signal
					});
				})
				.catch(function () {
					// If nonce refresh fails, try with original nonce.
					data.append('nonce', afChatbot.nonce);
					return fetch(afChatbot.ajaxUrl, {
						method: 'POST',
						body: data,
						credentials: 'same-origin',
						signal: state.activeRequestController.signal
					});
				})
				.then(function (response) {
					return response.text().then(function (rawText) {
						var payload = parseAjaxPayload(rawText);

						return {
							ok: response.ok,
							status: response.status,
							payload: payload
						};
					});
				})
				.then(function (result) {
					var payload = result && result.payload ? result.payload : null;
					if (result && result.ok && payload && payload.success) {
						message.className = 'af-chatbot-success';
						message.textContent = (payload.data && payload.data.message) || afChatbot.successText;
						appendBubble(message.textContent, 'bot');
						if (payload.data && payload.data.contract && payload.data.contract.generated && payload.data.contract.document_url) {
							appendBubble('Contrato generado automaticamente.', 'bot');
						}
							state.started = false;
							resetCollectionState();
						showForm(false);
						return;
					}

					if (payload && payload.data && payload.data.code === 'af_guest_email_exists') {
						state.isSubmitting = false;
						state.activeRequestController = null;
						setFormDisabled(false);
						message.className = '';
						message.textContent = '';
						renderExistingGuestChoice(payload.data.existing || null);
						return;
					}

					var errorMessage = (payload && payload.data && payload.data.message) ? payload.data.message : '';
					if (!errorMessage && result) {
						if (result.status === 403) {
							errorMessage = 'Tu sesion expiro. Recarga la pagina y vuelve a intentarlo.';
						} else if (result.status === 400) {
							errorMessage = 'Faltan datos obligatorios o hay un formato invalido. Revisa tus respuestas.';
						} else if (result.status === 413) {
							errorMessage = 'La solicitud es demasiado grande para el servidor.';
						} else if (result.status >= 500) {
							errorMessage = 'Error interno del servidor. Intenta nuevamente en unos minutos.';
						}
					}

					state.lastSubmitFailed = true;
					message.className = 'af-chatbot-error';
					message.textContent = errorMessage || afChatbot.errorText;
					appendBubble(message.textContent, 'bot');
						appendBubble('Puedes corregir datos con "Volver", reintentar con Enviar o escribir "cancelar" para reiniciar.', 'bot');
				})
					.catch(function (error) {
						if (error && error.name === 'AbortError') {
							message.className = '';
							message.textContent = '';
							appendBubble('Envio cancelado por ti. Puedes continuar corrigiendo datos o reiniciar.', 'bot');
							return;
						}
					state.lastSubmitFailed = true;
					message.className = 'af-chatbot-error';
					message.textContent = afChatbot.errorText || 'No se pudo enviar el registro.';
					appendBubble(message.textContent, 'bot');
						appendBubble('Error de conexion. Puedes reintentar con Enviar, volver un paso o cancelar.', 'bot');
				})
				.finally(function () {
						state.isSubmitting = false;
						state.activeRequestController = null;
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
			if (state.isSubmitting) {
				botReply('Tu solicitud se esta enviando. Puedes usar "Cancelar envio" si deseas detenerla.');
				return;
			}
			if (!state.collecting) {
				state.collecting = true;
				state.currentStep = 0;
				showForm(true);
				askCurrentStep();
				return;
			}
			var step = getCurrentStep();
			if (!step) {
				submitConversation();
				return;
			}
			var value = getStepValue(step);
			if (handleInlineCommands(value)) {
				if ('select' !== step.type) {
					input.value = '';
				}
				return;
			}
			if (!step.validate(value, state.values)) {
				message.className = 'af-chatbot-error';
				message.textContent = step.error;
				botReply(step.error);
				return;
			}
			message.textContent = '';
			message.className = '';
			state.values[step.key] = value;
			state.lastSubmitFailed = false;
			appendBubble(getStepLabel(step, value), 'user');
			state.currentStep += 1;
			askCurrentStep();
		});

		messages.addEventListener('click', function (event) {
			var target = event.target;
			if (target && target.classList && target.classList.contains('af-chatbot-existing-choice')) {
				resolveExistingGuestChoice(target.getAttribute('data-existing-choice') || 'refresh');
				return;
			}

			if (!target || !target.classList || !target.classList.contains('af-chatbot-option-btn')) {
				return;
			}
			handleMenuOption(target.getAttribute('data-option') || '', target.textContent || '');
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
