<?php
/**
 * One-time, administrator-triggered migration of identity documents (and RIDE invoice PDFs) that were
 * saved in public locations (older uploads, made when no private storage was configured) into the private local store.
 *
 * Nothing runs automatically. For each file the bytes are copied, the SHA-256 is compared
 * with the recorded checksum, the database reference is updated, and only then is the
 * public attachment deleted. A file that fails any step is left untouched.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Private_Storage_Migration
 */
class Arriendo_Facil_Private_Storage_Migration {

	const ACTION = 'af_migrate_private_documents';
	const NONCE  = 'af_migrate_private_documents';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_migration' ) );
	}

	/**
	 * Counts documents still sitting in the public media library.
	 *
	 * @return int
	 */
	public static function count_legacy() {
		global $wpdb;

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_guest_documents WHERE storage = 'local'" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$count += count( self::legacy_rides() );

		$users = (array) $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'af_admin_documents'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $users as $user_id ) {
			foreach ( (array) get_user_meta( (int) $user_id, 'af_admin_documents', true ) as $doc ) {
				if ( is_array( $doc ) && isset( $doc['storage'] ) && 'local' === $doc['storage'] ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Admin notice for platform administrators.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['af_private_migrated'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$moved  = absint( wp_unslash( $_GET['af_private_migrated'] ) );
			$failed = isset( $_GET['af_private_failed'] ) ? absint( wp_unslash( $_GET['af_private_failed'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
				$failed ? 'notice-warning' : 'notice-success',
				esc_html(
					sprintf(
						/* translators: 1: moved, 2: failed */
						__( 'Documentos movidos al almacenamiento privado: %1$d. Sin mover (se conservan sin cambios): %2$d.', 'arriendo-facil' ),
						$moved,
						$failed
					)
				)
			);
		}

		$pending = get_transient( 'af_private_legacy_count' );
		if ( false === $pending ) {
			$pending = self::count_legacy();
			set_transient( 'af_private_legacy_count', $pending, 5 * MINUTE_IN_SECONDS );
		}

		if ( ! $pending ) {
			$root = class_exists( 'Arriendo_Facil_Local_Private_Storage' ) ? Arriendo_Facil_Local_Private_Storage::root() : null;
			if ( $root && ! is_wp_error( $root ) && Arriendo_Facil_Local_Private_Storage::is_inside_web_root() && ! Arriendo_Facil_Private_Storage::is_configured() ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Los documentos sensibles se guardan en una carpeta dentro de wp-content. Define AF_PRIVATE_STORAGE_DIR con una ruta fuera del directorio web (o configura R2) para máxima protección.', 'arriendo-facil' ) . '</p></div>';
			}
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Documentos sensibles fuera del almacenamiento privado', 'arriendo-facil' ) . '</strong> — ';
		echo esc_html(
			sprintf(
				/* translators: %d: number of documents */
				_n( 'Hay %d documento guardado fuera del almacenamiento privado.', 'Hay %d documentos guardados fuera del almacenamiento privado.', (int) $pending, 'arriendo-facil' ),
				(int) $pending
			)
		);
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Mover al almacenamiento privado', 'arriendo-facil' ) . '</button></p></form></div>';
	}

	/**
	 * Handles the migration request.
	 *
	 * @return void
	 */
	public function handle_migration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permiso denegado.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		$result = self::migrate();
		delete_transient( 'af_private_legacy_count' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'af_private_migrated' => $result['moved'],
					'af_private_failed'   => $result['failed'],
				),
				admin_url( 'index.php' )
			)
		);
		exit;
	}

	/**
	 * Moves every legacy document that can be moved safely.
	 *
	 * @return array{moved:int,failed:int}
	 */
	public static function migrate() {
		global $wpdb;

		$moved  = 0;
		$failed = 0;

		// Guest documents.
		$rows = (array) $wpdb->get_results( "SELECT d.id, d.guest_id, d.doc_type, d.object_key, d.checksum_sha256, g.first_name, g.last_name FROM {$wpdb->prefix}af_guest_documents d LEFT JOIN {$wpdb->prefix}af_guests g ON g.id = d.guest_id WHERE d.storage = 'local'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $row ) {
			$key = self::move_attachment( (int) $row->object_key, Arriendo_Facil_Local_Private_Storage::CATEGORY_GUESTS, (int) $row->guest_id, trim( $row->first_name . ' ' . $row->last_name ), (string) $row->doc_type, (string) $row->checksum_sha256, true );
			if ( '' === $key ) {
				++$failed;
				continue;
			}

			$ok = $wpdb->update(
				$wpdb->prefix . 'af_guest_documents',
				array(
					'storage'    => Arriendo_Facil_Local_Private_Storage::STORAGE_ID,
					'object_key' => $key,
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $ok ) {
				Arriendo_Facil_Local_Private_Storage::delete( $key ); // Keep the original attachment; undo the copy.
				++$failed;
				continue;
			}

			wp_delete_attachment( (int) $row->object_key, true );
			++$moved;
		}

		// Property manager documents.
		$users = (array) $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'af_admin_documents'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $users as $user_id ) {
			$user_id   = (int) $user_id;
			$user      = get_userdata( $user_id );
			$documents = (array) get_user_meta( $user_id, 'af_admin_documents', true );
			$to_delete = array();

			foreach ( $documents as $doc_type => $doc ) {
				if ( ! is_array( $doc ) || ! isset( $doc['storage'] ) || 'local' !== $doc['storage'] ) {
					continue;
				}

				$attachment_id = (int) ( isset( $doc['object_key'] ) ? $doc['object_key'] : 0 );
				$key           = self::move_attachment( $attachment_id, Arriendo_Facil_Local_Private_Storage::CATEGORY_ADMINS, $user_id, $user ? (string) $user->display_name : '', (string) $doc_type, isset( $doc['checksum'] ) ? (string) $doc['checksum'] : '', false );
				if ( '' === $key ) {
					++$failed;
					continue;
				}

				$documents[ $doc_type ]['storage']    = Arriendo_Facil_Local_Private_Storage::STORAGE_ID;
				$documents[ $doc_type ]['object_key'] = $key;
				$to_delete[]                          = $attachment_id;
			}

			if ( $to_delete ) {
				update_user_meta( $user_id, 'af_admin_documents', $documents );
				// Delete the public originals only after the references point at the private copies.
				foreach ( $to_delete as $attachment_id ) {
					wp_delete_attachment( $attachment_id, true );
					++$moved;
				}
			}
		}

		// RIDE invoice PDFs left in the public uploads folder.
		foreach ( self::legacy_rides() as $ride ) {
			if ( self::move_ride( $ride ) ) {
				++$moved;
			} else {
				++$failed;
			}
		}

		return array(
			'moved'  => $moved,
			'failed' => $failed,
		);
	}

	/**
	 * Invoices whose RIDE file still sits in the old public uploads folder.
	 *
	 * @return array<int,object> Rows with id and ride_path.
	 */
	private static function legacy_rides() {
		global $wpdb;

		if ( ! class_exists( 'Arriendo_Facil_SRI_Ride' ) ) {
			return array();
		}

		$legacy = ( new Arriendo_Facil_SRI_Ride() )->legacy_ride_dir();
		$legacy = is_string( $legacy ) ? realpath( $legacy ) : false;
		if ( false === $legacy ) {
			return array();
		}

		$rows = (array) $wpdb->get_results( "SELECT id, ride_path FROM {$wpdb->prefix}af_electronic_invoices WHERE ride_path IS NOT NULL AND ride_path <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $row ) {
			$real = realpath( (string) $row->ride_path );
			if ( false !== $real && 0 === strpos( $real, $legacy . DIRECTORY_SEPARATOR ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Moves one RIDE into the private folder: copy, verify, update the row, then delete
	 * the public original. Any failure leaves the original in place.
	 *
	 * @param object $ride Row with id and ride_path.
	 * @return bool
	 */
	private static function move_ride( $ride ) {
		global $wpdb;

		$target_dir = ( new Arriendo_Facil_SRI_Ride() )->ride_dir();
		$legacy_dir = ( new Arriendo_Facil_SRI_Ride() )->legacy_ride_dir();
		if ( ! is_string( $target_dir ) || ! is_string( $legacy_dir ) || realpath( $target_dir ) === realpath( $legacy_dir ) ) {
			return false; // No private folder available: do not "move" within the public one.
		}

		$source   = (string) $ride->ride_path;
		$contents = file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || 0 !== strpos( $contents, '%PDF' ) ) {
			return false;
		}

		$target = trailingslashit( $target_dir ) . basename( $source );
		if ( false === file_put_contents( $target, $contents, LOCK_EX ) || hash( 'sha256', (string) file_get_contents( $target ) ) !== hash( 'sha256', $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( file_exists( $target ) ) {
				unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return false;
		}

		$ok = $wpdb->update( $wpdb->prefix . 'af_electronic_invoices', array( 'ride_path' => $target ), array( 'id' => (int) $ride->id ), array( '%s' ), array( '%d' ) );
		if ( false === $ok ) {
			unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}

		unlink( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return true;
	}

	/**
	 * Copies one attachment into the private store after verifying its integrity.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $category      Storage category.
	 * @param int    $owner_id      Guest or user ID.
	 * @param string $person_name   Display name.
	 * @param string $doc_type      Document type.
	 * @param string $checksum      Recorded SHA-256 ('' to skip the comparison).
	 * @param bool   $require_checksum Whether a recorded checksum is mandatory.
	 * @return string Storage key, or '' when the file could not be moved safely.
	 */
	private static function move_attachment( $attachment_id, $category, $owner_id, $person_name, $doc_type, $checksum, $require_checksum ) {
		$path = $attachment_id ? get_attached_file( $attachment_id ) : '';
		if ( ! $path || ! is_file( $path ) ) {
			return '';
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || '' === $contents || 0 !== strpos( $contents, '%PDF' ) ) {
			return '';
		}

		if ( '' !== $checksum && ! hash_equals( strtolower( $checksum ), hash( 'sha256', $contents ) ) ) {
			return '';
		}
		if ( $require_checksum && '' === $checksum ) {
			return '';
		}

		$key = Arriendo_Facil_Local_Private_Storage::store( $contents, $category, $owner_id, $person_name, $doc_type );
		if ( is_wp_error( $key ) ) {
			return '';
		}

		// Confirm the stored copy reads back identical before the original is removed.
		$copy = Arriendo_Facil_Local_Private_Storage::read( $key );
		if ( is_wp_error( $copy ) || ! hash_equals( hash( 'sha256', $contents ), hash( 'sha256', $copy ) ) ) {
			Arriendo_Facil_Local_Private_Storage::delete( $key );
			return '';
		}

		return $key;
	}
}
