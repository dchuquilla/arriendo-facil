<?php
/**
 * Public shared catalog template (https://site.tld/catalogo/<token>/).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb, $af_catalog_share_user, $af_catalog_share_not_found;

$af_catalog_share_user      = isset( $GLOBALS['af_catalog_share_user'] ) ? $GLOBALS['af_catalog_share_user'] : null;
$catalog_share_not_found    = $af_catalog_share_not_found;
$catalog_share_company_name = '';
$catalog_share_user         = $af_catalog_share_user;

$catalog_share_cards = array();

if ( $catalog_share_user instanceof WP_User ) {
	$catalog_share_company_name = (string) get_user_meta( $catalog_share_user->ID, 'af_company_name', true );
	if ( '' === $catalog_share_company_name ) {
		$catalog_share_company_name = $catalog_share_user->display_name ? $catalog_share_user->display_name : $catalog_share_user->user_login;
	}

	$share_ids = get_posts(
		array(
			'post_type'      => 'accommodation',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => '_af_owner_id', 'value' => (int) $catalog_share_user->ID ),
			),
		)
	);

	if ( ! empty( $share_ids ) ) {
		$ids_sql = Arriendo_Facil_Tenancy::ids_in_clause( $share_ids );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$share_rows = $wpdb->get_results(
			"SELECT p.ID,
			 MAX(CASE WHEN pm.meta_key = '_af_monthly_rent'  THEN pm.meta_value END) AS monthly_rent,
			 MAX(CASE WHEN pm.meta_key = '_af_status'        THEN pm.meta_value END) AS status,
			 MAX(CASE WHEN pm.meta_key = '_af_property_type' THEN pm.meta_value END) AS property_type,
			 MAX(CASE WHEN pm.meta_key = '_af_address'       THEN pm.meta_value END) AS address,
			 MAX(CASE WHEN pm.meta_key = '_af_city'          THEN pm.meta_value END) AS city,
			 MAX(CASE WHEN pm.meta_key = '_af_bedrooms'      THEN pm.meta_value END) AS bedrooms,
			 MAX(CASE WHEN pm.meta_key = '_af_bathrooms'     THEN pm.meta_value END) AS bathrooms,
			 MAX(CASE WHEN pm.meta_key = '_thumbnail_id'     THEN pm.meta_value END) AS thumbnail_id
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			  AND pm.meta_key IN ('_af_monthly_rent','_af_status','_af_property_type','_af_address','_af_city','_af_bedrooms','_af_bathrooms','_thumbnail_id')
			 WHERE p.ID IN ($ids_sql)
			 GROUP BY p.ID
			 ORDER BY p.post_title ASC"
		);
		// phpcs:enable

		$share_pick = array(
			'apartment'  => __( 'Apartamento', 'arriendo-facil' ),
			'house'      => __( 'Casa', 'arriendo-facil' ),
			'office'     => __( 'Oficina', 'arriendo-facil' ),
			'room'       => __( 'Habitación', 'arriendo-facil' ),
			'commercial' => __( 'Comercial', 'arriendo-facil' ),
		);
		$status_map  = array(
			'available'   => __( 'Disponible', 'arriendo-facil' ),
			'rented'      => __( 'Arrendado', 'arriendo-facil' ),
			'maintenance' => __( 'En mantenimiento', 'arriendo-facil' ),
			'inactive'    => __( 'Inactivo', 'arriendo-facil' ),
		);

		foreach ( (array) $share_rows as $af_row ) {
			$share_status = $af_row->status ? (string) $af_row->status : 'available';
			if ( 'inactive' === $share_status ) {
				continue;
			}

			$thumb = $af_row->thumbnail_id ? wp_get_attachment_image_url( (int) $af_row->thumbnail_id, 'medium_large' ) : get_the_post_thumbnail_url( (int) $af_row->ID, 'medium_large' );

			$catalog_share_cards[] = array(
				'id'          => (int) $af_row->ID,
				'title'       => get_the_title( (int) $af_row->ID ),
				'thumb'       => $thumb,
				'status'      => $share_status,
				'status_lbl'  => isset( $status_map[ $share_status ] ) ? $status_map[ $share_status ] : $share_status,
				'type'        => isset( $share_pick[ $af_row->property_type ] ) ? $share_pick[ $af_row->property_type ] : ( $af_row->property_type ? ucfirst( $af_row->property_type ) : '' ),
				'address'     => trim( trim( (string) $af_row->address ) . ( $af_row->city ? ', ' . $af_row->city : '' ) ),
				'bedrooms'    => (int) $af_row->bedrooms,
				'bathrooms'   => (int) $af_row->bathrooms,
				'monthly_rent'=> (float) $af_row->monthly_rent,
			);
		}
	}
}

get_header();
?>

<main class="af-cs-page">
	<header class="af-cs-hero">
		<div class="af-cs-hero__inner">
			<h1 class="af-cs-hero__title">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: company / administrator name */
						__( 'Catálogo de %s', 'arriendo-facil' ),
						$catalog_share_company_name
					)
				);
				?>
			</h1>
			<p class="af-cs-hero__subtitle">
				<?php
				if ( $catalog_share_not_found ) {
					esc_html_e( 'Este catálogo ya no está disponible o el enlace es incorrecto.', 'arriendo-facil' );
				} else {
					echo esc_html(
						sprintf(
							/* translators: %d: number of properties */
							_n( '%d propiedad disponible para arriendo.', '%d propiedades disponibles para arriendo.', count( $catalog_share_cards ), 'arriendo-facil' ),
							count( $catalog_share_cards )
						)
					);
				}
				?>
			</p>
		</div>
		<?php if ( ! $catalog_share_not_found ) : ?>
			<div class="af-cs-toolbar">
				<button type="button" class="af-cs-print" onclick="window.print();return false;">
					<?php esc_html_e( 'Imprimir / guardar PDF', 'arriendo-facil' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</header>

	<?php if ( ! $catalog_share_not_found ) : ?>

		<?php if ( empty( $catalog_share_cards ) ) : ?>
			<div class="af-cs-empty">
				<p><?php esc_html_e( 'Aún no hay propiedades publicadas en este catálogo.', 'arriendo-facil' ); ?></p>
			</div>
		<?php else : ?>
			<div class="af-cs-grid">
				<?php foreach ( $catalog_share_cards as $card ) : ?>
					<article class="af-cs-card">
						<div class="af-cs-card__media">
							<?php if ( $card['thumb'] ) : ?>
								<img src="<?php echo esc_url( $card['thumb'] ); ?>" alt="<?php echo esc_attr( $card['title'] ); ?>" loading="lazy" />
							<?php else : ?>
								<div class="af-cs-card__placeholder" aria-hidden="true">🏠</div>
							<?php endif; ?>
							<span class="af-cs-card__badge af-cs-card__badge--<?php echo esc_attr( $card['status'] ); ?>">
								<?php echo esc_html( $card['status_lbl'] ); ?>
							</span>
						</div>
						<div class="af-cs-card__body">
							<?php if ( $card['type'] ) : ?>
								<span class="af-cs-card__type"><?php echo esc_html( $card['type'] ); ?></span>
							<?php endif; ?>
							<h2 class="af-cs-card__title"><?php echo esc_html( $card['title'] ); ?></h2>
							<?php if ( $card['address'] ) : ?>
								<p class="af-cs-card__address"><?php echo esc_html( $card['address'] ); ?></p>
							<?php endif; ?>
							<div class="af-cs-card__specs">
								<?php if ( $card['bedrooms'] ) : ?>
									<span class="af-cs-card__spec">
										<?php echo esc_html( sprintf( /* translators: %d: bedrooms */ _n( '%d dorm.', '%d dorm.', $card['bedrooms'], 'arriendo-facil' ), $card['bedrooms'] ) ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $card['bathrooms'] ) : ?>
									<span class="af-cs-card__spec">
										<?php echo esc_html( sprintf( /* translators: %d: bathrooms */ _n( '%d baño', '%d baños', $card['bathrooms'], 'arriendo-facil' ), $card['bathrooms'] ) ); ?>
									</span>
								<?php endif; ?>
							</div>
							<div class="af-cs-card__price">
								<?php if ( $card['monthly_rent'] > 0 ) : ?>
									$<?php echo esc_html( number_format_i18n( $card['monthly_rent'], 2 ) ); ?>
									<small><?php esc_html_e( '/ mes', 'arriendo-facil' ); ?></small>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</div>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

	<?php endif; ?>

	<footer class="af-cs-footer">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: company name, 2: generated date */
				__( '%1$s — Listado generado el %2$s.', 'arriendo-facil' ),
				$catalog_share_company_name,
				wp_date( get_option( 'date_format' ) )
			)
		);
		?>
	</footer>
</main>

<script>
	(function () {
		if ('<?php echo isset( $_GET['print'] ) ? (int) $_GET['print'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>' === '1') {
			window.addEventListener('load', function () { window.print(); });
		}
	}());
</script>

<?php
get_footer();