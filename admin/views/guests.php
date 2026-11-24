<?php
/**
 * Huespedes admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$is_owner  = Arriendo_Facil_Accommodation::user_is_owner();
$owner_ids = array();

if ( $is_owner ) {
	$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
}

$queue_table = $wpdb->prefix . 'af_interest_queue';
$posts_table = $wpdb->posts;
$action_notice = '';
$action_notice_class = 'notice-success';

$can_manage_accommodation = static function ( $accommodation_id ) use ( $is_owner, $owner_ids ) {
	$accommodation_id = absint( $accommodation_id );
	if ( ! $accommodation_id || ! current_user_can( 'edit_posts' ) ) {
		return false;
	}

	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	if ( ! $is_owner ) {
		return false;
	}

	return in_array( $accommodation_id, $owner_ids, true );
};

if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['af_queue_action'], $_POST['af_queue_request_id'], $_POST['af_queue_nonce'] ) ) {
	$queue_action = sanitize_key( wp_unslash( $_POST['af_queue_action'] ) );
	$request_id   = absint( wp_unslash( $_POST['af_queue_request_id'] ) );
	$nonce        = sanitize_text_field( wp_unslash( $_POST['af_queue_nonce'] ) );

	if ( ! wp_verify_nonce( $nonce, 'af_queue_action' ) ) {
		$action_notice = __( 'Nonce invalido. Recarga la pagina e intenta nuevamente.', 'arriendo-facil' );
		$action_notice_class = 'notice-error';
	} elseif ( ! current_user_can( 'edit_posts' ) ) {
		$action_notice = __( 'No tienes permisos para ejecutar esta accion.', 'arriendo-facil' );
		$action_notice_class = 'notice-error';
	} else {
			$request_row = $wpdb->get_row(
			$wpdb->prepare(
					"SELECT id, accommodation_id, name, email, phone, status FROM {$queue_table} WHERE id = %d LIMIT 1",
				$request_id
			)
		);

		if ( ! $request_row ) {
			$action_notice = __( 'La solicitud ya no existe.', 'arriendo-facil' );
			$action_notice_class = 'notice-error';
		} elseif ( ! $can_manage_accommodation( (int) $request_row->accommodation_id ) ) {
			$action_notice = __( 'No puedes gestionar esta acomodacion.', 'arriendo-facil' );
			$action_notice_class = 'notice-error';
		} else {
			if ( 'approve' === $queue_action ) {
				$updated_selected = $wpdb->update(
					$queue_table,
					array( 'status' => 'approved' ),
					array( 'id' => $request_id ),
					array( '%s' ),
					array( '%d' )
				);

				if ( false === $updated_selected ) {
					$action_notice = __( 'No se pudo aprobar la solicitud.', 'arriendo-facil' );
					$action_notice_class = 'notice-error';
				} else {
					$other_rejected = (int) $wpdb->query(
						$wpdb->prepare(
							"UPDATE {$queue_table}
							 SET status = 'rejected'
							 WHERE accommodation_id = %d
							   AND id <> %d
							   AND status IN ('queued','notified','visit_requested')",
							(int) $request_row->accommodation_id,
							$request_id
						)
					);

					$selected_email = isset( $request_row->email ) ? sanitize_email( (string) $request_row->email ) : '';
					$selected_guest_id = 0;
					if ( '' !== $selected_email ) {
						$selected_guest_id = (int) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT id
								 FROM {$wpdb->prefix}af_guests
								 WHERE accommodation_id = %d AND email = %s
								 ORDER BY id DESC
								 LIMIT 1",
								(int) $request_row->accommodation_id,
								$selected_email
							)
						);
					}

					$terminated_drafts = 0;
					if ( $selected_guest_id > 0 ) {
						$terminated_drafts = (int) $wpdb->query(
							$wpdb->prepare(
								"UPDATE {$wpdb->prefix}af_leases
								 SET status = 'terminated', document_url = ''
								 WHERE accommodation_id = %d
								   AND guest_id <> %d
								   AND status = 'draft'",
								(int) $request_row->accommodation_id,
								$selected_guest_id
							)
						);
					}

					$action_notice = sprintf(
						/* translators: 1: rejected requests count, 2: archived draft contracts count */
						__( 'Solicitud aprobada. %1$d solicitud(es) adicional(es) fueron rechazadas automaticamente y %2$d contrato(s) draft de otros interesados fueron archivados.', 'arriendo-facil' ),
						max( 0, $other_rejected ),
						max( 0, $terminated_drafts )
					);

					// Send legal-profile form link to the approved guest.
					if ( '' !== $selected_email && class_exists( 'Arriendo_Facil_Guest_Onboarding' ) ) {
						$guest_service      = new Arriendo_Facil_Guest_Onboarding();
						$approved_name      = isset( $request_row->name ) ? sanitize_text_field( (string) $request_row->name ) : '';
						$approved_phone     = isset( $request_row->phone ) ? sanitize_text_field( (string) $request_row->phone ) : '';
						$approved_acc_id    = absint( $request_row->accommodation_id );

						// Check for an existing confirmed booking to link the token.
						$existing_booking_id = (int) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT id FROM {$wpdb->prefix}af_visit_bookings WHERE accommodation_id = %d AND guest_email = %s AND status IN ('confirmed','completed') ORDER BY id DESC LIMIT 1",
								$approved_acc_id,
								$selected_email
							)
						);

						$profile_result = $guest_service->send_guest_profile_link_for_booking(
							$approved_acc_id,
							$existing_booking_id,
							$approved_name,
							$selected_email,
							$approved_phone,
							'/completar-perfil-arriendo/',
							72
						);

						if ( ! empty( $profile_result['sent'] ) ) {
							$action_notice .= ' ' . __( 'Se envio el formulario de perfil legal al interesado.', 'arriendo-facil' );
						} else {
							$action_notice .= ' ' . __( 'Atencion: no se pudo enviar el formulario de perfil legal al interesado.', 'arriendo-facil' );
						}
					}
				}
			} elseif ( 'reject' === $queue_action ) {
				$updated_selected = $wpdb->update(
					$queue_table,
					array( 'status' => 'rejected' ),
					array( 'id' => $request_id ),
					array( '%s' ),
					array( '%d' )
				);

				if ( false === $updated_selected ) {
					$action_notice = __( 'No se pudo rechazar la solicitud.', 'arriendo-facil' );
					$action_notice_class = 'notice-error';
				} else {
					$action_notice = __( 'Solicitud rechazada correctamente.', 'arriendo-facil' );
				}
			} else {
				$action_notice = __( 'Accion no valida.', 'arriendo-facil' );
				$action_notice_class = 'notice-error';
			}
		}
	}
}

if ( $is_owner ) {
	// Incluye a los inquilinos que el propio usuario registró aún sin inmueble.
	Arriendo_Facil_Guest::ensure_guest_extra_columns();
	// Also guests that hold a lease on one of the manager's properties: the guest row is global and its
	// single accommodation_id may point elsewhere, which used to hide a tenant that shows up in Contratos.
	$guest_scope_sql = '';
	if ( $owner_ids ) {
		$owner_ids_sql   = implode( ',', array_map( 'intval', $owner_ids ) );
		$guest_scope_sql = "accommodation_id IN ({$owner_ids_sql}) OR id IN ( SELECT guest_id FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ({$owner_ids_sql}) AND deleted_at IS NULL AND guest_id IS NOT NULL ) OR ";
	}
	$guests = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}af_guests WHERE deleted_at IS NULL AND ( {$guest_scope_sql}created_by = %d ) ORDER BY created_at DESC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids casteados a int.
			get_current_user_id()
		)
	);
	if ( ! empty( $owner_ids ) ) {
		$ids_sql = implode( ',', array_map( 'intval', $owner_ids ) );
		$visit_requests = $wpdb->get_results(
			"SELECT q.id, q.accommodation_id, q.name, q.email, q.phone, q.message, q.status, q.created_at,
					p.post_title AS accommodation_title
			 FROM {$queue_table} q
			 LEFT JOIN {$posts_table} p ON p.ID = q.accommodation_id
			 WHERE q.accommodation_id IN ($ids_sql)
			 ORDER BY q.created_at DESC, q.id DESC
			 LIMIT 200"
		);
	} else {
		$visit_requests = array();
	}
} else {
	Arriendo_Facil_Guest::ensure_guest_extra_columns();
	$guests = $wpdb->get_results(
		"SELECT * FROM {$wpdb->prefix}af_guests WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 100"
	);
	$visit_requests = $wpdb->get_results(
		"SELECT q.id, q.accommodation_id, q.name, q.email, q.phone, q.message, q.status, q.created_at,
				p.post_title AS accommodation_title
		 FROM {$queue_table} q
		 LEFT JOIN {$posts_table} p ON p.ID = q.accommodation_id
		 ORDER BY q.created_at DESC, q.id DESC
		 LIMIT 200"
	);
}
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Personas', 'arriendo-facil' ),
			'title'    => __( 'Inquilinos', 'arriendo-facil' ),
			'subtitle' => __( 'Inquilinos bajo administración. Regístralos y verifica su identidad con los documentos necesarios.', 'arriendo-facil' ),
			'actions'  => array(
				current_user_can( Arriendo_Facil_Tenancy::CAP ) ? sprintf(
					'<button type="button" class="button af-btn af-btn--primary" id="af-new-guest"><span class="af-btn__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>%s</button>',
					esc_html__( 'Nuevo inquilino', 'arriendo-facil' )
				) : '',
			),
		)
	);
	?>

	<?php if ( '' !== $action_notice ) : ?>
		<div class="notice <?php echo esc_attr( $action_notice_class ); ?> is-dismissible">
			<p><?php echo esc_html( $action_notice ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	$af_can_prereg = current_user_can( Arriendo_Facil_Tenancy::CAP ) && class_exists( 'Arriendo_Facil_Tenant_Prereg' );
	if ( $af_can_prereg ) :
		$af_prereg_url  = Arriendo_Facil_Tenant_Prereg::url( get_current_user_id() );
		$af_portal_url  = Arriendo_Facil_Tenant_Access::portal_url();
		$af_leads       = Arriendo_Facil_Tenant_Prereg::leads_for_current_user( 60 );
		$af_lead_states = Arriendo_Facil_Tenant_Prereg::statuses();
		$af_lead_tone   = array( 'new' => 'info', 'invited' => 'neutral', 'completed' => 'attention', 'verified' => 'success', 'discarded' => 'neutral' );
		$af_lead_open   = 0;
		foreach ( $af_leads as $af_l ) {
			if ( in_array( $af_l->status, array( 'new', 'completed' ), true ) ) {
				$af_lead_open++;
			}
		}
		$af_my_properties = Arriendo_Facil_Tenant_Prereg::public_properties( get_current_user_id() );
		?>
		<details class="af-prereg" <?php echo $af_lead_open > 0 ? 'open' : ''; ?>>
			<summary class="af-prereg__head">
				<span class="af-prereg__titles">
					<strong><?php esc_html_e( 'Interesados y acceso de inquilinos', 'arriendo-facil' ); ?></strong>
					<small><?php esc_html_e( 'Comparte tu enlace, recibe interesados y envíales el formulario completo.', 'arriendo-facil' ); ?></small>
				</span>
				<?php if ( $af_lead_open > 0 ) : ?>
					<span class="af-pill af-pill--attention"><?php echo esc_html( sprintf( /* translators: %d: pending leads */ _n( '%d por atender', '%d por atender', $af_lead_open, 'arriendo-facil' ), $af_lead_open ) ); ?></span>
				<?php endif; ?>
			</summary>

			<div class="af-prereg__body">
				<div class="af-prereg__links">
					<div class="af-prereg__link">
						<label for="af-prereg-url"><?php esc_html_e( 'Tu enlace para interesados', 'arriendo-facil' ); ?></label>
						<div class="af-prereg__copy">
							<input type="text" id="af-prereg-url" value="<?php echo esc_attr( $af_prereg_url ); ?>" readonly />
							<button type="button" class="button af-btn af-btn--ghost af-btn--sm" data-af-copy="#af-prereg-url"><?php esc_html_e( 'Copiar', 'arriendo-facil' ); ?></button>
							<a class="button af-btn af-btn--ghost af-btn--sm" id="af-prereg-share" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( __( 'Déjame tus datos aquí y te escribo: ', 'arriendo-facil' ) . $af_prereg_url ) ); ?>"><?php esc_html_e( 'WhatsApp', 'arriendo-facil' ); ?></a>
						</div>
						<small><?php esc_html_e( 'Quien lo use queda vinculado a tu cuenta. Si lo compartiste donde no debías, genera uno nuevo:', 'arriendo-facil' ); ?> <button type="button" class="af-prereg__linkbtn" id="af-prereg-regenerate"><?php esc_html_e( 'regenerar enlace', 'arriendo-facil' ); ?></button></small>
					</div>
					<div class="af-prereg__link">
						<label for="af-portal-url"><?php esc_html_e( 'Portal del inquilino (acceso con código por correo)', 'arriendo-facil' ); ?></label>
						<div class="af-prereg__copy">
							<input type="text" id="af-portal-url" value="<?php echo esc_attr( $af_portal_url ); ?>" readonly />
							<button type="button" class="button af-btn af-btn--ghost af-btn--sm" data-af-copy="#af-portal-url"><?php esc_html_e( 'Copiar', 'arriendo-facil' ); ?></button>
						</div>
						<small><?php esc_html_e( 'Cada inquilino entra con su correo registrado. Desde su fila puedes enviarle su acceso personal.', 'arriendo-facil' ); ?></small>
					</div>
				</div>

				<?php if ( empty( $af_leads ) ) : ?>
					<p class="af-prereg__empty"><?php esc_html_e( 'Aún no hay interesados. Comparte tu enlace o el catálogo público: el botón "Me interesa" lleva aquí.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<ul class="af-lead-list">
						<?php foreach ( $af_leads as $af_lead ) : ?>
							<?php
							$af_lead_name = trim( $af_lead->first_name . ' ' . $af_lead->last_name );
							$af_closed    = in_array( $af_lead->status, array( 'discarded', 'verified' ), true );
							?>
							<li class="af-lead" data-lead-id="<?php echo esc_attr( $af_lead->id ); ?>">
								<div class="af-lead__main">
									<strong><?php echo esc_html( $af_lead_name ); ?></strong>
									<small><?php echo esc_html( $af_lead->email . ' · ' . $af_lead->phone ); ?></small>
									<small><?php echo esc_html( ( $af_lead->property ? $af_lead->property : __( 'Sin inmueble elegido', 'arriendo-facil' ) ) . ' · ' . af_time_ago( strtotime( $af_lead->created_at . ' UTC' ) ) ); ?></small>
									<?php if ( $af_lead->note ) : ?>
										<small class="af-lead__note">“<?php echo esc_html( $af_lead->note ); ?>”</small>
									<?php endif; ?>
								</div>
								<span class="af-pill af-pill--<?php echo esc_attr( $af_lead_tone[ $af_lead->status ] ); ?>"><?php echo esc_html( $af_lead_states[ $af_lead->status ] ); ?></span>
								<?php if ( ! $af_closed ) : ?>
									<div class="af-lead__actions">
										<?php if ( ! $af_lead->accommodation_id && $af_my_properties ) : ?>
											<select class="af-lead__prop" aria-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>">
												<option value=""><?php esc_html_e( 'Inmueble…', 'arriendo-facil' ); ?></option>
												<?php foreach ( $af_my_properties as $af_pid => $af_ptitle ) : ?>
													<option value="<?php echo esc_attr( $af_pid ); ?>"><?php echo esc_html( $af_ptitle ); ?></option>
												<?php endforeach; ?>
											</select>
										<?php endif; ?>
										<?php if ( 'completed' !== $af_lead->status ) : ?>
											<button type="button" class="button af-btn af-btn--primary af-btn--sm" data-lead-act="invite" data-channel="whatsapp"><?php echo esc_html( 'invited' === $af_lead->status ? __( 'Reenviar por WhatsApp', 'arriendo-facil' ) : __( 'Enviar formulario por WhatsApp', 'arriendo-facil' ) ); ?></button>
											<button type="button" class="button af-btn af-btn--ghost af-btn--sm" data-lead-act="invite" data-channel="email"><?php esc_html_e( 'Por correo', 'arriendo-facil' ); ?></button>
										<?php else : ?>
											<a class="button af-btn af-btn--primary af-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=af-guests&view=profile&guest_id=' . (int) $af_lead->guest_id ) ); ?>"><?php esc_html_e( 'Revisar datos', 'arriendo-facil' ); ?></a>
											<button type="button" class="button af-btn af-btn--ghost af-btn--sm" data-lead-act="status" data-status="verified"><?php esc_html_e( 'Marcar verificado', 'arriendo-facil' ); ?></button>
										<?php endif; ?>
										<button type="button" class="button af-btn af-btn--ghost af-btn--sm" data-lead-act="status" data-status="discarded"><?php esc_html_e( 'Descartar', 'arriendo-facil' ); ?></button>
									</div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</details>
	<?php endif; ?>

	<div id="af-guest-form-card" class="card" style="max-width: 900px; margin: 16px 0; padding: 16px; display: none;">
		<h2><?php esc_html_e( 'Nuevo inquilino', 'arriendo-facil' ); ?></h2>
		<p class="af-modal__hint" style="margin:0 0 16px;">
			<?php esc_html_e( 'Registra al inquilino desde el panel. Puedes subir sus documentos ahora (copia de cédula y un comprobante de ingresos) para agilizar la verificación de identidad.', 'arriendo-facil' ); ?>
		</p>
		<form id="af-guest-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="af_create_guest" />
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_guest_nonce' ) ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="af_guest_name"><?php esc_html_e( 'Nombre completo', 'arriendo-facil' ); ?> *</label></th>
					<td>
						<input type="text" required id="af_guest_name" name="name" class="regular-text" placeholder="<?php esc_attr_e( 'Nombres y apellidos', 'arriendo-facil' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_id_type"><?php esc_html_e( 'Tipo de identificación', 'arriendo-facil' ); ?> *</label></th>
					<td>
						<select id="af_guest_id_type" name="id_type" required>
							<option value="cedula"><?php esc_html_e( 'Cédula', 'arriendo-facil' ); ?></option>
							<option value="ruc"><?php esc_html_e( 'RUC', 'arriendo-facil' ); ?></option>
							<option value="pasaporte"><?php esc_html_e( 'Pasaporte', 'arriendo-facil' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_id_number"><?php esc_html_e( 'Número de identificación', 'arriendo-facil' ); ?> *</label></th>
					<td>
						<input type="text" required id="af_guest_id_number" name="id_number" class="regular-text" maxlength="13" />
						<p class="description" id="af_guest_id_hint"><?php esc_html_e( 'Solo números: 10 dígitos para cédula.', 'arriendo-facil' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_email"><?php esc_html_e( 'Email', 'arriendo-facil' ); ?> *</label></th>
					<td>
						<input type="email" required id="af_guest_email" name="email" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Sirve para identificar al inquilino dentro del historial y de los contratos.', 'arriendo-facil' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_phone"><?php esc_html_e( 'Teléfono', 'arriendo-facil' ); ?> *</label></th>
					<td>
						<input type="text" required id="af_guest_phone" name="phone" class="regular-text" inputmode="numeric" pattern="^[0-9]{10}$" maxlength="10" title="<?php esc_attr_e( 'Solo números (10 dígitos)', 'arriendo-facil' ); ?>" />
					</td>
				</tr>
			</table>

			<h3 style="margin: 16px 0 8px;"><?php esc_html_e( 'Vincular a un inmueble', 'arriendo-facil' ); ?></h3>
			<p class="af-modal__hint" style="margin: 0 0 8px;">
				<?php esc_html_e( 'Opcional. Puedes vincular al inquilino a un inmueble aquí o más adelante al crear el contrato desde la sección Contratos.', 'arriendo-facil' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="af_guest_accommodation_id"><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></label></th>
					<td>
						<select id="af_guest_accommodation_id" name="accommodation_id" class="regular-text">
							<option value=""><?php esc_html_e( '— Sin vincular por ahora —', 'arriendo-facil' ); ?></option>
							<?php
							$guest_form_accommodation_args = array(
								'post_type'      => 'accommodation',
								'posts_per_page' => 300,
								'orderby'        => 'title',
								'order'          => 'ASC',
								'post_status'    => array( 'publish', 'draft', 'private' ),
							);
							if ( $is_owner ) {
								$guest_form_accommodation_args['post__in'] = $owner_ids ? $owner_ids : array( 0 );
							}
							$guest_form_accommodations = get_posts( $guest_form_accommodation_args );
							foreach ( $guest_form_accommodations as $guest_form_accommodation ) :
								?>
								<option value="<?php echo esc_attr( (int) $guest_form_accommodation->ID ); ?>"
									data-rent="<?php echo esc_attr( (string) get_post_meta( $guest_form_accommodation->ID, '_af_monthly_rent', true ) ); ?>">
									<?php echo esc_html( $guest_form_accommodation->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Al elegir el inmueble se completa el canon y se piden las fechas de vigencia, igual que en Contratos.', 'arriendo-facil' ); ?></p>
					</td>
				</tr>
				<tr class="af-guest-link-field" hidden>
					<th scope="row"><label for="af_guest_rental_start_date"><?php esc_html_e( 'Inicio del arriendo', 'arriendo-facil' ); ?> *</label></th>
					<td><input type="date" id="af_guest_rental_start_date" name="rental_start_date" /></td>
				</tr>
				<tr class="af-guest-link-field" hidden>
					<th scope="row"><label for="af_guest_rental_end_date"><?php esc_html_e( 'Fin del arriendo', 'arriendo-facil' ); ?> *</label></th>
					<td><input type="date" id="af_guest_rental_end_date" name="rental_end_date" /></td>
				</tr>
				<tr class="af-guest-link-field" hidden>
					<th scope="row"><label for="af_guest_desired_price"><?php esc_html_e( 'Canon mensual (USD)', 'arriendo-facil' ); ?></label></th>
					<td><input type="number" id="af_guest_desired_price" name="desired_price" step="0.01" min="0" placeholder="0.00" /></td>
				</tr>
				<tr class="af-guest-link-field" hidden>
					<th scope="row"><label for="af_guest_guarantee_text"><?php esc_html_e( 'Garantía (USD)', 'arriendo-facil' ); ?></label></th>
					<td><input type="number" id="af_guest_guarantee_text" name="guarantee_text" step="0.01" min="0" placeholder="0.00" /></td>
				</tr>
			</table>

			<h3 style="margin: 16px 0 8px;"><?php esc_html_e( 'Identidad', 'arriendo-facil' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="af_guest_nationality"><?php esc_html_e( 'Nacionalidad', 'arriendo-facil' ); ?> *</label></th>
					<td>
						<select id="af_guest_nationality" name="nationality" class="regular-text" required>
							<option value=""><?php esc_html_e( '— Seleccionar —', 'arriendo-facil' ); ?></option>
							<?php foreach ( Arriendo_Facil_Guest::get_nationality_options() as $nat_value => $nat_label ) : ?>
								<option value="<?php echo esc_attr( $nat_value ); ?>"><?php echo esc_html( $nat_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_birth_city"><?php esc_html_e( 'Ciudad de nacimiento', 'arriendo-facil' ); ?></label></th>
					<td><input type="text" id="af_guest_birth_city" name="birth_city" class="regular-text" maxlength="150" placeholder="<?php esc_attr_e( 'Ej: Quito', 'arriendo-facil' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_income_range"><?php esc_html_e( 'Rango de ingresos mensuales', 'arriendo-facil' ); ?> *</label></th>
					<td>
						<select id="af_guest_income_range" name="income_range" class="regular-text" required>
							<option value=""><?php esc_html_e( '— Seleccionar —', 'arriendo-facil' ); ?></option>
							<?php foreach ( Arriendo_Facil_Guest::get_income_range_options() as $inc_value => $inc_label ) : ?>
								<option value="<?php echo esc_attr( $inc_value ); ?>"><?php echo esc_html( $inc_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Se contrasta con el comprobante de ingresos para evaluar la capacidad de pago.', 'arriendo-facil' ); ?></p>
					</td>
				</tr>
			</table>

			<h3 style="margin: 16px 0 8px;"><?php esc_html_e( 'Identidad e ingresos', 'arriendo-facil' ); ?></h3>
			<p class="af-modal__hint" style="margin: 0 0 8px;">
				<?php esc_html_e( 'Solo pedimos lo mínimo para verificar la identidad del inquilino (evitar suplantaciones) y confirmar que puede sostener el arriendo: copia de cédula y un comprobante de ingresos. Todo en PDF.', 'arriendo-facil' ); ?>
			</p>
			<?php
			$af_doc_card = static function ( $id, $name, $title_html, $badge, $badge_class, $hint, $extra = '', $required = false ) {
				?>
				<div class="af-doc <?php echo esc_attr( $extra ); ?>" data-af-doc <?php echo false !== strpos( $extra, 'af-guest-foreign-field' ) ? 'hidden' : ''; ?>>
					<div class="af-doc__zone">
						<input type="file" class="af-doc__input" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" accept="application/pdf,.pdf" <?php echo $required ? 'required' : ''; ?> aria-describedby="<?php echo esc_attr( $id ); ?>_hint" />
						<span class="af-doc__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M12 17v-6m0 0-2.5 2.5M12 11l2.5 2.5"/></svg>
						</span>
						<span class="af-doc__body">
							<span class="af-doc__title"><?php echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by caller. ?></span>
							<span class="af-doc__badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge ); ?></span>
							<span class="af-doc__hint" id="<?php echo esc_attr( $id ); ?>_hint"><?php echo esc_html( $hint ); ?></span>
						</span>
						<span class="af-doc__check" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
						</span>
					</div>
					<div class="af-doc__file" hidden>
						<span class="af-doc__file-name"></span>
						<span class="af-doc__file-size"></span>
						<button type="button" class="af-doc__remove"><?php esc_html_e( 'Quitar', 'arriendo-facil' ); ?></button>
					</div>
				</div>
				<?php
			};
			?>
			<div class="af-docs" data-af-docs>
				<?php
				$af_doc_card(
					'af_guest_cedula_papeleta_pdf',
					'guest_cedula_papeleta_pdf',
					'<span id="af_guest_cedula_label" data-cedula="' . esc_attr__( 'Copia de cédula', 'arriendo-facil' ) . '" data-pasaporte="' . esc_attr__( 'Copia de pasaporte', 'arriendo-facil' ) . '">' . esc_html__( 'Copia de cédula', 'arriendo-facil' ) . '</span>',
					__( 'Obligatorio', 'arriendo-facil' ),
					'is-required',
					__( 'Arrastra el PDF aquí o toca para elegirlo. Máx. 10 MB.', 'arriendo-facil' ),
					'',
					true
				);
				$af_doc_card(
					'af_guest_antecedentes_penales_pdf',
					'guest_antecedentes_penales_pdf',
					esc_html__( 'Antecedentes penales', 'arriendo-facil' ),
					__( 'Solo extranjeros', 'arriendo-facil' ),
					'is-info',
					__( 'Certificado vigente en PDF. Máx. 10 MB.', 'arriendo-facil' ),
					'af-guest-foreign-field'
				);
				?>
				<fieldset class="af-docs__group" data-af-doc-group>
					<legend class="af-docs__legend">
						<?php esc_html_e( 'Comprobante de ingresos', 'arriendo-facil' ); ?>
						<span class="af-docs__legend-note"><?php esc_html_e( 'Sube al menos uno: confirma que puede sostener el arriendo.', 'arriendo-facil' ); ?></span>
					</legend>
					<div class="af-docs__group-grid">
						<?php
						$af_doc_card(
							'af_guest_certificado_laboral_pdf',
							'guest_certificado_laboral_pdf',
							esc_html__( 'Certificado laboral', 'arriendo-facil' ),
							__( 'Uno de los dos', 'arriendo-facil' ),
							'is-required',
							__( 'PDF emitido por su empleador. Máx. 10 MB.', 'arriendo-facil' ),
							'af-guest-income-proof'
						);
						$af_doc_card(
							'af_guest_certificado_bancario_pdf',
							'guest_certificado_bancario_pdf',
							esc_html__( 'Certificado bancario', 'arriendo-facil' ),
							__( 'Uno de los dos', 'arriendo-facil' ),
							'is-required',
							__( 'PDF emitido por su banco. Máx. 10 MB.', 'arriendo-facil' ),
							'af-guest-income-proof'
						);
						?>
					</div>
				</fieldset>
			</div>

			<?php Arriendo_Facil_Consent::render( 'tenant_record' ); ?>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar inquilino', 'arriendo-facil' ); ?></button>
				<button type="button" class="button" id="af-cancel-new-guest"><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			</p>
		</form>
	</div>

	<?php
	$visit_count      = is_array( $visit_requests ) ? count( $visit_requests ) : 0;
	$guest_count_page = is_array( $guests ) ? count( $guests ) : 0;
	$pending_visits   = 0;
	if ( $visit_count > 0 ) {
		foreach ( $visit_requests as $r ) {
			$s = isset( $r->status ) ? sanitize_key( (string) $r->status ) : 'queued';
			if ( in_array( $s, array( 'queued', 'notified', 'visit_requested' ), true ) ) {
				$pending_visits++;
			}
		}
	}
	?>

	<div class="af-tabs af-guest-tabs">
		<?php $show_visits_tab = defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES; ?>
		<?php if ( $show_visits_tab ) : ?>
			<input type="radio" name="af-guest-tab" id="af-tab-visits" class="af-tabs__radio" <?php echo ( $pending_visits > 0 || 0 === $guest_count_page ) ? 'checked' : ''; ?> />
		<?php endif; ?>
		<input type="radio" name="af-guest-tab" id="af-tab-guests" class="af-tabs__radio" <?php echo ( ! $show_visits_tab || ( 0 === $pending_visits && $guest_count_page > 0 ) ) ? 'checked' : ''; ?> />

		<div class="af-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'Vista de inquilinos', 'arriendo-facil' ); ?>">
			<?php if ( $show_visits_tab ) : ?>
				<label for="af-tab-visits" class="af-tabs__tab af-tabs__tab--visits" role="tab">
					<span class="af-tabs__icon" aria-hidden="true">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2v4M16 2v4M3 10h18M5 6h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</span>
					<span class="af-tabs__label"><?php esc_html_e( 'Solicitudes de visita', 'arriendo-facil' ); ?></span>
					<?php if ( $pending_visits > 0 ) : ?>
						<span class="af-tabs__badge af-tabs__badge--attention"><?php echo esc_html( number_format_i18n( $pending_visits ) ); ?></span>
					<?php elseif ( $visit_count > 0 ) : ?>
						<span class="af-tabs__badge"><?php echo esc_html( number_format_i18n( $visit_count ) ); ?></span>
					<?php endif; ?>
				</label>
			<?php endif; ?>

			<label for="af-tab-guests" class="af-tabs__tab af-tabs__tab--guests" role="tab">
				<span class="af-tabs__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</span>
				<span class="af-tabs__label"><?php esc_html_e( 'Inquilinos registrados', 'arriendo-facil' ); ?></span>
				<?php if ( $guest_count_page > 0 ) : ?>
					<span class="af-tabs__badge"><?php echo esc_html( number_format_i18n( $guest_count_page ) ); ?></span>
				<?php endif; ?>
			</label>
		</div>

		<div class="af-tabs__panels">

			<?php if ( $show_visits_tab ) : ?>
			<section class="af-tabs__panel af-tabs__panel--visits" role="tabpanel" aria-labelledby="af-tab-visits">
				<div class="af-section af-section--visits">
					<header class="af-section__head">
						<div>
							<h2 class="af-section__title"><?php esc_html_e( 'Cola de solicitudes de visita', 'arriendo-facil' ); ?></h2>
							<p class="af-section__subtitle"><?php esc_html_e( 'Interesados que solicitaron ver la propiedad. Aprueba o rechaza; al aprobar una solicitud las demás activas para la misma propiedad se rechazan automáticamente.', 'arriendo-facil' ); ?></p>
						</div>
						<?php if ( $pending_visits > 0 ) : ?>
							<span class="af-pill af-pill--warning"><?php echo esc_html( sprintf( _n( '%s pendiente', '%s pendientes', $pending_visits, 'arriendo-facil' ), number_format_i18n( $pending_visits ) ) ); ?></span>
						<?php endif; ?>
					</header>

					<?php if ( ! empty( $visit_requests ) ) : ?>
					<table class="wp-list-table widefat af-data-table af-data-table--visits">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Alojamiento', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Interesado', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Contacto', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Mensaje', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $visit_requests as $request ) : ?>
								<?php
								$status         = isset( $request->status ) ? sanitize_key( (string) $request->status ) : 'queued';
								$is_actionable  = in_array( $status, array( 'queued', 'notified', 'visit_requested' ), true );
								$status_label   = $status;
								$status_variant = 'default';
								if ( 'visit_requested' === $status ) {
									$status_label   = __( 'Visita solicitada', 'arriendo-facil' );
									$status_variant = 'info';
								} elseif ( 'queued' === $status ) {
									$status_label   = __( 'En cola', 'arriendo-facil' );
									$status_variant = 'warning';
								} elseif ( 'notified' === $status ) {
									$status_label   = __( 'Notificado', 'arriendo-facil' );
									$status_variant = 'info';
								} elseif ( 'approved' === $status ) {
									$status_label   = __( 'Aprobado', 'arriendo-facil' );
									$status_variant = 'success';
								} elseif ( 'rejected' === $status ) {
									$status_label   = __( 'Rechazado', 'arriendo-facil' );
									$status_variant = 'danger';
								}
								?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Fecha', 'arriendo-facil' ); ?>"><?php echo isset( $request->created_at ) ? esc_html( (string) $request->created_at ) : '—'; ?></td>
									<td data-label="<?php esc_attr_e( 'Alojamiento', 'arriendo-facil' ); ?>">
										<strong><?php echo ! empty( $request->accommodation_title ) ? esc_html( (string) $request->accommodation_title ) : esc_html__( '(Sin título)', 'arriendo-facil' ); ?></strong>
										<div class="af-td-meta">#<?php echo esc_html( (string) absint( $request->accommodation_id ) ); ?></div>
									</td>
									<td data-label="<?php esc_attr_e( 'Interesado', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $request->name ) ? (string) $request->name : '—' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Contacto', 'arriendo-facil' ); ?>">
										<div class="af-td-meta"><?php echo esc_html( isset( $request->email ) ? (string) $request->email : '' ); ?></div>
										<div class="af-td-meta"><?php echo esc_html( isset( $request->phone ) ? (string) $request->phone : '' ); ?></div>
									</td>
									<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>">
										<span class="af-pill af-pill--<?php echo esc_attr( $status_variant ); ?>"><?php echo esc_html( (string) $status_label ); ?></span>
									</td>
									<td data-label="<?php esc_attr_e( 'Mensaje', 'arriendo-facil' ); ?>" class="af-td-truncate"><?php echo ! empty( $request->message ) ? esc_html( (string) $request->message ) : '—'; ?></td>
									<td class="af-td-actions" data-label="<?php esc_attr_e( 'Acciones', 'arriendo-facil' ); ?>">
										<?php if ( $is_actionable ) : ?>
											<form method="post" class="af-inline-form">
												<input type="hidden" name="af_queue_action" value="approve" />
												<input type="hidden" name="af_queue_request_id" value="<?php echo esc_attr( (string) absint( $request->id ) ); ?>" />
												<input type="hidden" name="af_queue_nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_queue_action' ) ); ?>" />
												<button type="submit" class="button af-btn af-btn--primary af-btn--sm" onclick="return confirm('<?php echo esc_js( __( 'Al aprobar esta solicitud, las otras solicitudes activas para la misma acomodacion se rechazaran automaticamente. Continuar?', 'arriendo-facil' ) ); ?>');">
													<?php esc_html_e( 'Aprobar', 'arriendo-facil' ); ?>
												</button>
											</form>
											<form method="post" class="af-inline-form">
												<input type="hidden" name="af_queue_action" value="reject" />
												<input type="hidden" name="af_queue_request_id" value="<?php echo esc_attr( (string) absint( $request->id ) ); ?>" />
												<input type="hidden" name="af_queue_nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_queue_action' ) ); ?>" />
												<button type="submit" class="button af-btn af-btn--ghost af-btn--sm" onclick="return confirm('<?php echo esc_js( __( 'Confirmas rechazar esta solicitud?', 'arriendo-facil' ) ); ?>');">
													<?php esc_html_e( 'Rechazar', 'arriendo-facil' ); ?>
												</button>
											</form>
										<?php else : ?>
											<span class="af-td-meta">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php else : ?>
						<div class="af-empty">
							<div class="af-empty__icon" aria-hidden="true">
								<svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2v4M16 2v4M3 10h18M5 6h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
							</div>
							<h3><?php esc_html_e( 'No hay solicitudes de visita', 'arriendo-facil' ); ?></h3>
							<p><?php esc_html_e( 'Cuando alguien pida ver una de tus propiedades desde la web pública, aparecerá aquí para que la apruebes.', 'arriendo-facil' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</section>
			<?php endif; ?>

			<section class="af-tabs__panel af-tabs__panel--guests" role="tabpanel" aria-labelledby="af-tab-guests">
				<div class="af-section af-section--guests">
					<header class="af-section__head">
						<div>
							<h2 class="af-section__title"><?php esc_html_e( 'Inquilinos registrados', 'arriendo-facil' ); ?></h2>
							<p class="af-section__subtitle"><?php esc_html_e( 'Perfiles de inquilinos bajo administración. Verifica los documentos de identidad antes de operar el contrato.', 'arriendo-facil' ); ?></p>
						</div>
						<?php if ( $guest_count_page > 0 ) : ?>
							<span class="af-pill af-pill--info"><?php echo esc_html( sprintf( _n( '%s inquilino', '%s inquilinos', $guest_count_page, 'arriendo-facil' ), number_format_i18n( $guest_count_page ) ) ); ?></span>
						<?php endif; ?>
					</header>

					<?php if ( $guests ) : ?>
					<?php
					$guest_tab_counts = array( 'pendiente' => 0, 'verificado' => 0, 'rechazado' => 0 );
					foreach ( $guests as $gt_guest ) {
						$gt_status = isset( $gt_guest->doc_status ) && $gt_guest->doc_status ? (string) $gt_guest->doc_status : 'pendiente';
						if ( isset( $guest_tab_counts[ $gt_status ] ) ) {
							$guest_tab_counts[ $gt_status ]++;
						}
					}
					?>
					<div class="af-listfilter">
						<label class="screen-reader-text" for="af-guests-filter"><?php esc_html_e( 'Buscar inquilino', 'arriendo-facil' ); ?></label>
						<input type="search" id="af-guests-filter" data-af-listfilter="#af-guests-tbody tr" data-af-empty="<?php esc_attr_e( 'Ningún inquilino coincide con tu búsqueda.', 'arriendo-facil' ); ?>" placeholder="<?php esc_attr_e( 'Buscar por nombre, cédula, correo o teléfono…', 'arriendo-facil' ); ?>" autocomplete="off" />
					</div>
					<div class="af-status-tabs" data-tabs-target="#af-guests-tbody">
						<button type="button" class="af-status-tabs__btn is-active" data-tab-value=""><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?> <span class="af-status-tabs__count"><?php echo esc_html( number_format_i18n( count( $guests ) ) ); ?></span></button>
						<button type="button" class="af-status-tabs__btn" data-tab-value="pendiente"><?php esc_html_e( 'Pendientes', 'arriendo-facil' ); ?> <span class="af-status-tabs__count"><?php echo esc_html( number_format_i18n( $guest_tab_counts['pendiente'] ) ); ?></span></button>
						<button type="button" class="af-status-tabs__btn" data-tab-value="verificado"><?php esc_html_e( 'Verificados', 'arriendo-facil' ); ?> <span class="af-status-tabs__count"><?php echo esc_html( number_format_i18n( $guest_tab_counts['verificado'] ) ); ?></span></button>
						<button type="button" class="af-status-tabs__btn" data-tab-value="rechazado"><?php esc_html_e( 'Rechazados', 'arriendo-facil' ); ?> <span class="af-status-tabs__count"><?php echo esc_html( number_format_i18n( $guest_tab_counts['rechazado'] ) ); ?></span></button>
					</div>
					<table class="wp-list-table widefat af-data-table af-data-table--guests">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Identificación', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Email', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Teléfono', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Documentos', 'arriendo-facil' ); ?></th>
								<?php if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) : ?>
									<th><?php esc_html_e( 'AI Score', 'arriendo-facil' ); ?></th>
								<?php endif; ?>
								<th><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
							</tr>
						</thead>
						<tbody id="af-guests-tbody">
							<?php
							$doc_statuses = class_exists( 'Arriendo_Facil_Document_Verification' )
								? Arriendo_Facil_Document_Verification::statuses()
								: array();
							foreach ( $guests as $guest ) :
								$score = $guest->ai_score ? (float) $guest->ai_score : null;
								$score_variant = 'default';
								if ( null !== $score ) {
									if ( $score >= 0.75 ) { $score_variant = 'success'; }
									elseif ( $score >= 0.5 ) { $score_variant = 'warning'; }
									else { $score_variant = 'danger'; }
								}

								$doc_status = isset( $guest->doc_status ) && $guest->doc_status ? (string) $guest->doc_status : 'pendiente';
								$doc_variant = 'warning';
								if ( 'verificado' === $doc_status ) {
									$doc_variant = 'success';
								} elseif ( 'rechazado' === $doc_status ) {
									$doc_variant = 'danger';
								}
							?>
								<tr data-tab-group="<?php echo esc_attr( $doc_status ); ?>">
									<td data-label="<?php esc_attr_e( 'Identificación', 'arriendo-facil' ); ?>"><?php echo esc_html( $guest->id_number ); ?></td>
									<td data-label="<?php esc_attr_e( 'Nombre', 'arriendo-facil' ); ?>">
										<strong><?php echo esc_html( $guest->first_name . ' ' . $guest->last_name ); ?></strong>
									</td>
									<td data-label="<?php esc_attr_e( 'Email', 'arriendo-facil' ); ?>"><?php echo esc_html( $guest->email ); ?></td>
									<td data-label="<?php esc_attr_e( 'Teléfono', 'arriendo-facil' ); ?>"><?php echo esc_html( $guest->phone ); ?></td>
									<td data-label="<?php esc_attr_e( 'Documentos', 'arriendo-facil' ); ?>">
										<span class="af-pill af-pill--<?php echo esc_attr( $doc_variant ); ?>">
											<?php echo esc_html( $doc_statuses[ $doc_status ] ?? $doc_status ); ?>
										</span>
										<?php
										$identity_status = isset( $guest->identity_match_status ) ? (string) $guest->identity_match_status : 'not_checked';
										if ( 'match' === $identity_status ) :
											?>
											<span class="af-td-meta" title="<?php esc_attr_e( 'La cédula declarada aparece en el texto del documento subido. Señal automática de apoyo, no reemplaza la revisión humana.', 'arriendo-facil' ); ?>">✓ <?php esc_html_e( 'coincide con el documento', 'arriendo-facil' ); ?></span>
										<?php elseif ( 'no_match' === $identity_status ) : ?>
											<span class="af-td-meta" style="color:#b03a36;" title="<?php esc_attr_e( 'La cédula declarada NO aparece en el texto del documento subido. Revisa manualmente antes de aprobar.', 'arriendo-facil' ); ?>">⚠ <?php esc_html_e( 'no coincide, revisar', 'arriendo-facil' ); ?></span>
										<?php endif; ?>
										<?php if ( current_user_can( Arriendo_Facil_Tenancy::CAP ) && class_exists( 'Arriendo_Facil_Document_Verification' ) ) : ?>
											<?php $guest_docs = Arriendo_Facil_Document_Verification::get_guest_documents( $guest->id ); ?>
											<?php if ( ! empty( $guest_docs ) ) : ?>
												<div class="af-guest-docs">
													<?php foreach ( $guest_docs as $guest_doc ) : ?>
														<button type="button" class="af-guest-doc-view" data-document="<?php echo esc_attr( (int) $guest_doc->id ); ?>">
															<?php echo esc_html( ucfirst( str_replace( '_', ' ', $guest_doc->doc_type ) ) ); ?>
														</button>
													<?php endforeach; ?>
												</div>
											<?php endif; ?>
										<?php endif; ?>
									</td>
									<?php if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) : ?>
										<td data-label="<?php esc_attr_e( 'AI Score', 'arriendo-facil' ); ?>">
											<?php if ( null !== $score ) : ?>
												<span class="af-pill af-pill--<?php echo esc_attr( $score_variant ); ?>"><?php echo esc_html( number_format( $score, 2 ) ); ?></span>
											<?php else : ?>
												<span class="af-td-meta">—</span>
											<?php endif; ?>
										</td>
									<?php endif; ?>
									<td class="af-td-actions" data-label="<?php esc_attr_e( 'Acciones', 'arriendo-facil' ); ?>">
										<a class="button af-btn af-btn--ghost af-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=af-guests&view=profile&guest_id=' . (int) $guest->id ) ); ?>">
											<?php esc_html_e( 'Ver ficha', 'arriendo-facil' ); ?>
										</a>
										<?php if ( current_user_can( Arriendo_Facil_Tenancy::CAP ) ) : ?>
											<button type="button" class="button af-btn af-btn--ghost af-btn--sm af-verify-docs"
												data-guest-id="<?php echo esc_attr( $guest->id ); ?>"
												data-guest-name="<?php echo esc_attr( trim( $guest->first_name . ' ' . $guest->last_name ) ); ?>"
												data-current="<?php echo esc_attr( $doc_status ); ?>">
												<?php esc_html_e( 'Verificar documentos', 'arriendo-facil' ); ?>
											</button>
										<?php else : ?>
											<span class="af-td-meta">—</span>
										<?php endif; ?>
										<?php if ( current_user_can( Arriendo_Facil_Tenancy::CAP ) && is_email( $guest->email ) ) : ?>
											<button type="button" class="button af-btn af-btn--ghost af-btn--sm af-portal-access"
												data-guest-id="<?php echo esc_attr( $guest->id ); ?>"
												data-guest-name="<?php echo esc_attr( trim( $guest->first_name . ' ' . $guest->last_name ) ); ?>">
												<?php esc_html_e( 'Enviar acceso', 'arriendo-facil' ); ?>
											</button>
										<?php endif; ?>
										<?php if ( current_user_can( Arriendo_Facil_Tenancy::CAP ) ) : ?>
											<button type="button" class="button af-btn af-btn--ghost af-btn--sm af-delete-guest"
												data-guest-id="<?php echo esc_attr( $guest->id ); ?>"
												data-guest-name="<?php echo esc_attr( trim( $guest->first_name . ' ' . $guest->last_name ) ); ?>">
												<?php esc_html_e( 'Eliminar', 'arriendo-facil' ); ?>
											</button>
										<?php endif; ?>
										<?php if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) : ?>
											<button type="button" class="button af-btn af-btn--ghost af-btn--sm af-score-guest"
												data-guest-id="<?php echo esc_attr( $guest->id ); ?>">
												<?php esc_html_e( 'Score (IA)', 'arriendo-facil' ); ?>
											</button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php else : ?>
						<div class="af-empty">
							<div class="af-empty__icon" aria-hidden="true">
								<svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
							</div>
							<h3><?php esc_html_e( 'Sin inquilinos registrados aún', 'arriendo-facil' ); ?></h3>
							<p><?php esc_html_e( 'Usa el botón de alta para registrar el primer inquilino bajo administración.', 'arriendo-facil' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</section>

		</div>
	</div>
</div>

<!-- Modal: verificación de documentos -->
<div class="af-modal" id="af-modal-verify" role="dialog" aria-modal="true" aria-labelledby="af-modal-verify-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-verify-title"><?php esc_html_e( 'Verificar documentos', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-verify-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-verify-status"></p>
			<div class="af-modal__field">
				<label><?php esc_html_e( 'Resultado de la revisión', 'arriendo-facil' ); ?></label>
				<div class="af-choice-group">
					<label class="af-choice" data-value="pendiente">
						<input type="radio" name="af_doc_status" value="pendiente" />
						<span>
							<span class="af-choice__label"><?php esc_html_e( 'Pendiente', 'arriendo-facil' ); ?></span>
							<span class="af-choice__desc"><?php esc_html_e( 'Aún no se revisan los documentos.', 'arriendo-facil' ); ?></span>
						</span>
					</label>
					<label class="af-choice" data-value="verificado">
						<input type="radio" name="af_doc_status" value="verificado" />
						<span>
							<span class="af-choice__label"><?php esc_html_e( 'Verificado', 'arriendo-facil' ); ?></span>
							<span class="af-choice__desc"><?php esc_html_e( 'Identidad confirmada. Puede operarse el contrato.', 'arriendo-facil' ); ?></span>
						</span>
					</label>
					<label class="af-choice" data-value="rechazado">
						<input type="radio" name="af_doc_status" value="rechazado" />
						<span>
							<span class="af-choice__label"><?php esc_html_e( 'Rechazado', 'arriendo-facil' ); ?></span>
							<span class="af-choice__desc"><?php esc_html_e( 'Documentos ilegibles, incompletos o inconsistentes.', 'arriendo-facil' ); ?></span>
						</span>
					</label>
				</div>
			</div>
			<div class="af-modal__field">
				<label for="af-verify-notes"><?php esc_html_e( 'Notas de revisión', 'arriendo-facil' ); ?></label>
				<textarea id="af-verify-notes" rows="3" placeholder="<?php esc_attr_e( 'Ej: cédula legible, certificado bancario vigente.', 'arriendo-facil' ); ?>"></textarea>
				<p class="af-modal__hint"><?php esc_html_e( 'Queda registrado junto con tu usuario y la fecha.', 'arriendo-facil' ); ?></p>
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-verify-confirm"><?php esc_html_e( 'Guardar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const docNonce = <?php echo wp_json_encode( wp_create_nonce( 'af_document_nonce' ) ); ?>;
	const guestEditNonce = <?php echo wp_json_encode( wp_create_nonce( 'af_guest_edit_nonce' ) ); ?>;

	function post(payload) {
		const body = new URLSearchParams();
		Object.keys(payload).forEach((k) => body.append(k, payload[k]));
		return fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then((r) => r.json());
	}

	function setStatus(el, message, type) {
		el.textContent = message;
		el.className = 'af-modal__status is-' + type;
	}

	function clearStatus(el) {
		el.textContent = '';
		el.className = 'af-modal__status';
	}

	function openModal(modal) {
		modal.classList.add('is-open');
		const focusable = modal.querySelector('select, textarea, input, button.button-primary');
		if (focusable) { focusable.focus(); }
	}

	function closeModal(modal) {
		modal.classList.remove('is-open');
	}

	document.querySelectorAll('.af-modal').forEach(function (modal) {
		modal.querySelectorAll('[data-af-modal-close]').forEach(function (btn) {
			btn.addEventListener('click', function () { closeModal(modal); });
		});
	});

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		document.querySelectorAll('.af-modal.is-open').forEach(closeModal);
	});

	// ---- Verificación de documentos ----
	const verifyModal = document.getElementById('af-modal-verify');
	const verifyStatus = document.getElementById('af-verify-status');
	const verifySubtitle = document.getElementById('af-verify-subtitle');
	const verifyNotes = document.getElementById('af-verify-notes');
	const verifyConfirm = document.getElementById('af-verify-confirm');
	let verifyGuestId = 0;

	function selectChoice(value) {
		verifyModal.querySelectorAll('.af-choice').forEach(function (choice) {
			const isMatch = choice.getAttribute('data-value') === value;
			choice.classList.toggle('is-selected', isMatch);
			choice.querySelector('input[type="radio"]').checked = isMatch;
		});
	}

	verifyModal.querySelectorAll('.af-choice input[type="radio"]').forEach(function (radio) {
		radio.addEventListener('change', function () { selectChoice(radio.value); });
	});

	document.querySelectorAll('.af-verify-docs').forEach(function (btn) {
		btn.addEventListener('click', function () {
			verifyGuestId = btn.getAttribute('data-guest-id');
			verifySubtitle.textContent = btn.getAttribute('data-guest-name');
			verifyNotes.value = '';
			clearStatus(verifyStatus);
			selectChoice(btn.getAttribute('data-current') || 'pendiente');
			openModal(verifyModal);
		});
	});

	verifyConfirm.addEventListener('click', function () {
		const selected = verifyModal.querySelector('.af-choice input[type="radio"]:checked');
		if (!selected) {
			setStatus(verifyStatus, <?php echo wp_json_encode( __( 'Selecciona un resultado de revisión.', 'arriendo-facil' ) ); ?>, 'error');
			return;
		}

		verifyConfirm.disabled = true;
		clearStatus(verifyStatus);

		post({
			action: 'af_set_document_status',
			nonce: docNonce,
			guest_id: verifyGuestId,
			doc_status: selected.value,
			notes: verifyNotes.value
		}).then(function (json) {
			verifyConfirm.disabled = false;
			if (!json || !json.success) {
				setStatus(verifyStatus, (json && json.data && json.data.message) || 'Error', 'error');
				return;
			}
			setStatus(verifyStatus, json.data.message, 'success');
			setTimeout(function () { window.location.reload(); }, 900);
		});
	});

	// ---- Interesados: copiar, regenerar enlace y enviar el formulario completo ----
	const preregNonce = <?php echo wp_json_encode( wp_create_nonce( 'af_prereg_nonce' ) ); ?>;
	const msg = {
		copied: <?php echo wp_json_encode( __( 'Enlace copiado.', 'arriendo-facil' ) ); ?>,
		fail: <?php echo wp_json_encode( __( 'La solicitud falló.', 'arriendo-facil' ) ); ?>,
		regen: <?php echo wp_json_encode( __( 'El enlace actual dejará de funcionar. ¿Generar uno nuevo?', 'arriendo-facil' ) ); ?>,
		discard: <?php echo wp_json_encode( __( '¿Descartar a este interesado?', 'arriendo-facil' ) ); ?>,
		verify: <?php echo wp_json_encode( __( '¿Marcar los datos como verificados?', 'arriendo-facil' ) ); ?>,
		pickProperty: <?php echo wp_json_encode( __( 'Elige primero el inmueble.', 'arriendo-facil' ) ); ?>,
		access: <?php echo wp_json_encode( __( '¿Enviar su acceso al portal a', 'arriendo-facil' ) ); ?>
	};

	document.querySelectorAll('[data-af-copy]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			const input = document.querySelector(btn.getAttribute('data-af-copy'));
			if (!input) { return; }
			input.select();
			const done = function () { window.afNotify(msg.copied, 'success'); };
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(input.value).then(done);
			} else {
				document.execCommand('copy');
				done();
			}
		});
	});

	const regen = document.getElementById('af-prereg-regenerate');
	if (regen) {
		regen.addEventListener('click', function () {
			if (!window.confirm(msg.regen)) { return; }
			post({ action: 'af_prereg_regenerate_key', nonce: preregNonce }).then(function (json) {
				if (!json || !json.success) { window.afNotify(msg.fail, 'error'); return; }
				document.getElementById('af-prereg-url').value = json.data.url;
				const share = document.getElementById('af-prereg-share');
				if (share) { share.href = 'https://wa.me/?text=' + encodeURIComponent(<?php echo wp_json_encode( __( 'Déjame tus datos aquí y te escribo: ', 'arriendo-facil' ) ); ?> + json.data.url); }
				window.afNotify(msg.copied, 'success');
			}).catch(function () { window.afNotify(msg.fail, 'error'); });
		});
	}

	document.querySelectorAll('[data-lead-act]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			const row = btn.closest('[data-lead-id]');
			const leadId = row.getAttribute('data-lead-id');
			const act = btn.getAttribute('data-lead-act');

			if (act === 'status') {
				const status = btn.getAttribute('data-status');
				if (!window.confirm(status === 'discarded' ? msg.discard : msg.verify)) { return; }
				btn.disabled = true;
				post({ action: 'af_prereg_status', nonce: preregNonce, lead_id: leadId, status: status }).then(function (json) {
					if (!json || !json.success) {
						btn.disabled = false;
						window.afNotify((json && json.data && json.data.message) || msg.fail, 'error');
						return;
					}
					window.location.reload();
				}).catch(function () { btn.disabled = false; window.afNotify(msg.fail, 'error'); });
				return;
			}

			const channel = btn.getAttribute('data-channel');
			const prop = row.querySelector('.af-lead__prop');
			if (prop && !prop.value) { window.afNotify(msg.pickProperty, 'error'); prop.focus(); return; }

			// WhatsApp: se abre la pestaña al instante (el navegador no bloquea el clic) y luego se rellena.
			const popup = channel === 'whatsapp' ? window.open('', '_blank') : null;
			if (popup) { popup.opener = null; }

			btn.disabled = true;
			post({ action: 'af_prereg_invite', nonce: preregNonce, lead_id: leadId, channel: channel, accommodation_id: prop ? prop.value : '' }).then(function (json) {
				if (!json || !json.success) {
					if (popup) { popup.close(); }
					btn.disabled = false;
					window.afNotify((json && json.data && json.data.message) || msg.fail, 'error');
					return;
				}
				if (popup && json.data.whatsapp_url) { popup.location.href = json.data.whatsapp_url; }
				window.afNotify(json.data.message, 'success');
				setTimeout(function () { window.location.reload(); }, 900);
			}).catch(function () {
				if (popup) { popup.close(); }
				btn.disabled = false;
				window.afNotify(msg.fail, 'error');
			});
		});
	});

	// ---- Acceso al portal del inquilino (código por correo) ----
	document.querySelectorAll('.af-portal-access').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!window.confirm(msg.access + ' ' + (btn.getAttribute('data-guest-name') || '') + '?')) { return; }
			btn.disabled = true;
			post({ action: 'af_send_tenant_portal_link', nonce: guestEditNonce, guest_id: btn.getAttribute('data-guest-id'), channel: 'email' }).then(function (json) {
				btn.disabled = false;
				window.afNotify((json && json.data && json.data.message) || msg.fail, json && json.success ? 'success' : 'error');
			}).catch(function () { btn.disabled = false; window.afNotify(msg.fail, 'error'); });
		});
	});

	// ---- Eliminar inquilino (borrado lógico: conserva contratos y cobros) ----
	document.querySelectorAll('.af-delete-guest').forEach(function (btn) {
		btn.addEventListener('click', function () {
			const name = btn.getAttribute('data-guest-name') || '';
			if (!window.confirm(<?php echo wp_json_encode( __( '¿Eliminar al inquilino', 'arriendo-facil' ) ); ?> + ' ' + name + '? ' + <?php echo wp_json_encode( __( 'Dejará de aparecer en las listas, pero se conserva su historial de contratos y cobros.', 'arriendo-facil' ) ); ?>)) { return; }

			btn.disabled = true;
			post({
				action: 'af_delete_guest',
				nonce: guestEditNonce,
				guest_id: btn.getAttribute('data-guest-id')
			}).then(function (json) {
				if (!json || !json.success) {
					btn.disabled = false;
					window.afNotify((json && json.data && json.data.message) || <?php echo wp_json_encode( __( 'No se pudo eliminar al inquilino.', 'arriendo-facil' ) ); ?>, 'error');
					return;
				}
				window.location.reload();
			}).catch(function () {
				btn.disabled = false;
				window.afNotify(<?php echo wp_json_encode( __( 'La solicitud falló.', 'arriendo-facil' ) ); ?>, 'error');
			});
		});
	});

	// ---- Ver documento (URL firmada de corta duración, nunca pública) ----
	document.querySelectorAll('.af-guest-doc-view').forEach(function (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			post({
				action: 'af_download_guest_document',
				nonce: docNonce,
				document_id: btn.getAttribute('data-document')
			}).then(function (json) {
				btn.disabled = false;
				if (!json || !json.success || !json.data || !json.data.url) {
					window.afNotify((json && json.data && json.data.message) || 'No se pudo abrir el documento.');
					return;
				}
				window.open(json.data.url, '_blank', 'noopener,noreferrer');
			});
		});
	});
}());
</script>
