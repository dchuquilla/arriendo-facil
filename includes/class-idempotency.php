<?php
/**
 * Idempotency service.
 *
 * Provides three concerns in a single class:
 *   1. Cryptographically-strong unique ID generators (UUIDv4, ULID, hybrid).
 *   2. An "Idempotency-Key" cache that lets endpoints replay a previous
 *      successful response when the same request is re-sent.
 *   3. Payload-fingerprint deduplication for webhooks / server-to-server calls
 *      where the client cannot generate an Idempotency-Key.
 *
 * Design goals:
 *   - Zero regressions: callers that do not opt-in behave exactly as before.
 *   - Safe on activation: falls back to transients if the DB table is missing.
 *   - Atomicity guaranteed by MySQL UNIQUE constraint, not by check-then-act.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Idempotency
 */
class Arriendo_Facil_Idempotency {

	/** Default TTL for cached responses (24 h). */
	const DEFAULT_TTL_SECONDS = 86400;

	/** Lock window used while a request is still in flight. */
	const IN_FLIGHT_SECONDS = 30;

	/** Status constants. */
	const STATUS_IN_PROGRESS = 'in_progress';
	const STATUS_COMPLETED   = 'completed';
	const STATUS_FAILED      = 'failed';

	/**
	 * Returns the fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'af_idempotency_keys';
	}

	// ─────────────────────────────────────────────────────────────────────
	// ID generators
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Generates a RFC-4122 v4 UUID (128 bits of randomness).
	 *
	 * Uses random_bytes (CSPRNG). Falls back to WordPress helper only when
	 * random_bytes is unavailable.
	 *
	 * @return string
	 */
	public static function uuid_v4(): string {
		try {
			$data = random_bytes( 16 );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wp_generate_uuid4' ) ) {
				return wp_generate_uuid4();
			}
			// Extremely unlikely fallback; still 128 bits of entropy from mt_rand.
			$data = '';
			for ( $i = 0; $i < 16; $i++ ) {
				$data .= chr( mt_rand( 0, 255 ) );
			}
		}

		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 ); // version 4.
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 ); // variant RFC-4122.

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	/**
	 * Generates a ULID (26 chars, Crockford Base32, lexicographically sortable).
	 *
	 * @param float|null $timestamp_ms Optional timestamp in milliseconds.
	 * @return string
	 */
	public static function ulid( $timestamp_ms = null ): string {
		$alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

		if ( null === $timestamp_ms ) {
			$timestamp_ms = (int) floor( microtime( true ) * 1000 );
		}

		// Time component: 48 bits, encoded as 10 chars.
		$time_chars = '';
		$time_int   = (int) $timestamp_ms;
		for ( $i = 0; $i < 10; $i++ ) {
			$time_chars = $alphabet[ $time_int & 0x1f ] . $time_chars;
			$time_int   = intdiv( $time_int, 32 );
		}

		// Randomness: 80 bits, encoded as 16 chars.
		try {
			$random = random_bytes( 10 );
		} catch ( \Throwable $e ) {
			$random = '';
			for ( $i = 0; $i < 10; $i++ ) {
				$random .= chr( mt_rand( 0, 255 ) );
			}
		}

		$rand_chars = '';
		$bits       = 0;
		$acc        = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$acc  = ( $acc << 8 ) | ord( $random[ $i ] );
			$bits += 8;
			while ( $bits >= 5 ) {
				$bits       -= 5;
				$rand_chars .= $alphabet[ ( $acc >> $bits ) & 0x1f ];
			}
		}
		if ( $bits > 0 ) {
			$rand_chars .= $alphabet[ ( $acc << ( 5 - $bits ) ) & 0x1f ];
		}
		$rand_chars = substr( $rand_chars, 0, 16 );

		return $time_chars . $rand_chars;
	}

	/**
	 * Generates a hybrid, human-inspectable ID: "{prefix}_{ulid}_{ts}".
	 *
	 * @param string $prefix Semantic prefix (e.g. "lease", "inv", "res").
	 * @return string
	 */
	public static function hybrid_id( string $prefix ): string {
		$safe_prefix = preg_replace( '/[^a-z0-9]/i', '', $prefix );
		$safe_prefix = '' === $safe_prefix ? 'id' : strtolower( $safe_prefix );
		return $safe_prefix . '_' . self::ulid() . '_' . time();
	}

	/**
	 * Checks whether a string is a syntactically valid UUID.
	 *
	 * @param string $value Value to validate.
	 * @return bool
	 */
	public static function is_valid_uuid( string $value ): bool {
		return (bool) preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}

	/**
	 * Computes a deterministic SHA-256 fingerprint from an arbitrary payload.
	 *
	 * @param mixed $payload Payload to hash.
	 * @return string 64-char lowercase hex.
	 */
	public static function fingerprint( $payload ): string {
		if ( is_string( $payload ) ) {
			$serialized = $payload;
		} elseif ( is_array( $payload ) ) {
			// Sort recursively for stability regardless of key order.
			$normalized = self::normalize_for_hash( $payload );
			$serialized = wp_json_encode( $normalized );
		} else {
			$serialized = wp_json_encode( $payload );
		}
		return hash( 'sha256', (string) $serialized );
	}

	/**
	 * Recursively ksorts arrays for stable fingerprinting.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private static function normalize_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		// Only ksort associative arrays. Preserve order of numeric lists.
		$is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
		if ( $is_assoc ) {
			ksort( $value );
		}
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::normalize_for_hash( $v );
		}
		return $value;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Idempotency-Key resolution helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Extracts an idempotency key from the current request without failing.
	 *
	 * Accepts (in order):
	 *   - HTTP header "X-Idempotency-Key" or "Idempotency-Key".
	 *   - POST/GET param "idempotency_key".
	 *
	 * @return string|null Sanitized key or null when not provided/invalid.
	 */
	public static function key_from_request(): ?string {
		$candidates = array();

		$unslash = static function ( $value ) {
			return function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
		};

		if ( isset( $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ) ) {
			$candidates[] = (string) $_SERVER['HTTP_X_IDEMPOTENCY_KEY'];
		}
		if ( isset( $_SERVER['HTTP_IDEMPOTENCY_KEY'] ) ) {
			$candidates[] = (string) $_SERVER['HTTP_IDEMPOTENCY_KEY'];
		}
		if ( isset( $_POST['idempotency_key'] ) ) {
			$candidates[] = (string) $unslash( $_POST['idempotency_key'] );
		}
		if ( isset( $_GET['idempotency_key'] ) ) {
			$candidates[] = (string) $unslash( $_GET['idempotency_key'] );
		}

		foreach ( $candidates as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			// Accept UUIDs and ULIDs (both are 26/36 chars, alphanumeric + dashes).
			if ( strlen( $candidate ) > 128 ) {
				continue;
			}
			if ( ! preg_match( '/^[A-Za-z0-9._\\-]+$/', $candidate ) ) {
				continue;
			}
			return $candidate;
		}

		return null;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Core "remember" / replay primitive
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Runs a callable under an idempotency guard.
	 *
	 * Semantics:
	 *   - First call with (scope, key) → executes $work and caches its return value.
	 *   - Subsequent successful calls with the same (scope, key) → returns cached value.
	 *   - Subsequent call arriving while first is still running → returns a
	 *     ['__af_idem_in_flight' => true] sentinel; callers decide how to respond.
	 *   - Callable returning a WP_Error is NOT cached (allows real retry).
	 *   - If $fingerprint is provided and does not match the stored one, returns
	 *     ['__af_idem_conflict' => true] (same key, different payload).
	 *
	 * @param string        $scope       Namespacing scope, e.g. "af_issue_invoice".
	 * @param string        $key         Idempotency key.
	 * @param int           $ttl_seconds How long to remember completed responses.
	 * @param callable      $work        Producer callable.
	 * @param string|null   $fingerprint Optional payload SHA-256 to detect conflicts.
	 * @return mixed Cached or freshly produced value; sentinel arrays on race/conflict.
	 */
	public static function remember( string $scope, string $key, int $ttl_seconds, callable $work, ?string $fingerprint = null ) {
		if ( '' === $scope || '' === $key ) {
			return $work();
		}

		$ttl_seconds = $ttl_seconds > 0 ? $ttl_seconds : self::DEFAULT_TTL_SECONDS;

		if ( ! self::table_exists() ) {
			// Safe fallback for environments where activator hasn't run yet.
			return self::remember_via_transient( $scope, $key, $ttl_seconds, $work );
		}

		global $wpdb;
		$table = self::table();

		$now_gmt     = gmdate( 'Y-m-d H:i:s' );
		$expires_gmt = gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds );
		$user_id     = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$fp          = $fingerprint ?? '';

		// Atomic insert: UNIQUE constraint on (scope, idempotency_key) is the gate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
					(scope, idempotency_key, user_id, request_hash, status, locked_at, expires_at, created_at)
				 VALUES (%s, %s, %d, %s, %s, %s, %s, %s)",
				$scope,
				$key,
				$user_id,
				$fp,
				self::STATUS_IN_PROGRESS,
				$now_gmt,
				$expires_gmt,
				$now_gmt
			)
		);

		// $inserted === 0 → row already exists (retry). $inserted === 1 → fresh.
		if ( 0 === (int) $inserted ) {
			$record = self::fetch_record( $scope, $key );
			if ( ! $record ) {
				// Concurrent delete or DB race: fall through to plain execution.
				return $work();
			}

			// Payload fingerprint mismatch → hard conflict.
			if ( '' !== $fp && '' !== (string) $record->request_hash && $fp !== (string) $record->request_hash ) {
				return array( '__af_idem_conflict' => true );
			}

			if ( self::STATUS_COMPLETED === (string) $record->status && null !== $record->response_body ) {
				$cached = json_decode( (string) $record->response_body, true );
				if ( null !== $cached ) {
					return $cached;
				}
			}

			if ( self::STATUS_IN_PROGRESS === (string) $record->status ) {
				$locked_ts = $record->locked_at ? strtotime( (string) $record->locked_at . ' UTC' ) : 0;
				if ( $locked_ts && ( time() - $locked_ts ) < self::IN_FLIGHT_SECONDS ) {
					return array( '__af_idem_in_flight' => true );
				}
				// Stale lock → reclaim by continuing execution.
			}
			// STATUS_FAILED or stale in_progress → re-run.
		}

		try {
			$result = $work();
		} catch ( \Throwable $t ) {
			self::update_record( $scope, $key, self::STATUS_FAILED, null );
			throw $t;
		}

		// Do not cache WP_Error responses so genuine errors remain retryable.
		if ( is_wp_error( $result ) ) {
			self::update_record( $scope, $key, self::STATUS_FAILED, null );
			return $result;
		}

		$encoded = wp_json_encode( $result );
		self::update_record( $scope, $key, self::STATUS_COMPLETED, is_string( $encoded ) ? $encoded : null );

		return $result;
	}

	/**
	 * Determines whether a value returned by remember() is the in-flight sentinel.
	 *
	 * @param mixed $value Value returned by remember().
	 * @return bool
	 */
	public static function is_in_flight( $value ): bool {
		return is_array( $value ) && ! empty( $value['__af_idem_in_flight'] );
	}

	/**
	 * Determines whether a value returned by remember() is the conflict sentinel.
	 *
	 * @param mixed $value Value returned by remember().
	 * @return bool
	 */
	public static function is_conflict( $value ): bool {
		return is_array( $value ) && ! empty( $value['__af_idem_conflict'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Deduplication for server-to-server / webhook flows
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Runs a callable only if the payload fingerprint has not been seen in $ttl.
	 * Ideal for webhooks that do not carry a client-supplied Idempotency-Key.
	 *
	 * @param string   $scope        Namespacing scope.
	 * @param string   $fingerprint  Payload fingerprint (see fingerprint()).
	 * @param int      $ttl_seconds  Deduplication window.
	 * @param callable $work         Callable to execute at most once per window.
	 * @return array{duplicate: bool, result?: mixed}
	 */
	public static function dedupe_by_fingerprint( string $scope, string $fingerprint, int $ttl_seconds, callable $work ): array {
		if ( '' === $fingerprint ) {
			return array( 'duplicate' => false, 'result' => $work() );
		}

		$key    = 'fp_' . $fingerprint;
		$result = self::remember( $scope, $key, $ttl_seconds, $work, $fingerprint );

		if ( self::is_in_flight( $result ) || self::is_conflict( $result ) ) {
			return array( 'duplicate' => true );
		}

		// If the underlying row already existed with completed status, remember() returned
		// the cached result. We treat that as a "duplicate" from the caller's perspective
		// only when the fingerprint was already stored — that is detected by comparing the
		// returned value's presence in cache. To keep the API simple we surface both cases
		// as non-duplicate here (the callable is executed at most once per window anyway).
		return array( 'duplicate' => false, 'result' => $result );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Persistence helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Checks whether the idempotency table exists (cached per request).
	 *
	 * @return bool
	 */
	private static function table_exists(): bool {
		static $exists = null;
		if ( null !== $exists ) {
			return $exists;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$exists = ( $found === $table );
		return $exists;
	}

	/**
	 * Fetches a stored idempotency record.
	 *
	 * @param string $scope Scope.
	 * @param string $key   Key.
	 * @return object|null
	 */
	private static function fetch_record( string $scope, string $key ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE scope = %s AND idempotency_key = %s LIMIT 1",
				$scope,
				$key
			)
		);
	}

	/**
	 * Updates the stored status and (optionally) response body.
	 *
	 * @param string      $scope         Scope.
	 * @param string      $key           Key.
	 * @param string      $status        New status.
	 * @param string|null $response_body Response body JSON, or null to leave alone.
	 * @return void
	 */
	private static function update_record( string $scope, string $key, string $status, ?string $response_body ): void {
		global $wpdb;
		$table = self::table();
		$data  = array(
			'status'       => $status,
			'completed_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$fmt   = array( '%s', '%s' );
		if ( null !== $response_body ) {
			$data['response_body'] = $response_body;
			$fmt[]                 = '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			$data,
			array(
				'scope'           => $scope,
				'idempotency_key' => $key,
			),
			$fmt,
			array( '%s', '%s' )
		);
	}

	/**
	 * Transient-based fallback used when the DB table isn't ready yet.
	 *
	 * @param string   $scope       Scope.
	 * @param string   $key         Key.
	 * @param int      $ttl_seconds TTL.
	 * @param callable $work        Callable.
	 * @return mixed
	 */
	private static function remember_via_transient( string $scope, string $key, int $ttl_seconds, callable $work ) {
		$cache_key = 'af_idem_' . md5( $scope . '|' . $key );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) && isset( $cached['result'] ) ) {
			return $cached['result'];
		}

		$in_flight_key = $cache_key . '_lock';
		if ( get_transient( $in_flight_key ) ) {
			return array( '__af_idem_in_flight' => true );
		}
		set_transient( $in_flight_key, 1, self::IN_FLIGHT_SECONDS );

		try {
			$result = $work();
		} finally {
			delete_transient( $in_flight_key );
		}

		if ( ! is_wp_error( $result ) ) {
			set_transient( $cache_key, array( 'result' => $result ), $ttl_seconds );
		}
		return $result;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Maintenance
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Deletes expired records. Wire to WP-Cron if desired.
	 *
	 * @return int Rows deleted.
	 */
	public static function purge_expired(): int {
		if ( ! self::table_exists() ) {
			return 0;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at < %s",
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}
}
