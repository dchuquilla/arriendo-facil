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

// Batch-load every per-admin metric (one query per metric, not per admin).
$admin_ids           = array_map( 'absint', wp_list_pluck( $property_admins, 'ID' ) );
$accommodation_count = array();
$buildings_by_admin  = array();
$leases_by_admin     = array();
$totals_by_admin     = array();

if ( ! empty( $admin_ids ) ) {
	update_meta_cache( 'user', $admin_ids );

	$admin_ids_sql = implode( ',', $admin_ids );
	// meta_value is a string column: quote the IDs so the (meta_key, meta_value) index is usable.
	$admin_meta_in = "'" . implode( "','", $admin_ids ) . "'";
	// Same scope as Arriendo_Facil_Accommodation::get_owner_accommodation_ids() (post_status 'any').
	$owned_join = static function ( $accommodation_column ) use ( $wpdb, $admin_meta_in ) {
		return "INNER JOIN {$wpdb->postmeta} owner_pm ON owner_pm.post_id = {$accommodation_column} AND owner_pm.meta_key = '_af_owner_id' AND owner_pm.meta_value IN ({$admin_meta_in})
		        INNER JOIN {$wpdb->posts} owner_p ON owner_p.ID = owner_pm.post_id AND owner_p.post_type = 'accommodation' AND owner_p.post_status NOT IN ('trash','auto-draft')";
	};

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	foreach ( (array) $wpdb->get_results(
		"SELECT owner_pm.meta_value AS owner_id, COUNT(DISTINCT owner_p.ID) AS total
		 FROM {$wpdb->posts} acc " . $owned_join( 'acc.ID' ) . '
		 GROUP BY owner_pm.meta_value'
	) as $count_row ) {
		$accommodation_count[ (int) $count_row->owner_id ] = (int) $count_row->total;
	}

	foreach ( (array) $wpdb->get_results( "SELECT owner_id, COUNT(*) AS total FROM {$wpdb->prefix}af_buildings WHERE owner_id IN ({$admin_ids_sql}) GROUP BY owner_id" ) as $count_row ) {
		$buildings_by_admin[ (int) $count_row->owner_id ] = (int) $count_row->total;
	}

	foreach ( (array) $wpdb->get_results(
		"SELECT owner_pm.meta_value AS owner_id, COUNT(DISTINCT l.id) AS total
		 FROM {$wpdb->prefix}af_leases l " . $owned_join( 'l.accommodation_id' ) . "
		 WHERE l.status = 'active' AND l.deleted_at IS NULL
		 GROUP BY owner_pm.meta_value"
	) as $count_row ) {
		$leases_by_admin[ (int) $count_row->owner_id ] = (int) $count_row->total;
	}

	foreach ( (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT owner_pm.meta_value AS owner_id,
			        COALESCE(SUM(c.amount_paid), 0) AS paid,
			        COALESCE(SUM(c.amount - c.amount_paid), 0) AS pending
			 FROM {$wpdb->prefix}af_charges c
			 INNER JOIN {$wpdb->prefix}af_leases l ON l.id = c.lease_id " . $owned_join( 'l.accommodation_id' ) . "
			 WHERE c.period = %s AND c.status != 'void'
			 GROUP BY owner_pm.meta_value",
			$current_period
		)
	) as $total_row ) {
		$totals_by_admin[ (int) $total_row->owner_id ] = $total_row;
	}
	// phpcs:enable
}

$rows = array();
foreach ( $property_admins as $admin_user ) {
	$user_id         = (int) $admin_user->ID;
	if ( Arriendo_Facil_Account_Deletion::is_deactivated( $user_id ) ) {
		continue;
	}
	$buildings_count = isset( $buildings_by_admin[ $user_id ] ) ? $buildings_by_admin[ $user_id ] : 0;
	$active_leases   = isset( $leases_by_admin[ $user_id ] ) ? $leases_by_admin[ $user_id ] : 0;
	$collected       = isset( $totals_by_admin[ $user_id ] ) ? (float) $totals_by_admin[ $user_id ]->paid : 0.0;
	$pending         = isset( $totals_by_admin[ $user_id ] ) ? (float) $totals_by_admin[ $user_id ]->pending : 0.0;

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
		'contact_name'     => get_user_meta( $user_id, 'af_contact_name', true ),
		'company'          => get_user_meta( $user_id, 'af_company_name', true ),
		'phone'             => get_user_meta( $user_id, 'af_contact_phone', true ),
		'buildings'        => $buildings_count,
		'accommodations'   => isset( $accommodation_count[ $user_id ] ) ? $accommodation_count[ $user_id ] : 0,
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
			'title'    => __( 'Gestores de Propiedades', 'arriendo-facil' ),
			'subtitle' => __( 'Cuentas licenciadas de gestores de propiedades y sus métricas agregadas. Solo visible para el administrador de la plataforma.', 'arriendo-facil' ),
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
				<h2 class="af-section__title" id="af-chart-admins-title"><?php esc_html_e( 'Cobranza por gestor (este mes)', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Comparativo de lo cobrado vs. lo pendiente por cada licencia.', 'arriendo-facil' ); ?></p>
			</div>
		</header>
		<div class="af-chart-canvas">
			<canvas id="af-chart-admins" role="img" aria-label="<?php esc_attr_e( 'Gráfico de cobranza por gestor', 'arriendo-facil' ); ?>"></canvas>
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
					{ label: '<?php echo esc_js( __( 'Cobrado', 'arriendo-facil' ) ); ?>', data: data.collected, backgroundColor: '#164A55' },
					{ label: '<?php echo esc_js( __( 'Pendiente', 'arriendo-facil' ) ); ?>', data: data.pending, backgroundColor: '#D89B5B' }
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
		<h2 class="af-section__title" style="margin-top:0;"><?php esc_html_e( 'Nueva licencia (gestor de propiedades)', 'arriendo-facil' ); ?></h2>
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
			<label>
				<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Plan', 'arriendo-facil' ); ?></span>
				<select name="plan" style="width:100%;">
					<?php foreach ( Arriendo_Facil_Plans::definitions() as $plan_key => $plan_def ) : ?>
						<option value="<?php echo esc_attr( $plan_key ); ?>"><?php echo esc_html( $plan_def['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Crear licencia', 'arriendo-facil' ); ?></button>
		</form>
		<div id="af-property-admin-credentials" class="af-creds" role="region" aria-live="polite" aria-labelledby="af-creds-title" hidden>
			<p class="af-creds__title" id="af-creds-title"><?php esc_html_e( 'Credenciales del nuevo gestor', 'arriendo-facil' ); ?></p>
			<p class="af-creds__hint"><?php esc_html_e( 'Cópialas y compártelas por un canal seguro: no se volverán a mostrar ni se reenvían. El gestor deberá cambiar la contraseña en su primer ingreso. Por seguridad se ocultan solas en 10 minutos o al salir de esta pantalla.', 'arriendo-facil' ); ?></p>
			<div class="af-creds__row">
				<span class="af-creds__label"><?php esc_html_e( 'Usuario', 'arriendo-facil' ); ?></span>
				<code class="af-creds__value" data-af-cred="username"></code>
				<button type="button" class="button af-creds__copy" data-af-copy="username"><?php esc_html_e( 'Copiar', 'arriendo-facil' ); ?></button>
			</div>
			<div class="af-creds__row">
				<span class="af-creds__label"><?php esc_html_e( 'Contraseña temporal', 'arriendo-facil' ); ?></span>
				<code class="af-creds__value" data-af-cred="password" aria-label="<?php esc_attr_e( 'Contraseña oculta', 'arriendo-facil' ); ?>">••••••••••••••</code>
				<button type="button" class="button af-creds__copy" data-af-copy="password"><?php esc_html_e( 'Copiar', 'arriendo-facil' ); ?></button>
				<button type="button" class="button" data-af-reveal aria-pressed="false"><?php esc_html_e( 'Mostrar', 'arriendo-facil' ); ?></button>
			</div>
			<div class="af-creds__actions">
				<button type="button" class="button" data-af-copy="both"><?php esc_html_e( 'Copiar ambos', 'arriendo-facil' ); ?></button>
				<button type="button" class="button button-primary" data-af-creds-done><?php esc_html_e( 'Listo, ya las guardé', 'arriendo-facil' ); ?></button>
			</div>
			<style>
				.af-creds { margin-top: 16px; padding: 16px; border: 1px solid var(--af-border, #E1E9E7); border-radius: var(--af-radius-md, 8px); background: var(--af-accent-soft, #EEF5F3); }
				.af-creds[hidden] { display: none; }
				.af-creds__title { margin: 0; font-weight: 600; font-size: 15px; }
				.af-creds__hint { margin: 4px 0 12px; color: var(--af-text-muted, #4F6267); }
				.af-creds__row { display: grid; grid-template-columns: 150px minmax(0, 1fr) auto auto; align-items: center; gap: 8px 12px; padding: 8px 0; border-top: 1px solid var(--af-border, #E1E9E7); }
				.af-creds__label { color: var(--af-text-muted, #4F6267); }
				.af-creds__value { padding: 6px 10px; border-radius: 6px; background: #fff; font-size: 15px; overflow-wrap: anywhere; }
				.af-creds__copy.is-copied { border-color: var(--af-positive, #1F6E63); color: var(--af-positive, #1F6E63); }
				.af-creds__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; margin-top: 12px; }
				@media (max-width: 600px) {
					.af-creds__row { grid-template-columns: minmax(0, 1fr) auto auto; }
					.af-creds__label { grid-column: 1 / -1; }
					.af-creds__actions .button { flex: 1 1 auto; }
				}
			</style>
		</div>
	</div>

	<div class="af-plan-wa">
		<strong><?php esc_html_e( 'WhatsApp para comprobantes de pago', 'arriendo-facil' ); ?></strong>
		<?php $wa_masked = Arriendo_Facil_Plan_Billing::whatsapp_masked(); ?>
		<input type="tel" id="af-plan-wa-input" inputmode="tel" autocomplete="off" placeholder="<?php echo esc_attr( '' !== $wa_masked ? $wa_masked : '+593…' ); ?>" aria-label="<?php esc_attr_e( 'Número de WhatsApp con código de país', 'arriendo-facil' ); ?>" />
		<button type="button" class="button af-btn af-btn--ghost" data-af-plan-save-wa><?php esc_html_e( 'Guardar número', 'arriendo-facil' ); ?></button>
		<span style="color:#647579;"><?php esc_html_e( 'Nunca se muestra a los gestores: solo se usa al abrir WhatsApp desde "Mi plan".', 'arriendo-facil' ); ?></span>
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
					<th><?php esc_html_e( 'Gestor', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Edificios', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Inmuebles', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Contratos activos', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Cobrado / Pendiente (mes)', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Plan', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Licencia', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Verificación', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'Aún no hay gestores de propiedades licenciados.', 'arriendo-facil' ); ?></td></tr>
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
							<td data-label="<?php esc_attr_e( 'Gestor', 'arriendo-facil' ); ?>">
								<strong><?php echo esc_html( $row['contact_name'] ? $row['contact_name'] : $admin_user->display_name ); ?></strong><br />
								<span style="color:#666;"><?php echo esc_html( $admin_user->user_email ); ?></span>
							</td>
							<td data-label="<?php esc_attr_e( 'Edificios', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( $row['buildings'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Inmuebles', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( $row['accommodations'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Contratos activos', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( $row['active_leases'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Cobrado / Pendiente', 'arriendo-facil' ); ?>">
								$<?php echo esc_html( number_format_i18n( $row['collected_period'], 2 ) ); ?> /
								$<?php echo esc_html( number_format_i18n( $row['pending_period'], 2 ) ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Plan', 'arriendo-facil' ); ?>">
								<?php
								$row_uid     = (int) $admin_user->ID;
								$row_plan    = Arriendo_Facil_Plans::get_plan( $row_uid );
								$row_billing = Arriendo_Facil_Plan_Billing::admin_row( $row_uid );
								$row_request = Arriendo_Facil_Plans::get_request( $row_uid );
								?>
								<div class="af-plan-cell">
									<select class="af-plan-select" data-user-id="<?php echo esc_attr( (string) $row_uid ); ?>" aria-label="<?php esc_attr_e( 'Plan del gestor', 'arriendo-facil' ); ?>">
										<?php foreach ( Arriendo_Facil_Plans::definitions() as $plan_key => $plan_def ) : ?>
											<option value="<?php echo esc_attr( $plan_key ); ?>" <?php selected( $row_plan, $plan_key ); ?>><?php echo esc_html( $plan_def['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php if ( '' !== $row_billing['label'] ) : ?>
										<span class="af-plan-due<?php echo '' !== $row_billing['tone'] ? ' is-' . esc_attr( $row_billing['tone'] ) : ''; ?>"><?php echo esc_html( $row_billing['label'] ); ?></span>
									<?php endif; ?>
									<?php if ( $row_billing['can_renew'] ) : ?>
										<button type="button" class="af-plan-renew" data-user-id="<?php echo esc_attr( (string) $row_uid ); ?>" data-expires="<?php echo esc_attr( (string) $row_billing['expires'] ); ?>"><?php echo esc_html( isset( $row_billing['cta'] ) ? $row_billing['cta'] : __( 'Renovar 1 mes', 'arriendo-facil' ) ); ?></button>
									<?php endif; ?>
									<?php if ( $row_request ) : ?>
										<span class="af-plan-badge-request"><?php echo esc_html( sprintf( /* translators: %s: plan */ __( 'Solicita: %s', 'arriendo-facil' ), Arriendo_Facil_Plans::definitions()[ $row_request['plan'] ]['label'] ) ); ?></span>
									<?php endif; ?>
								</div>
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
								<button type="button" class="button af-btn--danger af-delete-license" data-user-id="<?php echo esc_attr( $admin_user->ID ); ?>" data-user-name="<?php echo esc_attr( $row['contact_name'] ? $row['contact_name'] : $admin_user->display_name ); ?>" data-user-email="<?php echo esc_attr( $admin_user->user_email ); ?>">
									<?php esc_html_e( 'Eliminar', 'arriendo-facil' ); ?>
								</button>
								<?php if ( $needs_review ) : ?>
									<button type="button" class="button af-review-admin" data-user-id="<?php echo esc_attr( $admin_user->ID ); ?>" data-user-name="<?php echo esc_attr( $row['contact_name'] ? $row['contact_name'] : $admin_user->display_name ); ?>">
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

	<?php Arriendo_Facil_Account_Deletion::render_admin_section(); ?>
</div>

<div class="af-modal af-delete-license-modal" id="af-delete-license-modal" role="dialog" aria-modal="true" aria-labelledby="af-delete-license-title" hidden>
	<div class="af-modal__backdrop" data-af-delete-close></div>
	<div class="af-modal__dialog">
		<h2 class="af-modal__title" id="af-delete-license-title"><?php esc_html_e( 'Eliminar licencia', 'arriendo-facil' ); ?></h2>
		<p><?php esc_html_e( 'Vas a eliminar la licencia de', 'arriendo-facil' ); ?> <strong data-af-delete-name></strong>.</p>
		<ul class="af-delete-license-modal__effects">
			<li><?php esc_html_e( 'Pierde el acceso de inmediato y se cierran todas sus sesiones.', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Sus inmuebles dejan de publicarse.', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'La cuenta pasa a "Cuentas dadas de baja": puedes reactivarla durante 30 días; después puedes borrarla definitivamente.', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Contratos y facturas se conservan mientras dure la retención legal.', 'arriendo-facil' ); ?></li>
		</ul>
		<label class="af-delete-license-modal__confirm" for="af-delete-license-input">
			<?php esc_html_e( 'Para confirmar, escribe el correo del gestor:', 'arriendo-facil' ); ?>
			<code data-af-delete-email></code>
		</label>
		<input type="email" id="af-delete-license-input" autocomplete="off" spellcheck="false" />
		<p class="af-modal__status" id="af-delete-license-status" role="alert"></p>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-delete-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button af-btn--danger" id="af-delete-license-confirm" disabled><?php esc_html_e( 'Eliminar licencia', 'arriendo-facil' ); ?></button>
		</div>
	</div>
	<style>
		.af-delete-license-modal { position: fixed; inset: 0; z-index: 100080; display: flex; align-items: center; justify-content: center; }
		.af-delete-license-modal[hidden] { display: none; }
		.af-delete-license-modal .af-modal__backdrop { position: absolute; inset: 0; }
		.af-delete-license-modal *, .af-delete-license-modal *::before { box-sizing: border-box; }
		.af-delete-license-modal .af-modal__dialog { position: relative; display: block; width: min(520px, 92vw); padding: 24px; }
		.af-delete-license-modal .af-modal__dialog::before { content: none; }
		.af-delete-license-modal .af-modal__footer .button { flex: 1 1 auto; }
		@media (min-width: 783px) { .af-delete-license-modal .af-modal__footer .button { flex: 0 0 auto; } }
		.af-delete-license-modal__effects { margin: 12px 0 16px; padding-left: 18px; list-style: disc; }
		.af-delete-license-modal__effects li { margin: 0 0 6px; }
		.af-delete-license-modal__confirm { display: block; margin-bottom: 8px; font-weight: 500; }
		.af-delete-license-modal__confirm code { display: inline-block; margin-top: 4px; overflow-wrap: anywhere; }
		.af-delete-license-modal input { width: 100%; }
		.af-delete-license-modal .af-modal__status { min-height: 1.4em; margin: 8px 0 0; color: var(--af-negative, #B03A36); }
		.af-delete-license-modal .af-modal__footer { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
	</style>
</div>

<div id="af-admin-review-modal" style="display:none;">
	<div id="af-admin-review-backdrop" style="position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9990;"></div>
	<div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:9991; background:#fff; border-radius:12px; padding:24px; width:min(480px, 92vw); box-shadow:0 20px 50px rgba(0,0,0,.3);">
		<h2 style="margin-top:0;" id="af-admin-review-title"><?php esc_html_e( 'Revisar verificación', 'arriendo-facil' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Revisa la documentación del gestor y aprueba, rechaza o reinicia su verificación.', 'arriendo-facil' ); ?></p>
		<div id="af-admin-review-body" style="max-height:50vh; overflow:auto; margin-bottom:12px; border:1px solid #dce5e3; border-radius:8px; padding:12px;">
			<p class="description" style="margin:0;"><?php esc_html_e( 'Cargando información...', 'arriendo-facil' ); ?></p>
		</div>
		<label style="display:block; margin-bottom:12px;">
			<span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Observaciones (se envían al usuario)', 'arriendo-facil' ); ?></span>
			<textarea id="af-admin-review-notes" rows="3" class="large-text" maxlength="1000" placeholder="<?php esc_attr_e( 'Comentarios opcionales para el gestor...', 'arriendo-facil' ); ?>"></textarea>
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
		.af-review-info__row { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; border-bottom: 1px solid #eef2f0; padding: 4px 0; }
		.af-review-info__label { color: #647579; flex: 0 0 auto; }
		.af-review-info__value { font-weight: 600; text-align: right; overflow-wrap: anywhere; }
		.af-review-doc { display: flex; align-items: center; gap: 10px; font-size: 13px; padding: 8px 0; border-bottom: 1px solid #eef2f0; flex-wrap: wrap; }
		.af-review-doc.is-missing { opacity: .7; }
		.af-review-doc__label { font-weight: 600; }
		.af-review-doc__meta { color: #647579; font-size: 12px; }
		.af-review-notes { margin-top: 10px; padding: 8px; background: #f5e9da; border: 1px solid #ebd3b3; border-radius: 6px; font-size: 13px; color: #8a6d1c; }
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
			credsBox.hidden = true;

			post('af_create_property_admin', new FormData(form)).then((res) => {
				btn.disabled = false;
				if (res && res.success) {
					status.textContent = res.data.message;
					showCreds(res.data.username, res.data.password);
					credsBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
					form.reset();
				} else {
					status.textContent = (res && res.data && res.data.message) ? res.data.message : '<?php echo esc_js( __( 'Error inesperado.', 'arriendo-facil' ) ); ?>';
				}
						});
		});
	}

	// Las credenciales viven solo en memoria de esta pestaña: nunca en el HTML,
	// localStorage ni la URL. La contraseña se muestra oculta y se borra sola.
	let creds = null;
	let credsTimer = null;
	const MASK = '••••••••••••••';
	const passEl = credsBox ? credsBox.querySelector('[data-af-cred="password"]') : null;
	const revealBtn = credsBox ? credsBox.querySelector('[data-af-reveal]') : null;

	function setReveal(on) {
		if (!creds || !passEl) { return; }
		passEl.textContent = on ? creds.p : MASK;
		passEl.setAttribute('aria-label', on ? '' : <?php echo wp_json_encode( __( 'Contraseña oculta', 'arriendo-facil' ) ); ?>);
		revealBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
		revealBtn.textContent = on ? <?php echo wp_json_encode( __( 'Ocultar', 'arriendo-facil' ) ); ?> : <?php echo wp_json_encode( __( 'Mostrar', 'arriendo-facil' ) ); ?>;
	}

	function wipeCreds() {
		creds = null;
		clearTimeout(credsTimer);
		if (!credsBox) { return; }
		credsBox.querySelector('[data-af-cred="username"]').textContent = '';
		passEl.textContent = MASK;
		credsBox.hidden = true;
	}

	function showCreds(user, pass) {
		creds = { u: user, p: pass };
		credsBox.querySelector('[data-af-cred="username"]').textContent = user;
		setReveal(false);
		credsBox.hidden = false;
		clearTimeout(credsTimer);
		credsTimer = setTimeout(wipeCreds, 10 * 60 * 1000);
	}

	if (credsBox) {
		const copyText = function (text) {
			if (navigator.clipboard && window.isSecureContext) {
				return navigator.clipboard.writeText(text);
			}
			// Sin HTTPS no hay API de portapapeles: se copia con una selección temporal que se elimina al instante.
			const area = document.createElement('textarea');
			area.value = text;
			area.setAttribute('readonly', '');
			area.setAttribute('aria-hidden', 'true');
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild(area);
			area.select();
			const ok = document.execCommand('copy');
			area.value = '';
			document.body.removeChild(area);
			return ok ? Promise.resolve() : Promise.reject();
		};

		credsBox.addEventListener('click', function (e) {
			if (e.target.closest('[data-af-reveal]')) {
				setReveal(revealBtn.getAttribute('aria-pressed') !== 'true');
				return;
			}
			const btn = e.target.closest('[data-af-copy]');
			if (btn && creds) {
				const which = btn.dataset.afCopy;
				const text = which === 'username' ? creds.u : (which === 'password' ? creds.p : <?php echo wp_json_encode( __( 'Usuario:', 'arriendo-facil' ) ); ?> + ' ' + creds.u + '\n' + <?php echo wp_json_encode( __( 'Contraseña temporal:', 'arriendo-facil' ) ); ?> + ' ' + creds.p);
				const label = btn.textContent;
				copyText(text).then(function () {
					btn.textContent = <?php echo wp_json_encode( __( 'Copiado ✓', 'arriendo-facil' ) ); ?>;
					btn.classList.add('is-copied');
					setTimeout(function () { btn.textContent = label; btn.classList.remove('is-copied'); }, 2000);
				}).catch(function () {
					setReveal(true);
					window.afNotify(<?php echo wp_json_encode( __( 'No se pudo copiar. Selecciona el texto y cópialo manualmente.', 'arriendo-facil' ) ); ?>);
				});
				return;
			}
			if (e.target.closest('[data-af-creds-done]')) {
				wipeCreds();
				window.location.reload();
			}
		});

		// Al salir o al guardarse en la caché de "atrás", no queda nada en pantalla.
		window.addEventListener('pagehide', wipeCreds);
	}

	document.querySelectorAll('.af-toggle-license').forEach(function (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			post('af_set_property_admin_status', { user_id: btn.dataset.userId, status: btn.dataset.nextStatus }).then((res) => {
				if (res && res.success) {
					window.location.reload();
				} else {
					btn.disabled = false;
					window.afNotify((res && res.data && res.data.message) ? res.data.message : 'Error');
				}
			});
		});
	});

	const delModal = document.getElementById('af-delete-license-modal');
	if (delModal) {
		const delInput = document.getElementById('af-delete-license-input');
		const delBtn = document.getElementById('af-delete-license-confirm');
		const delStatus = document.getElementById('af-delete-license-status');
		let delTarget = null;

		const closeDel = function () {
			delModal.hidden = true;
			if (delTarget) { delTarget.focus(); }
			delTarget = null;
		};

		document.querySelectorAll('.af-delete-license').forEach(function (btn) {
			btn.addEventListener('click', function () {
				delTarget = btn;
				delModal.querySelector('[data-af-delete-name]').textContent = btn.dataset.userName;
				delModal.querySelector('[data-af-delete-email]').textContent = btn.dataset.userEmail;
				delInput.value = '';
				delStatus.textContent = '';
				delBtn.disabled = true;
				delModal.hidden = false;
				delInput.focus();
			});
		});

		delInput.addEventListener('input', function () {
			delBtn.disabled = !delTarget || delInput.value.trim().toLowerCase() !== delTarget.dataset.userEmail.toLowerCase();
		});

		delModal.querySelectorAll('[data-af-delete-close]').forEach(function (el) {
			el.addEventListener('click', closeDel);
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !delModal.hidden) { closeDel(); }
		});

		delBtn.addEventListener('click', function () {
			if (!delTarget) { return; }
			delBtn.disabled = true;
			delStatus.textContent = '';
			post('af_delete_property_admin', { user_id: delTarget.dataset.userId, confirm: delInput.value.trim() }).then(function (res) {
				if (res && res.success) {
					window.location.hash = 'af-deactivated-accounts';
					window.location.reload();
				} else {
					delBtn.disabled = false;
					delStatus.textContent = (res && res.data && res.data.message) ? res.data.message : <?php echo wp_json_encode( __( 'Error inesperado.', 'arriendo-facil' ) ); ?>;
				}
			}).catch(function () {
				delBtn.disabled = false;
				delStatus.textContent = <?php echo wp_json_encode( __( 'Error de red. Intenta nuevamente.', 'arriendo-facil' ) ); ?>;
			});
		});
	}

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
						reviewBody.innerHTML = '<p style="color:#9a6128;">' + escHtml((res && res.data && res.data.message) ? res.data.message : '<?php echo esc_js( __( 'Error al cargar la información.', 'arriendo-facil' ) ); ?>') + '</p>';
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
				window.afNotify((res && res.data && res.data.message) ? res.data.message : 'Error');
			}
		});
	});

	document.querySelectorAll('[data-review-action]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!reviewUserId) { return; }
			if (btn.dataset.reviewAction === 'approve'
				&& !window.confirm('<?php echo esc_js( __( 'Aprobar la verificación de este gestor? Se habilitará para usar datos reales.', 'arriendo-facil' ) ); ?>')) {
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
					window.afNotify((res && res.data && res.data.message) ? res.data.message : 'Error');
				}
			});
		});
	});
})();
</script>
