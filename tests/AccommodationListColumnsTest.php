<?php
/**
 * Tests for the accommodation list table columns (edit.php?post_type=accommodation).
 *
 * The screen is a browsing list, so the checkbox column is dropped and the
 * status/type dropdowns are no longer rendered. Both are easy to reintroduce by
 * accident from another admin class, and the column order is what makes the
 * table readable, so it is worth locking down here.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Records hooked callbacks so the constructor can be inspected.
	 *
	 * @param string $hook     Hook name.
	 * @param array  $callback Callback.
	 * @param int    $priority Priority.
	 * @param int    $args     Accepted args.
	 */
	function add_filter( $hook, $callback = null, $priority = 10, $args = 1 ) {
		$GLOBALS['af_test_hooks'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Records hooked callbacks so the constructor can be inspected.
	 *
	 * @param string $hook     Hook name.
	 * @param array  $callback Callback.
	 * @param int    $priority Priority.
	 * @param int    $args     Accepted args.
	 */
	function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) {
		$GLOBALS['af_test_hooks'][ $hook ][] = $callback;
	}
}

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-accommodation-list-admin.php';

/**
 * Class AccommodationListColumnsTest
 */
class AccommodationListColumnsTest extends TestCase {

	/**
	 * Builds an instance without running the constructor's hook wiring.
	 *
	 * @return Arriendo_Facil_Accommodation_List_Admin
	 */
	private function listTable() {
		$class = new ReflectionClass( 'Arriendo_Facil_Accommodation_List_Admin' );

		return $class->newInstanceWithoutConstructor();
	}

	/**
	 * The columns WordPress passes before the plugin filters them.
	 *
	 * @return array
	 */
	private function coreColumns() {
		return array(
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
			'date'  => 'Date',
		);
	}

	/**
	 * The checkbox column would bring back the whole bulk-action row.
	 */
	public function test_checkbox_column_is_removed() {
		$columns = $this->listTable()->add_columns( $this->coreColumns() );

		$this->assertArrayNotHasKey( 'cb', $columns );
	}

	/**
	 * Bulk actions also disappear when the column is not rendered, and the
	 * dropdowns came from a hook that is no longer registered.
	 */
	public function test_bulk_and_filter_furniture_is_not_hooked() {
		$GLOBALS['af_test_hooks'] = array();

		$class = new ReflectionClass( 'Arriendo_Facil_Accommodation_List_Admin' );
		$class->newInstance();

		$this->assertArrayNotHasKey( 'restrict_manage_posts', $GLOBALS['af_test_hooks'] );
		$this->assertFalse( method_exists( 'Arriendo_Facil_Accommodation_List_Admin', 'render_filters' ) );
	}

	/**
	 * The custom columns bracket the title so the row reads in one pass.
	 */
	public function test_custom_columns_surround_the_title() {
		$columns = array_keys( $this->listTable()->add_columns( $this->coreColumns() ) );

		$expected = array( 'af_thumb', 'title', 'af_meta', 'af_price', 'af_status', 'date' );

		$this->assertSame( $expected, $columns );
	}

	/**
	 * A post type that never exposes a title column still gets the custom ones.
	 */
	public function test_columns_survive_a_missing_title_column() {
		$columns = array_keys( $this->listTable()->add_columns( array( 'date' => 'Date' ) ) );

		foreach ( array( 'af_thumb', 'af_meta', 'af_price', 'af_status' ) as $key ) {
			$this->assertContains( $key, $columns );
		}
	}

	/**
	 * The rent column is sortable so the list can be read by price.
	 */
	public function test_price_column_is_sortable() {
		$sortable = $this->listTable()->sortable_columns( array( 'title' => 'title' ) );

		$this->assertSame( 'af_price', $sortable['af_price'] );
	}
}
