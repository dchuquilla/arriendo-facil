<?php
/**
 * Partial: Comprobantes electrónicos (panel dentro del hub de facturación).
 *
 * Listado de comprobantes con actualización asíncrona, emisión manual y
 * KPIs de estado. Se incluye desde admin/views/billing.php.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
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
		$owner_where   = " AND COALESCE(l.accommodation_id, cr.accommodation_id) IN ($owner_ids_sql)";
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
		 LEFT JOIN {$wpdb->prefix}af_cleaning_requests cr ON cr.id = ei.cleaning_request_id
		 LEFT JOIN {$wpdb->posts} p                   ON p.ID   = COALESCE(l.accommodation_id, cr.accommodation_id)
		 LEFT JOIN {$wpdb->prefix}af_guests g         ON g.id   = l.guest_id
		 WHERE 1=1 {$owner_where} {$extra_where}
		 ORDER BY ei.created_at DESC
		 LIMIT 200"
	);
};

if ( ! isset( $invoices ) ) {
	$invoices = $base_invoice_query();
}

$comprobantes_cfg = Arriendo_Facil_SRI_Config::get();
$billing_ajax_nonce = wp_create_nonce( 'af_billing_nonce' );

$estado_labels = array(
	'generada'   => array( 'label' => __( 'Generada', 'arriendo-facil' ),   'color' => '#757575', 'icon' => '○', 'grupo' => 'en_proceso' ),
	'firmada'    => array( 'label' => __( 'Firmada', 'arriendo-facil' ),    'color' => '#455a64', 'icon' => '○', 'grupo' => 'en_proceso' ),
	'enviada'    => array( 'label' => __( 'Enviada', 'arriendo-facil' ),    'color' => '#3882f6', 'icon' => '⟳', 'grupo' => 'en_proceso' ),
	'autorizada' => array( 'label' => __( 'Autorizada', 'arriendo-facil' ), 'color' => '#2f7d73', 'icon' => '✓', 'grupo' => 'autorizadas' ),
	'autorizada_sin_ride' => array( 'label' => __( 'Autorizada sin RIDE', 'arriendo-facil' ), 'color' => '#2f7d73', 'icon' => '✓', 'grupo' => 'autorizadas' ),
	'error_envio' => array( 'label' => __( 'Error envío', 'arriendo-facil' ), 'color' => '#b03a36', 'icon' => '✕', 'grupo' => 'error' ),
	'error_autorizacion' => array( 'label' => __( 'Error autorización', 'arriendo-facil' ), 'color' => '#b03a36', 'icon' => '✕', 'grupo' => 'error' ),
	'devuelta'   => array( 'label' => __( 'Devuelta', 'arriendo-facil' ),   'color' => '#b03a36', 'icon' => '↺', 'grupo' => 'error' ),
	'no_autorizada' => array( 'label' => __( 'No autorizada', 'arriendo-facil' ), 'color' => '#b03a36', 'icon' => '✕', 'grupo' => 'error' ),
	'rechazada'  => array( 'label' => __( 'Rechazada', 'arriendo-facil' ),  'color' => '#b03a36', 'icon' => '✕', 'grupo' => 'error' ),
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
		$allowed_lease = true;
		if ( $is_owner_view ) {
			$acc_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT accommodation_id FROM {$wpdb->prefix}af_leases WHERE id = %d LIMIT 1", $lease_id ) );
			$allowed_lease = $acc_id > 0 && in_array( $acc_id, array_map( 'intval', (array) ( $owner_acc_ids ?? array() ) ), true );
		}
		if ( ! $allowed_lease ) {
			$last_action_message = __( 'No tienes acceso a este contrato.', 'arriendo-facil' );
		} else {
			$manager   = new Arriendo_Facil_Billing_Manager();
			$lock_key  = 'af_issue_lock_' . $lease_id;
			if ( get_transient( $lock_key ) ) {
				$result = new WP_Error( 'af_in_flight', __( 'Ya hay una emisión en curso para este contrato. Espera unos segundos y revisa el listado antes de reintentar.', 'arriendo-facil' ) );
			} else {
				set_transient( $lock_key, 1, 60 );
				$result = $manager->issue_lease_invoice( $lease_id );
				delete_transient( $lock_key );
			}
			if ( is_wp_error( $result ) ) {
				$last_action_message = __( 'Error al emitir:', 'arriendo-facil' ) . ' ' . $result->get_error_message();
			} else {
				$last_action_message = __( 'Comprobante emitido correctamente.', 'arriendo-facil' );
				$invoices = $base_invoice_query();
			}
		}
	}
}

if ( isset( $_POST['af_retry_invoice_submit'] ) ) {
	check_admin_referer( 'af_billing_retry_invoice' );
	$invoice_id = isset( $_POST['invoice_id'] ) ? absint( wp_unslash( $_POST['invoice_id'] ) ) : 0;
	if ( $invoice_id > 0 ) {
		$allowed_invoice = true;
		if ( $is_owner_view ) {
			$acc_id_for_invoice = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(l.accommodation_id, cr.accommodation_id)
					 FROM {$wpdb->prefix}af_electronic_invoices ei
					 LEFT JOIN {$wpdb->prefix}af_leases l ON l.id = ei.lease_id
					 LEFT JOIN {$wpdb->prefix}af_cleaning_requests cr ON cr.id = ei.cleaning_request_id
					 WHERE ei.id = %d LIMIT 1",
					$invoice_id
				)
			);
			$allowed_invoice = $acc_id_for_invoice > 0 && in_array( $acc_id_for_invoice, array_map( 'intval', (array) ( $owner_acc_ids ?? array() ) ), true );
		}
		if ( ! $allowed_invoice ) {
			$last_action_message = __( 'No tienes acceso a este comprobante.', 'arriendo-facil' );
		} else {
			$retry_meta = get_option( 'af_sri_retry_meta', array() );
			if ( is_array( $retry_meta ) && isset( $retry_meta[ $invoice_id ] ) ) {
				unset( $retry_meta[ $invoice_id ] );
				update_option( 'af_sri_retry_meta', $retry_meta, false );
			}

			$manager  = new Arriendo_Facil_Billing_Manager();
			$lock_key = 'af_retry_lock_' . $invoice_id;
			if ( get_transient( $lock_key ) ) {
				$result = new WP_Error( 'af_in_flight', __( 'Este comprobante ya se está reenviando. Espera unos segundos y actualiza el listado.', 'arriendo-facil' ) );
			} else {
				set_transient( $lock_key, 1, 60 );
				$result = $manager->retry_invoice( $invoice_id );
				delete_transient( $lock_key );
			}
			if ( is_wp_error( $result ) ) {
				$last_action_message = __( 'Error al reintentar:', 'arriendo-facil' ) . ' ' . $result->get_error_message();
			} else {
				$last_action_message = __( 'Reintento ejecutado correctamente.', 'arriendo-facil' );
				$invoices = $base_invoice_query();
			}
		}
	}
}

// ── KPIs por estado (se actualizan también vía AJAX) ───────────────────────
$kpi_groups  = array( 'autorizadas' => 0, 'en_proceso' => 0, 'error' => 0 );
$kpi_totals  = array( 'autorizadas' => 0.0, 'en_proceso' => 0.0, 'error' => 0.0 );
foreach ( $invoices as $inv_row ) {
	$grupo_kpi = isset( $estado_labels[ $inv_row->estado ] ) ? $estado_labels[ $inv_row->estado ]['grupo'] : 'otro';
	if ( isset( $kpi_groups[ $grupo_kpi ] ) ) {
		$kpi_groups[ $grupo_kpi ]++;
		$kpi_totals[ $grupo_kpi ] += (float) $inv_row->total;
	}
}
?>

<div class="af-kpi-grid af-billing-kpis" aria-label="<?php esc_attr_e( 'Resumen de comprobantes', 'arriendo-facil' ); ?>">
	<article class="af-kpi af-kpi--success">
		<div class="af-kpi__head">
			<span class="af-kpi__label"><?php esc_html_e( 'Autorizadas', 'arriendo-facil' ); ?></span>
			<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( 'check-circle', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		</div>
		<div class="af-kpi__value" data-kpi-count="autorizadas"><?php echo (int) $kpi_groups['autorizadas']; ?></div>
		<div class="af-kpi__hint">
			<span data-kpi-total="autorizadas"><?php echo esc_html( '$' . number_format( $kpi_totals['autorizadas'], 2 ) ); ?></span>
			<?php esc_html_e( 'emitidos', 'arriendo-facil' ); ?>
		</div>
	</article>
	<article class="af-kpi af-kpi--info">
		<div class="af-kpi__head">
			<span class="af-kpi__label"><?php esc_html_e( 'En proceso', 'arriendo-facil' ); ?></span>
			<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		</div>
		<div class="af-kpi__value" data-kpi-count="en_proceso"><?php echo (int) $kpi_groups['en_proceso']; ?></div>
		<div class="af-kpi__hint">
			<?php esc_html_e( 'SRI procesando', 'arriendo-facil' ); ?>
			· <span data-kpi-total="en_proceso"><?php echo esc_html( '$' . number_format( $kpi_totals['en_proceso'], 2 ) ); ?></span>
		</div>
	</article>
	<article class="af-kpi af-kpi--attention">
		<div class="af-kpi__head">
			<span class="af-kpi__label"><?php esc_html_e( 'Con errores', 'arriendo-facil' ); ?></span>
			<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( 'circle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		</div>
		<div class="af-kpi__value" data-kpi-count="error"><?php echo (int) $kpi_groups['error']; ?></div>
		<div class="af-kpi__hint">
			<?php esc_html_e( 'Requieren revisión', 'arriendo-facil' ); ?>
			· <span data-kpi-total="error"><?php echo esc_html( '$' . number_format( $kpi_totals['error'], 2 ) ); ?></span>
		</div>
	</article>
</div>

<?php if ( '' !== $last_action_message ) : ?>
	<div class="af-note af-note--ok" role="status">
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

<details class="af-manual-issue" id="af-manual-issue">
	<summary class="af-manual-issue__summary">
		<span class="af-manual-issue__ico" aria-hidden="true"><?php echo af_lucide( 'receipt', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		<span>
			<strong><?php esc_html_e( 'Facturar un contrato sin cobro registrado', 'arriendo-facil' ); ?></strong>
			<small><?php esc_html_e( 'Para casos puntuales: busca el contrato y revisa la factura antes de emitir.', 'arriendo-facil' ); ?></small>
		</span>
	</summary>

	<form method="post" class="af-issue-form">
		<?php wp_nonce_field( 'af_billing_manual_issue' ); ?>
		<div class="af-issue-search">
			<label class="screen-reader-text" for="af-lease-search-input"><?php esc_html_e( 'Buscar contrato', 'arriendo-facil' ); ?></label>
			<input type="search" id="af-lease-search-input" placeholder="<?php esc_attr_e( 'Cédula, RUC, pasaporte o nombre del inquilino…', 'arriendo-facil' ); ?>" autocomplete="off" />
			<span id="af-lease-search-spinner" hidden class="af-td-meta"><?php esc_html_e( 'Buscando…', 'arriendo-facil' ); ?></span>
			<ul id="af-lease-search-results" class="af-lease-search__list" style="display:none;"></ul>
		</div>
		<input id="af-billing-lease-id" type="hidden" name="lease_id" value="" />
		<div class="af-issue-picked" id="af-issue-picked" hidden>
			<span id="af-billing-lease-label" class="af-issue-form__lease-label"></span>
			<button type="button" id="af-open-issue-preview" class="button af-btn af-btn--primary">
				<?php esc_html_e( 'Revisar y emitir', 'arriendo-facil' ); ?>
			</button>
		</div>
		<noscript>
			<button type="submit" name="af_issue_invoice_submit" class="button"><?php esc_html_e( 'Emitir (sin vista previa)', 'arriendo-facil' ); ?></button>
		</noscript>
	</form>
</details>

<div id="af-billing-preview-modal" class="af-modal" hidden>
	<div class="af-modal__backdrop" data-af-close-billing-preview></div>
	<div class="af-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-billing-preview-title">
		<div class="af-modal__header">
			<h2 id="af-billing-preview-title"><?php esc_html_e( 'Vista previa del comprobante', 'arriendo-facil' ); ?></h2>
			<button type="button" class="af-modal__close" data-af-close-billing-preview aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		</div>
		<div class="af-modal__body">
			<p id="af-billing-preview-summary" style="margin-top:0; color: var(--af-gray-600, #647579);"></p>
			<div id="af-billing-preview-warning" style="display:none; margin:8px 0; padding:10px; border-radius:6px; background: var(--af-warning-50, #F5E9DA); color: var(--af-warning-700, #7A4B00); border:1px solid var(--af-warning-200, #EBD3B3);"></div>

			<div class="af-form-grid-2" style="margin-bottom:10px;">
				<label>
					<span class="af-form-field__label" style="display:block; margin-bottom:4px;"><?php esc_html_e( 'Descripcion', 'arriendo-facil' ); ?></span>
					<input type="text" id="af-billing-preview-desc" class="regular-text" style="width:100%;" />
				</label>
				<label>
					<span class="af-form-field__label" style="display:block; margin-bottom:4px;"><?php esc_html_e( 'Precio Unitario', 'arriendo-facil' ); ?></span>
					<input type="number" id="af-billing-preview-price" min="0" step="0.01" style="width:100%;" />
				</label>
				<label>
					<span class="af-form-field__label" style="display:block; margin-bottom:4px;"><?php esc_html_e( 'Cantidad', 'arriendo-facil' ); ?></span>
					<input type="number" id="af-billing-preview-qty" min="0.01" step="0.01" style="width:100%;" />
				</label>
				<label>
					<span class="af-form-field__label" style="display:block; margin-bottom:4px;"><?php esc_html_e( 'Descuento', 'arriendo-facil' ); ?></span>
					<input type="number" id="af-billing-preview-discount" min="0" step="0.01" style="width:100%;" />
				</label>
				<label style="grid-column:1 / -1;">
					<span class="af-form-field__label" style="display:block; margin-bottom:4px;"><?php esc_html_e( 'Email (info adicional)', 'arriendo-facil' ); ?></span>
					<input type="email" id="af-billing-preview-email" style="width:100%;" />
				</label>
			</div>

			<div class="af-form-grid-2" style="gap:8px; margin-bottom:10px;">
				<div><?php esc_html_e( 'Comprador:', 'arriendo-facil' ); ?> <strong id="af-billing-preview-buyer-name">-</strong></div>
				<div><?php esc_html_e( 'Identificacion:', 'arriendo-facil' ); ?> <strong id="af-billing-preview-buyer-id">-</strong></div>
				<div><?php esc_html_e( 'Subtotal:', 'arriendo-facil' ); ?> <strong id="af-billing-preview-subtotal">$0.00</strong></div>
				<div><?php esc_html_e( 'IVA:', 'arriendo-facil' ); ?> <strong id="af-billing-preview-iva">$0.00</strong></div>
				<div style="grid-column:1 / -1;"><?php esc_html_e( 'Total:', 'arriendo-facil' ); ?> <strong id="af-billing-preview-total">$0.00</strong></div>
			</div>

			<p id="af-billing-preview-feedback" aria-live="polite" style="min-height:20px; margin:0 0 10px; color: var(--af-gray-600, #647579);"></p>

			<div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;">
				<button type="button" class="button" data-af-close-billing-preview><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
				<button type="button" class="button" id="af-billing-preview-refresh"><?php esc_html_e( 'Actualizar vista previa', 'arriendo-facil' ); ?></button>
				<button type="button" class="button button-primary" id="af-billing-preview-approve"><?php esc_html_e( 'Aprobar y Emitir', 'arriendo-facil' ); ?></button>
			</div>
		</div>
	</div>
</div>

<!-- ── Tabs de Estado ───────────────────────────────────────────────── -->
<?php if ( ! empty( $invoices ) ) : ?>
<div class="af-invoice-list">
	<div class="af-invoice-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Estado de comprobantes', 'arriendo-facil' ); ?>">
		<button type="button" class="af-invoice-tab af-invoice-tab-btn is-active" data-tab="autorizadas" role="tab" aria-selected="true">
			<span class="af-invoice-tab__icon" aria-hidden="true"><?php echo af_lucide( 'check-circle', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<span class="af-invoice-tab__label"><?php esc_html_e( 'Autorizadas', 'arriendo-facil' ); ?></span>
			<span class="af-invoice-count" data-status="autorizadas">0</span>
		</button>
		<button type="button" class="af-invoice-tab af-invoice-tab-btn" data-tab="en_proceso" role="tab" aria-selected="false">
			<span class="af-invoice-tab__icon" aria-hidden="true"><?php echo af_lucide( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<span class="af-invoice-tab__label"><?php esc_html_e( 'En Proceso', 'arriendo-facil' ); ?></span>
			<span class="af-invoice-count" data-status="en_proceso">0</span>
		</button>
		<button type="button" class="af-invoice-tab af-invoice-tab-btn" data-tab="error" role="tab" aria-selected="false">
			<span class="af-invoice-tab__icon" aria-hidden="true"><?php echo af_lucide( 'circle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<span class="af-invoice-tab__label"><?php esc_html_e( 'Con Errores', 'arriendo-facil' ); ?></span>
			<span class="af-invoice-count" data-status="error">0</span>
		</button>
	</div>

	<div class="af-invoice-toolbar">
		<label for="af-invoice-filter" class="af-invoice-search"><?php esc_html_e( 'Buscar:', 'arriendo-facil' ); ?></label>
		<input type="search" id="af-invoice-filter"
			placeholder="<?php esc_attr_e( 'Cédula/RUC, nombre, inmueble, número…', 'arriendo-facil' ); ?>" />
		<label for="af-invoice-date-from" class="af-invoice-search"><?php esc_html_e( 'Desde', 'arriendo-facil' ); ?></label>
		<input type="date" id="af-invoice-date-from" />
		<label for="af-invoice-date-to" class="af-invoice-search"><?php esc_html_e( 'Hasta', 'arriendo-facil' ); ?></label>
		<input type="date" id="af-invoice-date-to" />
		<span id="af-invoice-filter-count" class="af-invoice-toolbar__meta" aria-live="polite"></span>
		<span class="af-invoice-toolbar__spacer" aria-hidden="true"></span>
		<span id="af-last-update-time" class="af-invoice-toolbar__meta" aria-live="polite">—</span>
		<button type="button" id="af-refresh-invoices" class="af-invoice-refresh">
			<span class="af-invoice-tab__icon" aria-hidden="true"><?php echo af_lucide( 'refresh-cw', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<?php esc_html_e( 'Actualizar', 'arriendo-facil' ); ?>
		</button>
	</div>

	<div class="af-table-scroll">
	<table class="wp-list-table widefat fixed striped af-data-table" id="af-invoices-table">
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
				<tr data-search="<?php echo esc_attr( strtolower( $guest_label . ' ' . $guest_id . ' ' . (string) $inv->accommodation_title . ' ' . (string) $inv->numero_comprobante ) ); ?>"
					data-grupo="<?php echo esc_attr( $grupo ); ?>"
					data-date="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( $inv->created_at ) ) ); ?>"
					data-invoice-id="<?php echo (int) $inv->id; ?>"
					data-estado="<?php echo esc_attr( $inv->estado ); ?>"
					data-total="<?php echo esc_attr( (float) $inv->total ); ?>">
					<td class="af-invoice-no" data-label="#"><?php echo esc_html( $inv->id ); ?></td>
					<td data-label="<?php esc_attr_e( 'Comprobante', 'arriendo-facil' ); ?>">
						<?php if ( $inv->numero_comprobante ) : ?>
							<strong class="af-invoice-no"><?php echo esc_html( $inv->numero_comprobante ); ?></strong><br />
						<?php endif; ?>
						<?php if ( $inv->clave_acceso ) : ?>
							<small class="af-td-meta"><?php echo esc_html( substr( (string) $inv->clave_acceso, 0, 16 ) . '…' ); ?></small>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Cliente', 'arriendo-facil' ); ?>">
						<?php if ( '' !== $guest_label ) : ?>
							<strong><?php echo esc_html( $guest_label ); ?></strong><br />
						<?php endif; ?>
						<?php if ( '' !== $guest_id ) : ?>
							<small class="af-td-meta"><?php echo esc_html( $guest_id ); ?></small>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><?php echo esc_html( $inv->accommodation_title ?: '—' ); ?></td>
					<td class="af-money" data-label="<?php esc_attr_e( 'Total', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format( (float) $inv->total, 2 ) ); ?></td>
					<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>">
						<span class="af-status-chip af-status-chip--<?php echo esc_attr( $grupo ); ?>">
							<span class="af-status-chip__dot" aria-hidden="true"></span>
							<?php echo esc_html( $estado_info['label'] ); ?>
						</span>
						<button type="button" class="af-toggle-error-details" aria-expanded="false">
							<?php echo $has_mensajes ? esc_html__( 'ver error', 'arriendo-facil' ) : esc_html__( 'ver detalle', 'arriendo-facil' ); ?>
						</button>
					</td>
					<td class="af-td-meta" data-label="<?php esc_attr_e( 'Fecha', 'arriendo-facil' ); ?>">
						<?php echo esc_html( wp_date( 'd/m/Y', strtotime( $inv->created_at ) ) ); ?>
					</td>
					<td class="af-td-actions" data-label="<?php esc_attr_e( 'Acciones', 'arriendo-facil' ); ?>">
						<div style="display:flex; gap:4px; flex-wrap:wrap;">
							<?php if ( $inv->ride_path && file_exists( $inv->ride_path ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=af_download_ride&id=' . (int) $inv->id . '&nonce=' . wp_create_nonce( 'af_billing_nonce' ) ) ); ?>"
									class="button button-small" style="padding:4px 8px; font-size:11px;">
									<?php esc_html_e( 'RIDE', 'arriendo-facil' ); ?>
								</a>
							<?php endif; ?>
							<?php if ( $inv->xml_autorizacion || $inv->xml_firmado ) : ?>
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
				<tr class="af-error-details<?php echo $has_mensajes ? ' af-error-details--error' : ''; ?>" style="display:none;">
					<td colspan="8">
						<dl class="af-invoice-detail">
							<div><dt><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( '01' === (string) $inv->tipo_comprobante ? __( 'Factura (01)', 'arriendo-facil' ) : (string) $inv->tipo_comprobante ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Ambiente', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( 2 === (int) $inv->ambiente ? __( 'Producción', 'arriendo-facil' ) : __( 'Pruebas', 'arriendo-facil' ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Periodo', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $inv->billing_period ?: '—' ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Subtotal 0%', 'arriendo-facil' ); ?></dt><dd>$<?php echo esc_html( number_format( (float) $inv->subtotal_0, 2 ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Subtotal con IVA', 'arriendo-facil' ); ?></dt><dd>$<?php echo esc_html( number_format( (float) $inv->subtotal_iva, 2 ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'IVA', 'arriendo-facil' ); ?></dt><dd>$<?php echo esc_html( number_format( (float) $inv->iva_valor, 2 ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Total', 'arriendo-facil' ); ?></dt><dd><strong>$<?php echo esc_html( number_format( (float) $inv->total, 2 ) ); ?></strong></dd></div>
							<div><dt><?php esc_html_e( 'Autorización SRI', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $inv->numero_autorizacion ?: __( 'Sin autorización', 'arriendo-facil' ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Fecha de autorización', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $inv->fecha_autorizacion ? wp_date( 'd/m/Y H:i', strtotime( $inv->fecha_autorizacion ) ) : '—' ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Enviado por correo', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $inv->emailed_at ? wp_date( 'd/m/Y H:i', strtotime( $inv->emailed_at ) ) : __( 'No', 'arriendo-facil' ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Creado', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $inv->created_at ) ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Última actualización', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $inv->updated_at ) ) ); ?></dd></div>
							<div class="af-invoice-detail__wide"><dt><?php esc_html_e( 'Clave de acceso', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $inv->clave_acceso ?: '—' ); ?></dd></div>
						</dl>
						<?php if ( $has_mensajes ) : ?>
							<p class="af-error-details__head"><?php esc_html_e( 'Mensajes del SRI:', 'arriendo-facil' ); ?></p>
							<ul style="margin:0; padding:0 0 0 20px;">
								<?php foreach ( $mensajes_decoded as $msg ) :
									$tipo = strtoupper( (string) ( $msg['tipo'] ?? '' ) );
									$texto = (string) ( $msg['mensaje'] ?? '' );
									$info = (string) ( $msg['informacionAdicional'] ?? '' );
									$color = 'ERROR' === $tipo ? 'var(--af-danger-600, #D9534F)' : 'var(--af-warning-600, #D89B5B)';
								?>
									<li class="af-error-details__msg" style="color:<?php echo esc_attr( $color ); ?>; margin:4px 0;">
										<strong><?php echo esc_html( $tipo ); ?>:</strong>
										<?php echo esc_html( $texto ); ?>
										<?php if ( $info ) echo ' — ' . esc_html( $info ); ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="af-td-meta"><?php esc_html_e( 'Corrige el dato indicado y usa “Reintentar” en la fila; si el SRI no responde, vuelve a intentarlo más tarde.', 'arriendo-facil' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<p id="af-invoice-noresults" class="af-empty__text" role="status" hidden><?php esc_html_e( 'Ningún comprobante coincide con los filtros. Prueba con otro texto, otro rango de fechas o revisa las demás pestañas de estado.', 'arriendo-facil' ); ?></p>
	<nav id="af-invoice-pager" class="af-invoice-pager" aria-label="<?php esc_attr_e( 'Paginación de comprobantes', 'arriendo-facil' ); ?>" hidden>
		<button type="button" class="button" id="af-invoice-prev"><?php esc_html_e( 'Anterior', 'arriendo-facil' ); ?></button>
		<span id="af-invoice-page-label" aria-live="polite"></span>
		<button type="button" class="button" id="af-invoice-next"><?php esc_html_e( 'Siguiente', 'arriendo-facil' ); ?></button>
	</nav>
</div>
<?php else : ?>
	<div class="af-empty" style="margin-top: var(--af-space-5);">
		<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'receipt', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		<h3 class="af-empty__title"><?php esc_html_e( 'Aún no se han emitido comprobantes', 'arriendo-facil' ); ?></h3>
		<p class="af-empty__text"><?php esc_html_e( 'Cuando factures un cobro desde “Por facturar”, aparecerá aquí con su estado y descargas.', 'arriendo-facil' ); ?></p>
	</div>
<?php endif; ?>

<script>
(function () {
	'use strict';
	var billingNonce = '<?php echo esc_js( $billing_ajax_nonce ); ?>';
	var billingAjaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

	// ── 1. Live lease search ──────────────────────────────────────────
	var searchInput  = document.getElementById( 'af-lease-search-input' );
	var resultsList  = document.getElementById( 'af-lease-search-results' );
	var spinner      = document.getElementById( 'af-lease-search-spinner' );
	var leaseIdField = document.getElementById( 'af-billing-lease-id' );
	var leaseLabel   = document.getElementById( 'af-billing-lease-label' );
	var searchTimer  = null;
	var selectedLeaseData = null;

	if ( searchInput && resultsList ) {
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
						resultsList.innerHTML = '<li style="padding:8px 12px; color: var(--af-gray-500, #647579);"><?php echo esc_js( __( 'Sin resultados', 'arriendo-facil' ) ); ?></li>';
						resultsList.style.display = 'block';
						return;
					}
					resultsList.innerHTML = '';
					resp.data.leases.forEach( function ( l ) {
						var li = document.createElement( 'li' );
						var a  = document.createElement( 'a' );
						a.href = '#';
						a.textContent = '#' + l.id + ' — ' + ( l.guest_name || '—' ) + ' (' + ( l.id_number || '—' ) + ') · ' + ( l.accommodation_title || '' ) + ' · $' + parseFloat( l.monthly_rent || 0 ).toFixed( 2 );
						li.appendChild( a );
						a.addEventListener( 'click', function ( e ) {
							e.preventDefault();
							leaseIdField.value        = l.id;
							leaseLabel.textContent    = ( l.guest_name || '' ) + ' — ' + ( l.accommodation_title || '' );
							var pickedBox = document.getElementById( 'af-issue-picked' ); if ( pickedBox ) { pickedBox.hidden = false; }
							searchInput.value         = ( l.guest_name || '' ) + ' (' + ( l.id_number || '' ) + ')';
							selectedLeaseData         = l;
							resultsList.style.display = 'none';
						} );
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
	var previewRefreshBtn = document.getElementById( 'af-billing-preview-refresh' );
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
		baseline: null,
	};

	function money( value ) {
		return '$' + Number( value || 0 ).toFixed( 2 );
	}

	function setPreviewFeedback( text, isError ) {
		if ( ! feedback ) {
			return;
		}
		feedback.textContent = text || '';
		feedback.style.color = isError ? 'var(--af-danger-600, #D9534F)' : 'var(--af-gray-600, #647579)';
	}

	function prefillFromSelectedLease() {
		if ( ! selectedLeaseData ) {
			return;
		}

		var guestName = selectedLeaseData.guest_name || 'CONSUMIDOR FINAL';
		var idNumber = selectedLeaseData.id_number || '9999999999999';
		var rent = Number( selectedLeaseData.monthly_rent || 0 );

		if ( inputDesc && ! inputDesc.value ) {
			inputDesc.value = 'Canon de arriendo - ' + ( selectedLeaseData.accommodation_title || 'Inmueble' );
		}
		if ( inputQty && ! inputQty.value ) {
			inputQty.value = '1.00';
		}
		if ( inputPrice && ! inputPrice.value ) {
			inputPrice.value = rent.toFixed( 2 );
		}
		if ( inputDiscount && ! inputDiscount.value ) {
			inputDiscount.value = '0.00';
		}

		if ( buyerName ) buyerName.textContent = guestName;
		if ( buyerId ) buyerId.textContent = idNumber;
		if ( subtotalEl ) subtotalEl.textContent = money( rent );
		if ( ivaEl ) ivaEl.textContent = money( 0 );
		if ( totalEl ) totalEl.textContent = money( rent );
	}

	function collectOverrides() {
		var current = {
			descripcion: inputDesc ? inputDesc.value.trim() : '',
			precio_unitario: inputPrice ? Number( inputPrice.value || 0 ) : 0,
			cantidad: inputQty ? Number( inputQty.value || 0 ) : 0,
			descuento: inputDiscount ? Number( inputDiscount.value || 0 ) : 0,
			email: inputEmail ? inputEmail.value.trim() : '',
		};

		if ( ! issueState.baseline ) {
			return current;
		}

		var out = {};
		if ( current.descripcion !== issueState.baseline.descripcion ) {
			out.descripcion = current.descripcion;
		}
		if ( Math.abs( current.precio_unitario - issueState.baseline.precio_unitario ) > 0.0001 ) {
			out.precio_unitario = current.precio_unitario;
		}
		if ( Math.abs( current.cantidad - issueState.baseline.cantidad ) > 0.0001 ) {
			out.cantidad = current.cantidad;
		}
		if ( Math.abs( current.descuento - issueState.baseline.descuento ) > 0.0001 ) {
			out.descuento = current.descuento;
		}

		// Email vacío significa mantener el valor base (flujo histórico).
		if ( '' !== current.email && current.email !== issueState.baseline.email ) {
			out.email = current.email;
		}

		return out;
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

		issueState.baseline = {
			descripcion: item.descripcion !== undefined ? String( item.descripcion ) : '',
			precio_unitario: item.precio_unitario !== undefined ? Number( item.precio_unitario ) : 0,
			cantidad: item.cantidad !== undefined ? Number( item.cantidad ) : 0,
			descuento: item.descuento !== undefined ? Number( item.descuento ) : 0,
			email: ( data && data.info_adicional && data.info_adicional.email ) ? String( data.info_adicional.email ) : '',
		};

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
			summary.textContent = '<?php echo esc_js( __( 'ID del contrato:', 'arriendo-facil' ) ); ?> ' + issueState.leaseId + ' · <?php echo esc_js( __( 'Periodo:', 'arriendo-facil' ) ); ?> ' + ( data.billing_period || '-' );
		}
	}

	function closePreviewModal() {
		if ( modal ) {
			modal.setAttribute( 'hidden', 'hidden' );
		}
		document.body.classList.remove( 'af-modal-open' );
		issueState.leaseId = 0;
		issueState.canIssue = false;
		issueState.baseline = null;
		setPreviewFeedback( '', false );
		if ( warningEl ) {
			warningEl.style.display = 'none';
			warningEl.textContent = '';
		}
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

		setPreviewFeedback( '<?php echo esc_js( __( 'Calculando vista previa...', 'arriendo-facil' ) ); ?>', false );
		if ( previewRefreshBtn ) previewRefreshBtn.disabled = true;
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
			if ( previewRefreshBtn ) previewRefreshBtn.disabled = false;
			if ( approveBtn ) approveBtn.disabled = ! issueState.canIssue;
		} );
	}

	function approveAndIssue() {
		if ( ! issueState.leaseId || ! issueState.canIssue ) {
			return;
		}

		setPreviewFeedback( '<?php echo esc_js( __( 'Emitiendo comprobante...', 'arriendo-facil' ) ); ?>', false );
		if ( previewRefreshBtn ) previewRefreshBtn.disabled = true;
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
			if ( previewRefreshBtn ) previewRefreshBtn.disabled = false;
			if ( approveBtn ) approveBtn.disabled = ! issueState.canIssue;
		} );
	}

	if ( previewOpenBtn ) {
		previewOpenBtn.addEventListener( 'click', function () {
			var leaseId = leaseIdFieldIssue ? parseInt( leaseIdFieldIssue.value || '0', 10 ) : 0;
			if ( ! leaseId ) {
				window.alert( '<?php echo esc_js( __( 'Selecciona un ID de contrato valido antes de emitir.', 'arriendo-facil' ) ); ?>' );
				return;
			}

			issueState.leaseId = leaseId;
			openPreviewModal();
			prefillFromSelectedLease();
			requestPreview();
		} );
	}

	if ( previewRefreshBtn ) {
		previewRefreshBtn.addEventListener( 'click', requestPreview );
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

	// Evita envíos duplicados por doble clic en reintento / emisión.
	document.querySelectorAll( '#af-billing-panel-comprobantes form[method="post"]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			if ( form.dataset.submitting ) {
				e.preventDefault();
				return;
			}
			form.dataset.submitting = '1';
			form.querySelectorAll( 'button[type="submit"]' ).forEach( function ( b ) { b.setAttribute( 'aria-busy', 'true' ); b.classList.add( 'is-busy' ); } );
		} );
	} );

	// ── 3. Tab navigation & filtering ──────────────────────────────────
	var tabBtns = document.querySelectorAll( '.af-invoice-tab-btn' );
	var filterInput = document.getElementById( 'af-invoice-filter' );
	var filterCount = document.getElementById( 'af-invoice-filter-count' );
	var tbody = document.getElementById( 'af-invoices-tbody' );
	var table = document.getElementById( 'af-invoices-table' );
	var hubPanel = document.getElementById( 'af-billing-panel-comprobantes' );

	var activeTab = 'autorizadas';
	var PAGE_SIZE = 25;
	var currentPage = 1;
	var dateFrom = document.getElementById( 'af-invoice-date-from' );
	var dateTo = document.getElementById( 'af-invoice-date-to' );
	var noResults = document.getElementById( 'af-invoice-noresults' );
	var pager = document.getElementById( 'af-invoice-pager' );
	var pageLabel = document.getElementById( 'af-invoice-page-label' );
	var prevBtn = document.getElementById( 'af-invoice-prev' );
	var nextBtn = document.getElementById( 'af-invoice-next' );

	function kpiMoney( value ) {
		var x = Number( value || 0 ).toFixed( 2 ).split( '.' );
		x[ 0 ] = x[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
		return '$' + x.join( '.' );
	}

	function updateCounts() {
		if ( ! tbody ) return;
		var counts = { autorizadas: 0, en_proceso: 0, error: 0 };
		var totals = { autorizadas: 0, en_proceso: 0, error: 0 };
		tbody.querySelectorAll( 'tr[data-grupo]' ).forEach( function ( row ) {
			var grupo = row.dataset.grupo;
			if ( grupo && counts.hasOwnProperty( grupo ) ) {
				counts[ grupo ]++;
				totals[ grupo ] += parseFloat( row.dataset.total || 0 );
			}
		} );

		document.querySelectorAll( '.af-invoice-count' ).forEach( function ( el ) {
			var status = el.dataset.status;
			el.textContent = counts[ status ] || '0';
		} );

		document.querySelectorAll( '[data-kpi-count]' ).forEach( function ( el ) {
			el.textContent = counts[ el.dataset.kpiCount ] || '0';
		} );
		document.querySelectorAll( '[data-kpi-total]' ).forEach( function ( el ) {
			el.textContent = kpiMoney( totals[ el.dataset.kpiTotal ] );
		} );
	}

	function filterTable( keepPage ) {
		if ( ! tbody ) return;
		if ( true !== keepPage ) currentPage = 1;
		var q = ( filterInput && filterInput.value.toLowerCase().trim() ) || '';
		var from = dateFrom ? dateFrom.value : '';
		var to = dateTo ? dateTo.value : '';
		var matched = [];

		tbody.querySelectorAll( 'tr[data-grupo]' ).forEach( function ( row ) {
			var matchGroup = !activeTab || row.dataset.grupo === activeTab;
			var matchFilter = !q || row.dataset.search.indexOf( q ) !== -1;
			var d = row.dataset.date || '';
			var matchDate = ( !from || d >= from ) && ( !to || d <= to );
			if ( matchGroup && matchFilter && matchDate ) matched.push( row );
		} );

		var pages = Math.max( 1, Math.ceil( matched.length / PAGE_SIZE ) );
		if ( currentPage > pages ) currentPage = pages;
		var start = ( currentPage - 1 ) * PAGE_SIZE;
		var visible = new Set( matched.slice( start, start + PAGE_SIZE ) );

		tbody.querySelectorAll( 'tr[data-grupo]' ).forEach( function ( row ) {
			var show = visible.has( row );
			row.style.display = show ? '' : 'none';
			var next = row.nextElementSibling;
			if ( next && next.classList.contains( 'af-error-details' ) && ! show ) {
				next.style.display = 'none';
			}
		} );

		if ( filterCount ) {
			filterCount.textContent = matched.length + ' <?php echo esc_js( __( 'resultados', 'arriendo-facil' ) ); ?>';
		}
		if ( noResults ) noResults.hidden = matched.length > 0;
		if ( pager ) {
			pager.hidden = matched.length <= PAGE_SIZE;
			pageLabel.textContent = '<?php echo esc_js( __( 'Página', 'arriendo-facil' ) ); ?> ' + currentPage + ' / ' + pages;
			prevBtn.disabled = currentPage <= 1;
			nextBtn.disabled = currentPage >= pages;
		}
	}

	function setActiveTab( tab, active ) {
		var btn = document.querySelector( '.af-invoice-tab-btn[data-tab="' + tab + '"]' );
		if ( btn ) {
			btn.classList.toggle( 'is-active', active );
			btn.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		}
	}

	tabBtns.forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var tab = this.dataset.tab;
			activeTab = tab;

			tabBtns.forEach( function ( b ) {
				setActiveTab( b.dataset.tab, b.dataset.tab === tab );
			} );

			filterTable();
		} );
	} );

	if ( filterInput ) {
		filterInput.addEventListener( 'input', filterTable );
	}
	[ dateFrom, dateTo ].forEach( function ( el ) {
		if ( el ) el.addEventListener( 'change', filterTable );
	} );
	if ( prevBtn ) prevBtn.addEventListener( 'click', function () { currentPage--; filterTable( true ); } );
	if ( nextBtn ) nextBtn.addEventListener( 'click', function () { currentPage++; filterTable( true ); } );

	// Toggle error details
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'af-toggle-error-details' ) ) {
			var detailRow = e.target.closest( 'tr' ).nextElementSibling;
			if ( detailRow && detailRow.classList.contains( 'af-error-details' ) ) {
				var opening = detailRow.style.display === 'none';
				detailRow.style.display = opening ? '' : 'none';
				e.target.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
			}
		}
	} );

	// ── 4. Async refresh ──────────────────────────────────────────────
	var tableRefreshBtn = document.getElementById( 'af-refresh-invoices' );
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
		if ( ! lastUpdateTime ) return;
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

		if ( tableRefreshBtn ) {
			tableRefreshBtn.style.opacity = '0.6';
			tableRefreshBtn.disabled = true;
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
				filterTable( true );
				lastRefresh = Date.now();
				updateLastRefreshTime();
			}
		} )
		.catch( function () {} )
		.finally( function () {
			if ( tableRefreshBtn ) {
				tableRefreshBtn.style.opacity = '1';
				tableRefreshBtn.disabled = false;
			}
		} );
	}

	if ( tableRefreshBtn ) {
		tableRefreshBtn.addEventListener( 'click', refreshInvoices );
	}

	setInterval( updateLastRefreshTime, 1000 );
	setInterval( function () {
		// No consultar al servidor si el panel de comprobantes está oculto.
		if ( hubPanel && hubPanel.offsetParent === null ) {
			return;
		}
		refreshInvoices();
	}, autoRefreshInterval );

	// CSS animation
	if ( ! document.getElementById( 'af-billing-pulse-keyframes' ) ) {
		var style = document.createElement( 'style' );
		style.id = 'af-billing-pulse-keyframes';
		style.textContent = '@keyframes pulse { 0% { background-color: var(--af-warning-50, #F5E9DA); } 50% { background-color: var(--af-gray-0, #fff); } 100% { background-color: var(--af-warning-50, #F5E9DA); } }';
		document.head.appendChild( style );
	}
}());
</script>