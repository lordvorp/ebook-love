<?php
/**
 * EPUB / KEPUB extractor — Calibre OCF → OPF → NCX/nav + cover.
 *
 * @package Booklove
 * @see https://github.com/kovidgoyal/calibre/blob/master/src/calibre/ebooks/metadata/epub.py
 */

declare(strict_types=1);

namespace Booklove\Extractors;

use Booklove\Book_Metadata;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

final class Epub implements Extractor {

	public function supports( string $path ): bool {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'epub', 'kepub' ), true ) && class_exists( ZipArchive::class );
	}

	public function extract( string $path, array $args = array() ): Book_Metadata {
		$m           = new Book_Metadata();
		$m->format   = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$m->mime_type = 'application/epub+zip';
		$m->filesize = (int) filesize( $path );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			throw new \RuntimeException( 'Not a valid EPUB ZIP container.' );
		}

		try {
			$opf_path = $this->opf_path( $zip );
			if ( ! $opf_path ) {
				throw new \RuntimeException( 'Missing OPF package in container.xml.' );
			}

			$opf_xml = $zip->getFromName( $opf_path );
			if ( false === $opf_xml ) {
				throw new \RuntimeException( 'Could not read OPF.' );
			}

			$this->parse_opf( $m, $opf_xml, $opf_path, $zip, $args );

			if ( ! empty( $args['extract_toc'] ) ) {
				$this->parse_toc( $m, $opf_xml, $opf_path, $zip );
			}

			if ( ! empty( $args['extract_text'] ) ) {
				$this->sample_text( $m, $opf_xml, $opf_path, $zip, (int) ( $args['max_text_bytes'] ?? 512000 ) );
			}

			$m->confidence = max( $m->confidence, 0.85 );
		} finally {
			$zip->close();
		}

		return $m;
	}

	private function opf_path( ZipArchive $zip ): ?string {
		$container = $zip->getFromName( 'META-INF/container.xml' );
		if ( false === $container ) {
			// Broken epubs sometimes put OPF at root — last resort scan.
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( is_string( $name ) && str_ends_with( strtolower( $name ), '.opf' ) ) {
					return $name;
				}
			}
			return null;
		}

		$doc = $this->load_xml( $container );
		if ( ! $doc ) {
			return null;
		}

		$nodes = $doc->getElementsByTagName( 'rootfile' );
		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$mt = $node->getAttribute( 'media-type' );
			$fp = $node->getAttribute( 'full-path' );
			if ( $fp && ( ! $mt || str_contains( $mt, 'package+xml' ) || str_contains( $mt, 'oebps' ) ) ) {
				return $this->normalize_zip_path( $fp );
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function parse_opf( Book_Metadata $m, string $opf_xml, string $opf_path, ZipArchive $zip, array $args ): void {
		$doc = $this->load_xml( $opf_xml );
		if ( ! $doc ) {
			return;
		}

		$xpath = new \DOMXPath( $doc );
		$xpath->registerNamespace( 'dc', 'http://purl.org/dc/elements/1.1/' );
		$xpath->registerNamespace( 'opf', 'http://www.idpf.org/2007/opf' );

		$m->title = $this->xpath_text( $xpath, '//dc:title' ) ?: $this->xpath_text( $xpath, '//*[local-name()="title"]' );
		$creators = $xpath->query( '//dc:creator|//*[local-name()="creator"]' );
		if ( $creators ) {
			foreach ( $creators as $c ) {
				$t = trim( $c->textContent ?? '' );
				if ( '' !== $t ) {
					$m->authors[] = $t;
				}
			}
		}
		$m->description = $this->xpath_text( $xpath, '//dc:description|//*[local-name()="description"]' );
		$m->publisher   = $this->xpath_text( $xpath, '//dc:publisher|//*[local-name()="publisher"]' );
		$m->language    = $this->xpath_text( $xpath, '//dc:language|//*[local-name()="language"]' );
		$m->rights      = $this->xpath_text( $xpath, '//dc:rights|//*[local-name()="rights"]' );

		$subjects = $xpath->query( '//dc:subject|//*[local-name()="subject"]' );
		if ( $subjects ) {
			foreach ( $subjects as $s ) {
				$t = trim( $s->textContent ?? '' );
				if ( '' !== $t ) {
					$m->subjects[] = $t;
				}
			}
		}

		$ids = $xpath->query( '//dc:identifier|//*[local-name()="identifier"]' );
		if ( $ids ) {
			foreach ( $ids as $id ) {
				if ( ! $id instanceof \DOMElement ) {
					continue;
				}
				$val = trim( $id->textContent ?? '' );
				$scheme = strtolower( $id->getAttribute( 'opf:scheme' ) ?: $id->getAttribute( 'scheme' ) );
				if ( '' === $val ) {
					continue;
				}
				if ( ! $m->identifier ) {
					$m->identifier = $val;
				}
				if ( str_contains( strtolower( $val ), 'isbn' ) || 'isbn' === $scheme ) {
					$m->isbn = preg_replace( '/[^0-9Xx]/', '', $val ) ?: $val;
				}
				if ( 'doi' === $scheme || str_starts_with( strtolower( $val ), 'doi:' ) || str_contains( $val, 'doi.org' ) ) {
					$m->doi = $val;
				}
			}
		}

		$date = $this->xpath_text( $xpath, '//dc:date|//*[local-name()="date"]' );
		if ( $date ) {
			$m->pubdate = $date;
		}

		// Calibre series meta.
		$metas = $xpath->query( '//*[local-name()="meta"]' );
		if ( $metas ) {
			foreach ( $metas as $meta ) {
				if ( ! $meta instanceof \DOMElement ) {
					continue;
				}
				$name = $meta->getAttribute( 'name' ) ?: $meta->getAttribute( 'property' );
				$content = $meta->getAttribute( 'content' ) ?: trim( $meta->textContent ?? '' );
				if ( 'calibre:series' === $name || 'belongs-to-collection' === $name ) {
					$m->series = $content;
				}
				if ( 'calibre:series_index' === $name || 'group-position' === $name ) {
					$m->series_index = (float) $content;
				}
			}
		}

		if ( ! empty( $args['extract_cover'] ) ) {
			$cover_href = $this->find_cover_href( $xpath );
			if ( $cover_href ) {
				$full = $this->join_path( dirname( $opf_path ), $cover_href );
				$bytes = $zip->getFromName( $full );
				if ( false === $bytes ) {
					$bytes = $zip->getFromName( ltrim( $cover_href, '/' ) );
				}
				if ( false !== $bytes && strlen( $bytes ) > 32 ) {
					$ext = strtolower( pathinfo( $cover_href, PATHINFO_EXTENSION ) ) ?: 'jpg';
					if ( 'jpeg' === $ext ) {
						$ext = 'jpg';
					}
					$m->cover_data = array( $ext, $bytes );
				}
			}
		}
	}

	private function find_cover_href( \DOMXPath $xpath ): ?string {
		// EPUB3: properties="cover-image"
		$items = $xpath->query( '//*[local-name()="item"]' );
		$manifest = array();
		if ( $items ) {
			foreach ( $items as $item ) {
				if ( ! $item instanceof \DOMElement ) {
					continue;
				}
				$id = $item->getAttribute( 'id' );
				$href = $item->getAttribute( 'href' );
				$props = $item->getAttribute( 'properties' );
				$manifest[ $id ] = $href;
				if ( $href && str_contains( $props, 'cover-image' ) ) {
					return $href;
				}
			}
		}

		// EPUB2: meta name="cover" content="id"
		$metas = $xpath->query( '//*[local-name()="meta" and (@name="cover" or @name="Cover")]' );
		if ( $metas ) {
			foreach ( $metas as $meta ) {
				if ( ! $meta instanceof \DOMElement ) {
					continue;
				}
				$ref = $meta->getAttribute( 'content' );
				if ( $ref && isset( $manifest[ $ref ] ) ) {
					return $manifest[ $ref ];
				}
			}
		}

		// Guide reference type=cover.
		$refs = $xpath->query( '//*[local-name()="reference" and (@type="cover" or @type="other.ms-coverimage-standard")]' );
		if ( $refs && $refs->length ) {
			$el = $refs->item( 0 );
			if ( $el instanceof \DOMElement ) {
				return $el->getAttribute( 'href' ) ?: null;
			}
		}

		return null;
	}

	private function parse_toc( Book_Metadata $m, string $opf_xml, string $opf_path, ZipArchive $zip ): void {
		$doc = $this->load_xml( $opf_xml );
		if ( ! $doc ) {
			return;
		}
		$xpath = new \DOMXPath( $doc );

		// Prefer EPUB3 nav document.
		$items = $xpath->query( '//*[local-name()="item"]' );
		$nav_href = null;
		$ncx_href = null;
		if ( $items ) {
			foreach ( $items as $item ) {
				if ( ! $item instanceof \DOMElement ) {
					continue;
				}
				$props = $item->getAttribute( 'properties' );
				$mt    = $item->getAttribute( 'media-type' );
				$href  = $item->getAttribute( 'href' );
				if ( str_contains( $props, 'nav' ) ) {
					$nav_href = $href;
				}
				if ( str_contains( $mt, 'ncx' ) || str_ends_with( strtolower( $href ), '.ncx' ) ) {
					$ncx_href = $href;
				}
			}
		}

		if ( $nav_href ) {
			$full = $this->join_path( dirname( $opf_path ), $nav_href );
			$xml  = $zip->getFromName( $full );
			if ( false !== $xml ) {
				$m->toc = $this->parse_nav_toc( $xml );
				if ( $m->toc ) {
					return;
				}
			}
		}

		if ( $ncx_href ) {
			$full = $this->join_path( dirname( $opf_path ), $ncx_href );
			$xml  = $zip->getFromName( $full );
			if ( false !== $xml ) {
				$m->toc = $this->parse_ncx_toc( $xml );
			}
		}
	}

	/**
	 * @return list<array{title:string,href?:string,level?:int,children?:array}>
	 */
	private function parse_ncx_toc( string $xml ): array {
		$doc = $this->load_xml( $xml );
		if ( ! $doc ) {
			return array();
		}
		$nav = $doc->getElementsByTagName( 'navPoint' );
		// Only top-level: parent is navMap or similar, not another navPoint.
		$out = array();
		foreach ( $doc->getElementsByTagName( 'navMap' ) as $map ) {
			foreach ( $map->childNodes as $child ) {
				if ( $child instanceof \DOMElement && 'navPoint' === $child->localName ) {
					$out[] = $this->ncx_point( $child, 1 );
				}
			}
		}
		if ( ! $out && $nav ) {
			foreach ( $nav as $point ) {
				if ( ! $point instanceof \DOMElement ) {
					continue;
				}
				$parent = $point->parentNode;
				if ( $parent instanceof \DOMElement && 'navPoint' === $parent->localName ) {
					continue;
				}
				$out[] = $this->ncx_point( $point, 1 );
			}
		}
		return $out;
	}

	/**
	 * @return array{title:string,href?:string,level?:int,children?:array}
	 */
	private function ncx_point( \DOMElement $point, int $level ): array {
		$title = '';
		$href  = '';
		foreach ( $point->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			if ( 'navLabel' === $child->localName ) {
				foreach ( $child->getElementsByTagName( 'text' ) as $t ) {
					$title = trim( $t->textContent ?? '' );
					break;
				}
			}
			if ( 'content' === $child->localName ) {
				$href = $child->getAttribute( 'src' );
			}
		}
		$entry = array(
			'title' => $title ?: __( 'Untitled', 'booklove' ),
			'level' => $level,
		);
		if ( $href ) {
			$entry['href'] = $href;
		}
		$children = array();
		foreach ( $point->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'navPoint' === $child->localName ) {
				$children[] = $this->ncx_point( $child, $level + 1 );
			}
		}
		if ( $children ) {
			$entry['children'] = $children;
		}
		return $entry;
	}

	/**
	 * @return list<array{title:string,href?:string,level?:int,children?:array}>
	 */
	private function parse_nav_toc( string $html ): array {
		$doc = $this->load_xml( $html );
		if ( ! $doc ) {
			// HTML5 nav may not be strict XML — strip to links heuristically.
			return $this->parse_nav_regex( $html );
		}
		$xpath = new \DOMXPath( $doc );
		$navs  = $xpath->query( '//*[local-name()="nav" and (contains(@epub:type,"toc") or contains(@typeof,"toc") or contains(@role,"doc-toc") or contains(@id,"toc"))]' );
		if ( ! $navs || ! $navs->length ) {
			$navs = $xpath->query( '//*[local-name()="nav"]' );
		}
		if ( ! $navs || ! $navs->length ) {
			return $this->parse_nav_regex( $html );
		}
		$nav = $navs->item( 0 );
		if ( ! $nav instanceof \DOMElement ) {
			return array();
		}
		$ols = $nav->getElementsByTagName( 'ol' );
		if ( ! $ols->length ) {
			return $this->parse_nav_regex( $html );
		}
		$ol = $ols->item( 0 );
		return $ol instanceof \DOMElement ? $this->parse_nav_list( $ol, 1 ) : array();
	}

	/**
	 * @return list<array{title:string,href?:string,level?:int,children?:array}>
	 */
	private function parse_nav_list( \DOMElement $list, int $level ): array {
		$out = array();
		foreach ( $list->childNodes as $li ) {
			if ( ! $li instanceof \DOMElement || 'li' !== $li->localName ) {
				continue;
			}
			$title = '';
			$href  = '';
			$child_list = null;
			foreach ( $li->childNodes as $c ) {
				if ( ! $c instanceof \DOMElement ) {
					continue;
				}
				if ( 'a' === $c->localName ) {
					$title = trim( $c->textContent ?? '' );
					$href  = $c->getAttribute( 'href' );
				}
				if ( 'span' === $c->localName && ! $title ) {
					$title = trim( $c->textContent ?? '' );
				}
				if ( 'ol' === $c->localName || 'ul' === $c->localName ) {
					$child_list = $c;
				}
			}
			$entry = array(
				'title' => $title ?: __( 'Untitled', 'booklove' ),
				'level' => $level,
			);
			if ( $href ) {
				$entry['href'] = $href;
			}
			if ( $child_list ) {
				$entry['children'] = $this->parse_nav_list( $child_list, $level + 1 );
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * @return list<array{title:string,href?:string,level?:int}>
	 */
	private function parse_nav_regex( string $html ): array {
		$out = array();
		if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$title = trim( wp_strip_all_tags( $match[2] ) );
				if ( '' === $title ) {
					continue;
				}
				$out[] = array(
					'title' => $title,
					'href'  => $match[1],
					'level' => 1,
				);
				if ( count( $out ) >= 200 ) {
					break;
				}
			}
		}
		return $out;
	}

	private function sample_text( Book_Metadata $m, string $opf_xml, string $opf_path, ZipArchive $zip, int $max ): void {
		$doc = $this->load_xml( $opf_xml );
		if ( ! $doc ) {
			return;
		}
		$xpath = new \DOMXPath( $doc );
		$manifest = array();
		$items = $xpath->query( '//*[local-name()="item"]' );
		if ( $items ) {
			foreach ( $items as $item ) {
				if ( $item instanceof \DOMElement ) {
					$manifest[ $item->getAttribute( 'id' ) ] = $item->getAttribute( 'href' );
				}
			}
		}
		$spine = $xpath->query( '//*[local-name()="itemref"]' );
		$buf   = '';
		if ( $spine ) {
			foreach ( $spine as $ref ) {
				if ( ! $ref instanceof \DOMElement ) {
					continue;
				}
				$id = $ref->getAttribute( 'idref' );
				if ( ! isset( $manifest[ $id ] ) ) {
					continue;
				}
				$full = $this->join_path( dirname( $opf_path ), $manifest[ $id ] );
				$html = $zip->getFromName( $full );
				if ( false === $html ) {
					continue;
				}
				$text = trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
				$buf .= ( $buf ? "\n\n" : '' ) . $text;
				if ( strlen( $buf ) >= $max ) {
					$buf = substr( $buf, 0, $max );
					break;
				}
			}
		}
		$m->full_text    = $buf;
		$m->text_sample  = mb_substr( $buf, 0, 2000 );
	}

	private function load_xml( string $xml ): ?\DOMDocument {
		$doc = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? $doc : null;
	}

	private function xpath_text( \DOMXPath $xpath, string $query ): string {
		$nodes = $xpath->query( $query );
		if ( ! $nodes || ! $nodes->length ) {
			return '';
		}
		return trim( $nodes->item( 0 )->textContent ?? '' );
	}

	private function join_path( string $base, string $rel ): string {
		$rel = str_replace( '\\', '/', $rel );
		if ( str_starts_with( $rel, '/' ) ) {
			return $this->normalize_zip_path( ltrim( $rel, '/' ) );
		}
		$base = str_replace( '\\', '/', $base );
		if ( '.' === $base || '' === $base ) {
			return $this->normalize_zip_path( $rel );
		}
		return $this->normalize_zip_path( $base . '/' . $rel );
	}

	private function normalize_zip_path( string $path ): string {
		$path  = str_replace( '\\', '/', $path );
		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $part;
		}
		return implode( '/', $parts );
	}
}
