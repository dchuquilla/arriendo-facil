<?php
/**
 * Plain-text extraction from contract templates (DOCX, PDF, DOC, text).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Contract_Text_Extractor
 *
 * Stateless, dependency-free text extraction used to feed contract templates
 * to the AI prompt builders.
 */
class Arriendo_Facil_Contract_Text_Extractor {

	const MAX_TEMPLATE_CHARS = 8000;

	const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	/**
	 * Extracts plain text from a contract template file when possible.
	 *
	 * @param string $file_path Template file path.
	 * @param string $mime_type Mime type.
	 * @return string Text truncated to MAX_TEMPLATE_CHARS.
	 */
	public static function extract( $file_path, $mime_type ) {
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return '';
		}

		$mime_type = strtolower( (string) $mime_type );
		$extension = strtolower( (string) pathinfo( (string) $file_path, PATHINFO_EXTENSION ) );

		if ( false !== strpos( $mime_type, 'text/' ) ) {
			$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return false !== $content ? self::limit( wp_strip_all_tags( (string) $content ) ) : '';
		}

		if ( self::DOCX_MIME === $mime_type || 'docx' === $extension ) {
			return self::limit( self::extract_docx( $file_path ) );
		}

		if ( false !== strpos( $mime_type, 'application/pdf' ) || 'pdf' === $extension ) {
			return self::limit( self::extract_pdf( $file_path ) );
		}

		if ( false !== strpos( $mime_type, 'application/msword' ) || 'doc' === $extension ) {
			return self::limit( self::extract_printable( $file_path ) );
		}

		return self::limit( self::extract_printable( $file_path ) );
	}

	/**
	 * Truncates template text before sending it to AI.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function limit( $text ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );

		return strlen( $text ) > self::MAX_TEMPLATE_CHARS ? substr( $text, 0, self::MAX_TEMPLATE_CHARS ) : $text;
	}

	/**
	 * @param string $file_path DOCX path.
	 * @return string
	 */
	private static function extract_docx( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return '';
		}

		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		return ( false !== $xml && '' !== $xml ) ? (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $xml ) ) : '';
	}

	/**
	 * Best-effort PDF text extraction via stream parsing (no external libs).
	 *
	 * @param string $file_path PDF path.
	 * @return string
	 */
	private static function extract_pdf( $file_path ) {
		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content || '' === $content ) {
			return '';
		}

		$buffers = array( (string) $content );
		if ( preg_match_all( '/stream(.*?)endstream/s', (string) $content, $stream_matches ) ) {
			foreach ( $stream_matches[1] as $stream ) {
				$stream  = rtrim( ltrim( (string) $stream, "\r\n" ), "\r\n" );
				$decoded = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $decoded ) {
					$decoded = @gzinflate( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}

				$buffers[] = ( false !== $decoded && '' !== $decoded ) ? (string) $decoded : $stream;
			}
		}

		$text_chunks = array();
		foreach ( $buffers as $buffer ) {
			$tokens = array();
			if ( preg_match_all( '/\((.*?)\)\s*Tj/s', (string) $buffer, $matches ) ) {
				$tokens = $matches[1];
			}

			if ( preg_match_all( '/\[(.*?)\]\s*TJ/s', (string) $buffer, $matches ) ) {
				foreach ( $matches[1] as $array_body ) {
					if ( preg_match_all( '/\((.*?)\)/s', (string) $array_body, $token_matches ) ) {
						$tokens = array_merge( $tokens, $token_matches[1] );
					}
				}
			}

			foreach ( $tokens as $token ) {
				$decoded_token = self::decode_pdf_token( (string) $token );
				if ( '' !== $decoded_token ) {
					$text_chunks[] = $decoded_token;
				}
			}
		}

		return empty( $text_chunks ) ? '' : trim( (string) preg_replace( '/\s+/', ' ', implode( ' ', $text_chunks ) ) );
	}

	/**
	 * Decodes escaped PDF text token content.
	 *
	 * @param string $token Token text.
	 * @return string
	 */
	private static function decode_pdf_token( $token ) {
		$token = preg_replace_callback(
			'/\\\\([0-7]{1,3})/',
			static function ( $matches ) {
				return chr( octdec( $matches[1] ) );
			},
			(string) $token
		);

		$token = strtr(
			(string) $token,
			array(
				'\\n'  => "\n",
				'\\r'  => "\r",
				'\\t'  => "\t",
				'\\b'  => '',
				'\\f'  => '',
				'\\('  => '(',
				'\\)'  => ')',
				'\\\\' => '\\',
			)
		);

		$token = wp_strip_all_tags( $token );

		return trim( (string) preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', (string) $token ) );
	}

	/**
	 * Keeps printable ASCII from a binary file (legacy .doc and unknown types).
	 *
	 * @param string $file_path File path.
	 * @return string
	 */
	private static function extract_printable( $file_path ) {
		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content || '' === $content ) {
			return '';
		}

		return (string) preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', (string) $content );
	}
}
