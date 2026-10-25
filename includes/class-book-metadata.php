<?php
/**
 * Book metadata bag — Calibre MetaInformation / smart_update analogue.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

/**
 * Mutable metadata collected from a book file (or filename fallback).
 *
 * Shaped so it can land in both attachment metadata (WP media cleverness)
 * and an ebook CPT (full document).
 */
final class Book_Metadata {

	public string $title = '';
	/** @var list<string> */
	public array $authors = array();
	public string $description = '';
	public string $publisher = '';
	public string $language = '';
	public string $isbn = '';
	public string $doi = '';
	public string $series = '';
	public float $series_index = 0.0;
	public string $pubdate = '';
	public string $rights = '';
	/** @var list<string> */
	public array $subjects = array();
	public string $format = '';
	public string $mime_type = '';
	public int $filesize = 0;
	public int $page_count = 0;
	public string $identifier = '';

	/**
	 * Nested TOC: list of { title, href?, level, children? }.
	 *
	 * @var list<array{title:string,href?:string,level?:int,children?:array}>
	 */
	public array $toc = array();

	/**
	 * Extracted plain text for search indexing (may be truncated).
	 */
	public string $text_sample = '';

	/**
	 * Full-ish extractable text when cheap (TXT/RTF/EPUB spine).
	 */
	public string $full_text = '';

	/**
	 * Cover binary: [ extension, bytes ] like Calibre cover_data.
	 *
	 * @var array{0:string,1:string}|null
	 */
	public ?array $cover_data = null;

	/**
	 * OCR status: none|pending|done|skipped|unsupported
	 */
	public string $ocr_status = 'none';

	/**
	 * Extra bag for format-specific bits.
	 *
	 * @var array<string,mixed>
	 */
	public array $extras = array();

	/**
	 * Source confidence 0–1 for logging / UI.
	 */
	public float $confidence = 0.0;

	/**
	 * Calibre-style smart_update: fill empties; richer values win when $overwrite.
	 */
	public function smart_update( self $other, bool $overwrite = false ): void {
		$scalar = array(
			'title',
			'description',
			'publisher',
			'language',
			'isbn',
			'doi',
			'series',
			'pubdate',
			'rights',
			'format',
			'mime_type',
			'identifier',
			'text_sample',
			'full_text',
			'ocr_status',
		);

		foreach ( $scalar as $prop ) {
			$cur = $this->{$prop};
			$new = $other->{$prop};
			if ( ( $overwrite && '' !== $new ) || ( $this->is_empty_scalar( $cur ) && '' !== $new ) ) {
				$this->{$prop} = $new;
			}
		}

		if ( ( $overwrite && $other->authors ) || ( ! $this->authors && $other->authors ) ) {
			$this->authors = $other->authors;
		}
		if ( ( $overwrite && $other->subjects ) || ( ! $this->subjects && $other->subjects ) ) {
			$this->subjects = $other->subjects;
		}
		if ( ( $overwrite && $other->toc ) || ( ! $this->toc && $other->toc ) ) {
			$this->toc = $other->toc;
		}
		if ( ( $overwrite && $other->cover_data ) || ( null === $this->cover_data && $other->cover_data ) ) {
			$this->cover_data = $other->cover_data;
		}
		if ( $other->filesize > 0 && ( $overwrite || $this->filesize <= 0 ) ) {
			$this->filesize = $other->filesize;
		}
		if ( $other->page_count > 0 && ( $overwrite || $this->page_count <= 0 ) ) {
			$this->page_count = $other->page_count;
		}
		if ( $other->series_index > 0 && ( $overwrite || $this->series_index <= 0 ) ) {
			$this->series_index = $other->series_index;
		}
		if ( $other->confidence > $this->confidence ) {
			$this->confidence = $other->confidence;
		}

		$this->extras = array_merge( $this->extras, $other->extras );
	}

	private function is_empty_scalar( mixed $v ): bool {
		return null === $v || '' === $v || array() === $v;
	}

	/**
	 * Array shaped for wp_update_attachment_metadata / REST.
	 *
	 * Mirrors the spirit of audio metadata keys WordPress already stores.
	 *
	 * @return array<string,mixed>
	 */
	public function to_attachment_metadata(): array {
		return array(
			'booklove'         => true,
			'format'           => $this->format,
			'mime_type'        => $this->mime_type,
			'filesize'         => $this->filesize,
			'title'            => $this->title,
			'artist'           => $this->authors ? implode( ', ', $this->authors ) : '',
			'authors'          => $this->authors,
			'album'            => $this->series, // series ≈ album in WP audio metaphor
			'description'      => $this->description,
			'publisher'        => $this->publisher,
			'language'         => $this->language,
			'isbn'             => $this->isbn,
			'doi'              => $this->doi,
			'series'           => $this->series,
			'series_index'     => $this->series_index,
			'pubdate'          => $this->pubdate,
			'rights'           => $this->rights,
			'subjects'         => $this->subjects,
			'page_count'       => $this->page_count,
			'identifier'       => $this->identifier,
			'toc'              => $this->toc,
			'ocr_status'       => $this->ocr_status,
			'confidence'       => $this->confidence,
			'extras'           => $this->extras,
			'length'           => $this->page_count, // media UI sometimes expects length
			'length_formatted' => $this->page_count ? sprintf( '%d pages', $this->page_count ) : '',
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		$m = new self();
		foreach ( array(
			'title',
			'description',
			'publisher',
			'language',
			'isbn',
			'doi',
			'series',
			'pubdate',
			'rights',
			'format',
			'mime_type',
			'identifier',
			'text_sample',
			'full_text',
			'ocr_status',
		) as $k ) {
			if ( isset( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
				$m->{$k} = $data[ $k ];
			}
		}
		if ( ! empty( $data['authors'] ) && is_array( $data['authors'] ) ) {
			$m->authors = array_values( array_map( 'strval', $data['authors'] ) );
		} elseif ( ! empty( $data['artist'] ) && is_string( $data['artist'] ) ) {
			$m->authors = self::string_to_authors( $data['artist'] );
		}
		if ( ! empty( $data['subjects'] ) && is_array( $data['subjects'] ) ) {
			$m->subjects = array_values( array_map( 'strval', $data['subjects'] ) );
		}
		if ( ! empty( $data['toc'] ) && is_array( $data['toc'] ) ) {
			$m->toc = $data['toc'];
		}
		if ( isset( $data['filesize'] ) ) {
			$m->filesize = (int) $data['filesize'];
		}
		if ( isset( $data['page_count'] ) ) {
			$m->page_count = (int) $data['page_count'];
		}
		if ( isset( $data['series_index'] ) ) {
			$m->series_index = (float) $data['series_index'];
		}
		if ( isset( $data['confidence'] ) ) {
			$m->confidence = (float) $data['confidence'];
		}
		if ( ! empty( $data['extras'] ) && is_array( $data['extras'] ) ) {
			$m->extras = $data['extras'];
		}
		return $m;
	}

	/**
	 * Calibre string_to_authors: split on & / and / ;
	 *
	 * @return list<string>
	 */
	public static function string_to_authors( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}
		$parts = preg_split( '/\s*(?:&| and |;|,)\s*/i', $raw ) ?: array( $raw );
		$out   = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out ?: array( $raw );
	}

	/**
	 * Filename fallback — Calibre metadata_from_filename("Title - Author.ext").
	 */
	public static function from_filename( string $path ): self {
		$m        = new self();
		$base     = pathinfo( $path, PATHINFO_FILENAME );
		$base     = str_replace( '_', ' ', $base );
		$m->format = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( preg_match( '/^(?P<title>.+?)\s+-\s+(?P<author>[^-]+)$/u', $base, $match ) ) {
			$m->title   = trim( $match['title'] );
			$m->authors = self::string_to_authors( $match['author'] );
			$m->confidence = 0.3;
		} else {
			$m->title      = $base;
			$m->confidence = 0.1;
		}

		if ( is_readable( $path ) ) {
			$m->filesize = (int) filesize( $path );
		}

		return $m;
	}
}
