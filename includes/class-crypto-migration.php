<?php
/**
 * Administrator-run re-encryption: moves every value protected with the salt-derived keys
 * to the dedicated master key (AF_ENCRYPTION_KEY) and re-keys identity fingerprints.
 *
 * Nothing runs automatically. The administrator must confirm that a database backup and a
 * copy of the key exist. Each value is converted only after it was decrypted with the old
 * key, sealed with the new one, and opened again to confirm it matches; any value that fails
 * a step is left untouched. The tool is idempotent and works in batches, so it can be pressed
 * repeatedly until nothing is pending.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Arriendo_Facil_Crypto' ) ) {
	require_once __DIR__ . '/class-crypto.php';
}

/**
 * Class Arriendo_Facil_Crypto_Migration
 */
class Arriendo_Facil_Crypto_Migration {

	const ACTION = 'af_rekey_crypto';
	const NONCE  = 'af_rekey_crypto';
	const BATCH  = 200;

	/**
	 * User meta holding 'v1:' sodium payloads: key => [legacy slug, purpose].
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private static $meta_fields = array(
		'af_admin_phone_enc'      => array( 'af_admin_sensitive_v1', Arriendo_Facil_Crypto::PURPOSE_ADMIN ),
		'af_admin_id_number_enc'  => array( 'af_admin_sensitive_v1', Arriendo_Facil_Crypto::PURPOSE_ADMIN ),
		'af_tenant_phone_enc'     => array( 'af_tenant_sensitive_v1', Arriendo_Facil_Crypto::PURPOSE_TENANT ),
		'af_tenant_id_number_enc' => array( 'af_tenant_sensitive_v1', Arriendo_Facil_Crypto::PURPOSE_TENANT ),
	);

	/**
	 * Fields of the SRI configuration option that hold protected secrets.
	 *
	 * @var string[]
	 */
	private static $sri_fields = array( 'cert_password_enc', 'cert_pem_enc', 'pkey_pem_enc', 'chain_pem_enc' );

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Values still protected with the old salt-derived keys, per group.
	 *
	 * @return array<string,int>
	 */
	public static function pending() {
		global $wpdb;

		$p      = $wpdb->prefix;
		$counts = array();

		$keys = "'" . implode( "','", array_map( 'esc_sql', array_keys( self::$meta_fields ) ) ) . "'";
		$counts['usermeta'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN ({$keys}) AND meta_value LIKE 'v1:%'" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$counts['guests'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}af_guests WHERE phone_encrypted LIKE 'v1:%' OR id_number_encrypted LIKE 'v1:%'" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$counts['sri_config'] = 0;
		$config               = (array) get_option( 'af_sri_config', array() );
		foreach ( self::$sri_fields as $field ) {
			if ( ! empty( $config[ $field ] ) && ! Arriendo_Facil_Crypto::is_sealed( (string) $config[ $field ] ) ) {
				++$counts['sri_config'];
			}
		}

		$counts['invoices'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}af_electronic_invoices WHERE xml_firmado REGEXP '^ENC[12]:' OR xml_autorizacion REGEXP '^ENC[12]:'" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$counts['sri_log'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}af_sri_log WHERE request_payload REGEXP '^ENC[12]:' OR response_payload REGEXP '^ENC[12]:'" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$counts['ota'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}af_ota_credentials WHERE api_key_encrypted <> '' AND api_key_encrypted NOT LIKE 'ENC3:%'" ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $counts;
	}

	/**
	 * Admin notices for platform administrators.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['af_rekey_done'] ) ) {
			$done   = absint( wp_unslash( $_GET['af_rekey_done'] ) );
			$failed = isset( $_GET['af_rekey_failed'] ) ? absint( wp_unslash( $_GET['af_rekey_failed'] ) ) : 0;
			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
				$failed ? 'notice-warning' : 'notice-success',
				esc_html(
					sprintf(
						/* translators: 1: converted, 2: skipped */
						__( 'Re-cifrado: %1$d valores convertidos a la clave propia; %2$d no se pudieron convertir y se conservaron sin cambios.', 'arriendo-facil' ),
						$done,
						$failed
					)
				)
			);
		}
		if ( isset( $_GET['af_rekey_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'No se re-cifró: confirma la casilla de respaldo y que AF_ENCRYPTION_KEY esté definida y sea válida.', 'arriendo-facil' ) . '</p></div>';
		}
		// phpcs:enable

		$problem = Arriendo_Facil_Crypto::master_problem();
		if ( '' !== $problem ) {
			echo '<div class="notice notice-error"><p><strong>Arriendo Fácil:</strong> ' . esc_html( $problem ) . '</p></div>';
			return;
		}

		if ( ! Arriendo_Facil_Crypto::has_master() ) {
			// Only nag on the dashboard and plugin pages to avoid noise everywhere.
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && ( 'dashboard' === $screen->id || false !== strpos( (string) $screen->id, 'arriendo' ) ) ) {
				echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Cifrado de datos sensibles', 'arriendo-facil' ) . '</strong> — ';
				echo esc_html__( 'Las claves de cifrado se derivan de las salts de wp-config.php. Define AF_ENCRYPTION_KEY (64 caracteres hexadecimales, guardada también en un gestor de contraseñas) para usar una clave propia y luego usa la herramienta de re-cifrado.', 'arriendo-facil' );
				echo '</p></div>';
			}
			return;
		}

		$pending = self::pending();
		$total   = array_sum( $pending );
		if ( $total <= 0 ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Re-cifrado pendiente', 'arriendo-facil' ) . '</strong> — ';
		echo esc_html(
			sprintf(
				/* translators: %d: number of values */
				_n( 'Hay %d valor protegido con las salts de WordPress.', 'Hay %d valores protegidos con las salts de WordPress.', $total, 'arriendo-facil' ),
				$total
			)
		);
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<p><label><input type="checkbox" name="af_rekey_backup_ok" value="1" /> ';
		echo esc_html__( 'Confirmo que hice un respaldo reciente de la base de datos y que guardé una copia de AF_ENCRYPTION_KEY fuera del servidor.', 'arriendo-facil' );
		echo '</label></p><p><button type="submit" class="button button-primary">' . esc_html__( 'Re-cifrar con la clave propia', 'arriendo-facil' ) . '</button></p></form></div>';
	}

	/**
	 * Handles the request.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permiso denegado.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		if ( empty( $_POST['af_rekey_backup_ok'] ) || ! Arriendo_Facil_Crypto::has_master() ) {
			wp_safe_redirect( add_query_arg( 'af_rekey_error', 1, admin_url( 'index.php' ) ) );
			exit;
		}

		$result = self::run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'af_rekey_done'   => $result['converted'],
					'af_rekey_failed' => $result['failed'],
				),
				admin_url( 'index.php' )
			)
		);
		exit;
	}

	/**
	 * Converts one batch of every group.
	 *
	 * @return array{converted:int,failed:int}
	 */
	public static function run() {
		global $wpdb;

		$p         = $wpdb->prefix;
		$converted = 0;
		$failed    = 0;

		// 1. User meta ('v1:' sodium payloads) and the identity fingerprints that go with them.
		$keys = "'" . implode( "','", array_map( 'esc_sql', array_keys( self::$meta_fields ) ) ) . "'";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ({$keys}) AND meta_value LIKE 'v1:%%' LIMIT %d", self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $row ) {
			list( $slug, $purpose ) = self::$meta_fields[ $row->meta_key ];
			$sealed                 = Arriendo_Facil_Crypto::rekey_legacy_v1( (string) $row->meta_value, $slug, $purpose );
			if ( '' === $sealed ) {
				++$failed;
				continue;
			}

			$ok = $wpdb->update( $wpdb->usermeta, array( 'meta_value' => $sealed ), array( 'umeta_id' => (int) $row->umeta_id ), array( '%s' ), array( '%d' ) );
			if ( false === $ok ) {
				++$failed;
				continue;
			}

			++$converted;
			if ( in_array( $row->meta_key, array( 'af_admin_id_number_enc', 'af_tenant_id_number_enc' ), true ) ) {
				$converted += self::rekey_fingerprint( (int) $row->user_id, $row->meta_key, $sealed, $purpose );
			}
		}
		wp_cache_flush();

		// 2. Guest table.
		$guests = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, phone_encrypted, id_number_encrypted FROM {$p}af_guests WHERE phone_encrypted LIKE 'v1:%%' OR id_number_encrypted LIKE 'v1:%%' LIMIT %d", self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $guests as $guest ) {
			$update = array();
			foreach ( array( 'phone_encrypted', 'id_number_encrypted' ) as $col ) {
				if ( 0 !== strpos( (string) $guest->$col, 'v1:' ) ) {
					continue;
				}
				$sealed = Arriendo_Facil_Crypto::rekey_legacy_v1( (string) $guest->$col, 'af_tenant_sensitive_v1', Arriendo_Facil_Crypto::PURPOSE_TENANT );
				if ( '' === $sealed ) {
					++$failed;
					continue;
				}
				$update[ $col ] = $sealed;
			}
			if ( $update ) {
				if ( false === $wpdb->update( $p . 'af_guests', $update, array( 'id' => (int) $guest->id ) ) ) {
					++$failed;
				} else {
					$converted += count( $update );
				}
			}
		}

		// 3. SRI configuration option.
		$config  = (array) get_option( 'af_sri_config', array() );
		$changed = false;
		foreach ( self::$sri_fields as $field ) {
			if ( empty( $config[ $field ] ) || Arriendo_Facil_Crypto::is_sealed( (string) $config[ $field ] ) ) {
				continue;
			}
			$sealed = Arriendo_Facil_SRI_Config::rekey_stored( (string) $config[ $field ] );
			if ( '' === $sealed ) {
				++$failed;
				continue;
			}
			$config[ $field ] = $sealed;
			$changed          = true;
			++$converted;
		}
		if ( $changed ) {
			update_option( 'af_sri_config', $config );
		}

		// 4. Stored invoice XML and SRI log payloads.
		$converted += self::rekey_text_columns( $p . 'af_electronic_invoices', 'id', array( 'xml_firmado', 'xml_autorizacion' ), $failed );
		$converted += self::rekey_text_columns( $p . 'af_sri_log', 'id', array( 'request_payload', 'response_payload' ), $failed );

		// 5. OTA credentials.
		$ota = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, api_key_encrypted FROM {$p}af_ota_credentials WHERE api_key_encrypted <> '' AND api_key_encrypted NOT LIKE 'ENC3:%%' LIMIT %d", self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $ota as $row ) {
			$sealed = Arriendo_Facil_OTA_Credentials::rekey_stored( (string) $row->api_key_encrypted );
			if ( '' === $sealed ) {
				++$failed;
				continue;
			}
			if ( false === $wpdb->update( $p . 'af_ota_credentials', array( 'api_key_encrypted' => $sealed ), array( 'id' => (int) $row->id ) ) ) {
				++$failed;
			} else {
				++$converted;
			}
		}

		return array(
			'converted' => $converted,
			'failed'    => $failed,
		);
	}

	/**
	 * Re-keys one batch of LONGTEXT columns protected as ENC1/ENC2.
	 *
	 * @param string   $table   Table name.
	 * @param string   $id_col  Primary key column.
	 * @param string[] $columns Columns to convert.
	 * @param int      $failed  Failure counter (by reference).
	 * @return int Converted values.
	 */
	private static function rekey_text_columns( $table, $id_col, array $columns, &$failed ) {
		global $wpdb;

		$where = array();
		foreach ( $columns as $col ) {
			$where[] = "{$col} REGEXP '^ENC[12]:'";
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$id_col}, " . implode( ',', $columns ) . " FROM {$table} WHERE " . implode( ' OR ', $where ) . ' LIMIT %d', self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$done = 0;
		foreach ( $rows as $row ) {
			$update = array();
			foreach ( $columns as $col ) {
				$value = (string) $row->$col;
				if ( 1 !== preg_match( '/^ENC[12]:/', $value ) ) {
					continue;
				}
				$sealed = Arriendo_Facil_SRI_Config::rekey_stored( $value );
				if ( '' === $sealed ) {
					++$failed;
					continue;
				}
				$update[ $col ] = $sealed;
			}
			if ( $update ) {
				if ( false === $wpdb->update( $table, $update, array( $id_col => (int) $row->$id_col ) ) ) {
					++$failed;
				} else {
					$done += count( $update );
				}
			}
		}

		return $done;
	}

	/**
	 * Recomputes the identity fingerprint of a user whose document number was just re-keyed.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Document-number meta key.
	 * @param string $sealed   Newly sealed document number.
	 * @param string $purpose  Purpose it was sealed for.
	 * @return int 1 when the fingerprint changed.
	 */
	private static function rekey_fingerprint( $user_id, $meta_key, $sealed, $purpose ) {
		$is_admin = 'af_admin_id_number_enc' === $meta_key;
		$fp_key   = $is_admin ? 'af_admin_id_fingerprint' : 'af_tenant_id_fingerprint';
		$current  = (string) get_user_meta( $user_id, $fp_key, true );
		$plain    = Arriendo_Facil_Crypto::open( $sealed, $purpose );
		if ( '' === $current || false === $plain ) {
			return 0;
		}

		$normalized = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $plain ) );
		if ( '' === $normalized ) {
			return 0;
		}

		$new = Arriendo_Facil_Crypto::fingerprint(
			$normalized,
			$is_admin ? 'admin-id-fp' : 'tenant-id-fp',
			wp_salt( 'secure_auth' ) . ( $is_admin ? 'af_admin_id_fp_v1' : 'af_tenant_id_fp_v1' )
		);
		if ( hash_equals( $current, $new ) ) {
			return 0;
		}

		update_user_meta( $user_id, $fp_key, $new );

		return 1;
	}
}
