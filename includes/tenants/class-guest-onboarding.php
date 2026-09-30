<?php
/**
 * Tenant onboarding: one-time profile link, token handling and lease creation.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Guest_Onboarding
 *
 * Hook-free service used by the onboarding controller, the tenants screen and
 * the rental workflow.
 */
class Arriendo_Facil_Guest_Onboarding {

	/**
	 * Sends onboarding link for a confirmed visit booking without requiring AJAX.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param int    $visit_booking_id Visit booking ID.
	 * @param string $guest_name Guest full name.
	 * @param string $guest_email Guest email.
	 * @param string $guest_phone Guest phone.
	 * @param string $form_path Relative form path.
	 * @param int    $expires_hours Link lifetime in hours.
	 * @return array<string,mixed>
	 */
	public function send_guest_profile_link_for_booking( $accommodation_id, $visit_booking_id, $guest_name, $guest_email, $guest_phone = '', $form_path = '/completar-perfil-arriendo/', $expires_hours = 72 ) {
		$accommodation_id = absint( $accommodation_id );
		$visit_booking_id = absint( $visit_booking_id );
		$guest_name       = sanitize_text_field( (string) $guest_name );
		$guest_email      = sanitize_email( (string) $guest_email );
		$guest_phone      = sanitize_text_field( (string) $guest_phone );
		$form_path        = sanitize_text_field( (string) $form_path );
		$expires_hours    = max( 6, min( 168, absint( $expires_hours ) ) );

		if ( ! $accommodation_id || ! is_email( $guest_email ) ) {
			return array(
				'sent'  => false,
				'error' => 'invalid_booking_input',
			);
		}

		$schema_result = Arriendo_Facil_Guest::ensure_guest_extra_columns();
		if ( is_wp_error( $schema_result ) ) {
			return array(
				'sent'  => false,
				'error' => $schema_result->get_error_message(),
			);
		}

		global $wpdb;
		$guest = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, first_name, last_name, email FROM {$wpdb->prefix}af_guests WHERE email = %s ORDER BY id DESC LIMIT 1",
				$guest_email
			)
		);

		$guest_id = $guest && isset( $guest->id ) ? absint( $guest->id ) : 0;
		if ( ! $guest_id ) {
			$name_parts = preg_split( '/\s+/', trim( $guest_name ) );
			if ( ! is_array( $name_parts ) ) {
				$name_parts = array();
			}

			$first_name = ! empty( $name_parts[0] ) ? sanitize_text_field( (string) $name_parts[0] ) : __( 'Arrendatario', 'arriendo-facil' );
			$last_name  = count( $name_parts ) > 1 ? sanitize_text_field( trim( implode( ' ', array_slice( $name_parts, 1 ) ) ) ) : '';

			$inserted = $wpdb->insert(
				$wpdb->prefix . 'af_guests',
				array(
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'email'      => $guest_email,
					'phone'      => $guest_phone,
					'id_number'  => '',
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);

			if ( ! $inserted ) {
				return array(
					'sent'  => false,
					'error' => 'guest_insert_failed',
				);
			}

			$guest_id = absint( $wpdb->insert_id );
		} else {
			$wpdb->update(
				$wpdb->prefix . 'af_guests',
				array( 'phone' => $guest_phone ),
				array( 'id' => $guest_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		if ( ! $guest_id ) {
			return array(
				'sent'  => false,
				'error' => 'guest_id_missing',
			);
		}

		$token_data = $this->create_guest_onboarding_token( $guest_id, $accommodation_id, $visit_booking_id, $guest_email, $expires_hours );
		if ( is_wp_error( $token_data ) ) {
			return array(
				'sent'  => false,
				'error' => $token_data->get_error_message(),
			);
		}

		$selector = isset( $token_data['selector'] ) ? (string) $token_data['selector'] : '';
		$token    = isset( $token_data['token'] ) ? (string) $token_data['token'] : '';
		if ( '' === $selector || '' === $token ) {
			return array(
				'sent'  => false,
				'error' => 'token_build_failed',
			);
		}

		$form_url = home_url( '/' . ltrim( $form_path, '/' ) );
		$form_url = add_query_arg(
			array(
				'selector' => rawurlencode( $selector ),
				'token'    => rawurlencode( $token ),
			),
			$form_url
		);

		$sent = $this->send_guest_legal_profile_link_email(
			$guest_email,
			$guest_name,
			$form_url,
			$accommodation_id,
			isset( $token_data['expires_at'] ) ? (string) $token_data['expires_at'] : ''
		);

		if ( ! $sent ) {
			return array(
				'sent'       => false,
				'guest_id'   => $guest_id,
				'expires_at' => isset( $token_data['expires_at'] ) ? (string) $token_data['expires_at'] : '',
				'error'      => 'wp_mail_failed',
			);
		}

		return array(
			'sent'       => true,
			'guest_id'   => $guest_id,
			'expires_at' => isset( $token_data['expires_at'] ) ? (string) $token_data['expires_at'] : '',
		);
	}

	/**
	 * Creates onboarding token row for post-visit legal profile completion.
	 *
	 * @param int    $guest_id Guest ID.
	 * @param int    $accommodation_id Accommodation ID.
	 * @param int    $visit_booking_id Visit booking ID.
	 * @param string $recipient_email Guest email.
	 * @param int    $expires_hours Expiration in hours.
	 * @return array|WP_Error
	 */
	public function create_guest_onboarding_token( $guest_id, $accommodation_id, $visit_booking_id, $recipient_email, $expires_hours = 72 ) {
		$guest_id         = absint( $guest_id );
		$accommodation_id = absint( $accommodation_id );
		$visit_booking_id = absint( $visit_booking_id );
		$recipient_email  = sanitize_email( (string) $recipient_email );
		$expires_hours    = max( 6, min( 168, absint( $expires_hours ) ) );

		if ( ! $guest_id || ! $accommodation_id || ! is_email( $recipient_email ) ) {
			return new WP_Error( 'af_guest_onboarding_invalid_input', __( 'Datos insuficientes para generar token seguro.', 'arriendo-facil' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_guest_onboarding_tokens';

		$selector = wp_generate_password( 18, false, false ) . dechex( random_int( 1000, 65535 ) );
		$token    = wp_generate_password( 48, false, false ) . dechex( random_int( 4096, 65535 ) );
		$hash     = password_hash( $token, PASSWORD_DEFAULT );

		if ( ! is_string( $hash ) || '' === $hash ) {
			return new WP_Error( 'af_guest_onboarding_hash_failed', __( 'No se pudo generar hash de seguridad para el token.', 'arriendo-facil' ) );
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $expires_hours . ' hours' ) );

		$inserted = $wpdb->insert(
			$table,
			array(
				'selector'         => sanitize_text_field( (string) $selector ),
				'token_hash'       => $hash,
				'guest_id'         => $guest_id,
				'accommodation_id' => $accommodation_id,
				'visit_booking_id' => $visit_booking_id ? $visit_booking_id : null,
				'purpose'          => 'legal_profile',
				'recipient_email'  => $recipient_email,
				'expires_at'       => $expires_at,
				'max_attempts'     => 8,
				'attempts'         => 0,
				'status'           => 'active',
				'created_by'       => get_current_user_id(),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'af_guest_onboarding_insert_failed', __( 'No se pudo guardar el token de onboarding.', 'arriendo-facil' ) );
		}

		return array(
			'token_id'    => (int) $wpdb->insert_id,
			'selector'    => (string) $selector,
			'token'       => (string) $token,
			'expires_at'  => (string) $expires_at,
		);
	}

	/**
	 * Resolves and validates onboarding token.
	 *
	 * @param string $selector Selector component.
	 * @param string $token Plain token component.
	 * @param bool   $increment_attempts Whether to increment attempt counter.
	 * @return array|WP_Error
	 */
	public function resolve_guest_onboarding_token( $selector, $token, $increment_attempts = false ) {
		$selector = sanitize_text_field( (string) $selector );
		$token    = sanitize_text_field( (string) $token );

		if ( '' === $selector || '' === $token ) {
			return new WP_Error( 'af_guest_onboarding_missing_token', __( 'Token incompleto.', 'arriendo-facil' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_guest_onboarding_tokens';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE selector = %s LIMIT 1",
				$selector
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'af_guest_onboarding_not_found', __( 'El enlace no es valido.', 'arriendo-facil' ) );
		}

		$status = isset( $row->status ) ? sanitize_key( (string) $row->status ) : '';
		if ( 'active' !== $status ) {
			return new WP_Error( 'af_guest_onboarding_inactive', __( 'Este enlace ya no esta activo.', 'arriendo-facil' ) );
		}

		$expires_at = isset( $row->expires_at ) ? strtotime( (string) $row->expires_at ) : false;
		if ( false === $expires_at || $expires_at < time() ) {
			$wpdb->update(
				$table,
				array( 'status' => 'expired' ),
				array( 'id' => absint( $row->id ) ),
				array( '%s' ),
				array( '%d' )
			);
			return new WP_Error( 'af_guest_onboarding_expired', __( 'Este enlace ya expiro. Solicita uno nuevo.', 'arriendo-facil' ) );
		}

		$max_attempts = isset( $row->max_attempts ) ? absint( $row->max_attempts ) : 8;
		$attempts     = isset( $row->attempts ) ? absint( $row->attempts ) : 0;

		if ( $attempts >= $max_attempts ) {
			$wpdb->update(
				$table,
				array( 'status' => 'blocked' ),
				array( 'id' => absint( $row->id ) ),
				array( '%s' ),
				array( '%d' )
			);
			return new WP_Error( 'af_guest_onboarding_blocked', __( 'Enlace bloqueado por demasiados intentos.', 'arriendo-facil' ) );
		}

		$verified = password_verify( $token, (string) $row->token_hash );

		if ( $increment_attempts ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d",
					absint( $row->id )
				)
			);
		}

		if ( ! $verified ) {
			return new WP_Error( 'af_guest_onboarding_invalid_token', __( 'Token de acceso invalido.', 'arriendo-facil' ) );
		}

		return array(
			'token_id'         => absint( $row->id ),
			'guest_id'         => absint( $row->guest_id ),
			'accommodation_id' => absint( $row->accommodation_id ),
			'visit_booking_id' => absint( $row->visit_booking_id ),
			'expires_at'       => sanitize_text_field( (string) $row->expires_at ),
		);
	}

	/**
	 * Marks onboarding token as consumed.
	 *
	 * @param int $token_id Token row ID.
	 * @return void
	 */
	public function consume_guest_onboarding_token( $token_id ) {
		$token_id = absint( $token_id );
		if ( ! $token_id ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'af_guest_onboarding_tokens';
		$wpdb->update(
			$table,
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
	 * Sends onboarding email with secure legal-profile link.
	 *
	 * @param string $tenant_email Recipient email.
	 * @param string $tenant_name Recipient display name.
	 * @param string $form_url Link URL.
	 * @param int    $accommodation_id Accommodation ID.
	 * @param string $expires_at Expiration date/time.
	 * @return bool
	 */
	public function send_guest_legal_profile_link_email( $tenant_email, $tenant_name, $form_url, $accommodation_id, $expires_at ) {
		$tenant_email = sanitize_email( (string) $tenant_email );
		if ( ! is_email( $tenant_email ) ) {
			return false;
		}

		$tenant_name = sanitize_text_field( (string) $tenant_name );
		if ( '' === trim( $tenant_name ) ) {
			$tenant_name = __( 'arrendatario', 'arriendo-facil' );
		}

		$title   = (string) get_the_title( absint( $accommodation_id ) );
		$subject = sprintf( __( '[Arriendo Facil] Completa tu perfil legal para %s', 'arriendo-facil' ), $title ? $title : __( 'tu arriendo', 'arriendo-facil' ) );

		$expires_line = '';
		if ( '' !== trim( (string) $expires_at ) ) {
			$expires_line = '<p style="margin:0 0 14px;color:#334155;line-height:1.6;">' . sprintf( esc_html__( 'Este enlace estara disponible hasta: %s', 'arriendo-facil' ), esc_html( $expires_at ) ) . '</p>';
		}

		$message = '<div style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">';
		$message .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">';
		$message .= '<div style="padding:18px 22px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#ffffff;">';
		$message .= '<h2 style="margin:0;font-size:20px;line-height:1.3;">' . esc_html__( 'Completa tu perfil legal de arriendo', 'arriendo-facil' ) . '</h2>';
		$message .= '</div>';
		$message .= '<div style="padding:22px;">';
		$message .= '<p style="margin:0 0 12px;line-height:1.6;">' . sprintf( esc_html__( 'Hola %s, para continuar con tu proceso necesitamos tu informacion legal y documentos.', 'arriendo-facil' ), esc_html( $tenant_name ) ) . '</p>';
		$message .= '<p style="margin:0 0 16px;line-height:1.6;">' . esc_html__( 'Haz clic en el siguiente boton para completar el formulario seguro:', 'arriendo-facil' ) . '</p>';
		$message .= '<p style="margin:0 0 18px;"><a href="' . esc_url( (string) $form_url ) . '" style="display:inline-block;padding:12px 18px;border-radius:8px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:600;">' . esc_html__( 'Completar perfil legal', 'arriendo-facil' ) . '</a></p>';
		$message .= $expires_line;
		$message .= '<p style="margin:0;line-height:1.6;color:#475569;">' . esc_html__( 'Si el enlace expira, solicita uno nuevo al propietario o soporte.', 'arriendo-facil' ) . '</p>';
		$message .= '</div></div>';
		$message .= '<p style="max-width:640px;margin:12px auto 0;font-size:12px;color:#64748b;text-align:center;">Arriendo Facil</p>';
		$message .= '</div>';

		return (bool) wp_mail( $tenant_email, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Creates a draft lease and attempts automatic contract generation.
	 *
	 * @param int   $guest_id Guest ID.
	 * @param array $data Guest rental data.
	 * @return array
	 */
	public function create_lease_contract_for_guest( $guest_id, array $data ) {
		$accommodation_id = isset( $data['accommodation_id'] ) ? absint( $data['accommodation_id'] ) : 0;
		$rental_mode      = isset( $data['rental_mode'] ) ? sanitize_key( $data['rental_mode'] ) : 'years';

		if ( ! $accommodation_id || ! $guest_id ) {
			return array( 'generated' => false );
		}

		$start_date = '';
		$end_date   = '';
		$today      = current_time( 'Y-m-d' );

		if ( 'dates' === $rental_mode ) {
			$start_date = isset( $data['rental_start_date'] ) ? sanitize_text_field( $data['rental_start_date'] ) : '';
			$end_date   = isset( $data['rental_end_date'] ) ? sanitize_text_field( $data['rental_end_date'] ) : '';
		} elseif ( 'months' === $rental_mode ) {
			$months = isset( $data['rental_months'] ) ? absint( $data['rental_months'] ) : 1;
			$start_date = isset( $data['rental_start_date'] ) ? sanitize_text_field( $data['rental_start_date'] ) : $today;
			$end_date   = gmdate( 'Y-m-d', strtotime( '+' . max( 1, $months ) . ' months', strtotime( $start_date ) ) );
		} else {
			$years = isset( $data['rental_years'] ) ? max( 1, absint( $data['rental_years'] ) ) : 1;
			$start_date = isset( $data['rental_start_date'] ) ? sanitize_text_field( $data['rental_start_date'] ) : $today;
			$end_date   = gmdate( 'Y-m-d', strtotime( '+' . $years . ' years', strtotime( $start_date ) ) );
		}

		if ( ! $start_date || ! $end_date ) {
			return array( 'generated' => false );
		}

		$monthly_rent = (float) get_post_meta( $accommodation_id, '_af_monthly_rent', true );

		global $wpdb;
		$lease_inserted = $wpdb->insert(
			$wpdb->prefix . 'af_leases',
			array(
				'accommodation_id' => $accommodation_id,
				'guest_id'         => $guest_id,
				'start_date'       => $start_date,
				'end_date'         => $end_date,
				'monthly_rent'     => $monthly_rent,
				'status'           => 'draft',
			),
			array( '%d', '%d', '%s', '%s', '%f', '%s' )
		);

		if ( ! $lease_inserted ) {
			return array( 'generated' => false );
		}

		$lease_id = (int) $wpdb->insert_id;

		// Auto-marcar acomodación como ocupada al crear contrato en borrador.
		if ( class_exists( 'Arriendo_Facil_Occupancy' ) ) {
			Arriendo_Facil_Occupancy::mark_occupied( $accommodation_id );
		} else {
			update_post_meta( $accommodation_id, '_af_is_occupied', '1' );
		}

		$this->send_tenant_processing_email(
			isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '',
			isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '',
			$accommodation_id,
			$lease_id
		);

		return ( new Arriendo_Facil_Contract_Generator() )->generate_for_lease( $lease_id );
	}

	/**
	 * Sends processing-notification email to tenant after chatbot registration.
	 *
	 * @param string $tenant_email Tenant email.
	 * @param string $tenant_name Tenant full name.
	 * @param int    $accommodation_id Accommodation ID.
	 * @param int    $lease_id Lease ID.
	 * @return void
	 */
	public function send_tenant_processing_email( $tenant_email, $tenant_name, $accommodation_id, $lease_id ) {
		$tenant_email = sanitize_email( (string) $tenant_email );
		if ( ! is_email( $tenant_email ) ) {
			return;
		}

		$tenant_name = sanitize_text_field( (string) $tenant_name );
		$tenant_name = '' !== trim( $tenant_name ) ? $tenant_name : __( 'arrendatario', 'arriendo-facil' );

		$accommodation_title = (string) get_the_title( absint( $accommodation_id ) );
		if ( '' === trim( $accommodation_title ) ) {
			$accommodation_title = __( 'la propiedad solicitada', 'arriendo-facil' );
		}

		$subject = __( 'Estamos procesando tu solicitud de arriendo', 'arriendo-facil' );
		$message = sprintf(
			/* translators: 1: tenant name, 2: accommodation title, 3: lease ID */
			__( "Hola %1$s,\n\nRecibimos tu solicitud de arriendo para %2$s.\n\nTu contrato se encuentra en procesamiento y nuestro equipo revisara la informacion pronto.\n\nNumero de solicitud: %3$d\n\nGracias por usar Arriendo Facil.", 'arriendo-facil' ),
			$tenant_name,
			$accommodation_title,
			absint( $lease_id )
		);

		wp_mail( $tenant_email, $subject, $message );
	}
}
