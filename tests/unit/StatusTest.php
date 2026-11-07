<?php
/**
 * Admin bar badge logic: level, label and node.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Admin\Status;

/**
 * Status summary.
 */
final class StatusTest extends TestCase {

	const NOW = 1700000000;

	/**
	 * Build a stored scan.
	 *
	 * @param string[] $severities Severity of each finding.
	 * @param int      $at         Scan time.
	 * @return array<string, mixed>
	 */
	private function last( array $severities, int $at ): array {
		$findings = array();
		foreach ( $severities as $s ) {
			$findings[] = array( 'severity' => $s );
		}
		return array(
			'at'       => $at,
			'findings' => $findings,
		);
	}

	/**
	 * Worst severity wins; a quiet but old scan is stale; nothing stored is "none".
	 */
	public function test_levels(): void {
		$this->assertSame( 'none', Status::summary( array(), self::NOW )['level'] );
		$this->assertSame( 'ok', Status::summary( $this->last( array( 'info' ), self::NOW - 60 ), self::NOW )['level'] );
		$this->assertSame( 'warning', Status::summary( $this->last( array( 'info', 'warning' ), self::NOW - 60 ), self::NOW )['level'] );
		$this->assertSame( 'critical', Status::summary( $this->last( array( 'warning', 'critical' ), self::NOW - 60 ), self::NOW )['level'] );
		$this->assertSame( 'stale', Status::summary( $this->last( array(), self::NOW - 20000 ), self::NOW )['level'] );
		$this->assertSame( 'critical', Status::summary( $this->last( array( 'critical' ), self::NOW - 20000 ), self::NOW )['level'], 'A real finding is never hidden by age.' );
	}

	/**
	 * Labels count findings and singular/plural read right.
	 */
	public function test_labels(): void {
		$one = Status::summary( $this->last( array( 'warning' ), self::NOW ), self::NOW );
		$two = Status::summary( $this->last( array( 'warning', 'warning' ), self::NOW ), self::NOW );
		$this->assertSame( 'Earlyward: 1 warning', Status::label( $one ) );
		$this->assertSame( 'Earlyward: 2 warnings', Status::label( $two ) );
		$this->assertSame( 'Earlyward: all clear', Status::label( Status::summary( $this->last( array(), self::NOW ), self::NOW ) ) );
	}

	/**
	 * The node carries the level as a class and escapes the label.
	 */
	public function test_node(): void {
		$node = Status::bar_node( Status::summary( $this->last( array( 'critical' ), self::NOW ), self::NOW ), 'https://example.test/wp-admin/tools.php?page=earlyward' );
		$this->assertSame( 'earlyward', $node['id'] );
		$this->assertSame( 'earlyward-critical', $node['meta']['class'] );
		$this->assertStringContainsString( 'Earlyward: 1 critical', $node['title'] );
	}
}
