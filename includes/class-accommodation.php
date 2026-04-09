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
		add_shortcode( 'af_propiedades_gestion', array( $this, 'render_managed_accommodations_shortcode' ) );
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

	/**
	 * Renders accommodations under management using existing accommodation posts.
	 *
	 * @return string
	 */
	public function render_managed_accommodations_shortcode() {
		$accommodations = get_posts(
			array(
				'post_type'      => 'accommodation',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

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
				<p><?php esc_html_e( 'No hay accommodations disponibles por el momento.', 'arriendo-facil' ); ?></p>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
