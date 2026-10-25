<?php
/**
 * FictionBook 2 extractor.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove\Extractors;

use Booklove\Book_Metadata;

defined( 'ABSPATH' ) || exit;

final class Fb2 implements Extractor {

	public function supports( string $path ): bool {
		return 'fb2' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	public function extract( string $path, array $args = array() ): Book_Metadata {
		$m            = new Book_Metadata();
		$m->format    = 'fb2';
		$m->mime_type = 'application/x-fictionbook+xml';
		$m->filesize  = (int) filesize( $path );

		$xml = file_get_contents( $path );
		if ( false === $xml ) {
			throw new \RuntimeException( 'Cannot read FB2.' );
		}

		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			throw new \RuntimeException( 'Invalid FB2 XML.' );
		}

		$xpath = new \DOMXPath( $doc );
		$xpath->registerNamespace( 'fb', 'http://www.gribuser.ru/xml/fictionbook/2.0' );

		$m->title = trim( (string) $xpath->evaluate( 'string(//fb:title-info/fb:book-title|//*[local-name()="book-title"])' ) );
		$authors  = $xpath->query( '//fb:title-info/fb:author|//*[local-name()="title-info"]/*[local-name()="author"]' );
		if ( $authors ) {
			foreach ( $authors as $a ) {
				$fn = '';
				$ln = '';
				foreach ( $a->childNodes as $c ) {
					if ( ! $c instanceof \DOMElement ) {
						continue;
					}
					if ( 'first-name' === $c->localName ) {
						$fn = trim( $c->textContent ?? '' );
					}
					if ( 'last-name' === $c->localName ) {
						$ln = trim( $c->textContent ?? '' );
					}
				}
				$name = trim( $fn . ' ' . $ln );
				if ( $name ) {
					$m->authors[] = $name;
				}
			}
		}

		$m->description = trim( (string) $xpath->evaluate( 'string(//fb:title-info/fb:annotation|//*[local-name()="annotation"])' ) );
		$m->language    = trim( (string) $xpath->evaluate( 'string(//fb:title-info/fb:lang|//*[local-name()="lang"])' ) );

		if ( ! empty( $args['extract_cover'] ) ) {
			$href = trim( (string) $xpath->evaluate( 'string(//fb:coverpage//fb:image/@l:href|//*[local-name()="coverpage"]//*[local-name()="image"]/@*[local-name()="href"])' ) );
			$id   = ltrim( $href, '#' );
			if ( $id ) {
				$bins = $xpath->query( '//*[local-name()="binary" and (@id="' . $id . '" or @id=\'' . $id . '\')]' );
				if ( $bins && $bins->length ) {
					$bin = $bins->item( 0 );
					if ( $bin instanceof \DOMElement ) {
						$ctype = $bin->getAttribute( 'content-type' );
						$data  = base64_decode( preg_replace( '/\s+/', '', $bin->textContent ?? '' ) ?: '', true );
						if ( $data ) {
							$ext = str_contains( $ctype, 'png' ) ? 'png' : 'jpg';
							$m->cover_data = array( $ext, $data );
						}
					}
				}
			}
		}

		if ( ! empty( $args['extract_toc'] ) ) {
			$sections = $xpath->query( '//*[local-name()="body"]/*[local-name()="section"]/*[local-name()="title"]' );
			if ( $sections ) {
				foreach ( $sections as $t ) {
					$title = trim( preg_replace( '/\s+/u', ' ', $t->textContent ?? '' ) ?? '' );
					if ( $title ) {
						$m->toc[] = array(
							'title' => $title,
							'level' => 1,
						);
					}
				}
			}
		}

		if ( ! empty( $args['extract_text'] ) ) {
			$body = $xpath->query( '//*[local-name()="body"]' );
			if ( $body && $body->length ) {
				$text = trim( preg_replace( '/\s+/u', ' ', $body->item( 0 )->textContent ?? '' ) ?? '' );
				$max  = (int) ( $args['max_text_bytes'] ?? 512000 );
				$m->full_text   = mb_substr( $text, 0, $max );
				$m->text_sample = mb_substr( $text, 0, 2000 );
			}
		}

		$m->confidence = 0.8;
		return $m;
	}
}
