<?php
/**
 * Regression test for V3 (OWASP A10 SSRF hardening) — see plan
 * delightful-bouncing-bird.md.
 *
 * Covers Arriendo_Facil_Admin::is_url_safe_for_short_resolve() to ensure:
 *   • Only allow-listed Google Maps hosts pass.
 *   • Non-http(s) schemes are rejected.
 *   • Hosts resolving to loopback / private / link-local / reserved IPs
 *     are rejected (blocks 169.254.169.254 metadata, LAN scanning, etc.).
 *
 * The method is private in production; we reach it via ReflectionMethod
 * so we don't widen the public surface just for testing.
 *
 * @package Arriendo_Facil
 */

// Minimal WordPress stubs (declared in the GLOBAL namespace so
// Arriendo_Facil_Admin's constructor can call them).
namespace {
	if ( ! function_exists( 'add_action' ) ) {
		function add_action() { return true; }
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter() { return true; }
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	}
	if ( ! function_exists( 'remove_filter' ) ) {
		function remove_filter() { return true; }
	}
	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() { return 0; }
	}

	require_once ARRIENDO_FACIL_PLUGIN_DIR . 'admin/class-admin.php';
}

namespace ArriendoFacil\Tests\Security {

	use PHPUnit\Framework\TestCase;
	use ReflectionClass;

	class ShortUrlSsrfTest extends TestCase {

		private function invoke_is_safe( $url ) {
			$admin  = new \Arriendo_Facil_Admin();
			$refl   = new ReflectionClass( $admin );
			$method = $refl->getMethod( 'is_url_safe_for_short_resolve' );
			if ( \PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}
			return $method->invoke( $admin, $url );
		}

		// ── Positive cases ────────────────────────────────────────────────

		public function test_accepts_google_maps_short_url() {
			$this->assertTrue( $this->invoke_is_safe( 'https://maps.app.goo.gl/xyz123' ) );
		}

		public function test_accepts_google_maps_canonical_host() {
			$this->assertTrue( $this->invoke_is_safe( 'https://maps.google.com/maps?ll=1,2' ) );
		}

		public function test_accepts_subdomain_of_allowed_host() {
			$this->assertTrue( $this->invoke_is_safe( 'https://www.google.com/maps/place/x' ) );
		}

		// ── Negative: allow-list ──────────────────────────────────────────

		public function test_rejects_arbitrary_host() {
			$this->assertFalse( $this->invoke_is_safe( 'https://evil.example.com/x' ) );
		}

		public function test_rejects_host_that_only_contains_allowed_as_substring() {
			$this->assertFalse( $this->invoke_is_safe( 'https://goo.gl.evil.com/x' ) );
		}

		public function test_rejects_userinfo_smuggling_attempt() {
			$this->assertFalse( $this->invoke_is_safe( 'https://maps.google.com@evil.com/x' ) );
		}

		// ── Negative: scheme ──────────────────────────────────────────────

		public function test_rejects_file_scheme() {
			$this->assertFalse( $this->invoke_is_safe( 'file:///etc/passwd' ) );
		}

		public function test_rejects_gopher_scheme() {
			$this->assertFalse( $this->invoke_is_safe( 'gopher://maps.google.com/x' ) );
		}

		// ── Negative: private / loopback / link-local IPs ─────────────────

		public function test_rejects_direct_loopback_ip() {
			$this->assertFalse( $this->invoke_is_safe( 'http://127.0.0.1/' ) );
		}

		public function test_rejects_aws_metadata_ip() {
			$this->assertFalse( $this->invoke_is_safe( 'http://169.254.169.254/latest/meta-data/' ) );
		}

		public function test_rejects_lan_ip() {
			$this->assertFalse( $this->invoke_is_safe( 'http://192.168.1.1/admin' ) );
		}

		// ── Negative: malformed input ─────────────────────────────────────

		public function test_rejects_empty_string() {
			$this->assertFalse( $this->invoke_is_safe( '' ) );
		}

		public function test_rejects_missing_scheme() {
			$this->assertFalse( $this->invoke_is_safe( 'maps.google.com/x' ) );
		}
	}
}
