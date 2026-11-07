<?php
/**
 * Runs the full default scanner (the real entry point) against every fixture case and
 * requires the exact expected set of findings: missing findings and extra findings
 * (false positives) both fail.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Scanner\Scanner;

/**
 * Fixtures suite.
 */
final class FixturesTest extends TestCase {

	/**
	 * Every case directory.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function cases(): array {
		$out = array();
		foreach ( (array) glob( dirname( __DIR__ ) . '/fixtures/*', GLOB_ONLYDIR ) as $dir ) {
			$out[ basename( (string) $dir ) ] = array( (string) $dir );
		}
		return $out;
	}

	/**
	 * The scanner returns exactly the expected findings.
	 *
	 * @dataProvider cases
	 * @param string $case_dir Case directory.
	 */
	public function test_case( string $case_dir ): void {
		$site = new Fixture_Site( $case_dir );
		try {
			$result = Scanner::with_default_checks()->run( $site->context() );
			$this->assertSame( array(), $result['errors'], 'A check failed to run.' );

			$actual = array();
			foreach ( $result['findings'] as $finding ) {
				$a     = $finding->to_array();
				$where = array();
				foreach ( $a['evidence'] as $e ) {
					$where[] = $e['location'] . ( $e['line'] ? ':' . $e['line'] : '' );
				}
				sort( $where );
				$actual[] = array(
					'check'      => $a['check'],
					'severity'   => $a['severity'],
					'confidence' => $a['confidence'],
					'where'      => $where,
				);
			}
			$expected = $site->expected();
			foreach ( $expected as &$item ) {
				sort( $item['where'] );
			}
			unset( $item );

			$this->assertSame( self::sorted( $expected ), self::sorted( $actual ) );
		} finally {
			$site->destroy();
		}
	}

	/**
	 * Order-independent comparison.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sorted( array $items ): array {
		usort(
			$items,
			static function ( $a, $b ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in unit tests.
				return strcmp( (string) json_encode( $a ), (string) json_encode( $b ) );
			}
		);
		return $items;
	}
}
