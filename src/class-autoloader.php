<?php
/**
 * Minimal autoloader. No runtime Composer dependency.
 *
 * Maps Earlyward\Checks\Integrity\Auto_Prepend_Check to
 * src/checks/integrity/class-auto-prepend-check.php (WordPress file naming).
 *
 * @package Earlyward
 */

namespace Earlyward;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader for the Earlyward namespace.
 */
final class Autoloader {

	/**
	 * Base directory of the src tree.
	 *
	 * @var string
	 */
	private static $base = '';

	/**
	 * Register the autoloader.
	 *
	 * @param string $base Absolute path to src/.
	 */
	public static function register( string $base ): void {
		self::$base = rtrim( $base, '/\\' );
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load one class, interface or trait.
	 *
	 * @param string $class_name Fully qualified name.
	 */
	public static function load( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name  = strtolower( str_replace( '_', '-', (string) array_pop( $parts ) ) );
		$dir   = self::$base;
		foreach ( $parts as $part ) {
			$dir .= '/' . strtolower( str_replace( '_', '-', $part ) );
		}
		foreach ( array( 'class-', 'interface-', 'trait-' ) as $kind ) {
			$file = $dir . '/' . $kind . $name . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
