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
	}

	/**
	 * Registers the accommodation CPT.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Accommodations', 'arriendo-facil' ),
			'singular_name'      => __( 'Accommodation', 'arriendo-facil' ),
			'menu_name'          => __( 'Accommodations', 'arriendo-facil' ),
			'add_new'            => __( 'Add New', 'arriendo-facil' ),
			'add_new_item'       => __( 'Add New Accommodation', 'arriendo-facil' ),
			'edit_item'          => __( 'Edit Accommodation', 'arriendo-facil' ),
			'new_item'           => __( 'New Accommodation', 'arriendo-facil' ),
			'view_item'          => __( 'View Accommodation', 'arriendo-facil' ),
			'search_items'       => __( 'Search Accommodations', 'arriendo-facil' ),
			'not_found'          => __( 'No accommodations found', 'arriendo-facil' ),
			'not_found_in_trash' => __( 'No accommodations found in Trash', 'arriendo-facil' ),
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
			'show_in_rest'       => true,
		);

		register_post_type( 'accommodation', $args );
	}

	/**
	 * Adds meta boxes for accommodation details.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'af_accommodation_details',
			__( 'Accommodation Details', 'arriendo-facil' ),
			array( $this, 'render_meta_box' ),
			'accommodation',
			'normal',
			'high'
		);
	}

	/**
	 * Renders the accommodation details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'af_save_accommodation_meta', 'af_accommodation_nonce' );

		$address     = get_post_meta( $post->ID, '_af_address', true );
		$bedrooms    = get_post_meta( $post->ID, '_af_bedrooms', true );
		$bathrooms   = get_post_meta( $post->ID, '_af_bathrooms', true );
		$monthly_rent = get_post_meta( $post->ID, '_af_monthly_rent', true );
		$owner_id    = get_post_meta( $post->ID, '_af_owner_id', true );
		$status      = get_post_meta( $post->ID, '_af_status', true );

		include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/accommodation-meta-box.php';
	}

	/**
	 * Saves the accommodation meta data.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['af_accommodation_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['af_accommodation_nonce'] ) ), 'af_save_accommodation_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'_af_address'      => 'sanitize_text_field',
			'_af_bedrooms'     => 'absint',
			'_af_bathrooms'    => 'absint',
			'_af_monthly_rent' => 'floatval',
			'_af_owner_id'     => 'absint',
			'_af_status'       => 'sanitize_text_field',
		);

		foreach ( $fields as $key => $sanitize_cb ) {
			$form_key = str_replace( '_af_', 'af_', ltrim( $key, '_' ) );
			if ( isset( $_POST[ $form_key ] ) ) {
				update_post_meta( $post_id, $key, call_user_func( $sanitize_cb, wp_unslash( $_POST[ $form_key ] ) ) );
			}
		}
	}
}
