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
		 MAX(CASE WHEN pm.meta_key = '_thumbnail_id'        THEN pm.meta_value END) AS thumbnail_id
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
		  AND pm.meta_key IN ('_af_monthly_rent','_af_status','_af_property_type','_af_address','_af_city','_af_bedrooms','_af_bathrooms','_af_owner_id','_thumbnail_id')
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
$owner_names = array();
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
				__( 'Aquí ves todas tus propiedades para arriendo, una por una (%d). Pulsa "Nueva propiedad" para registrar otra.', 'arriendo-facil' ),
				$total_count
			),
			'actions'  => array(
				array(
					'label'   => __( 'Nueva propiedad', 'arriendo-facil' ),
					'url'     => admin_url( 'post-new.php?post_type=accommodation' ),
					'variant' => 'primary',
					'icon'    => '+',
				),
			),
		)
	);
	?>

	<section class="af-section af-catalog-filters">
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
					<label class="af-form-field__label" for="af-cat-admin"><?php esc_html_e( 'Administrador', 'arriendo-facil' ); ?></label>
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

				$status_lbls = array(
					'available'   => __( 'Disponible', 'arriendo-facil' ),
					'rented'      => __( 'Arrendado', 'arriendo-facil' ),
					'maintenance' => __( 'En mantenimiento', 'arriendo-facil' ),
					'inactive'    => __( 'Inactivo', 'arriendo-facil' ),
				);
				$type_icon   = isset( $property_types[ $type ]['icon'] ) ? $property_types[ $type ]['icon'] : 'building';
				$type_label  = isset( $property_types[ $type ]['label'] ) ? $property_types[ $type ]['label'] : ucfirst( $type );
				?>
				<a class="af-property-card" href="<?php echo esc_url( get_edit_post_link( $prop_id ) ); ?>">
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
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>