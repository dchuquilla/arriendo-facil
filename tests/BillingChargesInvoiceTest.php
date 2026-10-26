<?php
/**
 * Tests for charge-based invoicing: IVA by property use, SRI payment form,
 * consumidor-final limit and billable-charge selection rules.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

/**
 * Manager double that decides property use without touching post meta.
 */
class AF_Test_Billing_Manager extends Arriendo_Facil_Billing_Manager {

	/** @var bool */
	public $commercial = false;

	public function is_commercial_accommodation( int $accommodation_id ): bool {
		return $this->commercial;
	}
}

/**
 * Minimal wpdb double returning canned billable-charge rows.
 */
class AF_Test_Wpdb {

	public $prefix = 'wp_';
	public $posts  = 'wp_posts';
	public $rows   = array();
	public $last_sql = '';

	public function prepare( $sql, $args = array() ) {
		$args = (array) $args;
		return preg_replace_callback( '/%d/', static function () use ( &$args ) {
			return (int) array_shift( $args );
		}, $sql );
	}

	public function get_results( $sql ) {
		$this->last_sql = $sql;
		return $this->rows;
	}
}

class BillingChargesInvoiceTest extends TestCase {

	private function row( int $id, string $type, string $period, float $amount, array $extra = array() ): object {
		return (object) array_merge(
			array(
				'id'                  => $id,
				'lease_id'            => 10,
				'charge_type'         => $type,
				'period'              => $period,
				'description'         => '',
				'amount'              => $amount,
				'invoice_id'          => null,
				'accommodation_id'    => 77,
				'accommodation_title' => 'Apto 3B',
				'first_name'          => 'Ana',
				'last_name'           => 'Pérez',
				'id_number'           => '1710034065',
				'email'               => 'ana@example.com',
				'invoice_estado'      => null,
				'invoice_number'      => null,
				'paid_on'             => '2026-10-05',
				'pay_method'          => 'transferencia',
			),
			$extra
		);
	}

	private function manager( array $rows, bool $commercial = false ): AF_Test_Billing_Manager {
		$db       = new AF_Test_Wpdb();
		$db->rows = $rows;
		$manager  = new AF_Test_Billing_Manager( array( 'wpdb' => $db ) );
		$manager->commercial = $commercial;
		return $manager;
	}

	public function test_housing_is_billed_without_iva(): void {
		$plan = $this->manager( array( $this->row( 1, 'canon', '2026-10', 400.0 ) ) )->build_charges_invoice_plan( 10, array( 1 ) );

		$this->assertSame( '0', $plan['payload']['iva_codigo_porcentaje'] );
		$this->assertSame( 0, $plan['iva_rate'] );
		$this->assertEqualsWithDelta( 400.0, $plan['totals']['importe_total'], 0.001 );
	}

	public function test_commercial_use_adds_15_percent_iva(): void {
		$plan = $this->manager( array( $this->row( 1, 'canon', '2026-10', 400.0 ) ), true )->build_charges_invoice_plan( 10, array( 1 ) );

		$this->assertSame( '4', $plan['payload']['iva_codigo_porcentaje'] );
		$this->assertEqualsWithDelta( 60.0, $plan['totals']['iva_valor'], 0.001 );
		$this->assertEqualsWithDelta( 460.0, $plan['totals']['importe_total'], 0.001 );
	}

	public function test_payment_form_follows_payment_method(): void {
		$this->assertSame( '01', Arriendo_Facil_Billing_Manager::sri_forma_pago( 'efectivo' ) );
		$this->assertSame( '20', Arriendo_Facil_Billing_Manager::sri_forma_pago( 'transferencia' ) );

		$cash = $this->manager( array( $this->row( 1, 'canon', '2026-10', 100.0, array( 'pay_method' => 'efectivo' ) ) ) )->build_charges_invoice_plan( 10, array( 1 ) );
		$bank = $this->manager( array( $this->row( 1, 'canon', '2026-10', 100.0 ) ) )->build_charges_invoice_plan( 10, array( 1 ) );
		$this->assertSame( '01', $cash['payload']['forma_pago'] );
		$this->assertSame( '20', $bank['payload']['forma_pago'] );
	}

	public function test_several_charges_become_one_invoice_with_latest_period(): void {
		$rows = array(
			$this->row( 1, 'canon', '2026-09', 400.0 ),
			$this->row( 2, 'agua', '2026-10', 25.5 ),
		);
		$plan = $this->manager( $rows )->build_charges_invoice_plan( 10, array( 1, 2 ) );

		$this->assertCount( 2, $plan['payload']['items'] );
		$this->assertSame( '2026-10', $plan['billing_period'] );
		$this->assertEqualsWithDelta( 425.5, $plan['totals']['importe_total'], 0.001 );
		$this->assertSame( '10-2', $plan['payload']['items'][1]['codigo_auxiliar'] );
	}

	public function test_unbillable_or_foreign_charge_is_rejected(): void {
		$plan = $this->manager( array( $this->row( 1, 'canon', '2026-10', 400.0 ) ) )->build_charges_invoice_plan( 10, array( 1, 99 ) );

		$this->assertInstanceOf( WP_Error::class, $plan );
		$this->assertSame( 'charge_not_billable', $plan->get_error_code() );
	}

	public function test_empty_selection_is_rejected(): void {
		$plan = $this->manager( array() )->build_charges_invoice_plan( 10, array() );
		$this->assertSame( 'no_charges_selected', $plan->get_error_code() );
	}

	public function test_consumidor_final_above_limit_is_blocked(): void {
		$big   = $this->manager( array( $this->row( 1, 'canon', '2026-10', 400.0, array( 'id_number' => '' ) ) ) )->build_charges_invoice_plan( 10, array( 1 ) );
		$small = $this->manager( array( $this->row( 1, 'multa', '2026-10', 20.0, array( 'id_number' => '' ) ) ) )->build_charges_invoice_plan( 10, array( 1 ) );

		$this->assertNotSame( '', $big['blocking'] );
		$this->assertSame( '', $small['blocking'] );
		$this->assertSame( '9999999999999', $small['payload']['identificacion_comprador'] );
	}

	public function test_missing_email_only_warns(): void {
		$plan = $this->manager( array( $this->row( 1, 'canon', '2026-10', 400.0, array( 'email' => '' ) ) ) )->build_charges_invoice_plan( 10, array( 1 ) );

		$this->assertSame( '', $plan['blocking'] );
		$this->assertNotEmpty( $plan['warnings'] );
		$this->assertArrayNotHasKey( 'email', $plan['payload']['info_adicional'] );
	}

	public function test_rejected_invoice_charges_are_reissued_with_reuse_id(): void {
		$plan = $this->manager( array( $this->row( 1, 'canon', '2026-10', 400.0, array( 'invoice_id' => 55, 'invoice_estado' => 'devuelta' ) ) ) )->build_charges_invoice_plan( 10, array( 1 ) );

		$this->assertSame( 55, $plan['reuse_invoice_id'] );
	}

	public function test_billable_query_excludes_already_invoiced_charges(): void {
		$manager = $this->manager( array() );
		$manager->get_billable_charges( array( 'lease_id' => 10 ) );
		$sql = ( new ReflectionProperty( Arriendo_Facil_Billing_Manager::class, 'wpdb' ) );
		$last = $sql->getValue( $manager )->last_sql;

		$this->assertStringContainsString( "c.status = 'paid'", $last );
		$this->assertStringContainsString( 'c.invoice_id IS NULL', $last );
		$this->assertStringContainsString( "'devuelta'", $last );
		$this->assertStringNotContainsString( "'autorizada'", $last );
	}
}
