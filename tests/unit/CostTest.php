<?php
/**
 * The arithmetic behind docs/COST.md.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Scanner\Cost;

/**
 * Cost statistics.
 */
final class CostTest extends TestCase {

	/**
	 * Build one run.
	 *
	 * @param float    $a1  A1 seconds.
	 * @param float    $c1  C1 seconds.
	 * @param int|null $mem A1 memory.
	 * @return array{timings: array<string, float>, memory: array<string, int|null>}
	 */
	private static function sample( float $a1, float $c1, ?int $mem ): array {
		return array(
			'timings' => array(
				'A1' => $a1,
				'C1' => $c1,
			),
			'memory'  => array(
				'A1' => $mem,
				'C1' => null,
			),
		);
	}

	/**
	 * Median of odd and even lists.
	 */
	public function test_median(): void {
		$this->assertSame( 2.0, Cost::median( array( 3.0, 1.0, 2.0 ) ) );
		$this->assertSame( 2.5, Cost::median( array( 4.0, 1.0, 3.0, 2.0 ) ) );
	}

	/**
	 * Median, max and peak memory per check; unmeasurable memory stays null, never zero.
	 */
	public function test_summarise(): void {
		$s = Cost::summarise( array( self::sample( 1.0, 0.1, 100 ), self::sample( 3.0, 0.3, 300 ), self::sample( 2.0, 0.2, 200 ) ) );
		$this->assertSame( 3, $s['runs'] );
		$this->assertSame( 2.0, $s['checks']['A1']['median_s'] );
		$this->assertSame( 3.0, $s['checks']['A1']['max_s'] );
		$this->assertSame( 300, $s['checks']['A1']['peak_bytes'] );
		$this->assertNull( $s['checks']['C1']['peak_bytes'] );
		$this->assertEqualsWithDelta( 2.2, $s['total_median_s'], 0.0001 );
		$this->assertEqualsWithDelta( 3.3, $s['total_max_s'], 0.0001 );
	}

	/**
	 * No runs: zeros, no crash.
	 */
	public function test_no_runs(): void {
		$s = Cost::summarise( array() );
		$this->assertSame( 0, $s['runs'] );
		$this->assertSame( 0.0, $s['total_median_s'] );
	}
}
