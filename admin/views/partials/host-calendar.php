<?php
/**
 * Reusable interactive host calendar (grid + drawer + modal).
 *
 * Expects in scope:
 *  - string $calendar_month_anchor      Base date 'Y-m-d' used for the grid.
 *  - mixed  $calendar_scope_ids         Restriction from Arriendo_Facil_Tenancy.
 *  - int[]  $calendar_property_ids      Accommodation IDs for the filter/select.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cal_base_year  = (int) gmdate( 'Y', strtotime( $calendar_month_anchor ) );
$cal_base_month = (int) gmdate( 'n', strtotime( $calendar_month_anchor ) );
?>
<div class="af-cal" id="af-interactive-calendar" data-year="<?php echo esc_attr( $cal_base_year ); ?>" data-month="<?php echo esc_attr( $cal_base_month ); ?>">
	<div class="af-cal__grid-wrap">
		<div class="af-cal__toolbar">
			<h3 class="af-cal__title" id="af-cal-month-title"></h3>
			<span class="af-cal__nav">
				<button type="button" id="af-cal-prev" aria-label="<?php esc_attr_e( 'Mes anterior', 'arriendo-facil' ); ?>">‹</button>
				<button type="button" class="af-cal__today" id="af-cal-today"><?php esc_html_e( 'Hoy', 'arriendo-facil' ); ?></button>
				<button type="button" id="af-cal-next" aria-label="<?php esc_attr_e( 'Mes siguiente', 'arriendo-facil' ); ?>">›</button>
			</span>
			<span class="af-cal__legend">
				<span><i style="background:#eef4ff;"></i><?php esc_html_e( 'Visita', 'arriendo-facil' ); ?></span>
				<span><i style="background:#e7f6ec;"></i><?php esc_html_e( 'Check-in', 'arriendo-facil' ); ?></span>
				<span><i style="background:#fff4ed;"></i><?php esc_html_e( 'Check-out', 'arriendo-facil' ); ?></span>
				<span><i style="background:#f2f4f7;"></i><?php esc_html_e( 'Bloqueado', 'arriendo-facil' ); ?></span>
			</span>
		</div>
		<div class="af-cal__week">
			<span><?php esc_html_e( 'Lun', 'arriendo-facil' ); ?></span>
			<span><?php esc_html_e( 'Mar', 'arriendo-facil' ); ?></span>
			<span><?php esc_html_e( 'Mié', 'arriendo-facil' ); ?></span>
			<span><?php esc_html_e( 'Jue', 'arriendo-facil' ); ?></span>
			<span><?php esc_html_e( 'Vie', 'arriendo-facil' ); ?></span>
			<span><?php esc_html_e( 'Sáb', 'arriendo-facil' ); ?></span>
			<span><?php esc_html_e( 'Dom', 'arriendo-facil' ); ?></span>
		</div>
		<div class="af-cal__grid" id="af-cal-grid" role="grid" aria-label="<?php esc_attr_e( 'Calendario interactivo', 'arriendo-facil' ); ?>"></div>
		<p class="af-td-meta" style="margin-top:10px;"><?php esc_html_e( 'Haz clic en un día para ver sus eventos, agendar una visita o bloquear disponibilidad.', 'arriendo-facil' ); ?></p>
	</div>

	<aside class="af-cal__drawer" aria-live="polite">
		<div class="af-cal__drawer-head">
			<div class="af-cal__drawer-date" id="af-cal-drawer-date">—</div>
			<h3 class="af-cal__drawer-title" id="af-cal-drawer-title"><?php esc_html_e( 'Selecciona un día', 'arriendo-facil' ); ?></h3>
		</div>
		<div class="af-cal__drawer-body" id="af-cal-drawer-body"></div>
		<div class="af-cal__drawer-actions" id="af-cal-drawer-actions" style="visibility:hidden;">
			<button type="button" class="button af-btn af-btn--primary" data-cal-add><?php esc_html_e( '+ Visita', 'arriendo-facil' ); ?></button>
			<button type="button" class="button af-btn button--danger" data-cal-block><?php esc_html_e( 'Bloquear día', 'arriendo-facil' ); ?></button>
		</div>
	</aside>
</div>

<div class="af-modal" id="af-cal-modal" role="dialog" aria-modal="true" aria-labelledby="af-cal-modal-title" hidden>
	<div class="af-modal__backdrop" data-af-cal-close></div>
	<div class="af-modal__dialog">
		<button type="button" class="af-modal__close" data-af-cal-close aria-label="<?php esc_attr_e( 'Cerrar', 'arriendo-facil' ); ?>">&times;</button>
		<div class="af-modal__header">
			<h2 class="af-modal__title" id="af-cal-modal-title"><?php esc_html_e( 'Programar en el calendario', 'arriendo-facil' ); ?></h2>
			<p class="af-modal__subtitle" id="af-cal-modal-date" data-date=""></p>
		</div>
		<div class="af-modal__body">
			<p class="af-cal__status" id="af-cal-status"></p>
			<form id="af-cal-form">
				<div class="af-modal__field">
					<label for="af-cal-action-type"><?php esc_html_e( 'Acción', 'arriendo-facil' ); ?></label>
					<select id="af-cal-action-type">
						<option value="visit"><?php esc_html_e( 'Agendar visita', 'arriendo-facil' ); ?></option>
						<option value="block"><?php esc_html_e( 'Bloquear disponibilidad', 'arriendo-facil' ); ?></option>
					</select>
				</div>
				<div class="af-modal__field">
					<label for="af-cal-accommodation"><?php esc_html_e( 'Inmueble', 'arriendo-facil' ); ?></label>
					<select id="af-cal-accommodation">
						<option value=""><?php esc_html_e( 'Elige un inmueble…', 'arriendo-facil' ); ?></option>
						<?php
						$cal_prop_ids = $calendar_property_ids;
						if ( is_array( $calendar_scope_ids ) ) {
							$cal_prop_ids = array_values( array_intersect( array_map( 'absint', $calendar_property_ids ), array_map( 'intval', $calendar_scope_ids ) ) );
						}
						foreach ( $cal_prop_ids as $cal_prop_id ) :
							?>
							<option value="<?php echo esc_attr( (int) $cal_prop_id ); ?>"><?php echo esc_html( get_the_title( $cal_prop_id ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div id="af-cal-visit-fields">
					<div class="af-modal__field">
						<label for="af-cal-guest-name"><?php esc_html_e( 'Nombre del visitante', 'arriendo-facil' ); ?></label>
						<input type="text" id="af-cal-guest-name" />
					</div>
					<div class="af-modal__field" style="display:grid; grid-template-columns:1fr 1fr; gap:10px; align-items:end;">
						<div>
							<label for="af-cal-visit-time"><?php esc_html_e( 'Hora', 'arriendo-facil' ); ?></label>
							<input type="time" id="af-cal-visit-time" value="10:00" />
						</div>
						<div>
							<label for="af-cal-guest-email"><?php esc_html_e( 'Email (opcional)', 'arriendo-facil' ); ?></label>
							<input type="email" id="af-cal-guest-email" />
						</div>
					</div>
					<div class="af-modal__field" style="display:grid; grid-template-columns:1fr 1fr; gap:10px; align-items:end;">
						<div>
							<label for="af-cal-guest-phone"><?php esc_html_e( 'Teléfono (opcional)', 'arriendo-facil' ); ?></label>
							<input type="text" id="af-cal-guest-phone" />
						</div>
						<div style="display:flex; align-items:end;">
							<small class="af-td-meta"><?php esc_html_e( 'Se usa como referencia del visitante.', 'arriendo-facil' ); ?></small>
						</div>
					</div>
					<div class="af-modal__field">
						<label for="af-cal-visit-notes"><?php esc_html_e( 'Notas (opcional)', 'arriendo-facil' ); ?></label>
						<textarea id="af-cal-visit-notes" rows="2"></textarea>
					</div>
				</div>
				<div id="af-cal-block-fields" style="display:none;">
					<div class="af-modal__field">
						<label for="af-cal-block-reason"><?php esc_html_e( 'Motivo del bloqueo (opcional)', 'arriendo-facil' ); ?></label>
						<input type="text" id="af-cal-block-reason" placeholder="<?php esc_attr_e( 'p. ej. reparación, mantenimiento, reserva…', 'arriendo-facil' ); ?>" />
						<p class="af-modal__hint"><?php esc_html_e( 'El día quedará marcado como no disponible para ese inmueble.', 'arriendo-facil' ); ?></p>
					</div>
				</div>
				<div class="af-modal__footer">
					<button type="button" class="button af-btn af-btn--ghost" data-af-cal-close><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
					<button type="submit" class="button af-btn af-btn--primary" id="af-cal-submit"><?php esc_html_e( 'Agendar visita', 'arriendo-facil' ); ?></button>
				</div>
			</form>
		</div>
	</div>
</div>