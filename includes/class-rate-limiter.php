<?php
/**
 * Rate Limiter
 *
 * Implements request rate limiting to prevent abuse.
 *
 * @package Arriendo_Facil
 * @since 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Rate_Limiter
 *
 * Rate limiting based on IP address or user ID.
 */
class Arriendo_Facil_Rate_Limiter {

	/**
	 * Limit configuration: action_name => [max_requests, time_window_seconds]
	 *
	 * @var array
	 */
	private static $limits = array();

	/**
	 * Initialize rate limit configuration.
	 */
	public static function init() {
		self::$limits = apply_filters(
			'af_rate_limits',
			array(
				'api_public'        => array( 60, 3600 ),    // 60 requests per hour
				'api_authenticated' => array( 300, 3600 ),   // 300 requests per hour
				'signup'            => array( 5, 3600 ),     // 5 signups per hour
				'login_attempt'     => array( 10, 900 ),     // 10 attempts per 15 min
				'password_reset'    => array( 3, 3600 ),     // 3 resets per hour
				'contact_form'      => array( 10, 3600 ),    // 10 submissions per hour
				'issue_invoice'     => array( 5, 3600 ),     // 5 invoices per hour
				'download_invoice'  => array( 20, 3600 ),    // 20 downloads per hour
				'add_visit'         => array( 30, 86400 ),   // 30 visits per day
			)
		);
	}

	/**
	 * Check if request is rate-limited.
	 *
	 * @param string $action Action identifier.
	 * @param int    $user_id Optional user ID (if null, uses IP address).
	 *
	 * @return bool True if request should be blocked, false if allowed.
	 */
	public static function is_rate_limited( $action, $user_id = null ) {
		if ( empty( self::$limits[ $action ] ) ) {
			return false; // No rate limit configured for this action.
		}

		list( $max_requests, $window ) = self::$limits[ $action ];

		$key = self::get_rate_limit_key( $action, $user_id );
		$current = (int) get_transient( $key );

		// If transient doesn't exist, this is the first request.
		if ( ! $current ) {
			set_transient( $key, 1, $window );
			return false;
		}

		// If we've exceeded the limit, block.
		if ( $current >= $max_requests ) {
			return true;
		}

		// Increment counter and allow.
		set_transient( $key, $current + 1, $window );
		return false;
	}

	/**
	 * Get the rate limit key for this request.
	 *
	 * @param string $action Action identifier.
	 * @param int    $user_id Optional user ID.
	 *
	 * @return string Transient key.
	 */
	private static function get_rate_limit_key( $action, $user_id = null ) {
		if ( $user_id ) {
			return 'af_ratelimit_' . $action . '_user_' . (int) $user_id;
		}

		$ip = self::get_client_ip();
		return 'af_ratelimit_' . $action . '_ip_' . md5( $ip );
	}

	/**
	 * Get client IP address.
	 *
	 * Handles proxies and load balancers.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		// CloudFlare.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}

		// Standard headers.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $ips[0] );
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return '0.0.0.0';
	}

	/**
	 * Get remaining requests for an action.
	 *
	 * @param string $action Action identifier.
	 * @param int    $user_id Optional user ID.
	 *
	 * @return int Remaining requests.
	 */
	public static function get_remaining( $action, $user_id = null ) {
		if ( empty( self::$limits[ $action ] ) ) {
			return 0;
		}

		list( $max_requests, $window ) = self::$limits[ $action ];
		$key = self::get_rate_limit_key( $action, $user_id );
		$current = (int) get_transient( $key );

		return max( 0, $max_requests - $current );
	}

	/**
	 * Send rate limit headers with current status.
	 *
	 * @param string $action Action identifier.
	 * @param int    $user_id Optional user ID.
	 */
	public static function send_headers( $action, $user_id = null ) {
		if ( empty( self::$limits[ $action ] ) ) {
			return;
		}

		list( $max_requests, $window ) = self::$limits[ $action ];
		$remaining = self::get_remaining( $action, $user_id );

		header( 'X-RateLimit-Limit: ' . (int) $max_requests, true );
		header( 'X-RateLimit-Remaining: ' . (int) $remaining, true );
		header( 'X-RateLimit-Reset: ' . (int) ( time() + $window ), true );
	}
}

// Initialize on plugins_loaded.
add_action( 'plugins_loaded', array( 'Arriendo_Facil_Rate_Limiter', 'init' ) );
