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
$billing_is_owner = class_exists( 'Arriendo_Facil_Accommodation' ) && Arriendo_Facil_Accommodation::user_is_owner();
if ( ! current_user_can( 'manage_options' ) && ( ! current_user_can( (string) $billing_capability ) || ! $billing_is_owner ) ) {
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

$is_prod   = ( '2' === (string) $hub_cfg['ambiente'] );
$hub_ready = array(
	'emisor' => '' !== trim( (string) $hub_cfg['ruc'] ) && '' !== trim( (string) $hub_cfg['razon_social'] ),
	'firma'  => $hub_sri_ready,
);
global $wpdb;
$hub_points_sql = ( $hub_scope_owner = ! current_user_can( 'manage_options' ) )
	? $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}af_emission_points WHERE activo = 1 AND owner_id = %d", get_current_user_id() )
	: "SELECT COUNT(*) FROM {$wpdb->prefix}af_emission_points WHERE activo = 1 AND owner_id IS NULL";
$hub_ready['punto'] = (int) $wpdb->get_var( $hub_points_sql ) > 0;
$hub_done           = count( array_filter( $hub_ready ) );
$hub_all_ready      = 3 === $hub_done;
$hub_steps          = array(
	'emisor' => array( __( 'Datos del emisor', 'arriendo-facil' ), 'building-2' ),
	'firma'  => array( __( 'Firma electrónica', 'arriendo-facil' ), 'shield-check' ),
	'punto'  => array( __( 'Punto de emisión', 'arriendo-facil' ), 'receipt' ),
);
$hub_tabs = array(
	'pendientes'   => array( __( 'Por facturar', 'arriendo-facil' ), 'clock' ),
	'comprobantes' => array( __( 'Emitidas', 'arriendo-facil' ), 'receipt' ),
	'sri'          => array( __( 'Configuración SRI', 'arriendo-facil' ), 'settings' ),
);
?>
<div class="wrap af-shell af-bx">

	<header class="af-bx-head">
		<div class="af-bx-head__title">
			<span class="af-bx-eyebrow"><?php esc_html_e( 'SRI Ecuador', 'arriendo-facil' ); ?></span>
			<h1><?php esc_html_e( 'Facturación', 'arriendo-facil' ); ?></h1>
			<p><?php esc_html_e( 'Consulta y administra los comprobantes electrónicos.', 'arriendo-facil' ); ?></p>
		</div>
		<div class="af-bx-head__side">
			<span class="af-bx-env af-bx-env--<?php echo $is_prod ? 'prod' : 'test'; ?>">
				<span class="af-bx-env__dot" aria-hidden="true"></span>
				<span>
					<strong><?php echo esc_html( $is_prod ? __( 'Producción', 'arriendo-facil' ) : __( 'Pruebas', 'arriendo-facil' ) ); ?></strong>
					<small><?php echo esc_html( $is_prod ? __( 'Validez tributaria real', 'arriendo-facil' ) : __( 'Sin validez tributaria', 'arriendo-facil' ) ); ?></small>
				</span>
			</span>
			<a class="button af-btn af-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=af-billing&tab=pendientes' ) ); ?>">
				<span class="af-btn__icon" aria-hidden="true"><?php echo af_lucide( 'receipt', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<?php esc_html_e( 'Emitir factura', 'arriendo-facil' ); ?>
			</a>
		</div>
	</header>

	<?php if ( ! $hub_all_ready ) : ?>
		<section class="af-bx-setup" aria-label="<?php esc_attr_e( 'Preparación para facturar', 'arriendo-facil' ); ?>">
			<div class="af-bx-setup__lead">
				<strong><?php esc_html_e( 'Termina tu configuración para emitir', 'arriendo-facil' ); ?></strong>
				<span><?php echo esc_html( sprintf( /* translators: 1: done, 2: total */ __( '%1$d de %2$d pasos completos', 'arriendo-facil' ), $hub_done, 3 ) ); ?></span>
				<div class="af-bx-setup__bar" role="progressbar" aria-valuemin="0" aria-valuemax="3" aria-valuenow="<?php echo (int) $hub_done; ?>"><i style="width:<?php echo (int) round( $hub_done / 3 * 100 ); ?>%"></i></div>
			</div>
			<ol class="af-bx-setup__steps">
				<?php foreach ( $hub_steps as $key => $step ) : ?>
					<li class="<?php echo $hub_ready[ $key ] ? 'is-done' : 'is-todo'; ?>">
						<span class="af-bx-setup__ico" aria-hidden="true"><?php echo af_lucide( $hub_ready[ $key ] ? 'circle-check' : $step[1], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
						<?php echo esc_html( $step[0] ); ?>
						<span class="screen-reader-text"><?php echo esc_html( $hub_ready[ $key ] ? __( '(completo)', 'arriendo-facil' ) : __( '(pendiente)', 'arriendo-facil' ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
			<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-billing&tab=sri' ) ); ?>"><?php esc_html_e( 'Completar configuración', 'arriendo-facil' ); ?></a>
		</section>
	<?php endif; ?>

	<?php foreach ( $hub_tabs as $key => $tab ) : ?>
		<input type="radio" class="af-bx-radio" id="af-tab-billing-<?php echo esc_attr( $key ); ?>" name="af-billing-tabs" <?php checked( $active_tab, $key ); ?> />
	<?php endforeach; ?>

	<nav class="af-bx-nav" role="tablist" aria-label="<?php esc_attr_e( 'Facturación', 'arriendo-facil' ); ?>">
		<?php foreach ( $hub_tabs as $key => $tab ) : ?>
			<label class="af-bx-nav__tab" for="af-tab-billing-<?php echo esc_attr( $key ); ?>" role="tab" aria-selected="<?php echo $active_tab === $key ? 'true' : 'false'; ?>">
				<span aria-hidden="true"><?php echo af_lucide( $tab[1], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<?php echo esc_html( $tab[0] ); ?>
				<?php if ( 'pendientes' === $key ) : ?>
					<span class="af-bx-badge" id="af-pending-tab-count" <?php echo 0 === $hub_pending_count ? 'hidden' : ''; ?>><?php echo (int) $hub_pending_count; ?></span>
				<?php elseif ( 'sri' === $key && ! $hub_all_ready ) : ?>
					<span class="af-bx-badge" aria-label="<?php esc_attr_e( 'Configuración incompleta', 'arriendo-facil' ); ?>">!</span>
				<?php endif; ?>
			</label>
		<?php endforeach; ?>
	</nav>

	<div class="af-bx-panels">
		<section id="af-billing-panel-pendientes" class="af-bx-panel af-bx-panel--pendientes" role="tabpanel">
			<?php include __DIR__ . '/../partials/billing-pending.php'; ?>
		</section>

		<section id="af-billing-panel-comprobantes" class="af-bx-panel af-bx-panel--comprobantes" role="tabpanel">
			<?php include __DIR__ . '/../partials/billing-comprobantes.php'; ?>
		</section>

		<section id="af-billing-panel-sri" class="af-bx-panel af-bx-panel--sri" role="tabpanel">
			<?php include __DIR__ . '/../partials/billing-config.php'; ?>
		</section>
	</div>

</div><!-- .wrap -->

<script>
(function () {
	'use strict';
	var radios = document.querySelectorAll( '.af-bx-radio[name="af-billing-tabs"]' );
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
		var current = tabFromId( document.querySelector( '.af-bx-radio[name="af-billing-tabs"]:checked' ).id );
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