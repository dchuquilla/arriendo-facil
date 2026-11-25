<?php
/**
 * Portal del inquilino — perfil y documentos.
 *
 * Editable directo: teléfono, contacto de emergencia, dirección, ocupación.
 * Con verificación: el correo (código al correo NUEVO).
 * Solo por solicitud al gestor: nombre e identificación (evita suplantación).
 *
 * Todo cambio escribe en `af_guests` (la misma ficha que ve el gestor), deja una
 * marca "actualizado por el inquilino" y un evento en la bitácora.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Portal_Profile
 */
class Arriendo_Facil_Portal_Profile {

	const EMAIL_CODE_TTL = 600;

	/**
	 * Hooks.
	 */
	public function __construct() {
		foreach ( array( 'profile_save', 'email_request', 'email_verify', 'doc_upload', 'correction' ) as $action ) {
			add_action( 'wp_ajax_nopriv_af_tpa_' . $action, array( $this, 'ajax_' . $action ) );
			add_action( 'wp_ajax_af_tpa_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Piezas puras
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Teléfono normalizado: dígitos y un "+" inicial opcional, de 7 a 15 dígitos (E.164).
	 *
	 * @param string $raw Texto escrito.
	 * @return string Vacío si no es válido.
	 */
	public static function normalize_phone( $raw ) {
		$raw    = trim( (string) $raw );
		$plus   = 0 === strpos( $raw, '+' ) ? '+' : '';
		$digits = (string) preg_replace( '/\D+/', '', $raw );

		return ( strlen( $digits ) >= 7 && strlen( $digits ) <= 15 ) ? $plus . $digits : '';
	}

	/**
	 * Campos que el inquilino puede editar directamente (los mismos que pide el registro interno; solo el teléfono) y su largo máximo.
	 *
	 * @return array<string,int>
	 */
	public static function editable_fields() {
		return array(
			'phone'             => 20,
		);
	}

	/**
	 * Tipos de documento que el inquilino puede subir.
	 *
	 * @param int $guest_id Inquilino.
	 * @return array<string,string>
	 */
	public static function document_types( $guest_id ) {
		return array_merge(
			Arriendo_Facil_Document_Verification::required_document_types( $guest_id ),
			Arriendo_Facil_Document_Verification::income_proof_types(),
			array( 'garantia_alicuota' => __( 'Garantía / alícuota', 'arriendo-facil' ) )
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Lectura para el portal
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Documentos del inquilino por tipo (el último subido) y estado global.
	 *
	 * @param int $guest_id Inquilino.
	 * @return array{types:array<string,array<string,mixed>>,status:string,missing:int}
	 */
	public static function documents_overview( $guest_id ) {
		global $wpdb;

		$rows   = Arriendo_Facil_Document_Verification::get_guest_documents( $guest_id );
		$latest = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $latest[ $row->doc_type ] ) ) {
				$latest[ $row->doc_type ] = $row; // Vienen del más reciente al más antiguo.
			}
		}

		$required = Arriendo_Facil_Document_Verification::required_document_types( $guest_id );
		$income   = Arriendo_Facil_Document_Verification::income_proof_types();
		$types    = array();
		foreach ( self::document_types( $guest_id ) as $key => $label ) {
			$types[ $key ] = array(
				'label'      => $label,
				'uploaded'   => isset( $latest[ $key ] ),
				'uploaded_at' => isset( $latest[ $key ] ) ? (string) $latest[ $key ]->created_at : '',
				'required'   => isset( $required[ $key ] ),
				'income'     => isset( $income[ $key ] ),
			);
		}

		return array(
			'types'   => $types,
			'status'  => Arriendo_Facil_Document_Verification::get_status( $guest_id ),
			'missing' => count( Arriendo_Facil_Document_Verification::missing_required_documents( $guest_id ) ),
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * AJAX
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Sesión + CSRF. Termina si falla.
	 *
	 * @return object Sesión.
	 */
	private static function guard() {
		$session = Arriendo_Facil_Tenant_Access::current_session();
		if ( ! $session || ! Arriendo_Facil_Tenant_Access::csrf_ok( $session ) ) {
			wp_send_json_error( array( 'code' => 'session', 'message' => __( 'Tu sesión venció. Vuelve a entrar con un código.', 'arriendo-facil' ) ), 401 );
		}

		return $session;
	}

	/**
	 * Marca la ficha como actualizada por el inquilino y avisa al gestor de cada inmueble suyo.
	 *
	 * @param int    $guest_id Inquilino.
	 * @param string $title    Título del aviso.
	 * @param string $message  Mensaje.
	 * @return void
	 */
	private static function flag_for_manager( $guest_id, $title, $message ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'af_guests',
			array( 'profile_updated_at' => gmdate( 'Y-m-d H:i:s' ), 'profile_updated_review' => 1 ),
			array( 'id' => (int) $guest_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		$accommodations = array_unique( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT accommodation_id FROM {$wpdb->prefix}af_leases WHERE guest_id = %d AND deleted_at IS NULL", (int) $guest_id ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $accommodations as $accommodation_id ) {
			if ( $accommodation_id ) {
				Arriendo_Facil_Portal_Manager::notify_manager( $accommodation_id, 'tenant_profile', $title, $message, 'af-guests', 'tenant-profile-' . $guest_id . '-' . gmdate( 'YmdH' ) );
			}
		}
	}

	/**
	 * AJAX: guarda los campos editables.
	 *
	 * @return void
	 */
	public function ajax_profile_save() {
		global $wpdb;
		$session  = self::guard();
		$guest_id = (int) $session->guest_id;
		$guest    = Arriendo_Facil_Tenant_Access::guest_by_id( $guest_id );
		$update   = array();
		$changed  = array();

		foreach ( self::editable_fields() as $field => $max ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$value = mb_substr( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ), 0, $max );

			if ( 'phone' === $field ) {
				$value = self::normalize_phone( $value );
				if ( '' === $value ) {
					wp_send_json_error( array( 'message' => __( 'Escribe un teléfono válido (solo números, de 7 a 15 dígitos).', 'arriendo-facil' ) ), 400 );
				}
			}

			if ( (string) ( $guest->$field ?? '' ) !== $value ) {
				$update[ $field ] = '' === $value ? null : $value;
				$changed[]        = $field; // Solo el nombre del campo: la bitácora no guarda datos personales.
			}
		}

		if ( empty( $changed ) ) {
			wp_send_json_success( array( 'message' => __( 'No hay cambios que guardar.', 'arriendo-facil' ) ) );
		}

		if ( false === $wpdb->update( $wpdb->prefix . 'af_guests', $update, array( 'id' => $guest_id ) ) ) {
			wp_send_json_error( array( 'message' => __( 'No pudimos guardar tus datos. Inténtalo de nuevo.', 'arriendo-facil' ) ), 500 );
		}

		Arriendo_Facil_Portal_Events::log( $guest_id, 0, 'profile', $guest_id, 'tenant', 0, 'profile_updated', array( 'fields' => $changed ) );
		self::flag_for_manager( $guest_id, __( 'Un inquilino actualizó su perfil', 'arriendo-facil' ), __( 'Revisa los datos actualizados en su ficha.', 'arriendo-facil' ) );

		// Un cambio de teléfono avisa por correo: si no fue la persona, lo nota.
		if ( in_array( 'phone', $changed, true ) ) {
			Arriendo_Facil_Portal_Manager::notify_tenant( $guest_id, __( 'Cambiaste tu teléfono', 'arriendo-facil' ), __( 'Actualizaste el teléfono de tu perfil. Si no fuiste tú, avisa de inmediato a tu administrador.', 'arriendo-facil' ) );
		}

		wp_send_json_success( array( 'message' => __( 'Datos guardados. Tu administrador ya los ve.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: pide el código para cambiar de correo (se envía al correo NUEVO).
	 *
	 * @return void
	 */
	public function ajax_email_request() {
		global $wpdb;
		$session  = self::guard();
		$guest_id = (int) $session->guest_id;
		$guest    = Arriendo_Facil_Tenant_Access::guest_by_id( $guest_id );
		$email    = isset( $_POST['new_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['new_email'] ) ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Escribe un correo válido.', 'arriendo-facil' ) ), 400 );
		}
		if ( Arriendo_Facil_Tenant_Access::throttled( 'emailchg', (string) $guest_id, 5, HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Demasiados intentos. Espera un rato e inténtalo de nuevo.', 'arriendo-facil' ) ), 429 );
		}
		if ( strtolower( (string) $guest->email ) === $email ) {
			wp_send_json_error( array( 'message' => __( 'Ese ya es tu correo.', 'arriendo-facil' ) ), 400 );
		}

		// Mensaje genérico si el correo ya lo usa otra ficha: no se revela quién está registrado.
		$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}af_guests WHERE email = %s AND id <> %d AND deleted_at IS NULL LIMIT 1", $email, $guest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $taken ) {
			wp_send_json_error( array( 'message' => __( 'No pudimos usar ese correo. Prueba con otro o contacta a tu administrador.', 'arriendo-facil' ) ), 400 );
		}

		$code = Arriendo_Facil_Tenant_Access::new_code();
		set_transient(
			'af_tp_emailchg_' . $guest_id,
			array( 'hash' => Arriendo_Facil_Tenant_Access::hash_code( $code, $guest_id ), 'email' => $email, 'tries' => 0 ),
			self::EMAIL_CODE_TTL
		);

		$sent = Arriendo_Facil_Email::send(
			$email,
			__( 'Confirma tu nuevo correo', 'arriendo-facil' ),
			array(
				'title'     => __( 'Confirma tu nuevo correo', 'arriendo-facil' ),
				'body_text' => sprintf( /* translators: %s: code */ __( 'Tu código es %s. Vence en 10 minutos. Si no lo pediste, ignora este mensaje.', 'arriendo-facil' ), $code ),
			)
		);
		if ( ! $sent ) {
			delete_transient( 'af_tp_emailchg_' . $guest_id );
			wp_send_json_error( array( 'message' => __( 'No pudimos enviar el correo. Inténtalo de nuevo en unos minutos.', 'arriendo-facil' ) ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'Te enviamos un código al correo nuevo.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: confirma el código y cambia el correo.
	 *
	 * @return void
	 */
	public function ajax_email_verify() {
		global $wpdb;
		$session  = self::guard();
		$guest_id = (int) $session->guest_id;
		$code     = Arriendo_Facil_Tenant_Access::normalize_code( isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se reduce a dígitos.
		$pending  = get_transient( 'af_tp_emailchg_' . $guest_id );

		if ( ! is_array( $pending ) ) {
			wp_send_json_error( array( 'message' => __( 'El código venció. Pide uno nuevo.', 'arriendo-facil' ) ), 400 );
		}
		if ( (int) $pending['tries'] >= Arriendo_Facil_Tenant_Access::MAX_ATTEMPTS ) {
			delete_transient( 'af_tp_emailchg_' . $guest_id );
			wp_send_json_error( array( 'message' => __( 'Demasiados intentos. Pide un código nuevo.', 'arriendo-facil' ) ), 429 );
		}

		$pending['tries'] = (int) $pending['tries'] + 1;
		set_transient( 'af_tp_emailchg_' . $guest_id, $pending, self::EMAIL_CODE_TTL );

		if ( ! Arriendo_Facil_Tenant_Access::code_matches( $code, (string) $pending['hash'], $guest_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Código incorrecto.', 'arriendo-facil' ) ), 400 );
		}

		$old = (string) Arriendo_Facil_Tenant_Access::guest_by_id( $guest_id )->email;
		delete_transient( 'af_tp_emailchg_' . $guest_id );

		if ( false === $wpdb->update( $wpdb->prefix . 'af_guests', array( 'email' => $pending['email'] ), array( 'id' => $guest_id ), array( '%s' ), array( '%d' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'No pudimos cambiar el correo. Inténtalo de nuevo.', 'arriendo-facil' ) ), 500 );
		}

		Arriendo_Facil_Portal_Events::log( $guest_id, 0, 'profile', $guest_id, 'tenant', 0, 'email_changed', array() );
		self::flag_for_manager( $guest_id, __( 'Un inquilino cambió su correo', 'arriendo-facil' ), __( 'Revisa su ficha: el correo cambió tras verificarlo con un código.', 'arriendo-facil' ) );

		// Aviso al correo anterior: si no fue la persona, puede reaccionar.
		if ( is_email( $old ) ) {
			Arriendo_Facil_Email::send(
				$old,
				__( 'Cambiaron el correo de tu portal', 'arriendo-facil' ),
				array(
					'title'     => __( 'Tu correo de acceso cambió', 'arriendo-facil' ),
					'body_text' => __( 'El correo de tu portal de inquilino fue cambiado. Si no fuiste tú, avisa de inmediato a tu administrador.', 'arriendo-facil' ),
				)
			);
		}

		wp_send_json_success( array( 'message' => __( 'Correo actualizado.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: sube un documento.
	 *
	 * @return void
	 */
	public function ajax_doc_upload() {
		global $wpdb;
		$session  = self::guard();
		$guest_id = (int) $session->guest_id;

		if ( Arriendo_Facil_Tenant_Access::throttled( 'upload', (string) $guest_id, Arriendo_Facil_Portal_Evidence::DAILY_UPLOADS, DAY_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Llegaste al máximo de envíos por hoy. Inténtalo mañana.', 'arriendo-facil' ) ), 429 );
		}

		$type = isset( $_POST['doc_type'] ) ? sanitize_key( wp_unslash( $_POST['doc_type'] ) ) : '';
		if ( ! array_key_exists( $type, self::document_types( $guest_id ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Elige el tipo de documento.', 'arriendo-facil' ) ), 400 );
		}

		$files = Arriendo_Facil_Portal_Evidence::from_request( 'files', 1, 3 );
		if ( is_wp_error( $files ) ) {
			wp_send_json_error( array( 'message' => $files->get_error_message() ), 400 );
		}

		$person = trim( (string) $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(first_name, ' ', last_name) FROM {$wpdb->prefix}af_guests WHERE id = %d", $guest_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$added  = array();

		foreach ( $files as $file ) {
			$put = Arriendo_Facil_Portal_Evidence::store_bytes( $file, $guest_id, $person, $type );
			if ( is_wp_error( $put ) ) {
				wp_send_json_error( array( 'message' => $put->get_error_message() ), 500 );
			}

			$ok = $wpdb->insert(
				$wpdb->prefix . 'af_guest_documents',
				array(
					'guest_id'        => $guest_id,
					'doc_type'        => $type,
					'storage'         => $put['storage'],
					'object_key'      => $put['key'],
					'mime_type'       => $file['mime'],
					'file_size'       => strlen( $file['bytes'] ),
					'checksum_sha256' => hash( 'sha256', $file['bytes'] ),
					'uploaded_by'     => null,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( ! $ok ) {
				'r2' === $put['storage'] ? Arriendo_Facil_Private_Storage::delete( $put['key'] ) : Arriendo_Facil_Local_Private_Storage::delete( $put['key'] );
				wp_send_json_error( array( 'message' => __( 'No pudimos registrar el documento. Inténtalo de nuevo.', 'arriendo-facil' ) ), 500 );
			}
			$added[] = (int) $wpdb->insert_id;
		}

		// Un documento nuevo reabre la verificación del gestor.
		$wpdb->update( $wpdb->prefix . 'af_guests', array( 'doc_status' => 'pendiente' ), array( 'id' => $guest_id ), array( '%s' ), array( '%d' ) );

		Arriendo_Facil_Portal_Events::log( $guest_id, 0, 'document', $guest_id, 'tenant', 0, 'document_uploaded', array( 'type' => $type, 'files' => count( $added ) ) );
		self::flag_for_manager( $guest_id, __( 'Un inquilino subió un documento', 'arriendo-facil' ), __( 'Hay un documento nuevo por verificar.', 'arriendo-facil' ) );

		wp_send_json_success( array( 'message' => __( 'Documento recibido. Tu administrador lo verificará.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: pide corregir el nombre o la identificación (los aprueba el gestor).
	 *
	 * @return void
	 */
	public function ajax_correction() {
		$session  = self::guard();
		$guest_id = (int) $session->guest_id;

		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$value = isset( $_POST['value'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['value'] ) ), 0, 190 ) : '';
		$note  = isset( $_POST['note'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ) ), 0, 255 ) : '';

		if ( ! in_array( $field, array( 'name', 'id_number' ), true ) || mb_strlen( $value ) < 3 ) {
			wp_send_json_error( array( 'message' => __( 'Indica qué dato corregir y el valor correcto.', 'arriendo-facil' ) ), 400 );
		}
		if ( Arriendo_Facil_Tenant_Access::throttled( 'correction', (string) $guest_id, 5, DAY_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Ya enviaste varias solicitudes hoy. Contacta a tu administrador.', 'arriendo-facil' ) ), 429 );
		}

		// El valor propuesto puede ser una identificación: se guarda cifrado.
		$stored = class_exists( 'Arriendo_Facil_Crypto' ) && Arriendo_Facil_Crypto::has_master() ? Arriendo_Facil_Crypto::seal( $value, 'portal_correction' ) : $value;

		Arriendo_Facil_Portal_Events::log( $guest_id, 0, 'profile', $guest_id, 'tenant', 0, 'correction_requested', array( 'field' => $field, 'value' => $stored, 'note' => $note ) );
		self::flag_for_manager( $guest_id, __( 'Un inquilino pidió corregir un dato', 'arriendo-facil' ), __( 'Pidió corregir su nombre o identificación. Revisa su ficha y valida con su documento.', 'arriendo-facil' ) );

		wp_send_json_success( array( 'message' => __( 'Solicitud enviada. Tu administrador la revisará.', 'arriendo-facil' ) ) );
	}
}
