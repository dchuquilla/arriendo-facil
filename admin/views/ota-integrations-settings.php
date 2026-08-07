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
	<section class="af-section af-integration-card af-integration-card--booking">
		<header class="af-section__header af-integration-card__head">
			<div class="af-integration-brand af-integration-brand--booking" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 9h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
			</div>
			<div>
				<h2 class="af-section__title">Booking.com</h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Sincroniza reservas y disponibilidad desde tu extranet de Booking.', 'arriendo-facil' ); ?></p>
			</div>
			<span class="af-pill af-pill--info af-integration-card__tag">iCal · 30 min</span>
		</header>

		<h3><?php esc_html_e( 'Paso 1: Obtén tu URL iCal de Booking', 'arriendo-facil' ); ?></h3>
		<ol class="af-step-list">
			<li><?php esc_html_e( 'Ve a tu panel de Booking: ', 'arriendo-facil' ); ?><a href="https://secure.booking.com/" target="_blank" rel="noopener">secure.booking.com</a></li>
			<li><?php esc_html_e( 'En el menú superior: Haz click en tu nombre (arriba a la derecha)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Ve a: Anuncios de propiedades', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Selecciona tu propiedad', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'En el menú de la izquierda: Ve a: Precios y disponibilidad', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Baja a la sección: "Sincronización del calendario"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Verás el botón: "Exportar calendario"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Copia el enlace que termina en .ics', 'arriendo-facil' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Paso 2: Configura en ArriendoFácil', 'arriendo-facil' ); ?></h3>
		<ol class="af-step-list">
			<li><?php esc_html_e( 'En el menú izquierdo: Ve a: Arriendo Fácil → Inmuebles', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Selecciona la propiedad que quieres sincronizar', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Baja hasta el final de la página (scroll hacia abajo)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Verás la sección azul: "Sincronización OTA (iCal)"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'En la sección "Booking.com", pega la URL iCal en el campo', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Ingresa también tu Property ID de Booking (número de 8 dígitos de tu propiedad)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en "Probar URL" para validar que funciona', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Si ves un check verde, significa que funciona correctamente', 'arriendo-facil' ); ?></li>
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
	<section class="af-section af-integration-card af-integration-card--airbnb">
		<header class="af-section__header af-integration-card__head">
			<div class="af-integration-brand af-integration-brand--airbnb" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M3 11l9-8 9 8v9a2 2 0 01-2 2h-4v-6h-6v6H5a2 2 0 01-2-2v-9z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
			</div>
			<div>
				<h2 class="af-section__title">Airbnb</h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Sincroniza reservas y disponibilidad desde tu panel de host de Airbnb.', 'arriendo-facil' ); ?></p>
			</div>
			<span class="af-pill af-pill--info af-integration-card__tag">iCal · 30 min</span>
		</header>

		<h3><?php esc_html_e( 'Paso 1: Obtén tu URL iCal de Airbnb', 'arriendo-facil' ); ?></h3>
		<ol class="af-step-list">
			<li><?php esc_html_e( 'Ve a tu panel de Airbnb: ', 'arriendo-facil' ); ?><a href="https://www.airbnb.com/hosting/homes" target="_blank" rel="noopener">airbnb.com/hosting/homes</a></li>
			<li><?php esc_html_e( 'Ve a: Anuncios → Mis anuncios', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Selecciona la propiedad que quieres sincronizar', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'En el menú de la izquierda: Ve a: Calendario', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'En la esquina superior derecha, verás un engranaje', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en el engranaje', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Se abrirá un menú, busca: "Opciones del calendario"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en: "Exportar"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Copia el enlace que termina en .ics', 'arriendo-facil' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Paso 2: Configura en ArriendoFácil', 'arriendo-facil' ); ?></h3>
		<ol class="af-step-list">
			<li><?php esc_html_e( 'En el menú izquierdo: Ve a: Arriendo Fácil → Inmuebles', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Selecciona la misma propiedad', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Baja hasta el final de la página (scroll hacia abajo)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Verás la sección azul: "Sincronización OTA (iCal)"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'En la sección "Airbnb", pega la URL iCal en el campo', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Ingresa también tu Listing ID de Airbnb (número de tu anuncio: airbnb.com/rooms/[AQUI_ESTA_EL_ID])', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz click en "Probar URL" para validar que funciona', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Si ves un check verde, significa que funciona correctamente', 'arriendo-facil' ); ?></li>
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
		<header class="af-section__header af-integration-card__head">
			<div class="af-integration-brand af-integration-brand--neutral" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.9-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1-1.5 1.7 1.7 0 00-1.9.3l-.1.1A2 2 0 114.2 17l.1-.1a1.7 1.7 0 00.3-1.9 1.7 1.7 0 00-1.5-1H3a2 2 0 010-4h.1a1.7 1.7 0 001.5-1 1.7 1.7 0 00-.3-1.9l-.1-.1A2 2 0 117 4.2l.1.1a1.7 1.7 0 001.9.3H9a1.7 1.7 0 001-1.5V3a2 2 0 014 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.9-.3l.1-.1A2 2 0 1119.8 7l-.1.1a1.7 1.7 0 00-.3 1.9V9a1.7 1.7 0 001.5 1H21a2 2 0 010 4h-.1a1.7 1.7 0 00-1.5 1z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
			</div>
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Solución de problemas', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Errores comunes y cómo resolverlos.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

		<h3><?php esc_html_e( '¿No veo la sección "Sincronización OTA (iCal)"?', 'arriendo-facil' ); ?></h3>
		<ul class="af-step-list">
			<li><?php esc_html_e( 'Asegúrate de estar en: Arriendo Fácil → Inmuebles (no otra sección)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Editando un inmueble existente (no creando uno nuevo)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Haz scroll hacia ABAJO en el editor hasta el final', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'La sección debe estar en color azul claro', 'arriendo-facil' ); ?></li>
		</ul>

		<h3><?php esc_html_e( '¿La URL iCal no funciona?', 'arriendo-facil' ); ?></h3>
		<ul class="af-step-list">
			<li><?php esc_html_e( 'Verifica que copiaste el enlace completo (debe terminar en .ics)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Verifica que no hay espacios en blanco al inicio o final', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Intenta acceder a la URL directamente en el navegador para verificar que existe', 'arriendo-facil' ); ?></li>
		</ul>

		<h3><?php esc_html_e( '¿No se sincroniza automáticamente?', 'arriendo-facil' ); ?></h3>
		<ul class="af-step-list">
			<li><?php esc_html_e( 'Verifica que activaste el checkbox "Sincronización automática"', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Verifica que guardaste los cambios (click en "Actualizar")', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Intenta usar el botón "Sincronizar Ahora" para probar manualmente', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'La sincronización automática se ejecuta cada 30 minutos (no es en tiempo real)', 'arriendo-facil' ); ?></li>
		</ul>

		<h3><?php esc_html_e( '¿Dónde veo el historial de sincronizaciones?', 'arriendo-facil' ); ?></h3>
		<ul class="af-step-list">
			<li><?php esc_html_e( 'Ve a: Arriendo Fácil → Sincronización OTA (es un nuevo menú)', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Allí verás todas las sincronizaciones, errores y estadísticas', 'arriendo-facil' ); ?></li>
		</ul>
	</section>

	<!-- WEBHOOK INFO (Optional) -->
	<section class="af-section af-integration-card">
		<header class="af-section__header af-integration-card__head">
			<div class="af-integration-brand af-integration-brand--accent" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</div>
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Webhooks (tiempo real, opcional)', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Para usuarios avanzados que necesiten propagación instantánea.', 'arriendo-facil' ); ?></p>
			</div>
			<span class="af-pill af-pill--neutral af-integration-card__tag"><?php esc_html_e( 'Avanzado', 'arriendo-facil' ); ?></span>
		</header>

		<p><?php esc_html_e( 'Si quieres que se sincronice en tiempo real (cuando cambias disponibilidad), puedes configurar webhooks en Booking.com y Airbnb.', 'arriendo-facil' ); ?></p>

		<p><strong><?php esc_html_e( 'URLs de Webhooks para configurar manualmente en Booking y Airbnb:', 'arriendo-facil' ); ?></strong></p>

		<div class="af-code-block">
			<div class="af-code-block__label"><?php esc_html_e( 'Booking.com Webhook', 'arriendo-facil' ); ?></div>
			<code><?php echo esc_html( rest_url( 'af/v1/ota/webhook/booking' ) ); ?></code>
		</div>

		<div class="af-code-block">
			<div class="af-code-block__label"><?php esc_html_e( 'Airbnb Webhook', 'arriendo-facil' ); ?></div>
			<code><?php echo esc_html( rest_url( 'af/v1/ota/webhook/airbnb' ) ); ?></code>
		</div>

		<p class="af-note">
			<?php esc_html_e( 'Nota: Por ahora, la sincronización automática cada 30 minutos es suficiente para la mayoría de casos. Los webhooks son opcionales para usuarios avanzados.', 'arriendo-facil' ); ?>
		</p>
	</section>
</div>
