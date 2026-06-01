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
	'enviada'    => array( 'label' => __( 'Enviada', 'arriendo-facil' ),    'color' => '#1565c0' ),
	'autorizada' => array( 'label' => __( 'Autorizada', 'arriendo-facil' ), 'color' => '#2e7d32' ),
	'rechazada'  => array( 'label' => __( 'Rechazada', 'arriendo-facil' ),  'color' => '#c62828' ),
	'anulada'    => array( 'label' => __( 'Anulada', 'arriendo-facil' ),    'color' => '#e65100' ),
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Facturación Electrónica', 'arriendo-facil' ); ?></h1>

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
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div><!-- .wrap -->
