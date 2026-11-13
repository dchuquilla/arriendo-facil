<?php
/**
 * Detaches a property's tenant, lease and open charges when it goes to the
 * trash, and puts everything back when it is restored.
 *
 * Nothing is hard-deleted: leases are soft-deleted (deleted_at), unpaid charges
 * are voided and tenants/units are unlinked. A snapshot kept in post meta lets
 * a restore reverse exactly what the trash changed.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Property_Trash_Relations {

	const META_SNAPSHOT = '_af_trash_snapshot';

	/** Same detach/reattach, used when a plan limit hides a property. */
	const LOCK_SNAPSHOT = '_af_lock_snapshot';

	/**
	 * Statuses of a lease that tie a tenant to the property.
	 */
	const LEASE_STATUSES = array( 'draft', 'active', 'pending_release' );

	public function __construct() {
		add_action( 'trashed_post', array( $this, 'on_trashed' ) );
		add_action( 'untrashed_post', array( $this, 'on_untrashed' ) );
	}

	/**
	 * Detaches the relations when a property goes to the trash.
	 *
	 * @param int $post_id Property ID.
	 */
	public function on_trashed( $post_id ) {
		self::detach( $post_id, self::META_SNAPSHOT, 'property_trashed' );
	}

	/**
	 * Re-attaches the relations when a property leaves the trash.
	 *
	 * @param int $post_id Property ID.
	 */
	public function on_untrashed( $post_id ) {
		self::reattach( $post_id, self::META_SNAPSHOT, 'property_restored' );
	}

	/**
	 * Whether the post is an accommodation.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_accommodation( $post_id ) {
		return 'accommodation' === get_post_type( $post_id );
	}

	/**
	 * Whether a column exists (guest.accommodation_id is added by a migration).
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private static function has_column( $table, $column ) {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
	}

	/**
	 * Release reason stamped on the reservations a given snapshot released.
	 *
	 * @param string $snapshot_key Snapshot meta key.
	 * @return string
	 */
	private static function release_reason( $snapshot_key ) {
		return self::LOCK_SNAPSHOT === $snapshot_key ? 'property_locked' : 'property_trashed';
	}

	/**
	 * Detaches the relations and stores what changed in $snapshot_key.
	 *
	 * @param int    $post_id      Property ID.
	 * @param string $snapshot_key Post meta key of the snapshot.
	 * @param string $event        Lease event logged for each lease.
	 */
	public static function detach( $post_id, $snapshot_key, $event ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! self::is_accommodation( $post_id ) ) {
			return;
		}

		global $wpdb;

		$leases_table = $wpdb->prefix . 'af_leases';
		$guests_table = $wpdb->prefix . 'af_guests';
		$snapshot     = array(
			'leases'       => array(),
			'charges'      => array(),
			'units'        => array(),
			'reservations' => array(),
		);

		$statuses_in = "'" . implode( "','", self::LEASE_STATUSES ) . "'";
		$leases      = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$leases_table} WHERE accommodation_id = %d AND status IN ({$statuses_in}) AND deleted_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant list.
				$post_id
			)
		);

		$lease_ids = array();
		foreach ( $leases as $lease ) {
			$lease_ids[] = (int) $lease->id;
		}

		if ( ! empty( $lease_ids ) ) {
			$in                = implode( ',', $lease_ids );
			$snapshot['leases'] = $lease_ids;

			$charges = (array) $wpdb->get_results(
				"SELECT id, status FROM " . Arriendo_Facil_Billing_Ledger::charges_table() . " WHERE lease_id IN ({$in}) AND status IN ('pending','overdue') AND amount_paid = 0" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids are cast to int.
			);
			foreach ( $charges as $charge ) {
				$snapshot['charges'][ (int) $charge->id ] = (string) $charge->status;
			}
			if ( ! empty( $snapshot['charges'] ) ) {
				$wpdb->query( "UPDATE " . Arriendo_Facil_Billing_Ledger::charges_table() . " SET status = 'void' WHERE id IN (" . implode( ',', array_map( 'intval', array_keys( $snapshot['charges'] ) ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids are cast to int.
			}

			$wpdb->query( $wpdb->prepare( "UPDATE {$leases_table} SET deleted_at = %s WHERE id IN ({$in})", current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are cast to int.
		}

		if ( self::has_column( $guests_table, 'accommodation_id' ) ) {
			$linked = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$guests_table} WHERE accommodation_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			if ( ! empty( $linked ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$guests_table} SET accommodation_id = NULL WHERE accommodation_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			}
			$snapshot['linked'] = array_map( 'intval', $linked );
		}

		if ( class_exists( 'Arriendo_Facil_Property_Structure' ) ) {
			$units_table = Arriendo_Facil_Property_Structure::units_table();
			$unit_ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$units_table} WHERE accommodation_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			if ( ! empty( $unit_ids ) ) {
				$snapshot['units'] = array_map( 'intval', $unit_ids );
				$wpdb->query( $wpdb->prepare( "UPDATE {$units_table} SET accommodation_id = NULL WHERE accommodation_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			}
		}

		$reservations_table = $wpdb->prefix . 'af_reservations';
		$reservation_ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$reservations_table} WHERE accommodation_id = %d AND status = 'reserved'", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		if ( ! empty( $reservation_ids ) ) {
			$snapshot['reservations'] = array_map( 'intval', $reservation_ids );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$reservations_table} SET status = 'released', reservation_status = 'released', release_reason = %s, released_at = %s WHERE accommodation_id = %d AND status = 'reserved'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
					self::release_reason( $snapshot_key ),
					current_time( 'mysql' ),
					$post_id
				)
			);
		}

		update_post_meta( $post_id, $snapshot_key, wp_slash( wp_json_encode( $snapshot ) ) );

		if ( ! empty( $lease_ids ) ) {
			foreach ( $lease_ids as $lease_id ) {
				Arriendo_Facil_Rental_Workflow::log_lease_event( $lease_id, $post_id, $event, array( 'detached_by' => get_current_user_id() ) );
			}
		}
	}

	/**
	 * Re-attaches what detach() stored in $snapshot_key.
	 *
	 * @param int    $post_id      Property ID.
	 * @param string $snapshot_key Post meta key of the snapshot.
	 * @param string $event        Lease event logged for each lease.
	 */
	public static function reattach( $post_id, $snapshot_key, $event ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! self::is_accommodation( $post_id ) ) {
			return;
		}

		$raw      = (string) get_post_meta( $post_id, $snapshot_key, true );
		$snapshot = '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $snapshot ) ) {
			return;
		}

		global $wpdb;

		$leases_table = $wpdb->prefix . 'af_leases';
		$guests_table = $wpdb->prefix . 'af_guests';

		$lease_ids = array_values( array_filter( array_map( 'intval', (array) ( $snapshot['leases'] ?? array() ) ) ) );
		if ( ! empty( $lease_ids ) ) {
			$in = implode( ',', $lease_ids );
			$wpdb->query( "UPDATE {$leases_table} SET deleted_at = NULL WHERE id IN ({$in}) AND accommodation_id = " . $post_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids are cast to int.
		}

		foreach ( (array) ( $snapshot['charges'] ?? array() ) as $charge_id => $status ) {
			if ( ! in_array( $status, array( 'pending', 'overdue' ), true ) ) {
				continue;
			}
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . Arriendo_Facil_Billing_Ledger::charges_table() . " SET status = %s WHERE id = %d AND status = 'void' AND amount_paid = 0", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
					$status,
					(int) $charge_id
				)
			);
		}

		// Only re-link tenants that were linked to this property and are still free.
		if ( self::has_column( $guests_table, 'accommodation_id' ) ) {
			foreach ( array_map( 'intval', (array) ( $snapshot['linked'] ?? array() ) ) as $guest_id ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$guests_table} SET accommodation_id = %d WHERE id = %d AND accommodation_id IS NULL", $post_id, $guest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			}
		}

		if ( class_exists( 'Arriendo_Facil_Property_Structure' ) ) {
			$units_table = Arriendo_Facil_Property_Structure::units_table();
			foreach ( array_map( 'intval', (array) ( $snapshot['units'] ?? array() ) ) as $unit_id ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$units_table} SET accommodation_id = %d WHERE id = %d AND accommodation_id IS NULL", $post_id, $unit_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			}
		}

		$reservation_ids = array_values( array_filter( array_map( 'intval', (array) ( $snapshot['reservations'] ?? array() ) ) ) );
		if ( ! empty( $reservation_ids ) ) {
			$in = implode( ',', $reservation_ids );
			$wpdb->query( "UPDATE {$wpdb->prefix}af_reservations SET status = 'reserved', reservation_status = 'reserved', release_reason = NULL, released_at = NULL WHERE id IN ({$in}) AND release_reason = '" . self::release_reason( $snapshot_key ) . "'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids are cast to int.
		}

		delete_post_meta( $post_id, $snapshot_key );

		foreach ( $lease_ids as $lease_id ) {
			Arriendo_Facil_Rental_Workflow::log_lease_event( $lease_id, $post_id, $event, array( 'restored_by' => get_current_user_id() ) );
		}
	}
}
