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
 * @var array  $billing     Billing snapshot (Arriendo_Facil_Plan_Billing::snapshot()).
 * @var int[]  $owned_ids   The manager's properties (oldest first).
 * @var int    $locked_cnt  Properties hidden by the plan limit.
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

	<?php if ( $managed && ( $billing['expires'] || ( ! $billing['paid'] && $billing['prev'] ) ) ) : ?>
		<?php
		$is_lapsed = ! $billing['paid'];
		$ring      = $is_lapsed ? 0 : max( 0, min( 100, (int) round( $billing['days'] / 31 * 100 ) ) );
		$wa_url    = Arriendo_Facil_Plan_Billing::whatsapp_url();
		$ref_label = $is_lapsed && isset( $definitions[ $billing['prev'] ] ) ? $definitions[ $billing['prev'] ]['label'] : $current_def['label'];
		?>
		<section class="af-plan-renewal is-<?php echo esc_attr( $is_lapsed ? 'lapsed' : $billing['state'] ); ?>" aria-label="<?php esc_attr_e( 'Renovación', 'arriendo-facil' ); ?>">
			<div class="af-plan-renewal__ring" style="--p:<?php echo esc_attr( (string) $ring ); ?>" data-af-ring="<?php echo esc_attr( (string) $ring ); ?>">
				<span>
					<?php if ( $is_lapsed ) : ?>
						<?php echo af_lucide( 'lock', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
					<?php else : ?>
						<b><?php echo esc_html( (string) max( 0, $billing['days'] ) ); ?></b>
						<small><?php echo esc_html( _n( 'día', 'días', max( 0, $billing['days'] ), 'arriendo-facil' ) ); ?></small>
					<?php endif; ?>
				</span>
			</div>
			<div class="af-plan-renewal__body">
				<?php if ( $is_lapsed ) : ?>
					<h2><?php echo esc_html( sprintf( /* translators: %s: plan */ __( 'Tu plan %s venció', 'arriendo-facil' ), $ref_label ) ); ?></h2>
					<p>
						<?php
						echo esc_html(
							$locked_cnt > 0
								? sprintf( /* translators: %d: count */ _n( 'Tienes %d inmueble oculto por el límite del plan Free; se reactiva al renovar.', 'Tienes %d inmuebles ocultos por el límite del plan Free; se reactivan al renovar.', $locked_cnt, 'arriendo-facil' ), $locked_cnt )
								: __( 'Renueva para recuperar las funciones de tu plan.', 'arriendo-facil' )
						);
						?>
					</p>
				<?php else : ?>
					<h2>
						<?php
						echo esc_html(
							'ok' === $billing['state']
								? sprintf( /* translators: %s: date */ __( 'Se renueva el %s', 'arriendo-facil' ), Arriendo_Facil_Plan_Billing::format_date( $billing['expires'] ) )
								: sprintf( /* translators: %s: date */ __( 'Vence el %s', 'arriendo-facil' ), Arriendo_Facil_Plan_Billing::format_date( $billing['expires'] ) )
						);
						?>
					</h2>
					<p>
						<?php
						if ( 'due' === $billing['state'] ) {
							echo esc_html( sprintf( /* translators: %s: date */ __( 'Tienes hasta el %s para renovar. Después tu cuenta pasará al plan Free.', 'arriendo-facil' ), Arriendo_Facil_Plan_Billing::format_date( $billing['grace_until'] ) ) );
						} else {
							echo esc_html( sprintf( /* translators: 1: phrase, 2: day */ __( 'Tu plan %1$s. Se cobra el día %2$d de cada mes.', 'arriendo-facil' ), Arriendo_Facil_Plan_Billing::days_phrase( $billing['days'] ), max( 1, (int) $billing['day'] ) ) );
						}
						?>
					</p>
				<?php endif; ?>
			</div>
			<a class="button af-btn <?php echo ( $is_lapsed || 'ok' !== $billing['state'] ) ? 'af-btn--primary' : 'af-btn--ghost'; ?> af-plan-renewal__cta" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Enviar comprobante por WhatsApp', 'arriendo-facil' ); ?>
			</a>
		</section>
	<?php endif; ?>

	<?php if ( $managed && $locked_cnt > 0 ) : ?>
		<p class="af-plan-hidden-note">
			<?php echo af_lucide( 'lock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?>
			<span><?php echo esc_html( sprintf( /* translators: %d: number of hidden properties */ _n( '%d inmueble está oculto: tu plan administra tus primeros inmuebles. Sus datos están guardados y se reactivan al renovar o al contratar un plan con capacidad suficiente.', '%d inmuebles están ocultos: tu plan administra tus primeros inmuebles. Sus datos están guardados y se reactivan al renovar o al contratar un plan con capacidad suficiente.', $locked_cnt, 'arriendo-facil' ), $locked_cnt ) ); ?></span>
		</p>
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

	<details class="af-plan-terms">
		<summary><?php esc_html_e( 'Condiciones del plan', 'arriendo-facil' ); ?></summary>
		<ul>
			<li><?php esc_html_e( 'Los planes son mensuales y vencen siempre el mismo día de cada mes. El precio del plan es fijo.', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'El pago se hace fuera de la plataforma: envía tu comprobante por WhatsApp y activamos o renovamos tu plan de forma manual.', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Te avisamos en la plataforma y por correo 7, 3 y 1 días antes, y el día del vencimiento. Después tienes 3 días de gracia.', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Si no se renueva al terminar la gracia, tu cuenta pasa al plan Free. No borramos información: se administran tus 4 primeros inmuebles y el resto queda oculto hasta que renueves o contrates un plan con capacidad suficiente.', 'arriendo-facil' ); ?></li>
			<li><?php esc_html_e( 'Puedes cancelar cuando quieras: vuelves a Free de inmediato y conservas tus datos. Aplican los Términos y Condiciones del servicio.', 'arriendo-facil' ); ?></li>
		</ul>
	</details>
</div>
