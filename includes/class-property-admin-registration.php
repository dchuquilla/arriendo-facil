<?php
/**
 * Class Arriendo_Facil_Property_Admin_Registration
 *
 * Gestiona el auto-registro público de administradores de propiedades
 * (rol af_property_admin) a través del shortcode [af_property_admin_signup].
 *
 * Flujo de alta:
 *  1. El prospecto completa el formulario público (datos de la empresa,
 *     contacto, identificación, clave).
 *  2. El sistema valida identificación (cédula/RUC), aplica controles
 *     anti-abuso (nonce, honeypot, firma del formulario, rate-limit) y
 *     cifra los datos sensibles.
 *  3. Se crea la cuenta en estado "correo sin verificar" y se envía un
 *     correo con enlace de verificación (vence en 24 h).
 *  4. Al verificar el correo se activa la cuenta. No se siembran datos de
 *     ejemplo: el gestor opera desde el inicio con sus datos reales.
 *  5. El prospecto completa su perfil (identidad y documentos), que el
 *     super admin revisa de forma informativa (no bloquea la operación).
 *  6. Un cron diario (af_admin_profile_reminders_cron) recuerda al
 *     prospecto y al equipo los pasos pendientes.
 *
 * Conviviencia con el alta manual: la cuenta manual de propiedad no
 * requiere correo ni verificación (af_signup_source = 'manual'); la
 * cuenta auto-registrada usa af_signup_source = 'self' e incluye
 * verificación documental supervisada.
 *
 * @package Arriendo_Facil
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Arriendo_Facil_Crypto' ) ) {
	require_once __DIR__ . '/class-crypto.php';
}

class Arriendo_Facil_Property_Admin_Registration {

	const NONCE_ACTION   = 'af_admin_signup_frontend_nonce';
	const FORM_SECRET_SLUG = 'af_admin_signup_form_v1';
	const VERIFY_ACTION  = 'af_verify_admin_email';
	const CRON_HOOK      = 'af_admin_profile_reminders_cron';

	/**
	 * Hooks the feature.
	 */
	public function __construct() {
		add_shortcode( 'af_property_admin_signup', array( $this, 'render_signup_shortcode' ) );

		add_action( 'wp_ajax_af_register_property_admin', array( $this, 'ajax_register_property_admin' ) );
		add_action( 'wp_ajax_nopriv_af_register_property_admin', array( $this, 'ajax_register_property_admin' ) );

		add_action( 'wp_ajax_af_resend_admin_verification_email', array( $this, 'ajax_resend_admin_verification_email' ) );
		add_action( 'wp_ajax_nopriv_af_resend_admin_verification_email', array( $this, 'ajax_resend_admin_verification_email' ) );

		add_action( 'login_init', array( $this, 'handle_email_verification_request' ) );
		add_filter( 'authenticate', array( $this, 'enforce_email_verification_on_login' ), 30, 3 );

		add_action( self::CRON_HOOK, Arriendo_Facil_Job_Lock::guard( self::CRON_HOOK, array( $this, 'dispatch_profile_reminders' ) ) );
	}

	/**
	 * Renders the public registration shortcode with inline security-aware JS.
	 *
	 * @return string
	 */
	public function render_signup_shortcode() {
		$ajax_url     = esc_url( admin_url( 'admin-ajax.php' ) );
		$nonce        = wp_create_nonce( self::NONCE_ACTION );
		$login_url    = wp_login_url( home_url( '/' ) );
		$recover_url  = wp_lostpassword_url();
		$instance_id  = 'af-admin-signup-' . wp_rand( 1000, 999999 );
		$form_ts      = time();
		$form_sig     = hash_hmac( 'sha256', (string) $form_ts, wp_salt( 'nonce' ) . self::FORM_SECRET_SLUG );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $instance_id ); ?>" class="af-admin-signup">
			<div class="af-admin-signup__head">
				<h2 class="af-admin-signup__title"><?php echo esc_html__( 'Crea tu cuenta de gestor', 'arriendo-facil' ); ?></h2>
				<p class="af-admin-signup__subtitle"><?php echo esc_html__( 'Completa tus datos en pocos minutos. Antes de ingresar, te enviaremos un correo para confirmar que la cuenta es tuya.', 'arriendo-facil' ); ?></p>
				<div class="af-admin-signup__progress" aria-hidden="true"><span class="af-admin-signup__progress-bar" data-af-progress-bar></span></div>
				<p class="af-admin-signup__progress-label" data-af-progress-label aria-live="off"></p>
			</div>

			<div data-af-admin-alert class="af-admin-signup__alert" role="alert" tabindex="-1" hidden></div>

			<form data-af-admin-form class="af-admin-signup__form" autocomplete="on">

				<div class="af-admin-signup__group" role="group" aria-labelledby="<?php echo esc_attr( $instance_id ); ?>-g1">
					<h3 class="af-admin-signup__group-title" id="<?php echo esc_attr( $instance_id ); ?>-g1"><span class="af-admin-signup__num" aria-hidden="true">1</span><?php echo esc_html__( 'Tu empresa o gestión', 'arriendo-facil' ); ?></h3>
					<div class="af-admin-signup__grid">
						<label class="af-admin-signup__field af-admin-signup__full">
							<span class="af-admin-signup__label"><?php echo esc_html__( 'Nombre de la empresa', 'arriendo-facil' ); ?></span>
							<input type="text" name="company_name" required maxlength="190" autocomplete="organization" class="af-admin-signup__input" />
						</label>
						<label class="af-admin-signup__field af-admin-signup__full">
							<span class="af-admin-signup__label"><?php echo esc_html__( 'Nombre y apellido del responsable', 'arriendo-facil' ); ?></span>
							<input type="text" name="contact_name" required maxlength="190" autocomplete="name" class="af-admin-signup__input" />
						</label>
						<label class="af-admin-signup__field">
							<span class="af-admin-signup__label"><?php echo esc_html__( 'Correo electrónico', 'arriendo-facil' ); ?></span>
							<input type="email" name="email" required maxlength="190" autocomplete="email" inputmode="email" class="af-admin-signup__input" />
						</label>
						<label class="af-admin-signup__field">
							<span class="af-admin-signup__label"><?php echo esc_html__( 'Teléfono', 'arriendo-facil' ); ?></span>
							<input type="tel" name="phone" required maxlength="20" pattern="[0-9+\s\-]{7,20}" autocomplete="tel" inputmode="tel" class="af-admin-signup__input" />
						</label>
					</div>
				</div>

				<div class="af-admin-signup__group" role="group" aria-labelledby="<?php echo esc_attr( $instance_id ); ?>-g2">
					<h3 class="af-admin-signup__group-title" id="<?php echo esc_attr( $instance_id ); ?>-g2"><span class="af-admin-signup__num" aria-hidden="true">2</span><?php echo esc_html__( 'Identificación', 'arriendo-facil' ); ?></h3>
					<div class="af-admin-signup__grid">
						<label class="af-admin-signup__field">
							<span class="af-admin-signup__label"><?php echo esc_html__( 'Tipo de identificación', 'arriendo-facil' ); ?></span>
							<select name="id_type" required class="af-admin-signup__input">
								<option value=""><?php echo esc_html__( 'Selecciona', 'arriendo-facil' ); ?></option>
								<option value="cedula"><?php echo esc_html__( 'Cédula', 'arriendo-facil' ); ?></option>
								<option value="ruc"><?php echo esc_html__( 'RUC', 'arriendo-facil' ); ?></option>
								<option value="pasaporte"><?php echo esc_html__( 'Pasaporte', 'arriendo-facil' ); ?></option>
							</select>
						</label>
						<label class="af-admin-signup__field">
							<span class="af-admin-signup__label"><?php echo esc_html__( 'Número de identificación', 'arriendo-facil' ); ?></span>
							<input type="text" name="id_number" required maxlength="20" pattern="[A-Za-z0-9\-]{5,20}" autocomplete="off" aria-describedby="<?php echo esc_attr( $instance_id ); ?>-idhint" class="af-admin-signup__input" />
							<span class="af-admin-signup__hint" id="<?php echo esc_attr( $instance_id ); ?>-idhint" data-af-idhint><?php echo esc_html__( 'Cédula (10 dígitos), RUC (13 dígitos) o pasaporte (6 a 12 caracteres alfanuméricos).', 'arriendo-facil' ); ?></span>
						</label>
						<label class="af-admin-signup__field">
							<span class="af-admin-signup__label"><?php echo esc_html__( 'Nacionalidad', 'arriendo-facil' ); ?></span>
							<input type="text" name="nationality" required maxlength="100" autocomplete="country-name" class="af-admin-signup__input" value="Ecuador" />
						</label>
						<label class="af-admin-signup__field">
							<span class="af-admin-signup__label"><?php echo esc_html__( 'Ciudad de nacimiento', 'arriendo-facil' ); ?></span>
							<input type="text" name="birth_city" required maxlength="150" autocomplete="off" class="af-admin-signup__input" />
						</label>
					</div>
				</div>

				<div class="af-admin-signup__group" role="group" aria-labelledby="<?php echo esc_attr( $instance_id ); ?>-g3">
					<h3 class="af-admin-signup__group-title" id="<?php echo esc_attr( $instance_id ); ?>-g3"><span class="af-admin-signup__num" aria-hidden="true">3</span><?php echo esc_html__( 'Seguridad de la cuenta', 'arriendo-facil' ); ?></h3>
					<div class="af-admin-signup__grid">
						<label class="af-admin-signup__field">
							<span class="af-admin-signup__label"><?php echo esc_html__( 'Contraseña', 'arriendo-facil' ); ?></span>
							<span class="af-admin-signup__control">
								<input type="password" name="password" required minlength="10" maxlength="72" autocomplete="new-password" class="af-admin-signup__input af-admin-signup__input--reveal" />
								<?php $this->render_password_reveal_button(); ?>
							</span>
							<span class="af-admin-signup__strength" data-af-strength hidden>
								<span class="af-admin-signup__strength-track"><span class="af-admin-signup__strength-bar" data-af-strength-bar></span></span>
								<span class="af-admin-signup__strength-label" data-af-strength-label></span>
							</span>
						</label>
						<label class="af-admin-signup__field">
							<span class="af-admin-signup__label"><?php echo esc_html__( 'Confirmar contraseña', 'arriendo-facil' ); ?></span>
							<span class="af-admin-signup__control">
								<input type="password" name="password_confirm" required minlength="10" maxlength="72" autocomplete="new-password" class="af-admin-signup__input af-admin-signup__input--reveal" />
								<?php $this->render_password_reveal_button(); ?>
							</span>
							<span class="af-admin-signup__match" data-af-match hidden aria-live="polite"></span>
						</label>
					</div>

					<p class="af-admin-signup__rules-title"><?php echo esc_html__( 'Requisitos de contraseña', 'arriendo-facil' ); ?></p>
					<ul class="af-admin-signup__rules" data-af-rules>
						<li class="af-admin-signup__rule" data-rule="length"><?php echo esc_html__( 'Mínimo 10 caracteres', 'arriendo-facil' ); ?></li>
						<li class="af-admin-signup__rule" data-rule="upper"><?php echo esc_html__( 'Al menos 1 mayúscula', 'arriendo-facil' ); ?></li>
						<li class="af-admin-signup__rule" data-rule="lower"><?php echo esc_html__( 'Al menos 1 minúscula', 'arriendo-facil' ); ?></li>
						<li class="af-admin-signup__rule" data-rule="number"><?php echo esc_html__( 'Al menos 1 número', 'arriendo-facil' ); ?></li>
						<li class="af-admin-signup__rule" data-rule="symbol"><?php echo esc_html__( 'Al menos 1 símbolo (ej: !@#$%)', 'arriendo-facil' ); ?></li>
					</ul>
				</div>

				<input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="af-admin-signup__honeypot" />
				<input type="hidden" name="form_ts" value="<?php echo esc_attr( (string) $form_ts ); ?>" />
				<input type="hidden" name="form_sig" value="<?php echo esc_attr( (string) $form_sig ); ?>" />

				<?php Arriendo_Facil_Consent::render( 'admin_signup' ); ?>

				<div class="af-admin-signup__actions">
					<button type="submit" data-af-admin-submit class="af-admin-signup__submit">
						<span class="af-admin-signup__submit-text"><?php echo esc_html__( 'Crear mi cuenta', 'arriendo-facil' ); ?></span>
					</button>
				</div>

				<nav class="af-admin-signup__links" aria-label="<?php echo esc_attr__( 'Otras opciones de acceso', 'arriendo-facil' ); ?>">
					<a class="af-admin-signup__link af-admin-signup__link--primary" href="<?php echo esc_url( $login_url ); ?>"><?php echo esc_html__( 'Ya tengo cuenta, iniciar sesión', 'arriendo-facil' ); ?></a>
					<button type="button" class="af-admin-signup__link af-admin-signup__link--button" data-af-admin-resend><?php echo esc_html__( 'Reenviar correo de verificación', 'arriendo-facil' ); ?></button>
					<a class="af-admin-signup__link" href="<?php echo esc_url( $recover_url ); ?>"><?php echo esc_html__( 'Recuperar contraseña', 'arriendo-facil' ); ?></a>
				</nav>
			</form>

			<div class="af-admin-signup__done" data-af-admin-done hidden tabindex="-1">
				<span class="af-admin-signup__done-icon" aria-hidden="true">
					<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="3"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
				</span>
				<h3 class="af-admin-signup__done-title"><?php echo esc_html__( 'Revisa tu correo', 'arriendo-facil' ); ?></h3>
				<p class="af-admin-signup__done-text"><?php echo esc_html__( 'Te enviamos un enlace de verificación a', 'arriendo-facil' ); ?> <strong data-af-done-email></strong>. <?php echo esc_html__( 'Debes confirmarlo antes de iniciar sesión. El enlace vence en 24 horas.', 'arriendo-facil' ); ?></p>
				<p class="af-admin-signup__done-note"><?php echo esc_html__( '¿No lo ves? Revisa la carpeta de spam o correo no deseado.', 'arriendo-facil' ); ?></p>
				<div class="af-admin-signup__done-actions">
					<a class="af-admin-signup__submit af-admin-signup__submit--link" href="<?php echo esc_url( $login_url ); ?>"><?php echo esc_html__( 'Ir a iniciar sesión', 'arriendo-facil' ); ?></a>
					<button type="button" class="af-admin-signup__link af-admin-signup__link--button" data-af-admin-resend><?php echo esc_html__( 'Reenviar correo de verificación', 'arriendo-facil' ); ?></button>
				</div>
			</div>
		</div>

		<?php echo Arriendo_Facil_Toast::inline_assets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, escaped markup. ?>
		<script>
		(function(){
			const app = document.getElementById(<?php echo wp_json_encode( $instance_id ); ?>);
			if(!app){ return; }

			const ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			const nonce = <?php echo wp_json_encode( $nonce ); ?>;
			const form = app.querySelector('[data-af-admin-form]');
			const submitBtn = app.querySelector('[data-af-admin-submit]');
			const resendButtons = app.querySelectorAll('[data-af-admin-resend]');
			const alertBox = app.querySelector('[data-af-admin-alert]');
			const doneBox = app.querySelector('[data-af-admin-done]');
			const doneEmail = app.querySelector('[data-af-done-email]');
			const emailInput = form.querySelector('input[name="email"]');
			let lastEmail = '';
			let resendCooldown = null;

			function showAlert(message, type){
				alertBox.hidden = false;
				alertBox.dataset.type = type === 'success' ? 'success' : 'error';
				alertBox.textContent = String(message || '');
				if(typeof alertBox.scrollIntoView === 'function'){ alertBox.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
			}

			function hideAlert(){
				alertBox.hidden = true;
				alertBox.textContent = '';
			}

			function rejectRegistration(message){
				showAlert(message, 'error');
				notifyRegistration(false, message);
			}

			function notifyRegistration(approved, message){
				if(typeof window.afToast !== 'function'){ return; }
				window.afToast({
					type: approved ? 'approved' : 'rejected',
					title: approved ? <?php echo wp_json_encode( __( '¡Registro exitoso!', 'arriendo-facil' ) ); ?> : <?php echo wp_json_encode( __( 'No se pudo completar el registro', 'arriendo-facil' ) ); ?>,
					message: String(message || '')
				});
			}

			function passwordChecks(password){
				return [
					password.length >= 10,
					/[A-Z]/.test(password),
					/[a-z]/.test(password),
					/[0-9]/.test(password),
					/[^A-Za-z0-9]/.test(password)
				];
			}

			function validatePasswordClientSide(password){
				return passwordChecks(password).every(Boolean);
			}

			const pwInput = form.querySelector('input[name="password"]');
			const pwConfirmInput = form.querySelector('input[name="password_confirm"]');
			const ruleItems = app.querySelectorAll('[data-af-rules] [data-rule]');
			const strengthBox = app.querySelector('[data-af-strength]');
			const strengthBar = app.querySelector('[data-af-strength-bar]');
			const strengthLabel = app.querySelector('[data-af-strength-label]');
			const matchBox = app.querySelector('[data-af-match]');
			const strengthLevels = [
				{ label: <?php echo wp_json_encode( __( 'Muy débil', 'arriendo-facil' ) ); ?>, color: '#c0392b' },
				{ label: <?php echo wp_json_encode( __( 'Débil', 'arriendo-facil' ) ); ?>, color: '#e0742b' },
				{ label: <?php echo wp_json_encode( __( 'Media', 'arriendo-facil' ) ); ?>, color: '#d9a62b' },
				{ label: <?php echo wp_json_encode( __( 'Buena', 'arriendo-facil' ) ); ?>, color: '#8aa94f' },
				{ label: <?php echo wp_json_encode( __( 'Fuerte', 'arriendo-facil' ) ); ?>, color: '#426747' }
			];

			function updatePasswordUi(){
				const value = String(pwInput.value || '');
				const checks = passwordChecks(value);
				const passed = checks.filter(Boolean).length;

				ruleItems.forEach(function(item, index){
					item.classList.toggle('is-ok', !!checks[index]);
				});

				strengthBox.hidden = value === '';
				if(value !== ''){
					const level = strengthLevels[Math.max(0, passed - 1)];
					strengthBar.style.width = (passed * 20) + '%';
					strengthBar.style.background = level.color;
					strengthLabel.textContent = level.label;
				}

				const confirmValue = String(pwConfirmInput.value || '');
				matchBox.hidden = confirmValue === '';
				if(confirmValue !== ''){
					const same = confirmValue === value;
					matchBox.classList.toggle('is-ok', same);
					matchBox.classList.toggle('is-error', !same);
					matchBox.textContent = same
						? <?php echo wp_json_encode( __( 'Las contraseñas coinciden', 'arriendo-facil' ) ); ?>
						: <?php echo wp_json_encode( __( 'Las contraseñas no coinciden', 'arriendo-facil' ) ); ?>;
				}
			}

			/* Avance del formulario: campos obligatorios completos / total. */
			const progressBar = app.querySelector('[data-af-progress-bar]');
			const progressLabel = app.querySelector('[data-af-progress-label]');
			const requiredFields = Array.prototype.slice.call(form.querySelectorAll('input[required], select[required]'));
			const progressTpl = <?php echo wp_json_encode( __( '%1$s de %2$s requisitos completos', 'arriendo-facil' ) ); ?>;

			function fieldDone(field){
				if(field.type === 'checkbox'){ return field.checked; }
				if(field.name === 'password'){ return validatePasswordClientSide(String(field.value || '')); }
				if(field.name === 'password_confirm'){ return field.value !== '' && field.value === pwInput.value; }
				return String(field.value || '').trim() !== '' && field.checkValidity();
			}

			function updateProgress(){
				const done = requiredFields.filter(fieldDone).length;
				progressBar.style.width = Math.round((done / requiredFields.length) * 100) + '%';
				progressLabel.textContent = progressTpl.replace('%1$s', done).replace('%2$s', requiredFields.length);
			}

			/* Pista del número de identificación según el tipo elegido. */
			const idType = form.querySelector('select[name="id_type"]');
			const idHint = app.querySelector('[data-af-idhint]');
			const idHints = {
				'': idHint.textContent,
				cedula: <?php echo wp_json_encode( __( 'La cédula tiene 10 dígitos.', 'arriendo-facil' ) ); ?>,
				ruc: <?php echo wp_json_encode( __( 'El RUC tiene 13 dígitos.', 'arriendo-facil' ) ); ?>,
				pasaporte: <?php echo wp_json_encode( __( 'El pasaporte admite de 6 a 12 caracteres alfanuméricos.', 'arriendo-facil' ) ); ?>
			};
			idType.addEventListener('change', function(){
				idHint.textContent = idHints[idType.value] || idHints[''];
			});

			pwInput.addEventListener('input', updatePasswordUi);
			pwConfirmInput.addEventListener('input', updatePasswordUi);
			form.addEventListener('input', updateProgress);
			form.addEventListener('change', updateProgress);
			form.addEventListener('reset', function(){ setTimeout(function(){ updatePasswordUi(); updateProgress(); }, 0); });
			updateProgress();

			app.querySelectorAll('[data-af-reveal]').forEach(function(btn){
				btn.addEventListener('click', function(){
					const input = btn.parentElement.querySelector('input');
					const show = input.type === 'password';
					input.type = show ? 'text' : 'password';
					btn.classList.toggle('is-visible', show);
					btn.setAttribute('aria-pressed', show ? 'true' : 'false');
					btn.setAttribute('aria-label', btn.getAttribute(show ? 'data-label-hide' : 'data-label-show'));
				});
			});

			form.addEventListener('submit', async function(e){
				e.preventDefault();
				if(submitBtn.disabled){ return; }

				if(!validatePasswordClientSide(String(pwInput.value || ''))){
					rejectRegistration(<?php echo wp_json_encode( __( 'La contraseña no cumple los requisitos: mínimo 10 caracteres, mayúscula, minúscula, número y símbolo.', 'arriendo-facil' ) ); ?>);
					return;
				}

				if(String(pwInput.value || '') !== String(pwConfirmInput.value || '')){
					rejectRegistration(<?php echo wp_json_encode( __( 'Las contraseñas no coinciden.', 'arriendo-facil' ) ); ?>);
					return;
				}

				submitBtn.disabled = true;
				app.classList.add('is-loading');
				hideAlert();

				const data = new URLSearchParams(new FormData(form));
				data.set('action', 'af_register_property_admin');
				data.set('nonce', nonce);

				let json;
				try {
					const response = await fetch(ajaxUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
						body: data.toString()
					});
					json = await response.json();
				} catch (err) {
					submitBtn.disabled = false;
					app.classList.remove('is-loading');
					rejectRegistration(<?php echo wp_json_encode( __( 'No se pudo conectar con el servidor. Inténtalo nuevamente.', 'arriendo-facil' ) ); ?>);
					return;
				}

				submitBtn.disabled = false;
				app.classList.remove('is-loading');
				if(!json || !json.success){
					const message = json && json.data && json.data.message ? json.data.message : <?php echo wp_json_encode( __( 'No se pudo crear la cuenta.', 'arriendo-facil' ) ); ?>;
					rejectRegistration(message);
					return;
				}

				const okMessage = (json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'Cuenta creada correctamente.', 'arriendo-facil' ) ); ?>;
				lastEmail = String(emailInput.value || '').trim();
				doneEmail.textContent = lastEmail;
				notifyRegistration(true, okMessage);
				form.reset();
				app.classList.add('is-done');
				form.hidden = true;
				alertBox.hidden = true;
				app.querySelector('.af-admin-signup__head').hidden = true;
				doneBox.hidden = false;
				doneBox.focus({ preventScroll: true });
				doneBox.scrollIntoView({ block: 'center', behavior: 'smooth' });
				document.dispatchEvent(new CustomEvent('af:signup-done', { detail: { app: app } }));
				if(json.data && json.data.should_redirect && json.data.login_url){
					setTimeout(function(){ window.location.href = String(json.data.login_url); }, 900);
				}
			});

			if(window.location.hash === '#reenviar'){
				showAlert(<?php echo wp_json_encode( __( 'Escribe el correo con el que te registraste en el formulario y pulsa «Reenviar correo de verificación».', 'arriendo-facil' ) ); ?>, 'success');
				emailInput.focus({ preventScroll: true });
			}

			function startResendCooldown(seconds){
				let left = seconds;
				const labels = [];
				resendButtons.forEach(function(btn){
					labels.push(btn.textContent);
					btn.disabled = true;
				});
				clearInterval(resendCooldown);
				resendCooldown = setInterval(function(){
					left -= 1;
					if(left <= 0){
						clearInterval(resendCooldown);
						resendButtons.forEach(function(btn, i){ btn.disabled = false; btn.textContent = labels[i]; });
						return;
					}
					resendButtons.forEach(function(btn, i){ btn.textContent = labels[i] + ' (' + left + ' s)'; });
				}, 1000);
				resendButtons.forEach(function(btn, i){ btn.textContent = labels[i] + ' (' + left + ' s)'; });
			}

			resendButtons.forEach(function(resendBtn){
				resendBtn.addEventListener('click', async function(){
					if(resendBtn.disabled){ return; }

					const emailValue = String(emailInput.value || '').trim() || lastEmail;
					if(!emailValue || !/^\S+@\S+\.\S+$/.test(emailValue)){
						showAlert(<?php echo wp_json_encode( __( 'Escribe tu correo electrónico en el formulario para reenviar la verificación.', 'arriendo-facil' ) ); ?>, 'error');
						emailInput.focus();
						return;
					}

					const data = new URLSearchParams();
					data.set('action', 'af_resend_admin_verification_email');
					data.set('nonce', nonce);
					data.set('email', emailValue);

					resendBtn.disabled = true;

					let json;
					try {
						const response = await fetch(ajaxUrl, {
							method: 'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
							body: data.toString()
						});
						json = await response.json();
					} catch (err) {
						resendBtn.disabled = false;
						showAlert(<?php echo wp_json_encode( __( 'No se pudo reenviar el correo en este momento. Inténtalo nuevamente.', 'arriendo-facil' ) ); ?>, 'error');
						return;
					}

					if(!json || !json.success){
						resendBtn.disabled = false;
						const message = json && json.data && json.data.message ? json.data.message : <?php echo wp_json_encode( __( 'No se pudo procesar el reenvío.', 'arriendo-facil' ) ); ?>;
						showAlert(message, 'error');
						return;
					}

					alertBox.hidden = false;
					alertBox.dataset.type = 'success';
					alertBox.textContent = (json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'Si tu cuenta está pendiente, te enviaremos un nuevo correo.', 'arriendo-facil' ) ); ?>;
					if(app.classList.contains('is-done')){ doneBox.parentNode.insertBefore(alertBox, doneBox); }
					startResendCooldown(60);
				});
			});
		})();
		</script>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * AJAX: registers a property-admin account with email-first verification.
	 *
	 * @return void
	 */
	public function ajax_register_property_admin() {
		if ( false === check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'La sesión expiró. Recarga la página e intenta nuevamente.', 'arriendo-facil' ) ), 403 );
		}

		$company_name     = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
		$contact_name     = isset( $_POST['contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_name'] ) ) : '';
		$email            = isset( $_POST['email'] ) ? AF_Text_Normalizer::email( wp_unslash( $_POST['email'] ) ) : '';
		$phone            = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$id_type          = isset( $_POST['id_type'] ) ? sanitize_key( wp_unslash( $_POST['id_type'] ) ) : '';
		$id_number        = isset( $_POST['id_number'] ) ? sanitize_text_field( wp_unslash( $_POST['id_number'] ) ) : '';
		if ( class_exists( 'AF_Text_Normalizer' ) && in_array( $id_type, array( 'cedula', 'ruc', 'pasaporte' ), true ) ) {
			$id_number = AF_Text_Normalizer::document( $id_type, $id_number );
		}
		$nationality      = isset( $_POST['nationality'] ) ? sanitize_text_field( wp_unslash( $_POST['nationality'] ) ) : '';
		$birth_city       = isset( $_POST['birth_city'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_city'] ) ) : '';
		$password         = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$password_confirm = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';
		$accept_terms     = isset( $_POST['accept_terms'] ) ? absint( wp_unslash( $_POST['accept_terms'] ) ) : 0;
		$website_honeypot = isset( $_POST['website'] ) ? sanitize_text_field( wp_unslash( $_POST['website'] ) ) : '';
		$form_ts          = isset( $_POST['form_ts'] ) ? absint( wp_unslash( $_POST['form_ts'] ) ) : 0;
		$form_sig         = isset( $_POST['form_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['form_sig'] ) ) : '';

		if ( '' === $company_name || '' === $contact_name || '' === $email || '' === $phone || '' === $id_number || '' === $password || '' === $password_confirm ) {
			wp_send_json_error( array( 'message' => __( 'Completa todos los campos obligatorios.', 'arriendo-facil' ) ), 400 );
		}

		if ( '' !== trim( $website_honeypot ) ) {
			$this->log_signup_security_event( 'honeypot_triggered', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo procesar la solicitud.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->validate_form_proof( $form_ts, $form_sig ) ) {
			$this->log_signup_security_event( 'invalid_form_proof', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo validar la solicitud. Recarga la página e intenta nuevamente.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Correo electrónico inválido.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! in_array( $id_type, array( 'cedula', 'ruc', 'pasaporte' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Tipo de identificación inválido.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->signup_within_rate_limit( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Demasiados intentos. Espera unos minutos e intenta nuevamente.', 'arriendo-facil' ) ), 429 );
		}

		if ( ! Arriendo_Facil_Consent::is_given() ) {
				wp_send_json_error( array( 'message' => Arriendo_Facil_Consent::error_message() ), 400 );
			}

		if ( $password !== $password_confirm ) {
			wp_send_json_error( array( 'message' => __( 'Las contraseñas no coinciden.', 'arriendo-facil' ) ), 400 );
		}

		$password_policy_errors = $this->validate_password_policy( $password );
		if ( ! empty( $password_policy_errors ) ) {
			wp_send_json_error( array( 'message' => implode( ' ', $password_policy_errors ) ), 400 );
		}

		if ( ! class_exists( 'Arriendo_Facil_Identity_Validator' )
			|| ! Arriendo_Facil_Identity_Validator::validate_format( $id_type, $id_number ) ) {
			wp_send_json_error( array( 'message' => __( 'El número de identificación no es válido para el tipo seleccionado.', 'arriendo-facil' ) ), 400 );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error(
				array(
					'code'    => 'email_exists',
					'message' => __( 'Ese correo ya tiene una cuenta. Inicia sesión o recupera tu contraseña.', 'arriendo-facil' ),
				),
				409
			);
		}

		$encrypted_phone = $this->encrypt_sensitive_value( $phone );
		$encrypted_id    = $this->encrypt_sensitive_value( $id_number );
		if ( '' === $encrypted_phone || '' === $encrypted_id ) {
			$this->log_signup_security_event( 'encryption_failed', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo proteger la información sensible. Intenta nuevamente.', 'arriendo-facil' ) ), 500 );
		}

		$id_fingerprint = $this->build_identity_fingerprint( $id_number );
		if ( '' === $id_fingerprint || $this->identity_fingerprint_exists( $id_fingerprint ) || $this->identity_fingerprint_exists( $this->build_identity_fingerprint_legacy( $id_number ) ) ) {
			$this->log_signup_security_event( 'duplicate_identity_fingerprint', $email );
			wp_send_json_error(
				array(
					'code'    => 'identity_exists',
					'message' => __( 'La identificación ya está asociada a una cuenta. Si necesitas ayuda, contacta soporte.', 'arriendo-facil' ),
				),
				409
			);
		}

		if ( ! get_role( 'af_property_admin' ) && class_exists( 'Arriendo_Facil_Activator' ) ) {
			Arriendo_Facil_Activator::ensure_owner_role();
		}

		$user_login_base = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $user_login_base ) {
			$user_login_base = 'admin';
		}

		$user_login = $user_login_base;
		$attempts   = 0;
		while ( username_exists( $user_login ) && $attempts < 6 ) {
			++$attempts;
			$user_login = $user_login_base . '_' . wp_rand( 100, 9999 );
		}

		if ( username_exists( $user_login ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo generar un usuario válido. Intenta con otro correo.', 'arriendo-facil' ) ), 500 );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $user_login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'first_name'   => $contact_name,
				'display_name' => '' !== $company_name ? $company_name : $contact_name,
				'role'         => 'af_property_admin',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ), 500 );
		}

		update_user_meta( $user_id, 'af_company_name', $company_name );
		update_user_meta( $user_id, 'af_contact_name', $contact_name );
		update_user_meta( $user_id, 'af_contact_phone', $phone );
		update_user_meta( $user_id, 'af_admin_phone_enc', $encrypted_phone );
		update_user_meta( $user_id, 'af_admin_id_type', $id_type );
		update_user_meta( $user_id, 'af_admin_id_number_enc', $encrypted_id );
		update_user_meta( $user_id, 'af_admin_id_fingerprint', $id_fingerprint );
		update_user_meta( $user_id, 'af_admin_nationality', $nationality );
		update_user_meta( $user_id, 'af_admin_birth_city', $birth_city );

		update_user_meta( $user_id, 'af_admin_email_verified', 0 );
		update_user_meta( $user_id, 'af_admin_terms_accepted_at', current_time( 'mysql' ) );
		update_user_meta( $user_id, 'af_admin_terms_version', '2026-09-owasp-hardened' );
		Arriendo_Facil_Consent::record( 'admin_signup', $user_id, 'user', $user_id );

		update_user_meta( $user_id, 'af_signup_source', 'self' );
		update_user_meta( $user_id, 'af_license_status', 'active' );
		Arriendo_Facil_Plans::set_plan( $user_id, 'free', array( 'reason' => 'signup' ) );
		update_user_meta( $user_id, 'af_admin_doc_status', 'pendiente' );
		update_user_meta( $user_id, 'af_admin_doc_verified_by', 0 );
		update_user_meta( $user_id, 'af_admin_identity_match_status', 'not_checked' );
		update_user_meta( $user_id, 'af_admin_onboarding_step', 'email' );

		$verification_token = $this->create_admin_email_verification_token( $user_id );
		if ( is_wp_error( $verification_token ) ) {
			$this->log_signup_security_event( 'verification_token_generation_failed', $email );
			$this->delete_admin_user_safely( $user_id );
			wp_send_json_error( array( 'message' => __( 'No se pudo finalizar el registro seguro. Intenta nuevamente.', 'arriendo-facil' ) ), 500 );
		}

		if ( ! $this->send_admin_email_verification_email( $user_id, (string) $verification_token ) ) {
			$this->log_signup_security_event( 'verification_email_failed', $email );
			$this->delete_admin_user_safely( $user_id );
			wp_send_json_error( array( 'message' => __( 'No se pudo enviar el correo de verificación. Intenta nuevamente en unos minutos.', 'arriendo-facil' ) ), 500 );
		}

		$this->notify_team_new_signup( $user_id, $email );

		$this->log_signup_security_event( 'signup_pending_verification', $email );

		wp_send_json_success(
			array(
				'user_id'               => (int) $user_id,
				'login_url'             => wp_login_url( admin_url() ),
				'should_redirect'       => false,
				'requires_verification' => true,
				'message'               => __( 'Te enviamos un correo para verificar tu cuenta. Debes confirmar ese enlace antes de iniciar sesión.', 'arriendo-facil' ),
			)
		);
	}

	/**
	 * AJAX: resends the property-admin verification email (rate limited).
	 *
	 * @return void
	 */
	public function ajax_resend_admin_verification_email() {
		if ( false === check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'La sesión expiró. Recarga la página e intenta nuevamente.', 'arriendo-facil' ) ), 403 );
		}

		$email = isset( $_POST['email'] ) ? AF_Text_Normalizer::email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Ingresa un correo válido para reenviar la verificación.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->resend_verification_within_rate_limit( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Espera unos minutos antes de volver a solicitar el reenvío.', 'arriendo-facil' ) ), 429 );
		}

		$user = get_user_by( 'email', $email );
		if ( ! ( $user instanceof WP_User ) || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			$this->log_signup_security_event( 'resend_verification_email_unknown_account', $email );
			wp_send_json_success( array( 'message' => __( 'Si tu cuenta está pendiente de verificación, te enviaremos un nuevo correo. Si ya la verificaste, puedes iniciar sesión.', 'arriendo-facil' ) ) );
		}

		if ( 1 === (int) get_user_meta( $user->ID, 'af_admin_email_verified', true ) ) {
			wp_send_json_success( array( 'message' => __( 'Si tu cuenta está pendiente de verificación, te enviaremos un nuevo correo. Si ya la verificaste, puedes iniciar sesión.', 'arriendo-facil' ) ) );
		}

		$token = $this->create_admin_email_verification_token( (int) $user->ID );
		if ( is_wp_error( $token ) ) {
			$this->log_signup_security_event( 'resend_verification_token_generation_failed', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo generar el correo de verificación. Intenta nuevamente.', 'arriendo-facil' ) ), 500 );
		}

		if ( ! $this->send_admin_email_verification_email( (int) $user->ID, (string) $token ) ) {
			$this->log_signup_security_event( 'resend_verification_email_failed', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo enviar el correo en este momento. Intenta nuevamente en unos minutos.', 'arriendo-facil' ) ), 500 );
		}

		$this->log_signup_security_event( 'resend_verification_email_success', $email );

		wp_send_json_success( array( 'message' => __( 'Te enviamos un nuevo correo de verificación. Revisa tu bandeja de entrada.', 'arriendo-facil' ) ) );
	}

	/**
	 * Handles the email-verification callback from the login endpoint.
	 *
	 * @return void
	 */
	public function handle_email_verification_request() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( self::VERIFY_ACTION !== $action ) {
			return;
		}

		$user_id = isset( $_GET['uid'] ) ? absint( wp_unslash( $_GET['uid'] ) ) : 0;
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$status = 'invalid';
		if ( $user_id && '' !== $token ) {
			$result = $this->verify_admin_email_token( $user_id, $token );
			$status = is_wp_error( $result ) ? 'failed' : 'ok';
		}

		wp_safe_redirect( add_query_arg( 'af_verify_admin', $status, wp_login_url( admin_url() ) ) );
		exit;
	}

	/**
	 * Marks email as verified when the token matches.
	 *
	 * @param int    $user_id Admin user ID.
	 * @param string $token Raw verification token.
	 * @return true|WP_Error
	 */
	private function verify_admin_email_token( $user_id, $token ) {
		$user_id = absint( $user_id );
		$token   = (string) $token;

		if ( ! $user_id || '' === $token ) {
			return new WP_Error( 'af_invalid_verification_request', __( 'Solicitud de verificación inválida.', 'arriendo-facil' ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			return new WP_Error( 'af_verification_user_not_found', __( 'No se encontró el usuario a verificar.', 'arriendo-facil' ) );
		}

		$stored_hash  = (string) get_user_meta( $user_id, 'af_admin_email_verify_hash', true );
		$expires_at   = (int) get_user_meta( $user_id, 'af_admin_email_verify_expires', true );

		if ( '' === $stored_hash ) {
			return new WP_Error( 'af_no_verification_request', __( 'No hay una verificación pendiente para este usuario.', 'arriendo-facil' ) );
		}

		if ( time() > $expires_at ) {
			return new WP_Error( 'af_verification_token_expired', __( 'El enlace de verificación venció. Solicita un reenvío.', 'arriendo-facil' ) );
		}

		$expected_hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) . 'af_admin_email_verify_v1' );
		if ( ! hash_equals( (string) $stored_hash, (string) $expected_hash ) ) {
			return new WP_Error( 'af_verification_token_mismatch', __( 'El enlace de verificación no es válido.', 'arriendo-facil' ) );
		}

		update_user_meta( $user_id, 'af_admin_email_verified', 1 );
		delete_user_meta( $user_id, 'af_admin_email_verify_hash' );
		delete_user_meta( $user_id, 'af_admin_email_verify_expires' );

		update_user_meta( $user_id, 'af_admin_onboarding_step', 'profile' );

		return true;
	}

	/**
	 * Blocks unverified self-registered admins from logging in.
	 *
	 * @param WP_User|WP_Error|null $user Prior authenticate result.
	 * @param string                $username Submitted login/email.
	 * @param string                $password Submitted password.
	 * @return WP_User|WP_Error|null
	 */
	public function enforce_email_verification_on_login( $user, $username, $password ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		if ( ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			return $user;
		}

		if ( 'self' !== get_user_meta( $user->ID, 'af_signup_source', true ) ) {
			return $user;
		}

		$verified = (int) get_user_meta( $user->ID, 'af_admin_email_verified', true );
		if ( 1 === $verified ) {
			return $user;
		}

		$this->log_signup_security_event( 'blocked_unverified_login', (string) $user->user_email );

		return new WP_Error(
			'af_admin_email_pending',
			__( 'Tu correo aún no ha sido verificado. Revisa tu bandeja de entrada y usa el enlace enviado, o solicita un reenvío desde la página de registro.', 'arriendo-facil' )
		);
	}

	/**
	 * Daily cron: reminds prospects and the team about pending onboarding.
	 *
	 * @return void
	 */
	public function dispatch_profile_reminders() {
		global $wpdb;

		$self_signups = get_users(
			array(
				'role'      => 'af_property_admin',
				'fields'    => 'ids',
				'meta_key'  => 'af_signup_source',
				'meta_value' => 'self',
				'number'    => 500,
			)
		);

		if ( empty( $self_signups ) ) {
			return;
		}

		$team_needs_nudge = false;

		foreach ( $self_signups as $user_id ) {
			$user_id = (int) $user_id;
			$verified = (int) get_user_meta( $user_id, 'af_admin_email_verified', true );
			$created_at = get_user_meta( $user_id, 'af_admin_terms_accepted_at', true );
			$age_days   = $this->days_since( $created_at );

			if ( 0 === $verified ) {
				if ( $age_days >= 1 && ! get_option( 'af_admin_pending_email_reminder_' . $user_id ) ) {
					$this->send_pending_email_reminder( $user_id );
					update_option( 'af_admin_pending_email_reminder_' . $user_id, time() );
				}
				continue;
			}

			$doc_status = get_user_meta( $user_id, 'af_admin_doc_status', true );
			if ( in_array( $doc_status, array( 'pendiente', 'en_revision' ), true ) && 0 === $age_days ) {
				$team_needs_nudge = true;
			}

			if ( in_array( $doc_status, array( 'pendiente', 'en_revision' ), true ) ) {
				$last_reminded = (int) get_option( 'af_admin_profile_reminder_' . $user_id );
				$due           = (int) ( $age_days >= 10 ? 1 : ( $age_days >= 3 ? 2 : 0 ) );
				if ( $due >= 2 && ( time() - $last_reminded ) > DAY_IN_SECONDS ) {
					$this->send_profile_reminder( $user_id, $age_days );
					update_option( 'af_admin_profile_reminder_' . $user_id, time() );
				}
			}
		}

		if ( $team_needs_nudge && ! get_option( 'af_admin_operator_documents_reminder' ) ) {
			$this->send_team_documents_reminder( 'weekly' );
			update_option( 'af_admin_operator_documents_reminder', time() );
		}
	}

	/**
	 * Builds the email-verification token for a self-registered admin.
	 *
	 * @param int $user_id Admin user ID.
	 * @return string|WP_Error
	 */
	private function create_admin_email_verification_token( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'af_invalid_user', __( 'Usuario inválido para verificación.', 'arriendo-facil' ) );
		}

		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			return new WP_Error( 'af_token_entropy_failed', __( 'No se pudo generar un token seguro.', 'arriendo-facil' ) );
		}

		$token_hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) . 'af_admin_email_verify_v1' );
		$expires_at = time() + ( 24 * HOUR_IN_SECONDS );

		update_user_meta( $user_id, 'af_admin_email_verify_hash', $token_hash );
		update_user_meta( $user_id, 'af_admin_email_verify_expires', (int) $expires_at );

		return $token;
	}

	/**
	 * Sends the email-verification message to a self-registered admin.
	 *
	 * @param int    $user_id Admin user ID.
	 * @param string $token Raw verification token.
	 * @return bool
	 */
	private function send_admin_email_verification_email( $user_id, $token ) {
		$user_id = absint( $user_id );
		$token   = (string) $token;

		if ( ! $user_id || '' === $token ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || empty( $user->user_email ) || ! is_email( (string) $user->user_email ) ) {
			return false;
		}

		$recipient  = sanitize_email( (string) $user->user_email );
		$display    = sanitize_text_field( (string) $user->display_name );
		$verify_url = add_query_arg(
			array(
				'action' => self::VERIFY_ACTION,
				'uid'    => (int) $user_id,
				'token'  => rawurlencode( $token ),
			),
			wp_login_url( admin_url() )
		);

		$subject = __( '[Arriendo Fácil] Verifica tu correo para activar tu cuenta', 'arriendo-facil' );

		return Arriendo_Facil_Email::send(
			$recipient,
			$subject,
			array(
				'preheader' => __( 'Confirma tu correo para activar tu cuenta.', 'arriendo-facil' ),
				'eyebrow'   => __( 'Gestor de propiedades', 'arriendo-facil' ),
				'title'     => __( 'Verifica tu correo para activar tu cuenta', 'arriendo-facil' ),
				'greeting'  => sprintf(
					/* translators: %s: user display name */
					__( 'Hola %s,', 'arriendo-facil' ),
					'' !== $display ? $display : __( 'gestor', 'arriendo-facil' )
				),
				'body_text' => __( 'Recibimos tu solicitud de registro como gestor de propiedades.', 'arriendo-facil' ) . "\n\n" . __( 'Por seguridad, confirma que este correo te pertenece para activar tu cuenta.', 'arriendo-facil' ),
				'button'    => array(
					'label' => __( 'Verificar mi correo', 'arriendo-facil' ),
					'url'   => $verify_url,
				),
				'notice'    => array(
					'type' => 'info',
					'text' => __( 'Este enlace vence en 24 horas. Si no solicitaste esta cuenta, ignora este mensaje: no se activará nada.', 'arriendo-facil' ),
				),
			),
			Arriendo_Facil_Email::CAT_TRANSACTIONAL
		);
	}

	/**
	 * Notifies the internal team about a new self-registration.
	 *
	 * @param int    $user_id New admin user ID.
	 * @param string $email New admin email.
	 * @return void
	 */
	private function notify_team_new_signup( $user_id, $email ) {
		$admins = get_users(
			array(
				'role__in' => array( 'administrator' ),
				'fields'   => 'user_email',
				'number'   => 10,
			)
		);

		if ( empty( $admins ) ) {
			return;
		}

		$admin_url = admin_url( 'admin.php?page=af-property-admins' );
		$subject   = __( '[Arriendo Fácil] Nuevo registro: ' . sanitize_text_field( $email ), 'arriendo-facil' );

		foreach ( array_unique( array_filter( $admins ) ) as $admin_email ) {
			Arriendo_Facil_Email::send(
				sanitize_email( $admin_email ),
				$subject,
				array(
					'title'     => __( 'Nuevo registro de gestor', 'arriendo-facil' ),
					'status'    => array( 'type' => 'review', 'label' => __( 'Por revisar', 'arriendo-facil' ) ),
					'body_text' => __( 'Se registró una nueva cuenta de gestor de propiedades.', 'arriendo-facil' ),
					'details'   => array( __( 'Correo', 'arriendo-facil' ) => sanitize_text_field( $email ) ),
					'button'    => array(
						'label' => __( 'Revisar gestores', 'arriendo-facil' ),
						'url'   => $admin_url,
					),
				),
				Arriendo_Facil_Email::CAT_INTERNAL
			);
		}
	}

	/**
	 * Sends the pending-email reminder to a self-registered admin.
	 *
	 * @param int $user_id Admin user ID.
	 * @return void
	 */
	private function send_pending_email_reminder( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$subject = __( '[Arriendo Fácil] Falta verificar tu correo para activar tu cuenta', 'arriendo-facil' );
		Arriendo_Facil_Email::send(
			sanitize_email( (string) $user->user_email ),
			$subject,
			array(
				'title'     => __( 'Falta verificar tu correo', 'arriendo-facil' ),
				'greeting'  => sprintf(
					/* translators: %s: user display name */
					__( 'Hola %s,', 'arriendo-facil' ),
					sanitize_text_field( (string) $user->display_name )
				),
				'body_text' => __( 'Tu cuenta está creada, pero aún falta verificar tu correo. Busca el enlace que te enviamos (vence en 24 horas) o solicita un reenvío en la página de registro.', 'arriendo-facil' ),
				'button'    => array(
					'label' => __( 'Ir al registro', 'arriendo-facil' ),
					'url'   => wp_login_url( admin_url() ),
				),
			),
			Arriendo_Facil_Email::CAT_NOTIFICATION
		);
	}

	/**
	 * Sends a profile-completion reminder to a self-registered admin.
	 *
	 * @param int $user_id Admin user ID.
	 * @param int $age_days Days since signup.
	 * @return void
	 */
	private function send_profile_reminder( $user_id, $age_days ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$profile_url = admin_url( 'admin.php?page=af-admin-profile' );
		$subject     = __( '[Arriendo Fácil] Completa tu perfil de gestor', 'arriendo-facil' );

		Arriendo_Facil_Email::send(
			sanitize_email( (string) $user->user_email ),
			$subject,
			array(
				'title'     => __( 'Completa tu perfil de gestor', 'arriendo-facil' ),
				'greeting'  => sprintf(
					/* translators: %s: user display name */
					__( 'Hola %s,', 'arriendo-facil' ),
					sanitize_text_field( (string) $user->display_name )
				),
				'body_text' => __( 'Completa tu perfil y sube tus documentos de identificación para verificar tu cuenta de Arriendo Fácil.', 'arriendo-facil' ),
				'button'    => array(
					'label' => __( 'Completar perfil', 'arriendo-facil' ),
					'url'   => $profile_url,
				),
			),
			Arriendo_Facil_Email::CAT_NOTIFICATION
		);
	}

	/**
	 * Sends a global reminder to the team about pending document reviews.
	 *
	 * @param string $span Optional label for cadence.
	 * @return void
	 */
	private function send_team_documents_reminder( $span = 'daily' ) {
		$admins = get_users(
			array(
				'role__in' => array( 'administrator' ),
				'fields'   => 'user_email',
				'number'   => 10,
			)
		);

		if ( empty( $admins ) ) {
			return;
		}

		$subject = __( '[Arriendo Fácil] Documentos de gestores pendientes de revisión', 'arriendo-facil' );
		foreach ( array_unique( array_filter( $admins ) ) as $admin_email ) {
			Arriendo_Facil_Email::send(
				sanitize_email( $admin_email ),
				$subject,
				array(
					'title'     => __( 'Documentos de gestores por revisar', 'arriendo-facil' ),
					'status'    => array( 'type' => 'review', 'label' => __( 'En revisión', 'arriendo-facil' ) ),
					'body_text' => __( 'Hay cuentas auto-registradas esperando que su documentación sea revisada.', 'arriendo-facil' ),
					'button'    => array(
						'label' => __( 'Ir a Gestores', 'arriendo-facil' ),
						'url'   => admin_url( 'admin.php?page=af-property-admins' ),
					),
				),
				Arriendo_Facil_Email::CAT_INTERNAL
			);
		}
	}

	/**
	 * Approximates days elapsed from a MySQL timestamp.
	 *
	 * @param string $mysql DateTime string.
	 * @return int
	 */
	private function days_since( $mysql ) {
		if ( empty( $mysql ) ) {
			return PHP_INT_MAX;
		}

		$time = strtotime( (string) $mysql );
		if ( ! $time || $time <= 0 ) {
			return PHP_INT_MAX;
		}

		return (int) floor( ( time() - $time ) / DAY_IN_SECONDS );
	}

	/**
	 * Anti-bot: rate limit by IP/UA and by email.
	 *
	 * @param string $email Candidate email.
	 * @return bool
	 */
	private function signup_within_rate_limit( $email = '' ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';
		$key = 'af_admin_signup_rl_' . substr( md5( (string) $ip . '|' . (string) $ua ), 0, 16 );

		$count = (int) get_transient( $key );
		if ( $count >= 6 ) {
			$this->log_signup_security_event( 'rate_limit_exceeded', '' );
			return false;
		}

		if ( 0 === $count ) {
			set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		} else {
			set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		}

		$email = sanitize_email( (string) $email );
		if ( '' !== $email ) {
			$email_key   = 'af_admin_signup_email_rl_' . substr( md5( strtolower( $email ) ), 0, 16 );
			$email_count = (int) get_transient( $email_key );
			if ( $email_count >= 4 ) {
				$this->log_signup_security_event( 'email_rate_limit_exceeded', $email );
				return false;
			}

			if ( 0 === $email_count ) {
				set_transient( $email_key, 1, 20 * MINUTE_IN_SECONDS );
			} else {
				set_transient( $email_key, $email_count + 1, 20 * MINUTE_IN_SECONDS );
			}
		}

		return true;
	}

	/**
	 * Anti-bot: rate limit for verification resend.
	 *
	 * @param string $email Candidate email.
	 * @return bool
	 */
	private function resend_verification_within_rate_limit( $email ) {
		$email = sanitize_email( (string) $email );
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';

		$ip_key   = 'af_admin_resend_rl_' . substr( md5( (string) $ip . '|' . (string) $ua ), 0, 16 );
		$ip_count = (int) get_transient( $ip_key );
		if ( $ip_count >= 6 ) {
			$this->log_signup_security_event( 'resend_rate_limit_ip', $email );
			return false;
		}

		set_transient( $ip_key, $ip_count + 1, 15 * MINUTE_IN_SECONDS );

		if ( '' !== $email ) {
			$email_key   = 'af_admin_resend_email_rl_' . substr( md5( strtolower( $email ) ), 0, 16 );
			$email_count = (int) get_transient( $email_key );
			if ( $email_count >= 3 ) {
				$this->log_signup_security_event( 'resend_rate_limit_email', $email );
				return false;
			}

			set_transient( $email_key, $email_count + 1, 30 * MINUTE_IN_SECONDS );
		}

		return true;
	}

	/**
	 * Validates the signed anti-bot form proof.
	 *
	 * @param int    $form_ts Form timestamp.
	 * @param string $form_sig HMAC signature.
	 * @return bool
	 */
	private function validate_form_proof( $form_ts, $form_sig ) {
		$form_ts  = absint( $form_ts );
		$form_sig = (string) $form_sig;

		if ( ! $form_ts || '' === $form_sig ) {
			return false;
		}

		$expected_sig = hash_hmac( 'sha256', (string) $form_ts, wp_salt( 'nonce' ) . self::FORM_SECRET_SLUG );
		if ( ! hash_equals( (string) $expected_sig, $form_sig ) ) {
			return false;
		}

		$age = time() - $form_ts;
		if ( $age < 2 || $age > 2 * HOUR_IN_SECONDS ) {
			return false;
		}

		return true;
	}

	/**
	 * Builds a deterministic fingerprint for the admin identity document.
	 *
	 * @param string $id_number Identity value.
	 * @return string
	 */
	private function build_identity_fingerprint( $id_number ) {
		$normalized = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $id_number ) );
		if ( '' === $normalized ) {
			return '';
		}

		return Arriendo_Facil_Crypto::fingerprint( $normalized, 'admin-id-fp', wp_salt( 'secure_auth' ) . 'af_admin_id_fp_v1' );
	}

	/**
	 * Fingerprint as computed before AF_ENCRYPTION_KEY existed (salt-derived), for duplicate checks
	 * against accounts that have not been re-keyed yet.
	 *
	 * @param string $id_number Identity value.
	 * @return string
	 */
	private function build_identity_fingerprint_legacy( $id_number ) {
		$normalized = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $id_number ) );

		return '' === $normalized ? '' : hash_hmac( 'sha256', $normalized, wp_salt( 'secure_auth' ) . 'af_admin_id_fp_v1' );
	}

	/**
	 * Checks whether the admin identity fingerprint is already in use.
	 *
	 * @param string $fingerprint Fingerprint hash.
	 * @return bool
	 */
	private function identity_fingerprint_exists( $fingerprint ) {
		$fingerprint = sanitize_text_field( (string) $fingerprint );
		if ( '' === $fingerprint ) {
			return false;
		}

		$users = get_users(
			array(
				'number'     => 1,
				'fields'     => 'ids',
				'meta_key'   => 'af_admin_id_fingerprint',
				'meta_value' => $fingerprint,
			)
		);

		return ! empty( $users );
	}

	/**
	 * Prints the show/hide toggle button for a password input.
	 */
	private function render_password_reveal_button() {
		?>
		<button type="button" class="af-admin-signup__reveal" data-af-reveal aria-pressed="false" aria-label="<?php echo esc_attr__( 'Mostrar contraseña', 'arriendo-facil' ); ?>" data-label-show="<?php echo esc_attr__( 'Mostrar contraseña', 'arriendo-facil' ); ?>" data-label-hide="<?php echo esc_attr__( 'Ocultar contraseña', 'arriendo-facil' ); ?>">
			<svg class="af-admin-signup__eye--off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
			<svg class="af-admin-signup__eye--on" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
		</button>
		<?php
	}

	/**
	 * Validates password against the shared admin policy.
	 *
	 * @param string $password Plain password.
	 * @return string[]
	 */
	private function validate_password_policy( $password ) {
		$password = (string) $password;
		$errors   = array();

		if ( strlen( $password ) < 10 ) {
			$errors[] = __( 'La contraseña debe tener mínimo 10 caracteres.', 'arriendo-facil' );
		}

		if ( ! preg_match( '/[A-Z]/', $password ) ) {
			$errors[] = __( 'Incluye al menos una letra mayúscula.', 'arriendo-facil' );
		}

		if ( ! preg_match( '/[a-z]/', $password ) ) {
			$errors[] = __( 'Incluye al menos una letra minúscula.', 'arriendo-facil' );
		}

		if ( ! preg_match( '/[0-9]/', $password ) ) {
			$errors[] = __( 'Incluye al menos un número.', 'arriendo-facil' );
		}

		if ( ! preg_match( '/[^A-Za-z0-9]/', $password ) ) {
			$errors[] = __( 'Incluye al menos un símbolo especial (ej: !@#$%).', 'arriendo-facil' );
		}

		return $errors;
	}

	/**
	 * Encrypts sensitive admin data using libsodium secretbox.
	 *
	 * @param string $value Sensitive value.
	 * @return string
	 */
	private function encrypt_sensitive_value( $value ) {
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return '';
		}

		if ( Arriendo_Facil_Crypto::has_master() ) {
			$sealed = Arriendo_Facil_Crypto::seal( $value, Arriendo_Facil_Crypto::PURPOSE_ADMIN );
			if ( '' !== $sealed ) {
				return $sealed;
			}
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) || ! function_exists( 'random_bytes' ) ) {
			return '';
		}

		try {
			$key_material = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . 'af_admin_sensitive_v1', true );
			if ( ! is_string( $key_material ) || 32 !== strlen( $key_material ) ) {
				return '';
			}

			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $value, $nonce, $key_material );

			return 'v1:' . base64_encode( $nonce . $ciphertext );
		} catch ( Exception $exception ) {
			error_log( '[AF Security] admin sensitive encryption failure: ' . $exception->getMessage() );
			return '';
		}
	}

	/**
	 * Logs security-significant admin signup events.
	 *
	 * @param string $event Event key.
	 * @param string $email Optional email.
	 * @return void
	 */
	private function log_signup_security_event( $event, $email = '' ) {
		$ip          = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$email       = sanitize_email( (string) $email );
		$email_fprint = '' !== $email ? hash( 'sha256', strtolower( $email ) ) : '';
		error_log( sprintf( '[AF Security] admin_signup event=%s ip=%s email_fingerprint=%s', sanitize_key( $event ), $ip, $email_fprint ) );
	}

	/**
	 * Deletes the user safely in failure rollback paths.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function delete_admin_user_safely( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		if ( function_exists( 'wp_delete_user' ) ) {
			wp_delete_user( $user_id );
		}
	}
}