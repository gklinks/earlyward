<?php
/**
 * Summarises repeated scans: time and memory per check. Pure, so the arithmetic is unit-tested.
 *
 * @package Earlyward
 */

namespace Earlyward\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Cost statistics.
 */
final class Cost {

	/**
	 * Summarise several scan results.
	 *
	 * @param array<int, array{timings: array<string, float>, memory: array<string, int|null>}> $runs Results of Scanner::run(), one per scan.
	 * @return array{checks: array<string, array{median_s: float, max_s: float, peak_bytes: int|null}>, total_median_s: float, total_max_s: float, runs: int}
	 */
	public static function summarise( array $runs ): array {
		$times  = array();
		$memory = array();
		$totals = array();
		foreach ( $runs as $run ) {
			$totals[] = array_sum( $run['timings'] );
			foreach ( $run['timings'] as $id => $seconds ) {
				$times[ $id ][] = (float) $seconds;
			}
			foreach ( $run['memory'] as $id => $bytes ) {
				$memory[ $id ][] = $bytes;
			}
		}
		$checks = array();
		foreach ( $times as $id => $list ) {
			$known = array();
			foreach ( $memory[ $id ] ?? array() as $bytes ) {
				if ( null !== $bytes ) {
					$known[] = $bytes;
				}
			}
			$checks[ $id ] = array(
				'median_s'   => round( self::median( $list ), 4 ),
				'max_s'      => round( max( $list ), 4 ),
				'peak_bytes' => empty( $known ) ? null : (int) max( $known ),
			);
		}
		return array(
			'checks'         => $checks,
			'total_median_s' => empty( $totals ) ? 0.0 : round( self::median( $totals ), 4 ),
			'total_max_s'    => empty( $totals ) ? 0.0 : round( max( $totals ), 4 ),
			'runs'           => count( $runs ),
		);
	}

	/**
	 * Median of a non-empty list.
	 *
	 * @param float[] $values Values.
	 * @return float
	 */
	public static function median( array $values ): float {
		sort( $values );
		$n   = count( $values );
		$mid = intdiv( $n, 2 );
		return 0 === $n % 2 ? ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2 : $values[ $mid ];
	}
}
