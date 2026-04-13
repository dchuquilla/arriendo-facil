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

$lease_service = class_exists( 'Arriendo_Facil_Lease' ) ? new Arriendo_Facil_Lease() : null;

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

	<div class="af-lease-actions">
		<button type="button" class="button button-primary" id="af-new-lease">
			<?php esc_html_e( '+ New Lease', 'arriendo-facil' ); ?>
		</button>
	</div>

	<table class="wp-list-table widefat fixed striped af-leases-table">
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
					<?php
					$versions_data   = $lease_service ? $lease_service->get_contract_versions( (int) $lease->id ) : array( 'active_version' => 0, 'versions' => array() );
					$active_version  = isset( $versions_data['active_version'] ) ? (int) $versions_data['active_version'] : 0;
					$versions        = isset( $versions_data['versions'] ) && is_array( $versions_data['versions'] ) ? $versions_data['versions'] : array();
					$versions_count  = count( $versions );
					$download_active = add_query_arg(
						array(
							'action'   => 'af_download_lease_contract',
							'lease_id' => (int) $lease->id,
						),
						admin_url( 'admin-ajax.php' )
					);
					?>
					<tr class="af-lease-row">
						<td><?php echo esc_html( $lease->id ); ?></td>
						<td><?php echo esc_html( $lease->accommodation_title ?: $lease->accommodation_id ); ?></td>
						<td><?php echo esc_html( $lease->guest_id ); ?></td>
						<td><?php echo esc_html( $lease->start_date ); ?></td>
						<td><?php echo esc_html( $lease->end_date ); ?></td>
						<td><?php echo esc_html( number_format( (float) $lease->monthly_rent, 2 ) ); ?></td>
						<td><?php echo esc_html( $lease->status ); ?></td>
						<td class="af-lease-document-cell">
							<?php if ( $versions_count > 0 || $lease->document_url ) : ?>
								<a class="af-lease-view-link" href="<?php echo esc_url( $download_active ); ?>" target="_blank">
									<?php esc_html_e( 'View', 'arriendo-facil' ); ?>
								</a>
								<?php if ( $versions_count > 0 ) : ?>
									<div class="af-lease-version-meta">
										<?php echo esc_html( sprintf( __( 'Active version: v%d (%d total)', 'arriendo-facil' ), max( 1, $active_version ), $versions_count ) ); ?>
									</div>
								<?php endif; ?>
							<?php else : ?>
								<span class="af-lease-empty-document"><?php esc_html_e( 'No contract yet. It is generated from chatbot flow.', 'arriendo-facil' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="af-lease-actions-cell">
							<form class="af-upload-lease-version-form" data-lease-id="<?php echo esc_attr( $lease->id ); ?>" enctype="multipart/form-data">
								<input type="file" name="lease_contract_file" accept=".doc,.docx" required />
								<button type="submit" class="button button-secondary"><?php esc_html_e( 'Upload New Version', 'arriendo-facil' ); ?></button>
							</form>
							<button type="button" class="button af-change-lease-status af-lease-activate-button"
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
