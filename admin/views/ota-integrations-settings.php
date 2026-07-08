<?php
/**
 * OTA Integrations Settings Page
 *
 * Allows owners to configure API credentials for Booking.com and Airbnb.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get current user
$current_user_id = get_current_user_id();

// Get stored settings
$booking_creds = Arriendo_Facil_OTA_Credentials::get_decrypted( $current_user_id, 'booking' );
$airbnb_creds = Arriendo_Facil_OTA_Credentials::get_decrypted( $current_user_id, 'airbnb' );

// Get webhook URLs
$booking_webhook_url = Arriendo_Facil_OTA_Webhook_Handler::get_webhook_url( 'booking' );
$airbnb_webhook_url = Arriendo_Facil_OTA_Webhook_Handler::get_webhook_url( 'airbnb' );

// Check if we're in admin
$is_admin = current_user_can( 'manage_options' );
?>

<div class="wrap">
	<h1><?php esc_html_e( 'Integraciones OTA', 'arriendo-facil' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Configura tus credenciales de API para sincronizar disponibilidad automáticamente entre Booking.com, Airbnb y ArriendoFacil.', 'arriendo-facil' ); ?>
	</p>

	<!-- NAV TABS -->
	<nav class="nav-tab-wrapper" style="margin-bottom: 20px; border-bottom: 1px solid #ccc;">
		<a href="#booking" class="nav-tab nav-tab-active" data-tab="booking">
			<?php esc_html_e( 'Booking.com', 'arriendo-facil' ); ?>
		</a>
		<a href="#airbnb" class="nav-tab" data-tab="airbnb">
			<?php esc_html_e( 'Airbnb', 'arriendo-facil' ); ?>
		</a>
	</nav>

	<!-- BOOKING.COM TAB -->
	<div id="booking" class="ota-tab ota-tab--active" data-tab="booking">
		<h2><?php esc_html_e( 'Booking.com', 'arriendo-facil' ); ?></h2>

		<form method="POST" action="admin.php?action=af_save_ota_credentials" class="af-ota-form">
			<?php wp_nonce_field( 'af_ota_credentials_nonce', 'af_nonce' ); ?>

			<input type="hidden" name="platform" value="booking" />

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="booking_api_key"><?php esc_html_e( 'API Key', 'arriendo-facil' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="booking_api_key"
							name="booking_api_key"
							value="<?php echo $booking_creds ? '••••••••' : ''; ?>"
							placeholder="<?php esc_attr_e( 'Tu API key de Booking', 'arriendo-facil' ); ?>"
							class="regular-text"
							autocomplete="off"
							required
						/>
						<p class="description">
							<?php
							printf(
								wp_kses_post(
									__( 'Obtén tu API key en: <a href="%s" target="_blank">Extranet.booking.com</a> → Ajustes → API', 'arriendo-facil' )
								),
								'https://secure.booking.com/'
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="booking_partner_id"><?php esc_html_e( 'Partner ID', 'arriendo-facil' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="booking_partner_id"
							name="booking_partner_id"
							value="<?php echo $booking_creds ? esc_attr( $booking_creds['account_id'] ) : ''; ?>"
							placeholder="<?php esc_attr_e( 'Tu Partner ID', 'arriendo-facil' ); ?>"
							class="regular-text"
							required
						/>
						<p class="description">
							<?php esc_html_e( 'Tu ID de partner en Booking.com', 'arriendo-facil' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
					<td>
						<?php if ( $booking_creds && 'active' === $booking_creds['status'] ) : ?>
							<span class="dashicons dashicons-yes" style="color: #00a32a;"></span>
							<?php esc_html_e( 'Conectado', 'arriendo-facil' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-no" style="color: #dc3545;"></span>
							<?php esc_html_e( 'No configurado', 'arriendo-facil' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button
					type="button"
					class="button button-secondary af-test-connection"
					data-platform="booking"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'af_test_connection' ) ); ?>"
				>
					<?php esc_html_e( 'Probar Conexión', 'arriendo-facil' ); ?>
				</button>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Guardar Configuración', 'arriendo-facil' ); ?>
				</button>
				<?php if ( $booking_creds ) : ?>
					<button
						type="button"
						class="button button-danger af-disconnect"
						data-platform="booking"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'af_disconnect_ota' ) ); ?>"
					>
						<?php esc_html_e( 'Desconectar', 'arriendo-facil' ); ?>
					</button>
				<?php endif; ?>
			</p>

			<div class="af-connection-result" style="display: none; margin-top: 15px;"></div>
		</form>

		<!-- WEBHOOK INFO -->
		<div class="notice notice-info" style="margin-top: 30px;">
			<p>
				<strong><?php esc_html_e( 'Webhook para sincronización en tiempo real (opcional):', 'arriendo-facil' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Si configuraste webhooks en Booking, usa esta URL para recibir notificaciones automáticas:', 'arriendo-facil' ); ?><br/>
				<code style="display: block; margin-top: 10px; padding: 10px; background: #f5f5f5; word-break: break-all;">
					<?php echo esc_url( $booking_webhook_url ); ?>
				</code>
			</p>
		</div>
	</div>

	<!-- AIRBNB TAB -->
	<div id="airbnb" class="ota-tab" data-tab="airbnb">
		<h2><?php esc_html_e( 'Airbnb', 'arriendo-facil' ); ?></h2>

		<form method="POST" action="admin.php?action=af_save_ota_credentials" class="af-ota-form">
			<?php wp_nonce_field( 'af_ota_credentials_nonce', 'af_nonce' ); ?>

			<input type="hidden" name="platform" value="airbnb" />

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="airbnb_api_key"><?php esc_html_e( 'Access Token', 'arriendo-facil' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="airbnb_api_key"
							name="airbnb_api_key"
							value="<?php echo $airbnb_creds ? '••••••••' : ''; ?>"
							placeholder="<?php esc_attr_e( 'Tu Access Token de Airbnb', 'arriendo-facil' ); ?>"
							class="regular-text"
							autocomplete="off"
							required
						/>
						<p class="description">
							<?php
							printf(
								wp_kses_post(
									__( 'Access token desde <a href="%s" target="_blank">Airbnb API</a>', 'arriendo-facil' )
								),
								'https://www.airbnb.com/api'
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="airbnb_account_id"><?php esc_html_e( 'Account ID', 'arriendo-facil' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="airbnb_account_id"
							name="airbnb_account_id"
							value="<?php echo $airbnb_creds ? esc_attr( $airbnb_creds['account_id'] ) : ''; ?>"
							placeholder="<?php esc_attr_e( 'Tu Account ID en Airbnb', 'arriendo-facil' ); ?>"
							class="regular-text"
							required
						/>
						<p class="description">
							<?php esc_html_e( 'Tu ID de cuenta en Airbnb', 'arriendo-facil' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
					<td>
						<?php if ( $airbnb_creds && 'active' === $airbnb_creds['status'] ) : ?>
							<span class="dashicons dashicons-yes" style="color: #00a32a;"></span>
							<?php esc_html_e( 'Conectado', 'arriendo-facil' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-no" style="color: #dc3545;"></span>
							<?php esc_html_e( 'No configurado', 'arriendo-facil' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button
					type="button"
					class="button button-secondary af-test-connection"
					data-platform="airbnb"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'af_test_connection' ) ); ?>"
				>
					<?php esc_html_e( 'Probar Conexión', 'arriendo-facil' ); ?>
				</button>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Guardar Configuración', 'arriendo-facil' ); ?>
				</button>
				<?php if ( $airbnb_creds ) : ?>
					<button
						type="button"
						class="button button-danger af-disconnect"
						data-platform="airbnb"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'af_disconnect_ota' ) ); ?>"
					>
						<?php esc_html_e( 'Desconectar', 'arriendo-facil' ); ?>
					</button>
				<?php endif; ?>
			</p>

			<div class="af-connection-result" style="display: none; margin-top: 15px;"></div>
		</form>

		<!-- WEBHOOK INFO -->
		<div class="notice notice-info" style="margin-top: 30px;">
			<p>
				<strong><?php esc_html_e( 'Webhook para sincronización en tiempo real (opcional):', 'arriendo-facil' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Si configuraste webhooks en Airbnb, usa esta URL para recibir notificaciones automáticas:', 'arriendo-facil' ); ?><br/>
				<code style="display: block; margin-top: 10px; padding: 10px; background: #f5f5f5; word-break: break-all;">
					<?php echo esc_url( $airbnb_webhook_url ); ?>
				</code>
			</p>
		</div>
	</div>

</div>

<style>
	.nav-tab-wrapper .nav-tab {
		color: #0073aa;
		border: 1px solid #ccc;
		border-bottom: none;
		margin-right: 5px;
		padding: 10px 15px;
		text-decoration: none;
	}

	.nav-tab-wrapper .nav-tab:hover {
		color: #005a87;
	}

	.nav-tab-wrapper .nav-tab-active {
		background: #fff;
		border-bottom: 3px solid #0073aa;
		color: #0073aa;
		font-weight: bold;
	}

	.ota-tab {
		display: none;
		background: #fff;
		padding: 20px;
		border: 1px solid #ccc;
	}

	.ota-tab--active {
		display: block;
	}

	.af-ota-form table.form-table {
		margin-top: 20px;
	}

	.af-ota-form .description {
		display: block;
		margin-top: 5px;
	}

	.button-danger {
		background: #dc3545;
		border-color: #dc3545;
		color: white;
	}

	.button-danger:hover {
		background: #c82333;
		border-color: #bd2130;
		color: white;
	}

	.af-connection-result {
		padding: 15px;
		border-radius: 4px;
		border-left: 4px solid transparent;
	}

	.af-connection-result.success {
		background: #d4edda;
		border-left-color: #28a745;
		color: #155724;
	}

	.af-connection-result.error {
		background: #f8d7da;
		border-left-color: #dc3545;
		color: #721c24;
	}

	.af-connection-result.loading {
		background: #fff3cd;
		border-left-color: #ffc107;
		color: #856404;
	}
</style>

<script>
jQuery(document).ready(function ($) {
	// Tab switching
	$('.nav-tab').on('click', function (e) {
		e.preventDefault();
		var tab = $(this).data('tab');

		$('.nav-tab').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');

		$('.ota-tab').removeClass('ota-tab--active');
		$('#' + tab).addClass('ota-tab--active');
	});

	// Test connection button
	$('.af-test-connection').on('click', function (e) {
		e.preventDefault();
		var btn = $(this);
		var platform = btn.data('platform');
		var nonce = btn.data('nonce');
		var form = btn.closest('form');
		var resultEl = form.find('.af-connection-result');

		// Get form data
		var apiKey = form.find('[name=' + platform + '_api_key]').val();
		var accountId = form.find('[name=' + platform + '_account_id], [name=' + platform + '_partner_id]').val();

		if (!apiKey || !accountId) {
			resultEl
				.removeClass('success loading')
				.addClass('error')
				.text('<?php esc_html_e( 'Por favor completa todos los campos', 'arriendo-facil' ); ?>')
				.show();
			return;
		}

		// Show loading
		resultEl
			.removeClass('success error')
			.addClass('loading')
			.text('<?php esc_html_e( 'Probando conexión...', 'arriendo-facil' ); ?>')
			.show();

		btn.prop('disabled', true);

		// AJAX request
		$.post(
			afAdmin.ajaxUrl,
			{
				action: 'af_test_ota_connection',
				platform: platform,
				api_key: apiKey,
				account_id: accountId,
				nonce: nonce,
			},
			function (response) {
				if (response.success) {
					resultEl
						.removeClass('loading error')
						.addClass('success')
						.text('✓ ' + response.data.message)
						.show();
				} else {
					resultEl
						.removeClass('loading success')
						.addClass('error')
						.text('✗ ' + response.data.message)
						.show();
				}
			}
		).always(function () {
			btn.prop('disabled', false);
		});
	});

	// Disconnect button
	$('.af-disconnect').on('click', function (e) {
		e.preventDefault();

		if (!confirm('<?php esc_html_e( '¿Desconectar esta plataforma?', 'arriendo-facil' ); ?>')) {
			return;
		}

		var btn = $(this);
		var platform = btn.data('platform');
		var nonce = btn.data('nonce');

		btn.prop('disabled', true).text('<?php esc_html_e( 'Desconectando...', 'arriendo-facil' ); ?>');

		$.post(
			afAdmin.ajaxUrl,
			{
				action: 'af_disconnect_ota',
				platform: platform,
				nonce: nonce,
			},
			function (response) {
				if (response.success) {
					location.reload();
				} else {
					alert(response.data.message || '<?php esc_html_e( 'Error al desconectar', 'arriendo-facil' ); ?>');
					btn.prop('disabled', false).text('<?php esc_html_e( 'Desconectar', 'arriendo-facil' ); ?>');
				}
			}
		);
	});
});
</script>
