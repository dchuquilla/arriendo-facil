<?php
/**
 * Admin interface for Arriendo Fácil.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Admin
 *
 * Sets up the top-level admin menu and sub-pages for the plugin.
 */
class Arriendo_Facil_Admin {

	/**
	 * Constructor – hooks into WordPress admin.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_af_predict_cost', array( $this, 'ajax_predict_cost' ) );
		add_action( 'wp_ajax_af_generate_document', array( $this, 'ajax_generate_document' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Registers the plugin's top-level menu and sub-pages.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Arriendo Fácil', 'arriendo-facil' ),
			__( 'Arriendo Fácil', 'arriendo-facil' ),
			'edit_posts',
			'arriendo-facil',
			array( $this, 'render_dashboard' ),
			'dashicons-building',
			30
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Dashboard', 'arriendo-facil' ),
			__( 'Dashboard', 'arriendo-facil' ),
			'edit_posts',
			'arriendo-facil',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Leases', 'arriendo-facil' ),
			__( 'Leases', 'arriendo-facil' ),
			'edit_posts',
			'af-leases',
			array( $this, 'render_leases' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Cleaning Requests', 'arriendo-facil' ),
			__( 'Cleaning Requests', 'arriendo-facil' ),
			'edit_posts',
			'af-cleaning-requests',
			array( $this, 'render_cleaning_requests' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Owner Contacts', 'arriendo-facil' ),
			__( 'Owner Contacts', 'arriendo-facil' ),
			'manage_options',
			'af-owner-contacts',
			array( $this, 'render_owner_contacts' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'Guests', 'arriendo-facil' ),
			__( 'Guests', 'arriendo-facil' ),
			'edit_posts',
			'af-guests',
			array( $this, 'render_guests' )
		);

		add_submenu_page(
			'arriendo-facil',
			__( 'AI Settings', 'arriendo-facil' ),
			__( 'AI Settings', 'arriendo-facil' ),
			'manage_options',
			'af-ai-settings',
			array( $this, 'render_ai_settings' )
		);
	}

	/**
	 * Enqueues plugin admin CSS and JS.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$admin_css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/admin.css';
		$admin_js_path  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/admin.js';

		$admin_css_version = file_exists( $admin_css_path ) ? (string) filemtime( $admin_css_path ) : ARRIENDO_FACIL_VERSION;
		$admin_js_version  = file_exists( $admin_js_path ) ? (string) filemtime( $admin_js_path ) : ARRIENDO_FACIL_VERSION;

		wp_enqueue_style(
			'af-admin',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$admin_css_version
		);

		wp_enqueue_script(
			'af-admin',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			$admin_js_version,
			true
		);

		$php_post_max_bytes = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		$safe_request_bytes = (int) apply_filters( 'af_owner_contact_safe_request_bytes', min( $php_post_max_bytes, 30 * 1024 * 1024 ) );

		wp_localize_script(
			'af-admin',
			'afAdmin',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'leaseNonce'         => wp_create_nonce( 'af_lease_nonce' ),
				'cleaningNonce'      => wp_create_nonce( 'af_cleaning_request_nonce' ),
				'ownerContactNonce'  => wp_create_nonce( 'af_owner_contact_nonce' ),
				'ownerMaxFileBytes'  => min( wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ), 10 * 1024 * 1024 ),
				'ownerMaxTotalBytes' => $php_post_max_bytes,
				'ownerSafeTotalBytes'=> max( 1, $safe_request_bytes ),
				'guestNonce'         => wp_create_nonce( 'af_guest_nonce' ),
			)
		);
	}

	/**
	 * Registers plugin settings.
	 */
	public function register_settings() {
		register_setting( 'af_ai_settings', 'af_ai_api_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'af_ai_settings', 'af_ai_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	/**
	 * Renders the main dashboard page.
	 */
	public function render_dashboard() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Renders the leases admin page.
	 */
	public function render_leases() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/leases.php';
	}

	/**
	 * Renders the cleaning requests admin page.
	 */
	public function render_cleaning_requests() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/cleaning-requests.php';
	}

	/**
	 * Renders the owner contacts admin page.
	 */
	public function render_owner_contacts() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/owner-contacts.php';
	}

	/**
	 * Renders the guests admin page.
	 */
	public function render_guests() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/guests.php';
	}

	/**
	 * Renders the AI settings page.
	 */
	public function render_ai_settings() {
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/ai-settings.php';
	}

	/**
	 * AJAX handler: predict accommodation cost using AI.
	 */
	public function ajax_predict_cost() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$accommodation_id = isset( $_POST['accommodation_id'] ) ? absint( $_POST['accommodation_id'] ) : 0;
		if ( ! $accommodation_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid accommodation ID.', 'arriendo-facil' ) ) );
		}

		$data = array(
			'post_id'      => $accommodation_id,
			'address'      => get_post_meta( $accommodation_id, '_af_address', true ),
			'bedrooms'     => get_post_meta( $accommodation_id, '_af_bedrooms', true ),
			'bathrooms'    => get_post_meta( $accommodation_id, '_af_bathrooms', true ),
			'monthly_rent' => get_post_meta( $accommodation_id, '_af_monthly_rent', true ),
		);

		$ai       = new Arriendo_Facil_AI_Service();
		$result   = $ai->predict_cost( $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: generate a lease document using AI.
	 */
	public function ajax_generate_document() {
		check_ajax_referer( 'af_lease_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'arriendo-facil' ) ), 403 );
		}

		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		if ( ! $lease_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid lease ID.', 'arriendo-facil' ) ) );
		}

		$lease_obj = new Arriendo_Facil_Lease();
		$lease     = $lease_obj->get_lease( $lease_id );

		if ( ! $lease ) {
			wp_send_json_error( array( 'message' => __( 'Lease not found.', 'arriendo-facil' ) ) );
		}

		$ai_payload = $this->build_lease_ai_payload( $lease );

		$ai     = new Arriendo_Facil_AI_Service();
		$result = $ai->generate_document( $ai_payload );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$document_url = '';
		if ( isset( $result['document_url'] ) && is_string( $result['document_url'] ) ) {
			$document_url = esc_url_raw( $result['document_url'] );
		}

		if ( '' === $document_url && isset( $result['contract_text'] ) && is_string( $result['contract_text'] ) ) {
			$document_url = $this->create_generated_contract_file( $lease_id, $result['contract_text'] );
		}

		if ( $document_url ) {
			$lease_obj->attach_document( $lease_id, $document_url );
			$result['document_url'] = $document_url;
		} else {
			wp_send_json_error( array( 'message' => __( 'AI did not return a usable contract document.', 'arriendo-facil' ) ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Builds enriched AI payload for lease document generation.
	 *
	 * @param object $lease Lease row object.
	 * @return array<string,mixed>
	 */
	private function build_lease_ai_payload( $lease ) {
		$lease_arr         = (array) $lease;
		$accommodation_id  = isset( $lease_arr['accommodation_id'] ) ? absint( $lease_arr['accommodation_id'] ) : 0;
		$guest_id          = isset( $lease_arr['guest_id'] ) ? absint( $lease_arr['guest_id'] ) : 0;
		$owner_template    = $this->get_owner_contract_example_context( $accommodation_id );
		$accommodation     = array(
			'title'   => (string) get_the_title( $accommodation_id ),
			'address' => (string) get_post_meta( $accommodation_id, '_af_address', true ),
		);

		$guest_payload = array();
		if ( $guest_id ) {
			global $wpdb;
			$guest_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d",
					$guest_id
				)
			);

			if ( $guest_row ) {
				$guest_payload = array(
					'guest_name' => trim( (string) $guest_row->first_name . ' ' . (string) $guest_row->last_name ),
					'guest_email' => (string) $guest_row->email,
					'guest_phone' => (string) $guest_row->phone,
					'guest_id_number' => (string) $guest_row->id_number,
					'mascotas' => isset( $guest_row->mascotas ) ? absint( $guest_row->mascotas ) : 0,
					'referencia_personal_1' => isset( $guest_row->referencia_personal_1 ) ? (string) $guest_row->referencia_personal_1 : '',
					'referencia_personal_2' => isset( $guest_row->referencia_personal_2 ) ? (string) $guest_row->referencia_personal_2 : '',
					'personas_viviran' => isset( $guest_row->personas_viviran ) ? absint( $guest_row->personas_viviran ) : 0,
					'rental_mode' => isset( $guest_row->rental_mode ) ? (string) $guest_row->rental_mode : '',
					'rental_start_date' => isset( $guest_row->rental_start_date ) ? (string) $guest_row->rental_start_date : '',
					'rental_end_date' => isset( $guest_row->rental_end_date ) ? (string) $guest_row->rental_end_date : '',
					'rental_months' => isset( $guest_row->rental_months ) ? absint( $guest_row->rental_months ) : 0,
					'rental_years' => isset( $guest_row->rental_years ) ? absint( $guest_row->rental_years ) : 0,
					'desired_price' => isset( $guest_row->desired_price ) ? (string) $guest_row->desired_price : '',
					'guarantee_text' => isset( $guest_row->guarantee_text ) ? (string) $guest_row->guarantee_text : '',
				);
			}
		}

		return array_merge(
			$lease_arr,
			$guest_payload,
			array(
				'accommodation_title' => sanitize_text_field( (string) $accommodation['title'] ),
				'accommodation_address' => sanitize_text_field( (string) $accommodation['address'] ),
				'template_available' => ! empty( $owner_template['attachment_id'] ),
				'template_name' => isset( $owner_template['file_name'] ) ? sanitize_text_field( (string) $owner_template['file_name'] ) : '',
				'template_mime' => isset( $owner_template['mime_type'] ) ? sanitize_text_field( (string) $owner_template['mime_type'] ) : '',
				'template_url' => isset( $owner_template['url'] ) ? esc_url_raw( (string) $owner_template['url'] ) : '',
				'template_text' => isset( $owner_template['template_text'] ) ? (string) $owner_template['template_text'] : '',
				'owner_user_id' => isset( $owner_template['owner_user_id'] ) ? absint( $owner_template['owner_user_id'] ) : 0,
				'owner_name' => isset( $owner_template['owner_name'] ) ? sanitize_text_field( (string) $owner_template['owner_name'] ) : '',
				'owner_email' => isset( $owner_template['owner_email'] ) ? sanitize_email( (string) $owner_template['owner_email'] ) : '',
			)
		);
	}

	/**
	 * Finds latest contract example uploaded by the owner of an accommodation.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return array<string,mixed>
	 */
	private function get_owner_contract_example_context( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		$owner_user_id = absint( get_post_meta( $accommodation_id, '_af_owner_id', true ) );

		if ( ! $owner_user_id ) {
			return array();
		}

		$attachment_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_af_owner_contract_example',
						'value' => '1',
					),
					array(
						'key'   => '_af_owner_user_id',
						'value' => (string) $owner_user_id,
					),
				),
			)
		);

		$attachment_id = ! empty( $attachment_ids ) ? absint( $attachment_ids[0] ) : 0;
		if ( ! $attachment_id ) {
			return array();
		}

		return $this->build_contract_template_context_from_attachment( $attachment_id, $owner_user_id );
	}

	/**
	 * Builds standardized contract template context from an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $fallback_owner_user_id Owner user fallback ID.
	 * @return array<string,mixed>
	 */
	private function build_contract_template_context_from_attachment( $attachment_id, $fallback_owner_user_id = 0 ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array();
		}

		$owner_user_id = absint( get_post_meta( $attachment_id, '_af_owner_user_id', true ) );
		if ( ! $owner_user_id ) {
			$owner_user_id = absint( $fallback_owner_user_id );
		}

		$path          = get_attached_file( $attachment_id );
		$mime_type     = (string) get_post_mime_type( $attachment_id );
		$template_text = $this->extract_contract_template_text( $path, $mime_type );
		$owner_user    = get_user_by( 'id', $owner_user_id );

		return array(
			'attachment_id' => $attachment_id,
			'owner_user_id' => $owner_user_id,
			'owner_name'    => $owner_user ? (string) $owner_user->display_name : '',
			'owner_email'   => $owner_user ? (string) $owner_user->user_email : '',
			'file_name'     => $path ? wp_basename( $path ) : '',
			'mime_type'     => $mime_type,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'template_text' => $template_text,
		);
	}

	/**
	 * Extracts plain text from contract template when supported.
	 *
	 * @param string $file_path File path.
	 * @param string $mime_type Mime type.
	 * @return string
	 */
	private function extract_contract_template_text( $file_path, $mime_type ) {
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return '';
		}

		$mime_type = strtolower( (string) $mime_type );
		$extension = strtolower( (string) pathinfo( (string) $file_path, PATHINFO_EXTENSION ) );

		if ( false !== strpos( $mime_type, 'text/' ) ) {
			$content = file_get_contents( $file_path );
			if ( false !== $content ) {
				return $this->limit_template_text( wp_strip_all_tags( (string) $content ) );
			}

			return '';
		}

		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime_type || 'docx' === $extension ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( true === $zip->open( $file_path ) ) {
					$xml = $zip->getFromName( 'word/document.xml' );
					$zip->close();

					if ( false !== $xml && '' !== $xml ) {
						$text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $xml ) );
						return $this->limit_template_text( (string) $text );
					}
				}
			}

			return '';
		}

		if ( false !== strpos( $mime_type, 'application/pdf' ) || 'pdf' === $extension ) {
			return $this->limit_template_text( $this->extract_text_from_pdf_file( $file_path ) );
		}

		if ( false !== strpos( $mime_type, 'application/msword' ) || 'doc' === $extension ) {
			return $this->limit_template_text( $this->extract_text_from_legacy_doc_file( $file_path ) );
		}

		$content = file_get_contents( $file_path );
		if ( false !== $content ) {
			$fallback_text = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', (string) $content );
			return $this->limit_template_text( (string) $fallback_text );
		}

		return '';
	}

	/**
	 * Extracts readable text from a PDF file using basic stream parsing.
	 *
	 * @param string $file_path PDF path.
	 * @return string
	 */
	private function extract_text_from_pdf_file( $file_path ) {
		$content = file_get_contents( $file_path );
		if ( false === $content || '' === $content ) {
			return '';
		}

		$buffers = array( (string) $content );
		if ( preg_match_all( '/stream(.*?)endstream/s', (string) $content, $stream_matches ) ) {
			foreach ( $stream_matches[1] as $stream ) {
				$stream = ltrim( (string) $stream, "\r\n" );
				$stream = rtrim( $stream, "\r\n" );

				$decoded = @gzuncompress( $stream );
				if ( false === $decoded ) {
					$decoded = @gzinflate( $stream );
				}

				$buffers[] = ( false !== $decoded && '' !== $decoded ) ? (string) $decoded : $stream;
			}
		}

		$text_chunks = array();
		foreach ( $buffers as $buffer ) {
			if ( preg_match_all( '/\((.*?)\)\s*Tj/s', (string) $buffer, $matches ) ) {
				foreach ( $matches[1] as $token ) {
					$decoded_token = $this->decode_pdf_text_token( (string) $token );
					if ( '' !== $decoded_token ) {
						$text_chunks[] = $decoded_token;
					}
				}
			}

			if ( preg_match_all( '/\[(.*?)\]\s*TJ/s', (string) $buffer, $matches ) ) {
				foreach ( $matches[1] as $array_body ) {
					if ( preg_match_all( '/\((.*?)\)/s', (string) $array_body, $token_matches ) ) {
						foreach ( $token_matches[1] as $token ) {
							$decoded_token = $this->decode_pdf_text_token( (string) $token );
							if ( '' !== $decoded_token ) {
								$text_chunks[] = $decoded_token;
							}
						}
					}
				}
			}
		}

		if ( empty( $text_chunks ) ) {
			return '';
		}

		return trim( preg_replace( '/\s+/', ' ', implode( ' ', $text_chunks ) ) );
	}

	/**
	 * Decodes escaped PDF text token content.
	 *
	 * @param string $token Token text.
	 * @return string
	 */
	private function decode_pdf_text_token( $token ) {
		$token = preg_replace_callback(
			'/\\\\([0-7]{1,3})/',
			static function ( $matches ) {
				return chr( octdec( $matches[1] ) );
			},
			(string) $token
		);

		$token = strtr(
			(string) $token,
			array(
				'\\n'   => "\n",
				'\\r'   => "\r",
				'\\t'   => "\t",
				'\\b'   => '',
				'\\f'   => '',
				'\\('   => '(',
				'\\)'   => ')',
				'\\\\' => '\\',
			)
		);

		$token = wp_strip_all_tags( $token );
		$token = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', (string) $token );

		return trim( (string) $token );
	}

	/**
	 * Extracts rough text from legacy .doc binary file.
	 *
	 * @param string $file_path DOC path.
	 * @return string
	 */
	private function extract_text_from_legacy_doc_file( $file_path ) {
		$content = file_get_contents( $file_path );
		if ( false === $content || '' === $content ) {
			return '';
		}

		$text = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', (string) $content );
		$text = preg_replace( '/\s+/', ' ', (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Truncates template text before sending it to AI.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function limit_template_text( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

		if ( '' === $text ) {
			return '';
		}

		if ( strlen( $text ) > 8000 ) {
			return substr( $text, 0, 8000 );
		}

		return $text;
	}

	/**
	 * Saves AI-generated contract into DOCX and returns secure URL.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $contract_text Contract text.
	 * @return string
	 */
	private function create_generated_contract_file( $lease_id, $contract_text ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id || '' === trim( $contract_text ) ) {
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		$contracts_dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/contracts';
		if ( ! wp_mkdir_p( $contracts_dir ) ) {
			return '';
		}

		$file_name = sprintf( 'lease-%d-contract-admin-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;
		$mime_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		if ( ! $this->write_contract_docx_file( $file_path, $contract_text ) ) {
			$file_name = sprintf( 'lease-%d-contract-admin-%s.doc', $lease_id, gmdate( 'Ymd-His' ) );
			$file_path = trailingslashit( $contracts_dir ) . $file_name;
			$mime_type = 'application/msword';
			if ( ! $this->write_contract_doc_fallback_file( $file_path, $contract_text ) ) {
				return '';
			}
		}

		$local_url     = trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name );
		$document_url  = $local_url;
		$storage_meta  = array(
			'provider'  => 'local',
			'file_name' => $file_name,
			'local_url' => $local_url,
			'mime_type' => $mime_type,
		);

		$storage_provider = $this->get_storage_setting( 'AF_STORAGE_PROVIDER', 'af_storage_provider', 'cloudflare_r2' );
		if ( 'cloudflare_r2' === $storage_provider ) {
			$r2_config = $this->get_r2_config();
			if ( ! is_wp_error( $r2_config ) ) {
				$contents = file_get_contents( $file_path );
				if ( false !== $contents ) {
					$object_key = sprintf( 'lease-contracts/%d/%s', $lease_id, sanitize_file_name( $file_name ) );
					$upload     = $this->upload_contents_to_r2(
						$contents,
						$object_key,
						$mime_type,
						$r2_config
					);
					if ( ! is_wp_error( $upload ) ) {
						$document_url = add_query_arg(
							array(
								'action'   => 'af_download_lease_contract',
								'lease_id' => $lease_id,
							),
							admin_url( 'admin-ajax.php' )
						);
						$storage_meta = array(
							'provider'   => 'cloudflare_r2',
							'object_key' => $object_key,
							'file_name'  => $file_name,
							'local_url'  => $local_url,
							'mime_type'  => $mime_type,
						);
					}
				}
			}
		}

		if ( class_exists( 'Arriendo_Facil_Lease' ) ) {
			$lease_service = new Arriendo_Facil_Lease();
			$lease_service->set_contract_storage_meta( $lease_id, $storage_meta );
		}

		return $document_url;
	}

	/**
	 * Writes a fallback MS Word-compatible HTML document when DOCX is unavailable.
	 *
	 * @param string $file_path Destination path.
	 * @param string $contract_text Contract text.
	 * @return bool
	 */
	private function write_contract_doc_fallback_file( $file_path, $contract_text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $contract_text );
		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $contract_text );
		}

		$body = '';
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				$body .= '<p>&nbsp;</p>';
				continue;
			}

			$body .= '<p>' . esc_html( $line ) . '</p>';
		}

		if ( '' === $body ) {
			$body = '<p>Contrato</p>';
		}

		$html = '<html><head><meta charset="UTF-8"></head><body style="font-family:Times New Roman, serif; font-size:12pt;">' . $body . '</body></html>';

		return false !== file_put_contents( $file_path, $html );
	}

	/**
	 * Writes a minimal DOCX file from plain contract text.
	 *
	 * @param string $file_path Destination path.
	 * @param string $contract_text Contract text.
	 * @return bool
	 */
	private function write_contract_docx_file( $file_path, $contract_text ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$escaped_lines = array();
		$lines         = preg_split( '/\r\n|\r|\n/', (string) $contract_text );
		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $contract_text );
		}

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				$escaped_lines[] = '<w:p/>';
				continue;
			}

			$escaped_lines[] = '<w:p><w:r><w:t xml:space="preserve">' . esc_xml( $line ) . '</w:t></w:r></w:p>';
		}

		if ( empty( $escaped_lines ) ) {
			$escaped_lines[] = '<w:p><w:r><w:t xml:space="preserve">Contrato</w:t></w:r></w:p>';
		}

		$document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:w10="urn:schemas-microsoft-com:office:word" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml" xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup" xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk" xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml" xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" mc:Ignorable="w14 w15 wp14">'
			. '<w:body>' . implode( '', $escaped_lines ) . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>';

		$content_types_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '</Types>';

		$rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			. '</Relationships>';

		$doc_rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

		$zip->addFromString( '[Content_Types].xml', $content_types_xml );
		$zip->addFromString( '_rels/.rels', $rels_xml );
		$zip->addFromString( 'word/document.xml', $document_xml );
		$zip->addFromString( 'word/_rels/document.xml.rels', $doc_rels_xml );

		return $zip->close();
	}

	/**
	 * Reads storage setting with constant priority.
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
			return new WP_Error( 'af_r2_upload_failed', __( 'Cloudflare R2 rejected the generated contract file.', 'arriendo-facil' ) );
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
}
