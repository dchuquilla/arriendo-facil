<?php
/**
 * Unit tests for the pure (non-DB) helpers of Arriendo_Facil_Idempotency.
 *
 * DB-backed behaviour (remember/dedupe against MySQL) is covered by the
 * integration tests that run under WordPress; this file only exercises the
 * deterministic helpers to keep the standalone bootstrap fast and dependency-free.
 *
 * @package Arriendo_Facil\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-idempotency.php';

/**
 * Class IdempotencyTest
 */
class IdempotencyTest extends TestCase {

	public function test_uuid_v4_shape_and_uniqueness(): void {
		$a = Arriendo_Facil_Idempotency::uuid_v4();
		$b = Arriendo_Facil_Idempotency::uuid_v4();

		$this->assertNotSame( $a, $b );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$a
		);
		$this->assertTrue( Arriendo_Facil_Idempotency::is_valid_uuid( $a ) );
	}

	public function test_ulid_shape_and_ordering(): void {
		$earlier = Arriendo_Facil_Idempotency::ulid( 1_000_000_000_000 );
		$later   = Arriendo_Facil_Idempotency::ulid( 2_000_000_000_000 );

		$this->assertSame( 26, strlen( $earlier ) );
		$this->assertSame( 26, strlen( $later ) );
		// ULID is lexicographically time-ordered.
		$this->assertLessThan( 0, strcmp( $earlier, $later ) );
		// Only Crockford Base32 characters.
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $earlier );
	}

	public function test_hybrid_id_contains_prefix(): void {
		$id = Arriendo_Facil_Idempotency::hybrid_id( 'lease' );
		$this->assertStringStartsWith( 'lease_', $id );
		$parts = explode( '_', $id );
		$this->assertCount( 3, $parts );
		$this->assertSame( 26, strlen( $parts[1] ) ); // ULID.
		$this->assertMatchesRegularExpression( '/^\d+$/', $parts[2] ); // Epoch.
	}

	public function test_hybrid_id_sanitises_prefix(): void {
		$id = Arriendo_Facil_Idempotency::hybrid_id( 'unsafe prefix!@#' );
		$this->assertStringStartsWith( 'unsafeprefix_', $id );
	}

	public function test_fingerprint_is_deterministic_and_key_order_stable(): void {
		$payload_a = array( 'a' => 1, 'b' => 2, 'nested' => array( 'x' => 'y' ) );
		$payload_b = array( 'b' => 2, 'nested' => array( 'x' => 'y' ), 'a' => 1 );

		$hash_a = Arriendo_Facil_Idempotency::fingerprint( $payload_a );
		$hash_b = Arriendo_Facil_Idempotency::fingerprint( $payload_b );

		$this->assertSame( 64, strlen( $hash_a ) );
		$this->assertSame( $hash_a, $hash_b );

		// Different content → different hash.
		$this->assertNotSame(
			$hash_a,
			Arriendo_Facil_Idempotency::fingerprint( array( 'a' => 1, 'b' => 3 ) )
		);
	}

	public function test_fingerprint_preserves_list_order(): void {
		$hash_a = Arriendo_Facil_Idempotency::fingerprint( array( 1, 2, 3 ) );
		$hash_b = Arriendo_Facil_Idempotency::fingerprint( array( 3, 2, 1 ) );
		$this->assertNotSame( $hash_a, $hash_b, 'List order must matter.' );
	}

	public function test_in_flight_and_conflict_sentinels(): void {
		$this->assertTrue( Arriendo_Facil_Idempotency::is_in_flight( array( '__af_idem_in_flight' => true ) ) );
		$this->assertFalse( Arriendo_Facil_Idempotency::is_in_flight( array( 'ok' => true ) ) );
		$this->assertTrue( Arriendo_Facil_Idempotency::is_conflict( array( '__af_idem_conflict' => true ) ) );
		$this->assertFalse( Arriendo_Facil_Idempotency::is_conflict( array( 'ok' => true ) ) );
	}

	public function test_is_valid_uuid_rejects_bad_values(): void {
		$this->assertFalse( Arriendo_Facil_Idempotency::is_valid_uuid( 'not-a-uuid' ) );
		$this->assertFalse( Arriendo_Facil_Idempotency::is_valid_uuid( '' ) );
		$this->assertFalse( Arriendo_Facil_Idempotency::is_valid_uuid( '550e8400-e29b-71d4-a716-446655440000' ) ); // v7 label, invalid version digit here.
	}

	public function test_key_from_request_reads_headers_and_params(): void {
		$_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = '550e8400-e29b-41d4-a716-446655440000';
		$this->assertSame( '550e8400-e29b-41d4-a716-446655440000', Arriendo_Facil_Idempotency::key_from_request() );
		unset( $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] );

		$_POST['idempotency_key'] = ' abc123 ';
		$this->assertSame( 'abc123', Arriendo_Facil_Idempotency::key_from_request() );
		unset( $_POST['idempotency_key'] );

		$_POST['idempotency_key'] = 'has spaces';
		$this->assertNull( Arriendo_Facil_Idempotency::key_from_request(), 'Invalid chars must be rejected.' );
		unset( $_POST['idempotency_key'] );

		$_POST['idempotency_key'] = str_repeat( 'a', 200 );
		$this->assertNull( Arriendo_Facil_Idempotency::key_from_request(), 'Overlong keys must be rejected.' );
		unset( $_POST['idempotency_key'] );
	}
}
