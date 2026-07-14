<?php
/**
 * Accommodation creation/edition wizard.
 *
 * Replaces the default WP post editor for the `accommodation` CPT with a
 * focused multi-step screen. Reuses the existing meta and gallery sanitization
 * hooked to `save_post_accommodation` so business rules stay in one place.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Accommodation_Wizard {

	const PAGE_SLUG     = 'af-accommodation-form';
	const NONCE_ACTION  = 'af_save_accommodation_wizard';
	const NONCE_NAME    = 'af_wizard_nonce';
	const SUBMIT_ACTION = 'af_save_accommodation_wizard';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 11 );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::SUBMIT_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'admin_notices', array( $this, 'render_post_publish_notice' ) );
	}

	/**
	 * Registers the hidden wizard admin page.
	 */
	public function register_page() {
		add_submenu_page(
			'',
			__( 'Inmueble', 'arriendo-facil' ),
			__( 'Inmueble', 'arriendo-facil' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Redirects classic post editor URLs to the wizard page.
	 */
	public function maybe_redirect() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! empty( $_GET['af_classic_editor'] ) && current_user_can( 'manage_options' ) ) {
			return;
		}

		global $pagenow;

		if ( 'post-new.php' === $pagenow && isset( $_GET['post_type'] ) && 'accommodation' === $_GET['post_type'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		if ( 'post.php' === $pagenow && isset( $_GET['action'], $_GET['post'] ) && 'edit' === $_GET['action'] ) {
			$pid = absint( wp_unslash( $_GET['post'] ) );
			if ( $pid && 'accommodation' === get_post_type( $pid ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&id=' . $pid ) );
				exit;
			}
		}
	}

	/**
	 * Enqueues assets only on the wizard screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'admin_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'leaflet-css',
			'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css',
			array(),
			'1.9.4'
		);
		wp_enqueue_script(
			'leaflet-js',
			'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js',
			array(),
			'1.9.4',
			true
		);

		$picker_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/admin-location-picker.js';
		wp_enqueue_script(
			'af-location-picker',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/admin-location-picker.js',
			array( 'jquery', 'leaflet-js' ),
			file_exists( $picker_path ) ? (string) filemtime( $picker_path ) : ARRIENDO_FACIL_VERSION,
			true
		);
		wp_localize_script(
			'af-location-picker',
			'afLocationPicker',
			array(
				'defaultLat'    => -0.1807,
				'defaultLng'    => -78.4678,
				'ecuadorBounds' => array( 'latMin' => -5, 'latMax' => 2, 'lngMin' => -81, 'lngMax' => -75 ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'af_location_nonce' ),
			)
		);

		$admin_js_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/admin.js';
		// af-admin (handle) and afAdmin localization are already enqueued by
		// Arriendo_Facil_Admin::enqueue_assets() on every admin page, so we do
		// not re-enqueue here. We only need to ensure our wizard script lists
		// af-admin as a dependency.

		$wizard_css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/admin-wizard.css';
		$wizard_js_path  = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/admin-wizard.js';

		wp_enqueue_style(
			'af-admin-wizard',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/admin-wizard.css',
			array( 'af-admin' ),
			file_exists( $wizard_css_path ) ? (string) filemtime( $wizard_css_path ) : ARRIENDO_FACIL_VERSION
		);
		wp_enqueue_script(
			'af-admin-wizard',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/admin-wizard.js',
			array( 'jquery', 'af-admin', 'af-location-picker' ),
			file_exists( $wizard_js_path ) ? (string) filemtime( $wizard_js_path ) : ARRIENDO_FACIL_VERSION,
			true
		);

		// Input normalizer: capitalise address/city on blur.
		$normalizer_path = get_stylesheet_directory() . '/assets/js/input-normalizer.js';
		$normalizer_url  = get_stylesheet_directory_uri() . '/assets/js/input-normalizer.js';
		wp_enqueue_script(
			'af-input-normalizer',
			$normalizer_url,
			array(),
			file_exists( $normalizer_path ) ? (string) filemtime( $normalizer_path ) : ARRIENDO_FACIL_VERSION,
			true
		);

		$post_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		wp_localize_script(
			'af-admin-wizard',
			'afWizard',
			array(
				'mode'       => $post_id ? 'edit' : 'create',
				'postId'     => $post_id,
				'listUrl'    => admin_url( 'edit.php?post_type=accommodation' ),
				'i18n'       => array(
					'unsavedChanges'   => __( '¿Salir sin guardar los cambios?', 'arriendo-facil' ),
					'missingTitle'     => __( 'Escribe un nombre para el inmueble.', 'arriendo-facil' ),
					'missingType'      => __( 'Selecciona el tipo de propiedad.', 'arriendo-facil' ),
					'missingAddress'   => __( 'Ingresa la dirección.', 'arriendo-facil' ),
					'missingCity'      => __( 'Ingresa la ciudad.', 'arriendo-facil' ),
					'missingMap'       => __( 'Marca la ubicación en el mapa o busca una dirección.', 'arriendo-facil' ),
					'missingRent'      => __( 'Ingresa el valor del arriendo mensual.', 'arriendo-facil' ),
					'photosRecommended'=> __( 'Recomendamos agregar al menos una foto antes de publicar.', 'arriendo-facil' ),
					'pickFeatured'     => __( 'Seleccionar foto principal', 'arriendo-facil' ),
					'useAsFeatured'    => __( 'Usar como portada', 'arriendo-facil' ),
					'needSaveForAI'    => __( 'Guarda primero como borrador para sugerir precio con IA.', 'arriendo-facil' ),
					'publishing'       => __( 'Publicando inmueble...', 'arriendo-facil' ),
					'savingChanges'    => __( 'Guardando cambios...', 'arriendo-facil' ),
					'savingDraft'      => __( 'Guardando borrador...', 'arriendo-facil' ),
					'ownerSelf'        => __( 'Tu cuenta', 'arriendo-facil' ),
				),
			)
		);

		// OTA Sync script
		$ota_sync_js_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/js/admin-ota-sync.js';
		wp_enqueue_script(
			'af-ota-sync',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/js/admin-ota-sync.js',
			array( 'jquery', 'af-admin' ),
			file_exists( $ota_sync_js_path ) ? (string) filemtime( $ota_sync_js_path ) : ARRIENDO_FACIL_VERSION,
			true
		);

		wp_localize_script( 'af-ota-sync', 'afOtaSync', array(
			'nonce' => wp_create_nonce( 'af_ota_nonce' ),
		) );
	}

	/**
	 * Renders the wizard screen.
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'arriendo-facil' ), 403 );
		}

		$post_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$post    = null;
		$mode    = 'create';

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'accommodation' !== $post->post_type ) {
				wp_die( esc_html__( 'Inmueble no encontrado.', 'arriendo-facil' ), 404 );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_die( esc_html__( 'No tienes permisos para editar este inmueble.', 'arriendo-facil' ), 403 );
			}
			if ( Arriendo_Facil_Accommodation::user_is_owner() ) {
				$existing_owner = (int) get_post_meta( $post_id, '_af_owner_id', true );
				if ( $existing_owner && $existing_owner !== get_current_user_id() ) {
					wp_die( esc_html__( 'Este inmueble pertenece a otro propietario.', 'arriendo-facil' ), 403 );
				}
			}
			$mode = 'edit';
		}

		$is_owner_user = Arriendo_Facil_Accommodation::user_is_owner( get_current_user_id() );
		$saved_flag    = isset( $_GET['saved'] ) ? sanitize_key( wp_unslash( $_GET['saved'] ) ) : '';
		$error_message = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		$defaults = array(
			'address'       => '',
			'location_text' => '',
			'city'          => '',
			'latitude'      => '',
			'longitude'     => '',
			'bedrooms'      => 0,
			'bathrooms'     => 0,
			'shared_bathroom' => 0,
			'monthly_rent'  => '',
			'property_type' => '',
			'square_meters' => '',
			'amenities'     => array(),
			'owner_id'      => 0,
			'status'        => 'available',
			'featured_id'   => 0,
			'gallery_ids'   => array(),
			'post_title'    => '',
			'post_content'  => '',
		);

		$data = $defaults;
		if ( $post ) {
			$data['post_title']    = $post->post_title;
			$data['post_content']  = $post->post_content;
			$data['address']       = get_post_meta( $post_id, '_af_address', true );
			$data['location_text'] = get_post_meta( $post_id, '_af_location_text', true );
			$data['city']          = get_post_meta( $post_id, '_af_city', true );
			$data['latitude']      = get_post_meta( $post_id, '_af_latitude', true );
			$data['longitude']     = get_post_meta( $post_id, '_af_longitude', true );
			$data['bedrooms']      = (int) get_post_meta( $post_id, '_af_bedrooms', true );
			$data['bathrooms']     = (int) get_post_meta( $post_id, '_af_bathrooms', true );
			$data['shared_bathroom'] = (int) get_post_meta( $post_id, '_af_shared_bathroom', true );
			$data['monthly_rent']  = get_post_meta( $post_id, '_af_monthly_rent', true );
			$data['property_type'] = get_post_meta( $post_id, '_af_property_type', true );
			$data['square_meters'] = get_post_meta( $post_id, '_af_square_meters', true );
			$amenities             = get_post_meta( $post_id, '_af_amenities', true );
			$data['amenities']     = is_array( $amenities ) ? $amenities : array();
			$data['owner_id']      = (int) get_post_meta( $post_id, '_af_owner_id', true );
			$data['status']        = get_post_meta( $post_id, '_af_status', true );
			$data['featured_id']   = (int) get_post_thumbnail_id( $post_id );
			$gallery               = get_post_meta( $post_id, '_af_gallery', true );
			$data['gallery_ids']   = is_array( $gallery ) ? array_values( array_filter( array_map( 'absint', $gallery ) ) ) : array();
		}

		$owner_options = $this->get_owner_user_options();

		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/accommodation-wizard.php';
	}

	/**
	 * Handles wizard submissions through admin-post.php.
	 */
	public function handle_submit() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'arriendo-facil' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$post_id     = isset( $_POST['af_post_id'] ) ? absint( wp_unslash( $_POST['af_post_id'] ) ) : 0;
		$post_id_was_existing = $post_id > 0;
		$form_action = isset( $_POST['af_form_action'] ) ? sanitize_key( wp_unslash( $_POST['af_form_action'] ) ) : 'publish';

		if ( 'cancel' === $form_action ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=accommodation' ) );
			exit;
		}

		if ( $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_die( esc_html__( 'No tienes permisos para editar.', 'arriendo-facil' ), 403 );
			}
			if ( 'accommodation' !== get_post_type( $post_id ) ) {
				wp_die( esc_html__( 'Inmueble inválido.', 'arriendo-facil' ), 400 );
			}
			if ( Arriendo_Facil_Accommodation::user_is_owner() ) {
				$existing_owner = (int) get_post_meta( $post_id, '_af_owner_id', true );
				if ( $existing_owner && $existing_owner !== get_current_user_id() ) {
					wp_die( esc_html__( 'Inmueble de otro propietario.', 'arriendo-facil' ), 403 );
				}
			}
		}

		$title = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : '';
		if ( '' === trim( $title ) ) {
			$redirect = add_query_arg(
				array(
					'page'  => self::PAGE_SLUG,
					'id'    => $post_id ? $post_id : false,
					'error' => rawurlencode( __( 'El nombre del inmueble es obligatorio.', 'arriendo-facil' ) ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$post_status = ( 'draft' === $form_action ) ? 'draft' : 'publish';

		$payload = array(
			'post_type'    => 'accommodation',
			'post_status'  => $post_status,
			'post_title'   => $title,
			'post_content' => isset( $_POST['post_content'] ) ? wp_kses_post( wp_unslash( $_POST['post_content'] ) ) : '',
		);

		// Inject nonces so the existing save_meta / save_gallery_meta handlers
		// (hooked to save_post_accommodation) accept the request when wp_insert_post
		// or wp_update_post fires the action.
		$_POST['af_accommodation_nonce'] = wp_create_nonce( 'af_save_accommodation_meta' );
		$_POST['af_gallery_nonce']       = wp_create_nonce( 'af_save_gallery' );

		if ( $post_id ) {
			$payload['ID'] = $post_id;
			$result        = wp_update_post( wp_slash( $payload ), true );
		} else {
			$payload['post_author'] = get_current_user_id();
			$result                 = wp_insert_post( wp_slash( $payload ), true );
		}

		if ( is_wp_error( $result ) || ! $result ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'No se pudo guardar el inmueble.', 'arriendo-facil' );
			$redirect = add_query_arg(
				array(
					'page'  => self::PAGE_SLUG,
					'id'    => $post_id ? $post_id : false,
					'error' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$post_id = (int) $result;

		// Featured image after save (set_post_thumbnail is idempotent).
		$featured_id = isset( $_POST['af_featured_image_id'] ) ? absint( wp_unslash( $_POST['af_featured_image_id'] ) ) : 0;
		if ( $featured_id ) {
			set_post_thumbnail( $post_id, $featured_id );
		} else {
			delete_post_thumbnail( $post_id );
		}

		$is_first_publish = ! $post_id_was_existing && 'draft' !== $form_action;

		if ( 'draft' === $form_action ) {
			$redirect = add_query_arg(
				array(
					'page'  => self::PAGE_SLUG,
					'id'    => $post_id,
					'saved' => 'draft',
				),
				admin_url( 'admin.php' )
			);
		} elseif ( $is_first_publish ) {
			set_transient(
				'af_wizard_notice_' . get_current_user_id(),
				array(
					'type'    => 'success',
					'message' => __( 'Inmueble publicado correctamente.', 'arriendo-facil' ),
				),
				60
			);
			$redirect = admin_url( 'edit.php?post_type=accommodation' );
		} else {
			$redirect = add_query_arg(
				array(
					'page'  => self::PAGE_SLUG,
					'id'    => $post_id,
					'saved' => '1',
				),
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Emits a one-time admin notice on the accommodation list screen after publish.
	 */
	public function render_post_publish_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-accommodation' !== $screen->id ) {
			return;
		}
		$key    = 'af_wizard_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$class = 'success' === ( $notice['type'] ?? 'info' ) ? 'notice-success' : 'notice-info';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Returns owner options for the assignment dropdown.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_owner_user_options() {
		global $wpdb;

		$owner_user_ids = $wpdb->get_col(
			"SELECT DISTINCT wp_user_id FROM {$wpdb->prefix}af_owner_contacts WHERE wp_user_id IS NOT NULL AND wp_user_id > 0"
		);

		$role_owner_users = get_users( array( 'role' => 'af_owner', 'fields' => 'ID' ) );
		if ( is_array( $role_owner_users ) ) {
			$owner_user_ids = array_merge( is_array( $owner_user_ids ) ? $owner_user_ids : array(), $role_owner_users );
		}

		$owner_user_ids = array_values( array_unique( array_map( 'absint', is_array( $owner_user_ids ) ? $owner_user_ids : array() ) ) );
		if ( empty( $owner_user_ids ) ) {
			return array();
		}

		$users   = get_users( array( 'include' => $owner_user_ids, 'orderby' => 'display_name', 'order' => 'ASC' ) );
		$options = array();
		foreach ( $users as $user ) {
			$options[] = array(
				'id'    => (int) $user->ID,
				'label' => (string) $user->display_name . ' (' . (string) $user->user_email . ')',
			);
		}
		return $options;
	}
}
