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

		if ( false !== strpos( $mime_type, 'text/' ) ) {
			$content = file_get_contents( $file_path );
			if ( false !== $content ) {
				return $this->limit_template_text( wp_strip_all_tags( (string) $content ) );
			}

			return '';
		}

		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime_type && class_exists( 'ZipArchive' ) ) {
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
	 * Saves AI-generated contract text into uploads and returns URL.
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

		$file_name = sprintf( 'lease-%d-contract-admin-%s.txt', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;

		if ( false === file_put_contents( $file_path, (string) $contract_text . "\n" ) ) {
			return '';
		}

		return trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name );
	}
}
