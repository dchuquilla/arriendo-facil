<?php
/**
 * Avisos (tenant communications) admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$aviso_types   = Arriendo_Facil_Aviso::types();
$scope_ids     = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
$scope_clause  = null === $scope_ids ? '' : ' AND l.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';

$active_leases = (array) $wpdb->get_results(
	"SELECT l.id, l.accommodation_id,
	        p.post_title AS accommodation_title,
	        CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
	        g.email AS guest_email
	 FROM {$wpdb->prefix}af_leases l
	 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
	 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
	 WHERE l.deleted_at IS NULL
	   AND l.status = 'active'{$scope_clause}
	 ORDER BY l.end_date ASC
	 LIMIT 200" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

$f_acc  = isset( $_GET['af_inmueble'] ) ? absint( $_GET['af_inmueble'] ) : 0;
$f_stat = isset( $_GET['af_estado'] ) ? sanitize_key( wp_unslash( $_GET['af_estado'] ) ) : '';

$aviso_where  = '1=1';
$aviso_params = array();

if ( $f_acc && Arriendo_Facil_Tenancy::can_access_accommodation( $f_acc ) ) {
	$aviso_where  .= ' AND accommodation_id = %d';
	$aviso_params[] = $f_acc;
}

if ( in_array( $f_stat, array( 'sent', 'read' ), true ) ) {
	$aviso_where  .= ' AND status = %s';
	$aviso_params[] = $f_stat;
}

if ( null !== $scope_ids ) {
	$aviso_where .= ' AND accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';
}

$aviso_where .= ' ORDER BY id DESC LIMIT 150';

$avisos = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Arriendo_Facil_Aviso::table() . ' WHERE ' . $aviso_where, $aviso_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$acc_titles = array();
foreach ( (array) $avisos as $av ) {
	$acc_titles[ (int) $av->accommodation_id ] = get_the_title( (int) $av->accommodation_id );
}
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Inquilinos', 'arriendo-facil' ),
			'title'    => __( 'Comunicaciones con inquilinos', 'arriendo-facil' ),
			'subtitle' => __( 'Envía avisos a tus inquilinos y confirma con conocimiento de lectura que los recibieron.', 'arriendo-facil' ),
		)
	);
	?>

	<div class="af-split af-split--avisos" style="display:grid; grid-template-columns: 380px 1fr; gap: var(--af-space-4); align-items:start;">
		<div>
			<section class="af-section">
				<header class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Nuevo aviso', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'El inquilino recibirá un correo con un botón para confirmar la lectura.', 'arriendo-facil' ); ?></p>
					</div>
				</header>

				<form id="af-aviso-form" class="af-form-stack" novalidate>
					<div class="af-form-field">
						<label class="af-form-field__label" for="af-aviso-lease"><?php esc_html_e( 'Contrato / inquilino', 'arriendo-facil' ); ?> *</label>
						<select id="af-aviso-lease" name="lease_id" required>
							<option value=""><?php esc_html_e( 'Selecciona un contrato activo…', 'arriendo-facil' ); ?></option>
							<?php foreach ( $active_leases as $lease ) : ?>
								<option value="<?php echo esc_attr( (int) $lease->id ); ?>">
									<?php echo esc_html( (string) $lease->accommodation_title . ' — ' . trim( (string) $lease->guest_name ) . ' (' . (string) $lease->guest_email . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="af-form-field">
						<label class="af-form-field__label" for="af-aviso-type"><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></label>
						<select id="af-aviso-type" name="type">
							<?php foreach ( $aviso_types as $type_key => $type_label ) : ?>
								<option value="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="af-form-field">
						<label class="af-form-field__label" for="af-aviso-subject"><?php esc_html_e( 'Asunto', 'arriendo-facil' ); ?> *</label>
						<input type="text" id="af-aviso-subject" name="subject" required placeholder="<?php esc_attr_e( 'Ej: Recordatorio de pago — período septiembre', 'arriendo-facil' ); ?>" />
					</div>

					<div class="af-form-field">
						<label class="af-form-field__label" for="af-aviso-body"><?php esc_html_e( 'Mensaje', 'arriendo-facil' ); ?></label>
						<textarea id="af-aviso-body" name="body" rows="6" placeholder="<?php esc_attr_e( 'Detalle del aviso…', 'arriendo-facil' ); ?>"></textarea>
					</div>

					<p class="af-aviso-status" id="af-aviso-status" aria-live="polite"></p>

					<div class="af-aviso-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
						<button type="submit" class="button button-primary" id="af-aviso-send"><?php esc_html_e( 'Enviar aviso', 'arriendo-facil' ); ?></button>
					</div>
				</form>
			</section>
		</div>

		<section class="af-section">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title"><?php esc_html_e( 'Historial de avisos', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'Estado de envío y confirmación de lectura vinculados al inmueble.', 'arriendo-facil' ); ?></p>
				</div>
			</header>

			<form method="get" class="af-filter-bar" style="padding:0 0 16px;">
				<input type="hidden" name="page" value="af-avisos" />
				<label class="af-form-field__label" for="af-fil-inmueble"><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></label>
				<select id="af-fil-inmueble" name="af_inmueble">
					<option value=""><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
					<?php foreach ( $acc_titles as $aid => $atitle ) : ?>
						<option value="<?php echo esc_attr( (int) $aid ); ?>" <?php selected( $f_acc, (int) $aid ); ?>><?php echo esc_html( $atitle ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="af-form-field__label" for="af-fil-estado"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></label>
				<select id="af-fil-estado" name="af_estado">
					<option value=""><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></option>
					<option value="sent" <?php selected( $f_stat, 'sent' ); ?>><?php esc_html_e( 'Enviado', 'arriendo-facil' ); ?></option>
					<option value="read" <?php selected( $f_stat, 'read' ); ?>><?php esc_html_e( 'Leído', 'arriendo-facil' ); ?></option>
				</select>
				<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Filtrar', 'arriendo-facil' ); ?></button>
				<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-avisos' ) ); ?>"><?php esc_html_e( 'Limpiar', 'arriendo-facil' ); ?></a>
			</form>

			<?php if ( empty( $avisos ) ) : ?>
				<div class="af-empty">
					<span class="af-empty__icon" aria-hidden="true">📨</span>
					<h3 class="af-empty__title"><?php esc_html_e( 'Sin avisos todavía', 'arriendo-facil' ); ?></h3>
					<p class="af-empty__text"><?php esc_html_e( 'Usa el formulario para enviar el primer aviso a un inquilino.', 'arriendo-facil' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped af-data-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Asunto', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Correo', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Enviado', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Acción', 'arriendo-facil' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $avisos as $aviso ) : ?>
							<?php
							$is_read = ( 'read' === $aviso->status );
							$av_type = isset( $aviso_types[ $aviso->type ] ) ? $aviso_types[ $aviso->type ] : $aviso->type;
							?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $acc_titles[ (int) $aviso->accommodation_id ] ) ? $acc_titles[ (int) $aviso->accommodation_id ] : ( '#' . (int) $aviso->accommodation_id ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Tipo', 'arriendo-facil' ); ?>"><span class="af-pill af-pill--neutral"><?php echo esc_html( $av_type ); ?></span></td>
								<td data-label="<?php esc_attr_e( 'Asunto', 'arriendo-facil' ); ?>"><strong><?php echo esc_html( $aviso->subject ); ?></strong></td>
								<td data-label="<?php esc_attr_e( 'Correo', 'arriendo-facil' ); ?>"><?php echo esc_html( $aviso->recipient_email ); ?></td>
								<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>">
									<?php if ( $is_read ) : ?>
										<span class="af-pill af-pill--success"><?php esc_html_e( 'Leído', 'arriendo-facil' ); ?></span>
										<?php if ( $aviso->read_at ) : ?>
											<div class="af-td-meta" style="margin-top:2px;"><?php echo esc_html( '· ' . (string) $aviso->read_at ); ?></div>
										<?php endif; ?>
									<?php else : ?>
										<span class="af-pill af-pill--warning"><?php esc_html_e( 'Enviado', 'arriendo-facil' ); ?></span>
									<?php endif; ?>
								</td>
								<td data-label="<?php esc_attr_e( 'Enviado', 'arriendo-facil' ); ?>"><?php echo esc_html( (string) $aviso->created_at ); ?></td>
								<td data-label="<?php esc_attr_e( 'Acción', 'arriendo-facil' ); ?>">
									<button type="button" class="button button-small af-aviso-view"
										data-subject="<?php echo esc_attr( $aviso->subject ); ?>"
										data-body="<?php echo esc_attr( $aviso->body ? (string) $aviso->body : '' ); ?>"
										data-type="<?php echo esc_attr( $av_type ); ?>"
										data-created="<?php echo esc_attr( (string) $aviso->created_at ); ?>"
										data-read="<?php echo esc_attr( $is_read ? (string) $aviso->read_at : '' ); ?>">
										<?php esc_html_e( 'Ver', 'arriendo-facil' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
	</div>
</div>

<div class="af-modal" id="af-modal-aviso" role="dialog" aria-modal="true" aria-labelledby="af-modal-aviso-title">
	<div class="af-modal__backdrop" data-af-aviso-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-aviso-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-aviso-title"><?php esc_html_e( 'Detalle del aviso', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-aviso-view-meta"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-aviso-view-subject" style="font-weight:700; margin:0 0 12px;"></p>
			<div id="af-aviso-view-body" style="white-space:pre-wrap; color:#344054; font-size:14px; line-height:1.6;"></div>
			<p id="af-aviso-view-read" class="af-aviso-read" style="margin:16px 0 0; color:#027a48; font-weight:600;"></p>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-aviso-modal-close><?php esc_html_e( 'Cerrar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<script>
(function () {
	'use strict';

	var config = {
		ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
		nonce: <?php echo wp_json_encode( wp_create_nonce( 'af_aviso_nonce' ) ); ?>
	};

	var form = document.getElementById('af-aviso-form');
	var status = document.getElementById('af-aviso-status');
	var sendBtn = document.getElementById('af-aviso-send');

	if (form) {
		(function prefillFromCobros() {
			var qs = new URLSearchParams(window.location.search);
			var lease = qs.get('af_aviso_lease');
			var subject = qs.get('af_aviso_subject');
			var type = qs.get('af_aviso_type');
			if (lease && form.lease_id) {
				var found = false;
				Array.prototype.forEach.call(form.lease_id.options, function (opt) {
					if (opt.value === lease) {
						form.lease_id.value = opt.value;
						if (subject) { form.subject.value = subject; }
						if (type && Array.prototype.some.call(form.type.options, function (t) { return t.value === type; })) {
							form.type.value = type;
						}
						found = true;
					}
				});
				if (found) { form.subject.focus(); }
			}
		}());

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			sendBtn.disabled = true;
			status.textContent = '';
			status.className = 'af-aviso-status';

			var body = new URLSearchParams();
			body.append('action', 'af_send_aviso');
			body.append('nonce', config.nonce);
			body.append('lease_id', form.lease_id.value);
			body.append('type', form.type.value);
			body.append('subject', form.subject.value);
			body.append('body', form.body.value);

			fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body
			}).then(function (r) { return r.json(); }).then(function (json) {
				sendBtn.disabled = false;
				if (!json || !json.success) {
					status.textContent = (json && json.data && json.data.message) || 'Error';
					status.className = 'af-aviso-status is-error';
					return;
				}
				status.textContent = json.data.message;
				status.className = 'af-aviso-status is-success';
				setTimeout(function () { window.location.reload(); }, 900);
			}).catch(function () {
				sendBtn.disabled = false;
				status.textContent = 'Error de red';
				status.className = 'af-aviso-status is-error';
			});
		});
	}

	var modal = document.getElementById('af-modal-aviso');
	if (modal) {
		modal.querySelectorAll('[data-af-aviso-modal-close]').forEach(function (btn) {
			btn.addEventListener('click', function () { modal.classList.remove('is-open'); });
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { modal.classList.remove('is-open'); }
		});

		document.querySelectorAll('.af-aviso-view').forEach(function (btn) {
			btn.addEventListener('click', function () {
				document.getElementById('af-aviso-view-meta').textContent =
					btn.getAttribute('data-type') + ' · ' + btn.getAttribute('data-created');
				document.getElementById('af-aviso-view-subject').textContent = btn.getAttribute('data-subject');
				document.getElementById('af-aviso-view-body').textContent = btn.getAttribute('data-body');
				document.getElementById('af-aviso-view-read').textContent =
					btn.getAttribute('data-read') ? '<?php echo esc_js( __( 'Confirmado como leído el ', 'arriendo-facil' ) ); ?>' + btn.getAttribute('data-read') : '<?php echo esc_js( __( 'Sin confirmación de lectura aún.', 'arriendo-facil' ) ); ?>';
				modal.classList.add('is-open');
			});
		});
	}
}());
</script>