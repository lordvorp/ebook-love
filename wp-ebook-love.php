<?php
/**
 * Plugin Name:       Booklove
 * Plugin URI:        https://github.com/lordvorp/wp-ebook-love
 * Description:       Make WordPress love ebooks — EPUB, MOBI, AZW, PDF, TXT, RTF and friends. Metadata, covers, TOC, full-text indexing, and an OCR hook for later.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Rob
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       booklove
 * Domain Path:       /languages
 *
 * Architecture notes (Calibre-inspired):
 * - Format registry with metadata priority (see includes/class-format-registry.php)
 * - Per-format extractors return a Book_Metadata bag that smart-merges
 * - Filename pattern fallback: "Title - Author.ext"
 * - EPUB via OCF container.xml → OPF → NCX/nav TOC + raster cover
 * - OCR is intentionally a filter hook, not baked-in
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BOOKLOVE_VERSION', '0.1.0' );
define( 'BOOKLOVE_FILE', __FILE__ );
define( 'BOOKLOVE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BOOKLOVE_URL', plugin_dir_url( __FILE__ ) );
define( 'BOOKLOVE_BASENAME', plugin_basename( __FILE__ ) );

require_once BOOKLOVE_PATH . 'includes/class-autoloader.php';
Booklove\Autoloader::register();

/**
 * Bootstrap the plugin.
 */
function booklove(): Booklove\Plugin {
	return Booklove\Plugin::instance();
}

booklove();

register_activation_hook( __FILE__, array( Booklove\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Booklove\Plugin::class, 'deactivate' ) );
