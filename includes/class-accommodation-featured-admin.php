<?php
/**
 * Admin-only quick toggle to mark an accommodation as featured (destacada)
 * from the post list table. Uses post meta `_af_is_featured` as the source of
 * truth; the read path in class-accommodation.php OR-merges this with the
 * legacy `destacada`/`featured` tag so frontends keep working in harmony.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Accommodation_Featured_Admin {

	const META_KEY     = '_af_is_featured';
	const NONCE_ACTION = 'af_toggle_featured';
	const AJAX_ACTION  = 'af_toggle_featured';

	public function __construct() {
		add_filter( 'manage_accommodation_posts_columns', array( $this, 'add_column' ) );
		add_action( 'manage_accommodation_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_toggle' ) );
	}

	/**
	 * Adds a "Destacada" column visible only to administrators.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $columns;
		}

		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['af_featured'] = __( 'Destacada', 'arriendo-facil' );
			}
		}
		if ( ! isset( $new['af_featured'] ) ) {
			$new['af_featured'] = __( 'Destacada', 'arriendo-facil' );
		}
		return $new;
	}

	/**
	 * Renders the checkbox for a given row.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'af_featured' !== $column || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$is_featured = $this->is_featured( $post_id );
		printf(
			'<label class="af-featured-toggle-wrap %5$s" title="%4$s"><input type="checkbox" class="af-featured-toggle" data-id="%1$d" %2$s /><span class="af-featured-toggle-state">%3$s</span></label>',
			(int) $post_id,
			checked( $is_featured, true, false ),
			$is_featured ? esc_html__( 'Sí', 'arriendo-facil' ) : esc_html__( 'No', 'arriendo-facil' ),
			esc_attr__( 'Marcar/Quitar como destacada en la portada', 'arriendo-facil' ),
			$is_featured ? 'is-on' : ''
		);
	}

	/**
	 * Returns true when the accommodation is featured (meta or legacy tag).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_featured( $post_id ) {
		if ( '1' === (string) get_post_meta( $post_id, self::META_KEY, true ) ) {
			return true;
		}
		return has_tag( array( 'destacada', 'featured' ), $post_id );
	}

	/**
	 * Enqueues inline JS/CSS on the accommodation list screen for admins.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-accommodation' !== $screen->id ) {
			return;
		}

		$css = '.column-af_featured{width:90px;text-align:center;}'
			. '.af-featured-toggle-wrap{display:inline-flex;align-items:center;gap:6px;cursor:pointer;}'
			. '.af-featured-toggle-wrap input{margin:0;}'
			. '.af-featured-toggle-state{font-size:12px;color:#6b7280;}'
			. '.af-featured-toggle-wrap.is-loading{opacity:.5;pointer-events:none;}'
			. '.af-featured-toggle-wrap.is-on .af-featured-toggle-state{color:#065f46;font-weight:600;}';
		wp_register_style( 'af-featured-toggle', false, array(), ARRIENDO_FACIL_VERSION );
		wp_enqueue_style( 'af-featured-toggle' );
		wp_add_inline_style( 'af-featured-toggle', $css );

		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::AJAX_ACTION,
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'i18n'    => array(
				'yes'   => __( 'Sí', 'arriendo-facil' ),
				'no'    => __( 'No', 'arriendo-facil' ),
				'error' => __( 'No se pudo actualizar. Intenta nuevamente.', 'arriendo-facil' ),
			),
		);

		$js = "(function(){
			var cfg = " . wp_json_encode( $config ) . ";
			document.addEventListener('change', function(e){
				var input = e.target;
				if (!input.classList || !input.classList.contains('af-featured-toggle')) return;
				var wrap = input.closest('.af-featured-toggle-wrap');
				var state = wrap ? wrap.querySelector('.af-featured-toggle-state') : null;
				var postId = parseInt(input.getAttribute('data-id'), 10);
				if (!postId) return;
				var desired = input.checked ? 1 : 0;
				if (wrap) wrap.classList.add('is-loading');
				var body = new FormData();
				body.append('action', cfg.action);
				body.append('nonce', cfg.nonce);
				body.append('post_id', postId);
				body.append('featured', desired);
				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function(r){ return r.json(); })
					.then(function(res){
						if (!res || !res.success) { throw new Error((res && res.data && res.data.message) || cfg.i18n.error); }
						var on = !!res.data.featured;
						input.checked = on;
						if (state) state.textContent = on ? cfg.i18n.yes : cfg.i18n.no;
						if (wrap) wrap.classList.toggle('is-on', on);
					})
					.catch(function(err){
						input.checked = !desired;
						window.alert(err.message || cfg.i18n.error);
					})
					.finally(function(){
						if (wrap) wrap.classList.remove('is-loading');
					});
			});
		})();";

		wp_register_script( 'af-featured-toggle', '', array(), ARRIENDO_FACIL_VERSION, true );
		wp_enqueue_script( 'af-featured-toggle' );
		wp_add_inline_script( 'af-featured-toggle', $js );
	}

	/**
	 * AJAX handler: toggles `_af_is_featured` meta and syncs legacy tag.
	 */
	public function handle_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'arriendo-facil' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id  = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$featured = ! empty( $_POST['featured'] );

		if ( ! $post_id || 'accommodation' !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Inmueble inválido.', 'arriendo-facil' ) ), 400 );
		}

		if ( $featured ) {
			update_post_meta( $post_id, self::META_KEY, '1' );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}

		$this->purge_featured_caches();

		wp_send_json_success(
			array(
				'post_id'  => $post_id,
				'featured' => (bool) $featured,
			)
		);
	}

	/**
	 * Clears transients that depend on featured filtering.
	 */
	private function purge_featured_caches() {
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
}
