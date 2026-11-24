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
<main class="af-tp af-tp--prereg" id="af-tp-prereg">

	<?php if ( ! $af_manager ) : ?>

		<section class="af-tp-card af-tp-login">
			<h1 class="af-tp-title"><?php esc_html_e( 'Enlace no disponible', 'arriendo-facil' ); ?></h1>
			<p class="af-tp-lead"><?php esc_html_e( 'Este enlace ya no es válido. Pide uno nuevo al administrador del inmueble.', 'arriendo-facil' ); ?></p>
		</section>

	<?php else : ?>
		<?php
		$af_locked    = ! empty( $af_ctx['locked'] ) && $af_selected;
		$af_prop_name = $af_locked ? get_the_title( $af_selected ) : '';
		$af_prop_img  = $af_locked ? get_the_post_thumbnail_url( $af_selected, 'medium' ) : '';
		$af_prop_addr = $af_locked ? trim( (string) get_post_meta( $af_selected, '_af_address', true ) . ' ' . (string) get_post_meta( $af_selected, '_af_city', true ) ) : '';
		?>

		<section class="af-pr" data-pr-form-card aria-labelledby="af-pr-title">
			<header class="af-pr__hero">
				<p class="af-pr__eyebrow"><?php echo esc_html( $af_name ); ?></p>
				<h1 class="af-pr__title" id="af-pr-title"><?php esc_html_e( 'Cuéntanos quién eres', 'arriendo-facil' ); ?></h1>
				<p class="af-pr__lead"><?php esc_html_e( 'Son solo 5 datos. Luego el administrador te enviará un formulario completo.', 'arriendo-facil' ); ?></p>
			</header>

			<div class="af-pr__body">
				<?php if ( $af_locked ) : ?>
					<div class="af-pr__prop" aria-label="<?php esc_attr_e( 'Inmueble seleccionado', 'arriendo-facil' ); ?>">
						<?php if ( $af_prop_img ) : ?>
							<img class="af-pr__thumb" src="<?php echo esc_url( $af_prop_img ); ?>" alt="" loading="lazy" />
						<?php else : ?>
							<span class="af-pr__thumb af-pr__thumb--empty" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5V21H3z"/><path d="M9 21v-6h6v6"/></svg>
							</span>
						<?php endif; ?>
						<div class="af-pr__propinfo">
							<span class="af-pr__chip"><?php esc_html_e( 'Te interesa', 'arriendo-facil' ); ?></span>
							<strong><?php echo esc_html( $af_prop_name ); ?></strong>
							<?php if ( $af_prop_addr ) : ?>
								<small><?php echo esc_html( $af_prop_addr ); ?></small>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>

				<p class="af-tp-alert" role="alert" data-pr-alert hidden></p>

				<?php if ( ! $af_locked && empty( $af_properties ) ) : ?>
					<p class="af-tp-empty"><?php esc_html_e( 'Por ahora no hay inmuebles disponibles para pre-registro. Vuelve a intentarlo más tarde.', 'arriendo-facil' ); ?></p>
				<?php else : ?>

				<form class="af-pr__form" data-pr-form novalidate>
					<input type="hidden" name="key" value="<?php echo esc_attr( Arriendo_Facil_Tenant_Prereg::manager_key( (int) $af_manager->ID, false ) ); ?>" />
					<input type="hidden" name="source" value="<?php echo esc_attr( $af_source ); ?>" />
					<input type="hidden" name="form_ts" value="<?php echo esc_attr( (string) $af_ts ); ?>" />
					<input type="hidden" name="form_sig" value="<?php echo esc_attr( Arriendo_Facil_Tenant_Prereg::form_sig( $af_ts ) ); ?>" />
					<input class="af-tp-prereg__hp" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" />

					<div class="af-pr__grid">
						<div class="af-pr__field">
							<label for="af-pr-first"><?php esc_html_e( 'Nombres', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'Obligatorio', 'arriendo-facil' ); ?>">*</abbr></label>
							<input id="af-pr-first" name="first_name" type="text" autocomplete="given-name" maxlength="80" placeholder="<?php esc_attr_e( 'Ej. María José', 'arriendo-facil' ); ?>" required />
							<small class="af-pr__err" data-err-for="first_name"></small>
						</div>
						<div class="af-pr__field">
							<label for="af-pr-last"><?php esc_html_e( 'Apellidos', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'Obligatorio', 'arriendo-facil' ); ?>">*</abbr></label>
							<input id="af-pr-last" name="last_name" type="text" autocomplete="family-name" maxlength="80" placeholder="<?php esc_attr_e( 'Ej. Pérez Loor', 'arriendo-facil' ); ?>" required />
							<small class="af-pr__err" data-err-for="last_name"></small>
						</div>
						<div class="af-pr__field af-pr__field--wide">
							<label for="af-pr-email"><?php esc_html_e( 'Correo electrónico', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'Obligatorio', 'arriendo-facil' ); ?>">*</abbr></label>
							<input id="af-pr-email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="190" placeholder="nombre@correo.com" required />
							<small class="af-pr__err" data-err-for="email"></small>
						</div>
						<div class="af-pr__field af-pr__field--wide">
							<label for="af-pr-phone"><?php esc_html_e( 'Teléfono / WhatsApp', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'Obligatorio', 'arriendo-facil' ); ?>">*</abbr></label>
							<input id="af-pr-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="20" placeholder="0991234567" required />
							<small class="af-pr__hint"><?php esc_html_e( 'Por aquí te escribirá el administrador.', 'arriendo-facil' ); ?></small>
							<small class="af-pr__err" data-err-for="phone"></small>
						</div>

						<?php if ( $af_locked ) : ?>
							<input type="hidden" name="accommodation_id" value="<?php echo esc_attr( $af_selected ); ?>" />
							<input type="hidden" name="prop_sig" value="<?php echo esc_attr( Arriendo_Facil_Tenant_Prereg::property_sig( (int) $af_manager->ID, $af_selected ) ); ?>" />
						<?php else : ?>
							<div class="af-pr__field af-pr__field--wide">
								<label for="af-pr-property"><?php esc_html_e( 'Inmueble que te interesa', 'arriendo-facil' ); ?> <abbr title="<?php esc_attr_e( 'Obligatorio', 'arriendo-facil' ); ?>">*</abbr></label>
								<select id="af-pr-property" name="accommodation_id" required>
									<option value=""><?php esc_html_e( 'Selecciona un inmueble…', 'arriendo-facil' ); ?></option>
									<?php foreach ( $af_properties as $af_pid => $af_ptitle ) : ?>
										<option value="<?php echo esc_attr( $af_pid ); ?>"><?php echo esc_html( $af_ptitle ); ?></option>
									<?php endforeach; ?>
								</select>
								<small class="af-pr__err" data-err-for="accommodation_id"></small>
							</div>
						<?php endif; ?>
					</div>

					<?php Arriendo_Facil_Consent::render( 'tenant_prereg' ); ?>

					<button class="af-tp-btn af-tp-btn--primary af-pr__submit" type="submit" data-pr-submit><?php esc_html_e( 'Enviar pre-registro', 'arriendo-facil' ); ?></button>
					<p class="af-tp-fine"><?php esc_html_e( 'Tus datos solo se comparten con el administrador de este inmueble.', 'arriendo-facil' ); ?></p>
				</form>

				<?php endif; ?>
			</div>
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
