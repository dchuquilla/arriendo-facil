<?php
/**
 * Accommodation wizard view.
 *
 * @package Arriendo_Facil
 *
 * @var WP_Post|null $post           Current post (null for create).
 * @var int          $post_id        Post ID (0 for create).
 * @var string       $mode           'create' | 'edit'.
 * @var bool         $is_owner_user  Current user has af_owner role.
 * @var array        $data           Prefilled accommodation data.
 * @var array        $owner_options  Owner select options.
 * @var string       $saved_flag     '' | '1' | 'draft'.
 * @var string       $error_message  Error from previous submit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$statuses = array(
	'available'   => __( 'Disponible', 'arriendo-facil' ),
	'rented'      => __( 'Arrendado', 'arriendo-facil' ),
	'maintenance' => __( 'En mantenimiento', 'arriendo-facil' ),
	'inactive'    => __( 'Inactivo', 'arriendo-facil' ),
);

$property_types = array(
	'apartment'  => array( 'label' => __( 'Apartamento', 'arriendo-facil' ), 'icon' => '&#x1F3E2;' ),
	'house'      => array( 'label' => __( 'Casa', 'arriendo-facil' ), 'icon' => '&#x1F3E0;' ),
	'office'     => array( 'label' => __( 'Oficina', 'arriendo-facil' ), 'icon' => '&#x1F3D7;' ),
	'room'       => array( 'label' => __( 'Habitación', 'arriendo-facil' ), 'icon' => '&#x1F6CF;' ),
	'commercial' => array( 'label' => __( 'Comercial', 'arriendo-facil' ), 'icon' => '&#x1F3EA;' ),
);

$amenities_options = array(
	'pet-friendly' => array( 'label' => __( 'Mascotas', 'arriendo-facil' ), 'icon' => '&#x1F43E;' ),
	'wifi'         => array( 'label' => __( 'WiFi', 'arriendo-facil' ), 'icon' => '&#x1F4F6;' ),
	'parking'      => array( 'label' => __( 'Parqueadero', 'arriendo-facil' ), 'icon' => '&#x1F17F;' ),
	'pool'         => array( 'label' => __( 'Piscina', 'arriendo-facil' ), 'icon' => '&#x1F3CA;' ),
	'gym'          => array( 'label' => __( 'Gimnasio', 'arriendo-facil' ), 'icon' => '&#x1F3CB;' ),
	'kitchen'      => array( 'label' => __( 'Cocina', 'arriendo-facil' ), 'icon' => '&#x1F373;' ),
	'balcony'      => array( 'label' => __( 'Balcón', 'arriendo-facil' ), 'icon' => '&#x1F305;' ),
	'ac'           => array( 'label' => __( 'Aire Acond.', 'arriendo-facil' ), 'icon' => '&#x2744;' ),
);

$steps = array(
	1 => array( 'title' => __( 'Tipo y nombre', 'arriendo-facil' ),    'icon' => '&#x1F3E0;' ),
	2 => array( 'title' => __( 'Ubicación', 'arriendo-facil' ),        'icon' => '&#x1F4CD;' ),
	3 => array( 'title' => __( 'Características', 'arriendo-facil' ),  'icon' => '&#x1F4D0;' ),
	4 => array( 'title' => __( 'Fotos', 'arriendo-facil' ),            'icon' => '&#x1F4F7;' ),
	5 => array( 'title' => __( 'Precio y propietario', 'arriendo-facil' ), 'icon' => '&#x1F4B0;' ),
	6 => array( 'title' => __( 'Resumen y publicar', 'arriendo-facil' ),   'icon' => '&#x1F4DD;' ),
);

$wizard_class = 'af-wizard af-wizard--' . esc_attr( $mode );
$featured_url = $data['featured_id'] ? wp_get_attachment_image_url( (int) $data['featured_id'], 'medium' ) : '';
?>
<div class="<?php echo esc_attr( $wizard_class ); ?>" data-mode="<?php echo esc_attr( $mode ); ?>">

	<header class="af-wizard__header">
		<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=accommodation' ) ); ?>" class="af-wizard__back" data-af-cancel>
			<span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Volver a inmuebles', 'arriendo-facil' ); ?>
		</a>
		<div class="af-wizard__title">
			<h1>
				<?php echo 'edit' === $mode ? esc_html__( 'Editar inmueble', 'arriendo-facil' ) : esc_html__( 'Publicar un inmueble', 'arriendo-facil' ); ?>
			</h1>
			<p class="af-wizard__subtitle">
				<?php esc_html_e( 'Completa la información paso a paso. Puedes guardar como borrador en cualquier momento.', 'arriendo-facil' ); ?>
			</p>
		</div>
	</header>

	<?php if ( '1' === $saved_flag ) : ?>
		<div class="af-wizard__toast af-wizard__toast--success">
			<span aria-hidden="true">&#x2705;</span>
			<?php esc_html_e( 'Inmueble publicado correctamente.', 'arriendo-facil' ); ?>
		</div>
	<?php elseif ( 'draft' === $saved_flag ) : ?>
		<div class="af-wizard__toast af-wizard__toast--info">
			<span aria-hidden="true">&#x1F4BE;</span>
			<?php esc_html_e( 'Borrador guardado.', 'arriendo-facil' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $error_message ) : ?>
		<div class="af-wizard__toast af-wizard__toast--error">
			<span aria-hidden="true">&#x26A0;</span>
			<?php echo esc_html( $error_message ); ?>
		</div>
	<?php endif; ?>

	<div class="af-wizard__layout">

		<aside class="af-wizard__nav" aria-label="<?php esc_attr_e( 'Pasos del formulario', 'arriendo-facil' ); ?>">
			<ol class="af-wizard__steps">
				<?php foreach ( $steps as $n => $step ) : ?>
					<li class="af-wizard__step-item <?php echo 1 === $n ? 'is-current' : ''; ?>" data-step="<?php echo esc_attr( $n ); ?>">
						<button type="button" class="af-wizard__step-btn" data-jump-step="<?php echo esc_attr( $n ); ?>">
							<span class="af-wizard__step-num"><?php echo esc_html( (string) $n ); ?></span>
							<span class="af-wizard__step-label"><?php echo esc_html( $step['title'] ); ?></span>
						</button>
					</li>
				<?php endforeach; ?>
			</ol>
		</aside>

		<form class="af-wizard__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
			<?php wp_nonce_field( Arriendo_Facil_Accommodation_Wizard::NONCE_ACTION, Arriendo_Facil_Accommodation_Wizard::NONCE_NAME ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Arriendo_Facil_Accommodation_Wizard::SUBMIT_ACTION ); ?>" />
			<input type="hidden" name="af_post_id" value="<?php echo esc_attr( (string) $post_id ); ?>" />
			<input type="hidden" name="af_form_action" id="af_form_action" value="publish" />

			<!-- ============ PASO 1: TIPO Y NOMBRE ============ -->
			<section class="af-wizard__step" data-step="1">
				<div class="af-wizard__step-head">
					<span class="af-wizard__step-icon"><?php echo $steps[1]['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<div>
						<h2><?php esc_html_e( 'Tipo y nombre', 'arriendo-facil' ); ?></h2>
						<p><?php esc_html_e( 'Empecemos por lo básico: qué tipo de propiedad es y cómo se llamará en la publicación.', 'arriendo-facil' ); ?></p>
					</div>
				</div>

				<div class="af-field">
					<label class="af-field__label" for="post_title"><?php esc_html_e( 'Nombre del inmueble', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
					<input type="text" id="post_title" name="post_title" required maxlength="200"
						value="<?php echo esc_attr( $data['post_title'] ); ?>"
						class="af-input af-input--full af-input--lg"
						placeholder="<?php esc_attr_e( 'Ej: La Carolina', 'arriendo-facil' ); ?>" />
					<p class="af-field__hint"><?php esc_html_e( 'Este nombre se mostrará en los listados y en la página del inmueble.', 'arriendo-facil' ); ?></p>
				</div>

				<div class="af-field">
					<label class="af-field__label"><?php esc_html_e( 'Tipo de propiedad', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
					<div class="af-prop-type-grid">
						<?php foreach ( $property_types as $type_value => $type_data ) : ?>
							<label class="af-prop-type-card <?php echo ( $data['property_type'] === $type_value ) ? 'is-selected' : ''; ?>">
								<input type="radio" name="af_property_type" value="<?php echo esc_attr( $type_value ); ?>"
									<?php checked( $data['property_type'], $type_value ); ?> />
								<span class="af-prop-type-card__icon"><?php echo $type_data['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<span class="af-prop-type-card__label"><?php echo esc_html( $type_data['label'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="af-field">
					<label class="af-field__label"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></label>
					<div class="af-status-selector">
						<?php foreach ( $statuses as $s_value => $s_label ) :
							$status_value    = $data['status'] ? $data['status'] : 'available';
							$status_selected = $status_value === $s_value;
						?>
							<label class="af-status-option af-status-option--<?php echo esc_attr( $s_value ); ?> <?php echo $status_selected ? 'is-selected' : ''; ?>">
								<input type="radio" name="af_status" value="<?php echo esc_attr( $s_value ); ?>" <?php checked( $status_selected ); ?> />
								<span class="af-status-option__dot"></span>
								<?php echo esc_html( $s_label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<!-- ============ PASO 2: UBICACIÓN ============ -->
			<section class="af-wizard__step" data-step="2">
				<div class="af-wizard__step-head">
					<span class="af-wizard__step-icon"><?php echo $steps[2]['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<div>
						<h2><?php esc_html_e( 'Ubicación', 'arriendo-facil' ); ?></h2>
						<p><?php esc_html_e( 'Indica la dirección y marca el inmueble en el mapa para que los inquilinos lo encuentren.', 'arriendo-facil' ); ?></p>
					</div>
				</div>

				<div class="af-field-group af-field-group--two-col">
					<div class="af-field">
						<label class="af-field__label" for="af_address"><?php esc_html_e( 'Dirección completa', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
						<input type="text" id="af_address" name="af_address"
							value="<?php echo esc_attr( $data['address'] ); ?>"
							class="af-input af-input--full"
							placeholder="<?php esc_attr_e( 'Ej: Av. Amazonas N34-451', 'arriendo-facil' ); ?>" />
					</div>
					<div class="af-field">
						<label class="af-field__label" for="af_city"><?php esc_html_e( 'Ciudad', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
						<input type="text" id="af_city" name="af_city"
							value="<?php echo esc_attr( $data['city'] ); ?>"
							class="af-input af-input--full"
							placeholder="<?php esc_attr_e( 'Ej: Quito', 'arriendo-facil' ); ?>" />
					</div>
				</div>

				<div class="af-field">
					<label class="af-field__label" for="af_location_text"><?php esc_html_e( 'Sector o barrio', 'arriendo-facil' ); ?></label>
					<input type="text" id="af_location_text" name="af_location_text"
						value="<?php echo esc_attr( $data['location_text'] ); ?>"
						class="af-input af-input--full"
						placeholder="<?php esc_attr_e( 'Ej: La Carolina', 'arriendo-facil' ); ?>" />
					<p class="af-field__hint"><?php esc_html_e( 'Se usa en las búsquedas del sitio.', 'arriendo-facil' ); ?></p>
				</div>

				<div class="af-field">
					<label class="af-field__label"><?php esc_html_e( 'Ubicación en el mapa', 'arriendo-facil' ); ?></label>
					<div class="af-location-picker">
						<div class="af-location-search-row">
							<input type="text" id="af_location_search" autocomplete="off"
								class="af-input af-input--full"
								placeholder="<?php esc_attr_e( 'Buscar dirección o pegar URL de Google Maps...', 'arriendo-facil' ); ?>" />
							<button type="button" id="af_location_search_btn" class="button button-secondary">
								<?php esc_html_e( 'Buscar', 'arriendo-facil' ); ?>
							</button>
						</div>
						<div id="af-location-suggestions" class="af-location-suggestions"></div>
						<div id="af-location-map" class="af-map" tabindex="-1"></div>
						<input type="hidden" id="af_latitude" name="af_latitude" value="<?php echo esc_attr( $data['latitude'] ); ?>" />
						<input type="hidden" id="af_longitude" name="af_longitude" value="<?php echo esc_attr( $data['longitude'] ); ?>" />
						<?php if ( $data['latitude'] && $data['longitude'] ) : ?>
							<p class="af-field__hint af-coords-display">
								&#x1F4CC; <?php printf( esc_html__( 'Lat: %1$s, Lng: %2$s', 'arriendo-facil' ), esc_html( (string) $data['latitude'] ), esc_html( (string) $data['longitude'] ) ); ?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</section>

			<!-- ============ PASO 3: CARACTERÍSTICAS ============ -->
			<section class="af-wizard__step" data-step="3">
				<div class="af-wizard__step-head">
					<span class="af-wizard__step-icon"><?php echo $steps[3]['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<div>
						<h2><?php esc_html_e( 'Características', 'arriendo-facil' ); ?></h2>
						<p><?php esc_html_e( 'Cuéntale al inquilino cuántos espacios tiene y qué amenidades incluye.', 'arriendo-facil' ); ?></p>
					</div>
				</div>

				<div class="af-field-group af-field-group--three-col">
					<div class="af-field">
						<label class="af-field__label" for="af_bedrooms">&#x1F6CF; <?php esc_html_e( 'Dormitorios', 'arriendo-facil' ); ?></label>
						<div class="af-stepper">
							<button type="button" class="af-stepper__btn af-stepper__btn--minus" aria-label="<?php esc_attr_e( 'Reducir', 'arriendo-facil' ); ?>">&minus;</button>
							<input type="number" id="af_bedrooms" name="af_bedrooms" min="0" max="20"
								value="<?php echo esc_attr( (string) $data['bedrooms'] ); ?>" class="af-stepper__input" readonly />
							<button type="button" class="af-stepper__btn af-stepper__btn--plus" aria-label="<?php esc_attr_e( 'Aumentar', 'arriendo-facil' ); ?>">+</button>
						</div>
					</div>
					<div class="af-field">
						<label class="af-field__label" for="af_bathrooms">&#x1F6BF; <?php esc_html_e( 'Baños', 'arriendo-facil' ); ?></label>
						<div class="af-stepper">
							<button type="button" class="af-stepper__btn af-stepper__btn--minus" aria-label="<?php esc_attr_e( 'Reducir', 'arriendo-facil' ); ?>">&minus;</button>
							<input type="number" id="af_bathrooms" name="af_bathrooms" min="0" max="20"
								value="<?php echo esc_attr( (string) $data['bathrooms'] ); ?>" class="af-stepper__input" readonly />
							<button type="button" class="af-stepper__btn af-stepper__btn--plus" aria-label="<?php esc_attr_e( 'Aumentar', 'arriendo-facil' ); ?>">+</button>
						</div>
					</div>
					<div class="af-field">
						<label class="af-field__label" for="af_square_meters">&#x1F4CF; <?php esc_html_e( 'Metros cuadrados', 'arriendo-facil' ); ?></label>
						<div class="af-input-with-unit">
							<input type="number" id="af_square_meters" name="af_square_meters" step="0.5" min="0"
								value="<?php echo esc_attr( (string) $data['square_meters'] ); ?>"
								class="af-input af-input--full"
								placeholder="0" />
							<span class="af-input-unit">m&sup2;</span>
						</div>
					</div>
				</div>

				<div class="af-field">
					<label class="af-field__label"><?php esc_html_e( 'Amenidades', 'arriendo-facil' ); ?></label>
					<div class="af-amenities-grid">
						<?php foreach ( $amenities_options as $amenity_value => $amenity_data ) :
							$is_checked = in_array( $amenity_value, $data['amenities'], true );
						?>
							<label class="af-amenity-chip <?php echo $is_checked ? 'is-selected' : ''; ?>">
								<input type="checkbox" name="af_amenities[]" value="<?php echo esc_attr( $amenity_value ); ?>" <?php checked( $is_checked ); ?> />
								<span class="af-amenity-chip__icon"><?php echo $amenity_data['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<span class="af-amenity-chip__label"><?php echo esc_html( $amenity_data['label'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="af-field">
					<label class="af-field__label" for="post_content"><?php esc_html_e( 'Descripción del inmueble', 'arriendo-facil' ); ?></label>
					<textarea id="post_content" name="post_content" rows="6"
						class="af-input af-input--full af-textarea"
						placeholder="<?php esc_attr_e( 'Describe el inmueble: ambiente, entorno, qué lo hace especial...', 'arriendo-facil' ); ?>"><?php echo esc_textarea( $data['post_content'] ); ?></textarea>
					<p class="af-field__hint"><?php esc_html_e( 'Esta descripción se mostrará en la publicación del inmueble.', 'arriendo-facil' ); ?></p>
				</div>
			</section>

			<!-- ============ PASO 4: FOTOS ============ -->
			<section class="af-wizard__step" data-step="4">
				<div class="af-wizard__step-head">
					<span class="af-wizard__step-icon"><?php echo $steps[4]['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<div>
						<h2><?php esc_html_e( 'Fotos', 'arriendo-facil' ); ?></h2>
						<p><?php esc_html_e( 'Una buena galería triplica las consultas. Sube fotos claras y bien iluminadas.', 'arriendo-facil' ); ?></p>
					</div>
				</div>

				<div class="af-field">
					<label class="af-field__label"><?php esc_html_e( 'Foto principal (portada)', 'arriendo-facil' ); ?></label>
					<div class="af-featured-picker">
						<div id="af-featured-preview" class="af-featured-preview <?php echo $featured_url ? 'has-image' : ''; ?>">
							<?php if ( $featured_url ) : ?>
								<img src="<?php echo esc_url( $featured_url ); ?>" alt="" />
							<?php else : ?>
								<span class="af-featured-preview__placeholder">
									<span aria-hidden="true">&#x1F5BC;</span>
									<?php esc_html_e( 'Sin foto principal', 'arriendo-facil' ); ?>
								</span>
							<?php endif; ?>
						</div>
						<div class="af-featured-actions">
							<button type="button" id="af-featured-pick" class="button button-secondary">
								<?php echo $featured_url ? esc_html__( 'Cambiar foto', 'arriendo-facil' ) : esc_html__( 'Elegir foto principal', 'arriendo-facil' ); ?>
							</button>
							<button type="button" id="af-featured-remove" class="button-link af-featured-remove" <?php echo $featured_url ? '' : 'hidden'; ?>>
								<?php esc_html_e( 'Quitar', 'arriendo-facil' ); ?>
							</button>
							<input type="hidden" id="af_featured_image_id" name="af_featured_image_id" value="<?php echo esc_attr( (string) $data['featured_id'] ); ?>" />
							<p class="af-field__hint"><?php esc_html_e( 'Esta foto aparece como portada en los listados.', 'arriendo-facil' ); ?></p>
						</div>
					</div>
				</div>

				<div class="af-field">
					<label class="af-field__label"><?php esc_html_e( 'Galería de fotos', 'arriendo-facil' ); ?></label>
					<div class="af-gallery-box">
						<p class="af-gallery-box__intro">
							<?php esc_html_e( 'Agrega hasta 20 fotos adicionales del inmueble.', 'arriendo-facil' ); ?>
						</p>

						<div id="af-gallery-grid" class="af-gallery-grid">
							<?php foreach ( $data['gallery_ids'] as $att_id ) :
								$thumb = wp_get_attachment_image_src( $att_id, 'thumbnail' );
								if ( ! $thumb ) {
									continue;
								}
							?>
								<div class="af-gallery-item" data-id="<?php echo esc_attr( (string) $att_id ); ?>">
									<img src="<?php echo esc_url( $thumb[0] ); ?>" alt="" />
									<button type="button" class="af-gallery-item__remove" title="<?php esc_attr_e( 'Eliminar foto', 'arriendo-facil' ); ?>">&#x2715;</button>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="af-gallery-actions">
							<button type="button" id="af-gallery-add-btn" class="button button-secondary af-gallery-add-btn">
								<span class="af-gallery-add-btn__icon">&#x1F4F7;</span>
								<?php esc_html_e( 'Agregar fotos', 'arriendo-facil' ); ?>
							</button>
							<span class="af-gallery-count">
								<span id="af-gallery-count-num"><?php echo (int) count( $data['gallery_ids'] ); ?></span>
								<?php esc_html_e( 'foto(s) agregada(s)', 'arriendo-facil' ); ?>
							</span>
						</div>

						<input type="hidden" id="af_gallery_ids" name="af_gallery_ids"
							value="<?php echo esc_attr( implode( ',', array_map( 'absint', $data['gallery_ids'] ) ) ); ?>" />
					</div>
				</div>
			</section>

			<!-- ============ PASO 5: PRECIO Y PUBLICACIÓN ============ -->
			<section class="af-wizard__step" data-step="5">
				<div class="af-wizard__step-head">
					<span class="af-wizard__step-icon"><?php echo $steps[5]['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<div>
						<h2><?php esc_html_e( 'Precio y publicación', 'arriendo-facil' ); ?></h2>
						<p><?php esc_html_e( 'Define el arriendo mensual y publica el inmueble.', 'arriendo-facil' ); ?></p>
					</div>
				</div>

				<div class="af-field">
					<label class="af-field__label" for="af_monthly_rent"><?php esc_html_e( 'Arriendo mensual (USD)', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
					<div class="af-rent-row">
						<div class="af-input-with-unit af-input-with-unit--prefix">
							<span class="af-input-unit af-input-unit--prefix">$</span>
							<input type="number" id="af_monthly_rent" name="af_monthly_rent" step="0.01" min="0"
								value="<?php echo esc_attr( (string) $data['monthly_rent'] ); ?>"
								class="af-input af-input--rent"
								placeholder="0.00" />
						</div>
						<?php // Sugerir precio (IA) — temporalmente oculto. Mantener marcado para reactivar más adelante. ?>
						<span class="af-predict-cost-wrapper" style="display:none;">
							<button type="button" class="button af-predict-cost af-predict-btn"
								data-id="<?php echo esc_attr( (string) $post_id ); ?>"
								<?php echo $post_id ? '' : 'disabled'; ?>>
								&#x2728; <?php esc_html_e( 'Sugerir precio (IA)', 'arriendo-facil' ); ?>
							</button>
						</span>
					</div>
					<span class="af-predict-result" style="display:none;"></span>
				</div>

				<div class="af-field">
					<label class="af-field__label"><?php esc_html_e( 'Propietario', 'arriendo-facil' ); ?></label>
					<?php if ( $is_owner_user ) : ?>
						<input type="hidden" id="af_owner_id" name="af_owner_id" value="<?php echo esc_attr( (string) get_current_user_id() ); ?>" />
						<div class="af-owner-auto-badge">
							<span class="af-owner-auto-badge__icon">&#x2705;</span>
							<span><?php esc_html_e( 'Este inmueble quedará vinculado a tu cuenta automáticamente.', 'arriendo-facil' ); ?></span>
						</div>
					<?php else : ?>
						<select id="af_owner_id" name="af_owner_id" class="af-input af-input--select">
							<option value="0"><?php esc_html_e( '— Seleccionar propietario —', 'arriendo-facil' ); ?></option>
							<?php foreach ( $owner_options as $owner_option ) : ?>
								<option value="<?php echo esc_attr( (string) $owner_option['id'] ); ?>" <?php selected( (int) $data['owner_id'], (int) $owner_option['id'] ); ?>>
									<?php echo esc_html( (string) $owner_option['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				</div>
			</section>

			<!-- ============ PASO 6: RESUMEN ============ -->
			<section class="af-wizard__step" data-step="6">
				<div class="af-wizard__step-head">
					<span class="af-wizard__step-icon"><?php echo $steps[6]['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<div>
						<h2><?php esc_html_e( 'Resumen', 'arriendo-facil' ); ?></h2>
						<p><?php esc_html_e( 'Revisa toda la información antes de publicar. Puedes volver a cualquier paso para corregir.', 'arriendo-facil' ); ?></p>
					</div>
				</div>

				<div class="af-summary" id="af-summary">
					<div class="af-summary__card" data-jump-step="1">
						<div class="af-summary__card-head">
							<h3><?php esc_html_e( 'Tipo y nombre', 'arriendo-facil' ); ?></h3>
							<button type="button" class="button-link af-summary__edit" data-jump-step="1"><?php esc_html_e( 'Editar', 'arriendo-facil' ); ?></button>
						</div>
						<dl class="af-summary__list">
							<dt><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="post_title">—</dd>
							<dt><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="af_property_type">—</dd>
							<dt><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="af_status">—</dd>
						</dl>
					</div>

					<div class="af-summary__card">
						<div class="af-summary__card-head">
							<h3><?php esc_html_e( 'Ubicación', 'arriendo-facil' ); ?></h3>
							<button type="button" class="button-link af-summary__edit" data-jump-step="2"><?php esc_html_e( 'Editar', 'arriendo-facil' ); ?></button>
						</div>
						<dl class="af-summary__list">
							<dt><?php esc_html_e( 'Dirección', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="af_address">—</dd>
							<dt><?php esc_html_e( 'Ciudad', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="af_city">—</dd>
							<dt><?php esc_html_e( 'Sector', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="af_location_text">—</dd>
							<dt><?php esc_html_e( 'Coordenadas', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="coords">—</dd>
						</dl>
					</div>

					<div class="af-summary__card">
						<div class="af-summary__card-head">
							<h3><?php esc_html_e( 'Características', 'arriendo-facil' ); ?></h3>
							<button type="button" class="button-link af-summary__edit" data-jump-step="3"><?php esc_html_e( 'Editar', 'arriendo-facil' ); ?></button>
						</div>
						<dl class="af-summary__list">
							<dt><?php esc_html_e( 'Dormitorios', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="af_bedrooms">—</dd>
							<dt><?php esc_html_e( 'Baños', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="af_bathrooms">—</dd>
							<dt><?php esc_html_e( 'Metros cuadrados', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="af_square_meters">—</dd>
							<dt><?php esc_html_e( 'Amenidades', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="af_amenities">—</dd>
							<dt><?php esc_html_e( 'Descripción', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="post_content">—</dd>
						</dl>
					</div>

					<div class="af-summary__card">
						<div class="af-summary__card-head">
							<h3><?php esc_html_e( 'Fotos', 'arriendo-facil' ); ?></h3>
							<button type="button" class="button-link af-summary__edit" data-jump-step="4"><?php esc_html_e( 'Editar', 'arriendo-facil' ); ?></button>
						</div>
						<div class="af-summary__photos" data-af-summary="photos">
							<p class="af-summary__empty"><?php esc_html_e( 'Sin fotos', 'arriendo-facil' ); ?></p>
						</div>
					</div>

					<div class="af-summary__card">
						<div class="af-summary__card-head">
							<h3><?php esc_html_e( 'Precio y propietario', 'arriendo-facil' ); ?></h3>
							<button type="button" class="button-link af-summary__edit" data-jump-step="5"><?php esc_html_e( 'Editar', 'arriendo-facil' ); ?></button>
						</div>
						<dl class="af-summary__list">
							<dt><?php esc_html_e( 'Arriendo mensual', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="af_monthly_rent">—</dd>
							<dt><?php esc_html_e( 'Propietario', 'arriendo-facil' ); ?></dt>
							<dd data-af-summary="af_owner_id">—</dd>
						</dl>
					</div>
				</div>
			</section>

			<!-- Sticky action bar -->
			<div class="af-wizard__actions">
				<div class="af-wizard__actions-left">
					<button type="submit" class="button button-link af-wizard__cancel" data-af-action="cancel">
						<?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?>
					</button>
					<button type="submit" class="button af-wizard__draft" data-af-action="draft">
						<?php esc_html_e( 'Guardar borrador', 'arriendo-facil' ); ?>
					</button>
				</div>
				<div class="af-wizard__actions-right">
					<button type="button" class="button af-wizard__prev" data-af-prev hidden>
						<?php esc_html_e( '← Anterior', 'arriendo-facil' ); ?>
					</button>
					<button type="button" class="button button-primary af-wizard__next" data-af-next>
						<?php esc_html_e( 'Siguiente →', 'arriendo-facil' ); ?>
					</button>
					<button type="submit" class="button button-primary af-wizard__publish" data-af-action="publish" disabled>
						<?php echo 'edit' === $mode ? esc_html__( 'Guardar cambios', 'arriendo-facil' ) : esc_html__( 'Publicar inmueble', 'arriendo-facil' ); ?>
					</button>
				</div>
			</div>

		</form>
	</div>

	<div class="af-wizard__loading" id="af-wizard-loading" hidden aria-live="polite" aria-busy="true">
		<div class="af-wizard__loading-card">
			<div class="af-wizard__spinner" aria-hidden="true"></div>
			<p class="af-wizard__loading-text" id="af-wizard-loading-text"><?php esc_html_e( 'Publicando inmueble...', 'arriendo-facil' ); ?></p>
			<p class="af-wizard__loading-hint"><?php esc_html_e( 'Esto puede tardar unos segundos. No cierres la ventana.', 'arriendo-facil' ); ?></p>
		</div>
	</div>
</div>
