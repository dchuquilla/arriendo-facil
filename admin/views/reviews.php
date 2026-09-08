<?php
/**
 * Reviews admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$is_owner          = Arriendo_Facil_Accommodation::user_is_owner();
$review_table      = $wpdb->prefix . 'af_reviews';
$groups_table      = $wpdb->prefix . 'af_review_groups';
$posts_table       = $wpdb->posts;
$directions_map    = class_exists( 'Arriendo_Facil_Review' ) ? Arriendo_Facil_Review::review_directions() : array();
$direction_filter  = isset( $_GET['direction'] ) ? sanitize_key( wp_unslash( $_GET['direction'] ) ) : '';

$where_clauses = array( 'r.status = %s' );
$where_args    = array( 'completed' );

if ( $is_owner ) {
	$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
	if ( empty( $owner_ids ) ) {
		$owner_ids = array( 0 );
	}
	$ids_sql = implode( ',', array_map( 'intval', $owner_ids ) );
	$where_clauses[] = "r.accommodation_id IN ({$ids_sql})";
}

if ( '' !== $direction_filter ) {
	$where_clauses[] = 'r.review_direction = %s';
	$where_args[]    = $direction_filter;
}

$where_sql = implode( ' AND ', $where_clauses );

$summary_query = "
	SELECT
		COUNT(*) AS total_reviews,
		AVG(r.stars) AS avg_stars,
		SUM(CASE WHEN r.stars >= 4 THEN 1 ELSE 0 END) AS positive_reviews
	FROM {$review_table} r
	WHERE {$where_sql}
";

$summary = $wpdb->get_row( $wpdb->prepare( $summary_query, $where_args ) );
$total_reviews    = isset( $summary->total_reviews ) ? (int) $summary->total_reviews : 0;
$avg_stars        = isset( $summary->avg_stars ) ? (float) $summary->avg_stars : 0.0;
$positive_reviews = isset( $summary->positive_reviews ) ? (int) $summary->positive_reviews : 0;
$positive_rate    = $total_reviews > 0 ? ( $positive_reviews / $total_reviews ) * 100 : 0;

$list_query = "
	SELECT
		r.id,
		r.lease_id,
		r.accommodation_id,
		r.review_direction,
		r.stars,
		r.criteria_scores,
		r.submitted_at,
		r.tenant_email,
		r.owner_user_id,
		p.post_title AS accommodation_title,
		g.reviewer_type
	FROM {$review_table} r
	LEFT JOIN {$posts_table} p ON p.ID = r.accommodation_id
	LEFT JOIN {$groups_table} g ON g.id = r.review_group_id
	WHERE {$where_sql}
	ORDER BY r.submitted_at DESC
	LIMIT 150
";

$rows = $wpdb->get_results( $wpdb->prepare( $list_query, $where_args ) );

$is_management_model = ! ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES );
$criteria_labels     = Arriendo_Facil_Review::owner_to_tenant_criteria();

// Contratos vigentes o terminados que aún no tienen calificación del administrador.
$pending_leases = array();
if ( $is_management_model ) {
	$pending_leases = (array) $wpdb->get_results(
		"SELECT l.id, l.start_date, l.end_date, l.status,
		        p.post_title AS accommodation_title,
		        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
		 FROM {$wpdb->prefix}af_leases l
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
		 LEFT JOIN {$review_table} r
		        ON r.lease_id = l.id
		       AND r.review_direction = 'owner_to_tenant'
		       AND r.status = 'completed'
		 WHERE l.deleted_at IS NULL
		   AND l.status IN ('active', 'terminated')
		   AND r.id IS NULL
		 ORDER BY l.end_date ASC
		 LIMIT 100"
	);
}
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Reputación', 'arriendo-facil' ),
			'title'    => __( 'Calificación de inquilinos', 'arriendo-facil' ),
			'subtitle' => __( 'Evalúa el comportamiento de cada inquilino: pago, cuidado del inmueble, convivencia y comunicación.', 'arriendo-facil' ),
		)
	);
	?>

	<?php if ( $is_management_model && ! empty( $pending_leases ) ) : ?>
		<section class="af-section" style="margin-bottom: var(--af-space-4);">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title"><?php esc_html_e( 'Pendientes de calificar', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'La puntualidad de pago se sugiere automáticamente desde el historial de cobranza.', 'arriendo-facil' ); ?></p>
				</div>
			</header>

			<table class="wp-list-table widefat fixed striped af-data-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Inquilino', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Vigencia', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Historial de pago', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Acción', 'arriendo-facil' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pending_leases as $pending_lease ) : ?>
						<?php $payment_hint = Arriendo_Facil_Review::suggest_payment_score( (int) $pending_lease->id ); ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><?php echo esc_html( $pending_lease->accommodation_title ? $pending_lease->accommodation_title : '—' ); ?></td>
							<td data-label="<?php esc_attr_e( 'Inquilino', 'arriendo-facil' ); ?>"><strong><?php echo esc_html( trim( (string) $pending_lease->guest_name ) ); ?></strong></td>
							<td data-label="<?php esc_attr_e( 'Vigencia', 'arriendo-facil' ); ?>">
								<?php echo esc_html( $pending_lease->start_date . ' → ' . $pending_lease->end_date ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Historial de pago', 'arriendo-facil' ); ?>">
								<?php if ( $payment_hint ) : ?>
									<span class="af-pill <?php echo $payment_hint['score'] >= 4 ? 'af-pill--success' : ( $payment_hint['score'] >= 3 ? 'af-pill--warning' : 'af-pill--danger' ); ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: on-time payments, 2: total charges */
												__( '%1$d de %2$d a tiempo', 'arriendo-facil' ),
												$payment_hint['on_time'],
												$payment_hint['total']
											)
										);
										?>
									</span>
								<?php else : ?>
									<span class="af-td-meta"><?php esc_html_e( 'Sin historial suficiente', 'arriendo-facil' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Acción', 'arriendo-facil' ); ?>">
								<?php if ( current_user_can( 'manage_options' ) ) : ?>
									<button type="button" class="button button-primary af-rate-tenant"
										data-lease="<?php echo esc_attr( (int) $pending_lease->id ); ?>"
										data-tenant="<?php echo esc_attr( trim( (string) $pending_lease->guest_name ) ); ?>"
										data-suggested="<?php echo esc_attr( $payment_hint ? $payment_hint['score'] : 0 ); ?>">
										<?php esc_html_e( 'Calificar', 'arriendo-facil' ); ?>
									</button>
								<?php else : ?>
									<span class="af-td-meta">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>

	<?php if ( ! $is_management_model ) : ?>
	<form method="get" class="af-section" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding: var(--af-space-4) var(--af-space-5); margin-bottom: var(--af-space-4);">
		<input type="hidden" name="page" value="af-reviews" />
		<label style="display:flex; align-items:center; gap:8px; font-weight:600; color: var(--af-gray-700);">
			<?php esc_html_e( 'Dirección', 'arriendo-facil' ); ?>
			<select name="direction" style="min-height:36px;">
				<option value=""><?php esc_html_e( 'Todas', 'arriendo-facil' ); ?></option>
				<?php foreach ( $directions_map as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $direction_filter, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Filtrar', 'arriendo-facil' ); ?></button>
		<a class="button af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-reviews' ) ); ?>"><?php esc_html_e( 'Limpiar', 'arriendo-facil' ); ?></a>
	</form>
	<?php endif; ?>

	<div class="af-kpi-grid">

		<article class="af-kpi">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Reseñas completadas', 'arriendo-facil' ); ?></span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $total_reviews ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Total en el filtro actual', 'arriendo-facil' ); ?></div>
		</article>

		<article class="af-kpi af-kpi--accent">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Promedio general', 'arriendo-facil' ); ?></span>
				<span class="af-kpi__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3 6.5 7 .9-5.1 4.7 1.3 7L12 17.8 5.8 21l1.3-7L2 9.4l7-.9L12 2z"/></svg>
				</span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( $total_reviews > 0 ? number_format_i18n( $avg_stars, 2 ) : '—' ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Estrellas sobre 5', 'arriendo-facil' ); ?></div>
		</article>

		<article class="af-kpi af-kpi--success">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Reseñas positivas', 'arriendo-facil' ); ?></span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $positive_reviews ) ); ?></div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Con 4 o más estrellas', 'arriendo-facil' ); ?></div>
		</article>

		<article class="af-kpi <?php echo $positive_rate >= 80 ? 'af-kpi--success' : ( $positive_rate >= 60 ? '' : 'af-kpi--attention' ); ?>">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Tasa positiva', 'arriendo-facil' ); ?></span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $positive_rate, 1 ) ); ?>%</div>
			<div class="af-kpi__hint"><?php esc_html_e( 'Objetivo mínimo: 80%', 'arriendo-facil' ); ?></div>
		</article>

	</div>

	<section class="af-section">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Detalle de reseñas', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Últimas 150 completadas.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

	<table class="wp-list-table widefat fixed striped af-data-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Contrato', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Propiedad', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Dirección', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Estrellas', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Detalle', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No hay reseñas completadas para los filtros actuales.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$direction = isset( $row->review_direction ) ? sanitize_key( (string) $row->review_direction ) : '';
					$label     = isset( $directions_map[ $direction ] ) ? $directions_map[ $direction ] : $direction;
					$title     = isset( $row->accommodation_title ) && '' !== trim( (string) $row->accommodation_title ) ? (string) $row->accommodation_title : '#' . absint( $row->accommodation_id );
					$stars     = (float) $row->stars;
					$star_pill = 'af-pill--danger';
					if ( $stars >= 4 ) {
						$star_pill = 'af-pill--success';
					} elseif ( $stars >= 3 ) {
						$star_pill = 'af-pill--warning';
					}
					$criteria_labels = Arriendo_Facil_Review::owner_to_tenant_criteria();
					$criteria_scores = 'owner_to_tenant' === $direction && ! empty( $row->criteria_scores ) ? json_decode( (string) $row->criteria_scores, true ) : null;
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'ID', 'arriendo-facil' ); ?>"><?php echo esc_html( (int) $row->id ); ?></td>
						<td data-label="<?php esc_attr_e( 'Contrato', 'arriendo-facil' ); ?>"><?php echo esc_html( (int) $row->lease_id ); ?></td>
						<td data-label="<?php esc_attr_e( 'Propiedad', 'arriendo-facil' ); ?>"><?php echo esc_html( $title ); ?></td>
						<td data-label="<?php esc_attr_e( 'Dirección', 'arriendo-facil' ); ?>"><span class="af-pill af-pill--neutral"><?php echo esc_html( $label ); ?></span></td>
						<td data-label="<?php esc_attr_e( 'Estrellas', 'arriendo-facil' ); ?>"><span class="af-pill <?php echo esc_attr( $star_pill ); ?>">★ <?php echo esc_html( number_format_i18n( $stars, 1 ) ); ?></span></td>
						<td data-label="<?php esc_attr_e( 'Detalle', 'arriendo-facil' ); ?>">
							<?php if ( is_array( $criteria_scores ) ) : ?>
								<ul style="margin:0;padding-left:16px;">
									<?php foreach ( $criteria_labels as $crit_key => $crit_label ) : ?>
										<?php if ( isset( $criteria_scores[ $crit_key ] ) ) : ?>
											<li><?php echo esc_html( $crit_label ); ?>: <?php echo esc_html( (int) $criteria_scores[ $crit_key ] ); ?>/5</li>
										<?php endif; ?>
									<?php endforeach; ?>
								</ul>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Fecha', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $row->submitted_at ) ? (string) $row->submitted_at : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</section>
</div>

<?php if ( $is_management_model ) : ?>
<div class="af-modal" id="af-modal-rate" role="dialog" aria-modal="true" aria-labelledby="af-modal-rate-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-modal-rate-title"><?php esc_html_e( 'Calificar inquilino', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-rate-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-rate-status"></p>

			<?php foreach ( $criteria_labels as $criterion_key => $criterion_label ) : ?>
				<div class="af-modal__field">
					<label>
						<?php echo esc_html( $criterion_label ); ?>
						<?php if ( 'puntualidad_pago' === $criterion_key ) : ?>
							<span class="af-modal__hint" id="af-rate-suggested" style="display:none;"></span>
						<?php endif; ?>
					</label>
					<div style="display:flex; gap:12px; flex-wrap:wrap;">
						<?php for ( $star = 1; $star <= 5; $star++ ) : ?>
							<label style="display:flex; align-items:center; gap:5px;">
								<input type="radio" name="af_rate_<?php echo esc_attr( $criterion_key ); ?>" value="<?php echo esc_attr( $star ); ?>" <?php checked( 5 === $star ); ?> />
								<span><?php echo esc_html( $star ); ?>★</span>
							</label>
						<?php endfor; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<div class="af-modal__field">
				<label for="af-rate-comment"><?php esc_html_e( 'Observaciones', 'arriendo-facil' ); ?></label>
				<textarea id="af-rate-comment" rows="3" placeholder="<?php esc_attr_e( 'Ej: pagó puntual todo el contrato, entregó el inmueble en buen estado.', 'arriendo-facil' ); ?>"></textarea>
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			<button type="button" class="button button-primary" id="af-rate-confirm"><?php esc_html_e( 'Guardar calificación', 'arriendo-facil' ); ?></button>
		</div>
	</div>
</div>

<script>
(function () {
	const modal = document.getElementById('af-modal-rate');
	if (!modal) { return; }

	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_rate_tenant_nonce' ) ); ?>;
	const criteria = <?php echo wp_json_encode( array_keys( $criteria_labels ) ); ?>;

	const status = document.getElementById('af-rate-status');
	const subtitle = document.getElementById('af-rate-subtitle');
	const suggested = document.getElementById('af-rate-suggested');
	const comment = document.getElementById('af-rate-comment');
	const confirm = document.getElementById('af-rate-confirm');
	let leaseId = 0;

	modal.querySelectorAll('[data-af-modal-close]').forEach(function (btn) {
		btn.addEventListener('click', function () { modal.classList.remove('is-open'); });
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { modal.classList.remove('is-open'); }
	});

	document.querySelectorAll('.af-rate-tenant').forEach(function (btn) {
		btn.addEventListener('click', function () {
			leaseId = btn.getAttribute('data-lease');
			subtitle.textContent = btn.getAttribute('data-tenant');
			comment.value = '';
			status.textContent = '';
			status.className = 'af-modal__status';

			// Preselect the punctuality score derived from the ledger.
			const score = parseInt(btn.getAttribute('data-suggested'), 10);
			if (score >= 1 && score <= 5) {
				const input = modal.querySelector('input[name="af_rate_puntualidad_pago"][value="' + score + '"]');
				if (input) { input.checked = true; }
				suggested.textContent = <?php echo wp_json_encode( __( '— sugerido desde el historial de cobranza', 'arriendo-facil' ) ); ?>;
				suggested.style.display = 'inline';
			} else {
				suggested.style.display = 'none';
			}

			modal.classList.add('is-open');
		});
	});

	confirm.addEventListener('click', function () {
		confirm.disabled = true;
		status.textContent = '';
		status.className = 'af-modal__status';

		const body = new URLSearchParams();
		body.append('action', 'af_rate_tenant');
		body.append('nonce', nonce);
		body.append('lease_id', leaseId);
		body.append('comment', comment.value);

		criteria.forEach(function (key) {
			const checked = modal.querySelector('input[name="af_rate_' + key + '"]:checked');
			body.append(key, checked ? checked.value : '0');
		});

		fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then((r) => r.json()).then(function (json) {
			confirm.disabled = false;
			if (!json || !json.success) {
				status.textContent = (json && json.data && json.data.message) || 'Error';
				status.className = 'af-modal__status is-error';
				return;
			}
			status.textContent = json.data.message;
			status.className = 'af-modal__status is-success';
			setTimeout(function () { window.location.reload(); }, 800);
		});
	});
}());
</script>
<?php endif; ?>
