<?php
/**
 * Tests for Arriendo_Facil_Guest::ajax_update_guest — the admin endpoint that
 * corrects a tenant's record from the profile page.
 *
 * Covers the rules that are easy to get wrong and expensive in production:
 * tenancy scoping, the unique-email constraint, Ecuadorian check-digit
 * validation, and the fact that changing the identity resets the document
 * review instead of keeping a "verified" badge that no longer applies.
 *
 * The real Arriendo_Facil_Tenancy and Arriendo_Facil_Identity_Validator are
 * loaded (not doubled) so the assertions exercise production logic; only the
 * WordPress plumbing is stubbed.
 *
 * @package Arriendo_Facil
 */

namespace {

	if ( ! function_exists( 'add_action' ) ) {
		function add_action() { return true; }
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter() { return true; }
	}
	if ( ! function_exists( 'add_shortcode' ) ) {
		function add_shortcode() { return true; }
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ) { return abs( (int) $value ); }
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) { return $value; }
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) { return trim( (string) $value ); }
	}
	if ( ! function_exists( 'sanitize_email' ) ) {
		function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_EMAIL ); }
	}
	if ( ! function_exists( 'is_email' ) ) {
		function is_email( $value ) { return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ); }
	}
	if ( ! function_exists( 'check_ajax_referer' ) ) {
		/**
		 * Nonce check stub. Fails loudly when the test wants to exercise a
		 * missing/invalid nonce.
		 *
		 * @param string $action Nonce action.
		 * @param string $query_arg Request key.
		 * @return true
		 */
		function check_ajax_referer( $action, $query_arg = false ) {
			if ( ! empty( $GLOBALS['af_test_invalid_nonce'] ) ) {
				throw new \AF_Ajax_Response_Exception( false, array( 'message' => 'Nonce invalido.' ) );
			}

			return true;
		}
	}
	if ( ! function_exists( 'get_post_type' ) ) {
		function get_post_type( $post_id ) { return 77 === (int) $post_id ? 'accommodation' : ''; }
	}
	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() { return 5; }
	}
	if ( ! function_exists( 'user_can' ) ) {
		// The operator is not a global admin: tenancy scoping must apply.
		function user_can( $user_id, $cap ) { return false; }
	}
	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( $cap ) { return ! empty( $GLOBALS['af_test_can_manage'] ); }
	}
	if ( ! function_exists( 'get_post_meta' ) ) {
		// Property 77 belongs to the operator (user 5); nothing else is theirs.
		function get_post_meta( $post_id, $key = '', $single = false ) {
			if ( 77 === (int) $post_id && '_af_owner_id' === $key ) {
				return 5;
			}

			return '';
		}
	}

	/**
	 * Thrown in place of wp_send_json_*() so assertions can inspect the payload.
	 */
	class AF_Ajax_Response_Exception extends \Exception {
		/** @var bool Whether the response was a success. */
		public $success;
		/** @var array Response payload. */
		public $payload;

		/**
		 * @param bool  $success Whether it succeeded.
		 * @param array $payload Response payload.
		 */
		public function __construct( $success, array $payload ) {
			parent::__construct( isset( $payload['message'] ) ? (string) $payload['message'] : '' );
			$this->success = (bool) $success;
			$this->payload = $payload;
		}
	}

	if ( ! function_exists( 'wp_send_json_success' ) ) {
		function wp_send_json_success( $data = null, $status_code = null ) {
			throw new AF_Ajax_Response_Exception( true, is_array( $data ) ? $data : array() );
		}
	}
	if ( ! function_exists( 'wp_send_json_error' ) ) {
		function wp_send_json_error( $data = null, $status_code = null ) {
			throw new AF_Ajax_Response_Exception( false, is_array( $data ) ? $data : array() );
		}
	}

	/**
	 * Recording wpdb double: keeps the stored tenant row in memory so the test
	 * can assert what the endpoint actually persisted.
	 */
	class AF_Guest_WPDB_Stub {
		/** @var string Table prefix. */
		public $prefix = 'wp_';
		/** @var array<int,object> Tenants by id. */
		public $rows = array();
		/** @var array<int,array> Captured update payloads. */
		public $updates = array();

		/**
		 * @param string $query Query.
		 * @param mixed  ...$args Args.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			return vsprintf( str_replace( array( '%d', '%f' ), '%s', $query ), $args );
		}

		/**
		 * @param string $query Query.
		 * @return object|null
		 */
		public function get_row( $query ) {
			$id = 0;
			if ( preg_match( '/id = (\d+)/', (string) $query, $m ) ) {
				$id = (int) $m[1];
			}

			return isset( $this->rows[ $id ] ) ? $this->rows[ $id ] : null;
		}

		/**
		 * Serves the two lookups the endpoint performs.
		 *
		 * @param string $query Query.
		 * @return int
		 */
		public function get_var( $query ) {
			$query = (string) $query;

			// Tenancy lookup: "SELECT accommodation_id ... WHERE id = %d".
			if ( false !== strpos( $query, 'SELECT accommodation_id' ) ) {
				$id = preg_match( '/id = (\d+)/', $query, $m ) ? (int) $m[1] : 0;

				return isset( $this->rows[ $id ] ) ? (int) $this->rows[ $id ]->accommodation_id : 0;
			}

			// Duplicate email lookup: "WHERE email = %s AND id <> %d". The
			// endpoint must refuse to steal a tenant that already owns it.
			if ( false !== strpos( $query, 'SELECT id' ) ) {
				if ( ! preg_match( "/email = '?([^\\s']+)'?\\s+AND id/i", $query, $email_match ) ) {
					return 0;
				}
				$email   = $email_match[1];
				$exclude = preg_match( '/id <> (\d+)/', $query, $id_match ) ? (int) $id_match[1] : 0;

				foreach ( $this->rows as $id => $row ) {
					if ( $row->email === $email && (int) $id !== $exclude ) {
						return (int) $id;
					}
				}
			}

			return 0;
		}

		/**
		 * @param string $table        Table.
		 * @param array  $data         Data.
		 * @param array  $where        Where.
		 * @param array  $format       Formats.
		 * @param array  $where_format Where formats.
		 * @return int
		 */
		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$this->updates[] = $data;
			$id              = isset( $where['id'] ) ? (int) $where['id'] : 0;
			if ( isset( $this->rows[ $id ] ) ) {
				$this->rows[ $id ] = (object) array_merge( (array) $this->rows[ $id ], $data );
			}

			return 1;
		}
	}

	require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-tenancy.php';
	require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-identity-validator.php';
	require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-guest.php';
}

namespace ArriendoFacil\Tests\Security {

	use PHPUnit\Framework\TestCase;

	/**
	 * Class GuestUpdateTest
	 */
	class GuestUpdateTest extends TestCase {

		/**
		 * Valid Ecuadorian cédula for the check-digit assertions.
		 */
		const VALID_CEDULA = '1700000001';

		/**
		 * @var Arriendo_Facil_Guest
		 */
		private $guest;

		/**
		 * @var AF_Guest_WPDB_Stub
		 */
		private $db;

		/**
		 * Builds a tenant row and a fresh endpoint instance.
		 *
		 * @param array $overrides Column overrides for the stored tenant.
		 * @return void
		 */
		private function givenStoredTenant( array $overrides = array() ) {
			$this->db            = new \AF_Guest_WPDB_Stub();
			$this->db->rows[11] = (object) array_merge(
				array(
					'id'                    => 11,
					'first_name'            => 'Ana',
					'last_name'             => 'Ruiz',
					'email'                 => 'ana@example.com',
					'phone'                 => '0991234567',
					'id_number'             => self::VALID_CEDULA,
					'nationality'           => 'Ecuatoriana',
					'birth_city'            => 'Quito',
					'accommodation_id'      => 77,
					'doc_status'            => 'verificado',
					'identity_match_status' => 'match',
				),
				$overrides
			);

			$GLOBALS['wpdb']              = $this->db;
			$GLOBALS['af_test_can_manage'] = true;
			$GLOBALS['af_test_guest_id']   = 11;
			$GLOBALS['af_test_duplicate_email'] = '';

			// Ownership for the tenancy checks. Seeded as a global because
			// OccupancyTest.php may already own the get_post_meta() stub in
			// this process.
			if ( ! isset( $GLOBALS['af_meta'] ) || ! is_array( $GLOBALS['af_meta'] ) ) {
				$GLOBALS['af_meta'] = array();
			}
			$GLOBALS['af_meta'][77]['_af_owner_id'] = 5;

			$this->guest = new \Arriendo_Facil_Guest();
		}

		/**
		 * @return void
		 */
		protected function setUp(): void {
			$this->givenStoredTenant();
		}

		/**
		 * Runs the endpoint and returns the response it tried to send.
		 *
		 * @param array $post POST payload.
		 * @return AF_Ajax_Response_Exception
		 */
		private function callEndpoint( array $post ) {
			$_POST = $post;

			try {
				$this->guest->ajax_update_guest();
			} catch ( \AF_Ajax_Response_Exception $e ) {
				return $e;
			}

			$this->fail( 'The endpoint did not return a response.' );
		}

		/**
		 * A valid correction persists and leaves the document review alone.
		 */
		public function test_corrects_phone_without_touching_document_review() {
			$response = $this->callEndpoint(
				array(
					'guest_id'          => 11,
					'first_name'       => 'Ana Maria',
					'last_name'        => 'Ruiz',
					'email'            => 'ana@example.com',
					'phone'            => '0987654321',
					'id_number'        => self::VALID_CEDULA,
					'nationality'      => 'Ecuatoriana',
					'birth_city'       => 'Quito',
					'accommodation_id' => 77,
				)
			);

			$this->assertTrue( $response->success, $response->getMessage() );
			$this->assertSame( '0987654321', $this->db->rows[11]->phone );
			$this->assertSame( 'Ana Maria', $this->db->rows[11]->first_name );
			$this->assertSame( 'verificado', $this->db->rows[11]->doc_status );
			$this->assertArrayNotHasKey( 'doc_status', $this->db->updates[0] );
		}

		/**
		 * Changing the identity number invalidates a previous verification.
		 */
		public function test_changing_id_number_resets_document_review() {
			$this->givenStoredTenant( array( 'id_number' => '1700000002' ) );

			$response = $this->callEndpoint(
				array(
					'guest_id'          => 11,
					'first_name'       => 'Ana',
					'last_name'        => 'Ruiz',
					'email'            => 'ana@example.com',
					'phone'            => '0991234567',
					'id_number'        => self::VALID_CEDULA,
					'accommodation_id' => 0,
				)
			);

			$this->assertTrue( $response->success, $response->getMessage() );
			$this->assertTrue( $response->payload['identity_changed'] );
			$this->assertSame( 'pendiente', $this->db->rows[11]->doc_status );
			$this->assertSame( 'not_checked', $this->db->rows[11]->identity_match_status );
		}

		/**
		 * Unlinking a property stores 0, matching the creation flow.
		 */
		public function test_can_unlink_property() {
			$response = $this->callEndpoint(
				array(
					'guest_id'          => 11,
					'first_name'       => 'Ana',
					'last_name'        => 'Ruiz',
					'email'            => 'ana@example.com',
					'accommodation_id' => 0,
				)
			);

			$this->assertTrue( $response->success, $response->getMessage() );
			$this->assertSame( 0, (int) $this->db->rows[11]->accommodation_id );
		}

		/**
		 * A tenant outside the operator's scope is rejected.
		 */
		public function test_rejects_guest_outside_tenancy() {
			$this->givenStoredTenant();
			$this->db->rows[12] = (object) array(
				'id'               => 12,
				'email'            => 'otro@example.com',
				'accommodation_id' => 99,
			);

			$response = $this->callEndpoint(
				array(
					'guest_id'          => 12,
					'first_name'       => 'Otro',
					'email'            => 'otro@example.com',
					'accommodation_id' => 0,
				)
			);

			$this->assertFalse( $response->success );
			$this->assertCount( 0, $this->db->updates );
		}

		/**
		 * A property the operator does not manage is rejected.
		 */
		public function test_rejects_property_outside_tenancy() {
			$response = $this->callEndpoint(
				array(
					'guest_id'          => 11,
					'first_name'       => 'Ana',
					'email'            => 'ana@example.com',
					'accommodation_id' => 99,
				)
			);

			$this->assertFalse( $response->success );
			$this->assertCount( 0, $this->db->updates );
		}

		/**
		 * Operators without the manage-properties capability are rejected.
		 */
		public function test_rejects_user_without_capability() {
			$GLOBALS['af_test_can_manage'] = false;

			$response = $this->callEndpoint(
				array(
					'guest_id'    => 11,
					'first_name' => 'Ana',
					'email'      => 'ana@example.com',
				)
			);

			$this->assertFalse( $response->success );
			$this->assertCount( 0, $this->db->updates );
		}

		/**
		 * The unique email index is checked before writing.
		 */
		public function test_rejects_duplicate_email() {
			$this->db->rows[12] = (object) array(
				'id'               => 12,
				'email'            => 'otro@example.com',
				'accommodation_id' => 77,
			);

			$response = $this->callEndpoint(
				array(
					'guest_id'    => 11,
					'first_name' => 'Ana',
					'email'      => 'otro@example.com',
				)
			);

			$this->assertFalse( $response->success );
			$this->assertCount( 0, $this->db->updates );
		}

		/**
		 * Keeping the tenant's own email is not a duplicate.
		 */
		public function test_allows_saving_unchanged_email() {
			$this->db->rows[12] = (object) array(
				'id'               => 12,
				'email'            => 'otro@example.com',
				'accommodation_id' => 77,
			);

			$response = $this->callEndpoint(
				array(
					'guest_id'    => 11,
					'first_name' => 'Ana',
					'email'      => 'ana@example.com',
				)
			);

			$this->assertTrue( $response->success, $response->getMessage() );
		}

		/**
		 * An invalid check digit is refused even when editing.
		 */
		public function test_rejects_invalid_check_digit() {
			$this->givenStoredTenant( array( 'id_number' => '1700000002' ) );

			$response = $this->callEndpoint(
				array(
					'guest_id'    => 11,
					'first_name' => 'Ana',
					'email'      => 'ana@example.com',
					'id_number'  => '1234567890',
				)
			);

			$this->assertFalse( $response->success );
			$this->assertCount( 0, $this->db->updates );
		}

		/**
		 * A malformed email is refused.
		 */
		public function test_rejects_invalid_email() {
			$response = $this->callEndpoint(
				array(
					'guest_id'    => 11,
					'first_name' => 'Ana',
					'email'      => 'not-an-email',
				)
			);

			$this->assertFalse( $response->success );
		}

		/**
		 * A non-numeric phone is refused.
		 */
		public function test_rejects_invalid_phone() {
			$response = $this->callEndpoint(
				array(
					'guest_id'    => 11,
					'first_name' => 'Ana',
					'email'      => 'ana@example.com',
					'phone'      => '099-ABC',
				)
			);

			$this->assertFalse( $response->success );
		}

		/**
		 * A missing first name is refused.
		 */
		public function test_rejects_missing_name() {
			$response = $this->callEndpoint(
				array(
					'guest_id' => 11,
					'email'    => 'ana@example.com',
				)
			);

			$this->assertFalse( $response->success );
		}
	}
}
