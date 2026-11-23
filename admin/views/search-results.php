<?php
/**
 * Full results of the global search.
 *
 * @var string $term      Query typed by the user.
 * @var bool   $too_short Whether the query is too short to search.
 * @var array  $groups    Result groups from Arriendo_Facil_Global_Search::search().
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$total = 0;
foreach ( $groups as $group ) {
	$total += count( $group['items'] );
}
?>
<div class="wrap af-shell af-gsearch-page">
	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Búsqueda', 'arriendo-facil' ),
			'title'    => '' !== $term ? sprintf( /* translators: %s: query */ __( 'Resultados para “%s”', 'arriendo-facil' ), $term ) : __( 'Buscar en todo el sistema', 'arriendo-facil' ),
			'subtitle' => __( 'Inmuebles, inquilinos, contratos, mantenimiento, técnicos y catálogos.', 'arriendo-facil' ),
		)
	);
	?>

	<form class="af-gsearch-page__form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
		<input type="hidden" name="page" value="<?php echo esc_attr( Arriendo_Facil_Global_Search::PAGE_SLUG ); ?>" />
		<label class="screen-reader-text" for="af-gsearch-page-input"><?php esc_html_e( 'Buscar en todo el sistema', 'arriendo-facil' ); ?></label>
		<input type="search" id="af-gsearch-page-input" name="q" value="<?php echo esc_attr( $term ); ?>" placeholder="<?php esc_attr_e( 'Nombre, cédula, correo, dirección, técnico…', 'arriendo-facil' ); ?>" autocomplete="off" />
		<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Buscar', 'arriendo-facil' ); ?></button>
	</form>

	<?php if ( '' === $term ) : ?>
		<div class="af-empty">
			<h3><?php esc_html_e( '¿Qué estás buscando?', 'arriendo-facil' ); ?></h3>
			<p><?php esc_html_e( 'Escribe un nombre, una cédula, un correo, una dirección o un técnico y te mostramos todo lo que coincide.', 'arriendo-facil' ); ?></p>
		</div>
	<?php elseif ( $too_short ) : ?>
		<div class="af-empty">
			<h3><?php esc_html_e( 'Escribe al menos 2 letras', 'arriendo-facil' ); ?></h3>
		</div>
	<?php elseif ( 0 === $total ) : ?>
		<div class="af-empty">
			<h3><?php esc_html_e( 'No encontramos resultados', 'arriendo-facil' ); ?></h3>
			<p><?php esc_html_e( 'Prueba con otra palabra o con menos palabras. La búsqueda ignora tildes y mayúsculas.', 'arriendo-facil' ); ?></p>
		</div>
	<?php else : ?>
		<?php foreach ( $groups as $group ) : ?>
			<section class="af-section af-gsearch-group" aria-labelledby="af-gsearch-<?php echo esc_attr( $group['key'] ); ?>">
				<header class="af-section__header">
					<h2 class="af-section__title" id="af-gsearch-<?php echo esc_attr( $group['key'] ); ?>">
						<?php echo esc_html( $group['label'] ); ?>
						<span class="af-status-tabs__count"><?php echo esc_html( number_format_i18n( count( $group['items'] ) ) ); ?></span>
					</h2>
					<a class="button af-btn af-btn--ghost af-btn--sm" href="<?php echo esc_url( $group['more'] ); ?>"><?php esc_html_e( 'Abrir sección', 'arriendo-facil' ); ?></a>
				</header>
				<ul class="af-gsearch-list">
					<?php foreach ( $group['items'] as $item ) : ?>
						<li>
							<a class="af-gsearch-item" href="<?php echo esc_url( $item['url'] ); ?>">
								<span class="af-gsearch-item__main">
									<strong><?php echo esc_html( $item['title'] ); ?></strong>
									<?php if ( '' !== $item['subtitle'] ) : ?>
										<small><?php echo esc_html( $item['subtitle'] ); ?></small>
									<?php endif; ?>
								</span>
								<?php if ( '' !== $item['badge'] ) : ?>
									<span class="af-pill af-pill--neutral"><?php echo esc_html( $item['badge'] ); ?></span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
