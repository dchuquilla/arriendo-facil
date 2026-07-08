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

// Get current user
$current_user_id = get_current_user_id();
$is_admin = current_user_can( 'manage_options' );

// Fetch sync log
global $wpdb;
$sync_logs = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}af_otas_sync_log
		 ORDER BY created_at DESC
		 LIMIT %d",
		50
	)
);

// Calculate stats
$success_count = 0;
$error_count = 0;
$pending_count = 0;

foreach ( $sync_logs as $log ) {
	if ( 'success' === $log->status ) {
		$success_count++;
	} elseif ( 'failed' === $log->status ) {
		$error_count++;
	} else {
		$pending_count++;
	}
}

?>

<div class="wrap">
	<h1><?php esc_html_e( 'Panel de Sincronización OTA', 'arriendo-facil' ); ?></h1>

	<!-- STATS -->
	<div class="af-stats-grid">
		<div class="af-stat-card">
			<div class="af-stat-number" style="color: #28a745;">
				<?php echo absint( $success_count ); ?>
			</div>
			<div class="af-stat-label">
				<?php esc_html_e( 'Sincronizaciones Exitosas', 'arriendo-facil' ); ?>
			</div>
		</div>

		<div class="af-stat-card">
			<div class="af-stat-number" style="color: #dc3545;">
				<?php echo absint( $error_count ); ?>
			</div>
			<div class="af-stat-label">
				<?php esc_html_e( 'Errores', 'arriendo-facil' ); ?>
			</div>
		</div>

		<div class="af-stat-card">
			<div class="af-stat-number" style="color: #ffc107;">
				<?php echo absint( $pending_count ); ?>
			</div>
			<div class="af-stat-label">
				<?php esc_html_e( 'En Espera', 'arriendo-facil' ); ?>
			</div>
		</div>
	</div>

	<!-- FILTER FORM -->
	<form method="GET" action="" style="margin: 20px 0;">
		<input type="hidden" name="page" value="af-ota-sync-dashboard" />
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="filter_platform"><?php esc_html_e( 'Plataforma', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<select id="filter_platform" name="filter_platform">
						<option value="">-- <?php esc_html_e( 'Todas', 'arriendo-facil' ); ?> --</option>
						<option value="booking" <?php selected( $_GET['filter_platform'] ?? '', 'booking' ); ?>>
							<?php esc_html_e( 'Booking.com', 'arriendo-facil' ); ?>
						</option>
						<option value="airbnb" <?php selected( $_GET['filter_platform'] ?? '', 'airbnb' ); ?>>
							<?php esc_html_e( 'Airbnb', 'arriendo-facil' ); ?>
						</option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="filter_status"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<select id="filter_status" name="filter_status">
						<option value="">-- <?php esc_html_e( 'Todos', 'arriendo-facil' ); ?> --</option>
						<option value="success" <?php selected( $_GET['filter_status'] ?? '', 'success' ); ?>>
							<?php esc_html_e( 'Exitoso', 'arriendo-facil' ); ?>
						</option>
						<option value="failed" <?php selected( $_GET['filter_status'] ?? '', 'failed' ); ?>>
							<?php esc_html_e( 'Error', 'arriendo-facil' ); ?>
						</option>
					</select>
				</td>
			</tr>

			<tr>
				<th></th>
				<td>
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Filtrar', 'arriendo-facil' ); ?>" />
					<a href="?page=af-ota-sync-dashboard" class="button">
						<?php esc_html_e( 'Limpiar', 'arriendo-facil' ); ?>
					</a>
				</td>
			</tr>
		</table>
	</form>

	<!-- SYNC LOG TABLE -->
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Acomodación', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Plataforma', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Estado Local', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Estado Remoto', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Resultado', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Fecha/Hora', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $sync_logs ) ) : ?>
				<?php foreach ( $sync_logs as $log ) : ?>
					<?php
					// Get accommodation title
					$accommodation = get_post( $log->accommodation_id );
					$accom_title = $accommodation ? $accommodation->post_title : sprintf( __( 'ID: %d (eliminado)', 'arriendo-facil' ), $log->accommodation_id );
					$accom_edit_url = $accommodation ? get_edit_post_link( $accommodation->ID ) : '#';

					// Status badge color
					$status_color = 'success' === $log->status ? '#28a745' : '#dc3545';
					$status_text = 'success' === $log->status ? __( 'Exitoso', 'arriendo-facil' ) : __( 'Error', 'arriendo-facil' );

					// Local/Remote status text
					$local_text = $log->local_was_occupied ? __( '🔴 Ocupada', 'arriendo-facil' ) : __( '🟢 Disponible', 'arriendo-facil' );
					$remote_text = $log->remote_is_occupied ? __( '🔴 Ocupada', 'arriendo-facil' ) : __( '🟢 Disponible', 'arriendo-facil' );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $accom_edit_url ); ?>">
								<?php echo esc_html( $accom_title ); ?>
							</a>
						</td>
						<td>
							<strong><?php echo esc_html( ucfirst( $log->ota_source ) ); ?></strong>
						</td>
						<td>
							<?php echo wp_kses_post( $local_text ); ?>
						</td>
						<td>
							<?php echo wp_kses_post( $remote_text ); ?>
						</td>
						<td>
							<span style="color: <?php echo esc_attr( $status_color ); ?>; font-weight: bold;">
								<?php echo esc_html( $status_text ); ?>
							</span>
							<?php if ( ! empty( $log->error_message ) ) : ?>
								<br />
								<small style="color: #dc3545;">
									<?php echo esc_html( $log->error_message ); ?>
								</small>
							<?php endif; ?>
						</td>
						<td>
							<small>
								<?php
								echo wp_date(
									'd/m/Y H:i',
									strtotime( $log->created_at ),
									wp_timezone_string()
								);
								?>
							</small>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="6" style="text-align: center; padding: 20px;">
						<?php esc_html_e( 'Sin registros de sincronización', 'arriendo-facil' ); ?>
					</td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

</div>

<style>
	.af-stats-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 20px;
		margin: 20px 0;
	}

	.af-stat-card {
		background: #fff;
		border: 1px solid #ddd;
		border-radius: 4px;
		padding: 20px;
		text-align: center;
		box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
	}

	.af-stat-number {
		font-size: 32px;
		font-weight: bold;
		margin-bottom: 10px;
	}

	.af-stat-label {
		color: #666;
		font-size: 14px;
	}

	.widefat td {
		vertical-align: middle;
	}
</style>
