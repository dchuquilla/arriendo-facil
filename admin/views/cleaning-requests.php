<?php
/**
 * Cleaning requests admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$requests = $wpdb->get_results(
	"SELECT r.*, p.post_title AS accommodation_title
	 FROM {$wpdb->prefix}af_cleaning_requests r
	 LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
	 ORDER BY r.requested_date DESC
	 LIMIT 100"
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Cleaning Requests', 'arriendo-facil' ); ?></h1>

	<div class="af-cleaning-actions" style="margin-bottom: 16px;">
		<button type="button" class="button button-primary" id="af-new-cleaning-request">
			<?php esc_html_e( '+ New Cleaning Request', 'arriendo-facil' ); ?>
		</button>
	</div>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Accommodation', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Requested Date', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Completed Date', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Status', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Notes', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $requests ) : ?>
				<?php foreach ( $requests as $request ) : ?>
					<tr>
						<td><?php echo esc_html( $request->id ); ?></td>
						<td><?php echo esc_html( $request->accommodation_title ?: $request->accommodation_id ); ?></td>
						<td><?php echo esc_html( $request->requested_date ); ?></td>
						<td><?php echo esc_html( $request->completed_date ?: '—' ); ?></td>
						<td><?php echo esc_html( $request->status ); ?></td>
						<td><?php echo esc_html( $request->notes ); ?></td>
						<td>
							<?php if ( 'pending' === $request->status || 'in_progress' === $request->status ) : ?>
								<button type="button" class="button af-update-cleaning"
									data-request-id="<?php echo esc_attr( $request->id ); ?>"
									data-status="completed">
									<?php esc_html_e( 'Mark Complete', 'arriendo-facil' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No cleaning requests found.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
