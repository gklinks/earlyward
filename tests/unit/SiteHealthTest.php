<?php
/**
 * Site Health results built from stored findings.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Admin\Site_Health;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

/**
 * Site Health result builders.
 */
final class SiteHealthTest extends TestCase {

	const URL = 'https://site.fixture/wp-admin/tools.php?page=earlyward';

	/**
	 * Build a stored finding.
	 *
	 * @param string $severity Severity.
	 * @param string $title    Title.
	 * @param string $excerpt  Excerpt.
	 * @return array<string, mixed>
	 */
	private static function f( string $severity, string $title = 'Title', string $excerpt = '' ): array {
		return ( new Finding( 'C9', $severity, Finding::CONFIDENCE_HIGH, $title, 'Why.', array( new Evidence( 'file', 'wp-content/x.php', 4, $excerpt ) ), array( 'Step one.' ) ) )->to_array();
	}

	/**
	 * Critical maps to "critical", warning to "recommended".
	 */
	public function test_status_mapping(): void {
		$this->assertSame( 'critical', Site_Health::finding_result( self::f( 'critical' ), self::URL )['status'] );
		$this->assertSame( 'recommended', Site_Health::finding_result( self::f( 'warning' ), self::URL )['status'] );
	}

	/**
	 * The result carries the evidence location and the fix steps.
	 */
	public function test_result_shows_evidence_and_steps(): void {
		$r = Site_Health::finding_result( self::f( 'critical' ), self::URL );
		$this->assertStringContainsString( 'wp-content/x.php:4', $r['description'] );
		$this->assertStringContainsString( '<li>Step one.</li>', $r['description'] );
		$this->assertStringContainsString( self::URL, $r['actions'] );
	}

	/**
	 * Malicious: markup in a title or evidence cannot become live HTML in Site Health, which prints
	 * labels and descriptions unescaped.
	 */
	public function test_untrusted_text_is_escaped(): void {
		$bad = '<script>alert(1)</script>';
		$r   = Site_Health::finding_result( self::f( 'critical', $bad ), self::URL );
		$this->assertStringNotContainsString( '<script>', $r['label'] );
		$this->assertStringNotContainsString( '<script>', $r['description'] );

		$evil               = self::f( 'critical' );
		$evil['evidence'][] = array(
			'kind'     => 'file',
			'location' => '"><img src=x onerror=alert(1)>',
			'line'     => 0,
			'excerpt'  => '',
			'facts'    => array(),
		);
		$this->assertStringNotContainsString( '<img', Site_Health::finding_result( $evil, self::URL )['description'] );
	}

	/**
	 * Benign: no scan yet is "recommended", never a green "good".
	 */
	public function test_summary_without_a_scan_is_not_good(): void {
		$this->assertSame( 'recommended', Site_Health::summary_result( array(), self::URL )['status'] );
	}

	/**
	 * Benign: a clean scan is "good" but says it is not proof, and info findings do not turn it red.
	 */
	public function test_summary_clean_and_info_only(): void {
		$r = Site_Health::summary_result(
			array(
				'at'       => 1,
				'findings' => array( self::f( 'info' ) ),
			),
			self::URL
		);
		$this->assertSame( 'good', $r['status'] );
		$this->assertStringContainsString( 'not proof', $r['description'] );
	}

	/**
	 * Summary reflects the worst open severity.
	 */
	public function test_summary_with_findings(): void {
		$warn = Site_Health::summary_result(
			array(
				'at'       => 1,
				'findings' => array( self::f( 'warning' ) ),
			),
			self::URL
		);
		$crit = Site_Health::summary_result(
			array(
				'at'       => 1,
				'findings' => array( self::f( 'warning' ), self::f( 'critical' ) ),
			),
			self::URL
		);
		$this->assertSame( 'recommended', $warn['status'] );
		$this->assertSame( 'critical', $crit['status'] );
		$this->assertStringContainsString( '1 critical and 1 warning', $crit['label'] );
	}

	/**
	 * Freshness: recent and scheduled is good; stale or unscheduled is not.
	 */
	public function test_freshness(): void {
		$now = 1700000000;
		$this->assertSame( 'good', Site_Health::freshness_result( array( 'at' => $now - 600 ), $now + 600, $now, self::URL )['status'] );
		$this->assertSame( 'good', Site_Health::freshness_result( array(), $now + 300, $now, self::URL )['status'] );
		$this->assertSame( 'recommended', Site_Health::freshness_result( array( 'at' => $now - 4 * 3600 ), $now + 600, $now, self::URL )['status'] );
		$this->assertSame( 'recommended', Site_Health::freshness_result( array( 'at' => $now - 60 ), false, $now, self::URL )['status'] );
	}
}
