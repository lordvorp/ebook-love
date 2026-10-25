<?php
/**
 * Upload → attachment metadata → ebook document.
 *
 * Echoes core media_handle_upload + wp_read_audio_metadata cleverness:
 * title/content from tags, attachment meta bag, preview cover, then a real
 * public document (ebook CPT) so the book is not "just a file".
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

final class Ingestor {

	public Reader $reader;

	public function __construct(
		private Format_Registry $formats
	) {
		$this->reader = new Reader( $formats );
	}

	public function hooks(): void {
		// After core generates attachment metadata (images/PDF thumbs/audio).
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'filter_attachment_metadata' ), 10, 2 );

		// When an attachment is added, promote book files into documents.
		add_action( 'add_attachment', array( $this, 'on_add_attachment' ), 20 );

		// Media modal / edit screen fields.
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 10, 2 );

		// REST: expose book meta on media + ebook.
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );

		// Re-scan action for admins.
		add_action( 'admin_post_booklove_rescan', array( $this, 'handle_rescan' ) );
	}

	/**
	 * Fold ebook metadata into the same array WP stores for audio/PDF.
	 *
	 * @param array<string,mixed> $metadata
	 * @return array<string,mixed>
	 */
	public function filter_attachment_metadata( array $metadata, int $attachment_id ): array {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! $this->is_book_attachment( $attachment_id, $file ) ) {
			return $metadata;
		}

		$book = $this->reader->read_object( $file );
		if ( ! $book ) {
			return $metadata;
		}

		$book_meta = $book->to_attachment_metadata();
		$metadata  = array_merge( $metadata, $book_meta );

		// Cover sizes under metadata['sizes'] like PDF/image thumbs — media library loves this.
		if ( $book->cover_data ) {
			$cover_id = $this->ensure_cover_attachment( $attachment_id, $book );
			if ( $cover_id ) {
				$metadata['booklove_cover_id'] = $cover_id;
				$cover_meta = wp_get_attachment_metadata( $cover_id );
				if ( is_array( $cover_meta ) && ! empty( $cover_meta['sizes'] ) ) {
					$metadata['sizes'] = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] )
						? array_merge( $metadata['sizes'], $cover_meta['sizes'] )
						: $cover_meta['sizes'];
				}
				if ( ! empty( $cover_meta['width'] ) ) {
					$metadata['width']  = $cover_meta['width'];
					$metadata['height'] = $cover_meta['height'] ?? 0;
				}
			}
		}

		return $metadata;
	}

	/**
	 * Like media_handle_upload audio branch: set title/content from tags,
	 * then create/update the ebook CPT document.
	 */
	public function on_add_attachment( int $attachment_id ): void {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! $this->is_book_attachment( $attachment_id, $file ) ) {
			return;
		}

		$this->hydrate_attachment_post( $attachment_id, $file );
		$this->ensure_ebook_document( $attachment_id );
	}

	/**
	 * Fill attachment title / content / excerpt the way WP does for audio ID3.
	 */
	public function hydrate_attachment_post( int $attachment_id, ?string $file = null ): ?Book_Metadata {
		$file = $file ?: get_attached_file( $attachment_id );
		if ( ! $file ) {
			return null;
		}

		$book = $this->reader->read_object( $file );
		if ( ! $book ) {
			return null;
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return $book;
		}

		$title   = $book->title ?: $attachment->post_title;
		$content = $this->compose_attachment_content( $book );
		$excerpt = $book->description ? wp_trim_words( wp_strip_all_tags( $book->description ), 40 ) : '';

		$update = array(
			'ID'           => $attachment_id,
			'post_title'   => $title,
			'post_content' => $content,
		);
		if ( $excerpt ) {
			$update['post_excerpt'] = $excerpt;
		}

		// Avoid infinite loops from save hooks.
		remove_action( 'add_attachment', array( $this, 'on_add_attachment' ), 20 );
		wp_update_post( $update );
		add_action( 'add_attachment', array( $this, 'on_add_attachment' ), 20 );

		update_post_meta( $attachment_id, '_booklove_format', $book->format );
		update_post_meta( $attachment_id, '_booklove_isbn', $book->isbn );
		update_post_meta( $attachment_id, '_booklove_ocr_status', $book->ocr_status );

		return $book;
	}

	/**
	 * Promote file → full ebook document (CPT), linked to the attachment.
	 *
	 * @return int Ebook post ID.
	 */
	public function ensure_ebook_document( int $attachment_id ): int {
		$existing = $this->find_ebook_for_attachment( $attachment_id );
		$file     = get_attached_file( $attachment_id );
		$book     = $file ? $this->reader->read_object( $file ) : null;

		if ( ! $book ) {
			// Fall back to attachment metadata already stored.
			$meta = wp_get_attachment_metadata( $attachment_id );
			$book = is_array( $meta ) ? Book_Metadata::from_array( $meta ) : new Book_Metadata();
		}

		$cover_id = 0;
		$meta_now = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta_now ) && ! empty( $meta_now['booklove_cover_id'] ) ) {
			$cover_id = (int) $meta_now['booklove_cover_id'];
		} elseif ( $book->cover_data ) {
			$cover_id = $this->ensure_cover_attachment( $attachment_id, $book );
		}

		$title   = $book->title ?: get_the_title( $attachment_id );
		$content = $this->compose_document_content( $book );

		$postarr = array(
			'post_type'    => Post_Type::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $book->description ? wp_trim_words( wp_strip_all_tags( $book->description ), 55 ) : '',
		);

		if ( $existing ) {
			$postarr['ID'] = $existing;
			$ebook_id      = wp_update_post( $postarr, true );
		} else {
			$ebook_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $ebook_id ) || ! $ebook_id ) {
			return 0;
		}

		$ebook_id = (int) $ebook_id;

		update_post_meta( $ebook_id, '_booklove_attachment_id', $attachment_id );
		update_post_meta( $ebook_id, '_booklove_format', $book->format );
		update_post_meta( $ebook_id, '_booklove_isbn', $book->isbn );
		update_post_meta( $ebook_id, '_booklove_doi', $book->doi );
		update_post_meta( $ebook_id, '_booklove_publisher', $book->publisher );
		update_post_meta( $ebook_id, '_booklove_language', $book->language );
		update_post_meta( $ebook_id, '_booklove_series', $book->series );
		update_post_meta( $ebook_id, '_booklove_series_index', $book->series_index );
		update_post_meta( $ebook_id, '_booklove_pubdate', $book->pubdate );
		update_post_meta( $ebook_id, '_booklove_page_count', $book->page_count );
		update_post_meta( $ebook_id, '_booklove_identifier', $book->identifier );
		update_post_meta( $ebook_id, '_booklove_toc', wp_json_encode( $book->toc ) );
		update_post_meta( $ebook_id, '_booklove_ocr_status', $book->ocr_status );
		update_post_meta( $ebook_id, '_booklove_confidence', $book->confidence );

		update_post_meta( $attachment_id, '_booklove_ebook_id', $ebook_id );

		// Authors / subjects as taxonomies — first-class WP terms.
		if ( $book->authors ) {
			wp_set_object_terms( $ebook_id, $book->authors, Taxonomies::AUTHOR, false );
		}
		if ( $book->subjects ) {
			wp_set_object_terms( $ebook_id, $book->subjects, Taxonomies::SUBJECT, false );
		}
		if ( $book->series ) {
			wp_set_object_terms( $ebook_id, array( $book->series ), Taxonomies::SERIES, false );
		}
		if ( $book->format ) {
			wp_set_object_terms( $ebook_id, array( strtoupper( $book->format ) ), Taxonomies::FORMAT, false );
		}

		if ( $cover_id ) {
			set_post_thumbnail( $ebook_id, $cover_id );
			update_post_meta( $ebook_id, '_booklove_cover_id', $cover_id );
		}

		// Indexable full text for WP search (custom table / post meta).
		booklove()->indexer->index_ebook( $ebook_id, $book );

		/**
		 * Fires when an ebook document has been created/updated from an upload.
		 *
		 * @param int           $ebook_id
		 * @param int           $attachment_id
		 * @param Book_Metadata $book
		 */
		do_action( 'booklove_ebook_ingested', $ebook_id, $attachment_id, $book );

		return $ebook_id;
	}

	/**
	 * Persist cover bytes as an image attachment (featured image source).
	 */
	public function ensure_cover_attachment( int $book_attachment_id, Book_Metadata $book ): int {
		if ( ! $book->cover_data ) {
			return (int) get_post_meta( $book_attachment_id, '_booklove_cover_id', true );
		}

		$existing = (int) get_post_meta( $book_attachment_id, '_booklove_cover_id', true );
		if ( $existing && get_post( $existing ) ) {
			return $existing;
		}

		list( $ext, $bytes ) = $book->cover_data;
		$ext = preg_replace( '/[^a-z0-9]/', '', strtolower( $ext ) ) ?: 'jpg';
		if ( 'jpeg' === $ext ) {
			$ext = 'jpg';
		}

		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'booklove/covers';
		wp_mkdir_p( $dir );

		$filename = 'cover-' . $book_attachment_id . '-' . wp_generate_password( 6, false ) . '.' . $ext;
		$path     = trailingslashit( $dir ) . $filename;
		if ( false === file_put_contents( $path, $bytes ) ) {
			return 0;
		}

		$filetype = wp_check_filetype( $filename, null );
		$attach   = array(
			'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
			'post_title'     => sprintf(
				/* translators: %s: book title */
				__( 'Cover: %s', 'booklove' ),
				$book->title ?: get_the_title( $book_attachment_id )
			),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $book_attachment_id,
		);

		$cover_id = wp_insert_attachment( $attach, $path, $book_attachment_id );
		if ( ! $cover_id || is_wp_error( $cover_id ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $cover_id, $path );
		wp_update_attachment_metadata( $cover_id, $meta );

		update_post_meta( $book_attachment_id, '_booklove_cover_id', $cover_id );

		return (int) $cover_id;
	}

	public function find_ebook_for_attachment( int $attachment_id ): int {
		$cached = (int) get_post_meta( $attachment_id, '_booklove_ebook_id', true );
		if ( $cached && get_post( $cached ) ) {
			return $cached;
		}

		$q = new \WP_Query(
			array(
				'post_type'      => Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_booklove_attachment_id',
				'meta_value'     => $attachment_id,
				'no_found_rows'  => true,
			)
		);

		return $q->posts ? (int) $q->posts[0] : 0;
	}

	public function is_book_attachment( int $attachment_id, ?string $file = null ): bool {
		$file = $file ?: get_attached_file( $attachment_id );
		if ( $file && $this->formats->is_book_extension( $this->formats->extension_from_path( $file ) ) ) {
			return true;
		}
		$mime = get_post_mime_type( $attachment_id );
		if ( ! $mime ) {
			return false;
		}
		foreach ( $this->formats->all() as $info ) {
			if ( $info['mime'] === $mime ) {
				return true;
			}
		}
		return false;
	}

	private function compose_attachment_content( Book_Metadata $book ): string {
		// Parallel to media_handle_upload audio description sentences.
		$parts = array();
		$title = $book->title ?: __( 'Untitled', 'booklove' );
		$by    = $book->authors ? implode( ', ', $book->authors ) : '';

		if ( $by ) {
			$parts[] = sprintf(
				/* translators: 1: book title, 2: author list */
				__( '"%1$s" by %2$s.', 'booklove' ),
				$title,
				$by
			);
		} else {
			$parts[] = sprintf(
				/* translators: %s: book title */
				__( '"%s".', 'booklove' ),
				$title
			);
		}

		if ( $book->publisher ) {
			$parts[] = sprintf(
				/* translators: %s: publisher */
				__( 'Published by %s.', 'booklove' ),
				$book->publisher
			);
		}
		if ( $book->series ) {
			$parts[] = sprintf(
				/* translators: 1: series name, 2: series index */
				__( 'Series: %1$s%2$s.', 'booklove' ),
				$book->series,
				$book->series_index ? ' #' . $book->series_index : ''
			);
		}
		if ( $book->isbn ) {
			$parts[] = sprintf(
				/* translators: %s: ISBN */
				__( 'ISBN: %s.', 'booklove' ),
				$book->isbn
			);
		}
		if ( $book->format ) {
			$parts[] = sprintf(
				/* translators: %s: format extension */
				__( 'Format: %s.', 'booklove' ),
				strtoupper( $book->format )
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * Document body: description + TOC block + searchable extract.
	 * This is what makes the upload a *document*, not a dead file.
	 */
	private function compose_document_content( Book_Metadata $book ): string {
		$blocks = array();

		if ( $book->description ) {
			$blocks[] = '<!-- wp:paragraph -->' . "\n<p>" . esc_html( wp_strip_all_tags( $book->description ) ) . "</p>\n" . '<!-- /wp:paragraph -->';
		}

		if ( $book->toc ) {
			$blocks[] = $this->toc_to_block( $book->toc );
		}

		if ( $book->text_sample ) {
			$blocks[] = '<!-- wp:paragraph {"className":"booklove-text-sample"} -->' . "\n<p><em>" .
				esc_html__( 'Excerpt', 'booklove' ) . ':</em> ' .
				esc_html( wp_trim_words( $book->text_sample, 80 ) ) .
				"</p>\n" . '<!-- /wp:paragraph -->';
		}

		/**
		 * Filter generated ebook post_content blocks.
		 *
		 * @param string        $content
		 * @param Book_Metadata $book
		 */
		return apply_filters( 'booklove_document_content', implode( "\n\n", $blocks ), $book );
	}

	/**
	 * @param list<array{title:string,href?:string,level?:int,children?:array}> $toc
	 */
	private function toc_to_block( array $toc ): string {
		$html = '<!-- wp:list {"className":"booklove-toc"} -->' . "\n<ul class=\"booklove-toc\">";
		$html .= $this->toc_items_html( $toc );
		$html .= "</ul>\n" . '<!-- /wp:list -->';
		return $html;
	}

	/**
	 * @param list<array{title:string,href?:string,level?:int,children?:array}> $items
	 */
	private function toc_items_html( array $items ): string {
		$html = '';
		foreach ( $items as $item ) {
			$title = esc_html( $item['title'] ?? '' );
			$html .= '<li>' . $title;
			if ( ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
				$html .= '<ul>' . $this->toc_items_html( $item['children'] ) . '</ul>';
			}
			$html .= '</li>';
		}
		return $html;
	}

	/**
	 * @param array<string,array<string,mixed>> $fields
	 * @return array<string,array<string,mixed>>
	 */
	public function attachment_fields( array $fields, \WP_Post $post ): array {
		if ( ! $this->is_book_attachment( $post->ID ) ) {
			return $fields;
		}
		$meta   = wp_get_attachment_metadata( $post->ID );
		$ebook  = (int) get_post_meta( $post->ID, '_booklove_ebook_id', true );
		$format = is_array( $meta ) ? ( $meta['format'] ?? '' ) : '';
		$isbn   = is_array( $meta ) ? ( $meta['isbn'] ?? '' ) : '';
		$ocr    = is_array( $meta ) ? ( $meta['ocr_status'] ?? '' ) : '';

		$fields['booklove_info'] = array(
			'label' => __( 'Ebook', 'booklove' ),
			'input' => 'html',
			'html'  => sprintf(
				'<p><strong>%1$s</strong> %2$s<br><strong>%3$s</strong> %4$s<br><strong>%5$s</strong> %6$s<br>%7$s</p>',
				esc_html__( 'Format:', 'booklove' ),
				esc_html( strtoupper( (string) $format ) ),
				esc_html__( 'ISBN:', 'booklove' ),
				esc_html( (string) $isbn ?: '—' ),
				esc_html__( 'OCR:', 'booklove' ),
				esc_html( (string) $ocr ?: 'none' ),
				$ebook
					? sprintf(
						'<a href="%s">%s</a>',
						esc_url( get_edit_post_link( $ebook ) ?: '#' ),
						esc_html__( 'Open ebook document', 'booklove' )
					)
					: esc_html__( 'Document not created yet — re-scan.', 'booklove' )
			),
		);

		return $fields;
	}

	public function register_rest_fields(): void {
		register_rest_field(
			'attachment',
			'booklove',
			array(
				'get_callback' => function ( array $obj ) {
					$id = (int) $obj['id'];
					if ( ! $this->is_book_attachment( $id ) ) {
						return null;
					}
					$meta = wp_get_attachment_metadata( $id );
					return array(
						'ebook_id'   => (int) get_post_meta( $id, '_booklove_ebook_id', true ),
						'format'     => is_array( $meta ) ? ( $meta['format'] ?? '' ) : '',
						'toc'        => is_array( $meta ) ? ( $meta['toc'] ?? array() ) : array(),
						'isbn'       => is_array( $meta ) ? ( $meta['isbn'] ?? '' ) : '',
						'ocr_status' => is_array( $meta ) ? ( $meta['ocr_status'] ?? '' ) : '',
						'authors'    => is_array( $meta ) ? ( $meta['authors'] ?? array() ) : array(),
						'cover_id'   => is_array( $meta ) ? (int) ( $meta['booklove_cover_id'] ?? 0 ) : 0,
					);
				},
				'schema'       => null,
			)
		);
	}

	public function handle_rescan(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Forbidden', 'booklove' ) );
		}
		check_admin_referer( 'booklove_rescan' );
		$id = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0;
		if ( $id ) {
			$file = get_attached_file( $id );
			if ( $file ) {
				$meta = wp_generate_attachment_metadata( $id, $file );
				wp_update_attachment_metadata( $id, $meta );
				$this->hydrate_attachment_post( $id, $file );
				$this->ensure_ebook_document( $id );
			}
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'upload.php' ) );
		exit;
	}
}
