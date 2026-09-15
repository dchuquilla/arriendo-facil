<?php
/**
 * Ficha e historial consolidado del inquilino (Score de Pago Real +
 * evaluaciones cualitativas + historial de contratos y documentos).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$guest_id = isset( $_GET['guest_id'] ) ? absint( wp_unslash( $_GET['guest_id'] ) ) : 0;

if ( ! $guest_id || ! Arriendo_Facil_Tenancy::can_access_guest( $guest_id ) ) {
	wp_die( esc_html__( 'Inquilino no encontrado o sin acceso.', 'arriendo-facil' ) );
}

$guest = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d", $guest_id ) );
if ( ! $guest ) {
	wp_die( esc_html__( 'Inquilino no encontrado.', 'arriendo-facil' ) );
}

$leases = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT l.*, p.post_title AS accommodation_title
		 FROM {$wpdb->prefix}af_leases l
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 WHERE l.guest_id = %d AND l.deleted_at IS NULL
		 ORDER BY l.start_date DESC",
		$guest_id
	)
);

$latest_lease_id = 0;
foreach ( $leases as $lease_row ) {
	if ( 'active' === $lease_row->status ) {
		$latest_lease_id = (int) $lease_row->id;
		break;
	}
}
if ( ! $latest_lease_id && ! empty( $leases ) ) {
	$latest_lease_id = (int) $leases[0]->id;
}

$payment_score = $latest_lease_id && class_exists( 'Arriendo_Facil_Review' )
	? Arriendo_Facil_Review::suggest_payment_score( $latest_lease_id )
	: null;

$lease_ids  = wp_list_pluck( $leases, 'id' );
$reviews    = array();
$criteria_labels = class_exists( 'Arriendo_Facil_Review' ) ? Arriendo_Facil_Review::owner_to_tenant_criteria() : array();

// ── Expediente digital: trazabilidad legal + documentos del inmueble ─────
$legal_statuses_map = class_exists( 'Arriendo_Facil_Lease_Operations' ) ? Arriendo_Facil_Lease_Operations::legal_statuses() : array();
$document_types_map = class_exists( 'Arriendo_Facil_Accommodation' ) ? Arriendo_Facil_Accommodation::document_types() : array();

$legal_timeline    = array();
$property_docs     = array();
$seen_accommodation_ids = array();

foreach ( $leases as $lease_row ) {
	$legal_status = isset( $lease_row->legal_status ) && $lease_row->legal_status ? (string) $lease_row->legal_status : 'pendiente';
	$contract_url = '';
	if ( ! empty( $lease_row->document_url ) ) {
		$contract_url = add_query_arg(
			array(
				'action'   => 'af_download_lease_contract',
				'lease_id' => (int) $lease_row->id,
				'nonce'    => wp_create_nonce( 'af_lease_nonce' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	$legal_timeline[] = array(
		'lease'         => $lease_row,
		'legal_status'  => $legal_status,
		'legal_notes'   => isset( $lease_row->legal_notes ) ? (string) $lease_row->legal_notes : '',
		'legal_updated' => isset( $lease_row->legal_updated_at ) ? (string) $lease_row->legal_updated_at : '',
		'contract_url'  => $contract_url,
	);

	$accommodation_id = (int) $lease_row->accommodation_id;
	if ( ! $accommodation_id || isset( $seen_accommodation_ids[ $accommodation_id ] ) ) {
		continue;
	}
	$seen_accommodation_ids[ $accommodation_id ] = true;

	$raw_docs = get_post_meta( $accommodation_id, '_af_documents', true );
	$decoded  = $raw_docs ? json_decode( (string) $raw_docs, true ) : array();
	if ( ! is_array( $decoded ) ) {
		continue;
	}

	foreach ( $decoded as $doc ) {
		$attachment_id = isset( $doc['attachment_id'] ) ? absint( $doc['attachment_id'] ) : 0;
		$doc_url       = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
		if ( ! $doc_url ) {
			continue;
		}
		$property_docs[] = array(
			'accommodation_title' => $lease_row->accommodation_title ? $lease_row->accommodation_title : '#' . $accommodation_id,
			'type'                => isset( $doc['type'] ) ? sanitize_key( $doc['type'] ) : 'otro',
			'label'               => isset( $doc['label'] ) ? sanitize_text_field( $doc['label'] ) : '',
			'url'                 => $doc_url,
			'expires_at'          => isset( $doc['expires_at'] ) ? sanitize_text_field( $doc['expires_at'] ) : '',
		);
	}
}

if ( ! empty( $lease_ids ) ) {
	$lease_ids_sql = implode( ',', array_map( 'absint', $lease_ids ) );
	$reviews       = (array) $wpdb->get_results(
		"SELECT r.*, p.post_title AS accommodation_title
		 FROM {$wpdb->prefix}af_reviews r
		 LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
		 WHERE r.lease_id IN ({$lease_ids_sql})
		   AND r.review_direction = 'owner_to_tenant'
		   AND r.status = 'completed'
		 ORDER BY r.submitted_at DESC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);
}

$documents = class_exists( 'Arriendo_Facil_Document_Verification' )
	? Arriendo_Facil_Document_Verification::get_guest_documents( $guest_id )
	: array();

$doc_status  = isset( $guest->doc_status ) && $guest->doc_status ? (string) $guest->doc_status : 'pendiente';
$doc_variant = 'verificado' === $doc_status ? 'success' : ( 'rechazado' === $doc_status ? 'danger' : 'warning' );

$identity_status = isset( $guest->identity_match_status ) ? (string) $guest->identity_match_status : 'not_checked';

$can_manage = current_user_can( Arriendo_Facil_Tenancy::CAP );
$back_url   = admin_url( 'admin.php?page=af-guests' );
$full_name  = trim( $guest->first_name . ' ' . $guest->last_name );
?>
<div class="wrap af-shell">
	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Ficha del inquilino', 'arriendo-facil' ),
			'title'    => $full_name ? $full_name : __( 'Inquilino', 'arriendo-facil' ),
			'subtitle' => esc_html( $guest->email ) . ( $guest->phone ? ' · ' . esc_html( $guest->phone ) : '' ),
			'actions'  => array(
				sprintf( '<a href="%s" class="button af-btn af-btn--ghost">%s</a>', esc_url( $back_url ), esc_html__( '← Volver a inquilinos', 'arriendo-facil' ) ),
			),
		)
	);
	?>

	<div class="af-kpi-grid" role="list">
		<article class="af-kpi af-kpi--accent af-score-card" role="listitem">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Score de pago real', 'arriendo-facil' ); ?></span></div>
			<?php if ( $payment_score ) : ?>
				<div class="af-score-ring af-score-ring--<?php echo esc_attr( $payment_score['score'] >= 4 ? 'success' : ( $payment_score['score'] >= 3 ? 'warning' : 'danger' ) ); ?>" style="--af-score-pct: <?php echo esc_attr( $payment_score['score'] * 20 ); ?>;">
					<span><?php echo esc_html( $payment_score['score'] ); ?><small>/5</small></span>
				</div>
				<div class="af-kpi__hint">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: on-time count, 2: late count, 3: average days late */
							__( '%1$d a tiempo · %2$d con atraso · %3$s días promedio', 'arriendo-facil' ),
							$payment_score['on_time'],
							$payment_score['late'],
							number_format_i18n( $payment_score['average_days_late'], 1 )
						)
					);
					?>
				</div>
			<?php else : ?>
				<div class="af-kpi__value">—</div>
				<div class="af-kpi__hint"><?php esc_html_e( 'Aún no hay suficiente historial de cobros.', 'arriendo-facil' ); ?></div>
			<?php endif; ?>
		</article>

		<article class="af-kpi" role="listitem">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Documentos', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><span class="af-pill af-pill--<?php echo esc_attr( $doc_variant ); ?> af-pill--lg"><?php echo esc_html( ucfirst( $doc_status ) ); ?></span></div>
			<div class="af-kpi__hint">
				<?php if ( 'match' === $identity_status ) : ?>
					<?php esc_html_e( '✓ Cédula coincide con el documento', 'arriendo-facil' ); ?>
				<?php elseif ( 'no_match' === $identity_status ) : ?>
					<span style="color:var(--af-danger-700);"><?php esc_html_e( '⚠ No coincide, revisar', 'arriendo-facil' ); ?></span>
				<?php else : ?>
					<?php echo esc_html( sprintf( /* translators: %d: document count */ _n( '%d documento subido', '%d documentos subidos', count( $documents ), 'arriendo-facil' ), count( $documents ) ) ); ?>
				<?php endif; ?>
			</div>
		</article>

		<article class="af-kpi" role="listitem">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Contratos', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( count( $leases ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Historial completo con esta administración', 'arriendo-facil' ); ?></div>
		</article>

		<article class="af-kpi <?php echo count( $reviews ) > 0 ? 'af-kpi--success' : ''; ?>" role="listitem">
			<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Evaluaciones cualitativas', 'arriendo-facil' ); ?></span></div>
			<div class="af-kpi__value"><?php echo esc_html( count( $reviews ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Puntualidad, cuidado, convivencia y comunicación', 'arriendo-facil' ); ?></div>
			<?php if ( $can_manage && $latest_lease_id ) : ?>
				<div class="af-kpi__footer">
					<button type="button" class="af-kpi__link" id="af-profile-rate-btn" data-lease="<?php echo esc_attr( $latest_lease_id ); ?>" data-suggested="<?php echo esc_attr( $payment_score ? $payment_score['score'] : 0 ); ?>" style="background:none;border:0;cursor:pointer;padding:0;">
						<?php esc_html_e( 'Calificar ahora', 'arriendo-facil' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</article>
	</div>

	<?php
	$has_identity_extras = ! empty( $guest->nationality ) || ! empty( $guest->birth_city ) || ! empty( $guest->id_number );
	if ( $has_identity_extras ) :
		?>
		<div class="af-split af-split--identity" style="margin-top: var(--af-space-5);">
			<div class="af-info-strip">
				<strong><?php esc_html_e( 'Datos de identidad', 'arriendo-facil' ); ?></strong>
				<?php if ( ! empty( $guest->id_number ) ) : ?>
					<span><?php esc_html_e( 'Cédula / RUC:', 'arriendo-facil' ); ?> <b><?php echo esc_html( $guest->id_number ); ?></b></span>
				<?php endif; ?>
				<?php if ( ! empty( $guest->nationality ) ) : ?>
					<span><?php esc_html_e( 'Nacionalidad:', 'arriendo-facil' ); ?> <b><?php echo esc_html( $guest->nationality ); ?></b></span>
				<?php endif; ?>
				<?php if ( ! empty( $guest->birth_city ) ) : ?>
					<span><?php esc_html_e( 'Ciudad de nacimiento:', 'arriendo-facil' ); ?> <b><?php echo esc_html( $guest->birth_city ); ?></b></span>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="af-split">
		<section class="af-section" aria-labelledby="af-profile-leases">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-profile-leases"><?php esc_html_e( 'Historial de contratos', 'arriendo-facil' ); ?></h2>
				</div>
			</header>
			<?php if ( empty( $leases ) ) : ?>
				<p class="af-td-meta"><?php esc_html_e( 'Sin contratos registrados.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<div class="af-table-scroll">
					<table class="wp-list-table widefat fixed striped af-data-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Vigencia', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Canon', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Estado de cuenta', 'arriendo-facil' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $leases as $lease_row ) : ?>
								<?php
								$status_variant = 'active' === $lease_row->status ? 'success' : ( 'draft' === $lease_row->status ? 'neutral' : 'info' );
								?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><?php echo esc_html( $lease_row->accommodation_title ? $lease_row->accommodation_title : '#' . $lease_row->accommodation_id ); ?></td>
									<td data-label="<?php esc_attr_e( 'Vigencia', 'arriendo-facil' ); ?>"><?php echo esc_html( $lease_row->start_date . ' → ' . $lease_row->end_date ); ?></td>
									<td data-label="<?php esc_attr_e( 'Canon', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( (float) $lease_row->monthly_rent, 2 ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>"><span class="af-pill af-pill--<?php echo esc_attr( $status_variant ); ?>"><?php echo esc_html( ucfirst( $lease_row->status ) ); ?></span></td>
									<td data-label="<?php esc_attr_e( 'Estado de cuenta', 'arriendo-facil' ); ?>">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections&statement_lease=' . (int) $lease_row->id ) ); ?>"><?php esc_html_e( 'Ver estado de cuenta', 'arriendo-facil' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>

		<aside class="af-section" aria-labelledby="af-profile-reviews">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-profile-reviews"><?php esc_html_e( 'Evaluaciones del administrador', 'arriendo-facil' ); ?></h2>
				</div>
			</header>
			<?php if ( empty( $reviews ) ) : ?>
				<p class="af-td-meta"><?php esc_html_e( 'Aún no hay evaluaciones cualitativas registradas.', 'arriendo-facil' ); ?></p>
			<?php else : ?>
				<ul class="af-review-list">
					<?php foreach ( $reviews as $review ) : ?>
						<?php $criteria = ! empty( $review->criteria_scores ) ? json_decode( $review->criteria_scores, true ) : array(); ?>
						<li class="af-review-list__item">
							<div class="af-review-list__head">
								<strong><?php echo esc_html( $review->accommodation_title ? $review->accommodation_title : '—' ); ?></strong>
								<span class="af-td-meta"><?php echo esc_html( gmdate( 'd/m/Y', strtotime( $review->submitted_at ) ) ); ?></span>
							</div>
							<?php if ( is_array( $criteria ) && ! empty( $criteria ) ) : ?>
								<div class="af-review-list__criteria">
									<?php foreach ( $criteria as $key => $value ) : ?>
										<span class="af-pill af-pill--neutral"><?php echo esc_html( ( $criteria_labels[ $key ] ?? $key ) . ': ' . $value . '/5' ); ?></span>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $review->comment_text ) ) : ?>
								<p class="af-review-list__comment">"<?php echo esc_html( $review->comment_text ); ?>"</p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</aside>
	</div>

	<section class="af-section" aria-labelledby="af-profile-expediente">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title" id="af-profile-expediente"><?php esc_html_e( 'Expediente digital y control legal', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Trazabilidad del contrato y respaldos legales del inmueble asociado.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

		<div class="af-legal-timeline">
			<?php foreach ( $legal_timeline as $entry ) : ?>
				<?php
				$lease_row  = $entry['lease'];
				$legal_tier = 'notarizado' === $entry['legal_status'] ? 'success' : ( 'en_notarizacion' === $entry['legal_status'] ? 'warning' : 'neutral' );
				?>
				<div class="af-legal-timeline__item">
					<span class="af-pill af-pill--<?php echo esc_attr( $legal_tier ); ?>"><?php echo esc_html( $legal_statuses_map[ $entry['legal_status'] ] ?? $entry['legal_status'] ); ?></span>
					<div class="af-legal-timeline__body">
						<strong><?php echo esc_html( $lease_row->accommodation_title ? $lease_row->accommodation_title : '#' . (int) $lease_row->accommodation_id ); ?></strong>
						<?php if ( $entry['legal_notes'] ) : ?>
							<span class="af-td-meta"><?php echo esc_html( $entry['legal_notes'] ); ?></span>
						<?php endif; ?>
						<?php if ( $entry['legal_updated'] ) : ?>
							<span class="af-td-meta"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Actualizado: %s', 'arriendo-facil' ), gmdate( 'd/m/Y', strtotime( $entry['legal_updated'] ) ) ) ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( $entry['contract_url'] ) : ?>
						<a class="button af-btn af-btn--ghost af-btn--sm" href="<?php echo esc_url( $entry['contract_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver contrato', 'arriendo-facil' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<h3 class="af-section__title" style="font-size: var(--af-text-md); margin-top: var(--af-space-5);"><?php esc_html_e( 'Documentos del inmueble', 'arriendo-facil' ); ?></h3>
		<?php if ( empty( $property_docs ) ) : ?>
			<p class="af-td-meta"><?php esc_html_e( 'Sin documentos del inmueble cargados.', 'arriendo-facil' ); ?></p>
		<?php else : ?>
			<div class="af-legal-docs">
				<?php foreach ( $property_docs as $doc ) : ?>
					<a class="af-legal-docs__item" href="<?php echo esc_url( $doc['url'] ); ?>" target="_blank" rel="noopener">
						<span class="af-pill af-pill--neutral"><?php echo esc_html( $document_types_map[ $doc['type'] ] ?? $doc['type'] ); ?></span>
						<span><?php echo esc_html( $doc['label'] ? $doc['label'] : $doc['accommodation_title'] ); ?></span>
						<?php if ( $doc['expires_at'] ) : ?>
							<small><?php echo esc_html( sprintf( /* translators: %s: expiry date */ __( 'Vence %s', 'arriendo-facil' ), $doc['expires_at'] ) ); ?></small>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
</div>


<?php if ( $can_manage && $latest_lease_id ) : ?>
<div class="af-modal" id="af-modal-rate-tenant" role="dialog" aria-modal="true" aria-labelledby="af-modal-rate-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-rate-title"><?php esc_html_e( 'Calificar inquilino', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle"><?php echo esc_html( $full_name ); ?></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-rate-status"></p>
			<?php foreach ( $criteria_labels as $key => $label ) : ?>
				<div class="af-modal__field">
					<label for="af-rate-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
					<select id="af-rate-<?php echo esc_attr( $key ); ?>" data-criterion="<?php echo esc_attr( $key ); ?>">
						<option value="5"><?php esc_html_e( '5 — Excelente', 'arriendo-facil' ); ?></option>
						<option value="4"><?php esc_html_e( '4 — Muy bueno', 'arriendo-facil' ); ?></option>
						<option value="3"><?php esc_html_e( '3 — Aceptable', 'arriendo-facil' ); ?></option>
						<option value="2"><?php esc_html_e( '2 — Deficiente', 'arriendo-facil' ); ?></option>
						<option value="1"><?php esc_html_e( '1 — Muy deficiente', 'arriendo-facil' ); ?></option>
					</select>
				</div>
			<?php endforeach; ?>
			<div class="af-modal__field">
				<label for="af-rate-comment"><?php esc_html_e( 'Comentario (opcional)', 'arriendo-facil' ); ?></label>
				<textarea id="af-rate-comment" rows="3"></textarea>
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-rate-save"><?php esc_html_e( 'Guardar calificación', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>
<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_rate_tenant_nonce' ) ); ?>;
	const modal = document.getElementById('af-modal-rate-tenant');

	function openModal() {
		const suggested = document.getElementById('af-profile-rate-btn').dataset.suggested;
		if (suggested && suggested !== '0') {
			const puntualidad = document.getElementById('af-rate-puntualidad_pago');
			if (puntualidad) { puntualidad.value = suggested; }
		}
		modal.classList.add('is-open');
		document.body.classList.add('af-modal-open');
	}
	function closeModal() {
		modal.classList.remove('is-open');
		document.body.classList.remove('af-modal-open');
	}

	document.getElementById('af-profile-rate-btn').addEventListener('click', openModal);
	modal.querySelectorAll('[data-af-modal-close]').forEach((btn) => btn.addEventListener('click', closeModal));
	document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeModal(); } });

	document.getElementById('af-rate-save').addEventListener('click', function () {
		const status = document.getElementById('af-rate-status');
		const body = new URLSearchParams();
		body.append('action', 'af_rate_tenant');
		body.append('nonce', nonce);
		body.append('lease_id', <?php echo (int) $latest_lease_id; ?>);
		body.append('comment', document.getElementById('af-rate-comment').value);
		modal.querySelectorAll('select[data-criterion]').forEach((sel) => body.append(sel.dataset.criterion, sel.value));

		fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body })
			.then((r) => r.json())
			.then((res) => {
				if (res && res.success) { window.location.reload(); }
				else { status.textContent = (res && res.data && res.data.message) || 'Error'; status.className = 'af-modal__status is-error'; }
			});
	});
})();
</script>
<?php endif; ?>
