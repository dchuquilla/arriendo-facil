<?php
/**
 * Accommodation Custom Post Type.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Accommodation
 *
 * Registers the 'accommodation' CPT and its meta fields.
 */
class Arriendo_Facil_Accommodation {

	/**
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_accommodation', array( $this, 'save_meta' ) );
		add_action( 'save_post_accommodation', array( $this, 'save_gallery_meta' ) );
		add_action( 'save_post_accommodation', array( $this, 'save_documents_meta' ) );
		add_filter( 'enter_title_here', array( $this, 'customize_title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( $this, 'render_editor_intro' ) );
		add_filter( 'admin_post_thumbnail_html', array( $this, 'customize_thumbnail_label' ), 10, 3 );
		add_action( 'pre_get_posts', array( $this, 'force_home_queries_to_accommodations' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
		add_action( 'wp_ajax_af_estimate_rent', array( $this, 'ajax_estimate_rent' ) );

		// Catalogo publico: solo aplica al modelo marketplace, no a la administracion interna.
		if ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) {
			add_shortcode( 'af_propiedad_destacada', array( $this, 'render_featured_accommodation_shortcode' ) );
			add_shortcode( 'propiedad_destacada', array( $this, 'render_featured_accommodation_shortcode' ) );
			add_shortcode( 'af_acomodaciones_ocupadas', array( $this, 'render_occupied_accommodations_shortcode' ) );
			add_shortcode( 'acomodaciones_ocupadas', array( $this, 'render_occupied_accommodations_shortcode' ) );
			add_shortcode( 'af_propiedades_gestion', array( $this, 'render_managed_accommodations_shortcode' ) );
			add_shortcode( 'propiedades_bajo_gestion', array( $this, 'render_managed_accommodations_shortcode' ) );
			add_shortcode( 'accommodations', array( $this, 'render_managed_accommodations_shortcode' ) );
			add_filter( 'the_content', array( $this, 'append_single_accommodation_details' ), 20 );
			add_filter( 'elementor/frontend/the_content', array( $this, 'append_single_accommodation_details' ), 20 );
			add_filter( 'the_content', array( $this, 'inject_managed_accommodations_in_content' ), 999 );
			add_filter( 'elementor/frontend/the_content', array( $this, 'inject_managed_accommodations_in_content' ), 999 );
		}
	}

	/**
	 * AJAX: returns the automatic statistical rent estimate for comparable properties.
	 *
	 * @return void
	 */
	public function ajax_estimate_rent() {
		check_ajax_referer( 'af_save_accommodation_meta', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$estimate = self::estimate_monthly_rent(
			isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
			isset( $_POST['property_type'] ) ? sanitize_text_field( wp_unslash( $_POST['property_type'] ) ) : '',
			isset( $_POST['bedrooms'] ) ? absint( wp_unslash( $_POST['bedrooms'] ) ) : 0,
			isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0
		);

		wp_send_json_success( $estimate );
	}

	/**
	 * Ensures homepage post-based queries display accommodations instead.
	 *
	 * @param WP_Query $query Query instance.
	 * @return void
	 */
	public function force_home_queries_to_accommodations( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return;
		}

		if ( is_admin() ) {
			$this->restrict_admin_accommodation_queries_to_owner( $query );
			return;
		}

		// Sin catalogo publico no se reescribe la home.
		if ( ! ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES ) ) {
			return;
		}

		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$home_request = is_front_page() || is_home();
		if ( ! $home_request ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$path        = strtolower( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) );
			$path        = trim( $path, '/' );
			$home_request = '' === $path;
		}

		if ( ! $home_request ) {
			return;
		}

		if ( $query->is_singular() || $query->get( 'page_id' ) || $query->get( 'pagename' ) ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$is_posts_query = false;

		if ( is_array( $post_type ) ) {
			$is_posts_query = in_array( 'post', $post_type, true );
		} elseif ( empty( $post_type ) ) {
			$is_posts_query = true;
		} else {
			$is_posts_query = 'post' === $post_type;
		}

		if ( ! $is_posts_query ) {
			return;
		}

		$query->set( 'post_type', 'accommodation' );
		$query->set( 'post_status', 'publish' );
		$query->set( 'ignore_sticky_posts', true );
		$query->set( 'posts_per_page', 100 );
		$query->set( 'nopaging', false );
		$paged = get_query_var( 'paged' ) ? absint( get_query_var( 'paged' ) ) : 1;
		$query->set( 'paged', $paged );

		$featured_args = $this->get_featured_query_args();
		if ( ! empty( $featured_args['tax_query'] ) ) {
			$query->set( 'tax_query', $featured_args['tax_query'] );
		}
		if ( ! empty( $featured_args['meta_query'] ) ) {
			$query->set( 'meta_query', $featured_args['meta_query'] );
		}
		if ( ! empty( $featured_args['post__in'] ) ) {
			$query->set( 'post__in', $featured_args['post__in'] );
		}

		if ( ! $query->get( 'orderby' ) ) {
			$query->set( 'orderby', 'date' );
		}
		if ( ! $query->get( 'order' ) ) {
			$query->set( 'order', 'DESC' );
		}
	}

	/**
	 * Registers the accommodation CPT.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Inmuebles', 'arriendo-facil' ),
			'singular_name'      => __( 'Inmueble', 'arriendo-facil' ),
			'menu_name'          => __( 'Inmuebles', 'arriendo-facil' ),
			'add_new'            => __( 'Agregar nuevo', 'arriendo-facil' ),
			'add_new_item'       => __( 'Agregar nuevo inmueble', 'arriendo-facil' ),
			'edit_item'          => __( 'Editar inmueble', 'arriendo-facil' ),
			'new_item'           => __( 'Nuevo inmueble', 'arriendo-facil' ),
			'view_item'          => __( 'Ver inmueble', 'arriendo-facil' ),
			'search_items'       => __( 'Buscar inmuebles', 'arriendo-facil' ),
			'not_found'          => __( 'No se encontraron inmuebles', 'arriendo-facil' ),
			'not_found_in_trash' => __( 'No se encontraron inmuebles en la papelera', 'arriendo-facil' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => 'arriendo-facil',
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'accommodations' ),
			'capability_type'    => 'post',
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => null,
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'taxonomies'         => array( 'post_tag' ),
			'show_in_rest'       => true,
		);

		register_post_type( 'accommodation', $args );
	}

	/**
	 * Enqueues frontend styles for occupied accommodations.
	 */
	public function enqueue_frontend_styles() {
		wp_register_style(
			'af-occupied-badge-style',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/accommodations-occupied.css',
			array(),
			ARRIENDO_FACIL_VERSION
		);
	}

	/**
	 * Adds meta boxes for accommodation details.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'af_accommodation_details',
			__( 'Detalles del inmueble', 'arriendo-facil' ),
			array( $this, 'render_meta_box' ),
			'accommodation',
			'normal',
			'high'
		);

		add_meta_box(
			'af_accommodation_gallery',
			__( 'Galería de fotos', 'arriendo-facil' ),
			array( $this, 'render_gallery_meta_box' ),
			'accommodation',
			'normal',
			'high'
		);

		add_meta_box(
			'af_accommodation_documents',
			__( 'Documentos del inmueble', 'arriendo-facil' ),
			array( $this, 'render_documents_meta_box' ),
			'accommodation',
			'side',
			'default'
		);
	}

	/**
	 * Legal and administrative document slots tracked per property.
	 *
	 * @return array<string,string>
	 */
	public static function document_types() {
		return array(
			'escritura'      => __( 'Escritura', 'arriendo-facil' ),
			'predial'        => __( 'Impuesto predial', 'arriendo-facil' ),
			'poliza'         => __( 'Póliza de seguro', 'arriendo-facil' ),
			'reglamento'     => __( 'Reglamento interno', 'arriendo-facil' ),
			'certificado'    => __( 'Certificado de gravamen', 'arriendo-facil' ),
			'otro'           => __( 'Otro', 'arriendo-facil' ),
		);
	}

	/**
	 * Renders the property documents meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_documents_meta_box( $post ) {
		wp_nonce_field( 'af_save_accommodation_documents', 'af_documents_nonce' );

		$documents = get_post_meta( $post->ID, '_af_documents', true );
		if ( ! is_array( $documents ) ) {
			$documents = array();
		}

		$document_types = self::document_types();

		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/accommodation-documents.php';
	}

	/**
	 * Saves the property documents meta.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_documents_meta( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['af_documents_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['af_documents_nonce'] ) ), 'af_save_accommodation_documents' ) ) {
			return;
		}

		$raw       = isset( $_POST['af_documents'] ) ? wp_unslash( $_POST['af_documents'] ) : '';
		$decoded   = json_decode( (string) $raw, true );
		$allowed   = array_keys( self::document_types() );
		$sanitized = array();

		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['attachment_id'] ) ) {
					continue;
				}

				$type = isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : 'otro';

				$sanitized[] = array(
					'type'          => in_array( $type, $allowed, true ) ? $type : 'otro',
					'attachment_id' => absint( $entry['attachment_id'] ),
					'label'         => isset( $entry['label'] ) ? sanitize_text_field( $entry['label'] ) : '',
					'expires_at'    => isset( $entry['expires_at'] ) ? sanitize_text_field( $entry['expires_at'] ) : '',
				);
			}
		}

		update_post_meta( $post_id, '_af_documents', $sanitized );
	}

	/**
	 * Renders the accommodation details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'af_save_accommodation_meta', 'af_accommodation_nonce' );

		$address       = get_post_meta( $post->ID, '_af_address', true );
		$location_text = get_post_meta( $post->ID, '_af_location_text', true );
		$latitude      = get_post_meta( $post->ID, '_af_latitude', true );
		$longitude     = get_post_meta( $post->ID, '_af_longitude', true );
		$bedrooms      = get_post_meta( $post->ID, '_af_bedrooms', true );
		$bathrooms     = get_post_meta( $post->ID, '_af_bathrooms', true );
		$monthly_rent  = get_post_meta( $post->ID, '_af_monthly_rent', true );
		$property_type = get_post_meta( $post->ID, '_af_property_type', true );
		$square_meters = get_post_meta( $post->ID, '_af_square_meters', true );
		$amenities     = get_post_meta( $post->ID, '_af_amenities', true );
		if ( ! is_array( $amenities ) ) {
			$amenities = array();
		}
		$city          = get_post_meta( $post->ID, '_af_city', true );
		$owner_id      = get_post_meta( $post->ID, '_af_owner_id', true );
		$status        = get_post_meta( $post->ID, '_af_status', true );
		$owner_options = $this->get_owner_user_options();
		$is_owner_user = self::user_is_owner( get_current_user_id() );

		$year_built    = get_post_meta( $post->ID, '_af_year_built', true );
		$floor_number  = get_post_meta( $post->ID, '_af_floor_number', true );
		$total_floors  = get_post_meta( $post->ID, '_af_total_floors', true );
		$parking_spots = get_post_meta( $post->ID, '_af_parking_spots', true );
		$furnished     = get_post_meta( $post->ID, '_af_furnished', true );
		$condition     = get_post_meta( $post->ID, '_af_condition', true );
		$hoa_fee       = get_post_meta( $post->ID, '_af_hoa_fee', true );
		$utilities_included = get_post_meta( $post->ID, '_af_utilities_included', true );
		if ( ! is_array( $utilities_included ) ) {
			$utilities_included = array();
		}
		$inventory = get_post_meta( $post->ID, '_af_inventory', true );
		if ( ! is_array( $inventory ) ) {
			$inventory = array();
		}

		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/accommodation-meta-box.php';
	}

	/**
	 * Renders the gallery meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_gallery_meta_box( $post ) {
		wp_nonce_field( 'af_save_gallery', 'af_gallery_nonce' );
		$gallery_ids = get_post_meta( $post->ID, '_af_gallery', true );
		if ( ! is_array( $gallery_ids ) ) {
			$gallery_ids = array();
		}
		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/accommodation-gallery.php';
	}

	/**
	 * Saves the gallery meta data.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_gallery_meta( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['af_gallery_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['af_gallery_nonce'] ) ), 'af_save_gallery' ) ) {
			return;
		}

		$raw_ids = isset( $_POST['af_gallery_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['af_gallery_ids'] ) ) : '';
		if ( '' === $raw_ids ) {
			update_post_meta( $post_id, '_af_gallery', array() );
			return;
		}
		$ids = array_map( 'absint', explode( ',', $raw_ids ) );
		$ids = array_filter( $ids );
		update_post_meta( $post_id, '_af_gallery', array_values( $ids ) );
	}

	/**
	 * Customizes the title input placeholder for accommodations.
	 *
	 * @param string  $text Default placeholder.
	 * @param WP_Post $post Current post.
	 * @return string
	 */
	public function customize_title_placeholder( $text, $post ) {
		if ( isset( $post->post_type ) && 'accommodation' === $post->post_type ) {
			return __( 'Ej: La Carolina', 'arriendo-facil' );
		}
		return $text;
	}

	/**
	 * Renders a helpful intro section between the title and the content editor.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_editor_intro( $post ) {
		if ( ! isset( $post->post_type ) || 'accommodation' !== $post->post_type ) {
			return;
		}
		?>
		<div class="af-editor-intro">
			<div class="af-editor-intro__body">
				<div class="af-editor-intro__icon">&#x1F4DD;</div>
				<div>
					<strong><?php esc_html_e( 'Descripción del inmueble', 'arriendo-facil' ); ?></strong>
					<p class="af-editor-intro__hint">
						<?php esc_html_e( 'Escribe una descripción atractiva del inmueble: características especiales, ambiente, entorno, qué lo hace único. Esta descripción se mostrará en la publicación.', 'arriendo-facil' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Customizes the featured image box label for the accommodation CPT.
	 *
	 * @param string $content   Default HTML.
	 * @param int    $post_id   Post ID.
	 * @param int    $thumbnail_id Thumbnail attachment ID.
	 * @return string
	 */
	public function customize_thumbnail_label( $content, $post_id, $thumbnail_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'accommodation' !== $post->post_type ) {
			return $content;
		}
		return '<p class="af-thumbnail-hint">' .
			esc_html__( 'Esta será la foto principal que aparece en los listados y como portada del inmueble.', 'arriendo-facil' ) .
			'</p>' . $content;
	}

	/**
	 * Saves the accommodation meta data.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Nonce must be verified before writing any meta to prevent unauthorized
		// save_post triggers (e.g., from other plugins) from silently mutating owner data.
		if ( ! isset( $_POST['af_accommodation_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['af_accommodation_nonce'] ) ), 'af_save_accommodation_meta' ) ) {
			return;
		}

		// Invalidate search cache
		$this->invalidate_search_cache();

		$current_user_id = get_current_user_id();
		$is_owner_user   = self::user_is_owner( $current_user_id );

		// Keep owner/accommodation link consistent.
		if ( $is_owner_user ) {
			update_post_meta( $post_id, '_af_owner_id', $current_user_id );
		}

		$fields = array(
			'_af_address'              => array( 'AF_Text_Normalizer', 'proper_name' ),
			'_af_location_text'        => array( 'AF_Text_Normalizer', 'proper_name' ),
			'_af_city'                 => array( 'AF_Text_Normalizer', 'proper_name' ),
			'_af_latitude'             => 'floatval',
			'_af_longitude'            => 'floatval',
			'_af_bedrooms'             => 'absint',
			'_af_bathrooms'            => 'absint',
			'_af_monthly_rent'         => 'floatval',
			'_af_status'               => 'sanitize_text_field',
			'_af_property_type'        => 'sanitize_text_field',
			'_af_square_meters'        => 'floatval',
			'_af_booking_property_id'  => 'sanitize_text_field',
			'_af_booking_ical_url'     => 'esc_url_raw',
			'_af_airbnb_listing_id'    => 'sanitize_text_field',
			'_af_airbnb_ical_url'      => 'esc_url_raw',
			'_af_sync_enabled'         => 'absint',
			'_af_year_built'           => 'absint',
			'_af_floor_number'         => 'absint',
			'_af_total_floors'         => 'absint',
			'_af_parking_spots'        => 'absint',
			'_af_furnished'            => 'sanitize_text_field',
			'_af_condition'            => 'sanitize_text_field',
			'_af_hoa_fee'              => 'floatval',
		);

		foreach ( $fields as $key => $sanitize_cb ) {
			$form_key = str_replace( '_af_', 'af_', ltrim( $key, '_' ) );
			if ( isset( $_POST[ $form_key ] ) ) {
				update_post_meta( $post_id, $key, call_user_func( $sanitize_cb, wp_unslash( $_POST[ $form_key ] ) ) );
			}
		}

		if ( isset( $_POST['af_furnished'] ) ) {
			$allowed_furnished = array( 'unfurnished', 'semi', 'furnished' );
			$furnished_value   = sanitize_text_field( wp_unslash( $_POST['af_furnished'] ) );
			update_post_meta( $post_id, '_af_furnished', in_array( $furnished_value, $allowed_furnished, true ) ? $furnished_value : 'unfurnished' );
		}

		if ( isset( $_POST['af_condition'] ) ) {
			$allowed_conditions = array( 'new', 'very_good', 'good', 'needs_repair' );
			$condition_value    = sanitize_text_field( wp_unslash( $_POST['af_condition'] ) );
			update_post_meta( $post_id, '_af_condition', in_array( $condition_value, $allowed_conditions, true ) ? $condition_value : 'good' );
		}

		if ( isset( $_POST['af_utilities_included'] ) && is_array( $_POST['af_utilities_included'] ) ) {
			$allowed_utilities  = array( 'water', 'electric', 'internet', 'gas' );
			$utilities_included = array_values( array_intersect( $allowed_utilities, array_map( 'sanitize_text_field', wp_unslash( $_POST['af_utilities_included'] ) ) ) );
			update_post_meta( $post_id, '_af_utilities_included', $utilities_included );
		} else {
			update_post_meta( $post_id, '_af_utilities_included', array() );
		}

		if ( isset( $_POST['af_inventory'] ) ) {
			update_post_meta( $post_id, '_af_inventory', $this->sanitize_inventory_json( wp_unslash( $_POST['af_inventory'] ) ) );
		}

		if ( isset( $_POST['af_amenities'] ) && is_array( $_POST['af_amenities'] ) ) {
			$amenities = array_map( 'sanitize_text_field', wp_unslash( $_POST['af_amenities'] ) );
			update_post_meta( $post_id, '_af_amenities', $amenities );
		}

		// Baño compartido (checkbox). Si está marcado, forzar baños a 0.
		if ( isset( $_POST['af_form_action'] ) || isset( $_POST['af_bathrooms'] ) ) {
			$shared = ! empty( $_POST['af_shared_bathroom'] ) ? 1 : 0;
			update_post_meta( $post_id, '_af_shared_bathroom', $shared );
			if ( $shared ) {
				update_post_meta( $post_id, '_af_bathrooms', 0 );
			}
		}

		if ( ! $is_owner_user && isset( $_POST['af_owner_id'] ) ) {
			update_post_meta( $post_id, '_af_owner_id', absint( wp_unslash( $_POST['af_owner_id'] ) ) );
		}
	}

	/**
	 * Validates and sanitizes the raw inventory JSON payload submitted from the meta box.
	 *
	 * @param string $raw_json Raw JSON string from the hidden af_inventory field.
	 * @return array<int,array{item:string,category:string,condition:string,quantity:int}>
	 */
	private function sanitize_inventory_json( $raw_json ) {
		$decoded = json_decode( (string) $raw_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$allowed_categories  = array( 'electrodomestico', 'mobiliario', 'otro' );
		$allowed_conditions  = array( 'new', 'very_good', 'good', 'needs_repair' );
		$sanitized_inventory = array();

		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['item'] ) ) {
				continue;
			}

			$category  = isset( $entry['category'] ) ? sanitize_text_field( $entry['category'] ) : 'otro';
			$condition = isset( $entry['condition'] ) ? sanitize_text_field( $entry['condition'] ) : 'good';

			$sanitized_inventory[] = array(
				'item'      => sanitize_text_field( $entry['item'] ),
				'category'  => in_array( $category, $allowed_categories, true ) ? $category : 'otro',
				'condition' => in_array( $condition, $allowed_conditions, true ) ? $condition : 'good',
				'quantity'  => isset( $entry['quantity'] ) ? max( 1, absint( $entry['quantity'] ) ) : 1,
			);
		}

		return $sanitized_inventory;
	}

	/**
	 * Renders accommodations under management using existing accommodation posts.
	 *
	 * @return string
	 */
	public function render_managed_accommodations_shortcode() {
		$featured_args = $this->get_featured_query_args();

		$cache_key   = 'af_managed_accommodations_' . md5( wp_json_encode( $featured_args ) );
		$cached_html = get_transient( $cache_key );

		if ( false !== $cached_html ) {
			return (string) $cached_html;
		}

		$accommodations = get_posts(
			array_merge(
				array(
					'post_type'      => 'accommodation',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'date',
					'order'          => 'DESC',
				),
				$featured_args
			)
		);

		// Batch-prime meta cache to eliminate N+1 in the render loop.
		if ( ! empty( $accommodations ) ) {
			update_meta_cache( 'post', wp_list_pluck( $accommodations, 'ID' ) );
		}

		ob_start();
		?>
		<div class="af-managed-accommodations" aria-live="polite">
			<?php if ( ! empty( $accommodations ) ) : ?>
				<?php foreach ( $accommodations as $accommodation ) : ?>
					<?php
					$address      = (string) get_post_meta( $accommodation->ID, '_af_address', true );
					$bedrooms     = (int) get_post_meta( $accommodation->ID, '_af_bedrooms', true );
					$bathrooms    = (int) get_post_meta( $accommodation->ID, '_af_bathrooms', true );
					$monthly_rent = (string) get_post_meta( $accommodation->ID, '_af_monthly_rent', true );
					$details_url  = get_permalink( $accommodation->ID );
					?>
					<article class="af-managed-accommodation-card">
						<?php if ( has_post_thumbnail( $accommodation->ID ) ) : ?>
							<div class="af-managed-accommodation-image">
								<a href="<?php echo esc_url( $details_url ); ?>">
									<?php echo get_the_post_thumbnail( $accommodation->ID, 'large' ); ?>
								</a>
							</div>
						<?php endif; ?>

						<div class="af-managed-accommodation-content">
							<h3 class="af-managed-accommodation-title">
								<a href="<?php echo esc_url( $details_url ); ?>"><?php echo esc_html( get_the_title( $accommodation->ID ) ); ?></a>
							</h3>

							<?php if ( '' !== $address ) : ?>
								<p class="af-managed-accommodation-address"><?php echo esc_html( $address ); ?></p>
							<?php endif; ?>

							<ul class="af-managed-accommodation-meta">
								<li><?php echo esc_html( sprintf( __( 'Dormitorios: %d', 'arriendo-facil' ), $bedrooms ) ); ?></li>
								<li><?php echo esc_html( sprintf( __( 'Banos: %d', 'arriendo-facil' ), $bathrooms ) ); ?></li>
								<?php if ( '' !== trim( $monthly_rent ) ) : ?>
									<li><?php echo esc_html( sprintf( __( 'Renta mensual: %s', 'arriendo-facil' ), $monthly_rent ) ); ?></li>
								<?php endif; ?>
							</ul>

							<a class="button" href="<?php echo esc_url( $details_url ); ?>"><?php esc_html_e( 'Ver detalles', 'arriendo-facil' ); ?></a>
						</div>
					</article>
				<?php endforeach; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No hay alojamientos disponibles por el momento.', 'arriendo-facil' ); ?></p>
			<?php endif; ?>
		</div>
		<?php

		$html = (string) ob_get_clean();
		set_transient( $cache_key, $html, 12 * HOUR_IN_SECONDS );

		return $html;
	}

	/**
	 * Renders one featured accommodation (latest published).
	 *
	 * @return string
	 */
	public function render_featured_accommodation_shortcode() {
		$featured_args = $this->get_featured_query_args();

		$cache_key   = 'af_featured_accommodations_' . md5( wp_json_encode( $featured_args ) );
		$cached_html = get_transient( $cache_key );

		if ( false !== $cached_html ) {
			return (string) $cached_html;
		}

		$accommodations = get_posts(
			array_merge(
				array(
					'post_type'      => 'accommodation',
					'post_status'    => 'publish',
					'posts_per_page' => 12,
					'orderby'        => 'date',
					'order'          => 'DESC',
				),
				$featured_args
			)
		);

		if ( empty( $accommodations ) ) {
			return '<p>' . esc_html__( 'No hay accommodation disponible para destacar.', 'arriendo-facil' ) . '</p>';
		}

		// Batch-prime meta cache to eliminate N+1 in the render loop.
		update_meta_cache( 'post', wp_list_pluck( $accommodations, 'ID' ) );

		ob_start();
		wp_enqueue_style( 'af-occupied-badge-style' );
		?>
		<div class="af-featured-accommodations" aria-live="polite">
			<?php foreach ( $accommodations as $accommodation ) : ?>
				<?php
				$address       = (string) get_post_meta( $accommodation->ID, '_af_address', true );
				$bedrooms      = (int) get_post_meta( $accommodation->ID, '_af_bedrooms', true );
				$bathrooms     = (int) get_post_meta( $accommodation->ID, '_af_bathrooms', true );
				$monthly_rent  = (string) get_post_meta( $accommodation->ID, '_af_monthly_rent', true );
				$details_url   = get_permalink( $accommodation->ID );
				$is_occupied   = Arriendo_Facil_Accommodation_Occupied_Admin::is_occupied( $accommodation->ID );
				$article_class = $is_occupied ? 'af-featured-accommodation af-featured-accommodation--occupied' : 'af-featured-accommodation';
				?>
				<article class="<?php echo esc_attr( $article_class ); ?>">
					<?php if ( has_post_thumbnail( $accommodation->ID ) ) : ?>
						<?php if ( $is_occupied ) : ?>
							<div class="af-featured-accommodation-image">
								<?php echo get_the_post_thumbnail( $accommodation->ID, 'large' ); ?>
								<div class="af-occupied-overlay">
									<span class="af-occupied-badge"><?php esc_html_e( 'Ocupada', 'arriendo-facil' ); ?></span>
								</div>
							</div>
						<?php else : ?>
							<div class="af-featured-accommodation-image">
								<a href="<?php echo esc_url( $details_url ); ?>">
									<?php echo get_the_post_thumbnail( $accommodation->ID, 'large' ); ?>
								</a>
							</div>
						<?php endif; ?>
					<?php endif; ?>
					<div class="af-featured-accommodation-content">
						<h3>
							<?php if ( $is_occupied ) : ?>
								<span><?php echo esc_html( get_the_title( $accommodation->ID ) ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( $details_url ); ?>"><?php echo esc_html( get_the_title( $accommodation->ID ) ); ?></a>
							<?php endif; ?>
						</h3>
						<?php if ( '' !== $address ) : ?>
							<p><?php echo esc_html( $address ); ?></p>
						<?php endif; ?>
						<ul>
							<li><?php echo esc_html( sprintf( __( 'Dormitorios: %d', 'arriendo-facil' ), $bedrooms ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'Banos: %d', 'arriendo-facil' ), $bathrooms ) ); ?></li>
							<?php if ( '' !== trim( $monthly_rent ) ) : ?>
								<li><?php echo esc_html( sprintf( __( 'Renta mensual: %s', 'arriendo-facil' ), $monthly_rent ) ); ?></li>
							<?php endif; ?>
						</ul>
						<?php if ( ! $is_occupied ) : ?>
							<a class="button" href="<?php echo esc_url( $details_url ); ?>"><?php esc_html_e( 'Ver detalles', 'arriendo-facil' ); ?></a>
						<?php else : ?>
							<button class="button button-disabled" disabled><?php esc_html_e( 'No disponible', 'arriendo-facil' ); ?></button>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php

		$html = (string) ob_get_clean();
		set_transient( $cache_key, $html, 12 * HOUR_IN_SECONDS );

		return $html;
	}

	/**
	 * Appends accommodation metadata in singular detail pages.
	 *
	 * @param string $content Current post content.
	 * @return string
	 */
	public function append_single_accommodation_details( $content ) {
		if ( is_admin() || ! is_singular( 'accommodation' ) || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		if ( false !== strpos( (string) $content, 'af-accommodation-details' ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$address      = (string) get_post_meta( $post_id, '_af_address', true );
		$bedrooms     = (int) get_post_meta( $post_id, '_af_bedrooms', true );
		$bathrooms    = (int) get_post_meta( $post_id, '_af_bathrooms', true );
		$monthly_rent = (string) get_post_meta( $post_id, '_af_monthly_rent', true );
		$status       = (string) get_post_meta( $post_id, '_af_status', true );

		$details = array();
		if ( '' !== trim( $address ) ) {
			$details[] = sprintf( '<li><strong>%s</strong> %s</li>', esc_html__( 'Direccion:', 'arriendo-facil' ), esc_html( $address ) );
		}
		if ( $bedrooms > 0 ) {
			$details[] = sprintf( '<li><strong>%s</strong> %d</li>', esc_html__( 'Dormitorios:', 'arriendo-facil' ), $bedrooms );
		}
		if ( $bathrooms > 0 ) {
			$details[] = sprintf( '<li><strong>%s</strong> %d</li>', esc_html__( 'Banos:', 'arriendo-facil' ), $bathrooms );
		}
		if ( '' !== trim( $monthly_rent ) ) {
			$details[] = sprintf( '<li><strong>%s</strong> %s</li>', esc_html__( 'Renta mensual:', 'arriendo-facil' ), esc_html( $monthly_rent ) );
		}
		if ( '' !== trim( $status ) ) {
			$details[] = sprintf( '<li><strong>%s</strong> %s</li>', esc_html__( 'Estado:', 'arriendo-facil' ), esc_html( $status ) );
		}

		if ( empty( $details ) ) {
			return $content;
		}

		$details_html = '<section class="af-accommodation-details" aria-label="' . esc_attr__( 'Detalles del alojamiento', 'arriendo-facil' ) . '">';
		$details_html .= '<h3>' . esc_html__( 'Informacion del alojamiento', 'arriendo-facil' ) . '</h3>';
		$details_html .= '<ul>' . implode( '', $details ) . '</ul>';
		$details_html .= '</section>';

		return (string) $content . $details_html;
	}

	/**
	 * Replaces/augments managed-properties section in page content when detected.
	 *
	 * @param string $content Original content.
	 * @return string
	 */
	public function inject_managed_accommodations_in_content( $content ) {
		if ( is_admin() ) {
			return $content;
		}

		$raw_content = (string) $content;
		if ( false !== strpos( $raw_content, 'af-managed-accommodations' ) || false !== strpos( $raw_content, 'af-featured-accommodation' ) ) {
			return $raw_content;
		}

		$has_managed_heading = false !== stripos( $raw_content, 'Propiedades bajo nuestra' );
		$has_featured_heading = false !== stripos( $raw_content, 'Propiedad destacada' );

		if ( ! $has_managed_heading && ! $has_featured_heading ) {
			return $raw_content;
		}

		$clean_content = preg_replace( '/<a[^>]*>\s*Quiero\s+rentabilizar[^<]*<\/a>/iu', '', $raw_content );
		$clean_content = preg_replace( '/<button[^>]*>\s*Quiero\s+rentabilizar[^<]*<\/button>/iu', '', (string) $clean_content );
		$clean_content = preg_replace( '/<p[^>]*>\s*\x{00BF}?Eres\s+propietario\?[^<]*<\/p>/iu', '', (string) $clean_content );

		if ( $has_featured_heading ) {
			$featured_html = $this->render_featured_accommodation_shortcode();
			$clean_content = $this->replace_section_after_heading( $clean_content, 'Propiedad\s+destacada', $featured_html );
		}

		if ( $has_managed_heading ) {
			$list_html = $this->render_managed_accommodations_shortcode();
			$clean_content = $this->replace_section_after_heading( $clean_content, 'Propiedades\s+bajo\s+nuestra\s+gesti[oó]n', $list_html );
		}

		return $clean_content;
	}

	/**
	 * Replaces content section after a heading with controlled markup.
	 *
	 * @param string $content Full HTML content.
	 * @param string $heading_pattern Heading text regex (without delimiters).
	 * @param string $replacement Replacement section HTML.
	 * @return string
	 */
	private function replace_section_after_heading( $content, $heading_pattern, $replacement ) {
		$pattern = '/(<h[1-6][^>]*>[^<]*' . $heading_pattern . '[^<]*<\/h[1-6]>)([\s\S]*?)(?=<h[1-6][^>]*>|$)/iu';

		$updated = preg_replace( $pattern, '$1' . $replacement, (string) $content, 1 );

		if ( is_string( $updated ) && '' !== $updated ) {
			return $updated;
		}

		return (string) $content . $replacement;
	}

	/**
	 * Restricts admin accommodation listings to the current owner.
	 *
	 * @param WP_Query $query Query object.
	 * @return void
	 */
	private function restrict_admin_accommodation_queries_to_owner( $query ) {
		if ( ! is_admin() || wp_doing_ajax() || ! $query->is_main_query() ) {
			return;
		}

		$current_user_id = get_current_user_id();
		if ( ! self::user_is_owner( $current_user_id ) ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( 'accommodation' !== $post_type && ! ( is_array( $post_type ) && in_array( 'accommodation', $post_type, true ) ) ) {
			return;
		}

		$query->set(
			'meta_query',
			array(
				array(
					'key'   => '_af_owner_id',
					'value' => $current_user_id,
				),
			)
		);
	}

	/**
	 * Returns featured-taxonomy query (tagged accommodations).
	 *
	 * Considers two sources, OR-merged:
	 *   - `post_tag` slugs `destacada` / `featured` (legacy).
	 *   - Post meta `_af_is_featured = 1` (new admin toggle).
	 *
	 * When neither source has at least one published accommodation, returns
	 * an empty array so callers fall back to "show all" — preserving the
	 * original behavior before the admin toggle existed.
	 *
	 * @return array tax_query-compatible structure (may include meta clause for OR).
	 */
	private function get_featured_tax_query() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'slug'       => array( 'destacada', 'featured' ),
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$term_ids = ( is_wp_error( $terms ) || empty( $terms ) ) ? array() : array_map( 'absint', $terms );

		$has_tag_featured = false;
		if ( ! empty( $term_ids ) ) {
			$has_tag_featured = ! empty(
				get_posts(
					array(
						'post_type'      => 'accommodation',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'tax_query'      => array(
							array(
								'taxonomy' => 'post_tag',
								'field'    => 'term_id',
								'terms'    => $term_ids,
							),
						),
					)
				)
			);
		}

		$has_meta_featured = ! empty(
			get_posts(
				array(
					'post_type'      => 'accommodation',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => '_af_is_featured',
							'value'   => '1',
							'compare' => '=',
						),
					),
				)
			)
		);

		if ( ! $has_tag_featured && ! $has_meta_featured ) {
			return array();
		}

		if ( $has_tag_featured && ! $has_meta_featured ) {
			return array(
				array(
					'taxonomy' => 'post_tag',
					'field'    => 'term_id',
					'terms'    => $term_ids,
				),
			);
		}

		if ( $has_meta_featured && ! $has_tag_featured ) {
			return array(
				'_af_meta_only' => array(
					array(
						'key'     => '_af_is_featured',
						'value'   => '1',
						'compare' => '=',
					),
				),
			);
		}

		// Both sources have hits — return a marker so callers can apply the
		// OR-merged query via get_featured_query_args().
		return array(
			'_af_mixed' => array(
				'tax_term_ids' => $term_ids,
			),
		);
	}

	/**
	 * Returns WP_Query args that filter to featured accommodations using
	 * tag and/or meta, falling back to "no filter" when nothing is featured.
	 *
	 * @return array
	 */
	private function get_featured_query_args() {
		$marker = $this->get_featured_tax_query();
		if ( empty( $marker ) ) {
			return array();
		}

		if ( isset( $marker['_af_mixed'] ) ) {
			$term_ids = $marker['_af_mixed']['tax_term_ids'];
			// Match posts that have the tag OR the meta.
			$tag_ids = get_posts(
				array(
					'post_type'      => 'accommodation',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'post_tag',
							'field'    => 'term_id',
							'terms'    => $term_ids,
						),
					),
				)
			);
			$meta_ids = get_posts(
				array(
					'post_type'      => 'accommodation',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => '_af_is_featured',
							'value'   => '1',
							'compare' => '=',
						),
					),
				)
			);
			$ids = array_values( array_unique( array_map( 'absint', array_merge( (array) $tag_ids, (array) $meta_ids ) ) ) );
			if ( empty( $ids ) ) {
				return array();
			}
			return array( 'post__in' => $ids );
		}

		if ( isset( $marker['_af_meta_only'] ) ) {
			return array( 'meta_query' => $marker['_af_meta_only'] );
		}

		return array( 'tax_query' => $marker );
	}

	/**
	 * Returns whether a user has the af_property_admin (subadmin) role.
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return bool
	 */
	public static function user_is_owner( $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		$user = get_user_by( 'id', absint( $user_id ) );
		if ( ! $user ) {
			return false;
		}
		$roles = isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();
		return in_array( 'af_property_admin', $roles, true );
	}

	/**
	 * Returns accommodation post IDs owned by a given user.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function get_owner_accommodation_ids( $user_id ) {
		return get_posts( array(
			'post_type'      => 'accommodation',
			'post_status'    => 'any',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'meta_query'     => array(
				array( 'key' => '_af_owner_id', 'value' => absint( $user_id ) ),
			),
		) );
	}

	/**
	 * Estimates a monthly rent automatically from comparable properties already
	 * in the system (same city and property type, similar bedroom count).
	 * Purely statistical — no external API, no manual trigger required.
	 *
	 * @param string $city          City to compare against.
	 * @param string $property_type Property type to compare against.
	 * @param int    $bedrooms      Bedroom count to compare against.
	 * @param int    $exclude_id    Post ID to exclude (the property being edited).
	 * @return array{average:float,min:float,max:float,sample_size:int}
	 */
	public static function estimate_monthly_rent( $city, $property_type, $bedrooms, $exclude_id = 0 ) {
		global $wpdb;

		$city          = sanitize_text_field( (string) $city );
		$property_type = sanitize_text_field( (string) $property_type );
		$bedrooms      = absint( $bedrooms );
		$exclude_id    = absint( $exclude_id );

		$empty = array(
			'average'     => 0.0,
			'min'         => 0.0,
			'max'         => 0.0,
			'sample_size' => 0,
		);

		if ( '' === $city || '' === $property_type ) {
			return $empty;
		}

		$query = "SELECT pm_rent.meta_value AS rent
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_city ON pm_city.post_id = p.ID AND pm_city.meta_key = '_af_city'
			INNER JOIN {$wpdb->postmeta} pm_type ON pm_type.post_id = p.ID AND pm_type.meta_key = '_af_property_type'
			INNER JOIN {$wpdb->postmeta} pm_rent ON pm_rent.post_id = p.ID AND pm_rent.meta_key = '_af_monthly_rent'
			LEFT JOIN {$wpdb->postmeta} pm_bed  ON pm_bed.post_id = p.ID AND pm_bed.meta_key = '_af_bedrooms'
			WHERE p.post_type = 'accommodation'
			  AND p.post_status IN ('publish','private','draft')
			  AND pm_city.meta_value = %s
			  AND pm_type.meta_value = %s
			  AND pm_rent.meta_value > 0
			  AND ( pm_bed.meta_value IS NULL OR ABS(CAST(pm_bed.meta_value AS SIGNED) - %d) <= 1 )
			  AND p.ID != %d
			LIMIT 200";

		$rents = $wpdb->get_col( $wpdb->prepare( $query, $city, $property_type, $bedrooms, $exclude_id ) );
		$rents = array_map( 'floatval', (array) $rents );
		$rents = array_values( array_filter( $rents, static function ( $rent ) {
			return $rent > 0;
		} ) );

		if ( empty( $rents ) ) {
			return $empty;
		}

		return array(
			'average'     => round( array_sum( $rents ) / count( $rents ), 2 ),
			'min'         => round( min( $rents ), 2 ),
			'max'         => round( max( $rents ), 2 ),
			'sample_size' => count( $rents ),
		);
	}

	/**
	 * Invalidates search result and shortcode cache.
	 */
	private function invalidate_search_cache() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				'_transient_af_search_results_%',
				'_transient_af_managed_accommodations_%',
				'_transient_af_featured_accommodations_%'
			)
		);
	}

	/**
	 * Returns owner options for assignment dropdown.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_owner_user_options() {
		global $wpdb;

		$owner_user_ids = $wpdb->get_col(
			"SELECT DISTINCT wp_user_id FROM {$wpdb->prefix}af_owner_contacts WHERE wp_user_id IS NOT NULL AND wp_user_id > 0"
		);

		$role_owner_users = get_users(
			array(
				'role'   => 'af_property_admin',
				'fields' => 'ID',
			)
		);

		if ( is_array( $role_owner_users ) ) {
			$owner_user_ids = array_merge( is_array( $owner_user_ids ) ? $owner_user_ids : array(), $role_owner_users );
		}

		$owner_user_ids = array_values( array_unique( array_map( 'absint', is_array( $owner_user_ids ) ? $owner_user_ids : array() ) ) );

		if ( empty( $owner_user_ids ) ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => $owner_user_ids,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		$options = array();
		foreach ( $users as $user ) {
			$options[] = array(
				'id'    => (int) $user->ID,
				'label' => (string) $user->display_name . ' (' . (string) $user->user_email . ')',
			);
		}

		return $options;
	}

	/**
	 * Renders acomodaciones ocupadas (occupied accommodations).
	 * Shows them with overlay and disabled state.
	 *
	 * @return string
	 */
	public function render_occupied_accommodations_shortcode() {
		$occupied_args = $this->get_occupied_query_args();

		$cache_key   = 'af_occupied_accommodations_' . md5( wp_json_encode( $occupied_args ) );
		$cached_html = get_transient( $cache_key );

		if ( false !== $cached_html ) {
			return (string) $cached_html;
		}

		$accommodations = get_posts(
			array_merge(
				array(
					'post_type'      => 'accommodation',
					'post_status'    => 'publish',
					'posts_per_page' => 12,
					'orderby'        => 'date',
					'order'          => 'DESC',
				),
				$occupied_args
			)
		);

		if ( empty( $accommodations ) ) {
			return '<p>' . esc_html__( 'No hay acomodaciones ocupadas para mostrar.', 'arriendo-facil' ) . '</p>';
		}

		update_meta_cache( 'post', wp_list_pluck( $accommodations, 'ID' ) );

		ob_start();
		wp_enqueue_style( 'af-occupied-badge-style' );
		?>
		<div class="af-occupied-accommodations" aria-live="polite">
			<?php foreach ( $accommodations as $accommodation ) : ?>
				<?php
				$address      = (string) get_post_meta( $accommodation->ID, '_af_address', true );
				$bedrooms     = (int) get_post_meta( $accommodation->ID, '_af_bedrooms', true );
				$bathrooms    = (int) get_post_meta( $accommodation->ID, '_af_bathrooms', true );
				$monthly_rent = (string) get_post_meta( $accommodation->ID, '_af_monthly_rent', true );
				?>
				<article class="af-occupied-accommodation">
					<?php if ( has_post_thumbnail( $accommodation->ID ) ) : ?>
						<div class="af-occupied-accommodation-image">
							<?php echo get_the_post_thumbnail( $accommodation->ID, 'large' ); ?>
							<div class="af-occupied-overlay">
								<span class="af-occupied-badge"><?php esc_html_e( 'Ocupada', 'arriendo-facil' ); ?></span>
							</div>
						</div>
					<?php endif; ?>
					<div class="af-occupied-accommodation-content">
						<h3><?php echo esc_html( get_the_title( $accommodation->ID ) ); ?></h3>
						<?php if ( '' !== $address ) : ?>
							<p><?php echo esc_html( $address ); ?></p>
						<?php endif; ?>
						<ul>
							<li><?php echo esc_html( sprintf( __( 'Dormitorios: %d', 'arriendo-facil' ), $bedrooms ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'Banos: %d', 'arriendo-facil' ), $bathrooms ) ); ?></li>
							<?php if ( '' !== trim( $monthly_rent ) ) : ?>
								<li><?php echo esc_html( sprintf( __( 'Renta mensual: %s', 'arriendo-facil' ), $monthly_rent ) ); ?></li>
							<?php endif; ?>
						</ul>
						<button class="button button-disabled" disabled><?php esc_html_e( 'No disponible - Ocupada', 'arriendo-facil' ); ?></button>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php

		$html = (string) ob_get_clean();
		set_transient( $cache_key, $html, 12 * HOUR_IN_SECONDS );

		return $html;
	}

	/**
	 * Returns query args for occupied accommodations (meta-based filtering).
	 *
	 * @return array
	 */
	private function get_occupied_query_args() {
		return array(
			'meta_query' => array(
				array(
					'key'     => '_af_is_occupied',
					'value'   => '1',
					'compare' => '=',
				),
			),
		);
	}

}
