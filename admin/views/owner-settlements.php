<?php
/**
 * Owner settlements admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$owners = Arriendo_Facil_Owner_Settlement::get_active_owners( Arriendo_Facil_Tenancy::accessible_accommodation_ids() );

$period_filter = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : gmdate( 'Y-m' );
if ( ! preg_match( '/^\d{4}-\d{2}$/', $period_filter ) ) {
	$period_filter = gmdate( 'Y-m' );
}

$selected_owner = isset( $_GET['owner_id'] ) ? absint( wp_unslash( $_GET['owner_id'] ) ) : 0;
if ( $selected_owner && ! in_array( $selected_owner, wp_list_pluck( $owners, 'id' ), true ) ) {
	$selected_owner = 0;
}
if ( ! $selected_owner && ! empty( $owners ) ) {
	$selected_owner = $owners[0]['id'];
}

$settlement = $selected_owner
	? Arriendo_Facil_Owner_Settlement::build( $selected_owner, $period_filter )
	: null;

$owner_user   = $selected_owner ? get_userdata( $selected_owner ) : null;
$charge_types = class_exists( 'Arriendo_Facil_Billing_Ledger' ) ? Arriendo_Facil_Billing_Ledger::charge_types() : array();
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Propietarios', 'arriendo-facil' ),
			'title'    => __( 'Liquidación al propietario', 'arriendo-facil' ),
			'subtitle' => __( 'Lo cobrado a los inquilinos, los gastos del periodo y el neto que le corresponde al propietario.', 'arriendo-facil' ),
		)
	);
	?>

	<?php if ( empty( $owners ) ) : ?>
		<div class="af-section" style="padding: var(--af-space-5);">
			<p>
				<?php
				printf(
					/* translators: %s: link to the properties list */
					esc_html__( 'Aún no hay propietarios con inmuebles asignados. Asigna un propietario al editar un inmueble en %s.', 'arriendo-facil' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=accommodation' ) ) . '">' . esc_html__( 'Inmuebles', 'arriendo-facil' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php else : ?>

		<form method="get" class="af-section" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding: var(--af-space-4) var(--af-space-5); margin-bottom: var(--af-space-4);">
			<input type="hidden" name="page" value="af-owner-settlements" />
			<label style="display:flex; align-items:center; gap:8px; font-weight:600;">
				<?php esc_html_e( 'Propietario', 'arriendo-facil' ); ?>
				<select name="owner_id" style="min-height:36px;">
					<?php foreach ( $owners as $owner ) : ?>
						<option value="<?php echo esc_attr( $owner['id'] ); ?>" <?php selected( $selected_owner, $owner['id'] ); ?>>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: owner name, 2: property count */
									_n( '%1$s (%2$d inmueble)', '%1$s (%2$d inmuebles)', $owner['property_count'], 'arriendo-facil' ),
									$owner['name'],
									$owner['property_count']
								)
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label style="display:flex; align-items:center; gap:8px; font-weight:600;">
				<?php esc_html_e( 'Periodo', 'arriendo-facil' ); ?>
				<input type="month" name="period" value="<?php echo esc_attr( $period_filter ); ?>" style="min-height:36px;" />
			</label>
			<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Ver liquidación', 'arriendo-facil' ); ?></button>
			<button type="button" class="button af-btn af-btn--ghost" onclick="window.print()"><?php esc_html_e( 'Imprimir', 'arriendo-facil' ); ?></button>
		</form>

		<?php if ( $settlement ) : ?>
			<div class="af-kpi-grid">
				<article class="af-kpi af-kpi--success">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Cobrado', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $settlement['collected'], 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Recibido de los inquilinos', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi <?php echo $settlement['pending'] > 0 ? 'af-kpi--attention' : ''; ?>">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Por cobrar', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $settlement['pending'], 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Aún no ingresa', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Gastos', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $settlement['expenses'], 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Mantenimiento del periodo', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi af-kpi--accent">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Neto a liquidar', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $settlement['net'], 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Cobrado menos gastos', 'arriendo-facil' ); ?></div>
				</article>
			</div>

			<section class="af-section">
				<header class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Ingresos del periodo', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: owner name, 2: period */
									__( '%1$s · %2$s', 'arriendo-facil' ),
									$owner_user ? $owner_user->display_name : '',
									$period_filter
								)
							);
							?>
						</p>
					</div>
				</header>

				<table class="wp-list-table widefat fixed striped af-data-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Concepto', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Facturado', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Cobrado', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $settlement['charges'] ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No hay cargos en este periodo para los inmuebles de este propietario.', 'arriendo-facil' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $settlement['charges'] as $charge ) : ?>
								<?php
								$charge_pill = 'af-pill--neutral';
								if ( 'paid' === $charge->status ) {
									$charge_pill = 'af-pill--success';
								} elseif ( 'overdue' === $charge->status ) {
									$charge_pill = 'af-pill--danger';
								} elseif ( 'partial' === $charge->status ) {
									$charge_pill = 'af-pill--warning';
								}
								?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><?php echo esc_html( $charge->accommodation_title ? $charge->accommodation_title : '—' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Concepto', 'arriendo-facil' ); ?>"><?php echo esc_html( $charge_types[ $charge->charge_type ] ?? $charge->charge_type ); ?></td>
									<td data-label="<?php esc_attr_e( 'Facturado', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $charge->amount, 2 ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Cobrado', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $charge->amount_paid, 2 ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>"><span class="af-pill <?php echo esc_attr( $charge_pill ); ?>"><?php echo esc_html( $charge->status ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>

			<section class="af-section">
				<header class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Gastos del periodo', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Incidencias completadas que se descuentan de la liquidación.', 'arriendo-facil' ); ?></p>
					</div>
				</header>

				<table class="wp-list-table widefat fixed striped af-data-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Detalle', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Costo', 'arriendo-facil' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $settlement['expenses_detail'] ) ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'Sin gastos registrados en este periodo.', 'arriendo-facil' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $settlement['expenses_detail'] as $expense ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><?php echo esc_html( $expense->accommodation_title ? $expense->accommodation_title : '—' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Detalle', 'arriendo-facil' ); ?>"><?php echo esc_html( $expense->notes ? $expense->notes : '—' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Fecha', 'arriendo-facil' ); ?>"><?php echo esc_html( (string) $expense->completed_date ); ?></td>
									<td data-label="<?php esc_attr_e( 'Costo', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) ( $expense->cost ?? 0 ), 2 ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>
	<?php endif; ?>
</div>
