<?php
/**
 * Buildings and units admin page view.
 *
 * Billing hub: every unit is a tenant to charge (canon + alicuota + services).
 * Each property is a card with its pending amount and due date; clicking the
 * card opens a modal with the month's charge calendar and lets you register a
 * payment inline.
 *
 * The script and the data payload are inlined at the end of this file on
 * purpose: this view is deployed as a single file, so the modal works without
 * depending on a separately enqueued asset.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$current_period = current_time( 'Y-m' );

// ---------------------------------------------------------------------
// Cobranza: snapshot por inmueble (qué cobrar y cuándo).
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

	$unpaid      = array();
	$cur_charges = array();
	$cur_pending = 0.0;
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
		'thumb'           => $meta && $meta->thumbnail_id ? wp_get_attachment_image_url( (int) $meta->thumbnail_id, 'medium_large' ) : get_the_post_thumbnail_url( $acc_id, 'medium_large' ),
		'unit_code'       => $unit ? (string) $unit->unit_code : '',
		'building_name'   => $unit ? (string) $unit->building_name : '',
		'guest'           => $has_ls ? trim( (string) $lease->guest_name ) : '',
		'monthly_rent'    => $has_ls ? (float) $lease->monthly_rent : ( $meta && $meta->monthly_rent ? (float) $meta->monthly_rent : 0.0 ),
		'payment_due_day' => $pay_day,
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
uasort(
	$cob_props,
	static function ( $a, $b ) use ( $cob_statuses_order ) {
		$sa = isset( $cob_statuses_order[ $a['status'] ] ) ? $cob_statuses_order[ $a['status'] ] : 9;
		$sb = isset( $cob_statuses_order[ $b['status'] ] ) ? $cob_statuses_order[ $b['status'] ] : 9;
		if ( $sa !== $sb ) {
			return $sa <=> $sb;
		}
		$da = $a['next_due'] ? strtotime( $a['next_due'] ) : PHP_INT_MAX;
		$db = $b['next_due'] ? strtotime( $b['next_due'] ) : PHP_INT_MAX;
		return $da <=> $db;
	}
);

$cob_summary = array(
	'vencido_count' => 0,
	'vencido_total' => 0.0,
	'proximo_count' => 0,
	'proximo_total' => 0.0,
	'pending_month' => 0.0,
	'aldia_count'   => 0,
	'disponibles'   => 0,
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
			'subtitle' => __( 'Tus inmuebles a cobrar, por tarjeta: cuánto falta por cobrar y cuándo vence. Pulsa una tarjeta para abrir el calendario de cobros del mes.', 'arriendo-facil' ),
		)
	);
	?>

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
			<?php foreach ( $cob_props as $prop ) : ?>
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
					data-prop="<?php echo esc_attr( $prop['id'] ); ?>"
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
							<span class="af-catalog-card__hint-arrow" aria-hidden="true">&rarr;</span>
						</span>
					</div>
				</article>
			<?php endforeach; ?>
		</div>

		<?php if ( $cob_summary['disponibles'] > 0 ) : ?>
			<?php $disponibles = array_filter( $cob_props, static function ( $p ) { return 'available' === $p['status']; } ); ?>
			<details class="af-collapse af-section af-cobranza-avail">
				<summary class="af-collapse__summary">
					<span class="af-section__icon af-section__icon--slate" aria-hidden="true"><?php echo af_lucide( 'building', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					<span class="af-collapse__label"><?php echo esc_html( sprintf( /* translators: %d: number of properties */ _n( '%d inmueble disponible (sin contrato)', '%d inmuebles disponibles (sin contrato)', $cob_summary['disponibles'], 'arriendo-facil' ), $cob_summary['disponibles'] ) ); ?></span>
					<span class="af-collapse__chevron" aria-hidden="true"><?php echo af_lucide( 'chevron-down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
				</summary>
				<div class="af-collapse__body">
					<div class="af-property-grid">
						<?php foreach ( $disponibles as $prop ) : ?>
							<?php $csm = $cob_status_meta[ $prop['status'] ]; ?>
							<article class="af-property-card af-cobranza-card" data-prop="<?php echo esc_attr( $prop['id'] ); ?>" role="button" tabindex="0" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: property title */ __( 'Ver cobranza de %s', 'arriendo-facil' ), $prop['title'] ) ); ?>">
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
									<p class="af-catalog-card__address"><span class="af-catalog-card__address-text"><?php esc_html_e( 'Sin contrato activo: no genera cobros este mes.', 'arriendo-facil' ); ?></span></p>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				</div>
			</details>
		<?php endif; ?>
	<?php endif; ?>
</div><!-- .wrap -->

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
					<input type="date" id="af-cobranza-pay-date" value="<?php echo esc_attr( $cob_today ); ?>" />
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
</div>

<script>
/* Cobranza: modal con calendario de cobros del mes y anotación de pagos
   (af_record_payment). Va en línea en esta vista a propósito: la página se
   despliega como un único archivo, así el clic funciona sin depender de un
   asset encolado aparte. */
(function () {
	'use strict';

	var DATA   = <?php echo wp_json_encode( $cob_json_props ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Datos JSON para el JS del calendario. ?>;
	var TODAY  = <?php echo wp_json_encode( $cob_today ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fecha actual del sitio. ?>;
	var CONFIG = {
		ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL del AJAX de WordPress. ?>,
		nonce: <?php echo wp_json_encode( wp_create_nonce( 'af_ledger_nonce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Nonce del ledger. ?>
	};

	var LABELS = {
		paid: <?php echo wp_json_encode( __( 'Pagado', 'arriendo-facil' ) ); ?>,
		pending: <?php echo wp_json_encode( __( 'Pendiente', 'arriendo-facil' ) ); ?>,
		overdue: <?php echo wp_json_encode( __( 'Vencido', 'arriendo-facil' ) ); ?>,
		payday: <?php echo wp_json_encode( __( 'Día de pago', 'arriendo-facil' ) ); ?>,
		partial: <?php echo wp_json_encode( __( 'Parcial', 'arriendo-facil' ) ); ?>,
		noCharges: <?php echo wp_json_encode( __( 'Sin cargos para este periodo.', 'arriendo-facil' ) ); ?>,
		tenant: <?php echo wp_json_encode( __( 'Arrendatario', 'arriendo-facil' ) ); ?>,
		available: <?php echo wp_json_encode( __( 'Disponible', 'arriendo-facil' ) ); ?>,
		previous: <?php echo wp_json_encode( __( 'Mes anterior', 'arriendo-facil' ) ); ?>,
		next: <?php echo wp_json_encode( __( 'Mes siguiente', 'arriendo-facil' ) ); ?>,
		balance: <?php echo wp_json_encode( __( 'Saldo del cargo', 'arriendo-facil' ) ); ?>,
		outstanding: <?php echo wp_json_encode( __( 'puedes superarlo y el excedente quedará como saldo a favor.', 'arriendo-facil' ) ); ?>,
		pay: <?php echo wp_json_encode( __( 'Anotar pago', 'arriendo-facil' ) ); ?>,
		record: <?php echo wp_json_encode( __( 'Registrar pago', 'arriendo-facil' ) ); ?>,
		amount: <?php echo wp_json_encode( __( 'Monto recibido (USD)', 'arriendo-facil' ) ); ?>,
		paidOk: <?php echo wp_json_encode( __( 'Pago registrado correctamente.', 'arriendo-facil' ) ); ?>,
		amountErr: <?php echo wp_json_encode( __( 'Ingresa un monto mayor a cero.', 'arriendo-facil' ) ); ?>,
		genericErr: <?php echo wp_json_encode( __( 'Error al registrar el pago.', 'arriendo-facil' ) ); ?>,
		networkErr: <?php echo wp_json_encode( __( 'Error de red al registrar el pago.', 'arriendo-facil' ) ); ?>
	};

	var WEEKDAYS = <?php echo wp_json_encode( array( __( 'lun', 'arriendo-facil' ), __( 'mar', 'arriendo-facil' ), __( 'mié', 'arriendo-facil' ), __( 'jue', 'arriendo-facil' ), __( 'vie', 'arriendo-facil' ), __( 'sáb', 'arriendo-facil' ), __( 'dom', 'arriendo-facil' ) ) ); ?>;

	if (!DATA || !Object.keys(DATA).length) {
		return;
	}

	/* ── Helpers ─────────────────────────────────────────────────────────── */

	function pad(n) {
		return ('0' + n).slice(-2);
	}

	function periodString(d) {
		return d.getFullYear() + '-' + pad(d.getMonth() + 1);
	}

	function parsePeriod(p) {
		var parts = String(p).split('-');
		return { y: parseInt(parts[0], 10), m: parseInt(parts[1], 10) };
	}

	function periodDate(p) {
		var x = parsePeriod(p);
		return new Date(x.y, x.m - 1, 1);
	}

	function addMonths(p, delta) {
		var d = periodDate(p);
		d.setMonth(d.getMonth() + delta);
		return periodString(d);
	}

	var MONTH_FMT = new Intl.DateTimeFormat( <?php echo wp_json_encode( str_replace( '_', '-', determine_locale() ) ); ?>, { month: 'long', year: 'numeric' } );
	function monthLabel(p) {
		var s = MONTH_FMT.format(periodDate(p));
		return s.charAt(0).toUpperCase() + s.slice(1);
	}

	function monthMoney(n) {
		return Number(n || 0).toLocaleString( <?php echo wp_json_encode( str_replace( '_', '-', determine_locale() ) ); ?>, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function dateLabel(ymd) {
		if (!ymd) { return ''; }
		var parts = String(ymd).split('-');
		var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
		return d.toLocaleDateString( <?php echo wp_json_encode( str_replace( '_', '-', determine_locale() ) ); ?>, { day: 'numeric', month: 'short', year: 'numeric' } );
	}

	/* ── Iconos (estilo lucide) ───────────────────────────────────────────── */

	var ICONS = {
		canon: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>',
		alicuota: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17V7"/></svg>',
		agua: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>',
		luz: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>',
		gas: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
		internet: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><path d="M12 20h.01"/></svg>',
		multa: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
		otro: '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12h8M12 8v8"/></svg>'
	};

	function chargeIcon(type) {
		return ICONS[type] || ICONS.otro;
	}

	/* ── Estado DOM ──────────────────────────────────────────────────────── */

	var modal = document.getElementById('af-cobranza-modal');
	if (!modal) { return; }

	var periodLabel  = document.getElementById('af-cobranza-period');
	var calendarEl   = document.getElementById('af-cobranza-calendar');
	var legendEl     = document.getElementById('af-cobranza-legend');
	var chargesEl    = document.getElementById('af-cobranza-charges');
	var titleEl      = document.getElementById('af-cobranza-modal-title');
	var subtitleEl   = document.getElementById('af-cobranza-modal-subtitle');
	var statusEl     = document.getElementById('af-cobranza-status');
	var payPanel     = document.getElementById('af-cobranza-pay');
	var payChargeId  = document.getElementById('af-cobranza-pay-charge-id');
	var payAmount    = document.getElementById('af-cobranza-pay-amount');
	var payDate      = document.getElementById('af-cobranza-pay-date');
	var payMethod    = document.getElementById('af-cobranza-pay-method');
	var payReference = document.getElementById('af-cobranza-pay-reference');
	var payOutstand  = document.getElementById('af-cobranza-pay-outstanding');
	var payStatus    = document.getElementById('af-cobranza-pay-status');
	var payCancel    = document.getElementById('af-cobranza-pay-cancel');
	var payConfirm   = document.getElementById('af-cobranza-pay-confirm');

	var state = { prop: null, period: '' };

	function openModal() {
		modal.classList.add('is-open');
	}

	function closeModal() {
		modal.classList.remove('is-open');
		payPanel.hidden = true;
		hideStatus();
	}

	function showStatus(message, type) {
		if (!statusEl) { return; }
		statusEl.style.display = '';
		statusEl.textContent = message;
		statusEl.className = 'af-modal__status is-' + (type || 'success');
	}

	function hideStatus() {
		if (!statusEl) { return; }
		statusEl.textContent = '';
		statusEl.style.display = 'none';
		statusEl.className = 'af-modal__status';
	}

	modal.querySelectorAll('[data-af-modal-close]').forEach(function (btn) {
		btn.addEventListener('click', closeModal);
	});
	document.addEventListener('keydown', function (e) {
		if ('Escape' === e.key && modal.classList.contains('is-open')) {
			closeModal();
		}
	});

	/* ── Calendario ───────────────────────────────────────────────────────── */

	function dotClassFor(charge) {
		if ('paid' === charge.status) { return 'is-paid'; }
		if ('overdue' === charge.status) { return 'is-overdue'; }
		var due = charge.dueDate || '';
		if (due && TODAY && due < TODAY) { return 'is-overdue'; }
		return 'is-pending';
	}

	function renderCalendar() {
		var p = state.period;
		var x = parsePeriod(p);
		var first = new Date(x.y, x.m - 1, 1);
		var daysInMonth = new Date(x.y, x.m, 0).getDate();
		var startWeekday = (first.getDay() + 6) % 7; // lunes = 0

		var charges = (state.prop.charges && state.prop.charges[p]) || [];
		var byDay = {};
		charges.forEach(function (c) {
			var d = String(c.dueDate || '').slice(-2).replace(/^0/, '');
			if (!byDay[d]) { byDay[d] = []; }
			byDay[d].push(c);
		});

		var todayKey = String(TODAY).slice(0, 10);
		var weekDaysHtml = WEEKDAYS.map(function (w) {
			return '<span class="af-calendar-mini__wd">' + w + '</span>';
		}).join('');

		var cells = '';
		for (var i = 0; i < startWeekday; i++) {
			cells += '<div class="af-calendar-mini__day is-outside"></div>';
		}
		for (var day = 1; day <= daysInMonth; day++) {
			var dayStr = x.y + '-' + pad(x.m) + '-' + pad(day);
			var cls = 'af-calendar-mini__day';
			if (dayStr === todayKey) { cls += ' is-today'; }

			var dots = '';
			var dayCharges = byDay[String(day)] || [];
			dayCharges.forEach(function (c) {
				dots += '<i class="af-calendar-mini__day-dot ' + dotClassFor(c) + '"></i>';
			});
			if (state.prop.has_lease && state.prop.payment_due_day === day) {
				dots = '<i class="af-calendar-mini__day-dot is-payday"></i>' + dots;
			}

			cells += '<div class="' + cls + '" data-day="' + dayStr + '">' +
				'<span class="af-calendar-mini__day-num">' + day + '</span>' +
				'<span class="af-calendar-mini__dots">' + dots + '</span>' +
				'</div>';
		}
		var trailing = (7 - ((startWeekday + daysInMonth) % 7)) % 7;
		for (var t = 0; t < trailing; t++) {
			cells += '<div class="af-calendar-mini__day is-outside"></div>';
		}

		periodLabel.textContent = monthLabel(p);
		calendarEl.innerHTML = weekDaysHtml + cells;
		legendEl.innerHTML =
			'<span><i class="is-paid"></i> ' + LABELS.paid + '</span>' +
			'<span><i class="is-pending"></i> ' + LABELS.pending + '</span>' +
			'<span><i class="is-overdue"></i> ' + LABELS.overdue + '</span>' +
			(state.prop.has_lease ? '<span><i class="is-payday"></i> ' + LABELS.payday + '</span>' : '');
	}

	/* ── Lista de cargos del mes ─────────────────────────────────────────── */

	function balanceOf(c) {
		return (Number(c.amount) || 0) - (Number(c.paid) || 0);
	}

	function chargeStatusLabel(c) {
		if ('paid' === c.status) { return LABELS.paid; }
		if ('overdue' === c.status) { return LABELS.overdue; }
		var bal = balanceOf(c);
		if ('partial' === c.status && bal > 0.01) { return LABELS.partial; }
		return LABELS.pending;
	}

	function renderCharges() {
		var p = state.period;
		var charges = (state.prop.charges && state.prop.charges[p]) || [];

		chargesEl.innerHTML = '';
		if (!charges.length) {
			var empty = document.createElement('li');
			empty.className = 'af-cobranza-empty';
			empty.textContent = LABELS.noCharges;
			chargesEl.appendChild(empty);
			payPanel.hidden = true;
			return;
		}

		charges.forEach(function (c) {
			var paid = 'paid' === c.status;
			var bal = balanceOf(c);
			var li = document.createElement('li');
			li.className = 'af-cobranza-charge ' + (paid ? 'is-paid' : '');

			li.innerHTML =
				'<span class="af-cobranza-charge__icon">' + chargeIcon(c.type) + '</span>' +
				'<div class="af-cobranza-charge__body">' +
				'<span class="af-cobranza-charge__label"></span>' +
				'<span class="af-cobranza-charge__meta"></span>' +
				'</div>' +
				'<div class="af-cobranza-charge__amount">' +
				'<span class="af-cobranza-charge__value">$' + monthMoney(c.amount) + '</span>' +
				'<span class="af-cobranza-charge__balance"></span>' +
				'</div>' +
				'<div class="af-cobranza-charge__actions">' +
				(paid || bal <= 0.01 ? '' : '<button type="button" class="button af-btn af-cobranza-pay-open" data-charge="' + c.id + '" data-outstanding="' + bal.toFixed(2) + '">' + LABELS.pay + '</button>') +
				'</div>';

			li.querySelector('.af-cobranza-charge__label').textContent = c.label + (c.desc ? ' · ' + c.desc : '');
			li.querySelector('.af-cobranza-charge__meta').textContent =
				(c.dueDate ? dateLabel(c.dueDate) + ' · ' : '') + chargeStatusLabel(c);
			li.querySelector('.af-cobranza-charge__balance').textContent =
				paid ? LABELS.paid : (LABELS.balance + ' $' + monthMoney(bal));

			chargesEl.appendChild(li);
		});
	}

	function render() {
		renderCalendar();
		renderCharges();
	}

	/* ── Abrir modal desde tarjeta ───────────────────────────────────────── */

	function openForProp(prop) {
		state.prop = prop;
		state.period = periodString(new Date());

		// Si el mes actual no tiene cargos, abre el primer mes con cargos.
		if (!prop.charges || !prop.charges[state.period]) {
			var keys = prop.charges ? Object.keys(prop.charges).sort() : [];
			if (keys.length) {
				state.period = keys[0];
			}
		}

		titleEl.textContent = prop.title;
		var sub = [];
		if (prop.unit_code) { sub.push(prop.unit_code + (prop.building_name ? ' · ' + prop.building_name : '')); }
		if (prop.guest) { sub.push(LABELS.tenant + ': ' + prop.guest); }
		else if (!prop.has_lease) { sub.push(LABELS.available); }
		subtitleEl.textContent = sub.join(' — ');

		payPanel.hidden = true;
		hideStatus();
		render();
		openModal();
	}

	document.addEventListener('click', function (e) {
		var card = e.target.closest ? e.target.closest('.af-cobranza-card[data-prop]') : null;
		if (!card) { return; }
		var prop = DATA[card.getAttribute('data-prop')];
		if (prop) { openForProp(prop); }
	});

	document.addEventListener('keydown', function (e) {
		if ('Enter' !== e.key && ' ' !== e.key) { return; }
		var card = e.target.closest ? e.target.closest('.af-cobranza-card[data-prop]') : null;
		if (!card) { return; }
		e.preventDefault();
		card.click();
	});

	/* ── Navegación del calendario ───────────────────────────────────────── */

	var todayPeriod = TODAY ? String(TODAY).slice(0, 7) : periodString(new Date());

	document.querySelectorAll('[data-cal]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var dir = btn.getAttribute('data-cal');
			if ('prev' === dir) { state.period = addMonths(state.period, -1); }
			if ('next' === dir) { state.period = addMonths(state.period, 1); }
			if ('today' === dir) { state.period = todayPeriod; }
			render();
		});
	});

	/* ── Anotar pago ─────────────────────────────────────────────────────── */

	function setPayStatus(message, type) {
		payStatus.style.display = '';
		payStatus.textContent = message;
		payStatus.className = 'af-modal__status is-' + (type || 'success');
	}

	function hidePayStatus() {
		payStatus.textContent = '';
		payStatus.style.display = 'none';
		payStatus.className = 'af-modal__status';
	}

	chargesEl.addEventListener('click', function (e) {
		var btn = e.target.closest('.af-cobranza-pay-open');
		if (!btn) { return; }

		payChargeId.value = btn.getAttribute('data-charge');
		var outstanding = parseFloat(btn.getAttribute('data-outstanding')) || 0;
		payAmount.value = outstanding.toFixed(2);
		payDate.value = TODAY || '';
		payOutstand.textContent = LABELS.balance + ': $' + monthMoney(outstanding) + ' — ' + LABELS.outstanding;
		payReference.value = '';
		hidePayStatus();
		payPanel.hidden = false;
		payPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	});

	payCancel.addEventListener('click', function () {
		payPanel.hidden = true;
		hidePayStatus();
	});

	payConfirm.addEventListener('click', function () {
		var amount = parseFloat(payAmount.value);
		if (!amount || amount <= 0) {
			setPayStatus(LABELS.amountErr, 'error');
			return;
		}

		payConfirm.disabled = true;
		hidePayStatus();

		var body = new URLSearchParams();
		body.append('action', 'af_record_payment');
		body.append('nonce', CONFIG.nonce);
		body.append('charge_id', payChargeId.value);
		body.append('amount', amount);
		body.append('payment_date', payDate.value);
		body.append('method', payMethod.value);
		body.append('reference', payReference.value);

		fetch(CONFIG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); }).then(function (json) {
			payConfirm.disabled = false;
			if (!json || !json.success) {
				setPayStatus((json && json.data && json.data.message) || LABELS.genericErr, 'error');
				return;
			}
			setPayStatus(json.data.message || LABELS.paidOk, 'success');
			setTimeout(function () { window.location.reload(); }, 900);
		}).catch(function () {
			payConfirm.disabled = false;
			setPayStatus(LABELS.networkErr, 'error');
		});
	});
}());
</script>
