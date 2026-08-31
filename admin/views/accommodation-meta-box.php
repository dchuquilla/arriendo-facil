<?php
/**
 * Accommodation meta box view.
 *
 * @package Arriendo_Facil
 * @var WP_Post $post         Current post object (in scope from render_meta_box).
 * @var string  $address          Current address meta value.
 * @var string  $location_text    Current location text meta value (city, neighborhood).
 * @var float   $latitude         Current latitude meta value.
 * @var float   $longitude        Current longitude meta value.
 * @var int     $bedrooms         Current bedrooms meta value.
 * @var int     $bathrooms        Current bathrooms meta value.
 * @var float   $monthly_rent     Current monthly rent meta value.
 * @var string  $property_type    Current property type meta value.
 * @var float   $square_meters    Current square meters meta value.
 * @var array   $amenities        Current amenities meta value (array).
 * @var int     $owner_id         Current owner user ID meta value.
 * @var string  $status           Current accommodation status meta value.
 * @var array   $owner_options    Available owner options.
 * @var bool    $is_owner_user    Whether current editor is an owner role.
 * @var int     $year_built       Current year built meta value.
 * @var int     $floor_number     Current floor number meta value.
 * @var int     $total_floors     Current building total floors meta value.
 * @var int     $parking_spots    Current parking spots meta value.
 * @var string  $furnished        Current furnished state meta value.
 * @var string  $condition        Current condition meta value.
 * @var float   $hoa_fee          Current HOA/maintenance fee meta value.
 * @var array   $utilities_included Current utilities included meta value (array).
 * @var array   $inventory        Current inventory items (array of assoc arrays).
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
	'room'       => array( 'label' => __( 'Habitaci&oacute;n', 'arriendo-facil' ), 'icon' => '&#x1F6CF;' ),
	'commercial' => array( 'label' => __( 'Comercial', 'arriendo-facil' ), 'icon' => '&#x1F3EA;' ),
);

$amenities_options = array(
	'pet-friendly' => array( 'label' => __( 'Mascotas', 'arriendo-facil' ), 'icon' => '&#x1F43E;' ),
	'wifi'         => array( 'label' => __( 'WiFi', 'arriendo-facil' ), 'icon' => '&#x1F4F6;' ),
	'parking'      => array( 'label' => __( 'Parqueadero', 'arriendo-facil' ), 'icon' => '&#x1F17F;' ),
	'pool'         => array( 'label' => __( 'Piscina', 'arriendo-facil' ), 'icon' => '&#x1F3CA;' ),
	'gym'          => array( 'label' => __( 'Gimnasio', 'arriendo-facil' ), 'icon' => '&#x1F3CB;' ),
	'kitchen'      => array( 'label' => __( 'Cocina', 'arriendo-facil' ), 'icon' => '&#x1F373;' ),
	'balcony'      => array( 'label' => __( 'Balc&oacute;n', 'arriendo-facil' ), 'icon' => '&#x1F305;' ),
	'ac'           => array( 'label' => __( 'Aire Acond.', 'arriendo-facil' ), 'icon' => '&#x2744;' ),
);

$furnished_options = array(
	'unfurnished' => __( 'Sin amoblar', 'arriendo-facil' ),
	'semi'        => __( 'Semi-amoblado', 'arriendo-facil' ),
	'furnished'   => __( 'Amoblado', 'arriendo-facil' ),
);

$condition_options = array(
	'new'          => __( 'Nuevo', 'arriendo-facil' ),
	'very_good'    => __( 'Muy bueno', 'arriendo-facil' ),
	'good'         => __( 'Bueno', 'arriendo-facil' ),
	'needs_repair' => __( 'A remodelar', 'arriendo-facil' ),
);

$utilities_options = array(
	'water'    => __( 'Agua', 'arriendo-facil' ),
	'electric' => __( 'Luz', 'arriendo-facil' ),
	'internet' => __( 'Internet', 'arriendo-facil' ),
	'gas'      => __( 'Gas', 'arriendo-facil' ),
);

$inventory_categories = array(
	'electrodomestico' => __( 'Electrodoméstico', 'arriendo-facil' ),
	'mobiliario'       => __( 'Mobiliario', 'arriendo-facil' ),
	'otro'             => __( 'Otro', 'arriendo-facil' ),
);
?>
<div class="af-accom-form">

	<!-- SECCION 1: Tipo de propiedad y estado -->
	<div class="af-accom-section">
		<div class="af-accom-section__header">
			<span class="af-accom-section__icon">&#x1F3E0;</span>
			<h3 class="af-accom-section__title"><?php esc_html_e( 'Tipo de propiedad', 'arriendo-facil' ); ?></h3>
		</div>
		<div class="af-accom-section__body">
			<div class="af-field-group af-field-group--two-col">
				<div class="af-field">
					<label class="af-field__label"><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></label>
					<div class="af-prop-type-grid">
						<?php foreach ( $property_types as $type_value => $type_data ) : ?>
							<label class="af-prop-type-card <?php echo ( $property_type === $type_value ) ? 'is-selected' : ''; ?>">
								<input type="radio" name="af_property_type" value="<?php echo esc_attr( $type_value ); ?>"
									<?php checked( $property_type, $type_value ); ?> />
								<span class="af-prop-type-card__icon"><?php echo $type_data['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<span class="af-prop-type-card__label"><?php echo esc_html( $type_data['label'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="af-field">
					<label class="af-field__label"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></label>
					<div class="af-status-selector">
						<?php foreach ( $statuses as $s_value => $s_label ) : ?>
							<label class="af-status-option af-status-option--<?php echo esc_attr( $s_value ); ?> <?php echo ( $status === $s_value ) ? 'is-selected' : ''; ?>">
								<input type="radio" name="af_status" value="<?php echo esc_attr( $s_value ); ?>"
									<?php checked( $status, $s_value ); ?> />
								<span class="af-status-option__dot"></span>
								<?php echo esc_html( $s_label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- SECCION 2: Ubicacion -->
	<div class="af-accom-section">
		<div class="af-accom-section__header">
			<span class="af-accom-section__icon">&#x1F4CD;</span>
			<h3 class="af-accom-section__title"><?php esc_html_e( 'Ubicaci&oacute;n', 'arriendo-facil' ); ?></h3>
		</div>
		<div class="af-accom-section__body">
			<div class="af-field-group af-field-group--three-col">
				<div class="af-field af-field--span-2">
					<label class="af-field__label" for="af_address"><?php esc_html_e( 'Direcci&oacute;n completa', 'arriendo-facil' ); ?></label>
					<input type="text" id="af_address" name="af_address"
						value="<?php echo esc_attr( $address ); ?>"
						class="af-input af-input--full"
						placeholder="<?php esc_attr_e( 'Ej: Av. Amazonas N34-451', 'arriendo-facil' ); ?>" />
				</div>
				<div class="af-field">
					<label class="af-field__label" for="af_city"><?php esc_html_e( 'Ciudad', 'arriendo-facil' ); ?></label>
					<input type="text" id="af_city" name="af_city"
						value="<?php echo esc_attr( $city ); ?>"
						class="af-input af-input--full"
						placeholder="<?php esc_attr_e( 'Ej: Quito', 'arriendo-facil' ); ?>" />
					<p class="af-field__hint"><?php esc_html_e( 'Se usa en el contrato', 'arriendo-facil' ); ?></p>
				</div>
			</div>
			<div class="af-field" style="margin-top: 12px;">
				<label class="af-field__label" for="af_location_text"><?php esc_html_e( 'Sector / Barrio', 'arriendo-facil' ); ?></label>
				<input type="text" id="af_location_text" name="af_location_text"
					value="<?php echo esc_attr( $location_text ); ?>"
					class="af-input af-input--full"
					placeholder="<?php esc_attr_e( 'Ej: Quito, La Carolina', 'arriendo-facil' ); ?>" />
				<p class="af-field__hint"><?php esc_html_e( 'Usado en las b&uacute;squedas del sitio', 'arriendo-facil' ); ?></p>
			</div>
			<div class="af-field" style="margin-top: 16px;">
				<label class="af-field__label"><?php esc_html_e( 'Ubicaci&oacute;n en el mapa', 'arriendo-facil' ); ?></label>
				<div class="af-location-picker">
					<div class="af-location-search-row">
						<input type="text" id="af_location_search" autocomplete="off"
							class="af-input af-input--full"
							placeholder="<?php esc_attr_e( 'Buscar direcci&oacute;n o pegar URL de Google Maps...', 'arriendo-facil' ); ?>" />
						<button type="button" id="af_location_search_btn" class="button button-secondary">
							<?php esc_html_e( 'Buscar', 'arriendo-facil' ); ?>
						</button>
					</div>
					<div id="af-location-suggestions" class="af-location-suggestions"></div>
					<div id="af-location-map" class="af-map" tabindex="-1"></div>
					<input type="hidden" id="af_latitude" name="af_latitude"
						value="<?php echo esc_attr( $latitude ); ?>" />
					<input type="hidden" id="af_longitude" name="af_longitude"
						value="<?php echo esc_attr( $longitude ); ?>" />
					<?php if ( $latitude && $longitude ) : ?>
						<p class="af-field__hint af-coords-display">
							&#x1F4CC; <?php printf( esc_html__( 'Lat: %1$s, Lng: %2$s', 'arriendo-facil' ), esc_html( $latitude ), esc_html( $longitude ) ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- SECCION 3: Caracteristicas -->
	<div class="af-accom-section">
		<div class="af-accom-section__header">
			<span class="af-accom-section__icon">&#x1F4D0;</span>
			<h3 class="af-accom-section__title"><?php esc_html_e( 'Caracter&iacute;sticas', 'arriendo-facil' ); ?></h3>
		</div>
		<div class="af-accom-section__body">
			<div class="af-field-group af-field-group--three-col">
				<div class="af-field">
					<label class="af-field__label" for="af_bedrooms">
						&#x1F6CF; <?php esc_html_e( 'Dormitorios', 'arriendo-facil' ); ?>
					</label>
					<div class="af-stepper">
						<button type="button" class="af-stepper__btn af-stepper__btn--minus" aria-label="<?php esc_attr_e( 'Reducir', 'arriendo-facil' ); ?>">&minus;</button>
						<input type="number" id="af_bedrooms" name="af_bedrooms" min="0" max="20"
							value="<?php echo esc_attr( $bedrooms ? $bedrooms : 0 ); ?>" class="af-stepper__input" readonly />
						<button type="button" class="af-stepper__btn af-stepper__btn--plus" aria-label="<?php esc_attr_e( 'Aumentar', 'arriendo-facil' ); ?>">+</button>
					</div>
				</div>
				<div class="af-field">
					<label class="af-field__label" for="af_bathrooms">
						&#x1F6BF; <?php esc_html_e( 'Ba&ntilde;os', 'arriendo-facil' ); ?>
					</label>
					<div class="af-stepper">
						<button type="button" class="af-stepper__btn af-stepper__btn--minus" aria-label="<?php esc_attr_e( 'Reducir', 'arriendo-facil' ); ?>">&minus;</button>
						<input type="number" id="af_bathrooms" name="af_bathrooms" min="0" max="20"
							value="<?php echo esc_attr( $bathrooms ? $bathrooms : 0 ); ?>" class="af-stepper__input" readonly />
						<button type="button" class="af-stepper__btn af-stepper__btn--plus" aria-label="<?php esc_attr_e( 'Aumentar', 'arriendo-facil' ); ?>">+</button>
					</div>
				</div>
				<div class="af-field">
					<label class="af-field__label" for="af_square_meters">
						&#x1F4CF; <?php esc_html_e( 'Metros cuadrados', 'arriendo-facil' ); ?>
					</label>
					<div class="af-input-with-unit">
						<input type="number" id="af_square_meters" name="af_square_meters" step="0.5" min="0"
							value="<?php echo esc_attr( $square_meters ); ?>"
							class="af-input af-input--full"
							placeholder="0" />
						<span class="af-input-unit">m&sup2;</span>
					</div>
				</div>
			</div>

			<div class="af-field" style="margin-top: 20px;">
				<label class="af-field__label"><?php esc_html_e( 'Amenidades', 'arriendo-facil' ); ?></label>
				<div class="af-amenities-grid">
					<?php foreach ( $amenities_options as $amenity_value => $amenity_data ) :
						$is_checked = in_array( $amenity_value, $amenities, true );
					?>
						<label class="af-amenity-chip <?php echo $is_checked ? 'is-selected' : ''; ?>">
							<input type="checkbox" name="af_amenities[]"
								value="<?php echo esc_attr( $amenity_value ); ?>"
								<?php checked( $is_checked ); ?> />
							<span class="af-amenity-chip__icon"><?php echo $amenity_data['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<span class="af-amenity-chip__label"><?php echo esc_html( $amenity_data['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- SECCION 4: Precio -->
	<div class="af-accom-section">
		<div class="af-accom-section__header">
			<span class="af-accom-section__icon">&#x1F4B0;</span>
			<h3 class="af-accom-section__title"><?php esc_html_e( 'Precio', 'arriendo-facil' ); ?></h3>
		</div>
		<div class="af-accom-section__body">
			<div class="af-field">
				<label class="af-field__label" for="af_monthly_rent"><?php esc_html_e( 'Arriendo mensual (USD)', 'arriendo-facil' ); ?></label>
				<div class="af-rent-row">
					<div class="af-input-with-unit af-input-with-unit--prefix">
						<span class="af-input-unit af-input-unit--prefix">$</span>
						<input type="number" id="af_monthly_rent" name="af_monthly_rent" step="0.01" min="0"
							value="<?php echo esc_attr( $monthly_rent ); ?>"
							class="af-input af-input--rent"
							placeholder="0.00" />
					</div>
					<?php if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) : ?>
						<button type="button" class="button af-predict-cost af-predict-btn"
							data-id="<?php echo esc_attr( $post->ID ); ?>">
							&#x2728; <?php esc_html_e( 'Sugerir precio (IA)', 'arriendo-facil' ); ?>
						</button>
					<?php endif; ?>
				</div>
				<span class="af-predict-result"></span>
			</div>
		</div>
	</div>

	<!-- SECCION 4B: Detalles adicionales -->
	<div class="af-accom-section">
		<div class="af-accom-section__header">
			<span class="af-accom-section__icon">&#x1F4CB;</span>
			<h3 class="af-accom-section__title"><?php esc_html_e( 'Detalles adicionales', 'arriendo-facil' ); ?></h3>
		</div>
		<div class="af-accom-section__body">
			<div class="af-field-group af-field-group--three-col">
				<div class="af-field">
					<label class="af-field__label" for="af_year_built"><?php esc_html_e( 'Año de construcción', 'arriendo-facil' ); ?></label>
					<input type="number" id="af_year_built" name="af_year_built" min="1900" max="<?php echo esc_attr( gmdate( 'Y' ) + 1 ); ?>"
						value="<?php echo esc_attr( $year_built ); ?>" class="af-input af-input--full" placeholder="<?php echo esc_attr( gmdate( 'Y' ) ); ?>" />
				</div>
				<div class="af-field">
					<label class="af-field__label" for="af_floor_number"><?php esc_html_e( 'Piso', 'arriendo-facil' ); ?></label>
					<input type="number" id="af_floor_number" name="af_floor_number" min="0" max="200"
						value="<?php echo esc_attr( $floor_number ); ?>" class="af-input af-input--full" placeholder="0" />
				</div>
				<div class="af-field">
					<label class="af-field__label" for="af_total_floors"><?php esc_html_e( 'Total de pisos del edificio', 'arriendo-facil' ); ?></label>
					<input type="number" id="af_total_floors" name="af_total_floors" min="0" max="200"
						value="<?php echo esc_attr( $total_floors ); ?>" class="af-input af-input--full" placeholder="0" />
				</div>
				<div class="af-field">
					<label class="af-field__label" for="af_parking_spots"><?php esc_html_e( 'Parqueaderos', 'arriendo-facil' ); ?></label>
					<input type="number" id="af_parking_spots" name="af_parking_spots" min="0" max="20"
						value="<?php echo esc_attr( $parking_spots ); ?>" class="af-input af-input--full" placeholder="0" />
				</div>
				<div class="af-field">
					<label class="af-field__label" for="af_furnished"><?php esc_html_e( 'Estado de amoblado', 'arriendo-facil' ); ?></label>
					<select id="af_furnished" name="af_furnished" class="af-input af-input--select">
						<?php foreach ( $furnished_options as $f_value => $f_label ) : ?>
							<option value="<?php echo esc_attr( $f_value ); ?>" <?php selected( $furnished, $f_value ); ?>><?php echo esc_html( $f_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="af-field">
					<label class="af-field__label" for="af_condition"><?php esc_html_e( 'Estado general', 'arriendo-facil' ); ?></label>
					<select id="af_condition" name="af_condition" class="af-input af-input--select">
						<?php foreach ( $condition_options as $c_value => $c_label ) : ?>
							<option value="<?php echo esc_attr( $c_value ); ?>" <?php selected( $condition, $c_value ); ?>><?php echo esc_html( $c_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="af-field">
					<label class="af-field__label" for="af_hoa_fee"><?php esc_html_e( 'Alícuota / mantenimiento mensual (USD)', 'arriendo-facil' ); ?></label>
					<input type="number" id="af_hoa_fee" name="af_hoa_fee" step="0.01" min="0"
						value="<?php echo esc_attr( $hoa_fee ); ?>" class="af-input af-input--full" placeholder="0.00" />
				</div>
			</div>

			<div class="af-field" style="margin-top: 20px;">
				<label class="af-field__label"><?php esc_html_e( 'Servicios incluidos en el arriendo', 'arriendo-facil' ); ?></label>
				<div class="af-amenities-grid">
					<?php foreach ( $utilities_options as $u_value => $u_label ) :
						$u_checked = in_array( $u_value, $utilities_included, true );
					?>
						<label class="af-amenity-chip <?php echo $u_checked ? 'is-selected' : ''; ?>">
							<input type="checkbox" name="af_utilities_included[]"
								value="<?php echo esc_attr( $u_value ); ?>" <?php checked( $u_checked ); ?> />
							<span class="af-amenity-chip__label"><?php echo esc_html( $u_label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- SECCION 4C: Inventario -->
	<div class="af-accom-section">
		<div class="af-accom-section__header">
			<span class="af-accom-section__icon">&#x1F4E6;</span>
			<h3 class="af-accom-section__title"><?php esc_html_e( 'Inventario del inmueble', 'arriendo-facil' ); ?></h3>
		</div>
		<div class="af-accom-section__body">
			<p class="af-field__hint">
				<?php esc_html_e( 'Mobiliario y electrodomésticos que se entregan con la propiedad. Se usa para el checklist de entrega/devolución.', 'arriendo-facil' ); ?>
			</p>

			<table class="af-inventory-table" id="af-inventory-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Ítem', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Categoría', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Cant.', 'arriendo-facil' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="af-inventory-rows">
					<?php foreach ( $inventory as $inv_index => $inv_item ) : ?>
						<tr class="af-inventory-row">
							<td><input type="text" class="af-input af-inv-item" value="<?php echo esc_attr( $inv_item['item'] ); ?>" placeholder="<?php esc_attr_e( 'Ej: Refrigeradora', 'arriendo-facil' ); ?>" /></td>
							<td>
								<select class="af-input af-inv-category">
									<?php foreach ( $inventory_categories as $cat_value => $cat_label ) : ?>
										<option value="<?php echo esc_attr( $cat_value ); ?>" <?php selected( $inv_item['category'], $cat_value ); ?>><?php echo esc_html( $cat_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select class="af-input af-inv-condition">
									<?php foreach ( $condition_options as $c_value => $c_label ) : ?>
										<option value="<?php echo esc_attr( $c_value ); ?>" <?php selected( $inv_item['condition'], $c_value ); ?>><?php echo esc_html( $c_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><input type="number" class="af-input af-inv-quantity" min="1" max="99" value="<?php echo esc_attr( $inv_item['quantity'] ); ?>" /></td>
							<td><button type="button" class="button af-inventory-remove-row" aria-label="<?php esc_attr_e( 'Eliminar ítem', 'arriendo-facil' ); ?>">&#x2715;</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<button type="button" id="af-inventory-add-row" class="button button-secondary" style="margin-top:12px;">
				&#x2795; <?php esc_html_e( 'Agregar ítem', 'arriendo-facil' ); ?>
			</button>

			<input type="hidden" id="af_inventory" name="af_inventory" value="" />
		</div>
	</div>

	<!-- SECCION 5: Propietario -->
	<div class="af-accom-section">
		<div class="af-accom-section__header">
			<span class="af-accom-section__icon">&#x1F464;</span>
			<h3 class="af-accom-section__title"><?php esc_html_e( 'Propietario', 'arriendo-facil' ); ?></h3>
		</div>
		<div class="af-accom-section__body">
			<?php if ( $is_owner_user ) : ?>
				<input type="hidden" id="af_owner_id" name="af_owner_id" value="<?php echo esc_attr( get_current_user_id() ); ?>" />
				<div class="af-owner-auto-badge">
					<span class="af-owner-auto-badge__icon">&#x2705;</span>
					<span><?php esc_html_e( 'Esta propiedad quedar&aacute; vinculada a tu cuenta de propietario autom&aacute;ticamente.', 'arriendo-facil' ); ?></span>
				</div>
			<?php else : ?>
				<div class="af-field">
					<label class="af-field__label" for="af_owner_id"><?php esc_html_e( 'Seleccionar propietario', 'arriendo-facil' ); ?></label>
					<select id="af_owner_id" name="af_owner_id" class="af-input af-input--select">
						<option value="0"><?php esc_html_e( '&mdash; Seleccionar propietario &mdash;', 'arriendo-facil' ); ?></option>
						<?php foreach ( $owner_options as $owner_option ) : ?>
							<option value="<?php echo esc_attr( (string) $owner_option['id'] ); ?>" <?php selected( (int) $owner_id, (int) $owner_option['id'] ); ?>>
								<?php echo esc_html( (string) $owner_option['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- OTA INTEGRATIONS SECTION -->
	<?php include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/accommodation-ota-section.php'; ?>

</div><!-- /.af-accom-form -->

<script>
(function () {
	// Stepper buttons
	document.querySelectorAll('.af-stepper').forEach(function (stepper) {
		var input = stepper.querySelector('.af-stepper__input');
		stepper.querySelector('.af-stepper__btn--minus').addEventListener('click', function () {
			var val = parseInt(input.value, 10) || 0;
			var min = parseInt(input.min || 0, 10);
			if (val > min) {
				input.value = val - 1;
			}
		});
		stepper.querySelector('.af-stepper__btn--plus').addEventListener('click', function () {
			var val = parseInt(input.value, 10) || 0;
			var max = parseInt(input.max || 99, 10);
			if (val < max) {
				input.value = val + 1;
			}
		});
	});

	// Property type card visual selection
	document.querySelectorAll('.af-prop-type-card input[type="radio"]').forEach(function (radio) {
		radio.addEventListener('change', function () {
			document.querySelectorAll('.af-prop-type-card').forEach(function (card) {
				card.classList.remove('is-selected');
			});
			if (this.checked) {
				this.closest('.af-prop-type-card').classList.add('is-selected');
			}
		});
	});

	// Status selector visual selection
	document.querySelectorAll('.af-status-option input[type="radio"]').forEach(function (radio) {
		radio.addEventListener('change', function () {
			document.querySelectorAll('.af-status-option').forEach(function (opt) {
				opt.classList.remove('is-selected');
			});
			if (this.checked) {
				this.closest('.af-status-option').classList.add('is-selected');
			}
		});
	});

	// Amenity chip toggle
	document.querySelectorAll('.af-amenity-chip input[type="checkbox"]').forEach(function (cb) {
		cb.addEventListener('change', function () {
			this.closest('.af-amenity-chip').classList.toggle('is-selected', this.checked);
		});
	});

	// Inventory: dynamic rows
	var inventoryBody   = document.getElementById('af-inventory-rows');
	var inventoryHidden = document.getElementById('af_inventory');
	var inventoryTemplate =
		'<tr class="af-inventory-row">' +
		'<td><input type="text" class="af-input af-inv-item" placeholder="<?php echo esc_js( __( 'Ej: Refrigeradora', 'arriendo-facil' ) ); ?>" /></td>' +
		'<td><select class="af-input af-inv-category">' +
		<?php foreach ( $inventory_categories as $cat_value => $cat_label ) : ?>
		'<option value="<?php echo esc_js( $cat_value ); ?>"><?php echo esc_js( $cat_label ); ?></option>' +
		<?php endforeach; ?>
		'</select></td>' +
		'<td><select class="af-input af-inv-condition">' +
		<?php foreach ( $condition_options as $c_value => $c_label ) : ?>
		'<option value="<?php echo esc_js( $c_value ); ?>"><?php echo esc_js( $c_label ); ?></option>' +
		<?php endforeach; ?>
		'</select></td>' +
		'<td><input type="number" class="af-input af-inv-quantity" min="1" max="99" value="1" /></td>' +
		'<td><button type="button" class="button af-inventory-remove-row">&#x2715;</button></td>' +
		'</tr>';

	function bindInventoryRemove(row) {
		row.querySelector('.af-inventory-remove-row').addEventListener('click', function () {
			row.remove();
		});
	}

	if (inventoryBody) {
		inventoryBody.querySelectorAll('.af-inventory-row').forEach(bindInventoryRemove);
	}

	var addInventoryBtn = document.getElementById('af-inventory-add-row');
	if (addInventoryBtn) {
		addInventoryBtn.addEventListener('click', function () {
			var wrapper = document.createElement('tbody');
			wrapper.innerHTML = inventoryTemplate;
			var newRow = wrapper.firstElementChild;
			inventoryBody.appendChild(newRow);
			bindInventoryRemove(newRow);
		});
	}

	var accommodationForm = document.getElementById('post');
	if (accommodationForm && inventoryHidden) {
		accommodationForm.addEventListener('submit', function () {
			var items = [];
			inventoryBody.querySelectorAll('.af-inventory-row').forEach(function (row) {
				var item = row.querySelector('.af-inv-item').value.trim();
				if ('' === item) {
					return;
				}
				items.push({
					item: item,
					category: row.querySelector('.af-inv-category').value,
					condition: row.querySelector('.af-inv-condition').value,
					quantity: parseInt(row.querySelector('.af-inv-quantity').value, 10) || 1
				});
			});
			inventoryHidden.value = JSON.stringify(items);
		});
	}
}());
</script>
