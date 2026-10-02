<?php
/**
 * Visual catalog of accommodations.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$is_owner   = Arriendo_Facil_Accommodation::user_is_owner();
$can_all    = Arriendo_Facil_Tenancy::can_manage_all();
$scope_ids  = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
$filter_admins = $can_all ? Arriendo_Facil_Tenancy::get_property_admins() : array();

if ( $can_all && isset( $_GET['af_admin_id'] ) && absint( $_GET['af_admin_id'] ) ) {
	$admin_scope_ids = array_map( 'absint', Arriendo_Facil_Accommodation::get_owner_accommodation_ids( absint( $_GET['af_admin_id'] ) ) );
	$filter_admin_id = absint( $_GET['af_admin_id'] );
} else {
	$admin_scope_ids = null;
	$filter_admin_id = 0;
}

if ( null !== $admin_scope_ids ) {
	$scope_ids = is_array( $scope_ids ) ? array_intersect( $scope_ids, $admin_scope_ids ) : $admin_scope_ids;
}

$statuses = array(
	'available'   => __( 'Disponible', 'arriendo-facil' ),
	'rented'      => __( 'Arrendado', 'arriendo-facil' ),
	'maintenance' => __( 'En mantenimiento', 'arriendo-facil' ),
	'inactive'    => __( 'Inactivo', 'arriendo-facil' ),
);

$property_types = array(
	'apartment'  => array( 'label' => __( 'Apartamento', 'arriendo-facil' ), 'icon' => 'building' ),
	'house'      => array( 'label' => __( 'Casa', 'arriendo-facil' ), 'icon' => 'home' ),
	'office'     => array( 'label' => __( 'Oficina', 'arriendo-facil' ), 'icon' => 'building-2' ),
	'room'       => array( 'label' => __( 'Habitaci&oacute;n', 'arriendo-facil' ), 'icon' => 'bed' ),
	'commercial' => array( 'label' => __( 'Comercial', 'arriendo-facil' ), 'icon' => 'store' ),
);

$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$f_status = isset( $_GET['af_status'] ) ? sanitize_key( wp_unslash( $_GET['af_status'] ) ) : '';
if ( '' !== $f_status && ! isset( $statuses[ $f_status ] ) ) {
	$f_status = '';
}
$f_type = isset( $_GET['af_type'] ) ? sanitize_key( wp_unslash( $_GET['af_type'] ) ) : '';
if ( '' !== $f_type && ! isset( $property_types[ $f_type ] ) ) {
	$f_type = '';
}

$args = array(
	'post_type'      => 'accommodation',
	'post_status'    => array( 'publish', 'draft', 'private' ),
	'posts_per_page' => 500,
	'orderby'        => 'title',
	'order'          => 'ASC',
	'fields'         => 'ids',
);

if ( null !== $scope_ids ) {
	$args['post__in'] = ! empty( $scope_ids ) ? array_map( 'absint', $scope_ids ) : array( 0 );
}

$meta_queries = array();
if ( '' !== $search ) {
	$args['s'] = $search;
}
if ( '' !== $f_status ) {
	$meta_queries[] = array( 'key' => '_af_status', 'value' => $f_status );
}
if ( '' !== $f_type ) {
	$meta_queries[] = array( 'key' => '_af_property_type', 'value' => $f_type );
}
if ( count( $meta_queries ) > 1 ) {
	$meta_queries['relation'] = 'AND';
}
if ( ! empty( $meta_queries ) ) {
	$args['meta_query'] = $meta_queries; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
}

$accommodation_ids = get_posts( $args );
$total_count       = count( $accommodation_ids );

/*
 * Catalog grouping (edificios / conjuntos). The owner whose catalog is being
 * edited drives both the group list and the per-property dropdown; a super
 * admin viewing another administrator's catalog sees that owner's groups.
 */
$catalog_owner_id = $filter_admin_id ? $filter_admin_id : ( $is_owner ? get_current_user_id() : 0 );
$catalog_groups   = ( $catalog_owner_id && class_exists( 'Arriendo_Facil_Catalog_Groups' ) )
	? Arriendo_Facil_Catalog_Groups::get_for_owner( $catalog_owner_id )
	: array();
$af_group_names = array();
foreach ( $catalog_groups as $af_group ) {
	$af_group_names[ (int) $af_group->id ] = $af_group->name;
}

// Cache-friendly one-shot fetch of all card meta.
$fetched = array();
if ( ! empty( $accommodation_ids ) ) {
	$ids_sql = Arriendo_Facil_Tenancy::ids_in_clause( $accommodation_ids );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results(
		"SELECT p.ID,
		 MAX(CASE WHEN pm.meta_key = '_af_monthly_rent'     THEN pm.meta_value END) AS monthly_rent,
		 MAX(CASE WHEN pm.meta_key = '_af_status'           THEN pm.meta_value END) AS status,
		 MAX(CASE WHEN pm.meta_key = '_af_property_type'    THEN pm.meta_value END) AS property_type,
		 MAX(CASE WHEN pm.meta_key = '_af_address'          THEN pm.meta_value END) AS address,
		 MAX(CASE WHEN pm.meta_key = '_af_city'             THEN pm.meta_value END) AS city,
		 MAX(CASE WHEN pm.meta_key = '_af_bedrooms'         THEN pm.meta_value END) AS bedrooms,
		 MAX(CASE WHEN pm.meta_key = '_af_bathrooms'        THEN pm.meta_value END) AS bathrooms,
		 MAX(CASE WHEN pm.meta_key = '_af_owner_id'         THEN pm.meta_value END) AS owner_id,
		 MAX(CASE WHEN pm.meta_key = '_af_is_occupied'      THEN pm.meta_value END) AS occupied,
		 MAX(CASE WHEN pm.meta_key = '_thumbnail_id'        THEN pm.meta_value END) AS thumbnail_id
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
		  AND pm.meta_key IN ('_af_monthly_rent','_af_status','_af_property_type','_af_address','_af_city','_af_bedrooms','_af_bathrooms','_af_owner_id','_af_is_occupied','_thumbnail_id')
		 WHERE p.ID IN ($ids_sql)
		 GROUP BY p.ID
		 ORDER BY p.post_title ASC"
	);
	// phpcs:enable
	foreach ( (array) $rows as $row ) {
		$fetched[ (int) $row->ID ] = $row;
	}
}

// Build title + admin display mapping.
$owner_names    = array();
$catalog_owners = array();
foreach ( $accommodation_ids as $post_id ) {
	if ( ! empty( $fetched[ $post_id ]->owner_id ) ) {
		$catalog_owners[] = (int) $fetched[ $post_id ]->owner_id;
	}
}
if ( ! empty( $catalog_owners ) ) {
	cache_users( array_unique( $catalog_owners ) );
}
foreach ( $accommodation_ids as $post_id ) {
	$owner_id = isset( $fetched[ $post_id ]->owner_id ) ? (int) $fetched[ $post_id ]->owner_id : 0;
	if ( $owner_id && ! isset( $owner_names[ $owner_id ] ) ) {
		$user = get_userdata( $owner_id );
		if ( $user ) {
			$owner_names[ $owner_id ] = get_user_meta( $owner_id, 'af_company_name', true ) ? get_user_meta( $owner_id, 'af_company_name', true ) : $user->display_name;
		}
	}
}
?>
<div class="wrap af-shell af-catalog-page">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Propiedades', 'arriendo-facil' ),
			'title'    => __( 'Catálogo de propiedades', 'arriendo-facil' ),
			'subtitle' => sprintf(
				/* translators: %d: number of properties */
				__( 'Así verán tus inmuebles quienes abran tu enlace (%d en total).', 'arriendo-facil' ),
				$total_count
			),
		)
	);
	af_catalog_flow( 3 );
	?>

	<?php
$af_share_user = get_current_user_id();
if ( class_exists( 'Arriendo_Facil_Catalog_Share' ) && Arriendo_Facil_Catalog_Share::token_for_user( $af_share_user ) && '' === Arriendo_Facil_Catalog_Share::slug_for_user( $af_share_user ) ) {
	// Older links only had the long token: give them a short name (the token URL keeps working).
	Arriendo_Facil_Catalog_Share::generate_token( $af_share_user );
}
$af_link     = class_exists( 'Arriendo_Facil_Catalog_Share' ) ? Arriendo_Facil_Catalog_Share::link_payload( $af_share_user ) : null;
$share_url   = $af_link ? $af_link['url'] : '';
$share_pdf   = $af_link ? $af_link['pdf_url'] : '';
$af_slug     = $af_link ? $af_link['slug'] : '';
if ( ! $af_slug && class_exists( 'Arriendo_Facil_Catalog_Share' ) ) {
	$af_suggested = Arriendo_Facil_Catalog_Share::suggest_slugs( '', $af_share_user, 1 );
	$af_slug      = $af_suggested ? $af_suggested[0] : '';
}
$af_home_parts = wp_parse_url( home_url( '/' ) );
$af_prefix     = ( isset( $af_home_parts['host'] ) ? $af_home_parts['host'] : '' ) . ( isset( $af_home_parts['path'] ) ? rtrim( $af_home_parts['path'], '/' ) : '' ) . '/catalogo/';
?>
<section class="af-section af-share-panel">
	<div class="af-share-panel__head">
		<span class="af-share-panel__icon" aria-hidden="true"><?php echo af_lucide( 'link-2', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		<div class="af-share-panel__titles">
			<h2 class="af-section__title"><?php esc_html_e( 'Tu enlace público', 'arriendo-facil' ); ?></h2>
			<p class="af-share-panel__hint"><?php esc_html_e( 'Compártelo por WhatsApp o redes. Puedes cambiarle el nombre o desactivarlo cuando quieras.', 'arriendo-facil' ); ?></p>
		</div>
		<span class="af-share-panel__state" id="af-share-state" data-on="<?php esc_attr_e( 'Activo', 'arriendo-facil' ); ?>" data-off="<?php esc_attr_e( 'Sin crear', 'arriendo-facil' ); ?>">
			<?php echo $share_url ? esc_html__( 'Activo', 'arriendo-facil' ) : esc_html__( 'Sin crear', 'arriendo-facil' ); ?>
		</span>
	</div>

	<div class="af-share-card<?php echo $share_url ? ' is-active' : ''; ?>" id="af-share-card" data-active="<?php echo $share_url ? '1' : '0'; ?>" data-saved-slug="<?php echo esc_attr( $af_link ? $af_link['slug'] : '' ); ?>">
		<div class="af-share-card__main">
			<div class="af-slug">
				<span class="af-slug__icon" aria-hidden="true"><?php echo af_lucide( 'globe', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<label class="af-slug__prefix" for="af-share-slug"><?php echo esc_html( $af_prefix ); ?></label>
				<input type="text" id="af-share-slug" class="af-slug__input" value="<?php echo esc_attr( $af_slug ); ?>"
					maxlength="<?php echo esc_attr( Arriendo_Facil_Catalog_Share::SLUG_MAX ); ?>" autocomplete="off" spellcheck="false"
					aria-describedby="af-slug-msg" placeholder="<?php esc_attr_e( 'nombre-de-tu-catalogo', 'arriendo-facil' ); ?>" />
				<span class="af-slug__edit" aria-hidden="true" title="<?php esc_attr_e( 'Puedes editar este nombre', 'arriendo-facil' ); ?>"><?php echo af_lucide( 'pencil', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			</div>
			<input type="hidden" id="af-share-url" value="<?php echo esc_attr( $share_url ); ?>" />

			<div class="af-share-card__primary" id="af-share-primary" <?php echo $share_url ? '' : 'hidden'; ?>>
				<button type="button" class="button af-btn af-btn--primary" id="af-share-slug-save" hidden><?php echo af_lucide( 'save', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Guardar nombre', 'arriendo-facil' ); ?></button>
				<button type="button" class="button af-btn af-btn--primary" id="af-share-copy"><?php echo af_lucide( 'link-2', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <span data-label><?php esc_html_e( 'Copiar enlace', 'arriendo-facil' ); ?></span></button>
			</div>
			<div class="af-share-card__primary" id="af-share-generate-wrap" <?php echo $share_url ? 'hidden' : ''; ?>>
				<button type="button" class="button af-btn af-btn--primary" id="af-share-generate"><?php echo af_lucide( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Crear enlace', 'arriendo-facil' ); ?></button>
			</div>
		</div>

		<p class="af-slug__msg" id="af-slug-msg" aria-live="polite"></p>
		<div class="af-slug__suggest" id="af-slug-suggest" hidden></div>
		<p class="af-share-status" id="af-share-status" aria-live="polite"></p>

		<div class="af-share-card__footer" id="af-share-actions" <?php echo $share_url ? '' : 'hidden'; ?>>
			<button type="button" class="af-share-link" id="af-share-preview"><?php echo af_lucide( 'globe', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Ver catálogo', 'arriendo-facil' ); ?></button>
			<a class="af-share-link" id="af-share-pdf" href="<?php echo esc_url( $share_pdf ); ?>" target="_blank" rel="noopener"><?php echo af_lucide( 'file-text', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Descargar PDF', 'arriendo-facil' ); ?></a>
			<button type="button" class="af-share-link af-share-link--danger" id="af-share-revoke"><?php esc_html_e( 'Desactivar enlace', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</section>

<section class="af-section af-catalog-filters">
	<header class="af-section__header">
		<div>
			<h2 class="af-section__title"><?php esc_html_e( 'Filtrar propiedades', 'arriendo-facil' ); ?></h2>
		</div>
	</header>
	<form method="get" class="af-filter-bar">
			<input type="hidden" name="page" value="af-catalog" />
			<div class="af-form-field">
				<label class="af-form-field__label" for="af-cat-search"><?php esc_html_e( 'Buscar', 'arriendo-facil' ); ?></label>
				<div class="af-input-with-icon">
					<span class="af-input-with-icon__svg" aria-hidden="true"><?php echo af_lucide( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<input type="search" id="af-cat-search" name="s" value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( 'Título o dirección…', 'arriendo-facil' ); ?>" />
				</div>
			</div>
			<div class="af-form-field">
				<label class="af-form-field__label" for="af-cat-status"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></label>
				<select id="af-cat-status" name="af_status">
					<option value=""><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
					<?php foreach ( $statuses as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $f_status, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="af-form-field">
				<label class="af-form-field__label" for="af-cat-type"><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></label>
				<select id="af-cat-type" name="af_type">
					<option value=""><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
					<?php foreach ( $property_types as $value => $data ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $f_type, $value ); ?>><?php echo esc_html( $data['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php if ( $can_all && ! empty( $filter_admins ) ) : ?>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-cat-admin"><?php esc_html_e( 'Gestor', 'arriendo-facil' ); ?></label>
					<select id="af-cat-admin" name="af_admin_id">
						<option value=""><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
						<?php foreach ( $filter_admins as $pa ) : ?>
							<option value="<?php echo esc_attr( (int) $pa->ID ); ?>" <?php selected( $filter_admin_id, (int) $pa->ID ); ?>>
								<?php echo esc_html( $pa->display_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>
			<div class="af-filter-bar__actions">
				<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Filtrar', 'arriendo-facil' ); ?></button>
				<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-catalog' ) ); ?>"><?php esc_html_e( 'Limpiar', 'arriendo-facil' ); ?></a>
			</div>
		</form>
	</section>

	<?php if ( empty( $accommodation_ids ) ) : ?>
		<div class="af-empty">
			<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'building', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<h3 class="af-empty__title"><?php esc_html_e( 'No se encontraron propiedades', 'arriendo-facil' ); ?></h3>
			<p class="af-empty__text"><?php esc_html_e( 'Revisa o limpia los filtros para ver más resultados, o registra una propiedad nueva con el botón "Nueva propiedad".', 'arriendo-facil' ); ?></p>
			<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-catalog' ) ); ?>"><?php esc_html_e( 'Limpiar filtros', 'arriendo-facil' ); ?></a>
		</div>
	<?php else : ?>
		<div class="af-property-grid">
			<?php foreach ( $accommodation_ids as $prop_id ) : ?>
				<?php
				$prop   = $fetched[ $prop_id ];
				$thumb  = $prop->thumbnail_id ? wp_get_attachment_image_url( (int) $prop->thumbnail_id, 'medium_large' ) : get_the_post_thumbnail_url( $prop_id, 'medium_large' );
				$status = $prop->status ? (string) $prop->status : 'available';
				$type   = $prop->property_type ? (string) $prop->property_type : '';
				$rent   = $prop->monthly_rent ? (float) $prop->monthly_rent : 0;
				$owner  = isset( $owner_names[ (int) $prop->owner_id ] ) ? $owner_names[ (int) $prop->owner_id ] : '';
				$occupied_switch = '';
				if ( class_exists( 'Arriendo_Facil_Accommodation_Occupied_Admin' ) ) {
					$occupied_switch = Arriendo_Facil_Accommodation_Occupied_Admin::render_switch( (int) $prop_id );
				}

				$status_lbls = array(
					'available'   => __( 'Disponible', 'arriendo-facil' ),
					'rented'      => __( 'Arrendado', 'arriendo-facil' ),
					'maintenance' => __( 'En mantenimiento', 'arriendo-facil' ),
					'inactive'    => __( 'Inactivo', 'arriendo-facil' ),
				);
				$type_icon   = isset( $property_types[ $type ]['icon'] ) ? $property_types[ $type ]['icon'] : 'building';
				$type_label  = isset( $property_types[ $type ]['label'] ) ? $property_types[ $type ]['label'] : ucfirst( $type );

				$group_id     = class_exists( 'Arriendo_Facil_Catalog_Groups' )
					? Arriendo_Facil_Catalog_Groups::get_group_id_for_property( $prop_id )
					: 0;
				$in_catalog   = class_exists( 'Arriendo_Facil_Catalog_Groups' )
					? Arriendo_Facil_Catalog_Groups::is_in_public_catalog( $prop_id )
					: true;
				?>
				<div class="af-property-card" data-af-cs-prop="<?php echo esc_attr( (int) $prop_id ); ?>">
					<a class="af-property-card__link" href="<?php echo esc_url( get_edit_post_link( $prop_id ) ); ?>">
					<div class="af-property-card__media">
						<?php if ( $thumb ) : ?>
							<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( get_the_title( $prop_id ) ); ?>" loading="lazy" />
						<?php else : ?>
							<div class="af-property-card__placeholder" aria-hidden="true">
								<?php echo af_lucide( 'building', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
							</div>
						<?php endif; ?>
						<div class="af-property-card__badges">
							<?php echo af_pill( $status, $status_lbls[ $status ] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( ! $in_catalog ) : ?>
								<span class="af-pill af-pill--neutral"><?php esc_html_e( 'Fuera del catálogo', 'arriendo-facil' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
					<div class="af-property-card__body">
						<div class="af-catalog-card__type">
							<span class="af-catalog-card__type-icon" aria-hidden="true"><?php echo af_lucide( $type_icon, 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							<?php echo esc_html( $type_label ); ?>
						</div>
						<h3 class="af-property-card__title"><?php echo esc_html( get_the_title( $prop_id ) ); ?></h3>
						<?php if ( $prop->address || $prop->city ) : ?>
							<p class="af-catalog-card__address">
								<span class="af-catalog-card__address-icon" aria-hidden="true"><?php echo af_lucide( 'map-pin', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
								<span class="af-catalog-card__address-text"><?php echo esc_html( trim( trim( (string) $prop->address ) . ( $prop->city ? ', ' . $prop->city : '' ) ) ); ?></span>
							</p>
						<?php endif; ?>
						<p class="af-catalog-card__specs">
							<?php if ( (int) $prop->bedrooms ) : ?>
								<span class="af-catalog-card__spec">
									<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'bed', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
									<?php echo esc_html( sprintf( /* translators: %d: bedrooms */ _n( '%d dorm.', '%d dorm.', (int) $prop->bedrooms, 'arriendo-facil' ), (int) $prop->bedrooms ) ); ?>
								</span>
							<?php endif; ?>
							<?php if ( (int) $prop->bathrooms ) : ?>
								<span class="af-catalog-card__spec">
									<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'bath', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
									<?php echo esc_html( sprintf( /* translators: %d: bathrooms */ _n( '%d baño', '%d baños', (int) $prop->bathrooms, 'arriendo-facil' ), (int) $prop->bathrooms ) ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $owner ) : ?>
								<span class="af-catalog-card__spec af-catalog-card__owner">
									<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'user', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
									<?php echo esc_html( $owner ); ?>
								</span>
							<?php endif; ?>
						</p>
<div class="af-property-card__footer">
						<?php if ( $occupied_switch ) : // phpcs:ignore Squiz.PHP.CommentedOutCode ?>
							<span class="af-catalog-card__occ"><?php echo $occupied_switch; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup from render_switch(). ?></span>
						<?php endif; ?>
						<span class="af-property-card__price">
							<?php if ( $rent > 0 ) : ?>
								$<?php echo esc_html( number_format_i18n( $rent, 2 ) ); ?>
								<small><?php esc_html_e( '/ mes', 'arriendo-facil' ); ?></small>
							<?php else : ?>
								—
							<?php endif; ?>
						</span>
					<span class="af-catalog-card__hint">
						<?php esc_html_e( 'Gestionar', 'arriendo-facil' ); ?>
						<span class="af-catalog-card__hint-arrow" aria-hidden="true">→</span>
					</span>
				</div>
					</div>
					</a>

					<div class="af-cs-prop-controls">
						<span class="af-cs-prop-controls__group" title="<?php esc_attr_e( 'Edificio', 'arriendo-facil' ); ?>">
							<?php echo af_lucide( 'building', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
							<?php echo esc_html( isset( $af_group_names[ $group_id ] ) ? $af_group_names[ $group_id ] : __( 'Sin edificio', 'arriendo-facil' ) ); ?>
						</span>

						<label class="af-cs-switch">
							<input
								type="checkbox"
								data-af-cs-include
								data-property-id="<?php echo esc_attr( (int) $prop_id ); ?>"
								<?php checked( $in_catalog ); ?>
							/>
							<span><?php esc_html_e( 'En catálogo', 'arriendo-facil' ); ?></span>
						</label>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>