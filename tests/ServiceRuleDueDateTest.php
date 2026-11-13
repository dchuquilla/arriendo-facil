<?php
/**
 * A service rule created after its day of the month has passed must not start
 * overdue: its first obligation is the next month's.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-property-structure.php';
require_once __DIR__ . '/../includes/class-tenancy.php';
require_once __DIR__ . '/../includes/class-billing-ledger.php';

class ServiceRuleDueDateTest extends TestCase {

	public function test_rule_created_after_its_day_rolls_to_next_month() {
		$rule = Arriendo_Facil_Billing_Ledger::resolve_rule_due_date( '2026-11-10 09:00:00', '2026-11', 5 );

		$this->assertTrue( $rule['applies'] );
		$this->assertTrue( $rule['deferred'] );
		$this->assertSame( '2026-12-05', $rule['due_date'] );
	}

	public function test_rule_created_before_its_day_keeps_the_current_date() {
		$rule = Arriendo_Facil_Billing_Ledger::resolve_rule_due_date( '2026-11-02 09:00:00', '2026-11', 5 );

		$this->assertTrue( $rule['applies'] );
		$this->assertFalse( $rule['deferred'] );
		$this->assertSame( '2026-11-05', $rule['due_date'] );
	}

	public function test_rule_created_on_its_own_day_is_not_deferred() {
		$rule = Arriendo_Facil_Billing_Ledger::resolve_rule_due_date( '2026-11-05 23:00:00', '2026-11', 5 );

		$this->assertFalse( $rule['deferred'] );
		$this->assertSame( '2026-11-05', $rule['due_date'] );
	}

	public function test_periods_before_the_rule_existed_do_not_apply() {
		$rule = Arriendo_Facil_Billing_Ledger::resolve_rule_due_date( '2026-11-10 09:00:00', '2026-10', 5 );

		$this->assertFalse( $rule['applies'] );
	}

	public function test_later_periods_use_their_regular_date() {
		$rule = Arriendo_Facil_Billing_Ledger::resolve_rule_due_date( '2026-11-10 09:00:00', '2027-01', 5 );

		$this->assertTrue( $rule['applies'] );
		$this->assertFalse( $rule['deferred'] );
		$this->assertSame( '2027-01-05', $rule['due_date'] );
	}

	public function test_december_defers_into_january_of_next_year() {
		$rule = Arriendo_Facil_Billing_Ledger::resolve_rule_due_date( '2026-12-20 09:00:00', '2026-12', 5 );

		$this->assertTrue( $rule['deferred'] );
		$this->assertSame( '2027-01-05', $rule['due_date'] );
	}

	public function test_missing_creation_date_keeps_legacy_behaviour() {
		$rule = Arriendo_Facil_Billing_Ledger::resolve_rule_due_date( '', '2026-11', 5 );

		$this->assertTrue( $rule['applies'] );
		$this->assertFalse( $rule['deferred'] );
		$this->assertSame( '2026-11-05', $rule['due_date'] );
	}
}
