<?php
/**
 * Public shared catalog template (https://site.tld/catalogo/<token>/).
 *
 * Renders the exact same dataset that backs the PDF download, grouped by
 * building, with client-side filters and flip cards for the full details.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $af_catalog_share_user, $af_catalog_share_not_found;

$catalog_share_user      = isset( $GLOBALS['af_catalog_share_user'] ) ? $GLOBALS['af_catalog_share_user'] : null;
$catalog_share_not_found = (bool) ( isset( $GLOBALS['af_catalog_share_not_found'] ) ? $GLOBALS['af_catalog_share_not_found'] : false );
$catalog_share_token     = isset( $GLOBALS['af_catalog_share_token'] ) ? $GLOBALS['af_catalog_share_token'] : '';

$catalog = array(
	'company'   => '',
	'email'     => '',
	'phone'     => '',
	'whatsapp'  => '',
	'groups'    => array(),
	'total'     => 0,
	'available' => 0,
);

if ( $catalog_share_user instanceof WP_User ) {
	$catalog = Arriendo_Facil_Catalog_Share::get_catalog( $catalog_share_user );
}

$catalog_share_company = $catalog['company'];
$catalog_share_groups  = $catalog['groups'];
$catalog_share_total   = (int) $catalog['total'];
$catalog_share_free    = (int) $catalog['available'];

/*
 * Filter vocabulary, collected from the rendered cards so the controls can
 * never offer a value that returns zero results.
 */
$af_filter_statuses = array();
$af_filter_types    = array();

foreach ( $catalog_share_groups as $af_group ) {
	foreach ( $af_group['cards'] as $af_card ) {
		if ( ! empty( $af_card['type'] ) ) {
			$af_filter_types[ $af_card['type'] ] = true;
		}
		if ( ! empty( $af_card['status_lbl'] ) ) {
			$af_filter_statuses[ $af_card['status_lbl'] ] = true;
		}
	}
}

$af_filter_statuses = array_keys( $af_filter_statuses );
$af_filter_types    = array_keys( $af_filter_types );

sort( $af_filter_statuses );
sort( $af_filter_types );

$af_pdf_url = $catalog_share_token
	? add_query_arg( Arriendo_Facil_Catalog_Share::PDF_ARG, '1', home_url( '/' . Arriendo_Facil_Catalog_Share::REWRITE_SLUG . '/' . $catalog_share_token . '/' ) )
	: '';

get_header();
?>

<main class="af-cs-page">

	<header class="af-cs-hero">
		<div class="af-cs-hero__inner">
			<p class="af-cs-hero__eyebrow"><?php esc_html_e( 'Catálogo de inmuebles', 'arriendo-facil' ); ?></p>
			<h1 class="af-cs-hero__title"><?php echo esc_html( $catalog_share_company ); ?></h1>

			<p class="af-cs-hero__subtitle">
				<?php
				if ( $catalog_share_not_found ) {
					esc_html_e( 'Este catálogo ya no está disponible o el enlace es incorrecto.', 'arriendo-facil' );
				} else {
					echo esc_html(
						sprintf(
							/* translators: 1: total properties, 2: available properties */
							_n(
								'%1$d inmueble · %2$d disponible',
								'%1$d inmuebles · %2$d disponibles',
								$catalog_share_total,
								'arriendo-facil'
							),
							$catalog_share_total,
							$catalog_share_free
						)
					);
				}
				?>
			</p>

			<?php if ( $catalog['phone'] || $catalog['email'] ) : ?>
				<ul class="af-cs-hero__contact">
					<?php if ( $catalog['phone'] ) : ?>
						<li>
							<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $catalog['phone'] ) ); ?>">
								<?php echo esc_html( $catalog['phone'] ); ?>
							</a>
						</li>
					<?php endif; ?>
					<?php if ( $catalog['email'] ) : ?>
						<li>
							<a href="mailto:<?php echo esc_attr( $catalog['email'] ); ?>">
								<?php echo esc_html( $catalog['email'] ); ?>
							</a>
						</li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
		</div>

		<?php if ( ! $catalog_share_not_found && $catalog_share_total > 0 ) : ?>
			<div class="af-cs-toolbar">
				<?php if ( $af_pdf_url ) : ?>
					<a class="af-cs-btn af-cs-btn--primary" href="<?php echo esc_url( $af_pdf_url ); ?>">
						<?php esc_html_e( 'Descargar PDF', 'arriendo-facil' ); ?>
					</a>
				<?php endif; ?>
				<button type="button" class="af-cs-btn af-cs-btn--ghost af-cs-print">
					<?php esc_html_e( 'Imprimir', 'arriendo-facil' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</header>

	<?php if ( $catalog_share_not_found ) : ?>

		<div class="af-cs-empty">
			<p><?php esc_html_e( 'Pide a tu administrador un enlace actualizado para ver el catálogo.', 'arriendo-facil' ); ?></p>
		</div>

	<?php elseif ( empty( $catalog_share_groups ) ) : ?>

		<div class="af-cs-empty">
			<p><?php esc_html_e( 'Aún no hay propiedades publicadas en este catálogo.', 'arriendo-facil' ); ?></p>
		</div>

	<?php else : ?>

		<div class="af-cs-filters" role="search">
			<div class="af-cs-filters__field af-cs-filters__field--search">
				<label class="screen-reader-text" for="af-cs-q"><?php esc_html_e( 'Buscar', 'arriendo-facil' ); ?></label>
				<input
					type="search"
					id="af-cs-q"
					class="af-cs-filters__input"
					placeholder="<?php esc_attr_e( 'Buscar por nombre o dirección…', 'arriendo-facil' ); ?>"
					autocomplete="off"
				/>
			</div>

			<?php if ( count( $af_filter_statuses ) > 1 ) : ?>
				<div class="af-cs-filters__field">
					<label class="screen-reader-text" for="af-cs-status"><?php esc_html_e( 'Estado', 'arriendo-facil' ); ?></label>
					<select id="af-cs-status" class="af-cs-filters__input">
						<option value=""><?php esc_html_e( 'Todos los estados', 'arriendo-facil' ); ?></option>
						<?php foreach ( $af_filter_statuses as $af_status_label ) : ?>
							<option value="<?php echo esc_attr( $af_status_label ); ?>"><?php echo esc_html( $af_status_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>

			<?php if ( count( $af_filter_types ) > 1 ) : ?>
				<div class="af-cs-filters__field">
					<label class="screen-reader-text" for="af-cs-type"><?php esc_html_e( 'Tipo', 'arriendo-facil' ); ?></label>
					<select id="af-cs-type" class="af-cs-filters__input">
						<option value=""><?php esc_html_e( 'Todos los tipos', 'arriendo-facil' ); ?></option>
						<?php foreach ( $af_filter_types as $af_type_label ) : ?>
							<option value="<?php echo esc_attr( $af_type_label ); ?>"><?php echo esc_html( $af_type_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>

			<div class="af-cs-filters__field">
				<label class="screen-reader-text" for="af-cs-beds"><?php esc_html_e( 'Dormitorios', 'arriendo-facil' ); ?></label>
				<select id="af-cs-beds" class="af-cs-filters__input">
					<option value=""><?php esc_html_e( 'Dormitorios: todos', 'arriendo-facil' ); ?></option>
					<option value="1">1+</option>
					<option value="2">2+</option>
					<option value="3">3+</option>
					<option value="4">4+</option>
				</select>
			</div>
		</div>

		<p class="af-cs-count" data-af-cs-count hidden></p>

		<div class="af-cs-noresults" data-af-cs-empty hidden>
			<p><?php esc_html_e( 'Ninguna propiedad coincide con el filtro.', 'arriendo-facil' ); ?></p>
		</div>

		<?php foreach ( $catalog_share_groups as $af_group ) : ?>
			<section
				class="af-cs-group"
				data-af-cs-group
				data-af-cs-group-name="<?php echo esc_attr( $af_group['name'] ); ?>"
			>
				<div class="af-cs-group__head">
					<h2 class="af-cs-group__title"><?php echo esc_html( $af_group['name'] ); ?></h2>
					<span class="af-cs-group__count" data-af-cs-group-count>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of properties */
								_n( '%d inmueble', '%d inmuebles', count( $af_group['cards'] ), 'arriendo-facil' ),
								count( $af_group['cards'] )
							)
						);
						?>
					</span>
				</div>

				<div class="af-cs-grid">
					<?php foreach ( $af_group['cards'] as $af_card ) : ?>
						<?php
						/*
						 * The searchable haystack is rendered once and reused by
						 * the filter script, so there is a single source of truth
						 * for what "matches".
						 */
						$af_haystack = strtolower(
							$af_card['title'] . ' ' .
							$af_card['address'] . ' ' .
							$af_card['type'] . ' ' .
							$af_card['status_lbl'] . ' ' .
							$af_group['name'] . ' ' .
							implode( ' ', (array) $af_card['amenities'] )
						);

						$af_ws_message = $catalog['whatsapp']
							? rawurlencode(
								sprintf(
									/* translators: 1: property title, 2: company name */
									__( 'Hola, me interesa el inmueble %1$s que vi en el catálogo de %2$s.', 'arriendo-facil' ),
									$af_card['title'],
									$catalog_share_company
								)
							)
							: '';
						?>
						<article
							class="af-cs-card"
							data-af-cs-card
							data-af-cs-search="<?php echo esc_attr( $af_haystack ); ?>"
							data-af-cs-status="<?php echo esc_attr( $af_card['status_lbl'] ); ?>"
							data-af-cs-type="<?php echo esc_attr( $af_card['type'] ); ?>"
							data-af-cs-beds="<?php echo esc_attr( (int) $af_card['bedrooms'] ); ?>"
						>
							<div class="af-cs-card__inner">
								<div class="af-cs-card__face af-cs-card__face--front">
									<div class="af-cs-card__media">
										<?php if ( $af_card['thumb'] ) : ?>
											<img
												src="<?php echo esc_url( $af_card['thumb'] ); ?>"
												alt="<?php echo esc_attr( $af_card['title'] ); ?>"
												loading="lazy"
											/>
										<?php else : ?>
											<span class="af-cs-card__placeholder" aria-hidden="true">🏠</span>
										<?php endif; ?>
										<span class="af-cs-card__badge af-cs-card__badge--<?php echo esc_attr( $af_card['status'] ); ?>">
											<?php echo esc_html( $af_card['status_lbl'] ); ?>
										</span>
									</div>

									<div class="af-cs-card__body">
										<?php if ( $af_card['type'] ) : ?>
											<span class="af-cs-card__type"><?php echo esc_html( $af_card['type'] ); ?></span>
										<?php endif; ?>

										<h3 class="af-cs-card__title"><?php echo esc_html( $af_card['title'] ); ?></h3>

										<?php if ( $af_card['address'] ) : ?>
											<p class="af-cs-card__address"><?php echo esc_html( $af_card['address'] ); ?></p>
										<?php endif; ?>

										<ul class="af-cs-card__specs">
											<?php if ( $af_card['bedrooms'] ) : ?>
												<li><?php echo esc_html( sprintf( /* translators: %d: bedrooms */ _n( '%d dorm.', '%d dorms.', $af_card['bedrooms'], 'arriendo-facil' ), $af_card['bedrooms'] ) ); ?></li>
											<?php endif; ?>
											<?php if ( $af_card['bathrooms'] ) : ?>
												<li><?php echo esc_html( sprintf( /* translators: %d: bathrooms */ _n( '%d baño', '%d baños', $af_card['bathrooms'], 'arriendo-facil' ), $af_card['bathrooms'] ) ); ?></li>
											<?php endif; ?>
											<?php if ( $af_card['square_meters'] > 0 ) : ?>
												<li><?php echo esc_html( number_format_i18n( $af_card['square_meters'] ) ); ?> m²</li>
											<?php endif; ?>
											<?php if ( $af_card['parking'] > 0 ) : ?>
												<li><?php echo esc_html( sprintf( /* translators: %d: parking spots */ _n( '%d parqueadero', '%d parqueaderos', $af_card['parking'], 'arriendo-facil' ), $af_card['parking'] ) ); ?></li>
											<?php endif; ?>
										</ul>

										<div class="af-cs-card__price">
											<?php if ( $af_card['monthly_rent'] > 0 ) : ?>
												<span class="af-cs-card__amount">
													$<?php echo esc_html( number_format_i18n( $af_card['monthly_rent'], 2 ) ); ?>
												</span>
												<small><?php esc_html_e( '/ mes', 'arriendo-facil' ); ?></small>
											<?php else : ?>
												<span class="af-cs-card__amount af-cs-card__amount--na">
													<?php esc_html_e( 'A consultar', 'arriendo-facil' ); ?>
												</span>
											<?php endif; ?>
										</div>
									</div>

									<button
										type="button"
										class="af-cs-card__flip"
										data-af-cs-flip
										aria-expanded="false"
									>
										<span class="af-cs-card__flip-text">
											<?php esc_html_e( 'Ver detalles', 'arriendo-facil' ); ?>
										</span>
									</button>
								</div>

								<div class="af-cs-card__face af-cs-card__face--back">
									<div class="af-cs-card__backbody">
										<h4 class="af-cs-card__backtitle"><?php echo esc_html( $af_card['title'] ); ?></h4>

										<?php if ( $af_card['excerpt'] ) : ?>
											<p class="af-cs-card__excerpt"><?php echo esc_html( $af_card['excerpt'] ); ?></p>
										<?php endif; ?>

										<?php if ( $af_card['address'] ) : ?>
											<p class="af-cs-card__backline">
												<strong><?php esc_html_e( 'Ubicación:', 'arriendo-facil' ); ?></strong>
												<?php echo esc_html( $af_card['address'] ); ?>
											</p>
										<?php endif; ?>

										<?php if ( ! empty( $af_card['floor'] ) || $af_card['year_built'] ) : ?>
											<p class="af-cs-card__backline">
												<strong><?php esc_html_e( 'Datos:', 'arriendo-facil' ); ?></strong>
												<?php
												$af_extra = array();
												if ( $af_card['floor'] ) {
													$af_extra[] = sprintf(
														/* translators: %d: floor number */
														__( 'piso %d', 'arriendo-facil' ),
														$af_card['floor']
													);
												}
												if ( $af_card['year_built'] ) {
													$af_extra[] = sprintf(
														/* translators: %d: year */
														__( 'año %d', 'arriendo-facil' ),
														$af_card['year_built']
													);
												}
												echo esc_html( implode( ' · ', $af_extra ) );
												?>
											</p>
										<?php endif; ?>

										<?php if ( ! empty( $af_card['amenities'] ) ) : ?>
											<p class="af-cs-card__backline">
												<strong><?php esc_html_e( 'Amenidades:', 'arriendo-facil' ); ?></strong>
												<?php echo esc_html( implode( ' · ', $af_card['amenities'] ) ); ?>
											</p>
										<?php endif; ?>

										<?php if ( ! empty( $af_card['utilities'] ) ) : ?>
											<p class="af-cs-card__backline">
												<strong><?php esc_html_e( 'Servicios incluidos:', 'arriendo-facil' ); ?></strong>
												<?php echo esc_html( implode( ' · ', $af_card['utilities'] ) ); ?>
											</p>
										<?php endif; ?>
									</div>

									<div class="af-cs-card__actions">
										<?php if ( $af_ws_message && $catalog['whatsapp'] ) : ?>
											<a
												class="af-cs-btn af-cs-btn--whatsapp"
												href="<?php echo esc_url( $catalog['whatsapp'] . '?text=' . $af_ws_message ); ?>"
												target="_blank"
												rel="noopener noreferrer"
											>
												<?php esc_html_e( 'Consultar por WhatsApp', 'arriendo-facil' ); ?>
											</a>
										<?php endif; ?>

										<button type="button" class="af-cs-btn af-cs-btn--ghost" data-af-cs-flip>
											<?php esc_html_e( 'Volver', 'arriendo-facil' ); ?>
										</button>
									</div>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endforeach; ?>

	<?php endif; ?>

	<footer class="af-cs-footer">
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: company name, 2: generated date */
					__( '%1$s — Listado generado el %2$s.', 'arriendo-facil' ),
					$catalog_share_company,
					wp_date( get_option( 'date_format' ) )
				)
			);
			?>
		</p>
	</footer>
</main>

<?php
get_footer();
