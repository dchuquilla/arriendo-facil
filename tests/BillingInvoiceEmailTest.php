<?php
/**
 * Simulates the post-authorization email: recipient, attachments and "once only".
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		$GLOBALS['af_test_mail'][] = compact( 'to', 'subject', 'message', 'attachments' ) + array(
			'attachment_names' => array_map( 'basename', (array) $attachments ),
		);
		return ! empty( $GLOBALS['af_test_mail_ok'] );
	}
}
if ( ! function_exists( 'get_temp_dir' ) ) {
	function get_temp_dir() {
		return sys_get_temp_dir();
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		@unlink( $file ); // phpcs:ignore
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

class AF_Test_Mail_Wpdb {

	public $prefix  = 'wp_';
	public $posts   = 'wp_posts';
	public $invoice;
	public $guest_email = 'ficha@example.com';
	public $updates = array();

	public function prepare( $sql, ...$args ) {
		return $sql;
	}

	public function get_row( $sql ) {
		return $this->invoice;
	}

	public function get_var( $sql ) {
		return $this->guest_email;
	}

	public function update( $table, $data, $where ) {
		$this->updates[] = $data;
		foreach ( $data as $k => $v ) {
			$this->invoice->$k = $v;
		}
		return 1;
	}
}

class BillingInvoiceEmailTest extends TestCase {

	private $ride;

	protected function setUp(): void {
		$GLOBALS['af_test_mail']    = array();
		$GLOBALS['af_test_mail_ok'] = true;
		$this->ride                 = tempnam( sys_get_temp_dir(), 'ride' );
		file_put_contents( $this->ride, '%PDF-1.4 test' );
	}

	protected function tearDown(): void {
		@unlink( $this->ride ); // phpcs:ignore
	}

	private function manager( string $estado = 'autorizada', string $xml = '<x/>' ): array {
		$db          = new AF_Test_Mail_Wpdb();
		$db->invoice = (object) array(
			'id'                 => 5,
			'lease_id'           => 10,
			'estado'             => $estado,
			'numero_comprobante' => '001-001-000000123',
			'total'              => 345.00,
			'ride_path'          => $this->ride,
			'xml_autorizacion'   => $xml,
			'emailed_at'         => null,
		);
		return array( new Arriendo_Facil_Billing_Manager( array( 'wpdb' => $db ) ), $db );
	}

	public function test_authorized_invoice_is_emailed_with_ride_and_xml(): void {
		list( $manager, $db ) = $this->manager();

		$this->assertTrue( $manager->send_invoice_email( 5 ) );
		$mail = $GLOBALS['af_test_mail'][0];
		$this->assertSame( 'ficha@example.com', $mail['to'] );
		$this->assertStringContainsString( '001-001-000000123', $mail['subject'] );
		$this->assertCount( 2, $mail['attachments'] );
		$this->assertContains( 'factura-001-001-000000123.xml', $mail['attachment_names'] );
		$this->assertArrayHasKey( 'emailed_at', $db->updates[0] );
	}

	public function test_email_corrected_in_review_modal_wins_over_guest_record(): void {
		list( $manager ) = $this->manager();

		$this->assertTrue( $manager->send_invoice_email( 5, 'corregido@example.com' ) );
		$this->assertSame( 'corregido@example.com', $GLOBALS['af_test_mail'][0]['to'] );
	}

	public function test_email_is_read_from_invoice_xml_on_retry_path(): void {
		list( $manager ) = $this->manager( 'autorizada', '<infoAdicional><campoAdicional nombre="email">xml@example.com</campoAdicional></infoAdicional>' );

		$this->assertTrue( $manager->send_invoice_email( 5 ) );
		$this->assertSame( 'xml@example.com', $GLOBALS['af_test_mail'][0]['to'] );
	}

	public function test_invoice_without_pdf_is_not_emailed(): void {
		list( $manager, $db ) = $this->manager();
		$db->invoice->ride_path = '/nonexistent/ride.pdf';

		$this->assertFalse( $manager->send_invoice_email( 5 ) );
		$this->assertSame( array(), $GLOBALS['af_test_mail'] );
	}

	public function test_is_sent_only_once(): void {
		list( $manager ) = $this->manager();

		$this->assertTrue( $manager->send_invoice_email( 5 ) );
		$this->assertFalse( $manager->send_invoice_email( 5 ) );
		$this->assertCount( 1, $GLOBALS['af_test_mail'] );
	}

	public function test_unauthorized_invoice_is_not_emailed(): void {
		list( $manager ) = $this->manager( 'no_autorizada' );

		$this->assertFalse( $manager->send_invoice_email( 5 ) );
		$this->assertSame( array(), $GLOBALS['af_test_mail'] );
	}

	public function test_failed_mail_does_not_mark_invoice_as_emailed(): void {
		$GLOBALS['af_test_mail_ok'] = false;
		list( $manager, $db )       = $this->manager();

		$this->assertFalse( $manager->send_invoice_email( 5 ) );
		$this->assertSame( array(), $db->updates );
	}
}
