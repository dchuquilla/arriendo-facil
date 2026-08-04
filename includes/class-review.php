<?php
/**
 * Review management foundation.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Review
 *
 * Centralizes review directions, statuses, and timing rules.
 */
class Arriendo_Facil_Review {
	/**
	 * Hooks into lease lifecycle events.
	 */
	public function __construct() {
		add_action( 'af_lease_activated', array( $this, 'initialize_reviews_for_lease' ), 20, 1 );
	}

	/**
	 * Returns the review groups table name.
	 *
	 * @return string
	 */
	public static function groups_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_review_groups';
	}

	/**
	 * Returns the reviews table name.
	 *
	 * @return string
	 */
	public static function reviews_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_reviews';
	}

	/**
	 * Returns the review tokens table name.
	 *
	 * @return string
	 */
	public static function tokens_table() {
		global $wpdb;

		return $wpdb->prefix . 'af_review_tokens';
	}
	/**
	 * Returns the supported review directions.
	 *
	 * @return array<string,string>
	 */
	public static function review_directions() {
		return array(
			'tenant_to_owner'     => __( 'Inquilino a propietario', 'arriendo-facil' ),
			'tenant_to_property'  => __( 'Inquilino a propiedad', 'arriendo-facil' ),
			'owner_to_tenant'     => __( 'Propietario a inquilino', 'arriendo-facil' ),
		);
	}

	/**
	 * Returns the supported review group types.
	 *
	 * @return array<string,string>
	 */
	public static function reviewer_types() {
		return array(
			'tenant' => __( 'Inquilino', 'arriendo-facil' ),
			'owner'  => __( 'Propietario', 'arriendo-facil' ),
		);
	}

	/**
	 * Returns the supported review statuses.
	 *
	 * @return array<string,string>
	 */
	public static function review_statuses() {
		return array(
			'pending'   => __( 'Pendiente', 'arriendo-facil' ),
			'sent'      => __( 'Enviada', 'arriendo-facil' ),
			'completed' => __( 'Completada', 'arriendo-facil' ),
			'expired'   => __( 'Expirada', 'arriendo-facil' ),
			'blocked'   => __( 'Bloqueada', 'arriendo-facil' ),
		);
	}

	/**
	 * Returns the supported token statuses.
	 *
	 * @return array<string,string>
	 */
	public static function token_statuses() {
		return array(
			'active'  => __( 'Activo', 'arriendo-facil' ),
			'used'    => __( 'Usado', 'arriendo-facil' ),
			'expired' => __( 'Expirado', 'arriendo-facil' ),
			'blocked' => __( 'Bloqueado', 'arriendo-facil' ),
			'revoked' => __( 'Revocado', 'arriendo-facil' ),
		);
	}

	/**
	 * Returns the positive rating threshold.
	 *
	 * @return float
	 */
	public static function positive_threshold() {
		return 4.0;
	}

	/**
	 * Returns the maximum token attempts.
	 *
	 * @return int
	 */
	public static function default_token_attempts() {
		return 5;
	}

	/**
	 * Returns the review availability window after lease end.
	 *
	 * @return int
	 */
	public static function review_window_days() {
		return 15;
	}

	/**
	 * Returns the token lifetime in days.
	 *
	 * @return int
	 */
	public static function token_lifetime_days() {
		return 7;
	}

	/**
	 * Creates a review group row for a lease and reviewer type.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param int    $accommodation_id Accommodation ID.
	 * @param int    $owner_user_id Owner user ID.
	 * @param string $tenant_email Tenant email.
	 * @param string $reviewer_type Reviewer type (tenant or owner).
	 * @param string $due_at Optional due date in Y-m-d H:i:s.
	 * @return int|WP_Error
	 */
	public static function create_review_group( $lease_id, $accommodation_id, $owner_user_id, $tenant_email, $reviewer_type, $due_at = '' ) {
		$lease_id         = absint( $lease_id );
		$accommodation_id = absint( $accommodation_id );
		$owner_user_id    = absint( $owner_user_id );
		$tenant_email     = sanitize_email( (string) $tenant_email );
		$reviewer_type    = sanitize_key( (string) $reviewer_type );
		$due_at           = sanitize_text_field( (string) $due_at );

		if ( ! $lease_id || ! $accommodation_id || ! $owner_user_id || ! is_email( $tenant_email ) || ! array_key_exists( $reviewer_type, self::reviewer_types() ) ) {
			return new WP_Error( 'af_review_group_invalid_input', __( 'Datos insuficientes para crear el grupo de reseña.', 'arriendo-facil' ) );
		}

		global $wpdb;
		$existing_group_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::groups_table() . " WHERE lease_id = %d AND reviewer_type = %s LIMIT 1",
				$lease_id,
				$reviewer_type
			)
		);
		if ( $existing_group_id ) {
			return (int) $existing_group_id;
		}

		$inserted = $wpdb->insert(
			self::groups_table(),
			array(
				'lease_id'         => $lease_id,
				'accommodation_id' => $accommodation_id,
				'owner_user_id'    => $owner_user_id,
				'tenant_email'     => $tenant_email,
				'reviewer_type'    => $reviewer_type,
				'status'           => 'pending',
				'due_at'           => '' !== $due_at ? $due_at : null,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_review_group_insert_failed', __( 'No se pudo crear el grupo de reseña.', 'arriendo-facil' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Creates a token row for a review group.
	 *
	 * @param int $review_group_id Review group ID.
	 * @param int $max_attempts Maximum token attempts.
	 * @return array|WP_Error
	 */
	public static function create_review_token( $review_group_id, $max_attempts = 5 ) {
		$review_group_id = absint( $review_group_id );
		$max_attempts    = max( 1, absint( $max_attempts ) );

		if ( ! $review_group_id ) {
			return new WP_Error( 'af_review_token_invalid_group', __( 'No se pudo crear el token de reseña.', 'arriendo-facil' ) );
		}

		$selector = wp_generate_password( 18, false, false ) . dechex( random_int( 1000, 65535 ) );
		$token    = wp_generate_password( 48, false, false ) . dechex( random_int( 4096, 65535 ) );
		$hash     = password_hash( $token, PASSWORD_DEFAULT );

		if ( ! is_string( $hash ) || '' === $hash ) {
			return new WP_Error( 'af_review_token_hash_failed', __( 'No se pudo proteger el token de reseña.', 'arriendo-facil' ) );
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( '+' . self::token_lifetime_days() . ' days' ) );

		global $wpdb;
		$inserted = $wpdb->insert(
			self::tokens_table(),
			array(
				'review_group_id' => $review_group_id,
				'selector'        => sanitize_text_field( (string) $selector ),
				'token_hash'      => $hash,
				'expires_at'      => $expires_at,
				'attempts'        => 0,
				'max_attempts'    => $max_attempts,
				'status'          => 'active',
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_review_token_insert_failed', __( 'No se pudo guardar el token de reseña.', 'arriendo-facil' ) );
		}

		return array(
			'token_id'    => (int) $wpdb->insert_id,
			'selector'    => (string) $selector,
			'token'       => (string) $token,
			'expires_at'  => (string) $expires_at,
		);
	}

	/**
	 * Resolves a review token by selector and plaintext token.
	 *
	 * @param string $selector Selector component.
	 * @param string $token Plain token component.
	 * @param bool   $increment_attempts Whether to increment attempts.
	 * @return array|WP_Error
	 */
	public static function resolve_review_token( $selector, $token, $increment_attempts = false ) {
		$selector = sanitize_text_field( (string) $selector );
		$token    = sanitize_text_field( (string) $token );

		if ( '' === $selector || '' === $token ) {
			return new WP_Error( 'af_review_token_missing', __( 'Token de reseña incompleto.', 'arriendo-facil' ) );
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::tokens_table() . " WHERE selector = %s LIMIT 1",
				$selector
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'af_review_token_not_found', __( 'El enlace de reseña no es valido.', 'arriendo-facil' ) );
		}

		$status = isset( $row->status ) ? sanitize_key( (string) $row->status ) : '';
		if ( 'active' !== $status ) {
			return new WP_Error( 'af_review_token_inactive', __( 'Este enlace de reseña ya no esta activo.', 'arriendo-facil' ) );
		}

		$expires_at = isset( $row->expires_at ) ? strtotime( (string) $row->expires_at ) : false;
		if ( false === $expires_at || $expires_at < time() ) {
			$wpdb->update(
				self::tokens_table(),
				array( 'status' => 'expired' ),
				array( 'id' => absint( $row->id ) ),
				array( '%s' ),
				array( '%d' )
			);

			return new WP_Error( 'af_review_token_expired', __( 'Este enlace de reseña ya expiró. Solicita uno nuevo.', 'arriendo-facil' ) );
		}

		$max_attempts = isset( $row->max_attempts ) ? absint( $row->max_attempts ) : self::default_token_attempts();
		$attempts     = isset( $row->attempts ) ? absint( $row->attempts ) : 0;

		if ( $attempts >= $max_attempts ) {
			$wpdb->update(
				self::tokens_table(),
				array( 'status' => 'blocked' ),
				array( 'id' => absint( $row->id ) ),
				array( '%s' ),
				array( '%d' )
			);

			return new WP_Error( 'af_review_token_blocked', __( 'Enlace de reseña bloqueado por demasiados intentos.', 'arriendo-facil' ) );
		}

		$verified = password_verify( $token, (string) $row->token_hash );

		if ( $increment_attempts ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE " . self::tokens_table() . " SET attempts = attempts + 1 WHERE id = %d",
					absint( $row->id )
				)
			);
		}

		if ( ! $verified ) {
			return new WP_Error( 'af_review_token_invalid', __( 'Token de reseña invalido.', 'arriendo-facil' ) );
		}

		return array(
			'token_id'        => absint( $row->id ),
			'review_group_id' => absint( $row->review_group_id ),
			'expires_at'      => sanitize_text_field( (string) $row->expires_at ),
		);
	}

	/**
	 * Marks a review token as consumed.
	 *
	 * @param int $token_id Token row ID.
	 * @return void
	 */
	public static function consume_review_token( $token_id ) {
		$token_id = absint( $token_id );
		if ( ! $token_id ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			self::tokens_table(),
			array(
				'status'  => 'used',
				'used_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $token_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Initializes pending reviews for a lease right after activation.
	 *
	 * @param int $lease_id Lease ID.
	 * @return array|WP_Error
	 */
	public function initialize_reviews_for_lease( $lease_id ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return new WP_Error( 'af_review_lease_invalid', __( 'ID de contrato invalido para inicializar reseñas.', 'arriendo-facil' ) );
		}

		$context = $this->resolve_lease_review_context( $lease_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$end_date_ts = strtotime( (string) $context['lease']->end_date );
		if ( false === $end_date_ts ) {
			return new WP_Error( 'af_review_lease_end_invalid', __( 'No se pudo leer la fecha de fin del contrato.', 'arriendo-facil' ) );
		}

		$due_at = gmdate( 'Y-m-d H:i:s', strtotime( '+' . self::review_window_days() . ' days', $end_date_ts ) );
		$tenant_group_id = self::create_review_group(
			$lease_id,
			$context['accommodation_id'],
			$context['owner_user_id'],
			$context['tenant_email'],
			'tenant',
			$due_at
		);
		if ( is_wp_error( $tenant_group_id ) ) {
			return $tenant_group_id;
		}

		$owner_group_id = self::create_review_group(
			$lease_id,
			$context['accommodation_id'],
			$context['owner_user_id'],
			$context['tenant_email'],
			'owner',
			$due_at
		);
		if ( is_wp_error( $owner_group_id ) ) {
			return $owner_group_id;
		}

		$created = array(
			'tenant_group_id' => (int) $tenant_group_id,
			'owner_group_id'  => (int) $owner_group_id,
		);

		$tenant_review_ids = $this->create_reviews_for_group(
			(int) $tenant_group_id,
			$lease_id,
			$context['accommodation_id'],
			$context['owner_user_id'],
			$context['tenant_email'],
			array( 'tenant_to_owner', 'tenant_to_property' )
		);
		if ( is_wp_error( $tenant_review_ids ) ) {
			return $tenant_review_ids;
		}

		$owner_review_ids = $this->create_reviews_for_group(
			(int) $owner_group_id,
			$lease_id,
			$context['accommodation_id'],
			$context['owner_user_id'],
			$context['tenant_email'],
			array( 'owner_to_tenant' )
		);
		if ( is_wp_error( $owner_review_ids ) ) {
			return $owner_review_ids;
		}

		return $created;
	}

	/**
	 * Resolves the lease, owner, tenant, and accommodation context for review creation.
	 *
	 * @param int $lease_id Lease ID.
	 * @return array|WP_Error
	 */
	private function resolve_lease_review_context( $lease_id ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return new WP_Error( 'af_review_context_invalid', __( 'No se pudo resolver el contexto de reseña.', 'arriendo-facil' ) );
		}

		$lease = null;
		if ( class_exists( 'Arriendo_Facil_Lease' ) ) {
			$lease_service = new Arriendo_Facil_Lease();
			$lease         = $lease_service->get_lease( $lease_id );
		}

		if ( ! $lease ) {
			return new WP_Error( 'af_review_lease_missing', __( 'No se encontro el contrato para crear reseñas.', 'arriendo-facil' ) );
		}

		$accommodation_id = isset( $lease->accommodation_id ) ? absint( $lease->accommodation_id ) : 0;
		if ( ! $accommodation_id ) {
			return new WP_Error( 'af_review_accommodation_missing', __( 'El contrato no tiene una propiedad asociada.', 'arriendo-facil' ) );
		}

		$owner_user_id = absint( get_post_meta( $accommodation_id, '_af_owner_id', true ) );
		if ( ! $owner_user_id ) {
			return new WP_Error( 'af_review_owner_missing', __( 'La propiedad no tiene propietario asociado.', 'arriendo-facil' ) );
		}

		global $wpdb;
		$tenant_email = '';
		if ( isset( $lease->guest_id ) ) {
			$tenant_email = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT email FROM {$wpdb->prefix}af_guests WHERE id = %d LIMIT 1",
					absint( $lease->guest_id )
				)
			);
		}

		$tenant_email = sanitize_email( $tenant_email );
		if ( ! is_email( $tenant_email ) ) {
			return new WP_Error( 'af_review_tenant_missing', __( 'No se encontro el correo del inquilino para crear reseñas.', 'arriendo-facil' ) );
		}

		return array(
			'lease'           => $lease,
			'accommodation_id' => $accommodation_id,
			'owner_user_id'    => $owner_user_id,
			'tenant_email'     => $tenant_email,
		);
	}

	/**
	 * Creates the individual pending review rows for a group.
	 *
	 * @param int   $review_group_id Review group ID.
	 * @param int   $lease_id Lease ID.
	 * @param int   $accommodation_id Accommodation ID.
	 * @param int   $owner_user_id Owner user ID.
	 * @param string $tenant_email Tenant email.
	 * @param array  $directions Review directions to create.
	 * @return array|WP_Error
	 */
	private function create_reviews_for_group( $review_group_id, $lease_id, $accommodation_id, $owner_user_id, $tenant_email, array $directions ) {
		$review_group_id  = absint( $review_group_id );
		$lease_id         = absint( $lease_id );
		$accommodation_id = absint( $accommodation_id );
		$owner_user_id    = absint( $owner_user_id );
		$tenant_email     = sanitize_email( (string) $tenant_email );

		if ( ! $review_group_id || ! $lease_id || ! $accommodation_id || ! $owner_user_id || ! is_email( $tenant_email ) || empty( $directions ) ) {
			return new WP_Error( 'af_review_rows_invalid', __( 'Datos insuficientes para crear reseñas pendientes.', 'arriendo-facil' ) );
		}

		$created_ids = array();
		global $wpdb;

		foreach ( $directions as $direction ) {
			$direction = sanitize_key( (string) $direction );
			if ( ! array_key_exists( $direction, self::review_directions() ) ) {
				continue;
			}

			$existing_review_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM " . self::reviews_table() . " WHERE lease_id = %d AND review_direction = %s LIMIT 1",
					$lease_id,
					$direction
				)
			);
			if ( $existing_review_id ) {
				$created_ids[] = (int) $existing_review_id;
				continue;
			}

			$inserted = $wpdb->insert(
				self::reviews_table(),
				array(
					'review_group_id' => $review_group_id,
					'lease_id'        => $lease_id,
					'accommodation_id'=> $accommodation_id,
					'owner_user_id'   => $owner_user_id,
					'tenant_email'    => $tenant_email,
					'review_direction'=> $direction,
					'stars'           => 0,
					'status'          => 'pending',
				),
				array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
			);

			if ( ! $inserted ) {
				return new WP_Error( 'af_review_row_insert_failed', __( 'No se pudo crear una reseña pendiente.', 'arriendo-facil' ) );
			}

			$created_ids[] = (int) $wpdb->insert_id;
		}

		return $created_ids;
	}
}