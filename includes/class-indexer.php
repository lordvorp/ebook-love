<?php
/**
 * Full-text indexing — make ebooks trivially findable in WP search.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

final class Indexer {

	public const META_FULLTEXT = '_booklove_fulltext';

	public function hooks(): void {
		add_filter( 'posts_search', array( $this, 'extend_search' ), 10, 2 );
		add_filter( 'posts_join', array( $this, 'search_join' ), 10, 2 );
		add_filter( 'posts_distinct', array( $this, 'search_distinct' ), 10, 2 );
	}

	public function index_ebook( int $ebook_id, Book_Metadata $book ): void {
		$text = trim( $book->full_text ?: $book->text_sample );
		if ( $book->description ) {
			$text = $book->description . "\n\n" . $text;
		}
		if ( $book->toc ) {
			$text .= "\n\n" . $this->flatten_toc( $book->toc );
		}

		/**
		 * Filter text stored for search.
		 *
		 * @param string        $text
		 * @param int           $ebook_id
		 * @param Book_Metadata $book
		 */
		$text = (string) apply_filters( 'booklove_index_text', $text, $ebook_id, $book );

		// Cap meta size; WP postmeta is fine for hundreds of KB.
		$max = (int) apply_filters( 'booklove_index_max_bytes', 400000 );
		if ( strlen( $text ) > $max ) {
			$text = substr( $text, 0, $max );
		}

		update_post_meta( $ebook_id, self::META_FULLTEXT, $text );

		// Also nudge post_content search by ensuring excerpt carries keywords.
		if ( $book->subjects || $book->authors ) {
			$keywords = array_merge( $book->authors, $book->subjects );
			update_post_meta( $ebook_id, '_booklove_keywords', implode( ', ', $keywords ) );
		}
	}

	/**
	 * @param list<array{title:string,children?:array}> $toc
	 */
	private function flatten_toc( array $toc ): string {
		$lines = array();
		$walk  = static function ( array $items ) use ( &$walk, &$lines ): void {
			foreach ( $items as $item ) {
				if ( ! empty( $item['title'] ) ) {
					$lines[] = $item['title'];
				}
				if ( ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
					$walk( $item['children'] );
				}
			}
		};
		$walk( $toc );
		return implode( "\n", $lines );
	}

	/**
	 * Join postmeta so fulltext is searchable.
	 *
	 * @param string    $join
	 * @param \WP_Query $query
	 */
	public function search_join( string $join, \WP_Query $query ): string {
		if ( ! $this->should_extend( $query ) ) {
			return $join;
		}
		global $wpdb;
		$join .= " LEFT JOIN {$wpdb->postmeta} AS booklove_ft ON ({$wpdb->posts}.ID = booklove_ft.post_id AND booklove_ft.meta_key = '" . esc_sql( self::META_FULLTEXT ) . "') ";
		return $join;
	}

	/**
	 * @param string    $search
	 * @param \WP_Query $query
	 */
	public function extend_search( string $search, \WP_Query $query ): string {
		if ( ! $this->should_extend( $query ) || '' === $search ) {
			return $search;
		}
		global $wpdb;
		$terms = $query->get( 's' );
		if ( ! is_string( $terms ) || '' === trim( $terms ) ) {
			return $search;
		}

		$like = '%' . $wpdb->esc_like( $terms ) . '%';
		$extra = $wpdb->prepare( ' OR (booklove_ft.meta_value LIKE %s) ', $like );

		// Inject before the closing paren of posts_search when possible.
		$search = preg_replace( '/\)\s*$/', $extra . ')', $search, 1 ) ?? ( $search . $extra );
		return $search;
	}

	/**
	 * @param string    $distinct
	 * @param \WP_Query $query
	 */
	public function search_distinct( string $distinct, \WP_Query $query ): string {
		if ( $this->should_extend( $query ) ) {
			return 'DISTINCT';
		}
		return $distinct;
	}

	private function should_extend( \WP_Query $query ): bool {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return false;
		}
		return true;
	}
}
