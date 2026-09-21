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

	$signup_source        = get_user_meta( $user_id, 'af_signup_source', true );
	$doc_status           = get_user_meta( $user_id, 'af_admin_doc_status', true );
	$identity_match       = get_user_meta( $user_id, 'af_admin_identity_match_status', true );
	$admin_documents      = (array) get_user_meta( $user_id, 'af_admin_documents', true );
	$verified_by          = (int) get_user_meta( $user_id, 'af_admin_doc_verified_by', true );
	$verified_at          = get_user_meta( $user_id, 'af_admin_doc_verified_at', true );

	$doc_count   = count( array_filter( $admin_documents ) );
	$doc_status_effective = $doc_status ? (string) $doc_status : 'pendiente';
	$needs_review = 'verificado' !== $doc_status_effective;

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
		'signup_source'    => $signup_source ? $signup_source : 'manual',
		'doc_status'       => $doc_status ? $doc_status : 'pendiente',
		'identity_match'   => $identity_match ? $identity_match : 'not_checked',
		'doc_count'        => $doc_count,
		'needs_review'     => $needs_review,
		'doc_notes'        => get_user_meta( $user_id, 'af_admin_doc_notes', true ),
		'verified_by'      => $verified_by,
		'verified_at'      => $verified_at,
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

	<?php if ( ! empty( $rows ) ) : ?>
	<section class="af-section" aria-labelledby="af-chart-admins-title">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title" id="af-chart-admins-title"><?php esc_html_e( 'Cobranza por administrador (este mes)', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Comparativo de lo cobrado vs. lo pendiente por cada licencia.', 'arriendo-facil' ); ?></p>
			</div>
		</header>
		<div class="af-chart-canvas">
			<canvas id="af-chart-admins" role="img" aria-label="<?php esc_attr_e( 'Gráfico de cobranza por administrador', 'arriendo-facil' ); ?>"></canvas>
		</div>
	</section>
	<script type="application/json" id="af-admins-chart-data"><?php
		echo wp_json_encode(
			array(
				'labels'    => wp_list_pluck( wp_list_pluck( $rows, 'user' ), 'display_name' ),
				'collected' => array_map( 'floatval', wp_list_pluck( $rows, 'collected_period' ) ),
				'pending'   => array_map( 'floatval', wp_list_pluck( $rows, 'pending_period' ) ),
			)
		);
	?></script>
	<script>
	document.addEventListener( 'DOMContentLoaded', function() {
		if ( typeof Chart === 'undefined' ) {
			return;
		}
		var dataEl = document.getElementById( 'af-admins-chart-data' );
		var canvas = document.getElementById( 'af-chart-admins' );
		if ( ! dataEl || ! canvas ) {
			return;
		}
		var data = JSON.parse( dataEl.textContent );
		new Chart( canvas, {
			type: 'bar',
			data: {
				labels: data.labels,
				datasets: [
					{ label: '<?php echo esc_js( __( 'Cobrado', 'arriendo-facil' ) ); ?>', data: data.collected, backgroundColor: '#00A884' },
					{ label: '<?php echo esc_js( __( 'Pendiente', 'arriendo-facil' ) ); ?>', data: data.pending, backgroundColor: '#F59E0B' }
				]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				scales: { x: { stacked: false }, y: { beginAtZero: true } }
			}
		} );
	} );
	</script>
	<?php endif; ?>

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
					<th><?php esc_html_e( 'Verificación', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'Aún no hay administradores de propiedades licenciados.', 'arriendo-facil' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$admin_user = $row['user'];
						$is_active  = 'active' === $row['license_status'];
						$is_self    = 'self' === $row['signup_source'];
						$needs_review = $row['needs_review'];

						$doc_badges = array(
							'pendiente'   => array( 'af-pill--warning', __( 'Pendiente', 'arriendo-facil' ) ),
							'en_revision' => array( 'af-pill--info', __( 'En revisión', 'arriendo-facil' ) ),
							'verificado'  => array( 'af-pill--success', __( 'Verificado', 'arriendo-facil' ) ),
							'rechazado'   => array( 'af-pill--danger', __( 'Rechazado', 'arriendo-facil' ) ),
							'manual'      => array( 'af-pill', __( 'No aplica', 'arriendo-facil' ) ),
						);
						$badge = isset( $doc_badges[ $row['doc_status'] ] ) ? $doc_badges[ $row['doc_status'] ] : $doc_badges['pendiente'];
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
							<td data-label="<?php esc_attr_e( 'Verificación', 'arriendo-facil' ); ?>">
								<span class="af-pill <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
								<br />
								<span style="color:#666; font-size:12px;">
									<?php
									printf(
										/* translators: %s: signup source (Auto-registro or Manual) */
										esc_html__( 'Origen: %s', 'arriendo-facil' ),
										$is_self ? esc_html__( 'Auto-registro', 'arriendo-facil' ) : esc_html__( 'Manual', 'arriendo-facil' )
									);
									?>
									<?php if ( $needs_review ) : ?>
										&middot; <?php echo esc_html( number_format_i18n( $row['doc_count'] ) . '/3 ' . __( 'docs', 'arriendo-facil' ) ); ?>
									<?php endif; ?>
								</span>
								<?php if ( $needs_review && '' !== $row['doc_notes'] ) : ?>
									<div style="color:#8a6d1c; font-size:12px; margin-top:4px;"><?php echo esc_html( $row['doc_notes'] ); ?></div>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Acciones', 'arriendo-facil' ); ?>">
								<button type="button" class="button af-toggle-license" data-user-id="<?php echo esc_attr( $admin_user->ID ); ?>" data-next-status="<?php echo $is_active ? 'suspended' : 'active'; ?>">
									<?php echo $is_active ? esc_html__( 'Suspender', 'arriendo-facil' ) : esc_html__( 'Activar', 'arriendo-facil' ); ?>
								</button>
								<?php if ( $needs_review ) : ?>
									<button type="button" class="button af-review-admin" data-user-id="<?php echo esc_attr( $admin_user->ID ); ?>" data-user-name="<?php echo esc_attr( $row['company'] ? $row['company'] : $admin_user->display_name ); ?>">
										<?php esc_html_e( 'Revisar', 'arriendo-facil' ); ?>
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<div id="af-admin-review-modal" style="display:none;">
	<div id="af-admin-review-backdrop" style="position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9990;"></div>
	<div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:9991; background:#fff; border-radius:12px; padding:24px; width:min(480px, 92vw); box-shadow:0 20px 50px rgba(0,0,0,.3);">
		<h2 style="margin-top:0;" id="af-admin-review-title"><?php esc_html_e( 'Revisar verificación', 'arriendo-facil' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Revisa la documentación del administrador y aprueba, rechaza o reinicia su verificación.', 'arriendo-facil' ); ?></p>
		<div id="af-admin-review-body" style="max-height:50vh; overflow:auto; margin-bottom:12px; border:1px solid #e5e7eb; border-radius:8px; padding:12px;">
			<p class="description" style="margin:0;"><?php esc_html_e( 'Cargando información...', 'arriendo-facil' ); ?></p>
		</div>
		<label style="display:block; margin-bottom:12px;">
			<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Observaciones (se envían al usuario)', 'arriendo-facil' ); ?></span>
			<textarea id="af-admin-review-notes" rows="3" class="large-text" maxlength="1000" placeholder="<?php esc_attr_e( 'Comentarios opcionales para el administrador...', 'arriendo-facil' ); ?>"></textarea>
		</label>
		<div style="display:flex; gap:8px; flex-wrap:wrap;">
			<button type="button" class="button button-primary" data-review-action="approve"><?php esc_html_e( 'Aprobar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button" data-review-action="reject"><?php esc_html_e( 'Rechazar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button" data-review-action="reset"><?php esc_html_e( 'Reiniciar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button" data-recompute-status><?php esc_html_e( 'Recomputar estado', 'arriendo-facil' ); ?></button>
			<button type="button" class="button" data-review-close style="margin-left:auto;"><?php esc_html_e( 'Cerrar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
<style>
		#af-admin-review-body h3 { margin: 14px 0 8px; font-size: 14px; }
		.af-review-info { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 16px; }
		.af-review-info__row { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; border-bottom: 1px solid #f3f4f6; padding: 4px 0; }
		.af-review-info__label { color: #6b7280; flex: 0 0 auto; }
		.af-review-info__value { font-weight: 600; text-align: right; overflow-wrap: anywhere; }
		.af-review-doc { display: flex; align-items: center; gap: 10px; font-size: 13px; padding: 8px 0; border-bottom: 1px solid #f3f4f6; flex-wrap: wrap; }
		.af-review-doc.is-missing { opacity: .7; }
		.af-review-doc__label { font-weight: 600; }
		.af-review-doc__meta { color: #6b7280; font-size: 12px; }
		.af-review-notes { margin-top: 10px; padding: 8px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; font-size: 13px; color: #8a6d1c; }
	</style>
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

	const reviewModal = document.getElementById('af-admin-review-modal');
	const reviewNotes = document.getElementById('af-admin-review-notes');
	const reviewTitle = document.getElementById('af-admin-review-title');
	const reviewBody = document.getElementById('af-admin-review-body');
	let reviewUserId = 0;

	function escHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function fmtSize(bytes) {
		const kb = Math.round((parseInt(bytes, 10) || 0) / 1024);
		return kb < 1024 ? kb + ' KB' : (kb / 1024).toFixed(1).replace('.', ',') + ' MB';
	}
	function renderReviewDetail(d) {
		const idLabels = { cedula: 'Cédula', ruc: 'RUC', pasaporte: 'Pasaporte' };
		const matchLabels = { coincide: 'Coincide', no_coincide: 'No coincide', not_checked: 'Sin comprobar' };
		const docStatusLabels = { pendiente: 'Pendiente', en_revision: 'En revisión', verificado: 'Verificado', rechazado: 'Rechazado' };
		const licenseLabels = { active: 'Activa', suspended: 'Suspendida' };
		const sourceLabels = { self: 'Auto-registro', manual: 'Manual' };

		const rows = [
			['Empresa / Marca', d.company],
			['Nombre de contacto', d.contact_name],
			['Teléfono', d.phone],
			['Correo', d.email],
			['Nacionalidad', d.nationality],
			['Ciudad de nacimiento', d.birth_city],
			['Identificación', (idLabels[d.id_type] || d.id_type || '') + (d.id_masked ? ' ' + d.id_masked : '')],
			['Origen', sourceLabels[d.signup_source] || d.signup_source],
			['Licencia', licenseLabels[d.license_status] || d.license_status],
			['Estado de documentación', docStatusLabels[d.doc_status] || d.doc_status],
			['Coincidencia de identidad', matchLabels[d.identity_match] || d.identity_match]
		];
		if (d.verified_by) {
			rows.push(['Verificado por', d.verified_by + (d.verified_at ? ' · ' + d.verified_at.replace('T', ' ') : '')]);
		}

		let html = '<div class="af-review-info">';
		rows.forEach(function (r) {
			html += '<div class="af-review-info__row"><span class="af-review-info__label">' + escHtml(r[0]) + '</span><span class="af-review-info__value">' + escHtml(r[1] || '—') + '</span></div>';
		});
		html += '</div>';

		html += '<h3><?php echo esc_js( __( 'Documentos de soporte', 'arriendo-facil' ) ); ?></h3><div>';
		(d.documents || []).forEach(function (doc) {
			html += '<div class="af-review-doc' + (doc.present ? '' : ' is-missing') + '">';
			html += '<span class="af-review-doc__label">' + escHtml(doc.label) + '</span>';
			if (doc.present) {
				html += '<span class="af-pill af-pill--success">Subido</span>';
				let meta = fmtSize(doc.file_size);
				if (doc.uploaded_at) { meta += ' · ' + escHtml(doc.uploaded_at); }
				html += '<span class="af-review-doc__meta">' + meta + '</span>';
				if (doc.download_url) {
					html += '<a class="button af-btn af-btn--ghost" href="' + escHtml(doc.download_url) + '" target="_blank" rel="noopener noreferrer"><?php echo esc_js( __( 'Ver PDF', 'arriendo-facil' ) ); ?></a>';
				} else {
					html += '<span class="af-review-doc__meta"><?php echo esc_js( __( 'Sin enlace disponible', 'arriendo-facil' ) ); ?></span>';
				}
			} else {
				html += '<span class="af-pill af-pill--warning">Pendiente</span>';
			}
			html += '</div>';
		});
		html += '</div>';

		if (d.doc_notes) {
			html += '<div class="af-review-notes"><strong><?php echo esc_js( __( 'Observaciones actuales:', 'arriendo-facil' ) ); ?></strong> ' + escHtml(d.doc_notes) + '</div>';
		}

		return html;
	}
	function closeReview() { reviewModal.style.display = 'none'; }

	document.querySelectorAll('.af-review-admin').forEach(function (btn) {
		btn.addEventListener('click', function () {
			reviewUserId = parseInt(btn.dataset.userId, 10) || 0;
			reviewTitle.textContent = '<?php echo esc_js( __( 'Revisar verificación de', 'arriendo-facil' ) ); ?> ' + (btn.dataset.userName || '');
			reviewNotes.value = '';
			if (reviewBody) {
				reviewBody.innerHTML = '<p class="description" style="margin:0;"><?php echo esc_js( __( 'Cargando información...', 'arriendo-facil' ) ); ?></p>';
			}
			reviewModal.style.display = 'block';
			if (reviewUserId) {
				post('af_admin_review_detail', { user_id: reviewUserId }).then(function (res) {
					if (!reviewBody) { return; }
					if (res && res.success) {
						reviewBody.innerHTML = renderReviewDetail(res.data);
					} else {
						reviewBody.innerHTML = '<p style="color:#b45309;">' + escHtml((res && res.data && res.data.message) ? res.data.message : '<?php echo esc_js( __( 'Error al cargar la información.', 'arriendo-facil' ) ); ?>') + '</p>';
					}
				});
			}
		});
	});

	document.querySelector('[data-review-close]').addEventListener('click', closeReview);
	document.getElementById('af-admin-review-backdrop').addEventListener('click', closeReview);

	document.querySelector('[data-recompute-status]').addEventListener('click', function () {
		if (!reviewUserId) { return; }
		const btn = document.querySelector('[data-recompute-status]');
		btn.disabled = true;
		post('af_admin_recompute_status', { user_id: reviewUserId }).then((res) => {
			if (res && res.success) {
				window.location.reload();
			} else {
				btn.disabled = false;
				alert((res && res.data && res.data.message) ? res.data.message : 'Error');
			}
		});
	});

	document.querySelectorAll('[data-review-action]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!reviewUserId) { return; }
			if (btn.dataset.reviewAction === 'approve'
				&& !window.confirm('<?php echo esc_js( __( 'Aprobar la verificación de este administrador? Se habilitará para usar datos reales.', 'arriendo-facil' ) ); ?>')) {
				return;
			}
			btn.disabled = true;
			post('af_review_admin_verification', {
				user_id: reviewUserId,
				action_type: btn.dataset.reviewAction,
				notes: reviewNotes.value
			}).then((res) => {
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
