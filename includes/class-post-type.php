<?php
/**
 * Ebook custom post type — the full document.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

final class Post_Type {

	public const POST_TYPE = 'ebook';

	public static function register(): void {
		$labels = array(
			'name'               => __( 'Ebooks', 'booklove' ),
			'singular_name'      => __( 'Ebook', 'booklove' ),
			'add_new'            => __( 'Add Ebook', 'booklove' ),
			'add_new_item'       => __( 'Add New Ebook', 'booklove' ),
			'edit_item'          => __( 'Edit Ebook', 'booklove' ),
			'new_item'           => __( 'New Ebook', 'booklove' ),
			'view_item'          => __( 'View Ebook', 'booklove' ),
			'search_items'       => __( 'Search Ebooks', 'booklove' ),
			'not_found'          => __( 'No ebooks found.', 'booklove' ),
			'not_found_in_trash' => __( 'No ebooks found in Trash.', 'booklove' ),
			'all_items'          => __( 'All Ebooks', 'booklove' ),
			'menu_name'          => __( 'Ebooks', 'booklove' ),
			'featured_image'     => __( 'Book cover', 'booklove' ),
			'set_featured_image' => __( 'Set book cover', 'booklove' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => true,
				'show_ui'             => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-book-alt',
				'menu_position'       => 20,
				'has_archive'         => 'ebooks',
				'rewrite'             => array(
					'slug'       => 'ebook',
					'with_front' => false,
				),
				'supports'            => array(
					'title',
					'editor',
					'excerpt',
					'thumbnail',
					'custom-fields',
					'revisions',
					'author',
				),
				'taxonomies'          => array(
					Taxonomies::AUTHOR,
					Taxonomies::SUBJECT,
					Taxonomies::SERIES,
					Taxonomies::FORMAT,
				),
				// Participate in WP search like posts/pages/documents.
				'exclude_from_search' => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);

		// Document-ish meta registered for REST + block editor sidebar.
		$self = new self();
		$self->register_meta();
	}

	private function register_meta(): void {
		$keys = array(
			'_booklove_attachment_id' => 'integer',
			'_booklove_format'        => 'string',
			'_booklove_isbn'          => 'string',
			'_booklove_doi'           => 'string',
			'_booklove_publisher'     => 'string',
			'_booklove_language'      => 'string',
			'_booklove_series'        => 'string',
			'_booklove_series_index'  => 'number',
			'_booklove_pubdate'       => 'string',
			'_booklove_page_count'    => 'integer',
			'_booklove_identifier'    => 'string',
			'_booklove_toc'           => 'string',
			'_booklove_ocr_status'    => 'string',
			'_booklove_cover_id'      => 'integer',
			'_booklove_confidence'    => 'number',
		);

		foreach ( $keys as $key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => static function (): bool {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}
}
