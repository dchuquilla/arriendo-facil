<?php
/**
 * Dashboard admin view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var wpdb $wpdb */
global $wpdb;

$current_user = wp_get_current_user();
$is_owner     = Arriendo_Facil_Accommodation::user_is_owner();

$owner_ids = array();
if ( $is_owner ) {
	$owner_ids = array_map( 'intval', Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() ) );
}

$ids_sql = ( $is_owner && ! empty( $owner_ids ) ) ? implode( ',', $owner_ids ) : '';

// ── KPI counts ────────────────────────────────────────────────────────────
if ( $is_owner ) {
	$accommodation_count = count( $owner_ids );

	if ( '' !== $ids_sql ) {
		$lease_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($ids_sql) AND deleted_at IS NULL" );
		$active_leases    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($ids_sql) AND status = 'active' AND deleted_at IS NULL" );
		$draft_leases     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE accommodation_id IN ($ids_sql) AND status = 'draft' AND deleted_at IS NULL" );
		$guest_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_guests WHERE accommodation_id IN ($ids_sql)" );
		$pending_cleaning = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_cleaning_requests WHERE accommodation_id IN ($ids_sql) AND status = 'pending'" );
		$review_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews WHERE accommodation_id IN ($ids_sql) AND status = 'completed'" );
		$avg_stars        = (float) $wpdb->get_var( "SELECT AVG(stars) FROM {$wpdb->prefix}af_reviews WHERE accommodation_id IN ($ids_sql) AND status = 'completed'" );
		$positive_reviews = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews WHERE accommodation_id IN ($ids_sql) AND status = 'completed' AND stars >= 4" );
		$pending_queue    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_interest_queue WHERE accommodation_id IN ($ids_sql) AND status = 'pending'" );
	} else {
		$lease_count = $active_leases = $draft_leases = $guest_count = $pending_cleaning = $review_count = $positive_reviews = $pending_queue = 0;
		$avg_stars   = 0.0;
	}
	$active_contacts = null;
} else {
	$accommodation_count = (int) wp_count_posts( 'accommodation' )->publish;
	$lease_count         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE deleted_at IS NULL" );
	$active_leases       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE status = 'active' AND deleted_at IS NULL" );
	$draft_leases        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases WHERE status = 'draft' AND deleted_at IS NULL" );
	$guest_count         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_guests" );
	$pending_cleaning    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_cleaning_requests WHERE status = 'pending'" );
	$review_count        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews WHERE status = 'completed'" );
	$avg_stars           = (float) $wpdb->get_var( "SELECT AVG(stars) FROM {$wpdb->prefix}af_reviews WHERE status = 'completed'" );
	$positive_reviews    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews WHERE status = 'completed' AND stars >= 4" );
	$active_contacts     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_owner_contacts WHERE status = 'active'" );
	$pending_queue       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_interest_queue WHERE status = 'pending'" );
}

$positive_rate = $review_count > 0 ? (int) round( $positive_reviews / $review_count * 100 ) : 0;

// ── Owner property preview (top 6) ────────────────────────────────────────
$owner_properties = array();
if ( $is_owner && '' !== $ids_sql ) {
	$owner_properties = $wpdb->get_results(
		"SELECT p.ID, p.post_title, pm_rent.meta_value AS monthly_rent, pm_status.meta_value AS availability
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm_rent   ON pm_rent.post_id = p.ID   AND pm_rent.meta_key = '_af_monthly_rent'
		 LEFT JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_af_availability_status'
		 WHERE p.post_type = 'accommodation'
		   AND p.post_status = 'publish'
		   AND p.ID IN ($ids_sql)
		 ORDER BY p.post_date DESC
		 LIMIT 6"
	);
}

// ── Greeting ──────────────────────────────────────────────────────────────
$hour = (int) current_time( 'H' );
if ( $hour < 12 ) {
	$greeting = __( 'Buenos días', 'arriendo-facil' );
} elseif ( $hour < 19 ) {
	$greeting = __( 'Buenas tardes', 'arriendo-facil' );
} else {
	$greeting = __( 'Buenas noches', 'arriendo-facil' );
}

$first_name = $current_user->first_name ? $current_user->first_name : $current_user->display_name;
$today_str  = wp_date( 'l, j \d\e F' );

// ── Tasks list ────────────────────────────────────────────────────────────
$tasks = array();
if ( $draft_leases > 0 ) {
	$tasks[] = array(
		'label' => _n( 'contrato en borrador por revisar', 'contratos en borrador por revisar', $draft_leases, 'arriendo-facil' ),
		'count' => $draft_leases,
		'url'   => admin_url( 'admin.php?page=af-leases' ),
	);
}
if ( $pending_cleaning > 0 ) {
	$tasks[] = array(
		'label' => _n( 'solicitud de limpieza pendiente', 'solicitudes de limpieza pendientes', $pending_cleaning, 'arriendo-facil' ),
		'count' => $pending_cleaning,
		'url'   => admin_url( 'admin.php?page=af-cleaning-requests' ),
	);
}
if ( $pending_queue > 0 ) {
	$tasks[] = array(
		'label' => _n( 'huésped interesado por aprobar', 'huéspedes interesados por aprobar', $pending_queue, 'arriendo-facil' ),
		'count' => $pending_queue,
		'url'   => admin_url( 'admin.php?page=af-guests' ),
	);
}
?>
<div class="wrap af-shell af-dashboard">

	<header class="af-page-header">
		<div class="af-page-header__title">
			<span class="af-page-header__eyebrow"><?php echo esc_html( $today_str ); ?></span>
			<h1>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: greeting (e.g. Buenos días), 2: user first name */
						__( '%1$s, %2$s', 'arriendo-facil' ),
						$greeting,
						$first_name
					)
				);
				?>
			</h1>
			<p class="af-page-header__subtitle">
				<?php
				echo esc_html(
					$is_owner
						? __( 'Un resumen rápido de tus propiedades y todo lo que necesita tu atención.', 'arriendo-facil' )
						: __( 'Panel de operaciones — el pulso de Arriendo Fácil en un vistazo.', 'arriendo-facil' )
				);
				?>
			</p>
		</div>

		<div class="af-page-header__actions">
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=accommodation' ) ); ?>" class="button af-btn af-btn--primary">
				<span class="af-btn__icon" aria-hidden="true">
					<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
				</span>
				<?php esc_html_e( 'Nuevo alojamiento', 'arriendo-facil' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>" class="button af-btn af-btn--ghost">
				<?php esc_html_e( 'Ver contratos', 'arriendo-facil' ); ?>
			</a>
		</div>
	</header>

	<div class="af-kpi-grid" role="list">

		<article class="af-kpi" role="listitem">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Alojamientos', 'arriendo-facil' ); ?></span>
				<span class="af-kpi__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 11l9-8 9 8v10a1 1 0 01-1 1h-5v-6H10v6H4a1 1 0 01-1-1V11z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
				</span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $accommodation_count ) ); ?></div>
			<div class="af-kpi__hint">
				<?php echo esc_html( $is_owner ? __( 'Propiedades publicadas a tu nombre', 'arriendo-facil' ) : __( 'Publicadas en la plataforma', 'arriendo-facil' ) ); ?>
			</div>
			<div class="af-kpi__footer">
				<span class="af-pill af-pill--info">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: active leases count */
							_n( '%d activo', '%d activos', $active_leases, 'arriendo-facil' ),
							$active_leases
						)
					);
					?>
				</span>
				<a class="af-kpi__link" href="<?php echo esc_url( admin_url( 'edit.php?post_type=accommodation' ) ); ?>"><?php esc_html_e( 'Ver', 'arriendo-facil' ); ?></a>
			</div>
		</article>

		<article class="af-kpi <?php echo $draft_leases > 0 ? 'af-kpi--attention' : ''; ?>" role="listitem">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Contratos', 'arriendo-facil' ); ?></span>
				<span class="af-kpi__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 3h9l4 4v14a1 1 0 01-1 1H6a1 1 0 01-1-1V4a1 1 0 011-1z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3v5h5M8 13h8M8 17h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
				</span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $lease_count ) ); ?></div>
			<div class="af-kpi__hint">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: active leases */
						_n( '%d contrato activo', '%d contratos activos', $active_leases, 'arriendo-facil' ),
						$active_leases
					)
				);
				?>
			</div>
			<div class="af-kpi__footer">
				<?php if ( $draft_leases > 0 ) : ?>
					<span class="af-pill af-pill--warning">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: draft leases */
								_n( '%d borrador', '%d borradores', $draft_leases, 'arriendo-facil' ),
								$draft_leases
							)
						);
						?>
					</span>
				<?php else : ?>
					<span class="af-pill af-pill--success"><?php esc_html_e( 'Todo al día', 'arriendo-facil' ); ?></span>
				<?php endif; ?>
				<a class="af-kpi__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>"><?php esc_html_e( 'Gestionar', 'arriendo-facil' ); ?></a>
			</div>
		</article>

		<article class="af-kpi <?php echo $pending_cleaning > 0 ? 'af-kpi--attention' : 'af-kpi--success'; ?>" role="listitem">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Limpiezas pendientes', 'arriendo-facil' ); ?></span>
				<span class="af-kpi__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 20l6-6 4 4 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="18" cy="6" r="2" stroke="currentColor" stroke-width="1.8"/></svg>
				</span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $pending_cleaning ) ); ?></div>
			<div class="af-kpi__hint">
				<?php echo esc_html( $pending_cleaning > 0 ? __( 'Requieren asignación o programación', 'arriendo-facil' ) : __( 'Sin solicitudes en espera', 'arriendo-facil' ) ); ?>
			</div>
			<div class="af-kpi__footer">
				<span class="af-pill <?php echo $pending_cleaning > 0 ? 'af-pill--warning' : 'af-pill--success'; ?>">
					<?php echo esc_html( $pending_cleaning > 0 ? __( 'Acción requerida', 'arriendo-facil' ) : __( 'Al día', 'arriendo-facil' ) ); ?>
				</span>
				<a class="af-kpi__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-cleaning-requests' ) ); ?>"><?php esc_html_e( 'Revisar', 'arriendo-facil' ); ?></a>
			</div>
		</article>

		<article class="af-kpi af-kpi--accent" role="listitem">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Valoraciones', 'arriendo-facil' ); ?></span>
				<span class="af-kpi__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3 6.5 7 .9-5.1 4.7 1.3 7L12 17.8 5.8 21l1.3-7L2 9.4l7-.9L12 2z"/></svg>
				</span>
			</div>
			<div class="af-kpi__value">
				<?php echo esc_html( $review_count > 0 ? number_format_i18n( $avg_stars, 1 ) : '—' ); ?>
				<?php if ( $review_count > 0 ) : ?>
					<small style="font-size: var(--af-text-base); color: var(--af-gray-400); font-weight: 500;">/ 5</small>
				<?php endif; ?>
			</div>
			<div class="af-kpi__hint">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: review count, 2: positive % */
						_n( '%1$d reseña · %2$d%% positivas', '%1$d reseñas · %2$d%% positivas', $review_count, 'arriendo-facil' ),
						$review_count,
						$positive_rate
					)
				);
				?>
			</div>
			<div class="af-kpi__footer">
				<span class="af-pill af-pill--accent">
					<?php
					if ( $avg_stars >= 4.5 ) {
						esc_html_e( 'Excelente', 'arriendo-facil' );
					} elseif ( $avg_stars >= 3.5 ) {
						esc_html_e( 'Muy bueno', 'arriendo-facil' );
					} elseif ( $review_count > 0 ) {
						esc_html_e( 'A mejorar', 'arriendo-facil' );
					} else {
						esc_html_e( 'Sin datos aún', 'arriendo-facil' );
					}
					?>
				</span>
				<a class="af-kpi__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-reviews' ) ); ?>"><?php esc_html_e( 'Ver todas', 'arriendo-facil' ); ?></a>
			</div>
		</article>

		<?php if ( ! $is_owner && null !== $active_contacts ) : ?>
			<article class="af-kpi" role="listitem">
				<div class="af-kpi__head">
					<span class="af-kpi__label"><?php esc_html_e( 'Propietarios', 'arriendo-facil' ); ?></span>
					<span class="af-kpi__icon" aria-hidden="true">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
					</span>
				</div>
				<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $active_contacts ) ); ?></div>
				<div class="af-kpi__hint"><?php esc_html_e( 'Contactos activos', 'arriendo-facil' ); ?></div>
				<div class="af-kpi__footer">
					<span class="af-pill af-pill--info"><?php esc_html_e( 'Registrados', 'arriendo-facil' ); ?></span>
					<a class="af-kpi__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-owner-contacts' ) ); ?>"><?php esc_html_e( 'Ver', 'arriendo-facil' ); ?></a>
				</div>
			</article>
		<?php endif; ?>

		<article class="af-kpi" role="listitem">
			<div class="af-kpi__head">
				<span class="af-kpi__label"><?php esc_html_e( 'Huéspedes', 'arriendo-facil' ); ?></span>
				<span class="af-kpi__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2M21 21v-2a4 4 0 00-3-3.87M9 11a4 4 0 100-8 4 4 0 000 8zM17 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</span>
			</div>
			<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( $guest_count ) ); ?></div>
			<div class="af-kpi__hint">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: pending queue */
						_n( '%d en cola', '%d en cola', $pending_queue, 'arriendo-facil' ),
						$pending_queue
					)
				);
				?>
			</div>
			<div class="af-kpi__footer">
				<span class="af-pill <?php echo $pending_queue > 0 ? 'af-pill--warning' : 'af-pill--neutral'; ?>">
					<?php echo esc_html( $pending_queue > 0 ? __( 'Por aprobar', 'arriendo-facil' ) : __( 'Al día', 'arriendo-facil' ) ); ?>
				</span>
				<a class="af-kpi__link" href="<?php echo esc_url( admin_url( 'admin.php?page=af-guests' ) ); ?>"><?php esc_html_e( 'Gestionar', 'arriendo-facil' ); ?></a>
			</div>
		</article>

	</div>

	<div class="af-split">

		<section class="af-section" aria-labelledby="af-dashboard-focus">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-dashboard-focus">
						<?php echo esc_html( $is_owner ? __( 'Tus propiedades', 'arriendo-facil' ) : __( 'Accesos rápidos', 'arriendo-facil' ) ); ?>
					</h2>
					<p class="af-section__subtitle">
						<?php
						echo esc_html(
							$is_owner
								? __( 'Un vistazo a lo que tienes publicado. Toca una tarjeta para gestionarla.', 'arriendo-facil' )
								: __( 'Las acciones que más usas para operar la plataforma.', 'arriendo-facil' )
						);
						?>
					</p>
				</div>
			</header>

			<?php if ( $is_owner ) : ?>

				<?php if ( ! empty( $owner_properties ) ) : ?>
					<div class="af-property-grid">
						<?php
						foreach ( $owner_properties as $prop ) :
							$thumb  = get_the_post_thumbnail_url( (int) $prop->ID, 'medium_large' );
							$rent   = $prop->monthly_rent ? (float) $prop->monthly_rent : 0;
							$status = $prop->availability ? (string) $prop->availability : 'available';
							$status_pill = 'af-pill--success';
							$status_lbl  = __( 'Disponible', 'arriendo-facil' );
							if ( 'occupied' === $status ) {
								$status_pill = 'af-pill--danger';
								$status_lbl  = __( 'Ocupado', 'arriendo-facil' );
							} elseif ( 'maintenance' === $status ) {
								$status_pill = 'af-pill--warning';
								$status_lbl  = __( 'En mantenimiento', 'arriendo-facil' );
							}
							?>
							<a class="af-property-card" href="<?php echo esc_url( get_edit_post_link( (int) $prop->ID ) ); ?>">
								<div class="af-property-card__media">
									<?php if ( $thumb ) : ?>
										<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $prop->post_title ); ?>" loading="lazy" />
									<?php else : ?>
										<div class="af-property-card__placeholder" aria-hidden="true">
											<svg width="42" height="42" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 11l9-8 9 8v10a1 1 0 01-1 1h-5v-6H10v6H4a1 1 0 01-1-1V11z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
										</div>
									<?php endif; ?>
									<div class="af-property-card__badges">
										<span class="af-pill <?php echo esc_attr( $status_pill ); ?>"><?php echo esc_html( $status_lbl ); ?></span>
									</div>
								</div>
								<div class="af-property-card__body">
									<h3 class="af-property-card__title"><?php echo esc_html( $prop->post_title ); ?></h3>
									<p class="af-property-card__meta"><?php esc_html_e( 'Toca para ver detalles y actividad', 'arriendo-facil' ); ?></p>
									<div class="af-property-card__footer">
										<span class="af-property-card__price">
											<?php echo $rent > 0 ? esc_html( '$' . number_format_i18n( $rent, 2 ) ) : '—'; ?>
											<?php if ( $rent > 0 ) : ?>
												<small><?php esc_html_e( '/ mes', 'arriendo-facil' ); ?></small>
											<?php endif; ?>
										</span>
										<span class="af-kpi__link"><?php esc_html_e( 'Editar', 'arriendo-facil' ); ?></span>
									</div>
								</div>
							</a>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<div class="af-empty">
						<span class="af-empty__icon" aria-hidden="true">
							<svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 11l9-8 9 8v10a1 1 0 01-1 1h-5v-6H10v6H4a1 1 0 01-1-1V11z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
						</span>
						<h3 class="af-empty__title"><?php esc_html_e( 'Aún no tienes propiedades', 'arriendo-facil' ); ?></h3>
						<p class="af-empty__text"><?php esc_html_e( 'Publica tu primer alojamiento y comienza a recibir huéspedes verificados en cuestión de días.', 'arriendo-facil' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=accommodation' ) ); ?>" class="button af-btn af-btn--primary">
							<?php esc_html_e( 'Registrar mi primera propiedad', 'arriendo-facil' ); ?>
						</a>
					</div>
				<?php endif; ?>

			<?php else : ?>

				<div class="af-property-grid">
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=accommodation' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( '+ Nuevo alojamiento', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Publicar una propiedad en la plataforma.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( 'Gestionar contratos', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Ver, activar y facturar contratos vigentes.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'admin.php?page=af-cleaning-requests' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( 'Solicitudes de limpieza', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Asigna, programa y da seguimiento.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'admin.php?page=af-billing' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( 'Facturación electrónica', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Emitir y firmar comprobantes SRI del período.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'admin.php?page=af-ota-sync-dashboard' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( 'Sincronización OTA', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Airbnb y Booking en tiempo real.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
					<a class="af-property-card" href="<?php echo esc_url( admin_url( 'admin.php?page=af-ai-settings' ) ); ?>">
						<div class="af-property-card__body">
							<h3 class="af-property-card__title"><?php esc_html_e( 'Ajustes de IA', 'arriendo-facil' ); ?></h3>
							<p class="af-property-card__meta"><?php esc_html_e( 'Modelos y credenciales para automatización.', 'arriendo-facil' ); ?></p>
						</div>
					</a>
				</div>

			<?php endif; ?>
		</section>

		<aside class="af-section" aria-labelledby="af-dashboard-tasks">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title" id="af-dashboard-tasks"><?php esc_html_e( 'Requiere tu atención', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'Prioridad del día. Toca para resolver.', 'arriendo-facil' ); ?></p>
				</div>
			</header>

			<?php if ( ! empty( $tasks ) ) : ?>
				<ul class="af-tasklist">
					<?php foreach ( $tasks as $task ) : ?>
						<li>
							<a class="af-tasklist__item" href="<?php echo esc_url( $task['url'] ); ?>">
								<span class="af-tasklist__badge"><?php echo esc_html( number_format_i18n( $task['count'] ) ); ?></span>
								<span class="af-tasklist__label"><?php echo esc_html( $task['label'] ); ?></span>
								<span class="af-tasklist__arrow" aria-hidden="true">→</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<div class="af-empty" style="padding: var(--af-space-6) var(--af-space-4);">
					<span class="af-empty__icon" aria-hidden="true">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12l4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</span>
					<h3 class="af-empty__title"><?php esc_html_e( 'Todo bajo control', 'arriendo-facil' ); ?></h3>
					<p class="af-empty__text"><?php esc_html_e( 'No hay pendientes urgentes. Buen momento para revisar reseñas o programar limpiezas.', 'arriendo-facil' ); ?></p>
				</div>
			<?php endif; ?>
		</aside>

	</div>

</div>
