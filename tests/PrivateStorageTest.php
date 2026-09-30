<?php
/**
 * Tests for the shared private storage (R2 SigV4) and contract text extraction
 * that replaced five duplicated copies across Lease/Guest/Admin/Owner_Contact.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['af_test_http_calls'][] = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => $GLOBALS['af_test_http_status'] ?? 200 ) );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/storage/class-private-storage.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/contracts/class-contract-text-extractor.php';

class PrivateStorageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['af_test_http_calls']  = array();
		$GLOBALS['af_test_http_status'] = 200;
		$GLOBALS['af_test_options']     = array(
			'af_r2_access_key_id'     => 'AKIDEXAMPLE',
			'af_r2_secret_access_key' => 'secret-example',
			'af_r2_endpoint_url'      => 'https://acct.r2.cloudflarestorage.com/',
			'af_r2_bucket_name'       => 'af-bucket',
		);
	}

	protected function tearDown(): void {
		$GLOBALS['af_test_options'] = array();
	}

	public function test_not_configured_returns_error() {
		$GLOBALS['af_test_options'] = array();

		$this->assertFalse( Arriendo_Facil_Private_Storage::is_configured() );
		$this->assertFalse( Arriendo_Facil_Private_Storage::is_r2_enabled() );
		$this->assertTrue( is_wp_error( Arriendo_Facil_Private_Storage::upload( 'x', 'k', 'text/plain' ) ) );
		$this->assertSame( '', Arriendo_Facil_Private_Storage::bucket() );
	}

	public function test_r2_enabled_respects_provider_setting() {
		$this->assertTrue( Arriendo_Facil_Private_Storage::is_r2_enabled() );

		$GLOBALS['af_test_options']['af_storage_provider'] = 'local';
		$this->assertFalse( Arriendo_Facil_Private_Storage::is_r2_enabled() );
	}

	public function test_upload_signature_matches_legacy_implementation() {
		$contents   = 'contract-bytes';
		$object_key = 'lease-contracts/7/lease 7.docx';

		$this->assertTrue( Arriendo_Facil_Private_Storage::upload( $contents, $object_key, 'application/pdf' ) );
		$this->assertCount( 1, $GLOBALS['af_test_http_calls'] );

		$call    = $GLOBALS['af_test_http_calls'][0];
		$headers = $call['args']['headers'];

		$this->assertSame( 'PUT', $call['args']['method'] );
		$this->assertSame( $contents, $call['args']['body'] );
		$this->assertSame( 'https://acct.r2.cloudflarestorage.com/af-bucket/lease-contracts/7/lease%207.docx', $call['url'] );
		$this->assertSame( 'application/pdf', $headers['Content-Type'] );
		$this->assertSame(
			$this->legacy_put_authorization( $contents, $object_key, $headers['x-amz-date'] ),
			$headers['Authorization']
		);
	}

	public function test_upload_reports_http_status_on_rejection() {
		$GLOBALS['af_test_http_status'] = 403;

		$result = Arriendo_Facil_Private_Storage::upload( 'x', 'k', 'text/plain' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertStringContainsString( 'HTTP 403', $result->get_error_message() );
	}

	public function test_presigned_url_contains_expected_query() {
		$url = Arriendo_Facil_Private_Storage::presigned_get_url( '/guest-documents/3/cedula.pdf', 99999 );

		$this->assertStringStartsWith( 'https://acct.r2.cloudflarestorage.com/af-bucket/guest-documents/3/cedula.pdf?', $url );
		$this->assertStringContainsString( 'X-Amz-Expires=3600', $url );
		$this->assertMatchesRegularExpression( '/X-Amz-Signature=[0-9a-f]{64}$/', $url );
	}

	public function test_extractor_limit_collapses_whitespace_and_truncates() {
		$this->assertSame( 'a b c', Arriendo_Facil_Contract_Text_Extractor::limit( "  a \n\n b\tc " ) );
		$this->assertSame(
			Arriendo_Facil_Contract_Text_Extractor::MAX_TEMPLATE_CHARS,
			strlen( Arriendo_Facil_Contract_Text_Extractor::limit( str_repeat( 'x', 9000 ) ) )
		);
	}

	public function test_extractor_reads_pdf_text_operators() {
		$pdf  = tempnam( sys_get_temp_dir(), 'af_pdf' ) . '.pdf';
		$body = "BT (Contrato de\\040arriendo) Tj [(Canon) -250 (mensual)] TJ ET";
		file_put_contents( $pdf, "%PDF-1.4\nstream\n" . gzcompress( $body ) . "\nendstream\n" );

		$text = Arriendo_Facil_Contract_Text_Extractor::extract( $pdf, 'application/pdf' );
		unlink( $pdf );

		$this->assertStringContainsString( 'Contrato de arriendo', $text );
		$this->assertStringContainsString( 'Canon mensual', $text );
	}

	public function test_extractor_missing_file_returns_empty() {
		$this->assertSame( '', Arriendo_Facil_Contract_Text_Extractor::extract( '/nonexistent/file.docx', '' ) );
	}

	/**
	 * Verbatim reimplementation of the removed upload_contents_to_r2() signing.
	 */
	private function legacy_put_authorization( $contents, $object_key, $amz_date ) {
		$date_stamp    = substr( $amz_date, 0, 8 );
		$payload_hash  = hash( 'sha256', $contents );
		$canonical_uri = '/' . rawurlencode( 'af-bucket' ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );
		$host          = 'acct.r2.cloudflarestorage.com';

		$canonical_headers = 'host:' . $host . "\n" . 'x-amz-content-sha256:' . $payload_hash . "\n" . 'x-amz-date:' . $amz_date . "\n";
		$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';
		$canonical_request = "PUT\n" . $canonical_uri . "\n" . "\n" . $canonical_headers . "\n" . $signed_headers . "\n" . $payload_hash;
		$scope             = $date_stamp . '/auto/s3/aws4_request';
		$string_to_sign    = 'AWS4-HMAC-SHA256' . "\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );

		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4secret-example', true );
		$k_region  = hash_hmac( 'sha256', 'auto', $k_date, true );
		$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		return 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
	}
}
