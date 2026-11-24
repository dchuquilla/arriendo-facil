<?php
/**
 * Consentimientos legales obligatorios (doble casilla + modal) con registro de respaldo.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Consent {

	const DOC_VERSION = '2026-11-v1';
	const DB_VERSION  = '1';

	private static $assets_printed = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'af_consents';
	}

	public static function maybe_install() {
		if ( get_option( 'af_consents_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				context VARCHAR(40) NOT NULL,
				subject_type VARCHAR(30) NOT NULL DEFAULT '',
				subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				doc_version VARCHAR(30) NOT NULL,
				text_hash CHAR(64) NOT NULL,
				accepted_read TINYINT(1) NOT NULL DEFAULT 0,
				accepted_agree TINYINT(1) NOT NULL DEFAULT 0,
				ip_address VARCHAR(45) NOT NULL DEFAULT '',
				user_agent VARCHAR(255) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_ctx (user_id, context),
				KEY subject (subject_type, subject_id)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'af_consents_db_version', self::DB_VERSION, false );
	}

	/** Textos por contexto: título, motivo (modal) y declaración de la casilla 2. */
	private static function contexts() {
		$facilitator = __( 'Arriendo Fácil es únicamente un facilitador administrativo y tecnológico: no es parte de los contratos, no asume responsabilidad solidaria ni subsidiaria por ellos y no responde por los datos o documentos que usted registre.', 'arriendo-facil' );
		return array(
			'admin_signup'  => array(
				'title' => __( 'Registro de administrador', 'arriendo-facil' ),
				'why'   => __( 'Al crear su cuenta usted gestionará contratos, inquilinos y facturación de terceros. Por eso debemos dejar constancia de que conoce y acepta las condiciones del servicio y su responsabilidad como responsable del tratamiento de esos datos.', 'arriendo-facil' ) . ' ' . $facilitator,
				'agree' => __( 'Estoy de acuerdo con los Términos y Condiciones y la Política de Privacidad, y acepto que soy el único responsable de los datos, contratos y documentos que gestione.', 'arriendo-facil' ),
			),
			'tenant_signup' => array(
				'title' => __( 'Registro de inquilino', 'arriendo-facil' ),
				'why'   => __( 'Sus datos personales serán tratados para gestionar su arrendamiento. Necesitamos su autorización expresa y su declaración de que la información entregada es veraz.', 'arriendo-facil' ),
				'agree' => __( 'Estoy de acuerdo con el tratamiento de mis datos personales y declaro que la información que proporciono es veraz y de mi responsabilidad.', 'arriendo-facil' ),
			),
			'tenant_prereg' => array(
				'title' => __( 'Datos de contacto del interesado', 'arriendo-facil' ),
				'why'   => __( 'Compartirás tu nombre y datos de contacto con el administrador del inmueble para que pueda comunicarse contigo y enviarte el formulario completo. Solo se usarán para gestionar tu posible arriendo.', 'arriendo-facil' ),
				'agree' => __( 'Autorizo que mis datos de contacto se compartan con el administrador del inmueble y declaro que la información que proporciono es veraz.', 'arriendo-facil' ),
			),
			'contract'      => array(
				'title' => __( 'Creación de contrato', 'arriendo-facil' ),
				'why'   => __( 'El contrato se genera a partir de los datos y plantillas que usted proporciona. Debe revisarlo y, de ser necesario, consultarlo con un abogado. ', 'arriendo-facil' ) . $facilitator,
				'agree' => __( 'Estoy de acuerdo en que el contenido del contrato y los datos de las partes son de mi exclusiva responsabilidad, y que Arriendo Fácil no es parte ni responde solidariamente por él.', 'arriendo-facil' ),
			),
			'tenant_record' => array(
				'title' => __( 'Registro de inquilino por el administrador', 'arriendo-facil' ),
				'why'   => __( 'Usted registrará datos personales y documentos de un tercero. Debe contar con su consentimiento y tratarlos solo para la gestión del arriendo. Usted actúa como responsable del tratamiento y Arriendo Fácil como encargado.', 'arriendo-facil' ),
				'agree' => __( 'Estoy de acuerdo en que cuento con el consentimiento del inquilino y que la información registrada es de mi exclusiva responsabilidad, no de Arriendo Fácil.', 'arriendo-facil' ),
			),
			'billing'       => array(
				'title' => __( 'Configuración de facturación', 'arriendo-facil' ),
				'why'   => __( 'La facturación usa su RUC, certificado de firma y datos tributarios. La emisión de comprobantes y el cumplimiento ante el SRI son su responsabilidad. ', 'arriendo-facil' ) . $facilitator,
				'agree' => __( 'Estoy de acuerdo en que la información tributaria, los certificados y los comprobantes que gestiono son de mi exclusiva responsabilidad, y que Arriendo Fácil no responde por su contenido ni por obligaciones ante el SRI.', 'arriendo-facil' ),
			),
		);
	}

	private static function text_hash( $context ) {
		$c = self::contexts()[ $context ] ?? array();
		return hash( 'sha256', self::DOC_VERSION . '|' . wp_json_encode( $c ) );
	}

	private static function legal_url( $slug ) {
		$page = get_page_by_path( $slug );
		return $page instanceof WP_Post ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
	}

	/** Imprime las dos casillas obligatorias y el modal informativo. */
	public static function render( $context ) {
		$all = self::contexts();
		if ( ! isset( $all[ $context ] ) ) {
			return;
		}
		$c   = $all[ $context ];
		$id  = 'af-consent-' . $context . '-' . wp_rand( 100, 999 );
		$tos = self::legal_url( 'terminos-y-condiciones' );
		$prv = self::legal_url( 'politica-de-privacidad' );

		self::print_assets();
		?>
		<fieldset class="af-consent" data-af-consent>
			<legend class="screen-reader-text"><?php echo esc_html( $c['title'] ); ?></legend>
			<label class="af-consent__row">
				<input type="checkbox" name="consent_read" value="1" required />
				<span>
					<?php esc_html_e( 'Declaro que he leído y comprendido la información sobre esta aceptación.', 'arriendo-facil' ); ?>
					<button type="button" class="af-consent__link" data-af-consent-open="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Ver por qué se solicita', 'arriendo-facil' ); ?></button>
				</span>
			</label>
			<label class="af-consent__row">
				<input type="checkbox" name="consent_agree" value="1" required />
				<span><?php echo esc_html( $c['agree'] ); ?></span>
			</label>
			<input type="hidden" name="consent_context" value="<?php echo esc_attr( $context ); ?>" />
			<dialog class="af-consent__modal" id="<?php echo esc_attr( $id ); ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>-t">
				<h3 id="<?php echo esc_attr( $id ); ?>-t"><?php echo esc_html( $c['title'] ); ?></h3>
				<p><?php echo esc_html( $c['why'] ); ?></p>
				<p class="af-consent__meta">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: 1: terms url, 2: privacy url */
							__( 'Documentos completos: <a href="%1$s" target="_blank" rel="noopener noreferrer">Términos y Condiciones</a> · <a href="%2$s" target="_blank" rel="noopener noreferrer">Política de Privacidad</a>.', 'arriendo-facil' ),
							esc_url( $tos ),
							esc_url( $prv )
						)
					);
					?>
				</p>
				<p class="af-consent__meta"><?php esc_html_e( 'Guardamos la fecha, versión del texto, IP y navegador de su aceptación como constancia.', 'arriendo-facil' ); ?></p>
				<button type="button" class="af-consent__close" data-af-consent-close><?php esc_html_e( 'Entendido', 'arriendo-facil' ); ?></button>
			</dialog>
		</fieldset>
		<?php
	}

	/** True si las dos casillas fueron marcadas. */
	public static function is_given() {
		return ! empty( $_POST['consent_read'] ) && ! empty( $_POST['consent_agree'] ); // phpcs:ignore WordPress.Security.NonceVerification -- el nonce lo valida el llamador.
	}

	public static function error_message() {
		return __( 'Debe marcar ambas declaraciones (haber leído la información y estar de acuerdo) para continuar.', 'arriendo-facil' );
	}

	/** Guarda la constancia vinculada al usuario gestor. */
	public static function record( $context, $user_id, $subject_type = '', $subject_id = 0 ) {
		global $wpdb;
		self::maybe_install();
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		return (bool) $wpdb->insert(
			self::table(),
			array(
				'user_id'        => absint( $user_id ),
				'context'        => sanitize_key( $context ),
				'subject_type'   => sanitize_key( $subject_type ),
				'subject_id'     => absint( $subject_id ),
				'doc_version'    => self::DOC_VERSION,
				'text_hash'      => self::text_hash( $context ),
				'accepted_read'  => 1,
				'accepted_agree' => 1,
				'ip_address'     => substr( $ip, 0, 45 ),
				'user_agent'     => $ua,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	private static function print_assets() {
		if ( self::$assets_printed ) {
			return;
		}
		self::$assets_printed = true;
		?>
		<style>
			.af-consent{margin:16px 0;padding:12px 14px;border:1px solid #d5e0e3;border-radius:10px;background:#f6fafb;color:#2e4046;font-size:13px;line-height:1.5;min-width:0}
			.af-consent__row{display:flex;align-items:flex-start;gap:10px;margin:0 0 10px;cursor:pointer}
			.af-consent__row:last-of-type{margin-bottom:0}
			.af-consent__row input{flex:0 0 auto;margin:2px 0 0}
			.af-consent__link{background:none;border:0;padding:0;margin-left:4px;color:#164a55;text-decoration:underline;cursor:pointer;font:inherit}
			.af-consent__link:focus-visible,.af-consent__close:focus-visible{outline:2px solid #164a55;outline-offset:2px}
			.af-consent__modal{width:min(92vw,520px);border:0;border-radius:14px;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.3);color:#2e4046;line-height:1.55}
			.af-consent__modal::backdrop{background:rgba(10,25,30,.55)}
			.af-consent__modal h3{margin:0 0 10px;font-size:18px}
			.af-consent__meta{font-size:12.5px;color:#5a6c72}
			.af-consent__close{margin-top:8px;padding:9px 18px;border:0;border-radius:8px;background:#164a55;color:#fff;cursor:pointer;min-height:40px}
			@media (max-width:600px){.af-consent{font-size:12.5px;padding:10px 12px}.af-consent__modal{padding:18px}}
		</style>
		<script>
			document.addEventListener('click',function(e){
				var o=e.target.closest('[data-af-consent-open]');
				if(o){e.preventDefault();var d=document.getElementById(o.getAttribute('data-af-consent-open'));if(d&&d.showModal){d.showModal();}return;}
				if(e.target.closest('[data-af-consent-close]')){var m=e.target.closest('dialog');if(m){m.close();}return;}
				if(e.target.tagName==='DIALOG'){e.target.close();}
			});
		</script>
		<?php
	}
}

Arriendo_Facil_Consent::init();
