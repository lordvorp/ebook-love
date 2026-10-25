<?php
/**
 * CBZ comic archive — ComicInfo.xml + first image as cover.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove\Extractors;

use Booklove\Book_Metadata;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

final class Cbz implements Extractor {

	public function supports( string $path ): bool {
		return 'cbz' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) && class_exists( ZipArchive::class );
	}

	public function extract( string $path, array $args = array() ): Book_Metadata {
		$m            = new Book_Metadata();
		$m->format    = 'cbz';
		$m->mime_type = 'application/vnd.comicbook+zip';
		$m->filesize  = (int) filesize( $path );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			throw new \RuntimeException( 'Invalid CBZ.' );
		}

		try {
			$info = $zip->getFromName( 'ComicInfo.xml' );
			if ( false === $info ) {
				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$name = $zip->getNameIndex( $i );
					if ( is_string( $name ) && strcasecmp( basename( $name ), 'ComicInfo.xml' ) === 0 ) {
						$info = $zip->getFromName( $name );
						break;
					}
				}
			}
			if ( is_string( $info ) ) {
				$this->parse_comic_info( $m, $info );
			}

			$images = array();
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( ! is_string( $name ) || str_ends_with( $name, '/' ) ) {
					continue;
				}
				if ( preg_match( '/\.(jpe?g|png|gif|webp)$/i', $name ) ) {
					$images[] = $name;
				}
			}
			natcasesort( $images );
			$images = array_values( $images );
			$m->page_count = count( $images );

			if ( ! empty( $args['extract_cover'] ) && $images ) {
				$bytes = $zip->getFromName( $images[0] );
				if ( false !== $bytes ) {
					$ext = strtolower( pathinfo( $images[0], PATHINFO_EXTENSION ) );
					if ( 'jpeg' === $ext ) {
						$ext = 'jpg';
					}
					$m->cover_data = array( $ext, $bytes );
				}
			}

			if ( ! empty( $args['extract_toc'] ) ) {
				foreach ( $images as $idx => $img ) {
					$m->toc[] = array(
						'title' => sprintf(
							/* translators: %d: page number */
							__( 'Page %d', 'booklove' ),
							$idx + 1
						),
						'href'  => $img,
						'level' => 1,
					);
					if ( $idx >= 49 ) {
						break;
					}
				}
			}
		} finally {
			$zip->close();
		}

		$m->confidence = $m->title ? 0.75 : 0.4;
		return $m;
	}

	private function parse_comic_info( Book_Metadata $m, string $xml ): void {
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return;
		}
		$get = static function ( string $tag ) use ( $doc ): string {
			$nodes = $doc->getElementsByTagName( $tag );
			return $nodes->length ? trim( $nodes->item( 0 )->textContent ?? '' ) : '';
		};
		$m->title       = $get( 'Title' ) ?: $get( 'Series' );
		$m->series      = $get( 'Series' );
		$m->description = $get( 'Summary' );
		$m->publisher   = $get( 'Publisher' );
		$m->language    = $get( 'LanguageISO' );
		if ( $get( 'Number' ) !== '' ) {
			$m->series_index = (float) $get( 'Number' );
		}
		foreach ( array( 'Writer', 'Penciller', 'Inker', 'Colorist', 'Letterer', 'CoverArtist' ) as $role ) {
			$v = $get( $role );
			if ( $v ) {
				foreach ( Book_Metadata::string_to_authors( $v ) as $a ) {
					$m->authors[] = $a;
				}
			}
		}
		$m->authors = array_values( array_unique( $m->authors ) );
		$genre      = $get( 'Genre' );
		if ( $genre ) {
			$m->subjects[] = $genre;
		}
	}
}
