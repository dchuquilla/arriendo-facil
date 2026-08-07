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

<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Integraciones externas', 'arriendo-facil' ),
			'title'    => __( 'Integraciones OTA', 'arriendo-facil' ),
			'subtitle' => __( 'Cómo obtener y configurar las URL iCal de Booking y Airbnb en tus alojamientos.', 'arriendo-facil' ),
		)
	);
	?>

	<div class="af-info-banner">
		<svg class="af-info-banner__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
			<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.8"/>
			<path d="M12 8v4M12 16h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
		</svg>
		<div class="af-info-banner__body">
			<p class="af-info-banner__title"><?php esc_html_e( 'Nuevo método de sincronización', 'arriendo-facil' ); ?></p>
			<p><?php esc_html_e( 'ArriendoFácil ahora usa calendarios iCal públicos (estándar de la industria) en lugar de APIs. Esto es más simple, seguro y no requiere credenciales.', 'arriendo-facil' ); ?></p>
		</div>
	</div>

	<!-- BOOKING.COM -->
	<section class="af-section af-integration-card">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title">📅 Booking.com</h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Sincroniza reservas y disponibilidad desde tu extranet de Booking.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

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

		<div class="af-info-banner af-info-banner--success">
			<svg class="af-info-banner__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			<div class="af-info-banner__body">
				<p class="af-info-banner__title"><?php esc_html_e( 'Resultado', 'arriendo-facil' ); ?></p>
				<p><?php esc_html_e( 'Cada 30 minutos, ArriendoFácil descargará tu calendario de Booking y marcará automáticamente el inmueble como ocupado cuando haya reservas.', 'arriendo-facil' ); ?></p>
			</div>
		</div>
	</section>

	<!-- AIRBNB -->
	<section class="af-section af-integration-card">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title">🏠 Airbnb</h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Sincroniza reservas y disponibilidad desde tu panel de host de Airbnb.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

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

		<div class="af-info-banner af-info-banner--success">
			<svg class="af-info-banner__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			<div class="af-info-banner__body">
				<p class="af-info-banner__title"><?php esc_html_e( 'Resultado', 'arriendo-facil' ); ?></p>
				<p><?php esc_html_e( 'Cada 30 minutos, ArriendoFácil descargará tu calendario de Airbnb y marcará automáticamente el inmueble como ocupado cuando haya reservas.', 'arriendo-facil' ); ?></p>
			</div>
		</div>
	</section>

	<!-- TROUBLESHOOTING -->
	<section class="af-section af-integration-card">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title">⚙️ <?php esc_html_e( 'Solución de problemas', 'arriendo-facil' ); ?></h2>
			</div>
		</header>

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
	</section>

	<!-- WEBHOOK INFO (Optional) -->
	<section class="af-section af-integration-card">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title">🔔 <?php esc_html_e( 'Webhooks (sincronización en tiempo real – opcional)', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Para usuarios avanzados que necesiten propagación instantánea.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

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

		<p style="color: var(--af-gray-500); font-size: 13px;">
			<?php esc_html_e( 'Nota: Por ahora, la sincronización automática cada 30 minutos es suficiente para la mayoría de casos. Los webhooks son opcionales para usuarios avanzados.', 'arriendo-facil' ); ?>
		</p>
	</section>
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
