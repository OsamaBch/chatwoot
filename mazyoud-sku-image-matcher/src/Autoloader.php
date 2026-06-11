<?php
/**
 * PSR-4 style class autoloader (no Composer dependency at runtime).
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the Mazyoud\SkuImageMatcher\* namespace to the src/ directory.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 *
	 * @var string
	 */
	private const PREFIX = 'Mazyoud\\SkuImageMatcher\\';

	/**
	 * Register the autoloader with SPL.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Load a class file for a fully qualified class name within our namespace.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
