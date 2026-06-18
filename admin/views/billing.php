<?php
/**
 * Facturación Electrónica – listado de comprobantes con actualización asincrónica.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$billing_capability = apply_filters( 'af_billing_capability', 'manage_options' );
if ( ! current_user_can( (string) $billing_capability ) ) {
	wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'arriendo-facil' ) );
}

global $wpdb;

// ── Owner restriction ──────────────────────────────────────────────────────
$is_owner_view  = class_exists( 'Arriendo_Facil_Accommodation' ) && Arriendo_Facil_Accommodation::user_is_owner();
$owner_where    = '';
if ( $is_owner_view ) {
	$owner_acc_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
	if ( empty( $owner_acc_ids ) ) {
		$invoices = array();
	} else {
		$owner_ids_sql = implode( ',', array_map( 'intval', $owner_acc_ids ) );
		$owner_where   = " AND l.accommodation_id IN ($owner_ids_sql)";
	}
}

// ── Build base query helper ─────────────────────────────────────────────────
$base_invoice_query = function ( string $extra_where = '' ) use ( $wpdb, $owner_where ): array {
	return (array) $wpdb->get_results(
		"SELECT ei.*, l.monthly_rent,
		        p.post_title AS accommodation_title,
		        CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
		        g.id_number AS guest_id_number
		 FROM {$wpdb->prefix}af_electronic_invoices ei
		 LEFT JOIN {$wpdb->prefix}af_leases l         ON l.id   = ei.lease_id
		 LEFT JOIN {$wpdb->posts} p                   ON p.ID   = l.accommodation_id
		 LEFT JOIN {$wpdb->prefix}af_guests g         ON g.id   = l.guest_id
		 WHERE 1=1 {$owner_where} {$extra_where}
		 ORDER BY ei.created_at DESC
		 LIMIT 200"
	);
};

if ( ! isset( $invoices ) ) {
	$invoices = $base_invoice_query();
}

$cfg = Arriendo_Facil_SRI_Config::get();
$billing_ajax_nonce = wp_create_nonce( 'af_billing_nonce' );

$estado_labels = array(
	'generada'   => array( 'label' => __( 'Generada', 'arriendo-facil' ),   'color' => '#757575', 'icon' => '○', 'grupo' => 'en_proceso' ),
	'firmada'    => array( 'label' => __( 'Firmada', 'arriendo-facil' ),    'color' => '#455a64', 'icon' => '○', 'grupo' => 'en_proceso' ),
	'enviada'    => array( 'label' => __( 'Enviada', 'arriendo-facil' ),    'color' => '#1565c0', 'icon' => '⟳', 'grupo' => 'en_proceso' ),
	'autorizada' => array( 'label' => __( 'Autorizada', 'arriendo-facil' ), 'color' => '#2e7d32', 'icon' => '✓', 'grupo' => 'autorizadas' ),
	'autorizada_sin_ride' => array( 'label' => __( 'Autorizada sin RIDE', 'arriendo-facil' ), 'color' => '#2e7d32', 'icon' => '✓', 'grupo' => 'autorizadas' ),
	'error_envio' => array( 'label' => __( 'Error envío', 'arriendo-facil' ), 'color' => '#c62828', 'icon' => '✕', 'grupo' => 'error' ),
	'error_autorizacion' => array( 'label' => __( 'Error autorización', 'arriendo-facil' ), 'color' => '#c62828', 'icon' => '✕', 'grupo' => 'error' ),
	'devuelta'   => array( 'label' => __( 'Devuelta', 'arriendo-facil' ),   'color' => '#c62828', 'icon' => '↺', 'grupo' => 'error' ),
	'no_autorizada' => array( 'label' => __( 'No autorizada', 'arriendo-facil' ), 'color' => '#c62828', 'icon' => '✕', 'grupo' => 'error' ),
	'rechazada'  => array( 'label' => __( 'Rechazada', 'arriendo-facil' ),  'color' => '#c62828', 'icon' => '✕', 'grupo' => 'error' ),
	'anulada'    => array( 'label' => __( 'Anulada', 'arriendo-facil' ),    'color' => '#e65100', 'icon' => '–', 'grupo' => 'error' ),
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
			$invoices = $base_invoice_query();
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
			$invoices = $base_invoice_query();
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
		<?php if ( strpos( $last_action_message, 'correctamente' ) !== false ) : ?>
			<script>
				setTimeout( function () {
					location.reload();
				}, 2000 );
			</script>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( '2' === $cfg['ambiente'] ) : ?>
		<div class="notice notice-warning inline" style="margin-bottom:16px;">
			<p><strong><?php esc_html_e( '⚠ Ambiente: PRODUCCIÓN', 'arriendo-facil' ); ?></strong></p>
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
		<h2 style="margin-top:0;"><?php esc_html_e( 'Emisión Manual de Comprobante', 'arriendo-facil' ); ?></h2>

		<div style="margin-bottom:12px;">
			<label for="af-lease-search-input" style="display:block; font-weight:600; margin-bottom:4px;">
				<?php esc_html_e( 'Buscar contrato (cédula / RUC / pasaporte / nombre)', 'arriendo-facil' ); ?>
			</label>
			<div style="display:flex; gap:8px; align-items:center;">
				<input type="text" id="af-lease-search-input" placeholder="<?php esc_attr_e( 'Escribe al menos 2 caracteres…', 'arriendo-facil' ); ?>" class="regular-text" autocomplete="off" />
				<span id="af-lease-search-spinner" hidden style="color:#888;"><?php esc_html_e( 'Buscando…', 'arriendo-facil' ); ?></span>
			</div>
			<ul id="af-lease-search-results" style="list-style:none; margin:4px 0 0; padding:0; border:1px solid #dcdcde; background:#fff; max-width:540px; display:none;"></ul>
		</div>

		<form method="post" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
			<?php wp_nonce_field( 'af_billing_manual_issue' ); ?>
			<label for="af-billing-lease-id"><strong><?php esc_html_e( 'Lease ID', 'arriendo-facil' ); ?></strong></label>
			<input id="af-billing-lease-id" type="number" name="lease_id" min="1" required style="width:100px;" />
			<span id="af-billing-lease-label" style="color:#555; font-size:13px;"></span>
			<button type="button" id="af-open-issue-preview" class="button button-primary">
				<?php esc_html_e( 'Emitir Comprobante', 'arriendo-facil' ); ?>
			</button>
			<noscript>
				<button type="submit" name="af_issue_invoice_submit" class="button">
					<?php esc_html_e( 'Emitir (sin preview)', 'arriendo-facil' ); ?>
				</button>
			</noscript>
		</form>
	</div>

	<div id="af-billing-preview-modal" class="af-modal" hidden>
		<div class="af-modal__backdrop" data-af-close-billing-preview></div>
		<div class="af-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-billing-preview-title">
			<div class="af-modal__header">
				<h2 id="af-billing-preview-title"><?php esc_html_e( 'Preview de Comprobante', 'arriendo-facil' ); ?></h2>
				<button type="button" class="af-modal__close" data-af-close-billing-preview aria-label="<?php esc_attr_e( 'Close', 'arriendo-facil' ); ?>">&times;</button>
			</div>
			<div class="af-modal__body">
				<p id="af-billing-preview-summary" style="margin-top:0; color:#555;"></p>
				<div id="af-billing-preview-warning" style="display:none; margin:8px 0; padding:10px; border-radius:6px; background:#fff4e5; color:#7a4b00; border:1px solid #f3d2a2;"></div>

				<div style="display:grid; grid-template-columns:repeat(2,minmax(220px,1fr)); gap:10px; margin-bottom:10px;">
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Descripcion', 'arriendo-facil' ); ?></span>
						<input type="text" id="af-billing-preview-desc" class="regular-text" style="width:100%;" />
					</label>
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Precio Unitario', 'arriendo-facil' ); ?></span>
						<input type="number" id="af-billing-preview-price" min="0" step="0.01" style="width:100%;" />
					</label>
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Cantidad', 'arriendo-facil' ); ?></span>
						<input type="number" id="af-billing-preview-qty" min="0.01" step="0.01" style="width:100%;" />
					</label>
					<label>
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Descuento', 'arriendo-facil' ); ?></span>
						<input type="number" id="af-billing-preview-discount" min="0" step="0.01" style="width:100%;" />
					</label>
					<label style="grid-column:1 / -1;">
						<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Email (info adicional)', 'arriendo-facil' ); ?></span>
						<input type="email" id="af-billing-preview-email" style="width:100%;" />
					</label>
				</div>

				<div style="display:grid; grid-template-columns:repeat(2,minmax(220px,1fr)); gap:8px; margin-bottom:10px;">
					<div><?php esc_html_e( 'Comprador:', 'arriendo-facil' ); ?> <strong id="af-billing-preview-buyer-name">-</strong></div>
					<div><?php esc_html_e( 'Identificacion:', 'arriendo-facil' ); ?> <strong id="af-billing-preview-buyer-id">-</strong></div>
					<div><?php esc_html_e( 'Subtotal:', 'arriendo-facil' ); ?> <strong id="af-billing-preview-subtotal">$0.00</strong></div>
					<div><?php esc_html_e( 'IVA:', 'arriendo-facil' ); ?> <strong id="af-billing-preview-iva">$0.00</strong></div>
					<div style="grid-column:1 / -1;"><?php esc_html_e( 'Total:', 'arriendo-facil' ); ?> <strong id="af-billing-preview-total">$0.00</strong></div>
				</div>

				<p id="af-billing-preview-feedback" aria-live="polite" style="min-height:20px; margin:0 0 10px; color:#555;"></p>

				<div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;">
					<button type="button" class="button" data-af-close-billing-preview><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button type="button" class="button" id="af-billing-preview-refresh"><?php esc_html_e( 'Actualizar Preview', 'arriendo-facil' ); ?></button>
					<button type="button" class="button button-primary" id="af-billing-preview-approve"><?php esc_html_e( 'Aprobar y Emitir', 'arriendo-facil' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<!-- ── Tabs de Estado ───────────────────────────────────────────────── -->
	<?php if ( ! empty( $invoices ) ) : ?>
	<div style="margin-bottom:16px;">
		<div style="display:flex; gap:0; border-bottom:2px solid #dcdcde; margin-bottom:0; flex-wrap:wrap;">
			<button type="button" class="af-invoice-tab-btn" data-tab="autorizadas" style="padding:12px 16px; font-weight:600; border:none; background:none; cursor:pointer; color:#555; border-bottom:3px solid transparent; transition:all 0.2s;">
				<span style="font-size:18px; margin-right:6px;">✓</span> <?php esc_html_e( 'Autorizadas', 'arriendo-facil' ); ?>
				<span class="af-invoice-count" data-status="autorizadas" style="background:#2e7d32; color:#fff; border-radius:12px; padding:2px 8px; font-size:12px; margin-left:8px; min-width:24px; text-align:center;">0</span>
			</button>
			<button type="button" class="af-invoice-tab-btn" data-tab="en_proceso" style="padding:12px 16px; font-weight:600; border:none; background:none; cursor:pointer; color:#555; border-bottom:3px solid transparent; transition:all 0.2s;">
				<span style="font-size:18px; margin-right:6px;">⟳</span> <?php esc_html_e( 'En Proceso', 'arriendo-facil' ); ?>
				<span class="af-invoice-count" data-status="en_proceso" style="background:#1565c0; color:#fff; border-radius:12px; padding:2px 8px; font-size:12px; margin-left:8px; min-width:24px; text-align:center;">0</span>
			</button>
			<button type="button" class="af-invoice-tab-btn" data-tab="error" style="padding:12px 16px; font-weight:600; border:none; background:none; cursor:pointer; color:#555; border-bottom:3px solid transparent; transition:all 0.2s;">
				<span style="font-size:18px; margin-right:6px;">✕</span> <?php esc_html_e( 'Con Errores', 'arriendo-facil' ); ?>
				<span class="af-invoice-count" data-status="error" style="background:#c62828; color:#fff; border-radius:12px; padding:2px 8px; font-size:12px; margin-left:8px; min-width:24px; text-align:center;">0</span>
			</button>
			<span style="flex:1; display:flex; align-items:center; justify-content:flex-end; padding-right:16px; color:#999; font-size:12px; gap:8px;">
				<span id="af-last-update-time" style="min-width:120px; text-align:right;">—</span>
				<button type="button" id="af-refresh-invoices" style="background:none; border:none; color:#0073aa; cursor:pointer; font-weight:600; padding:4px 8px; white-space:nowrap;">
					🔄 <?php esc_html_e( 'Actualizar', 'arriendo-facil' ); ?>
				</button>
			</span>
		</div>

		<!-- ── Search / filter ──────────────────────────────────────── -->
		<div style="margin:12px 0 12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:#f9f9f9; padding:10px 12px; border-radius:3px;">
			<label for="af-invoice-filter" style="font-weight:600; margin:0;"><?php esc_html_e( 'Filtrar:', 'arriendo-facil' ); ?></label>
			<input type="search" id="af-invoice-filter"
				placeholder="<?php esc_attr_e( 'Cédula/RUC, nombre, inmueble, número…', 'arriendo-facil' ); ?>"
				class="regular-text" />
			<span id="af-invoice-filter-count" style="color:#999; font-size:12px; margin-left:auto;"></span>
		</div>

		<!-- ── Invoices Table ──────────────────────────────────────── -->
		<table class="wp-list-table widefat fixed striped" id="af-invoices-table">
			<thead>
				<tr>
					<th style="width:50px;">#</th>
					<th><?php esc_html_e( 'Comprobante', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Cliente', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Total', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
				</tr>
			</thead>
			<tbody id="af-invoices-tbody">
				<?php foreach ( $invoices as $inv ) : ?>
					<?php
					$estado_info = isset( $estado_labels[ $inv->estado ] )
						? $estado_labels[ $inv->estado ]
						: array( 'label' => esc_html( $inv->estado ), 'color' => '#555', 'icon' => '?', 'grupo' => 'otro' );
					$guest_label = isset( $inv->guest_name ) ? trim( (string) $inv->guest_name ) : '';
					$guest_id    = isset( $inv->guest_id_number ) ? (string) $inv->guest_id_number : '';
					$grupo       = $estado_info['grupo'] ?? 'otro';
					$errores_raw = (string) ( $inv->errores ?? '' );
					$mensajes_decoded = '' !== $errores_raw ? json_decode( $errores_raw, true ) : null;
					$has_mensajes = is_array( $mensajes_decoded ) && ! empty( $mensajes_decoded );
					?>
					<tr data-search="<?php echo esc_attr( strtolower( $guest_label . ' ' . $guest_id . ' ' . (string) $inv->accommodation_title . ' ' . (string) $inv->numero_comprobante ) ); ?>" data-grupo="<?php echo esc_attr( $grupo ); ?>" data-invoice-id="<?php echo (int) $inv->id; ?>" data-estado="<?php echo esc_attr( $inv->estado ); ?>">
						<td style="font-weight:600;"><?php echo esc_html( $inv->id ); ?></td>
						<td>
							<?php if ( $inv->numero_comprobante ) : ?>
								<strong style="font-size:13px;"><?php echo esc_html( $inv->numero_comprobante ); ?></strong><br />
							<?php endif; ?>
							<?php if ( $inv->clave_acceso ) : ?>
								<small style="word-break:break-all; color:#888;">
									<?php echo esc_html( substr( (string) $inv->clave_acceso, 0, 16 ) . '…' ); ?>
								</small>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( '' !== $guest_label ) : ?>
								<strong style="font-size:13px;"><?php echo esc_html( $guest_label ); ?></strong><br />
							<?php endif; ?>
							<?php if ( '' !== $guest_id ) : ?>
								<small style="color:#888;"><?php echo esc_html( $guest_id ); ?></small>
							<?php endif; ?>
						</td>
						<td style="font-size:13px;"><?php echo esc_html( $inv->accommodation_title ?: '—' ); ?></td>
						<td style="font-weight:600; color:#2e7d32; text-align:right;">$ <?php echo esc_html( number_format( (float) $inv->total, 2 ) ); ?></td>
						<td>
							<span style="font-weight:600; color:<?php echo esc_attr( $estado_info['color'] ); ?>; display:inline-flex; align-items:center; gap:6px;">
								<span style="font-size:16px;"><?php echo esc_html( $estado_info['icon'] ); ?></span>
								<?php echo esc_html( $estado_info['label'] ); ?>
							</span>
							<?php if ( $has_mensajes ) : ?>
								<button type="button" class="af-toggle-error-details" style="margin-left:8px; background:none; border:none; color:#c62828; cursor:pointer; text-decoration:underline; padding:0; font-size:11px;">
									<?php esc_html_e( 'detalles', 'arriendo-facil' ); ?>
								</button>
							<?php endif; ?>
						</td>
						<td style="font-size:12px; color:#888;">
							<?php echo esc_html( wp_date( 'd/m/Y', strtotime( $inv->created_at ) ) ); ?>
						</td>
						<td>
							<div style="display:flex; gap:4px; flex-wrap:wrap;">
								<?php if ( $inv->ride_path && file_exists( $inv->ride_path ) ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=af_download_ride&id=' . (int) $inv->id . '&nonce=' . wp_create_nonce( 'af_billing_nonce' ) ) ); ?>"
										class="button button-small" style="padding:4px 8px; font-size:11px;">
										<?php esc_html_e( 'RIDE', 'arriendo-facil' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( $inv->xml_autorizacion ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=af_download_xml&id=' . (int) $inv->id . '&nonce=' . wp_create_nonce( 'af_billing_nonce' ) ) ); ?>"
										class="button button-small" style="padding:4px 8px; font-size:11px;">
										<?php esc_html_e( 'XML', 'arriendo-facil' ); ?>
									</a>
								<?php elseif ( $inv->xml_firmado ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=af_download_xml&id=' . (int) $inv->id . '&nonce=' . wp_create_nonce( 'af_billing_nonce' ) ) ); ?>"
										class="button button-small" style="padding:4px 8px; font-size:11px;">
										<?php esc_html_e( 'XML', 'arriendo-facil' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( in_array( (string) $inv->estado, array( 'error_envio', 'error_autorizacion', 'devuelta', 'no_autorizada', 'autorizada_sin_ride' ), true ) ) : ?>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'af_billing_retry_invoice' ); ?>
										<input type="hidden" name="invoice_id" value="<?php echo (int) $inv->id; ?>" />
										<button type="submit" name="af_retry_invoice_submit" class="button button-small" style="padding:4px 8px; font-size:11px;">
											<?php esc_html_e( 'Reintentar', 'arriendo-facil' ); ?>
										</button>
									</form>
								<?php endif; ?>
							</div>
						</td>
					</tr>
					<?php if ( $has_mensajes ) : ?>
						<tr class="af-error-details" style="display:none; background:#fef5f5;">
							<td colspan="8" style="padding:12px; border-left:4px solid #c62828;">
								<strong style="color:#c62828; display:block; margin-bottom:6px;"><?php esc_html_e( 'Detalles del error:', 'arriendo-facil' ); ?></strong>
								<ul style="margin:0; padding:0 0 0 20px;">
									<?php foreach ( $mensajes_decoded as $msg ) :
										$tipo = strtoupper( (string) ( $msg['tipo'] ?? '' ) );
										$texto = (string) ( $msg['mensaje'] ?? '' );
										$info = (string) ( $msg['informacionAdicional'] ?? '' );
										$color = 'ERROR' === $tipo ? '#c62828' : '#b45700';
									?>
										<li style="margin:4px 0; color:<?php echo esc_attr( $color ); ?>; font-size:12px;">
											<strong><?php echo esc_html( $tipo ); ?>:</strong>
											<?php echo esc_html( $texto ); ?>
											<?php if ( $info ) echo ' — ' . esc_html( $info ); ?>
										</li>
									<?php endforeach; ?>
								</ul>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php else : ?>
		<p style="margin-top:24px; color:#888; text-align:center; padding:40px 20px;">
			<?php esc_html_e( 'Aún no se han emitido comprobantes. Los comprobantes se generarán automáticamente al aprobar un contrato.', 'arriendo-facil' ); ?>
		</p>
	<?php endif; ?>
</div><!-- .wrap -->

<script>
(function () {
	var billingNonce = '<?php echo esc_js( $billing_ajax_nonce ); ?>';
	var billingAjaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

	// ── 1. Live lease search ──────────────────────────────────────────
	var searchInput  = document.getElementById( 'af-lease-search-input' );
	var resultsList  = document.getElementById( 'af-lease-search-results' );
	var spinner      = document.getElementById( 'af-lease-search-spinner' );
	var leaseIdField = document.getElementById( 'af-billing-lease-id' );
	var leaseLabel   = document.getElementById( 'af-billing-lease-label' );
	var searchTimer  = null;

	if ( searchInput ) {
		searchInput.addEventListener( 'input', function () {
			clearTimeout( searchTimer );
			var q = this.value.trim();
			resultsList.style.display = 'none';
			resultsList.innerHTML     = '';
			if ( q.length < 2 ) return;

			spinner.hidden = false;
			searchTimer = setTimeout( function () {
				var fd = new FormData();
				fd.append( 'action', 'af_billing_lease_search' );
				fd.append( 'q', q );
				fd.append( 'nonce', billingNonce );

				fetch( billingAjaxUrl, {
					method: 'POST', body: fd, credentials: 'same-origin',
				} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( resp ) {
					spinner.hidden = true;
					if ( ! resp.success || ! resp.data.leases.length ) {
						resultsList.innerHTML = '<li style="padding:8px 12px; color:#888;"><?php echo esc_js( __( 'Sin resultados', 'arriendo-facil' ) ); ?></li>';
						resultsList.style.display = 'block';
						return;
					}
					resultsList.innerHTML = '';
					resp.data.leases.forEach( function ( l ) {
						var li = document.createElement( 'li' );
						li.style.cssText = 'padding:8px 12px; cursor:pointer; border-bottom:1px solid #f0f0f0;';
						li.innerHTML = '<strong>#' + l.id + '</strong> &mdash; ' + ( l.guest_name || '—' ) + ' <small style="color:#888;">(' + ( l.id_number || '—' ) + ')</small> &nbsp; ' + ( l.accommodation_title || '' ) + ' &nbsp; <em style="color:#555;">$' + parseFloat( l.monthly_rent || 0 ).toFixed( 2 ) + '</em>';
						li.addEventListener( 'click', function () {
							leaseIdField.value        = l.id;
							leaseLabel.textContent    = ( l.guest_name || '' ) + ' — ' + ( l.accommodation_title || '' );
							searchInput.value         = ( l.guest_name || '' ) + ' (' + ( l.id_number || '' ) + ')';
							resultsList.style.display = 'none';
						} );
						li.addEventListener( 'mouseenter', function () { this.style.background = '#f6f7f7'; } );
						li.addEventListener( 'mouseleave', function () { this.style.background = ''; } );
						resultsList.appendChild( li );
					} );
					resultsList.style.display = 'block';
				} )
				.catch( function () { spinner.hidden = true; } );
			}, 300 );
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! resultsList.contains( e.target ) && e.target !== searchInput ) {
				resultsList.style.display = 'none';
			}
		} );
	}

	// ── 2. Manual issue preview flow ───────────────────────────────────
	var previewOpenBtn = document.getElementById( 'af-open-issue-preview' );
	var leaseIdFieldIssue = document.getElementById( 'af-billing-lease-id' );
	var modal = document.getElementById( 'af-billing-preview-modal' );
	var summary = document.getElementById( 'af-billing-preview-summary' );
	var warningEl = document.getElementById( 'af-billing-preview-warning' );
	var feedback = document.getElementById( 'af-billing-preview-feedback' );
	var refreshBtn = document.getElementById( 'af-billing-preview-refresh' );
	var approveBtn = document.getElementById( 'af-billing-preview-approve' );
	var inputDesc = document.getElementById( 'af-billing-preview-desc' );
	var inputPrice = document.getElementById( 'af-billing-preview-price' );
	var inputQty = document.getElementById( 'af-billing-preview-qty' );
	var inputDiscount = document.getElementById( 'af-billing-preview-discount' );
	var inputEmail = document.getElementById( 'af-billing-preview-email' );
	var buyerName = document.getElementById( 'af-billing-preview-buyer-name' );
	var buyerId = document.getElementById( 'af-billing-preview-buyer-id' );
	var subtotalEl = document.getElementById( 'af-billing-preview-subtotal' );
	var ivaEl = document.getElementById( 'af-billing-preview-iva' );
	var totalEl = document.getElementById( 'af-billing-preview-total' );

	var issueState = {
		leaseId: 0,
		canIssue: false,
	};

	function money( value ) {
		return '$' + Number( value || 0 ).toFixed( 2 );
	}

	function setPreviewFeedback( text, isError ) {
		if ( ! feedback ) {
			return;
		}
		feedback.textContent = text || '';
		feedback.style.color = isError ? '#c62828' : '#555';
	}

	function collectOverrides() {
		return {
			descripcion: inputDesc ? inputDesc.value.trim() : '',
			precio_unitario: inputPrice ? Number( inputPrice.value || 0 ) : 0,
			cantidad: inputQty ? Number( inputQty.value || 0 ) : 0,
			descuento: inputDiscount ? Number( inputDiscount.value || 0 ) : 0,
			email: inputEmail ? inputEmail.value.trim() : '',
		};
	}

	function renderPreview( data ) {
		var item = ( data && data.item ) ? data.item : {};
		var buyer = ( data && data.buyer ) ? data.buyer : {};
		var totals = ( data && data.totals ) ? data.totals : {};

		if ( inputDesc && item.descripcion !== undefined ) inputDesc.value = item.descripcion;
		if ( inputPrice && item.precio_unitario !== undefined ) inputPrice.value = Number( item.precio_unitario ).toFixed( 2 );
		if ( inputQty && item.cantidad !== undefined ) inputQty.value = Number( item.cantidad ).toFixed( 2 );
		if ( inputDiscount && item.descuento !== undefined ) inputDiscount.value = Number( item.descuento ).toFixed( 2 );
		if ( inputEmail && data && data.info_adicional && data.info_adicional.email !== undefined ) inputEmail.value = data.info_adicional.email || '';

		if ( buyerName ) buyerName.textContent = buyer.name || '-';
		if ( buyerId ) buyerId.textContent = buyer.identification || '-';
		if ( subtotalEl ) subtotalEl.textContent = money( totals.total_sin_impuestos );
		if ( ivaEl ) ivaEl.textContent = money( totals.iva_valor );
		if ( totalEl ) totalEl.textContent = money( totals.importe_total );

		issueState.canIssue = !!( data && data.can_issue );
		if ( warningEl ) {
			warningEl.style.display = ( data && data.warning ) ? 'block' : 'none';
			warningEl.textContent = ( data && data.warning ) ? data.warning : '';
		}
		if ( approveBtn ) {
			approveBtn.disabled = ! issueState.canIssue;
		}
		if ( summary ) {
			summary.textContent = '<?php echo esc_js( __( 'Lease ID:', 'arriendo-facil' ) ); ?> ' + issueState.leaseId + ' · <?php echo esc_js( __( 'Periodo:', 'arriendo-facil' ) ); ?> ' + ( data.billing_period || '-' );
		}
	}

	function closePreviewModal() {
		if ( modal ) {
			modal.setAttribute( 'hidden', 'hidden' );
		}
		document.body.classList.remove( 'af-modal-open' );
		issueState.leaseId = 0;
		issueState.canIssue = false;
		setPreviewFeedback( '', false );
	}

	function openPreviewModal() {
		if ( modal ) {
			modal.removeAttribute( 'hidden' );
		}
		document.body.classList.add( 'af-modal-open' );
	}

	function requestPreview() {
		if ( ! issueState.leaseId ) {
			return;
		}

		setPreviewFeedback( '<?php echo esc_js( __( 'Calculando preview...', 'arriendo-facil' ) ); ?>', false );
		if ( refreshBtn ) refreshBtn.disabled = true;
		if ( approveBtn ) approveBtn.disabled = true;

		var fd = new FormData();
		fd.append( 'action', 'af_preview_invoice' );
		fd.append( 'lease_id', String( issueState.leaseId ) );
		fd.append( 'nonce', billingNonce );
		fd.append( 'overrides', JSON.stringify( collectOverrides() ) );

		fetch( billingAjaxUrl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( resp ) {
			if ( ! resp.success ) {
				throw new Error( ( resp.data && resp.data.message ) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo obtener el preview.', 'arriendo-facil' ) ); ?>' );
			}
			renderPreview( resp.data );
			setPreviewFeedback( issueState.canIssue ? '<?php echo esc_js( __( 'Revisa los datos y aprueba para emitir.', 'arriendo-facil' ) ); ?>' : '<?php echo esc_js( __( 'No se puede emitir en este periodo.', 'arriendo-facil' ) ); ?>', ! issueState.canIssue );
		} )
		.catch( function ( err ) {
			issueState.canIssue = false;
			setPreviewFeedback( err.message || '<?php echo esc_js( __( 'Error de red.', 'arriendo-facil' ) ); ?>', true );
		} )
		.finally( function () {
			if ( refreshBtn ) refreshBtn.disabled = false;
			if ( approveBtn ) approveBtn.disabled = ! issueState.canIssue;
		} );
	}

	function approveAndIssue() {
		if ( ! issueState.leaseId || ! issueState.canIssue ) {
			return;
		}

		setPreviewFeedback( '<?php echo esc_js( __( 'Emitiendo comprobante...', 'arriendo-facil' ) ); ?>', false );
		if ( refreshBtn ) refreshBtn.disabled = true;
		if ( approveBtn ) approveBtn.disabled = true;

		var fd = new FormData();
		fd.append( 'action', 'af_issue_invoice' );
		fd.append( 'lease_id', String( issueState.leaseId ) );
		fd.append( 'nonce', billingNonce );
		fd.append( 'overrides', JSON.stringify( collectOverrides() ) );

		fetch( billingAjaxUrl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( resp ) {
			if ( ! resp.success ) {
				throw new Error( ( resp.data && resp.data.message ) ? resp.data.message : '<?php echo esc_js( __( 'Error al emitir.', 'arriendo-facil' ) ); ?>' );
			}

			setPreviewFeedback( '<?php echo esc_js( __( 'Comprobante emitido correctamente. Recargando...', 'arriendo-facil' ) ); ?>', false );
			setTimeout( function () {
				window.location.reload();
			}, 900 );
		} )
		.catch( function ( err ) {
			setPreviewFeedback( err.message || '<?php echo esc_js( __( 'Error de red.', 'arriendo-facil' ) ); ?>', true );
			if ( refreshBtn ) refreshBtn.disabled = false;
			if ( approveBtn ) approveBtn.disabled = ! issueState.canIssue;
		} );
	}

	if ( previewOpenBtn ) {
		previewOpenBtn.addEventListener( 'click', function () {
			var leaseId = leaseIdFieldIssue ? parseInt( leaseIdFieldIssue.value || '0', 10 ) : 0;
			if ( ! leaseId ) {
				window.alert( '<?php echo esc_js( __( 'Selecciona un Lease ID válido antes de emitir.', 'arriendo-facil' ) ); ?>' );
				return;
			}

			issueState.leaseId = leaseId;
			openPreviewModal();
			requestPreview();
		} );
	}

	if ( refreshBtn ) {
		refreshBtn.addEventListener( 'click', requestPreview );
	}

	if ( approveBtn ) {
		approveBtn.addEventListener( 'click', approveAndIssue );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-af-close-billing-preview]' ) ) {
			closePreviewModal();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && modal && ! modal.hasAttribute( 'hidden' ) ) {
			closePreviewModal();
		}
	} );

	// ── 3. Tab navigation & filtering ──────────────────────────────────
	var tabBtns = document.querySelectorAll( '.af-invoice-tab-btn' );
	var filterInput = document.getElementById( 'af-invoice-filter' );
	var filterCount = document.getElementById( 'af-invoice-filter-count' );
	var tbody = document.getElementById( 'af-invoices-tbody' );
	var table = document.getElementById( 'af-invoices-table' );

	var activeTab = 'autorizadas';

	function updateCounts() {
		if ( ! tbody ) return;
		var counts = { autorizadas: 0, en_proceso: 0, error: 0 };
		tbody.querySelectorAll( 'tr[data-grupo]' ).forEach( function ( row ) {
			var grupo = row.dataset.grupo;
			if ( grupo && counts.hasOwnProperty( grupo ) ) {
				counts[ grupo ]++;
			}
		} );

		document.querySelectorAll( '.af-invoice-count' ).forEach( function ( el ) {
			var status = el.dataset.status;
			el.textContent = counts[ status ] || '0';
		} );
	}

	function filterTable() {
		if ( ! tbody ) return;
		var q = ( filterInput && filterInput.value.toLowerCase().trim() ) || '';
		var visibleCount = 0;

		tbody.querySelectorAll( 'tr' ).forEach( function ( row ) {
			var grupoRow = row.dataset.grupo;
			var isDataRow = grupoRow !== undefined;
			var isErrorDetail = row.classList.contains( 'af-error-details' );

			if ( isErrorDetail ) {
				var prevRow = row.previousElementSibling;
				row.style.display = prevRow && prevRow.style.display !== 'none' ? '' : 'none';
				return;
			}

			if ( ! isDataRow ) return;

			var matchGroup = !activeTab || grupoRow === activeTab;
			var matchFilter = !q || row.dataset.search.indexOf( q ) !== -1;
			var show = matchGroup && matchFilter;

			row.style.display = show ? '' : 'none';
			if ( show ) visibleCount++;
		} );

		if ( filterCount ) {
			filterCount.textContent = q ? '(' + visibleCount + ' <?php echo esc_js( __( 'resultados', 'arriendo-facil' ) ); ?>)' : '';
		}
	}

	tabBtns.forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var tab = this.dataset.tab;
			activeTab = tab;

			tabBtns.forEach( function ( b ) {
				b.style.borderBottomColor = b.dataset.tab === tab ? '#0073aa' : 'transparent';
				b.style.color = b.dataset.tab === tab ? '#0073aa' : '#555';
			} );

			filterTable();
		} );
	} );

	if ( filterInput ) {
		filterInput.addEventListener( 'input', filterTable );
	}

	// Toggle error details
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'af-toggle-error-details' ) ) {
			var detailRow = e.target.closest( 'tr' ).nextElementSibling;
			if ( detailRow && detailRow.classList.contains( 'af-error-details' ) ) {
				detailRow.style.display = detailRow.style.display === 'none' ? '' : 'none';
			}
		}
	} );

	// ── 4. Async refresh ──────────────────────────────────────────────
	var refreshBtn = document.getElementById( 'af-refresh-invoices' );
	var lastUpdateTime = document.getElementById( 'af-last-update-time' );
	var autoRefreshInterval = 15000;
	var lastRefresh = Date.now();
	var hasJustSubmitted = false;

	// Detect if page just loaded after form submission
	if ( window.performance && window.performance.navigation ) {
		hasJustSubmitted = window.performance.navigation.type === 1; // Page reload
	}

	// Initial setup
	updateCounts();
	filterTable();

	// Auto-refresh on page load if just submitted
	if ( hasJustSubmitted && tbody ) {
		setTimeout( function () {
			refreshInvoices();
		}, 1000 );
	}

	function updateLastRefreshTime() {
		var now = Date.now();
		var diff = Math.floor( ( now - lastRefresh ) / 1000 );
		if ( diff < 60 ) {
			lastUpdateTime.textContent = '<?php echo esc_js( __( 'Actualizado hace', 'arriendo-facil' ) ); ?> ' + diff + ' seg.';
		} else {
			var mins = Math.floor( diff / 60 );
			lastUpdateTime.textContent = '<?php echo esc_js( __( 'Actualizado hace', 'arriendo-facil' ) ); ?> ' + mins + ' min.';
		}
	}

	function refreshInvoices() {
		if ( ! tbody || ! table ) return;

		var fd = new FormData();
		fd.append( 'action', 'af_get_invoices_async' );
		fd.append( 'nonce', billingNonce );

		if ( refreshBtn ) {
			refreshBtn.style.opacity = '0.6';
			refreshBtn.disabled = true;
		}

		fetch( billingAjaxUrl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( resp ) {
			if ( resp.success && resp.data && resp.data.invoices ) {
				var invoices = resp.data.invoices;
				var currentIds = new Set();
				tbody.querySelectorAll( 'tr[data-invoice-id]' ).forEach( function ( row ) {
					currentIds.add( parseInt( row.dataset.invoiceId ) );
				} );

				invoices.forEach( function ( inv ) {
					var row = tbody.querySelector( 'tr[data-invoice-id="' + inv.id + '"]' );
					if ( row ) {
						var statusCell = row.querySelectorAll( 'td' )[ 5 ];
						if ( statusCell && row.dataset.estado !== inv.estado ) {
							statusCell.style.animation = 'pulse 0.5s';
							row.dataset.estado = inv.estado;
							row.dataset.grupo = inv.grupo;
						}
					}
				} );

				updateCounts();
				filterTable();
				lastRefresh = Date.now();
				updateLastRefreshTime();
			}
		} )
		.catch( function () {} )
		.finally( function () {
			if ( refreshBtn ) {
				refreshBtn.style.opacity = '1';
				refreshBtn.disabled = false;
			}
		} );
	}

	if ( refreshBtn ) {
		refreshBtn.addEventListener( 'click', refreshInvoices );
	}

	setInterval( updateLastRefreshTime, 1000 );
	setInterval( refreshInvoices, autoRefreshInterval );

	// CSS animation
	var style = document.createElement( 'style' );
	style.textContent = '@keyframes pulse { 0% { background-color: #fff9e6; } 50% { background-color: #fff; } 100% { background-color: #fff9e6; } }';
	document.head.appendChild( style );
}());
</script>
