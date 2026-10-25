<?php
/**
 * Book format registry — Calibre-inspired priorities and MIME map.
 *
 * @package Booklove
 * @see https://github.com/kovidgoyal/calibre/blob/master/src/calibre/ebooks/metadata/meta.py
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

/**
 * Knows which extensions are "books", how to label them, and which extractor wins.
 *
 * Calibre sorts formats by METADATA_PRIORITIES and smart-merges; richer container
 * formats (EPUB, MOBI/AZW) outrank plain text/PDF Info dicts when both exist.
 */
final class Format_Registry {

	/**
	 * Higher = preferred when merging metadata from multiple files of one book.
	 * Order mirrors Calibre's METADATA_PRIORITIES spirit.
	 *
	 * @var array<string,int>
	 */
	public const PRIORITIES = array(
		'txt'   => 1,
		'text'  => 1,
		'rtf'   => 2,
		'fb2'   => 3,
		'pdf'   => 4,
		'prc'   => 5,
		'epub'  => 8,
		'mobi'  => 9,
		'azw'   => 10,
		'azw3'  => 11,
		'azw4'  => 10,
		'kf8'   => 11,
		'kepub' => 8,
		'cbz'   => 6,
		'cbr'   => 6,
		'docx'  => 5,
		'odt'   => 5,
		'html'  => 1,
		'htm'   => 1,
	);

	/**
	 * @var array<string,array{label:string,mime:string,extractor:string,family:string}>
	 */
	private array $formats;

	public function __construct() {
		$this->formats = array(
			'epub'  => array(
				'label'     => 'EPUB',
				'mime'      => 'application/epub+zip',
				'extractor' => Extractors\Epub::class,
				'family'    => 'epub',
			),
			'kepub' => array(
				'label'     => 'KEPUB',
				'mime'      => 'application/epub+zip',
				'extractor' => Extractors\Epub::class,
				'family'    => 'epub',
			),
			'mobi'  => array(
				'label'     => 'MOBI',
				'mime'      => 'application/x-mobipocket-ebook',
				'extractor' => Extractors\Mobi::class,
				'family'    => 'mobi',
			),
			'azw'   => array(
				'label'     => 'AZW',
				'mime'      => 'application/vnd.amazon.ebook',
				'extractor' => Extractors\Mobi::class,
				'family'    => 'mobi',
			),
			'azw3'  => array(
				'label'     => 'AZW3',
				'mime'      => 'application/vnd.amazon.mobi8-ebook',
				'extractor' => Extractors\Mobi::class,
				'family'    => 'mobi',
			),
			'azw4'  => array(
				'label'     => 'AZW4',
				'mime'      => 'application/vnd.amazon.ebook',
				'extractor' => Extractors\Mobi::class,
				'family'    => 'mobi',
			),
			'prc'   => array(
				'label'     => 'PRC',
				'mime'      => 'application/x-mobipocket-ebook',
				'extractor' => Extractors\Mobi::class,
				'family'    => 'mobi',
			),
			'pdf'   => array(
				'label'     => 'PDF',
				'mime'      => 'application/pdf',
				'extractor' => Extractors\Pdf::class,
				'family'    => 'pdf',
			),
			'txt'   => array(
				'label'     => 'Plain text',
				'mime'      => 'text/plain',
				'extractor' => Extractors\Plain_Text::class,
				'family'    => 'text',
			),
			'text'  => array(
				'label'     => 'Plain text',
				'mime'      => 'text/plain',
				'extractor' => Extractors\Plain_Text::class,
				'family'    => 'text',
			),
			'rtf'   => array(
				'label'     => 'Rich Text',
				'mime'      => 'application/rtf',
				'extractor' => Extractors\Rtf::class,
				'family'    => 'text',
			),
			'fb2'   => array(
				'label'     => 'FictionBook',
				'mime'      => 'application/x-fictionbook+xml',
				'extractor' => Extractors\Fb2::class,
				'family'    => 'fb2',
			),
			'cbz'   => array(
				'label'     => 'Comic (CBZ)',
				'mime'      => 'application/vnd.comicbook+zip',
				'extractor' => Extractors\Cbz::class,
				'family'    => 'comic',
			),
			'docx'  => array(
				'label'     => 'Word',
				'mime'      => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'extractor' => Extractors\Docx::class,
				'family'    => 'office',
			),
		);

		/**
		 * Let other plugins register or override book formats.
		 *
		 * @param array<string,array{label:string,mime:string,extractor:string,family:string}> $formats
		 */
		$this->formats = apply_filters( 'booklove_formats', $this->formats );
	}

	/**
	 * @return array<string,array{label:string,mime:string,extractor:string,family:string}>
	 */
	public function all(): array {
		return $this->formats;
	}

	public function is_book_extension( string $ext ): bool {
		return isset( $this->formats[ strtolower( $ext ) ] );
	}

	/**
	 * @return array{label:string,mime:string,extractor:string,family:string}|null
	 */
	public function get( string $ext ): ?array {
		$ext = strtolower( $ext );
		return $this->formats[ $ext ] ?? null;
	}

	public function priority( string $ext ): int {
		$ext = strtolower( $ext );
		return self::PRIORITIES[ $ext ] ?? 0;
	}

	public function extension_from_path( string $path ): string {
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Instantiate the right extractor for a path.
	 */
	public function extractor_for( string $path ): ?Extractors\Extractor {
		$ext  = $this->extension_from_path( $path );
		$meta = $this->get( $ext );
		if ( ! $meta ) {
			return null;
		}
		$class = $meta['extractor'];
		if ( ! class_exists( $class ) ) {
			return null;
		}
		/** @var Extractors\Extractor $instance */
		$instance = new $class();
		return $instance;
	}

	/**
	 * @param array<string,string> $mimes
	 * @return array<string,string>
	 */
	public function filter_upload_mimes( array $mimes ): array {
		foreach ( $this->formats as $ext => $info ) {
			$mimes[ $ext ] = $info['mime'];
		}
		return $mimes;
	}

	/**
	 * WordPress often rejects non-image books; force recognition for known book types.
	 *
	 * @param array{ext?:string,type?:string,proper_filename?:string|false} $data
	 * @param string                                                         $file
	 * @param string                                                         $filename
	 * @param array<string,string>|null                                      $mimes
	 * @param string                                                         $real_mime
	 * @return array{ext?:string,type?:string,proper_filename?:string|false}
	 */
	public function fix_filetype( array $data, string $file, string $filename, $mimes, string $real_mime = '' ): array {
		$ext = $this->extension_from_path( $filename );
		if ( ! $this->is_book_extension( $ext ) ) {
			return $data;
		}
		$info = $this->get( $ext );
		if ( ! $info ) {
			return $data;
		}
		$data['ext']  = $ext;
		$data['type'] = $info['mime'];
		return $data;
	}

	/**
	 * Human list for settings / help screens.
	 */
	public function supported_list(): string {
		$labels = array();
		foreach ( $this->formats as $ext => $info ) {
			$labels[] = strtoupper( $ext );
		}
		sort( $labels );
		return implode( ', ', array_unique( $labels ) );
	}
}
