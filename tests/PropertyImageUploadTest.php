<?php
/**
 * Tests for the property media picker upload guard: real-type sniffing,
 * rejection of non-images/oversize files and JFIF handling.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes ) {
		return round( $bytes / 1048576, 1 ) . ' MB';
	}
}

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-property-image-upload.php';

class PropertyImageUploadTest extends TestCase {

	private $guard;
	private $tmp = array();

	protected function setUp(): void {
		$this->guard                  = ( new ReflectionClass( Arriendo_Facil_Property_Image_Upload::class ) )->newInstanceWithoutConstructor();
		$_REQUEST['action']           = 'upload-attachment';
		$_REQUEST['af_image_only']    = '1';
	}

	protected function tearDown(): void {
		unset( $_REQUEST['action'], $_REQUEST['af_image_only'] );
		foreach ( $this->tmp as $path ) {
			@unlink( $path );
		}
	}

	private function file( $bytes, $name ) {
		$path        = tempnam( sys_get_temp_dir(), 'afimg' );
		$this->tmp[] = $path;
		file_put_contents( $path, $bytes );

		return array( 'name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $path, 'size' => strlen( $bytes ) );
	}

	private function png() {
		return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' );
	}

	public function test_sniff_recognises_supported_and_rejected_types() {
		$sniff = array( Arriendo_Facil_Property_Image_Upload::class, 'sniff' );

		$this->assertSame( 'jpeg', $sniff( "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01" ) );
		$this->assertSame( 'png', $sniff( $this->png() ) );
		$this->assertSame( 'webp', $sniff( 'RIFF' . "\x00\x00\x00\x00" . 'WEBPVP8 ' ) );
		$this->assertSame( 'jxl', $sniff( "\xFF\x0A\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" ) );
		$this->assertSame( 'jxl', $sniff( "\x00\x00\x00\x0CJXL \r\n\x87\n\x00\x00\x00\x00" ) );
		$this->assertSame( 'heic', $sniff( "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00" ) );
		$this->assertSame( '', $sniff( "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00" ), 'MP4 video' );
		$this->assertSame( '', $sniff( '%PDF-1.7' . str_repeat( ' ', 8 ) ), 'PDF' );
		$this->assertSame( '', $sniff( '<?php echo 1; ?>' ), 'script' );
	}

	public function test_png_is_accepted_and_keeps_its_extension() {
		$out = $this->guard->validate_upload( $this->file( $this->png(), 'casa.PNG' ) );

		$this->assertArrayNotHasKey( 'error', $out );
		$this->assertSame( 'casa.png', $out['name'] );
		$this->assertSame( 'image/png', $out['type'] );
	}

	public function test_jfif_is_renamed_to_jpg() {
		$jpeg = imagecreatetruecolor( 4, 4 );
		ob_start();
		imagejpeg( $jpeg );
		$bytes = ob_get_clean();

		$out = $this->guard->validate_upload( $this->file( $bytes, 'foto.jfif' ) );

		$this->assertArrayNotHasKey( 'error', $out );
		$this->assertSame( 'foto.jpg', $out['name'] );
		$this->assertSame( 'image/jpeg', $out['type'] );
	}

	public function test_video_renamed_as_image_is_rejected() {
		$out = $this->guard->validate_upload( $this->file( "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00 video", 'clip.jpg' ) );

		$this->assertArrayHasKey( 'error', $out );
	}

	public function test_non_image_is_rejected_even_with_image_mime() {
		$file         = $this->file( '<?php system($_GET[0]);', 'shell.png' );
		$file['type'] = 'image/png';

		$this->assertArrayHasKey( 'error', $this->guard->validate_upload( $file ) );
	}

	public function test_oversize_file_is_rejected() {
		$file = $this->file( $this->png() . str_repeat( 'A', Arriendo_Facil_Property_Image_Upload::max_bytes() ), 'grande.png' );

		$this->assertArrayHasKey( 'error', $this->guard->validate_upload( $file ) );
	}

	public function test_jxl_is_converted_or_explained() {
		$out = $this->guard->validate_upload( $this->file( "\xFF\x0A" . str_repeat( "\x00", 32 ), 'foto.jxl' ) );

		// Without a JXL-capable Imagick (or with a bogus stream) the user gets a message, never a silent failure.
		$this->assertTrue( isset( $out['error'] ) || 'foto.jpg' === $out['name'] );
	}

	public function test_requests_outside_the_media_modal_are_untouched() {
		$_REQUEST['action'] = 'some-other-action';
		$file               = $this->file( 'not an image', 'contrato.pdf' );

		$this->assertSame( $file, $this->guard->validate_upload( $file ) );
	}
}
