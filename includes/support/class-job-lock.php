<?php
/**
 * Cross-node mutual exclusion for background jobs.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Job_Lock
 *
 * Uses the UNIQUE option_name of wp_options as an atomic lock, so it works
 * behind a load balancer with the shared database alone (no Redis needed):
 * when two web nodes fire the same WP-Cron event, only one runs it.
 */
class Arriendo_Facil_Job_Lock {

	const PREFIX = 'af_job_lock_';

	/**
	 * Acquires a lock. Stale locks (past their TTL) are reclaimed.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds before the lock is considered stale.
	 * @return string|false Owner token, or false when another process holds it.
	 */
	public static function acquire( $name, $ttl ) {
		global $wpdb;

		$option = self::PREFIX . sanitize_key( $name );
		$token  = wp_generate_password( 20, false );
		$value  = ( time() + max( 1, (int) $ttl ) ) . '|' . $token;

		// INSERT IGNORE is atomic on the UNIQUE option_name key.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option,
				$value
			)
		);
		if ( 1 === (int) $inserted ) {
			return $token;
		}

		$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$expires = (int) strtok( $current, '|' );
		if ( $expires > time() ) {
			return false;
		}

		// Reclaim a stale lock only if nobody else reclaimed it first.
		$reclaimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$option,
				$current
			)
		);

		return 1 === (int) $reclaimed ? $token : false;
	}

	/**
	 * Releases a lock held by the given token.
	 *
	 * @param string $name  Lock name.
	 * @param string $token Owner token returned by acquire().
	 * @return void
	 */
	public static function release( $name, $token ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				self::PREFIX . sanitize_key( $name ),
				'%|' . $wpdb->esc_like( (string) $token )
			)
		);
	}

	/**
	 * Runs a callback only if the lock can be acquired.
	 *
	 * @param string   $name     Lock name.
	 * @param int      $ttl      Lock TTL in seconds.
	 * @param callable $callback Work to run.
	 * @return bool Whether the callback ran.
	 */
	public static function run( $name, $ttl, callable $callback ) {
		$token = self::acquire( $name, $ttl );
		if ( false === $token ) {
			return false;
		}

		try {
			call_user_func( $callback );
		} finally {
			self::release( $name, $token );
		}

		return true;
	}

	/**
	 * Wraps a cron callback so concurrent nodes never run it twice at once.
	 *
	 * @param string   $hook     Cron hook name (used as the lock name).
	 * @param callable $callback Original callback.
	 * @param int      $ttl      Lock TTL in seconds.
	 * @return callable
	 */
	public static function guard( $hook, callable $callback, $ttl = 15 * MINUTE_IN_SECONDS ) {
		return static function () use ( $hook, $callback, $ttl ) {
			$args = func_get_args();
			self::run(
				'cron_' . $hook,
				$ttl,
				static function () use ( $callback, $args ) {
					call_user_func_array( $callback, $args );
				}
			);
		};
	}
}
