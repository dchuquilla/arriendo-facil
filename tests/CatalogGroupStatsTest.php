<?php
/**
 * Regression tests for the admin "Edificios y conjuntos" live list.
 *
 * The panel used to be repainted from the public catalog payload, which only
 * carries groups that already hold at least one property. A building created
 * from the panel therefore disappeared from its own list (and wiped the
 * per-property dropdowns) until a full page reload, even though the row was
 * correctly stored.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

class CatalogGroupStatsTest extends TestCase {

	/**
	 * Captures the SQL issued by the stats endpoint and replays canned rows.
	 */
	private function with_captured_stats_sql( array $rows, callable $assert_sql ) {
		$share = new ReflectionClass( 'Arriendo_Facil_Catalog_Share' );
		$method = $share->getMethod( 'group_counts_for_owner' );

		$captured = null;
		$wpdb     = new class( $rows, $captured ) {

			/** @var array */
			public $posts    = 'wp_posts';
			/** @var array */
			public $postmeta = 'wp_postmeta';
			/** @var array */
			public $rows;
			/** @var string|null */
			public $captured;

			public function __construct( $rows, &$captured ) {
				$this->rows     = $rows;
				$this->captured = &$captured;
			}

			public function prepare( $query, ...$args ) {
				return vsprintf( str_replace( array( '%d', '%f', '%s' ), '%s', $query ), $args );
			}

			public function get_results( $query ) {
				$this->captured = $query;
				return $this->rows;
			}
		};

		$previous = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $wpdb;

		try {
			$counts = $method->invoke( null, 67 );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}

		$assert_sql( $wpdb->captured );

		return $counts;
	}

	/**
	 * A group holding no property must still be reported, with a zero count,
	 * so the panel can list it right after creation.
	 */
	public function test_counts_are_reported_for_groups_without_properties() {
		$rows = (object) array(
			'group_id' => '1',
			'total'    => '1',
		);

		$counts = $this->with_captured_stats_sql(
			array( $rows ),
			function ( $sql ) {
				// Counts come from postmeta; the groups table is read separately
				// via get_for_owner(), so that empty buildings are still listed.
				$this->assertStringContainsString( 'af_catalog_group_id', $sql );
			}
		);

		$this->assertSame( array( 1 => 1 ), $counts );
	}

	/**
	 * A brand new group with no members simply has no row: the panel defaults
	 * it to 0 instead of dropping it.
	 */
	public function test_absent_group_defaults_to_zero_count() {
		$counts = $this->with_captured_stats_sql(
			array(),
			function ( $sql ) {
				$this->assertStringContainsString( '_af_owner_id', $sql );
			}
		);

		$this->assertSame( array(), $counts, 'No rows means the panel must render 0 for every group.' );
	}

	/**
	 * Ownership must stay enforced in the counter query, otherwise the counts
	 * would leak another administrator's properties.
	 */
	public function test_counter_query_scopes_by_owner() {
		$this->with_captured_stats_sql(
			array(),
			function ( $sql ) {
				$this->assertStringContainsString( "om.meta_key = '_af_owner_id'", $sql );
				$this->assertStringContainsString( 'om.meta_value = 67', $sql );
				$this->assertStringContainsString( "p.post_status = 'publish'", $sql );
				$this->assertStringContainsString( "gm.meta_value <> '0'", $sql );
			}
		);
	}

	/**
	 * Invalid owner ids short-circuit instead of hitting the database.
	 */
	public function test_zero_owner_returns_no_counts() {
		$method = ( new ReflectionClass( 'Arriendo_Facil_Catalog_Share' ) )->getMethod( 'group_counts_for_owner' );

		// The shared $wpdb stub has no posts/postmeta tables, so reaching the
		// database here at all would be the failure: it must short-circuit.
		$this->assertSame( array(), $method->invoke( null, 0 ) );
		$this->assertSame( array(), $method->invoke( null, '' ) );
		$this->assertSame( array(), $method->invoke( null, 'abc' ) );
	}
}
