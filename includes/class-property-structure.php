<?php
/**
 * Buildings and units management.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Property_Structure
 *
 * Manages the building -> unit hierarchy required to prorate HOA fees
 * (alicuotas) and shared utilities across units.
 */
class Arriendo_Facil_Property_Structure {

	/**
	 * Hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_create_building', array( $this, 'ajax_create_building' ) );
		add_action( 'wp_ajax_af_create_unit', array( $this, 'ajax_create_unit' ) );
	}

	/**
	 * Returns the buildings table name.
	 *
	 * @return string
	 */
	public static function buildings_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_buildings';
	}

	/**
	 * Returns the units table name.
	 *
	 * @return string
	 */
	public static function units_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_units';
	}

	/**
	 * Whether at least one active building exists.
	 *
	 * @return bool
	 */
	public static function has_active_buildings() {
		global $wpdb;

		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::buildings_table() . " WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return absint( $count ) > 0;
	}

	/**
	 * Whether the buildings module is enabled.
	 *
	 * The section is optional: it is only used to split shared building
	 * expenses (gastos comunes) and shared meters between units inside the
	 * same building. The admin can enable or disable it from the section
	 * itself; disabling it never deletes data.
	 *
	 * @return bool
	 */
	public static function module_enabled() {
		return '1' === (string) get_option( 'af_use_buildings', '0' );
	}

	/**
	 * Creates a building.
	 *
	 * @param array<string,mixed> $data Building data.
	 * @return int|WP_Error Building ID on success.
	 */
	public static function create_building( array $data ) {
		global $wpdb;

		$name = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'af_building_name_required', __( 'El nombre del edificio es obligatorio.', 'arriendo-facil' ) );
		}

		$inserted = $wpdb->insert(
			self::buildings_table(),
			array(
				'name'              => $name,
				'address'           => isset( $data['address'] ) ? sanitize_text_field( (string) $data['address'] ) : null,
				'city'              => isset( $data['city'] ) ? sanitize_text_field( (string) $data['city'] ) : null,
				'owner_id'          => isset( $data['owner_id'] ) ? absint( $data['owner_id'] ) : null,
				'monthly_hoa_total' => isset( $data['monthly_hoa_total'] ) ? (float) $data['monthly_hoa_total'] : 0.0,
				'status'            => 'active',
				'notes'             => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : null,
			),
			array( '%s', '%s', '%s', '%d', '%f', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_building_insert_failed', __( 'No se pudo crear el edificio.', 'arriendo-facil' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Creates a unit inside a building.
	 *
	 * @param array<string,mixed> $data Unit data.
	 * @return int|WP_Error Unit ID on success.
	 */
	public static function create_unit( array $data ) {
		global $wpdb;

		$building_id = isset( $data['building_id'] ) ? absint( $data['building_id'] ) : 0;
		$unit_code   = isset( $data['unit_code'] ) ? sanitize_text_field( (string) $data['unit_code'] ) : '';

		if ( ! $building_id || ! self::get_building( $building_id ) ) {
			return new WP_Error( 'af_unit_building_invalid', __( 'Edificio invalido.', 'arriendo-facil' ) );
		}

		if ( '' === trim( $unit_code ) ) {
			return new WP_Error( 'af_unit_code_required', __( 'El codigo de la unidad es obligatorio.', 'arriendo-facil' ) );
		}

		$inserted = $wpdb->insert(
			self::units_table(),
			array(
				'building_id'      => $building_id,
				'accommodation_id' => isset( $data['accommodation_id'] ) ? absint( $data['accommodation_id'] ) : null,
				'unit_code'        => $unit_code,
				'hoa_coefficient'  => isset( $data['hoa_coefficient'] ) ? (float) $data['hoa_coefficient'] : 0.0,
				'area_m2'          => isset( $data['area_m2'] ) ? (float) $data['area_m2'] : 0.0,
				'status'           => 'active',
			),
			array( '%d', '%d', '%s', '%f', '%f', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_unit_insert_failed', __( 'No se pudo crear la unidad. Verifica que el codigo no este repetido.', 'arriendo-facil' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retrieves a building row.
	 *
	 * @param int $building_id Building ID.
	 * @return object|null
	 */
	public static function get_building( $building_id ) {
		global $wpdb;

		$building_id = absint( $building_id );
		if ( ! $building_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::buildings_table() . ' WHERE id = %d', $building_id )
		);
	}

	/**
	 * Retrieves a unit row.
	 *
	 * @param int $unit_id Unit ID.
	 * @return object|null
	 */
	public static function get_unit( $unit_id ) {
		global $wpdb;

		$unit_id = absint( $unit_id );
		if ( ! $unit_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::units_table() . ' WHERE id = %d', $unit_id )
		);
	}

	/**
	 * Lists units for a building.
	 *
	 * @param int $building_id Building ID.
	 * @return array<int,object>
	 */
	public static function get_units_by_building( $building_id ) {
		global $wpdb;

		$building_id = absint( $building_id );
		if ( ! $building_id ) {
			return array();
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::units_table() . ' WHERE building_id = %d AND status = %s ORDER BY unit_code ASC',
				$building_id,
				'active'
			)
		);
	}

	/**
	 * Resolves the unit linked to an accommodation post, when one exists.
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return object|null
	 */
	public static function get_unit_by_accommodation( $accommodation_id ) {
		global $wpdb;

		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::units_table() . ' WHERE accommodation_id = %d LIMIT 1',
				$accommodation_id
			)
		);
	}

	/**
	 * Returns the sum of HOA coefficients for a building. Should total 100
	 * for a correctly configured building; used to surface misconfiguration.
	 *
	 * @param int $building_id Building ID.
	 * @return float
	 */
	public static function get_coefficient_total( $building_id ) {
		global $wpdb;

		$building_id = absint( $building_id );
		if ( ! $building_id ) {
			return 0.0;
		}

		return (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(hoa_coefficient), 0) FROM ' . self::units_table() . ' WHERE building_id = %d AND status = %s',
				$building_id,
				'active'
			)
		);
	}

	/**
	 * Calculates the HOA amount owed by a unit for one period.
	 *
	 * @param int $unit_id Unit ID.
	 * @return float
	 */
	public static function calculate_unit_hoa( $unit_id ) {
		$unit = self::get_unit( $unit_id );
		if ( ! $unit ) {
			return 0.0;
		}

		$building = self::get_building( (int) $unit->building_id );
		if ( ! $building ) {
			return 0.0;
		}

		$coefficient = (float) $unit->hoa_coefficient;
		$total       = (float) $building->monthly_hoa_total;

		return round( $total * ( $coefficient / 100 ), 2 );
	}

	/**
	 * AJAX: creates a building.
	 *
	 * @return void
	 */
	public function ajax_create_building() {
		check_ajax_referer( 'af_structure_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$can_manage_all = Arriendo_Facil_Tenancy::can_manage_all();
		$owner_id       = get_current_user_id();

		// Only a super admin may assign a building to a different property admin.
		if ( $can_manage_all && ! empty( $_POST['assigned_admin_id'] ) ) {
			$owner_id = absint( wp_unslash( $_POST['assigned_admin_id'] ) );
		}

		$result = self::create_building(
			array(
				'name'              => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'address'           => isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '',
				'city'              => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
				'monthly_hoa_total' => isset( $_POST['monthly_hoa_total'] ) ? (float) wp_unslash( $_POST['monthly_hoa_total'] ) : 0,
				'owner_id'          => $owner_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Edificio creado correctamente.', 'arriendo-facil' ),
				'building_id' => $result,
			)
		);
	}

	/**
	 * AJAX: creates a unit.
	 *
	 * @return void
	 */
	public function ajax_create_unit() {
		check_ajax_referer( 'af_structure_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$building_id = isset( $_POST['building_id'] ) ? absint( wp_unslash( $_POST['building_id'] ) ) : 0;

		if ( ! Arriendo_Facil_Tenancy::can_access_building( $building_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este edificio.', 'arriendo-facil' ) ), 403 );
		}

		$result = self::create_unit(
			array(
				'building_id'      => $building_id,
				'unit_code'        => isset( $_POST['unit_code'] ) ? sanitize_text_field( wp_unslash( $_POST['unit_code'] ) ) : '',
				'hoa_coefficient'  => isset( $_POST['hoa_coefficient'] ) ? (float) wp_unslash( $_POST['hoa_coefficient'] ) : 0,
				'area_m2'          => isset( $_POST['area_m2'] ) ? (float) wp_unslash( $_POST['area_m2'] ) : 0,
				'accommodation_id' => isset( $_POST['accommodation_id'] ) ? absint( wp_unslash( $_POST['accommodation_id'] ) ) : 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Unidad creada correctamente.', 'arriendo-facil' ),
				'unit_id' => $result,
			)
		);
	}
}
