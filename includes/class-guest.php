<?php
/**
 * Guest management (AI-assisted).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Guest
 *
 * Tenant (af_guests) domain services shared by the tenant controllers:
 * schema self-heal, private document storage and document reminders.
 * HTTP endpoints live in includes/tenants/.
 */
class Arriendo_Facil_Guest {

	/**
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'af_guest_reminders_cron', Arriendo_Facil_Job_Lock::guard( 'af_guest_reminders_cron', array( $this, 'dispatch_guest_reminders' ) ) );
	}

	/**
	 * Daily automatic reminders for guests.
	 *
	 * Emails tenants whose documents are still pending and reminds active
	 * tenants that their lease is about to end. Each email is throttled with
	 * an option timestamp so a guest is never spammed daily.
	 *
	 * @return void
	 */
	public function dispatch_guest_reminders() {
		if ( ! function_exists( 'wp_mail' ) ) {
			return;
		}

		global $wpdb;
		$now = time();

		// 1) Documento pendiente: avisa a inquilinos que aún no completan su
		// documentación desde hace al menos 3 días. Máximo una vez por semana.
		$doc_cutoff = gmdate( 'Y-m-d H:i:s', $now - 3 * DAY_IN_SECONDS );
		$guests     = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, first_name, last_name, email
				 FROM {$wpdb->prefix}af_guests
				 WHERE doc_status IN ('pendiente','rechazado')
				   AND created_at < %s
				   AND email <> ''
				 ORDER BY id DESC
				 LIMIT 100",
				$doc_cutoff
			)
		);

		$admin_url = admin_url( 'admin.php?page=af-guests&view=profile' );

		foreach ( $guests as $guest ) {
			$throttle_key = 'af_guest_doc_reminder_' . (int) $guest->id;
			$last_sent    = (int) get_option( $throttle_key, 0 );
			if ( $last_sent && ( $now - $last_sent ) < 7 * DAY_IN_SECONDS ) {
				continue;
			}

			$subject = __( '[ArriendoFacil] Documentacion pendiente de revisar', 'arriendo-facil' );
			$message = '<p>' . sprintf(
				esc_html__( 'Hola %s,', 'arriendo-facil' ),
				esc_html( trim( $guest->first_name . ' ' . $guest->last_name ) )
			) . '</p>';
			$message .= '<p>' . esc_html__( 'Aun no hemos completado la revision de tu documentacion para el arriendo. Nuestro equipo la esta tramitando; si necesitas enviar algun documento, responde a este correo.', 'arriendo-facil' ) . '</p>';
			$message .= '<p>' . esc_html__( 'Si ya los cargaste, este correo es solo un recordatorio para el equipo.', 'arriendo-facil' ) . '</p>';

			if ( wp_mail( $guest->email, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) ) ) {
				update_option( $throttle_key, $now );
			}
		}

		// El recordatorio al equipo operativo aparte (un solo correo global si
		// hay documentos pendientes) acelera el cierre comercial, no al inquilino.
		$operator_reminder_key = 'af_guest_doc_operator_reminder';
		$last_operator_sent    = (int) get_option( $operator_reminder_key, 0 );
		if ( count( $guests ) > 0 && ( ! $last_operator_sent || $last_operator_sent && ( $now - $last_operator_sent ) >= 7 * DAY_IN_SECONDS ) ) {
			$operator_emails = $this->get_operator_emails_for_guest_docs();
			if ( ! empty( $operator_emails ) ) {
				$subject = __( '[ArriendoFacil] Hay documentacion de inquilinos pendiente', 'arriendo-facil' );
				$message = '<p>' . esc_html__( 'El siguiente inquilino tiene documentacion pendiente de revisar:', 'arriendo-facil' ) . '</p><ul>';
				foreach ( $guests as $guest ) {
					$message .= '<li>' . esc_html( trim( $guest->first_name . ' ' . $guest->last_name ) ) . ' &lt;' . esc_html( $guest->email ) . '&gt;</li>';
				}
				$message .= '</ul><p>' . sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( $admin_url ),
					esc_html__( 'Ir a la ficha del inquilino', 'arriendo-facil' )
				) . '</p>';

				$sent_any = false;
				foreach ( $operator_emails as $recipient ) {
					if ( wp_mail( $recipient, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) ) ) {
						$sent_any = true;
					}
				}
				if ( $sent_any ) {
					update_option( $operator_reminder_key, $now );
				}
			}
		}

		// 2) Renovacion: avisa con 60 y 30 dias de anticipacion que el
		// contrato activo de un inquilino esta por vencer.
		$lease_table = $wpdb->prefix . 'af_leases';
		$renewal_cutoff = gmdate( 'Y-m-d', $now + 60 * DAY_IN_SECONDS );

		$leases = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.end_date, g.email, g.first_name, g.last_name
				 FROM {$lease_table} l
				 INNER JOIN {$wpdb->prefix}af_guests g ON g.id = l.guest_id
				 WHERE l.status = 'active'
				   AND l.end_date >= %s
				   AND l.end_date <= %s
				 ORDER BY l.end_date ASC
				 LIMIT 100",
				gmdate( 'Y-m-d' ),
				$renewal_cutoff
			)
		);

		foreach ( $leases as $lease ) {
			$throttle_key = 'af_lease_renewal_reminder_' . (int) $lease->id;
			$last_sent    = (int) get_option( $throttle_key, 0 );
			if ( $last_sent && ( $now - $last_sent ) < 30 * DAY_IN_SECONDS ) {
				continue;
			}

			$days_left = max( 0, (int) ( ( strtotime( $lease->end_date ) - $now ) / DAY_IN_SECONDS ) );
			$subject = __( '[ArriendoFacil] Tu contrato esta por vencer', 'arriendo-facil' );
			$message = '<p>' . sprintf(
				esc_html__( 'Hola %s,', 'arriendo-facil' ),
				esc_html( trim( $lease->first_name . ' ' . $lease->last_name ) )
			) . '</p>';
			$message .= '<p>' . sprintf(
				esc_html__( 'Tu contrato vence el %1$s. Si deseas renovarlo o tienes dudas, ponte en contacto con la administracion.', 'arriendo-facil' ),
				esc_html( mysql2date( get_option( 'date_format' ), $lease->end_date ) )
			) . '</p>';

			if ( wp_mail( $lease->email, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) ) ) {
				update_option( $throttle_key, $now );
			}
		}
	}

	/**
	 * Collects recipient addresses for operator-side reminders about pending guest docs.
	 *
	 * @return string[]
	 */
	private function get_operator_emails_for_guest_docs() {
		$emails = array();
		$users  = get_users(
			array(
				'role__in'        => array( 'administrator', 'af_property_admin' ),
				'fields'          => 'user_email',
				'number'          => 10,
				'search_columns'  => array( 'user_email' ),
			)
		);
		foreach ( $users as $email ) {
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}
		return array_unique( $emails );
	}

	/**
	 * Ensures required extra columns exist on guests table.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_guest_extra_columns() {
		global $wpdb;

		$table = $wpdb->prefix . 'af_guests';
		$columns = array(
			'accommodation_id'      => 'ALTER TABLE ' . $table . ' ADD COLUMN accommodation_id BIGINT(20) UNSIGNED DEFAULT NULL',
			'rental_mode'           => 'ALTER TABLE ' . $table . ' ADD COLUMN rental_mode VARCHAR(20) DEFAULT NULL',
			'rental_start_date'     => 'ALTER TABLE ' . $table . ' ADD COLUMN rental_start_date DATE DEFAULT NULL',
			'rental_end_date'       => 'ALTER TABLE ' . $table . ' ADD COLUMN rental_end_date DATE DEFAULT NULL',
			'rental_months'         => 'ALTER TABLE ' . $table . ' ADD COLUMN rental_months SMALLINT UNSIGNED DEFAULT NULL',
			'rental_years'          => 'ALTER TABLE ' . $table . ' ADD COLUMN rental_years SMALLINT UNSIGNED DEFAULT NULL',
			'desired_price'         => 'ALTER TABLE ' . $table . ' ADD COLUMN desired_price VARCHAR(100) DEFAULT NULL',
			'guarantee_text'        => 'ALTER TABLE ' . $table . ' ADD COLUMN guarantee_text VARCHAR(255) DEFAULT NULL',
			'mascotas'             => 'ALTER TABLE ' . $table . ' ADD COLUMN mascotas TINYINT UNSIGNED DEFAULT NULL',
			'referencia_personal_1'=> 'ALTER TABLE ' . $table . ' ADD COLUMN referencia_personal_1 VARCHAR(255) DEFAULT NULL',
			'referencia_personal_2'=> 'ALTER TABLE ' . $table . ' ADD COLUMN referencia_personal_2 VARCHAR(255) DEFAULT NULL',
			'personas_viviran'     => 'ALTER TABLE ' . $table . ' ADD COLUMN personas_viviran TINYINT UNSIGNED DEFAULT NULL',
			'phone_encrypted'      => 'ALTER TABLE ' . $table . ' ADD COLUMN phone_encrypted LONGTEXT DEFAULT NULL',
			'id_number_encrypted'  => 'ALTER TABLE ' . $table . ' ADD COLUMN id_number_encrypted LONGTEXT DEFAULT NULL',
			'nationality'          => 'ALTER TABLE ' . $table . ' ADD COLUMN nationality VARCHAR(100) DEFAULT NULL',
			'birth_city'           => 'ALTER TABLE ' . $table . ' ADD COLUMN birth_city VARCHAR(150) DEFAULT NULL',
		);

		foreach ( $columns as $column_name => $sql ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SHOW COLUMNS FROM {$table} LIKE %s",
					$column_name
				)
			);

			if ( $exists ) {
				continue;
			}

			$result = $wpdb->query( $sql );
			if ( false === $result ) {
				return new WP_Error( 'af_guest_schema_update_failed', __( 'No se pudo actualizar el esquema de la tabla de huespedes para campos adicionales.', 'arriendo-facil' ) );
			}
		}

		return true;
	}

	/**
	 * Uploads optional guest PDF documents and links them with metadata.
	 *
	 * @param int $guest_id Guest ID.
	 * @return array|WP_Error
	 */
	/**
	 * Uploads the guest's identity/support PDFs to private storage when
	 * configured (Cloudflare R2, never publicly reachable), falling back to
	 * the local WordPress media library only if no private storage is set up
	 * so document capture never breaks on a site without R2 credentials.
	 *
	 * @param int        $guest_id      Guest ID.
	 * @param array|null $raw_bytes_out Optional. Filled with doc_type => raw file
	 *                                  contents for callers that need to run a
	 *                                  best-effort check (e.g. identity cross-check)
	 *                                  without re-reading from storage. Never
	 *                                  returned to the client.
	 * @return array|WP_Error Map of doc_type => af_guest_documents row ID.
	 */
	public static function upload_guest_documents( $guest_id, &$raw_bytes_out = array() ) {
		$fields = array(
			'guest_garantia_alicuota_pdf'    => 'garantia_alicuota',
			'guest_cedula_papeleta_pdf'      => 'cedula_papeleta',
			'guest_certificado_bancario_pdf' => 'certificado_bancario',
			'guest_certificado_laboral_pdf'  => 'certificado_laboral',
		);

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		global $wpdb;
		$uploaded       = array();
		$raw_bytes_out  = array();

		foreach ( $fields as $field_name => $doc_type ) {
			if ( ! isset( $_FILES[ $field_name ] ) || ! is_array( $_FILES[ $field_name ] ) ) {
				continue;
			}

			$file_data  = $_FILES[ $field_name ];
			$file_error = isset( $file_data['error'] ) ? (int) $file_data['error'] : UPLOAD_ERR_NO_FILE;

			if ( UPLOAD_ERR_NO_FILE === $file_error ) {
				continue;
			}

			if ( UPLOAD_ERR_OK !== $file_error ) {
				return new WP_Error( 'af_guest_pdf_upload_error', __( 'No se pudo subir uno de los documentos PDF del huesped.', 'arriendo-facil' ) );
			}

			if ( ! empty( $file_data['size'] ) && (int) $file_data['size'] > ( 10 * 1024 * 1024 ) ) {
				return new WP_Error( 'af_guest_pdf_upload_too_large', __( 'El PDF del huesped supera el tamano maximo (10 MB).', 'arriendo-facil' ) );
			}

			$checked = wp_check_filetype_and_ext( $file_data['tmp_name'], $file_data['name'], array( 'pdf' => 'application/pdf' ) );
			if ( 'pdf' !== (string) $checked['ext'] ) {
				return new WP_Error( 'af_guest_pdf_invalid_type', __( 'Solo se permiten archivos PDF para documentos del huesped.', 'arriendo-facil' ) );
			}

			$contents = file_get_contents( $file_data['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $contents || '' === $contents ) {
				return new WP_Error( 'af_guest_pdf_read_failed', __( 'No se pudo leer uno de los documentos PDF del huesped.', 'arriendo-facil' ) );
			}

			$raw_bytes_out[ $doc_type ] = $contents;
			$checksum                  = hash( 'sha256', $contents );
			$storage                   = 'local';
			$object_key                = '';

			if ( Arriendo_Facil_Private_Storage::is_configured() ) {
				$object_key = 'guest-documents/' . (int) $guest_id . '/' . $doc_type . '-' . wp_generate_password( 12, false, false ) . '.pdf';
				$put_result = Arriendo_Facil_Private_Storage::upload( $contents, $object_key, 'application/pdf' );

				if ( is_wp_error( $put_result ) ) {
					return new WP_Error( 'af_guest_pdf_save_failed', __( 'No se pudo guardar uno de los documentos PDF del huesped en el almacenamiento privado.', 'arriendo-facil' ) );
				}

				$storage = 'r2';
			} else {
				// Fallback: sin credenciales de almacenamiento privado configuradas.
				// El archivo queda en la libreria de medios publica de WordPress —
				// menos seguro, pero evita que la captura de documentos se rompa.
				$attachment_id = media_handle_upload(
					$field_name,
					0,
					array( 'post_title' => sprintf( 'guest-%d-%s', (int) $guest_id, $doc_type ) ),
					array(
						'test_form' => false,
						'mimes'     => array( 'pdf' => 'application/pdf' ),
					)
				);

				if ( is_wp_error( $attachment_id ) ) {
					return new WP_Error( 'af_guest_pdf_save_failed', __( 'No se pudo guardar uno de los documentos PDF del huesped.', 'arriendo-facil' ) );
				}

				update_post_meta( (int) $attachment_id, '_af_guest_id', (int) $guest_id );
				update_post_meta( (int) $attachment_id, '_af_guest_doc_type', $doc_type );
				$object_key = (string) $attachment_id;
			}

			$wpdb->insert(
				$wpdb->prefix . 'af_guest_documents',
				array(
					'guest_id'        => (int) $guest_id,
					'doc_type'        => $doc_type,
					'storage'         => $storage,
					'object_key'      => $object_key,
					'mime_type'       => 'application/pdf',
					'file_size'       => strlen( $contents ),
					'checksum_sha256' => $checksum,
					'uploaded_by'     => get_current_user_id(),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d' )
			);

			$uploaded[ $doc_type ] = (int) $wpdb->insert_id;
		}

		return $uploaded;
	}

	/**
	 * Returns a guest record by ID.
	 *
	 * @param int $guest_id Guest ID.
	 * @return object|null Guest row or null.
	 */
	public function get_guest( $guest_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d", $guest_id )
		);
	}
}
