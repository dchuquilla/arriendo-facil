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

?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Reputación', 'arriendo-facil' ),
			'title'    => __( 'Valoraciones', 'arriendo-facil' ),
			'subtitle' => __( 'Resumen de reseñas de huéspedes y propietarios. Filtra por dirección para analizar tendencias.', 'arriendo-facil' ),
		)
	);
	?>

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

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Contrato', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Propiedad', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Dirección', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Estrellas', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr>
					<td colspan="6"><?php esc_html_e( 'No hay reseñas completadas para los filtros actuales.', 'arriendo-facil' ); ?></td>
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
					?>
					<tr>
						<td><?php echo esc_html( (int) $row->id ); ?></td>
						<td><?php echo esc_html( (int) $row->lease_id ); ?></td>
						<td><?php echo esc_html( $title ); ?></td>
						<td><span class="af-pill af-pill--neutral"><?php echo esc_html( $label ); ?></span></td>
						<td><span class="af-pill <?php echo esc_attr( $star_pill ); ?>">★ <?php echo esc_html( number_format_i18n( $stars, 1 ) ); ?></span></td>
						<td><?php echo esc_html( isset( $row->submitted_at ) ? (string) $row->submitted_at : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</section>
</div>
