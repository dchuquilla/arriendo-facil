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
	const CATEGORY_CONTRACTS = 'contratos';
	const CATEGORY_TEMPLATES = 'plantillas';
	const CATEGORY_PROPERTIES = 'inmuebles';

	/**
	 * Allowed stored file types and their content types.
	 *
	 * @var array<string,string>
	 */
	const TYPES = array(
		'pdf'  => 'application/pdf',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'doc'  => 'application/msword',
		'md'   => 'text/markdown',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

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
		// wp-content itself is often read-only while uploads is writable.
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();
			if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				$candidates[] = array( rtrim( (string) $uploads['basedir'], '/\\' ) . '/af-private/' . self::site_secret(), true );
			}
		}

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
	 * @param string $ext           File extension: pdf (default), docx or doc.
	 * @return string|WP_Error Storage key relative to the root.
	 */
	public static function store( $contents, $category, $owner_id, $person_name, $doc_type, $ext = 'pdf' ) {
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$ext = strtolower( (string) $ext );
		if ( ! isset( self::TYPES[ $ext ] ) ) {
			return new WP_Error( 'af_local_storage_bad_type', __( 'Tipo de archivo no permitido.', 'arriendo-facil' ) );
		}

		if ( ! in_array( $category, array( self::CATEGORY_GUESTS, self::CATEGORY_ADMINS, self::CATEGORY_CONTRACTS, self::CATEGORY_TEMPLATES, self::CATEGORY_PROPERTIES ), true ) ) {
			return new WP_Error( 'af_local_storage_bad_category', __( 'Categoría de almacenamiento inválida.', 'arriendo-facil' ) );
		}

		$folder = self::person_folder( $owner_id, $person_name );
		$dir    = $root . '/' . $category . '/' . $folder;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'af_local_storage_mkdir', __( 'No se pudo crear la carpeta privada del documento.', 'arriendo-facil' ) );
		}

		$base    = self::slug( str_replace( '_', '-', (string) $doc_type ), 'documento' ) . '_' . gmdate( 'Y-m-d' );
		$name    = $base . '.' . $ext;
		$version = 1;
		while ( file_exists( $dir . '/' . $name ) ) {
			++$version;
			$name = $base . '-v' . $version . '.' . $ext;
		}

		$path = $dir . '/' . $name;
		if ( false === file_put_contents( $path, (string) $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'af_local_storage_write', __( 'No se pudo guardar el documento en el almacenamiento privado.', 'arriendo-facil' ) );
		}
		@chmod( $path, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.chmod_chmod

		return $category . '/' . $folder . '/' . $name;
	}

	/**
	 * Absolute path of a person's folder for a category (created when missing). Used by modules
	 * that write their own files (e.g. processed contract templates) so they land in the private
	 * store with a readable per-owner folder.
	 *
	 * @param string $category    CATEGORY_* constant.
	 * @param int    $owner_id    User or record ID.
	 * @param string $person_name Display name for the folder.
	 * @return string|WP_Error
	 */
	public static function directory( $category, $owner_id, $person_name ) {
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		if ( ! in_array( $category, array( self::CATEGORY_GUESTS, self::CATEGORY_ADMINS, self::CATEGORY_CONTRACTS, self::CATEGORY_TEMPLATES, self::CATEGORY_PROPERTIES ), true ) ) {
			return new WP_Error( 'af_local_storage_bad_category', __( 'Categoría de almacenamiento inválida.', 'arriendo-facil' ) );
		}

		$dir = $root . '/' . $category . '/' . self::person_folder( $owner_id, $person_name );

		return wp_mkdir_p( $dir ) ? $dir : new WP_Error( 'af_local_storage_mkdir', __( 'No se pudo crear la carpeta privada.', 'arriendo-facil' ) );
	}

	/**
	 * Whether a path is inside the private store.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_private_path( $path ) {
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return false;
		}

		$real_root = realpath( $root );
		$real_path = realpath( (string) $path );

		return false !== $real_root && false !== $real_path && 0 === strpos( $real_path, $real_root . DIRECTORY_SEPARATOR );
	}

	/**
	 * Moves a media-library attachment's file into the private store and points the attachment at
	 * the new absolute path (WordPress resolves absolute _wp_attached_file values as they are).
	 * The copy is verified before the public original is deleted; any failure leaves it untouched.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $category      CATEGORY_* constant.
	 * @param int    $owner_id      Owner user ID.
	 * @param string $person_name   Owner display name.
	 * @param string $prefix        Readable file-name prefix (e.g. plantilla).
	 * @return bool True when the file now lives in the private store.
	 */
	public static function relocate_attachment( $attachment_id, $category, $owner_id, $person_name, $prefix ) {
		$attachment_id = absint( $attachment_id );
		$path          = $attachment_id ? get_attached_file( $attachment_id, true ) : '';
		if ( ! $path || ! is_file( $path ) ) {
			return false;
		}
		if ( self::is_private_path( $path ) ) {
			return true;
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! isset( self::TYPES[ $ext ] ) ) {
			return false;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || '' === $contents ) {
			return false;
		}

		$key = self::store( $contents, $category, $owner_id, $person_name, $prefix . '-' . pathinfo( $path, PATHINFO_FILENAME ), $ext );
		if ( is_wp_error( $key ) ) {
			return false;
		}

		$new_path = self::resolve( $key );
		$copy     = is_wp_error( $new_path ) ? false : file_get_contents( $new_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $copy || ! hash_equals( hash( 'sha256', $contents ), hash( 'sha256', $copy ) ) ) {
			self::delete( $key );
			return false;
		}

		$original = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		update_post_meta( $attachment_id, '_af_original_attached_file', $original );
		update_post_meta( $attachment_id, '_wp_attached_file', $new_path );

		// Only delete the public original once the attachment resolves to the private copy.
		if ( get_attached_file( $attachment_id, true ) === $new_path ) {
			wp_delete_file( $path );
			return true;
		}

		update_post_meta( $attachment_id, '_wp_attached_file', $original );
		self::delete( $key );

		return false;
	}

	/**
	 * Moves an owner's uploaded contract template out of the public media library into the
	 * owner's private folder (see relocate_attachment()).
	 *
	 * @param int $attachment_id Template attachment ID.
	 * @param int $user_id       Owner user ID.
	 * @return bool
	 */
	public static function relocate_owner_template( $attachment_id, $user_id ) {
		$user = get_userdata( absint( $user_id ) );

		return self::relocate_attachment( $attachment_id, self::CATEGORY_TEMPLATES, absint( $user_id ), $user ? (string) $user->display_name : '', 'plantilla' );
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

		return self::stream_path( $path, $download_name );
	}

	/**
	 * Sends a file that lives in the private store (given by absolute path) and exits. Refuses any
	 * path outside the store or with an unlisted extension. The caller must have authorised the request.
	 *
	 * @param string $path          Absolute path.
	 * @param string $download_name Name shown to the user (sanitised here).
	 * @return void|WP_Error Returns only on error.
	 */
	public static function stream_path( $path, $download_name = '' ) {
		$path = (string) $path;
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! self::is_private_path( $path ) || ! isset( self::TYPES[ $ext ] ) || ! is_file( $path ) || is_link( $path ) ) {
			return new WP_Error( 'af_local_storage_not_found', __( 'Documento no encontrado.', 'arriendo-facil' ) );
		}

		$name = sanitize_file_name( '' !== $download_name ? $download_name : basename( $path ) );
		if ( '' === $name ) {
			$name = 'documento.pdf';
		}

		nocache_headers();
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		header( 'Content-Type: ' . ( isset( self::TYPES[ $ext ] ) ? self::TYPES[ $ext ] : 'application/octet-stream' ) );
		header( 'Content-Disposition: ' . ( in_array( $ext, array( 'pdf', 'jpg', 'jpeg', 'png' ), true ) ? 'inline' : 'attachment' ) . '; filename="' . str_replace( '"', '', $name ) . '"' );
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
		if ( '' === $key || false !== strpos( $key, '..' ) || false !== strpos( $key, "\0" ) || ! preg_match( '#^[a-z]+/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.(pdf|docx|doc|md|jpe?g|png)$#', $key ) ) {
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
