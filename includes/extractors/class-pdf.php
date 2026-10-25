<?php
/**
 * PDF extractor — Info/XMP dictionary (Calibre pdfinfo spirit, pure PHP).
 *
 * Cover rendering & OCR are hooks: Imagick first-page like core PDF thumbs,
 * and booklove_ocr_* for scanned PDFs.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove\Extractors;

use Booklove\Book_Metadata;

defined( 'ABSPATH' ) || exit;

final class Pdf implements Extractor {

	public function supports( string $path ): bool {
		return 'pdf' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	public function extract( string $path, array $args = array() ): Book_Metadata {
		$m            = new Book_Metadata();
		$m->format    = 'pdf';
		$m->mime_type = 'application/pdf';
		$m->filesize  = (int) filesize( $path );

		$chunk_size = 512_000;
		$fh         = fopen( $path, 'rb' );
		if ( ! $fh ) {
			throw new \RuntimeException( 'Cannot open PDF.' );
		}

		try {
			$head = fread( $fh, $chunk_size ) ?: '';
			fseek( $fh, max( 0, filesize( $path ) - $chunk_size ) );
			$tail = fread( $fh, $chunk_size ) ?: '';
		} finally {
			fclose( $fh );
		}

		$blob = $head . "\n" . $tail;
		$info = $this->parse_info_dict( $blob );
		foreach ( $info as $key => $val ) {
			$val = $this->decode_pdf_string( $val );
			switch ( strtolower( $key ) ) {
				case 'title':
					$m->title = $val;
					break;
				case 'author':
					$m->authors = Book_Metadata::string_to_authors( $val );
					break;
				case 'subject':
					$m->description = $val;
					break;
				case 'keywords':
					foreach ( preg_split( '/[,;]/', $val ) ?: array() as $kw ) {
						$kw = trim( $kw );
						if ( $kw ) {
							$m->subjects[] = $kw;
						}
					}
					break;
				case 'creator':
				case 'producer':
					$m->extras[ strtolower( $key ) ] = $val;
					break;
				case 'moddate':
				case 'creationdate':
					if ( ! $m->pubdate ) {
						$m->pubdate = $this->normalize_pdf_date( $val );
					}
					break;
			}
		}

		// Page count heuristic: /Type /Page (not /Pages).
		if ( preg_match_all( '/\/Type\s*\/Page(?![s\w])/i', $blob, $pm ) ) {
			$m->page_count = count( $pm[0] );
		} elseif ( preg_match( '/\/Count\s+(\d+)/', $blob, $cm ) ) {
			$m->page_count = (int) $cm[1];
		}

		// XMP packet.
		if ( preg_match( '/<x:xmpmeta[\s\S]*?<\/x:xmpmeta>/i', $blob, $xm ) ) {
			$this->parse_xmp( $m, $xm[0] );
		}

		if ( ! empty( $args['extract_text'] ) ) {
			$text = $this->extract_literal_strings( $head, (int) ( $args['max_text_bytes'] ?? 100000 ) );
			if ( $text ) {
				$m->text_sample = mb_substr( $text, 0, 2000 );
				$m->full_text   = $text;
				$m->ocr_status  = 'skipped'; // digital text present
			} else {
				// Scanned PDF — OCR hook expected later.
				$m->ocr_status = 'pending';
			}
		}

		if ( ! empty( $args['extract_cover'] ) ) {
			$cover = $this->render_cover( $path );
			if ( $cover ) {
				$m->cover_data = $cover;
			}
		}

		// Outline / TOC from /Outlines — best effort names.
		if ( ! empty( $args['extract_toc'] ) ) {
			$m->toc = $this->parse_outline_titles( $blob );
		}

		$m->confidence = $m->title || $m->authors ? 0.55 : 0.25;
		return $m;
	}

	/**
	 * @return array<string,string>
	 */
	private function parse_info_dict( string $blob ): array {
		if ( ! preg_match( '/\/Info\s+(\d+)\s+(\d+)\s+R/', $blob, $ref ) ) {
			// Sometimes Info is inline.
			if ( preg_match( '/<<([^>]*\/Title[^>]*)>>/i', $blob, $inline ) ) {
				return $this->parse_dict_body( $inline[1] );
			}
			return array();
		}
		// Without a full xref parser we scan for a dictionary containing /Title near Info.
		if ( preg_match( '/<<((?:[^>]|>(?!>))*?\/Title(?:[^>]|>(?!>))*)>>/i', $blob, $dict ) ) {
			return $this->parse_dict_body( $dict[1] );
		}
		return array();
	}

	/**
	 * @return array<string,string>
	 */
	private function parse_dict_body( string $body ): array {
		$out = array();
		if ( preg_match_all( '/\/(Title|Author|Subject|Keywords|Creator|Producer|CreationDate|ModDate)\s*(\((?:\\\\.|[^\\\\)])*\)|<[^>]+>)/i', $body, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$out[ $m[1] ] = $m[2];
			}
		}
		return $out;
	}

	private function decode_pdf_string( string $raw ): string {
		$raw = trim( $raw );
		if ( str_starts_with( $raw, '<' ) && str_ends_with( $raw, '>' ) ) {
			$hex = preg_replace( '/[^0-9A-Fa-f]/', '', substr( $raw, 1, -1 ) ) ?? '';
			if ( '' !== $hex && 0 === strlen( $hex ) % 2 ) {
				$bin = hex2bin( $hex );
				if ( false !== $bin ) {
					if ( str_starts_with( $bin, "\xFE\xFF" ) ) {
						return trim( mb_convert_encoding( substr( $bin, 2 ), 'UTF-8', 'UTF-16BE' ) );
					}
					return trim( $bin );
				}
			}
		}
		if ( str_starts_with( $raw, '(' ) && str_ends_with( $raw, ')' ) ) {
			$raw = substr( $raw, 1, -1 );
			$raw = str_replace( array( '\\n', '\\r', '\\t', '\\(', '\\)', '\\\\' ), array( "\n", "\r", "\t", '(', ')', '\\' ), $raw );
			$raw = preg_replace_callback( '/\\\\([0-7]{1,3})/', static fn( $m ) => chr( octdec( $m[1] ) ), $raw ) ?? $raw;
		}
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'UTF-8, ISO-8859-1' );
		}
		return trim( $raw );
	}

	private function normalize_pdf_date( string $val ): string {
		if ( preg_match( '/D:(\d{4})(\d{2})?(\d{2})?/', $val, $m ) ) {
			return sprintf( '%s-%s-%s', $m[1], $m[2] ?? '01', $m[3] ?? '01' );
		}
		return $val;
	}

	private function parse_xmp( Book_Metadata $m, string $xmp ): void {
		$doc = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok = $doc->loadXML( $xmp, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return;
		}
		$xpath = new \DOMXPath( $doc );
		$xpath->registerNamespace( 'dc', 'http://purl.org/dc/elements/1.1/' );
		$title = $xpath->evaluate( 'string(//dc:title//rdf:li|//dc:title)' );
		if ( is_string( $title ) && trim( $title ) && ! $m->title ) {
			$m->title = trim( $title );
		}
		$creator = $xpath->query( '//dc:creator//*[local-name()="li"]|//dc:creator' );
		if ( $creator && ! $m->authors ) {
			foreach ( $creator as $node ) {
				$t = trim( $node->textContent ?? '' );
				if ( $t ) {
					$m->authors[] = $t;
				}
			}
		}
	}

	private function extract_literal_strings( string $blob, int $max ): string {
		$out = '';
		if ( preg_match_all( '/\(((?:\\\\.|[^\\\\)]){3,})\)\s*Tj/i', $blob, $matches ) ) {
			foreach ( $matches[1] as $s ) {
				$decoded = $this->decode_pdf_string( '(' . $s . ')' );
				if ( strlen( $decoded ) < 2 ) {
					continue;
				}
				$out .= ( $out ? ' ' : '' ) . $decoded;
				if ( strlen( $out ) >= $max ) {
					return substr( $out, 0, $max );
				}
			}
		}
		return trim( $out );
	}

	/**
	 * @return list<array{title:string,level?:int}>
	 */
	private function parse_outline_titles( string $blob ): array {
		$out = array();
		if ( preg_match_all( '/\/Title\s*(\((?:\\\\.|[^\\\\)])*\)|<[^>]+>)/i', $blob, $matches ) ) {
			$seen = array();
			foreach ( $matches[1] as $raw ) {
				$t = $this->decode_pdf_string( $raw );
				if ( '' === $t || isset( $seen[ $t ] ) ) {
					continue;
				}
				$seen[ $t ] = true;
				$out[] = array(
					'title' => $t,
					'level' => 1,
				);
				if ( count( $out ) >= 100 ) {
					break;
				}
			}
		}
		// First Title is often document title — drop if matches.
		return $out;
	}

	/**
	 * Use Imagick like core PDF thumbnails when available.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private function render_cover( string $path ): ?array {
		if ( ! class_exists( 'Imagick' ) ) {
			/**
			 * Provide cover bytes when Imagick is unavailable.
			 *
			 * @param array{0:string,1:string}|null $cover
			 * @param string                        $path
			 */
			$filtered = apply_filters( 'booklove_pdf_cover', null, $path );
			return is_array( $filtered ) ? $filtered : null;
		}
		try {
			$img = new \Imagick();
			$img->setResolution( 72, 72 );
			$img->readImage( $path . '[0]' );
			$img->setImageFormat( 'jpeg' );
			$img->setImageCompressionQuality( 85 );
			$blob = $img->getImageBlob();
			$img->clear();
			$img->destroy();
			return $blob ? array( 'jpg', $blob ) : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
