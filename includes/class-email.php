<?php
/**
 * Plantilla y envío centralizado de correos de Arriendo Fácil.
 *
 * - Diseño responsive con la paleta de la marca.
 * - Pie legal: identidad del remitente, motivo por el que se recibe el correo,
 *   derechos del titular de datos (LOPDP) y enlace de baja.
 * - Baja de comunicaciones firmada (HMAC) con soporte RFC 8058 (One-Click).
 *
 * Categorías de envío:
 * - transactional: mensajes de servicio (verificación, contratos, avisos). Siempre se envían.
 * - notification:  recordatorios, resúmenes y novedades. Respetan la baja del destinatario.
 * - internal:      avisos para el equipo operativo. Sin enlace de baja.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Email
 */
class Arriendo_Facil_Email {

	const OPTOUT_OPTION = 'af_email_optouts';
	const QUERY_VAR     = 'af_email_pref';

	const CAT_TRANSACTIONAL = 'transactional';
	const CAT_NOTIFICATION  = 'notification';
	const CAT_INTERNAL      = 'internal';

	/**
	 * Registra el endpoint público de preferencias (baja / reactivación).
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'handle_preferences_request' ), 1 );
	}

	/**
	 * Paleta de la marca (colores en línea: los clientes de correo no soportan variables CSS).
	 *
	 * @return array<string,string>
	 */
	private static function palette() {
		return array(
			'primary'       => '#164A55',
			'primary_hover' => '#0F3D47',
			'secondary'     => '#2F7D73',
			'secondary_bg'  => '#DDEEEB',
			'accent'        => '#D89B5B',
			'accent_bg'     => '#F5E9DA',
			'bg'            => '#F7F8F6',
			'surface'       => '#FFFFFF',
			'text'          => '#193238',
			'muted'         => '#647579',
			'border'        => '#DCE5E3',
			'info'          => '#3882F6',
			'error'         => '#D9534F',
			'review'        => '#7C68AA',
			'inactive'      => '#8A9799',
		);
	}

	/**
	 * Colores (texto, fondo suave) por tipo de aviso/estado.
	 *
	 * @param string $type success|warning|info|error|review|neutral.
	 * @return array<string,string>
	 */
	private static function tone( $type ) {
		$p     = self::palette();
		$tones = array(
			'success' => array( 'fg' => '#1F5A52', 'bg' => $p['secondary_bg'], 'bar' => $p['secondary'] ),
			'warning' => array( 'fg' => '#7A4B16', 'bg' => $p['accent_bg'], 'bar' => $p['accent'] ),
			'info'    => array( 'fg' => '#1F5FC4', 'bg' => '#E8F1FE', 'bar' => $p['info'] ),
			'error'   => array( 'fg' => '#B03A36', 'bg' => '#FBECEB', 'bar' => $p['error'] ),
			'review'  => array( 'fg' => '#5A4A82', 'bg' => '#EFEBF6', 'bar' => $p['review'] ),
			'neutral' => array( 'fg' => $p['muted'], 'bg' => '#EEF2F0', 'bar' => $p['inactive'] ),
		);

		return isset( $tones[ $type ] ) ? $tones[ $type ] : $tones['neutral'];
	}

	/* ─────────────────────────────── Envío ─────────────────────────────── */

	/**
	 * Envía un correo con la plantilla de la marca.
	 *
	 * @param string|string[]      $to       Destinatario(s).
	 * @param string               $subject  Asunto.
	 * @param array<string,mixed>  $args     Ver render().
	 * @param string               $category Categoría de envío.
	 * @param string[]             $headers  Cabeceras adicionales (p. ej. From).
	 * @return bool
	 */
	public static function send( $to, $subject, array $args = array(), $category = self::CAT_TRANSACTIONAL, array $headers = array() ) {
		$recipients = array_values( array_filter( array_map( 'sanitize_email', (array) $to ) ) );

		if ( empty( $recipients ) ) {
			return false;
		}

		$all_sent = true;

		foreach ( $recipients as $recipient ) {
			if ( ! is_email( $recipient ) ) {
				$all_sent = false;
				continue;
			}

			if ( self::CAT_NOTIFICATION === $category && self::is_opted_out( $recipient ) ) {
				$all_sent = false;
				continue;
			}

			$args['category'] = $category;
			$html             = self::render( $args, $recipient );
			$plain            = self::render_plain_text( $args, $recipient );

			$mail_headers = array( 'Content-Type: text/html; charset=UTF-8' );
			foreach ( $headers as $header ) {
				if ( 0 !== stripos( (string) $header, 'Content-Type:' ) ) {
					$mail_headers[] = (string) $header;
				}
			}

			if ( self::CAT_NOTIFICATION === $category ) {
				$mail_headers[] = 'List-Unsubscribe: <' . self::unsubscribe_url( $recipient, true ) . '>';
				$mail_headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
			}

			$alt_body = static function ( $phpmailer ) use ( $plain ) {
				$phpmailer->AltBody = $plain; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			};
			add_action( 'phpmailer_init', $alt_body );
			$sent = (bool) wp_mail( $recipient, (string) $subject, $html, $mail_headers );
			remove_action( 'phpmailer_init', $alt_body );

			if ( ! $sent ) {
				$all_sent = false;
			}
		}

		return $all_sent;
	}

	/* ───────────────────────────── Renderizado ─────────────────────────── */

	/**
	 * Convierte texto plano (párrafos separados por línea en blanco) en HTML seguro.
	 *
	 * @param string $text Texto plano.
	 * @return string
	 */
	public static function text_to_html( $text ) {
		$p      = self::palette();
		$blocks = preg_split( '/\R{2,}/', trim( (string) $text ) );
		$html   = '';

		foreach ( (array) $blocks as $block ) {
			$block = trim( (string) $block );
			if ( '' === $block ) {
				continue;
			}
			$html .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.65;color:' . $p['text'] . ';">' . nl2br( esc_html( $block ) ) . '</p>';
		}

		return $html;
	}

	/**
	 * Construye el HTML completo del correo.
	 *
	 * Claves de $args (todas opcionales salvo title o content):
	 * - preheader  (string)  Texto de vista previa.
	 * - eyebrow    (string)  Etiqueta pequeña sobre el título.
	 * - title      (string)  Título del encabezado.
	 * - status     (array)   array( 'type' => success|warning|info|error|review|neutral, 'label' => string ).
	 * - greeting   (string)  Saludo, p. ej. "Hola Ana,".
	 * - body_text  (string)  Texto plano (se escapa y se separa en párrafos).
	 * - content    (string)  HTML ya escapado (se inserta tal cual, antes de details).
 * - content_after (string) HTML ya escapado (después de details/notice, antes del botón).
	 * - details    (array)   Filas etiqueta => valor (se escapan).
	 * - notice     (array)   array( 'type' => ..., 'text' => string ).
	 * - button     (array)   array( 'label' => string, 'url' => string ).
	 * - note       (string)  Texto pequeño bajo el botón.
	 * - category   (string)  Categoría de envío.
	 *
	 * @param array<string,mixed> $args  Contenido.
	 * @param string              $email Destinatario (para el pie y el enlace de baja).
	 * @return string
	 */
	public static function render( array $args, $email = '' ) {
		$p        = self::palette();
		$category = isset( $args['category'] ) ? (string) $args['category'] : self::CAT_TRANSACTIONAL;
		$site     = self::site_name();
		$font     = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";

		$title     = isset( $args['title'] ) ? (string) $args['title'] : '';
		$preheader = isset( $args['preheader'] ) ? (string) $args['preheader'] : $title;

		$body = '';

		if ( ! empty( $args['greeting'] ) ) {
			$body .= '<p style="margin:0 0 16px;font-size:17px;line-height:1.5;font-weight:600;color:' . $p['text'] . ';">' . esc_html( (string) $args['greeting'] ) . '</p>';
		}

		if ( ! empty( $args['body_text'] ) ) {
			$body .= self::text_to_html( (string) $args['body_text'] );
		}

		if ( ! empty( $args['content'] ) ) {
			$body .= (string) $args['content'];
		}

		if ( ! empty( $args['details'] ) && is_array( $args['details'] ) ) {
			$body .= self::render_details( $args['details'] );
		}

		if ( ! empty( $args['notice']['text'] ) ) {
			$tone  = self::tone( isset( $args['notice']['type'] ) ? (string) $args['notice']['type'] : 'info' );
			$body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;"><tr>'
				. '<td style="background:' . $tone['bg'] . ';border-left:4px solid ' . $tone['bar'] . ';border-radius:8px;padding:14px 16px;font-size:14px;line-height:1.6;color:' . $tone['fg'] . ';">'
				. nl2br( esc_html( (string) $args['notice']['text'] ) )
				. '</td></tr></table>';
		}

		if ( ! empty( $args['content_after'] ) ) {
			$body .= (string) $args['content_after'];
		}

		if ( ! empty( $args['button']['url'] ) && ! empty( $args['button']['label'] ) ) {
			$url   = esc_url( (string) $args['button']['url'] );
			$body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 20px;"><tr>'
				. '<td align="center" bgcolor="' . $p['primary'] . '" style="border-radius:10px;background:' . $p['primary'] . ';">'
				. '<a href="' . $url . '" class="af-btn" style="display:inline-block;padding:14px 28px;font-family:' . $font . ';font-size:16px;font-weight:700;line-height:1.2;color:#FFFFFF;text-decoration:none;border-radius:10px;">'
				. esc_html( (string) $args['button']['label'] ) . '</a></td></tr></table>';

			$body .= '<p style="margin:0 0 20px;font-size:12px;line-height:1.6;color:' . $p['muted'] . ';">'
				. esc_html__( 'Si el botón no funciona, copia y pega este enlace en tu navegador:', 'arriendo-facil' )
				. '<br><a href="' . $url . '" style="color:' . $p['primary'] . ';word-break:break-all;">' . esc_html( $url ) . '</a></p>';
		}

		if ( ! empty( $args['note'] ) ) {
			$body .= '<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:' . $p['muted'] . ';">' . nl2br( esc_html( (string) $args['note'] ) ) . '</p>';
		}

		$status_html = '';
		if ( ! empty( $args['status']['label'] ) ) {
			$tone        = self::tone( isset( $args['status']['type'] ) ? (string) $args['status']['type'] : 'neutral' );
			$status_html = '<span style="display:inline-block;margin:0 0 12px;padding:5px 12px;border-radius:999px;background:' . $tone['bg'] . ';color:' . $tone['fg'] . ';font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">'
				. esc_html( (string) $args['status']['label'] ) . '</span><br>';
		}

		$eyebrow_html = '';
		if ( ! empty( $args['eyebrow'] ) ) {
			$eyebrow_html = '<div style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:' . $p['accent'] . ';">' . esc_html( (string) $args['eyebrow'] ) . '</div>';
		}

		$out  = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">';
		$out .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
		$out .= '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">';
		$out .= '<title>' . esc_html( $title ) . '</title>';
		$out .= '<style>';
		$out .= 'body{margin:0;padding:0;background:' . $p['bg'] . ';-webkit-text-size-adjust:100%;}';
		$out .= 'table{border-collapse:collapse;}';
		$out .= 'a{color:' . $p['primary'] . ';}';
		$out .= '.af-btn:hover{background:' . $p['primary_hover'] . ' !important;}';
		$out .= '@media only screen and (max-width:620px){';
		$out .= '.af-wrap{padding:12px 8px !important;}';
		$out .= '.af-card{border-radius:12px !important;}';
		$out .= '.af-pad{padding:24px 20px !important;}';
		$out .= '.af-title{font-size:22px !important;}';
		$out .= '.af-btn{display:block !important;text-align:center !important;}';
		$out .= '.af-detail-label,.af-detail-value{display:block !important;width:100% !important;padding:2px 0 !important;}';
		$out .= '}';
		$out .= '</style></head>';
		$out .= '<body style="margin:0;padding:0;background:' . $p['bg'] . ';font-family:' . $font . ';color:' . $p['text'] . ';">';

		$out .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;font-size:1px;line-height:1px;">' . esc_html( $preheader ) . str_repeat( '&nbsp;&zwnj;', 20 ) . '</div>';

		$out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $p['bg'] . ';"><tr><td align="center" class="af-wrap" style="padding:28px 16px;">';
		$out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">';

		// Marca.
		$out .= '<tr><td style="padding:0 4px 14px;font-family:' . $font . ';font-size:20px;font-weight:800;letter-spacing:-.01em;color:' . $p['primary'] . ';">'
			. esc_html( $site )
			. '<span style="color:' . $p['accent'] . ';">.</span></td></tr>';

		// Tarjeta.
		$out .= '<tr><td class="af-card" style="background:' . $p['surface'] . ';border:1px solid ' . $p['border'] . ';border-radius:16px;overflow:hidden;">';
		$out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';

		if ( '' !== $title ) {
			$out .= '<tr><td class="af-pad" style="background:' . $p['primary'] . ';padding:28px 32px;border-bottom:4px solid ' . $p['accent'] . ';font-family:' . $font . ';">'
				. $eyebrow_html . $status_html
				. '<h1 class="af-title" style="margin:0;font-size:24px;line-height:1.3;font-weight:700;color:#FFFFFF;">' . esc_html( $title ) . '</h1>'
				. '</td></tr>';
		}

		$out .= '<tr><td class="af-pad" style="padding:32px;font-family:' . $font . ';font-size:16px;line-height:1.65;color:' . $p['text'] . ';">' . $body . '</td></tr>';
		$out .= '</table></td></tr>';

		// Pie.
		$out .= '<tr><td style="padding:22px 8px 0;font-family:' . $font . ';font-size:12px;line-height:1.7;color:' . $p['muted'] . ';text-align:center;">'
			. self::render_footer( $category, $email )
			. '</td></tr>';

		$out .= '</table></td></tr></table></body></html>';

		return $out;
	}

	/**
	 * Tabla de datos clave/valor (apila etiqueta y valor en móvil).
	 *
	 * @param array<string,string> $details Filas.
	 * @return string
	 */
	private static function render_details( array $details ) {
		$p    = self::palette();
		$rows = '';

		foreach ( $details as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$rows .= '<tr>'
				. '<td class="af-detail-label" width="38%" valign="top" style="padding:10px 14px;border-bottom:1px solid ' . $p['border'] . ';font-size:13px;font-weight:600;color:' . $p['muted'] . ';">' . esc_html( (string) $label ) . '</td>'
				. '<td class="af-detail-value" valign="top" style="padding:10px 14px;border-bottom:1px solid ' . $p['border'] . ';font-size:14px;color:' . $p['text'] . ';">' . nl2br( esc_html( (string) $value ) ) . '</td>'
				. '</tr>';
		}

		if ( '' === $rows ) {
			return '';
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:' . $p['bg'] . ';border:1px solid ' . $p['border'] . ';border-radius:10px;">' . $rows . '</table>';
	}

	/**
	 * Pie legal del correo.
	 *
	 * @param string $category Categoría de envío.
	 * @param string $email    Destinatario.
	 * @return string
	 */
	private static function render_footer( $category, $email ) {
		$p       = self::palette();
		$site    = self::site_name();
		$support = self::support_email();
		$link    = 'color:' . $p['primary'] . ';text-decoration:underline;';
		$html    = '';

		if ( self::CAT_INTERNAL === $category ) {
			$html .= esc_html__( 'Notificación interna para el equipo operativo de Arriendo Fácil.', 'arriendo-facil' );
		} else {
			$why = '' !== $email
				? sprintf(
					/* translators: %s: recipient email */
					esc_html__( 'Recibes este correo porque %s está asociado a una cuenta, solicitud o proceso en Arriendo Fácil.', 'arriendo-facil' ),
					'<strong>' . esc_html( $email ) . '</strong>'
				)
				: esc_html__( 'Recibes este correo porque tu dirección está asociada a una cuenta, solicitud o proceso en Arriendo Fácil.', 'arriendo-facil' );
			$html .= $why . '<br>';

			if ( self::CAT_NOTIFICATION === $category ) {
				$html .= esc_html__( 'Es un recordatorio o novedad opcional: puedes dejar de recibirlo cuando quieras.', 'arriendo-facil' );
			} else {
				$html .= esc_html__( 'Es un mensaje de servicio necesario para tu cuenta o trámite; los recordatorios opcionales sí puedes desactivarlos.', 'arriendo-facil' );
			}
			$html .= '<br>';

			if ( '' !== $email ) {
				$label = self::CAT_NOTIFICATION === $category
					? __( 'Darme de baja de estos correos', 'arriendo-facil' )
					: __( 'Gestionar mis comunicaciones', 'arriendo-facil' );
				$html .= '<a href="' . esc_url( self::unsubscribe_url( $email ) ) . '" style="' . $link . '">' . esc_html( $label ) . '</a> &nbsp;·&nbsp; ';
			}

			$html .= '<a href="' . esc_url( self::privacy_url() ) . '" style="' . $link . '">' . esc_html__( 'Política de privacidad', 'arriendo-facil' ) . '</a>'
				. ' &nbsp;·&nbsp; <a href="' . esc_url( self::terms_url() ) . '" style="' . $link . '">' . esc_html__( 'Términos y condiciones', 'arriendo-facil' ) . '</a>';

			$html .= '<br><br>' . esc_html__( 'Puedes ejercer tus derechos de acceso, rectificación, eliminación y oposición sobre tus datos personales escribiendo a', 'arriendo-facil' )
				. ' <a href="mailto:' . esc_attr( $support ) . '" style="' . $link . '">' . esc_html( $support ) . '</a>.';
		}

		$identity = self::legal_identity();
		$html    .= '<br><br><span style="color:' . $p['inactive'] . ';">' . esc_html( $identity ) . ' · <a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . $p['inactive'] . ';text-decoration:underline;">' . esc_html( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) . '</a></span>';

		return $html;
	}

	/**
	 * Versión en texto plano (multipart/alternative) para clientes sin HTML y filtros antispam.
	 *
	 * @param array<string,mixed> $args  Contenido.
	 * @param string              $email Destinatario.
	 * @return string
	 */
	private static function render_plain_text( array $args, $email ) {
		$category = isset( $args['category'] ) ? (string) $args['category'] : self::CAT_TRANSACTIONAL;
		$lines    = array();

		if ( ! empty( $args['title'] ) ) {
			$lines[] = (string) $args['title'];
			$lines[] = '';
		}
		if ( ! empty( $args['greeting'] ) ) {
			$lines[] = (string) $args['greeting'];
			$lines[] = '';
		}
		if ( ! empty( $args['body_text'] ) ) {
			$lines[] = trim( (string) $args['body_text'] );
			$lines[] = '';
		}
		if ( ! empty( $args['content'] ) ) {
			$content = preg_replace( '#<(br|/p|/tr|/li|/h[1-6])\s*/?>#i', "\n", (string) $args['content'] );
			$lines[] = trim( html_entity_decode( wp_strip_all_tags( (string) $content ), ENT_QUOTES, 'UTF-8' ) );
			$lines[] = '';
		}
		if ( ! empty( $args['content_after'] ) ) {
			$after   = preg_replace( '#<(br|/p|/tr|/li|/h[1-6])\s*/?>#i', "\n", (string) $args['content_after'] );
			$lines[] = trim( html_entity_decode( wp_strip_all_tags( (string) $after ), ENT_QUOTES, 'UTF-8' ) );
			$lines[] = '';
		}
		if ( ! empty( $args['details'] ) && is_array( $args['details'] ) ) {
			foreach ( $args['details'] as $label => $value ) {
				if ( '' !== trim( (string) $value ) ) {
					$lines[] = $label . ': ' . $value;
				}
			}
			$lines[] = '';
		}
		if ( ! empty( $args['notice']['text'] ) ) {
			$lines[] = (string) $args['notice']['text'];
			$lines[] = '';
		}
		if ( ! empty( $args['button']['url'] ) ) {
			$lines[] = ( ! empty( $args['button']['label'] ) ? $args['button']['label'] . ': ' : '' ) . $args['button']['url'];
			$lines[] = '';
		}
		if ( ! empty( $args['note'] ) ) {
			$lines[] = (string) $args['note'];
			$lines[] = '';
		}

		$lines[] = '--';
		if ( self::CAT_INTERNAL === $category ) {
			$lines[] = __( 'Notificación interna para el equipo operativo de Arriendo Fácil.', 'arriendo-facil' );
		} else {
			$lines[] = sprintf(
				/* translators: %s: recipient email */
				__( 'Recibes este correo porque %s está asociado a una cuenta, solicitud o proceso en Arriendo Fácil.', 'arriendo-facil' ),
				'' !== $email ? $email : __( 'tu dirección', 'arriendo-facil' )
			);
			if ( '' !== $email ) {
				$lines[] = __( 'Gestionar o darte de baja:', 'arriendo-facil' ) . ' ' . self::unsubscribe_url( $email );
			}
			$lines[] = __( 'Política de privacidad:', 'arriendo-facil' ) . ' ' . self::privacy_url();
			$lines[] = __( 'Tus derechos sobre tus datos personales:', 'arriendo-facil' ) . ' ' . self::support_email();
		}
		$lines[] = self::legal_identity() . ' - ' . home_url( '/' );

		return implode( "\n", $lines );
	}

	/* ───────────────────── Datos del remitente / enlaces ───────────────── */

	/**
	 * Nombre comercial.
	 *
	 * @return string
	 */
	private static function site_name() {
		return (string) apply_filters( 'af_email_brand_name', 'Arriendo Fácil' );
	}

	/**
	 * Razón social / identidad legal (y dirección si está configurada).
	 *
	 * @return string
	 */
	private static function legal_identity() {
		$entity  = (string) apply_filters( 'af_email_legal_entity', (string) get_option( 'af_email_legal_entity', 'Arriendo Fácil' ) );
		$address = (string) apply_filters( 'af_email_legal_address', (string) get_option( 'af_email_legal_address', '' ) );

		return '' !== trim( $address ) ? $entity . ' · ' . $address : $entity;
	}

	/**
	 * Correo de contacto para soporte y derechos del titular.
	 *
	 * @return string
	 */
	private static function support_email() {
		$email = (string) apply_filters( 'af_email_support_address', (string) get_option( 'af_email_support_address', '' ) );
		if ( ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email', '' );
		}

		return $email;
	}

	/**
	 * URL de política de privacidad.
	 *
	 * @return string
	 */
	private static function privacy_url() {
		$url = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';

		return '' !== $url ? $url : home_url( '/politica-de-privacidad/' );
	}

	/**
	 * URL de términos y condiciones.
	 *
	 * @return string
	 */
	private static function terms_url() {
		$page = get_page_by_path( 'terminos-y-condiciones' );

		return $page instanceof WP_Post ? (string) get_permalink( $page ) : home_url( '/terminos-y-condiciones/' );
	}

	/* ───────────────────────── Baja de comunicaciones ──────────────────── */

	/**
	 * Clave interna (hash) de un correo para guardar la baja sin exponer la dirección.
	 *
	 * @param string $email Correo.
	 * @return string
	 */
	private static function optout_key( $email ) {
		return hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), wp_salt( 'auth' ) . 'af_email_optout_v1' );
	}

	/**
	 * Firma del enlace de baja.
	 *
	 * @param string $email Correo.
	 * @return string
	 */
	private static function sign( $email ) {
		return substr( hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), wp_salt( 'auth' ) . 'af_email_unsub_v1' ), 0, 40 );
	}

	/**
	 * URL pública de baja/gestión para un correo.
	 *
	 * @param string $email     Correo.
	 * @param bool   $one_click Si es el endpoint para List-Unsubscribe (RFC 8058).
	 * @return string
	 */
	public static function unsubscribe_url( $email, $one_click = false ) {
		$email = strtolower( trim( (string) $email ) );
		$args  = array(
			self::QUERY_VAR => '1',
			'e'             => rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			't'             => self::sign( $email ),
		);

		if ( $one_click ) {
			$args['oc'] = '1';
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Indica si un correo se dio de baja de las comunicaciones opcionales.
	 *
	 * @param string $email Correo.
	 * @return bool
	 */
	public static function is_opted_out( $email ) {
		$list = get_option( self::OPTOUT_OPTION, array() );

		return is_array( $list ) && isset( $list[ self::optout_key( $email ) ] );
	}

	/**
	 * Registra o revierte la baja.
	 *
	 * @param string $email   Correo.
	 * @param bool   $opt_out true para dar de baja, false para reactivar.
	 * @return void
	 */
	public static function set_opt_out( $email, $opt_out ) {
		$list = get_option( self::OPTOUT_OPTION, array() );
		$list = is_array( $list ) ? $list : array();
		$key  = self::optout_key( $email );

		if ( $opt_out ) {
			$list[ $key ] = time();
		} else {
			unset( $list[ $key ] );
		}

		update_option( self::OPTOUT_OPTION, $list, false );
	}

	/**
	 * Procesa el enlace público de baja: confirmación, baja, reactivación y One-Click.
	 *
	 * @return void
	 */
	public function handle_preferences_request() {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- enlace firmado por HMAC.
			return;
		}

		$encoded = isset( $_GET['e'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_GET['e'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token   = isset( $_GET['t'] ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( (string) wp_unslash( $_GET['t'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$email   = strtolower( (string) base64_decode( strtr( (string) $encoded, '-_', '+/' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( ! is_email( $email ) || '' === $token || ! hash_equals( self::sign( $email ), $token ) ) {
			$this->render_page( 'invalid', '' );
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'POST' === $method ) {
			// RFC 8058: el cliente de correo envía "List-Unsubscribe=One-Click".
			if ( isset( $_POST['List-Unsubscribe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				self::set_opt_out( $email, true );
				nocache_headers();
				status_header( 200 );
				exit;
			}

			$action = isset( $_POST['af_pref_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['af_pref_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( 'unsubscribe' === $action ) {
				self::set_opt_out( $email, true );
				$this->render_page( 'unsubscribed', $email );
				return;
			}
			if ( 'resubscribe' === $action ) {
				self::set_opt_out( $email, false );
				$this->render_page( 'resubscribed', $email );
				return;
			}
		}

		$this->render_page( self::is_opted_out( $email ) ? 'already' : 'confirm', $email );
	}

	/**
	 * Página pública de preferencias (con la paleta de la marca).
	 *
	 * @param string $state invalid|confirm|already|unsubscribed|resubscribed.
	 * @param string $email Correo (puede estar vacío en 'invalid').
	 * @return void
	 */
	private function render_page( $state, $email ) {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		status_header( 'invalid' === $state ? 404 : 200 );

		$p        = self::palette();
		$masked   = self::mask_email( $email );
		$icon     = '&#9993;&#xFE0F;';
		$heading  = '';
		$message  = '';
		$action   = '';
		$btn      = '';

		switch ( $state ) {
			case 'invalid':
				$icon    = '&#9888;&#xFE0F;';
				$heading = __( 'Enlace no válido', 'arriendo-facil' );
				$message = __( 'Este enlace no es válido o está incompleto. Escríbenos y atenderemos tu solicitud manualmente.', 'arriendo-facil' );
				break;
			case 'unsubscribed':
				$icon    = '&#9989;';
				$heading = __( 'Te diste de baja', 'arriendo-facil' );
				$message = sprintf(
					/* translators: %s: masked email */
					__( 'Dejaremos de enviar recordatorios y novedades opcionales a %s. Seguirás recibiendo los mensajes de servicio indispensables (verificación de cuenta, seguridad, contratos y avisos legales).', 'arriendo-facil' ),
					$masked
				);
				$action = 'resubscribe';
				$btn    = __( 'Me equivoqué, volver a suscribirme', 'arriendo-facil' );
				break;
			case 'already':
				$icon    = '&#9989;';
				$heading = __( 'Ya estás dado de baja', 'arriendo-facil' );
				$message = sprintf(
					/* translators: %s: masked email */
					__( '%s no recibe recordatorios ni novedades opcionales. Los mensajes de servicio indispensables continúan.', 'arriendo-facil' ),
					$masked
				);
				$action = 'resubscribe';
				$btn    = __( 'Volver a suscribirme', 'arriendo-facil' );
				break;
			case 'resubscribed':
				$icon    = '&#128236;';
				$heading = __( 'Suscripción reactivada', 'arriendo-facil' );
				$message = sprintf(
					/* translators: %s: masked email */
					__( 'Volverás a recibir recordatorios y novedades en %s.', 'arriendo-facil' ),
					$masked
				);
				$action = 'unsubscribe';
				$btn    = __( 'Darme de baja otra vez', 'arriendo-facil' );
				break;
			default:
				$heading = __( 'Gestiona tus comunicaciones', 'arriendo-facil' );
				$message = sprintf(
					/* translators: %s: masked email */
					__( 'Confirma si quieres dejar de recibir recordatorios y novedades opcionales en %s. Los mensajes de servicio indispensables (verificación de cuenta, seguridad, contratos y avisos legales) se mantienen mientras tengas una relación activa con Arriendo Fácil.', 'arriendo-facil' ),
					$masked
				);
				$action = 'unsubscribe';
				$btn    = __( 'Confirmar baja', 'arriendo-facil' );
				break;
		}

		$support = self::support_email();

		echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">';
		echo '<title>' . esc_html( $heading ) . ' · ' . esc_html( self::site_name() ) . '</title>';
		echo '<style>';
		echo 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:' . esc_html( $p['bg'] ) . ';font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:' . esc_html( $p['text'] ) . ';}';
		echo '.afp{box-sizing:border-box;width:100%;max-width:460px;margin:24px;background:' . esc_html( $p['surface'] ) . ';border:1px solid ' . esc_html( $p['border'] ) . ';border-top:5px solid ' . esc_html( $p['accent'] ) . ';border-radius:16px;padding:36px 28px;text-align:center;box-shadow:0 10px 30px rgba(25,50,56,.08);}';
		echo '.afp__brand{margin:0 0 18px;font-size:18px;font-weight:800;color:' . esc_html( $p['primary'] ) . ';}';
		echo '.afp__icon{font-size:44px;line-height:1;margin:0 0 12px;}';
		echo '.afp h1{margin:0 0 10px;font-size:22px;color:' . esc_html( $p['text'] ) . ';}';
		echo '.afp p{margin:0 0 20px;font-size:15px;line-height:1.65;color:' . esc_html( $p['muted'] ) . ';}';
		echo '.afp button{width:100%;padding:14px 20px;border:0;border-radius:10px;background:' . esc_html( $p['primary'] ) . ';color:#fff;font-size:16px;font-weight:700;cursor:pointer;}';
		echo '.afp button:hover,.afp button:focus-visible{background:' . esc_html( $p['primary_hover'] ) . ';}';
		echo '.afp small{display:block;margin-top:18px;font-size:12px;color:' . esc_html( $p['muted'] ) . ';}';
		echo '.afp a{color:' . esc_html( $p['primary'] ) . ';}';
		echo '</style></head><body><main class="afp">';
		echo '<div class="afp__brand">' . esc_html( self::site_name() ) . '<span style="color:' . esc_html( $p['accent'] ) . ';">.</span></div>';
		echo '<div class="afp__icon" aria-hidden="true">' . $icon . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- entidades estáticas.
		echo '<h1>' . esc_html( $heading ) . '</h1><p>' . esc_html( $message ) . '</p>';

		if ( '' !== $action && is_email( $email ) ) {
			echo '<form method="post" action="' . esc_url( self::unsubscribe_url( $email ) ) . '">';
			echo '<input type="hidden" name="af_pref_action" value="' . esc_attr( $action ) . '">';
			echo '<button type="submit">' . esc_html( $btn ) . '</button></form>';
		}

		echo '<small>' . esc_html__( '¿Dudas o quieres ejercer tus derechos sobre tus datos personales? Escríbenos a', 'arriendo-facil' ) . ' <a href="mailto:' . esc_attr( $support ) . '">' . esc_html( $support ) . '</a></small>';
		echo '</main></body></html>';
		exit;
	}

	/**
	 * Oculta parte del correo (a***@dominio.com) en la página pública.
	 *
	 * @param string $email Correo.
	 * @return string
	 */
	private static function mask_email( $email ) {
		if ( ! is_email( $email ) ) {
			return '';
		}

		list( $local, $domain ) = explode( '@', $email, 2 );

		return substr( $local, 0, 1 ) . str_repeat( '*', max( 2, strlen( $local ) - 1 ) ) . '@' . $domain;
	}
}
