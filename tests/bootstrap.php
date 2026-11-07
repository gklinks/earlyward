<?php
/**
 * PHPUnit bootstrap for unit and fixture tests (no WordPress needed).
 *
 * @package Earlyward
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- stands in for WordPress in unit tests.
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stand-in for tests: returns the text unchanged.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Universal.NamingConventions.NoReservedKeywordParameterNames,Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- stands in for WordPress in unit tests.
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/src/class-autoloader.php';
\Earlyward\Autoloader::register( dirname( __DIR__ ) . '/src' );
require_once __DIR__ . '/support/class-fixture-database.php';
require_once __DIR__ . '/support/class-fixture-loopback.php';
require_once __DIR__ . '/support/class-fixture-site.php';
