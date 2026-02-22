<?php
/**
 * Accommodation meta box view.
 *
 * @package Arriendo_Facil
 * @var WP_Post $post         Current post object (in scope from render_meta_box).
 * @var string  $address      Current address meta value.
 * @var int     $bedrooms     Current bedrooms meta value.
 * @var int     $bathrooms    Current bathrooms meta value.
 * @var float   $monthly_rent Current monthly rent meta value.
 * @var int     $owner_id     Current owner user ID meta value.
 * @var string  $status       Current accommodation status meta value.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$statuses = array(
	'available'  => __( 'Available', 'arriendo-facil' ),
	'rented'     => __( 'Rented', 'arriendo-facil' ),
	'maintenance'=> __( 'Maintenance', 'arriendo-facil' ),
	'inactive'   => __( 'Inactive', 'arriendo-facil' ),
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
			<input type="number" id="af_owner_id" name="af_owner_id" min="0"
				value="<?php echo esc_attr( $owner_id ); ?>" class="small-text" />
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
