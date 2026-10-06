<?php
/**
 * Contratos admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$lease_service = class_exists( 'Arriendo_Facil_Lease' ) ? new Arriendo_Facil_Lease() : null;

$is_owner = Arriendo_Facil_Accommodation::user_is_owner();

if ( $is_owner ) {
	$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
	if ( ! empty( $owner_ids ) ) {
		$ids_sql = implode( ',', array_map( 'intval', $owner_ids ) );
		$leases = $wpdb->get_results(
			"SELECT l.*, p.post_title AS accommodation_title,
			        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
			 FROM {$wpdb->prefix}af_leases l
			 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
			 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
			 WHERE l.accommodation_id IN ($ids_sql) AND l.deleted_at IS NULL
			 ORDER BY l.created_at DESC
			 LIMIT 100"
		);
	} else {
		$leases = array();
	}
} else {
	$leases = $wpdb->get_results(
		"SELECT l.*, p.post_title AS accommodation_title,
		        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
		 FROM {$wpdb->prefix}af_leases l
		 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
		 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
		 WHERE l.deleted_at IS NULL
		 ORDER BY l.created_at DESC
		 LIMIT 100"
	);
}

// Unidad vinculada y saldo pendiente por contrato (batch, sin N+1).
$lease_unit_map    = array();
$lease_balance_map = array();
if ( ! empty( $leases ) ) {
	$lease_ids_sql = implode( ',', array_map( static function ( $l ) { return (int) $l->id; }, $leases ) );

	if ( class_exists( 'Arriendo_Facil_Property_Structure' ) ) {
		$unit_rows = $wpdb->get_results(
			'SELECT u.accommodation_id, u.unit_code FROM ' . Arriendo_Facil_Property_Structure::units_table() . '
			 WHERE accommodation_id IN (' . implode( ',', array_map( static function ( $l ) { return (int) $l->accommodation_id; }, $leases ) ) . ')'
		);
		foreach ( (array) $unit_rows as $unit_row ) {
			$lease_unit_map[ (int) $unit_row->accommodation_id ] = $unit_row->unit_code;
		}
	}

	if ( class_exists( 'Arriendo_Facil_Billing_Ledger' ) ) {
		$balance_rows = $wpdb->get_results(
			'SELECT lease_id, SUM(amount - amount_paid) AS balance FROM ' . Arriendo_Facil_Billing_Ledger::charges_table() . "
			 WHERE lease_id IN ({$lease_ids_sql}) AND status != 'void'
			 GROUP BY lease_id"
		);
		foreach ( (array) $balance_rows as $balance_row ) {
			$lease_balance_map[ (int) $balance_row->lease_id ] = round( (float) $balance_row->balance, 2 );
		}
	}
}


// ── Batch-fetch billing state for the CURRENT period per visible lease ──────
$billing_status_map    = array();
$billing_current_month = class_exists( 'Arriendo_Facil_Billing_Manager' )
	? Arriendo_Facil_Billing_Manager::billing_period()
	: gmdate( 'Y-m' );

if ( ! empty( $leases ) ) {
	$lease_ids = array_filter( array_map( function( $l ) { return (int) $l->id; }, (array) $leases ) );
	if ( ! empty( $lease_ids ) ) {
		$ids_sql = implode( ',', $lease_ids );
		// Current-period invoice (may be null → button shows).
		$billing_rows_period = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT lease_id, id AS invoice_id, estado
				 FROM {$wpdb->prefix}af_electronic_invoices
				 WHERE lease_id IN ($ids_sql)
				   AND billing_period = %s
				 ORDER BY id DESC",
				$billing_current_month
			)
		);
		foreach ( (array) $billing_rows_period as $br ) {
			$billing_status_map[ (int) $br->lease_id ] = array(
				'invoice_id' => (int) $br->invoice_id,
				'estado'     => (string) $br->estado,
				'period'     => $billing_current_month,
			);
		}

		// For leases with no current-period invoice, check if there's any invoice at all
		// (to show last state as reference without blocking the Emitir button).
		$no_current = array_diff(
			$lease_ids,
			array_keys( $billing_status_map )
		);
		if ( ! empty( $no_current ) ) {
			$no_ids_sql  = implode( ',', array_map( 'intval', $no_current ) );
			$prev_rows   = $wpdb->get_results(
				"SELECT b.lease_id, b.id AS invoice_id, b.estado, b.billing_period
				 FROM {$wpdb->prefix}af_electronic_invoices b
				 INNER JOIN (
				     SELECT lease_id, MAX(id) AS max_id
				     FROM {$wpdb->prefix}af_electronic_invoices
				     WHERE lease_id IN ($no_ids_sql)
				     GROUP BY lease_id
				 ) latest ON latest.max_id = b.id"
			);
			foreach ( (array) $prev_rows as $br ) {
				// Mark as previous period so the view knows to show the Emitir button too.
				$billing_status_map[ (int) $br->lease_id ] = array(
					'invoice_id'  => (int) $br->invoice_id,
					'estado'      => (string) $br->estado,
					'period'      => (string) $br->billing_period,
					'is_previous' => true,
				);
			}
		}
	}
}

$billing_nonce = wp_create_nonce( 'af_billing_nonce' );
$can_bill      = current_user_can( (string) apply_filters( 'af_billing_capability', 'manage_options' ) );

$total_leases = is_array( $leases ) ? count( $leases ) : 0;
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Gestión de arrendamientos', 'arriendo-facil' ),
			'title'    => __( 'Contratos', 'arriendo-facil' ),
			'subtitle' => __( 'Todos los contratos vigentes, borradores y terminados. Actívalos, sube versiones y emite facturas del período.', 'arriendo-facil' ),
			'actions'  => array(
				sprintf(
					'<button type="button" class="button af-btn af-btn--primary" id="af-new-lease"><span class="af-btn__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>%s</button>',
					esc_html__( 'Nuevo contrato', 'arriendo-facil' )
				),
			),
		)
	);
	?>

	<?php
	// Filter accommodations by current user's scope
	// Note: accessible_accommodation_ids() returns null if user is admin (can manage all)
	$accessible_acc_ids = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
	
	if ( null === $accessible_acc_ids ) {
		// User is admin - can see all accommodations
		$lease_accommodations = get_posts(
			array(
				'post_type'      => 'accommodation',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$ids_sql = null; // Will be handled in guest query
	} elseif ( ! empty( $accessible_acc_ids ) ) {
		// User is owner - can only see their accommodations
		$lease_accommodations = get_posts(
			array(
				'post_type'      => 'accommodation',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post__in'       => $accessible_acc_ids,
			)
		);
		$ids_sql = implode( ',', array_map( 'intval', $accessible_acc_ids ) );
	} else {
		// User has no accommodations
		$lease_accommodations = array();
		$ids_sql = null;
	}

	// Inquilinos del formulario: SIEMPRE dentro del alcance del usuario.
	// Un huésped sin inmueble no es "de nadie", así que no se lista para
	// nadie: dejar `accommodation_id IS NULL/0` aquí hacía que un propietario
	// viera inquilinos de otras carteras (la página de Inquilinos ya filtra
	// con `WHERE accommodation_id IN (...)`).
	$af_guest_columns = "SELECT g.id, g.first_name, g.last_name, g.email, g.accommodation_id,
			        g.rental_start_date, g.rental_end_date, g.desired_price, g.guarantee_text, g.doc_status,
			        g.id_number, g.nationality, a.post_title AS accommodation_title
			 FROM {$wpdb->prefix}af_guests g
			 LEFT JOIN {$wpdb->posts} a ON a.ID = g.accommodation_id";

	if ( null === $accessible_acc_ids ) {
		// Sin restricciones (puede gestionar todo): todos los inquilinos.
		$lease_guests = $wpdb->get_results(
			$af_guest_columns . '
			 ORDER BY g.first_name ASC
			 LIMIT 300'
		);
	} elseif ( ! empty( $accessible_acc_ids ) ) {
		// Propietario/gestor: los de sus inmuebles, más cualquier inquilino
		// que ya tenga un contrato suyo (vínculo anterior a la auto-vínculo).
		$lease_guests = $wpdb->get_results(
			$af_guest_columns . "
			 WHERE g.accommodation_id IN ($ids_sql)
			    OR EXISTS (
			        SELECT 1 FROM {$wpdb->prefix}af_leases l
			         WHERE l.guest_id = g.id
			           AND l.accommodation_id IN ($ids_sql)
			           AND l.deleted_at IS NULL
			    )
			 ORDER BY g.first_name ASC
			 LIMIT 300" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $ids_sql es enteros casteados arriba.
		);
	} else {
		// Sin inmuebles: no hay cartera de inquilinos que mostrar.
		$lease_guests = array();
	}
	?>

	<!-- Contract Process Steps -->
	<div class="af-section" style="padding: var(--af-space-4); margin-bottom: var(--af-space-5); background:#f9fafb; border-radius: 8px;">
		<h3 style="margin: 0 0 var(--af-space-3); font-size: 14px; font-weight: 600; text-transform: uppercase; color: #6b7280;">
			<?php esc_html_e( 'Flujo de Contratos', 'arriendo-facil' ); ?>
		</h3>
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: var(--af-space-3);">
			<div style="padding: var(--af-space-3); background: #fff; border-radius: 6px; border-left: 3px solid #10b981;">
				<div style="font-weight: 600; font-size: 13px; color: #111; margin-bottom: 4px;">1. <?php esc_html_e( 'Registrar Inquilino', 'arriendo-facil' ); ?></div>
				<p style="margin: 0; font-size: 12px; color: #6b7280;">
					<?php esc_html_e( 'Completa datos de identidad y referencias.', 'arriendo-facil' ); ?>
				</p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-guests' ) ); ?>" style="display: inline-block; margin-top: 6px; font-size: 12px; color: #0891b2; text-decoration: none; font-weight: 500;">
					<?php esc_html_e( 'Ir a Inquilinos →', 'arriendo-facil' ); ?>
				</a>
			</div>
			<div style="padding: var(--af-space-3); background: #fff; border-radius: 6px; border-left: 3px solid #3b82f6;">
				<div style="font-weight: 600; font-size: 13px; color: #111; margin-bottom: 4px;">2. <?php esc_html_e( 'Crear Contrato', 'arriendo-facil' ); ?></div>
				<p style="margin: 0; font-size: 12px; color: #6b7280;">
					<?php esc_html_e( 'Vincula inmueble + inquilino + datos financieros.', 'arriendo-facil' ); ?>
				</p>
				<button type="button" id="af-new-lease-from-steps" style="display: inline-block; margin-top: 6px; font-size: 12px; background: none; border: none; color: #0891b2; text-decoration: none; font-weight: 500; cursor: pointer; padding: 0;">
					<?php esc_html_e( 'Crear Contrato →', 'arriendo-facil' ); ?>
				</button>
			</div>
			<div style="padding: var(--af-space-3); background: #fff; border-radius: 6px; border-left: 3px solid #8b5cf6;">
				<div style="font-weight: 600; font-size: 13px; color: #111; margin-bottom: 4px;">3. <?php esc_html_e( 'Activar', 'arriendo-facil' ); ?></div>
				<p style="margin: 0; font-size: 12px; color: #6b7280;">
					<?php esc_html_e( 'Sube documento y activa para emitir facturas.', 'arriendo-facil' ); ?>
				</p>
			</div>
		</div>
	</div>

	<div id="af-lease-form-card" class="af-section" style="padding: var(--af-space-5); margin-bottom: var(--af-space-4); display:none;">
		<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--af-space-3);">
			<h2 class="af-section__title" style="margin:0;"><?php esc_html_e( 'Nuevo Contrato', 'arriendo-facil' ); ?></h2>
			<button type="button" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #9ca3af; padding: 0;" id="af-lease-form-close" aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		</div>
		<p class="af-modal__hint" style="margin:0 0 var(--af-space-4);">
			<?php esc_html_e( 'Selecciona el inmueble e inquilino ya registrados. El sistema generará automáticamente el contrato con los datos.', 'arriendo-facil' ); ?>
		</p>

		<?php
		$af_ph_sections    = class_exists( 'Arriendo_Facil_Contract_Storage' ) ? Arriendo_Facil_Contract_Storage::get_placeholders_by_section() : array();
		$af_ph_generator   = class_exists( 'Arriendo_Facil_Contract_Generator' ) ? new Arriendo_Facil_Contract_Generator() : null;
		$af_ph_owner_cache = array();

		// Cada grupo de placeholders se dibuja en el paso que le corresponde.
		// El paso 5 (Datos Bancarios) es opcional: ningún campo es obligatorio.
		$af_step_groups = array(
			1 => array( 'inquilino' ),
			2 => array( 'inmueble' ),
			4 => array( 'propietario', 'financiero', 'garantia', 'fecha_lugar' ),
			5 => array( 'bancario' ),
		);

		$af_render_section = static function ( $section_id ) use ( $af_ph_sections ) {
			if ( empty( $af_ph_sections[ $section_id ]['placeholders'] ) ) {
				return;
			}
			$section_data = $af_ph_sections[ $section_id ];
			?>
			<div class="af-cwiz__group">
				<h3 class="af-cwiz__group-title"><?php echo esc_html( $section_data['label'] ); ?></h3>
				<div class="af-form-grid">
					<?php foreach ( $section_data['placeholders'] as $placeholder => $config ) :
						$field_id   = 'af-ph-' . sanitize_title( $placeholder );
						$field_name = 'placeholder_' . $placeholder;
						$input_type = 'text';
						$input_attrs = '';

						switch ( $config['type'] ) {
							case 'date':
								$input_type = 'date';
								break;
							case 'year':
								$input_type = 'number';
								$input_attrs = ' inputmode="numeric" min="1900" max="2100"';
								break;
							case 'month':
							case 'day':
							case 'integer':
								$input_type = 'number';
								$input_attrs = ' step="1"';
								break;
							case 'decimal':
								$input_type = 'number';
								$input_attrs = ' step="0.01" min="0"';
								break;
							case 'select':
								$input_type = 'select';
								break;
							case 'textarea':
								$input_type = 'textarea';
								break;
						}

						$source   = (string) ( $config['source'] ?? '' );
						$computed = (string) ( $config['computed'] ?? '' );
						$is_auto  = ( '' !== $source && 'manual' !== $source ) || '' !== $computed;
						?>
						<div class="af-form-field<?php echo $is_auto ? ' af-cwiz__field--auto' : ''; ?>"
							data-ph="<?php echo esc_attr( $placeholder ); ?>"
							data-source="<?php echo esc_attr( $source ); ?>"
							data-computed="<?php echo esc_attr( $computed ); ?>"
							data-default="<?php echo esc_attr( (string) ( $config['default'] ?? '' ) ); ?>">
							<label class="af-form-field__label" for="<?php echo esc_attr( $field_id ); ?>">
								<?php echo esc_html( $config['label'] ); ?>
								<?php if ( ! empty( $config['required'] ) ) : ?>
									<span class="af-required">*</span>
								<?php endif; ?>
								<?php if ( $is_auto ) : ?>
									<span class="af-cwiz__badge" hidden><?php esc_html_e( 'Auto', 'arriendo-facil' ); ?></span>
								<?php endif; ?>
							</label>

							<?php if ( 'select' === $input_type ) : ?>
								<select name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_id ); ?>"<?php echo ! empty( $config['required'] ) ? ' required' : ''; ?>>
									<option value=""><?php esc_html_e( '— Seleccionar —', 'arriendo-facil' ); ?></option>
									<?php foreach ( (array) ( $config['options'] ?? array() ) as $opt_value => $opt_label ) : ?>
										<option value="<?php echo esc_attr( $opt_value ); ?>"><?php echo esc_html( $opt_label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( 'textarea' === $input_type ) : ?>
								<textarea name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_id ); ?>" rows="3"<?php echo ! empty( $config['required'] ) ? ' required' : ''; ?>></textarea>
							<?php else : ?>
								<input type="<?php echo esc_attr( $input_type ); ?>" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_id ); ?>"<?php echo ! empty( $config['required'] ) ? ' required' : ''; ?><?php echo $input_attrs ? wp_kses_post( $input_attrs ) : ''; ?> />
							<?php endif; ?>

							<?php if ( $is_auto ) : ?>
								<span class="af-form-field__hint"><?php esc_html_e( 'Se rellena solo; puedes editarlo si hace falta.', 'arriendo-facil' ); ?></span>
							<?php elseif ( ! empty( $config['description'] ) ) : ?>
								<span class="af-form-field__hint"><?php echo esc_html( $config['description'] ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		};
		?>


		<form id="af-lease-form" novalidate>
			<p class="af-modal__status" id="af-lease-status" style="display:none;"></p>

			<div class="af-cwiz__progress" id="af-wizard-progress"
				role="progressbar" aria-valuemin="1" aria-valuemax="6" aria-valuenow="1"
				aria-label="<?php esc_attr_e( 'Progreso del formulario de contrato', 'arriendo-facil' ); ?>">
				<div class="af-cwiz__progress-head">
					<span class="af-cwiz__progress-label" id="af-wizard-progress-label"><?php esc_html_e( 'Paso 1 de 6 — Partes', 'arriendo-facil' ); ?></span>
					<span class="af-cwiz__progress-pct" id="af-wizard-progress-pct">17%</span>
				</div>
				<div class="af-cwiz__progress-track" aria-hidden="true">
					<span class="af-cwiz__progress-fill" id="af-wizard-progress-fill" style="width:17%"></span>
				</div>
			</div>

			<ol class="af-cwiz__steps" id="af-wizard-steps">
				<li class="is-current"><button type="button" class="af-cwiz__step" data-goto="1" data-label="<?php esc_attr_e( 'Partes', 'arriendo-facil' ); ?>"><span class="af-cwiz__num">1</span> <?php esc_html_e( 'Partes', 'arriendo-facil' ); ?></button></li>
				<li><button type="button" class="af-cwiz__step" data-goto="2" data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>"><span class="af-cwiz__num">2</span> <?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></button></li>
				<li><button type="button" class="af-cwiz__step" data-goto="3" data-label="<?php esc_attr_e( 'Vigencia', 'arriendo-facil' ); ?>"><span class="af-cwiz__num">3</span> <?php esc_html_e( 'Vigencia', 'arriendo-facil' ); ?></button></li>
				<li><button type="button" class="af-cwiz__step" data-goto="4" data-label="<?php esc_attr_e( 'Documento', 'arriendo-facil' ); ?>"><span class="af-cwiz__num">4</span> <?php esc_html_e( 'Documento', 'arriendo-facil' ); ?></button></li>
				<li><button type="button" class="af-cwiz__step" data-goto="5" data-label="<?php esc_attr_e( 'Banco', 'arriendo-facil' ); ?>"><span class="af-cwiz__num">5</span> <?php esc_html_e( 'Banco', 'arriendo-facil' ); ?></button></li>
				<li><button type="button" class="af-cwiz__step" data-goto="6" data-label="<?php esc_attr_e( 'Revisar', 'arriendo-facil' ); ?>"><span class="af-cwiz__num">6</span> <?php esc_html_e( 'Revisar', 'arriendo-facil' ); ?></button></li>
			</ol>

			<p class="af-cwiz__error" id="af-wizard-error" role="alert"></p>

			<!-- ── Paso 1: Partes ────────────────────────────────────────────── -->
			<section class="af-cwiz__panel" data-step="1">
				<div class="af-cwiz__group">
					<h3 class="af-cwiz__group-title"><?php esc_html_e( 'Partes del contrato', 'arriendo-facil' ); ?></h3>
					<div class="af-form-grid">
						<div class="af-form-field">
							<label class="af-form-field__label" for="af-lease-guest"><?php esc_html_e( 'Inquilino', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
							<select name="guest_id" id="af-lease-guest" required>
								<option value=""><?php esc_html_e( '— Seleccionar —', 'arriendo-facil' ); ?></option>
								<?php foreach ( (array) $lease_guests as $lease_guest ) : ?>
									<option value="<?php echo esc_attr( (int) $lease_guest->id ); ?>"
										data-accommodation="<?php echo esc_attr( (int) $lease_guest->accommodation_id ); ?>"
										data-start="<?php echo esc_attr( (string) $lease_guest->rental_start_date ); ?>"
										data-end="<?php echo esc_attr( (string) $lease_guest->rental_end_date ); ?>"
										data-price="<?php echo esc_attr( (string) $lease_guest->desired_price ); ?>"
										data-doc-status="<?php echo esc_attr( (string) $lease_guest->doc_status ); ?>"
										data-guest-name="<?php echo esc_attr( trim( $lease_guest->first_name . ' ' . $lease_guest->last_name ) ); ?>"
										data-document-id="<?php echo esc_attr( (string) $lease_guest->id_number ); ?>"
										data-nationality="<?php echo esc_attr( (string) $lease_guest->nationality ); ?>">
										<?php
										echo esc_html( trim( $lease_guest->first_name . ' ' . $lease_guest->last_name ) );
										if ( 'verificado' === (string) $lease_guest->doc_status ) {
											echo esc_html( ' ✓' );
										}
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<span class="af-form-field__hint">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-guests' ) ); ?>"><?php esc_html_e( '+ Registrar nuevo inquilino', 'arriendo-facil' ); ?></a>
							</span>
						</div>

						<div class="af-form-field">
							<label class="af-form-field__label" for="af-lease-accommodation"><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
							<select name="accommodation_id" id="af-lease-accommodation" required>
								<option value=""><?php esc_html_e( '— Seleccionar —', 'arriendo-facil' ); ?></option>
								<?php foreach ( $lease_accommodations as $lease_accommodation ) :
									$acc_id           = (int) $lease_accommodation->ID;
									$acc_address      = (string) get_post_meta( $acc_id, '_af_address', true );
									$acc_type         = (string) get_post_meta( $acc_id, '_af_property_type', true );
									$acc_bedrooms     = (int) get_post_meta( $acc_id, '_af_bedrooms', true );
									$acc_bathrooms    = (int) get_post_meta( $acc_id, '_af_bathrooms', true );
									$acc_rent         = (string) get_post_meta( $acc_id, '_af_monthly_rent', true );
									$acc_parking      = get_post_meta( $acc_id, '_af_parking_spots', true );
									$acc_sqm          = get_post_meta( $acc_id, '_af_square_meters', true );
									$acc_furnished    = (string) get_post_meta( $acc_id, '_af_furnished', true );
									$acc_condition    = (string) get_post_meta( $acc_id, '_af_condition', true );

									if ( $af_ph_generator && ! isset( $af_ph_owner_cache[ $acc_id ] ) ) {
										$af_ph_owner_cache[ $acc_id ] = $af_ph_generator->get_owner_identity_for_accommodation( $acc_id );
									}
									$acc_owner = $af_ph_owner_cache[ $acc_id ] ?? array( 'name' => '', 'id_number' => '' );
									?>
									<option value="<?php echo esc_attr( $acc_id ); ?>"
										data-rent="<?php echo esc_attr( $acc_rent ); ?>"
										data-address="<?php echo esc_attr( $acc_address ); ?>"
										data-property-type="<?php echo esc_attr( $acc_type ); ?>"
										data-bedrooms="<?php echo esc_attr( $acc_bedrooms ); ?>"
										data-bathrooms="<?php echo esc_attr( $acc_bathrooms ); ?>"
										data-parking="<?php echo esc_attr( $acc_parking ); ?>"
										data-square-meters="<?php echo esc_attr( $acc_sqm ); ?>"
										data-furnished="<?php echo esc_attr( $acc_furnished ); ?>"
										data-condition="<?php echo esc_attr( $acc_condition ); ?>"
										data-owner-name="<?php echo esc_attr( (string) $acc_owner['name'] ); ?>"
										data-owner-id="<?php echo esc_attr( (string) $acc_owner['id_number'] ); ?>"
										data-reference="<?php echo esc_attr( $lease_accommodation->post_title ); ?>">
										<?php echo esc_html( $lease_accommodation->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<span class="af-form-field__hint">
								<?php esc_html_e( 'Si el inquilino ya tiene un inmueble vinculado se selecciona solo.', 'arriendo-facil' ); ?>
							</span>
						</div>
					</div>

					<p class="af-cwiz__link" id="af-wizard-link" hidden>
						<strong id="af-wizard-link-title"></strong>
						<span id="af-wizard-link-text"></span>
					</p>
				</div>

				<?php $af_render_section( 'inquilino' ); ?>
			</section>

			<!-- ── Paso 2: Inmueble ─────────────────────────────────────────── -->
			<section class="af-cwiz__panel" data-step="2" hidden>
				<?php $af_render_section( 'inmueble' ); ?>
			</section>

			<!-- ── Paso 3: Vigencia y condiciones ────────────────────────────── -->
			<section class="af-cwiz__panel" data-step="3" hidden>
				<div class="af-cwiz__group">
					<h3 class="af-cwiz__group-title"><?php esc_html_e( 'Vigencia', 'arriendo-facil' ); ?></h3>
					<div class="af-form-grid">
						<div class="af-form-field">
							<label class="af-form-field__label" for="af-lease-start"><?php esc_html_e( 'Inicio', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
							<input type="date" name="start_date" id="af-lease-start" required />
						</div>
						<div class="af-form-field">
							<label class="af-form-field__label" for="af-lease-end"><?php esc_html_e( 'Fin', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
							<input type="date" name="end_date" id="af-lease-end" required />
						</div>
						<div class="af-form-field">
							<label class="af-form-field__label" for="af-lease-rent"><?php esc_html_e( 'Canon Mensual (USD)', 'arriendo-facil' ); ?> <span class="af-required">*</span></label>
							<input type="number" name="monthly_rent" id="af-lease-rent" step="0.01" min="0" required placeholder="0.00" />
						</div>
						<div class="af-form-field">
							<label class="af-form-field__label" for="af-lease-deposit"><?php esc_html_e( 'Garantía (USD)', 'arriendo-facil' ); ?></label>
							<input type="number" name="deposit_amount" id="af-lease-deposit" step="0.01" min="0" placeholder="0.00" />
						</div>
						<div class="af-form-field">
							<label class="af-form-field__label" for="af-lease-due-day"><?php esc_html_e( 'Día Límite de Pago', 'arriendo-facil' ); ?></label>
							<select name="payment_due_day" id="af-lease-due-day">
								<option value=""><?php esc_html_e( 'Día 5 (predeterminado)', 'arriendo-facil' ); ?></option>
								<?php for ( $day = 1; $day <= 28; $day++ ) : ?>
									<option value="<?php echo esc_attr( $day ); ?>"><?php echo esc_html( sprintf( __( 'Día %d', 'arriendo-facil' ), $day ) ); ?></option>
								<?php endfor; ?>
							</select>
						</div>
					</div>
				</div>
			</section>

			<!-- ── Paso 4: Datos del documento ───────────────────────────────── -->
			<section class="af-cwiz__panel" data-step="4" hidden>
				<?php if ( ! empty( $af_step_groups[4] ) ) : ?>
					<div class="af-cwiz__autofill" id="af-wizard-autofill" hidden>
						<span><strong id="af-wizard-autofill-count">0</strong> <?php esc_html_e( 'campos completados automáticamente desde el inmueble, el inquilino y las fechas del contrato.', 'arriendo-facil' ); ?></span>
						<button type="button" id="af-wizard-autofill-clear"><?php esc_html_e( 'Limpiar autocompletado', 'arriendo-facil' ); ?></button>
					</div>

					<?php foreach ( $af_step_groups[4] as $af_section_id ) : ?>
						<?php $af_render_section( $af_section_id ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</section>

			<!-- ── Paso 5: Datos Bancarios (opcional) ───────────────────────── -->
			<section class="af-cwiz__panel" data-step="5" hidden>
				<?php if ( ! empty( $af_step_groups[5] ) ) : ?>
					<p class="af-cwiz__opt-hint"><?php esc_html_e( 'Datos de la cuenta para pagos por transferencia. Todos los campos son opcionales.', 'arriendo-facil' ); ?></p>
					<?php foreach ( $af_step_groups[5] as $af_section_id ) : ?>
						<?php $af_render_section( $af_section_id ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</section>

			<!-- ── Paso 6: Revisar ──────────────────────────────────────────── -->
			<section class="af-cwiz__panel" data-step="6" hidden>
				<div class="af-cwiz__group">
					<h3 class="af-cwiz__group-title"><?php esc_html_e( 'Resumen del contrato', 'arriendo-facil' ); ?></h3>
					<dl class="af-cwiz__review" id="af-wizard-review"></dl>
				</div>
				<div class="af-info-banner">
					<span class="af-info-banner__icon" aria-hidden="true"><?php echo af_lucide( 'sparkles', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG in af-view-helpers.php ?></span>
					<div class="af-info-banner__body">
						<p class="af-info-banner__title"><?php esc_html_e( 'Documento listo para generar', 'arriendo-facil' ); ?></p>
						<?php esc_html_e( 'Al crear el contrato se generará el DOCX con los datos de arriba y quedará en estado Borrador para que lo revises y actives.', 'arriendo-facil' ); ?>
					</div>
				</div>
			</section>

			<!-- Navegación -->
			<div class="af-cwiz__nav">
				<button type="button" class="button" id="af-wizard-prev" hidden><?php esc_html_e( 'Atrás', 'arriendo-facil' ); ?></button>
				<button type="button" class="button button-primary" id="af-wizard-next"><?php esc_html_e( 'Siguiente', 'arriendo-facil' ); ?></button>
				<span class="af-cwiz__spacer"></span>
				<button type="submit" class="button button-primary" id="af-wizard-submit" hidden><?php esc_html_e( 'Crear Contrato', 'arriendo-facil' ); ?></button>
				<button type="button" class="button" id="af-lease-cancel"><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			</div>
		</form>

	</div>

	<script>
	(function () {
		const card = document.getElementById('af-lease-form-card');
		const form = document.getElementById('af-lease-form');
		const status = document.getElementById('af-lease-status');
		const openBtn = document.getElementById('af-new-lease');
		const openBtnFromSteps = document.getElementById('af-new-lease-from-steps');
		const cancelBtn = document.getElementById('af-lease-cancel');
		const closeBtn = document.getElementById('af-lease-form-close');
		if (!card || !form || (!openBtn && !openBtnFromSteps)) { return; }

		const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_lease_nonce' ) ); ?>;

		/* ── Helper: resolve a dotted source path out of the form state ────── */
		const guestSelect = form.querySelector('select[name="guest_id"]');

		function selectedOption(select) {
			if (!select) { return null; }
			return select.options[select.selectedIndex] || null;
		}

		function monthsBetween(startIso, endIso) {
			const a = parseIso(startIso);
			const b = parseIso(endIso);
			if (!a || !b) { return 0; }
			let months = (b.getUTCFullYear() - a.getUTCFullYear()) * 12 + (b.getUTCMonth() - a.getUTCMonth());
			if (b.getUTCDate() < a.getUTCDate()) { months -= 1; }
			return Math.max(0, months);
		}

		function parseIso(iso) {
			const parts = String(iso || '').split('-');
			if (parts.length !== 3) { return null; }
			const d = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));
			return isNaN(d.getTime()) ? null : d;
		}

		function addMonths(isoDate, months) {
			const d = parseIso(isoDate);
			if (!d) { return ''; }
			d.setUTCMonth(d.getUTCMonth() + months);
			return d.toISOString().slice(0, 10);
		}

		function addDays(isoDate, days) {
			const d = parseIso(isoDate);
			if (!d) { return ''; }
			d.setUTCDate(d.getUTCDate() + days);
			return d.toISOString().slice(0, 10);
		}

		function numberToWords(n) {
			n = Math.floor(Math.abs(Number(n) || 0));
			if (n === 0) { return 'cero'; }
			const ones = ['','uno','dos','tres','cuatro','cinco','seis','siete','ocho','nueve','diez','once','doce','trece','catorce','quince','dieciséis','diecisiete','dieciocho','diecinueve','veinte','veintiuno','veintidós','veintitrés','veinticuatro','veinticinco','veintiséis','veintisiete','veintiocho','veintinueve'];
			const tens = ['','','','treinta','cuarenta','cincuenta','sesenta','setenta','ochenta','noventa'];
			const hundreds = ['','ciento','doscientos','trescientos','cuatrocientos','quinientos','seiscientos','setecientos','ochocientos','novecientos'];

			function under1000(x) {
				if (x < 30) { return ones[x]; }
				if (x < 100) {
					const t = tens[Math.floor(x / 10)];
					const r = x % 10;
					return r ? t + ' y ' + ones[r] : t;
				}
				if (x === 100) { return 'cien'; }
				const h = hundreds[Math.floor(x / 100)];
				const r = x % 100;
				return r ? h + ' ' + under1000(r) : h;
			}

			const groups = [];
			let rest = n;
			while (rest > 0 && groups.length < 4) {
				groups.push(rest % 1000);
				rest = Math.floor(rest / 1000);
			}

			const scales = ['', ' mil', ' millón', ' mil millones'];
			const parts = [];
			for (let i = groups.length - 1; i >= 0; i--) {
				const g = groups[i];
				if (!g) { continue; }
				if (i === 1 && g === 1) { parts.push('mil'); continue; }
				if (i === 2 && g === 1) { parts.push('un millón'); continue; }
				parts.push(under1000(g) + scales[i]);
			}
			return parts.join(' ').trim();
		}

		function resolveSource(path) {
			const accOpt = selectedOption(form.accommodation_id);
			const gOpt = selectedOption(guestSelect);
			const today = new Date();
			const start = form.start_date.value;
			const end = form.end_date.value;
			const rent = form.monthly_rent.value;
			const deposit = form.deposit_amount.value;

			switch (path) {
				case 'contract.signed_year': return String(today.getFullYear());
				case 'contract.signed_month': return String(today.getMonth() + 1);
				case 'contract.signed_day': return String(today.getDate());
				case 'lease.start_date': return start;
				case 'lease.end_date': return end;
				case 'lease.monthly_rent': return rent ? parseFloat(rent).toFixed(2) : '';
				case 'lease.deposit_amount': return deposit ? parseFloat(deposit).toFixed(2) : '';
				case 'lease.duration': {
					const m = monthsBetween(start, end);
					return m ? (m === 1 ? '1 mes' : m + ' meses') : '';
				}
				case 'lease.deposit_due_1': return start;
				case 'lease.deposit_due_2': return addDays(start, 15);
				case 'guest.display_name': return gOpt ? (gOpt.getAttribute('data-guest-name') || '') : '';
				case 'guest.document_id': return gOpt ? (gOpt.getAttribute('data-document-id') || '') : '';
				case 'guest.nationality': return nationality(gOpt);
				case 'accommodation.address': return accOpt ? (accOpt.getAttribute('data-address') || '') : '';
				case 'accommodation.property_type': return propertyType(accOpt);
				case 'accommodation.bedrooms': return accOpt ? (accOpt.getAttribute('data-bedrooms') || '') : '';
				case 'accommodation.bathrooms': return accOpt ? (accOpt.getAttribute('data-bathrooms') || '') : '';
				case 'accommodation.parking': return accOpt ? (accOpt.getAttribute('data-parking') || '') : '';
				case 'accommodation.square_meters': return accOpt ? (accOpt.getAttribute('data-square-meters') || '') : '';
				case 'accommodation.reference': return accOpt ? (accOpt.getAttribute('data-reference') || '') : '';
				case 'accommodation.delivery_state': return deliveryState(accOpt);
				case 'accommodation.owner_name':
				case 'owner.name': return accOpt ? (accOpt.getAttribute('data-owner-name') || '') : '';
				case 'accommodation.owner_id': return accOpt ? (accOpt.getAttribute('data-owner-id') || '') : '';
				default: return '';
			}
		}

		/* Tipo de inmueble: el catálogo guarda slugs en inglés (apartment, house…)
			y la plantilla espera slugs en español. Se traduce aquí; si el valor
			ya fuera una de las opciones del select, pasa tal cual. */
		function propertyType(accOpt) {
			if (!accOpt) { return ''; }
			const raw = String(accOpt.getAttribute('data-property-type') || '').toLowerCase().trim();
			if (!raw) { return ''; }
			const map = {
				apartment: 'apartamento',
				house: 'casa',
				office: 'oficina',
				commercial: 'local',
				room: 'habitacion',
				apartamento: 'apartamento',
				casa: 'casa',
				oficina: 'oficina',
				local: 'local',
				habitacion: 'habitacion',
			};
			return map[raw] || raw;
		}

		/* Nacionalidad del inquilino: el registro guarda el país o el gentilicio
			en cualquier género; la plantilla espera el gentilicio femenino
			(Ecuatoriana, Alemana…). Se normaliza a la opción del select. */
		function nationality(gOpt) {
			if (!gOpt) { return ''; }
			const raw = String(gOpt.getAttribute('data-nationality') || '').trim();
			if (!raw) { return ''; }
			const norm = raw.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
			const map = {
				ecuador: 'Ecuatoriana', ecuatoriana: 'Ecuatoriana', ecuatoriano: 'Ecuatoriana',
				colombia: 'Colombiana', colombiana: 'Colombiana', colombiano: 'Colombiana',
				venezuela: 'Venezolana', venezolana: 'Venezolana', venezolano: 'Venezolana',
				peru: 'Peruana', peruana: 'Peruana', peruano: 'Peruana',
				chile: 'Chilena', chilena: 'Chilena', chileno: 'Chilena',
				argentina: 'Argentina', argentino: 'Argentina',
				bolivia: 'Boliviana', boliviana: 'Boliviana', boliviano: 'Boliviana',
				paraguay: 'Paraguaya', paraguaya: 'Paraguaya', paraguayo: 'Paraguaya',
				uruguay: 'Uruguaya', uruguaya: 'Uruguaya', uruguayo: 'Uruguaya',
				brasil: 'Brasileña', brasileno: 'Brasileña', brasilena: 'Brasileña',
				espana: 'Española', espanol: 'Española', espanola: 'Española',
				francia: 'Francesa', frances: 'Francesa', francesa: 'Francesa',
				alemania: 'Alemana', aleman: 'Alemana', alemana: 'Alemana',
				italia: 'Italiana', italiano: 'Italiana', italiana: 'Italiana',
				portugal: 'Portuguesa', portugues: 'Portuguesa', portuguesa: 'Portuguesa',
				'reino unido': 'Británica', inglaterra: 'Británica', britanico: 'Británica', britanica: 'Británica', britano: 'Británica', britana: 'Británica',
				irlanda: 'Irlandesa', irlandes: 'Irlandesa', irlandesa: 'Irlandesa',
				'estados unidos': 'Estadounidense', usa: 'Estadounidense', eeuu: 'Estadounidense', estadounidense: 'Estadounidense', americano: 'Estadounidense', americana: 'Estadounidense',
				canada: 'Canadiense', canadiense: 'Canadiense',
				mexico: 'Mexicana', mexicana: 'Mexicana', mexicano: 'Mexicana',
				cuba: 'Cubana', cubana: 'Cubana', cubano: 'Cubana',
				'republica dominicana': 'Dominicana', dominicana: 'Dominicana', dominicano: 'Dominicana',
				'puerto rico': 'Puertorriqueña', puertorriquena: 'Puertorriqueña', puertorriqueno: 'Puertorriqueña',
				guatemala: 'Guatemalteca', guatemalteca: 'Guatemalteca', guatemalteco: 'Guatemalteca',
				'el salvador': 'Salvadoreña', salvadorena: 'Salvadoreña', salvadoreno: 'Salvadoreña',
				honduras: 'Hondureña', hondurena: 'Hondureña', hondureno: 'Hondureña',
				nicaragua: 'Nicaragüense', nicaraguense: 'Nicaragüense', nicarageno: 'Nicaragüense',
				'costa rica': 'Costarricense', costarricense: 'Costarricense',
				panama: 'Panameña', panamena: 'Panameña', panameno: 'Panameña',
				belice: 'Beliceña', belicena: 'Beliceña', beliceno: 'Beliceña',
				china: 'China', chino: 'China',
				japon: 'Japonesa', japonesa: 'Japonesa', japones: 'Japonesa',
				corea: 'Coreana', coreana: 'Coreana', coreano: 'Coreana',
				india: 'India',
				filipinas: 'Filipina', filipina: 'Filipina', filipino: 'Filipina',
				tailandia: 'Tailandesa', tailandesa: 'Tailandesa', tailandes: 'Tailandesa',
				vietnam: 'Vietnamita', vietnamita: 'Vietnamita',
				indonesia: 'Indonesa', indonesa: 'Indonesa', indones: 'Indonesa',
				marruecos: 'Marroquí', marroqui: 'Marroquí',
				egipto: 'Egipcia', egipcia: 'Egipcia', egipcio: 'Egipcia',
				nigeria: 'Nigeriana', nigeriana: 'Nigeriana', nigeriano: 'Nigeriana',
				sudafrica: 'Sudafricana', sudafricana: 'Sudafricana', sudafricano: 'Sudafricana',
				rusia: 'Rusa', rusa: 'Rusa', ruso: 'Rusa',
				ucrania: 'Ucraniana', ucraniana: 'Ucraniana', ucraniano: 'Ucraniana',
				polonia: 'Polaca', polaca: 'Polaca', polaco: 'Polaca',
				rumania: 'Rumana', rumana: 'Rumana', rumano: 'Rumana',
				grecia: 'Griega', griega: 'Griega', griego: 'Griega',
				turquia: 'Turca', turca: 'Turca', turco: 'Turca',
				holanda: 'Neerlandesa', 'paises bajos': 'Neerlandesa', neerlandesa: 'Neerlandesa', neerlandes: 'Neerlandesa',
				belgica: 'Belga', belga: 'Belga',
				suecia: 'Sueca', sueca: 'Sueca', sueco: 'Sueca',
				noruega: 'Noruega',
				finlandia: 'Finlandesa', finlandesa: 'Finlandesa', finlandes: 'Finlandesa',
				suiza: 'Suiza', suizo: 'Suiza',
				australia: 'Australiana', australiana: 'Australiana', australiano: 'Australiana',
				'nueva zelanda': 'Neozelandesa', neozelandesa: 'Neozelandesa', neozelandes: 'Neozelandesa',
			};
			return map[norm] || raw;
		}

		/* Estado del inmueble derivado del mobiliario y la conservación. */
		function deliveryState(accOpt) {
			if (!accOpt) { return ''; }
			const cond = accOpt.getAttribute('data-condition') || '';
			const furn = accOpt.getAttribute('data-furnished') || '';
			if (!cond && !furn) { return ''; }
			if (cond === 'needs_repair') { return 'reparacion'; }
			if (furn === 'furnished') { return 'amueblado'; }
			if (furn === 'semi') { return 'semi_amoblado'; }
			if (furn === 'unfurnished') { return 'sin_amoblar'; }
			if (cond === 'new') { return 'nuevo'; }
			return 'buen_estado';
		}

		/* ── Wizard state ───────────────────────────────────────────────────── */
		const stepPanels = Array.prototype.slice.call(form.querySelectorAll('.af-cwiz__panel'));
		const stepItems = Array.prototype.slice.call(document.querySelectorAll('#af-wizard-steps > li'));
		const prevBtn = document.getElementById('af-wizard-prev');
		const nextBtn = document.getElementById('af-wizard-next');
		const submitBtn = document.getElementById('af-wizard-submit');
		const errorBox = document.getElementById('af-wizard-error');
		const reviewList = document.getElementById('af-wizard-review');
		const autofillBox = document.getElementById('af-wizard-autofill');
		const autofillCount = document.getElementById('af-wizard-autofill-count');
		const autofillClear = document.getElementById('af-wizard-autofill-clear');
		const progressBox = document.getElementById('af-wizard-progress');
		const progressFill = document.getElementById('af-wizard-progress-fill');
		const progressLabel = document.getElementById('af-wizard-progress-label');
		const progressPct = document.getElementById('af-wizard-progress-pct');
		const totalSteps = stepPanels.length;
		let currentStep = 1;
		let autoValues = {};

		function showError(msg) {
			if (!errorBox) { return; }
			errorBox.textContent = msg || '';
			errorBox.classList.toggle('is-visible', !!msg);
		}

		/* Barra de progreso: ancho, porcentaje y texto "Paso X de Y — nombre". */
		function updateProgress(step) {
			if (!progressBox || !progressFill) { return; }
			const pct = Math.round((step / totalSteps) * 100);
			progressFill.style.width = pct + '%';
			const btn = stepItems[step - 1] && stepItems[step - 1].querySelector('.af-cwiz__step');
			const name = btn ? (btn.getAttribute('data-label') || '').trim() : '';
			const head = <?php echo wp_json_encode( __( 'Paso', 'arriendo-facil' ) ); ?> + ' ' + step + ' ' +
				<?php echo wp_json_encode( __( 'de', 'arriendo-facil' ) ); ?> + ' ' + totalSteps +
				(name ? ' — ' + name : '');
			if (progressLabel) { progressLabel.textContent = head; }
			if (progressPct) { progressPct.textContent = pct + '%'; }
			progressBox.setAttribute('aria-valuemax', String(totalSteps));
			progressBox.setAttribute('aria-valuenow', String(step));
			progressBox.setAttribute('aria-valuetext', head + ' (' + pct + '%)');
		}

		function goTo(step) {
			step = Math.min(Math.max(1, step), totalSteps);
			currentStep = step;
			stepPanels.forEach(function (panel) {
				panel.hidden = Number(panel.getAttribute('data-step')) !== step;
			});
			stepItems.forEach(function (li, idx) {
				const n = idx + 1;
				li.classList.toggle('is-current', n === step);
				li.classList.toggle('is-done', n < step);
				const btn = li.querySelector('.af-cwiz__step');
				if (btn) { btn.classList.toggle('is-clickable', n < step); }
			});
			updateProgress(step);
			if (prevBtn) { prevBtn.hidden = step === 1; }
			if (nextBtn) { nextBtn.hidden = step === totalSteps; }
			if (submitBtn) { submitBtn.hidden = step !== totalSteps; }
			showError('');
			if (step === totalSteps) { buildReview(); }
			applyAutofill();
			updateLinkNote();
		}

		function stepFields(step) {
			const panel = stepPanels[step - 1];
			return panel ? Array.prototype.slice.call(panel.querySelectorAll('input, select, textarea')) : [];
		}

		function validateStep(step) {
			const missing = stepFields(step).filter(function (el) {
				if (el.disabled || el.type === 'hidden') { return false; }
				if (!el.required) { return false; }
				return !String(el.value || '').trim();
			});
			if (missing.length) {
				const label = missing[0].id
					? (document.querySelector('label[for="' + missing[0].id + '"]') || {}).textContent
					: '';
				missing[0].focus();
				return (label ? label.replace('*', '').trim() + ' — ' : '') +
					<?php echo wp_json_encode( __( 'Este campo es obligatorio.', 'arriendo-facil' ) ); ?>;
			}
			if (step === 3 && form.end_date.value && form.start_date.value &&
				form.end_date.value <= form.start_date.value) {
				return <?php echo wp_json_encode( __( 'La fecha de fin debe ser posterior al inicio.', 'arriendo-facil' ) ); ?>;
			}
			return '';
		}

		/* ── Autofill engine ───────────────────────────────────────────────── */
		function applyAutofill() {
			autoValues = {};
			Array.prototype.forEach.call(form.querySelectorAll('[data-ph]'), function (field) {
				const source = field.getAttribute('data-source') || '';
				const computed = field.getAttribute('data-computed') || '';
				const def = field.getAttribute('data-default') || '';
				const control = field.querySelector('input, select, textarea');
				if (!control) { return; }

				// `dataset.auto` marks a value the machine still owns. Anything
				// else already in the control was typed by the operator.
				if (control.dataset.auto !== '1' && control.value !== '') { return; }

				let value = '';
				if (computed.indexOf('number_to_words:') === 0) {
					const origin = computed.slice('number_to_words:'.length);
					const raw = fieldOriginValue(origin);
					value = raw === '' ? '' : numberToWords(raw) + ' dólares';
				} else if (source && source !== 'manual') {
					value = resolveSource(source);
				} else if (def) {
					value = def;
				}
				if (!value) { return; }

				if (control.tagName === 'SELECT') {
					const match = Array.prototype.find.call(control.options, function (o) {
						return o.value && (o.value.toLowerCase() === String(value).toLowerCase());
					});
					if (!match) { return; }
					control.value = match.value;
				} else {
					control.value = value;
				}

				control.dataset.auto = '1';
				control.dataset.original = value;
				const badge = field.querySelector('.af-cwiz__badge');
				if (badge) { badge.hidden = false; }
				autoValues[field.getAttribute('data-ph')] = value;
			});

			const count = Object.keys(autoValues).length;
			if (autofillBox) {
				autofillBox.hidden = count === 0;
				autofillCount.textContent = String(count);
			}
		}

		function fieldOriginValue(key) {
			switch (key) {
				case 'canon_mensual': return form.monthly_rent.value ? parseFloat(form.monthly_rent.value) : '';
				case 'monto_numero': return form.deposit_amount.value ? parseFloat(form.deposit_amount.value) : '';
				default: return '';
			}
		}

		function clearAutofill() {
			Array.prototype.forEach.call(form.querySelectorAll('[data-ph]'), function (field) {
				const control = field.querySelector('input, select, textarea');
				if (!control || control.dataset.auto !== '1') { return; }
				control.value = '';
				delete control.dataset.auto;
				delete control.dataset.original;
				const badge = field.querySelector('.af-cwiz__badge');
				if (badge) { badge.hidden = true; }
			});
			autoValues = {};
			if (autofillBox) { autofillBox.hidden = true; }
		}

		/* ── Vínculo inquilino ↔ inmueble ──────────────────────────────────── */
		const linkBox = document.getElementById('af-wizard-link');
		const linkTitle = document.getElementById('af-wizard-link-title');
		const linkText = document.getElementById('af-wizard-link-text');

		function updateLinkNote() {
			if (!linkBox || !linkTitle || !linkText) { return; }
			const gOpt = selectedOption(guestSelect);
			const accOpt = selectedOption(form.accommodation_id);
			const linkedId = gOpt ? (gOpt.getAttribute('data-accommodation') || '') : '';
			const isLinked = !!gOpt && !!gOpt.value && linkedId && linkedId !== '0';

			if (!gOpt || !gOpt.value) {
				linkBox.hidden = true;
				linkBox.classList.remove('is-linked', 'is-unlinked');
				return;
			}

			linkBox.hidden = false;

			if (isLinked) {
				linkBox.classList.add('is-linked');
				linkBox.classList.remove('is-unlinked');
				linkTitle.textContent = <?php echo wp_json_encode( __( 'Inquilino con inmueble vinculado.', 'arriendo-facil' ) ); ?>;
				if (accOpt && accOpt.value && accOpt.value === linkedId) {
					linkText.textContent = ' ' + <?php echo wp_json_encode( __( 'Se completaron sus datos y los del inmueble automáticamente.', 'arriendo-facil' ) ); ?>;
				} else if (accOpt && accOpt.value) {
					linkText.textContent = ' ' + <?php echo wp_json_encode( __( 'Se usará el inmueble seleccionado y se actualizará el vínculo del inquilino.', 'arriendo-facil' ) ); ?>;
				} else {
					linkText.textContent = ' ' + <?php echo wp_json_encode( __( 'Elige el inmueble que se arrendará.', 'arriendo-facil' ) ); ?>;
				}
			} else {
				linkBox.classList.add('is-unlinked');
				linkBox.classList.remove('is-linked');
				linkTitle.textContent = <?php echo wp_json_encode( __( 'Este inquilino aún no tiene inmueble.', 'arriendo-facil' ) ); ?>;
				linkText.textContent = ' ' + <?php echo wp_json_encode( __( 'Al crear el contrato quedará vinculado al inmueble seleccionado.', 'arriendo-facil' ) ); ?>;
			}
		}

		// Manual edits always win over the next autofill pass.
		form.addEventListener('input', function (e) {
			const el = e.target;
			if (!el.dataset) { return; }
			if (el.dataset.auto === '1' && el.dataset.original !== el.value) {
				delete el.dataset.auto;
				const holder = el.closest('[data-ph]');
				const badge = holder ? holder.querySelector('.af-cwiz__badge') : null;
				if (badge) { badge.hidden = true; }
			}
		});

		if (autofillClear) {
			autofillClear.addEventListener('click', clearAutofill);
		}

		/* ── Review step ────────────────────────────────────────────────────── */
		function buildReview() {
			if (!reviewList) { return; }
			const accOpt = selectedOption(form.accommodation_id);
			const gOpt = selectedOption(guestSelect);
			const rows = [
				[<?php echo wp_json_encode( __( 'Inmueble', 'arriendo-facil' ) ); ?>, accOpt ? accOpt.textContent.trim() : ''],
				[<?php echo wp_json_encode( __( 'Inquilino', 'arriendo-facil' ) ); ?>, gOpt ? gOpt.textContent.trim() : ''],
				[<?php echo wp_json_encode( __( 'Inicio', 'arriendo-facil' ) ); ?>, form.start_date.value],
				[<?php echo wp_json_encode( __( 'Fin', 'arriendo-facil' ) ); ?>, form.end_date.value],
				[<?php echo wp_json_encode( __( 'Plazo', 'arriendo-facil' ) ); ?>, (function () {
					const m = monthsBetween(form.start_date.value, form.end_date.value);
					return m ? (m === 1 ? '1 mes' : m + ' meses') : '';
				})()],
				[<?php echo wp_json_encode( __( 'Canon mensual (USD)', 'arriendo-facil' ) ); ?>, form.monthly_rent.value ? parseFloat(form.monthly_rent.value).toFixed(2) : ''],
				[<?php echo wp_json_encode( __( 'Garantía (USD)', 'arriendo-facil' ) ); ?>, form.deposit_amount.value ? parseFloat(form.deposit_amount.value).toFixed(2) : ''],
				[<?php echo wp_json_encode( __( 'Día de pago', 'arriendo-facil' ) ); ?>, form.payment_due_day.value || <?php echo wp_json_encode( __( 'Día 5 (predeterminado)', 'arriendo-facil' ) ); ?>]
			];

			reviewList.innerHTML = '';
			rows.forEach(function (row) {
				if (!row[1]) { return; }
				const wrap = document.createElement('div');
				wrap.className = 'af-cwiz__review-item';
				const dt = document.createElement('dt');
				dt.className = 'af-cwiz__review-label';
				dt.textContent = row[0];
				const dd = document.createElement('dd');
				dd.className = 'af-cwiz__review-value';
				dd.style.margin = '0';
				dd.textContent = row[1];
				wrap.appendChild(dt);
				wrap.appendChild(dd);
				reviewList.appendChild(wrap);
			});
		}

		/* ── Navigation wiring ─────────────────────────────────────────────── */
		if (nextBtn) {
			nextBtn.addEventListener('click', function () {
				const err = validateStep(currentStep);
				if (err) { showError(err); return; }
				goTo(currentStep + 1);
			});
		}
		if (prevBtn) {
			prevBtn.addEventListener('click', function () { goTo(currentStep - 1); });
		}
		stepItems.forEach(function (li) {
			const btn = li.querySelector('.af-cwiz__step');
			if (!btn) { return; }
			btn.addEventListener('click', function () {
				const target = Number(btn.getAttribute('data-goto'));
				if (!target || target >= currentStep) { return; }
				goTo(target);
			});
		});

		/* ── Open / close ──────────────────────────────────────────────────── */
		function openForm() {
			card.style.display = 'block';
			goTo(1);
			card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			form.querySelector('select[name="guest_id"]').focus();
		}

		function closeForm() {
			card.style.display = 'none';
			form.reset();
			clearAutofill();
			status.style.display = 'none';
			status.textContent = '';
			goTo(1);
		}

		if (openBtn) { openBtn.addEventListener('click', openForm); }
		if (openBtnFromSteps) { openBtnFromSteps.addEventListener('click', openForm); }
		if (cancelBtn) { cancelBtn.addEventListener('click', closeForm); }
		if (closeBtn) { closeBtn.addEventListener('click', closeForm); }

		/* ── Prefill agreement from what the operator already captured ─────── */
		guestSelect.addEventListener('change', function () {
			const opt = selectedOption(this);
			if (!opt || !opt.value) { return; }

			const start = opt.getAttribute('data-start') || '';
			const end = opt.getAttribute('data-end') || '';

			if (start && !form.start_date.value) { form.start_date.value = start; }
			if (end && !form.end_date.value) { form.end_date.value = end; }
			if (!form.end_date.value && form.start_date.value) {
				form.end_date.value = addMonths(form.start_date.value, 12);
			}

			const rent = parseFloat(form.monthly_rent.value || '0');
			if (!form.deposit_amount.value && rent > 0) {
				form.deposit_amount.value = (rent * 2).toFixed(2);
			}

			if (form.start_date.value && form.payment_due_day) {
				const day = Number(String(form.start_date.value).split('-')[2]);
				if (day >= 1) { form.payment_due_day.value = String(Math.min(28, day)); }
			}

			const linkedId = opt.getAttribute('data-accommodation');
			if (linkedId && linkedId !== '0' && form.accommodation_id.value !== linkedId) {
				const exists = Array.prototype.some.call(form.accommodation_id.options, function (o) { return o.value === linkedId; });
				if (exists) {
					form.accommodation_id.value = linkedId;
					form.accommodation_id.dispatchEvent(new Event('change'));
				}
			}
			applyAutofill();
			updateLinkNote();
		});

		form.accommodation_id.addEventListener('change', function () {
			const opt = selectedOption(this);
			const rent = opt ? opt.getAttribute('data-rent') : '';
			if (rent && !form.monthly_rent.value) {
				const parsed = parseFloat(rent);
				if (!isNaN(parsed)) { form.monthly_rent.value = parsed.toFixed(2); }
			}
			if (rent && !form.deposit_amount.value) {
				const parsed = parseFloat(rent);
				if (parsed > 0) { form.deposit_amount.value = (parsed * 2).toFixed(2); }
			}
			applyAutofill();
			updateLinkNote();
		});

		// Keep the derived placeholders in sync while the operator types.
		['input', 'change'].forEach(function (evt) {
			form.addEventListener(evt, function (e) {
				if (!e.target.name) { return; }
				if (['monthly_rent', 'deposit_amount', 'start_date', 'end_date'].indexOf(e.target.name) !== -1) {
					applyAutofill();
				}
			});
		});

		goTo(1);

		/* ── Submit ─────────────────────────────────────────────────────────── */
		form.addEventListener('submit', function (e) {
			e.preventDefault();

			for (let step = 1; step <= totalSteps - 1; step++) {
				const err = validateStep(step);
				if (err) {
					goTo(step);
					showError(err);
					return;
				}
			}

			const btn = submitBtn;
			btn.disabled = true;
			status.textContent = '';
			status.style.display = 'none';
			status.className = 'af-modal__status';

			const body = new URLSearchParams(new FormData(form));
			body.append('action', 'af_create_lease');
			body.append('nonce', nonce);

			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body
			}).then((r) => r.json()).then(function (json) {
				btn.disabled = false;
				if (!json || !json.success) {
					status.textContent = (json && json.data && json.data.message) || 'Error';
					status.className = 'af-modal__status is-error';
					status.style.display = 'block';
					return;
				}
				status.textContent = <?php echo wp_json_encode( __( 'Contrato creado correctamente. Redirigiendo...', 'arriendo-facil' ) ); ?>;
				status.className = 'af-modal__status is-success';
				status.style.display = 'block';
				setTimeout(function () { window.location.reload(); }, 1200);
			}).catch(function () {
				btn.disabled = false;
				status.textContent = 'Error de red';
				status.className = 'af-modal__status is-error';
				status.style.display = 'block';
			});
		});
	}());
	</script>

	<section class="af-section" aria-labelledby="af-leases-title">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title" id="af-leases-title"><?php esc_html_e( 'Listado de contratos', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: total leases shown */
							_n( 'Mostrando %d contrato.', 'Mostrando %d contratos.', $total_leases, 'arriendo-facil' ),
							$total_leases
						)
					);
					?>
				</p>
			</div>
		</header>

		<?php
		$lease_tab_counts = array( 'active' => 0, 'por_vencer' => 0, 'draft' => 0, 'terminated' => 0 );
		foreach ( (array) $leases as $lt_lease ) {
			$lt_group = (string) $lt_lease->status;
			if ( 'active' === $lt_group && ! empty( $lt_lease->end_date ) && strtotime( $lt_lease->end_date ) <= strtotime( '+60 days' ) ) {
				$lt_group = 'por_vencer';
			}
			if ( isset( $lease_tab_counts[ $lt_group ] ) ) {
				$lease_tab_counts[ $lt_group ]++;
			}
		}
		?>
		<div class="af-status-tabs" data-tabs-target="#af-leases-tbody">
			<button type="button" class="af-status-tabs__btn is-active" data-tab-value=""><?php esc_html_e( 'Todos', 'arriendo-facil' ); ?> <span class="af-status-tabs__count"><?php echo esc_html( number_format_i18n( $total_leases ) ); ?></span></button>
			<button type="button" class="af-status-tabs__btn" data-tab-value="active"><?php esc_html_e( 'Activos', 'arriendo-facil' ); ?> <span class="af-status-tabs__count"><?php echo esc_html( number_format_i18n( $lease_tab_counts['active'] ) ); ?></span></button>
			<button type="button" class="af-status-tabs__btn" data-tab-value="por_vencer"><?php esc_html_e( 'Por vencer', 'arriendo-facil' ); ?> <span class="af-status-tabs__count"><?php echo esc_html( number_format_i18n( $lease_tab_counts['por_vencer'] ) ); ?></span></button>
			<button type="button" class="af-status-tabs__btn" data-tab-value="draft"><?php esc_html_e( 'Borradores', 'arriendo-facil' ); ?> <span class="af-status-tabs__count"><?php echo esc_html( number_format_i18n( $lease_tab_counts['draft'] ) ); ?></span></button>
			<button type="button" class="af-status-tabs__btn" data-tab-value="terminated"><?php esc_html_e( 'Terminados', 'arriendo-facil' ); ?> <span class="af-status-tabs__count"><?php echo esc_html( number_format_i18n( $lease_tab_counts['terminated'] ) ); ?></span></button>
		</div>

	<div class="af-table-scroll">
	<table class="wp-list-table widefat fixed striped af-leases-table af-leases-table--compact af-data-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Unidad', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Inquilino', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Inicio', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Fin', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Canon mensual', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Día de pago', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Saldo', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Factura', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Documento', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody id="af-leases-tbody">
			<?php if ( $leases ) : ?>
				<?php foreach ( $leases as $lease ) : ?>
					<?php
					$row_tab_group = (string) $lease->status;
					if ( 'active' === $row_tab_group && ! empty( $lease->end_date ) && strtotime( $lease->end_date ) <= strtotime( '+60 days' ) ) {
						$row_tab_group = 'por_vencer';
					}
					?>
					<?php
					$versions_data   = $lease_service ? $lease_service->get_contract_versions( (int) $lease->id ) : array( 'active_version' => 0, 'versions' => array() );
					$active_version  = isset( $versions_data['active_version'] ) ? (int) $versions_data['active_version'] : 0;
					$versions        = isset( $versions_data['versions'] ) && is_array( $versions_data['versions'] ) ? $versions_data['versions'] : array();
					$versions_count  = count( $versions );
					$document_queued = false;
					if ( $lease_service && empty( $lease->document_url ) && 0 === $versions_count ) {
						$document_queued = Arriendo_Facil_Contract_Generator::schedule( (int) $lease->id );
					}
					$next_version    = $versions_count + 1;
					$active_entry    = null;
					if ( $versions_count > 0 ) {
						foreach ( $versions as $version_row ) {
							if ( isset( $version_row['version'] ) && (int) $version_row['version'] === max( 1, $active_version ) ) {
								$active_entry = $version_row;
								break;
							}
						}

						if ( ! is_array( $active_entry ) ) {
							$active_entry = end( $versions );
						}
					}
					$has_approved_pdf = is_array( $active_entry ) && isset( $active_entry['approved_pdf'] ) && is_array( $active_entry['approved_pdf'] ) && ! empty( $active_entry['approved_pdf']['file_name'] );
					$download_active = add_query_arg(
						array(
							'action'   => 'af_download_lease_contract',
							'lease_id' => (int) $lease->id,
							'nonce'    => wp_create_nonce( 'af_lease_nonce' ),
						),
						admin_url( 'admin-ajax.php' )
					);
					?>
					<tr class="af-lease-row" data-tab-group="<?php echo esc_attr( $row_tab_group ); ?>">
						<td data-label="<?php esc_attr_e( 'ID', 'arriendo-facil' ); ?>"><?php echo esc_html( $lease->id ); ?></td>
							<td data-label="<?php esc_attr_e( 'Inmueble', 'arriendo-facil' ); ?>">
								<strong><?php echo esc_html( ( isset( $lease->accommodation_title ) ? $lease->accommodation_title : null ) ?: ( isset( $lease->accommodation_id ) ? get_the_title( (int) $lease->accommodation_id ) : '' ) ?: $lease->accommodation_id ); ?></strong>
								<?php
								if ( class_exists( 'Arriendo_Facil_Accommodation_Occupied_Admin' ) && ! empty( $lease->accommodation_id ) ) {
									echo '<br />' . Arriendo_Facil_Accommodation_Occupied_Admin::render_switch( (int) $lease->accommodation_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup from render_switch().
								}
								?>
							</td>
						<td data-label="<?php esc_attr_e( 'Unidad', 'arriendo-facil' ); ?>">
							<?php echo esc_html( $lease_unit_map[ (int) $lease->accommodation_id ] ?? '—' ); ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Inquilino', 'arriendo-facil' ); ?>"><?php echo esc_html( trim( (string) ( $lease->guest_name ?? '' ) ) ?: '#' . (int) $lease->guest_id ); ?></td>
						<td data-label="<?php esc_attr_e( 'Inicio', 'arriendo-facil' ); ?>"><?php echo esc_html( $lease->start_date ); ?></td>
						<td data-label="<?php esc_attr_e( 'Fin', 'arriendo-facil' ); ?>"><?php echo esc_html( $lease->end_date ); ?></td>
						<td data-label="<?php esc_attr_e( 'Canon mensual', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format( (float) $lease->monthly_rent, 2 ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Día de pago', 'arriendo-facil' ); ?>"><?php echo esc_html( ! empty( $lease->payment_due_day ) ? (int) $lease->payment_due_day : 5 ); ?></td>
						<td data-label="<?php esc_attr_e( 'Saldo', 'arriendo-facil' ); ?>">
							<?php $lease_balance = $lease_balance_map[ (int) $lease->id ] ?? 0.0; ?>
							<span class="af-pill <?php echo $lease_balance > 0 ? 'af-pill--attention' : 'af-pill--success'; ?>">
								$<?php echo esc_html( number_format_i18n( $lease_balance, 2 ) ); ?>
							</span>
						</td>
						<td class="af-lease-status-cell" data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>"><?php echo af_pill( (string) $lease->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>					<?php
					$binfo  = isset( $billing_status_map[ (int) $lease->id ] ) ? $billing_status_map[ (int) $lease->id ] : null;
					$estado_colores = array(
						'autorizada'          => array( 'label' => 'Autorizada', 'color' => '#2e7d32' ),
						'autorizada_sin_ride' => array( 'label' => 'Autorizada', 'color' => '#2e7d32' ),
						'firmada'             => array( 'label' => 'Firmada',    'color' => '#1565c0' ),
						'enviada'             => array( 'label' => 'Enviada',    'color' => '#1565c0' ),
						'generada'            => array( 'label' => 'Generada',   'color' => '#555' ),
						'devuelta'            => array( 'label' => 'Devuelta',   'color' => '#c62828' ),
						'error_envio'         => array( 'label' => 'Error',      'color' => '#c62828' ),
						'error_autorizacion'  => array( 'label' => 'Error',      'color' => '#c62828' ),
						'no_autorizada'       => array( 'label' => 'No Aut.',    'color' => '#c62828' ),
						'rechazada'           => array( 'label' => 'Rechazada',  'color' => '#c62828' ),
						'anulada'             => array( 'label' => 'Anulada',    'color' => '#e65100' ),
					);
					?>
					<td class="af-lease-billing-cell" id="af-billing-cell-<?php echo esc_attr( $lease->id ); ?>" data-label="<?php esc_attr_e( 'Factura', 'arriendo-facil' ); ?>">
						<?php
						$is_current_period = $binfo && empty( $binfo['is_previous'] );
						$is_prev_period    = $binfo && ! empty( $binfo['is_previous'] );
						?>

						<?php if ( $binfo ) : ?>
							<?php
							$ei      = isset( $estado_colores[ $binfo['estado'] ] ) ? $estado_colores[ $binfo['estado'] ] : array( 'label' => esc_html( $binfo['estado'] ), 'color' => '#555' );
							$period_label = isset( $binfo['period'] ) && $binfo['period'] ? $binfo['period'] : '';
							?>
							<span style="font-weight:600; color:<?php echo esc_attr( $ei['color'] ); ?>; font-size:12px;">
								<?php echo esc_html( $ei['label'] ); ?>
							</span>
							<?php if ( $period_label ) : ?>
								<span style="color:#888; font-size:11px;"> (<?php echo esc_html( $period_label ); ?>)</span>
							<?php endif; ?><br>
							<small style="color:#888;">#<?php echo esc_html( $binfo['invoice_id'] ); ?></small>
						<?php endif; ?>

						<?php if ( ( ! $binfo || $is_prev_period ) && $can_bill && 'active' === (string) $lease->status ) : ?>
							<br>
							<button type="button"
								class="button button-small af-lease-issue-invoice"
								data-lease-id="<?php echo esc_attr( $lease->id ); ?>"
								data-nonce="<?php echo esc_attr( $billing_nonce ); ?>">
								<?php echo esc_html( sprintf( __( 'Emitir %s', 'arriendo-facil' ), $billing_current_month ) ); ?>
							</button>
						<?php elseif ( ! $binfo && ! $can_bill ) : ?>
							<span style="color:#aaa; font-size:12px;">&mdash;</span>
						<?php endif; ?>
					</td>					<td class="af-lease-document-cell" data-label="<?php esc_attr_e( 'Documento', 'arriendo-facil' ); ?>">
							<?php if ( $versions_count > 0 || $lease->document_url ) : ?>
								<a class="af-lease-view-link" href="<?php echo esc_url( $download_active ); ?>" target="_blank">
									<?php esc_html_e( 'Ver', 'arriendo-facil' ); ?>
								</a>
								<?php if ( $versions_count > 0 ) : ?>
									<div class="af-lease-version-meta">
										<?php echo esc_html( sprintf( __( 'Version activa: v%d (%d en total)', 'arriendo-facil' ), max( 1, $active_version ), $versions_count ) ); ?>
									</div>
									<?php if ( $has_approved_pdf ) : ?>
										<div class="af-lease-version-meta">
											<?php esc_html_e( 'Seguridad: PDF aprobado activo (solo lectura/impresion).', 'arriendo-facil' ); ?>
										</div>
									<?php endif; ?>
								<?php endif; ?>
							<?php elseif ( $document_queued ) : ?>
								<span class="af-lease-empty-document"><?php esc_html_e( 'Generando documento en segundo plano. Recarga en unos segundos.', 'arriendo-facil' ); ?></span>
							<?php else : ?>
								<span class="af-lease-empty-document"><?php esc_html_e( 'Aun no hay contrato. Sube una version Word desde Acciones.', 'arriendo-facil' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="af-lease-actions-cell af-td-actions" data-label="<?php esc_attr_e( 'Acciones', 'arriendo-facil' ); ?>">
							<details class="af-lease-actions-menu" role="region" aria-label="<?php esc_attr_e( 'Acciones disponibles', 'arriendo-facil' ); ?>">
								<summary role="button" aria-label="<?php esc_attr_e( 'Abrir menu de acciones', 'arriendo-facil' ); ?>">
									<span><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></span>
									<svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true" style="flex-shrink:0;"><path d="M5 7.5 10 12.5 15 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
								</summary>
								<div class="af-lease-actions-dropdown">
									<button type="button" class="button button-secondary af-open-upload-version-modal"
											data-lease-id="<?php echo esc_attr( $lease->id ); ?>"
											data-next-version="<?php echo esc_attr( $next_version ); ?>">
											<?php echo esc_html( sprintf( __( 'Subir v%d', 'arriendo-facil' ), $next_version ) ); ?>
										</button>
										<?php if ( $versions_count > 0 || $lease->document_url ) : ?>
											<button type="button" class="button button-primary af-approve-lease-document"
												data-lease-id="<?php echo esc_attr( $lease->id ); ?>"
												data-active-version="<?php echo esc_attr( max( 1, $active_version ) ); ?>"
												<?php disabled( $has_approved_pdf ); ?>>
												<?php echo esc_html( $has_approved_pdf ? __( 'Documento aprobado', 'arriendo-facil' ) : __( 'Aprobar documento', 'arriendo-facil' ) ); ?>
											</button>
										<?php endif; ?>
										<button type="button" class="button button-primary af-change-lease-status af-lease-activate-button"
											data-lease-id="<?php echo esc_attr( $lease->id ); ?>"
											data-status="active"
											<?php disabled( in_array( (string) $lease->status, array( 'active', 'pending_release' ), true ) ); ?>>
											<?php echo esc_html( in_array( (string) $lease->status, array( 'active', 'pending_release' ), true ) ? __( 'Contrato activo', 'arriendo-facil' ) : __( 'Activar contrato', 'arriendo-facil' ) ); ?>
										</button>
										<?php if ( in_array( (string) $lease->status, array( 'active', 'pending_release' ), true ) ) : ?>
											<button type="button"
												class="button af-btn af-btn--danger"
												data-lease-id="<?php echo esc_attr( $lease->id ); ?>"
												data-nonce="<?php echo esc_attr( wp_create_nonce( 'af_lease_nonce' ) ); ?>"
												id="af-early-terminate-lease-btn-<?php echo esc_attr( $lease->id ); ?>"
												style="background:#b91c1c !important;border-color:#991b1b !important;color:#fff !important;">
												<?php esc_html_e( 'Terminar anticipadamente', 'arriendo-facil' ); ?>
											</button>
										<?php endif; ?>
								</div>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="13"><?php esc_html_e( 'No se encontraron contratos.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
	</div>
	</section>

	<div id="af-early-terminate-modal" class="af-modal" style="display:none;position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center;">
		<div class="af-modal__backdrop" id="af-early-terminate-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.55);"></div>
		<div class="af-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-early-terminate-modal-title"
			style="position:relative;background:#fff;border-radius:10px;max-width:480px;width:92%;padding:28px 28px 22px;box-shadow:0 8px 32px rgba(0,0,0,.22);">
			<h2 id="af-early-terminate-modal-title" style="margin:0 0 6px;font-size:18px;color:#b91c1c;">
				<?php esc_html_e( 'Terminar contrato anticipadamente', 'arriendo-facil' ); ?>
			</h2>
			<p style="margin:0 0 16px;color:#374151;font-size:14px;line-height:1.5;">
				<?php esc_html_e( 'Esta accion terminara el contrato antes de la fecha acordada, liberara el inmueble, cancelara reservas activas y notificara al arrendatario y a los interesados en cola.', 'arriendo-facil' ); ?>
			</p>
			<label for="af-early-terminate-reason" style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;">
				<?php esc_html_e( 'Motivo de terminacion anticipada *', 'arriendo-facil' ); ?>
			</label>
			<textarea id="af-early-terminate-reason" rows="4"
				placeholder="<?php esc_attr_e( 'Ej: Acuerdo mutuo, incumplimiento de contrato, venta del inmueble...', 'arriendo-facil' ); ?>"
				style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;resize:vertical;"></textarea>
			<p id="af-early-terminate-feedback" style="margin:8px 0 0;font-size:13px;color:#b91c1c;" aria-live="polite"></p>
			<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
				<button type="button" id="af-early-terminate-cancel" class="button">
					<?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?>
				</button>
				<button type="button" id="af-early-terminate-confirm" class="button"
					style="background:#b91c1c;border-color:#991b1b;color:#fff;">
					<?php esc_html_e( 'Confirmar terminacion', 'arriendo-facil' ); ?>
				</button>
			</div>
		</div>
	</div>

	<div id="af-lease-upload-modal" class="af-modal af-lease-upload-modal" hidden>
		<div class="af-modal__backdrop" data-af-close-upload-modal></div>
		<div class="af-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-lease-upload-modal-title">
			<div class="af-modal__header">
				<h2 id="af-lease-upload-modal-title"><?php esc_html_e( 'Subir nueva version de contrato', 'arriendo-facil' ); ?></h2>
				<button type="button" class="af-modal__close" data-af-close-upload-modal aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
			</div>
			<div class="af-modal__body">
				<p class="af-lease-upload-help"><?php esc_html_e( 'Sube tu archivo Word editado como la siguiente version de este contrato.', 'arriendo-facil' ); ?></p>
				<p class="af-lease-upload-rules"><?php esc_html_e( 'Permitidos: .doc, .docx | Max: 12 MB', 'arriendo-facil' ); ?></p>

				<div class="af-lease-upload-picker">
					<input type="file" id="af-lease-upload-file" accept=".doc,.docx" hidden />
					<button type="button" class="button" id="af-lease-upload-select-btn"><?php esc_html_e( 'Seleccionar archivo Word', 'arriendo-facil' ); ?></button>
					<span id="af-lease-upload-file-name" class="af-lease-upload-file-name"><?php esc_html_e( 'Ningun archivo seleccionado.', 'arriendo-facil' ); ?></span>
				</div>

				<p id="af-lease-upload-feedback" class="af-lease-upload-feedback" aria-live="polite"></p>

				<div class="af-lease-upload-actions">
					<button type="button" class="button" data-af-close-upload-modal><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button type="button" class="button button-primary" id="af-lease-upload-submit" disabled><?php esc_html_e( 'Subir version', 'arriendo-facil' ); ?></button>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
(function () {
	// Two-step confirmation: first click arms the button, second click fires the request.
	// This prevents accidental double-clicks from generating duplicate invoices.
	var CONFIRM_TIMEOUT = 5000; // ms to auto-disarm if second click doesn't come.
	var armed = {}; // keyed by leaseId

	function disarm( leaseId, btn, origLabel ) {
		clearTimeout( armed[ leaseId ] );
		delete armed[ leaseId ];
		if ( btn ) {
			btn.textContent = origLabel;
			btn.classList.remove( 'af-confirm-armed' );
			btn.disabled = false;
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.af-lease-issue-invoice' );
		if ( ! btn || btn.disabled ) return;

		var leaseId   = btn.dataset.leaseId;
		var nonce     = btn.dataset.nonce;
		var origLabel = btn.dataset.origLabel || btn.textContent.trim();
		btn.dataset.origLabel = origLabel;

		// ---- First click: arm / confirm prompt ----
		if ( ! armed[ leaseId ] ) {
			btn.textContent = '<?php echo esc_js( __( '¿Confirmar? (clic para emitir)', 'arriendo-facil' ) ); ?>';
			btn.classList.add( 'af-confirm-armed' );
			armed[ leaseId ] = setTimeout( function () {
				disarm( leaseId, btn, origLabel );
			}, CONFIRM_TIMEOUT );
			return;
		}

		// ---- Second click: confirmed — fire request ----
		clearTimeout( armed[ leaseId ] );
		delete armed[ leaseId ];

		var cell = document.getElementById( 'af-billing-cell-' + leaseId );

		btn.disabled    = true;
		btn.classList.remove( 'af-confirm-armed' );
		btn.textContent = '<?php echo esc_js( __( 'Emitiendo…', 'arriendo-facil' ) ); ?>';

		var formData = new FormData();
		formData.append( 'action',   'af_issue_invoice' );
		formData.append( 'lease_id', leaseId );
		formData.append( 'nonce',    nonce );

		fetch( '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
			method      : 'POST',
			body        : formData,
			credentials : 'same-origin',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( resp ) {
			if ( resp.success ) {
				var estado = ( resp.data && resp.data.estado ) ? resp.data.estado : 'generada';
				var color  = ( 'autorizada' === estado || 'autorizada_sin_ride' === estado ) ? '#2e7d32'
				           : ( 'error_envio' === estado || 'error_autorizacion' === estado ? '#c62828' : '#1565c0' );
				var invId  = ( resp.data && resp.data.invoice_id ) ? '#' + resp.data.invoice_id : '';
				var period = ( resp.data && resp.data.billing_period ) ? ' (' + resp.data.billing_period + ')' : '';
				cell.innerHTML = '<span style="font-weight:600;color:' + color + ';font-size:12px;">' + estado + period + '</span><br><small style="color:#888;">' + invId + '</small>';
			} else {
				var msg = ( resp.data && resp.data.message ) ? resp.data.message : '<?php echo esc_js( __( 'Error al emitir', 'arriendo-facil' ) ); ?>';
				// Re-show button so admin can retry (server lock released on error).
				btn.disabled    = false;
				btn.textContent = origLabel;
				cell.querySelector( '.af-issue-error' ) && cell.querySelector( '.af-issue-error' ).remove();
				var errSpan = document.createElement( 'span' );
				errSpan.className = 'af-issue-error';
				errSpan.style.cssText = 'display:block;color:#c62828;font-size:11px;margin-top:2px;';
				errSpan.title = msg;
				errSpan.textContent = '⚠ ' + msg.substring( 0, 60 ) + ( msg.length > 60 ? '…' : '' );
				cell.appendChild( errSpan );
			}
		} )
		.catch( function () {
			btn.disabled    = false;
			btn.textContent = origLabel;
			var errSpan = document.createElement( 'span' );
			errSpan.className = 'af-issue-error';
			errSpan.style.cssText = 'display:block;color:#c62828;font-size:11px;margin-top:2px;';
			errSpan.textContent = '⚠ <?php echo esc_js( __( 'Error de red', 'arriendo-facil' ) ); ?>';
			cell.appendChild( errSpan );
		} );
	} );

	// Style for armed state.
	var style = document.createElement( 'style' );
	style.textContent = '.af-confirm-armed{background:#c62828!important;border-color:#b71c1c!important;color:#fff!important;}';
	document.head.appendChild( style );

	// ── Early termination modal ──────────────────────────────────────────────
	(function () {
		var modal      = document.getElementById( 'af-early-terminate-modal' );
		var backdrop   = document.getElementById( 'af-early-terminate-backdrop' );
		var reasonArea = document.getElementById( 'af-early-terminate-reason' );
		var feedback   = document.getElementById( 'af-early-terminate-feedback' );
		var btnCancel  = document.getElementById( 'af-early-terminate-cancel' );
		var btnConfirm = document.getElementById( 'af-early-terminate-confirm' );
		var ajaxUrl    = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

		var activeLease = null; // { leaseId, nonce, row }

		function openModal( leaseId, nonce, row ) {
			activeLease = { leaseId: leaseId, nonce: nonce, row: row };
			reasonArea.value = '';
			feedback.textContent = '';
			btnConfirm.disabled = false;
			btnConfirm.textContent = '<?php echo esc_js( __( 'Confirmar terminacion', 'arriendo-facil' ) ); ?>';
			modal.style.display = 'flex';
			reasonArea.focus();
		}

		function closeModal() {
			modal.style.display = 'none';
			activeLease = null;
		}

		backdrop.addEventListener( 'click', closeModal );
		btnCancel.addEventListener( 'click', closeModal );

		document.addEventListener( 'keydown', function ( e ) {
			if ( ! modal.hidden && 'Escape' === e.key ) {
				closeModal();
			}
		} );

		// Open modal when "Terminar anticipadamente" is clicked.
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.af-early-terminate-lease-btn' );
			if ( ! btn ) return;
			var leaseId = btn.dataset.leaseId;
			var nonce   = btn.dataset.nonce;
			var row     = btn.closest( 'tr' );
			openModal( leaseId, nonce, row );
		} );

		btnConfirm.addEventListener( 'click', function () {
			if ( ! activeLease ) return;

			var reason = reasonArea.value.trim();
			if ( '' === reason ) {
				feedback.textContent = '<?php echo esc_js( __( 'Por favor, ingresa el motivo de terminacion.', 'arriendo-facil' ) ); ?>';
				reasonArea.focus();
				return;
			}

			feedback.textContent = '';
			btnConfirm.disabled = true;
			btnConfirm.textContent = '<?php echo esc_js( __( 'Procesando…', 'arriendo-facil' ) ); ?>';

			var body = new FormData();
			body.append( 'action',   'af_early_terminate_lease' );
			body.append( 'nonce',    activeLease.nonce );
			body.append( 'lease_id', activeLease.leaseId );
			body.append( 'reason',   reason );

			fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						// Update the status cell in the row.
						if ( activeLease.row ) {
							var statusCell = activeLease.row.querySelector( '.af-lease-status-cell' );
							if ( statusCell ) {
								statusCell.innerHTML = '<?php echo esc_js( af_pill( 'terminated' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill is trusted markup. ?>';
							}
							// Hide the terminate button.
							var terminateBtn = activeLease.row.querySelector( '.af-early-terminate-lease-btn' );
							if ( terminateBtn ) {
								terminateBtn.remove();
							}
						}
						closeModal();
						alert( ( res.data && res.data.message ) ? res.data.message : '<?php echo esc_js( __( 'Contrato terminado.', 'arriendo-facil' ) ); ?>' );
					} else {
						var msg = ( res && res.data && res.data.message ) ? res.data.message : '<?php echo esc_js( __( 'No se pudo terminar el contrato.', 'arriendo-facil' ) ); ?>';
						feedback.textContent = msg;
						btnConfirm.disabled = false;
						btnConfirm.textContent = '<?php echo esc_js( __( 'Confirmar terminacion', 'arriendo-facil' ) ); ?>';
					}
				} )
				.catch( function () {
					feedback.textContent = '<?php echo esc_js( __( 'Error de red. Intenta nuevamente.', 'arriendo-facil' ) ); ?>';
					btnConfirm.disabled = false;
					btnConfirm.textContent = '<?php echo esc_js( __( 'Confirmar terminacion', 'arriendo-facil' ) ); ?>';
				} );
		} );
	}());
}());

// ── Action menu controller ────────────────────────────────────────────────
// `position: fixed` resolves against the viewport, so every measurement here
// comes from getBoundingClientRect() WITHOUT adding window.scrollY (that was
// the reason the menu opened hundreds of pixels below its trigger).
(function () {
	const MENUS_SELECTOR = '.af-lease-actions-menu';
	const DROPDOWN_CLASS = 'af-lease-actions-dropdown';
	const VIEWPORT_PAD   = 8;

	let openMenu = null;

	// `af-fade-up` termina en `transform: translateY(0)` con `fill: both`, así
	// que `.af-section` conserva para siempre una matriz identidad. Ese
	// transform convierte la sección en el containing block del `position:
	// fixed`, y las coordenadas de viewport que medimos con
	// getBoundingClientRect() ya no le corresponden: el menú se dibujaba
	// cientos de píxeles más abajo, fuera de pantalla, y "no se abría".
	function containingBlockOrigin(el) {
		let node = el.parentElement;
		while (node && node !== document.documentElement) {
			const cs = window.getComputedStyle(node);
			if (cs.transform !== 'none' || cs.filter !== 'none' ||
				cs.perspective !== 'none' || cs.backdropFilter !== 'none' ||
				(cs.willChange && cs.willChange !== 'auto') ||
				(cs.contain && cs.contain !== 'none')) {
				const r = node.getBoundingClientRect();
				return {
					top: r.top + (parseFloat(cs.borderTopWidth) || 0),
					left: r.left + (parseFloat(cs.borderLeftWidth) || 0),
				};
			}
			node = node.parentElement;
		}
		return { top: 0, left: 0 };
	}

	function place(menu) {
		const summary = menu.querySelector('summary');
		const panel   = menu.querySelector('.' + DROPDOWN_CLASS);
		if (!summary || !panel) { return; }

		const trigger = summary.getBoundingClientRect();
		const height  = panel.offsetHeight  || panel.getBoundingClientRect().height  || 0;
		const width   = panel.offsetWidth   || panel.getBoundingClientRect().width   || 0;
		const vw      = document.documentElement.clientWidth;
		const vh      = window.innerHeight;

		let top  = trigger.bottom + 6;
		let left = trigger.right - width;

		// Flip above when the viewport has no room below.
		if (top + height > vh - VIEWPORT_PAD && trigger.top - height - 6 > VIEWPORT_PAD) {
			top = trigger.top - height - 6;
		}
		if (top + height > vh - VIEWPORT_PAD) {
			top = Math.max(VIEWPORT_PAD, vh - height - VIEWPORT_PAD);
		}

		if (left + width > vw - VIEWPORT_PAD) {
			left = vw - width - VIEWPORT_PAD;
		}
		if (left < VIEWPORT_PAD) {
			left = VIEWPORT_PAD;
		}

		// Rebasa sobre el containing block real (viewport => origen 0,0).
		const origin = containingBlockOrigin(panel);
		panel.style.top  = Math.round(top  - origin.top)  + 'px';
		panel.style.left = Math.round(left - origin.left) + 'px';
	}

	function close(menu) {
		if (!menu || menu.open === false) { return; }
		menu.open = false;
		menu.classList.remove('is-open');
		if (openMenu === menu) { openMenu = null; }
	}

	function open(menu) {
		closeAllOthers(menu);
		menu.classList.add('is-open');
		openMenu = menu;
		// Read layout before positioning so `display:grid` has settled.
		window.requestAnimationFrame(function () { place(menu); });
	}

	function closeAllOthers(except) {
		document.querySelectorAll(MENUS_SELECTOR + '[open]').forEach(function (other) {
			if (other !== except) { close(other); }
		});
	}

	document.addEventListener('toggle', function (e) {
		const menu = e.target;
		if (!(menu instanceof HTMLDetailsElement) || !menu.matches(MENUS_SELECTOR)) { return; }
		if (menu.open) {
			open(menu);
		} else {
			menu.classList.remove('is-open');
			if (openMenu === menu) { openMenu = null; }
		}
	}, true);

	// Clicking a row action must close the menu before its handler runs.
	document.addEventListener('click', function (e) {
		const menu = e.target.closest ? e.target.closest(MENUS_SELECTOR) : null;

		if (!menu) {
			if (openMenu) { close(openMenu); }
			return;
		}

		if (e.target.closest('.' + DROPDOWN_CLASS) && e.target.closest('button, a')) {
			window.setTimeout(function () { close(menu); }, 0);
		}
	});

	document.addEventListener('keydown', function (e) {
		if ('Escape' !== e.key || !openMenu) { return; }
		const summary = openMenu.querySelector('summary');
		close(openMenu);
		if (summary) { summary.focus(); }
	});

	// The dropdown is fixed: keep it glued while the table container scrolls.
	window.addEventListener('scroll', function () {
		if (openMenu) { place(openMenu); }
	}, true);

	window.addEventListener('resize', function () {
		if (openMenu) { place(openMenu); }
	});
})();

</script>

