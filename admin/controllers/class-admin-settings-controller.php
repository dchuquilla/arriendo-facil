<?php
/**
 * AJAX endpoints for panel settings and utilities.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Admin_Settings_Controller
 */
class Arriendo_Facil_Admin_Settings_Controller {

	/**
	 * Registers AJAX endpoints.
	 */
	public function __construct() {
		add_action( 'wp_ajax_af_resolve_short_url', array( $this, 'ajax_resolve_short_url' ) );
		add_action( 'wp_ajax_af_save_dashboard_hero_video', array( $this, 'ajax_save_dashboard_hero_video' ) );
		add_action( 'wp_ajax_af_toggle_buildings_module', array( $this, 'ajax_toggle_buildings_module' ) );
	}

	/**
	 * AJAX handler: resolve a short Google Maps URL to extract the final redirect URL.
	 *
	 * Defense-in-depth against SSRF (OWASP A10):
	 * - Nonce + capability check (edit_posts).
	 * - Host allow-list: only Google Maps domains permitted.
	 * - DNS pre-resolution: rejects hosts resolving to loopback / private /
	 *   link-local / reserved IPs (blocks 169.254.169.254 metadata, LAN, etc.).
	 * - Redirects capped at 3 and re-validated via http_request_args filter.
	 * - Generic error messages; details logged with [AF Security] prefix.
	 */
	public function ajax_resolve_short_url() {
		check_ajax_referer( 'af_location_nonce', 'nonce' );

		$actor_id  = get_current_user_id();
		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';

		if ( ! current_user_can( 'edit_posts' ) ) {
			error_log( sprintf( '[AF Security] resolve_short_url denied (user=%d ip=%s)', $actor_id, $remote_ip ) );
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => __( 'URL no permitida.', 'arriendo-facil' ) ), 400 );
		}

		if ( ! $this->is_url_safe_for_short_resolve( $url ) ) {
			error_log( sprintf( '[AF Security] resolve_short_url blocked SSRF candidate (user=%d ip=%s url=%s)', $actor_id, $remote_ip, $url ) );
			wp_send_json_error( array( 'message' => __( 'URL no permitida.', 'arriendo-facil' ) ), 400 );
		}

		$block_redirect = function ( $args, $redirect_url ) {
			if ( is_string( $redirect_url ) && '' !== $redirect_url && ! $this->is_url_safe_for_short_resolve( $redirect_url ) ) {
				return new WP_Error( 'af_ssrf_blocked', 'Redirect target not allowed' );
			}
			return $args;
		};
		add_filter( 'http_request_redirection_count', array( $this, 'cap_short_resolve_redirects' ) );
		add_filter( 'http_request_args', $block_redirect, 10, 2 );

		$response = wp_remote_get(
			$url,
			array(
				'redirection' => 3,
				'timeout'     => 8,
				'user-agent'  => 'Mozilla/5.0 (compatible; ArriendoFacilResolver/1.0)',
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		remove_filter( 'http_request_args', $block_redirect, 10 );
		remove_filter( 'http_request_redirection_count', array( $this, 'cap_short_resolve_redirects' ) );

		if ( is_wp_error( $response ) ) {
			error_log( sprintf( '[AF Security] resolve_short_url wp_remote_get failed: %s', $response->get_error_message() ) );
			wp_send_json_error( array( 'message' => __( 'No se pudo resolver la URL.', 'arriendo-facil' ) ), 502 );
		}

		$body      = wp_remote_retrieve_body( $response );
		$final_url = '';

		// Try to find coordinates in the resolved page content.
		if ( preg_match( '/@(-?\d+\.\d+),(-?\d+\.\d+)/', $body, $m ) ) {
			$final_url = '@' . $m[1] . ',' . $m[2];
		} elseif ( preg_match( '/center=(-?\d+\.\d+)%2C(-?\d+\.\d+)/', $body, $m ) ) {
			$final_url = '@' . $m[1] . ',' . $m[2];
		} elseif ( preg_match( '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $body, $m ) ) {
			$final_url = '!3d' . $m[1] . '!4d' . $m[2];
		} elseif ( preg_match( '/ll=(-?\d+\.\d+),(-?\d+\.\d+)/', $body, $m ) ) {
			$final_url = '@' . $m[1] . ',' . $m[2];
		} elseif ( preg_match( '/href="([^"]*google\.com\/maps[^"]*)"/', $body, $m ) ) {
			$final_url = html_entity_decode( $m[1] );
		} elseif ( preg_match( '/content="0;\s*url=([^"]+)"/i', $body, $m ) ) {
			$final_url = $m[1];
		} elseif ( preg_match( '/window\.location\s*=\s*["\']([^"\']+)/', $body, $m ) ) {
			$final_url = $m[1];
		}

		if ( ! $final_url ) {
			$final_url = $url;
		}

		wp_send_json_success( array( 'resolved_url' => $final_url ) );
	}

	/**
	 * Filter helper: cap redirects at 3 for the short-url resolver.
	 *
	 * @param int $count Redirection count.
	 * @return int
	 */
	public function cap_short_resolve_redirects( $count ) {
		return min( (int) $count, 3 );
	}

	/**
	 * Validates a URL for the short-URL resolver: allow-listed Google host,
	 * https/http scheme, and DNS resolves only to public IPs.
	 *
	 * @param string $url URL to validate.
	 * @return bool
	 */
	private function is_url_safe_for_short_resolve( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = strtolower( (string) $parts['host'] );

		$allowed_hosts = array(
			'maps.app.goo.gl',
			'goo.gl',
			'app.goo.gl',
			'maps.google.com',
			'www.google.com',
			'google.com',
			'g.co',
			'maps.googleapis.com',
		);

		$host_allowed = false;
		foreach ( $allowed_hosts as $allowed ) {
			if ( $host === $allowed || substr( $host, -( strlen( $allowed ) + 1 ) ) === '.' . $allowed ) {
				$host_allowed = true;
				break;
			}
		}
		if ( ! $host_allowed ) {
			return false;
		}

		$ips = @gethostbynamel( $host );
		if ( ! is_array( $ips ) || empty( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			$public = filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			if ( false === $public ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * AJAX: sets (or clears) the branded intro video shown on the dashboard
	 * hero. Accepts a direct video file URL (mp4/webm/ogg) or a link from a
	 * supported oEmbed provider (YouTube, Vimeo).
	 *
	 * @return void
	 */
	public function ajax_save_dashboard_hero_video() {
		check_ajax_referer( 'af_dashboard_hero_video_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$url = isset( $_POST['video_url'] ) ? esc_url_raw( wp_unslash( $_POST['video_url'] ) ) : '';

		if ( '' === $url ) {
			delete_option( 'af_dashboard_hero_video_url' );
			wp_send_json_success( array( 'message' => __( 'Video removido.', 'arriendo-facil' ) ) );
		}

		$is_direct_file = (bool) preg_match( '/\.(mp4|webm|ogg)(\?.*)?$/i', $url );
		$is_oembed       = false;

		if ( ! $is_direct_file ) {
			$is_oembed = false !== wp_oembed_get( $url, array( 'width' => 640 ) );
		}

		if ( ! $is_direct_file && ! $is_oembed ) {
			wp_send_json_error( array( 'message' => __( 'URL no soportada. Usa un archivo .mp4/.webm o un enlace de YouTube/Vimeo.', 'arriendo-facil' ) ), 400 );
		}

		update_option( 'af_dashboard_hero_video_url', $url );

		wp_send_json_success( array( 'message' => __( 'Video guardado.', 'arriendo-facil' ) ) );
	}

	/**
	 * AJAX: enables or disables the buildings module (gastos comunes entre
	 * unidades dentro de un mismo edificio). Hides the section from the
	 * sidebar when it is not needed, keeping the property flow simple.
	 *
	 * @return void
	 */
	public function ajax_toggle_buildings_module() {
		check_ajax_referer( 'af_buildings_module_nonce', 'nonce' );

		if ( ! current_user_can( Arriendo_Facil_Tenancy::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'arriendo-facil' ) ), 403 );
		}

		$enabled = isset( $_POST['enabled'] ) ? ( '1' === sanitize_key( wp_unslash( $_POST['enabled'] ) ) ) : false;

		update_option( 'af_use_buildings', $enabled ? '1' : '0' );

		wp_send_json_success(
			array(
				'message'  => $enabled
					? __( 'Sección de cobranza activada. Ya puedes organizar departamentos dentro de un edificio.', 'arriendo-facil' )
					: __( 'Sección oculta. Puedes reactivarla cuando la necesites.', 'arriendo-facil' ),
				'enabled'  => $enabled,
				'show_menu' => Arriendo_Facil_Property_Structure::module_enabled(),
			)
		);
	}
}
