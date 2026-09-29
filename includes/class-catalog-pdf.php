<?php
/**
 * Renders the public catalog as a paginated, print-ready PDF.
 *
 * The document is built on top of {@see Arriendo_Facil_Pdf}, a dependency-free
 * PDF 1.4 writer, so the shared link can offer a real download without pulling
 * in Dompdf/mPDF/TCPDF. The layout mirrors the public web catalog: the same
 * groups, the same cards and the same data, so a landlord can hand a prospect
 * either one and always show the same properties.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Catalog_Pdf
 */
class Arriendo_Facil_Catalog_Pdf {

	/* Palette, kept in sync with the public stylesheet. */
	const INK        = '#0f172a';
	const MUTED      = '#64748b';
	const LINE       = '#e2e8f0';
	const CARD_BG    = '#ffffff';
	const PAGE_BG    = '#f8fafc';
	const BRAND      = '#1d4ed8';
	const STATUS_BG  = '#f1f5f9';

	/**
	 * Card metrics (pt).
	 */
	const GAP        = 14.0;
	const CARD_H     = 236.0;
	const IMAGE_H    = 108.0;
	const PAD        = 11.0;

	/**
	 * Vertical space a group heading occupies (padding + rule).
	 */
	const HEADING_H  = 30.0;

	/**
	 * Builds the full catalog PDF.
	 *
	 * @param array $catalog Output of Arriendo_Facil_Catalog_Share::get_catalog().
	 * @return string|WP_Error Raw PDF bytes.
	 *
	 * The share token is deliberately never printed: a PDF gets forwarded,
	 * and the link must stay revocable independently of the document.
	 */
	public static function build( array $catalog ) {
		if ( empty( $catalog['groups'] ) ) {
			return new WP_Error( 'af_catalog_empty', __( 'No hay propiedades para generar el catálogo.', 'arriendo-facil' ) );
		}

		$pdf = new Arriendo_Facil_Pdf();
		$pdf->set_margins( 40, 40, 44, 46 );

		$content_w = Arriendo_Facil_Pdf::PAGE_W - 80;
		$card_w    = ( $content_w - self::GAP ) / 2;

		$pdf->set_footer(
			static function ( $f, $page_index, $page_count ) use ( $catalog ) {
				self::draw_footer( $f, $page_index, $page_count, $catalog );
			}
		);

		self::draw_header( $pdf, $catalog, $content_w );

		foreach ( $catalog['groups'] as $group ) {
			self::draw_group( $pdf, $group, $card_w );
		}

		return $pdf->render();
	}

	/* ---------------------------------------------------------------------
	 * Header / footer
	 * ------------------------------------------------------------------- */

	/**
	 * Draws the document masthead: brand line, company and contact strip.
	 *
	 * @param Arriendo_Facil_Pdf $pdf        Engine.
	 * @param array              $catalog    Catalog payload.
	 * @param float              $content_w  Usable width.
	 */
	private static function draw_header( Arriendo_Facil_Pdf $pdf, array $catalog, $content_w ) {
		$pdf->add_page();
		$pdf->y = 44.0;

		// Accent bar.
		$pdf->set_fill( self::BRAND );
		$pdf->rect( 40, 44, $content_w, 3 );
		$pdf->y += 16;

		$pdf->set_fill( self::INK );
		$pdf->text( __( 'Catálogo de inmuebles', 'arriendo-facil' ), 40, $pdf->y, Arriendo_Facil_Pdf::FONT_BOLD, 19 );
		$pdf->y += 24;

		$pdf->set_fill( self::MUTED );
		$pdf->text(
			sprintf(
				/* translators: 1: number of properties, 2: number available */
				__( '%1$d inmueble(s) · %2$d disponible(s)', 'arriendo-facil' ),
				(int) $catalog['total'],
				(int) $catalog['available']
			),
			40,
			$pdf->y,
			Arriendo_Facil_Pdf::FONT_REG,
			10
		);
		$pdf->y += 22;

		// Company + contact strip.
		$strip_h = 52.0;
		$pdf->set_fill( self::PAGE_BG );
		$pdf->round_rect( 40, $pdf->y, $content_w, $strip_h, 8 );
		$pdf->y += 18;

		$pdf->set_fill( self::INK );
		$company = $pdf->truncate( (string) $catalog['company'], Arriendo_Facil_Pdf::FONT_BOLD, 15, $content_w - 24 );
		$pdf->text( $company, 52, $pdf->y, Arriendo_Facil_Pdf::FONT_BOLD, 15 );
		$pdf->y += 16;

		$pdf->set_fill( self::MUTED );
		$contact_bits = array_filter(
			array(
				! empty( $catalog['phone'] ) ? $catalog['phone'] : '',
				! empty( $catalog['email'] ) ? $catalog['email'] : '',
			)
		);

		$contact = $contact_bits ? implode( '   ·   ', $contact_bits ) : __( 'Contáctanos para más información.', 'arriendo-facil' );
		$contact = $pdf->truncate( $contact, Arriendo_Facil_Pdf::FONT_REG, 9.5, $content_w - 24 );
		$pdf->text( $contact, 52, $pdf->y, Arriendo_Facil_Pdf::FONT_REG, 9.5 );

		// Keep the WhatsApp CTA clickable when present.
		if ( ! empty( $catalog['whatsapp'] ) ) {
			$label = __( 'Escribir por WhatsApp', 'arriendo-facil' );
			$pdf->set_fill( self::BRAND );
			$pdf->text( $label, 52, $pdf->y + 13, Arriendo_Facil_Pdf::FONT_BOLD, 9.5 );
			$pdf->link( 52, $pdf->y + 5, $pdf->text_width( $label, Arriendo_Facil_Pdf::FONT_BOLD, 9.5 ), 14, $catalog['whatsapp'] );
		}

		$pdf->y += 26;
	}

	/**
	 * Draws the running footer once the total page count is known.
	 *
	 * @param Arriendo_Facil_Pdf $pdf        Engine.
	 * @param int                $page_index 0-based page index.
	 * @param int                $page_count Total pages.
	 * @param array              $catalog    Catalog payload.
	 */
	private static function draw_footer( Arriendo_Facil_Pdf $pdf, $page_index, $page_count, array $catalog ) {
		$y = Arriendo_Facil_Pdf::PAGE_H - 34;

		$pdf->set_fill( self::LINE );
		$pdf->line( 40, $y - 8, Arriendo_Facil_Pdf::PAGE_W - 40, $y - 8, 0.6 );

		$pdf->set_fill( self::MUTED );
		$left = $catalog['company'] . ' — ' . __( 'Catálogo', 'arriendo-facil' );
		$pdf->text( $pdf->truncate( $left, Arriendo_Facil_Pdf::FONT_REG, 8, 300 ), 40, $y, Arriendo_Facil_Pdf::FONT_REG, 8 );

		$page_label = sprintf(
			/* translators: 1: current page, 2: total pages */
			__( 'Página %1$d de %2$d', 'arriendo-facil' ),
			$page_index + 1,
			$page_count
		);
		$pdf->text_right( $page_label, Arriendo_Facil_Pdf::PAGE_W - 40, $y, Arriendo_Facil_Pdf::FONT_REG, 8 );
	}

	/* ---------------------------------------------------------------------
	 * Groups and cards
	 * ------------------------------------------------------------------- */

	/**
	 * Draws a group section and its cards, paginating as needed.
	 *
	 * @param Arriendo_Facil_Pdf $pdf    Engine.
	 * @param array              $group  Group payload.
	 * @param float              $card_w Card width.
	 */
	private static function draw_group( Arriendo_Facil_Pdf $pdf, array $group, $card_w ) {
		$count = count( $group['cards'] );

		// Never strand a heading at the foot of a page: reserve the heading plus
		// the first row of cards so they travel to the next page together.
		$pdf->ensure_space( $count ? self::HEADING_H + self::CARD_H + self::GAP : self::HEADING_H );

		$pdf->y += 4;
		$pdf->set_fill( self::INK );
		$name = $pdf->truncate( (string) $group['name'], Arriendo_Facil_Pdf::FONT_BOLD, 13, 420 );
		$pdf->text( $name, 40, $pdf->y, Arriendo_Facil_Pdf::FONT_BOLD, 13 );

		$badge = sprintf(
			/* translators: %d: number of properties */
			_n( '%d inmueble', '%d inmuebles', $count, 'arriendo-facil' ),
			$count
		);
		$pdf->set_fill( self::MUTED );
		$pdf->text_right( $badge, Arriendo_Facil_Pdf::PAGE_W - 40, $pdf->y, Arriendo_Facil_Pdf::FONT_REG, 9 );

		$pdf->y += 8;
		$pdf->set_fill( self::LINE );
		$pdf->line( 40, $pdf->y, Arriendo_Facil_Pdf::PAGE_W - 40, $pdf->y, 0.8 );
		$pdf->y += 14;

		$row_top = 0.0;
		$col     = 0;

		foreach ( $group['cards'] as $card ) {
			// A new row always starts a fresh page check, and each group
			// restarts at the left column so an odd card count in one group
			// cannot shift the next group's layout.
			if ( 0 === $col ) {
				$pdf->ensure_space( self::CARD_H + self::GAP );
				$row_top = $pdf->y;
			}

			$card_x = 40 + $col * ( $card_w + self::GAP );

			self::draw_card( $pdf, $card, $card_x, $row_top, $card_w );

			$col = 1 - $col;

			if ( 0 === $col ) {
				$pdf->y = $row_top + self::CARD_H + self::GAP;
			}
		}

		// Close a dangling half row.
		if ( 1 === $col ) {
			$pdf->y = $row_top + self::CARD_H + self::GAP;
		}
	}

	/**
	 * Draws one property card inside a fixed-height box.
	 *
	 * @param Arriendo_Facil_Pdf $pdf    Engine.
	 * @param array              $card   Card payload.
	 * @param float              $x      Card left.
	 * @param float              $y      Card top.
	 * @param float              $w      Card width.
	 */
	private static function draw_card( Arriendo_Facil_Pdf $pdf, array $card, $x, $y, $w ) {
		$h = self::CARD_H;

		// Card shell.
		$pdf->set_fill( self::CARD_BG );
		$pdf->set_stroke( self::LINE );
		$pdf->round_rect( $x, $y, $w, $h, 7 );

		// Photo, with a neutral placeholder when the property has no image.
		if ( ! empty( $card['thumb'] ) ) {
			$pdf->image_cover( $card['thumb'], $x, $y, $w, self::IMAGE_H );
		} else {
			$pdf->set_fill( self::PAGE_BG );
			$pdf->rect( $x, $y, $w, self::IMAGE_H );
			$pdf->set_fill( self::MUTED );
			$pdf->text_center( __( 'Sin fotografía', 'arriendo-facil' ), $x, $w, $y + self::IMAGE_H / 2 - 4, Arriendo_Facil_Pdf::FONT_ITAL, 9 );
		}

		// Status pill, bottom-right of the photo.
		$styles = Arriendo_Facil_Catalog_Share::status_styles();
		$style  = isset( $styles[ $card['status'] ] ) ? $styles[ $card['status'] ] : null;

		if ( $style ) {
			$label = $style['label'];
			$tw    = $pdf->text_width( $label, Arriendo_Facil_Pdf::FONT_BOLD, 8 ) + 14;
			$px    = $x + $w - $tw - 8;
			$py    = $y + self::IMAGE_H - 18;

			$pdf->set_fill( $style['bg'] );
			$pdf->round_rect( $px, $py, $tw, 14, 7 );
			$pdf->set_fill( $style['fg'] );
			$pdf->text_center( $label, $px, $tw, $py + 3.5, Arriendo_Facil_Pdf::FONT_BOLD, 8 );
		}

		// Body.
		$ty    = $y + self::IMAGE_H + 16;
		$inner = $w - ( self::PAD * 2 );

		$pdf->set_fill( self::INK );
		$pdf->text( $pdf->truncate( $card['title'], Arriendo_Facil_Pdf::FONT_BOLD, 10.5, $inner ), $x + self::PAD, $ty, Arriendo_Facil_Pdf::FONT_BOLD, 10.5 );
		$ty += 14;

		$bits = array();
		if ( ! empty( $card['type'] ) ) {
			$bits[] = $card['type'];
		}
		if ( $card['bedrooms'] > 0 ) {
			$bits[] = sprintf(
				/* translators: %d: bedrooms */
				_n( '%d dorm.', '%d dorms.', $card['bedrooms'], 'arriendo-facil' ),
				$card['bedrooms']
			);
		}
		if ( $card['bathrooms'] > 0 ) {
			$bits[] = sprintf(
				/* translators: %d: bathrooms */
				_n( '%d baño', '%d baños', $card['bathrooms'], 'arriendo-facil' ),
				$card['bathrooms']
			);
		}
		if ( $card['square_meters'] > 0 ) {
			$bits[] = rtrim( rtrim( number_format( $card['square_meters'], 0, ',', '.' ), '0' ), ',' ) . ' m²';
		}

		$pdf->set_fill( self::MUTED );
		$line = $bits ? implode( '  ·  ', $bits ) : __( 'Consultar detalles', 'arriendo-facil' );
		$pdf->text( $pdf->truncate( $line, Arriendo_Facil_Pdf::FONT_REG, 8.5, $inner ), $x + self::PAD, $ty, Arriendo_Facil_Pdf::FONT_REG, 8.5 );
		$ty += 12;

		if ( ! empty( $card['address'] ) ) {
			$pdf->set_fill( self::MUTED );
			$pdf->text( $pdf->truncate( $card['address'], Arriendo_Facil_Pdf::FONT_REG, 8.5, $inner ), $x + self::PAD, $ty, Arriendo_Facil_Pdf::FONT_REG, 8.5 );
		}

		// Price, bottom-left.
		$py = $y + $h - 16;
		$pdf->set_fill( self::INK );
		$price = self::format_price( $card['monthly_rent'] );
		$pdf->text( $pdf->truncate( $price, Arriendo_Facil_Pdf::FONT_BOLD, 11.5, $inner - 60 ), $x + self::PAD, $py, Arriendo_Facil_Pdf::FONT_BOLD, 11.5 );

		// Amenity hint, bottom-right when there is room.
		if ( ! empty( $card['amenities'] ) ) {
			$hint = implode( ' · ', array_slice( $card['amenities'], 0, 3 ) );
			$pdf->set_fill( self::MUTED );
			$pdf->text_right( $pdf->truncate( $hint, Arriendo_Facil_Pdf::FONT_REG, 8, $inner / 2 ), $x + $w - self::PAD, $py + 3, Arriendo_Facil_Pdf::FONT_REG, 8 );
		}
	}

	/**
	 * Formats a monthly rent for display.
	 *
	 * @param float $amount Amount in USD.
	 * @return string
	 */
	private static function format_price( $amount ) {
		if ( $amount <= 0 ) {
			return __( 'Precio a consultar', 'arriendo-facil' );
		}

		return '$ ' . number_format_i18n( $amount, 2 ) . ' / mes';
	}
}
