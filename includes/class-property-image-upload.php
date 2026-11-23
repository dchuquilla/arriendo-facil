<?php
/**
 * Image-only uploads for the property media picker.
 *
 * The wizard and the property meta box open the WordPress media modal. Left
 * alone it accepts videos, PDFs and anything else WordPress allows, and it
 * rejects the formats phones and newer cameras produce (.jfif, JPEG XL).
 *
 * This guard, applied only to media-modal uploads (action upload-attachment /
 * async-upload.php) coming from the property screens or from non-admin users:
 *
 *   • accepts JPG/JPEG, JFIF (a JPEG), PNG and WebP;
 *   • accepts JPEG XL only when the server can convert it to JPEG, otherwise
 *     says so in plain Spanish instead of failing silently;
 *   • decides by the file CONTENT (magic bytes), never by extension or the
 *     browser-supplied MIME, so a renamed video or script is refused;
 *   • caps the file size and the pixel count, and shrinks big originals.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Property_Image_Upload {

	/** Flag the media modal sends so a request is known to come from a property screen. */
	const PARAM = 'af_image_only';

	/** Default per-file ceiling (bytes). Filter: af_property_image_max_bytes. */
	const DEFAULT_MAX_BYTES = 10485760;

	/** Largest decoded image accepted (pixels), a guard against decompression bombs. */
	const MAX_PIXELS = 60000000;

	/** Longest side kept for the stored original. Filter: af_property_image_max_side. */
	const DEFAULT_MAX_SIDE = 2400;

	/**
	 * Extensions the picker offers (JXL is listed so the browser lets it through
	 * to the server, which then converts or explains).
	 */
	const EXTENSIONS = 'jpg,jpeg,jfif,png,webp,jxl';

	public function __construct() {
		add_filter( 'upload_mimes', array( $this, 'restrict_mimes' ), 99 );
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'validate_upload' ), 1 );
		add_filter( 'big_image_size_threshold', array( $this, 'big_image_threshold' ), 99 );
		add_filter( 'plupload_default_settings', array( $this, 'client_settings' ) );
		add_filter( 'plupload_default_params', array( $this, 'client_params' ) );
	}

	/**
	 * Largest accepted file in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		$limit = (int) apply_filters( 'af_property_image_max_bytes', self::DEFAULT_MAX_BYTES );
		if ( function_exists( 'wp_max_upload_size' ) ) {
			$limit = min( $limit, (int) wp_max_upload_size() );
		}

		return max( 1, $limit );
	}

	/**
	 * Whether the current request is a media-modal upload this guard must police.
	 *
	 * @return bool
	 */
	private function is_guarded_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- only classifies the request; core verifies the nonce.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) $_SERVER['SCRIPT_NAME'] ) : '';
		$flag   = ! empty( $_REQUEST[ self::PARAM ] );
		// phpcs:enable WordPress.Security.NonceVerification

		$is_modal_upload = 'upload-attachment' === $action || 'async-upload.php' === $script;
		if ( ! $is_modal_upload ) {
			return false;
		}

		return $flag || ( is_user_logged_in() && ! current_user_can( 'manage_options' ) );
	}

	/**
	 * Whether the current admin screen is a property screen (media modal host).
	 *
	 * @return bool
	 */
	private function is_property_screen() {
		if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( class_exists( 'Arriendo_Facil_Accommodation_Wizard' ) && Arriendo_Facil_Accommodation_Wizard::PAGE_SLUG === $page ) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && 'accommodation' === $screen->post_type;
	}

	/**
	 * Image types only, with JFIF added (it is a JPEG with another extension).
	 *
	 * @param array<string,string> $mimes Allowed types.
	 * @return array<string,string>
	 */
	public function restrict_mimes( $mimes ) {
		if ( ! $this->is_guarded_request() ) {
			return $mimes;
		}

		return array(
			'jpg|jpeg|jpe|jfif' => 'image/jpeg',
			'png'               => 'image/png',
			'webp'              => 'image/webp',
		);
	}

	/**
	 * Keeps the stored original at a sensible size (core downsizes anything larger).
	 *
	 * @param int $threshold Current threshold in pixels (or false).
	 * @return int|false
	 */
	public function big_image_threshold( $threshold ) {
		if ( ! $this->is_guarded_request() ) {
			return $threshold;
		}

		return max( 800, (int) apply_filters( 'af_property_image_max_side', self::DEFAULT_MAX_SIDE ) );
	}

	/**
	 * Tells the browser uploader which files to offer and the size limit, so most
	 * mistakes are caught before anything is sent.
	 *
	 * @param array $settings Plupload settings.
	 * @return array
	 */
	public function client_settings( $settings ) {
		if ( ! $this->is_property_screen() ) {
			return $settings;
		}

		if ( ! isset( $settings['filters'] ) || ! is_array( $settings['filters'] ) ) {
			$settings['filters'] = array();
		}
		$settings['filters']['mime_types']    = array(
			array(
				'title'      => __( 'Imágenes', 'arriendo-facil' ),
				'extensions' => self::EXTENSIONS,
			),
		);
		$settings['filters']['max_file_size'] = self::max_bytes() . 'b';

		return $settings;
	}

	/**
	 * Marks the modal's uploads so the server enforces the same rules.
	 *
	 * @param array $params Plupload multipart params.
	 * @return array
	 */
	public function client_params( $params ) {
		if ( $this->is_property_screen() ) {
			$params[ self::PARAM ] = 1;
		}

		return $params;
	}

	/**
	 * Detects the real image type from the file's first bytes.
	 *
	 * @param string $head First bytes of the file (at least 16).
	 * @return string jpeg|png|webp|jxl|gif|heic|'' (empty when not a supported image).
	 */
	public static function sniff( $head ) {
		$head = (string) $head;

		if ( 0 === strncmp( $head, "\xFF\xD8\xFF", 3 ) ) {
			return 'jpeg';
		}
		if ( 0 === strncmp( $head, "\x89PNG\r\n\x1A\n", 8 ) ) {
			return 'png';
		}
		if ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'webp';
		}
		// JPEG XL: bare codestream or ISO-BMFF container.
		if ( 0 === strncmp( $head, "\xFF\x0A", 2 ) || 0 === strncmp( $head, "\x00\x00\x00\x0CJXL \r\n\x87\n", 12 ) ) {
			return 'jxl';
		}
		if ( 0 === strncmp( $head, 'GIF8', 4 ) ) {
			return 'gif';
		}
		if ( 'ftyp' === substr( $head, 4, 4 ) && in_array( substr( $head, 8, 4 ), array( 'heic', 'heix', 'mif1', 'avif' ), true ) ) {
			return 'heic';
		}

		return '';
	}

	/**
	 * Whether this server can turn JPEG XL into JPEG.
	 *
	 * @return bool
	 */
	public static function can_convert_jxl() {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		try {
			return ! empty( \Imagick::queryFormats( 'JXL' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Rejects non-images and oversize files; converts JPEG XL when possible.
	 *
	 * @param array $file Entry of $_FILES being handled.
	 * @return array
	 */
	public function validate_upload( $file ) {
		if ( ! $this->is_guarded_request() || ! isset( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return $file;
		}

		$size = (int) filesize( $file['tmp_name'] );
		if ( $size > self::max_bytes() ) {
			$file['error'] = sprintf(
				/* translators: %s: maximum size, e.g. 10 MB */
				__( 'La imagen pesa demasiado. El máximo es %s; reduce su tamaño e inténtalo de nuevo.', 'arriendo-facil' ),
				size_format( self::max_bytes() )
			);
			return $file;
		}

		$handle = fopen( $file['tmp_name'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$head   = $handle ? (string) fread( $handle, 16 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$kind = self::sniff( $head );

		if ( 'jxl' === $kind ) {
			return $this->convert_jxl( $file );
		}

		if ( ! in_array( $kind, array( 'jpeg', 'png', 'webp' ), true ) ) {
			$file['error'] = 'heic' === $kind
				? __( 'Este formato (HEIC/AVIF) no es compatible. Guarda la foto como JPG o PNG e inténtalo de nuevo.', 'arriendo-facil' )
				: __( 'Solo se pueden subir imágenes en formato JPG, JFIF, PNG, WebP o JPEG XL. No se permiten videos ni otros archivos.', 'arriendo-facil' );
			return $file;
		}

		$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
			$file['error'] = __( 'La imagen está dañada o no se puede leer.', 'arriendo-facil' );
			return $file;
		}
		if ( (int) $info[0] * (int) $info[1] > self::MAX_PIXELS ) {
			$file['error'] = __( 'La imagen tiene una resolución demasiado alta. Reduce sus dimensiones e inténtalo de nuevo.', 'arriendo-facil' );
			return $file;
		}

		// A .jfif, an extensionless or a mislabelled JPEG is stored with its real extension.
		$ext_for_kind = array(
			'jpeg' => 'jpg',
			'png'  => 'png',
			'webp' => 'webp',
		);
		$base         = pathinfo( (string) ( $file['name'] ?? 'foto' ), PATHINFO_FILENAME );
		$file['name'] = ( '' !== $base ? $base : 'foto' ) . '.' . $ext_for_kind[ $kind ];
		$file['type'] = $info['mime'] ?? $file['type'];

		return $file;
	}

	/**
	 * Replaces an uploaded JPEG XL with a JPEG (or explains why it cannot).
	 *
	 * @param array $file Entry of $_FILES.
	 * @return array
	 */
	private function convert_jxl( $file ) {
		if ( ! self::can_convert_jxl() ) {
			$file['error'] = __( 'El formato JPEG XL todavía no es compatible con este servidor. Conviértela a JPG o PNG e inténtalo de nuevo.', 'arriendo-facil' );
			return $file;
		}

		try {
			$image = new \Imagick();
			$image->setResourceLimit( \Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024 );
			$image->readImage( $file['tmp_name'] );
			$image->setIteratorIndex( 0 );

			if ( $image->getImageWidth() * $image->getImageHeight() > self::MAX_PIXELS ) {
				$image->clear();
				$file['error'] = __( 'La imagen tiene una resolución demasiado alta. Reduce sus dimensiones e inténtalo de nuevo.', 'arriendo-facil' );
				return $file;
			}

			$image->setImageBackgroundColor( 'white' );
			$image = $image->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );
			$image->setImageFormat( 'jpeg' );
			$image->setImageCompressionQuality( 88 );
			$image->stripImage();

			if ( false === file_put_contents( $file['tmp_name'], $image->getImagesBlob() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				throw new \RuntimeException( 'write failed' );
			}
			$image->clear();
		} catch ( \Throwable $e ) {
			$file['error'] = __( 'No se pudo convertir la imagen JPEG XL. Conviértela a JPG o PNG e inténtalo de nuevo.', 'arriendo-facil' );
			return $file;
		}

		clearstatcache( true, $file['tmp_name'] );
		$base         = pathinfo( (string) ( $file['name'] ?? 'foto' ), PATHINFO_FILENAME );
		$file['name'] = ( '' !== $base ? $base : 'foto' ) . '.jpg';
		$file['type'] = 'image/jpeg';
		$file['size'] = (int) filesize( $file['tmp_name'] );

		return $file;
	}
}
