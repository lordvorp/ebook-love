<?php
/**
 * Admin UI — media library + ebook edit screens.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public function __construct(
		private Ingestor $ingestor
	) {}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
		add_filter( 'manage_media_columns', array( $this, 'media_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'media_column_content' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'save_post_' . Post_Type::POST_TYPE, array( $this, 'save_ebook_meta' ), 10, 2 );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::POST_TYPE,
			__( 'Booklove settings', 'booklove' ),
			__( 'Settings', 'booklove' ),
			'manage_options',
			'booklove-settings',
			array( $this, 'render_settings' )
		);
	}

	public function assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'upload.php', 'ebook_page_booklove-settings' ), true ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || Post_Type::POST_TYPE !== $screen->post_type ) {
				return;
			}
		}
		wp_enqueue_style(
			'booklove-admin',
			BOOKLOVE_URL . 'assets/css/admin.css',
			array(),
			BOOKLOVE_VERSION
		);
	}

	public function meta_boxes(): void {
		add_meta_box(
			'booklove_document',
			__( 'Ebook document', 'booklove' ),
			array( $this, 'render_document_metabox' ),
			Post_Type::POST_TYPE,
			'side',
			'high'
		);
	}

	public function render_document_metabox( \WP_Post $post ): void {
		$attachment_id = (int) get_post_meta( $post->ID, '_booklove_attachment_id', true );
		$format        = (string) get_post_meta( $post->ID, '_booklove_format', true );
		$isbn          = (string) get_post_meta( $post->ID, '_booklove_isbn', true );
		$ocr           = (string) get_post_meta( $post->ID, '_booklove_ocr_status', true );
		$toc_json      = (string) get_post_meta( $post->ID, '_booklove_toc', true );
		$toc           = json_decode( $toc_json, true );
		$toc_count     = is_array( $toc ) ? count( $toc ) : 0;

		wp_nonce_field( 'booklove_save_ebook', 'booklove_nonce' );

		echo '<p class="booklove-meta">';
		printf(
			'<label>%s<br><input type="text" class="widefat" name="booklove_isbn" value="%s" /></label>',
			esc_html__( 'ISBN', 'booklove' ),
			esc_attr( $isbn )
		);
		echo '</p>';

		echo '<ul class="booklove-meta-list">';
		printf( '<li><strong>%s</strong> %s</li>', esc_html__( 'Format:', 'booklove' ), esc_html( strtoupper( $format ) ?: '—' ) );
		printf( '<li><strong>%s</strong> %s</li>', esc_html__( 'OCR:', 'booklove' ), esc_html( $ocr ?: 'none' ) );
		printf( '<li><strong>%s</strong> %d</li>', esc_html__( 'TOC entries:', 'booklove' ), $toc_count );
		echo '</ul>';

		if ( $attachment_id ) {
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( get_edit_post_link( $attachment_id ) ?: '#' ),
				esc_html__( 'Open source file', 'booklove' )
			);
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=booklove_rescan&attachment_id=' . $attachment_id ),
						'booklove_rescan'
					)
				),
				esc_html__( 'Re-scan metadata', 'booklove' )
			);
			if ( in_array( $ocr, array( 'pending', 'unsupported', 'none' ), true ) ) {
				printf(
					'<p><a href="%s">%s</a></p>',
					esc_url(
						wp_nonce_url(
							admin_url(
								'admin-post.php?action=booklove_request_ocr&attachment_id=' . $attachment_id . '&ebook_id=' . $post->ID
							),
							'booklove_request_ocr'
						)
					),
					esc_html__( 'Request OCR', 'booklove' )
				);
			}
		} else {
			echo '<p class="description">' . esc_html__( 'Upload an ebook via Media → Add New. Booklove turns the file into this kind of document automatically.', 'booklove' ) . '</p>';
		}
	}

	public function save_ebook_meta( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['booklove_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['booklove_nonce'] ) ), 'booklove_save_ebook' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['booklove_isbn'] ) ) {
			update_post_meta( $post_id, '_booklove_isbn', sanitize_text_field( wp_unslash( $_POST['booklove_isbn'] ) ) );
		}
	}

	/**
	 * @param array<string,string> $cols
	 * @return array<string,string>
	 */
	public function media_columns( array $cols ): array {
		$cols['booklove'] = __( 'Ebook', 'booklove' );
		return $cols;
	}

	public function media_column_content( string $column, int $post_id ): void {
		if ( 'booklove' !== $column ) {
			return;
		}
		if ( ! $this->ingestor->is_book_attachment( $post_id ) ) {
			echo '—';
			return;
		}
		$ebook_id = (int) get_post_meta( $post_id, '_booklove_ebook_id', true );
		$format   = (string) get_post_meta( $post_id, '_booklove_format', true );
		echo esc_html( strtoupper( $format ) );
		if ( $ebook_id ) {
			printf(
				'<br><a href="%s">%s</a>',
				esc_url( get_edit_post_link( $ebook_id ) ?: '#' ),
				esc_html__( 'Document', 'booklove' )
			);
		}
	}

	public function render_settings(): void {
		$formats = booklove()->formats->supported_list();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Booklove', 'booklove' ); ?></h1>
			<p><?php esc_html_e( 'Upload EPUB, MOBI, AZW, PDF, TXT, RTF, FB2, CBZ, DOCX — WordPress treats them like documents (title, cover, TOC, searchable text), not dead files.', 'booklove' ); ?></p>
			<p><strong><?php esc_html_e( 'Supported formats:', 'booklove' ); ?></strong> <?php echo esc_html( $formats ); ?></p>
			<h2><?php esc_html_e( 'How it works', 'booklove' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Upload a book via Media Library (same path as images/audio).', 'booklove' ); ?></li>
				<li><?php esc_html_e( 'Booklove reads metadata (Calibre-style), fills attachment title/content, extracts cover + TOC.', 'booklove' ); ?></li>
				<li><?php esc_html_e( 'An Ebook document post is created/updated and indexed for site search.', 'booklove' ); ?></li>
				<li><?php esc_html_e( 'OCR is a hook — plug in Tesseract or a cloud provider later via booklove_ocr_extract.', 'booklove' ); ?></li>
			</ol>
		</div>
		<?php
	}
}
