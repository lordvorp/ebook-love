<?php
/**
 * Plain text extractor.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove\Extractors;

use Booklove\Book_Metadata;

defined( 'ABSPATH' ) || exit;

final class Plain_Text implements Extractor {

	public function supports( string $path ): bool {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'txt', 'text' ), true );
	}

	public function extract( string $path, array $args = array() ): Book_Metadata {
		$m            = new Book_Metadata();
		$m->format    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$m->mime_type = 'text/plain';
		$m->filesize  = (int) filesize( $path );

		$max  = (int) ( $args['max_text_bytes'] ?? 512000 );
		$data = file_get_contents( $path, false, null, 0, $max + 1 );
		if ( false === $data ) {
			throw new \RuntimeException( 'Cannot read text file.' );
		}

		if ( ! mb_check_encoding( $data, 'UTF-8' ) ) {
			$data = mb_convert_encoding( $data, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252' );
		}

		// Strip BOM.
		$data = preg_replace( '/^\xEF\xBB\xBF/', '', $data ) ?? $data;

		$m->full_text   = substr( $data, 0, $max );
		$m->text_sample = mb_substr( $m->full_text, 0, 2000 );
		$m->ocr_status  = 'skipped';

		// First non-empty line as title candidate when filename is weak.
		foreach ( preg_split( '/\R/u', $m->text_sample ) ?: array() as $line ) {
			$line = trim( $line );
			if ( strlen( $line ) > 2 && strlen( $line ) < 200 ) {
				$m->title = $line;
				break;
			}
		}

		// TOC heuristic: markdown-ish or ALL CAPS chapter lines.
		if ( ! empty( $args['extract_toc'] ) ) {
			$m->toc = $this->guess_toc( $m->full_text );
		}

		$m->confidence = 0.4;
		return $m;
	}

	/**
	 * @return list<array{title:string,level?:int}>
	 */
	private function guess_toc( string $text ): array {
		$out   = array();
		$lines = preg_split( '/\R/u', $text ) ?: array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^(#{1,3})\s+(.+)$/', $line, $m ) ) {
				$out[] = array(
					'title' => $m[2],
					'level' => strlen( $m[1] ),
				);
			} elseif ( preg_match( '/^(Chapter\s+\d+|CHAPTER\s+\d+|Part\s+\d+)[:.\s].{0,80}$/u', $line ) ) {
				$out[] = array(
					'title' => $line,
					'level' => 1,
				);
			}
			if ( count( $out ) >= 100 ) {
				break;
			}
		}
		return $out;
	}
}
