<?php
/**
 * Centro de alertas — histórico + configuración de la cuenta.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Arriendo_Facil_Alerts' ) ) {
	return;
}

$alerts_service = new Arriendo_Facil_Alerts();
$user_id        = get_current_user_id();
$settings       = Arriendo_Facil_Alerts::get_settings( $user_id );

$history = Arriendo_Facil_Alerts::get_for_user( $user_id, 60, false );

$severity_meta = Arriendo_Facil_Alerts::severity_meta();

$unread_count = Arriendo_Facil_Alerts::count_unread( $user_id );

$severity_icons = array(
	'info'    => 'info',
	'warning' => 'circle-alert',
	'danger'  => 'triangle-alert',
);
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Centro de alertas', 'arriendo-facil' ),
			'title'    => __( 'Alertas operativas', 'arriendo-facil' ),
			'subtitle' => __( 'Revisa los avisos del sistema y configura cómo recordarte cada pendiente: en el ícono de campana y, opcionalmente, por correo.', 'arriendo-facil' ),
			'actions'  => array(
				sprintf(
					'<button type="button" class="button af-btn af-btn--primary" data-af-alerts-center-mark-all %s><span class="af-btn__icon" aria-hidden="true">%s</span>%s</button>',
					$unread_count ? '' : 'disabled',
					af_lucide( 'check-check', 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper.
					esc_html__( 'Marcar todas como leídas', 'arriendo-facil' )
				),
			),
		)
	);
	?>

	<div class="af-alerts-center__grid">

		<section class="af-section" aria-labelledby="af-alerts-history-title">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-alerts-history-title">
						<?php esc_html_e( 'Histórico de alertas', 'arriendo-facil' ); ?>
					</h2>
					<p class="af-section__subtitle">
						<?php
						printf(
							/* translators: 1: unread count, 2: total count */
							esc_html__( '%1$d por leer de %2$d alertas mostradas.', 'arriendo-facil' ),
							(int) $unread_count,
							(int) count( $history )
						);
						?>
					</p>
				</div>
			</header>

			<?php if ( empty( $history ) ) : ?>
				<div class="af-alerts-center__empty">
					<?php echo af_lucide( 'bell-off', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
					<p><?php esc_html_e( 'Aún no hay alertas. El sistema las genera automáticamente (contratos por vencer, cargos vencidos, lecturas faltantes…).', 'arriendo-facil' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="af-alerts-center__list">
					<?php foreach ( $history as $alert ) : ?>
						<?php
						$alert_severity = isset( $alert->severity ) ? (string) $alert->severity : 'info';
						$alert_sev_meta = isset( $severity_meta[ $alert_severity ] ) ? $severity_meta[ $alert_severity ] : array( 'label' => $alert_severity, 'icon' => 'info' );
						$alert_icon     = isset( $severity_icons[ $alert_severity ] ) ? $severity_icons[ $alert_severity ] : 'info';
						$alert_url      = ! empty( $alert->url ) ? (string) $alert->url : '';
						?>
						<li class="af-alerts-center__item af-alerts-center__item--<?php echo esc_attr( $alert_severity ); ?><?php echo $alert->is_read ? ' is-read' : ''; ?>">
							<span class="af-alerts-center__icon"><?php echo af_lucide( $alert_icon, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							<div class="af-alerts-center__body">
								<span class="af-alerts-center__title"><?php echo esc_html( (string) ( $alert->title ?? '' ) ); ?></span>
								<?php if ( ! empty( $alert->message ) ) : ?>
									<span class="af-alerts-center__message"><?php echo esc_html( (string) $alert->message ); ?></span>
								<?php endif; ?>
								<span class="af-alerts-center__meta">
									<?php echo esc_html( (string) $alert_sev_meta['label'] ); ?> &middot;
									<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), (string) $alert->created_at ) ); ?>
								</span>
							</div>
							<div class="af-alerts-center__actions">
								<?php if ( $alert_url ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $alert_url ); ?>" target="_blank">
										<?php esc_html_e( 'Ver', 'arriendo-facil' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( ! $alert->is_read ) : ?>
									<button type="button" class="button button-small" data-af-alerts-center-mark-read="<?php echo esc_attr( (int) $alert->id ); ?>">
										<?php esc_html_e( 'Marcar como leída', 'arriendo-facil' ); ?>
									</button>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<aside class="af-section" aria-labelledby="af-alerts-settings-title">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-alerts-settings-title">
						<?php esc_html_e( 'Mi configuración', 'arriendo-facil' ); ?>
					</h2>
					<p class="af-section__subtitle">
						<?php esc_html_e( 'Configura cómo recibir los recordatorios de esta cuenta, tanto en el sistema interno como por correo.', 'arriendo-facil' ); ?>
					</p>
				</div>
			</header>

			<form id="af-alerts-settings-form" class="af-alerts-center__settings-list" novalidate>
				<label class="af-alerts-center__toggle">
					<input type="checkbox" name="email_enabled" value="1" <?php checked( ! empty( $settings['email_enabled'] ) ); ?> />
					<span>
						<?php esc_html_e( 'Recibir recordatorios por correo', 'arriendo-facil' ); ?>
						<span class="af-field-hint"><?php esc_html_e( 'Cada día, las alertas pendientes se envían a tu correo como recordatorio.', 'arriendo-facil' ); ?></span>
					</span>
				</label>

				<label>
					<span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Correo para recordatorios', 'arriendo-facil' ); ?></span>
					<input type="email" name="email_address" style="width:100%;" value="<?php echo esc_attr( ! empty( $settings['email'] ) ? $settings['email'] : $settings['default_email'] ); ?>" placeholder="<?php echo esc_attr( $settings['default_email'] ); ?>" />
					<span class="af-field-hint"><?php esc_html_e( 'Si lo dejas vacío, se usará el correo de tu cuenta.', 'arriendo-facil' ); ?></span>
				</label>

				<div style="display:flex;gap:8px;align-items:center;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar configuración', 'arriendo-facil' ); ?></button>
				</div>
				<p id="af-alerts-settings-status" class="af-alerts-center__status" aria-live="polite"></p>
			</form>

			<hr style="border:0;border-top:1px solid var(--af-gray-200,#E2E1DB);margin:20px 0;" />

			<h3 style="margin:0 0 6px;font-size:14px;font-weight:700;"><?php esc_html_e( 'Enviar recordatorio ahora', 'arriendo-facil' ); ?></h3>
			<p style="margin:0 0 12px;font-size:13px;color:#5B594F;">
				<?php esc_html_e( 'Envía de inmediato las alertas pendientes al correo configurado.', 'arriendo-facil' ); ?>
			</p>
			<button type="button" class="button" id="af-alerts-send-reminder">
				<?php esc_html_e( 'Enviar recordatorio por correo', 'arriendo-facil' ); ?>
			</button>
			<p id="af-alerts-send-status" class="af-alerts-center__status" aria-live="polite"></p>

			<hr style="border:0;border-top:1px solid var(--af-gray-200,#E2E1DB);margin:20px 0;" />

			<p style="margin:0;font-size:12px;color:#7A7870;line-height:1.6;">
				<?php esc_html_e( 'Las alertas operativas se generan todos los días: contratos por vencer o vencidos, cargos de pago vencidos y lecturas de medidor faltantes del período. Siempre aparecen en el ícono de campana del panel.', 'arriendo-facil' ); ?>
			</p>
		</aside>
	</div>
</div>