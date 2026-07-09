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

		<h3><?php esc_html_e( 'Paso 1: Obtén tu URL iCal de Booking', 'arriendo-facil' ); ?></h3>
		<ol style="line-height: 2;">
			<li><?php esc_html_e( 'Ve a tu panel de Booking: ', 'arriendo-facil' ); ?><a href="https://secure.booking.com/" target="_blank" style="color: #0073aa;">secure.booking.com</a></li>
			<li><?php esc_html_e( 'En el menú superior: Haz click en tu nombre (arriba a la derecha)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Ve a: Anuncios de propiedades', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Selecciona tu propiedad', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'En el menú de la izquierda: Ve a: Precios y disponibilidad', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Baja a la sección: "Sincronización del calendario"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Verás el botón: "Exportar calendario"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Copia el enlace que termina en .ics', 'arriendo-facil' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Paso 2: Configura en ArriendoFácil', 'arriendo-facil' ); ?></h3>
		<ol style="line-height: 2;">
			<li><?php esc_html_e( 'En el menú izquierdo: Ve a: Arriendo Fácil → Inmuebles', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Selecciona la propiedad que quieres sincronizar', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Baja hasta el final de la página (scroll hacia abajo)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Verás la sección azul: "Sincronización OTA (iCal)"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'En la sección "Booking.com" (📅), pega la URL iCal en el campo', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Ingresa también tu Property ID de Booking (número de 8 dígitos de tu propiedad)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en "Probar URL" para validar que funciona', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Si ves ✓ (check), significa que funciona correctamente', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Activa el checkbox: "Habilitar sincronización automática (cada 30 minutos)"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en el botón azul "Actualizar" o "Guardar" (lado derecho de la pantalla)', 'arriendo-facil' ); ?></li>
		</ol>

		<div style="background: #f0f6fc; border-left: 3px solid #0073aa; padding: 12px; margin: 15px 0;">
			<strong><?php esc_html_e( 'Resultado:', 'arriendo-facil' ); ?></strong><br/>
			<?php esc_html_e( 'Cada 30 minutos, ArriendoFácil descargará tu calendario de Booking y marcará automáticamente el inmueble como ocupado cuando haya reservas.', 'arriendo-facil' ); ?>
		</div>
	</div>

	<!-- AIRBNB -->
	<div class="af-integration-card" style="background: white; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 4px;">
		<h2 style="margin-top: 0;">🏠 Airbnb</h2>

		<h3><?php esc_html_e( 'Paso 1: Obtén tu URL iCal de Airbnb', 'arriendo-facil' ); ?></h3>
		<ol style="line-height: 2;">
			<li><?php esc_html_e( 'Ve a tu panel de Airbnb: ', 'arriendo-facil' ); ?><a href="https://www.airbnb.com/hosting/homes" target="_blank" style="color: #0073aa;">airbnb.com/hosting/homes</a></li>
			<li><?php esc_html_e( 'Ve a: Anuncios → Mis anuncios', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Selecciona la propiedad que quieres sincronizar', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'En el menú de la izquierda: Ve a: Calendario', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'En la esquina superior derecha, verás un engranaje (⚙️)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en el engranaje', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Se abrirá un menú, busca: "Opciones del calendario"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en: "Exportar"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Copia el enlace que termina en .ics', 'arriendo-facil' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Paso 2: Configura en ArriendoFácil', 'arriendo-facil' ); ?></h3>
		<ol style="line-height: 2;">
			<li><?php esc_html_e( 'En el menú izquierdo: Ve a: Arriendo Fácil → Inmuebles', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Selecciona la misma propiedad', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Baja hasta el final de la página (scroll hacia abajo)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Verás la sección azul: "Sincronización OTA (iCal)"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'En la sección "Airbnb" (🏠), pega la URL iCal en el campo', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Ingresa también tu Listing ID de Airbnb (número de tu anuncio: airbnb.com/rooms/[AQUI_ESTA_EL_ID])', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en "Probar URL" para validar que funciona', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Si ves ✓ (check), significa que funciona correctamente', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Activa el checkbox: "Habilitar sincronización automática (cada 30 minutos)"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en el botón azul "Actualizar" o "Guardar" (lado derecho de la pantalla)', 'arriendo-facil' ); ?></li>
		</ol>

		<div style="background: #f0f6fc; border-left: 3px solid #0073aa; padding: 12px; margin: 15px 0;">
			<strong><?php esc_html_e( 'Resultado:', 'arriendo-facil' ); ?></strong><br/>
			<?php esc_html_e( 'Cada 30 minutos, ArriendoFácil descargará tu calendario de Airbnb y marcará automáticamente el inmueble como ocupado cuando haya reservas.', 'arriendo-facil' ); ?>
		</div>
	</div>

	<!-- TROUBLESHOOTING -->
	<div class="af-integration-card" style="background: #fff8e5; border: 1px solid #ffb81c; padding: 20px; margin: 20px 0; border-radius: 4px;">
		<h2 style="margin-top: 0; color: #856404;">⚙️ <?php esc_html_e( 'Solución de problemas', 'arriendo-facil' ); ?></h2>

		<h3><?php esc_html_e( '¿No veo la sección "Sincronización OTA (iCal)"?', 'arriendo-facil' ); ?></h3>
		<ul style="line-height: 1.8;">
			<li><?php esc_html_e( 'Asegúrate de estar en: Arriendo Fácil → Inmuebles (no otra sección)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Editando un inmueble existente (no creando uno nuevo)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz scroll hacia ABAJO en el editor hasta el final', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'La sección debe estar en color azul claro', 'arriendo-facil' ); ?></li>
		</ul>

		<h3><?php esc_html_e( '¿La URL iCal no funciona?', 'arriendo-facil' ); ?></h3>
		<ul style="line-height: 1.8;">
			<li><?php esc_html_e( 'Verifica que copiaste el enlace completo (debe terminar en .ics)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Verifica que no hay espacios en blanco al inicio o final', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Intenta acceder a la URL directamente en el navegador para verificar que existe', 'arriendo-facil' ); ?></li>
		</ul>

		<h3><?php esc_html_e( '¿No se sincroniza automáticamente?', 'arriendo-facil' ); ?></h3>
		<ul style="line-height: 1.8;">
			<li><?php esc_html_e( 'Verifica que activaste el checkbox "Sincronización automática"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Verifica que guardaste los cambios (click en "Actualizar")', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Intenta usar el botón "Sincronizar Ahora" para probar manualmente', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'La sincronización automática se ejecuta cada 30 minutos (no es en tiempo real)', 'arriendo-facil' ); ?></li>
		</ul>

		<h3><?php esc_html_e( '¿Dónde veo el historial de sincronizaciones?', 'arriendo-facil' ); ?></h3>
		<ul style="line-height: 1.8;">
			<li><?php esc_html_e( 'Ve a: Arriendo Fácil → Sincronización OTA (es un nuevo menú)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Allí verás todas las sincronizaciones, errores y estadísticas', 'arriendo-facil' ); ?></li>
		</ul>
	</div>

	<!-- WEBHOOK INFO (Optional) -->
	<div class="af-integration-card" style="background: white; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 4px;">
		<h2 style="margin-top: 0;">🔔 <?php esc_html_e( 'Webhooks (Sincronización en Tiempo Real - Opcional)', 'arriendo-facil' ); ?></h2>

		<p><?php esc_html_e( 'Si quieres que se sincronice en tiempo real (cuando cambias disponibilidad), puedes configurar webhooks en Booking.com y Airbnb.', 'arriendo-facil' ); ?></p>

		<p><strong><?php esc_html_e( 'URLs de Webhooks para configurar manualmente en Booking y Airbnb:', 'arriendo-facil' ); ?></strong></p>

		<div style="background: #f0f6fc; border-left: 3px solid #0073aa; padding: 12px; margin: 10px 0; word-break: break-all;">
			<strong><?php esc_html_e( 'Booking.com Webhook:', 'arriendo-facil' ); ?></strong><br/>
			<code><?php echo esc_html( rest_url( 'af/v1/ota/webhook/booking' ) ); ?></code>
		</div>

		<div style="background: #f0f6fc; border-left: 3px solid #0073aa; padding: 12px; margin: 10px 0; word-break: break-all;">
			<strong><?php esc_html_e( 'Airbnb Webhook:', 'arriendo-facil' ); ?></strong><br/>
			<code><?php echo esc_html( rest_url( 'af/v1/ota/webhook/airbnb' ) ); ?></code>
		</div>

		<p style="color: #666; font-size: 13px;">
			<?php esc_html_e( 'Nota: Por ahora, la sincronización automática cada 30 minutos es suficiente para la mayoría de casos. Los webhooks son opcionales para usuarios avanzados.', 'arriendo-facil' ); ?>
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
