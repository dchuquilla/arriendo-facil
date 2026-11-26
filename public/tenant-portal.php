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
		$af_guest    = $af_data['guest'];
		$af_name     = trim( $af_guest->first_name . ' ' . $af_guest->last_name );
		$af_id       = (string) $af_guest->id_number;
		$af_idmask   = $af_id ? str_repeat( '•', max( 0, strlen( $af_id ) - 3 ) ) . substr( $af_id, -3 ) : '—';
		$af_open     = array_values(
			array_filter(
				$af_data['leases'],
				static function ( $l ) {
					return in_array( $l->status, array( 'active', 'pending_release' ), true );
				}
			)
		);
		$af_req_ty   = array(
			'reparacion' => __( 'Reparación', 'arriendo-facil' ),
			'emergencia' => __( 'Emergencia', 'arriendo-facil' ),
			'limpieza'   => __( 'Limpieza', 'arriendo-facil' ),
			'otro'       => __( 'Otro', 'arriendo-facil' ),
		);
		$af_req_st    = Arriendo_Facil_Maintenance::statuses();
		$af_req_pr    = Arriendo_Facil_Maintenance::priorities();
		$af_charge_st = Arriendo_Facil_Tenant_Access::charge_status_labels();
		$af_services  = $af_data['services'];
		$af_incidents = $af_data['incidents'];
		$af_docs      = $af_data['documents'];
		$af_canon     = $af_data['canon'] ?? array();
		$af_canon_todo = class_exists( 'Arriendo_Facil_Portal_Canon' ) ? Arriendo_Facil_Portal_Canon::count_actionable( $af_canon ) : 0;
		$af_svc_todo  = Arriendo_Facil_Portal_Services::count_actionable( $af_services );
		$af_inc_open  = count(
			array_filter(
				$af_incidents,
				static function ( $r ) {
					return ! in_array( $r->status, array( 'completed', 'cancelled' ), true ) || 'confirm' === Arriendo_Facil_Portal_Incidents::tenant_action( $r );
				}
			)
		);
		$af_ev        = static function ( $id ) {
			return add_query_arg( array( 'action' => 'af_tpa_evidence_view', 'id' => (int) $id ), admin_url( 'admin-ajax.php' ) );
		};
		$af_dt        = static function ( $utc ) {
			return $utc ? wp_date( 'j M Y, H:i', strtotime( $utc . ' UTC' ) ) : '—';
		};
		$af_pay_lbl   = array(
			'pending'   => array( __( 'Por pagar', 'arriendo-facil' ), 'warn' ),
			'submitted' => array( __( 'En revisión', 'arriendo-facil' ), 'info' ),
			'approved'  => array( __( 'Aprobado', 'arriendo-facil' ), 'ok' ),
			'rejected'  => array( __( 'Rechazado: corrige', 'arriendo-facil' ), 'bad' ),
			'received'  => array( __( 'Dinero recibido', 'arriendo-facil' ), 'info' ),
		);
		$af_pay_lbl_via = array(
			'pending'   => array( __( 'Falta entregar', 'arriendo-facil' ), 'warn' ),
			'submitted' => array( __( 'Por confirmar', 'arriendo-facil' ), 'info' ),
			'received'  => array( __( 'Entregado · con recibo', 'arriendo-facil' ), 'info' ),
			'approved'  => array( __( 'Pagado a la empresa', 'arriendo-facil' ), 'ok' ),
		);
		$af_methods   = Arriendo_Facil_Portal_Collections::methods();
		$af_rc_url    = static function ( $id ) {
			return add_query_arg( array( 'action' => 'af_tpa_receipt', 'id' => (int) $id ), admin_url( 'admin-ajax.php' ) );
		};
		$af_resp_lbl  = array(
			'por_definir' => __( 'Responsable por definir', 'arriendo-facil' ),
			'owner'       => __( 'Lo repara el propietario', 'arriendo-facil' ),
			'tenant'      => __( 'Te corresponde repararlo', 'arriendo-facil' ),
		);
		$af_gallery   = static function ( array $items ) use ( $af_ev ) {
			if ( empty( $items ) ) {
				return;
			}
			echo '<div class="af-tp-gallery">';
			foreach ( $items as $ev ) {
				$url = esc_url( $af_ev( $ev->id ) );
				if ( 'application/pdf' === $ev->mime_type ) {
					echo '<a class="af-tp-file" href="' . $url . '" target="_blank" rel="noopener">PDF</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '<a class="af-tp-thumb" href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="' . esc_attr__( 'Foto adjunta', 'arriendo-facil' ) . '" loading="lazy" width="84" height="84"></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			echo '</div>';
		};
		$af_timeline  = static function ( array $events ) use ( $af_dt ) {
			if ( empty( $events ) ) {
				return;
			}
			echo '<details class="af-tp-more"><summary>' . esc_html__( 'Historial', 'arriendo-facil' ) . '</summary><ol class="af-tp-timeline">';
			foreach ( $events as $e ) {
				$who = 'tenant' === $e->actor_type ? __( 'Tú', 'arriendo-facil' ) : ( 'manager' === $e->actor_type ? __( 'Administrador', 'arriendo-facil' ) : __( 'Sistema', 'arriendo-facil' ) );
				echo '<li><span>' . esc_html( $af_dt( $e->created_at ) ) . '</span> ' . esc_html( $who . ' · ' . Arriendo_Facil_Portal_Events::label( $e->action ) ) . '</li>';
			}
			echo '</ol></details>';
		};
		$af_files_input = static function ( string $label = '' ) {
			$lbl = $label ?: __( 'Adjuntar fotos o PDF', 'arriendo-facil' );
			echo '<div class="af-tp-upload">';
			echo '<input class="af-tp-upload__input" type="file" name="files[]" accept="image/*,application/pdf" multiple data-tp-files id="af-tp-file-' . uniqid() . '">';
			echo '<label class="af-tp-upload__zone" onclick="this.previousElementSibling.click()">';
			echo '<span>📎</span>';
			echo '<span class="af-tp-upload__label">' . esc_html( $lbl ) . '</span>';
			echo '<span class="af-tp-upload__count"></span>';
			echo '</label>';
			echo '</div>';
			echo '<div class="af-tp-previews" data-tp-previews></div>';
		};
		$af_todo = array();
		foreach ( $af_canon as $cc ) {
			if ( in_array( $cc['pay_status'], array( 'pending', 'rejected' ), true ) && ! in_array( $cc['charge_status'], array( 'paid', 'void' ), true ) ) {
				$type_label = $af_type_labels[ $cc['charge_type'] ] ?? $cc['charge_type'];
				$af_todo[] = array( 'overview', sprintf( /* translators: 1: type, 2: period, 3: date */ __( 'Enviar comprobante de %1$s %2$s (vence %3$s)', 'arriendo-facil' ), $type_label, $cc['period'], $af_date( $cc['due_date'] ) ) );
			}
		}
		foreach ( $af_services as $svc ) {
			if ( in_array( $svc['pay_status'], array( 'pending', 'rejected' ), true ) ) {
				$af_todo[] = array( 'services', sprintf( /* translators: 1: service, 2: date */ __( 'Pagar %1$s (vence %2$s)', 'arriendo-facil' ), $svc['service_label'], $af_date( $svc['due_date'] ) ) );
			}
		}
		foreach ( $af_incidents as $inc ) {
			$act = Arriendo_Facil_Portal_Incidents::tenant_action( $inc );
			if ( 'upload_repair' === $act ) {
				$af_todo[] = array( 'incidents', __( 'Subir evidencia de una reparación que te corresponde', 'arriendo-facil' ) );
			} elseif ( 'confirm' === $act ) {
				$af_todo[] = array( 'incidents', __( 'Confirmar si una reparación quedó conforme', 'arriendo-facil' ) );
			}
		}
		if ( $af_docs['missing'] > 0 ) {
			$af_todo[] = array( 'profile', sprintf( /* translators: %d: count */ _n( 'Falta %d documento requerido', 'Faltan %d documentos requeridos', $af_docs['missing'], 'arriendo-facil' ), $af_docs['missing'] ) );
		}
		?>
		<header class="af-tp-head">
			<div>
				<p class="af-tp-eyebrow"><?php esc_html_e( 'Portal del inquilino', 'arriendo-facil' ); ?></p>
				<h1 class="af-tp-title"><?php echo esc_html( sprintf( /* translators: %s: first name */ __( 'Hola, %s', 'arriendo-facil' ), $af_guest->first_name ) ); ?></h1>
			</div>
			<button class="af-tp-btn af-tp-btn--ghost" type="button" data-tp-logout><?php esc_html_e( 'Salir', 'arriendo-facil' ); ?></button>
		</header>

		<nav class="af-tp-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Secciones', 'arriendo-facil' ); ?>">
			<button type="button" role="tab" class="is-active" aria-selected="true" data-tp-tab="overview"><span><?php esc_html_e( 'Resumen', 'arriendo-facil' ); ?></span></button>
			<button type="button" role="tab" aria-selected="false" data-tp-tab="services"><span><?php esc_html_e( 'Servicios', 'arriendo-facil' ); ?></span><?php if ( $af_svc_todo ) : ?><span class="af-tp-tab-count" aria-label="<?php echo esc_attr( $af_svc_todo ); ?> servicios pendientes"><?php echo esc_html( number_format_i18n( $af_svc_todo ) ); ?></span><?php endif; ?></button>
			<button type="button" role="tab" aria-selected="false" data-tp-tab="incidents"><span><?php esc_html_e( 'Incidentes', 'arriendo-facil' ); ?></span><?php if ( $af_inc_open ) : ?><span class="af-tp-tab-count af-tp-tab-count--info" aria-label="<?php echo esc_attr( $af_inc_open ); ?> incidentes abiertos"><?php echo esc_html( number_format_i18n( $af_inc_open ) ); ?></span><?php endif; ?></button>
		</nav>

		<!-- ───────── Resumen ───────── -->
		<section class="af-tp-card af-tp-card--overview" data-tp-panel="overview" role="tabpanel">

			<!-- Perfil del inquilino -->
			<div class="af-tp-profile-card">
				<div class="af-tp-profile-card__head">
					<span class="af-tp-profile-card__avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( (string) $af_guest->first_name, 0, 1 ) . mb_substr( (string) $af_guest->last_name, 0, 1 ) ) ); ?></span>
					<div class="af-tp-profile-card__id">
						<h2><?php echo esc_html( $af_name ); ?></h2>
						<span><?php echo esc_html( $af_guest->email ); ?></span>
						<?php if ( $af_guest->phone ) : ?><span><?php echo esc_html( (string) $af_guest->phone ); ?></span><?php endif; ?>
					</div>
					<button type="button" class="af-tp-btn af-tp-btn--ghost af-tp-docs-trigger" data-tp-open-docs aria-haspopup="dialog">
						<?php esc_html_e( 'Documentos', 'arriendo-facil' ); ?>
						<?php if ( $af_docs['missing'] ) : ?>
							<span class="af-tp-tab-count af-tp-tab-count--warn" aria-hidden="true"><?php echo esc_html( number_format_i18n( $af_docs['missing'] ) ); ?></span>
						<?php else : ?>
							<span class="af-tp-pill is-ok"><?php echo esc_html( ucfirst( $af_docs['status'] ) ); ?></span>
						<?php endif; ?>
					</button>
				</div>

				<dl class="af-tp-facts">
					<div><dt><?php esc_html_e( 'Identificación', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $af_idmask ); ?></dd></div>
					<?php if ( ! empty( $af_guest->nationality ) ) : ?>
						<div><dt><?php esc_html_e( 'Nacionalidad', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $af_guest->nationality ); ?></dd></div>
					<?php endif; ?>
					<?php if ( ! empty( $af_guest->birth_city ) ) : ?>
						<div><dt><?php esc_html_e( 'Ciudad de nacimiento', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $af_guest->birth_city ); ?></dd></div>
					<?php endif; ?>
					<?php
					$af_income_opts = Arriendo_Facil_Guest::get_income_range_options();
					if ( ! empty( $af_guest->income_range ) && isset( $af_income_opts[ $af_guest->income_range ] ) ) :
						?>
						<div><dt><?php esc_html_e( 'Rango de ingresos', 'arriendo-facil' ); ?></dt><dd><?php echo esc_html( $af_income_opts[ $af_guest->income_range ] ); ?></dd></div>
					<?php endif; ?>
				</dl>

				<div class="af-tp-profile-card__actions">
					<form class="af-tp-form af-tp-form--inline" data-tp-form="af_tpa_profile_save" novalidate>
						<p class="af-tp-alert" role="alert" data-tp-msg hidden></p>
						<div class="af-tp-row">
							<div style="flex:1 1 160px">
								<label class="af-tp-label" for="af-tp-phone"><?php esc_html_e( 'Teléfono', 'arriendo-facil' ); ?></label>
								<input class="af-tp-input" type="tel" id="af-tp-phone" name="phone" inputmode="tel" autocomplete="tel" maxlength="20" value="<?php echo esc_attr( (string) $af_guest->phone ); ?>" required />
							</div>
							<button class="af-tp-btn af-tp-btn--primary" type="submit" data-tp-submit style="align-self:flex-end;flex-shrink:0"><?php esc_html_e( 'Guardar', 'arriendo-facil' ); ?></button>
						</div>
					</form>
					<details class="af-tp-more">
						<summary><?php esc_html_e( 'Cambiar correo', 'arriendo-facil' ); ?></summary>
						<form class="af-tp-form" data-tp-form="af_tpa_email_request" data-tp-reveal="#af-tp-email-code" novalidate>
							<p class="af-tp-alert" role="alert" data-tp-msg hidden></p>
							<label class="af-tp-label" for="af-tp-newmail"><?php esc_html_e( 'Correo nuevo', 'arriendo-facil' ); ?></label>
							<input class="af-tp-input" type="email" id="af-tp-newmail" name="new_email" autocomplete="email" maxlength="190" required />
							<button class="af-tp-btn" type="submit" data-tp-submit><?php esc_html_e( 'Enviar código al correo nuevo', 'arriendo-facil' ); ?></button>
						</form>
						<form class="af-tp-form" id="af-tp-email-code" data-tp-form="af_tpa_email_verify" novalidate hidden>
							<p class="af-tp-alert" role="alert" data-tp-msg hidden></p>
							<label class="af-tp-label" for="af-tp-mailcode"><?php esc_html_e( 'Código de 6 dígitos', 'arriendo-facil' ); ?></label>
							<input class="af-tp-input af-tp-input--code" type="text" id="af-tp-mailcode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required />
							<button class="af-tp-btn af-tp-btn--primary" type="submit" data-tp-submit><?php esc_html_e( 'Confirmar cambio', 'arriendo-facil' ); ?></button>
						</form>
					</details>
					<details class="af-tp-more">
						<summary><?php esc_html_e( 'Corregir nombre o identificación', 'arriendo-facil' ); ?></summary>
						<p class="af-tp-meta"><?php esc_html_e( 'Por seguridad estos datos los cambia tu administrador. Cuéntanos qué corregir y lo revisará con tu documento.', 'arriendo-facil' ); ?></p>
						<form class="af-tp-form" data-tp-form="af_tpa_correction" novalidate>
							<p class="af-tp-alert" role="alert" data-tp-msg hidden></p>
							<select class="af-tp-input" name="field"><option value="name"><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?></option><option value="id_number"><?php esc_html_e( 'Identificación', 'arriendo-facil' ); ?></option></select>
							<input class="af-tp-input" type="text" name="value" maxlength="190" placeholder="<?php esc_attr_e( 'Valor correcto', 'arriendo-facil' ); ?>" required />
							<input class="af-tp-input" type="text" name="note" maxlength="255" placeholder="<?php esc_attr_e( 'Motivo (opcional)', 'arriendo-facil' ); ?>" />
							<button class="af-tp-btn" type="submit" data-tp-submit><?php esc_html_e( 'Solicitar corrección', 'arriendo-facil' ); ?></button>
						</form>
					</details>
				</div>
			</div>

			<h2 class="af-tp-h2"><?php esc_html_e( 'Pendiente de ti', 'arriendo-facil' ); ?></h2>
			<?php if ( empty( $af_todo ) ) : ?>
				<p class="af-tp-empty"><?php esc_html_e( 'Estás al día. No tienes nada pendiente.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<ul class="af-tp-list">
					<?php foreach ( $af_todo as $todo ) : ?>
						<li class="af-tp-item"><div class="af-tp-item__main"><strong><?php echo esc_html( $todo[1] ); ?></strong></div><button type="button" class="af-tp-btn af-tp-btn--small" data-tp-goto="<?php echo esc_attr( $todo[0] ); ?>"><?php esc_html_e( 'Ir', 'arriendo-facil' ); ?></button></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

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

			<h2 class="af-tp-h2"><?php esc_html_e( 'Arriendo', 'arriendo-facil' ); ?>
				<span class="af-tp-h2__aside <?php echo $af_data['balance'] > 0 ? 'is-bad' : 'is-ok'; ?>"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'Saldo pendiente %s', 'arriendo-facil' ), $af_money( $af_data['balance'] ) ) ); ?></span>
			</h2>
			<p class="af-tp-lead af-tp-lead--left"><?php esc_html_e( 'Cuando pagues el arriendo, sube tu comprobante. Tu administrador lo revisará y quedará guardado como respaldo para los dos.', 'arriendo-facil' ); ?></p>
			<?php if ( empty( $af_canon ) ) : ?>
				<p class="af-tp-empty"><?php esc_html_e( 'Todavía no hay cobros registrados.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<ul class="af-tp-stack">
					<?php foreach ( $af_canon as $cc ) :
						$cc_lbl  = $af_pay_lbl[ $cc['pay_status'] ] ?? $af_pay_lbl['pending'];
						$cc_type = $af_type_labels[ $cc['charge_type'] ] ?? $cc['charge_type'];
						$cc_can_submit = in_array( $cc['pay_status'], array( 'pending', 'rejected' ), true ) && ! in_array( $cc['charge_status'], array( 'paid', 'void' ), true );
						$cc_methods = Arriendo_Facil_Billing_Ledger::invoice_payment_methods();
					?>
						<li class="af-tp-svc" id="af-tp-canon-<?php echo esc_attr( (string) $cc['id'] ); ?>">
							<div class="af-tp-svc__top">
								<div class="af-tp-item__main">
									<strong><?php echo esc_html( $cc_type . ' · ' . $cc['period'] ); ?></strong>
									<small><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Vence %s', 'arriendo-facil' ), $af_date( $cc['due_date'] ) ) ); ?></small>
									<?php if ( $cc['description'] ) : ?><small><?php echo esc_html( $cc['description'] ); ?></small><?php endif; ?>
								</div>
								<div class="af-tp-item__side">
									<strong><?php echo esc_html( $af_money( $cc['amount'] ) ); ?></strong>
									<span class="af-tp-pill is-<?php echo esc_attr( $cc_lbl[1] ); ?>"><?php echo esc_html( $cc_lbl[0] ); ?></span>
								</div>
							</div>

							<?php if ( 'rejected' === $cc['pay_status'] && $cc['review_note'] ) : ?>
								<p class="af-tp-alert is-warn" style="margin:8px 0 0"><?php echo esc_html( sprintf( /* translators: %s: reason */ __( 'Rechazado: %s', 'arriendo-facil' ), $cc['review_note'] ) ); ?></p>
							<?php endif; ?>

							<?php if ( $cc_can_submit ) : ?>
								<form class="af-tp-form af-tp-form--canon" data-tp-canon-form="<?php echo esc_attr( (string) $cc['id'] ); ?>" novalidate style="margin-top:12px">
									<p class="af-tp-alert" role="alert" data-tp-msg hidden></p>
									<div class="af-tp-row">
										<div style="flex:1 1 110px">
											<label class="af-tp-label"><?php esc_html_e( 'Fecha de pago', 'arriendo-facil' ); ?></label>
											<input class="af-tp-input" type="date" name="paid_on" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required>
										</div>
										<div style="flex:1 1 110px">
											<label class="af-tp-label"><?php esc_html_e( 'Monto pagado', 'arriendo-facil' ); ?></label>
											<input class="af-tp-input" type="number" name="amount" step="0.01" min="1" value="<?php echo esc_attr( (string) $cc['amount'] ); ?>" required>
										</div>
									</div>
									<div class="af-tp-row">
										<div style="flex:1 1 130px">
											<label class="af-tp-label"><?php esc_html_e( 'Método de pago', 'arriendo-facil' ); ?></label>
											<select class="af-tp-input" name="method">
												<?php foreach ( $cc_methods as $k => $v ) : ?>
													<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option>
												<?php endforeach; ?>
											</select>
										</div>
										<div style="flex:1 1 130px">
											<label class="af-tp-label"><?php esc_html_e( 'Referencia / comprobante No.', 'arriendo-facil' ); ?></label>
											<input class="af-tp-input" type="text" name="reference" maxlength="190" placeholder="<?php esc_attr_e( 'Opcional', 'arriendo-facil' ); ?>">
										</div>
									</div>
									<?php $af_files_input( __( 'Adjuntar comprobante (requerido)', 'arriendo-facil' ) ); ?>
									<button class="af-tp-btn af-tp-btn--primary" type="submit" data-tp-submit><?php esc_html_e( 'Enviar comprobante', 'arriendo-facil' ); ?></button>
								</form>
							<?php endif; ?>

							<?php if ( 'submitted' === $cc['pay_status'] ) : ?>
								<p class="af-tp-meta" style="margin-top:8px"><?php echo esc_html( sprintf( /* translators: 1: amount, 2: date */ __( 'Enviaste $%1$s el %2$s. Tu administrador lo revisará pronto.', 'arriendo-facil' ), number_format_i18n( $cc['paid_amount'], 2 ), $af_date( $cc['paid_on'] ) ) ); ?></p>
								<?php $af_gallery( $cc['evidence'] ); ?>
							<?php endif; ?>

							<?php if ( 'approved' === $cc['pay_status'] ) : ?>
								<p class="af-tp-meta" style="margin-top:8px"><?php echo esc_html( sprintf( /* translators: 1: amount, 2: date */ __( '✓ Pago de $%1$s aprobado el %2$s.', 'arriendo-facil' ), number_format_i18n( $cc['paid_amount'], 2 ), $af_date( $cc['paid_on'] ) ) ); ?></p>
								<?php $af_gallery( $cc['evidence'] ); ?>
							<?php endif; ?>

							<?php if ( ! empty( $cc['timeline'] ) ) : ?>
								<?php $af_timeline( $cc['timeline'] ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<!-- ───────── Servicios ───────── -->
		<section class="af-tp-card" data-tp-panel="services" role="tabpanel" hidden>
			<h2 class="af-tp-h2"><?php esc_html_e( 'Servicios que pagas tú', 'arriendo-facil' ); ?></h2>
			<p class="af-tp-lead af-tp-lead--left"><?php esc_html_e( 'Cuando pagues un servicio, márcalo y sube tu comprobante. Tu administrador lo revisa y queda guardado como respaldo para los dos.', 'arriendo-facil' ); ?></p>
			<?php if ( empty( $af_services ) ) : ?>
				<p class="af-tp-empty"><?php esc_html_e( 'No tienes servicios a tu cargo por ahora.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<ul class="af-tp-stack">
					<?php foreach ( $af_services as $svc ) : ?>
						<?php
						$af_via = 'via_owner' === $svc['payer'];
						$lbl    = $af_via ? ( $af_pay_lbl_via[ $svc['pay_status'] ] ?? $af_pay_lbl_via['pending'] ) : ( $af_pay_lbl[ $svc['pay_status'] ] ?? $af_pay_lbl['pending'] );
						?>
						<li class="af-tp-svc">
							<div class="af-tp-svc__top">
								<div class="af-tp-item__main">
									<strong><?php echo esc_html( $svc['service_label'] ); ?></strong>
									<small><?php echo esc_html( $svc['accommodation_title'] . ' · ' . $svc['period'] ); ?></small>
									<small><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Vence %s', 'arriendo-facil' ), $af_date( $svc['due_date'] ) ) ); ?></small>
								</div>
								<span class="af-tp-pill is-<?php echo esc_attr( $lbl[1] ); ?>"><?php echo esc_html( $lbl[0] ); ?></span>
							</div>

							<?php if ( $af_via ) : ?>
								<p class="af-tp-meta"><?php esc_html_e( 'Este servicio lo paga tu administrador a la empresa. Tú le entregas el dinero y, cuando lo recibe, te emite un recibo que te respalda.', 'arriendo-facil' ); ?></p>
								<?php if ( $svc['expected_amount'] > 0 ) : ?>
									<p class="af-tp-meta"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'Monto estimado: %s', 'arriendo-facil' ), $af_money( $svc['expected_amount'] ) ) ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $svc['deliveries'] ) ) : ?>
									<ul class="af-tp-deliveries">
										<?php foreach ( $svc['deliveries'] as $d ) : ?>
											<li>
												<span><?php echo esc_html( $af_money( (float) $d->amount ) . ' · ' . ( $af_methods[ $d->method ] ?? $d->method ) . ' · ' . $af_date( $d->delivered_on ) ); ?></span>
												<?php if ( 'received' === $d->status ) : ?>
													<a class="af-tp-link" href="<?php echo esc_url( $af_rc_url( $d->id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( sprintf( /* translators: %s: receipt number */ __( 'Ver recibo %s', 'arriendo-facil' ), $d->receipt_no ) ); ?></a>
												<?php elseif ( 'submitted' === $d->status ) : ?>
													<em><?php esc_html_e( 'esperando confirmación', 'arriendo-facil' ); ?></em>
												<?php else : ?>
													<em class="is-bad"><?php echo esc_html( sprintf( /* translators: %s: reason */ __( 'no confirmada: %s', 'arriendo-facil' ), (string) $d->review_note ) ); ?></em>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
								<?php if ( 'approved' === $svc['pay_status'] && ! empty( $svc['company_paid_on'] ) ) : ?>
									<p class="af-tp-meta"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Tu administrador pagó a la empresa el %s.', 'arriendo-facil' ), $af_date( $svc['company_paid_on'] ) ) ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( 'rejected' === $svc['pay_status'] && $svc['review_note'] ) : ?>
								<p class="af-tp-alert is-note"><?php echo esc_html( sprintf( /* translators: %s: reason */ __( 'Motivo: %s', 'arriendo-facil' ), $svc['review_note'] ) ); ?></p>
							<?php endif; ?>
							<?php if ( $svc['bill_id'] && $svc['paid_amount'] > 0 ) : ?>
								<p class="af-tp-meta"><?php echo esc_html( sprintf( /* translators: 1: amount, 2: date */ __( 'Enviaste %1$s el %2$s', 'arriendo-facil' ), $af_money( $svc['paid_amount'] ), $af_date( $svc['paid_on'] ) ) ); ?></p>
							<?php endif; ?>

							<?php $af_gallery( $svc['evidence'] ); ?>

							<?php if ( $af_via && in_array( $svc['pay_status'], array( 'pending', 'received' ), true ) ) : ?>
								<button type="button" class="af-tp-btn af-tp-btn--primary" data-tp-open-delivery
									data-schedule="<?php echo esc_attr( $svc['schedule_id'] ); ?>"
									data-period="<?php echo esc_attr( $svc['period'] ); ?>"
									data-label="<?php echo esc_attr( $svc['service_label'] . ' · ' . $svc['period'] ); ?>"
									data-amount="<?php echo esc_attr( $svc['expected_amount'] > 0 ? $svc['expected_amount'] : '' ); ?>"><?php echo esc_html( 'received' === $svc['pay_status'] ? __( 'Informar otra entrega', 'arriendo-facil' ) : __( 'Informar entrega de dinero', 'arriendo-facil' ) ); ?></button>
							<?php endif; ?>
							<?php if ( ! $af_via && in_array( $svc['pay_status'], array( 'pending', 'rejected' ), true ) ) : ?>
								<button type="button" class="af-tp-btn af-tp-btn--primary" data-tp-open-pay
									data-schedule="<?php echo esc_attr( $svc['schedule_id'] ); ?>"
									data-period="<?php echo esc_attr( $svc['period'] ); ?>"
									data-label="<?php echo esc_attr( $svc['service_label'] . ' · ' . $svc['period'] ); ?>"
									data-amount="<?php echo esc_attr( $svc['expected_amount'] > 0 ? $svc['expected_amount'] : '' ); ?>"><?php echo esc_html( 'rejected' === $svc['pay_status'] ? __( 'Enviar comprobante corregido', 'arriendo-facil' ) : __( 'Marcar como pagado', 'arriendo-facil' ) ); ?></button>
							<?php endif; ?>
							<?php $af_timeline( $svc['timeline'] ); ?>

							<?php
							// Historial de pagos anteriores (excluye el período actual que ya se muestra arriba).
							Arriendo_Facil_Portal_Manager::render_service_history(
								(int) $svc['schedule_id'],
								(string) $svc['period'],
								$af_ev,
								false
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<!-- ───────── Incidentes ───────── -->
		<section class="af-tp-card" data-tp-panel="incidents" role="tabpanel" hidden>
			<h2 class="af-tp-h2"><?php esc_html_e( 'Reportar un incidente', 'arriendo-facil' ); ?></h2>
			<?php if ( empty( $af_open ) ) : ?>
				<p class="af-tp-empty"><?php esc_html_e( 'Podrás reportar incidentes cuando tengas un contrato vigente.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<p class="af-tp-lead af-tp-lead--left"><?php esc_html_e( 'Cuéntanos qué se dañó y adjunta fotos. Tu administrador lo ve de inmediato y todo queda registrado.', 'arriendo-facil' ); ?></p>
				<form class="af-tp-form" data-tp-form="af_tpa_incident_report" novalidate>
					<p class="af-tp-alert" role="alert" data-tp-msg hidden></p>
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

					<div class="af-tp-row">
						<div>
							<label class="af-tp-label" for="af-tp-asset"><?php esc_html_e( '¿Qué se dañó?', 'arriendo-facil' ); ?></label>
							<input class="af-tp-input" type="text" id="af-tp-asset" name="asset_name" maxlength="190" placeholder="<?php esc_attr_e( 'Ej. calefón, llave del lavabo', 'arriendo-facil' ); ?>" />
						</div>
						<div>
							<label class="af-tp-label" for="af-tp-where"><?php esc_html_e( '¿Dónde?', 'arriendo-facil' ); ?></label>
							<input class="af-tp-input" type="text" id="af-tp-where" name="asset_location" maxlength="120" placeholder="<?php esc_attr_e( 'Ej. baño principal', 'arriendo-facil' ); ?>" />
						</div>
					</div>

					<label class="af-tp-label" for="af-tp-details"><?php esc_html_e( 'Cuéntanos qué pasó', 'arriendo-facil' ); ?></label>
					<textarea class="af-tp-input" id="af-tp-details" name="damage_details" rows="4" minlength="10" maxlength="1000" required></textarea>

					<?php $af_files_input(); ?>
					<button class="af-tp-btn af-tp-btn--primary" type="submit" data-tp-submit><?php esc_html_e( 'Enviar incidente', 'arriendo-facil' ); ?></button>
				</form>
			<?php endif; ?>

			<h2 class="af-tp-h2"><?php esc_html_e( 'Mis incidentes', 'arriendo-facil' ); ?></h2>
			<?php if ( empty( $af_incidents ) ) : ?>
				<p class="af-tp-empty"><?php esc_html_e( 'No has reportado incidentes.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<ul class="af-tp-stack">
					<?php foreach ( $af_incidents as $inc ) : ?>
						<?php
						$rtone = 'completed' === $inc->status ? 'ok' : ( 'in_progress' === $inc->status ? 'warn' : ( 'cancelled' === $inc->status ? 'info' : 'bad' ) );
						$act   = Arriendo_Facil_Portal_Incidents::tenant_action( $inc );
						?>
						<li class="af-tp-svc">
							<div class="af-tp-svc__top">
								<div class="af-tp-item__main">
									<strong><?php echo esc_html( ( $inc->asset_name ? $inc->asset_name : ( $af_req_ty[ $inc->request_type ] ?? ucfirst( (string) $inc->request_type ) ) ) . ' · ' . $af_date( $inc->requested_date ) ); ?></strong>
									<small><?php echo esc_html( wp_trim_words( (string) $inc->damage_details, 24 ) ); ?></small>
								</div>
								<span class="af-tp-pill is-<?php echo esc_attr( $rtone ); ?>"><?php echo esc_html( $af_req_st[ $inc->status ] ?? $inc->status ); ?></span>
							</div>

							<div class="af-tp-tags">
								<span class="af-tp-pill is-<?php echo esc_attr( 'tenant' === $inc->responsibility ? 'bad' : ( 'owner' === $inc->responsibility ? 'ok' : 'info' ) ); ?>"><?php echo esc_html( $af_resp_lbl[ $inc->responsibility ] ?? $inc->responsibility ); ?></span>
								<?php if ( $inc->recurrence_of_id ) : ?>
									<span class="af-tp-pill is-warn"><?php esc_html_e( 'Reincidencia', 'arriendo-facil' ); ?></span>
								<?php endif; ?>
								<?php if ( $inc->scheduled_date ) : ?>
									<span class="af-tp-pill is-info"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Visita: %s', 'arriendo-facil' ), $af_date( $inc->scheduled_date ) ) ); ?></span>
								<?php endif; ?>
							</div>

							<?php if ( $inc->responsibility_note ) : ?>
								<p class="af-tp-alert is-note"><strong><?php esc_html_e( 'Motivo registrado:', 'arriendo-facil' ); ?></strong> <?php echo esc_html( $inc->responsibility_note ); ?></p>
							<?php endif; ?>

							<?php if ( $inc->report_evidence ) : ?><p class="af-tp-meta"><?php esc_html_e( 'Tus fotos', 'arriendo-facil' ); ?></p><?php $af_gallery( $inc->report_evidence ); ?><?php endif; ?>
							<?php if ( $inc->repair_evidence ) : ?><p class="af-tp-meta"><?php esc_html_e( 'Evidencia de la reparación', 'arriendo-facil' ); ?></p><?php $af_gallery( $inc->repair_evidence ); ?><?php endif; ?>

							<?php if ( 'upload_repair' === $act ) : ?>
								<form class="af-tp-form af-tp-form--inline" data-tp-form="af_tpa_incident_evidence" novalidate>
									<p class="af-tp-alert" role="alert" data-tp-msg hidden></p>
									<input type="hidden" name="request_id" value="<?php echo esc_attr( $inc->id ); ?>" />
									<p class="af-tp-meta"><?php esc_html_e( 'Esta reparación te corresponde. Cuando esté lista, sube fotos del resultado o la factura.', 'arriendo-facil' ); ?></p>
									<?php $af_files_input(); ?>
									<button class="af-tp-btn af-tp-btn--primary" type="submit" data-tp-submit><?php esc_html_e( 'Subir evidencia de reparación', 'arriendo-facil' ); ?></button>
								</form>
							<?php elseif ( 'confirm' === $act ) : ?>
								<form class="af-tp-form af-tp-form--inline" data-tp-form="af_tpa_incident_ack" novalidate>
									<p class="af-tp-alert" role="alert" data-tp-msg hidden></p>
									<input type="hidden" name="request_id" value="<?php echo esc_attr( $inc->id ); ?>" />
									<p class="af-tp-meta"><strong><?php esc_html_e( '¿La reparación quedó bien?', 'arriendo-facil' ); ?></strong> <?php esc_html_e( 'Tu respuesta queda como constancia.', 'arriendo-facil' ); ?></p>
									<input class="af-tp-input" type="text" name="note" maxlength="255" placeholder="<?php esc_attr_e( 'Comentario (obligatorio si no estás conforme)', 'arriendo-facil' ); ?>" />
									<div class="af-tp-row">
										<button class="af-tp-btn af-tp-btn--primary" type="submit" name="answer" value="conforme" data-tp-submit><?php esc_html_e( 'Recibí conforme', 'arriendo-facil' ); ?></button>
										<button class="af-tp-btn af-tp-btn--ghost" type="submit" name="answer" value="no_conforme" data-tp-submit><?php esc_html_e( 'No estoy conforme', 'arriendo-facil' ); ?></button>
									</div>
								</form>
							<?php elseif ( $inc->tenant_ack ) : ?>
								<p class="af-tp-meta"><?php echo esc_html( sprintf( /* translators: 1: answer, 2: date */ __( 'Tu respuesta: %1$s (%2$s)', 'arriendo-facil' ), 'conforme' === $inc->tenant_ack ? __( 'conforme', 'arriendo-facil' ) : __( 'no conforme', 'arriendo-facil' ), $af_dt( $inc->tenant_ack_at ) ) ); ?></p>
							<?php endif; ?>

							<?php $af_timeline( $inc->timeline ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<!-- Diálogos -->

		<!-- Lightbox: comprobante -->
		<dialog class="af-tp-dialog af-tp-dialog--lightbox" data-tp-dialog="lightbox" aria-label="<?php esc_attr_e( 'Comprobante de pago', 'arriendo-facil' ); ?>">
			<div class="af-tp-dialog__head">
				<span class="af-tp-h2"><?php esc_html_e( 'Comprobante de pago', 'arriendo-facil' ); ?></span>
				<button type="button" class="af-tp-btn-close" data-tp-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&#x2715;</button>
			</div>
			<div class="af-tp-lightbox__body" id="af-tp-lightbox-body"></div>
		</dialog>

		<dialog class="af-tp-dialog af-tp-dialog--docs" data-tp-dialog="docs" aria-labelledby="af-tp-docs-title">
			<div class="af-tp-dialog__head">
				<h2 class="af-tp-h2" id="af-tp-docs-title"><?php esc_html_e( 'Mis documentos', 'arriendo-facil' ); ?>
					<span class="af-tp-pill is-<?php echo esc_attr( 'verificado' === $af_docs['status'] ? 'ok' : ( 'rechazado' === $af_docs['status'] ? 'bad' : 'info' ) ); ?>"><?php echo esc_html( ucfirst( $af_docs['status'] ) ); ?></span>
				</h2>
				<button type="button" class="af-tp-btn-close" data-tp-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&#x2715;</button>
			</div>
			<ul class="af-tp-list">
				<?php foreach ( $af_docs['types'] as $key => $doc ) : ?>
					<li class="af-tp-item">
						<div class="af-tp-item__main">
							<strong><?php echo esc_html( $doc['label'] ); ?></strong>
							<small><?php echo esc_html( $doc['uploaded'] ? sprintf( /* translators: %s: date */ __( 'Subido %s', 'arriendo-facil' ), $af_dt( $doc['uploaded_at'] ) ) : ( $doc['required'] ? __( 'Falta · requerido', 'arriendo-facil' ) : ( $doc['income'] ? __( 'Opcional · comprobante de ingresos', 'arriendo-facil' ) : __( 'Opcional', 'arriendo-facil' ) ) ) ); ?></small>
						</div>
						<button type="button" class="af-tp-btn af-tp-btn--small" data-tp-open-doc data-type="<?php echo esc_attr( $key ); ?>" data-label="<?php echo esc_attr( $doc['label'] ); ?>"><?php echo esc_html( $doc['uploaded'] ? __( 'Reemplazar', 'arriendo-facil' ) : __( 'Subir', 'arriendo-facil' ) ); ?></button>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="af-tp-meta"><?php esc_html_e( 'Tus documentos se guardan en un espacio privado y solo los ve tu administrador. No se pueden descargar desde aquí.', 'arriendo-facil' ); ?></p>
			<div class="af-tp-row">
				<button type="button" class="af-tp-btn af-tp-btn--ghost" data-tp-close><?php esc_html_e( 'Cerrar', 'arriendo-facil' ); ?></button>
			</div>
		</dialog>

		<dialog class="af-tp-dialog" data-tp-dialog="pay" aria-labelledby="af-tp-pay-title">
			<form class="af-tp-form" data-tp-form="af_tpa_service_pay" novalidate>
				<h2 class="af-tp-h2" id="af-tp-pay-title"><?php esc_html_e( 'Registrar pago', 'arriendo-facil' ); ?></h2>
				<p class="af-tp-meta" data-tp-dialog-label></p>
				<p class="af-tp-alert" role="alert" data-tp-msg hidden></p>
				<input type="hidden" name="schedule_id" /><input type="hidden" name="period" />
				<div class="af-tp-row">
					<div><label class="af-tp-label" for="af-tp-paid-on"><?php esc_html_e( 'Fecha de pago', 'arriendo-facil' ); ?></label>
						<input class="af-tp-input" type="date" id="af-tp-paid-on" name="paid_on" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required /></div>
					<div><label class="af-tp-label" for="af-tp-amount"><?php esc_html_e( 'Monto pagado ($)', 'arriendo-facil' ); ?></label>
						<input class="af-tp-input" type="text" id="af-tp-amount" name="amount" inputmode="decimal" required /></div>
				</div>
				<label class="af-tp-label" for="af-tp-ref"><?php esc_html_e( 'Referencia (opcional)', 'arriendo-facil' ); ?></label>
				<input class="af-tp-input" type="text" id="af-tp-ref" name="reference" maxlength="190" />
				<?php $af_files_input(); ?>
				<div class="af-tp-row">
					<button class="af-tp-btn af-tp-btn--ghost" type="button" data-tp-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button class="af-tp-btn af-tp-btn--primary" type="submit" data-tp-submit><?php esc_html_e( 'Enviar pago', 'arriendo-facil' ); ?></button>
				</div>
			</form>
		</dialog>

		<dialog class="af-tp-dialog" data-tp-dialog="delivery" aria-labelledby="af-tp-del-title">
			<form class="af-tp-form" data-tp-form="af_tpa_delivery" novalidate>
				<h2 class="af-tp-h2" id="af-tp-del-title"><?php esc_html_e( 'Informar entrega de dinero', 'arriendo-facil' ); ?></h2>
				<p class="af-tp-meta" data-tp-dialog-label></p>
				<p class="af-tp-alert" role="alert" data-tp-msg hidden></p>
				<input type="hidden" name="schedule_id" /><input type="hidden" name="period" />
				<label class="af-tp-label" for="af-tp-del-method"><?php esc_html_e( 'Cómo se lo entregaste', 'arriendo-facil' ); ?></label>
				<select class="af-tp-input" id="af-tp-del-method" name="method" required data-tp-method>
					<?php foreach ( $af_methods as $mk => $ml ) : ?>
						<option value="<?php echo esc_attr( $mk ); ?>"><?php echo esc_html( $ml ); ?></option>
					<?php endforeach; ?>
				</select>
				<div class="af-tp-row">
					<div><label class="af-tp-label" for="af-tp-del-on"><?php esc_html_e( 'Fecha de entrega', 'arriendo-facil' ); ?></label>
						<input class="af-tp-input" type="date" id="af-tp-del-on" name="delivered_on" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required /></div>
					<div><label class="af-tp-label" for="af-tp-del-amount"><?php esc_html_e( 'Monto entregado ($)', 'arriendo-facil' ); ?></label>
						<input class="af-tp-input" type="text" id="af-tp-del-amount" name="amount" inputmode="decimal" required /></div>
				</div>
				<label class="af-tp-label" for="af-tp-del-ref"><?php esc_html_e( 'Referencia (opcional)', 'arriendo-facil' ); ?></label>
				<input class="af-tp-input" type="text" id="af-tp-del-ref" name="reference" maxlength="190" />
				<p class="af-tp-meta" data-tp-proof-hint><?php esc_html_e( 'En efectivo el comprobante es opcional: tu administrador te emitirá un recibo al confirmar. En transferencia o depósito sube la foto del comprobante.', 'arriendo-facil' ); ?></p>
				<?php $af_files_input(); ?>
				<div class="af-tp-row">
					<button class="af-tp-btn af-tp-btn--ghost" type="button" data-tp-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button class="af-tp-btn af-tp-btn--primary" type="submit" data-tp-submit><?php esc_html_e( 'Enviar', 'arriendo-facil' ); ?></button>
				</div>
			</form>
		</dialog>

		<dialog class="af-tp-dialog" data-tp-dialog="doc" aria-labelledby="af-tp-doc-title">
			<form class="af-tp-form" data-tp-form="af_tpa_doc_upload" novalidate>
				<h2 class="af-tp-h2" id="af-tp-doc-title"><?php esc_html_e( 'Subir documento', 'arriendo-facil' ); ?></h2>
				<p class="af-tp-meta" data-tp-dialog-label></p>
				<p class="af-tp-alert" role="alert" data-tp-msg hidden></p>
				<input type="hidden" name="doc_type" />
				<?php $af_files_input(); ?>
				<div class="af-tp-row">
					<button class="af-tp-btn af-tp-btn--ghost" type="button" data-tp-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button class="af-tp-btn af-tp-btn--primary" type="submit" data-tp-submit><?php esc_html_e( 'Subir', 'arriendo-facil' ); ?></button>
				</div>
			</form>
		</dialog>

		<div class="af-tp-idle" data-tp-idle role="alertdialog" aria-live="assertive" hidden>
			<span><?php esc_html_e( 'Tu sesión se cerrará pronto por inactividad.', 'arriendo-facil' ); ?></span>
			<button type="button" class="af-tp-btn af-tp-btn--small af-tp-btn--primary" data-tp-stay><?php esc_html_e( 'Seguir aquí', 'arriendo-facil' ); ?></button>
		</div>

		<p class="af-tp-fine"><?php esc_html_e( 'Por seguridad la sesión se cierra sola tras 30 minutos sin actividad.', 'arriendo-facil' ); ?></p>

	<?php endif; ?>
</main>
<?php
get_footer();
