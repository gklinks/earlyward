<?php
/**
 * Reading the newest backup from UpdraftPlus's history, for the panel above update forms.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Fix\Backup;

/**
 * Tests.
 */
final class BackupTest extends TestCase {

	/**
	 * The newest entry wins, with its remote storage; "none" is not remote.
	 */
	public function test_newest(): void {
		$this->assertNull( Backup::newest( null ) );
		$this->assertNull( Backup::newest( array() ) );
		$this->assertNull( Backup::newest( array( 'x' => array() ) ) );

		$got = Backup::newest(
			array(
				1700000000   => array( 'service' => array( 'none' ) ),
				1790000000   => array( 'service' => array( 'googledrive' ) ),
				'1600000000' => array( 'service' => 'none' ),
			)
		);
		$this->assertSame( 1790000000, $got['at'] );
		$this->assertSame( array( 'googledrive' ), $got['remote'] );

		$local = Backup::newest( array( 1790000000 => array( 'service' => 'none' ) ) );
		$this->assertSame( array(), $local['remote'], 'Local only.' );

		$bare = Backup::newest( array( 1790000000 => 'not an array' ) );
		$this->assertSame( array(), $bare['remote'] );
	}
}
