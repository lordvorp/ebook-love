<?php
/**
 * MOBI / AZW / PRC extractor — PalmDB + EXTH records (Calibre mobi reader spirit).
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove\Extractors;

use Booklove\Book_Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Mobipocket EXTH metadata without full KF8 unpack.
 *
 * EXTH record types (common):
 * 100 author, 101 publisher, 103 description, 104 isbn, 105 subject,
 * 106 publishing date, 108 contributor, 109 rights, 113 asin, 503 title,
 * 201 cover offset (into PDB records — best-effort cover).
 */
final class Mobi implements Extractor {

	public function supports( string $path ): bool {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'mobi', 'azw', 'azw3', 'azw4', 'prc', 'kf8' ), true );
	}

	public function extract( string $path, array $args = array() ): Book_Metadata {
		$m            = new Book_Metadata();
		$m->format    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$m->mime_type = 'application/x-mobipocket-ebook';
		$m->filesize  = (int) filesize( $path );

		$fh = fopen( $path, 'rb' );
		if ( ! $fh ) {
			throw new \RuntimeException( 'Cannot open MOBI file.' );
		}

		try {
			$header = fread( $fh, 78 );
			if ( false === $header || strlen( $header ) < 78 ) {
				throw new \RuntimeException( 'Truncated PalmDB header.' );
			}

			// PalmDOC type/creator at 60–68.
			$type = substr( $header, 60, 4 );
			if ( ! in_array( $type, array( 'BOOK', 'TEXt' ), true ) ) {
				// Still try — some AZW variants differ.
				$m->extras['palmdb_type'] = $type;
			}

			$num_records = unpack( 'n', substr( $header, 76, 2 ) )[1] ?? 0;
			if ( $num_records < 1 ) {
				throw new \RuntimeException( 'No PalmDB records.' );
			}

			$records = array();
			for ( $i = 0; $i < $num_records; $i++ ) {
				$entry = fread( $fh, 8 );
				if ( false === $entry || strlen( $entry ) < 8 ) {
					break;
				}
				$records[] = unpack( 'Noffset/Nattr', $entry )['offset'] ?? 0;
			}

			if ( ! $records ) {
				throw new \RuntimeException( 'Empty record list.' );
			}

			$rec0_off = (int) $records[0];
			fseek( $fh, $rec0_off );
			$rec0 = fread( $fh, 1024 );
			if ( false === $rec0 || strlen( $rec0 ) < 232 ) {
				throw new \RuntimeException( 'Missing MOBI header.' );
			}

			// PalmDOC header 16 bytes, then MOBI.
			$ident = substr( $rec0, 16, 4 );
			if ( 'MOBI' !== $ident ) {
				// May still be PalmDOC-only; use filename meta.
				$m->confidence = 0.2;
				$m->extras['mobi_ident'] = $ident;
				return $m;
			}

			$header_len = unpack( 'N', substr( $rec0, 20, 4 ) )[1] ?? 0;
			$title_offset = unpack( 'N', substr( $rec0, 84, 4 ) )[1] ?? 0;
			$title_length = unpack( 'N', substr( $rec0, 88, 4 ) )[1] ?? 0;
			$exth_flags   = unpack( 'N', substr( $rec0, 128, 4 ) )[1] ?? 0;

			if ( $title_length > 0 && $title_length < 1024 ) {
				fseek( $fh, $rec0_off + $title_offset );
				$title = fread( $fh, $title_length );
				if ( is_string( $title ) ) {
					$m->title = $this->clean( $title );
				}
			}

			$first_image = unpack( 'N', substr( $rec0, 108, 4 ) )[1] ?? 0;

			if ( $exth_flags & 0x40 ) {
				$exth_off = $rec0_off + 16 + $header_len;
				fseek( $fh, $exth_off );
				$exth_head = fread( $fh, 12 );
				if ( is_string( $exth_head ) && str_starts_with( $exth_head, 'EXTH' ) ) {
					$exth_len = unpack( 'N', substr( $exth_head, 4, 4 ) )[1] ?? 0;
					$count    = unpack( 'N', substr( $exth_head, 8, 4 ) )[1] ?? 0;
					$payload  = $exth_len > 12 ? fread( $fh, $exth_len - 12 ) : '';
					if ( is_string( $payload ) ) {
						$this->parse_exth( $m, $payload, $count );
					}
				}
			}

			if ( ! empty( $args['extract_cover'] ) && $first_image > 0 && isset( $records[ $first_image ] ) ) {
				$img = $this->read_record_bytes( $fh, $records, $first_image, (int) filesize( $path ) );
				if ( $img && $this->looks_like_image( $img ) ) {
					$ext = $this->image_ext( $img );
					$m->cover_data = array( $ext, $img );
				}
			}

			// Lightweight text sample from first text record (PalmDOC compression often applied — skip if compressed).
			if ( ! empty( $args['extract_text'] ) ) {
				$compression = unpack( 'n', substr( $rec0, 0, 2 ) )[1] ?? 0;
				$m->extras['palmdoc_compression'] = $compression;
				if ( 1 === $compression && isset( $records[1] ) ) {
					$chunk = $this->read_record_bytes( $fh, $records, 1, (int) filesize( $path ) );
					if ( $chunk ) {
						$text = preg_replace( '/[^\P{C}\n\t]+/u', ' ', $chunk ) ?? '';
						$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
						$m->text_sample = mb_substr( $text, 0, 2000 );
					}
				}
			}

			$m->confidence = max( $m->confidence, 0.7 );
		} finally {
			fclose( $fh );
		}

		return $m;
	}

	/**
	 * @param list<int> $records
	 */
	private function read_record_bytes( $fh, array $records, int $index, int $filesize ): ?string {
		if ( ! isset( $records[ $index ] ) ) {
			return null;
		}
		$start = (int) $records[ $index ];
		$end   = isset( $records[ $index + 1 ] ) ? (int) $records[ $index + 1 ] : $filesize;
		$len   = max( 0, min( $end - $start, 5_000_000 ) );
		if ( $len < 8 ) {
			return null;
		}
		fseek( $fh, $start );
		$data = fread( $fh, $len );
		return is_string( $data ) ? $data : null;
	}

	private function parse_exth( Book_Metadata $m, string $payload, int $count ): void {
		$pos = 0;
		$len = strlen( $payload );
		for ( $i = 0; $i < $count && $pos + 8 <= $len; $i++ ) {
			$type = unpack( 'N', substr( $payload, $pos, 4 ) )[1] ?? 0;
			$size = unpack( 'N', substr( $payload, $pos + 4, 4 ) )[1] ?? 0;
			if ( $size < 8 || $pos + $size > $len ) {
				break;
			}
			$data = substr( $payload, $pos + 8, $size - 8 );
			$pos += $size;
			$str  = $this->clean( $data );

			switch ( $type ) {
				case 100: // author
					if ( $str ) {
						$m->authors[] = $str;
					}
					break;
				case 101:
					$m->publisher = $str;
					break;
				case 103:
					$m->description = $str;
					break;
				case 104:
					$m->isbn = preg_replace( '/[^0-9Xx]/', '', $str ) ?: $str;
					break;
				case 105:
					if ( $str ) {
						$m->subjects[] = $str;
					}
					break;
				case 106:
					$m->pubdate = $str;
					break;
				case 109:
					$m->rights = $str;
					break;
				case 113:
				case 504:
					$m->identifier = $str ?: $m->identifier;
					break;
				case 503: // updated title
					if ( $str ) {
						$m->title = $str;
					}
					break;
				case 524:
					$m->language = $str;
					break;
			}
		}
	}

	private function clean( string $raw ): string {
		$raw = preg_replace( '/\x00+/', '', $raw ) ?? $raw;
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252' );
		}
		return trim( $raw );
	}

	private function looks_like_image( string $bytes ): bool {
		return str_starts_with( $bytes, "\xFF\xD8\xFF" )
			|| str_starts_with( $bytes, "\x89PNG" )
			|| str_starts_with( $bytes, 'GIF8' );
	}

	private function image_ext( string $bytes ): string {
		if ( str_starts_with( $bytes, "\x89PNG" ) ) {
			return 'png';
		}
		if ( str_starts_with( $bytes, 'GIF8' ) ) {
			return 'gif';
		}
		return 'jpg';
	}
}
