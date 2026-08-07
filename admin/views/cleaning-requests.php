<?php
/**
 * Cleaning requests admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$is_owner = Arriendo_Facil_Accommodation::user_is_owner();

if ( $is_owner ) {
	$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
	if ( ! empty( $owner_ids ) ) {
		$ids_sql = implode( ',', array_map( 'intval', $owner_ids ) );
		$requests = $wpdb->get_results(
			"SELECT r.*, p.post_title AS accommodation_title
			 FROM {$wpdb->prefix}af_cleaning_requests r
			 LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
			 WHERE r.accommodation_id IN ($ids_sql)
			 ORDER BY r.requested_date DESC
			 LIMIT 100"
		);
	} else {
		$requests = array();
	}
} else {
	$requests = $wpdb->get_results(
		"SELECT r.*, p.post_title AS accommodation_title
		 FROM {$wpdb->prefix}af_cleaning_requests r
		 LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
		 ORDER BY r.requested_date DESC
		 LIMIT 100"
	);
}

$owners_raw = $wpdb->get_results(
	"SELECT id, wp_user_id, owner_id_type, owner_id, subject
	 FROM {$wpdb->prefix}af_owner_contacts
	 ORDER BY id DESC"
);

$owners = array();
$owner_seen = array();
foreach ( $owners_raw as $owner_row ) {
	$owner_user_id = isset( $owner_row->wp_user_id ) ? (int) $owner_row->wp_user_id : 0;
	$owner_key     = $owner_user_id > 0
		? 'user_' . $owner_user_id
		: 'doc_' . (string) $owner_row->owner_id_type . '_' . (string) $owner_row->owner_id;

	if ( isset( $owner_seen[ $owner_key ] ) ) {
		continue;
	}

	$owner_seen[ $owner_key ] = true;
	$owners[] = $owner_row;
}

$accommodations = $wpdb->get_results(
	"SELECT p.ID, p.post_title, CAST(pm.meta_value AS UNSIGNED) AS owner_user_id
	 FROM {$wpdb->posts} p
	 LEFT JOIN {$wpdb->postmeta} pm
		ON pm.post_id = p.ID
		AND pm.meta_key = '_af_owner_id'
	 WHERE p.post_type = 'accommodation'
		AND p.post_status NOT IN ('trash', 'auto-draft')
	 ORDER BY p.post_title ASC"
);

$cleaning_services = $wpdb->get_results(
	"SELECT p.ID,
			p.post_title,
			pm_name.meta_value AS company_name,
			pm_ruc.meta_value AS company_ruc
	 FROM {$wpdb->posts} p
	 LEFT JOIN {$wpdb->postmeta} pm_name
		ON pm_name.post_id = p.ID
		AND pm_name.meta_key = '_af_company_name'
	 LEFT JOIN {$wpdb->postmeta} pm_ruc
		ON pm_ruc.post_id = p.ID
		AND pm_ruc.meta_key = '_af_company_ruc'
	 WHERE p.post_type = 'cleaning_service'
		AND p.post_status NOT IN ('trash', 'auto-draft')
	 ORDER BY COALESCE(pm_name.meta_value, p.post_title) ASC"
);
?>
<div class="wrap af-shell">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Operaciones', 'arriendo-facil' ),
			'title'    => __( 'Solicitudes de limpieza', 'arriendo-facil' ),
			'subtitle' => __( 'Programa, asigna y da seguimiento a cada solicitud. Genera contratos para el proveedor con un clic.', 'arriendo-facil' ),
			'actions'  => array(
				sprintf(
					'<button type="button" class="button af-btn af-btn--primary" id="af-new-cleaning-request"><span class="af-btn__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>%s</button>',
					esc_html__( 'Nueva solicitud', 'arriendo-facil' )
				),
			),
		)
	);
	?>

	<div id="af-cleaning-request-form-card" class="card" style="max-width: 1000px; margin: 16px 0; padding: 16px; display: none;">
		<h2><?php esc_html_e( 'Nueva solicitud de limpieza', 'arriendo-facil' ); ?></h2>
		<form id="af-cleaning-request-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<input type="hidden" name="action" value="af_create_cleaning_request" />
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_cleaning_request_nonce' ) ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="af_cleaning_owner_select"><?php esc_html_e( 'Nombre del propietario / RUC', 'arriendo-facil' ); ?></label></th>
					<td>
						<select id="af_cleaning_owner_select" class="regular-text" required>
							<option value=""><?php esc_html_e( 'Seleccionar propietario', 'arriendo-facil' ); ?></option>
							<?php foreach ( $owners as $owner ) : ?>
								<?php
								$owner_name    = (string) $owner->subject;
								$owner_doc     = (string) $owner->owner_id;
								$owner_type    = strtoupper( (string) $owner->owner_id_type );
								$owner_user_id = isset( $owner->wp_user_id ) ? (int) $owner->wp_user_id : 0;
								$owner_label   = $owner_name . ' - ' . $owner_type . ': ' . $owner_doc;
								?>
								<option
									value="<?php echo esc_attr( (string) $owner_user_id ); ?>"
									data-owner-name="<?php echo esc_attr( strtolower( $owner_name ) ); ?>"
									data-owner-ruc="<?php echo esc_attr( strtolower( $owner_doc ) ); ?>"
									data-owner-user="<?php echo esc_attr( (string) $owner_user_id ); ?>"
								>
									<?php echo esc_html( $owner_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_cleaning_accommodation_id"><?php esc_html_e( 'Accommodations', 'arriendo-facil' ); ?></label></th>
					<td>
						<select id="af_cleaning_accommodation_id" name="accommodation_id" class="regular-text" required>
							<option value=""><?php esc_html_e( 'Select accommodation', 'arriendo-facil' ); ?></option>
							<?php foreach ( $accommodations as $accommodation ) : ?>
								<option
									value="<?php echo esc_attr( (string) $accommodation->ID ); ?>"
									data-owner-user="<?php echo esc_attr( (string) (int) $accommodation->owner_user_id ); ?>"
								>
									<?php echo esc_html( (string) $accommodation->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_cleaning_service_select"><?php esc_html_e( 'Servicios de limpieza', 'arriendo-facil' ); ?></label></th>
					<td>
						<select id="af_cleaning_service_select" class="regular-text">
							<option value=""><?php esc_html_e( 'Seleccionar servicio de limpieza', 'arriendo-facil' ); ?></option>
							<?php foreach ( $cleaning_services as $service ) : ?>
								<?php
								$service_name = '' !== trim( (string) $service->company_name ) ? (string) $service->company_name : (string) $service->post_title;
								$service_ruc  = (string) $service->company_ruc;
								$service_label = $service_name;
								if ( '' !== $service_ruc ) {
									$service_label .= ' - RUC: ' . $service_ruc;
								}
								?>
								<option
									value="<?php echo esc_attr( (string) $service->ID ); ?>"
									data-company-name="<?php echo esc_attr( strtolower( $service_name ) ); ?>"
									data-company-ruc="<?php echo esc_attr( strtolower( $service_ruc ) ); ?>"
								>
									<?php echo esc_html( $service_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_cleaning_requested_date"><?php esc_html_e( 'Requested Date', 'arriendo-facil' ); ?></label></th>
					<td><input type="date" id="af_cleaning_requested_date" name="requested_date" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_cleaning_notes"><?php esc_html_e( 'Notas', 'arriendo-facil' ); ?></label></th>
					<td><textarea id="af_cleaning_notes" name="notes" rows="4" class="large-text"></textarea></td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary" id="af-cleaning-request-submit"><?php esc_html_e( 'Crear solicitud de limpieza', 'arriendo-facil' ); ?></button>
				<button type="button" class="button" id="af-cancel-cleaning-request"><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
			</p>
		</form>
	</div>

	<section class="af-section">
		<header class="af-section__header">
			<div>
				<h2 class="af-section__title"><?php esc_html_e( 'Listado de solicitudes', 'arriendo-facil' ); ?></h2>
				<p class="af-section__subtitle"><?php esc_html_e( 'Las más recientes aparecen primero.', 'arriendo-facil' ); ?></p>
			</div>
		</header>

	<table class="wp-list-table widefat fixed striped af-data-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Accommodation', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Requested Date', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Completed Date', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Status', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Notas', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $requests ) : ?>
				<?php foreach ( $requests as $request ) : ?>
					<?php
					$contract_url = add_query_arg(
						array(
							'action'     => 'af_generate_cleaning_contract_word',
							'nonce'      => wp_create_nonce( 'af_cleaning_request_nonce' ),
							'request_id' => (int) $request->id,
						),
						admin_url( 'admin-ajax.php' )
					);
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'ID', 'arriendo-facil' ); ?>"><?php echo esc_html( $request->id ); ?></td>
						<td data-label="<?php esc_attr_e( 'Accommodation', 'arriendo-facil' ); ?>"><?php echo esc_html( $request->accommodation_title ?: $request->accommodation_id ); ?></td>
						<td data-label="<?php esc_attr_e( 'Requested Date', 'arriendo-facil' ); ?>"><?php echo esc_html( $request->requested_date ); ?></td>
						<td data-label="<?php esc_attr_e( 'Completed Date', 'arriendo-facil' ); ?>"><?php echo esc_html( $request->completed_date ?: '—' ); ?></td>
						<td data-label="<?php esc_attr_e( 'Status', 'arriendo-facil' ); ?>"><?php echo af_pill( (string) $request->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td data-label="<?php esc_attr_e( 'Notas', 'arriendo-facil' ); ?>"><?php echo esc_html( $request->notes ); ?></td>
						<td class="af-td-actions" data-label="<?php esc_attr_e( 'Actions', 'arriendo-facil' ); ?>">
							<a class="button button-secondary" href="<?php echo esc_url( $contract_url ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Generar contrato', 'arriendo-facil' ); ?>
							</a>
							<?php if ( 'pending' === $request->status || 'in_progress' === $request->status ) : ?>
								<button type="button" class="button af-update-cleaning"
									data-request-id="<?php echo esc_attr( $request->id ); ?>"
									data-status="completed">
									<?php esc_html_e( 'Mark Complete', 'arriendo-facil' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No se encontraron solicitudes de limpieza.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
	</section>
</div>
