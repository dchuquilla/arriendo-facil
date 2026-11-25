<?php
/**
 * Pruebas de la validación de evidencia del portal (por contenido real).
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/tenants/class-portal-evidence.php';

class PortalEvidenceTest extends TestCase {

	private function png( $w = 40, $h = 30 ) {
		$im = imagecreatetruecolor( $w, $h );
		ob_start();
		imagepng( $im );
		return (string) ob_get_clean();
	}

	public function test_png_is_reencoded_to_jpeg() {
		$r = Arriendo_Facil_Portal_Evidence::inspect( $this->png() );
		$this->assertIsArray( $r );
		$this->assertSame( 'image/jpeg', $r['mime'] );
		$this->assertSame( 'jpg', $r['ext'] );
		$this->assertStringStartsWith( "\xFF\xD8", $r['bytes'] );
	}

	public function test_large_image_is_scaled_down() {
		$r    = Arriendo_Facil_Portal_Evidence::inspect( $this->png( 3000, 1000 ) );
		$info = getimagesizefromstring( $r['bytes'] );
		$this->assertSame( 2000, $info[0] );
	}

	public function test_fake_extension_content_is_rejected() {
		$r = Arriendo_Facil_Portal_Evidence::inspect( "<?php echo 'x';" );
		$this->assertInstanceOf( WP_Error::class, $r );
	}

	public function test_html_and_svg_are_rejected() {
		$this->assertInstanceOf( WP_Error::class, Arriendo_Facil_Portal_Evidence::inspect( '<html><script>alert(1)</script></html>' ) );
		$this->assertInstanceOf( WP_Error::class, Arriendo_Facil_Portal_Evidence::inspect( '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>' ) );
	}

	public function test_plain_pdf_is_accepted_and_active_pdf_rejected() {
		$ok = Arriendo_Facil_Portal_Evidence::inspect( "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<<>>\n%%EOF" );
		$this->assertIsArray( $ok );
		$this->assertSame( 'pdf', $ok['ext'] );

		$bad = Arriendo_Facil_Portal_Evidence::inspect( "%PDF-1.4\n1 0 obj<</Type/Catalog/OpenAction<</S/JavaScript/JS(app.alert(1))>>>>endobj\n%%EOF" );
		$this->assertInstanceOf( WP_Error::class, $bad );
	}

	public function test_empty_and_oversized_are_rejected() {
		$this->assertInstanceOf( WP_Error::class, Arriendo_Facil_Portal_Evidence::inspect( '' ) );
		$this->assertInstanceOf( WP_Error::class, Arriendo_Facil_Portal_Evidence::inspect( str_repeat( 'a', Arriendo_Facil_Portal_Evidence::MAX_BYTES + 1 ) ) );
	}

	public function test_jpeg_metadata_is_stripped() {
		$im = imagecreatetruecolor( 20, 20 );
		ob_start();
		imagejpeg( $im );
		$jpeg = (string) ob_get_clean();
		// Inserta un segmento APP1 (EXIF) falso tras el SOI.
		$exif   = "Exif\0\0GPS-SECRET";
		$marked = substr( $jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . substr( $jpeg, 2 );
		$r      = Arriendo_Facil_Portal_Evidence::inspect( $marked );
		$this->assertStringNotContainsString( 'GPS-SECRET', $r['bytes'] );
	}
}
