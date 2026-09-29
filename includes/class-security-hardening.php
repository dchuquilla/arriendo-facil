<?php
/**
 * Hardening for the files the plugin makes reachable from the web.
 *
 * Everything under wp-content/plugins/ is served by the web server, so a
 * repository that travels to production drags its own development files with
 * it: composer.lock (exact dependency versions), phpunit.xml, the docs folder
 * and -- on misconfigured hosts -- the .git directory and WP_DEBUG_LOG's
 * debug.log, which ships every SQL statement, absolute file path and internal
 * hostname to anonymous visitors.
 *
 * This class owns the two halves of the fix:
 *  - .htaccess / web.config rules that deny those files (and are idempotent,
 *    so re-running them can never corrupt a file that already exists);
 *  - an admin notice that reports a still-reachable debug.log and offers to
 *    purge it, because the rules only help on Apache/LiteSpeed and only if
 *    the log is not disabled in wp-config.php.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Security_Hardening
 */
class Arriendo_Facil_Security_Hardening {

	/**
	 * Markers wrapping the block this class owns inside a server config file.
	 */
	const BLOCK_BEGIN = '# BEGIN Arriendo Facil (security hardening)';
	const BLOCK_END   = '# END Arriendo Facil (security hardening)';

	/**
	 * admin-post action used to purge the debug log.
	 */
	const PURGE_ACTION = 'af_security_purge_debug_log';

	/**
	 * Option remembering that the protection could not be written, so the
	 * notice can explain the manual fix instead of failing silently.
	 */
	const OPTION_DEGRADED = 'af_security_protection_degraded';

	/**
	 * Wires the hardening rules, the notice and the purge endpoint.
	 */
	public function __construct() {
		// XML-RPC is a credential-guessing and pingback-SSRF amplifier that
		// this plugin never uses. Disable it unless the site opts back in.
		if ( ! ( defined( 'AF_ALLOW_XMLRPC' ) && AF_ALLOW_XMLRPC ) ) {
			add_filter( 'xmlrpc_enabled', array( $this, 'disable_xmlrpc' ) );
			add_filter( 'xmlrpc_methods', array( $this, 'strip_xmlrpc_methods' ) );
			add_filter( 'wp_headers', array( $this, 'strip_pingback_header' ) );
		}

		add_action( 'admin_init', array( $this, 'protect_web_accessible_logs' ), 5 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::PURGE_ACTION, array( $this, 'handle_purge' ) );
	}

	/* ---------------------------------------------------------------------
	 * Server config files
	 * ------------------------------------------------------------------- */

	/**
	 * The deny rules appended to wp-content/.htaccess.
	 *
	 * Covers both Apache 2.4 (mod_authz_core) and 2.2 (mod_access_compat)
	 * syntax, and additionally hides the backup copy this class may create.
	 *
	 * @return string
	 */
	public static function htaccess_block() {
		return self::BLOCK_BEGIN . "
# Bloquea los registros de depuracion de WordPress: incluyen SQL, rutas
# absolutas del servidor y datos internos de la aplicacion.
<IfModule mod_authz_core.c>
	<FilesMatch \"\.log\$\">
		Require all denied
	</FilesMatch>
	<FilesMatch \"\.af-backup\$\">
		Require all denied
	</FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
	<FilesMatch \"\.log\$\">
		Order deny,allow
		Deny from all
	</FilesMatch>
	<FilesMatch \"\.af-backup\$\">
		Order deny,allow
		Deny from all
	</FilesMatch>
</IfModule>
" . self::BLOCK_END . "\n";
	}

	/**
	 * The matching rule set for IIS (web.config).
	 *
	 * @return string
	 */
	public static function webconfig_block() {
		return '<!-- ' . self::BLOCK_BEGIN . ' -->
<configuration>
	<system.webServer>
		<security>
			<requestFiltering>
				<fileExtensions>
					<add fileExtension=".log" allowed="false" />
					<add fileExtension=".af-backup" allowed="false" />
				</fileExtensions>
				<hiddenSegments>
					<add segment="debug.log" />
				</hiddenSegments>
			</requestFiltering>
			<authorization>
				<deny users="*" />
			</authorization>
		</security>
	</system.webServer>
</configuration>
<!-- ' . self::BLOCK_END . ' -->
';
	}

	/**
	 * Whether a config file already carries the block owned by this class.
	 *
	 * @param string $content File contents.
	 * @return bool
	 */
	public static function has_block( $content ) {
		return false !== strpos( (string) $content, self::BLOCK_BEGIN );
	}

	/**
	 * Appends the block, preserving whatever the host already had.
	 *
	 * Idempotent by design: running it twice is a no-op, and an existing
	 * WordPress-generated .htaccess keeps every one of its rules.
	 *
	 * @param string $content Existing file contents.
	 * @param string $block   Block to append.
	 * @return string
	 */
	public static function inject_block( $content, $block ) {
		$content = (string) $content;

		if ( self::has_block( $content ) ) {
			return $content;
		}

		if ( '' !== trim( $content ) ) {
			$content = rtrim( $content, "\r\n" ) . "\n\n";
		}

		return $content . $block;
	}

	/**
	 * Removes the block again, for a clean uninstall.
	 *
	 * @param string $content File contents.
	 * @return string
	 */
	public static function strip_block( $content ) {
		$content = (string) $content;

		return (string) preg_replace(
			'/\n?' . preg_quote( self::BLOCK_BEGIN, '/' ) . '.*?' . preg_quote( self::BLOCK_END, '/' ) . '\n?/s',
			'',
			$content
		);
	}

	/**
	 * Writes the deny rules for wp-content and silences its directory index.
	 *
	 * Runs on admin_init for administrators only, and only touches files it
	 * can write: on a read-only filesystem it records a flag instead, which
	 * the admin notice turns into an explicit manual instruction.
	 */
	public function protect_web_accessible_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! defined( 'WP_CONTENT_DIR' ) || ! WP_CONTENT_DIR ) {
			return;
		}

		$degraded = false;

		$degraded = ! $this->protect_file(
			WP_CONTENT_DIR . '/.htaccess',
			array( __CLASS__, 'htaccess_block' )
		) || $degraded;

		$degraded = ! $this->protect_file(
			WP_CONTENT_DIR . '/web.config',
			array( __CLASS__, 'webconfig_block' )
		) || $degraded;

		// An index.php in wp-content is what keeps a directory listing from
		// replacing a 404 on hosts that have indexes enabled.
		$index = WP_CONTENT_DIR . '/index.php';

		if ( ! file_exists( $index ) ) {
			$degraded = ! self::write_file( $index, self::index_php_body() ) || $degraded;
		}

		update_option( self::OPTION_DEGRADED, $degraded ? 1 : 0 );
	}

	/**
	 * Ensures one server config file carries the block, backing it up once.
	 *
	 * @param string   $path  Absolute file path.
	 * @param callable $block Callback returning the block to append.
	 * @return bool True when the file ends up protected.
	 */
	private function protect_file( $path, $block ) {
		$existing = file_exists( $path ) ? (string) file_get_contents( $path ) : '';

		if ( self::has_block( $existing ) ) {
			return true;
		}

		if ( file_exists( $path ) && ! is_writable( $path ) ) {
			return false;
		}

		if ( ! file_exists( $path ) && ! is_writable( dirname( $path ) ) ) {
			return false;
		}

		// Keep the original next to it: this file decides what the server
		// may serve, so a hand-rolled mistake has to be recoverable.
		if ( '' !== $existing ) {
			self::write_file( $path . '.af-backup', $existing );
		}

		return self::write_file( $path, self::inject_block( $existing, call_user_func( $block ) ) );
	}

	/**
	 * @return string Contents of the index.php sentinel.
	 */
	private static function index_php_body() {
		return "<?php\n// Silence is golden.\n";
	}

	/**
	 * @param string $path     Absolute file path.
	 * @param string $contents New contents.
	 * @return bool
	 */
	private static function write_file( $path, $contents ) {
		$written = file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( false === $written ) {
			return false;
		}

		if ( function_exists( 'wp_chmod' ) ) {
			wp_chmod( $path );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Debug log
	 * ------------------------------------------------------------------- */

	/**
	 * Absolute path of the WordPress debug log, when there is one.
	 *
	 * @return string Empty when the constant is undefined.
	 */
	public static function debug_log_path() {
		if ( ! defined( 'WP_CONTENT_DIR' ) || ! WP_CONTENT_DIR ) {
			return '';
		}

		return rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/debug.log';
	}

	/**
	 * Whether a path really lives *inside* a base directory.
	 *
	 * Strictly below, never the base itself: this guards a delete, and a
	 * target equal to the base would take the whole directory with it.
	 *
	 * @param string $path Candidate path.
	 * @param string $base Base directory.
	 * @return bool
	 */
	public static function is_inside( $path, $base ) {
		$path = realpath( $path );
		$base = realpath( $base );

		if ( ! $path || ! $base ) {
			return false;
		}

		$base = rtrim( str_replace( '\\', '/', $base ), '/' );

		return 0 === strpos( str_replace( '\\', '/', $path ), $base . '/' );
	}

	/**
	 * Deletes the debug log if it is the file this class expects.
	 *
	 * @return bool True when a file was removed.
	 */
	public static function purge_debug_log() {
		$path = self::debug_log_path();

		if ( ! $path || ! file_exists( $path ) ) {
			return false;
		}

		if ( ! self::is_inside( $path, WP_CONTENT_DIR ) ) {
			return false;
		}

		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $path );

			return ! file_exists( $path );
		}

		return (bool) @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * admin-post handler behind the "borrar ahora" button.
	 */
	public function handle_purge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permiso denegado.', 'arriendo-facil' ), 403 );
		}

		check_admin_referer( self::PURGE_ACTION );

		self::purge_debug_log();

		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
	}

	/**
	 * Warns while a web-reachable debug log is still on disk.
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$path     = self::debug_log_path();
		$has_log  = (bool) ( $path && file_exists( $path ) );
		$degraded = (bool) get_option( self::OPTION_DEGRADED );

		if ( ! $has_log && ! $degraded ) {
			return;
		}

		if ( $has_log ) {
			$messages[] = sprintf(
				/* translators: %s: absolute path of the debug log. */
				__( 'Arriendo Fácil: el registro de depuración (%s) está en el servidor y puede descargarse sin autenticación: contiene SQL, rutas absolutas y datos internos.', 'arriendo-facil' ),
				'<code>' . esc_html( $path ) . '</code>'
			);
		}

		if ( $degraded ) {
			$messages[] = __( 'Arriendo Fácil: no se pudieron escribir las reglas de protección en wp-content. Si tu servidor no es Apache o LiteSpeed, bloquea el archivo desde la configuración del hosting.', 'arriendo-facil' );
		}

		$messages[] = __( 'Arriendo Fácil: desactiva WP_DEBUG_LOG en wp-config.php una vez resuelto, para que el archivo no vuelva a crearse.', 'arriendo-facil' );

		echo '<div class="notice notice-error"><p>' . implode( '</p><p>', $messages ) . '</p>';

		if ( $has_log ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::PURGE_ACTION ) . '" />'
				. wp_nonce_field( self::PURGE_ACTION )
				. '<p><button type="submit" class="button button-primary">'
				. esc_html__( 'Borrar el registro ahora', 'arriendo-facil' )
				. '</button></p></form>';
		}

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * XML-RPC
	 * ------------------------------------------------------------------- */

	/**
	 * Turns xmlrpc.php into a 405 for every request.
	 *
	 * @param bool $enabled Current state.
	 * @return bool
	 */
	public function disable_xmlrpc( $enabled ) {
		return false;
	}

	/**
	 * Empties the method table, so no pingback or wp.getUsersBlogs remains
	 * reachable even through a cached route.
	 *
	 * @param array $methods Available methods.
	 * @return array
	 */
	public function strip_xmlrpc_methods( $methods ) {
		return array();
	}

	/**
	 * Removes the X-Pingback advertisement from the front end.
	 *
	 * @param array $headers Response headers.
	 * @return array
	 */
	public function strip_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );

		return $headers;
	}
}
