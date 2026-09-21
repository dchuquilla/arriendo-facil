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
		// Register at a later priority than Arriendo_Facil_Admin::add_menu()
		// (priority 10) so the top-level `arriendo-facil` page exists when the
		// submenu hookname is computed. Registering earlier makes WP compute a
		// different hookname (`admin_page_*` vs `arriendo-facil_page_*`), the
		// page lands in $_registered_pages under the wrong key and access is
		// denied with a 403 regardless of capability.
		add_action( 'admin_menu', array( $this, 'register_profile_menu' ), 20 );
		add_action( 'admin_menu', array( $this, 'hide_operational_menus_for_unverified' ), 99 );

		add_action( 'admin_init', array( $this, 'enforce_verification_gate' ) );

		add_action( 'wp_ajax_af_admin_update_profile', array( $this, 'ajax_update_profile' ) );
		add_action( 'wp_ajax_af_admin_upload_document', array( $this, 'ajax_upload_document' ) );
		add_action( 'wp_ajax_af_admin_purge_demo', array( $this, 'ajax_purge_demo' ) );
		add_action( 'wp_ajax_af_review_admin_verification', array( $this, 'ajax_review_verification' ) );
		add_action( 'wp_ajax_af_admin_review_detail', array( $this, 'ajax_review_detail' ) );
		add_action( 'wp_ajax_af_admin_recompute_status', array( $this, 'ajax_recompute_status' ) );

		add_action( 'admin_notices', array( $this, 'render_demo_banner' ) );

		add_filter( 'login_redirect', array( $this, 'login_redirect_to_onboarding' ), 20, 3 );
	}

	/**
	 * Registers the "Mi perfil" page for property admins.
	 *
	 * Access fallback: the page is gated by `af_manage_properties`, but if a
	 * logged-in operator (property admin or WP administrator/super admin)
	 * still lacks that capability (stale role definition or `wp_capabilities`
	 * user meta — e.g. databases seeded before the cap existed), the page is
	 * registered under `edit_posts` (always present) so the profile screen is
	 * never unreachable for them. `render_profile_page()` still enforces the
	 * role-based guard, so opening the page is only a render — never a data
	 * leak.
	 *
	 * @return void
	 */
	public function register_profile_menu() {
		$cap = Arriendo_Facil_Tenancy::CAP;

		$user = wp_get_current_user();
		if (
			$user instanceof WP_User
			&& $user->exists()
			&& $this->can_manage_own_profile( $user->ID )
			&& ! user_can( $user, $cap )
		) {
			$cap = 'edit_posts';
		}

		add_submenu_page(
			'arriendo-facil',
			__( 'Mi perfil', 'arriendo-facil' ),
			__( 'Mi perfil', 'arriendo-facil' ),
			$cap,
			self::PAGE_SLUG,
			array( $this, 'render_profile_page' )
		);
	}

	/**
	 * Whether the current user may manage their own profile/onboarding.
	 *
	 * Property admins always can (it's their own data). WP administrators
	 * (super admins) can as well.
	 *
	 * @param int $user_id Optional user ID (defaults to current user).
	 * @return bool
	 */
	private function can_manage_own_profile( $user_id = 0 ) {
		$user = $user_id ? get_user_by( 'id', absint( $user_id ) ) : wp_get_current_user();
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();

		return in_array( 'af_property_admin', $roles, true );
	}

	/**
	 * Renders the profile/onboarding admin page.
	 *
	 * @return void
	 */
	public function render_profile_page() {
		if ( ! $this->can_manage_own_profile() ) {
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

		$id_last4 = '';
		if ( '' !== $id_enc ) {
			$id_plain = $this->decrypt_sensitive_value( $id_enc );
			if ( '' !== $id_plain ) {
				$id_digits = preg_replace( '/\D/', '', $id_plain );
				$id_last4  = strlen( $id_digits ) >= 4 ? '••••' . substr( $id_digits, -4 ) : '••••';
			}
		}

		$email_ok   = 1 === (int) get_user_meta( $user_id, 'af_admin_email_verified', true );
		$company_ok = '' !== $company_name && '' !== $contact_name && '' !== $phone;
		$identity_ok = in_array( $id_type, array( 'cedula', 'ruc', 'pasaporte' ), true ) && '' !== $id_enc;
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
		$total   = count( $steps );
		$percent = $total > 0 ? (int) round( ( $done_count / $total ) * 100 ) : 0;
		?>
		<div class="wrap af-shell af-profile-page">
			<?php
			$current_user = wp_get_current_user();
			$email_text   = $current_user instanceof WP_User ? (string) $current_user->user_email : '';
			$display_name = $current_user instanceof WP_User ? (string) $current_user->display_name : '';
			$signup_source = (string) get_user_meta( $user_id, 'af_signup_source', true );
			$profile_url  = get_edit_profile_url( $user_id );
			$avatar_html  = get_avatar( $user_id, 96 );
			$resend_nonce = wp_create_nonce( 'af_admin_signup_frontend_nonce' );

			af_page_header(
				array(
					'eyebrow'  => __( 'Arriendo Fácil', 'arriendo-facil' ),
					'title'    => __( 'Mi perfil', 'arriendo-facil' ),
					'subtitle' => __( 'Revisa el estado de tu cuenta y completa los datos de tu empresa para activar tu espacio de administración.', 'arriendo-facil' ),
					'actions'  => array(
						array(
							'label'   => __( 'Nombre, foto y contraseña', 'arriendo-facil' ),
							'url'     => $profile_url,
							'variant' => 'ghost',
							'icon'    => af_lucide( 'user' ),
						),
					),
				)
			);
			?>

			<div id="af-profile-alert" class="af-profile-alert" role="status" aria-live="polite" hidden></div>

			<div class="af-kpi-grid af-profile__status" role="list">
				<?php foreach ( $steps as $key => $step ) : ?>
					<?php $done = $status_map[ $key ]; ?>
					<div class="af-kpi <?php echo $done ? 'af-kpi--success' : 'af-kpi--attention'; ?>" role="listitem">
						<div class="af-kpi__head">
							<span class="af-kpi__label"><?php echo esc_html( $step['label'] ); ?></span>
							<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( $done ? 'check' : 'circle-alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</div>
						<div class="af-kpi__value"><?php echo esc_html( $done ? __( 'Listo', 'arriendo-facil' ) : __( 'Pendiente', 'arriendo-facil' ) ); ?></div>
						<div class="af-kpi__hint"><?php echo esc_html( $step['hint'] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="af-profile af-profile-layout">
				<div class="af-profile-layout__main">
					<form data-af-profile-form class="af-profile__form">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />

						<section class="af-section">
							<div class="af-section__header">
								<div>
									<h2 class="af-section__title"><?php esc_html_e( 'Datos de la empresa', 'arriendo-facil' ); ?></h2>
									<p class="af-section__subtitle"><?php esc_html_e( 'Información que usa el equipo de la plataforma para contactarte y validar tu operación.', 'arriendo-facil' ); ?></p>
								</div>
							</div>
							<div class="af-form-grid">
								<div class="af-form-field af-form-field--full">
									<label class="af-form-field__label" for="af-company-name"><?php esc_html_e( 'Nombre de la empresa', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
									<input id="af-company-name" class="regular-text" type="text" name="company_name" maxlength="190" value="<?php echo esc_attr( $company_name ); ?>" required />
								</div>
								<div class="af-form-field">
									<label class="af-form-field__label" for="af-contact-name"><?php esc_html_e( 'Responsable', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
									<input id="af-contact-name" class="regular-text" type="text" name="contact_name" maxlength="190" value="<?php echo esc_attr( $contact_name ); ?>" required />
								</div>
								<div class="af-form-field">
									<label class="af-form-field__label" for="af-contact-phone"><?php esc_html_e( 'Teléfono de contacto', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
									<input id="af-contact-phone" class="regular-text" type="tel" name="phone" maxlength="20" value="<?php echo esc_attr( $phone ); ?>" required />
								</div>
							</div>
						</section>

						<section class="af-section">
							<div class="af-section__header">
								<div>
									<h2 class="af-section__title"><?php esc_html_e( 'Identidad del responsable', 'arriendo-facil' ); ?></h2>
									<p class="af-section__subtitle"><?php esc_html_e( 'Se valida automáticamente y se guarda cifrada.', 'arriendo-facil' ); ?></p>
								</div>
								<?php if ( $identity_ok ) : ?>
									<span class="af-pill af-pill--success"><?php esc_html_e( 'Guardado', 'arriendo-facil' ); ?></span>
								<?php else : ?>
									<span class="af-pill af-pill--warning"><?php esc_html_e( 'Pendiente', 'arriendo-facil' ); ?></span>
								<?php endif; ?>
							</div>
							<div class="af-form-grid">
								<div class="af-form-field">
									<label class="af-form-field__label" for="af-id-type"><?php esc_html_e( 'Tipo de identificación', 'arriendo-facil' ); ?></label>
									<select id="af-id-type" class="regular-text" name="id_type">
										<option value=""><?php esc_html_e( 'Selecciona', 'arriendo-facil' ); ?></option>
										<option value="cedula" <?php selected( $id_type, 'cedula' ); ?>><?php esc_html_e( 'Cédula', 'arriendo-facil' ); ?></option>
										<option value="ruc" <?php selected( $id_type, 'ruc' ); ?>><?php esc_html_e( 'RUC', 'arriendo-facil' ); ?></option>
										<option value="pasaporte" <?php selected( $id_type, 'pasaporte' ); ?>><?php esc_html_e( 'Pasaporte', 'arriendo-facil' ); ?></option>
									</select>
									<span id="af-id-limit-hint" class="af-form-field__hint"></span>
								</div>
								<div class="af-form-field">
									<label class="af-form-field__label" for="af-id-number"><?php esc_html_e( 'Número de identificación', 'arriendo-facil' ); ?></label>
									<input id="af-id-number" class="regular-text" type="text" name="id_number" maxlength="20"
									value=""
									placeholder="<?php echo $id_last4 ? esc_attr( sprintf( /* translators: %s: masked identity number */ __( '%s (ya guardado)', 'arriendo-facil' ), $id_last4 ) ) : esc_attr__( 'ej. 1834567890', 'arriendo-facil' ); ?>"
									<?php echo $id_enc ? 'data-preserve-id="1"' : 'required'; ?> />
									<?php if ( $id_enc ) : ?>
									<span class="af-form-field__hint"><?php echo esc_html( sprintf( /* translators: %s: masked identity number */ __( 'Número actual %1$s. Déjalo en blanco para conservarlo o ingresa uno nuevo.', 'arriendo-facil' ), $id_last4 ) ); ?></span>
									<?php endif; ?>
								</div>
								<div class="af-form-field">
									<label class="af-form-field__label" for="af-nationality"><?php esc_html_e( 'Nacionalidad', 'arriendo-facil' ); ?></label>
									<input id="af-nationality" class="regular-text" type="text" name="nationality" maxlength="100" value="<?php echo esc_attr( $nationality ); ?>" />
								</div>
								<div class="af-form-field">
									<label class="af-form-field__label" for="af-birth-city"><?php esc_html_e( 'Ciudad de nacimiento', 'arriendo-facil' ); ?></label>
									<input id="af-birth-city" class="regular-text" type="text" name="birth_city" maxlength="150" value="<?php echo esc_attr( $birth_city ); ?>" />
								</div>
							</div>
						</section>

						<div class="af-profile__submit">
							<button type="submit" class="button button-primary af-btn af-btn--primary"><?php esc_html_e( 'Guardar cambios', 'arriendo-facil' ); ?></button>
						</div>
					</form>

					<section class="af-section">
						<div class="af-section__header">
							<div>
								<h2 class="af-section__title"><?php esc_html_e( 'Documentos de soporte', 'arriendo-facil' ); ?></h2>
								<p class="af-section__subtitle"><?php esc_html_e( 'Cédula o papeleta de votación, certificado laboral y certificado bancario en PDF. Se guardan de forma privada.', 'arriendo-facil' ); ?></p>
							</div>
						</div>
						<form data-af-doc-form>
							<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />
							<?php foreach ( self::DOC_TYPES as $doc_type ) : ?>
								<?php $doc = isset( $documents[ $doc_type ] ) ? $documents[ $doc_type ] : null; ?>
								<div class="af-profile__doc-row">
									<div class="af-profile__doc-meta">
										<span class="af-profile__doc-label"><?php echo esc_html( $this->doc_type_label( $doc_type ) ); ?></span>
										<span class="af-form-field__hint"><?php esc_html_e( 'PDF', 'arriendo-facil' ); ?></span>
										<span class="af-profile__doc-pick" data-af-doc-pick></span>
									</div>
									<div class="af-profile__doc-controls">
										<?php echo af_pill( $doc ? 'active' : 'pending', $doc ? __( 'Subido', 'arriendo-facil' ) : __( 'Pendiente', 'arriendo-facil' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<label class="button af-btn af-btn--ghost af-profile__file-btn">
											<?php echo af_lucide( 'upload', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<span><?php echo $doc ? esc_html__( 'Reemplazar', 'arriendo-facil' ) : esc_html__( 'Subir', 'arriendo-facil' ); ?></span>
											<input type="file" name="document_pdf" accept="application/pdf" class="af-profile__file" data-doc-type="<?php echo esc_attr( $doc_type ); ?>" />
										</label>
										<button type="button" class="button af-btn af-btn--primary af-profile__doc-confirm" data-af-doc-confirm hidden>
											<?php esc_html_e( 'Confirmar subida', 'arriendo-facil' ); ?>
										</button>
									</div>
								</div>
							<?php endforeach; ?>
						</form>
					</section>
				</div>

				<aside class="af-profile-layout__side">
					<section class="af-section">
						<div class="af-section__header">
							<div>
								<h2 class="af-section__title"><?php esc_html_e( 'Mi cuenta', 'arriendo-facil' ); ?></h2>
								<p class="af-section__subtitle"><?php esc_html_e( 'Tu acceso a la plataforma.', 'arriendo-facil' ); ?></p>
							</div>
						</div>
						<div class="af-account">
							<div class="af-account__avatar"><?php echo wp_kses_post( $avatar_html ); ?></div>
							<div class="af-account__meta">
								<span class="af-account__name"><?php echo esc_html( $display_name ); ?></span>
								<span class="af-account__email"><?php echo esc_html( $email_text ); ?></span>
							</div>
							<span class="af-pill <?php echo $email_ok ? 'af-pill--success' : 'af-pill--warning'; ?>"><?php echo esc_html( $email_ok ? __( 'Verificado', 'arriendo-facil' ) : __( 'Pendiente', 'arriendo-facil' ) ); ?></span>
						</div>
						<?php if ( ! $email_ok && 'manual' !== $signup_source ) : ?>
							<p class="af-account__hint"><?php esc_html_e( 'Necesitas verificar tu correo para operar. Te enviamos un enlace al registrarte.', 'arriendo-facil' ); ?></p>
							<button type="button" class="button af-btn af-btn--ghost" data-af-resend-email>
								<?php echo af_lucide( 'refresh-cw', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php esc_html_e( 'Reenviar correo de verificación', 'arriendo-facil' ); ?>
							</button>
						<?php elseif ( ! $email_ok ) : ?>
							<p class="af-account__hint"><?php esc_html_e( 'Tu cuenta fue creada por un administrador. El correo figurará como verificado una vez actives la cuenta.', 'arriendo-facil' ); ?></p>
						<?php endif; ?>
						<ul class="af-account__links">
							<li>
								<a href="<?php echo esc_url( $profile_url ); ?>"><?php echo af_lucide( 'user', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Editar nombre, foto y contraseña', 'arriendo-facil' ); ?></span></a>
							</li>
							<li>
								<a href="<?php echo esc_url( wp_logout_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>"><?php echo af_lucide( 'log-out', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Cerrar sesión', 'arriendo-facil' ); ?></span></a>
							</li>
						</ul>
					</section>

					<section class="af-section">
						<div class="af-section__header">
							<div>
								<h2 class="af-section__title"><?php esc_html_e( 'Tu camino de activación', 'arriendo-facil' ); ?></h2>
								<p class="af-section__subtitle">
									<?php
									printf(
										/* translators: %1$d: done steps, %2$d: total steps */
										esc_html__( 'Completaste %1$d de %2$d pasos.', 'arriendo-facil' ),
										esc_html( (string) $done_count ),
										esc_html( (string) $total )
									);
									?>
								</p>
							</div>
						</div>
						<div class="af-progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $total ); ?>" aria-valuenow="<?php echo esc_attr( (string) $done_count ); ?>">
							<div class="af-progress__track"><span class="af-progress__bar" style="width:<?php echo esc_attr( (string) $percent ); ?>%;"></span></div>
							<span class="af-progress__value"><?php echo esc_html( (string) $percent ); ?>%</span>
						</div>
						<ul class="af-checklist">
							<?php foreach ( $steps as $key => $step ) : ?>
								<li class="af-checklist__item <?php echo $status_map[ $key ] ? 'is-done' : 'is-pending'; ?>">
									<span class="af-checklist__dot" aria-hidden="true"><?php echo af_lucide( $status_map[ $key ] ? 'check' : 'circle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<span class="af-checklist__text">
										<span class="af-checklist__label"><?php echo esc_html( $step['label'] ); ?></span>
										<span class="af-checklist__hint"><?php echo esc_html( $step['hint'] ); ?></span>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>

					<?php if ( $demo_seeded ) : ?>
						<section class="af-section af-profile__demo">
							<div class="af-section__header">
								<div>
									<h2 class="af-section__title"><?php esc_html_e( 'Estás viendo datos de ejemplo', 'arriendo-facil' ); ?></h2>
								</div>
							</div>
							<p class="af-section__subtitle"><?php esc_html_e( 'La demo incluye un edificio, unidades, un contrato y cobros ficticios. Puedes limpiarlos cuando quieras para empezar con tus datos reales.', 'arriendo-facil' ); ?></p>
							<p><button type="button" class="button af-btn af-btn--danger" data-af-purge-demo><?php esc_html_e( 'Limpiar datos de ejemplo', 'arriendo-facil' ); ?></button></p>
						</section>
					<?php endif; ?>
				</aside>
			</div>
		</div>

		<style>
			.af-profile-page { max-width: var(--af-page-max); }
			.af-profile__status { margin-bottom: var(--af-space-6); }
			.af-profile-alert { margin-bottom: var(--af-space-4); padding: var(--af-space-3) var(--af-space-4); border-radius: var(--af-radius-md); border-left: 4px solid var(--af-gray-300); background: var(--af-gray-50); color: var(--af-gray-700); font-weight: 600; box-shadow: var(--af-shadow-xs); }
			.af-profile-alert.is-success { border-left-color: var(--af-success-500); background: var(--af-success-50); color: var(--af-success-700); }
			.af-profile-alert.is-error { border-left-color: var(--af-danger-500); background: var(--af-danger-50); color: var(--af-danger-700); }
			.af-profile-layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: var(--af-space-6); align-items: start; }
			@media (max-width: 1200px) { .af-profile-layout { grid-template-columns: 1fr; } }
			.af-profile__submit { margin-bottom: var(--af-space-6); }
			.af-account { display: flex; align-items: center; gap: var(--af-space-3); flex-wrap: wrap; }
			.af-account__avatar img { border-radius: var(--af-radius-full); display: block; }
			.af-account__meta { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1 1 auto; }
			.af-account__name { font-weight: 700; color: var(--af-gray-900); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.af-account__email { color: var(--af-gray-500); font-size: var(--af-text-xs); }
			.af-account__hint { color: var(--af-gray-500); font-size: var(--af-text-xs); margin: var(--af-space-3) 0; }
			.af-account__links { list-style: none; margin: var(--af-space-4) 0 0; padding: var(--af-space-4) 0 0; border-top: 1px solid var(--af-gray-200); }
			.af-account__links li { margin: 0 0 var(--af-space-2); }
			.af-account__links a { display: inline-flex; align-items: center; gap: var(--af-space-2); text-decoration: none; font-weight: 600; font-size: var(--af-text-sm); }
			.af-account__links .af-icon { color: var(--af-gray-400); }
			.af-progress { display: flex; align-items: center; gap: var(--af-space-3); margin-bottom: var(--af-space-5); }
			.af-progress__track { flex: 1; height: 8px; border-radius: var(--af-radius-full); background: var(--af-gray-100); overflow: hidden; }
			.af-progress__bar { display: block; height: 100%; border-radius: var(--af-radius-full); background: linear-gradient(90deg, var(--af-primary-500), var(--af-primary-400)); transition: width var(--af-motion-base); }
			.af-progress__value { font-family: var(--af-font-numeric); font-weight: 700; font-size: var(--af-text-sm); color: var(--af-primary-700); }
			.af-checklist { list-style: none; margin: 0; padding: 0; }
			.af-checklist__item { display: flex; gap: var(--af-space-3); align-items: flex-start; padding: var(--af-space-2) 0; }
			.af-checklist__dot { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: var(--af-radius-full); flex-shrink: 0; }
			.af-checklist__item.is-done .af-checklist__dot { background: var(--af-success-50); color: var(--af-success-700); }
			.af-checklist__item.is-pending .af-checklist__dot { background: var(--af-warning-50); color: var(--af-warning-700); }
			.af-checklist__text { display: flex; flex-direction: column; gap: 2px; }
			.af-checklist__label { font-weight: 600; color: var(--af-gray-900); }
			.af-checklist__hint { color: var(--af-gray-500); font-size: var(--af-text-xs); }
			.af-profile__doc-row { display: flex; align-items: center; justify-content: space-between; gap: var(--af-space-4); padding: var(--af-space-3) 0; border-bottom: 1px solid var(--af-gray-100); }
			.af-profile__doc-row:last-of-type { border-bottom: 0; }
			.af-profile__doc-meta { display: flex; flex-direction: column; gap: 2px; }
			.af-profile__doc-label { font-weight: 600; color: var(--af-gray-900); }
			.af-profile__doc-controls { display: flex; align-items: center; gap: var(--af-space-3); flex-wrap: wrap; }
			.af-profile__doc-pick { display: block; font-size: 12px; line-height: 1.4; color: var(--af-gray-500); max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.af-profile__doc-confirm { height: 36px; }
			.af-profile__file-btn { position: relative; overflow: hidden; height: 36px; cursor: pointer; }
			.af-profile__file-btn input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; font-size: 100px; }
			.af-profile__demo { border: 1px dashed var(--af-warning-100); background: var(--af-warning-50); }
		</style>

		<script>
		(function(){
			var wrap = document.querySelector('.af-profile');
			if(!wrap){ return; }

			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var resendNonce = <?php echo wp_json_encode( $resend_nonce ); ?>;
			var resendEmail = <?php echo wp_json_encode( $email_text ); ?>;
			var alertBox = document.getElementById('af-profile-alert');

			function showAlert(message, type){
				alertBox.hidden = false;
				alertBox.textContent = String(message || '');
				alertBox.classList.remove('is-success', 'is-error');
				alertBox.classList.add(type === 'success' ? 'is-success' : 'is-error');
				alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			}

			var idTypeSelect  = document.getElementById('af-id-type');
			var idNumberInput = document.getElementById('af-id-number');
			var idLimitHint   = document.getElementById('af-id-limit-hint');
			var idLimits      = {
				cedula:    { max: 10, text: 'Cedula: 10 digitos.' },
				ruc:       { max: 13, text: 'RUC: 13 digitos.' },
				pasaporte: { max: 12, text: 'Pasaporte: 6 a 12 caracteres alfanumericos (letras y numeros, sin espacios).' }
			};
			function updateIdHint(){
				var t = idTypeSelect ? idTypeSelect.value : '';
				var lim = idLimits[t] || null;
				if(idNumberInput){ idNumberInput.maxLength = lim ? lim.max : 20; }
				if(idLimitHint){ idLimitHint.textContent = lim ? lim.text : ''; }
			}
			if(idTypeSelect){
				idTypeSelect.addEventListener('change', updateIdHint);
				updateIdHint();
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
				var docInputs = docForm.querySelectorAll('.af-profile__file');
				function fmtSize(bytes){
					if(!bytes){ return '0 KB'; }
					var kb = Math.round(bytes / 1024);
					return kb < 1024 ? kb + ' KB' : (kb / 1024).toFixed(1).replace('.', ',') + ' MB';
				}
				function resetDocPick(input){
					var row = input.closest('.af-profile__doc-row');
					if(!row){ return; }
					var pick = row.querySelector('[data-af-doc-pick]');
					var confirmBtn = row.querySelector('[data-af-doc-confirm]');
					if(pick){ pick.textContent = ''; }
					if(confirmBtn){ confirmBtn.hidden = true; }
				}
				docInputs.forEach(function(input){
					input.addEventListener('change', function(){
						docInputs.forEach(resetDocPick);
						if(!input.files || !input.files.length){ return; }
						var row = input.closest('.af-profile__doc-row');
						var pick = row.querySelector('[data-af-doc-pick]');
						var confirmBtn = row.querySelector('[data-af-doc-confirm]');
						var f = input.files[0];
						if(pick){ pick.textContent = <?php echo wp_json_encode( __( 'Seleccionado:', 'arriendo-facil' ) ); ?> + ' ' + f.name + ' (' + fmtSize(f.size) + ')'; }
						if(confirmBtn){ confirmBtn.hidden = false; }
					});
				});
				docForm.querySelectorAll('[data-af-doc-confirm]').forEach(function(confirmBtn){
					confirmBtn.addEventListener('click', async function(){
						if(confirmBtn.disabled){ return; }
						var row = confirmBtn.closest('.af-profile__doc-row');
						var input = row.querySelector('.af-profile__file');
						if(!input || !input.files || !input.files.length){
							showAlert(<?php echo wp_json_encode( __( 'Selecciona un PDF para subir.', 'arriendo-facil' ) ); ?>, 'error');
							return;
						}
						var data = new FormData();
						data.set('action', 'af_admin_upload_document');
						data.set('nonce', nonce);
						data.set('doc_type', input.getAttribute('data-doc-type'));
						data.set('document_pdf', input.files[0]);
						confirmBtn.disabled = true;
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
							confirmBtn.disabled = false;
						}
					});
				});
			}

			var resendBtn = wrap.querySelector('[data-af-resend-email]');
			if(resendBtn){
				resendBtn.addEventListener('click', async function(){
					resendBtn.disabled = true;
					var data = new URLSearchParams();
					data.set('action', 'af_resend_admin_verification_email');
					data.set('nonce', resendNonce);
					data.set('email', resendEmail);
					try {
						var res = await fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: data.toString() });
						var json = await res.json();
						var msg = (json && json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'No se pudo reenviar el correo.', 'arriendo-facil' ) ); ?>;
						showAlert(msg, json && json.success ? 'success' : 'error');
					} catch (err) {
						showAlert(<?php echo wp_json_encode( __( 'No se pudo conectar con el servidor.', 'arriendo-facil' ) ); ?>, 'error');
					} finally {
						resendBtn.disabled = false;
					}
				});
			}

			var purgeBtn = wrap.querySelector('[data-af-purge-demo]');
			if(purgeBtn){
				purgeBtn.addEventListener('click', async function(){
					if(!window.confirm(<?php echo wp_json_encode( __( 'Se eliminarán los datos de ejemplo de tu demo. Continuar?', 'arriendo-facil' ) ); ?>)){ return; }
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

		if ( ! $this->can_manage_own_profile() ) {
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
		if ( class_exists( 'AF_Text_Normalizer' ) && in_array( $id_type, array( 'cedula', 'ruc', 'pasaporte' ), true ) ) {
			$id_number = AF_Text_Normalizer::document( $id_type, $id_number );
		}
		$nationality  = isset( $_POST['nationality'] ) ? sanitize_text_field( wp_unslash( $_POST['nationality'] ) ) : '';
		$birth_city   = isset( $_POST['birth_city'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_city'] ) ) : '';

		$current_id_enc = (string) get_user_meta( $user_id, 'af_admin_id_number_enc', true );
		$current_id_type = (string) get_user_meta( $user_id, 'af_admin_id_type', true );

		if ( in_array( $id_type, array( 'cedula', 'ruc', 'pasaporte' ), true ) && '' !== $id_number ) {
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
			if ( ! in_array( $id_type, array( 'cedula', 'ruc', 'pasaporte' ), true ) ) {
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

		if ( ! $this->can_manage_own_profile() ) {
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
	 * AJAX: recomputes an admin's verification status from the stored
	 * identity and documents (super admin only). Fixes accounts where the
	 * af_admin_doc_status meta went stale even though the data exists.
	 *
	 * @return void
	 */
	public function ajax_recompute_status() {
		check_ajax_referer( self::REVIEW_NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Usuario invalido.', 'arriendo-facil' ) ), 400 );
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Administrador no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		$status = $this->next_doc_status( $user_id );
		update_user_meta( $user_id, 'af_admin_doc_status', $status );

		if ( '' === get_user_meta( $user_id, 'af_admin_identity_match_status', true ) ) {
			update_user_meta( $user_id, 'af_admin_identity_match_status', 'not_checked' );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Estado de verificacion recalculado.', 'arriendo-facil' ),
				'doc_status' => $status,
			)
		);
	}

	/**
	 * AJAX: purges the demo dataset of the current admin.
	 *
	 * @return void
	 */
	public function ajax_purge_demo() {
		check_ajax_referer( self::PROFILE_NONCE, 'nonce' );

		if ( ! $this->can_manage_own_profile() ) {
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
	 * AJAX: super-admin detail view for a self-registered admin verification.
	 *
	 * Returns the profile data plus each uploaded support document (with a
	 * short-lived download URL when present) so the reviewer can check that
	 * the information is correct before approving/rejecting.
	 *
	 * @return void
	 */
	public function ajax_review_detail() {
		check_ajax_referer( self::REVIEW_NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Usuario invalido.', 'arriendo-facil' ) ), 400 );
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'af_property_admin', (array) $user->roles, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Administrador no encontrado.', 'arriendo-facil' ) ), 404 );
		}

		$id_enc    = (string) get_user_meta( $user_id, 'af_admin_id_number_enc', true );
		$id_type   = (string) get_user_meta( $user_id, 'af_admin_id_type', true );
		$id_masked = '';

		if ( '' !== $id_enc ) {
			$id_plain = $this->decrypt_sensitive_value( $id_enc );
			if ( '' !== $id_plain ) {
				$id_plain  = preg_replace( '/[^A-Za-z0-9]/', '', $id_plain );
				$id_masked = strlen( $id_plain ) >= 4 ? '••••' . substr( $id_plain, -4 ) : '••••';
			}
		}

		$documents = (array) get_user_meta( $user_id, 'af_admin_documents', true );
		$docs_out  = array();

		foreach ( self::DOC_TYPES as $doc_type ) {
			$item = array(
				'doc_type'    => $doc_type,
				'label'       => $this->doc_type_label( $doc_type ),
				'present'     => false,
				'storage'     => '',
				'file_size'   => 0,
				'uploaded_at' => '',
				'download_url'=> '',
			);

			if ( ! empty( $documents[ $doc_type ] ) && is_array( $documents[ $doc_type ] ) ) {
				$doc = $documents[ $doc_type ];

				$item['present']     = true;
				$item['storage']     = isset( $doc['storage'] ) ? (string) $doc['storage'] : 'local';
				$item['file_size']   = isset( $doc['file_size'] ) ? (int) $doc['file_size'] : 0;
				$item['uploaded_at'] = isset( $doc['uploaded_at'] ) ? (string) $doc['uploaded_at'] : '';

				$object_key = isset( $doc['object_key'] ) ? (string) $doc['object_key'] : '';
				if ( 'r2' === $item['storage'] && '' !== $object_key && class_exists( 'Arriendo_Facil_Private_Storage' ) ) {
					$signed = Arriendo_Facil_Private_Storage::presigned_get_url( $object_key, 600 );
					if ( is_string( $signed ) && '' !== $signed ) {
						$item['download_url'] = $signed;
					}
				} elseif ( 'local' === $item['storage'] && '' !== $object_key ) {
					$attachment_url = wp_get_attachment_url( (int) $object_key );
					if ( is_string( $attachment_url ) && '' !== $attachment_url ) {
						$item['download_url'] = $attachment_url;
					}
				}
			}

			$docs_out[] = $item;
		}

		$verified_by = (int) get_user_meta( $user_id, 'af_admin_doc_verified_by', true );
		$verified_at = (string) get_user_meta( $user_id, 'af_admin_doc_verified_at', true );
		$verified_by_name = '';
		if ( $verified_by ) {
			$verified_user = get_userdata( $verified_by );
			$verified_by_name = $verified_user ? $verified_user->display_name : (string) $verified_by;
		}

		$signup_source = (string) get_user_meta( $user_id, 'af_signup_source', true );
		$doc_status    = (string) get_user_meta( $user_id, 'af_admin_doc_status', true );
		$identity_match = (string) get_user_meta( $user_id, 'af_admin_identity_match_status', true );

		wp_send_json_success(
			array(
				'user_id'        => $user_id,
				'display_name'   => $user->display_name,
				'company'        => (string) get_user_meta( $user_id, 'af_company_name', true ),
				'contact_name'   => (string) get_user_meta( $user_id, 'af_contact_name', true ),
				'phone'          => (string) get_user_meta( $user_id, 'af_contact_phone', true ),
				'email'          => $user->user_email,
				'nationality'    => (string) get_user_meta( $user_id, 'af_admin_nationality', true ),
				'birth_city'     => (string) get_user_meta( $user_id, 'af_admin_birth_city', true ),
				'id_type'        => $id_type,
				'id_masked'      => $id_masked,
				'license_status' => (string) get_user_meta( $user_id, 'af_license_status', true ),
				'signup_source'  => $signup_source ? $signup_source : 'manual',
				'doc_status'     => $doc_status ? $doc_status : 'pendiente',
				'identity_match' => $identity_match ? $identity_match : 'not_checked',
				'doc_notes'      => (string) get_user_meta( $user_id, 'af_admin_doc_notes', true ),
				'verified_by'    => $verified_by_name,
				'verified_at'    => $verified_at,
				'documents'      => $docs_out,
			)
		);
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
	 * Disabled by default: property admins see all modules (matching the
	 * product's intended dashboard) as soon as they sign up; identity review
	 * stays informational (banner + super admin review screen) instead of a
	 * hard block. Re-enable with `add_filter('af_property_admin_gate_operational_menus', '__return_true')`.
	 *
	 * @return void
	 */
	public function hide_operational_menus_for_unverified() {
		if ( ! apply_filters( 'af_property_admin_gate_operational_menus', false ) ) {
			return;
		}

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
	 * Disabled by default — see `hide_operational_menus_for_unverified()`.
	 *
	 * @return void
	 */
	public function enforce_verification_gate() {
		if ( ! apply_filters( 'af_property_admin_gate_operational_menus', false ) ) {
			return;
		}

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
		$identity_ok  = in_array( $id_type, array( 'cedula', 'ruc', 'pasaporte' ), true ) && '' !== $id_enc;

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
	 * Decrypts sensitive admin data previously encrypted with
	 * encrypt_sensitive_value() (sodium secretbox, 'v1:' payload).
	 *
	 * Returns '' whenever the value cannot be decrypted (invalid format,
	 * missing sodium extension, or corrupted payload). Never throws.
	 *
	 * @param string $encrypted Encrypted value.
	 * @return string
	 */
	private function decrypt_sensitive_value( $encrypted ) {
		$encrypted = (string) $encrypted;
		if ( '' === $encrypted || 0 !== strpos( $encrypted, 'v1:' ) ) {
			return '';
		}

		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || ! function_exists( 'base64_decode' ) ) {
			return '';
		}

		try {
			$key_material = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . 'af_admin_sensitive_v1', true );
			if ( ! is_string( $key_material ) || 32 !== strlen( $key_material ) ) {
				return '';
			}

			$payload = base64_decode( substr( $encrypted, 3 ), true );
			if ( false === $payload || strlen( $payload ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}

			$nonce      = substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			$plain = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key_material );
			return false !== $plain ? (string) $plain : '';
		} catch ( Exception $exception ) {
			error_log( '[AF Security] admin sensitive decryption failure: ' . $exception->getMessage() );
			return '';
		}
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