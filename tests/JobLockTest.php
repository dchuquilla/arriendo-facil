<?php
/**
 * Tests for Arriendo_Facil_Job_Lock (cross-node cron mutual exclusion).
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/support/class-job-lock.php';

/**
 * In-memory stand-in for the wp_options table honouring the UNIQUE option_name.
 */
class AF_Job_Lock_WPDB_Stub {
	public $options = 'wp_options';
	public $rows    = array();

	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return array( $query, $args );
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function query( $prepared ) {
		list( $sql, $args ) = $prepared;

		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			if ( isset( $this->rows[ $args[0] ] ) ) {
				return 0;
			}
			$this->rows[ $args[0] ] = $args[1];
			return 1;
		}

		if ( 0 === strpos( $sql, 'UPDATE' ) ) {
			if ( isset( $this->rows[ $args[1] ] ) && $this->rows[ $args[1] ] === $args[2] ) {
				$this->rows[ $args[1] ] = $args[0];
				return 1;
			}
			return 0;
		}

		if ( 0 === strpos( $sql, 'DELETE' ) ) {
			$token = substr( stripslashes( $args[1] ), 2 );
			if ( isset( $this->rows[ $args[0] ] ) && substr( $this->rows[ $args[0] ], -strlen( $token ) ) === $token ) {
				unset( $this->rows[ $args[0] ] );
				return 1;
			}
			return 0;
		}

		return 0;
	}

	public function get_var( $prepared ) {
		$args = $prepared[1];
		return isset( $this->rows[ $args[0] ] ) ? $this->rows[ $args[0] ] : null;
	}
}

class JobLockTest extends TestCase {

	private $previous_wpdb;

	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new AF_Job_Lock_WPDB_Stub();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
	}

	public function test_second_acquire_is_refused_while_held() {
		$this->assertNotFalse( Arriendo_Facil_Job_Lock::acquire( 'cron_x', 60 ) );
		$this->assertFalse( Arriendo_Facil_Job_Lock::acquire( 'cron_x', 60 ) );
	}

	public function test_release_allows_reacquire() {
		$token = Arriendo_Facil_Job_Lock::acquire( 'cron_x', 60 );
		Arriendo_Facil_Job_Lock::release( 'cron_x', $token );

		$this->assertNotFalse( Arriendo_Facil_Job_Lock::acquire( 'cron_x', 60 ) );
	}

	public function test_release_with_foreign_token_keeps_lock() {
		Arriendo_Facil_Job_Lock::acquire( 'cron_x', 60 );
		Arriendo_Facil_Job_Lock::release( 'cron_x', 'not-the-owner' );

		$this->assertFalse( Arriendo_Facil_Job_Lock::acquire( 'cron_x', 60 ) );
	}

	public function test_stale_lock_is_reclaimed() {
		$GLOBALS['wpdb']->rows['af_job_lock_cron_x'] = ( time() - 5 ) . '|oldtoken';

		$this->assertNotFalse( Arriendo_Facil_Job_Lock::acquire( 'cron_x', 60 ) );
	}

	public function test_guard_skips_callback_when_locked_and_releases_after_run() {
		$calls   = 0;
		$guarded = Arriendo_Facil_Job_Lock::guard(
			'af_test_cron',
			static function () use ( &$calls ) {
				$calls++;
			}
		);

		$guarded();
		$this->assertSame( 1, $calls );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows, 'Lock must be released after the run.' );

		Arriendo_Facil_Job_Lock::acquire( 'cron_af_test_cron', 60 );
		$guarded();
		$this->assertSame( 1, $calls, 'A concurrent node must not run the job.' );
	}
}
