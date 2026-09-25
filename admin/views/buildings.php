<?php
/**
 * Collection hub per property ("Cobranza por inmueble").
 *
 * Framed as a billing hub: each unit is a tenant to charge (canon + alicuota
 * + services) and the page shows who lives in each unit and what is pending
 * for the current period.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$buildings_table = Arriendo_Facil_Property_Structure::buildings_table();

global $wpdb;

$accessible_building_ids = Arriendo_Facil_Tenancy::accessible_building_ids();
$scope_clause            = null === $accessible_building_ids ? '' : ' AND id IN (' . Arriendo_Facil_Tenancy::ids_in_clause( $accessible_building_ids ) . ')';

$buildings   = (array) $wpdb->get_results( "SELECT * FROM {$buildings_table} WHERE status = 'active'{$scope_clause} ORDER BY name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$selected_id = isset( $_GET['building_id'] ) ? absint( wp_unslash( $_GET['building_id'] ) ) : 0;

if ( $selected_id && ! Arriendo_Facil_Tenancy::can_access_building( $selected_id ) ) {
	$selected_id = 0;
}

if ( ! $selected_id && ! empty( $buildings ) ) {
	$selected_id = (int) $buildings[0]->id;
}

$selected_building = $selected_id ? Arriendo_Facil_Property_Structure::get_building( $selected_id ) : null;
$units             = $selected_id ? Arriendo_Facil_Property_Structure::get_units_by_building( $selected_id ) : array();
$coefficient_total = $selected_id ? Arriendo_Facil_Property_Structure::get_coefficient_total( $selected_id ) : 0.0;

$is_super_admin  = Arriendo_Facil_Tenancy::can_manage_all();
$property_admins = $is_super_admin ? Arriendo_Facil_Tenancy::get_property_admins() : array();

$accommodation_args = array(
	'post_type'      => 'accommodation',
	'post_status'    => array( 'publish', 'draft', 'private' ),
	'posts_per_page' => 200,
	'orderby'        => 'title',
	'order'          => 'ASC',
);
$accessible_accommodation_ids_for_dropdown = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
if ( null !== $accessible_accommodation_ids_for_dropdown ) {
	$accommodation_args['post__in'] = ! empty( $accessible_accommodation_ids_for_dropdown ) ? $accessible_accommodation_ids_for_dropdown : array( 0 );
}
$accommodations = get_posts( $accommodation_args );

// Unit count per building, used by the building switcher chips.
$unit_counts = array();
if ( ! empty( $buildings ) ) {
	$building_ids = array_map( 'absint', wp_list_pluck( $buildings, 'id' ) );
	$ids_sql      = Arriendo_Facil_Tenancy::ids_in_clause( $building_ids );
	$unit_rows    = $wpdb->get_results(
		'SELECT building_id, COUNT(*) AS total FROM ' . Arriendo_Facil_Property_Structure::units_table() . " WHERE building_id IN ({$ids_sql}) AND status = 'active' GROUP BY building_id" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
	foreach ( (array) $unit_rows as $row ) {
		$unit_counts[ (int) $row->building_id ] = (int) $row->total;
	}
}

// ---- Billing snapshot for the selected building --------------------------
//
// Active leases for the units' linked accommodations, the charges generated
// for the current period and how much is still pending per unit.
$current_period = current_time( 'Y-m' );

$units_leases          = array();
$charges_by_lease      = array();
$active_lease_count    = 0;
$pending_total_period  = 0.0;
$alicuota_total        = 0.0;

$linked_accommodation_ids = array_values(
	array_unique(
		array_filter(
			array_map( 'absint', wp_list_pluck( $units, 'accommodation_id' ) )
		)
	)
);

if ( ! empty( $linked_accommodation_ids ) && ! empty( $units ) ) {
	$acc_ph    = implode( ',', array_fill( 0, count( $linked_accommodation_ids ), '%d' ) );
	$lease_rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.id, l.accommodation_id, l.guest_id, l.monthly_rent, l.start_date, l.end_date,
			        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
			 FROM {$wpdb->prefix}af_leases l
			 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
			 WHERE l.status = 'active' AND l.deleted_at IS NULL AND l.accommodation_id IN ({$acc_ph})",
			$linked_accommodation_ids
		) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built safely.
	);

	foreach ( $lease_rows as $lease_row ) {
		$units_leases[ (int) $lease_row->accommodation_id ] = $lease_row;
	}

	$lease_ids = array_map( 'absint', wp_list_pluck( $lease_rows, 'id' ) );
	if ( ! empty( $lease_ids ) ) {
		$active_lease_count = count( $lease_ids );
		$lease_ph           = implode( ',', array_fill( 0, count( $lease_ids ), '%d' ) );
		$charge_rows        = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT lease_id, charge_type, amount, amount_paid, status
				 FROM {$wpdb->prefix}af_charges
				 WHERE lease_id IN ({$lease_ph}) AND period = %s",
				array_merge( $lease_ids, array( $current_period ) )
			) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built safely.
		);

		foreach ( $charge_rows as $charge_row ) {
			$charges_by_lease[ (int) $charge_row->lease_id ][ (string) $charge_row->charge_type ] = $charge_row;
		}
	}
}

$units_meta = array();
foreach ( $units as $unit ) {
	$lease     = isset( $units_leases[ (int) $unit->accommodation_id ] ) ? $units_leases[ (int) $unit->accommodation_id ] : null;
	$unit_hoa  = Arriendo_Facil_Property_Structure::calculate_unit_hoa( (int) $unit->id );
	$alicuota_total += $unit_hoa;

	$charges = array();
	if ( $lease && isset( $charges_by_lease[ (int) $lease->id ] ) ) {
		$charges = $charges_by_lease[ (int) $lease->id ];
	}

	$pending = 0.0;
	foreach ( $charges as $charge ) {
		if ( in_array( (string) $charge->status, array( 'pending', 'partial', 'overdue' ), true ) ) {
			$pending += (float) $charge->amount - (float) $charge->amount_paid;
		}
	}
	$pending_total_period += $pending;

	if ( null === $lease ) {
		$badge = empty( (int) $unit->accommodation_id ) ? 'unlinked' : 'available';
	} elseif ( $pending > 0.01 ) {
		$badge = 'pending';
	} else {
		$badge = 'aldia';
	}

	$units_meta[ (int) $unit->id ] = array(
		'lease'   => $lease,
		'hoa'     => $unit_hoa,
		'pending' => $pending,
		'badge'   => $badge,
	);
}
// ---------------------------------------------------------------------
// Cobranza: snapshot por inmueble (qué cobrar y cuándo) del tab "Cobranza".
// ---------------------------------------------------------------------
$cob_today   = current_time( 'Y-m-d' );
$cob_scope   = Arriendo_Facil_Tenancy::accessible_accommodation_ids();
$cob_prev_pe = gmdate( 'Y-m', strtotime( $current_period . '-01 -1 month' ) );
$cob_last_pe = gmdate( 'Y-m', strtotime( $current_period . '-01 +4 months' ) );

$cob_args = array(
	'post_type'      => 'accommodation',
	'post_status'    => array( 'publish', 'draft', 'private' ),
	'posts_per_page' => 500,
	'orderby'        => 'title',
	'order'          => 'ASC',
	'fields'         => 'ids',
);
if ( null !== $cob_scope ) {
	$cob_args['post__in'] = ! empty( $cob_scope ) ? array_map( 'absint', $cob_scope ) : array( 0 );
}
$cob_acc_ids = (array) get_posts( $cob_args );

$cob_meta = array();
if ( ! empty( $cob_acc_ids ) ) {
	$cob_ids_sql = Arriendo_Facil_Tenancy::ids_in_clause( $cob_acc_ids );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$cob_rows = (array) $wpdb->get_results(
		"SELECT p.ID,
		 MAX(CASE WHEN pm.meta_key = '_af_monthly_rent'  THEN pm.meta_value END) AS monthly_rent,
		 MAX(CASE WHEN pm.meta_key = '_af_property_type' THEN pm.meta_value END) AS property_type,
		 MAX(CASE WHEN pm.meta_key = '_af_address'       THEN pm.meta_value END) AS address,
		 MAX(CASE WHEN pm.meta_key = '_af_city'          THEN pm.meta_value END) AS city,
		 MAX(CASE WHEN pm.meta_key = '_af_bedrooms'      THEN pm.meta_value END) AS bedrooms,
		 MAX(CASE WHEN pm.meta_key = '_af_bathrooms'     THEN pm.meta_value END) AS bathrooms,
		 MAX(CASE WHEN pm.meta_key = '_thumbnail_id'     THEN pm.meta_value END) AS thumbnail_id
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
		  AND pm.meta_key IN ('_af_monthly_rent','_af_property_type','_af_address','_af_city','_af_bedrooms','_af_bathrooms','_thumbnail_id')
		 WHERE p.ID IN ({$cob_ids_sql})
		 GROUP BY p.ID
		 ORDER BY p.post_title ASC"
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	foreach ( $cob_rows as $row ) {
		$cob_meta[ (int) $row->ID ] = $row;
	}
}

$cob_units = array();
if ( ! empty( $cob_acc_ids ) ) {
	$cob_units_ph = implode( ',', array_fill( 0, count( $cob_acc_ids ), '%d' ) );
	$cob_units_rs = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT u.accommodation_id, u.unit_code, b.name AS building_name
			 FROM {$wpdb->prefix}af_units u
			 LEFT JOIN {$wpdb->prefix}af_buildings b ON b.id = u.building_id
			 WHERE u.accommodation_id IN ({$cob_units_ph}) AND u.status = 'active'",
			$cob_acc_ids
		) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built safely.
	);
	foreach ( $cob_units_rs as $row ) {
		$cob_units[ (int) $row->accommodation_id ] = $row;
	}
}

$cob_leases      = array();
$cob_lease_ids   = array();
$cob_charges_acc = array();
if ( ! empty( $cob_acc_ids ) ) {
	$cob_ids_ph = implode( ',', array_fill( 0, count( $cob_acc_ids ), '%d' ) );
	$cob_lrows  = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.id, l.accommodation_id, l.guest_id, l.monthly_rent, l.payment_due_day, l.end_date,
			        CONCAT(g.first_name, ' ', g.last_name) AS guest_name
			 FROM {$wpdb->prefix}af_leases l
			 LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
			 WHERE l.status = 'active' AND l.deleted_at IS NULL AND l.accommodation_id IN ({$cob_ids_ph})
			 ORDER BY l.start_date DESC",
			$cob_acc_ids
		) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built safely.
	);
	foreach ( $cob_lrows as $row ) {
		if ( ! isset( $cob_leases[ (int) $row->accommodation_id ] ) ) {
			$cob_leases[ (int) $row->accommodation_id ] = $row;
		}
		$cob_lease_ids[] = (int) $row->id;
	}

	if ( ! empty( $cob_lease_ids ) ) {
		$cob_lph     = implode( ',', array_fill( 0, count( $cob_lease_ids ), '%d' ) );
		$cob_chr_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.lease_id, c.charge_type, c.description, c.amount, c.amount_paid, c.due_date, c.status, c.period, l.accommodation_id
				 FROM {$wpdb->prefix}af_charges c
				 INNER JOIN {$wpdb->prefix}af_leases l ON l.id = c.lease_id
				 WHERE c.lease_id IN ({$cob_lph}) AND c.period BETWEEN %s AND %s AND c.status <> 'void'
				 ORDER BY c.period ASC, c.due_date ASC, c.id ASC",
				array_merge( $cob_lease_ids, array( $cob_prev_pe, $cob_last_pe ) )
			) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built safely.
		);
		foreach ( $cob_chr_rows as $row ) {
			$cob_charges_acc[ (int) $row->accommodation_id ][] = $row;
		}
	}
}

$cob_types = array(
	'apartment'  => array( 'label' => __( 'Apartamento', 'arriendo-facil' ), 'icon' => 'building' ),
	'house'      => array( 'label' => __( 'Casa', 'arriendo-facil' ), 'icon' => 'home' ),
	'office'     => array( 'label' => __( 'Oficina', 'arriendo-facil' ), 'icon' => 'building-2' ),
	'room'       => array( 'label' => __( 'Habitación', 'arriendo-facil' ), 'icon' => 'bed' ),
	'commercial' => array( 'label' => __( 'Comercial', 'arriendo-facil' ), 'icon' => 'store' ),
);

$cob_charge_labels = array(
	'canon'     => __( 'Canon', 'arriendo-facil' ),
	'alicuota'  => __( 'Alícuota', 'arriendo-facil' ),
	'agua'      => __( 'Agua', 'arriendo-facil' ),
	'luz'       => __( 'Luz', 'arriendo-facil' ),
	'gas'       => __( 'Gas', 'arriendo-facil' ),
	'internet'  => __( 'Internet', 'arriendo-facil' ),
	'multa'     => __( 'Multa', 'arriendo-facil' ),
	'otro'      => __( 'Otro', 'arriendo-facil' ),
);

$cob_props = array();
foreach ( $cob_acc_ids as $acc_id ) {
	$acc_id   = (int) $acc_id;
	$meta     = isset( $cob_meta[ $acc_id ] ) ? $cob_meta[ $acc_id ] : null;
	$type     = $meta ? (string) $meta->property_type : '';
	$unit     = isset( $cob_units[ $acc_id ] ) ? $cob_units[ $acc_id ] : null;
	$lease    = isset( $cob_leases[ $acc_id ] ) ? $cob_leases[ $acc_id ] : null;
	$has_ls   = null !== $lease;
	$pay_day  = $has_ls ? (int) $lease->payment_due_day : 0;
	$pay_day  = $pay_day >= 1 && $pay_day <= 28 ? $pay_day : 5;
	$charges  = isset( $cob_charges_acc[ $acc_id ] ) ? $cob_charges_acc[ $acc_id ] : array();

	$unpaid       = array();
	$cur_charges  = array();
	$cur_pending  = 0.0;
	foreach ( $charges as $c ) {
		if ( (string) $c->period === $current_period ) {
			$cur_charges[] = $c;
		}
		$bal = (float) $c->amount - (float) $c->amount_paid;
		if ( in_array( (string) $c->status, array( 'pending', 'partial' ), true ) && $bal > 0.01 ) {
			$unpaid[] = array( 'balance' => $bal, 'due' => (string) $c->due_date );
			if ( (string) $c->period === $current_period ) {
				$cur_pending += $bal;
			}
		}
	}

	$overdue_total = 0.0;
	$next_due      = '';
	foreach ( $unpaid as $item ) {
		if ( $item['due'] < $cob_today ) {
			$overdue_total += $item['balance'];
		}
		if ( '' === $next_due || $item['due'] < $next_due ) {
			$next_due = $item['due'];
		}
	}
	$pending_total = 0.0;
	foreach ( $unpaid as $item ) {
		$pending_total += $item['balance'];
	}

	if ( ! $has_ls && empty( $cur_charges ) ) {
		$status = 'available';
	} elseif ( $overdue_total > 0.01 || ( '' !== $next_due && $next_due < $cob_today ) ) {
		$status = 'vencido';
	} elseif ( $next_due === $cob_today ) {
		$status = 'vence_hoy';
	} elseif ( '' !== $next_due && $next_due > $cob_today && strtotime( $next_due ) <= strtotime( $cob_today . ' +5 days' ) ) {
		$status = 'vence_proximo';
	} elseif ( $has_ls && empty( $cur_charges ) ) {
		$status = 'sin_cargo';
	} elseif ( $pending_total > 0.01 ) {
		$status = 'por_cobrar';
	} else {
		$status = 'aldia';
	}

	$cob_props[ $acc_id ] = array(
		'id'              => $acc_id,
		'title'           => get_the_title( $acc_id ),
		'address'         => $meta ? (string) $meta->address : '',
		'city'            => $meta ? (string) $meta->city : '',
		'type'            => $type,
		'type_icon'       => isset( $cob_types[ $type ] ) ? $cob_types[ $type ]['icon'] : 'building',
		'type_label'      => isset( $cob_types[ $type ] ) ? $cob_types[ $type ]['label'] : ucfirst( $type ),
		'bedrooms'        => $meta ? (int) $meta->bedrooms : 0,
		'bathrooms'       => $meta ? (int) $meta->bathrooms : 0,
		'thumb'           => $meta && $meta->thumbnail_id ? wp_get_attachment_image_url( (int) $meta->thumbnail_id, 'medium_large' ) : get_the_post_thumbnail_url( $acc_id, 'medium_large' ),
		'unit_code'       => $unit ? (string) $unit->unit_code : '',
		'building_name'   => $unit ? (string) $unit->building_name : '',
		'guest'           => $has_ls ? trim( (string) $lease->guest_name ) : '',
		'monthly_rent'    => $has_ls ? (float) $lease->monthly_rent : ( $meta && $meta->monthly_rent ? (float) $meta->monthly_rent : 0.0 ),
		'payment_due_day' => $pay_day,
		'lease_id'        => $has_ls ? (int) $lease->id : 0,
		'lease_end'       => $has_ls ? (string) $lease->end_date : '',
		'has_lease'       => $has_ls,
		'status'          => $status,
		'pending_total'   => $pending_total,
		'pending_month'   => $cur_pending,
		'overdue_total'   => $overdue_total,
		'next_due'        => $next_due,
	);
}

$cob_json_props = array();
foreach ( $cob_props as $acc_id => $prop ) {
	$per = array();
	foreach ( ( isset( $cob_charges_acc[ $acc_id ] ) ? $cob_charges_acc[ $acc_id ] : array() ) as $c ) {
		$per[ (string) $c->period ][] = array(
			'id'      => (int) $c->id,
			'type'    => (string) $c->charge_type,
			'label'   => isset( $cob_charge_labels[ (string) $c->charge_type ] ) ? $cob_charge_labels[ (string) $c->charge_type ] : (string) $c->charge_type,
			'desc'    => (string) $c->description,
			'amount'  => (float) $c->amount,
			'paid'    => (float) $c->amount_paid,
			'dueDate' => (string) $c->due_date,
			'status'  => (string) $c->status,
		);
	}
	$cob_json_props[ $acc_id ] = array(
		'id'              => $prop['id'],
		'title'           => $prop['title'],
		'guest'           => $prop['guest'],
		'unit_code'       => $prop['unit_code'],
		'building_name'   => $prop['building_name'],
		'payment_due_day' => $prop['payment_due_day'],
		'has_lease'       => $prop['has_lease'],
		'status'          => $prop['status'],
		'charges'         => $per,
	);
}

$cob_statuses_order = array(
	'vencido'       => 0,
	'vence_hoy'     => 1,
	'vence_proximo' => 2,
	'por_cobrar'    => 3,
	'sin_cargo'     => 4,
	'aldia'         => 5,
	'available'     => 6,
);
usort(
	$cob_props,
	static function ( $p ) {
		return 'available' !== $p['status'];
	}
);

$cob_summary = array(
	'vencido_count'  => 0,
	'vencido_total'  => 0.0,
	'proximo_count'  => 0,
	'proximo_total'  => 0.0,
	'pending_month'  => 0.0,
	'aldia_count'    => 0,
	'disponibles'    => 0,
);
foreach ( $cob_props as $prop ) {
	if ( 'vencido' === $prop['status'] ) {
		$cob_summary['vencido_count']++;
		$cob_summary['vencido_total'] += $prop['pending_total'];
	} elseif ( in_array( $prop['status'], array( 'vence_hoy', 'vence_proximo' ), true ) ) {
		$cob_summary['proximo_count']++;
		$cob_summary['proximo_total'] += $prop['pending_total'];
	} elseif ( 'aldia' === $prop['status'] ) {
		$cob_summary['aldia_count']++;
	} elseif ( 'available' === $prop['status'] ) {
		$cob_summary['disponibles']++;
	}
	$cob_summary['pending_month'] += $prop['pending_month'];
}

$cob_status_meta = array(
	'vencido'       => array( 'pill' => 'danger',  'ring' => 'is-overdue', 'label' => __( 'Vencido', 'arriendo-facil' ) ),
	'vence_hoy'     => array( 'pill' => 'danger',  'ring' => 'is-overdue', 'label' => __( 'Vence hoy', 'arriendo-facil' ) ),
	'vence_proximo' => array( 'pill' => 'warning', 'ring' => 'is-soon',    'label' => __( 'Vence pronto', 'arriendo-facil' ) ),
	'por_cobrar'    => array( 'pill' => 'info',    'ring' => '',           'label' => __( 'Por cobrar', 'arriendo-facil' ) ),
	'sin_cargo'     => array( 'pill' => 'neutral', 'ring' => '',           'label' => __( 'Sin cargo generado', 'arriendo-facil' ) ),
	'aldia'         => array( 'pill' => 'success', 'ring' => '',           'label' => __( 'Al día', 'arriendo-facil' ) ),
	'available'     => array( 'pill' => 'neutral', 'ring' => '',           'label' => __( 'Disponible', 'arriendo-facil' ) ),
);
?>

<div class="wrap af-shell af-buildings-page">
	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Cobranza', 'arriendo-facil' ),
			'title'    => __( 'Edificios y unidades', 'arriendo-facil' ),
			'subtitle' => __( 'Tus inmuebles a cobrar, por tarjeta: cuánto falta por cobrar y cuándo vence. Pulsa una tarjeta para abrir el calendario de cobros del mes. La gestión de edificios y unidades vive en la pestaña "Estructura".', 'arriendo-facil' ),
		)
	);
	?>

	<div class="af-tabs af-buildings-tabs">
		<input type="radio" class="af-tabs__radio" id="af-tab-cobranza" name="af-buildings-tabs" checked />
		<input type="radio" class="af-tabs__radio" id="af-tab-estructura" name="af-buildings-tabs" />
		<div class="af-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'Sección de edificios y unidades', 'arriendo-facil' ); ?>">
			<label class="af-tabs__tab" for="af-tab-cobranza" role="tab" aria-selected="true">
				<span class="af-tabs__icon" aria-hidden="true"><?php echo af_lucide( 'wallet', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-tabs__label"><?php esc_html_e( 'Cobranza', 'arriendo-facil' ); ?></span>
				<?php if ( $cob_summary['vencido_count'] + $cob_summary['proximo_count'] > 0 ) : ?>
					<span class="af-tabs__badge af-tabs__badge--attention"><?php echo esc_html( number_format_i18n( $cob_summary['vencido_count'] + $cob_summary['proximo_count'] ) ); ?></span>
				<?php endif; ?>
			</label>
			<label class="af-tabs__tab" for="af-tab-estructura" role="tab" aria-selected="false">
				<span class="af-tabs__icon" aria-hidden="true"><?php echo af_lucide( 'building-2', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				<span class="af-tabs__label"><?php esc_html_e( 'Estructura', 'arriendo-facil' ); ?></span>
			</label>
		</div>
		<div class="af-tabs__panels">
			<section class="af-tabs__panel af-tabs__panel--cobranza" role="tabpanel" aria-labelledby="af-tab-cobranza">
				<?php if ( empty( $cob_props ) && 0 === $cob_summary['disponibles'] ) : ?>
					<div class="af-empty">
						<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'building', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
						<h3 class="af-empty__title"><?php esc_html_e( 'Aún no tienes inmuebles registrados', 'arriendo-facil' ); ?></h3>
						<p class="af-empty__text"><?php esc_html_e( 'Registra tus propiedades en el Catálogo de inmuebles para que aparezcan aquí, con su canon y sus fechas de cobro.', 'arriendo-facil' ); ?></p>
						<a class="button af-btn af-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=af-catalog' ) ); ?>"><?php esc_html_e( 'Ir al catálogo', 'arriendo-facil' ); ?></a>
					</div>
				<?php else : ?>
					<section class="af-cobranza-alerts">
						<?php if ( $cob_summary['vencido_count'] > 0 ) : ?>
							<div class="af-cobranza-alert af-cobranza-alert--danger">
								<span class="af-cobranza-alert__icon" aria-hidden="true"><?php echo af_lucide( 'triangle-alert', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
								<div class="af-cobranza-alert__body">
									<strong><?php echo esc_html( sprintf( /* translators: %d: number of properties */ _n( '%d inmueble con cobros vencidos', '%d inmuebles con cobros vencidos', $cob_summary['vencido_count'], 'arriendo-facil' ), $cob_summary['vencido_count'] ) ); ?></strong>
									<span><?php echo esc_html( sprintf( /* translators: %s: amount */ __( '%s por cobrar. Revisa las tarjetas en rojo.', 'arriendo-facil' ), '$' . number_format_i18n( $cob_summary['vencido_total'], 2 ) ) ); ?></span>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( $cob_summary['proximo_count'] > 0 ) : ?>
							<div class="af-cobranza-alert af-cobranza-alert--warning">
								<span class="af-cobranza-alert__icon" aria-hidden="true"><?php echo af_lucide( 'clock', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
								<div class="af-cobranza-alert__body">
									<strong><?php echo esc_html( sprintf( /* translators: %d: number of properties */ _n( '%d inmueble vence en los próximos días', '%d inmuebles vencen en los próximos días', $cob_summary['proximo_count'], 'arriendo-facil' ), $cob_summary['proximo_count'] ) ); ?></strong>
									<span><?php echo esc_html( sprintf( /* translators: %s: amount */ __( '%s por cobrar. Prepara la cobranza.', 'arriendo-facil' ), '$' . number_format_i18n( $cob_summary['proximo_total'], 2 ) ) ); ?></span>
								</div>
							</div>
						<?php endif; ?>

						<div class="af-cobranza-alert af-cobranza-alert--ok">
							<span class="af-cobranza-alert__icon" aria-hidden="true"><?php echo af_lucide( 'calendar', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							<div class="af-cobranza-alert__body">
								<strong><?php echo esc_html( sprintf( /* translators: 1: amount, 2: period */ __( 'Total a cobrar este mes: %1$s (%2$s)', 'arriendo-facil' ), '$' . number_format_i18n( $cob_summary['pending_month'], 2 ), $current_period ) ); ?></strong>
								<span>
									<?php
									if ( $cob_summary['vencido_count'] > 0 || $cob_summary['proximo_count'] > 0 ) {
										echo esc_html( sprintf( /* translators: %d: number of properties */ _n( '%d inmueble al día', '%d inmuebles al día', $cob_summary['aldia_count'], 'arriendo-facil' ), $cob_summary['aldia_count'] ) );
									} else {
										echo esc_html__( 'Sin vencidos ni cobros próximos. Todo al día.', 'arriendo-facil' );
									}
									?>
								</span>
							</div>
						</div>
					</section>

					<div class="af-property-grid af-cobranza-grid">
						<?php foreach ( $cob_props as $acc_id => $prop ) : ?>
							<?php
							$csm       = $cob_status_meta[ $prop['status'] ];
							$address   = trim( trim( (string) $prop['address'] ) . ( $prop['city'] ? ', ' . $prop['city'] : '' ) );
							$due_hint  = '';
							$due_class = '';
							if ( 'vencido' === $prop['status'] && $prop['next_due'] ) {
								$due_hint  = sprintf(
									/* translators: %s: formatted date */
									__( 'Vencido desde el %s', 'arriendo-facil' ),
									date_i18n( 'j M', strtotime( $prop['next_due'] ) )
								);
								$due_class = 'is-danger';
							} elseif ( 'vence_hoy' === $prop['status'] ) {
								$due_hint  = __( 'Vence hoy', 'arriendo-facil' );
								$due_class = 'is-danger';
							} elseif ( 'vence_proximo' === $prop['status'] && $prop['next_due'] ) {
								$days      = (int) ceil( ( strtotime( $prop['next_due'] ) - strtotime( $cob_today ) ) / DAY_IN_SECONDS );
								$rel       = 1 === $days ? __( 'mañana', 'arriendo-facil' ) : sprintf( /* translators: %d: days */ _n( 'en %d día', 'en %d días', $days, 'arriendo-facil' ), $days );
								$due_hint  = sprintf(
									/* translators: 1: relative time, 2: formatted date */
									__( 'Vence %1$s (%2$s)', 'arriendo-facil' ),
									$rel,
									date_i18n( 'j M', strtotime( $prop['next_due'] ) )
								);
								$due_class = 'is-warning';
							} elseif ( 'por_cobrar' === $prop['status'] && $prop['next_due'] ) {
								$due_hint = sprintf(
									/* translators: %s: formatted date */
									__( 'Vence el %s', 'arriendo-facil' ),
									date_i18n( 'j M', strtotime( $prop['next_due'] ) )
								);
							} elseif ( 'sin_cargo' === $prop['status'] ) {
								$due_hint = sprintf(
									/* translators: %d: due day of month */
									__( 'Día de pago: %d de cada mes', 'arriendo-facil' ),
									$prop['payment_due_day']
								);
							} elseif ( 'aldia' === $prop['status'] ) {
								$due_hint = __( 'Todo al día', 'arriendo-facil' );
							} else {
								$due_hint = __( 'Sin contrato activo', 'arriendo-facil' );
							}

							$amounttotal = $prop['pending_total'] > 0.01 ? $prop['pending_total'] : $prop['monthly_rent'];
							$amount_lbl  = $prop['pending_total'] > 0.01 ? __( 'por cobrar', 'arriendo-facil' ) : __( '/ mes', 'arriendo-facil' );
							?>
							<article
								class="af-property-card af-cobranza-card <?php echo esc_attr( $csm['ring'] ); ?>"
								data-prop="<?php echo esc_attr( $acc_id ); ?>"
								role="button"
								tabindex="0"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: property title */ __( 'Ver cobranza de %s', 'arriendo-facil' ), $prop['title'] ) ); ?>"
							>
								<div class="af-property-card__media">
									<?php if ( $prop['thumb'] ) : ?>
										<img src="<?php echo esc_url( $prop['thumb'] ); ?>" alt="<?php echo esc_attr( $prop['title'] ); ?>" loading="lazy" />
									<?php else : ?>
										<div class="af-property-card__placeholder" aria-hidden="true"><?php echo af_lucide( 'building', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></div>
									<?php endif; ?>
									<div class="af-property-card__badges">
										<span class="af-pill af-pill--<?php echo esc_attr( $csm['pill'] ); ?>"><?php echo esc_html( $csm['label'] ); ?></span>
									</div>
								</div>
								<div class="af-property-card__body">
									<div class="af-catalog-card__type">
										<span class="af-catalog-card__type-icon" aria-hidden="true"><?php echo af_lucide( $prop['type_icon'], 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
										<?php echo esc_html( $prop['type_label'] ); ?>
									</div>
									<h3 class="af-property-card__title"><?php echo esc_html( $prop['title'] ); ?></h3>
									<?php if ( $prop['unit_code'] ) : ?>
										<p class="af-catalog-card__address">
											<span class="af-catalog-card__address-icon" aria-hidden="true"><?php echo af_lucide( 'home', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
											<span class="af-catalog-card__address-text"><?php echo esc_html( $prop['unit_code'] . ( $prop['building_name'] ? ' · ' . $prop['building_name'] : '' ) ); ?></span>
										</p>
									<?php endif; ?>
									<?php if ( $address ) : ?>
										<p class="af-catalog-card__address">
											<span class="af-catalog-card__address-icon" aria-hidden="true"><?php echo af_lucide( 'map-pin', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
											<span class="af-catalog-card__address-text"><?php echo esc_html( $address ); ?></span>
										</p>
									<?php endif; ?>
									<p class="af-catalog-card__specs">
										<?php if ( $prop['guest'] ) : ?>
											<span class="af-catalog-card__spec">
												<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'user', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
												<?php echo esc_html( $prop['guest'] ); ?>
											</span>
										<?php endif; ?>
										<?php if ( $prop['bedrooms'] ) : ?>
											<span class="af-catalog-card__spec">
												<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'bed', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
												<?php echo esc_html( sprintf( /* translators: %d: bedrooms */ _n( '%d dorm.', '%d dorm.', $prop['bedrooms'], 'arriendo-facil' ), $prop['bedrooms'] ) ); ?>
											</span>
										<?php endif; ?>
										<?php if ( $prop['lease_end'] ) : ?>
											<span class="af-catalog-card__spec">
												<span class="af-catalog-card__spec-icon" aria-hidden="true"><?php echo af_lucide( 'calendar', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
												<?php echo esc_html( sprintf( /* translators: %s: formatted date */ __( 'Contrato hasta %s', 'arriendo-facil' ), date_i18n( 'j M Y', strtotime( $prop['lease_end'] ) ) ) ); ?>
											</span>
										<?php endif; ?>
									</p>
								</div>
								<div class="af-property-card__footer">
									<span class="af-cobranza-card__amount <?php echo esc_attr( $due_class ); ?>">
										$<?php echo esc_html( number_format_i18n( $amounttotal, 2 ) ); ?>
										<small><?php echo esc_html( $amount_lbl ); ?></small>
									</span>
									<span class="af-cobranza-card__due <?php echo esc_attr( $due_class ); ?>"><?php echo esc_html( $due_hint ); ?></span>
									<span class="af-catalog-card__hint">
										<?php esc_html_e( 'Ver cobranza', 'arriendo-facil' ); ?>
										<span class="af-catalog-card__hint-arrow" aria-hidden="true">→</span>
									</span>
								</div>
							</article>
						<?php endforeach; ?>
					</div>

					<?php if ( $cob_summary['disponibles'] > 0 ) : ?>
						<?php $disponibles = array_filter( $cob_props, static function ( $p ) { return 'available' === $p['status']; } ); ?>
						<details class="af-collapse af-section af-cobranza-avail" style="margin-top: var(--af-space-5);">
							<summary class="af-collapse__summary">
								<span class="af-section__icon af-section__icon--slate" aria-hidden="true"><?php echo af_lucide( 'building', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
								<span class="af-collapse__label"><?php echo esc_html( sprintf( /* translators: %d: number of properties */ _n( '%d inmueble disponible (sin contrato)', '%d inmuebles disponibles (sin contrato)', $cob_summary['disponibles'], 'arriendo-facil' ), $cob_summary['disponibles'] ) ); ?></span>
								<span class="af-collapse__chevron" aria-hidden="true"><?php echo af_lucide( 'chevron-down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
							</summary>
							<div class="af-collapse__body">
								<div class="af-property-grid">
									<?php foreach ( $disponibles as $acc_id => $prop ) : ?>
										<?php $csm = $cob_status_meta[ $prop['status'] ]; ?>
										<article class="af-property-card af-cobranza-card" data-prop="<?php echo esc_attr( $acc_id ); ?>" role="button" tabindex="0" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: property title */ __( 'Ver cobranza de %s', 'arriendo-facil' ), $prop['title'] ) ); ?>">
											<div class="af-property-card__media">
												<?php if ( $prop['thumb'] ) : ?>
													<img src="<?php echo esc_url( $prop['thumb'] ); ?>" alt="<?php echo esc_attr( $prop['title'] ); ?>" loading="lazy" />
												<?php else : ?>
													<div class="af-property-card__placeholder" aria-hidden="true"><?php echo af_lucide( 'building', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></div>
												<?php endif; ?>
												<div class="af-property-card__badges"><span class="af-pill af-pill--<?php echo esc_attr( $csm['pill'] ); ?>"><?php echo esc_html( $csm['label'] ); ?></span></div>
											</div>
											<div class="af-property-card__body">
												<div class="af-catalog-card__type">
													<span class="af-catalog-card__type-icon" aria-hidden="true"><?php echo af_lucide( $prop['type_icon'], 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
													<?php echo esc_html( $prop['type_label'] ); ?>
												</div>
												<h3 class="af-property-card__title"><?php echo esc_html( $prop['title'] ); ?></h3>
												<p class="af-catalog-card__address"><span class="af-cobranza-card__due"><?php esc_html_e( 'Sin contrato activo: no genera cobros este mes.', 'arriendo-facil' ); ?></span></p>
											</div>
										</article>
									<?php endforeach; ?>
								</div>
							</div>
						</details>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<section class="af-tabs__panel af-tabs__panel--estructura" role="tabpanel" aria-labelledby="af-tab-estructura">

	<section class="af-section" style="display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding: var(--af-space-4) var(--af-space-5); margin-bottom: var(--af-space-4);">
		<span class="af-section__icon af-section__icon--slate" aria-hidden="true"><?php echo af_lucide( 'building', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		<div style="flex:1; min-width:220px;">
			<h2 class="af-section__title" style="margin:0;">
				<?php if ( Arriendo_Facil_Property_Structure::module_enabled() ) : ?>
					<?php esc_html_e( 'Sección activa', 'arriendo-facil' ); ?> <span class="af-pill af-pill--success"><?php esc_html_e( 'Elegiste usarla', 'arriendo-facil' ); ?></span>
				<?php else : ?>
					<?php esc_html_e( 'Sección opcional', 'arriendo-facil' ); ?> <span class="af-pill af-pill--info"><?php esc_html_e( 'No elegida todavía', 'arriendo-facil' ); ?></span>
				<?php endif; ?>
			</h2>
			<p class="af-section__subtitle" style="margin:2px 0 0;">
				<?php
				if ( Arriendo_Facil_Property_Structure::module_enabled() ) {
					esc_html_e( 'La usas para repartir los gastos comunes de un edificio entre sus departamentos u oficinas y cobrarlos junto con el canon. Puedes desactivarla cuando quieras y seguir igual con casas o propiedades sueltas.', 'arriendo-facil' );
				} else {
					esc_html_e( 'Solo elígela si arriendas varias propiedades dentro de un mismo edificio y necesitas repartir entre ellas los gastos comunes (mantenimiento, guardianía, agua del edificio, etc.). Si solo tienes casas o locales sueltos, no la necesitas: puedes ignorarla.', 'arriendo-facil' );
				}
				?>
			</p>
			<?php if ( ! Arriendo_Facil_Property_Structure::module_enabled() && Arriendo_Facil_Property_Structure::has_active_buildings() ) : ?>
				<p class="af-section__subtitle" style="margin:4px 0 0; font-weight:600;">
					<?php esc_html_e( 'Nota: ya tienes edificios guardados. No se borran; los volverás a ver al activar la sección.', 'arriendo-facil' ); ?>
				</p>
			<?php endif; ?>
			<p class="af-modal__status" id="af-buildings-module-status" style="margin:6px 0 0;"></p>
		</div>
		<button
			type="button"
			id="af-buildings-module-toggle"
			class="button af-btn <?php echo Arriendo_Facil_Property_Structure::module_enabled() ? 'af-btn--ghost' : 'af-btn--primary'; ?>"
			data-enable="<?php echo Arriendo_Facil_Property_Structure::module_enabled() ? '0' : '1'; ?>"
		>
			<?php echo Arriendo_Facil_Property_Structure::module_enabled() ? esc_html_e( 'Apagar (no la uso)', 'arriendo-facil' ) : esc_html_e( 'Activar sección', 'arriendo-facil' ); ?>
		</button>
	</section>

	<?php if ( ! Arriendo_Facil_Property_Structure::module_enabled() ) : ?>

		<div class="af-empty">
			<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'building-2', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<h3 class="af-empty__title"><?php esc_html_e( 'Esta sección no está activada', 'arriendo-facil' ); ?></h3>
			<p class="af-empty__text"><?php esc_html_e( 'Pulsa el botón "Activar sección" de arriba si quieres organizar departamentos dentro de un edificio y repartir (y cobrar) los gastos comunes.', 'arriendo-facil' ); ?></p>
		</div>

	<?php elseif ( empty( $buildings ) ) : ?>

		<div class="af-empty" style="margin-bottom: var(--af-space-5);">
			<span class="af-empty__icon" aria-hidden="true"><?php echo af_lucide( 'building-2', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
			<h3 class="af-empty__title"><?php esc_html_e( 'Aún no hay edificios registrados', 'arriendo-facil' ); ?></h3>
			<p class="af-empty__text"><?php esc_html_e( 'Aquí organizas los edificios con varios arriendos adentro (por ejemplo, un edificio con departamentos) para cobrar canon y gastos comunes por unidad. Si tus propiedades son casas o locales sueltos, no necesitas usar esta sección.', 'arriendo-facil' ); ?></p>
		</div>

		<section class="af-section">
			<header class="af-section__header">
				<div class="af-section__head">
					<h2 class="af-section__title"><?php esc_html_e( 'Nuevo edificio', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'Pon el nombre del edificio y cuánto se gasta por mes en mantenerlo. Después añades cada departamento y cuánto le toca pagar.', 'arriendo-facil' ); ?></p>
				</div>
			</header>
			<p class="af-modal__status" id="af-building-status"></p>
			<form id="af-building-form" class="af-buildings-form">
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-name"><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?> <span class="af-required" aria-hidden="true">*</span></label>
					<input type="text" id="af-building-name" name="name" required class="regular-text" />
				</div>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-address"><?php esc_html_e( 'Dirección', 'arriendo-facil' ); ?></label>
					<input type="text" id="af-building-address" name="address" class="regular-text" />
				</div>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-city"><?php esc_html_e( 'Ciudad', 'arriendo-facil' ); ?></label>
					<input type="text" id="af-building-city" name="city" class="regular-text" />
				</div>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-hoa"><?php esc_html_e( 'Gastos comunes mensuales (USD)', 'arriendo-facil' ); ?></label>
					<input type="number" id="af-building-hoa" name="monthly_hoa_total" step="0.01" min="0" value="0.00" />
					<p class="af-form-field__hint"><?php esc_html_e( 'Cuánto cuesta al mes mantener el edificio entre todos: mantenimiento, guardianía, agua del edificio, etc. El sistema lo reparte entre las unidades.', 'arriendo-facil' ); ?></p>
				</div>
				<?php if ( $is_super_admin && ! empty( $property_admins ) ) : ?>
				<div class="af-form-field">
					<label class="af-form-field__label" for="af-building-admin"><?php esc_html_e( 'Administrador de propiedades', 'arriendo-facil' ); ?></label>
					<select id="af-building-admin" name="assigned_admin_id">
						<option value=""><?php esc_html_e( '— Asignar a mí mismo —', 'arriendo-facil' ); ?></option>
						<?php foreach ( $property_admins as $pa ) : ?>
							<option value="<?php echo esc_attr( $pa->ID ); ?>"><?php echo esc_html( get_user_meta( $pa->ID, 'af_company_name', true ) ? get_user_meta( $pa->ID, 'af_company_name', true ) : $pa->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Crear edificio', 'arriendo-facil' ); ?></button>
			</form>
		</section>

	<?php else : ?>

		<nav class="af-building-switcher" aria-label="<?php esc_attr_e( 'Seleccionar edificio', 'arriendo-facil' ); ?>">
			<?php foreach ( $buildings as $building ) : ?>
				<?php
				$is_active = $selected_id === (int) $building->id;
				$count     = isset( $unit_counts[ (int) $building->id ] ) ? $unit_counts[ (int) $building->id ] : 0;
				$place     = trim( trim( (string) $building->address ) . ( ! empty( $building->city ) ? ', ' . $building->city : '' ) );
				?>
				<a class="af-building-chip <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'af-buildings', 'building_id' => (int) $building->id ), admin_url( 'admin.php' ) ) ); ?>">
					<span class="af-building-chip__icon" aria-hidden="true"><?php echo af_lucide( 'building-2', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<span class="af-building-chip__body">
						<span class="af-building-chip__name"><?php echo esc_html( $building->name ); ?></span>
						<?php if ( '' !== $place ) : ?>
							<span class="af-building-chip__meta"><?php echo esc_html( $place ); ?></span>
						<?php endif; ?>
					</span>
					<span class="af-building-chip__count"><?php echo esc_html( sprintf( /* translators: %d: number of units */ _n( '%d unidad', '%d unidades', $count, 'arriendo-facil' ), $count ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( $selected_building ) : ?>

			<div class="af-kpi-grid af-buildings-kpis">
				<article class="af-kpi af-kpi--info">
					<div class="af-kpi__head">
						<span class="af-kpi__label"><?php esc_html_e( 'Unidades', 'arriendo-facil' ); ?></span>
						<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( 'layout-grid', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					</div>
					<div class="af-kpi__value"><?php echo esc_html( count( $units ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Activas en el edificio', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi af-kpi--accent">
					<div class="af-kpi__head">
						<span class="af-kpi__label"><?php esc_html_e( 'Arrendadas', 'arriendo-facil' ); ?></span>
						<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( 'bed', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					</div>
					<div class="af-kpi__value"><?php echo esc_html( $active_lease_count ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Con contrato activo', 'arriendo-facil' ); ?></div>
				</article>

				<article class="af-kpi <?php echo $pending_total_period > 0.01 ? 'af-kpi--attention' : 'af-kpi--success'; ?>">
					<div class="af-kpi__head">
						<span class="af-kpi__label"><?php esc_html_e( 'Por cobrar este mes', 'arriendo-facil' ); ?></span>
						<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( 'wallet', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					</div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $pending_total_period, 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php echo esc_html( $current_period ); ?></div>
				</article>

				<article class="af-kpi af-kpi--info">
					<div class="af-kpi__head">
						<span class="af-kpi__label"><?php esc_html_e( 'Alícuota del edificio', 'arriendo-facil' ); ?></span>
						<span class="af-kpi__icon" aria-hidden="true"><?php echo af_lucide( 'receipt', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					</div>
					<div class="af-kpi__value">$<?php echo esc_html( number_format_i18n( $alicuota_total, 2 ) ); ?></div>
					<div class="af-kpi__hint"><?php esc_html_e( 'Gastos comunes por mes', 'arriendo-facil' ); ?></div>
				</article>
			</div>

			<section class="af-section" style="margin-bottom: var(--af-space-5);">
				<header class="af-section__header">
					<div class="af-section__head">
						<h2 class="af-section__title"><?php esc_html_e( 'Qué cobrar', 'arriendo-facil' ); ?></h2>
						<p class="af-section__subtitle"><?php echo esc_html( sprintf( /* translators: 1: building name, 2: period */ __( '%1$s · periodo %2$s', 'arriendo-facil' ), $selected_building->name, $current_period ) ); ?></p>
					</div>
					<span class="af-pill af-pill--info"><?php echo esc_html( sprintf( /* translators: %d: number of units */ _n( '%d unidad', '%d unidades', count( $units ), 'arriendo-facil' ), count( $units ) ) ); ?></span>
				</header>

				<table class="wp-list-table widefat fixed striped af-data-table af-units-table af-units-cobranza">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Unidad', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Quién paga', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Canon', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Alícuota', 'arriendo-facil' ); ?></th>
							<th><?php esc_html_e( 'Por cobrar', 'arriendo-facil' ); ?></th>
							<th class="af-actions-col"><?php esc_html_e( 'Acciones', 'arriendo-facil' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $units ) ) : ?>
							<tr>
								<td colspan="7" class="no-items-cell">
									<?php esc_html_e( 'Este edificio aún no tiene unidades. Añade la primera en "Administrar la estructura" más abajo.', 'arriendo-facil' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php
							foreach ( $units as $unit ) :
								$meta          = $units_meta[ (int) $unit->id ];
								$lease         = $meta['lease'];
								$linked_title  = $unit->accommodation_id ? get_the_title( (int) $unit->accommodation_id ) : '';
								$canon         = $lease ? (float) $lease->monthly_rent : 0.0;
								$tenant_name   = $lease ? trim( (string) $lease->guest_name ) : '';
								$pending_text  = '$' . number_format_i18n( $meta['pending'], 2 );
								?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Unidad', 'arriendo-facil' ); ?>">
										<span class="af-unit-code">
											<?php echo af_lucide( 'home', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
											<?php echo esc_html( $unit->unit_code ); ?>
										</span>
									</td>
									<td data-label="<?php esc_attr_e( 'Quién paga', 'arriendo-facil' ); ?>">
										<span class="af-unit-linked">
											<?php if ( $lease ) : ?>
												<strong><?php echo esc_html( $tenant_name ? $tenant_name : __( 'Inquilino', 'arriendo-facil' ) ); ?></strong>
											<?php else : ?>
												<?php echo esc_html__( 'Sin contrato activo', 'arriendo-facil' ); ?>
											<?php endif; ?>
										</span>
										<small class="af-unit-fine"><?php echo esc_html( $linked_title ? $linked_title : ( $unit->accommodation_id ? __( 'Propiedad vinculada', 'arriendo-facil' ) : __( 'Sin propiedad vinculada', 'arriendo-facil' ) ) ); ?></small>
									</td>
									<td data-label="<?php esc_attr_e( 'Estado', 'arriendo-facil' ); ?>">
										<?php
										switch ( $meta['badge'] ) {
											case 'aldia':
												echo af_pill( 'active', __( 'Al día', 'arriendo-facil' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup.
												break;
											case 'pending':
												echo af_pill( 'pending', __( 'Por cobrar', 'arriendo-facil' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup.
												break;
											case 'available':
												echo af_pill( 'available', __( 'Disponible', 'arriendo-facil' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup.
												break;
											default:
												echo af_pill( 'inactive', __( 'Sin vínculo', 'arriendo-facil' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup.
												break;
										}
										?>
									</td>
									<td data-label="<?php esc_attr_e( 'Canon', 'arriendo-facil' ); ?>">
										<?php if ( $canon > 0 ) : ?>
											<span class="af-unit-canon">$<?php echo esc_html( number_format_i18n( $canon, 2 ) ); ?></span>
										<?php else : ?>
											<span class="af-muted">—</span>
										<?php endif; ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Alícuota', 'arriendo-facil' ); ?>">
										<span class="af-unit-hoa">$<?php echo esc_html( number_format_i18n( $meta['hoa'], 2 ) ); ?></span>
									</td>
									<td data-label="<?php esc_attr_e( 'Por cobrar', 'arriendo-facil' ); ?>">
										<?php if ( ! $lease ) : ?>
											<span class="af-muted"><?php esc_html_e( '—', 'arriendo-facil' ); ?></span>
										<?php elseif ( $meta['pending'] > 0.01 ) : ?>
											<span class="af-unit-pending"><?php echo esc_html( $pending_text ); ?></span>
										<?php else : ?>
											<span class="af-unit-paid">$0,00</span>
										<?php endif; ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Acciones', 'arriendo-facil' ); ?>">
										<span class="af-unit-actions">
											<?php if ( $lease ) : ?>
												<a class="af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-collections' ) ); ?>"><?php esc_html_e( 'Pagos', 'arriendo-facil' ); ?></a>
												<a class="af-btn af-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>"><?php esc_html_e( 'Contrato', 'arriendo-facil' ); ?></a>
											<?php elseif ( $unit->accommodation_id ) : ?>
										<?php $edit_link = get_edit_post_link( (int) $unit->accommodation_id ); ?>
										<?php if ( $edit_link ) : ?>
											<a class="af-btn af-btn--ghost" href="<?php echo esc_url( $edit_link ); ?>"><?php esc_html_e( 'Propiedad', 'arriendo-facil' ); ?></a>
										<?php endif; ?>
									<?php endif; ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>

			<details class="af-collapse af-section" style="margin-bottom: var(--af-space-5);" open>
				<summary class="af-collapse__summary">
					<span class="af-section__icon af-section__icon--slate" aria-hidden="true"><?php echo af_lucide( 'settings', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<span class="af-collapse__label"><?php esc_html_e( 'Administrar la estructura del edificio', 'arriendo-facil' ); ?></span>
					<span class="af-collapse__chevron" aria-hidden="true"><?php echo af_lucide( 'chevron-down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				</summary>
				<div class="af-collapse__body">

					<section class="af-section af-coefficient-card" style="margin-bottom: var(--af-space-5);">
						<header class="af-section__header">
							<div class="af-section__head">
								<h2 class="af-section__title"><?php esc_html_e( 'Suma de porcentajes', 'arriendo-facil' ); ?></h2>
								<p class="af-section__subtitle">
									<?php
									echo esc_html(
										abs( $coefficient_total - 100 ) > 0.01
											? __( 'Todas deben sumar 100% para repartir bien los gastos', 'arriendo-facil' )
											: __( 'Reparto correcto', 'arriendo-facil' )
									);
									?>
								</p>
							</div>
							<span class="af-coefficient-value <?php echo abs( $coefficient_total - 100 ) > 0.01 ? 'is-warning' : 'is-ok'; ?>"><?php echo esc_html( number_format_i18n( $coefficient_total, 2 ) ); ?>%</span>
						</header>

						<section class="af-section" style="margin-bottom: var(--af-space-5);">
						<header class="af-section__header">
							<div class="af-section__head">
								<h2 class="af-section__title"><?php esc_html_e( 'Agregar unidad', 'arriendo-facil' ); ?></h2>
								<p class="af-section__subtitle"><?php esc_html_e( 'Una unidad es un departamento, local u oficina dentro del edificio. Dile cómo se identifica (por ejemplo A-101) y qué parte de los gastos comunes le corresponde.', 'arriendo-facil' ); ?></p>
							</div>
						</header>
						<p class="af-modal__status" id="af-unit-status"></p>
						<form id="af-unit-form" class="af-buildings-form">
							<input type="hidden" name="building_id" value="<?php echo esc_attr( $selected_id ); ?>" />
							<div class="af-form-field">
								<label class="af-form-field__label" for="af-unit-code"><?php esc_html_e( 'Código', 'arriendo-facil' ); ?> <span class="af-required" aria-hidden="true">*</span></label>
								<input type="text" id="af-unit-code" name="unit_code" required placeholder="A-101" />
							</div>
							<div class="af-form-field">
								<label class="af-form-field__label" for="af-unit-coef"><?php esc_html_e( 'Porción de los gastos comunes (%)', 'arriendo-facil' ); ?></label>
								<input type="number" id="af-unit-coef" name="hoa_coefficient" step="0.0001" min="0" max="100" value="0" />
								<p class="af-form-field__hint"><?php esc_html_e( 'Cuánto le toca de los gastos comunes a esta unidad. Entre todas las unidades debe sumar 100%.', 'arriendo-facil' ); ?></p>
							</div>
							<div class="af-form-field">
								<label class="af-form-field__label" for="af-unit-area"><?php esc_html_e( 'Área (m²)', 'arriendo-facil' ); ?></label>
								<input type="number" id="af-unit-area" name="area_m2" step="0.01" min="0" value="0" />
							</div>
							<div class="af-form-field">
								<label class="af-form-field__label" for="af-unit-accommodation"><?php esc_html_e( 'Vincular a propiedad', 'arriendo-facil' ); ?></label>
								<select id="af-unit-accommodation" name="accommodation_id">
									<option value="0"><?php esc_html_e( '— Ninguna —', 'arriendo-facil' ); ?></option>
									<?php foreach ( $accommodations as $accommodation ) : ?>
										<option value="<?php echo esc_attr( (int) $accommodation->ID ); ?>"><?php echo esc_html( $accommodation->post_title ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="af-form-field__hint"><?php esc_html_e( 'Si este departamento ya está registrado en el Catálogo de propiedades, elígela aquí para que los dos queden unidos.', 'arriendo-facil' ); ?></p>
							</div>
							<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Agregar unidad', 'arriendo-facil' ); ?></button>
						</form>
					</section>

					<section class="af-section">
						<header class="af-section__header">
							<div class="af-section__head">
								<h2 class="af-section__title"><?php esc_html_e( 'Unidades del edificio', 'arriendo-facil' ); ?></h2>
								<p class="af-section__subtitle"><?php echo esc_html( $selected_building->name ); ?></p>
							</div>
							<span class="af-pill af-pill--info"><?php echo esc_html( sprintf( /* translators: %d: number of units */ _n( '%d unidad', '%d unidades', count( $units ), 'arriendo-facil' ), count( $units ) ) ); ?></span>
						</header>

						<table class="wp-list-table widefat fixed striped af-data-table af-units-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Unidad', 'arriendo-facil' ); ?></th>
									<th><?php esc_html_e( 'Propiedad vinculada', 'arriendo-facil' ); ?></th>
									<th><?php esc_html_e( 'Gastos comunes %', 'arriendo-facil' ); ?></th>
									<th><?php esc_html_e( 'Área', 'arriendo-facil' ); ?></th>
									<th><?php esc_html_e( 'Le toca por mes', 'arriendo-facil' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $units ) ) : ?>
									<tr>
										<td colspan="5" class="no-items-cell">
											<?php esc_html_e( 'Este edificio aún no tiene unidades. Añade la primera con el formulario de arriba.', 'arriendo-facil' ); ?>
										</td>
									</tr>
								<?php else : ?>
									<?php
									$coef_sum = 0.0;
									$area_sum = 0.0;
									$hoa_sum  = 0.0;
									foreach ( $units as $unit ) :
										$linked_title = $unit->accommodation_id ? get_the_title( (int) $unit->accommodation_id ) : '';
										$unit_hoa     = $units_meta[ (int) $unit->id ]['hoa'];
										$unit_coef    = (float) $unit->hoa_coefficient;
										$coef_sum    += $unit_coef;
										$area_sum    += (float) $unit->area_m2;
										$hoa_sum     += $unit_hoa;
										?>
									<tr>
										<td data-label="<?php esc_attr_e( 'Unidad', 'arriendo-facil' ); ?>">
											<span class="af-unit-code">
												<?php echo af_lucide( 'home', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
												<?php echo esc_html( $unit->unit_code ); ?>
											</span>
										</td>
										<td data-label="<?php esc_attr_e( 'Propiedad vinculada', 'arriendo-facil' ); ?>">
											<span class="af-unit-linked">
												<?php echo $linked_title ? esc_html( $linked_title ) : esc_html__( 'Sin vincular', 'arriendo-facil' ); ?>
											</span>
											<?php if ( $linked_title ) : ?>
												<?php echo af_pill( 'active', __( 'Vinculada', 'arriendo-facil' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup. ?>
											<?php endif; ?>
										</td>
										<td data-label="<?php esc_attr_e( 'Gastos comunes %', 'arriendo-facil' ); ?>">
											<span class="af-coef <?php echo $unit_coef > 100 ? 'is-over' : ''; ?>">
												<strong><?php echo esc_html( number_format_i18n( $unit_coef, 4 ) ); ?>%</strong>
												<span class="af-coef__bar" aria-hidden="true"><span style="width: <?php echo esc_attr( max( 0, min( 100, $unit_coef ) ) ); ?>%;"></span></span>
											</span>
										</td>
										<td data-label="<?php esc_attr_e( 'Área', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( (float) $unit->area_m2, 2 ) ); ?> m²</td>
										<td data-label="<?php esc_attr_e( 'Le toca por mes', 'arriendo-facil' ); ?>"><span class="af-unit-hoa">$<?php echo esc_html( number_format_i18n( $unit_hoa, 2 ) ); ?></span></td>
									</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
							<?php if ( ! empty( $units ) ) : ?>
								<tfoot>
									<tr>
										<td colspan="2"><span class="af-totals-label"><?php esc_html_e( 'Totales', 'arriendo-facil' ); ?></span></td>
										<td data-label="<?php esc_attr_e( 'Gastos comunes %', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( $coef_sum, 4 ) ); ?>%</td>
										<td data-label="<?php esc_attr_e( 'Área', 'arriendo-facil' ); ?>"><?php echo esc_html( number_format_i18n( $area_sum, 2 ) ); ?> m²</td>
										<td data-label="<?php esc_attr_e( 'Le toca por mes', 'arriendo-facil' ); ?>">$<?php echo esc_html( number_format_i18n( $hoa_sum, 2 ) ); ?></td>
									</tr>
								</tfoot>
							<?php endif; ?>
						</table>
					</section>

					</section>

				</div>
			</details>

			<details class="af-collapse af-section">
				<summary class="af-collapse__summary">
					<span class="af-section__icon af-section__icon--slate" aria-hidden="true"><?php echo af_lucide( 'building-2', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<span class="af-collapse__label"><?php esc_html_e( 'Registrar otro edificio', 'arriendo-facil' ); ?></span>
					<span class="af-collapse__chevron" aria-hidden="true"><?php echo af_lucide( 'chevron-down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				</summary>
				<div class="af-collapse__body">
					<header class="af-section__header">
						<div class="af-section__head">
							<h2 class="af-section__title"><?php esc_html_e( 'Nuevo edificio', 'arriendo-facil' ); ?></h2>
							<p class="af-section__subtitle"><?php esc_html_e( '¿Tienes otro edificio? Regístralo de la misma manera: datos, gastos comunes y después sus unidades.', 'arriendo-facil' ); ?></p>
						</div>
					</header>
					<p class="af-modal__status" id="af-building-status"></p>
					<form id="af-building-form" class="af-buildings-form">
						<div class="af-form-field">
							<label class="af-form-field__label" for="af-building-name"><?php esc_html_e( 'Nombre', 'arriendo-facil' ); ?> <span class="af-required" aria-hidden="true">*</span></label>
							<input type="text" id="af-building-name" name="name" required class="regular-text" />
						</div>
						<div class="af-form-field">
							<label class="af-form-field__label" for="af-building-address"><?php esc_html_e( 'Dirección', 'arriendo-facil' ); ?></label>
							<input type="text" id="af-building-address" name="address" class="regular-text" />
						</div>
						<div class="af-form-field">
							<label class="af-form-field__label" for="af-building-city"><?php esc_html_e( 'Ciudad', 'arriendo-facil' ); ?></label>
							<input type="text" id="af-building-city" name="city" class="regular-text" />
						</div>
						<div class="af-form-field">
							<label class="af-form-field__label" for="af-building-hoa"><?php esc_html_e( 'Gastos comunes mensuales (USD)', 'arriendo-facil' ); ?></label>
							<input type="number" id="af-building-hoa" name="monthly_hoa_total" step="0.01" min="0" value="0.00" />
							<p class="af-form-field__hint"><?php esc_html_e( 'Cuánto cuesta al mes mantener el edificio entre todos: mantenimiento, guardianía, agua del edificio, etc. El sistema lo reparte entre las unidades.', 'arriendo-facil' ); ?></p>
						</div>
						<?php if ( $is_super_admin && ! empty( $property_admins ) ) : ?>
						<div class="af-form-field">
							<label class="af-form-field__label" for="af-building-admin"><?php esc_html_e( 'Administrador de propiedades', 'arriendo-facil' ); ?></label>
							<select id="af-building-admin" name="assigned_admin_id">
								<option value=""><?php esc_html_e( '— Asignar a mí mismo —', 'arriendo-facil' ); ?></option>
								<?php foreach ( $property_admins as $pa ) : ?>
									<option value="<?php echo esc_attr( $pa->ID ); ?>"><?php echo esc_html( get_user_meta( $pa->ID, 'af_company_name', true ) ? get_user_meta( $pa->ID, 'af_company_name', true ) : $pa->display_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<?php endif; ?>
						<button type="submit" class="button af-btn af-btn--primary"><?php esc_html_e( 'Crear edificio', 'arriendo-facil' ); ?></button>
					</form>
				</div>
			</details>

		<?php endif; ?>

	<?php endif; ?>
			</section>

		</div><!-- .af-tabs__panels -->
	</div><!-- .af-tabs .af-buildings-tabs -->
</div>

<!-- Modal: cobranza del inmueble (calendario de cobros del mes) -->
<div class="af-modal" id="af-cobranza-modal" role="dialog" aria-modal="true" aria-labelledby="af-cobranza-modal-title">
	<div class="af-modal__backdrop" data-af-modal-close></div>
	<div class="af-modal__dialog af-cobranza-modal__dialog">
		<button type="button" class="af-modal__close" data-af-modal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-cobranza-modal-title"></h2>
			<p class="af-modal__subtitle" id="af-cobranza-modal-subtitle"></p>
		</div>
		<div class="af-modal__body">
			<p class="af-modal__status" id="af-cobranza-status" style="display:none;"></p>

			<div class="af-cobranza-cal__nav">
				<button type="button" class="button af-cobranza-cal__nav-btn" data-cal="prev" aria-label="<?php esc_attr_e( 'Mes anterior', 'arriendo-facil' ); ?>">&larr;</button>
				<h3 class="af-cobranza-cal__period" id="af-cobranza-period"></h3>
				<button type="button" class="button af-cobranza-cal__nav-btn" data-cal="next" aria-label="<?php esc_attr_e( 'Mes siguiente', 'arriendo-facil' ); ?>">&rarr;</button>
			</div>
			<div class="af-cobranza-cal__today-row">
				<button type="button" class="button af-cobranza-cal__today" data-cal="today"><?php esc_html_e( 'Hoy', 'arriendo-facil' ); ?></button>
			</div>

			<div class="af-calendar-mini" id="af-cobranza-calendar" aria-label="<?php esc_attr_e( 'Calendario de cobros', 'arriendo-facil' ); ?>"></div>

			<div class="af-calendar-mini__legend" id="af-cobranza-legend"></div>

			<div class="af-cobranza-summary" id="af-cobranza-summary" hidden>
				<div class="af-cobranza-summary__row">
					<span class="af-cobranza-summary__item">
						<span class="af-cobranza-summary__k"><?php esc_html_e( 'Facturado', 'arriendo-facil' ); ?></span>
						<span class="af-cobranza-summary__v" data-sum="charged">$0,00</span>
					</span>
					<span class="af-cobranza-summary__item">
						<span class="af-cobranza-summary__k"><?php esc_html_e( 'Pagado', 'arriendo-facil' ); ?></span>
						<span class="af-cobranza-summary__v is-ok" data-sum="paid">$0,00</span>
					</span>
					<span class="af-cobranza-summary__item">
						<span class="af-cobranza-summary__k"><?php esc_html_e( 'Pendiente', 'arriendo-facil' ); ?></span>
						<span class="af-cobranza-summary__v is-danger" data-sum="pending">$0,00</span>
					</span>
				</div>
				<div class="af-cobranza-summary__bar" role="img" aria-label="<?php esc_attr_e( 'Progreso de cobranza del mes', 'arriendo-facil' ); ?>">
					<span class="af-cobranza-summary__fill" data-sum="fill" style="width:0%"></span>
				</div>
			</div>

			<div class="af-cobranza-dayfilter" id="af-cobranza-dayfilter" hidden></div>

			<h4 class="af-cobranza-charges__title"><?php esc_html_e( 'Cargos del mes', 'arriendo-facil' ); ?></h4>
			<ul class="af-cobranza-charges" id="af-cobranza-charges"></ul>

			<div class="af-cobranza-pay" id="af-cobranza-pay" hidden>
				<h4 class="af-cobranza-pay__title"><?php esc_html_e( 'Anotar pago', 'arriendo-facil' ); ?></h4>
				<p class="af-modal__status" id="af-cobranza-pay-status" style="display:none;"></p>
				<input type="hidden" id="af-cobranza-pay-charge-id" />
				<div class="af-modal__field">
					<label for="af-cobranza-pay-amount"><?php esc_html_e( 'Monto recibido (USD)', 'arriendo-facil' ); ?></label>
					<input type="number" id="af-cobranza-pay-amount" step="0.01" min="0.01" />
					<p class="af-modal__hint" id="af-cobranza-pay-outstanding"></p>
				</div>
				<div class="af-modal__field">
					<label for="af-cobranza-pay-date"><?php esc_html_e( 'Fecha de pago', 'arriendo-facil' ); ?></label>
					<input type="date" id="af-cobranza-pay-date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
				</div>
				<div class="af-modal__field">
					<label for="af-cobranza-pay-method"><?php esc_html_e( 'Método', 'arriendo-facil' ); ?></label>
					<select id="af-cobranza-pay-method">
						<?php foreach ( Arriendo_Facil_Billing_Ledger::payment_methods() as $method_key => $method_label ) : ?>
							<option value="<?php echo esc_attr( $method_key ); ?>"><?php echo esc_html( $method_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="af-modal__field">
					<label for="af-cobranza-pay-reference"><?php esc_html_e( 'Referencia / comprobante', 'arriendo-facil' ); ?></label>
					<input type="text" id="af-cobranza-pay-reference" placeholder="<?php esc_attr_e( 'Ej: transferencia #01234', 'arriendo-facil' ); ?>" />
				</div>
				<div class="af-cobranza-pay__actions">
					<button type="button" class="button" id="af-cobranza-pay-cancel"><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button type="button" class="button button-primary" id="af-cobranza-pay-confirm"><?php esc_html_e( 'Registrar pago', 'arriendo-facil' ); ?></button>
				</div>
			</div>
		</div>
		<div class="af-modal__footer">
			<button type="button" class="button" data-af-modal-close><?php esc_html_e( 'Cerrar', 'arriendo-facil' ); ?></button>
		</div>
	</div>
	<div class="af-cobranza-tip" id="af-cobranza-tip" role="tooltip" hidden></div>
</div>

<script>
	window.afBuildingsCobranza = <?php echo wp_json_encode( $cob_json_props ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Datos JSON para el JS del calendario. ?>;
	window.afBuildingsCobranzaToday = <?php echo wp_json_encode( $cob_today ); ?>;
</script>

<script>
(function () {
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_structure_nonce' ) ); ?>;

	function submitForm(form, action) {
		const body = new URLSearchParams(new FormData(form));
		body.append('action', action);
		body.append('nonce', nonce);

		return fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then((r) => r.json());
	}

	function bind(formId, action, statusId) {
		const form = document.getElementById(formId);
		const status = document.getElementById(statusId);
		if (!form || !status) { return; }

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			const btn = form.querySelector('button[type="submit"]');
			btn.disabled = true;
			status.textContent = '';
			status.className = 'af-modal__status';

			submitForm(form, action).then(function (json) {
				btn.disabled = false;
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
	}

	bind('af-building-form', 'af_create_building', 'af-building-status');
	bind('af-unit-form', 'af_create_unit', 'af-unit-status');
}());
</script>

<script>
(function () {
	const btn = document.getElementById('af-buildings-module-toggle');
	if (!btn) { return; }
	const status = document.getElementById('af-buildings-module-status');
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const nonce = <?php echo wp_json_encode( wp_create_nonce( 'af_buildings_module_nonce' ) ); ?>;

	btn.addEventListener('click', function () {
		btn.disabled = true;
		const body = new URLSearchParams();
		body.append('action', 'af_toggle_buildings_module');
		body.append('nonce', nonce);
		body.append('enabled', btn.getAttribute('data-enable') === '1' ? '1' : '0');
		fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then(function (r) { return r.json(); }).then(function (json) {
			if (status) {
				status.textContent = (json && json.success && json.data && json.data.message) ? json.data.message : 'Error';
			}
			window.location.reload();
		}).catch(function () {
			btn.disabled = false;
			if (status) { status.textContent = 'Error'; }
		});
	});
}());
</script>