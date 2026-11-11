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
		return isset( $GLOBALS['earlyward_test_translate'] ) ? (string) call_user_func( $GLOBALS['earlyward_test_translate'], $text ) : $text; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- test hook.
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Plural translation stand-in for tests: English rule (1 is singular).
	 *
	 * @param string $single Singular text.
	 * @param string $plural Plural text.
	 * @param int    $number Number.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Universal.NamingConventions.NoReservedKeywordParameterNames,Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- stands in for WordPress in unit tests.
		$text = 1 === (int) $number ? $single : $plural;
		return isset( $GLOBALS['earlyward_test_translate'] ) ? (string) call_user_func( $GLOBALS['earlyward_test_translate'], $text ) : $text; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- test hook.
	}
}

require_once dirname( __DIR__ ) . '/src/class-autoloader.php';
\Earlyward\Autoloader::register( dirname( __DIR__ ) . '/src' );
require_once __DIR__ . '/support/class-fixture-database.php';
require_once __DIR__ . '/support/class-fixture-loopback.php';
require_once __DIR__ . '/support/class-memory-post-store.php';
require_once __DIR__ . '/support/class-fixture-site.php';
