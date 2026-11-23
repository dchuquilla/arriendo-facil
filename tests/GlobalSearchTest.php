<?php
/**
 * Tests for the pure pieces of the global search and the Spanish relative time.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Arriendo_Facil_Tenancy' ) ) {
	class Arriendo_Facil_Tenancy {
		const CAP = 'af_manage_properties';
	}
}

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-global-search.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'admin/af-view-helpers.php';

class GlobalSearchTest extends TestCase {

	public function test_words_splits_and_trims() {
		$this->assertSame( array( 'juan', 'perez' ), Arriendo_Facil_Global_Search::words( "  juan \n  perez " ) );
	}

	public function test_words_rejects_too_short_queries() {
		$this->assertSame( array(), Arriendo_Facil_Global_Search::words( '' ) );
		$this->assertSame( array(), Arriendo_Facil_Global_Search::words( 'a' ) );
		$this->assertSame( array( 'ab' ), Arriendo_Facil_Global_Search::words( 'ab' ) );
	}

	public function test_words_drops_duplicates_and_caps_the_count() {
		$this->assertSame( array( 'casa' ), Arriendo_Facil_Global_Search::words( 'casa casa casa' ) );
		$this->assertCount( Arriendo_Facil_Global_Search::MAX_WORDS, Arriendo_Facil_Global_Search::words( 'a1 b2 c3 d4 e5 f6 g7' ) );
	}

	public function test_time_ago_is_spanish_with_correct_plurals() {
		$now = 1000000000;

		$this->assertSame( 'hace un momento', af_time_ago( $now - 20, $now ) );
		$this->assertSame( 'hace 5 minutos', af_time_ago( $now - 300, $now ) );
		$this->assertSame( 'hace 1 día', af_time_ago( $now - DAY_IN_SECONDS, $now ) );
		$this->assertSame( 'hace 1 semana', af_time_ago( $now - WEEK_IN_SECONDS, $now ) );
		$this->assertSame( 'hace 2 semanas', af_time_ago( $now - 2 * WEEK_IN_SECONDS, $now ) );
		$this->assertSame( 'hace 3 meses', af_time_ago( $now - 3 * MONTH_IN_SECONDS, $now ) );
		$this->assertSame( 'hace 2 años', af_time_ago( $now - 2 * YEAR_IN_SECONDS, $now ) );
	}
}
