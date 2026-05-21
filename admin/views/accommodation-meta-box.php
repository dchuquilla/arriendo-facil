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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$statuses = array(
	'available'   => __( 'Available', 'arriendo-facil' ),
	'rented'      => __( 'Rented', 'arriendo-facil' ),
	'maintenance' => __( 'Maintenance', 'arriendo-facil' ),
	'inactive'    => __( 'Inactive', 'arriendo-facil' ),
);

$property_types = array(
	'apartment'  => __( 'Apartment', 'arriendo-facil' ),
	'house'      => __( 'House', 'arriendo-facil' ),
	'office'     => __( 'Office', 'arriendo-facil' ),
	'room'       => __( 'Room', 'arriendo-facil' ),
	'commercial' => __( 'Commercial', 'arriendo-facil' ),
);

$amenities_options = array(
	'pet-friendly' => __( 'Pet Friendly', 'arriendo-facil' ),
	'wifi'         => __( 'WiFi', 'arriendo-facil' ),
	'parking'      => __( 'Parking', 'arriendo-facil' ),
	'pool'         => __( 'Pool', 'arriendo-facil' ),
	'gym'          => __( 'Gym', 'arriendo-facil' ),
	'kitchen'      => __( 'Kitchen', 'arriendo-facil' ),
	'balcony'      => __( 'Balcony', 'arriendo-facil' ),
	'ac'           => __( 'Air Conditioning', 'arriendo-facil' ),
);
?>
<table class="form-table">
	<tr>
		<th><label for="af_address"><?php esc_html_e( 'Address', 'arriendo-facil' ); ?></label></th>
		<td>
			<input type="text" id="af_address" name="af_address"
				value="<?php echo esc_attr( $address ); ?>" class="regular-text" />
		</td>
	</tr>
	<tr>
		<th><label for="af_location_text"><?php esc_html_e( 'Location (City/Neighborhood)', 'arriendo-facil' ); ?></label></th>
		<td>
			<input type="text" id="af_location_text" name="af_location_text"
				value="<?php echo esc_attr( $location_text ); ?>" class="regular-text"
				placeholder="<?php esc_attr_e( 'e.g., Quito, La Carolina', 'arriendo-facil' ); ?>" />
			<p class="description"><?php esc_html_e( 'Used for search by location', 'arriendo-facil' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label for="af_city"><?php esc_html_e( 'City', 'arriendo-facil' ); ?></label></th>
		<td>
			<input type="text" id="af_city" name="af_city"
				value="<?php echo esc_attr( $city ); ?>" class="regular-text"
				placeholder="<?php esc_attr_e( 'e.g., Quito', 'arriendo-facil' ); ?>" />
			<p class="description"><?php esc_html_e( 'City name used in the rental contract', 'arriendo-facil' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label for="af_location_search"><?php esc_html_e( 'Location', 'arriendo-facil' ); ?></label></th>
		<td>
			<div class="af-location-picker">
				<div class="af-location-search-row">
					<input type="text" id="af_location_search" autocomplete="off"
						placeholder="<?php esc_attr_e( 'Search address or paste Google Maps URL…', 'arriendo-facil' ); ?>" />
					<button type="button" id="af_location_search_btn" class="button"><?php esc_html_e( 'Search', 'arriendo-facil' ); ?></button>
				</div>
				<div id="af-location-suggestions" class="af-location-suggestions"></div>
				<div id="af-location-map" style="height: 300px; width: 100%; margin-top: 10px;" tabindex="-1"></div>
				<input type="hidden" id="af_latitude" name="af_latitude"
					value="<?php echo esc_attr( $latitude ); ?>" />
				<input type="hidden" id="af_longitude" name="af_longitude"
					value="<?php echo esc_attr( $longitude ); ?>" />
			</div>
		</td>
	</tr>
	<tr>
		<th><label for="af_property_type"><?php esc_html_e( 'Property Type', 'arriendo-facil' ); ?></label></th>
		<td>
			<select id="af_property_type" name="af_property_type">
				<option value=""><?php esc_html_e( 'Select type', 'arriendo-facil' ); ?></option>
				<?php foreach ( $property_types as $type_value => $type_label ) : ?>
					<option value="<?php echo esc_attr( $type_value ); ?>" <?php selected( $property_type, $type_value ); ?>>
						<?php echo esc_html( $type_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<tr>
		<th><label for="af_bedrooms"><?php esc_html_e( 'Bedrooms', 'arriendo-facil' ); ?></label></th>
		<td>
			<input type="number" id="af_bedrooms" name="af_bedrooms" min="0"
				value="<?php echo esc_attr( $bedrooms ); ?>" class="small-text" />
		</td>
	</tr>
	<tr>
		<th><label for="af_bathrooms"><?php esc_html_e( 'Bathrooms', 'arriendo-facil' ); ?></label></th>
		<td>
			<input type="number" id="af_bathrooms" name="af_bathrooms" min="0"
				value="<?php echo esc_attr( $bathrooms ); ?>" class="small-text" />
		</td>
	</tr>
	<tr>
		<th><label for="af_square_meters"><?php esc_html_e( 'Square Meters', 'arriendo-facil' ); ?></label></th>
		<td>
			<input type="number" id="af_square_meters" name="af_square_meters" step="0.01" min="0"
				value="<?php echo esc_attr( $square_meters ); ?>" class="small-text" />
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Amenities', 'arriendo-facil' ); ?></label></th>
		<td>
			<fieldset>
				<?php foreach ( $amenities_options as $amenity_value => $amenity_label ) : ?>
					<label>
						<input type="checkbox" name="af_amenities[]"
							value="<?php echo esc_attr( $amenity_value ); ?>"
							<?php checked( in_array( $amenity_value, $amenities, true ) ); ?> />
						<?php echo esc_html( $amenity_label ); ?>
					</label><br />
				<?php endforeach; ?>
			</fieldset>
		</td>
	</tr>
	<tr>
		<th><label for="af_monthly_rent"><?php esc_html_e( 'Monthly Rent', 'arriendo-facil' ); ?></label></th>
		<td>
			<input type="number" id="af_monthly_rent" name="af_monthly_rent" step="0.01" min="0"
				value="<?php echo esc_attr( $monthly_rent ); ?>" class="regular-text" />
			<button type="button" class="button af-predict-cost"
				data-id="<?php echo esc_attr( $post->ID ); ?>">
				<?php esc_html_e( 'Predict Cost (AI)', 'arriendo-facil' ); ?>
			</button>
			<span class="af-predict-result"></span>
		</td>
	</tr>
	<tr>
		<th><label for="af_owner_id"><?php esc_html_e( 'Owner (User ID)', 'arriendo-facil' ); ?></label></th>
		<td>
			<?php if ( $is_owner_user ) : ?>
				<input type="hidden" id="af_owner_id" name="af_owner_id" value="<?php echo esc_attr( get_current_user_id() ); ?>" />
				<p><?php esc_html_e( 'This accommodation will be linked to your owner account automatically.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<select id="af_owner_id" name="af_owner_id" class="regular-text">
					<option value="0"><?php esc_html_e( 'Select owner', 'arriendo-facil' ); ?></option>
					<?php foreach ( $owner_options as $owner_option ) : ?>
						<option value="<?php echo esc_attr( (string) $owner_option['id'] ); ?>" <?php selected( (int) $owner_id, (int) $owner_option['id'] ); ?>>
							<?php echo esc_html( (string) $owner_option['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th><label for="af_status"><?php esc_html_e( 'Status', 'arriendo-facil' ); ?></label></th>
		<td>
			<select id="af_status" name="af_status">
				<?php foreach ( $statuses as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"
						<?php selected( $status, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
</table>
