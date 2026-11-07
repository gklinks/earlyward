<?php
/**
 * PHPUnit bootstrap for unit and fixture tests (no WordPress needed).
 *
 * @package Wallwarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- stands in for WordPress in unit tests.
}

require_once dirname( __DIR__ ) . '/src/class-autoloader.php';
\Wallwarden\Autoloader::register( dirname( __DIR__ ) . '/src' );
require_once __DIR__ . '/support/class-fixture-database.php';
require_once __DIR__ . '/support/class-fixture-site.php';
