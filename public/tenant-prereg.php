<?php
/**
 * Pre-registro de interesados (https://sitio/preregistro/<clave>/).
 *
 * Formulario corto y público, ligado al gestor dueño de la clave. Al enviarlo
 * se abre WhatsApp hacia el gestor con un mensaje listo para pedirle el enlace
 * del formulario completo.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$af_ctx        = isset( $GLOBALS['af_prereg'] ) ? $GLOBALS['af_prereg'] : array();
$af_manager    = isset( $af_ctx['manager'] ) ? $af_ctx['manager'] : null;
$af_properties = isset( $af_ctx['properties'] ) ? (array) $af_ctx['properties'] : array();
$af_selected   = isset( $af_ctx['selected'] ) ? (int) $af_ctx['selected'] : 0;
$af_source     = isset( $_GET['origen'] ) && 'catalogo' === sanitize_key( wp_unslash( $_GET['origen'] ) ) ? 'catalogo' : 'link'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$af_ts         = time();
$af_name       = '';
if ( $af_manager ) {
	$af_name = (string) get_user_meta( (int) $af_manager->ID, 'af_contact_name', true );
	if ( '' === trim( $af_name ) ) {
		$af_name = $af_manager->display_name;
	}
}

get_header();
?>
<main class="af-tp" id="af-tp-prereg">

	<?php if ( ! $af_manager ) : ?>

		<section class="af-tp-card af-tp-login">
			<h1 class="af-tp-title"><?php esc_html_e( 'Enlace no disponible', 'arriendo-facil' ); ?></h1>
			<p class="af-tp-lead"><?php esc_html_e( 'Este enlace ya no es válido. Pide uno nuevo al administrador del inmueble.', 'arriendo-facil' ); ?></p>
		</section>

	<?php else : ?>

		<section class="af-tp-card" data-pr-form-card aria-labelledby="af-pr-title">
			<p class="af-tp-eyebrow"><?php echo esc_html( $af_name ); ?></p>
			<h1 class="af-tp-title" id="af-pr-title"><?php esc_html_e( 'Pre-registro', 'arriendo-facil' ); ?></h1>
			<p class="af-tp-lead"><?php esc_html_e( 'Déjanos tus datos básicos. Luego tu administrador te enviará un formulario completo para terminar el registro.', 'arriendo-facil' ); ?></p>

			<p class="af-tp-alert" role="alert" data-pr-alert hidden></p>

			<form class="af-tp-form" data-pr-form novalidate>
				<input type="hidden" name="key" value="<?php echo esc_attr( Arriendo_Facil_Tenant_Prereg::manager_key( (int) $af_manager->ID, false ) ); ?>" />
				<input type="hidden" name="source" value="<?php echo esc_attr( $af_source ); ?>" />
				<input type="hidden" name="form_ts" value="<?php echo esc_attr( (string) $af_ts ); ?>" />
				<input type="hidden" name="form_sig" value="<?php echo esc_attr( Arriendo_Facil_Tenant_Prereg::form_sig( $af_ts ) ); ?>" />
				<input class="af-tp-prereg__hp" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" />

				<div class="af-tp-prereg__grid">
					<div>
						<label class="af-tp-label" for="af-pr-first"><?php esc_html_e( 'Nombres', 'arriendo-facil' ); ?></label>
						<input class="af-tp-input" id="af-pr-first" name="first_name" type="text" autocomplete="given-name" maxlength="80" required />
					</div>
					<div>
						<label class="af-tp-label" for="af-pr-last"><?php esc_html_e( 'Apellidos', 'arriendo-facil' ); ?></label>
						<input class="af-tp-input" id="af-pr-last" name="last_name" type="text" autocomplete="family-name" maxlength="80" required />
					</div>
					<div class="is-wide">
						<label class="af-tp-label" for="af-pr-email"><?php esc_html_e( 'Correo', 'arriendo-facil' ); ?></label>
						<input class="af-tp-input" id="af-pr-email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="190" required />
					</div>
					<div class="is-wide">
						<label class="af-tp-label" for="af-pr-phone"><?php esc_html_e( 'Teléfono / WhatsApp', 'arriendo-facil' ); ?></label>
						<input class="af-tp-input" id="af-pr-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="20" required />
					</div>

					<?php if ( $af_properties ) : ?>
						<div class="is-wide">
							<label class="af-tp-label" for="af-pr-property"><?php esc_html_e( 'Inmueble que te interesa', 'arriendo-facil' ); ?></label>
							<select class="af-tp-input" id="af-pr-property" name="accommodation_id">
								<option value=""><?php esc_html_e( 'Aún no lo sé', 'arriendo-facil' ); ?></option>
								<?php foreach ( $af_properties as $af_pid => $af_ptitle ) : ?>
									<option value="<?php echo esc_attr( $af_pid ); ?>" <?php selected( $af_selected, $af_pid ); ?>><?php echo esc_html( $af_ptitle ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>

					<div class="is-wide">
						<label class="af-tp-label" for="af-pr-note"><?php esc_html_e( 'Mensaje (opcional)', 'arriendo-facil' ); ?></label>
						<textarea class="af-tp-input" id="af-pr-note" name="note" rows="3" maxlength="300"></textarea>
					</div>
				</div>

				<?php Arriendo_Facil_Consent::render( 'tenant_prereg' ); ?>

				<button class="af-tp-btn af-tp-btn--primary" type="submit" data-pr-submit><?php esc_html_e( 'Enviar pre-registro', 'arriendo-facil' ); ?></button>
			</form>
		</section>

		<section class="af-tp-card af-tp-done" data-pr-done hidden aria-live="polite">
			<span class="af-tp-badge" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
			</span>
			<h2 class="af-tp-title"><?php esc_html_e( '¡Listo, recibimos tu pre-registro!', 'arriendo-facil' ); ?></h2>
			<p class="af-tp-lead" data-pr-done-text></p>
			<a class="af-tp-btn af-tp-btn--wa" data-pr-wa href="#" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e( 'Abrir WhatsApp', 'arriendo-facil' ); ?></a>
			<p class="af-tp-fine" data-pr-nowa hidden><?php esc_html_e( 'Tu administrador se pondrá en contacto contigo con el formulario completo.', 'arriendo-facil' ); ?></p>
		</section>

	<?php endif; ?>
</main>
<?php
get_footer();
