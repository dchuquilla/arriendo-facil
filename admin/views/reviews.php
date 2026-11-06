<?php
/**
 * Calificación de inquilinos — vista del panel.
 *
 * Enfoque: reputación interna y privada. El gestor evalúa a cada inquilino
 * (y el super-admin evalúa a los gestores) para decidir renovaciones.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$is_owner            = Arriendo_Facil_Accommodation::user_is_owner();
$is_management_model = ! ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES );
$is_super_admin      = current_user_can( 'manage_options' );
$can_rate            = current_user_can( Arriendo_Facil_Tenancy::CAP );

$review_table   = $wpdb->prefix . 'af_reviews';
$groups_table   = $wpdb->prefix . 'af_review_groups';
$directions_map = Arriendo_Facil_Review::review_directions();
$criteria_map   = Arriendo_Facil_Review::owner_to_tenant_criteria();
$admin_criteria = Arriendo_Facil_Review::admin_criteria();

$direction_filter = isset( $_GET['direction'] ) ? sanitize_key( wp_unslash( $_GET['direction'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Alcance de datos ────────────────────────────────────────────────────────
$where_clauses = array( 'r.status = %s' );
$where_args    = array( 'completed' );

if ( $is_management_model ) {
	$where_clauses[] = 'r.review_direction = %s';
	$where_args[]    = 'owner_to_tenant';
	$accessible_ids  = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
	$scope_ids       = $accessible_ids;
} else {
	if ( '' !== $direction_filter ) {
		$where_clauses[] = 'r.review_direction = %s';
		$where_args[]    = $direction_filter;
	}
	$scope_ids = $is_owner ? Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() ) : null;
}

if ( null !== $scope_ids ) {
	$scope_ids       = empty( $scope_ids ) ? array( 0 ) : $scope_ids;
	$where_clauses[] = 'r.accommodation_id IN (' . implode( ',', array_map( 'intval', $scope_ids ) ) . ')';
}

$where_sql = implode( ' AND ', $where_clauses );

$rows = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT r.*, p.post_title AS accommodation_title,
		        l.start_date, l.end_date,
		        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
		 FROM {$review_table} r
		 LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
		 LEFT JOIN {$wpdb->prefix}af_leases l ON l.id = r.lease_id
		 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
		 WHERE {$where_sql}
		 ORDER BY r.submitted_at DESC
		 LIMIT 300", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$where_args
	)
);

// ── Contratos pendientes de calificar ───────────────────────────────────────
$pending_leases = array();
if ( $is_management_model ) {
	$scope_clause   = null === $scope_ids ? '' : ' AND l.accommodation_id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $scope_ids ) . ')';
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
		   AND r.id IS NULL{$scope_clause}
		 ORDER BY (l.status = 'terminated') DESC, l.end_date ASC
		 LIMIT 100" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);
}

// ── Evaluación de gestores (super-admin) / mi evaluación (gestor) ───────────
$admins                = array();
$admin_evaluations     = array();
$admin_property_counts = array();
$my_evaluation         = null;

if ( $is_management_model && $is_super_admin ) {
	$admins    = (array) Arriendo_Facil_Tenancy::get_property_admins();
	$admin_ids = array_map( 'absint', wp_list_pluck( $admins, 'ID' ) );

	if ( ! empty( $admin_ids ) ) {
		$admin_ids_sql   = implode( ',', $admin_ids );
		$evaluation_rows = (array) $wpdb->get_results(
			"SELECT id, admin_user_id, stars, criteria_scores, comment_text, updated_at
			 FROM {$wpdb->prefix}af_admin_reviews
			 WHERE admin_user_id IN ({$admin_ids_sql})" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		foreach ( $evaluation_rows as $evaluation_row ) {
			$admin_evaluations[ (int) $evaluation_row->admin_user_id ] = $evaluation_row;
		}

		$count_rows = (array) $wpdb->get_results(
			"SELECT pm.meta_value AS owner_id, COUNT(DISTINCT p.ID) AS total
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'accommodation' AND p.post_status NOT IN ('trash','auto-draft')
			 WHERE pm.meta_key = '_af_owner_id' AND pm.meta_value IN ('" . implode( "','", $admin_ids ) . "')
			 GROUP BY pm.meta_value" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		foreach ( $count_rows as $count_row ) {
			$admin_property_counts[ (int) $count_row->owner_id ] = (int) $count_row->total;
		}
	}
} elseif ( $is_management_model && in_array( 'af_property_admin', (array) wp_get_current_user()->roles, true ) ) {
	$my_evaluation = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, stars, criteria_scores, comment_text, updated_at
			 FROM {$wpdb->prefix}af_admin_reviews
			 WHERE admin_user_id = %d
			 LIMIT 1",
			get_current_user_id()
		)
	);
}

// ── Helpers de presentación ─────────────────────────────────────────────────
$tone_for = static function ( $score ) {
	if ( $score <= 0 ) {
		return 'none';
	}
	return $score >= 4 ? 'good' : ( $score >= 3 ? 'mid' : 'bad' );
};

$verdict_for = static function ( $score ) {
	if ( $score <= 0 ) {
		return '';
	}
	if ( $score >= 4.5 ) {
		return __( 'Excelente', 'arriendo-facil' );
	}
	if ( $score >= 3.5 ) {
		return __( 'Muy bueno', 'arriendo-facil' );
	}
	if ( $score >= 2.5 ) {
		return __( 'Regular', 'arriendo-facil' );
	}
	return __( 'Requiere atención', 'arriendo-facil' );
};

$initials_for = static function ( $name ) {
	$parts = preg_split( '/\s+/', trim( (string) $name ), -1, PREG_SPLIT_NO_EMPTY );
	if ( empty( $parts ) ) {
		return '?';
	}
	$first = function_exists( 'mb_substr' ) ? mb_substr( $parts[0], 0, 1 ) : substr( $parts[0], 0, 1 );
	$last  = count( $parts ) > 1 ? ( function_exists( 'mb_substr' ) ? mb_substr( end( $parts ), 0, 1 ) : substr( end( $parts ), 0, 1 ) ) : '';
	return strtoupper( $first . $last );
};

$format_date = static function ( $date, $is_gmt = false ) {
	if ( empty( $date ) || '0000-00-00' === substr( (string) $date, 0, 10 ) ) {
		return '—';
	}
	$ts = strtotime( (string) $date . ( $is_gmt ? ' UTC' : '' ) );
	return $ts ? wp_date( 'j M Y', $ts ) : '—';
};

$scores_of = static function ( $json ) {
	$decoded = ! empty( $json ) ? json_decode( (string) $json, true ) : array();
	return is_array( $decoded ) ? $decoded : array();
};

// Promedio preciso: de los criterios si existen, si no de las estrellas guardadas.
$score_of_row = static function ( $row, array $criteria ) use ( $scores_of ) {
	$scores = $scores_of( isset( $row->criteria_scores ) ? $row->criteria_scores : '' );
	$values = array();
	foreach ( array_keys( $criteria ) as $key ) {
		if ( isset( $scores[ $key ] ) && (int) $scores[ $key ] > 0 ) {
			$values[] = (int) $scores[ $key ];
		}
	}
	return empty( $values ) ? (float) $row->stars : array_sum( $values ) / count( $values );
};

// ── Agregados ───────────────────────────────────────────────────────────────
$total_reviews = count( $rows );
$sum_scores    = 0.0;
$distribution  = array( 5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0 );
$criteria_sum  = array_fill_keys( array_keys( $criteria_map ), 0 );
$criteria_n    = array_fill_keys( array_keys( $criteria_map ), 0 );
$tone_counts   = array( 'good' => 0, 'mid' => 0, 'bad' => 0, 'none' => 0 );

foreach ( $rows as $row ) {
	$row->_score = $score_of_row( $row, $criteria_map );
	$sum_scores += $row->_score;
	$bucket      = max( 1, min( 5, (int) round( $row->_score ) ) );
	++$distribution[ $bucket ];
	++$tone_counts[ $tone_for( $row->_score ) ];

	$row_scores = $scores_of( $row->criteria_scores );
	foreach ( array_keys( $criteria_map ) as $key ) {
		if ( isset( $row_scores[ $key ] ) && (int) $row_scores[ $key ] > 0 ) {
			$criteria_sum[ $key ] += (int) $row_scores[ $key ];
			++$criteria_n[ $key ];
		}
	}
}

$avg_score      = $total_reviews > 0 ? $sum_scores / $total_reviews : 0.0;
$positive_rate  = $total_reviews > 0 ? round( 100 * $tone_counts['good'] / $total_reviews ) : 0;
$pending_count  = count( $pending_leases );
$overdue_leases = 0;
foreach ( $pending_leases as $pl ) {
	if ( 'terminated' === $pl->status ) {
		++$overdue_leases;
	}
}

$show_managers_tab = $is_management_model && ! empty( $admins );
$default_tab       = $pending_count > 0 ? 'pending' : 'rated';
if ( ! $is_management_model ) {
	$default_tab = 'rated';
}

$config = array(
	'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
	'tenant'   => array(
		'action'   => 'af_rate_tenant',
		'nonce'    => wp_create_nonce( 'af_rate_tenant_nonce' ),
		'idField'  => 'lease_id',
		'criteria' => $criteria_map,
		'hints'    => array(
			'puntualidad_pago' => __( 'Pagos a tiempo y sin recordatorios', 'arriendo-facil' ),
			'cuidado_inmueble' => __( 'Estado del inmueble y sus bienes', 'arriendo-facil' ),
			'convivencia'      => __( 'Trato con vecinos y respeto de normas', 'arriendo-facil' ),
			'comunicacion'     => __( 'Respuesta rápida y trato cordial', 'arriendo-facil' ),
		),
		'title'    => __( 'Calificar inquilino', 'arriendo-facil' ),
		'save'     => __( 'Guardar calificación', 'arriendo-facil' ),
		'placeholder' => __( 'Ej: pagó puntual todo el contrato y entregó el inmueble en buen estado.', 'arriendo-facil' ),
	),
	'admin'    => array(
		'action'   => 'af_rate_admin',
		'nonce'    => wp_create_nonce( 'af_rate_admin_nonce' ),
		'idField'  => 'admin_user_id',
		'criteria' => $admin_criteria,
		'hints'    => array(),
		'title'    => __( 'Evaluar gestor', 'arriendo-facil' ),
		'save'     => __( 'Guardar evaluación', 'arriendo-facil' ),
		'placeholder' => __( 'Ej: gestiona la cobranza puntualmente y responde rápido.', 'arriendo-facil' ),
	),
	'labels'   => array(
		1          => __( 'Deficiente', 'arriendo-facil' ),
		2          => __( 'Regular', 'arriendo-facil' ),
		3          => __( 'Bueno', 'arriendo-facil' ),
		4          => __( 'Muy bueno', 'arriendo-facil' ),
		5          => __( 'Excelente', 'arriendo-facil' ),
		'overall'  => __( 'Calificación general', 'arriendo-facil' ),
		'pick'     => __( 'Califica los 4 criterios', 'arriendo-facil' ),
		'missing'  => __( 'Falta calificar: %s', 'arriendo-facil' ),
		'suggest'  => __( 'Sugerencia del historial de cobranza', 'arriendo-facil' ),
		'applied'  => __( 'Aplicada a "Puntualidad de pago"', 'arriendo-facil' ),
		'apply'    => __( 'Aplicar', 'arriendo-facil' ),
		'saving'   => __( 'Guardando…', 'arriendo-facil' ),
		'error'    => __( 'No se pudo guardar. Inténtalo de nuevo.', 'arriendo-facil' ),
		'noMatch'  => __( 'Ningún resultado coincide con tu búsqueda.', 'arriendo-facil' ),
	),
);
?>
<div class="wrap af-shell af-reviews-page">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Reputación · Privado', 'arriendo-facil' ),
			'title'    => __( 'Calificación de inquilinos', 'arriendo-facil' ),
			'subtitle' => __( 'Tu historial interno de cada inquilino: puntualidad, cuidado del inmueble, convivencia y comunicación. Solo lo ves tú y te ayuda a decidir renovaciones.', 'arriendo-facil' ),
		)
	);
	?>

	<?php if ( ! $is_management_model ) : ?>
		<form method="get" class="af-rv-legacy-filter">
			<input type="hidden" name="page" value="af-reviews" />
			<label>
				<span><?php esc_html_e( 'Dirección', 'arriendo-facil' ); ?></span>
				<select name="direction">
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

	<!-- ── Resumen ───────────────────────────────────────────────────────── -->
	<section class="af-rv-summary" aria-label="<?php esc_attr_e( 'Resumen de calificaciones', 'arriendo-facil' ); ?>">

		<div class="af-rv-card af-rv-overall">
			<span class="af-rv-eyebrow"><?php esc_html_e( 'Promedio de tus inquilinos', 'arriendo-facil' ); ?></span>
			<?php if ( $total_reviews > 0 ) : ?>
				<div class="af-rv-overall__main">
					<strong class="af-rv-overall__value"><?php echo esc_html( number_format_i18n( $avg_score, 1 ) ); ?></strong>
					<div>
						<span class="af-rv-stars" style="--pct: <?php echo esc_attr( round( $avg_score / 5 * 100 ) ); ?>%" aria-hidden="true"></span>
						<p class="af-rv-overall__verdict"><?php echo esc_html( $verdict_for( $avg_score ) ); ?> · <?php echo esc_html( sprintf( /* translators: %s: number of ratings */ _n( '%s calificación', '%s calificaciones', $total_reviews, 'arriendo-facil' ), number_format_i18n( $total_reviews ) ) ); ?></p>
					</div>
				</div>
				<ul class="af-rv-dist" aria-label="<?php esc_attr_e( 'Distribución de estrellas', 'arriendo-facil' ); ?>">
					<?php foreach ( $distribution as $star => $count ) : ?>
						<li>
							<span class="af-rv-dist__label"><?php echo esc_html( $star ); ?>★</span>
							<span class="af-rv-dist__bar"><span style="width: <?php echo esc_attr( round( 100 * $count / $total_reviews ) ); ?>%"></span></span>
							<span class="af-rv-dist__count"><?php echo esc_html( $count ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="af-rv-muted"><?php esc_html_e( 'Aún no hay calificaciones. Cuando califiques a tu primer inquilino verás aquí su promedio y distribución.', 'arriendo-facil' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="af-rv-card af-rv-criteria">
			<span class="af-rv-eyebrow"><?php esc_html_e( 'Qué evalúas mejor y peor', 'arriendo-facil' ); ?></span>
			<?php if ( $total_reviews > 0 ) : ?>
				<ul>
					<?php foreach ( $criteria_map as $key => $label ) : ?>
						<?php
						$crit_avg  = $criteria_n[ $key ] > 0 ? $criteria_sum[ $key ] / $criteria_n[ $key ] : 0;
						$crit_tone = $tone_for( $crit_avg );
						?>
						<li>
							<div class="af-rv-criteria__row">
								<span><?php echo esc_html( $label ); ?></span>
								<strong><?php echo esc_html( $crit_avg > 0 ? number_format_i18n( $crit_avg, 1 ) : '—' ); ?></strong>
							</div>
							<span class="af-rv-meter af-rv-tone--<?php echo esc_attr( $crit_tone ); ?>"><span style="width: <?php echo esc_attr( round( $crit_avg / 5 * 100 ) ); ?>%"></span></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="af-rv-muted"><?php esc_html_e( 'Se mostrará el promedio por criterio.', 'arriendo-facil' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="af-rv-card af-rv-stats">
			<button type="button" class="af-rv-stat <?php echo $pending_count > 0 ? 'is-alert' : ''; ?>" data-rv-goto="pending"<?php echo $is_management_model ? '' : ' hidden'; ?>>
				<span class="af-rv-stat__value"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></span>
				<span class="af-rv-stat__label"><?php esc_html_e( 'Por calificar', 'arriendo-facil' ); ?></span>
				<?php if ( $overdue_leases > 0 ) : ?>
					<span class="af-rv-stat__hint"><?php echo esc_html( sprintf( /* translators: %d: number of finished leases */ _n( '%d con contrato terminado', '%d con contrato terminado', $overdue_leases, 'arriendo-facil' ), $overdue_leases ) ); ?></span>
				<?php endif; ?>
			</button>
			<button type="button" class="af-rv-stat" data-rv-goto="rated">
				<span class="af-rv-stat__value"><?php echo esc_html( number_format_i18n( $total_reviews ) ); ?></span>
				<span class="af-rv-stat__label"><?php esc_html_e( 'Calificados', 'arriendo-facil' ); ?></span>
			</button>
			<div class="af-rv-stat af-rv-stat--static af-rv-tone--<?php echo esc_attr( $positive_rate >= 80 ? 'good' : ( $positive_rate >= 60 ? 'mid' : ( $total_reviews > 0 ? 'bad' : 'none' ) ) ); ?>">
				<span class="af-rv-stat__value"><?php echo esc_html( $total_reviews > 0 ? $positive_rate . '%' : '—' ); ?></span>
				<span class="af-rv-stat__label"><?php esc_html_e( 'Inquilinos 4★ o más', 'arriendo-facil' ); ?></span>
			</div>
		</div>
	</section>

	<!-- ── Barra de herramientas ─────────────────────────────────────────── -->
	<div class="af-rv-toolbar">
		<div class="af-rv-tabs" role="tablist">
			<?php if ( $is_management_model ) : ?>
				<button type="button" role="tab" class="af-rv-tab" data-rv-tab="pending" aria-selected="false">
					<?php esc_html_e( 'Por calificar', 'arriendo-facil' ); ?>
					<span class="af-rv-tab__count<?php echo $pending_count > 0 ? ' is-alert' : ''; ?>"><?php echo esc_html( $pending_count ); ?></span>
				</button>
			<?php endif; ?>
			<button type="button" role="tab" class="af-rv-tab" data-rv-tab="rated" aria-selected="false">
				<?php esc_html_e( 'Calificados', 'arriendo-facil' ); ?>
				<span class="af-rv-tab__count"><?php echo esc_html( $total_reviews ); ?></span>
			</button>
			<?php if ( $show_managers_tab ) : ?>
				<button type="button" role="tab" class="af-rv-tab" data-rv-tab="managers" aria-selected="false">
					<?php esc_html_e( 'Gestores', 'arriendo-facil' ); ?>
					<span class="af-rv-tab__count"><?php echo esc_html( count( $admins ) ); ?></span>
				</button>
			<?php elseif ( $my_evaluation ) : ?>
				<button type="button" role="tab" class="af-rv-tab" data-rv-tab="mine" aria-selected="false"><?php esc_html_e( 'Mi evaluación', 'arriendo-facil' ); ?></button>
			<?php endif; ?>
		</div>

		<div class="af-rv-toolbar__tools">
			<div class="af-rv-chips" data-rv-chips="rated" hidden>
				<button type="button" class="af-rv-chip is-active" data-rv-filter=""><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?></button>
				<button type="button" class="af-rv-chip" data-rv-filter="good"><?php esc_html_e( 'Positivos', 'arriendo-facil' ); ?> <em><?php echo esc_html( $tone_counts['good'] ); ?></em></button>
				<button type="button" class="af-rv-chip" data-rv-filter="mid"><?php esc_html_e( 'Regulares', 'arriendo-facil' ); ?> <em><?php echo esc_html( $tone_counts['mid'] ); ?></em></button>
				<button type="button" class="af-rv-chip" data-rv-filter="bad"><?php esc_html_e( 'Atención', 'arriendo-facil' ); ?> <em><?php echo esc_html( $tone_counts['bad'] ); ?></em></button>
			</div>
			<label class="af-rv-search">
				<span class="screen-reader-text"><?php esc_html_e( 'Buscar', 'arriendo-facil' ); ?></span>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
				<input type="search" id="af-rv-search" placeholder="<?php esc_attr_e( 'Buscar inquilino o inmueble…', 'arriendo-facil' ); ?>" autocomplete="off" />
			</label>
		</div>
	</div>

	<!-- ── Panel: por calificar ──────────────────────────────────────────── -->
	<?php if ( $is_management_model ) : ?>
		<section class="af-rv-panel" data-rv-panel="pending" hidden>
			<?php if ( empty( $pending_leases ) ) : ?>
				<div class="af-rv-empty">
					<span class="af-rv-empty__icon" aria-hidden="true">✓</span>
					<h3><?php esc_html_e( '¡Todo al día!', 'arriendo-facil' ); ?></h3>
					<p><?php esc_html_e( 'No tienes inquilinos pendientes de calificar. Los nuevos contratos activos aparecerán aquí.', 'arriendo-facil' ); ?></p>
				</div>
			<?php else : ?>
				<div class="af-rv-grid">
					<?php foreach ( $pending_leases as $pending_lease ) : ?>
						<?php
						$tenant_name  = trim( (string) $pending_lease->guest_name );
						$tenant_name  = '' !== $tenant_name ? $tenant_name : __( 'Inquilino sin nombre', 'arriendo-facil' );
						$property     = $pending_lease->accommodation_title ? $pending_lease->accommodation_title : '—';
						$payment_hint = Arriendo_Facil_Review::suggest_payment_score( (int) $pending_lease->id );
						$is_finished  = 'terminated' === $pending_lease->status;
						$hint_tone    = $payment_hint ? $tone_for( $payment_hint['score'] ) : 'none';
						$on_time_pct  = $payment_hint ? round( 100 * $payment_hint['on_time'] / max( 1, $payment_hint['total'] ) ) : 0;
						?>
						<article class="af-rv-card af-rv-tenant" data-rv-item data-search="<?php echo esc_attr( strtolower( $tenant_name . ' ' . $property ) ); ?>">
							<header class="af-rv-tenant__head">
								<span class="af-rv-avatar" aria-hidden="true"><?php echo esc_html( $initials_for( $tenant_name ) ); ?></span>
								<div class="af-rv-tenant__id">
									<h3><?php echo esc_html( $tenant_name ); ?></h3>
									<p><?php echo esc_html( $property ); ?></p>
								</div>
								<span class="af-rv-badge <?php echo $is_finished ? 'af-rv-badge--warn' : 'af-rv-badge--ok'; ?>">
									<?php echo $is_finished ? esc_html__( 'Contrato terminado', 'arriendo-facil' ) : esc_html__( 'Contrato activo', 'arriendo-facil' ); ?>
								</span>
							</header>

							<p class="af-rv-tenant__dates">
								<?php echo esc_html( $format_date( $pending_lease->start_date ) . ' → ' . $format_date( $pending_lease->end_date ) ); ?>
							</p>

							<div class="af-rv-payment af-rv-tone--<?php echo esc_attr( $hint_tone ); ?>">
								<?php if ( $payment_hint ) : ?>
									<div class="af-rv-payment__top">
										<span><?php esc_html_e( 'Historial de pago', 'arriendo-facil' ); ?></span>
										<strong><?php echo esc_html( sprintf( /* translators: %d: percentage */ __( '%d%% a tiempo', 'arriendo-facil' ), $on_time_pct ) ); ?></strong>
									</div>
									<span class="af-rv-meter"><span style="width: <?php echo esc_attr( $on_time_pct ); ?>%"></span></span>
									<p class="af-rv-muted">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: on-time count, 2: late count, 3: average days late */
												__( '%1$d a tiempo · %2$d con atraso · %3$s días de atraso promedio', 'arriendo-facil' ),
												$payment_hint['on_time'],
												$payment_hint['late'],
												number_format_i18n( $payment_hint['average_days_late'], 1 )
											)
										);
										?>
									</p>
								<?php else : ?>
									<div class="af-rv-payment__top"><span><?php esc_html_e( 'Historial de pago', 'arriendo-facil' ); ?></span></div>
									<p class="af-rv-muted"><?php esc_html_e( 'Sin historial suficiente para sugerir una nota.', 'arriendo-facil' ); ?></p>
								<?php endif; ?>
							</div>

							<footer class="af-rv-tenant__foot">
								<?php if ( $can_rate ) : ?>
									<button type="button" class="button button-primary af-rv-cta"
										data-rv-open="tenant"
										data-id="<?php echo esc_attr( (int) $pending_lease->id ); ?>"
										data-name="<?php echo esc_attr( $tenant_name ); ?>"
										data-context="<?php echo esc_attr( $property ); ?>"
										data-suggested="<?php echo esc_attr( $payment_hint ? $payment_hint['score'] : 0 ); ?>"
										data-suggested-note="<?php echo esc_attr( $payment_hint ? sprintf( /* translators: 1: percentage on time, 2: avg days late */ __( '%1$d%% de pagos a tiempo · %2$s días de atraso promedio', 'arriendo-facil' ), $on_time_pct, number_format_i18n( $payment_hint['average_days_late'], 1 ) ) : '' ); ?>">
										<?php esc_html_e( 'Calificar', 'arriendo-facil' ); ?>
									</button>
								<?php endif; ?>
							</footer>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<!-- ── Panel: calificados ────────────────────────────────────────────── -->
	<section class="af-rv-panel" data-rv-panel="rated" hidden>
		<?php if ( empty( $rows ) ) : ?>
			<div class="af-rv-empty">
				<span class="af-rv-empty__icon" aria-hidden="true">★</span>
				<h3><?php esc_html_e( 'Aún no has calificado inquilinos', 'arriendo-facil' ); ?></h3>
				<p><?php esc_html_e( 'Tus calificaciones quedan como historial privado de cada inquilino.', 'arriendo-facil' ); ?></p>
				<?php if ( $pending_count > 0 ) : ?>
					<button type="button" class="button button-primary" data-rv-goto="pending"><?php esc_html_e( 'Ver pendientes', 'arriendo-facil' ); ?></button>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<div class="af-rv-grid">
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$direction   = sanitize_key( (string) $row->review_direction );
					$tenant_name = trim( (string) $row->guest_name );
					if ( '' === $tenant_name ) {
						$tenant_name = isset( $directions_map[ $direction ] ) ? $directions_map[ $direction ] : __( 'Inquilino', 'arriendo-facil' );
					}
					$property = '' !== trim( (string) $row->accommodation_title ) ? (string) $row->accommodation_title : '#' . absint( $row->accommodation_id );
					$score    = (float) $row->_score;
					$tone     = $tone_for( $score );
					$row_crit = 'owner_to_tenant' === $direction ? $scores_of( $row->criteria_scores ) : array();
					$comment  = isset( $row->comment_text ) ? trim( (string) $row->comment_text ) : '';
					?>
					<article class="af-rv-card af-rv-tenant af-rv-rated" data-rv-item data-tone="<?php echo esc_attr( $tone ); ?>" data-search="<?php echo esc_attr( strtolower( $tenant_name . ' ' . $property ) ); ?>">
						<header class="af-rv-tenant__head">
							<span class="af-rv-avatar" aria-hidden="true"><?php echo esc_html( $initials_for( $tenant_name ) ); ?></span>
							<div class="af-rv-tenant__id">
								<h3><?php echo esc_html( $tenant_name ); ?></h3>
								<p><?php echo esc_html( $property ); ?></p>
							</div>
							<div class="af-rv-ring af-rv-tone--<?php echo esc_attr( $tone ); ?>" style="--pct: <?php echo esc_attr( round( $score / 5 * 100 ) ); ?>%" title="<?php echo esc_attr( $verdict_for( $score ) ); ?>">
								<strong><?php echo esc_html( number_format_i18n( $score, 1 ) ); ?></strong>
							</div>
						</header>

						<p class="af-rv-verdict af-rv-tone--<?php echo esc_attr( $tone ); ?>">
							<span class="af-rv-stars" style="--pct: <?php echo esc_attr( round( $score / 5 * 100 ) ); ?>%" aria-hidden="true"></span>
							<span><?php echo esc_html( $verdict_for( $score ) ); ?></span>
						</p>

						<?php if ( ! empty( $row_crit ) ) : ?>
							<ul class="af-rv-crit">
								<?php foreach ( $criteria_map as $key => $label ) : ?>
									<?php if ( isset( $row_crit[ $key ] ) ) : ?>
										<li class="af-rv-tone--<?php echo esc_attr( $tone_for( (int) $row_crit[ $key ] ) ); ?>">
											<span><?php echo esc_html( $label ); ?></span>
											<span class="af-rv-meter"><span style="width: <?php echo esc_attr( (int) $row_crit[ $key ] * 20 ); ?>%"></span></span>
											<strong><?php echo esc_html( (int) $row_crit[ $key ] ); ?></strong>
										</li>
									<?php endif; ?>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if ( '' !== $comment ) : ?>
							<blockquote class="af-rv-quote"><?php echo esc_html( $comment ); ?></blockquote>
						<?php endif; ?>

						<footer class="af-rv-tenant__foot">
							<span class="af-rv-muted"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Calificado el %s', 'arriendo-facil' ), $format_date( $row->submitted_at, true ) ) ); ?></span>
							<?php if ( $is_management_model && $can_rate ) : ?>
								<button type="button" class="button af-rv-ghost"
									data-rv-open="tenant"
									data-id="<?php echo esc_attr( (int) $row->lease_id ); ?>"
									data-name="<?php echo esc_attr( $tenant_name ); ?>"
									data-context="<?php echo esc_attr( $property ); ?>"
									data-scores="<?php echo esc_attr( wp_json_encode( $row_crit ) ); ?>"
									data-comment="<?php echo esc_attr( $comment ); ?>">
									<?php esc_html_e( 'Editar', 'arriendo-facil' ); ?>
								</button>
							<?php endif; ?>
						</footer>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<!-- ── Panel: gestores (super-admin) ─────────────────────────────────── -->
	<?php if ( $show_managers_tab ) : ?>
		<section class="af-rv-panel" data-rv-panel="managers" hidden>
			<p class="af-rv-lead"><?php esc_html_e( 'Como administrador de la plataforma, evalúas la gestión de cada gestor de propiedades.', 'arriendo-facil' ); ?></p>
			<div class="af-rv-grid">
				<?php foreach ( $admins as $af_admin ) : ?>
					<?php
					$admin_name   = $af_admin->display_name ? $af_admin->display_name : $af_admin->user_login;
					$evaluation   = isset( $admin_evaluations[ (int) $af_admin->ID ] ) ? $admin_evaluations[ (int) $af_admin->ID ] : null;
					$eval_scores  = $evaluation ? $scores_of( $evaluation->criteria_scores ) : array();
					$eval_score   = $evaluation ? $score_of_row( $evaluation, $admin_criteria ) : 0.0;
					$eval_tone    = $tone_for( $eval_score );
					$prop_count   = isset( $admin_property_counts[ (int) $af_admin->ID ] ) ? $admin_property_counts[ (int) $af_admin->ID ] : 0;
					$eval_comment = $evaluation && isset( $evaluation->comment_text ) ? trim( (string) $evaluation->comment_text ) : '';
					?>
					<article class="af-rv-card af-rv-tenant" data-rv-item data-search="<?php echo esc_attr( strtolower( $admin_name . ' ' . $af_admin->user_email ) ); ?>">
						<header class="af-rv-tenant__head">
							<span class="af-rv-avatar af-rv-avatar--alt" aria-hidden="true"><?php echo esc_html( $initials_for( $admin_name ) ); ?></span>
							<div class="af-rv-tenant__id">
								<h3><?php echo esc_html( $admin_name ); ?></h3>
								<p><?php echo esc_html( sprintf( /* translators: %s: number of properties */ _n( '%s inmueble', '%s inmuebles', $prop_count, 'arriendo-facil' ), number_format_i18n( $prop_count ) ) ); ?></p>
							</div>
							<?php if ( $evaluation ) : ?>
								<div class="af-rv-ring af-rv-tone--<?php echo esc_attr( $eval_tone ); ?>" style="--pct: <?php echo esc_attr( round( $eval_score / 5 * 100 ) ); ?>%">
									<strong><?php echo esc_html( number_format_i18n( $eval_score, 1 ) ); ?></strong>
								</div>
							<?php else : ?>
								<span class="af-rv-badge"><?php esc_html_e( 'Sin evaluar', 'arriendo-facil' ); ?></span>
							<?php endif; ?>
						</header>

						<?php if ( ! empty( $eval_scores ) ) : ?>
							<ul class="af-rv-crit">
								<?php foreach ( $admin_criteria as $key => $label ) : ?>
									<?php if ( isset( $eval_scores[ $key ] ) ) : ?>
										<li class="af-rv-tone--<?php echo esc_attr( $tone_for( (int) $eval_scores[ $key ] ) ); ?>">
											<span><?php echo esc_html( $label ); ?></span>
											<span class="af-rv-meter"><span style="width: <?php echo esc_attr( (int) $eval_scores[ $key ] * 20 ); ?>%"></span></span>
											<strong><?php echo esc_html( (int) $eval_scores[ $key ] ); ?></strong>
										</li>
									<?php endif; ?>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if ( '' !== $eval_comment ) : ?>
							<blockquote class="af-rv-quote"><?php echo esc_html( $eval_comment ); ?></blockquote>
						<?php endif; ?>

						<footer class="af-rv-tenant__foot">
							<span class="af-rv-muted"><?php echo esc_html( $evaluation ? sprintf( /* translators: %s: date */ __( 'Actualizada el %s', 'arriendo-facil' ), $format_date( $evaluation->updated_at ) ) : __( 'Aún sin evaluación', 'arriendo-facil' ) ); ?></span>
							<button type="button" class="button <?php echo $evaluation ? 'af-rv-ghost' : 'button-primary af-rv-cta'; ?>"
								data-rv-open="admin"
								data-id="<?php echo esc_attr( (int) $af_admin->ID ); ?>"
								data-name="<?php echo esc_attr( $admin_name ); ?>"
								data-context="<?php echo esc_attr( $af_admin->user_email ); ?>"
								data-scores="<?php echo esc_attr( wp_json_encode( $eval_scores ) ); ?>"
								data-comment="<?php echo esc_attr( $eval_comment ); ?>">
								<?php echo $evaluation ? esc_html__( 'Editar', 'arriendo-facil' ) : esc_html__( 'Evaluar', 'arriendo-facil' ); ?>
							</button>
						</footer>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
	<?php elseif ( $my_evaluation ) : ?>
		<?php
		$my_scores = $scores_of( $my_evaluation->criteria_scores );
		$my_score  = $score_of_row( $my_evaluation, $admin_criteria );
		$my_tone   = $tone_for( $my_score );
		$my_note   = isset( $my_evaluation->comment_text ) ? trim( (string) $my_evaluation->comment_text ) : '';
		?>
		<section class="af-rv-panel" data-rv-panel="mine" hidden>
			<p class="af-rv-lead"><?php esc_html_e( 'Evaluación registrada por el administrador de la plataforma sobre tu gestión.', 'arriendo-facil' ); ?></p>
			<article class="af-rv-card af-rv-tenant af-rv-mine">
				<header class="af-rv-tenant__head">
					<div class="af-rv-ring af-rv-tone--<?php echo esc_attr( $my_tone ); ?>" style="--pct: <?php echo esc_attr( round( $my_score / 5 * 100 ) ); ?>%">
						<strong><?php echo esc_html( number_format_i18n( $my_score, 1 ) ); ?></strong>
					</div>
					<div class="af-rv-tenant__id">
						<h3><?php echo esc_html( $verdict_for( $my_score ) ); ?></h3>
						<p><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Actualizada el %s', 'arriendo-facil' ), $format_date( $my_evaluation->updated_at ) ) ); ?></p>
					</div>
				</header>
				<ul class="af-rv-crit">
					<?php foreach ( $admin_criteria as $key => $label ) : ?>
						<?php if ( isset( $my_scores[ $key ] ) ) : ?>
							<li class="af-rv-tone--<?php echo esc_attr( $tone_for( (int) $my_scores[ $key ] ) ); ?>">
								<span><?php echo esc_html( $label ); ?></span>
								<span class="af-rv-meter"><span style="width: <?php echo esc_attr( (int) $my_scores[ $key ] * 20 ); ?>%"></span></span>
								<strong><?php echo esc_html( (int) $my_scores[ $key ] ); ?></strong>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
				<?php if ( '' !== $my_note ) : ?>
					<blockquote class="af-rv-quote"><?php echo esc_html( $my_note ); ?></blockquote>
				<?php endif; ?>
			</article>
		</section>
	<?php endif; ?>

	<p class="af-rv-empty af-rv-empty--search" id="af-rv-nomatch" hidden></p>
</div>

<?php if ( $is_management_model && ( $can_rate || $is_super_admin ) ) : ?>
	<!-- ── Panel lateral de calificación ───────────────────────────────── -->
	<div class="af-rv-drawer af-shell" id="af-rv-drawer" role="dialog" aria-modal="true" aria-labelledby="af-rv-drawer-title" hidden>
		<div class="af-rv-drawer__backdrop" data-rv-close></div>
		<aside class="af-rv-drawer__panel">
			<header class="af-rv-drawer__head">
				<span class="af-rv-avatar" id="af-rv-drawer-avatar" aria-hidden="true"></span>
				<div>
					<h2 id="af-rv-drawer-title"></h2>
					<p id="af-rv-drawer-sub"></p>
				</div>
				<button type="button" class="af-rv-drawer__close" data-rv-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
			</header>

			<div class="af-rv-drawer__body">
				<div class="af-rv-suggest" id="af-rv-suggest" hidden></div>
				<div id="af-rv-criteria"></div>

				<div class="af-rv-field">
					<label for="af-rv-comment"><?php esc_html_e( 'Observaciones', 'arriendo-facil' ); ?> <em><?php esc_html_e( '(opcional, solo visible para ti)', 'arriendo-facil' ); ?></em></label>
					<textarea id="af-rv-comment" rows="4" maxlength="600"></textarea>
					<span class="af-rv-counter" id="af-rv-counter">0 / 600</span>
				</div>
			</div>

			<footer class="af-rv-drawer__foot">
				<div class="af-rv-total" id="af-rv-total" aria-live="polite"></div>
				<p class="af-rv-status" id="af-rv-status" role="status"></p>
				<div class="af-rv-drawer__actions">
					<button type="button" class="button" data-rv-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button type="button" class="button button-primary" id="af-rv-save"></button>
				</div>
			</footer>
		</aside>
	</div>
<?php endif; ?>

<script type="application/json" id="af-rv-config"><?php echo wp_json_encode( $config ); ?></script>
<script>window.afRvDefaultTab = <?php echo wp_json_encode( $default_tab ); ?>;</script>
