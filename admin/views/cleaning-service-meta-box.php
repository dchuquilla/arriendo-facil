<?php
/**
 * Cleaning service meta box view.
 *
 * @package Arriendo_Facil
 * @var float  $price_per_hour Current price per hour meta value.
 * @var float  $duration_hours Current duration meta value.
 * @var string $service_type   Current service type meta value.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$service_types = array(
	'standard'  => __( 'Standard', 'arriendo-facil' ),
	'deep'      => __( 'Deep Clean', 'arriendo-facil' ),
	'move_in'   => __( 'Move-In', 'arriendo-facil' ),
	'move_out'  => __( 'Move-Out', 'arriendo-facil' ),
	'post_stay' => __( 'Post-Stay', 'arriendo-facil' ),
);
?>
<table class="form-table">
	<tr>
		<th><label for="af_service_type"><?php esc_html_e( 'Service Type', 'arriendo-facil' ); ?></label></th>
		<td>
			<select id="af_service_type" name="af_service_type">
				<?php foreach ( $service_types as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"
						<?php selected( $service_type, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<tr>
		<th><label for="af_price_per_hour"><?php esc_html_e( 'Price per Hour', 'arriendo-facil' ); ?></label></th>
		<td>
			<input type="number" id="af_price_per_hour" name="af_price_per_hour"
				step="0.01" min="0"
				value="<?php echo esc_attr( $price_per_hour ); ?>" class="regular-text" />
		</td>
	</tr>
	<tr>
		<th><label for="af_duration_hours"><?php esc_html_e( 'Duration (hours)', 'arriendo-facil' ); ?></label></th>
		<td>
			<input type="number" id="af_duration_hours" name="af_duration_hours"
				step="0.5" min="0"
				value="<?php echo esc_attr( $duration_hours ); ?>" class="regular-text" />
		</td>
	</tr>
</table>
