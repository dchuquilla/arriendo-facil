<?php
/**
 * Upcoming exits operational page.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$today  = gmdate( 'Y-m-d' );
$limit  = gmdate( 'Y-m-d', strtotime( '+90 days' ) );
$leases = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT l.*, p.post_title AS accommodation_title, CONCAT(g.first_name, ' ', g.last_name) AS guest_name
		 FROM {$wpdb->prefix}af_leases l
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
		 WHERE l.status = 'active' AND l.deleted_at IS NULL AND l.end_date BETWEEN %s AND %s
		 ORDER BY l.end_date ASC",
		$today,
		$limit
	)
);

$buckets = array( '30' => 0, '60' => 0, '90' => 0 );
foreach ( $leases as $lease ) {
	$days = max( 0, (int) floor( ( strtotime( $lease->end_date ) - strtotime( $today ) ) / DAY_IN_SECONDS ) );
	if ( $days <= 30 ) {
		$buckets['30']++;
	} elseif ( $days <= 60 ) {
		$buckets['60']++;
	} else {
		$buckets['90']++;
	}
}

$legal_statuses = Arriendo_Facil_Lease_Operations::legal_statuses();
$nonce          = wp_create_nonce( 'af_lease_operations_nonce' );
?>
<div class="wrap af-shell">
	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Operación preventiva', 'arriendo-facil' ),
			'title'    => __( 'Próximas salidas y garantías', 'arriendo-facil' ),
			'subtitle' => __( 'Anticipa vencimientos, prepara renovaciones y liquida garantías con saldos reales.', 'arriendo-facil' ),
		)
	);
	?>

	<div class="af-kpi-grid" role="list">
		<article class="af-kpi af-kpi--attention" role="listitem"><div class="af-kpi__label"><?php esc_html_e( 'En los próximos 30 días', 'arriendo-facil' ); ?></div><div class="af-kpi__value"><?php echo esc_html( $buckets['30'] ); ?></div><div class="af-kpi__hint"><?php esc_html_e( 'Prioridad de decisión inmediata', 'arriendo-facil' ); ?></div></article>
		<article class="af-kpi" role="listitem"><div class="af-kpi__label"><?php esc_html_e( 'De 31 a 60 días', 'arriendo-facil' ); ?></div><div class="af-kpi__value"><?php echo esc_html( $buckets['60'] ); ?></div><div class="af-kpi__hint"><?php esc_html_e( 'Iniciar conversación de renovación', 'arriendo-facil' ); ?></div></article>
		<article class="af-kpi af-kpi--info" role="listitem"><div class="af-kpi__label"><?php esc_html_e( 'De 61 a 90 días', 'arriendo-facil' ); ?></div><div class="af-kpi__value"><?php echo esc_html( $buckets['90'] ); ?></div><div class="af-kpi__hint"><?php esc_html_e( 'Planificación anticipada', 'arriendo-facil' ); ?></div></article>
	</div>

	<section class="af-section">
		<header class="af-section__header"><div><h2 class="af-section__title"><?php esc_html_e( 'Contratos por vencer', 'arriendo-facil' ); ?></h2><p class="af-section__subtitle"><?php esc_html_e( 'Las deducciones de garantía requieren revisión y autorización humana antes de marcarse como pagadas.', 'arriendo-facil' ); ?></p></div></header>
		<div class="af-table-scroll"><table class="wp-list-table widefat fixed striped af-data-table af-exits-table"><thead><tr><th><?php esc_html_e( 'Contrato', 'arriendo-facil' ); ?></th><th><?php esc_html_e( 'Vencimiento', 'arriendo-facil' ); ?></th><th><?php esc_html_e( 'Renovación', 'arriendo-facil' ); ?></th><th><?php esc_html_e( 'Estado legal', 'arriendo-facil' ); ?></th><th><?php esc_html_e( 'Garantía', 'arriendo-facil' ); ?></th></tr></thead><tbody>
		<?php if ( empty( $leases ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No hay contratos activos que venzan en los próximos 90 días.', 'arriendo-facil' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $leases as $lease ) : ?>
			<?php
			$days     = max( 0, (int) floor( ( strtotime( $lease->end_date ) - strtotime( $today ) ) / DAY_IN_SECONDS ) );
			$summary  = Arriendo_Facil_Lease_Operations::deposit_summary( $lease );
			$estimate = Arriendo_Facil_Accommodation::estimate_monthly_rent(
				(string) get_post_meta( $lease->accommodation_id, '_af_city', true ),
				(string) get_post_meta( $lease->accommodation_id, '_af_property_type', true ),
				(int) get_post_meta( $lease->accommodation_id, '_af_bedrooms', true ),
				(int) $lease->accommodation_id
			);
			?>
			<tr>
				<td data-label="<?php esc_attr_e( 'Contrato', 'arriendo-facil' ); ?>"><strong><?php echo esc_html( $lease->accommodation_title ?: '#' . $lease->accommodation_id ); ?></strong><span class="af-td-meta"><?php echo esc_html( trim( $lease->guest_name ) ?: __( 'Sin inquilino', 'arriendo-facil' ) ); ?></span></td>
				<td data-label="<?php esc_attr_e( 'Vencimiento', 'arriendo-facil' ); ?>"><span class="af-pill <?php echo esc_attr( $days <= 30 ? 'af-pill--danger' : ( $days <= 60 ? 'af-pill--warning' : 'af-pill--neutral' ) ); ?>"><?php echo esc_html( sprintf( _n( '%d día', '%d días', $days, 'arriendo-facil' ), $days ) ); ?></span><span class="af-td-meta"><?php echo esc_html( $lease->end_date ); ?></span></td>
				<td data-label="<?php esc_attr_e( 'Renovación', 'arriendo-facil' ); ?>">
					<?php if ( $estimate['sample_size'] > 0 ) : ?><span class="af-td-meta"><?php echo esc_html( sprintf( __( 'Sugerido: $%1$s (%2$d comparables)', 'arriendo-facil' ), number_format_i18n( $estimate['average'], 2 ), $estimate['sample_size'] ) ); ?></span><?php else : ?><span class="af-td-meta"><?php esc_html_e( 'Sin comparables internos', 'arriendo-facil' ); ?></span><?php endif; ?>
					<form class="af-inline-operation" data-action="af_renew_lease"><input type="hidden" name="lease_id" value="<?php echo esc_attr( $lease->id ); ?>"><input type="date" name="new_end_date" min="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( $lease->end_date . ' +1 day' ) ) ); ?>" required><input type="text" name="reason" placeholder="<?php esc_attr_e( 'Motivo', 'arriendo-facil' ); ?>" required><button class="button af-btn af-btn--ghost"><?php esc_html_e( 'Renovar', 'arriendo-facil' ); ?></button></form>
				</td>
				<td data-label="<?php esc_attr_e( 'Estado legal', 'arriendo-facil' ); ?>"><form class="af-inline-operation" data-action="af_update_lease_legal_status"><input type="hidden" name="lease_id" value="<?php echo esc_attr( $lease->id ); ?>"><select name="legal_status"><?php foreach ( $legal_statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $lease->legal_status ?? 'pendiente', $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><input type="text" name="legal_notes" value="<?php echo esc_attr( $lease->legal_notes ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Referencia o nota', 'arriendo-facil' ); ?>"><button class="button af-btn af-btn--ghost"><?php esc_html_e( 'Guardar', 'arriendo-facil' ); ?></button></form></td>
				<td data-label="<?php esc_attr_e( 'Garantía', 'arriendo-facil' ); ?>"><details class="af-deposit-details"><summary><?php echo esc_html( sprintf( __( 'Devolver: $%s', 'arriendo-facil' ), number_format_i18n( $summary['refund'], 2 ) ) ); ?></summary><p><?php echo esc_html( sprintf( __( 'Depósito $%1$s - saldo $%2$s', 'arriendo-facil' ), number_format_i18n( $summary['deposit'], 2 ), number_format_i18n( $summary['balance'], 2 ) ) ); ?></p><form class="af-inline-operation" data-action="af_save_deposit_settlement"><input type="hidden" name="lease_id" value="<?php echo esc_attr( $lease->id ); ?>"><label><?php esc_html_e( 'Daños', 'arriendo-facil' ); ?><input type="number" name="damages" min="0" step="0.01" value="<?php echo esc_attr( $summary['damages'] ); ?>"></label><label><?php esc_html_e( 'Servicios', 'arriendo-facil' ); ?><input type="number" name="services" min="0" step="0.01" value="<?php echo esc_attr( $summary['services'] ); ?>"></label><select name="status"><option value="pendiente" <?php selected( $lease->deposit_settlement_status ?? 'pendiente', 'pendiente' ); ?>><?php esc_html_e( 'Pendiente', 'arriendo-facil' ); ?></option><option value="aprobada" <?php selected( $lease->deposit_settlement_status ?? '', 'aprobada' ); ?>><?php esc_html_e( 'Aprobada', 'arriendo-facil' ); ?></option><option value="pagada" <?php selected( $lease->deposit_settlement_status ?? '', 'pagada' ); ?>><?php esc_html_e( 'Pagada', 'arriendo-facil' ); ?></option></select><input type="text" name="notes" value="<?php echo esc_attr( $lease->deposit_settlement_notes ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Notas', 'arriendo-facil' ); ?>"><button class="button af-btn af-btn--primary"><?php esc_html_e( 'Calcular y guardar', 'arriendo-facil' ); ?></button></form></details></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table></div>
	</section>
</div>
<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( $nonce ); ?>;
	document.querySelectorAll('.af-inline-operation').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			const button = form.querySelector('button');
			button.disabled = true;
			const body = new URLSearchParams(new FormData(form));
			body.append('action', form.dataset.action);
			body.append('nonce', nonce);
			fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body })
				.then(function (response) { return response.json(); })
				.then(function (json) { if (!json || !json.success) { throw new Error((json && json.data && json.data.message) || 'Error'); } window.location.reload(); })
				.catch(function (error) { button.disabled = false; window.alert(error.message); });
		});
	});
}());
</script>
