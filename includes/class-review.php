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
		add_action( 'af_review_dispatch_cron', array( $this, 'dispatch_pending_reviews' ) );
		add_action( 'wp_ajax_af_validate_review_token', array( $this, 'ajax_validate_review_token' ) );
		add_action( 'wp_ajax_nopriv_af_validate_review_token', array( $this, 'ajax_validate_review_token' ) );
		add_action( 'wp_ajax_af_submit_review_by_token', array( $this, 'ajax_submit_review_by_token' ) );
		add_action( 'wp_ajax_nopriv_af_submit_review_by_token', array( $this, 'ajax_submit_review_by_token' ) );
		add_action( 'wp_ajax_af_request_new_review_link', array( $this, 'ajax_request_new_review_link' ) );
		add_action( 'wp_ajax_nopriv_af_request_new_review_link', array( $this, 'ajax_request_new_review_link' ) );
		add_action( 'wp_ajax_af_generate_review_test_link', array( $this, 'ajax_generate_review_test_link' ) );
		add_shortcode( 'af_review_form', array( $this, 'render_review_form_shortcode' ) );
		add_shortcode( 'af_review_stats', array( $this, 'render_review_stats_shortcode' ) );
		add_filter( 'the_content', array( $this, 'append_public_stats_to_single_accommodation' ), 30 );
		add_filter( 'elementor/frontend/the_content', array( $this, 'append_public_stats_to_single_accommodation' ), 30 );
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
	 * Cron worker that sends review links for eligible lease groups.
	 *
	 * @return int Number of groups processed.
	 */
	public function dispatch_pending_reviews() {
		global $wpdb;

		$today    = current_time( 'Y-m-d' );
		$min_date = gmdate( 'Y-m-d', strtotime( '-' . self::review_window_days() . ' days', strtotime( $today . ' 00:00:00' ) ) );

		$groups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.*
				 FROM " . self::groups_table() . " g
				 INNER JOIN {$wpdb->prefix}af_leases l ON l.id = g.lease_id
				 WHERE g.status = %s
				   AND l.end_date <= %s
				   AND l.end_date >= %s
				 ORDER BY g.id ASC
				 LIMIT 100",
				'pending',
				$today,
				$min_date
			)
		);

		if ( empty( $groups ) ) {
			return 0;
		}

		$processed = 0;
		foreach ( $groups as $group ) {
			$processed += $this->dispatch_single_group( $group ) ? 1 : 0;
		}

		return $processed;
	}

	/**
	 * AJAX: validates a public review token and returns pending directions.
	 *
	 * @return void
	 */
	public function ajax_validate_review_token() {
		$selector = isset( $_REQUEST['selector'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['selector'] ) ) : '';
		$token    = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';

		$resolved = self::resolve_review_token( $selector, $token, false );
		if ( is_wp_error( $resolved ) ) {
			wp_send_json_error(
				array(
					'message' => $resolved->get_error_message(),
					'code'    => $resolved->get_error_code(),
				),
				400
			);
		}

		$group = $this->get_group_by_id( isset( $resolved['review_group_id'] ) ? absint( $resolved['review_group_id'] ) : 0 );
		if ( ! $group ) {
			wp_send_json_error( array( 'message' => __( 'No se encontro el grupo de reseña asociado al enlace.', 'arriendo-facil' ) ), 404 );
		}

		$pending = $this->get_pending_reviews_for_group( (int) $group->id );
		if ( empty( $pending ) ) {
			wp_send_json_error( array( 'message' => __( 'No hay reseñas pendientes para este enlace.', 'arriendo-facil' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'group_id'       => (int) $group->id,
				'reviewer_type'  => sanitize_key( (string) $group->reviewer_type ),
				'accommodation_id'=> (int) $group->accommodation_id,
				'lease_id'        => (int) $group->lease_id,
				'expires_at'      => isset( $resolved['expires_at'] ) ? sanitize_text_field( (string) $resolved['expires_at'] ) : '',
				'directions'      => $pending,
			)
		);
	}

	/**
	 * AJAX: submits star reviews for a valid token and consumes it.
	 *
	 * @return void
	 */
	public function ajax_submit_review_by_token() {
		$selector = isset( $_POST['selector'] ) ? sanitize_text_field( wp_unslash( $_POST['selector'] ) ) : '';
		$token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		$resolved = self::resolve_review_token( $selector, $token, true );
		if ( is_wp_error( $resolved ) ) {
			wp_send_json_error(
				array(
					'message' => $resolved->get_error_message(),
					'code'    => $resolved->get_error_code(),
				),
				400
			);
		}

		$group_id = isset( $resolved['review_group_id'] ) ? absint( $resolved['review_group_id'] ) : 0;
		$group    = $this->get_group_by_id( $group_id );
		if ( ! $group ) {
			wp_send_json_error( array( 'message' => __( 'No se encontro el grupo de reseña asociado al token.', 'arriendo-facil' ) ), 404 );
		}

		$pending_reviews = $this->get_pending_reviews_for_group( $group_id );
		if ( empty( $pending_reviews ) ) {
			wp_send_json_error( array( 'message' => __( 'No hay reseñas pendientes para enviar en este enlace.', 'arriendo-facil' ) ), 400 );
		}

		$ratings_payload = isset( $_POST['ratings'] ) ? wp_unslash( $_POST['ratings'] ) : '';
		$ratings         = $this->parse_ratings_payload( $ratings_payload );
		if ( empty( $ratings ) ) {
			wp_send_json_error( array( 'message' => __( 'No se recibieron calificaciones válidas.', 'arriendo-facil' ) ), 400 );
		}

		foreach ( $pending_reviews as $review_row ) {
			$direction = sanitize_key( (string) $review_row['direction'] );
			if ( ! isset( $ratings[ $direction ] ) ) {
				wp_send_json_error( array( 'message' => sprintf( __( 'Falta la calificación para: %s', 'arriendo-facil' ), $direction ) ), 400 );
			}

			$stars = absint( $ratings[ $direction ] );
			if ( $stars < 1 || $stars > 5 ) {
				wp_send_json_error( array( 'message' => __( 'Cada calificación debe estar entre 1 y 5 estrellas.', 'arriendo-facil' ) ), 400 );
			}
		}

		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $pending_reviews as $review_row ) {
			$direction = sanitize_key( (string) $review_row['direction'] );
			$stars     = absint( $ratings[ $direction ] );

			$wpdb->update(
				self::reviews_table(),
				array(
					'stars'        => $stars,
					'status'       => 'completed',
					'submitted_at' => $now,
				),
				array( 'id' => absint( $review_row['id'] ) ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
		}

		$wpdb->update(
			self::groups_table(),
			array(
				'status'       => 'completed',
				'completed_at' => $now,
			),
			array( 'id' => $group_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		self::consume_review_token( isset( $resolved['token_id'] ) ? absint( $resolved['token_id'] ) : 0 );

		wp_send_json_success(
			array(
				'message' => __( 'Gracias. Tu calificación fue registrada correctamente.', 'arriendo-facil' ),
				'group_id' => $group_id,
			)
		);
	}

	/**
	 * AJAX: issues and sends a fresh token for an existing review group.
	 *
	 * @return void
	 */
	public function ajax_request_new_review_link() {
		$selector = isset( $_POST['selector'] ) ? sanitize_text_field( wp_unslash( $_POST['selector'] ) ) : '';
		if ( '' === $selector ) {
			wp_send_json_error( array( 'message' => __( 'Debes indicar el selector del enlace anterior.', 'arriendo-facil' ) ), 400 );
		}

		$token_row = $this->get_token_by_selector( $selector );
		if ( ! $token_row ) {
			wp_send_json_error( array( 'message' => __( 'No se encontro el enlace para solicitar uno nuevo.', 'arriendo-facil' ) ), 404 );
		}

		$group = $this->get_group_by_id( isset( $token_row->review_group_id ) ? absint( $token_row->review_group_id ) : 0 );
		if ( ! $group ) {
			wp_send_json_error( array( 'message' => __( 'No se encontro el grupo de reseña para reenviar el enlace.', 'arriendo-facil' ) ), 404 );
		}

		$group_status = isset( $group->status ) ? sanitize_key( (string) $group->status ) : '';
		if ( 'completed' === $group_status ) {
			wp_send_json_error( array( 'message' => __( 'Este ciclo de reseñas ya se completó y no admite nuevo enlace.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! empty( $group->due_at ) && strtotime( (string) $group->due_at ) < time() ) {
			wp_send_json_error( array( 'message' => __( 'La ventana de reseña ya venció para este contrato.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! empty( $group->sent_at ) && strtotime( (string) $group->sent_at ) > ( time() - 600 ) ) {
			wp_send_json_error( array( 'message' => __( 'Espera unos minutos antes de solicitar otro enlace.', 'arriendo-facil' ) ), 429 );
		}

		$sent = $this->dispatch_single_group( $group );
		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo generar y enviar el nuevo enlace de reseña.', 'arriendo-facil' ) ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'Se envio un nuevo enlace de reseña al correo registrado.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: generates a valid review link for testing (admin/owner only).
	 *
	 * Expects:
	 * - nonce: af_lease_nonce
	 * - lease_id: lease ID
	 * - reviewer_type: tenant|owner (optional, default tenant)
	 *
	 * @return void
	 */
	public function ajax_generate_review_test_link() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id      = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		$reviewer_type = isset( $_POST['reviewer_type'] ) ? sanitize_key( wp_unslash( $_POST['reviewer_type'] ) ) : 'tenant';

		if ( ! $lease_id ) {
			wp_send_json_error( array( 'message' => __( 'Debes enviar un lease_id valido.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! array_key_exists( $reviewer_type, self::reviewer_types() ) ) {
			wp_send_json_error( array( 'message' => __( 'reviewer_type invalido. Usa tenant u owner.', 'arriendo-facil' ) ), 400 );
		}

		$context = $this->resolve_lease_review_context( $lease_id );
		if ( is_wp_error( $context ) ) {
			wp_send_json_error( array( 'message' => $context->get_error_message() ), 400 );
		}

		$group_id = $this->find_group_id_by_lease_and_reviewer( $lease_id, $reviewer_type );
		if ( ! $group_id ) {
			$due_at = gmdate( 'Y-m-d H:i:s', strtotime( '+' . self::review_window_days() . ' days' ) );
			$group_id = self::create_review_group(
				$lease_id,
				$context['accommodation_id'],
				$context['owner_user_id'],
				$context['tenant_email'],
				$reviewer_type,
				$due_at
			);
			if ( is_wp_error( $group_id ) ) {
				wp_send_json_error( array( 'message' => $group_id->get_error_message() ), 500 );
			}

			$directions = 'tenant' === $reviewer_type
				? array( 'tenant_to_owner', 'tenant_to_property' )
				: array( 'owner_to_tenant' );

			$created = $this->create_reviews_for_group(
				(int) $group_id,
				$lease_id,
				$context['accommodation_id'],
				$context['owner_user_id'],
				$context['tenant_email'],
				$directions
			);
			if ( is_wp_error( $created ) ) {
				wp_send_json_error( array( 'message' => $created->get_error_message() ), 500 );
			}
		}

		$this->revoke_active_tokens_for_group( (int) $group_id );
		$token_data = self::create_review_token( (int) $group_id, self::default_token_attempts() );
		if ( is_wp_error( $token_data ) ) {
			wp_send_json_error( array( 'message' => $token_data->get_error_message() ), 500 );
		}

		$review_url = $this->build_review_link(
			isset( $token_data['selector'] ) ? (string) $token_data['selector'] : '',
			isset( $token_data['token'] ) ? (string) $token_data['token'] : ''
		);

		if ( '' === $review_url ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo construir la URL de prueba.', 'arriendo-facil' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'lease_id'      => $lease_id,
				'reviewer_type' => $reviewer_type,
				'group_id'      => (int) $group_id,
				'review_url'    => $review_url,
				'expires_at'    => isset( $token_data['expires_at'] ) ? (string) $token_data['expires_at'] : '',
			)
		);
	}

	/**
	 * Renders the public token-based review form.
	 *
	 * Usage: [af_review_form]
	 *
	 * @return string
	 */
	public function render_review_form_shortcode() {
		$ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
		$title    = esc_html__( 'Calificar estancia', 'arriendo-facil' );

		ob_start();
		?>
		<div id="af-review-form-app" style="max-width:760px;margin:20px auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;">
			<h2 style="margin:0 0 14px;font-size:26px;line-height:1.2;color:#0f172a;"><?php echo $title; ?></h2>
			<p style="margin:0 0 14px;color:#475569;line-height:1.6;"><?php echo esc_html__( 'Este enlace es seguro y de un solo uso. Completa las calificaciones pendientes para finalizar.', 'arriendo-facil' ); ?></p>

			<div id="af-review-alert" style="display:none;padding:10px 12px;border-radius:8px;margin-bottom:14px;"></div>
			<div id="af-review-loading" style="color:#334155;"><?php echo esc_html__( 'Validando enlace…', 'arriendo-facil' ); ?></div>

			<form id="af-review-form" style="display:none;">
				<div id="af-review-fields" style="display:grid;grid-template-columns:1fr;gap:14px;"></div>
				<button type="submit" id="af-review-submit" style="margin-top:16px;padding:11px 16px;border:0;border-radius:8px;background:#1d4ed8;color:#fff;font-weight:600;cursor:pointer;">
					<?php echo esc_html__( 'Enviar calificación', 'arriendo-facil' ); ?>
				</button>
			</form>

			<button type="button" id="af-review-new-link" style="display:none;margin-top:12px;padding:9px 14px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#0f172a;cursor:pointer;">
				<?php echo esc_html__( 'Solicitar nuevo enlace', 'arriendo-facil' ); ?>
			</button>
		</div>

		<script>
		(function(){
			const app = document.getElementById('af-review-form-app');
			if(!app){ return; }

			const ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			const alertBox = document.getElementById('af-review-alert');
			const loading = document.getElementById('af-review-loading');
			const form = document.getElementById('af-review-form');
			const fieldsWrap = document.getElementById('af-review-fields');
			const submitBtn = document.getElementById('af-review-submit');
			const newLinkBtn = document.getElementById('af-review-new-link');

			const labels = {
				tenant_to_owner: <?php echo wp_json_encode( __( 'Califica al propietario', 'arriendo-facil' ) ); ?>,
				tenant_to_property: <?php echo wp_json_encode( __( 'Califica la propiedad', 'arriendo-facil' ) ); ?>,
				owner_to_tenant: <?php echo wp_json_encode( __( 'Califica al inquilino', 'arriendo-facil' ) ); ?>
			};

			const params = new URLSearchParams(window.location.search);
			const selector = params.get('selector') || '';
			const token = params.get('token') || '';

			function showAlert(message, type){
				alertBox.style.display = 'block';
				alertBox.textContent = message || '';
				if(type === 'success'){
					alertBox.style.background = '#ecfdf5';
					alertBox.style.border = '1px solid #86efac';
					alertBox.style.color = '#166534';
				} else {
					alertBox.style.background = '#fef2f2';
					alertBox.style.border = '1px solid #fca5a5';
					alertBox.style.color = '#991b1b';
				}
			}

			function toFormBody(obj){
				const fd = new URLSearchParams();
				Object.keys(obj).forEach((key) => fd.append(key, obj[key]));
				return fd;
			}

			function renderDirectionFields(directions){
				fieldsWrap.innerHTML = '';
				directions.forEach((entry) => {
					const key = entry.direction;
					const label = labels[key] || key;
					const block = document.createElement('div');
					block.style.border = '1px solid #e2e8f0';
					block.style.borderRadius = '10px';
					block.style.padding = '12px';
					block.innerHTML = `
						<div style="font-weight:600;color:#0f172a;margin-bottom:8px;">${label}</div>
						<div style="display:flex;gap:10px;flex-wrap:wrap;">
							${[1,2,3,4,5].map((n) => `
								<label style="display:flex;align-items:center;gap:6px;color:#334155;">
									<input type="radio" name="rating_${key}" value="${n}" ${n===5?'checked':''}>
									<span>${n}★</span>
								</label>
							`).join('')}
						</div>
					`;
					fieldsWrap.appendChild(block);
				});
			}

			async function validateToken(){
				if(!selector || !token){
					loading.style.display = 'none';
					showAlert(<?php echo wp_json_encode( __( 'Enlace incompleto. Verifica que la URL tenga selector y token.', 'arriendo-facil' ) ); ?>, 'error');
					return;
				}

				const response = await fetch(ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: toFormBody({
						action: 'af_validate_review_token',
						selector,
						token
					})
				});

				const json = await response.json();
				loading.style.display = 'none';

				if(!json || !json.success){
					const message = json && json.data && json.data.message ? json.data.message : <?php echo wp_json_encode( __( 'No se pudo validar el enlace de reseña.', 'arriendo-facil' ) ); ?>;
					showAlert(message, 'error');
					newLinkBtn.style.display = 'inline-block';
					return;
				}

				renderDirectionFields((json.data && json.data.directions) ? json.data.directions : []);
				form.style.display = 'block';
			}

			form.addEventListener('submit', async function(e){
				e.preventDefault();
				submitBtn.disabled = true;

				const ratings = {};
				const radios = form.querySelectorAll('input[type="radio"]:checked');
				radios.forEach((r) => {
					const name = r.getAttribute('name') || '';
					const direction = name.replace('rating_', '');
					if(direction){ ratings[direction] = parseInt(r.value, 10); }
				});

				const response = await fetch(ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: toFormBody({
						action: 'af_submit_review_by_token',
						selector,
						token,
						ratings: JSON.stringify(ratings)
					})
				});

				const json = await response.json();
				submitBtn.disabled = false;

				if(!json || !json.success){
					const message = json && json.data && json.data.message ? json.data.message : <?php echo wp_json_encode( __( 'No se pudo enviar la calificación.', 'arriendo-facil' ) ); ?>;
					showAlert(message, 'error');
					if(message.toLowerCase().includes('expir') || message.toLowerCase().includes('bloque')){
						newLinkBtn.style.display = 'inline-block';
					}
					return;
				}

				showAlert((json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'Calificación enviada correctamente.', 'arriendo-facil' ) ); ?>, 'success');
				form.style.display = 'none';
				newLinkBtn.style.display = 'none';
			});

			newLinkBtn.addEventListener('click', async function(){
				newLinkBtn.disabled = true;
				const response = await fetch(ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: toFormBody({
						action: 'af_request_new_review_link',
						selector
					})
				});

				const json = await response.json();
				newLinkBtn.disabled = false;
				if(!json || !json.success){
					const message = json && json.data && json.data.message ? json.data.message : <?php echo wp_json_encode( __( 'No se pudo solicitar un nuevo enlace.', 'arriendo-facil' ) ); ?>;
					showAlert(message, 'error');
					return;
				}

				showAlert((json.data && json.data.message) ? json.data.message : <?php echo wp_json_encode( __( 'Se enviará un nuevo enlace si la reseña aún está habilitada.', 'arriendo-facil' ) ); ?>, 'success');
			});

			validateToken();
		})();
		</script>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders aggregate public review statistics for a property.
	 *
	 * Usage:
	 * - [af_review_stats] (uses current post ID)
	 * - [af_review_stats accommodation_id="123"]
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_review_stats_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'accommodation_id' => 0,
			),
			(array) $atts,
			'af_review_stats'
		);

		$accommodation_id = absint( $atts['accommodation_id'] );
		if ( ! $accommodation_id ) {
			$accommodation_id = get_the_ID() ? absint( get_the_ID() ) : 0;
		}

		if ( ! $accommodation_id ) {
			return '';
		}

		global $wpdb;
		$summary = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_reviews,
					AVG(stars) AS avg_stars,
					SUM(CASE WHEN stars >= %f THEN 1 ELSE 0 END) AS positive_reviews
				 FROM " . self::reviews_table() . "
				 WHERE accommodation_id = %d
				   AND review_direction = %s
				   AND status = %s",
				self::positive_threshold(),
				$accommodation_id,
				'tenant_to_property',
				'completed'
			)
		);

		$total_reviews    = isset( $summary->total_reviews ) ? (int) $summary->total_reviews : 0;
		$avg_stars        = isset( $summary->avg_stars ) ? (float) $summary->avg_stars : 0.0;
		$positive_reviews = isset( $summary->positive_reviews ) ? (int) $summary->positive_reviews : 0;
		$positive_rate    = $total_reviews > 0 ? ( $positive_reviews / $total_reviews ) * 100 : 0;

		if ( 0 === $total_reviews ) {
			return '<div class="af-review-stats" style="padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">' . esc_html__( 'Aún no hay valoraciones públicas para esta propiedad.', 'arriendo-facil' ) . '</div>';
		}

		ob_start();
		?>
		<div class="af-review-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;padding:12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
			<div>
				<div style="font-size:20px;font-weight:700;color:#0f172a;"><?php echo esc_html( number_format( $avg_stars, 2 ) ); ?></div>
				<div style="color:#64748b;font-size:12px;"><?php esc_html_e( 'Promedio', 'arriendo-facil' ); ?></div>
			</div>
			<div>
				<div style="font-size:20px;font-weight:700;color:#0f172a;"><?php echo esc_html( $total_reviews ); ?></div>
				<div style="color:#64748b;font-size:12px;"><?php esc_html_e( 'Reseñas', 'arriendo-facil' ); ?></div>
			</div>
			<div>
				<div style="font-size:20px;font-weight:700;color:#0f172a;"><?php echo esc_html( $positive_reviews ); ?></div>
				<div style="color:#64748b;font-size:12px;"><?php esc_html_e( 'Positivas', 'arriendo-facil' ); ?></div>
			</div>
			<div>
				<div style="font-size:20px;font-weight:700;color:#0f172a;"><?php echo esc_html( number_format( $positive_rate, 1 ) ); ?>%</div>
				<div style="color:#64748b;font-size:12px;"><?php esc_html_e( 'Tasa positiva', 'arriendo-facil' ); ?></div>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Appends aggregate review stats to singular accommodation content.
	 *
	 * @param string $content Current post content.
	 * @return string
	 */
	public function append_public_stats_to_single_accommodation( $content ) {
		if ( is_admin() || ! is_singular( 'accommodation' ) || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		$content = (string) $content;
		if ( false !== strpos( $content, 'af-review-stats' ) || false !== strpos( $content, '[af_review_stats' ) ) {
			return $content;
		}

		$stats_html = $this->render_review_stats_shortcode(
			array(
				'accommodation_id' => absint( get_the_ID() ),
			)
		);

		if ( '' === trim( $stats_html ) ) {
			return $content;
		}

		$section  = '<section class="af-review-summary" aria-label="' . esc_attr__( 'Valoración de la propiedad', 'arriendo-facil' ) . '">';
		$section .= '<h3>' . esc_html__( 'Valoración general', 'arriendo-facil' ) . '</h3>';
		$section .= $stats_html;
		$section .= '</section>';

		return $content . $section;
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

	/**
	 * Dispatches one review group by creating token and sending its email.
	 *
	 * @param object $group Review group row.
	 * @return bool
	 */
	private function dispatch_single_group( $group ) {
		if ( ! is_object( $group ) || ! isset( $group->id ) || ! isset( $group->reviewer_type ) ) {
			return false;
		}

		$reviewer_type = sanitize_key( (string) $group->reviewer_type );
		if ( ! array_key_exists( $reviewer_type, self::reviewer_types() ) ) {
			return false;
		}

		$recipient_email = $this->resolve_group_recipient_email( $group );
		if ( ! is_email( $recipient_email ) ) {
			return false;
		}

		$pending_reviews = $this->get_pending_reviews_for_group( (int) $group->id );
		if ( empty( $pending_reviews ) ) {
			return false;
		}

		$this->revoke_active_tokens_for_group( (int) $group->id );
		$token_data = self::create_review_token( (int) $group->id, self::default_token_attempts() );
		if ( is_wp_error( $token_data ) ) {
			return false;
		}

		$review_url = $this->build_review_link(
			isset( $token_data['selector'] ) ? (string) $token_data['selector'] : '',
			isset( $token_data['token'] ) ? (string) $token_data['token'] : ''
		);
		if ( '' === $review_url ) {
			return false;
		}

		$sent = $this->send_review_link_email(
			$recipient_email,
			$reviewer_type,
			(int) $group->accommodation_id,
			$review_url,
			isset( $token_data['expires_at'] ) ? (string) $token_data['expires_at'] : ''
		);
		if ( ! $sent ) {
			return false;
		}

		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update(
			self::groups_table(),
			array(
				'status'  => 'sent',
				'sent_at' => $now,
			),
			array( 'id' => (int) $group->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( isset( $token_data['token_id'] ) ) {
			$wpdb->update(
				self::tokens_table(),
				array( 'sent_at' => $now ),
				array( 'id' => absint( $token_data['token_id'] ) ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return true;
	}

	/**
	 * Gets a review group by ID.
	 *
	 * @param int $group_id Review group ID.
	 * @return object|null
	 */
	private function get_group_by_id( $group_id ) {
		$group_id = absint( $group_id );
		if ( ! $group_id ) {
			return null;
		}

		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::groups_table() . " WHERE id = %d LIMIT 1",
				$group_id
			)
		);
	}

	/**
	 * Finds review group ID by lease and reviewer type.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $reviewer_type Reviewer type.
	 * @return int
	 */
	private function find_group_id_by_lease_and_reviewer( $lease_id, $reviewer_type ) {
		$lease_id      = absint( $lease_id );
		$reviewer_type = sanitize_key( (string) $reviewer_type );
		if ( ! $lease_id || ! array_key_exists( $reviewer_type, self::reviewer_types() ) ) {
			return 0;
		}

		global $wpdb;
		$group_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::groups_table() . " WHERE lease_id = %d AND reviewer_type = %s LIMIT 1",
				$lease_id,
				$reviewer_type
			)
		);

		return $group_id ? (int) $group_id : 0;
	}

	/**
	 * Gets a token row by selector.
	 *
	 * @param string $selector Token selector.
	 * @return object|null
	 */
	private function get_token_by_selector( $selector ) {
		$selector = sanitize_text_field( (string) $selector );
		if ( '' === $selector ) {
			return null;
		}

		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::tokens_table() . " WHERE selector = %s LIMIT 1",
				$selector
			)
		);
	}

	/**
	 * Returns pending review rows for a group.
	 *
	 * @param int $group_id Review group ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_pending_reviews_for_group( $group_id ) {
		$group_id = absint( $group_id );
		if ( ! $group_id ) {
			return array();
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, review_direction
				 FROM " . self::reviews_table() . "
				 WHERE review_group_id = %d
				   AND status = %s
				 ORDER BY id ASC",
				$group_id,
				'pending'
			)
		);

		$pending = array();
		foreach ( (array) $rows as $row ) {
			$pending[] = array(
				'id'        => isset( $row->id ) ? absint( $row->id ) : 0,
				'direction' => isset( $row->review_direction ) ? sanitize_key( (string) $row->review_direction ) : '',
			);
		}

		return array_values( array_filter( $pending, static function( $item ) {
			return ! empty( $item['id'] ) && ! empty( $item['direction'] );
		} ) );
	}

	/**
	 * Revokes active tokens for a group before creating a new one.
	 *
	 * @param int $group_id Review group ID.
	 * @return void
	 */
	private function revoke_active_tokens_for_group( $group_id ) {
		$group_id = absint( $group_id );
		if ( ! $group_id ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			self::tokens_table(),
			array( 'status' => 'revoked' ),
			array(
				'review_group_id' => $group_id,
				'status'          => 'active',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Resolves which email receives the group review link.
	 *
	 * @param object $group Review group row.
	 * @return string
	 */
	private function resolve_group_recipient_email( $group ) {
		$reviewer_type = sanitize_key( (string) $group->reviewer_type );
		if ( 'tenant' === $reviewer_type ) {
			return sanitize_email( (string) $group->tenant_email );
		}

		$owner_user_id = isset( $group->owner_user_id ) ? absint( $group->owner_user_id ) : 0;
		if ( ! $owner_user_id ) {
			return '';
		}

		$user = get_userdata( $owner_user_id );
		if ( ! $user instanceof WP_User ) {
			return '';
		}

		return sanitize_email( (string) $user->user_email );
	}

	/**
	 * Builds the public review form URL.
	 *
	 * @param string $selector Token selector.
	 * @param string $token Plain token.
	 * @return string
	 */
	private function build_review_link( $selector, $token ) {
		$selector = sanitize_text_field( (string) $selector );
		$token    = sanitize_text_field( (string) $token );

		if ( '' === $selector || '' === $token ) {
			return '';
		}

		$path = apply_filters( 'af_review_form_path', '/calificar-estancia/' );
		$url  = home_url( '/' . ltrim( (string) $path, '/' ) );

		return (string) add_query_arg(
			array(
				'selector' => rawurlencode( $selector ),
				'token'    => rawurlencode( $token ),
			),
			$url
		);
	}

	/**
	 * Sends the review link email.
	 *
	 * @param string $recipient_email Recipient email.
	 * @param string $reviewer_type Reviewer type.
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $review_url Review URL.
	 * @param string $expires_at Expiration date/time.
	 * @return bool
	 */
	private function send_review_link_email( $recipient_email, $reviewer_type, $accommodation_id, $review_url, $expires_at ) {
		$recipient_email = sanitize_email( (string) $recipient_email );
		$reviewer_type   = sanitize_key( (string) $reviewer_type );
		$accommodation_id = absint( $accommodation_id );
		$review_url      = esc_url_raw( (string) $review_url );

		if ( ! is_email( $recipient_email ) || '' === $review_url ) {
			return false;
		}

		$property_title = (string) get_the_title( $accommodation_id );
		$subject        = 'tenant' === $reviewer_type
			? sprintf( __( '[Arriendo Facil] Califica tu experiencia en %s', 'arriendo-facil' ), $property_title ? $property_title : __( 'tu arriendo', 'arriendo-facil' ) )
			: sprintf( __( '[Arriendo Facil] Califica a tu inquilino de %s', 'arriendo-facil' ), $property_title ? $property_title : __( 'tu contrato', 'arriendo-facil' ) );

		$title = 'tenant' === $reviewer_type
			? __( 'Tu reseña ayuda a mejorar el ecosistema de arriendo', 'arriendo-facil' )
			: __( 'Comparte tu reseña del inquilino', 'arriendo-facil' );

		$description = 'tenant' === $reviewer_type
			? __( 'Completa en un solo formulario la calificación del propietario y de la propiedad.', 'arriendo-facil' )
			: __( 'Completa la calificación del inquilino para cerrar el ciclo del contrato.', 'arriendo-facil' );

		$expires_line = '';
		if ( '' !== trim( (string) $expires_at ) ) {
			$expires_line = '<p style="margin:0 0 14px;color:#334155;line-height:1.6;">' . sprintf( esc_html__( 'Este enlace estara disponible hasta: %s', 'arriendo-facil' ), esc_html( $expires_at ) ) . '</p>';
		}

		$message = '<div style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">';
		$message .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">';
		$message .= '<div style="padding:18px 22px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#ffffff;">';
		$message .= '<h2 style="margin:0;font-size:20px;line-height:1.3;">' . esc_html( $title ) . '</h2>';
		$message .= '</div>';
		$message .= '<div style="padding:22px;">';
		$message .= '<p style="margin:0 0 12px;line-height:1.6;">' . esc_html( $description ) . '</p>';
		$message .= '<p style="margin:0 0 18px;"><a href="' . esc_url( $review_url ) . '" style="display:inline-block;padding:12px 18px;border-radius:8px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:600;">' . esc_html__( 'Ir a calificar', 'arriendo-facil' ) . '</a></p>';
		$message .= $expires_line;
		$message .= '<p style="margin:0;line-height:1.6;color:#475569;">' . esc_html__( 'El enlace es de un solo uso y se invalida al enviar tu calificación.', 'arriendo-facil' ) . '</p>';
		$message .= '</div></div>';
		$message .= '<p style="max-width:640px;margin:12px auto 0;font-size:12px;color:#64748b;text-align:center;">Arriendo Facil</p>';
		$message .= '</div>';

		return (bool) wp_mail( $recipient_email, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Parses ratings payload from JSON or array format.
	 *
	 * @param mixed $ratings_payload Raw ratings payload.
	 * @return array<string,int>
	 */
	private function parse_ratings_payload( $ratings_payload ) {
		$ratings = array();

		if ( is_array( $ratings_payload ) ) {
			$raw = $ratings_payload;
		} elseif ( is_string( $ratings_payload ) && '' !== trim( $ratings_payload ) ) {
			$decoded = json_decode( (string) $ratings_payload, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		} else {
			$raw = array();
		}

		$allowed = self::review_directions();
		foreach ( $raw as $direction => $stars ) {
			$direction = sanitize_key( (string) $direction );
			if ( ! array_key_exists( $direction, $allowed ) ) {
				continue;
			}

			$stars = absint( $stars );
			if ( $stars < 1 || $stars > 5 ) {
				continue;
			}

			$ratings[ $direction ] = $stars;
		}

		// Fallback for clients that submit a single pair: direction + stars.
		if ( empty( $ratings ) ) {
			$single_direction = isset( $_POST['review_direction'] ) ? sanitize_key( (string) wp_unslash( $_POST['review_direction'] ) ) : '';
			$single_stars     = isset( $_POST['stars'] ) ? absint( wp_unslash( $_POST['stars'] ) ) : 0;
			if ( '' !== $single_direction && $single_stars >= 1 && $single_stars <= 5 && array_key_exists( $single_direction, $allowed ) ) {
				$ratings[ $single_direction ] = $single_stars;
			}
		}

		return $ratings;
	}
}