<?php
/**
 * "Email only when the set of findings changes."
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;
use Earlyward\Notify\Alert;

/**
 * Alert diff and email body.
 */
final class AlertTest extends TestCase {

	/**
	 * Build a finding.
	 *
	 * @param string $severity Severity.
	 * @param string $where    Evidence location.
	 * @param string $title    Title.
	 * @param string $excerpt  Excerpt.
	 * @return Finding
	 */
	private static function f( string $severity, string $where, string $title = 'Title', string $excerpt = '' ): Finding {
		return new Finding( 'C9', $severity, Finding::CONFIDENCE_HIGH, $title, 'Why.', array( new Evidence( 'file', $where, 0, $excerpt ) ), array( 'Step one.' ) );
	}

	/**
	 * Same set twice: no change, so no email.
	 */
	public function test_same_set_is_not_a_change(): void {
		$first  = Alert::diff( array(), array( self::f( 'critical', 'a.php' ) ) );
		$second = Alert::diff( $first['current'], array( self::f( 'critical', 'a.php' ) ) );
		$this->assertTrue( $first['changed'] );
		$this->assertFalse( $second['changed'] );
	}

	/**
	 * Rewording a title must not re-alert: the fingerprint is what and where, not wording.
	 */
	public function test_copy_change_is_not_a_change(): void {
		$first  = Alert::diff( array(), array( self::f( 'critical', 'a.php', 'Old wording' ) ) );
		$second = Alert::diff( $first['current'], array( self::f( 'critical', 'a.php', 'New wording' ) ) );
		$this->assertFalse( $second['changed'] );
	}

	/**
	 * Info findings never alert.
	 */
	public function test_info_never_alerts(): void {
		$diff = Alert::diff( array(), array( self::f( 'info', 'mu.php' ) ) );
		$this->assertFalse( $diff['changed'] );
		$this->assertSame( array(), $diff['current'] );
	}

	/**
	 * New and resolved are both changes, and both are listed.
	 */
	public function test_new_and_resolved(): void {
		$first  = Alert::diff( array(), array( self::f( 'critical', 'a.php', 'A' ) ) );
		$second = Alert::diff( $first['current'], array( self::f( 'warning', 'b.php', 'B' ) ) );
		$this->assertTrue( $second['changed'] );
		$this->assertCount( 1, $second['new'] );
		$this->assertCount( 1, $second['resolved'] );
		$mail = Alert::compose( 'Site', 'https://site.fixture/', $second );
		$this->assertStringContainsString( '1 new, 1 resolved (0 critical open)', $mail['subject'] );
		$this->assertStringContainsString( '== NO LONGER FOUND ==', $mail['body'] );
		$this->assertStringContainsString( '- A (check C9)', $mail['body'] );
	}

	/**
	 * Attacker-controlled excerpts cannot inject extra lines (or headers) into the email.
	 */
	public function test_excerpt_cannot_inject_lines(): void {
		$diff = Alert::diff( array(), array( self::f( 'critical', 'a.php', "Title\r\nBcc: x@evil.fixture", "line1\nFAKE: all clear" ) ) );
		$mail = Alert::compose( "Site\nX", 'https://site.fixture/', $diff );
		$this->assertStringNotContainsString( "\n", $mail['subject'] );
		$this->assertDoesNotMatchRegularExpression( '/^FAKE:/m', $mail['body'] );
		$this->assertDoesNotMatchRegularExpression( '/^Bcc:/m', $mail['body'] );
	}
}
