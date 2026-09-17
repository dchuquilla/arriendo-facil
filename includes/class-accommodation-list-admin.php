<?php
/**
 * Enriches the native accommodation list table (edit.php?post_type=accommodation).
 *
 * Adds a thumbnail, a semantic status pill and a monthly-rent column, plus
 * status/type filters and price sorting. This keeps the WordPress list table
 * (bulk actions, quick edit, search, pagination) fully functional while making
 * the screen consistent with the plugin's green/slate design system.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Accommodation_List_Admin {

	/**
	 * Registers the list-screen hooks.
	 */
	public function __construct() {
		// Priority 9 so our columns land before the featured/occupied toggles
		// (registered at the default 10) are appended after the title column.
		add_filter( 'manage_accommodation_posts_columns', array( $this, 'add_columns' ), 9 );
		add_action( 'manage_accommodation_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_filters' ) );
		add_filter( 'manage_edit-accommodation_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_query_filters' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Returns the localized status labels used across the admin.
	 *
	 * @return array<string, string>
	 */
	private function get_status_labels() {
		return array(
			'available'   => __( 'Disponible', 'arriendo-facil' ),
			'rented'      => __( 'Arrendado', 'arriendo-facil' ),
			'maintenance' => __( 'En mantenimiento', 'arriendo-facil' ),
			'inactive'    => __( 'Inactivo', 'arriendo-facil' ),
		);
	}

	/**
	 * Returns the localized property-type labels used across the admin.
	 *
	 * @return array<string, string>
	 */
	private function get_type_labels() {
		return array(
			'apartment'  => __( 'Apartamento', 'arriendo-facil' ),
			'house'      => __( 'Casa', 'arriendo-facil' ),
			'office'     => __( 'Oficina', 'arriendo-facil' ),
			'room'       => __( 'Habitación', 'arriendo-facil' ),
			'commercial' => __( 'Comercial', 'arriendo-facil' ),
		);
	}

	/**
	 * Adds the thumbnail, status and monthly-rent columns.
	 *
	 * The custom columns are inserted right before and right after the title
	 * column so the DOM reads naturally: Imagen → Inmueble → (Destacada /
	 * Ocupada, appended by their own admin classes) → Tipo y ubicación →
	 * Renta mensual → Estado → Fecha.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['af_thumb'] = __( 'Imagen', 'arriendo-facil' );
			}
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['af_meta']   = __( 'Tipo y ubicación', 'arriendo-facil' );
				$new['af_price']  = __( 'Renta mensual', 'arriendo-facil' );
				$new['af_status'] = __( 'Estado', 'arriendo-facil' );
			}
		}

		if ( ! isset( $new['af_thumb'] ) ) {
			$new['af_thumb'] = __( 'Imagen', 'arriendo-facil' );
		}
		if ( ! isset( $new['af_status'] ) ) {
			$new['af_status'] = __( 'Estado', 'arriendo-facil' );
		}
		if ( ! isset( $new['af_price'] ) ) {
			$new['af_price'] = __( 'Renta mensual', 'arriendo-facil' );
		}
		if ( ! isset( $new['af_meta'] ) ) {
			$new['af_meta'] = __( 'Tipo y ubicación', 'arriendo-facil' );
		}

		return $new;
	}

	/**
	 * Marks the monthly-rent column as sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable_columns( $columns ) {
		$columns['af_price'] = 'af_price';
		return $columns;
	}

	/**
	 * Renders the content of the custom columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Accommodation post ID.
	 */
	public function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'af_thumb':
				$this->render_thumb( $post_id );
				break;

			case 'af_status':
				$status = (string) get_post_meta( $post_id, '_af_status', true );
				$labels = $this->get_status_labels();
				if ( '' === $status || ! isset( $labels[ $status ] ) ) {
					$status = 'available';
				}
				echo af_pill( $status, $labels[ $status ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup.
				break;

			case 'af_price':
				$rent = (float) get_post_meta( $post_id, '_af_monthly_rent', true );
				if ( $rent <= 0 ) {
					echo '<span class="af-list-empty">—</span>';
					break;
				}
				printf(
					'<span class="af-list-price">$%1$s <small>%2$s</small></span>',
					esc_html( number_format_i18n( $rent, 2 ) ),
					esc_html__( '/ mes', 'arriendo-facil' )
				);
				break;

			case 'af_meta':
				$this->render_meta( $post_id );
				break;
		}
	}

	/**
	 * Returns the localized property-type icons used across the admin.
	 *
	 * @return array<string, string>
	 */
	private function get_type_icons() {
		return array(
			'apartment'  => 'building',
			'house'      => 'home',
			'office'     => 'building-2',
			'room'       => 'bed',
			'commercial' => 'store',
		);
	}

	/**
	 * Renders the property type + location line inside the card meta cell.
	 *
	 * @param int $post_id Accommodation post ID.
	 */
	private function render_meta( $post_id ) {
		$type  = (string) get_post_meta( $post_id, '_af_property_type', true );
		$addr  = trim( (string) get_post_meta( $post_id, '_af_address', true ) );
		$city  = trim( (string) get_post_meta( $post_id, '_af_city', true ) );
		$place = trim( $addr . ( '' !== $city ? ', ' . $city : '' ) );

		$labels = $this->get_type_labels();
		$icons  = $this->get_type_icons();

		echo '<div class="af-list-meta">';

		if ( '' !== $type && isset( $labels[ $type ] ) ) {
			$icon = isset( $icons[ $type ] ) ? $icons[ $type ] : 'building';
			echo '<span class="af-list-meta__item">'
				. '<span class="af-list-meta__icon" aria-hidden="true">' . af_lucide( $icon, 13 ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper.
				. '<span class="af-list-meta__text">' . esc_html( $labels[ $type ] ) . '</span>'
				. '</span>';
		}

		if ( '' !== $place ) {
			echo '<span class="af-list-meta__item">'
				. '<span class="af-list-meta__icon" aria-hidden="true">' . af_lucide( 'map-pin', 13 ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper.
				. '<span class="af-list-meta__text">' . esc_html( $place ) . '</span>'
				. '</span>';
		}

		echo '</div>';
	}

	/**
	 * Renders the thumbnail cell, falling back to the gallery or an icon.
	 *
	 * @param int $post_id Accommodation post ID.
	 */
	private function render_thumb( $post_id ) {
		$title = get_the_title( $post_id );
		$url   = get_the_post_thumbnail_url( $post_id, 'medium' );

		if ( ! $url ) {
			$gallery = get_post_meta( $post_id, '_af_gallery', true );
			if ( is_array( $gallery ) && ! empty( $gallery ) ) {
				$url = wp_get_attachment_image_url( (int) reset( $gallery ), 'medium' );
			}
		}

		if ( $url ) {
			printf(
				'<img class="af-list-thumb" src="%1$s" alt="%2$s" loading="lazy" />',
				esc_url( $url ),
				esc_attr( $title )
			);
			return;
		}

		printf(
			'<span class="af-list-thumb af-list-thumb--placeholder" aria-hidden="true">%1$s</span>',
			af_lucide( 'building', 18 ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG helper.
		);
	}

	/**
	 * Renders the status/type filter dropdowns in the table navigation.
	 *
	 * @param string $post_type Current post type.
	 */
	public function render_filters( $post_type ) {
		if ( 'accommodation' !== $post_type ) {
			return;
		}

		$current_status = isset( $_GET['af_status'] ) ? sanitize_key( wp_unslash( $_GET['af_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_type   = isset( $_GET['af_type'] ) ? sanitize_key( wp_unslash( $_GET['af_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$statuses = $this->get_status_labels();
		$types    = $this->get_type_labels();

		echo '<label class="screen-reader-text" for="af-filter-status">' . esc_html__( 'Filtrar por estado', 'arriendo-facil' ) . '</label>';
		echo '<select name="af_status" id="af-filter-status">';
		echo '<option value="">' . esc_html__( 'Todos los estados', 'arriendo-facil' ) . '</option>';
		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current_status, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="af-filter-type">' . esc_html__( 'Filtrar por tipo', 'arriendo-facil' ) . '</label>';
		echo '<select name="af_type" id="af-filter-type">';
		echo '<option value="">' . esc_html__( 'Todos los tipos', 'arriendo-facil' ) . '</option>';
		foreach ( $types as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current_type, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Applies the status/type filters and the price ordering to the list query.
	 *
	 * @param WP_Query $query Current query.
	 */
	public function apply_query_filters( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'accommodation' !== $query->get( 'post_type' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-accommodation' !== $screen->id ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$status = isset( $_GET['af_status'] ) ? sanitize_key( wp_unslash( $_GET['af_status'] ) ) : '';
		$type   = isset( $_GET['af_type'] ) ? sanitize_key( wp_unslash( $_GET['af_type'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$statuses = $this->get_status_labels();
		$types    = $this->get_type_labels();

		if ( '' !== $status && isset( $statuses[ $status ] ) ) {
			$meta_query[] = array(
				'key'   => '_af_status',
				'value' => $status,
			);
		}

		if ( '' !== $type && isset( $types[ $type ] ) ) {
			$meta_query[] = array(
				'key'   => '_af_property_type',
				'value' => $type,
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$meta_query['relation'] = 'AND';
		}
		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( 'af_price' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', '_af_monthly_rent' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Enqueues the list-screen stylesheet.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-accommodation' !== $screen->id ) {
			return;
		}

		$css_path = ARRIENDO_FACIL_PLUGIN_DIR . 'assets/css/af-accommodation-list.css';
		wp_enqueue_style(
			'af-accommodation-list',
			ARRIENDO_FACIL_PLUGIN_URL . 'assets/css/af-accommodation-list.css',
			array( 'af-tokens', 'af-shell', 'af-admin-chrome' ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : ARRIENDO_FACIL_VERSION
		);
	}
}
