<?php
/**
 * Security Configuration
 *
 * Central configuration for all security settings.
 *
 * @package Arriendo_Facil
 * @since 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define security settings.
 *
 * Use filters to override in wp-config.php or mu-plugins.
 */

// ========================
// AUTHENTICATION SETTINGS
// ========================

/**
 * Session timeout (in minutes).
 *
 * @filter af_session_timeout
 */
define( 'AF_SESSION_TIMEOUT', apply_filters( 'af_session_timeout', 60 ) );

/**
 * Enable 2FA for admins.
 *
 * @filter af_enable_2fa
 */
define( 'AF_ENABLE_2FA', apply_filters( 'af_enable_2fa', false ) );

/**
 * Password minimum length.
 *
 * @filter af_password_min_length
 */
define( 'AF_PASSWORD_MIN_LENGTH', apply_filters( 'af_password_min_length', 12 ) );

/**
 * Require complex passwords (upper, lower, numbers, symbols).
 *
 * @filter af_require_complex_passwords
 */
define( 'AF_REQUIRE_COMPLEX_PASSWORDS', apply_filters( 'af_require_complex_passwords', true ) );

// ========================
// RATE LIMITING SETTINGS
// ========================

/**
 * Enable rate limiting.
 *
 * @filter af_enable_rate_limiting
 */
define( 'AF_ENABLE_RATE_LIMITING', apply_filters( 'af_enable_rate_limiting', true ) );

/**
 * Rate limit for public API.
 *
 * Format: [requests, time_window_in_seconds]
 *
 * @filter af_rate_limit_public_api
 */
define( 'AF_RATE_LIMIT_PUBLIC_API', apply_filters( 'af_rate_limit_public_api', array( 60, 3600 ) ) );

/**
 * Rate limit for authenticated API.
 *
 * @filter af_rate_limit_authenticated_api
 */
define( 'AF_RATE_LIMIT_AUTHENTICATED_API', apply_filters( 'af_rate_limit_authenticated_api', array( 300, 3600 ) ) );

// ========================
// LOGGING SETTINGS
// ========================

/**
 * Enable security logging.
 *
 * @filter af_enable_security_logging
 */
define( 'AF_ENABLE_SECURITY_LOGGING', apply_filters( 'af_enable_security_logging', true ) );

/**
 * Enable sensitive data logging (will be redacted).
 *
 * @filter af_log_sensitive_data
 */
define( 'AF_LOG_SENSITIVE_DATA', apply_filters( 'af_log_sensitive_data', false ) );

/**
 * Log retention (in days).
 *
 * @filter af_log_retention_days
 */
define( 'AF_LOG_RETENTION_DAYS', apply_filters( 'af_log_retention_days', 30 ) );

// ========================
// ENCRYPTION SETTINGS
// ========================

/**
 * Encryption algorithm for sensitive data.
 *
 * Options: 'aes-256-gcm', 'secretbox'
 *
 * @filter af_encryption_algorithm
 */
define( 'AF_ENCRYPTION_ALGORITHM', apply_filters( 'af_encryption_algorithm', 'secretbox' ) );

/**
 * Enable data encryption at rest.
 *
 * @filter af_encrypt_at_rest
 */
define( 'AF_ENCRYPT_AT_REST', apply_filters( 'af_encrypt_at_rest', true ) );

// ========================
// API SECURITY SETTINGS
// ========================

/**
 * Allowed API origins (CORS).
 *
 * @filter af_api_allowed_origins
 */
define( 'AF_API_ALLOWED_ORIGINS', apply_filters( 'af_api_allowed_origins', array( home_url() ) ) );

/**
 * Require HTTPS for API requests.
 *
 * @filter af_require_api_https
 */
define( 'AF_REQUIRE_API_HTTPS', apply_filters( 'af_require_api_https', is_ssl() ) );

/**
 * API key rotation interval (in days).
 *
 * @filter af_api_key_rotation_days
 */
define( 'AF_API_KEY_ROTATION_DAYS', apply_filters( 'af_api_key_rotation_days', 90 ) );

// ========================
// DATABASE SECURITY
// ========================

/**
 * Enable Row Level Security (RLS).
 *
 * @filter af_enable_rls
 */
define( 'AF_ENABLE_RLS', apply_filters( 'af_enable_rls', true ) );

/**
 * Enable automatic database backups.
 *
 * @filter af_enable_db_backups
 */
define( 'AF_ENABLE_DB_BACKUPS', apply_filters( 'af_enable_db_backups', true ) );

/**
 * Database backup retention (in days).
 *
 * @filter af_db_backup_retention_days
 */
define( 'AF_DB_BACKUP_RETENTION_DAYS', apply_filters( 'af_db_backup_retention_days', 30 ) );

// ========================
// COMPLIANCE SETTINGS
// ========================

/**
 * Enable GDPR compliance mode.
 *
 * @filter af_enable_gdpr_mode
 */
define( 'AF_ENABLE_GDPR_MODE', apply_filters( 'af_enable_gdpr_mode', true ) );

/**
 * Enable CCPA compliance mode.
 *
 * @filter af_enable_ccpa_mode
 */
define( 'AF_ENABLE_CCPA_MODE', apply_filters( 'af_enable_ccpa_mode', false ) );

/**
 * Data retention policy (in days).
 *
 * @filter af_data_retention_days
 */
define( 'AF_DATA_RETENTION_DAYS', apply_filters( 'af_data_retention_days', 2555 ) ); // ~7 years

/**
 * Cookie consent required.
 *
 * @filter af_require_cookie_consent
 */
define( 'AF_REQUIRE_COOKIE_CONSENT', apply_filters( 'af_require_cookie_consent', true ) );

// ========================
// SECURITY HEADERS
// ========================

/**
 * Enable security headers.
 *
 * @filter af_enable_security_headers
 */
define( 'AF_ENABLE_SECURITY_HEADERS', apply_filters( 'af_enable_security_headers', true ) );

/**
 * Content Security Policy level.
 *
 * Options: 'strict', 'moderate', 'permissive'
 *
 * @filter af_csp_level
 */
define( 'AF_CSP_LEVEL', apply_filters( 'af_csp_level', 'moderate' ) );

/**
 * Enable HSTS (Strict Transport Security).
 *
 * @filter af_enable_hsts
 */
define( 'AF_ENABLE_HSTS', apply_filters( 'af_enable_hsts', is_ssl() ) );

// ========================
// IP BLOCKING SETTINGS
// ========================

/**
 * Enable IP blocking for suspicious activity.
 *
 * @filter af_enable_ip_blocking
 */
define( 'AF_ENABLE_IP_BLOCKING', apply_filters( 'af_enable_ip_blocking', true ) );

/**
 * IP block threshold (failed login attempts before blocking).
 *
 * @filter af_ip_block_threshold
 */
define( 'AF_IP_BLOCK_THRESHOLD', apply_filters( 'af_ip_block_threshold', 10 ) );

/**
 * IP block duration (in minutes).
 *
 * @filter af_ip_block_duration
 */
define( 'AF_IP_BLOCK_DURATION', apply_filters( 'af_ip_block_duration', 30 ) );

// ========================
// WEBHOOK SECURITY
// ========================

/**
 * Webhook signature algorithm.
 *
 * Options: 'sha256', 'sha1'
 *
 * @filter af_webhook_signature_algorithm
 */
define( 'AF_WEBHOOK_SIGNATURE_ALGORITHM', apply_filters( 'af_webhook_signature_algorithm', 'sha256' ) );

/**
 * Webhook request timeout (in seconds).
 *
 * @filter af_webhook_timeout
 */
define( 'AF_WEBHOOK_TIMEOUT', apply_filters( 'af_webhook_timeout', 10 ) );

/**
 * Webhook retry attempts.
 *
 * @filter af_webhook_max_retries
 */
define( 'AF_WEBHOOK_MAX_RETRIES', apply_filters( 'af_webhook_max_retries', 5 ) );

// ========================
// FILE SECURITY
// ========================

/**
 * Maximum upload file size (in MB).
 *
 * @filter af_max_upload_size_mb
 */
define( 'AF_MAX_UPLOAD_SIZE_MB', apply_filters( 'af_max_upload_size_mb', 12 ) );

/**
 * Allowed file types for upload.
 *
 * @filter af_allowed_upload_types
 */
define( 'AF_ALLOWED_UPLOAD_TYPES', apply_filters( 'af_allowed_upload_types', array( 'pdf', 'docx', 'xlsx', 'jpg', 'png' ) ) );

/**
 * Scan files for malware.
 *
 * @filter af_scan_uploads_for_malware
 */
define( 'AF_SCAN_UPLOADS_FOR_MALWARE', apply_filters( 'af_scan_uploads_for_malware', false ) );

/**
 * Store uploads in private directory (outside webroot).
 *
 * @filter af_store_uploads_privately
 */
define( 'AF_STORE_UPLOADS_PRIVATELY', apply_filters( 'af_store_uploads_privately', true ) );
