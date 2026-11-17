<?php
/**
 * Private local file storage for sensitive documents (identity papers) used when
 * no S3/R2 credentials are configured.
 *
 * Files never go to the public media library. They live in a directory that is
 * outside the web root when the host allows it, with readable per-person folders
 * and file names, and are delivered only through authenticated handlers.
 *
 * Layout:  <root>/<category>/<id>-<person-name>/<document-type>_<YYYY-MM-DD>[-vN].pdf
 * Example: <root>/huespedes/0042-maria-garcia-perez/cedula-papeleta_2026-11-17.pdf
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Local_Private_Storage
 */
class Arriendo_Facil_Local_Private_Storage {

	// Short on purpose: af_guest_documents.storage is VARCHAR(10).
	const STORAGE_ID = 'private';

	const CATEGORY_GUESTS = 'huespedes';
	const CATEGORY_ADMINS = 'gestores';

	/**
	 * Resolves (and prepares) the storage root.
	 *
	 * Order: AF_PRIVATE_STORAGE_DIR constant, a sibling of the WordPress directory
	 * outside the web root, then a protected directory inside wp-content as a last resort.
	 *
	 * @return string|WP_Error Absolute path without trailing slash.
	 */
	public static function root() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$candidates = array();
		if ( defined( 'AF_PRIVATE_STORAGE_DIR' ) && is_string( AF_PRIVATE_STORAGE_DIR ) && '' !== AF_PRIVATE_STORAGE_DIR ) {
			$candidates[] = array( rtrim( AF_PRIVATE_STORAGE_DIR, '/\\' ), false );
		} else {
			$candidates[] = array( dirname( rtrim( ABSPATH, '/\\' ) ) . '/af-private-storage', false );
		}
		$candidates[] = array( rtrim( WP_CONTENT_DIR, '/\\' ) . '/af-private/' . self::site_secret(), true );

		foreach ( $candidates as $candidate ) {
			list( $path, $inside_web_root ) = $candidate;
			if ( self::prepare_dir( $path, $inside_web_root ) ) {
				$cached = $path;
				return $cached;
			}
		}

		return new WP_Error( 'af_local_storage_unavailable', __( 'No hay un directorio privado escribible para guardar documentos. Configura AF_PRIVATE_STORAGE_DIR o el almacenamiento R2.', 'arriendo-facil' ) );
	}

	/**
	 * Whether the active root is inside wp-content (reachable by URL if the web server
	 * ignores the deny rules we write). Used to warn administrators.
	 *
	 * @return bool
	 */
	public static function is_inside_web_root() {
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return false;
		}

		return 0 === strpos( wp_normalize_path( $root ), wp_normalize_path( ABSPATH ) );
	}

	/**
	 * Saves a PDF in the person's own folder with a readable name. Never overwrites:
	 * a repeated upload of the same document type on the same day gets -v2, -v3...
	 *
	 * @param string $contents      File bytes.
	 * @param string $category      CATEGORY_* constant.
	 * @param int    $owner_id      Guest ID or user ID.
	 * @param string $person_name   Person's display name (used for the folder name).
	 * @param string $doc_type      Document type slug (e.g. cedula_papeleta).
	 * @return string|WP_Error Storage key relative to the root.
	 */
	public static function store( $contents, $category, $owner_id, $person_name, $doc_type ) {
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		if ( ! in_array( $category, array( self::CATEGORY_GUESTS, self::CATEGORY_ADMINS ), true ) ) {
			return new WP_Error( 'af_local_storage_bad_category', __( 'Categoría de almacenamiento inválida.', 'arriendo-facil' ) );
		}

		$folder = self::person_folder( $owner_id, $person_name );
		$dir    = $root . '/' . $category . '/' . $folder;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'af_local_storage_mkdir', __( 'No se pudo crear la carpeta privada del documento.', 'arriendo-facil' ) );
		}

		$base    = self::slug( str_replace( '_', '-', (string) $doc_type ), 'documento' ) . '_' . gmdate( 'Y-m-d' );
		$name    = $base . '.pdf';
		$version = 1;
		while ( file_exists( $dir . '/' . $name ) ) {
			++$version;
			$name = $base . '-v' . $version . '.pdf';
		}

		$path = $dir . '/' . $name;
		if ( false === file_put_contents( $path, (string) $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'af_local_storage_write', __( 'No se pudo guardar el documento en el almacenamiento privado.', 'arriendo-facil' ) );
		}
		@chmod( $path, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.chmod_chmod

		return $category . '/' . $folder . '/' . $name;
	}

	/**
	 * Reads a stored file.
	 *
	 * @param string $key Storage key.
	 * @return string|WP_Error
	 */
	public static function read( $key ) {
		$path = self::resolve( $key );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return new WP_Error( 'af_local_storage_read', __( 'No se pudo leer el documento.', 'arriendo-facil' ) );
		}

		return $contents;
	}

	/**
	 * Deletes a stored file (used when the owning record is purged).
	 *
	 * @param string $key Storage key.
	 * @return bool
	 */
	public static function delete( $key ) {
		$path = self::resolve( $key );
		if ( is_wp_error( $path ) ) {
			return false;
		}

		$deleted = unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$dir     = dirname( $path );
		// Drop the person's folder once empty.
		if ( $deleted && is_dir( $dir ) && 2 === count( (array) scandir( $dir ) ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rmdir_rmdir
		}

		return $deleted;
	}

	/**
	 * Sends a stored PDF to the browser and exits. The caller must have authorised the
	 * request already.
	 *
	 * @param string $key           Storage key.
	 * @param string $download_name Name shown to the user (sanitised here).
	 * @return void|WP_Error Returns only on error.
	 */
	public static function stream( $key, $download_name = '' ) {
		$path = self::resolve( $key );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$name = sanitize_file_name( '' !== $download_name ? $download_name : basename( $path ) );
		if ( '' === $name ) {
			$name = 'documento.pdf';
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . str_replace( '"', '', $name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Builds the readable download name for a document.
	 *
	 * @param string $key Storage key.
	 * @return string
	 */
	public static function display_name( $key ) {
		return basename( (string) $key );
	}

	/**
	 * Resolves a key to an absolute path inside the root. Rejects traversal, symlinks
	 * and anything that is not a regular .pdf file under the root.
	 *
	 * @param string $key Storage key.
	 * @return string|WP_Error
	 */
	public static function resolve( $key ) {
		$key = (string) $key;
		if ( '' === $key || false !== strpos( $key, '..' ) || false !== strpos( $key, "\0" ) || ! preg_match( '#^[a-z]+/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.pdf$#', $key ) ) {
			return new WP_Error( 'af_local_storage_bad_key', __( 'Clave de archivo inválida.', 'arriendo-facil' ) );
		}

		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$real_root = realpath( $root );
		$real_path = realpath( $root . '/' . $key );
		if ( false === $real_root || false === $real_path || 0 !== strpos( $real_path, $real_root . DIRECTORY_SEPARATOR ) || ! is_file( $real_path ) || is_link( $root . '/' . $key ) ) {
			return new WP_Error( 'af_local_storage_not_found', __( 'Documento no encontrado.', 'arriendo-facil' ) );
		}

		return $real_path;
	}

	/**
	 * Readable, per-person folder name: "<id padded>-<name slug>".
	 *
	 * @param int    $owner_id    Guest or user ID.
	 * @param string $person_name Display name.
	 * @return string
	 */
	public static function person_folder( $owner_id, $person_name ) {
		return sprintf( '%04d', absint( $owner_id ) ) . '-' . self::slug( $person_name, 'sin-nombre' );
	}

	/**
	 * Lowercase ASCII slug, max 40 chars.
	 *
	 * @param string $text     Source text.
	 * @param string $fallback Used when the slug ends up empty.
	 * @return string
	 */
	private static function slug( $text, $fallback ) {
		$text = function_exists( 'remove_accents' ) ? remove_accents( (string) $text ) : (string) $text;
		$text = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $text ) );
		$text = trim( substr( trim( $text, '-' ), 0, 40 ), '-' );

		return '' !== $text ? $text : $fallback;
	}

	/**
	 * Per-site secret path segment for the in-web-root fallback.
	 *
	 * @return string
	 */
	private static function site_secret() {
		$secret = (string) get_option( 'af_private_storage_secret', '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $secret ) ) {
			$secret = bin2hex( random_bytes( 16 ) );
			update_option( 'af_private_storage_secret', $secret, false );
		}

		return $secret;
	}

	/**
	 * Creates a directory with the guard files that make it inert if reached by URL.
	 *
	 * @param string $path            Directory.
	 * @param bool   $inside_web_root Whether it is under wp-content.
	 * @return bool Whether the directory is usable.
	 */
	private static function prepare_dir( $path, $inside_web_root ) {
		if ( ! is_dir( $path ) && ! @wp_mkdir_p( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		if ( ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return false;
		}

		// Guard files are cheap and also protect a root that turns out to be web reachable.
		$guards = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $guards as $file => $content ) {
			if ( ! file_exists( $path . '/' . $file ) ) {
				@file_put_contents( $path . '/' . $file, $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}

		return true;
	}
}
