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
					if ( $lease_service && empty( $lease->document_url ) ) {
						$saved_accommodation_title = isset( $lease->accommodation_title ) ? $lease->accommodation_title : null;
						$lease_service->ensure_lease_document_available( (int) $lease->id );
						$refreshed_lease = $lease_service->get_lease( (int) $lease->id );
						if ( $refreshed_lease ) {
							$lease = $refreshed_lease;
							if ( null !== $saved_accommodation_title ) {
								$lease->accommodation_title = $saved_accommodation_title;
							} else {
								$lease->accommodation_title = isset( $lease->accommodation_id ) ? get_the_title( (int) $lease->accommodation_id ) : '';
							}
						}
					}

					$versions_data   = $lease_service ? $lease_service->get_contract_versions( (int) $lease->id ) : array( 'active_version' => 0, 'versions' => array() );
					$active_version  = isset( $versions_data['active_version'] ) ? (int) $versions_data['active_version'] : 0;
					$versions        = isset( $versions_data['versions'] ) && is_array( $versions_data['versions'] ) ? $versions_data['versions'] : array();
					$versions_count  = count( $versions );
					$next_version    = $versions_count + 1;
					$active_entry    = null;
					if ( $versions_count > 0 ) {
						foreach ( $versions as $version_row ) {
							if ( isset( $version_row['version'] ) && (int) $version_row['version'] === max( 1, $active_version ) ) {
								$active_entry = $version_row;
								break;
							}
						}

						if ( ! is_array( $active_entry ) ) {
							$active_entry = end( $versions );
						}
					}
					$has_approved_pdf = is_array( $active_entry ) && isset( $active_entry['approved_pdf'] ) && is_array( $active_entry['approved_pdf'] ) && ! empty( $active_entry['approved_pdf']['file_name'] );
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
							<td><?php echo esc_html( ( isset( $lease->accommodation_title ) ? $lease->accommodation_title : null ) ?: ( isset( $lease->accommodation_id ) ? get_the_title( (int) $lease->accommodation_id ) : '' ) ?: $lease->accommodation_id ); ?></td>
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
									<?php if ( $has_approved_pdf ) : ?>
										<div class="af-lease-version-meta">
											<?php esc_html_e( 'Security: Approved PDF active (read/print only).', 'arriendo-facil' ); ?>
										</div>
									<?php endif; ?>
								<?php endif; ?>
							<?php else : ?>
								<span class="af-lease-empty-document"><?php esc_html_e( 'No contract yet. It is generated from chatbot flow.', 'arriendo-facil' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="af-lease-actions-cell">
							<div class="af-lease-actions-stack">
								<button type="button" class="button button-secondary af-open-upload-version-modal"
									data-lease-id="<?php echo esc_attr( $lease->id ); ?>"
									data-next-version="<?php echo esc_attr( $next_version ); ?>">
									<?php echo esc_html( sprintf( __( 'Upload v%d', 'arriendo-facil' ), $next_version ) ); ?>
								</button>
								<?php if ( $versions_count > 0 || $lease->document_url ) : ?>
									<button type="button" class="button button-primary af-approve-lease-document"
										data-lease-id="<?php echo esc_attr( $lease->id ); ?>"
										data-active-version="<?php echo esc_attr( max( 1, $active_version ) ); ?>"
										<?php disabled( $has_approved_pdf ); ?>>
										<?php echo esc_html( $has_approved_pdf ? __( 'Document Approved', 'arriendo-facil' ) : __( 'Approve Document', 'arriendo-facil' ) ); ?>
									</button>
								<?php endif; ?>
							<button type="button" class="button af-change-lease-status af-lease-activate-button"
								data-lease-id="<?php echo esc_attr( $lease->id ); ?>"
								data-status="active">
								<?php esc_html_e( 'Activate', 'arriendo-facil' ); ?>
							</button>
							</div>
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

	<div id="af-lease-upload-modal" class="af-modal af-lease-upload-modal" hidden>
		<div class="af-modal__backdrop" data-af-close-upload-modal></div>
		<div class="af-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-lease-upload-modal-title">
			<div class="af-modal__header">
				<h2 id="af-lease-upload-modal-title"><?php esc_html_e( 'Upload New Contract Version', 'arriendo-facil' ); ?></h2>
				<button type="button" class="af-modal__close" data-af-close-upload-modal aria-label="<?php esc_attr_e( 'Close', 'arriendo-facil' ); ?>">&times;</button>
			</div>
			<div class="af-modal__body">
				<p class="af-lease-upload-help"><?php esc_html_e( 'Upload your edited Word file as the next version for this lease.', 'arriendo-facil' ); ?></p>
				<p class="af-lease-upload-rules"><?php esc_html_e( 'Allowed: .doc, .docx | Max: 12 MB', 'arriendo-facil' ); ?></p>

				<div class="af-lease-upload-picker">
					<input type="file" id="af-lease-upload-file" accept=".doc,.docx" hidden />
					<button type="button" class="button" id="af-lease-upload-select-btn"><?php esc_html_e( 'Select Word File', 'arriendo-facil' ); ?></button>
					<span id="af-lease-upload-file-name" class="af-lease-upload-file-name"><?php esc_html_e( 'No file selected.', 'arriendo-facil' ); ?></span>
				</div>

				<p id="af-lease-upload-feedback" class="af-lease-upload-feedback" aria-live="polite"></p>

				<div class="af-lease-upload-actions">
					<button type="button" class="button" data-af-close-upload-modal><?php esc_html_e( 'Cancel', 'arriendo-facil' ); ?></button>
					<button type="button" class="button button-primary" id="af-lease-upload-submit" disabled><?php esc_html_e( 'Upload Version', 'arriendo-facil' ); ?></button>
				</div>
			</div>
		</div>
	</div>
</div>
