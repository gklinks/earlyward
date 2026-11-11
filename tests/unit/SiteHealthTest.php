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
	/**
	 * A check "found nothing" only if it ran, reported nothing and did not fail.
	 */
	public function test_passed_ids(): void {
		$last = array(
			'timings'  => array(
				'A1' => 0.1,
				'C1' => 0.1,
				'C9' => 0.1,
				'B1' => 0.1,
			),
			'errors'   => array( 'C1' => 'Error: boom' ),
			'findings' => array( self::f( 'info' ) ), // From check C9.
		);
		$this->assertSame( array( 'A1', 'B1' ), Site_Health::passed_ids( $last ) );
		$r = Site_Health::passed_result( $last, self::URL );
		$this->assertSame( 'good', $r['status'] );
		$this->assertStringContainsString( 'A1: ', $r['description'] );
		$this->assertStringNotContainsString( 'C1: ', $r['description'] );
	}

	/**
	 * C18 has a name in the "found nothing" list, so a clean C18 run is listed like the other checks.
	 */
	public function test_seo_spam_check_is_in_the_found_nothing_list(): void {
		$names = Site_Health::check_names();
		$this->assertArrayHasKey( 'C18', $names );
		$this->assertNotSame( '', $names['C18'] );

		$last = array(
			'timings'  => array(
				'C18' => 0.1,
				'C9'  => 0.1,
			),
			'findings' => array( self::f( 'info' ) ), // From check C9.
		);
		$this->assertSame( array( 'C18' ), Site_Health::passed_ids( $last ) );
		$r = Site_Health::passed_result( $last, self::URL );
		$this->assertStringContainsString( 'C18: ' . $names['C18'], $r['description'] );
	}

	/**
	 * A failed check is reported, so fail-open never means silently unchecked.
	 */
	public function test_failed_result_lists_the_check(): void {
		$r = Site_Health::failed_result( array( 'errors' => array( 'C3' => '<b>x</b>' ) ), self::URL );
		$this->assertSame( 'recommended', $r['status'] );
		$this->assertStringContainsString( 'C3: ', $r['description'] );
		$this->assertStringNotContainsString( '<b>', $r['description'], 'The error text is not shown here.' );
	}

	/**
	 * The Info tab fields are plain facts.
	 */
	public function test_debug_fields(): void {
		$fields = Site_Health::debug_fields(
			array(
				'timings'  => array( 'A1' => 1 ),
				'findings' => array( self::f( 'warning' ) ),
			),
			'0.1.0',
			'2026-11-07 12:00:00 UTC',
			''
		);
		$this->assertSame( '0.1.0', $fields['Version'] );
		$this->assertSame( 'not scheduled', $fields['Next scan'] );
		$this->assertSame( '0 critical, 1 warning, 0 info', $fields['Findings'] );
		$this->assertSame( 'never', Site_Health::debug_fields( array(), '0.1.0', '', '' )['Last scan'] );
	}
}
