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
					if ( '' !== $selected_email && class_exists( 'Arriendo_Facil_Guest' ) ) {
						$guest_service      = new Arriendo_Facil_Guest();
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
	if ( ! empty( $owner_ids ) ) {
		$ids_sql = implode( ',', array_map( 'intval', $owner_ids ) );
		$guests = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}af_guests WHERE accommodation_id IN ($ids_sql) ORDER BY created_at DESC LIMIT 100"
		);
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
		$guests = array();
		$visit_requests = array();
	}
} else {
	$guests = $wpdb->get_results(
		"SELECT * FROM {$wpdb->prefix}af_guests ORDER BY created_at DESC LIMIT 100"
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
			'title'    => __( 'Huéspedes', 'arriendo-facil' ),
			'subtitle' => __( 'Interesados en cola, huéspedes activos y perfiles de scoring. Aprueba solicitudes y comparte formularios de perfil legal.', 'arriendo-facil' ),
			'actions'  => array(
				sprintf(
					'<button type="button" class="button af-btn af-btn--primary" id="af-new-guest"><span class="af-btn__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>%s</button>',
					esc_html__( 'Nuevo huésped', 'arriendo-facil' )
				),
			),
		)
	);
	?>

	<?php if ( '' !== $action_notice ) : ?>
		<div class="notice <?php echo esc_attr( $action_notice_class ); ?> is-dismissible">
			<p><?php echo esc_html( $action_notice ); ?></p>
		</div>
	<?php endif; ?>

	<div id="af-guest-form-card" class="card" style="max-width: 900px; margin: 16px 0; padding: 16px; display: none;">
		<h2><?php esc_html_e( 'Nuevo huesped', 'arriendo-facil' ); ?></h2>
		<form id="af-guest-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="af_create_guest" />
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_guest_nonce' ) ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="af_guest_id_number"><?php esc_html_e( 'ID (National ID or Passport)*', 'arriendo-facil' ); ?></label></th>
					<td><input type="text" required id="af_guest_id_number" name="id_number" class="regular-text" inputmode="numeric" pattern="^[0-9]{1,10}$" maxlength="10" title="Use only numbers (max 10)" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_name"><?php esc_html_e( 'Name*', 'arriendo-facil' ); ?></label></th>
					<td><input type="text" required id="af_guest_name" name="name" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_email"><?php esc_html_e( 'Email*', 'arriendo-facil' ); ?></label></th>
					<td><input type="email" required id="af_guest_email" name="email" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_phone"><?php esc_html_e( 'Contact*', 'arriendo-facil' ); ?></label></th>
					<td><input type="text" required id="af_guest_phone" name="phone" class="regular-text" inputmode="numeric" pattern="^[0-9]{1,10}$" maxlength="10" title="Use only numbers (max 10)" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_mascotas"><?php esc_html_e( 'Pets (1 to 10)*', 'arriendo-facil' ); ?></label></th>
					<td><input type="number" required id="af_guest_mascotas" name="mascotas" class="small-text" min="1" max="10" step="1" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_referencia_1"><?php esc_html_e( 'Personal References (min 2)*', 'arriendo-facil' ); ?></label></th>
					<td>
						<input type="text" required id="af_guest_referencia_1" name="referencia_personal_1" class="regular-text" placeholder="Personal reference 1" style="margin-bottom:8px;" />
						<br />
						<input type="text" required id="af_guest_referencia_2" name="referencia_personal_2" class="regular-text" placeholder="Personal reference 2" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_personas_viviran"><?php esc_html_e( 'How many people will live in or enter the property?*', 'arriendo-facil' ); ?></label></th>
					<td>
						<select id="af_guest_personas_viviran" name="personas_viviran" required>
							<option value="">--</option>
							<?php for ( $i = 1; $i <= 10; $i++ ) : ?>
								<option value="<?php echo esc_attr( (string) $i ); ?>"><?php echo esc_html( (string) $i ); ?></option>
							<?php endfor; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_garantia_alicuota_pdf"><?php esc_html_e( 'Guarantee and HOA Fee (PDF)', 'arriendo-facil' ); ?></label></th>
					<td><input type="file" id="af_guest_garantia_alicuota_pdf" name="guest_garantia_alicuota_pdf" class="regular-text" accept="application/pdf,.pdf" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_cedula_papeleta_pdf"><?php esc_html_e( 'National ID and Voting Certificate (PDF)', 'arriendo-facil' ); ?></label></th>
					<td><input type="file" id="af_guest_cedula_papeleta_pdf" name="guest_cedula_papeleta_pdf" class="regular-text" accept="application/pdf,.pdf" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_certificado_bancario_pdf"><?php esc_html_e( 'Bank Certificate (PDF)', 'arriendo-facil' ); ?></label></th>
					<td><input type="file" id="af_guest_certificado_bancario_pdf" name="guest_certificado_bancario_pdf" class="regular-text" accept="application/pdf,.pdf" /></td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar huesped', 'arriendo-facil' ); ?></button>
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
		<input type="radio" name="af-guest-tab" id="af-tab-visits" class="af-tabs__radio" <?php echo ( $pending_visits > 0 || 0 === $guest_count_page ) ? 'checked' : ''; ?> />
		<input type="radio" name="af-guest-tab" id="af-tab-guests" class="af-tabs__radio" <?php echo ( 0 === $pending_visits && $guest_count_page > 0 ) ? 'checked' : ''; ?> />

		<div class="af-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'Vista de huéspedes', 'arriendo-facil' ); ?>">
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

			<label for="af-tab-guests" class="af-tabs__tab af-tabs__tab--guests" role="tab">
				<span class="af-tabs__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</span>
				<span class="af-tabs__label"><?php esc_html_e( 'Huéspedes registrados', 'arriendo-facil' ); ?></span>
				<?php if ( $guest_count_page > 0 ) : ?>
					<span class="af-tabs__badge"><?php echo esc_html( number_format_i18n( $guest_count_page ) ); ?></span>
				<?php endif; ?>
			</label>
		</div>

		<div class="af-tabs__panels">

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

			<section class="af-tabs__panel af-tabs__panel--guests" role="tabpanel" aria-labelledby="af-tab-guests">
				<div class="af-section af-section--guests">
					<header class="af-section__head">
						<div>
							<h2 class="af-section__title"><?php esc_html_e( 'Huéspedes registrados', 'arriendo-facil' ); ?></h2>
							<p class="af-section__subtitle"><?php esc_html_e( 'Perfiles verificados de personas que ya alquilaron o están en proceso. Ejecuta el scoring con IA para evaluar riesgo.', 'arriendo-facil' ); ?></p>
						</div>
						<?php if ( $guest_count_page > 0 ) : ?>
							<span class="af-pill af-pill--info"><?php echo esc_html( sprintf( _n( '%s huésped', '%s huéspedes', $guest_count_page, 'arriendo-facil' ), number_format_i18n( $guest_count_page ) ) ); ?></span>
						<?php endif; ?>
					</header>

					<?php if ( $guests ) : ?>
					<table class="wp-list-table widefat af-data-table af-data-table--guests">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Identificación', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Email', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Teléfono', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'AI Score', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $guests as $guest ) :
								$score = $guest->ai_score ? (float) $guest->ai_score : null;
								$score_variant = 'default';
								if ( null !== $score ) {
									if ( $score >= 0.75 ) { $score_variant = 'success'; }
									elseif ( $score >= 0.5 ) { $score_variant = 'warning'; }
									else { $score_variant = 'danger'; }
								}
							?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Identificación', 'arriendo-facil' ); ?>"><?php echo esc_html( $guest->id_number ); ?></td>
									<td data-label="<?php esc_attr_e( 'Nombre', 'arriendo-facil' ); ?>">
										<strong><?php echo esc_html( $guest->first_name . ' ' . $guest->last_name ); ?></strong>
									</td>
									<td data-label="<?php esc_attr_e( 'Email', 'arriendo-facil' ); ?>"><?php echo esc_html( $guest->email ); ?></td>
									<td data-label="<?php esc_attr_e( 'Teléfono', 'arriendo-facil' ); ?>"><?php echo esc_html( $guest->phone ); ?></td>
									<td data-label="<?php esc_attr_e( 'AI Score', 'arriendo-facil' ); ?>">
										<?php if ( null !== $score ) : ?>
											<span class="af-pill af-pill--<?php echo esc_attr( $score_variant ); ?>"><?php echo esc_html( number_format( $score, 2 ) ); ?></span>
										<?php else : ?>
											<span class="af-td-meta">—</span>
										<?php endif; ?>
									</td>
									<td class="af-td-actions" data-label="<?php esc_attr_e( 'Acciones', 'arriendo-facil' ); ?>">
										<button type="button" class="button af-btn af-btn--ghost af-btn--sm af-score-guest"
											data-guest-id="<?php echo esc_attr( $guest->id ); ?>">
											<?php esc_html_e( 'Score (IA)', 'arriendo-facil' ); ?>
										</button>
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
							<h3><?php esc_html_e( 'Sin huéspedes registrados aún', 'arriendo-facil' ); ?></h3>
							<p><?php esc_html_e( 'Usa el botón "Nuevo huésped" para registrar el primer perfil verificado.', 'arriendo-facil' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</section>

		</div>
	</div>
</div>
