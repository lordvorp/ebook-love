<?php
/**
 * Public ebook templates + download link.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	public function hooks(): void {
		add_filter( 'the_content', array( $this, 'append_document_chrome' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_shortcode( 'booklove_toc', array( $this, 'shortcode_toc' ) );
		add_shortcode( 'booklove_cover', array( $this, 'shortcode_cover' ) );
	}

	public function assets(): void {
		if ( ! is_singular( Post_Type::POST_TYPE ) && ! is_post_type_archive( Post_Type::POST_TYPE ) ) {
			return;
		}
		wp_enqueue_style(
			'booklove-public',
			BOOKLOVE_URL . 'assets/css/public.css',
			array(),
			BOOKLOVE_VERSION
		);
	}

	public function append_document_chrome( string $content ): string {
		if ( ! is_singular( Post_Type::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id       = get_the_ID();
		$attachment_id = (int) get_post_meta( $post_id, '_booklove_attachment_id', true );
		$format        = (string) get_post_meta( $post_id, '_booklove_format', true );
		$isbn          = (string) get_post_meta( $post_id, '_booklove_isbn', true );
		$publisher     = (string) get_post_meta( $post_id, '_booklove_publisher', true );
		$pages         = (int) get_post_meta( $post_id, '_booklove_page_count', true );

		ob_start();
		echo '<aside class="booklove-doc-meta">';
		echo '<dl>';
		if ( $format ) {
			printf( '<dt>%s</dt><dd>%s</dd>', esc_html__( 'Format', 'booklove' ), esc_html( strtoupper( $format ) ) );
		}
		if ( $isbn ) {
			printf( '<dt>%s</dt><dd>%s</dd>', esc_html__( 'ISBN', 'booklove' ), esc_html( $isbn ) );
		}
		if ( $publisher ) {
			printf( '<dt>%s</dt><dd>%s</dd>', esc_html__( 'Publisher', 'booklove' ), esc_html( $publisher ) );
		}
		if ( $pages ) {
			printf( '<dt>%s</dt><dd>%s</dd>', esc_html__( 'Pages', 'booklove' ), esc_html( (string) $pages ) );
		}
		$authors = get_the_term_list( $post_id, Taxonomies::AUTHOR, '', ', ' );
		if ( $authors && ! is_wp_error( $authors ) ) {
			printf( '<dt>%s</dt><dd>%s</dd>', esc_html__( 'Authors', 'booklove' ), wp_kses_post( $authors ) );
		}
		echo '</dl>';

		if ( $attachment_id ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				printf(
					'<p class="booklove-download"><a class="booklove-download__btn" href="%s">%s</a></p>',
					esc_url( $url ),
					esc_html__( 'Download ebook file', 'booklove' )
				);
			}
		}
		echo '</aside>';

		return ob_get_clean() . $content;
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public function shortcode_toc( $atts ): string {
		$atts    = shortcode_atts( array( 'id' => get_the_ID() ), (array) $atts, 'booklove_toc' );
		$post_id = (int) $atts['id'];
		$raw     = (string) get_post_meta( $post_id, '_booklove_toc', true );
		$toc     = json_decode( $raw, true );
		if ( ! is_array( $toc ) || ! $toc ) {
			return '';
		}
		return '<nav class="booklove-toc" aria-label="' . esc_attr__( 'Table of contents', 'booklove' ) . '"><ul>' .
			$this->toc_html( $toc ) . '</ul></nav>';
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public function shortcode_cover( $atts ): string {
		$atts    = shortcode_atts( array( 'id' => get_the_ID(), 'size' => 'medium' ), (array) $atts, 'booklove_cover' );
		$post_id = (int) $atts['id'];
		if ( ! has_post_thumbnail( $post_id ) ) {
			return '';
		}
		return get_the_post_thumbnail( $post_id, $atts['size'], array( 'class' => 'booklove-cover' ) );
	}

	/**
	 * @param list<array{title:string,children?:array}> $items
	 */
	private function toc_html( array $items ): string {
		$html = '';
		foreach ( $items as $item ) {
			$html .= '<li>' . esc_html( (string) ( $item['title'] ?? '' ) );
			if ( ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
				$html .= '<ul>' . $this->toc_html( $item['children'] ) . '</ul>';
			}
			$html .= '</li>';
		}
		return $html;
	}
}
