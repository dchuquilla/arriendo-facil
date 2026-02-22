<?php
/**
 * Leases admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$leases = $wpdb->get_results(
	"SELECT l.*, p.post_title AS accommodation_title
	 FROM {$wpdb->prefix}af_leases l
	 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
	 ORDER BY l.created_at DESC
	 LIMIT 100"
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Leases', 'arriendo-facil' ); ?></h1>

	<div class="af-lease-actions" style="margin-bottom: 16px;">
		<button type="button" class="button button-primary" id="af-new-lease">
			<?php esc_html_e( '+ New Lease', 'arriendo-facil' ); ?>
		</button>
	</div>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Accommodation', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Guest ID', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Start Date', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'End Date', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Monthly Rent', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Status', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Document', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $leases ) : ?>
				<?php foreach ( $leases as $lease ) : ?>
					<tr>
						<td><?php echo esc_html( $lease->id ); ?></td>
						<td><?php echo esc_html( $lease->accommodation_title ?: $lease->accommodation_id ); ?></td>
						<td><?php echo esc_html( $lease->guest_id ); ?></td>
						<td><?php echo esc_html( $lease->start_date ); ?></td>
						<td><?php echo esc_html( $lease->end_date ); ?></td>
						<td><?php echo esc_html( number_format( (float) $lease->monthly_rent, 2 ) ); ?></td>
						<td><?php echo esc_html( $lease->status ); ?></td>
						<td>
							<?php if ( $lease->document_url ) : ?>
								<a href="<?php echo esc_url( $lease->document_url ); ?>" target="_blank">
									<?php esc_html_e( 'View', 'arriendo-facil' ); ?>
								</a>
							<?php else : ?>
								<button type="button" class="button af-generate-document"
									data-lease-id="<?php echo esc_attr( $lease->id ); ?>">
									<?php esc_html_e( 'Generate (AI)', 'arriendo-facil' ); ?>
								</button>
							<?php endif; ?>
						</td>
						<td>
							<button type="button" class="button af-change-lease-status"
								data-lease-id="<?php echo esc_attr( $lease->id ); ?>"
								data-status="active">
								<?php esc_html_e( 'Activate', 'arriendo-facil' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="9"><?php esc_html_e( 'No leases found.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
