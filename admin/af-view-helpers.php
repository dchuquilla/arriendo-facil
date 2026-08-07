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
