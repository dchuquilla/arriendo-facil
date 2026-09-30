<?php
/**
 * Tests for Arriendo_Facil_Occupancy, the single source of truth that keeps
 * _af_is_occupied / _af_status / _af_commercial_* in sync so every section
 * (listado, catalogo, panel, cobranza) shows the same occupancy.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_post_type' ) ) {
	/**
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function get_post_type( $post_id ) {
		return 'accommodation';
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		if ( ! isset( $GLOBALS['af_meta'][ $post_id ][ $key ] ) ) {
			return '';
		}

		return $GLOBALS['af_meta'][ $post_id ][ $key ];
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return bool
	 */
	function update_post_meta( $post_id, $meta_key, $meta_value ) {
		$GLOBALS['af_meta'][ $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	/**
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	function delete_post_meta( $post_id, $meta_key ) {
		unset( $GLOBALS['af_meta'][ $post_id ][ $meta_key ] );
		return true;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * @param array $args Arguments.
	 * @return array
	 */
	function get_posts( $args ) {
		$ids = isset( $GLOBALS['af_posts'] ) && is_array( $GLOBALS['af_posts'] ) ? $GLOBALS['af_posts'] : array();

		if ( 'ids' === $args['fields'] ) {
			return $ids;
		}

		return array_map(
			function ( $id ) {
				return (object) array( 'ID' => $id );
			},
			$ids
		);
	}
}

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-occupancy.php';

/**
 * Class OccupancyTest
 */
class OccupancyTest extends TestCase {

	/**
	 * Resets the in-memory meta store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['af_meta']   = array();
		$GLOBALS['af_posts']  = array( 5, 6, 7, 8 );
		// CatalogCardsQueryTest define su propio get_posts(); alimentamos los
		// dos globals para que el stub ganador devuelva los mismos IDs.
		$GLOBALS['af_test_candidate_ids'] = array( 5, 6, 7, 8 );
		$GLOBALS['wpdb']      = new WPDB_Stub();
	}

	/**
	 * Mark occupied sets all four representations.
	 */
	public function test_mark_occupied_syncs_all_lenses() {
		Arriendo_Facil_Occupancy::mark_occupied( 5 );

		$this->assertTrue( Arriendo_Facil_Occupancy::is_occupied( 5 ) );
		$this->assertSame( 'rented', get_post_meta( 5, '_af_status' ) );
		$this->assertSame( 'rented', get_post_meta( 5, '_af_commercial_state' ) );
		$this->assertSame( 'private', get_post_meta( 5, '_af_commercial_visibility' ) );
		$this->assertSame( 'private', get_post_meta( 5, '_af_commercial_status' ) );
		$this->assertSame( '1', get_post_meta( 5, '_af_is_occupied' ) );
	}

	/**
	 * Mark available clears occupancy in every representation.
	 */
	public function test_mark_available_clears_all_lenses() {
		Arriendo_Facil_Occupancy::mark_occupied( 5 );
		Arriendo_Facil_Occupancy::mark_available( 5 );

		$this->assertFalse( Arriendo_Facil_Occupancy::is_occupied( 5 ) );
		$this->assertSame( 'available', get_post_meta( 5, '_af_status' ) );
		$this->assertSame( 'available', get_post_meta( 5, '_af_commercial_state' ) );
		$this->assertSame( 'public', get_post_meta( 5, '_af_commercial_visibility' ) );
		$this->assertSame( '', get_post_meta( 5, '_af_is_occupied' ) );
	}

	/**
	 * Occupied transition must not erase a manual maintenance status.
	 */
	public function test_mark_occupied_preserves_maintenance_status() {
		update_post_meta( 5, '_af_status', 'maintenance' );

		Arriendo_Facil_Occupancy::mark_occupied( 5 );

		$this->assertSame( 'maintenance', get_post_meta( 5, '_af_status' ) );
		$this->assertTrue( Arriendo_Facil_Occupancy::is_occupied( 5 ) );
		$this->assertSame( 'private', get_post_meta( 5, '_af_commercial_visibility' ) );
	}

	/**
	 * Sync derives availability when leases no longer block the unit.
	 */
	public function test_sync_from_leases_marks_available_when_no_lease() {
		update_post_meta( 5, '_af_is_occupied', '1' );
		update_post_meta( 5, '_af_status', 'rented' );

		Arriendo_Facil_Occupancy::sync_from_leases( 5 );

		$this->assertFalse( Arriendo_Facil_Occupancy::is_occupied( 5 ) );
		$this->assertSame( 'available', get_post_meta( 5, '_af_status' ) );
	}

	/**
	 * Backfill normalizes legacy markups into a single consistent view:
	 * manual occupied stays occupied, drifted statuses get fixed, orphans
	 * get cleaned.
	 */
	public function test_backfill_all_normalizes_legacy_data() {
		// 5: marcado ocupado manualmente, sin contrato -> se conserva ocupado.
		update_post_meta( 5, '_af_is_occupied', '1' );
		update_post_meta( 5, '_af_status', 'available' );

		// 6: estado 'rented' huerfano (sin contrato ni marca) -> conservador:
		//    se mantiene ocupado para no liberar por error una unidad real.
		update_post_meta( 6, '_af_status', 'rented' );
		update_post_meta( 6, '_af_commercial_state', 'rented' );

		// 7: huerfano de marca manual sin contrato -> se conserva ocupado.
		update_post_meta( 7, '_af_is_occupied', '1' );
		update_post_meta( 7, '_af_status', 'available' );

		// 8: unidad realmente disponible con metadata comercial obsoleta ->
		//    se limpia y queda consistente como disponible.
		update_post_meta( 8, '_af_commercial_state', 'rented' );
		update_post_meta( 8, '_af_commercial_visibility', 'private' );

		$updated = Arriendo_Facil_Occupancy::backfill_all();

		$this->assertSame( 4, $updated );

		// 5 ocupado consistente.
		$this->assertTrue( Arriendo_Facil_Occupancy::is_occupied( 5 ) );
		$this->assertSame( 'rented', get_post_meta( 5, '_af_status' ) );

		// 6 se conserva ocupado (decision conservadora).
		$this->assertTrue( Arriendo_Facil_Occupancy::is_occupied( 6 ) );
		$this->assertSame( 'rented', get_post_meta( 6, '_af_status' ) );

		// 7 marca manual huerfana: se conserva ocupado.
		$this->assertTrue( Arriendo_Facil_Occupancy::is_occupied( 7 ) );

		// 8 huerfano de metadata comercial: se libera y queda disponible.
		$this->assertFalse( Arriendo_Facil_Occupancy::is_occupied( 8 ) );
		$this->assertSame( 'available', get_post_meta( 8, '_af_status' ) );
		$this->assertSame( 'available', get_post_meta( 8, '_af_commercial_state' ) );
		$this->assertSame( 'public', get_post_meta( 8, '_af_commercial_visibility' ) );
	}

	/**
	 * Backfill skips maintenance/inactive units.
	 */
	public function test_backfill_skips_manual_operational_states() {
		update_post_meta( 5, '_af_status', 'maintenance' );

		Arriendo_Facil_Occupancy::backfill_all();

		$this->assertSame( 'maintenance', get_post_meta( 5, '_af_status' ) );
	}

	/**
	 * A reservation blocks the unit but keeps it visible in the catalog, and
	 * does not claim it is rented.
	 */
	public function test_apply_commercial_state_reserved_keeps_catalog_visibility() {
		Arriendo_Facil_Occupancy::apply_commercial_state( 5, 'reserved', 'public' );

		$this->assertTrue( Arriendo_Facil_Occupancy::is_occupied( 5 ) );
		$this->assertSame( 'reserved', get_post_meta( 5, '_af_commercial_state' ) );
		$this->assertSame( 'public', get_post_meta( 5, '_af_commercial_visibility' ) );
		$this->assertSame( 'reserved', get_post_meta( 5, '_af_commercial_status' ) );
		$this->assertSame( '', get_post_meta( 5, '_af_status' ) );
	}

	/**
	 * Renting through the commercial state fully occupies the unit.
	 */
	public function test_apply_commercial_state_rented_occupies_everything() {
		Arriendo_Facil_Occupancy::apply_commercial_state( 5, 'rented', 'private' );

		$this->assertTrue( Arriendo_Facil_Occupancy::is_occupied( 5 ) );
		$this->assertSame( 'rented', get_post_meta( 5, '_af_status' ) );
		$this->assertSame( 'private', get_post_meta( 5, '_af_commercial_visibility' ) );
	}

	/**
	 * Releasing the hold makes the unit available again.
	 */
	public function test_apply_commercial_state_available_frees_unit() {
		Arriendo_Facil_Occupancy::apply_commercial_state( 5, 'reserved', 'public' );

		Arriendo_Facil_Occupancy::apply_commercial_state( 5, 'available', 'public' );

		$this->assertFalse( Arriendo_Facil_Occupancy::is_occupied( 5 ) );
		$this->assertSame( 'available', get_post_meta( 5, '_af_status' ) );
		$this->assertSame( 'public', get_post_meta( 5, '_af_commercial_visibility' ) );
	}

	/**
	 * set_occupied_flag only touches the occupancy flag, never the status.
	 */
	public function test_set_occupied_flag_leaves_status_untouched() {
		update_post_meta( 5, '_af_status', 'available' );

		Arriendo_Facil_Occupancy::set_occupied_flag( 5, true );
		$this->assertTrue( Arriendo_Facil_Occupancy::is_occupied( 5 ) );
		$this->assertSame( 'available', get_post_meta( 5, '_af_status' ) );

		Arriendo_Facil_Occupancy::set_occupied_flag( 5, false );
		$this->assertFalse( Arriendo_Facil_Occupancy::is_occupied( 5 ) );
		$this->assertSame( 'available', get_post_meta( 5, '_af_status' ) );
	}
}