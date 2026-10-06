<?php
/**
 * Lease management.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Lease
 *
 * Manages lease records stored in the af_leases table and provides
 * AJAX endpoints for creating and updating leases.
 */
class Arriendo_Facil_Lease {

	/**
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_create_lease', array( $this, 'ajax_create_lease' ) );
		add_action( 'wp_ajax_af_update_lease', array( $this, 'ajax_update_lease' ) );
		add_action( 'wp_ajax_af_get_leases', array( $this, 'ajax_get_leases' ) );
		add_action( 'wp_ajax_af_download_lease_contract', array( $this, 'ajax_download_lease_contract' ) );
		add_action( 'wp_ajax_af_upload_lease_contract_version', array( $this, 'ajax_upload_lease_contract_version' ) );
		add_action( 'wp_ajax_af_approve_lease_contract', array( $this, 'ajax_approve_lease_contract' ) );
	}

	/**
	 * Approves active lease contract version and generates protected PDF.
	 */
	public function ajax_approve_lease_contract() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		if ( ! $lease_id || ! $this->get_lease( $lease_id ) ) {
			wp_send_json_error( array( 'message' => __( 'ID de contrato invalido.', 'arriendo-facil' ) ), 400 );
		}

		$pdf_password = $this->get_approved_pdf_password();

		$versions_data = $this->get_contract_versions( $lease_id );
		$active_version = isset( $versions_data['active_version'] ) ? absint( $versions_data['active_version'] ) : 0;
		$version_entry  = $this->find_version_entry( $versions_data['versions'], $active_version );

		if ( ! is_array( $version_entry ) ) {
			wp_send_json_error( array( 'message' => __( 'No se encontro una version de contrato para aprobar.', 'arriendo-facil' ) ), 400 );
		}

		if ( isset( $version_entry['approved_pdf'] ) && is_array( $version_entry['approved_pdf'] ) && ! empty( $version_entry['approved_pdf']['file_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'La version activa del contrato ya esta aprobada.', 'arriendo-facil' ) ), 400 );
		}

		$source = $this->read_contract_version_source( $version_entry );
		if ( is_wp_error( $source ) ) {
			wp_send_json_error( array( 'message' => $source->get_error_message() ), 400 );
		}

		$contract_text = $this->extract_text_from_contract_binary(
			isset( $source['contents'] ) ? (string) $source['contents'] : '',
			isset( $source['mime_type'] ) ? (string) $source['mime_type'] : ''
		);

		if ( '' === trim( $contract_text ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo leer el texto del contrato de la version activa. Sube una version DOCX e intenta nuevamente.', 'arriendo-facil' ) ), 400 );
		}

		$approved_pdf = $this->create_approved_pdf_for_version(
			$lease_id,
			isset( $version_entry['version'] ) ? absint( $version_entry['version'] ) : 1,
			$contract_text,
			$pdf_password
		);

		if ( is_wp_error( $approved_pdf ) ) {
			wp_send_json_error( array( 'message' => $approved_pdf->get_error_message() ), 500 );
		}

		$saved = $this->set_approved_pdf_for_version(
			$lease_id,
			isset( $version_entry['version'] ) ? absint( $version_entry['version'] ) : 1,
			$approved_pdf
		);

		if ( ! $saved ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar la metadata del PDF aprobado.', 'arriendo-facil' ) ), 500 );
		}

		$this->attach_document(
			$lease_id,
			add_query_arg(
				array(
					'action'   => 'af_download_lease_contract',
					'lease_id' => $lease_id,
				),
				admin_url( 'admin-ajax.php' )
			)
		);

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'af_leases',
			array( 'status' => 'active' ),
			array( 'id' => $lease_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( class_exists( 'Arriendo_Facil_Rental_Workflow' ) ) {
			$lease = $this->get_lease( $lease_id );
			if ( $lease && isset( $lease->accommodation_id ) ) {
				if ( class_exists( 'Arriendo_Facil_Occupancy' ) ) {
					Arriendo_Facil_Occupancy::mark_occupied( (int) $lease->accommodation_id );
				} else {
					Arriendo_Facil_Rental_Workflow::set_commercial_state( (int) $lease->accommodation_id, 'rented', 'private' );
				}
				Arriendo_Facil_Rental_Workflow::log_lease_event( $lease_id, (int) $lease->accommodation_id, 'lease_approved_active' );
			}
		}

		do_action( 'af_lease_activated', $lease_id );

		wp_send_json_success(
			array(
				'message' => __( 'Documento aprobado. El PDF protegido ya esta activo para ver y descargar.', 'arriendo-facil' ),
			)
		);
	}

	/**
	 * Uploads a manually edited Word file as a new contract version.
	 */
	public function ajax_upload_lease_contract_version() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		if ( ! $lease_id || ! $this->get_lease( $lease_id ) ) {
			wp_send_json_error( array( 'message' => __( 'ID de contrato invalido.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! isset( $_FILES['lease_contract_file'] ) || ! is_array( $_FILES['lease_contract_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Debes subir un archivo Word (.doc o .docx).', 'arriendo-facil' ) ), 400 );
		}

		$file_data = $_FILES['lease_contract_file'];
		$file_error = isset( $file_data['error'] ) ? (int) $file_data['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $file_error ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo subir el archivo seleccionado.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! empty( $file_data['size'] ) && (int) $file_data['size'] > ( 12 * 1024 * 1024 ) ) {
			wp_send_json_error( array( 'message' => __( 'El archivo Word supera el tamano maximo (12 MB).', 'arriendo-facil' ) ), 400 );
		}

		$allowed_mimes = array(
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		);

		$checked = wp_check_filetype_and_ext( $file_data['tmp_name'], $file_data['name'], $allowed_mimes );
		if ( ! in_array( (string) $checked['ext'], array( 'doc', 'docx' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Solo se permiten archivos Word (.doc, .docx).', 'arriendo-facil' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload(
			$file_data,
			array(
				'test_form' => false,
				'mimes'     => $allowed_mimes,
			)
		);

		if ( ! is_array( $upload ) || isset( $upload['error'] ) ) {
			$error_msg = is_array( $upload ) && isset( $upload['error'] ) ? (string) $upload['error'] : __( 'No se pudo guardar el archivo Word cargado.', 'arriendo-facil' );
			wp_send_json_error( array( 'message' => $error_msg ) );
		}

		$file_path = isset( $upload['file'] ) ? (string) $upload['file'] : '';
		$file_url  = isset( $upload['url'] ) ? esc_url_raw( (string) $upload['url'] ) : '';
		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			wp_send_json_error( array( 'message' => __( 'El archivo cargado no existe en el servidor.', 'arriendo-facil' ) ) );
		}

		$versions_data = $this->get_contract_versions( $lease_id );
		$next_version  = count( isset( $versions_data['versions'] ) && is_array( $versions_data['versions'] ) ? $versions_data['versions'] : array() ) + 1;

		$final_document_url = $file_url;
		$storage_meta       = array(
			'provider'  => 'local',
			'file_name' => wp_basename( $file_path ),
			'local_url' => $file_url,
			'mime_type' => (string) $checked['type'],
		);

		if ( Arriendo_Facil_Private_Storage::is_r2_enabled() ) {
			$contents = file_get_contents( $file_path );
			if ( false !== $contents ) {
				$safe_name     = sanitize_file_name( wp_basename( $file_path ) );
				$object_key    = sprintf( 'lease-contracts/%d/v%d/%s', $lease_id, $next_version, $safe_name );
				$contract_mime = '' !== (string) $checked['type'] ? (string) $checked['type'] : Arriendo_Facil_Contract_Text_Extractor::DOCX_MIME;
				$upload_r2     = Arriendo_Facil_Private_Storage::upload( $contents, $object_key, $contract_mime );

				if ( ! is_wp_error( $upload_r2 ) ) {
					$final_document_url = Arriendo_Facil_Contract_File_Store::download_url( $lease_id );
					$storage_meta       = array(
						'provider'   => Arriendo_Facil_Private_Storage::PROVIDER_R2,
						'object_key' => $object_key,
						'file_name'  => $safe_name,
						'local_url'  => '',
						'mime_type'  => $contract_mime,
					);
					Arriendo_Facil_Contract_File_Store::discard_local_copy( $file_path );
				}
			}
		}

		$this->set_contract_storage_meta( $lease_id, $storage_meta );
		$this->attach_document(
			$lease_id,
			add_query_arg(
				array(
					'action'   => 'af_download_lease_contract',
					'lease_id' => $lease_id,
				),
				admin_url( 'admin-ajax.php' )
			)
		);

		wp_send_json_success(
			array(
				'message' => sprintf( __( 'Subido como version v%d.', 'arriendo-facil' ), $next_version ),
				'version' => $next_version,
				'url'     => $final_document_url,
			)
		);
	}

	/**
	 * Creates a new lease record via AJAX.
	 */
	public function ajax_create_lease() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( $_POST['accommodation_id'] ) : 0;
		$guest_id         = isset( $_POST['guest_id'] ) ? absint( $_POST['guest_id'] ) : 0;
		$start_date       = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date         = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$monthly_rent     = isset( $_POST['monthly_rent'] ) ? floatval( wp_unslash( $_POST['monthly_rent'] ) ) : 0.0;
		$deposit_amount   = isset( $_POST['deposit_amount'] ) ? max( 0, floatval( wp_unslash( $_POST['deposit_amount'] ) ) ) : 0.0;
		$payment_due_day  = isset( $_POST['payment_due_day'] ) ? absint( $_POST['payment_due_day'] ) : 0;
		$payment_due_day  = ( $payment_due_day >= 1 && $payment_due_day <= 28 ) ? $payment_due_day : 0;
		$template_attachment_id = isset( $_POST['template_attachment_id'] ) ? absint( $_POST['template_attachment_id'] ) : 0;

		if ( ! $accommodation_id || ! $guest_id || ! $start_date || ! $end_date ) {
			wp_send_json_error( array( 'message' => __( 'Faltan campos obligatorios.', 'arriendo-facil' ) ) );
		}

		// ── Procesar Placeholders ─────────────────────────────────────────────
		$placeholders = array();
		if ( class_exists( 'Arriendo_Facil_Contract_Storage' ) ) {
			// Capturar todos los campos placeholder_* del POST
			foreach ( $_POST as $key => $value ) {
				if ( strpos( $key, 'placeholder_' ) === 0 ) {
					$placeholder_key = substr( $key, strlen( 'placeholder_' ) );
					$placeholders[ $placeholder_key ] = sanitize_text_field( wp_unslash( $value ) );
				}
			}

			// Fecha de firma, espejos de los campos base del formulario y
			// valores derivados: nunca se piden al operador, se calculan aquí.
			$placeholders = Arriendo_Facil_Contract_Storage::with_defaults(
				$placeholders,
				$start_date,
				$end_date,
				$monthly_rent,
				$deposit_amount
			);

			// Validar placeholders contra el schema
			if ( ! empty( $placeholders ) ) {
				$validation = Arriendo_Facil_Contract_Storage::validate_placeholders( $placeholders );
				if ( ! $validation['valid'] ) {
					wp_send_json_error(
						array(
							'message' => __( 'Errores en Datos Avanzados:', 'arriendo-facil' ),
							'errors'  => $validation['errors'],
						)
					);
				}
			}
		}

		// Optional idempotency guard: activates only when the client sends the key.
		$idempotency_key = Arriendo_Facil_Idempotency::key_from_request();
		if ( null !== $idempotency_key ) {
			$scope         = 'af_create_lease_' . get_current_user_id();
			$fingerprint   = Arriendo_Facil_Idempotency::fingerprint(
				array(
					'accommodation_id' => $accommodation_id,
					'guest_id'         => $guest_id,
					'start_date'       => $start_date,
					'end_date'         => $end_date,
					'monthly_rent'     => $monthly_rent,
					'deposit_amount'   => $deposit_amount,
					'payment_due_day'  => $payment_due_day,
					'placeholders'     => $placeholders,
				)
			);
			$idem_response = Arriendo_Facil_Idempotency::remember(
				$scope,
				$idempotency_key,
				DAY_IN_SECONDS,
				function () use ( $accommodation_id, $guest_id, $start_date, $end_date, $monthly_rent, $deposit_amount, $payment_due_day, $template_attachment_id, $placeholders ) {
					return $this->insert_lease_record( $accommodation_id, $guest_id, $start_date, $end_date, $monthly_rent, $deposit_amount, $payment_due_day, $template_attachment_id, $placeholders );
				},
				$fingerprint
			);

			if ( Arriendo_Facil_Idempotency::is_in_flight( $idem_response ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Solicitud en progreso.', 'arriendo-facil' ), 'code' => 'request_locked' ),
					429
				);
			}
			if ( Arriendo_Facil_Idempotency::is_conflict( $idem_response ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Idempotency-Key reutilizada con datos distintos.', 'arriendo-facil' ), 'code' => 'idempotency_conflict' ),
					422
				);
			}

			if ( is_array( $idem_response ) && ! empty( $idem_response['id'] ) ) {
				wp_send_json_success( array( 'id' => (int) $idem_response['id'] ) );
			}
			wp_send_json_error( array( 'message' => __( 'No se pudo crear el contrato.', 'arriendo-facil' ) ) );
		}

		$result = $this->insert_lease_record( $accommodation_id, $guest_id, $start_date, $end_date, $monthly_rent, $deposit_amount, $payment_due_day, $template_attachment_id, $placeholders );
		if ( is_array( $result ) && ! empty( $result['id'] ) ) {
			wp_send_json_success( array( 'id' => (int) $result['id'] ) );
		}
		wp_send_json_error( array( 'message' => __( 'No se pudo crear el contrato.', 'arriendo-facil' ) ) );
	}

	/**
	 * Inserts the lease row and sets side-effects. Returns ['id' => int] on success, [] on failure.
	 *
	 * @param int    $accommodation_id Accommodation ID.
	 * @param int    $guest_id         Guest ID.
	 * @param string $start_date       Start date (Y-m-d).
	 * @param string $end_date         End date (Y-m-d).
	 * @param float  $monthly_rent     Monthly rent.
	 * @param float  $deposit_amount   Refundable deposit received.
	 * @param int    $payment_due_day  Monthly payment due day (1-28), 0 = not set.
	 * @param int    $template_attachment_id Owner DOCX template to use for the contract, 0 = latest.
	 * @param array  $placeholders     Optional array of placeholder values to store in meta.
	 * @return array
	 */
	private function insert_lease_record( int $accommodation_id, int $guest_id, string $start_date, string $end_date, float $monthly_rent, float $deposit_amount = 0.0, int $payment_due_day = 0, int $template_attachment_id = 0, array $placeholders = array() ): array {
		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'af_leases',
			array(
				'accommodation_id' => $accommodation_id,
				'guest_id'         => $guest_id,
				'start_date'       => $start_date,
				'end_date'         => $end_date,
				'monthly_rent'     => $monthly_rent,
				'deposit_amount'   => $deposit_amount,
				'payment_due_day'  => $payment_due_day ? $payment_due_day : null,
				'template_attachment_id' => $template_attachment_id ? $template_attachment_id : null,
				'status'           => 'draft',
			),
			array( '%d', '%d', '%s', '%s', '%f', '%f', '%d', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return array();
		}

		$new_id = (int) $wpdb->insert_id;

		// ── Guardar Placeholders ─────────────────────────────────────────────
		if ( ! empty( $placeholders ) ) {
			// Scoped by lease id in a single option: writing them as post meta
			// under the lease id collided with real posts of the same id.
			$all = get_option( 'af_contract_placeholders_by_lease', array() );
			if ( ! is_array( $all ) ) {
				$all = array();
			}
			$all[ $new_id ] = $placeholders;
			update_option( 'af_contract_placeholders_by_lease', $all, false );
		}

		// ── Auto-vinculación: Inquilino → Inmueble ───────────────────────────
		// El inquilino queda ligado al inmueble elegido para que la próxima vez
		// que se abra el formulario se autocompleten sus datos y los del
		// inmueble sin tener que volver a elegirlos.
		if ( $guest_id > 0 ) {
			$guest_row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, accommodation_id, first_name, last_name, id_number, nationality, rental_start_date, rental_end_date
					 FROM ' . $wpdb->prefix . 'af_guests WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$guest_id
				)
			);
			if ( $guest_row && (int) $guest_row->accommodation_id !== (int) $accommodation_id ) {
				$wpdb->update(
					$wpdb->prefix . 'af_guests',
					array( 'accommodation_id' => (int) $accommodation_id ),
					array( 'id' => (int) $guest_id ),
					array( '%d' ),
					array( '%d' )
				);
			}

			// ── Retro-completar la ficha del inquilino ─────────────────────
			// Si el inquilino quedó registrado sin algunos datos (nacionalidad,
			// cédula, fechas, etc.) y el operador los dejó al crear el contrato,
			// se copian a su ficha. Solo se llenan huecos: nunca se pisa un dato
			// que el inquilino ya tenga.
			if ( $guest_row ) {
				$guest_updates = array();

				$guest_name   = isset( $placeholders['nombres_inquilino'] ) ? trim( (string) $placeholders['nombres_inquilino'] ) : '';
				$guest_cedula = isset( $placeholders['cedula_inquilino'] ) ? trim( (string) $placeholders['cedula_inquilino'] ) : '';
				$guest_nac    = isset( $placeholders['nacionalidad_inquilino'] ) ? trim( (string) $placeholders['nacionalidad_inquilino'] ) : '';

				if ( '' !== $guest_name ) {
					$name_parts = preg_split( '/\s+/', $guest_name, 2 );
					if ( false === $name_parts ) {
						$name_parts = array();
					}
					if ( isset( $name_parts[0] ) && '' === (string) $guest_row->first_name ) {
						$guest_updates['first_name'] = $name_parts[0];
					}
					// Apellido: solo si falta y no está ya incluido en el nombre
					// guardado (evita "Maria Diaz" -> "Maria Diaz Diaz").
					$last_name_candidate = isset( $name_parts[1] ) ? $name_parts[1] : '';
					if ( '' !== $last_name_candidate && '' === (string) $guest_row->last_name ) {
						$full_in_first_name = false !== mb_stripos( (string) $guest_row->first_name, $last_name_candidate );
						if ( ! $full_in_first_name ) {
							$guest_updates['last_name'] = $last_name_candidate;
						}
					}
				}

				if ( '' === (string) $guest_row->id_number && '' !== $guest_cedula ) {
					$guest_updates['id_number'] = $guest_cedula;
				}

				if ( '' === (string) $guest_row->nationality && '' !== $guest_nac ) {
					$guest_updates['nationality'] = $guest_nac;
				}

				if ( '' === (string) $guest_row->rental_start_date && '' !== $start_date ) {
					$guest_updates['rental_start_date'] = $start_date;
				}

				if ( '' === (string) $guest_row->rental_end_date && '' !== $end_date ) {
					$guest_updates['rental_end_date'] = $end_date;
				}

				if ( ! empty( $guest_updates ) ) {
					$wpdb->update(
						$wpdb->prefix . 'af_guests',
						$guest_updates,
						array( 'id' => (int) $guest_id ),
						array_fill( 0, count( $guest_updates ), '%s' ),
						array( '%d' )
					);
				}
			}
		}

		if ( class_exists( 'Arriendo_Facil_Occupancy' ) ) {
			Arriendo_Facil_Occupancy::mark_occupied( $accommodation_id );
		} else {
			update_post_meta( $accommodation_id, '_af_is_occupied', '1' );
		}
		Arriendo_Facil_Contract_Generator::schedule( $new_id );
		return array( 'id' => $new_id );
	}

	/**
	 * Updates an existing lease via AJAX.
	 */
	public function ajax_update_lease() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		$allowed_statuses = array( 'draft', 'active', 'expired', 'terminated', 'pending_release' );
		if ( ! $lease_id || ! in_array( $status, $allowed_statuses, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Datos invalidos.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'af_leases',
			array( 'status' => $status ),
			array( 'id' => $lease_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			if ( class_exists( 'Arriendo_Facil_Occupancy' ) ) {
				$lease = $this->get_lease( $lease_id );
				if ( $lease && isset( $lease->accommodation_id ) ) {
					Arriendo_Facil_Occupancy::sync_from_leases( (int) $lease->accommodation_id );
				}
			}
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'No se pudo actualizar el contrato.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Returns leases for a given accommodation via AJAX.
	 */
	public function ajax_get_leases() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_GET['accommodation_id'] ) ? absint( $_GET['accommodation_id'] ) : 0;

		if ( Arriendo_Facil_Accommodation::user_is_owner() ) {
			$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
			if ( ! in_array( $accommodation_id, $owner_ids, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
			}
		}

		global $wpdb;
		$leases = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_leases WHERE accommodation_id = %d ORDER BY start_date DESC",
				$accommodation_id
			)
		);

		wp_send_json_success( $leases );
	}

	/**
	 * Returns a single lease by ID.
	 *
	 * @param int $lease_id Lease ID.
	 * @return object|null Lease object or null.
	 */
	public function get_lease( $lease_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_leases WHERE id = %d AND deleted_at IS NULL",
				$lease_id
			)
		);
	}

	/**
	 * Resolves the currently active lease for an accommodation.
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return int Lease ID, or 0 when no active lease exists.
	 */
	public static function get_active_lease_id_for_accommodation( $accommodation_id ) {
		global $wpdb;

		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}af_leases
				 WHERE accommodation_id = %d AND status = 'active' AND deleted_at IS NULL
				 ORDER BY id DESC LIMIT 1",
				$accommodation_id
			)
		);
	}

	/**
	 * Attaches a generated document URL to a lease.
	 *
	 * @param int    $lease_id     Lease ID.
	 * @param string $document_url URL of the generated document.
	 * @return bool True on success.
	 */
	public function attach_document( $lease_id, $document_url ) {
		global $wpdb;
		return (bool) $wpdb->update(
				$wpdb->prefix . 'af_leases',
			array( 'document_url' => esc_url_raw( $document_url ) ),
			array( 'id' => absint( $lease_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Ensures a lease has a document, generating it synchronously if missing.
	 * Screens should call Arriendo_Facil_Contract_Generator::schedule() instead.
	 *
	 * @param int $lease_id Lease ID.
	 * @return bool
	 */
	public function ensure_lease_document_available( $lease_id ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id || ! $this->get_lease( $lease_id ) ) {
			return false;
		}

		$generator = new Arriendo_Facil_Contract_Generator();
		if ( $generator->lease_has_document( $lease_id ) ) {
			return true;
		}

		$result = $generator->generate_for_lease( $lease_id );
		return ! empty( $result['generated'] );
	}

	/**
	 * Saves storage metadata for a lease contract.
	 *
	 * @param int   $lease_id Lease ID.
	 * @param array $meta Storage metadata.
	 * @return bool
	 */
	public function set_contract_storage_meta( $lease_id, array $meta ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return false;
		}

		$option_name = $this->get_contract_storage_option_name( $lease_id );
		$clean_meta  = array(
			'provider'   => isset( $meta['provider'] ) ? sanitize_key( (string) $meta['provider'] ) : '',
			'object_key' => isset( $meta['object_key'] ) ? sanitize_text_field( (string) $meta['object_key'] ) : '',
			'mime_type'  => isset( $meta['mime_type'] ) ? sanitize_text_field( (string) $meta['mime_type'] ) : '',
			'file_name'  => isset( $meta['file_name'] ) ? sanitize_file_name( (string) $meta['file_name'] ) : '',
			'local_url'  => isset( $meta['local_url'] ) ? esc_url_raw( (string) $meta['local_url'] ) : '',
			'updated_at' => current_time( 'mysql' ),
			'created_by' => get_current_user_id(),
		);

		$stored = get_option( $option_name, false );

		// Backward compatibility: migrate legacy flat metadata into versioned structure.
		if ( is_array( $stored ) && isset( $stored['provider'] ) && ! isset( $stored['versions'] ) ) {
			$legacy_created_at = isset( $stored['updated_at'] ) ? sanitize_text_field( (string) $stored['updated_at'] ) : current_time( 'mysql' );
			$stored            = array(
				'active_version' => 1,
				'versions'       => array(
					array(
						'version'    => 1,
						'provider'   => sanitize_key( (string) ( $stored['provider'] ?? '' ) ),
						'object_key' => sanitize_text_field( (string) ( $stored['object_key'] ?? '' ) ),
						'mime_type'  => sanitize_text_field( (string) ( $stored['mime_type'] ?? '' ) ),
						'file_name'  => sanitize_file_name( (string) ( $stored['file_name'] ?? '' ) ),
						'local_url'  => esc_url_raw( (string) ( $stored['local_url'] ?? '' ) ),
						'created_at' => $legacy_created_at,
						'created_by' => absint( $stored['created_by'] ?? 0 ),
					),
				),
			);
		}

		if ( ! is_array( $stored ) || ! isset( $stored['versions'] ) || ! is_array( $stored['versions'] ) ) {
			$stored = array(
				'active_version' => 0,
				'versions'       => array(),
			);
		}

		$next_version = count( $stored['versions'] ) + 1;
		$new_version  = array(
			'version'    => $next_version,
			'provider'   => $clean_meta['provider'],
			'object_key' => $clean_meta['object_key'],
			'mime_type'  => $clean_meta['mime_type'],
			'file_name'  => $clean_meta['file_name'],
			'local_url'  => $clean_meta['local_url'],
			'created_at' => $clean_meta['updated_at'],
			'created_by' => absint( $clean_meta['created_by'] ),
		);

		$stored['versions'][]    = $new_version;
		$stored['active_version'] = $next_version;

		if ( false === get_option( $option_name, false ) ) {
			return add_option( $option_name, $stored, '', false );
		}

		return update_option( $option_name, $stored, false );
	}

	/**
	 * Returns storage metadata for a lease contract.
	 *
	 * @param int $lease_id Lease ID.
	 * @return array<string,string>
	 */
	public function get_contract_storage_meta( $lease_id ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return array();
		}

		$meta = get_option( $this->get_contract_storage_option_name( $lease_id ), array() );
		if ( ! is_array( $meta ) ) {
			return array();
		}

		// If already versioned, expose active version fields for compatibility.
		if ( isset( $meta['versions'] ) && is_array( $meta['versions'] ) ) {
			$active_version_number = isset( $meta['active_version'] ) ? absint( $meta['active_version'] ) : 0;
			$active_version        = $this->find_version_entry( $meta['versions'], $active_version_number );
			if ( ! $active_version && ! empty( $meta['versions'] ) ) {
				$active_version = end( $meta['versions'] );
			}

			if ( is_array( $active_version ) ) {
				$meta['provider']   = isset( $active_version['provider'] ) ? sanitize_key( (string) $active_version['provider'] ) : '';
				$meta['object_key'] = isset( $active_version['object_key'] ) ? sanitize_text_field( (string) $active_version['object_key'] ) : '';
				$meta['mime_type']  = isset( $active_version['mime_type'] ) ? sanitize_text_field( (string) $active_version['mime_type'] ) : '';
				$meta['file_name']  = isset( $active_version['file_name'] ) ? sanitize_file_name( (string) $active_version['file_name'] ) : '';
				$meta['local_url']  = isset( $active_version['local_url'] ) ? esc_url_raw( (string) $active_version['local_url'] ) : '';
			}
		}

		return $meta;
	}

	/**
	 * Returns normalized contract versions and active version.
	 *
	 * @param int $lease_id Lease ID.
	 * @return array<string,mixed>
	 */
	public function get_contract_versions( $lease_id ) {
		$meta = $this->get_contract_storage_meta( $lease_id );
		if ( empty( $meta ) ) {
			return array(
				'active_version' => 0,
				'versions'       => array(),
			);
		}

		if ( isset( $meta['versions'] ) && is_array( $meta['versions'] ) ) {
			$versions = array();
			foreach ( $meta['versions'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$approved_pdf = array();
				if ( isset( $entry['approved_pdf'] ) && is_array( $entry['approved_pdf'] ) ) {
					$approved_pdf = array(
						'provider'   => isset( $entry['approved_pdf']['provider'] ) ? sanitize_key( (string) $entry['approved_pdf']['provider'] ) : '',
						'object_key' => isset( $entry['approved_pdf']['object_key'] ) ? sanitize_text_field( (string) $entry['approved_pdf']['object_key'] ) : '',
						'mime_type'  => isset( $entry['approved_pdf']['mime_type'] ) ? sanitize_text_field( (string) $entry['approved_pdf']['mime_type'] ) : 'application/pdf',
						'file_name'  => isset( $entry['approved_pdf']['file_name'] ) ? sanitize_file_name( (string) $entry['approved_pdf']['file_name'] ) : '',
						'local_url'  => isset( $entry['approved_pdf']['local_url'] ) ? esc_url_raw( (string) $entry['approved_pdf']['local_url'] ) : '',
						'approved_at' => isset( $entry['approved_pdf']['approved_at'] ) ? sanitize_text_field( (string) $entry['approved_pdf']['approved_at'] ) : '',
						'approved_by' => isset( $entry['approved_pdf']['approved_by'] ) ? absint( $entry['approved_pdf']['approved_by'] ) : 0,
					);
				}

				$versions[] = array(
					'version'    => isset( $entry['version'] ) ? absint( $entry['version'] ) : 0,
					'provider'   => isset( $entry['provider'] ) ? sanitize_key( (string) $entry['provider'] ) : '',
					'object_key' => isset( $entry['object_key'] ) ? sanitize_text_field( (string) $entry['object_key'] ) : '',
					'mime_type'  => isset( $entry['mime_type'] ) ? sanitize_text_field( (string) $entry['mime_type'] ) : '',
					'file_name'  => isset( $entry['file_name'] ) ? sanitize_file_name( (string) $entry['file_name'] ) : '',
					'local_url'  => isset( $entry['local_url'] ) ? esc_url_raw( (string) $entry['local_url'] ) : '',
					'created_at' => isset( $entry['created_at'] ) ? sanitize_text_field( (string) $entry['created_at'] ) : '',
					'created_by' => isset( $entry['created_by'] ) ? absint( $entry['created_by'] ) : 0,
					'approved_pdf' => $approved_pdf,
				);
			}

			return array(
				'active_version' => isset( $meta['active_version'] ) ? absint( $meta['active_version'] ) : 0,
				'versions'       => $versions,
			);
		}

		// Legacy flat structure -> virtual v1.
		return array(
			'active_version' => 1,
			'versions'       => array(
				array(
					'version'    => 1,
					'provider'   => isset( $meta['provider'] ) ? sanitize_key( (string) $meta['provider'] ) : '',
					'object_key' => isset( $meta['object_key'] ) ? sanitize_text_field( (string) $meta['object_key'] ) : '',
					'mime_type'  => isset( $meta['mime_type'] ) ? sanitize_text_field( (string) $meta['mime_type'] ) : '',
					'file_name'  => isset( $meta['file_name'] ) ? sanitize_file_name( (string) $meta['file_name'] ) : '',
					'local_url'  => isset( $meta['local_url'] ) ? esc_url_raw( (string) $meta['local_url'] ) : '',
					'created_at' => isset( $meta['updated_at'] ) ? sanitize_text_field( (string) $meta['updated_at'] ) : '',
					'created_by' => isset( $meta['created_by'] ) ? absint( $meta['created_by'] ) : 0,
				),
			),
		);
	}

	/**
	 * Downloads a lease contract via secure redirect.
	 *
	 * Defense (OWASP A01 Broken Access Control / CSRF):
	 * - Nonce check first: prevents CSRF that would leak short-lived R2 presigned
	 *   URLs to attacker-controlled sites via redirect chain.
	 * - Capability check: only editors/admins.
	 * - Ownership check: owners are restricted to their own accommodations.
	 *   Users with `manage_options` (site admins) bypass ownership by design.
	 */
	public function ajax_download_lease_contract() {
		if ( false === check_ajax_referer( 'af_lease_nonce', 'nonce', false ) ) {
			$actor = get_current_user_id();
			error_log( sprintf( '[AF Security] lease_download invalid nonce (user=%d)', $actor ) );
			wp_die( esc_html__( 'Solicitud invalida.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permiso denegado.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}

		$lease_id = isset( $_GET['lease_id'] ) ? absint( wp_unslash( $_GET['lease_id'] ) ) : 0;
		if ( ! $lease_id ) {
			wp_die( esc_html__( 'ID de contrato invalido.', 'arriendo-facil' ), 400 );
		}

		$lease = $this->get_lease( $lease_id );
		if ( ! $lease ) {
			wp_die( esc_html__( 'Contrato no encontrado.', 'arriendo-facil' ), 404 );
		}

		if ( Arriendo_Facil_Accommodation::user_is_owner() ) {
			$owner_ids = Arriendo_Facil_Accommodation::get_owner_accommodation_ids( get_current_user_id() );
			if ( ! in_array( (int) $lease->accommodation_id, $owner_ids, true ) ) {
				wp_die( esc_html__( 'Permiso denegado.', 'arriendo-facil' ), 403 );
			}
		}

		$requested_version = isset( $_GET['version'] ) ? absint( wp_unslash( $_GET['version'] ) ) : 0;
		$versions_data     = $this->get_contract_versions( $lease_id );
		$version_entry     = $this->find_version_entry( $versions_data['versions'], $requested_version );
		if ( ! $version_entry ) {
			$active_version = isset( $versions_data['active_version'] ) ? absint( $versions_data['active_version'] ) : 0;
			$version_entry  = $this->find_version_entry( $versions_data['versions'], $active_version );
		}

		if ( is_array( $version_entry ) && isset( $version_entry['approved_pdf'] ) && is_array( $version_entry['approved_pdf'] ) ) {
			$approved_provider   = isset( $version_entry['approved_pdf']['provider'] ) ? sanitize_key( (string) $version_entry['approved_pdf']['provider'] ) : '';
			$approved_object_key = isset( $version_entry['approved_pdf']['object_key'] ) ? sanitize_text_field( (string) $version_entry['approved_pdf']['object_key'] ) : '';
			$approved_local_url  = isset( $version_entry['approved_pdf']['local_url'] ) ? esc_url_raw( (string) $version_entry['approved_pdf']['local_url'] ) : '';

			if ( 'cloudflare_r2' === $approved_provider && '' !== $approved_object_key ) {
				$presigned_url = Arriendo_Facil_Private_Storage::presigned_get_url( $approved_object_key, 600 );
				if ( ! is_wp_error( $presigned_url ) && is_string( $presigned_url ) && '' !== $presigned_url ) {
					$this->redirect_to_contract_url( $presigned_url );
				}
			}

			if ( '' !== $approved_local_url ) {
				$this->redirect_to_contract_url( $approved_local_url );
			}
		}

		if ( is_array( $version_entry ) && isset( $version_entry['provider'], $version_entry['object_key'] ) && 'cloudflare_r2' === $version_entry['provider'] && '' !== trim( (string) $version_entry['object_key'] ) ) {
			$presigned_url = Arriendo_Facil_Private_Storage::presigned_get_url( (string) $version_entry['object_key'], 600 );
			if ( ! is_wp_error( $presigned_url ) && is_string( $presigned_url ) && '' !== $presigned_url ) {
				$this->redirect_to_contract_url( $presigned_url );
			}
		}

		if ( is_array( $version_entry ) && isset( $version_entry['local_url'] ) && '' !== trim( (string) $version_entry['local_url'] ) ) {
			$this->redirect_to_contract_url( (string) $version_entry['local_url'] );
		}

		$document_url = isset( $lease->document_url ) ? esc_url_raw( (string) $lease->document_url ) : '';
		if ( '' !== $document_url ) {
			$this->redirect_to_contract_url( $document_url );
		}

		wp_die( esc_html__( 'El documento del contrato no esta disponible.', 'arriendo-facil' ), 404 );
	}

	/**
	 * Builds option name for lease contract storage metadata.
	 *
	 * @param int $lease_id Lease ID.
	 * @return string
	 */
	private function get_contract_storage_option_name( $lease_id ) {
		return 'af_lease_contract_storage_' . absint( $lease_id );
	}

	/**
	 * Finds a version entry by version number.
	 *
	 * @param array $versions Version list.
	 * @param int   $version  Version number.
	 * @return array|null
	 */
	private function find_version_entry( $versions, $version ) {
		if ( ! is_array( $versions ) || empty( $versions ) ) {
			return null;
		}

		$version = absint( $version );
		if ( $version < 1 ) {
			$last = end( $versions );
			return is_array( $last ) ? $last : null;
		}

		foreach ( $versions as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( isset( $entry['version'] ) && absint( $entry['version'] ) === $version ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Redirects to contract URL allowing signed external storage links.
	 *
	 * @param string $url Target URL.
	 * @return void
	 */
	private function redirect_to_contract_url( $url ) {
		$target_url = esc_url_raw( (string) $url );
		if ( '' === $target_url ) {
			wp_die( esc_html__( 'URL de contrato invalida.', 'arriendo-facil' ), 400 );
		}

		wp_redirect( $target_url, 302, 'Arriendo Facil' );
		exit;
	}

	/**
	 * Reads source bytes for a version from R2 or local URL.
	 *
	 * @param array $version_entry Version metadata.
	 * @return array|WP_Error
	 */
	private function read_contract_version_source( array $version_entry ) {
		$provider   = isset( $version_entry['provider'] ) ? sanitize_key( (string) $version_entry['provider'] ) : '';
		$object_key = isset( $version_entry['object_key'] ) ? sanitize_text_field( (string) $version_entry['object_key'] ) : '';
		$local_url  = isset( $version_entry['local_url'] ) ? esc_url_raw( (string) $version_entry['local_url'] ) : '';
		$mime_type  = isset( $version_entry['mime_type'] ) ? sanitize_text_field( (string) $version_entry['mime_type'] ) : '';

		if ( 'cloudflare_r2' === $provider && '' !== $object_key ) {
			$body = Arriendo_Facil_Private_Storage::download( $object_key, 45 );
			if ( is_wp_error( $body ) ) {
				return new WP_Error( 'af_lease_source_download_failed', __( 'No se pudo descargar la version activa del contrato desde almacenamiento privado.', 'arriendo-facil' ) );
			}

			return array(
				'contents'  => $body,
				'mime_type' => $mime_type,
			);
		}

		if ( '' !== $local_url ) {
			$path = $this->resolve_upload_url_to_path( $local_url );
			if ( '' !== $path && file_exists( $path ) ) {
				$contents = file_get_contents( $path );
				if ( false !== $contents && '' !== $contents ) {
					return array(
						'contents'  => $contents,
						'mime_type' => $mime_type,
					);
				}
			}
		}

		return new WP_Error( 'af_lease_source_unavailable', __( 'No se pudo acceder al archivo fuente de la version activa del contrato.', 'arriendo-facil' ) );
	}

	/**
	 * Converts DOCX/text contract binary to plain text.
	 *
	 * @param string $contents Binary file contents.
	 * @param string $mime_type Source mime type.
	 * @return string
	 */
	private function extract_text_from_contract_binary( $contents, $mime_type ) {
		$mime_type = strtolower( (string) $mime_type );
		$contents  = (string) $contents;

		if ( '' === $contents ) {
			return '';
		}

		if ( false !== strpos( $mime_type, 'text/' ) ) {
			$text = wp_strip_all_tags( $contents );
			$text = preg_replace( '/\s+\n/', "\n", (string) $text );
			return trim( (string) $text );
		}

		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime_type && class_exists( 'ZipArchive' ) ) {
			$temp_file = wp_tempnam( 'af-lease-docx' );
			if ( ! $temp_file ) {
				return '';
			}

			file_put_contents( $temp_file, $contents );
			$zip = new ZipArchive();
			if ( true !== $zip->open( $temp_file ) ) {
				@unlink( $temp_file );
				return '';
			}

			$xml = $zip->getFromName( 'word/document.xml' );
			$zip->close();
			@unlink( $temp_file );

			if ( false === $xml || '' === $xml ) {
				return '';
			}

			$prepared = str_replace( array( '</w:p>', '</w:tr>', '</w:tbl>' ), "\n", (string) $xml );
			$text     = wp_strip_all_tags( $prepared );
			$text     = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
			$text     = preg_replace( "/\r\n|\r/", "\n", (string) $text );
			$text     = preg_replace( '/\n{3,}/', "\n\n", (string) $text );

			return trim( (string) $text );
		}

		// Legacy .doc binaries are not consistently parseable without external libraries.
		return '';
	}

	/**
	 * Creates protected PDF for an approved lease contract version.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param int    $version Version number.
	 * @param string $contract_text Contract text.
	 * @param string $pdf_password User password for PDF opening.
	 * @return array|WP_Error
	 */
	private function create_approved_pdf_for_version( $lease_id, $version, $contract_text, $pdf_password ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return new WP_Error( 'af_lease_pdf_uploads_unavailable', __( 'WordPress uploads directory is not available.', 'arriendo-facil' ) );
		}

		$approved_dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/contracts-approved';
		if ( ! wp_mkdir_p( $approved_dir ) ) {
			return new WP_Error( 'af_lease_pdf_dir_failed', __( 'No se pudo crear el directorio de contratos aprobados.', 'arriendo-facil' ) );
		}

		$file_name = sprintf( 'lease-%d-v%d-approved-%s.pdf', absint( $lease_id ), absint( $version ), gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $approved_dir ) . $file_name;

		$written = $this->write_password_protected_pdf_file( $file_path, (string) $contract_text, (string) $pdf_password );
		if ( ! $written ) {
			return new WP_Error( 'af_lease_pdf_write_failed', __( 'No se pudo generar el documento PDF protegido.', 'arriendo-facil' ) );
		}

		$local_url    = trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts-approved/' . rawurlencode( $file_name );
		$approved_pdf = array(
			'provider'   => 'local',
			'object_key' => '',
			'mime_type'  => 'application/pdf',
			'file_name'  => $file_name,
			'local_url'  => $local_url,
			'approved_at' => current_time( 'mysql' ),
			'approved_by' => get_current_user_id(),
		);

		if ( Arriendo_Facil_Private_Storage::is_r2_enabled() ) {
			$pdf_contents = file_get_contents( $file_path );
			if ( false !== $pdf_contents && '' !== $pdf_contents ) {
				$object_key = sprintf( 'lease-contracts/%d/v%d/approved/%s', absint( $lease_id ), absint( $version ), sanitize_file_name( $file_name ) );
				$upload_r2  = Arriendo_Facil_Private_Storage::upload( $pdf_contents, $object_key, 'application/pdf' );

				if ( ! is_wp_error( $upload_r2 ) ) {
					$approved_pdf['provider']   = Arriendo_Facil_Private_Storage::PROVIDER_R2;
					$approved_pdf['object_key'] = $object_key;
					$approved_pdf['local_url']  = '';
					Arriendo_Facil_Contract_File_Store::discard_local_copy( $file_path );
				}
			}
		}

		return $approved_pdf;
	}

	/**
	 * Stores approved PDF metadata in a specific version.
	 *
	 * @param int   $lease_id Lease ID.
	 * @param int   $version Version number.
	 * @param array $approved_pdf Approved PDF metadata.
	 * @return bool
	 */
	private function set_approved_pdf_for_version( $lease_id, $version, array $approved_pdf ) {
		$option_name = $this->get_contract_storage_option_name( $lease_id );
		$stored      = get_option( $option_name, false );

		if ( is_array( $stored ) && isset( $stored['provider'] ) && ! isset( $stored['versions'] ) ) {
			$legacy_created_at = isset( $stored['updated_at'] ) ? sanitize_text_field( (string) $stored['updated_at'] ) : current_time( 'mysql' );
			$stored            = array(
				'active_version' => 1,
				'versions'       => array(
					array(
						'version'    => 1,
						'provider'   => sanitize_key( (string) ( $stored['provider'] ?? '' ) ),
						'object_key' => sanitize_text_field( (string) ( $stored['object_key'] ?? '' ) ),
						'mime_type'  => sanitize_text_field( (string) ( $stored['mime_type'] ?? '' ) ),
						'file_name'  => sanitize_file_name( (string) ( $stored['file_name'] ?? '' ) ),
						'local_url'  => esc_url_raw( (string) ( $stored['local_url'] ?? '' ) ),
						'created_at' => $legacy_created_at,
						'created_by' => absint( $stored['created_by'] ?? 0 ),
					),
				),
			);
		}

		if ( ! is_array( $stored ) || ! isset( $stored['versions'] ) || ! is_array( $stored['versions'] ) ) {
			return false;
		}

		$version = absint( $version );
		if ( $version < 1 ) {
			return false;
		}

		$clean_approved_pdf = array(
			'provider'   => isset( $approved_pdf['provider'] ) ? sanitize_key( (string) $approved_pdf['provider'] ) : 'local',
			'object_key' => isset( $approved_pdf['object_key'] ) ? sanitize_text_field( (string) $approved_pdf['object_key'] ) : '',
			'mime_type'  => 'application/pdf',
			'file_name'  => isset( $approved_pdf['file_name'] ) ? sanitize_file_name( (string) $approved_pdf['file_name'] ) : '',
			'local_url'  => isset( $approved_pdf['local_url'] ) ? esc_url_raw( (string) $approved_pdf['local_url'] ) : '',
			'approved_at' => current_time( 'mysql' ),
			'approved_by' => get_current_user_id(),
		);

		$updated = false;
		foreach ( $stored['versions'] as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( isset( $entry['version'] ) && absint( $entry['version'] ) === $version ) {
				$stored['versions'][ $index ]['approved_pdf'] = $clean_approved_pdf;
				$updated = true;
				break;
			}
		}

		if ( ! $updated ) {
			return false;
		}

		return update_option( $option_name, $stored, false );
	}

	/**
	 * Resolves a local uploads URL to absolute file path.
	 *
	 * @param string $url File URL.
	 * @return string
	 */
	private function resolve_upload_url_to_path( $url ) {
		$uploads = wp_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? trailingslashit( (string) $uploads['baseurl'] ) : '';
		$basedir = isset( $uploads['basedir'] ) ? trailingslashit( (string) $uploads['basedir'] ) : '';

		if ( '' === $baseurl || '' === $basedir ) {
			return '';
		}

		$clean_url = esc_url_raw( (string) $url );
		if ( 0 !== strpos( $clean_url, $baseurl ) ) {
			return '';
		}

		$relative = ltrim( substr( $clean_url, strlen( $baseurl ) ), '/' );
		if ( '' === $relative ) {
			return '';
		}

		$relative = str_replace( array( '../', '..\\' ), '', $relative );

		return $basedir . $relative;
	}

	/**
	 * Writes a password-protected PDF with print-only permission.
	 *
	 * @param string $file_path Destination file path.
	 * @param string $text Document text.
	 * @param string $user_password User/open password.
	 * @return bool
	 */
	private function write_password_protected_pdf_file( $file_path, $text, $user_password ) {
		$lines = $this->split_text_for_pdf( $text, 95 );
		if ( empty( $lines ) ) {
			$lines = array( 'Contrato aprobado' );
		}

		$lines_per_page = 44;
		$pages          = array_chunk( $lines, $lines_per_page );
		if ( empty( $pages ) ) {
			$pages = array( array( 'Contrato aprobado' ) );
		}

		$objects   = array();
		$catalog_n = 1;
		$pages_n   = 2;
		$font_n    = 3;

		$page_refs = array();
		$next_obj  = 4;

		foreach ( $pages as $page_lines ) {
			$page_n    = $next_obj;
			$content_n = $next_obj + 1;
			$page_refs[] = $page_n;
			$next_obj += 2;

			$stream = "BT\n/F1 10 Tf\n50 790 Td\n";
			$index  = 0;
			foreach ( $page_lines as $line ) {
				$escaped = $this->escape_pdf_text( (string) $line );
				if ( 0 === $index ) {
					$stream .= '(' . $escaped . ") Tj\n";
				} else {
					$stream .= "0 -16 Td\n(" . $escaped . ") Tj\n";
				}
				$index++;
			}
			$stream .= "ET\n";

			$objects[ $page_n ] = '<< /Type /Page /Parent ' . $pages_n . ' 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $font_n . ' 0 R >> >> /Contents ' . $content_n . ' 0 R >>';
			$objects[ $content_n ] = array(
				'stream' => $stream,
			);
		}

		$objects[ $catalog_n ] = '<< /Type /Catalog /Pages ' . $pages_n . ' 0 R >>';
		$objects[ $pages_n ]   = '<< /Type /Pages /Kids [' . implode( ' ', array_map( static function ( $n ) { return $n . ' 0 R'; }, $page_refs ) ) . '] /Count ' . count( $page_refs ) . ' >>';
		$objects[ $font_n ]    = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

		$encrypt_n = $next_obj;
		$max_obj   = $encrypt_n;

		$owner_password = wp_generate_password( 24, true, true );
		$p_value        = -44; // Allow print only; deny modify/copy/annotate.
		$id_hex         = md5( uniqid( 'af-lease-pdf-', true ) );
		$id_binary      = hex2bin( $id_hex );

		$padding = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";
		$user_pad  = substr( (string) $user_password . $padding, 0, 32 );
		$owner_pad = substr( (string) $owner_password . $padding, 0, 32 );

		$owner_key = substr( md5( $owner_pad, true ), 0, 5 );
		$o_value   = $this->pdf_rc4( $owner_key, $user_pad );

		$enc_key = substr( md5( $user_pad . $o_value . pack( 'V', $p_value ) . $id_binary, true ), 0, 5 );
		$u_value = $this->pdf_rc4( $enc_key, $padding );

		foreach ( $objects as $obj_num => $obj_data ) {
			if ( ! is_array( $obj_data ) || ! isset( $obj_data['stream'] ) ) {
				continue;
			}

			$obj_key = $this->pdf_object_encryption_key( $enc_key, (int) $obj_num, 0 );
			$objects[ $obj_num ]['stream'] = $this->pdf_rc4( $obj_key, (string) $obj_data['stream'] );
		}

		$objects[ $encrypt_n ] = '<< /Filter /Standard /V 1 /R 2 /O <' . bin2hex( $o_value ) . '> /U <' . bin2hex( $u_value ) . '> /P ' . $p_value . ' >>';

		ksort( $objects );

		$pdf      = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets  = array( 0 );
		$position = strlen( $pdf );

		for ( $i = 1; $i <= $max_obj; $i++ ) {
			$offsets[ $i ] = $position;
			$body = '';

			if ( isset( $objects[ $i ] ) && is_array( $objects[ $i ] ) && isset( $objects[ $i ]['stream'] ) ) {
				$stream_data = (string) $objects[ $i ]['stream'];
				$body = '<< /Length ' . strlen( $stream_data ) . ' >>' . "\nstream\n" . $stream_data . "endstream";
			} elseif ( isset( $objects[ $i ] ) ) {
				$body = (string) $objects[ $i ];
			}

			$obj_text = $i . " 0 obj\n" . $body . "\nendobj\n";
			$pdf     .= $obj_text;
			$position += strlen( $obj_text );
		}

		$xref_position = strlen( $pdf );
		$pdf .= 'xref' . "\n";
		$pdf .= '0 ' . ( $max_obj + 1 ) . "\n";
		$pdf .= "0000000000 65535 f \n";

		for ( $i = 1; $i <= $max_obj; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", isset( $offsets[ $i ] ) ? (int) $offsets[ $i ] : 0 );
		}

		$pdf .= 'trailer' . "\n";
		$pdf .= '<< /Size ' . ( $max_obj + 1 ) . ' /Root ' . $catalog_n . ' 0 R /Encrypt ' . $encrypt_n . ' 0 R /ID [<' . $id_hex . '><' . $id_hex . '>] >>' . "\n";
		$pdf .= 'startxref' . "\n";
		$pdf .= $xref_position . "\n";
		$pdf .= '%%EOF';

		return false !== file_put_contents( $file_path, $pdf );
	}

	/**
	 * Escapes text content for PDF literals.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function escape_pdf_text( $text ) {
		$text = str_replace( array( "\\", '(', ')' ), array( '\\\\', '\\(', '\\)' ), (string) $text );
		$text = preg_replace( '/[^\x20-\x7E]/', '?', (string) $text );

		return (string) $text;
	}

	/**
	 * Splits text into line-safe chunks for generated PDF pages.
	 *
	 * @param string $text Full text.
	 * @param int    $max_len Maximum line length.
	 * @return array<int,string>
	 */
	private function split_text_for_pdf( $text, $max_len ) {
		$max_len = max( 30, absint( $max_len ) );
		$input_lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		if ( ! is_array( $input_lines ) ) {
			$input_lines = array( (string) $text );
		}

		$out = array();
		foreach ( $input_lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				$out[] = '';
				continue;
			}

			while ( strlen( $line ) > $max_len ) {
				$chunk = substr( $line, 0, $max_len );
				$cut   = strrpos( $chunk, ' ' );
				if ( false === $cut || $cut < (int) floor( $max_len * 0.5 ) ) {
					$cut = $max_len;
				}

				$out[] = trim( substr( $line, 0, $cut ) );
				$line  = ltrim( substr( $line, $cut ) );
			}

			$out[] = $line;
		}

		return $out;
	}

	/**
	 * RC4 helper for PDF encryption.
	 *
	 * @param string $key Encryption key.
	 * @param string $data Data to encrypt.
	 * @return string
	 */
	private function pdf_rc4( $key, $data ) {
		$key_length = strlen( (string) $key );
		$data       = (string) $data;
		$state      = range( 0, 255 );
		$j          = 0;

		for ( $i = 0; $i < 256; $i++ ) {
			$j = ( $j + $state[ $i ] + ord( $key[ $i % $key_length ] ) ) % 256;
			$tmp = $state[ $i ];
			$state[ $i ] = $state[ $j ];
			$state[ $j ] = $tmp;
		}

		$i = 0;
		$j = 0;
		$result = '';
		$data_length = strlen( $data );

		for ( $y = 0; $y < $data_length; $y++ ) {
			$i = ( $i + 1 ) % 256;
			$j = ( $j + $state[ $i ] ) % 256;
			$tmp = $state[ $i ];
			$state[ $i ] = $state[ $j ];
			$state[ $j ] = $tmp;
			$k = $state[ ( $state[ $i ] + $state[ $j ] ) % 256 ];
			$result .= chr( ord( $data[ $y ] ) ^ $k );
		}

		return $result;
	}

	/**
	 * Builds object-specific encryption key for PDF streams.
	 *
	 * @param string $file_key Document encryption key.
	 * @param int    $object_number PDF object number.
	 * @param int    $generation_number PDF generation number.
	 * @return string
	 */
	private function pdf_object_encryption_key( $file_key, $object_number, $generation_number ) {
		$file_key = (string) $file_key;
		$object_number = absint( $object_number );
		$generation_number = absint( $generation_number );

		$key_material =
			$file_key
			. chr( $object_number & 0xFF )
			. chr( ( $object_number >> 8 ) & 0xFF )
			. chr( ( $object_number >> 16 ) & 0xFF )
			. chr( $generation_number & 0xFF )
			. chr( ( $generation_number >> 8 ) & 0xFF );

		$hash = md5( $key_material, true );
		$key_len = min( strlen( $file_key ) + 5, 16 );

		return substr( $hash, 0, $key_len );
	}

	/**
	 * Returns the fixed password used for approved PDFs.
	 *
	 * @return string
	 */
	private function get_approved_pdf_password() {
		return 'arriendofacil.net';
	}

	/**
	 * Marks a lease as deleted by setting the deleted_at field.
	 *
	 * @param int $lease_id Lease ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_lease($lease_id) {
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . 'af_leases',
			array('deleted_at' => current_time('mysql')),
			array('id' => absint($lease_id)),
			array('%s'),
			array('%d')
		);
	}

	/**
	 * Fetches leases for a given accommodation, excluding deleted ones.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return array List of leases.
	 */
	public function get_leases_by_accommodation($accommodation_id) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}af_leases WHERE accommodation_id = %d AND deleted_at IS NULL ORDER BY start_date DESC",
				$accommodation_id
			)
		);
	}
}
