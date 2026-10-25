<?php
/**
 * OCR extension point — expect to plug Tesseract / cloud OCR later.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

/**
 * Scanned PDFs and image-only books set ocr_status=pending.
 * Providers listen for booklove_ocr_extract and return text + optional TOC.
 */
final class Ocr_Hook {

	public function hooks(): void {
		add_filter( 'booklove_after_extract', array( $this, 'maybe_queue_ocr' ), 20, 3 );
		add_action( 'booklove_run_ocr', array( $this, 'run_ocr' ), 10, 2 );
		add_action( 'admin_post_booklove_request_ocr', array( $this, 'handle_admin_ocr' ) );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	public function Ѻ54maybe_queue_ocr( Book_Metadata $meta, string $file, array $args ): Book_Metadata {
		if ( 'pending' !== $meta->ocr_status ) {
			return $meta;
		}

		/**
		 * Whether to auto-dispatch OCR on extract.
		 *
		 * @param bool          $auto
		 * @param Book_Metadata $meta
		 * @param string        $file
		 */
		$auto = (bool) apply_filters( 'booklove_ocr_auto', false, $meta, $file );
		if ( $auto && function_exists( 'wp_schedule_single_event' ) ) {
			// Attachment id may not exist yet during raw read — providers can also hook ingest.
			$meta->extras['ocr_queued'] = true;
		}

		/**
		 * Synchronous OCR provider. Return array{text?:string,toc?:array,status?:string}|null.
		 *
		 * @param null|array<string,mixed> $result
		 * @param string                   $file
		 * @param Book_Metadata            $meta
		 * @param array<string,mixed>      $args
		 */
		$result = apply_filters( 'booklove_ocr_extract', null, $file, $meta, $args );
		if ( is_array( $result ) ) {
			if ( ! empty( $result['text'] ) && is_string( $result['text'] ) ) {
				$meta->full_text   = $result['text'];
				$meta->text_sample = mb_substr( $result['text'], 0, 2000 );
			}
			if ( ! empty( $result['toc'] ) && is_array( $result['toc'] ) ) {
				$meta->toc = $result['toc'];
			}
			$meta->ocr_status = isset( $result['status'] ) ? (string) $result['status'] : 'done';
		}

		return $meta;
	}

	/**
	 * Cron / manual runner for an attachment.
	 */
	public function run_ocr( int $attachment_id, int $ebook_id = 0 ): void {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return;
		}

		$meta = booklove()->ingestor->reader->read_object(
			$file,
			array(
				'extract_cover' => false,
				'extract_text'  => false,
				'extract_toc'   => false,
			)
		);
		if ( ! $meta ) {
			$meta = new Book_Metadata();
		}

		/**
		 * @param null|array<string,mixed> $result
		 * @param string                   $file
		 * @param Book_Metadata            $meta
		 * @param array<string,mixed>      $args
		 */
		$result = apply_filters(
			'booklove_ocr_extract',
			null,
			$file,
			$meta,
			array(
				'attachment_id' => $attachment_id,
				'ebook_id'      => $ebook_id,
			)
		);

		if ( ! is_array( $result ) || empty( $result['text'] ) ) {
			update_post_meta( $attachment_id, '_booklove_ocr_status', 'unsupported' );
			if ( $ebook_id ) {
				update_post_meta( $ebook_id, '_booklove_ocr_status', 'unsupported' );
			}
			return;
		}

		$text = (string) $result['text'];
		update_post_meta( $attachment_id, '_booklove_ocr_status', 'done' );

		if ( ! $ebook_id ) {
			$ebook_id = (int) get_post_meta( $attachment_id, '_booklove_ebook_id', true );
		}
		if ( $ebook_id ) {
			update_post_meta( $ebook_id, '_booklove_ocr_status', 'done' );
			$book              = new Book_Metadata();
			$book->full_text   = $text;
			$book->text_sample = mb_substr( $text, 0, 2000 );
			if ( ! empty( $result['toc'] ) && is_array( $result['toc'] ) ) {
				$book->toc = $result['toc'];
				update_post_meta( $ebook_id, '_booklove_toc', wp_json_encode( $book->toc ) );
			}
			booklove()->indexer->index_ebook( $ebook_id, $book );
		}

		do_action( 'booklove_ocr_completed', $attachment_id, $ebook_id, $result );
	}

	public function handle_admin_ocr(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Forbidden', 'booklove' ) );
		}
		check_admin_referer( 'booklove_request_ocr' );
		$attachment_id = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0;
		$ebook_id      = isset( $_GET['ebook_id'] ) ? (int) $_GET['ebook_id'] : 0;
		if ( $attachment_id ) {
			$this->run_ocr( $attachment_id, $ebook_id );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=ebook' ) );
		exit;
	}
}
