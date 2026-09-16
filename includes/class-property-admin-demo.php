<?php
/**
 * Class Arriendo_Facil_Property_Admin_Demo
 *
 * Siembra/limpia un dataset de datos de ejemplo aislado por cuenta de
 * administrador de propiedades auto-registrado (af_signup_source = 'self').
 *
 * Objetivo de producto: el prospecto que verifica su correo recibe una
 * demo navegable (edificio, unidades, departamento, inquilino, contrato y
 * cobros) sin afectar datos reales de otros usuarios. Cada registro que
 * se siembra queda asociado a la cuenta propietaria (owner_id /
 * _af_owner_id = user_id), por lo que la demo siempre se muestra aislada.
 *
 * El dataset se registra en la meta af_demo_dataset del usuario para
 * permitir la limpieza idempotente ("Limpiar datos de ejemplo").
 *
 * @package Arriendo_Facil
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Property_Admin_Demo {

	/**
	 * Creates the isolated demo dataset for a property admin.
	 * Idempotent: guarded by the af_demo_seeded user meta.
	 *
	 * @param int $user_id Property admin user ID.
	 * @return true|WP_Error
	 */
	public static function seed_demo( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'af_demo_invalid_user', __( 'Usuario invalido para la demo.', 'arriendo-facil' ) );
		}

		if ( get_user_meta( $user_id, 'af_demo_seeded', true ) ) {
			return true;
		}

		$table_prefix = $wpdb->prefix;

		$now         = current_time( 'mysql' );
		$period_now  = current_time( 'Y-m' );
		$period_prev = gmdate( 'Y-m', current_time( 'timestamp' ) - MONTH_IN_SECONDS );
		$due_day     = 5;
		$due_now     = gmdate( 'Y-m-' . sprintf( '%02d', $due_day ), current_time( 'timestamp' ) );
		$due_prev    = gmdate( 'Y-m-' . sprintf( '%02d', $due_day ), current_time( 'timestamp' ) - MONTH_IN_SECONDS );

		$building_id = (int) $wpdb->insert(
			$table_prefix . 'af_buildings',
			array(
				'name'              => __( 'Edificio Alborada (demo)', 'arriendo-facil' ),
				'address'           => 'de los Nogales N45-12 y Río Coca',
				'city'              => 'Quito',
				'owner_id'          => $user_id,
				'monthly_hoa_total' => 320.00,
				'status'            => 'active',
				'notes'             => __( 'Dato de ejemplo generado para tu demo.', 'arriendo-facil' ),
				'created_at'        => $now,
			),
			array( '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s' )
		);

		if ( ! $building_id ) {
			return new WP_Error( 'af_demo_building_failed', __( 'No se pudo crear el edificio de demo.', 'arriendo-facil' ) );
		}

		$seed_units = array(
			array( 'unit_code' => 'A-101', 'hoa_coefficient' => 0.2500, 'area_m2' => 82.5, 'status' => 'rented' ),
			array( 'unit_code' => 'A-102', 'hoa_coefficient' => 0.2500, 'area_m2' => 78.0, 'status' => 'available' ),
			array( 'unit_code' => 'B-201', 'hoa_coefficient' => 0.5000, 'area_m2' => 160.5, 'status' => 'available' ),
		);

		$unit_ids = array();
		foreach ( $seed_units as $seed_unit ) {
			$unit_id = (int) $wpdb->insert(
				$table_prefix . 'af_units',
				array(
					'building_id'      => $building_id,
					'unit_code'        => $seed_unit['unit_code'],
					'hoa_coefficient'  => $seed_unit['hoa_coefficient'],
					'area_m2'          => $seed_unit['area_m2'],
					'status'           => $seed_unit['status'],
					'created_at'       => $now,
				),
				array( '%d', '%s', '%f', '%f', '%s', '%s' )
			);

			if ( $unit_id ) {
				$unit_ids[ $seed_unit['unit_code'] ] = $unit_id;
			}
		}

		if ( empty( $unit_ids['A-101'] ) ) {
			return new WP_Error( 'af_demo_units_failed', __( 'No se pudo crear las unidades de demo.', 'arriendo-facil' ) );
		}

		$accommodation_id = wp_insert_post(
			array(
				'post_type'    => 'accommodation',
				'post_status'  => 'publish',
				'post_title'   => __( 'Departamento Tipo A — Alborada (demo)', 'arriendo-facil' ),
				'post_author'  => $user_id,
			),
			true
		);

		if ( is_wp_error( $accommodation_id ) ) {
			return new WP_Error( 'af_demo_accommodation_failed', __( 'No se pudo crear el departamento de demo.', 'arriendo-facil' ) );
		}

		$accommodation_id = (int) $accommodation_id;
		update_post_meta( $accommodation_id, '_af_owner_id', $user_id );
		update_post_meta( $accommodation_id, '_af_status', 'rented' );
		update_post_meta( $accommodation_id, '_af_monthly_rent', 420.00 );
		update_post_meta( $accommodation_id, '_af_address', 'de los Nogales N45-12 y Río Coca' );
		update_post_meta( $accommodation_id, '_af_city', 'Quito' );
		update_post_meta( $accommodation_id, '_af_property_type', 'apartamento' );
		update_post_meta( $accommodation_id, '_af_square_meters', 82.5 );
		update_post_meta( $accommodation_id, '_af_bedrooms', 2 );
		update_post_meta( $accommodation_id, '_af_bathrooms', 1 );
		update_post_meta( $accommodation_id, '_af_furnished', '1' );
		update_post_meta( $accommodation_id, '_af_hoa_fee', 80.00 );
		update_post_meta( $accommodation_id, '_af_demo', '1' );

		$wpdb->update(
			$table_prefix . 'af_units',
			array( 'accommodation_id' => $accommodation_id ),
			array( 'id' => $unit_ids['A-101'] ),
			array( '%d' ),
			array( '%d' )
		);

		$guest_email = 'demo.guest.' . $user_id . '@example.local';
		$guest_id    = (int) $wpdb->insert(
			$table_prefix . 'af_guests',
			array(
				'first_name'  => 'María',
				'last_name'   => 'Andrade',
				'email'       => $guest_email,
				'phone'       => '***1234',
				'id_number'   => '***0011',
				'nationality' => 'Ecuador',
				'birth_city'  => 'Quito',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $guest_id ) {
			return new WP_Error( 'af_demo_guest_failed', __( 'No se pudo crear el inquilino de demo.', 'arriendo-facil' ) );
		}

		$lease_id = (int) $wpdb->insert(
			$table_prefix . 'af_leases',
			array(
				'accommodation_id' => $accommodation_id,
				'guest_id'         => $guest_id,
				'start_date'       => gmdate( 'Y-m-d', current_time( 'timestamp' ) - ( 180 * DAY_IN_SECONDS ) ),
				'end_date'         => gmdate( 'Y-m-d', current_time( 'timestamp' ) + ( 180 * DAY_IN_SECONDS ) ),
				'monthly_rent'     => 420.00,
				'status'           => 'active',
				'payment_due_day'  => $due_day,
			),
			array( '%d', '%d', '%s', '%s', '%f', '%s', '%d' )
		);

		if ( ! $lease_id ) {
			return new WP_Error( 'af_demo_lease_failed', __( 'No se pudo crear el contrato de demo.', 'arriendo-facil' ) );
		}

		$charge_now_id = (int) $wpdb->insert(
			$table_prefix . 'af_charges',
			array(
				'lease_id'    => $lease_id,
				'unit_id'     => $unit_ids['A-101'],
				'guest_id'    => $guest_id,
				'charge_type' => 'canon',
				'period'      => $period_now,
				'description' => __( 'Canon de arriendo del mes en curso', 'arriendo-facil' ),
				'amount'      => 420.00,
				'amount_paid' => 0.00,
				'due_date'    => $due_now,
				'status'      => 'pending',
				'created_by'  => $user_id,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%d' )
		);

		$charge_hoa_id = (int) $wpdb->insert(
			$table_prefix . 'af_charges',
			array(
				'lease_id'    => $lease_id,
				'unit_id'     => $unit_ids['A-101'],
				'guest_id'    => $guest_id,
				'charge_type' => 'alicuota',
				'period'      => $period_now,
				'description' => __( 'Alicuota mensual del edificio', 'arriendo-facil' ),
				'amount'      => 80.00,
				'amount_paid' => 0.00,
				'due_date'    => $due_now,
				'status'      => 'pending',
				'created_by'  => $user_id,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%d' )
		);

		$charge_prev_id = (int) $wpdb->insert(
			$table_prefix . 'af_charges',
			array(
				'lease_id'    => $lease_id,
				'unit_id'     => $unit_ids['A-101'],
				'guest_id'    => $guest_id,
				'charge_type' => 'canon',
				'period'      => $period_prev,
				'description' => __( 'Canon de arriendo pagado (mes anterior)', 'arriendo-facil' ),
				'amount'      => 420.00,
				'amount_paid' => 420.00,
				'due_date'    => $due_prev,
				'status'      => 'paid',
				'created_by'  => $user_id,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%d' )
		);

		$payment_id = 0;
		if ( $charge_prev_id ) {
			$payment_id = (int) $wpdb->insert(
				$table_prefix . 'af_payments',
				array(
					'charge_id'    => $charge_prev_id,
					'amount'       => 420.00,
					'payment_date' => $due_prev,
					'method'       => 'transferencia',
					'reference'    => 'DEMO-' . $user_id,
					'notes'        => __( 'Pago de ejemplo registrado en la demo.', 'arriendo-facil' ),
					'recorded_by'  => $user_id,
				),
				array( '%d', '%f', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		$dataset = array(
			'building_id'       => $building_id,
			'unit_ids'          => array_values( $unit_ids ),
			'accommodation_id'  => $accommodation_id,
			'guest_id'          => $guest_id,
			'lease_id'          => $lease_id,
			'charge_ids'        => array_filter( array( $charge_now_id, $charge_hoa_id, $charge_prev_id ) ),
			'payment_ids'       => $payment_id ? array( $payment_id ) : array(),
			'created_at'        => $now,
		);

		update_user_meta( $user_id, 'af_demo_seeded', time() );
		update_user_meta( $user_id, 'af_demo_dataset', $dataset );

		return true;
	}

	/**
	 * Removes the demo dataset for a property admin.
	 * Safe no-op when nothing was seeded.
	 *
	 * @param int $user_id Property admin user ID.
	 * @return true
	 */
	public static function purge_demo( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return true;
		}

		$dataset = get_user_meta( $user_id, 'af_demo_dataset', true );
		if ( ! is_array( $dataset ) || empty( $dataset ) ) {
			delete_user_meta( $user_id, 'af_demo_seeded' );
			return true;
		}

		$prefix = $wpdb->prefix;

		$payment_ids = ! empty( $dataset['payment_ids'] ) ? array_map( 'intval', (array) $dataset['payment_ids'] ) : array();
		if ( $payment_ids ) {
			foreach ( $payment_ids as $payment_id ) {
				$wpdb->delete( $prefix . 'af_payments', array( 'id' => $payment_id ), array( '%d' ) );
			}
		}

		$charge_ids = ! empty( $dataset['charge_ids'] ) ? array_map( 'intval', (array) $dataset['charge_ids'] ) : array();
		if ( $charge_ids ) {
			foreach ( $charge_ids as $charge_id ) {
				$wpdb->delete( $prefix . 'af_charges', array( 'id' => $charge_id ), array( '%d' ) );
			}
		}

		$lease_id = ! empty( $dataset['lease_id'] ) ? absint( $dataset['lease_id'] ) : 0;
		if ( $lease_id ) {
			$wpdb->delete( $prefix . 'af_leases', array( 'id' => $lease_id ), array( '%d' ) );
		}

		$guest_id = ! empty( $dataset['guest_id'] ) ? absint( $dataset['guest_id'] ) : 0;
		if ( $guest_id ) {
			$wpdb->delete( $prefix . 'af_guests', array( 'id' => $guest_id ), array( '%d' ) );
		}

		$accommodation_id = ! empty( $dataset['accommodation_id'] ) ? absint( $dataset['accommodation_id'] ) : 0;
		if ( $accommodation_id ) {
			wp_delete_post( $accommodation_id, true );
		}

		$unit_ids = ! empty( $dataset['unit_ids'] ) ? array_map( 'intval', (array) $dataset['unit_ids'] ) : array();
		if ( $unit_ids ) {
			foreach ( $unit_ids as $unit_id ) {
				$wpdb->delete( $prefix . 'af_units', array( 'id' => $unit_id ), array( '%d' ) );
			}
		}

		$building_id = ! empty( $dataset['building_id'] ) ? absint( $dataset['building_id'] ) : 0;
		if ( $building_id ) {
			$wpdb->delete( $prefix . 'af_buildings', array( 'id' => $building_id ), array( '%d' ) );
		}

		delete_user_meta( $user_id, 'af_demo_seeded' );
		delete_user_meta( $user_id, 'af_demo_dataset' );

		return true;
	}

	/**
	 * Whether a demo dataset is present for an admin.
	 *
	 * @param int $user_id Property admin user ID.
	 * @return bool
	 */
	public static function has_demo( $user_id ) {
		$user_id = absint( $user_id );
		return $user_id > 0 && (bool) get_user_meta( $user_id, 'af_demo_seeded', true );
	}
}