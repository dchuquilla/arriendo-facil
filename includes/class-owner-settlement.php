<?php
/**
 * Owner settlements (liquidación al propietario).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Owner_Settlement
 *
 * Builds the monthly statement an owner receives: what was collected from
 * their tenants, what was spent on their properties, and the net balance.
 */
class Arriendo_Facil_Owner_Settlement {

	/**
	 * Returns the accommodation IDs that belong to an owner.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return int[]
	 */
	public static function get_owner_property_ids( $owner_id ) {
		$owner_id = absint( $owner_id );
		if ( ! $owner_id ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => 'accommodation',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_af_owner_id',
						'value' => $owner_id,
					),
				),
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Lists the owners that currently have at least one property assigned.
	 *
	 * @param int[]|null $accommodation_ids Restrict to these accommodations
	 *                                       (property-admin scope). Null = all.
	 * @return array<int,array{id:int,name:string,property_count:int}>
	 */
	public static function get_active_owners( $accommodation_ids = null ) {
		global $wpdb;

		$scope_clause = '';
		if ( is_array( $accommodation_ids ) ) {
			$scope_clause = empty( $accommodation_ids )
				? ' AND 1 = 0'
				: ' AND p.ID IN (' . implode( ',', array_map( 'absint', $accommodation_ids ) ) . ')';
		}

		$rows = (array) $wpdb->get_results(
			"SELECT pm.meta_value AS owner_id, COUNT(*) AS property_count
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_af_owner_id'
			   AND pm.meta_value > 0
			   AND p.post_type = 'accommodation'{$scope_clause}
			 GROUP BY pm.meta_value" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$owners = array();
		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row->owner_id );
			if ( ! $user ) {
				continue;
			}

			$owners[] = array(
				'id'             => (int) $row->owner_id,
				'name'           => $user->display_name ? $user->display_name : $user->user_login,
				'property_count' => (int) $row->property_count,
			);
		}

		usort(
			$owners,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $owners;
	}

	/**
	 * Builds the settlement for one owner and period.
	 *
	 * @param int    $owner_id Owner user ID.
	 * @param string $period   Period in YYYY-MM format.
	 * @return array{
	 *     collected:float, pending:float, expenses:float, net:float,
	 *     charges:array<int,object>, expenses_detail:array<int,object>, property_ids:int[]
	 * }
	 */
	public static function build( $owner_id, $period ) {
		global $wpdb;

		$empty = array(
			'collected'       => 0.0,
			'pending'         => 0.0,
			'expenses'        => 0.0,
			'net'             => 0.0,
			'charges'         => array(),
			'expenses_detail' => array(),
			'property_ids'    => array(),
		);

		$owner_id = absint( $owner_id );
		$period   = sanitize_text_field( (string) $period );

		if ( ! $owner_id || ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
			return $empty;
		}

		$property_ids = self::get_owner_property_ids( $owner_id );
		if ( empty( $property_ids ) ) {
			return $empty;
		}

		$ids_sql = implode( ',', $property_ids );

		$charges = array();
		if ( class_exists( 'Arriendo_Facil_Billing_Ledger' ) ) {
			$charges_table = Arriendo_Facil_Billing_Ledger::charges_table();
			$leases_table  = $wpdb->prefix . 'af_leases';

			$charges = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.*, p.post_title AS accommodation_title
					 FROM {$charges_table} c
					 INNER JOIN {$leases_table} l ON l.id = c.lease_id
					 LEFT JOIN {$wpdb->posts} p ON p.ID = l.accommodation_id
					 WHERE c.period = %s
					   AND c.status != 'void'
					   AND l.accommodation_id IN ({$ids_sql})
					 ORDER BY p.post_title ASC, c.charge_type ASC",
					$period
				)
			);
		}

		$collected = 0.0;
		$billed    = 0.0;
		foreach ( $charges as $charge ) {
			$billed    += (float) $charge->amount;
			$collected += (float) $charge->amount_paid;
		}

		$expenses_detail = array();
		$expenses        = 0.0;
		if ( class_exists( 'Arriendo_Facil_Maintenance' ) ) {
			$maintenance_table = Arriendo_Facil_Maintenance::table();
			$period_start      = $period . '-01';
			$period_end        = gmdate( 'Y-m-t', strtotime( $period_start ) );

			$expenses_detail = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.*, p.post_title AS accommodation_title
					 FROM {$maintenance_table} r
					 LEFT JOIN {$wpdb->posts} p ON p.ID = r.accommodation_id
					 WHERE r.status = 'completed'
					   AND r.completed_date BETWEEN %s AND %s
					   AND r.accommodation_id IN ({$ids_sql})
					 ORDER BY r.completed_date ASC",
					$period_start,
					$period_end
				)
			);

			foreach ( $expenses_detail as $expense ) {
				$expenses += (float) ( $expense->cost ?? 0 );
			}
		}

		return array(
			'collected'       => round( $collected, 2 ),
			'pending'         => round( $billed - $collected, 2 ),
			'expenses'        => round( $expenses, 2 ),
			'net'             => round( $collected - $expenses, 2 ),
			'charges'         => $charges,
			'expenses_detail' => $expenses_detail,
			'property_ids'    => $property_ids,
		);
	}
}
