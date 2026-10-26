<?php
/**
 * Facturación Electrónica – hub único con tabs internos.
 *
 * Comprobantes (emisión/seguimiento) + Configuración SRI en una sola página
 * con navegación CSS-only por tabs.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$billing_capability = apply_filters( 'af_billing_capability', 'af_view_billing' );
if ( ! current_user_can( (string) $billing_capability ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'arriendo-facil' ) );
}

// ── Tab activo (validado) ────────────────────────────────────────────────────
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pendientes';
if ( ! in_array( $active_tab, array( 'pendientes', 'comprobantes', 'sri' ), true ) ) {
	$active_tab = 'pendientes';
}

$hub_cfg       = Arriendo_Facil_SRI_Config::get();
$hub_sri_ready = ! empty( $hub_cfg['ruc'] ) && ! empty( $hub_cfg['cert_filename'] );

// Cobros pagados sin factura: alimenta el contador de la pestaña principal.
$hub_pending_args = array();
if ( ! current_user_can( 'manage_options' ) && class_exists( 'Arriendo_Facil_Accommodation' ) && Arriendo_Facil_Accommodation::user_is_owner() ) {
	$hub_pending_args['owner_accommodation_ids'] = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
}
$hub_pending_leases = array();
foreach ( ( new Arriendo_Facil_Billing_Manager() )->get_billable_charges( $hub_pending_args ) as $hub_pending_row ) {
	$hub_pending_leases[ (int) $hub_pending_row->lease_id ] = true;
}
$hub_pending_count = count( $hub_pending_leases );

$env_variant = ( '2' === $hub_cfg['ambiente'] ) ? 'danger' : 'info';
$env_label   = ( '2' === $hub_cfg['ambiente'] )
	? __( 'Ambiente: PRODUCCIÓN', 'arriendo-facil' )
	: __( 'Ambiente: PRUEBAS (certificación SRI)', 'arriendo-facil' );
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'SRI Ecuador', 'arriendo-facil' ),
			'title'    => __( 'Facturación electrónica', 'arriendo-facil' ),
			'subtitle' => __( 'Factura tus cobros en 1 clic: el pago se registra, la factura se emite y el inquilino la recibe por correo.', 'arriendo-facil' ),
			'actions'  => array(
				sprintf(
					'<span class="af-pill af-pill--%s">%s</span>',
					esc_attr( $env_variant ),
					esc_html( $env_label )
				),
			),
		)
	);
	?>

	<input type="radio" class="af-tabs__radio" id="af-tab-billing-pendientes" name="af-billing-tabs" <?php checked( $active_tab, 'pendientes' ); ?> />
	<input type="radio" class="af-tabs__radio" id="af-tab-billing-comprobantes" name="af-billing-tabs" <?php checked( $active_tab, 'comprobantes' ); ?> />
	<input type="radio" class="af-tabs__radio" id="af-tab-billing-sri" name="af-billing-tabs" <?php checked( $active_tab, 'sri' ); ?> />

	<nav class="af-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'Facturación electrónica', 'arriendo-facil' ); ?>">
		<label class="af-tabs__tab" for="af-tab-billing-pendientes" role="tab" aria-selected="<?php echo $active_tab === 'pendientes' ? 'true' : 'false'; ?>">
			<span class="af-tabs__icon" aria-hidden="true"><?php echo af_lucide( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<span class="af-tabs__label"><?php esc_html_e( 'Por facturar', 'arriendo-facil' ); ?></span>
			<span class="af-tabs__badge af-tabs__badge--attention" id="af-pending-tab-count" <?php echo 0 === $hub_pending_count ? 'hidden' : ''; ?>><?php echo (int) $hub_pending_count; ?></span>
		</label>
		<label class="af-tabs__tab" for="af-tab-billing-comprobantes" role="tab" aria-selected="<?php echo $active_tab === 'comprobantes' ? 'true' : 'false'; ?>">
			<span class="af-tabs__icon" aria-hidden="true"><?php echo af_lucide( 'receipt', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<span class="af-tabs__label"><?php esc_html_e( 'Emitidas', 'arriendo-facil' ); ?></span>
		</label>
		<label class="af-tabs__tab" for="af-tab-billing-sri" role="tab" aria-selected="<?php echo $active_tab === 'sri' ? 'true' : 'false'; ?>">
			<span class="af-tabs__icon" aria-hidden="true"><?php echo af_lucide( 'settings', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<span class="af-tabs__label"><?php esc_html_e( 'Configuración SRI', 'arriendo-facil' ); ?></span>
			<?php if ( ! $hub_sri_ready ) : ?>
				<span class="af-tabs__badge af-tabs__badge--attention" aria-hidden="true">!</span>
			<?php endif; ?>
		</label>
	</nav>

	<div class="af-tabs__panels">
		<section id="af-billing-panel-pendientes" class="af-tabs__panel af-tabs__panel--pendientes" role="tabpanel">
			<?php include __DIR__ . '/../partials/billing-pending.php'; ?>
		</section>

		<section id="af-billing-panel-comprobantes" class="af-tabs__panel af-tabs__panel--comprobantes" role="tabpanel">
			<?php include __DIR__ . '/../partials/billing-comprobantes.php'; ?>
		</section>

		<section id="af-billing-panel-sri" class="af-tabs__panel af-tabs__panel--sri" role="tabpanel">
			<?php include __DIR__ . '/../partials/billing-config.php'; ?>
		</section>
	</div>

</div><!-- .wrap -->

<script>
(function () {
	'use strict';
	var radios = document.querySelectorAll( '.af-tabs__radio[name="af-billing-tabs"]' );
	if ( ! radios.length ) {
		return;
	}

	function tabFromId( id ) {
		if ( id === 'af-tab-billing-sri' ) { return 'sri'; }
		return id === 'af-tab-billing-comprobantes' ? 'comprobantes' : 'pendientes';
	}

	// Normaliza la URL al primer render: mantiene el deep-link ?tab= legible.
	try {
		var url = new URL( window.location.href );
		var current = tabFromId( document.querySelector( '.af-tabs__radio[name="af-billing-tabs"]:checked' ).id );
		if ( url.searchParams.get( 'tab' ) !== current ) {
			url.searchParams.set( 'tab', current );
			window.history.replaceState( {}, '', url );
		}
	} catch ( e ) {}

	radios.forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			if ( ! radio.checked ) {
				return;
			}
			var url = new URL( window.location.href );
			var tab = tabFromId( radio.id );
			if ( url.searchParams.get( 'tab' ) !== tab ) {
				url.searchParams.set( 'tab', tab );
				window.history.replaceState( {}, '', url );
			}
		} );
	} );
}());
</script>