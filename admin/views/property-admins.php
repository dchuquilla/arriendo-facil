<?php
/**
 * Super-admin master panel: property-admin (subadmin) licenses and their
 * aggregated metrics across the platform.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permiso para ver esta página.', 'arriendo-facil' ) );
}

global $wpdb;

$property_admins = Arriendo_Facil_Tenancy::get_property_admins();
$current_period  = gmdate( 'Y-m' );

$rows = array();
foreach ( $property_admins as $admin_user ) {
	$user_id           = (int) $admin_user->ID;
	$accommodation_ids = array_map( 'absint', Arriendo_Facil_Accommodation::get_owner_accommodation_ids( $user_id ) );
	$ids_sql           = ! empty( $accommodation_ids ) ? implode( ',', $accommodation_ids ) : '';

	$buildings_count = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}af_buildings WHERE owner_id = %d", $user_id )
	);

	$active_leases = 0;
	$collected     = 0.0;
	$pending       = 0.0;

	if ( '' !== $ids_sql ) {
		$active_leases = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($ids_sql) AND status = 'active' AND deleted_at IS NULL"
		);

		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount_paid), 0) AS paid, COALESCE(SUM(amount - amount_paid), 0) AS pending
				 FROM {$wpdb->prefix}af_charges
				 WHERE period = %s AND status != 'void'
				   AND lease_id IN (SELECT id FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($ids_sql))",
				$current_period
			)
		);
		$collected = $totals ? (float) $totals->paid : 0.0;
		$pending   = $totals ? (float) $totals->pending : 0.0;
	}

	$license_status = get_user_meta( $user_id, 'af_license_status', true );
	$license_status  = $license_status ? $license_status : 'active';

	$rows[] = array(
		'user'             => $admin_user,
		'company'          => get_user_meta( $user_id, 'af_company_name', true ),
		'phone'             => get_user_meta( $user_id, 'af_contact_phone', true ),
		'buildings'        => $buildings_count,
		'accommodations'   => count( $accommodation_ids ),
		'active_leases'    => $active_leases,
		'collected_period' => $collected,
		'pending_period'   => $pending,
		'license_status'   => $license_status,
	);
}

$totals_platform = array(
	'admins'      => count( $rows ),
	'buildings'   => array_sum( wp_list_pluck( $rows, 'buildings' ) ),
	'active'      => array_sum( wp_list_pluck( $rows, 'active_leases' ) ),
	'collected'   => array_sum( wp_list_pluck( $rows, 'collected_period' ) ),
);
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Panel maestro', 'arriendo-facil' ),
			'title'    => __( 'Administradores de Propiedades', 'arriendo-facil' ),
			'subtitle' => __( 'Cuentas licenciadas de administradores de propiedades (subadmins) y sus métricas agregadas. Solo visible para el super admin.', 'arriendo-facil' ),
		)
	);
	?>

	<div class="af-kpi-grid" role="list">
		<article class="af-kpi" role="listitem">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Licencias activas', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $totals_platform['admins'] ) ); ?></div>
		</article>
		<article class="af-kpi" role="listitem">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Edificios en la plataforma', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $totals_platform['buildings'] ) ); ?></div>
		</article>
		<article class="af-kpi" role="listitem">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Contratos activos', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $totals_platform['active'] ) ); ?></div>
		</article>
		<article class="af-kpi af-kpi--success" role="listitem">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Cobrado este mes (todos)', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $totals_platform['collected'], 2 ) ); ?></div>
		</article>
	</div>

	<div class="af-section" style="padding: var(--af-space-5); margin: var(--af-space-4) 0;">
		<h2 class="af-section__title" style="margin-top:0;"><?php esc_html_e( 'Nueva licencia (administrador de propiedades)', 'arriendo-facil' ); ?></h2>
		<p class="af-modal__status" id="af-property-admin-status"></p>
		<form id="af-property-admin-form" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Empresa / Marca', 'arriendo-facil' ); ?></span>
				<input type="text" name="company_name" class="regular-text" style="width:100%;" />
			</label>
			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Nombre de contacto', 'arriendo-facil' ); ?> *</span>
				<input type="text" name="contact_name" required class="regular-text" style="width:100%;" />
			</label>
			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Correo (será el usuario)', 'arriendo-facil' ); ?> *</span>
				<input type="email" name="email" required class="regular-text" style="width:100%;" />
			</label>
			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Teléfono', 'arriendo-facil' ); ?></span>
				<input type="text" name="phone" class="regular-text" style="width:100%;" />
			</label>
			<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Crear licencia', 'arriendo-facil' ); ?></button>
		</form>
		<div id="af-property-admin-credentials" style="display:none; margin-top:12px; padding:12px; border-radius:8px; background:#f0f6fc; border:1px solid #c3d4e0;"></div>
	</div>

	<div class="af-section">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Licencias existentes', 'arriendo-facil' ); ?></h2>
			</div>
		</header>

		<table class="wp-list-table widefat fixed striped af-data-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Administrador', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Edificios', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Inmuebles', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Contratos activos', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Cobrado / Pendiente (mes)', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Licencia', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Aún no hay administradores de propiedades licenciados.', 'arriendo-facil' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$admin_user = $row['user'];
						$is_active  = 'active' === $row['license_status'];
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Administrador', 'arriendo-facil' ); ?>">
								<strong><?php echo esc_html( $row['company'] ? $row['company'] : $admin_user->display_name ); ?></strong><br />
								<span style="color:#666;"><?php echo esc_html( $admin_user->user_email ); ?></span>
							</td>
							<td data-label="<?php esc_attr_e( 'Edificios', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( $row['buildings'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Inmuebles', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( $row['accommodations'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Contratos activos', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( $row['active_leases'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Cobrado / Pendiente', 'arriendo-facil' ); ?>">
								$<?php echo esc_html( number_format_i18n( $row['collected_period'], 2 ) ); ?> /
								$<?php echo esc_html( number_format_i18n( $row['pending_period'], 2 ) ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Licencia', 'arriendo-facil' ); ?>">
								<span class="af-pill <?php echo $is_active ? 'af-pill--success' : 'af-pill--danger'; ?>">
									<?php echo $is_active ? esc_html__( 'Activa', 'arriendo-facil' ) : esc_html__( 'Suspendida', 'arriendo-facil' ); ?>
								</span>
							</td>
							<td data-label="<?php esc_attr_e( 'Acciones', 'arriendo-facil' ); ?>">
								<button type="button" class="button af-toggle-license" data-user-id="<?php echo esc_attr( $admin_user->ID ); ?>" data-next-status="<?php echo $is_active ? 'suspended' : 'active'; ?>">
									<?php echo $is_active ? esc_html__( 'Suspender', 'arriendo-facil' ) : esc_html__( 'Activar', 'arriendo-facil' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_property_admin_nonce' ) ); ?>;

	function post(action, params) {
		const body = new URLSearchParams(params);
		body.append('action', action);
		body.append('nonce', nonce);
		return fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then((r) => r.json());
	}

	const form = document.getElementById('af-property-admin-form');
	const status = document.getElementById('af-property-admin-status');
	const credsBox = document.getElementById('af-property-admin-credentials');

	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			const btn = form.querySelector('button[type="submit"]');
			btn.disabled = true;
			status.textContent = '';
			credsBox.style.display = 'none';

			post('af_create_property_admin', new FormData(form)).then((res) => {
				btn.disabled = false;
				if (res && res.success) {
					status.textContent = res.data.message;
					credsBox.style.display = 'block';
					credsBox.innerHTML = '<strong><?php echo esc_js( __( 'Credenciales (compártelas de forma segura, no se reenviarán):', 'arriendo-facil' ) ); ?></strong><br>' +
						'<?php echo esc_js( __( 'Usuario:', 'arriendo-facil' ) ); ?> <code>' + res.data.username + '</code><br>' +
						'<?php echo esc_js( __( 'Contraseña temporal:', 'arriendo-facil' ) ); ?> <code>' + res.data.password + '</code>';
					form.reset();
					setTimeout(() => window.location.reload(), 4000);
				} else {
					status.textContent = (res && res.data && res.data.message) ? res.data.message : '<?php echo esc_js( __( 'Error inesperado.', 'arriendo-facil' ) ); ?>';
				}
			});
		});
	}

	document.querySelectorAll('.af-toggle-license').forEach(function (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			post('af_set_property_admin_status', { user_id: btn.dataset.userId, status: btn.dataset.nextStatus }).then((res) => {
				if (res && res.success) {
					window.location.reload();
				} else {
					btn.disabled = false;
					alert((res && res.data && res.data.message) ? res.data.message : 'Error');
				}
			});
		});
	});
})();
</script>
