<?php
/**
 * Every sentence a finding shows to a site owner must go through WordPress translation.
 *
 * Runs the real scanner over every fixture with a translator that marks each translated
 * string, then requires the mark on every title, "why" text and fix step. A new check that
 * hard-codes English fails here.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Scanner\Scanner;

/**
 * Translation coverage.
 */
final class TranslationTest extends TestCase {

	const MARK = "\u{2192}";

	/**
	 * Remove the test translator.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['earlyward_test_translate'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- test hook.
	}

	/**
	 * Titles, why texts and fix steps of all fixture findings are translated.
	 */
	public function test_every_finding_text_is_translated(): void {
		$GLOBALS['earlyward_test_translate'] = static function ( string $text ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- test hook.
			return self::MARK . $text;
		};

		$seen = 0;
		foreach ( (array) glob( dirname( __DIR__ ) . '/fixtures/*', GLOB_ONLYDIR ) as $dir ) {
			$site = new Fixture_Site( (string) $dir );
			try {
				$result = Scanner::with_default_checks()->run( $site->context() );
				foreach ( $result['findings'] as $finding ) {
					$name = basename( (string) $dir ) . ' / ' . $finding->check();
					$this->assertStringStartsWith( self::MARK, $finding->title(), "Untranslated title: $name" );
					$this->assertStringStartsWith( self::MARK, $finding->why(), "Untranslated why: $name" );
					foreach ( $finding->fix_steps() as $step ) {
						$this->assertStringStartsWith( self::MARK, $step, "Untranslated step in $name: $step" );
					}
					++$seen;
				}
			} finally {
				$site->destroy();
			}
		}
		$this->assertGreaterThan( 40, $seen );
	}
}
