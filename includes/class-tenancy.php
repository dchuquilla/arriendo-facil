<?php
/**
 * Multi-tenant scoping helpers for the property-admin (subadmin) model.
 *
 * The platform is licensed to property management companies ("administradores
 * de propiedades" / subadmins, role af_property_admin). A super admin
 * (manage_options) sees everything; a subadmin only sees the buildings,
 * units, accommodations, leases, guests, charges and maintenance requests
 * that belong to their own scope.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Tenancy
 */
class Arriendo_Facil_Tenancy {

	/**
	 * Capability required to operate property-management screens/AJAX.
	 * Granted to administrator and af_property_admin.
	 */
	const CAP = 'af_manage_properties';

	/**
	 * Whether the given (or current) user can see/operate across all tenants.
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return bool
	 */
	public static function can_manage_all( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		return user_can( $user_id, 'manage_options' );
	}

	/**
	 * Accommodation IDs the given user is allowed to operate on.
	 * Returns null when the user can manage everything (no restriction).
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return int[]|null
	 */
	public static function accessible_accommodation_ids( $user_id = 0 ) {
		if ( self::can_manage_all( $user_id ) ) {
			return null;
		}

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! class_exists( 'Arriendo_Facil_Accommodation' ) ) {
			return array();
		}

		return array_map( 'absint', Arriendo_Facil_Accommodation::get_owner_accommodation_ids( $user_id ) );
	}

	/**
	 * Whether the given user can operate on a specific accommodation.
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @param int $user_id          User ID. Defaults to current user.
	 * @return bool
	 */
	public static function can_access_accommodation( $accommodation_id, $user_id = 0 ) {
		if ( self::can_manage_all( $user_id ) ) {
			return true;
		}

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$owner   = (int) get_post_meta( absint( $accommodation_id ), '_af_owner_id', true );

		return $owner > 0 && $owner === $user_id;
	}

	/**
	 * Building IDs managed by the given user. Null means unrestricted.
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return int[]|null
	 */
	public static function accessible_building_ids( $user_id = 0 ) {
		if ( self::can_manage_all( $user_id ) ) {
			return null;
		}

		global $wpdb;
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}af_buildings WHERE owner_id = %d",
				$user_id
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Whether the given user manages a specific building.
	 *
	 * @param int $building_id Building ID.
	 * @param int $user_id     User ID. Defaults to current user.
	 * @return bool
	 */
	public static function can_access_building( $building_id, $user_id = 0 ) {
		if ( self::can_manage_all( $user_id ) ) {
			return true;
		}

		global $wpdb;
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		$manager = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT owner_id FROM {$wpdb->prefix}af_buildings WHERE id = %d",
				absint( $building_id )
			)
		);

		return $manager > 0 && $manager === $user_id;
	}

	/**
	 * Whether the given user manages the building a unit belongs to.
	 *
	 * @param int $unit_id Unit ID.
	 * @param int $user_id User ID. Defaults to current user.
	 * @return bool
	 */
	public static function can_access_unit( $unit_id, $user_id = 0 ) {
		if ( self::can_manage_all( $user_id ) ) {
			return true;
		}

		global $wpdb;

		$building_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT building_id FROM {$wpdb->prefix}af_units WHERE id = %d",
				absint( $unit_id )
			)
		);

		return $building_id > 0 && self::can_access_building( $building_id, $user_id );
	}

	/**
	 * Whether the given user can operate on a lease (via its accommodation).
	 *
	 * @param int $lease_id Lease ID.
	 * @param int $user_id  User ID. Defaults to current user.
	 * @return bool
	 */
	public static function can_access_lease( $lease_id, $user_id = 0 ) {
		if ( self::can_manage_all( $user_id ) ) {
			return true;
		}

		global $wpdb;

		$accommodation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT accommodation_id FROM {$wpdb->prefix}af_leases WHERE id = %d",
				absint( $lease_id )
			)
		);

		return $accommodation_id > 0 && self::can_access_accommodation( $accommodation_id, $user_id );
	}

	/**
	 * Whether the given user can operate on a guest (via its accommodation).
	 *
	 * @param int $guest_id Guest ID.
	 * @param int $user_id  User ID. Defaults to current user.
	 * @return bool
	 */
	public static function can_access_guest( $guest_id, $user_id = 0 ) {
		if ( self::can_manage_all( $user_id ) ) {
			return true;
		}

		global $wpdb;

		$accommodation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT accommodation_id FROM {$wpdb->prefix}af_guests WHERE id = %d",
				absint( $guest_id )
			)
		);

		return $accommodation_id > 0 && self::can_access_accommodation( $accommodation_id, $user_id );
	}

	/**
	 * Whether the given user can operate on a charge (via its lease).
	 *
	 * @param int $charge_id Charge ID.
	 * @param int $user_id   User ID. Defaults to current user.
	 * @return bool
	 */
	public static function can_access_charge( $charge_id, $user_id = 0 ) {
		if ( self::can_manage_all( $user_id ) ) {
			return true;
		}

		global $wpdb;

		$lease_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT lease_id FROM {$wpdb->prefix}af_charges WHERE id = %d",
				absint( $charge_id )
			)
		);

		return $lease_id > 0 && self::can_access_lease( $lease_id, $user_id );
	}

	/**
	 * Whether the given user can operate on a maintenance request.
	 *
	 * @param int $request_id Maintenance request ID.
	 * @param int $user_id    User ID. Defaults to current user.
	 * @return bool
	 */
	public static function can_access_maintenance( $request_id, $user_id = 0 ) {
		if ( self::can_manage_all( $user_id ) ) {
			return true;
		}

		global $wpdb;

		$accommodation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT accommodation_id FROM {$wpdb->prefix}af_cleaning_requests WHERE id = %d",
				absint( $request_id )
			)
		);

		return $accommodation_id > 0 && self::can_access_accommodation( $accommodation_id, $user_id );
	}

	/**
	 * Returns all users holding the property-admin (subadmin) role.
	 *
	 * @return WP_User[]
	 */
	public static function get_property_admins() {
		return get_users(
			array(
				'role'    => 'af_property_admin',
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
	}

	/**
	 * Builds a SQL "IN (...)" fragment (or a false-y clause) from a list of
	 * accommodation IDs, ready to interpolate after validating each ID with
	 * absint(). Returns null when the caller has unrestricted access.
	 *
	 * @param int[]|null $ids Accommodation IDs, or null for unrestricted.
	 * @return string|null
	 */
	public static function ids_in_clause( $ids ) {
		if ( null === $ids ) {
			return null;
		}

		if ( empty( $ids ) ) {
			return '0';
		}

		return implode( ',', array_map( 'absint', $ids ) );
	}
}
