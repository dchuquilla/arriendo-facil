<?php
/**
 * Catalogs: editor screen.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$raw_id     = isset( $_GET['catalog'] ) ? sanitize_text_field( wp_unslash( $_GET['catalog'] ) ) : 'new'; // phpcs:ignore WordPress.Security.NonceVerification
$catalog_id = 'new' === $raw_id ? 0 : absint( $raw_id );

if ( $catalog_id && ( ! Arriendo_Facil_Catalogs::user_can_manage( $catalog_id ) || Arriendo_Facil_Catalogs::is_archived( $catalog_id ) ) ) {
	echo '<div class="wrap"><p>' . esc_html__( 'Este catálogo no existe o está archivado.', 'arriendo-facil' ) . ' <a href="' . esc_url( Arriendo_Facil_Catalogs::admin_url() ) . '">' . esc_html__( 'Volver a Mis catálogos', 'arriendo-facil' ) . '</a></p></div>';
	return;
}

$back     = Arriendo_Facil_Catalogs::admin_url();
$name     = '';
$selected = array();
$link     = null;

if ( $catalog_id ) {
	Arriendo_Facil_Catalogs::touch( $catalog_id );
	$name     = get_the_title( $catalog_id );
	$selected = Arriendo_Facil_Catalogs::property_ids( $catalog_id );
	$link     = Arriendo_Facil_Catalogs::link( $catalog_id );
}

$props = Arriendo_Facil_Catalogs::pickable_properties( get_current_user_id() );
$msg   = isset( $_GET['af_msg'] ) ? sanitize_key( wp_unslash( $_GET['af_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
?>
<div class="wrap af-shell af-catalogs-page">
	<p><a href="<?php echo esc_url( $back ); ?>">← <?php esc_html_e( 'Mis catálogos', 'arriendo-facil' ); ?></a></p>

	<?php
	af_page_header(
		array(
			'eyebrow' => __( 'Catálogo', 'arriendo-facil' ),
			'title'   => $catalog_id ? __( 'Editar catálogo', 'arriendo-facil' ) : __( 'Nuevo catálogo', 'arriendo-facil' ),
		)
	);
	?>

	<?php if ( in_array( $msg, array( 'created', 'saved', 'duplicated' ), true ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Listo, cambios guardados.', 'arriendo-facil' ); ?></p></div>
	<?php elseif ( 0 === strpos( $msg, 'slug_' ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'El enlace personalizado no es válido o ya está en uso. Se guardó lo demás.', 'arriendo-facil' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $link ) : ?>
		<div class="af-cats-linkbox">
			<input type="text" readonly value="<?php echo esc_attr( $link['url'] ); ?>" onfocus="this.select()">
			<button type="button" class="button button-primary" data-af-copy="<?php echo esc_attr( $link['url'] ); ?>"><?php esc_html_e( 'Copiar enlace', 'arriendo-facil' ); ?></button>
			<a class="button" href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver', 'arriendo-facil' ); ?></a>
			<a class="button" href="<?php echo esc_url( $link['pdf_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'PDF', 'arriendo-facil' ); ?></a>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="af-cats-editor" data-af-cats-editor>
		<input type="hidden" name="action" value="af_catalog_save">
		<input type="hidden" name="catalog_id" value="<?php echo esc_attr( $catalog_id ); ?>">
		<?php wp_nonce_field( 'af_catalog_save' ); ?>

		<label class="af-cats-field">
			<span><?php esc_html_e( 'Nombre del catálogo', 'arriendo-facil' ); ?></span>
			<input type="text" name="catalog_name" value="<?php echo esc_attr( $name ); ?>" maxlength="120" placeholder="<?php esc_attr_e( 'Ej. Apartamentos zona norte', 'arriendo-facil' ); ?>">
		</label>

		<?php if ( $catalog_id ) : ?>
			<details class="af-cats-slug">
				<summary><?php esc_html_e( 'Personalizar enlace', 'arriendo-facil' ); ?></summary>
				<input type="text" name="catalog_slug" value="<?php echo esc_attr( $link['slug'] ); ?>" placeholder="mi-catalogo" pattern="[a-z0-9][a-z0-9\-]{1,62}[a-z0-9]">
			</details>
		<?php endif; ?>

		<div class="af-cats-picker">
			<div class="af-cats-picker__bar">
				<input type="search" data-af-cats-search placeholder="<?php esc_attr_e( 'Buscar propiedad…', 'arriendo-facil' ); ?>">
				<label><input type="checkbox" data-af-cats-available> <?php esc_html_e( 'Solo disponibles', 'arriendo-facil' ); ?></label>
				<button type="button" class="button-link" data-af-cats-all><?php esc_html_e( 'Todas', 'arriendo-facil' ); ?></button>
				<button type="button" class="button-link" data-af-cats-none><?php esc_html_e( 'Ninguna', 'arriendo-facil' ); ?></button>
			</div>

			<?php if ( empty( $props ) ) : ?>
				<p><?php esc_html_e( 'Aún no tienes propiedades publicadas.', 'arriendo-facil' ); ?></p>
			<?php endif; ?>

			<ul class="af-cats-list">
				<?php foreach ( $props as $p ) : ?>
					<li class="af-cats-row" data-status="<?php echo esc_attr( $p['status'] ); ?>" data-text="<?php echo esc_attr( strtolower( $p['title'] . ' ' . $p['address'] . ' ' . $p['type_lbl'] ) ); ?>">
						<label>
							<input type="checkbox" name="property_ids[]" value="<?php echo esc_attr( $p['id'] ); ?>" <?php checked( in_array( $p['id'], $selected, true ) ); ?>>
							<?php if ( $p['thumb'] ) : ?>
								<img src="<?php echo esc_url( $p['thumb'] ); ?>" alt="" loading="lazy">
							<?php else : ?>
								<span class="af-cats-row__noimg"></span>
							<?php endif; ?>
							<span class="af-cats-row__info">
								<strong><?php echo esc_html( $p['title'] ); ?></strong>
								<small><?php echo esc_html( $p['address'] ); ?></small>
							</span>
							<span class="af-cats-row__status"><?php echo esc_html( $p['status_lbl'] ); ?></span>
							<?php if ( $p['rent'] > 0 ) : ?>
								<span class="af-cats-row__rent">$<?php echo esc_html( number_format_i18n( $p['rent'], 0 ) ); ?></span>
							<?php endif; ?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="af-cats-empty" hidden></p>
		</div>

		<div class="af-cats-savebar">
			<span data-af-cats-count></span>
			<a class="button" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></a>
			<button type="submit" class="button button-primary"><?php echo esc_html( $catalog_id ? __( 'Guardar cambios', 'arriendo-facil' ) : __( 'Crear catálogo', 'arriendo-facil' ) ); ?></button>
		</div>
	</form>
</div>
