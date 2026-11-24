<?php
/**
 * Acceso del inquilino por código temporal (OTP) enviado a su correo.
 *
 * El inquilino no crea usuario ni contraseña de WordPress: recibe un enlace
 * (/inquilino/), pide un código de 6 dígitos a su correo registrado y, si es
 * correcto, obtiene una sesión corta ligada solo a su ficha. Esa sesión nunca
 * es una sesión de WordPress: únicamente permite ver su perfil, su historial
 * de pagos y registrar/consultar novedades de mantenimiento de SUS contratos.
 *
 * Seguridad:
 *  - El código se guarda como HMAC (nunca en claro), vence a los 10 minutos,
 *    admite 5 intentos y se invalida al usarse o al pedir uno nuevo.
 *  - Respuestas genéricas: no se revela si un correo está o no registrado.
 *  - Límites por IP y por correo para solicitar/validar códigos.
 *  - Sesión: token aleatorio (solo su hash en BD), cookie HttpOnly/SameSite,
 *    caducidad por inactividad y tope absoluto; las acciones que escriben
 *    exigen además un token CSRF atado a la sesión.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Tenant_Access
 */
class Arriendo_Facil_Tenant_Access {

	const QUERY_VAR      = 'af_tenant_portal';
	const SLUG           = 'inquilino';
	const COOKIE         = 'af_tp_session';
	const DB_VERSION     = '1';
	const CODE_LENGTH    = 6;
	const CODE_TTL       = 600;    // 10 minutos.
	const MAX_ATTEMPTS   = 5;
	const RESEND_WAIT    = 60;     // Segundos entre envíos.
	const SESSION_IDLE   = 7200;   // 2 h de inactividad.
	const SESSION_MAX    = 43200;  // 12 h absolutas.
	const REPORTS_PER_DAY = 10;

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_install' ), 5 );
		add_action( 'init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'maybe_flush_rules' ), 99 );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'send_privacy_headers' ), 1 );
		add_filter( 'template_include', array( $this, 'load_template' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		foreach ( array( 'request', 'verify', 'logout', 'report' ) as $action ) {
			add_action( 'wp_ajax_nopriv_af_tpa_' . $action, array( $this, 'ajax_' . $action ) );
			add_action( 'wp_ajax_af_tpa_' . $action, array( $this, 'ajax_' . $action ) );
		}
		add_action( 'wp_ajax_af_send_tenant_portal_link', array( $this, 'ajax_send_portal_link' ) );
		add_action( 'af_tenant_access_purge', array( $this, 'purge_expired' ) );
		if ( ! wp_next_scheduled( 'af_tenant_access_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'af_tenant_access_purge' );
		}
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Piezas puras (probadas sin WordPress)
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Código numérico de 6 dígitos con CSPRNG.
	 *
	 * @return string
	 */
	public static function new_code() {
		return str_pad( (string) random_int( 0, (int) ( 10 ** self::CODE_LENGTH ) - 1 ), self::CODE_LENGTH, '0', STR_PAD_LEFT );
	}

	/**
	 * Deja solo los dígitos que escribió la persona (admite espacios y guiones).
	 *
	 * @param string $raw Texto escrito.
	 * @return string
	 */
	public static function normalize_code( $raw ) {
		return substr( (string) preg_replace( '/\D+/', '', (string) $raw ), 0, self::CODE_LENGTH );
	}

	/**
	 * HMAC del código, atado al inquilino para que no sirva con otro.
	 *
	 * @param string $code     Código en claro.
	 * @param int    $guest_id Inquilino.
	 * @return string
	 */
	public static function hash_code( $code, $guest_id ) {
		return hash_hmac( 'sha256', absint( $guest_id ) . '|' . $code, wp_salt( 'auth' ) . 'af_tenant_otp_v1' );
	}

	/**
	 * Comparación en tiempo constante.
	 *
	 * @param string $code     Código escrito.
	 * @param string $hash     Hash guardado.
	 * @param int    $guest_id Inquilino.
	 * @return bool
	 */
	public static function code_matches( $code, $hash, $guest_id ) {
		return self::CODE_LENGTH === strlen( (string) $code ) && hash_equals( (string) $hash, self::hash_code( $code, $guest_id ) );
	}

	/**
	 * @param string $expires_at Fecha UTC 'Y-m-d H:i:s'.
	 * @param int    $now        Marca de tiempo actual.
	 * @return bool
	 */
	public static function is_expired( $expires_at, $now = 0 ) {
		$ts = strtotime( (string) $expires_at . ' UTC' );

		return false === $ts || $ts <= ( $now ? $now : time() );
	}

	/**
	 * "juan.perez@gmail.com" → "j•••••@gmail.com".
	 *
	 * @param string $email Correo.
	 * @return string
	 */
	public static function mask_email( $email ) {
		$parts = explode( '@', (string) $email, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] ) {
			return '';
		}

		return mb_substr( $parts[0], 0, 1 ) . str_repeat( '•', max( 3, min( 6, mb_strlen( $parts[0] ) - 1 ) ) ) . '@' . $parts[1];
	}

	/**
	 * Referencia firmada de un inquilino para el enlace que envía el gestor.
	 * Solo permite pedir un código al correo YA registrado: no abre nada.
	 *
	 * @param int $guest_id Inquilino.
	 * @return string
	 */
	public static function sign_ref( $guest_id ) {
		$guest_id = absint( $guest_id );

		return $guest_id . '.' . substr( hash_hmac( 'sha256', 'ref|' . $guest_id, wp_salt( 'auth' ) . 'af_tenant_ref_v1' ), 0, 24 );
	}

	/**
	 * @param string $ref Referencia recibida.
	 * @return int ID del inquilino o 0 si la firma no es válida.
	 */
	public static function parse_ref( $ref ) {
		if ( 1 !== preg_match( '/^(\d{1,12})\.([a-f0-9]{24})$/', (string) $ref, $m ) ) {
			return 0;
		}
		$guest_id = (int) $m[1];

		return hash_equals( self::sign_ref( $guest_id ), $guest_id . '.' . $m[2] ) ? $guest_id : 0;
	}

	/**
	 * Token CSRF de una sesión (el token de sesión nunca llega al JS).
	 *
	 * @param string $session_hash Hash guardado de la sesión.
	 * @return string
	 */
	public static function csrf_for( $session_hash ) {
		return substr( hash_hmac( 'sha256', 'csrf|' . $session_hash, wp_salt( 'nonce' ) . 'af_tenant_csrf_v1' ), 0, 32 );
	}

	/**
	 * Etiquetas de estado de un cargo.
	 *
	 * @return array<string,string>
	 */
	public static function charge_status_labels() {
		return array(
			'pending' => __( 'Pendiente', 'arriendo-facil' ),
			'partial' => __( 'Pago parcial', 'arriendo-facil' ),
			'paid'    => __( 'Pagado', 'arriendo-facil' ),
			'overdue' => __( 'Vencido', 'arriendo-facil' ),
			'void'    => __( 'Anulado', 'arriendo-facil' ),
		);
	}

	/**
	 * Enlace al portal. Con $guest_id lleva la referencia firmada.
	 *
	 * @param int $guest_id Inquilino (opcional).
	 * @return string
	 */
	public static function portal_url( $guest_id = 0 ) {
		$url = home_url( '/' . self::SLUG . '/' );

		return $guest_id ? add_query_arg( 'r', rawurlencode( self::sign_ref( $guest_id ) ), $url ) : $url;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Esquema
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * @return string
	 */
	public static function otp_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_tenant_otps';
	}

	/**
	 * @return string
	 */
	public static function session_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_tenant_sessions';
	}

	/**
	 * Crea las tablas una sola vez por versión.
	 */
	public function maybe_install() {
		if ( get_option( 'af_tenant_access_db_version' ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::otp_table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				guest_id BIGINT UNSIGNED NOT NULL,
				code_hash CHAR(64) NOT NULL,
				attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
				expires_at DATETIME NOT NULL,
				consumed_at DATETIME DEFAULT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY guest_id (guest_id),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . self::session_table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				guest_id BIGINT UNSIGNED NOT NULL,
				token_hash CHAR(64) NOT NULL,
				created_at DATETIME NOT NULL,
				expires_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY guest_id (guest_id),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		update_option( 'af_tenant_access_db_version', self::DB_VERSION, false );
	}

	/**
	 * Limpieza diaria de códigos y sesiones vencidos.
	 */
	public function purge_expired() {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::otp_table() . ' WHERE expires_at < %s', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::session_table() . ' WHERE expires_at < %s', $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Rutas y plantilla
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * /inquilino/ → index.php?af_tenant_portal=1
	 */
	public function register_routes() {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Regenera las reglas una sola vez cuando falta la ruta.
	 */
	public function maybe_flush_rules() {
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) && isset( $rules[ '^' . self::SLUG . '/?$' ] ) ) {
			return;
		}
		flush_rewrite_rules( false );
	}

	/**
	 * @return bool
	 */
	private static function is_portal_request() {
		return '' !== (string) get_query_var( self::QUERY_VAR );
	}

	/**
	 * Sin caché, sin indexar y sin filtrar el enlace por Referer.
	 */
	public function send_privacy_headers() {
		if ( ! self::is_portal_request() ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' );
	}

	/**
	 * @param string $template Plantilla actual.
	 * @return string
	 */
	public function load_template( $template ) {
		if ( ! self::is_portal_request() ) {
			return $template;
		}

		return ARRIENDO_FACIL_PLUGIN_DIR . 'public/tenant-portal.php';
	}

	/**
	 * Estilos y script de la pantalla.
	 */
	public function enqueue_assets() {
		if ( ! self::is_portal_request() ) {
			return;
		}

		$css = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-tenant-portal.css';
		$js  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-tenant-portal.js';

		wp_enqueue_style( 'af-tenant-portal', ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-tenant-portal.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : ARRIENDO_FACIL_VERSION );
		wp_enqueue_script( 'af-tenant-portal', ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-tenant-portal.js', array(), file_exists( $js ) ? (string) filemtime( $js ) : ARRIENDO_FACIL_VERSION, true );

		$session = self::current_session();
		$ref     = isset( $_GET['r'] ) ? sanitize_text_field( wp_unslash( $_GET['r'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- referencia firmada, no una acción.
		$guest   = ! $session && $ref ? self::guest_by_id( self::parse_ref( $ref ) ) : null;

		wp_localize_script(
			'af-tenant-portal',
			'afTenantPortal',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'ref'        => $guest ? $ref : '',
				'maskedMail' => $guest ? self::mask_email( $guest->email ) : '',
				'csrf'       => $session ? self::csrf_for( $session->token_hash ) : '',
				'resendWait' => self::RESEND_WAIT,
				'codeLength' => self::CODE_LENGTH,
				'i18n'       => array(
					'sending'     => __( 'Enviando…', 'arriendo-facil' ),
					'verifying'   => __( 'Verificando…', 'arriendo-facil' ),
					'sentTo'      => __( 'Enviamos un código de 6 dígitos a %s. Vence en 10 minutos.', 'arriendo-facil' ),
					'sentGeneric' => __( 'Si el correo está registrado, te enviamos un código de 6 dígitos. Vence en 10 minutos.', 'arriendo-facil' ),
					'resendIn'    => __( 'Reenviar código en %ss', 'arriendo-facil' ),
					'resend'      => __( 'Reenviar código', 'arriendo-facil' ),
					'badEmail'    => __( 'Escribe un correo válido.', 'arriendo-facil' ),
					'badCode'     => __( 'Escribe los 6 dígitos del código.', 'arriendo-facil' ),
					'network'     => __( 'No pudimos conectar. Revisa tu internet e inténtalo de nuevo.', 'arriendo-facil' ),
					'reported'    => __( 'Novedad enviada. Tu administrador ya la puede ver.', 'arriendo-facil' ),
					'sendReport'  => __( 'Enviar novedad', 'arriendo-facil' ),
				),
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Utilidades
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Rangos publicados por Cloudflare (https://www.cloudflare.com/ips/). Filtrable con `af_trusted_proxy_ranges`.
	 *
	 * @return string[]
	 */
	private static function cloudflare_ranges() {
		$ranges = array(
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
			'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
			'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
			'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
			'2a06:98c0::/29', '2c0f:f248::/32',
		);

		return (array) apply_filters( 'af_trusted_proxy_ranges', $ranges );
	}

	/**
	 * @param string   $ip     IP a comprobar.
	 * @param string[] $ranges CIDR.
	 * @return bool
	 */
	public static function ip_in_ranges( $ip, array $ranges ) {
		$bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- IP inválida devuelve false.
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			$parts = explode( '/', (string) $range );
			$base  = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $base || strlen( $base ) !== strlen( $bin ) || ! isset( $parts[1] ) ) {
				continue;
			}
			$bits  = (int) $parts[1];
			$bytes = intdiv( $bits, 8 );
			$rest  = $bits % 8;
			if ( substr( $bin, 0, $bytes ) !== substr( $base, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
			if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $base[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * IP real del visitante: con Cloudflare delante solo se confía en CF-Connecting-IP
	 * si la conexión llega desde un rango de Cloudflare; si no, REMOTE_ADDR.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $remote && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::ip_in_ranges( $remote, self::cloudflare_ranges() ) ) {
			$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}

		return $remote;
	}

	/**
	 * @return string Hash corto de la IP (no se guarda la IP).
	 */
	private static function ip_key() {
		return md5( self::client_ip() );
	}

	/**
	 * Cuenta un intento en una ventana. True cuando ya se superó el tope.
	 *
	 * @param string $bucket Nombre del contador.
	 * @param string $key    Clave (IP, correo…).
	 * @param int    $limit  Máximo en la ventana.
	 * @param int    $window Segundos de la ventana.
	 * @return bool
	 */
	private static function throttled( $bucket, $key, $limit, $window ) {
		$name  = 'af_tp_' . $bucket . '_' . md5( $key );
		$count = (int) get_transient( $name );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $name, $count + 1, $window );

		return false;
	}

	/**
	 * @param int $guest_id Inquilino.
	 * @return object|null
	 */
	private static function guest_by_id( $guest_id ) {
		global $wpdb;
		$guest_id = absint( $guest_id );
		if ( ! $guest_id ) {
			return null;
		}
		Arriendo_Facil_Guest::ensure_guest_extra_columns();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d AND deleted_at IS NULL LIMIT 1", $guest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param string $email Correo.
	 * @return object|null
	 */
	private static function guest_by_email( $email ) {
		global $wpdb;
		if ( ! is_email( $email ) ) {
			return null;
		}
		Arriendo_Facil_Guest::ensure_guest_extra_columns();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}af_guests WHERE email = %s AND deleted_at IS NULL LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Resuelve el inquilino de la petición: referencia firmada o correo escrito.
	 *
	 * @return array{guest:object|null,email:string,by_ref:bool}
	 */
	private static function guest_from_request() {
		$ref   = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- endpoint público con límites propios.
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' !== $ref ) {
			$guest = self::guest_by_id( self::parse_ref( $ref ) );

			return array( 'guest' => $guest, 'email' => $guest ? (string) $guest->email : '', 'by_ref' => true );
		}

		return array( 'guest' => self::guest_by_email( strtolower( $email ) ), 'email' => strtolower( $email ), 'by_ref' => false );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * AJAX público: pedir / validar código, cerrar sesión
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Pide un código. Siempre responde igual para no revelar qué correos existen.
	 */
	public function ajax_request() {
		$who   = self::guest_from_request();
		$email = $who['email'];

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'code' => 'invalid_email', 'message' => __( 'Escribe un correo válido.', 'arriendo-facil' ) ), 400 );
		}

		if ( self::throttled( 'req_ip', self::ip_key(), 12, HOUR_IN_SECONDS ) || self::throttled( 'req_mail', $email, 5, HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'code' => 'rate_limited', 'message' => __( 'Hiciste demasiadas solicitudes. Espera un rato e inténtalo de nuevo.', 'arriendo-facil' ) ), 429 );
		}

		$response = array(
			'resend_in' => self::RESEND_WAIT,
			'masked'    => self::mask_email( $email ),
			'message'   => __( 'Si el correo está registrado, te enviamos un código de 6 dígitos.', 'arriendo-facil' ),
		);

		$guest = $who['guest'];
		if ( ! $guest ) {
			wp_send_json_success( $response ); // Misma respuesta: no se revela que no existe.
		}

		global $wpdb;
		$otp_table = self::otp_table();
		$last      = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$otp_table} WHERE guest_id = %d ORDER BY id DESC LIMIT 1", (int) $guest->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $last ) {
			$wait = self::RESEND_WAIT - ( time() - (int) strtotime( $last . ' UTC' ) );
			if ( $wait > 0 ) {
				$response['resend_in'] = $wait; // Aún no se reenvía: se respeta la espera.
				wp_send_json_success( $response );
			}
		}

		// Un solo código vigente por inquilino.
		$wpdb->delete( $otp_table, array( 'guest_id' => (int) $guest->id ), array( '%d' ) );

		$code = self::new_code();
		$wpdb->insert(
			$otp_table,
			array(
				'guest_id'   => (int) $guest->id,
				'code_hash'  => self::hash_code( $code, (int) $guest->id ),
				'attempts'   => 0,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		if ( ! self::send_code_email( $guest, $code ) ) {
			$wpdb->delete( $otp_table, array( 'guest_id' => (int) $guest->id ), array( '%d' ) );
			wp_send_json_error( array( 'code' => 'mail_failed', 'message' => __( 'No pudimos enviar el correo. Inténtalo de nuevo en unos minutos.', 'arriendo-facil' ) ), 500 );
		}

		wp_send_json_success( $response );
	}

	/**
	 * @param object $guest Inquilino.
	 * @param string $code  Código en claro.
	 * @return bool
	 */
	private static function send_code_email( $guest, $code ) {
		$name = trim( (string) $guest->first_name );
		$box  = '<p style="margin:8px 0 20px;text-align:center;"><span style="display:inline-block;padding:14px 26px;border-radius:12px;background:#eef5f3;border:1px solid #c2deda;font:700 32px/1.1 ui-monospace,Menlo,Consolas,monospace;letter-spacing:10px;color:#0b2f37;">' . esc_html( $code ) . '</span></p>';

		return Arriendo_Facil_Email::send(
			(string) $guest->email,
			__( 'Tu código de acceso', 'arriendo-facil' ),
			array(
				'preheader' => sprintf( /* translators: %s: code */ __( 'Tu código es %s. Vence en 10 minutos.', 'arriendo-facil' ), $code ),
				'title'     => __( 'Tu código de acceso', 'arriendo-facil' ),
				'greeting'  => $name ? sprintf( /* translators: %s: first name */ __( 'Hola %s,', 'arriendo-facil' ), $name ) : __( 'Hola,', 'arriendo-facil' ),
				'body_text' => __( 'Usa este código para entrar a tu portal de inquilino. Vence en 10 minutos y solo funciona una vez.', 'arriendo-facil' ),
				'content'   => $box,
				'notice'    => array(
					'type' => 'warning',
					'text' => __( 'Si no lo pediste tú, ignora este mensaje: nadie puede entrar sin el código. No lo compartas con nadie.', 'arriendo-facil' ),
				),
			)
		);
	}

	/**
	 * Valida el código y abre la sesión.
	 */
	public function ajax_verify() {
		$who  = self::guest_from_request();
		$code = self::normalize_code( isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se reduce a dígitos.

		if ( self::CODE_LENGTH !== strlen( $code ) ) {
			wp_send_json_error( array( 'code' => 'invalid_code', 'message' => __( 'Escribe los 6 dígitos del código.', 'arriendo-facil' ) ), 400 );
		}

		if ( self::throttled( 'ver_ip', self::ip_key(), 30, HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'code' => 'rate_limited', 'message' => __( 'Demasiados intentos. Espera un rato e inténtalo de nuevo.', 'arriendo-facil' ) ), 429 );
		}

		$generic = array( 'code' => 'expired', 'message' => __( 'El código venció o no es válido. Pide uno nuevo.', 'arriendo-facil' ) );
		$guest   = $who['guest'];
		if ( ! $guest ) {
			wp_send_json_error( $generic, 400 );
		}

		global $wpdb;
		$otp_table = self::otp_table();
		$otp       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$otp_table} WHERE guest_id = %d AND consumed_at IS NULL ORDER BY id DESC LIMIT 1", (int) $guest->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $otp || self::is_expired( $otp->expires_at ) ) {
			if ( $otp ) {
				$wpdb->delete( $otp_table, array( 'id' => (int) $otp->id ), array( '%d' ) );
			}
			wp_send_json_error( $generic, 400 );
		}

		if ( (int) $otp->attempts >= self::MAX_ATTEMPTS ) {
			$wpdb->delete( $otp_table, array( 'id' => (int) $otp->id ), array( '%d' ) );
			wp_send_json_error( array( 'code' => 'locked', 'message' => __( 'Demasiados intentos con este código. Pide uno nuevo.', 'arriendo-facil' ) ), 429 );
		}

		// Se cuenta el intento ANTES de comparar: dos peticiones simultáneas no duplican oportunidades.
		$wpdb->query( $wpdb->prepare( "UPDATE {$otp_table} SET attempts = attempts + 1 WHERE id = %d", (int) $otp->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! self::code_matches( $code, (string) $otp->code_hash, (int) $guest->id ) ) {
			$left = max( 0, self::MAX_ATTEMPTS - ( (int) $otp->attempts + 1 ) );
			if ( 0 === $left ) {
				$wpdb->delete( $otp_table, array( 'id' => (int) $otp->id ), array( '%d' ) );
			}
			wp_send_json_error(
				array(
					'code'      => $left ? 'invalid_code' : 'locked',
					'attempts'  => $left,
					'message'   => $left
						? sprintf( /* translators: %d: attempts left */ _n( 'Código incorrecto. Te queda %d intento.', 'Código incorrecto. Te quedan %d intentos.', $left, 'arriendo-facil' ), $left )
						: __( 'Demasiados intentos con este código. Pide uno nuevo.', 'arriendo-facil' ),
				),
				$left ? 400 : 429
			);
		}

		// Un código solo sirve una vez.
		$consumed = $wpdb->query( $wpdb->prepare( "UPDATE {$otp_table} SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL", gmdate( 'Y-m-d H:i:s' ), (int) $otp->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $consumed ) {
			wp_send_json_error( $generic, 400 );
		}

		self::open_session( (int) $guest->id );
		wp_send_json_success( array( 'redirect' => self::portal_url() ) );
	}

	/**
	 * Cierra la sesión actual.
	 */
	public function ajax_logout() {
		$session = self::current_session();
		if ( $session ) {
			if ( ! self::csrf_ok( $session ) ) {
				wp_send_json_error( array( 'message' => __( 'Sesión no válida. Recarga la página.', 'arriendo-facil' ) ), 403 );
			}
			global $wpdb;
			$wpdb->delete( self::session_table(), array( 'id' => (int) $session->id ), array( '%d' ) );
		}
		self::set_cookie( '', time() - YEAR_IN_SECONDS );
		wp_send_json_success( array( 'redirect' => self::portal_url() ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Sesión
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * @param string $value   Valor de la cookie.
	 * @param int    $expires Caducidad (0 = al cerrar el navegador).
	 */
	private static function set_cookie( $value, $expires = 0 ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		if ( '' === $value ) {
			unset( $_COOKIE[ self::COOKIE ] );
		} else {
			$_COOKIE[ self::COOKIE ] = $value;
		}
	}

	/**
	 * @param int $guest_id Inquilino autenticado.
	 */
	private static function open_session( $guest_id ) {
		global $wpdb;
		$token = bin2hex( random_bytes( 32 ) );

		// Una sesión nueva sustituye a las anteriores del mismo inquilino.
		$wpdb->delete( self::session_table(), array( 'guest_id' => absint( $guest_id ) ), array( '%d' ) );
		$wpdb->insert(
			self::session_table(),
			array(
				'guest_id'   => absint( $guest_id ),
				'token_hash' => hash( 'sha256', $token ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::SESSION_IDLE ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		self::set_cookie( $token );
	}

	/**
	 * Sesión vigente de la petición, renovando la inactividad.
	 *
	 * @return object|null Fila con id, guest_id, token_hash.
	 */
	public static function current_session() {
		static $cache = array();

		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $raw ) ) {
			return null;
		}
		if ( array_key_exists( $raw, $cache ) ) {
			return $cache[ $raw ];
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::session_table() . ' WHERE token_hash = %s LIMIT 1', hash( 'sha256', $raw ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$valid = $row
			&& ! self::is_expired( $row->expires_at )
			&& ( time() - (int) strtotime( $row->created_at . ' UTC' ) ) < self::SESSION_MAX
			&& self::guest_by_id( (int) $row->guest_id );

		if ( ! $valid ) {
			if ( $row ) {
				$wpdb->delete( self::session_table(), array( 'id' => (int) $row->id ), array( '%d' ) );
			}
			$cache[ $raw ] = null;

			return null;
		}

		$wpdb->update( self::session_table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::SESSION_IDLE ) ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
		$cache[ $raw ] = $row;

		return $row;
	}

	/**
	 * @param object $session Sesión.
	 * @return bool
	 */
	private static function csrf_ok( $session ) {
		$sent = isset( $_POST['csrf'] ) ? sanitize_text_field( wp_unslash( $_POST['csrf'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- es el propio token CSRF de la sesión.

		return '' !== $sent && hash_equals( self::csrf_for( $session->token_hash ), $sent );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Datos del portal (siempre filtrados por el inquilino de la sesión)
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Perfil, contratos, pagos y novedades de UN inquilino.
	 *
	 * @param int $guest_id Inquilino de la sesión.
	 * @return array<string,mixed>|null
	 */
	public static function portal_data( $guest_id ) {
		global $wpdb;
		$guest = self::guest_by_id( $guest_id );
		if ( ! $guest ) {
			return null;
		}

		$leases = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.accommodation_id, l.start_date, l.end_date, l.monthly_rent, l.status, l.deposit_amount, l.payment_due_day, p.post_title AS property
				 FROM {$wpdb->prefix}af_leases l
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
				 WHERE l.guest_id = %d AND l.deleted_at IS NULL
				 ORDER BY l.start_date DESC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $guest->id
			)
		);
		$lease_ids = array_map( 'intval', wp_list_pluck( $leases, 'id' ) );

		$charges  = array();
		$payments = array();
		$requests = array();

		if ( $lease_ids ) {
			$in = implode( ',', $lease_ids ); // Enteros ya convertidos.

			$charges = (array) $wpdb->get_results(
				"SELECT id, lease_id, charge_type, period, description, amount, amount_paid, due_date, status
				 FROM {$wpdb->prefix}af_charges
				 WHERE lease_id IN ({$in}) AND status <> 'void'
				 ORDER BY period DESC, id DESC LIMIT 60" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);

			$payments = (array) $wpdb->get_results(
				"SELECT pay.amount, pay.payment_date, pay.method, pay.reference, c.period, c.charge_type
				 FROM {$wpdb->prefix}af_payments pay
				 INNER JOIN {$wpdb->prefix}af_charges c ON c.id = pay.charge_id
				 WHERE c.lease_id IN ({$in})
				 ORDER BY pay.payment_date DESC, pay.id DESC LIMIT 40" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);

			$requests = (array) $wpdb->get_results(
				'SELECT id, accommodation_id, request_type, priority, status, requested_date, scheduled_date, damage_details, asset_location
				 FROM ' . Arriendo_Facil_Maintenance::table() . "
				 WHERE lease_id IN ({$in})
				 ORDER BY id DESC LIMIT 30" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}

		$balance = 0.0;
		foreach ( $charges as $charge ) {
			$balance += max( 0.0, (float) $charge->amount - (float) $charge->amount_paid );
		}

		return array(
			'guest'    => $guest,
			'leases'   => $leases,
			'charges'  => $charges,
			'payments' => $payments,
			'requests' => $requests,
			'balance'  => round( $balance, 2 ),
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Novedad de mantenimiento desde el portal
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Registra una novedad. Exige sesión, CSRF y que el contrato sea del inquilino.
	 */
	public function ajax_report() {
		$session = self::current_session();
		if ( ! $session || ! self::csrf_ok( $session ) ) {
			wp_send_json_error( array( 'code' => 'session', 'message' => __( 'Tu sesión venció. Vuelve a entrar con un código.', 'arriendo-facil' ) ), 401 );
		}

		global $wpdb;
		$guest_id = (int) $session->guest_id;

		if ( self::throttled( 'report', (string) $guest_id, self::REPORTS_PER_DAY, DAY_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Llegaste al máximo de novedades por hoy. Contacta a tu administrador.', 'arriendo-facil' ) ), 429 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		$lease    = $lease_id ? $wpdb->get_row(
			$wpdb->prepare( "SELECT id, accommodation_id, status FROM {$wpdb->prefix}af_leases WHERE id = %d AND guest_id = %d AND deleted_at IS NULL LIMIT 1", $lease_id, $guest_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		) : null;

		if ( ! $lease ) {
			wp_send_json_error( array( 'message' => __( 'Elige uno de tus contratos.', 'arriendo-facil' ) ), 400 );
		}

		$types    = array( 'reparacion', 'emergencia', 'limpieza', 'otro' );
		$type     = isset( $_POST['request_type'] ) ? sanitize_key( wp_unslash( $_POST['request_type'] ) ) : '';
		$priority = isset( $_POST['priority'] ) ? sanitize_key( wp_unslash( $_POST['priority'] ) ) : 'media';
		$details  = isset( $_POST['damage_details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['damage_details'] ) ) : '';
		$where    = isset( $_POST['asset_location'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_location'] ) ) : '';

		if ( ! in_array( $type, $types, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Elige el tipo de novedad.', 'arriendo-facil' ) ), 400 );
		}
		if ( ! array_key_exists( $priority, Arriendo_Facil_Maintenance::priorities() ) ) {
			$priority = 'media';
		}
		if ( 'emergencia' === $type ) {
			$priority = 'alta';
		}
		$length = mb_strlen( $details );
		if ( $length < 10 || $length > 1000 ) {
			wp_send_json_error( array( 'message' => __( 'Describe la novedad en 10 a 1000 caracteres.', 'arriendo-facil' ) ), 400 );
		}

		$id = Arriendo_Facil_Maintenance::create(
			array(
				'accommodation_id' => (int) $lease->accommodation_id,
				'lease_id'         => (int) $lease->id,
				'request_type'     => $type,
				'priority'         => $priority,
				'reported_by'      => 'inquilino',
				'damage_details'   => $details,
				'asset_location'   => mb_substr( $where, 0, 120 ),
				'notes'            => __( 'Reportado por el inquilino desde su portal.', 'arriendo-facil' ),
			)
		);

		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 500 );
		}

		self::notify_manager_of_report( (int) $lease->accommodation_id, $details );
		wp_send_json_success( array( 'id' => (int) $id ) );
	}

	/**
	 * Avisa por correo al administrador del inmueble.
	 *
	 * @param int    $accommodation_id Inmueble.
	 * @param string $details          Texto de la novedad.
	 */
	private static function notify_manager_of_report( $accommodation_id, $details ) {
		$owner = get_userdata( (int) get_post_meta( $accommodation_id, '_af_owner_id', true ) );
		if ( ! $owner || ! is_email( $owner->user_email ) ) {
			return;
		}

		Arriendo_Facil_Email::send(
			$owner->user_email,
			__( 'Nueva novedad de mantenimiento', 'arriendo-facil' ),
			array(
				'title'     => __( 'Un inquilino reportó una novedad', 'arriendo-facil' ),
				'body_text' => sprintf( /* translators: 1: property, 2: details */ __( 'Inmueble: %1$s' . "\n\n" . '%2$s', 'arriendo-facil' ), get_the_title( $accommodation_id ), wp_trim_words( $details, 40 ) ),
				'button'    => array(
					'label' => __( 'Ver en Mantenimiento', 'arriendo-facil' ),
					'url'   => admin_url( 'admin.php?page=af-maintenance' ),
				),
			),
			Arriendo_Facil_Email::CAT_NOTIFICATION
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Gestor: enviar el acceso al inquilino
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * El gestor envía (por correo) o copia el enlace de acceso de un inquilino suyo.
	 */
	public function ajax_send_portal_link() {
		check_ajax_referer( 'af_guest_edit_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para esta acción.', 'arriendo-facil' ) ), 403 );
		}

		$guest_id = isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0;
		if ( ! $guest_id || ! Arriendo_Facil_Tenancy::can_access_guest( $guest_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este inquilino.', 'arriendo-facil' ) ), 403 );
		}

		$guest = self::guest_by_id( $guest_id );
		if ( ! $guest || ! is_email( $guest->email ) ) {
			wp_send_json_error( array( 'message' => __( 'El inquilino no tiene un correo válido.', 'arriendo-facil' ) ), 400 );
		}

		$url   = self::portal_url( $guest_id );
		$email = isset( $_POST['channel'] ) && 'email' === sanitize_key( wp_unslash( $_POST['channel'] ) );

		if ( $email ) {
			$sent = Arriendo_Facil_Email::send(
				$guest->email,
				__( 'Tu acceso al portal de inquilino', 'arriendo-facil' ),
				array(
					'title'     => __( 'Tu portal de inquilino', 'arriendo-facil' ),
					'greeting'  => sprintf( /* translators: %s: first name */ __( 'Hola %s,', 'arriendo-facil' ), $guest->first_name ),
					'body_text' => __( 'Desde tu portal puedes ver tu perfil, tu historial de pagos y reportar novedades de mantenimiento. No necesitas contraseña: te enviaremos un código a este correo cada vez que entres.', 'arriendo-facil' ),
					'button'    => array(
						'label' => __( 'Entrar a mi portal', 'arriendo-facil' ),
						'url'   => $url,
					),
				)
			);
			if ( ! $sent ) {
				wp_send_json_error( array( 'message' => __( 'No se pudo enviar el correo.', 'arriendo-facil' ) ), 500 );
			}
		}

		wp_send_json_success(
			array(
				'url'     => $url,
				'emailed' => $email,
				'message' => $email ? __( 'Acceso enviado por correo.', 'arriendo-facil' ) : __( 'Enlace listo para compartir.', 'arriendo-facil' ),
			)
		);
	}
}
