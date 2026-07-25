<?php
/**
 * PSR-4 autoloader.
 *
 * M0 has no Composer dependencies, so the plugin loads its own classes. When
 * Composer arrives this file is replaced by `vendor/autoload.php` and nothing
 * else changes — the namespace layout is already PSR-4.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `UnusedImageCleaner\Foo\Bar` onto `src/Foo/Bar.php`, PSR-4 style.
 */
final class Autoloader {

	private const PREFIX = 'UnusedImageCleaner\\';

	/**
	 * Register this class with PHP's autoload chain.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Require the file this class name maps to, if it exists.
	 *
	 * @param string $class_name Fully-qualified class name being requested.
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$path     = UIC_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
