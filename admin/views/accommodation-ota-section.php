<?php
/**
 * OTA Integration Section in Accommodation Meta Box
 *
 * Displays fields for linking accommodation to Booking and Airbnb properties.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get current values
$booking_property_id = get_post_meta( $post->ID, '_af_booking_property_id', true );
$airbnb_listing_id   = get_post_meta( $post->ID, '_af_airbnb_listing_id', true );
$sync_enabled        = get_post_meta( $post->ID, '_af_sync_enabled', true );
$last_sync           = get_post_meta( $post->ID, '_af_last_sync_timestamp', true );

// Get current user
$current_user_id = get_current_user_id();

// Check if this owner has configured API credentials
$booking_configured = Arriendo_Facil_OTA_Credentials::is_configured( $current_user_id, 'booking' );
$airbnb_configured  = Arriendo_Facil_OTA_Credentials::is_configured( $current_user_id, 'airbnb' );
?>

<!-- OTA INTEGRATIONS SECTION -->
<div class="af-accom-section" id="af-ota-section">
	<div class="af-accom-section__header">
		<span class="af-accom-section__icon">🌐</span>
		<h3 class="af-accom-section__title">
			<?php esc_html_e( 'Vincular con plataformas de alquiler', 'arriendo-facil' ); ?>
		</h3>
	</div>

	<div class="af-accom-section__body">

		<?php if ( ! $booking_configured && ! $airbnb_configured ) : ?>
			<div class="af-notice af-notice-info">
				<p>
					<?php
					printf(
						wp_kses_post(
							__( 'Para vincular esta propiedad con Booking.com o Airbnb, primero debes configurar tus credenciales en <a href="%s">Integraciones OTA</a>.', 'arriendo-facil' )
						),
						esc_url( admin_url( 'admin.php?page=af-ota-integrations' ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<!-- BOOKING.COM FIELD -->
		<?php if ( $booking_configured ) : ?>
			<div class="af-field">
				<label for="af_booking_property_id" class="af-field__label">
					<?php esc_html_e( 'ID de Propiedad Booking.com', 'arriendo-facil' ); ?>
				</label>

				<div class="af-field__input-group">
					<input
						type="text"
						id="af_booking_property_id"
						name="af_booking_property_id"
						value="<?php echo esc_attr( $booking_property_id ); ?>"
						placeholder="<?php esc_attr_e( 'Ej: 12345678', 'arriendo-facil' ); ?>"
						class="regular-text"
						maxlength="50"
					/>
				</div>

				<p class="description">
					<?php
					printf(
						wp_kses_post(
							__( 'Encontralo en <a href="%s" target="_blank">tu panel de Booking</a>: Propiedades → Configuración → Código de propiedad', 'arriendo-facil' )
						),
						'https://secure.booking.com/hotels/'
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<div class="af-field af-field--disabled">
				<label class="af-field__label">
					<?php esc_html_e( 'ID de Propiedad Booking.com', 'arriendo-facil' ); ?>
				</label>
				<p class="description" style="color: #999;">
					<?php esc_html_e( 'Configurar credenciales en Integraciones OTA para activar', 'arriendo-facil' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<!-- AIRBNB FIELD -->
		<?php if ( $airbnb_configured ) : ?>
			<div class="af-field">
				<label for="af_airbnb_listing_id" class="af-field__label">
					<?php esc_html_e( 'ID de Listing Airbnb', 'arriendo-facil' ); ?>
				</label>

				<div class="af-field__input-group">
					<input
						type="text"
						id="af_airbnb_listing_id"
						name="af_airbnb_listing_id"
						value="<?php echo esc_attr( $airbnb_listing_id ); ?>"
						placeholder="<?php esc_attr_e( 'Ej: 87654321', 'arriendo-facil' ); ?>"
						class="regular-text"
						maxlength="50"
					/>
				</div>

				<p class="description">
					<?php
					printf(
						wp_kses_post(
							__( 'Tu ID de listing está en la URL: <code>airbnb.com/rooms/[ID_AQUI]</code>', 'arriendo-facil' )
						)
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<div class="af-field af-field--disabled">
				<label class="af-field__label">
					<?php esc_html_e( 'ID de Listing Airbnb', 'arriendo-facil' ); ?>
				</label>
				<p class="description" style="color: #999;">
					<?php esc_html_e( 'Configurar credenciales en Integraciones OTA para activar', 'arriendo-facil' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<!-- SYNC ENABLED TOGGLE -->
		<?php if ( $booking_configured || $airbnb_configured ) : ?>
			<div class="af-field">
				<label class="af-checkbox-label">
					<input
						type="checkbox"
						name="af_sync_enabled"
						value="1"
						<?php checked( $sync_enabled, 1 ); ?>
					/>
					<span>
						<?php esc_html_e( 'Sincronizar disponibilidad automáticamente', 'arriendo-facil' ); ?>
					</span>
				</label>

				<p class="description">
					<?php esc_html_e( 'Si está activado, la disponibilidad de Booking/Airbnb se reflejará aquí cada 2 horas', 'arriendo-facil' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<!-- LAST SYNC STATUS -->
		<?php if ( $last_sync ) : ?>
			<div class="af-field af-field--status">
				<p class="description">
					<strong>✓ <?php esc_html_e( 'Última sincronización:', 'arriendo-facil' ); ?></strong>
					<?php echo wp_date( 'd/m/Y H:i', (int) $last_sync ); ?>
				</p>
			</div>
		<?php endif; ?>

		<!-- SYNC ERRORS (if any) -->
		<?php
		$sync_errors = get_post_meta( $post->ID, '_af_ota_last_errors', true );
		if ( ! empty( $sync_errors ) ) :
			$errors = is_array( $sync_errors ) ? $sync_errors : array();
			?>
			<div class="af-field af-notice af-notice-warning">
				<p>
					<strong><?php esc_html_e( '⚠ Errores en última sincronización:', 'arriendo-facil' ); ?></strong>
				</p>
				<ul>
					<?php foreach ( $errors as $platform => $error ) : ?>
						<li>
							<strong><?php echo esc_html( ucfirst( $platform ) ); ?>:</strong>
							<?php echo esc_html( $error ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<!-- MANUAL SYNC BUTTON -->
		<?php if ( ( $booking_property_id && $booking_configured ) || ( $airbnb_listing_id && $airbnb_configured ) ) : ?>
			<div class="af-field af-field--actions">
				<button
					type="button"
					class="button button-secondary"
					id="af-sync-ota-now"
					data-accommodation-id="<?php echo (int) $post->ID; ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'af_sync_accommodation_now' ) ); ?>"
				>
					<?php esc_html_e( 'Sincronizar Ahora', 'arriendo-facil' ); ?>
				</button>

				<span id="af-sync-result" class="af-sync-result" style="margin-left: 10px; display: none;"></span>
			</div>
		<?php endif; ?>

		<!-- INFO BOX -->
		<div class="af-notice af-notice-info" style="margin-top: 15px;">
			<p>
				<strong><?php esc_html_e( 'ℹ Cómo funciona:', 'arriendo-facil' ); ?></strong><br/>
				<?php esc_html_e( '1. Obtén los IDs de tus propiedades en Booking y Airbnb', 'arriendo-facil' ); ?><br/>
				<?php esc_html_e( '2. Ingrésalos aquí para vincularlas con esta acomodación', 'arriendo-facil' ); ?><br/>
				<?php esc_html_e( '3. Activa la sincronización automática', 'arriendo-facil' ); ?><br/>
				<?php esc_html_e( '4. Cuando se reserve en Booking/Airbnb, aparecerá como ocupada aquí', 'arriendo-facil' ); ?>
			</p>
		</div>

	</div>
</div>

<style>
	#af-ota-section {
		margin-top: 20px;
		padding: 20px;
		border: 1px solid #ddd;
		border-radius: 4px;
		background: #fafafa;
	}

	#af-ota-section .af-accom-section__header {
		display: flex;
		align-items: center;
		gap: 10px;
		margin-bottom: 15px;
		padding-bottom: 10px;
		border-bottom: 2px solid #0073aa;
	}

	#af-ota-section .af-accom-section__icon {
		font-size: 24px;
	}

	#af-ota-section .af-accom-section__title {
		margin: 0;
		color: #0073aa;
	}

	.af-field--disabled input,
	.af-field--disabled select,
	.af-field--disabled textarea {
		background-color: #f5f5f5;
		color: #999;
		cursor: not-allowed;
		opacity: 0.6;
	}

	.af-notice {
		padding: 12px 15px;
		border-left: 4px solid #0073aa;
		background: #f0f6fc;
		margin: 15px 0;
	}

	.af-notice-warning {
		border-left-color: #ffb81c;
		background: #fff8e5;
	}

	.af-notice-info {
		border-left-color: #0073aa;
		background: #f0f6fc;
	}

	.af-field__input-group {
		display: flex;
		gap: 10px;
	}

	.af-sync-result {
		padding: 5px 10px;
		border-radius: 3px;
		font-size: 13px;
		font-weight: 500;
	}

	.af-sync-result.success {
		color: #155724;
		background: #d4edda;
		border: 1px solid #c3e6cb;
	}

	.af-sync-result.error {
		color: #721c24;
		background: #f8d7da;
		border: 1px solid #f5c6cb;
	}

	.af-sync-result.loading {
		color: #856404;
		background: #fff3cd;
		border: 1px solid #ffeaa7;
	}

	#af-sync-ota-now:disabled {
		opacity: 0.6;
		cursor: not-allowed;
	}

	#af-sync-ota-now.loading {
		position: relative;
	}

	#af-sync-ota-now.loading::after {
		content: '';
		position: absolute;
		width: 16px;
		height: 16px;
		top: 50%;
		right: 10px;
		transform: translateY(-50%);
		border: 2px solid #f3f3f3;
		border-top: 2px solid #0073aa;
		border-radius: 50%;
		animation: spin 1s linear infinite;
	}

	@keyframes spin {
		0% { transform: translateY(-50%) rotate(0deg); }
		100% { transform: translateY(-50%) rotate(360deg); }
	}
</style>
