<?php
/**
 * Pre-registro de interesados vinculado a un gestor de propiedades.
 *
 * Cada gestor tiene un enlace público propio (/preregistro/<clave>/). Quien lo
 * abre deja nombre, correo y teléfono (más el inmueble que le interesa) y
 * queda como "interesado" de ESE gestor. No se crea todavía ningún inquilino:
 * los datos públicos viven aparte hasta que el gestor decide continuar.
 *
 * Flujo:
 *  1. Interesado → pre-registro (también desde el botón de WhatsApp del
 *     catálogo público). Al terminar se abre WhatsApp hacia el gestor con un
 *     mensaje listo para pedirle el enlace.
 *  2. Gestor → "Enviar formulario completo": se crea la ficha del inquilino a
 *     su nombre y se genera el enlace seguro de un solo uso (el mismo del
 *     perfil legal), que envía por WhatsApp o correo.
 *  3. Inquilino completa el formulario → el interesado pasa a "Completado".
 *  4. Gestor revisa los datos/documentos y lo marca como "Verificado".
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Tenant_Prereg
 */
class Arriendo_Facil_Tenant_Prereg {

	const QUERY_VAR  = 'af_tenant_prereg';
	const SLUG       = 'preregistro';
	const KEY_META   = 'af_prereg_key';
	const DB_VERSION = '1';
	const NONCE      = 'af_prereg_nonce';
	const FORM_PATH  = '/completar-perfil-arriendo/';

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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 40 ); // Después del tema, para reutilizar su GSAP.

		add_action( 'wp_ajax_nopriv_af_tenant_prereg_submit', array( $this, 'ajax_submit' ) );
		add_action( 'wp_ajax_af_tenant_prereg_submit', array( $this, 'ajax_submit' ) );
		add_action( 'wp_ajax_af_prereg_invite', array( $this, 'ajax_invite' ) );
		add_action( 'wp_ajax_af_prereg_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_af_prereg_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_af_prereg_regenerate_key', array( $this, 'ajax_regenerate_key' ) );
		add_action( 'af_guest_profile_completed', array( __CLASS__, 'mark_completed' ), 10, 1 );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Piezas puras
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Estados del interesado.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'new'       => __( 'Nuevo', 'arriendo-facil' ),
			'invited'   => __( 'Formulario enviado', 'arriendo-facil' ),
			'completed' => __( 'Completó el formulario', 'arriendo-facil' ),
			'verified'  => __( 'Verificado', 'arriendo-facil' ),
			'discarded' => __( 'Descartado', 'arriendo-facil' ),
		);
	}

	/**
	 * Nombre o apellido válido: letras (con tildes), espacios, punto, guion, apóstrofo.
	 *
	 * @param string $name Texto.
	 * @return bool
	 */
	public static function valid_name( $name ) {
		return 1 === preg_match( '/^[\p{L}\p{M}][\p{L}\p{M}\s\'.\-]{1,79}$/u', (string) $name );
	}

	/**
	 * Teléfono → solo dígitos (7 a 15), conservando un + inicial si lo había.
	 *
	 * @param string $phone Texto escrito.
	 * @return string Vacío si no es válido.
	 */
	public static function normalize_phone( $phone ) {
		$phone  = trim( (string) $phone );
		$plus   = 0 === strpos( $phone, '+' ) ? '+' : '';
		$digits = (string) preg_replace( '/\D+/', '', $phone );

		return ( strlen( $digits ) >= 7 && strlen( $digits ) <= 15 ) ? $plus . $digits : '';
	}

	/**
	 * Firma anti-bot del formulario (marca de tiempo).
	 *
	 * @param int $ts Marca de tiempo.
	 * @return string
	 */
	public static function form_sig( $ts ) {
		return hash_hmac( 'sha256', 'prereg|' . (int) $ts, wp_salt( 'nonce' ) . 'af_prereg_form_v1' );
	}

	/**
	 * ¿Es la misma persona? Compara sin tildes ni mayúsculas y tolera que uno de los
	 * nombres traiga menos palabras ("Ana Pérez" vs "Ana María Pérez Loor").
	 *
	 * @param string $a Nombre completo.
	 * @param string $b Nombre completo.
	 * @return bool
	 */
	public static function same_person( $a, $b ) {
		$words = static function ( $name ) {
			$name = function_exists( 'remove_accents' ) ? remove_accents( (string) $name ) : (string) $name;

			return array_values( array_filter( preg_split( '/[^a-z0-9]+/', strtolower( $name ) ) ) );
		};

		$wa = $words( $a );
		$wb = $words( $b );
		if ( ! $wa || ! $wb ) {
			return false;
		}

		$short = count( $wa ) <= count( $wb ) ? $wa : $wb;
		$long  = count( $wa ) <= count( $wb ) ? $wb : $wa;

		return ! array_diff( $short, $long );
	}

	/**
	 * Firma del inmueble elegido en el catálogo: con ella el formulario lo muestra
	 * fijo y el servidor rechaza cualquier otro.
	 *
	 * @param int $manager_id Gestor.
	 * @param int $property   Inmueble.
	 * @return string
	 */
	public static function property_sig( $manager_id, $property ) {
		return hash_hmac( 'sha256', 'prop|' . absint( $manager_id ) . '|' . absint( $property ), wp_salt( 'nonce' ) . 'af_prereg_prop_v1' );
	}

	/**
	 * El formulario no se acepta ni demasiado rápido (bot) ni demasiado viejo.
	 *
	 * @param int    $ts  Marca de tiempo enviada.
	 * @param string $sig Firma enviada.
	 * @param int    $now Ahora.
	 * @return bool
	 */
	public static function form_fresh( $ts, $sig, $now = 0 ) {
		$now = $now ? $now : time();
		$ts  = (int) $ts;

		return hash_equals( self::form_sig( $ts ), (string) $sig ) && ( $now - $ts ) >= 3 && ( $now - $ts ) <= 2 * HOUR_IN_SECONDS;
	}

	/**
	 * Mensaje que el interesado envía al gestor al terminar el pre-registro.
	 *
	 * @param string $name     Nombre del interesado.
	 * @param string $property Inmueble (opcional).
	 * @return string
	 */
	public static function interested_message( $name, $property = '' ) {
		if ( '' !== $property ) {
			/* translators: 1: name, 2: property */
			return sprintf( __( 'Hola, soy %1$s. Acabo de dejar mis datos para el inmueble "%2$s". ¿Me envías el enlace para completar mi información?', 'arriendo-facil' ), $name, $property );
		}

		/* translators: %s: name */
		return sprintf( __( 'Hola, soy %s. Acabo de dejar mis datos. ¿Me envías el enlace para completar mi información?', 'arriendo-facil' ), $name );
	}

	/**
	 * Mensaje que el gestor envía al interesado con el formulario completo.
	 *
	 * @param string $name Nombre del interesado.
	 * @param string $url  Enlace de un solo uso.
	 * @return string
	 */
	public static function manager_message( $name, $url ) {
		/* translators: 1: name, 2: url */
		return sprintf( __( 'Hola %1$s, gracias por tu interés. Completa tu información en este enlace seguro (vence en 72 horas): %2$s', 'arriendo-facil' ), $name, $url );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Esquema, rutas y plantilla
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'af_tenant_leads';
	}

	/**
	 * Crea la tabla una sola vez por versión.
	 */
	public function maybe_install() {
		self::install();
	}

	/**
	 * Instala la tabla si falta (se puede llamar sin construir la clase).
	 */
	private static function install() {
		if ( get_option( 'af_tenant_prereg_db_version' ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				manager_id BIGINT UNSIGNED NOT NULL,
				accommodation_id BIGINT UNSIGNED DEFAULT NULL,
				first_name VARCHAR(100) NOT NULL,
				last_name VARCHAR(100) NOT NULL,
				email VARCHAR(190) NOT NULL,
				phone VARCHAR(20) NOT NULL,
				note VARCHAR(300) DEFAULT NULL,
				source VARCHAR(20) NOT NULL DEFAULT 'link',
				status VARCHAR(20) NOT NULL DEFAULT 'new',
				guest_id BIGINT UNSIGNED DEFAULT NULL,
				invited_at DATETIME DEFAULT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY manager_status (manager_id, status),
				KEY manager_email (manager_id, email),
				KEY guest_id (guest_id)
			) {$wpdb->get_charset_collate()};"
		);

		update_option( 'af_tenant_prereg_db_version', self::DB_VERSION, false );
	}

	/**
	 * /preregistro/<clave>/ → index.php?af_tenant_prereg=<clave>
	 */
	public function register_routes() {
		add_rewrite_rule( '^' . self::SLUG . '/([a-z0-9]{24})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
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
		if ( is_array( $rules ) && isset( $rules[ '^' . self::SLUG . '/([a-z0-9]{24})/?$' ] ) ) {
			return;
		}
		flush_rewrite_rules( false );
	}

	/**
	 * @return bool
	 */
	private static function is_prereg_request() {
		return '' !== (string) get_query_var( self::QUERY_VAR );
	}

	/**
	 * La página lleva una clave en la URL: no se indexa ni se filtra por Referer.
	 */
	public function send_privacy_headers() {
		if ( ! self::is_prereg_request() ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' );
	}

	/**
	 * Carga el contexto del gestor y la plantilla.
	 *
	 * @param string $template Plantilla actual.
	 * @return string
	 */
	public function load_template( $template ) {
		if ( ! self::is_prereg_request() ) {
			return $template;
		}

		$manager = self::manager_by_key( sanitize_key( (string) get_query_var( self::QUERY_VAR ) ) );

		$GLOBALS['af_prereg'] = array(
			'manager'    => $manager,
			'properties' => $manager ? self::public_properties( (int) $manager->ID ) : array(),
			'selected'   => $manager ? self::requested_property( (int) $manager->ID ) : 0,
		);
		$GLOBALS['af_prereg']['locked'] = $GLOBALS['af_prereg']['selected'] > 0;

		return ARRIENDO_FACIL_PLUGIN_DIR . 'public/tenant-prereg.php';
	}

	/**
	 * Estilos (los del portal) y script.
	 */
	public function enqueue_assets() {
		if ( ! self::is_prereg_request() ) {
			return;
		}

		$css = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-tenant-prereg.css';
		$js  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/af-tenant-prereg.js';

		wp_enqueue_style( 'af-tenant-prereg', ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-tenant-prereg.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : ARRIENDO_FACIL_VERSION );

		// GSAP viene del tema (local, sin CDN): si no está, la página funciona igual sin movimiento.
		$deps = wp_script_is( 'afx-gsap', 'registered' ) ? array( 'afx-gsap' ) : array();
		wp_enqueue_script( 'af-tenant-prereg', ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/af-tenant-prereg.js', $deps, file_exists( $js ) ? (string) filemtime( $js ) : ARRIENDO_FACIL_VERSION, true );
		wp_localize_script(
			'af-tenant-prereg',
			'afTenantPrereg',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'sending'  => __( 'Enviando…', 'arriendo-facil' ),
					'send'     => __( 'Enviar mis datos', 'arriendo-facil' ),
					'network'  => __( 'No pudimos conectar. Revisa tu internet e inténtalo de nuevo.', 'arriendo-facil' ),
					'opening'  => __( 'Abriendo WhatsApp…', 'arriendo-facil' ),
					'errName'     => __( 'Escribe tu nombre (solo letras).', 'arriendo-facil' ),
					'errLast'     => __( 'Escribe tu apellido (solo letras).', 'arriendo-facil' ),
					'errEmail'    => __( 'Escribe un correo válido, como nombre@correo.com.', 'arriendo-facil' ),
					'errPhone'    => __( 'Escribe un teléfono válido (7 a 15 dígitos).', 'arriendo-facil' ),
					'errProperty' => __( 'Selecciona el inmueble que te interesa.', 'arriendo-facil' ),
					'errConsent'  => __( 'Marca las dos casillas para continuar.', 'arriendo-facil' ),
				),
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Clave del gestor
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Clave pública del gestor (la crea la primera vez).
	 *
	 * @param int  $user_id Gestor.
	 * @param bool $create  Crearla si no existe.
	 * @return string
	 */
	public static function manager_key( $user_id, $create = true ) {
		$user_id = absint( $user_id );
		$key     = (string) get_user_meta( $user_id, self::KEY_META, true );

		if ( 1 === preg_match( '/^[a-z0-9]{24}$/', $key ) ) {
			return $key;
		}
		if ( ! $create || ! $user_id ) {
			return '';
		}

		return self::rotate_key( $user_id );
	}

	/**
	 * Genera una clave nueva (la anterior deja de funcionar).
	 *
	 * @param int $user_id Gestor.
	 * @return string
	 */
	public static function rotate_key( $user_id ) {
		do {
			$key = strtolower( wp_generate_password( 24, false, false ) );
		} while ( 1 !== preg_match( '/^[a-z0-9]{24}$/', $key ) );

		update_user_meta( absint( $user_id ), self::KEY_META, $key );

		return $key;
	}

	/**
	 * @param string $key Clave de la URL.
	 * @return WP_User|null Solo gestores con permiso de operar inmuebles.
	 */
	public static function manager_by_key( $key ) {
		if ( 1 !== preg_match( '/^[a-z0-9]{24}$/', (string) $key ) ) {
			return null;
		}

		$ids = get_users(
			array(
				'meta_key'   => self::KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		$id = $ids ? (int) $ids[0] : 0;

		return ( $id && user_can( $id, Arriendo_Facil_Tenancy::CAP ) ) ? get_userdata( $id ) : null;
	}

	/**
	 * Enlace público de pre-registro de un gestor.
	 *
	 * @param int $user_id  Gestor.
	 * @param int $property Inmueble (opcional).
	 * @param string $source 'link' o 'catalogo'.
	 * @return string
	 */
	public static function url( $user_id, $property = 0, $source = '' ) {
		$url  = home_url( '/' . self::SLUG . '/' . self::manager_key( $user_id ) . '/' );
		$args = array();
		if ( $property ) {
			$args['inmueble'] = absint( $property );
		}
		if ( 'catalogo' === $source ) {
			$args['origen'] = 'catalogo';
		}

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Inmuebles publicados del gestor que se ofrecen en el formulario.
	 *
	 * @param int $manager_id Gestor.
	 * @return array<int,string> id => título.
	 */
	public static function public_properties( $manager_id ) {
		$out = array();
		foreach ( array_slice( (array) Arriendo_Facil_Accommodation::get_owner_accommodation_ids( $manager_id ), 0, 100 ) as $id ) {
			if ( 'publish' === get_post_status( $id ) ) {
				$out[ (int) $id ] = get_the_title( $id );
			}
		}

		return $out;
	}

	/**
	 * Inmueble pedido en la URL, solo si es del gestor y está publicado.
	 *
	 * @param int $manager_id Gestor.
	 * @return int
	 */
	private static function requested_property( $manager_id ) {
		$id = isset( $_GET['inmueble'] ) ? absint( wp_unslash( $_GET['inmueble'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return ( $id && array_key_exists( $id, self::public_properties( $manager_id ) ) ) ? $id : 0;
	}

	/**
	 * Cuenta un intento en una ventana. True cuando ya se superó el tope.
	 *
	 * @param string $bucket Contador.
	 * @param string $key    Clave.
	 * @param int    $limit  Máximo.
	 * @param int    $window Segundos.
	 * @return bool
	 */
	private static function throttled( $bucket, $key, $limit, $window ) {
		$name  = 'af_pr_' . $bucket . '_' . md5( $key );
		$count = (int) get_transient( $name );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $name, $count + 1, $window );

		return false;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Público: enviar el pre-registro
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Recibe el pre-registro de un interesado.
	 */
	public function ajax_submit() {
		$manager = self::manager_by_key( isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- formulario público protegido con firma de tiempo, honeypot y límites.
		if ( ! $manager ) {
			wp_send_json_error( array( 'message' => __( 'Este enlace ya no está disponible. Pide uno nuevo al administrador.', 'arriendo-facil' ) ), 404 );
		}

		// Trampa para bots: se responde "ok" sin guardar nada.
		if ( ! empty( $_POST['website'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_send_json_success( array( 'name' => '', 'whatsapp_url' => '' ) );
		}

		$ts  = isset( $_POST['form_ts'] ) ? absint( wp_unslash( $_POST['form_ts'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$sig = isset( $_POST['form_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['form_sig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! self::form_fresh( $ts, $sig ) ) {
			wp_send_json_error( array( 'message' => __( 'El formulario venció. Recarga la página e inténtalo de nuevo.', 'arriendo-facil' ) ), 400 );
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( self::throttled( 'ip', $ip, 8, HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Hiciste demasiadas solicitudes. Espera un rato e inténtalo de nuevo.', 'arriendo-facil' ) ), 429 );
		}

		if ( ! Arriendo_Facil_Consent::is_given() ) {
			wp_send_json_error( array( 'message' => Arriendo_Facil_Consent::error_message() ), 400 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$first = isset( $_POST['first_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) ) : '';
		$last  = isset( $_POST['last_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) ) : '';
		$email = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
		$phone = self::normalize_phone( isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '' );
		$note  = '';
		$psig  = isset( $_POST['prop_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['prop_sig'] ) ) : '';
		$prop  = isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0;
		$from  = isset( $_POST['source'] ) && 'catalogo' === sanitize_key( wp_unslash( $_POST['source'] ) ) ? 'catalogo' : 'link';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! self::valid_name( $first ) || ! self::valid_name( $last ) ) {
			wp_send_json_error( array( 'message' => __( 'Escribe tu nombre y apellido (solo letras).', 'arriendo-facil' ) ), 400 );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Escribe un correo válido.', 'arriendo-facil' ) ), 400 );
		}
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'Escribe un teléfono válido (7 a 15 dígitos).', 'arriendo-facil' ) ), 400 );
		}
		// Elegido desde el catálogo: solo vale ese inmueble, sin importar lo que llegue en el formulario.
		if ( '' !== $psig && ! hash_equals( self::property_sig( (int) $manager->ID, $prop ), $psig ) ) {
			wp_send_json_error( array( 'message' => __( 'El inmueble no coincide con el que elegiste. Vuelve al catálogo e inténtalo de nuevo.', 'arriendo-facil' ) ), 400 );
		}
		if ( ! $prop || ! array_key_exists( $prop, self::public_properties( (int) $manager->ID ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Selecciona el inmueble que te interesa.', 'arriendo-facil' ) ), 400 );
		}

		if ( self::throttled( 'mail', $manager->ID . '|' . $email, 3, DAY_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Ya recibimos tus datos. El administrador se pondrá en contacto contigo.', 'arriendo-facil' ) ), 429 );
		}

		global $wpdb;
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE manager_id = %d AND email = %s ORDER BY id DESC LIMIT 1", (int) $manager->ID, $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$is_new   = true;

		if ( $existing && in_array( $existing->status, array( 'new', 'invited' ), true ) ) {
			// Mismo correo con el mismo gestor: se actualiza, no se duplica.
			$lead_id = (int) $existing->id;
			$is_new  = false;
			$wpdb->update(
				$table,
				array(
					'first_name'       => $first,
					'last_name'        => $last,
					'phone'            => $phone,
					'note'             => $note ? $note : null,
					'accommodation_id' => $prop,
					'updated_at'       => $now,
				),
				array( 'id' => $lead_id ),
				array( '%s', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
		} elseif ( $existing ) {
			$lead_id = (int) $existing->id; // Ya completó o fue verificado/descartado: no se reabre.
			$is_new  = false;
		} else {
			$wpdb->insert(
				$table,
				array(
					'manager_id'       => (int) $manager->ID,
					'accommodation_id' => $prop,
					'first_name'       => $first,
					'last_name'        => $last,
					'email'            => $email,
					'phone'            => $phone,
					'note'             => $note ? $note : null,
					'source'           => $from,
					'status'           => 'new',
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$lead_id = (int) $wpdb->insert_id;
		}

		if ( ! $lead_id ) {
			wp_send_json_error( array( 'message' => __( 'No pudimos guardar tus datos. Inténtalo de nuevo.', 'arriendo-facil' ) ), 500 );
		}

		if ( $is_new ) {
			Arriendo_Facil_Consent::record( 'tenant_prereg', (int) $manager->ID, 'tenant_lead', $lead_id );
			self::notify_manager( $manager, $first . ' ' . $last, $prop );
		}

		// WhatsApp del gestor con el mensaje listo (si tiene número válido).
		$wa      = Arriendo_Facil_Catalog_Share::whatsapp_link( (string) get_user_meta( (int) $manager->ID, 'af_contact_phone', true ) );
		$message = self::interested_message( $first, get_the_title( $prop ) );

		wp_send_json_success(
			array(
				'name'         => $first,
				'whatsapp_url' => $wa ? $wa . '?text=' . rawurlencode( $message ) : '',
			)
		);
	}

	/**
	 * Avisa al gestor de un interesado nuevo.
	 *
	 * @param WP_User $manager  Gestor.
	 * @param string  $name     Nombre del interesado.
	 * @param int     $property Inmueble (opcional).
	 */
	private static function notify_manager( $manager, $name, $property ) {
		if ( ! is_email( $manager->user_email ) ) {
			return;
		}

		Arriendo_Facil_Email::send(
			$manager->user_email,
			__( 'Nuevo interesado', 'arriendo-facil' ),
			array(
				'title'     => __( 'Tienes un nuevo interesado', 'arriendo-facil' ),
				'body_text' => sprintf( /* translators: 1: name, 2: property */ __( '%1$s dejó sus datos%2$s. Envíale el formulario completo desde Inquilinos → Interesados.', 'arriendo-facil' ), $name, $property ? sprintf( /* translators: %s: property */ __( ' para "%s"', 'arriendo-facil' ), get_the_title( $property ) ) : '' ),
				'button'    => array(
					'label' => __( 'Ver interesados', 'arriendo-facil' ),
					'url'   => admin_url( 'admin.php?page=af-guests' ),
				),
			),
			Arriendo_Facil_Email::CAT_NOTIFICATION
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Gestor: lista y acciones
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Interesados visibles para el usuario actual (cada gestor, solo los suyos).
	 *
	 * @param int $limit Máximo.
	 * @return array<int,object>
	 */
	public static function leads_for_current_user( $limit = 100 ) {
		global $wpdb;
		self::ensure_table();

		$scope = Arriendo_Facil_Tenancy::can_manage_all() ? '' : $wpdb->prepare( 'WHERE l.manager_id = %d', get_current_user_id() );

		return (array) $wpdb->get_results(
			'SELECT l.*, p.post_title AS property FROM ' . self::table() . " l
			 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
			 {$scope}
			 ORDER BY FIELD(l.status, 'new', 'completed', 'invited', 'verified', 'discarded'), l.id DESC
			 LIMIT " . absint( $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Garantiza la tabla cuando la pantalla se abre antes del hook init.
	 */
	private static function ensure_table() {
		self::install();
	}

	/**
	 * Interesado accesible para el usuario actual.
	 *
	 * @param int $lead_id Interesado.
	 * @return object|null
	 */
	private static function lead_for_current_user( $lead_id ) {
		global $wpdb;
		$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $lead_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $lead ) {
			return null;
		}

		return ( Arriendo_Facil_Tenancy::can_manage_all() || (int) $lead->manager_id === get_current_user_id() ) ? $lead : null;
	}

	/**
	 * Verificación común de las acciones del gestor.
	 */
	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para esta acción.', 'arriendo-facil' ) ), 403 );
		}
	}

	/**
	 * Crea la ficha del inquilino y genera el enlace del formulario completo.
	 */
	public function ajax_invite() {
		self::guard();

		global $wpdb;
		$lead = self::lead_for_current_user( isset( $_POST['lead_id'] ) ? absint( wp_unslash( $_POST['lead_id'] ) ) : 0 );
		if ( ! $lead || in_array( $lead->status, array( 'discarded', 'verified' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Este interesado ya no se puede invitar.', 'arriendo-facil' ) ), 404 );
		}

		$channel          = isset( $_POST['channel'] ) && 'email' === sanitize_key( wp_unslash( $_POST['channel'] ) ) ? 'email' : 'whatsapp';
		$accommodation_id = $lead->accommodation_id ? (int) $lead->accommodation_id : ( isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0 );

		if ( ! $accommodation_id || ! Arriendo_Facil_Tenancy::can_access_accommodation( $accommodation_id ) ) {
			wp_send_json_error( array( 'code' => 'need_property', 'message' => __( 'Elige el inmueble para el que se enviará el formulario.', 'arriendo-facil' ) ), 400 );
		}

		if ( 'whatsapp' === $channel && '' === Arriendo_Facil_Catalog_Share::whatsapp_link( $lead->phone ) ) {
			wp_send_json_error( array( 'message' => __( 'El teléfono no es válido para WhatsApp. Envíalo por correo.', 'arriendo-facil' ) ), 400 );
		}

		$schema = Arriendo_Facil_Guest::ensure_guest_extra_columns();
		if ( is_wp_error( $schema ) ) {
			wp_send_json_error( array( 'message' => $schema->get_error_message() ), 500 );
		}

		$manager_id = (int) $lead->manager_id;
		$guests     = $wpdb->prefix . 'af_guests';
		$guest      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$guests} WHERE email = %s LIMIT 1", $lead->email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $guest ) {
			// La ficha es global por correo: solo se reutiliza si ya es de este gestor.
			$mine = (int) $guest->created_by === $manager_id || Arriendo_Facil_Tenancy::can_access_guest( (int) $guest->id, $manager_id )
				|| ( ! empty( $guest->deleted_at ) && (int) $guest->accommodation_id && Arriendo_Facil_Tenancy::can_access_accommodation( (int) $guest->accommodation_id, $manager_id ) );
			if ( ! $mine ) {
				wp_send_json_error( array( 'message' => __( 'Este correo ya está registrado en otra cuenta. Pídele que use otro o contacta soporte.', 'arriendo-facil' ) ), 409 );
			}
			$lead_name  = trim( $lead->first_name . ' ' . $lead->last_name );
			$guest_name = trim( $guest->first_name . ' ' . $guest->last_name );
			$identified = '' !== trim( (string) $guest->id_number );

			// La ficha es global por correo. Si ya pertenece a OTRA persona con identificación, el
			// formulario le cambiaría sus datos: se frena y se le dice al gestor qué hacer.
			if ( $identified && ! self::same_person( $lead_name, $guest_name ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: 1: registered name, 2: lead name */
							__( 'Ese correo ya está registrado a nombre de "%1$s", no de "%2$s". Pídele a esta persona otro correo, o abre la ficha existente.', 'arriendo-facil' ),
							$guest_name,
							$lead_name
						),
					),
					409
				);
			}

			// Ficha sin completar (solo un marcador): se alinea con quien realmente recibe el enlace.
			if ( ! $identified ) {
				$wpdb->update(
					$guests,
					array( 'first_name' => $lead->first_name, 'last_name' => $lead->last_name, 'phone' => $lead->phone ),
					array( 'id' => (int) $guest->id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}

			if ( ! empty( $guest->deleted_at ) ) {
				$wpdb->update( $guests, array( 'deleted_at' => null, 'deleted_by' => null ), array( 'id' => (int) $guest->id ), array( '%s', '%s' ), array( '%d' ) );
			}
			$guest_id = (int) $guest->id;
		} else {
			if ( class_exists( 'Arriendo_Facil_Plans' ) && Arriendo_Facil_Plans::applies_to( $manager_id ) && Arriendo_Facil_Plans::limit_gate( 'guests', $manager_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Tu plan llegó al máximo de inquilinos. Mejora tu plan para continuar.', 'arriendo-facil' ) ), 409 );
			}
			$wpdb->insert(
				$guests,
				array(
					'first_name'       => $lead->first_name,
					'last_name'        => $lead->last_name,
					'email'            => $lead->email,
					'phone'            => $lead->phone,
					'id_number'        => '',
					'accommodation_id' => $accommodation_id,
					'created_by'       => $manager_id,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
			);
			$guest_id = (int) $wpdb->insert_id;
		}

		if ( ! $guest_id ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo crear la ficha del inquilino.', 'arriendo-facil' ) ), 500 );
		}

		$onboarding = new Arriendo_Facil_Guest_Onboarding();
		$token      = $onboarding->create_guest_onboarding_token( $guest_id, $accommodation_id, 0, $lead->email, 72 );
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( array( 'message' => $token->get_error_message() ), 500 );
		}

		$form_url = add_query_arg(
			array(
				'selector' => rawurlencode( $token['selector'] ),
				'token'    => rawurlencode( $token['token'] ),
			),
			home_url( self::FORM_PATH )
		);
		$name     = trim( $lead->first_name . ' ' . $lead->last_name );
		$response = array( 'channel' => $channel );

		if ( 'email' === $channel ) {
			if ( ! $onboarding->send_guest_legal_profile_link_email( $lead->email, $name, $form_url, $accommodation_id, $token['expires_at'] ) ) {
				wp_send_json_error( array( 'message' => __( 'No se pudo enviar el correo.', 'arriendo-facil' ) ), 500 );
			}
			$response['message'] = __( 'Formulario enviado por correo.', 'arriendo-facil' );
		} else {
			$response['whatsapp_url'] = Arriendo_Facil_Catalog_Share::whatsapp_link( $lead->phone ) . '?text=' . rawurlencode( self::manager_message( $lead->first_name, $form_url ) );
			$response['message']      = __( 'Abriendo WhatsApp con el mensaje listo.', 'arriendo-facil' );
		}

		$wpdb->update(
			self::table(),
			array(
				'status'           => 'invited',
				'guest_id'         => $guest_id,
				'accommodation_id' => $accommodation_id,
				'invited_at'       => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => (int) $lead->id ),
			array( '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		wp_send_json_success( $response );
	}

	/**
	 * Descartar o dar por verificado.
	 */
	public function ajax_status() {
		self::guard();

		global $wpdb;
		$lead   = self::lead_for_current_user( isset( $_POST['lead_id'] ) ? absint( wp_unslash( $_POST['lead_id'] ) ) : 0 );
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! $lead || ! in_array( $status, array( 'discarded', 'verified' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Acción no válida.', 'arriendo-facil' ) ), 400 );
		}
		// Solo se verifica a quien ya completó el formulario.
		if ( 'verified' === $status && 'completed' !== $lead->status ) {
			wp_send_json_error( array( 'message' => __( 'Aún no completó el formulario.', 'arriendo-facil' ) ), 400 );
		}

		$wpdb->update( self::table(), array( 'status' => $status, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $lead->id ), array( '%s', '%s' ), array( '%d' ) );
		wp_send_json_success( array( 'status' => $status ) );
	}

	/**
	 * Eliminar definitivamente a un interesado. Solo los descartados: no hay forma de deshacerlo.
	 */
	public function ajax_delete() {
		self::guard();

		global $wpdb;
		$lead = self::lead_for_current_user( isset( $_POST['lead_id'] ) ? absint( wp_unslash( $_POST['lead_id'] ) ) : 0 );

		if ( ! $lead ) {
			wp_send_json_error( array( 'message' => __( 'No encontramos a este interesado.', 'arriendo-facil' ) ), 404 );
		}
		if ( 'discarded' !== $lead->status ) {
			wp_send_json_error( array( 'message' => __( 'Solo se pueden eliminar los interesados descartados.', 'arriendo-facil' ) ), 409 );
		}

		$deleted = $wpdb->delete( self::table(), array( 'id' => (int) $lead->id, 'status' => 'discarded' ), array( '%d', '%s' ) );
		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo eliminar. Inténtalo de nuevo.', 'arriendo-facil' ) ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'Interesado eliminado definitivamente.', 'arriendo-facil' ) ) );
	}

	/**
	 * El gestor invalida su enlace público y obtiene uno nuevo.
	 */
	public function ajax_regenerate_key() {
		self::guard();

		self::rotate_key( get_current_user_id() );
		wp_send_json_success( array( 'url' => self::url( get_current_user_id() ) ) );
	}

	/**
	 * Cuando el inquilino termina el formulario, su interesado pasa a "completado".
	 *
	 * @param int $guest_id Inquilino.
	 */
	public static function mark_completed( $guest_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'completed', updated_at = %s WHERE guest_id = %d AND status = 'invited'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				gmdate( 'Y-m-d H:i:s' ),
				absint( $guest_id )
			)
		);
	}
}
