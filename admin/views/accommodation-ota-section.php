<?php
/**
 * Accommodation OTA Sync Meta Box
 *
 * Allows configuration of iCal URLs from Booking and Airbnb
 * for automatic availability synchronization.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$accommodation_id = $post->ID;
$booking_property_id = get_post_meta( $accommodation_id, '_af_booking_property_id', true );
$booking_ical_url = get_post_meta( $accommodation_id, '_af_booking_ical_url', true );
$airbnb_listing_id = get_post_meta( $accommodation_id, '_af_airbnb_listing_id', true );
$airbnb_ical_url = get_post_meta( $accommodation_id, '_af_airbnb_ical_url', true );
$sync_enabled = (int) get_post_meta( $accommodation_id, '_af_sync_enabled', true );
$last_sync = get_post_meta( $accommodation_id, '_af_last_sync_timestamp', true );
$is_occupied = (int) get_post_meta( $accommodation_id, '_af_is_occupied', true );

$last_sync_text = $last_sync ? wp_date( 'd/m/Y H:i', $last_sync, wp_timezone_string() ) : __( 'Nunca', 'arriendo-facil' );
$status_class = $is_occupied ? 'occupied' : 'available';
$status_text = $is_occupied ? __( '🔴 Ocupada', 'arriendo-facil' ) : __( '🟢 Disponible', 'arriendo-facil' );

wp_nonce_field( 'af_ota_sync_nonce', '_wpnonce_ota_sync' );
?>

<div class="af-ota-section">
	<h3><?php esc_html_e( 'Sincronización OTA (iCal)', 'arriendo-facil' ); ?></h3>

	<div class="af-ota-info">
		<p><?php esc_html_e( 'Sincroniza la disponibilidad de esta acomodación con Booking.com y Airbnb usando sus calendarios iCal. Esto previene overbooking automáticamente.', 'arriendo-facil' ); ?></p>
	</div>

	<!-- Booking Configuration -->
	<div class="af-ota-platform booking">
		<h4>📅 Booking.com</h4>

		<div class="form-group">
			<label for="af_booking_property_id">
				<?php esc_html_e( 'ID de Propiedad (para webhooks)', 'arriendo-facil' ); ?>
			</label>
			<input
				type="text"
				id="af_booking_property_id"
				name="af_booking_property_id"
				value="<?php echo esc_attr( $booking_property_id ); ?>"
				placeholder="<?php esc_attr_e( 'Ej: 12345678', 'arriendo-facil' ); ?>"
				class="widefat"
			/>
			<small><?php esc_html_e( 'Encontrado en la URL de tu propiedad en Booking: /property/[ID]', 'arriendo-facil' ); ?></small>
		</div>

		<div class="form-group">
			<label for="af_booking_ical_url">
				<?php esc_html_e( 'URL del Calendario iCal', 'arriendo-facil' ); ?>
			</label>
			<input
				type="url"
				id="af_booking_ical_url"
				name="af_booking_ical_url"
				value="<?php echo esc_url( $booking_ical_url ); ?>"
				placeholder="https://..."
				class="widefat"
			/>
			<small>
				<?php esc_html_e( 'Obtén este enlace: Anuncios → Propiedades → Precios y disponibilidad → Sincronización del calendario → Exportar calendario', 'arriendo-facil' ); ?>
			</small>
		</div>

		<button
			type="button"
			class="button af-test-ical-btn"
			data-platform="booking"
			data-accommodation-id="<?php echo absint( $accommodation_id ); ?>"
		>
			<?php esc_html_e( 'Probar URL', 'arriendo-facil' ); ?>
		</button>
		<span class="af-test-result" data-platform="booking"></span>
	</div>

	<!-- Airbnb Configuration -->
	<div class="af-ota-platform airbnb">
		<h4>🏠 Airbnb</h4>

		<div class="form-group">
			<label for="af_airbnb_listing_id">
				<?php esc_html_e( 'ID del Anuncio (para webhooks)', 'arriendo-facil' ); ?>
			</label>
			<input
				type="text"
				id="af_airbnb_listing_id"
				name="af_airbnb_listing_id"
				value="<?php echo esc_attr( $airbnb_listing_id ); ?>"
				placeholder="<?php esc_attr_e( 'Ej: 12345678', 'arriendo-facil' ); ?>"
				class="widefat"
			/>
			<small><?php esc_html_e( 'ID de tu listado: airbnb.com/rooms/[ID_AQUI]', 'arriendo-facil' ); ?></small>
		</div>

		<div class="form-group">
			<label for="af_airbnb_ical_url">
				<?php esc_html_e( 'URL del Calendario iCal', 'arriendo-facil' ); ?>
			</label>
			<input
				type="url"
				id="af_airbnb_ical_url"
				name="af_airbnb_ical_url"
				value="<?php echo esc_url( $airbnb_ical_url ); ?>"
				placeholder="https://..."
				class="widefat"
			/>
			<small>
				<?php esc_html_e( 'Obtén este enlace: Anuncios → Propiedades → Calendario → Engranaje (⚙) → Opciones del calendario → Exportar', 'arriendo-facil' ); ?>
			</small>
		</div>

		<button
			type="button"
			class="button af-test-ical-btn"
			data-platform="airbnb"
			data-accommodation-id="<?php echo absint( $accommodation_id ); ?>"
		>
			<?php esc_html_e( 'Probar URL', 'arriendo-facil' ); ?>
		</button>
		<span class="af-test-result" data-platform="airbnb"></span>
	</div>

	<!-- Sync Options -->
	<div class="af-ota-options">
		<h4><?php esc_html_e( 'Opciones de Sincronización', 'arriendo-facil' ); ?></h4>

		<div class="form-group checkbox">
			<label>
				<input
					type="hidden"
					name="af_sync_enabled"
					value="0"
				/>
				<input
					type="checkbox"
					name="af_sync_enabled"
					value="1"
					<?php checked( $sync_enabled, 1 ); ?>
				/>
				<?php esc_html_e( 'Habilitar sincronización automática (cada 30 minutos)', 'arriendo-facil' ); ?>
			</label>
		</div>
	</div>

	<!-- Status & Last Sync -->
	<div class="af-ota-status">
		<h4><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></h4>

		<div class="status-row">
			<span class="status-label"><?php esc_html_e( 'Estado de la propiedad:', 'arriendo-facil' ); ?></span>
			<span class="status-badge <?php echo esc_attr( $status_class ); ?>">
				<?php echo wp_kses_post( $status_text ); ?>
			</span>
		</div>

		<div class="status-row">
			<span class="status-label"><?php esc_html_e( 'Última sincronización:', 'arriendo-facil' ); ?></span>
			<span class="status-value"><?php echo esc_html( $last_sync_text ); ?></span>
		</div>

		<button
			type="button"
			class="button button-secondary af-sync-now-btn"
			data-accommodation-id="<?php echo absint( $accommodation_id ); ?>"
		>
			<?php esc_html_e( 'Sincronizar Ahora', 'arriendo-facil' ); ?>
		</button>
		<span class="af-sync-message" style="margin-left: 10px; display: none;"></span>
	</div>
</div>

<style>
.af-ota-section {
	background: #f9f9f9;
	padding: 20px;
	border-radius: 4px;
	margin: 20px 0;
}

.af-ota-section h3 {
	margin-top: 0;
	color: #333;
	border-bottom: 2px solid #0073aa;
	padding-bottom: 10px;
}

.af-ota-info {
	background: #e7f3ff;
	border-left: 4px solid #0073aa;
	padding: 12px;
	margin-bottom: 20px;
	border-radius: 2px;
	font-size: 14px;
}

.af-ota-info p {
	margin: 0;
	color: #0073aa;
}

.af-ota-platform {
	background: white;
	border: 1px solid #ddd;
	border-radius: 4px;
	padding: 15px;
	margin-bottom: 15px;
}

.af-ota-platform h4 {
	margin: 0 0 15px 0;
	color: #333;
	font-size: 15px;
}

.form-group {
	margin-bottom: 15px;
}

.form-group label {
	display: block;
	margin-bottom: 5px;
	font-weight: 500;
	color: #333;
}

.form-group small {
	display: block;
	margin-top: 5px;
	color: #666;
	font-size: 12px;
	line-height: 1.4;
}

.form-group.checkbox label {
	display: flex;
	align-items: center;
	font-weight: normal;
	gap: 8px;
}

.form-group.checkbox input {
	margin: 0;
}

.af-ota-options {
	background: white;
	border: 1px solid #ddd;
	border-radius: 4px;
	padding: 15px;
	margin-bottom: 15px;
}

.af-ota-options h4 {
	margin: 0 0 15px 0;
	color: #333;
	font-size: 15px;
}

.af-ota-status {
	background: white;
	border: 1px solid #ddd;
	border-radius: 4px;
	padding: 15px;
}

.af-ota-status h4 {
	margin: 0 0 15px 0;
	color: #333;
	font-size: 15px;
}

.status-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid #eee;
}

.status-row:last-of-type {
	border-bottom: none;
	padding-bottom: 15px;
}

.status-label {
	font-weight: 500;
	color: #666;
}

.status-value {
	color: #333;
	font-family: monospace;
	font-size: 12px;
}

.status-badge {
	font-weight: bold;
	padding: 4px 12px;
	border-radius: 3px;
	font-size: 14px;
}

.status-badge.occupied {
	background: #fee;
	color: #d32f2f;
}

.status-badge.available {
	background: #efe;
	color: #2e7d32;
}

.af-test-ical-btn,
.af-sync-now-btn {
	margin-top: 10px;
	margin-right: 10px;
}

.af-test-result,
.af-sync-message {
	padding: 5px 10px;
	border-radius: 3px;
	font-size: 13px;
	font-weight: 500;
}

.af-test-result.success,
.af-sync-message.success {
	color: #2e7d32;
	background: #e8f5e9;
	border: 1px solid #a5d6a7;
	display: inline-block;
}

.af-test-result.error,
.af-sync-message.error {
	color: #d32f2f;
	background: #ffebee;
	border: 1px solid #ef9a9a;
	display: inline-block;
}

.af-test-result.loading,
.af-sync-message.loading {
	color: #856404;
	background: #fff3cd;
	border: 1px solid #ffeaa7;
	display: inline-block;
}
</style>
