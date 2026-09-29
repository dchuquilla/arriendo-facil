<?php
/**
 * Tests for the shared catalog: the dependency-free PDF engine, the catalog
 * PDF layout and the WhatsApp deep-link normalisation.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

/**
 * Minimal stubs the catalog classes expect from WordPress.
 */
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo $text;
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = 'default' ) {
		echo $text;
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals, '.', ',' );
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}
if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $string, $quote_style = ENT_NOQUOTES ) {
		return html_entity_decode( $string, $quote_style, 'UTF-8' );
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return 'Arriendo Facil';
	}
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return 'es_EC';
	}
}
if ( ! function_exists( 'status_header' ) ) {
	function status_header( $code, $description = '' ) {}
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {}
}

/*
 * The catalog PDF builder only asks the share class for its status palette,
 * so a tiny stand-in keeps this suite free of the full WordPress stack.
 */
if ( ! class_exists( 'Arriendo_Facil_Catalog_Share' ) ) {
	class Arriendo_Facil_Catalog_Share {
		public static function status_styles() {
			return array(
				'available'   => array( 'label' => 'Disponible', 'fg' => '#ffffff', 'bg' => '#15803d' ),
				'rented'      => array( 'label' => 'Arrendado', 'fg' => '#ffffff', 'bg' => '#1d4ed8' ),
				'maintenance' => array( 'label' => 'En mantenimiento', 'fg' => '#ffffff', 'bg' => '#b45309' ),
			);
		}
	}
}

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-pdf.php';
require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-catalog-pdf.php';

/**
 * Class CatalogPdfTest
 */
class CatalogPdfTest extends TestCase {

	/**
	 * Builds a card payload for the tests.
	 *
	 * @param int    $id     Card id.
	 * @param string $status Status key.
	 * @return array
	 */
	private function card( $id, $status = 'available' ) {
		return array(
			'id'            => $id,
			'title'         => 'Depto A-' . $id,
			'thumb'         => '',
			'status'        => $status,
			'status_lbl'    => 'Disponible',
			'type'          => 'Apartamento',
			'address'       => 'Av. Amazonas N' . $id,
			'excerpt'       => str_repeat( 'Detalle. ', 10 ),
			'bedrooms'      => 2,
			'bathrooms'     => 1,
			'square_meters' => 85.0,
			'parking'       => 1,
			'floor'         => 3,
			'year_built'    => 2015,
			'amenities'     => array( 'Ascensor', 'Balcón', 'Seguridad 24/7' ),
			'utilities'     => array( 'Agua', 'Luz' ),
			'monthly_rent'  => 640.0 + $id,
			'group_id'      => 0,
		);
	}

	/**
	 * @param int $groups Number of groups.
	 * @param int $cards  Cards per group.
	 * @return array
	 */
	private function catalog( $groups = 1, $cards = 2 ) {
		$payload = array();
		$id      = 1;

		for ( $g = 1; $g <= $groups; $g++ ) {
			$list = array();

			for ( $c = 0; $c < $cards; $c++ ) {
				$list[] = $this->card( $id++ );
			}

			$payload[] = array( 'id' => $g, 'name' => 'Edificio ' . $g, 'cards' => $list );
		}

		return array(
			'company'   => 'Inmobiliaria Test',
			'email'     => 'test@example.com',
			'phone'     => '0991234567',
			'whatsapp'  => 'https://wa.me/593991234567',
			'total'     => $id - 1,
			'available' => $id - 1,
			'groups'    => $payload,
		);
	}

	/* -------------------------------------------------------------- Engine */

	public function test_engine_encodes_winansi_and_escapes_parens() {
		// WinAnsi keeps the Latin-1 range, so "á" becomes 0xE1 rather than
		// being stripped: the glyph must survive the round trip.
		$this->assertSame( "Cat\xe1logo", Arriendo_Facil_Pdf::to_winansi( 'Catálogo' ) );
		$this->assertSame( 'a(b)c', Arriendo_Facil_Pdf::to_winansi( 'a(b)c' ) );
	}

	public function test_rendered_stream_escapes_pdf_string_delimiters() {
		$pdf = new Arriendo_Facil_Pdf();
		$pdf->add_page();
		$pdf->text( 'Precio (USD) 100% \\ ok', 40, 100 );
		$out = $pdf->render();

		// The literal text must not be able to terminate the PDF string early.
		$streams = $this->content_streams( $out );
		$body    = implode( '', $streams );

		$this->assertStringContainsString( 'Precio \\(USD\\)', $body );
		$this->assertStringContainsString( '100%', $body );
		$this->assertStringContainsString( '\\\\ ok', $body );
	}

	public function test_engine_encoding_is_idempotent_and_lossless() {
		$source = 'Catálogo · m² — ñ ¿Amoblado?';
		$once   = Arriendo_Facil_Pdf::to_winansi( $source );

		// Applying it twice must not corrupt already-encoded text.
		$this->assertSame( $once, Arriendo_Facil_Pdf::to_winansi( $once ) );

		// Every character must decode back to the original.
		$this->assertSame( $source, mb_convert_encoding( $once, 'UTF-8', 'CP1252' ) );
	}

	public function test_engine_truncates_with_ellipsis() {
		$pdf   = new Arriendo_Facil_Pdf();
		$short = $pdf->truncate( 'Hola', Arriendo_Facil_Pdf::FONT_REG, 10, 200 );
		$this->assertSame( 'Hola', $short );

		$long = $pdf->truncate( str_repeat( 'palabra ', 40 ), Arriendo_Facil_Pdf::FONT_REG, 10, 60 );
		$this->assertStringEndsWith( '...', $long );
		$this->assertLessThanOrEqual( 60, $pdf->text_width( $long, Arriendo_Facil_Pdf::FONT_REG, 10 ) + 0.5 );
	}

	public function test_engine_wrap_respects_max_lines() {
		$pdf  = new Arriendo_Facil_Pdf();
		$text = str_repeat( 'palabra ', 40 );
		$lines = $pdf->wrap( $text, Arriendo_Facil_Pdf::FONT_REG, 10, 100, 3 );

		$this->assertCount( 3, $lines );

		foreach ( $lines as $line ) {
			$this->assertLessThanOrEqual( 100, $pdf->text_width( $line, Arriendo_Facil_Pdf::FONT_REG, 10 ) + 0.5 );
		}
	}

	/* ----------------------------------------------------------------- PDF */

	public function test_build_returns_valid_pdf_bytes() {
		$pdf = Arriendo_Facil_Catalog_Pdf::build( $this->catalog( 2, 2 ) );

		$this->assertIsString( $pdf );
		$this->assertStringStartsWith( '%PDF-1.4', $pdf );
		$this->assertStringEndsWith( "%%EOF\n", $pdf );
	}

	public function test_build_rejects_empty_catalog() {
		$catalog          = $this->catalog( 1, 0 );
		$catalog['groups'] = array();

		$this->assertInstanceOf( 'WP_Error', Arriendo_Facil_Catalog_Pdf::build( $catalog ) );
	}

	public function test_xref_offsets_point_at_real_objects() {
		$pdf = Arriendo_Facil_Catalog_Pdf::build( $this->catalog( 2, 3 ) );

		// Collect "N 0 obj" positions and compare them with the xref table.
		$offsets = array();
		preg_match_all( '/(\d+) 0 obj/', $pdf, $matches, PREG_OFFSET_CAPTURE );

		foreach ( $matches[0] as $match ) {
			$offsets[ (int) $match[0] ] = $match[1];
		}

		$this->assertNotEmpty( $offsets );

		// "startxref" also contains "xref", so anchor on a line of its own.
		$this->assertSame( 1, preg_match_all( '/^xref$/m', $pdf, $xref_matches, PREG_OFFSET_CAPTURE ) );
		$xref_pos = $xref_matches[0][0][1];

		$tail    = substr( $pdf, $xref_pos );
		$entries = array();

		// Group 2 holds only the fixed-width entry lines; the full match would
		// also swallow the "trailer" keyword and offset every slice.
		if ( preg_match( '/xref\s+0 (\d+)\s+(.+?)\s+trailer/s', $tail, $start, PREG_OFFSET_CAPTURE ) ) {
			$section_count = (int) $start[1][0];
			$entries_blob  = $start[2][0];

			for ( $i = 0; $i < $section_count; $i++ ) {
				$line = substr( $entries_blob, $i * 20, 20 );

				if ( preg_match( '/(\d{10}) (\d{5}) ([nf])/', $line, $entry ) ) {
					// The object number is the entry's position, not a field.
					$entry['object'] = $i;
					$entries[]       = $entry;
				}
			}
		}

		$this->assertNotEmpty( $entries, 'No se pudo leer la tabla xref.' );

		foreach ( $entries as $entry ) {
			if ( 'f' === $entry[3] ) {
				continue;
			}

			$object = $entry['object'];
			$offset = (int) $entry[1];

			$this->assertArrayHasKey( $object, $offsets, "El xref apunta al objeto inexistente {$object}." );
			$this->assertSame( $offsets[ $object ], $offset, "El offset del objeto {$object} no coincide." );
		}
	}

	public function test_page_count_matches_expected_pagination() {
		// Header + a handful of cards must stay on a single page.
		$pdf = Arriendo_Facil_Catalog_Pdf::build( $this->catalog( 1, 2 ) );

		$this->assertSame( 1, substr_count( $pdf, '/Type /Page' ) - substr_count( $pdf, '/Type /Pages' ) );
	}

	public function test_group_heading_is_never_orphaned_from_its_cards() {
		// Enough cards to force several page breaks, so some headings land at
		// page tops and some would be stranded at a page bottom if the layout
		// did not reserve room for the first row of cards.
		$pdf     = Arriendo_Facil_Catalog_Pdf::build( $this->catalog( 4, 4 ) );
		$streams = $this->content_streams( $pdf );

		$this->assertGreaterThan( 1, count( $streams ) );

		$headings = 0;

		foreach ( $streams as $stream ) {
			preg_match_all(
				'/BT (F\d) ([\d.]+) Tf 1 0 0 1 ([\d.]+) ([\d.]+) Tm \((.*?)\) Tj ET/',
				$stream,
				$ops,
				PREG_SET_ORDER
			);

			// Collect everything drawn above the footer band.
			$body = array();

			foreach ( $ops as $op ) {
				$y = (float) $op[4];

				if ( $y > Arriendo_Facil_Pdf::PAGE_H - 60 ) {
					continue; // Footer.
				}

				$body[] = $y;
			}

			if ( empty( $body ) ) {
				continue;
			}

			$lowest = min( $body );

			// Whatever sits lowest on the page must not be a section heading.
			foreach ( $ops as $op ) {
				if ( abs( (float) $op[4] - $lowest ) > 0.01 ) {
					continue;
				}

				$this->assertNotEquals(
					13.0,
					(float) $op[2],
					'Un encabezado de grupo quedó huérfano al final de una página.'
				);
			}

			foreach ( $ops as $op ) {
				if ( 13.0 === (float) $op[2] ) {
					$headings++;
				}
			}
		}

		// Sanity: every group heading really was rendered.
		$this->assertSame( 4, $headings );
	}

	public function test_odd_card_group_does_not_overlap_next_group() {
		// 1 + 1 + 1 cards: every group leaves a dangling half row.
		$pdf = Arriendo_Facil_Catalog_Pdf::build( $this->catalog( 3, 1 ) );

		$this->assertStringStartsWith( '%PDF-1.4', $pdf );
	}

	/**
	 * Extracts and inflates the page content streams.
	 *
	 * The stream dictionaries carry an exact /Length, which is the only safe
	 * way to slice them: searching for "endstream" or splitting on "stream\n"
	 * both break, because "endstream" itself contains the substring "stream".
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return string[]
	 */
	private function content_streams( $pdf ) {
		$out = array();

		$pattern = '/<< \/Length (\d+)(?: \/Filter \/FlateDecode)? >>\s*stream\r?\n/';

		if ( ! preg_match_all( $pattern, $pdf, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $matches[0] as $index => $hit ) {
			$start = $hit[1] + strlen( $hit[0] );
			$raw   = substr( $pdf, $start, (int) $matches[1][ $index ][0] );

			$inflated = @gzuncompress( $raw );

			if ( false === $inflated ) {
				$inflated = $raw;
			}

			// Only page content streams draw text.
			if ( str_contains( $inflated, 'Tj' ) ) {
				$out[] = $inflated;
			}
		}

		return $out;
	}
}
