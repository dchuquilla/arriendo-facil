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
					"SELECT id, accommodation_id, email, status FROM {$queue_table} WHERE id = %d LIMIT 1",
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
<div class="wrap">
	<h1><?php esc_html_e( 'Huespedes', 'arriendo-facil' ); ?></h1>

	<?php if ( '' !== $action_notice ) : ?>
		<div class="notice <?php echo esc_attr( $action_notice_class ); ?> is-dismissible">
			<p><?php echo esc_html( $action_notice ); ?></p>
		</div>
	<?php endif; ?>

	<div class="af-guest-actions" style="margin-bottom: 16px;">
		<button type="button" class="button button-primary" id="af-new-guest">
			<?php esc_html_e( '+ Nuevo huesped', 'arriendo-facil' ); ?>
		</button>
	</div>

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

	<h2 style="margin-top:20px;"><?php esc_html_e( 'Visit Requests Queue', 'arriendo-facil' ); ?></h2>
	<table class="wp-list-table widefat fixed striped" style="margin-bottom:20px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Accommodation', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Interested Person', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Contact', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Status', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Request Details', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $visit_requests ) ) : ?>
				<?php foreach ( $visit_requests as $request ) : ?>
					<?php
					$status = isset( $request->status ) ? sanitize_key( (string) $request->status ) : 'queued';
					$is_actionable = in_array( $status, array( 'queued', 'notified', 'visit_requested' ), true );
					$status_label = $status;
					if ( 'visit_requested' === $status ) {
						$status_label = __( 'Visit Requested', 'arriendo-facil' );
					} elseif ( 'queued' === $status ) {
						$status_label = __( 'Queued', 'arriendo-facil' );
					} elseif ( 'notified' === $status ) {
						$status_label = __( 'Notified', 'arriendo-facil' );
					} elseif ( 'approved' === $status ) {
						$status_label = __( 'Approved', 'arriendo-facil' );
					} elseif ( 'rejected' === $status ) {
						$status_label = __( 'Rejected', 'arriendo-facil' );
					}
					?>
					<tr>
						<td><?php echo isset( $request->created_at ) ? esc_html( (string) $request->created_at ) : '—'; ?></td>
						<td>
							<strong><?php echo ! empty( $request->accommodation_title ) ? esc_html( (string) $request->accommodation_title ) : __( '(Sin titulo)', 'arriendo-facil' ); ?></strong>
							<br />
							<small><?php echo esc_html( '#' . absint( $request->accommodation_id ) ); ?></small>
						</td>
						<td><?php echo esc_html( isset( $request->name ) ? (string) $request->name : '—' ); ?></td>
						<td>
							<div><strong><?php esc_html_e( 'Email:', 'arriendo-facil' ); ?></strong> <?php echo esc_html( isset( $request->email ) ? (string) $request->email : '—' ); ?></div>
							<div><strong><?php esc_html_e( 'Phone:', 'arriendo-facil' ); ?></strong> <?php echo esc_html( isset( $request->phone ) ? (string) $request->phone : '—' ); ?></div>
						</td>
						<td><?php echo esc_html( (string) $status_label ); ?></td>
						<td><?php echo ! empty( $request->message ) ? esc_html( (string) $request->message ) : '—'; ?></td>
						<td>
							<?php if ( $is_actionable ) : ?>
								<form method="post" style="display:inline-block;margin-right:4px;">
									<input type="hidden" name="af_queue_action" value="approve" />
									<input type="hidden" name="af_queue_request_id" value="<?php echo esc_attr( (string) absint( $request->id ) ); ?>" />
									<input type="hidden" name="af_queue_nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_queue_action' ) ); ?>" />
									<button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Al aprobar esta solicitud, las otras solicitudes activas para la misma acomodacion se rechazaran automaticamente. Continuar?', 'arriendo-facil' ) ); ?>');">
										<?php esc_html_e( 'Approve', 'arriendo-facil' ); ?>
									</button>
								</form>
								<form method="post" style="display:inline-block;">
									<input type="hidden" name="af_queue_action" value="reject" />
									<input type="hidden" name="af_queue_request_id" value="<?php echo esc_attr( (string) absint( $request->id ) ); ?>" />
									<input type="hidden" name="af_queue_nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_queue_action' ) ); ?>" />
									<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Confirmas rechazar esta solicitud?', 'arriendo-facil' ) ); ?>');">
										<?php esc_html_e( 'Reject', 'arriendo-facil' ); ?>
									</button>
								</form>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No se encontraron solicitudes de visita.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID (National ID or Passport)', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Name', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Email', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Phone', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'ID Number', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'AI Score', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $guests ) : ?>
				<?php foreach ( $guests as $guest ) : ?>
					<tr>
						<td><?php echo esc_html( $guest->id_number ); ?></td>
						<td><?php echo esc_html( $guest->first_name . ' ' . $guest->last_name ); ?></td>
						<td><?php echo esc_html( $guest->email ); ?></td>
						<td><?php echo esc_html( $guest->phone ); ?></td>
						<td>—</td>
						<td>
							<?php echo $guest->ai_score ? esc_html( number_format( (float) $guest->ai_score, 2 ) ) : '—'; ?>
						</td>
						<td>
							<button type="button" class="button af-score-guest"
								data-guest-id="<?php echo esc_attr( $guest->id ); ?>">
								<?php esc_html_e( 'Score (AI)', 'arriendo-facil' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No se encontraron huespedes.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
