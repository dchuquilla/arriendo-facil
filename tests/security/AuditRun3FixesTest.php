<?php
/**
 * Regression tests for the ownership and isolation defects found in audit run 3.
 *
 * Each test runs in its own process because it defines its own minimal WordPress
 * doubles (other test files define conflicting global functions); the real plugin
 * classes are loaded so the assertions exercise production logic.
 *
 * @package Arriendo_Facil
 */

namespace ArriendoFacil\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AuditRun3FixesTest extends TestCase {

	/**
	 * Defines the WordPress doubles shared by these tests.
	 *
	 * @param array<string,mixed> $opts caps: capabilities held by the current user; meta: post meta map.
	 * @return void
	 */
	private function boot( array $opts = array() ) {
		$GLOBALS['af_t_caps'] = isset( $opts['caps'] ) ? $opts['caps'] : array();
		$GLOBALS['af_t_meta'] = isset( $opts['meta'] ) ? $opts['meta'] : array();

		if ( ! function_exists( 'current_user_can' ) ) {
			eval( 'function current_user_can( $cap ) { return in_array( $cap, $GLOBALS["af_t_caps"], true ); }' );
		}
		if ( ! function_exists( 'get_current_user_id' ) ) {
			eval( 'function get_current_user_id() { return 5; }' );
		}
		if ( ! function_exists( 'user_can' ) ) {
			eval( 'function user_can( $user_id, $cap ) { return false; }' );
		}
		if ( ! function_exists( 'get_post_meta' ) ) {
			eval( 'function get_post_meta( $id, $key = "", $single = false ) { return isset( $GLOBALS["af_t_meta"][ (int) $id ][ $key ] ) ? $GLOBALS["af_t_meta"][ (int) $id ][ $key ] : ""; }' );
		}
		if ( ! function_exists( 'add_action' ) ) {
			eval( 'function add_action() { return true; }' );
		}
		if ( ! function_exists( 'add_filter' ) ) {
			eval( 'function add_filter() { return true; }' );
		}
		if ( ! function_exists( 'absint' ) ) {
			eval( 'function absint( $v ) { return abs( (int) $v ); }' );
		}
		if ( ! function_exists( 'wp_unslash' ) ) {
			eval( 'function wp_unslash( $v ) { return $v; }' );
		}
		if ( ! function_exists( 'check_ajax_referer' ) ) {
			eval( 'function check_ajax_referer() { return true; }' );
		}
		if ( ! class_exists( 'AF_T_Json' ) ) {
			eval( 'class AF_T_Json extends \Exception { public $ok; public $data; public function __construct( $ok, $data ) { parent::__construct( "json" ); $this->ok = $ok; $this->data = $data; } }' );
		}
		if ( ! function_exists( 'wp_send_json_success' ) ) {
			eval( 'function wp_send_json_success( $data = null, $code = null ) { throw new AF_T_Json( true, $data ); }' );
		}
		if ( ! function_exists( 'wp_send_json_error' ) ) {
			eval( 'function wp_send_json_error( $data = null, $code = null ) { throw new AF_T_Json( false, $data ); }' );
		}
		if ( ! function_exists( '__' ) ) {
			eval( 'function __( $t ) { return $t; }' );
		}

		$GLOBALS['wpdb'] = new class() {
			public $prefix  = 'wp_';
			public $queries = array();
			public $updates = array();
			public $rows    = array();
			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return vsprintf( str_replace( array( '%d', '%f' ), '%s', $query ), array_map( 'strval', $args ) );
			}
			public function get_var( $query ) {
				$this->queries[] = $query;
				foreach ( $this->rows as $needle => $row ) {
					if ( false !== strpos( (string) $query, (string) $needle ) && isset( $row->id ) ) {
						return $row->id;
					}
				}
				return 0;
			}
			public function get_results( $query ) {
				$this->queries[] = $query;
				return array();
			}
			public function get_row( $query ) {
				$this->queries[] = $query;
				foreach ( $this->rows as $needle => $row ) {
					if ( false !== strpos( (string) $query, (string) $needle ) ) {
						return $row;
					}
				}
				return null;
			}
			public function update( $table, $data, $where, $f = null, $wf = null ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
			public function query( $q ) {
				$this->queries[] = $q;
				return 1;
			}
			public function get_col( $q, $x = 0 ) {
				return array( 'id', 'guest_id', 'accommodation_id', 'user_id' );
			}
			public function insert( $t, $d, $f = null ) {
				return 1;
			}
			public function delete( $t, $w, $f = null ) {
				return 1;
			}
		};
	}

	/**
	 * A user with edit_posts who is not a property manager must not manage any accommodation.
	 */
	public function test_can_manage_accommodation_fails_closed_for_plain_edit_posts_user() {
		$this->boot( array( 'caps' => array( 'edit_posts' ) ) );
		require_once dirname( __DIR__, 2 ) . '/includes/class-tenancy.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-rental-workflow.php';

		$ref    = new \ReflectionClass( 'Arriendo_Facil_Rental_Workflow' );
		$obj    = $ref->newInstanceWithoutConstructor();
		$method = $ref->getMethod( 'can_manage_accommodation' );

		$this->assertFalse( $method->invoke( $obj, 999 ) );
	}

	/**
	 * A property manager keeps access to their own accommodation only.
	 */
	public function test_can_manage_accommodation_allows_only_own_accommodation_for_manager() {
		$this->boot(
			array(
				'caps' => array( 'edit_posts', 'af_manage_properties' ),
				'meta' => array( 100 => array( '_af_owner_id' => 5 ), 200 => array( '_af_owner_id' => 9 ) ),
			)
		);
		require_once dirname( __DIR__, 2 ) . '/includes/class-tenancy.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-rental-workflow.php';

		$ref    = new \ReflectionClass( 'Arriendo_Facil_Rental_Workflow' );
		$obj    = $ref->newInstanceWithoutConstructor();
		$method = $ref->getMethod( 'can_manage_accommodation' );

		$this->assertTrue( $method->invoke( $obj, 100 ) );
		$this->assertFalse( $method->invoke( $obj, 200 ) );
	}

	/**
	 * Voiding a charge after a meter reading is deleted must stay inside the reading's own leases.
	 */
	public function test_delete_meter_reading_scopes_charge_lookup_to_reading_accommodation() {
		$this->boot();
		require_once dirname( __DIR__, 2 ) . '/includes/class-billing-ledger.php';

		$GLOBALS['wpdb']->rows['readings'] = (object) array(
			'id'               => 1,
			'service'          => 'agua',
			'period'           => '2026-10',
			'unit_id'          => 5,
			'accommodation_id' => 100,
		);

		\Arriendo_Facil_Billing_Ledger::delete_meter_reading( 1 );

		$charge_query = '';
		foreach ( $GLOBALS['wpdb']->queries as $q ) {
			if ( false !== strpos( (string) $q, 'Consumo' ) ) {
				$charge_query = (string) $q;
			}
		}

		$this->assertNotSame( '', $charge_query, 'The void lookup query was not issued.' );
		// The lease scope must be a mandatory condition ahead of the unit match, not only an
		// alternative branch used when the charge has no unit.
		$this->assertMatchesRegularExpression( "/status <> 'void'\\s+AND lease_id IN \\( SELECT id FROM wp_af_leases WHERE accommodation_id = 100 \\)/", $charge_query );
	}

	/**
	 * Anonymous signup must not rewrite a guest row that belongs to someone else.
	 */
	public function test_signup_does_not_overwrite_existing_unlinked_guest_row() {
		$this->boot();
		require_once dirname( __DIR__, 2 ) . '/includes/tenants/class-tenant-signup.php';

		$GLOBALS['wpdb']->rows['FROM wp_af_guests WHERE email'] = (object) array(
			'id'      => 1,
			'user_id' => null,
		);

		$ref    = new \ReflectionClass( 'Arriendo_Facil_Tenant_Signup' );
		$obj    = $ref->newInstanceWithoutConstructor();
		$method = $ref->getMethod( 'upsert_guest_row_for_tenant' );
		$method->invoke( $obj, 999, 'Atacante', 'X', 'victim@example.test', '0987654321', '1799999999', 'enc', 'enc' );

		$this->assertSame( array(), $GLOBALS['wpdb']->updates, 'Existing guest row was modified before e-mail verification.' );
	}

	/**
	 * Forwarded-for headers are only honoured when the immediate peer is a local/trusted proxy.
	 */
	public function test_rate_limiter_ignores_spoofed_forwarded_headers_from_public_peer() {
		$this->boot();
		if ( ! function_exists( 'sanitize_text_field' ) ) {
			eval( 'function sanitize_text_field( $v ) { return trim( (string) $v ); }' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/class-rate-limiter.php';

		$method = new \ReflectionMethod( 'Arriendo_Facil_Rate_Limiter', 'get_client_ip' );

		// Direct connection from a public address that forges the headers.
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.8';
		$this->assertSame( '203.0.113.9', $method->invoke( null ) );

		// Local reverse proxy / tunnel connector: the forwarded client address is used.
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$this->assertSame( '198.51.100.8', $method->invoke( null ) );

		// Garbage in the header is ignored.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.7, 10.0.0.1';
		$this->assertSame( '198.51.100.7', $method->invoke( null ) );
	}

	/**
	 * Private local storage: readable per-person names, no overwrite, no traversal.
	 */
	public function test_local_private_storage_uses_readable_per_person_names_and_rejects_traversal() {
		$this->boot();
		$dir = sys_get_temp_dir() . '/af-priv-test-' . uniqid();
		define( 'AF_PRIVATE_STORAGE_DIR', $dir );
		if ( ! function_exists( 'remove_accents' ) ) {
			eval( 'function remove_accents( $s ) { return strtr( $s, array( "á"=>"a","é"=>"e","í"=>"i","ó"=>"o","ú"=>"u","ñ"=>"n","Á"=>"A","É"=>"E","Í"=>"I","Ó"=>"O","Ú"=>"U","Ñ"=>"N" ) ); }' );
		}
		if ( ! function_exists( 'wp_mkdir_p' ) ) {
			eval( 'function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/storage/class-local-private-storage.php';

		$pdf = "%PDF-1.4\nbody";
		$key = \Arriendo_Facil_Local_Private_Storage::store( $pdf, 'huespedes', 42, 'María García Pérez', 'cedula_papeleta' );
		$this->assertIsString( $key );
		$this->assertSame( 'huespedes/0042-maria-garcia-perez/cedula-papeleta_' . gmdate( 'Y-m-d' ) . '.pdf', $key );
		$this->assertSame( $pdf, \Arriendo_Facil_Local_Private_Storage::read( $key ) );
		$this->assertFileExists( $dir . '/index.php' );

		// A second upload the same day never overwrites the first.
		$key2 = \Arriendo_Facil_Local_Private_Storage::store( 'second', 'huespedes', 42, 'María García Pérez', 'cedula_papeleta' );
		$this->assertStringEndsWith( '-v2.pdf', $key2 );
		$this->assertSame( $pdf, \Arriendo_Facil_Local_Private_Storage::read( $key ) );

		// Traversal, absolute paths, wrong extension and unknown categories are rejected.
		foreach ( array( '../../etc/passwd', '/etc/passwd', 'huespedes/../../x.pdf', 'huespedes/0042-x/notes.txt', "huespedes/0042-x/a.pdf\0.txt", '' ) as $bad ) {
			$this->assertTrue( is_wp_error( \Arriendo_Facil_Local_Private_Storage::resolve( $bad ) ), 'accepted: ' . $bad );
		}
		$this->assertTrue( is_wp_error( \Arriendo_Facil_Local_Private_Storage::store( 'x', '../escape', 1, 'a', 'b' ) ) );

		// Deleting removes the file; the folder goes away when empty.
		$this->assertTrue( \Arriendo_Facil_Local_Private_Storage::delete( $key2 ) );
		$this->assertTrue( \Arriendo_Facil_Local_Private_Storage::delete( $key ) );
		$this->assertDirectoryDoesNotExist( $dir . '/huespedes/0042-maria-garcia-perez' );
	}

	/**
	 * Master-key encryption: round trip, purpose binding, tamper detection.
	 */
	public function test_crypto_seals_binds_purpose_and_detects_tampering() {
		define( 'AF_ENCRYPTION_KEY', str_repeat( 'a1b2c3d4', 8 ) );
		require_once dirname( __DIR__, 2 ) . '/includes/class-crypto.php';

		$this->assertTrue( \Arriendo_Facil_Crypto::has_master() );
		$sealed = \Arriendo_Facil_Crypto::seal( 'cedula 1712345678', 'tenant-pii' );
		$this->assertStringStartsWith( 'ENC3:', $sealed );
		$this->assertNotSame( $sealed, \Arriendo_Facil_Crypto::seal( 'cedula 1712345678', 'tenant-pii' ), 'IV must be random.' );
		$this->assertSame( 'cedula 1712345678', \Arriendo_Facil_Crypto::open( $sealed, 'tenant-pii' ) );

		// A value sealed for one purpose does not open under another.
		$this->assertFalse( \Arriendo_Facil_Crypto::open( $sealed, 'admin-pii' ) );

		// Flipping any byte of the payload makes it unreadable.
		$raw      = base64_decode( substr( $sealed, 5 ) );
		$raw[40 % strlen( $raw )] = chr( ord( $raw[40 % strlen( $raw )] ) ^ 1 );
		$this->assertFalse( \Arriendo_Facil_Crypto::open( 'ENC3:' . base64_encode( $raw ), 'tenant-pii' ) );

		// Derived HMAC keys are per purpose and independent from the legacy secret.
		$a = \Arriendo_Facil_Crypto::hmac_secret( 'invoice-link', 'legacy-salt' );
		$b = \Arriendo_Facil_Crypto::hmac_secret( 'other', 'legacy-salt' );
		$this->assertSame( 32, strlen( $a ) );
		$this->assertNotSame( $a, $b );
		$this->assertNotSame( 'legacy-salt', $a );
	}

	/**
	 * Weak or malformed master keys are rejected; without a key nothing is sealed.
	 */
	public function test_crypto_rejects_weak_master_key() {
		define( 'AF_ENCRYPTION_KEY', str_repeat( 'ab', 32 ) . 'zz' ); // Not hex, wrong length.
		require_once dirname( __DIR__, 2 ) . '/includes/class-crypto.php';

		$this->assertFalse( \Arriendo_Facil_Crypto::has_master() );
		$this->assertNotSame( '', \Arriendo_Facil_Crypto::master_problem() );
		$this->assertSame( '', \Arriendo_Facil_Crypto::seal( 'x', 'sri' ) );
		$this->assertSame( 'legacy', \Arriendo_Facil_Crypto::hmac_secret( 'p', 'legacy' ) );
	}

	/**
	 * Legacy salt-derived 'v1:' payloads convert to the master key, verified by round trip,
	 * and identity fingerprints become independent from the salts.
	 */
	public function test_crypto_rekeys_legacy_payload_and_fingerprint() {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium not available' );
		}
		define( 'AF_ENCRYPTION_KEY', str_repeat( '0f1e2d3c', 8 ) );
		$this->boot();
		if ( ! function_exists( 'wp_salt' ) ) {
			eval( 'function wp_salt( $scheme = "auth" ) { return "salt-" . $scheme . "-" . ( isset( $GLOBALS["af_t_salt"] ) ? $GLOBALS["af_t_salt"] : "one" ); }' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/class-crypto.php';

		// Build a legacy payload exactly like the old encrypt_sensitive_tenant_value().
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$legacy = 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( '0991234567', $nonce, \Arriendo_Facil_Crypto::legacy_v1_key( 'af_tenant_sensitive_v1' ) ) );

		$this->assertSame( '0991234567', \Arriendo_Facil_Crypto::open_legacy_v1( $legacy, 'af_tenant_sensitive_v1' ) );
		$sealed = \Arriendo_Facil_Crypto::rekey_legacy_v1( $legacy, 'af_tenant_sensitive_v1', 'tenant-pii' );
		$this->assertStringStartsWith( 'ENC3:', $sealed );
		$this->assertSame( '0991234567', \Arriendo_Facil_Crypto::open( $sealed, 'tenant-pii' ) );

		// A payload that does not open (wrong slug) is never converted.
		$this->assertSame( '', \Arriendo_Facil_Crypto::rekey_legacy_v1( $legacy, 'af_admin_sensitive_v1', 'admin-pii' ) );

		// With the master key the fingerprint does not depend on the (possibly leaked) salts.
		$one = \Arriendo_Facil_Crypto::fingerprint( '1712345678', 'tenant-id-fp', 'salt-one' );
		$two = \Arriendo_Facil_Crypto::fingerprint( '1712345678', 'tenant-id-fp', 'salt-two' );
		$this->assertSame( $one, $two );
	}

	/**
	 * SRI secrets: an existing ENC2 value stays readable, converts to the master key, and new
	 * values are sealed with it.
	 */
	public function test_sri_secrets_migrate_from_salt_key_to_master_key() {
		$this->boot();
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'test-auth-key-0123456789abcdef0123456789abcdef' );
		}
		if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
			define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-0123456789abcdef0123456789' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/class-crypto.php';
		require_once dirname( __DIR__, 2 ) . '/includes/billing/class-sri-config.php';

		// Before the master key exists: legacy ENC2 payload (existing production data).
		$legacy = \Arriendo_Facil_SRI_Config::protect_sensitive( 'cert-password' );
		$this->assertStringStartsWith( 'ENC2:', $legacy );

		define( 'AF_ENCRYPTION_KEY', str_repeat( '9a8b7c6d', 8 ) );

		// Still readable after the key is configured.
		$this->assertSame( 'cert-password', \Arriendo_Facil_SRI_Config::unprotect_sensitive( $legacy ) );

		// Conversion is verified and idempotent.
		$sealed = \Arriendo_Facil_SRI_Config::rekey_stored( $legacy );
		$this->assertStringStartsWith( 'ENC3:', $sealed );
		$this->assertSame( 'cert-password', \Arriendo_Facil_SRI_Config::unprotect_sensitive( $sealed ) );
		$this->assertSame( '', \Arriendo_Facil_SRI_Config::rekey_stored( $sealed ) );
		$this->assertSame( 'cert-password', \Arriendo_Facil_SRI_Config::decrypt_password( $sealed ) );

		// New values use the master key.
		$this->assertStringStartsWith( 'ENC3:', \Arriendo_Facil_SRI_Config::protect_sensitive( 'another' ) );
	}

	/**
	 * Contracts: stored privately with readable per-lease names, Word types accepted, executables rejected.
	 */
	public function test_contract_files_are_stored_privately_with_readable_names() {
		$this->boot();
		$dir = sys_get_temp_dir() . '/af-priv-contracts-' . uniqid();
		define( 'AF_PRIVATE_STORAGE_DIR', $dir );
		if ( ! function_exists( 'remove_accents' ) ) {
			eval( 'function remove_accents( $s ) { return $s; }' );
		}
		if ( ! function_exists( 'wp_mkdir_p' ) ) {
			eval( 'function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/storage/class-local-private-storage.php';

		$key = \Arriendo_Facil_Local_Private_Storage::store( 'PK-docx-bytes', 'contratos', 17, 'Ana Torres', 'contrato-v1', 'docx' );
		$this->assertSame( 'contratos/0017-ana-torres/contrato-v1_' . gmdate( 'Y-m-d' ) . '.docx', $key );
		$this->assertSame( 'PK-docx-bytes', \Arriendo_Facil_Local_Private_Storage::read( $key ) );

		$pdf_key = \Arriendo_Facil_Local_Private_Storage::store( '%PDF-1', 'contratos', 17, 'Ana Torres', 'contrato-aprobado-v1', 'pdf' );
		$this->assertStringEndsWith( '.pdf', $pdf_key );

		// Nothing executable can be stored or resolved.
		$this->assertTrue( is_wp_error( \Arriendo_Facil_Local_Private_Storage::store( '<?php', 'contratos', 17, 'x', 'shell', 'php' ) ) );
		$this->assertTrue( is_wp_error( \Arriendo_Facil_Local_Private_Storage::resolve( 'contratos/0017-ana-torres/x.php' ) ) );
		$this->assertTrue( is_wp_error( \Arriendo_Facil_Local_Private_Storage::resolve( 'contratos/../../etc/passwd.pdf' ) ) );
	}

	/**
	 * An uploaded owner template leaves the public folder: it is copied, verified, the attachment
	 * points at the private copy, and the public original is deleted. Unsupported files stay put.
	 */
	public function test_owner_template_is_relocated_to_private_store() {
		$this->boot();
		$base = sys_get_temp_dir() . '/af-tpl-' . uniqid();
		$priv = $base . '/private';
		$pub  = $base . '/public';
		mkdir( $pub, 0755, true );
		define( 'AF_PRIVATE_STORAGE_DIR', $priv );
		foreach ( array(
			'remove_accents'     => 'function remove_accents( $s ) { return $s; }',
			'wp_mkdir_p'         => 'function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }',
			'get_userdata'       => 'function get_userdata( $id ) { return (object) array( "display_name" => "Carlos Perez" ); }',
			'get_attached_file'  => 'function get_attached_file( $id, $u = false ) { return isset( $GLOBALS["af_t_meta"][ (int) $id ]["_wp_attached_file"] ) ? $GLOBALS["af_t_meta"][ (int) $id ]["_wp_attached_file"] : false; }',
			'update_post_meta'   => 'function update_post_meta( $id, $k, $v ) { $GLOBALS["af_t_meta"][ (int) $id ][ $k ] = $v; return true; }',
			'wp_delete_file'     => 'function wp_delete_file( $f ) { if ( is_file( $f ) ) { unlink( $f ); } }',
		) as $name => $code ) {
			if ( ! function_exists( $name ) ) {
				eval( $code );
			}
		}
		require_once dirname( __DIR__, 2 ) . '/includes/storage/class-local-private-storage.php';

		$original = $pub . '/Contrato-modelo.docx';
		file_put_contents( $original, 'PK-template-bytes' );
		$GLOBALS['af_t_meta'][55]['_wp_attached_file'] = $original;

		$this->assertTrue( \Arriendo_Facil_Local_Private_Storage::relocate_owner_template( 55, 9 ) );

		$new = $GLOBALS['af_t_meta'][55]['_wp_attached_file'];
		$this->assertTrue( \Arriendo_Facil_Local_Private_Storage::is_private_path( $new ) );
		$this->assertStringContainsString( '/plantillas/0009-carlos-perez/', $new );
		$this->assertSame( 'PK-template-bytes', file_get_contents( $new ) );
		$this->assertFileDoesNotExist( $original, 'The public original must be deleted.' );
		$this->assertSame( $original, $GLOBALS['af_t_meta'][55]['_af_original_attached_file'] );

		// Already private: nothing to do. Unsupported type: untouched.
		$this->assertTrue( \Arriendo_Facil_Local_Private_Storage::relocate_owner_template( 55, 9 ) );
		$exe = $pub . '/shell.php';
		file_put_contents( $exe, '<?php' );
		$GLOBALS['af_t_meta'][56]['_wp_attached_file'] = $exe;
		$this->assertFalse( \Arriendo_Facil_Local_Private_Storage::relocate_owner_template( 56, 9 ) );
		$this->assertFileExists( $exe );
	}

	/**
	 * Property documents (PDF/images) go to a readable per-property folder; streaming refuses
	 * anything outside the private store.
	 */
	public function test_property_documents_use_property_folder_and_stream_is_confined() {
		$this->boot();
		$base = sys_get_temp_dir() . '/af-prop-' . uniqid();
		$pub  = $base . '/public';
		mkdir( $pub, 0755, true );
		define( 'AF_PRIVATE_STORAGE_DIR', $base . '/private' );
		foreach ( array(
			'remove_accents'    => 'function remove_accents( $s ) { return $s; }',
			'wp_mkdir_p'        => 'function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }',
			'get_attached_file' => 'function get_attached_file( $id, $u = false ) { return isset( $GLOBALS["af_t_meta"][ (int) $id ]["_wp_attached_file"] ) ? $GLOBALS["af_t_meta"][ (int) $id ]["_wp_attached_file"] : false; }',
			'update_post_meta'  => 'function update_post_meta( $id, $k, $v ) { $GLOBALS["af_t_meta"][ (int) $id ][ $k ] = $v; return true; }',
			'wp_delete_file'    => 'function wp_delete_file( $f ) { if ( is_file( $f ) ) { unlink( $f ); } }',
			'sanitize_file_name' => 'function sanitize_file_name( $n ) { return preg_replace( "/[^A-Za-z0-9._-]/", "", $n ); }',
			'nocache_headers'   => 'function nocache_headers() {}',
		) as $name => $code ) {
			if ( ! function_exists( $name ) ) {
				eval( $code );
			}
		}
		require_once dirname( __DIR__, 2 ) . '/includes/storage/class-local-private-storage.php';

		$scan = $pub . '/Escritura Casa Centro.JPG';
		file_put_contents( $scan, 'JPEG-bytes' );
		$GLOBALS['af_t_meta'][70]['_wp_attached_file'] = $scan;

		$this->assertTrue( \Arriendo_Facil_Local_Private_Storage::relocate_attachment( 70, 'inmuebles', 100, 'Casa Centro', 'escritura' ) );
		$new = $GLOBALS['af_t_meta'][70]['_wp_attached_file'];
		$this->assertStringContainsString( '/inmuebles/0100-casa-centro/escritura-escritura-casa-centro_', $new );
		$this->assertStringEndsWith( '.jpg', $new );
		$this->assertFileDoesNotExist( $scan );

		// Streaming only serves files inside the private store.
		$outside = $pub . '/other.pdf';
		file_put_contents( $outside, '%PDF' );
		$this->assertTrue( is_wp_error( \Arriendo_Facil_Local_Private_Storage::stream_path( $outside ) ) );
		$this->assertTrue( is_wp_error( \Arriendo_Facil_Local_Private_Storage::stream_path( $base . '/private/../public/other.pdf' ) ) );

		// A type outside the allow-list is neither moved nor streamed.
		$odd = $pub . '/macro.xlsm';
		file_put_contents( $odd, 'x' );
		$GLOBALS['af_t_meta'][71]['_wp_attached_file'] = $odd;
		$this->assertFalse( \Arriendo_Facil_Local_Private_Storage::relocate_attachment( 71, 'inmuebles', 100, 'Casa Centro', 'otro' ) );
		$this->assertFileExists( $odd );
	}

	/**
	 * A guest row is global with a single accommodation_id. A manager must still reach a guest who
	 * holds a lease on one of their properties (it shows in Contratos), but not unrelated guests.
	 */
	public function test_guest_with_lease_on_own_property_is_accessible_even_if_row_points_elsewhere() {
		$this->boot( array( 'caps' => array( 'af_manage_properties' ) ) );
		if ( ! class_exists( 'Arriendo_Facil_Accommodation' ) ) {
			eval( 'class Arriendo_Facil_Accommodation { public static function get_owner_accommodation_ids( $u ) { return array( 100 ); } }' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/class-tenancy.php';

		// Guest 7 points at somebody else's accommodation (999) and was created by another user.
		$GLOBALS['wpdb']->rows['af_guests WHERE id'] = (object) array( 'id' => 7, 'accommodation_id' => 999, 'created_by' => 3 );

		// No lease with this manager: no access.
		$this->assertFalse( \Arriendo_Facil_Tenancy::can_access_guest( 7 ) );

		// A lease on accommodation 100 (the manager's own) makes the guest accessible.
		$GLOBALS['wpdb']->rows['af_leases WHERE guest_id'] = (object) array( 'id' => 1 );
		$this->assertTrue( \Arriendo_Facil_Tenancy::can_access_guest( 7 ) );

		$lease_query = '';
		foreach ( $GLOBALS['wpdb']->queries as $q ) {
			if ( false !== strpos( (string) $q, 'af_leases WHERE guest_id' ) ) {
				$lease_query = (string) $q;
			}
		}
		$this->assertStringContainsString( 'accommodation_id IN (100)', $lease_query );
		$this->assertStringContainsString( 'deleted_at IS NULL', $lease_query );
	}
}
