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
<main id="main-content" class="afx-main afp" data-afp>
	<div class="afp__bg" aria-hidden="true"><span class="afp__blob afp__blob--a"></span><span class="afp__blob afp__blob--b"></span></div>

	<?php if ( ! $af_manager ) : ?>

		<div class="afp__wrap afp__wrap--single">
			<section class="afp-card afp-card--center" data-afp-panel>
				<h1 class="afp-h1 afp-h1--dark"><?php esc_html_e( 'Enlace no disponible', 'arriendo-facil' ); ?></h1>
				<p class="afp-muted"><?php esc_html_e( 'Este enlace ya no es válido. Pide uno nuevo al administrador del inmueble.', 'arriendo-facil' ); ?></p>
			</section>
		</div>

	<?php else : ?>
		<?php
		$af_locked    = ! empty( $af_ctx['locked'] ) && $af_selected;
		$af_prop_name = $af_locked ? get_the_title( $af_selected ) : '';
		$af_prop_img  = $af_locked ? get_the_post_thumbnail_url( $af_selected, 'large' ) : '';
		$af_prop_addr = $af_locked ? trim( (string) get_post_meta( $af_selected, '_af_address', true ) . ' ' . (string) get_post_meta( $af_selected, '_af_city', true ) ) : '';
		$af_steps     = array(
			array( __( 'Pre-registro', 'arriendo-facil' ), __( 'Tus 5 datos básicos. Menos de un minuto.', 'arriendo-facil' ) ),
			array( __( 'Formulario completo', 'arriendo-facil' ), __( 'El administrador te envía un enlace seguro por WhatsApp o correo.', 'arriendo-facil' ) ),
			array( __( 'Verificación', 'arriendo-facil' ), __( 'Revisa tu información y continúa con el contrato.', 'arriendo-facil' ) ),
		);
		?>

		<div class="afp__wrap">

			<aside class="afp-story" data-afp-story>
				<p class="afp-eyebrow"><?php echo esc_html( $af_name ); ?></p>
				<h1 class="afp-h1"><?php esc_html_e( 'Cuéntanos quién eres', 'arriendo-facil' ); ?></h1>
				<p class="afp-lead"><?php esc_html_e( 'Son solo 5 datos. Luego el administrador te enviará el formulario completo.', 'arriendo-facil' ); ?></p>

				<?php if ( $af_locked ) : ?>
					<figure class="afp-prop" data-afp-prop aria-label="<?php esc_attr_e( 'Inmueble seleccionado', 'arriendo-facil' ); ?>">
						<?php if ( $af_prop_img ) : ?>
							<img class="afp-prop__img" src="<?php echo esc_url( $af_prop_img ); ?>" alt="<?php echo esc_attr( $af_prop_name ); ?>" width="640" height="400" fetchpriority="high" decoding="async" />
						<?php else : ?>
							<span class="afp-prop__img afp-prop__img--empty" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5V21H3z"/><path d="M9 21v-6h6v6"/></svg>
							</span>
						<?php endif; ?>
						<figcaption class="afp-prop__cap">
							<span class="afp-chip"><?php esc_html_e( 'Te interesa', 'arriendo-facil' ); ?></span>
							<strong><?php echo esc_html( $af_prop_name ); ?></strong>
							<?php if ( $af_prop_addr ) : ?>
								<small><?php echo esc_html( $af_prop_addr ); ?></small>
							<?php endif; ?>
						</figcaption>
					</figure>
				<?php endif; ?>

				<ol class="afp-steps" aria-label="<?php esc_attr_e( 'Cómo funciona', 'arriendo-facil' ); ?>">
					<?php foreach ( $af_steps as $af_i => $af_step ) : ?>
						<li class="afp-step" data-afp-step>
							<span class="afp-step__num"><?php echo esc_html( (string) ( $af_i + 1 ) ); ?></span>
							<span><strong><?php echo esc_html( $af_step[0] ); ?></strong><small><?php echo esc_html( $af_step[1] ); ?></small></span>
						</li>
					<?php endforeach; ?>
				</ol>
			</aside>

			<section class="afp-card" data-pr-form-card data-afp-panel aria-labelledby="afp-form-title">
				<header class="afp-card__head">
					<h2 id="afp-form-title"><?php esc_html_e( 'Tus datos', 'arriendo-facil' ); ?></h2>
					<span class="afp-req"><abbr title="<?php esc_attr_e( 'Obligatorio', 'arriendo-facil' ); ?>">*</abbr> <?php esc_html_e( 'obligatorios', 'arriendo-facil' ); ?></span>
				</header>

				<p class="afp-alert" role="alert" data-pr-alert hidden></p>

				<?php if ( ! $af_locked && empty( $af_properties ) ) : ?>
					<p class="afp-empty"><?php esc_html_e( 'Por ahora no hay inmuebles disponibles para pre-registro. Vuelve a intentarlo más tarde.', 'arriendo-facil' ); ?></p>
				<?php else : ?>

				<form class="afp-form" data-pr-form novalidate>
					<input type="hidden" name="key" value="<?php echo esc_attr( Arriendo_Facil_Tenant_Prereg::manager_key( (int) $af_manager->ID, false ) ); ?>" />
					<input type="hidden" name="source" value="<?php echo esc_attr( $af_source ); ?>" />
					<input type="hidden" name="form_ts" value="<?php echo esc_attr( (string) $af_ts ); ?>" />
					<input type="hidden" name="form_sig" value="<?php echo esc_attr( Arriendo_Facil_Tenant_Prereg::form_sig( $af_ts ) ); ?>" />
					<input class="afp-hp" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" />

					<div class="afp-grid">
						<div class="afp-field" data-afp-field>
							<label for="afp-first"><?php esc_html_e( 'Nombres', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'Obligatorio', 'arriendo-facil' ); ?>">*</abbr></label>
							<input id="afp-first" name="first_name" type="text" autocomplete="given-name" maxlength="80" placeholder="<?php esc_attr_e( 'Ej. María José', 'arriendo-facil' ); ?>" required />
							<small class="afp-err" data-err-for="first_name"></small>
						</div>
						<div class="afp-field" data-afp-field>
							<label for="afp-last"><?php esc_html_e( 'Apellidos', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'Obligatorio', 'arriendo-facil' ); ?>">*</abbr></label>
							<input id="afp-last" name="last_name" type="text" autocomplete="family-name" maxlength="80" placeholder="<?php esc_attr_e( 'Ej. Pérez Loor', 'arriendo-facil' ); ?>" required />
							<small class="afp-err" data-err-for="last_name"></small>
						</div>
						<div class="afp-field afp-field--wide" data-afp-field>
							<label for="afp-email"><?php esc_html_e( 'Correo electrónico', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'Obligatorio', 'arriendo-facil' ); ?>">*</abbr></label>
							<input id="afp-email" name="email" type="email" autocomplete="email" inputmode="email" autocapitalize="off" maxlength="190" placeholder="nombre@correo.com" required />
							<small class="afp-err" data-err-for="email"></small>
						</div>
						<div class="afp-field afp-field--wide" data-afp-field>
							<label for="afp-phone"><?php esc_html_e( 'Teléfono / WhatsApp', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'Obligatorio', 'arriendo-facil' ); ?>">*</abbr></label>
							<input id="afp-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="20" placeholder="0991234567" required />
							<small class="afp-hint"><?php esc_html_e( 'Por aquí te escribirá el administrador.', 'arriendo-facil' ); ?></small>
							<small class="afp-err" data-err-for="phone"></small>
						</div>

						<?php if ( $af_locked ) : ?>
							<input type="hidden" name="accommodation_id" value="<?php echo esc_attr( $af_selected ); ?>" />
							<input type="hidden" name="prop_sig" value="<?php echo esc_attr( Arriendo_Facil_Tenant_Prereg::property_sig( (int) $af_manager->ID, $af_selected ) ); ?>" />
							<p class="afp-locked afp-field--wide">
								<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
								<span><?php esc_html_e( 'Inmueble:', 'arriendo-facil' ); ?> <strong><?php echo esc_html( $af_prop_name ); ?></strong></span>
							</p>
						<?php else : ?>
							<div class="afp-field afp-field--wide" data-afp-field>
								<label for="afp-property"><?php esc_html_e( 'Inmueble que te interesa', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'Obligatorio', 'arriendo-facil' ); ?>">*</abbr></label>
								<select id="afp-property" name="accommodation_id" required>
									<option value=""><?php esc_html_e( 'Selecciona un inmueble…', 'arriendo-facil' ); ?></option>
									<?php foreach ( $af_properties as $af_pid => $af_ptitle ) : ?>
										<option value="<?php echo esc_attr( $af_pid ); ?>"><?php echo esc_html( $af_ptitle ); ?></option>
									<?php endforeach; ?>
								</select>
								<small class="afp-err" data-err-for="accommodation_id"></small>
							</div>
						<?php endif; ?>
					</div>

					<div class="afp-consent" data-afp-field>
						<?php Arriendo_Facil_Consent::render( 'tenant_prereg' ); ?>
					</div>

					<button class="afp-btn" type="submit" data-pr-submit><?php esc_html_e( 'Enviar pre-registro', 'arriendo-facil' ); ?></button>
					<p class="afp-fine">
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2 4 6v6c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/></svg>
						<?php esc_html_e( 'Tus datos solo se comparten con el administrador de este inmueble.', 'arriendo-facil' ); ?>
					</p>
				</form>

				<?php endif; ?>
			</section>

			<section class="afp-card afp-card--center afp-done" data-pr-done data-afp-panel hidden aria-live="polite">
				<span class="afp-done__badge" data-afp-badge aria-hidden="true">
					<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
				</span>
				<h2 class="afp-h2"><?php esc_html_e( '¡Listo, recibimos tu pre-registro!', 'arriendo-facil' ); ?></h2>
				<p class="afp-muted" data-pr-done-text></p>
				<a class="afp-btn afp-btn--wa" data-pr-wa href="#" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e( 'Abrir WhatsApp', 'arriendo-facil' ); ?></a>
				<p class="afp-fine" data-pr-nowa hidden><?php esc_html_e( 'Tu administrador se pondrá en contacto contigo con el formulario completo.', 'arriendo-facil' ); ?></p>
			</section>

		</div>

	<?php endif; ?>
</main>
<?php
get_footer();
