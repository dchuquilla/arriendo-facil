<?php
/**
 * Image Optimizer — serves intermediate image sizes as WebP by default.
 *
 * WordPress 5.8+ lets us switch the output format of generated sub-sizes
 * (the 300×, 768×, 1024×, large, medium_large files) via the
 * `image_editor_output_format` filter. We only convert sub-sizes; the
 * original upload is preserved as JPEG/PNG so downloads and reprocessing
 * stay intact. This typically shaves 25–35% off image transfer with no
 * perceptible quality loss for photographic content.
 *
 * Behavior:
 *   • New uploads only — existing media files are untouched.
 *   • If the server's Imagick/GD build lacks WebP support, we silently
 *     fall back (no filter applied).
 *   • Opt-out via `define( 'AF_DISABLE_WEBP_OUTPUT', true )` for sites
 *     that need pixel-identical reprocessing (e.g. forensic photos).
 *   • AVIF is NOT enabled by default — Imagick/GD support is uneven on
 *     shared hosting. Enable with `define( 'AF_PREFER_AVIF', true )`.
 *
 * Security: the filter only remaps the output MIME type. It does not
 * change which uploads are *allowed* (that gate remains WordPress's
 * default `upload_mimes`), so an attacker cannot smuggle a different
 * payload through this filter.
 *
 * @package Arriendo_Facil
 * @since 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Image_Optimizer
 */
class Arriendo_Facil_Image_Optimizer {

	/**
	 * Register hooks.
	 */
	public static function init() {
		if ( defined( 'AF_DISABLE_WEBP_OUTPUT' ) && AF_DISABLE_WEBP_OUTPUT ) {
			return;
		}
		add_filter( 'image_editor_output_format', array( __CLASS__, 'prefer_webp' ), 10, 3 );
	}

	/**
	 * Remap intermediate image sub-sizes to WebP (or AVIF when explicitly
	 * requested AND supported) for new JPEG/PNG uploads.
	 *
	 * @param array<string,string> $formats  Current mapping (mime → mime).
	 * @param string               $filename Source filename being processed.
	 * @param string               $mime     Source MIME.
	 * @return array<string,string>
	 */
	public static function prefer_webp( $formats, $filename, $mime ) {
		$prefer_avif  = defined( 'AF_PREFER_AVIF' ) && AF_PREFER_AVIF;
		$target_mime  = $prefer_avif && self::supports( 'image/avif' ) ? 'image/avif' : null;
		if ( null === $target_mime ) {
			$target_mime = self::supports( 'image/webp' ) ? 'image/webp' : null;
		}

		if ( null === $target_mime ) {
			return $formats;
		}

		// Only remap source types we trust the editor to re-encode well.
		$formats['image/jpeg'] = $target_mime;
		$formats['image/png']  = $target_mime;

		return $formats;
	}

	/**
	 * Check whether the active image editor can encode the given MIME.
	 *
	 * We ask WP for the editor it would use for a dummy PNG, then query
	 * supports() on it. If the probe fails for any reason (missing
	 * extension, filesystem perms), we return false so callers fall back
	 * to the default format.
	 *
	 * @param string $mime Target MIME.
	 * @return bool
	 */
	private static function supports( $mime ) {
		static $cache = array();
		if ( isset( $cache[ $mime ] ) ) {
			return $cache[ $mime ];
		}

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			$cache[ $mime ] = false;
			return false;
		}

		// A tiny 1×1 PNG baked inline keeps the probe self-contained.
		$probe_path = wp_tempnam( 'af-image-probe.png' );
		if ( ! $probe_path ) {
			$cache[ $mime ] = false;
			return false;
		}

		$png_bytes = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
		);
		if ( false === file_put_contents( $probe_path, $png_bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$cache[ $mime ] = false;
			@unlink( $probe_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		$editor = wp_get_image_editor( $probe_path );
		@unlink( $probe_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_wp_error( $editor ) ) {
			$cache[ $mime ] = false;
			return false;
		}

		$cache[ $mime ] = (bool) call_user_func( array( get_class( $editor ), 'supports_mime_type' ), $mime );
		return $cache[ $mime ];
	}
}

add_action( 'plugins_loaded', array( 'Arriendo_Facil_Image_Optimizer', 'init' ) );
