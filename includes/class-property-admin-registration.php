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
 *  4. Al verificar el correo se activa la cuenta y se siembra un dataset
 *     de datos de ejemplo aislado por prospecto (ver
 *     Arriendo_Facil_Property_Admin_Demo) para que explore el sistema.
 *  5. El prospecto completa su perfil (identidad y documentos), que el
 *     super admin debe revisar antes de dar acceso a datos reales.
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

		add_action( self::CRON_HOOK, array( $this, 'dispatch_profile_reminders' ) );
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
		$terms_page   = get_page_by_path( 'terminos-y-condiciones' );
		$terms_url    = $terms_page instanceof WP_Post ? get_permalink( $terms_page ) : home_url( '/terminos-y-condiciones/' );
		$privacy_url  = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : home_url( '/politica-de-privacidad/' );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $instance_id ); ?>" class="af-admin-signup">
			<div>
				<h3 class="af-admin-signup__title"><?php echo esc_html__( 'Crea tu cuenta y comienza a gestionar', 'arriendo-facil' ); ?></h3>
				<p class="af-admin-signup__subtitle"><?php echo esc_html__( 'Crea tu cuenta como administrador de propiedades. Verifica tu correo y empieza hoy con datos de ejemplo: cobros, contratos, mantenimiento e inquilinos.', 'arriendo-facil' ); ?></p>
			</div>

			<div data-af-admin-alert class="af-admin-signup__alert"></div>

			<form data-af-admin-form class="af-admin-signup__form">
				<div class="af-admin-signup__grid">
					<label class="af-admin-signup__field af-admin-signup__full">
						<span class="af-admin-signup__label"><?php echo esc_html__( 'Nombre de la empresa', 'arriendo-facil' ); ?></span>
						<input type="text" name="company_name" required maxlength="190" class="af-admin-signup__input" />
					</label>
					<label class="af-admin-signup__field af-admin-signup__full">
						<span class="af-admin-signup__label"><?php echo esc_html__( 'Nombre y apellido del responsable', 'arriendo-facil' ); ?></span>
						<input type="text" name="contact_name" required maxlength="190" class="af-admin-signup__input" />
					</label>
					<label class="af-admin-signup__field">
						<span class="af-admin-signup__label"><?php echo esc_html__( 'Correo electronico', 'arriendo-facil' ); ?></span>
						<input type="email" name="email" required maxlength="190" class="af-admin-signup__input" />
					</label>
					<label class="af-admin-signup__field">
						<span class="af-admin-signup__label"><?php echo esc_html__( 'Telefono', 'arriendo-facil' ); ?></span>
						<input type="text" name="phone" required maxlength="20" pattern="[0-9+\s-]{7,20}" class="af-admin-signup__input" />
					</label>
					<label class="af-admin-signup__field">
						<span class="af-admin-signup__label"><?php echo esc_html__( 'Tipo de identificacion', 'arriendo-facil' ); ?></span>
						<select name="id_type" required class="af-admin-signup__input">
							<option value=""><?php echo esc_html__( 'Selecciona', 'arriendo-facil' ); ?></option>
							<option value="cedula"><?php echo esc_html__( 'Cedula', 'arriendo-facil' ); ?></option>
							<option value="ruc"><?php echo esc_html__( 'RUC', 'arriendo-facil' ); ?></option>
							<option value="pasaporte"><?php echo esc_html__( 'Pasaporte', 'arriendo-facil' ); ?></option>
						</select>
					</label>
					<label class="af-admin-signup__field">
						<span class="af-admin-signup__label"><?php echo esc_html__( 'Numero de identificacion', 'arriendo-facil' ); ?></span>
						<input type="text" name="id_number" required maxlength="20" pattern="[A-Za-z0-9-]{5,20}" class="af-admin-signup__input" />
						<span class="af-admin-signup__hint" style="display:block;font-size:12px;line-height:1.4;color:#64748b;margin-top:4px;"><?php echo esc_html__( 'Cedula (10 digitos), RUC (13 digitos) o Pasaporte (6 a 12 caracteres alfanumericos).', 'arriendo-facil' ); ?></span>
					</label>
					<label class="af-admin-signup__field">
						<span class="af-admin-signup__label"><?php echo esc_html__( 'Nacionalidad', 'arriendo-facil' ); ?></span>
						<input type="text" name="nationality" required maxlength="100" class="af-admin-signup__input" value="Ecuador" />
					</label>
					<label class="af-admin-signup__field">
						<span class="af-admin-signup__label"><?php echo esc_html__( 'Ciudad de nacimiento', 'arriendo-facil' ); ?></span>
						<input type="text" name="birth_city" required maxlength="150" class="af-admin-signup__input" />
					</label>
					<label class="af-admin-signup__field">
						<span class="af-admin-signup__label"><?php echo esc_html__( 'Contrasena', 'arriendo-facil' ); ?></span>
						<input type="password" name="password" required minlength="10" maxlength="72" autocomplete="new-password" class="af-admin-signup__input" />
					</label>
					<label class="af-admin-signup__field">
						<span class="af-admin-signup__label"><?php echo esc_html__( 'Confirmar contrasena', 'arriendo-facil' ); ?></span>
						<input type="password" name="password_confirm" required minlength="10" maxlength="72" autocomplete="new-password" class="af-admin-signup__input" />
					</label>
				</div>

				<div class="af-admin-signup__password-hint">
					<strong><?php echo esc_html__( 'Requisitos de contrasena:', 'arriendo-facil' ); ?></strong>
					<?php echo esc_html__( 'minimo 10 caracteres, al menos 1 mayuscula, 1 minuscula, 1 numero y 1 simbolo (ej: !@#$%).', 'arriendo-facil' ); ?>
				</div>

				<input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="af-admin-signup__honeypot" />
				<input type="hidden" name="form_ts" value="<?php echo esc_attr( (string) $form_ts ); ?>" />
				<input type="hidden" name="form_sig" value="<?php echo esc_attr( (string) $form_sig ); ?>" />

				<label class="af-admin-signup__legal">
					<input type="checkbox" name="accept_terms" value="1" required class="af-admin-signup__checkbox" />
					<span>
						<?php
						echo wp_kses_post(
							sprintf(
								__( 'Acepto los <a href="%1$s" target="_blank" rel="noopener noreferrer">Terminos y Condiciones</a> y la <a href="%2$s" target="_blank" rel="noopener noreferrer">Politica de Privacidad</a> para el tratamiento de mis datos.', 'arriendo-facil' ),
								esc_url( $terms_url ),
								esc_url( $privacy_url )
							)
						);
						?>
					</span>
				</label>

				<div class="af-admin-signup__actions">
					<button type="submit" data-af-admin-submit class="af-admin-signup__submit">
						<?php echo esc_html__( 'Crear mi cuenta', 'arriendo-facil' ); ?>
					</button>
					<a class="af-admin-signup__link af-admin-signup__link--secondary" href="#" data-af-admin-resend><?php echo esc_html__( 'Reenviar correo de verificacion', 'arriendo-facil' ); ?></a>
					<a class="af-admin-signup__link af-admin-signup__link--primary" href="<?php echo esc_url( $login_url ); ?>"><?php echo esc_html__( 'Ya tengo cuenta, iniciar sesion', 'arriendo-facil' ); ?></a>
					<a class="af-admin-signup__link af-admin-signup__link--secondary" href="<?php echo esc_url( $recover_url ); ?>"><?php echo esc_html__( 'Recuperar contrasena', 'arriendo-facil' ); ?></a>
				</div>
			</form>
		</div>

		<script>
		(function(){
			const app = document.getElementById(<?php echo wp_json_encode( $instance_id ); ?>);
			if(!app){ return; }

			const ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			const nonce = <?php echo wp_json_encode( $nonce ); ?>;
			const form = app.querySelector('[data-af-admin-form]');
			const submitBtn = app.querySelector('[data-af-admin-submit]');
			const resendLink = app.querySelector('[data-af-admin-resend]');
			const alertBox = app.querySelector('[data-af-admin-alert]');
			const emailInput = form.querySelector('input[name="email"]');

			function showAlert(message, type){
				alertBox.style.display = 'block';
				alertBox.textContent = String(message || '');
				if(type === 'success'){
					alertBox.style.background = '#ecfdf5';
					alertBox.style.border = '1px solid #86efac';
					alertBox.style.color = '#166534';
				} else {
					alertBox.style.background = '#fef2f2';
					alertBox.style.border = '1px solid #fca5a5';
					alertBox.style.color = '#991b1b';
				}
			}

			function validatePasswordClientSide(password){
				const checks = [
					password.length >= 10,
					/[A-Z]/.test(password),
					/[a-z]/.test(password),
					/[0-9]/.test(password),
					/[^A-Za-z0-9]/.test(password)
				];
				return checks.every(Boolean);
			}

			form.addEventListener('submit', async function(e){
				e.preventDefault();

				const passwordInput = form.querySelector('input[name="password"]');
				const passwordConfirmInput = form.querySelector('input[name="password_confirm"]');

				if(!validatePasswordClientSide(String(passwordInput && passwordInput.value ? passwordInput.value : ''))){
					showAlert(<?php echo wp_json_encode( __( 'La contrasena no cumple los requisitos: minimo 10 caracteres, mayuscula, minuscula, numero y simbolo.', 'arriendo-facil' ) ); ?>, 'error');
					return;
				}

				if(String(passwordInput.value || '') !== String(passwordConfirmInput.value || '')){
					showAlert(<?php echo wp_json_encode( __( 'Las contrasenas no coinciden.', 'arriendo-facil' ) ); ?>, 'error');
					return;
				}

				submitBtn.disabled = true;
				alertBox.style.display = 'none';

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
					showAlert(<?php echo wp_json_encode( __( 'No se pudo conectar con el servidor. Intenta nuevamente.', 'arriendo-facil' ) ); ?>, 'error');
					return;
				}

				submitBtn.disabled = false;
				if(!json || !json.success){
					const message = json && json.data && json.data.message ? json.data.message : <?php echo wp_json_encode( __( 'No se pudo crear la cuenta.', 'arriendo-facil' ) ); ?>;
					showAlert(message, 'error');
					return;
				}

				showAlert((json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'Cuenta creada correctamente.', 'arriendo-facil' ) ); ?>, 'success');
				form.reset();
				if(json.data && json.data.should_redirect && json.data.login_url){
					setTimeout(function(){ window.location.href = String(json.data.login_url); }, 900);
				}
			});

			if(resendLink){
				resendLink.addEventListener('click', async function(e){
					e.preventDefault();

					const emailValue = String(emailInput && emailInput.value ? emailInput.value : '').trim();
					if(!emailValue || !/^\S+@\S+\.\S+$/.test(emailValue)){
						showAlert(<?php echo wp_json_encode( __( 'Ingresa un correo valido para reenviar la verificacion.', 'arriendo-facil' ) ); ?>, 'error');
						return;
					}

					resendLink.setAttribute('aria-disabled', 'true');
					resendLink.style.pointerEvents = 'none';
					resendLink.style.opacity = '0.6';

					const data = new URLSearchParams();
					data.set('action', 'af_resend_admin_verification_email');
					data.set('nonce', nonce);
					data.set('email', emailValue);

					let json;
					try {
						const response = await fetch(ajaxUrl, {
							method: 'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
							body: data.toString()
						});
						json = await response.json();
					} catch (err) {
						resendLink.removeAttribute('aria-disabled');
						resendLink.style.pointerEvents = '';
						resendLink.style.opacity = '';
						showAlert(<?php echo wp_json_encode( __( 'No se pudo reenviar el correo en este momento. Intenta nuevamente.', 'arriendo-facil' ) ); ?>, 'error');
						return;
					}

					setTimeout(function(){
						resendLink.removeAttribute('aria-disabled');
						resendLink.style.pointerEvents = '';
						resendLink.style.opacity = '';
					}, 1200);

					if(!json || !json.success){
						const message = json && json.data && json.data.message ? json.data.message : <?php echo wp_json_encode( __( 'No se pudo procesar el reenvio.', 'arriendo-facil' ) ); ?>;
						showAlert(message, 'error');
						return;
					}

					showAlert((json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'Si tu cuenta esta pendiente, te enviaremos un nuevo correo.', 'arriendo-facil' ) ); ?>, 'success');
				});
			}
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
			wp_send_json_error( array( 'message' => __( 'La sesion expiro. Recarga la pagina e intenta nuevamente.', 'arriendo-facil' ) ), 403 );
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
			wp_send_json_error( array( 'message' => __( 'No se pudo validar la solicitud. Recarga la pagina e intenta nuevamente.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Correo electronico invalido.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! in_array( $id_type, array( 'cedula', 'ruc', 'pasaporte' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Tipo de identificacion invalido.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->signup_within_rate_limit( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Demasiados intentos. Espera unos minutos e intenta nuevamente.', 'arriendo-facil' ) ), 429 );
		}

		if ( 1 !== $accept_terms ) {
			wp_send_json_error( array( 'message' => __( 'Debes aceptar el tratamiento de datos para crear la cuenta.', 'arriendo-facil' ) ), 400 );
		}

		if ( $password !== $password_confirm ) {
			wp_send_json_error( array( 'message' => __( 'Las contrasenas no coinciden.', 'arriendo-facil' ) ), 400 );
		}

		$password_policy_errors = $this->validate_password_policy( $password );
		if ( ! empty( $password_policy_errors ) ) {
			wp_send_json_error( array( 'message' => implode( ' ', $password_policy_errors ) ), 400 );
		}

		if ( ! class_exists( 'Arriendo_Facil_Identity_Validator' )
			|| ! Arriendo_Facil_Identity_Validator::validate( $id_type, $id_number ) ) {
			wp_send_json_error( array( 'message' => __( 'El numero de identificacion no es valido para el tipo seleccionado.', 'arriendo-facil' ) ), 400 );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error(
				array(
					'code'    => 'email_exists',
					'message' => __( 'Ese correo ya tiene una cuenta. Inicia sesion o recupera tu contrasena.', 'arriendo-facil' ),
				),
				409
			);
		}

		$encrypted_phone = $this->encrypt_sensitive_value( $phone );
		$encrypted_id    = $this->encrypt_sensitive_value( $id_number );
		if ( '' === $encrypted_phone || '' === $encrypted_id ) {
			$this->log_signup_security_event( 'encryption_failed', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo proteger la informacion sensible. Intenta nuevamente.', 'arriendo-facil' ) ), 500 );
		}

		$id_fingerprint = $this->build_identity_fingerprint( $id_number );
		if ( '' === $id_fingerprint || $this->identity_fingerprint_exists( $id_fingerprint ) ) {
			$this->log_signup_security_event( 'duplicate_identity_fingerprint', $email );
			wp_send_json_error(
				array(
					'code'    => 'identity_exists',
					'message' => __( 'La identificacion ya esta asociada a una cuenta. Si necesitas ayuda, contacta soporte.', 'arriendo-facil' ),
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
			wp_send_json_error( array( 'message' => __( 'No se pudo generar un usuario valido. Intenta con otro correo.', 'arriendo-facil' ) ), 500 );
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

		update_user_meta( $user_id, 'af_signup_source', 'self' );
		update_user_meta( $user_id, 'af_license_status', 'active' );
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
			wp_send_json_error( array( 'message' => __( 'No se pudo enviar el correo de verificacion. Intenta nuevamente en unos minutos.', 'arriendo-facil' ) ), 500 );
		}

		$this->notify_team_new_signup( $user_id, $email );

		$this->log_signup_security_event( 'signup_pending_verification', $email );

		wp_send_json_success(
			array(
				'user_id'               => (int) $user_id,
				'login_url'             => wp_login_url( admin_url() ),
				'should_redirect'       => false,
				'requires_verification' => true,
				'message'               => __( 'Te enviamos un correo para verificar tu cuenta. Debes confirmar ese enlace antes de iniciar sesion.', 'arriendo-facil' ),
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
			wp_send_json_error( array( 'message' => __( 'La sesion expiro. Recarga la pagina e intenta nuevamente.', 'arriendo-facil' ) ), 403 );
		}

		$email = isset( $_POST['email'] ) ? AF_Text_Normalizer::email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Ingresa un correo valido para reenviar la verificacion.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->resend_verification_within_rate_limit( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Espera unos minutos antes de volver a solicitar el reenvio.', 'arriendo-facil' ) ), 429 );
		}

		$user = get_user_by( 'email', $email );
		if ( ! ( $user instanceof WP_User ) || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			$this->log_signup_security_event( 'resend_verification_email_unknown_account', $email );
			wp_send_json_success( array( 'message' => __( 'Si tu cuenta esta pendiente de verificacion, te enviaremos un nuevo correo.', 'arriendo-facil' ) ) );
		}

		if ( 1 === (int) get_user_meta( $user->ID, 'af_admin_email_verified', true ) ) {
			wp_send_json_success( array( 'message' => __( 'Tu correo ya esta verificado. Puedes iniciar sesion.', 'arriendo-facil' ) ) );
		}

		$token = $this->create_admin_email_verification_token( (int) $user->ID );
		if ( is_wp_error( $token ) ) {
			$this->log_signup_security_event( 'resend_verification_token_generation_failed', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo generar el correo de verificacion. Intenta nuevamente.', 'arriendo-facil' ) ), 500 );
		}

		if ( ! $this->send_admin_email_verification_email( (int) $user->ID, (string) $token ) ) {
			$this->log_signup_security_event( 'resend_verification_email_failed', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo enviar el correo en este momento. Intenta nuevamente en unos minutos.', 'arriendo-facil' ) ), 500 );
		}

		$this->log_signup_security_event( 'resend_verification_email_success', $email );

		wp_send_json_success( array( 'message' => __( 'Te enviamos un nuevo correo de verificacion. Revisa tu bandeja de entrada.', 'arriendo-facil' ) ) );
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
	 * Marks email as verified when the token matches and seeds the demo.
	 *
	 * @param int    $user_id Admin user ID.
	 * @param string $token Raw verification token.
	 * @return true|WP_Error
	 */
	private function verify_admin_email_token( $user_id, $token ) {
		$user_id = absint( $user_id );
		$token   = (string) $token;

		if ( ! $user_id || '' === $token ) {
			return new WP_Error( 'af_invalid_verification_request', __( 'Solicitud de verificacion invalida.', 'arriendo-facil' ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			return new WP_Error( 'af_verification_user_not_found', __( 'No se encontro el usuario a verificar.', 'arriendo-facil' ) );
		}

		$stored_hash  = (string) get_user_meta( $user_id, 'af_admin_email_verify_hash', true );
		$expires_at   = (int) get_user_meta( $user_id, 'af_admin_email_verify_expires', true );

		if ( '' === $stored_hash ) {
			return new WP_Error( 'af_no_verification_request', __( 'No hay una verificacion pendiente para este usuario.', 'arriendo-facil' ) );
		}

		if ( time() > $expires_at ) {
			return new WP_Error( 'af_verification_token_expired', __( 'El enlace de verificacion vencio. Solicita un reenvio.', 'arriendo-facil' ) );
		}

		$expected_hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) . 'af_admin_email_verify_v1' );
		if ( ! hash_equals( (string) $stored_hash, (string) $expected_hash ) ) {
			return new WP_Error( 'af_verification_token_mismatch', __( 'El enlace de verificacion no es valido.', 'arriendo-facil' ) );
		}

		update_user_meta( $user_id, 'af_admin_email_verified', 1 );
		delete_user_meta( $user_id, 'af_admin_email_verify_hash' );
		delete_user_meta( $user_id, 'af_admin_email_verify_expires' );

		if ( ! get_user_meta( $user_id, 'af_demo_seeded', true )
			&& class_exists( 'Arriendo_Facil_Property_Admin_Demo' ) ) {
			$user_id_int = (int) $user_id;
			Arriendo_Facil_Property_Admin_Demo::seed_demo( $user_id_int );
		}

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
			__( 'Tu correo aun no ha sido verificado. Revisa tu bandeja de entrada y usa el enlace enviado, o solicita un reenvio desde la pagina de registro.', 'arriendo-facil' )
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
			return new WP_Error( 'af_invalid_user', __( 'Usuario invalido para verificacion.', 'arriendo-facil' ) );
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

		$subject = __( '[Arriendo Facil] Verifica tu correo para activar tu demo', 'arriendo-facil' );

		$message = '<div style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">';
		$message .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">';
		$message .= '<div style="padding:18px 22px;background:linear-gradient(135deg,#1d4ed8,#0ea5e9);color:#ffffff;">';
		$message .= '<h2 style="margin:0;font-size:20px;line-height:1.3;">' . esc_html__( 'Verifica tu correo para activar tu demo', 'arriendo-facil' ) . '</h2>';
		$message .= '</div>';
		$message .= '<div style="padding:22px;">';
		$message .= '<p style="margin:0 0 12px;line-height:1.6;">' . sprintf( esc_html__( 'Hola %s, recibimos tu solicitud de demo como administrador de propiedades.', 'arriendo-facil' ), esc_html( '' !== $display ? $display : __( 'administrador', 'arriendo-facil' ) ) ) . '</p>';
		$message .= '<p style="margin:0 0 16px;line-height:1.6;color:#334155;">' . esc_html__( 'Por seguridad, confirma que este correo te pertenece para activar tu cuenta y preparar tu area de demostracion.', 'arriendo-facil' ) . '</p>';
		$message .= '<p style="margin:0 0 16px;">';
		$message .= '<a href="' . esc_url( $verify_url ) . '" style="display:inline-block;padding:11px 16px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">' . esc_html__( 'Verificar mi correo', 'arriendo-facil' ) . '</a>';
		$message .= '</p>';
		$message .= '<p style="margin:0;color:#475569;line-height:1.6;">' . esc_html__( 'Este enlace vence en 24 horas. Si no solicitaste esta cuenta, ignora este mensaje.', 'arriendo-facil' ) . '</p>';
		$message .= '</div>';
		$message .= '</div>';
		$message .= '<p style="max-width:640px;margin:12px auto 0;font-size:12px;color:#64748b;text-align:center;">Arriendo Facil</p>';
		$message .= '</div>';

		return (bool) wp_mail( $recipient, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
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
		$subject   = __( '[Arriendo Facil] Nuevo registro: ' . sanitize_text_field( $email ), 'arriendo-facil' );

		$message = '<p style="margin:0 0 12px;line-height:1.6;">' . esc_html__( 'Se registro una nueva cuenta de administrador de propiedades:', 'arriendo-facil' ) . '</p>';
		$message .= '<p style="margin:0 0 16px;"><a href="' . esc_url( $admin_url ) . '" style="display:inline-block;padding:10px 16px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">' . esc_html__( 'Revisar administradores', 'arriendo-facil' ) . '</a></p>';

		foreach ( array_unique( array_filter( $admins ) ) as $admin_email ) {
			wp_mail( sanitize_email( $admin_email ), $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
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

		$subject = __( '[Arriendo Facil] Falta verificar tu correo para activar tu demo', 'arriendo-facil' );
		$message = sprintf(
			// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
			__( 'Hola %1$s, tu cuenta de demo esta creada pero aun falta verificar tu correo. Busca el enlace que te enviamos (vence en 24 horas) o solicita un reenvio en la pagina de registro.', 'arriendo-facil' ),
			esc_html( sanitize_text_field( (string) $user->display_name ) )
		);
		wp_mail( sanitize_email( (string) $user->user_email ), $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
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
		$subject     = __( '[Arriendo Facil] Completa tu perfil para usar la demo', 'arriendo-facil' );

		$message = '<p style="margin:0 0 12px;line-height:1.6;">' . sprintf( esc_html__( 'Hola %s, para aprovechar tu demo de Arriendo Facil completa tu perfil y sube tus documentos de identificacion.', 'arriendo-facil' ), esc_html( sanitize_text_field( (string) $user->display_name ) ) ) . '</p>';
		$message .= '<p style="margin:0 0 16px;"><a href="' . esc_url( $profile_url ) . '" style="display:inline-block;padding:10px 16px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">' . esc_html__( 'Completar perfil', 'arriendo-facil' ) . '</a></p>';

		wp_mail( sanitize_email( (string) $user->user_email ), $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
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

		$subject = __( '[Arriendo Facil] Documentos de administradores pendientes de revision', 'arriendo-facil' );
		$message = '<p style="margin:0 0 12px;line-height:1.6;">' . esc_html__( 'Hay cuentas auto-registradas que estan esperando que su documentacion sea revisada.', 'arriendo-facil' ) . '</p>';
		$message .= '<p style="margin:0 0 16px;"><a href="' . esc_url( admin_url( 'admin.php?page=af-property-admins' ) ) . '">' . esc_html__( 'Ir a Administradores', 'arriendo-facil' ) . '</a></p>';

		foreach ( array_unique( array_filter( $admins ) ) as $admin_email ) {
			wp_mail( sanitize_email( $admin_email ), $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
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

		return hash_hmac( 'sha256', $normalized, wp_salt( 'secure_auth' ) . 'af_admin_id_fp_v1' );
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
	 * Validates password against the shared admin policy.
	 *
	 * @param string $password Plain password.
	 * @return string[]
	 */
	private function validate_password_policy( $password ) {
		$password = (string) $password;
		$errors   = array();

		if ( strlen( $password ) < 10 ) {
			$errors[] = __( 'La contrasena debe tener minimo 10 caracteres.', 'arriendo-facil' );
		}

		if ( ! preg_match( '/[A-Z]/', $password ) ) {
			$errors[] = __( 'Incluye al menos una letra mayuscula.', 'arriendo-facil' );
		}

		if ( ! preg_match( '/[a-z]/', $password ) ) {
			$errors[] = __( 'Incluye al menos una letra minuscula.', 'arriendo-facil' );
		}

		if ( ! preg_match( '/[0-9]/', $password ) ) {
			$errors[] = __( 'Incluye al menos un numero.', 'arriendo-facil' );
		}

		if ( ! preg_match( '/[^A-Za-z0-9]/', $password ) ) {
			$errors[] = __( 'Incluye al menos un simbolo especial (ej: !@#$%).', 'arriendo-facil' );
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