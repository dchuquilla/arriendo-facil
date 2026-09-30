<?php
/**
 * Class Arriendo_Facil_Occupancy
 *
 * Fuente unica de verdad para la ocupacion de un inmueble. Mantiene
 * sincronizadas las cuatro representaciones que hoy viven separadas y
 * provocaban contradicciones entre secciones:
 *
 *   - _af_is_occupied          listado Inmuebles + toggle manual + catalogo.
 *   - _af_status               estado (available/rented/maintenance/inactive)
 *                              que leen el Panel, el listado y el catalogo.
 *   - _af_commercial_*         estado/visibilidad comercial del catalogo.
 *   - af_leases.status         contratos / cobranza.
 *
 * Toda transicion de ocupacion debe pasar por mark_occupied()/mark_available()
 * (o por sync_from_leases()/backfill_all() derivando del estado real) para que
 * las secciones vuelvan a mostrar el mismo estado.
 *
 * @package Arriendo_Facil
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Occupancy {

	const POST_TYPE     = 'accommodation';
	const META_OCCUPIED = '_af_is_occupied';
	const META_STATUS   = '_af_status';

	/**
	 * Estados de contrato que mantienen el inmueble ocupado.
	 *
	 * El borrador cuenta como ocupado porque es la materializacion de
	 * "vincular inquilino -> inmueble": mientras hay un contrato en gestion
	 * (o vigente, o a la espera de liberacion) el inmueble no esta disponible.
	 */
	const LEASE_OCCUPIED_STATUSES = array( 'draft', 'active', 'pending_release' );

	/**
	 * Marca el inmueble como ocupado y sincroniza las metas de ocupacion,
	 * estado y comercializacion. Conserva estados operativos manuales
	 * (maintenance/inactive) sin pisarlos.
	 *
	 * @param int $accommodation_id Post ID del inmueble.
	 * @return void
	 */
	public static function mark_occupied( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! self::is_accommodation( $accommodation_id ) ) {
			return;
		}

		$current_status = (string) get_post_meta( $accommodation_id, self::META_STATUS, true );

		update_post_meta( $accommodation_id, '_af_commercial_state', 'rented' );
		update_post_meta( $accommodation_id, '_af_commercial_visibility', 'private' );
		update_post_meta( $accommodation_id, '_af_commercial_status', 'private' );

		if ( ! in_array( $current_status, array( 'maintenance', 'inactive' ), true ) ) {
			update_post_meta( $accommodation_id, self::META_STATUS, 'rented' );
		}

		update_post_meta( $accommodation_id, self::META_OCCUPIED, '1' );
		self::purge_caches();
	}

	/**
	 * Libera el inmueble y sincroniza las metas de ocupacion, estado y
	 * comercializacion a disponibles.
	 *
	 * @param int $accommodation_id Post ID del inmueble.
	 * @return void
	 */
	public static function mark_available( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! self::is_accommodation( $accommodation_id ) ) {
			return;
		}

		update_post_meta( $accommodation_id, '_af_commercial_state', 'available' );
		update_post_meta( $accommodation_id, '_af_commercial_visibility', 'public' );
		update_post_meta( $accommodation_id, '_af_commercial_status', 'available' );
		update_post_meta( $accommodation_id, self::META_STATUS, 'available' );
		delete_post_meta( $accommodation_id, self::META_OCCUPIED );

		self::purge_caches();
	}

	/**
	 * Aplica un estado comercial (disponible / reservado / arrendado) y su
	 * visibilidad, sincronizando la ocupacion que corresponde.
	 *
	 * Es la via para el flujo de reservas, que no es lo mismo que un contrato:
	 * un inmueble reservado sigue siendo visible en el catalogo (visibilidad
	 * publica) pero no esta disponible para arrendar.
	 *
	 * @param int    $accommodation_id Post ID del inmueble.
	 * @param string $state            available|reserved|rented.
	 * @param string $visibility       public|private.
	 * @return void
	 */
	public static function apply_commercial_state( $accommodation_id, $state, $visibility ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! self::is_accommodation( $accommodation_id ) ) {
			return;
		}

		$allowed_states     = array( 'available', 'reserved', 'rented' );
		$allowed_visibility = array( 'public', 'private' );
		$state              = in_array( $state, $allowed_states, true ) ? $state : 'available';
		$visibility         = in_array( $visibility, $allowed_visibility, true ) ? $visibility : 'public';

		update_post_meta( $accommodation_id, '_af_commercial_state', $state );
		update_post_meta( $accommodation_id, '_af_commercial_visibility', $visibility );
		update_post_meta( $accommodation_id, '_af_commercial_status', 'private' === $visibility ? 'private' : $state );

		if ( 'rented' === $state ) {
			self::mark_occupied( $accommodation_id );
			return;
		}

		if ( 'reserved' === $state ) {
			// Reservado no es un estado de _af_status: se marca ocupado y se deja
			// el estado operativo como estaba para no mentir con un "arrendado".
			self::set_occupied_flag( $accommodation_id, true );
			return;
		}

		if ( 'public' === $visibility ) {
			self::mark_available( $accommodation_id );
		}
	}

	/**
	 * Marca o limpia solo la marca de ocupacion, sin tocar estado ni
	 * comercializacion. Para estados operativos que no encajan en available/
	 * rented (por ejemplo una reserva).
	 *
	 * @param int  $accommodation_id Post ID del inmueble.
	 * @param bool $occupied         true marca ocupado, false lo libera.
	 * @return void
	 */
	public static function set_occupied_flag( $accommodation_id, $occupied = true ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! self::is_accommodation( $accommodation_id ) ) {
			return;
		}

		if ( $occupied ) {
			update_post_meta( $accommodation_id, self::META_OCCUPIED, '1' );
		} else {
			delete_post_meta( $accommodation_id, self::META_OCCUPIED );
		}

		self::purge_caches();
	}

	/**
	 * Indica si el inmueble esta marcado como ocupado.
	 *
	 * @param int $accommodation_id Post ID del inmueble.
	 * @return bool
	 */
	public static function is_occupied( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id ) {
			return false;
		}

		return (bool) get_post_meta( $accommodation_id, self::META_OCCUPIED, true );
	}

	/**
	 * Deriva la ocupacion desde los contratos reales y la persiste de forma
	 * consistente. Salta inmuebles en mantenimiento/inactivo, cuya ocupacion
	 * es una decision operativa manual.
	 *
	 * @param int $accommodation_id Post ID del inmueble.
	 * @return void
	 */
	public static function sync_from_leases( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! self::is_accommodation( $accommodation_id ) ) {
			return;
		}

		$current_status = (string) get_post_meta( $accommodation_id, self::META_STATUS, true );
		if ( in_array( $current_status, array( 'maintenance', 'inactive' ), true ) ) {
			return;
		}

		if ( 0 !== self::count_occupying_leases( $accommodation_id ) ) {
			self::mark_occupied( $accommodation_id );
		} else {
			self::mark_available( $accommodation_id );
		}
	}

	/**
	 * Normaliza la ocupacion de todos los inmuebles en base a contratos y
	 * marcas manuales previas. Idempotente: disenada para migracion legacy y
	 * para que un deploy no dependa del orden en que se ejecuto cada codigo.
	 *
	 * Reglas:
	 *   1. Contrato en gestion/vigente -> ocupado.
	 *   2. Marcado manual como ocupado o estado 'rented' sin contrato ->
	 *      se conserva ocupado (decision del operador).
	 *   3. Sin contrato y disponible -> disponible (limpia marcas huerfanas).
	 *
	 * @return int Cantidad de inmuebles normalizados.
	 */
	public static function backfill_all() {
		$post_ids = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		if ( ! is_array( $post_ids ) || empty( $post_ids ) ) {
			return 0;
		}

		$updated = 0;
		foreach ( $post_ids as $post_id ) {
			$current_status = (string) get_post_meta( (int) $post_id, self::META_STATUS, true );
			if ( in_array( $current_status, array( 'maintenance', 'inactive' ), true ) ) {
				continue;
			}

			$has_lease   = 0 !== self::count_occupying_leases( (int) $post_id );
			$is_occupied = (bool) get_post_meta( (int) $post_id, self::META_OCCUPIED, true );

			if ( $has_lease || $is_occupied || 'rented' === $current_status ) {
				self::mark_occupied( (int) $post_id );
			} else {
				self::mark_available( (int) $post_id );
			}

			++$updated;
		}

		return $updated;
	}

	/**
	 * Cuenta contratos no borrados que mantienen el inmueble ocupado.
	 *
	 * @param int $accommodation_id Post ID del inmueble.
	 * @return int
	 */
	private static function count_occupying_leases( $accommodation_id ) {
		global $wpdb;

		$list = "'" . implode( "','", array_map( 'sanitize_key', self::LEASE_OCCUPIED_STATUSES ) ) . "'";

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}af_leases
				 WHERE accommodation_id = %d
				   AND deleted_at IS NULL
				   AND status IN ({$list})",
				$accommodation_id
			)
		);
	}

	/**
	 * Valida que el ID corresponda a un post de tipo accommodation.
	 *
	 * @param int $accommodation_id Post ID del inmueble.
	 * @return bool
	 */
	private static function is_accommodation( $accommodation_id ) {
		if ( ! $accommodation_id ) {
			return false;
		}

		return self::POST_TYPE === get_post_type( $accommodation_id );
	}

	/**
	 * Invalida los transients que dependen del filtrado por ocupacion.
	 *
	 * @return void
	 */
	private static function purge_caches() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_af_search_results_%',
				'_transient_af_featured_accommodations_%'
			)
		);
	}
}