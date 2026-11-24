<?php
/**
 * Portal del inquilino (https://sitio/inquilino/).
 *
 * Sin usuario ni contraseña: el inquilino entra con un código de 6 dígitos
 * enviado a su correo. Solo ve SU perfil, SU historial de pagos y las novedades
 * de mantenimiento de SUS contratos.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$af_session = Arriendo_Facil_Tenant_Access::current_session();
$af_data    = $af_session ? Arriendo_Facil_Tenant_Access::portal_data( (int) $af_session->guest_id ) : null;
$af_ref     = isset( $_GET['r'] ) ? sanitize_text_field( wp_unslash( $_GET['r'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- referencia firmada.
$af_masked  = '';
if ( ! $af_data && $af_ref ) {
	$af_ref_guest = Arriendo_Facil_Tenant_Access::parse_ref( $af_ref );
	if ( $af_ref_guest ) {
		global $wpdb;
		Arriendo_Facil_Guest::ensure_guest_extra_columns();
		$af_ref_email = (string) $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$wpdb->prefix}af_guests WHERE id = %d AND deleted_at IS NULL", $af_ref_guest ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$af_masked    = Arriendo_Facil_Tenant_Access::mask_email( $af_ref_email );
	}
}

$af_money        = static function ( $amount ) {
	return '$' . number_format_i18n( (float) $amount, 2 );
};
$af_date         = static function ( $date ) {
	return $date ? wp_date( 'j M Y', strtotime( $date . ' 12:00:00' ) ) : '—';
};
$af_lease_labels = array(
	'draft'           => __( 'Borrador', 'arriendo-facil' ),
	'active'          => __( 'Activo', 'arriendo-facil' ),
	'pending_release' => __( 'Por liberar', 'arriendo-facil' ),
	'terminated'      => __( 'Terminado', 'arriendo-facil' ),
);
$af_type_labels  = array(
	'canon'    => __( 'Canon', 'arriendo-facil' ),
	'alicuota' => __( 'Alícuota', 'arriendo-facil' ),
	'agua'     => __( 'Agua', 'arriendo-facil' ),
	'luz'      => __( 'Luz', 'arriendo-facil' ),
	'gas'      => __( 'Gas', 'arriendo-facil' ),
	'internet' => __( 'Internet', 'arriendo-facil' ),
	'telefono' => __( 'Teléfono', 'arriendo-facil' ),
	'multa'    => __( 'Multa', 'arriendo-facil' ),
	'otro'     => __( 'Otro', 'arriendo-facil' ),
);
$af_status_tone  = array(
	'paid'    => 'ok',
	'partial' => 'warn',
	'pending' => 'info',
	'overdue' => 'bad',
);

get_header();
?>
<main class="af-tp" id="af-tp" data-state="<?php echo $af_data ? 'portal' : 'login'; ?>">

	<?php if ( ! $af_data ) : ?>

		<section class="af-tp-card af-tp-login" aria-labelledby="af-tp-login-title">
			<span class="af-tp-badge" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
			</span>
			<h1 class="af-tp-title" id="af-tp-login-title"><?php esc_html_e( 'Portal del inquilino', 'arriendo-facil' ); ?></h1>
			<p class="af-tp-lead"><?php esc_html_e( 'Entra sin contraseña: te enviamos un código de 6 dígitos a tu correo.', 'arriendo-facil' ); ?></p>

			<p class="af-tp-alert" role="alert" data-tp-alert hidden></p>

			<form class="af-tp-form" data-tp-step="email" novalidate>
				<?php if ( $af_masked ) : ?>
					<p class="af-tp-masked"><?php echo esc_html( sprintf( /* translators: %s: masked email */ __( 'Enviaremos el código a %s', 'arriendo-facil' ), $af_masked ) ); ?></p>
				<?php else : ?>
					<label class="af-tp-label" for="af-tp-email"><?php esc_html_e( 'Correo registrado', 'arriendo-facil' ); ?></label>
					<input class="af-tp-input" type="email" id="af-tp-email" name="email" autocomplete="email" inputmode="email" maxlength="190" required />
				<?php endif; ?>
				<button class="af-tp-btn af-tp-btn--primary" type="submit" data-tp-submit><?php esc_html_e( 'Enviar código', 'arriendo-facil' ); ?></button>
			</form>

			<form class="af-tp-form" data-tp-step="code" novalidate hidden>
				<p class="af-tp-masked" data-tp-sent></p>
				<label class="af-tp-label" for="af-tp-code"><?php esc_html_e( 'Código de 6 dígitos', 'arriendo-facil' ); ?></label>
				<input class="af-tp-input af-tp-input--code" type="text" id="af-tp-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="7" placeholder="000000" required />
				<button class="af-tp-btn af-tp-btn--primary" type="submit" data-tp-submit><?php esc_html_e( 'Entrar', 'arriendo-facil' ); ?></button>
				<div class="af-tp-links">
					<button class="af-tp-link" type="button" data-tp-resend disabled><?php esc_html_e( 'Reenviar código', 'arriendo-facil' ); ?></button>
					<button class="af-tp-link" type="button" data-tp-back><?php esc_html_e( 'Usar otro correo', 'arriendo-facil' ); ?></button>
				</div>
			</form>

			<noscript><p class="af-tp-alert"><?php esc_html_e( 'Activa JavaScript para entrar con tu código.', 'arriendo-facil' ); ?></p></noscript>
			<p class="af-tp-fine"><?php esc_html_e( '¿No tienes acceso? Pídele a tu administrador que te envíe el enlace.', 'arriendo-facil' ); ?></p>
		</section>

	<?php else : ?>
		<?php
		$af_guest   = $af_data['guest'];
		$af_name    = trim( $af_guest->first_name . ' ' . $af_guest->last_name );
		$af_id      = (string) $af_guest->id_number;
		$af_idmask  = $af_id ? str_repeat( '•', max( 0, strlen( $af_id ) - 3 ) ) . substr( $af_id, -3 ) : '—';
		$af_open    = array_values(
			array_filter(
				$af_data['leases'],
				static function ( $l ) {
					return in_array( $l->status, array( 'active', 'pending_release' ), true );
				}
			)
		);
		$af_req_ty  = array(
			'reparacion' => __( 'Reparación', 'arriendo-facil' ),
			'emergencia' => __( 'Emergencia', 'arriendo-facil' ),
			'limpieza'   => __( 'Limpieza', 'arriendo-facil' ),
			'otro'       => __( 'Otro', 'arriendo-facil' ),
		);
		$af_req_st  = Arriendo_Facil_Maintenance::statuses();
		$af_req_pr  = Arriendo_Facil_Maintenance::priorities();
		$af_charge_st = Arriendo_Facil_Tenant_Access::charge_status_labels();
		?>
		<header class="af-tp-head">
			<div>
				<p class="af-tp-eyebrow"><?php esc_html_e( 'Portal del inquilino', 'arriendo-facil' ); ?></p>
				<h1 class="af-tp-title"><?php echo esc_html( sprintf( /* translators: %s: first name */ __( 'Hola, %s', 'arriendo-facil' ), $af_guest->first_name ) ); ?></h1>
			</div>
			<button class="af-tp-btn af-tp-btn--ghost" type="button" data-tp-logout><?php esc_html_e( 'Salir', 'arriendo-facil' ); ?></button>
		</header>

		<section class="af-tp-summary" aria-label="<?php esc_attr_e( 'Resumen', 'arriendo-facil' ); ?>">
			<div class="af-tp-stat <?php echo $af_data['balance'] > 0 ? 'is-bad' : 'is-ok'; ?>">
				<span><?php esc_html_e( 'Saldo pendiente', 'arriendo-facil' ); ?></span>
				<strong><?php echo esc_html( $af_money( $af_data['balance'] ) ); ?></strong>
			</div>
			<div class="af-tp-stat">
				<span><?php esc_html_e( 'Contratos vigentes', 'arriendo-facil' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( count( $af_open ) ) ); ?></strong>
			</div>
		</section>

		<nav class="af-tp-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Secciones', 'arriendo-facil' ); ?>">
			<button type="button" role="tab" class="is-active" aria-selected="true" data-tp-tab="profile"><?php esc_html_e( 'Mi perfil', 'arriendo-facil' ); ?></button>
			<button type="button" role="tab" aria-selected="false" data-tp-tab="payments"><?php esc_html_e( 'Pagos', 'arriendo-facil' ); ?></button>
			<button type="button" role="tab" aria-selected="false" data-tp-tab="maintenance"><?php esc_html_e( 'Mantenimiento', 'arriendo-facil' ); ?></button>
		</nav>

		<section class="af-tp-card" data-tp-panel="profile" role="tabpanel">
			<h2 class="af-tp-h2"><?php esc_html_e( 'Mis datos', 'arriendo-facil' ); ?></h2>
			<dl class="af-tp-facts">
				<div><dt><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $af_name ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Correo', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $af_guest->email ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Teléfono', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $af_guest->phone ? $af_guest->phone : '—' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Identificación', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $af_idmask ); ?></dd></div>
			</dl>

			<h2 class="af-tp-h2"><?php esc_html_e( 'Mis contratos', 'arriendo-facil' ); ?></h2>
			<?php if ( empty( $af_data['leases'] ) ) : ?>
				<p class="af-tp-empty"><?php esc_html_e( 'Aún no tienes contratos registrados.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<ul class="af-tp-list">
					<?php foreach ( $af_data['leases'] as $af_lease ) : ?>
						<li class="af-tp-item">
							<div class="af-tp-item__main">
								<strong><?php echo esc_html( $af_lease->property ? $af_lease->property : __( 'Inmueble', 'arriendo-facil' ) ); ?></strong>
								<small><?php echo esc_html( $af_date( $af_lease->start_date ) . ' → ' . $af_date( $af_lease->end_date ) ); ?></small>
							</div>
							<div class="af-tp-item__side">
								<strong><?php echo esc_html( $af_money( $af_lease->monthly_rent ) ); ?></strong>
								<span class="af-tp-pill is-<?php echo esc_attr( 'active' === $af_lease->status ? 'ok' : 'info' ); ?>"><?php echo esc_html( isset( $af_lease_labels[ $af_lease->status ] ) ? $af_lease_labels[ $af_lease->status ] : $af_lease->status ); ?></span>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<section class="af-tp-card" data-tp-panel="payments" role="tabpanel" hidden>
			<h2 class="af-tp-h2"><?php esc_html_e( 'Cobros', 'arriendo-facil' ); ?></h2>
			<?php if ( empty( $af_data['charges'] ) ) : ?>
				<p class="af-tp-empty"><?php esc_html_e( 'Todavía no hay cobros registrados.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<ul class="af-tp-list">
					<?php foreach ( $af_data['charges'] as $af_charge ) : ?>
						<?php $af_tone = isset( $af_status_tone[ $af_charge->status ] ) ? $af_status_tone[ $af_charge->status ] : 'info'; ?>
						<li class="af-tp-item">
							<div class="af-tp-item__main">
								<strong><?php echo esc_html( ( isset( $af_type_labels[ $af_charge->charge_type ] ) ? $af_type_labels[ $af_charge->charge_type ] : $af_charge->charge_type ) . ' · ' . $af_charge->period ); ?></strong>
								<small><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Vence %s', 'arriendo-facil' ), $af_date( $af_charge->due_date ) ) ); ?></small>
							</div>
							<div class="af-tp-item__side">
								<strong><?php echo esc_html( $af_money( $af_charge->amount ) ); ?></strong>
								<span class="af-tp-pill is-<?php echo esc_attr( $af_tone ); ?>"><?php echo esc_html( isset( $af_charge_st[ $af_charge->status ] ) ? $af_charge_st[ $af_charge->status ] : $af_charge->status ); ?></span>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2 class="af-tp-h2"><?php esc_html_e( 'Pagos realizados', 'arriendo-facil' ); ?></h2>
			<?php if ( empty( $af_data['payments'] ) ) : ?>
				<p class="af-tp-empty"><?php esc_html_e( 'Aún no hay pagos registrados.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<ul class="af-tp-list">
					<?php foreach ( $af_data['payments'] as $af_pay ) : ?>
						<li class="af-tp-item">
							<div class="af-tp-item__main">
								<strong><?php echo esc_html( $af_date( $af_pay->payment_date ) ); ?></strong>
								<small><?php echo esc_html( ucfirst( (string) $af_pay->method ) . ( $af_pay->reference ? ' · ' . $af_pay->reference : '' ) ); ?></small>
							</div>
							<div class="af-tp-item__side"><strong class="af-tp-money-ok"><?php echo esc_html( $af_money( $af_pay->amount ) ); ?></strong></div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<section class="af-tp-card" data-tp-panel="maintenance" role="tabpanel" hidden>
			<h2 class="af-tp-h2"><?php esc_html_e( 'Reportar una novedad', 'arriendo-facil' ); ?></h2>
			<?php if ( empty( $af_open ) ) : ?>
				<p class="af-tp-empty"><?php esc_html_e( 'Podrás reportar novedades cuando tengas un contrato vigente.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<p class="af-tp-alert" role="alert" data-tp-report-alert hidden></p>
				<form class="af-tp-form" data-tp-report novalidate>
					<?php if ( count( $af_open ) > 1 ) : ?>
						<label class="af-tp-label" for="af-tp-lease"><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></label>
						<select class="af-tp-input" id="af-tp-lease" name="lease_id" required>
							<?php foreach ( $af_open as $af_lease ) : ?>
								<option value="<?php echo esc_attr( $af_lease->id ); ?>"><?php echo esc_html( $af_lease->property ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<input type="hidden" name="lease_id" value="<?php echo esc_attr( $af_open[0]->id ); ?>" />
					<?php endif; ?>

					<div class="af-tp-row">
						<div>
							<label class="af-tp-label" for="af-tp-type"><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></label>
							<select class="af-tp-input" id="af-tp-type" name="request_type" required>
								<?php foreach ( $af_req_ty as $af_k => $af_v ) : ?>
									<option value="<?php echo esc_attr( $af_k ); ?>"><?php echo esc_html( $af_v ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div>
							<label class="af-tp-label" for="af-tp-priority"><?php esc_html_e( 'Urgencia', 'arriendo-facil' ); ?></label>
							<select class="af-tp-input" id="af-tp-priority" name="priority">
								<?php foreach ( $af_req_pr as $af_k => $af_v ) : ?>
									<option value="<?php echo esc_attr( $af_k ); ?>" <?php selected( 'media', $af_k ); ?>><?php echo esc_html( $af_v ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<label class="af-tp-label" for="af-tp-where"><?php esc_html_e( '¿Dónde ocurre? (opcional)', 'arriendo-facil' ); ?></label>
					<input class="af-tp-input" type="text" id="af-tp-where" name="asset_location" maxlength="120" placeholder="<?php esc_attr_e( 'Ej. baño principal', 'arriendo-facil' ); ?>" />

					<label class="af-tp-label" for="af-tp-details"><?php esc_html_e( 'Cuéntanos qué pasó', 'arriendo-facil' ); ?></label>
					<textarea class="af-tp-input" id="af-tp-details" name="damage_details" rows="4" minlength="10" maxlength="1000" required></textarea>

					<button class="af-tp-btn af-tp-btn--primary" type="submit" data-tp-submit><?php esc_html_e( 'Enviar novedad', 'arriendo-facil' ); ?></button>
				</form>
			<?php endif; ?>

			<h2 class="af-tp-h2"><?php esc_html_e( 'Mis novedades', 'arriendo-facil' ); ?></h2>
			<?php if ( empty( $af_data['requests'] ) ) : ?>
				<p class="af-tp-empty"><?php esc_html_e( 'No has reportado novedades.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<ul class="af-tp-list">
					<?php foreach ( $af_data['requests'] as $af_req ) : ?>
						<?php $af_rtone = 'completed' === $af_req->status ? 'ok' : ( 'in_progress' === $af_req->status ? 'warn' : ( 'cancelled' === $af_req->status ? 'info' : 'bad' ) ); ?>
						<li class="af-tp-item">
							<div class="af-tp-item__main">
								<strong><?php echo esc_html( isset( $af_req_ty[ $af_req->request_type ] ) ? $af_req_ty[ $af_req->request_type ] : ucfirst( (string) $af_req->request_type ) ); ?> · <?php echo esc_html( $af_date( $af_req->requested_date ) ); ?></strong>
								<small><?php echo esc_html( wp_trim_words( (string) $af_req->damage_details, 18 ) ); ?></small>
							</div>
							<div class="af-tp-item__side">
								<span class="af-tp-pill is-<?php echo esc_attr( $af_rtone ); ?>"><?php echo esc_html( isset( $af_req_st[ $af_req->status ] ) ? $af_req_st[ $af_req->status ] : $af_req->status ); ?></span>
								<?php if ( $af_req->scheduled_date ) : ?>
									<small><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Visita: %s', 'arriendo-facil' ), $af_date( $af_req->scheduled_date ) ) ); ?></small>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<p class="af-tp-fine"><?php esc_html_e( 'Por seguridad la sesión se cierra sola tras un rato sin actividad.', 'arriendo-facil' ); ?></p>

	<?php endif; ?>
</main>
<?php
get_footer();
