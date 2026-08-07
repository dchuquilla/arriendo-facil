<?php
/**
 * OTA Sync Dashboard
 *
 * Displays sync history, status, and statistics.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_user_id = get_current_user_id();
$is_admin        = current_user_can( 'manage_options' );

global $wpdb;

$filter_platform = isset( $_GET['filter_platform'] ) ? sanitize_key( wp_unslash( $_GET['filter_platform'] ) ) : '';
$filter_status   = isset( $_GET['filter_status'] ) ? sanitize_key( wp_unslash( $_GET['filter_status'] ) ) : '';

$where      = array( '1=1' );
$where_args = array();
if ( 'booking' === $filter_platform || 'airbnb' === $filter_platform ) {
	$where[]      = 'ota_source = %s';
	$where_args[] = $filter_platform;
}
if ( 'success' === $filter_status || 'failed' === $filter_status ) {
	$where[]      = 'status = %s';
	$where_args[] = $filter_status;
}
$where_sql = implode( ' AND ', $where );

$table = $wpdb->prefix . 'af_otas_sync_log';
$sync_logs = empty( $where_args )
	? $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 50" )
	: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT 50", $where_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$totals_row = $wpdb->get_row(
	"SELECT
		SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_total,
		SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS error_total,
		SUM(CASE WHEN status NOT IN ('success','failed') THEN 1 ELSE 0 END) AS pending_total,
		COUNT(*) AS total,
		MAX(created_at) AS last_sync
	FROM {$table}"
);

$success_count = (int) ( $totals_row->success_total ?? 0 );
$error_count   = (int) ( $totals_row->error_total ?? 0 );
$pending_count = (int) ( $totals_row->pending_total ?? 0 );
$total_syncs   = (int) ( $totals_row->total ?? 0 );
$last_sync_raw = $totals_row->last_sync ?? '';
$last_sync_str = $last_sync_raw ? wp_date( 'd/m/Y H:i', strtotime( $last_sync_raw ), wp_timezone_string() ) : '—';

$denominator  = max( 1, $success_count + $error_count );
$success_rate = ( $success_count + $error_count ) > 0 ? round( ( $success_count / $denominator ) * 100, 1 ) : 0;
?>

<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Integraciones externas', 'arriendo-facil' ),
			'title'    => __( 'Sincronización OTA', 'arriendo-facil' ),
			'subtitle' => __( 'Historial y estado de sincronización con Booking.com y Airbnb vía iCal.', 'arriendo-facil' ),
			'actions'  => array(
				sprintf(
					'<a href="%s" class="button af-btn af-btn--ghost">%s</a>',
					esc_url( admin_url( 'admin.php?page=af-ota-integrations' ) ),
					esc_html__( 'Cómo configurar iCal', 'arriendo-facil' )
				),
				sprintf(
					'<a href="%s" class="button af-btn af-btn--primary"><span class="af-btn__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 10a6 6 0 0110-4.24V4h2v5h-5V7h2.29A4 4 0 006 10H4zm12 0a6 6 0 01-10 4.24V16H4v-5h5v2H6.71A4 4 0 0014 10h2z" fill="currentColor"/></svg></span>%s</a>',
					esc_url( admin_url( 'edit.php?post_type=accommodation' ) ),
					esc_html__( 'Gestionar alojamientos', 'arriendo-facil' )
				),
			),
		)
	);
	?>

	<div class="af-kpi-grid">

		<article class="af-kpi af-kpi--success">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Sincronizaciones exitosas', 'arriendo-facil' ); ?></span>
				<span class="af-kpi__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $success_count ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Total acumulado', 'arriendo-facil' ); ?></div>
		</article>

		<article class="af-kpi <?php echo $error_count > 0 ? 'af-kpi--attention' : ''; ?>">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Errores', 'arriendo-facil' ); ?></span>
				<span class="af-kpi__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 8v5m0 4h.01M4.93 19h14.14a2 2 0 001.73-3L13.73 4a2 2 0 00-3.46 0L3.2 16a2 2 0 001.73 3z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $error_count ) ); ?></div>
			<div class="af-kpi__hint">
				<?php
				echo $error_count > 0
					? esc_html__( 'Revisar detalle abajo', 'arriendo-facil' )
					: esc_html__( 'Sin errores registrados', 'arriendo-facil' );
				?>
			</div>
		</article>

		<article class="af-kpi <?php echo $pending_count > 0 ? 'af-kpi--info' : ''; ?>">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'En cola', 'arriendo-facil' ); ?></span>
				<span class="af-kpi__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
				</span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Esperando ejecución', 'arriendo-facil' ); ?></div>
		</article>

		<article class="af-kpi af-kpi--accent">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Tasa de éxito', 'arriendo-facil' ); ?></span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $success_rate, 1 ) ); ?>%</div>
			<div class="af-kpi__hint">
				<?php
				printf(
					/* translators: %s: last sync datetime */
					esc_html__( 'Última sync: %s', 'arriendo-facil' ),
					esc_html( $last_sync_str )
				);
				?>
			</div>
		</article>

	</div>

	<section class="af-section af-ota-filters">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Filtros', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Acota el historial por plataforma y estado.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

		<form method="get" action="" class="af-filter-bar">
			<input type="hidden" name="page" value="af-ota-sync-dashboard" />

			<div class="af-form-field">
				<label class="af-form-field__label" for="filter_platform"><?php esc_html_e( 'Plataforma', 'arriendo-facil' ); ?></label>
				<select id="filter_platform" name="filter_platform">
					<option value=""><?php esc_html_e( 'Todas', 'arriendo-facil' ); ?></option>
					<option value="booking" <?php selected( $filter_platform, 'booking' ); ?>><?php esc_html_e( 'Booking.com', 'arriendo-facil' ); ?></option>
					<option value="airbnb" <?php selected( $filter_platform, 'airbnb' ); ?>><?php esc_html_e( 'Airbnb', 'arriendo-facil' ); ?></option>
				</select>
			</div>

			<div class="af-form-field">
				<label class="af-form-field__label" for="filter_status"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></label>
				<select id="filter_status" name="filter_status">
					<option value=""><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
					<option value="success" <?php selected( $filter_status, 'success' ); ?>><?php esc_html_e( 'Exitoso', 'arriendo-facil' ); ?></option>
					<option value="failed" <?php selected( $filter_status, 'failed' ); ?>><?php esc_html_e( 'Error', 'arriendo-facil' ); ?></option>
				</select>
			</div>

			<div class="af-filter-bar__actions">
				<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Filtrar', 'arriendo-facil' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-ota-sync-dashboard' ) ); ?>" class="button af-btn af-btn--ghost"><?php esc_html_e( 'Limpiar', 'arriendo-facil' ); ?></a>
			</div>
		</form>
	</section>

	<section class="af-section">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Historial reciente', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle">
					<?php
					printf(
						/* translators: %d: rows shown */
						esc_html( _n( 'Mostrando %d evento (máx. 50 más recientes).', 'Mostrando %d eventos (máx. 50 más recientes).', is_array( $sync_logs ) ? count( $sync_logs ) : 0, 'arriendo-facil' ) ),
						is_array( $sync_logs ) ? (int) count( $sync_logs ) : 0
					);
					?>
				</p>
			</div>
		</header>

	<table class="widefat striped af-ota-log af-data-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Alojamiento', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Plataforma', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Estado local', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Estado remoto', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Resultado', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Fecha / hora', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $sync_logs ) ) : ?>
				<?php foreach ( $sync_logs as $log ) : ?>
					<?php
					$accommodation  = get_post( $log->accommodation_id );
					$accom_title    = $accommodation ? $accommodation->post_title : sprintf( __( 'ID: %d (eliminado)', 'arriendo-facil' ), $log->accommodation_id );
					$accom_edit_url = $accommodation ? get_edit_post_link( $accommodation->ID ) : '#';

					$platform_label = 'booking' === $log->ota_source
						? 'Booking.com'
						: ( 'airbnb' === $log->ota_source ? 'Airbnb' : ucfirst( (string) $log->ota_source ) );

					$local_variant  = $log->local_was_occupied ? 'danger' : 'success';
					$local_text     = $log->local_was_occupied ? __( 'Ocupada', 'arriendo-facil' ) : __( 'Disponible', 'arriendo-facil' );
					$remote_variant = $log->remote_is_occupied ? 'danger' : 'success';
					$remote_text    = $log->remote_is_occupied ? __( 'Ocupada', 'arriendo-facil' ) : __( 'Disponible', 'arriendo-facil' );

					$is_success  = 'success' === $log->status;
					$status_text = $is_success ? __( 'Exitoso', 'arriendo-facil' ) : __( 'Error', 'arriendo-facil' );
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Alojamiento', 'arriendo-facil' ); ?>">
							<?php if ( $accommodation ) : ?>
								<a href="<?php echo esc_url( $accom_edit_url ); ?>"><?php echo esc_html( $accom_title ); ?></a>
							<?php else : ?>
								<span class="af-ota-log__deleted"><?php echo esc_html( $accom_title ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Plataforma', 'arriendo-facil' ); ?>"><strong><?php echo esc_html( $platform_label ); ?></strong></td>
						<td data-label="<?php esc_attr_e( 'Estado local', 'arriendo-facil' ); ?>">
							<span class="af-pill af-pill--<?php echo esc_attr( $local_variant ); ?>"><?php echo esc_html( $local_text ); ?></span>
						</td>
						<td data-label="<?php esc_attr_e( 'Estado remoto', 'arriendo-facil' ); ?>">
							<span class="af-pill af-pill--<?php echo esc_attr( $remote_variant ); ?>"><?php echo esc_html( $remote_text ); ?></span>
						</td>
						<td data-label="<?php esc_attr_e( 'Resultado', 'arriendo-facil' ); ?>">
							<span class="af-pill af-pill--<?php echo $is_success ? 'success' : 'danger'; ?>"><?php echo esc_html( $status_text ); ?></span>
							<?php if ( ! empty( $log->error_message ) ) : ?>
								<div class="af-ota-log__error"><?php echo esc_html( $log->error_message ); ?></div>
							<?php endif; ?>
						</td>
						<td class="af-ota-log__timestamp" data-label="<?php esc_attr_e( 'Fecha / hora', 'arriendo-facil' ); ?>">
							<?php
							echo esc_html(
								wp_date(
									'd/m/Y H:i',
									strtotime( $log->created_at ),
									wp_timezone_string()
								)
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="6">
						<div class="af-empty">
							<div class="af-empty__icon" aria-hidden="true">
								<svg width="48" height="48" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
							</div>
							<h3 class="af-empty__title"><?php esc_html_e( 'Sin registros aún', 'arriendo-facil' ); ?></h3>
							<p class="af-empty__text">
								<?php esc_html_e( 'Las sincronizaciones se registran automáticamente cada 30 minutos. Configura primero las URL iCal de tus alojamientos.', 'arriendo-facil' ); ?>
							</p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-ota-integrations' ) ); ?>" class="button af-btn af-btn--primary">
								<?php esc_html_e( 'Cómo configurar iCal', 'arriendo-facil' ); ?>
							</a>
						</div>
					</td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
	</section>
</div>
