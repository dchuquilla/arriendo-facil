<?php
/**
 * Class Arriendo_Facil_Property_Admin_Onboarding
 *
 * Gestiona la finalización del perfil dentro de wp-admin para cuentas
 * auto-registradas de administrador de propiedades (af_signup_source =
 * 'self'):
 *
 *  1. Página "Mi perfil" (af-admin-profile): checklist de onboarding,
 *     datos de la empresa, identidad (cédula/RUC validada) y subida de
 *     documentos (cédula/papeleta, certificado laboral, certificado
 *     bancario) con almacenamiento privado.
 *  2. Banner contextual en wp-admin recordando completar el perfil y
 *     avisando que los datos de la demo son de ejemplo; permite limpiar
 *     el dataset de ejemplo.
 *  3. Revisión supervisada del super admin (aprobar/rechazar/reiniciar)
 *     desde la pantalla de Administradores (af-property-admins).
 *
 * @package Arriendo_Facil
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Property_Admin_Onboarding {

	const PREFIX           = 'af_admin_';
	const DOC_TYPES        = array( 'cedula_papeleta', 'certificado_laboral', 'certificado_bancario' );
	const PROFILE_NONCE    = 'af_admin_profile_nonce';
	const REVIEW_NONCE     = 'af_property_admin_nonce';
	const PAGE_SLUG        = 'af-admin-profile';

	/**
	 * Plugin operational pages hidden until identity verification is done.
	 * The Panel (arriendo-facil) and this profile page stay reachable so the
	 * prospect can see the internal system while finishing onboarding.
	 */
	const GATED_SLUGS = array(
		'af-catalog',
		'af-leases',
		'af-upcoming-exits',
		'af-buildings',
		'af-collections',
		'af-meter-readings',
		'af-owner-settlements',
		'af-maintenance',
		'af-cleaning-requests',
		'af-owner-contacts',
		'af-guests',
		'af-reviews',
		'af-ai-settings',
		'af-billing',
		'af-billing-settings',
		'af-ota-integrations',
		'af-ota-sync-dashboard',
	);

	/**
	 * Hooks the onboarding feature.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_profile_menu' ) );
		add_action( 'admin_menu', array( $this, 'hide_operational_menus_for_unverified' ), 99 );

		add_action( 'admin_init', array( $this, 'enforce_verification_gate' ) );

		add_action( 'wp_ajax_af_admin_update_profile', array( $this, 'ajax_update_profile' ) );
		add_action( 'wp_ajax_af_admin_upload_document', array( $this, 'ajax_upload_document' ) );
		add_action( 'wp_ajax_af_admin_purge_demo', array( $this, 'ajax_purge_demo' ) );
		add_action( 'wp_ajax_af_review_admin_verification', array( $this, 'ajax_review_verification' ) );

		add_action( 'admin_notices', array( $this, 'render_demo_banner' ) );

		add_filter( 'login_redirect', array( $this, 'login_redirect_to_onboarding' ), 20, 3 );
	}

	/**
	 * Registers the "Mi perfil" page for property admins.
	 *
	 * @return void
	 */
	public function register_profile_menu() {
		add_submenu_page(
			'arriendo-facil',
			__( 'Mi perfil', 'arriendo-facil' ),
			__( 'Mi perfil', 'arriendo-facil' ),
			Arriendo_Facil_Tenancy::CAP,
			self::PAGE_SLUG,
			array( $this, 'render_profile_page' )
		);
	}

	/**
	 * Renders the profile/onboarding admin page.
	 *
	 * @return void
	 */
	public function render_profile_page() {
		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_die( esc_html__( 'Permiso denegado.', 'arriendo-facil' ) );
		}

		$user_id      = get_current_user_id();
		$ajax_url     = admin_url( 'admin-ajax.php' );
		$nonce        = wp_create_nonce( self::PROFILE_NONCE );
		$demo_seeded  = Arriendo_Facil_Property_Admin_Demo::has_demo( $user_id );
		$company_name = (string) get_user_meta( $user_id, 'af_company_name', true );
		$contact_name = (string) get_user_meta( $user_id, 'af_contact_name', true );
		$phone        = (string) get_user_meta( $user_id, 'af_contact_phone', true );
		$id_type      = (string) get_user_meta( $user_id, 'af_admin_id_type', true );
		$id_enc       = (string) get_user_meta( $user_id, 'af_admin_id_number_enc', true );
		$nationality  = (string) get_user_meta( $user_id, 'af_admin_nationality', true );
		$birth_city   = (string) get_user_meta( $user_id, 'af_admin_birth_city', true );
		$doc_status   = (string) get_user_meta( $user_id, 'af_admin_doc_status', true );
		$documents    = (array) get_user_meta( $user_id, 'af_admin_documents', true );

		$email_ok   = 1 === (int) get_user_meta( $user_id, 'af_admin_email_verified', true );
		$company_ok = '' !== $company_name && '' !== $contact_name && '' !== $phone;
		$identity_ok = in_array( $id_type, array( 'cedula', 'ruc' ), true ) && '' !== $id_enc;
		$docs_ok     = true;
		foreach ( self::DOC_TYPES as $doc_type ) {
			if ( empty( $documents[ $doc_type ] ) ) {
				$docs_ok = false;
			}
		}
		$review_ok  = 'verificado' === $doc_status;

		$status_map = array(
			'email'     => $email_ok,
			'company'   => $company_ok,
			'identity'  => $identity_ok,
			'documents' => $docs_ok,
			'review'    => $review_ok,
		);

		$steps = array(
			'email'     => array( 'label' => __( 'Verifica tu correo', 'arriendo-facil' ), 'hint' => __( 'Revisa tu bandeja y usa el enlace de verificacion.', 'arriendo-facil' ) ),
			'company'   => array( 'label' => __( 'Datos de la empresa', 'arriendo-facil' ), 'hint' => __( 'Nombre, responsable y telefono de contacto.', 'arriendo-facil' ) ),
			'identity'  => array( 'label' => __( 'Identidad del responsable', 'arriendo-facil' ), 'hint' => __( 'Cedula o RUC validados automaticamente.', 'arriendo-facil' ) ),
			'documents' => array( 'label' => __( 'Documentos de soporte', 'arriendo-facil' ), 'hint' => __( 'Cedula/papeleta, certificado laboral y bancario (PDF).', 'arriendo-facil' ) ),
			'review'    => array( 'label' => __( 'Revision del equipo', 'arriendo-facil' ), 'hint' => __( 'El equipo supervisor valida tus documentos.', 'arriendo-facil' ) ),
		);

		$done_count = 0;
		foreach ( $status_map as $done ) {
			if ( $done ) {
				++$done_count;
			}
		}
		$total = count( $steps );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Mi perfil', 'arriendo-facil' ); ?></h1>

			<div id="af-profile-alert" class="notice" style="display:none;"></div>

			<div class="af-profile">
				<div class="af-profile__checklist">
					<h2><?php echo esc_html__( 'Tu camino de activacion', 'arriendo-facil' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %1$d: done steps, %2$d: total steps */
							esc_html__( 'Completaste %1$d de %2$d pasos.', 'arriendo-facil' ),
							esc_html( (string) $done_count ),
							esc_html( (string) $total )
						);
						?>
					</p>
					<ol class="af-profile__steps">
						<?php foreach ( $steps as $key => $step ) : ?>
							<li class="af-profile__step <?php echo $status_map[ $key ] ? 'is-complete' : ''; ?>">
								<span class="af-profile__step-label"><?php echo esc_html( $step['label'] ); ?></span>
								<span class="af-profile__step-hint"><?php echo esc_html( $step['hint'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>

					<?php if ( $demo_seeded ) : ?>
						<div class="af-profile__demo-note">
							<strong><?php echo esc_html__( 'Estas viendo datos de ejemplo.', 'arriendo-facil' ); ?></strong>
							<p><?php echo esc_html__( 'La demo incluye un edificio, unidades, un contrato y cobros ficticios. Puedes limpiarlos cuando quieras para empezar con tus datos reales.', 'arriendo-facil' ); ?></p>
							<button type="button" class="button" data-af-purge-demo><?php echo esc_html__( 'Limpiar datos de ejemplo', 'arriendo-facil' ); ?></button>
						</div>
					<?php endif; ?>
				</div>

				<form data-af-profile-form class="af-profile__form">
					<h2><?php echo esc_html__( 'Datos de la empresa', 'arriendo-facil' ); ?></h2>
					<label>
						<span><?php echo esc_html__( 'Nombre de la empresa', 'arriendo-facil' ); ?></span>
						<input type="text" name="company_name" maxlength="190" value="<?php echo esc_attr( $company_name ); ?>" required class="regular-text" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Responsable', 'arriendo-facil' ); ?></span>
						<input type="text" name="contact_name" maxlength="190" value="<?php echo esc_attr( $contact_name ); ?>" required class="regular-text" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Telefono de contacto', 'arriendo-facil' ); ?></span>
						<input type="text" name="phone" maxlength="20" value="<?php echo esc_attr( $phone ); ?>" required class="regular-text" />
					</label>

					<h2><?php echo esc_html__( 'Identidad del responsable', 'arriendo-facil' ); ?></h2>
					<label>
						<span><?php echo esc_html__( 'Tipo de identificacion', 'arriendo-facil' ); ?></span>
						<select name="id_type" class="regular-text">
							<option value=""><?php echo esc_html__( 'Selecciona', 'arriendo-facil' ); ?></option>
							<option value="cedula" <?php selected( $id_type, 'cedula' ); ?>><?php echo esc_html__( 'Cedula', 'arriendo-facil' ); ?></option>
							<option value="ruc" <?php selected( $id_type, 'ruc' ); ?>><?php echo esc_html__( 'RUC', 'arriendo-facil' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php echo esc_html__( 'Numero de identificacion', 'arriendo-facil' ); ?></span>
						<input type="text" name="id_number" maxlength="20" value="<?php echo $id_enc ? esc_attr__( '(guardado)', 'arriendo-facil' ) : ''; ?>" placeholder="<?php echo esc_attr__( '1834567890', 'arriendo-facil' ); ?>" class="regular-text" <?php echo $id_enc ? 'data-preserve-id="1"' : 'required'; ?> />
						<?php if ( $id_enc ) : ?>
							<small><?php echo esc_html__( 'El numero ya esta guardado de forma segura. Dejalo en blanco para conservarlo o ingresa uno nuevo para reemplazarlo.', 'arriendo-facil' ); ?></small>
						<?php endif; ?>
					</label>
					<label>
						<span><?php echo esc_html__( 'Nacionalidad', 'arriendo-facil' ); ?></span>
						<input type="text" name="nationality" maxlength="100" value="<?php echo esc_attr( $nationality ); ?>" class="regular-text" />
					</label>
					<label>
						<span><?php echo esc_html__( 'Ciudad de nacimiento', 'arriendo-facil' ); ?></span>
						<input type="text" name="birth_city" maxlength="150" value="<?php echo esc_attr( $birth_city ); ?>" class="regular-text" />
					</label>

					<button type="submit" class="button button-primary"><?php echo esc_html__( 'Guardar perfil', 'arriendo-facil' ); ?></button>
				</form>

				<div class="af-profile__documents">
					<h2><?php echo esc_html__( 'Documentos de soporte', 'arriendo-facil' ); ?></h2>
					<p class="description"><?php echo esc_html__( 'Sube en PDF: tu cedula o papeleta de votacion, un certificado laboral y un certificado bancario. Se guardan de forma privada y solo el equipo supervisor puede revisarlos.', 'arriendo-facil' ); ?></p>
					<form data-af-doc-form>
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />
						<?php foreach ( self::DOC_TYPES as $doc_type ) : ?>
							<?php
							$doc = isset( $documents[ $doc_type ] ) ? $documents[ $doc_type ] : null;
							?>
							<div class="af-profile__doc-row">
								<label>
									<span><?php echo esc_html( $this->doc_type_label( $doc_type ) ); ?></span>
									<input type="file" name="document_pdf" accept="application/pdf" class="af-profile__file" data-doc-type="<?php echo esc_attr( $doc_type ); ?>" />
								</label>
								<?php if ( $doc ) : ?>
									<span class="af-profile__doc-ok"><?php echo esc_html__( 'Subido', 'arriendo-facil' ); ?></span>
								<?php else : ?>
									<span class="af-profile__doc-missing"><?php echo esc_html__( 'Pendiente', 'arriendo-facil' ); ?></span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
						<button type="submit" class="button"><?php echo esc_html__( 'Subir documento', 'arriendo-facil' ); ?></button>
					</form>
				</div>
			</div>
		</div>

		<style>
			.af-profile { display: flex; flex-wrap: wrap; gap: 24px; margin-top: 16px; }
			.af-profile > div, .af-profile > form { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 18px; flex: 1 1 340px; max-width: 100%; }
			.af-profile h2 { margin-top: 0; }
			.af-profile label { display: block; margin: 0 0 12px; }
			.af-profile label > span { display: block; font-weight: 600; margin-bottom: 4px; }
			.af-profile__steps { margin: 12px 0 0 18px; padding: 0; }
			.af-profile__step { margin-bottom: 8px; }
			.af-profile__step-label { font-weight: 600; }
			.af-profile__step-hint { display: block; color: #646970; font-size: 12px; }
			.af-profile__step.is-complete .af-profile__step-label { color: #2271b1; }
			.af-profile__step.is-complete .af-profile__step-label::after { content: ' \2713'; color: #00a32a; font-weight: 700; }
			.af-profile__demo-note { border: 1px dashed #b32d2e; background: #fcf0f1; padding: 12px; border-radius: 6px; margin-top: 16px; }
			.af-profile__doc-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
			.af-profile__doc-ok { color: #00a32a; font-weight: 600; }
			.af-profile__doc-missing { color: #b32d2e; }
		</style>

		<script>
		(function(){
			var wrap = document.querySelector('.af-profile');
			if(!wrap){ return; }

			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var alertBox = document.getElementById('af-profile-alert');

			function showAlert(message, type){
				alertBox.style.display = 'block';
				alertBox.textContent = String(message || '');
				alertBox.className = 'notice ' + (type === 'success' ? 'notice-success' : 'notice-error');
			}

			var profileForm = wrap.querySelector('[data-af-profile-form]');
			if(profileForm){
				profileForm.addEventListener('submit', async function(e){
					e.preventDefault();
					var data = new URLSearchParams(new FormData(profileForm));
					data.set('action', 'af_admin_update_profile');
					data.set('nonce', nonce);
					var btn = profileForm.querySelector('button[type="submit"]');
					btn.disabled = true;
					try {
						var res = await fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: data.toString() });
						var json = await res.json();
						if(!json || !json.success){
							showAlert((json && json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'No se pudo guardar el perfil.', 'arriendo-facil' ) ); ?>, 'error');
							return;
						}
						showAlert((json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'Perfil guardado.', 'arriendo-facil' ) ); ?>, 'success');
						setTimeout(function(){ window.location.reload(); }, 800);
					} catch (err) {
						showAlert(<?php echo wp_json_encode( __( 'No se pudo conectar con el servidor.', 'arriendo-facil' ) ); ?>, 'error');
					} finally {
						btn.disabled = false;
					}
				});
			}

			var docForm = wrap.querySelector('[data-af-doc-form]');
			if(docForm){
				docForm.addEventListener('submit', async function(e){
					e.preventDefault();
					var selected = null;
					docForm.querySelectorAll('.af-profile__file').forEach(function(input){
						if(input.files && input.files.length > 0){ selected = input; }
					});
					if(!selected){
						showAlert(<?php echo wp_json_encode( __( 'Selecciona un PDF para subir.', 'arriendo-facil' ) ); ?>, 'error');
						return;
					}
					var data = new FormData();
					data.set('action', 'af_admin_upload_document');
					data.set('nonce', nonce);
					data.set('doc_type', selected.getAttribute('data-doc-type'));
					data.set('document_pdf', selected.files[0]);
					var btn = docForm.querySelector('button[type="submit"]');
					btn.disabled = true;
					try {
						var res = await fetch(ajaxUrl, { method: 'POST', body: data });
						var json = await res.json();
						if(!json || !json.success){
							showAlert((json && json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'No se pudo subir el documento.', 'arriendo-facil' ) ); ?>, 'error');
							return;
						}
						showAlert((json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'Documento subido.', 'arriendo-facil' ) ); ?>, 'success');
						setTimeout(function(){ window.location.reload(); }, 800);
					} catch (err) {
						showAlert(<?php echo wp_json_encode( __( 'No se pudo conectar con el servidor.', 'arriendo-facil' ) ); ?>, 'error');
					} finally {
						btn.disabled = false;
					}
				});
			}

			var purgeBtn = wrap.querySelector('[data-af-purge-demo]');
			if(purgeBtn){
				purgeBtn.addEventListener('click', async function(){
					if(!window.confirm(<?php echo wp_json_encode( __( 'Se eliminaran los datos de ejemplo de tu demo. Continuar?', 'arriendo-facil' ) ); ?>)){ return; }
					purgeBtn.disabled = true;
					var data = new URLSearchParams();
					data.set('action', 'af_admin_purge_demo');
					data.set('nonce', nonce);
					try {
						var res = await fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: data.toString() });
						var json = await res.json();
						showAlert((json && json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'Datos de ejemplo eliminados.', 'arriendo-facil' ) ); ?>, 'success');
						setTimeout(function(){ window.location.reload(); }, 900);
					} catch (err) {
						purgeBtn.disabled = false;
						showAlert(<?php echo wp_json_encode( __( 'No se pudo completar la limpieza.', 'arriendo-facil' ) ); ?>, 'error');
					}
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * AJAX: updates company + identity profile fields for current admin.
	 *
	 * @return void
	 */
	public function ajax_update_profile() {
		check_ajax_referer( self::PROFILE_NONCE, 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id      = get_current_user_id();
		$user         = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Cuenta invalida.', 'arriendo-facil' ) ), 403 );
		}

		$company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
		$contact_name = isset( $_POST['contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_name'] ) ) : '';
		$phone        = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$id_type      = isset( $_POST['id_type'] ) ? sanitize_key( wp_unslash( $_POST['id_type'] ) ) : '';
		$id_number    = isset( $_POST['id_number'] ) ? sanitize_text_field( wp_unslash( $_POST['id_number'] ) ) : '';
		$nationality  = isset( $_POST['nationality'] ) ? sanitize_text_field( wp_unslash( $_POST['nationality'] ) ) : '';
		$birth_city   = isset( $_POST['birth_city'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_city'] ) ) : '';

		$current_id_enc = (string) get_user_meta( $user_id, 'af_admin_id_number_enc', true );
		$current_id_type = (string) get_user_meta( $user_id, 'af_admin_id_type', true );

		if ( in_array( $id_type, array( 'cedula', 'ruc' ), true ) && '' !== $id_number ) {
			if ( ! class_exists( 'Arriendo_Facil_Identity_Validator' )
				|| ! Arriendo_Facil_Identity_Validator::validate( $id_type, $id_number ) ) {
				wp_send_json_error( array( 'message' => __( 'El numero de identificacion no es valido para el tipo seleccionado.', 'arriendo-facil' ) ), 400 );
			}

			$encrypted_id = $this->encrypt_sensitive_value( $id_number );
			if ( '' === $encrypted_id ) {
				wp_send_json_error( array( 'message' => __( 'No se pudo proteger la informacion de identidad.', 'arriendo-facil' ) ), 500 );
			}

			update_user_meta( $user_id, 'af_admin_id_number_enc', $encrypted_id );
			$current_id_enc = $encrypted_id;
		}

		if ( '' === $current_id_enc ) {
			if ( ! in_array( $id_type, array( 'cedula', 'ruc' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Selecciona un tipo de identificacion.', 'arriendo-facil' ) ), 400 );
			}
			wp_send_json_error( array( 'message' => __( 'Ingresa un numero de identificacion valido.', 'arriendo-facil' ) ), 400 );
		}

		update_user_meta( $user_id, 'af_company_name', $company_name );
		update_user_meta( $user_id, 'af_contact_name', $contact_name );
		update_user_meta( $user_id, 'af_contact_phone', $phone );
		update_user_meta( $user_id, 'af_admin_id_type', $id_type );
		update_user_meta( $user_id, 'af_admin_nationality', $nationality );
		update_user_meta( $user_id, 'af_admin_birth_city', $birth_city );

		if ( '' !== $id_number ) {
			// La identidad cambio: el equipo debe re-evaluar la coincidencia.
			update_user_meta( $user_id, 'af_admin_identity_match_status', 'not_checked' );
		}

		update_user_meta( $user_id, 'af_admin_doc_status', $this->next_doc_status( $user_id ) );
		update_user_meta( $user_id, 'af_admin_onboarding_step', 'documents' );

		wp_send_json_success( array( 'message' => __( 'Perfil guardado correctamente.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: uploads a single PDF support document for the current admin.
	 *
	 * @return void
	 */
	public function ajax_upload_document() {
		check_ajax_referer( self::PROFILE_NONCE, 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Cuenta invalida.', 'arriendo-facil' ) ), 403 );
		}

		$doc_type = isset( $_POST['doc_type'] ) ? sanitize_key( wp_unslash( $_POST['doc_type'] ) ) : '';
		if ( ! in_array( $doc_type, self::DOC_TYPES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Tipo de documento invalido.', 'arriendo-facil' ) ), 400 );
		}

		if ( empty( $_FILES['document_pdf'] ) || ! is_array( $_FILES['document_pdf'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No se recibio ningun archivo.', 'arriendo-facil' ) ), 400 );
		}

		$file_data  = $_FILES['document_pdf'];
		$file_error = isset( $file_data['error'] ) ? (int) $file_data['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $file_error ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo subir el documento.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! empty( $file_data['size'] ) && (int) $file_data['size'] > ( 10 * 1024 * 1024 ) ) {
			wp_send_json_error( array( 'message' => __( 'El PDF supera el tamano maximo (10 MB).', 'arriendo-facil' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$checked = wp_check_filetype_and_ext( $file_data['tmp_name'], $file_data['name'], array( 'pdf' => 'application/pdf' ) );
		if ( 'pdf' !== (string) $checked['ext'] ) {
			wp_send_json_error( array( 'message' => __( 'Solo se permiten archivos PDF.', 'arriendo-facil' ) ), 400 );
		}

		$contents = file_get_contents( $file_data['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || '' === $contents ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo leer el archivo PDF.', 'arriendo-facil' ) ), 400 );
		}

		$storage = 'local';
		$object_key = '';

		if ( Arriendo_Facil_Private_Storage::is_configured() ) {
			$object_key = 'property-admin-docs/' . (int) $user_id . '/' . $doc_type . '-' . wp_generate_password( 12, false, false ) . '.pdf';
			$put_result = Arriendo_Facil_Private_Storage::upload( $contents, $object_key, 'application/pdf' );

			if ( is_wp_error( $put_result ) ) {
				wp_send_json_error( array( 'message' => __( 'No se pudo guardar el documento en el almacenamiento privado.', 'arriendo-facil' ) ), 500 );
			}

			$storage = 'r2';
		} else {
			$field_key  = 'document_' . $doc_type . '_' . $user_id; // unique field per upload round
			$_FILES[ $field_key ] = $file_data;
			$attachment_id = media_handle_upload(
				$field_key,
				0,
				array( 'post_title' => sprintf( 'property-admin-%d-%s', (int) $user_id, $doc_type ) ),
				array(
					'test_form' => false,
					'mimes'     => array( 'pdf' => 'application/pdf' ),
				)
			);
			unset( $_FILES[ $field_key ] );

			if ( is_wp_error( $attachment_id ) ) {
				wp_send_json_error( array( 'message' => __( 'No se pudo guardar el documento.', 'arriendo-facil' ) ), 500 );
			}

			update_post_meta( (int) $attachment_id, '_af_property_admin_doc', (int) $user_id );
			update_post_meta( (int) $attachment_id, '_af_property_admin_doc_type', $doc_type );
			$object_key = (string) $attachment_id;
		}

		$documents = (array) get_user_meta( $user_id, 'af_admin_documents', true );

		$documents[ $doc_type ] = array(
			'doc_type'    => $doc_type,
			'storage'     => $storage,
			'object_key'  => $object_key,
			'mime_type'   => 'application/pdf',
			'file_size'   => strlen( $contents ),
			'checksum'    => hash( 'sha256', $contents ),
			'uploaded_at' => current_time( 'mysql' ),
			'uploaded_by' => (int) $user_id,
		);

		update_user_meta( $user_id, 'af_admin_documents', $documents );
		update_user_meta( $user_id, 'af_admin_doc_status', $this->next_doc_status( $user_id ) );

		if ( 'pendiente' === get_user_meta( $user_id, 'af_admin_doc_status', true ) ) {
			update_user_meta( $user_id, 'af_admin_onboarding_step', 'documents' );
		}

		wp_send_json_success( array( 'message' => __( 'Documento subido correctamente.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: purges the demo dataset of the current admin.
	 *
	 * @return void
	 */
	public function ajax_purge_demo() {
		check_ajax_referer( self::PROFILE_NONCE, 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id = get_current_user_id();
		if ( class_exists( 'Arriendo_Facil_Property_Admin_Demo' ) ) {
			Arriendo_Facil_Property_Admin_Demo::purge_demo( $user_id );
		}

		wp_send_json_success( array( 'message' => __( 'Datos de ejemplo eliminados. Ya puedes empezar con tus datos reales.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: super-admin review of a self-registered admin verification.
	 *
	 * @return void
	 */
	public function ajax_review_verification() {
		check_ajax_referer( self::REVIEW_NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$action  = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : '';
		$notes   = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( ! $user_id || ! in_array( $action, array( 'approve', 'reject', 'reset' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Datos invalidos.', 'arriendo-facil' ) ), 400 );
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Administrador no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		if ( 'approve' === $action ) {
			update_user_meta( $user_id, 'af_admin_doc_status', 'verificado' );
			update_user_meta( $user_id, 'af_admin_identity_match_status', 'coincide' );
			update_user_meta( $user_id, 'af_admin_doc_verified_by', get_current_user_id() );
			update_user_meta( $user_id, 'af_admin_doc_verified_at', current_time( 'mysql' ) );
			update_user_meta( $user_id, 'af_admin_doc_notes', $notes );
			update_user_meta( $user_id, 'af_admin_onboarding_step', 'ready' );
			$this->send_verification_result_email( $user_id, 'approved', $notes );
			wp_send_json_success( array( 'message' => __( 'Administrador verificado. Cuenta habilitada para datos reales.', 'arriendo-facil' ) ) );
		}

		if ( 'reject' === $action ) {
			update_user_meta( $user_id, 'af_admin_doc_status', 'rechazado' );
			update_user_meta( $user_id, 'af_admin_identity_match_status', 'no_coincide' );
			update_user_meta( $user_id, 'af_admin_doc_notes', $notes );
			update_user_meta( $user_id, 'af_admin_onboarding_step', 'documents' );
			$this->send_verification_result_email( $user_id, 'rejected', $notes );
			wp_send_json_success( array( 'message' => __( 'Administrador marcado como rechazado. Se aviso al usuario.', 'arriendo-facil' ) ) );
		}

		// reset.
		update_user_meta( $user_id, 'af_admin_doc_status', 'en_revision' );
		update_user_meta( $user_id, 'af_admin_identity_match_status', 'not_checked' );
		update_user_meta( $user_id, 'af_admin_doc_notes', $notes );
		update_user_meta( $user_id, 'af_admin_onboarding_step', 'documents' );

		wp_send_json_success( array( 'message' => __( 'Revision reiniciada. La documentacion vuelve a estar pendiente.', 'arriendo-facil' ) ) );
	}

	/**
	 * Renders the contextual wp-admin banner for demos/onboarding.
	 *
	 * @return void
	 */
	public function render_demo_banner() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			return;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id     = (int) $user->ID;
		$doc_status  = (string) get_user_meta( $user_id, 'af_admin_doc_status', true );
		$is_demo     = Arriendo_Facil_Property_Admin_Demo::has_demo( $user_id );
		$profile_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( in_array( $doc_status, array( 'manual', 'verificado' ), true ) ) {
			return;
		}

		$message = '';

		if ( $is_demo ) {
			$message = __( 'Estas en tu demo, con datos de ejemplo aislados. Completa tu perfil para que el equipo habilite el uso con datos reales.', 'arriendo-facil' );
		} else {
			$message = __( 'Bienvenido. Completa tu perfil para activar tu cuenta.', 'arriendo-facil' );
		}

		echo '<div class="notice notice-info is-dismissible"><p>'
			. esc_html( $message )
			. ' <a href="' . esc_url( $profile_url ) . '" class="button button-small">' . esc_html__( 'Completar perfil', 'arriendo-facil' ) . '</a></p></div>';
	}

	/**
	 * Whether the current property admin (self-sourced) still needs to prove
	 * identity before operating the internal system.
	 *
	 * @param int $user_id Optional user ID (defaults to current).
	 * @return bool
	 */
	public static function needs_identity_verification( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			return false;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return false;
		}

		$signup_source = (string) get_user_meta( $user_id, 'af_signup_source', true );
		if ( 'self' !== $signup_source ) {
			return false;
		}

		$doc_status = (string) get_user_meta( $user_id, 'af_admin_doc_status', true );

		return 'verificado' !== $doc_status;
	}

	/**
	 * Hides operational plugin menus until the self-sourced admin verifies
	 * identity. The Panel and "Mi perfil" stay visible.
	 *
	 * @return void
	 */
	public function hide_operational_menus_for_unverified() {
		if ( ! self::needs_identity_verification() ) {
			return;
		}

		foreach ( self::GATED_SLUGS as $slug ) {
			remove_submenu_page( 'arriendo-facil', $slug );
		}
	}

	/**
	 * Blocks direct access to operational plugin pages while the account is
	 * not identity-verified, redirecting to the onboarding profile page.
	 *
	 * @return void
	 */
	public function enforce_verification_gate() {
		if ( ! self::needs_identity_verification() ) {
			return;
		}

		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $current_page ) {
			return;
		}

		if ( self::PAGE_SLUG === $current_page || 'arriendo-facil' === $current_page ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Sends self-sourced property admins straight to their onboarding page
	 * after login until the account is identity-verified.
	 *
	 * @param string    $redirect_to           Destination computed by WordPress.
	 * @param string    $requested_redirect_to Requested destination.
	 * @param WP_User|WP_Error $user           Logged-in user.
	 * @return string
	 */
	public function login_redirect_to_onboarding( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		if ( ! self::needs_identity_verification( $user->ID ) ) {
			return $redirect_to;
		}

		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Computes next doc-status based on identity + all documents present.
	 *
	 * @param int $user_id Admin user ID.
	 * @return string
	 */
	private function next_doc_status( $user_id ) {
		$id_enc       = (string) get_user_meta( $user_id, 'af_admin_id_number_enc', true );
		$id_type      = (string) get_user_meta( $user_id, 'af_admin_id_type', true );
		$documents    = (array) get_user_meta( $user_id, 'af_admin_documents', true );
		$identity_ok  = in_array( $id_type, array( 'cedula', 'ruc' ), true ) && '' !== $id_enc;

		if ( ! $identity_ok ) {
			return 'pendiente';
		}

		foreach ( self::DOC_TYPES as $doc_type ) {
			if ( empty( $documents[ $doc_type ] ) ) {
				return 'pendiente';
			}
		}

		return 'en_revision';
	}

	/**
	 * Notifies the admin about a verification result.
	 *
	 * @param int    $user_id Admin user ID.
	 * @param string $result  approved|rejected.
	 * @param string $notes   Reviewer notes.
	 * @return void
	 */
	private function send_verification_result_email( $user_id, $result, $notes = '' ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$approved = 'approved' === $result;
		$subject  = $approved
			? __( '[Arriendo Facil] Tu cuenta fue verificada', 'arriendo-facil' )
			: __( '[Arriendo Facil] Necesitamos revisar tus documentos', 'arriendo-facil' );

		$message  = '<p style="margin:0 0 12px;line-height:1.6;">';
		if ( $approved ) {
			$message .= esc_html__( 'Tus documentos fueron verificados correctamente. Ya puedes usar Arriendo Facil con datos reales.', 'arriendo-facil' );
		} else {
			$message .= esc_html__( 'Tus documentos no pudieron ser verificados. Revisa la informacion y vuelve a intentarlo.', 'arriendo-facil' );
		}
		$message .= '</p>';

		if ( '' !== trim( $notes ) ) {
			$message .= '<p style="margin:0 0 12px;color:#334155;">' . esc_html__( 'Observaciones del equipo:', 'arriendo-facil' ) . ' ' . esc_html( $notes ) . '</p>';
		}

		$message .= '<p style="margin:0;"><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '" style="display:inline-block;padding:10px 16px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">' . esc_html__( 'Ir a mi perfil', 'arriendo-facil' ) . '</a></p>';

		wp_mail( sanitize_email( (string) $user->user_email ), $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Human label for a document type.
	 *
	 * @param string $doc_type Document type slug.
	 * @return string
	 */
	private function doc_type_label( $doc_type ) {
		$labels = array(
			'cedula_papeleta'    => __( 'Cedula o papeleta de votacion', 'arriendo-facil' ),
			'certificado_laboral' => __( 'Certificado laboral', 'arriendo-facil' ),
			'certificado_bancario' => __( 'Certificado bancario', 'arriendo-facil' ),
		);

		return isset( $labels[ $doc_type ] ) ? $labels[ $doc_type ] : $doc_type;
	}

	/**
	 * Encrypts a sensitive value (libsodium secretbox, admin scope).
	 *
	 * @param string $value Plain value.
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
}