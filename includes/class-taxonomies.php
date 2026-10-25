<?php
/**
 * Book taxonomies.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

final class Taxonomies {

	public const AUTHOR  = 'ebook_author';
	public const SUBJECT = 'ebook_subject';
	public const SERIES  = 'ebook_series';
	public const FORMAT  = 'ebook_format';

	public static function register(): void {
		register_taxonomy(
			self::AUTHOR,
			Post_Type::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Authors', 'booklove' ),
					'singular_name' => __( 'Author', 'booklove' ),
				),
				'public'            => true,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => array( 'slug' => 'ebook-author' ),
				'show_admin_column' => true,
			)
		);

		register_taxonomy(
			self::SUBJECT,
			Post_Type::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Subjects', 'booklove' ),
					'singular_name' => __( 'Subject', 'booklove' ),
				),
				'public'            => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => array( 'slug' => 'ebook-subject' ),
				'show_admin_column' => true,
			)
		);

		register_taxonomy(
			self::SERIES,
			Post_Type::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Series', 'booklove' ),
					'singular_name' => __( 'Series', 'booklove' ),
				),
				'public'            => true,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => array( 'slug' => 'ebook-series' ),
				'show_admin_column' => true,
			)
		);

		register_taxonomy(
			self::FORMAT,
			Post_Type::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Formats', 'booklove' ),
					'singular_name' => __( 'Format', 'booklove' ),
				),
				'public'            => true,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => array( 'slug' => 'ebook-format' ),
				'show_admin_column' => true,
			)
		);
	}
}
