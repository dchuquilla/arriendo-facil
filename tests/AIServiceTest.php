<?php
/**
 * Unit tests for Arriendo_Facil_AI_Service.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

/**
 * Class AIServiceTest
 *
 * Tests AI service behaviour without a live API endpoint.
 */
class AIServiceTest extends TestCase {

	/**
	 * Returns a fresh AI service instance.
	 *
	 * @return Arriendo_Facil_AI_Service
	 */
	private function make_service() {
		return new Arriendo_Facil_AI_Service();
	}

	/**
	 * predict_cost() should return a WP_Error when no API URL is configured.
	 */
	public function test_predict_cost_returns_error_when_no_api_url() {
		$service = $this->make_service();
		$result  = $service->predict_cost( array( 'bedrooms' => 2 ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'no_api_url', $result->get_error_code() );
	}

	/**
	 * generate_document() should return a WP_Error when no API URL is configured.
	 */
	public function test_generate_document_returns_error_when_no_api_url() {
		$service = $this->make_service();
		$result  = $service->generate_document( array( 'lease_id' => 1 ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'no_api_url', $result->get_error_code() );
	}

	/**
	 * score_guest() should return a WP_Error when no API URL is configured.
	 */
	public function test_score_guest_returns_error_when_no_api_url() {
		$service = $this->make_service();
		$result  = $service->score_guest( array( 'first_name' => 'Alice', 'email' => 'alice@example.com' ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'no_api_url', $result->get_error_code() );
	}

	/**
	 * predict_cost() should return a WP_Error when the HTTP request itself fails.
	 */
	public function test_predict_cost_propagates_http_error() {
		// Override get_option to return a URL so the service attempts a request.
		// wp_remote_post is stubbed in bootstrap to always return WP_Error.
		$service = new class extends Arriendo_Facil_AI_Service {
			public function __construct() {
				// Bypass the real constructor's get_option call.
				// Use reflection to set private properties.
				$ref = new ReflectionClass( Arriendo_Facil_AI_Service::class );

				$urlProp = $ref->getProperty( 'api_url' );
				$urlProp->setValue( $this, 'https://api.example.com' );

				$keyProp = $ref->getProperty( 'api_key' );
				$keyProp->setValue( $this, 'test-key' );
			}
		};

		$result = $service->predict_cost( array( 'bedrooms' => 3 ) );

		// wp_remote_post stub always returns WP_Error.
		$this->assertTrue( is_wp_error( $result ) );
	}
}
