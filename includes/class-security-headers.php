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
	 * Set CSP header with appropriate directives.
	 *
	 * WordPress allows inline scripts via wp_localize_script, so we use
	 * 'unsafe-inline' for scripts but keep styles strict.
	 */
	private static function set_content_security_policy() {
		$csp_directives = array(
			"default-src 'self'",
			"script-src 'self' 'unsafe-inline' 'unsafe-eval' *.wp.com cdn.jsdelivr.net", // WordPress core uses eval for some JS
			"style-src 'self' 'unsafe-inline' fonts.googleapis.com", // Inline styles needed for WordPress
			"img-src 'self' data: https:",
			"font-src 'self' fonts.gstatic.com",
			"connect-src 'self'",
			"frame-ancestors 'self'",
			"base-uri 'self'",
			"form-action 'self'",
			"upgrade-insecure-requests",
		);

		/**
		 * Filter CSP directives.
		 *
		 * @param array $directives List of CSP directives.
		 */
		$directives = apply_filters( 'af_security_csp_directives', $csp_directives );

		$csp = implode( '; ', $directives );
		header( 'Content-Security-Policy: ' . $csp, true );

		// Also set Report-Only header for monitoring (doesn't block).
		header( 'Content-Security-Policy-Report-Only: ' . $csp . '; report-uri ' . esc_url( rest_url( 'af/v1/security/csp-report' ) ), true );
	}

	/**
	 * Set security headers for REST API responses.
	 *
	 * REST API responses should include standard headers to prevent misuse.
	 */
	public static function set_rest_headers() {
		if ( ! is_rest_request() ) {
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
