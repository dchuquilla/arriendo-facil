<?php
/**
 * Tests for the fixed-day monthly billing arithmetic of the plans.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-plan-billing.php';

class PlanBillingTest extends TestCase {

	private function tz() {
		return new DateTimeZone( 'America/Guayaquil' );
	}

	private function ts( $date ) {
		return ( new DateTimeImmutable( $date, $this->tz() ) )->getTimestamp();
	}

	private function fmt( $ts ) {
		return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $this->tz() )->format( 'Y-m-d H:i' );
	}

	public function test_renewal_keeps_the_fixed_billing_day_without_drifting() {
		$expires = $this->ts( '2026-12-05 00:00' );

		$next = Arriendo_Facil_Plan_Billing::period_end( $expires, 5, $this->tz() );
		$this->assertSame( '2027-01-05 00:00', $this->fmt( $next ) );

		$again = Arriendo_Facil_Plan_Billing::period_end( $next, 5, $this->tz() );
		$this->assertSame( '2027-02-05 00:00', $this->fmt( $again ) );
	}

	public function test_period_rolls_over_the_year() {
		$this->assertSame(
			'2027-01-10 00:00',
			$this->fmt( Arriendo_Facil_Plan_Billing::period_end( $this->ts( '2026-12-10 15:30' ), 10, $this->tz() ) )
		);
	}

	public function test_billing_day_is_capped_at_28_so_every_month_has_it() {
		$this->assertSame(
			'2027-02-28 00:00',
			$this->fmt( Arriendo_Facil_Plan_Billing::period_end( $this->ts( '2027-01-31 10:00' ), 31, $this->tz() ) )
		);
	}

	public function test_first_period_uses_the_activation_day() {
		list( $expires, $day ) = Arriendo_Facil_Plan_Billing::first_period( $this->ts( '2026-11-13 09:00' ), $this->tz() );

		$this->assertSame( 13, $day );
		$this->assertSame( '2026-12-13 00:00', $this->fmt( $expires ) );
	}

	public function test_first_period_after_day_28_is_never_shorter_than_27_days() {
		$now = $this->ts( '2027-01-31 12:00' );
		list( $expires, $day ) = Arriendo_Facil_Plan_Billing::first_period( $now, $this->tz() );

		$this->assertSame( 28, $day );
		$this->assertSame( '2027-02-28 00:00', $this->fmt( $expires ) );
		$this->assertGreaterThanOrEqual( 27 * DAY_IN_SECONDS, $expires - $now );
	}

	public function test_days_until_counts_calendar_days_in_both_directions() {
		$tz  = $this->tz();
		$now = $this->ts( '2026-11-28 23:50' );

		$this->assertSame( 7, Arriendo_Facil_Plan_Billing::days_until( $now, $this->ts( '2026-12-05 00:00' ), $tz ) );
		$this->assertSame( 0, Arriendo_Facil_Plan_Billing::days_until( $now, $this->ts( '2026-11-28 00:00' ), $tz ) );
		$this->assertSame( -3, Arriendo_Facil_Plan_Billing::days_until( $now, $this->ts( '2026-11-25 00:00' ), $tz ) );
	}

	public function test_state_follows_reminder_due_and_grace_windows() {
		$state = array( 'Arriendo_Facil_Plan_Billing', 'state_for_days' );

		$this->assertSame( 'ok', $state( 8, 7, 3 ) );
		$this->assertSame( 'soon', $state( 7, 7, 3 ) );
		$this->assertSame( 'soon', $state( 1, 7, 3 ) );
		$this->assertSame( 'due', $state( 0, 7, 3 ) );
		$this->assertSame( 'due', $state( -3, 7, 3 ) );
		$this->assertSame( 'lapsed', $state( -4, 7, 3 ) );
	}

	public function test_reminder_stage_picks_the_closest_threshold() {
		$stage = array( 'Arriendo_Facil_Plan_Billing', 'reminder_stage' );

		$this->assertNull( $stage( 8 ) );
		$this->assertSame( 7, $stage( 7 ) );
		$this->assertSame( 7, $stage( 5 ) );
		$this->assertSame( 3, $stage( 3 ) );
		$this->assertSame( 1, $stage( 1 ) );
		$this->assertSame( 0, $stage( 0 ) );
		$this->assertSame( 0, $stage( -2 ) );
	}
}
