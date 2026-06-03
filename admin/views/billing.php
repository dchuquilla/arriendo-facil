<?php
/**
 * Facturación Electrónica – listado de comprobantes.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'edit_posts' ) ) {
	wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'arriendo-facil' ) );
}

global $wpdb;

$invoices = $wpdb->get_results(
	"SELECT ei.*, l.monthly_rent,
	        p.post_title AS accommodation_title
	 FROM {$wpdb->prefix}af_electronic_invoices ei
	 LEFT JOIN {$wpdb->prefix}af_leases l ON l.id = ei.lease_id
	 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
	 ORDER BY ei.created_at DESC
	 LIMIT 200"
);

$cfg = Arriendo_Facil_SRI_Config::get();

$estado_labels = array(
	'generada'   => array( 'label' => __( 'Generada', 'arriendo-facil' ),   'color' => '#757575' ),
	'firmada'    => array( 'label' => __( 'Firmada', 'arriendo-facil' ),    'color' => '#455a64' ),
	'enviada'    => array( 'label' => __( 'Enviada', 'arriendo-facil' ),    'color' => '#1565c0' ),
	'autorizada' => array( 'label' => __( 'Autorizada', 'arriendo-facil' ), 'color' => '#2e7d32' ),
	'autorizada_sin_ride' => array( 'label' => __( 'Autorizada sin RIDE', 'arriendo-facil' ), 'color' => '#2e7d32' ),
	'error_envio' => array( 'label' => __( 'Error envio', 'arriendo-facil' ), 'color' => '#c62828' ),
	'error_autorizacion' => array( 'label' => __( 'Error autorizacion', 'arriendo-facil' ), 'color' => '#c62828' ),
	'devuelta'   => array( 'label' => __( 'Devuelta', 'arriendo-facil' ),   'color' => '#c62828' ),
	'no_autorizada' => array( 'label' => __( 'No autorizada', 'arriendo-facil' ), 'color' => '#c62828' ),
	'rechazada'  => array( 'label' => __( 'Rechazada', 'arriendo-facil' ),  'color' => '#c62828' ),
	'anulada'    => array( 'label' => __( 'Anulada', 'arriendo-facil' ),    'color' => '#e65100' ),
);

$last_action_message = '';
if ( isset( $_GET['af_billing_msg'] ) ) {
	$last_action_message = sanitize_text_field( wp_unslash( $_GET['af_billing_msg'] ) );
}

if ( isset( $_POST['af_issue_invoice_submit'] ) ) {
	check_admin_referer( 'af_billing_manual_issue' );
	$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
	if ( $lease_id > 0 ) {
		$manager = new Arriendo_Facil_Billing_Manager();
		$result  = $manager->issue_lease_invoice( $lease_id );
		if ( is_wp_error( $result ) ) {
			$last_action_message = __( 'Error al emitir:', 'arriendo-facil' ) . ' ' . $result->get_error_message();
		} else {
			$last_action_message = __( 'Comprobante emitido correctamente.', 'arriendo-facil' );
			$invoices = $wpdb->get_results(
				"SELECT ei.*, l.monthly_rent,
				        p.post_title AS accommodation_title
				 FROM {$wpdb->prefix}af_electronic_invoices ei
				 LEFT JOIN {$wpdb->prefix}af_leases l ON l.id = ei.lease_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 ORDER BY ei.created_at DESC
				 LIMIT 200"
			);
		}
	}
}

if ( isset( $_POST['af_retry_invoice_submit'] ) ) {
	check_admin_referer( 'af_billing_retry_invoice' );
	$invoice_id = isset( $_POST['invoice_id'] ) ? absint( wp_unslash( $_POST['invoice_id'] ) ) : 0;
	if ( $invoice_id > 0 ) {
		$manager = new Arriendo_Facil_Billing_Manager();
		$result  = $manager->retry_invoice( $invoice_id );
		if ( is_wp_error( $result ) ) {
			$last_action_message = __( 'Error al reintentar:', 'arriendo-facil' ) . ' ' . $result->get_error_message();
		} else {
			$last_action_message = __( 'Reintento ejecutado correctamente.', 'arriendo-facil' );
			$invoices = $wpdb->get_results(
				"SELECT ei.*, l.monthly_rent,
				        p.post_title AS accommodation_title
				 FROM {$wpdb->prefix}af_electronic_invoices ei
				 LEFT JOIN {$wpdb->prefix}af_leases l ON l.id = ei.lease_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 ORDER BY ei.created_at DESC
				 LIMIT 200"
			);
		}
	}
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Facturación Electrónica', 'arriendo-facil' ); ?></h1>

	<?php if ( '' !== $last_action_message ) : ?>
		<div class="notice notice-info is-dismissible" style="margin-top:12px;">
			<p><?php echo esc_html( $last_action_message ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( '2' === $cfg['ambiente'] ) : ?>
		<div class="notice notice-warning inline" style="margin-bottom:16px;">
			<p><strong><?php esc_html_e( 'Ambiente: PRODUCCIÓN', 'arriendo-facil' ); ?></strong></p>
		</div>
	<?php else : ?>
		<div class="notice notice-info inline" style="margin-bottom:16px;">
			<p><?php esc_html_e( 'Ambiente: PRUEBAS (certificación SRI)', 'arriendo-facil' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $cfg['ruc'] ) || empty( $cfg['cert_filename'] ) ) : ?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'Faltan datos de configuración SRI.', 'arriendo-facil' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-billing-settings' ) ); ?>">
					<?php esc_html_e( 'Ir a Configuración SRI', 'arriendo-facil' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<div style="margin: 16px 0 18px; padding: 14px 16px; background: #fff; border: 1px solid #dcdcde; border-radius: 4px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Emisión Manual por Lease', 'arriendo-facil' ); ?></h2>
		<form method="post" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
			<?php wp_nonce_field( 'af_billing_manual_issue' ); ?>
			<label for="af-billing-lease-id"><strong><?php esc_html_e( 'Lease ID', 'arriendo-facil' ); ?></strong></label>
			<input id="af-billing-lease-id" type="number" name="lease_id" min="1" required />
			<button type="submit" name="af_issue_invoice_submit" class="button button-primary">
				<?php esc_html_e( 'Emitir Comprobante', 'arriendo-facil' ); ?>
			</button>
		</form>
	</div>

	<?php if ( empty( $invoices ) ) : ?>
		<p style="margin-top:24px; color:#555;">
			<?php esc_html_e( 'Aún no se han emitido comprobantes electrónicos. Los comprobantes se generarán automáticamente al aprobar un contrato de arriendo.', 'arriendo-facil' ); ?>
		</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:60px;"><?php esc_html_e( '#', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Comprobante', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Total', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'IVA', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Estado SRI', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $invoices as $inv ) : ?>
					<?php
					$estado_info = isset( $estado_labels[ $inv->estado ] )
						? $estado_labels[ $inv->estado ]
						: array( 'label' => esc_html( $inv->estado ), 'color' => '#555' );
					?>
					<tr>
						<td><?php echo esc_html( $inv->id ); ?></td>
						<td>
							<?php if ( $inv->numero_comprobante ) : ?>
								<strong><?php echo esc_html( $inv->numero_comprobante ); ?></strong><br />
							<?php endif; ?>
							<?php if ( $inv->clave_acceso ) : ?>
								<small style="word-break:break-all; color:#555;">
									<?php echo esc_html( $inv->clave_acceso ); ?>
								</small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $inv->accommodation_title ?: '—' ); ?></td>
						<td>$ <?php echo esc_html( number_format( (float) $inv->total, 2 ) ); ?></td>
						<td>$ <?php echo esc_html( number_format( (float) $inv->iva_valor, 2 ) ); ?></td>
						<td>
							<span style="font-weight:600; color:<?php echo esc_attr( $estado_info['color'] ); ?>;">
								<?php echo esc_html( $estado_info['label'] ); ?>
							</span>
						</td>
						<td>
							<?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $inv->created_at ) ) ); ?>
						</td>
						<td>
							<?php if ( $inv->ride_path && file_exists( $inv->ride_path ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=af_download_ride&id=' . (int) $inv->id . '&nonce=' . wp_create_nonce( 'af_billing_nonce' ) ) ); ?>"
									class="button button-small">
									<?php esc_html_e( 'RIDE', 'arriendo-facil' ); ?>
								</a>
							<?php endif; ?>
							<?php if ( $inv->xml_autorizacion ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=af_download_xml&id=' . (int) $inv->id . '&nonce=' . wp_create_nonce( 'af_billing_nonce' ) ) ); ?>"
									class="button button-small">
									<?php esc_html_e( 'XML', 'arriendo-facil' ); ?>
								</a>
							<?php elseif ( $inv->xml_firmado ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=af_download_xml&id=' . (int) $inv->id . '&nonce=' . wp_create_nonce( 'af_billing_nonce' ) ) ); ?>"
									class="button button-small">
									<?php esc_html_e( 'XML Firmado', 'arriendo-facil' ); ?>
								</a>
							<?php endif; ?>

							<?php if ( in_array( (string) $inv->estado, array( 'error_envio', 'error_autorizacion', 'devuelta', 'no_autorizada', 'autorizada_sin_ride' ), true ) ) : ?>
								<form method="post" style="display:inline-block; margin-left:4px;">
									<?php wp_nonce_field( 'af_billing_retry_invoice' ); ?>
									<input type="hidden" name="invoice_id" value="<?php echo (int) $inv->id; ?>" />
									<button type="submit" name="af_retry_invoice_submit" class="button button-small">
										<?php esc_html_e( 'Reintentar', 'arriendo-facil' ); ?>
									</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( ! empty( $inv->errores ) ) : ?>
						<tr>
							<td></td>
							<td colspan="7" style="background:#fff8f8; color:#7f1d1d; font-size:12px; word-break:break-word;">
								<strong><?php esc_html_e( 'Detalle error:', 'arriendo-facil' ); ?></strong>
								<?php echo esc_html( $inv->errores ); ?>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div><!-- .wrap -->
