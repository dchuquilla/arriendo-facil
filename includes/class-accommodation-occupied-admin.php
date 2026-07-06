<?php
/**
 * Admin toggle to mark an accommodation as occupied (ocupada)
 * from the post list table. Uses post meta `_af_is_occupied` to track status.
 * Both owners and admins can toggle.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Accommodation_Occupied_Admin {

	const META_KEY     = '_af_is_occupied';
	const NONCE_ACTION = 'af_toggle_occupied';
	const AJAX_ACTION  = 'af_toggle_occupied';

	public function __construct() {
		add_filter( 'manage_accommodation_posts_columns', array( $this, 'add_column' ) );
		add_action( 'manage_accommodation_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_toggle' ) );
	}

	/**
	 * Adds an "Ocupada" column visible to administrators and owners.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		if ( ! $this->can_manage_occupied() ) {
			return $columns;
		}

		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['af_occupied'] = __( 'Ocupada', 'arriendo-facil' );
			}
		}
		if ( ! isset( $new['af_occupied'] ) ) {
			$new['af_occupied'] = __( 'Ocupada', 'arriendo-facil' );
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
		if ( 'af_occupied' !== $column || ! $this->can_manage_occupied() ) {
			return;
		}

		// Owners can only manage their own accommodations
		if ( ! current_user_can( 'manage_options' ) && ! $this->is_accommodation_owner( $post_id ) ) {
			return;
		}

		$is_occupied = $this->is_occupied( $post_id );
		printf(
			'<label class="af-occupied-toggle-wrap %5$s" title="%4$s"><input type="checkbox" class="af-occupied-toggle" data-id="%1$d" %2$s /><span class="af-occupied-toggle-state">%3$s</span></label>',
			(int) $post_id,
			checked( $is_occupied, true, false ),
			$is_occupied ? esc_html__( 'Sí', 'arriendo-facil' ) : esc_html__( 'No', 'arriendo-facil' ),
			esc_attr__( 'Marcar/Quitar como ocupada', 'arriendo-facil' ),
			$is_occupied ? 'is-on' : ''
		);
	}

	/**
	 * Returns true when the accommodation is occupied.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_occupied( $post_id ) {
		return '1' === (string) get_post_meta( $post_id, self::META_KEY, true );
	}

	/**
	 * Enqueues inline JS/CSS on the accommodation list screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		if ( ! $this->can_manage_occupied() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-accommodation' !== $screen->id ) {
			return;
		}

		$css = '.column-af_occupied{width:80px;text-align:center;}'
			. '.af-occupied-toggle-wrap{display:inline-flex;align-items:center;gap:6px;cursor:pointer;}'
			. '.af-occupied-toggle-wrap input{margin:0;}'
			. '.af-occupied-toggle-state{font-size:12px;color:#6b7280;}'
			. '.af-occupied-toggle-wrap.is-loading{opacity:.5;pointer-events:none;}'
			. '.af-occupied-toggle-wrap.is-on .af-occupied-toggle-state{color:#b91c1c;font-weight:600;}';
		wp_register_style( 'af-occupied-toggle', false, array(), ARRIENDO_FACIL_VERSION );
		wp_enqueue_style( 'af-occupied-toggle' );
		wp_add_inline_style( 'af-occupied-toggle', $css );

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
				if (!input.classList || !input.classList.contains('af-occupied-toggle')) return;
				var wrap = input.closest('.af-occupied-toggle-wrap');
				var state = wrap ? wrap.querySelector('.af-occupied-toggle-state') : null;
				var postId = parseInt(input.getAttribute('data-id'), 10);
				if (!postId) return;
				var desired = input.checked ? 1 : 0;
				if (wrap) wrap.classList.add('is-loading');
				var body = new FormData();
				body.append('action', cfg.action);
				body.append('nonce', cfg.nonce);
				body.append('post_id', postId);
				body.append('occupied', desired);
				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function(r){ return r.json(); })
					.then(function(res){
						if (!res || !res.success) { throw new Error((res && res.data && res.data.message) || cfg.i18n.error); }
						var on = !!res.data.occupied;
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

		wp_register_script( 'af-occupied-toggle', '', array(), ARRIENDO_FACIL_VERSION, true );
		wp_enqueue_script( 'af-occupied-toggle' );
		wp_add_inline_script( 'af-occupied-toggle', $js );
	}

	/**
	 * AJAX handler: toggles `_af_is_occupied` meta.
	 */
	public function handle_toggle() {
		if ( ! $this->can_manage_occupied() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'arriendo-facil' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$occupied = ! empty( $_POST['occupied'] );

		if ( ! $post_id || 'accommodation' !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Inmueble inválido.', 'arriendo-facil' ) ), 400 );
		}

		// Owners can only manage their own accommodations
		if ( ! current_user_can( 'manage_options' ) && ! $this->is_accommodation_owner( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos para esta acomodación.', 'arriendo-facil' ) ), 403 );
		}

		if ( $occupied ) {
			update_post_meta( $post_id, self::META_KEY, '1' );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}

		$this->purge_occupied_caches();

		wp_send_json_success(
			array(
				'post_id'  => $post_id,
				'occupied' => (bool) $occupied,
			)
		);
	}

	/**
	 * Check if current user can manage occupied status.
	 *
	 * @return bool
	 */
	private function can_manage_occupied() {
		return current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' );
	}

	/**
	 * Check if current user is the owner of the accommodation.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_accommodation_owner( $post_id ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		return (int) $post->post_author === (int) get_current_user_id();
	}

	/**
	 * Clears transients that depend on occupied filtering.
	 */
	private function purge_occupied_caches() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_af_search_results_%',
				'_transient_af_featured_accommodations_%'
			)
		);
	}
}
