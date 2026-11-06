<?php
/**
 * Regression: found in the first real-WordPress smoke test (2026-11-07). The site root
 * and the path inside .user.ini can name the same directory differently (a symlinked
 * docroot, or "dir/sub/.." forms). Comparing strings misclassified a genuine Wordfence
 * line as an unknown loader.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Tests;

use PHPUnit\Framework\TestCase;
use Wallwarden\Env\Context;
use Wallwarden\Scanner\Scanner;

/**
 * Path spelling test.
 */
final class AutoPrependPathSpellingTest extends TestCase {

	/**
	 * Same directory, different spelling: still recognised as Wordfence's loader.
	 */
	public function test_wordfence_line_recognised_when_root_is_spelled_differently(): void {
		$site = new Fixture_Site( dirname( __DIR__ ) . '/fixtures/c1-benign-wordfence-only' );
		try {
			$odd_root = $site->root . '/wp-content/..';
			$ctx      = new Context( $odd_root, $odd_root . '/wp-content', $odd_root . '/wp-content/uploads', array() );
			$result   = Scanner::with_default_checks()->run( $ctx );
			$this->assertSame( array(), $result['errors'] );
			$this->assertCount( 0, $result['findings'] );
		} finally {
			$site->destroy();
		}
	}

	/**
	 * And a malicious line next to it is still caught, with a relative location.
	 */
	public function test_malicious_line_still_caught_and_reported_relative(): void {
		$site = new Fixture_Site( dirname( __DIR__ ) . '/fixtures/c1-hybrid-user-ini' );
		try {
			$odd_root = $site->root . '/wp-content/..';
			$ctx      = new Context( $odd_root, $odd_root . '/wp-content', $odd_root . '/wp-content/uploads', array() );
			$result   = Scanner::with_default_checks()->run( $ctx );
			$this->assertCount( 1, $result['findings'] );
			$where = array();
			foreach ( $result['findings'][0]->to_array()['evidence'] as $e ) {
				$where[] = $e['location'];
			}
			sort( $where );
			$this->assertSame( array( '.user.ini', 'wp-content/.user.ini', 'wp-content/a1b2c3d4.php' ), $where );
		} finally {
			$site->destroy();
		}
	}
}
