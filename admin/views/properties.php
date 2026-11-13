<?php
/**
 * Inmuebles: card grid (catalog style).
 *
 * @package Arriendo_Facil
 *
 * @var array $view Data prepared by Arriendo_Facil_Properties_Page::render().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$posts    = $view['posts'];
$query    = $view['query'];
$counts   = $view['counts'];
$filter   = $view['filter'];
$search   = $view['search'];
$order    = $view['order'];
$paged    = (int) $view['paged'];
$notice   = $view['notice'];
$tenants  = $view['tenants'];
$owner_id = (int) $view['owner_id'];

$new_url  = admin_url( 'admin.php?page=' . Arriendo_Facil_Accommodation_Wizard::PAGE_SLUG );
$edit_url = static function ( $post_id ) use ( $new_url ) {
	return add_query_arg( 'id', (int) $post_id, $new_url );
};

$status_labels = array(
	'available'   => __( 'Disponible', 'arriendo-facil' ),
	'rented'      => __( 'Arrendado', 'arriendo-facil' ),
	'occupied'    => __( 'Arrendado', 'arriendo-facil' ),
	'maintenance' => __( 'En mantenimiento', 'arriendo-facil' ),
	'inactive'    => __( 'Inactivo', 'arriendo-facil' ),
);
$type_meta     = array(
	'apartment'  => array( __( 'Apartamento', 'arriendo-facil' ), 'building' ),
	'house'      => array( __( 'Casa', 'arriendo-facil' ), 'home' ),
	'office'     => array( __( 'Oficina', 'arriendo-facil' ), 'building-2' ),
	'room'       => array( __( 'Habitación', 'arriendo-facil' ), 'bed' ),
	'commercial' => array( __( 'Comercial', 'arriendo-facil' ), 'store' ),
);

$tabs = array(
	'all'         => __( 'Todos', 'arriendo-facil' ),
	'available'   => __( 'Disponibles', 'arriendo-facil' ),
	'rented'      => __( 'Arrendados', 'arriendo-facil' ),
	'maintenance' => __( 'Mantenimiento', 'arriendo-facil' ),
	'draft'       => __( 'Borradores', 'arriendo-facil' ),
	'locked'      => __( 'Bloqueados', 'arriendo-facil' ),
	'trash'       => __( 'Papelera', 'arriendo-facil' ),
);

$plan_managed    = Arriendo_Facil_Plans::applies_to();
$has_catalog     = ! $plan_managed || Arriendo_Facil_Plans::has_feature( 'catalog' );
$property_gate   = $plan_managed ? Arriendo_Facil_Plans::limit_gate( 'properties' ) : null;
$property_limit  = $plan_managed ? Arriendo_Facil_Plans::limit( 'properties' ) : null;
$gate_attr       = $property_gate ? ' data-af-plan-gate="' . esc_attr( wp_json_encode( $property_gate ) ) . '"' : '';

$groups = ( $owner_id && class_exists( 'Arriendo_Facil_Catalog_Groups' ) ) ? Arriendo_Facil_Catalog_Groups::get_for_owner( $owner_id ) : array();

$is_filtered = 'all' !== $filter || '' !== $search;
$show_new    = ! $is_filtered && 1 === $paged;
?>
<div class="wrap af-shell af-props">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Propiedades', 'arriendo-facil' ),
			'title'    => __( 'Inmuebles', 'arriendo-facil' ),
			'subtitle' => __( 'Tus propiedades en un vistazo. Abre una para editarla.', 'arriendo-facil' ) . ( null !== $property_limit ? ' ' . sprintf( /* translators: 1: used, 2: limit */ __( 'Tu plan incluye %2$d inmuebles; usas %1$d.', 'arriendo-facil' ), Arriendo_Facil_Plans::usage( 'properties' ), $property_limit ) : '' ),
		)
	);
	?>

	<?php if ( $counts['all'] > 0 || $counts['trash'] > 0 ) : ?>
		<div class="af-ptoolbar">
			<nav class="af-ptabs" aria-label="<?php esc_attr_e( 'Filtrar inmuebles', 'arriendo-facil' ); ?>">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<?php
					$tab_url = Arriendo_Facil_Properties_Page::url(
						array_filter(
							array(
								'estado' => 'all' === $tab_key ? '' : $tab_key,
								's'      => $search,
								'orden'  => 'recent' === $order ? '' : $order,
							)
						)
					);
					?>
					<a class="af-ptabs__tab<?php echo $tab_key === $filter ? ' is-active' : ''; ?>"<?php echo ( in_array( $tab_key, array( 'trash', 'locked' ), true ) && 0 === $counts[ $tab_key ] && $tab_key !== $filter ) ? ' hidden' : ''; ?> data-af-tab="<?php echo esc_attr( $tab_key ); ?>" href="<?php echo esc_url( $tab_url ); ?>"<?php echo $tab_key === $filter ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $tab_label ); ?>
						<span class="af-ptabs__count" data-af-count="<?php echo esc_attr( $tab_key ); ?>"><?php echo esc_html( number_format_i18n( $counts[ $tab_key ] ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<form class="af-psearch" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
				<input type="hidden" name="page" value="<?php echo esc_attr( Arriendo_Facil_Properties_Page::PAGE_SLUG ); ?>" />
				<?php if ( 'all' !== $filter ) : ?>
					<input type="hidden" name="estado" value="<?php echo esc_attr( $filter ); ?>" />
				<?php endif; ?>
				<label class="screen-reader-text" for="af-props-search"><?php esc_html_e( 'Buscar inmuebles', 'arriendo-facil' ); ?></label>
				<span class="af-psearch__field">
					<?php echo af_lucide( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
					<input type="search" id="af-props-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Buscar inmueble…', 'arriendo-facil' ); ?>" />
				</span>
				<label class="screen-reader-text" for="af-props-order"><?php esc_html_e( 'Ordenar por', 'arriendo-facil' ); ?></label>
				<select id="af-props-order" name="orden" data-af-autosubmit>
					<option value="recent" <?php selected( $order, 'recent' ); ?>><?php esc_html_e( 'Más recientes', 'arriendo-facil' ); ?></option>
					<option value="name" <?php selected( $order, 'name' ); ?>><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?></option>
					<option value="rent_desc" <?php selected( $order, 'rent_desc' ); ?>><?php esc_html_e( 'Renta: mayor a menor', 'arriendo-facil' ); ?></option>
					<option value="rent_asc" <?php selected( $order, 'rent_asc' ); ?>><?php esc_html_e( 'Renta: menor a mayor', 'arriendo-facil' ); ?></option>
				</select>
			</form>
		</div>
	<?php endif; ?>

	<?php if ( 0 === $counts['all'] && 0 === $counts['trash'] ) : ?>

		<section class="af-pempty-state">
			<span class="af-pempty-state__icon" aria-hidden="true"><?php echo af_lucide( 'building-2', 30 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<h2><?php esc_html_e( 'Registra tu primer inmueble', 'arriendo-facil' ); ?></h2>
			<p><?php esc_html_e( 'Son seis pasos cortos. Después podrás vincular un inquilino y generar sus cobros.', 'arriendo-facil' ); ?></p>
			<a class="button af-btn af-btn--primary" href="<?php echo esc_url( $new_url ); ?>"<?php echo $gate_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>><?php esc_html_e( 'Empezar', 'arriendo-facil' ); ?></a>
		</section>

	<?php else : ?>

		<div class="af-pgrid" id="af-props-grid" data-af-stagger>
			<?php if ( $show_new ) : ?>
				<a class="af-pnew<?php echo $property_gate ? ' is-plan-locked' : ''; ?>" href="<?php echo esc_url( $new_url ); ?>"<?php echo $gate_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
					<span class="af-pnew__plus" aria-hidden="true">+</span>
					<strong><?php esc_html_e( 'Nuevo inmueble', 'arriendo-facil' ); ?></strong>
					<small><?php esc_html_e( 'Tipo, ubicación, fotos y canon', 'arriendo-facil' ); ?></small>
				</a>
			<?php endif; ?>

			<?php foreach ( $posts as $post_obj ) : ?>
				<?php
				$pid         = (int) $post_obj->ID;
				$is_trashed  = 'trash' === $post_obj->post_status;
				$is_draft    = in_array( $post_obj->post_status, array( 'draft', 'pending' ), true );
				$status      = (string) get_post_meta( $pid, '_af_status', true );
				$status      = isset( $status_labels[ $status ] ) ? $status : 'available';
				$type        = (string) get_post_meta( $pid, '_af_property_type', true );
				$type_info   = isset( $type_meta[ $type ] ) ? $type_meta[ $type ] : null;
				$address     = trim( trim( (string) get_post_meta( $pid, '_af_address', true ) ) . ( '' !== trim( (string) get_post_meta( $pid, '_af_city', true ) ) ? ', ' . trim( (string) get_post_meta( $pid, '_af_city', true ) ) : '' ) );
				$bedrooms    = (int) get_post_meta( $pid, '_af_bedrooms', true );
				$bathrooms   = (int) get_post_meta( $pid, '_af_bathrooms', true );
				$area        = (float) get_post_meta( $pid, '_af_square_meters', true );
				$rent        = (float) get_post_meta( $pid, '_af_monthly_rent', true );
				$tenant      = isset( $tenants[ $pid ] ) ? $tenants[ $pid ] : '';
				$thumb       = get_the_post_thumbnail_url( $pid, 'medium_large' );
				$title       = get_the_title( $pid );
				
				$is_locked   = ! $is_trashed && Arriendo_Facil_Tenancy::is_plan_locked( $pid );
				$can_delete  = current_user_can( Arriendo_Facil_Tenancy::CAP ) && Arriendo_Facil_Tenancy::owns_accommodation( $pid );
				$link        = ( $is_trashed || $is_locked ) ? '' : $edit_url( $pid );
				$group_id    = $owner_id && class_exists( 'Arriendo_Facil_Catalog_Groups' ) ? (int) Arriendo_Facil_Catalog_Groups::get_group_id_for_property( $pid ) : 0;

				if ( ! $thumb ) {
					$gallery = get_post_meta( $pid, '_af_gallery', true );
					if ( is_array( $gallery ) && ! empty( $gallery ) ) {
						$thumb = wp_get_attachment_image_url( (int) reset( $gallery ), 'medium_large' );
					}
				}
				?>
				<article class="af-pc<?php echo $is_trashed ? ' is-trashed' : ''; ?><?php echo $is_locked ? ' is-locked' : ''; ?>" data-property="<?php echo esc_attr( $pid ); ?>">
					<?php if ( $link ) : ?><a class="af-pc__media" href="<?php echo esc_url( $link ); ?>" tabindex="-1" aria-hidden="true"><?php else : ?><div class="af-pc__media"><?php endif; ?>
						<?php if ( $thumb ) : ?>
							<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy" />
						<?php else : ?>
							<span class="af-pc__placeholder"><?php echo af_lucide( 'building', 34 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
						<?php endif; ?>
						<span class="af-pc__badge"><?php echo $is_locked ? '<span class="af-pc__lockpill">' . af_lucide( 'lock', 12 ) . esc_html__( 'Bloqueado', 'arriendo-facil' ) . '</span>' : ( $is_draft ? af_pill( 'draft' ) : af_pill( $status, $status_labels[ $status ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- af_pill() escapes internally. ?></span>
					<?php echo $link ? '</a>' : '</div>'; ?>

					<div class="af-pc__body">
						<?php if ( $type_info ) : ?>
							<span class="af-pc__type"><?php echo af_lucide( $type_info[1], 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?><?php echo esc_html( $type_info[0] ); ?></span>
						<?php endif; ?>
						<h3 class="af-pc__title">
							<?php if ( $link ) : ?><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $title ? $title : __( '(sin nombre)', 'arriendo-facil' ) ); ?></a><?php else : ?><?php echo esc_html( $title ); ?><?php endif; ?>
						</h3>
						<?php if ( $address ) : ?>
							<p class="af-pc__line"><?php echo af_lucide( 'map-pin', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?><span><?php echo esc_html( $address ); ?></span></p>
						<?php endif; ?>
						<?php if ( $bedrooms || $bathrooms || $area ) : ?>
							<p class="af-pc__specs">
								<?php if ( $bedrooms ) : ?><span><?php echo esc_html( sprintf( /* translators: %d: bedrooms */ _n( '%d dorm.', '%d dorm.', $bedrooms, 'arriendo-facil' ), $bedrooms ) ); ?></span><?php endif; ?>
								<?php if ( $bathrooms ) : ?><span><?php echo esc_html( sprintf( /* translators: %d: bathrooms */ _n( '%d baño', '%d baños', $bathrooms, 'arriendo-facil' ), $bathrooms ) ); ?></span><?php endif; ?>
								<?php if ( $area ) : ?><span><?php echo esc_html( number_format_i18n( $area, 0 ) ); ?> m²</span><?php endif; ?>
							</p>
						<?php endif; ?>
						<?php if ( $is_trashed ) : ?>
							<p class="af-pc__line af-pc__purge"><span><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Se eliminará definitivamente el %s', 'arriendo-facil' ), date_i18n( get_option( 'date_format' ), Arriendo_Facil_Properties_Page::purge_timestamp( $pid ) ) ) ); ?></span></p>
						<?php endif; ?>
						<?php if ( $tenant ) : ?>
							<p class="af-pc__line af-pc__tenant"><?php echo af_lucide( 'user', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?><span><?php echo esc_html( $tenant ); ?></span></p>
						<?php endif; ?>
					</div>

					<footer class="af-pc__foot">
						<span class="af-pc__price">
							<?php if ( $rent > 0 ) : ?>
								$<?php echo esc_html( number_format_i18n( $rent, 2 ) ); ?> <small><?php esc_html_e( '/ mes', 'arriendo-facil' ); ?></small>
							<?php else : ?>
								<small><?php esc_html_e( 'Sin canon', 'arriendo-facil' ); ?></small>
							<?php endif; ?>
						</span>

						<?php if ( $link ) : ?>
							<a class="button af-btn af-btn--ghost af-btn--sm" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Editar', 'arriendo-facil' ); ?></a>
						<?php elseif ( $is_locked ) : ?>
							<a class="button af-btn af-btn--ghost af-btn--sm" href="<?php echo esc_url( Arriendo_Facil_Plans::url() . '#af-plan-select' ); ?>"><?php esc_html_e( 'Activar', 'arriendo-facil' ); ?></a>
						<?php elseif ( $can_delete ) : ?>
							<button type="button" class="button af-btn af-btn--ghost af-btn--sm" data-af-prop-action="restore" data-id="<?php echo esc_attr( $pid ); ?>"><?php esc_html_e( 'Restaurar', 'arriendo-facil' ); ?></button>
							<button type="button" class="button af-btn af-btn--ghost af-btn--sm af-pc__danger" data-af-prop-action="delete" data-id="<?php echo esc_attr( $pid ); ?>" data-name="<?php echo esc_attr( $title ); ?>"><?php esc_html_e( 'Eliminar', 'arriendo-facil' ); ?></button>
						<?php endif; ?>

						<?php if ( ! $is_trashed && ( ( $owner_id && $has_catalog && ! $is_locked ) || $can_delete ) ) : ?>
							<details class="af-pc__more">
								<summary aria-label="<?php esc_attr_e( 'Más acciones', 'arriendo-facil' ); ?>">&#8943;</summary>
								<div class="af-pc__menu">
									<?php if ( $owner_id && $has_catalog && ! $is_locked ) : ?>
										<label for="af-cs-group-<?php echo esc_attr( $pid ); ?>"><?php esc_html_e( 'Edificio', 'arriendo-facil' ); ?></label>
										<select id="af-cs-group-<?php echo esc_attr( $pid ); ?>" data-af-cs-assign data-property-id="<?php echo esc_attr( $pid ); ?>">
											<option value="0"><?php esc_html_e( 'Sin edificio', 'arriendo-facil' ); ?></option>
											<?php foreach ( $groups as $group ) : ?>
												<option value="<?php echo esc_attr( (int) $group->id ); ?>" <?php selected( $group_id, (int) $group->id ); ?>><?php echo esc_html( $group->name ); ?></option>
											<?php endforeach; ?>
										</select>
									<?php endif; ?>
									<?php if ( $can_delete ) : ?>
										<button type="button" class="af-pc__danger" data-af-prop-action="trash" data-id="<?php echo esc_attr( $pid ); ?>" data-name="<?php echo esc_attr( $title ); ?>"><?php esc_html_e( 'Eliminar inmueble', 'arriendo-facil' ); ?></button>
									<?php endif; ?>
								</div>
							</details>
						<?php endif; ?>
					</footer>
				</article>
			<?php endforeach; ?>
		</div>

		<?php if ( empty( $posts ) ) : ?>
			<section class="af-pempty-state af-pempty-state--compact">
				<h2><?php esc_html_e( 'No encontramos inmuebles', 'arriendo-facil' ); ?></h2>
				<p><?php esc_html_e( 'Prueba con otro filtro o con otra búsqueda.', 'arriendo-facil' ); ?></p>
				<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( Arriendo_Facil_Properties_Page::url() ); ?>"><?php esc_html_e( 'Ver todos', 'arriendo-facil' ); ?></a>
			</section>
		<?php endif; ?>

		<?php
		$pagination = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => max( 1, (int) $query->max_num_pages ),
				'prev_text' => '&larr;',
				'next_text' => '&rarr;',
				'type'      => 'list',
			)
		);
		if ( $pagination ) :
			?>
			<nav class="af-ppager" aria-label="<?php esc_attr_e( 'Paginación', 'arriendo-facil' ); ?>"><?php echo $pagination; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes paginate_links(). ?></nav>
		<?php endif; ?>

		<?php if ( $owner_id && $has_catalog && class_exists( 'Arriendo_Facil_Catalog_Share' ) ) : ?>
			<?php $group_counts = Arriendo_Facil_Catalog_Share::group_counts_for_owner( $owner_id ); ?>
			<details class="af-intro-more"<?php echo empty( $group_counts ) ? '' : ' open'; ?>>
				<summary><?php esc_html_e( 'Agrupar por edificio o conjunto (opcional)', 'arriendo-facil' ); ?></summary>
				<?php af_catalog_groups_panel( $owner_id, $group_counts ); ?>
			</details>
		<?php endif; ?>

	<?php endif; ?>

	<div class="af-pmodal" id="af-pmodal" hidden>
		<div class="af-pmodal__backdrop" data-af-modal-close></div>
		<div class="af-pmodal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-pmodal-title" aria-describedby="af-pmodal-text" tabindex="-1">
			<span class="af-pmodal__icon" aria-hidden="true"><?php echo af_lucide( 'trash-2', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<h2 id="af-pmodal-title"></h2>
			<p class="af-pmodal__name"></p>
			<p id="af-pmodal-text"></p>
			<div class="af-pmodal__actions">
				<button type="button" class="button af-btn af-btn--ghost" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
				<button type="button" class="button af-btn af-pmodal__confirm" data-af-modal-confirm></button>
			</div>
		</div>
	</div>
</div>
