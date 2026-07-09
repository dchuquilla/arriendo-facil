<?php
/**
 * OTA Integrations Settings Page
 *
 * Información sobre cómo configurar sincronización con Booking y Airbnb.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap">
	<h1><?php esc_html_e( 'Integraciones OTA', 'arriendo-facil' ); ?></h1>

	<div class="af-ota-info-box" style="background: #e7f3ff; border-left: 4px solid #0073aa; padding: 15px; margin: 20px 0; border-radius: 4px;">
		<p style="margin: 0; color: #0073aa; font-size: 15px;">
			<strong><?php esc_html_e( 'ℹ Nuevo método de sincronización:', 'arriendo-facil' ); ?></strong><br/>
			<?php esc_html_e( 'ArriendoFácil ahora usa calendarios iCal públicos (estándar de la industria) en lugar de APIs. Esto es más simple, seguro y no requiere credenciales.', 'arriendo-facil' ); ?>
		</p>
	</div>

	<!-- BOOKING.COM -->
	<div class="af-integration-card" style="background: white; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 4px;">
		<h2 style="margin-top: 0;">📅 Booking.com</h2>

		<h3><?php esc_html_e( 'Paso 1: Obtén tu URL iCal', 'arriendo-facil' ); ?></h3>
		<ol style="line-height: 2;">
			<li><?php esc_html_e( 'Ve a tu panel de Booking: ', 'arriendo-facil' ); ?><a href="https://secure.booking.com/" target="_blank" style="color: #0073aa;">secure.booking.com</a></li>
			<li><?php esc_html_e( 'Ve a: Anuncios → Propiedades', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Selecciona tu propiedad', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Ve a: Precios y disponibilidad', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Baja a: Sincronización del calendario', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en: Exportar calendario', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Copia el enlace .ics', 'arriendo-facil' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Paso 2: Configura en ArriendoFácil', 'arriendo-facil' ); ?></h3>
		<ol style="line-height: 2;">
			<li><?php esc_html_e( 'Ve a: Acomodaciones → Edita tu propiedad', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Baja a la sección: "Sincronización OTA (iCal)"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Pega la URL iCal en el campo de Booking.com', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Ingresa tu Property ID (número de 8 dígitos de Booking)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Click en "Probar URL" para validar', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Activa el checkbox "Sincronización automática"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Guarda los cambios', 'arriendo-facil' ); ?></li>
		</ol>

		<div style="background: #f0f6fc; border-left: 3px solid #0073aa; padding: 12px; margin: 15px 0;">
			<strong><?php esc_html_e( 'Resultado:', 'arriendo-facil' ); ?></strong><br/>
			<?php esc_html_e( 'Cada 30 minutos, ArriendoFácil descargará tu calendario de Booking y marcará las propiedades como ocupadas cuando haya reservas.', 'arriendo-facil' ); ?>
		</div>
	</div>

	<!-- AIRBNB -->
	<div class="af-integration-card" style="background: white; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 4px;">
		<h2 style="margin-top: 0;">🏠 Airbnb</h2>

		<h3><?php esc_html_e( 'Paso 1: Obtén tu URL iCal', 'arriendo-facil' ); ?></h3>
		<ol style="line-height: 2;">
			<li><?php esc_html_e( 'Ve a tu panel de Airbnb: ', 'arriendo-facil' ); ?><a href="https://www.airbnb.com/hosting/homes" target="_blank" style="color: #0073aa;">airbnb.com/hosting/homes</a></li>
			<li><?php esc_html_e( 'Ve a: Anuncios → Propiedades', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Selecciona tu propiedad', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Ve a: Calendario', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Click en el engranaje (⚙) en la esquina superior derecha', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Ve a: Opciones del calendario', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en: Exportar', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Copia el enlace .ics', 'arriendo-facil' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Paso 2: Configura en ArriendoFácil', 'arriendo-facil' ); ?></h3>
		<ol style="line-height: 2;">
			<li><?php esc_html_e( 'Ve a: Acomodaciones → Edita tu propiedad', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Baja a la sección: "Sincronización OTA (iCal)"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Pega la URL iCal en el campo de Airbnb', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Ingresa tu Listing ID (número de tu anuncio: airbnb.com/rooms/[ID])', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Click en "Probar URL" para validar', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Activa el checkbox "Sincronización automática"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Guarda los cambios', 'arriendo-facil' ); ?></li>
		</ol>

		<div style="background: #f0f6fc; border-left: 3px solid #0073aa; padding: 12px; margin: 15px 0;">
			<strong><?php esc_html_e( 'Resultado:', 'arriendo-facil' ); ?></strong><br/>
			<?php esc_html_e( 'Cada 30 minutos, ArriendoFácil descargará tu calendario de Airbnb y marcará las propiedades como ocupadas cuando haya reservas.', 'arriendo-facil' ); ?>
		</div>
	</div>

	<!-- TROUBLESHOOTING -->
	<div class="af-integration-card" style="background: #fff8e5; border: 1px solid #ffb81c; padding: 20px; margin: 20px 0; border-radius: 4px;">
		<h2 style="margin-top: 0; color: #856404;">⚙️ <?php esc_html_e( 'Solución de problemas', 'arriendo-facil' ); ?></h2>

		<h3><?php esc_html_e( '¿La URL iCal no funciona?', 'arriendo-facil' ); ?></h3>
		<ul style="line-height: 1.8;">
			<li><?php esc_html_e( 'Verifica que copiaste el enlace completo (debe terminar en .ics)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Asegúrate que el enlace es público (en algunos casos las URLs son privadas)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Intenta acceder a la URL directamente en el navegador para verificar que existe', 'arriendo-facil' ); ?></li>
		</ul>

		<h3><?php esc_html_e( '¿No se sincroniza automáticamente?', 'arriendo-facil' ); ?></h3>
		<ul style="line-height: 1.8;">
			<li><?php esc_html_e( 'Verifica que activaste el checkbox "Sincronización automática"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Intenta usar el botón "Sincronizar Ahora" para probar manualmente', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'La sincronización automática se ejecuta cada 30 minutos (no es en tiempo real)', 'arriendo-facil' ); ?></li>
		</ul>

		<h3><?php esc_html_e( '¿Dónde veo el historial de sincronizaciones?', 'arriendo-facil' ); ?></h3>
		<ul style="line-height: 1.8;">
			<li><?php esc_html_e( 'Ve a: Arriendo Fácil → Sincronización OTA (dashboard)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Allí verás todas las sincronizaciones, errores y estadísticas', 'arriendo-facil' ); ?></li>
		</ul>
	</div>

	<!-- WEBHOOK INFO (Optional) -->
	<div class="af-integration-card" style="background: white; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 4px;">
		<h2 style="margin-top: 0;">🔔 <?php esc_html_e( 'Webhooks (Sincronización en Tiempo Real - Opcional)', 'arriendo-facil' ); ?></h2>

		<p><?php esc_html_e( 'Si quieres que se sincronice en tiempo real (cuando cambias disponibilidad), puedes configurar webhooks en Booking.com y Airbnb para que notifiquen a ArriendoFácil instantáneamente.', 'arriendo-facil' ); ?></p>

		<p><strong><?php esc_html_e( 'URLs de Webhooks:', 'arriendo-facil' ); ?></strong></p>

		<div style="background: #f0f6fc; border-left: 3px solid #0073aa; padding: 12px; margin: 10px 0; word-break: break-all;">
			<strong><?php esc_html_e( 'Booking.com:', 'arriendo-facil' ); ?></strong><br/>
			<code><?php echo esc_html( rest_url( 'af/v1/ota/webhook/booking' ) ); ?></code>
		</div>

		<div style="background: #f0f6fc; border-left: 3px solid #0073aa; padding: 12px; margin: 10px 0; word-break: break-all;">
			<strong><?php esc_html_e( 'Airbnb:', 'arriendo-facil' ); ?></strong><br/>
			<code><?php echo esc_html( rest_url( 'af/v1/ota/webhook/airbnb' ) ); ?></code>
		</div>

		<p style="color: #666; font-size: 13px;">
			<?php esc_html_e( 'Nota: La configuración de webhooks requiere acceso a la API de cada plataforma. Por ahora, la sincronización automática cada 30 minutos es suficiente para la mayoría de casos.', 'arriendo-facil' ); ?>
		</p>
	</div>
</div>

<style>
.af-integration-card h3 {
	margin-top: 20px;
	margin-bottom: 10px;
	color: #333;
	border-left: 3px solid #0073aa;
	padding-left: 12px;
}

.af-integration-card code {
	background: white;
	border: 1px solid #ddd;
	padding: 8px 12px;
	border-radius: 3px;
	font-family: monospace;
	font-size: 12px;
	display: block;
	word-break: break-all;
}
</style>
