<?php
/**
 * Reglas puras de las entregas de dinero al propietario/gestor y de su recibo.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/tenants/class-portal-collections.php';

class PortalCollectionsTest extends TestCase {

	private function row( $status ) {
		return (object) array( 'status' => $status );
	}

	public function test_receipt_number_is_padded_and_dated() {
		$this->assertSame( 'RC-202611-00042', Arriendo_Facil_Portal_Collections::receipt_number( 42, '2026-11-25 10:00:00' ) );
	}

	public function test_bank_methods_need_proof_but_cash_does_not() {
		$this->assertFalse( Arriendo_Facil_Portal_Collections::needs_proof( 'efectivo' ) );
		$this->assertTrue( Arriendo_Facil_Portal_Collections::needs_proof( 'transferencia' ) );
		$this->assertTrue( Arriendo_Facil_Portal_Collections::needs_proof( 'deposito' ) );
	}

	public function test_card_is_pending_until_something_is_delivered() {
		$this->assertSame( 'pending', Arriendo_Facil_Portal_Collections::card_status( '', array() ) );
		$this->assertSame( 'pending', Arriendo_Facil_Portal_Collections::card_status( '', array( $this->row( 'rejected' ) ) ) );
	}

	public function test_waiting_confirmation_wins_over_an_earlier_receipt() {
		$this->assertSame( 'submitted', Arriendo_Facil_Portal_Collections::card_status( '', array( $this->row( 'submitted' ), $this->row( 'received' ) ) ) );
	}

	public function test_received_money_is_not_the_same_as_paid_to_the_company() {
		$this->assertSame( 'received', Arriendo_Facil_Portal_Collections::card_status( '', array( $this->row( 'received' ) ) ) );
		$this->assertSame( 'approved', Arriendo_Facil_Portal_Collections::card_status( 'approved', array( $this->row( 'received' ) ) ) );
	}
}
