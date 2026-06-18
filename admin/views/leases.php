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

$is_owner = Arriendo_Facil_Accommodation::user_is_owner();

if ( $is_owner ) {
	$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
	if ( ! empty( $owner_ids ) ) {
		$ids_sql = implode( ',', array_map( 'intval', $owner_ids ) );
		$leases = $wpdb->get_results(
			"SELECT l.*, p.post_title AS accommodation_title
			 FROM {$wpdb->prefix}af_leases l
			 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
			 WHERE l.accommodation_id IN ($ids_sql) AND l.deleted_at IS NULL
			 ORDER BY l.created_at DESC
			 LIMIT 100"
		);
	} else {
		$leases = array();
	}
} else {
	$leases = $wpdb->get_results(
		"SELECT l.*, p.post_title AS accommodation_title
		 FROM {$wpdb->prefix}af_leases l
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 WHERE l.deleted_at IS NULL
		 ORDER BY l.created_at DESC
		 LIMIT 100"
	);
}

// ── Batch-fetch billing state for the CURRENT period per visible lease ──────
$billing_status_map    = array();
$billing_current_month = class_exists( 'Arriendo_Facil_Billing_Manager' )
	? Arriendo_Facil_Billing_Manager::billing_period()
	: gmdate( 'Y-m' );

if ( ! empty( $leases ) ) {
	$lease_ids = array_filter( array_map( function( $l ) { return (int) $l->id; }, (array) $leases ) );
	if ( ! empty( $lease_ids ) ) {
		$ids_sql = implode( ',', $lease_ids );
		// Current-period invoice (may be null → button shows).
		$billing_rows_period = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT lease_id, id AS invoice_id, estado
				 FROM {$wpdb->prefix}af_electronic_invoices
				 WHERE lease_id IN ($ids_sql)
				   AND billing_period = %s
				 ORDER BY id DESC",
				$billing_current_month
			)
		);
		foreach ( (array) $billing_rows_period as $br ) {
			$billing_status_map[ (int) $br->lease_id ] = array(
				'invoice_id' => (int) $br->invoice_id,
				'estado'     => (string) $br->estado,
				'period'     => $billing_current_month,
			);
		}

		// For leases with no current-period invoice, check if there's any invoice at all
		// (to show last state as reference without blocking the Emitir button).
		$no_current = array_diff(
			$lease_ids,
			array_keys( $billing_status_map )
		);
		if ( ! empty( $no_current ) ) {
			$no_ids_sql  = implode( ',', array_map( 'intval', $no_current ) );
			$prev_rows   = $wpdb->get_results(
				"SELECT b.lease_id, b.id AS invoice_id, b.estado, b.billing_period
				 FROM {$wpdb->prefix}af_electronic_invoices b
				 INNER JOIN (
				     SELECT lease_id, MAX(id) AS max_id
				     FROM {$wpdb->prefix}af_electronic_invoices
				     WHERE lease_id IN ($no_ids_sql)
				     GROUP BY lease_id
				 ) latest ON latest.max_id = b.id"
			);
			foreach ( (array) $prev_rows as $br ) {
				// Mark as previous period so the view knows to show the Emitir button too.
				$billing_status_map[ (int) $br->lease_id ] = array(
					'invoice_id'  => (int) $br->invoice_id,
					'estado'      => (string) $br->estado,
					'period'      => (string) $br->billing_period,
					'is_previous' => true,
				);
			}
		}
	}
}

$billing_nonce = wp_create_nonce( 'af_billing_nonce' );
$can_bill      = current_user_can( (string) apply_filters( 'af_billing_capability', 'manage_options' ) );
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
			<th><?php esc_html_e( 'Factura', 'arriendo-facil' ); ?></th>
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
						<td><?php echo esc_html( $lease->status ); ?></td>					<?php
					$binfo  = isset( $billing_status_map[ (int) $lease->id ] ) ? $billing_status_map[ (int) $lease->id ] : null;
					$estado_colores = array(
						'autorizada'          => array( 'label' => 'Autorizada', 'color' => '#2e7d32' ),
						'autorizada_sin_ride' => array( 'label' => 'Autorizada', 'color' => '#2e7d32' ),
						'firmada'             => array( 'label' => 'Firmada',    'color' => '#1565c0' ),
						'enviada'             => array( 'label' => 'Enviada',    'color' => '#1565c0' ),
						'generada'            => array( 'label' => 'Generada',   'color' => '#555' ),
						'devuelta'            => array( 'label' => 'Devuelta',   'color' => '#c62828' ),
						'error_envio'         => array( 'label' => 'Error',      'color' => '#c62828' ),
						'error_autorizacion'  => array( 'label' => 'Error',      'color' => '#c62828' ),
						'no_autorizada'       => array( 'label' => 'No Aut.',    'color' => '#c62828' ),
						'rechazada'           => array( 'label' => 'Rechazada',  'color' => '#c62828' ),
						'anulada'             => array( 'label' => 'Anulada',    'color' => '#e65100' ),
					);
					?>
					<td class="af-lease-billing-cell" id="af-billing-cell-<?php echo esc_attr( $lease->id ); ?>">
						<?php
						$is_current_period = $binfo && empty( $binfo['is_previous'] );
						$is_prev_period    = $binfo && ! empty( $binfo['is_previous'] );
						?>

						<?php if ( $binfo ) : ?>
							<?php
							$ei      = isset( $estado_colores[ $binfo['estado'] ] ) ? $estado_colores[ $binfo['estado'] ] : array( 'label' => esc_html( $binfo['estado'] ), 'color' => '#555' );
							$period_label = isset( $binfo['period'] ) && $binfo['period'] ? $binfo['period'] : '';
							?>
							<span style="font-weight:600; color:<?php echo esc_attr( $ei['color'] ); ?>; font-size:12px;">
								<?php echo esc_html( $ei['label'] ); ?>
							</span>
							<?php if ( $period_label ) : ?>
								<span style="color:#888; font-size:11px;"> (<?php echo esc_html( $period_label ); ?>)</span>
							<?php endif; ?><br>
							<small style="color:#888;">#<?php echo esc_html( $binfo['invoice_id'] ); ?></small>
						<?php endif; ?>

						<?php if ( ( ! $binfo || $is_prev_period ) && $can_bill && 'active' === (string) $lease->status ) : ?>
							<br>
							<button type="button"
								class="button button-small af-lease-issue-invoice"
								data-lease-id="<?php echo esc_attr( $lease->id ); ?>"
								data-nonce="<?php echo esc_attr( $billing_nonce ); ?>">
								<?php echo esc_html( sprintf( __( 'Emitir %s', 'arriendo-facil' ), $billing_current_month ) ); ?>
							</button>
						<?php elseif ( ! $binfo && ! $can_bill ) : ?>
							<span style="color:#aaa; font-size:12px;">&mdash;</span>
						<?php endif; ?>
					</td>					<td class="af-lease-document-cell">
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
					<td colspan="10"><?php esc_html_e( 'No leases found.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<div id="af-invoice-preview-modal" class="af-modal af-invoice-preview-modal" hidden>
		<div class="af-modal__backdrop" data-af-close-invoice-preview-modal></div>
		<div class="af-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-invoice-preview-modal-title">
			<div class="af-modal__header">
				<h2 id="af-invoice-preview-modal-title"><?php esc_html_e( 'Preview de Factura', 'arriendo-facil' ); ?></h2>
				<button type="button" class="af-modal__close" data-af-close-invoice-preview-modal aria-label="<?php esc_attr_e( 'Close', 'arriendo-facil' ); ?>">&times;</button>
			</div>
			<div class="af-modal__body">
				<p id="af-invoice-preview-summary" style="margin-top:0; color:#555;"></p>

				<div id="af-invoice-preview-warning" style="display:none; margin:8px 0; padding:10px; border-radius:6px; background:#fff4e5; color:#7a4b00; border:1px solid #f3d2a2;"></div>

				<div style="display:grid; grid-template-columns:repeat(2,minmax(220px,1fr)); gap:10px; margin-bottom:10px;">
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Descripcion', 'arriendo-facil' ); ?></span>
						<input type="text" id="af-preview-descripcion" class="regular-text" style="width:100%;" />
					</label>
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Precio Unitario', 'arriendo-facil' ); ?></span>
						<input type="number" id="af-preview-precio" min="0" step="0.01" style="width:100%;" />
					</label>
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Cantidad', 'arriendo-facil' ); ?></span>
						<input type="number" id="af-preview-cantidad" min="0.01" step="0.01" style="width:100%;" />
					</label>
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Descuento', 'arriendo-facil' ); ?></span>
						<input type="number" id="af-preview-descuento" min="0" step="0.01" style="width:100%;" />
					</label>
					<label style="grid-column:1 / -1;">
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Email (info adicional)', 'arriendo-facil' ); ?></span>
						<input type="email" id="af-preview-email" style="width:100%;" />
					</label>
				</div>

				<div style="display:grid; grid-template-columns:repeat(2,minmax(220px,1fr)); gap:8px; margin-bottom:10px;">
					<div><?php esc_html_e( 'Comprador:', 'arriendo-facil' ); ?> <strong id="af-preview-buyer-name">-</strong></div>
					<div><?php esc_html_e( 'Identificacion:', 'arriendo-facil' ); ?> <strong id="af-preview-buyer-id">-</strong></div>
					<div><?php esc_html_e( 'Subtotal:', 'arriendo-facil' ); ?> <strong id="af-preview-subtotal">$0.00</strong></div>
					<div><?php esc_html_e( 'IVA:', 'arriendo-facil' ); ?> <strong id="af-preview-iva">$0.00</strong></div>
					<div style="grid-column:1 / -1;"><?php esc_html_e( 'Total:', 'arriendo-facil' ); ?> <strong id="af-preview-total">$0.00</strong></div>
				</div>

				<p id="af-invoice-preview-feedback" aria-live="polite" style="min-height:20px; margin:0 0 10px; color:#555;"></p>

				<div class="af-lease-upload-actions">
					<button type="button" class="button" data-af-close-invoice-preview-modal><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button type="button" class="button" id="af-preview-recalculate"><?php esc_html_e( 'Actualizar Preview', 'arriendo-facil' ); ?></button>
					<button type="button" class="button button-primary" id="af-preview-approve-issue"><?php esc_html_e( 'Aprobar y Emitir', 'arriendo-facil' ); ?></button>
				</div>
			</div>
		</div>
	</div>

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

<script>
(function () {
	var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
	var modal = document.getElementById( 'af-invoice-preview-modal' );
	var summary = document.getElementById( 'af-invoice-preview-summary' );
	var warningEl = document.getElementById( 'af-invoice-preview-warning' );
	var feedback = document.getElementById( 'af-invoice-preview-feedback' );
	var btnRecalc = document.getElementById( 'af-preview-recalculate' );
	var btnApprove = document.getElementById( 'af-preview-approve-issue' );
	var inputDesc = document.getElementById( 'af-preview-descripcion' );
	var inputPrecio = document.getElementById( 'af-preview-precio' );
	var inputCantidad = document.getElementById( 'af-preview-cantidad' );
	var inputDescuento = document.getElementById( 'af-preview-descuento' );
	var inputEmail = document.getElementById( 'af-preview-email' );
	var buyerName = document.getElementById( 'af-preview-buyer-name' );
	var buyerId = document.getElementById( 'af-preview-buyer-id' );
	var subtotalEl = document.getElementById( 'af-preview-subtotal' );
	var ivaEl = document.getElementById( 'af-preview-iva' );
	var totalEl = document.getElementById( 'af-preview-total' );

	var state = {
		leaseId: 0,
		nonce: '',
		triggerBtn: null,
		canIssue: true,
	};

	if ( ! modal ) {
		return;
	}

	function money( value ) {
		return '$' + Number( value || 0 ).toFixed( 2 );
	}

	function collectOverrides() {
		return {
			descripcion: inputDesc.value.trim(),
			precio_unitario: Number( inputPrecio.value || 0 ),
			cantidad: Number( inputCantidad.value || 0 ),
			descuento: Number( inputDescuento.value || 0 ),
			email: inputEmail.value.trim(),
		};
	}

	function applyPreviewData( data ) {
		var item = ( data && data.item ) ? data.item : {};
		var buyer = ( data && data.buyer ) ? data.buyer : {};
		var totals = ( data && data.totals ) ? data.totals : {};

		if ( item.descripcion !== undefined ) inputDesc.value = item.descripcion;
		if ( item.precio_unitario !== undefined ) inputPrecio.value = Number( item.precio_unitario ).toFixed( 2 );
		if ( item.cantidad !== undefined ) inputCantidad.value = Number( item.cantidad ).toFixed( 2 );
		if ( item.descuento !== undefined ) inputDescuento.value = Number( item.descuento ).toFixed( 2 );
		if ( data && data.info_adicional && data.info_adicional.email !== undefined ) inputEmail.value = data.info_adicional.email || '';

		buyerName.textContent = buyer.name || '-';
		buyerId.textContent = buyer.identification || '-';
		subtotalEl.textContent = money( totals.total_sin_impuestos );
		ivaEl.textContent = money( totals.iva_valor );
		totalEl.textContent = money( totals.importe_total );

		state.canIssue = !!( data && data.can_issue );
		warningEl.style.display = ( data && data.warning ) ? 'block' : 'none';
		warningEl.textContent = ( data && data.warning ) ? data.warning : '';
		btnApprove.disabled = ! state.canIssue;
	}

	function setFeedback( text, isError ) {
		feedback.textContent = text || '';
		feedback.style.color = isError ? '#c62828' : '#555';
	}

	function closeModal() {
		modal.setAttribute( 'hidden', 'hidden' );
		document.body.classList.remove( 'af-modal-open' );
		setFeedback( '' );
		warningEl.style.display = 'none';
		warningEl.textContent = '';
		state.leaseId = 0;
		state.nonce = '';
		state.triggerBtn = null;
	}

	function openModal() {
		modal.removeAttribute( 'hidden' );
		document.body.classList.add( 'af-modal-open' );
	}

	function fetchPreview( overrides ) {
		setFeedback( '<?php echo esc_js( __( 'Calculando preview...', 'arriendo-facil' ) ); ?>' );
		btnRecalc.disabled = true;
		btnApprove.disabled = true;

		var fd = new FormData();
		fd.append( 'action', 'af_preview_invoice' );
		fd.append( 'lease_id', String( state.leaseId ) );
		fd.append( 'nonce', state.nonce );
		fd.append( 'overrides', JSON.stringify( overrides || {} ) );

		return fetch( ajaxUrl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( resp ) {
			if ( ! resp.success ) {
				throw new Error( ( resp.data && resp.data.message ) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo obtener el preview.', 'arriendo-facil' ) ); ?>' );
			}

			applyPreviewData( resp.data );
			summary.textContent = '<?php echo esc_js( __( 'Periodo:', 'arriendo-facil' ) ); ?> ' + ( resp.data.billing_period || '-' );
			setFeedback( state.canIssue ? '<?php echo esc_js( __( 'Revisa los datos y aprueba para emitir.', 'arriendo-facil' ) ); ?>' : '<?php echo esc_js( __( 'No se puede emitir en este periodo.', 'arriendo-facil' ) ); ?>', ! state.canIssue );
		} )
		.catch( function ( err ) {
			setFeedback( err.message || '<?php echo esc_js( __( 'Error de red.', 'arriendo-facil' ) ); ?>', true );
		} )
		.finally( function () {
			btnRecalc.disabled = false;
			btnApprove.disabled = ! state.canIssue;
		} );
	}

	function issueWithOverrides() {
		var overrides = collectOverrides();
		btnApprove.disabled = true;
		setFeedback( '<?php echo esc_js( __( 'Emitiendo comprobante...', 'arriendo-facil' ) ); ?>' );

		var fd = new FormData();
		fd.append( 'action', 'af_issue_invoice' );
		fd.append( 'lease_id', String( state.leaseId ) );
		fd.append( 'nonce', state.nonce );
		fd.append( 'overrides', JSON.stringify( overrides ) );

		fetch( ajaxUrl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( resp ) {
			if ( ! resp.success ) {
				throw new Error( ( resp.data && resp.data.message ) ? resp.data.message : '<?php echo esc_js( __( 'Error al emitir.', 'arriendo-facil' ) ); ?>' );
			}

			var cell = document.getElementById( 'af-billing-cell-' + state.leaseId );
			if ( cell ) {
				var estado = ( resp.data && resp.data.estado ) ? resp.data.estado : 'generada';
				var color = ( 'autorizada' === estado || 'autorizada_sin_ride' === estado ) ? '#2e7d32'
					: ( 'error_envio' === estado || 'error_autorizacion' === estado ? '#c62828' : '#1565c0' );
				var invId = ( resp.data && resp.data.invoice_id ) ? '#' + resp.data.invoice_id : '';
				var period = ( resp.data && resp.data.billing_period ) ? ' (' + resp.data.billing_period + ')' : '';
				cell.innerHTML = '<span style="font-weight:600;color:' + color + ';font-size:12px;">' + estado + period + '</span><br><small style="color:#888;">' + invId + '</small>';
			}

			if ( state.triggerBtn ) {
				state.triggerBtn.remove();
			}

			closeModal();
		} )
		.catch( function ( err ) {
			setFeedback( err.message || '<?php echo esc_js( __( 'Error de red.', 'arriendo-facil' ) ); ?>', true );
			btnApprove.disabled = ! state.canIssue;
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.af-lease-issue-invoice' );
		if ( btn ) {
			state.leaseId = parseInt( btn.dataset.leaseId || '0', 10 );
			state.nonce = btn.dataset.nonce || '';
			state.triggerBtn = btn;
			if ( ! state.leaseId || ! state.nonce ) {
				return;
			}

			openModal();
			fetchPreview( {} );
			return;
		}

		if ( e.target.closest( '[data-af-close-invoice-preview-modal]' ) ) {
			closeModal();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && ! modal.hasAttribute( 'hidden' ) ) {
			closeModal();
		}
	} );

	btnRecalc.addEventListener( 'click', function () {
		fetchPreview( collectOverrides() );
	} );

	btnApprove.addEventListener( 'click', function () {
		if ( ! state.canIssue ) {
			return;
		}
		issueWithOverrides();
	} );
}());
</script>
