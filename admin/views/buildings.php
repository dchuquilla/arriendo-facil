<?php
/**
 * Collection hub per property ("Cobranza por inmueble").
 *
 * Every unit is a tenant to charge (canon + alicuota + services). Each property
 * is a card showing what is pending and when it is due; clicking the card opens
 * a modal with the month's charge calendar and lets you register a payment
 * inline.
 *
 * The snapshot itself lives in Arriendo_Facil_Billing_Ledger::cobranza_snapshot()
 * so the very same data can be refreshed over AJAX after a payment is recorded
 * (af_cobranza_snapshot) instead of reloading the page.
 *
 * The script and the data payload are inlined at the end of this file on
 * purpose: this view is deployed as a single file, so the modal works without
 * depending on a separately enqueued asset.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$af_snap     = Arriendo_Facil_Billing_Ledger::cobranza_snapshot();
$cob_props   = $af_snap['props'];
$cob_summary = $af_snap['summary'];
$cob_json_props = $cob_props;
$current_period  = $af_snap['period'];
$cob_today       = $af_snap['today'];
$cob_status_meta = $af_snap['statuses'];
$af_alerts       = $af_snap['display'];

// Los disponibles tienen su propio bloque colapsable más abajo: no se repiten en la grilla principal.
$cob_principal = array_filter(
	$cob_props,
	static function ( $p ) {
		return 'available' !== $p['status'];
	}
);
?>

<div class="wrap af-shell af-buildings-page">
	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Cobranza', 'arriendo-facil' ),
			'title'    => __( 'Cobranza por inmueble', 'arriendo-facil' ),
			'subtitle' => __( 'Tus inmuebles a cobrar, por tarjeta: cuánto falta por cobrar y cuándo vence. Pulsa una tarjeta para abrir el calendario de cobros del mes.', 'arriendo-facil' ),
		)
	);
	?>

	<?php if ( empty( $cob_props ) && 0 === $cob_summary['disponibles'] ) : ?>
		<div class="af-empty">
			<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'building', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<h3 class="af-empty__title"><?php esc_html_e( 'Aún no tienes inmuebles registrados', 'arriendo-facil' ); ?></h3>
			<p class="af-empty__text"><?php esc_html_e( 'Registra tus propiedades en el Catálogo de inmuebles para que aparezcan aquí, con su canon y sus fechas de cobro.', 'arriendo-facil' ); ?></p>
			<a class="button af-btn af-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=af-catalog' ) ); ?>"><?php esc_html_e( 'Ir al catálogo', 'arriendo-facil' ); ?></a>
		</div>
	<?php else : ?>
		<section class="af-cobranza-alerts" id="af-cobranza-alerts">
			<div class="af-cobranza-alert af-cobranza-alert--danger" data-alert="vencido"<?php echo empty( $af_alerts['vencido'] ) ? ' hidden' : ''; ?>>
				<span class="af-cobranza-alert__icon" aria-hidden="true"><?php echo af_lucide( 'triangle-alert', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<div class="af-cobranza-alert__body">
					<strong data-alert-title><?php echo esc_html( ! empty( $af_alerts['vencido'] ) ? $af_alerts['vencido']['title'] : '' ); ?></strong>
					<span data-alert-text><?php echo esc_html( ! empty( $af_alerts['vencido'] ) ? $af_alerts['vencido']['text'] : '' ); ?></span>
				</div>
			</div>

			<div class="af-cobranza-alert af-cobranza-alert--warning" data-alert="proximo"<?php echo empty( $af_alerts['proximo'] ) ? ' hidden' : ''; ?>>
				<span class="af-cobranza-alert__icon" aria-hidden="true"><?php echo af_lucide( 'clock', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<div class="af-cobranza-alert__body">
					<strong data-alert-title><?php echo esc_html( ! empty( $af_alerts['proximo'] ) ? $af_alerts['proximo']['title'] : '' ); ?></strong>
					<span data-alert-text><?php echo esc_html( ! empty( $af_alerts['proximo'] ) ? $af_alerts['proximo']['text'] : '' ); ?></span>
				</div>
			</div>

			<div class="af-cobranza-alert af-cobranza-alert--ok" data-alert="mes">
				<span class="af-cobranza-alert__icon" aria-hidden="true"><?php echo af_lucide( 'calendar', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<div class="af-cobranza-alert__body">
					<strong data-alert-title><?php echo esc_html( $af_alerts['mes']['title'] ); ?></strong>
					<span data-alert-text><?php echo esc_html( $af_alerts['mes']['text'] ); ?></span>
				</div>
			</div>
		</section>

		<?php if ( ! empty( $cob_principal ) ) : ?>
			<div class="af-property-grid af-cobranza-grid">
			<?php foreach ( $cob_principal as $prop ) : ?>
				<?php
				$csm       = $cob_status_meta[ $prop['status'] ];
				$address   = trim( trim( (string) $prop['address'] ) . ( $prop['city'] ? ', ' . $prop['city'] : '' ) );
				$due_hint  = $prop['due_hint'];
				$due_class = $prop['due_class'];
				?>
				<article
					class="af-property-card af-cobranza-card <?php echo esc_attr( $csm['card'] ); ?>"
					data-prop="<?php echo esc_attr( $prop['id'] ); ?>"
					role="button"
					tabindex="0"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: property title */ __( 'Ver cobranza de %s', 'arriendo-facil' ), $prop['title'] ) ); ?>"
				>
					<div class="af-property-card__media">
						<?php if ( $prop['thumb'] ) : ?>
							<img src="<?php echo esc_url( $prop['thumb'] ); ?>" alt="<?php echo esc_attr( $prop['title'] ); ?>" loading="lazy" />
						<?php else : ?>
							<div class="af-property-card__placeholder" aria-hidden="true"><?php echo af_lucide( 'building', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></div>
						<?php endif; ?>
						<div class="af-property-card__badges">
							<span class="af-pill af-pill--<?php echo esc_attr( $csm['pill'] ); ?>" data-role="pill"><?php echo esc_html( $csm['label'] ); ?></span>
						</div>
					</div>
					<div class="af-property-card__body">
						<div class="af-catalog-card__type">
							<span class="af-catalog-card__type-icon" aria-hidden="true"><?php echo af_lucide( $prop['type_icon'], 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							<?php echo esc_html( $prop['type_label'] ); ?>
						</div>
						<h3 class="af-property-card__title"><?php echo esc_html( $prop['title'] ); ?></h3>
						<?php if ( $prop['unit_code'] ) : ?>
							<p class="af-catalog-card__address">
								<span class="af-catalog-card__address-icon" aria-hidden="true"><?php echo af_lucide( 'home', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
								<span class="af-catalog-card__address-text"><?php echo esc_html( $prop['unit_code'] . ( $prop['building_name'] ? ' · ' . $prop['building_name'] : '' ) ); ?></span>
							</p>
						<?php endif; ?>
						<?php if ( $address ) : ?>
							<p class="af-catalog-card__address">
								<span class="af-catalog-card__address-icon" aria-hidden="true"><?php echo af_lucide( 'map-pin', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
								<span class="af-catalog-card__address-text"><?php echo esc_html( $address ); ?></span>
							</p>
						<?php endif; ?>
						<p class="af-catalog-card__specs">
							<?php if ( $prop['guest'] ) : ?>
								<span class="af-catalog-card__spec">
									<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'user', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
									<?php echo esc_html( $prop['guest'] ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $prop['bedrooms'] ) : ?>
								<span class="af-catalog-card__spec">
									<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'bed', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
									<?php echo esc_html( sprintf( /* translators: %d: bedrooms */ _n( '%d dorm.', '%d dorm.', $prop['bedrooms'], 'arriendo-facil' ), $prop['bedrooms'] ) ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $prop['lease_end'] ) : ?>
								<span class="af-catalog-card__spec">
									<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'calendar', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
									<?php echo esc_html( sprintf( /* translators: %s: formatted date */ __( 'Contrato hasta %s', 'arriendo-facil' ), date_i18n( 'j M Y', strtotime( $prop['lease_end'] ) ) ) ); ?>
								</span>
							<?php endif; ?>
						</p>
					</div>
					<div class="af-property-card__footer">
						<span class="af-cobranza-card__amount <?php echo esc_attr( $due_class ); ?>" data-role="amount">
							$<?php echo esc_html( number_format_i18n( $prop['amount_total'], 2 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- number_format_i18n returns a localized number. ?>
							<small data-role="amount-label"><?php echo esc_html( $prop['amount_label'] ); ?></small>
						</span>
						<span class="af-cobranza-card__due <?php echo esc_attr( $due_class ); ?>" data-role="due"><?php echo esc_html( $due_hint ); ?></span>
						<span class="af-catalog-card__hint">
							<?php esc_html_e( 'Ver cobranza', 'arriendo-facil' ); ?>
							<span class="af-catalog-card__hint-arrow" aria-hidden="true">&rarr;</span>
						</span>
					</div>
				</article>
			<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $cob_summary['disponibles'] > 0 ) : ?>
			<?php $disponibles = array_filter( $cob_props, static function ( $p ) { return 'available' === $p['status']; } ); ?>
			<details class="af-collapse af-section af-cobranza-avail">
				<summary class="af-collapse__summary">
					<span class="af-section__icon af-section__icon--slate" aria-hidden="true"><?php echo af_lucide( 'building', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<span class="af-collapse__label"><?php echo esc_html( sprintf( /* translators: %d: number of properties */ _n( '%d inmueble disponible (sin contrato)', '%d inmuebles disponibles (sin contrato)', $cob_summary['disponibles'], 'arriendo-facil' ), $cob_summary['disponibles'] ) ); ?></span>
					<span class="af-collapse__chevron" aria-hidden="true"><?php echo af_lucide( 'chevron-down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				</summary>
				<div class="af-collapse__body">
					<div class="af-property-grid">
						<?php foreach ( $disponibles as $prop ) : ?>
							<?php $csm = $cob_status_meta[ $prop['status'] ]; ?>
							<article class="af-property-card af-cobranza-card <?php echo esc_attr( $csm['card'] ); ?>" data-prop="<?php echo esc_attr( $prop['id'] ); ?>" role="button" tabindex="0" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: property title */ __( 'Ver cobranza de %s', 'arriendo-facil' ), $prop['title'] ) ); ?>">
								<div class="af-property-card__media">
									<?php if ( $prop['thumb'] ) : ?>
										<img src="<?php echo esc_url( $prop['thumb'] ); ?>" alt="<?php echo esc_attr( $prop['title'] ); ?>" loading="lazy" />
									<?php else : ?>
										<div class="af-property-card__placeholder" aria-hidden="true"><?php echo af_lucide( 'building', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></div>
									<?php endif; ?>
									<div class="af-property-card__badges"><span class="af-pill af-pill--<?php echo esc_attr( $csm['pill'] ); ?>"><?php echo esc_html( $csm['label'] ); ?></span></div>
								</div>
								<div class="af-property-card__body">
									<div class="af-catalog-card__type">
										<span class="af-catalog-card__type-icon" aria-hidden="true"><?php echo af_lucide( $prop['type_icon'], 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
										<?php echo esc_html( $prop['type_label'] ); ?>
									</div>
									<h3 class="af-property-card__title"><?php echo esc_html( $prop['title'] ); ?></h3>
									<p class="af-catalog-card__address"><span class="af-catalog-card__address-text"><?php esc_html_e( 'Sin contrato activo: no genera cobros este mes.', 'arriendo-facil' ); ?></span></p>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				</div>
			</details>
		<?php endif; ?>
	<?php endif; ?>
</div><!-- .wrap -->

<!-- Modal: cobranza del inmueble (calendario de cobros del mes) -->
<div class="af-modal" id="af-cobranza-modal" role="dialog" aria-modal="true" aria-labelledby="af-cobranza-modal-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog af-cobranza-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-cobranza-modal-title"></h2>
			<p class="af-modal__subtitle" id="af-cobranza-modal-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-cobranza-status" style="display:none;"></p>

			<div class="af-cobranza-cal__nav">
				<button type="button" class="button af-cobranza-cal__nav-btn" data-cal="prev" aria-label="<?php esc_attr_e( 'Mes anterior', 'arriendo-facil' ); ?>">&larr;</button>
				<h3 class="af-cobranza-cal__period" id="af-cobranza-period"></h3>
				<button type="button" class="button af-cobranza-cal__nav-btn" data-cal="next" aria-label="<?php esc_attr_e( 'Mes siguiente', 'arriendo-facil' ); ?>">&rarr;</button>
			</div>
			<div class="af-cobranza-cal__today-row">
				<button type="button" class="button af-cobranza-cal__today" data-cal="today"><?php esc_html_e( 'Hoy', 'arriendo-facil' ); ?></button>
			</div>

			<div class="af-calendar-mini" id="af-cobranza-calendar" aria-label="<?php esc_attr_e( 'Calendario de cobros', 'arriendo-facil' ); ?>"></div>

			<div class="af-calendar-mini__legend" id="af-cobranza-legend"></div>

			<div class="af-cobranza-summary" id="af-cobranza-summary" hidden>
				<div class="af-cobranza-summary__row">
					<span class="af-cobranza-summary__item">
						<span class="af-cobranza-summary__k"><?php esc_html_e( 'Facturado', 'arriendo-facil' ); ?></span>
						<span class="af-cobranza-summary__v" data-sum="charged">$0,00</span>
					</span>
					<span class="af-cobranza-summary__item">
						<span class="af-cobranza-summary__k"><?php esc_html_e( 'Pagado', 'arriendo-facil' ); ?></span>
						<span class="af-cobranza-summary__v is-ok" data-sum="paid">$0,00</span>
					</span>
					<span class="af-cobranza-summary__item">
						<span class="af-cobranza-summary__k"><?php esc_html_e( 'Pendiente', 'arriendo-facil' ); ?></span>
						<span class="af-cobranza-summary__v is-danger" data-sum="pending">$0,00</span>
					</span>
				</div>
				<div class="af-cobranza-summary__bar" role="img" aria-label="<?php esc_attr_e( 'Progreso de cobranza del mes', 'arriendo-facil' ); ?>">
					<span class="af-cobranza-summary__fill" data-sum="fill" style="width:0%"></span>
				</div>
			</div>

			<div class="af-cobranza-dayfilter" id="af-cobranza-dayfilter" hidden></div>

			<h4 class="af-cobranza-charges__title"><?php esc_html_e( 'Cargos del mes', 'arriendo-facil' ); ?></h4>
			<ul class="af-cobranza-charges" id="af-cobranza-charges"></ul>

			<div class="af-cobranza-pay" id="af-cobranza-pay" hidden>
				<h4 class="af-cobranza-pay__title"><?php esc_html_e( 'Anotar pago', 'arriendo-facil' ); ?></h4>
				<p class="af-modal__status" id="af-cobranza-pay-status" style="display:none;"></p>
				<input type="hidden" id="af-cobranza-pay-charge-id" />
				<div class="af-modal__field">
					<label for="af-cobranza-pay-amount"><?php esc_html_e( 'Monto recibido (USD)', 'arriendo-facil' ); ?></label>
					<input type="number" id="af-cobranza-pay-amount" step="0.01" min="0.01" />
					<p class="af-modal__hint" id="af-cobranza-pay-outstanding"></p>
				</div>
				<div class="af-modal__field">
					<label for="af-cobranza-pay-date"><?php esc_html_e( 'Fecha de pago', 'arriendo-facil' ); ?></label>
					<input type="date" id="af-cobranza-pay-date" value="<?php echo esc_attr( $cob_today ); ?>" />
				</div>
				<div class="af-modal__field">
					<label for="af-cobranza-pay-method"><?php esc_html_e( 'Método', 'arriendo-facil' ); ?></label>
					<select id="af-cobranza-pay-method">
						<?php foreach ( Arriendo_Facil_Billing_Ledger::payment_methods() as $method_key => $method_label ) : ?>
							<option value="<?php echo esc_attr( $method_key ); ?>"><?php echo esc_html( $method_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="af-modal__field">
					<label for="af-cobranza-pay-reference"><?php esc_html_e( 'Referencia / comprobante', 'arriendo-facil' ); ?></label>
					<input type="text" id="af-cobranza-pay-reference" placeholder="<?php esc_attr_e( 'Ej: transferencia #01234', 'arriendo-facil' ); ?>" />
				</div>
				<div class="af-cobranza-pay__actions">
					<button type="button" class="button" id="af-cobranza-pay-cancel"><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button type="button" class="button button-primary" id="af-cobranza-pay-confirm"><?php esc_html_e( 'Registrar pago', 'arriendo-facil' ); ?></button>
				</div>
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cerrar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
	<div class="af-cobranza-tip" id="af-cobranza-tip" role="tooltip" hidden></div>
</div>

<script>
/* Cobranza por inmueble: modal con calendario de cobros del mes y anotación de
   pagos (af_record_payment). Va en línea en esta vista a propósito: la página
   se despliega como un único archivo, así el clic funciona sin depender de un
   asset encolado aparte.

   El calendario es interactivo: clic en un día filtra la lista, clic en un cargo
   resalta su día, el hover muestra el detalle, la leyenda filtra por estado y
   al registrar un pago todo se refresca en vivo (af_cobranza_snapshot) sin
   recargar la página. */
(function () {
	'use strict';

	var DATA    = <?php echo wp_json_encode( $cob_json_props ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Datos JSON para el JS del calendario. ?>;
	var TODAY   = <?php echo wp_json_encode( $cob_today ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fecha actual del sitio. ?>;
	var PERIOD  = <?php echo wp_json_encode( $current_period ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Periodo de cobranza actual. ?>;
	var STATUSES = <?php echo wp_json_encode( $cob_status_meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Metadatos de estado para refrescar tarjetas. ?>;
	var CONFIG  = {
		ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL del AJAX de WordPress. ?>,
		nonce: <?php echo wp_json_encode( wp_create_nonce( 'af_ledger_nonce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Nonce del ledger. ?>
	};

	var LABELS = {
		paid: <?php echo wp_json_encode( __( 'Pagado', 'arriendo-facil' ) ); ?>,
		pending: <?php echo wp_json_encode( __( 'Pendiente', 'arriendo-facil' ) ); ?>,
		overdue: <?php echo wp_json_encode( __( 'Vencido', 'arriendo-facil' ) ); ?>,
		payday: <?php echo wp_json_encode( __( 'Día de pago', 'arriendo-facil' ) ); ?>,
		partial: <?php echo wp_json_encode( __( 'Parcial', 'arriendo-facil' ) ); ?>,
		noCharges: <?php echo wp_json_encode( __( 'Sin cargos para este periodo.', 'arriendo-facil' ) ); ?>,
		noChargesDay: <?php echo wp_json_encode( __( 'No hay cargos en este día.', 'arriendo-facil' ) ); ?>,
		allHidden: <?php echo wp_json_encode( __( 'Todos los cargos de este mes están ocultos por el filtro.', 'arriendo-facil' ) ); ?>,
		tenant: <?php echo wp_json_encode( __( 'Arrendatario', 'arriendo-facil' ) ); ?>,
		available: <?php echo wp_json_encode( __( 'Disponible', 'arriendo-facil' ) ); ?>,
		balance: <?php echo wp_json_encode( __( 'Saldo del cargo', 'arriendo-facil' ) ); ?>,
		outstanding: <?php echo wp_json_encode( __( 'puedes superarlo y el excedente quedará como saldo a favor.', 'arriendo-facil' ) ); ?>,
		pay: <?php echo wp_json_encode( __( 'Anotar pago', 'arriendo-facil' ) ); ?>,
		record: <?php echo wp_json_encode( __( 'Registrar pago', 'arriendo-facil' ) ); ?>,
		amount: <?php echo wp_json_encode( __( 'Monto recibido (USD)', 'arriendo-facil' ) ); ?>,
		paidOk: <?php echo wp_json_encode( __( 'Pago registrado correctamente.', 'arriendo-facil' ) ); ?>,
		amountErr: <?php echo wp_json_encode( __( 'Ingresa un monto mayor a cero.', 'arriendo-facil' ) ); ?>,
		genericErr: <?php echo wp_json_encode( __( 'Error al registrar el pago.', 'arriendo-facil' ) ); ?>,
		networkErr: <?php echo wp_json_encode( __( 'Error de red al registrar el pago.', 'arriendo-facil' ) ); ?>,
		refreshErr: <?php echo wp_json_encode( __( 'Pago registrado, pero no se pudo refrescar la vista. Recarga la página.', 'arriendo-facil' ) ); ?>,
		clearDay: <?php echo wp_json_encode( __( 'Quitar filtro', 'arriendo-facil' ) ); ?>,
		dayFilter: <?php echo wp_json_encode( __( 'Mostrando solo el día %s', 'arriendo-facil' ) ); ?>,
		charged: <?php echo wp_json_encode( __( 'Facturado', 'arriendo-facil' ) ); ?>,
		paidShort: <?php echo wp_json_encode( __( 'Pagado', 'arriendo-facil' ) ); ?>,
		pendingShort: <?php echo wp_json_encode( __( 'Pendiente', 'arriendo-facil' ) ); ?>,
		refreshing: <?php echo wp_json_encode( __( 'Actualizando resumen…', 'arriendo-facil' ) ); ?>
	};

	var WEEKDAYS = <?php echo wp_json_encode( array( __( 'lun', 'arriendo-facil' ), __( 'mar', 'arriendo-facil' ), __( 'mié', 'arriendo-facil' ), __( 'jue', 'arriendo-facil' ), __( 'vie', 'arriendo-facil' ), __( 'sáb', 'arriendo-facil' ), __( 'dom', 'arriendo-facil' ) ) ); ?>;

	if (!DATA || !Object.keys(DATA).length) {
		return;
	}

	var LOCALE = <?php echo wp_json_encode( str_replace( '_', '-', determine_locale() ) ); ?>;

	/* ── Helpers ─────────────────────────────────────────────────────────── */

	function pad(n) { return ('0' + n).slice(-2); }

	function periodString(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1); }

	function parsePeriod(p) {
		var parts = String(p).split('-');
		return { y: parseInt(parts[0], 10), m: parseInt(parts[1], 10) };
	}

	function periodDate(p) {
		var x = parsePeriod(p);
		return new Date(x.y, x.m - 1, 1);
	}

	function addMonths(p, delta) {
		var d = periodDate(p);
		d.setMonth(d.getMonth() + delta);
		return periodString(d);
	}

	var MONTH_FMT = new Intl.DateTimeFormat(LOCALE, { month: 'long', year: 'numeric' });
	function monthLabel(p) {
		var s = MONTH_FMT.format(periodDate(p));
		return s.charAt(0).toUpperCase() + s.slice(1);
	}

	function money(n) {
		return Number(n || 0).toLocaleString(LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function dateLabel(ymd) {
		if (!ymd) { return ''; }
		var parts = String(ymd).split('-');
		var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
		return d.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });
	}

	function dayOf(ymd) {
		return String(ymd || '').slice(-2).replace(/^0/, '');
	}

	/* ── Iconos (estilo lucide) ───────────────────────────────────────────── */

	var ICONS = {
		canon: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>',
		alicuota: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1Z"/><path d="M16 8h-6a2 2 0 0 0 0 4h4a2 2 0 0 1 0 4H8"/><path d="M12 17V7"/></svg>',
		agua: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>',
		luz: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A.5.5 0 0 0 13 10h7a.5.5 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A.5.5 0 0 0 11 14z"/></svg>',
		gas: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
		internet: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><path d="M12 20h.01"/></svg>',
		multa: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
		otro: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12h8M12 8v8"/></svg>'
	};

	function chargeIcon(type) { return ICONS[type] || ICONS.otro; }

	/* ── Estado DOM ──────────────────────────────────────────────────────── */

	var modal = document.getElementById('af-cobranza-modal');
	if (!modal) { return; }

	var periodLabel   = document.getElementById('af-cobranza-period');
	var calendarEl    = document.getElementById('af-cobranza-calendar');
	var legendEl      = document.getElementById('af-cobranza-legend');
	var chargesEl     = document.getElementById('af-cobranza-charges');
	var titleEl       = document.getElementById('af-cobranza-modal-title');
	var subtitleEl    = document.getElementById('af-cobranza-modal-subtitle');
	var statusEl      = document.getElementById('af-cobranza-status');
	var payPanel      = document.getElementById('af-cobranza-pay');
	var payChargeId   = document.getElementById('af-cobranza-pay-charge-id');
	var payAmount     = document.getElementById('af-cobranza-pay-amount');
	var payDate       = document.getElementById('af-cobranza-pay-date');
	var payMethod     = document.getElementById('af-cobranza-pay-method');
	var payReference  = document.getElementById('af-cobranza-pay-reference');
	var payOutstand   = document.getElementById('af-cobranza-pay-outstanding');
	var payStatus     = document.getElementById('af-cobranza-pay-status');
	var payCancel     = document.getElementById('af-cobranza-pay-cancel');
	var payConfirm    = document.getElementById('af-cobranza-pay-confirm');
	var summaryEl     = document.getElementById('af-cobranza-summary');
	var dayFilterEl   = document.getElementById('af-cobranza-dayfilter');
	var tipEl         = document.getElementById('af-cobranza-tip');
	var alertsEl      = document.getElementById('af-cobranza-alerts');

	var state = {
		prop: null,
		period: PERIOD,
		selectedDay: '',
		filters: { paid: true, pending: true, overdue: true }
	};

	function openModal() { modal.classList.add('is-open'); }

	function closeModal() {
		modal.classList.remove('is-open');
		payPanel.hidden = true;
		hideTip();
		hideStatus();
	}

	function showStatus(message, type) {
		if (!statusEl) { return; }
		statusEl.style.display = '';
		statusEl.textContent = message;
		statusEl.className = 'af-modal__status is-' + (type || 'success');
	}

	function hideStatus() {
		if (!statusEl) { return; }
		statusEl.textContent = '';
		statusEl.style.display = 'none';
		statusEl.className = 'af-modal__status';
	}

	modal.querySelectorAll('[data-af-modal-close]').forEach(function (btn) {
		btn.addEventListener('click', closeModal);
	});

	/* ── Datos del mes ───────────────────────────────────────────────────── */

	function chargesOf(period) {
		if (!state.prop || !state.prop.charges) { return []; }
		return state.prop.charges[period] || [];
	}

	function byDayOf(period) {
		var map = {};
		chargesOf(period).forEach(function (c) {
			var d = dayOf(c.dueDate);
			if (!d) { return; }
			if (!map[d]) { map[d] = []; }
			map[d].push(c);
		});
		return map;
	}

	function isOverdue(c) {
		return 'overdue' === c.status || (c.dueDate && TODAY && c.dueDate < TODAY);
	}

	function bucketOf(c) {
		if ('paid' === c.status) { return 'paid'; }
		if (isOverdue(c)) { return 'overdue'; }
		return 'pending';
	}

	function visible(c) { return state.filters[ bucketOf(c) ]; }

	function balanceOf(c) { return (Number(c.amount) || 0) - (Number(c.paid) || 0); }

	/* ── Calendario ───────────────────────────────────────────────────────── */

	function renderCalendar() {
		var p = state.period;
		var x = parsePeriod(p);
		var first = new Date(x.y, x.m - 1, 1);
		var daysInMonth = new Date(x.y, x.m, 0).getDate();
		var startWeekday = (first.getDay() + 6) % 7; // lunes = 0

		var byDay = byDayOf(p);
		var todayKey = String(TODAY || '').slice(0, 10);
		var selected = state.selectedDay;

		var cells = '';
		for (var i = 0; i < startWeekday; i++) {
			cells += '<div class="af-calendar-mini__day is-outside"></div>';
		}
		for (var day = 1; day <= daysInMonth; day++) {
			var dayStr = x.y + '-' + pad(x.m) + '-' + pad(day);
			var cls = 'af-calendar-mini__day';
			if (dayStr === todayKey) { cls += ' is-today'; }
			if (dayStr === selected) { cls += ' is-selected'; }
			if (state.prop.has_lease && state.prop.payment_due_day === day) { cls += ' is-payday'; }

			var dayCharges = byDay[String(day)] || [];
			var shown = dayCharges.filter(visible);
			if (dayCharges.length && !shown.length) { cls += ' is-dimmed'; }
			if (shown.length) { cls += ' has-charges'; }

			var dots = '';
			if (state.prop.has_lease && state.prop.payment_due_day === day) {
				dots += '<i class="af-calendar-mini__day-dot is-payday"></i>';
			}
			shown.forEach(function (c) {
				dots += '<i class="af-calendar-mini__day-dot is-' + bucketOf(c) + '"></i>';
			});

			var tip = dayCharges.length
				? dayCharges.map(function (c) { return c.label + ' · $' + money(c.amount); }).join(' / ')
				: (state.prop.has_lease && state.prop.payment_due_day === day ? LABELS.payday : '');

			cells += '<div class="' + cls + '" data-day="' + dayStr + '" data-tip="' + escapeAttr(tip) + '">' +
				'<span class="af-calendar-mini__day-num">' + day + '</span>' +
				'<span class="af-calendar-mini__dots">' + dots + '</span>' +
				'</div>';
		}
		var trailing = (7 - ((startWeekday + daysInMonth) % 7)) % 7;
		for (var t = 0; t < trailing; t++) {
			cells += '<div class="af-calendar-mini__day is-outside"></div>';
		}

		periodLabel.textContent = monthLabel(p);
		calendarEl.innerHTML = WEEKDAYS.map(function (w) {
			return '<span class="af-calendar-mini__wd">' + w + '</span>';
		}).join('') + cells;
	}

	function escapeAttr(s) {
		return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	/* Leyenda: clic para filtrar por estado. */

	function renderLegend() {
		var items = [
			{ key: 'paid', label: LABELS.paid },
			{ key: 'pending', label: LABELS.pending },
			{ key: 'overdue', label: LABELS.overdue }
		];
		if (state.prop.has_lease) { items.push({ key: 'payday', label: LABELS.payday }); }

		legendEl.innerHTML = items.map(function (it) {
			if ('payday' === it.key) {
				return '<span class="is-static"><i class="is-payday"></i> ' + it.label + '</span>';
			}
			return '<button type="button" class="af-calendar-mini__filter' + (state.filters[it.key] ? '' : ' is-off') +
				'" data-filter="' + it.key + '" aria-pressed="' + (state.filters[it.key] ? 'true' : 'false') + '">' +
				'<i class="is-' + it.key + '"></i> ' + it.label + '</button>';
		}).join('');
	}

	/* ── Resumen del mes ──────────────────────────────────────────────────── */

	function renderSummary() {
		if (!summaryEl) { return; }
		var list = chargesOf(state.period);
		summaryEl.hidden = !list.length;
		if (!list.length) { return; }

		var charged = 0, paid = 0;
		list.forEach(function (c) {
			charged += Number(c.amount) || 0;
			paid += Math.min(Number(c.paid) || 0, Number(c.amount) || 0);
		});
		var pending = Math.max(charged - paid, 0);
		var pct = charged > 0 ? Math.round((paid / charged) * 100) : 0;

		var set = function (key, value) {
			var el = summaryEl.querySelector('[data-sum="' + key + '"]');
			if (el) { el.textContent = '$' + money(value); }
		};
		set('charged', charged);
		set('paid', paid);
		set('pending', pending);
		var fill = summaryEl.querySelector('[data-sum="fill"]');
		if (fill) { fill.style.width = pct + '%'; }
	}

	/* ── Lista de cargos ──────────────────────────────────────────────────── */

	function chargeStatusLabel(c) {
		if ('paid' === c.status) { return LABELS.paid; }
		if (isOverdue(c)) { return LABELS.overdue; }
		if ('partial' === c.status && balanceOf(c) > 0.01) { return LABELS.partial; }
		return LABELS.pending;
	}

	function renderDayFilter() {
		if (!dayFilterEl) { return; }
		if (!state.selectedDay) {
			dayFilterEl.hidden = true;
			dayFilterEl.innerHTML = '';
			return;
		}
		var d = dayOf(state.selectedDay);
		dayFilterEl.hidden = false;
		dayFilterEl.innerHTML = '<span class="af-cobranza-dayfilter__chip">' +
			escapeAttr(LABELS.dayFilter.replace('%s', d)) +
			'</span><button type="button" class="button af-btn" data-clear-day>' + LABELS.clearDay + '</button>';
	}

	function renderCharges() {
		var all = chargesOf(state.period);
		chargesEl.innerHTML = '';

		if (!all.length) {
			chargesEl.appendChild(emptyRow(LABELS.noCharges));
			payPanel.hidden = true;
			renderDayFilter();
			return;
		}

		var list = all.filter(function (c) {
			if (state.selectedDay && dayOf(c.dueDate) !== dayOf(state.selectedDay)) { return false; }
			return visible(c);
		});

		if (!list.length) {
			chargesEl.appendChild(emptyRow(state.selectedDay ? LABELS.noChargesDay : LABELS.allHidden));
			payPanel.hidden = true;
			renderDayFilter();
			return;
		}

		list.forEach(function (c) {
			var paid = 'paid' === c.status;
			var bal = balanceOf(c);
			var li = document.createElement('li');
			li.className = 'af-cobranza-charge ' + (paid ? 'is-paid' : '') + ' is-' + bucketOf(c);
			li.setAttribute('data-charge-id', c.id);
			li.setAttribute('data-day', dayOf(c.dueDate));

			li.innerHTML =
				'<span class="af-cobranza-charge__icon">' + chargeIcon(c.type) + '</span>' +
				'<div class="af-cobranza-charge__body">' +
				'<span class="af-cobranza-charge__label"></span>' +
				'<span class="af-cobranza-charge__meta"></span>' +
				'</div>' +
				'<div class="af-cobranza-charge__amount">' +
				'<span class="af-cobranza-charge__value">$' + money(c.amount) + '</span>' +
				'<span class="af-cobranza-charge__balance"></span>' +
				'</div>' +
				'<div class="af-cobranza-charge__actions">' +
				(paid || bal <= 0.01 ? '' : '<button type="button" class="button af-btn af-cobranza-pay-open" data-charge="' + c.id + '" data-outstanding="' + bal.toFixed(2) + '">' + LABELS.pay + '</button>') +
				'</div>';

			li.querySelector('.af-cobranza-charge__label').textContent = c.label + (c.desc ? ' · ' + c.desc : '');
			li.querySelector('.af-cobranza-charge__meta').textContent =
				(c.dueDate ? dateLabel(c.dueDate) + ' · ' : '') + chargeStatusLabel(c);
			li.querySelector('.af-cobranza-charge__balance').textContent =
				paid ? LABELS.paid : (LABELS.balance + ' $' + money(bal));

			chargesEl.appendChild(li);
		});

		payPanel.hidden = true;
		renderDayFilter();
	}

	function emptyRow(text) {
		var li = document.createElement('li');
		li.className = 'af-cobranza-empty';
		li.textContent = text;
		return li;
	}

	function render() {
		hideTip();
		renderCalendar();
		renderLegend();
		renderSummary();
		renderCharges();
	}

	/* ── Tooltip ──────────────────────────────────────────────────────────── */

	function showTip(target) {
		if (!tipEl) { return; }
		var text = target.getAttribute('data-tip');
		if (!text) { hideTip(); return; }
		tipEl.textContent = text;
		tipEl.hidden = false;
		var box = target.getBoundingClientRect();
		var host = modal.getBoundingClientRect();
		var top = box.top - host.top - tipEl.offsetHeight - 8;
		tipEl.style.top = Math.max(top, 4) + 'px';
		tipEl.style.left = Math.max((box.left - host.left) - (tipEl.offsetWidth / 2) + (box.width / 2), 4) + 'px';
	}

	function hideTip() {
		if (tipEl) { tipEl.hidden = true; }
	}

	/* ── Abrir modal desde tarjeta ───────────────────────────────────────── */

	function openForProp(prop) {
		state.prop = prop;
		state.period = PERIOD;
		state.selectedDay = '';

		// Si el periodo actual no tiene cargos, abre el primero que exista.
		if (!prop.charges || !prop.charges[state.period]) {
			var keys = prop.charges ? Object.keys(prop.charges).sort() : [];
			if (keys.length) { state.period = keys[0]; }
		}

		titleEl.textContent = prop.title;
		var sub = [];
		if (prop.unit_code) { sub.push(prop.unit_code + (prop.building_name ? ' · ' + prop.building_name : '')); }
		if (prop.guest) { sub.push(LABELS.tenant + ': ' + prop.guest); }
		else if (!prop.has_lease) { sub.push(LABELS.available); }
		subtitleEl.textContent = sub.join(' — ');

		payPanel.hidden = true;
		hideStatus();
		render();
		openModal();
	}

	document.addEventListener('click', function (e) {
		var card = e.target.closest ? e.target.closest('.af-cobranza-card[data-prop]') : null;
		if (!card) { return; }
		var prop = DATA[card.getAttribute('data-prop')];
		if (prop) { openForProp(prop); }
	});

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Enter' && e.key !== ' ') { return; }
		var card = e.target.closest ? e.target.closest('.af-cobranza-card[data-prop]') : null;
		if (!card) { return; }
		e.preventDefault();
		card.click();
	});

	/* ── Interacción del calendario ──────────────────────────────────────── */

	calendarEl.addEventListener('click', function (e) {
		var cell = e.target.closest ? e.target.closest('.af-calendar-mini__day[data-day]') : null;
		if (!cell) { return; }
		var day = cell.getAttribute('data-day');
		state.selectedDay = ( state.selectedDay === day ) ? '' : day;
		render();
	});

	calendarEl.addEventListener('mouseover', function (e) {
		var cell = e.target.closest ? e.target.closest('.af-calendar-mini__day[data-day]') : null;
		if (cell) { showTip(cell); } else { hideTip(); }
	});
	calendarEl.addEventListener('mouseout', hideTip);

	// El diálogo hace scroll propio: el tooltip se posiciona en coordenadas
	// fijas, así que se oculta en vez de quedar desalineado.
	var dialog = modal.querySelector('.af-modal__dialog');
	if (dialog) { dialog.addEventListener('scroll', hideTip, { passive: true }); }

	legendEl.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-filter]') : null;
		if (!btn) { return; }
		var key = btn.getAttribute('data-filter');
		state.filters[key] = !state.filters[key];
		render();
	});

	/* Clic en un cargo → resalta su día en el calendario. */

	chargesEl.addEventListener('click', function (e) {
		var payBtn = e.target.closest ? e.target.closest('.af-cobranza-pay-open') : null;
		if (payBtn) {
			openPayPanel(payBtn);
			return;
		}
		var row = e.target.closest ? e.target.closest('.af-cobranza-charge[data-day]') : null;
		if (!row) { return; }
		var day = row.getAttribute('data-day');
		if (!day) { return; }
		state.selectedDay = ( state.selectedDay === day ) ? '' : day;
		render();
	});

	dayFilterEl.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-clear-day]') : null;
		if (!btn) { return; }
		state.selectedDay = '';
		render();
	});

	/* ── Navegación ──────────────────────────────────────────────────────── */

	var todayPeriod = TODAY ? String(TODAY).slice(0, 7) : PERIOD;

	document.querySelectorAll('[data-cal]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var dir = btn.getAttribute('data-cal');
			if ('prev' === dir) { state.period = addMonths(state.period, -1); }
			if ('next' === dir) { state.period = addMonths(state.period, 1); }
			if ('today' === dir) { state.period = todayPeriod; }
			state.selectedDay = '';
			render();
		});
	});

	/* ← / → cambian de mes, Escape cierra. */

	document.addEventListener('keydown', function (e) {
		if (!modal.classList.contains('is-open')) { return; }
		var tag = (e.target && e.target.tagName || '').toLowerCase();
		if ('input' === tag || 'select' === tag || 'textarea' === tag) { return; }

		if ('Escape' === e.key) { closeModal(); return; }
		if ('ArrowLeft' === e.key) { state.period = addMonths(state.period, -1); state.selectedDay = ''; render(); }
		if ('ArrowRight' === e.key) { state.period = addMonths(state.period, 1); state.selectedDay = ''; render(); }
	});

	/* ── Anotar pago ─────────────────────────────────────────────────────── */

	function setPayStatus(message, type) {
		payStatus.style.display = '';
		payStatus.textContent = message;
		payStatus.className = 'af-modal__status is-' + (type || 'success');
	}

	function hidePayStatus() {
		payStatus.textContent = '';
		payStatus.style.display = 'none';
		payStatus.className = 'af-modal__status';
	}

	function openPayPanel(btn) {
		payChargeId.value = btn.getAttribute('data-charge');
		var outstanding = parseFloat(btn.getAttribute('data-outstanding')) || 0;
		payAmount.value = outstanding.toFixed(2);
		payDate.value = TODAY || '';
		payOutstand.textContent = LABELS.balance + ': $' + money(outstanding) + ' — ' + LABELS.outstanding;
		payReference.value = '';
		hidePayStatus();
		payPanel.hidden = false;
		payPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	payCancel.addEventListener('click', function () {
		payPanel.hidden = true;
		hidePayStatus();
	});

	/* Refresco en vivo: vuelve a pedir el snapshot al servidor y actualiza
	   modal, tarjetas y franja de alertas sin recargar la página. */

	function refreshFromServer() {
		var body = new URLSearchParams();
		body.append('action', 'af_cobranza_snapshot');
		body.append('nonce', CONFIG.nonce);
		body.append('period', PERIOD);

		return fetch(CONFIG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); }).then(function (json) {
			if (!json || !json.success || !json.data) {
				throw new Error('snapshot');
			}
			applySnapshot(json.data);
		});
	}

	function applySnapshot(snap) {
		if (snap.props) { DATA = snap.props; }
		if (snap.today) { TODAY = snap.today; }
		updateCards(snap.props || {});
		updateAlerts(snap.display || null);
		if (state.prop) {
			var fresh = DATA[String(state.prop.id)];
			if (fresh) {
				state.prop = fresh;
				if (!fresh.charges || !fresh.charges[state.period]) {
					var keys = fresh.charges ? Object.keys(fresh.charges).sort() : [];
					if (keys.length) { state.period = keys[0]; }
				}
			}
			render();
		}
	}

	function updateCards(props) {
		Object.keys(props).forEach(function (id) {
			var card = document.querySelector('.af-cobranza-card[data-prop="' + id + '"]');
			if (!card) { return; }
			var p = props[id];
			var meta = STATUSES[p.status] || {};

			card.classList.remove('is-danger', 'is-warning', 'is-info', 'is-ok', 'is-muted', 'is-available');
			if (meta.card) { card.classList.add(meta.card); }

			var pill = card.querySelector('[data-role="pill"]');
			if (pill) {
				pill.className = 'af-pill af-pill--' + (meta.pill || 'neutral');
				pill.textContent = meta.label || '';
			}

			var amount = card.querySelector('[data-role="amount"]');
			if (amount) {
				var small = amount.querySelector('[data-role="amount-label"]');
				amount.className = 'af-cobranza-card__amount ' + (p.due_class || '');
				amount.textContent = '$' + money(p.amount_total) + ' ';
				if (small) {
					small.textContent = p.amount_label;
					amount.appendChild(small);
				}
			}

			var due = card.querySelector('[data-role="due"]');
			if (due) {
				due.className = 'af-cobranza-card__due ' + (p.due_class || '');
				due.textContent = p.due_hint || '';
			}
		});
	}

	function updateAlerts(display) {
		if (!alertsEl || !display) { return; }
		['vencido', 'proximo'].forEach(function (key) {
			var box = alertsEl.querySelector('[data-alert="' + key + '"]');
			var d = display[key];
			if (!box) { return; }
			if (!d) { box.hidden = true; return; }
			box.hidden = false;
			var t = box.querySelector('[data-alert-title]');
			var x = box.querySelector('[data-alert-text]');
			if (t) { t.textContent = d.title; }
			if (x) { x.textContent = d.text; }
		});
		var mesBox = alertsEl.querySelector('[data-alert="mes"]');
		if (mesBox && display.mes) {
			var mt = mesBox.querySelector('[data-alert-title]');
			var mx = mesBox.querySelector('[data-alert-text]');
			if (mt) { mt.textContent = display.mes.title; }
			if (mx) { mx.textContent = display.mes.text; }
		}
	}

	payConfirm.addEventListener('click', function () {
		var amount = parseFloat(payAmount.value);
		if (!amount || amount <= 0) {
			setPayStatus(LABELS.amountErr, 'error');
			return;
		}

		payConfirm.disabled = true;
		hidePayStatus();
		showStatus(LABELS.refreshing, 'success');

		var body = new URLSearchParams();
		body.append('action', 'af_record_payment');
		body.append('nonce', CONFIG.nonce);
		body.append('charge_id', payChargeId.value);
		body.append('amount', amount);
		body.append('payment_date', payDate.value);
		body.append('method', payMethod.value);
		body.append('reference', payReference.value);

		fetch(CONFIG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); }).then(function (json) {
			payConfirm.disabled = false;
			if (!json || !json.success) {
				hideStatus();
				setPayStatus((json && json.data && json.data.message) || LABELS.genericErr, 'error');
				return;
			}
			return refreshFromServer().then(function () {
				hideStatus();
				showStatus(json.data.message || LABELS.paidOk, 'success');
				payPanel.hidden = true;
			});
		}).catch(function () {
			payConfirm.disabled = false;
			hideStatus();
			setPayStatus(LABELS.networkErr, 'error');
		});
	});
}());
</script>
