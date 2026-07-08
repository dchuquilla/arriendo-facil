<?php
/**
 * Tests for OTA API Clients
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OTA_ClientsTest
 *
 * Tests OTA API client functionality.
 */
class OTA_ClientsTest extends WP_UnitTestCase {

	/**
	 * Test Booking client instantiation.
	 *
	 * @covers Arriendo_Facil_Booking_API_Client::__construct
	 */
	public function test_booking_client_instantiation() {
		$client = new Arriendo_Facil_Booking_API_Client( 'test_key_123', 'partner_456' );
		$this->assertInstanceOf( 'Arriendo_Facil_Booking_API_Client', $client );
		$this->assertInstanceOf( 'Arriendo_Facil_OTA_API_Client_Base', $client );
	}

	/**
	 * Test Airbnb client instantiation.
	 *
	 * @covers Arriendo_Facil_Airbnb_API_Client::__construct
	 */
	public function test_airbnb_client_instantiation() {
		$client = new Arriendo_Facil_Airbnb_API_Client( 'token_xyz', 'account_789' );
		$this->assertInstanceOf( 'Arriendo_Facil_Airbnb_API_Client', $client );
		$this->assertInstanceOf( 'Arriendo_Facil_OTA_API_Client_Base', $client );
	}

	/**
	 * Test Credentials encryption and decryption.
	 *
	 * @covers Arriendo_Facil_OTA_Credentials::save_encrypted
	 * @covers Arriendo_Facil_OTA_Credentials::get_decrypted
	 */
	public function test_credentials_encryption() {
		$owner_id = 1;
		$platform = 'booking';
		$api_key = 'secret_key_12345';
		$account_id = 'account_xyz';

		// Save encrypted credentials
		$saved = Arriendo_Facil_OTA_Credentials::save_encrypted( $owner_id, $platform, $api_key, $account_id );
		$this->assertTrue( $saved );

		// Retrieve and verify
		$credentials = Arriendo_Facil_OTA_Credentials::get_decrypted( $owner_id, $platform );
		$this->assertIsArray( $credentials );
		$this->assertEquals( $api_key, $credentials['api_key'] );
		$this->assertEquals( $account_id, $credentials['account_id'] );
	}

	/**
	 * Test credentials configuration status check.
	 *
	 * @covers Arriendo_Facil_OTA_Credentials::is_configured
	 */
	public function test_credentials_configuration_status() {
		$owner_id = 2;
		$platform = 'airbnb';

		// Should not be configured initially
		$this->assertFalse( Arriendo_Facil_OTA_Credentials::is_configured( $owner_id, $platform ) );

		// Save and mark as verified
		Arriendo_Facil_OTA_Credentials::save_encrypted( $owner_id, $platform, 'test_key', 'test_account' );
		Arriendo_Facil_OTA_Credentials::mark_verified( $owner_id, $platform );

		// Should now be configured
		$this->assertTrue( Arriendo_Facil_OTA_Credentials::is_configured( $owner_id, $platform ) );
	}

	/**
	 * Test credentials disconnection.
	 *
	 * @covers Arriendo_Facil_OTA_Credentials::mark_disconnected
	 */
	public function test_credentials_disconnection() {
		$owner_id = 3;
		$platform = 'booking';

		// Setup
		Arriendo_Facil_OTA_Credentials::save_encrypted( $owner_id, $platform, 'key', 'account' );
		Arriendo_Facil_OTA_Credentials::mark_verified( $owner_id, $platform );
		$this->assertTrue( Arriendo_Facil_OTA_Credentials::is_configured( $owner_id, $platform ) );

		// Disconnect
		Arriendo_Facil_OTA_Credentials::mark_disconnected( $owner_id, $platform );
		$this->assertFalse( Arriendo_Facil_OTA_Credentials::is_configured( $owner_id, $platform ) );
	}

	/**
	 * Test webhook URL generation.
	 *
	 * @covers Arriendo_Facil_OTA_Webhook_Handler::get_webhook_url
	 */
	public function test_webhook_url_generation() {
		$booking_url = Arriendo_Facil_OTA_Webhook_Handler::get_webhook_url( 'booking' );
		$this->assertStringContainsString( 'af/v1/ota/webhook/booking', $booking_url );

		$airbnb_url = Arriendo_Facil_OTA_Webhook_Handler::get_webhook_url( 'airbnb' );
		$this->assertStringContainsString( 'af/v1/ota/webhook/airbnb', $airbnb_url );
	}

	/**
	 * Test webhook secret storage.
	 *
	 * @covers Arriendo_Facil_OTA_Webhook_Handler::save_webhook_secret
	 */
	public function test_webhook_secret_storage() {
		$platform = 'booking';
		$secret = 'webhook_secret_key_123';

		$saved = Arriendo_Facil_OTA_Webhook_Handler::save_webhook_secret( $platform, $secret );
		$this->assertTrue( $saved );

		// Verify it was stored
		$stored_secret = get_option( "af_ota_{$platform}_webhook_secret" );
		$this->assertEquals( $secret, $stored_secret );
	}

	/**
	 * Test sync manager instantiation.
	 *
	 * @covers Arriendo_Facil_OTA_Sync_Manager::__construct
	 */
	public function test_sync_manager_instantiation() {
		$manager = new Arriendo_Facil_OTA_Sync_Manager();
		$this->assertInstanceOf( 'Arriendo_Facil_OTA_Sync_Manager', $manager );
	}

	/**
	 * Test sync manager with invalid accommodation.
	 *
	 * @covers Arriendo_Facil_OTA_Sync_Manager::sync_accommodation
	 */
	public function test_sync_manager_invalid_accommodation() {
		$manager = new Arriendo_Facil_OTA_Sync_Manager();
		$result = $manager->sync_accommodation( 99999 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'invalid_accommodation', $result->get_error_code() );
	}

	/**
	 * Test sync manager with valid accommodation but no configured sources.
	 *
	 * @covers Arriendo_Facil_OTA_Sync_Manager::sync_accommodation
	 */
	public function test_sync_manager_no_configured_sources() {
		// Create test accommodation
		$post_id = wp_insert_post( array(
			'post_type' => 'accommodation',
			'post_title' => 'Test Accommodation',
			'post_status' => 'publish',
		) );

		// Set owner
		update_post_meta( $post_id, '_af_owner_id', get_current_user_id() );

		// Sync without configured sources
		$manager = new Arriendo_Facil_OTA_Sync_Manager();
		$result = $manager->sync_accommodation( $post_id );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'no_configured_sources', $result->get_error_code() );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test OTA metadata saving.
	 *
	 * @covers Arriendo_Facil_Accommodation::save_meta
	 */
	public function test_accommodation_ota_metadata() {
		// Create accommodation
		$post_id = wp_insert_post( array(
			'post_type' => 'accommodation',
			'post_title' => 'Test Property',
			'post_status' => 'publish',
		) );

		// Simulate form submission with OTA IDs
		$_POST['af_accommodation_nonce'] = wp_create_nonce( 'af_save_accommodation_meta' );
		$_POST['af_booking_property_id'] = '12345';
		$_POST['af_airbnb_listing_id'] = '67890';
		$_POST['af_sync_enabled'] = '1';

		// Save metadata via action
		do_action( 'save_post_accommodation', $post_id );

		// Verify metadata was saved
		$booking_id = get_post_meta( $post_id, '_af_booking_property_id', true );
		$airbnb_id = get_post_meta( $post_id, '_af_airbnb_listing_id', true );
		$sync_enabled = get_post_meta( $post_id, '_af_sync_enabled', true );

		$this->assertEquals( '12345', $booking_id );
		$this->assertEquals( '67890', $airbnb_id );
		$this->assertEquals( '1', $sync_enabled );

		// Cleanup
		unset( $_POST['af_accommodation_nonce'], $_POST['af_booking_property_id'], $_POST['af_airbnb_listing_id'], $_POST['af_sync_enabled'] );
		wp_delete_post( $post_id, true );
	}
}
