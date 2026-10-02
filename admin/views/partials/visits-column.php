<?php
/**
 * "Visitas agendadas" column (Panel and Calendario). Each row opens the
 * post-visit result modal from partials/visit-prospects.php.
 *
 * Expects: $upcoming_visits (with outcome/rating), $prospects_pending, $visit_outcomes.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<article class="af-calendar-col af-calendar-col--visit">
	<header class="af-calendar-col__head">
		<span class="af-calendar-col__icon" aria-hidden="true"><?php echo af_lucide( 'user-plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper. ?></span>
		<div class="af-calendar-col__title">
			<h3><?php esc_html_e( 'Visitas agendadas', 'arriendo-facil' ); ?></h3>
			<span class="af-calendar-col__count"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d agendada', '%d agendadas', count( $upcoming_visits ), 'arriendo-facil' ), count( $upcoming_visits ) ) ); ?></span>
		</div>
	</header>
	<?php if ( empty( $upcoming_visits ) ) : ?>
		<p class="af-empty__text"><?php esc_html_e( 'Sin visitas confirmadas en el rango.', 'arriendo-facil' ); ?></p>
	<?php else : ?>
		<div class="af-semaforo__table" role="table" aria-label="<?php esc_attr_e( 'Visitas agendadas', 'arriendo-facil' ); ?>">
			<?php foreach ( $upcoming_visits as $visit ) : ?>
				<?php $visit_out = isset( $visit->outcome, $visit_outcomes[ $visit->outcome ] ) && 'pending' !== $visit->outcome ? $visit->outcome : ''; ?>
				<button type="button" class="af-semaforo__row af-visit-row" data-visit-outcome="<?php echo esc_attr( (int) $visit->id ); ?>" title="<?php esc_attr_e( 'Registrar resultado de la visita', 'arriendo-facil' ); ?>">
					<span class="af-semaforo__tenant">
						<strong>
							<?php echo esc_html( trim( (string) $visit->guest_name ) ? trim( (string) $visit->guest_name ) : __( 'Visitante', 'arriendo-facil' ) ); ?>
							<?php if ( ! empty( $visit->rating ) ) : ?>
								<span class="af-rating af-rating--<?php echo esc_attr( strtolower( $visit->rating ) ); ?>"><?php echo esc_html( $visit->rating ); ?></span>
							<?php endif; ?>
						</strong>
						<small><?php echo esc_html( ( $visit->accommodation_title ? $visit->accommodation_title : '—' ) . ( $visit_out ? ' · ' . $visit_outcomes[ $visit_out ] : '' ) ); ?></small>
					</span>
					<span class="af-pill af-pill--<?php echo esc_attr( 'registered' === $visit_out ? 'success' : 'info' ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $visit->visit_date . ' ' . $visit->start_time ) ) ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<button type="button" class="button af-btn af-btn--ghost af-calendar-col__cta" data-prospects-open>
		<?php
		echo esc_html(
			$prospects_pending
				? sprintf( /* translators: %d: visits without result */ _n( 'Historial y prospectos · %d sin resultado', 'Historial y prospectos · %d sin resultado', $prospects_pending, 'arriendo-facil' ), $prospects_pending )
				: __( 'Historial y prospectos', 'arriendo-facil' )
		);
		?>
	</button>
</article>
