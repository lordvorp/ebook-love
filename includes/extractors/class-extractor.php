<?php
/**
 * Extractor contract.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove\Extractors;

use Booklove\Book_Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * One format → Book_Metadata. Keep side-effect free (no WP inserts).
 */
interface Extractor {

	/**
	 * @param string               $path Absolute filesystem path.
	 * @param array<string,mixed>  $args Options: extract_cover, extract_text, extract_toc, max_text_bytes.
	 */
	public function extract( string $path, array $args = array() ): Book_Metadata;

	/**
	 * Whether this extractor can handle the path (extension / magic).
	 */
	public function supports( string $path ): bool;
}
