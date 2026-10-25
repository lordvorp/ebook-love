<?php
/**
 * PSR-4-ish autoloader for the Booklove namespace.
 *
 * @package Booklove
 */

declare(strict_types=1);

namespace Booklove;

defined( 'ABSPATH' ) || exit;

/**
 * Maps Booklove\Foo_Bar → includes/class-foo-bar.php
 * and Booklove\Extractors\Epub → includes/extractors/class-epub.php
 */
final class Autoloader {

	/**
	 * Register spl autoload.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * @param string $class Fully-qualified class name.
	 */
	public static function load( string $class ): void {
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$parts    = explode( '\\', $relative );
		$class_part = array_pop( $parts );
		$subdir     = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';

		// Booklove\Extractors\Epub → class-epub.php under extractors/
		$slug = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_part ) ?? $class_part );
		$slug = str_replace( '_', '-', $slug );

		$path = BOOKLOVE_PATH . 'includes/' . $subdir . 'class-' . $slug . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
