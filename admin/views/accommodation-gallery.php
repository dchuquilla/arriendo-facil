<?php
/**
 * Accommodation gallery meta box view.
 *
 * @package Arriendo_Facil
 * @var int[]  $gallery_ids  Array of attachment IDs already saved.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="af-gallery-box">
	<p class="af-gallery-box__intro">
		<?php esc_html_e( 'Agrega hasta 20 fotos del inmueble. La primera foto de la galería se usará como imagen de portada si no hay foto principal definida.', 'arriendo-facil' ); ?>
	</p>

	<div id="af-gallery-grid" class="af-gallery-grid">
		<?php if ( ! empty( $gallery_ids ) ) : ?>
			<?php foreach ( $gallery_ids as $att_id ) :
				$thumb = wp_get_attachment_image_src( $att_id, 'thumbnail' );
				if ( ! $thumb ) {
					continue;
				}
			?>
				<div class="af-gallery-item" data-id="<?php echo esc_attr( $att_id ); ?>">
					<img src="<?php echo esc_url( $thumb[0] ); ?>" alt="" />
					<button type="button" class="af-gallery-item__remove" title="<?php esc_attr_e( 'Eliminar foto', 'arriendo-facil' ); ?>">&#x2715;</button>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<div class="af-gallery-actions">
		<button type="button" id="af-gallery-add-btn" class="button button-secondary af-gallery-add-btn">
			<span class="af-gallery-add-btn__icon">&#x1F4F7;</span>
			<?php esc_html_e( 'Agregar fotos', 'arriendo-facil' ); ?>
		</button>
		<span class="af-gallery-count">
			<span id="af-gallery-count-num"><?php echo count( $gallery_ids ); ?></span>
			<?php esc_html_e( 'foto(s) agregada(s)', 'arriendo-facil' ); ?>
		</span>
	</div>

	<input type="hidden" id="af_gallery_ids" name="af_gallery_ids"
		value="<?php echo esc_attr( implode( ',', array_map( 'absint', $gallery_ids ) ) ); ?>" />
</div>
