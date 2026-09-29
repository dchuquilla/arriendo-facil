<?php
/**
 * Tests for the hardening of web-served plugin files.
 *
 * A plugin directory is reachable over HTTP, so anything committed next to
 * the PHP -- composer.lock, phpunit.xml, docs/, .git -- is downloadable by
 * anyone. The rules that prevent it are written into files the plugin does
 * not own, so what matters here is that they are idempotent, that they never
 * clobber what the host already had, and that the purge endpoint cannot be
 * pointed outside wp-content.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

/**
 * Class SecurityHardeningTest
 */
class SecurityHardeningTest extends TestCase {

	/**
	 * Builds an instance without running the constructor's hook wiring.
	 *
	 * @return Arriendo_Facil_Security_Hardening
	 */
	private function hardening() {
		$class = new ReflectionClass( 'Arriendo_Facil_Security_Hardening' );

		return $class->newInstanceWithoutConstructor();
	}

	/**
	 * A block is appended after whatever the host already had, never instead
	 * of it: a WordPress-generated .htaccess must survive untouched.
	 */
	public function test_existing_rules_are_preserved() {
		$existing = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\n</IfModule>\n# END WordPress\n";

		$result = Arriendo_Facil_Security_Hardening::inject_block( $existing, Arriendo_Facil_Security_Hardening::htaccess_block() );

		$this->assertStringContainsString( '# BEGIN WordPress', $result );
		$this->assertStringContainsString( 'RewriteEngine On', $result );
		$this->assertStringContainsString( Arriendo_Facil_Security_Hardening::BLOCK_BEGIN, $result );
		$this->assertTrue(
			strpos( $result, '# BEGIN WordPress' ) < strpos( $result, Arriendo_Facil_Security_Hardening::BLOCK_BEGIN ),
			'El bloque propio debe ir al final, nunca delante.'
		);
	}

	/**
	 * The block is re-inserted on every admin request, so running it twice
	 * must be a no-op instead of stacking duplicate rules.
	 */
	public function test_injection_is_idempotent() {
		$block = Arriendo_Facil_Security_Hardening::htaccess_block();

		$once  = Arriendo_Facil_Security_Hardening::inject_block( '', $block );
		$twice = Arriendo_Facil_Security_Hardening::inject_block( $once, $block );

		$this->assertSame( $once, $twice );
		$this->assertSame( 1, substr_count( $twice, Arriendo_Facil_Security_Hardening::BLOCK_BEGIN ) );
		$this->assertTrue( Arriendo_Facil_Security_Hardening::has_block( $twice ) );
	}

	/**
	 * Uninstalling has to give the file back untouched.
	 */
	public function test_block_can_be_removed() {
		$existing = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$with     = Arriendo_Facil_Security_Hardening::inject_block( $existing, Arriendo_Facil_Security_Hardening::htaccess_block() );

		$this->assertSame(
			$existing,
			Arriendo_Facil_Security_Hardening::strip_block( $with )
		);
	}

	/**
	 * Both Apache generations have to be covered: 2.4 uses mod_authz_core,
	 * 2.2 falls back to Order/Deny.
	 */
	public function test_htaccess_block_covers_both_apache_generations() {
		$block = Arriendo_Facil_Security_Hardening::htaccess_block();

		$this->assertStringContainsString( 'mod_authz_core.c', $block );
		$this->assertStringContainsString( 'Require all denied', $block );
		$this->assertStringContainsString( 'Deny from all', $block );
		$this->assertStringContainsString( '\.log$', $block );
		$this->assertStringContainsString( '\.af-backup$', $block );
	}

	/**
	 * The purge endpoint takes no path from the browser, but the check that
	 * keeps it inside wp-content must still reject a traversal.
	 */
	public function test_purge_target_cannot_escape_wp_content() {
		$base    = sys_get_temp_dir() . '/af-hardening-' . uniqid();
		$outside = sys_get_temp_dir() . '/af-hardening-outside-' . uniqid() . '.log';

		wp_mkdir_p( $base . '/inner' );
		file_put_contents( $base . '/inner/debug.log', 'x' );
		file_put_contents( $outside, 'x' );

		$this->assertTrue( Arriendo_Facil_Security_Hardening::is_inside( $base . '/inner/debug.log', $base ) );
		$this->assertTrue( Arriendo_Facil_Security_Hardening::is_inside( $base . '/inner/../inner/debug.log', $base ) );
		$this->assertFalse( Arriendo_Facil_Security_Hardening::is_inside( $outside, $base ) );
		$this->assertFalse( Arriendo_Facil_Security_Hardening::is_inside( $base . '/inner', $base . '/inner' ) );
		$this->assertFalse( Arriendo_Facil_Security_Hardening::is_inside( $base . '/nope.log', $base ) );

		@unlink( $outside );
		@unlink( $base . '/inner/debug.log' );
		@rmdir( $base . '/inner' );
		@rmdir( $base );
	}

	/**
	 * The debug log path is derived from the constant, never from input, and
	 * purging it removes that one file and nothing else.
	 */
	public function test_purge_removes_only_the_debug_log() {
		wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );

		$log   = Arriendo_Facil_Security_Hardening::debug_log_path();
		$other = WP_CONTENT_DIR . '/uploads/keep.txt';

		file_put_contents( $log, 'SQL leaked here' );
		file_put_contents( $other, 'keep me' );

		$this->assertSame( rtrim( WP_CONTENT_DIR, '/' ) . '/debug.log', $log );
		$this->assertTrue( Arriendo_Facil_Security_Hardening::is_inside( $log, WP_CONTENT_DIR ) );
		$this->assertTrue( Arriendo_Facil_Security_Hardening::purge_debug_log() );
		$this->assertFileDoesNotExist( $log );
		$this->assertFileExists( $other );
		$this->assertFalse( Arriendo_Facil_Security_Hardening::purge_debug_log(), 'Borrar dos veces no debe fallar.' );

		@unlink( $other );
	}

	/**
	 * XML-RPC is unused by the plugin and is the usual entry point for
	 * credential guessing (system.multicall) and pingback SSRF.
	 */
	public function test_xmlrpc_is_disabled() {
		$hardening = $this->hardening();

		$this->assertFalse( $hardening->disable_xmlrpc( true ) );
		$this->assertSame( array(), $hardening->strip_xmlrpc_methods( array( 'wp.getUsersBlogs' => 1, 'pingback.ping' => 1 ) ) );

		$headers = $hardening->strip_pingback_header( array( 'X-Pingback' => 'https://site/xmlrpc.php', 'Vary' => 'Accept' ) );

		$this->assertArrayNotHasKey( 'X-Pingback', $headers );
		$this->assertArrayHasKey( 'Vary', $headers );
	}

	/**
	 * The shipped .htaccess/web.config must keep blocking the development
	 * files while leaving the public assets alone.
	 */
	public function test_shipped_rules_cover_the_leaked_files() {
		$htaccess = (string) file_get_contents( ARRIENDO_FACIL_PLUGIN_DIR . '.htaccess' );

		$this->assertStringContainsString( 'CONVERTIDOR_P12_VALIDO.php', $htaccess );
		$this->assertStringContainsString( 'docs|tests|vendor', $htaccess );

		// Assets are PHP-adjacent, never matched by the deny patterns.
		$this->assertDoesNotMatchRegularExpression( '/<FilesMatch[^>]*>.*\.(?:js|css|php)/', $htaccess );
		$this->assertFileExists( ARRIENDO_FACIL_PLUGIN_DIR . 'index.php' );
	}
}
