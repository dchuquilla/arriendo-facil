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
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		if ( ! $lease_id || ! $this->get_lease( $lease_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid lease ID.', 'arriendo-facil' ) ), 400 );
		}

		$pdf_password = $this->get_approved_pdf_password();

		$versions_data = $this->get_contract_versions( $lease_id );
		$active_version = isset( $versions_data['active_version'] ) ? absint( $versions_data['active_version'] ) : 0;
		$version_entry  = $this->find_version_entry( $versions_data['versions'], $active_version );

		if ( ! is_array( $version_entry ) ) {
			wp_send_json_error( array( 'message' => __( 'No contract version found to approve.', 'arriendo-facil' ) ), 400 );
		}

		if ( isset( $version_entry['approved_pdf'] ) && is_array( $version_entry['approved_pdf'] ) && ! empty( $version_entry['approved_pdf']['file_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Active contract version is already approved.', 'arriendo-facil' ) ), 400 );
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
			wp_send_json_error( array( 'message' => __( 'Could not read contract text from the active version. Upload a DOCX version and try again.', 'arriendo-facil' ) ), 400 );
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
			wp_send_json_error( array( 'message' => __( 'Could not save approved PDF metadata.', 'arriendo-facil' ) ), 500 );
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

		wp_send_json_success(
			array(
				'message' => __( 'Document approved. Protected PDF is now active for view/download.', 'arriendo-facil' ),
			)
		);
	}

	/**
	 * Uploads a manually edited Word file as a new contract version.
	 */
	public function ajax_upload_lease_contract_version() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( wp_unslash( $_POST['lease_id'] ) ) : 0;
		if ( ! $lease_id || ! $this->get_lease( $lease_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid lease ID.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! isset( $_FILES['lease_contract_file'] ) || ! is_array( $_FILES['lease_contract_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'You must upload a Word file (.doc or .docx).', 'arriendo-facil' ) ), 400 );
		}

		$file_data = $_FILES['lease_contract_file'];
		$file_error = isset( $file_data['error'] ) ? (int) $file_data['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $file_error ) {
			wp_send_json_error( array( 'message' => __( 'Could not upload the selected file.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! empty( $file_data['size'] ) && (int) $file_data['size'] > ( 12 * 1024 * 1024 ) ) {
			wp_send_json_error( array( 'message' => __( 'Word file exceeds maximum size (12 MB).', 'arriendo-facil' ) ), 400 );
		}

		$allowed_mimes = array(
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		);

		$checked = wp_check_filetype_and_ext( $file_data['tmp_name'], $file_data['name'], $allowed_mimes );
		if ( ! in_array( (string) $checked['ext'], array( 'doc', 'docx' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Only Word files are allowed (.doc, .docx).', 'arriendo-facil' ) ), 400 );
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
			$error_msg = is_array( $upload ) && isset( $upload['error'] ) ? (string) $upload['error'] : __( 'Could not save the uploaded Word file.', 'arriendo-facil' );
			wp_send_json_error( array( 'message' => $error_msg ) );
		}

		$file_path = isset( $upload['file'] ) ? (string) $upload['file'] : '';
		$file_url  = isset( $upload['url'] ) ? esc_url_raw( (string) $upload['url'] ) : '';
		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Uploaded file is missing on server.', 'arriendo-facil' ) ) );
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

		$storage_provider = $this->get_storage_setting( 'AF_STORAGE_PROVIDER', 'af_storage_provider', 'cloudflare_r2' );
		if ( 'cloudflare_r2' === $storage_provider ) {
			$r2_config = $this->get_r2_config();
			if ( ! is_wp_error( $r2_config ) ) {
				$contents = file_get_contents( $file_path );
				if ( false !== $contents ) {
					$safe_name  = sanitize_file_name( wp_basename( $file_path ) );
					$object_key = sprintf( 'lease-contracts/%d/v%d/%s', $lease_id, $next_version, $safe_name );
					$upload_r2  = $this->upload_contents_to_r2(
						$contents,
						$object_key,
						'' !== (string) $checked['type'] ? (string) $checked['type'] : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
						$r2_config
					);

					if ( ! is_wp_error( $upload_r2 ) ) {
						$final_document_url = add_query_arg(
							array(
								'action'   => 'af_download_lease_contract',
								'lease_id' => $lease_id,
							),
							admin_url( 'admin-ajax.php' )
						);
						$storage_meta = array(
							'provider'   => 'cloudflare_r2',
							'object_key' => $object_key,
							'file_name'  => $safe_name,
							'local_url'  => $file_url,
							'mime_type'  => '' !== (string) $checked['type'] ? (string) $checked['type'] : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
						);
					}
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
				'message' => sprintf( __( 'Uploaded as version v%d.', 'arriendo-facil' ), $next_version ),
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
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( $_POST['accommodation_id'] ) : 0;
		$guest_id         = isset( $_POST['guest_id'] ) ? absint( $_POST['guest_id'] ) : 0;
		$start_date       = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date         = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$monthly_rent     = isset( $_POST['monthly_rent'] ) ? floatval( wp_unslash( $_POST['monthly_rent'] ) ) : 0.0;

		if ( ! $accommodation_id || ! $guest_id || ! $start_date || ! $end_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'arriendo-facil' ) ) );
		}

		global $wpdb;
		$inserted = $wpdb->insert(
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

		if ( $inserted ) {
			wp_send_json_success( array( 'id' => $wpdb->insert_id ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not create lease.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Updates an existing lease via AJAX.
	 */
	public function ajax_update_lease() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		$allowed_statuses = array( 'draft', 'active', 'expired', 'terminated' );
		if ( ! $lease_id || ! in_array( $status, $allowed_statuses, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'arriendo-facil' ) ) );
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
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not update lease.', 'arriendo-facil' ) ) );
		}
	}

	/**
	 * Returns leases for a given accommodation via AJAX.
	 */
	public function ajax_get_leases() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_GET['accommodation_id'] ) ? absint( $_GET['accommodation_id'] ) : 0;

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
				"SELECT * FROM {$wpdb->prefix}af_leases WHERE id = %d",
				$lease_id
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
	 * Ensures a lease has a downloadable document URL.
	 *
	 * Creates a simple fallback contract text file if no version/document exists.
	 *
	 * @param int $lease_id Lease ID.
	 * @return bool
	 */
	public function ensure_lease_document_available( $lease_id ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return false;
		}

		$versions_data = $this->get_contract_versions( $lease_id );
		$has_versions  = isset( $versions_data['versions'] ) && is_array( $versions_data['versions'] ) && ! empty( $versions_data['versions'] );
		if ( $has_versions ) {
			return true;
		}

		$lease = $this->get_lease( $lease_id );
		if ( ! $lease ) {
			return false;
		}

		$current_document_url = isset( $lease->document_url ) ? esc_url_raw( (string) $lease->document_url ) : '';
		if ( '' !== $current_document_url ) {
			return true;
		}

		// Priority rule: when an owner template exists for this accommodation,
		// generate and attach that document before any auto-fallback file.
		if ( $this->try_attach_owner_template_document( $lease ) ) {
			return true;
		}

		$owner_template_url = $this->get_owner_template_url_for_lease( $lease );
		if ( '' !== $owner_template_url ) {
			return $this->attach_document( $lease_id, $owner_template_url );
		}

		$fallback_text = $this->build_minimal_fallback_contract_text( $lease );

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return false;
		}

		$contracts_dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/contracts';
		if ( ! wp_mkdir_p( $contracts_dir ) ) {
			return false;
		}

		$file_name = sprintf( 'lease-%d-autofallback-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;

		if ( ! $this->write_fallback_contract_docx( $file_path, $fallback_text ) ) {
			return false;
		}

		$file_url = esc_url_raw( trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name ) );
		return $this->attach_document( $lease_id, $file_url );
	}

	/**
	 * Attempts to generate and attach lease document from owner DOCX template.
	 *
	 * @param object $lease Lease row object.
	 * @return bool
	 */
	private function try_attach_owner_template_document( $lease ) {
		if ( ! $lease || ! class_exists( 'Arriendo_Facil_Guest' ) ) {
			return false;
		}

		$lease_id         = isset( $lease->id ) ? absint( $lease->id ) : 0;
		$accommodation_id = isset( $lease->accommodation_id ) ? absint( $lease->accommodation_id ) : 0;
		$guest_id         = isset( $lease->guest_id ) ? absint( $lease->guest_id ) : 0;

		if ( ! $lease_id || ! $accommodation_id ) {
			return false;
		}

		try {
			$guest_service = new Arriendo_Facil_Guest();

			$ref_get_context = new ReflectionMethod( 'Arriendo_Facil_Guest', 'get_owner_contract_example_context' );
			$ref_get_context->setAccessible( true );
			$owner_template = $ref_get_context->invoke( $guest_service, $accommodation_id );

			if ( ! is_array( $owner_template ) || empty( $owner_template['attachment_id'] ) ) {
				return false;
			}

			global $wpdb;
			$guest_row = null;
			if ( $guest_id ) {
				$guest_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d LIMIT 1",
						$guest_id
					)
				);
			}

			$owner_id_number = '';
			$owner_user_id   = isset( $owner_template['owner_user_id'] ) ? absint( $owner_template['owner_user_id'] ) : 0;
			if ( $owner_user_id ) {
				$owner_id_number = (string) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT owner_id FROM {$wpdb->prefix}af_owner_contacts WHERE wp_user_id = %d ORDER BY id DESC LIMIT 1",
						$owner_user_id
					)
				);
			}

			$payload = array(
				'owner_name'            => isset( $owner_template['owner_name'] ) ? sanitize_text_field( (string) $owner_template['owner_name'] ) : '',
				'owner_email'           => isset( $owner_template['owner_email'] ) ? sanitize_email( (string) $owner_template['owner_email'] ) : '',
				'owner_id_number'       => sanitize_text_field( $owner_id_number ),
				'guest_name'            => $guest_row ? sanitize_text_field( trim( (string) $guest_row->first_name . ' ' . (string) $guest_row->last_name ) ) : 'INQUILINO',
				'guest_email'           => $guest_row ? sanitize_email( (string) $guest_row->email ) : '',
				'guest_phone'           => $guest_row ? sanitize_text_field( (string) $guest_row->phone ) : '',
				'guest_id_number'       => $guest_row ? sanitize_text_field( (string) $guest_row->id_number ) : '',
				'accommodation_title'   => sanitize_text_field( (string) get_the_title( $accommodation_id ) ),
				'accommodation_address' => sanitize_text_field( (string) get_post_meta( $accommodation_id, '_af_address', true ) ),
				'start_date'            => isset( $lease->start_date ) ? sanitize_text_field( (string) $lease->start_date ) : '',
				'end_date'              => isset( $lease->end_date ) ? sanitize_text_field( (string) $lease->end_date ) : '',
				'monthly_rent'          => isset( $lease->monthly_rent ) ? (float) $lease->monthly_rent : 0.0,
				'desired_price'         => '',
				'guarantee_text'        => ( $guest_row && isset( $guest_row->guarantee_text ) ) ? sanitize_text_field( (string) $guest_row->guarantee_text ) : '',
				'template_available'    => true,
				'template_text'         => isset( $owner_template['template_text'] ) ? (string) $owner_template['template_text'] : '',
			);

			$ref_create = new ReflectionMethod( 'Arriendo_Facil_Guest', 'create_filled_contract_from_owner_template' );
			$ref_create->setAccessible( true );
			$document_url = (string) $ref_create->invoke( $guest_service, $lease_id, $owner_template, $payload );

			if ( '' === $document_url ) {
				return false;
			}

			$ref_attach = new ReflectionMethod( 'Arriendo_Facil_Guest', 'force_attach_lease_document' );
			$ref_attach->setAccessible( true );
			$ref_attach->invoke( $guest_service, $lease_id, $document_url );

			$lease_after = $this->get_lease( $lease_id );
			$attached_url = $lease_after && isset( $lease_after->document_url ) ? esc_url_raw( (string) $lease_after->document_url ) : '';
			return '' !== $attached_url;
		} catch ( Throwable $throwable ) {
			error_log( 'Arriendo Facil owner-template auto-attach failed: ' . $throwable->getMessage() );
			return false;
		}
	}

	/**
	 * Returns the owner template URL for this lease when available.
	 *
	 * @param object $lease Lease row object.
	 * @return string
	 */
	private function get_owner_template_url_for_lease( $lease ) {
		if ( ! $lease || ! class_exists( 'Arriendo_Facil_Guest' ) ) {
			return '';
		}

		$accommodation_id = isset( $lease->accommodation_id ) ? absint( $lease->accommodation_id ) : 0;
		if ( ! $accommodation_id ) {
			return '';
		}

		try {
			$guest_service = new Arriendo_Facil_Guest();
			$ref_get_context = new ReflectionMethod( 'Arriendo_Facil_Guest', 'get_owner_contract_example_context' );
			$ref_get_context->setAccessible( true );
			$owner_template = $ref_get_context->invoke( $guest_service, $accommodation_id );
			if ( is_array( $owner_template ) && isset( $owner_template['url'] ) && is_string( $owner_template['url'] ) ) {
				return esc_url_raw( (string) $owner_template['url'] );
			}
		} catch ( Throwable $throwable ) {
			error_log( 'Arriendo Facil owner-template URL lookup failed: ' . $throwable->getMessage() );
		}

		return '';
	}

	/**
	 * Builds a minimal fallback contract body for visibility when generation fails.
	 *
	 * @param object $lease Lease row.
	 * @return string
	 */
	private function build_minimal_fallback_contract_text( $lease ) {
		$accommodation_id = isset( $lease->accommodation_id ) ? absint( $lease->accommodation_id ) : 0;
		$guest_id         = isset( $lease->guest_id ) ? absint( $lease->guest_id ) : 0;
		$start_date       = isset( $lease->start_date ) ? sanitize_text_field( (string) $lease->start_date ) : '';
		$end_date         = isset( $lease->end_date ) ? sanitize_text_field( (string) $lease->end_date ) : '';
		$monthly_rent_raw = isset( $lease->monthly_rent ) ? (float) $lease->monthly_rent : 0.0;

		// Build full payload to generate a proper legal contract.
		$accommodation_title   = $accommodation_id ? (string) get_the_title( $accommodation_id ) : '________________________';
		$accommodation_address = $accommodation_id ? (string) get_post_meta( $accommodation_id, '_af_address', true ) : '________________________';

		$owner_name      = '________________________';
		$owner_id_number = '________________________';
		$owner_email     = '';
		if ( $accommodation_id ) {
			$owner_user_id = absint( get_post_meta( $accommodation_id, '_af_owner_id', true ) );
			if ( $owner_user_id ) {
				$owner_user = get_user_by( 'id', $owner_user_id );
				if ( $owner_user ) {
					$owner_name  = (string) $owner_user->display_name;
					$owner_email = (string) $owner_user->user_email;
				}
			}
		}

		$guest_name      = '________________________';
		$guest_email     = '';
		$guest_phone     = '';
		$guest_id_number = '________________________';
		$guarantee_text  = 'Garantia equivalente a dos (2) meses del canon de arrendamiento.';
		if ( $guest_id ) {
			global $wpdb;
			$guest_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d LIMIT 1",
					$guest_id
				)
			);
			if ( $guest_row ) {
				$guest_name      = trim( (string) $guest_row->first_name . ' ' . (string) $guest_row->last_name );
				$guest_email     = (string) $guest_row->email;
				$guest_phone     = (string) $guest_row->phone;
				$guest_id_number = (string) $guest_row->id_number;
				if ( ! empty( $guest_row->guarantee_text ) ) {
					$guarantee_text = (string) $guest_row->guarantee_text;
				}
			}
		}

		$payload = array(
			'owner_name'            => $owner_name,
			'owner_email'           => $owner_email,
			'owner_id_number'       => $owner_id_number,
			'guest_name'            => $guest_name,
			'guest_email'           => $guest_email,
			'guest_phone'           => $guest_phone,
			'guest_id_number'       => $guest_id_number,
			'accommodation_title'   => $accommodation_title,
			'accommodation_address' => $accommodation_address,
			'start_date'            => $start_date,
			'end_date'              => $end_date,
			'monthly_rent'          => $monthly_rent_raw,
			'guarantee_text'        => $guarantee_text,
			'mascotas'              => 0,
			'personas_viviran'      => 0,
			'referencia_personal_1' => '________________________',
			'referencia_personal_2' => '________________________',
		);

		// Try AI generation first.
		if ( class_exists( 'Arriendo_Facil_AI_Service' ) ) {
			try {
				$ai_payload_full = array_merge( $payload, array(
					'template_available' => false,
					'template_text'      => '',
				) );
				$ai = new Arriendo_Facil_AI_Service();
				$result = $ai->generate_document( $ai_payload_full );
				if ( ! is_wp_error( $result ) && isset( $result['contract_text'] ) && '' !== trim( (string) $result['contract_text'] ) ) {
					return trim( wp_strip_all_tags( (string) $result['contract_text'] ) );
				}
			} catch ( Throwable $throwable ) {
				error_log( 'Arriendo Facil lease auto-repair AI error: ' . $throwable->getMessage() );
			}
		}

		// Inline legal contract fallback (Ecuador 2026).
		$rent_formatted = number_format( $monthly_rent_raw, 2, '.', '' );
		$city_and_date  = sprintf( 'Quito, %s', current_time( 'Y-m-d' ) );

		$text  = "CONTRATO DE ARRENDAMIENTO DE INMUEBLE\n";
		$text .= "(Conforme al Codigo Civil del Ecuador, Arts. 1857-1948, y la Ley de Inquilinato vigente con sus reformas)\n";
		$text .= "\n" . $city_and_date . "\n";
		$text .= "\nCOMPARECIENTES\n";
		$text .= "\nARRENDADOR: " . $owner_name . "\nARRENDATARIO: " . $guest_name . " (Cedula: " . $guest_id_number . ", Celular: " . $guest_phone . ", Correo: " . $guest_email . ")\n";
		$text .= "\nCLAUSULA PRIMERA - OBJETO DEL CONTRATO\n";
		$text .= "El ARRENDADOR da en arrendamiento el inmueble \"" . $accommodation_title . "\", ubicado en " . $accommodation_address . ".\n";
		$text .= "\nCLAUSULA SEGUNDA - PLAZO\n";
		$text .= "Vigencia: " . $start_date . " hasta el " . $end_date . ". Prorroga automatica de 30 dias salvo aviso previo escrito.\n";
		$text .= "\nCLAUSULA TERCERA - CANON Y FORMA DE PAGO\n";
		$text .= "Canon mensual: USD " . $rent_formatted . ", pagadero dentro de los primeros cinco (5) dias de cada mes. Mora del 1% mensual por retraso.\n";
		$text .= "\nCLAUSULA CUARTA - GARANTIA\n";
		$text .= $guarantee_text . "\n";
		$text .= "\nCLAUSULA QUINTA - DESTINO Y USO\n";
		$text .= "Uso exclusivo habitacional. Prohibido subarrendar sin autorizacion escrita del ARRENDADOR.\n";
		$text .= "\nCLAUSULA SEXTA - SERVICIOS BASICOS\n";
		$text .= "Energia, agua, telefonia, internet y gas a cargo del ARRENDATARIO. Predial y administracion a cargo del ARRENDADOR.\n";
		$text .= "\nCLAUSULA SEPTIMA - OBLIGACIONES DEL ARRENDATARIO\n";
		$text .= "Pago puntual, conservacion del inmueble, no modificar sin autorizacion, permitir inspecciones con 24 h de aviso.\n";
		$text .= "\nCLAUSULA OCTAVA - OBLIGACIONES DEL ARRENDADOR\n";
		$text .= "Garantizar la posesion pacifica y atender reparaciones estructurales (Art. 1937 CC).\n";
		$text .= "\nCLAUSULA NOVENA - TERMINACION\n";
		$text .= "Por vencimiento, mutuo acuerdo, incumplimiento o desahucio conforme COGEP. Aviso de 30 dias para desahucio voluntario.\n";
		$text .= "\nCLAUSULA DECIMA - JURISDICCION\n";
		$text .= "Jueces competentes del Ecuador. Ley de Inquilinato, Codigo Civil Arts. 1857-1948 y COGEP vigentes 2026. Renuncia a domicilio y fuero especial.\n";
		$text .= "\nEn fe de lo cual las partes suscriben el presente contrato en dos ejemplares.\n";
		$text .= "\nFIRMAS\n";
		$text .= "\nARRENDADOR: ________________________\nNombre: " . $owner_name . "\nCedula/RUC: " . $owner_id_number . "\n";
		$text .= "\nARRENDATARIO: ________________________\nNombre: " . $guest_name . "\nCedula: " . $guest_id_number . "\n";

		return $text;
	}

	/**
	 * Writes a formatted DOCX fallback contract file.
	 *
	 * @param string $file_path Destination file path.
	 * @param string $contract_text Contract text content.
	 * @return bool
	 */
	private function write_fallback_contract_docx( $file_path, $contract_text ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$lines = preg_split( '/\r\n|\r|\n/', (string) $contract_text );
		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $contract_text );
		}

		$doc_paragraphs_xml = '';
		$first_line = true;
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				$doc_paragraphs_xml .= '<w:p/>';
				continue;
			}

			$upper = strtoupper( $line );
			$is_title  = $first_line || false !== strpos( $upper, 'CONTRATO DE ARRENDAMIENTO' );
			$is_clause = 0 === strpos( $upper, 'CLAUSULA ' );
			$first_line = false;

			$ppr = '';
			$rpr = '';
			if ( $is_title ) {
				$ppr = '<w:pPr><w:jc w:val="center"/></w:pPr>';
				$rpr = '<w:rPr><w:b/><w:bCs/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr>';
			} elseif ( $is_clause ) {
				$ppr = '<w:pPr><w:jc w:val="left"/></w:pPr>';
				$rpr = '<w:rPr><w:b/><w:bCs/></w:rPr>';
			} else {
				$ppr = '<w:pPr><w:jc w:val="both"/></w:pPr>';
			}

			$doc_paragraphs_xml .= '<w:p>' . $ppr . '<w:r>' . $rpr . '<w:t xml:space="preserve">' . esc_xml( $line ) . '</w:t></w:r></w:p>';
		}

		if ( '' === $doc_paragraphs_xml ) {
			$doc_paragraphs_xml = '<w:p><w:r><w:t xml:space="preserve">Contrato</w:t></w:r></w:p>';
		}

		$document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:w10="urn:schemas-microsoft-com:office:word" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml" xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup" xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk" xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml" xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" mc:Ignorable="w14 w15 wp14">'
			. '<w:body>' . $doc_paragraphs_xml . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/><w:cols w:space="720"/></w:sectPr></w:body></w:document>';

		$content_types_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '</Types>';

		$styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:styles xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" mc:Ignorable="">'
			. '<w:docDefaults>'
			. '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:rPrDefault>'
			. '<w:pPrDefault><w:pPr><w:spacing w:before="0" w:after="160" w:line="360" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr></w:pPrDefault>'
			. '</w:docDefaults>'
			. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
			. '</w:styles>';

		$rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			. '</Relationships>';

		$doc_rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';

		$zip->addFromString( '[Content_Types].xml', $content_types_xml );
		$zip->addFromString( '_rels/.rels', $rels_xml );
		$zip->addFromString( 'word/document.xml', $document_xml );
		$zip->addFromString( 'word/styles.xml', $styles_xml );
		$zip->addFromString( 'word/_rels/document.xml.rels', $doc_rels_xml );

		return $zip->close();
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
	 */
	public function ajax_download_lease_contract() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'arriendo-facil' ), 403 );
		}

		$lease_id = isset( $_GET['lease_id'] ) ? absint( wp_unslash( $_GET['lease_id'] ) ) : 0;
		if ( ! $lease_id ) {
			wp_die( esc_html__( 'Invalid lease ID.', 'arriendo-facil' ), 400 );
		}

		$lease = $this->get_lease( $lease_id );
		if ( ! $lease ) {
			wp_die( esc_html__( 'Lease not found.', 'arriendo-facil' ), 404 );
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
				$presigned_url = $this->build_r2_presigned_get_url( $approved_object_key, 600 );
				if ( ! is_wp_error( $presigned_url ) && is_string( $presigned_url ) && '' !== $presigned_url ) {
					$this->redirect_to_contract_url( $presigned_url );
				}
			}

			if ( '' !== $approved_local_url ) {
				$this->redirect_to_contract_url( $approved_local_url );
			}
		}

		if ( is_array( $version_entry ) && isset( $version_entry['provider'], $version_entry['object_key'] ) && 'cloudflare_r2' === $version_entry['provider'] && '' !== trim( (string) $version_entry['object_key'] ) ) {
			$presigned_url = $this->build_r2_presigned_get_url( (string) $version_entry['object_key'], 600 );
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

		wp_die( esc_html__( 'Contract document is not available.', 'arriendo-facil' ), 404 );
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
	 * Reads setting with wp-config constant priority.
	 *
	 * @param string $constant_name Constant name.
	 * @param string $option_name Option name.
	 * @param string $default Default value.
	 * @return string
	 */
	private function get_storage_setting( $constant_name, $option_name, $default = '' ) {
		if ( defined( $constant_name ) ) {
			$value = constant( $constant_name );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return trim( (string) get_option( $option_name, $default ) );
	}

	/**
	 * Loads and validates Cloudflare R2 credentials.
	 *
	 * @return array|WP_Error
	 */
	private function get_r2_config() {
		$access_key = $this->get_storage_setting( 'AF_R2_ACCESS_KEY_ID', 'af_r2_access_key_id', '' );
		$secret_key = $this->get_storage_setting( 'AF_R2_SECRET_ACCESS_KEY', 'af_r2_secret_access_key', '' );
		$endpoint   = untrailingslashit( $this->get_storage_setting( 'AF_R2_ENDPOINT_URL', 'af_r2_endpoint_url', '' ) );
		$bucket     = $this->get_storage_setting( 'AF_R2_BUCKET_NAME', 'af_r2_bucket_name', '' );

		if ( '' === $access_key || '' === $secret_key || '' === $endpoint || '' === $bucket ) {
			return new WP_Error( 'af_r2_missing_config', __( 'Missing Cloudflare R2 credentials. Check Settings > Cloud Provider.', 'arriendo-facil' ) );
		}

		$parsed = wp_parse_url( $endpoint );
		$host   = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		$scheme = isset( $parsed['scheme'] ) ? (string) $parsed['scheme'] : '';

		if ( '' === $host || '' === $scheme ) {
			return new WP_Error( 'af_r2_invalid_endpoint', __( 'Invalid Cloudflare R2 endpoint URL.', 'arriendo-facil' ) );
		}

		return array(
			'access_key' => $access_key,
			'secret_key' => $secret_key,
			'endpoint'   => $scheme . '://' . $host,
			'host'       => $host,
			'bucket'     => $bucket,
			'region'     => 'auto',
			'service'    => 's3',
		);
	}

	/**
	 * Builds pre-signed GET URL for a private R2 object.
	 *
	 * @param string $object_key R2 object key.
	 * @param int    $expires Expiration in seconds.
	 * @return string|WP_Error
	 */
	private function build_r2_presigned_get_url( $object_key, $expires = 600 ) {
		$r2_config = $this->get_r2_config();
		if ( is_wp_error( $r2_config ) ) {
			return $r2_config;
		}

		$object_key = ltrim( (string) $object_key, '/' );
		if ( '' === $object_key ) {
			return new WP_Error( 'af_r2_missing_object_key', __( 'Missing R2 object key.', 'arriendo-facil' ) );
		}

		$expires      = max( 60, min( 3600, absint( $expires ) ) );
		$amz_date     = gmdate( 'Ymd\\THis\\Z' );
		$date_stamp   = gmdate( 'Ymd' );
		$scope        = $date_stamp . '/' . $r2_config['region'] . '/' . $r2_config['service'] . '/aws4_request';
		$canonical_uri = '/' . rawurlencode( $r2_config['bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );

		$query_params = array(
			'X-Amz-Algorithm'  => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential' => rawurlencode( $r2_config['access_key'] . '/' . $scope ),
			'X-Amz-Date'       => $amz_date,
			'X-Amz-Expires'    => (string) $expires,
			'X-Amz-SignedHeaders' => 'host',
		);

		ksort( $query_params );
		$canonical_query = '';
		foreach ( $query_params as $key => $value ) {
			if ( '' !== $canonical_query ) {
				$canonical_query .= '&';
			}
			$canonical_query .= rawurlencode( (string) $key ) . '=' . (string) $value;
		}

		$canonical_request =
			"GET\n"
			. $canonical_uri . "\n"
			. $canonical_query . "\n"
			. 'host:' . $r2_config['host'] . "\n\n"
			. 'host' . "\n"
			. 'UNSIGNED-PAYLOAD';

		$string_to_sign =
			'AWS4-HMAC-SHA256' . "\n"
			. $amz_date . "\n"
			. $scope . "\n"
			. hash( 'sha256', $canonical_request );

		$signing_key = $this->get_aws_v4_signing_key( $r2_config['secret_key'], $date_stamp, $r2_config['region'], $r2_config['service'] );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		return $r2_config['endpoint'] . $canonical_uri . '?' . $canonical_query . '&X-Amz-Signature=' . rawurlencode( $signature );
	}

	/**
	 * Uploads raw contents to Cloudflare R2 using SigV4.
	 *
	 * @param string $contents File contents.
	 * @param string $object_key Object key path.
	 * @param string $mime_type Mime type.
	 * @param array  $r2_config Parsed R2 config.
	 * @return true|WP_Error
	 */
	private function upload_contents_to_r2( $contents, $object_key, $mime_type, array $r2_config ) {
		$payload_hash   = hash( 'sha256', $contents );
		$amz_date       = gmdate( 'Ymd\\THis\\Z' );
		$date_stamp     = gmdate( 'Ymd' );
		$canonical_uri  = '/' . rawurlencode( $r2_config['bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( (string) $object_key ) );

		$canonical_headers =
			'host:' . $r2_config['host'] . "\n"
			. 'x-amz-content-sha256:' . $payload_hash . "\n"
			. 'x-amz-date:' . $amz_date . "\n";
		$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request =
			"PUT\n"
			. $canonical_uri . "\n"
			. "\n"
			. $canonical_headers . "\n"
			. $signed_headers . "\n"
			. $payload_hash;

		$credential_scope = $date_stamp . '/' . $r2_config['region'] . '/' . $r2_config['service'] . '/aws4_request';
		$string_to_sign   =
			'AWS4-HMAC-SHA256' . "\n"
			. $amz_date . "\n"
			. $credential_scope . "\n"
			. hash( 'sha256', $canonical_request );

		$signing_key = $this->get_aws_v4_signing_key( $r2_config['secret_key'], $date_stamp, $r2_config['region'], $r2_config['service'] );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$authorization =
			'AWS4-HMAC-SHA256 '
			. 'Credential=' . $r2_config['access_key'] . '/' . $credential_scope . ', '
			. 'SignedHeaders=' . $signed_headers . ', '
			. 'Signature=' . $signature;

		$response = wp_remote_request(
			$r2_config['endpoint'] . $canonical_uri,
			array(
				'method'  => 'PUT',
				'timeout' => 45,
				'headers' => array(
					'Host'                 => $r2_config['host'],
					'Content-Type'         => $mime_type,
					'x-amz-date'           => $amz_date,
					'x-amz-content-sha256' => $payload_hash,
					'Authorization'        => $authorization,
				),
				'body' => $contents,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error( 'af_r2_upload_failed', __( 'Cloudflare R2 rejected the uploaded contract file.', 'arriendo-facil' ) );
		}

		return true;
	}

	/**
	 * Builds AWS Signature V4 signing key.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $date_stamp Date stamp.
	 * @param string $region Region.
	 * @param string $service Service.
	 * @return string
	 */
	private function get_aws_v4_signing_key( $secret_key, $date_stamp, $region, $service ) {
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );

		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
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
			wp_die( esc_html__( 'Invalid contract URL.', 'arriendo-facil' ), 400 );
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
			$download_url = $this->build_r2_presigned_get_url( $object_key, 300 );
			if ( is_wp_error( $download_url ) ) {
				return $download_url;
			}

			$response = wp_remote_get(
				$download_url,
				array(
					'timeout' => 45,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status < 200 || $status >= 300 ) {
				return new WP_Error( 'af_lease_source_download_failed', __( 'Could not download active contract version from private storage.', 'arriendo-facil' ) );
			}

			$body = wp_remote_retrieve_body( $response );
			if ( ! is_string( $body ) || '' === $body ) {
				return new WP_Error( 'af_lease_source_empty', __( 'Downloaded contract is empty.', 'arriendo-facil' ) );
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

		return new WP_Error( 'af_lease_source_unavailable', __( 'Could not access active contract version source file.', 'arriendo-facil' ) );
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
			return new WP_Error( 'af_lease_pdf_dir_failed', __( 'Could not create approved contracts directory.', 'arriendo-facil' ) );
		}

		$file_name = sprintf( 'lease-%d-v%d-approved-%s.pdf', absint( $lease_id ), absint( $version ), gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $approved_dir ) . $file_name;

		$written = $this->write_password_protected_pdf_file( $file_path, (string) $contract_text, (string) $pdf_password );
		if ( ! $written ) {
			return new WP_Error( 'af_lease_pdf_write_failed', __( 'Could not generate protected PDF document.', 'arriendo-facil' ) );
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

		$storage_provider = $this->get_storage_setting( 'AF_STORAGE_PROVIDER', 'af_storage_provider', 'cloudflare_r2' );
		if ( 'cloudflare_r2' === $storage_provider ) {
			$r2_config = $this->get_r2_config();
			if ( ! is_wp_error( $r2_config ) ) {
				$pdf_contents = file_get_contents( $file_path );
				if ( false !== $pdf_contents && '' !== $pdf_contents ) {
					$object_key = sprintf( 'lease-contracts/%d/v%d/approved/%s', absint( $lease_id ), absint( $version ), sanitize_file_name( $file_name ) );
					$upload_r2  = $this->upload_contents_to_r2( $pdf_contents, $object_key, 'application/pdf', $r2_config );

					if ( ! is_wp_error( $upload_r2 ) ) {
						$approved_pdf['provider']   = 'cloudflare_r2';
						$approved_pdf['object_key'] = $object_key;
					}
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
}
