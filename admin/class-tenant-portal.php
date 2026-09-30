<?php
/**
 * Tenant self-service portal inside wp-admin (profile, rentals, reviews, calendar).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Tenant_Portal
 */
class Arriendo_Facil_Tenant_Portal {

	/**
	 * Hooks the tenant dashboard pages and widgets.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_tenant_dashboard_pages' ), 50 );
		add_filter( 'get_user_option_screen_layout_dashboard', array( $this, 'force_tenant_dashboard_single_column' ), 10, 3 );
		add_action( 'wp_dashboard_setup', array( $this, 'remove_tenant_dashboard_widgets' ), 999 );
		add_action( 'wp_dashboard_setup', array( $this, 'register_tenant_dashboard_widget' ) );
	}

	/**
	 * Whether the current user is a tenant (non-admin).
	 *
	 * @return bool
	 */
	public static function is_restricted_tenant() {
		if ( current_user_can( 'manage_options' ) ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( ! ( $user instanceof WP_User ) ) {
			return false;
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();
		return in_array( 'af_tenant', $roles, true );
	}

	/**
	 * Tenant landing URL inside wp-admin.
	 *
	 * @return string
	 */
	public static function dashboard_url() {
		return admin_url();
	}

	/**
	 * Forces the native dashboard to one column for tenant users so the
	 * tenant widget uses full available space.
	 *
	 * @param mixed  $result Existing option value.
	 * @param string $option Option key.
	 * @param WP_User $user  User object.
	 * @return mixed
	 */
	public function force_tenant_dashboard_single_column( $result, $option, $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $result;
		}

		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();
		if ( in_array( 'af_tenant', $roles, true ) && ! in_array( 'administrator', $roles, true ) ) {
			return 1;
		}

		return $result;
	}

	/**
	 * Registers tenant sections under the native Dashboard menu.
	 *
	 * @return void
	 */
	public function register_tenant_dashboard_pages() {
		if ( ! self::is_restricted_tenant() ) {
			return;
		}

		add_dashboard_page(
			__( 'Mi perfil', 'arriendo-facil' ),
			__( 'Mi perfil', 'arriendo-facil' ),
			'af_tenant_portal',
			'af-tenant-profile',
			array( $this, 'render_tenant_profile_page' )
		);

		add_dashboard_page(
			__( 'Historial de arriendos', 'arriendo-facil' ),
			__( 'Historial de arriendos', 'arriendo-facil' ),
			'af_tenant_view_own_leases',
			'af-tenant-rentals',
			array( $this, 'render_tenant_rentals_page' )
		);

		add_dashboard_page(
			__( 'Reseñas', 'arriendo-facil' ),
			__( 'Reseñas', 'arriendo-facil' ),
			'af_tenant_submit_reviews',
			'af-tenant-reviews',
			array( $this, 'render_tenant_reviews_page' )
		);

		add_dashboard_page(
			__( 'Calendario', 'arriendo-facil' ),
			__( 'Calendario', 'arriendo-facil' ),
			'af_tenant_view_own_reservations',
			'af-tenant-calendar',
			array( $this, 'render_tenant_calendar_page' )
		);
	}

	/**
	 * Removes default dashboard widgets for tenant users.
	 *
	 * @return void
	 */
	public function remove_tenant_dashboard_widgets() {
		if ( ! self::is_restricted_tenant() ) {
			return;
		}

		remove_meta_box( 'dashboard_right_now', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_incoming_links', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_plugins', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_secondary', 'dashboard', 'side' );
	}

	/**
	 * Registers tenant summary widget on native dashboard.
	 *
	 * @return void
	 */
	public function register_tenant_dashboard_widget() {
		if ( ! self::is_restricted_tenant() ) {
			return;
		}

		global $wp_meta_boxes;

		wp_add_dashboard_widget(
			'af_tenant_dashboard_summary',
			__( 'Arriendo Fácil — Mi panel de inquilino', 'arriendo-facil' ),
			array( $this, 'render_tenant_dashboard_widget' )
		);

		if ( isset( $wp_meta_boxes['dashboard']['normal']['core'] ) && is_array( $wp_meta_boxes['dashboard']['normal']['core'] ) ) {
			$normal = $wp_meta_boxes['dashboard']['normal']['core'];
			if ( isset( $normal['af_tenant_dashboard_summary'] ) ) {
				$tenant_widget = array( 'af_tenant_dashboard_summary' => $normal['af_tenant_dashboard_summary'] );
				unset( $normal['af_tenant_dashboard_summary'] );
				$wp_meta_boxes['dashboard']['normal']['core'] = $tenant_widget + $normal;
			}
		}
	}

	/**
	 * Renders tenant summary widget with quick access sections.
	 *
	 * @return void
	 */
	public function render_tenant_dashboard_widget() {
		$context = $this->get_tenant_portal_context();
		$stats   = isset( $context['stats'] ) && is_array( $context['stats'] ) ? $context['stats'] : array();

		$lease_total       = isset( $stats['lease_total'] ) ? (int) $stats['lease_total'] : 0;
		$lease_active      = isset( $stats['lease_active'] ) ? (int) $stats['lease_active'] : 0;
		$visit_upcoming    = isset( $stats['visit_upcoming'] ) ? (int) $stats['visit_upcoming'] : 0;
		$reviews_pending   = isset( $stats['reviews_pending'] ) ? (int) $stats['reviews_pending'] : 0;

		$profile_url  = admin_url( 'admin.php?page=af-tenant-profile' );
		$rentals_url  = admin_url( 'admin.php?page=af-tenant-rentals' );
		$reviews_url  = admin_url( 'admin.php?page=af-tenant-reviews' );
		$calendar_url = admin_url( 'admin.php?page=af-tenant-calendar' );
		?>
		<div class="af-dash-widget af-tenant-dash-widget">
			<div class="af-dash-widget__hero">
				<span class="af-dash-widget__logo" aria-hidden="true">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3l8 4v5c0 5.25-3.5 8.9-8 10-4.5-1.1-8-4.75-8-10V7l8-4z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9.5 12.2l1.8 1.8 3.4-3.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</span>
				<div class="af-dash-widget__hero-text">
					<h3><?php esc_html_e( 'Tu experiencia de arriendo', 'arriendo-facil' ); ?></h3>
					<p><?php esc_html_e( 'Accede rápido a perfil, historial, reseñas y calendario.', 'arriendo-facil' ); ?></p>
				</div>
			</div>

			<div class="af-dash-widget__stats">
				<a class="af-dash-widget__stat" href="<?php echo esc_url( $rentals_url ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Arriendos', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $lease_total ) ); ?></span>
				</a>
				<a class="af-dash-widget__stat" href="<?php echo esc_url( $rentals_url ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Activos', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $lease_active ) ); ?></span>
				</a>
				<a class="af-dash-widget__stat <?php echo $visit_upcoming > 0 ? 'af-dash-widget__stat--attention' : ''; ?>" href="<?php echo esc_url( $calendar_url ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Próximas visitas', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $visit_upcoming ) ); ?></span>
				</a>
				<a class="af-dash-widget__stat <?php echo $reviews_pending > 0 ? 'af-dash-widget__stat--attention' : ''; ?>" href="<?php echo esc_url( $reviews_url ); ?>">
					<span class="af-dash-widget__stat-label"><?php esc_html_e( 'Reseñas pendientes', 'arriendo-facil' ); ?></span>
					<span class="af-dash-widget__stat-value"><?php echo esc_html( number_format_i18n( $reviews_pending ) ); ?></span>
				</a>
			</div>

			<div class="af-dash-widget__actions">
				<a class="af-dash-btn af-dash-btn--primary" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Editar perfil', 'arriendo-facil' ); ?></a>
				<a class="af-dash-btn af-dash-btn--ghost" href="<?php echo esc_url( $reviews_url ); ?>"><?php esc_html_e( 'Gestionar reseñas', 'arriendo-facil' ); ?></a>
				<a class="af-dash-btn af-dash-btn--ghost" href="<?php echo esc_url( $calendar_url ); ?>"><?php esc_html_e( 'Ver calendario', 'arriendo-facil' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders tenant profile section.
	 *
	 * @return void
	 */
	public function render_tenant_profile_page() {
		if ( ! self::is_restricted_tenant() ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta sección.', 'arriendo-facil' ) );
		}

		$context       = $this->get_tenant_portal_context();
		$user          = isset( $context['user'] ) && $context['user'] instanceof WP_User ? $context['user'] : wp_get_current_user();
		$email_verified = (int) get_user_meta( (int) $user->ID, 'af_tenant_email_verified', true );
		$terms_at      = (string) get_user_meta( (int) $user->ID, 'af_tenant_terms_accepted_at', true );
		$profile_url   = admin_url( 'profile.php' );
		?>
		<div class="wrap af-shell af-tenant-portal">
			<div class="af-page-header">
				<div class="af-page-header__title">
					<span class="af-page-header__eyebrow"><?php esc_html_e( 'Inquilino', 'arriendo-facil' ); ?></span>
					<h1><?php esc_html_e( 'Mi perfil', 'arriendo-facil' ); ?></h1>
					<p class="af-page-header__subtitle"><?php esc_html_e( 'Administra tus datos personales y seguridad de cuenta.', 'arriendo-facil' ); ?></p>
				</div>
				<div class="af-page-header__actions">
					<a class="button af-btn af-btn--primary" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Abrir editor de perfil', 'arriendo-facil' ); ?></a>
				</div>
			</div>

			<div class="af-kpi-grid">
				<div class="af-kpi af-kpi--info">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( (string) $user->display_name ); ?></div>
				</div>
				<div class="af-kpi <?php echo $email_verified ? 'af-kpi--success' : 'af-kpi--attention'; ?>">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Correo verificado', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( $email_verified ? __( 'Sí', 'arriendo-facil' ) : __( 'Pendiente', 'arriendo-facil' ) ); ?></div>
				</div>
				<div class="af-kpi af-kpi--accent">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Términos aceptados', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( '' !== $terms_at ? wp_date( 'd/m/Y H:i', strtotime( $terms_at ) ) : __( 'Sin registro', 'arriendo-facil' ) ); ?></div>
				</div>
			</div>

			<section class="af-section">
				<div class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Edición de perfil recomendada', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Usa la vista nativa de WordPress, ya adaptada a la estética de Arriendo Fácil, para actualizar nombre, contraseña y datos de contacto.', 'arriendo-facil' ); ?></p>
					</div>
				</div>
				<p><a class="button af-btn af-btn--ghost" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Ir a editar perfil', 'arriendo-facil' ); ?></a></p>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders tenant rental history section.
	 *
	 * @return void
	 */
	public function render_tenant_rentals_page() {
		if ( ! self::is_restricted_tenant() ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta sección.', 'arriendo-facil' ) );
		}

		$leases = $this->get_tenant_lease_rows( 60 );
		$visits = $this->get_tenant_visit_rows( 40 );
		?>
		<div class="wrap af-shell af-tenant-portal">
			<div class="af-page-header">
				<div class="af-page-header__title">
					<span class="af-page-header__eyebrow"><?php esc_html_e( 'Historial', 'arriendo-facil' ); ?></span>
					<h1><?php esc_html_e( 'Historial de arriendos', 'arriendo-facil' ); ?></h1>
					<p class="af-page-header__subtitle"><?php esc_html_e( 'Inspirado en Airbnb y Booking: revisa tus estancias, estado y fechas clave en un solo lugar.', 'arriendo-facil' ); ?></p>
				</div>
			</div>

			<section class="af-section">
				<div class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Estancias registradas', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Histórico de arriendos con fechas y estado de cada contrato.', 'arriendo-facil' ); ?></p>
					</div>
				</div>
				<?php if ( empty( $leases ) ) : ?>
					<p><?php esc_html_e( 'Aún no tienes arriendos registrados.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<table class="widefat striped af-data-table af-data-table--tenant">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Alojamiento', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Inicio', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Fin', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Valor mensual', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $leases as $lease ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Alojamiento', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $lease['accommodation_title'] ) ? (string) $lease['accommodation_title'] : __( 'Alojamiento', 'arriendo-facil' ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Inicio', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $lease['start_date'] ) ? (string) wp_date( 'd/m/Y', strtotime( (string) $lease['start_date'] ) ) : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Fin', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $lease['end_date'] ) ? (string) wp_date( 'd/m/Y', strtotime( (string) $lease['end_date'] ) ) : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Valor mensual', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $lease['monthly_rent'] ) ? '$' . number_format_i18n( (float) $lease['monthly_rent'], 2 ) : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $lease['status_label'] ) ? (string) $lease['status_label'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>

			<section class="af-section">
				<div class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Visitas y reservas', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Seguimiento de visitas agendadas y reservas confirmadas en tu proceso de arriendo.', 'arriendo-facil' ); ?></p>
					</div>
				</div>
				<?php if ( empty( $visits ) ) : ?>
					<p><?php esc_html_e( 'Aún no tienes visitas o reservas registradas.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<table class="widefat striped af-data-table af-data-table--tenant">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Alojamiento', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Hora', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $visits as $visit ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Alojamiento', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $visit['accommodation_title'] ) ? (string) $visit['accommodation_title'] : __( 'Alojamiento', 'arriendo-facil' ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Fecha', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $visit['visit_date'] ) ? (string) wp_date( 'd/m/Y', strtotime( (string) $visit['visit_date'] ) ) : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Hora', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $visit['start_time'] ) ? (string) substr( (string) $visit['start_time'], 0, 5 ) : '--:--' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $visit['status_label'] ) ? (string) $visit['status_label'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders tenant reviews section.
	 *
	 * @return void
	 */
	public function render_tenant_reviews_page() {
		if ( ! self::is_restricted_tenant() ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta sección.', 'arriendo-facil' ) );
		}

		$summary = $this->get_tenant_reviews_summary();
		$nonce   = wp_create_nonce( 'af_guest_nonce' );
		?>
		<div class="wrap af-shell af-tenant-portal">
			<div class="af-page-header">
				<div class="af-page-header__title">
					<span class="af-page-header__eyebrow"><?php esc_html_e( 'Reputación', 'arriendo-facil' ); ?></span>
					<h1><?php esc_html_e( 'Reseñas', 'arriendo-facil' ); ?></h1>
					<p class="af-page-header__subtitle"><?php esc_html_e( 'Comparte tu experiencia como en Airbnb: califica al propietario y la propiedad cuando tengas reseñas pendientes.', 'arriendo-facil' ); ?></p>
				</div>
			</div>

			<div class="af-kpi-grid">
				<div class="af-kpi af-kpi--info">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Reseñas completadas', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( (int) $summary['completed'] ) ); ?></div>
				</div>
				<div class="af-kpi <?php echo (int) $summary['pending'] > 0 ? 'af-kpi--attention' : 'af-kpi--success'; ?>">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Reseñas pendientes', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( number_format_i18n( (int) $summary['pending'] ) ); ?></div>
				</div>
				<div class="af-kpi af-kpi--accent">
					<div class="af-kpi__head"><span class="af-kpi__label"><?php esc_html_e( 'Promedio otorgado', 'arriendo-facil' ); ?></span></div>
					<div class="af-kpi__value"><?php echo esc_html( number_format( (float) $summary['avg_stars'], 2 ) ); ?></div>
				</div>
			</div>

			<section class="af-section">
				<div class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Calificar estancia', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Este botón genera un enlace seguro y temporal del flujo de reseñas existente, sin alterar la lógica actual de tokens.', 'arriendo-facil' ); ?></p>
					</div>
				</div>
				<p>
					<button type="button" class="button af-btn af-btn--primary" id="af-tenant-open-review-flow" data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Abrir mis reseñas pendientes', 'arriendo-facil' ); ?>
					</button>
				</p>
				<p id="af-tenant-review-feedback" aria-live="polite"></p>
			</section>
		</div>

		<script>
		(function(){
			const button = document.getElementById('af-tenant-open-review-flow');
			const feedback = document.getElementById('af-tenant-review-feedback');
			if(!button || !feedback){ return; }

			button.addEventListener('click', async function(){
				button.disabled = true;
				feedback.textContent = <?php echo wp_json_encode( __( 'Generando enlace de reseña...', 'arriendo-facil' ) ); ?>;

				const payload = new URLSearchParams();
				payload.set('action', 'af_tenant_request_review_link');
				payload.set('nonce', button.getAttribute('data-nonce') || '');

				let json = null;
				try {
					const response = await fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
						body: payload.toString()
					});
					json = await response.json();
				} catch (err) {
					button.disabled = false;
					feedback.textContent = <?php echo wp_json_encode( __( 'No se pudo contactar al servidor. Intenta nuevamente.', 'arriendo-facil' ) ); ?>;
					return;
				}

				button.disabled = false;
				if(!json || !json.success){
					feedback.textContent = (json && json.data && json.data.message)
						? String(json.data.message)
						: <?php echo wp_json_encode( __( 'No fue posible abrir tus reseñas en este momento.', 'arriendo-facil' ) ); ?>;
					return;
				}

				const reviewUrl = json && json.data && json.data.review_url ? String(json.data.review_url) : '';
				feedback.textContent = (json && json.data && json.data.message)
					? String(json.data.message)
					: <?php echo wp_json_encode( __( 'Enlace generado correctamente.', 'arriendo-facil' ) ); ?>;

				if(reviewUrl){
					window.location.href = reviewUrl;
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Renders tenant calendar section.
	 *
	 * @return void
	 */
	public function render_tenant_calendar_page() {
		if ( ! self::is_restricted_tenant() ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta sección.', 'arriendo-facil' ) );
		}

		$events = $this->get_tenant_calendar_events( 80 );
		?>
		<div class="wrap af-shell af-tenant-portal">
			<div class="af-page-header">
				<div class="af-page-header__title">
					<span class="af-page-header__eyebrow"><?php esc_html_e( 'Agenda', 'arriendo-facil' ); ?></span>
					<h1><?php esc_html_e( 'Calendario de inquilino', 'arriendo-facil' ); ?></h1>
					<p class="af-page-header__subtitle"><?php esc_html_e( 'Vista cronológica de estancias y visitas próximas, estilo experiencia de viaje.', 'arriendo-facil' ); ?></p>
				</div>
			</div>

			<section class="af-section">
				<div class="af-section__header">
					<div>
						<h2 class="af-section__title"><?php esc_html_e( 'Eventos próximos', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php esc_html_e( 'Línea de tiempo de estancias y visitas confirmadas.', 'arriendo-facil' ); ?></p>
					</div>
				</div>
				<?php if ( empty( $events ) ) : ?>
					<p><?php esc_html_e( 'No tienes eventos próximos en el calendario.', 'arriendo-facil' ); ?></p>
				<?php else : ?>
					<table class="widefat striped af-data-table af-data-table--tenant">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Fecha', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></th>
								<th><?php esc_html_e( 'Detalle', 'arriendo-facil' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $events as $event ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Fecha', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $event['date'] ) ? wp_date( 'd/m/Y', strtotime( (string) $event['date'] ) ) : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Tipo', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $event['type_label'] ) ? (string) $event['type_label'] : '' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Detalle', 'arriendo-facil' ); ?>"><?php echo esc_html( isset( $event['detail'] ) ? (string) $event['detail'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Returns tenant context and summary stats for dashboard sections.
	 *
	 * @return array<string,mixed>
	 */
	private function get_tenant_portal_context() {
		$user = wp_get_current_user();
		if ( ! ( $user instanceof WP_User ) ) {
			return array(
				'user'     => null,
				'email'    => '',
				'guest_id' => 0,
				'stats'    => array(),
			);
		}

		global $wpdb;
		$email = sanitize_email( (string) $user->user_email );

		$guest_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}af_guests WHERE user_id = %d ORDER BY id DESC LIMIT 1",
				(int) $user->ID
			)
		);

		if ( ! $guest_id && is_email( $email ) ) {
			$guest_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}af_guests WHERE email = %s ORDER BY id DESC LIMIT 1",
					$email
				)
			);
		}

		$lease_total  = 0;
		$lease_active = 0;
		if ( $guest_id || is_email( $email ) ) {
			$lease_total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$wpdb->prefix}af_leases l
					 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
					 WHERE l.deleted_at IS NULL
					   AND ((%d > 0 AND l.guest_id = %d) OR g.email = %s)",
					$guest_id,
					$guest_id,
					$email
				)
			);

			$lease_active = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$wpdb->prefix}af_leases l
					 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
					 WHERE l.deleted_at IS NULL
					   AND l.status = %s
					   AND ((%d > 0 AND l.guest_id = %d) OR g.email = %s)",
					'active',
					$guest_id,
					$guest_id,
					$email
				)
			);
		}

		$today = gmdate( 'Y-m-d' );
		$visit_upcoming = is_email( $email ) ? (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}af_visit_bookings vb
				 LEFT JOIN {$wpdb->prefix}af_visit_slots vs ON vs.id = vb.slot_id
				 WHERE vb.guest_email = %s
				   AND vb.status IN ('confirmed','completed')
				   AND vs.visit_date >= %s",
				$email,
				$today
			)
		) : 0;

		$reviews_pending = 0;
		if ( is_email( $email ) ) {
			$review_now = gmdate( 'Y-m-d H:i:s' );
			$user_id    = (int) $user->ID;

			if ( class_exists( 'Arriendo_Facil_Review' ) && Arriendo_Facil_Review::groups_tenant_user_column_exists() ) {
				$reviews_pending = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}af_review_groups
						 WHERE reviewer_type = %s
						   AND status IN ('pending','sent')
						   AND (due_at IS NULL OR due_at >= %s)
						   AND (
							 tenant_user_id = %d
							 OR ((tenant_user_id IS NULL OR tenant_user_id = 0) AND tenant_email = %s)
						   )",
						'tenant',
						$review_now,
						$user_id,
						$email
					)
				);
			} else {
				$reviews_pending = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}af_review_groups
						 WHERE tenant_email = %s
						   AND reviewer_type = %s
						   AND status IN ('pending','sent')
						   AND (due_at IS NULL OR due_at >= %s)",
						$email,
						'tenant',
						$review_now
					)
				);
			}
		}

		return array(
			'user'     => $user,
			'email'    => $email,
			'guest_id' => $guest_id,
			'stats'    => array(
				'lease_total'      => $lease_total,
				'lease_active'     => $lease_active,
				'visit_upcoming'   => $visit_upcoming,
				'reviews_pending'  => $reviews_pending,
			),
		);
	}

	/**
	 * Returns tenant lease rows for history and calendar sections.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_tenant_lease_rows( $limit = 40 ) {
		$context = $this->get_tenant_portal_context();
		$email   = isset( $context['email'] ) ? sanitize_email( (string) $context['email'] ) : '';
		$guest_id = isset( $context['guest_id'] ) ? absint( $context['guest_id'] ) : 0;

		if ( ! $guest_id && ! is_email( $email ) ) {
			return array();
		}

		global $wpdb;
		$limit = max( 1, absint( $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.accommodation_id, l.start_date, l.end_date, l.monthly_rent, l.status,
				        COALESCE(NULLIF(p.post_title, ''), %s) AS accommodation_title
				 FROM {$wpdb->prefix}af_leases l
				 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 WHERE l.deleted_at IS NULL
				   AND ((%d > 0 AND l.guest_id = %d) OR g.email = %s)
				 ORDER BY l.start_date DESC, l.id DESC
				 LIMIT %d",
				__( 'Alojamiento', 'arriendo-facil' ),
				$guest_id,
				$guest_id,
				$email,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$status_map = array(
			'active'    => __( 'Activo', 'arriendo-facil' ),
			'draft'     => __( 'Borrador', 'arriendo-facil' ),
			'completed' => __( 'Completado', 'arriendo-facil' ),
			'cancelled' => __( 'Cancelado', 'arriendo-facil' ),
		);

		foreach ( $rows as &$row ) {
			$status = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
			$row['status_label'] = isset( $status_map[ $status ] ) ? $status_map[ $status ] : ucfirst( $status );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Returns tenant review summary.
	 *
	 * @return array<string,mixed>
	 */
	private function get_tenant_reviews_summary() {
		$context = $this->get_tenant_portal_context();
		$email   = isset( $context['email'] ) ? sanitize_email( (string) $context['email'] ) : '';
		$user_id = isset( $context['user'] ) && $context['user'] instanceof WP_User ? (int) $context['user']->ID : 0;

		if ( ! is_email( $email ) ) {
			return array(
				'completed' => 0,
				'pending'   => 0,
				'avg_stars' => 0,
			);
		}

		global $wpdb;

		$use_user_link_groups  = class_exists( 'Arriendo_Facil_Review' ) && Arriendo_Facil_Review::groups_tenant_user_column_exists();
		$use_user_link_reviews = class_exists( 'Arriendo_Facil_Review' ) && Arriendo_Facil_Review::reviews_tenant_user_column_exists();

		if ( $use_user_link_reviews ) {
			$completed = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews
					 WHERE review_direction IN ('tenant_to_owner','tenant_to_property')
					   AND status = 'completed'
					   AND (
						 tenant_user_id = %d
						 OR ((tenant_user_id IS NULL OR tenant_user_id = 0) AND tenant_email = %s)
					   )",
					$user_id,
					$email
				)
			);
		} else {
			$completed = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}af_reviews
					 WHERE tenant_email = %s
					   AND review_direction IN ('tenant_to_owner','tenant_to_property')
					   AND status = 'completed'",
					$email
				)
			);
		}

		if ( $use_user_link_groups ) {
			$pending = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}af_review_groups
					 WHERE reviewer_type = %s
					   AND status IN ('pending','sent')
					   AND (due_at IS NULL OR due_at >= %s)
					   AND (
						 tenant_user_id = %d
						 OR ((tenant_user_id IS NULL OR tenant_user_id = 0) AND tenant_email = %s)
					   )",
					'tenant',
					gmdate( 'Y-m-d H:i:s' ),
					$user_id,
					$email
				)
			);
		} else {
			$pending = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}af_review_groups
					 WHERE tenant_email = %s
					   AND reviewer_type = %s
					   AND status IN ('pending','sent')
					   AND (due_at IS NULL OR due_at >= %s)",
					$email,
					'tenant',
					gmdate( 'Y-m-d H:i:s' )
				)
			);
		}

		if ( $use_user_link_reviews ) {
			$avg_stars = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT AVG(stars) FROM {$wpdb->prefix}af_reviews
					 WHERE review_direction IN ('tenant_to_owner','tenant_to_property')
					   AND status = 'completed'
					   AND (
						 tenant_user_id = %d
						 OR ((tenant_user_id IS NULL OR tenant_user_id = 0) AND tenant_email = %s)
					   )",
					$user_id,
					$email
				)
			);
		} else {
			$avg_stars = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT AVG(stars) FROM {$wpdb->prefix}af_reviews
					 WHERE tenant_email = %s
					   AND review_direction IN ('tenant_to_owner','tenant_to_property')
					   AND status = 'completed'",
					$email
				)
			);
		}

		return array(
			'completed' => $completed,
			'pending'   => $pending,
			'avg_stars' => $avg_stars,
		);
	}

	/**
	 * Returns tenant visit rows aligned to reservation-style tracking.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_tenant_visit_rows( $limit = 30 ) {
		$context = $this->get_tenant_portal_context();
		$email   = isset( $context['email'] ) ? sanitize_email( (string) $context['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return array();
		}

		global $wpdb;
		$limit = max( 1, absint( $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vb.id, vb.status, vs.visit_date, vs.start_time,
				        COALESCE(NULLIF(p.post_title, ''), %s) AS accommodation_title
				 FROM {$wpdb->prefix}af_visit_bookings vb
				 LEFT JOIN {$wpdb->prefix}af_visit_slots vs ON vs.id = vb.slot_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = vb.accommodation_id
				 WHERE vb.guest_email = %s
				 ORDER BY vs.visit_date DESC, vb.id DESC
				 LIMIT %d",
				__( 'Alojamiento', 'arriendo-facil' ),
				$email,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$status_map = array(
			'confirmed' => __( 'Confirmada', 'arriendo-facil' ),
			'completed' => __( 'Completada', 'arriendo-facil' ),
			'cancelled' => __( 'Cancelada', 'arriendo-facil' ),
		);

		foreach ( $rows as &$row ) {
			$status = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
			$row['status_label'] = isset( $status_map[ $status ] ) ? $status_map[ $status ] : ucfirst( $status );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Returns sorted tenant calendar events from leases and visit bookings.
	 *
	 * @param int $limit Max events.
	 * @return array<int,array<string,string>>
	 */
	private function get_tenant_calendar_events( $limit = 60 ) {
		$events = array();
		$today  = gmdate( 'Y-m-d' );

		$leases = $this->get_tenant_lease_rows( max( 20, absint( $limit ) ) );
		foreach ( $leases as $lease ) {
			$end_date = isset( $lease['end_date'] ) ? (string) $lease['end_date'] : '';
			if ( '' === $end_date || $end_date < $today ) {
				continue;
			}

			$events[] = array(
				'date'       => isset( $lease['start_date'] ) ? (string) $lease['start_date'] : $today,
				'type_label' => __( 'Inicio de estancia', 'arriendo-facil' ),
				'detail'     => sprintf(
					/* translators: 1: accommodation title 2: end date */
					__( '%1$s hasta %2$s', 'arriendo-facil' ),
					isset( $lease['accommodation_title'] ) ? (string) $lease['accommodation_title'] : __( 'Alojamiento', 'arriendo-facil' ),
					wp_date( 'd/m/Y', strtotime( $end_date ) )
				),
			);
		}

		$context = $this->get_tenant_portal_context();
		$email   = isset( $context['email'] ) ? sanitize_email( (string) $context['email'] ) : '';
		if ( is_email( $email ) ) {
			global $wpdb;
			$visit_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT vs.visit_date, vs.start_time, vb.status,
					        COALESCE(NULLIF(p.post_title, ''), %s) AS accommodation_title
					 FROM {$wpdb->prefix}af_visit_bookings vb
					 LEFT JOIN {$wpdb->prefix}af_visit_slots vs ON vs.id = vb.slot_id
					 LEFT JOIN {$wpdb->posts} p ON p.ID = vb.accommodation_id
					 WHERE vb.guest_email = %s
					   AND vb.status IN ('confirmed','completed')
					   AND vs.visit_date >= %s
					 ORDER BY vs.visit_date ASC
					 LIMIT %d",
					__( 'Alojamiento', 'arriendo-facil' ),
					$email,
					$today,
					max( 10, absint( $limit ) )
				),
				ARRAY_A
			);

			if ( is_array( $visit_rows ) ) {
				foreach ( $visit_rows as $visit ) {
					$visit_date = isset( $visit['visit_date'] ) ? (string) $visit['visit_date'] : '';
					if ( '' === $visit_date ) {
						continue;
					}

					$events[] = array(
						'date'       => $visit_date,
						'type_label' => __( 'Visita agendada', 'arriendo-facil' ),
						'detail'     => sprintf(
							/* translators: 1: accommodation title 2: start time */
							__( '%1$s a las %2$s', 'arriendo-facil' ),
							isset( $visit['accommodation_title'] ) ? (string) $visit['accommodation_title'] : __( 'Alojamiento', 'arriendo-facil' ),
							isset( $visit['start_time'] ) ? (string) substr( (string) $visit['start_time'], 0, 5 ) : '--:--'
						),
					);
				}
			}
		}

		usort(
			$events,
			static function ( $a, $b ) {
				$date_a = isset( $a['date'] ) ? strtotime( (string) $a['date'] ) : 0;
				$date_b = isset( $b['date'] ) ? strtotime( (string) $b['date'] ) : 0;
				if ( $date_a === $date_b ) {
					return 0;
				}
				return ( $date_a < $date_b ) ? -1 : 1;
			}
		);

		if ( count( $events ) > $limit ) {
			$events = array_slice( $events, 0, $limit );
		}

		return $events;
	}
}
