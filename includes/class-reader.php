<?php
/**
 * booklove_read_ebook_metadata — WordPress wp_read_audio_metadata analogue.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

/**
 * Central read path used by upload, re-scan, and CLI.
 */
final class Reader {

	public function __construct(
		private Format_Registry $formats
	) {}

	/**
	 * Mirror of wp_read_audio_metadata( $file ): array|false
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>|false
	 */
	public function read( string $file, array $args = array() ): array|false {
		$meta = $this->read_object( $file, $args );
		if ( ! $meta ) {
			return false;
		}
		/**
		 * Filter raw ebook metadata array (attachment-shaped).
		 *
		 * @param array<string,mixed> $data Attachment-shaped metadata.
		 * @param string              $file Path.
		 * @param string|null         $format Extension.
		 * @param Book_Metadata       $object Rich object.
		 */
		return apply_filters(
			'booklove_read_ebook_metadata',
			$meta->to_attachment_metadata(),
			$file,
			$meta->format ?: null,
			$meta
		);
	}

	/**
	 * @param array<string,mixed> $args
	 */
	public function read_object( string $file, array $args = array() ): ?Book_Metadata {
		if ( ! is_readable( $file ) ) {
			return null;
		}

		$defaults = array(
			'extract_cover'  => true,
			'extract_text'   => true,
			'extract_toc'    => true,
			'max_text_bytes' => 512000,
		);
		$args     = array_merge( $defaults, $args );

		$base = Book_Metadata::from_filename( $file );
		$ext  = $this->formats->extension_from_path( $file );
		$base->format = $ext;

		$info = $this->formats->get( $ext );
		if ( $info ) {
			$base->mime_type = $info['mime'];
		}

		$extractor = $this->formats->extractor_for( $file );
		if ( $extractor && $extractor->supports( $file ) ) {
			try {
				$parsed = $extractor->extract( $file, $args );
				$base->smart_update( $parsed, true );
			} catch ( \Throwable $e ) {
				$base->extras['extract_error'] = $e->getMessage();
			}
		}

		/**
		 * After parse — OCR providers can fill full_text / toc / ocr_status.
		 *
		 * @param Book_Metadata       $base Metadata so far.
		 * @param string              $file Path.
		 * @param array<string,mixed> $args Args.
		 */
		$base = apply_filters( 'booklove_after_extract', $base, $file, $args );

		if ( ! $base->title ) {
			$base->title = __( 'Unknown', 'booklove' );
		}
		if ( ! $base->authors ) {
			$base->authors = array( __( 'Unknown', 'booklove' ) );
		}

		return $base;
	}
}

/**
 * Global helper matching core naming (wp_read_audio_metadata).
 *
 * @param array<string,mixed> $args
 * @return array<string,mixed>|false
 */
function booklove_read_ebook_metadata( string $file, array $args = array() ): array|false {
	return booklove()->ingestor->reader->read( $file, $args );
}
