<?php
/**
 * Catalogs: list screen (editor lives in catalog-edit.php).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( isset( $_GET['catalog'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
	include __DIR__ . '/catalog-edit.php';
	return;
}

$owner_id = get_current_user_id();
$active   = Arriendo_Facil_Catalogs::list_for_owner( $owner_id, false );
$archived = Arriendo_Facil_Catalogs::list_for_owner( $owner_id, true );
$msg      = isset( $_GET['af_msg'] ) ? sanitize_key( wp_unslash( $_GET['af_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

$messages = array(
	'created'    => __( 'Catálogo creado. Ya puedes copiar su enlace.', 'arriendo-facil' ),
	'saved'      => __( 'Cambios guardados.', 'arriendo-facil' ),
	'duplicated' => __( 'Catálogo duplicado. Edita la copia.', 'arriendo-facil' ),
	'archived'   => __( 'Catálogo archivado. Su enlace ya no funciona; puedes restaurarlo cuando quieras.', 'arriendo-facil' ),
	'restored'   => __( 'Catálogo restaurado.', 'arriendo-facil' ),
	'deleted'    => __( 'Catálogo eliminado.', 'arriendo-facil' ),
	'error'      => __( 'No se pudo completar la acción.', 'arriendo-facil' ),
);

$new_url = Arriendo_Facil_Catalogs::admin_url( array( 'catalog' => 'new' ) );

/**
 * Small POST form for a catalog action.
 *
 * @param int    $id    Catalog ID.
 * @param string $do    Action key.
 * @param string $label Button label.
 * @param string $class Extra CSS class.
 * @param bool   $confirm Ask for confirmation.
 */
$af_cat_action = static function ( $id, $do, $label, $class = '', $confirm = false ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="af-cats-inline"<?php echo $confirm ? ' data-af-confirm="1"' : ''; ?>>
		<input type="hidden" name="action" value="af_catalog_action">
		<input type="hidden" name="catalog_id" value="<?php echo esc_attr( $id ); ?>">
		<input type="hidden" name="catalog_action" value="<?php echo esc_attr( $do ); ?>">
		<?php wp_nonce_field( 'af_catalog_action_' . $id ); ?>
		<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};
?>
<div class="wrap af-shell af-catalogs-page">
	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Propiedades', 'arriendo-facil' ),
			'title'    => __( 'Mis catálogos', 'arriendo-facil' ),
			'subtitle' => __( 'Crea un catálogo con las propiedades que quieras y comparte su enlace.', 'arriendo-facil' ),
		)
	);
	?>

	<?php if ( isset( $messages[ $msg ] ) ) : ?>
		<div class="notice notice-<?php echo 'error' === $msg ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $msg ] ); ?></p></div>
	<?php elseif ( 0 === strpos( $msg, 'slug_' ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'El enlace personalizado no es válido o ya está en uso.', 'arriendo-facil' ); ?></p></div>
	<?php endif; ?>

	<div class="af-cats-grid">
		<a class="af-cats-new" href="<?php echo esc_url( $new_url ); ?>">
			<span class="af-cats-new__plus">+</span>
			<strong><?php echo esc_html( empty( $active ) ? __( 'Crea tu primer catálogo', 'arriendo-facil' ) : __( 'Nuevo catálogo', 'arriendo-facil' ) ); ?></strong>
		</a>

		<?php
		foreach ( $active as $cat ) :
			$sum  = Arriendo_Facil_Catalogs::summary( $cat->ID );
			$link = Arriendo_Facil_Catalogs::link( $cat->ID );
			$edit = Arriendo_Facil_Catalogs::admin_url( array( 'catalog' => $cat->ID ) );
			$used = (int) get_post_meta( $cat->ID, '_af_cat_used', true );
			?>
			<article class="af-cats-card">
				<a class="af-cats-card__thumbs" href="<?php echo esc_url( $edit ); ?>">
					<?php foreach ( $sum['thumbs'] as $thumb ) : ?>
						<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
					<?php endforeach; ?>
					<?php if ( empty( $sum['thumbs'] ) ) : ?>
						<span class="af-cats-card__empty"><?php esc_html_e( 'Sin fotos', 'arriendo-facil' ); ?></span>
					<?php endif; ?>
				</a>
				<div class="af-cats-card__body">
					<h2><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( get_the_title( $cat ) ); ?></a></h2>
					<p class="af-cats-card__meta">
						<?php
						printf(
							/* translators: 1: total properties, 2: available properties */
							esc_html__( '%1$d propiedades · %2$d disponibles', 'arriendo-facil' ),
							(int) $sum['total'],
							(int) $sum['available']
						);
						?>
					</p>
					<?php if ( $used ) : ?>
						<p class="af-cats-card__meta">
							<?php
							/* translators: %s: relative time */
							printf( esc_html__( 'Usado hace %s', 'arriendo-facil' ), esc_html( human_time_diff( $used ) ) );
							?>
						</p>
					<?php endif; ?>
					<div class="af-cats-card__actions">
						<button type="button" class="button button-primary" data-af-copy="<?php echo esc_attr( $link['url'] ); ?>"><?php esc_html_e( 'Copiar enlace', 'arriendo-facil' ); ?></button>
						<a class="button" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Editar', 'arriendo-facil' ); ?></a>
						<details class="af-cats-more">
							<summary aria-label="<?php esc_attr_e( 'Más acciones', 'arriendo-facil' ); ?>">⋯</summary>
							<div class="af-cats-more__menu">
								<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver', 'arriendo-facil' ); ?></a>
								<a href="<?php echo esc_url( $link['pdf_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'PDF', 'arriendo-facil' ); ?></a>
								<?php
								$af_cat_action( $cat->ID, 'duplicate', __( 'Duplicar', 'arriendo-facil' ) );
								$af_cat_action( $cat->ID, 'archive', __( 'Archivar', 'arriendo-facil' ) );
								?>
							</div>
						</details>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
	</div>

	<?php if ( ! empty( $archived ) ) : ?>
		<details class="af-cats-archived">
			<summary>
				<?php
				/* translators: %d: archived catalogs */
				printf( esc_html__( 'Archivados (%d)', 'arriendo-facil' ), count( $archived ) );
				?>
			</summary>
			<ul>
				<?php foreach ( $archived as $cat ) : ?>
					<li>
						<span><?php echo esc_html( get_the_title( $cat ) ); ?></span>
						<?php
						$af_cat_action( $cat->ID, 'restore', __( 'Restaurar', 'arriendo-facil' ), 'button' );
						$af_cat_action( $cat->ID, 'delete', __( 'Eliminar', 'arriendo-facil' ), 'button-link-delete', true );
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
</div>
