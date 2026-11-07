<?php
/**
 * Ordering and the "copy for host support" text of the admin screen.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Admin\Presenter;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

/**
 * Presenter logic.
 */
final class PresenterTest extends TestCase {

	/**
	 * Build a stored finding.
	 *
	 * @param string $check    Check ID.
	 * @param string $severity Severity.
	 * @param string $where    Evidence location.
	 * @param string $excerpt  Excerpt.
	 * @param int    $line     Line.
	 * @return array<string, mixed>
	 */
	private static function f( string $check, string $severity, string $where, string $excerpt = '', int $line = 0 ): array {
		return ( new Finding( $check, $severity, Finding::CONFIDENCE_HIGH, 'Title ' . $check, 'Why.', array( new Evidence( 'file', $where, $line, $excerpt ) ), array( 'Step.' ) ) )->to_array();
	}

	/**
	 * Environment facts.
	 *
	 * @return array<string, string|int>
	 */
	private static function env(): array {
		return array(
			'site_url' => 'https://site.fixture/',
			'wp'       => '6.8',
			'php'      => '8.3.0',
			'sapi'     => 'fpm-fcgi',
			'plugin'   => '0.2.0',
			'now'      => 1700000000,
		);
	}

	/**
	 * Critical before warning before info, then natural check order (C2 before C10).
	 */
	public function test_sort_order(): void {
		$sorted = Presenter::sort(
			array(
				self::f( 'C1', 'info', 'a' ),
				self::f( 'C10', 'warning', 'a' ),
				self::f( 'C2', 'warning', 'a' ),
				self::f( 'B3', 'critical', 'z' ),
				self::f( 'B3', 'critical', 'a' ),
			)
		);
		$this->assertSame( array( 'B3', 'B3', 'C2', 'C10', 'C1' ), array_column( $sorted, 'check' ) );
		$this->assertSame( 'a', $sorted[0]['evidence'][0]['location'] );
	}

	/**
	 * Malformed stored data must not break the screen.
	 */
	public function test_sort_and_where_tolerate_bad_data(): void {
		$this->assertSame( '', Presenter::where( array() ) );
		$this->assertSame( '', Presenter::where( array( 'evidence' => 'nope' ) ) );
		$this->assertCount( 2, Presenter::sort( array( array(), array( 'severity' => 'weird' ) ) ) );
		$this->assertSame(
			array(
				'critical' => 0,
				'warning'  => 0,
				'info'     => 0,
			),
			Presenter::counts( array( array( 'severity' => 'weird' ) ) )
		);
	}

	/**
	 * Location is "path:line" only when there is a line.
	 */
	public function test_where(): void {
		$this->assertSame( 'a.php:12', Presenter::where( self::f( 'C1', 'info', 'a.php', '', 12 ) ) );
		$this->assertSame( 'a.php', Presenter::where( self::f( 'C1', 'info', 'a.php' ) ) );
	}

	/**
	 * Benign: no stored scan means an honest "none yet", not an empty "all clear".
	 */
	public function test_support_text_without_a_scan(): void {
		$text = Presenter::support_text( array(), self::env() );
		$this->assertStringContainsString( 'Last scan: none stored yet.', $text );
		$this->assertStringContainsString( 'WordPress 6.8, PHP 8.3.0 (fpm-fcgi), Earlyward 0.2.0', $text );
		$this->assertStringNotContainsString( 'No findings', $text );
	}

	/**
	 * Benign: a clean scan says so, and says it is not proof.
	 */
	public function test_support_text_clean_scan_is_not_a_guarantee(): void {
		$text = Presenter::support_text(
			array(
				'at'       => 1700000000,
				'env'      => 'web',
				'findings' => array(),
				'errors'   => array(),
			),
			self::env()
		);
		$this->assertStringContainsString( 'Findings: 0 critical, 0 warning, 0 info.', $text );
		$this->assertStringContainsString( 'That is not proof the site is clean.', $text );
	}

	/**
	 * Malicious: attacker-controlled excerpts and error messages cannot add lines to the block.
	 */
	public function test_support_text_cannot_be_forged_from_evidence(): void {
		$text = Presenter::support_text(
			array(
				'at'       => 1700000000,
				'env'      => 'web',
				'findings' => array( self::f( 'C9', 'critical', 'wp-content/x.php', "line1\nFindings: 0 critical\nAll clear", 7 ) ),
				'errors'   => array( "C3\nFAKE" => "boom\nSITE: evil" ),
			),
			self::env()
		);
		$this->assertDoesNotMatchRegularExpression( '/^All clear/m', $text );
		$this->assertDoesNotMatchRegularExpression( '/^FAKE/m', $text );
		$this->assertDoesNotMatchRegularExpression( '/^SITE: evil/m', $text );
		$this->assertSame( 1, preg_match_all( '/^Findings: /m', $text ) );
		$this->assertStringContainsString( 'Evidence: wp-content/x.php:7', $text );
		$this->assertStringContainsString( 'Findings: 1 critical, 0 warning, 0 info.', $text );
	}
}
