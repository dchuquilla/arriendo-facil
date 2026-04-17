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
				lastSubmitFailed: false,
				isSubmitting: false,
				activeRequestController: null
		};

		var menuOptions = [
				{ key: 'rent', label: 'Quiero arrendar (soy inquilino)' },
			{ key: 'availability', label: 'Consultar disponibilidad' },
				{ key: 'advisor', label: 'Hablar con un asesor' }
		];

		var steps = [
			{
				key: 'name',
				type: 'text',
				question: 'Empecemos con tus datos como inquilino. Cual es tu nombre completo?',
				placeholder: 'Ejemplo: Juan Perez',
				validate: function (value) { return value.length >= 3; },
				error: 'Escribe tu nombre completo.'
			},
			{
				key: 'email',
				type: 'text',
				question: 'Cual es tu correo electronico para enviarte informacion del arriendo?',
				placeholder: 'correo@dominio.com',
				validate: function (value) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value); },
				error: 'Ingresa un correo valido.'
			},
			{
				key: 'phone',
				type: 'text',
				question: 'Cual es tu numero de celular en Ecuador? (10 digitos, sin espacios ni guiones)',
				placeholder: '0991234567',
				validate: function (value) { return /^[0-9]{10}$/.test(value); },
				error: 'El telefono debe tener exactamente 10 digitos numericos.'
			},
			{
				key: 'id_number',
				type: 'text',
				question: 'Ingresa tu numero de cedula (10 digitos, solo numeros).',
				placeholder: '1723456789',
				validate: function (value) { return /^[0-9]{10}$/.test(value); },
				error: 'La cedula debe tener exactamente 10 digitos numericos.'
			},
			{
				key: 'mascotas',
				type: 'text',
				question: 'Cuantas mascotas vivirian contigo en la propiedad? (0 a 10)',
				placeholder: '0',
				validate: function (value) {
					var num = parseInt(value, 10);
					return !isNaN(num) && num >= 0 && num <= 10;
				},
				error: 'Mascotas debe estar entre 0 y 10.'
			},
			{
				key: 'reference_1_name',
				type: 'text',
				question: 'Primera referencia personal: escribe solo el nombre completo.',
				placeholder: 'Ejemplo: Ana Perez',
				helperText: 'Esta referencia debe ser una persona que pueda confirmar tu perfil como inquilino.',
				validate: function (value) {
					return /^[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'.,\-\s]{2,79}$/.test(value);
				},
				error: 'Ingresa un nombre valido (solo letras y minimo 3 caracteres).'
			},
			{
				key: 'reference_1_phone',
				type: 'text',
				question: 'Ahora escribe el celular de esa primera referencia.',
				placeholder: 'Ejemplo: 0991234567',
				helperText: 'Acepta formatos como: 0991234567, 099 123 4567 o 099-123-4567. Siempre deben ser 10 digitos.',
				validate: function (value) {
					var digits = normalizePhoneDigits(value);
					return /^0[0-9]{9}$/.test(digits);
				},
				error: 'El celular de la referencia debe tener 10 digitos y empezar con 0.'
			},
			{
				key: 'reference_2_name',
				type: 'text',
				question: 'Segunda referencia personal: escribe solo el nombre completo.',
				placeholder: 'Ejemplo: Carlos Ruiz',
				helperText: 'Debe ser una persona distinta a la referencia anterior.',
				validate: function (value, values) {
					if (!/^[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'.,\-\s]{2,79}$/.test(value)) {
						return false;
					}
					return normalizeText(value) !== normalizeText(values.reference_1_name || '');
				},
				error: 'Ingresa un nombre valido y diferente al de la primera referencia.'
			},
			{
				key: 'reference_2_phone',
				type: 'text',
				question: 'Ahora escribe el celular de la segunda referencia.',
				placeholder: 'Ejemplo: 0987654321',
				helperText: 'Acepta formatos como: 0991234567, 099 123 4567 o 099-123-4567. Siempre deben ser 10 digitos.',
				validate: function (value, values) {
					var digits = normalizePhoneDigits(value);
					if (!/^0[0-9]{9}$/.test(digits)) {
						return false;
					}
					return digits !== normalizePhoneDigits(values.reference_1_phone || '');
				},
				error: 'El celular debe tener 10 digitos y ser distinto al de la primera referencia.'
			},
			{
				key: 'personas_viviran',
				type: 'text',
				question: 'Contandote a ti, cuantas personas vivirian en la propiedad? (1 a 10)',
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
				question: 'Selecciona la propiedad o habitacion que te interesa arrendar.',
				shouldAsk: function () { return true; },
				options: function () {
					var opts = [{ value: '', label: 'Selecciona una opcion' }];
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
				error: 'Selecciona una accommodation valida.'
			},
			{
				key: 'rental_mode',
				type: 'select',
				question: 'Como prefieres indicar el tiempo de arriendo?',
				options: function () {
					return [
						{ value: '', label: 'Selecciona una modalidad' },
						{ value: 'dates', label: 'Por fechas exactas (inicio y fin)' },
						{ value: 'months', label: 'Por cantidad de meses' },
						{ value: 'years', label: 'Por cantidad de anos' }
					];
				},
				validate: function (value) {
					return value === 'dates' || value === 'months' || value === 'years';
				},
				error: 'Selecciona una modalidad valida.'
			},
			{
				key: 'rental_start_date',
				type: 'text',
				question: 'Fecha de inicio del arriendo (formato YYYY-MM-DD).',
				placeholder: '2026-04-01',
				shouldAsk: function (values) { return values.rental_mode === 'dates'; },
				validate: function (value) { return /^\d{4}-\d{2}-\d{2}$/.test(value); },
				error: 'Ingresa la fecha inicial en formato YYYY-MM-DD.'
			},
			{
				key: 'rental_end_date',
				type: 'text',
				question: 'Fecha de fin del arriendo (formato YYYY-MM-DD).',
				placeholder: '2026-10-01',
				shouldAsk: function (values) { return values.rental_mode === 'dates'; },
				validate: function (value, values) {
					if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
						return false;
					}
					if (!/^\d{4}-\d{2}-\d{2}$/.test(values.rental_start_date || '')) {
						return false;
					}
					return new Date(value).getTime() >= new Date(values.rental_start_date).getTime();
				},
				error: 'La fecha final debe ser valida y mayor o igual a la fecha inicial.'
			},
			{
				key: 'rental_months',
				type: 'text',
				question: 'Cuantos meses quieres arrendar la propiedad?',
				placeholder: '12',
				shouldAsk: function (values) { return values.rental_mode === 'months'; },
				validate: function (value) {
					var num = parseInt(value, 10);
					return !isNaN(num) && num >= 1 && num <= 120;
				},
				error: 'Los meses deben estar entre 1 y 120.'
			},
			{
				key: 'rental_years',
				type: 'text',
				question: 'Cuantos anos quieres arrendar la propiedad?',
				placeholder: '2',
				shouldAsk: function (values) { return values.rental_mode === 'years'; },
				validate: function (value) {
					var num = parseInt(value, 10);
					return !isNaN(num) && num >= 1 && num <= 20;
				},
				error: 'Los anos deben estar entre 1 y 20.'
			},
			{
				key: 'desired_price',
				type: 'text',
				question: 'Cual es tu presupuesto mensual aproximado para el arriendo?',
				placeholder: 'Ejemplo: 350 USD al mes',
				validate: function (value) { return value.length >= 2; },
				error: 'Ingresa un presupuesto aproximado.'
			},
			{
				key: 'guarantee_text',
				type: 'text',
				question: 'Como puedes garantizar el pago? (ejemplo: deposito, garante, rol de pagos, certificado laboral)',
				placeholder: 'Ejemplo: Deposito de 2 meses + certificado laboral',
				validate: function (value) { return value.length >= 5; },
				error: 'Describe la garantia con un poco mas de detalle.'
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
				select.focus();
				return;
			}
			select.setAttribute('hidden', 'hidden');
			input.removeAttribute('hidden');
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
			state.collecting = false;
			state.currentStep = 0;
			state.values = {};
			state.lastSubmitFailed = false;
			if (currentAccommodationId > 0) {
				state.values.accommodation_id = String(currentAccommodationId);
			}
			messages.innerHTML = '';
			message.textContent = '';
			message.className = '';
			showForm(false);
				botReply(afChatbot.welcomeText || 'Hola, soy el asistente de Arriendo Facil. Te ayudo a registrar tu solicitud de arriendo.', function () {
					botReply('Este chatbot es solo para personas que quieren arrendar (inquilinos).', function () {
						appendOptions();
					});
				});
			}

			function resetCollectionState() {
				state.collecting = false;
				state.currentStep = 0;
				state.values = {};
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
				setFormDisabled(false);
				showForm(false);
				message.className = '';
				message.textContent = '';
				botReply('Operacion cancelada. Cuando quieras, puedes iniciar nuevamente.', function () {
					appendOptions();
				});
			}

			function clearDependentValues(stepKey) {
				if ('rental_mode' !== stepKey) {
					return;
				}
				delete state.values.rental_start_date;
				delete state.values.rental_end_date;
				delete state.values.rental_months;
				delete state.values.rental_years;
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
				if ('rent' === key) {
					state.collecting = true;
					state.currentStep = 0;
					state.values = {};
					if (currentAccommodationId > 0) {
						state.values.accommodation_id = String(currentAccommodationId);
					}
					showForm(true);
					botReply('Perfecto. Vamos paso a paso. Si te equivocas, puedes usar "Volver" o escribir "cancelar" para reiniciar.');
					askCurrentStep();
					return;
				}
				if ('availability' === key) {
					botReply('Te ayudo con disponibilidad. Si quieres registrar una solicitud completa de arriendo, elige "Quiero arrendar (soy inquilino)".', function () {
						appendOptions();
					});
					return;
				}
				botReply('Un asesor te contactara pronto. Si deseas, tambien puedes elegir "Quiero arrendar (soy inquilino)" para adelantar tu registro.', function () {
				appendOptions();
			});
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
			var reference1Phone = normalizePhoneDigits(state.values.reference_1_phone || '');
			var reference2Phone = normalizePhoneDigits(state.values.reference_2_phone || '');
			var reference1Text = ((state.values.reference_1_name || '').trim() + ' - ' + reference1Phone).trim();
			var reference2Text = ((state.values.reference_2_name || '').trim() + ' - ' + reference2Phone).trim();
			data.append('action', 'af_create_guest_frontend');
			data.append('nonce', afChatbot.nonce);
			data.append('name', state.values.name || '');
			data.append('email', state.values.email || '');
			data.append('phone', state.values.phone || '');
			data.append('id_number', state.values.id_number || '');
			data.append('mascotas', state.values.mascotas || '0');
			data.append('reference_1_name', state.values.reference_1_name || '');
			data.append('reference_1_phone', reference1Phone);
			data.append('reference_2_name', state.values.reference_2_name || '');
			data.append('reference_2_phone', reference2Phone);
			data.append('referencia_personal_1', reference1Text);
			data.append('referencia_personal_2', reference2Text);
			data.append('personas_viviran', state.values.personas_viviran || '1');
			data.append('accommodation_id', state.values.accommodation_id || '');
			data.append('rental_mode', state.values.rental_mode || '');
			data.append('rental_start_date', state.values.rental_start_date || '');
			data.append('rental_end_date', state.values.rental_end_date || '');
			data.append('rental_months', state.values.rental_months || '');
			data.append('rental_years', state.values.rental_years || '');
			data.append('desired_price', state.values.desired_price || '');
			data.append('guarantee_text', state.values.guarantee_text || '');

			state.isSubmitting = true;
			state.activeRequestController = new AbortController();
			setFormDisabled(true);
			state.lastSubmitFailed = false;
			botReply(afChatbot.doneText || 'Perfecto, ya tengo tus datos. Estoy registrando tu solicitud...');

			fetch(afChatbot.ajaxUrl, {
				method: 'POST',
				body: data,
					credentials: 'same-origin',
					signal: state.activeRequestController.signal
			})
				.then(function (response) {
					return response.text().then(function (rawText) {
						var payload = null;
						var text = String(rawText || '').trim();

						if (text) {
							try {
								payload = JSON.parse(text);
							} catch (parseError) {
								payload = null;
							}
						}

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
				botReply('Elige una opcion para continuar.', function () {
					appendOptions();
				});
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
			clearDependentValues(step.key);
			if (step.key === 'reference_1_name' || step.key === 'reference_2_name') {
				state.values[step.key] = value.replace(/\s+/g, ' ').trim();
			}
			if (step.key === 'reference_1_phone' || step.key === 'reference_2_phone') {
				state.values[step.key] = normalizePhoneDigits(value);
			}
			state.lastSubmitFailed = false;
			appendBubble(getStepLabel(step, value), 'user');
			state.currentStep += 1;
			askCurrentStep();
		});

		messages.addEventListener('click', function (event) {
			var target = event.target;
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
