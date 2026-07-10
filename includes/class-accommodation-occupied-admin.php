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
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'action'          => self::AJAX_ACTION,
			'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
			'leaseNonce'      => wp_create_nonce( 'af_lease_nonce' ),
			'i18n'            => array(
				'yes'              => __( 'Sí', 'arriendo-facil' ),
				'no'               => __( 'No', 'arriendo-facil' ),
				'error'            => __( 'No se pudo actualizar. Intenta nuevamente.', 'arriendo-facil' ),
				'unoccupyTitle'    => __( 'Desmarcar como ocupada', 'arriendo-facil' ),
				'unoccupyWarning'  => __( 'Si existe un contrato activo, será terminado anticipadamente y el inmueble quedará disponible. Se notificará al arrendatario y a los interesados en cola.', 'arriendo-facil' ),
				'reasonLabel'      => __( 'Motivo (obligatorio si hay contrato activo):', 'arriendo-facil' ),
				'reasonPlaceholder'=> __( 'Ej: Acuerdo mutuo, venta del inmueble, otro...', 'arriendo-facil' ),
				'btnCancel'        => __( 'Cancelar', 'arriendo-facil' ),
				'btnConfirm'       => __( 'Confirmar', 'arriendo-facil' ),
				'processing'       => __( 'Procesando…', 'arriendo-facil' ),
				'noReason'         => __( 'Ingresa un motivo antes de confirmar.', 'arriendo-facil' ),
			),
		);

		$js = "(function(){
			var cfg = " . wp_json_encode( $config ) . ";

			/* ── Inline modal for unoccupy confirmation ── */
			var modalEl = null;
			function buildModal() {
				if (modalEl) return;
				modalEl = document.createElement('div');
				modalEl.id = 'af-unoccupy-modal';
				modalEl.style.cssText = 'display:none;position:fixed;inset:0;z-index:200000;align-items:center;justify-content:center;';
				modalEl.innerHTML = '<div id=\"af-unoccupy-backdrop\" style=\"position:absolute;inset:0;background:rgba(0,0,0,.55);\"></div>'
					+ '<div style=\"position:relative;background:#fff;border-radius:10px;max-width:440px;width:92%;padding:26px 26px 20px;box-shadow:0 8px 32px rgba(0,0,0,.22);\">'
					+ '<h2 style=\"margin:0 0 6px;font-size:17px;color:#b91c1c;\">' + cfg.i18n.unoccupyTitle + '</h2>'
					+ '<p style=\"margin:0 0 14px;font-size:13px;color:#374151;line-height:1.5;\">' + cfg.i18n.unoccupyWarning + '</p>'
					+ '<label style=\"display:block;font-weight:600;font-size:13px;margin-bottom:5px;\">' + cfg.i18n.reasonLabel + '</label>'
					+ '<textarea id=\"af-unoccupy-reason\" rows=\"3\" placeholder=\"' + cfg.i18n.reasonPlaceholder + '\" style=\"width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;resize:vertical;\"></textarea>'
					+ '<p id=\"af-unoccupy-feedback\" style=\"margin:6px 0 0;font-size:12px;color:#b91c1c;\" aria-live=\"polite\"></p>'
					+ '<div style=\"display:flex;justify-content:flex-end;gap:10px;margin-top:16px;\">'
					+ '<button id=\"af-unoccupy-cancel\" type=\"button\" class=\"button\">' + cfg.i18n.btnCancel + '</button>'
					+ '<button id=\"af-unoccupy-confirm\" type=\"button\" class=\"button\" style=\"background:#b91c1c;border-color:#991b1b;color:#fff;\">' + cfg.i18n.btnConfirm + '</button>'
					+ '</div>'
					+ '</div>';
				document.body.appendChild(modalEl);

				document.getElementById('af-unoccupy-backdrop').addEventListener('click', cancelModal);
				document.getElementById('af-unoccupy-cancel').addEventListener('click', cancelModal);
				document.addEventListener('keydown', function(e){ if ('Escape' === e.key && modalEl && modalEl.style.display !== 'none') cancelModal(); });
			}

			var pendingToggle = null; /* { input, wrap, state, postId } */

			function openModal(context) {
				buildModal();
				pendingToggle = context;
				document.getElementById('af-unoccupy-reason').value = '';
				document.getElementById('af-unoccupy-feedback').textContent = '';
				var btn = document.getElementById('af-unoccupy-confirm');
				btn.disabled = false;
				btn.textContent = cfg.i18n.btnConfirm;
				modalEl.style.display = 'flex';
				document.getElementById('af-unoccupy-reason').focus();
			}

			function cancelModal() {
				if (modalEl) modalEl.style.display = 'none';
				if (pendingToggle) {
					/* Revert the checkbox to checked */
					pendingToggle.input.checked = true;
					if (pendingToggle.wrap) {
						pendingToggle.wrap.classList.remove('is-loading');
						pendingToggle.wrap.classList.add('is-on');
					}
					if (pendingToggle.state) pendingToggle.state.textContent = cfg.i18n.yes;
				}
				pendingToggle = null;
			}

			function finishToggle(ctx, on) {
				ctx.input.checked = on;
				if (ctx.state) ctx.state.textContent = on ? cfg.i18n.yes : cfg.i18n.no;
				if (ctx.wrap) {
					ctx.wrap.classList.toggle('is-on', on);
					ctx.wrap.classList.remove('is-loading');
				}
			}

			document.addEventListener('change', function(e){
				var input = e.target;
				if (!input.classList || !input.classList.contains('af-occupied-toggle')) return;
				var wrap   = input.closest('.af-occupied-toggle-wrap');
				var state  = wrap ? wrap.querySelector('.af-occupied-toggle-state') : null;
				var postId = parseInt(input.getAttribute('data-id'), 10);
				if (!postId) return;
				var desired = input.checked ? 1 : 0;

				/* Unchecking: show confirmation modal first */
				if (0 === desired) {
					if (wrap) wrap.classList.add('is-loading');
					openModal({ input: input, wrap: wrap, state: state, postId: postId });
					return;
				}

				/* Checking: proceed normally */
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
						finishToggle({ input: input, wrap: wrap, state: state }, !!res.data.occupied);
					})
					.catch(function(err){
						input.checked = !desired;
						window.alert(err.message || cfg.i18n.error);
					})
					.finally(function(){
						if (wrap) wrap.classList.remove('is-loading');
					});
			});

			/* Confirm button: call early-terminate; only uncheck if it succeeds */
			document.addEventListener('click', function(e){
				if (!e.target || e.target.id !== 'af-unoccupy-confirm') return;
				if (!pendingToggle) return;

				var reason  = document.getElementById('af-unoccupy-reason').value.trim();
				var fbEl    = document.getElementById('af-unoccupy-feedback');
				var btnConf = document.getElementById('af-unoccupy-confirm');

				fbEl.textContent = '';
				btnConf.disabled = true;
				btnConf.textContent = cfg.i18n.processing;

				var ctx = pendingToggle;

				/* Step 1: early termination (terminates lease, frees accommodation, notifies) */
				var earlyBody = new FormData();
				earlyBody.append('action',           'af_early_terminate_lease');
				earlyBody.append('nonce',            cfg.leaseNonce);
				earlyBody.append('accommodation_id', ctx.postId);
				earlyBody.append('reason',           reason !== '' ? reason : 'Desmarcado como ocupado por el administrador/propietario.');

				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: earlyBody })
					.then(function(r){ return r.json(); })
					.then(function(earlyRes){
						if (!earlyRes || !earlyRes.success) {
							/* Step 1 failed — show error, keep checkbox checked, stay in modal */
							var msg = (earlyRes && earlyRes.data && earlyRes.data.message)
								? earlyRes.data.message
								: cfg.i18n.error;
							fbEl.textContent = msg;
							btnConf.disabled = false;
							btnConf.textContent = cfg.i18n.btnConfirm;
							return;
						}

						/* Step 2: Step 1 succeeded — now update the _af_is_occupied meta */
						var toggleBody = new FormData();
						toggleBody.append('action',   cfg.action);
						toggleBody.append('nonce',    cfg.nonce);
						toggleBody.append('post_id',  ctx.postId);
						toggleBody.append('occupied', 0);

						fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: toggleBody })
							.then(function(r2){ return r2.json(); })
							.then(function(){
								/* Both steps succeeded — update UI */
								if (modalEl) modalEl.style.display = 'none';
								pendingToggle = null;
								finishToggle(ctx, false);
								var msg = (earlyRes.data && earlyRes.data.message)
									? earlyRes.data.message
									: cfg.i18n.yes;
								window.alert(msg);
							})
							.catch(function(){
								/* Toggle meta failed — early termination already happened;
								   close modal and update UI anyway (lease IS terminated) */
								if (modalEl) modalEl.style.display = 'none';
								pendingToggle = null;
								finishToggle(ctx, false);
							});
					})
					.catch(function(){
						fbEl.textContent = cfg.i18n.error;
						btnConf.disabled = false;
						btnConf.textContent = cfg.i18n.btnConfirm;
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
