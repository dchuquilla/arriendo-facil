<?php
/**
 * Shared view helpers for admin templates.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'af_pill' ) ) {
	/**
	 * Renders a status pill mapped from DB status strings to semantic variants.
	 *
	 * @param string      $status Raw status value.
	 * @param string|null $label  Optional custom label; defaults to a localized version of $status.
	 * @return string HTML.
	 */
	function af_pill( $status, $label = null ) {
		$key = sanitize_key( (string) $status );

		$map = array(
			'active'              => array( 'success', __( 'Activo', 'arriendo-facil' ) ),
			'completed'           => array( 'success', __( 'Completada', 'arriendo-facil' ) ),
			'paid'                => array( 'success', __( 'Pagado', 'arriendo-facil' ) ),
			'approved'            => array( 'success', __( 'Aprobada', 'arriendo-facil' ) ),
			'available'           => array( 'success', __( 'Disponible', 'arriendo-facil' ) ),
			'autorizada'          => array( 'success', __( 'Autorizada', 'arriendo-facil' ) ),
			'autorizada_sin_ride' => array( 'success', __( 'Autorizada', 'arriendo-facil' ) ),
			'firmada'             => array( 'info',    __( 'Firmada', 'arriendo-facil' ) ),
			'enviada'             => array( 'info',    __( 'Enviada', 'arriendo-facil' ) ),
			'sent'                => array( 'info',    __( 'Enviada', 'arriendo-facil' ) ),
			'synced'              => array( 'info',    __( 'Sincronizada', 'arriendo-facil' ) ),
			'draft'               => array( 'neutral', __( 'Borrador', 'arriendo-facil' ) ),
			'generada'            => array( 'neutral', __( 'Generada', 'arriendo-facil' ) ),
			'inactive'            => array( 'neutral', __( 'Inactivo', 'arriendo-facil' ) ),
			'pending'             => array( 'warning', __( 'Pendiente', 'arriendo-facil' ) ),
			'in_progress'         => array( 'warning', __( 'En curso', 'arriendo-facil' ) ),
			'pending_release'     => array( 'warning', __( 'Por liberar', 'arriendo-facil' ) ),
			'anulada'             => array( 'warning', __( 'Anulada', 'arriendo-facil' ) ),
			'maintenance'         => array( 'warning', __( 'Mantenimiento', 'arriendo-facil' ) ),
			'expired'             => array( 'danger',  __( 'Vencido', 'arriendo-facil' ) ),
			'terminated'          => array( 'danger',  __( 'Terminado', 'arriendo-facil' ) ),
			'rejected'            => array( 'danger',  __( 'Rechazada', 'arriendo-facil' ) ),
			'occupied'            => array( 'danger',  __( 'Ocupado', 'arriendo-facil' ) ),
			'rented'              => array( 'danger',  __( 'Arrendado', 'arriendo-facil' ) ),
			'devuelta'            => array( 'danger',  __( 'Devuelta', 'arriendo-facil' ) ),
			'no_autorizada'       => array( 'danger',  __( 'No autorizada', 'arriendo-facil' ) ),
			'rechazada'           => array( 'danger',  __( 'Rechazada', 'arriendo-facil' ) ),
			'error_envio'         => array( 'danger',  __( 'Error de envío', 'arriendo-facil' ) ),
			'error_autorizacion'  => array( 'danger',  __( 'Error de autorización', 'arriendo-facil' ) ),
		);

		if ( isset( $map[ $key ] ) ) {
			$variant = $map[ $key ][0];
			$text    = null !== $label ? (string) $label : $map[ $key ][1];
		} else {
			$variant = 'neutral';
			$text    = null !== $label ? (string) $label : ucfirst( str_replace( '_', ' ', $key ) );
		}

		return sprintf(
			'<span class="af-pill af-pill--%1$s">%2$s</span>',
			esc_attr( $variant ),
			esc_html( $text )
		);
	}
}

if ( ! function_exists( 'af_page_header' ) ) {
	/**
	 * Renders a consistent page header for admin views.
	 *
	 * @param array $args {
	 *     @type string $eyebrow  Optional short label above the title.
	 *     @type string $title    Main heading (required).
	 *     @type string $subtitle Optional descriptive line.
	 *     @type array  $actions  Optional array of buttons; each item may be raw HTML string
	 *                            or associative array with keys: label, url, variant (primary|ghost|accent), icon.
	 * }
	 * @return void Echoes markup.
	 */
	function af_page_header( array $args ) {
		$eyebrow  = isset( $args['eyebrow'] ) ? (string) $args['eyebrow'] : '';
		$title    = isset( $args['title'] ) ? (string) $args['title'] : '';
		$subtitle = isset( $args['subtitle'] ) ? (string) $args['subtitle'] : '';
		$actions  = isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array();
		?>
		<header class="af-page-header">
			<div class="af-page-header__title">
				<?php if ( '' !== $eyebrow ) : ?>
					<span class="af-page-header__eyebrow"><?php echo esc_html( $eyebrow ); ?></span>
				<?php endif; ?>
				<h1><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== $subtitle ) : ?>
					<p class="af-page-header__subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $actions ) ) : ?>
				<div class="af-page-header__actions">
					<?php foreach ( $actions as $action ) : ?>
						<?php
						if ( is_string( $action ) ) {
							// Raw HTML (already escaped by caller).
							echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							continue;
						}
						if ( ! is_array( $action ) || empty( $action['label'] ) ) {
							continue;
						}
						$variant = isset( $action['variant'] ) ? sanitize_key( $action['variant'] ) : 'ghost';
						$url     = isset( $action['url'] ) ? (string) $action['url'] : '#';
						$icon    = isset( $action['icon'] ) ? (string) $action['icon'] : '';
						$class   = 'button af-btn af-btn--' . ( in_array( $variant, array( 'primary', 'ghost', 'accent' ), true ) ? $variant : 'ghost' );
						?>
						<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>">
							<?php if ( '' !== $icon ) : ?>
								<span class="af-btn__icon" aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<?php endif; ?>
							<?php echo esc_html( $action['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</header>
		<?php
	}
}

if ( ! function_exists( 'af_lucide' ) ) {
	/**
	 * Renders a Lucide-style icon as inline SVG.
	 *
	 * Stroke-based icons (24x24, stroke="currentColor"), vendored as static
	 * paths so the plugin keeps working without any CDN/build dependency.
	 *
	 * @param string $name Icon key from the map below.
	 * @param int    $size Render size in pixels (CSS width/height).
	 * @return string SVG markup (empty string for unknown icons).
	 */
	function af_lucide( $name, $size = 20 ) {
		static $icons = array(
			'layout-dashboard'   => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
			'building-2'         => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
			'layout-grid'        => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
			'building'           => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 22h12"/><path d="M10 10h.01"/><path d="M14 10h.01"/><path d="M10 14h.01"/><path d="M14 14h.01"/>',
			'wrench'             => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
			'file-text'          => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
			'log-out'            => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
			'log-in'             => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
			'user-plus'          => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
			'clock'              => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
			'sparkles'           => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>',
			'bot'                => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
			'check'              => '<path d="M20 6L9 17l-5-5"/>',
			'trending-up'        => '<path d="M22 7l-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
			'map-pin'            => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
			'bed'                => '<path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/>',
			'bath'               => '<path d="M9 5 7.62 3.62A2 2 0 0 0 4 4v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2a2 2 0 0 0-2-2H7"/><path d="M10 4 8 6"/><path d="M2 12h20"/><path d="M7 19v2"/><path d="M17 19v2"/>',
			'store'              => '<path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/>',
			'users'              => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
			'credit-card'        => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
			'circle-alert'       => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
			'calendar'           => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
			'star'               => '<path d="M12 2l3 6.5 7 .9-5.1 4.7 1.3 7L12 17.8 5.8 21l1.3-7L2 9.4l7-.9L12 2z"/>',
			'receipt'            => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
			'settings'           => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
			'user-check'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M16 11l2 2 4-4"/>',
			'user'               => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
			'home'               => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
			'bell'               => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
			'search'             => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
			'chevron-down'       => '<path d="M6 9l6 6 6-6"/>',
			'chevrons-left'      => '<path d="M11 17l-5-5 5-5"/><path d="M18 17l-5-5 5-5"/>',
			'menu'               => '<line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/>',
			'globe'              => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
			'puzzle'             => '<path d="M19.439 7.85c-.049.322.059.648.289.878l1.568 1.568c.47.47.706 1.087.706 1.704s-.235 1.233-.706 1.704l-1.611 1.611a.98.98 0 0 1-.837.276c-.47-.07-.802-.48-.968-.925a2.501 2.501 0 1 0-3.214 3.214c.446.166.855.497.925.968a.979.979 0 0 1-.276.837l-1.61 1.61a2.404 2.404 0 0 1-1.705.706 2.402 2.402 0 0 1-1.704-.706l-1.568-1.568a1.026 1.026 0 0 0-.877-.29c-.493.074-.84.504-1.02.968a2.5 2.5 0 1 1-3.237-3.237c.464-.18.894-.527.967-1.02a1.026 1.026 0 0 0-.289-.877l-1.568-1.568A2.402 2.402 0 0 1 1.84 12c0-.617.236-1.234.706-1.704L4.157 8.686c.305-.305.67-.49 1.078-.49.284 0 .529.112.738.322.492.491.74 1.188.74 1.87 0 .618.237 1.235.706 1.705.209.209.442.318.71.318.269 0 .526-.112.733-.319.196-.197.294-.457.294-.722V8.96a1 1 0 0 1 1-1h.986a1 1 0 0 1 1 1v.645c.212.04.438.027.683-.028.348-.08.686-.295.928-.585.288-.345.441-.785.441-1.268 0-1.049.416-2.047 1.158-2.789a2.404 2.404 0 0 1 1.705-.707z"/>',
			'sliders-horizontal' => '<line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/><line x1="14" x2="14" y1="2" y2="6"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="16" x2="16" y1="18" y2="22"/>',
			'check-circle'       => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
			'circle-check'       => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
			'triangle-alert'     => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 20h16a2 2 0 0 0 1.73-2"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
			'refresh-cw'         => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
			'shield-check'       => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
			'link-2'             => '<path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><line x1="8" x2="16" y1="12" y2="12"/>',
			'upload'             => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
			'save'               => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
			'plus'               => '<path d="M5 12h14"/><path d="M12 5v14"/>',
			'wallet'             => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
		);

		if ( ! isset( $icons[ $name ] ) ) {
			return '';
		}

		return sprintf(
			'<svg class="af-icon af-icon--%1$s" xmlns="http://www.w3.org/2000/svg" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%3$s</svg>',
			sanitize_key( $name ),
			absint( $size ),
			$icons[ $name ]
		);
	}
}
