<?php
/**
 * RTF → rough plain text + title.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove\Extractors;

use Booklove\Book_Metadata;

defined( 'ABSPATH' ) || exit;

final class Rtf implements Extractor {

	public function supports( string $path ): bool {
		return 'rtf' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	public function extract( string $path, array $args = array() ): Book_Metadata {
		$m            = new Book_Metadata();
		$m->format    = 'rtf';
		$m->mime_type = 'application/rtf';
		$m->filesize  = (int) filesize( $path );

		$max  = (int) ( $args['max_text_bytes'] ?? 512000 );
		$raw  = file_get_contents( $path, false, null, 0, min( $m->filesize, $max * 3 ) );
		if ( false === $raw ) {
			throw new \RuntimeException( 'Cannot read RTF.' );
		}

		$text = $this->rtf_to_text( $raw );
		$m->full_text   = mb_substr( $text, 0, $max );
		$m->text_sample = mb_substr( $m->full_text, 0, 2000 );
		$m->ocr_status  = 'skipped';

		if ( preg_match( '/\\\\title\s+([^\{\\\\]+)/', $raw, $tm ) ) {
			$m->title = trim( $this->rtf_to_text( $tm[1] ) );
		}
		if ( preg_match( '/\\\\author\s+([^\{\\\\]+)/', $raw, $am ) ) {
			$m->authors = Book_Metadata::string_to_authors( trim( $this->rtf_to_text( $am[1] ) ) );
		}

		$m->confidence = 0.45;
		return $m;
	}

	private function rtf_to_text( string $rtf ): string {
		// Remove groups we don't want (fonts, colors, pict).
		$rtf = preg_replace( '/\{\\\\pict[\s\S]*?\}/', ' ', $rtf ) ?? $rtf;
		$rtf = preg_replace( '/\{\\\\fonttbl[\s\S]*?\}/', ' ', $rtf ) ?? $rtf;
		$rtf = preg_replace( '/\{\\\\colortbl[\s\S]*?\}/', ' ', $rtf ) ?? $rtf;
		$rtf = preg_replace( '/\{\\\\stylesheet[\s\S]*?\}/', ' ', $rtf ) ?? $rtf;
		$rtf = preg_replace( '/\\\\\'([0-9a-fA-F]{2})/', '&#x$1;', $rtf ) ?? $rtf;
		$rtf = preg_replace( '/\\\\u(-?\d+)\??/', '&#$1;', $rtf ) ?? $rtf;
		$rtf = str_replace( array( '\\par', '\\line', '\\tab' ), array( "\n", "\n", "\t" ), $rtf );
		$rtf = preg_replace( '/\\\\[a-z]+(-?\d+)?[ ]?/', '', $rtf ) ?? $rtf;
		$rtf = str_replace( array( '{', '}' ), '', $rtf );
		$rtf = html_entity_decode( $rtf, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$rtf = preg_replace( '/[ \t]+/', ' ', $rtf ) ?? $rtf;
		$rtf = preg_replace( '/\n{3,}/', "\n\n", $rtf ) ?? $rtf;
		return trim( $rtf );
	}
}
