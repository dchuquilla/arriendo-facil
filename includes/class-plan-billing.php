<?php
/**
 * Ciclo de cobro mensual de los planes: vencimiento fijo, renovación manual,
 * avisos, gracia y regreso automático a Free.
 *
 * Los pagos se hacen fuera de la plataforma: el gestor envía su comprobante por
 * WhatsApp y el administrador renueva con un clic. El número de WhatsApp nunca
 * se imprime en el HTML ni en el JavaScript: se guarda en una opción (o en la
 * constante AF_PLAN_WHATSAPP) y solo se usa en una redirección con nonce.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Plan_Billing {

	const META_EXPIRES  = 'af_plan_expires_at';
	const META_DAY      = 'af_plan_billing_day';
	const META_REV      = 'af_plan_rev';
	const META_SEEN     = 'af_plan_seen_rev';
	const META_NOTICE   = 'af_plan_notice';
	const META_PREV     = 'af_plan_prev';
	const META_REMINDED = 'af_plan_reminded';
	const META_MODAL    = 'af_plan_modal_day';
	const OPT_WHATSAPP  = 'af_plan_whatsapp';
	const CRON          = 'af_plan_billing_daily';
	const WA_ACTION     = 'af_plan_whatsapp';

	/** Último día del mes admitido como día de cobro: así todos los meses lo tienen. */
	const MAX_DAY = 28;

	public function __construct() {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON, array( $this, 'run_daily' ) );
		add_action( 'admin_init', array( $this, 'sync_current_user' ), 0 );
		add_action( 'af_plan_changed', array( $this, 'on_plan_changed' ), 10, 5 );
		add_filter( 'heartbeat_received', array( $this, 'heartbeat' ), 10, 2 );

		add_action( 'admin_post_' . self::WA_ACTION, array( $this, 'redirect_to_whatsapp' ) );
		add_action( 'wp_ajax_af_plan_renew', array( $this, 'ajax_renew' ) );
		add_action( 'wp_ajax_af_plan_save_whatsapp', array( $this, 'ajax_save_whatsapp' ) );
	}

	/* ------------------------------------------------------------------
	 * Aritmética de fechas (sin dependencias de WordPress: testeable)
	 * ------------------------------------------------------------------ */

	/**
	 * End of the period that starts at $base_ts: the next month, same billing day, 00:00.
	 *
	 * @param int          $base_ts     Start of the period (any time of the day).
	 * @param int          $billing_day Fixed day of the month (1-28).
	 * @param DateTimeZone $tz          Site timezone.
	 * @return int Timestamp.
	 */
	public static function period_end( $base_ts, $billing_day, DateTimeZone $tz ) {
		$day = max( 1, min( self::MAX_DAY, (int) $billing_day ) );
		$d   = ( new DateTimeImmutable( '@' . (int) $base_ts ) )->setTimezone( $tz );
		$y   = (int) $d->format( 'Y' );
		$m   = (int) $d->format( 'n' ) + 1;

		if ( $m > 12 ) {
			$m = 1;
			++$y;
		}

		return $d->setDate( $y, $m, $day )->setTime( 0, 0, 0 )->getTimestamp();
	}

	/**
	 * First period of a fresh activation: the billing day is the activation day (max 28).
	 *
	 * @param int          $now_ts Activation time.
	 * @param DateTimeZone $tz     Site timezone.
	 * @return array{0:int,1:int} Expiry timestamp and billing day.
	 */
	public static function first_period( $now_ts, DateTimeZone $tz ) {
		$day = min( self::MAX_DAY, (int) ( new DateTimeImmutable( '@' . (int) $now_ts ) )->setTimezone( $tz )->format( 'j' ) );

		return array( self::period_end( $now_ts, $day, $tz ), $day );
	}

	/**
	 * Calendar days from $now_ts to $due_ts (negative once past).
	 *
	 * @param int          $now_ts Now.
	 * @param int          $due_ts Due date.
	 * @param DateTimeZone $tz     Site timezone.
	 * @return int
	 */
	public static function days_until( $now_ts, $due_ts, DateTimeZone $tz ) {
		$from = ( new DateTimeImmutable( '@' . (int) $now_ts ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
		$to   = ( new DateTimeImmutable( '@' . (int) $due_ts ) )->setTimezone( $tz )->setTime( 0, 0, 0 );

		return (int) $from->diff( $to )->format( '%r%a' );
	}

	/**
	 * Billing state from the days left.
	 *
	 * ok: more than $remind days; soon: 1..$remind; due: due date up to the end
	 * of the grace days; lapsed: after the grace.
	 *
	 * @param int $days_left Days until the due date.
	 * @param int $remind    Reminder window (days).
	 * @param int $grace     Grace days after the due date.
	 * @return string
	 */
	public static function state_for_days( $days_left, $remind, $grace ) {
		if ( $days_left > $remind ) {
			return 'ok';
		}
		if ( $days_left > 0 ) {
			return 'soon';
		}

		return $days_left >= -$grace ? 'due' : 'lapsed';
	}

	/**
	 * Reminder stage (7, 3, 1 or 0 days) for the days left, or null when none applies.
	 *
	 * @param int $days_left Days until the due date.
	 * @return int|null
	 */
	public static function reminder_stage( $days_left ) {
		foreach ( array( 0, 1, 3, 7 ) as $stage ) {
			if ( $days_left <= $stage ) {
				return $stage;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------
	 * Configuración
	 * ------------------------------------------------------------------ */

	/**
	 * Grace days after the due date.
	 *
	 * @return int
	 */
	public static function grace_days() {
		return max( 0, (int) apply_filters( 'af_plan_grace_days', 3 ) );
	}

	/**
	 * Days before the due date in which the manager is reminded.
	 *
	 * @return int
	 */
	public static function remind_days() {
		return max( 1, (int) apply_filters( 'af_plan_remind_days', 7 ) );
	}

	/**
	 * Days before the due date in which the admin gets the one-click renew button.
	 *
	 * @return int
	 */
	public static function admin_window_days() {
		return max( self::remind_days(), (int) apply_filters( 'af_plan_admin_window_days', 10 ) );
	}

	/**
	 * Digits-only WhatsApp number (never printed to the page).
	 *
	 * @return string
	 */
	private static function whatsapp_number() {
		$raw = defined( 'AF_PLAN_WHATSAPP' ) ? (string) AF_PLAN_WHATSAPP : (string) get_option( self::OPT_WHATSAPP, '' );

		return preg_replace( '/\D+/', '', $raw );
	}

	/**
	 * Masked number for the admin screen only (e.g. +593 ••• ••• 383).
	 *
	 * @return string Empty when not configured.
	 */
	public static function whatsapp_masked() {
		$digits = self::whatsapp_number();

		return strlen( $digits ) < 8 ? '' : '+' . substr( $digits, 0, 3 ) . ' ••• ••• ' . substr( $digits, -3 );
	}

	/* ------------------------------------------------------------------
	 * Estado de un gestor
	 * ------------------------------------------------------------------ */

	/**
	 * Expiry timestamp of the user's paid plan (0 = no expiry).
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function expires_at( $user_id ) {
		return (int) get_user_meta( absint( $user_id ), self::META_EXPIRES, true );
	}

	/**
	 * Billing snapshot of a user, computed from cached user meta only.
	 *
	 * @param int $user_id User ID.
	 * @return array{paid:bool,expires:int,days:int,state:string,grace_until:int,day:int,prev:string}
	 */
	public static function snapshot( $user_id ) {
		$user_id = absint( $user_id );
		$plan    = Arriendo_Facil_Plans::get_plan( $user_id );
		$paid    = 'free' !== $plan;
		$expires = $paid ? self::expires_at( $user_id ) : 0;
		$tz      = wp_timezone();
		$now     = time();
		$days    = $expires ? self::days_until( $now, $expires, $tz ) : 0;

		return array(
			'paid'        => $paid,
			'expires'     => $expires,
			'days'        => $days,
			'state'       => $paid && $expires ? self::state_for_days( $days, self::remind_days(), self::grace_days() ) : 'none',
			'grace_until' => $expires ? $expires + self::grace_days() * DAY_IN_SECONDS : 0,
			'day'         => (int) get_user_meta( $user_id, self::META_DAY, true ),
			'prev'        => (string) get_user_meta( $user_id, self::META_PREV, true ),
		);
	}

	/**
	 * Formats a timestamp as a long local date.
	 *
	 * @param int $ts Timestamp.
	 * @return string
	 */
	public static function format_date( $ts ) {
		return wp_date( 'j \d\e F \d\e Y', (int) $ts );
	}

	/**
	 * Human line for the days left ("en 12 días", "vence hoy", "venció hace 2 días").
	 *
	 * @param int $days Days until the due date.
	 * @return string
	 */
	public static function days_phrase( $days ) {
		if ( 0 === $days ) {
			return __( 'vence hoy', 'arriendo-facil' );
		}

		if ( $days > 0 ) {
			/* translators: %d: days */
			return sprintf( _n( 'en %d día', 'en %d días', $days, 'arriendo-facil' ), $days );
		}

		/* translators: %d: days */
		return sprintf( _n( 'venció hace %d día', 'venció hace %d días', -$days, 'arriendo-facil' ), -$days );
	}

	/* ------------------------------------------------------------------
	 * Cambios de plan, renovación y vencimiento
	 * ------------------------------------------------------------------ */

	/**
	 * Keeps expiry, notice and revision in step with a plan change.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $plan     New plan.
	 * @param string $previous Previous plan.
	 * @param int    $by       Acting user (0 = system).
	 * @param array  $args     Extra context (reason).
	 */
	public function on_plan_changed( $user_id, $plan, $previous, $by, $args = array() ) {
		$user_id = absint( $user_id );
		$reason  = isset( $args['reason'] ) ? (string) $args['reason'] : 'admin';
		$paid    = 'free' !== $plan;
		$type    = '';

		if ( $paid && $plan !== $previous ) {
			list( $expires, $day ) = self::first_period( time(), wp_timezone() );
			update_user_meta( $user_id, self::META_EXPIRES, $expires );
			update_user_meta( $user_id, self::META_DAY, $day );
			delete_user_meta( $user_id, self::META_PREV );
			delete_user_meta( $user_id, self::META_REMINDED );
			$type = 'renew' === $reason ? 'renewed' : ( 'free' === $previous ? 'activated' : 'changed' );
		} elseif ( ! $paid ) {
			delete_user_meta( $user_id, self::META_EXPIRES );
			delete_user_meta( $user_id, self::META_REMINDED );
			if ( 'lapsed' === $reason ) {
				update_user_meta( $user_id, self::META_PREV, $previous );
				$type = 'lapsed';
			} else {
				delete_user_meta( $user_id, self::META_PREV );
				$type = 'cancel' === $reason ? 'cancelled' : 'changed';
			}
		}

		if ( '' !== $type && 'signup' !== $reason && $plan !== $previous ) {
			self::push_notice( $user_id, $type, $plan, $previous );
		}
	}

	/**
	 * Stores the notice the user will see and bumps the revision (cheap change detector).
	 *
	 * @param int    $user_id  User ID.
	 * @param string $type     activated|renewed|changed|cancelled|lapsed.
	 * @param string $plan     Current plan.
	 * @param string $previous Previous plan.
	 */
	private static function push_notice( $user_id, $type, $plan, $previous ) {
		$defs    = Arriendo_Facil_Plans::definitions();
		$label   = $defs[ $plan ]['label'];
		$expires = self::expires_at( $user_id );
		$date    = $expires ? self::format_date( $expires ) : '';
		$tone    = 'confirm';

		switch ( $type ) {
			case 'activated':
			case 'changed':
				$title = sprintf( /* translators: %s: plan */ __( 'Tu plan ahora es %s', 'arriendo-facil' ), $label );
				$text  = $date
					? sprintf( /* translators: 1: plan, 2: date */ __( 'Activamos tu plan %1$s. Se renueva cada mes y tu próximo vencimiento es el %2$s.', 'arriendo-facil' ), $label, $date )
					: sprintf( /* translators: %s: plan */ __( 'Tu plan ahora es %s.', 'arriendo-facil' ), $label );
				break;
			case 'renewed':
				$title = __( 'Plan renovado', 'arriendo-facil' );
				$text  = sprintf( /* translators: 1: plan, 2: date */ __( 'Recibimos tu pago. Tu plan %1$s está activo hasta el %2$s y tus inmuebles vuelven a estar disponibles.', 'arriendo-facil' ), $label, $date );
				break;
			case 'cancelled':
				$title = __( 'Plan cancelado', 'arriendo-facil' );
				$text  = __( 'Ahora estás en el plan Free. Conservamos todos tus datos: los inmuebles que superan el límite quedan ocultos, no se borran.', 'arriendo-facil' );
				$tone  = 'danger';
				break;
			default: // lapsed.
				$title = __( 'Tu plan volvió a Free', 'arriendo-facil' );
				$text  = sprintf(
					/* translators: %s: previous plan */
					__( 'No recibimos la renovación de tu plan %s. Conservamos todos tus datos: se administran tus 4 primeros inmuebles y el resto queda oculto hasta que renueves o contrates un plan con capacidad suficiente.', 'arriendo-facil' ),
					$defs[ $previous ]['label']
				);
				$tone  = 'danger';
		}

		update_user_meta(
			$user_id,
			self::META_NOTICE,
			array(
				'type'  => $type,
				'title' => $title,
				'text'  => $text,
				'tone'  => $tone,
				'at'    => time(),
			)
		);
		update_user_meta( $user_id, self::META_REV, (int) get_user_meta( $user_id, self::META_REV, true ) + 1 );
	}

	/**
	 * Renews a paid plan by one month, keeping the fixed billing day.
	 *
	 * @param int $user_id          User ID.
	 * @param int $expected_expires Expiry the caller saw (guards against double clicks).
	 * @param int $by               Acting user.
	 * @return array{expires:int,plan:string}|WP_Error
	 */
	public static function renew( $user_id, $expected_expires, $by ) {
		$user_id = absint( $user_id );

		if ( ! Arriendo_Facil_Plans::applies_to( $user_id ) ) {
			return new WP_Error( 'af_plan_not_manager', __( 'Solo los gestores de propiedades tienen plan.', 'arriendo-facil' ) );
		}

		if ( ! Arriendo_Facil_Plans::lock( $user_id ) ) {
			return new WP_Error( 'af_plan_busy', __( 'Hay otra operación en curso para este gestor. Inténtalo de nuevo.', 'arriendo-facil' ) );
		}

		$plan = Arriendo_Facil_Plans::get_plan( $user_id );
		$snap = self::snapshot( $user_id );

		if ( (int) $expected_expires !== $snap['expires'] ) {
			return new WP_Error( 'af_plan_stale', __( 'La fecha de vencimiento cambió. Recarga la página para ver el estado actual.', 'arriendo-facil' ) );
		}

		// Plan vencido que volvió a Free: se restablece el que tenía.
		if ( 'free' === $plan ) {
			$prev = $snap['prev'];
			if ( '' === Arriendo_Facil_Plans::normalize( $prev ) || 'free' === $prev ) {
				return new WP_Error( 'af_plan_nothing_to_renew', __( 'Este gestor está en Free: activa primero un plan de pago.', 'arriendo-facil' ) );
			}
			Arriendo_Facil_Plans::set_plan( $user_id, $prev, array( 'reason' => 'renew', 'by' => $by ) );
			return array( 'expires' => self::expires_at( $user_id ), 'plan' => $prev );
		}

		$tz = wp_timezone();
		if ( $snap['expires'] && $snap['days'] > self::admin_window_days() ) {
			return new WP_Error(
				'af_plan_too_early',
				sprintf(
					/* translators: %d: days */
					__( 'Aún no corresponde renovar: faltan %d días para el vencimiento.', 'arriendo-facil' ),
					$snap['days']
				)
			);
		}

		$day = $snap['day'] ? $snap['day'] : self::first_period( time(), $tz )[1];

		if ( $snap['expires'] && time() <= $snap['grace_until'] + DAY_IN_SECONDS ) {
			$expires = self::period_end( $snap['expires'], $day, $tz ); // Se conserva el día fijo.
		} else {
			list( $expires, $day ) = self::first_period( time(), $tz ); // Sin vigencia previa: nuevo ciclo desde hoy.
		}

		update_user_meta( $user_id, self::META_EXPIRES, $expires );
		update_user_meta( $user_id, self::META_DAY, $day );
		update_user_meta( $user_id, Arriendo_Facil_Plans::META_SINCE, time() );
		delete_user_meta( $user_id, self::META_REMINDED );
		delete_user_meta( $user_id, self::META_PREV );

		Arriendo_Facil_Plans::log_history( $user_id, $plan, $plan, $by, 'renew' );
		self::push_notice( $user_id, 'renewed', $plan, $plan );
		Arriendo_Facil_Plans::apply_property_limit( $user_id );

		return array( 'expires' => $expires, 'plan' => $plan );
	}

	/**
	 * Returns an expired account to Free once the grace period is over.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether the plan was downgraded.
	 */
	public static function sync_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! Arriendo_Facil_Plans::applies_to( $user_id ) ) {
			return false;
		}

		$snap = self::snapshot( $user_id );
		if ( ! $snap['paid'] || ! $snap['expires'] || 'lapsed' !== $snap['state'] ) {
			return false;
		}

		if ( ! Arriendo_Facil_Plans::lock( $user_id ) ) {
			return false;
		}

		// Otra petición pudo haberlo resuelto mientras esperábamos el bloqueo.
		wp_cache_delete( $user_id, 'user_meta' );
		Arriendo_Facil_Plans::forget( $user_id );
		$snap = self::snapshot( $user_id );
		if ( ! $snap['paid'] || 'lapsed' !== $snap['state'] ) {
			return false;
		}

		return Arriendo_Facil_Plans::set_plan( $user_id, 'free', array( 'reason' => 'lapsed', 'by' => 0 ) );
	}

	/**
	 * Applies an expiry in real time on every admin request of a manager.
	 */
	public function sync_current_user() {
		if ( is_user_logged_in() && ! wp_doing_cron() ) {
			self::sync_user( get_current_user_id() );
		}
	}

	/* ------------------------------------------------------------------
	 * Cron diario: avisos y vencimientos (respaldo del cálculo en tiempo real)
	 * ------------------------------------------------------------------ */

	/**
	 * Schedules the daily job.
	 */
	public function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Daily job: applies expiries and sends the reminder emails once per stage.
	 */
	public function run_daily() {
		$ids = get_users(
			array(
				'role'       => Arriendo_Facil_Tenancy::PROPERTY_MANAGER_ROLE,
				'fields'     => 'ID',
				'number'     => 500,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_EXPIRES,
						'value'   => array( 1, time() + ( self::remind_days() + 1 ) * DAY_IN_SECONDS ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $ids as $user_id ) {
			$user_id = (int) $user_id;
			if ( self::sync_user( $user_id ) ) {
				self::email( $user_id, 'lapsed' );
				continue;
			}

			$snap = self::snapshot( $user_id );
			if ( ! $snap['paid'] || ! $snap['expires'] ) {
				continue;
			}

			$stage = self::reminder_stage( $snap['days'] );
			if ( null === $stage ) {
				continue;
			}

			$key  = $snap['expires'] . ':' . $stage;
			$sent = get_user_meta( $user_id, self::META_REMINDED, true );
			$sent = is_array( $sent ) ? $sent : array();
			if ( in_array( $key, $sent, true ) ) {
				continue;
			}

			self::email( $user_id, 'stage' . $stage );
			$sent[] = $key;
			update_user_meta( $user_id, self::META_REMINDED, array_slice( $sent, -8 ) );
		}
	}

	/**
	 * Sends a plain-text plan email. The payment channel is never included.
	 *
	 * @param int    $user_id User ID.
	 * @param string $kind    stage7|stage3|stage1|stage0|lapsed.
	 */
	private static function email( $user_id, $kind ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$defs    = Arriendo_Facil_Plans::definitions();
		$plan    = Arriendo_Facil_Plans::get_plan( $user_id );
		$label   = $defs[ $plan ]['label'];
		$snap    = self::snapshot( $user_id );
		$due     = $snap['expires'] ? self::format_date( $snap['expires'] ) : '';
		$until   = $snap['expires'] ? self::format_date( $snap['grace_until'] ) : '';
		$prev    = $snap['prev'] && isset( $defs[ $snap['prev'] ] ) ? $defs[ $snap['prev'] ]['label'] : $label;
		$url     = Arriendo_Facil_Plans::url();
		$name    = $user->display_name;

		if ( 'lapsed' === $kind ) {
			$subject = __( '[Arriendo Fácil] Tu plan volvió a Free', 'arriendo-facil' );
			$body    = sprintf(
				/* translators: 1: name, 2: previous plan, 3: url */
				__( "Hola %1\$s,\n\nNo recibimos la renovación de tu plan %2\$s, por lo que tu cuenta pasó al plan Free.\n\nNo borramos nada: se administran tus 4 primeros inmuebles y el resto queda oculto, con todos sus datos, hasta que renueves o contrates un plan con capacidad suficiente.\n\nPuedes renovar desde: %3\$s", 'arriendo-facil' ),
				$name,
				$prev,
				$url
			);
		} else {
			$when    = 'stage0' === $kind
				? sprintf( /* translators: 1: date, 2: date */ __( 'venció el %1$s. Tienes hasta el %2$s para renovar sin perder acceso', 'arriendo-facil' ), $due, $until )
				: sprintf( /* translators: %s: date */ __( 'vence el %s', 'arriendo-facil' ), $due );
			$subject = sprintf( /* translators: %s: plan */ __( '[Arriendo Fácil] Renovación de tu plan %s', 'arriendo-facil' ), $label );
			$body    = sprintf(
				/* translators: 1: name, 2: plan, 3: when, 4: url */
				__( "Hola %1\$s,\n\nTu plan %2\$s %3\$s.\n\nPara renovarlo, entra a \"Mi plan\" y envía tu comprobante de pago por WhatsApp; lo activamos manualmente.\nSi no se renueva a tiempo, tu cuenta volverá al plan Free y los inmuebles que superen su límite quedarán ocultos (sin borrarse) hasta que renueves.\n\nMi plan: %4\$s", 'arriendo-facil' ),
				$name,
				$label,
				$when,
				$url
			);
		}

		wp_mail( $user->user_email, $subject, $body );
	}

	/* ------------------------------------------------------------------
	 * Cambio de plan en tiempo real (Heartbeat) y avisos al cargar
	 * ------------------------------------------------------------------ */

	/**
	 * Notice waiting for the user, marked as seen. Null when nothing changed.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,string>|null
	 */
	public static function take_notice( $user_id ) {
		$rev  = (int) get_user_meta( $user_id, self::META_REV, true );
		$seen = (int) get_user_meta( $user_id, self::META_SEEN, true );
		if ( $rev === $seen ) {
			return null;
		}

		update_user_meta( $user_id, self::META_SEEN, $rev );
		$notice = get_user_meta( $user_id, self::META_NOTICE, true );

		return is_array( $notice ) && ! empty( $notice['title'] ) ? $notice : null;
	}

	/**
	 * Heartbeat: answers only when the plan revision differs from the page's.
	 *
	 * @param array $response Heartbeat response.
	 * @param array $data     Data sent by the browser.
	 * @return array
	 */
	public function heartbeat( $response, $data ) {
		if ( ! isset( $data['af_plan_rev'] ) || ! is_user_logged_in() ) {
			return $response;
		}

		$user_id = get_current_user_id();
		if ( ! Arriendo_Facil_Plans::applies_to( $user_id ) ) {
			return $response;
		}

		self::sync_user( $user_id );

		$rev = (int) get_user_meta( $user_id, self::META_REV, true );
		if ( $rev !== (int) $data['af_plan_rev'] ) {
			$response['af_plan'] = array(
				'rev'    => $rev,
				'notice' => self::take_notice( $user_id ),
			);
		}

		return $response;
	}

	/**
	 * Data the page needs: revision, change notice and (once a day) the renewal reminder.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>
	 */
	public static function client_state( $user_id ) {
		$state = array(
			'rev'     => (int) get_user_meta( $user_id, self::META_REV, true ),
			'notice'  => self::take_notice( $user_id ),
			'renewal' => null,
		);

		$snap = self::snapshot( $user_id );
		if ( $state['notice'] || ! in_array( $snap['state'], array( 'soon', 'due' ), true ) ) {
			return $state;
		}

		$today = wp_date( 'Y-m-d' );
		if ( $today === (string) get_user_meta( $user_id, self::META_MODAL, true ) ) {
			return $state;
		}
		update_user_meta( $user_id, self::META_MODAL, $today );

		$defs  = Arriendo_Facil_Plans::definitions();
		$label = $defs[ Arriendo_Facil_Plans::get_plan( $user_id ) ]['label'];
		$due   = self::format_date( $snap['expires'] );

		$state['renewal'] = array(
			'state' => $snap['state'],
			'title' => 'soon' === $snap['state']
				? sprintf( /* translators: %s: plan */ __( 'Tu plan %s se renueva pronto', 'arriendo-facil' ), $label )
				: sprintf( /* translators: %s: plan */ __( 'Tu plan %s necesita renovarse', 'arriendo-facil' ), $label ),
			'text'  => 'soon' === $snap['state']
				? sprintf( /* translators: 1: date, 2: phrase */ __( 'Vence el %1$s (%2$s). Para renovarlo, envía tu comprobante de pago por WhatsApp y lo activamos manualmente.', 'arriendo-facil' ), $due, self::days_phrase( $snap['days'] ) )
				: sprintf( /* translators: 1: date, 2: date */ __( 'Venció el %1$s. Tienes hasta el %2$s para renovar. Después tu cuenta volverá al plan Free y los inmuebles que superen su límite quedarán ocultos hasta que renueves; no se borra nada.', 'arriendo-facil' ), $due, self::format_date( $snap['grace_until'] ) ),
			'url'   => self::whatsapp_url(),
		);

		return $state;
	}

	/* ------------------------------------------------------------------
	 * WhatsApp protegido
	 * ------------------------------------------------------------------ */

	/**
	 * URL of the protected redirect (it carries a nonce, never the number).
	 *
	 * @return string
	 */
	public static function whatsapp_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::WA_ACTION ), self::WA_ACTION );
	}

	/**
	 * Verifies the request and sends the manager to WhatsApp with a prefilled message.
	 */
	public function redirect_to_whatsapp() {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! Arriendo_Facil_Plans::applies_to( $user_id ) ) {
			wp_die( esc_html__( 'No tienes permiso para esta acción.', 'arriendo-facil' ), 403 );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::WA_ACTION ) ) {
			wp_die( esc_html__( 'El enlace caducó. Vuelve a "Mi plan" e inténtalo de nuevo.', 'arriendo-facil' ), 403 );
		}

		// Máximo 10 aperturas por hora por usuario: evita usar el endpoint para rastrear el número.
		$key   = 'af_plan_wa_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count >= 10 ) {
			wp_die( esc_html__( 'Demasiados intentos. Espera un momento e inténtalo de nuevo.', 'arriendo-facil' ), 429 );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$number = self::whatsapp_number();
		if ( strlen( $number ) < 8 || strlen( $number ) > 15 ) {
			wp_die( esc_html__( 'El canal de pagos aún no está disponible. Escríbenos por correo y te ayudamos.', 'arriendo-facil' ), 503 );
		}

		$user  = wp_get_current_user();
		$defs  = Arriendo_Facil_Plans::definitions();
		$plan  = Arriendo_Facil_Plans::get_plan( $user_id );
		$snap  = self::snapshot( $user_id );
		$plan_ref = 'free' === $plan && $snap['prev'] && isset( $defs[ $snap['prev'] ] ) ? $snap['prev'] : $plan;
		$text  = sprintf(
			/* translators: 1: name, 2: email, 3: plan, 4: price, 5: due date */
			__( 'Hola, soy %1$s (%2$s). Envío el comprobante de pago del plan %3$s ($%4$s) de Arriendo Fácil.%5$s', 'arriendo-facil' ),
			$user->display_name,
			$user->user_email,
			$defs[ $plan_ref ]['label'],
			number_format_i18n( (float) $defs[ $plan_ref ]['price'], 0 ),
			$snap['expires'] ? ' ' . sprintf( /* translators: %s: date */ __( 'Vencimiento: %s.', 'arriendo-facil' ), self::format_date( $snap['expires'] ) ) : ''
		);

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		wp_redirect( 'https://wa.me/' . $number . '?text=' . rawurlencode( $text ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed host, digits-only number.
		exit;
	}

	/**
	 * Admin: stores the WhatsApp number used for payment proofs.
	 */
	public function ajax_save_whatsapp() {
		check_ajax_referer( Arriendo_Facil_Plans::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$digits = isset( $_POST['number'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['number'] ) ) ) : '';
		if ( strlen( $digits ) < 8 || strlen( $digits ) > 15 ) {
			wp_send_json_error( array( 'message' => __( 'Escribe el número con código de país (8 a 15 dígitos).', 'arriendo-facil' ) ), 400 );
		}

		update_option( self::OPT_WHATSAPP, $digits, false );

		wp_send_json_success(
			array(
				'message' => __( 'Número de WhatsApp guardado.', 'arriendo-facil' ),
				'masked'  => self::whatsapp_masked(),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Renovación con un clic (administrador)
	 * ------------------------------------------------------------------ */

	/**
	 * Admin: renews a manager's plan by one month.
	 */
	public function ajax_renew() {
		check_ajax_referer( Arriendo_Facil_Plans::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$user_id  = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$expected = isset( $_POST['expires'] ) ? absint( wp_unslash( $_POST['expires'] ) ) : 0;

		$result = self::renew( $user_id, $expected, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: date */
					__( 'Plan renovado hasta el %s.', 'arriendo-facil' ),
					self::format_date( $result['expires'] )
				),
			)
		);
	}

	/**
	 * What the master panel shows for a manager's billing.
	 *
	 * @param int $user_id User ID.
	 * @return array{label:string,tone:string,can_renew:bool,expires:int}
	 */
	public static function admin_row( $user_id ) {
		$snap = self::snapshot( $user_id );

		if ( ! $snap['paid'] ) {
			$can = '' !== Arriendo_Facil_Plans::normalize( $snap['prev'] ) && 'free' !== $snap['prev'];

			return array(
				'label'     => $can ? __( 'Venció: pasó a Free', 'arriendo-facil' ) : '',
				'tone'      => $can ? 'danger' : '',
				'can_renew' => $can,
				'expires'   => 0,
			);
		}

		if ( ! $snap['expires'] ) {
			return array( 'label' => __( 'Sin vencimiento (cuenta anterior)', 'arriendo-facil' ), 'tone' => '', 'can_renew' => true, 'cta' => __( 'Iniciar cobro mensual', 'arriendo-facil' ), 'expires' => 0 );
		}

		return array(
			'label'     => sprintf( /* translators: 1: date, 2: phrase */ __( 'Vence %1$s (%2$s)', 'arriendo-facil' ), wp_date( 'j M Y', $snap['expires'] ), self::days_phrase( $snap['days'] ) ),
			'tone'      => 'ok' === $snap['state'] ? '' : ( 'soon' === $snap['state'] ? 'warn' : 'danger' ),
			'can_renew' => $snap['days'] <= self::admin_window_days(),
			'expires'   => $snap['expires'],
		);
	}
}
