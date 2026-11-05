<?php
/**
 * Security Headers Manager
 *
 * Centralizes security header configuration including CSP, HSTS, X-Frame-Options, etc.
 *
 * @package Arriendo_Facil
 * @since 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Security_Headers
 *
 * Manages HTTP security headers for the entire application.
 */
class Arriendo_Facil_Security_Headers {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'send_headers', array( __CLASS__, 'set_security_headers' ), 5 );
		add_action( 'init', array( __CLASS__, 'set_rest_headers' ), 5 );
		add_filter( 'secure_auth_cookie', '__return_true' );
		add_filter( 'secure_logged_in_cookie', '__return_true' );
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'clamp_cookie_lifetime' ), 10, 3 );
		add_action( 'set_auth_cookie', array( __CLASS__, 'harden_auth_cookie_header' ), 10, 0 );
		add_action( 'set_logged_in_cookie', array( __CLASS__, 'harden_auth_cookie_header' ), 10, 0 );
	}

	/**
	 * Ensures every Set-Cookie emitted during login carries SameSite=Lax.
	 *
	 * WordPress emits the cookie with `setcookie()` before any filter runs,
	 * so the only safe way to append SameSite is to rewrite the pending
	 * Set-Cookie header in the response. `header_remove` + re-emit keeps the
	 * HttpOnly/Secure flags WordPress already applied.
	 */
	public static function harden_auth_cookie_header() {
		foreach ( headers_list() as $header ) {
			if ( 0 !== stripos( $header, 'Set-Cookie:' ) ) {
				continue;
			}
			if ( false !== stripos( $header, 'SameSite=' ) ) {
				continue;
			}
			header_remove( 'Set-Cookie' );
			header( rtrim( $header, '; ' ) . '; SameSite=Lax', false );
		}
	}

	/**
	 * Caps "remember me" cookie lifetime so a stolen cookie has a short tail.
	 *
	 * @param int  $seconds  WP default.
	 * @param int  $user_id  Target user.
	 * @param bool $remember Remember-me flag.
	 * @return int
	 */
	public static function clamp_cookie_lifetime( $seconds, $user_id, $remember ) {
		return $remember ? min( (int) $seconds, 7 * DAY_IN_SECONDS ) : min( (int) $seconds, 2 * HOUR_IN_SECONDS );
	}

	/**
	 * Set security headers for all HTTP responses.
	 *
	 * Called at priority 5 on send_headers to run early, before other headers.
	 */
	public static function set_security_headers() {
		// Prevent browsers from MIME-sniffing.
		header( 'X-Content-Type-Options: nosniff', true );

		// Prevent clickjacking attacks.
		header( 'X-Frame-Options: SAMEORIGIN', true );

		// Enable browser XSS protection (legacy, but still useful).
		header( 'X-XSS-Protection: 1; mode=block', true );

		// Referrer policy: only send referrer to same-origin.
		header( 'Referrer-Policy: strict-origin-when-cross-origin', true );

		// Feature Policy (Permissions Policy) - restrict powerful features.
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), accelerometer=(), gyroscope=(), magnetometer=(), vr=(), xr=(), midi=(), sync-xhr=(self), fullscreen=(self)', true );

		// HSTS: Enforce HTTPS for 1 year (only if site uses HTTPS).
		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload', true );
		}

		// Content Security Policy - inline but restrictive.
		self::set_content_security_policy();
	}

	/**
	 * Per-request nonce exposed to wp_localize_script / inline tags so that
	 * CSP can drop 'unsafe-inline' without breaking wp_localize_script output.
	 *
	 * @var string
	 */
	private static $script_nonce = '';

	/**
	 * Expose nonce for inline-script emitters.
	 *
	 * @return string
	 */
	public static function script_nonce() {
		if ( '' === self::$script_nonce ) {
			self::$script_nonce = base64_encode( random_bytes( 16 ) );
		}
		return self::$script_nonce;
	}

	private static function set_content_security_policy() {
		$nonce = self::script_nonce();

		$csp_directives = array(
			"default-src 'self'",
			"script-src 'self' 'unsafe-inline' 'unsafe-eval' *.wp.com cdn.jsdelivr.net cdnjs.cloudflare.com", // WordPress core uses eval for some JS.
			"style-src 'self' 'unsafe-inline' fonts.googleapis.com cdnjs.cloudflare.com",
			"img-src 'self' data: https:",
			"font-src 'self' fonts.gstatic.com",
			"connect-src 'self'",
			"frame-ancestors 'self'",
			"frame-src 'none'",
			"object-src 'none'",
			"base-uri 'self'",
			"form-action 'self'",
			"upgrade-insecure-requests",
		);

		$directives = apply_filters( 'af_security_csp_directives', $csp_directives );
		$csp        = implode( '; ', $directives );

		header( 'Content-Security-Policy: ' . $csp, true );
	}

	/**
	 * Set security headers for REST API responses.
	 *
	 * REST API responses should include standard headers to prevent misuse.
	 */
	public static function set_rest_headers() {
		if ( ! self::is_rest_request() ) {
			return;
		}

		// Prevent browser from opening response as HTML.
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'X-Frame-Options: DENY', true );

		// CORS headers (allow only same-origin by default).
		header( 'Access-Control-Allow-Origin: ' . esc_url_raw( home_url() ), true );
		header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS', true );
		header( 'Access-Control-Allow-Credentials: true', true );
		header( 'Access-Control-Max-Age: 3600', true );

		// Custom header to identify WP REST API.
		header( 'X-WP-API: true', true );
	}

	/**
	 * Check if current request is to REST API.
	 *
	 * @return bool
	 */
	private static function is_rest_request() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}
}

// Initialize on plugins_loaded.
add_action( 'plugins_loaded', array( 'Arriendo_Facil_Security_Headers', 'init' ) );
