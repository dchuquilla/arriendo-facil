<?php
/**
 * Public tenant account signup and e-mail verification.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Tenant_Signup
 */
class Arriendo_Facil_Tenant_Signup {

	/**
	 * Registers the shortcode, AJAX endpoints and login hooks.
	 */
	public function __construct() {
		add_shortcode( 'af_tenant_signup', array( $this, 'render_tenant_signup_shortcode' ) );
		add_action( 'wp_ajax_af_register_tenant_account', array( $this, 'ajax_register_tenant_account' ) );
		add_action( 'wp_ajax_nopriv_af_register_tenant_account', array( $this, 'ajax_register_tenant_account' ) );
		add_action( 'wp_ajax_af_resend_tenant_verification_email', array( $this, 'ajax_resend_tenant_verification_email' ) );
		add_action( 'wp_ajax_nopriv_af_resend_tenant_verification_email', array( $this, 'ajax_resend_tenant_verification_email' ) );
		add_action( 'login_init', array( $this, 'handle_tenant_email_verification_request' ) );
		add_filter( 'authenticate', array( $this, 'enforce_tenant_email_verification_on_login' ), 30, 3 );
		add_filter( 'login_message', array( $this, 'render_tenant_email_verification_login_notice' ) );
	}

	/**
	 * Renders a public tenant account signup flow.
	 *
	 * Usage: [af_tenant_signup]
	 *
	 * @return string
	 */
	public function render_tenant_signup_shortcode() {
		$ajax_url     = esc_url( admin_url( 'admin-ajax.php' ) );
		$nonce        = wp_create_nonce( 'af_guest_frontend_nonce' );
		$login_url    = wp_login_url( home_url( '/' ) );
		$recover_url  = wp_lostpassword_url();
		$instance_id  = 'af-tenant-signup-' . wp_rand( 1000, 999999 );
		$form_ts      = time();
		$form_sig     = hash_hmac( 'sha256', (string) $form_ts, wp_salt( 'nonce' ) . 'af_tenant_signup_form_v1' );
		$terms_page   = get_page_by_path( 'terminos-y-condiciones' );
		$terms_url    = $terms_page instanceof WP_Post ? get_permalink( $terms_page ) : home_url( '/terminos-y-condiciones/' );
		$privacy_url  = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : home_url( '/politica-de-privacidad/' );

		wp_enqueue_style(
			'af-tenant-signup',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-tenant-signup.css',
			array(),
			ARRIENDO_FACIL_VERSION
		);

		ob_start();
		?>
		<div id="<?php echo esc_attr( $instance_id ); ?>" class="af-tenant-signup">
			<div>
				<h3 class="af-tenant-signup__title"><?php echo esc_html__( 'Crea tu cuenta como inquilino', 'arriendo-facil' ); ?></h3>
				<p class="af-tenant-signup__subtitle"><?php echo esc_html__( 'Registra tus datos, define tu clave y gestiona tus visitas, contratos y reservas en un solo lugar.', 'arriendo-facil' ); ?></p>
			</div>

			<div data-af-signup-alert class="af-tenant-signup__alert"></div>

			<form data-af-signup-form class="af-tenant-signup__form">
				<div class="af-tenant-signup__grid">
					<label class="af-tenant-signup__field">
						<span class="af-tenant-signup__label"><?php echo esc_html__( 'Nombres', 'arriendo-facil' ); ?></span>
						<input type="text" name="first_name" required maxlength="100" class="af-tenant-signup__input" />
					</label>
					<label class="af-tenant-signup__field">
						<span class="af-tenant-signup__label"><?php echo esc_html__( 'Apellidos', 'arriendo-facil' ); ?></span>
						<input type="text" name="last_name" required maxlength="100" class="af-tenant-signup__input" />
					</label>
					<label class="af-tenant-signup__field af-tenant-signup__full">
						<span class="af-tenant-signup__label"><?php echo esc_html__( 'Correo electronico', 'arriendo-facil' ); ?></span>
						<input type="email" name="email" required maxlength="190" class="af-tenant-signup__input" />
					</label>
					<label class="af-tenant-signup__field">
						<span class="af-tenant-signup__label"><?php echo esc_html__( 'Telefono', 'arriendo-facil' ); ?></span>
						<input type="text" name="phone" required maxlength="20" pattern="[0-9+\s-]{7,20}" class="af-tenant-signup__input" />
					</label>
					<label class="af-tenant-signup__field">
						<span class="af-tenant-signup__label"><?php echo esc_html__( 'Cedula o identificacion', 'arriendo-facil' ); ?></span>
						<input type="text" name="id_number" required maxlength="20" pattern="[A-Za-z0-9-]{5,20}" class="af-tenant-signup__input" />
					</label>
					<label class="af-tenant-signup__field">
						<span class="af-tenant-signup__label"><?php echo esc_html__( 'Contrasena', 'arriendo-facil' ); ?></span>
						<input type="password" name="password" required minlength="10" maxlength="72" autocomplete="new-password" class="af-tenant-signup__input" />
					</label>
					<label class="af-tenant-signup__field">
						<span class="af-tenant-signup__label"><?php echo esc_html__( 'Confirmar contrasena', 'arriendo-facil' ); ?></span>
						<input type="password" name="password_confirm" required minlength="10" maxlength="72" autocomplete="new-password" class="af-tenant-signup__input" />
					</label>
				</div>

				<div class="af-tenant-signup__password-hint">
					<strong><?php echo esc_html__( 'Requisitos de contrasena:', 'arriendo-facil' ); ?></strong>
					<?php echo esc_html__( 'minimo 10 caracteres, al menos 1 mayuscula, 1 minuscula, 1 numero y 1 simbolo (ej: !@#$%).', 'arriendo-facil' ); ?>
				</div>

				<input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="af-tenant-signup__honeypot" />
				<input type="hidden" name="form_ts" value="<?php echo esc_attr( (string) $form_ts ); ?>" />
				<input type="hidden" name="form_sig" value="<?php echo esc_attr( (string) $form_sig ); ?>" />

				<label class="af-tenant-signup__legal">
					<input type="checkbox" name="accept_terms" value="1" required class="af-tenant-signup__checkbox" />
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

				<div class="af-tenant-signup__actions">
					<button type="submit" data-af-submit-signup class="af-tenant-signup__submit">
						<?php echo esc_html__( 'Crear cuenta', 'arriendo-facil' ); ?>
					</button>
					<a class="af-tenant-signup__link af-tenant-signup__link--secondary" href="#" data-af-resend-verification><?php echo esc_html__( 'Reenviar correo de verificacion', 'arriendo-facil' ); ?></a>
					<a class="af-tenant-signup__link af-tenant-signup__link--primary" href="<?php echo esc_url( $login_url ); ?>"><?php echo esc_html__( 'Ya tengo cuenta, iniciar sesion', 'arriendo-facil' ); ?></a>
					<a class="af-tenant-signup__link af-tenant-signup__link--secondary" href="<?php echo esc_url( $recover_url ); ?>"><?php echo esc_html__( 'Recuperar contrasena', 'arriendo-facil' ); ?></a>
				</div>
			</form>
		</div>

		<script>
		(function(){
			const app = document.getElementById(<?php echo wp_json_encode( $instance_id ); ?>);
			if(!app){ return; }

			const ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			const nonce = <?php echo wp_json_encode( $nonce ); ?>;
			const form = app.querySelector('[data-af-signup-form]');
			const submitBtn = app.querySelector('[data-af-submit-signup]');
			const resendLink = app.querySelector('[data-af-resend-verification]');
			const alertBox = app.querySelector('[data-af-signup-alert]');
			const passwordInput = form.querySelector('input[name="password"]');
			const passwordConfirmInput = form.querySelector('input[name="password_confirm"]');
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

			const firstInput = form.querySelector('input[name="first_name"]');
			if(firstInput){ firstInput.focus(); }

			form.addEventListener('submit', async function(e){
				e.preventDefault();

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
				data.set('action', 'af_register_tenant_account');
				data.set('nonce', nonce);

				let response;
				let json;
				try {
					response = await fetch(ajaxUrl, {
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
					data.set('action', 'af_resend_tenant_verification_email');
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
	 * AJAX: registers a tenant account using WordPress users table.
	 *
	 * @return void
	 */
	public function ajax_register_tenant_account() {
		if ( false === check_ajax_referer( 'af_guest_frontend_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'La sesion expiro. Recarga la pagina e intenta nuevamente.', 'arriendo-facil' ) ), 403 );
		}

		$first_name       = isset( $_POST['first_name'] ) ? AF_Text_Normalizer::proper_name( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name        = isset( $_POST['last_name'] ) ? AF_Text_Normalizer::proper_name( wp_unslash( $_POST['last_name'] ) ) : '';
		$email            = isset( $_POST['email'] ) ? AF_Text_Normalizer::email( wp_unslash( $_POST['email'] ) ) : '';
		$phone            = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$id_number        = isset( $_POST['id_number'] ) ? sanitize_text_field( wp_unslash( $_POST['id_number'] ) ) : '';
		$password         = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$password_confirm = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';
		$accept_terms     = isset( $_POST['accept_terms'] ) ? absint( wp_unslash( $_POST['accept_terms'] ) ) : 0;
		$website_honeypot = isset( $_POST['website'] ) ? sanitize_text_field( wp_unslash( $_POST['website'] ) ) : '';
		$form_ts          = isset( $_POST['form_ts'] ) ? absint( wp_unslash( $_POST['form_ts'] ) ) : 0;
		$form_sig         = isset( $_POST['form_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['form_sig'] ) ) : '';

		if ( '' === $first_name || '' === $last_name || '' === $email || '' === $phone || '' === $id_number || '' === $password || '' === $password_confirm ) {
			wp_send_json_error( array( 'message' => __( 'Completa todos los campos obligatorios.', 'arriendo-facil' ) ), 400 );
		}

		if ( '' !== trim( $website_honeypot ) ) {
			$this->log_tenant_signup_security_event( 'honeypot_triggered', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo procesar la solicitud.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->validate_tenant_signup_form_proof( $form_ts, $form_sig ) ) {
			$this->log_tenant_signup_security_event( 'invalid_form_proof', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo validar la solicitud. Recarga la pagina e intenta nuevamente.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Correo electronico invalido.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->tenant_signup_within_rate_limit( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Demasiados intentos. Espera unos minutos e intenta nuevamente.', 'arriendo-facil' ) ), 429 );
		}

		if ( 1 !== $accept_terms ) {
			wp_send_json_error( array( 'message' => __( 'Debes aceptar el tratamiento de datos para crear la cuenta.', 'arriendo-facil' ) ), 400 );
		}

		if ( $password !== $password_confirm ) {
			wp_send_json_error( array( 'message' => __( 'Las contrasenas no coinciden.', 'arriendo-facil' ) ), 400 );
		}

		$password_policy_errors = $this->validate_tenant_password_policy( $password );
		if ( ! empty( $password_policy_errors ) ) {
			wp_send_json_error( array( 'message' => implode( ' ', $password_policy_errors ) ), 400 );
		}

		$encrypted_phone = $this->encrypt_sensitive_tenant_value( $phone );
		$encrypted_id    = $this->encrypt_sensitive_tenant_value( $id_number );
		if ( '' === $encrypted_phone || '' === $encrypted_id ) {
			$this->log_tenant_signup_security_event( 'encryption_failed', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo proteger la informacion sensible. Intenta nuevamente.', 'arriendo-facil' ) ), 500 );
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

		$id_fingerprint = $this->build_tenant_identity_fingerprint( $id_number );
		if ( '' === $id_fingerprint ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo validar tu identificacion. Intenta nuevamente.', 'arriendo-facil' ) ), 400 );
		}

		if ( $this->tenant_identity_fingerprint_exists( $id_fingerprint ) ) {
			$this->log_tenant_signup_security_event( 'duplicate_identity_fingerprint', $email );
			wp_send_json_error(
				array(
					'code'    => 'identity_exists',
					'message' => __( 'La identificacion ya esta asociada a una cuenta. Si necesitas ayuda, contacta soporte.', 'arriendo-facil' ),
				),
				409
			);
		}

		$user_login_base = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $user_login_base ) {
			$user_login_base = 'tenant';
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
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
				'role'         => 'af_tenant',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ), 500 );
		}

		update_user_meta( $user_id, 'af_tenant_phone_enc', $encrypted_phone );
		update_user_meta( $user_id, 'af_tenant_id_number_enc', $encrypted_id );
		update_user_meta( $user_id, 'af_tenant_id_fingerprint', $id_fingerprint );
		update_user_meta( $user_id, 'af_tenant_email_verified', 0 );
		update_user_meta( $user_id, 'af_tenant_terms_accepted_at', current_time( 'mysql' ) );
		update_user_meta( $user_id, 'af_tenant_terms_version', '2026-08-owasp-hardened' );

		$schema_result = Arriendo_Facil_Guest::ensure_guest_extra_columns();
		if ( is_wp_error( $schema_result ) ) {
			wp_send_json_error( array( 'message' => $schema_result->get_error_message() ), 500 );
		}

		$this->upsert_guest_row_for_tenant( $user_id, $first_name, $last_name, $email, $phone, $id_number, $encrypted_phone, $encrypted_id );

		$verification_token = $this->create_tenant_email_verification_token( $user_id );
		if ( is_wp_error( $verification_token ) ) {
			$this->log_tenant_signup_security_event( 'verification_token_generation_failed', $email );
			$this->delete_tenant_user_safely( $user_id );
			wp_send_json_error( array( 'message' => __( 'No se pudo finalizar el registro seguro. Intenta nuevamente.', 'arriendo-facil' ) ), 500 );
		}

		if ( ! $this->send_tenant_email_verification_email( $user_id, (string) $verification_token ) ) {
			$this->log_tenant_signup_security_event( 'verification_email_failed', $email );
			$this->delete_tenant_user_safely( $user_id );
			wp_send_json_error( array( 'message' => __( 'No se pudo enviar el correo de verificacion. Intenta nuevamente en unos minutos.', 'arriendo-facil' ) ), 500 );
		}

		$tenant_dashboard_url = admin_url();
		$this->log_tenant_signup_security_event( 'signup_pending_verification', $email );

		wp_send_json_success(
			array(
				'user_id'                => (int) $user_id,
				'login_url'              => wp_login_url( $tenant_dashboard_url ),
				'should_redirect'        => false,
				'requires_verification'  => true,
				'message'                => __( 'Te enviamos un correo para verificar tu cuenta. Debes confirmar ese enlace antes de iniciar sesion.', 'arriendo-facil' ),
			)
		);
	}

	/**
	 * AJAX: resends tenant verification email.
	 *
	 * @return void
	 */
	public function ajax_resend_tenant_verification_email() {
		if ( false === check_ajax_referer( 'af_guest_frontend_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'La sesion expiro. Recarga la pagina e intenta nuevamente.', 'arriendo-facil' ) ), 403 );
		}

		$email = isset( $_POST['email'] ) ? AF_Text_Normalizer::email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Ingresa un correo valido para reenviar la verificacion.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->tenant_resend_verification_within_rate_limit( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Espera unos minutos antes de volver a solicitar el reenvio.', 'arriendo-facil' ) ), 429 );
		}

		$user = get_user_by( 'email', $email );
		if ( ! ( $user instanceof WP_User ) || ! in_array( 'af_tenant', (array) $user->roles, true ) ) {
			$this->log_tenant_signup_security_event( 'resend_verification_email_unknown_account', $email );
			wp_send_json_success(
				array(
					'message' => __( 'Si tu cuenta esta pendiente de verificacion, te enviaremos un nuevo correo.', 'arriendo-facil' ),
				)
			);
		}

		$is_verified = (int) get_user_meta( (int) $user->ID, 'af_tenant_email_verified', true );
		if ( 1 === $is_verified ) {
			wp_send_json_success(
				array(
					'message' => __( 'Si tu cuenta esta pendiente de verificacion, te enviaremos un nuevo correo.', 'arriendo-facil' ),
				)
			);
		}

		$token = $this->create_tenant_email_verification_token( (int) $user->ID );
		if ( is_wp_error( $token ) ) {
			$this->log_tenant_signup_security_event( 'resend_verification_token_generation_failed', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo procesar el reenvio en este momento. Intenta nuevamente.', 'arriendo-facil' ) ), 500 );
		}

		if ( ! $this->send_tenant_email_verification_email( (int) $user->ID, (string) $token ) ) {
			$this->log_tenant_signup_security_event( 'resend_verification_email_failed', $email );
			wp_send_json_error( array( 'message' => __( 'No se pudo enviar el correo ahora. Intenta nuevamente en unos minutos.', 'arriendo-facil' ) ), 500 );
		}

		$this->log_tenant_signup_security_event( 'resend_verification_email_success', $email );

		wp_send_json_success(
			array(
				'message' => __( 'Si tu cuenta esta pendiente de verificacion, te enviaremos un nuevo correo.', 'arriendo-facil' ),
			)
		);
	}

	/**
	 * Enforces a simple IP-based rate limit for tenant signup endpoint.
	 *
	 * @return bool
	 */
	private function tenant_signup_within_rate_limit( $email = '' ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';
		$key = 'af_tenant_signup_rl_' . substr( md5( (string) $ip . '|' . (string) $ua ), 0, 16 );

		$count = (int) get_transient( $key );
		if ( $count >= 6 ) {
			$this->log_tenant_signup_security_event( 'rate_limit_exceeded', '' );
			return false;
		}

		if ( 0 === $count ) {
			set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		} else {
			set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		}

		$email = sanitize_email( (string) $email );
		if ( '' !== $email ) {
			$email_key   = 'af_tenant_signup_email_rl_' . substr( md5( strtolower( $email ) ), 0, 16 );
			$email_count = (int) get_transient( $email_key );
			if ( $email_count >= 4 ) {
				$this->log_tenant_signup_security_event( 'email_rate_limit_exceeded', $email );
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
	 * Enforces anti-abuse rate limits for verification email resend endpoint.
	 *
	 * @param string $email Candidate email.
	 * @return bool
	 */
	private function tenant_resend_verification_within_rate_limit( $email ) {
		$email = sanitize_email( (string) $email );
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';

		$ip_key   = 'af_tenant_resend_rl_' . substr( md5( (string) $ip . '|' . (string) $ua ), 0, 16 );
		$ip_count = (int) get_transient( $ip_key );
		if ( $ip_count >= 6 ) {
			$this->log_tenant_signup_security_event( 'resend_rate_limit_ip', $email );
			return false;
		}

		set_transient( $ip_key, $ip_count + 1, 15 * MINUTE_IN_SECONDS );

		if ( '' !== $email ) {
			$email_key   = 'af_tenant_resend_email_rl_' . substr( md5( strtolower( $email ) ), 0, 16 );
			$email_count = (int) get_transient( $email_key );
			if ( $email_count >= 3 ) {
				$this->log_tenant_signup_security_event( 'resend_rate_limit_email', $email );
				return false;
			}

			set_transient( $email_key, $email_count + 1, 30 * MINUTE_IN_SECONDS );
		}

		return true;
	}

	/**
	 * Validates signed anti-bot form proof emitted by the rendered signup form.
	 *
	 * @param int    $form_ts Form unix timestamp.
	 * @param string $form_sig HMAC signature.
	 * @return bool
	 */
	private function validate_tenant_signup_form_proof( $form_ts, $form_sig ) {
		$form_ts  = absint( $form_ts );
		$form_sig = (string) $form_sig;

		if ( ! $form_ts || '' === $form_sig ) {
			return false;
		}

		$expected_sig = hash_hmac( 'sha256', (string) $form_ts, wp_salt( 'nonce' ) . 'af_tenant_signup_form_v1' );
		if ( ! hash_equals( (string) $expected_sig, $form_sig ) ) {
			return false;
		}

		$age = time() - $form_ts;
		if ( $age < 2 ) {
			return false;
		}

		if ( $age > 2 * HOUR_IN_SECONDS ) {
			return false;
		}

		return true;
	}

	/**
	 * Builds deterministic fingerprint for tenant identity document.
	 *
	 * @param string $id_number Identity value.
	 * @return string
	 */
	private function build_tenant_identity_fingerprint( $id_number ) {
		$normalized = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $id_number ) );
		if ( '' === $normalized ) {
			return '';
		}

		return hash_hmac( 'sha256', $normalized, wp_salt( 'secure_auth' ) . 'af_tenant_id_fp_v1' );
	}

	/**
	 * Checks whether tenant identity fingerprint is already in use.
	 *
	 * @param string $fingerprint Fingerprint hash.
	 * @return bool
	 */
	private function tenant_identity_fingerprint_exists( $fingerprint ) {
		$fingerprint = sanitize_text_field( (string) $fingerprint );
		if ( '' === $fingerprint ) {
			return false;
		}

		$users = get_users(
			array(
				'number'     => 1,
				'fields'     => 'ids',
				'meta_key'   => 'af_tenant_id_fingerprint',
				'meta_value' => $fingerprint,
			)
		);

		return ! empty( $users );
	}

	/**
	 * Creates or updates guest row associated to a tenant account.
	 *
	 * @param int    $user_id Tenant user ID.
	 * @param string $first_name First name.
	 * @param string $last_name Last name.
	 * @param string $email Email.
	 * @param string $phone Phone.
	 * @param string $id_number ID number.
	 * @param string $encrypted_phone Encrypted phone.
	 * @param string $encrypted_id Encrypted ID number.
	 * @return void
	 */
	private function upsert_guest_row_for_tenant( $user_id, $first_name, $last_name, $email, $phone, $id_number, $encrypted_phone = '', $encrypted_id = '' ) {
		global $wpdb;

		$masked_phone = $this->mask_sensitive_phone( $phone );
		$masked_id    = $this->mask_sensitive_id_number( $id_number );

		$existing_guest_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}af_guests WHERE email = %s ORDER BY id DESC LIMIT 1",
				$email
			)
		);

		if ( $existing_guest_id > 0 ) {
			$wpdb->update(
				$wpdb->prefix . 'af_guests',
				array(
					'user_id'             => (int) $user_id,
					'first_name'          => $first_name,
					'last_name'           => $last_name,
					'phone'               => $masked_phone,
					'id_number'           => $masked_id,
					'phone_encrypted'     => $encrypted_phone,
					'id_number_encrypted' => $encrypted_id,
				),
				array( 'id' => $existing_guest_id ),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$wpdb->insert(
			$wpdb->prefix . 'af_guests',
			array(
				'user_id'             => (int) $user_id,
				'first_name'          => $first_name,
				'last_name'           => $last_name,
				'email'               => $email,
				'phone'               => $masked_phone,
				'id_number'           => $masked_id,
				'phone_encrypted'     => $encrypted_phone,
				'id_number_encrypted' => $encrypted_id,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Validates password against tenant policy.
	 *
	 * @param string $password Plain password.
	 * @return string[]
	 */
	private function validate_tenant_password_policy( $password ) {
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
	 * Encrypts sensitive tenant data using libsodium secretbox.
	 *
	 * @param string $value Sensitive value.
	 * @return string
	 */
	private function encrypt_sensitive_tenant_value( $value ) {
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return '';
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) || ! function_exists( 'random_bytes' ) ) {
			return '';
		}

		try {
			$key_material = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . 'af_tenant_sensitive_v1', true );
			if ( ! is_string( $key_material ) || 32 !== strlen( $key_material ) ) {
				return '';
			}

			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $value, $nonce, $key_material );

			return 'v1:' . base64_encode( $nonce . $ciphertext );
		} catch ( Exception $exception ) {
			error_log( '[AF Security] tenant sensitive encryption failure: ' . $exception->getMessage() );
			return '';
		}
	}

	/**
	 * Masks sensitive phone value for non-sensitive display storage.
	 *
	 * @param string $phone Raw phone.
	 * @return string
	 */
	private function mask_sensitive_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( ! is_string( $digits ) || '' === $digits ) {
			return '';
		}

		$tail = strlen( $digits ) > 4 ? substr( $digits, -4 ) : $digits;
		return '***' . $tail;
	}

	/**
	 * Masks sensitive ID value for non-sensitive display storage.
	 *
	 * @param string $id_number Raw ID number.
	 * @return string
	 */
	private function mask_sensitive_id_number( $id_number ) {
		$clean = preg_replace( '/\s+/', '', (string) $id_number );
		if ( ! is_string( $clean ) || '' === $clean ) {
			return '';
		}

		$tail = strlen( $clean ) > 4 ? substr( $clean, -4 ) : $clean;
		return '***' . $tail;
	}

	/**
	 * Logs security-significant tenant signup events.
	 *
	 * @param string $event Event key.
	 * @param string $email Optional email.
	 * @return void
	 */
	private function log_tenant_signup_security_event( $event, $email = '' ) {
		$ip          = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$email       = sanitize_email( (string) $email );
		$email_fprint = '' !== $email ? hash( 'sha256', strtolower( $email ) ) : '';
		error_log( sprintf( '[AF Security] tenant_signup event=%s ip=%s email_fingerprint=%s', sanitize_key( $event ), $ip, $email_fprint ) );
	}

	/**
	 * Sends account-created confirmation email to tenant.
	 *
	 * @param int $user_id Tenant user ID.
	 * @return void
	 */
	private function send_tenant_account_created_email( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || empty( $user->user_email ) || ! is_email( (string) $user->user_email ) ) {
			return;
		}

		$recipient   = sanitize_email( (string) $user->user_email );
		$display     = sanitize_text_field( (string) $user->display_name );
		$login_url   = wp_login_url( admin_url() );
		$recover_url = wp_lostpassword_url();

		$subject = __( '[Arriendo Facil] Tu cuenta de inquilino fue creada', 'arriendo-facil' );

		$message = '<div style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">';
		$message .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">';
		$message .= '<div style="padding:18px 22px;background:linear-gradient(135deg,#0f766e,#0ea5a4);color:#ffffff;">';
		$message .= '<h2 style="margin:0;font-size:20px;line-height:1.3;">' . esc_html__( 'Cuenta creada con exito', 'arriendo-facil' ) . '</h2>';
		$message .= '</div>';
		$message .= '<div style="padding:22px;">';
		$message .= '<p style="margin:0 0 12px;line-height:1.6;">' . sprintf( esc_html__( 'Hola %s, tu cuenta de inquilino ya esta activa.', 'arriendo-facil' ), esc_html( '' !== $display ? $display : __( 'inquilino', 'arriendo-facil' ) ) ) . '</p>';
		$message .= '<p style="margin:0 0 16px;line-height:1.6;color:#334155;">' . esc_html__( 'Desde ahora puedes iniciar sesion para gestionar tus visitas, reservas y documentos del proceso de arriendo.', 'arriendo-facil' ) . '</p>';
		$message .= '<p style="margin:0 0 16px;">';
		$message .= '<a href="' . esc_url( $login_url ) . '" style="display:inline-block;padding:11px 16px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">' . esc_html__( 'Iniciar sesion', 'arriendo-facil' ) . '</a>';
		$message .= '</p>';
		$message .= '<p style="margin:0;color:#475569;line-height:1.6;">' . esc_html__( 'Si olvidaste tu contrasena, puedes restablecerla aqui:', 'arriendo-facil' ) . ' <a href="' . esc_url( $recover_url ) . '">' . esc_html__( 'Recuperar contrasena', 'arriendo-facil' ) . '</a></p>';
		$message .= '</div>';
		$message .= '</div>';
		$message .= '<p style="max-width:640px;margin:12px auto 0;font-size:12px;color:#64748b;text-align:center;">Arriendo Facil</p>';
		$message .= '</div>';

		wp_mail( $recipient, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Deletes user safely in contexts where wp_delete_user may not be loaded yet.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function delete_tenant_user_safely( $user_id ) {
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

	/**
	 * Generates and stores an email verification token for a tenant user.
	 *
	 * @param int $user_id Tenant user ID.
	 * @return string|WP_Error
	 */
	private function create_tenant_email_verification_token( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'af_invalid_user', __( 'Usuario invalido para verificacion.', 'arriendo-facil' ) );
		}

		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			return new WP_Error( 'af_token_entropy_failed', __( 'No se pudo generar un token seguro.', 'arriendo-facil' ) );
		}

		$token_hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) . 'af_tenant_email_verify_v1' );
		$expires_at = time() + ( 24 * HOUR_IN_SECONDS );

		update_user_meta( $user_id, 'af_tenant_email_verify_hash', $token_hash );
		update_user_meta( $user_id, 'af_tenant_email_verify_expires', (int) $expires_at );
		update_user_meta( $user_id, 'af_tenant_email_verify_requested_at', current_time( 'mysql' ) );

		return $token;
	}

	/**
	 * Sends tenant email verification message.
	 *
	 * @param int    $user_id Tenant user ID.
	 * @param string $token Raw verification token.
	 * @return bool
	 */
	private function send_tenant_email_verification_email( $user_id, $token ) {
		$user_id = absint( $user_id );
		$token   = (string) $token;

		if ( ! $user_id || '' === $token ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || empty( $user->user_email ) || ! is_email( (string) $user->user_email ) ) {
			return false;
		}

		$recipient = sanitize_email( (string) $user->user_email );
		$display   = sanitize_text_field( (string) $user->display_name );
		$verify_url = add_query_arg(
			array(
				'action' => 'af_verify_tenant_email',
				'uid'    => (int) $user_id,
				'token'  => rawurlencode( $token ),
			),
			wp_login_url( admin_url() )
		);

		$subject = __( '[Arriendo Facil] Verifica tu correo para activar tu cuenta', 'arriendo-facil' );

		$message = '<div style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">';
		$message .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">';
		$message .= '<div style="padding:18px 22px;background:linear-gradient(135deg,#1d4ed8,#0ea5e9);color:#ffffff;">';
		$message .= '<h2 style="margin:0;font-size:20px;line-height:1.3;">' . esc_html__( 'Verifica tu correo para activar tu cuenta', 'arriendo-facil' ) . '</h2>';
		$message .= '</div>';
		$message .= '<div style="padding:22px;">';
		$message .= '<p style="margin:0 0 12px;line-height:1.6;">' . sprintf( esc_html__( 'Hola %s, recibimos tu solicitud de registro como inquilino.', 'arriendo-facil' ), esc_html( '' !== $display ? $display : __( 'inquilino', 'arriendo-facil' ) ) ) . '</p>';
		$message .= '<p style="margin:0 0 16px;line-height:1.6;color:#334155;">' . esc_html__( 'Por seguridad, confirma que este correo te pertenece para activar la cuenta y poder iniciar sesion.', 'arriendo-facil' ) . '</p>';
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
	 * Handles tenant email verification callback from login endpoint.
	 *
	 * @return void
	 */
	public function handle_tenant_email_verification_request() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'af_verify_tenant_email' !== $action ) {
			return;
		}

		$user_id = isset( $_GET['uid'] ) ? absint( wp_unslash( $_GET['uid'] ) ) : 0;
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$status = 'invalid';
		if ( $user_id && '' !== $token ) {
			$result = $this->verify_tenant_email_token( $user_id, $token );
			$status = is_wp_error( $result ) ? 'failed' : 'ok';
		}

		wp_safe_redirect( add_query_arg( 'af_verify', $status, wp_login_url( admin_url() ) ) );
		exit;
	}

	/**
	 * Marks tenant email as verified when token is valid.
	 *
	 * @param int    $user_id Tenant user ID.
	 * @param string $token Raw verification token.
	 * @return true|WP_Error
	 */
	private function verify_tenant_email_token( $user_id, $token ) {
		$user_id = absint( $user_id );
		$token   = (string) $token;

		if ( ! $user_id || '' === $token ) {
			return new WP_Error( 'af_invalid_verification_request', __( 'Solicitud de verificacion invalida.', 'arriendo-facil' ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'af_verification_user_not_found', __( 'No se encontro el usuario a verificar.', 'arriendo-facil' ) );
		}

		$stored_hash = (string) get_user_meta( $user_id, 'af_tenant_email_verify_hash', true );
		$expires_at  = (int) get_user_meta( $user_id, 'af_tenant_email_verify_expires', true );
		if ( '' === $stored_hash || ! $expires_at ) {
			return new WP_Error( 'af_verification_not_found', __( 'No existe una verificacion pendiente para esta cuenta.', 'arriendo-facil' ) );
		}

		if ( time() > $expires_at ) {
			$this->log_tenant_signup_security_event( 'verification_token_expired', (string) $user->user_email );
			return new WP_Error( 'af_verification_expired', __( 'El enlace de verificacion ya expiro.', 'arriendo-facil' ) );
		}

		$provided_hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) . 'af_tenant_email_verify_v1' );
		if ( ! hash_equals( $stored_hash, $provided_hash ) ) {
			$this->log_tenant_signup_security_event( 'verification_token_mismatch', (string) $user->user_email );
			return new WP_Error( 'af_verification_mismatch', __( 'No pudimos validar el enlace de verificacion.', 'arriendo-facil' ) );
		}

		update_user_meta( $user_id, 'af_tenant_email_verified', 1 );
		update_user_meta( $user_id, 'af_tenant_email_verified_at', current_time( 'mysql' ) );
		delete_user_meta( $user_id, 'af_tenant_email_verify_hash' );
		delete_user_meta( $user_id, 'af_tenant_email_verify_expires' );
		delete_user_meta( $user_id, 'af_tenant_email_verify_requested_at' );
		$this->log_tenant_signup_security_event( 'verification_success', (string) $user->user_email );
		$this->send_tenant_account_created_email( $user_id );

		return true;
	}

	/**
	 * Blocks tenant login until email verification is completed.
	 *
	 * @param WP_User|WP_Error|null $user Authenticated user object.
	 * @param string                $username Submitted username.
	 * @param string                $password Submitted password.
	 * @return WP_User|WP_Error|null
	 */
	public function enforce_tenant_email_verification_on_login( $user, $username, $password ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		if ( ! in_array( 'af_tenant', (array) $user->roles, true ) ) {
			return $user;
		}

		$is_verified = (int) get_user_meta( (int) $user->ID, 'af_tenant_email_verified', true );
		if ( 1 === $is_verified ) {
			return $user;
		}

		$pending_hash    = (string) get_user_meta( (int) $user->ID, 'af_tenant_email_verify_hash', true );
		$pending_expires = (int) get_user_meta( (int) $user->ID, 'af_tenant_email_verify_expires', true );

		// Keep rollout safe: only block tenant logins when verification is explicitly pending.
		if ( '' === $pending_hash || $pending_expires <= 0 ) {
			return $user;
		}

		$this->log_tenant_signup_security_event( 'blocked_unverified_login', (string) $user->user_email );

		return new WP_Error(
			'af_tenant_email_unverified',
			__( 'Debes verificar tu correo antes de iniciar sesion. Revisa la bandeja de entrada del email registrado.', 'arriendo-facil' )
		);
	}

	/**
	 * Renders login notices after tenant email verification attempts.
	 *
	 * @param string $message Existing login message.
	 * @return string
	 */
	public function render_tenant_email_verification_login_notice( $message ) {
		$status = isset( $_GET['af_verify'] ) ? sanitize_key( wp_unslash( $_GET['af_verify'] ) ) : '';

		if ( 'ok' === $status ) {
			$message .= '<p class="message" style="border-left-color:#16a34a;">' . esc_html__( 'Tu correo fue verificado correctamente. Ya puedes iniciar sesion.', 'arriendo-facil' ) . '</p>';
		} elseif ( 'failed' === $status || 'invalid' === $status ) {
			$message .= '<p class="message" style="border-left-color:#dc2626;">' . esc_html__( 'No se pudo verificar el enlace. Solicita un nuevo registro o contacta soporte.', 'arriendo-facil' ) . '</p>';
		}

		return $message;
	}
}
