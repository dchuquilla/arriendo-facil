<?php
/**
 * Mi plan: plan actual, uso y comparativa de planes.
 *
 * @package Arriendo_Facil
 *
 * @var array  $definitions Plan definitions.
 * @var string $current     Current plan key.
 * @var array  $request     Pending request or null.
 * @var int    $since       Timestamp of the last plan change (0 when unknown).
 * @var bool   $managed     Whether plan rules apply to this user.
 * @var array  $usage       Usage per resource: used / limit.
 * @var string $target      Plan highlighted by an upgrade prompt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$keys         = array_keys( $definitions );
$current_rank = (int) array_search( $current, $keys, true );
$limit_labels = Arriendo_Facil_Plans::limit_labels();
$feature_lbls = Arriendo_Facil_Plans::feature_labels();
$current_def  = $definitions[ $current ];

$plan_lines = static function ( array $definition ) use ( $feature_lbls ) {
	$lines = array();

	$limit = $definition['limits']['properties'];
	$lines[] = array(
		true,
		null === $limit
			? __( 'Inmuebles, inquilinos y contratos ilimitados', 'arriendo-facil' )
			/* translators: %d: amount of properties */
			: sprintf( __( 'Hasta %d inmuebles, inquilinos y contratos', 'arriendo-facil' ), (int) $limit ),
	);

	$lines[] = array(
		true,
		null === $definition['services']
			? __( 'Pagos de servicios completos', 'arriendo-facil' )
			: __( 'Pagos de servicios: solo agua y luz', 'arriendo-facil' ),
	);

	foreach ( $feature_lbls as $feature => $label ) {
		$lines[] = array( ! empty( $definition['features'][ $feature ] ), $label );
	}

	return $lines;
};
?>
<div class="wrap af-shell af-plans">

	<?php
	af_page_header(
		array(
			'eyebrow'  => __( 'Suscripción', 'arriendo-facil' ),
			'title'    => __( 'Mi plan', 'arriendo-facil' ),
			'subtitle' => __( 'Elige el plan que se ajusta a tu operación. Puedes cambiarlo o cancelarlo cuando quieras.', 'arriendo-facil' ),
		)
	);
	?>

	<section class="af-plan-current" data-af-plan-current="<?php echo esc_attr( $current ); ?>">
		<span class="af-plan-current__icon" aria-hidden="true"><?php echo af_lucide( 'crown', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		<div class="af-plan-current__body">
			<p class="af-plan-current__eyebrow"><?php esc_html_e( 'Tu plan actual', 'arriendo-facil' ); ?></p>
			<h2><?php echo esc_html( $current_def['label'] ); ?> <small>$<?php echo esc_html( number_format_i18n( (float) $current_def['price'], 0 ) ); ?> <?php esc_html_e( '/ mes', 'arriendo-facil' ); ?></small></h2>
			<?php if ( $since ) : ?>
				<p class="af-plan-current__meta"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Activo desde el %s', 'arriendo-facil' ), date_i18n( get_option( 'date_format' ), $since ) ) ); ?></p>
			<?php endif; ?>
			<?php if ( $request ) : ?>
				<p class="af-plan-current__pending">
					<?php echo esc_html( sprintf( /* translators: %s: plan name */ __( 'Solicitaste el plan %s. Lo activaremos en breve.', 'arriendo-facil' ), $definitions[ $request['plan'] ]['label'] ) ); ?>
					<button type="button" class="af-plan-link" data-af-plan-cancel-request><?php esc_html_e( 'Cancelar solicitud', 'arriendo-facil' ); ?></button>
				</p>
			<?php endif; ?>
		</div>
		<?php if ( $managed && 'free' !== $current ) : ?>
			<button type="button" class="button af-btn af-btn--ghost af-plan-current__cancel" data-af-plan-cancel data-plan-label="<?php echo esc_attr( $current_def['label'] ); ?>"><?php esc_html_e( 'Cancelar plan', 'arriendo-facil' ); ?></button>
		<?php endif; ?>
	</section>

	<?php if ( $managed ) : ?>
		<section class="af-plan-usage" aria-label="<?php esc_attr_e( 'Uso de tu plan', 'arriendo-facil' ); ?>">
			<?php foreach ( $usage as $key => $row ) : ?>
				<?php
				$limit   = $row['limit'];
				$percent = ( null === $limit || $limit <= 0 ) ? 0 : min( 100, (int) round( $row['used'] / $limit * 100 ) );
				$state   = null !== $limit && $row['used'] >= $limit ? ' is-full' : ( $percent >= 80 ? ' is-near' : '' );
				?>
				<article class="af-plan-meter<?php echo esc_attr( $state ); ?>">
					<header>
						<span><?php echo esc_html( ucfirst( $limit_labels[ $key ] ) ); ?></span>
						<strong><?php echo null === $limit ? esc_html( number_format_i18n( $row['used'] ) . ' · ' . __( 'ilimitado', 'arriendo-facil' ) ) : esc_html( number_format_i18n( $row['used'] ) . ' / ' . number_format_i18n( $limit ) ); ?></strong>
					</header>
					<div class="af-plan-meter__bar"><i style="width:<?php echo esc_attr( (string) ( null === $limit ? 0 : $percent ) ); ?>%" data-af-fill="<?php echo esc_attr( (string) ( null === $limit ? 0 : $percent ) ); ?>"></i></div>
				</article>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>

	<?php if ( $needs_selection ) : ?>
		<section class="af-plan-select" id="af-plan-select" data-limit="<?php echo esc_attr( (string) $prop_limit ); ?>">
			<header class="af-plan-select__head">
				<div>
					<h2><?php esc_html_e( 'Elige tus inmuebles activos', 'arriendo-facil' ); ?></h2>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: plan, 2: limit, 3: total */
								__( 'Tu plan %1$s permite administrar %2$d de tus %3$d inmuebles. Los demás quedan guardados, con todos sus datos, y vuelven a activarse si cambias de plan.', 'arriendo-facil' ),
								$current_def['label'],
								$prop_limit,
								count( $owned_ids )
							)
						);
						?>
					</p>
				</div>
				<span class="af-plan-select__count" data-af-select-count aria-live="polite"></span>
			</header>

			<div class="af-plan-select__grid">
				<?php foreach ( $owned_ids as $owned_id ) : ?>
					<?php
					$owned_thumb = get_the_post_thumbnail_url( $owned_id, 'medium' );
					$owned_addr  = trim( (string) get_post_meta( $owned_id, '_af_address', true ) );
					$owned_on    = ! Arriendo_Facil_Tenancy::is_plan_locked( $owned_id );
					?>
					<label class="af-plan-select__item">
						<input type="checkbox" value="<?php echo esc_attr( (string) $owned_id ); ?>" <?php checked( $owned_on ); ?> />
						<span class="af-plan-select__thumb"<?php echo $owned_thumb ? ' style="background-image:url(' . esc_url( $owned_thumb ) . ')"' : ''; ?>>
							<?php echo $owned_thumb ? '' : af_lucide( 'building', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
						</span>
						<span class="af-plan-select__text">
							<strong><?php echo esc_html( get_the_title( $owned_id ) ? get_the_title( $owned_id ) : __( '(sin nombre)', 'arriendo-facil' ) ); ?></strong>
							<small><?php echo esc_html( $owned_addr ); ?></small>
						</span>
						<span class="af-plan-select__check" aria-hidden="true"><?php echo af_lucide( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<footer class="af-plan-select__foot">
				<button type="button" class="button af-btn af-btn--primary" data-af-plan-save-selection disabled><?php esc_html_e( 'Guardar selección', 'arriendo-facil' ); ?></button>
			</footer>
		</section>
	<?php endif; ?>

	<div class="af-plan-grid" id="af-plan-grid">
		<?php foreach ( $definitions as $key => $definition ) : ?>
			<?php
			$is_current = $key === $current;
			$is_pro     = 'pro' === $key;
			$is_pending = $request && $request['plan'] === $key;
			$rank       = (int) array_search( $key, $keys, true );
			$is_upgrade = $rank > $current_rank;
			?>
			<article class="af-plan-card<?php echo $is_current ? ' is-current' : ''; ?><?php echo $is_pro ? ' is-pro' : ''; ?><?php echo $target === $key ? ' is-target' : ''; ?>" data-plan="<?php echo esc_attr( $key ); ?>">
				<?php if ( $is_pro ) : ?>
					<span class="af-plan-card__crown"><?php echo af_lucide( 'crown', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?><?php esc_html_e( 'Recomendado', 'arriendo-facil' ); ?></span>
				<?php endif; ?>
				<h3><?php echo esc_html( sprintf( /* translators: %s: plan name */ __( 'Plan %s', 'arriendo-facil' ), $definition['label'] ) ); ?></h3>
				<p class="af-plan-card__tagline"><?php echo esc_html( $definition['tagline'] ); ?></p>
				<p class="af-plan-card__price"><b>$<?php echo esc_html( number_format_i18n( (float) $definition['price'], 0 ) ); ?></b> <span><?php esc_html_e( '/ mes', 'arriendo-facil' ); ?></span></p>

				<ul class="af-plan-card__list">
					<?php foreach ( $plan_lines( $definition ) as $line ) : ?>
						<li class="<?php echo $line[0] ? 'is-yes' : 'is-no'; ?>">
							<?php echo af_lucide( $line[0] ? 'check' : 'x', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
							<span><?php echo esc_html( $line[1] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ( ! $managed ) : ?>
					<button type="button" class="button af-btn af-btn--ghost" disabled><?php echo $is_current ? esc_html__( 'Plan actual', 'arriendo-facil' ) : esc_html__( 'Solo para gestores', 'arriendo-facil' ); ?></button>
				<?php elseif ( $is_current ) : ?>
					<button type="button" class="button af-btn af-btn--ghost" disabled><?php esc_html_e( 'Plan actual', 'arriendo-facil' ); ?></button>
				<?php elseif ( $is_pending ) : ?>
					<button type="button" class="button af-btn af-btn--ghost" disabled><?php esc_html_e( 'Solicitud enviada', 'arriendo-facil' ); ?></button>
				<?php else : ?>
					<button type="button" class="button af-btn <?php echo $is_upgrade ? 'af-btn--primary' : 'af-btn--ghost'; ?>" data-af-plan-choose="<?php echo esc_attr( $key ); ?>" data-plan-label="<?php echo esc_attr( $definition['label'] ); ?>" data-plan-price="<?php echo esc_attr( (string) $definition['price'] ); ?>">
						<?php
						if ( 'free' === $key ) {
							esc_html_e( 'Cambiar a Free', 'arriendo-facil' );
						} elseif ( $is_upgrade ) {
							echo esc_html( sprintf( /* translators: %s: plan name */ __( 'Mejorar a %s', 'arriendo-facil' ), $definition['label'] ) );
						} else {
							echo esc_html( sprintf( /* translators: %s: plan name */ __( 'Cambiar a %s', 'arriendo-facil' ), $definition['label'] ) );
						}
						?>
					</button>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>

	<p class="af-plan-note"><?php esc_html_e( 'Activamos los cambios de plan de forma manual: tras tu solicitud te confirmamos el pago y habilitamos el plan. Si cancelas, vuelves a Free de inmediato y conservas tus datos; eliges cuáles inmuebles seguir administrando y el resto queda guardado.', 'arriendo-facil' ); ?></p>
</div>
