<?php
/**
 * DOCX core properties + document text sample.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove\Extractors;

use Booklove\Book_Metadata;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

final class Docx implements Extractor {

	public function supports( string $path ): bool {
		return 'docx' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) && class_exists( ZipArchive::class );
	}

	public function extract( string $path, array $args = array() ): Book_Metadata {
		$m            = new Book_Metadata();
		$m->format    = 'docx';
		$m->mime_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		$m->filesize  = (int) filesize( $path );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			throw new \RuntimeException( 'Invalid DOCX.' );
		}

		try {
			$core = $zip->getFromName( 'docProps/core.xml' );
			if ( is_string( $core ) ) {
				$doc  = new \DOMDocument();
				$prev = libxml_use_internal_errors( true );
				$ok   = $doc->loadXML( $core, LIBXML_NONET );
				libxml_clear_errors();
				libxml_use_internal_errors( $prev );
				if ( $ok ) {
					$xpath = new \DOMXPath( $doc );
					$xpath->registerNamespace( 'dc', 'http://purl.org/dc/elements/1.1/' );
					$xpath->registerNamespace( 'cp', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties' );
					$xpath->registerNamespace( 'dcterms', 'http://purl.org/dc/terms/' );
					$m->title       = trim( (string) $xpath->evaluate( 'string(//dc:title)' ) );
					$creator        = trim( (string) $xpath->evaluate( 'string(//dc:creator)' ) );
					$m->authors     = $creator ? Book_Metadata::string_to_authors( $creator ) : array();
					$m->description = trim( (string) $xpath->evaluate( 'string(//dc:description)' ) );
					$m->subjects    = array_values(
						array_filter(
							array_map( 'trim', explode( ',', (string) $xpath->evaluate( 'string(//cp:keywords)' ) ) )
						)
					);
					$m->pubdate     = trim( (string) $xpath->evaluate( 'string(//dcterms:created)' ) );
				}
			}

			if ( ! empty( $args['extract_text'] ) ) {
				$document = $zip->getFromName( 'word/document.xml' );
				if ( is_string( $document ) ) {
					$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $document ) ) ?? '' );
					$max  = (int) ( $args['max_text_bytes'] ?? 512000 );
					$m->full_text   = mb_substr( $text, 0, $max );
					$m->text_sample = mb_substr( $text, 0, 2000 );
					$m->ocr_status  = 'skipped';
				}
			}
		} finally {
			$zip->close();
		}

		$m->confidence = 0.6;
		return $m;
	}
}
