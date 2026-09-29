<?php
/**
 * Minimal, dependency-free PDF writer used to export the shareable catalog.
 *
 * The plugin cannot rely on dompdf/TCPDF/mPDF: composer dependencies are not
 * deployed alongside the plugin ZIP, and the "Imprimir / PDF" flow previously
 * fell back to the browser print dialog, which produced a flat screenshot-like
 * page instead of a real, paginated document.
 *
 * This class emits a genuine PDF 1.4 file using only the 14 standard Type 1
 * fonts plus Flate/DCT image XObjects:
 *
 *  - A4 pages with a top-left origin coordinate system (like the web).
 *  - Real, selectable vector text (Helvetica family, WinAnsiEncoding).
 *  - Accurate text measurement from the Adobe AFM width tables, so wrapping,
 *    truncation and centring behave like a browser.
 *  - Rounded rectangles, lines, fills and clipped "cover"/"contain" images.
 *  - Clickable link annotations.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Pdf
 */
class Arriendo_Facil_Pdf {

	const PAGE_W    = 595.28;
	const PAGE_H    = 841.89;
	const FONT_REG  = 'F1';
	const FONT_BOLD = 'F2';
	const FONT_ITAL = 'F3';

	/**
	 * Per-page content operators.
	 *
	 * @var string[][]
	 */
	private $pages = array();

	/**
	 * Per-page link annotations.
	 *
	 * @var array<int,array<int,array{rect:float[],url:string}>>
	 */
	private $page_links = array();

	/**
	 * Registered image XObjects: signature => object number.
	 *
	 * @var array<string,int>
	 */
	private $images = array();

	/**
	 * Number of pages that still need a footer drawn at render time.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $footers = array();

	/**
	 * Current page index.
	 *
	 * @var int
	 */
	private $current = -1;

	/**
	 * Current cursor position, in points from the top-left corner.
	 *
	 * @var float
	 */
	public $x = 0.0;

	/**
	 * @var float
	 */
	public $y = 0.0;

	/**
	 * @var float
	 */
	public $margin_left = 42.0;

	/**
	 * @var float
	 */
	public $margin_right = 42.0;

	/**
	 * @var float
	 */
	public $margin_top = 42.0;

	/**
	 * @var float
	 */
	public $margin_bottom = 48.0;

	/**
	 * @var float
	 */
	public $content_width = 0.0;

	/**
	 * @var array{0:float,1:float,2:float}
	 */
	private $fill = array( 0.0, 0.0, 0.0 );

	/**
	 * @var array{0:float,1:float,2:float}
	 */
	private $stroke = array( 0.0, 0.0, 0.0 );

	/**
	 * Sets page margins and derives the usable content width.
	 *
	 * @param float $left   Left margin (pt).
	 * @param float $right  Right margin (pt).
	 * @param float $top    Top margin (pt).
	 * @param float $bottom Bottom margin (pt).
	 * @return $this
	 */
	public function set_margins( $left, $right, $top, $bottom ) {
		$this->margin_left   = (float) $left;
		$this->margin_right  = (float) $right;
		$this->margin_top    = (float) $top;
		$this->margin_bottom = (float) $bottom;

		$this->content_width = self::PAGE_W - $this->margin_left - $this->margin_right;

		return $this;
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------- */

	/**
	 * Starts a new page and resets the cursor to the top-left content edge.
	 *
	 * @return $this
	 */
	public function add_page() {
		$this->pages[]        = array();
		$this->page_links[]   = array();
		$this->current        = count( $this->pages ) - 1;
		$this->x              = $this->margin_left;
		$this->y              = $this->margin_top;

		return $this;
	}

	/**
	 * @return int Current page index (0-based), -1 when empty.
	 */
	public function page_index() {
		return $this->current;
	}

	/**
	 * @return int
	 */
	public function page_count() {
		return count( $this->pages );
	}

	/**
	 * Registers a footer callback rendered on every page once the document is
	 * complete (page numbering needs the final page count).
	 *
	 * @param callable $callback Receives ($pdf, $page_index, $page_count).
	 * @return $this
	 */
	public function set_footer( callable $callback ) {
		$this->footers[] = $callback;

		return $this;
	}

	/**
	 * Ensures at least $height points remain, breaking to a new page if not.
	 *
	 * @param float $height Needed height (pt).
	 * @return bool True when a page break happened.
	 */
	public function ensure_space( $height ) {
		if ( $this->current < 0 ) {
			$this->add_page();

			return true;
		}

		if ( ( $this->y + (float) $height ) <= ( self::PAGE_H - $this->margin_bottom ) ) {
			return false;
		}

		$this->add_page();

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Colours
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $hex Hex colour, e.g. "#17202a" or "17202a".
	 * @return $this
	 */
	public function set_fill( $hex ) {
		$this->fill = self::hex_to_rgb( $hex );

		return $this;
	}

	/**
	 * @param string $hex Hex colour.
	 * @return $this
	 */
	public function set_stroke( $hex ) {
		$this->stroke = self::hex_to_rgb( $hex );

		return $this;
	}

	/**
	 * @param string $hex Hex colour.
	 * @return array{0:float,1:float,2:float}
	 */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return array( 0.0, 0.0, 0.0 );
		}

		return array(
			hexdec( substr( $hex, 0, 2 ) ) / 255,
			hexdec( substr( $hex, 2, 2 ) ) / 255,
			hexdec( substr( $hex, 4, 2 ) ) / 255,
		);
	}

	/* ---------------------------------------------------------------------
	 * Primitives
	 * ------------------------------------------------------------------- */

	/**
	 * Fills a rectangle. Coordinates are top-left based.
	 *
	 * @param float $x Left.
	 * @param float $y Top.
	 * @param float $w Width.
	 * @param float $h Height.
	 * @return $this
	 */
	public function rect( $x, $y, $w, $h ) {
		$this->op(
			sprintf(
				'%s %s %s rg %.2F %.2F %.2F %.2F re f',
				self::n( $this->fill[0] ),
				self::n( $this->fill[1] ),
				self::n( $this->fill[2] ),
				$x,
				self::y_pdf( $y + $h ),
				$w,
				$h
			)
		);

		return $this;
	}

	/**
	 * Fills a rounded rectangle.
	 *
	 * @param float $x      Left.
	 * @param float $y      Top.
	 * @param float $w      Width.
	 * @param float $h      Height.
	 * @param float $radius Corner radius.
	 * @return $this
	 */
	public function round_rect( $x, $y, $w, $h, $radius = 6.0 ) {
		$radius = max( 0.0, min( (float) $radius, (float) $w / 2, (float) $h / 2 ) );

		if ( $radius <= 0.01 ) {
			return $this->rect( $x, $y, $w, $h );
		}

		// Bezier control-point ratio for a quarter circle.
		$k = 0.5523 * $radius;
		$top = self::y_pdf( $y );
		$bot = self::y_pdf( $y + $h );
		$l   = $x;
		$r   = $x + $w;

		$path = sprintf( '%.2F %.2F m', $l + $radius, $top );
		$path .= sprintf( ' %.2F %.2F l', $r - $radius, $top );
		$path .= sprintf( ' %.2F %.2F %.2F %.2F %.2F %.2F c', $r - $radius + $k, $top, $r, $top + $radius - $k, $r, $top + $radius );
		$path .= sprintf( ' %.2F %.2F l', $r, $bot - $radius );
		$path .= sprintf( ' %.2F %.2F %.2F %.2F %.2F %.2F c', $r, $bot - $radius + $k, $r - $radius + $k, $bot, $r - $radius, $bot );
		$path .= sprintf( ' %.2F %.2F l', $l + $radius, $bot );
		$path .= sprintf( ' %.2F %.2F %.2F %.2F %.2F %.2F c', $l + $radius - $k, $bot, $l, $bot - $radius + $k, $l, $bot - $radius );
		$path .= sprintf( ' %.2F %.2F l', $l, $top + $radius );
		$path .= sprintf( ' %.2F %.2F %.2F %.2F %.2F %.2F c', $l, $top + $radius - $k, $l + $radius - $k, $top, $l + $radius, $top );
		$path .= 'h f';

		$this->op(
			sprintf(
				'%s %s %s rg %s',
				self::n( $this->fill[0] ),
				self::n( $this->fill[1] ),
				self::n( $this->fill[2] ),
				$path
			)
		);

		return $this;
	}

	/**
	 * Strokes a straight line.
	 *
	 * @param float $x1 Start x.
	 * @param float $y1 Start y (top-left based).
	 * @param float $x2 End x.
	 * @param float $y2 End y (top-left based).
	 * @param float $w  Line width.
	 * @return $this
	 */
	public function line( $x1, $y1, $x2, $y2, $w = 0.6 ) {
		$this->op(
			sprintf(
				'%s %s %s RG %.2F w %.2F %.2F m %.2F %.2F l S',
				self::n( $this->stroke[0] ),
				self::n( $this->stroke[1] ),
				self::n( $this->stroke[2] ),
				$w,
				$x1,
				self::y_pdf( $y1 ),
				$x2,
				self::y_pdf( $y2 )
			)
		);

		return $this;
	}

	/**
	 * Draws text at an absolute position. $y is the text *baseline* measured
	 * from the top of the page.
	 *
	 * @param string $text  Text to draw.
	 * @param float  $x     Left edge.
	 * @param float  $y     Baseline from the top.
	 * @param string $font  One of the FONT_* constants.
	 * @param float  $size  Font size (pt).
	 * @return $this
	 */
	public function text( $text, $x, $y, $font = self::FONT_REG, $size = 10.0 ) {
		$encoded = self::to_winansi( (string) $text );

		if ( '' === $encoded ) {
			return $this;
		}

		$this->op(
			sprintf(
				'BT %s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
				$font,
				$size,
				$x,
				self::y_pdf( $y ),
				self::pdf_string( $encoded )
			)
		);

		return $this;
	}

	/**
	 * Right-aligns text so it ends at $x_right.
	 *
	 * @param string $text Text.
	 * @param float  $x_right Right edge.
	 * @param float  $y      Baseline from the top.
	 * @param string $font   Font key.
	 * @param float  $size   Font size.
	 * @return $this
	 */
	public function text_right( $text, $x_right, $y, $font = self::FONT_REG, $size = 10.0 ) {
		$width = $this->text_width( $text, $font, $size );

		return $this->text( $text, $x_right - $width, $y, $font, $size );
	}

	/**
	 * Centres text horizontally inside [$x_left, $x_left + $max_width].
	 *
	 * @param string $text      Text.
	 * @param float  $x_left    Box left.
	 * @param float  $max_width Box width.
	 * @param float  $y         Baseline from the top.
	 * @param string $font      Font key.
	 * @param float  $size      Font size.
	 * @return $this
	 */
	public function text_center( $text, $x_left, $max_width, $y, $font = self::FONT_REG, $size = 10.0 ) {
		$width = $this->text_width( $text, $font, $size );

		return $this->text( $text, $x_left + ( $max_width - $width ) / 2, $y, $font, $size );
	}

	/**
	 * Adds a clickable link over a rectangle.
	 *
	 * @param float  $x   Left.
	 * @param float  $y   Top.
	 * @param float  $w   Width.
	 * @param float  $h   Height.
	 * @param string $url Target URL.
	 * @return $this
	 */
	public function link( $x, $y, $w, $h, $url ) {
		$url = esc_url_raw( (string) $url );

		if ( '' === $url || $this->current < 0 ) {
			return $this;
		}

		$this->page_links[ $this->current ][] = array(
			'rect' => array( (float) $x, self::y_pdf( $y + $h ), (float) ( $x + $w ), self::y_pdf( $y ) ),
			'url'  => $url,
		);

		return $this;
	}

	/* ---------------------------------------------------------------------
	 * Images
	 * ------------------------------------------------------------------- */

	/**
	 * Draws an image scaled to *cover* a box (cropped, like CSS object-fit),
	 * clipping the overflow.
	 *
	 * @param string $path Local file path or URL.
	 * @param float  $x    Box left.
	 * @param float  $y    Box top.
	 * @param float  $w    Box width.
	 * @param float  $h    Box height.
	 * @return $this
	 */
	public function image_cover( $path, $x, $y, $w, $h ) {
		return $this->image( $path, $x, $y, $w, $h, true );
	}

	/**
	 * Draws an image scaled to *fit inside* a box (letterboxed, centred).
	 *
	 * @param string $path Local file path or URL.
	 * @param float  $x    Box left.
	 * @param float  $y    Box top.
	 * @param float  $w    Box width.
	 * @param float  $h    Box height.
	 * @return $this
	 */
	public function image_contain( $path, $x, $y, $w, $h ) {
		return $this->image( $path, $x, $y, $w, $h, false );
	}

	/**
	 * Draws an image.
	 *
	 * @param string $path  Local path or URL.
	 * @param float  $x     Box left.
	 * @param float  $y     Box top.
	 * @param float  $w     Box width.
	 * @param float  $h     Box height.
	 * @param bool   $cover Whether to crop to fill the box.
	 * @return $this
	 */
	private function image( $path, $x, $y, $w, $h, $cover ) {
		$target = $this->prepare_image( $path );

		if ( ! $target ) {
			return $this;
		}

		$name = $this->register_image( $target );
		if ( ! $name ) {
			return $this;
		}

		$sw = (float) $target['w'];
		$sh = (float) $target['h'];

		if ( $sw <= 0 || $sh <= 0 ) {
			return $this;
		}

		$dw = (float) $w;
		$dh = (float) $h;

		$scale = $cover ? max( $dw / $sw, $dh / $sh ) : min( $dw / $sw, $dh / $sh );

		$draw_w = $sw * $scale;
		$draw_h = $sh * $scale;

		$draw_x = $x + ( $dw - $draw_w ) / 2;
		$draw_y = $y + ( $dh - $draw_h ) / 2;

		// Intersect the image transform with the box so "cover" crops cleanly.
		$clip_x = $x;
		$clip_y = $y;
		$clip_w = $dw;
		$clip_h = $dh;

		$overlap_x = max( 0.0, min( $draw_x + $draw_w, $clip_x + $clip_w ) - max( $draw_x, $clip_x ) );
		$overlap_y = max( 0.0, min( $draw_y + $draw_h, $clip_y + $clip_h ) - max( $draw_y, $clip_y ) );

		if ( $overlap_x <= 0 || $overlap_y <= 0 ) {
			return $this;
		}

		$shift_x = 0.0;
		unset( $shift_x );

		// Recompute the visible sub-rectangle in image space.
		$sx     = ( $clip_x > $draw_x ) ? ( $clip_x - $draw_x ) / $scale : 0.0;
		$sy     = ( $clip_y > $draw_y ) ? ( $clip_y - $draw_y ) / $scale : 0.0;
		$sw_vis = $overlap_x / $scale;
		$sh_vis = $overlap_y / $scale;

		unset( $sx, $sy );

		// Trim the drawn quad down to the visible part only, then clip to the
		// box so sub-pixel rounding can never bleed outside it.
		$draw_x += ( $clip_x > $draw_x ) ? ( $clip_x - $draw_x ) : 0.0;
		$draw_y += ( $clip_y > $draw_y ) ? ( $clip_y - $draw_y ) : 0.0;

		$this->op( 'q' );
		$this->op(
			sprintf(
				'%.2F %.2F %.2F %.2F re W n',
				$clip_x,
				self::y_pdf( $clip_y + $clip_h ),
				$clip_w,
				$clip_h
			)
		);
		$this->op(
			sprintf(
				'q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q',
				$sw_vis * $scale,
				$sh_vis * $scale,
				$draw_x,
				self::y_pdf( $draw_y + $sh_vis * $scale ),
				$name
			)
		);
		$this->op( 'Q' );

		return $this;
	}

	/**
	 * Resolves a path/URL to raw bytes plus dimensions, normalising anything
	 * that is not a baseline JPEG into one.
	 *
	 * @param string $path Local path or URL.
	 * @return array{data:string,w:int,h:int,filter:string}|null
	 */
	private function prepare_image( $path ) {
		$path = (string) $path;

		if ( '' === $path ) {
			return null;
		}

		$bytes = $this->read_asset( $path );

		if ( null === $bytes ) {
			return null;
		}

		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return null;
		}

		$width  = (int) $info[0];
		$height = (int) $info[1];
		$type   = isset( $info[2] ) ? (int) $info[2] : 0;

		// JPEG: embed the original bytes untouched (DCTDecode).
		if ( IMAGETYPE_JPEG === $type ) {
			return array(
				'data'   => $bytes,
				'w'      => $width,
				'h'      => $height,
				'filter' => '/DCTDecode',
				'color'  => '/DeviceRGB',
			);
		}

		// Everything else (PNG, WebP, GIF, …): re-encode to baseline JPEG.
		$reencoded = $this->to_jpeg( $bytes );

		if ( ! $reencoded ) {
			return null;
		}

		$info = @getimagesizefromstring( $reencoded ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return null;
		}

		return array(
			'data'   => $reencoded,
			'w'      => (int) $info[0],
			'h'      => (int) $info[1],
			'filter' => '/DCTDecode',
			'color'  => '/DeviceRGB',
		);
	}

	/**
	 * Converts arbitrary image bytes to a baseline JPEG using GD.
	 *
	 * @param string $bytes Source image bytes.
	 * @return string|null
	 */
	private function to_jpeg( $bytes ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}

		$image = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $image ) {
			return null;
		}

		// Flatten transparency onto white, then downscale huge images.
		$width  = imagesx( $image );
		$height = imagesy( $image );
		$max    = 1400;

		$canvas = imagecreatetruecolor( $width, $height );
		$white  = imagecolorallocate( $canvas, 255, 255, 255 );
		imagefilledrectangle( $canvas, 0, 0, $width, $height, $white );
		imagecopy( $canvas, $image, 0, 0, 0, 0, $width, $height );
		imagedestroy( $image );

		if ( $width > $max || $height > $max ) {
			$new_w = (int) round( $width * $max / max( $width, $height ) );
			$new_h = (int) round( $height * $max / max( $width, $height ) );
			$scaled = imagecreatetruecolor( $new_w, $new_h );
			imagecopyresampled( $scaled, $canvas, 0, 0, 0, 0, $new_w, $new_h, $width, $height );
			imagedestroy( $canvas );
			$canvas = $scaled;
		}

		ob_start();
		$ok = imagejpeg( $canvas, null, 82 );
		$out = ob_get_clean();
		imagedestroy( $canvas );

		return ( $ok && is_string( $out ) && '' !== $out ) ? $out : null;
	}

	/**
	 * Reads a local file or remote URL into a string.
	 *
	 * @param string $path Local path or URL.
	 * @return string|null
	 */
	private function read_asset( $path ) {
		$max_bytes = 6 * 1024 * 1024;

		if ( 0 === strpos( $path, 'http://' ) || 0 === strpos( $path, 'https://' ) ) {
			$response = wp_remote_get(
				$path,
				array(
					'timeout'    => 6,
					'redirection' => 2,
					'sslverify'  => true,
					'headers'    => array( 'Accept' => 'image/*' ),
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$body = wp_remote_retrieve_body( $response );

			return ( is_string( $body ) && '' !== $body ) ? $body : null;
		}

		// Local path (absolute or relative to uploads).
		$candidates = array( $path );

		if ( 0 !== strpos( $path, '/' ) ) {
			$candidates[] = trailingslashit( ABSPATH ) . ltrim( $path, '/' );
		}

		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$candidates[] = trailingslashit( $uploads['basedir'] ) . ltrim( $path, '/' );
		}

		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) || '' === $candidate || ! file_exists( $candidate ) || ! is_readable( $candidate ) ) {
				continue;
			}

			if ( filesize( $candidate ) > $max_bytes ) {
				continue;
			}

			$bytes = file_get_contents( $candidate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( is_string( $bytes ) && '' !== $bytes ) {
				return $bytes;
			}
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * Text measurement
	 * ------------------------------------------------------------------- */

	/**
	 * Measures a string in points.
	 *
	 * @param string $text Text.
	 * @param string $font Font key.
	 * @param float  $size Font size.
	 * @return float
	 */
	public function text_width( $text, $font = self::FONT_REG, $size = 10.0 ) {
		$encoded = self::to_winansi( (string) $text );
		$total   = 0;
		$length  = strlen( $encoded );

		for ( $i = 0; $i < $length; $i++ ) {
			$total += self::char_width( ord( $encoded[ $i ] ), $font );
		}

		return $total * ( (float) $size / 1000 );
	}

	/**
	 * Greedy word wrap.
	 *
	 * @param string $text     Text.
	 * @param string $font     Font key.
	 * @param float  $size     Font size.
	 * @param float  $max_width Available width (pt).
	 * @param int    $max_lines Stop after this many lines (0 = unlimited).
	 * @return string[]
	 */
	public function wrap( $text, $font, $size, $max_width, $max_lines = 0 ) {
		$text = self::to_winansi( (string) $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		if ( '' === $text ) {
			return array( '' );
		}

		$words    = explode( ' ', $text );
		$lines    = array();
		$current  = '';

		foreach ( $words as $word ) {
			$candidate = ( '' === $current ) ? $word : $current . ' ' . $word;

			if ( $this->text_width( $candidate, $font, $size ) <= $max_width || '' === $current ) {
				$current = $candidate;
				continue;
			}

			$lines[]  = $current;
			$current  = $word;

			if ( $max_lines > 0 && count( $lines ) >= $max_lines ) {
				break;
			}
		}

		if ( '' !== $current && ( 0 === $max_lines || count( $lines ) < $max_lines ) ) {
			$lines[] = $current;
		}

		if ( $max_lines > 0 && count( $lines ) > $max_lines ) {
			$lines = array_slice( $lines, 0, $max_lines );
		}

		return $lines ? $lines : array( '' );
	}

	/**
	 * Shortens a string with an ellipsis so it fits the given width.
	 *
	 * @param string $text      Text.
	 * @param string $font      Font key.
	 * @param float  $size      Font size.
	 * @param float  $max_width Available width (pt).
	 * @return string
	 */
	public function truncate( $text, $font, $size, $max_width ) {
		$text = self::to_winansi( trim( (string) $text ) );

		if ( '' === $text || $this->text_width( $text, $font, $size ) <= $max_width ) {
			return $text;
		}

		$out = '';
		$len = strlen( $text );

		for ( $i = 0; $i < $len; $i++ ) {
			if ( $this->text_width( $out . $text[ $i ] . '...', $font, $size ) > $max_width ) {
				break;
			}

			$out .= $text[ $i ];
		}

		return rtrim( $out ) . '...';
	}

	/**
	 * AFM advance width (per 1000 units) for a WinAnsi byte.
	 *
	 * @param int    $code Character code.
	 * @param string $font Font key.
	 * @return int
	 */
	private static function char_width( $code, $font ) {
		$tables = self::width_tables();
		$key    = ( self::FONT_BOLD === $font ) ? 'bold' : 'regular';

		if ( ! isset( $tables[ $key ][ $code ] ) ) {
			return 556;
		}

		return $tables[ $key ][ $code ];
	}

	/**
	 * Adobe AFM advance widths for Helvetica and Helvetica-Bold, WinAnsi codes
	 * 32..255. Italic reuses the regular widths, as in the real font.
	 *
	 * @return array<string,array<int,int>>
	 */
	private static function width_tables() {
		static $tables = null;

		if ( null !== $tables ) {
			return $tables;
		}

		$regular = array(
			32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
			40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
			48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
			56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
			64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
			72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
			80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
			88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
			96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
			104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556,
			112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722,
			120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
			128 => 556, 130 => 222, 131 => 556, 132 => 333, 133 => 1000, 134 => 556, 135 => 556, 136 => 333,
			137 => 1000, 138 => 667, 139 => 333, 140 => 1000, 142 => 611, 145 => 222, 146 => 222, 147 => 333,
			148 => 333, 149 => 350, 150 => 556, 151 => 333, 152 => 1000, 153 => 500, 154 => 333, 155 => 944,
			156 => 500, 157 => 500, 158 => 500, 159 => 500, 160 => 278, 161 => 333, 162 => 556, 163 => 556,
			164 => 556, 165 => 556, 166 => 260, 167 => 556, 168 => 333, 169 => 737, 170 => 370, 171 => 556,
			172 => 584, 173 => 333, 174 => 737, 175 => 333, 176 => 400, 177 => 584, 178 => 333, 179 => 333,
			180 => 333, 181 => 556, 182 => 537, 183 => 278, 184 => 333, 185 => 333, 186 => 365, 187 => 556,
			188 => 834, 189 => 834, 190 => 834, 191 => 611, 192 => 667, 193 => 667, 194 => 667, 195 => 667,
			196 => 667, 197 => 667, 198 => 1000, 199 => 722, 200 => 667, 201 => 667, 202 => 667, 203 => 667,
			204 => 278, 205 => 278, 206 => 278, 207 => 278, 208 => 722, 209 => 722, 210 => 778, 211 => 778,
			212 => 778, 213 => 778, 214 => 778, 215 => 584, 216 => 778, 217 => 722, 218 => 722, 219 => 722,
			220 => 722, 221 => 722, 222 => 667, 223 => 611, 224 => 556, 225 => 556, 226 => 556, 227 => 556,
			228 => 556, 229 => 556, 230 => 889, 231 => 500, 232 => 556, 233 => 556, 234 => 556, 235 => 556,
			236 => 222, 237 => 222, 238 => 222, 239 => 222, 240 => 556, 241 => 556, 242 => 556, 243 => 556,
			244 => 556, 245 => 556, 246 => 556, 247 => 584, 248 => 556, 249 => 556, 250 => 556, 251 => 556,
			252 => 556, 253 => 500, 254 => 500, 255 => 500,
		);

		$bold = array(
			32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238,
			40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
			48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
			56 => 556, 57 => 556, 58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584, 63 => 611,
			64 => 975, 65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
			72 => 722, 73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778,
			80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
			88 => 667, 89 => 667, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556,
			96 => 333, 97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556, 102 => 333, 103 => 611,
			104 => 611, 105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611, 111 => 611,
			112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611, 118 => 556, 119 => 778,
			120 => 556, 121 => 556, 122 => 500, 123 => 389, 124 => 280, 125 => 389, 126 => 584,
			128 => 556, 130 => 278, 131 => 556, 132 => 333, 133 => 1000, 134 => 556, 135 => 556, 136 => 333,
			137 => 1000, 138 => 667, 139 => 333, 140 => 1000, 142 => 611, 145 => 278, 146 => 278, 147 => 333,
			148 => 333, 149 => 350, 150 => 556, 151 => 333, 152 => 1000, 153 => 556, 154 => 333, 155 => 944,
			156 => 556, 157 => 556, 158 => 500, 159 => 500, 160 => 278, 161 => 333, 162 => 556, 163 => 556,
			164 => 556, 165 => 556, 166 => 280, 167 => 556, 168 => 333, 169 => 737, 170 => 370, 171 => 556,
			172 => 584, 173 => 333, 174 => 737, 175 => 333, 176 => 400, 177 => 584, 178 => 333, 179 => 333,
			180 => 333, 181 => 556, 182 => 556, 183 => 278, 184 => 333, 185 => 333, 186 => 365, 187 => 556,
			188 => 834, 189 => 834, 190 => 834, 191 => 611, 192 => 722, 193 => 722, 194 => 722, 195 => 722,
			196 => 722, 197 => 722, 198 => 1000, 199 => 722, 200 => 667, 201 => 667, 202 => 667, 203 => 667,
			204 => 278, 205 => 278, 206 => 278, 207 => 278, 208 => 722, 209 => 722, 210 => 778, 211 => 778,
			212 => 778, 213 => 778, 214 => 778, 215 => 584, 216 => 778, 217 => 722, 218 => 722, 219 => 722,
			220 => 722, 221 => 722, 222 => 667, 223 => 611, 224 => 556, 225 => 556, 226 => 556, 227 => 556,
			228 => 556, 229 => 556, 230 => 889, 231 => 556, 232 => 556, 233 => 556, 234 => 556, 235 => 556,
			236 => 278, 237 => 278, 238 => 278, 239 => 278, 240 => 611, 241 => 611, 242 => 611, 243 => 611,
			244 => 611, 245 => 611, 246 => 611, 247 => 584, 248 => 611, 249 => 611, 250 => 611, 251 => 611,
			252 => 611, 253 => 556, 254 => 556, 255 => 611,
		);

		$tables = array(
			'regular' => $regular,
			'bold'    => $bold,
		);

		return $tables;
	}

	/* ---------------------------------------------------------------------
	 * Encoding
	 * ------------------------------------------------------------------- */

	/**
	 * Converts a UTF-8 string to single-byte WinAnsi (CP1252) bytes, replacing
	 * anything the standard fonts cannot represent.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	public static function to_winansi( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return '';
		}

		// Only decode HTML entities while the string is still valid UTF-8.
		// Running the decoders over already-encoded CP1252 bytes corrupts them,
		// which makes this function safe to call more than once (the measurement
		// helpers re-encode their input on every call).
		$is_utf8 = ( '' !== $text && 1 === preg_match( '//u', $text ) );

		// Already single-byte (i.e. already encoded on a previous call): just
		// drop control characters and hand it straight back.
		if ( ! $is_utf8 ) {
			return preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text );
		}

		if ( $is_utf8 ) {
			$text = html_entity_decode( wp_specialchars_decode( $text, ENT_QUOTES ), ENT_QUOTES, 'UTF-8' );
		}

		// Replacements for symbols the 14 standard fonts do not cover. Only
		// applies to real UTF-8 input, otherwise the UTF-8 keys can never match.
		if ( $is_utf8 ) {
			$text = strtr(
				$text,
				array(
					"\xE2\x86\x92" => '->',   // →
					"\xE2\x86\x90" => '<-',   // ←
					"\xE2\x86\x91" => '^',    // ↑
					"\xE2\x86\x93" => 'v',    // ↓
					"\xE2\x9C\x93" => 'OK',   // ✓
					"\xE2\x9C\x97" => 'x',    // ✗
					"\xE2\x80\xA2" => '-',    // •
					"\xE2\x80\xA6" => '...',  // …
					"\xC2\xA0"     => ' ',    // non-breaking space
					"\xE2\x82\xAC" => 'EUR',  // €
					"\xE2\x80\x99" => '’',
				)
			);

			// Strip variation selectors, zero-width joiners, word joiner.
			$stripped = preg_replace( '/[\x{FE00}-\x{FE0F}\x{200B}-\x{200D}\x{2060}]/u', '', $text );

			if ( is_string( $stripped ) ) {
				$text = $stripped;
			}
		}

		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'CP1252//TRANSLIT', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false !== $converted && is_string( $converted ) ) {
				return $converted;
			}
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			$converted = mb_convert_encoding( $text, 'CP1252', 'UTF-8' );

			if ( is_string( $converted ) ) {
				return $converted;
			}
		}

		$converted = @iconv( 'UTF-8', 'ISO-8859-1//TRANSLIT', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $converted && is_string( $converted ) ) {
			return $converted;
		}

		return preg_replace( '/[^\x20-\x7E]/', '?', $text );
	}

	/**
	 * Escapes a byte string for use inside a PDF literal string.
	 *
	 * @param string $text Raw bytes.
	 * @return string
	 */
	private static function pdf_string( $text ) {
		$text = str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', '', ' ' ), (string) $text );

		// Escape non-printable bytes as octal.
		return preg_replace_callback(
			'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\xFF]/',
			static function ( $m ) {
				return sprintf( '\\%03o', ord( $m[0] ) );
			},
			$text
		);
	}

	/* ---------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------- */

	/**
	 * Appends a content-stream operator to the current page.
	 *
	 * @param string $operator Operator string.
	 * @return void
	 */
	private function op( $operator ) {
		if ( $this->current < 0 ) {
			$this->add_page();
		}

		$this->pages[ $this->current ][] = $operator;
	}

	/**
	 * Converts a top-left based Y coordinate to PDF's bottom-left space.
	 *
	 * @param float $y Distance from the top of the page.
	 * @return float
	 */
	private static function y_pdf( $y ) {
		return self::PAGE_H - (float) $y;
	}

	/**
	 * Formats a colour component.
	 *
	 * @param float $value 0..1.
	 * @return string
	 */
	private static function n( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' ) ?: '0';
	}

	/**
	 * Registers an image and returns its XObject resource name.
	 *
	 * @param array $data Prepared image payload.
	 * @return string
	 */
	private function register_image( array $data ) {
		$signature = md5( $data['data'] . '|' . $data['w'] . 'x' . $data['h'] );

		if ( isset( $this->images[ $signature ] ) ) {
			return $this->images[ $signature ]['name'];
		}

		// Object numbers: 1 catalog, 2 pages, 3..5 fonts, then images.
		$number = 6 + count( $this->images );
		$name   = 'Im' . $number;

		$this->images[ $signature ] = array(
			'name'   => $name,
			'number' => $number,
			'data'   => $data,
		);

		return $name;
	}

	/**
	 * Serialises the document to a PDF byte string.
	 *
	 * @return string
	 */
	public function render() {
		if ( empty( $this->pages ) ) {
			$this->add_page();
		}

		$total = count( $this->pages );

		// Footers need the final page count, so draw them last.
		foreach ( $this->footers as $footer ) {
			foreach ( range( 0, $total - 1 ) as $index ) {
				$saved          = $this->current;
				$this->current  = $index;
				call_user_func( $footer, $this, $index, $total );
				$this->current  = $saved;
			}
		}

		$objects = array();

		$fonts = array(
			self::FONT_REG  => 'Helvetica',
			self::FONT_BOLD => 'Helvetica-Bold',
			self::FONT_ITAL => 'Helvetica-Oblique',
		);

		$font_objects = array();
		$number       = 3;

		foreach ( $fonts as $key => $base ) {
			$font_objects[ $key ] = $number;
			$objects[ $number ]   = sprintf(
				"<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>",
				$base
			);
			$number++;
		}

		$image_objects = array();

		foreach ( $this->images as $signature => $image ) {
			$image_objects[ $image['name'] ] = $image['number'];
			$objects[ $image['number'] ]     = sprintf(
				"<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter %s /Length %d >>\nstream\n%s\nendstream",
				(int) $image['data']['w'],
				(int) $image['data']['h'],
				$image['data']['color'],
				$image['data']['filter'],
				strlen( $image['data']['data'] ),
				$image['data']['data']
			);
		}

		$font_res = '';
		foreach ( $font_objects as $key => $object_number ) {
			$font_res .= sprintf( '/%s %d 0 R ', $key, $object_number );
		}

		$xobject_res = '';
		foreach ( $image_objects as $name => $object_number ) {
			$xobject_res .= sprintf( '/%s %d 0 R ', $name, $object_number );
		}

		$page_numbers = array();
		$number       = 6 + count( $this->images );

		foreach ( $this->pages as $index => $operators ) {
			$content = implode( "\n", $operators );
			// Deflate when possible to keep the download small.
			$compressed = $this->deflate( $content );

			if ( null !== $compressed ) {
				$content_object = sprintf(
					"<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
					strlen( $compressed ),
					$compressed
				);
			} else {
				$content_object = sprintf( "<< /Length %d >>\nstream\n%s\nendstream", strlen( $content ), $content );
			}

			$content_number = $number;
			$objects[ $number ] = $content_object;
			$number++;

			$annots = '';
			$annot_number = $number;

			foreach ( ( isset( $this->page_links[ $index ] ) ? $this->page_links[ $index ] : array() ) as $link ) {
				$objects[ $annot_number ] = sprintf(
					"<< /Type /Annot /Subtype /Link /Rect [%.2F %.2F %.2F %.2F] /Border [0 0 0] /A << /S /URI /URI (%s) >> >>",
					$link['rect'][0],
					$link['rect'][1],
					$link['rect'][2],
					$link['rect'][3],
					self::pdf_string( self::to_winansi( $link['url'] ) )
				);
				$annots   .= sprintf( '%d 0 R ', $annot_number );
				$annot_number++;
				$number++;
			}

			$page_number = $number;
			$objects[ $number ] = sprintf(
				"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << %s>> /XObject << %s>> /ProcSet [/PDF /Text /ImageC] >> /Contents %d 0 R%s >>",
				self::PAGE_W,
				self::PAGE_H,
				$font_res,
				$xobject_res,
				$content_number,
				$annots ? ' /Annots [' . $annots . ']' : ''
			);
			$number++;

			$page_numbers[] = $page_number;
		}

		$objects[2] = sprintf(
			'<< /Type /Pages /Kids [%s] /Count %d >>',
			implode( ' ', array_map( static function ( $n ) { return $n . ' 0 R'; }, $page_numbers ) ),
			count( $page_numbers )
		);

		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

		// Document information dictionary, appended as the last object.
		$objects[ max( $page_numbers ) + 1 ] = sprintf(
			'<< /Title (%s) /Producer (Arriendo Facil) /Creator (Arriendo Facil) >>',
			self::pdf_string( self::to_winansi( get_bloginfo( 'name' ) . ' - Catalogo' ) )
		);

		ksort( $objects );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();

		foreach ( $objects as $object_number => $body ) {
			$offsets[ $object_number ] = strlen( $pdf );
			$pdf                     .= sprintf( "%d 0 obj\n%s\nendobj\n", $object_number, $body );
		}

		$max_object = max( array_keys( $objects ) );
		$info_number = max( $page_numbers ) + 1;

		$xref_offset = strlen( $pdf );
		$pdf        .= sprintf( "xref\n0 %d\n", $max_object + 1 );
		$pdf        .= "0000000000 65535 f \n";

		for ( $i = 1; $i <= $max_object; $i++ ) {
			$pdf .= isset( $offsets[ $i ] )
				? sprintf( "%010d 00000 n \n", $offsets[ $i ] )
				: "0000000000 65535 f \n";
		}

		$pdf .= sprintf(
			"trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n",
			$max_object + 1,
			$info_number,
			$xref_offset
		);

		return $pdf;
	}

	/**
	 * Gzlib-compresses a string when the extension is available.
	 *
	 * @param string $data Raw content.
	 * @return string|null
	 */
	private function deflate( $data ) {
		if ( ! function_exists( 'gzcompress' ) ) {
			return null;
		}

		$compressed = @gzcompress( $data, 6 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return ( is_string( $compressed ) && strlen( $compressed ) < strlen( $data ) ) ? $compressed : null;
	}
}
