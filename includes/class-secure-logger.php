<?php
/**
 * Secure Logger
 *
 * Logs events without exposing sensitive information.
 *
 * @package Arriendo_Facil
 * @since 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Secure_Logger
 *
 * Logs security events and errors safely without exposing sensitive data.
 */
class Arriendo_Facil_Secure_Logger {

	/**
	 * Log levels.
	 */
	const DEBUG = 'debug';
	const INFO = 'info';
	const WARNING = 'warning';
	const ERROR = 'error';
	const CRITICAL = 'critical';

	/**
	 * Log a security event.
	 *
	 * @param string $level Log level (debug, info, warning, error, critical).
	 * @param string $message Log message.
	 * @param array  $context Additional context (will be sanitized).
	 *
	 * @return bool True if logged, false if failed.
	 */
	public static function log( $level, $message, $context = array() ) {
		// Sanitize context to remove sensitive data.
		$safe_context = self::sanitize_context( $context );

		// Build log entry.
		$entry = array(
			'timestamp' => gmdate( 'Y-m-d H:i:s' ),
			'level'     => sanitize_key( $level ),
			'message'   => sanitize_text_field( $message ),
			'context'   => $safe_context,
			'user_id'   => get_current_user_id(),
			'ip'        => self::get_client_ip(),
		);

		// Log to file if logging is enabled.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( wp_json_encode( $entry ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Also store in database for admin review.
		if ( self::should_store_in_db( $level ) ) {
			self::store_log_entry( $entry );
		}

		return true;
	}

	/**
	 * Log an error with optional exception.
	 *
	 * @param string      $message Error message.
	 * @param \Exception  $exception Optional exception.
	 * @param array       $context Optional context.
	 */
	public static function log_error( $message, $exception = null, $context = array() ) {
		$error_context = $context;

		if ( $exception ) {
			$error_context['exception'] = array(
				'class'  => get_class( $exception ),
				'code'   => $exception->getCode(),
				'file'   => basename( $exception->getFile() ),
				'line'   => $exception->getLine(),
				// Don't include full stack trace - it can expose paths.
			);
		}

		self::log( self::ERROR, $message, $error_context );
	}

	/**
	 * Log a security event (auth, access, etc).
	 *
	 * @param string $event Event name (login, logout, access_denied, etc).
	 * @param array  $data Optional event data.
	 */
	public static function log_security_event( $event, $data = array() ) {
		self::log( self::WARNING, 'Security event: ' . $event, $data );
	}

	/**
	 * Sanitize context to remove sensitive data.
	 *
	 * @param array $context Context array.
	 *
	 * @return array Sanitized context.
	 */
	private static function sanitize_context( $context ) {
		if ( ! is_array( $context ) ) {
			return array();
		}

		$sensitive_keys = array(
			'password',
			'pass',
			'pwd',
			'secret',
			'api_key',
			'token',
			'credit_card',
			'ssn',
			'social_security',
			'private_key',
			'access_token',
			'refresh_token',
			'authorization',
		);

		$sanitized = array();

		foreach ( $context as $key => $value ) {
			$key_lower = strtolower( $key );

			// Check if this is a sensitive key.
			foreach ( $sensitive_keys as $sensitive ) {
				if ( false !== strpos( $key_lower, $sensitive ) ) {
					$sanitized[ $key ] = '[REDACTED]';
					continue 2;
				}
			}

			// Sanitize the value based on type.
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = self::sanitize_context( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $key ] = self::sanitize_string_value( $value );
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $key ] = $value;
			} else {
				$sanitized[ $key ] = '[UNKNOWN TYPE]';
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize string value to prevent logging sensitive data.
	 *
	 * @param string $value Value to sanitize.
	 *
	 * @return string
	 */
	private static function sanitize_string_value( $value ) {
		// If value looks like a hash, email, phone, or credit card, mask it.
		if ( preg_match( '/@/', $value ) && strlen( $value ) > 10 ) {
			return substr( $value, 0, 3 ) . '***@***.' . substr( $value, -3 );
		}

		if ( preg_match( '/^[0-9]{4}[- ]?[0-9]{4}[- ]?[0-9]{4}[- ]?[0-9]{4}$/', $value ) ) {
			return '****-****-****-' . substr( $value, -4 );
		}

		if ( preg_match( '/^[+]?[0-9]{7,}$/', $value ) ) {
			return '***-***-' . substr( $value, -4 );
		}

		// Very long strings might be sensitive data.
		if ( strlen( $value ) > 255 ) {
			return substr( $value, 0, 50 ) . '...[' . strlen( $value ) . ' chars]';
		}

		return (string) $value;
	}

	/**
	 * Check if log level should be stored in database.
	 *
	 * @param string $level Log level.
	 *
	 * @return bool
	 */
	private static function should_store_in_db( $level ) {
		$levels_to_store = array( self::WARNING, self::ERROR, self::CRITICAL );
		return in_array( $level, $levels_to_store, true );
	}

	/**
	 * Store log entry in database.
	 *
	 * @param array $entry Log entry.
	 */
	private static function store_log_entry( $entry ) {
		global $wpdb;

		$table = $wpdb->prefix . 'af_security_logs';

		// Create table if it doesn't exist.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			self::create_logs_table();
		}

		$wpdb->insert(
			$table,
			array(
				'timestamp' => $entry['timestamp'],
				'level'     => $entry['level'],
				'message'   => $entry['message'],
				'context'   => wp_json_encode( $entry['context'] ),
				'user_id'   => $entry['user_id'],
				'ip_addr'   => $entry['ip'],
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Create security logs table.
	 */
	private static function create_logs_table() {
		global $wpdb;

		$table = $wpdb->prefix . 'af_security_logs';

		$sql = $wpdb->prepare(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				timestamp DATETIME NOT NULL,
				level VARCHAR(20) NOT NULL,
				message TEXT NOT NULL,
				context LONGTEXT,
				user_id BIGINT(20) UNSIGNED DEFAULT 0,
				ip_addr VARCHAR(45),
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_timestamp (timestamp),
				INDEX idx_level (level),
				INDEX idx_user_id (user_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $ips[0] );
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return 'UNKNOWN';
	}

	/**
	 * Get recent logs (for admin dashboard).
	 *
	 * @param int $limit Number of logs to retrieve.
	 *
	 * @return array
	 */
	public static function get_recent_logs( $limit = 50 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'af_security_logs';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Purge old logs (older than 30 days).
	 */
	public static function purge_old_logs() {
		global $wpdb;

		$table = $wpdb->prefix . 'af_security_logs';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
			)
		);
	}
}
